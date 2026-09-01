/**
 * Learnomy — Memberships admin scripts.
 *
 * Handles three pages:
 *   - Plans list (learnomy-memberships): bulk actions, row delete, status toggle.
 *   - Plan editor (learnomy-membership-edit): slug auto-gen, pricing-mode toggle,
 *     features repeater, course search filter, media picker, AJAX save.
 *   - Subscriptions list (learnomy-subscriptions): admin cancel + extend modal.
 *
 * ES5 style to match the rest of the admin JS. Reads config from
 * window.learnomyAdmin which is localised by Admin::enqueue_assets().
 *
 * @package Learnomy
 */
( function () {
	'use strict';

	var __ = wp.i18n.__;

	var cfg       = window.learnomyAdmin || {};
	var restUrl   = cfg.restUrl || '/wp-json/learnomy/v1/';
	var restNonce = cfg.restNonce || '';

	// -------------------------------------------------------------------------
	// Shared helpers
	// -------------------------------------------------------------------------

	/**
	 * Send a REST request with cookie auth + X-WP-Nonce.
	 *
	 * @param {string}  method HTTP verb (GET/POST/PUT/PATCH/DELETE).
	 * @param {string}  path   Path relative to restUrl (e.g. "membership-plans/12").
	 * @param {?object} body   JSON body. Skipped for GET/DELETE.
	 * @return {Promise<object>} Resolves with the response's `data` envelope.
	 */
	function restCall( method, path, body ) {
		var opts = {
			method:      method,
			credentials: 'same-origin',
			headers:     { 'X-WP-Nonce': restNonce },
		};
		if ( body && method !== 'GET' && method !== 'DELETE' ) {
			opts.headers[ 'Content-Type' ] = 'application/json';
			opts.body = JSON.stringify( body );
		}

		return fetch( restUrl + path, opts ).then( function ( response ) {
			return response.json().then( function ( payload ) {
				if ( ! response.ok ) {
					var msg = ( payload && payload.error && payload.error.message )
						|| ( payload && payload.message )
						|| __( 'Request failed.', 'learnomy' );
					throw new Error( msg );
				}
				// Base_Controller wraps single resources as { data: ... }; pass that through.
				return payload && Object.prototype.hasOwnProperty.call( payload, 'data' )
					? payload.data
					: ( payload || {} );
			} );
		} );
	}

	function toast( type, message ) {
		if ( window.lrnToast ) {
			// Canonical signature is lrnToast( message, type, duration ).
			window.lrnToast( message, type );
		}
	}

	// Queued for the page we are about to reload into — see `lrnToast.flash()`.
	// Keeps this file's reversed ( type, message ) order on purpose so the call
	// sites below convert without their arguments being resequenced.
	toast.flash = function ( type, message ) {
		if ( window.lrnToast ) {
			window.lrnToast.flash( message, type );
		}
	};

	/**
	 * Inline message with an Undo button. No-op when shared/toast.js hasn't
	 * loaded yet — saves us from breaking older pages during asset migrations.
	 */
	function undoToast( message, onUndo, timeout ) {
		if ( window.lrnUndo ) {
			window.lrnUndo( message, onUndo, timeout );
		} else {
			toast( 'success', message );
		}
	}

	function confirmDialog( options ) {
		if ( window.lrnConfirm ) {
			return window.lrnConfirm( options );
		}
		return Promise.resolve( true );
	}

	// -------------------------------------------------------------------------
	// Plans list page
	// -------------------------------------------------------------------------

	function initPlansList() {
		var table = document.getElementById( 'lrn-memberships-table' );
		if ( ! table ) {
			return;
		}

		// Row delete action.
		table.querySelectorAll( '.lrn-delete-plan' ).forEach( function ( link ) {
			link.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var row    = link.closest( 'tr' );
				var planId = row && row.getAttribute( 'data-plan-id' );
				if ( ! planId ) {
					return;
				}
				confirmDialog( {
					title:        __( 'Delete membership plan', 'learnomy' ),
					message:      __( 'Plans with active subscribers will be archived instead. Continue?', 'learnomy' ),
					confirmLabel: __( 'Delete', 'learnomy' ),
					destructive:  true
				} ).then( function ( ok ) {
					if ( ! ok ) { return; }
					restCall( 'DELETE', 'membership-plans/' + encodeURIComponent( planId ) )
						.then( function ( data ) {
							// Archive path (plan had subscribers) is reversible
							// via the status-toggle endpoint — offer Undo. Hard
							// delete (no subs) is gone for good, so plain toast.
							var wasArchived = data && data.archived;
							if ( row && row.parentNode ) {
								row.parentNode.removeChild( row );
							}
							if ( wasArchived ) {
								undoToast( 'Plan archived.', function () {
									restCall( 'PATCH', 'membership-plans/' + encodeURIComponent( planId ) + '/status', { status: 'active' } )
										.then( function () { window.location.reload(); } )
										.catch( function ( err ) { toast( 'error', err.message ); } );
								} );
							} else {
								toast( 'success', __( 'Plan removed.', 'learnomy' ) );
							}
						} )
						.catch( function ( err ) { toast( 'error', err.message ); } );
				} );
			} );
		} );

		// Inline status toggle (click the badge).
		table.querySelectorAll( '.lrn-toggle-status' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var row    = btn.closest( 'tr' );
				var planId = row && row.getAttribute( 'data-plan-id' );
				var now    = btn.getAttribute( 'data-current' );
				var next   = now === 'active' ? 'inactive' : 'active';
				restCall( 'PATCH', 'membership-plans/' + encodeURIComponent( planId ) + '/status', { status: next } )
					.then( function () {
						btn.setAttribute( 'data-current', next );
						btn.classList.remove( 'lrn-status-badge--active', 'lrn-status-badge--inactive' );
						btn.classList.add( 'lrn-status-badge--' + next );
						btn.textContent = next === 'active' ? __( 'Active', 'learnomy' ) : __( 'Inactive', 'learnomy' );
						toast( 'success', next === 'active' ? __( 'Plan activated.', 'learnomy' ) : __( 'Plan deactivated.', 'learnomy' ) );
					} )
					.catch( function ( err ) { toast( 'error', err.message ); } );
			} );
		} );

		// Bulk actions — hook the list-page.js event.
		document.addEventListener( 'lrn:bulk-action', function ( e ) {
			var detail = e.detail;
			if ( ! detail || ! detail.ids || ! detail.ids.length ) { return; }

			var statusMap = { activate: 'active', deactivate: 'inactive' };
			var action    = detail.action;
			var promises  = [];

			detail.ids.forEach( function ( id ) {
				if ( action === 'delete' ) {
					promises.push( restCall( 'DELETE', 'membership-plans/' + encodeURIComponent( id ) ) );
				} else if ( statusMap[ action ] ) {
					promises.push(
						restCall(
							'PATCH',
							'membership-plans/' + encodeURIComponent( id ) + '/status',
							{ status: statusMap[ action ] }
						)
					);
				}
			} );

			Promise.all( promises )
				.then( function () {
					toast.flash( 'success', __( 'Bulk action complete.', 'learnomy' ) );
					window.location.reload();
				} )
				.catch( function ( err ) { toast( 'error', err.message ); } );
		} );
	}

	// -------------------------------------------------------------------------
	// Plan editor page
	// -------------------------------------------------------------------------

	function slugify( input ) {
		return ( input || '' )
			.toString()
			.toLowerCase()
			.replace( /[^a-z0-9]+/g, '-' )
			.replace( /^-+|-+$/g, '' );
	}

	/**
	 * Build one features-repeater row via DOM APIs — no innerHTML so user-
	 * supplied strings are never concatenated into markup.
	 */
	function buildFeatureRow() {
		var row = document.createElement( 'div' );
		row.className = 'lrn-features-row';

		var input = document.createElement( 'input' );
		input.type        = 'text';
		input.name        = 'features[]';
		input.className   = 'regular-text';
		input.placeholder = __( 'e.g. Priority support', 'learnomy' );
		row.appendChild( input );

		var btn = document.createElement( 'button' );
		btn.type      = 'button';
		btn.className = 'lrn-admin-btn lrn-admin-btn--ghost lrn-features-remove';
		btn.setAttribute( 'aria-label', __( 'Remove feature', 'learnomy' ) );

		var icon = document.createElement( 'i' );
		icon.setAttribute( 'data-lucide', 'trash-2' );
		icon.setAttribute( 'aria-hidden', 'true' );
		btn.appendChild( icon );

		row.appendChild( btn );
		return row;
	}

	function initPlanEditor() {
		var form = document.getElementById( 'lrn-plan-form' );
		if ( ! form ) {
			return;
		}

		// Slug auto-generate on title blur when slug is empty.
		var title = form.querySelector( '#lrn-plan-title' );
		var slug  = form.querySelector( '#lrn-plan-slug' );
		if ( title && slug ) {
			title.addEventListener( 'blur', function () {
				if ( slug.value.trim() === '' ) {
					slug.value = slugify( title.value );
				}
			} );
		}

		// Pricing mode toggle.
		function refreshPricingMode() {
			var mode = form.querySelector( 'input[name="pricing_mode"]:checked' );
			mode     = mode ? mode.value : 'single';
			form.querySelectorAll( '[data-pricing-mode]' ).forEach( function ( el ) {
				el.hidden = ( el.getAttribute( 'data-pricing-mode' ) !== mode );
			} );
		}
		form.querySelectorAll( 'input[name="pricing_mode"]' ).forEach( function ( radio ) {
			radio.addEventListener( 'change', refreshPricingMode );
		} );
		refreshPricingMode();

		// Features repeater.
		var featuresList = document.getElementById( 'lrn-features-list' );
		var featuresAdd  = document.getElementById( 'lrn-features-add' );
		if ( featuresList && featuresAdd ) {
			featuresAdd.addEventListener( 'click', function () {
				// Was a SCOPED conversion ({ nodes: [ row ] }). lucide-live.js
				// converts only what is still unconverted on each pass, so the
				// scoping bought nothing the seam does not already do
				// (Basecamp 10184590411).
				var row = buildFeatureRow();
				featuresList.appendChild( row );
			} );

			featuresList.addEventListener( 'click', function ( e ) {
				var btn = e.target.closest( '.lrn-features-remove' );
				if ( ! btn ) { return; }
				var row = btn.closest( '.lrn-features-row' );
				if ( row && featuresList.children.length > 1 ) {
					row.parentNode.removeChild( row );
				} else if ( row ) {
					// Always keep at least one empty row.
					var input = row.querySelector( 'input' );
					if ( input ) { input.value = ''; }
				}
			} );
		}

		// Course search filter (client-side for already-loaded courses).
		var coursesSearch = document.getElementById( 'lrn-courses-search' );
		var coursesPicker = document.getElementById( 'lrn-courses-picker' );
		if ( coursesSearch && coursesPicker ) {
			coursesSearch.addEventListener( 'input', function () {
				var q = coursesSearch.value.toLowerCase().trim();
				coursesPicker.querySelectorAll( '.lrn-courses-picker__row' ).forEach( function ( row ) {
					var title = row.getAttribute( 'data-title' ) || '';
					row.hidden = ( q !== '' && title.indexOf( q ) === -1 );
				} );
			} );
		}

		// Media picker — hooks wp.media when available.
		var pickBtn    = document.getElementById( 'lrn-plan-thumbnail-pick' );
		var thumbInput = document.getElementById( 'lrn-plan-thumbnail' );
		if ( pickBtn && thumbInput && window.wp && window.wp.media ) {
			var frame;
			pickBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				if ( ! frame ) {
					frame = window.wp.media( {
						title:    __( 'Choose plan thumbnail', 'learnomy' ),
						button:   { text: __( 'Use this image', 'learnomy' ) },
						multiple: false
					} );
					frame.on( 'select', function () {
						var attachment = frame.state().get( 'selection' ).first().toJSON();
						thumbInput.value = attachment.url;
					} );
				}
				frame.open();
			} );
		}

		// Save handlers — intercept submit and the Save-as-Draft button.
		var draftBtn = document.getElementById( 'lrn-plan-save-draft' );
		if ( draftBtn ) {
			draftBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var statusSelect = form.querySelector( '#lrn-plan-status' );
				if ( statusSelect ) { statusSelect.value = 'inactive'; }
				submitPlan( form );
			} );
		}
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			submitPlan( form );
		} );
	}

	function submitPlan( form ) {
		var payload = {};
		var planId  = parseInt( form.getAttribute( 'data-plan-id' ), 10 ) || 0;

		var fields = form.querySelectorAll( 'input, select, textarea' );
		fields.forEach( function ( field ) {
			if ( ! field.name ) { return; }
			if ( field.name === 'lrn_nonce' ) { return; }

			if ( field.type === 'checkbox' ) {
				if ( field.name === 'course_ids[]' ) {
					if ( field.checked ) {
						payload.course_ids = payload.course_ids || [];
						payload.course_ids.push( parseInt( field.value, 10 ) || 0 );
					}
					return;
				}
				if ( field.name === 'features[]' ) { return; }
				if ( field.checked ) {
					payload[ field.name ] = field.value || '1';
				}
				return;
			}
			if ( field.type === 'radio' ) {
				if ( field.checked ) { payload[ field.name ] = field.value; }
				return;
			}
			if ( field.name === 'features[]' ) {
				payload.features = payload.features || [];
				if ( field.value.trim() !== '' ) {
					payload.features.push( field.value );
				}
				return;
			}
			if ( field.name.indexOf( '[]' ) !== -1 ) { return; }
			payload[ field.name ] = field.value;
		} );

		if ( ! payload.title || payload.title.trim() === '' ) {
			toast( 'error', __( 'Plan title is required.', 'learnomy' ) );
			return;
		}

		// REST normalisation:
		// - features[] → features_json (REST stores as JSON string column).
		// - pricing_mode is a JS-only UI state; collapse it into price/price_monthly/price_yearly.
		// - is_featured arrives as "1" / "0" string from the checkbox sentinel — coerce to bool.
		payload.features_json = JSON.stringify( payload.features || [] );
		delete payload.features;

		var pricingMode = payload.pricing_mode;
		delete payload.pricing_mode;
		if ( pricingMode === 'dual' ) {
			payload.price_monthly = parseFloat( payload.price_monthly ) || 0;
			payload.price_yearly  = parseFloat( payload.price_yearly ) || 0;
			payload.price         = payload.price_monthly;
		} else {
			payload.price         = parseFloat( payload.price ) || 0;
			payload.price_monthly = null;
			payload.price_yearly  = null;
		}

		payload.is_featured    = !! payload.is_featured && payload.is_featured !== '0';
		payload.interval_count = parseInt( payload.interval_count, 10 ) || 1;
		payload.trial_days     = parseInt( payload.trial_days, 10 ) || 0;
		payload.course_limit   = ( payload.course_limit === '' || payload.course_limit === undefined )
			? null
			: ( parseInt( payload.course_limit, 10 ) || null );

		var saveBtn = document.getElementById( 'lrn-plan-save' );
		if ( saveBtn ) { saveBtn.disabled = true; }

		var method = planId > 0 ? 'PUT' : 'POST';
		var path   = planId > 0 ? 'membership-plans/' + planId : 'membership-plans';

		restCall( method, path, payload )
			.then( function ( data ) {
				toast.flash( 'success', planId > 0 ? __( 'Plan updated.', 'learnomy' ) : __( 'Plan created.', 'learnomy' ) );
				if ( saveBtn ) { saveBtn.disabled = false; }

				// New plan? Drop the user on the editor for the freshly-created
				// row so subsequent saves are updates (matches the legacy AJAX
				// redirect semantics — single round-trip to "edit mode").
				if ( planId === 0 && data && data.id ) {
					window.location.href = 'admin.php?page=learnomy-membership-edit&plan_id=' + data.id + '&saved=1';
				}
			} )
			.catch( function ( err ) {
				if ( saveBtn ) { saveBtn.disabled = false; }
				toast( 'error', err.message );
			} );
	}

	// -------------------------------------------------------------------------
	// Subscriptions list page
	// -------------------------------------------------------------------------

	function initSubscriptionsList() {
		var cancelLinks = document.querySelectorAll( '.lrn-sub-cancel' );
		cancelLinks.forEach( function ( link ) {
			link.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var subId = link.getAttribute( 'data-sub-id' );
				if ( ! subId ) { return; }
				confirmDialog( {
					title:        __( 'Cancel subscription', 'learnomy' ),
					message:      __( 'This revokes access immediately. Continue?', 'learnomy' ),
					confirmLabel: __( 'Cancel subscription', 'learnomy' ),
					destructive:  true
				} ).then( function ( ok ) {
					if ( ! ok ) { return; }
					restCall( 'POST', 'admin/memberships/' + encodeURIComponent( subId ) + '/cancel' )
						.then( function () {
							toast.flash( 'success', __( 'Subscription cancelled immediately.', 'learnomy' ) );
							window.location.reload();
						} )
						.catch( function ( err ) { toast( 'error', err.message ); } );
				} );
			} );
		} );

		var modal       = document.getElementById( 'lrn-extend-modal' );
		var dateInput   = document.getElementById( 'lrn-extend-date' );
		var extendBtn   = document.getElementById( 'lrn-extend-confirm' );
		var cancelBtn   = document.getElementById( 'lrn-extend-cancel' );
		var currentSub  = 0;

		if ( modal && dateInput && extendBtn && cancelBtn ) {
			document.querySelectorAll( '.lrn-sub-extend' ).forEach( function ( link ) {
				link.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					currentSub = parseInt( link.getAttribute( 'data-sub-id' ), 10 ) || 0;
					var currentEnd = link.getAttribute( 'data-period-end' );
					if ( currentEnd ) {
						dateInput.value = currentEnd.substr( 0, 10 );
					}
					modal.classList.remove( 'lrn-modal-backdrop--hidden' );
				} );
			} );

			cancelBtn.addEventListener( 'click', function () {
				modal.classList.add( 'lrn-modal-backdrop--hidden' );
			} );

			extendBtn.addEventListener( 'click', function () {
				if ( ! currentSub || ! dateInput.value ) {
					toast( 'error', __( 'Pick a date first.', 'learnomy' ) );
					return;
				}
				restCall(
					'POST',
					'admin/memberships/' + encodeURIComponent( currentSub ) + '/extend',
					{ period_end: dateInput.value + ' 23:59:59' }
				)
					.then( function () {
						modal.classList.add( 'lrn-modal-backdrop--hidden' );
						toast.flash( 'success', __( 'Subscription extended.', 'learnomy' ) );
						window.location.reload();
					} )
					.catch( function ( err ) { toast( 'error', err.message ); } );
			} );
		}
	}

	// -------------------------------------------------------------------------
	// Init
	// -------------------------------------------------------------------------

	document.addEventListener( 'DOMContentLoaded', function () {
		initPlansList();
		initPlanEditor();
		initSubscriptionsList();
	} );
} )();
