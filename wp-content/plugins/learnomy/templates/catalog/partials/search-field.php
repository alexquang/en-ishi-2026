<?php
/**
 * Catalog search field + typeahead — ONE input, rendered by every catalog layout.
 *
 * The grid layout and the sidebar layout each used to carry their own copy of
 * this markup. They drifted: `data-wp-bind--value="state.searchQuery"` was added
 * to the grid copy to stop the field clearing itself, and the sidebar copy never
 * got it, so the bug stayed live on that layout for every site using it.
 *
 * THE BINDING IS LOAD-BEARING, not decoration. This input sits inside an
 * interactive region, so any state change (opening the typeahead, receiving
 * suggestions) re-renders it. Without a value binding the runtime rewrites the
 * DOM value back to the server-rendered `value` attribute — empty on an unfiltered
 * catalog — so each keystroke wipes the last. Typing "cour" left "r".
 *
 * Expects in scope:
 *   $search (string) The current search term from the query, for the no-JS render.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$search = isset( $search ) ? (string) $search : '';
?>
<input
	id="lrn-search"
	type="search"
	name="s"
	class="lrn-input"
	placeholder="<?php esc_attr_e( 'Search courses', 'learnomy' ); ?>"
	value="<?php echo esc_attr( $search ); ?>"
	autocomplete="off"
	role="combobox"
	aria-autocomplete="list"
	aria-expanded="false"
	data-wp-bind--value="state.searchQuery"
	data-wp-bind--aria-expanded="state.suggestVisible"
	aria-controls="lrn-search-suggest-list"
	data-wp-bind--aria-activedescendant="state.activeSuggestionDomId"
	data-wp-on--input="actions.search"
	data-wp-on--focus="actions.openSuggestions"
	data-wp-on--blur="actions.hideSuggestions"
	data-wp-on--keydown="actions.searchKeydown"
>
<?php require \Learnomy\template_path( 'catalog/partials/search-suggest.php' ); ?>
