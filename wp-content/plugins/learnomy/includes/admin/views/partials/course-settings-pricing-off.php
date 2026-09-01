<?php
/**
 * Pricing & Access — the explained-absence state.
 *
 * Rendered instead of course-settings-pricing.php when the
 * `learnomy_course_form_show_pricing` filter hides the pricing panel
 * (an internal academy, a Pro site-mode toggle, an owner snippet).
 *
 * This partial exists so the panel can NEVER vanish silently. When
 * pricing disappeared with no explanation, instructors read the screen
 * as broken and reported "settings not saving" (Basecamp #9932303730) —
 * which is why the payments module became non-disableable. The lesson
 * was "never hide pricing SILENTLY", not "never hide it": hidden by an
 * owner's explicit choice AND labelled, the absence is configuration,
 * not a bug.
 *
 * Saving a course with this partial rendered keeps existing prices: the
 * form JS omits absent inputs (course-form.js guards every collector)
 * and the REST controller only updates keys present in the payload.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="lrn-settings-section is-open" data-section="pricing">
	<button type="button" class="lrn-settings-section__header" aria-expanded="true">
		<span class="lrn-settings-section__heading">
			<i data-lucide="credit-card"></i>
			<span><?php esc_html_e( 'Pricing & Access', 'learnomy' ); ?></span>
		</span>
		<i data-lucide="chevron-down" class="lrn-settings-section__chevron"></i>
	</button>
	<div class="lrn-settings-section__body">
		<p class="lrn-settings-section__help">
			<?php esc_html_e( 'Pricing is switched off for this site, so this course is free or invite only. Any prices saved earlier are kept and come back if pricing is switched on again.', 'learnomy' ); ?>
		</p>
	</div>
</section>
