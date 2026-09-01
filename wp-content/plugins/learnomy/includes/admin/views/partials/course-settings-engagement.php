<?php
/**
 * Engagement section — Phase 1 course settings.
 *
 * @package Learnomy
 *
 * @var array $course_settings
 */

defined( 'ABSPATH' ) || exit;

$reviews_enabled       = (bool) ( $course_settings['reviews_enabled'] ?? true );
$qa_enabled            = (bool) ( $course_settings['qa_enabled'] ?? false );
$announcements_enabled = (bool) ( $course_settings['announcements_enabled'] ?? true );
$comments_enabled      = (bool) ( $course_settings['comments_enabled'] ?? true );
$welcome_message       = (string) ( $course_settings['welcome_message'] ?? '' );
?>
<section class="lrn-settings-section is-open" data-section="engagement">
	<button type="button" class="lrn-settings-section__header" aria-expanded="true">
		<span class="lrn-settings-section__heading">
			<i data-lucide="messages-square"></i>
			<span><?php esc_html_e( 'Engagement', 'learnomy' ); ?></span>
		</span>
		<i data-lucide="chevron-down" class="lrn-settings-section__chevron"></i>
	</button>
	<div class="lrn-settings-section__body">
		<p class="lrn-settings-section__help">
			<?php esc_html_e( 'Decide which community features are available to enrolled students.', 'learnomy' ); ?>
		</p>

		<div class="lrn-form-field">
			<label class="lrn-checkbox-label" for="lrn-course-reviews-enabled">
				<input type="checkbox" id="lrn-course-reviews-enabled"
					name="reviews_enabled" value="1"
					<?php checked( $reviews_enabled ); ?>>
				<?php esc_html_e( 'Allow course reviews', 'learnomy' ); ?>
			</label>
		</div>

		<?php
		// Only offer the Q&A toggle when an implementation is hooked
		// (half-cooked audit F2): with zero learnomy_course_qa_section
		// subscribers the checkbox saved a value that rendered a blank
		// section — a control that does nothing is confusion, not choice.
		// Self-healing: the toggle (and any stored value) come back the
		// moment an extension supplies the thread UI.
		if ( has_action( 'learnomy_course_qa_section' ) ) :
			?>
		<div class="lrn-form-field">
			<label class="lrn-checkbox-label" for="lrn-course-qa-enabled">
				<input type="checkbox" id="lrn-course-qa-enabled"
					name="qa_enabled" value="1"
					<?php checked( $qa_enabled ); ?>>
				<?php esc_html_e( 'Allow Q&A', 'learnomy' ); ?>
			</label>
		</div>
		<?php endif; ?>

		<div class="lrn-form-field">
			<label class="lrn-checkbox-label" for="lrn-course-announcements-enabled">
				<input type="checkbox" id="lrn-course-announcements-enabled"
					name="announcements_enabled" value="1"
					<?php checked( $announcements_enabled ); ?>>
				<?php esc_html_e( 'Allow instructor announcements', 'learnomy' ); ?>
			</label>
		</div>

		<?php
		// The global `enable_lesson_comments` master kill-switch beats the per-course
		// choice — when it is off, course comments cannot render, so hide this toggle
		// rather than imply it can re-enable them (Basecamp 10127776404). Absent key =
		// default ON, mirroring Lesson::comments_enabled().
		$lrn_global_settings    = get_option( 'learnomy_settings', array() );
		$lrn_course_comments_on = ! (
			is_array( $lrn_global_settings )
			&& array_key_exists( 'enable_lesson_comments', $lrn_global_settings )
			&& empty( $lrn_global_settings['enable_lesson_comments'] )
		);
		// The has_action( 'learnomy_render_course_comments' ) gate is gone.
		//
		// It was added because the toggle's COPY promised a comments section on
		// the course page, and nothing renders one — a fair reason to hide a
		// control that lies. But nothing ever subscribed to that action, in Free,
		// Pro or any theme, so the toggle could never appear at all: a site owner
		// had no way to set this in wp-admin while Pro's course builder exposed
		// it happily, and the same course showed a control in one editor and not
		// the other (Basecamp 10199056673).
		//
		// The copy was the wrong half. This column does not drive a course-page
		// comments section — it is the COURSE-LEVEL DEFAULT FOR LESSON COMMENTS.
		// Lesson::get_effective_comments_enabled() reads a lesson's own NULL as
		// "inherit from the course", so this is what decides whether a lesson's
		// Discussion rail appears when the lesson itself has no explicit
		// override. Pro's builder has always labelled it correctly.
		//
		// So the toggle renders, and says what it actually does. The global
		// kill-switch above still hides it: when lesson comments are off
		// site-wide, no course-level default can re-enable them, and offering
		// one would be the same lie in the other direction.
		if ( $lrn_course_comments_on ) :
			?>
		<div class="lrn-form-field">
			<label class="lrn-checkbox-label" for="lrn-course-comments-enabled">
				<input type="checkbox" id="lrn-course-comments-enabled"
					name="comments_enabled" value="1"
					<?php checked( $comments_enabled ); ?>>
				<?php
				// Same wording as the Pro course builder, deliberately: one
					// setting shown in two editors must not have two names.
				?>
				<?php esc_html_e( 'Allow comments on lessons', 'learnomy' ); ?>
			</label>
			<p class="lrn-form-help"><?php esc_html_e( 'Students can discuss each lesson in this course. A lesson can override this on its own settings.', 'learnomy' ); ?></p>
		</div>
		<?php endif; ?>

		<div class="lrn-form-field lrn-form-field--wide">
			<label for="lrn-course-welcome-message"><?php esc_html_e( 'Welcome Message', 'learnomy' ); ?></label>
			<?php
			// A short, plain message — a textarea (not the block editor) keeps the
			// three entry points consistent with the frontend builder, which uses
			// a plain textarea here. Seed clean text: post-cutover the stored value
			// is semantic HTML, which a textarea would show as literal tags.
			?>
			<textarea name="welcome_message" id="lrn-course-welcome-message" class="lrn-input" rows="2"
				placeholder="<?php esc_attr_e( 'Welcome! Here is how to get started.', 'learnomy' ); ?>"><?php echo esc_textarea( \Learnomy\editor_plain_text( (string) $welcome_message ) ); ?></textarea>
			<p class="lrn-form-help"><?php esc_html_e( 'Shown the first time a student visits the course after enrolling.', 'learnomy' ); ?></p>
		</div>
	</div>
</section>
