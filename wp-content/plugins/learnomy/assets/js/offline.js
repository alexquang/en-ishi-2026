/**
 * Offline page — Try Again button.
 *
 * Reloads the page when the user clicks the retry CTA. Rendered by
 * templates/offline.php for the `offline` route.
 *
 * @package Learnomy
 */
( function () {
	'use strict';

	var btn = document.querySelector( '.lrn-offline__retry' );
	if ( ! btn ) {
		return;
	}
	btn.addEventListener( 'click', function () {
		window.location.reload();
	} );
}() );
