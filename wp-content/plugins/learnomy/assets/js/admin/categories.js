/**
 * Categories & Tags — CRUD interactions via the REST API.
 *
 * Handles add, edit, and delete for both categories and tags on the
 * tabbed admin page. Uses `window.learnomyAdmin` for restUrl and restNonce.
 *
 * @package Learnomy
 */

( function () {
	'use strict';

	var __       = wp.i18n.__;
	var _n       = wp.i18n._n;
	var sprintf  = wp.i18n.sprintf;
	var cfg      = window.learnomyAdmin || {};
	var restUrl  = cfg.restUrl || '/wp-json/learnomy/v1/';
	var restNonce = cfg.restNonce || '';

	// =========================================================================
	// Utility
	// =========================================================================

	/**
	 * Escape a string for safe insertion into HTML using textContent.
	 */
	function escHtml( str ) {
		var div = document.createElement( 'div' );
		div.appendChild( document.createTextNode( str || '' ) );
		return div.innerHTML;
	}

	// Delegates to the shared toast (`assets/js/shared/toast.js`).
	//
	// This built its own bare `.lrn-toast` outside the stack container and drove
	// it with a `lrn-toast--visible` class that no stylesheet defines, so it got
	// none of the component's entry animation, icon, dismiss button or
	// `aria-live` region. Every call below is immediately followed by a reload
	// anyway, which destroyed it outright.
	function showToast( message, type ) {
		window.lrnToast( message, type || 'success' );
	}

	// For the reload paths — see `lrnToast.flash()`.
	showToast.flash = function ( message, type ) {
		window.lrnToast.flash( message, type || 'success' );
	};

	function apiRequest( method, path, body ) {
		var options = {
			method:  method,
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce':   restNonce,
			},
			credentials: 'same-origin',
		};

		if ( body ) {
			options.body = JSON.stringify( body );
		}

		var fullUrl = restUrl + path;

		return fetch( fullUrl, options ).then( function ( response ) {
			return response.json().then( function ( json ) {
				if ( ! response.ok ) {
					// Log the full failure context to console so QA can paste it
					// onto the bug card without having to crack open the Network tab.
					// Catches the 'rest_no_route' class of bug where the customer's
					// site silently lost a route registration (cached opcache, wrong
					// plugin version, mu-plugin override) and previously surfaced
					// only a generic toast.
					if ( window.console && window.console.error ) {
						window.console.error(
							'[learnomy/categories] ' + method + ' ' + fullUrl + ' → HTTP ' + response.status,
							{ requestBody: body, responseBody: json }
						);
					}
					var msg = ( json.error && json.error.message ) || json.message || 'Request failed.';
					var err = new Error( msg );
					err.status = response.status;
					err.code = json.code;
					err.url = fullUrl;
					throw err;
				}
				return json;
			} );
		} );
	}

	function setSpinnerActive( wrapper, active ) {
		var spin = wrapper.querySelector( '.spinner' );
		if ( spin ) {
			spin.classList.toggle( 'is-active', active );
		}
	}

	// =========================================================================
	// Categories
	// =========================================================================

	var catNameInput   = document.getElementById( 'lrn-cat-name' );
	var catSlugInput   = document.getElementById( 'lrn-cat-slug' );
	var catParentSel   = document.getElementById( 'lrn-cat-parent' );
	var catDescInput   = document.getElementById( 'lrn-cat-description' );
	var catSaveBtn     = document.getElementById( 'lrn-save-category' );
	var catCancelBtn   = document.getElementById( 'lrn-cancel-category-edit' );
	var catFormWrap    = document.getElementById( 'lrn-category-form-wrap' );
	var catList        = document.getElementById( 'lrn-categories-list' );

	// Track whether we are editing an existing category.
	var editingCatId = 0;

	function resetCategoryForm() {
		editingCatId = 0;

		if ( catNameInput ) {
			catNameInput.value = '';
		}
		if ( catSlugInput ) {
			catSlugInput.value = '';
		}
		if ( catParentSel ) {
			catParentSel.value = '0';
		}
		if ( catDescInput ) {
			catDescInput.value = '';
		}
		if ( catSaveBtn ) {
			catSaveBtn.textContent = __( 'Add Category', 'learnomy' );
		}
		if ( catCancelBtn ) {
			catCancelBtn.hidden = true;
		}
		if ( catFormWrap ) {
			var title = catFormWrap.querySelector( '.lrn-admin-card__title' );
			if ( title ) {
				title.textContent = __( 'Add New Category', 'learnomy' );
			}
		}
	}

	if ( catSaveBtn ) {
		catSaveBtn.addEventListener( 'click', function () {
			var name = catNameInput ? catNameInput.value.trim() : '';
			if ( ! name ) {
				showToast( __( 'Category name is required.', 'learnomy' ), 'error' );
				if ( catNameInput ) {
					catNameInput.focus();
				}
				return;
			}

			var payload = {
				name:        name,
				slug:        catSlugInput ? catSlugInput.value.trim() : '',
				parent_id:   catParentSel ? parseInt( catParentSel.value, 10 ) || 0 : 0,
				description: catDescInput ? catDescInput.value.trim() : '',
			};

			catSaveBtn.disabled = true;
			setSpinnerActive( catSaveBtn.parentNode, true );

			var method = editingCatId ? 'PATCH' : 'POST';
			var path   = editingCatId
				? 'admin/categories/' + editingCatId
				: 'admin/categories';

			apiRequest( method, path, payload )
				.then( function ( json ) {
					var msg = editingCatId ? __( 'Category updated.', 'learnomy' ) : __( 'Category added.', 'learnomy' );
					showToast.flash( json.data && json.data.message ? json.data.message : msg, 'success' );
					// Reload page to reflect the updated table and parent dropdown.
					window.location.reload();
				} )
				.catch( function ( err ) {
					var msg = err.message || __( 'Could not save category.', 'learnomy' );
					// 404 rest_no_route is a server-side wiring issue, not user error
					// — point the admin at the actionable resolution.
					if ( err.code === 'rest_no_route' ) {
						msg = __( 'The category endpoint is not registered on this site. Try Settings → Permalinks → Save Changes, then reload. See browser console for the exact URL being requested.', 'learnomy' );
					}
					showToast( msg, 'error' );
					catSaveBtn.disabled = false;
					setSpinnerActive( catSaveBtn.parentNode, false );
				} );
		} );
	}

	if ( catCancelBtn ) {
		catCancelBtn.addEventListener( 'click', function () {
			resetCategoryForm();
		} );
	}

	// Edit and delete category links — delegated.
	if ( catList ) {
		catList.addEventListener( 'click', async function ( e ) {
			var editLink = e.target.closest( '.lrn-edit-category' );
			var deleteLink = e.target.closest( '.lrn-delete-category' );

			if ( editLink ) {
				e.preventDefault();
				editingCatId = parseInt( editLink.dataset.id, 10 ) || 0;

				if ( catNameInput ) {
					catNameInput.value = editLink.dataset.name || '';
				}
				if ( catSlugInput ) {
					catSlugInput.value = editLink.dataset.slug || '';
				}
				if ( catParentSel ) {
					catParentSel.value = editLink.dataset.parent || '0';
				}
				if ( catDescInput ) {
					catDescInput.value = editLink.dataset.description || '';
				}
				if ( catSaveBtn ) {
					catSaveBtn.textContent = __( 'Update Category', 'learnomy' );
				}
				if ( catCancelBtn ) {
					catCancelBtn.hidden = false;
				}
				if ( catFormWrap ) {
					var title = catFormWrap.querySelector( '.lrn-admin-card__title' );
					if ( title ) {
						title.textContent = __( 'Edit Category', 'learnomy' );
					}
				}

				// Scroll the form into view for convenience.
				if ( catNameInput ) {
					catNameInput.focus();
				}
				return;
			}

			if ( deleteLink ) {
				e.preventDefault();
				var catId = parseInt( deleteLink.dataset.id, 10 ) || 0;
				if ( ! catId ) {
					return;
				}

				// When courses are still in this category, tell the user up front
				// that they will move to Uncategorized — so deleting is never a
				// confusing dead-end ("nothing happened").
				var courseCount = parseInt( deleteLink.dataset.courseCount, 10 ) || 0;
				var confirmMsg = courseCount > 0
					? sprintf(
						/* translators: %d: number of courses in the category. */
						_n(
							'This category has %d course. Deleting it will move that course to Uncategorized. Continue?',
							'This category has %d courses. Deleting them is not required. They will move to Uncategorized. Continue?',
							courseCount,
							'learnomy'
						),
						courseCount
					)
					// Published count can be 0 while drafts still exist, so phrase
					// the fallback so it is accurate whether or not courses move.
					: __( 'Deleting this category will move any courses in it to Uncategorized. Continue?', 'learnomy' );

				var okCat = await window.lrnConfirm( {
					title:        __( 'Delete category', 'learnomy' ),
					message:      confirmMsg,
					confirmLabel: __( 'Delete', 'learnomy' ),
					destructive:  true,
				} );
				if ( ! okCat ) {
					return;
				}

				deleteLink.textContent = __( 'Deleting…', 'learnomy' );

				apiRequest( 'DELETE', 'admin/categories/' + catId )
					.then( function ( res ) {
						var movedCount = ( res && res.data && res.data.courses_moved ) || ( res && res.courses_moved ) || 0;
						showToast(
							movedCount > 0
								? sprintf(
									/* translators: %d: number of courses moved to Uncategorized. */
									_n(
										'Category deleted. %d course moved to Uncategorized.',
										'Category deleted. %d courses moved to Uncategorized.',
										movedCount,
										'learnomy'
									),
									movedCount
								)
								: __( 'Category deleted.', 'learnomy' ),
							'success'
						);
						var row = deleteLink.closest( 'tr' );
						if ( row ) {
							row.remove();
						}

						// If we were editing this category, reset the form.
						if ( editingCatId === catId ) {
							resetCategoryForm();
						}
					} )
					.catch( function ( err ) {
						showToast( err.message || 'Could not delete category.', 'error' );
						deleteLink.textContent = __( 'Delete', 'learnomy' );
					} );
			}
		} );
	}

	// =========================================================================
	// Tags
	// =========================================================================

	var tagNameInput = document.getElementById( 'lrn-tag-name' );
	var tagSlugInput = document.getElementById( 'lrn-tag-slug' );
	var tagSaveBtn   = document.getElementById( 'lrn-save-tag' );
	var tagCancelBtn = document.getElementById( 'lrn-cancel-tag-edit' );
	var tagList      = document.getElementById( 'lrn-tags-list' );

	var editingTagId = 0;

	function resetTagForm() {
		editingTagId = 0;

		if ( tagNameInput ) {
			tagNameInput.value = '';
		}
		if ( tagSlugInput ) {
			tagSlugInput.value = '';
		}
		if ( tagSaveBtn ) {
			tagSaveBtn.textContent = __( 'Add Tag', 'learnomy' );
		}
		if ( tagCancelBtn ) {
			tagCancelBtn.hidden = true;
		}
	}

	if ( tagSaveBtn ) {
		tagSaveBtn.addEventListener( 'click', function () {
			var name = tagNameInput ? tagNameInput.value.trim() : '';
			if ( ! name ) {
				showToast( __( 'Tag name is required.', 'learnomy' ), 'error' );
				if ( tagNameInput ) {
					tagNameInput.focus();
				}
				return;
			}

			var payload = {
				name: name,
				slug: tagSlugInput ? tagSlugInput.value.trim() : '',
			};

			// Create into the active vocabulary (Course / Lesson / Quiz /
			// Question). Only sent on create — a tag's type is fixed.
			var tagTypeEl = document.getElementById( 'lrn-tag-type' );
			if ( ! editingTagId && tagTypeEl && tagTypeEl.value ) {
				payload.type = tagTypeEl.value;
			}

			tagSaveBtn.disabled = true;
			setSpinnerActive( tagSaveBtn.parentNode, true );

			var method = editingTagId ? 'PATCH' : 'POST';
			var path   = editingTagId
				? 'admin/tags/' + editingTagId
				: 'admin/tags';

			apiRequest( method, path, payload )
				.then( function ( json ) {
					var msg = editingTagId ? __( 'Tag updated.', 'learnomy' ) : __( 'Tag added.', 'learnomy' );
					showToast.flash( json.data && json.data.message ? json.data.message : msg, 'success' );
					window.location.reload();
				} )
				.catch( function ( err ) {
					showToast( err.message || __( 'Could not save tag.', 'learnomy' ), 'error' );
					tagSaveBtn.disabled = false;
					setSpinnerActive( tagSaveBtn.parentNode, false );
				} );
		} );
	}

	if ( tagCancelBtn ) {
		tagCancelBtn.addEventListener( 'click', function () {
			resetTagForm();
		} );
	}

	// Edit and delete tag links — delegated.
	if ( tagList ) {
		tagList.addEventListener( 'click', async function ( e ) {
			var editLink = e.target.closest( '.lrn-edit-tag' );
			var deleteLink = e.target.closest( '.lrn-delete-tag' );

			if ( editLink ) {
				e.preventDefault();
				editingTagId = parseInt( editLink.dataset.id, 10 ) || 0;

				if ( tagNameInput ) {
					tagNameInput.value = editLink.dataset.name || '';
				}
				if ( tagSlugInput ) {
					tagSlugInput.value = editLink.dataset.slug || '';
				}
				if ( tagSaveBtn ) {
					tagSaveBtn.textContent = __( 'Update Tag', 'learnomy' );
				}
				if ( tagCancelBtn ) {
					tagCancelBtn.hidden = false;
				}

				if ( tagNameInput ) {
					tagNameInput.focus();
				}
				return;
			}

			if ( deleteLink ) {
				e.preventDefault();
				var tagId = parseInt( deleteLink.dataset.id, 10 ) || 0;
				if ( ! tagId ) {
					return;
				}
				var okTag = await window.lrnConfirm( {
					title:        __( 'Delete tag', 'learnomy' ),
					message:      __( 'Are you sure you want to delete this tag?', 'learnomy' ),
					confirmLabel: __( 'Delete', 'learnomy' ),
					destructive:  true,
				} );
				if ( ! okTag ) {
					return;
				}

				deleteLink.textContent = __( 'Deleting…', 'learnomy' );

				apiRequest( 'DELETE', 'admin/tags/' + tagId )
					.then( function () {
						showToast( __( 'Tag deleted.', 'learnomy' ), 'success' );
						var row = deleteLink.closest( 'tr' );
						if ( row ) {
							row.remove();
						}

						if ( editingTagId === tagId ) {
							resetTagForm();
						}
					} )
					.catch( function ( err ) {
						showToast( err.message || 'Could not delete tag.', 'error' );
						deleteLink.textContent = __( 'Delete', 'learnomy' );
					} );
			}
		} );
	}
} )();
