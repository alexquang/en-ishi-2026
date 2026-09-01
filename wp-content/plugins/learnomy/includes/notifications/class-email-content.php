<?php
/**
 * Per-type email content — subject, heading, body, and CTA for each notification.
 *
 * Each method returns an associative array with keys: subject, heading, body,
 * and optionally cta_url, cta_text, and footer. The body is HTML-safe and
 * written in a warm, professional tone.
 *
 * @package Learnomy\Notifications
 */

namespace Learnomy\Notifications;

defined( 'ABSPATH' ) || exit;

class Email_Content {

	/**
	 * Dispatches to the correct content method based on notification type.
	 *
	 * @param string   $type The notification type slug.
	 * @param \WP_User $user Recipient user object.
	 * @param array    $data Notification data from the Notifier callback.
	 * @return array|null Content array or null if no template exists.
	 */
	public static function for_type( string $type, \WP_User $user, array $data ): ?array {
		$name = $user->display_name;

		switch ( $type ) {
			case 'enrollment':
				return self::enrollment_welcome(
					$name,
					$data['course_title'] ?? '',
					$data['course_url'] ?? $data['action_url'] ?? ''
				);

			case 'course_completed':
				return self::course_completed(
					$name,
					$data['course_title'] ?? '',
					$data['action_url'] ?? \Learnomy\route_url( 'student-dashboard' )
				);

			case 'certificate_issued':
				return self::certificate_issued(
					$name,
					$data['course_title'] ?? '',
					$data['verification_url'] ?? $data['action_url'] ?? ''
				);

			case 'quiz_result':
				return self::quiz_graded(
					$name,
					$data['title'] ?? \Learnomy\quiz_label(),
					(int) ( $data['score'] ?? 0 ),
					! empty( $data['passed'] ),
					$data['action_url'] ?? \Learnomy\route_url( 'student-dashboard' )
				);

			case 'application_submitted':
				return self::instructor_application_received(
					$name,
					$data['action_url'] ?? \Learnomy\route_url( 'student-dashboard' )
				);

			case 'admin_new_application':
				return self::admin_new_application(
					$data['applicant_name'] ?? '',
					$data['action_url'] ?? admin_url( 'admin.php?page=learnomy-applications' )
				);

			case 'application_approved':
				return self::instructor_approved(
					$name,
					$data['action_url'] ?? \Learnomy\route_url( 'instructor-dashboard' )
				);

			case 'application_rejected':
				return self::instructor_rejected(
					$name,
					$data['rejection_reason'] ?? ''
				);

			case 'withdrawal_approved':
				$amount   = (float) ( $data['amount'] ?? 0 );
				$currency = $data['currency'] ?? 'USD';
				return self::withdrawal_approved( $name, $amount, $currency );

			case 'withdrawal_rejected':
				return self::withdrawal_rejected(
					$name,
					(float) ( $data['amount'] ?? 0 ),
					$data['currency'] ?? 'USD',
					(string) ( $data['rejection_reason'] ?? '' )
				);

			case 'withdrawal_paid':
				return self::withdrawal_paid(
					$name,
					(float) ( $data['amount'] ?? 0 ),
					$data['currency'] ?? 'USD',
					(string) ( $data['reference'] ?? '' )
				);

			case 'withdrawal_failed':
				return self::withdrawal_failed(
					$name,
					(float) ( $data['amount'] ?? 0 ),
					$data['currency'] ?? 'USD'
				);

			case 'admin_withdrawal_failed':
				return self::admin_withdrawal_failed(
					(float) ( $data['amount'] ?? 0 ),
					$data['currency'] ?? 'USD',
					(string) ( $data['error'] ?? '' ),
					$data['action_url'] ?? admin_url( 'admin.php?page=learnomy-earnings' )
				);

			case 'membership_created':
				return self::membership_created(
					$name,
					$data['plan_title'] ?? '',
					$data['manage_url'] ?? \Learnomy\route_url( 'membership-manage' )
				);

			case 'membership_cancelled':
				return self::membership_cancelled(
					$name,
					$data['plan_title'] ?? '',
					$data['expiry_date'] ?? ''
				);

			case 'membership_renewed':
				return self::membership_renewed(
					$name,
					$data['plan_title'] ?? '',
					$data['next_billing'] ?? ''
				);

			case 'membership_expired':
				return self::membership_expired(
					$name,
					$data['plan_title'] ?? ''
				);

			case 'lesson_unlocked':
				return self::lesson_unlocked(
					$name,
					$data['lesson_title'] ?? '',
					$data['course_title'] ?? '',
					$data['lesson_url'] ?? $data['action_url'] ?? \Learnomy\route_url( 'student-dashboard' )
				);

			// PR-E reminder cadences. The 7d versions (enrollment_expiring,
			// membership_expiring) are handled by the cases above via the
			// existing notification types; the new types fan in here.
			case 'enrollment_expiring':
			case 'enrollment_expiring_3d':
			case 'enrollment_expiring_1d':
				return self::access_expiring_reminder(
					$name,
					(int) ( $data['days_out'] ?? 7 ),
					(string) ( $data['expires_at'] ?? '' ),
					(int) ( $data['object_id'] ?? 0 )
				);

			case 'membership_expiring':
			case 'membership_renewing_3d':
			case 'membership_renewing_1d':
				return self::subscription_renewal_reminder(
					$name,
					(int) ( $data['days_out'] ?? 7 ),
					(string) ( $data['current_period_end'] ?? '' )
				);

			default:
				return null;
		}
	}

