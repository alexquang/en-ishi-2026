/**
 * Single course — "Load more" paging for the Student Reviews section.
 *
 * The page renders only the first page of approved reviews server-side; the
 * displayed count is the true total, so on a course with more reviews this
 * button fetches the next page of rendered cards from REST
 * (GET learnomy/v1/courses/{id}/reviews/page?offset=) and appends them to the
 * list. Cards arrive as HTML rendered by the same review-card partial the page
 * uses, so no card markup lives in JS (it can't drift from the server view).
 *
 * @package Learnomy
 */
( function () {
	'use strict';

	var cfg  = window.lrnCourseReviews || {};
	var i18n = cfg.i18n || {};
	if ( ! cfg.restRoot ) {
		return;
	}

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.lrn-reviews-load-more' );
		if ( ! btn || btn.disabled ) {
			return;
		}

		var courseId = btn.getAttribute( 'data-course-id' );
		var list     = document.querySelector( '.lrn-reviews-list[data-course-id="' + courseId + '"]' );
		if ( ! courseId || ! list ) {
			return;
		}

		var offset      = parseInt( list.getAttribute( 'data-offset' ), 10 ) || 0;
		var original    = btn.innerHTML;
		btn.disabled    = true;
		btn.textContent = i18n.loading || 'Loading…';

		var url = cfg.restRoot
			+ encodeURIComponent( courseId )
			+ '/reviews/page?offset=' + encodeURIComponent( offset );

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
					list.insertAdjacentHTML( 'beforeend', data.html );
				}

				if ( data && typeof data.offset !== 'undefined' ) {
					list.setAttribute( 'data-offset', String( data.offset ) );
				}

				if ( data && data.has_more ) {
					btn.disabled  = false;
					btn.innerHTML = original;
				} else {
					// Reached the end — drop the button (and its wrapper).
					var wrap = btn.closest( '.lrn-reviews-load-more-wrap' );
					( wrap || btn ).remove();
				}
			} )
			.catch( function () {
				btn.disabled  = false;
				btn.innerHTML = original;
				if ( window.lrnToast ) {
					window.lrnToast( i18n.failed || 'Could not load more reviews. Please try again.', 'error' );
				}
			} );
	} );
}() );
