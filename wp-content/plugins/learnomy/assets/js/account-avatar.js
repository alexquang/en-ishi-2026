/**
 * Member avatar upload + square crop (account-details page).
 *
 * Self-contained interactive cropper — no third-party library. The member
 * picks an image, drags + zooms it inside a locked square frame, and the
 * visible square is exported via <canvas> to a 512x512 blob that is POSTed
 * to /auth/account/avatar (multipart). The avatar then shows everywhere,
 * because the server filters get_avatar()/get_avatar_url() site-wide.
 *
 * Markup contract (templates/account-details.php):
 *   [data-lrn-avatar][data-endpoint][data-nonce]
 *     img[data-avatar-preview]
 *     button[data-avatar-choose] · button[data-avatar-remove]
 *     input[type=file][data-avatar-input]
 *     span.lrn-avatar-field__status
 *
 * @package Learnomy
 */
( function () {
	'use strict';

	var __ = ( window.wp && window.wp.i18n && window.wp.i18n.__ )
		? window.wp.i18n.__
		: function ( s ) { return s; };

	var OUTPUT = 512;     // exported square size (px)
	var VIEWPORT = 300;   // crop stage size (px)
	var MAX_BYTES = 3 * 1024 * 1024;

	function toast( msg, type ) {
		if ( window.lrnToast ) {
			window.lrnToast( msg, type || 'info' );
		}
	}

	function ready( fn ) {
		if ( document.readyState !== 'loading' ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	ready( function () {
		var field = document.querySelector( '[data-lrn-avatar]' );
		if ( ! field ) {
			return;
		}

		var endpoint = field.getAttribute( 'data-endpoint' );
		var nonce = field.getAttribute( 'data-nonce' );
		var preview = field.querySelector( '[data-avatar-preview]' );
		var chooseBtn = field.querySelector( '[data-avatar-choose]' );
		var removeBtn = field.querySelector( '[data-avatar-remove]' );
		var input = field.querySelector( '[data-avatar-input]' );
		var status = field.querySelector( '.lrn-avatar-field__status' );

		function setStatus( msg, isError ) {
			if ( ! status ) {
				return;
			}
			if ( ! msg ) {
				status.hidden = true;
				status.textContent = '';
				return;
			}
			status.hidden = false;
			status.textContent = msg;
			status.classList.toggle( 'is-error', !! isError );
		}

		chooseBtn.addEventListener( 'click', function () {
			input.value = '';
			input.click();
		} );

		input.addEventListener( 'change', function () {
			var file = input.files && input.files[ 0 ];
			if ( ! file ) {
				return;
			}
			if ( ! /^image\/(jpeg|png|webp|gif)$/.test( file.type ) ) {
				setStatus( __( 'Please choose a JPG, PNG, WebP or GIF image.', 'learnomy' ), true );
				return;
			}
			if ( file.size > MAX_BYTES ) {
				setStatus( __( 'That image is too large. Please use one under 3 MB.', 'learnomy' ), true );
				return;
			}
			setStatus( '' );
			var url = URL.createObjectURL( file );
			openCropper( url, function () {
				URL.revokeObjectURL( url );
			} );
		} );

		removeBtn.addEventListener( 'click', function () {
			if ( removeBtn.disabled ) {
				return;
			}
			var go = function () {
				removeBtn.disabled = true;
				setStatus( __( 'Removing…', 'learnomy' ) );
				fetch( endpoint, {
					method: 'DELETE',
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': nonce }
				} ).then( function ( r ) {
					return r.json().then( function ( body ) {
						return { ok: r.ok, body: body };
					} );
				} ).then( function ( res ) {
					if ( ! res.ok ) {
						removeBtn.disabled = false;
						setStatus( ( res.body && res.body.error && res.body.error.message ) || __( 'Could not remove photo.', 'learnomy' ), true );
						return;
					}
					var newUrl = res.body && res.body.data && res.body.data.avatar_url;
					if ( newUrl && preview ) {
						preview.src = bust( newUrl );
					}
					setStatus( '' );
					toast( __( 'Photo removed.', 'learnomy' ), 'success' );
				} ).catch( function () {
					removeBtn.disabled = false;
					setStatus( __( 'Network error. Please try again.', 'learnomy' ), true );
				} );
			};
			if ( window.lrnConfirm ) {
				/*
				 * lrnConfirm RESOLVES A PROMISE. It has no onConfirm option and
				 * never had one, so this dialog used to confirm nothing: the
				 * member clicked Remove, the modal closed, and `go` was never
				 * called. Verified in the browser — the promise resolved true
				 * and the callback did not fire.
				 *
				 * The body text was passed as `body`, which the modal also
				 * ignores (it reads `message`), so the dialog asked the member
				 * to confirm with no explanation of what would happen. And the
				 * title and button were bare English literals, untranslated on
				 * a screen where every other string is.
				 *
				 * Three separate ways for a shared component's contract to be
				 * missed in nine lines. The contract is
				 * title / message / confirmLabel / cancelLabel / destructive,
				 * and it returns a Promise — see
				 * assets/js/shared/confirm-modal.js.
				 */
				window.lrnConfirm( {
					title: __( 'Remove photo?', 'learnomy' ),
					message: __( 'Your avatar will revert to the default.', 'learnomy' ),
					confirmLabel: __( 'Remove', 'learnomy' ),
					cancelLabel: __( 'Keep it', 'learnomy' ),
					destructive: true
				} ).then( function ( ok ) {
					if ( ok ) {
						go();
					}
				} );
			} else {
				go();
			}
		} );

		// Cache-bust a URL so the <img> repaints after a same-URL re-upload.
		function bust( u ) {
			return u + ( u.indexOf( '?' ) === -1 ? '?' : '&' ) + 'v=' + Date.now();
		}

		/**
		 * Build + open the crop modal for an image URL. Drag to pan, slider to
		 * zoom; the image always covers the square frame. Confirm exports the
		 * frame to a 512x512 blob and uploads it.
		 */
		function openCropper( imageUrl, cleanup ) {
			var img = new Image();
			img.onload = function () {
				mountModal( img );
			};
			img.onerror = function () {
				setStatus( __( 'That image could not be read.', 'learnomy' ), true );
				if ( cleanup ) {
					cleanup();
				}
			};
			img.src = imageUrl;

			function mountModal( image ) {
				var overlay = document.createElement( 'div' );
				overlay.className = 'lrn-avatar-cropper';
				overlay.setAttribute( 'role', 'dialog' );
				overlay.setAttribute( 'aria-modal', 'true' );
				overlay.setAttribute( 'aria-label', __( 'Crop your photo', 'learnomy' ) );
				overlay.innerHTML =
					'<div class="lrn-avatar-cropper__dialog">' +
						'<h3 class="lrn-avatar-cropper__title">' + __( 'Crop your photo', 'learnomy' ) + '</h3>' +
						'<div class="lrn-avatar-cropper__stage" data-stage>' +
							'<canvas class="lrn-avatar-cropper__canvas" width="' + VIEWPORT + '" height="' + VIEWPORT + '"></canvas>' +
							'<div class="lrn-avatar-cropper__ring" aria-hidden="true"></div>' +
						'</div>' +
						'<label class="lrn-avatar-cropper__zoom">' +
							'<span>' + __( 'Zoom', 'learnomy' ) + '</span>' +
							'<input type="range" min="1" max="3" step="0.01" value="1" data-zoom>' +
						'</label>' +
						'<div class="lrn-avatar-cropper__actions">' +
							'<button type="button" class="lrn-btn lrn-btn--ghost" data-cancel>' + __( 'Cancel', 'learnomy' ) + '</button>' +
							'<button type="button" class="lrn-btn lrn-btn--primary" data-save>' + __( 'Save photo', 'learnomy' ) + '</button>' +
						'</div>' +
					'</div>';
				document.body.appendChild( overlay );

				var canvas = overlay.querySelector( 'canvas' );
				var ctx = canvas.getContext( '2d' );
				var zoom = overlay.querySelector( '[data-zoom]' );
				var stage = overlay.querySelector( '[data-stage]' );

				var nw = image.naturalWidth;
				var nh = image.naturalHeight;
				var baseScale = VIEWPORT / Math.min( nw, nh ); // cover at zoom 1
				var z = 1;
				var offX = 0;
				var offY = 0;

				function clamp() {
					var w = nw * baseScale * z;
					var h = nh * baseScale * z;
					offX = Math.min( 0, Math.max( VIEWPORT - w, offX ) );
					offY = Math.min( 0, Math.max( VIEWPORT - h, offY ) );
				}

				function center() {
					var w = nw * baseScale * z;
					var h = nh * baseScale * z;
					offX = ( VIEWPORT - w ) / 2;
					offY = ( VIEWPORT - h ) / 2;
				}

				function draw() {
					clamp();
					ctx.clearRect( 0, 0, VIEWPORT, VIEWPORT );
					ctx.drawImage( image, offX, offY, nw * baseScale * z, nh * baseScale * z );
				}

				center();
				draw();

				zoom.addEventListener( 'input', function () {
					var prevZ = z;
					z = parseFloat( zoom.value ) || 1;
					// Zoom around the frame centre so it doesn't drift.
					var cx = VIEWPORT / 2;
					var cy = VIEWPORT / 2;
					offX = cx - ( ( cx - offX ) * ( z / prevZ ) );
					offY = cy - ( ( cy - offY ) * ( z / prevZ ) );
					draw();
				} );

				// Pointer drag to pan.
				var dragging = false;
				var lastX = 0;
				var lastY = 0;
				stage.addEventListener( 'pointerdown', function ( e ) {
					dragging = true;
					lastX = e.clientX;
					lastY = e.clientY;
					stage.setPointerCapture( e.pointerId );
				} );
				stage.addEventListener( 'pointermove', function ( e ) {
					if ( ! dragging ) {
						return;
					}
					offX += e.clientX - lastX;
					offY += e.clientY - lastY;
					lastX = e.clientX;
					lastY = e.clientY;
					draw();
				} );
				function endDrag() {
					dragging = false;
				}
				stage.addEventListener( 'pointerup', endDrag );
				stage.addEventListener( 'pointercancel', endDrag );

				function close() {
					overlay.remove();
					if ( cleanup ) {
						cleanup();
					}
				}

				overlay.querySelector( '[data-cancel]' ).addEventListener( 'click', close );
				overlay.addEventListener( 'click', function ( e ) {
					if ( e.target === overlay ) {
						close();
					}
				} );
				document.addEventListener( 'keydown', function escClose( e ) {
					if ( 'Escape' === e.key ) {
						document.removeEventListener( 'keydown', escClose );
						close();
					}
				} );

				overlay.querySelector( '[data-save]' ).addEventListener( 'click', function () {
					// Map the visible frame back to source pixels and render the
					// square at full OUTPUT resolution.
					var scale = baseScale * z;
					var srcX = ( 0 - offX ) / scale;
					var srcY = ( 0 - offY ) / scale;
					var srcSize = VIEWPORT / scale;

					var out = document.createElement( 'canvas' );
					out.width = OUTPUT;
					out.height = OUTPUT;
					var octx = out.getContext( '2d' );
					octx.imageSmoothingQuality = 'high';
					octx.drawImage( image, srcX, srcY, srcSize, srcSize, 0, 0, OUTPUT, OUTPUT );

					var saveBtn = overlay.querySelector( '[data-save]' );
					saveBtn.disabled = true;
					saveBtn.textContent = __( 'Uploading…', 'learnomy' );

					out.toBlob( function ( blob ) {
						if ( ! blob ) {
							saveBtn.disabled = false;
							saveBtn.textContent = __( 'Save photo', 'learnomy' );
							setStatus( __( 'Could not process the image.', 'learnomy' ), true );
							return;
						}
						var fd = new FormData();
						fd.append( 'avatar', blob, 'avatar.png' );
						fetch( endpoint, {
							method: 'POST',
							credentials: 'same-origin',
							headers: { 'X-WP-Nonce': nonce },
							body: fd
						} ).then( function ( r ) {
							return r.json().then( function ( body ) {
								return { ok: r.ok, body: body };
							} );
						} ).then( function ( res ) {
							if ( ! res.ok ) {
								saveBtn.disabled = false;
								saveBtn.textContent = __( 'Save photo', 'learnomy' );
								setStatus( ( res.body && res.body.error && res.body.error.message ) || __( 'Upload failed.', 'learnomy' ), true );
								return;
							}
							var newUrl = res.body && res.body.data && res.body.data.avatar_url;
							if ( newUrl && preview ) {
								preview.src = bust( newUrl );
							}
							if ( removeBtn ) {
								removeBtn.disabled = false;
							}
							setStatus( '' );
							toast( __( 'Photo updated.', 'learnomy' ), 'success' );
							close();
						} ).catch( function () {
							saveBtn.disabled = false;
							saveBtn.textContent = __( 'Save photo', 'learnomy' );
							setStatus( __( 'Network error. Please try again.', 'learnomy' ), true );
						} );
					}, 'image/png' );
				} );
			}
		}
	} );
}() );
