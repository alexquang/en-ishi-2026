/**
 * Lesson comments — Interactivity API store for posting, replying, deleting.
 *
 * Uses `data-wp-interactive="learnomy/comments"` on the comments section.
 * Communicates with the REST API at /learnomy/v1/lessons/{id}/comments
 * and /learnomy/v1/comments/{id}/reply.
 *
 * @package Learnomy
 */
import { store, getContext, getElement } from '@wordpress/interactivity';

/**
 * Comments strings, read from the JSON element printed next to the markup.
 * Module scripts can't reliably receive wp_localize_script data, so the
 * template ships it in the DOM. Every read goes through t() with a hardcoded
 * fallback, so a missing/garbled data element can NEVER throw — a throw in the
 * post-success path used to be swallowed and left the form populated, which is
 * how a single comment became duplicates on retry.
 */
const commentsData = ( function () {
	try {
		return JSON.parse(
			document.getElementById( 'learnomy-comments-data' )?.textContent || '{}'
		);
	} catch ( e ) {
		return {};
	}
}() );
const commentsI18n = commentsData.i18n || {};

function t( key, fallback ) {
	return commentsI18n[ key ] || fallback;
}

function commentsToast( message, type ) {
	if ( window.lrnToast ) {
		window.lrnToast( message, type || 'success' );
	}
}