	/**
	 * Access-expiring reminder email — fired at 7 / 3 / 1 days out by
	 * `Cron::send_enrollment_expiry_reminders()`. The same case feeds all
	 * three cadences; the copy adapts to `$days` ("expires tomorrow" vs
	 * "expires in N days"). Course URL is the renew CTA.
	 */
	public static function access_expiring_reminder( string $student_name, int $days, string $expires_at, int $course_id ): array {
		$date_human = '' !== $expires_at
			? wp_date( get_option( 'date_format' ), strtotime( $expires_at ) )
			: '';

		// Pull the course slug for the CTA. Defer the lookup until we need it
		// — many sites send these in batches; cheap to skip when $course_id
		// is 0 (defensive).
		$course_url = \Learnomy\route_url( 'student-dashboard' );
		if ( $course_id > 0 ) {
			$course = \Learnomy\Models\Course::find( $course_id );
			if ( $course && ! empty( $course->slug ) ) {
				$course_url = \Learnomy\route_url( 'course', array( 'slug' => $course->slug ) );
			}
		}

		$heading = 1 === $days
			? sprintf(
				/* translators: %s: singular Course label, renamable in Settings > Labels */
				__( 'Your %s access expires tomorrow', 'learnomy' ),
				\Learnomy\course_label()
			)
			: sprintf(
				/* translators: 1: singular Course label, renamable in Settings > Labels; 2: days remaining */
				_n( 'Your %1$s access expires in %2$d day', 'Your %1$s access expires in %2$d days', $days, 'learnomy' ),
				\Learnomy\course_label(),
				$days
			);

		$body = '' !== $date_human
			? sprintf(
				/* translators: 1: student name, 2: singular Course label, renamable in Settings > Labels; 3: expiry date */
				__( 'Hi %1$s, your %2$s access expires on %3$s. Renew now to keep your progress and your certificates.', 'learnomy' ),
				esc_html( $student_name ),
				\Learnomy\course_label(),
				esc_html( $date_human )
			)
			: sprintf(
				/* translators: 1: student name, 2: singular Course label, renamable in Settings > Labels */
				__( 'Hi %1$s, your %2$s access is expiring soon. Renew now to keep your progress and your certificates.', 'learnomy' ),
				esc_html( $student_name ),
				\Learnomy\course_label()
			);

		return array(
			'subject'  => $heading,
			'heading'  => $heading,
			'body'     => $body,
			'cta_url'  => $course_url,
			'cta_text' => 1 === $days ? __( 'Renew today', 'learnomy' ) : __( 'Renew now', 'learnomy' ),
		);
	}

	/**
	 * Subscription renewal reminder — fired at 7 / 3 / 1 days out by
	 * `Cron::send_subscription_renewal_reminders()`. Copy adapts to `$days`
	 * the same way the access-expiring reminder does. CTA points at the
	 * /account/billing/ page where the buyer can update their payment
	 * method via the Stripe Customer Portal (PR-C).
	 */
	public static function subscription_renewal_reminder( string $student_name, int $days, string $renewal_date_raw ): array {
		$date_human = '' !== $renewal_date_raw
			? wp_date( get_option( 'date_format' ), strtotime( $renewal_date_raw ) )
			: '';

		$heading = 1 === $days
			? __( 'Your membership renews tomorrow', 'learnomy' )
			: sprintf(
				/* translators: %d: days remaining */
				_n( 'Your membership renews in %d day', 'Your membership renews in %d days', $days, 'learnomy' ),
				$days
			);

		$body = '' !== $date_human
			? sprintf(
				/* translators: 1: student name, 2: renewal date */
				__( 'Hi %1$s, your membership renews on %2$s. If you want to update your payment method or cancel, you can do that from your billing page.', 'learnomy' ),
				esc_html( $student_name ),
				esc_html( $date_human )
			)
			: sprintf(
				/* translators: %s: student name */
				__( 'Hi %s, your membership is renewing soon. If you want to update your payment method or cancel, you can do that from your billing page.', 'learnomy' ),
				esc_html( $student_name )
			);

		return array(
			'subject'  => $heading,
			'heading'  => $heading,
			'body'     => $body,
			'cta_url'  => \Learnomy\route_url( 'account-billing' ),
			'cta_text' => __( 'Open billing page', 'learnomy' ),
		);
	}

