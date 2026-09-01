/**
 * Learnomy - Question bank list + quiz question picker.
 *
 * Handles three concerns:
 *   1. CSV import/export buttons on the bank list page.
 *   2. Row-action "Duplicate" button (piggy-backs on actions.js).
 *   3. The quiz editor's #lrn-question-picker-modal - populates filters
 *      from QuestionTypes + categories + difficulty, paginates against
 *      /wp-json/learnomy/v1/questions, marks already-attached rows with
 *      an "Already in quiz" badge.
 *
 * DOM is built via createElement() + textContent throughout so pasting
 * HTML-ish strings into a question title cannot introduce script injection.
 *
 * @package Learnomy
 */
( function () {
	'use strict';

	var __      = wp.i18n.__;
	var _n      = wp.i18n._n;
	var sprintf = wp.i18n.sprintf;

	var cfg       = window.learnomyQuestionBank || {};
	var admin     = window.learnomyAdmin || {};
	var restUrl   = admin.restUrl || '/wp-json/learnomy/v1/';
	var restNonce = admin.restNonce || '';
	var i18n      = cfg.i18n || {};

	// Delegates to the shared toast (`assets/js/shared/toast.js`), enqueued on
	// every Learnomy admin screen.
	//
	// The third copy of a hand-rolled `lrn-admin-toast`, a class no stylesheet
	// defines — see the note in `question-editor.js` (Basecamp 10185990987).
	function showToast( message, kind ) {
		window.lrnToast( message, kind || 'success' );
	}

	// For any path that navigates afterwards — see `lrnToast.flash()`.
	showToast.flash = function ( message, kind ) {
		window.lrnToast.flash( message, kind || 'success' );
	};

	function el( tag, attrs, text ) {
		var node = document.createElement( tag );
		if ( attrs ) {
			Object.keys( attrs ).forEach( function ( k ) {
				if ( attrs[ k ] === null || attrs[ k ] === undefined || attrs[ k ] === false ) { return; }
				if ( k === 'className' ) { node.className = attrs[ k ]; return; }
				if ( k === 'checked' ) { node.checked = !! attrs[ k ]; return; }
				if ( k === 'disabled' ) { node.disabled = !! attrs[ k ]; return; }
				node.setAttribute( k, attrs[ k ] );
			} );
		}
		if ( text !== undefined && text !== null ) { node.textContent = text; }
		return node;
	}

	// ───────────────────────────────────────────────────────────────
	// CSV export / import — bank list page
	// ───────────────────────────────────────────────────────────────

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '#lrn-questions-export' );
		if ( ! btn ) { return; }
		e.preventDefault();

		// REST GET /questions/export — endpoint streams text/csv with
		// attachment headers, so we hand the browser the URL and let the
		// download flow run natively. Forward whatever filter params are
		// currently in the page URL so exports match the admin's view.
		var params = new URLSearchParams( window.location.search );
		var qs     = new URLSearchParams();
		qs.set( '_wpnonce', restNonce );
		[ 'category_id', 'type', 'difficulty', 'status' ].forEach( function ( name ) {
			var v = params.get( name );
			if ( v ) { qs.set( name, v ); }
		} );

		showToast( i18n.exporting || __( 'Preparing download…', 'learnomy' ), 'success' );

		var url  = restUrl + 'questions/export?' + qs.toString();
		var link = document.createElement( 'a' );
		link.href = url;
		link.download = 'questions.csv'; // server's Content-Disposition wins anyway
		document.body.appendChild( link );
		link.click();
		document.body.removeChild( link );
	} );

	// Import modal open / close.
	document.addEventListener( 'click', function ( e ) {
		var opener = e.target.closest( '#lrn-questions-import' );
		if ( opener ) {
			e.preventDefault();
			var modal = document.getElementById( 'lrn-csv-import-modal' );
			if ( modal ) { modal.classList.remove( 'lrn-modal--hidden' ); }
			return;
		}
		var closer = e.target.closest( '[data-lrn-modal-close]' );
		if ( closer ) {
			var m = closer.closest( '.lrn-modal' );
			if ( m ) { m.classList.add( 'lrn-modal--hidden' ); }
		}
	} );

	document.addEventListener( 'click', function ( e ) {
		var submit = e.target.closest( '#lrn-csv-import-submit' );
		if ( ! submit ) { return; }
		e.preventDefault();

		var form = document.getElementById( 'lrn-csv-import-form' );
		var resultBox = document.getElementById( 'lrn-csv-import-result' );
		if ( ! form ) { return; }

		var fileInput = form.querySelector( 'input[type="file"]' );
		if ( ! fileInput || ! fileInput.files || ! fileInput.files[0] ) {
			showToast( i18n.selectFile || __( 'Choose a CSV file.', 'learnomy' ), 'error' );
			return;
		}

		submit.disabled = true;

		// REST POST /questions/import takes the CSV body in a `csv` JSON
		// field. Read the file as text first; the endpoint accepts the
		// raw CSV string rather than multipart so it composes with the
		// REST framing layer.
		var reader = new FileReader();
		reader.onload = function ( ev ) {
			fetch( restUrl + 'questions/import', {
				method:      'POST',
				credentials: 'same-origin',
				headers:     {
					'Content-Type': 'application/json',
					'X-WP-Nonce':   restNonce,
				},
				body: JSON.stringify( { csv: String( ev.target.result || '' ) } ),
			} )
				.then( function ( r ) {
					return r.json().then( function ( body ) { return { ok: r.ok, body: body }; } );
				} )
				.then( function ( out ) {
					submit.disabled = false;
					if ( ! out.ok ) {
						var msg = ( out.body && out.body.error && out.body.error.message ) || __( 'Import failed.', 'learnomy' );
						showToast( msg, 'error' );
						return;
					}
					var data     = ( out.body && out.body.data ) || {};
					var imported = data.imported || 0;
					var skipped  = data.skipped || 0;
					/* translators: 1: number imported, 2: number skipped */
					showToast( sprintf( __( 'Imported %1$d questions. Skipped %2$d.', 'learnomy' ), imported, skipped ), 'success' );

					if ( resultBox ) {
						resultBox.hidden      = false;
						resultBox.textContent = '';
						resultBox.appendChild( el( 'p', {}, ( i18n.importResult || 'Imported %1$d, skipped %2$d.' )
							.replace( '%1$d', String( imported ) )
							.replace( '%2$d', String( skipped ) )
						) );
						if ( Array.isArray( data.errors ) && data.errors.length ) {
							var ul = el( 'ul', { className: 'lrn-csv-errors' } );
							data.errors.forEach( function ( msg ) {
								ul.appendChild( el( 'li', {}, msg ) );
							} );
							resultBox.appendChild( ul );
						}
					}

					// Reload list after a short beat so the newly imported rows show.
					setTimeout( function () { window.location.reload(); }, 1800 );
				} )
				.catch( function () {
					submit.disabled = false;
					showToast( __( 'Network error.', 'learnomy' ), 'error' );
				} );
		};
		reader.onerror = function () {
			submit.disabled = false;
			showToast( __( 'Could not read the CSV file.', 'learnomy' ), 'error' );
		};
		reader.readAsText( fileInput.files[0] );
	} );

	// ───────────────────────────────────────────────────────────────
	// Quiz editor question picker
	// ───────────────────────────────────────────────────────────────

	var pickerModal = document.getElementById( 'lrn-question-picker-modal' );
	if ( pickerModal ) { initPicker( pickerModal ); }

	function initPicker( modal ) {
		// Fall back to `learnomyAdmin` (always localized) when neither the
		// quiz-editor nor course-builder shim is on the page. WP REST
		// validates `wp_rest` nonces — `restNonce`, not the AJAX `nonce`.
		var restCfg   = window.learnomyQuizEditor || window.learnomyBuilder || window.learnomyAdmin || {};
		var restUrl   = restCfg.restUrl || '/wp-json/learnomy/v1/';
		var restNonce = restCfg.restNonce || restCfg.nonce || '';

		var listEl    = modal.querySelector( '#lrn-picker-list' );
		var emptyEl   = modal.querySelector( '#lrn-picker-empty' );
		var pagerEl   = modal.querySelector( '#lrn-picker-pagination' );
		var filtersEl = modal.querySelector( '.lrn-question-picker-filters' );
		var typeSel   = modal.querySelector( '#lrn-picker-type' );
		var searchEl  = modal.querySelector( '#lrn-picker-search' );
		var catSel;
		var diffSel;

		// Collect IDs already slotted into this quiz so we can badge duplicates.
		var existingIds = readExistingSlotIds();

		// Populate type dropdown from QuestionTypes.
		var qTypes = restCfg.questionTypes || {};
		Object.keys( qTypes ).forEach( function ( slug ) {
			typeSel.appendChild( el( 'option', { value: slug }, qTypes[ slug ] ) );
		} );

		// Inject category + difficulty selects alongside existing controls.
		catSel = el( 'select', { id: 'lrn-picker-category' } );
		catSel.appendChild( el( 'option', { value: '' }, __( 'All categories', 'learnomy' ) ) );
		( restCfg.questionCategories || [] ).forEach( function ( c ) {
			catSel.appendChild( el( 'option', { value: c.id }, c.name ) );
		} );
		filtersEl.appendChild( catSel );

		diffSel = el( 'select', { id: 'lrn-picker-difficulty' } );
		diffSel.appendChild( el( 'option', { value: '' }, __( 'Any difficulty', 'learnomy' ) ) );
		[ 1, 2, 3, 4, 5 ].forEach( function ( n ) {
			/* translators: %d: difficulty level number */
			diffSel.appendChild( el( 'option', { value: n }, sprintf( __( 'Level %d', 'learnomy' ), n ) ) );
		} );
		filtersEl.appendChild( diffSel );

		var page = 1;
		var perPage = 20;

		[ typeSel, catSel, diffSel ].forEach( function ( c ) {
			c.addEventListener( 'change', function () { page = 1; load(); } );
		} );
		if ( searchEl ) {
			var debounced;
			searchEl.addEventListener( 'input', function () {
				clearTimeout( debounced );
				debounced = setTimeout( function () { page = 1; load(); }, 300 );
			} );
		}

		// Reload when the modal opens so newly-created questions show up
		// without needing to refresh the page. The picker mounts in markup
		// with `lrn-modal--hidden` so it doesn't flash on load — the click
		// handler is the only place that pulls it off.
		document.addEventListener( 'click', function ( e ) {
			if ( e.target.closest( '#lrn-open-question-picker' ) ) {
				modal.classList.remove( 'lrn-modal--hidden' );
				modal.removeAttribute( 'hidden' );
				setTimeout( load, 80 );
			}
		} );

		// Close handlers — the modal's own close button + backdrop click
		// + Escape key. Without these the click handler above could only
		// open the modal, leaving no way to dismiss it.
		modal.querySelectorAll( '[data-lrn-modal-close]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', closeModal );
		} );
		modal.addEventListener( 'click', function ( e ) {
			if ( e.target === modal ) { closeModal(); }
		} );
		document.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' && ! modal.classList.contains( 'lrn-modal--hidden' ) ) {
				closeModal();
			}
		} );

		// Add-selected: attach each ticked question to the quiz via POST
		// /quizzes/{id}/questions with { question_id }. The server's attach
		// handler returns the updated questions list; we reload the page so
		// the server-rendered slot list refreshes with the new rows in the
		// correct sort_order (the slot list is PHP-rendered, not client-side).
		var addBtn = modal.querySelector( '#lrn-picker-add' );
		if ( addBtn ) {
			addBtn.addEventListener( 'click', function () {
				var checked = modal.querySelectorAll( '.lrn-picker-item__check:checked' );
				if ( ! checked.length ) {
					showToast( __( 'Select at least one question.', 'learnomy' ), 'error' );
					return;
				}
				var ids = Array.prototype.map.call( checked, function ( c ) { return parseInt( c.value, 10 ); } );

				var quizId = readQuizId();
				if ( quizId ) {
					attachSelected( quizId, ids );
					return;
				}

				// New (unsaved) quiz: auto-save it first so questions can be added
				// DURING creation, no save-and-reopen round trip (BC #9905191822).
				var form    = document.querySelector( 'form[data-quiz-id]' );
				var titleEl = form && form.querySelector( '[name="title"]' );
				var title   = titleEl ? titleEl.value.trim() : '';
				if ( ! title ) {
					showToast( __( 'Name the quiz first, then add questions.', 'learnomy' ), 'error' );
					if ( titleEl ) { titleEl.focus(); }
					return;
				}

				addBtn.disabled = true;
				createQuizFromForm( form ).then( function ( newId ) {
					if ( ! newId ) {
						addBtn.disabled = false;
						showToast( __( 'Could not save the quiz. Please try again.', 'learnomy' ), 'error' );
						return;
					}
					attachSelected( newId, ids );
				} ).catch( function () {
					addBtn.disabled = false;
					showToast( __( 'Network error while saving the quiz.', 'learnomy' ), 'error' );
				} );
			} );
		}

		// Attach the chosen question ids to a saved quiz, then reload so the
		// server-rendered slot list shows them in the right sort order.
		function attachSelected( quizId, ids ) {
			addBtn.disabled = true;
			Promise.all( ids.map( function ( id ) {
				return fetch( restUrl + 'quizzes/' + quizId + '/questions', {
					method:      'POST',
					credentials: 'same-origin',
					headers:     { 'Content-Type': 'application/json', 'X-WP-Nonce': restNonce },
					body: JSON.stringify( { question_id: id } ),
				} ).then( function ( r ) { return r.ok; } );
			} ) ).then( function ( results ) {
				var ok = results.filter( Boolean ).length;
				if ( ok === ids.length ) {
					closeModal();
					window.location.reload();
				} else {
					addBtn.disabled = false;
					/* translators: 1: number attached, 2: total selected */
					showToast( sprintf( __( 'Attached %1$d of %2$d questions.', 'learnomy' ), ok, ids.length ), 'error' );
				}
			} ).catch( function () {
				addBtn.disabled = false;
				showToast( __( 'Network error while attaching questions.', 'learnomy' ), 'error' );
			} );
		}

		// Create the quiz from the current editor form so the picker can attach
		// to it right away (BC #9905191822). Flips the page into edit mode on
		// success (data-quiz-id + hidden quiz_id + URL) so a later save updates
		// the same quiz and a reload keeps it.
		function createQuizFromForm( form ) {
			var fd      = new FormData( form );
			var payload = {};
			[ 'title', 'course_id', 'section_id', 'lesson_id', 'mode', 'passing_score',
				'grading_method', 'attempt_limit', 'shuffle_questions', 'shuffle_answers',
				'group_by_category', 'navigation', 'feedback_timing', 'display_template',
				'time_limit' ].forEach( function ( k ) {
				if ( fd.has( k ) ) { payload[ k ] = fd.get( k ); }
			} );

			return fetch( restUrl + 'quizzes', {
				method:      'POST',
				credentials: 'same-origin',
				headers:     { 'Content-Type': 'application/json', 'X-WP-Nonce': restNonce },
				body: JSON.stringify( payload ),
			} ).then( function ( r ) { return r.json(); } ).then( function ( res ) {
				var id = ( res && res.data && res.data.id ) ? parseInt( res.data.id, 10 ) : 0;
				if ( ! id ) { return 0; }

				form.setAttribute( 'data-quiz-id', id );
				form.setAttribute( 'data-ajax-action', 'learnomy_update_quiz' );
				var slotsC = document.getElementById( 'lrn-quiz-slots' );
				if ( slotsC ) { slotsC.setAttribute( 'data-quiz-id', id ); }
				var hidden = form.querySelector( 'input[name="quiz_id"]' );
				if ( ! hidden ) {
					hidden = document.createElement( 'input' );
					hidden.type = 'hidden';
					hidden.name = 'quiz_id';
					form.appendChild( hidden );
				}
				hidden.value = String( id );
				try {
					window.history.replaceState( {}, '', 'admin.php?page=learnomy-edit-quiz&quiz_id=' + id );
				} catch ( e ) { /* history blocked — the post-attach reload still lands right */ }
				return id;
			} );
		}

		function readQuizId() {
			// The quiz editor's <form> carries data-quiz-id; fall back to a
			// localized config object so this stays decoupled from markup.
			var form = document.querySelector( 'form[data-quiz-id]' );
			if ( form ) {
				var id = parseInt( form.getAttribute( 'data-quiz-id' ), 10 );
				if ( id ) { return id; }
			}
			return parseInt( ( restCfg && restCfg.quizId ) || 0, 10 ) || 0;
		}

		function closeModal() {
			modal.classList.add( 'lrn-modal--hidden' );
		}

		function readExistingSlotIds() {
			var ids = {};
			document.querySelectorAll( '[data-quiz-slot-question-id]' ).forEach( function ( n ) {
				var id = parseInt( n.getAttribute( 'data-quiz-slot-question-id' ), 10 );
				if ( ! isNaN( id ) ) { ids[ id ] = true; }
			} );
			return ids;
		}

		function load() {
			var params = new URLSearchParams();
			params.set( 'per_page', perPage );
			params.set( 'page', page );
			if ( typeSel.value ) { params.set( 'type', typeSel.value ); }
			if ( catSel.value )  { params.set( 'category_id', catSel.value ); }
			if ( diffSel.value ) { params.set( 'difficulty', diffSel.value ); }
			if ( searchEl && searchEl.value ) { params.set( 'search', searchEl.value ); }

			fetch( restUrl + 'questions?' + params.toString(), {
				headers: { 'X-WP-Nonce': restNonce, Accept: 'application/json' },
				credentials: 'same-origin'
			} )
				.then( function ( r ) { return r.json(); } )
				.then( function ( body ) {
					var items = ( body && body.data ) || [];
					renderList( items );
					renderPager( body );
				} )
				.catch( function () {
					if ( listEl ) {
						listEl.textContent = '';
						listEl.appendChild( el( 'li', { className: 'lrn-muted' }, __( 'Failed to load questions.', 'learnomy' ) ) );
					}
				} );
		}

		function renderList( items ) {
			if ( ! listEl ) { return; }
			listEl.textContent = '';

			if ( ! items.length ) {
				if ( emptyEl ) { emptyEl.classList.remove( 'lrn-hidden' ); }
				return;
			}
			if ( emptyEl ) { emptyEl.classList.add( 'lrn-hidden' ); }

			items.forEach( function ( q ) {
				var li = el( 'li', { className: 'lrn-picker-item' } );
				var label = el( 'label', { className: 'lrn-picker-item__label' } );
				var alreadyIn = !! existingIds[ q.id ];

				var cb = el( 'input', {
					type: 'checkbox',
					value: q.id,
					className: 'lrn-picker-item__check',
					disabled: alreadyIn
				} );
				label.appendChild( cb );

				var meta = el( 'span', { className: 'lrn-picker-item__meta' } );
				meta.appendChild( el( 'strong', {}, q.title || '(untitled)' ) );
				meta.appendChild( el( 'span', { className: 'lrn-picker-item__type' }, q.type || '' ) );
				if ( alreadyIn ) {
					meta.appendChild( el( 'span', { className: 'lrn-picker-item__badge' }, __( 'Already in quiz', 'learnomy' ) ) );
				}
				label.appendChild( meta );

				li.appendChild( label );
				listEl.appendChild( li );
			} );
		}

		function renderPager( body ) {
			if ( ! pagerEl ) { return; }
			pagerEl.textContent = '';
			var hasMore = body && body.meta && body.meta.has_more;

			var prev = el( 'button', {
				type: 'button',
				className: 'lrn-admin-btn lrn-admin-btn--sm',
				disabled: page <= 1
			}, __( 'Prev', 'learnomy' ) );
			prev.addEventListener( 'click', function () { if ( page > 1 ) { page--; load(); } } );

			var next = el( 'button', {
				type: 'button',
				className: 'lrn-admin-btn lrn-admin-btn--sm',
				disabled: ! hasMore
			}, __( 'Next', 'learnomy' ) );
			next.addEventListener( 'click', function () { if ( hasMore ) { page++; load(); } } );

			pagerEl.appendChild( prev );
			/* translators: %d: page number */
			pagerEl.appendChild( el( 'span', { className: 'lrn-picker-pager__page' }, sprintf( __( 'Page %d', 'learnomy' ), page ) ) );
			pagerEl.appendChild( next );
		}
	}

} )();
