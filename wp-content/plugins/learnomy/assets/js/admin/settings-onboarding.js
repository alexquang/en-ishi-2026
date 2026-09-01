/**
 * Settings → onboarding-reopen button.
 *
 * Originally an inline <script> in includes/admin/views/settings.php.
 * Extracted per ux-foundation rule F2. Enqueued via class-admin.php on
 * the `learnomy-settings` admin page. Dashboard URL localised through
 * `lrnAdminSettings.dashboardUrl`.
 *
 * @package Learnomy
 */
( function () {
	'use strict';

	var data  = window.lrnAdminSettings || {};
	var admin = window.learnomyAdmin || {};

	var btn = document.getElementById( 'lrn-onboarding-reopen' );
	if ( ! btn ) {
		return;
	}

	btn.addEventListener( 'click', function () {
		btn.disabled = true;
		fetch( ( admin.restUrl || '/wp-json/learnomy/v1/' ) + 'admin/onboarding-state', {
			method:      'PATCH',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce':   admin.restNonce || '',
			},
			body: JSON.stringify( { dismissed: false } ),
		} )
			.then( function ( r ) {
				return r.json().then( function ( j ) { return { ok: r.ok, body: j }; } );
			} )
			.then( function ( out ) {
				btn.disabled = false;
				if ( out.ok && data.dashboardUrl ) {
					window.location.href = data.dashboardUrl;
				}
			} );
	} );
}() );
