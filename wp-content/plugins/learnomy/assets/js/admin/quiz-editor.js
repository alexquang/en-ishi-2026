/**
 * Learnomy — Admin quiz editor.
 *
 * Three responsibilities:
 *
 *  1. Mirror the cooldown minutes input into the hidden seconds field so
 *     the instructor thinks in minutes but the DB stores seconds.
 *
 *  2. Mirror the minutes / seconds pair on the existing time-limit field
 *     (it was previously only wired up globally in admin.js).
 *
 *  3. Wire each slot marks input's `blur` event to a single-slot AJAX
 *     write. The instructor gets immediate feedback and never loses an
 *     edit to an accidental refresh.
 *
 * The rest of the form submits through the generic `data-ajax-action`
 * handler in actions.js — we deliberately don't re-implement that here.
 *
 * @package Learnomy
 */
( function () {
	'use strict';

	var form = document.querySelector( '.lrn-quiz-editor' );
	if ( ! form ) {
		return;
	}

	var admin = window.learnomyAdmin || {};
	var quizId = parseInt( form.getAttribute( 'data-quiz-id' ), 10 ) || 0;

	// ── Course → Section → Lesson cascade ─────────────────────────────
	// When the user changes the Course dropdown the Section dropdown was
	// staying disabled (and the Lesson dropdown after it) because the
	// editor only computed sections on initial PHP render. Wiring an
	// onchange handler that fetches the new sections via REST keeps the
	// cascade live (bug card "Section Dropdown Disabled in Quiz Settings").
	var courseSelect  = document.getElementById( 'lrn-quiz-course' );
	var sectionSelect = document.getElementById( 'lrn-quiz-section' );
	var lessonSelect  = document.getElementById( 'lrn-quiz-lesson' );
	// `admin.restUrl` is already the full namespace ("…/wp-json/learnomy/v1/")
	// when localized; only append the namespace when falling back to
	// `wpApiSettings.root` (which is just "…/wp-json/"). Without this
	// guard the cascade fired against "…/learnomy/v1/learnomy/v1/courses/…"
	// and 403'd, so the section dropdown stayed disabled.
	var restBase      = admin.restUrl || ( ( window.wpApiSettings && window.wpApiSettings.root ? window.wpApiSettings.root : '/wp-json/' ) + 'learnomy/v1/' );
	// `admin.restNonce` is the `wp_rest` nonce; `admin.nonce` is the
	// admin-AJAX nonce. WP REST validates against `wp_rest`, so the AJAX
	// nonce came back as `rest_cookie_invalid_nonce` 403 even though the
	// admin was logged in.
	var restNonce     = admin.restNonce || admin.nonce || ( window.wpApiSettings && window.wpApiSettings.nonce ) || '';

	function refillSelect( select, items, placeholder ) {
		// Drain existing options by removing children — never via innerHTML.
		while ( select.firstChild ) {
			select.removeChild( select.firstChild );
		}
		var blank = document.createElement( 'option' );
		blank.value = '';
		blank.textContent = placeholder;
		select.appendChild( blank );
		items.forEach( function ( item ) {
			var opt = document.createElement( 'option' );
			opt.value = item.id;
			opt.textContent = item.title;
			select.appendChild( opt );
		} );
	}

	function fetchJson( path ) {
		return fetch( restBase + path.replace( /^\//, '' ), {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': restNonce, Accept: 'application/json' },
		} ).then( function ( r ) { return r.json(); } );
	}

	if ( courseSelect && sectionSelect ) {
		courseSelect.addEventListener( 'change', function () {
			var courseId = parseInt( courseSelect.value, 10 ) || 0;

			if ( ! courseId ) {
				refillSelect( sectionSelect, [], '— Select a section —' );
				sectionSelect.disabled = true;
				if ( lessonSelect ) {
					refillSelect( lessonSelect, [], '— End of section —' );
					lessonSelect.disabled = true;
				}
				return;
			}

			sectionSelect.disabled = true; // brief disable while loading
			fetchJson( 'courses/' + courseId + '/sections' )
				.then( function ( body ) {
					var sections = ( body && Array.isArray( body.data ) ? body.data : body ) || [];
					refillSelect( sectionSelect, sections, '— Select a section —' );
					sectionSelect.disabled = sections.length === 0;
					if ( lessonSelect ) {
						refillSelect( lessonSelect, [], '— End of section —' );
						lessonSelect.disabled = true;
					}
				} )
				.catch( function () {
					sectionSelect.disabled = true;
				} );
		} );
	}

	if ( sectionSelect && lessonSelect ) {
		sectionSelect.addEventListener( 'change', function () {
			var sectionId = parseInt( sectionSelect.value, 10 ) || 0;
			if ( ! sectionId ) {
				refillSelect( lessonSelect, [], '— End of section —' );
				lessonSelect.disabled = true;
				return;
			}
			lessonSelect.disabled = true;
			fetchJson( 'lessons?section_id=' + sectionId )
				.then( function ( body ) {
					var lessons = ( body && Array.isArray( body.data ) ? body.data : body ) || [];
					refillSelect( lessonSelect, lessons, '— End of section —' );
					lessonSelect.disabled = lessons.length === 0;
				} )
				.catch( function () {
					lessonSelect.disabled = true;
				} );
		} );
	}

	// ── Collapsible settings sections — click header to toggle .is-open
	form.querySelectorAll( '.lrn-settings-section__header' ).forEach( function ( header ) {
		header.addEventListener( 'click', function () {
			var section = header.closest( '.lrn-settings-section' );
			if ( ! section ) {
				return;
			}
			var isOpen = section.classList.toggle( 'is-open' );
			header.setAttribute( 'aria-expanded', isOpen ? 'true' : 'false' );
		} );
	} );

	// ── Range slider live value readout (passing score) ──────────────
	form.querySelectorAll( 'input[type="range"][data-lrn-range-output]' ).forEach( function ( range ) {
		var outputId = range.getAttribute( 'data-lrn-range-output' );
		var output   = document.getElementById( outputId );
		if ( ! output ) {
			return;
		}
		function sync() {
			output.textContent = range.value + '%';
		}
		range.addEventListener( 'input', sync );
		sync();
	} );

	// ── Cooldown: minutes input ⇆ hidden seconds column ──────────────
	var cooldownMins = document.getElementById( 'lrn-quiz-cooldown-mins' );
	var cooldownSecs = document.getElementById( 'lrn-quiz-cooldown-seconds' );
	if ( cooldownMins && cooldownSecs ) {
		cooldownMins.addEventListener( 'input', function () {
			var mins = Math.max( 0, parseInt( cooldownMins.value, 10 ) || 0 );
			cooldownSecs.value = String( mins * 60 );
		} );
	}

	// ── Time limit: minutes + seconds inputs ⇆ hidden total-seconds ──
	var tlMins = document.getElementById( 'lrn-quiz-time-limit-mins' );
	var tlSecs = document.getElementById( 'lrn-quiz-time-limit-secs' );
	var tlTotal = document.getElementById( 'lrn-quiz-time-limit-seconds' );
	if ( tlMins && tlSecs && tlTotal ) {
		var syncTimeLimit = function () {
			var m = Math.max( 0, parseInt( tlMins.value, 10 ) || 0 );
			var s = Math.max( 0, Math.min( 59, parseInt( tlSecs.value, 10 ) || 0 ) );
			tlTotal.value = String( ( m * 60 ) + s );
		};
		tlMins.addEventListener( 'input', syncTimeLimit );
		tlSecs.addEventListener( 'input', syncTimeLimit );
	}

	// ── Template picker visual state ─────────────────────────────────
	var templateRadios = form.querySelectorAll( '.lrn-template-picker input[type="radio"]' );
	templateRadios.forEach( function ( radio ) {
		radio.addEventListener( 'change', function () {
			form.querySelectorAll( '.lrn-template-card' ).forEach( function ( card ) {
				card.classList.remove( 'is-active' );
			} );
			if ( radio.checked ) {
				var card = radio.closest( '.lrn-template-card' );
				if ( card ) {
					card.classList.add( 'is-active' );
				}
			}
		} );
	} );

	// ── Inline slot marks save on blur ───────────────────────────────
	//
	// The generic form submit will include slot_marks[] in the same POST
	// (it's in the <form> scope). But instructors often adjust one slot
	// and walk away; the blur handler gives them confirmation the value
	// was saved, even if they never click "Update Quiz".

	var slotInputs = form.querySelectorAll( '.lrn-slot-marks' );
	slotInputs.forEach( function ( input ) {
		var statusEl = input.parentElement.querySelector( '.lrn-slot-marks__status' );
		var lastSent = input.value;

		input.addEventListener( 'blur', function () {
			if ( input.value === lastSent ) {
				return;
			}
			if ( ! quizId ) {
				return;
			}
			var slotId = parseInt( input.getAttribute( 'data-slot-id' ), 10 ) || 0;
			if ( ! slotId ) {
				return;
			}

			setStatus( statusEl, 'saving' );
			input.setAttribute( 'aria-busy', 'true' );

			// REST PATCH /quiz-slots/{id} — replaces the legacy admin-ajax
			// learnomy_update_slot_marks handler. Cookie auth + X-WP-Nonce, the
			// slot endpoint validates parent-quiz manage permissions.
			var rest = ( admin && admin.restUrl ) ? admin.restUrl : '/wp-json/learnomy/v1/';
			fetch( rest + 'quiz-slots/' + slotId, {
				method:      'PATCH',
				credentials: 'same-origin',
				headers:     {
					'Content-Type': 'application/json',
					'X-WP-Nonce':   admin.restNonce || '',
				},
				body: JSON.stringify( { marks: parseFloat( input.value ) || 0 } ),
			} )
				.then( function ( resp ) {
					return resp.json().then( function ( json ) {
						if ( ! resp.ok ) {
							var msg = ( json.error && json.error.message ) || json.message || window.wp.i18n.__( 'Save failed', 'learnomy' );
							throw new Error( msg );
						}
						return json;
					} );
				} )
				.then( function ( result ) {
					input.removeAttribute( 'aria-busy' );
					lastSent = input.value;
					if ( result && result.data && typeof result.data.marks !== 'undefined' ) {
						input.value = String( result.data.marks );
					}
					setStatus( statusEl, 'saved' );
				} )
				.catch( function ( err ) {
					input.removeAttribute( 'aria-busy' );
					setStatus( statusEl, 'error', err && err.message ? err.message : '' );
				} );
		} );
	} );

	// ── Slot drag-drop reorder ──────────────────────────────────────
	//
	// Same pattern as course-builder's lesson DnD: HTML5 draggable on each
	// <li class="lrn-quiz-slot">, visual drop-zone indicators, optimistic
	// DOM move with rollback on REST error. The persistence endpoint is
	// `POST /quizzes/:id/slots/reorder { order: [slot_id] }`.

	( function wireSlotDragDrop() {
		var slotsList = form.querySelector( '.lrn-quiz-slot-list' );
		if ( ! slotsList || ! quizId ) {
			return;
		}

		// Slot rows are already rendered with draggable="true" + a
		// .lrn-quiz-slot__drag-handle by the view. This block is a safety
		// net for any future DOM surgery (e.g. the picker modal appending
		// new rows) that forgets to set the attribute.
		var slots = slotsList.querySelectorAll( '.lrn-quiz-slot' );
		slots.forEach( function ( li ) {
			if ( ! li.hasAttribute( 'draggable' ) ) {
				li.setAttribute( 'draggable', 'true' );
			}
			if ( ! li.querySelector( '.lrn-quiz-slot__drag-handle' ) ) {
				var handle = document.createElement( 'span' );
				handle.className   = 'lrn-quiz-slot__drag-handle';
				handle.setAttribute( 'aria-hidden', 'true' );
				handle.title       = ( window.wp && window.wp.i18n )
					? window.wp.i18n.__( 'Drag to reorder', 'learnomy' )
					: 'Drag to reorder';
				handle.innerHTML   = '<i data-lucide="grip-vertical"></i>';
				// lucide-live.js paints the drag handle's icon (Basecamp 10184590411).
				li.insertBefore( handle, li.firstChild );
			}
		} );

		var dragged = null;
		var origNext = null;
		var rafId = 0;

		slotsList.addEventListener( 'dragstart', function ( e ) {
			var li = e.target.closest && e.target.closest( '.lrn-quiz-slot' );
			if ( ! li ) {
				return;
			}
			dragged  = li;
			origNext = li.nextElementSibling;
			li.classList.add( 'is-dragging' );
			if ( e.dataTransfer ) {
				e.dataTransfer.effectAllowed = 'move';
				try {
					e.dataTransfer.setData( 'text/plain', li.getAttribute( 'data-slot-id' ) || '' );
				} catch ( ignore ) {
					// noop — setData can throw in some browsers during protected drags.
				}
			}
		} );

		slotsList.addEventListener( 'dragend', function () {
			if ( dragged ) {
				dragged.classList.remove( 'is-dragging' );
			}
			clearZones();
			dragged  = null;
			origNext = null;
		} );

		slotsList.addEventListener( 'dragover', function ( e ) {
			if ( ! dragged ) {
				return;
			}
			e.preventDefault();
			if ( e.dataTransfer ) {
				e.dataTransfer.dropEffect = 'move';
			}
			if ( rafId ) {
				return;
			}
			rafId = window.requestAnimationFrame( function () {
				rafId = 0;
				paintZone( e.clientY, e.target );
			} );
		} );

		slotsList.addEventListener( 'drop', function ( e ) {
			if ( ! dragged ) {
				return;
			}
			e.preventDefault();

			var row = e.target.closest && e.target.closest( '.lrn-quiz-slot' );
			if ( row && row !== dragged ) {
				var rect  = row.getBoundingClientRect();
				var after = e.clientY > ( rect.top + rect.height / 2 );
				if ( after ) {
					slotsList.insertBefore( dragged, row.nextSibling );
				} else {
					slotsList.insertBefore( dragged, row );
				}
			}
			clearZones();

			var order = [];
			slotsList.querySelectorAll( '.lrn-quiz-slot' ).forEach( function ( el ) {
				var sid = parseInt( el.getAttribute( 'data-slot-id' ), 10 );
				if ( sid ) {
					order.push( sid );
				}
			} );

			var undoSnapshot = (function ( el, next ) {
				return function () {
					if ( next && next.parentNode === slotsList ) {
						slotsList.insertBefore( el, next );
					} else {
						slotsList.appendChild( el );
					}
				};
			})( dragged, origNext );

			persistSlotOrder( order ).catch( function ( err ) {
				undoSnapshot();
				notify( ( err && err.message ) || window.wp.i18n.__( 'Could not save slot order.', 'learnomy' ), true );
			} );
		} );

		function paintZone( clientY, raw ) {
			clearZones();
			var row = raw && raw.closest ? raw.closest( '.lrn-quiz-slot' ) : null;
			if ( ! row || row === dragged ) {
				return;
			}
			var rect  = row.getBoundingClientRect();
			var after = clientY > ( rect.top + rect.height / 2 );
			row.classList.add( after ? 'drop-zone-active--after' : 'drop-zone-active--before' );
		}

		function clearZones() {
			slotsList.querySelectorAll( '.drop-zone-active--before, .drop-zone-active--after' ).forEach( function ( el ) {
				el.classList.remove( 'drop-zone-active--before' );
				el.classList.remove( 'drop-zone-active--after' );
			} );
		}

		function persistSlotOrder( order ) {
			var rest = ( admin && admin.restUrl ) ? admin.restUrl : '/wp-json/learnomy/v1/';
			return fetch( rest + 'quizzes/' + quizId + '/slots/reorder', {
				method:      'POST',
				credentials: 'same-origin',
				headers:     {
					'Content-Type': 'application/json',
					'X-WP-Nonce':   admin.restNonce || admin.nonce || '',
				},
				body: JSON.stringify( { order: order } ),
			} ).then( function ( resp ) {
				return resp.json().then( function ( json ) {
					if ( ! resp.ok ) {
						var msg = ( json.error && json.error.message ) || json.message || window.wp.i18n.__( 'Request failed', 'learnomy' );
						throw new Error( msg );
					}
					return json;
				} );
			} );
		}

		function notify( message, isError ) {
			var toast = document.getElementById( 'lrn-quiz-toast' );
			if ( toast ) {
				toast.textContent = message;
				toast.className = 'lrn-quiz-toast is-visible' + ( isError ? ' lrn-quiz-toast--error' : '' );
				setTimeout( function () { toast.classList.remove( 'is-visible' ); }, 3000 );
			} else if ( isError && window.console ) {
				window.console.warn( message );
			}
		}
	} )();

	function setStatus( el, state, message ) {
		if ( ! el ) {
			return;
		}
		el.classList.remove( 'is-saving', 'is-saved', 'is-error' );
		switch ( state ) {
			case 'saving':
				el.classList.add( 'is-saving' );
				el.textContent = window.wp && window.wp.i18n ? window.wp.i18n.__( 'Saving…', 'learnomy' ) : 'Saving…';
				break;
			case 'saved':
				el.classList.add( 'is-saved' );
				el.textContent = window.wp && window.wp.i18n ? window.wp.i18n.__( 'Saved', 'learnomy' ) : 'Saved';
				setTimeout( function () {
					if ( el.classList.contains( 'is-saved' ) ) {
						el.textContent = '';
						el.classList.remove( 'is-saved' );
					}
				}, 1800 );
				break;
			case 'error':
				el.classList.add( 'is-error' );
				el.textContent = message || ( window.wp && window.wp.i18n ? window.wp.i18n.__( 'Save failed', 'learnomy' ) : 'Save failed' );
				break;
			default:
				el.textContent = '';
		}
	}

	// ── Delete quiz ──────────────────────────────────────────────────
	// Confirm-gated so the destructive action can never fire without an
	// explicit confirmation, then DELETE /quizzes/{id} and return to the
	// list (we can't stay on the editor of a quiz that no longer exists).
	( function wireDeleteQuiz() {
		var deleteBtn = document.getElementById( 'lrn-delete-quiz' );
		if ( ! deleteBtn || ! quizId ) {
			return;
		}
		deleteBtn.addEventListener( 'click', function () {
			if ( typeof window.lrnConfirm !== 'function' ) {
				return;
			}
			window.lrnConfirm( {
				title:       window.wp && window.wp.i18n ? window.wp.i18n.__( 'Delete this quiz?', 'learnomy' ) : 'Delete this quiz?',
				message:     window.wp && window.wp.i18n ? window.wp.i18n.__( 'This permanently removes the quiz, its question slots and every attempt. This cannot be undone.', 'learnomy' ) : 'This permanently removes the quiz, its question slots and every attempt. This cannot be undone.',
				destructive: true,
			} ).then( function ( ok ) {
				if ( ! ok ) {
					return;
				}
				deleteBtn.disabled = true;
				fetch( restBase + 'quizzes/' + quizId, {
					method:  'DELETE',
					headers: { 'X-WP-Nonce': restNonce },
				} ).then( function ( r ) {
					if ( ! r.ok ) {
						throw new Error( 'delete failed' );
					}
					window.location.href = 'admin.php?page=learnomy-quizzes';
				} ).catch( function () {
					deleteBtn.disabled = false;
					notifyGlobal( window.wp && window.wp.i18n ? window.wp.i18n.__( 'Could not delete the quiz. Please try again.', 'learnomy' ) : 'Could not delete the quiz. Please try again.' );
				} );
			} );
		} );

		function notifyGlobal( message ) {
			var toast = document.getElementById( 'lrn-quiz-toast' );
			if ( toast ) {
				toast.textContent = message;
				toast.className = 'lrn-quiz-toast is-visible lrn-quiz-toast--error';
				setTimeout( function () { toast.classList.remove( 'is-visible' ); }, 3000 );
			} else if ( window.console ) {
				window.console.warn( message );
			}
		}
	} )();

	// ── Description rich-text (Editor.js) ────────────────────────────
	// The quiz form submits through the shared actions.js handler, so this
	// mirrors course-description-editor.js: mount on the host, seed from the
	// hidden #lrn-quiz-description input, and on submit serialize the blocks
	// back into that hidden input BEFORE actions.js reads the form
	// (capture-phase + once-guard + re-fire). Legacy plain/HTML descriptions
	// are wrapped into a paragraph block; render_editorjs_content() renders
	// both shapes on the front, so existing quizzes keep working.
	( function wireDescriptionEditor() {
		var host   = document.getElementById( 'lrn-quiz-desc-editor' );
		var hidden = document.getElementById( 'lrn-quiz-description' );
		if ( ! host || ! hidden || 'undefined' === typeof EditorJS ) {
			return;
		}

		function seedData( raw ) {
			if ( ! raw ) { return { blocks: [] }; }
			try {
				var parsed = JSON.parse( raw );
				if ( parsed && Array.isArray( parsed.blocks ) ) { return parsed; }
			} catch ( e ) { /* legacy HTML/text */ }
			return { blocks: [ { type: 'paragraph', data: { text: raw } } ] };
		}

		// Uniform tool set — the SAME shared factory the course Description and
		// lesson Content editors use, so the quiz Description carries the exact
		// same blocks (image, table, attachments, math, raw HTML, link preview…)
		// and the open `window.learnomyEditorTools` extension seam, instead of
		// the hand-rolled subset this used to keep (no image/table/math here
		// before, and it drifted from the canonical set).
		var tools = ( 'function' === typeof window.lrnEditorTools )
			? window.lrnEditorTools( { restUrl: restBase, nonce: restNonce } )
			: {};

		var editor = new EditorJS( {
			holder:      host,
			tools:       tools,
			data:        seedData( hidden.value ),
			minHeight:   80,
			placeholder: host.getAttribute( 'data-placeholder' ) || '',
		} );

		form.addEventListener( 'submit', function ( e ) {
			if ( '1' === form.dataset.lrnDescSynced ) {
				form.dataset.lrnDescSynced = '';
				return;
			}
			e.preventDefault();
			e.stopImmediatePropagation();
			editor.save().then( function ( output ) {
				hidden.value = JSON.stringify( output );
			} ).catch( function () { /* keep prior value */ } ).then( function () {
				form.dataset.lrnDescSynced = '1';
				// Defer the re-submit to a macrotask. We're still inside the
				// original submit's promise continuation, where the form's
				// in-flight-submission guard is set — calling requestSubmit()
				// now is a silent no-op (it never dispatches), so the quiz never
				// saved. A 0ms timeout lets that guard clear first.
				setTimeout( function () {
					if ( typeof form.requestSubmit === 'function' ) {
						form.requestSubmit();
					} else {
						form.submit();
					}
				}, 0 );
			} );
		}, true );
	} )();

} )();