store( 'learnomy/comments', {
	state: {
		submitting: false,
	},

	actions: {
		/**
		 * Submit a new top-level comment.
		 */
		*submitComment( event ) {
			event.preventDefault();

			const ctx  = getContext();
			const form = event.target;
			const textarea = form.querySelector( 'textarea[name="comment_text"]' );
			const content  = textarea ? textarea.value.trim() : '';

			if ( ! content ) {
				return;
			}

			const submitBtn = form.querySelector( 'button[type="submit"]' );
			if ( submitBtn ) {
				submitBtn.disabled = true;
			}

			try {
				const response = yield wp.apiFetch( {
					path: '/learnomy/v1/lessons/' + ctx.lessonId + '/comments',
					method: 'POST',
					data: { content },
				} );

				const comment = response.data || response;

				// Clear the form FIRST. The post already succeeded server-side,
				// so the stale text must not survive — otherwise a render hiccup
				// below (or an impatient click) re-posts it as a duplicate.
				if ( textarea ) {
					textarea.value = '';
				}

				// Held for moderation (Settings > Discussion, disallowed keys,
				// or an anti-spam plugin): the comment is NOT live, so it must
				// not enter the thread - the list only serves approved
				// comments, and a node that vanishes on reload reads as a
				// deletion. Tell the member instead.
				if ( comment && comment.moderation === 'pending' ) {
					commentsToast( t( 'pendingNotice', 'Your comment is awaiting review. It will appear once it is approved.' ), 'success' );
					return;
				}

				const list = document.getElementById( 'lrn-comments-list' );
				if ( list ) {
					// Remove "no comments" message if present.
					const emptyMsg = list.querySelector( '.lrn-comments-list__empty' );
					if ( emptyMsg ) {
						emptyMsg.remove();
					}

					list.appendChild( buildCommentNode( comment, true ) );
				}

				updateCommentCount( 1 );
				commentsToast( t( 'posted', 'Comment posted.' ), 'success' );
			} catch ( error ) {
				commentsToast( t( 'postFailed', 'Could not post your comment. Please try again.' ), 'error' );
			} finally {
				if ( submitBtn ) {
					submitBtn.disabled = false;
				}
			}
		},

		/**
		 * Toggle inline reply form visibility.
		 */
		toggleReplyForm() {
			const el  = getElement();
			const btn = el.ref;

			const commentBody = btn.closest( '.lrn-comment__body' );
			if ( ! commentBody ) {
				return;
			}

			const replyForm = commentBody.querySelector( '.lrn-comment-form--reply' );
			if ( ! replyForm ) {
				return;
			}

			// Use the HTML hidden attribute — no inline style (check 1: tokens_only).
			const isHidden = replyForm.hasAttribute( 'hidden' );
			if ( isHidden ) {
				replyForm.removeAttribute( 'hidden' );
				const textarea = replyForm.querySelector( 'textarea' );
				if ( textarea ) {
					textarea.focus();
				}
			} else {
				replyForm.setAttribute( 'hidden', '' );
			}
		},

		/**
		 * Submit a reply to an existing comment.
		 */
		*submitReply( event ) {
			event.preventDefault();

			const form     = event.target;
			const parentId = parseInt( form.dataset.parentId, 10 );
			const textarea = form.querySelector( 'textarea[name="reply_text"]' );
			const content  = textarea ? textarea.value.trim() : '';

			if ( ! content || ! parentId ) {
				return;
			}

			const submitBtn = form.querySelector( 'button[type="submit"]' );
			if ( submitBtn ) {
				submitBtn.disabled = true;
			}

			try {
				const response = yield wp.apiFetch( {
					path: '/learnomy/v1/comments/' + parentId + '/reply',
					method: 'POST',
					data: { content },
				} );

				const reply         = response.data || response;

				// Clear + hide the reply form FIRST so a render hiccup below
				// can't leave stale text that a retry would re-post.
				if ( textarea ) {
					textarea.value = '';
				}
				form.setAttribute( 'hidden', '' );

				// Same moderation guard as the top-level path: a held reply
				// must not enter the thread it is not yet part of.
				if ( reply && reply.moderation === 'pending' ) {
					commentsToast( t( 'pendingNotice', 'Your comment is awaiting review. It will appear once it is approved.' ), 'success' );
					return;
				}

				const parentComment = document.getElementById( 'lrn-comment-' + parentId );
				if ( parentComment ) {
					const commentBody = parentComment.querySelector( '.lrn-comment__body' );
					let repliesWrap   = commentBody
						? commentBody.querySelector( '.lrn-comment__replies' )
						: null;

					if ( ! repliesWrap && commentBody ) {
						repliesWrap = document.createElement( 'div' );
						repliesWrap.className = 'lrn-comment__replies';
						commentBody.appendChild( repliesWrap );
					}

					if ( repliesWrap ) {
						repliesWrap.appendChild( buildCommentNode( reply, true ) );
					}
				}

				updateCommentCount( 1 );
				commentsToast( t( 'posted', 'Comment posted.' ), 'success' );
			} catch ( error ) {
				commentsToast( t( 'postFailed', 'Could not post your comment. Please try again.' ), 'error' );
			} finally {
				if ( submitBtn ) {
					submitBtn.disabled = false;
				}
			}
		},

		/**
		 * Delete a comment after confirmation. Thin wrapper over the shared
		 * performDelete(): the directive path serves server-rendered buttons,
		 * while JS-created buttons attach a plain listener to the SAME helper
		 * in buildCommentNode() - the Interactivity API never wires
		 * directives on DOM it didn't render, so a data-wp-on set via
		 * setAttribute was a dead button (Basecamp 10171791438).
		 */
		*deleteComment() {
			const el  = getElement();
			const btn = el.ref;
			yield performDelete( parseInt( btn.dataset.commentId, 10 ) );
		},
	},
} );

/**
 * Confirm, DELETE over REST, and remove the comment node. One
 * implementation behind both the directive action (hydrated buttons) and
 * the plain listener on buttons this module creates after post/reply.
 *
 * @param {number} commentId Comment to delete.
 */
async function performDelete( commentId ) {
	if ( ! commentId ) {
		return;
	}

	const ok = await window.lrnConfirm( {
		title:        t( 'deleteLabel', 'Delete' ),
		message:      t( 'confirmDelete', 'Are you sure you want to delete this comment?' ),
		confirmLabel: t( 'deleteLabel', 'Delete' ),
		destructive:  true,
	} );
	if ( ! ok ) {
		return;
	}

	const commentEl = document.getElementById( 'lrn-comment-' + commentId );

	// Optimistic: dim immediately.
	if ( commentEl ) {
		commentEl.style.opacity = '0.4';
		commentEl.style.pointerEvents = 'none';
	}

	try {
		await wp.apiFetch( {
			path: '/learnomy/v1/comments/' + commentId,
			method: 'DELETE',
		} );

		let removedCount = 1;
		if ( commentEl ) {
			const replies = commentEl.querySelectorAll( '.lrn-comment--reply' );
			removedCount += replies.length;
			commentEl.remove();
		}

		updateCommentCount( -removedCount );
	} catch ( error ) {
		if ( commentEl ) {
			commentEl.style.opacity = '';
			commentEl.style.pointerEvents = '';
		}
	}
}

