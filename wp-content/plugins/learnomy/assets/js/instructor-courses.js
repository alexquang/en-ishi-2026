/**
 * My Courses — Prev/Next paging for the instructor dashboard widget.
 *
 * The first 25 rows render server-side. Prev/Next fetches the target page from
 * REST (GET learnomy/v1/courses/instructor-page?page=N) and REPLACES the
 * table's <tbody> — same URL, no reload — so the dashboard's other sections
 * (stats, funnel, reviews) stay put. Rows arrive as HTML rendered by the same
 * partial the widget uses; no row markup lives here. Mirrors account-courses.js.
 *
 * @package Learnomy
 */
( function () {
	'use strict';

	var cfg  = window.lrnInstructorCourses || {};
	var i18n = cfg.i18n || {};
	if ( ! cfg.restUrl ) {
		return;
	}

	var pager = document.querySelector( '[data-lrn-courses-pager]' );
	if ( ! pager ) {
		return;
	}

	var tbody  = document.querySelector( '[data-lrn-courses-tbody]' );
	var status = pager.querySelector( '[data-lrn-courses-status]' );
	var prev   = pager.querySelector( '[data-lrn-courses-prev]' );
	var next   = pager.querySelector( '[data-lrn-courses-next]' );
	if ( ! tbody || ! prev || ! next ) {
		return;
	}

	var totalPages = parseInt( pager.getAttribute( 'data-total-pages' ), 10 ) || 1;
	var busy       = false;

	function setStatus( page ) {
		if ( ! status ) {
			return;
		}
		var tpl = i18n.pageOf || 'Page %1$d of %2$d';
		status.textContent = tpl.replace( '%1$d', page ).replace( '%2$d', totalPages );
	}

	function setControls( page, hasPrev, hasNext ) {
		prev.disabled = ! hasPrev;
		next.disabled = ! hasNext;
		pager.setAttribute( 'data-page', String( page ) );
	}

	function goToPage( page ) {
		if ( busy || page < 1 || page > totalPages ) {
			return;
		}
		busy = true;
		prev.disabled = true;
		next.disabled = true;
		tbody.setAttribute( 'aria-busy', 'true' );

		var url = cfg.restUrl
			+ ( cfg.restUrl.indexOf( '?' ) === -1 ? '?' : '&' )
			+ 'page=' + encodeURIComponent( page );

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
				if ( ! data || typeof data.html !== 'string' ) {
					throw new Error( 'Malformed response' );
				}
				// data.html is server-rendered by my-courses-rows.php, which
				// escapes every field (esc_html / esc_attr / esc_url), and is
				// fetched same-origin with the REST nonce — trusted output, the
				// same trust boundary as account-courses.js. Not user input.
				tbody.innerHTML = data.html;
				setStatus( data.page );
				setControls( data.page, !! data.has_prev, !! data.has_next );
			} )
			.catch( function () {
				// Restore the controls so the user can retry; the current rows
				// stay untouched.
				var current = parseInt( pager.getAttribute( 'data-page' ), 10 ) || 1;
				setControls( current, current > 1, current < totalPages );
				if ( window.lrnToast ) {
					window.lrnToast(
						i18n.failed || 'Could not load that page. Please try again.',
						{ type: 'error' }
					);
				}
			} )
			.then( function () {
				busy = false;
				tbody.removeAttribute( 'aria-busy' );
			} );
	}

	prev.addEventListener( 'click', function () {
		goToPage( ( parseInt( pager.getAttribute( 'data-page' ), 10 ) || 1 ) - 1 );
	} );
	next.addEventListener( 'click', function () {
		goToPage( ( parseInt( pager.getAttribute( 'data-page' ), 10 ) || 1 ) + 1 );
	} );
}() );
