/**
 * Certificate Designer — Canva-like visual template builder.
 *
 * Vanilla JS only. No jQuery, no HTML Canvas API.
 * Elements are absolutely positioned DOM nodes within the canvas container.
 * All positions stored as percentages (0-100) for resolution independence.
 *
 * @package Learnomy
 */

( function () {
	'use strict';

	var __ = wp.i18n.__;

	/* ──────────────────────────────────────────────────────────────────────
	   Config & State
	   ────────────────────────────────────────────────────────────────────── */

	var config = window.learnomyCertDesigner || {};
	// `wp_localize_script` casts every scalar to a string, so `0` arrives as
	// `"0"` (truthy) — the previous `if ( state.templateId )` guard then
	// PATCH'd `/certificate-templates/0` for new templates and the server
	// returned 404 (bug card "Certificate Template Not Saving"). Coerce
	// to a real integer here so the create/update branch is correct.
	var state  = {
		templateId:  parseInt( config.templateId, 10 ) || 0,
		orientation: config.orientation || 'landscape',
		background:  { color: '#ffffff', image: null },
		elements:    [],
		selectedId:  null,
		nextId:      1,
		isDragging:  false,
		isResizing:  false,
		dragData:    null,
		saving:      false,
	};

	/* Merge tag sample data for live preview on the canvas. */
	var MERGE_TAGS = {
		'{student_name}':    'Jane Smith',
		'{course_title}':    'Introduction to Web Development',
		'{completion_date}': 'March 31, 2026',
		'{score}':           '95%',
		'{hours}':           '42',
		'{certificate_id}':  'CERT-A1B2C3D4',
		'{instructor_name}': 'John Doe',
		'{site_name}':       'Learnomy',
	};

	// Only families the PDF renderer can actually stand in for. Dompdf ships the
	// 14 PDF core fonts and nothing else, so every choice here is substituted -
	// what matters is that it is substituted with the right KIND of face, which
	// Certificate_Renderer::font_stack() now guarantees for each of these.
	//
	// Courier New was removed. It is a monospace, so honouring it means Dompdf's
	// Courier, which is far wider than the Times it silently printed before -
	// wide enough that a title wrapping to two lines goes to three and prints
	// through the line below. Offering a font whose only correct rendering
	// breaks the layout is worse than not offering it. Bringing it back needs
	// per-family width ratios in the wrap guard first.
	var AVAILABLE_FONTS = [
		'Georgia',
		'Times New Roman',
		'Inter',
		'Helvetica',
		'Arial',
		'Verdana',
		'Trebuchet MS',
	];

	/* ──────────────────────────────────────────────────────────────────────
	   Pre-built Templates
	   ────────────────────────────────────────────────────────────────────── */

	// Template applied to a brand-new certificate so the designer never opens
	// blank. Filterable target if a site ever wants a different starting point.
	var DEFAULT_PREBUILT = 'classic';

	var PREBUILT = {
		classic: {
			orientation: 'landscape',
			background:  { color: '#fffef5', image: null },
			elements: [
				{ id: 'el_1', type: 'line', x: 3, y: 3, width: 94, height: 0.4, color: '#c8a951', lineStyle: 'solid' },
				{ id: 'el_2', type: 'line', x: 3, y: 96, width: 94, height: 0.4, color: '#c8a951', lineStyle: 'solid' },
				{ id: 'el_3', type: 'line', x: 3, y: 3, width: 0.3, height: 93, color: '#c8a951', lineStyle: 'solid' },
				{ id: 'el_4', type: 'line', x: 96.7, y: 3, width: 0.3, height: 93, color: '#c8a951', lineStyle: 'solid' },
				{ id: 'el_5', type: 'text', text: __( 'Certificate of Completion', 'learnomy' ), x: 50, y: 18, font: 'Georgia', size: 32, color: '#1a1a1a', bold: false, align: 'center' },
				{ id: 'el_6', type: 'text', text: __( 'This is to certify that', 'learnomy' ), x: 50, y: 32, font: 'Georgia', size: 14, color: '#666666', bold: false, align: 'center' },
				{ id: 'el_7', type: 'merge_tag', tag: '{student_name}', x: 50, y: 42, font: 'Georgia', size: 36, color: '#1a1a1a', bold: true, align: 'center' },
				{ id: 'el_8', type: 'text', text: __( 'has successfully completed the course', 'learnomy' ), x: 50, y: 54, font: 'Georgia', size: 14, color: '#666666', bold: false, align: 'center' },
				{ id: 'el_9', type: 'merge_tag', tag: '{course_title}', x: 50, y: 64, font: 'Georgia', size: 22, color: '#333333', bold: true, align: 'center' },
				{ id: 'el_10', type: 'merge_tag', tag: '{completion_date}', x: 50, y: 78, font: 'Georgia', size: 14, color: '#666666', bold: false, align: 'center' },
				{ id: 'el_11', type: 'signature', label: __( 'Instructor', 'learnomy' ), x: 25, y: 85, font: 'Georgia', size: 12, color: '#333333' },
				{ id: 'el_12', type: 'signature', label: __( 'Director', 'learnomy' ), x: 75, y: 85, font: 'Georgia', size: 12, color: '#333333' },
				{ id: 'el_13', type: 'qr_code', x: 88, y: 78, size: 10 },
			],
		},
		modern: {
			orientation: 'landscape',
			background:  { color: '#ffffff', image: null },
			elements: [
				{ id: 'el_1', type: 'line', x: 0, y: 0, width: 100, height: 2.5, color: '#2383e2', lineStyle: 'solid' },
				{ id: 'el_2', type: 'text', text: __( 'CERTIFICATE', 'learnomy' ), x: 8, y: 14, font: 'Helvetica', size: 14, color: '#2383e2', bold: true, align: 'left' },
				{ id: 'el_3', type: 'text', text: __( 'OF COMPLETION', 'learnomy' ), x: 8, y: 20, font: 'Helvetica', size: 14, color: '#999999', bold: false, align: 'left' },
				{ id: 'el_4', type: 'merge_tag', tag: '{student_name}', x: 8, y: 38, font: 'Helvetica', size: 40, color: '#1a1a1a', bold: true, align: 'left' },
				{ id: 'el_5', type: 'text', text: __( 'Has successfully completed', 'learnomy' ), x: 8, y: 52, font: 'Helvetica', size: 14, color: '#666666', bold: false, align: 'left' },
				{ id: 'el_6', type: 'merge_tag', tag: '{course_title}', x: 8, y: 60, font: 'Helvetica', size: 22, color: '#333333', bold: true, align: 'left' },
				{ id: 'el_7', type: 'merge_tag', tag: '{completion_date}', x: 8, y: 76, font: 'Helvetica', size: 13, color: '#999999', bold: false, align: 'left' },
				{ id: 'el_8', type: 'signature', label: __( 'Instructor', 'learnomy' ), x: 8, y: 86, font: 'Helvetica', size: 12, color: '#333333' },
				{ id: 'el_9', type: 'qr_code', x: 85, y: 75, size: 12 },
			],
		},
		minimal: {
			orientation: 'landscape',
			background:  { color: '#ffffff', image: null },
			elements: [
				{ id: 'el_1', type: 'line', x: 10, y: 6, width: 80, height: 0.15, color: '#e0e0e0', lineStyle: 'solid' },
				{ id: 'el_2', type: 'line', x: 10, y: 94, width: 80, height: 0.15, color: '#e0e0e0', lineStyle: 'solid' },
				{ id: 'el_3', type: 'text', text: __( 'Certificate of Completion', 'learnomy' ), x: 50, y: 22, font: 'Inter', size: 18, color: '#999999', bold: false, align: 'center' },
				{ id: 'el_4', type: 'merge_tag', tag: '{student_name}', x: 50, y: 40, font: 'Inter', size: 36, color: '#1a1a1a', bold: true, align: 'center' },
				{ id: 'el_5', type: 'merge_tag', tag: '{course_title}', x: 50, y: 56, font: 'Inter', size: 18, color: '#555555', bold: false, align: 'center' },
				{ id: 'el_6', type: 'merge_tag', tag: '{completion_date}', x: 50, y: 68, font: 'Inter', size: 13, color: '#aaaaaa', bold: false, align: 'center' },
				{ id: 'el_7', type: 'signature', label: __( 'Authorized Signature', 'learnomy' ), x: 50, y: 82, font: 'Inter', size: 11, color: '#999999' },
				{ id: 'el_8', type: 'qr_code', x: 88, y: 82, size: 8 },
			],
		},
	};

	/* ──────────────────────────────────────────────────────────────────────
	   DOM References
	   ────────────────────────────────────────────────────────────────────── */

	var canvas      = document.getElementById( 'lrn-designer-canvas' );
	var sidebar     = document.getElementById( 'lrn-designer-sidebar' );
	var propsPanel  = document.getElementById( 'lrn-designer-props' );
	var propsBody   = document.getElementById( 'lrn-props-body' );
	var toastEl     = document.getElementById( 'lrn-designer-toast' );
	var nameInput   = document.getElementById( 'lrn-tpl-name' );
	var bgColorIn   = document.getElementById( 'lrn-bg-color' );
	var bgImageIn   = document.getElementById( 'lrn-bg-image' );
	var bgImageBtn  = document.getElementById( 'lrn-bg-image-btn' );
	var bgImageClr  = document.getElementById( 'lrn-bg-image-clear' );
	var saveBtn     = document.getElementById( 'lrn-designer-save' );
	var previewBtn  = document.getElementById( 'lrn-designer-preview' );

	/* ──────────────────────────────────────────────────────────────────────
	   Initialization
	   ────────────────────────────────────────────────────────────────────── */

	/**
	 * Defensive on() — wires a click/input handler only when the element
	 * exists. A missing DOM reference no longer aborts init() before the
	 * palette buttons get wired (regression class behind Sanjana's
	 * 2026-05-11 "Text element not adding" report — a single
	 * `null.addEventListener` upstream broke every downstream wiring).
	 */
	function on( el, event, handler ) {
		if ( el && typeof el.addEventListener === 'function' ) {
			el.addEventListener( event, handler );
		}
	}

	function init() {
		// Load existing design if editing; otherwise start a NEW certificate
		// from a sensible default pre-built template (Classic) so the canvas is
		// never blank and the admin isn't forced to pick a template every time.
		if ( config.designJson ) {
			loadDesign( config.designJson );
		} else if ( PREBUILT[ DEFAULT_PREBUILT ] ) {
			loadPrebuilt( DEFAULT_PREBUILT );
		}

		// Bind orientation toggles.
		var orientationRadios = document.querySelectorAll( 'input[name="lrn-orientation"]' );
		orientationRadios.forEach( function ( radio ) {
			on( radio, 'change', function () {
				state.orientation = this.value;
				applyOrientation();
			} );
		} );

		// Curated-library availability controls: reveal the specific-instructor
		// picker only for the "Specific" scope, and filter the (capped) list as
		// the admin types. Each guarded so a missing node never breaks init().
		wireAvailabilityControls();

		// Background controls — each guarded so a single missing element
		// doesn't break the whole designer.
		on( bgColorIn, 'input', function () {
			state.background.color = this.value;
			applyBackground();
		} );
		on( bgImageBtn, 'click', openMediaPicker );
		on( bgImageClr, 'click', clearBackgroundImage );

		// Element palette — the critical one Sanjana hit. Even if sidebar
		// is null (Pro extension stripped it / theme override), don't throw.
		if ( sidebar ) {
			var paletteBtns = sidebar.querySelectorAll( '[data-add]' );
			paletteBtns.forEach( function ( btn ) {
				on( btn, 'click', function () {
					addElement( this.getAttribute( 'data-add' ) );
				} );
			} );

			// Pre-built templates.
			var prebuiltBtns = sidebar.querySelectorAll( '[data-template]' );
			prebuiltBtns.forEach( function ( btn ) {
				on( btn, 'click', function () {
					var key = this.getAttribute( 'data-template' );
					if ( PREBUILT[ key ] ) {
						loadPrebuilt( key );
					}
				} );
			} );
		}

		// Canvas click deselects.
		on( canvas, 'mousedown', function ( e ) {
			if ( e.target === canvas ) {
				deselectAll();
			}
		} );

		// Global mouse events for drag/resize.
		document.addEventListener( 'mousemove', onMouseMove );
		document.addEventListener( 'mouseup', onMouseUp );

		// Keyboard shortcuts.
		document.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Delete' || e.key === 'Backspace' ) {
				// Only delete when not focused on an input.
				if ( state.selectedId && ! isInputFocused() ) {
					deleteElement( state.selectedId );
					e.preventDefault();
				}
			}
		} );

		// Save and preview.
		on( saveBtn, 'click', saveTemplate );
		on( previewBtn, 'click', previewTemplate );

		// Duplicate and delete buttons in properties panel (may be absent
		// if the properties partial is not rendered).
		on( document.getElementById( 'lrn-duplicate-el' ), 'click', function () {
			if ( state.selectedId ) {
				duplicateElement( state.selectedId );
			}
		} );
		on( document.getElementById( 'lrn-delete-el' ), 'click', function () {
			if ( state.selectedId ) {
				deleteElement( state.selectedId );
			}
		} );

		applyOrientation();
		applyBackground();

		// Enable preview button if we already have a saved template.
		if ( state.templateId && previewBtn ) {
			previewBtn.disabled = false;
		}
	}

	/* ──────────────────────────────────────────────────────────────────────
	   Design Load / Export
	   ────────────────────────────────────────────────────────────────────── */

	/*
	 * Legacy-schema map. Seeded default templates stored
	 * { fields:[{type,label,position}], background:'#fff', accent } instead of
	 * the { elements:[...] } schema the designer AND the server renderer use.
	 * Neither could read `fields`, so editing a default showed a BLANK canvas
	 * and it rendered via the generic fallback. Convert on load so the design
	 * shows and editing/saving upgrades it to the real schema.
	 */
	var LEGACY_FIELD_TAG = {
		student_name:    '{student_name}',
		course_name:     '{course_title}',
		course_title:    '{course_title}',
		issued_date:     '{completion_date}',
		completion_date: '{completion_date}',
		cert_uuid:       '{certificate_id}',
		certificate_id:  '{certificate_id}',
	};

	function normalizeLegacyDesign( design ) {
		design = design || {};

		// Background: legacy string colour -> { color, image } object.
		if ( 'string' === typeof design.background ) {
			design.background = { color: design.background, image: null };
		}

		// fields[] -> elements[] (only when there are no elements already).
		if ( ( ! design.elements || ! design.elements.length ) && design.fields && design.fields.length ) {
			design.elements = design.fields.map( function ( f, i ) {
				var pos  = f.position || {};
				var tag  = LEGACY_FIELD_TAG[ f.type ];
				var base = {
					id:    'el_' + ( i + 1 ),
					x:     'number' === typeof pos.x ? pos.x : 50,
					y:     'number' === typeof pos.y ? pos.y : ( 40 + i * 10 ),
					font:  'Georgia',
					size:  18,
					color: '#1a1a1a',
					bold:  false,
					align: 'center',
				};
				if ( tag ) {
					base.type = 'merge_tag';
					base.tag  = tag;
				} else {
					base.type = 'text';
					base.text = f.label || f.type || '';
				}
				return base;
			} );
		}

		return design;
	}

	function loadDesign( design ) {
		design            = normalizeLegacyDesign( design );
		state.orientation = design.orientation || 'landscape';
		state.background  = design.background || { color: '#ffffff', image: null };
		state.elements    = [];

		// Update UI controls.
		var orientationRadio = document.querySelector( 'input[name="lrn-orientation"][value="' + state.orientation + '"]' );
		if ( orientationRadio ) {
			orientationRadio.checked = true;
		}
		bgColorIn.value = state.background.color || '#ffffff';
		if ( state.background.image ) {
			bgImageIn.value = state.background.image;
			bgImageClr.hidden = false;
		}

		applyOrientation();
		applyBackground();

		// Load elements.
		if ( design.elements && design.elements.length ) {
			design.elements.forEach( function ( el ) {
				var id = el.id || generateId();
				// Track the next ID counter.
				var num = parseInt( id.replace( 'el_', '' ), 10 );
				if ( num >= state.nextId ) {
					state.nextId = num + 1;
				}
				state.elements.push( Object.assign( {}, el, { id: id } ) );
				renderElement( state.elements[ state.elements.length - 1 ] );
			} );
		}
	}

	function exportDesign() {
		return {
			orientation: state.orientation,
			background:  state.background,
			elements:    state.elements.map( function ( el ) {
				return Object.assign( {}, el );
			} ),
		};
	}

	function loadPrebuilt( key ) {
		// Clear canvas.
		state.elements.forEach( function ( el ) {
			var node = canvas.querySelector( '[data-el-id="' + el.id + '"]' );
			if ( node ) {
				canvas.removeChild( node );
			}
		} );
		state.elements  = [];
		state.selectedId = null;
		state.nextId     = 1;
		propsPanel.hidden = true;

		// Deep clone the template.
		var tpl = JSON.parse( JSON.stringify( PREBUILT[ key ] ) );
		loadDesign( tpl );
	}

	/* ──────────────────────────────────────────────────────────────────────
	   Canvas Orientation & Background
	   ────────────────────────────────────────────────────────────────────── */

	function applyOrientation() {
		canvas.classList.toggle( 'lrn-designer__canvas--portrait', 'portrait' === state.orientation );
		// Font sizes are expressed as a fraction of the PAGE width, and the page
		// is narrower in portrait, so every text element has to be re-measured
		// when the orientation flips.
		state.elements.forEach( function ( el ) {
			var node = canvas.querySelector( '[data-el-id="' + el.id + '"]' );
			if ( node ) {
				updateElementContent( node, el );
			}
		} );
	}

	function applyBackground() {
		canvas.style.backgroundColor = state.background.color || '#ffffff';
		if ( state.background.image ) {
			canvas.style.backgroundImage    = 'url(' + state.background.image + ')';
			canvas.style.backgroundSize     = 'cover';
			canvas.style.backgroundPosition = 'center';
		} else {
			canvas.style.backgroundImage = 'none';
		}
	}

	/* ──────────────────────────────────────────────────────────────────────
	   Element CRUD
	   ────────────────────────────────────────────────────────────────────── */

	function generateId() {
		return 'el_' + ( state.nextId++ );
	}

	function addElement( type ) {
		var el = { id: generateId(), type: type };

		switch ( type ) {
			case 'text':
				Object.assign( el, {
					text:  __( 'Your text here', 'learnomy' ),
					x: 50, y: 50,
					font: 'Helvetica', size: 18, color: '#333333',
					bold: false, align: 'center',
				} );
				break;

			case 'merge_tag':
				Object.assign( el, {
					tag:  '{student_name}',
					x: 50, y: 50,
					font: 'Georgia', size: 24, color: '#1a1a1a',
					bold: true, align: 'center',
				} );
				break;

			case 'image':
				Object.assign( el, {
					src:  '',
					x: 50, y: 10, width: 15, height: 10,
				} );
				// Open media picker immediately.
				state.elements.push( el );
				renderElement( el );
				selectElement( el.id );
				openImagePicker( el.id );
				return;

			case 'qr_code':
				Object.assign( el, {
					x: 85, y: 80, size: 10,
				} );
				break;

			case 'line':
				Object.assign( el, {
					x: 10, y: 50, width: 80, height: 0.3,
					color: '#333333', lineStyle: 'solid',
				} );
				break;

			case 'signature':
				Object.assign( el, {
					label: __( 'Signature', 'learnomy' ),
					x: 50, y: 85,
					font: 'Georgia', size: 12, color: '#333333',
				} );
				break;
		}

		state.elements.push( el );
		renderElement( el );
		selectElement( el.id );
	}

	function deleteElement( id ) {
		var idx = state.elements.findIndex( function ( e ) { return e.id === id; } );
		if ( idx === -1 ) {
			return;
		}
		state.elements.splice( idx, 1 );
		var node = canvas.querySelector( '[data-el-id="' + id + '"]' );
		if ( node ) {
			canvas.removeChild( node );
		}
		if ( state.selectedId === id ) {
			state.selectedId = null;
			propsPanel.hidden = true;
		}
	}

	function duplicateElement( id ) {
		var src = findElement( id );
		if ( ! src ) {
			return;
		}
		var copy = Object.assign( {}, src, {
			id: generateId(),
			x:  Math.min( src.x + 3, 95 ),
			y:  Math.min( src.y + 3, 95 ),
		} );
		state.elements.push( copy );
		renderElement( copy );
		selectElement( copy.id );
	}

	function findElement( id ) {
		return state.elements.find( function ( e ) { return e.id === id; } );
	}

	/* ──────────────────────────────────────────────────────────────────────
	   Render Element to Canvas DOM
	   ────────────────────────────────────────────────────────────────────── */

	function renderElement( el ) {
		var node = document.createElement( 'div' );
		node.className = 'lrn-designer__el lrn-designer__el--' + el.type;
		node.setAttribute( 'data-el-id', el.id );

		// Position via percentages.
		applyPositionToNode( node, el );

		// Inner content depends on type.
		updateElementContent( node, el );

		// Resize handles (4 corners).
		var corners = [ 'nw', 'ne', 'sw', 'se' ];
		corners.forEach( function ( dir ) {
			var handle = document.createElement( 'div' );
			handle.className = 'lrn-designer__resize-handle lrn-designer__resize-handle--' + dir;
			handle.setAttribute( 'data-resize', dir );
			node.appendChild( handle );
		} );

		// Mouse down for drag/select.
		node.addEventListener( 'mousedown', function ( e ) {
			// Resize handle?
			if ( e.target.hasAttribute( 'data-resize' ) ) {
				startResize( e, el.id, e.target.getAttribute( 'data-resize' ) );
				return;
			}
			selectElement( el.id );
			startDrag( e, el.id );
		} );

		canvas.appendChild( node );
	}

	function updateElementContent( node, el ) {
		// Remove previous non-handle children.
		var children = Array.from( node.childNodes );
		children.forEach( function ( child ) {
			if ( ! child.classList || ! child.classList.contains( 'lrn-designer__resize-handle' ) ) {
				node.removeChild( child );
			}
		} );

		var span, sigWrap, sigLine, sigLabel, inner, img;

		switch ( el.type ) {
			case 'text':
				span = document.createElement( 'span' );
				span.textContent = el.text || '';
				applyTextStyles( span, el );
				node.insertBefore( span, node.firstChild );
				break;

			case 'merge_tag':
				span = document.createElement( 'span' );
				span.textContent = MERGE_TAGS[ el.tag ] || el.tag;
				applyTextStyles( span, el );
				node.insertBefore( span, node.firstChild );
				break;

			case 'image':
				if ( el.src ) {
					// Reset inline styles from the placeholder state.
					node.style.display        = '';
					node.style.alignItems     = '';
					node.style.justifyContent = '';
					node.style.background     = '';
					node.style.border         = '';

					img = document.createElement( 'img' );
					img.src = el.src;
					img.alt = '';
					img.draggable = false;
					node.insertBefore( img, node.firstChild );
				} else {
					span = document.createElement( 'span' );
					span.textContent  = __( 'Select image', 'learnomy' );
					span.style.fontSize   = '12px';
					span.style.color      = '#999';
					span.style.fontFamily = 'inherit';
					node.insertBefore( span, node.firstChild );
					node.style.display        = 'flex';
					node.style.alignItems     = 'center';
					node.style.justifyContent = 'center';
					node.style.background     = '#f7f7f5';
					node.style.border         = '1px dashed #ccc';
				}
				break;

			case 'qr_code':
				node.insertBefore( buildQrPreview(), node.firstChild );
				break;

			case 'line':
				inner = document.createElement( 'div' );
				inner.className = 'lrn-designer__el--line-inner';
				inner.style.borderTopStyle = el.lineStyle || 'solid';
				inner.style.borderTopColor = el.color || '#333333';
				inner.style.borderTopWidth = Math.max( 1, ( el.height || 0.3 ) * ( canvas.clientHeight / 100 ) ) + 'px';
				node.insertBefore( inner, node.firstChild );
				node.style.color = el.color || '#333333';
				break;

			case 'signature':
				sigWrap = document.createElement( 'div' );
				sigWrap.style.textAlign  = 'center';
				sigWrap.style.fontFamily = el.font || 'Georgia';
				setScaledFontSize( sigWrap, el.size || 12 );
				sigWrap.style.color      = el.color || '#333333';

				sigLine = document.createElement( 'div' );
				sigLine.style.width     = '120px';
				sigLine.style.borderTop = '1px solid ' + ( el.color || '#333333' );
				sigLine.style.margin    = '0 auto 4px';

				sigLabel = document.createElement( 'div' );
				sigLabel.textContent = el.label || __( 'Signature', 'learnomy' );

				sigWrap.appendChild( sigLine );
				sigWrap.appendChild( sigLabel );
				node.insertBefore( sigWrap, node.firstChild );
				break;
		}
	}

	/**
	 * Page width of the printed certificate, in points, per orientation.
	 *
	 * class-certificate-renderer.php lays every element out on a fixed A4 page
	 * (297mm landscape / 210mm portrait) and emits the stored `size` verbatim as
	 * `font-size: <size>pt`. So the stored number is only meaningful RELATIVE to
	 * this page width — it is not a pixel measurement.
	 *
	 * 297mm = 841.89pt, 210mm = 595.28pt.
	 */
	var PAGE_PT = { landscape: 841.89, portrait: 595.28 };

	/**
	 * Convert a stored font size into a size that scales with the canvas.
	 *
	 * Everything else on this canvas is already resolution-independent: x, y,
	 * width and height are all percentages (see applyPositionToNode), so they
	 * follow the canvas as it grows and shrinks. Font size was the one property
	 * still written as a flat pixel literal, so on a narrow viewport — where the
	 * canvas is fluid (`width:100%` + `aspect-ratio`) — the text kept its
	 * desktop size and ran off both edges of the box.
	 *
	 * `cqw` is 1% of the canvas's own inline size, so expressing the font as a
	 * fraction of the page width makes the browser rescale it on every resize
	 * with no JS involved. It also makes the designer agree with the PDF for the
	 * first time: the same fraction of page width in both places.
	 *
	 * @param {number} size Stored font size (points on the printed page).
	 * @return {string} A CSS length in cqw.
	 */
	function scaledFontSize( size ) {
		var pageWidth = PAGE_PT[ state.orientation ] || PAGE_PT.landscape;
		return ( size / pageWidth * 100 ) + 'cqw';
	}

	/**
	 * Set font-size with a px fallback first, so a browser without container
	 * query units still renders readable text instead of dropping the
	 * declaration. Mirrors the CSS convention of emitting the fallback before
	 * the modern value.
	 *
	 * @param {HTMLElement} node Element to size.
	 * @param {number}      size Stored font size.
	 */
	function setScaledFontSize( node, size ) {
		node.style.fontSize = size + 'px';
		node.style.fontSize = scaledFontSize( size );
	}

	function applyTextStyles( span, el ) {
		span.style.fontFamily = el.font || 'Helvetica';
		setScaledFontSize( span, el.size || 16 );
		span.style.color      = el.color || '#333333';
		span.style.fontWeight = el.bold ? '700' : '400';
	}

	/**
	 * Build a real QR preview node for the designer canvas using the bundled,
	 * MIT-licensed qrcode-generator library (window.qrcode). The QR encodes the
	 * verify URL with sample data so what the designer sees matches what the
	 * rendered certificate will show. Falls back to a small placeholder if the
	 * library failed to load.
	 */
	function buildQrPreview() {
		var sampleUrl = ( config && config.verifyUrlSample )
			? config.verifyUrlSample
			: ( window.location.origin + '/verify/a1b2c3d4-e5f6-7890-abcd-ef1234567890/' );

		if ( typeof window.qrcode !== 'function' ) {
			var fallback = document.createElement( 'span' );
			fallback.textContent = __( 'QR', 'learnomy' );
			return fallback;
		}

		// typeNumber 0 lets the library auto-size to the data; level M matches
		// the server renderer (Certificate_Renderer::render_qr).
		var qr = window.qrcode( 0, 'M' );
		qr.addData( sampleUrl );
		qr.make();

		// createDataURL returns a self-contained GIF data-URI built from the QR
		// matrix (no markup, no external request). We assign it to an <img> via
		// DOM APIs only — never innerHTML — so the box-filling element carries
		// no injected markup.
		var img = document.createElement( 'img' );
		img.src           = qr.createDataURL( 4, 0 );
		img.alt           = '';
		img.draggable     = false;
		img.style.width   = '100%';
		img.style.height  = '100%';
		img.style.display = 'block';
		return img;
	}

	function applyPositionToNode( node, el ) {
		// Sized elements (image, qr_code, line) use explicit width/height.
		var hasWidth = ( 'image' === el.type || 'qr_code' === el.type || 'line' === el.type );

		if ( hasWidth ) {
			var w = el.width || el.size || 10;
			var h = el.height || el.size || 10;
			node.style.left      = el.x + '%';
			node.style.top       = el.y + '%';
			node.style.width     = w + '%';
			node.style.height    = h + '%';
			// A rule is anchored TOP-LEFT; an image/QR is centred on its coordinate.
			//
			// Rules are authored as "start here, run this far" — the frame in the
			// elegant designs is x:3, y:3, width:94 (3% -> 97%) and x:3, y:3,
			// width:0.3, height:93 (a left border down the page). Centring those
			// puts them at -44%..+50%, i.e. off the canvas, which is why the
			// "bordered" designs have never actually drawn their border here or in
			// the PDF. class-certificate-renderer.php anchors `line` the same way.
			node.style.transform = ( 'line' === el.type )
				? 'none'
				: 'translate(-50%, -50%)';
		} else {
			node.style.left  = el.x + '%';
			node.style.top   = el.y + '%';
			node.style.width = '';
			node.style.height = '';
			// Text alignment determines the horizontal transform origin.
			var tx = '-50%';
			if ( el.align === 'left' )  { tx = '0'; }
			if ( el.align === 'right' ) { tx = '-100%'; }
			node.style.transform = 'translateX(' + tx + ')';
		}
	}

	/* ──────────────────────────────────────────────────────────────────────
	   Selection
	   ────────────────────────────────────────────────────────────────────── */

	function selectElement( id ) {
		deselectAll();
		state.selectedId = id;
		var node = canvas.querySelector( '[data-el-id="' + id + '"]' );
		if ( node ) {
			node.classList.add( 'lrn-designer__el--selected' );
		}
		showProperties( id );
	}

	function deselectAll() {
		state.selectedId = null;
		var selected = canvas.querySelectorAll( '.lrn-designer__el--selected' );
		selected.forEach( function ( n ) { n.classList.remove( 'lrn-designer__el--selected' ); } );
		propsPanel.hidden = true;
	}

	/* ──────────────────────────────────────────────────────────────────────
	   Drag & Drop
	   ────────────────────────────────────────────────────────────────────── */

	function startDrag( e, id ) {
		e.preventDefault();
		var el = findElement( id );
		if ( ! el ) { return; }

		var rect = canvas.getBoundingClientRect();
		state.isDragging = true;
		state.dragData = {
			id:      id,
			startX:  e.clientX,
			startY:  e.clientY,
			origX:   el.x,
			origY:   el.y,
			cWidth:  rect.width,
			cHeight: rect.height,
		};

		var node = canvas.querySelector( '[data-el-id="' + id + '"]' );
		if ( node ) {
			node.classList.add( 'lrn-designer__el--dragging' );
		}
	}

	function startResize( e, id, dir ) {
		e.preventDefault();
		e.stopPropagation();
		var el = findElement( id );
		if ( ! el ) { return; }

		selectElement( id );

		var rect = canvas.getBoundingClientRect();
		state.isResizing = true;
		state.dragData = {
			id:       id,
			dir:      dir,
			startX:   e.clientX,
			startY:   e.clientY,
			origX:    el.x,
			origY:    el.y,
			origW:    el.width || el.size || 10,
			origH:    el.height || el.size || 10,
			cWidth:   rect.width,
			cHeight:  rect.height,
			elType:   el.type,
		};
	}

	function onMouseMove( e ) {
		if ( state.isDragging && state.dragData ) {
			var d  = state.dragData;
			var dx = ( ( e.clientX - d.startX ) / d.cWidth ) * 100;
			var dy = ( ( e.clientY - d.startY ) / d.cHeight ) * 100;

			var el = findElement( d.id );
			if ( ! el ) { return; }

			el.x = clamp( d.origX + dx, 0, 100 );
			el.y = clamp( d.origY + dy, 0, 100 );

			var node = canvas.querySelector( '[data-el-id="' + d.id + '"]' );
			if ( node ) {
				applyPositionToNode( node, el );
			}
		}

		if ( state.isResizing && state.dragData ) {
			var dr  = state.dragData;
			var ddx = ( ( e.clientX - dr.startX ) / dr.cWidth ) * 100;
			var ddy = ( ( e.clientY - dr.startY ) / dr.cHeight ) * 100;
			var el2 = findElement( dr.id );
			if ( ! el2 ) { return; }

			// Determine new width/height based on corner being dragged.
			var newW = dr.origW;
			var newH = dr.origH;

			if ( dr.dir.indexOf( 'e' ) !== -1 ) { newW = dr.origW + ddx; }
			if ( dr.dir.indexOf( 'w' ) !== -1 ) { newW = dr.origW - ddx; }
			if ( dr.dir.indexOf( 's' ) !== -1 ) { newH = dr.origH + ddy; }
			if ( dr.dir.indexOf( 'n' ) !== -1 ) { newH = dr.origH - ddy; }

			newW = Math.max( 2, newW );
			newH = Math.max( 0.2, newH );

			if ( 'qr_code' === dr.elType ) {
				// Keep QR square.
				el2.size = Math.max( 2, Math.max( newW, newH ) );
			} else {
				el2.width  = newW;
				el2.height = newH;
			}

			// Adjust position for NW/NE/SW corners.
			if ( dr.dir.indexOf( 'w' ) !== -1 ) {
				el2.x = dr.origX + ddx;
			}
			if ( dr.dir.indexOf( 'n' ) !== -1 ) {
				el2.y = dr.origY + ddy;
			}

			var node2 = canvas.querySelector( '[data-el-id="' + dr.id + '"]' );
			if ( node2 ) {
				applyPositionToNode( node2, el2 );
				updateElementContent( node2, el2 );
			}
		}
	}

	function onMouseUp() {
		if ( state.isDragging ) {
			state.isDragging = false;
			if ( state.dragData ) {
				var node = canvas.querySelector( '[data-el-id="' + state.dragData.id + '"]' );
				if ( node ) {
					node.classList.remove( 'lrn-designer__el--dragging' );
				}
			}
			state.dragData = null;
		}
		if ( state.isResizing ) {
			state.isResizing = false;
			state.dragData   = null;
			// Refresh properties panel.
			if ( state.selectedId ) {
				showProperties( state.selectedId );
			}
		}
	}

	/* ──────────────────────────────────────────────────────────────────────
	   Properties Panel
	   ────────────────────────────────────────────────────────────────────── */

	function showProperties( id ) {
		var el = findElement( id );
		if ( ! el ) { return; }

		propsPanel.hidden = false;

		// Clear previous contents safely.
		while ( propsBody.firstChild ) {
			propsBody.removeChild( propsBody.firstChild );
		}

		// Common: x / y position.
		appendFieldRow( propsBody, __( 'X (%)', 'learnomy' ), buildInputField( 'prop-x', el.x, 'number', '0', '100' ) );
		appendFieldRow( propsBody, __( 'Y (%)', 'learnomy' ), buildInputField( 'prop-y', el.y, 'number', '0', '100' ) );

		switch ( el.type ) {
			case 'text':
				appendFieldRow( propsBody, __( 'Text', 'learnomy' ), buildTextInput( 'prop-text', el.text || '' ) );
				appendTextFields( propsBody, el );
				break;

			case 'merge_tag':
				appendFieldRow( propsBody, __( 'Merge Tag', 'learnomy' ), buildMergeTagSelect( el.tag ) );
				appendTextFields( propsBody, el );
				break;

			case 'image':
				appendFieldRow( propsBody, __( 'Image URL', 'learnomy' ), buildImagePicker( el.src || '' ) );
				appendFieldRow( propsBody, __( 'Width (%)', 'learnomy' ), buildInputField( 'prop-width', el.width || 15, 'number', '1', '100' ) );
				appendFieldRow( propsBody, __( 'Height (%)', 'learnomy' ), buildInputField( 'prop-height', el.height || 10, 'number', '1', '100' ) );
				break;

			case 'qr_code':
				appendFieldRow( propsBody, __( 'Size (%)', 'learnomy' ), buildInputField( 'prop-size', el.size || 10, 'number', '2', '50' ) );
				break;

			case 'line':
				appendFieldRow( propsBody, __( 'Width (%)', 'learnomy' ), buildInputField( 'prop-width', el.width || 80, 'number', '1', '100' ) );
				appendFieldRow( propsBody, __( 'Height (%)', 'learnomy' ), buildInputField( 'prop-height', el.height || 0.3, 'number', '0.1', '100' ) );
				appendFieldRow( propsBody, __( 'Color', 'learnomy' ), buildColorInput( 'prop-color', el.color || '#333333' ) );
				appendFieldRow( propsBody, __( 'Style', 'learnomy' ), buildLineStyleSelect( el.lineStyle || 'solid' ) );
				break;

			case 'signature':
				appendFieldRow( propsBody, __( 'Label', 'learnomy' ), buildTextInput( 'prop-label', el.label || '' ) );
				appendFieldRow( propsBody, __( 'Font', 'learnomy' ), buildFontSelect( el.font ) );
				appendFieldRow( propsBody, __( 'Size (px)', 'learnomy' ), buildInputField( 'prop-size', el.size || 12, 'number', '8', '72' ) );
				appendFieldRow( propsBody, __( 'Color', 'learnomy' ), buildColorInput( 'prop-color', el.color || '#333333' ) );
				break;
		}

		// Bind change events.
		bindPropChange( 'prop-x', function ( v ) { el.x = parseFloat( v ); } );
		bindPropChange( 'prop-y', function ( v ) { el.y = parseFloat( v ); } );
		bindPropChange( 'prop-text', function ( v ) { el.text = v; } );
		bindPropChange( 'prop-tag', function ( v ) { el.tag = v; } );
		bindPropChange( 'prop-font', function ( v ) { el.font = v; } );
		bindPropChange( 'prop-size', function ( v ) { el.size = parseFloat( v ); } );
		bindPropChange( 'prop-color', function ( v ) { el.color = v; } );
		bindPropChange( 'prop-bold', function ( v ) { el.bold = v; }, true );
		bindPropChange( 'prop-width', function ( v ) { el.width = parseFloat( v ); } );
		bindPropChange( 'prop-height', function ( v ) { el.height = parseFloat( v ); } );
		bindPropChange( 'prop-label', function ( v ) { el.label = v; } );
		bindPropChange( 'prop-line-style', function ( v ) { el.lineStyle = v; } );
		bindPropChange( 'prop-src', function ( v ) { el.src = v; } );

		// Align buttons.
		var alignBtns = propsBody.querySelectorAll( '[data-align]' );
		alignBtns.forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				el.align = this.getAttribute( 'data-align' );
				alignBtns.forEach( function ( b ) { b.classList.remove( 'lrn-designer__align-btn--active' ); } );
				this.classList.add( 'lrn-designer__align-btn--active' );
				refreshNode( el );
			} );
		} );

		// Image select button in properties.
		var srcBtn = document.getElementById( 'prop-src-btn' );
		if ( srcBtn ) {
			srcBtn.addEventListener( 'click', function () {
				openImagePicker( el.id );
			} );
		}
	}

	function bindPropChange( fieldId, setter, isCheckbox ) {
		var field = document.getElementById( fieldId );
		if ( ! field ) { return; }

		var eventType = isCheckbox ? 'change' : 'input';
		field.addEventListener( eventType, function () {
			var val = isCheckbox ? this.checked : this.value;
			setter( val );
			refreshNode( findElement( state.selectedId ) );
		} );
	}

	function refreshNode( el ) {
		if ( ! el ) { return; }
		var node = canvas.querySelector( '[data-el-id="' + el.id + '"]' );
		if ( ! node ) { return; }
		applyPositionToNode( node, el );
		updateElementContent( node, el );
	}

	/* ──────────────────────────────────────────────────────────────────────
	   Properties Panel DOM Builders (safe — no innerHTML)
	   ────────────────────────────────────────────────────────────────────── */

	function appendFieldRow( parent, labelText, inputNode ) {
		var label = document.createElement( 'label' );
		label.className   = 'lrn-designer__label';
		label.textContent = labelText;
		parent.appendChild( label );
		parent.appendChild( inputNode );
	}

	function buildInputField( id, value, type, min, max ) {
		var input       = document.createElement( 'input' );
		input.type      = type;
		input.className = 'lrn-designer__input';
		input.id        = id;
		input.value     = value;
		if ( min ) { input.min = min; }
		if ( max ) { input.max = max; }
		if ( type === 'number' && parseFloat( min ) < 1 ) {
			input.step = '0.1';
		}
		return input;
	}

	function buildTextInput( id, value ) {
		var input       = document.createElement( 'input' );
		input.type      = 'text';
		input.className = 'lrn-designer__input';
		input.id        = id;
		input.value     = value;
		return input;
	}

	function buildColorInput( id, value ) {
		var input       = document.createElement( 'input' );
		input.type      = 'color';
		input.className = 'lrn-designer__color-input';
		input.id        = id;
		input.value     = value;
		return input;
	}

	function buildFontSelect( current ) {
		var select       = document.createElement( 'select' );
		select.className = 'lrn-designer__select';
		select.id        = 'prop-font';
		AVAILABLE_FONTS.forEach( function ( f ) {
			var opt = document.createElement( 'option' );
			opt.value       = f;
			opt.textContent = f;
			if ( f === current ) { opt.selected = true; }
			select.appendChild( opt );
		} );
		return select;
	}

	function buildMergeTagSelect( current ) {
		var select       = document.createElement( 'select' );
		select.className = 'lrn-designer__select';
		select.id        = 'prop-tag';
		Object.keys( MERGE_TAGS ).forEach( function ( t ) {
			var opt = document.createElement( 'option' );
			opt.value       = t;
			opt.textContent = t;
			if ( t === current ) { opt.selected = true; }
			select.appendChild( opt );
		} );
		return select;
	}

	function buildLineStyleSelect( current ) {
		var select       = document.createElement( 'select' );
		select.className = 'lrn-designer__select';
		select.id        = 'prop-line-style';
		[ 'solid', 'dashed', 'dotted', 'double' ].forEach( function ( s ) {
			var opt = document.createElement( 'option' );
			opt.value       = s;
			opt.textContent = s.charAt( 0 ).toUpperCase() + s.slice( 1 );
			if ( s === current ) { opt.selected = true; }
			select.appendChild( opt );
		} );
		return select;
	}

	function buildImagePicker( src ) {
		var wrapper       = document.createElement( 'div' );
		wrapper.className = 'lrn-designer__image-picker';

		var input       = document.createElement( 'input' );
		input.type      = 'text';
		input.className = 'lrn-designer__input';
		input.id        = 'prop-src';
		input.value     = src;
		input.readOnly  = true;

		var btn           = document.createElement( 'button' );
		btn.type          = 'button';
		btn.className     = 'lrn-admin-btn lrn-admin-btn--sm';
		btn.id            = 'prop-src-btn';
		btn.textContent   = __( 'Select', 'learnomy' );

		wrapper.appendChild( input );
		wrapper.appendChild( btn );
		return wrapper;
	}

	function buildAlignButtons( current ) {
		var wrapper       = document.createElement( 'div' );
		wrapper.className = 'lrn-designer__align-group';
		var aligns = [ 'left', 'center', 'right' ];
		var labels = { left: 'L', center: 'C', right: 'R' };
		aligns.forEach( function ( a ) {
			var btn           = document.createElement( 'button' );
			btn.type          = 'button';
			btn.className     = 'lrn-designer__align-btn' + ( a === current ? ' lrn-designer__align-btn--active' : '' );
			btn.setAttribute( 'data-align', a );
			btn.textContent   = labels[ a ];
			wrapper.appendChild( btn );
		} );
		return wrapper;
	}

	function appendTextFields( parent, el ) {
		appendFieldRow( parent, __( 'Font', 'learnomy' ), buildFontSelect( el.font ) );
		appendFieldRow( parent, __( 'Size (px)', 'learnomy' ), buildInputField( 'prop-size', el.size || 16, 'number', '8', '120' ) );
		appendFieldRow( parent, __( 'Color', 'learnomy' ), buildColorInput( 'prop-color', el.color || '#333333' ) );

		// Bold checkbox row.
		var checkRow       = document.createElement( 'div' );
		checkRow.className = 'lrn-designer__checkbox-row';
		var checkbox    = document.createElement( 'input' );
		checkbox.type   = 'checkbox';
		checkbox.id     = 'prop-bold';
		checkbox.checked = !! el.bold;
		var checkLabel       = document.createElement( 'label' );
		checkLabel.htmlFor   = 'prop-bold';
		checkLabel.textContent = __( 'Bold', 'learnomy' );
		checkRow.appendChild( checkbox );
		checkRow.appendChild( checkLabel );
		parent.appendChild( checkRow );

		appendFieldRow( parent, __( 'Align', 'learnomy' ), buildAlignButtons( el.align || 'center' ) );
	}

	/* ──────────────────────────────────────────────────────────────────────
	   Media Library Integration
	   ────────────────────────────────────────────────────────────────────── */

	function openMediaPicker() {
		if ( ! window.wp || ! window.wp.media ) { return; }

		var frame = wp.media( {
			title:    __( 'Select Background Image', 'learnomy' ),
			multiple: false,
			library:  { type: 'image' },
		} );

		frame.on( 'select', function () {
			var attachment = frame.state().get( 'selection' ).first().toJSON();
			state.background.image = attachment.url;
			bgImageIn.value = attachment.url;
			bgImageClr.hidden = false;
			applyBackground();
		} );

		frame.open();
	}

	function clearBackgroundImage() {
		state.background.image = null;
		bgImageIn.value = '';
		bgImageClr.hidden = true;
		applyBackground();
	}

	function openImagePicker( elId ) {
		if ( ! window.wp || ! window.wp.media ) { return; }

		var frame = wp.media( {
			title:    __( 'Select Image', 'learnomy' ),
			multiple: false,
			library:  { type: 'image' },
		} );

		frame.on( 'select', function () {
			var attachment = frame.state().get( 'selection' ).first().toJSON();
			var el = findElement( elId );
			if ( el ) {
				el.src = attachment.url;
				refreshNode( el );
				// Update properties panel if still selected.
				var srcInput = document.getElementById( 'prop-src' );
				if ( srcInput ) {
					srcInput.value = attachment.url;
				}
			}
		} );

		frame.open();
	}

	/* ──────────────────────────────────────────────────────────────────────
	   Availability governance controls
	   ────────────────────────────────────────────────────────────────────── */

	function wireAvailabilityControls() {
		var availEl    = document.getElementById( 'lrn-tpl-availability' );
		var specific   = document.getElementById( 'lrn-tpl-specific-wrap' );
		var searchEl   = document.getElementById( 'lrn-tpl-instructor-search' );
		var instrSel   = document.getElementById( 'lrn-tpl-instructors' );

		on( availEl, 'change', function () {
			if ( specific ) {
				specific.hidden = ( 'specific' !== this.value );
			}
		} );

		// Client-side filter over the capped instructor option list.
		on( searchEl, 'input', function () {
			if ( ! instrSel ) { return; }
			var q = this.value.toLowerCase();
			Array.prototype.forEach.call( instrSel.options, function ( opt ) {
				opt.hidden = q && opt.text.toLowerCase().indexOf( q ) === -1;
			} );
		} );
	}

	/* ──────────────────────────────────────────────────────────────────────
	   Save & Preview
	   ────────────────────────────────────────────────────────────────────── */

	function saveTemplate() {
		if ( state.saving ) { return; }

		var title = nameInput.value.trim();
		if ( ! title ) {
			showToast( config.i18n.titleRequired, true );
			nameInput.focus();
			return;
		}

		state.saving = true;
		saveBtn.disabled = true;

		var payload = {
			title:       title,
			design_json: exportDesign(),
			orientation: state.orientation,
			status:      'active',
		};

		// Curated-library governance: certificate type + instructor availability.
		var kindEl = document.getElementById( 'lrn-tpl-kind' );
		if ( kindEl ) {
			payload.kind = kindEl.value;
		}
		var availEl = document.getElementById( 'lrn-tpl-availability' );
		if ( availEl ) {
			payload.availability = availEl.value;
			if ( 'specific' === availEl.value ) {
				var instrSel = document.getElementById( 'lrn-tpl-instructors' );
				payload.instructors = instrSel
					? Array.prototype.slice.call( instrSel.selectedOptions )
						.map( function ( o ) { return parseInt( o.value, 10 ) || 0; } )
						.filter( Boolean )
					: [];
			} else {
				payload.instructors = [];
			}
		}

		var url    = config.restUrl + 'certificate-templates';
		var method = 'POST';

		if ( state.templateId ) {
			url    += '/' + state.templateId;
			method  = 'PATCH';
		}

		fetch( url, {
			method:  method,
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce':   config.nonce,
			},
			body: JSON.stringify( payload ),
		} )
		.then( function ( response ) { return response.json(); } )
		.then( function ( result ) {
			state.saving     = false;
			saveBtn.disabled = false;

			if ( result.data && result.data.id ) {
				state.templateId = parseInt( result.data.id, 10 ) || 0;
				// Capture the freshly-minted preview token so the new-tab
				// preview works immediately after save without a reload.
				if ( result.data.preview_token ) {
					state.previewToken = result.data.preview_token;
				}
				// Update the browser URL so subsequent saves are PATCHes.
				var newUrl = window.location.pathname + '?page=learnomy-certificate-designer&template_id=' + result.data.id;
				window.history.replaceState( {}, '', newUrl );
				previewBtn.disabled = false;
				showToast( config.i18n.saved );
			} else {
				showToast( config.i18n.saveError, true );
			}
		} )
		.catch( function () {
			state.saving     = false;
			saveBtn.disabled = false;
			showToast( config.i18n.saveError, true );
		} );
	}

	function previewTemplate() {
		if ( ! state.templateId ) { return; }
		// Auth via signed preview token, NOT cookie+nonce. window.open()
		// in a new tab doesn't always carry the WP auth cookie — that's
		// what surfaced as `learnomy_unauthorized` 401 in the original
		// bug card "Error Appears on Certificate Preview PDF". The token
		// is server-signed at localize time and valid for 24h, so the
		// new tab can authenticate without a session cookie.
		var token = state.previewToken || config.previewToken;
		if ( ! token ) {
			showToast( config.i18n.saveError, true );
			return;
		}
		var url = config.restUrl + 'certificate-templates/' + state.templateId + '/preview'
			+ '?token=' + encodeURIComponent( token );
		window.open( url, '_blank' );
	}

	/* ──────────────────────────────────────────────────────────────────────
	   Utilities
	   ────────────────────────────────────────────────────────────────────── */

	function clamp( val, min, max ) {
		return Math.min( Math.max( val, min ), max );
	}

	function isInputFocused() {
		var tag = document.activeElement && document.activeElement.tagName;
		return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT';
	}

	var toastTimer = null;

	function showToast( message, isError ) {
		if ( toastTimer ) {
			clearTimeout( toastTimer );
		}
		toastEl.textContent = message;
		toastEl.classList.toggle( 'lrn-designer__toast--error', !! isError );
		toastEl.classList.add( 'lrn-designer__toast--visible' );
		toastTimer = setTimeout( function () {
			toastEl.classList.remove( 'lrn-designer__toast--visible' );
		}, 3000 );
	}

	/* ──────────────────────────────────────────────────────────────────────
	   Boot
	   ────────────────────────────────────────────────────────────────────── */

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

} )();
