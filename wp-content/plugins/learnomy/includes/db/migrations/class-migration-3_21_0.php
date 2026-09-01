<?php
/**
 * Migration 3.21.0 — carry the "Quiz Graded" email settings onto the key that
 * is actually read.
 *
 * The Settings → Emails toggle wrote `email_notify_quiz_graded`, but the email
 * is sent as type `quiz_result` — so the code looked up
 * `email_notify_quiz_result`, never found it, and defaulted to "on". The toggle
 * was inert. The same key drives the copy overrides, so a site owner's custom
 * subject/body (`email_subj_quiz_graded` / `email_body_quiz_graded`) were
 * silently ignored as well (Basecamp 10104865195).
 *
 * 1.7.0 renames the key to `quiz_result`, which is the correct half of the
 * mismatch to move: `quiz_result` is already the notification type stored in
 * `lrn_notifications`, the string `Email_Content::for_type()` matches, and what
 * the REST/app surfaces read. Renaming the sent type instead would have fixed
 * the toggle by breaking every consumer of the type.
 *
 * But a rename alone would REVERSE the one thing an owner explicitly asked for:
 * whoever ticked "Quiz Graded" off — the very people this bug ignored — would
 * have their setting land on a key nobody reads and start receiving the email
 * again on upgrade. A silent opt-out reversal is worse than the inert toggle,
 * so the saved values move with the key.
 *
 * All three keys move, not just the toggle, because all three were dead.
 * Forward-only + idempotent: only copies when the old key exists and the new
 * one does not, so a re-run cannot clobber a choice made after the upgrade.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_3_21_0 {

	/**
	 * Old key => new key, for every setting a wrong template name drove.
	 *
	 * Two mismatches, not one. The card reported the quiz toggle; diffing the
	 * toggle list against every type `Notifier::notify()` actually sends found
	 * the instructor-application toggles broken the same way — they said
	 * `instructor_*` while the emails send (and Email_Content matches)
	 * `application_*`. Same defect, same release.
	 */
	private const RENAMED = array(
		'email_notify_quiz_graded'         => 'email_notify_quiz_result',
		'email_subj_quiz_graded'           => 'email_subj_quiz_result',
		'email_body_quiz_graded'           => 'email_body_quiz_result',
		'email_notify_instructor_approved' => 'email_notify_application_approved',
		'email_subj_instructor_approved'   => 'email_subj_application_approved',
		'email_body_instructor_approved'   => 'email_body_application_approved',
		'email_notify_instructor_rejected' => 'email_notify_application_rejected',
		'email_subj_instructor_rejected'   => 'email_subj_application_rejected',
		'email_body_instructor_rejected'   => 'email_body_application_rejected',
	);

	public function up(): void {
		$settings = get_option( 'learnomy_settings', array() );
		if ( ! is_array( $settings ) ) {
			return;
		}

		$changed = false;

		foreach ( self::RENAMED as $old => $new ) {
			if ( ! array_key_exists( $old, $settings ) ) {
				continue;
			}

			// Never overwrite a value saved against the new key — on a re-run, or
			// where an owner has already used the fixed toggle, their current
			// choice wins over the historical one.
			if ( ! array_key_exists( $new, $settings ) ) {
				$settings[ $new ] = $settings[ $old ];
				$changed          = true;
			}

			unset( $settings[ $old ] );
			$changed = true;
		}

		if ( $changed ) {
			update_option( 'learnomy_settings', $settings );
		}
	}
}
