/**
 * Extracted inline script from includes/admin/views/courses.php.
 * Enqueued via class-admin.php conditionally on the matching admin page.
 * Per ux-foundation rule F2 (no inline <script> in PHP).
 *
 * @package Learnomy
 */
document.addEventListener( 'lrn:bulk-action', function ( e ) {
	var detail = e.detail;
	if ( ! detail || ! detail.ids || ! detail.ids.length ) { return; }

	// REST POST /courses/bulk — Stripe-style per-id results array.
	// Action value matches the legacy AJAX vocabulary: 'delete' or a target
	// status (published / archived / draft).
	var admin = window.learnomyAdmin || {};
	fetch( ( admin.restUrl || '/wp-json/learnomy/v1/' ) + 'courses/bulk', {
		method:      'POST',
		credentials: 'same-origin',
		headers:     {
			'Content-Type': 'application/json',
			'X-WP-Nonce':   admin.restNonce || '',
		},
		body: JSON.stringify( { action: detail.action, ids: detail.ids.map( function ( id ) { return parseInt( id, 10 ); } ) } ),
	} )
		.then( function ( r ) { return r.json(); } )
		.then( function ( result ) {
			if ( result && result.data && Array.isArray( result.data.results ) ) {
				window.location.reload();
			}
		} );
} );