	/**
	 * Welcome email after enrolling in a course.
	 */
	public static function enrollment_welcome( string $student_name, string $course_title, string $course_url ): array {
		$site_name = get_bloginfo( 'name' );

		return array(
			'subject'  => sprintf(
				/* translators: %s: course title */
				__( 'You are in. Welcome to %s', 'learnomy' ),
				$course_title
			),
			'heading'  => sprintf(
				/* translators: %s: student first name */
				__( 'Welcome, %s.', 'learnomy' ),
				self::first_name( $student_name )
			),
			'body'     => '<p style="margin:0 0 16px;font-size:15px;line-height:26px;color:#37352f;">'
				. sprintf(
					/* translators: %s: course title in bold */
					__( 'You just enrolled in %s. Your learning journey starts now.', 'learnomy' ),
					'<strong>' . esc_html( $course_title ) . '</strong>'
				)
				. '</p>'
				. '<p style="margin:0 0 16px;font-size:15px;line-height:26px;color:#37352f;">'
				. __( 'Jump in whenever you are ready. Your progress is saved automatically, so you can pick up right where you left off.', 'learnomy' )
				. '</p>',
			'cta_url'  => $course_url,
			'cta_text' => __( 'Start Learning', 'learnomy' ),
		);
	}

	/**
	 * Congratulations email when a course is completed.
	 */
	public static function course_completed( string $student_name, string $course_title, string $course_url ): array {
		return array(
			'subject'  => sprintf(
				/* translators: %s: course title */
				__( '%s is complete', 'learnomy' ),
				$course_title
			),
			'heading'  => sprintf(
				/* translators: %s: student first name */
				__( 'Congratulations, %s.', 'learnomy' ),
				self::first_name( $student_name )
			),
			'body'     => '<p style="margin:0 0 16px;font-size:15px;line-height:26px;color:#37352f;">'
				. sprintf(
					/* translators: %s: course title in bold */
					__( 'You have successfully completed %s. That is a real accomplishment.', 'learnomy' ),
					'<strong>' . esc_html( $course_title ) . '</strong>'
				)
				. '</p>'
				. '<p style="margin:0 0 16px;font-size:15px;line-height:26px;color:#37352f;">'
				. sprintf(
					/* translators: %s: singular Course label, renamable in Settings > Labels */
					__( 'If a certificate is available for this %s, it will appear in your dashboard shortly.', 'learnomy' ),
					\Learnomy\course_label()
				)
				. '</p>',
			'cta_url'  => $course_url,
			'cta_text' => sprintf(
				/* translators: %s: plural Course label, renamable in Settings > Labels */
				__( 'View My %s', 'learnomy' ),
				\Learnomy\course_label( true )
			),
		);
	}

	/**
	 * Certificate ready for download/sharing.
	 */
	public static function certificate_issued( string $student_name, string $course_title, string $verify_url ): array {
		return array(
			'subject'  => sprintf(
				/* translators: %s: course title */
				__( 'Your certificate for %s is ready', 'learnomy' ),
				$course_title
			),
			'heading'  => __( 'Your certificate is ready', 'learnomy' ),
			'body'     => '<p style="margin:0 0 16px;font-size:15px;line-height:26px;color:#37352f;">'
				. sprintf(
					/* translators: 1: student name, 2: course title in bold */
					__( 'Your certificate for %2$s has been issued, %1$s. It is ready to download and share.', 'learnomy' ),
					esc_html( self::first_name( $student_name ) ),
					'<strong>' . esc_html( $course_title ) . '</strong>'
				)
				. '</p>'
				. '<p style="margin:0 0 16px;font-size:15px;line-height:26px;color:#37352f;">'
				. __( 'Each certificate includes a unique verification link that employers and colleagues can use to confirm its authenticity.', 'learnomy' )
				. '</p>',
			'cta_url'  => $verify_url,
			'cta_text' => __( 'View Certificate', 'learnomy' ),
		);
	}