/**
 * Builds a comment DOM node using safe DOM methods (no innerHTML).
 *
 * @param {Object}  comment   Comment data from the API.
 * @param {boolean} canDelete Whether the current user can delete.
 * @return {HTMLElement}
 */
function buildCommentNode( comment, canDelete ) {
	const wrap = document.createElement( 'div' );
	wrap.className = comment.parent_id ? 'lrn-comment lrn-comment--reply' : 'lrn-comment';
	wrap.id = 'lrn-comment-' + comment.id;
	wrap.dataset.commentId = comment.id;

	// Avatar.
	const avatar = document.createElement( 'img' );
	avatar.className = 'lrn-comment__avatar';
	avatar.src    = comment.author_avatar || '';
	avatar.alt    = '';
	avatar.width  = 36;
	avatar.height = 36;
	wrap.appendChild( avatar );

	// Body.
	const body = document.createElement( 'div' );
	body.className = 'lrn-comment__body';

	// Header.
	const header = document.createElement( 'div' );
	header.className = 'lrn-comment__header';

	const authorSpan = document.createElement( 'span' );
	authorSpan.className   = 'lrn-comment__author';
	authorSpan.textContent = comment.author_name || '';
	header.appendChild( authorSpan );

	if ( comment.is_instructor ) {
		const badge = document.createElement( 'span' );
		badge.className   = 'lrn-comment__badge';
		badge.textContent = t( 'instructor', 'Instructor' );
		header.appendChild( badge );
	}

	const time = document.createElement( 'time' );
	time.className   = 'lrn-comment__date';
	time.textContent = comment.date_relative || comment.time_ago || '';
	header.appendChild( time );

	body.appendChild( header );

	// Text.
	const text = document.createElement( 'p' );
	text.className   = 'lrn-comment__text';
	text.textContent = comment.content || '';
	body.appendChild( text );

	// Actions.
	if ( canDelete ) {
		const actions = document.createElement( 'div' );
		actions.className = 'lrn-comment__actions';

		const deleteBtn = document.createElement( 'button' );
		deleteBtn.type = 'button';
		deleteBtn.className = 'lrn-comment__delete-btn';
		deleteBtn.textContent = t( 'deleteLabel', 'Delete' );
		deleteBtn.dataset.commentId = comment.id;
		// A real listener, not a data-wp-on attribute: the Interactivity API
		// only wires directives on DOM it rendered, so the attribute form was
		// a dead button on every comment this module creates in-session
		// (Basecamp 10171791438). Same helper the directive path uses.
		deleteBtn.addEventListener( 'click', () => performDelete( parseInt( comment.id, 10 ) ) );
		actions.appendChild( deleteBtn );

		body.appendChild( actions );
	}

	wrap.appendChild( body );
	return wrap;
}

/**
 * Updates the "(X comments)" count in the heading.
 *
 * @param {number} delta Positive or negative adjustment.
 */
function updateCommentCount( delta ) {
	const heading = document.querySelector( '.lrn-lesson-comments__heading' );
	if ( ! heading ) {
		return;
	}

	const match = heading.textContent.match( /\((\d+)/ );
	if ( match ) {
		const newCount = Math.max( 0, parseInt( match[1], 10 ) + delta );
		const label    = newCount === 1
			? t( 'commentSingular', 'Discussion (%d comment)' )
			: t( 'commentPlural', 'Discussion (%d comments)' );
		heading.textContent = label.replace( '%d', newCount );
	}
}
