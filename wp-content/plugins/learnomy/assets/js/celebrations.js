/**
 * Celebrations — fires confetti when a student completes a course.
 *
 * Reads a data-celebrate attribute injected by the student dashboard
 * template when a ?completed=1 query param is present. Uses sessionStorage
 * to prevent re-firing on page refresh.
 *
 * @package Learnomy
 */
( function() {
	'use strict';

	var trigger = document.querySelector( '[data-celebrate]' );
	if ( ! trigger || typeof confetti === 'undefined' ) {
		return;
	}

	var key = 'lrn_celebrated_' + trigger.getAttribute( 'data-celebrate' );

	// Only fire once per session — prevent re-fire on refresh.
	if ( sessionStorage.getItem( key ) ) {
		return;
	}

	sessionStorage.setItem( key, '1' );

	// Short delay so the page is visually settled before confetti fires.
	setTimeout( function() {
		confetti( {
			particleCount: 100,
			spread:        70,
			origin:        { y: 0.6 }
		} );
	}, 400 );
} )();
