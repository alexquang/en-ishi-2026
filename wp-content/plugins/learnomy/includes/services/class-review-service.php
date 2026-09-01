<?php
/**
 * Review Service — student course reviews with rating aggregation.
 *
 * @package Learnomy
 */

namespace Learnomy\Services;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Review;
use Learnomy\Models\Course;
use Learnomy\Models\Enrollment;
use Learnomy\Models\Progress;
use function Learnomy\now;

class Review_Service {

	public function submit( int $user_id, int $course_id, int $rating, string $title, string $content ): int {
		if ( ! Enrollment::is_enrolled( $user_id, $course_id ) ) {
			throw new \RuntimeException( esc_html__( 'You must be enrolled in this course to leave a review.', 'learnomy' ) );
		}

		if ( Review::has_reviewed( $user_id, $course_id ) ) {
			throw new \RuntimeException( esc_html__( 'You have already submitted a review for this course.', 'learnomy' ) );
		}

		if ( ! $this->has_completed_lesson( $user_id, $course_id ) ) {
			throw new \RuntimeException( esc_html__( 'Please complete at least one lesson before leaving a review.', 'learnomy' ) );
		}

		$rating = max( 1, min( 5, $rating ) );

		self::assert_within_limit( $title, Review::MAX_TITLE_LENGTH, __( 'Review title', 'learnomy' ) );
		self::assert_within_limit( $content, Review::MAX_CONTENT_LENGTH, __( 'Review', 'learnomy' ) );

		// Honour the admin `auto_approve_reviews` toggle. When ON, reviews
		// publish immediately and feed the avg_rating right away; when OFF,
		// they sit as pending until an admin approves. Wired in Wave 2C row
		// 2C-7 — before this, the Review model's default ('approved') made
		// every review auto-publish regardless of the admin setting.
		$status = self::auto_approve_enabled() ? 'approved' : 'pending';

		$review_id = Review::create(
			[
				'user_id'   => $user_id,
				'course_id' => $course_id,
				'rating'    => $rating,
				'title'     => sanitize_text_field( $title ),
				'content'   => wp_kses_post( $content ),
				'status'    => $status,
			]
		);

		// A refused write used to return 0 here, and the controller then handed
		// `Review::find( 0 )` (null) to `single_response()`, which is typed
		// `object|array` — a hard TypeError and an HTTP 500 with the student's
		// review lost. Fail loudly and in the same shape as every other guard
		// above, so the caller's existing catch renders a real message.
		if ( $review_id <= 0 ) {
			throw new \RuntimeException( esc_html__( 'Your review could not be saved. Please try again.', 'learnomy' ) );
		}

		// Aggregate only counts `approved` rows, so a pending review can't
		// move the average — recompute either way to keep counts consistent.
		$this->recalculate_rating( $course_id );

		do_action( 'learnomy_review_submitted', $review_id, $user_id, $course_id );

		return $review_id;
	}

	/**
	 * Site-wide auto-approval flag. Default-off mirrors the install default
	 * in includes/class-learnomy.php (`auto_approve_reviews => false`) so
	 * fresh sites moderate reviews until an admin opts into auto-publish.
	 */
	public static function auto_approve_enabled(): bool {
		$settings = get_option( 'learnomy_settings', array() );
		if ( ! is_array( $settings ) || ! array_key_exists( 'auto_approve_reviews', $settings ) ) {
			return false;
		}
		return ! empty( $settings['auto_approve_reviews'] );
	}

	public function approve( int $review_id ): bool {
		return $this->set_status( $review_id, 'approved', 'learnomy_review_approved' );
	}

	public function reject( int $review_id ): bool {
		return $this->set_status( $review_id, 'rejected', 'learnomy_review_rejected' );
	}

	public function spam( int $review_id ): bool {
		return $this->set_status( $review_id, 'spam', 'learnomy_review_marked_spam' );
	}

	/**
	 * Single seam for status flips from the admin moderation page.
	 *
	 * Recomputes the course's denormalised avg_rating + review_count
	 * whenever the row enters or leaves the `approved` bucket so the
	 * catalog and single-course pages stay in sync without a cron pass.
	 * Fires a per-status action so notifications can hook in.
	 */
	private function set_status( int $review_id, string $status, string $hook ): bool {
		$review = Review::find( $review_id );
		if ( ! $review ) {
			return false;
		}

		$result = Review::update(
			$review_id,
			array(
				'status'     => $status,
				'updated_at' => now(),
			)
		);

		if ( $result ) {
			$this->recalculate_rating( (int) $review->course_id );
			/**
			 * Fires per-status — the caller passes the literal hook name
			 * (one of `learnomy_review_approved`, `learnomy_review_rejected`,
			 * `learnomy_review_marked_spam`). The dynamic-hook sniff fires on
			 * the variable but every fan-out is prefixed `learnomy_`.
			 *
			 * @param int $review_id Review row id.
			 * @param int $course_id Course id the review belongs to.
			 * @param int $user_id   Student who authored the review.
			 */
			do_action( $hook, $review_id, (int) $review->course_id, (int) $review->user_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
		}

		return $result;
	}

	public function delete( int $review_id ): bool {
		$review = Review::find( $review_id );
		if ( ! $review ) {
			return false;
		}

		$course_id = (int) $review->course_id;
		$result    = Review::delete( $review_id );

		if ( $result ) {
			$this->recalculate_rating( $course_id );
		}

		return $result;
	}

	public function reply( int $review_id, string $reply ): bool {
		self::assert_within_limit( $reply, Review::MAX_REPLY_LENGTH, __( 'Reply', 'learnomy' ) );

		return Review::update(
			$review_id,
			[
				'instructor_reply'    => wp_kses_post( $reply ),
				'instructor_reply_at' => now(),
				'updated_at'          => now(),
			]
		);
	}

	/**
	 * Length ceiling, enforced in the service so EVERY caller is covered — the
	 * REST arg schemas reject first and give the nicer message, but an ability,
	 * a CLI command or an integration calling the service directly must not be
	 * able to walk past the same rule.
	 *
	 * Counts characters (not bytes) so the ceiling means the same thing to a
	 * reviewer writing in Japanese as to one writing in English.
	 *
	 * @throws \RuntimeException When the value is longer than $max.
	 */
	private static function assert_within_limit( string $value, int $max, string $label ): void {
		if ( mb_strlen( $value ) <= $max ) {
			return;
		}

		throw new \RuntimeException(
			esc_html(
				sprintf(
					/* translators: 1: field label, e.g. "Review". 2: maximum number of characters. */
					__( '%1$s is too long. Please keep it under %2$s characters.', 'learnomy' ),
					$label,
					number_format_i18n( $max )
				)
			)
		);
	}

	public function recalculate_rating( int $course_id ): void {
		$aggregate = Review::get_aggregate( $course_id );

		$avg_rating   = round( (float) $aggregate->avg_rating, 2 );
		$review_count = (int) $aggregate->review_count;

		Course::update(
			$course_id,
			[
				'avg_rating'   => $avg_rating,
				'review_count' => $review_count,
				'updated_at'   => now(),
			]
		);
	}

	// Returns true if the user has at least one completed lesson in the course.
	private function has_completed_lesson( int $user_id, int $course_id ): bool {
		return Progress::has_completed_lesson_in_course( $user_id, $course_id );
	}
}
