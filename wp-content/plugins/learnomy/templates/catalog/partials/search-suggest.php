<?php
/**
 * Catalog search autocomplete dropdown (Basecamp #10043289391).
 *
 * Included immediately after the catalog search <input>, inside a
 * position:relative wrapper. Driven by the `learnomy/catalog` Interactivity
 * store: as the customer types, `actions.search` debounce-fetches matching
 * courses into `state.suggestions` and this list renders below the field.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="lrn-search-suggest" role="listbox" id="lrn-search-suggest-list"
	aria-label="<?php esc_attr_e( 'Matching courses', 'learnomy' ); ?>"
	data-wp-bind--hidden="!state.suggestVisible">
	<template data-wp-each--sug="state.suggestions" data-wp-each-key="context.sug.url">
		<a class="lrn-search-suggest__item" role="option"
			data-wp-bind--id="context.sug.optId"
			data-wp-bind--href="context.sug.url"
			data-wp-bind--aria-selected="state.isSuggestActive"
			data-wp-class--is-active="state.isSuggestActive"
			data-wp-text="context.sug.title"></a>
	</template>
	<p class="lrn-search-suggest__empty"
		data-wp-bind--hidden="!state.suggestEmpty"
		data-wp-text="state.suggestStatusLabel"></p>
</div>
