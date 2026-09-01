/**
 * Course Builder — tree manager interactions.
 *
 * Handles add/delete/reorder sections, lessons, topics, and quizzes via the
 * REST API. Enhances server-rendered HTML with JS-powered CRUD.
 *
 * All dynamic values are sanitized through escHtml() which uses textContent-based
 * escaping to prevent XSS. innerHTML is only used with hardcoded markup templates
 * combined with escaped dynamic values.
 *
 * @package Learnomy
 */

( function () {
	'use strict';

	var __        = wp.i18n.__;
	var sprintf   = wp.i18n.sprintf;
	var cfg      = window.learnomyBuilder || {};
	var restUrl  = cfg.restUrl || '';
	var nonce    = cfg.nonce || '';
	var courseId = cfg.courseId || 0;
	var tree     = document.getElementById( 'lrn-builder-tree' );
	var toast    = document.getElementById( 'lrn-builder-toast' );

	if ( ! tree || ! courseId ) {
		return;
	}

	// =========================================================================
	// Utility
	// =========================================================================

	/**
	 * Escape a string for safe insertion into HTML using textContent.
	 * This prevents XSS by converting special characters to HTML entities.
	 */
	function escHtml( str ) {
		var div = document.createElement( 'div' );
		div.appendChild( document.createTextNode( str || '' ) );
		return div.innerHTML;
	}

	function adminUrl( path ) {
		var base = restUrl.replace( /wp-json\/learnomy\/v1\/$/, '' );
		return base + 'wp-admin/' + path;
	}

	// =========================================================================
	// REST helpers
	// =========================================================================

	function apiRequest( method, path, body ) {
		var options = {
			method:  method,
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce':   nonce,
			},
			credentials: 'same-origin',
		};

		if ( body ) {
			options.body = JSON.stringify( body );
		}

		return fetch( restUrl + path, options ).then( function ( response ) {
			return response.json().then( function ( json ) {
				if ( ! response.ok ) {
					var msg = ( json.error && json.error.message ) || json.message || 'Request failed';
					throw new Error( msg );
				}
				return json;
			} );
		} );
	}

	// =========================================================================
	// Toast
	// =========================================================================

	var toastTimer;

	function showToast( message, isError ) {
		if ( ! toast ) {
			return;
		}
		toast.textContent = message;
		toast.className   = 'lrn-builder-toast is-visible' + ( isError ? ' lrn-builder-toast--error' : '' );
		clearTimeout( toastTimer );
		toastTimer = setTimeout( function () {
			toast.classList.remove( 'is-visible' );
		}, 3000 );
	}

	// =========================================================================
	// DOM builder helpers — create elements safely using DOM API
	// =========================================================================

	function createEl( tag, attrs, children ) {
		var el = document.createElement( tag );
		if ( attrs ) {
			Object.keys( attrs ).forEach( function ( key ) {
				if ( key === 'className' ) {
					el.className = attrs[ key ];
				} else if ( key === 'textContent' ) {
					el.textContent = attrs[ key ];
				} else if ( key === 'disabled' && attrs[ key ] ) {
					el.disabled = true;
				} else if ( key.indexOf( 'on' ) === 0 ) {
					el.addEventListener( key.slice( 2 ).toLowerCase(), attrs[ key ] );
				} else {
					el.setAttribute( key, attrs[ key ] );
				}
			} );
		}
		if ( children ) {
			children.forEach( function ( child ) {
				if ( typeof child === 'string' ) {
					el.appendChild( document.createTextNode( child ) );
				} else if ( child ) {
					el.appendChild( child );
				}
			} );
		}
		return el;
	}

	// Map of legacy Dashicon names → Lucide equivalents. Keep the call sites
	// readable (they pass the old WP icon names that match the action) while
	// rendering Lucide SVGs everywhere per the icon-system rule in CLAUDE.md.
	// lucide.createIcons() runs site-wide and replaces these <i data-lucide>
	// stubs with inline SVGs after we mount.
	var ICON_MAP = {
		'arrow-down-alt2': 'chevron-down',
		plus:              'plus',
		'admin-links':     'link-2',
		edit:              'pencil',
		menu:              'grip-vertical',
		'media-text':      'file-text',
		forms:             'list-checks',
	};

	function dashicon( name ) {
		var lucideName = ICON_MAP[ name ] || name;
		var el = createEl( 'i', { className: 'lrn-icon' } );
		el.setAttribute( 'data-lucide', lucideName );
		el.setAttribute( 'aria-hidden', 'true' );
		return el;
	}

	// Re-mount Lucide icons after we inject DOM. Safe to call multiple times.

	function iconBtn( iconName, title, cssClass, disabled ) {
		var classes = 'lrn-builder__icon-btn' + ( cssClass ? ' ' + cssClass : '' );
		var btn = createEl( 'button', { type: 'button', className: classes, title: title } );
		if ( disabled ) {
			btn.disabled = true;
		}
		btn.appendChild( dashicon( iconName ) );
		return btn;
	}

	// =========================================================================
	// Section collapse / expand
	// =========================================================================

	tree.addEventListener( 'click', function ( e ) {
		var header = e.target.closest( '.lrn-builder__section-header' );
		if ( ! header ) {
			return;
		}
		if ( e.target.closest( '.lrn-builder__section-actions' ) ) {
			return;
		}

		var section = header.closest( '.lrn-builder__section' );
		if ( ! section ) {
			return;
		}

		section.classList.toggle( 'is-collapsed' );
		var expanded = ! section.classList.contains( 'is-collapsed' );
		header.setAttribute( 'aria-expanded', expanded ? 'true' : 'false' );
	} );

	tree.addEventListener( 'keydown', function ( e ) {
		if ( e.key !== 'Enter' && e.key !== ' ' ) {
			return;
		}
		var header = e.target.closest( '.lrn-builder__section-header' );
		if ( ! header || e.target.closest( '.lrn-builder__section-actions' ) ) {
			return;
		}
		e.preventDefault();
		header.click();
	} );

	// =========================================================================
	// Add Section
	// =========================================================================

	var addSectionBtn = document.getElementById( 'lrn-add-section' );
	if ( addSectionBtn ) {
		addSectionBtn.addEventListener( 'click', async function () {
			if ( 'function' !== typeof window.lrnPrompt ) {
				return;
			}
			var title = await window.lrnPrompt( {
				title:        __( 'Add new section', 'learnomy' ),
				message:      __( 'Give this module a short, clear name your students will see in the curriculum.', 'learnomy' ),
				label:        __( 'Section title', 'learnomy' ),
				placeholder:  __( 'e.g. Getting Started', 'learnomy' ),
				confirmLabel: __( 'Add section', 'learnomy' ),
				required:     true,
				maxLength:    120,
			} );
			if ( null === title || ! title ) {
				return;
			}

			var sectionCount = tree.querySelectorAll( '.lrn-builder__section' ).length;

			addSectionBtn.disabled = true;
			apiRequest( 'POST', 'courses/' + courseId + '/sections', {
				title:      title,
				sort_order: sectionCount,
			} ).then( function ( json ) {
				var section = json.data || json;
				hideEmpty();
				appendSectionNode( section );
				showToast( __( 'Section added.', 'learnomy' ) );
			} ).catch( function ( err ) {
				showToast( err.message, true );
			} ).finally( function () {
				addSectionBtn.disabled = false;
			} );
		} );
	}

	function hideEmpty() {
		var empty = document.getElementById( 'lrn-builder-empty' );
		if ( empty ) {
			empty.remove();
		}
	}

	// =========================================================================
	// Build section DOM from REST response (safe DOM API)
	// =========================================================================

	function appendSectionNode( section ) {
		var sectionDiv = createEl( 'div', {
			className: 'lrn-builder__section',
			'data-section-id': section.id,
		} );

		// Header
		var actionsSpan = createEl( 'span', { className: 'lrn-builder__section-actions' } );
		// NB: do NOT stopPropagation() here. The Rename/Move/Delete handlers are
		// delegated on `tree` (see below) and rely on the click bubbling up from
		// these buttons; cancelling propagation left them dead on a just-created
		// section until a page reload. The collapse toggle already ignores clicks
		// inside .lrn-builder__section-actions via closest(), so bubbling is safe.

		actionsSpan.appendChild( iconBtn( 'arrow-up-alt2', __( 'Move Up', 'learnomy' ), 'js-move-section-up' ) );
		actionsSpan.appendChild( iconBtn( 'arrow-down-alt2', __( 'Move Down', 'learnomy' ), 'js-move-section-down' ) );
		actionsSpan.appendChild( iconBtn( 'edit', __( 'Rename', 'learnomy' ), 'js-edit-section' ) );
		actionsSpan.appendChild( iconBtn( 'trash', __( 'Delete', 'learnomy' ), 'js-delete-section lrn-builder__icon-btn--danger' ) );

		var header = createEl( 'div', {
			className: 'lrn-builder__section-header',
			role: 'button',
			tabindex: '0',
			'aria-expanded': 'true',
		}, [
			createEl( 'span', { className: 'lrn-builder__toggle' }, [ dashicon( 'arrow-down-alt2' ) ] ),
			createEl( 'span', { className: 'lrn-builder__section-title', textContent: section.title } ),
			actionsSpan,
		] );

		// Body
		var itemsList = createEl( 'ul', { className: 'lrn-builder__items' } );

		var footer = createEl( 'div', { className: 'lrn-builder__section-footer' } );

		var addLessonBtn = createEl( 'button', {
			type: 'button',
			className: 'lrn-builder__add-btn js-add-lesson',
			'data-section-id': section.id,
		}, [ dashicon( 'plus' ), ' ' + __( 'Add Lesson', 'learnomy' ) ] );

		var addQuizBtn = createEl( 'button', {
			type: 'button',
			className: 'lrn-builder__add-btn js-add-quiz',
			'data-section-id': section.id,
		}, [ dashicon( 'plus' ), ' ' + __( 'Add Quiz', 'learnomy' ) ] );

		var attachBtn = createEl( 'button', {
			type: 'button',
			className: 'lrn-builder__add-btn js-attach-existing',
			'data-section-id': section.id,
		}, [ dashicon( 'admin-links' ), ' ' + __( 'Attach Existing', 'learnomy' ) ] );

		footer.appendChild( addLessonBtn );
		footer.appendChild( addQuizBtn );
		footer.appendChild( attachBtn );

		var body = createEl( 'div', { className: 'lrn-builder__section-body' }, [ itemsList, footer ] );

		sectionDiv.appendChild( header );
		sectionDiv.appendChild( body );
		tree.appendChild( sectionDiv );
		updateSectionMoveButtons();
	}

	// =========================================================================
	// Delete Section
	// =========================================================================

	tree.addEventListener( 'click', async function ( e ) {
		var btn = e.target.closest( '.js-delete-section' );
		if ( ! btn ) {
			return;
		}

		var section   = btn.closest( '.lrn-builder__section' );
		var sectionId = section.getAttribute( 'data-section-id' );

		var okSection = await window.lrnConfirm( {
			title:        __( 'Delete section', 'learnomy' ),
			message:      __( 'Delete this section and all its contents?', 'learnomy' ),
			confirmLabel: __( 'Delete', 'learnomy' ),
			destructive:  true,
		} );
		if ( ! okSection ) {
			return;
		}

		btn.disabled = true;
		apiRequest( 'DELETE', 'sections/' + sectionId ).then( function () {
			section.remove();
			updateSectionMoveButtons();
			showToast( __( 'Section deleted.', 'learnomy' ) );
		} ).catch( function ( err ) {
			showToast( err.message, true );
			btn.disabled = false;
		} );
	} );

	// =========================================================================
	// Rename Section (inline)
	// =========================================================================

	tree.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.js-edit-section' );
		if ( ! btn ) {
			return;
		}

		var section   = btn.closest( '.lrn-builder__section' );
		var sectionId = section.getAttribute( 'data-section-id' );
		var titleEl   = section.querySelector( '.lrn-builder__section-title' );
		var oldTitle  = titleEl.textContent.trim();

		var input = document.createElement( 'input' );
		input.type      = 'text';
		input.className = 'lrn-builder__rename-input';
		input.value     = oldTitle;

		titleEl.textContent = '';
		titleEl.appendChild( input );
		input.focus();
		input.select();

		function save() {
			var newTitle = input.value.trim();
			if ( ! newTitle || newTitle === oldTitle ) {
				titleEl.textContent = oldTitle;
				return;
			}

			titleEl.textContent = newTitle;

			apiRequest( 'PATCH', 'sections/' + sectionId, { title: newTitle } ).then( function () {
				showToast( __( 'Section renamed.', 'learnomy' ) );
			} ).catch( function ( err ) {
				titleEl.textContent = oldTitle;
				showToast( err.message, true );
			} );
		}

		input.addEventListener( 'blur', save );
		input.addEventListener( 'keydown', function ( ev ) {
			if ( ev.key === 'Enter' ) {
				ev.preventDefault();
				input.blur();
			}
			if ( ev.key === 'Escape' ) {
				input.value = oldTitle;
				input.blur();
			}
		} );
	} );

	// =========================================================================
	// Reorder Sections (Move Up / Down)
	// =========================================================================

	tree.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.js-move-section-up, .js-move-section-down' );
		if ( ! btn || btn.disabled ) {
			return;
		}

		var isUp    = btn.classList.contains( 'js-move-section-up' );
		var section = btn.closest( '.lrn-builder__section' );
		var sibling = isUp ? section.previousElementSibling : section.nextElementSibling;

		if ( ! sibling || ! sibling.classList.contains( 'lrn-builder__section' ) ) {
			return;
		}

		if ( isUp ) {
			section.parentNode.insertBefore( section, sibling );
		} else {
			section.parentNode.insertBefore( sibling, section );
		}

		updateSectionMoveButtons();
		persistSectionOrder();
	} );

	function persistSectionOrder() {
		var sectionEls = tree.querySelectorAll( '.lrn-builder__section' );
		var order      = [];

		sectionEls.forEach( function ( el ) {
			order.push( parseInt( el.getAttribute( 'data-section-id' ), 10 ) );
		} );

		apiRequest( 'POST', 'courses/' + courseId + '/sections/reorder', { order: order } ).then( function () {
			showToast( __( 'Section order saved.', 'learnomy' ) );
		} ).catch( function ( err ) {
			showToast( err.message, true );
		} );
	}

	function updateSectionMoveButtons() {
		var sectionEls = tree.querySelectorAll( '.lrn-builder__section' );

		sectionEls.forEach( function ( el, index ) {
			var upBtn   = el.querySelector( '.js-move-section-up' );
			var downBtn = el.querySelector( '.js-move-section-down' );

			if ( upBtn ) {
				upBtn.disabled = index === 0;
			}
			if ( downBtn ) {
				downBtn.disabled = index === sectionEls.length - 1;
			}
		} );
	}

	// =========================================================================
	// Add Lesson (to section)
	// =========================================================================

	tree.addEventListener( 'click', async function ( e ) {
		var btn = e.target.closest( '.js-add-lesson' );
		if ( ! btn ) {
			return;
		}
		if ( 'function' !== typeof window.lrnPrompt ) {
			return;
		}

		var sectionId = btn.getAttribute( 'data-section-id' );
		var title     = await window.lrnPrompt( {
			title:        __( 'Add new lesson', 'learnomy' ),
			message:      __( 'What will students learn in this lesson? Keep the title short and specific.', 'learnomy' ),
			label:        __( 'Lesson title', 'learnomy' ),
			placeholder:  __( 'e.g. Setting up your first project', 'learnomy' ),
			confirmLabel: __( 'Add lesson', 'learnomy' ),
			required:     true,
			maxLength:    160,
		} );
		if ( null === title || ! title ) {
			return;
		}

		btn.disabled = true;
		apiRequest( 'POST', 'sections/' + sectionId + '/lessons', {
			title: title,
		} ).then( function ( json ) {
			var lesson = json.data || json;
			appendLessonNode( sectionId, lesson );
			showToast( __( 'Lesson added.', 'learnomy' ) );
		} ).catch( function ( err ) {
			showToast( err.message, true );
		} ).finally( function () {
			btn.disabled = false;
		} );
	} );

	function appendLessonNode( sectionId, lesson ) {
		var section = tree.querySelector( '[data-section-id="' + sectionId + '"]' );
		if ( ! section ) {
			return;
		}

		var list    = section.querySelector( '.lrn-builder__items' );
		var editUrl = adminUrl( 'admin.php?page=learnomy-lesson-editor&lesson_id=' + lesson.id );

		var addTopicBtn = iconBtn( 'plus', __( 'Add Topic', 'learnomy' ), 'js-add-topic' );
		addTopicBtn.setAttribute( 'data-parent-id', lesson.id );

		var titleLink = createEl( 'a', {
			href: editUrl,
			className: 'lrn-builder__item-title',
			textContent: lesson.title,
		} );

		var statusSpan = createEl( 'span', {
			className: 'lrn-builder__item-status lrn-builder__item-status--draft',
			textContent: __( 'Draft', 'learnomy' ),
		} );

		var actions = createEl( 'span', { className: 'lrn-builder__item-actions' }, [
			iconBtn( 'arrow-up-alt2', __( 'Move Up', 'learnomy' ), 'js-move-item-up' ),
			iconBtn( 'arrow-down-alt2', __( 'Move Down', 'learnomy' ), 'js-move-item-down' ),
			createEl( 'a', { href: editUrl, className: 'lrn-builder__icon-btn', title: __( 'Edit', 'learnomy' ) }, [ dashicon( 'edit' ) ] ),
			addTopicBtn,
			iconBtn( 'trash', __( 'Remove', 'learnomy' ), 'js-delete-item lrn-builder__icon-btn--danger' ),
		] );

		var li = createEl( 'li', {
			className: 'lrn-builder__item lrn-builder__item--lesson lrn-lesson-item',
			draggable: 'true',
			'data-item-type': 'lesson',
			'data-item-id': lesson.id,
			'data-section-id': sectionId,
		}, [
			createEl( 'span', { className: 'lrn-builder__item-drag-handle', title: __( 'Drag to reorder', 'learnomy' ) }, [ dashicon( 'menu' ) ] ),
			createEl( 'span', { className: 'lrn-builder__item-icon lrn-builder__item-icon--lesson' }, [ dashicon( 'media-text' ) ] ),
			titleLink,
			statusSpan,
			actions,
		] );

		list.appendChild( li );
	}

	// =========================================================================
	// Add Topic (sub-lesson, nested under a lesson)
	// =========================================================================

	tree.addEventListener( 'click', async function ( e ) {
		var btn = e.target.closest( '.js-add-topic' );
		if ( ! btn ) {
			return;
		}
		if ( 'function' !== typeof window.lrnPrompt ) {
			return;
		}

		var parentLessonId = btn.getAttribute( 'data-parent-id' );
		var title          = await window.lrnPrompt( {
			title:        __( 'Add topic', 'learnomy' ),
			message:      __( 'A topic is a sub-lesson nested under this lesson.', 'learnomy' ),
			label:        __( 'Topic title', 'learnomy' ),
			placeholder:  __( 'e.g. Worked example', 'learnomy' ),
			confirmLabel: __( 'Add topic', 'learnomy' ),
			required:     true,
			maxLength:    160,
		} );
		if ( null === title || ! title ) {
			return;
		}

		btn.disabled = true;
		apiRequest( 'POST', 'lessons/' + parentLessonId + '/topics', {
			title: title,
		} ).then( function ( json ) {
			var topic = json.data || json;
			appendTopicNode( parentLessonId, topic );
			showToast( __( 'Topic added.', 'learnomy' ) );
		} ).catch( function ( err ) {
			showToast( err.message, true );
		} ).finally( function () {
			btn.disabled = false;
		} );
	} );

	function appendTopicNode( parentLessonId, topic ) {
		var lessonLi = tree.querySelector( '[data-item-type="lesson"][data-item-id="' + parentLessonId + '"]' );
		if ( ! lessonLi ) {
			return;
		}

		var editUrl = adminUrl( 'admin.php?page=learnomy-lesson-editor&lesson_id=' + topic.id );

		var actions = createEl( 'span', { className: 'lrn-builder__item-actions' }, [
			iconBtn( 'arrow-up-alt2', __( 'Move Up', 'learnomy' ), 'js-move-item-up' ),
			iconBtn( 'arrow-down-alt2', __( 'Move Down', 'learnomy' ), 'js-move-item-down' ),
			createEl( 'a', { href: editUrl, className: 'lrn-builder__icon-btn', title: __( 'Edit', 'learnomy' ) }, [ dashicon( 'edit' ) ] ),
			iconBtn( 'trash', __( 'Remove', 'learnomy' ), 'js-delete-item lrn-builder__icon-btn--danger' ),
		] );

		var li = createEl( 'li', {
			className: 'lrn-builder__item lrn-builder__item--topic',
			'data-item-type': 'topic',
			'data-item-id': topic.id,
			'data-parent-id': parentLessonId,
		}, [
			createEl( 'span', { className: 'lrn-builder__item-icon lrn-builder__item-icon--topic' }, [ dashicon( 'media-text' ) ] ),
			createEl( 'a', { href: editUrl, className: 'lrn-builder__item-title', textContent: topic.title } ),
			actions,
		] );

		var insertAfter = lessonLi;
		var sib         = lessonLi.nextElementSibling;
		while ( sib && 'topic' === sib.getAttribute( 'data-item-type' ) && String( parentLessonId ) === sib.getAttribute( 'data-parent-id' ) ) {
			insertAfter = sib;
			sib         = sib.nextElementSibling;
		}
		insertAfter.parentNode.insertBefore( li, insertAfter.nextSibling );
	}

	// =========================================================================
	// Add Quiz (to section)
	// =========================================================================

	tree.addEventListener( 'click', async function ( e ) {
		var btn = e.target.closest( '.js-add-quiz' );
		if ( ! btn ) {
			return;
		}
		if ( 'function' !== typeof window.lrnPrompt ) {
			return;
		}

		var sectionId = btn.getAttribute( 'data-section-id' );
		var title     = await window.lrnPrompt( {
			title:        __( 'Add new quiz', 'learnomy' ),
			message:      __( 'Name this quiz so students know what it tests. You can add questions next.', 'learnomy' ),
			label:        __( 'Quiz title', 'learnomy' ),
			placeholder:  __( 'e.g. Chapter 1 Review', 'learnomy' ),
			confirmLabel: __( 'Add quiz', 'learnomy' ),
			required:     true,
			maxLength:    160,
		} );
		if ( null === title || ! title ) {
			return;
		}

		btn.disabled = true;
		apiRequest( 'POST', 'quizzes', {
			title:      title,
			course_id:  courseId,
			section_id: parseInt( sectionId, 10 ),
		} ).then( function ( json ) {
			var quiz = json.data || json;
			appendQuizNode( sectionId, quiz );
			showToast( __( 'Quiz added.', 'learnomy' ) );
		} ).catch( function ( err ) {
			showToast( err.message, true );
		} ).finally( function () {
			btn.disabled = false;
		} );
	} );

	function appendQuizNode( sectionId, quiz ) {
		var section = tree.querySelector( '[data-section-id="' + sectionId + '"]' );
		if ( ! section ) {
			return;
		}

		var list    = section.querySelector( '.lrn-builder__items' );
		var editUrl = adminUrl( 'admin.php?page=learnomy-quizzes&quiz_id=' + quiz.id );

		var titleLink = createEl( 'a', {
			href: editUrl,
			className: 'lrn-builder__item-title',
			textContent: quiz.title,
		} );

		var statusSpan = createEl( 'span', {
			className: 'lrn-builder__item-status lrn-builder__item-status--draft',
			textContent: __( 'Draft', 'learnomy' ),
		} );

		var actions = createEl( 'span', { className: 'lrn-builder__item-actions' }, [
			iconBtn( 'arrow-up-alt2', __( 'Move Up', 'learnomy' ), 'js-move-item-up' ),
			iconBtn( 'arrow-down-alt2', __( 'Move Down', 'learnomy' ), 'js-move-item-down' ),
			createEl( 'a', { href: editUrl, className: 'lrn-builder__icon-btn', title: __( 'Edit', 'learnomy' ) }, [ dashicon( 'edit' ) ] ),
			iconBtn( 'trash', __( 'Remove', 'learnomy' ), 'js-delete-item lrn-builder__icon-btn--danger' ),
		] );

		var li = createEl( 'li', {
			className: 'lrn-builder__item lrn-builder__item--quiz',
			'data-item-type': 'quiz',
			'data-item-id': quiz.id,
			'data-section-id': sectionId,
		}, [
			createEl( 'span', { className: 'lrn-builder__item-icon lrn-builder__item-icon--quiz' }, [ dashicon( 'forms' ) ] ),
			titleLink,
			statusSpan,
			actions,
		] );

		list.appendChild( li );
	}

	// =========================================================================
	// Delete / detach item (lesson, topic, quiz)
	// =========================================================================

	tree.addEventListener( 'click', async function ( e ) {
		var btn = e.target.closest( '.js-delete-item' );
		if ( ! btn ) {
			return;
		}

		var item     = btn.closest( '.lrn-builder__item' );
		var itemType = item.getAttribute( 'data-item-type' );
		var itemId   = item.getAttribute( 'data-item-id' );
		var endpoint;

		var okItem = await window.lrnConfirm( {
			title:        __( 'Remove item', 'learnomy' ),
			message:      __( 'Remove this item from the course?', 'learnomy' ),
			confirmLabel: __( 'Remove', 'learnomy' ),
			destructive:  true,
		} );
		if ( ! okItem ) {
			return;
		}

		if ( itemType === 'lesson' || itemType === 'topic' ) {
			endpoint = 'lessons/' + itemId;
		} else if ( itemType === 'quiz' ) {
			endpoint = 'quizzes/' + itemId;
		} else {
			return;
		}

		btn.disabled = true;
		apiRequest( 'DELETE', endpoint ).then( function () {
			if ( itemType === 'lesson' ) {
				var topics = tree.querySelectorAll( '[data-parent-id="' + itemId + '"]' );
				topics.forEach( function ( t ) {
					t.remove();
				} );
			}
			item.remove();
			showToast( __( 'Item removed.', 'learnomy' ) );
		} ).catch( function ( err ) {
			showToast( err.message, true );
			btn.disabled = false;
		} );
	} );

	// =========================================================================
	// Reorder items within a section (Move Up / Down)
	// =========================================================================

	tree.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.js-move-item-up, .js-move-item-down' );
		if ( ! btn || btn.disabled ) {
			return;
		}

		var isUp = btn.classList.contains( 'js-move-item-up' );
		var item = btn.closest( '.lrn-builder__item' );

		var siblings = getSiblings( item );
		var index    = siblings.indexOf( item );

		if ( isUp && index <= 0 ) {
			return;
		}
		if ( ! isUp && index >= siblings.length - 1 ) {
			return;
		}

		var swapWith = isUp ? siblings[ index - 1 ] : siblings[ index + 1 ];

		if ( isUp ) {
			item.parentNode.insertBefore( item, swapWith );
		} else {
			item.parentNode.insertBefore( swapWith, item );
		}

		var sectionEl = item.closest( '.lrn-builder__section' );
		if ( sectionEl ) {
			persistItemOrder( sectionEl );
		}
	} );

	function getSiblings( item ) {
		var type = item.getAttribute( 'data-item-type' );

		if ( type === 'topic' ) {
			var parentId = item.getAttribute( 'data-parent-id' );
			return Array.from( item.parentNode.querySelectorAll( '[data-item-type="topic"][data-parent-id="' + parentId + '"]' ) );
		}

		return Array.from( item.parentNode.querySelectorAll( '.lrn-builder__item--lesson, .lrn-builder__item--quiz' ) );
	}

	function persistItemOrder( sectionEl ) {
		var sectionId = sectionEl.getAttribute( 'data-section-id' );
		var lessons   = sectionEl.querySelectorAll( '[data-item-type="lesson"]' );
		var order     = [];

		lessons.forEach( function ( el ) {
			order.push( parseInt( el.getAttribute( 'data-item-id' ), 10 ) );
		} );

		if ( order.length > 0 ) {
			apiRequest( 'POST', 'sections/' + sectionId + '/lessons/reorder', { order: order } ).then( function () {
				showToast( __( 'Order saved.', 'learnomy' ) );
			} ).catch( function ( err ) {
				showToast( err.message, true );
			} );
		}
	}

	// =========================================================================
	// Attach Existing (search modal)
	// =========================================================================

	var modal       = document.getElementById( 'lrn-attach-modal' );
	var modalClose  = document.getElementById( 'lrn-attach-modal-close' );
	var searchInput = document.getElementById( 'lrn-attach-search' );
	var resultsList = document.getElementById( 'lrn-attach-results' );
	var attachSectionId = 0;
	var searchTimer;

	tree.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.js-attach-existing' );
		if ( ! btn ) {
			return;
		}
		attachSectionId = btn.getAttribute( 'data-section-id' );
		openModal();
	} );

	function openModal() {
		if ( ! modal ) {
			return;
		}
		modal.classList.add( 'is-open' );
		searchInput.value = '';
		resultsList.textContent = '';
		searchInput.focus();
	}

	function closeModal() {
		if ( ! modal ) {
			return;
		}
		modal.classList.remove( 'is-open' );
		attachSectionId = 0;
	}

	if ( modalClose ) {
		modalClose.addEventListener( 'click', closeModal );
	}

	if ( modal ) {
		modal.addEventListener( 'click', function ( e ) {
			if ( e.target === modal ) {
				closeModal();
			}
		} );
	}

	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Escape' && modal && modal.classList.contains( 'is-open' ) ) {
			closeModal();
		}
	} );

	if ( searchInput ) {
		searchInput.addEventListener( 'input', function () {
			clearTimeout( searchTimer );
			searchTimer = setTimeout( function () {
				runSearch( searchInput.value.trim() );
			}, 300 );
		} );
	}

	function runSearch( query ) {
		if ( ! query ) {
			resultsList.textContent = '';
			return;
		}

		resultsList.textContent = '';
		var loadingLi = createEl( 'li', {
			className: 'lrn-builder-modal__loading',
			textContent: __( 'Searching…', 'learnomy' ),
		} );
		resultsList.appendChild( loadingLi );

		// Attach Existing search — pull lessons that AREN'T already inside
		// the current course (so re-attaching is a true "move from another
		// course / orphan pool" operation), and orphan quizzes (course_id=0).
		// The previous URL `courses/{id}/lessons` returned only lessons IN
		// THIS course, so the modal looked empty for the user (bug card #7).
		Promise.all( [
			apiRequest( 'GET', 'lessons?unattached_to_course=' + encodeURIComponent( courseId ) + '&search=' + encodeURIComponent( query ) ),
			apiRequest( 'GET', 'quizzes?course_id=0&search=' + encodeURIComponent( query ) ),
		] ).then( function ( responses ) {
			var lessons = ( responses[ 0 ].data || responses[ 0 ] ) || [];
			var quizzes = ( responses[ 1 ].data || responses[ 1 ] ) || [];

			resultsList.textContent = '';

			if ( Array.isArray( lessons ) ) {
				lessons.forEach( function ( lesson ) {
					appendSearchResult( lesson, 'lesson' );
				} );
			}

			if ( Array.isArray( quizzes ) ) {
				quizzes.forEach( function ( quiz ) {
					appendSearchResult( quiz, 'quiz' );
				} );
			}

			if ( resultsList.children.length === 0 ) {
				resultsList.appendChild( createEl( 'li', {
					className: 'lrn-builder-modal__empty',
					textContent: __( 'No results found.', 'learnomy' ),
				} ) );
			}
		} ).catch( function ( err ) {
			resultsList.textContent = '';
			resultsList.appendChild( createEl( 'li', {
				className: 'lrn-builder-modal__empty',
				textContent: err.message,
			} ) );
		} );
	}

	// A lesson from ANOTHER course needs a decision, not a silent default:
	// attaching is a MOVE (its course_id follows the section, so it leaves
	// the original course and its old URL breaks — Basecamp 10153330214).
	function belongsElsewhere( item, type ) {
		return type === 'lesson' && parseInt( item.course_id, 10 ) > 0 && parseInt( item.course_id, 10 ) !== parseInt( courseId, 10 );
	}

	function appendSearchResult( item, type ) {
		var labelChildren = [
			createEl( 'span', { className: 'lrn-builder-modal__result-title', textContent: item.title } ),
			createEl( 'span', { className: 'lrn-builder-modal__result-type', textContent: type } ),
		];

		// Flag it BEFORE the click, so choosing one is never a surprise.
		if ( belongsElsewhere( item, type ) ) {
			labelChildren.push( createEl( 'span', {
				className: 'lrn-builder-modal__result-note',
				textContent: __( 'in another course', 'learnomy' ),
			} ) );
		}

		var li = createEl( 'li', {
			className: 'lrn-builder-modal__result',
			'data-item-id': item.id,
			'data-item-type': type,
		}, labelChildren );

		li.addEventListener( 'click', function () {
			if ( belongsElsewhere( item, type ) ) {
				showAttachChoice( item );
			} else {
				attachItem( item, type );
			}
		} );

		resultsList.appendChild( li );
	}

	// Copy (recommended: the source course keeps its lesson, the new course
	// gets an independent row with its own progress and URL) or Move (the
	// old behaviour, now labelled as what it is).
	function showAttachChoice( item ) {
		resultsList.textContent = '';

		var panel = createEl( 'li', { className: 'lrn-builder-modal__choice' }, [
			createEl( 'p', {
				className: 'lrn-builder-modal__choice-title',
				textContent: sprintf(
					/* translators: %s: lesson title */
					__( '"%s" belongs to another course.', 'learnomy' ),
					item.title
				),
			} ),
			createEl( 'p', {
				className: 'lrn-builder-modal__choice-help',
				textContent: __( 'Copy it to reuse the content here while the original course keeps its lesson. Moving it removes it from that course and changes its old link.', 'learnomy' ),
			} ),
		] );

		var copyBtn = createEl( 'button', {
			type: 'button',
			className: 'lrn-admin-btn lrn-admin-btn--primary',
		}, [ __( 'Copy into this course', 'learnomy' ) ] );
		copyBtn.addEventListener( 'click', function () {
			copyLessonHere( item );
		} );

		var moveBtn = createEl( 'button', {
			type: 'button',
			className: 'lrn-admin-btn lrn-admin-btn--ghost',
		}, [ __( 'Move it here', 'learnomy' ) ] );
		moveBtn.addEventListener( 'click', function () {
			attachItem( item, 'lesson' );
		} );

		var backBtn = createEl( 'button', {
			type: 'button',
			className: 'lrn-admin-btn lrn-admin-btn--ghost',
		}, [ __( 'Back', 'learnomy' ) ] );
		backBtn.addEventListener( 'click', function () {
			runSearch( searchInput.value.trim() );
		} );

		panel.appendChild( createEl( 'div', { className: 'lrn-builder-modal__choice-actions' }, [ copyBtn, moveBtn, backBtn ] ) );
		resultsList.appendChild( panel );
	}

	function copyLessonHere( item ) {
		apiRequest( 'POST', 'lessons/' + item.id + '/copy', {
			// section_id 0 = the course-root pool; course_id names the target
			// course either way (the server derives it from a real section).
			section_id: parseInt( attachSectionId, 10 ) || 0,
			course_id:  parseInt( courseId, 10 ),
		} ).then( function ( json ) {
			var created = json.data || json;
			appendLessonNode( attachSectionId, created );
			closeModal();
			showToast( __( 'Lesson copied into this course.', 'learnomy' ) );
		} ).catch( function ( err ) {
			showToast( err.message, true );
		} );
	}

	function attachItem( item, type ) {
		if ( type === 'lesson' ) {
			apiRequest( 'PATCH', 'lessons/' + item.id, {
				section_id: parseInt( attachSectionId, 10 ) || 0,
				// Explicit target course. Without it, attaching into the
				// course-ROOT pool (section_id 0) never re-coursed the lesson:
				// validate_placement only derives the course from a real
				// section, so a foreign lesson silently detached inside its
				// OWN course instead of arriving here, and an orphan stayed
				// course-less. Naming the course also engages the
				// destination-ownership check for the root case.
				course_id:  parseInt( courseId, 10 ),
			} ).then( function ( json ) {
				var updated = json.data || json;
				appendLessonNode( attachSectionId, updated );
				closeModal();
				showToast(
					belongsElsewhere( item, 'lesson' )
						? __( 'Lesson moved into this course.', 'learnomy' )
						: __( 'Lesson attached.', 'learnomy' )
				);
			} ).catch( function ( err ) {
				showToast( err.message, true );
			} );
		} else if ( type === 'quiz' ) {
			apiRequest( 'PATCH', 'quizzes/' + item.id, {
				course_id:  courseId,
				section_id: parseInt( attachSectionId, 10 ),
			} ).then( function ( json ) {
				var updated = json.data || json;
				appendQuizNode( attachSectionId, updated );
				closeModal();
				showToast( __( 'Quiz attached.', 'learnomy' ) );
			} ).catch( function ( err ) {
				showToast( err.message, true );
			} );
		}
	}

	// =========================================================================
	// HTML5 drag-drop — lesson reorder (within + across sections)
	// =========================================================================
	//
	// Keeps the existing arrow buttons as a fallback. The dragstart handler
	// stashes the lesson id on the dataTransfer payload; dragover adds a
	// visual drop-zone indicator to the hovered row; drop computes the new
	// sort_order by index and — if the section changed — the new section_id,
	// then POSTs a single {assignments:[{lesson_id,section_id,sort_order}…]}
	// batch to `/sections/reorder-lessons`. We optimistically move the DOM
	// before the REST round-trip and roll back on error.
	//
	// Only lesson rows are draggable — quiz rows still use the up/down
	// arrows for reordering within a section. Drag-drop for quiz slots is
	// handled separately in quiz-editor.js.

	var dragState = {
		draggedEl:         null,
		originalSectionId: 0,
		originalNext:      null, // Sibling node we can re-insert before on rollback.
	};

	tree.addEventListener( 'dragstart', function ( e ) {
		var lessonEl = e.target.closest( '.lrn-lesson-item, .lrn-builder__item--lesson' );
		if ( ! lessonEl ) {
			return;
		}
		dragState.draggedEl         = lessonEl;
		dragState.originalSectionId = parseInt( lessonEl.getAttribute( 'data-section-id' ), 10 ) || 0;
		dragState.originalNext      = lessonEl.nextElementSibling;

		lessonEl.classList.add( 'is-dragging' );

		if ( e.dataTransfer ) {
			e.dataTransfer.effectAllowed = 'move';
			try {
				e.dataTransfer.setData( 'text/plain', lessonEl.getAttribute( 'data-item-id' ) || '' );
			} catch ( ignore ) {
				// IE-era browsers — we don't support them, swallow.
			}
		}
	} );

	tree.addEventListener( 'dragend', function () {
		if ( dragState.draggedEl ) {
			dragState.draggedEl.classList.remove( 'is-dragging' );
		}
		clearDropZones();
		dragState.draggedEl         = null;
		dragState.originalSectionId = 0;
		dragState.originalNext      = null;
	} );

	// Throttle: one rAF pass per dragover event cluster.
	var dragOverFrame = 0;

	tree.addEventListener( 'dragover', function ( e ) {
		if ( ! dragState.draggedEl ) {
			return;
		}
		// Must preventDefault to enable drop.
		e.preventDefault();
		if ( e.dataTransfer ) {
			e.dataTransfer.dropEffect = 'move';
		}

		if ( dragOverFrame ) {
			return;
		}
		dragOverFrame = window.requestAnimationFrame( function () {
			dragOverFrame = 0;
			paintDropZone( e.clientY, e.target );
		} );
	} );

	tree.addEventListener( 'drop', function ( e ) {
		if ( ! dragState.draggedEl ) {
			return;
		}
		e.preventDefault();

		var target     = e.target.closest( '.lrn-lesson-item, .lrn-builder__item--lesson, .lrn-builder__items, .lrn-builder__section' );
		var dragged    = dragState.draggedEl;
		var moved      = moveDraggedTo( target, e.clientY );
		clearDropZones();

		if ( ! moved ) {
			return;
		}

		// Compute new assignments for every affected section and POST.
		var assignments  = collectAssignments( moved.affectedSections );
		var undoSnapshot = moved.undo;

		apiRequest( 'POST', 'sections/reorder-lessons', { assignments: assignments } ).then( function () {
			showToast( __( 'Lesson order saved.', 'learnomy' ) );
			// Update the lesson's data-section-id to match its new home so
			// subsequent drags pick up the correct origin.
			dragged.setAttribute( 'data-section-id', String( moved.newSectionId ) );
		} ).catch( function ( err ) {
			undoSnapshot();
			showToast( err.message || __( 'Could not save lesson order.', 'learnomy' ), true );
		} );
	} );

	function paintDropZone( clientY, rawTarget ) {
		clearDropZones();
		var row = rawTarget && rawTarget.closest ? rawTarget.closest( '.lrn-lesson-item, .lrn-builder__item--lesson' ) : null;
		if ( ! row || row === dragState.draggedEl ) {
			return;
		}
		var rect = row.getBoundingClientRect();
		var after = clientY > ( rect.top + rect.height / 2 );
		row.classList.add( after ? 'drop-zone-active--after' : 'drop-zone-active--before' );
	}

	function clearDropZones() {
		var marked = tree.querySelectorAll( '.drop-zone-active--before, .drop-zone-active--after' );
		marked.forEach( function ( el ) {
			el.classList.remove( 'drop-zone-active--before' );
			el.classList.remove( 'drop-zone-active--after' );
		} );
	}

	function moveDraggedTo( target, clientY ) {
		var dragged = dragState.draggedEl;
		if ( ! dragged || ! target ) {
			return null;
		}

		// Original position — for rollback we re-insert before the same sibling.
		var origParent = dragged.parentNode;
		var origNext   = dragState.originalNext;

		var targetRow  = target.closest( '.lrn-lesson-item, .lrn-builder__item--lesson' );
		var targetList = target.closest( '.lrn-builder__items' );
		var targetSec  = target.closest( '.lrn-builder__section' );

		if ( ! targetList && targetSec ) {
			targetList = targetSec.querySelector( '.lrn-builder__items' );
		}
		if ( ! targetList ) {
			return null;
		}

		if ( targetRow && targetRow !== dragged ) {
			var rect  = targetRow.getBoundingClientRect();
			var after = clientY > ( rect.top + rect.height / 2 );
			if ( after ) {
				targetList.insertBefore( dragged, targetRow.nextSibling );
			} else {
				targetList.insertBefore( dragged, targetRow );
			}
		} else {
			// Empty list / dropped on section — append at end.
			targetList.appendChild( dragged );
		}

		var newSectionEl = dragged.closest( '.lrn-builder__section' );
		var newSectionId = newSectionEl ? parseInt( newSectionEl.getAttribute( 'data-section-id' ), 10 ) || 0 : 0;

		var affected = [ newSectionId ];
		if ( dragState.originalSectionId && dragState.originalSectionId !== newSectionId ) {
			affected.push( dragState.originalSectionId );
		}

		return {
			newSectionId:     newSectionId,
			affectedSections: affected,
			undo:             function () {
				if ( origNext && origNext.parentNode === origParent ) {
					origParent.insertBefore( dragged, origNext );
				} else {
					origParent.appendChild( dragged );
				}
			},
		};
	}

	function collectAssignments( sectionIds ) {
		var payload = [];
		sectionIds.forEach( function ( sid ) {
			if ( ! sid ) {
				return;
			}
			var sectionEl = tree.querySelector( '[data-section-id="' + sid + '"]' );
			if ( ! sectionEl ) {
				return;
			}
			var lessonEls = sectionEl.querySelectorAll( '[data-item-type="lesson"]' );
			lessonEls.forEach( function ( el, index ) {
				payload.push( {
					lesson_id:  parseInt( el.getAttribute( 'data-item-id' ), 10 ) || 0,
					section_id: sid,
					sort_order: index,
				} );
			} );
		} );
		return payload;
	}

} )();
