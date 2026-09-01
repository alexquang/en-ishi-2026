/**
 * Registration form — client-side validation and REST API submission.
 *
 * Vanilla JS (no Interactivity API) — registration forms are simple
 * request/response cycles, not reactive UI.
 *
 * @package Learnomy
 */
( function () {
	'use strict';

	var __ = ( window.wp && window.wp.i18n && window.wp.i18n.__ )
		? window.wp.i18n.__
		: function ( s ) { return s; };

	var form      = document.getElementById( 'lrn-register-form' );
	var submitBtn = document.getElementById( 'lrn-register-submit' );
	var errorBox  = document.getElementById( 'lrn-register-error' );
	var successBox = document.getElementById( 'lrn-register-success' );

	if ( ! form || ! submitBtn ) {
		return;
	}

	var config = window.learnomyRegister || {};

	// -------------------------------------------------------------------------
	// Validation rules
	// -------------------------------------------------------------------------

	var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

	function validateField( input ) {
		var name  = input.name;
		var value = input.value.trim();
		var error = '';

		switch ( name ) {
			case 'first_name':
			case 'last_name':
				if ( ! value ) {
					error = __( 'This field is required.', 'learnomy' );
				} else if ( value.length > 100 ) {
					// Mirrors Auth_Controller::MAX_NAME_LENGTH.
					error = __( 'Please keep this under 100 characters.', 'learnomy' );
				}
				break;

			case 'accept_terms':
				// A checkbox carries value="1" whether or not it is ticked, so
				// the shared `value` above says nothing here — read `checked`.
				if ( input.hasAttribute( 'required' ) && ! input.checked ) {
					error = __( 'Please accept the terms before creating your account.', 'learnomy' );
				}
				break;

			case 'email':
				if ( ! value ) {
					error = __( 'Email is required.', 'learnomy' );
				} else if ( ! EMAIL_RE.test( value ) ) {
					error = __( 'Please enter a valid email address.', 'learnomy' );
				}
				break;

			case 'username':
				if ( ! value ) {
					error = __( 'Username is required.', 'learnomy' );
				} else if ( value.length < 3 ) {
					error = __( 'Username must be at least 3 characters.', 'learnomy' );
				} else if ( /[^a-zA-Z0-9_\-.]/.test( value ) ) {
					error = __( 'Username can only contain letters, numbers, hyphens, underscores, and dots.', 'learnomy' );
				}
				break;

			case 'password':
				if ( ! value ) {
					error = __( 'Password is required.', 'learnomy' );
				} else if ( value.length < 8 ) {
					error = __( 'Password must be at least 8 characters.', 'learnomy' );
				}
				break;

			case 'password_confirm':
				var password = form.querySelector( '[name="password"]' );
				if ( ! value ) {
					error = __( 'Please confirm your password.', 'learnomy' );
				} else if ( password && value !== password.value ) {
					error = __( 'Passwords do not match.', 'learnomy' );
				}
				break;

			case 'bio':
			case 'course_idea':
				if ( input.hasAttribute( 'required' ) && ! value ) {
					error = __( 'This field is required.', 'learnomy' );
				}
				break;

			// The paypal_email payout-field validation was removed 2026-07-12
			// (seam P1-5/V3) with the payout fields themselves.

			// Fields contributed by a plugin through learnomy_account_fields are
			// not in this switch, and the form is `novalidate`, so nothing was
			// checking their `required` attribute — an author could mark a field
			// required and watch the form submit straight past it. The server is
			// still the authority (learnomy_account_fields_validate); this only
			// saves the round trip.
			default:
				if ( input.hasAttribute( 'required' ) ) {
					var isCheckable = input.type === 'checkbox' || input.type === 'radio';
					var missing     = isCheckable ? ! input.checked : ! value;
					if ( missing ) {
						error = __( 'This field is required.', 'learnomy' );
					}
				}
				break;
		}

		showFieldError( name, error );
		return error === '';
	}

	function showFieldError( fieldName, message ) {
		var errorEl = form.querySelector( '[data-field="' + fieldName + '"]' );
		var input   = form.querySelector( '[name="' + fieldName + '"]' );

		// The form's own fields ship an empty error span; a field contributed
		// through learnomy_account_fields has no reason to know that convention,
		// so it used to be refused with nowhere to say why - the submit stopped
		// and the page looked like it had ignored the click. Make the node on
		// demand instead of asking every consumer to remember it.
		if ( ! errorEl && input ) {
			errorEl = document.createElement( 'span' );
			errorEl.className = 'lrn-form-error';
			errorEl.setAttribute( 'data-field', fieldName );
			errorEl.hidden = true;
			input.insertAdjacentElement( 'afterend', errorEl );
		}

		if ( ! errorEl ) {
			return;
		}

		if ( message ) {
			errorEl.textContent = message;
			errorEl.hidden = false;
			if ( input ) {
				input.classList.add( 'lrn-input--error' );
				input.setAttribute( 'aria-invalid', 'true' );
			}
		} else {
			errorEl.textContent = '';
			errorEl.hidden = true;
			if ( input ) {
				input.classList.remove( 'lrn-input--error' );
				input.removeAttribute( 'aria-invalid' );
			}
		}
	}

	function validateAll() {
		var inputs = form.querySelectorAll( 'input:not([type="hidden"]), textarea' );
		var valid  = true;

		for ( var i = 0; i < inputs.length; i++ ) {
			if ( ! validateField( inputs[ i ] ) ) {
				valid = false;
			}
		}

		return valid;
	}

	function clearErrors() {
		var errors = form.querySelectorAll( '.lrn-form-error' );
		for ( var i = 0; i < errors.length; i++ ) {
			errors[ i ].textContent = '';
			errors[ i ].hidden = true;
		}

		var inputs = form.querySelectorAll( '.lrn-input--error' );
		for ( var j = 0; j < inputs.length; j++ ) {
			inputs[ j ].classList.remove( 'lrn-input--error' );
			inputs[ j ].removeAttribute( 'aria-invalid' );
		}
	}

	// -------------------------------------------------------------------------
	// Inline validation on blur
	// -------------------------------------------------------------------------

	form.addEventListener( 'blur', function ( e ) {
		var target = e.target;
		if ( target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' ) {
			validateField( target );
		}
	}, true );

	// -------------------------------------------------------------------------
	// Pick-and-add lists
	// -------------------------------------------------------------------------
	//
	// A generic behaviour: a consumer renders a plain <select> carrying
	// `data-lrn-multi-add="<ul id>"`, and choosing an option moves it into that
	// list as a removable item backed by a hidden input.
	//
	// Deliberately not a select[multiple]. That control silently clears every
	// existing choice when an option is clicked without Ctrl held, which on a
	// sign-up form loses what somebody already picked and tells them nothing.
	// Here a pick is a visible row and removing one is its own button.

	form.querySelectorAll( '[data-lrn-multi-add]' ).forEach( function ( select ) {
		var chosen = document.getElementById( select.getAttribute( 'data-lrn-multi-add' ) );
		if ( ! chosen ) {
			return;
		}
		var fieldName   = select.getAttribute( 'data-lrn-field-name' ) || 'choices[]';
		var removeLabel = chosen.getAttribute( 'data-lrn-remove-label' ) || 'Remove';

		select.addEventListener( 'change', function () {
			var value = select.value;
			if ( ! value ) {
				return;
			}
			var option = select.querySelector( 'option[value="' + value + '"]' );
			var label  = option ? option.textContent : value;

			var li = document.createElement( 'li' );
			li.className = 'lrn-signup-spaces__chip';

			var text = document.createElement( 'span' );
			text.textContent = label;

			var field = document.createElement( 'input' );
			field.type  = 'hidden';
			field.name  = fieldName;
			field.value = value;

			var remove = document.createElement( 'button' );
			remove.type      = 'button';
			remove.className = 'lrn-signup-spaces__remove';
			// Names the thing being removed, so the control is not just "Remove"
			// repeated down the list to anyone using a screen reader.
			remove.setAttribute( 'aria-label', removeLabel + ': ' + label );
			remove.textContent = '×';

			remove.addEventListener( 'click', function () {
				li.remove();
				// Put it back where it came from so it can be picked again.
				if ( option ) {
					select.appendChild( option );
				}
				select.focus();
			} );

			li.appendChild( text );
			li.appendChild( field );
			li.appendChild( remove );
			chosen.appendChild( li );

			// Taking it out of the select is what stops the same organisation
			// being added twice, without needing a duplicate check anywhere.
			if ( option ) {
				option.remove();
			}
			select.value = '';
		} );
	} );

	// -------------------------------------------------------------------------
	// Form submission
	// -------------------------------------------------------------------------

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();

		clearErrors();

		if ( ! validateAll() ) {
			// Focus the first field with an error.
			var firstError = form.querySelector( '.lrn-input--error' );
			if ( firstError ) {
				firstError.focus();
			}
			return;
		}

		// Collect form data.
		//
		// A `name="thing[]"` field appears once per checked box, and assigning
		// straight into the object kept only the LAST one — so a multi-select
		// rendered through learnomy_account_fields looked like it worked, sent
		// one value, and lost the rest silently. Collect those into an array and
		// drop the brackets, which is what the name was already claiming.
		var data = {};
		var formData = new FormData( form );
		formData.forEach( function ( value, key ) {
			if ( key.slice( -2 ) === '[]' ) {
				var arrayKey = key.slice( 0, -2 );
				if ( ! Array.isArray( data[ arrayKey ] ) ) {
					data[ arrayKey ] = [];
				}
				data[ arrayKey ].push( value );
				return;
			}
			data[ key ] = value;
		} );

		// Set loading state.
		submitBtn.disabled = true;
		submitBtn.setAttribute( 'aria-disabled', 'true' );
		var originalText = submitBtn.textContent;
		submitBtn.textContent = submitBtn.textContent.replace( /.$/, '' ) + '…';

		errorBox.hidden = true;
		successBox.hidden = true;

		fetch( config.restUrl + 'auth/register', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce,
			},
			credentials: 'same-origin',
			body: JSON.stringify( data ),
		} )
			.then( function ( response ) {
				return response.json().then( function ( json ) {
					return { ok: response.ok, status: response.status, body: json };
				} );
			} )
			.then( function ( result ) {
				if ( ! result.ok ) {
					// Two failure shapes reach this branch. The plugin's own
					// envelope is { error: { message, details } }. A rejection
					// from the REST arg schema never gets that far — WP answers
					// before the callback runs, with { message, data: { params:
					// { field: reason } } }. Only the first was read, so every
					// schema rejection showed the generic fallback and the
					// member never learned which field was wrong.
					var body    = result.body || {};
					var params  = ( body.data && body.data.params ) || null;
					var message = ( body.error && body.error.message )
						? body.error.message
						: ( body.message || __( 'Registration failed. Please try again.', 'learnomy' ) );

					// Field-specific errors, from either shape.
					if ( body.error && Array.isArray( body.error.details ) ) {
						body.error.details.forEach( function ( detail ) {
							if ( detail.field && detail.message ) {
								showFieldError( detail.field, detail.message );
							}
						} );
					} else if ( params ) {
						Object.keys( params ).forEach( function ( field ) {
							showFieldError( field, params[ field ] );
						} );
					}

					errorBox.textContent = message;
					errorBox.hidden = false;
					errorBox.scrollIntoView( { behavior: 'smooth', block: 'center' } );
					return;
				}

				// Registration successful — show the server's message.
				var data = result.body.data || {};
				successBox.textContent = data.message
					? data.message
					: __( 'Account created successfully!', 'learnomy' );
				successBox.hidden = false;
				form.hidden = true;

				// Email verification pending → the user is NOT logged in (auto-login
				// is skipped until they click the emailed link). Do NOT redirect onto
				// a course/dashboard, where they would appear logged-out and see
				// "Sign up to buy" again while the "check your email" message is lost.
				// Keep the verify-email message on screen. [card 10062744598]
				if ( data.verification_required ) {
					return;
				}

				// Auto-logged-in (verification not required) — land them on their
				// destination. J-01.6: honour redirect_to (e.g. Sign up to buy on a
				// course), else the my-courses dashboard.
				var redirectField  = form.querySelector( 'input[name="redirect_to"]' );
				var redirectTarget = redirectField && redirectField.value
					? redirectField.value
					: ( config.myCoursesUrl || '/my-courses/' );
				setTimeout( function () {
					window.location.href = redirectTarget;
				}, 1000 );
			} )
			.catch( function () {
				errorBox.textContent = __( 'A network error occurred. Please check your connection and try again.', 'learnomy' );
				errorBox.hidden = false;
			} )
			.finally( function () {
				submitBtn.disabled = false;
				submitBtn.removeAttribute( 'aria-disabled' );
				submitBtn.textContent = originalText;
			} );
	} );
} )();
