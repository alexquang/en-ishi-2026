/**
 * Extracted inline script from includes/admin/class-pricing-gateway-watcher.php.
 * Enqueued via class-admin.php conditionally on the matching admin page.
 * Per ux-foundation rule F2 (no inline <script> in PHP).
 *
 * @package Learnomy
 */
				( function () {
					var n = document.querySelector( '.learnomy-gateway-warning' );
					if ( ! n ) { return; }
					n.addEventListener( 'click', function ( ev ) {
						if ( ! ev.target.classList.contains( 'notice-dismiss' ) ) { return; }
						var ids   = n.getAttribute( 'data-course-ids' ) || '';
						var nonce = n.getAttribute( 'data-nonce' ) || '';
						var body  = new URLSearchParams();
						body.append( 'action', 'learnomy_dismiss_gateway_warning' );
						body.append( 'course_ids', ids );
						body.append( 'nonce', nonce );
						fetch( ajaxurl, {
							method: 'POST',
							credentials: 'same-origin',
							headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
							body: body.toString()
						} );
					} );
				} )();
