/**
 * Setup wizard interaction logic — multi-step form, server-state polling,
 * cohort generation, confetti on completion.
 *
 * Extracted from includes/admin/views/setup-wizard.php per ux-foundation
 * rule F2. The wizard renders its own <html>/<head>/<body> via
 * `maybe_render_setup_wizard()` (admin_init exit path) so this file is
 * loaded via a literal <script src="…"> in the wizard PHP — same pattern
 * as the vendored lucide + confetti scripts the wizard relies on.
 *
 * @package Learnomy
 */
( function () {
	'use strict';

	var dataEl = document.getElementById( 'lrn-wizard-data' );
	var bootData = dataEl ? JSON.parse( dataEl.textContent ) : {};
	var i18n = bootData.i18n || {};

	var wizardState = {
		currentStep:     1,
		isRerun:         !! bootData.isRerun,
		demoLocked:      !! bootData.demoLocked,
		demoChoice:      'demo',
		paymentMode:     bootData.paymentMode || 'sandbox',
		preset:          'standard',
		homeUrl:         bootData.homeUrl || '',
		baseSlug:        bootData.baseSlug || 'courses',
		restUrl:         bootData.restUrl || '',
		restNonce:       bootData.restNonce || '',
		dashboardUrl:    bootData.dashboardUrl || '',
		createCourseUrl: bootData.createCourseUrl || ''
	};

	var chips  = document.querySelectorAll( '[data-chip]' );
	var steps  = document.querySelectorAll( '.lrn-step' );
	var notice = document.getElementById( 'lrn-notice' );


	function clearChildren( el ) {
		while ( el.firstChild ) { el.removeChild( el.firstChild ); }
	}

	function showNotice( msg, opts ) {
		opts = opts || {};
		clearChildren( notice );

		var text = document.createElement( 'div' );
		text.textContent = msg;
		notice.appendChild( text );

		if ( opts.actions ) {
			var wrap = document.createElement( 'div' );
			wrap.className = 'lrn-notice__actions';
			opts.actions.forEach( function ( act ) {
				var b = document.createElement( 'button' );
				b.type = 'button';
				b.textContent = act.label;
				b.addEventListener( 'click', act.onClick );
				wrap.appendChild( b );
			} );
			notice.appendChild( wrap );
		}

		notice.classList.add( 'is-error' );
		notice.scrollIntoView( { behavior: 'smooth', block: 'center' } );
	}

	function clearNotice() {
		clearChildren( notice );
		notice.classList.remove( 'is-error' );
	}

	/**
	 * Issue a REST request against the Learnomy admin namespace and invoke
	 * the callback with a legacy-shaped envelope: { success, data }. The
	 * existing call sites consume r.success, r.data.message, r.data.stats,
	 * r.data.previews — so we translate the REST response shape here once
	 * instead of touching every step's onResponse handler.
	 */
	function restCall( method, path, body, cb ) {
		var opts = {
			method: method,
			credentials: 'same-origin',
			headers: { 'Accept': 'application/json', 'X-WP-Nonce': wizardState.restNonce }
		};

		if ( body !== null && body !== undefined ) {
			opts.headers[ 'Content-Type' ] = 'application/json';
			opts.body = JSON.stringify( body );
		}

		fetch( wizardState.restUrl + path, opts )
			.then( function ( r ) {
				return r.json().then( function ( j ) {
					if ( r.ok ) {
						// REST returns { data: ... }. Reshape to the legacy
						// envelope the call sites already understand.
						var payload = ( j && typeof j === 'object' && j.hasOwnProperty( 'data' ) ) ? j.data : j;
						cb( { success: true, data: payload || {} } );
						return;
					}

					var message = ( j && j.error && j.error.message )
						? j.error.message
						: ( ( j && j.message ) ? j.message : i18n.requestFailed );
					cb( { success: false, data: { message: message } } );
				} );
			} )
			.catch( function () {
				cb( { success: false, data: { message: i18n.networkError } } );
			} );
	}

	function setLoading( btn, loading ) {
		if ( ! btn ) return;
		btn.disabled = loading;
		if ( loading ) { btn.classList.add( 'is-loading' ); }
		else           { btn.classList.remove( 'is-loading' ); }
	}

	function renderChips() {
		chips.forEach( function ( chip ) {
			var n = parseInt( chip.dataset.chip, 10 );
			chip.classList.remove( 'is-past', 'is-current' );
			if ( n < wizardState.currentStep ) {
				chip.classList.add( 'is-past' );
			} else if ( n === wizardState.currentStep ) {
				chip.classList.add( 'is-current' );
			}
		} );
	}

	chips.forEach( function ( chip ) {
		chip.addEventListener( 'click', function () {
			var n = parseInt( chip.dataset.chip, 10 );
			if ( n < wizardState.currentStep ) { goToStep( n ); }
		} );
	} );

	function goToStep( n ) {
		clearNotice();
		wizardState.currentStep = n;
		steps.forEach( function ( s ) { s.classList.remove( 'is-active' ); } );
		var active = document.querySelector( '.lrn-step[data-step="' + n + '"]' );
		if ( active ) { active.classList.add( 'is-active' ); }
		renderChips();
		if ( n === 5 ) { fireConfetti(); }
	}

	document.querySelectorAll( '[data-go]' ).forEach( function ( el ) {
		el.addEventListener( 'click', function () {
			var n = parseInt( el.dataset.go, 10 );
			if ( ! isNaN( n ) ) { goToStep( n ); }
		} );
	} );

	document.getElementById( 'lrn-skip-btn' ).addEventListener( 'click', async function () {
		var ok = await window.lrnConfirm( { message: i18n.confirmSkipWizard } );
		if ( ! ok ) return;
		restCall( 'PATCH', 'admin/setup-state', collectBasics(), function () {
			window.location.href = wizardState.dashboardUrl;
		} );
	} );

	var slugInput   = document.getElementById( 'lrn-base-slug' );
	var slugPreview = document.getElementById( 'lrn-slug-preview' );
	var slugField   = document.getElementById( 'lrn-field-slug' );

	slugInput.addEventListener( 'input', function () {
		var v = slugInput.value.trim();
		slugPreview.textContent = wizardState.homeUrl + ( v || 'courses' ) + '/';
		if ( v && /[^a-z0-9\-]/.test( v ) ) {
			slugField.classList.add( 'is-invalid' );
		} else {
			slugField.classList.remove( 'is-invalid' );
		}
	} );

	function wireRadioGroup( name, onChange ) {
		var group = document.querySelector( '[data-radio="' + name + '"]' );
		if ( ! group ) return;
		var tiles = group.querySelectorAll( '.lrn-tile' );
		tiles.forEach( function ( tile ) {
			function select() {
				if ( tile.classList.contains( 'is-locked' ) ) return;
				tiles.forEach( function ( t ) {
					t.classList.remove( 'is-selected' );
					t.setAttribute( 'aria-checked', 'false' );
				} );
				tile.classList.add( 'is-selected' );
				tile.setAttribute( 'aria-checked', 'true' );
				if ( onChange ) { onChange( tile.dataset.value ); }
			}
			tile.addEventListener( 'click', select );
			tile.addEventListener( 'keydown', function ( e ) {
				if ( e.key === ' ' || e.key === 'Enter' ) {
					e.preventDefault();
					select();
				}
			} );
		} );
	}

	// Enrollment-mode tiles removed until the approval feature is built
	// (half-cooked audit F1) — no radio group to wire.
	wireRadioGroup( 'payment', function ( v ) {
		wizardState.paymentMode = v;
		document.getElementById( 'lrn-stripe-fields' ).style.display = ( v === 'stripe_test' ) ? 'block' : 'none';
	} );
	wireRadioGroup( 'demo', function ( v ) { wizardState.demoChoice = v; } );
	wireRadioGroup( 'preset', function ( v ) { wizardState.preset = v; } );

	function collectBasics() {
		return {
			base_slug: slugInput.value.trim() || 'courses',
			currency:  document.getElementById( 'lrn-currency' ).value,
			preset:    wizardState.preset
		};
	}

	document.getElementById( 'lrn-step2-next' ).addEventListener( 'click', function () {
		clearNotice();
		var v = slugInput.value.trim();
		if ( v && /[^a-z0-9\-]/.test( v ) ) {
			slugField.classList.add( 'is-invalid' );
			slugInput.focus();
			return;
		}
		var btn = this;
		setLoading( btn, true );
		restCall( 'PATCH', 'admin/setup-state', collectBasics(), function ( r ) {
			setLoading( btn, false );
			if ( ! r.success ) {
				showNotice( ( r.data && r.data.message ) || i18n.saveSettingsFailed );
				return;
			}
			wizardState.baseSlug = slugInput.value.trim() || 'courses';

			// Always walk Welcome → Configure → Payment → Demo → Success in
			// strict order. Even free-only academies must see the Payment
			// step so the operator knows the option exists; the step itself
			// offers a "Skip for now" tile (`deferred` mode) which is the
			// proper escape hatch. Auto-skipping the screen wholesale was
			// the SS-4 bug in the 2026-05-16 customer-journey walk — the
			// wizard "completed" without ever prompting for payment config.
			goToStep( 3 );
		} );
	} );

	var pkInput = document.getElementById( 'lrn-stripe-pk' );
	var skInput = document.getElementById( 'lrn-stripe-sk' );
	var pkField = document.getElementById( 'lrn-field-pk' );
	var skField = document.getElementById( 'lrn-field-sk' );

	function validateStripeBlur( input, field, prefix ) {
		input.addEventListener( 'blur', function () {
			var v = input.value.trim();
			if ( v && v.indexOf( prefix ) !== 0 ) {
				field.classList.add( 'is-invalid' );
			} else {
				field.classList.remove( 'is-invalid' );
			}
		} );
	}

	validateStripeBlur( pkInput, pkField, 'pk_test_' );
	validateStripeBlur( skInput, skField, 'sk_test_' );

	document.getElementById( 'lrn-step3-next' ).addEventListener( 'click', function () {
		clearNotice();
		var mode = wizardState.paymentMode;
		var payload = { payment_mode: mode };

		if ( mode === 'stripe_test' ) {
			var pk = pkInput.value.trim();
			var sk = skInput.value.trim();
			var bad = false;
			if ( pk.indexOf( 'pk_test_' ) !== 0 ) { pkField.classList.add( 'is-invalid' ); bad = true; }
			if ( sk.indexOf( 'sk_test_' ) !== 0 ) { skField.classList.add( 'is-invalid' ); bad = true; }
			if ( bad ) return;
			payload.stripe_test_publishable_key = pk;
			payload.stripe_test_secret_key      = sk;
		}

		var btn = this;
		setLoading( btn, true );
		restCall( 'POST', 'admin/setup-state/payment', payload, function ( r ) {
			setLoading( btn, false );
			if ( ! r.success ) {
				showNotice( ( r.data && r.data.message ) || i18n.savePaymentFailed );
				return;
			}
			goToStep( 4 );
		} );
	} );

	var finishBtn    = document.getElementById( 'lrn-finish-btn' );
	var step4Actions = document.getElementById( 'lrn-step4-actions' );
	var choiceWrap   = document.getElementById( 'lrn-demo-choice-wrap' );
	var loadingPanel = document.getElementById( 'lrn-loading' );

	function revealChecklist() {
		var items = loadingPanel.querySelectorAll( '.lrn-loading__item' );
		items.forEach( function ( item, idx ) {
			setTimeout( function () { item.classList.add( 'is-done' ); }, 500 + idx * 600 );
		} );
	}

	function handleSeederResponse( r ) {
		if ( ! r.success ) {
			loadingPanel.style.display = 'none';
			choiceWrap.style.display = '';
			step4Actions.style.display = '';
			showNotice(
				( r.data && r.data.message ) || i18n.demoSeedFailed,
				{
					actions: [
						{ label: i18n.retry, onClick: function () { finishBtn.click(); } },
						{ label: i18n.skipDemo, onClick: function () {
							wizardState.demoChoice = 'empty';
							finishBtn.click();
						} }
					]
				}
			);
			return;
		}

		var stats    = ( r.data && r.data.stats ) || {};
		var previews = ( r.data && r.data.previews ) || [];
		renderSuccess( stats, previews, true );
		goToStep( 5 );
	}

	finishBtn.addEventListener( 'click', function () {
		clearNotice();

		if ( wizardState.demoLocked || wizardState.demoChoice === 'empty' ) {
			renderSuccess( {}, [], false );
			goToStep( 5 );
			return;
		}

		choiceWrap.style.display   = 'none';
		step4Actions.style.display = 'none';
		loadingPanel.style.display = 'block';
		chips.forEach( function ( c ) { c.disabled = true; } );
		revealChecklist();

		restCall( 'POST', 'admin/setup-state/sample', collectBasics(), function ( r ) {
			chips.forEach( function ( c ) { c.disabled = false; } );
			handleSeederResponse( r );
		} );
	} );

	function animateCountUp( el, target, isCurrency ) {
		var duration = 900;
		var start    = performance.now();
		function tick( t ) {
			var progress = Math.min( 1, ( t - start ) / duration );
			var eased    = 1 - Math.pow( 1 - progress, 3 );
			var value    = target * eased;
			el.textContent = isCurrency ? ( '$' + value.toFixed( 0 ) ) : Math.round( value ).toString();
			if ( progress < 1 ) {
				requestAnimationFrame( tick );
			} else if ( isCurrency ) {
				el.textContent = '$' + ( Math.round( target * 100 ) / 100 ).toFixed( 2 );
			}
		}
		requestAnimationFrame( tick );
	}

	/**
	 * Reveal the post-setup "next steps" signposts that match the chosen
	 * preset. A preset can enable a module (quizzes, membership, coupons) yet
	 * seed no content, so we point the owner straight at the create screen
	 * instead of leaving them on a bare empty list. The preset → signpost-keys
	 * map is computed server-side from each preset's own module list.
	 */
	function renderNextSteps() {
		var wrap = document.getElementById( 'lrn-next' );
		if ( ! wrap ) return;

		var map     = bootData.presetSignposts || {};
		var allowed = map[ wizardState.preset ] || [ 'course' ];

		wrap.querySelectorAll( '.lrn-next__item' ).forEach( function ( item ) {
			item.hidden = ( allowed.indexOf( item.getAttribute( 'data-signpost' ) ) === -1 );
		} );

		wrap.hidden = false;
	}

	function renderSuccess( stats, previews, seeded ) {
		var headline   = document.getElementById( 'lrn-success-headline' );
		var lede       = document.getElementById( 'lrn-success-lede' );
		var statsEl    = document.getElementById( 'lrn-stats' );
		var prevEl     = document.getElementById( 'lrn-previews' );
		var openBtn    = document.getElementById( 'lrn-open-dashboard' );
		var footer     = document.getElementById( 'lrn-reset-demo-footer' );

		renderNextSteps();

		if ( ! seeded ) {
			headline.textContent  = i18n.successHeadlineEmpty;
			lede.textContent      = '';
			statsEl.style.display = 'none';
			prevEl.style.display  = 'none';
			openBtn.textContent   = i18n.createFirstCourse;
			openBtn.setAttribute( 'href', wizardState.createCourseUrl );
			if ( footer ) { footer.parentNode.style.display = 'none'; }
			return;
		}

		var targets = {
			courses:      parseInt( stats.courses || 0, 10 ),
			students:     parseInt( stats.students || 0, 10 ),
			revenue:      parseFloat( stats.revenue || 0 ),
			certificates: parseInt( stats.certificates || 0, 10 )
		};
		Object.keys( targets ).forEach( function ( key ) {
			var el = statsEl.querySelector( '[data-stat="' + key + '"]' );
			if ( el ) {
				animateCountUp( el, targets[ key ], key === 'revenue' );
			}
		} );

		clearChildren( prevEl );
		( previews || [] ).forEach( function ( p ) {
			var a = document.createElement( 'a' );
			a.className = 'lrn-preview-card';
			a.href      = wizardState.homeUrl + wizardState.baseSlug + '/' + ( p.slug || '' ) + '/';
			a.target    = '_blank';
			a.rel       = 'noopener';

			var img = document.createElement( 'div' );
			img.className = 'lrn-preview-card__img';
			if ( p.thumbnail ) {
				img.style.backgroundImage    = "url('" + p.thumbnail + "')";
				img.style.backgroundSize     = 'cover';
				img.style.backgroundPosition = 'center';
			}
			a.appendChild( img );

			var body = document.createElement( 'div' );
			body.className = 'lrn-preview-card__body';
			var t = document.createElement( 'div' );
			t.className   = 'lrn-preview-card__title';
			t.textContent = p.title || '';
			body.appendChild( t );

			var price = document.createElement( 'div' );
			price.className = 'lrn-preview-card__price';
			if ( p.price === 0 || p.price === '0' || p.price === 0.0 ) {
				price.textContent = i18n.freeOrMembership;
			} else {
				price.textContent = '$' + ( parseFloat( p.price ) || 0 ).toFixed( 0 );
			}
			body.appendChild( price );
			a.appendChild( body );

			prevEl.appendChild( a );
		} );
	}

	var resetFooter = document.getElementById( 'lrn-reset-demo-footer' );
	if ( resetFooter ) {
		resetFooter.addEventListener( 'click', async function () {
			var ok = await window.lrnConfirm( { message: i18n.confirmResetDemo } );
			if ( ! ok ) return;
			resetFooter.disabled    = true;
			resetFooter.textContent = i18n.resetting;
			// Destructive action — the endpoint requires an explicit confirmation
			// flag in the body, else it 400s (BC #9976862388). restCall() only
			// sends a body when it is non-null, so pass { confirm: true } here.
			restCall( 'POST', 'admin/setup-state/reset-demo', { confirm: true }, function ( r ) {
				resetFooter.disabled    = false;
				resetFooter.textContent = i18n.resetDemoData;
				if ( r.success ) {
					var stats    = ( r.data && r.data.stats ) || {};
					var previews = ( r.data && r.data.previews ) || [];
					renderSuccess( stats, previews, true );
					fireConfetti();
				} else {
					window.lrnToast( ( r.data && r.data.message ) || i18n.resetFailed, 'error' );
				}
			} );
		} );
	}

	function fireConfetti() {
		if ( typeof confetti !== 'function' ) return;
		var reduce = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
		if ( reduce ) return;

		confetti( { particleCount: 90, spread: 70, origin: { y: 0.6 } } );
		setTimeout( function () {
			confetti( {
				particleCount: 120,
				spread: 100,
				origin: { y: 0.6 },
				colors: [ '#2383e2', '#059669', '#f59e0b', '#ec4899' ]
			} );
		}, 400 );
	}

	function boot() {
		goToStep( 1 );
	}

	window.addEventListener( 'load', boot );
	if ( document.readyState === 'complete' ) { boot(); }

} )();
