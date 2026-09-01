/**
 * Quiz gate behavior — cooldown countdown + access-code verification.
 *
 * Wired against the markup rendered by templates/partials/quiz-gate.php.
 * The page localises `lrnQuizGate.i18n` for translation strings.
 *
 * @package Learnomy
 */
( function () {
	'use strict';

	var _i18n = ( window.lrnQuizGate && window.lrnQuizGate.i18n ) ? window.lrnQuizGate.i18n : {};
	var _t    = function ( key, fallback ) { return _i18n[ key ] || fallback; };

	var init = function () {
		// Live countdown for cooldown state — ticks the inline span each second
		// and reloads the page when it hits zero so the gate can re-evaluate.
		var target = document.querySelector( '[data-lrn-countdown]' );
		if ( target ) {
			var secs = parseInt( target.getAttribute( 'data-seconds' ), 10 ) || 0;
			var render = function () {
				var mLabel = _t( 'minutesShort', 'm' );
				var sLabel = _t( 'secondsShort', 's' );
				if ( secs <= 0 ) {
					target.textContent = '0' + sLabel;
					window.location.reload();
					return;
				}
				var m = Math.floor( secs / 60 );
				var s = secs % 60;
				target.textContent = ( m > 0 ? m + mLabel + ' ' : '' ) + s + sLabel;
				secs--;
			};
			render();
			setInterval( render, 1000 );
		}

		// Access-code form — POST via REST /verify-code, then reload on success.
		var form = document.querySelector( '[data-lrn-quiz-gate-form]' );
		if ( form ) {
			var errorBox   = document.querySelector( '[data-lrn-gate-error]' );
			var errorText  = document.querySelector( '[data-lrn-gate-error-text]' );
			var showError  = function ( msg ) {
				if ( ! errorBox || ! errorText ) {
					return;
				}
				errorText.textContent = msg || '';
				errorBox.removeAttribute( 'hidden' );
			};

			form.addEventListener( 'submit', function ( event ) {
				event.preventDefault();
				var quizId = form.querySelector( '[name="quiz_id"]' ).value;
				var code   = form.querySelector( '[name="access_code"]' ).value;
				var btn    = form.querySelector( 'button[type="submit"]' );
				if ( btn ) {
					btn.disabled    = true;
					btn.setAttribute( 'aria-busy', 'true' );
				}

				if ( ! window.wp || ! window.wp.apiFetch ) {
					showError( _t( 'apiUnavailable', 'API not available. Reload and try again.' ) );
					if ( btn ) {
						btn.disabled = false;
						btn.removeAttribute( 'aria-busy' );
					}
					return;
				}

				window.wp.apiFetch( {
					path: '/learnomy/v1/quizzes/' + quizId + '/verify-code',
					method: 'POST',
					data: { access_code: code },
				} ).then( function ( res ) {
					if ( res && res.data && res.data.verified ) {
						window.location.reload();
					} else {
						if ( btn ) {
							btn.disabled = false;
							btn.removeAttribute( 'aria-busy' );
						}
						showError( _t( 'incorrectCode', 'Incorrect access code.' ) );
					}
				} ).catch( function ( err ) {
					if ( btn ) {
						btn.disabled = false;
						btn.removeAttribute( 'aria-busy' );
					}
					showError( ( err && err.message ) ? err.message : _t( 'incorrectCode', 'Incorrect access code.' ) );
				} );
			} );
		}

	};

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
