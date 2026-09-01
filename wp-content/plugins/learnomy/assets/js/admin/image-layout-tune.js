/**
 * Image layout — a Block Tune for the Editor.js image block: width + alignment.
 *
 * WHY THIS EXISTS
 * ---------------
 * Instructors reported not being able to use an uploaded image at the size they
 * wanted. There was no size or alignment control anywhere: not in the editor, not
 * in the schema, not in the rendered HTML. The only sizing affordance Editor.js
 * gives you for free — the native "Stretch image" tune — was being silently
 * dropped by the HTML storage layer, so even that toggle appeared to do nothing.
 *
 * THE VOCABULARY
 * --------------
 * We mirror WordPress's own words (left / center / right, plus a simple width
 * scale) instead of inventing a private one. Site owners already know what "half
 * width, aligned left" means, and it maps onto what they expect from the block
 * editor. The tune stores ONLY the choice — `{ width: '50', align: 'left' }` —
 * and Content_HTML turns that into classes on the <figure>. The CSS owns the
 * looks; this file owns the intent.
 *
 * Defaults (100% / center) are stored as-is but emit no class, which keeps the
 * HTML clean and the content round-trip a stable fixpoint.
 *
 * @package Learnomy
 */

( function () {
	'use strict';

	var WIDTHS = [
		{ value: '25', label: '25%' },
		{ value: '50', label: '50%' },
		{ value: '75', label: '75%' },
		{ value: '100', label: '100%' }
	];

	var DEFAULTS = { width: '100', align: 'center' };

	function t( key, fallback ) {
		var i18n = ( window.LearnomyEditor && window.LearnomyEditor.i18n ) || {};
		return i18n[ key ] || fallback;
	}

	// Align labels go through t() like every other string — they used to be raw
	// English literals that no locale could translate. Built after t() is defined.
	var ALIGNS = [
		{ value: 'left', label: t( 'alignLeft', 'Left' ), icon: 'align-left' },
		{ value: 'center', label: t( 'alignCenter', 'Center' ), icon: 'align-center' },
		{ value: 'right', label: t( 'alignRight', 'Right' ), icon: 'align-right' }
	];

	/**
	 * Editor.js Block Tune. Saves to `block.tunes.lrnImageLayout`.
	 */
	function LrnImageLayout( opts ) {
		opts = opts || {};

		this.api   = opts.api;
		this.block = opts.block;

		var data   = opts.data || {};
		this.data  = {
			width: WIDTHS.some( function ( w ) { return w.value === data.width; } ) ? data.width : DEFAULTS.width,
			align: ALIGNS.some( function ( a ) { return a.value === data.align; } ) ? data.align : DEFAULTS.align
		};
	}

	LrnImageLayout.isTune = true;

	/**
	 * Mirror the choice onto the block in the editor, so the author SEES the size
	 * they picked instead of having to save and reload to find out. Same class
	 * names the front end uses, so the editor preview and the published lesson
	 * cannot disagree.
	 */
	LrnImageLayout.prototype.applyPreview = function () {
		var holder = this.block && this.block.holder;
		if ( ! holder ) {
			return;
		}

		var wrap = holder.querySelector( '.image-tool' ) || holder;

		WIDTHS.forEach( function ( w ) {
			wrap.classList.remove( 'lrn-image--w-' + w.value );
		} );
		ALIGNS.forEach( function ( a ) {
			wrap.classList.remove( 'lrn-image--' + a.value );
		} );

		wrap.classList.add( 'lrn-image-preview' );
		if ( '100' !== this.data.width ) {
			wrap.classList.add( 'lrn-image--w-' + this.data.width );
		}
		if ( 'center' !== this.data.align ) {
			wrap.classList.add( 'lrn-image--' + this.data.align );
		}
	};

	LrnImageLayout.prototype.set = function ( key, value, group ) {
		this.data[ key ] = value;

		// Repaint the segmented control.
		Array.prototype.forEach.call( group.children, function ( btn ) {
			var active = btn.dataset.value === value;
			btn.classList.toggle( 'lrn-tune-seg__btn--active', active );
			btn.setAttribute( 'aria-checked', active ? 'true' : 'false' );
		} );

		this.applyPreview();

		// Mark the block dirty so Editor.js actually persists the change.
		if ( this.block && typeof this.block.dispatchChange === 'function' ) {
			this.block.dispatchChange();
		}
	};

	LrnImageLayout.prototype.segmented = function ( legend, options, key ) {
		var self = this;

		var row = document.createElement( 'div' );
		row.className = 'lrn-tune-row';

		var label = document.createElement( 'span' );
		label.className = 'lrn-tune-row__label';
		label.textContent = legend;
		row.appendChild( label );

		var group = document.createElement( 'div' );
		group.className = 'lrn-tune-seg';
		group.setAttribute( 'role', 'radiogroup' );
		group.setAttribute( 'aria-label', legend );

		options.forEach( function ( opt ) {
			var btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'lrn-tune-seg__btn';
			btn.dataset.value = opt.value;
			btn.textContent = opt.label;
			btn.setAttribute( 'role', 'radio' );

			var active = self.data[ key ] === opt.value;
			btn.setAttribute( 'aria-checked', active ? 'true' : 'false' );
			if ( active ) {
				btn.classList.add( 'lrn-tune-seg__btn--active' );
			}

			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				e.stopPropagation();
				self.set( key, opt.value, group );
			} );

			group.appendChild( btn );
		} );

		row.appendChild( group );
		return row;
	};

	LrnImageLayout.prototype.render = function () {
		var wrap = document.createElement( 'div' );
		wrap.className = 'lrn-image-tune';

		wrap.appendChild( this.segmented( t( 'imageWidth', 'Width' ), WIDTHS, 'width' ) );
		wrap.appendChild( this.segmented( t( 'imageAlign', 'Align' ), ALIGNS, 'align' ) );

		// Paint the current state onto the block as soon as the settings open, so
		// an image saved at 50% shows at 50% in the editor too.
		this.applyPreview();

		return wrap;
	};

	LrnImageLayout.prototype.save = function () {
		return this.data;
	};

	window.LrnImageLayout = LrnImageLayout;
}() );