	/**
	 * Quiz graded — different tone for pass vs. fail.
	 */
	public static function quiz_graded( string $student_name, string $quiz_title, int $score, bool $passed, string $course_url ): array {
		if ( $passed ) {
			$subject = sprintf(
				/* translators: %d: score percentage */
				__( 'You passed. Score: %d%%', 'learnomy' ),
				$score
			);
			$heading = sprintf(
				/* translators: %s: singular Quiz label, renamable in Settings > Labels */
				__( 'You passed the %s.', 'learnomy' ),
				\Learnomy\quiz_label()
			);
			$body = '<p style="margin:0 0 16px;font-size:15px;line-height:26px;color:#37352f;">'
				. sprintf(
					/* translators: 1: student first name, 2: score percentage */
					__( '%1$s, you scored %2$d%% and passed.', 'learnomy' ),
					esc_html( self::first_name( $student_name ) ),
					$score
				)
				. '</p>';
		} else {
			$subject = sprintf(
				/* translators: 1: singular Quiz label, renamable in Settings > Labels; 2: score percentage */
				__( 'Your %1$s results: %2$d%%', 'learnomy' ),
				\Learnomy\quiz_label(),
				$score
			);
			$heading = sprintf(
				/* translators: %s: singular Quiz label, renamable in Settings > Labels */
				__( 'Your %s results are in', 'learnomy' ),
				\Learnomy\quiz_label()
			);
			$body = '<p style="margin:0 0 16px;font-size:15px;line-height:26px;color:#37352f;">'
				. sprintf(
					/* translators: 1: student first name, 2: score percentage */
					__( '%1$s, you scored %2$d%% on this attempt. You can review the material and try again.', 'learnomy' ),
					esc_html( self::first_name( $student_name ) ),
					$score
				)
				. '</p>';
		}

		return array(
			'subject'  => $subject,
			'heading'  => $heading,
			'body'     => $body,
			'cta_url'  => $course_url,
			'cta_text' => $passed ? __( 'Continue learning', 'learnomy' ) : __( 'Review & Retry', 'learnomy' ),
		);
	}

	/**
	 * Instructor application received (sent to the applicant on submission).
	 */
	public static function instructor_application_received( string $instructor_name, string $courses_url ): array {
		return array(
			'subject'  => __( 'We received your instructor application', 'learnomy' ),
			'heading'  => sprintf(
				/* translators: %s: instructor first name */
				__( 'Thanks, %s.', 'learnomy' ),
				self::first_name( $instructor_name )
			),
			'body'     => '<p style="margin:0 0 16px;font-size:15px;line-height:26px;color:#37352f;">'
				. __( 'Your instructor application has been received and is pending review. We will email you as soon as it has been reviewed.', 'learnomy' )
				. '</p>'
				. '<p style="margin:0 0 16px;font-size:15px;line-height:26px;color:#37352f;">'
				. __( 'In the meantime you can keep learning as a student.', 'learnomy' )
				. '</p>',
			'cta_url'  => $courses_url,
			'cta_text' => sprintf(
				/* translators: %s: plural Course label, renamable in Settings > Labels */
				__( 'Go to My %s', 'learnomy' ),
				\Learnomy\course_label( true )
			),
		);
	}

	/**
	 * New instructor application awaiting review (sent to the site admin).
	 */
	public static function admin_new_application( string $applicant_name, string $review_url ): array {
		return array(
			'subject'  => __( 'New instructor application to review', 'learnomy' ),
			'heading'  => __( 'New instructor application', 'learnomy' ),
			'body'     => '<p style="margin:0 0 16px;font-size:15px;line-height:26px;color:#37352f;">'
				. sprintf(
					/* translators: %s: applicant display name */
					__( '%s has applied to become an instructor and is awaiting your review.', 'learnomy' ),
					esc_html( $applicant_name )
				)
				. '</p>',
			'cta_url'  => $review_url,
			'cta_text' => __( 'Review Applications', 'learnomy' ),
		);
	}

	/**
	 * Instructor application approved.
	 */
	public static function instructor_approved( string $instructor_name, string $dashboard_url ): array {
		return array(
			'subject'  => __( 'Your instructor application has been approved', 'learnomy' ),
			'heading'  => sprintf(
				/* translators: %s: instructor first name */
				__( 'Welcome to the team, %s.', 'learnomy' ),
				self::first_name( $instructor_name )
			),
			'body'     => '<p style="margin:0 0 16px;font-size:15px;line-height:26px;color:#37352f;">'
				. sprintf(
					/* translators: 1: plural Course label, 2: plural Quiz label, both renamable in Settings > Labels */
					__( 'Your instructor application has been reviewed and approved. You can now create %1$s, build %2$s, and start sharing your expertise.', 'learnomy' ),
					\Learnomy\course_label( true ),
					\Learnomy\quiz_label( true )
				)
				. '</p>'
				. '<p style="margin:0 0 16px;font-size:15px;line-height:26px;color:#37352f;">'
				. __( 'Head to your instructor dashboard to get started.', 'learnomy' )
				. '</p>',
			'cta_url'  => $dashboard_url,
			'cta_text' => __( 'Go to Dashboard', 'learnomy' ),
		);
	}

