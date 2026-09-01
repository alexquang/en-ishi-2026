/**
 * Service Worker registration for Learnomy.
 *
 * Registers the SW from a virtual root URL (/learnomy-sw.js) so the scope
 * covers all Learnomy frontend routes (/courses/, /my-courses/, etc.).
 *
 * Also handles:
 *   - Online/offline detection with visual indicator
 *   - Flushing the offline progress queue when connectivity returns
 *   - Listening for sync-complete messages from the SW
 *
 * @package Learnomy
 */

( function () {
	'use strict';

	if ( ! ( 'serviceWorker' in navigator ) ) {
		return;
	}

	// ------------------------------------------------------------------
	// Register
	// ------------------------------------------------------------------

	navigator.serviceWorker
		.register( '/learnomy-sw.js', { scope: '/' } )
		.then( function ( registration ) {
			// Check for SW updates on each page load.
			registration.update();

			// Listen for an updated SW waiting to activate.
			registration.addEventListener( 'updatefound', function () {
				var newWorker = registration.installing;
				if ( ! newWorker ) {
					return;
				}
				newWorker.addEventListener( 'statechange', function () {
					if ( newWorker.state === 'activated' ) {
						// New version active — the activate handler already
						// called clients.claim(), so the next fetch uses it.
					}
				} );
			} );
		} )
		.catch( function ( err ) {
			// Registration failed — the site still works, just without
			// offline support. Log for debugging but don't bother the user.
			if ( typeof console !== 'undefined' ) {
				console.warn( 'Learnomy SW registration failed:', err );
			}
		} );

	// ------------------------------------------------------------------
	// Online / offline detection
	// ------------------------------------------------------------------

	/**
	 * Show or hide a small offline banner at the top of the page.
	 *
	 * @param {boolean} isOffline
	 */
	function toggleOfflineBanner( isOffline ) {
		var banner = document.getElementById( 'lrn-offline-banner' );

		if ( isOffline && ! banner ) {
			banner              = document.createElement( 'div' );
			banner.id           = 'lrn-offline-banner';
			banner.className    = 'lrn-offline-banner';
			banner.setAttribute( 'role', 'status' );
			banner.setAttribute( 'aria-live', 'polite' );
			banner.textContent  = learnomySW.i18n.offline || 'You are offline. Changes will sync when you reconnect.';
			document.body.prepend( banner );
		}

		if ( ! isOffline && banner ) {
			banner.textContent = learnomySW.i18n.backOnline || 'Back online — syncing progress\u2026';
			banner.classList.add( 'lrn-offline-banner--online' );

			// Remove after a short delay so the user sees the confirmation.
			setTimeout( function () {
				if ( banner && banner.parentNode ) {
					banner.parentNode.removeChild( banner );
				}
			}, 3000 );
		}
	}

	window.addEventListener( 'online', function () {
		toggleOfflineBanner( false );
		requestProgressSync();
	} );

	window.addEventListener( 'offline', function () {
		toggleOfflineBanner( true );
	} );

	// Show banner immediately if already offline on page load.
	if ( ! navigator.onLine ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			toggleOfflineBanner( true );
		} );
	}

	// ------------------------------------------------------------------
	// Progress sync helpers (used by the progress Interactivity store)
	// ------------------------------------------------------------------

	/**
	 * Queue a progress completion via the Service Worker when offline.
	 *
	 * @param {Object} payload  Completion data (lessonId, courseId, etc.).
	 * @param {string} nonce    WP REST nonce for authentication.
	 */
	window.learnomyQueueProgress = function ( payload, nonce ) {
		if ( ! navigator.serviceWorker.controller ) {
			return;
		}

		navigator.serviceWorker.controller.postMessage( {
			type:    'QUEUE_PROGRESS',
			payload: payload,
			nonce:   nonce,
		} );
	};

	/**
	 * Ask the SW to flush the offline queue. Uses Background Sync API
	 * when available, otherwise sends a direct message to the SW.
	 */
	function requestProgressSync() {
		navigator.serviceWorker.ready.then( function ( registration ) {
			if ( 'sync' in registration ) {
				return registration.sync.register( 'learnomy-progress-sync' );
			}

			// Fallback: tell the SW to replay the queue directly.
			if ( navigator.serviceWorker.controller ) {
				navigator.serviceWorker.controller.postMessage( { type: 'FORCE_SYNC' } );
			}
		} );
	}

	/**
	 * Listen for sync-complete messages from the Service Worker.
	 */
	navigator.serviceWorker.addEventListener( 'message', function ( event ) {
		if ( event.data && event.data.type === 'SYNC_COMPLETE' ) {
			// Dispatch a DOM event so the Interactivity API store can react
			// (e.g. update the "Mark as Complete" button state).
			document.dispatchEvent( new CustomEvent( 'learnomy:sync-complete' ) );
		}
	} );
} )();
