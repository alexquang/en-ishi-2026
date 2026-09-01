<?php
/**
 * Wishlist Service — the one owner of the `learnomy_wishlist` user meta.
 *
 * A wishlist is "courses I mean to take later". The moment a course becomes
 * "a course I am taking", the entry is not just redundant, it is wrong: the
 * dashboard showed the same course under Wishlist and under My Courses, the
 * saved-count kept counting it, and the catalog card offered "Add to wishlist"
 * on something the student had already paid for.
 *
 * Both halves of that are enforced here rather than at the REST controller,
 * because enrollment arrives from a dozen directions — checkout, a Stripe or
 * PayPal webhook, a WooCommerce order, a membership plan, a space or group
 * seat, an admin grant, a bulk CSV import, WP-CLI, LTI, a cohort, a learning
 * path, a WP Fusion tag. Every one of those funnels through
 * `Enrollment_Service::enroll()`, which fires `learnomy_student_enrolled`, so
 * listening to the lifecycle event covers all of them at once. Reacting in the
 * checkout controller instead would have shipped the same bug for the other
 * eleven paths.
 *
 * @package Learnomy
 */

namespace Learnomy\Services;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Course;
use Learnomy\Models\Enrollment;

class Wishlist_Service {

	/**
	 * User-meta key holding the JSON-encoded array of course ids.
	 *
	 * Kept as a constant so the reader, the writers and any future exporter
	 * name it once. Historically three call sites hardcoded the string and one
	 * of them wrote a raw PHP array where the others wrote JSON.
	 */
	public const META_KEY = 'learnomy_wishlist';

	/**
	 * Subscribe to the enrollment lifecycle. Called once from the plugin
	 * bootstrap.
	 */
	public static function register_hooks(): void {
		// The single choke point every enrollment path passes through.
		add_action( 'learnomy_student_enrolled', [ self::class, 'on_access_granted' ], 10, 3 );
		// Re-activating a cancelled/expired enrollment grants access too, and
		// fires its own event rather than the enrolled one.
		add_action( 'learnomy_student_access_restored', [ self::class, 'on_access_granted' ], 10, 3 );
	}

	/**
	 * Course ids on a user's wishlist.
	 *
	 * Tolerates a raw/serialised PHP array as well as the canonical JSON
	 * string: older rows were written both ways, and feeding an array to
	 * `json_decode()` is a PHP 8 TypeError that used to fatal `/account`.
	 *
	 * @return array<int,int>
	 */
	public static function ids( int $user_id = 0 ): array {
		$user_id = $user_id ?: get_current_user_id();
		if ( ! $user_id ) {
			return [];
		}

		$raw  = get_user_meta( $user_id, self::META_KEY, true );
		$list = is_array( $raw ) ? $raw : json_decode( (string) ( $raw ?: '[]' ), true );

		return is_array( $list ) ? array_values( array_unique( array_map( 'intval', $list ) ) ) : [];
	}

	/**
	 * The saved courses a viewer can actually be shown.
	 *
	 * `ids()` returns what the learner saved; this returns what still exists and
	 * is published. The two disagree the moment a saved course is unpublished or
	 * deleted, and every caller was left to notice that on its own — which is how
	 * the account page ended up counting saved ids while rendering published
	 * courses, showing a Wishlist badge of 2 above a panel holding 1, with no way
	 * for the learner to clear the phantom.
	 *
	 * Stale ids are deliberately NOT pruned from storage. A site owner routinely
	 * unpublishes a course to edit it, and pruning would quietly destroy every
	 * learner's save the moment they did — the entry comes back on its own when
	 * the course is published again. Hidden, not deleted.
	 *
	 * One query via Course::find_published_in(), not a find() per id, so a long
	 * wishlist does not become a page full of queries.
	 *
	 * @return object[] Published course rows, title ASC.
	 */
	public static function visible_courses( int $user_id = 0 ): array {
		$ids = self::ids( $user_id );

		return $ids ? \Learnomy\Models\Course::find_published_in( $ids ) : [];
	}

	public static function contains( int $user_id, int $course_id ): bool {
		return in_array( $course_id, self::ids( $user_id ), true );
	}

	/**
	 * Save a course for later.
	 *
	 * Refuses a course the student is already enrolled in — "save for later" is
	 * meaningless once you own it, and allowing it is what produced the
	 * duplicate dashboard row. The guard lives here, not in the controller, so
	 * the mobile app, an ability and any integration hit the same rule.
	 *
	 * @throws \RuntimeException When the course does not exist or is already owned.
	 */
	public static function add( int $user_id, int $course_id ): array {
		if ( ! Course::find( $course_id ) ) {
			throw new \RuntimeException( esc_html__( 'That course could not be found.', 'learnomy' ) );
		}

		// find_for_user_course(), not find_active(): a COMPLETED enrollment is
		// still ownership, and re-wishlisting a course you finished is the same
		// duplicate-row bug wearing a different status.
		if ( Enrollment::find_for_user_course( $user_id, $course_id ) ) {
			throw new \RuntimeException( esc_html__( 'You are already enrolled in this course. It is in My Courses.', 'learnomy' ) );
		}

		$wishlist = self::ids( $user_id );
		if ( ! in_array( $course_id, $wishlist, true ) ) {
			$wishlist[] = $course_id;
			self::persist( $user_id, $wishlist );
		}

		return array_values( $wishlist );
	}

	/**
	 * Drop a course from the wishlist. Idempotent — removing something that is
	 * not there is a no-op, which is what both the UI toggle and the
	 * enrollment listener want.
	 *
	 * @return array<int,int> The wishlist after the removal.
	 */
	public static function remove( int $user_id, int $course_id ): array {
		$wishlist = self::ids( $user_id );
		$filtered = array_values( array_filter( $wishlist, static fn( $id ) => $id !== $course_id ) );

		if ( count( $filtered ) !== count( $wishlist ) ) {
			self::persist( $user_id, $filtered );
		}

		return $filtered;
	}

	/**
	 * Lifecycle listener — a student just gained access to a course, so the
	 * wishlist entry has served its purpose.
	 *
	 * Signature matches both `learnomy_student_enrolled` ( $enrollment_id,
	 * $user_id, $course_id, $source ) and `learnomy_student_access_restored`
	 * ( $enrollment_id, $user_id, $course_id ) — the fourth argument is simply
	 * not requested.
	 *
	 * A path-level learning-path enrollment carries `course_id = 0`; the guard
	 * below skips it, and the path's individual course steps enroll through the
	 * same hook, so those still clear correctly.
	 */
	public static function on_access_granted( int $enrollment_id, int $user_id, int $course_id ): void {
		if ( $user_id <= 0 || $course_id <= 0 ) {
			return;
		}

		self::remove( $user_id, $course_id );
	}

	/**
	 * @param array<int,int> $wishlist
	 */
	private static function persist( int $user_id, array $wishlist ): void {
		update_user_meta( $user_id, self::META_KEY, wp_json_encode( array_values( $wishlist ) ) );
	}
}
