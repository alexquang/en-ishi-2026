<?php
/**
 * Course — the top-level learning object everything else hangs off.
 *
 * @package Learnomy
 */

namespace Learnomy\Models;

defined( 'ABSPATH' ) || exit;

use function Learnomy\now;

class Course extends Model {

	/** Canonical publish-status vocabulary — one word across every content entity. */
	public const STATUS_PUBLISHED = 'published';
	public const STATUS_DRAFT     = 'draft';

	/**
	 * Trashed courses are hidden everywhere except the Trash view.
	 *
	 * This was a bare `'trashed'` literal in the one query that remembered to
	 * exclude it, which is why nothing else did.
	 */
	public const STATUS_TRASHED = 'trashed';

	/** Opt into the base Model publish-status failsafe (heals `publish` -> `published`). */
	protected static function normalizes_publish_status(): bool {
		return true;
	}

	/**
	 * `description_plain` is the tag-stripped half of the `ft_title_description`
	 * FULLTEXT index — the column catalog search actually matches against. Deriving
	 * it here means every writer maintains it (REST, admin, CLI, seeder, importer,
	 * migrator), not just the one that happened to remember (Basecamp 10134139389).
	 */
	protected static function search_projections(): array {
		return [ 'description' => 'description_plain' ];
	}

	/**
	 * Columns a course "card" needs to render correctly anywhere it appears
	 * (catalog, dashboard recommendations, grids). Pricing columns are included
	 * so a card never falls back to "Free" just because a lightweight query
	 * omitted them (Basecamp #9981579795). Keep in sync with what
	 * templates/partials/course-card.php reads.
	 */
	private const CARD_COLUMNS = 'id, title, slug, short_description, category_id, author_id, student_count, avg_rating, review_count, thumbnail_url, thumbnail_id, price, sale_price, sale_starts, sale_ends, access_type, pricing_model, recurring_amount, recurring_interval';

	/**
	 * ── Pricing vocabulary (single source of truth) ─────────────────────────
	 *
	 * The whole pricing system round-trips through the legacy `access_type`
	 * column (coarse, 4 values) with the richer `pricing_model` column layered
	 * on top (6 values). These maps were previously duplicated across ~11 sites
	 * (admin match/array, JS ACCESS_MAP, both REST controller maps, the model's
	 * own match, plus enum whitelists), which guaranteed drift. They now live
	 * here ONCE; every consumer reads these constants + model_for_access() /
	 * access_for_model(). Adding a new model is a one-line change here.
	 */

	/** Allowed `access_type` column values (the coarse legacy dimension). */
	public const ACCESS_TYPES = [ 'free', 'paid', 'membership', 'paid_or_membership', 'closed' ];

	/** Allowed `pricing_model` column values (the richer dimension). */
	public const PRICING_MODELS = [ 'free', 'one_time', 'fixed_term', 'recurring', 'members_only', 'one_time_or_members', 'closed', 'invite_only' ];

	/**
	 * pricing_model => access_type. Authoritative model->access direction used
	 * on save. Several rich models collapse onto the same legacy access_type
	 * (one_time/fixed_term/recurring all => paid) because access_type is coarse.
	 * `closed` is a first-class value in BOTH columns (no on-site sale; access
	 * granted externally / manually) so the round-trip stays 1:1.
	 */
	public const MODEL_ACCESS_MAP = [
		'free'                => 'free',
		'one_time'            => 'paid',
		'fixed_term'          => 'paid',
		'recurring'           => 'paid',
		'members_only'        => 'membership',
		'one_time_or_members' => 'paid_or_membership',
		'closed'              => 'closed',
		// Invite-only collapses onto `closed` on purpose. The two are the SAME
		// access rule — nobody may enrol themselves — so they share every
		// enforcement path already proven for closed: the enrol route answers
		// 403, checkout refuses, and only an administrator can grant access.
		// They differ only in what the owner is asked for and what the visitor
		// is told: `closed` means the course is sold somewhere else and may
		// carry a link there; `invite_only` means an administrator adds people,
		// and asks for no URL at all. Presentation branches on pricing_model;
		// access never has to.
		'invite_only'         => 'closed',
	];

	/**
	 * access_type => primary pricing_model. The reverse direction used to
	 * reconstruct pricing_model() when the column is empty/stale — the canonical
	 * model for each access_type.
	 */
	public const ACCESS_PRIMARY_MODEL = [
		'free'               => 'free',
		'paid'               => 'one_time',
		'membership'         => 'members_only',
		'paid_or_membership' => 'one_time_or_members',
		'closed'             => 'closed',
	];

	protected const INT_COLUMNS = [
		'author_id',
		'thumbnail_id',
		'category_id',
		'completion_value',
		'review_count',
		'student_count',
		'duration_seconds',
		'access_duration_days',
	];

	protected const FLOAT_COLUMNS = [
		'price',
		'sale_price',
		'avg_rating',
		'recurring_amount',
	];

	protected static function table_name(): string {
		return 'courses';
	}

	public static function create( array $course ): int {
		$defaults = [
			'status'           => 'draft',
			'access_type'      => 'free',
			'price'            => 0.00,
			'avg_rating'       => 0.00,
			'review_count'     => 0,
			'student_count'    => 0,
			'duration_seconds' => 0,
			'created_at'       => now(),
			'updated_at'       => now(),
		];
		$course   = array_merge( $defaults, $course );

		// Publish-status healing (publish -> published) is enforced uniformly in
		// Model::insert()/update() via normalizes_publish_status(), so every
		// caller — import, REST, seeder, third-party — is covered on create AND
		// update, not just here.
		$course = self::normalize_free_pricing( $course );

		// Derive a slug from the title when the caller did not supply one.
		//
		// `lrn_courses.slug` is UNIQUE, so a slugless create stored an EMPTY
		// slug and the SECOND one failed on the index: seeding 20 courses
		// without slugs created 1 and errored 19 times (Basecamp 10194261083).
		// An empty slug is also unreachable on the front end, because the
		// router resolves by slug — the row exists and no URL reaches it.
		//
		// This is the same defect Quiz::create() had and fixed
		// (D.quiz-slug-autoderive, Basecamp #9905159504); the shape below
		// deliberately mirrors it rather than inventing a second approach. The
		// random fallback keeps a title-less course routable too.
		if ( empty( $course['slug'] ) ) {
			$derived        = sanitize_title( (string) ( $course['title'] ?? '' ) );
			$course['slug'] = '' !== $derived ? $derived : 'course-' . wp_generate_password( 8, false, false );
		}

		// Uniqueness is global here, not per-parent as it is for quizzes: the
		// column's UNIQUE index has no course_id to scope against. Two courses
		// named the same thing are ordinary, so suffix rather than reject.
		$base = (string) $course['slug'];
		$i    = 1;
		while ( static::find_by_slug( $course['slug'] ) ) {
			$course['slug'] = $base . '-' . ( ++$i );
		}

		return static::insert( $course );
	}

	/**
	 * Override the base writer so the free-pricing invariant holds on EVERY
	 * update path — REST, admin course-save, the demo/model seeders, CLI,
	 * imports. Switching a course to free must drop any lingering price; this
	 * is the single chokepoint that guarantees it (see normalize_free_pricing).
	 *
	 * @param int                 $id   Course id.
	 * @param array<string,mixed> $data Columns to update.
	 */
	public static function update( int $id, array $data ): bool {
		return parent::update( $id, self::normalize_free_pricing( $data ) );
	}

	/**
	 * Reassign every course in one category to another (single bulk UPDATE).
	 *
	 * Used when a category is deleted: its courses move to the target (0 =
	 * Uncategorized) so no course is left pointing at a row that no longer
	 * exists. Big-site safe — one statement regardless of how many courses.
	 *
	 * @param int $from_category_id Category being emptied.
	 * @param int $to_category_id   Destination category (0 = Uncategorized).
	 * @return int Number of courses moved.
	 */
	public static function move_category( int $from_category_id, int $to_category_id ): int {
		$affected = static::db()->query(
			static::db()->prepare(
				'UPDATE ' . static::table() . ' SET category_id = %d WHERE category_id = %d',
				$to_category_id,
				$from_category_id
			)
		);
		// Raw write bypasses base insert/update/delete — bump the cache group.
		static::invalidate_cache();
		return is_numeric( $affected ) ? (int) $affected : 0;
	}

	/**
	 * Reassign the denormalized primary-author pointer on every course owned by
	 * one user to another (single bulk UPDATE).
	 *
	 * Companion to CourseInstructor::reassign_user() — the junction table holds
	 * the share/primary rows, while `lrn_courses.author_id` is the denormalized
	 * pointer the catalog card + admin list read for the instructor name. Both
	 * must move together when an instructor is deleted, or the course still
	 * shows the dead user (COURSE-01). Big-site safe — one statement.
	 *
	 * @return int Number of courses whose author was reassigned.
	 */
	public static function reassign_author( int $from_user_id, int $to_user_id ): int {
		if ( $from_user_id <= 0 || $to_user_id <= 0 || $from_user_id === $to_user_id ) {
			return 0;
		}
		$affected = static::db()->query(
			static::db()->prepare(
				'UPDATE ' . static::table() . ' SET author_id = %d WHERE author_id = %d',
				$to_user_id,
				$from_user_id
			)
		);
		// Raw write bypasses base insert/update/delete — bump the cache group.
		static::invalidate_cache();
		return is_numeric( $affected ) ? (int) $affected : 0;
	}

	/**
	 * One bounded page of course ids authored by a user, oldest first.
	 *
	 * Backs the GDPR authored-content eraser, which purges an author's catalogue
	 * in batches: each course cascades to its sections, lessons, quizzes, reviews,
	 * enrollments and certificates, so an instructor with a large catalogue would
	 * blow the request timeout in one pass. Oldest-first with a LIMIT gives the
	 * batch a stable, resumable order.
	 *
	 * @return array
	 */
	public static function authored_ids( int $user_id, int $limit = 50 ): array {
		if ( $user_id <= 0 ) {
			return [];
		}

		$ids = static::db()->get_col(
			static::db()->prepare(
				'SELECT id FROM ' . static::table() . ' WHERE author_id = %d ORDER BY id ASC LIMIT %d',
				$user_id,
				max( 1, $limit )
			)
		) ?: [];

		return array_map( 'intval', $ids );
	}

