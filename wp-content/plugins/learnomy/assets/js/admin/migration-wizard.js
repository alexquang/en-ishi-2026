/**
 * Learnomy — LMS Import wizard.
 *
 * A small REST-driven state machine for the Import admin page. Steps:
 *   1 Source  → 2 What to import  → 3 Review (dry-run)  → 4 Import (live progress)
 * Plus a "Recent imports" list with undo. All data comes from the migration
 * REST routes; nothing is hardcoded. No build step (plain ES module-free IIFE).
 */
( function () {
	'use strict';

	var cfg = window.learnomyMigration || {};
	var __ = ( window.wp && window.wp.i18n && window.wp.i18n.__ ) || function ( s ) { return s; };
	var sprintf = ( window.wp && window.wp.i18n && window.wp.i18n.sprintf ) || function ( s ) { return s; };

	var app = document.getElementById( 'lrn-migration-app' );
	if ( ! app || ! cfg.restUrl ) {
		return;
	}

	// Friendly labels for the canonical entity keys.
	var ENTITY_LABELS = {
		category: __( 'Categories', 'learnomy' ),
		course: __( 'Courses', 'learnomy' ),
		section: __( 'Sections', 'learnomy' ),
		lesson: __( 'Lessons', 'learnomy' ),
		sub_lesson: __( 'Sub-lessons', 'learnomy' ),
		quiz: __( 'Quizzes', 'learnomy' ),
		question: __( 'Questions', 'learnomy' ),
		enrollment: __( 'Enrollments', 'learnomy' ),
		progress: __( 'Progress', 'learnomy' )
	};

	var state = {
		step: 1,
		sources: [],
		source: null, // selected source object
		selected: {}, // entity key → bool
		preview: null,
		run: null,
		runs: [],
		busy: false,
		poll: null
	};

	// ── REST helpers ────────────────────────────────────────────────────────

	function request( method, path, body ) {
		var opts = {
			method: method,
			headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' }
		};
		if ( body ) {
			opts.body = JSON.stringify( body );
		}
		return fetch( cfg.restUrl + path, opts ).then( function ( r ) {
			return r.json().then( function ( data ) {
				if ( ! r.ok ) {
					var msg = ( data && data.error && data.error.message ) || ( data && data.message ) || __( 'Request failed', 'learnomy' );
					throw new Error( msg );
				}
				return data;
			} );
		} );
	}

	function toast( msg, type ) {
		if ( window.lrnToast ) {
			window.lrnToast( msg, type || 'info' );
		}
	}

	// ── Tiny DOM builder (textContent only — no innerHTML for data) ────────────

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		attrs = attrs || {};
		Object.keys( attrs ).forEach( function ( k ) {
			if ( k === 'class' ) {
				node.className = attrs[ k ];
			} else if ( k === 'text' ) {
				node.textContent = attrs[ k ];
			} else if ( k.indexOf( 'data-' ) === 0 || k === 'type' || k === 'role' || k === 'aria-label' || k === 'disabled' || k === 'value' || k === 'checked' ) {
				if ( attrs[ k ] === false || attrs[ k ] === null ) {
					return;
				}
				node.setAttribute( k, attrs[ k ] === true ? '' : attrs[ k ] );
			} else {
				node[ k ] = attrs[ k ];
			}
		} );
		( children || [] ).forEach( function ( c ) {
			if ( c == null ) {
				return;
			}
			node.appendChild( typeof c === 'string' ? document.createTextNode( c ) : c );
		} );
		return node;
	}

	/**
	 * Wrap free-form card content so it gets the panel's inset.
	 *
	 * `.lrn-settings-card` deliberately carries no padding: content arrives
	 * either as a `.lrn-admin-table` (whose cells supply the inset) or inside
	 * `__body-pad`. Every step of this wizard appended its content straight to
	 * the card, so the source tiles, the entity checklist, the progress bar and
	 * the warning panels all sat flush against the panel border — reported for
	 * the source tiles, but the same on four screens (Basecamp 10150326890).
	 *
	 * @param {Node[]} children Nodes to inset.
	 * @return {HTMLElement} The padded wrapper.
	 */
	function padded( children ) {
		return el( 'div', { class: 'lrn-settings-card__body-pad' }, children );
	}

	function btn( label, cls, onClick, opts ) {
		opts = opts || {};
		var b = el( 'button', { type: 'button', class: 'lrn-admin-btn ' + ( cls || '' ), text: label } );
		if ( opts.disabled ) {
			b.setAttribute( 'disabled', '' );
		}
		b.addEventListener( 'click', onClick );
		return b;
	}


	// ── Steps ────────────────────────────────────────────────────────────────

	function stepNav() {
		var labels = [ __( 'Source', 'learnomy' ), __( 'Content', 'learnomy' ), __( 'Review', 'learnomy' ), __( 'Import', 'learnomy' ) ];
		var nav = el( 'ol', { class: 'lrn-migration-steps' } );
		labels.forEach( function ( label, i ) {
			var n = i + 1;
			var cls = 'lrn-migration-step';
			if ( n === state.step ) {
				cls += ' is-active';
			} else if ( n < state.step ) {
				cls += ' is-done';
			}
			nav.appendChild( el( 'li', { class: cls }, [
				el( 'span', { class: 'lrn-migration-step__num', text: String( n ) } ),
				el( 'span', { class: 'lrn-migration-step__label', text: label } )
			] ) );
		} );
		return nav;
	}

	function renderSourceStep() {
		var card = el( 'div', { class: 'lrn-settings-card' }, [
			el( 'div', { class: 'lrn-settings-card__head' }, [
				el( 'h2', { class: 'lrn-settings-card__title', text: __( 'Choose the source LMS', 'learnomy' ) } ),
				el( 'p', { class: 'lrn-settings-card__desc', text: __( 'Only plugins that are active here and have content appear as selectable.', 'learnomy' ) } )
			] )
		] );

		var grid = el( 'div', { class: 'lrn-migration-sources' } );
		state.sources.forEach( function ( src ) {
			var total = 0;
			Object.keys( src.counts || {} ).forEach( function ( k ) { total += src.counts[ k ]; } );
			var selected = state.source && state.source.slug === src.slug;
			var cardCls = 'lrn-migration-source' + ( ! src.available ? ' is-disabled' : '' ) + ( selected ? ' is-selected' : '' );
			var node = el( 'button', { type: 'button', class: cardCls, disabled: ! src.available }, [
				el( 'span', { class: 'lrn-migration-source__name', text: src.label } ),
				el( 'span', {
					class: 'lrn-migration-source__meta',
					text: src.available
						? sprintf( __( '%d items found', 'learnomy' ), total )
						: __( 'Not detected on this site', 'learnomy' )
				} )
			] );
			if ( src.available ) {
				node.addEventListener( 'click', function () {
					state.source = src;
					state.selected = {};
					( src.entities || [] ).forEach( function ( e ) { state.selected[ e ] = true; } );
					state.step = 2;
					render();
				} );
			}
			grid.appendChild( node );
		} );
		card.appendChild( padded( [ grid ] ) );
		return card;
	}

	function renderEntityStep() {
		var src = state.source;
		var card = el( 'div', { class: 'lrn-settings-card' }, [
			el( 'div', { class: 'lrn-settings-card__head' }, [
				el( 'h2', { class: 'lrn-settings-card__title', text: sprintf( __( 'What to import from %s', 'learnomy' ), src.label ) } ),
				el( 'p', { class: 'lrn-settings-card__desc', text: __( 'Categories and tags come across with their courses, lessons, and quizzes.', 'learnomy' ) } )
			] )
		] );

		var list = el( 'div', { class: 'lrn-migration-entities' } );
		( src.entities || [] ).forEach( function ( entity ) {
			var count = ( src.counts && src.counts[ entity ] ) || 0;
			var id = 'lrn-ent-' + entity;
			var cb = el( 'input', { type: 'checkbox', id: id, value: entity } );
			cb.checked = !! state.selected[ entity ];
			cb.addEventListener( 'change', function () { state.selected[ entity ] = cb.checked; } );
			list.appendChild( el( 'label', { class: 'lrn-migration-entity', htmlFor: id }, [
				cb,
				el( 'span', { class: 'lrn-migration-entity__label', text: ENTITY_LABELS[ entity ] || entity } ),
				el( 'span', { class: 'lrn-migration-entity__count', text: String( count ) } )
			] ) );
		} );
		// The footer below carries its own padding, so it stays outside.
		card.appendChild( padded( [ list ] ) );

		card.appendChild( el( 'div', { class: 'lrn-settings-section__footer lrn-migration-actions' }, [
			btn( __( 'Back', 'learnomy' ), '--ghost', function () { state.step = 1; render(); } ),
			btn( __( 'Preview import', 'learnomy' ), '--primary', function () { runPreview(); } )
		] ) );
		return card;
	}

	function selectedEntities() {
		return Object.keys( state.selected ).filter( function ( k ) { return state.selected[ k ]; } );
	}

	function runPreview() {
		var entities = selectedEntities();
		if ( ! entities.length ) {
			toast( __( 'Pick at least one thing to import.', 'learnomy' ), 'error' );
			return;
		}
		state.busy = true;
		render();
		request( 'POST', 'preview', { source: state.source.slug, entities: entities } )
			.then( function ( res ) {
				state.preview = res.data;
				state.step = 3;
			} )
			.catch( function ( e ) { toast( e.message, 'error' ); } )
			.finally( function () { state.busy = false; render(); } );
	}

	function renderReviewStep() {
		var p = state.preview || { entities: {}, warnings: [] };
		var card = el( 'div', { class: 'lrn-settings-card' }, [
			el( 'div', { class: 'lrn-settings-card__head' }, [
				el( 'h2', { class: 'lrn-settings-card__title', text: __( 'Review before importing', 'learnomy' ) } ),
				el( 'p', { class: 'lrn-settings-card__desc', text: __( 'A dry run. Nothing is written until you start the import.', 'learnomy' ) } )
			] )
		] );

		var table = el( 'table', { class: 'lrn-admin-table' } );
		var tbody = el( 'tbody' );
		Object.keys( p.entities ).forEach( function ( entity ) {
			tbody.appendChild( el( 'tr', {}, [
				el( 'td', { text: ENTITY_LABELS[ entity ] || entity } ),
				el( 'td', { class: 'lrn-migration-num', text: String( p.entities[ entity ] ) } )
			] ) );
		} );
		table.appendChild( tbody );
		card.appendChild( el( 'div', { class: 'lrn-admin-table-wrap' }, [ table ] ) );

		// Both notices are bordered panels of their own, so flush against the
		// card border they read as a doubled edge. Collected into ONE padded
		// wrapper rather than two, so they don't stack two insets between them.
		var notes = [];

		if ( p.warnings && p.warnings.length ) {
			var warn = el( 'div', { class: 'lrn-migration-warnings' }, [
				el( 'p', { class: 'lrn-migration-warnings__head', text: __( 'Heads up - some question types have no exact match:', 'learnomy' ) } )
			] );
			var ul = el( 'ul' );
			p.warnings.forEach( function ( w ) { ul.appendChild( el( 'li', { text: w } ) ); } );
			warn.appendChild( ul );
			notes.push( warn );
		}

		// What we deliberately do NOT migrate — stated up front so there are no
		// surprises (certificates, quiz scores, payments).
		if ( p.not_migrated && p.not_migrated.length ) {
			var nm = el( 'div', { class: 'lrn-migration-notmigrated' }, [
				el( 'p', { class: 'lrn-migration-notmigrated__head', text: __( 'Not migrated:', 'learnomy' ) } )
			] );
			var nmul = el( 'ul' );
			p.not_migrated.forEach( function ( w ) { nmul.appendChild( el( 'li', { text: w } ) ); } );
			nm.appendChild( nmul );
			notes.push( nm );
		}

		if ( notes.length ) {
			card.appendChild( padded( notes ) );
		}

		card.appendChild( el( 'div', { class: 'lrn-settings-section__footer lrn-migration-actions' }, [
			btn( __( 'Back', 'learnomy' ), '--ghost', function () { state.step = 2; render(); } ),
			btn( __( 'Start import', 'learnomy' ), '--primary', function () { startRun(); } )
		] ) );
		return card;
	}

	function startRun() {
		state.busy = true;
		render();
		request( 'POST', 'run', { source: state.source.slug, entities: selectedEntities() } )
			.then( function ( res ) {
				state.run = res.data;
				state.step = 4;
				render();
				startPolling( res.data.id );
			} )
			.catch( function ( e ) { toast( e.message, 'error' ); } )
			.finally( function () { state.busy = false; } );
	}

	function startPolling( id ) {
		stopPolling();
		state.poll = window.setInterval( function () {
			request( 'GET', 'runs/' + encodeURIComponent( id ) )
				.then( function ( res ) {
					state.run = res.data;
					if ( res.data.status === 'done' || res.data.status === 'failed' ) {
						stopPolling();
						loadRuns();
					}
					render();
				} )
				.catch( function () { stopPolling(); } );
		}, 1500 );
	}

	function stopPolling() {
		if ( state.poll ) {
			window.clearInterval( state.poll );
			state.poll = null;
		}
	}

	function renderProgressStep() {
		var run = state.run || {};
		var done = run.status === 'done';
		var failed = run.status === 'failed';
		var card = el( 'div', { class: 'lrn-settings-card' }, [
			el( 'div', { class: 'lrn-settings-card__head' }, [
				el( 'h2', {
					class: 'lrn-settings-card__title',
					text: done ? __( 'Import complete', 'learnomy' ) : ( failed ? __( 'Import failed', 'learnomy' ) : __( 'Importing…', 'learnomy' ) )
				} ),
				el( 'p', { class: 'lrn-settings-card__desc', text: sprintf( __( 'Run %s', 'learnomy' ), run.id || '' ) } )
			] )
		] );

		var pct = run.percent || 0;
		var bar = el( 'div', { class: 'lrn-migration-bar' + ( failed ? ' is-failed' : '' ) }, [
			el( 'div', { class: 'lrn-migration-bar__fill', style: 'width:' + pct + '%' } )
		] );
		card.appendChild( padded( [
			bar,
			el( 'p', {
				class: 'lrn-migration-progress-text',
				text: failed
					? ( run.error || __( 'Something went wrong.', 'learnomy' ) )
					: sprintf( __( '%1$d%% - %2$d of %3$d items', 'learnomy' ), pct, run.processed || 0, run.total || 0 )
			} )
		] ) );

		// Per-entity created counts.
		var created = ( run.counters && run.counters.created ) || {};
		var skipped = ( run.counters && run.counters.skipped ) || {};
		var table = el( 'table', { class: 'lrn-admin-table' } );
		table.appendChild( el( 'thead', {}, [ el( 'tr', {}, [
			el( 'th', { text: __( 'Type', 'learnomy' ) } ),
			el( 'th', { text: __( 'Imported', 'learnomy' ) } ),
			el( 'th', { text: __( 'Skipped', 'learnomy' ) } )
		] ) ] ) );
		var tbody = el( 'tbody' );
		( run.entities || [] ).forEach( function ( entity ) {
			tbody.appendChild( el( 'tr', {}, [
				el( 'td', { text: ENTITY_LABELS[ entity ] || entity } ),
				el( 'td', { class: 'lrn-migration-num', text: String( created[ entity ] || 0 ) } ),
				el( 'td', { class: 'lrn-migration-num', text: String( skipped[ entity ] || 0 ) } )
			] ) );
		} );
		table.appendChild( tbody );
		card.appendChild( el( 'div', { class: 'lrn-admin-table-wrap' }, [ table ] ) );

		// Same single-wrapper treatment as the review step's notices.
		var runNotes = [];

		if ( run.warnings && run.warnings.length ) {
			var ul = el( 'ul', { class: 'lrn-migration-warnings' } );
			run.warnings.forEach( function ( w ) { ul.appendChild( el( 'li', { text: w } ) ); } );
			runNotes.push( ul );
		}

		// Repeat what did NOT come across, on the screen the owner actually ends on.
		// The preview step showed this list and the completion step showed counters
		// only, so clicking through the preview meant finishing an import with
		// "Import complete" and no reminder that certificates, quiz scores and
		// orders stayed behind (Basecamp 10143404772). Framed as next steps here
		// rather than caveats — at this point they are things left to do.
		if ( done && run.not_migrated && run.not_migrated.length ) {
			var nmDone = el( 'div', { class: 'lrn-migration-notmigrated' }, [
				el( 'p', {
					class: 'lrn-migration-notmigrated__head',
					text: __( 'Not migrated - worth setting up next:', 'learnomy' )
				} )
			] );
			var nmDoneUl = el( 'ul' );
			run.not_migrated.forEach( function ( w ) { nmDoneUl.appendChild( el( 'li', { text: w } ) ); } );
			nmDone.appendChild( nmDoneUl );
			runNotes.push( nmDone );
		}

		if ( runNotes.length ) {
			card.appendChild( padded( runNotes ) );
		}

		if ( done || failed ) {
			card.appendChild( el( 'div', { class: 'lrn-settings-section__footer lrn-migration-actions' }, [
				btn( __( 'Import another', 'learnomy' ), '--ghost', function () { resetWizard(); } ),
				btn( __( 'Undo this import', 'learnomy' ), '--danger', function () { undoRun( run.id ); } )
			] ) );
		}
		return card;
	}

	function resetWizard() {
		stopPolling();
		state.step = 1;
		state.source = null;
		state.selected = {};
		state.preview = null;
		state.run = null;
		render();
	}

	function undoRun( id ) {
		var go = function () {
			request( 'POST', 'runs/' + encodeURIComponent( id ) + '/undo' )
				.then( function ( res ) {
					toast( sprintf( __( 'Removed %d imported items.', 'learnomy' ), res.data.deleted ), 'success' );
					if ( state.run && state.run.id === id ) {
						resetWizard();
					}
					loadRuns();
				} )
				.catch( function ( e ) { toast( e.message, 'error' ); } );
		};
		// `learnomy-confirm-modal` is a declared dependency of this script
		// (class-admin.php), so window.lrnConfirm is always defined here. The
		// old native window.confirm() branch was unreachable dead code.
		window.lrnConfirm( {
			title: __( 'Undo this import?', 'learnomy' ),
			message: __( 'Everything this run created will be deleted. This cannot be undone.', 'learnomy' ),
			confirmLabel: __( 'Undo import', 'learnomy' ),
			destructive: true
		} ).then( function ( ok ) { if ( ok ) { go(); } } );
	}

	function renderRecentRuns() {
		if ( ! state.runs.length ) {
			return null;
		}
		var card = el( 'div', { class: 'lrn-settings-card lrn-migration-recent' }, [
			el( 'div', { class: 'lrn-settings-card__head' }, [
				el( 'h2', { class: 'lrn-settings-card__title', text: __( 'Recent imports', 'learnomy' ) } )
			] )
		] );
		var table = el( 'table', { class: 'lrn-admin-table' } );
		table.appendChild( el( 'thead', {}, [ el( 'tr', {}, [
			el( 'th', { text: __( 'Run', 'learnomy' ) } ),
			el( 'th', { text: __( 'Source', 'learnomy' ) } ),
			el( 'th', { text: __( 'Status', 'learnomy' ) } ),
			el( 'th', { text: __( 'Progress', 'learnomy' ) } ),
			el( 'th', { text: '' } )
		] ) ] ) );
		var tbody = el( 'tbody' );
		state.runs.forEach( function ( r ) {
			var actions = el( 'td' );
			actions.appendChild( btn( __( 'Undo', 'learnomy' ), '--ghost --sm --danger', function () { undoRun( r.id ); } ) );
			tbody.appendChild( el( 'tr', {}, [
				el( 'td', { text: r.id } ),
				el( 'td', { text: r.source } ),
				el( 'td', {}, [ el( 'span', { class: 'lrn-status-badge lrn-status-badge--' + r.status, text: r.status } ) ] ),
				el( 'td', { text: ( r.percent || 0 ) + '%' } ),
				actions
			] ) );
		} );
		table.appendChild( tbody );
		card.appendChild( el( 'div', { class: 'lrn-admin-table-wrap' }, [ table ] ) );
		return card;
	}

	// ── Render ─────────────────────────────────────────────────────────────

	function render() {
		app.innerHTML = '';
		app.appendChild( stepNav() );

		if ( state.busy ) {
			app.appendChild( el( 'p', { class: 'lrn-migration-loading', text: __( 'Working…', 'learnomy' ) } ) );
		}

		var body;
		if ( state.step === 1 ) {
			body = renderSourceStep();
		} else if ( state.step === 2 ) {
			body = renderEntityStep();
		} else if ( state.step === 3 ) {
			body = renderReviewStep();
		} else {
			body = renderProgressStep();
		}
		app.appendChild( body );

		var recent = renderRecentRuns();
		if ( recent ) {
			app.appendChild( recent );
		}
	}

	function loadRuns() {
		return request( 'GET', 'runs' ).then( function ( res ) {
			state.runs = res.data || [];
		} ).catch( function () {} );
	}

	function init() {
		request( 'GET', 'sources' )
			.then( function ( res ) {
				state.sources = res.data || [];
				return loadRuns();
			} )
			.then( function () { render(); } )
			.catch( function ( e ) {
				app.innerHTML = '';
				app.appendChild( el( 'div', { class: 'notice notice-error' }, [ el( 'p', { text: e.message } ) ] ) );
			} );
	}

	init();
}() );