	/**
	 * Instructor application rejected.
	 */
	public static function instructor_rejected( string $instructor_name, string $reason ): array {
		$body = '<p style="margin:0 0 16px;font-size:15px;line-height:26px;color:#37352f;">'
			. sprintf(
				/* translators: %s: instructor first name */
				__( 'Hi %s, thank you for your interest in teaching on our platform.', 'learnomy' ),
				esc_html( self::first_name( $instructor_name ) )
			)
			. '</p>'
			. '<p style="margin:0 0 16px;font-size:15px;line-height:26px;color:#37352f;">'
			. __( 'After reviewing your application, we are unable to approve it at this time.', 'learnomy' )
			. '</p>';

		if ( $reason ) {
			$body .= '<div style="margin:0 0 16px;padding:16px 20px;background-color:#f7f7f5;border-left:3px solid #2383e2;border-radius:4px;">'
				. '<p style="margin:0;font-size:14px;line-height:22px;color:#37352f;"><strong>'
				. esc_html__( 'Feedback:', 'learnomy' ) . '</strong> '
				. esc_html( $reason )
				. '</p></div>';
		}

		$body .= '<p style="margin:0;font-size:15px;line-height:26px;color:#37352f;">'
			. __( 'You are welcome to re-apply in the future. Contact us if you have any questions.', 'learnomy' )
			. '</p>';

		return array(
			'subject' => __( 'Update on your instructor application', 'learnomy' ),
			'heading' => __( 'Application update', 'learnomy' ),
			'body'    => $body,
		);
	}

	/**
	 * Withdrawal approved notification for instructors.
	 */
	public static function withdrawal_approved( string $instructor_name, float $amount, string $currency ): array {
		$formatted = number_format( $amount, 2 );

		return array(
			'subject'  => sprintf(
				/* translators: 1: currency, 2: amount */
				__( 'Withdrawal of %1$s %2$s approved', 'learnomy' ),
				strtoupper( $currency ),
				$formatted
			),
			'heading'  => __( 'Your payout is on the way', 'learnomy' ),
			'body'     => '<p style="margin:0 0 16px;font-size:15px;line-height:26px;color:#37352f;">'
				. sprintf(
					/* translators: 1: instructor first name, 2: currency, 3: formatted amount */
					__( 'Good news, %1$s! Your withdrawal of %2$s %3$s has been approved and is being processed.', 'learnomy' ),
					esc_html( self::first_name( $instructor_name ) ),
					esc_html( strtoupper( $currency ) ),
					esc_html( $formatted )
				)
				. '</p>'
				. '<p style="margin:0;font-size:15px;line-height:26px;color:#37352f;">'
				. __( 'Funds typically arrive within 3-5 business days depending on your payment method.', 'learnomy' )
				. '</p>',
			'cta_url'  => \Learnomy\route_url( 'instructor-dashboard' ),
			'cta_text' => __( 'View Earnings', 'learnomy' ),
		);
	}

	/**
	 * Withdrawal rejected. The reason is the point of the email — an instructor
	 * whose balance silently reappears has no idea whether they did something
	 * wrong, whether to ask again, or whether the site is broken.
	 */
	public static function withdrawal_rejected( string $instructor_name, float $amount, string $currency, string $reason ): array {
		$formatted = number_format( $amount, 2 );

		$body = '<p style="margin:0 0 16px;font-size:15px;line-height:26px;color:#37352f;">'
			. sprintf(
				/* translators: 1: instructor first name, 2: currency, 3: formatted amount */
				__( 'Hi %1$s, your withdrawal request for %2$s %3$s was not approved.', 'learnomy' ),
				esc_html( self::first_name( $instructor_name ) ),
				esc_html( strtoupper( $currency ) ),
				esc_html( $formatted )
			)
			. '</p>';

		if ( '' !== $reason ) {
			$body .= '<div style="margin:0 0 16px;padding:16px;background:#f7f6f3;border-radius:8px;">'
				. '<p style="margin:0;font-size:15px;line-height:26px;color:#37352f;"><strong>'
				. esc_html__( 'Reason:', 'learnomy' ) . '</strong> '
				. esc_html( $reason )
				. '</p></div>';
		}

		$body .= '<p style="margin:0;font-size:15px;line-height:26px;color:#37352f;">'
			. __( 'Nothing was deducted. These earnings are available to withdraw again whenever you are ready.', 'learnomy' )
			. '</p>';

		return array(
			'subject'  => sprintf(
				/* translators: 1: currency, 2: amount */
				__( 'Your withdrawal of %1$s %2$s was not approved', 'learnomy' ),
				strtoupper( $currency ),
				$formatted
			),
			'heading'  => __( 'Withdrawal not approved', 'learnomy' ),
			'body'     => $body,
			'cta_url'  => \Learnomy\route_url( 'instructor-dashboard' ),
			'cta_text' => __( 'View Earnings', 'learnomy' ),
		);
	}

