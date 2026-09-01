<?php
/**
 * Quiz resume banner — shown when a student reopens an in-progress attempt, so
 * the reappearing player narrates "your state was restored" instead of reading
 * as if progress was lost. Interactivity-API driven: visibility + detail come
 * from the quiz store (state.resumeNoticeVisible / state.resumeNoticeDetail),
 * set on a resume in assets/js/quiz-view.js. Shared by every quiz template.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="lrn-quiz-resume-banner" role="status" data-wp-bind--hidden="!state.resumeNoticeVisible">
	<i data-lucide="history" class="lrn-quiz-resume-banner__icon" aria-hidden="true"></i>
	<p class="lrn-quiz-resume-banner__text">
		<strong><?php esc_html_e( 'Welcome back - your progress was restored.', 'learnomy' ); ?></strong>
		<span class="lrn-quiz-resume-banner__detail" data-wp-text="state.resumeNoticeDetail"></span>
	</p>
	<button type="button" class="lrn-quiz-resume-banner__dismiss" data-wp-on--click="actions.dismissResumeNotice" aria-label="<?php esc_attr_e( 'Dismiss', 'learnomy' ); ?>">
		<i data-lucide="x" aria-hidden="true"></i>
	</button>
</div>
