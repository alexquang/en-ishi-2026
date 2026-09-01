<?php
/**
 * Question-bank picker modal (shared by the quiz editor's create + edit modes).
 *
 * The list, filters, and pagination are populated by assets/js/admin/question-bank.js.
 * On a brand-new (unsaved) quiz the "Add selected" button auto-saves the quiz
 * first, then attaches (Basecamp #9905191822), so this modal works identically
 * during quiz creation and editing.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;
?>
<!-- Question Bank Picker modal -->
<div class="lrn-modal lrn-modal--hidden" id="lrn-question-picker-modal" role="dialog" aria-modal="true" aria-labelledby="lrn-question-picker-title">
	<div class="lrn-modal__backdrop"></div>
	<div class="lrn-modal__dialog">
		<div class="lrn-modal__head">
			<h3 class="lrn-modal__title" id="lrn-question-picker-title"><?php esc_html_e( 'Add Questions', 'learnomy' ); ?></h3>
			<button type="button" class="lrn-modal__close" aria-label="<?php esc_attr_e( 'Close', 'learnomy' ); ?>">
				<i data-lucide="x"></i>
			</button>
		</div>
		<div class="lrn-modal__body">
			<?php
			/*
			Wrapper is a <div>, not a <form> — the modal sits inside the outer
					quiz <form>, and the browser HTML parser strips nested forms,
					which removed the .lrn-question-picker-filters element entirely
					and broke the picker init (bug card "Add Questions from Bank
					Not Working"). */
			?>
			<div class="lrn-question-picker-filters">
				<input type="search" id="lrn-picker-search"
					placeholder="<?php esc_attr_e( 'Search questions…', 'learnomy' ); ?>" class="regular-text">
				<select id="lrn-picker-type">
					<option value=""><?php esc_html_e( 'All types', 'learnomy' ); ?></option>
				</select>
			</div>
			<ul class="lrn-question-picker-list" id="lrn-picker-list"></ul>
			<div class="lrn-question-picker-empty lrn-hidden" id="lrn-picker-empty">
				<?php esc_html_e( 'No questions match those filters.', 'learnomy' ); ?>
			</div>
			<div class="lrn-question-picker-pagination" id="lrn-picker-pagination"></div>
		</div>
		<div class="lrn-modal__foot">
			<button type="button" class="lrn-admin-btn" id="lrn-picker-cancel" data-lrn-modal-close>
				<?php esc_html_e( 'Cancel', 'learnomy' ); ?>
			</button>
			<button type="button" class="lrn-admin-btn lrn-admin-btn--primary" id="lrn-picker-add">
				<?php esc_html_e( 'Add selected', 'learnomy' ); ?>
			</button>
		</div>
	</div>
</div>
