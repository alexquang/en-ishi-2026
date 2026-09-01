/**
 * Learnomy - Admin question editor.
 *
 * Per-type answers UI. The Type dropdown switches the visible layout; each
 * layout serializes into answers[N][field] inputs that the AJAX handler
 * reads server-side. Layouts:
 *
 *   mc_single    - radios + feedback per row (one correct)
 *   mc_multi     - checkboxes + fraction (0.0-1.0) + feedback per row
 *   true_false   - 2 fixed radios (True / False)
 *   short_answer - text inputs (accepted strings) + case-sensitive flag
 *   fill_blank   - one answer-group per {{blankN}} token in the question text
 *   ordering     - drag-drop list, correct order = saved order
 *   matching     - two-column pair builder
 *   essay        - no answers (manually graded)
 *
 * The editor intercepts the form submit to replace the default FormData
 * serializer in actions.js - that helper cannot handle nested answers[i][key]
 * arrays because new FormData(form) flattens them into duplicate keys.
 *
 * All DOM is built through createElement() / textContent / setAttribute -
 * no innerHTML with user data. That keeps the editor XSS-safe even when
 * instructors paste HTML-like text into answer content.
 *
 * @package Learnomy
 */
( function () {
	'use strict';

	var __ = wp.i18n.__;

	var cfg       = window.learnomyQuestionEditor || {};
	var i18n      = cfg.i18n || {};
	var admin     = window.learnomyAdmin || {};
	var restUrl   = cfg.restUrl || admin.restUrl || '/wp-json/learnomy/v1/';
	var restNonce = cfg.restNonce || admin.restNonce || '';

	var form = document.getElementById( 'lrn-question-editor-form' );
	if ( ! form ) { return; }

	// A3: mount Editor.js on the rich question stem field (its own `stem` column).
	// Reuses the shared tools factory; seeds from the localized for_editor() blocks.
	// On submit, the handler below awaits stemEditor.save() and writes the JSON to
	// the hidden #lrn-question-stem-input (name="stem"), which prepare_question_stem
	// canonicalises to HTML server-side.
	var stemEditor = null;
	( function mountStemEditor() {
		var holder = document.getElementById( 'lrn-question-stem' );
		if ( ! holder || 'undefined' === typeof window.EditorJS ) { return; }
		var tools = ( 'function' === typeof window.lrnEditorTools )
			? window.lrnEditorTools( { restUrl: restUrl, nonce: restNonce } )
			: {};
		var seed = cfg.stem && cfg.stem.blocks ? cfg.stem : {};
		stemEditor = new window.EditorJS( {
			holder: 'lrn-question-stem',
			tools: tools,
			data: seed,
			placeholder: i18n.stemPlaceholder || __( 'Add an optional passage, code, or image…', 'learnomy' ),
		} );
	} )();

	var region = form.querySelector( '[data-lrn-answers-region]' );
	var addBtn = form.querySelector( '[data-lrn-answers-add]' );
	var typeSelect = form.querySelector( '[data-lrn-question-type]' );
	var bodyInput = form.querySelector( '[name="title"]' );

	var initialScript = document.getElementById( 'lrn-question-editor-initial' );
	var initial = { type: 'mc_single', answers: [] };
	if ( initialScript ) {
		try {
			initial = JSON.parse( initialScript.textContent || initialScript.innerText );
		} catch ( err ) { /* fall back to defaults */ }
	}

	// Matching questions persist as separate prompt/match rows (paired by
	// sort_order) but the editor works one-row-per-pair. Fold the stored rows
	// back into pairs (left = prompt content, right = match content) so editing
	// an existing matching question shows both sides. Legacy rows that aren't
	// tagged prompt/match are treated as the left side.
	if ( 'matching' === initial.type && Array.isArray( initial.answers ) ) {
		var byOrder = {};
		initial.answers.forEach( function ( a ) {
			var so = parseInt( a.sort_order, 10 ) || 0;
			byOrder[ so ] = byOrder[ so ] || { content: '', feedback: '' };
			if ( 'match' === a.group_id ) {
				byOrder[ so ].feedback = a.content || '';
			} else {
				byOrder[ so ].content = a.content || '';
			}
		} );
		initial.answers = Object.keys( byOrder )
			.sort( function ( x, y ) { return x - y; } )
			.map( function ( so ) {
				return {
					content:    byOrder[ so ].content,
					feedback:   byOrder[ so ].feedback,
					is_correct: 1,
					fraction:   1,
					group_id:   '',
					sort_order: parseInt( so, 10 )
				};
			} );
	}

	// State - the source of truth the UI renders from. Keeping a single array
	// simplifies add/remove/move operations and lets us re-serialize on submit
	// without having to walk the DOM twice.
	var state = {
		type: initial.type || 'mc_single',
		answers: Array.isArray( initial.answers ) ? initial.answers.slice() : []
	};


	/**
	 * Small DOM builder. Passing a text child uses textContent (safe).
	 * Attributes are applied via setAttribute so nothing is parsed as HTML.
	 */
	function h( tag, attrs, children ) {
		var el = document.createElement( tag );
		if ( attrs ) {
			Object.keys( attrs ).forEach( function ( k ) {
				var v = attrs[ k ];
				if ( v === null || v === undefined || v === false ) { return; }
				if ( k === 'className' ) { el.className = v; return; }
				if ( k === 'checked' ) { el.checked = !! v; return; }
				if ( k === 'draggable' ) { el.draggable = !! v; return; }
				if ( k === 'value' ) { el.value = v; return; }
				if ( k === 'text' ) { el.textContent = v; return; }
				if ( k === 'hidden' ) { el.hidden = !! v; return; }
				el.setAttribute( k, v );
			} );
		}
		if ( children ) {
			( Array.isArray( children ) ? children : [ children ] ).forEach( function ( c ) {
				if ( c === null || c === undefined ) { return; }
				el.appendChild( typeof c === 'string' ? document.createTextNode( c ) : c );
			} );
		}
		return el;
	}

	function clear( node ) {
		while ( node.firstChild ) { node.removeChild( node.firstChild ); }
	}

	function iconEl( name ) {
		var i = document.createElement( 'i' );
		i.setAttribute( 'data-lucide', name );
		return i;
	}

	function removeButton() {
		return h( 'button', {
			type: 'button',
			className: 'lrn-answer-row__remove',
			'data-lrn-answer-remove': '1',
			'aria-label': i18n.removeAnswer || __( 'Remove', 'learnomy' )
		}, [ iconEl( 'x' ) ] );
	}

	/**
	 * Ensure at least `min` answer rows exist - used when switching to a
	 * type that requires multiple answers (MCQ needs >= 2).
	 */
	function ensureRows( min, defaults ) {
		while ( state.answers.length < min ) {
			state.answers.push( Object.assign( {
				content: '', is_correct: 0, fraction: 0, feedback: '', group_id: '', sort_order: state.answers.length
			}, defaults || {} ) );
		}
	}

	/**
	 * Parse {{blank1}}, {{blank2}} tokens from the question body. Returns the
	 * list of token identifiers in document order.
	 *
	 * DUPLICATE NOTE: The same regex lives in PHP at
	 * includes/api/class-quizzes-controller.php::parse_blank_tokens(). If the
	 * token format ever changes, both locations must be updated together. The PHP
	 * side is the canonical definition; this function is the authoring-time mirror.
	 */
	function parseBlanks( text ) {
		if ( ! text ) { return []; }
		var matches = String( text ).match( /\{\{\s*(blank[0-9]+)\s*\}\}/gi );
		if ( ! matches ) { return []; }
		var seen = {};
		var out  = [];
		matches.forEach( function ( m ) {
			var id = m.replace( /\{\{|\}\}|\s/g, '' ).toLowerCase();
			if ( ! seen[ id ] ) {
				seen[ id ] = true;
				out.push( id );
			}
		} );
		return out;
	}

	// -----------------------------------------------------------------
	// Renderers - one per question type
	// -----------------------------------------------------------------

	function renderEssay() {
		clear( region );
		region.appendChild(
			h( 'p', { className: 'lrn-muted' },
				i18n.essayNote || __( 'Essays are graded manually by the instructor. The explanation field is shown to students, so it is not a scoring rubric.', 'learnomy' )
			)
		);
		if ( addBtn ) { addBtn.hidden = true; }
	}

	function renderRadioGroup( fixedLabels ) {
		if ( addBtn ) { addBtn.hidden = !! fixedLabels; }

		if ( fixedLabels ) {
			state.answers = [
				{
					content: fixedLabels[0],
					is_correct: ( state.answers[0] && state.answers[0].is_correct ) ? 1 : 0,
					fraction: 1,
					feedback: ( state.answers[0] && state.answers[0].feedback ) || '',
					sort_order: 0
				},
				{
					content: fixedLabels[1],
					is_correct: ( state.answers[1] && state.answers[1].is_correct ) ? 1 : 0,
					fraction: 1,
					feedback: ( state.answers[1] && state.answers[1].feedback ) || '',
					sort_order: 1
				}
			];
			if ( ! state.answers[0].is_correct && ! state.answers[1].is_correct ) {
				state.answers[0].is_correct = 1;
			}
		} else {
			ensureRows( 2 );
		}

		clear( region );
		var list = h( 'div', { className: 'lrn-answers-list' } );

		state.answers.forEach( function ( ans, idx ) {
			var main = h( 'div', { className: 'lrn-answer-row__main' } );

			if ( fixedLabels ) {
				main.appendChild( h( 'span', { className: 'lrn-answer-row__label', text: ans.content } ) );
				main.appendChild( h( 'input', { type: 'hidden', 'data-field': 'content', value: ans.content } ) );
			} else {
				main.appendChild( h( 'input', {
					type: 'text', className: 'regular-text', 'data-field': 'content',
					value: ans.content, placeholder: i18n.answerPlaceholder || __( 'Answer text', 'learnomy' )
				} ) );
				main.appendChild( removeButton() );
			}

			// Controls row: Correct radio on its own line below the content input
			// so it never collides with the content field on narrow viewports.
			var controls = h( 'div', { className: 'lrn-answer-row__controls' } );

			var radioLabel = h( 'label', { className: 'lrn-answer-row__correct' } );
			radioLabel.appendChild( h( 'input', {
				type: 'radio', name: 'lrn-correct-radio', 'data-field': 'is_correct', checked: !! ans.is_correct
			} ) );
			radioLabel.appendChild( document.createTextNode( ' ' + ( i18n.correct || __( 'Correct', 'learnomy' ) ) ) );
			controls.appendChild( radioLabel );

			var row = h( 'div', { className: 'lrn-answer-row', 'data-answer-idx': idx }, [ main, controls ] );
			row.appendChild( h( 'textarea', {
				className: 'lrn-answer-row__feedback', rows: '1',
				'data-field': 'feedback', placeholder: i18n.feedback || __( 'Feedback (optional)', 'learnomy' ),
				text: ans.feedback || ''
			} ) );

			list.appendChild( row );
		} );

		region.appendChild( list );
	}

	/**
	 * Build the fraction slider + live % readout. The slider is a 0-100 range;
	 * we convert to/from the 0.0-1.0 value the DB stores. The readout node
	 * lives inside the label so tab order stays logical.
	 */
	function fractionSlider( ans ) {
		var frac = parseFloat( ans.fraction );
		if ( isNaN( frac ) ) { frac = 0; }
		if ( frac > 1 ) { frac = frac / 100; } // legacy percentage values
		var pct = Math.round( frac * 100 );

		var wrap = h( 'label', { className: 'lrn-answer-row__fraction' } );
		wrap.appendChild( h( 'span', {
			className: 'lrn-answer-row__fraction-label',
			text: i18n.fraction || __( 'Credit', 'learnomy' )
		} ) );
		var slider = h( 'input', {
			type: 'range', min: '0', max: '100', step: '5',
			className: 'lrn-answer-row__fraction-slider',
			'data-field': 'fraction_pct', value: String( pct )
		} );
		var out = h( 'span', { className: 'lrn-answer-row__fraction-value', text: pct + '%' } );
		slider.addEventListener( 'input', function () {
			out.textContent = slider.value + '%';
		} );
		wrap.appendChild( slider );
		wrap.appendChild( out );
		return wrap;
	}

	function renderCheckboxGroup() {
		ensureRows( 2 );
		if ( addBtn ) { addBtn.hidden = false; }

		clear( region );
		var list = h( 'div', { className: 'lrn-answers-list' } );

		state.answers.forEach( function ( ans, idx ) {
			var main = h( 'div', { className: 'lrn-answer-row__main' } );
			main.appendChild( h( 'input', {
				type: 'text', className: 'regular-text', 'data-field': 'content',
				value: ans.content, placeholder: i18n.answerPlaceholder || __( 'Answer text', 'learnomy' )
			} ) );
			main.appendChild( removeButton() );

			// Controls row: Correct checkbox + Credit slider. Separating these
			// from the content input stops "Correct" and "Credit (0-1)" from
			// colliding the way they did in the old form-table layout.
			var controls = h( 'div', { className: 'lrn-answer-row__controls' } );

			var cbLabel = h( 'label', { className: 'lrn-answer-row__correct' } );
			cbLabel.appendChild( h( 'input', {
				type: 'checkbox', 'data-field': 'is_correct', checked: !! ans.is_correct
			} ) );
			cbLabel.appendChild( document.createTextNode( ' ' + ( i18n.correct || __( 'Correct', 'learnomy' ) ) ) );
			controls.appendChild( cbLabel );
			controls.appendChild( fractionSlider( ans ) );

			var row = h( 'div', { className: 'lrn-answer-row', 'data-answer-idx': idx }, [ main, controls ] );
			row.appendChild( h( 'textarea', {
				className: 'lrn-answer-row__feedback', rows: '1',
				'data-field': 'feedback', placeholder: i18n.feedback || __( 'Feedback (optional)', 'learnomy' ),
				text: ans.feedback || ''
			} ) );

			list.appendChild( row );
		} );

		region.appendChild( list );
	}

	function renderShortAnswer() {
		ensureRows( 1 );
		if ( addBtn ) { addBtn.hidden = false; }

		clear( region );
		var list = h( 'div', { className: 'lrn-answers-list' } );

		state.answers.forEach( function ( ans, idx ) {
			// short_answer stores "case-sensitive" inside group_id.
			var caseSensitive = ( ans.group_id || '' ).indexOf( 'cs:1' ) === 0;

			var main = h( 'div', { className: 'lrn-answer-row__main' } );
			main.appendChild( h( 'input', {
				type: 'text', className: 'regular-text', 'data-field': 'content',
				value: ans.content, placeholder: i18n.acceptedPlaceholder || __( 'Accepted answer', 'learnomy' )
			} ) );
			main.appendChild( h( 'input', { type: 'hidden', 'data-field': 'is_correct', value: '1' } ) );
			main.appendChild( h( 'input', { type: 'hidden', 'data-field': 'fraction', value: '1' } ) );
			main.appendChild( removeButton() );

			var controls = h( 'div', { className: 'lrn-answer-row__controls' } );
			var csLabel = h( 'label', { className: 'lrn-answer-row__case' } );
			csLabel.appendChild( h( 'input', {
				type: 'checkbox', 'data-field': '_case_sensitive', checked: caseSensitive
			} ) );
			csLabel.appendChild( document.createTextNode( ' ' + ( i18n.caseSense || __( 'Case-sensitive', 'learnomy' ) ) ) );
			controls.appendChild( csLabel );

			var row = h( 'div', { className: 'lrn-answer-row', 'data-answer-idx': idx }, [ main, controls ] );
			row.appendChild( h( 'textarea', {
				className: 'lrn-answer-row__feedback', rows: '1',
				'data-field': 'feedback', placeholder: i18n.feedback || __( 'Feedback (optional)', 'learnomy' ),
				text: ans.feedback || ''
			} ) );

			list.appendChild( row );
		} );

		region.appendChild( list );
	}

	function renderFillBlank() {
		if ( addBtn ) { addBtn.hidden = true; }
		var tokens = parseBlanks( bodyInput ? bodyInput.value : '' );

		clear( region );
		if ( tokens.length === 0 ) {
			region.appendChild( h( 'p', { className: 'lrn-muted' },
				i18n.noTokens || __( 'Add {{blank1}}, {{blank2}} tokens in the question text to create blanks.', 'learnomy' )
			) );
			return;
		}

		var grouped = {};
		( state.answers || [] ).forEach( function ( a ) {
			var gid = a.group_id || 'blank1';
			if ( ! grouped[ gid ] ) { grouped[ gid ] = []; }
			grouped[ gid ].push( a );
		} );

		tokens.forEach( function ( tok, gi ) {
			var fs = h( 'fieldset', { className: 'lrn-blank-group', 'data-blank-group': tok } );
			fs.appendChild( h( 'legend', {}, ( i18n.blankLabel || __( 'Blank %s accepted answers', 'learnomy' ) ).replace( '%s', String( gi + 1 ) ) ) );

			var rows = grouped[ tok ] && grouped[ tok ].length > 0 ? grouped[ tok ] : [ { content: '' } ];
			var rowsWrap = h( 'div', { className: 'lrn-blank-group__rows' } );

			rows.forEach( function ( a, idx ) {
				var row = h( 'div', {
					className: 'lrn-answer-row lrn-answer-row--blank',
					'data-blank': tok, 'data-idx': idx
				} );
				row.appendChild( h( 'input', {
					type: 'text', className: 'regular-text', 'data-field': 'content',
					value: a.content || '', placeholder: __( 'Accepted value', 'learnomy' )
				} ) );
				row.appendChild( h( 'button', {
					type: 'button', className: 'lrn-answer-row__remove',
					'data-lrn-blank-remove': '1', 'aria-label': i18n.removeAnswer || __( 'Remove', 'learnomy' )
				}, [ iconEl( 'x' ) ] ) );
				rowsWrap.appendChild( row );
			} );

			fs.appendChild( rowsWrap );

			var addLine = h( 'button', {
				type: 'button', className: 'lrn-admin-btn lrn-admin-btn--sm',
				'data-lrn-blank-add': tok
			}, [ iconEl( 'plus' ), document.createTextNode( ' ' + ( i18n.addBlankLine || __( 'Add acceptable answer', 'learnomy' ) ) ) ] );
			fs.appendChild( addLine );

			region.appendChild( fs );
		} );
	}

	function renderOrdering() {
		ensureRows( 2 );
		if ( addBtn ) { addBtn.hidden = false; }

		clear( region );
		var ol = h( 'ol', { className: 'lrn-answers-list lrn-answers-list--order' } );

		state.answers.forEach( function ( ans, idx ) {
			var li = h( 'li', {
				className: 'lrn-answer-row lrn-answer-row--order',
				'data-answer-idx': idx, draggable: true
			} );
			li.appendChild( h( 'span', { className: 'lrn-answer-row__drag', 'aria-hidden': 'true' }, [ iconEl( 'grip-vertical' ) ] ) );
			li.appendChild( h( 'input', {
				type: 'text', className: 'regular-text', 'data-field': 'content',
				value: ans.content, placeholder: i18n.orderingItem || __( 'Ordered item', 'learnomy' )
			} ) );
			li.appendChild( h( 'input', { type: 'hidden', 'data-field': 'is_correct', value: '1' } ) );
			li.appendChild( h( 'input', { type: 'hidden', 'data-field': 'sort_order', value: String( idx ) } ) );
			li.appendChild( removeButton() );
			ol.appendChild( li );
		} );

		region.appendChild( ol );
	}

	function renderMatching() {
		ensureRows( 2 );
		if ( addBtn ) { addBtn.hidden = false; }

		clear( region );
		var list = h( 'div', { className: 'lrn-answers-list' } );

		state.answers.forEach( function ( ans, idx ) {
			var row = h( 'div', {
				className: 'lrn-answer-row lrn-answer-row--pair',
				'data-answer-idx': idx
			} );
			row.appendChild( h( 'input', {
				type: 'text', className: 'regular-text', 'data-field': 'content',
				value: ans.content, placeholder: i18n.leftItem || __( 'Left item', 'learnomy' )
			} ) );
			row.appendChild( h( 'span', { className: 'lrn-answer-row__pair-sep', 'aria-hidden': 'true' }, '<->' ) );
			row.appendChild( h( 'input', {
				type: 'text', className: 'regular-text', 'data-field': '_match',
				value: ans.feedback || '', placeholder: i18n.rightItem || __( 'Right pair', 'learnomy' )
			} ) );
			row.appendChild( h( 'input', { type: 'hidden', 'data-field': 'is_correct', value: '1' } ) );
			row.appendChild( removeButton() );
			list.appendChild( row );
		} );

		region.appendChild( list );
	}

	// -----------------------------------------------------------------
	// Dispatch
	// -----------------------------------------------------------------

	/**
	 * Look up a renderer an extension registered for a custom question type.
	 * Pro's advanced-quizzes registers numeric / math / calculated here. The
	 * renderer receives the answer-region helpers and must build standard
	 * answer rows (data-answer-idx + data-field="content|group_id|…") so the
	 * generic serializer and the REST save path capture them unchanged.
	 *
	 * @param {string} type Question type slug.
	 * @return {Function|null}
	 */
	function customEditor( type ) {
		var reg = ( typeof window !== 'undefined' && window.learnomyQuestionTypeEditors ) || null;
		return reg && typeof reg[ type ] === 'function' ? reg[ type ] : null;
	}

	function render() {
		switch ( state.type ) {
			case 'mc_multi':
				renderCheckboxGroup();
				break;
			case 'true_false':
				renderRadioGroup( [ i18n.trueLabel || __( 'True', 'learnomy' ), i18n.falseLabel || __( 'False', 'learnomy' ) ] );
				break;
			case 'short_answer':
				renderShortAnswer();
				break;
			case 'fill_blank':
				renderFillBlank();
				break;
			case 'ordering':
				renderOrdering();
				break;
			case 'matching':
				renderMatching();
				break;
			case 'essay':
				renderEssay();
				break;
			case 'mc_single':
				renderRadioGroup();
				break;
			default:
				// Custom type registered by an extension (Pro numeric / math /
				// calculated). The renderer builds standard answer rows
				// (data-answer-idx + data-field=*) so harvestState captures them.
				if ( customEditor( state.type ) ) {
					customEditor( state.type )( {
						region: region,
						addBtn: addBtn,
						h: h,
						clear: clear,
						ensureRows: ensureRows,
						answers: state.answers,
					} );
				} else {
					renderRadioGroup();
				}
				break;
		}
	}

	/**
	 * Snapshot DOM back into state before a re-render so in-flight edits
	 * aren't lost when the user swaps a row or type.
	 */
	function harvestState() {
		if ( state.type === 'essay' ) {
			state.answers = [];
			return;
		}

		if ( state.type === 'fill_blank' ) {
			var answers = [];
			region.querySelectorAll( '[data-blank-group]' ).forEach( function ( fs ) {
				var blank = fs.getAttribute( 'data-blank-group' );
				fs.querySelectorAll( '[data-blank]' ).forEach( function ( row, idx ) {
					var contentInput = row.querySelector( '[data-field="content"]' );
					if ( ! contentInput ) { return; }
					var content = contentInput.value || '';
					if ( ! content.trim() ) { return; }
					answers.push( {
						content: content,
						is_correct: 1,
						fraction: 1,
						feedback: '',
						group_id: blank,
						sort_order: idx
					} );
				} );
			} );
			state.answers = answers;
			return;
		}

		var rows = region.querySelectorAll( '[data-answer-idx]' );
		var next = [];
		rows.forEach( function ( row, idx ) {
			var get = function ( field ) {
				var el = row.querySelector( '[data-field="' + field + '"]' );
				if ( ! el ) { return ''; }
				if ( el.type === 'checkbox' || el.type === 'radio' ) { return el.checked ? 1 : 0; }
				return el.value;
			};
			var content = get( 'content' );

			// mc_multi uses a 0-100 slider stored in data-field="fraction_pct".
			// Convert to the 0.0-1.0 decimal the DB column expects.
			var fractionValue;
			var pctEl = row.querySelector( '[data-field="fraction_pct"]' );
			if ( pctEl ) {
				fractionValue = ( parseFloat( pctEl.value ) || 0 ) / 100;
			} else {
				fractionValue = parseFloat( get( 'fraction' ) );
				if ( isNaN( fractionValue ) ) {
					fractionValue = get( 'is_correct' ) ? 1 : 0;
				}
			}

			var answer = {
				content: content,
				is_correct: get( 'is_correct' ) ? 1 : 0,
				fraction: fractionValue,
				feedback: get( 'feedback' ) || '',
				group_id: '',
				sort_order: idx
			};

			if ( state.type === 'matching' ) {
				// One UI row per pair: left in content, right in _match. We
				// carry the right side in feedback for now and split each pair
				// into its two prompt/match rows after the loop.
				answer.feedback = get( '_match' ) || '';
			}
			if ( state.type === 'short_answer' ) {
				var cs = row.querySelector( '[data-field="_case_sensitive"]' );
				answer.group_id = cs && cs.checked ? 'cs:1' : 'cs:0';
			}
			// Custom types (Pro numeric / math / calculated) carry their own
			// per-row metadata in a data-field="group_id" control (e.g. the
			// numeric tolerance, "tol:0.01"). The built-in types above never
			// emit that field, so this only fires for registered custom types.
			if ( customEditor( state.type ) ) {
				answer.group_id = get( 'group_id' ) || '';
			}
			next.push( answer );
		} );

		// Matching persists as separate prompt/match rows paired by a shared
		// sort_order — the runtime grader pairs prompt[N] with match[N] and the
		// player payload + template only render group_id 'prompt'/'match'. The
		// editor works one-row-per-pair, so expand each pair into its two rows.
		// Both rows share the pair index as sort_order (NOT the flat array
		// index, which would desync the pairing).
		if ( state.type === 'matching' ) {
			var expanded = [];
			next.forEach( function ( pair, pairIdx ) {
				var left  = ( pair.content || '' );
				var right = ( pair.feedback || '' );
				if ( ! left.trim() && ! right.trim() ) { return; }
				expanded.push( { content: left,  is_correct: 1, fraction: 1, feedback: '', group_id: 'prompt', sort_order: pairIdx } );
				expanded.push( { content: right, is_correct: 1, fraction: 1, feedback: '', group_id: 'match',  sort_order: pairIdx } );
			} );
			next = expanded;
		}

		state.answers = next;
	}

	// -----------------------------------------------------------------
	// Event wiring
	// -----------------------------------------------------------------

	// Collapsible sections — click the header to toggle .is-open. Mirrors
	// the handler in course-form.js so this page works without pulling in
	// the course-form bundle.
	form.querySelectorAll( '.lrn-settings-section__header' ).forEach( function ( header ) {
		header.addEventListener( 'click', function () {
			var section = header.closest( '.lrn-settings-section' );
			if ( ! section ) { return; }
			var isOpen = section.classList.toggle( 'is-open' );
			header.setAttribute( 'aria-expanded', isOpen ? 'true' : 'false' );
		} );
	} );

	if ( typeSelect ) {
		typeSelect.addEventListener( 'change', function () {
			harvestState();
			state.type = typeSelect.value;
			if ( state.type === 'true_false' ) { state.answers = []; }
			if ( state.type === 'essay' ) { state.answers = []; }
			render();
		} );
	}

	if ( bodyInput ) {
		bodyInput.addEventListener( 'input', function () {
			if ( state.type === 'fill_blank' ) {
				harvestState();
				render();
			}
		} );
	}

	if ( addBtn ) {
		addBtn.addEventListener( 'click', function () {
			harvestState();
			state.answers.push( {
				content: '', is_correct: 0, fraction: 0, feedback: '', group_id: '', sort_order: state.answers.length
			} );
			render();
		} );
	}

	region.addEventListener( 'click', function ( e ) {
		var removeBtn = e.target.closest( '[data-lrn-answer-remove]' );
		if ( removeBtn ) {
			var row = removeBtn.closest( '[data-answer-idx]' );
			if ( row ) {
				harvestState();
				var idx = parseInt( row.getAttribute( 'data-answer-idx' ), 10 );
				state.answers.splice( idx, 1 );
				render();
			}
			return;
		}

		var blankAdd = e.target.closest( '[data-lrn-blank-add]' );
		if ( blankAdd ) {
			var blank = blankAdd.getAttribute( 'data-lrn-blank-add' );
			harvestState();
			state.answers.push( {
				content: '', is_correct: 1, fraction: 1, feedback: '',
				group_id: blank, sort_order: state.answers.length
			} );
			render();
			return;
		}

		var blankRemove = e.target.closest( '[data-lrn-blank-remove]' );
		if ( blankRemove ) {
			var blankRow = blankRemove.closest( '[data-blank]' );
			if ( blankRow ) {
				blankRow.remove();
			}
		}
	} );

	// Ordering drag-drop - simple HTML5 DnD, no library.
	region.addEventListener( 'dragstart', function ( e ) {
		var li = e.target.closest( '.lrn-answer-row--order' );
		if ( ! li ) { return; }
		e.dataTransfer.effectAllowed = 'move';
		e.dataTransfer.setData( 'text/plain', li.getAttribute( 'data-answer-idx' ) );
		li.classList.add( 'is-dragging' );
	} );
	region.addEventListener( 'dragover', function ( e ) {
		var li = e.target.closest( '.lrn-answer-row--order' );
		if ( li ) { e.preventDefault(); li.classList.add( 'is-over' ); }
	} );
	region.addEventListener( 'dragleave', function ( e ) {
		var li = e.target.closest( '.lrn-answer-row--order' );
		if ( li ) { li.classList.remove( 'is-over' ); }
	} );
	region.addEventListener( 'drop', function ( e ) {
		var target = e.target.closest( '.lrn-answer-row--order' );
		if ( ! target ) { return; }
		e.preventDefault();
		var fromIdx = parseInt( e.dataTransfer.getData( 'text/plain' ), 10 );
		var toIdx = parseInt( target.getAttribute( 'data-answer-idx' ), 10 );
		if ( isNaN( fromIdx ) || isNaN( toIdx ) || fromIdx === toIdx ) { return; }
		harvestState();
		var moved = state.answers.splice( fromIdx, 1 )[0];
		state.answers.splice( toIdx, 0, moved );
		state.answers.forEach( function ( a, i ) { a.sort_order = i; } );
		render();
	} );
	region.addEventListener( 'dragend', function () {
		region.querySelectorAll( '.is-dragging, .is-over' ).forEach( function ( el ) {
			el.classList.remove( 'is-dragging' );
			el.classList.remove( 'is-over' );
		} );
	} );

	// -----------------------------------------------------------------
	// Submit - serialize state into FormData so the AJAX handler gets
	// properly-bracketed answers[] keys.
	// -----------------------------------------------------------------

	// Submit through REST. The actions.js dispatcher would otherwise rebuild the
	// answers array via expandKeys/FormData — this handler short-circuits that
	// path because state.answers is already a clean JS array. Capture-phase +
	// stopImmediatePropagation() keeps the bubble-phase listener in actions.js
	// from double-submitting.
	form.addEventListener( 'submit', async function ( e ) {
		e.preventDefault();
		e.stopImmediatePropagation();
		harvestState();

		// A3: flush the rich stem editor into its hidden field before serialising,
		// so the latest blocks are captured (Editor.js save() is async).
		if ( stemEditor && 'function' === typeof stemEditor.save ) {
			var stemInput = document.getElementById( 'lrn-question-stem-input' );
			try {
				var stemData = await stemEditor.save();
				if ( stemInput ) {
					stemInput.value = ( stemData && stemData.blocks && stemData.blocks.length )
						? JSON.stringify( stemData )
						: '';
				}
			} catch ( err ) { /* keep the prior hidden value on save failure */ }
		}

		var questionId = parseInt( ( form.querySelector( '[name="question_id"]' ) || {} ).value, 10 ) || 0;
		var isUpdate   = questionId > 0;
		var url        = restUrl + ( isUpdate ? 'questions/' + questionId : 'questions' );
		var method     = isUpdate ? 'PATCH' : 'POST';

		var body = { answers: [] };
		[ 'title', 'type', 'content', 'stem', 'explanation', 'hint', 'difficulty', 'category_id', 'status' ].forEach( function ( name ) {
			var el = form.querySelector( '[name="' + name + '"]' );
			if ( el && el.value !== '' ) {
				body[ name ] = el.value;
			}
		} );

		// Inline quiz attach (Basecamp #9928064574). Empty array = detach all,
		// non-empty = full replace. Omitting the param leaves slots untouched.
		var quizSelect = form.querySelector( 'select[name="quiz_ids[]"]' );
		if ( quizSelect ) {
			body.quiz_ids = Array.prototype.map.call(
				quizSelect.selectedOptions,
				function ( opt ) { return parseInt( opt.value, 10 ); }
			).filter( function ( id ) { return id > 0; } );
		}

		// Tags — this handler builds the body itself, so collect the checked
		// tag_ids[] boxes explicitly (the generic serializer never runs here).
		// Present-but-empty array clears tags; omitted leaves them untouched.
		var tagBoxes = form.querySelectorAll( 'input[name="tag_ids[]"]' );
		if ( tagBoxes.length ) {
			body.tag_ids = Array.prototype.filter.call(
				tagBoxes,
				function ( box ) { return box.checked; }
			).map( function ( box ) { return parseInt( box.value, 10 ); } );
		}

		body.answers = state.answers.map( function ( ans, i ) {
			return {
				content:    ans.content || '',
				is_correct: ans.is_correct ? 1 : 0,
				fraction:   ans.fraction || 0,
				feedback:   ans.feedback || '',
				group_id:   ans.group_id || '',
				// Preserve an explicit sort_order when the harvest set one
				// (matching pairs share a pair-index across their two rows);
				// fall back to array position for every other type.
				sort_order: ( typeof ans.sort_order === 'number' ? ans.sort_order : i ),
			};
		} );

		var submitBtn = form.querySelector( 'button[type="submit"]' );
		if ( submitBtn ) { submitBtn.disabled = true; submitBtn.classList.add( 'is-loading' ); }

		fetch( url, {
			method:      method,
			credentials: 'same-origin',
			headers:     {
				'Content-Type': 'application/json',
				'X-WP-Nonce':   restNonce,
			},
			body: JSON.stringify( body ),
		} )
			.then( function ( resp ) {
				return resp.json().then( function ( json ) {
					return { ok: resp.ok, json: json };
				} );
			} )
			.then( function ( out ) {
				if ( submitBtn ) { submitBtn.disabled = false; submitBtn.classList.remove( 'is-loading' ); }
				if ( ! out.ok ) {
					var msg = ( out.json && out.json.error && out.json.error.message )
						|| ( out.json && out.json.message )
						|| __( 'Save failed.', 'learnomy' );
					showToast( msg, 'error' );
					return;
				}
				var savedQuestion = out.json && out.json.data && out.json.data.question;
				var newId         = savedQuestion && savedQuestion.id ? parseInt( savedQuestion.id, 10 ) : questionId;
				var message       = isUpdate ? __( 'Question updated.', 'learnomy' ) : __( 'Question created.', 'learnomy' );

				// Which of the two depends on whether we are about to navigate.
				// Flashing unconditionally would park the message in storage on
				// the no-redirect branch, where it would then surface on
				// whatever page the author opened next.
				if ( newId ) {
					window.lrnToast.flash( message, 'success' );
					setTimeout( function () {
						window.location.href = 'admin.php?page=learnomy-question-editor&question_id=' + newId;
					}, 600 );
				} else {
					showToast( message, 'success' );
				}
			} )
			.catch( function ( err ) {
				if ( submitBtn ) { submitBtn.disabled = false; submitBtn.classList.remove( 'is-loading' ); }
				showToast( ( err && err.message ) || __( 'Network error.', 'learnomy' ), 'error' );
			} );
	}, true );

	// Delegates to the shared toast (`assets/js/shared/toast.js`), which is
	// enqueued on every Learnomy admin screen.
	//
	// This used to hand-roll its own element with the class `lrn-admin-toast`.
	// Nothing in either plugin styles that class — it has no CSS rule anywhere —
	// so the "toast" rendered as unstyled black text in normal document flow.
	// Measured on this page: position static, transparent background, no
	// padding, and a bounding box at y=2384 in a 900px viewport. It was 1484px
	// below the fold, which is why saving a question looked like it did nothing
	// (Basecamp 10185990987). The `is-visible` class it toggled has no rule
	// either.
	//
	// The real component was already loaded on the same page the whole time.
	function showToast( message, kind ) {
		window.lrnToast( message, kind || 'success' );
	}

	render();

	// ── Delete question ──────────────────────────────────────────────
	// Confirm-gated; deletes then returns to the bank (the editor can't show
	// a question that no longer exists). Detaching from quizzes cascades on
	// the server side.
	( function wireDeleteQuestion() {
		var deleteBtn = document.getElementById( 'lrn-delete-question' );
		if ( ! deleteBtn ) {
			return;
		}
		var questionId = parseInt( deleteBtn.getAttribute( 'data-question-id' ), 10 ) || 0;
		if ( ! questionId ) {
			return;
		}
		deleteBtn.addEventListener( 'click', function () {
			if ( 'function' !== typeof window.lrnConfirm ) {
				return;
			}
			window.lrnConfirm( {
				title:       i18n.deleteTitle || __( 'Delete this question?', 'learnomy' ),
				message:     i18n.deleteMessage || __( 'This permanently removes the question and detaches it from every quiz. Past attempt responses keep their recorded answer. This cannot be undone.', 'learnomy' ),
				destructive: true,
			} ).then( function ( ok ) {
				if ( ! ok ) {
					return;
				}
				deleteBtn.disabled = true;
				fetch( restUrl + 'questions/' + questionId, {
					method:  'DELETE',
					headers: { 'X-WP-Nonce': restNonce },
				} ).then( function ( r ) {
					if ( ! r.ok ) {
						throw new Error( 'delete failed' );
					}
					window.location.href = 'admin.php?page=learnomy-questions';
				} ).catch( function () {
					deleteBtn.disabled = false;
				} );
			} );
		} );
	} )();

} )();