	/**
	 * Enforce the data invariant: a free course never carries a price.
	 *
	 * When a write marks the course free (access_type='free' or
	 * pricing_model='free'), zero the monetary columns so a stale price/sale
	 * can never linger on a free course. This is the root-cause guard for the
	 * "free course shows Buy Now / has purchase rows" drift: previously any
	 * caller could persist access_type=free while leaving price=49, because no
	 * layer reconciled the two. Scoped to FREE only — paid / membership /
	 * paid_or_membership keep their price untouched. Mirrors the
	 * publish->published normalization above: enforce once, in the model, so no
	 * caller (seeder, REST, admin, import) can reintroduce the drift.
	 *
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	private static function normalize_free_pricing( array $data ): array {
		$is_free = ( isset( $data['access_type'] ) && 'free' === $data['access_type'] )
			|| ( isset( $data['pricing_model'] ) && 'free' === $data['pricing_model'] );

		if ( $is_free ) {
			$data['price']      = 0.00;
			$data['sale_price'] = null;
		}

		return $data;
	}

	public static function find_by_slug( string $slug ): ?object {
		return static::db()->get_row(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE slug = %s LIMIT 1',
				$slug
			)
		) ?: null;
	}

	/**
	 * One page of publicly listable published courses, newest first.
	 *
	 * Backs the catalog blocks, the `[learnomy_courses]` shortcode, the Recent
	 * Courses widget and the public list-courses ability — all of which showed
	 * unlisted and private courses before the visibility rule became reusable.
	 *
	 * @param int      $page      1-based page number.
	 * @param int      $per_page  Rows per page.
	 * @param int|null $viewer_id Visibility viewer; see public_visibility_scope().
	 */
	public static function list_published( int $page = 1, int $per_page = 20, ?int $viewer_id = null ): array {
		$offset                   = ( max( 1, $page ) - 1 ) * $per_page;
		[ $where, $where_values ] = self::public_listing_where( $viewer_id );

		return static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is a model-built fragment; its values are bound below.
				'SELECT * FROM ' . static::table() . " WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
				...array_merge( $where_values, [ $per_page, $offset ] )
			)
		) ?: [];
	}

	/**
	 * Catalog-facing search. Shared between the course-archive template (first
	 * paint) and the REST controller (Interactivity store fetches) so both
	 * always filter, sort, and paginate identically. Returns an array of
	 * course rows; the caller is responsible for counting / has_more when it
	 * needs meta data.
	 *
	 * Accepted args:
	 * - search      (string) LIKE match across title, description, short_description.
	 * - category    (string) Category slug (not id — catalog URLs use slugs).
	 * - access_type (string) Optional access-type filter.
	 * - sort        (string) latest | popular | rating | price_asc | price_desc.
	 * - after       (int)    Cursor id (only used with sort=latest).
	 * - page        (int)    1-based page number (ignored when `after` > 0).
	 * - per_page    (int)    Max rows returned.
	 *
	 * @param array<string,mixed> $args
	 * @return object[]
	 */
	public static function search_catalog( array $args ): array {
		$wpdb = static::db();
		$p    = $wpdb->prefix;

		[ $where_sql, $where_values ] = self::build_catalog_filters( $args );

		$sort    = (string) ( $args['sort'] ?? 'latest' );
		$order   = self::catalog_order_by( $sort );
		$per     = max( 1, (int) ( $args['per_page'] ?? 12 ) );
		$page    = max( 1, (int) ( $args['page'] ?? 1 ) );
		$after   = (int) ( $args['after'] ?? 0 );
		$use_cur = $after > 0 && 'latest' === $sort;

		if ( $use_cur ) {
			// KEYSET CURSOR ON THE ACTUAL SORT KEY.
			//
			// This used to be a bare `id < %d` while 'latest' orders by
			// `created_at DESC, id DESC`. Filtering on a column the result set
			// is not ordered by does not paginate it — it just removes an
			// arbitrary subset. On a catalogue whose id order differs from its
			// created_at order (any imported, seeded or backdated set), page 2
			// re-served rows page 1 had already shown and skipped others
			// entirely: 6 of 20 rows overlapped on lms.local, and courses in
			// the gap were unreachable by scrolling at all (Basecamp
			// 10159960755).
			//
			// The public contract is unchanged on purpose: `after` is still a
			// course id and `meta.cursor_next` still returns one, so the mobile
			// app and any existing integration keep working untouched. We
			// simply resolve that id to its sort position first, then compare
			// on the same tuple the ORDER BY uses.
			//
			// Served by KEY status_created (status, created_at).
			$anchor = static::db()->get_var(
				static::db()->prepare( "SELECT created_at FROM {$p}lrn_courses WHERE id = %d", $after )
			);

			if ( null === $anchor ) {
				// The cursor row was deleted or unpublished between requests.
				// Fall back to the id-only predicate rather than returning the
				// whole catalogue from the top, which would loop the client
				// forever.
				$where_sql[]    = 'id < %d';
				$where_values[] = $after;
			} else {
				$where_sql[]    = '( created_at < %s OR ( created_at = %s AND id < %d ) )';
				$where_values[] = $anchor;
				$where_values[] = $anchor;
				$where_values[] = $after;
			}
			$offset = 0;
		} else {
			$offset = ( $page - 1 ) * $per;
		}

		$where_str = implode( ' AND ', $where_sql );

		// $p is the trusted $wpdb->prefix, $where_str is composed of hard-coded fragments joined with whitelisted column names, and $order is validated earlier. User-supplied values all flow through placeholders.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT * FROM {$p}lrn_courses WHERE {$where_str} ORDER BY {$order} LIMIT %d OFFSET %d";

		$values   = $where_values;
		$values[] = $per;
		$values[] = $offset;

		/** @var object[]|null $rows */
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is a whitelisted-identifier template; parameters are prepared below.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$values ) );

		return $rows ?: [];
	}

	/**
	 * Counts the catalog result set under the same filter as
	 * {@see self::search_catalog()} — used by REST to populate meta.total.
	 *
	 * @param array<string,mixed> $args
	 */
	public static function count_catalog( array $args ): int {
		$wpdb = static::db();
		$p    = $wpdb->prefix;

		[ $where_sql, $where_values ] = self::build_catalog_filters( $args );
		$where_str                    = implode( ' AND ', $where_sql );

		if ( empty( $where_values ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}lrn_courses WHERE {$where_str}" );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}lrn_courses WHERE {$where_str}", ...$where_values ) );
	}

	/**
	 * Turn a raw user search string into a BOOLEAN MODE query with a trailing
	 * wildcard on each term, so partial words match their full form (e.g.
	 * "mind" matches "Mindful"). BOOLEAN MODE only matches whole words without
	 * the wildcard, which is the Basecamp #9976465708 bug.
	 *
	 * Strips BOOLEAN-mode operators (+ - * " ( ) ~ < > @) from each term before
	 * re-adding our own trailing '*', so user input can neither break the query
	 * nor inject operators. Terms stay OPTIONAL (no leading '+') to keep the
	 * same OR breadth the catalog had before. Returns '' when nothing usable
	 * remains so the caller can fall back to the raw term.
	 *
	 * @param string $search Raw search string.
	 * @return string Space-joined prefix query, e.g. "habit* builder*".
	 */
	private static function to_boolean_prefix_query( string $search ): string {
		$terms = preg_split( '/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY );
		$out   = array();
		foreach ( (array) $terms as $term ) {
			$clean = preg_replace( '/[+\-*"()~<>@]/', '', $term );
			if ( '' !== $clean ) {
				$out[] = $clean . '*';
			}
		}
		return implode( ' ', $out );
	}

	/**
	 * Shared catalog filter builder. Returns [sql_clauses, values] ready to
	 * feed into $wpdb->prepare(). Always narrows to published courses, and
	 * excludes courses flagged `is_private=true` from the listing — except
	 * for admins (manage_options) and the course's own instructor, who still
	 * need to surface their private courses in the catalog for preview.
	 *
	 * @param array<string,mixed> $args
	 * @return array{0: string[], 1: array<int,mixed>}
	 */
	private static function build_catalog_filters( array $args ): array {
		$wpdb = static::db();

		$where_sql    = [ "status = 'published'" ];
		$where_values = [];

		$search = trim( (string) ( $args['search'] ?? '' ) );
		if ( '' !== $search ) {
			// lrn_courses has a FULLTEXT index on (title, description_plain) —
			// the tag-stripped projection (G16), so matches are on clean text.
			// MySQL BOOLEAN MODE lets short queries (<3 chars on most
			// installations) match where NATURAL LANGUAGE mode would reject
			// them, and keeps the planner from ignoring the FT index in favour
			// of the triple-LIKE scan used here previously.
			// short_description is not in the FULLTEXT index but contributes a
			// secondary LIKE clause so partial-description matches still surface.
			//
			// Each term gets a trailing '*' so a PARTIAL word matches its full
			// form. BOOLEAN MODE otherwise only matches whole words, so "mind"
			// returned nothing for the "Mindful Productivity" course even though
			// it clearly should match (Basecamp #9976465708). User-supplied
			// boolean operators are stripped first so the query can neither be
			// broken nor abused; we fall back to the raw term if nothing usable
			// remains.
			$boolean        = self::to_boolean_prefix_query( $search );
			$where_sql[]    = '(MATCH(title, description_plain) AGAINST(%s IN BOOLEAN MODE) OR short_description LIKE %s)';
			$where_values[] = '' !== $boolean ? $boolean : $search;
			$where_values[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$category = trim( (string) ( $args['category'] ?? '' ) );
		if ( '' !== $category ) {
			$cat = \Learnomy\Models\Category::find_by_slug( $category );
			if ( $cat ) {
				// Same scope the category archive uses, so /courses/?cat=design
				// and /courses/category/design/ list the same courses — nested
				// categories rolled in, both times.
				[ $category_sql, $category_ids ] = self::category_scope( (int) $cat->id );

				$where_sql[]  = $category_sql;
				$where_values = array_merge( $where_values, $category_ids );
			} else {
				// Unknown slug returns zero matches rather than ignoring.
				$where_sql[] = '1 = 0';
			}
		}

		// Filter on what the LEARNER PAYS, not on the stored access_type alone.
		//
		// `access_type` says how a course is SOLD; whether it currently costs
		// anything also depends on its price. A course can be access_type='paid'
		// with a price of 0 - that is a supported state, not bad data (see the
		// $0-course work, Free #773) - and every display surface already treats
		// it as free, because is_purchasable_free() returns true when price and
		// sale_price are both <= 0 whatever the access_type says.
		//
		// The filter did not look at price at all, so those courses answered the
		// "Paid" filter and then rendered with no price on them: the catalog
		// promised something to pay for and delivered a free course
		// (Basecamp 10198897149, app #10194757896).
		//
		// The card suspected access_type/pricing_model drift. That drift is real
		// but it is one row on this dataset, and it is not this symptom - the two
		// columns agree on every course involved. The mismatch is between the
		// column and the PRICE.
		//
		// So: 'paid' means it costs money, 'free' includes a paid-model course
		// priced at zero. Membership and closed are access rules that price does
		// not qualify, and they keep the plain column match.
		$access = (string) ( $args['access_type'] ?? '' );
		if ( '' !== $access && in_array( $access, self::ACCESS_TYPES, true ) ) {
			// "Costs money" has to include the recurring amount. A recurring
			// course keeps its price in `recurring_amount` and leaves `price` at
			// 0, so a price-only test would file a live $19/month subscription
			// under Free - swapping one wrong answer for another.
			$costs_money = '( COALESCE( NULLIF( sale_price, 0 ), price ) > 0'
				. ' OR COALESCE( recurring_amount, 0 ) > 0 )';

			if ( 'paid' === $access ) {
				$where_sql[]    = '( access_type = %s AND ' . $costs_money . ' )';
				$where_values[] = $access;
			} elseif ( 'free' === $access ) {
				// Free by model, OR sold under a paid model but currently costing
				// nothing - which is exactly what the learner sees.
				$where_sql[]    = '( access_type = %s OR ( access_type = %s AND NOT ' . $costs_money . ' ) )';
				$where_values[] = $access;
				$where_values[] = 'paid';
			} else {
				$where_sql[]    = 'access_type = %s';
				$where_values[] = $access;
			}
		}

		// Visibility gate — the ONE definition, shared with every other public
		// surface. See public_visibility_scope(): admins see all, a logged-in
		// author sees their own hidden courses, everyone else gets unlisted AND
		// private excluded. This block used to inline the needles, which is why
		// no other surface could reuse it.
		[ $visibility_sql, $visibility_values ] = self::public_visibility_scope();
		foreach ( $visibility_sql as $fragment ) {
			$where_sql[] = $fragment;
		}
		foreach ( $visibility_values as $value ) {
			$where_values[] = $value;
		}

		// Facet-driven WHERE clauses (Catalog Facets framework).
		// Built-in tag/access_type facets contribute here; custom callables
		// can append arbitrary clauses via the filter below. Empty when no
		// facets are registered or active.
		if ( ! empty( $args['facets'] ) && is_array( $args['facets'] ) && class_exists( \Learnomy\Catalog\Facets::class ) ) {
			[ $facet_sql, $facet_values ] = \Learnomy\Catalog\Facets::build_clauses( $args['facets'] );
			foreach ( $facet_sql as $frag ) {
				$where_sql[] = $frag;
			}
			foreach ( $facet_values as $v ) {
				$where_values[] = $v;
			}
		}

		/**
		 * Final hook for extensions to contribute WHERE clauses + values to
		 * the catalog query. Listeners receive a tuple of [ $where_sql_array,
		 * $where_values_array ] and must return the same shape.
		 *
		 * The Facets framework above is the recommended path; this hook is
		 * the escape hatch for one-off custom queries that don't fit the
		 * facet model.
		 *
		 * @param array{0: string[], 1: array<int,mixed>} $clauses
		 * @param array<string,mixed>                     $args
		 */
		[ $where_sql, $where_values ] = apply_filters(
			'learnomy_catalog_where_clauses',
			array( $where_sql, $where_values ),
			$args
		);

		return [ $where_sql, $where_values ];
	}

	/**
	 * Maps the public sort slug to a safe indexed ORDER BY clause. Unknown
	 * slugs fall back to latest so malformed requests never SQL-error.
	 *
	 * `featured` is a derived sort: rows whose settings_json contains
	 * `"is_featured":true` surface first, within each group newest wins.
	 * Uses a LIKE on a static needle so no user input ever reaches the
	 * ORDER BY clause — safe to interpolate.
	 */
	private static function catalog_order_by( string $sort ): string {
		return match ( $sort ) {
			'popular'    => 'student_count DESC, id DESC',
			'rating'     => 'avg_rating DESC, review_count DESC, id DESC',
			'price_asc'  => 'price ASC, id DESC',
			'price_desc' => 'price DESC, id DESC',
			'featured'   => "(settings_json LIKE '%\"is_featured\":true%') DESC, created_at DESC, id DESC",
			// `oldest` and `title` were in the shared REST sort enum
			// (class-base-controller.php) but had no arm here, so they fell to
			// `default` and silently returned `latest`. A caller asking for
			// oldest-first got newest-first with a 200 and no hint anything was
			// wrong — the worst kind of wrong answer (Basecamp 10159960755).
			'oldest'     => 'created_at ASC, id ASC',
			'title'      => 'title ASC, id DESC',
			default      => 'created_at DESC, id DESC',
		};
	}

	/**
	 * Batch-lookup published courses by ID list. Returns rows ordered by
	 * title ASC (display-friendly). Used by Pro Spaces' public space
	 * page to hydrate its assigned-courses grid without an N+1 chain
	 * through `Course::find()`.
	 *
	 * Filters status='published' in SQL — the admin/private statuses
	 * are dropped server-side so the view doesn't have to.
	 */
	public static function find_published_in( array $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids ) {
			return [];
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table()
					. " WHERE status = 'published' AND id IN ({$placeholders})" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					. ' ORDER BY title ASC',
				...$ids
			)
		) ?: [];
	}

	/**
	 * Filter a set of ids down to the ones that still exist, ANY status.
	 *
	 * The existence question, separate from the visibility question: a draft
	 * course exists and its pointers are valid, so `find_published_in()` is the
	 * wrong test for "is this row still real". Callers cleaning up junction
	 * tables need this one — a published-only filter would read every draft as
	 * deleted and purge live data.
	 *
	 * @param int[] $ids Candidate course ids.
	 * @return int[] The subset that exists, order not guaranteed.
	 */
	public static function existing_ids( array $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids ) {
			return [];
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = static::db()->get_col(
			static::db()->prepare(
				'SELECT id FROM ' . static::table() . " WHERE id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$ids
			)
		);
		return array_map( 'intval', (array) $rows );
	}

	/**
	 * Published courses NOT in an exclude list, ordered by title.
	 * Used by the Spaces admin course-picker (which excludes courses
	 * already linked to the space). Capped — large catalogs need a
	 * proper search UI rather than a giant dropdown.
	 */
	public static function published_excluding( array $exclude_ids, int $limit = 200 ): array {
		$exclude_ids = array_values( array_unique( array_filter( array_map( 'intval', $exclude_ids ) ) ) );
		$limit       = max( 1, min( 500, $limit ) );

		if ( empty( $exclude_ids ) ) {
			return static::db()->get_results(
				static::db()->prepare(
					'SELECT id, title FROM ' . static::table()
						. " WHERE status = 'published' ORDER BY title ASC LIMIT %d",
					$limit
				)
			) ?: [];
		}

		$placeholders = implode( ',', array_fill( 0, count( $exclude_ids ), '%d' ) );
		$params       = array_merge( $exclude_ids, [ $limit ] );

		return static::db()->get_results(
			static::db()->prepare(
				'SELECT id, title FROM ' . static::table()
					. " WHERE status = 'published' AND id NOT IN ({$placeholders})" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					. ' ORDER BY title ASC LIMIT %d',
				...$params
			)
		) ?: [];
	}

	/**
	 * Decorate a set of course rows with their dominant lesson content type
	 * (the format most of the course is delivered in) plus the matching badge
	 * icon + label, so catalog cards can show "Video" / "Audio" / "Text" at a
	 * glance. One grouped query for the whole page — no per-card N+1.
	 *
	 * Sets `dominant_content_type`, `content_type_icon`, and `content_type_label`
	 * on each row in place; rows for courses with no lessons get empty strings
	 * (the card renders no badge).
	 *
	 * @param object[] $courses Course rows (each must carry an `id`).
	 * @return object[] The same rows, decorated.
	 */
	public static function attach_content_type_summary( array $courses ): array {
		if ( empty( $courses ) ) {
			return $courses;
		}

		$ids = array();
		foreach ( $courses as $course ) {
			$cid = (int) ( $course->id ?? 0 );
			if ( $cid > 0 ) {
				$ids[] = $cid;
			}
		}
		$ids = array_values( array_unique( $ids ) );

		$dominant = array();
		if ( ! empty( $ids ) ) {
			$wpdb         = static::db();
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$rows         = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT course_id, content_type, COUNT(*) AS cnt FROM {$wpdb->prefix}lrn_lessons WHERE course_id IN ({$placeholders}) AND ( parent_id IS NULL OR parent_id = 0 ) GROUP BY course_id, content_type",
					...$ids
				)
			) ?: array();

			$best = array();
			foreach ( $rows as $row ) {
				$cid = (int) $row->course_id;
				$cnt = (int) $row->cnt;
				if ( ! isset( $best[ $cid ] ) || $cnt > $best[ $cid ] ) {
					$best[ $cid ]     = $cnt;
					$dominant[ $cid ] = (string) $row->content_type;
				}
			}
		}

		foreach ( $courses as $course ) {
			$cid  = (int) ( $course->id ?? 0 );
			$type = $dominant[ $cid ] ?? '';

			// Learner-facing: a technical type (SCORM/xAPI/LTI/QTI/H5P) resolves to
			// an empty label, so the card shows no tech badge — a student sees a
			// normal lesson, not "a SCORM lesson" (LESSON-01). Suppress the icon
			// too when the label is empty so no orphan icon renders.
			$learner_label                 = '' !== $type ? Lesson::learner_content_type_label( $type ) : '';
			$course->dominant_content_type = $type;
			$course->content_type_label    = $learner_label;
			$course->content_type_icon     = '' !== $learner_label ? Lesson::content_type_icon( $type ) : '';
		}

		return $courses;
	}

	/**
	 * Decorate a page of course rows with their resolved category name and
	 * primary-instructor display name + profile URL, so the course-card
	 * partial can render those without a per-card Category::find() +
	 * get_userdata() (an N+1 on a 12-card grid).
	 *
	 * Two batched reads total: one Category::find_in() over the distinct
	 * category ids, and one WP_User_Query(['include' => …]) over the distinct
	 * author ids. The card template reads the pre-resolved `category_name`,
	 * `instructor_name`, and `instructor_url` properties when present and
	 * falls back to its own lookup only when they are absent (cards rendered
	 * outside this builder).
	 *
	 * @param object[] $courses Course rows.
	 * @return object[] The same rows, decorated in place.
	 */
	public static function attach_card_meta( array $courses ): array {
		if ( empty( $courses ) ) {
			return $courses;
		}

		$category_ids = array();
		$author_ids   = array();
		foreach ( $courses as $course ) {
			$cat_id = (int) ( $course->category_id ?? 0 );
			if ( $cat_id > 0 ) {
				$category_ids[] = $cat_id;
			}
			$author_id = (int) ( $course->author_id ?? 0 );
			if ( $author_id > 0 ) {
				$author_ids[] = $author_id;
			}
		}

		// One query: category id → row map.
		$categories = ! empty( $category_ids ) ? Category::find_in( $category_ids ) : array();

		// One query: author id → user object map. WP_User_Query with `include`
		// resolves every author in a single SELECT, priming the user cache so
		// later get_userdata() fallbacks (if any) are cache hits too.
		$users      = array();
		$author_ids = array_values( array_unique( $author_ids ) );
		if ( ! empty( $author_ids ) ) {
			$user_query = new \WP_User_Query(
				array(
					'include'     => $author_ids,
					'fields'      => array( 'ID', 'display_name', 'user_login' ),
					'number'      => count( $author_ids ),
					'count_total' => false,
				)
			);
			foreach ( (array) $user_query->get_results() as $user ) {
				$users[ (int) $user->ID ] = $user;
			}
		}

		foreach ( $courses as $course ) {
			$cat_id                = (int) ( $course->category_id ?? 0 );
			$category              = $cat_id > 0 ? ( $categories[ $cat_id ] ?? null ) : null;
			$course->category_name = $category ? (string) $category->name : '';

			$author_id               = (int) ( $course->author_id ?? 0 );
			$user                    = $author_id > 0 ? ( $users[ $author_id ] ?? null ) : null;
			$course->instructor_name = $user ? (string) $user->display_name : '';
			$course->instructor_url  = $user
				? \Learnomy\route_url( 'instructor-profile', array( 'slug' => (string) $user->user_login ) )
				: '';
		}

		return $courses;
	}

	/**
	 * Stamp the viewer's own enrollment onto each card row, for the whole page,
	 * in ONE query.
	 *
	 * The course card renders "Continue" / "Review" + a progress bar when it is
	 * handed the viewer's enrollment, and a price when it is not. Every private
	 * surface (dashboard tabs, the shortcodes) passed one; the public catalog,
	 * category archives, the instructor profile and the three course blocks never
	 * did, so an actively-enrolled student was quoted the price of a course they
	 * already own (Basecamp 10134139389). This is the batched supply for those
	 * surfaces — a sibling of {@see attach_card_meta()}, called the same way.
	 *
	 * Scale contract:
	 * - Anonymous visitors cost NOTHING extra. The catalog is public and mostly
	 *   read by logged-out traffic; there is no user to resolve, so we return
	 *   before touching the database.
	 * - Logged-in viewers cost exactly ONE query per page, regardless of page size
	 *   — never one lookup per card.
	 *
	 * Uses {@see Enrollment::find_for_user_courses()}, the batch sibling of
	 * `find_for_user_course()`, so a COMPLETED student is included and sees
	 * "Review". `find_active()` would drop them (coding-rules Rule 12).
	 *
	 * @param object[] $courses  Course rows to stamp (mutated in place and returned).
	 * @param int|null $user_id  Viewer; defaults to the current user.
	 * @return object[] The same rows, each carrying `viewer_enrollment` (row|null).
	 */
	/**
	 * Stamp `viewer_claimable` on a set of course rows: the member's plan covers
	 * this course and they have not claimed it yet.
	 *
	 * The companion to {@see attach_viewer_enrollment()}, and it must be called
	 * WITH it - claimable means covered AND not already enrolled, so it reads the
	 * enrollment the other helper stamped rather than asking again.
	 *
	 * Exists because a catalog card had no way to know. It resolves state from
	 * the batched enrollment row, so a plan-covered member - who holds access
	 * from the subscription while owning no enrollment row - looked exactly like
	 * a stranger, and every members-only course in the catalog showed them
	 * "Members only" as though they had to buy something they were already
	 * paying for (Basecamp 10207864281).
	 *
	 * Costs two queries for the whole page regardless of how many cards are on
	 * it, via Subscription_Service::has_access_map(), and only for the courses
	 * that actually have a membership path.
	 *
	 * @param object[] $courses Course rows, ideally already through
	 *                          attach_viewer_enrollment().
	 * @param int|null $user_id Viewer; defaults to the current user.
	 * @return object[] The same rows, each carrying `viewer_claimable`.
	 */
	public static function attach_viewer_claimable( array $courses, ?int $user_id = null ): array {
		if ( empty( $courses ) ) {
			return $courses;
		}

		$user_id = null === $user_id ? get_current_user_id() : $user_id;

		// Stamped even when it cannot be true, so the card can tell "resolved,
		// not claimable" from "nobody resolved this" and never falls back to a
		// per-card lookup - the same contract attach_viewer_enrollment() keeps.
		foreach ( $courses as $course ) {
			$course->viewer_claimable = false;
		}

		if ( $user_id <= 0 ) {
			return $courses;
		}

		// Only courses sold through a plan can be claimed, and only by somebody
		// not already enrolled. Narrowing here keeps the entitlement lookup off
		// every ordinary paid course on the page.
		$candidates = array();
		foreach ( $courses as $course ) {
			if ( ! empty( $course->viewer_enrollment ) ) {
				continue;
			}
			if ( ! self::has_member_path( $course ) ) {
				continue;
			}
			$candidates[] = (int) ( $course->id ?? 0 );
		}

		if ( ! $candidates ) {
			return $courses;
		}

		$access = ( new \Learnomy\Services\Subscription_Service() )->has_access_map( $user_id, $candidates );

		foreach ( $courses as $course ) {
			$course->viewer_claimable = ! empty( $access[ (int) ( $course->id ?? 0 ) ] );
		}

		return $courses;
	}

	public static function attach_viewer_enrollment( array $courses, ?int $user_id = null ): array {
		if ( empty( $courses ) ) {
			return $courses;
		}

		$user_id = null === $user_id ? get_current_user_id() : $user_id;

		// Stamp the property even when logged out, so the card can tell "resolved,
		// not enrolled" from "nobody resolved this" and never falls back to a
		// per-card lookup.
		if ( $user_id <= 0 ) {
			foreach ( $courses as $course ) {
				$course->viewer_enrollment = null;
			}
			return $courses;
		}

		$course_ids = array();
		foreach ( $courses as $course ) {
			$course_ids[] = (int) ( $course->id ?? 0 );
		}

		$enrollments = Enrollment::find_for_user_courses( $user_id, $course_ids );

		foreach ( $courses as $course ) {
			$course->viewer_enrollment = $enrollments[ (int) ( $course->id ?? 0 ) ] ?? null;
		}

		return $courses;
	}

	public static function increment_student_count( int $id, int $by = 1 ): void {
		static::db()->query(
			static::db()->prepare(
				'UPDATE ' . static::table() . ' SET student_count = GREATEST(student_count + %d, 0), updated_at = %s WHERE id = %d',
				$by,
				now(),
				$id
			)
		);
		// Raw write bypasses base insert/update/delete — bump the cache group.
		static::invalidate_cache();
	}

	public static function get_by_author( int $user_id, int $limit = 100, int $offset = 0 ): array {
		return static::where( [ 'author_id' => $user_id ], 'id', 'DESC', $limit, $offset );
	}

	public static function count_by_author( int $user_id ): int {
		return static::count( [ 'author_id' => $user_id ] );
	}

	/**
	 * Every course the user is associated with — as the row author OR as
	 * an instructor (co-teacher) via `lrn_course_instructors`. Distinct so
	 * the row appears once regardless of how many `lrn_course_instructors`
	 * rows reference it.
	 *
	 * Used by the instructor dashboard's "My Courses" listing so a
	 * co-instructor sees the courses they were assigned to, not just the
	 * ones they authored. The prior `get_by_author()` query hid every
	 * co-instructor assignment from the dashboard (Basecamp #9932171957).
	 */
	public static function get_for_instructor( int $user_id, int $limit = 100, int $offset = 0 ): array {
		if ( $user_id <= 0 ) {
			return array();
		}
		$courses     = static::table();
		$instructors = \Learnomy\table( 'course_instructors' );
		return (array) static::db()->get_results(
			static::db()->prepare(
				// Trashed courses are excluded here and in count_for_instructor()
				// below, which MUST agree: the list is paginated against that
				// count, so filtering one and not the other yields a pager
				// promising a page of rows the query cannot return. Trashing a
				// course used to leave it sitting in My Courses as though nothing
				// had happened (Basecamp 10190411312).
				"SELECT DISTINCT c.* FROM {$courses} c
				 LEFT JOIN {$instructors} ci ON ci.course_id = c.id
				 WHERE ( c.author_id = %d OR ci.user_id = %d )
				   AND c.status <> 'trashed'
				 ORDER BY c.id DESC LIMIT %d OFFSET %d",
				$user_id,
				$user_id,
				$limit,
				$offset
			)
		);
	}

	/**
	 * Distinct course counts (as author OR co-instructor) for a SET of users,
	 * in one query. Powers the admin Instructors list without a per-row COUNT.
	 * A UNION folds the two ways a user is on a course (author_id, or a
	 * course_instructors row) before the distinct count, so a course taught
	 * both ways is counted once. Users with no courses are absent (default 0).
	 *
	 * @param int[] $user_ids
	 * @return array<int,int> keyed by user_id.
	 */
	public static function course_counts_for_instructors( array $user_ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $user_ids ) ) ) );
		if ( empty( $ids ) ) {
			return array();
		}
		$courses      = static::table();
		$instructors  = \Learnomy\table( 'course_instructors' );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				// The co-instructor arm is INNER JOINed to the courses table so a
				// teaching assignment can only count a course that still exists.
				// This is a roster figure an owner judges people by, and the
				// junction is a pointer: any row whose course was deleted outside
				// Course_Service::delete_course() inflated it silently. A QA
				// install read "Courses: 286" for one instructor while the whole
				// courses table held 14 rows (Basecamp 10207832866). The author
				// arm needs no join - it reads the courses table already.
				//
				// `wp learnomy repair data-integrity` removes the rows themselves;
				// this makes the number right even on a site that has not run it,
				// and keeps it right if some future path forgets the cascade.
				"SELECT uid, COUNT(DISTINCT cid) AS n FROM (
					SELECT author_id AS uid, id AS cid FROM {$courses} WHERE author_id IN ({$placeholders})
					UNION
					SELECT ci.user_id AS uid, ci.course_id AS cid
					  FROM {$instructors} ci
					  JOIN {$courses} c2 ON c2.id = ci.course_id
					 WHERE ci.user_id IN ({$placeholders})
				 ) t GROUP BY uid",
				...array_merge( $ids, $ids )
			)
		);

		$map = array();
		foreach ( (array) $rows as $row ) {
			$map[ (int) $row->uid ] = (int) $row->n;
		}
		return $map;
	}

	/**
	 * The course's main author — the only recipient of course commission.
	 *
	 * Co-instructors get credit + reporting access, never money (the co-author
	 * model). Resolves to the designated primary instructor (is_primary=1 in
	 * lrn_course_instructors), falling back to the course's author_id when no
	 * primary row exists. Returns 0 for an unknown course.
	 */
	public static function main_author_id( int $course_id ): int {
		if ( $course_id <= 0 ) {
			return 0;
		}
		$instructors = \Learnomy\table( 'course_instructors' );
		$primary     = (int) static::db()->get_var(
			static::db()->prepare(
				"SELECT user_id FROM {$instructors} WHERE course_id = %d AND is_primary = 1 LIMIT 1",
				$course_id
			)
		);
		if ( $primary > 0 ) {
			return $primary;
		}
		$course = static::find( $course_id );
		return $course ? (int) $course->author_id : 0;
	}

	/**
	 * Published courses an instructor teaches — as author OR co-instructor.
	 *
	 * The public profile must credit co-taught courses, not just authored ones
	 * (audit finding F3: `get_published_by_author` hid a co-instructor's whole
	 * catalogue). Same author-OR-junction match as `get_for_instructor`, scoped
	 * to `status='published'` since this feeds a public page.
	 *
	 * Publicly visible only. Setting a course to private used to leave it fully
	 * rendered in the /instructor/{slug}/ grid, because the visibility rule was
	 * locked inside the catalog query (Basecamp 10134139515). Pair with
	 * {@see count_published_for_instructor()} — same WHERE, so the grid and the
	 * header stat can never disagree.
	 *
	 * @param int      $user_id   Instructor user ID.
	 * @param int      $limit     Max rows.
	 * @param int      $offset    Pagination offset.
	 * @param int|null $viewer_id Visibility viewer; see public_visibility_scope().
	 */
	public static function get_published_for_instructor( int $user_id, int $limit = 24, int $offset = 0, ?int $viewer_id = null ): array {
		if ( $user_id <= 0 ) {
			return array();
		}
		$courses                      = static::table();
		$instructors                  = \Learnomy\table( 'course_instructors' );
		[ $visible, $visible_values ] = self::public_listing_where( $viewer_id, 'c' );

		return (array) static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $visible is a model-built fragment; its values are bound below.
				"SELECT DISTINCT c.* FROM {$courses} c
				 LEFT JOIN {$instructors} ci ON ci.course_id = c.id
				 WHERE ( c.author_id = %d OR ci.user_id = %d ) AND {$visible}
				 ORDER BY c.id DESC LIMIT %d OFFSET %d",
				...array_merge( [ $user_id, $user_id ], $visible_values, [ $limit, $offset ] )
			)
		);
	}

	/**
	 * Count companion for {@see get_published_for_instructor()} — the number the
	 * public profile header shows above the grid.
	 *
	 * Deliberately separate from {@see count_for_instructor_by_status()}: that
	 * one is the OWNER's count (the instructor dashboard's "Published" tile) and
	 * must keep counting hidden courses, because the owner can see them. A grid
	 * of 7 under a header saying 8 is the same bug as the leak itself, so the
	 * public surfaces share this one WHERE.
	 *
	 * @param int      $user_id   Instructor user ID.
	 * @param int|null $viewer_id Visibility viewer; see public_visibility_scope().
	 */
	public static function count_published_for_instructor( int $user_id, ?int $viewer_id = null ): int {
		if ( $user_id <= 0 ) {
			return 0;
		}
		$courses                      = static::table();
		$instructors                  = \Learnomy\table( 'course_instructors' );
		[ $visible, $visible_values ] = self::public_listing_where( $viewer_id, 'c' );

		return (int) static::db()->get_var(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $visible is a model-built fragment; its values are bound below.
				"SELECT COUNT( DISTINCT c.id ) FROM {$courses} c
				 LEFT JOIN {$instructors} ci ON ci.course_id = c.id
				 WHERE ( c.author_id = %d OR ci.user_id = %d ) AND {$visible}",
				...array_merge( [ $user_id, $user_id ], $visible_values )
			)
		);
	}

	/**
	 * Count companion for {@see get_for_instructor()} — distinct courses
	 * authored or co-instructed by the user.
	 */
	/**
	 * Course IDs an instructor teaches — authored OR co-instructed.
	 *
	 * The ids-only twin of get_for_instructor(). Reporting needs the scope set
	 * to gate a requested course and to bound every aggregate; hydrating full
	 * course rows to read their ids is wasted work at 200 courses.
	 *
	 * This is THE reporting scope rule. It is deliberately wider than
	 * Permission_Engine::is_course_owner() (main author only), because the
	 * co-author model already promises co-instructors read-only student
	 * reporting. Writes keep the stricter rule; reads use this.
	 *
	 * Pair with count_for_instructor() for pagination — same WHERE, so the
	 * page and the total can never disagree.
	 *
	 * `$limit = 0` returns EVERY id, following the Model::where() convention.
	 * A permission scope must use that form: a scope silently cut off at 100
	 * does not deny the 101st course, it makes the owner's own course vanish
	 * from their reports, and nothing anywhere says why.
	 *
	 * @param int $user_id Instructor user ID.
	 * @param int $limit   Max ids to return. 0 = no limit.
	 * @param int $offset  Pagination offset.
	 * @return int[] Course IDs, newest first.
	 */
	public static function ids_for_instructor( int $user_id, int $limit = 100, int $offset = 0 ): array {
		if ( $user_id <= 0 ) {
			return array();
		}
		$courses     = static::table();
		$instructors = \Learnomy\table( 'course_instructors' );

		$sql = "SELECT DISTINCT c.id FROM {$courses} c
				 LEFT JOIN {$instructors} ci ON ci.course_id = c.id
				 WHERE c.author_id = %d OR ci.user_id = %d
				 ORDER BY c.id DESC";

		$ids = $limit > 0
			? static::db()->get_col(
				static::db()->prepare( $sql . ' LIMIT %d OFFSET %d', $user_id, $user_id, $limit, $offset )
			)
			: static::db()->get_col(
				static::db()->prepare( $sql, $user_id, $user_id )
			);

		return array_map( 'absint', (array) $ids );
	}

	/**
	 * Every course an instructor authors or is assigned to, as the two columns a
	 * permission decision actually needs, with NO row cap.
	 *
	 * `Permission_Engine::owned_course_ids()` used to build its scope from
	 * `get_for_instructor( $user_id, 500, 0 )`. That is a LIMIT on a permission
	 * decision, and a truncated scope does not deny the 501st course - it makes
	 * the owner's own course vanish from their lists, their filters and their
	 * grading queue, with nothing anywhere saying why (Basecamp 10184655982).
	 * `ids_for_instructor()`'s docblock already warned about exactly this shape
	 * at 100; the permission path had the same bug one order of magnitude up.
	 *
	 * Unbounded is safe here precisely because the projection is narrow: two
	 * integers per row, and `decide_course_owner()` reads nothing else. Handing
	 * back `c.*` for 50,000 courses would be a different conversation, which is
	 * why this exists rather than a `$limit = 0` flag on the full-row reader.
	 *
	 * @param int $user_id Instructor user ID.
	 * @return object[] Rows carrying `id` and `author_id` only.
	 */
	public static function ownership_rows_for_instructor( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		$courses     = static::table();
		$instructors = \Learnomy\table( 'course_instructors' );

		return (array) static::db()->get_results(
			static::db()->prepare(
				"SELECT DISTINCT c.id, c.author_id FROM {$courses} c
				 LEFT JOIN {$instructors} ci ON ci.course_id = c.id
				 WHERE c.author_id = %d OR ci.user_id = %d",
				$user_id,
				$user_id
			)
		);
	}

	public static function count_for_instructor( int $user_id ): int {
		if ( $user_id <= 0 ) {
			return 0;
		}
		$courses     = static::table();
		$instructors = \Learnomy\table( 'course_instructors' );
		return (int) static::db()->get_var(
			static::db()->prepare(
				// Must mirror get_for_instructor()'s filter exactly — see the note
				// there. This count is the denominator the My Courses pager
				// divides by.
				"SELECT COUNT( DISTINCT c.id ) FROM {$courses} c
				 LEFT JOIN {$instructors} ci ON ci.course_id = c.id
				 WHERE ( c.author_id = %d OR ci.user_id = %d )
				   AND c.status <> 'trashed'",
				$user_id,
				$user_id
			)
		);
	}

	/**
	 * Count an instructor's courses in a given status (own + co-instructed).
	 * Drives the dashboard "Published" stat so it reflects every course, not
	 * just the first page the dashboard lists.
	 *
	 * OWNER-FACING — no visibility gate, on purpose: the instructor's own
	 * dashboard must count the courses they hid. Any PUBLIC surface wants
	 * {@see count_published_for_instructor()} instead.
	 *
	 * @param int    $user_id Instructor user ID.
	 * @param string $status  Course status to count (e.g. 'published').
	 */
	public static function count_for_instructor_by_status( int $user_id, string $status ): int {
		if ( $user_id <= 0 ) {
			return 0;
		}
		$courses     = static::table();
		$instructors = \Learnomy\table( 'course_instructors' );
		return (int) static::db()->get_var(
			static::db()->prepare(
				"SELECT COUNT( DISTINCT c.id ) FROM {$courses} c
				 LEFT JOIN {$instructors} ci ON ci.course_id = c.id
				 WHERE ( c.author_id = %d OR ci.user_id = %d ) AND c.status = %s",
				$user_id,
				$user_id,
				$status
			)
		);
	}

	/**
	 * Count the DISTINCT students across all of an instructor's courses (own +
	 * co-instructed). Counts unique people, NOT enrollments: a student taking
	 * two of the instructor's courses is one student. The old version summed the
	 * denormalised per-course student_count, so that student counted twice and
	 * the "Total Students" tile + public profile over-reported (18 shown for 12
	 * real students). Scopes via the active enrollment rows so the number always
	 * matches the roster.
	 *
	 * @param int $user_id Instructor user ID.
	 */
	public static function sum_students_for_instructor( int $user_id ): int {
		if ( $user_id <= 0 ) {
			return 0;
		}
		$courses     = static::table();
		$instructors = \Learnomy\table( 'course_instructors' );
		$enrollments = \Learnomy\table( 'enrollments' );
		return (int) static::db()->get_var(
			static::db()->prepare(
				"SELECT COUNT( DISTINCT e.user_id ) FROM {$enrollments} e
				 WHERE e.status IN ( 'active', 'completed' ) AND e.course_id IN (
					SELECT id FROM (
						SELECT DISTINCT c.id FROM {$courses} c
						LEFT JOIN {$instructors} ci ON ci.course_id = c.id
						WHERE c.author_id = %d OR ci.user_id = %d
					) AS instructor_courses
				 )",
				$user_id,
				$user_id
			)
		);
	}

	/**
	 * The DISTINCT-id subquery (as prepared SQL) selecting every course an
	 * instructor owns or co-teaches. Lets cross-table aggregates (enrollment
	 * funnel, pending reviews) scope to ALL of an instructor's courses without
	 * materialising and passing a large id array. Caller embeds it in `IN ( … )`.
	 *
	 * @param int $user_id Instructor user ID.
	 * @return string Prepared SQL subquery, or '0' (matches nothing) when invalid.
	 */
	public static function instructor_course_ids_subquery( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '0';
		}
		$courses     = static::table();
		$instructors = \Learnomy\table( 'course_instructors' );
		return (string) static::db()->prepare(
			"SELECT DISTINCT c.id FROM {$courses} c
			 LEFT JOIN {$instructors} ci ON ci.course_id = c.id
			 WHERE c.author_id = %d OR ci.user_id = %d",
			$user_id,
			$user_id
		);
	}

	// ── Flexible settings (settings_json column) ─────────────────────────────
	//
	// `settings_json` stores every boolean/text course option that doesn't
	// need its own indexed column — engagement toggles, completion message,
	// language, SEO text, retake behaviour, etc. Callers always go through
	// these helpers so default values are consistent.

	/**
	 * Default values for every flexible setting. Any key not present here is
	 * considered unknown — callers asking for it get `$default` back.
	 *
	 * @return array<string,mixed>
	 */
	public static function default_settings(): array {
		return [
			// Completion & certificate.
			'certificate_enabled'     => false,
			'certificate_template_id' => 0,
			'completion_message'      => '',
			'allow_retake'            => false,
			'reset_on_retake'         => false,
			// Progression.
			'require_sequential'      => false,
			'min_time_seconds'        => 0,
			// Prereqs / target / language.
			'language'                => '',
			'target_audience'         => '',
			'learning_outcomes'       => [],
			// Engagement.
			'comments_enabled'        => true,
			'reviews_enabled'         => true,
			'qa_enabled'              => false,
			'announcements_enabled'   => true,
			'welcome_message'         => '',
			// Closed pricing model: optional custom button pointing buyers to
			// wherever the course is actually sold (single-course page only).
			'closed_cta_label'        => '',
			'closed_cta_url'          => '',
			// Visibility.
			'is_featured'             => false,
			// public   = listed in catalog, anyone can enrol.
			// unlisted = hidden from catalog, but the direct link works and
			// anyone can enrol (share-by-link).
			// private  = hidden from catalog AND only enrolled students / the
			// owner / admins can open it (enrolled-only).
			'visibility'              => 'public',
			// Derived mirror of visibility === 'private'. Kept so the existing
			// enrolled-only gate and the Pro front-end builder keep reading one
			// boolean; the save path keeps it in sync with `visibility`.
			'is_private'              => false,
		];
	}

	/**
	 * The course ids a learner must complete before this one. THE decoder.
	 *
	 * `lrn_courses.prerequisites` is stored as a JSON array (written by
	 * Courses_Controller). Nothing owned that fact, so every reader re-derived it:
	 * Free decoded it inline in three places, and Pro's front-end course editor
	 * guessed it was a comma-separated string and did
	 * `array_map( 'intval', explode( ',', $course->prerequisites ) )`.
	 *
	 * Given a stored `[12,34]` that yields **`[0, 34]`** — `explode` produces
	 * `['[12', '34]']`, `intval('[12')` is 0. So the first prerequisite was silently
	 * destroyed and a phantom course id 0 was injected into the picker, and an
	 * instructor merely OPENING a course in the front-end builder and saving it
	 * corrupted the prerequisite chain. Nothing warned them.
	 *
	 * One decoder, called by both plugins. The format now has an owner.
	 *
	 * Tolerates the legacy CSV shape too, so a row written by the broken Pro path
	 * before this fix still reads back sensibly instead of turning into `[0]`.
	 *
	 * @param object|string|null $course A course row, or the raw column value.
	 * @return int[] Positive course ids, deduped, in stored order.
	 */
	/**
	 * Remove one course id from every OTHER course's prerequisites list.
	 *
	 * Called from the course-deletion cascade: a deleted course must not stay
	 * behind as a prerequisite id, because a dangling id used to read as an
	 * unmet prerequisite — a gate nobody could ever pass — while the course
	 * page (which skips dangling ids on display) showed no hint of it
	 * (Basecamp 10155834186). The reader now skips dangling ids too, so this
	 * is hygiene rather than a second gate: it keeps the stored list matching
	 * what every surface actually enforces and displays.
	 *
	 * @param int $course_id The course being deleted.
	 * @return int Number of courses whose prerequisites were rewritten.
	 */
	public static function remove_prerequisite_from_all( int $course_id ): int {
		$db = static::db();

		// Coarse LIKE prefilter — matches any prerequisites JSON containing the
		// digits, then each row is decoded and precisely filtered. The false
		// positives (e.g. id 5 matching "55") cost one decode each and are
		// dropped by the exact comparison below.
		$rows = $db->get_results(
			$db->prepare(
				'SELECT id, prerequisites FROM ' . static::table() . ' WHERE prerequisites LIKE %s AND id != %d',
				'%' . $db->esc_like( (string) $course_id ) . '%',
				$course_id
			)
		);

		$rewritten = 0;
		foreach ( (array) $rows as $row ) {
			$ids = static::prerequisite_ids( $row->prerequisites );
			if ( ! in_array( $course_id, $ids, true ) ) {
				continue;
			}

			$remaining = array_values( array_diff( $ids, array( $course_id ) ) );
			static::update(
				(int) $row->id,
				array( 'prerequisites' => $remaining ? wp_json_encode( $remaining ) : null )
			);
			++$rewritten;
		}

		return $rewritten;
	}

	public static function prerequisite_ids( $course ): array {
		$raw = is_object( $course ) ? ( $course->prerequisites ?? '' ) : $course;
		$raw = is_string( $raw ) ? trim( $raw ) : $raw;

		if ( empty( $raw ) ) {
			return array();
		}

		// Already an array (a caller that decoded upstream).
		if ( is_array( $raw ) ) {
			$ids = $raw;
		} else {
			$decoded = json_decode( (string) $raw, true );
			if ( is_array( $decoded ) ) {
				$ids = $decoded;
			} else {
				// Legacy / hand-edited CSV. Never reachable from our own writer, but a
				// row corrupted by the old Pro path can land here.
				$ids = explode( ',', (string) $raw );
			}
		}

		$ids = array_map( 'absint', (array) $ids );
		$ids = array_filter( $ids );               // Drop 0 — the phantom id.

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Effective catalog/access visibility for a course: 'public', 'unlisted',
	 * or 'private'. Reads the explicit `visibility` setting when present and
	 * falls back to the legacy `is_private` boolean for courses saved before
	 * the three-way control existed (true => private, otherwise public).
	 *
	 * @param array<string,mixed>|null $settings A settings map (e.g. from
	 *                                            get_all_settings()).
	 */
	public static function visibility( ?array $settings ): string {
		$settings = is_array( $settings ) ? $settings : [];

		// The is_private mirror is authoritative for the private case: the save
		// path only ever sets it true for a private course, and legacy courses
		// (saved before the three-way control) carry only this flag. Checking it
		// first also avoids the `get_all_settings()` default merge masking a
		// legacy private course with the 'public' visibility default.
		if ( ! empty( $settings['is_private'] ) ) {
			return 'private';
		}

		$value = isset( $settings['visibility'] ) ? (string) $settings['visibility'] : '';
		if ( in_array( $value, [ 'public', 'unlisted', 'private' ], true ) ) {
			return $value;
		}

		return 'public';
	}

	/*
	 * ── "Hidden from the public" — ONE definition, two shapes ────────────────
	 *
	 * A course is hidden from the public when its visibility is anything other
	 * than `public`: `unlisted` (direct link only) and `private` (enrolled only)
	 * both stay out of every listing, every crawler artefact and every public
	 * count. That rule used to exist as ad-hoc `settings_json NOT LIKE` needles
	 * inlined in build_catalog_filters(), so it was not reusable — and every
	 * other public surface either re-derived it or forgot it. The instructor
	 * profile forgot it (Basecamp 10134139515); the JSON-LD / Open Graph
	 * emitters forgot it and published a private course's title, description
	 * and price on its own 404 response (Basecamp 10134139449).
	 *
	 * The definition now lives in ONE place and answers the same question in
	 * two shapes, both derived from HIDDEN_VISIBILITIES:
	 *
	 *   public_visibility_scope()  — for queries. WHERE fragment + bound values.
	 *   is_hidden_from_public()    — for surfaces that already hold the row.
	 *
	 * Both take the same `$viewer_id` contract, so they can never disagree:
	 *   null  = resolve from the current request (admin sees all, a logged-in
	 *           author sees their own hidden courses). What the catalog does.
	 *   0     = the strictly public question. What a crawler, a sitemap or a
	 *           <head> emitter must ask, so an admin-triggered render never
	 *           bakes a hidden course into a public artefact.
	 *   > 0   = judge for that specific user.
	 */

	/** Visibility values that keep a course out of every public listing. */
	public const HIDDEN_VISIBILITIES = [ 'unlisted', 'private' ];

	/**
	 * Resolve the viewer the visibility rule is being judged for.
	 *
	 * @param int|null $viewer_id See the block comment above.
	 * @return array{0: int, 1: bool} [ viewer id, viewer sees every course ].
	 */
	private static function resolve_visibility_viewer( ?int $viewer_id ): array {
		if ( null === $viewer_id ) {
			return [
				get_current_user_id(),
				function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ),
			];
		}

		$viewer_id = max( 0, $viewer_id );

		return [ $viewer_id, $viewer_id > 0 && user_can( $viewer_id, 'manage_options' ) ];
	}

	/**
	 * The `settings_json` LIKE needles that mark a course hidden, derived from
	 * HIDDEN_VISIBILITIES so the SQL shape and {@see visibility()} can only ever
	 * describe the same set. LIKE on static needles (rather than JSON_EXTRACT)
	 * keeps this MySQL 5.6 compatible.
	 *
	 * @return string[]
	 */
	private static function hidden_visibility_needles(): array {
		$needles = [];
		foreach ( self::HIDDEN_VISIBILITIES as $value ) {
			$needles[] = '%"visibility":"' . $value . '"%';
		}
		// Legacy mirror — courses saved before the three-way control carry only
		// this boolean, and visibility() reads it as private.
		$needles[] = '%"is_private":true%';
		return $needles;
	}

	/**
	 * Which courses may this viewer see in a public listing? Query shape.
	 *
	 * Returns a WHERE fragment list plus the values to bind, ready to merge into
	 * any course query. An empty fragment list means "no restriction" (the
	 * viewer sees every course), so callers append unconditionally.
	 *
	 * @param int|null $viewer_id See the block comment above.
	 * @param string   $alias     Table alias used by the caller's query (e.g. 'c').
	 * @return array{0: string[], 1: array<int,mixed>}
	 */
	public static function public_visibility_scope( ?int $viewer_id = null, string $alias = '' ): array {
		[ $viewer_id, $sees_everything ] = self::resolve_visibility_viewer( $viewer_id );

		if ( $sees_everything ) {
			return [ [], [] ];
		}

		$prefix   = self::column_prefix( $alias );
		$settings = $prefix . 'settings_json';
		$author   = $prefix . 'author_id';
		$needles  = self::hidden_visibility_needles();
		$not_like = implode( ' AND ', array_fill( 0, count( $needles ), $settings . ' NOT LIKE %s' ) );

		// NOT LIKE against NULL is NULL, which would drop every course that has
		// never had settings saved — hence the explicit IS NULL arm.
		if ( $viewer_id > 0 ) {
			return [
				[ "({$author} = %d OR {$settings} IS NULL OR ({$not_like}))" ],
				array_merge( [ $viewer_id ], $needles ),
			];
		}

		return [
			[ "({$settings} IS NULL OR ({$not_like}))" ],
			$needles,
		];
	}

	/**
	 * Is this course hidden from the viewer? Row shape.
	 *
	 * The twin of {@see public_visibility_scope()} for surfaces that already
	 * hold the row — the <head> metadata emitters, mostly. Same vocabulary, same
	 * viewer contract, same answer.
	 *
	 * A course row selected with a partial column list (CARD_COLUMNS omits
	 * settings_json) is re-read from the model rather than silently reading as
	 * public — a visibility test that fails open is worse than no test.
	 *
	 * @param object|array<string,mixed>|string|null $course    Course row, a decoded
	 *                                                          settings map, or the raw
	 *                                                          settings_json string.
	 * @param int|null                               $viewer_id See the block comment above.
	 */
	public static function is_hidden_from_public( $course, ?int $viewer_id = null ): bool {
		[ $viewer_id, $sees_everything ] = self::resolve_visibility_viewer( $viewer_id );

		if ( $sees_everything ) {
			return false;
		}

		$settings  = [];
		$author_id = 0;

		if ( is_object( $course ) ) {
			$author_id = (int) ( $course->author_id ?? 0 );
			if ( property_exists( $course, 'settings_json' ) ) {
				$raw      = (string) ( $course->settings_json ?? '' );
				$settings = '' !== $raw ? json_decode( $raw, true ) : [];
			} elseif ( ! empty( $course->id ) ) {
				$settings = self::get_all_settings( (int) $course->id );
			}
		} elseif ( is_array( $course ) ) {
			$settings = $course;
		} elseif ( is_string( $course ) && '' !== $course ) {
			$settings = json_decode( $course, true );
		}

		// The author always sees their own hidden courses — mirrors the scope.
		if ( $viewer_id > 0 && $author_id > 0 && $author_id === $viewer_id ) {
			return false;
		}

		return in_array(
			self::visibility( is_array( $settings ) ? $settings : [] ),
			self::HIDDEN_VISIBILITIES,
			true
		);
	}

	/**
	 * "Published AND visible to this viewer" — the WHERE every public listing
	 * query is built from, so the gate cannot be present in one listing and
	 * missing from its own count.
	 *
	 * Public because the category counts ask it too: a tally rendered beside a
	 * category on a public page has to be scoped exactly like the grid it
	 * promises, and re-deriving `status = 'published'` there is how the two
	 * drifted apart.
	 *
	 * @param int|null $viewer_id See the block comment above.
	 * @param string   $alias     Table alias used by the caller's query.
	 * @return array{0: string, 1: array<int,mixed>} [ WHERE fragment, values ].
	 */
	public static function public_listing_where( ?int $viewer_id = null, string $alias = '' ): array {
		[ $scope_sql, $scope_values ] = self::public_visibility_scope( $viewer_id, $alias );

		$sql = self::column_prefix( $alias ) . "status = '" . self::STATUS_PUBLISHED . "'";
		if ( ! empty( $scope_sql ) ) {
			$sql .= ' AND ' . implode( ' AND ', $scope_sql );
		}

		return [ $sql, $scope_values ];
	}

	/**
	 * "Filed under this category" — ONE definition, for every listing query.
	 *
	 * The WHERE fragment that answers which courses belong to a category, with
	 * its nested categories rolled in ({@see Category::descendant_ids()}). Four
	 * separate queries used to spell this as `category_id = %d` by hand — the
	 * archive page, its COUNT twin, the shortcode/blocks reader and the catalog
	 * filter — so nesting worked in the UI (subcategory chips render) and
	 * nowhere in the data (Basecamp 10134139480). Spelling it once is what
	 * keeps a listing and its own count from disagreeing.
	 *
	 * @param int    $category_id Category id.
	 * @param string $alias       Table alias used by the caller's query.
	 * @return array{0: string, 1: array<int,mixed>} [ WHERE fragment, values ].
	 */
	public static function category_scope( int $category_id, string $alias = '' ): array {
		$ids = \Learnomy\Models\Category::descendant_ids( $category_id );

		$column = self::column_prefix( $alias ) . 'category_id';

		// A category that does not exist matches nothing rather than matching
		// everything — the same fail-closed choice build_catalog_filters()
		// makes for an unknown slug.
		if ( empty( $ids ) ) {
			return [ '1 = 0', [] ];
		}

		if ( 1 === count( $ids ) ) {
			return [ $column . ' = %d', $ids ];
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		return [ $column . ' IN (' . $placeholders . ')', $ids ];
	}

	/** Sanitised `alias.` prefix (or '') for the fragments built above. */
	private static function column_prefix( string $alias ): string {
		$alias = preg_replace( '/[^A-Za-z0-9_]/', '', $alias );
		return '' !== (string) $alias ? $alias . '.' : '';
	}

	/**
	 * Decode the `settings_json` column, merge over defaults. Returns the
	 * complete settings map — never null, never missing keys — so templates
	 * and controllers can read any setting without guards.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_all_settings( int $course_id ): array {
		$row = static::db()->get_var(
			static::db()->prepare(
				'SELECT settings_json FROM ' . static::table() . ' WHERE id = %d LIMIT 1',
				$course_id
			)
		);

		$stored = is_string( $row ) && '' !== $row ? json_decode( $row, true ) : [];
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}

		return array_merge( static::default_settings(), $stored );
	}

	/**
	 * Does this course award a certificate?
	 *
	 * THE definition. There were four hand-rolled copies and two of them
	 * disagreed, which is the whole of Basecamp 10171331421.
	 *
	 * The ISSUE path read "per-course flag OR the global default", documented
	 * deliberately: an instructor who switches it on gets it regardless of the
	 * site setting, and everyone else inherits the site default. The DISPLAY
	 * path read the per-course flag ALONE. So on the default configuration —
	 * global on, per-course off, which is what most courses ship as — Learnomy
	 * issued certificates it then refused to show on the completion screen. Not
	 * an edge case: the default.
	 *
	 * A third copy in the notifier used the per-course flag to decide whether
	 * the certificate email would cover the completion email, so on that same
	 * default it sent BOTH — re-opening the double-email defect from Basecamp
	 * 9932113730.
	 *
	 * One reader now. If you need this answer, call this; do not re-derive it.
	 *
	 * @param int $course_id Course.
	 * @return bool
	 */
	public static function certificates_enabled( int $course_id ): bool {
		if ( ! \Learnomy\module_enabled( 'certificates' ) ) {
			return false;
		}

		$settings = static::get_all_settings( $course_id );
		if ( ! empty( $settings['certificate_enabled'] ) ) {
			return true;
		}

		$global = get_option( 'learnomy_settings', array() );

		return ! empty( $global['enable_certificates'] );
	}

	public static function get_setting( int $course_id, string $key, mixed $default = null ): mixed {
		$all = static::get_all_settings( $course_id );
		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}
		return $default;
	}

	/**
	 * Merge `$updates` into the stored settings JSON and write it back.
	 * Keys not in {@see self::default_settings()} are still accepted so
	 * add-ons can extend the map without changing this model.
	 *
	 * @param int                 $course_id Course id.
	 * @param array<string,mixed> $updates   Settings keys to merge.
	 */
	public static function update_settings( int $course_id, array $updates ): bool {
		if ( empty( $updates ) ) {
			return true;
		}

		$current = static::get_all_settings( $course_id );
		$merged  = array_merge( $current, $updates );

		// Keep the stored JSON small — only persist values that differ from
		// the defaults. On read, missing keys are filled in from defaults.
		$defaults = static::default_settings();
		$to_store = [];
		foreach ( $merged as $k => $v ) {
			if ( ! array_key_exists( $k, $defaults ) || $v !== $defaults[ $k ] ) {
				$to_store[ $k ] = $v;
			}
		}

		return static::update(
			$course_id,
			[ 'settings_json' => wp_json_encode( $to_store ) ]
		);
	}

	public static function set_setting( int $course_id, string $key, mixed $value ): bool {
		return static::update_settings( $course_id, [ $key => $value ] );
	}

	/**
	 * Frontend gate for the course reviews surface.
	 *
	 * Reviews show on the public course page only when BOTH gates pass:
	 *   1. Site owner has Learnomy Settings → Reviews → Enable Reviews on.
	 *   2. The course's per-course `reviews_enabled` flag is not explicitly off.
	 *
	 * The admin form keeps editing the per-course flag independently, so a
	 * site owner can toggle reviews off globally and have every course honour
	 * it without losing each course's individual preference for later.
	 *
	 * Use this helper at every render gate so the rule cannot drift between
	 * templates and partials. Re-fetches inside nested partials (e.g.
	 * `templates/partials/curriculum.php`) overwrite the local
	 * `$course_settings` array, so reading the merged value through this
	 * helper is the only safe pattern.
	 */
	public static function reviews_enabled_for( int $course_id ): bool {
		$global = get_option( 'learnomy_settings', array() );
		if ( empty( $global['enable_reviews'] ) ) {
			return false;
		}
		$settings = static::get_all_settings( $course_id );
		return false !== ( $settings['reviews_enabled'] ?? true );
	}

	/**
	 * Convenience predicate used by the catalog Featured badge renderer
	 * (wired via the `learnomy_course_card_badges` action in the card
	 * template). Centralizes the cast so templates never read raw
	 * settings_json.
	 */
	public static function is_featured( int $id ): bool {
		$settings = self::get_all_settings( $id );
		return ! empty( $settings['is_featured'] );
	}

	public static function get_published_by_author( int $user_id, int $limit = 24 ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . " WHERE author_id = %d AND status = 'published' ORDER BY created_at DESC LIMIT %d",
				$user_id,
				$limit
			)
		);
	}

	/**
	 * Course recommendations for a user — published courses in the same
	 * categories the user is already enrolled in, EXCLUDING courses
	 * they already hold an enrollment for. Falls back to popular
	 * published courses when the user has no category overlap (e.g. a
	 * brand-new student whose first enrollment had no category).
	 *
	 * Used by the student-dashboard "Recommended" widget. Cheap: two
	 * indexed queries (enrolled course ids → category ids; published
	 * by category set, NOT IN enrolled set).
	 *
	 * @return object[] Up to $limit course rows (id, title, slug, etc.).
	 */
	public static function recommended_for_user( int $user_id, int $limit = 3 ): array {
		if ( $user_id <= 0 || ! class_exists( '\\Learnomy\\Models\\Enrollment' ) ) {
			return array();
		}
		global $wpdb;
		$enrollments_table = $wpdb->prefix . 'lrn_enrollments';
		$courses_table     = static::table();

		// 1) enrolled course ids
		$enrolled_ids = array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT course_id FROM {$enrollments_table} WHERE user_id = %d AND course_id > 0",
					$user_id
				)
			)
		);

		// 2) category ids for those courses
		$cat_ids = array();
		if ( ! empty( $enrolled_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $enrolled_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$cat_ids = array_map(
				'intval',
				(array) $wpdb->get_col(
					$wpdb->prepare(
						"SELECT DISTINCT category_id FROM {$courses_table} WHERE id IN ($placeholders) AND category_id > 0",
						...$enrolled_ids
					)
				)
			);
		}

		// 3) candidates = published in those categories minus enrolled
		if ( ! empty( $cat_ids ) ) {
			$cat_placeholders = implode( ',', array_fill( 0, count( $cat_ids ), '%d' ) );
			$not_in_clause    = '';
			$args             = $cat_ids;
			if ( ! empty( $enrolled_ids ) ) {
				$en_placeholders = implode( ',', array_fill( 0, count( $enrolled_ids ), '%d' ) );
				$not_in_clause   = " AND id NOT IN ($en_placeholders)";
				$args            = array_merge( $args, $enrolled_ids );
			}
			// Never recommend a course the student cannot open: an unlisted or
			// private course surfaced here links straight to a 404.
			[ $visible, $visible_values ] = self::public_listing_where( $user_id );
			$args                         = array_merge( $args, $visible_values );
			$args[]                       = $limit;
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT ' . self::CARD_COLUMNS . "
					 FROM {$courses_table}
					 WHERE category_id IN ($cat_placeholders)$not_in_clause AND {$visible}
					 ORDER BY student_count DESC, avg_rating DESC
					 LIMIT %d",
					...$args
				)
			);
			if ( ! empty( $rows ) ) {
				return self::attach_card_meta( $rows );
			}
		}

		// 4) Fallback: popular published courses the user hasn't enrolled in
		$not_in_clause = '';
		$args          = array();
		if ( ! empty( $enrolled_ids ) ) {
			$en_placeholders = implode( ',', array_fill( 0, count( $enrolled_ids ), '%d' ) );
			$not_in_clause   = " AND id NOT IN ($en_placeholders)";
			$args            = $enrolled_ids;
		}
		[ $visible, $visible_values ] = self::public_listing_where( $user_id );
		$args                         = array_merge( $args, $visible_values );
		$args[]                       = $limit;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT ' . self::CARD_COLUMNS . "
				 FROM {$courses_table}
				 WHERE 1 = 1{$not_in_clause} AND {$visible}
				 ORDER BY student_count DESC, avg_rating DESC
				 LIMIT %d",
				...$args
			)
		);
		return self::attach_card_meta( $rows );
	}

	/**
	 * Publicly listable published courses in a category.
	 *
	 * @param int      $category_id Category id.
	 * @param int      $limit       Max rows.
	 * @param int|null $viewer_id   Visibility viewer; see public_visibility_scope().
	 */
	public static function get_published_by_category( int $category_id, int $limit = 48, ?int $viewer_id = null ): array {
		[ $where, $where_values ]        = self::public_listing_where( $viewer_id );
		[ $category_sql, $category_ids ] = self::category_scope( $category_id );

		return static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where and $category_sql are model-built fragments; their values are bound below.
				'SELECT * FROM ' . static::table() . " WHERE {$category_sql} AND {$where} ORDER BY created_at DESC LIMIT %d",
				...array_merge( $category_ids, $where_values, [ $limit ] )
			)
		);
	}

	/**
	 * One page of published courses in a category, newest first.
	 *
	 * Backs the paginated category archive (/courses/category/:slug/page/N/).
	 * Uses the composite index category_status_created (category_id, status,
	 * created_at) so it stays index-only at any catalog size.
	 *
	 * @param int      $category_id Category id.
	 * @param int      $per_page    Rows per page.
	 * @param int      $offset      Pagination offset.
	 * @param int|null $viewer_id   Visibility viewer; see public_visibility_scope().
	 */
	public static function get_published_by_category_page( int $category_id, int $per_page, int $offset, ?int $viewer_id = null ): array {
		[ $where, $where_values ]        = self::public_listing_where( $viewer_id );
		[ $category_sql, $category_ids ] = self::category_scope( $category_id );

		return static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where and $category_sql are model-built fragments; their values are bound below.
				'SELECT * FROM ' . static::table() . " WHERE {$category_sql} AND {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
				...array_merge( $category_ids, $where_values, [ max( 1, $per_page ), max( 0, $offset ) ] )
			)
		) ?: array();
	}

	/**
	 * Total publicly listable published courses in a category. Same WHERE as
	 * {@see get_published_by_category_page()}, so the archive's pagination and
	 * its rows agree.
	 *
	 * @param int      $category_id Category id.
	 * @param int|null $viewer_id   Visibility viewer; see public_visibility_scope().
	 */
	public static function count_published_by_category( int $category_id, ?int $viewer_id = null ): int {
		[ $where, $where_values ]        = self::public_listing_where( $viewer_id );
		[ $category_sql, $category_ids ] = self::category_scope( $category_id );

		return (int) static::db()->get_var(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where and $category_sql are model-built fragments; their values are bound below.
				'SELECT COUNT(*) FROM ' . static::table() . " WHERE {$category_sql} AND {$where}",
				...array_merge( $category_ids, $where_values )
			)
		);
	}

	/**
	 * Most-enrolled publicly listable courses. Backs the Popular Courses widget.
	 *
	 * @param int      $limit     Max rows.
	 * @param int|null $viewer_id Visibility viewer; see public_visibility_scope().
	 */
	public static function get_popular( int $limit = 10, ?int $viewer_id = null ): array {
		[ $where, $where_values ] = self::public_listing_where( $viewer_id );

		return static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is a model-built fragment; its values are bound below.
				'SELECT * FROM ' . static::table() . " WHERE {$where} ORDER BY student_count DESC LIMIT %d",
				...array_merge( $where_values, [ $limit ] )
			)
		);
	}

	/**
	 * Aggregate stats for an instructor's publicly listable courses — backs the
	 * `[learnomy_instructor]` shortcode and the Instructor widget, both of which
	 * render on public pages, so the count must not include hidden courses.
	 *
	 * @param int      $user_id   Instructor user ID.
	 * @param int|null $viewer_id Visibility viewer; see public_visibility_scope().
	 */
	/**
	 * The SQL that averages a set of course rows into an instructor rating.
	 *
	 * Two things it does that a plain AVG(avg_rating) does not, and both matter:
	 *
	 * 1. It IGNORES courses with no reviews. An unrated course stores
	 *    avg_rating = 0.00, so averaging over every course dragged the figure
	 *    toward zero for each course still waiting for its first review — a
	 *    five-star instructor with six unreviewed courses displayed as 0.71.
	 *    Publishing a course made your public rating worse, which is exactly
	 *    backwards.
	 * 2. It WEIGHTS by review_count. Among rated courses a plain mean lets one
	 *    course with a single 3-star review cancel out one with forty at 4.9.
	 *    The rating people mean is "across all the reviews", not "across the
	 *    courses".
	 *
	 * NULL when the instructor has no reviewed course at all — the caller shows
	 * "no ratings yet" rather than 0.0, which reads as a genuine zero-star score.
	 *
	 * @param string $alias Table alias in the calling query ('' for none).
	 */
	private static function rating_select( string $alias = '' ): string {
		$col = '' !== $alias ? $alias . '.' : '';

		return "SUM( CASE WHEN {$col}review_count > 0 THEN {$col}avg_rating * {$col}review_count ELSE 0 END )
		        / NULLIF( SUM( CASE WHEN {$col}review_count > 0 THEN {$col}review_count ELSE 0 END ), 0 )";
	}

	public static function get_instructor_stats( int $user_id, ?int $viewer_id = null ): object {
		[ $where, $where_values ] = self::public_listing_where( $viewer_id );

		return static::db()->get_row(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is a model-built fragment; its values are bound below.
				'SELECT COUNT(*) AS course_count, ' . self::rating_select() . ' AS avg_rating FROM ' . static::table() . " WHERE author_id = %d AND {$where}",
				...array_merge( [ $user_id ], $where_values )
			)
		) ?: (object) [
			'course_count' => 0,
			'avg_rating'   => 0,
		];
	}

	/**
	 * The people teaching a course — {user_id, share_pct, is_primary} rows,
	 * primary first.
	 *
	 * Reads the `lrn_course_instructors` junction, which is written on every
	 * assignment and is what the course page, the instructor profile and
	 * {@see get_instructor_card_stats()} already treat as authoritative.
	 *
	 * It used to decode the denormalised `instructors_json` column instead. That
	 * column has NO writer anywhere in Free or Pro — it is empty on every one of
	 * this install's published courses — so every caller silently got an empty
	 * list: JSON-LD published `"instructor": null` on every course page, the
	 * comment notifier had nobody to notify, and the lesson player credited
	 * nobody (Basecamp 10134139449). Reading the junction is the fix rather than
	 * back-filling and maintaining the column, because the junction is already
	 * the source of truth and a denormalisation with one reader and no writer is
	 * dead weight, not a cache (Rule 4a / 4c).
	 *
	 * Falls back to the course's own author when the junction has no row, so a
	 * course created by a path that never wrote one still names its teacher —
	 * the same fallback {@see main_author_id()} uses.
	 *
	 * @return object[] Junction rows, primary first.
	 */
	public static function get_instructors( int $course_id ): array {
		if ( $course_id <= 0 ) {
			return [];
		}

		$rows = CourseInstructor::get_for_course( $course_id );
		if ( ! empty( $rows ) ) {
			return $rows;
		}

		$course    = static::find( $course_id );
		$author_id = $course ? (int) ( $course->author_id ?? 0 ) : 0;
		if ( $author_id <= 0 ) {
			return [];
		}

		return [
			(object) [
				'course_id'  => $course_id,
				'user_id'    => $author_id,
				'share_pct'  => 100.00,
				'is_primary' => 1,
			],
		];
	}

	/**
	 * Instructor card stats: published-course count and average rating.
	 *
	 * Counts from the authoritative `lrn_course_instructors` junction (kept
	 * current on every assignment), not the denormalised `instructors_json`
	 * column — that column is not maintained on this install, so the old
	 * JSON_CONTAINS query returned 0 courses for every instructor even when
	 * they taught several. Used by the instructor-card block, which renders on
	 * public pages — so hidden courses are excluded here too.
	 *
	 * @param int      $user_id   Instructor user ID.
	 * @param int|null $viewer_id Visibility viewer; see public_visibility_scope().
	 */
	public static function get_instructor_card_stats( int $user_id, ?int $viewer_id = null ): object {
		$courses                      = static::table();
		$junction                     = static::db()->prefix . 'lrn_course_instructors';
		[ $visible, $visible_values ] = self::public_listing_where( $viewer_id, 'c' );

		return static::db()->get_row(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are internal constants; every value is bound.
				'SELECT COUNT(DISTINCT c.id) AS course_count, ' . self::rating_select( 'c' ) . " AS avg_rating
				 FROM {$junction} ci
				 INNER JOIN {$courses} c ON c.id = ci.course_id
				 WHERE ci.user_id = %d AND {$visible}",
				...array_merge( [ $user_id ], $visible_values )
			)
		) ?: (object) [
			'course_count' => 0,
			'avg_rating'   => 0,
		];
	}

	/**
	 * Paginated slug + updated_at for sitemap generation.
	 *
	 * Always asks the strictly public question (viewer 0), never "what can the
	 * person who happened to trigger this render see" — a sitemap is a crawler
	 * artefact, so an admin-initiated regeneration must not publish the URL of
	 * an unlisted or private course.
	 */
	public static function get_sitemap_page( int $per_page, int $offset ): array {
		[ $where, $where_values ] = self::public_listing_where( 0 );

		return static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is a model-built fragment; its values are bound below.
				'SELECT slug, updated_at FROM ' . static::table() . " WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
				...array_merge( $where_values, [ $per_page, $offset ] )
			)
		) ?: [];
	}

	/**
	 * The users who have a public instructor profile — everyone who authors or
	 * co-teaches at least one publicly visible published course.
	 *
	 * The set form of {@see \Learnomy\Models\Instructor::has_public_profile()},
	 * so the sitemap lists EXACTLY the profile URLs that render. The provider
	 * used to enumerate the `lrn_instructor` role instead, which is a different
	 * set in both directions: it published the username of every approved
	 * instructor who has nothing live yet (URLs that now 404 — soft-404s fed to
	 * crawlers, and a member roster nobody asked to publish), and it omitted the
	 * administrator who authors the courses on a single-owner academy.
	 *
	 * Viewer 0 always: a sitemap is a crawler artefact, so an admin-triggered
	 * regeneration must not widen it (mirrors get_sitemap_page()).
	 *
	 * Ordered by user id so paging is stable across the two queries.
	 *
	 * @param int $limit  Max ids to return.
	 * @param int $offset Pagination offset.
	 * @return int[]
	 */
	public static function public_instructor_ids( int $limit, int $offset ): array {
		$ids = static::db()->get_col(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- built from the model's own visibility fragment; values are bound below.
				self::public_instructor_ids_sql() . ' ORDER BY uid ASC LIMIT %d OFFSET %d',
				...array_merge( self::public_instructor_ids_values(), [ $limit, $offset ] )
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * COUNT companion for {@see public_instructor_ids()} — same subquery, so the
	 * sitemap's page count and its pages can never describe different sets.
	 */
	public static function count_public_instructors(): int {
		return (int) static::db()->get_var(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- built from the model's own visibility fragment; values are bound below.
				'SELECT COUNT(*) FROM ( ' . self::public_instructor_ids_sql() . ' ) counted',
				...self::public_instructor_ids_values()
			)
		);
	}

	/**
	 * The `SELECT uid` UNION shared by the two methods above. Authors come from
	 * `lrn_courses.author_id` (KEY author_status), co-instructors from
	 * `lrn_course_instructors.user_id` (KEY user_id); UNION de-duplicates the
	 * person who is both.
	 */
	private static function public_instructor_ids_sql(): string {
		$courses               = static::table();
		$instructors           = \Learnomy\table( 'course_instructors' );
		[ $visible, $ignored ] = self::public_listing_where( 0, 'c' );
		unset( $ignored );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $visible is a model-built fragment; the caller binds its values.
		return "SELECT uid FROM (
			SELECT c.author_id AS uid FROM {$courses} c WHERE {$visible} AND c.author_id > 0
			UNION
			SELECT ci.user_id AS uid FROM {$instructors} ci
			 INNER JOIN {$courses} c ON c.id = ci.course_id
			 WHERE {$visible} AND ci.user_id > 0
		) public_instructors";
	}

	/** Bound values for {@see public_instructor_ids_sql()} — the WHERE appears twice. */
	private static function public_instructor_ids_values(): array {
		[ $ignored, $values ] = self::public_listing_where( 0, 'c' );
		unset( $ignored );

		return array_merge( $values, $values );
	}

	// ── Pricing-model helpers (PR-0 foundation refactor) ────────────────
	//
	// Today the `lrn_courses` table stores `access_type` as the legacy
	// 4-value enum (`free`, `paid`, `membership`, `paid_or_membership`).
	// PR-A1 will add a `pricing_model` column with a richer vocabulary
	// (`free`, `one_time`, `fixed_term`, `recurring`, `members_only`,
	// `one_time_or_members`). These helpers read from `pricing_model`
	// when present, otherwise derive from `access_type` so PR-0 ships
	// without the schema change.
	//
	// All helpers take a `stdClass` course row (the shape `Course::find()`
	// returns), not a hydrated Course instance — that's the existing
	// pattern across the rest of this file.

	/**
	 * Map a course row's stored access_type / pricing_model to the
	 * canonical pricing-model identifier the helpers below all switch on.
	 *
	 * Returns one of: `free`, `one_time`, `fixed_term`, `recurring`,
	 * `members_only`, `one_time_or_members`. Defaults to `free` when the
	 * row carries an unknown value.
	 */
	/**
	 * The canonical pricing_model for a given access_type (access -> model).
	 * Reads the ACCESS_PRIMARY_MODEL map; unknown access_type falls back to free.
	 */
	public static function model_for_access( string $access_type ): string {
		return self::ACCESS_PRIMARY_MODEL[ $access_type ] ?? 'free';
	}

	/**
	 * The access_type a pricing_model stores under (model -> access). Reads the
	 * MODEL_ACCESS_MAP; unknown model falls back to free.
	 */
	public static function access_for_model( string $model ): string {
		return self::MODEL_ACCESS_MAP[ $model ] ?? 'free';
	}

	public static function pricing_model( object $course ): string {
		$access_type = isset( $course->access_type ) ? (string) $course->access_type : 'free';
		$from_access = self::model_for_access( $access_type );

		$explicit = isset( $course->pricing_model ) ? (string) $course->pricing_model : '';
		if ( '' === $explicit ) {
			return $from_access;
		}

		// Stale-column guard. The pricing_model column defaults to 'free' in
		// the schema, and a legacy admin save that wrote only `access_type`
		// left it at that default — so a paid / membership course can carry a
		// stale `pricing_model='free'`. When the column says 'free' but
		// access_type disagrees, the column is stale: trust access_type. We
		// only distrust the column in the dangerous (free-overriding-paid)
		// direction; every richer PR-A1 value (one_time / fixed_term /
		// recurring / members_only / one_time_or_members) is preserved because
		// it maps to a non-free access_type. A genuine free course keeps
		// 'free' since access_type also says free.
		if ( 'free' === $explicit && 'free' !== $from_access ) {
			return $from_access;
		}

		return $explicit;
	}

	/** True when the course is freely accessible — no purchase, no membership. */
	public static function is_free( object $course ): bool {
		return 'free' === self::pricing_model( $course );
	}

	/**
	 * True when the course is "closed": not purchasable on-site. Access is
	 * granted externally (webhook / manual / CSV) and the course carries no
	 * on-site price. The single-course page may show a custom external button.
	 *
	 * Asks MODEL_ACCESS_MAP rather than comparing the model literal, because
	 * `closed` is an ACCESS RULE that more than one pricing model resolves to.
	 * `invite_only` is the second one, and it is the same rule by construction —
	 * the map says so, and the two differ only in wording. Comparing the literal
	 * made every caller answer "no" for an invite-only course, which showed an
	 * "Enroll Now" button directly under the words "Invite only", let it past the
	 * checkout guard on line 466 of the checkout controller, and had the external
	 * enrolment webhook reject the one access model that has no other way in.
	 * Adding a third model that maps to `closed` must not reopen any of that.
	 */
	/**
	 * True when checkout may offer this course for nothing.
	 *
	 * Free-ness at the till is NOT "the price column is zero". A closed or
	 * invite-only course carries no price by construction, so a bare zero-price
	 * test declared it free and the checkout offered "Enroll for Free" on a
	 * course nobody may enrol themselves into - the button then failed at the
	 * API, which is the correct answer to a request that should never have been
	 * offered.
	 *
	 * This exists because the rule was authored TWICE - once in the checkout
	 * view-data and once in the template loader - and a fix to either one left
	 * the other showing the old button. One rule, one place, both callers.
	 *
	 * @param object $course     Course row.
	 * @param float  $price      Resolved price for the item.
	 * @param float  $sale_price Resolved sale price, if any.
	 */
	public static function is_purchasable_free( object $course, float $price, float $sale_price = 0.0 ): bool {
		if ( self::is_closed( $course ) ) {
			return false;
		}

		return self::is_free( $course ) || ( $price <= 0 && $sale_price <= 0 );
	}

	public static function is_closed( object $course ): bool {
		return 'closed' === self::access_for_model( self::pricing_model( $course ) );
	}

	/**
	 * True for the invite-only half of the closed access rule.
	 *
	 * `is_closed()` answers the ACCESS question — may a visitor enrol themselves,
	 * may this be sold, does the webhook manage it — and invite-only answers it
	 * identically. This answers the WORDING question, which is where the two
	 * differ: a `closed` course is provisioned somewhere else, so "External" and
	 * "Enrol externally" are true of it, while an invite-only course is
	 * provisioned right here by the owner and those words describe nothing.
	 *
	 * Three surfaces asked this by comparing the model literal inline. It lives
	 * here so a surface added later gets the distinction for free rather than
	 * inheriting closed-course wording by default.
	 */
	public static function is_invite_only( object $course ): bool {
		return 'invite_only' === self::pricing_model( $course );
	}

	/**
	 * One plain-English sentence describing who can see the course and how a
	 * student gets in — composed from visibility + pricing model. This is the
	 * single producer consumed by BOTH the course editor (live "what students
	 * will experience" line) and the admin status banner, so the two never
	 * drift (Basecamp #10052638309 §9.1). Not escaped — the caller escapes.
	 */
	public static function access_summary( object $course ): string {
		$settings = self::get_all_settings( (int) ( $course->id ?? 0 ) );

		return self::compose_access_summary(
			self::visibility( $settings ),
			self::pricing_model( $course )
		);
	}

	/**
	 * The clause maps + sentence template behind access_summary(). Exposed so
	 * the course editor can localize them to JS and recompose the sentence live
	 * as the owner toggles visibility / pricing — one source, no duplicated copy.
	 *
	 * @return array{template:string,visibility:array<string,string>,entry:array<string,string>}
	 */
	public static function access_summary_parts(): array {
		return array(
			/* translators: 1: visibility clause, 2: how-students-enrol clause */
			'template'   => __( 'This course is %1$s. To get in, %2$s.', 'learnomy' ),
			'visibility' => array(
				'public'   => __( 'listed in your catalog', 'learnomy' ),
				'unlisted' => __( 'hidden from your catalog, but anyone with the link can reach it', 'learnomy' ),
				'private'  => __( 'hidden from your catalog - only enrolled students can open it', 'learnomy' ),
			),
			'entry'      => array(
				'closed'              => __( "students can't buy it here - you grant access (by email, CSV upload, or a connected external system)", 'learnomy' ),
				// Without its own entry an invite-only course fell to the default
				// and the summary told the owner "students buy it at checkout" —
				// the one thing this model does not do.
				'invite_only'         => __( 'you add the students - there is no checkout', 'learnomy' ),
				'free'                => __( 'anyone signed in can enroll for free', 'learnomy' ),
				'members_only'        => __( 'members whose plan includes it can enroll', 'learnomy' ),
				'one_time_or_members' => __( 'students can buy it outright or use their membership', 'learnomy' ),
				'recurring'           => __( 'students subscribe to keep access', 'learnomy' ),
				'_default'            => __( 'students buy it at checkout', 'learnomy' ),
			),
		);
	}

	/** Compose the access-summary sentence from a visibility + pricing model. */
	public static function compose_access_summary( string $visibility, string $model ): string {
		$parts = self::access_summary_parts();

		return sprintf(
			$parts['template'],
			$parts['visibility'][ $visibility ] ?? $parts['visibility']['public'],
			$parts['entry'][ $model ] ?? $parts['entry']['_default']
		);
	}

	/**
	 * True when the course has a one-time-purchase path. Includes
	 * `one_time_or_members` because that model still exposes a real-money
	 * purchase route alongside the membership path.
	 */
	public static function is_one_time( object $course ): bool {
		return in_array( self::pricing_model( $course ), array( 'one_time', 'one_time_or_members' ), true );
	}

	/** True when the course grants time-bounded access (added in PR-A1). */
	public static function is_fixed_term( object $course ): bool {
		return 'fixed_term' === self::pricing_model( $course );
	}

	/**
	 * What this course COSTS, resolved once, for every surface that shows money.
	 *
	 * The single answer to "what does the learner pay". Callers get a resolved
	 * shape and pass no columns, which is the whole point: `is_purchasable_free()`
	 * takes price and sale_price as arguments the CALLER derives, and that
	 * signature is what let two checkout builders drift apart - one read
	 * `recurring_amount` for a recurring course and one read the raw `price`
	 * column, so the same $19/month course was "not free" to the enroll route
	 * and "free" to the checkout JS on the same page render
	 * (Basecamp 10198954861).
	 *
	 * A recurring course keeps its price in `recurring_amount` and leaves
	 * `price` at 0. A $0 one-time course is legitimately free. A closed or
	 * invite-only course carries no on-site price BY CONSTRUCTION and must
	 * never read as "free to enrol" - that distinction is is_closed()'s, and it
	 * is honoured here so no caller has to remember it.
	 *
	 * @param object $course Course row.
	 * @return array{
	 *     amount:float, is_free:bool, is_recurring:bool, is_closed:bool,
	 *     interval:string, currency:?string, formatted:string
	 * }
	 */
	public static function price_view( object $course ): array {
		$recurring = self::is_recurring( $course );

		if ( $recurring ) {
			// The subscription amount IS the price. Sale pricing does not apply
			// to a recurring plan - there is no second column for it.
			$amount = (float) ( $course->recurring_amount ?? 0 );
		} else {
			$regular = (float) ( $course->price ?? 0 );
			$sale    = (float) ( $course->sale_price ?? 0 );
			// An active sale wins; is_on_sale() owns the window check so this
			// does not become a fourth place that decides what "on sale" means.
			$amount = ( $sale > 0 && self::is_on_sale( $course ) ) ? $sale : $regular;
		}

		$closed = self::is_closed( $course );

		// Free means "the learner pays nothing to enrol here". A closed course
		// costs nothing on-site and is still NOT free to enrol - access comes
		// from somewhere else entirely.
		$is_free = ! $closed && ( self::is_free( $course ) || $amount <= 0 );

		return array(
			'amount'       => $amount,
			'is_free'      => $is_free,
			'is_recurring' => $recurring,
			'is_closed'    => $closed,
			'interval'     => $recurring ? (string) ( $course->recurring_interval ?? 'month' ) : '',
			'currency'     => $course->currency ?? null,
			// Formatted through format_price() so the currency, its decimals
			// and its symbol position all come from one place (coding Rule 28).
			'formatted'    => $amount > 0 ? \Learnomy\format_price( $amount, $course->currency ?? null ) : '',
		);
	}

	/** True when the course is sold as a recurring per-course subscription (added in PR-A1). */
	public static function is_recurring( object $course ): bool {
		return 'recurring' === self::pricing_model( $course );
	}

	/** True when the course is gated behind a membership plan (no real-money purchase). */
	public static function is_members_only( object $course ): bool {
		return 'members_only' === self::pricing_model( $course );
	}

	/**
	 * Is the course on an ACTIVE sale right now? A sale counts only when there
	 * is a positive sale price below the regular price AND the current time
	 * falls inside the [sale_starts, sale_ends] window. This is the single
	 * source of truth for sale state — the single-course page, the catalog/
	 * dashboard cards, and any other surface all read it so they can't disagree
	 * (Basecamp #9981514248: the catalog card never showed the sale price).
	 */
	public static function is_on_sale( object $course ): bool {
		$price = (float) ( $course->price ?? 0 );
		$sale  = (float) ( $course->sale_price ?? 0 );
		if ( $sale <= 0 || $sale >= $price ) {
			return false;
		}
		// No window set → not an active sale (matches the single-course rule).
		if ( empty( $course->sale_starts ) || empty( $course->sale_ends ) ) {
			return false;
		}
		// Sale window is admin calendar-date semantics — a Y-m-d picked in the
		// site timezone, not an absolute instant — so compare against the
		// site-local clock: the sale must open/close on the site's day, not on
		// UTC's (which would shift it by the offset near midnight).
		$now = current_time( 'mysql' ); // tz-local-ok
		return $now >= $course->sale_starts && $now <= $course->sale_ends;
	}

	/**
	 * The price a buyer actually pays right now: the sale price when a sale is
	 * active, otherwise the regular price. Pairs with {@see self::is_on_sale()}.
	 */
	public static function effective_price( object $course ): float {
		return self::is_on_sale( $course )
			? (float) $course->sale_price
			: (float) ( $course->price ?? 0 );
	}

	/**
	 * True when enrolling costs nothing right now: a free-model course, or a
	 * one-time paid course whose effective (sale-aware) price is zero.
	 *
	 * The enroll guards and the enroll CTA must share this one answer. The
	 * CTA answered by price while the guards answered by access_type, so a
	 * paid-model course priced at zero advertised "Enroll Now" and then
	 * 402'd the click into a checkout error banner (2026-08-06). Recurring
	 * and membership models never qualify: their charge is the subscription,
	 * not the sticker price.
	 */
	public static function is_zero_charge( object $course ): bool {
		if ( self::is_free( $course ) ) {
			return true;
		}
		if ( 'paid' !== ( $course->access_type ?? 'free' ) || self::is_recurring( $course ) ) {
			return false;
		}
		return self::effective_price( $course ) <= 0;
	}

	/**
	 * True when the course can be accessed via an active membership plan.
	 * Covers `members_only` AND `one_time_or_members` (the dual-path
	 * legacy `paid_or_membership` shape).
	 */
	public static function has_member_path( object $course ): bool {
		return in_array( self::pricing_model( $course ), array( 'members_only', 'one_time_or_members' ), true );
	}

	/**
	 * True when the course requires the customer to pay before enrolling.
	 * Covers every model except `free` and `members_only` — that is,
	 * `one_time`, `fixed_term`, `recurring`, and `one_time_or_members`.
	 *
	 * Used by the router redirect and the publish-time gateway watcher to
	 * decide whether the customer needs to land on checkout.
	 */
	public static function requires_purchase( object $course ): bool {
		return in_array(
			self::pricing_model( $course ),
			array( 'one_time', 'fixed_term', 'recurring', 'one_time_or_members' ),
			true
		);
	}

	/**
	 * Compute the access-expiry timestamp for a fixed-term enrollment.
	 *
	 * Returns null for every pricing model except `fixed_term` (lifetime
	 * access is the universal default). Reads `access_duration_days` from
	 * the course row — that column lands in PR-A1; until then the method
	 * defensively returns null whenever the column is missing or zero.
	 *
	 * @param object                  $course Course row.
	 * @param \DateTimeInterface|null $from   Anchor (defaults to now UTC).
	 */
	public static function compute_access_expiry( object $course, ?\DateTimeInterface $from = null ): ?\DateTimeImmutable {
		if ( ! self::is_fixed_term( $course ) ) {
			return null;
		}

		$days = isset( $course->access_duration_days ) ? (int) $course->access_duration_days : 0;
		if ( $days <= 0 ) {
			return null;
		}

		$anchor = null !== $from
			? \DateTimeImmutable::createFromInterface( $from )
			: new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );

		return $anchor->modify( "+{$days} days" );
	}

	/** Recurring-course price-per-period (null for non-recurring courses). */
	public static function recurring_amount( object $course ): ?float {
		if ( ! self::is_recurring( $course ) ) {
			return null;
		}
		$amount = isset( $course->recurring_amount ) ? (float) $course->recurring_amount : 0.0;
		return $amount > 0 ? $amount : null;
	}

	/**
	 * Recurring-course billing interval — 'month' or 'year'. Null for
	 * non-recurring courses or when the column isn't populated yet.
	 */
	public static function recurring_interval( object $course ): ?string {
		if ( ! self::is_recurring( $course ) ) {
			return null;
		}
		$interval = isset( $course->recurring_interval ) ? strtolower( (string) $course->recurring_interval ) : '';
		if ( in_array( $interval, array( 'month', 'year' ), true ) ) {
			return $interval;
		}
		return null;
	}

	/**
	 * A short, human name for a course's pricing model.
	 *
	 * Exists so a surface that cannot EDIT a model can still NAME it. The Pro
	 * frontend builder offers two radios (Free / Paid) against eight models, and
	 * showed a bare "Paid" for a monthly subscription — the author had no way to
	 * know the course billed recurringly, and saving collapsed it (Basecamp
	 * 10183993741). Telling someone what they are looking at is the minimum a
	 * read-only surface owes them.
	 *
	 * Deliberately shorter than the wp-admin pricing-screen labels, which are
	 * written to be chosen between ("One-time payment (time-limited access)")
	 * rather than to sit in a badge.
	 *
	 * @param object $course Course row.
	 * @return string Translated label.
	 */
	public static function pricing_model_label( object $course ): string {
		switch ( self::pricing_model( $course ) ) {
			case 'one_time':
				return __( 'One-time payment', 'learnomy' );
			case 'fixed_term':
				return __( 'Time-limited access', 'learnomy' );
			case 'recurring':
				return __( 'Recurring subscription', 'learnomy' );
			case 'members_only':
				return __( 'Members only', 'learnomy' );
			case 'one_time_or_members':
				return __( 'Buy or use a membership', 'learnomy' );
			case 'invite_only':
				return __( 'Invite only', 'learnomy' );
			case 'closed':
				return __( 'Access granted externally', 'learnomy' );
			case 'free':
			default:
				return __( 'Free', 'learnomy' );
		}
	}

	/**
	 * The headline amount a buyer is being asked for, whichever column holds it.
	 *
	 * A recurring course stores its charge in `recurring_amount` and leaves the
	 * `price` column at 0 — the admin pricing screen only writes the recurring
	 * field for that model. Every display surface read `price` directly, so a
	 * $19/month course announced itself as "Free" with an "Enroll Now" button on
	 * both the catalog card and the single-course page, while checkout correctly
	 * charged $19 monthly (Basecamp 10184318257). The money was right and only
	 * the promise was wrong, which is the worst way round.
	 *
	 * Sale pricing deliberately does not pass through here: `sale_price`
	 * discounts a one-time purchase and has no meaning against a per-period
	 * charge, so callers keep resolving sales the way they already do.
	 *
	 * @param object $course Course row.
	 * @return float Amount to display, 0.0 when nothing is being charged.
	 */
	public static function headline_price( object $course ): float {
		$recurring = self::recurring_amount( $course );
		if ( null !== $recurring ) {
			return $recurring;
		}

		return isset( $course->price ) ? (float) $course->price : 0.0;
	}

	/**
	 * One sentence for the "This course includes" list answering how long access
	 * lasts — or '' when the honest answer is to say nothing.
	 *
	 * "Lifetime access" is a promise about what a PURCHASE buys. The list
	 * printed it for every course that was not invite-only, so it appeared on a
	 * 30-day fixed-term course (contradicting the duration the owner had just
	 * configured), on a monthly subscription that ends when billing stops, on a
	 * members-only course that ends when the membership does, and on a closed
	 * course that is not sold here at all (Basecamp 10184318317). The
	 * invite-only carve-out already in the template had the right instinct and
	 * the wrong scope: the question is not "is this invite-only", it is "does a
	 * purchase here buy permanent access".
	 *
	 * @param object $course Course row.
	 * @return string Translated line, or '' to omit the row entirely.
	 */
	public static function access_duration_note( object $course ): string {
		if ( self::is_invite_only( $course ) || self::is_closed( $course ) ) {
			// Nothing is bought here, and access lasts exactly as long as whoever
			// granted it leaves it in place. Any claim would be the owner's to
			// make, not ours.
			return '';
		}

		if ( self::is_fixed_term( $course ) ) {
			$days = isset( $course->access_duration_days ) ? (int) $course->access_duration_days : 0;
			if ( $days > 0 ) {
				return sprintf(
					/* translators: %d: number of days of access purchased. */
					_n( 'Access for %d day', 'Access for %d days', $days, 'learnomy' ),
					$days
				);
			}
			// Fixed-term with no duration set is a half-configured course; saying
			// "lifetime" would contradict the model the owner picked.
			return '';
		}

		if ( self::is_recurring( $course ) ) {
			return __( 'Access while your subscription is active', 'learnomy' );
		}

		if ( self::is_members_only( $course ) ) {
			return __( 'Access while your membership is active', 'learnomy' );
		}

		return __( 'Lifetime access', 'learnomy' );
	}



	/**
	 * Whether a course's pricing model is sticky — at least one paying
	 * customer holds a non-pending enrollment, so changing the model would
	 * leave existing customers in a half-state. The course form refuses to
	 * write a new pricing_model when this returns true; the admin must
	 * clone the course to re-launch with new pricing.
	 *
	 * Free enrollments are NOT considered locking — a course that was free
	 * and is being converted to paid can still flip its pricing model.
	 */
	public static function lock_pricing_model_if_purchased( int $course_id ): bool {
		$enrollments_table = \Learnomy\table( 'enrollments' );
		$count             = (int) static::db()->get_var(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$enrollments_table} WHERE course_id = %d AND source IN ('purchase','subscription') AND status NOT IN ('cancelled','expired','pending')",
				$course_id
			)
		);
		return $count > 0;
	}

	/**
	 * Resolve the column name on lrn_courses that caches a provider's
	 * price/plan ID for the given (gateway, mode) pair. Centralised here
	 * so adapters never type-check column names themselves.
	 *
	 * Stripe modes: 'test' | 'live'. PayPal modes: 'sandbox' | 'live'.
	 */
	private static function provider_price_column( string $gateway, string $mode ): string {
		$map = array(
			'stripe' => array(
				'test' => 'stripe_test_price_id',
				'live' => 'stripe_live_price_id',
			),
			'paypal' => array(
				'sandbox' => 'paypal_sandbox_plan_id',
				'live'    => 'paypal_live_plan_id',
			),
		);
		return $map[ $gateway ][ $mode ] ?? '';
	}

	/**
	 * Total publicly listable published courses. Pairs with
	 * {@see get_sitemap_page()}, which asks the same strictly-public question.
	 *
	 * @param int|null $viewer_id Visibility viewer; see public_visibility_scope().
	 */
	public static function count_published( ?int $viewer_id = null ): int {
		[ $where, $where_values ] = self::public_listing_where( $viewer_id );

		if ( empty( $where_values ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is a model-built fragment with no bound values.
			return (int) static::db()->get_var( 'SELECT COUNT(*) FROM ' . static::table() . " WHERE {$where}" );
		}

		return (int) static::db()->get_var(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is a model-built fragment; its values are bound below.
				'SELECT COUNT(*) FROM ' . static::table() . " WHERE {$where}",
				...$where_values
			)
		);
	}

	/**
	 * LIKE search across publicly listable published courses, with optional
	 * access_type filter. Backs the public `learnomy/search-courses` ability,
	 * which is `permission_callback => __return_true` — search is the catalog
	 * through a different door, so it carries the same visibility rule.
	 *
	 * @param string   $query       Search term.
	 * @param string   $access_type Optional access_type filter.
	 * @param int      $limit       Max rows.
	 * @param int      $offset      Pagination offset.
	 * @param int|null $viewer_id   Visibility viewer; see public_visibility_scope().
	 */
	public static function search_like( string $query, string $access_type = '', int $limit = 20, int $offset = 0, ?int $viewer_id = null ): array {
		$like                     = '%' . static::db()->esc_like( $query ) . '%';
		[ $where, $where_values ] = self::public_listing_where( $viewer_id );

		if ( $access_type ) {
			return static::db()->get_results(
				static::db()->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is a model-built fragment; its values are bound below.
					'SELECT * FROM ' . static::table() . " WHERE {$where} AND access_type = %s AND (title LIKE %s OR description LIKE %s) ORDER BY created_at DESC LIMIT %d OFFSET %d",
					...array_merge( $where_values, [ $access_type, $like, $like, $limit, $offset ] )
				)
			) ?: [];
		}

		return static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is a model-built fragment; its values are bound below.
				'SELECT * FROM ' . static::table() . " WHERE {$where} AND (title LIKE %s OR description LIKE %s) ORDER BY created_at DESC LIMIT %d OFFSET %d",
				...array_merge( $where_values, [ $like, $like, $limit, $offset ] )
			)
		) ?: [];
	}

	/** Counts by status — returns array of {status, n} objects. */
	public static function count_by_status(): array {
		return static::db()->get_results(
			'SELECT status, COUNT(*) AS n FROM ' . static::table() . ' GROUP BY status'
		) ?: [];
	}

	/**
	 * Admin course list, trash-aware. A specific status filter returns just
	 * that status; the "all" view excludes trashed courses (they live only in
	 * the Trash tab), matching the WordPress Trash convention.
	 *
	 * @param string $status_filter Status slug, or 'all'.
	 */
	public static function list_for_admin( string $status_filter, string $orderby = 'created_at', string $order = 'DESC', int $limit = 20, int $offset = 0, int $author_id = 0, int $category_id = 0, int $tag_id = 0, string $search = '' ): array {
		$direction = 'ASC' === strtoupper( $order ) ? 'ASC' : 'DESC';
		$orderby   = esc_sql( $orderby );

		[ $join, $where, $params ] = self::build_admin_filter_sql( $status_filter, $author_id, $category_id, $tag_id, $search );
		$params[]                  = $limit;
		$params[]                  = $offset;

		return static::db()->get_results(
			static::db()->prepare(
				'SELECT c.* FROM ' . static::table() . " c{$join} WHERE " . implode( ' AND ', $where )
					. ' ORDER BY c.`' . $orderby . '` ' . $direction . ' LIMIT %d OFFSET %d',
				...$params
			)
		) ?: array();
	}

	/**
	 * Count for the admin list, trash-aware (see list_for_admin()).
	 *
	 * @param string $status_filter Status slug, or 'all'.
	 * @param int    $author_id     When non-zero, restrict count to courses authored
	 *                              by this user — instructor view of the admin list.
	 * @param int    $category_id   When non-zero, restrict to one course category.
	 * @param int    $tag_id        When non-zero, restrict to courses carrying this tag.
	 * @param string $search        When non-empty, LIKE-match title or description.
	 */
	public static function count_for_admin( string $status_filter, int $author_id = 0, int $category_id = 0, int $tag_id = 0, string $search = '' ): int {
		[ $join, $where, $params ] = self::build_admin_filter_sql( $status_filter, $author_id, $category_id, $tag_id, $search );

		return (int) static::db()->get_var(
			static::db()->prepare(
				'SELECT COUNT(DISTINCT c.id) FROM ' . static::table() . " c{$join} WHERE " . implode( ' AND ', $where ),
				...$params
			)
		);
	}

	/**
	 * Compose the JOIN + WHERE + bound params shared by the admin list + count.
	 * Status is trash-aware (a specific status returns just that status; "all"
	 * excludes trashed). Category is the direct `category_id` column; a tag
	 * filter INNER JOINs lrn_tag_map on (object_id, object_type='course').
	 *
	 * @return array{0:string,1:string[],2:array<int,mixed>} [join_sql, where[], params[]]
	 */
	/**
	 * Courses that may be OFFERED AS A TARGET — the one source for every picker
	 * that attaches something to a course.
	 *
	 * Excludes trashed and nothing else. Draft is deliberately included: building
	 * a course as a draft and adding its lessons before publishing is the normal
	 * authoring flow, so a picker limited to published courses would break
	 * authoring far more widely than the bug it fixed (Basecamp 10153289435
	 * suggested exactly that; this site alone has 14 draft courses).
	 *
	 * This exists because every picker hand-rolled `Course::where( array() )`,
	 * which builds no WHERE clause at all and therefore returns trashed rows
	 * alongside live ones. `list_for_admin()` knew to exclude them; it is
	 * list-shaped (paged, filtered) and could not be reused as-is, so the
	 * knowledge stayed in one query and the pickers never got it.
	 *
	 * @param int $author_id When non-zero, restrict to this author's courses —
	 *                       an instructor must not attach to someone else's.
	 * @return object[]
	 */
	public static function selectable( int $author_id = 0, string $orderby = 'title', string $order = 'ASC', int $limit = 500 ): array {
		$direction = 'ASC' === strtoupper( $order ) ? 'ASC' : 'DESC';
		$orderby   = esc_sql( $orderby );

		$where  = array( 'status != %s' );
		$params = array( self::STATUS_TRASHED );

		if ( $author_id > 0 ) {
			$where[]  = 'author_id = %d';
			$params[] = $author_id;
		}

		$params[] = $limit;

		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE ' . implode( ' AND ', $where )
					. ' ORDER BY `' . $orderby . '` ' . $direction . ' LIMIT %d',
				...$params
			)
		) ?: array();
	}

	/**
	 * COUNT(*) matching {@see self::selectable()}, for pagination and overflow.
	 *
	 * Kept beside it deliberately: a list that excludes trashed paired with a
	 * total that counts it reports courses the caller can never reach.
	 */
	public static function count_selectable( int $author_id = 0 ): int {
		$where  = array( 'status != %s' );
		$params = array( self::STATUS_TRASHED );

		if ( $author_id > 0 ) {
			$where[]  = 'author_id = %d';
			$params[] = $author_id;
		}

		return (int) static::db()->get_var(
			static::db()->prepare(
				'SELECT COUNT(*) FROM ' . static::table() . ' WHERE ' . implode( ' AND ', $where ),
				...$params
			)
		);
	}

	private static function build_admin_filter_sql( string $status_filter, int $author_id, int $category_id, int $tag_id, string $search = '' ): array {
		$join   = '';
		$where  = array();
		$params = array();

		// JOIN params bind before WHERE params (the JOIN clause comes first).
		if ( $tag_id > 0 ) {
			$map      = static::db()->prefix . 'lrn_tag_map';
			$join     = " INNER JOIN {$map} tm ON tm.object_id = c.id AND tm.object_type = 'course' AND tm.tag_id = %d";
			$params[] = $tag_id;
		}

		if ( 'all' !== $status_filter && '' !== $status_filter ) {
			$where[]  = 'c.status = %s';
			$params[] = $status_filter;
		} else {
			$where[]  = 'c.status != %s';
			$params[] = self::STATUS_TRASHED;
		}
		if ( $author_id > 0 ) {
			$where[]  = 'c.author_id = %d';
			$params[] = $author_id;
		}
		if ( $category_id > 0 ) {
			$where[]  = 'c.category_id = %d';
			$params[] = $category_id;
		}
		if ( '' !== $search ) {
			$like     = '%' . static::db()->esc_like( $search ) . '%';
			$where[]  = '(c.title LIKE %s OR c.description LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		return array( $join, $where, $params );
	}

	/**
	 * Batch-recompute denormalized student_count from enrollments.
	 * Runs as a single UPDATE ... subquery for efficiency.
	 *
	 * Canonical definition of an enrolled student: a DISTINCT user with a row in
	 * an active state, i.e. `COUNT(DISTINCT user_id) WHERE status IN
	 * ('active','completed')`. DISTINCT because the unique key is
	 * (user_id, course_id, source, path_id): a student holding a course through
	 * two sources (e.g. direct + Space/membership) is two rows but ONE student, so
	 * a plain COUNT(*) over-counts them (Basecamp 10109120366). A completed
	 * enrollment is still a student (completion never decrements). This predicate
	 * is the single source of truth, mirrored by the distinct-aware increment /
	 * decrement transitions in Enrollment_Service and the repair CLI. Matches
	 * Course::sum_students_for_instructor(), which fixed the same class earlier.
	 */
	public static function recompute_student_counts(): void {
		$enrollments_table = \Learnomy\table( 'enrollments' );
		static::db()->query(
			'UPDATE ' . static::table() . " c
			SET c.student_count = (
				SELECT COUNT(DISTINCT e.user_id) FROM {$enrollments_table} e
				WHERE e.course_id = c.id AND e.status IN ( 'active', 'completed' )
			)"
		);
		// Raw write bypasses base insert/update/delete — bump the cache group.
		static::invalidate_cache();
	}

	/**
	 * Recompute student_count for a SPECIFIC set of courses from the distinct
	 * source of truth. Used by the bulk membership-expire paths: rather than
	 * hand-computing a distinct-aware decrement across many users at once (which a
	 * grouped COUNT(*) cannot express), they flip the rows then call this to set
	 * each touched course to its true COUNT(DISTINCT user_id). Same predicate as
	 * recompute_student_counts(), scoped by id.
	 *
	 * @param int[] $course_ids
	 */
	public static function recompute_student_count_for_courses( array $course_ids ): void {
		$course_ids = array_values( array_unique( array_filter( array_map( 'intval', $course_ids ) ) ) );
		if ( empty( $course_ids ) ) {
			return;
		}
		$enrollments_table = \Learnomy\table( 'enrollments' );
		$placeholders      = implode( ',', array_fill( 0, count( $course_ids ), '%d' ) );
		static::db()->query(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table + placeholders internal; ids bound.
				'UPDATE ' . static::table() . " c
				SET c.student_count = (
					SELECT COUNT(DISTINCT e.user_id) FROM {$enrollments_table} e
					WHERE e.course_id = c.id AND e.status IN ( 'active', 'completed' )
				)
				WHERE c.id IN ({$placeholders})",
				...$course_ids
			)
		);
		static::invalidate_cache();
	}

	/**
	 * Batch-recompute denormalized avg_rating and review_count from reviews.
	 */
	public static function recompute_ratings(): void {
		$reviews_table = \Learnomy\table( 'reviews' );
		static::db()->query(
			'UPDATE ' . static::table() . " c
			SET
				c.avg_rating   = COALESCE(( SELECT AVG(r.rating) FROM {$reviews_table} r WHERE r.course_id = c.id AND r.status = 'approved' ), 0),
				c.review_count = ( SELECT COUNT(*) FROM {$reviews_table} r WHERE r.course_id = c.id AND r.status = 'approved' )"
		);
		// Raw write bypasses base insert/update/delete — bump the cache group.
		static::invalidate_cache();
	}

	/** FULLTEXT search on published courses. Returns rows with a relevance score. */
	public static function fulltext_search( string $query, int $limit = 20 ): array {
		// FT index ft_title_description is on (title, description_plain) — the
		// tag-stripped projection (G16), so search matches clean text, not
		// block-JSON / HTML noise. `description` is still SELECTed for the excerpt.
		return static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				'SELECT id, title, description, slug,
				        MATCH(title, description_plain) AGAINST(%s IN NATURAL LANGUAGE MODE) AS relevance
				 FROM ' . static::table() . "
				 WHERE status = 'published'
				   AND MATCH(title, description_plain) AGAINST(%s IN NATURAL LANGUAGE MODE)
				 ORDER BY relevance DESC
				 LIMIT %d",
				$query,
				$query,
				$limit
			)
		) ?: [];
	}

	/** Count of published courses matching a FULLTEXT query. */
	public static function fulltext_count( string $query ): int {
		return (int) static::db()->get_var(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				'SELECT COUNT(*) FROM ' . static::table() . "
				 WHERE status = 'published'
				   AND MATCH(title, description_plain) AGAINST(%s IN NATURAL LANGUAGE MODE)",
				$query
			)
		);
	}

	/**
	 * Refreshes denormalized rating for a single course from the reviews table.
	 * Used by the demo seeder where reviews are inserted outside the service layer.
	 */
	public static function refresh_rating( int $course_id ): void {
		$reviews_table = \Learnomy\table( 'reviews' );
		$agg           = static::db()->get_row(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) AS cnt, AVG(rating) AS avg FROM {$reviews_table} WHERE course_id = %d AND status = 'approved'",
				$course_id
			)
		);

		if ( $agg ) {
			static::update(
				$course_id,
				[
					'review_count' => (int) $agg->cnt,
					'avg_rating'   => round( (float) $agg->avg, 2 ),
					'updated_at'   => now(),
				]
			);
		}
	}

	/**
	 * How many courses link to this media item?
	 *
	 * The course-side companion to {@see \Learnomy\Models\Lesson::media_reference_count()},
	 * asked before a file is relocated into the protected store. Covers the four
	 * places a course can point at media: the thumbnail (by id and by URL), the
	 * promo video, and an embed inside the description or the long content.
	 * Matched on the URL stem so generated image sizes count as references.
	 *
	 * @param int    $media_id Attachment post id.
	 * @param string $url      The media item's current public URL.
	 */
	/**
	 * Author ids on courses whose WordPress user no longer exists.
	 *
	 * Lives here because data access lives in models — the cleanup service was
	 * running this join on a raw `$wpdb` and failing architecture invariant F1.
	 * The service owns the DECISION (hand the course back to an admin rather
	 * than delete it, since an orphaned author can mean a real course whose
	 * instructor was removed); the query belongs to the table.
	 *
	 * @return int[] Distinct author ids with no matching user.
	 */
	public static function orphaned_author_ids(): array {
		$db = static::db();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- integrity sweep, runs from a cleanup command; caching a "what is broken right now" answer would be wrong.
		$ids = $db->get_col(
			'SELECT DISTINCT c.author_id
			   FROM ' . static::table() . " c
			   LEFT JOIN {$db->users} u ON u.ID = c.author_id
			  WHERE c.author_id > 0 AND u.ID IS NULL"
		);

		return array_map( 'intval', (array) $ids );
	}

	public static function media_reference_count( int $media_id, string $url ): int {
		$stem = (string) preg_replace( '/\.[a-zA-Z0-9]{1,8}$/', '', $url );

		if ( $media_id > 0 && '' === $stem ) {
			return (int) static::db()->get_var(
				static::db()->prepare(
					'SELECT COUNT(*) FROM ' . static::table() . ' WHERE thumbnail_id = %d',
					$media_id
				)
			);
		}

		if ( '' === $stem ) {
			return 0;
		}

		$like = '%' . static::db()->esc_like( $stem ) . '%';

		return (int) static::db()->get_var(
			static::db()->prepare(
				'SELECT COUNT(*) FROM ' . static::table() . '
				 WHERE thumbnail_id = %d
				    OR thumbnail_url LIKE %s
				    OR video_url LIKE %s
				    OR description LIKE %s
				    OR content LIKE %s',
				$media_id,
				$like,
				$like,
				$like,
				$like
			)
		);
	}

	/**
	 * The ONE primary action a given viewer should be offered on a course.
	 *
	 * WHY THIS EXISTS
	 *
	 * The sidebar enroll card, the mobile sticky bar, the catalog card and the
	 * checkout refusal each used to assemble this answer themselves from the
	 * same low-level predicates. They agreed - verified across every pricing
	 * model - but only because somebody kept four branch trees in step by hand,
	 * and the files said so out loud: "The two partials must branch identically
	 * or the phone and the desktop card disagree."
	 *
	 * That is a comment doing a compiler's job, and the bugs it was written
	 * after are on the record: a catalog card reading "Free" + "Enroll" on a
	 * $19/month course, a free course offering "Buy Now", a guest on a
	 * members-only course invited to "Sign up to enroll" underneath a price
	 * label reading "Members only". Each was fixed in one file at a time,
	 * because there was no one place to fix.
	 *
	 * This is that place. It decides; the surfaces render.
	 *
	 * NO QUERIES. Everything it needs that is not on the course row arrives in
	 * `$context`, already resolved by the view-data layer that the templates
	 * were reading anyway. A resolver that re-derived access or progress would
	 * both cost a query per card on a catalog page and be free to disagree with
	 * the page around it - which is the defect, not the fix.
	 *
	 * @since 1.9.3
	 *
	 * @param object $course  Course row.
	 * @param int    $user_id Viewer, 0 for a guest.
	 * @param array  $context {
	 *     Already-resolved page state. Every key optional.
	 *
	 *     @type bool        $has_access         Viewer may open the lessons.
	 *     @type bool        $member_can_claim   Plan covers it but no enrollment row exists yet.
	 *     @type bool        $is_owner           Viewer authors/administers this course.
	 *     @type bool        $is_course_complete Viewer finished it.
	 *     @type int         $progress_pct       0-100.
	 *     @type string      $continue_url       Resume link.
	 *     @type string      $enroll_url         Enrol/checkout link.
	 *     @type string      $course_url         Canonical course URL, for redirect_to.
	 * }
	 * @return array{kind:string,label:string,url:string,enabled:bool} kind is one of:
	 *         owner, gated, complete, start, continue, claim, external, closed,
	 *         signup_join, signup_buy, signup_enroll, members_join, buy, enroll.
	 */
	public static function cta_for_viewer( object $course, int $user_id, array $context = array() ): array {
		$ctx = wp_parse_args(
			$context,
			array(
				'has_access'         => false,
				'member_can_claim'   => false,
				'is_owner'           => false,
				'is_course_complete' => false,
				'progress_pct'       => 0,
				'continue_url'       => '',
				'enroll_url'         => '',
				'course_url'         => '',
			)
		);

		$none = static function ( string $kind ): array {
			return array(
				'kind'    => $kind,
				'label'   => '',
				'url'     => '',
				'enabled' => false,
			);
		};

		// An owner manages, never buys.
		if ( ! empty( $ctx['is_owner'] ) ) {
			return $none( 'owner' );
		}

		// `member_can_claim` beats `has_access`: a plan-covered member holds
		// access from the subscription while owning no enrollment row, so
		// "Start course" would strand them - lessons but no enrollment, and the
		// course never reaches My Courses.
		if ( ! empty( $ctx['has_access'] ) && empty( $ctx['member_can_claim'] ) ) {
			if ( ! empty( $ctx['is_course_complete'] ) ) {
				return array(
					'kind'    => 'complete',
					// Composed, not hardcoded: a white-labelled site may call a
					// course a Class or a Program, and the destination page is
					// renamed with it. Renders "Go to My Courses" unchanged on a
					// default install.
					'label'   => sprintf(
						/* translators: %s: plural course label, e.g. "Courses" */
						__( 'Go to My %s', 'learnomy' ),
						\Learnomy\course_label( true )
					),
					'url'     => (string) $ctx['continue_url'],
					'enabled' => true,
				);
			}

			$started = (int) $ctx['progress_pct'] > 0;

			return array(
				'kind'    => $started ? 'continue' : 'start',
				'label'   => $started ? __( 'Continue learning', 'learnomy' ) : __( 'Start course', 'learnomy' ),
				'url'     => (string) $ctx['continue_url'],
				'enabled' => true,
			);
		}

		if ( self::is_closed( $course ) ) {
			// Invite-only never gets the external button, even when a URL is
			// present: switching a course FROM closed TO invite-only leaves the
			// old value in settings, and honouring it would advertise "Sold
			// externally" on a course that is sold nowhere and enrolled here.
			$external = self::is_invite_only( $course )
				? ''
				: (string) self::get_setting( (int) $course->id, 'closed_cta_url', '' );

			if ( '' === $external ) {
				return $none( 'closed' );
			}

			$label = (string) self::get_setting( (int) $course->id, 'closed_cta_label', '' );

			return array(
				'kind'    => 'external',
				'label'   => '' !== $label ? $label : __( 'Enroll externally', 'learnomy' ),
				'url'     => $external,
				'enabled' => true,
			);
		}

		// An extension may own the CTA entirely - a cohort-delivered course
		// answers "join a cohort", not "buy". Asked here, below access and
		// closed, because those two outrank it: an already-enrolled student told
		// to "join a cohort to enrol" is being sent to buy what they own, and a
		// closed course has no on-site enrolment for a gate to redirect - its
		// CTA belongs to `learnomy_closed_cta`. The sticky bar asked FIRST and
		// the enrol card asked LAST, so on any site running a gate extension the
		// two halves of one page gave different answers; this is that order
		// settled in one place rather than twice.
		if ( '' !== (string) apply_filters( 'learnomy_course_enroll_gate', '', $course, $user_id ) ) {
			return $none( 'gated' );
		}

		$members_only = self::is_members_only( $course );

		// Model-driven, never the `price` column alone. A recurring course keeps
		// its charge in `recurring_amount` with `price` at 0, so a column read
		// answered "not paid" and offered "Enroll Now" into a checkout that then
		// charges monthly (Basecamp 10184318257); a course switched to free with
		// a stale price column has the opposite failure and read as paid (smoke
		// C6). requires_purchase() is the predicate the router redirect and the
		// publish-time gateway watcher already use, so the button and the
		// destination cannot disagree, and is_zero_charge() keeps a genuinely $0
		// one-time course on "Enroll Now".
		$is_paid = self::requires_purchase( $course ) && ! self::is_zero_charge( $course );

		if ( ! $user_id ) {
			$course_url = '' !== (string) $ctx['course_url']
				? (string) $ctx['course_url']
				: \Learnomy\route_url( 'course', array( 'slug' => (string) $course->slug ) );

			$url = add_query_arg(
				array( 'redirect_to' => rawurlencode( $course_url ) ),
				\Learnomy\route_url( 'register-student' )
			);

			// The members-only verb must survive being logged out. A guest used
			// to fall through to the generic free-funnel wording printed under a
			// "Members only" price.
			if ( $members_only ) {
				return array(
					'kind'    => 'signup_join',
					'label'   => __( 'Sign up to join', 'learnomy' ),
					'url'     => $url,
					'enabled' => true,
				);
			}

			return array(
				'kind'    => $is_paid ? 'signup_buy' : 'signup_enroll',
				'label'   => $is_paid ? __( 'Sign up to buy', 'learnomy' ) : __( 'Sign up to enroll', 'learnomy' ),
				'url'     => $url,
				'enabled' => true,
			);
		}

		if ( ! empty( $ctx['member_can_claim'] ) ) {
			return array(
				'kind'    => 'claim',
				'label'   => __( 'Activate via membership', 'learnomy' ),
				'url'     => (string) $ctx['enroll_url'],
				'enabled' => true,
			);
		}

		if ( $members_only ) {
			return array(
				'kind'    => 'members_join',
				'label'   => __( 'Join to enroll', 'learnomy' ),
				'url'     => \Learnomy\route_url( 'membership-pricing' ),
				'enabled' => true,
			);
		}

		return array(
			'kind'    => $is_paid ? 'buy' : 'enroll',
			'label'   => $is_paid ? __( 'Buy Now', 'learnomy' ) : __( 'Enroll Now', 'learnomy' ),
			'url'     => (string) $ctx['enroll_url'],
			'enabled' => true,
		);
	}
}
