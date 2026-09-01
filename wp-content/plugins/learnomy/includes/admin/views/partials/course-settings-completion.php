<?php
/**
 * Completion & Certificate section — Phase 1 course settings.
 *
 * Every field here either lives on a first-class column
 * (completion_type, completion_value) or inside `settings_json` via
 * Course::get_all_settings(). The partial only renders HTML; submission
 * is handled by the course-form AJAX handler.
 *
 * @package Learnomy
 *
 * @var array        $course_settings        Full settings array, defaults merged.
 * @var object|null  $course                 Course row (null on create).
 * @var bool         $is_edit                True when editing.
 * @var object[]     $certificate_templates  Active certificate templates.
 */

defined( 'ABSPATH' ) || exit;

$completion_type        = $is_edit ? ( $course->completion_type ?? 'all_lessons' ) : 'all_lessons';
$completion_value       = $is_edit ? (int) ( $course->completion_value ?? 60 ) : 60;
$completion_enforcement = $is_edit ? (string) ( $course->completion_enforcement ?? '' ) : '';
if ( $completion_value < 1 ) {
	$completion_value = 60;
}

$certificate_enabled     = (bool) ( $course_settings['certificate_enabled'] ?? false );
$certificate_template_id = (int) ( $course_settings['certificate_template_id'] ?? 0 );
$completion_message      = (string) ( $course_settings['completion_message'] ?? '' );
$allow_retake            = (bool) ( $course_settings['allow_retake'] ?? false );
$reset_on_retake         = (bool) ( $course_settings['reset_on_retake'] ?? false );

