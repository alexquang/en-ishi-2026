/**
 * Learnomy — Admin action button wiring.
 *
 * Generic dispatcher for all admin action buttons. Buttons declare their
 * action and data via data-* attributes:
 *
 *   data-action="learnomy_delete_category"   → action name
 *   data-id="5"                               → passed as the ID field
 *   data-confirm="Delete this category?"      → optional confirmation prompt
 *   data-id-field="category_id"               → field name for the ID (default: "id")
 *
 * Routing — REST_MAP is the whole contract. An action listed there is sent
 * via REST (cookie auth + X-WP-Nonce); an action that is NOT listed is left
 * alone, because this dispatcher does not own it.
 *
 * `data-action` is a generic attribute name that Pro and other Free scripts
 * also use for their own local wiring. This listener sits on `document`, so
 * it sees every one of them. It used to fall through to admin-ajax.php with
 * the action name as a guess; nothing registered a handler for any of those,
 * so the request 400'd and the owner got a red "Action failed." on top of a
 * button that had just worked (Basecamp 10159647813). Unmapped means
 * not-ours, not try-something-else.
 *
 * @package Learnomy
 */
( function () {
	'use strict';

	var __        = wp.i18n.__;
	var admin     = window.learnomyAdmin || {};
	var nonce     = admin.nonce || '';
	var restNonce = admin.restNonce || '';
	var restUrl   = admin.restUrl || '/wp-json/learnomy/v1/';

	// ── REST mapping ────────────────────────────────────────────────
	//
	// Each entry says how to call REST instead of admin-ajax for one
	// action name. `path` builds the URL (relative to restUrl), `method`
	// is the HTTP verb, and `redirect` synthesises the post-save URL when
	// the AJAX handler used to send one. `excludeKeys` strips fields that
	// belong in the URL (e.g. quiz_id) so they don't double-send in the
	// JSON body. `successMessage` is the toast text — REST returns the
	// resource, not a human string, so we phrase it here.

	var REST_MAP = {
		learnomy_create_quiz: {
			method: 'POST',
			path:   function () { return 'quizzes'; },
			redirect: function ( _d, res ) {
				var id = res && res.data && res.data.id;
				return id ? 'admin.php?page=learnomy-edit-quiz&quiz_id=' + id : '';
			},
			successMessage: __( 'Quiz created.', 'learnomy' ),
		},
		learnomy_update_quiz: {
			method: 'PATCH',
			path:   function ( d ) { return 'quizzes/' + parseInt( d.quiz_id, 10 ); },
			redirect: function ( d ) {
				// Match the legacy AJAX behaviour: navigate back to the same
				// editor URL after save (no form-reset flash).
				return 'admin.php?page=learnomy-edit-quiz&quiz_id=' + parseInt( d.quiz_id, 10 );
			},
			successMessage: __( 'Quiz updated.', 'learnomy' ),
			excludeKeys: [ 'quiz_id' ],
		},
		learnomy_delete_quiz: {
			method:   'DELETE',
			path:     function ( d ) { return 'quizzes/' + parseInt( d.quiz_id, 10 ); },
			sendBody: false,
			successMessage: __( 'Quiz deleted.', 'learnomy' ),
		},
		learnomy_delete_lesson: {
			method:   'DELETE',
			path:     function ( d ) { return 'lessons/' + parseInt( d.lesson_id, 10 ); },
			sendBody: false,
			successMessage: __( 'Lesson deleted.', 'learnomy' ),
		},
		// Courses were the only content model without this entry, so the
		// "Delete Permanently" row action in the trash view had no mapping to
		// reach — see the note on that button in views/courses.php.
		learnomy_delete_course: {
			method:   'DELETE',
			path:     function ( d ) { return 'courses/' + parseInt( d.course_id, 10 ); },
			sendBody: false,
			successMessage: __( 'Course deleted permanently.', 'learnomy' ),
		},
		// Recall a sent announcement broadcast. Keyed by the broadcast tuple
		// (no single id), sent in the body.
		learnomy_delete_announcement: {
			method: 'POST',
			path:   function () { return 'admin/announcements/delete'; },
			bodyTransform: function ( d ) {
				return {
					created_at: d.created_at,
					title:      d.title,
					object_id:  parseInt( d.object_id || 0, 10 ),
				};
			},
			successMessage: __( 'Announcement deleted.', 'learnomy' ),
		},
		learnomy_create_question: {
			method: 'POST',
			path:   function () { return 'questions'; },
			redirect: function ( _d, res ) {
				// REST nests the row under .data.question; AJAX used .question_id.
				var q  = res && res.data && res.data.question;
				var id = q && q.id;
				return id ? 'admin.php?page=learnomy-question-editor&question_id=' + id : '';
			},
			successMessage: __( 'Question created.', 'learnomy' ),
		},
		learnomy_update_question: {
			method: 'PATCH',
			path:   function ( d ) { return 'questions/' + parseInt( d.question_id, 10 ); },
			redirect: function ( d ) {
				return 'admin.php?page=learnomy-question-editor&question_id=' + parseInt( d.question_id, 10 );
			},
			successMessage: __( 'Question updated.', 'learnomy' ),
			excludeKeys: [ 'question_id' ],
		},
		learnomy_delete_question: {
			method:   'DELETE',
			path:     function ( d ) { return 'questions/' + parseInt( d.question_id, 10 ); },
			sendBody: false,
			successMessage: __( 'Question deleted.', 'learnomy' ),
		},
		learnomy_duplicate_question: {
			method:   'POST',
			path:     function ( d ) { return 'questions/' + parseInt( d.question_id, 10 ) + '/duplicate'; },
			sendBody: false,
			successMessage: __( 'Question duplicated.', 'learnomy' ),
		},

		// Course duplication. The service, the REST route (POST courses/{id}/duplicate)
		// and the Ability have all existed for months — there was simply no button, so
		// a site owner could not duplicate a course without calling the API by hand.
		// Questions had this from day one; courses, the thing people actually want to
		// clone, did not.
		learnomy_duplicate_course: {
			method:   'POST',
			path:     function ( d ) { return 'courses/' + parseInt( d.course_id, 10 ) + '/duplicate'; },
			sendBody: false,
			successMessage: __( 'Course duplicated.', 'learnomy' ),
		},

		// ── Stage 5K-C: Coupons / Applications / Withdrawals / Commissions ──
		learnomy_create_coupon: {
			method: 'POST',
			path:   function () { return 'admin/coupons'; },
			successMessage: __( 'Coupon created.', 'learnomy' ),
			excludeKeys: [ 'coupon_id' ],
		},
		learnomy_update_coupon: {
			method: 'PATCH',
			path:   function ( d ) { return 'admin/coupons/' + parseInt( d.coupon_id, 10 ); },
			successMessage: __( 'Coupon updated.', 'learnomy' ),
			excludeKeys: [ 'coupon_id' ],
		},
		learnomy_delete_coupon: {
			method:   'DELETE',
			path:     function ( d ) { return 'admin/coupons/' + parseInt( d.coupon_id, 10 ); },
			sendBody: false,
			successMessage: __( 'Coupon deleted.', 'learnomy' ),
		},
		learnomy_approve_application: {
			method:   'POST',
			path:     function ( d ) { return 'admin/instructor-applications/' + parseInt( d.application_id, 10 ) + '/approve'; },
			sendBody: false,
			successMessage: __( 'Application approved. Instructor role granted.', 'learnomy' ),
		},
		learnomy_reject_application: {
			method: 'POST',
			path:   function ( d ) { return 'admin/instructor-applications/' + parseInt( d.application_id, 10 ) + '/reject'; },
			// `reason` from the legacy AJAX maps to REST `feedback` — both
			// drive the email sent to the applicant.
			bodyTransform: function ( d ) { return d.reason ? { feedback: d.reason } : {}; },
			successMessage: __( 'Application rejected.', 'learnomy' ),
		},
		learnomy_approve_withdrawal: {
			method:   'POST',
			path:     function ( d ) { return 'admin/withdrawals/' + parseInt( d.withdrawal_id, 10 ) + '/approve'; },
			sendBody: false,
			successMessage: __( 'Withdrawal approved.', 'learnomy' ),
		},
		learnomy_reject_withdrawal: {
			method: 'POST',
			path:   function ( d ) { return 'admin/withdrawals/' + parseInt( d.withdrawal_id, 10 ) + '/reject'; },
			bodyTransform: function ( d ) { return d.reason ? { reason: d.reason } : {}; },
			successMessage: __( 'Withdrawal rejected.', 'learnomy' ),
		},
		learnomy_update_commission_rule: {
			method: 'PATCH',
			path:   function ( d ) { return 'admin/commission-rules/' + parseInt( d.rule_id, 10 ); },
			// Legacy AJAX field `rate` maps to the schema's actual column
			// `platform_rate`. (The legacy AJAX wrote to a non-existent `rate`
			// column and silently no-op'd — this REST migration is also a
			// real bug fix.)
			bodyTransform: function ( d ) { return { platform_rate: parseFloat( d.rate ) }; },
			successMessage: __( 'Commission rule saved.', 'learnomy' ),
		},
		learnomy_delete_commission_rule: {
			method:   'DELETE',
			path:     function ( d ) { return 'admin/commission-rules/' + parseInt( d.rule_id, 10 ); },
			sendBody: false,
			// Deleting an override restores inheritance rather than removing a
			// rate, so the message says what the owner will now see.
			successMessage: __( 'Commission rule removed. This now inherits the default rate.', 'learnomy' ),
		},

		// ── Stage 5K-D: Webhooks ──────────────────────────────────────────
		learnomy_create_webhook: {
			method: 'POST',
			path:   function () { return 'admin/webhooks'; },
			successMessage: __( 'Webhook created.', 'learnomy' ),
		},
		learnomy_delete_webhook: {
			method:   'DELETE',
			path:     function ( d ) { return 'admin/webhooks/' + parseInt( d.webhook_id, 10 ); },
			sendBody: false,
			successMessage: __( 'Webhook deleted.', 'learnomy' ),
		},
		learnomy_update_webhook: {
			method:      'PATCH',
			path:        function ( d ) { return 'admin/webhooks/' + parseInt( d.webhook_id, 10 ); },
			excludeKeys: [ 'webhook_id' ],
			successMessage: __( 'Webhook updated.', 'learnomy' ),
		},
		// A test ping can come back 200 from OUR endpoint while the customer's
		// endpoint rejected it, so the toast is derived from the payload rather
		// than asserted. The message is composed server-side (one i18n home) and
		// the list reloads either way, because a test always rewrites the row's
		// last HTTP status — and a SUCCESSFUL test can clear a "Failed" badge.
		// A failed test no longer pauses the endpoint (Basecamp 10132808815).
		learnomy_test_webhook: {
			method:   'POST',
			path:     function ( d ) { return 'admin/webhooks/' + parseInt( d.webhook_id, 10 ) + '/test'; },
			sendBody: false,
			successMessage: __( 'Test delivered.', 'learnomy' ),
			reloadOnError:  true,
			resultMessage:  function ( data ) {
				return {
					message: data.message || __( 'Test delivery failed.', 'learnomy' ),
					isError: ! data.delivered,
				};
			},
		},

		// ── Stage 5K-E: Courses ───────────────────────────────────────────
		// Status flip from the "Publish" / "Move to draft" row links — REST
		// PATCH /courses/{id} accepts a partial body with just `status`.
		learnomy_update_course_status: {
			method: 'PATCH',
			path:   function ( d ) { return 'courses/' + parseInt( d.course_id, 10 ); },
			bodyTransform: function ( d ) { return { status: d.status }; },
			successMessage: __( 'Course status updated.', 'learnomy' ),
		},
		learnomy_update_quiz_status: {
			method: 'PATCH',
			path:   function ( d ) { return 'quizzes/' + parseInt( d.quiz_id, 10 ); },
			bodyTransform: function ( d ) { return { status: d.status }; },
			successMessage: __( 'Quiz status updated.', 'learnomy' ),
		},

		// Marketplace course approval — the Pending Review row actions invoke the
		// EXISTING learnomy/approve-course + learnomy/reject-course abilities via
		// the WordPress core Abilities run endpoint (a different REST namespace
		// than learnomy/v1, so path() returns an absolute URL). The ability's own
		// permission_callback (manage_options) gates the request; both take the
		// input under the standard `input` envelope. Approve publishes; Reject
		// carries the prompted reason and returns the course to draft.
		learnomy_approve_course: {
			method: 'POST',
			path:   function () { return abilityRunUrl( 'learnomy/approve-course' ); },
			bodyTransform: function ( d ) { return { input: { course_id: parseInt( d.course_id, 10 ) } }; },
			successMessage: __( 'Course approved and published.', 'learnomy' ),
		},
		learnomy_reject_course: {
			method: 'POST',
			path:   function () { return abilityRunUrl( 'learnomy/reject-course' ); },
			bodyTransform: function ( d ) {
				return { input: { course_id: parseInt( d.course_id, 10 ), reason: d.reason || '' } };
			},
			successMessage: __( 'Course rejected and returned to draft.', 'learnomy' ),
		},

		// ── Instructors ───────────────────────────────────────────────────
		// Pause or resume an instructor. Nothing is deleted: their courses stay
		// live, their existing students keep full access and their earnings stay
		// payable. Suspension only hides them from the public directory and stops
		// NEW enrollments in their courses.
		learnomy_set_instructor_status: {
			method: 'POST',
			path:   function ( d ) { return 'admin/instructors/' + parseInt( d.user_id, 10 ) + '/status'; },
			bodyTransform: function ( d ) { return { status: d.status }; },
			successMessage: __( 'Instructor status updated.', 'learnomy' ),
		},

		// ── Stage 5K-G: Students ──────────────────────────────────────────
		// Remove a student's enrollment from a course (DELETE the row).
		learnomy_remove_enrollment: {
			method:   'DELETE',
			path:     function ( d ) {
				// Two callers map here:
				//   1. Per-row "Unenroll" on a single course's Students list — passes
				//      enrollment_id, deletes one row.
				//   2. Per-student "Remove" on the Students admin page — passes
				//      user_id (the list has no enrollment_id), drops every row
				//      for that user via the bulk endpoint. See Basecamp #9927159497.
				if ( d.enrollment_id ) {
					return 'enrollments/' + parseInt( d.enrollment_id, 10 );
				}
				return 'students/' + parseInt( d.user_id, 10 ) + '/enrollments';
			},
			sendBody: false,
			successMessage: __( 'Access removed. The enrollment is kept so you can restore it.', 'learnomy' ),
		},

		// The undo for the action above. Restores a cancelled/expired enrollment
		// with the student's progress intact — NOT the same thing as re-enrolling
		// them, which starts the course over.
		learnomy_admin_restore_enrollment: {
			method:   'POST',
			path:     function ( d ) { return 'enrollments/' + parseInt( d.enrollment_id, 10 ) + '/restore'; },
			sendBody: false,
			successMessage: __( 'Access restored.', 'learnomy' ),
		},

		// ── Per-student detail page ───────────────────────────────────────
		// Reset one course's progress + quiz attempts for a student.
		learnomy_admin_reset_course: {
			method: 'POST',
			path:   function ( d ) { return 'admin/students/' + parseInt( d.user_id, 10 ) + '/reset-course'; },
			bodyTransform: function ( d ) { return { course_id: parseInt( d.course_id, 10 ) }; },
			successMessage: __( 'Course progress reset.', 'learnomy' ),
		},
		// Give this student one more go at ONE quiz, without the collateral
		// damage of resetting the whole course.
		learnomy_admin_grant_quiz_attempt: {
			method: 'POST',
			path:   function ( d ) { return 'admin/students/' + parseInt( d.user_id, 10 ) + '/quiz-attempt-grant'; },
			bodyTransform: function ( d ) {
				return {
					quiz_id:  parseInt( d.quiz_id, 10 ),
					attempts: parseInt( d.attempts, 10 ) || 1,
				};
			},
			successMessage: __( 'Extra attempt granted.', 'learnomy' ),
		},

		// ── Per-subscription detail page ──────────────────────────────────
		// Cancel: data-at-period-end=1 keeps access until current_period_end
		// (status → cancel_pending); 0 cancels immediately.
		learnomy_admin_cancel_subscription: {
			method: 'POST',
			path:   function ( d ) { return 'admin/memberships/' + parseInt( d.subscription_id, 10 ) + '/cancel'; },
			bodyTransform: function ( d ) { return { at_period_end: parseInt( d.at_period_end, 10 ) === 1 }; },
			successMessage: __( 'Subscription cancelled.', 'learnomy' ),
		},
		learnomy_admin_resume_subscription: {
			method: 'POST',
			path:   function ( d ) { return 'admin/memberships/' + parseInt( d.subscription_id, 10 ) + '/resume'; },
			sendBody: false,
			successMessage: __( 'Subscription resumed.', 'learnomy' ),
		},

		// ── Per-instructor detail page ────────────────────────────────────
		// Toggle the verification badge. data-verified carries the target
		// state (1 = verify, 0 = unverify).
		learnomy_admin_verify_instructor: {
			method: 'POST',
			path:   function ( d ) { return 'admin/instructors/' + parseInt( d.instructor_id, 10 ) + '/verify'; },
			bodyTransform: function ( d ) { return { verified: parseInt( d.verified, 10 ) }; },
			successMessage: __( 'Verification updated.', 'learnomy' ),
		},

		// Per-instructor course-approval override. Require review for one
		// instructor even when the site allows self-publish (data-require carries
		// the target state: 1 = require approval, 0 = allow self-publish).
		learnomy_admin_set_require_approval: {
			method: 'POST',
			path:   function ( d ) { return 'admin/instructors/' + parseInt( d.instructor_id, 10 ) + '/require-approval'; },
			bodyTransform: function ( d ) { return { require_approval: parseInt( d.require, 10 ) }; },
			successMessage: __( 'Course-approval setting updated.', 'learnomy' ),
		},
	};

	/**
	 * Build the absolute run URL for a core Abilities API ability. Derives the
	 * WP REST root from our own restUrl (…/wp-json/learnomy/v1/ → …/wp-json/)
	 * and appends the abilities run route for the named ability.
	 */
	function abilityRunUrl( name ) {
		var root = restUrl.replace( /learnomy\/v1\/?$/, '' );
		return root + 'wp-abilities/v1/abilities/' + name + '/run';
	}

	/**
	 * Re-inflate FormData's string-keyed entries (e.g. "answers[0][content]"
	 * or "events[]") into a nested object. Necessary because REST expects
	 * JSON; FormData iteration produces flat keys with PHP-style array
	 * notation.
	 *
	 * Bare `events[]` becomes a real JS array. Indexed `answers[0][content]`
	 * becomes `{ answers: [{ content: ... }] }`. Non-sequential numeric keys
	 * like `slot_marks[123]` stay as objects via compactArrays() downstream.
	 */
	function expandKeys( flat ) {
		var result = {};
		Object.keys( flat ).forEach( function ( key ) {
			var value = flat[ key ];
			var match = key.match( /^([^\[\]]+)((?:\[[^\]]*\])*)$/ );
			if ( ! match ) {
				result[ key ] = value;
				return;
			}
			var parts = [ match[ 1 ] ];
			match[ 2 ].replace( /\[([^\]]*)\]/g, function ( _m, k ) {
				parts.push( k );
				return '';
			} );

			var node = result;
			for ( var i = 0; i < parts.length; i++ ) {
				var p      = parts[ i ];
				var nextP  = ( i < parts.length - 1 ) ? parts[ i + 1 ] : null;
				var isLast = ( i === parts.length - 1 );
				if ( isLast ) {
					if ( p === '' ) {
						// Bare [] at leaf — push to the array container that
						// was created in the previous iteration (when we saw
						// nextP === '').
						if ( Array.isArray( node ) ) {
							// `value` is an array when the same `foo[]` key was
							// checked multiple times (accumulated at submit time);
							// flatten it so each selection is its own element.
							if ( Array.isArray( value ) ) {
								value.forEach( function ( v ) { node.push( v ); } );
							} else {
								node.push( value );
							}
						}
					} else {
						node[ p ] = value;
					}
				} else {
					// Look ahead one step: bare-[] next means this slot is an
					// array; otherwise it's an object. Without this, my
					// previous code always created objects and dropped the
					// final value into a synthetic .__list key.
					if ( nextP === '' ) {
						if ( ! Array.isArray( node[ p ] ) ) {
							node[ p ] = [];
						}
					} else if ( typeof node[ p ] !== 'object' || node[ p ] === null ) {
						node[ p ] = {};
					}
					node = node[ p ];
				}
			}
		} );
		return result;
	}

	/**
	 * Convert objects whose keys are sequential 0..N strings into arrays.
	 * Keeps non-sequential numeric keys (e.g. {"123": x}) as objects so
	 * slot_marks-style maps round-trip cleanly through PHP's json_decode.
	 */
	function compactArrays( node ) {
		if ( Array.isArray( node ) ) {
			return node.map( compactArrays );
		}
		if ( node !== null && typeof node === 'object' ) {
			var keys = Object.keys( node );
			if ( keys.length > 0 && keys.every( function ( k ) { return /^\d+$/.test( k ); } ) ) {
				var nums = keys.map( Number ).sort( function ( a, b ) { return a - b; } );
				var contiguous = nums[ 0 ] === 0 && nums[ nums.length - 1 ] === nums.length - 1;
				if ( contiguous ) {
					return nums.map( function ( i ) { return compactArrays( node[ String( i ) ] ); } );
				}
			}
			var out = {};
			keys.forEach( function ( k ) { out[ k ] = compactArrays( node[ k ] ); } );
			return out;
		}
		return node;
	}

	/**
	 * Send a request, REST or AJAX depending on REST_MAP membership.
	 */
	function sendAction( action, data, btn, onSuccess ) {
		btn.disabled = true;
		btn.classList.add( 'is-loading' );

		var done = function ( result, isError ) {
			btn.disabled = false;
			btn.classList.remove( 'is-loading' );
			if ( isError ) {
				// A few actions change server state even when the outcome they
				// report is a failure (a webhook Test marks the endpoint
				// failed). Those mappings ask for a refresh so the row stops
				// showing the pre-attempt state.
				var reloadsOnError = !! ( REST_MAP[ action ] && REST_MAP[ action ].reloadOnError );
				var errorMessage   = ( result && result.message ) || 'Action failed.';

				// An error message matters more than a success one, and this is
				// the branch that reloads — so it has to survive the reload or
				// the user is left with a silently reverted row and no reason.
				if ( reloadsOnError ) {
					showToast.flash( errorMessage, 'error' );
					setTimeout( function () { location.reload(); }, 1200 );
				} else {
					showToast( errorMessage, 'error' );
				}
				return;
			}
			if ( typeof onSuccess === 'function' ) {
				onSuccess( result );
			} else {
				showToast.flash( ( result && result.message ) || 'Done.', 'success' );
				setTimeout( function () { location.reload(); }, 800 );
			}
		};

		var mapping = REST_MAP[ action ];
		if ( ! mapping ) {
			// Unreachable from either caller today: the click listener returns
			// early on an unmapped action, and both `form[data-ajax-action]`
			// values are mapped. Kept as a loud developer signal for the next
			// person who adds a form action and forgets the REST_MAP entry.
			//
			// This used to fall through to an ajaxCall() that POSTed the action
			// name to admin-ajax.php as a guess. No handler was ever registered
			// for any of them, so it 400'd and surfaced a red "Action failed."
			// to the site owner — a wrong answer delivered confidently. A
			// missing mapping is a developer mistake and now reads as one.
			window.console && window.console.error(
				'[learnomy] No REST_MAP entry for data-action "' + action + '" — nothing was sent.'
			);
			done( { message: __( 'Action failed.', 'learnomy' ) }, true );
			return;
		}
		restCall( mapping, data, done );
	}

	function restCall( mapping, flatData, done ) {
		var fetchOpts = {
			method:      mapping.method,
			credentials: 'same-origin',
			headers:     { 'X-WP-Nonce': restNonce },
		};

		if ( mapping.sendBody !== false && mapping.method !== 'GET' && mapping.method !== 'DELETE' ) {
			// bodyTransform takes the raw flat form data and returns the
			// JSON body to send. Lets us rename fields (rate→platform_rate)
			// or strip everything except a single known key without
			// inheriting whatever else the form posted.
			var body = typeof mapping.bodyTransform === 'function'
				? mapping.bodyTransform( flatData )
				: compactArrays( expandKeys( flatData ) );
			( mapping.excludeKeys || [] ).forEach( function ( k ) { delete body[ k ]; } );
			fetchOpts.headers[ 'Content-Type' ] = 'application/json';
			fetchOpts.body = JSON.stringify( body );
		}

		// path() usually returns a route relative to restUrl (learnomy/v1/); an
		// absolute URL (e.g. the core Abilities run endpoint on another
		// namespace) is used verbatim.
		var target = mapping.path( flatData );
		if ( ! /^https?:\/\//.test( target ) ) {
			target = restUrl + target;
		}

		fetch( target, fetchOpts )
			.then( function ( resp ) {
				return resp.json().then( function ( json ) {
					return { ok: resp.ok, json: json };
				} );
			} )
			.then( function ( out ) {
				if ( ! out.ok ) {
					var msg = ( out.json && out.json.error && out.json.error.message )
						|| ( out.json && out.json.message )
						|| 'Request failed.';
					done( { message: msg }, true );
					return;
				}
				var synthetic = { message: mapping.successMessage || __( 'Done.', 'learnomy' ) };

				// An HTTP 200 says the request was handled, not that the thing
				// it did worked. `resultMessage` lets a mapping read the real
				// outcome out of the payload and report that instead.
				if ( typeof mapping.resultMessage === 'function' ) {
					var outcome = mapping.resultMessage( ( out.json && out.json.data ) || {} );
					if ( outcome && outcome.isError ) {
						done( { message: outcome.message }, true );
						return;
					}
					if ( outcome && outcome.message ) {
						synthetic.message = outcome.message;
					}
				}
				if ( typeof mapping.redirect === 'function' ) {
					var url = mapping.redirect( flatData, out.json );
					if ( url ) {
						synthetic.redirect = url;
					}
				}
				done( synthetic );
			} )
			.catch( function ( err ) {
				done( { message: ( err && err.message ) || __( 'Network error.', 'learnomy' ) }, true );
			} );
	}

	/**
	 * Show a temporary toast notification.
	 */
	// Delegates to the shared toast (`assets/js/shared/toast.js`), a dependency
	// of this script and therefore always loaded.
	//
	// This hand-rolled its own `lrn-admin-toast` element, a class with no CSS
	// rule in either plugin — so it rendered as unstyled text in document flow,
	// far below the fold, and nobody ever saw one (Basecamp 10185990987).
	function showToast( message, type ) {
		window.lrnToast( message, type || 'success' );
	}

	// Same message, but queued for the page we are about to navigate to. See
	// `lrnToast.flash()` — a toast shown immediately before a reload dies with
	// the document it was drawn into.
	showToast.flash = function ( message, type ) {
		window.lrnToast.flash( message, type || 'success' );
	};

	// ── Generic data-action buttons ────────────────────────────────

	document.addEventListener( 'click', async function ( e ) {
		var btn = e.target.closest( '[data-action]' );
		if ( ! btn ) { return; }

		var action     = btn.getAttribute( 'data-action' );

		// Only handle actions this dispatcher actually knows.
		//
		// `data-action` is a generic attribute name, not ours alone: Pro uses it
		// as local wiring on 6 admin screens (Spaces groups, bulk import, space
		// detail, spaces list, assignments, Stripe Connect) and Free itself uses
		// it for reply-save / reply-cancel / request-withdrawal / reset-demo —
		// every one of them handled by its OWN script. This listener is on
		// `document`, so it saw all of them, found no REST_MAP entry, and fell
		// through to ajaxCall(), which POSTed a guessed action name to
		// admin-ajax.php. No handler is registered for any of them, so the
		// request 400'd and showToast rendered a red "Action failed." — on top
		// of the real handler having just done the job correctly.
		//
		// An owner importing a 200-person roster saw the import succeed and an
		// error telling them it had failed (Basecamp 10159647813).
		//
		// The fallback was never reachable: not one of the 43 data-action values
		// across both plugins matches a registered wp_ajax_ action, so ajaxCall()
		// could only ever produce that false error. Ignoring an unmapped action
		// is what this listener always should have done — claiming a click it
		// cannot service is the bug, and the guessed AJAX post was never a real
		// second path.
		if ( ! REST_MAP[ action ] ) { return; }

		var confirmMsg = btn.getAttribute( 'data-confirm' );
		var promptMsg  = btn.getAttribute( 'data-prompt' );
		var idField    = btn.getAttribute( 'data-id-field' ) || 'id';
		var id         = btn.getAttribute( 'data-id' );

		var data = {};
		data[ idField ] = id;

		// data-prompt actions (e.g. reject-withdrawal needs a reason) ask the
		// user for a single value via the Learnomy prompt modal and stash it on
		// data.reason. The REST endpoint requires `reason`; sending {} produced
		// rest_missing_callback_param: reason → a guaranteed 400. The prompt
		// doubles as the confirm step, so we skip the plain confirm when a
		// prompt is present.
		if ( promptMsg ) {
			if ( 'function' !== typeof window.lrnPrompt ) {
				return;
			}
			var reason = await window.lrnPrompt( {
				title:        __( 'Please provide a reason', 'learnomy' ),
				message:      promptMsg,
				label:        btn.getAttribute( 'data-prompt-label' ) || __( 'Reason', 'learnomy' ),
				confirmLabel: __( 'Submit', 'learnomy' ),
				required:     true,
				maxLength:    255,
			} );
			// Null = cancelled / Escape / backdrop; empty string can't happen
			// because required:true keeps the modal open until non-empty.
			if ( null === reason || ! reason ) {
				return;
			}
			data.reason = reason;
		} else if ( confirmMsg ) {
			var ok = await window.lrnConfirm( {
				title:       __( 'Please confirm', 'learnomy' ),
				message:     confirmMsg,
				destructive: action.indexOf( 'delete' ) !== -1,
			} );
			if ( ! ok ) {
				return;
			}
		}

		// Forward every other data-* attribute to the action, converting the
		// HTML5 camelCase dataset keys back to the snake_case that the REST
		// path()/bodyTransform() functions expect (data-user-id → userId →
		// user_id). Reserved keys consumed above are skipped so they never
		// leak into the request. This is generic on purpose: hardcoding a
		// fixed list (status/type/rate) silently dropped data-user-id /
		// data-course-id, which made reset-course POST to students/NaN (404).
		var lrnReservedDataKeys = {
			action: 1, confirm: 1, prompt: 1, promptLabel: 1, id: 1, idField: 1,
		};
		Object.keys( btn.dataset ).forEach( function ( key ) {
			if ( lrnReservedDataKeys[ key ] ) {
				return;
			}
			var snakeKey = key.replace( /[A-Z]/g, function ( letter ) {
				return '_' + letter.toLowerCase();
			} );
			data[ snakeKey ] = btn.dataset[ key ];
		} );

		// For delete actions, remove the row on success.
		var isDelete = action.indexOf( 'delete' ) !== -1;

		sendAction( action, data, btn, function ( result ) {
			showToast( result.message || 'Done.', 'success' );
			if ( isDelete ) {
				var row = btn.closest( 'tr' ) || btn.closest( '.lrn-card' );
				if ( row ) {
					row.style.transition = 'opacity 200ms ease-out';
					row.style.opacity = '0';
					setTimeout( function () { row.remove(); }, 200 );
				}
			} else {
				setTimeout( function () { location.reload(); }, 800 );
			}
		} );
	} );

	// ── Edit links that repopulate forms ──────────────────────────
	//
	// The row's "Edit" link carries every editable field in a single
	// data-coupon JSON blob (see admin/views/coupons.php). We decode it,
	// prefill the create form, and flip it into update mode.

	document.addEventListener( 'click', function ( e ) {
		var editBtn = e.target.closest( '.lrn-edit-coupon' );
		if ( ! editBtn ) { return; }
		e.preventDefault();

		var form = document.querySelector( 'form[data-ajax-action="learnomy_create_coupon"], form[data-ajax-action="learnomy_update_coupon"]' );
		if ( ! form ) { return; }

		var coupon = {};
		try {
			coupon = JSON.parse( editBtn.dataset.coupon || '{}' );
		} catch ( err ) {
			return;
		}

		// Every editable column maps to one form input by matching name.
		// Null/undefined values become '' so selects don't break.
		var fields = [
			'code', 'type', 'value', 'applies_to', 'status',
			'usage_limit', 'per_user_limit', 'min_purchase',
			'starts_at', 'expires_at'
		];
		fields.forEach( function ( name ) {
			var input = form.querySelector( '[name="' + name + '"]' );
			if ( ! input ) { return; }
			var value = coupon[ name ];
			input.value = ( value === null || value === undefined ) ? '' : value;
		} );

		// Switch form to update mode
		form.setAttribute( 'data-ajax-action', 'learnomy_update_coupon' );
		var hiddenId = form.querySelector( '[name="coupon_id"]' );
		if ( ! hiddenId ) {
			hiddenId = document.createElement( 'input' );
			hiddenId.type = 'hidden';
			hiddenId.name = 'coupon_id';
			form.appendChild( hiddenId );
		}
		hiddenId.value = coupon.id || '';

		// Swap title + submit label, reveal Cancel.
		var title = document.querySelector( '.lrn-coupon-form__title' );
		if ( title ) { title.textContent = __( 'Edit Coupon', 'learnomy' ); }
		var submitBtn = form.querySelector( 'button[type="submit"], input[type="submit"]' );
		if ( submitBtn ) { submitBtn.textContent = __( 'Update Coupon', 'learnomy' ); }
		var cancelBtn = document.getElementById( 'lrn-coupon-cancel-edit' );
		if ( cancelBtn ) { cancelBtn.hidden = false; }

		// Rehydrate the course-scope chips from the coupon's stored items.
		if ( window.__lrnCouponScope ) { window.__lrnCouponScope.setItems( coupon.items || [] ); }

		form.scrollIntoView( { behavior: 'smooth', block: 'start' } );
	} );

	// Cancel button resets the form back to Create mode.
	document.addEventListener( 'click', function ( e ) {
		var cancelBtn = e.target.closest( '#lrn-coupon-cancel-edit' );
		if ( ! cancelBtn ) { return; }
		e.preventDefault();

		var form = document.querySelector( 'form[data-ajax-action="learnomy_update_coupon"]' );
		if ( ! form ) { return; }

		form.reset();
		form.setAttribute( 'data-ajax-action', 'learnomy_create_coupon' );
		if ( window.__lrnCouponScope ) { window.__lrnCouponScope.clear(); }

		var hiddenId = form.querySelector( '[name="coupon_id"]' );
		if ( hiddenId ) { hiddenId.remove(); }

		var title = document.querySelector( '.lrn-coupon-form__title' );
		if ( title ) { title.textContent = __( 'Create New Coupon', 'learnomy' ); }
		var submitBtn = form.querySelector( 'button[type="submit"], input[type="submit"]' );
		if ( submitBtn ) { submitBtn.textContent = __( 'Create Coupon', 'learnomy' ); }
		cancelBtn.hidden = true;
	} );

	// ── Coupon course-scope picker ─────────────────────────────────
	// Typeahead over Free's GET /courses?search= to limit a coupon to
	// specific courses. Selected courses become removable chips backed by
	// hidden items[] inputs the generic form serializer already sends
	// (empty = applies to all courses). All labels via textContent — no
	// untrusted HTML.
	( function initCouponScope() {
		var picker = document.querySelector( '[data-coupon-course-picker]' );
		if ( ! picker ) { return; }
		var input   = picker.querySelector( '[data-scope-input]' );
		var results = picker.querySelector( '[data-scope-results]' );
		var chips   = picker.querySelector( '[data-scope-chips]' );
		var timer   = null, items = [], active = -1;

		function base() { return ( restUrl || '/wp-json/learnomy/v1/' ).replace( /\/$/, '' ); }
		function headers() { return restNonce ? { 'X-WP-Nonce': restNonce } : {}; }
		function selectedIds() {
			return Array.prototype.map.call( chips.querySelectorAll( 'input[name="items[]"]' ), function ( i ) { return parseInt( i.value, 10 ); } );
		}
		function addChip( id, title ) {
			id = parseInt( id, 10 );
			if ( ! id || selectedIds().indexOf( id ) !== -1 ) { return; }
			var chip  = document.createElement( 'span' );
			chip.className = 'lrn-coupon-scope__chip';
			var label = document.createElement( 'span' );
			label.textContent = title || ( '#' + id );
			var x = document.createElement( 'button' );
			x.type = 'button';
			x.className = 'lrn-coupon-scope__chip-x';
			x.setAttribute( 'aria-label', __( 'Remove', 'learnomy' ) );
			x.textContent = '×';
			x.addEventListener( 'click', function () { chip.remove(); } );
			var hidden = document.createElement( 'input' );
			hidden.type = 'hidden';
			hidden.name = 'items[]';
			hidden.value = String( id );
			chip.appendChild( label );
			chip.appendChild( x );
			chip.appendChild( hidden );
			chips.appendChild( chip );
		}
		function closeResults() {
			results.hidden = true;
			results.innerHTML = '';
			items = []; active = -1;
			input.setAttribute( 'aria-expanded', 'false' );
			input.removeAttribute( 'aria-activedescendant' );
		}
		function render( courses ) {
			var have = selectedIds();
			items = ( courses || [] ).filter( function ( c ) { return have.indexOf( parseInt( c.id, 10 ) ) === -1; } ).slice( 0, 8 );
			if ( ! items.length ) { closeResults(); return; }
			results.innerHTML = '';
			items.forEach( function ( c, i ) {
				var li = document.createElement( 'li' );
				li.setAttribute( 'role', 'option' );
				li.id = 'lrn-coupon-course-opt-' + i;
				li.className = 'lrn-coupon-scope__option';
				li.setAttribute( 'data-id', String( parseInt( c.id, 10 ) ) );
				li.textContent = c.title || ( '#' + c.id );
				results.appendChild( li );
			} );
			results.hidden = false;
			input.setAttribute( 'aria-expanded', 'true' );
			active = -1;
		}
		function search( q ) {
			fetch( base() + '/courses?search=' + encodeURIComponent( q ) + '&per_page=15', { credentials: 'same-origin', headers: headers() } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( j ) { render( Array.isArray( j.data ) ? j.data : [] ); } )
				.catch( function () { closeResults(); } );
		}
		function setActive( i ) {
			var opts = results.querySelectorAll( '[role="option"]' );
			Array.prototype.forEach.call( opts, function ( o ) { o.classList.remove( 'is-active' ); } );
			if ( i >= 0 && opts[ i ] ) { opts[ i ].classList.add( 'is-active' ); input.setAttribute( 'aria-activedescendant', opts[ i ].id ); }
			else { input.removeAttribute( 'aria-activedescendant' ); }
			active = i;
		}
		function pick( i ) {
			if ( i < 0 || ! items[ i ] ) { return; }
			addChip( items[ i ].id, items[ i ].title );
			input.value = '';
			closeResults();
		}
		input.addEventListener( 'input', function () {
			var q = input.value.trim();
			if ( timer ) { clearTimeout( timer ); }
			if ( q.length < 2 ) { closeResults(); return; }
			timer = setTimeout( function () { search( q ); }, 250 );
		} );
		input.addEventListener( 'keydown', function ( e ) {
			if ( results.hidden ) { return; }
			if ( 'ArrowDown' === e.key ) { e.preventDefault(); setActive( Math.min( active + 1, items.length - 1 ) ); }
			else if ( 'ArrowUp' === e.key ) { e.preventDefault(); setActive( Math.max( active - 1, 0 ) ); }
			else if ( 'Enter' === e.key && active >= 0 ) { e.preventDefault(); pick( active ); }
			else if ( 'Escape' === e.key ) { closeResults(); }
		} );
		results.addEventListener( 'mousedown', function ( e ) {
			var li = e.target.closest( '[role="option"]' );
			if ( ! li ) { return; }
			e.preventDefault();
			var id = parseInt( li.getAttribute( 'data-id' ), 10 );
			for ( var k = 0; k < items.length; k++ ) { if ( parseInt( items[ k ].id, 10 ) === id ) { pick( k ); return; } }
		} );
		document.addEventListener( 'click', function ( e ) { if ( ! picker.contains( e.target ) ) { closeResults(); } } );

		window.__lrnCouponScope = {
			clear: function () { chips.innerHTML = ''; input.value = ''; closeResults(); },
			setItems: function ( ids ) {
				chips.innerHTML = '';
				( ids || [] ).forEach( function ( id ) {
					id = parseInt( id, 10 );
					if ( ! id ) { return; }
					addChip( id, '#' + id ); // Placeholder until the title resolves.
					fetch( base() + '/courses/' + id, { credentials: 'same-origin', headers: headers() } )
						.then( function ( r ) { return r.json(); } )
						.then( function ( j ) {
							var t = j && j.data && j.data.title;
							if ( ! t ) { return; }
							var hidden = chips.querySelector( 'input[name="items[]"][value="' + id + '"]' );
							if ( hidden && hidden.parentNode ) { hidden.parentNode.querySelector( 'span' ).textContent = t; }
						} ).catch( function () {} );
				} );
			}
		};
	}() );

	// ── Form submission via AJAX ───────────────────────────────────

	document.querySelectorAll( 'form[data-ajax-action]' ).forEach( function ( form ) {
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();

			var action = form.getAttribute( 'data-ajax-action' );
			var btn    = form.querySelector( 'button[type="submit"], input[type="submit"]' );
			var data   = {};

			new FormData( form ).forEach( function ( value, key ) {
				if ( key === 'action' || key === 'nonce' ) {
					return;
				}
				// A key can repeat in FormData two different ways:
				//  1. A multi-value checkbox group named `foo[]` (e.g. tag_ids[])
				//     — accumulate those into an array.
				//  2. The hidden-input + checkbox pair that shares ONE plain name
				//     (e.g. a hidden `shuffle_questions=0` plus a checkbox
				//     `shuffle_questions=1`, so an unchecked box still submits a
				//     value). For that NON-`[]` pair the checkbox must OVERRIDE the
				//     hidden — take the last value. Accumulating it built ['0','1'],
				//     an array the REST API rejected with "not of type boolean".
				// Indexed keys (`answers[0]`, `slot_marks[123]`) are unique and hit
				// neither path.
				if ( Object.prototype.hasOwnProperty.call( data, key ) ) {
					if ( '[]' === key.slice( -2 ) ) {
						if ( ! Array.isArray( data[ key ] ) ) {
							data[ key ] = [ data[ key ] ];
						}
						data[ key ].push( value );
					} else {
						// hidden + checkbox pair → last (the checkbox) wins.
						data[ key ] = value;
					}
				} else {
					data[ key ] = value;
				}
			} );

			// A checkbox group with zero boxes checked emits NO FormData entry,
			// so the field disappears from `data` entirely. The server only
			// updates a field when it is present in the request, so "uncheck all
			// + save" silently kept the old value (e.g. webhook events could
			// never be cleared from the admin UI). Send an explicit empty array
			// for every `name="...[]"` checkbox field the form declares but that
			// ended up with nothing checked. Generic — covers events[], tag_ids[],
			// any multi-checkbox group.
			form.querySelectorAll( 'input[type="checkbox"][name$="[]"]' ).forEach( function ( cb ) {
				if ( ! Object.prototype.hasOwnProperty.call( data, cb.name ) ) {
					data[ cb.name ] = [];
				}
			} );

			sendAction( action, data, btn || form, function ( result ) {
				showToast.flash( result.message || 'Saved.', 'success' );

				// Honor a server-provided redirect so create actions can
				// send the user to the edit URL of the newly saved record.
				// Without this, editors reload with a blank form and the
				// save appears to have done nothing.
				if ( result && result.redirect ) {
					setTimeout( function () { window.location.href = result.redirect; }, 600 );
					return;
				}

				form.reset();
				setTimeout( function () { location.reload(); }, 800 );
			} );
		} );
	} );

} )();
