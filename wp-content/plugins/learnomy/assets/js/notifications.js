/**
 * Notifications — Interactivity API store for the notification bell.
 *
 * Manages the bell dropdown: toggle open/close, mark single notification
 * as read, mark all read. Loaded on every Learnomy frontend page.
 *
 * @package Learnomy
 */
import { store, getContext, getElement } from '@wordpress/interactivity';

store( 'learnomy/notifications', {
	actions: {
		/**
		 * Toggle the notification dropdown panel.
		 */
		togglePanel() {
			const ctx = getContext();
			ctx.open  = ! ctx.open;
		},

		/**
		 * Close the panel when the click landed outside the bell.
		 *
		 * This used to be a raw document listener that set panel.hidden = true.
		 * The panel's visibility is bound to context.open, so that closed it on
		 * screen while context.open stayed true: the next bell click flipped
		 * context back to false, the binding agreed it was already hidden, and
		 * nothing happened. The customer saw a bell that ignored the first click
		 * every time they dismissed the panel by clicking away. Same state, one
		 * writer.
		 *
		 * @param {Event} event The document click.
		 */
		closeOnOutsideClick( event ) {
			const ctx = getContext();
			if ( ! ctx.open ) {
				return;
			}

			const { ref } = getElement();
			if ( ref && ref.contains( event.target ) ) {
				return;
			}

			ctx.open = false;
		},

		/**
		 * Close the panel on Escape and return focus to the bell.
		 *
		 * A dropdown you can open from the keyboard and not close from it is
		 * only half a control.
		 *
		 * @param {KeyboardEvent} event The document keydown.
		 */
		closeOnEscape( event ) {
			if ( 'Escape' !== event.key ) {
				return;
			}

			const ctx = getContext();
			if ( ! ctx.open ) {
				return;
			}

			ctx.open = false;

			const { ref } = getElement();
			const trigger = ref && ref.querySelector( '.lrn-notification-bell__trigger' );
			if ( trigger ) {
				trigger.focus();
			}
		},

		/**
		 * Mark a single notification as read when clicked.
		 */
		*markRead() {
			const el             = getElement();
			const li             = el.ref;
			const notificationId = parseInt( li.dataset.notificationId, 10 );

			if ( ! notificationId ) {
				return;
			}

			// Optimistic: remove unread styling immediately.
			li.classList.remove( 'lrn-notification-item--unread' );

			const ctx = getContext();
			if ( ctx.unreadCount > 0 ) {
				ctx.unreadCount = ctx.unreadCount - 1;
			}

			// Hide badge when count reaches zero.
			if ( ctx.unreadCount <= 0 ) {
				const badge = document.querySelector( '.lrn-notification-bell__count' );
				if ( badge ) {
					badge.style.display = 'none';
				}
			}

			try {
				yield wp.apiFetch( {
					path: '/learnomy/v1/notifications/' + notificationId + '/read',
					method: 'PATCH',
					// The row is a link, so this request races the navigation it
					// triggers. `keepalive` lets it outlive the document rather
					// than being dropped mid-flight if the browser tears the
					// connection down before it is on the wire. apiFetch forwards
					// unknown options to fetch(); a browser that does not know the
					// flag ignores it.
					//
					// Insurance, not a fix: Basecamp 10199428997 reported the
					// notification staying unread after a click-through and blamed
					// this race, and it does not reproduce - see the commit. The
					// server-side write is not at risk either way, because PHP does
					// not observe a client disconnect until it tries to write
					// output, by which point the UPDATE has run.
					keepalive: true,
				} );
			} catch ( error ) {
				// Revert on failure.
				li.classList.add( 'lrn-notification-item--unread' );
				ctx.unreadCount = ctx.unreadCount + 1;
			}
		},

		/**
		 * Mark all notifications as read.
		 */
		*markAllRead() {
			const ctx = getContext();

			// Optimistic: clear all unread styling.
			const items = document.querySelectorAll( '.lrn-notification-item--unread' );
			items.forEach( function( item ) {
				item.classList.remove( 'lrn-notification-item--unread' );
			} );

			const previousCount = ctx.unreadCount;
			ctx.unreadCount = 0;

			const badge = document.querySelector( '.lrn-notification-bell__count' );
			if ( badge ) {
				badge.style.display = 'none';
			}

			// Hide the "Mark all read" button.
			const markAllBtn = document.querySelector( '.lrn-notification-bell__mark-all' );
			if ( markAllBtn ) {
				markAllBtn.style.display = 'none';
			}

			try {
				yield wp.apiFetch( {
					path: '/learnomy/v1/notifications/mark-all-read',
					method: 'POST',
				} );
			} catch ( error ) {
				// Revert on failure.
				items.forEach( function( item ) {
					item.classList.add( 'lrn-notification-item--unread' );
				} );
				ctx.unreadCount = previousCount;

				if ( badge ) {
					badge.style.display = '';
				}
				if ( markAllBtn ) {
					markAllBtn.style.display = '';
				}
			}
		},
	},
} );
