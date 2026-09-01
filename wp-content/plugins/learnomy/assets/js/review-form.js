/**
 * Review form — submit a star rating + comment to the existing
 * POST /learnomy/v1/courses/<id>/reviews route.
 *
 * Vanilla JS (matches register.js pattern) — no Interactivity API store
 * is needed for a single-step request/response.
 *
 * @package Learnomy
 */
( function () {
	'use strict';

	var form = document.getElementById( 'lrn-review-form' );
	if ( ! form ) {
		return;
	}

	var config   = window.lrnReviewForm || {};
	var submit   = document.getElementById( 'lrn-review-form-submit' );
	var errorBox = document.getElementById( 'lrn-review-form-error' );

	// Mirrors Review::MAX_CONTENT_LENGTH, seeded by the template loader. The
	// fallback only matters if the localized object failed to load; the server
	// rejects over-length input either way, so the client is a courtesy, never
	// the enforcement.
	var maxContent  = parseInt( config.maxContent, 10 ) || 5000;
	var contentEl   = form.querySelector( '[name="content"]' );
	var counterEl   = document.getElementById( 'lrn-review-content-counter' );

	if ( contentEl && counterEl ) {
		contentEl.addEventListener( 'input', updateCounter );
		updateCounter();
	}

	function updateCounter() {
		var left = maxContent - contentEl.value.length;
		if ( left < 0 ) {
			left = 0;
		}
		counterEl.textContent = sprintf(
			( config.i18n && config.i18n.charsLeft ) || '%s characters left',
			String( left )
		);
		counterEl.classList.toggle( 'lrn-form-hint--warn', left <= 100 );
	}

	// Minimal %s substitution — the strings come from wp_localize_script, which
	// delivers them already translated, so no wp.i18n round trip is needed.
	function sprintf( template, value ) {
		return String( template ).replace( '%s', value );
	}

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		clearError();

		var ratingInput = form.querySelector( 'input[name="rating"]:checked' );
		var content     = ( form.querySelector( '[name="content"]' ).value || '' ).trim();
		var title       = ( form.querySelector( '[name="title"]' ).value || '' ).trim();

		if ( ! ratingInput ) {
			showError( ( config.i18n && config.i18n.pickRating ) || 'Pick a star rating before submitting.' );
			return;
		}
		if ( ! content ) {
			showError( ( config.i18n && config.i18n.needContent ) || 'Write a short review so others can learn from your experience.' );
			return;
		}
		// `maxlength` blocks typing past the cap but not a paste on every
		// browser, and it does nothing at all for a scripted submit.
		if ( content.length > maxContent ) {
			showError(
				sprintf(
					( config.i18n && config.i18n.tooLong ) || 'Your review is too long. Please keep it under %s characters.',
					String( maxContent )
				)
			);
			return;
		}

		submit.disabled = true;
		var originalText = submit.textContent;
		submit.textContent = ( config.i18n && config.i18n.posting ) || 'Posting…';

		var courseId = parseInt( form.getAttribute( 'data-course-id' ), 10 );

		// wp.apiFetch handles nonce injection, nonce refresh, REST root
		// detection, and error normalisation automatically — the previous
		// raw fetch() bypassed all of that.
		wp.apiFetch( {
			path: '/learnomy/v1/courses/' + courseId + '/reviews',
			method: 'POST',
			data: {
				course_id: courseId,
				rating: parseInt( ratingInput.value, 10 ),
				title: title,
				content: content,
			},
		} )
			.then( function ( res ) {
				// wp.apiFetch resolves with the parsed response body on 2xx.
				// The SDK envelope wraps the resource in { data: ... }.
				var posted = res && res.data ? res.data : { rating: ratingInput.value, title: title, content: content };

				// Optimistic UI — swap the form for a thank-you card so the
				// student sees their input acknowledged without a reload.
				renderPosted( posted );

				if ( window.lrnToast ) {
					window.lrnToast(
						( config.i18n && config.i18n.posted ) || 'Review posted. Thanks for sharing.',
						{ type: 'success' }
					);
				}
			} )
			.catch( function ( err ) {
				var msg = ( err && err.message )
					? err.message
					: ( config.i18n && config.i18n.failed ) || 'Could not post your review. Please try again.';
				showError( msg );
			} )
			.finally( function () {
				submit.disabled = false;
				submit.textContent = originalText;
			} );
	} );

	function showError( msg ) {
		errorBox.textContent = msg;
		errorBox.hidden = false;
	}
	function clearError() {
		errorBox.textContent = '';
		errorBox.hidden = true;
	}

	function makeEl( tag, className, text ) {
		var el = document.createElement( tag );
		if ( className ) {
			el.className = className;
		}
		if ( null !== text && undefined !== text ) {
			el.textContent = text;
		}
		return el;
	}

	function renderPosted( review ) {
		var rating = parseInt( review.rating, 10 );
		if ( isNaN( rating ) || rating < 1 ) { rating = 1; }
		if ( rating > 5 ) { rating = 5; }

		var card = makeEl( 'div', 'lrn-review-form lrn-review-form--posted lrn-card' );

		var head = makeEl( 'div', 'lrn-review-form__posted-head' );
		head.appendChild( makeEl( 'strong', null, ( config.i18n && config.i18n.thanks ) || 'Thanks for reviewing this course.' ) );
		card.appendChild( head );

		var starsWrap = makeEl( 'div', 'lrn-review-form__posted-stars' );
		for ( var i = 1; i <= 5; i++ ) {
			var star = makeEl( 'span', 'lrn-star ' + ( i <= rating ? 'lrn-star--filled' : 'lrn-star--empty' ), '★' );
			star.setAttribute( 'aria-hidden', 'true' );
			starsWrap.appendChild( star );
		}
		card.appendChild( starsWrap );

		if ( review.title ) {
			card.appendChild( makeEl( 'h4', 'lrn-review-form__posted-title', review.title ) );
		}
		if ( review.content ) {
			card.appendChild( makeEl( 'p', 'lrn-review-form__posted-body', review.content ) );
		}

		form.parentNode.replaceChild( card, form );
	}
} )();
