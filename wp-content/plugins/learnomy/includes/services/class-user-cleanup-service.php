<?php
/**
 * User Cleanup Service — removes all LMS data for a deleted WP user.
 *
 * Wired to the `deleted_user` action so the cleanup runs after WordPress
 * has confirmed the deletion. All writes go through Model methods — no raw
 * $wpdb outside includes/db/ per architecture rule F-1.
 *
 * @package Learnomy
 */

namespace Learnomy\Services;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Certificate;
use Learnomy\Models\Commission;
use Learnomy\Models\Coupon;
use Learnomy\Models\CouponUsage;
use Learnomy\Models\Course;
use Learnomy\Models\CourseInstructor;
use Learnomy\Models\Enrollment;
use Learnomy\Models\Progress;
use Learnomy\Models\QuestionResponse;
use Learnomy\Models\QuizAttempt;
use Learnomy\Models\Review;
use Learnomy\Models\Token;

class User_Cleanup_Service {

	/**
	 * Registers the `deleted_user` hook. Called once during plugin init.
	 * Priority 20 ensures any Pro extension listeners on `delete_user`
	 * (which fires BEFORE deletion) have already captured what they need.
	 */
	public function register_hooks(): void {
		add_action( 'deleted_user', array( $this, 'purge_user_data' ), 20, 1 );
		// Instructor courses are REASSIGNED, not purged — hand them to the
		// acting admin so they never orphan to "Unknown" (COURSE-01).
		add_action( 'deleted_user', array( $this, 'reassign_instructor_courses' ), 20, 3 );
		add_action( 'admin_notices', array( $this, 'render_reassign_notice' ) );
	}

