/**
 * Settings → Payments demo-reset button.
 *
 * Originally an inline <script> at the bottom of the General settings
 * card in includes/admin/views/settings.php. Extracted per ux-foundation
 * rule F2. Enqueued via class-admin.php on the `learnomy-settings` admin
 * page. Strings + URLs + nonce localised via wp_localize_script into
 * `lrnAdminSettingsPay`.
 *
 * @package Learnomy
 */
( function () {
	'use strict';

	var data = window.lrnAdminSettingsPay || {};
	var __   = wp.i18n.__;
	var i18n = data.i18n || {};
	var ctx  = window.learnomyAdmin || {};

	var btn = document.getElementById( 'lrn-reset-demo-btn' );
	if ( ! btn ) {
		return;
	}

	function notify( msg, tone ) {
		// Prefer the canonical toast helper; fall back through lrnAlert; no native dialogs.
		if ( window.lrnToast ) {
			window.lrnToast( msg, 'success' === tone ? 'success' : 'error' );
			return;
		}
		if ( window.learnomyToast && typeof window.learnomyToast === 'function' ) {
			window.learnomyToast( msg, tone );
			return;
		}
		if ( window.lrnAlert ) {
			window.lrnAlert( { message: msg } );
		}
	}

	btn.addEventListener( 'click', async function () {
		var ok = false;
		if ( window.lrnConfirm ) {
			ok = await window.lrnConfirm( {
				title:        i18n.confirmTitle || __( 'Reset demo content', 'learnomy' ),
				// Real fallback text, not __( '' ). An empty string gave a
				// DESTRUCTIVE confirm with no body at all — a title, a red
				// button and nothing telling the owner what is about to be
				// deleted. __( '' ) is also a gettext no-no: the empty msgid is
				// the .po header, so it returns catalog metadata, not "".
				message:      i18n.confirmReset || __( 'This deletes the demo courses, students, and transactions and creates a fresh set. Continue?', 'learnomy' ),
				// Its OWN key. This used to read i18n.resetting — the in-flight
				// label — so the button you had not pressed yet said "Resetting…".
				confirmLabel: i18n.confirmLabel || __( 'Reset', 'learnomy' ),
				destructive:  true,
			} );
		}
		if ( ! ok ) {
			return;
		}

		btn.disabled = true;
		var origText = btn.textContent;
		btn.textContent = i18n.resetting || __( 'Resetting…', 'learnomy' );

		try {
			var response = await fetch( ctx.restUrl + 'admin/setup-state/reset-demo', {
				method:      'POST',
				credentials: 'same-origin',
				headers: {
					'Accept':       'application/json',
					'Content-Type': 'application/json',
					'X-WP-Nonce':   ctx.restNonce,
				},
				// The endpoint requires an explicit confirmation flag for this
				// destructive action; without it the request 400s (BC #9976862388).
				body: JSON.stringify( { confirm: true } ),
			} );
			var body = await response.json();
			btn.disabled = false;
			btn.textContent = origText;
			var msg = response.ok
				? ( i18n.success || __( 'Demo content reset.', 'learnomy' ) )
				: ( ( body && body.error && body.error.message ) || i18n.failed || __( 'Reset failed.', 'learnomy' ) );
			notify( msg, response.ok ? 'success' : 'danger' );
		} catch ( err ) {
			btn.disabled = false;
			btn.textContent = origText;
			notify( i18n.failed || __( 'Reset failed.', 'learnomy' ), 'danger' );
		}
	} );
}() );