	/**
	 * Payout actually sent by an automated rail (Stripe Connect transfer).
	 */
	public static function withdrawal_paid( string $instructor_name, float $amount, string $currency, string $reference ): array {
		$formatted = number_format( $amount, 2 );

		$body = '<p style="margin:0 0 16px;font-size:15px;line-height:26px;color:#37352f;">'
			. sprintf(
				/* translators: 1: instructor first name, 2: currency, 3: formatted amount */
				__( 'Hi %1$s, your payout of %2$s %3$s has been sent.', 'learnomy' ),
				esc_html( self::first_name( $instructor_name ) ),
				esc_html( strtoupper( $currency ) ),
				esc_html( $formatted )
			)
			. '</p>'
			. '<p style="margin:0 0 16px;font-size:15px;line-height:26px;color:#37352f;">'
			. __( 'Funds usually land in 1-3 business days, depending on your bank.', 'learnomy' )
			. '</p>';

		if ( '' !== $reference ) {
			$body .= '<p style="margin:0;font-size:13px;line-height:22px;color:#787066;">'
				. esc_html__( 'Payment reference:', 'learnomy' ) . ' '
				. esc_html( $reference )
				. '</p>';
		}

		return array(
			'subject'  => sprintf(
				/* translators: 1: currency, 2: amount */
				__( 'Payout of %1$s %2$s sent', 'learnomy' ),
				strtoupper( $currency ),
				$formatted
			),
			'heading'  => __( 'Your payout is on its way', 'learnomy' ),
			'body'     => $body,
			'cta_url'  => \Learnomy\route_url( 'instructor-dashboard' ),
			'cta_text' => __( 'View Earnings', 'learnomy' ),
		);
	}

	/**
	 * Automatic payout failed — instructor copy. Deliberately carries no
	 * provider error text: the instructor cannot act on it, and a raw gateway
	 * string reads as a system fault rather than a next step.
	 */
	public static function withdrawal_failed( string $instructor_name, float $amount, string $currency ): array {
		$formatted = number_format( $amount, 2 );

		return array(
			'subject'  => sprintf(
				/* translators: 1: currency, 2: amount */
				__( 'Your payout of %1$s %2$s could not be sent', 'learnomy' ),
				strtoupper( $currency ),
				$formatted
			),
			'heading'  => __( 'Payout could not be sent', 'learnomy' ),
			'body'     => '<p style="margin:0 0 16px;font-size:15px;line-height:26px;color:#37352f;">'
				. sprintf(
					/* translators: 1: instructor first name, 2: currency, 3: formatted amount */
					__( 'Hi %1$s, we could not send your payout of %2$s %3$s automatically.', 'learnomy' ),
					esc_html( self::first_name( $instructor_name ) ),
					esc_html( strtoupper( $currency ) ),
					esc_html( $formatted )
				)
				. '</p>'
				. '<p style="margin:0;font-size:15px;line-height:26px;color:#37352f;">'
				. __( 'Your request is still open and the site owner has been notified. Check that your payout account is connected and able to receive transfers.', 'learnomy' )
				. '</p>',
			'cta_url'  => \Learnomy\route_url( 'instructor-dashboard' ),
			'cta_text' => __( 'View Earnings', 'learnomy' ),
		);
	}

