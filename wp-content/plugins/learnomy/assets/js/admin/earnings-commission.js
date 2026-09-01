/**
 * Extracted inline script from includes/admin/views/earnings.php.
 * Enqueued via class-admin.php conditionally on the matching admin page.
 * Per ux-foundation rule F2 (no inline <script> in PHP).
 *
 * @package Learnomy
 */
	var __ = wp.i18n.__;

	// Commission rule save — inject rate from sibling input before the
	// generic actions.js handler fires. Capture phase so it runs ahead of
	// the bubble-phase listener that reads data attributes into the payload.
	document.addEventListener( 'click', function ( e ) {
		var saveBtn = e.target.closest( '.lrn-save-commission' );
		if ( ! saveBtn ) { return; }
		var row   = saveBtn.closest( 'tr' );
		var input = row ? row.querySelector( '.lrn-commission-rate' ) : null;
		if ( input ) {
			// The inline input is the INSTRUCTOR percentage (its value is
			// rendered as 100 - platform_rate in earnings.php). actions.js maps
			// data-rate straight onto the schema's `platform_rate` column, so we
			// must convert instructor% -> platform% here — exactly as the create
			// form does — or the split is stored inverted (a 70/30 rule saves as
			// 30/70 on every edit).
			var instPct = parseFloat( input.value );
			if ( ! isNaN( instPct ) ) {
				saveBtn.setAttribute( 'data-rate', String( Math.round( ( 100 - instPct ) * 100 ) / 100 ) );
			}
		}
	}, true );

	// Create-rule form: toggle the course/instructor picker by type and POST a
	// new per-course / per-instructor override. Stores platform_rate (100 minus
	// the instructor % the admin types). Reuses the learnomyAdmin REST config.
	( function () {
		var form = document.getElementById( 'lrn-commission-rule-create' );
		if ( ! form ) { return; }

		var typeSel  = form.querySelector( '#lrn-cr-type' );
		var courseEl = form.querySelector( '#lrn-cr-course' );
		var instEl   = form.querySelector( '#lrn-cr-instructor' );
		var statusEl = form.querySelector( '.lrn-commission-rule-create__status' );

		function syncEntity() {
			var isCourse = 'course' === typeSel.value;
			courseEl.hidden = ! isCourse;
			instEl.hidden   = isCourse;
		}
		typeSel.addEventListener( 'change', syncEntity );
		syncEntity();

		function showStatus( msg, ok ) {
			if ( ! statusEl ) { return; }
			statusEl.textContent = msg;
			statusEl.hidden = false;
			statusEl.classList.toggle( 'is-error', ! ok );
		}

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var admin   = window.learnomyAdmin || {};
			var type    = typeSel.value;
			var entity  = 'course' === type ? parseInt( courseEl.value, 10 ) : parseInt( instEl.value, 10 );
			var instPct = parseFloat( form.querySelector( '#lrn-cr-rate' ).value );

			if ( ! entity ) { showStatus( __( 'Choose a course or instructor.', 'learnomy' ), false ); return; }
			if ( isNaN( instPct ) || instPct < 0 || instPct > 100 ) { showStatus( __( 'Enter an instructor rate between 0 and 100.', 'learnomy' ), false ); return; }

			fetch( ( admin.restUrl || '/wp-json/learnomy/v1/' ) + 'admin/commission-rules', {
				method:      'POST',
				credentials: 'include',
				headers:     { 'Content-Type': 'application/json', 'X-WP-Nonce': admin.restNonce || '' },
				body: JSON.stringify( { rule_type: type, entity_id: entity, platform_rate: Math.round( ( 100 - instPct ) * 100 ) / 100 } )
			} ).then( function ( r ) {
				return r.json().then( function ( b ) { return { ok: r.ok, body: b }; } );
			} ).then( function ( res ) {
				if ( ! res.ok ) {
					showStatus( ( res.body && res.body.error && res.body.error.message ) || __( 'Could not add the rule.', 'learnomy' ), false );
					return;
				}
				window.location.reload();
			} ).catch( function () { showStatus( __( 'Could not add the rule.', 'learnomy' ), false ); } );
		} );
	} )();
