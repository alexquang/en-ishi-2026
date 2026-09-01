/**
 * Admin grading detail — inline score + feedback save.
 *
 * Persists through the existing Free REST route
 * `POST learnomy/v1/attempts/{id}/grade` (Quizzes_Controller::grade_attempt →
 * Quiz_Service::grade_question). When the last pending response on the attempt
 * is graded the backend finalizes the attempt; this script then reveals the
 * "finalized" banner.
 *
 * @package Learnomy
 */
( function () {
	'use strict';

	var admin     = window.learnomyAdmin || {};
	var restUrl   = admin.restUrl || '/wp-json/learnomy/v1/';
	var restNonce = admin.restNonce || '';

	var wrap = document.querySelector( '[data-grading-attempt]' );
	if ( ! wrap ) {
		return;
	}

	var attemptId = parseInt( wrap.getAttribute( 'data-grading-attempt' ), 10 );

	function toast( message, type ) {
		if ( window.lrnToast ) {
			window.lrnToast( message, type || 'success' );
		}
	}

	function pendingCards() {
		return wrap.querySelectorAll( '.lrn-grading-card:not(.is-graded)' );
	}


	async function saveGrade( card, button ) {
		var responseId = parseInt( card.getAttribute( 'data-response-id' ), 10 );
		var max        = parseFloat( card.getAttribute( 'data-max' ) ) || 0;
		var scoreInput = card.querySelector( '[data-grade-score]' );
		var feedback   = card.querySelector( '[data-grade-feedback]' );

		var raw = scoreInput ? scoreInput.value.trim() : '';
		if ( '' === raw ) {
			toast( wp.i18n.__( 'Enter a score first.', 'learnomy' ), 'danger' );
			scoreInput && scoreInput.focus();
			return;
		}

		var score = parseFloat( raw );
		if ( isNaN( score ) || score < 0 || ( max > 0 && score > max ) ) {
			toast(
				wp.i18n.sprintf(
					/* translators: %s: maximum marks */
					wp.i18n.__( 'Score must be between 0 and %s.', 'learnomy' ),
					String( max )
				),
				'danger'
			);
			scoreInput && scoreInput.focus();
			return;
		}

		var original     = button.textContent;
		button.disabled  = true;
		button.textContent = wp.i18n.__( 'Saving…', 'learnomy' );

		try {
			var res = await fetch( restUrl + 'attempts/' + attemptId + '/grade', {
				method:      'POST',
				credentials: 'same-origin',
				headers:     {
					'Content-Type': 'application/json',
					'X-WP-Nonce':   restNonce
				},
				body: JSON.stringify( {
					response_id: responseId,
					score:       score,
					feedback:    feedback ? feedback.value : ''
				} )
			} );

			if ( ! res.ok ) {
				throw new Error( 'HTTP ' + res.status );
			}

			markGraded( card, score, max, feedback ? feedback.value : '' );
			toast( wp.i18n.__( 'Grade saved.', 'learnomy' ), 'success' );

			if ( 0 === pendingCards().length ) {
				var banner = wrap.querySelector( '[data-grading-done]' );
				if ( banner ) {
					banner.hidden = false;
				}
			}
		} catch ( err ) {
			button.disabled    = false;
			button.textContent = original;
			toast( wp.i18n.__( 'Could not save the grade. Please try again.', 'learnomy' ), 'danger' );
		}
	}

	function markGraded( card, score, max, feedbackText ) {
		card.classList.add( 'is-graded' );

		var form = card.querySelector( '[data-grade-form]' );
		if ( ! form ) {
			return;
		}

		var result = document.createElement( 'div' );
		result.className = 'lrn-grading-card__result';

		var scoreLine = document.createElement( 'p' );
		var label     = document.createElement( 'strong' );
		label.textContent = wp.i18n.__( 'Score', 'learnomy' ) + ': ';
		scoreLine.appendChild( label );
		scoreLine.appendChild(
			document.createTextNode( String( score ) + ' / ' + String( max ) )
		);
		result.appendChild( scoreLine );

		if ( feedbackText && feedbackText.trim() ) {
			var fbLine = document.createElement( 'p' );
			var fbLbl  = document.createElement( 'strong' );
			fbLbl.textContent = wp.i18n.__( 'Feedback', 'learnomy' ) + ': ';
			fbLine.appendChild( fbLbl );
			fbLine.appendChild( document.createTextNode( feedbackText.trim() ) );
			result.appendChild( fbLine );
		}

		form.replaceWith( result );

		// Update the per-card status badge from "Needs grading" to "Graded".
		var badge = card.querySelector( '.lrn-status-badge' );
		if ( badge ) {
			badge.className   = 'lrn-status-badge lrn-status-badge--active';
			badge.textContent = wp.i18n.__( 'Graded', 'learnomy' );
		}

	}

	wrap.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-grade-save]' );
		if ( ! button ) {
			return;
		}
		var card = button.closest( '.lrn-grading-card' );
		if ( card ) {
			saveGrade( card, button );
		}
	} );
}() );
