/**
 * Learnomy — front-end "Block member" affordance.
 *
 * A small, delegated handler for `.lrn-block-btn` buttons that appear next to a
 * review's or comment's author. On click it confirms, then POSTs to
 * /learnomy/v1/users/{id}/block. On success the blocked author's card is hidden
 * immediately (it will not come back on reload — the server filters blocked
 * authors out of comment + review reads), and a toast confirms. This is the
 * web twin of the mobile app's block control (App Store 1.2 UGC gate): report
 * flags content for a moderator; block hides a member from your own reads.
 *
 * Delegated on document so it also covers cards loaded later (e.g. the
 * "Load more reviews" REST paging). ES5, cookie-auth REST, no jQuery.
 *
 * @package Learnomy
 */
( function () {
	'use strict';

	var cfg  = window.lrnBlock || {};
	var i18n = cfg.i18n || {};
	if ( ! cfg.restUrl ) {
		return;
	}

	function toast( type, message ) {
		if ( window.lrnToast ) {
			// Canonical signature is lrnToast( message, type, duration ).
			window.lrnToast( message, type );
		}
	}

	// `learnomy-confirm-modal` is a declared wp_enqueue_script dependency of this
	// file (class-template-loader.php), so window.lrnConfirm is always defined by
	// the time this runs. There is deliberately no native window.confirm()
	// fallback: it was unreachable, untranslatable, and unstyled.
	function confirmBlock( name ) {
		var msg = ( i18n.message || 'Block %s? You will no longer see their comments or reviews. You can unblock them from your account.' )
			.replace( '%s', name || ( i18n.thisMember || 'this member' ) );
		return window.lrnConfirm( {
			title:        i18n.title || 'Block member',
			message:      msg,
			confirmLabel: i18n.confirm || 'Block',
			cancelLabel:  i18n.cancel || 'Cancel',
			destructive:  true
		} );
	}

	// Remove the blocked author's card from view for instant feedback; the next
	// server render omits it anyway.
	function hideAuthorContent( userId ) {
		var nodes = document.querySelectorAll( '[data-author-id="' + userId + '"]' );
		Array.prototype.forEach.call( nodes, function ( node ) {
			var card = node.closest( '.lrn-review-card, .lrn-comment, .lrn-comment-item' ) || node;
			card.style.display = 'none';
		} );
	}

	// Unblock (from the account "Blocked members" list): DELETE the block and
	// drop the row. The web twin of removing a block from the app's manage screen.
	document.addEventListener( 'click', function ( e ) {
		var ubtn = e.target.closest( '.lrn-unblock-btn' );
		if ( ! ubtn || ubtn.disabled ) {
			return;
		}
		e.preventDefault();
		var uid = ubtn.getAttribute( 'data-user-id' );
		if ( ! uid ) {
			return;
		}
		ubtn.disabled = true;
		fetch( cfg.restUrl + 'users/' + parseInt( uid, 10 ) + '/block', {
			method:      'DELETE',
			credentials: 'same-origin',
			headers:     { 'X-WP-Nonce': cfg.nonce || '' }
		} )
			.then( function ( r ) {
				if ( ! r.ok ) {
					throw new Error( i18n.unblockFailed || 'Could not unblock this member. Please try again.' );
				}
				var row = ubtn.closest( '.lrn-account-blocked__row' );
				if ( row ) {
					row.parentNode.removeChild( row );
				}
				toast( 'success', i18n.unblocked || 'Member unblocked.' );
			} )
			.catch( function ( err ) {
				ubtn.disabled = false;
				toast( 'error', err.message );
			} );
	} );

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.lrn-block-btn' );
		if ( ! btn || btn.disabled ) {
			return;
		}
		e.preventDefault();

		var userId = btn.getAttribute( 'data-user-id' );
		var name   = btn.getAttribute( 'data-user-name' ) || '';
		if ( ! userId ) {
			return;
		}

		confirmBlock( name ).then( function ( ok ) {
			if ( ! ok ) {
				return;
			}
			btn.disabled = true;

			fetch( cfg.restUrl + 'users/' + parseInt( userId, 10 ) + '/block', {
				method:      'POST',
				credentials: 'same-origin',
				headers:     { 'X-WP-Nonce': cfg.nonce || '' }
			} )
				.then( function ( r ) {
					return r.json().then( function ( payload ) {
						if ( ! r.ok ) {
							var msg = ( payload && payload.error && payload.error.message )
								|| ( payload && payload.message )
								|| ( i18n.failed || 'Could not block this member. Please try again.' );
							throw new Error( msg );
						}
						return payload;
					} );
				} )
				.then( function () {
					hideAuthorContent( userId );
					toast( 'success', i18n.blocked || 'Member blocked. You will no longer see their content.' );
				} )
				.catch( function ( err ) {
					btn.disabled = false;
					toast( 'error', err.message );
				} );
		} );
	} );
} )();