	/**
	 * When an instructor's WP account is deleted, reassign their courses to a
	 * real owner instead of leaving them orphaned (COURSE-01).
	 *
	 * Target, in order: (1) the user WordPress reassigned content to on the
	 * delete-user screen, if any; (2) the admin performing the deletion;
	 * (3) the first site administrator (covers WP-CLI / programmatic deletes
	 * with no current user). Admins already have full course access, so no
	 * role grant is needed. A one-shot admin notice tells the owner it happened.
	 *
	 * @param int           $user_id  Deleted user id.
	 * @param int|null      $reassign User id WP reassigned content to (may be null).
	 * @param \WP_User|null $user    The user object as it was before deletion.
	 */
	/**
	 * Repair courses pointing at a user row that no longer exists.
	 *
	 * `reassign_instructor_courses()` above keeps this from happening when a user
	 * is deleted through WordPress — verified: deleting an instructor moves their
	 * courses to the admin rather than orphaning them. Rows still turn up though,
	 * from deletions that never fired `deleted_user`: direct SQL, a user removed
	 * while the plugin was inactive, or a cleanup that died part-way (the demo
	 * reset did exactly that until 1.9.1). 14 such courses were found on this
	 * sandbox.
	 *
	 * REASSIGNS rather than deletes, deliberately. A course whose author row has
	 * gone is broken data, but it is not necessarily DEMO data — it can be a real
	 * course with real enrollments whose instructor was removed. Deleting on that
	 * signal would destroy paid content; handing it to the admin loses nothing and
	 * makes it manageable again. Same destination and the same two writes as the
	 * deletion path, so a repaired course is indistinguishable from one that went
	 * through the hook.
	 *
	 * @return int Courses reassigned.
	 */
	public function repair_orphaned_authors(): int {
		// Through the model, not a raw $wpdb: data access lives in models
		// (architecture invariant F1). The service keeps the decision, the
		// table keeps the query.
		$orphan_ids = Course::orphaned_author_ids();

		if ( ! $orphan_ids ) {
			return 0;
		}

		$admins = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'fields'  => 'ID',
			)
		);
		$target = ! empty( $admins ) ? (int) $admins[0] : 0;

		if ( $target <= 0 ) {
			return 0;
		}

		$repaired = 0;
		foreach ( $orphan_ids as $orphan_id ) {
			$orphan_id = (int) $orphan_id;
			CourseInstructor::reassign_user( $orphan_id, $target );
			$repaired += (int) Course::reassign_author( $orphan_id, $target );
		}

		return $repaired;
	}

	public function reassign_instructor_courses( int $user_id, $reassign = null, $user = null ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		$target = $reassign ? (int) $reassign : get_current_user_id();
		if ( $target <= 0 || ! user_can( $target, 'manage_options' ) ) {
			$admins = get_users(
				array(
					'role'    => 'administrator',
					'number'  => 1,
					'orderby' => 'ID',
					'order'   => 'ASC',
					'fields'  => 'ID',
				)
			);
			$target = ! empty( $admins ) ? (int) $admins[0] : 0;
		}

		if ( $target <= 0 || $target === $user_id ) {
			return;
		}

		// Move both the junction rows (share/primary) AND the denormalized
		// `lrn_courses.author_id` pointer the card + admin list read — or the
		// course would still show the deleted user's name.
		$count = CourseInstructor::reassign_user( $user_id, $target );
		Course::reassign_author( $user_id, $target );
		if ( $count <= 0 ) {
			return;
		}

		$from_name = ( $user instanceof \WP_User && '' !== $user->display_name )
			? $user->display_name
			: sprintf( '#%d', $user_id );

		// Stash a one-shot notice for the admin who triggered the deletion.
		$acting = get_current_user_id();
		if ( $acting > 0 ) {
			set_transient(
				'learnomy_instructor_reassigned_' . $acting,
				array(
					'count'     => $count,
					'from_name' => $from_name,
					'target'    => $target,
				),
				MINUTE_IN_SECONDS
			);
		}

		/**
		 * Fires after a deleted instructor's courses are reassigned.
		 *
		 * @param int $user_id Deleted instructor id.
		 * @param int $target  User the courses were reassigned to.
		 * @param int $count   Number of courses reassigned.
		 */
		do_action( 'learnomy_instructor_courses_reassigned', $user_id, $target, $count );
	}

	/**
	 * Show the one-shot "courses reassigned" notice to the acting admin.
	 */
	public function render_reassign_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$acting = get_current_user_id();
		$key    = 'learnomy_instructor_reassigned_' . $acting;
		$data   = get_transient( $key );
		if ( ! is_array( $data ) || empty( $data['count'] ) ) {
			return;
		}
		delete_transient( $key );

		$target_user = get_userdata( (int) ( $data['target'] ?? 0 ) );
		$target_name = $target_user ? $target_user->display_name : __( 'the site admin', 'learnomy' );
		$count       = (int) $data['count'];

		$message = sprintf(
			/* translators: 1: number of courses, 2: deleted instructor name, 3: reassigned-to admin name */
			_n(
				'%1$d course owned by %2$s was reassigned to %3$s.',
				'%1$d courses owned by %2$s were reassigned to %3$s.',
				$count,
				'learnomy'
			),
			$count,
			esc_html( (string) ( $data['from_name'] ?? '' ) ),
			esc_html( $target_name )
		);
		?>
		<div class="notice notice-info is-dismissible">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
		<?php
	}

	/**
	 * Delete every LMS row that references the deleted user.
	 *
	 * Deletion order respects the FK chain:
	 *   1. question_responses (FK → quiz_attempts)
	 *   2. quiz_attempts (FK → user_id)
	 *   3. certificates (UUID rows — also clears lrn_tokens by user_id)
	 *   4. tokens (JWT / signed-URL rows)
	 *   5. progress
	 *   6. enrollments
	 *   7. reviews
	 *   8. coupon_usages
	 *
	 * Returns a summary array — useful for CLI and test assertions.
	 *
	 * @param int $user_id The WP user ID that was just deleted.
	 * @return array{attempts: int, responses: int, certs: int, tokens: int, progress: int, enrollments: int, reviews: int, coupon_usages: int}
	 */
	public function purge_user_data( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return $this->empty_summary();
		}

		// Collect attempt IDs before deleting so we can cascade to responses.
		$attempts    = QuizAttempt::get_for_user( $user_id );
		$attempt_ids = array_map( static fn( $a ) => (int) $a->id, $attempts );

		$response_count = 0;
		if ( ! empty( $attempt_ids ) ) {
			QuestionResponse::delete_for_attempts( $attempt_ids );
			// QuestionResponse::delete_for_attempts has no return value — derive
			// the count from the attempt list length as a proxy.
			$response_count = count( $attempt_ids );
		}

		$attempt_count = count( $attempt_ids );
		// Hard-delete attempt rows (not anonymize — user is gone).
		foreach ( $attempt_ids as $attempt_id ) {
			QuizAttempt::delete( $attempt_id );
		}

		// Certificates: delete the cert rows entirely. Hard-delete is correct
		// here because the cert's verify URL should 404 once the user is gone —
		// a revoked row would still surface in verification responses.
		$cert_rows  = Certificate::where( array( 'user_id' => $user_id ) );
		$cert_count = count( $cert_rows );
		foreach ( $cert_rows as $cert ) {
			Certificate::delete( (int) $cert->id );
		}

		// JWT / signed-URL tokens issued to this user.
		$token_rows  = Token::where( array( 'user_id' => $user_id ) );
		$token_count = count( $token_rows );
		foreach ( $token_rows as $token ) {
			Token::delete( (int) $token->id );
		}

		// Progress rows (item_type = 'lesson' | 'section').
		$progress_count = Progress::delete_for_user( $user_id );

		// Enrollments (also clears the user's seat from lrn_enrollments).
		$enrollment_count = Enrollment::delete_for_user( $user_id );

		// Reviews — hard-delete because the reviewer is gone; keeping an
		// anonymized row would distort course ratings without context.
		$review_rows  = Review::where( array( 'user_id' => $user_id ) );
		$review_count = count( $review_rows );
		foreach ( $review_rows as $review ) {
			Review::delete( (int) $review->id );
		}

		// Coupon usage log — referential integrity; coupon stats should not
		// reflect deleted users.
		$usage_rows      = CouponUsage::where( array( 'user_id' => $user_id ) );
		$usage_count     = count( $usage_rows );
		$touched_coupons = array();
		foreach ( $usage_rows as $usage ) {
			$touched_coupons[ (int) ( $usage->coupon_id ?? 0 ) ] = true;
			CouponUsage::delete( (int) $usage->id );
		}
		// Deleting usage rows without reconciling times_used would leave the
		// increment-only counter above the real redemption count and could
		// prematurely lock the coupon (denormalized-column audit).
		foreach ( array_keys( array_filter( $touched_coupons ) ) as $coupon_id ) {
			Coupon::recompute_times_used( $coupon_id );
		}

		/**
		 * Fires after all LMS rows for a deleted user have been removed.
		 *
		 * @param int   $user_id  The WP user that was deleted.
		 * @param array $summary  Row counts keyed by table domain.
		 */
		$summary = array(
			'attempts'      => $attempt_count,
			'responses'     => $response_count,
			'certs'         => $cert_count,
			'tokens'        => $token_count,
			'progress'      => $progress_count,
			'enrollments'   => $enrollment_count,
			'reviews'       => $review_count,
			'coupon_usages' => $usage_count,
		);
		do_action( 'learnomy_user_data_purged', $user_id, $summary );

		return $summary;
	}

	/**
	 * Delete certificate rows whose user_id references a non-existent WP user.
	 *
	 * Used by the CLI `wp learnomy cleanup orphan-certs` subcommand to
	 * retroactively fix the 83 orphan rows that existed before the
	 * `deleted_user` hook was added.
	 *
	 * The cross-table JOIN lives in Certificate::get_orphan_ids() — raw $wpdb
	 * is allowed inside includes/models/ per architecture rule F-1.
	 *
	 * Returns the number of rows deleted.
	 */
	public function delete_orphan_certificates(): int {
		$orphan_ids = Certificate::get_orphan_ids();

		if ( empty( $orphan_ids ) ) {
			return 0;
		}

		$deleted = 0;
		foreach ( $orphan_ids as $cert_id ) {
			if ( Certificate::delete( $cert_id ) ) {
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * Delete enrollment rows whose WordPress user no longer exists.
	 *
	 * Same shape and same era as {@see self::delete_orphan_certificates()}: rows
	 * left behind before the `deleted_user` hook purged a departing user's LMS
	 * data. Deleting through the model keeps the counter and cache invalidation
	 * paths intact.
	 *
	 * @return int Rows deleted.
	 */
	public function delete_orphan_enrollments(): int {
		$deleted = 0;

		foreach ( Enrollment::get_orphan_ids() as $enrollment_id ) {
			if ( Enrollment::delete( $enrollment_id ) ) {
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * Delete commission rows whose parent transaction no longer exists.
	 *
	 * Engagement splits (`transaction_id = 0`) are never orphans and are excluded
	 * by the model query. A commission with no transaction can never be paid out
	 * or reconciled against a sale, so it only inflates pending-payout counts.
	 *
	 * @return int Rows deleted.
	 */
	public function delete_orphan_commissions(): int {
		$deleted = 0;

		foreach ( Commission::get_orphan_ids() as $commission_id ) {
			if ( Commission::delete( $commission_id ) ) {
				++$deleted;
			}
		}

		return $deleted;
	}

	// ── Private helpers ────────────────────────────────────────────────────────

	/** Zero-value summary for early-return paths. */
	private function empty_summary(): array {
		return array(
			'attempts'      => 0,
			'responses'     => 0,
			'certs'         => 0,
			'tokens'        => 0,
			'progress'      => 0,
			'enrollments'   => 0,
			'reviews'       => 0,
			'coupon_usages' => 0,
		);
	}
}
