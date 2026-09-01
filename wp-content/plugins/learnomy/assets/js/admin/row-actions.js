/**
 * Row-actions overflow menu — the shared admin "family" behaviour.
 *
 * Markup comes from \Learnomy\Admin\UX::row_actions():
 *   .lrn-row-actions__menu > .lrn-row-actions__trigger + .lrn-row-actions__panel[hidden]
 *
 * One delegated listener drives every menu on the page: click the ⋮ trigger to
 * toggle its panel, click outside / press Escape to close, and only one menu is
 * open at a time. The action items inside keep their own data-* / class hooks,
 * so existing per-page handlers (delete, edit, cancel-sub, etc.) still fire.
 *
 * @package Learnomy
 */
( function () {
	'use strict';

	function closeAll( except ) {
		document.querySelectorAll( '.lrn-row-actions__menu .lrn-row-actions__trigger[aria-expanded="true"]' ).forEach( function ( trigger ) {
			var panel = trigger.parentNode.querySelector( '.lrn-row-actions__panel' );
			if ( panel === except ) {
				return;
			}
			trigger.setAttribute( 'aria-expanded', 'false' );
			if ( panel ) {
				panel.hidden = true;
			}
		} );
	}

	document.addEventListener( 'click', function ( e ) {
		var trigger = e.target.closest( '.lrn-row-actions__trigger' );

		if ( trigger ) {
			e.preventDefault();
			var panel = trigger.parentNode.querySelector( '.lrn-row-actions__panel' );
			var open  = 'true' === trigger.getAttribute( 'aria-expanded' );
			closeAll( panel );
			trigger.setAttribute( 'aria-expanded', open ? 'false' : 'true' );
			if ( panel ) {
				panel.hidden = open;
				if ( ! open ) {
					// Flip upward when opening downward would clip the panel
					// against the bottom of the scrolling table wrap (the wrap
					// is overflow:auto, which clips absolute descendants) —
					// but only when there is actually room above the trigger.
					trigger.parentNode.classList.remove( 'lrn-row-actions__menu--up' );
					var wrap = trigger.closest( '.lrn-admin-table-wrap' );
					if ( wrap ) {
						var pr = panel.getBoundingClientRect();
						var wr = wrap.getBoundingClientRect();
						var tr = trigger.getBoundingClientRect();
						if ( pr.bottom > wr.bottom && ( tr.top - wr.top ) > pr.height + 8 ) {
							trigger.parentNode.classList.add( 'lrn-row-actions__menu--up' );
						}
					}
					var first = panel.querySelector( '.lrn-row-actions__item' );
					if ( first ) {
						first.focus();
					}
				}
			}
			return;
		}

		// A click on a menu item runs its own handler; close the menu after.
		if ( e.target.closest( '.lrn-row-actions__item' ) ) {
			closeAll( null );
			return;
		}

		// Click anywhere else closes any open menu.
		if ( ! e.target.closest( '.lrn-row-actions__panel' ) ) {
			closeAll( null );
		}
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key ) {
			var open = document.querySelector( '.lrn-row-actions__trigger[aria-expanded="true"]' );
			if ( open ) {
				closeAll( null );
				open.focus();
			}
		}
	} );
}() );
