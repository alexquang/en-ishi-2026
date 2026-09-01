<?php
/**
 * View-data resolver for the post-cancel landing page.
 *
 * Renders when the buyer abandons at the gateway. Reads `course_id` /
 * `plan_id` from the query string and builds a retry CTA pointed back at
 * the existing `/enroll/?course_id=N` checkout. No DB row is created or
 * read for cancellations — there's nothing to record.
 *
 * @package Learnomy
 */

namespace Learnomy\Services\View_Data;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Course;
use Learnomy\Models\MembershipPlan;

class Checkout_Cancelled_View_Data {

	/**
	 * Resolve all data needed by templates/checkout-cancelled.php.
	 *
	 * @param array $data Route data (typically just 'route' => 'enroll-cancelled').
	 * @return array Pre-fetched view data.
	 */
	public static function resolve( array $data ): array {
		unset( $data );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only landing page populated by gateway redirect.
		$course_id = isset( $_GET['course_id'] ) ? absint( $_GET['course_id'] ) : 0;
		$plan_id   = isset( $_GET['plan_id'] ) ? absint( $_GET['plan_id'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$settings  = get_option( 'learnomy_settings', array() );
		$base_slug = (string) ( $settings['base_slug'] ?? 'courses' );

		$course = $course_id ? Course::find( $course_id ) : null;
		$plan   = $plan_id ? MembershipPlan::find( $plan_id ) : null;

		$item_title = '';
		if ( $course && ! empty( $course->title ) ) {
			$item_title = (string) $course->title;
		} elseif ( $plan && ! empty( $plan->title ) ) {
			$item_title = (string) $plan->title;
		}

		// Retry URL — back to the checkout source the buyer just abandoned.
		$retry_url = \Learnomy\route_url( 'enroll' );
		if ( $course_id ) {
			$retry_url = add_query_arg( 'course_id', $course_id, $retry_url );
		} elseif ( $plan_id ) {
			$retry_url = add_query_arg( 'plan_id', $plan_id, $retry_url );
		}

		$view_data = array(
			'course_id'        => $course_id,
			'plan_id'          => $plan_id,
			'course'           => $course,
			'plan'             => $plan,
			'item_title'       => $item_title,
			'is_plan_purchase' => (bool) $plan_id,
			'retry_url'        => $retry_url,
			'browse_url'       => home_url( '/' . $base_slug . '/' ),
			'membership_url'   => \Learnomy\route_url( 'membership-pricing' ),
			'help_url'         => home_url( '/' ),
		);

		/**
		 * Filter the cancelled-flow view data before it reaches the template.
		 *
		 * Pro extensions use this to swap in an upsell (e.g. "20% off if you
		 * complete in the next 15 minutes") or attach a customer-support
		 * link tied to the abandoned cart.
		 *
		 * @since 2.2.0
		 *
		 * @param array $view_data Resolved view data.
		 * @param int   $course_id Course the buyer was checking out (0 if plan).
		 * @param int   $plan_id   Plan the buyer was checking out (0 if course).
		 */
		return (array) apply_filters( 'learnomy_checkout_cancelled_view_data', $view_data, $course_id, $plan_id );
	}
}
