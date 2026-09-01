<?php
/**
 * Outbound webhook event registrar.
 *
 * Bridges Learnomy business-event hooks to {@see Webhook_Service::dispatch()}
 * so the core (Free) outbound webhook feature actually fires on real activity
 * instead of only on the admin "Send test" button.
 *
 * It is also the single source for the subscribable-event list shown on the
 * Webhooks admin screen, so the events an admin can pick and the events that
 * actually fire can never drift apart.
 *
 * When Webhooks Pro is active it owns delivery through its own retry-capable
 * dispatcher and turns this core path off via the
 * `learnomy_webhooks_core_dispatch_enabled` filter, so a single endpoint is
 * never delivered twice.
 *
 * @package Learnomy
 */

namespace Learnomy\Services;

defined( 'ABSPATH' ) || exit;

class Webhook_Events {

	/**
	 * Register on `init` rather than at construction time so a
	 * `plugins_loaded`-stage consumer (Webhooks Pro boots at priority 20)
	 * can set the core-dispatch guard before the listeners are wired.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register' ) );
	}

	/**
	 * Canonical event catalogue: dot-notation slug => { hook, label }.
	 *
	 * One source feeds both the admin subscribe UI (slug => label) and the
	 * dispatch listeners (hook => slug). Extensions add lifecycle events via
	 * the `learnomy_webhook_event_map` filter; whatever they add becomes
	 * selectable in the UI and deliverable in the same step.
	 *
	 * @return array<string, array{hook: string, label: string}>
	 */
	public static function map(): array {
		$map = array(
			'enrollment.created'   => array(
				'hook'  => 'learnomy_student_enrolled',
				'label' => __( 'Enrollment Created', 'learnomy' ),
			),
			'enrollment.completed' => array(
				'hook'  => 'learnomy_course_completed',
				'label' => __( 'Enrollment Completed', 'learnomy' ),
			),
			'course.published'     => array(
				'hook'  => 'learnomy_course_published',
				'label' => __( 'Course Published', 'learnomy' ),
			),
			'quiz.submitted'       => array(
				'hook'  => 'learnomy_quiz_submitted',
				'label' => __( 'Quiz Submitted', 'learnomy' ),
			),
			'payment.completed'    => array(
				'hook'  => 'learnomy_payment_completed',
				'label' => __( 'Payment Completed', 'learnomy' ),
			),
			'membership.created'   => array(
				'hook'  => 'learnomy_membership_created',
				'label' => __( 'Membership Created', 'learnomy' ),
			),
			'withdrawal.requested' => array(
				'hook'  => 'learnomy_withdrawal_requested',
				'label' => __( 'Withdrawal Requested', 'learnomy' ),
			),
			// The rest of the payout lifecycle. `requested` was catalogued alone,
			// so an owner wiring payouts into their accounting tool could see a
			// request open and never see it close.
			'withdrawal.rejected'  => array(
				'hook'  => 'learnomy_withdrawal_rejected',
				'label' => __( 'Withdrawal Rejected', 'learnomy' ),
			),
			'withdrawal.paid'      => array(
				'hook'  => 'learnomy_withdrawal_paid',
				'label' => __( 'Withdrawal Paid', 'learnomy' ),
			),
			'withdrawal.failed'    => array(
				'hook'  => 'learnomy_withdrawal_failed',
				'label' => __( 'Withdrawal Payout Failed', 'learnomy' ),
			),
			'certificate.issued'   => array(
				'hook'  => 'learnomy_certificate_issued',
				'label' => __( 'Certificate Issued', 'learnomy' ),
			),
			'review.submitted'     => array(
				'hook'  => 'learnomy_review_submitted',
				'label' => __( 'Review Submitted', 'learnomy' ),
			),
			'instructor.approved'  => array(
				'hook'  => 'learnomy_instructor_application_approved',
				'label' => __( 'Instructor Application Approved', 'learnomy' ),
			),
		);

		/**
		 * Filter the outbound webhook event catalogue.
		 *
		 * @param array<string, array{hook: string, label: string}> $map slug => { hook, label }.
		 */
		return apply_filters( 'learnomy_webhook_event_map', $map );
	}

	/**
	 * slug => human label, for the admin subscribe UI.
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		$labels = array();
		foreach ( self::map() as $slug => $event ) {
			$labels[ $slug ] = $event['label'] ?? $slug;
		}
		return $labels;
	}

	/**
	 * Wire each catalogued business-event hook to the dispatcher.
	 *
	 * No-op when a delivery provider (Webhooks Pro) has claimed the channel
	 * via `learnomy_webhooks_core_dispatch_enabled`, so endpoints subscribed
	 * through the shared UI are never delivered twice.
	 */
	public function register(): void {
		/**
		 * Whether the core (Free) webhook dispatcher should wire itself to
		 * business events. Webhooks Pro returns false so its retry-capable
		 * dispatcher is the single delivery path.
		 *
		 * @param bool $enabled Default true.
		 */
		if ( ! apply_filters( 'learnomy_webhooks_core_dispatch_enabled', true ) ) {
			return;
		}

		foreach ( self::map() as $slug => $event ) {
			$hook = $event['hook'] ?? '';
			if ( '' === $hook ) {
				continue;
			}

			add_action(
				$hook,
				static function () use ( $slug ): void {
					( new Webhook_Service() )->dispatch(
						$slug,
						array(
							'event'     => $slug,
							'timestamp' => gmdate( 'c' ),
							'data'      => func_get_args(),
						)
					);
				},
				10,
				10
			);
		}
	}
}
