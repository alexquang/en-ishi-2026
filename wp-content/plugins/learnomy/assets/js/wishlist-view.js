/**
 * Wishlist — Interactivity API store for toggling course wishlists.
 *
 * The heart button on each course card uses `data-wp-on--click="actions.toggleWishlist"`
 * and `data-wp-interactive="learnomy/wishlist"` to wire into this store.
 *
 * @package Learnomy
 */
import { store, getElement } from '@wordpress/interactivity';

// i18n label resolution strategy (in priority order):
//
//  1. PHP-injected global window.lrnWishlistI18n  — set via wp_localize_script
//     or inline <script> by Template_Loader; covers sites with a custom
//     translation loaded before this module runs.
//  2. data-i18n-add / data-i18n-remove attributes on the button itself — an
//     opt-in path for theme overrides or blocks that render the button outside
//     the canonical course-card partial.
//  3. The button's current PHP-rendered aria-label is captured before each
//     toggle so the revert path always restores the exact translated string,
//     regardless of which resolution path succeeded above.
//  4. Hard-coded English fallback for bare JS unit-test environments.
const globalI18n = ( window.lrnWishlistI18n && typeof window.lrnWishlistI18n === 'object' )
	? window.lrnWishlistI18n
	: null;

store( 'learnomy/wishlist', {
	actions: {
		*toggleWishlist() {
			const el       = getElement();
			const btn      = el.ref;
			const courseId = parseInt( btn.dataset.courseId, 10 );

			if ( ! courseId || btn.disabled ) {
				return;
			}

			const wasWishlisted = btn.dataset.wishlisted === '1';
			const method        = wasWishlisted ? 'DELETE' : 'POST';

			// Resolve translated aria-label strings for this button.
			// Per-button data attributes override the global object so a
			// theme can localise individual buttons without a PHP filter.
			const labelAdd    = btn.dataset.i18nAdd
				|| ( globalI18n && globalI18n.add )
				|| 'Add to wishlist';
			const labelRemove = btn.dataset.i18nRemove
				|| ( globalI18n && globalI18n.remove )
				|| 'Remove from wishlist';

			// Capture the PHP-rendered aria-label before any mutation so the
			// revert path restores the exact translated string.
			const originalAriaLabel = btn.getAttribute( 'aria-label' );

			// Optimistic UI update — flip the icon immediately.
			btn.dataset.wishlisted = wasWishlisted ? '0' : '1';
			btn.classList.toggle( 'lrn-wishlist-btn--active', ! wasWishlisted );
			btn.setAttribute( 'aria-pressed', wasWishlisted ? 'false' : 'true' );
			btn.setAttribute( 'aria-label', wasWishlisted ? labelAdd : labelRemove );

			btn.disabled = true;

			// Guard the apiFetch global. The module is enqueued with `wp-api-fetch`
			// as an explicit dependency, but if a theme or another plugin
			// deregisters it we fail loudly (a toast) instead of throwing an
			// uncaught error that leaves the heart silently reverted.
			const apiFetch = window.wp && window.wp.apiFetch;

			try {
				if ( ! apiFetch ) {
					throw new Error( 'wp.apiFetch unavailable' );
				}
				yield apiFetch( {
					path: '/learnomy/v1/courses/' + courseId + '/wishlist',
					method: method,
				} );

				// Keep the "My Courses" Wishlist tab in sync without a reload.
				// When a course is removed from inside that tab, the card and
				// the tab count must update immediately — a lingering card with
				// an empty heart plus a stale count otherwise reads as "the
				// course was not removed from the wishlist".
				if ( wasWishlisted ) {
					const wishlistGrid = btn.closest( '[data-courses-grid="wishlist"]' );
					if ( wishlistGrid ) {
						const card = btn.closest( '.lrn-course-card' );
						if ( card ) {
							card.remove();
						}

						const countEl = document.querySelector(
							'#lrn-tab-btn-wishlist .lrn-tab-nav__count'
						);
						if ( countEl ) {
							const current = parseInt( countEl.textContent, 10 );
							countEl.textContent = String(
								Number.isFinite( current ) ? Math.max( 0, current - 1 ) : 0
							);
						}

						// Last card gone — reveal the (pre-rendered, hidden)
						// empty state so the panel never shows a blank grid.
						if ( ! wishlistGrid.querySelector( '.lrn-course-card' ) ) {
							const panel = wishlistGrid.closest( '.lrn-tab-panel' );
							wishlistGrid.hidden = true;
							const empty = panel && panel.querySelector( '[data-wishlist-empty]' );
							if ( empty ) {
								empty.hidden = false;
							}
						}
					}
				}
			} catch ( error ) {
				// Revert optimistic update on failure — restore the exact label
				// that was present before the user clicked (strategy 3 above).
				btn.dataset.wishlisted = wasWishlisted ? '1' : '0';
				btn.classList.toggle( 'lrn-wishlist-btn--active', wasWishlisted );
				btn.setAttribute( 'aria-pressed', wasWishlisted ? 'true' : 'false' );
				btn.setAttribute( 'aria-label', originalAriaLabel );

				// Surface the failure. A silent revert reads as "the button does
				// nothing" — give the user (and QA) a reason, e.g. an expired
				// session nonce after a long-lived tab.
				if ( typeof window.lrnToast === 'function' ) {
					window.lrnToast(
						( globalI18n && globalI18n.error ) || 'Could not update your wishlist. Please refresh and try again.',
						'error'
					);
				}
			} finally {
				btn.disabled = false;
			}
		},
	},
} );