	/**
	 * Automatic payout failed — site owner copy. This one DOES carry the
	 * provider error, because the owner is the only person who can act on it.
	 */
	public static function admin_withdrawal_failed( float $amount, string $currency, string $error, string $action_url ): array {
		$formatted = number_format( $amount, 2 );

		$body = '<p style="margin:0 0 16px;font-size:15px;line-height:26px;color:#37352f;">'
			. sprintf(
				/* translators: 1: currency, 2: formatted amount */
				__( 'An automatic payout of %1$s %2$s to an instructor could not be sent.', 'learnomy' ),
				esc_html( strtoupper( $currency ) ),
				esc_html( $formatted )
			)
			. '</p>';

		if ( '' !== $error ) {
			$body .= '<div style="margin:0 0 16px;padding:16px;background:#f7f6f3;border-radius:8px;">'
				. '<p style="margin:0;font-size:14px;line-height:24px;color:#37352f;"><strong>'
				. esc_html__( 'Provider said:', 'learnomy' ) . '</strong> '
				. esc_html( $error )
				. '</p></div>';
		}

		$body .= '<p style="margin:0;font-size:15px;line-height:26px;color:#37352f;">'
			. __( 'The withdrawal request is still open, so it can be paid manually or retried once the cause is fixed.', 'learnomy' )
			. '</p>';

		return array(
			'subject'  => sprintf(
				/* translators: 1: currency, 2: amount */
				__( 'Automatic payout of %1$s %2$s failed', 'learnomy' ),
				strtoupper( $currency ),
				$formatted
			),
			'heading'  => __( 'A payout needs your attention', 'learnomy' ),
			'body'     => $body,
			'cta_url'  => $action_url,
			'cta_text' => __( 'View Withdrawal', 'learnomy' ),
		);
	}

	/**
	 * New membership activated.
	 */
	public static function membership_created( string $name, string $plan_title, string $manage_url ): array {
		return array(
			'subject'  => sprintf(
				/* translators: %s: plan title */
				__( 'Welcome to %s', 'learnomy' ),
				$plan_title
			),
			'heading'  => __( 'Your membership is active', 'learnomy' ),
			'body'     => '<p style="margin:0 0 16px;font-size:15px;line-height:26px;color:#37352f;">'
				. sprintf(
					/* translators: 1: member first name, 2: plan title in bold, 3: plural Course label, renamable in Settings > Labels */
					__( 'Hi %1$s, your %2$s membership is now active. You have full access to all the %3$s included in your plan.', 'learnomy' ),
					esc_html( self::first_name( $name ) ),
					'<strong>' . esc_html( $plan_title ) . '</strong>',
					\Learnomy\course_label( true )
				)
				. '</p>'
				. '<p style="margin:0;font-size:15px;line-height:26px;color:#37352f;">'
				. sprintf(
					/* translators: %s: plural Course label, renamable in Settings > Labels */
					__( 'Start exploring your %s today.', 'learnomy' ),
					\Learnomy\course_label( true )
				)
				. '</p>',
			'cta_url'  => $manage_url,
			'cta_text' => __( 'Manage Membership', 'learnomy' ),
		);
	}

	/**
	 * Membership cancelled — still active until expiry.
	 */
	public static function membership_cancelled( string $name, string $plan_title, string $expiry_date ): array {
		$body = '<p style="margin:0 0 16px;font-size:15px;line-height:26px;color:#37352f;">'
			. sprintf(
				/* translators: 1: member first name, 2: plan title */
				__( 'Hi %1$s, your %2$s membership has been cancelled.', 'learnomy' ),
				esc_html( self::first_name( $name ) ),
				esc_html( $plan_title )
			)
			. '</p>';

		if ( $expiry_date ) {
			$body .= '<p style="margin:0 0 16px;font-size:15px;line-height:26px;color:#37352f;">'
				. sprintf(
					/* translators: 1: expiry date, 2: plural Course label, renamable in Settings > Labels */
					__( 'You still have access until %1$s. After that, membership-only %2$s will no longer be available.', 'learnomy' ),
					'<strong>' . esc_html( $expiry_date ) . '</strong>',
					\Learnomy\course_label( true )
				)
				. '</p>';
		}

		$body .= '<p style="margin:0;font-size:15px;line-height:26px;color:#37352f;">'
			. __( 'Changed your mind? You can resubscribe anytime from the membership page.', 'learnomy' )
			. '</p>';

		return array(
			'subject'  => sprintf(
				/* translators: %s: plan title */
				__( 'Your %s membership has been cancelled.', 'learnomy' ),
				$plan_title
			),
			'heading'  => __( 'Membership cancelled', 'learnomy' ),
			'body'     => $body,
			'cta_url'  => \Learnomy\route_url( 'membership-pricing' ),
			'cta_text' => __( 'View Membership Options', 'learnomy' ),
		);
	}

