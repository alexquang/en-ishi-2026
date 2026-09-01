/**
 * Shared Editor.js tool set — the ONE place every Learnomy admin block editor
 * (course Description, lesson Content, and any future content area) reads its
 * tools from, so they are uniform: same blocks, same upload endpoints, same
 * behaviour. Do NOT hand-build a per-editor tools object anymore — call
 * `window.lrnEditorTools( { restUrl, nonce } )`.
 *
 * Each tool is registered only when its vendor bundle is present (the bundles
 * are loaded by `Admin::enqueue_editorjs_vendor()`), so a missing optional
 * plugin degrades gracefully instead of throwing.
 *
 * Upload-backed tools (image, attaches, link preview) post to the Learnomy
 * Media REST controller and MUST send the REST nonce — without it WP treats the
 * request as anonymous and `current_user_can('upload_files')` fails with 401.
 *
 * @package Learnomy
 */
( function ( window ) {
	'use strict';

	// Translatable strings via core wp.i18n when present; English fallback for any
	// context where wp-i18n isn't loaded (the script degrades, never throws).
	var __ = ( window.wp && window.wp.i18n && window.wp.i18n.__ )
		? window.wp.i18n.__
		: function ( s ) { return s; };

	/**
	 * Make the image block pick from the WordPress media library.
	 *
	 * WHY
	 * ---
	 * The block offered exactly two things: upload from your desktop, or paste a
	 * URL. Neither reaches an image already in the site's library, so an instructor
	 * reusing their own course banner had to download it and upload it again,
	 * leaving a duplicate attachment behind every time (support ticket
	 * 233992000086471681, lifestylefirst.nl).
	 *
	 * There is deliberately NO second button. `wp.media` opens on an "Upload files"
	 * tab sitting next to a "Media Library" tab, so the one control the block
	 * already has now does both — the same picker, in the same place, as every
	 * other image field in wp-admin. A separate "browse library" button would have
	 * made this block behave unlike the rest of WordPress for no gain.
	 *
	 * Choosing an existing image inserts its URL, so no copy is uploaded and the
	 * duplicates stop being created rather than being cleaned up afterwards.
	 *
	 * The frame's library is already scoped to the author by Media_Library_Scope,
	 * so an instructor sees their own uploads and not a colleague's. This inherits
	 * that scoping rather than re-implementing it.
	 *
	 * HOW
	 * ---
	 * Every route into the file dialog — clicking "Select an Image", and the
	 * automatic open when the block is added from the toolbox (`appendCallback()`
	 * clicks that button for you) — funnels through one instance method,
	 * `uploader.uploadSelectedFile()`. Replacing that per instance catches both.
	 *
	 * It is done by wrapping `render()`, which runs before either route can fire,
	 * because the uploader is built in the tool's constructor and neither it nor
	 * its class is reachable any other way. The result is handed back through the
	 * tool's own `onUpload()`, so the block fills itself exactly as it does after a
	 * normal upload — captions, tunes and the saved shape all unchanged.
	 *
	 * Patched once, on the shared tool class, so all four admin editors and the Pro
	 * front-end builder get it without a line of per-editor wiring.
	 *
	 * @param {Function} Tool The @editorjs/image class.
	 */
	function patchImageToolWithMediaLibrary( Tool ) {
		if ( ! Tool || ! Tool.prototype || Tool.prototype.lrnMediaLibraryPatched ) {
			return;
		}
		// No media frame (wp_enqueue_media() did not run) — leave the tool stock
		// rather than breaking the only way to add an image.
		if ( ! window.wp || ! window.wp.media ) {
			return;
		}

		Tool.prototype.lrnMediaLibraryPatched = true;

		var originalRender = Tool.prototype.render;

		Tool.prototype.render = function () {
			var tool = this;

			if ( tool.uploader && ! tool.uploader.lrnMediaLibraryPatched ) {
				tool.uploader.lrnMediaLibraryPatched = true;
				tool.uploader.uploadSelectedFile = function () {
					openMediaFrame( tool );
				};
			}

			return originalRender.apply( this, arguments );
		};
	}

	/**
	 * Put the block back to a state that matches its data.
	 *
	 * Cancelling the frame used to leave the block in `image-tool--uploading`
	 * with a spinner that never stopped, because nothing told the tool the
	 * upload it was waiting for is never coming. Reads the tool's own data so an
	 * ALREADY-FILLED block that the author opened and then cancelled keeps its
	 * image instead of being blanked.
	 *
	 * @param {Object} tool An @editorjs/image instance.
	 */
	function restoreToolState( tool ) {
		if ( ! tool.ui || 'function' !== typeof tool.ui.toggleStatus ) {
			return;
		}
		var data     = tool._data || {};
		var hasImage = !! ( data.file && data.file.url );
		tool.ui.toggleStatus( hasImage ? 'filled' : 'empty' );
	}

	/**
	 * Open the media frame and hand the chosen image back to the tool.
	 *
	 * The frame is built ONCE per tool instance and reused. Constructing it on
	 * every open left a new `.media-modal` in the DOM each time — two opens, two
	 * frames — each with its own listeners, which is a leak that grows for as
	 * long as the editor stays on screen.
	 *
	 * @param {Object} tool An @editorjs/image instance.
	 */
	function openMediaFrame( tool ) {
		if ( ! tool.lrnMediaFrame ) {
			var frame = window.wp.media( {
				title:    __( 'Select or upload an image', 'learnomy' ),
				button:   { text: __( 'Use this image', 'learnomy' ) },
				library:  { type: 'image' },
				multiple: false
			} );

			frame.on( 'select', function () {
				var picked = frame.state().get( 'selection' ).first();
				var media  = picked ? picked.toJSON() : null;

				if ( ! media || ! media.url ) {
					return;
				}

				tool.lrnMediaPicked = true;

				// The same shape the upload endpoint returns, so the tool takes its
				// normal path and we are not reimplementing how a block gets filled.
				tool.onUpload( { success: 1, file: { url: media.url } } );
			} );

			// Fires on every close, including after a successful pick — hence the
			// flag. Only a close with nothing chosen needs the block put back.
			frame.on( 'close', function () {
				if ( ! tool.lrnMediaPicked ) {
					restoreToolState( tool );
				}
				tool.lrnMediaPicked = false;
			} );

			tool.lrnMediaFrame = frame;
		}

		tool.lrnMediaPicked = false;

		// Reusing the frame means reusing the library collection it fetched the
		// first time, so an image added since (in another tab, or by someone else)
		// would not be listed until the page was reloaded. Bumping a throwaway prop
		// makes the query look new and forces the refetch — the standard idiom for
		// a cached wp.media frame. Cheap: it only refetches when the frame opens.
		var library = tool.lrnMediaFrame.state() && tool.lrnMediaFrame.state().get( 'library' );
		if ( library && library.props ) {
			library.props.set( { ignore: ( new Date() ).getTime() } );
		}

		tool.lrnMediaFrame.open();
	}

	/**
	 * The ONE embed block, defined once and reused by every editor (the wp-admin
	 * course/lesson/quiz editors AND the Pro front-end builder, which calls
	 * window.lrnEditorTools). It previews a pasted link through the SAME server
	 * renderer the learner sees — `\Learnomy\Content\Embed::render()` via the
	 * `/oembed-preview` REST endpoint — so author preview == published result, and
	 * it supports any oEmbed provider (not just YouTube/Vimeo). On save it stores
	 * just the source URL; Content_HTML turns that into the round-trip-safe
	 * placeholder the player hydrates. Replaces @editorjs/embed for uniform embeds.
	 */
	if ( ! window.LrnEmbedBlock ) {
		window.LrnEmbedBlock = class LrnEmbedBlock {
			static get toolbox() {
				return {
					title: __( 'Embed', 'learnomy' ),
					icon: '<svg width="17" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="m10 8 5 3-5 3z"/></svg>',
				};
			}

			constructor( params ) {
				params        = params || {};
				this.data     = params.data || {};
				this.config   = params.config || {};
				this.api      = params.api;
				this.readOnly = !! params.readOnly;
				this.wrapper  = null;
			}

			render() {
				this.wrapper           = window.document.createElement( 'div' );
				this.wrapper.className = 'lrn-editor-embed';
				var url = this.data.source || this.data.embed || '';
				if ( url ) {
					this._preview( url );
				} else {
					this._input();
				}
				return this.wrapper;
			}

			_input() {
				var self = this;
				this.wrapper.innerHTML = '';
				var input         = window.document.createElement( 'input' );
				input.type        = 'url';
				input.className   = 'lrn-editor-embed__input';
				input.placeholder = __( 'Paste a link (YouTube, Vimeo, etc.) and press Enter', 'learnomy' );
				input.addEventListener( 'keydown', function ( e ) {
					if ( 'Enter' === e.key ) {
						e.preventDefault();
						self._setUrl( input.value.trim() );
					}
				} );
				input.addEventListener( 'paste', function () {
					window.setTimeout( function () {
						self._setUrl( input.value.trim() );
					}, 0 );
				} );
				this.wrapper.appendChild( input );
				if ( ! this.readOnly ) {
					window.setTimeout( function () {
						input.focus();
					}, 0 );
				}
			}

			_setUrl( url ) {
				if ( ! url ) {
					return;
				}
				this.data.source = url;
				this._preview( url );
			}

			_preview( url ) {
				var self     = this;
				var endpoint = ( this.config.endpoint || '/wp-json/learnomy/v1/oembed-preview' ) + '?url=' + window.encodeURIComponent( url );
				this.wrapper.innerHTML = '<div class="lrn-editor-embed__status">' + __( 'Loading preview…', 'learnomy' ) + '</div>';

				window.fetch( endpoint, {
					headers: { 'X-WP-Nonce': this.config.nonce || '' },
					credentials: 'same-origin',
				} )
					.then( function ( r ) {
						return r.json();
					} )
					.then( function ( d ) {
						var html = ( d && d.data && d.data.html ) ? d.data.html : ( d && d.html ? d.html : '' );
						self.wrapper.innerHTML = '';
						var preview       = window.document.createElement( 'div' );
						preview.className = 'lrn-editor-embed__preview';
						preview.innerHTML = html || ( '<div class="lrn-editor-embed__status">' + __( 'Could not preview this link.', 'learnomy' ) + '</div>' );
						self.wrapper.appendChild( preview );
						if ( ! self.readOnly ) {
							self.wrapper.appendChild( self._bar( url ) );
						}
					} )
					.catch( function () {
						self.wrapper.innerHTML = '<div class="lrn-editor-embed__status">' + __( 'Preview unavailable - the link will still be saved.', 'learnomy' ) + '</div>';
						if ( ! self.readOnly ) {
							self.wrapper.appendChild( self._bar( url ) );
						}
					} );
			}

			_bar( url ) {
				var self = this;
				var bar  = window.document.createElement( 'div' );
				bar.className = 'lrn-editor-embed__bar';
				var label         = window.document.createElement( 'span' );
				label.className   = 'lrn-editor-embed__url';
				label.textContent = url;
				var replace         = window.document.createElement( 'button' );
				replace.type        = 'button';
				replace.className   = 'lrn-editor-embed__replace';
				replace.textContent = __( 'Replace', 'learnomy' );
				replace.addEventListener( 'click', function () {
					self.data.source = '';
					self._input();
				} );
				bar.appendChild( label );
				bar.appendChild( replace );
				return bar;
			}

			save() {
				return {
					source:  this.data.source || this.data.embed || '',
					caption: this.data.caption || '',
				};
			}

			static get sanitize() {
				return { source: false, embed: false, caption: {} };
			}
		};
	}

	/**
	 * Build the uniform Editor.js tools map.
	 *
	 * @param {Object} opts          Options.
	 * @param {string} opts.restUrl  Learnomy REST base, e.g. '/wp-json/learnomy/v1/'.
	 * @param {string} opts.nonce    `wp_rest` nonce for authenticated uploads.
	 * @return {Object} Editor.js `tools` config.
	 */
	window.lrnEditorTools = function ( opts ) {
		opts = opts || {};
		var restUrl = opts.restUrl || '';
		var nonce   = opts.nonce || '';

		var uploadHeaders = { 'X-WP-Nonce': nonce };
		var tools         = {};

		// ── Block tools ──────────────────────────────────────────────────────
		if ( 'undefined' !== typeof Header ) {
			tools.header = { class: Header, config: { levels: [ 2, 3, 4 ], defaultLevel: 2 }, inlineToolbar: true };
		}
		if ( 'undefined' !== typeof List ) {
			tools.list = { class: List, inlineToolbar: true };
		}
		if ( 'undefined' !== typeof ImageTool ) {
			// One picker for both jobs: upload a new file OR reuse one already in the
			// library, through the standard WordPress media frame.
			patchImageToolWithMediaLibrary( ImageTool );

			tools.image = {
				class:  ImageTool,
				config: {
					endpoints: { byFile: restUrl + 'media', byUrl: restUrl + 'media' },
					additionalRequestHeaders: uploadHeaders,
				},
			};

			// Width + alignment. Without this an author can upload an image but has
			// no way to say how big it should be or where it sits — the complaint
			// that started this. The tune saves to block.tunes.lrnImageLayout, which
			// Content_HTML turns into classes on the <figure>; the native
			// border/background/stretch tunes ride along in block.data and are now
			// persisted too (they used to be dropped on save, so they looked broken).
			if ( window.LrnImageLayout ) {
				tools.lrnImageLayout = { class: window.LrnImageLayout };
				tools.image.tunes    = [ 'lrnImageLayout' ];
			}
		}
		// The uniform embed block: previews through the server's Embed::render()
		// (one renderer, any oEmbed provider). Fall back to @editorjs/embed only if
		// LrnEmbedBlock somehow isn't defined.
		if ( window.LrnEmbedBlock ) {
			tools.embed = {
				class:  window.LrnEmbedBlock,
				config: { endpoint: restUrl + 'oembed-preview', nonce: nonce },
			};
		} else if ( 'undefined' !== typeof Embed ) {
			tools.embed = { class: Embed, config: { services: { youtube: true, vimeo: true } } };
		}
		if ( 'undefined' !== typeof Table ) {
			tools.table = { class: Table, inlineToolbar: true };
		}
		if ( 'undefined' !== typeof Quote ) {
			tools.quote = { class: Quote, inlineToolbar: true };
		}
		if ( 'undefined' !== typeof CodeTool ) {
			tools.code = CodeTool;
		}
		if ( 'undefined' !== typeof Checklist ) {
			tools.checklist = { class: Checklist, inlineToolbar: true };
		}
		if ( 'undefined' !== typeof Delimiter ) {
			tools.delimiter = Delimiter;
		}
		if ( 'undefined' !== typeof Warning ) {
			tools.warning = { class: Warning, inlineToolbar: true };
		}
		if ( 'undefined' !== typeof AttachesTool ) {
			tools.attaches = {
				class:  AttachesTool,
				config: { endpoint: restUrl + 'media', additionalRequestHeaders: uploadHeaders },
			};
		}
		if ( 'undefined' !== typeof RawTool ) {
			tools.raw = RawTool;
		}
		if ( 'undefined' !== typeof LinkTool ) {
			// LinkTool reads config.headers for its fetch (unlike the image/attaches
			// tools which use additionalRequestHeaders). The /fetchUrl route is
			// authenticated, so it MUST carry the REST nonce or WP returns 401.
			tools.linkTool = { class: LinkTool, config: { endpoint: restUrl + 'fetchUrl', headers: uploadHeaders } };
		}
		if ( 'undefined' !== typeof MathTex ) {
			tools.math = { class: MathTex };
		}

		// ── Inline tools ─────────────────────────────────────────────────────
		if ( 'undefined' !== typeof Marker ) {
			tools.marker = { class: Marker };
		}
		if ( 'undefined' !== typeof InlineCode ) {
			tools.inlineCode = { class: InlineCode };
		}
		if ( 'undefined' !== typeof Underline ) {
			tools.underline = { class: Underline };
		}

		// ── Extension seam (open by design) ──────────────────────────────────
		// It is the site owner's site — they (or any plugin/theme they install)
		// may add ANY Editor.js tool, or override/remove a core one, without
		// forking this file. Register on the shared global
		// `window.learnomyEditorTools`, an object keyed by block name. The same
		// registry feeds every Learnomy editor surface — the wp-admin editors
		// AND the Pro front-end builder — so a tool registered once shows up for
		// every author on both backend and frontend.
		//
		//   window.learnomyEditorTools = window.learnomyEditorTools || {};
		//   window.learnomyEditorTools.columns = { class: MyColumns, config: {} };
		//   window.learnomyEditorTools.warning = false; // drop a core tool
		//
		// Deliberately defensive: across thousands of installs a hand-registered
		// tool will occasionally be malformed. We skip the bad entry and keep
		// the editor usable rather than letting one tool break authoring for
		// everyone — the same graceful-degradation contract as a missing bundle.
		var extra = window.learnomyEditorTools;
		if ( extra && 'object' === typeof extra ) {
			Object.keys( extra ).forEach( function ( name ) {
				try {
					var def = extra[ name ];
					if ( ! def ) {
						delete tools[ name ]; // explicit owner opt-out of a core tool.
						return;
					}
					tools[ name ] = def; // add a new tool or override a core one.
				} catch ( err ) {
					if ( window.console && window.console.warn ) {
						window.console.warn( 'Learnomy: skipped invalid editor tool "' + name + '".', err );
					}
				}
			} );
		}

		return tools;
	};
} )( window );
