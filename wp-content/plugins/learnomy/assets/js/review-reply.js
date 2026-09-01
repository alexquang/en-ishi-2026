/**
 * Instructor review reply — PATCHes a public response onto a review via
 * PATCH /learnomy/v1/reviews/<id> { reply }.
 *
 * Delegated submit handler so it covers every reply form on the page
 * (one per review card) without binding each individually.
 *
 * @package Learnomy
 */
( function () {
	'use strict';

	var config = window.lrnReviewReply || {};

	document.addEventListener( 'submit', function ( e ) {
		var form = e.target;
		if ( ! form.classList || ! form.classList.contains( 'lrn-review-reply-form' ) ) {
			return;
		}
		e.preventDefault();

		var reviewId = parseInt( form.getAttribute( 'data-review-id' ), 10 );
		var input    = form.querySelector( '[name="reply"]' );
		var alertBox = form.querySelector( '.lrn-review-reply-form__alert' );
		var submit   = form.querySelector( '.lrn-review-reply-form__submit' );
		var reply    = ( input && input.value || '' ).trim();

		function showError( msg ) {
			if ( ! alertBox ) { return; }
			alertBox.textContent = msg;
			alertBox.hidden = false;
		}

		if ( ! reply ) {
			showError( ( config.i18n && config.i18n.empty ) || 'Write a response before posting.' );
			return;
		}
		// Mirrors Review::MAX_REPLY_LENGTH — `maxlength` does not survive a
		// paste on every browser, and the REST schema rejects regardless.
		var maxReply = parseInt( config.maxReply, 10 ) || 5000;
		if ( reply.length > maxReply ) {
			showError(
				String(
					( config.i18n && config.i18n.tooLong ) || 'Your response is too long. Please keep it under %s characters.'
				).replace( '%s', String( maxReply ) )
			);
			return;
		}
		if ( alertBox ) { alertBox.hidden = true; }

		submit.disabled = true;
		var originalText = submit.textContent;
		submit.textContent = ( config.i18n && config.i18n.posting ) || 'Posting…';

		// wp.apiFetch handles nonce injection, nonce refresh, REST root
		// detection, and error normalisation automatically — the previous
		// raw fetch() bypassed all of that.
		wp.apiFetch( {
			path: '/learnomy/v1/reviews/' + reviewId,
			method: 'PATCH',
			data: { reply: reply },
		} )
			.then( function () {
				renderPosted( form, reply );
				if ( window.lrnToast ) {
					window.lrnToast( ( config.i18n && config.i18n.posted ) || 'Response posted.', 'success' );
				}
			} )
			.catch( function ( err ) {
				var msg = ( err && err.message )
					? err.message
					: ( config.i18n && config.i18n.failed ) || 'Could not post your response. Please try again.';
				showError( msg );
				submit.disabled = false;
				submit.textContent = originalText;
			} );
	} );

	function makeEl( tag, className, text ) {
		var el = document.createElement( tag );
		if ( className ) { el.className = className; }
		if ( null !== text && undefined !== text ) { el.textContent = text; }
		return el;
	}

	// Swap the form for the read-only "Instructor response" block so the
	// poster sees their reply acknowledged without a reload — same shape the
	// server renders for an existing reply.
	function renderPosted( form, reply ) {
		var wrap  = makeEl( 'div', 'lrn-review-card__reply' );
		var label = makeEl( 'span', 'lrn-review-card__reply-label', ( window.lrnReviewReply && window.lrnReviewReply.i18n && window.lrnReviewReply.i18n.label ) || 'Instructor response' );
		wrap.appendChild( label );
		wrap.appendChild( makeEl( 'p', 'lrn-review-card__reply-body', reply ) );
		form.parentNode.replaceChild( wrap, form );
	}
} )();
