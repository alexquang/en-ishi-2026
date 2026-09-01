<?php
/**
 * Instructor profile — one row per instructor, the single source of truth for
 * bio / headline / expertise / social links and denormalized roster stats.
 *
 * Consolidates what used to be split across WP user_meta and the write-once
 * application row (audit finding F4). The public profile, the instructor's own
 * editor, and the admin Instructors list all read this table. FREE owns it;
 * Pro extends via its own tables and never alters these columns.
 *
 * @package Learnomy
 */

namespace Learnomy\Models;

defined( 'ABSPATH' ) || exit;

use function Learnomy\now;

class Instructor extends Model {

	/** Taking new business as normal. */
	public const STATUS_ACTIVE = 'active';

	/**
	 * Paused by the site owner.
	 *
	 * Deliberately NOT deletion. The card that asked for this wanted a "Remove"
	 * mirroring the Students list, but that list is derived from enrollments,
	 * whereas this table is a profile row and courses point at `wp_users.ID` -
	 * so deleting it would wipe a bio irreversibly while leaving the person
	 * authoring live courses with students enrolled (Basecamp 10203580675).
	 *
	 * Suspension means: hidden from the public directory, no NEW enrollments in
	 * their courses. Existing students keep full access and earnings stay
	 * payable, because neither is the owner's to take away by pausing someone.
	 */
	public const STATUS_SUSPENDED = 'suspended';

	protected static function table_name(): string {
		return 'instructors';
	}

	/**
	 * Columns an instructor (or admin on their behalf) may write through the
	 * profile editor. `location`, `extra_social_json` and `verification_status`
	 * are stored here but surfaced/written by Pro, so they are intentionally
	 * NOT in the FREE editable set.
	 *
	 * @var string[]
	 */
	private const EDITABLE = array(
		'headline',
		'bio',
		'expertise',
		'website_url',
		'twitter_handle',
		'linkedin_url',
	);

	/**
	 * Does this user have a PUBLIC instructor profile on this site?
	 *
	 * THE predicate behind /instructor/{username}/. Every surface that renders,
	 * titles, describes, links to or lists a public instructor profile asks this
	 * one question, so they can never disagree about who is an instructor.
	 *
	 * The answer is "teaches at least one course the viewer can see" — authored
	 * OR co-instructed, status=published, and not unlisted/private. Deliberately
	 * NOT "holds the lrn_instructor role":
	 *
	 * - The role is not sufficient. An approved instructor with nothing published
	 *   yet has no profile to show; publishing an empty page (and feeding it to
	 *   crawlers) advertises a person, not a teacher.
	 * - The role is not necessary. On a single-owner academy the administrator
	 *   authors every course, and the byline on each course page links to their
	 *   profile. A role-only rule would 404 that link on the most common install.
	 *
	 * Because it is the same visibility-scoped count the profile header and grid
	 * use, the gate and the page can never disagree: if the grid would be empty,
	 * the page does not exist.
	 *
	 * @param int      $user_id   User to test.
	 * @param int|null $viewer_id Visibility viewer; see Course::public_visibility_scope().
	 *                            Pass 0 to ask the strictly-anonymous question (sitemaps,
	 *                            and any card embedded in public page content, so an
	 *                            administrator previewing sees what a visitor sees).
	 */
	public static function has_public_profile( int $user_id, ?int $viewer_id = null ): bool {
		if ( $user_id <= 0 || self::is_suspended( $user_id ) ) {
			return false;
		}

		return Course::count_published_for_instructor( $user_id, $viewer_id ) > 0;
	}

