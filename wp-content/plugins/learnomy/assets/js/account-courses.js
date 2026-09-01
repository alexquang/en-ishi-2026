/**
 * My Courses — "Load more" paging for the /account/courses/ section.
 *
 * Each tab (All / In Progress / Completed / Wishlist) renders its first 40
 * cards server-side. Clicking "Load more" fetches the next page of rendered
 * cards from REST (GET learnomy/v1/enrollments/page?tab=&offset=) and appends
 * them to that tab's grid — same URL, no slug change — so a learner with
 * hundreds of courses can reach all of them on one page. Cards arrive as HTML
 * rendered by the same partial the page uses; no card markup lives in JS.
 *
 * @package Learnomy
 */
( function () {
	'use strict';

	var cfg  = window.lrnAccountCourses || {};
	var i18n = cfg.i18n || {};
	if ( ! cfg.restUrl ) {
		return;
	}

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.lrn-load-more' );
		if ( ! btn || btn.disabled ) {
			return;
		}

		var tab    = btn.getAttribute( 'data-tab' ) || 'all';
		var offset = parseInt( btn.getAttribute( 'data-offset' ), 10 ) || 0;
		var grid   = document.querySelector( '[data-courses-grid="' + tab + '"]' );
		if ( ! grid ) {
			return;
		}

		var original  = btn.textContent;
		btn.disabled  = true;
		btn.textContent = i18n.loading || 'Loading…';

		var url = cfg.restUrl
			+ ( cfg.restUrl.indexOf( '?' ) === -1 ? '?' : '&' )
			+ 'tab=' + encodeURIComponent( tab )
			+ '&offset=' + encodeURIComponent( offset );

		fetch( url, {
			headers: { 'X-WP-Nonce': cfg.nonce || '' },
			credentials: 'same-origin'
		} )
			.then( function ( r ) {
				if ( ! r.ok ) {
					throw new Error( 'HTTP ' + r.status );
				}
				return r.json();
			} )
			.then( function ( res ) {
				var data = ( res && res.data ) ? res.data : res;
				if ( data && data.html ) {
					grid.insertAdjacentHTML( 'beforeend', data.html );
				}

				if ( data && data.has_more ) {
					btn.setAttribute( 'data-offset', String( data.offset ) );
					btn.disabled    = false;
					btn.textContent = original;
				} else {
					// Reached the end — drop the button.
					var wrap = btn.closest( '.lrn-load-more-wrap' );
					( wrap || btn ).remove();
				}
			} )
			.catch( function () {
				btn.disabled    = false;
				btn.textContent = original;
				if ( window.lrnToast ) {
					window.lrnToast( i18n.failed || 'Could not load more courses. Please try again.', 'error' );
				}
			} );
	} );
}() );