$completion_types = array(
	'all_lessons'      => __( 'All Lessons Completed', 'learnomy' ),
	'lessons_and_quiz' => __( 'Lessons + Final Quiz', 'learnomy' ),
	'percentage'       => __( 'Percentage of Content', 'learnomy' ),
	'manual'           => __( 'Manual Completion', 'learnomy' ),
);
?>
<section class="lrn-settings-section is-open" data-section="completion">
	<button type="button" class="lrn-settings-section__header" aria-expanded="true">
		<span class="lrn-settings-section__heading">
			<i data-lucide="award"></i>
			<span><?php esc_html_e( 'Completion & Certificate', 'learnomy' ); ?></span>
		</span>
		<i data-lucide="chevron-down" class="lrn-settings-section__chevron"></i>
	</button>
	<div class="lrn-settings-section__body">
		<p class="lrn-settings-section__help">
			<?php esc_html_e( 'Define how students complete this course and whether a certificate is awarded.', 'learnomy' ); ?>
		</p>

		<div class="lrn-form-field">
			<label for="lrn-course-completion"><?php esc_html_e( 'Completion Requirement', 'learnomy' ); ?></label>
			<select id="lrn-course-completion" name="completion_type" data-lrn-controls="completion-percent">
				<?php foreach ( $completion_types as $val => $label ) : ?>
					<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $completion_type, $val ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>

		<div class="lrn-form-field" data-lrn-conditional="completion-percent" <?php echo 'percentage' === $completion_type ? '' : 'hidden'; ?>>
			<label for="lrn-course-completion-value"><?php esc_html_e( 'Completion Percentage', 'learnomy' ); ?></label>
			<div class="lrn-range-row">
				<input type="range" id="lrn-course-completion-value" name="completion_value"
					min="1" max="100" step="1"
					value="<?php echo esc_attr( (string) $completion_value ); ?>"
					data-lrn-range-output="completion-value-output">
				<output class="lrn-range-row__value" id="completion-value-output" for="lrn-course-completion-value">
					<?php echo esc_html( $completion_value . '%' ); ?>
				</output>
			</div>
			<p class="lrn-form-help"><?php esc_html_e( 'Minimum portion of lessons students must finish to complete the course.', 'learnomy' ); ?></p>
		</div>

		<div class="lrn-form-field">
			<label for="lrn-course-completion-enforcement"><?php esc_html_e( 'Per-Lesson Rule Enforcement', 'learnomy' ); ?></label>
			<select id="lrn-course-completion-enforcement" name="completion_enforcement">
				<option value="" <?php selected( $completion_enforcement, '' ); ?>>
					<?php esc_html_e( 'Inherit (use site setting)', 'learnomy' ); ?>
				</option>
				<option value="strict" <?php selected( $completion_enforcement, 'strict' ); ?>>
					<?php esc_html_e( 'Strict: rules must be met', 'learnomy' ); ?>
				</option>
				<option value="advisory" <?php selected( $completion_enforcement, 'advisory' ); ?>>
					<?php esc_html_e( 'Advisory: shown but optional', 'learnomy' ); ?>
				</option>
				<option value="off" <?php selected( $completion_enforcement, 'off' ); ?>>
					<?php esc_html_e( 'Off: no rules shown', 'learnomy' ); ?>
				</option>
			</select>
			<p class="lrn-form-help"><?php esc_html_e( 'How strictly per-lesson completion rules (watch time, video %, scroll, quiz pass) are enforced in this course. Inherits the site-wide default unless overridden. Individual lessons may still override this per lesson.', 'learnomy' ); ?></p>
		</div>

		<div class="lrn-form-field">
			<label class="lrn-checkbox-label" for="lrn-course-cert-enabled">
				<input type="checkbox" id="lrn-course-cert-enabled"
					name="certificate_enabled" value="1"
					data-lrn-controls="certificate-template"
					<?php checked( $certificate_enabled ); ?>>
				<?php esc_html_e( 'Award a certificate on completion', 'learnomy' ); ?>
			</label>
		</div>

		<div class="lrn-form-field" data-lrn-conditional="certificate-template" <?php echo $certificate_enabled ? '' : 'hidden'; ?>>
			<label for="lrn-cert-template-search"><?php esc_html_e( 'Certificate Template', 'learnomy' ); ?></label>

			<?php
			// The certificate designer is admin-only BY DESIGN (see
			// Admin::hidden_submenu_pages() — it defaults to manage_options). So the
			// empty state has to say something different to each role: an admin can
			// go and build one; an instructor cannot, and telling them "creating one
			// takes a minute" sent them to a page that answers "Sorry, you are not
			// allowed to access this page" (Basecamp 10082715994).
			//
			// Guard on the page's REGISTERED capability, not Permission_Engine —
			// WordPress enforces manage_options on the target page, and
			// Permission_Engine short-circuits on it, so a user granted
			// learnomy_manage_settings without manage_options would pass the guard
			// and still eat the 403.
			$lrn_can_design_certs = current_user_can( 'manage_options' );
			?>
			<?php if ( empty( $certificate_templates ) ) : ?>
				<div class="lrn-empty-cta">
					<?php if ( $lrn_can_design_certs ) : ?>
						<p><?php esc_html_e( 'No certificate templates yet', 'learnomy' ); ?></p>
						<a class="lrn-admin-btn lrn-admin-btn--primary"
							href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-certificate-designer' ) ); ?>">
							<i data-lucide="plus"></i>
							<?php esc_html_e( 'Create your first certificate template', 'learnomy' ); ?>
						</a>
					<?php else : ?>
						<p><?php esc_html_e( 'No certificate templates available to you yet', 'learnomy' ); ?></p>
						<p class="lrn-form-help">
							<?php esc_html_e( 'An administrator needs to create a certificate template and make it available to instructors before you can award one here.', 'learnomy' ); ?>
						</p>
					<?php endif; ?>
					<input type="hidden" name="certificate_template_id" value="0">
				</div>
			<?php else : ?>
				<div class="lrn-searchable-select" data-lrn-searchable-select>
					<input type="search" id="lrn-cert-template-search"
						class="lrn-searchable-select__search regular-text"
						placeholder="<?php esc_attr_e( 'Search templates…', 'learnomy' ); ?>"
						data-lrn-search-input>
					<select name="certificate_template_id" class="lrn-searchable-select__native">
						<option value="0"><?php esc_html_e( 'Select a template', 'learnomy' ); ?></option>
						<?php foreach ( $certificate_templates as $tpl ) : ?>
							<option value="<?php echo absint( $tpl->id ); ?>"
								<?php selected( $certificate_template_id, (int) $tpl->id ); ?>>
								<?php echo esc_html( $tpl->title ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<?php if ( $lrn_can_design_certs ) : ?>
						<p class="lrn-form-help">
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-certificate-designer' ) ); ?>">
								<?php esc_html_e( 'Manage certificate templates', 'learnomy' ); ?>
							</a>
						</p>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>

		<div class="lrn-form-field lrn-form-field--wide">
			<label for="lrn-course-completion-message"><?php esc_html_e( 'Completion Message', 'learnomy' ); ?></label>
			<?php
			// A short, plain message — a textarea (not the block editor) keeps the
			// three entry points consistent with the frontend builder, which uses
			// a plain textarea here. Seed clean text: post-cutover the stored value
			// is semantic HTML, which a textarea would show as literal tags.
			?>
			<textarea name="completion_message" id="lrn-course-completion-message" class="lrn-input" rows="3"
				placeholder="<?php esc_attr_e( 'Congratulations! You have completed the course.', 'learnomy' ); ?>"><?php echo esc_textarea( \Learnomy\editor_plain_text( (string) $completion_message ) ); ?></textarea>
			<p class="lrn-form-help"><?php esc_html_e( 'Displayed on the completion page. Shown after the student finishes the course.', 'learnomy' ); ?></p>
		</div>

		<div class="lrn-form-field">
			<label class="lrn-checkbox-label" for="lrn-course-allow-retake">
				<input type="checkbox" id="lrn-course-allow-retake"
					name="allow_retake" value="1"
					data-lrn-controls="reset-on-retake"
					<?php checked( $allow_retake ); ?>>
				<?php esc_html_e( 'Allow students to retake the course', 'learnomy' ); ?>
			</label>
		</div>

		<div class="lrn-form-field" data-lrn-conditional="reset-on-retake" <?php echo $allow_retake ? '' : 'hidden'; ?>>
			<label class="lrn-checkbox-label" for="lrn-course-reset-retake">
				<input type="checkbox" id="lrn-course-reset-retake"
					name="reset_on_retake" value="1"
					<?php checked( $reset_on_retake ); ?>>
				<?php esc_html_e( 'Reset progress when they retake', 'learnomy' ); ?>
			</label>
			<p class="lrn-form-help"><?php esc_html_e( 'Clears lesson & quiz progress so the student starts fresh.', 'learnomy' ); ?></p>
		</div>
	</div>
</section>
