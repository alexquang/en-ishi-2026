/**
 * Lesson Editor — Editor.js integration and full lesson-form wiring.
 *
 * Vanilla JS, no jQuery. Communicates with the Learnomy REST API via fetch().
 * Reads configuration from `window.learnomyLessonEditor` which is localized
 * from PHP via wp_localize_script().
 *
 * Renders the full `lrn_lessons` data model: course, section, parent lesson
 * (topic), sort order, content type, duration (minutes + seconds), preview.
 *
 * @package Learnomy
 */

( function () {
	'use strict';

	var __      = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;

	var config   = window.learnomyLessonEditor || {};
	var restUrl  = config.restUrl || '';
	var nonce    = config.nonce || '';
	var content  = config.content || {};
	var lessonId = config.lessonId || 0;
	var courseId  = config.courseId || 0;
	var sectionId = config.sectionId || 0;
	var parentId  = config.parentId || 0;

	var editor   = null;
	var isSaving = false;

	/**
	 * Parse the stored lesson body into Editor.js data.
	 *
	 * Content is canonically Editor.js JSON (what this editor saves). But a
	 * lesson body can also be plain HTML — seeded fixtures, imports, or rows
	 * written through the REST API. The frontend renderer tolerates both
	 * (render_editorjs_content() falls back to wp_kses_post on non-JSON), so
	 * the editor must too: otherwise an HTML-bodied lesson opens blank and a
	 * save silently overwrites the existing content with empty blocks.
	 */
	function parseContent( raw ) {
		if ( ! raw ) {
			return {};
		}
		if ( typeof raw !== 'string' ) {
			return raw;
		}
		var trimmed = raw.trim();
		if ( '{' === trimmed.charAt( 0 ) ) {
			try {
				return JSON.parse( trimmed );
			} catch ( e ) {
				// Not valid Editor.js JSON after all — fall through to HTML import.
			}
		}
		return htmlToBlocks( trimmed );
	}

	/**
	 * Convert a legacy HTML body into Editor.js blocks so it loads (and can be
	 * edited) instead of showing blank. Parsed with DOMParser (no script
	 * execution, no innerHTML writes); Editor.js sanitises the inline markup
	 * carried in each block on render. Maps the common block-level tags;
	 * anything else degrades to a paragraph carrying its text.
	 */
	function htmlToBlocks( html ) {
		if ( ! html || typeof DOMParser === 'undefined' ) {
			return {};
		}
		var body = new DOMParser().parseFromString( html, 'text/html' ).body;
		if ( ! body ) {
			return {};
		}

		var blocks = [];
		var pushParagraph = function ( text ) {
			var t = ( text || '' ).trim();
			if ( t ) {
				blocks.push( { type: 'paragraph', data: { text: t } } );
			}
		};

		Array.prototype.forEach.call( body.childNodes, function ( node ) {
			if ( 3 === node.nodeType ) {
				pushParagraph( node.textContent );
				return;
			}
			if ( 1 !== node.nodeType ) {
				return;
			}
			var tag = node.tagName.toLowerCase();
			if ( 'p' === tag || 'div' === tag ) {
				pushParagraph( node.innerHTML );
			} else if ( /^h[1-6]$/.test( tag ) ) {
				var level = Math.min( 4, Math.max( 2, parseInt( tag.charAt( 1 ), 10 ) ) );
				var htext = node.innerHTML.trim();
				if ( htext ) {
					blocks.push( { type: 'header', data: { text: htext, level: level } } );
				}
			} else if ( 'ul' === tag || 'ol' === tag ) {
				var items = [];
				Array.prototype.forEach.call( node.children, function ( li ) {
					if ( 'li' === li.tagName.toLowerCase() ) {
						var it = li.innerHTML.trim();
						if ( it ) {
							items.push( it );
						}
					}
				} );
				if ( items.length ) {
					blocks.push( { type: 'list', data: { style: 'ol' === tag ? 'ordered' : 'unordered', items: items } } );
				}
			} else {
				pushParagraph( node.textContent );
			}
		} );

		return blocks.length ? { blocks: blocks } : {};
	}

	function initEditor() {
		var data = parseContent( content );

		// Uniform tool set from the one shared factory — the SAME blocks the
		// course Description (and every other content area) gets, so they never
		// drift. Image / attaches upload to the Media REST controller with the
		// page's nonce (without X-WP-Nonce the upload 401s — Basecamp #9925216970).
		var tools = ( 'function' === typeof window.lrnEditorTools )
			? window.lrnEditorTools( { restUrl: restUrl, nonce: nonce } )
			: {};

		editor = new EditorJS( {
			holder: 'lrn-editor',
			tools: tools,
			data: data && data.blocks ? data : {},
			placeholder: config.i18n && config.i18n.placeholder ? config.i18n.placeholder : __( 'Start writing your lesson content…', 'learnomy' )
		} );
	}

	/**
	 * Extract plain text from Editor.js blocks for FULLTEXT search indexing.
	 */
	function extractPlainText( savedData ) {
		if ( ! savedData || ! savedData.blocks ) {
			return '';
		}
		var parts  = [];
		var blocks = savedData.blocks;

		for ( var i = 0; i < blocks.length; i++ ) {
			var block = blocks[ i ];
			var data  = block.data || {};

			switch ( block.type ) {
				case 'paragraph':
				case 'header':
				case 'quote':
				case 'warning':
					if ( data.text ) parts.push( stripHtml( data.text ) );
					if ( data.title ) parts.push( stripHtml( data.title ) );
					if ( data.message ) parts.push( stripHtml( data.message ) );
					if ( data.caption ) parts.push( stripHtml( data.caption ) );
					break;
				case 'list':
				case 'checklist':
					if ( data.items && data.items.length ) {
						for ( var j = 0; j < data.items.length; j++ ) {
							var item = data.items[ j ];
							var text = typeof item === 'string' ? item : ( item.text || item.content || '' );
							parts.push( stripHtml( text ) );
						}
					}
					break;
				case 'code':
					if ( data.code ) parts.push( data.code );
					break;
				case 'table':
					if ( data.content && data.content.length ) {
						for ( var r = 0; r < data.content.length; r++ ) {
							var row = data.content[ r ];
							for ( var c = 0; c < row.length; c++ ) {
								parts.push( stripHtml( row[ c ] ) );
							}
						}
					}
					break;
				case 'image':
				case 'embed':
					if ( data.caption ) parts.push( stripHtml( data.caption ) );
					break;
			}
		}
		return parts.join( '\n' ).trim();
	}

	function stripHtml( html ) {
		if ( ! html ) return '';
		return html.replace( /<[^>]*>/g, '' );
	}

	function showToast( message, type ) {
		var toast = document.getElementById( 'lrn-toast' );
		if ( ! toast ) return;
		toast.textContent = message;
		toast.className   = 'lrn-lesson-editor__toast lrn-lesson-editor__toast--' + ( type || 'success' );
		toast.style.display = 'block';
		setTimeout( function () { toast.style.display = 'none'; }, 4000 );
	}

	function setSaving( saving ) {
		isSaving = saving;
		var btn     = document.getElementById( 'lrn-save-lesson' );
		var spinner = document.getElementById( 'lrn-save-spinner' );
		if ( btn ) btn.disabled = saving;
		if ( spinner ) spinner.className = saving ? 'spinner is-active' : 'spinner';
	}

	// ── Dependent dropdowns ──────────────────────────────────────────────────

	function apiFetch( path, opts ) {
		opts = opts || {};
		opts.headers = opts.headers || {};
		opts.headers[ 'X-WP-Nonce' ] = nonce;
		return fetch( restUrl + path, opts ).then( function ( r ) { return r.json(); } );
	}

	function clearSelect( selectEl, placeholder ) {
		while ( selectEl.firstChild ) {
			selectEl.removeChild( selectEl.firstChild );
		}
		var opt = document.createElement( 'option' );
		opt.value       = '';
		opt.textContent = placeholder;
		selectEl.appendChild( opt );
	}

	function fillSelect( selectEl, rows, selectedId ) {
		for ( var i = 0; i < rows.length; i++ ) {
			var row = rows[ i ];
			if ( lessonId && parseInt( row.id, 10 ) === lessonId ) continue; // exclude self
			var opt = document.createElement( 'option' );
			opt.value       = row.id;
			opt.textContent = row.title;
			if ( selectedId && parseInt( selectedId, 10 ) === parseInt( row.id, 10 ) ) {
				opt.selected = true;
			}
			selectEl.appendChild( opt );
		}
	}

	function loadSections( forCourseId, selectedSectionId ) {
		var sectionSel = document.getElementById( 'lrn-lesson-section' );
		var parentSel  = document.getElementById( 'lrn-lesson-parent' );
		if ( ! sectionSel ) return Promise.resolve();

		clearSelect( sectionSel, config.i18n.selectSection || __( '— Select a section —', 'learnomy' ) );
		sectionSel.disabled = true;

		if ( parentSel ) {
			clearSelect( parentSel, config.i18n.parentNone || __( '— None (top-level) —', 'learnomy' ) );
			parentSel.disabled = true;
		}

		if ( ! forCourseId ) {
			return Promise.resolve();
		}

		return apiFetch( 'sections?course_id=' + encodeURIComponent( forCourseId ) ).then( function ( res ) {
			var rows = ( res && res.data ) ? res.data : [];
			// Auto-pick the first section so the admin does not have to make
			// a separate click after choosing a course. If `selectedSectionId`
			// is already present (edit view), respect that; otherwise default
			// to the first available section. Empty courses keep the
			// placeholder and the save handler will fall back to the
			// course-scoped lesson endpoint.
			var pickId = selectedSectionId;
			if ( ! pickId && rows.length > 0 ) {
				pickId = rows[ 0 ].id;
			}
			fillSelect( sectionSel, rows, pickId );
			sectionSel.disabled = false;
		} ).catch( function () {
			sectionSel.disabled = false;
		} );
	}

	function loadParents( forSectionId, selectedParentId ) {
		var parentSel = document.getElementById( 'lrn-lesson-parent' );
		if ( ! parentSel ) return Promise.resolve();

		clearSelect( parentSel, config.i18n.parentNone || __( '— None (top-level) —', 'learnomy' ) );
		parentSel.disabled = true;

		if ( ! forSectionId ) return Promise.resolve();

		return apiFetch( 'lessons?section_id=' + encodeURIComponent( forSectionId ) + '&parent_only=1' ).then( function ( res ) {
			var rows = ( res && res.data ) ? res.data : [];
			fillSelect( parentSel, rows, selectedParentId );
			parentSel.disabled = false;
		} ).catch( function () {
			parentSel.disabled = false;
		} );
	}

	// ── Content-type dynamic visibility ──────────────────────────────────────

	function syncContentBlocks() {
		var typeEl = document.getElementById( 'lrn-lesson-content-type' );
		if ( ! typeEl ) return;
		var type = typeEl.value;
		var blocks = document.querySelectorAll( '.lrn-lesson-editor__content-block' );
		blocks.forEach( function ( el ) {
			var forList = ( el.getAttribute( 'data-content-for' ) || '' ).split( ',' );
			el.style.display = forList.indexOf( type ) !== -1 ? '' : 'none';
		} );

		// The inverse: a field that applies to every type EXCEPT the ones listed.
		// Duration uses it — a live session declares its length in "Runs for",
		// so showing both asks the author to pick between two identical questions.
		document.querySelectorAll( '[data-content-not-for]' ).forEach( function ( el ) {
			var notList = ( el.getAttribute( 'data-content-not-for' ) || '' ).split( ',' );
			el.style.display = notList.indexOf( type ) !== -1 ? 'none' : '';
		} );
		// Completion rules — the video rule only applies to video/embed/live.
		syncCompletionRules();
		syncLiveSummary();
	}

	// ── Live session preview ─────────────────────────────────────────────────
	//
	// Says, in plain English, exactly what the student will see. An author
	// setting up a webinar should not have to publish it and open a second
	// browser as a student to find out whether they got the schedule right —
	// which, before this, was the only way to find out anything about it.

	function syncLiveSummary() {
		var out = document.getElementById( 'lrn-lesson-live-summary' );
		if ( ! out ) return;

		var typeEl = document.getElementById( 'lrn-lesson-content-type' );
		if ( ! typeEl || 'live' !== typeEl.value ) { out.textContent = ''; return; }

		var startEl = document.getElementById( 'lrn-lesson-live-start' );
		var durEl   = document.getElementById( 'lrn-lesson-live-duration' );
		var joinEl  = document.getElementById( 'lrn-lesson-live-join-url' );

		if ( ! joinEl || ! joinEl.value.trim() ) {
			out.textContent = __( 'Add a join link and students will see a Join button here.', 'learnomy' );
			return;
		}

		if ( ! startEl || ! startEl.value ) {
			out.textContent = __( 'No start time set, so the room is always open: students see the Join button as soon as they reach this lesson.', 'learnomy' );
			return;
		}

		var start = new Date( startEl.value );
		if ( isNaN( start.getTime() ) ) { out.textContent = ''; return; }

		var mins = parseInt( durEl && durEl.value, 10 );
		if ( ! ( mins > 0 ) ) mins = 60;
		var end = new Date( start.getTime() + ( mins * 60000 ) );

		var timeFmt = { hour: 'numeric', minute: '2-digit' };
		var dayFmt  = { weekday: 'short', month: 'short', day: 'numeric' };

		out.textContent = sprintf(
			/* translators: 1: session date, 2: start time, 3: end time, 4: duration in minutes. */
			__( 'Students see a countdown until %1$s at %2$s, then a Join button for %4$d minutes (until %3$s). After that: "This session has ended."', 'learnomy' ),
			start.toLocaleDateString( undefined, dayFmt ),
			start.toLocaleTimeString( undefined, timeFmt ),
			end.toLocaleTimeString( undefined, timeFmt ),
			mins
		);
	}

	// ── Completion rules visibility ──────────────────────────────────────────
	//
	// Rules are revealed based on (a) the selected completion mode and (b)
	// whether a rule requires a video-capable content type. Combined mode
	// shows every rule so the instructor can mix thresholds.

	function syncCompletionRules() {
		var modeEl = document.getElementById( 'lrn-lesson-completion-mode' );
		if ( ! modeEl ) return;
		var mode   = modeEl.value;
		var typeEl = document.getElementById( 'lrn-lesson-content-type' );
		var type   = typeEl ? typeEl.value : '';
		var videoCapable = [ 'video', 'embed', 'live' ].indexOf( type ) !== -1;

		var rules = document.querySelectorAll( '.lrn-lesson-completion__rule' );
		rules.forEach( function ( el ) {
			var forList = ( el.getAttribute( 'data-rule-for' ) || '' ).split( ',' );
			var visible = forList.indexOf( mode ) !== -1;
			var requiresVideo = el.getAttribute( 'data-rule-requires-video' ) === '1';

			// A MediaShield protected video plays in its own player, which emits
			// no Plyr watch events — the "video watched" rule can never be met, so
			// hide it entirely for that type rather than showing it disabled.
			if ( requiresVideo && 'mediashield' === type ) {
				visible = false;
			}

			// "video watched" rule requires a video-capable content type.
			if ( visible && requiresVideo && ! videoCapable ) {
				el.classList.add( 'is-disabled' );
			} else {
				el.classList.remove( 'is-disabled' );
			}

			if ( visible ) {
				el.classList.add( 'is-active' );
			} else {
				el.classList.remove( 'is-active' );
			}
		} );
	}

	// ── Video provider auto-detect ───────────────────────────────────────────
	//
	// Runs on blur and on initial load. Reads the current video URL and shows
	// a small badge under the field identifying YouTube / Vimeo / Wistia /
	// Bunny / S3 / HLS / direct MP4. The detected key is cached on the DOM
	// node so the save flow can forward it as a provider hint later if we
	// need per-provider Plyr config.

	function detectProvider( url ) {
		if ( ! url ) return '';
		var u = url.toLowerCase();
		if ( /(^|\/\/)(www\.)?(youtube\.com|youtu\.be)\//.test( u ) ) return 'youtube';
		if ( /(^|\/\/)(player\.)?vimeo\.com\//.test( u ) ) return 'vimeo';
		if ( /wistia\.(com|net)/.test( u ) ) return 'wistia';
		if ( /b-cdn\.net|bunny(cdn)?\.com|iframe\.mediadelivery\.net/.test( u ) ) return 'bunny';
		if ( /\.m3u8(\?|$)/.test( u ) ) return 'hls';
		if ( /s3[.-]|amazonaws\.com/.test( u ) ) return 's3';
		if ( /\.mp4(\?|$)/.test( u ) ) return 'mp4';
		return '';
	}

	function syncProviderBadge() {
		var badge   = document.getElementById( 'lrn-lesson-provider-badge' );
		if ( ! badge ) return;
		var videoEl = document.getElementById( 'lrn-lesson-video-url' );
		var url     = videoEl ? videoEl.value.trim() : '';
		var key     = detectProvider( url );

		if ( ! key ) {
			badge.hidden = true;
			badge.textContent = '';
			badge.removeAttribute( 'data-provider' );
			return;
		}

		badge.hidden = false;
		badge.setAttribute( 'data-provider', key );
		var labels = {
			youtube: 'YouTube',
			vimeo:   'Vimeo',
			wistia:  'Wistia',
			bunny:   'Bunny',
			hls:     'HLS stream',
			s3:      'S3',
			mp4:     'Self-hosted MP4'
		};
		var prefix = ( config.i18n && config.i18n.detected ) ? config.i18n.detected : __( 'Detected', 'learnomy' );
		badge.textContent = prefix + ': ' + ( labels[ key ] || key );
	}

	// ── Completion UI wiring ─────────────────────────────────────────────────

	function wireCompletionUI() {
		var modeEl  = document.getElementById( 'lrn-lesson-completion-mode' );
		if ( modeEl ) modeEl.addEventListener( 'change', syncCompletionRules );

		var pctEl    = document.getElementById( 'lrn-lesson-min-video-pct' );
		var pctOut   = document.getElementById( 'lrn-lesson-min-video-pct-output' );
		if ( pctEl && pctOut ) {
			var render = function () { pctOut.textContent = pctEl.value; };
			pctEl.addEventListener( 'input', render );
			render();
		}

		syncCompletionRules();
	}

	// ── Attachments uploader ─────────────────────────────────────────────────
	//
	// Uses the WP media library so instructors can multi-select. Each pick
	// becomes a POST to /lessons/:id/attachments; the response is inserted
	// at the end of the list. Removals DELETE the single-attachment route
	// and strip the DOM node. Drag-and-drop reorder fires a single reorder
	// POST with the new id array.

	function attachmentsStatus( message, type ) {
		var el = document.getElementById( 'lrn-lesson-attachments-status' );
		if ( ! el ) return;
		el.textContent = message || '';
		el.className   = 'lrn-lesson-attachments__status' + ( type ? ' lrn-lesson-attachments__status--' + type : '' );
	}

	function renderAttachmentRow( row ) {
		var li = document.createElement( 'li' );
		li.className = 'lrn-lesson-attachments__item';
		li.draggable = true;
		li.setAttribute( 'data-attachment-id', row.id );

		var handle = document.createElement( 'span' );
		handle.className = 'lrn-lesson-attachments__handle';
		handle.setAttribute( 'aria-hidden', 'true' );
		handle.innerHTML = '<i data-lucide="grip-vertical"></i>';

		var name = document.createElement( 'span' );
		name.className = 'lrn-lesson-attachments__filename';
		name.textContent = row.filename || ( '#' + row.media_id );

		var size = document.createElement( 'span' );
		size.className = 'lrn-lesson-attachments__size';
		size.textContent = formatBytes( row.size_bytes || 0 );

		var remove = document.createElement( 'button' );
		remove.type = 'button';
		remove.className = 'lrn-lesson-attachments__remove button-link-delete';
		remove.setAttribute( 'aria-label', __( 'Remove attachment', 'learnomy' ) );
		remove.innerHTML = '<i data-lucide="x"></i>';

		li.appendChild( handle );
		li.appendChild( name );
		li.appendChild( size );
		li.appendChild( remove );

		wireAttachmentRow( li );
		return li;
	}

	function formatBytes( bytes ) {
		if ( ! bytes || bytes <= 0 ) return '';
		var units = [ 'B', 'KB', 'MB', 'GB' ];
		var u = 0;
		var n = bytes;
		while ( n >= 1024 && u < units.length - 1 ) {
			n = n / 1024;
			u++;
		}
		return n.toFixed( n < 10 && u > 0 ? 1 : 0 ) + ' ' + units[ u ];
	}

	function wireAttachmentRow( li ) {
		var removeBtn = li.querySelector( '.lrn-lesson-attachments__remove' );
		if ( removeBtn ) {
			removeBtn.addEventListener( 'click', function () {
				removeAttachment( li );
			} );
		}
		li.addEventListener( 'dragstart', onDragStart );
		li.addEventListener( 'dragend', onDragEnd );
		li.addEventListener( 'dragover', onDragOver );
		li.addEventListener( 'drop', onDrop );
	}

	var draggingLi = null;

	function onDragStart( e ) {
		draggingLi = this;
		this.classList.add( 'is-dragging' );
		if ( e.dataTransfer ) {
			e.dataTransfer.effectAllowed = 'move';
			try { e.dataTransfer.setData( 'text/plain', this.getAttribute( 'data-attachment-id' ) ); } catch ( _err ) {}
		}
	}

	function onDragEnd() {
		if ( draggingLi ) draggingLi.classList.remove( 'is-dragging' );
		draggingLi = null;
	}

	function onDragOver( e ) {
		e.preventDefault();
		if ( ! draggingLi || draggingLi === this ) return;
		var list = document.getElementById( 'lrn-lesson-attachments-list' );
		if ( ! list ) return;
		var rect = this.getBoundingClientRect();
		var midY = rect.top + ( rect.height / 2 );
		if ( e.clientY < midY ) {
			list.insertBefore( draggingLi, this );
		} else {
			list.insertBefore( draggingLi, this.nextSibling );
		}
	}

	function onDrop( e ) {
		e.preventDefault();
		persistAttachmentOrder();
	}

	function persistAttachmentOrder() {
		if ( ! lessonId ) return;
		var list  = document.getElementById( 'lrn-lesson-attachments-list' );
		if ( ! list ) return;
		var ids = [];
		list.querySelectorAll( '[data-attachment-id]' ).forEach( function ( el ) {
			ids.push( parseInt( el.getAttribute( 'data-attachment-id' ), 10 ) );
		} );
		if ( ! ids.length ) return;

		fetch( restUrl + 'lessons/' + lessonId + '/attachments/reorder', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': nonce
			},
			body: JSON.stringify( { order: ids } )
		} ).then( function () {
			attachmentsStatus( ( config.i18n && config.i18n.reordered ) || __( 'Order saved.', 'learnomy' ) );
		} ).catch( function () {
			attachmentsStatus( ( config.i18n && config.i18n.reorderFailed ) || __( 'Could not save order.', 'learnomy' ), 'error' );
		} );
	}

	function removeAttachment( li ) {
		var id = parseInt( li.getAttribute( 'data-attachment-id' ) || '0', 10 );
		if ( ! id || ! lessonId ) return;
		fetch( restUrl + 'lessons/' + lessonId + '/attachments/' + id, {
			method: 'DELETE',
			headers: { 'X-WP-Nonce': nonce }
		} ).then( function ( r ) {
			if ( ! r.ok ) throw new Error( 'delete failed' );
			li.parentNode && li.parentNode.removeChild( li );
			attachmentsStatus( ( config.i18n && config.i18n.removed ) || __( 'Removed.', 'learnomy' ) );
		} ).catch( function () {
			attachmentsStatus( ( config.i18n && config.i18n.removeFailed ) || __( 'Could not remove.', 'learnomy' ), 'error' );
		} );
	}

	function wireAttachmentsUploader() {
		var uploadBtn = document.getElementById( 'lrn-lesson-attachments-upload' );
		if ( ! uploadBtn ) return;
		if ( typeof window.wp === 'undefined' || ! window.wp.media ) {
			uploadBtn.disabled = true;
			return;
		}

		// Wire the existing DOM rows once so drag + remove already works.
		var list = document.getElementById( 'lrn-lesson-attachments-list' );
		if ( list ) {
			list.querySelectorAll( '.lrn-lesson-attachments__item' ).forEach( wireAttachmentRow );
		}

		uploadBtn.addEventListener( 'click', function () {
			var frame = window.wp.media( {
				title: ( config.i18n && config.i18n.selectResources ) || __( 'Select resources', 'learnomy' ),
				button: { text: ( config.i18n && config.i18n.selectResourcesCta ) || __( 'Attach to lesson', 'learnomy' ) },
				multiple: true
			} );

			frame.on( 'select', function () {
				var selection = frame.state().get( 'selection' );
				var promises = [];
				// Sticky so the "Attached." summary below cannot overwrite a
				// per-file refusal the instructor still needs to read.
				var refusal = '';
				selection.forEach( function ( item ) {
					var media = item.toJSON();
					promises.push(
						fetch( restUrl + 'lessons/' + lessonId + '/attachments', {
							method: 'POST',
							headers: {
								'Content-Type': 'application/json',
								'X-WP-Nonce': nonce
							},
							body: JSON.stringify( {
								media_id: media.id,
								filename: media.filename || media.title || ''
							} )
						} )
						.then( function ( r ) { return r.json(); } )
						.then( function ( body ) {
							// A refusal here is actionable ("this file is also a
							// course thumbnail", "uploads is not writable"), so
							// show the server's reason instead of swallowing it
							// into a generic "some files failed".
							if ( body && body.error && body.error.message ) {
								refusal = body.error.message;
								return;
							}
							var row = body && body.data ? body.data : null;
							if ( row && list ) {
								// lucide-live.js observes the document and paints
								// the new row's icons (Basecamp 10184590411).
								list.appendChild( renderAttachmentRow( row ) );
							}
						} )
					);
				} );

				Promise.all( promises ).then( function () {
					if ( refusal ) {
						attachmentsStatus( refusal, 'error' );
						return;
					}
					attachmentsStatus( ( config.i18n && config.i18n.uploaded ) || __( 'Attached.', 'learnomy' ) );
				} ).catch( function () {
					attachmentsStatus( ( config.i18n && config.i18n.uploadFailed ) || __( 'Some files failed to attach.', 'learnomy' ), 'error' );
				} );
			} );

			frame.open();
		} );
	}

	// ── Field gather + save ──────────────────────────────────────────────────

	function gatherFields() {
		var fields = {};

		var titleEl = document.getElementById( 'lrn-lesson-title' );
		if ( titleEl ) fields.title = titleEl.value.trim();

		var statusEl = document.getElementById( 'lrn-lesson-status' );
		if ( statusEl ) fields.status = statusEl.value;

		// Editable URL slug (edit screen only). The REST layer sanitises +
		// dedupes within the course and drops an empty value, so a blank field
		// safely regenerates from the title rather than wiping the slug.
		var slugEl = document.getElementById( 'lrn-lesson-slug' );
		if ( slugEl ) fields.slug = slugEl.value;

		// Tag taxonomies — collect every checked tag across all taxonomy cards.
		// Only send the array when at least one checklist is present so a lesson
		// without the picker (new lesson) doesn't post an empty clear.
		var tagBoxes = document.querySelectorAll( 'input[name="tag_ids[]"]' );
		if ( tagBoxes.length ) {
			fields.tag_ids = Array.prototype.slice.call( tagBoxes )
				.filter( function ( box ) { return box.checked; } )
				.map( function ( box ) { return parseInt( box.value, 10 ); } );
		}

		var contentTypeEl = document.getElementById( 'lrn-lesson-content-type' );
		if ( contentTypeEl ) fields.content_type = contentTypeEl.value;

		// video uses the "video URL" input; live uses its own "join link" input;
		// audio / pdf / embed / download use the generic "media URL" input;
		// mediashield uses the protected-video picker (its value is a
		// mediashield_video post ID, not a URL). They all write to the single
		// `video_url` column — content_type tells the renderer how to read it.
		// Live has a separate input purely so its label and help text can tell the
		// truth: a Zoom link is not a "Video URL", and calling it one is how the
		// feature ended up unusable.
		// DELIBERATE: video_url is NOT cleared when the type changes away from
		// video. The generic media input still holds the previous URL, so the
		// `else if (mediaEl)` branch below writes it back — an author who
		// switches video → text → video finds their URL still there instead of
		// having to dig it out again.
		//
		// This is the OPPOSITE of the live-schedule handling immediately below,
		// which blanks scheduled_start_at on every non-live save. The two differ
		// on purpose: a stale start time is actively harmful (it silently locks
		// or mis-labels a session the author can no longer see), whereas a stale
		// video_url is inert — nothing reads it unless content_type says to.
		// class-template-loader.php's needsPlayer gate makes that explicit: the
		// URL is blanked at render for any non-video/audio lesson, so it never
		// reaches the page. Decision recorded 2026-07-22, Basecamp 10119248021.
		var videoEl  = document.getElementById( 'lrn-lesson-video-url' );
		var liveEl   = document.getElementById( 'lrn-lesson-live-join-url' );
		var mediaEl  = document.getElementById( 'lrn-lesson-media-url' );
		var msEl     = document.getElementById( 'lrn-lesson-mediashield-id' );
		var mediaUrl = '';
		if ( 'mediashield' === fields.content_type && msEl ) {
			mediaUrl = msEl.value.trim();
		} else if ( 'live' === fields.content_type && liveEl ) {
			mediaUrl = liveEl.value.trim();
		} else if ( 'video' === fields.content_type && videoEl ) {
			mediaUrl = videoEl.value.trim();
		} else if ( mediaEl ) {
			mediaUrl = mediaEl.value.trim();
		}
		fields.video_url = mediaUrl;

		// Live schedule. Sent on every save (not only when live is selected) so
		// switching a lesson away from live and back does not resurrect a stale
		// start time the author can no longer see.
		var liveStartEl = document.getElementById( 'lrn-lesson-live-start' );
		var liveDurEl   = document.getElementById( 'lrn-lesson-live-duration' );
		if ( liveStartEl ) {
			fields.scheduled_start_at = ( 'live' === fields.content_type ) ? liveStartEl.value : '';
		}
		if ( liveDurEl ) {
			var liveMins = parseInt( liveDurEl.value, 10 );
			fields.live_duration_minutes = ( 'live' === fields.content_type && liveMins > 0 ) ? liveMins : 0;
		}

		var mins = parseInt( ( document.getElementById( 'lrn-lesson-duration-mins' ) || {} ).value || '0', 10 );
		var secs = parseInt( ( document.getElementById( 'lrn-lesson-duration-secs' ) || {} ).value || '0', 10 );
		if ( isNaN( mins ) ) mins = 0;
		if ( isNaN( secs ) ) secs = 0;
		fields.duration_seconds = ( mins * 60 ) + secs;

		var sortEl = document.getElementById( 'lrn-lesson-sort-order' );
		if ( sortEl ) fields.sort_order = parseInt( sortEl.value || '0', 10 ) || 0;

		var previewEl = document.getElementById( 'lrn-lesson-is-preview' );
		if ( previewEl ) fields.is_preview = previewEl.checked;

		var courseEl  = document.getElementById( 'lrn-lesson-course' );
		var sectionEl = document.getElementById( 'lrn-lesson-section' );
		var parentEl  = document.getElementById( 'lrn-lesson-parent' );

		if ( courseEl )  fields.course_id  = parseInt( courseEl.value || '0', 10 ) || 0;
		if ( sectionEl ) fields.section_id = parseInt( sectionEl.value || '0', 10 ) || 0;
		if ( parentEl )  fields.parent_id  = parseInt( parentEl.value || '0', 10 ) || 0;

		// Completion rule fields — always send the mode + the thresholds. The
		// REST layer ignores values that aren't relevant to the chosen mode
		// but writes whatever we POST, so the form acts as the source of
		// truth for the instructor's configured values.
		var modeEl = document.getElementById( 'lrn-lesson-completion-mode' );
		if ( modeEl ) fields.completion_mode = modeEl.value;

		var minMinsEl = document.getElementById( 'lrn-lesson-min-time-mins' );
		var minSecsEl = document.getElementById( 'lrn-lesson-min-time-secs' );
		var minMins   = parseInt( ( minMinsEl || {} ).value || '0', 10 ) || 0;
		var minSecs   = parseInt( ( minSecsEl || {} ).value || '0', 10 ) || 0;
		fields.min_time_seconds = ( minMins * 60 ) + minSecs;

		var pctEl = document.getElementById( 'lrn-lesson-min-video-pct' );
		if ( pctEl ) fields.min_video_watch_pct = parseInt( pctEl.value || '0', 10 ) || 0;

		var scrollEl = document.getElementById( 'lrn-lesson-require-scroll-end' );
		if ( scrollEl ) fields.require_scroll_end = scrollEl.checked;

		var quizPassEl = document.getElementById( 'lrn-lesson-require-quiz-pass' );
		if ( quizPassEl ) fields.require_quiz_pass = quizPassEl.checked;

		var lockNextEl = document.getElementById( 'lrn-lesson-lock-next' );
		if ( lockNextEl ) fields.lock_next_until_complete = lockNextEl.checked;

		// Completion enforcement override (tri-state plus inherit). Empty
		// string posts null so the REST layer clears the lesson override and
		// resolution falls back to course/site.
		var enforcementEl = document.getElementById( 'lrn-lesson-completion-enforcement' );
		if ( enforcementEl ) {
			fields.completion_enforcement = '' === enforcementEl.value ? null : enforcementEl.value;
		}

		// Comments tri-state. "inherit" writes null so the REST layer clears
		// the lesson override; "on"/"off" persist an explicit boolean.
		var commentsEl = document.getElementById( 'lrn-lesson-comments-enabled' );
		if ( commentsEl ) {
			var val = commentsEl.value;
			if ( val === 'on' ) {
				fields.comments_enabled = true;
			} else if ( val === 'off' ) {
				fields.comments_enabled = false;
			} else {
				fields.comments_enabled = 'inherit';
			}
		}

		// Drip schedule (plan-06). Always send the field — an empty string
		// tells the REST controller to write NULL, which clears any prior
		// lock. Non-empty values are in datetime-local format (site TZ);
		// the controller converts to UTC before persisting.
		var availableDateEl = document.getElementById( 'lrn-lesson-available-date' );
		if ( availableDateEl ) {
			fields.available_date = availableDateEl.value;
		}

		return fields;
	}

	function buildSaveUrl( fields ) {
		if ( lessonId > 0 ) return restUrl + 'lessons/' + lessonId;
		// Prefer the section-scoped create endpoint since it populates course_id + section_id.
		if ( fields.section_id > 0 ) return restUrl + 'sections/' + fields.section_id + '/lessons';
		if ( fields.course_id > 0 )  return restUrl + 'courses/' + fields.course_id + '/lessons';
		return '';
	}

	function saveLesson() {
		if ( isSaving || ! editor ) return;

		var fields = gatherFields();

		if ( ! fields.title ) {
			showToast( config.i18n && config.i18n.titleRequired ? config.i18n.titleRequired : __( 'Please enter a lesson title.', 'learnomy' ), 'error' );
			document.getElementById( 'lrn-lesson-title' ).focus();
			return;
		}
		if ( ! fields.course_id ) {
			showToast( config.i18n.courseRequired || __( 'Please pick a course.', 'learnomy' ), 'error' );
			return;
		}
		// `section_id` is no longer required up-front. When the course has at
		// least one section, loadSections() auto-selects the first option so
		// this field is populated by the time the admin reaches Save. When the
		// course has no sections yet, buildSaveUrl() falls back to the
		// `courses/{id}/lessons` endpoint so the admin can still create the
		// lesson without an extra detour to build a section first. See
		// Basecamp #9924944301.

		setSaving( true );

		editor.save().then( function ( savedData ) {
			fields.content       = JSON.stringify( savedData );
			fields.content_plain = extractPlainText( savedData );

			// Integration fields — anything an extension printed via
			// learnomy_lesson_editor_fields inside a [data-lrn-ext-fields]
			// panel. Fields are namespaced under extension_fields[...] by the
			// integration, so merging at the body root lands them at
			// body.extension_fields (same as the quiz editor's serializer).
			// Read by the learnomy_lesson_saved action via get_json_params().
			if ( window.lrnExtFields ) {
				Object.assign( fields, window.lrnExtFields.collect() );
			}

			var url = buildSaveUrl( fields );
			if ( ! url ) {
				setSaving( false );
				showToast( __( 'Missing course context.', 'learnomy' ), 'error' );
				return;
			}

			var method = lessonId > 0 ? 'PATCH' : 'POST';

			return fetch( url, {
				method: method,
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonce
				},
				body: JSON.stringify( fields )
			} );
		} ).then( function ( response ) {
			if ( ! response ) return null;
			return response.json().then( function ( body ) {
				return { ok: response.ok, status: response.status, body: body };
			} );
		} ).then( function ( result ) {
			if ( ! result ) {
				setSaving( false );
				return;
			}

			if ( ! result.ok ) {
				setSaving( false );
				var errorMsg = __( 'Save failed.', 'learnomy' );
				if ( result.body && result.body.error && result.body.error.message ) {
					errorMsg = result.body.error.message;
				} else if ( result.body && result.body.message ) {
					errorMsg = result.body.message;
				}
				showToast( errorMsg, 'error' );
				return;
			}

			var data = result.body.data || result.body;
			showToast( config.i18n && config.i18n.saved ? config.i18n.saved : __( 'Lesson saved.', 'learnomy' ), 'success' );

			// If this was a successful CREATE, claim the returned ID immediately
			// AND keep the saving lock engaged until the page navigates away.
			// Without this, a rapid second click between setSaving(false) and
			// window.location.replace would re-enter saveLesson() with lessonId
			// still 0 (closure var) and POST a second time — that's the QA
			// "duplicate lesson on save" report (Basecamp #9925260277).
			if ( ! lessonId && data.id ) {
				lessonId = parseInt( data.id, 10 );
				var newUrl = window.location.pathname + '?page=learnomy-lesson-editor&lesson_id=' + lessonId;
				window.location.replace( newUrl );
				return; // keep isSaving=true so any in-flight click is dropped.
			}

			// Existing-lesson PATCH path — release the lock so the next edit
			// can save.
			setSaving( false );
		} ).catch( function ( err ) {
			setSaving( false );
			showToast( err.message || 'An unexpected error occurred.', 'error' );
		} );
	}

	// ── Boot ────────────────────────────────────────────────────────────────

	/**
	 * Plug-and-play media field for audio / pdf / embed / download lessons.
	 *
	 * Two presentations:
	 * - File types (audio/pdf/download): primary "Choose file" button +
	 *   selected filename + Remove link. Opens wp.media(), populates the
	 *   hidden `#lrn-lesson-media-url` input on selection.
	 * - Embed type: switches to a URL-only input. Typing syncs to the
	 *   hidden `#lrn-lesson-media-url`.
	 *
	 * Label, button text, and help copy update per content type so the
	 * instructor sees "Choose audio file" / "Choose PDF" / "Choose file to
	 * download" / "Embed URL" — never a generic "Media URL" they have to
	 * decode. Without this, admins pasted placeholder URLs and got 404s.
	 */
	function wireMediaUrlPicker() {
		var pickBtn  = document.getElementById( 'lrn-lesson-media-url-pick' );
		var hidden   = document.getElementById( 'lrn-lesson-media-url' );
		var label    = document.getElementById( 'lrn-lesson-media-url-label' );
		var help     = document.getElementById( 'lrn-lesson-media-url-help' );
		var picker   = document.getElementById( 'lrn-lesson-media-picker' );
		var selected = document.getElementById( 'lrn-lesson-media-picker-selected' );
		var fname    = document.getElementById( 'lrn-lesson-media-picker-filename' );
		var clearBtn = document.getElementById( 'lrn-lesson-media-url-clear' );
		var embedBox = document.getElementById( 'lrn-lesson-media-embed' );
		var embedUrl = document.getElementById( 'lrn-lesson-media-embed-url' );
		var typeEl   = document.getElementById( 'lrn-lesson-content-type' );

		if ( ! pickBtn || ! hidden || ! typeEl ) return;
		if ( typeof window.wp === 'undefined' || ! window.wp.media ) {
			pickBtn.disabled = true;
			pickBtn.title = __( 'Media library is unavailable.', 'learnomy' );
			return;
		}

		// Per-content-type copy — terse, action-first.
		var COPY = {
			audio:    { label: __( 'Audio file', 'learnomy' ),  button: __( 'Choose audio file', 'learnomy' ),  help: __( 'Pick an MP3, WAV, or M4A file from your library.', 'learnomy' ),  filter: 'audio' },
			pdf:      { label: __( 'PDF file', 'learnomy' ),    button: __( 'Choose PDF', 'learnomy' ),         help: __( 'Pick a PDF from your library.', 'learnomy' ),                     filter: 'application/pdf' },
			download: { label: __( 'File', 'learnomy' ),        button: __( 'Choose file', 'learnomy' ),        help: __( 'Pick any file students should be able to download.', 'learnomy' ), filter: null },
			embed:    { label: __( 'Embed URL', 'learnomy' ),   button: '',                   help: __( 'Paste a URL from Figma, CodeSandbox, Loom, Vimeo, etc.', 'learnomy' ), filter: null },
		};

		function setLabel( text ) {
			if ( label ) label.textContent = text;
		}
		function setHelp( text ) {
			if ( help ) help.textContent = text;
		}
		function setButtonText( text ) {
			pickBtn.textContent = text;
		}
		function setSelectedFilename( name ) {
			if ( ! selected || ! fname ) return;
			if ( name ) {
				fname.textContent = name;
				selected.hidden = false;
			} else {
				fname.textContent = '';
				selected.hidden = true;
			}
		}

		function refreshFromHidden() {
			var url = hidden.value || '';
			if ( ! url ) {
				setSelectedFilename( '' );
				if ( embedUrl ) embedUrl.value = '';
				return;
			}
			// Show the filename portion to the admin — full URL is too noisy.
			try {
				var parts = url.split( '?' )[ 0 ].split( '/' );
				var name  = parts[ parts.length - 1 ] || url;
				setSelectedFilename( name );
			} catch ( e ) {
				setSelectedFilename( url );
			}
			if ( embedUrl ) embedUrl.value = url;
		}

		function applyContentType( type ) {
			var copy = COPY[ type ];
			if ( ! copy ) {
				// Other content types (text/video/live) don't use this field.
				return;
			}
			setLabel( copy.label );
			setHelp( copy.help );

			if ( type === 'embed' ) {
				if ( picker )   picker.hidden = true;
				if ( embedBox ) embedBox.hidden = false;
			} else {
				if ( picker )   picker.hidden = false;
				if ( embedBox ) embedBox.hidden = true;
				setButtonText( copy.button );
				pickBtn.dataset.mimeFilter = copy.filter || '';
			}
			refreshFromHidden();
		}

		pickBtn.addEventListener( 'click', function () {
			var libraryArgs = { type: pickBtn.dataset.mimeFilter || undefined };
			var frame = window.wp.media( {
				title: __( 'Select a file', 'learnomy' ),
				button: { text: __( 'Use this file', 'learnomy' ) },
				multiple: false,
				library: libraryArgs,
			} );
			frame.on( 'select', function () {
				var item = frame.state().get( 'selection' ).first();
				if ( ! item ) return;
				var media = item.toJSON();
				if ( media && media.url ) {
					hidden.value = media.url;
					setSelectedFilename( media.filename || media.title || media.url );
				}
			} );
			frame.open();
		} );

		if ( clearBtn ) {
			clearBtn.addEventListener( 'click', function () {
				hidden.value = '';
				setSelectedFilename( '' );
			} );
		}

		if ( embedUrl ) {
			embedUrl.addEventListener( 'input', function () {
				hidden.value = embedUrl.value.trim();
			} );
		}

		// Re-apply when content type changes.
		typeEl.addEventListener( 'change', function () {
			applyContentType( typeEl.value );
		} );

		// Initial render.
		applyContentType( typeEl.value );
	}

	function deleteLesson() {
		var btn = document.getElementById( 'lrn-delete-lesson' );
		if ( ! btn || ! lessonId ) return;

		// Never delete without an explicit confirmation. The confirm modal is a
		// hard dependency of this script, so its absence is a load error, not a
		// reason to skip the guard.
		if ( typeof window.lrnConfirm !== 'function' ) return;

		var courseId = parseInt( btn.getAttribute( 'data-course-id' ) || '0', 10 ) || 0;

		window.lrnConfirm( {
			title: config.i18n && config.i18n.deleteTitle ? config.i18n.deleteTitle : __( 'Delete this lesson?', 'learnomy' ),
			message: config.i18n && config.i18n.deleteMessage ? config.i18n.deleteMessage : __( 'This permanently removes the lesson and its progress, comments and attachments. This cannot be undone.', 'learnomy' ),
			confirmLabel: config.i18n && config.i18n.deleteConfirm ? config.i18n.deleteConfirm : __( 'Delete lesson', 'learnomy' ),
			tone: 'danger',
		} ).then( function ( ok ) {
			if ( ! ok ) return;
			btn.disabled = true;
			fetch( restUrl + 'lessons/' + lessonId, {
				method: 'DELETE',
				headers: { 'X-WP-Nonce': config.nonce },
			} ).then( function ( r ) {
				if ( ! r.ok ) throw new Error( 'delete failed' );
				// Back to the course builder so the admin lands on the curriculum
				// tree they were managing; fall back to the lessons list.
				window.location.href = courseId
					? 'admin.php?page=learnomy-course-builder&course_id=' + courseId
					: 'admin.php?page=learnomy-lessons';
			} ).catch( function () {
				btn.disabled = false;
				showToast( config.i18n && config.i18n.deleteFailed ? config.i18n.deleteFailed : __( 'Could not delete the lesson. Please try again.', 'learnomy' ), 'error' );
			} );
		} );
	}

	function init() {
		initEditor();
		syncContentBlocks();
		wireCompletionUI();
		wireAttachmentsUploader();
		wireMediaUrlPicker();

		var courseEl  = document.getElementById( 'lrn-lesson-course' );
		var sectionEl = document.getElementById( 'lrn-lesson-section' );
		var typeEl    = document.getElementById( 'lrn-lesson-content-type' );
		var videoEl   = document.getElementById( 'lrn-lesson-video-url' );

		if ( typeEl ) {
			typeEl.addEventListener( 'change', syncContentBlocks );
		}

		// Keep the live preview honest as the author types.
		[ 'lrn-lesson-live-start', 'lrn-lesson-live-duration', 'lrn-lesson-live-join-url' ].forEach( function ( id ) {
			var el = document.getElementById( id );
			if ( el ) {
				el.addEventListener( 'input', syncLiveSummary );
				el.addEventListener( 'change', syncLiveSummary );
			}
		} );
		if ( videoEl ) {
			videoEl.addEventListener( 'blur', syncProviderBadge );
			videoEl.addEventListener( 'input', syncProviderBadge );
			// Initial paint — render the badge when editing an existing lesson.
			syncProviderBadge();
		}

		if ( courseEl ) {
			courseEl.addEventListener( 'change', function () {
				courseId = parseInt( courseEl.value || '0', 10 ) || 0;
				loadSections( courseId, 0 );
			} );
		}

		if ( sectionEl ) {
			sectionEl.addEventListener( 'change', function () {
				sectionId = parseInt( sectionEl.value || '0', 10 ) || 0;
				loadParents( sectionId, 0 );
			} );
		}

		var saveBtn = document.getElementById( 'lrn-save-lesson' );
		if ( saveBtn ) saveBtn.addEventListener( 'click', saveLesson );

		var deleteBtn = document.getElementById( 'lrn-delete-lesson' );
		if ( deleteBtn ) deleteBtn.addEventListener( 'click', deleteLesson );

		document.addEventListener( 'keydown', function ( e ) {
			if ( ( e.ctrlKey || e.metaKey ) && e.key === 's' ) {
				e.preventDefault();
				saveLesson();
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
