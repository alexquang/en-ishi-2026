/**
 * Question Review filter chips — All / Incorrect / Correct / Pending.
 *
 * One delegated listener serves BOTH review surfaces: the Interactivity
 * results screen (templates/quiz/*.php) and the server-rendered account
 * review (partials/quiz-attempt-review.php). The chip flips a
 * `data-review-filter` attribute on the `.lrn-quiz__review` container and
 * quiz-player.css does the actual hiding off the state classes the cards
 * already carry — no store round-trip, no per-surface fork.
 *
 * Card expand/collapse needs no JS at all: the cards are native <details>.
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( event ) {
		var btn = event.target.closest( '.lrn-quiz__review-filter' );
		if ( ! btn ) {
			return;
		}

		var bar    = btn.closest( '.lrn-quiz__review-filters' );
		var review = btn.closest( '.lrn-quiz__review' );
		if ( ! bar || ! review ) {
			return;
		}

		review.setAttribute( 'data-review-filter', btn.getAttribute( 'data-review-filter' ) || 'all' );

		bar.querySelectorAll( '.lrn-quiz__review-filter' ).forEach( function ( chip ) {
			chip.setAttribute( 'aria-pressed', chip === btn ? 'true' : 'false' );
		} );
	} );
} )();
