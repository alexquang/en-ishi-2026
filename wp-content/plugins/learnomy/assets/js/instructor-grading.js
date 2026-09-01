/**
 * Instructor grading — inline score + feedback save on /instructor/grading/.
 *
 * Persists through the SAME Free REST route the wp-admin grader and the mobile
 * app use — `POST learnomy/v1/attempts/{id}/grade` (Quizzes_Controller::
 * grade_attempt -> Quiz_Service::grade_question). There is no front-end-only
 * endpoint: a second write path is how two surfaces end up saving different
 * things.
 *
 * On success the card flips to its read-only result in place. When the last
 * pending response on the attempt is graded the backend finalizes the attempt,
 * and the "all graded" notice appears. A failed save leaves the form exactly as
 * the grader typed it, so nothing they wrote is lost to a network blip.
 *
 * @package Learnomy
 */
( function () {
	'use strict';

	var cfg     = window.lrnGrading || {};
	var restUrl = cfg.restUrl || '/wp-json/learnomy/v1/';
	var nonce   = cfg.nonce || '';
	var i18n    = cfg.i18n || {};

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

	/** Flip one card from "needs grading" to its saved result, without a reload. */
	function markGraded( card, score, feedbackText ) {
		card.classList.add( 'is-graded' );

		var form = card.querySelector( '[data-grade-form]' );
		if ( form ) {
			form.hidden = true;
		}

		var result = card.querySelector( '[data-grade-result]' );
		if ( result ) {
			var scoreOut = result.querySelector( '[data-grade-score-out]' );
			if ( scoreOut ) {
				scoreOut.textContent = String( score );
			}

			var line = result.querySelector( '[data-grade-feedback-line]' );
			var out  = result.querySelector( '[data-grade-feedback-out]' );
			var text = ( feedbackText || '' ).trim();
			if ( line && out ) {
				out.textContent = text;
				line.hidden     = '' === text;
			}

			result.hidden = false;
		}

		var badge = card.querySelector( '[data-grade-badge]' );
		if ( badge ) {
			badge.classList.add( 'lrn-badge--green' );
			badge.textContent = i18n.graded || 'Graded';
		}

	}

	async function saveGrade( card, button ) {
		var responseId = parseInt( card.getAttribute( 'data-response-id' ), 10 );
		var max        = parseFloat( card.getAttribute( 'data-max' ) ) || 0;
		var scoreInput = card.querySelector( '[data-grade-score]' );
		var feedback   = card.querySelector( '[data-grade-feedback]' );

		var raw = scoreInput ? scoreInput.value.trim() : '';
		if ( '' === raw ) {
			toast( i18n.needScore || 'Enter a score first.', 'danger' );
			if ( scoreInput ) {
				scoreInput.focus();
			}
			return;
		}

		// The response row carries its own max_score, so the bound is real and
		// the server clamps to the same number. Checking here keeps the grader
		// from a pointless round trip, never from a correct grade.
		var score = parseFloat( raw );
		if ( isNaN( score ) || score < 0 || ( max > 0 && score > max ) ) {
			toast(
				( i18n.scoreRange || 'Score must be between 0 and %s.' ).replace( '%s', String( max ) ),
				'danger'
			);
			if ( scoreInput ) {
				scoreInput.focus();
			}
			return;
		}

		var original       = button.textContent;
		button.disabled    = true;
		button.textContent = i18n.saving || 'Saving…';

		try {
			var res = await fetch( restUrl + 'attempts/' + attemptId + '/grade', {
				method:      'POST',
				credentials: 'same-origin',
				headers:     {
					'Content-Type': 'application/json',
					'X-WP-Nonce':   nonce
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

			markGraded( card, score, feedback ? feedback.value : '' );
			toast( i18n.saved || 'Grade saved.', 'success' );

			if ( 0 === pendingCards().length ) {
				var notice = wrap.querySelector( '[data-grading-done]' );
				if ( notice ) {
					notice.hidden = false;
				}
			}
		} catch ( err ) {
			button.disabled    = false;
			button.textContent = original;
			toast( i18n.failed || 'Could not save the grade. Please try again.', 'danger' );
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
