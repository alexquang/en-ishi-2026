<?php
/**
 * Progression & Flow section — Phase 1 course settings.
 *
 * @package Learnomy
 *
 * @var array $course_settings
 */

defined( 'ABSPATH' ) || exit;

$require_sequential = (bool) ( $course_settings['require_sequential'] ?? false );
$min_time_seconds   = (int) ( $course_settings['min_time_seconds'] ?? 0 );
$min_time_minutes   = (int) floor( $min_time_seconds / 60 );
?>
<section class="lrn-settings-section is-open" data-section="progression">
	<button type="button" class="lrn-settings-section__header" aria-expanded="true">
		<span class="lrn-settings-section__heading">
			<i data-lucide="list-ordered"></i>
			<span><?php esc_html_e( 'Progression & Flow', 'learnomy' ); ?></span>
		</span>
		<i data-lucide="chevron-down" class="lrn-settings-section__chevron"></i>
	</button>
	<div class="lrn-settings-section__body">
		<p class="lrn-settings-section__help">
			<?php esc_html_e( 'Control how students move through the content.', 'learnomy' ); ?>
		</p>

		<div class="lrn-form-field">
			<label class="lrn-checkbox-label" for="lrn-course-require-sequential">
				<input type="checkbox" id="lrn-course-require-sequential"
					name="require_sequential" value="1"
					<?php checked( $require_sequential ); ?>>
				<?php esc_html_e( 'Require lessons to be completed in order', 'learnomy' ); ?>
			</label>
			<p class="lrn-form-help"><?php esc_html_e( 'Students must finish each lesson before the next one unlocks.', 'learnomy' ); ?></p>
		</div>

		<div class="lrn-form-field">
			<label for="lrn-course-min-time"><?php esc_html_e( 'Minimum Time per Lesson', 'learnomy' ); ?></label>
			<div class="lrn-duration-row">
				<input type="number" id="lrn-course-min-time"
					name="min_time_minutes"
					min="0" step="1"
					value="<?php echo esc_attr( (string) $min_time_minutes ); ?>"
					class="small-text">
				<span class="lrn-duration-row__unit">
					<label for="lrn-course-min-time"><?php esc_html_e( 'minutes', 'learnomy' ); ?></label>
				</span>
			</div>
			<p class="lrn-form-help"><?php esc_html_e( 'Prevents Mark Complete from enabling before this much time elapses. 0 disables the check.', 'learnomy' ); ?></p>
		</div>
	</div>
</section>