	/** Profile row for a user, or null when none exists yet. */
	public static function find_by_user( int $user_id ): ?object {
		if ( $user_id <= 0 ) {
			return null;
		}
		return static::db()->get_row(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE user_id = %d LIMIT 1',
				$user_id
			)
		) ?: null;
	}

	/**
	 * Is this instructor currently paused by the owner?
	 *
	 * Answers false for a user with no profile row at all: "no row" means nobody
	 * has ever suspended them, and defaulting the other way would silently stop
	 * enrollments across every course on a site whose rows predate the column.
	 */
	public static function is_suspended( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		$row = static::find_by_user( $user_id );

		return $row && self::STATUS_SUSPENDED === (string) ( $row->status ?? self::STATUS_ACTIVE );
	}

	/**
	 * Pause or resume an instructor.
	 *
	 * Writes through the Model base so the cache group is invalidated - the
	 * enrollment gate reads this on every attempt, and a stale read would either
	 * keep selling for a suspended instructor or refuse for a resumed one.
	 *
	 * Fires `learnomy_instructor_suspended` / `learnomy_instructor_resumed` so an
	 * extension can notify the instructor, pause a payout schedule, or unlist
	 * them elsewhere without this method growing those responsibilities.
	 *
	 * @param int    $user_id Instructor's user id.
	 * @param string $status  One of the STATUS_* constants.
	 * @return bool True when the row now carries that status.
	 */
	public static function set_status( int $user_id, string $status ): bool {
		if ( $user_id <= 0 || ! in_array( $status, array( self::STATUS_ACTIVE, self::STATUS_SUSPENDED ), true ) ) {
			return false;
		}

		// Creates the row when a site's instructor predates the profile table,
		// so suspending someone can never silently do nothing.
		$row = static::ensure_for_user( $user_id );
		if ( ! $row ) {
			return false;
		}

		$previous = (string) ( $row->status ?? self::STATUS_ACTIVE );
		if ( $previous === $status ) {
			return true;
		}

		static::update(
			(int) $row->id,
			array(
				'status'     => $status,
				'updated_at' => now(),
			)
		);

		if ( self::STATUS_SUSPENDED === $status ) {
			/**
			 * An instructor was paused: hidden from the public directory, no new
			 * enrollments in their courses.
			 *
			 * @param int $user_id Instructor's user id.
			 */
			do_action( 'learnomy_instructor_suspended', $user_id );
		} else {
			/**
			 * An instructor was resumed and takes new business again.
			 *
			 * @param int $user_id Instructor's user id.
			 */
			do_action( 'learnomy_instructor_resumed', $user_id );
		}

		return true;
	}

	/**
	 * Return the profile row for a user, creating an empty one on first access.
	 * Lets callers (the editor, the approval hook) treat every instructor as
	 * having a row without each one repeating the create-if-missing dance.
	 */
	public static function ensure_for_user( int $user_id ): ?object {
		if ( $user_id <= 0 ) {
			return null;
		}
		$row = static::find_by_user( $user_id );
		if ( $row ) {
			return $row;
		}
		static::insert(
			array(
				'user_id'             => $user_id,
				'verification_status' => 'unverified',
				'created_at'          => now(),
				'updated_at'          => now(),
			)
		);
		return static::find_by_user( $user_id );
	}

	/**
	 * Provision an instructor profile when an application is approved.
	 *
	 * BOTH approve paths must call this — the Abilities AJAX action
	 * (Abilities::execute_approve_instructor, used by the admin Applications
	 * page) and the Admin REST controller — so a newly-approved instructor
	 * always gets an lrn_instructors row and appears in the Instructors list.
	 * Previously only the REST path created the row, so applications approved
	 * from the admin UI granted the role but left the Instructors list empty
	 * ("No instructors yet" beside N approved applications). Centralising it
	 * here stops the two paths drifting again. Idempotent; seeds bio/expertise
	 * from the application only into fields the profile doesn't already have.
	 *
	 * @param object|null $application An lrn_instructor_applications row.
	 * @return object|null The instructor profile row, or null on bad input.
	 */
	public static function provision_from_application( ?object $application ): ?object {
		$user_id = (int) ( $application->user_id ?? 0 );
		if ( $user_id <= 0 ) {
			return null;
		}

		$profile = static::ensure_for_user( $user_id );
		if ( ! $profile ) {
			return null;
		}

		$seed = array();
		if ( '' === trim( (string) ( $profile->bio ?? '' ) ) && '' !== trim( (string) ( $application->bio ?? '' ) ) ) {
			$seed['bio'] = (string) $application->bio;
		}
		if ( '' === trim( (string) ( $profile->expertise ?? '' ) ) && '' !== trim( (string) ( $application->expertise ?? '' ) ) ) {
			$seed['expertise'] = (string) $application->expertise;
		}
		if ( $seed ) {
			static::upsert( $user_id, $seed );
			$profile = static::find_by_user( $user_id );
		}

		return $profile;
	}

	/**
	 * Set an instructor's verification status. Deliberately separate from
	 * upsert(): verification_status is NOT in the instructor-editable
	 * allowlist (so a crafted profile-editor payload can't self-verify), so
	 * this admin-only path writes the column directly. Creates the profile
	 * row first if the instructor has none yet.
	 *
	 * @param int    $user_id Instructor user ID.
	 * @param string $status  'verified' or 'unverified' (anything else → 'unverified').
	 */
	public static function set_verification_status( int $user_id, string $status ): bool {
		$row = $user_id > 0 ? static::ensure_for_user( $user_id ) : null;
		if ( ! $row ) {
			return false;
		}
		$status = in_array( $status, array( 'verified', 'unverified' ), true ) ? $status : 'unverified';
		return (bool) static::update(
			(int) $row->id,
			array(
				'verification_status' => $status,
				'updated_at'          => now(),
			)
		);
	}

	/**
	 * Create or update an instructor's profile fields. Only whitelisted,
	 * already-sanitized columns are written; unknown keys are ignored so a
	 * crafted REST payload can't reach a stat or verification column. Returns
	 * the row id.
	 *
	 * @param int                 $user_id Instructor user id.
	 * @param array<string,mixed> $fields Subset of self::EDITABLE.
	 */
	public static function upsert( int $user_id, array $fields ): int {
		if ( $user_id <= 0 ) {
			return 0;
		}

		$data = array();
		foreach ( self::EDITABLE as $column ) {
			if ( array_key_exists( $column, $fields ) ) {
				$data[ $column ] = $fields[ $column ];
			}
		}
		$data['updated_at'] = now();

		$existing = static::find_by_user( $user_id );
		if ( $existing ) {
			static::update( (int) $existing->id, $data );
			return (int) $existing->id;
		}

		$data['user_id']             = $user_id;
		$data['verification_status'] = 'unverified';
		$data['created_at']          = now();
		return static::insert( $data );
	}

	/**
	 * Paginated, searchable, sortable instructor roster for the admin list.
	 *
	 * Driven by lrn_instructors (one row per instructor) joined to wp_users for
	 * name/email/join-date. Sort + search run on indexed wp_users columns; the
	 * per-page earnings + course counts are computed separately in ONE batched
	 * query each (see Commission::earnings_for_recipients / Course::
	 * course_counts_for_instructors) so the list never N+1s at scale.
	 *
	 * @param array{search?:string,orderby?:string,order?:string,per_page?:int,paged?:int} $args
	 * @return array{rows:object[],total:int,total_pages:int}
	 */
	public static function admin_list( array $args = array() ): array {
		$db       = static::db();
		$table    = static::table();
		$users    = $db->users;
		$search   = isset( $args['search'] ) ? (string) $args['search'] : '';
		$orderby  = isset( $args['orderby'] ) ? (string) $args['orderby'] : 'joined';
		$order    = isset( $args['order'] ) && 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';
		$per_page = isset( $args['per_page'] ) ? max( 1, (int) $args['per_page'] ) : 20;
		$paged    = isset( $args['paged'] ) ? max( 1, (int) $args['paged'] ) : 1;

		// Whitelisted sort columns — both indexed on wp_users.
		$sort_map  = array(
			'joined' => 'u.user_registered',
			'name'   => 'u.display_name',
		);
		$order_col = $sort_map[ $orderby ] ?? 'u.user_registered';

		$where      = '1=1';
		$where_args = array();
		if ( '' !== $search ) {
			$like         = '%' . $db->esc_like( $search ) . '%';
			$where       .= ' AND ( u.display_name LIKE %s OR u.user_email LIKE %s )';
			$where_args[] = $like;
			$where_args[] = $like;
		}

		// Table names are internal; only WHERE values are user input (parameterised).
		$count_sql = "SELECT COUNT(*) FROM {$table} i JOIN {$users} u ON u.ID = i.user_id WHERE {$where}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $where_args ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$total = (int) $db->get_var( $db->prepare( $count_sql, $where_args ) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$total = (int) $db->get_var( $count_sql );
		}

		$offset    = ( $paged - 1 ) * $per_page;
		$rows_args = array_merge( $where_args, array( $per_page, $offset ) );
		// $order_col + $order are whitelisted above; only LIMIT/OFFSET + WHERE are bound.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $db->get_results(
			$db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT i.user_id, i.headline, i.verification_status, i.status, u.display_name, u.user_email, u.user_registered
				 FROM {$table} i
				 JOIN {$users} u ON u.ID = i.user_id
				 WHERE {$where}
				 ORDER BY {$order_col} {$order}
				 LIMIT %d OFFSET %d",
				$rows_args
			)
		);

		return array(
			'rows'        => $rows ?: array(),
			'total'       => $total,
			'total_pages' => max( 1, (int) ceil( $total / $per_page ) ),
		);
	}
}
