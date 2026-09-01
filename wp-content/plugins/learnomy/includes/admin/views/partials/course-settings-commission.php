<?php
/**
 * Commission section — per-course platform-rate override.
 *
 * Sits in the fallback chain course → instructor → global
 * (Commission_Service::resolve_platform_rate). A blank value means "inherit",
 * which removes the per-course rule so resolution walks down to the
 * instructor rate, then the global rate. Only rendered when the commissions
 * module is on and we're editing an existing course.
 *
 * @package Learnomy
 * @var object|null $course_commission Exact course rule, or null when inheriting.
 * @var float|null  $inherited_rate    The rate the course resolves to if it inherits.
 */

defined( 'ABSPATH' ) || exit;

$current_rate  = ( isset( $course_commission ) && $course_commission ) ? (float) $course_commission->platform_rate : null;
$fallback_rate = isset( $inherited_rate ) ? (float) $inherited_rate : 0.0;
?>
<section class="lrn-settings-section is-open" data-section="commission">
	<button type="button" class="lrn-settings-section__header" aria-expanded="true">
		<span class="lrn-settings-section__heading">
			<i data-lucide="percent"></i>
			<span><?php esc_html_e( 'Commission', 'learnomy' ); ?></span>
		</span>
		<i data-lucide="chevron-down" class="lrn-settings-section__chevron"></i>
	</button>
	<div class="lrn-settings-section__body">
		<p class="lrn-settings-section__help">
			<?php esc_html_e( 'Set a platform commission just for this course. Leave it blank to inherit the instructor rate, then the global rate.', 'learnomy' ); ?>
		</p>

		<div class="lrn-form-field">
			<label for="lrn-course-commission"><?php esc_html_e( 'Platform commission for this course', 'learnomy' ); ?></label>
			<div class="lrn-duration-row">
				<input type="number" id="lrn-course-commission" name="commission_rate"
					min="0" max="100" step="0.01"
					value="<?php echo null !== $current_rate ? esc_attr( (string) $current_rate ) : ''; ?>"
					placeholder="<?php esc_attr_e( 'Inherit', 'learnomy' ); ?>"
					class="small-text">
				<span class="lrn-duration-row__unit">%</span>
			</div>
			<p class="lrn-form-help">
				<?php
				printf(
					/* translators: %s: the inherited platform rate, e.g. 30.00 */
					esc_html__( 'The platform keeps this percentage; the instructor receives the rest. Leave blank to inherit. Currently %s%% (instructor, then global).', 'learnomy' ),
					esc_html( number_format_i18n( $fallback_rate, 2 ) )
				);
				?>
			</p>
		</div>
	</div>
</section>