	/**
	 * Membership auto-renewed.
	 */
	public static function membership_renewed( string $name, string $plan_title, string $next_billing ): array {
		$body = '<p style="margin:0 0 16px;font-size:15px;line-height:26px;color:#37352f;">'
			. sprintf(
				/* translators: 1: member first name, 2: plan title in bold */
				__( 'Hi %1$s, your %2$s membership has been renewed successfully.', 'learnomy' ),
				esc_html( self::first_name( $name ) ),
				'<strong>' . esc_html( $plan_title ) . '</strong>'
			)
			. '</p>';

		if ( $next_billing ) {
			$body .= '<p style="margin:0;font-size:15px;line-height:26px;color:#37352f;">'
				. sprintf(
					/* translators: %s: next billing date */
					__( 'Your next billing date is %s.', 'learnomy' ),
					'<strong>' . esc_html( $next_billing ) . '</strong>'
				)
				. '</p>';
		}

		return array(
			'subject'  => sprintf(
				/* translators: %s: plan title */
				__( 'Your %s membership has been renewed', 'learnomy' ),
				$plan_title
			),
			'heading'  => __( 'Membership renewed', 'learnomy' ),
			'body'     => $body,
			'cta_url'  => \Learnomy\route_url( 'membership-manage' ),
			'cta_text' => __( 'Manage Membership', 'learnomy' ),
		);
	}

	/**
	 * Membership expired.
	 */
	public static function membership_expired( string $name, string $plan_title ): array {
		return array(
			'subject'  => sprintf(
				/* translators: %s: plan title */
				__( 'Your %s membership has expired', 'learnomy' ),
				$plan_title
			),
			'heading'  => __( 'Your membership has expired', 'learnomy' ),
			'body'     => '<p style="margin:0 0 16px;font-size:15px;line-height:26px;color:#37352f;">'
				. sprintf(
					/* translators: 1: member first name, 2: plan title, 3: plural Course label, renamable in Settings > Labels */
					__( 'Hi %1$s, your %2$s membership has expired. You no longer have access to membership-only %3$s.', 'learnomy' ),
					esc_html( self::first_name( $name ) ),
					esc_html( $plan_title ),
					\Learnomy\course_label( true )
				)
				. '</p>'
				. '<p style="margin:0;font-size:15px;line-height:26px;color:#37352f;">'
				. __( 'Resubscribe anytime to pick up where you left off. Your progress is still saved.', 'learnomy' )
				. '</p>',
			'cta_url'  => \Learnomy\route_url( 'membership-pricing' ),
			'cta_text' => __( 'Resubscribe', 'learnomy' ),
		);
	}

	/**
	 * Lesson unlocked via drip schedule — fires once per (lesson, student).
	 *
	 * Producer: `Learnomy\Cron\Drip_Cron`. Plan 06 §9 / §10.
	 */
	public static function lesson_unlocked( string $student_name, string $lesson_title, string $course_title, string $lesson_url ): array {
		$body = '<p style="margin:0 0 16px;font-size:15px;line-height:26px;color:#37352f;">'
			. sprintf(
				/* translators: 1: student first name, 2: singular Lesson label, renamable in Settings > Labels; 3: lesson title in bold */
				__( 'Hi %1$s, the %2$s %3$s is now available.', 'learnomy' ),
				esc_html( self::first_name( $student_name ) ),
				\Learnomy\lesson_label(),
				'<strong>' . esc_html( $lesson_title ) . '</strong>'
			)
			. '</p>';

		if ( '' !== $course_title ) {
			$body .= '<p style="margin:0 0 16px;font-size:15px;line-height:26px;color:#37352f;">'
				. sprintf(
					/* translators: %s: course title in bold */
					__( 'Continue your progress in %s at your own pace.', 'learnomy' ),
					'<strong>' . esc_html( $course_title ) . '</strong>'
				)
				. '</p>';
		}

		return array(
			'subject'  => sprintf(
				/* translators: 1: singular Lesson label, renamable in Settings > Labels; 2: lesson title */
				__( 'New %1$s unlocked: %2$s', 'learnomy' ),
				\Learnomy\lesson_label(),
				$lesson_title
			),
			'heading'  => sprintf(
				/* translators: %s: singular Lesson label, renamable in Settings > Labels */
				__( 'A new %s is unlocked', 'learnomy' ),
				\Learnomy\lesson_label()
			),
			'body'     => $body,
			'cta_url'  => $lesson_url,
			'cta_text' => __( 'Continue learning', 'learnomy' ),
		);
	}

	/**
	 * Extracts the first name from a display name for a friendlier greeting.
	 */
	private static function first_name( string $display_name ): string {
		$parts = explode( ' ', trim( $display_name ) );
		return $parts[0] ?: $display_name;
	}
}
