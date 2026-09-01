/**
 * Extracted inline script from includes/admin/views/modules.php.
 * Enqueued via class-admin.php conditionally on the matching admin page.
 * Per ux-foundation rule F2 (no inline <script> in PHP).
 *
 * @package Learnomy
 */
(function () {
	'use strict';

	var __      = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	// Toasts come from the shared implementation, not a private one.
	//
	// This screen used to carry its own showToast() writing into a
	// #lrn-module-toast element, and the success path called
	// showToast.flash() — a method the shared toast has and this local copy
	// never did. The throw landed inside the .then(), the .catch() reported it
	// as "Network error", and the switch flipped back even though the module
	// had already been saved. Nothing appeared in the console, so the screen
	// blamed the network for a missing function (Basecamp 10198722572).
	//
	// A private copy of a shared behaviour only has to fall one method behind
	// to start lying, so the copy is gone rather than completed. `flash` is the
	// method that matters here: this page reloads 600ms after a successful
	// toggle, so the confirmation has to survive the reload to be seen at all.
	function showToast(msg, isError) {
		window.lrnToast(msg, isError ? 'error' : 'success');
	}

	// Plan-08: dismiss the Pro Features pointer notice. WP core wires the
	// `.notice-dismiss` button via core/notices.js automatically; we just
	// listen for its click and persist the preference per-user.
	var proNotice = document.querySelector('[data-lrn-pro-notice="1"]');
	if (proNotice) {
		proNotice.addEventListener('click', function (e) {
			if (!e.target.classList.contains('notice-dismiss')) return;
			var admin = window.learnomyAdmin || {};
			fetch( ( admin.restUrl || '/wp-json/learnomy/v1/' ) + 'admin/dismiss-notice/learnomy_pro_modules_notice_dismissed', {
				method:      'POST',
				credentials: 'same-origin',
				headers:     { 'X-WP-Nonce': admin.restNonce || '' },
			} ).catch(function () { /* best-effort; the user can dismiss again on next load */ });
		});
	}

	/**
	 * POST the toggle. `confirm` acknowledges a live-commitment warning the
	 * server already showed us — see the 409 branch below.
	 *
	 * Pro module slugs use the form `pro:<id>` (e.g. pro:cohorts).
	 * encodeURIComponent encodes the colon as %3A, but WP REST matches the
	 * literal route pattern `[a-z0-9_:-]+` against the decoded path — and the
	 * encoded colon never decodes back in time to satisfy the regex, so every
	 * Pro toggle 404s. Colon is path-safe per RFC 3986, so put it back
	 * unencoded.
	 *
	 * @param {string}  slug    Module slug.
	 * @param {boolean} enabled Desired state.
	 * @param {boolean} confirm Whether the owner has accepted the impact.
	 * @return {Promise<{ok: boolean, status: number, body: Object}>} Result.
	 */
	function postToggle( slug, enabled, confirm ) {
		var admin = window.learnomyAdmin || {};
		var safeSlug = encodeURIComponent( slug ).replace( /%3A/gi, ':' );
		return fetch( ( admin.restUrl || '/wp-json/learnomy/v1/' ) + 'admin/modules/' + safeSlug, {
			method:      'POST',
			credentials: 'same-origin',
			headers:     {
				'Content-Type': 'application/json',
				'X-WP-Nonce':   admin.restNonce || '',
			},
			body: JSON.stringify( { enabled: enabled, confirm: !! confirm } ),
		} ).then( function ( r ) {
			return r.json().then( function ( b ) {
				return { ok: r.ok, status: r.status, body: b };
			} );
		} );
	}

	document.querySelectorAll('.lrn-module-toggle').forEach(function (el) {
		el.addEventListener('change', function () {
			var checkbox = this;
			var slug = checkbox.dataset.module;
			var moduleName = checkbox.dataset.moduleName || slug;
			var enabled = checkbox.checked;
			checkbox.disabled = true;

			function settle( out ) {
				checkbox.disabled = false;
				if (out.ok) {
					/* translators: %s: module name */
					window.lrnToast.flash(sprintf(enabled ? __('%s enabled', 'learnomy') : __('%s disabled', 'learnomy'), moduleName), 'success');
					var card = checkbox.closest('.lrn-module-card');
					if (card) card.classList.toggle('lrn-module-card--enabled', enabled);
					setTimeout(function () { window.location.reload(); }, 600);
					return;
				}
				checkbox.checked = !enabled;
				var msg = (out.body && out.body.error && out.body.error.message) || __('Toggle failed', 'learnomy');
				showToast(msg, true);
			}

			// REST POST /admin/modules/{slug} — replaces the legacy
			// learnomy_module_toggle AJAX (deleted in Stage 5K-H).
			postToggle( slug, enabled, false )
				.then(function (out) {
					// 409 = the module still owes people money or access. The
					// server refuses until the owner has actually read what they
					// are about to do, so show it and re-POST with confirm.
					var impact = ( out.body && out.body.error && out.body.error.details && out.body.error.details.impact ) || null;
					if ( 409 !== out.status || ! impact || ! impact.length || ! window.lrnConfirm ) {
						settle( out );
						return;
					}

					var lines = impact.map( function ( item ) { return item.message; } );
					return window.lrnConfirm( {
						/* translators: %s: module name */
						title:        sprintf( __( 'Turn off %s?', 'learnomy' ), moduleName ),
						message:      lines.join( '\n\n' ) + '\n\n'
							+ __( 'Nothing is cancelled or deleted. Members who already bought keep their access and can still manage their billing.', 'learnomy' ),
						confirmLabel: __( 'Turn it off anyway', 'learnomy' ),
						cancelLabel:  __( 'Keep it on', 'learnomy' ),
						destructive:  true,
					} ).then( function ( ok ) {
						if ( ! ok ) {
							checkbox.disabled = false;
							checkbox.checked = !enabled;
							return;
						}
						return postToggle( slug, enabled, true ).then( settle );
					} );
				})
				.catch(function () {
					checkbox.disabled = false;
					checkbox.checked = !enabled;
					showToast(__('Network error', 'learnomy'), true);
				});
		});
	});
})();
