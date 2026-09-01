/**
 * Extracted inline script from includes/admin/views/partials/course-settings-pricing.php.
 * Enqueued via class-admin.php conditionally on the matching admin page.
 * Per ux-foundation rule F2 (no inline <script> in PHP).
 *
 * @package Learnomy
 */
// Show/hide per-model fields + keep the legacy access_type mirror in sync
// with the radio selection so the existing controller validation passes.
( function () {
	// pricing_model -> access_type mirror, localized from the Course model's
	// single-source MODEL_ACCESS_MAP (window.lrnPricingMap). The inline object
	// is only a defensive fallback if the localize ever fails to print.
	var ACCESS_MAP = ( window.lrnPricingMap && typeof window.lrnPricingMap === 'object' )
		? window.lrnPricingMap
		: {
			'free': 'free',
			'one_time': 'paid',
			'fixed_term': 'paid',
			'recurring': 'paid',
			'members_only': 'membership',
			'one_time_or_members': 'paid_or_membership'
		};

	function sync() {
		var checked = document.querySelector( 'input[name="pricing_model"]:checked' );
		var value   = checked ? checked.value : 'free';

		var mirror = document.getElementById( 'lrn-course-access' );
		if ( mirror ) {
			mirror.value = ACCESS_MAP[ value ] || 'free';
		}

		document.querySelectorAll( '.lrn-pricing-fields' ).forEach( function ( el ) {
			var shows = ( el.getAttribute( 'data-shows-for' ) || '' ).split( /\s+/ );
			el.style.display = shows.indexOf( value ) !== -1 ? '' : 'none';
		} );

		updateAccessSummary( value );
	}

	/**
	 * Recompose the live "what students will experience" sentence from the
	 * selected pricing model + visibility, using the localized clause maps
	 * (window.lrnAccessSummary) — the same source Course::access_summary uses.
	 */
	function updateAccessSummary( model ) {
		var parts = window.lrnAccessSummary;
		var out   = document.getElementById( 'lrn-access-summary-text' );
		if ( ! parts || ! out ) { return; }

		var visEl = document.getElementById( 'lrn-course-visibility' );
		var vis   = visEl ? visEl.value : 'public';

		var visClause   = parts.visibility[ vis ] || parts.visibility.public;
		var entryClause = parts.entry[ model ] || parts.entry._default;

		out.textContent = parts.template
			.replace( '%1$s', visClause )
			.replace( '%2$s', entryClause );
	}

	document.addEventListener( 'change', function ( e ) {
		if ( ! e.target ) { return; }
		if ( 'pricing_model' === e.target.name ) {
			sync();
		} else if ( 'visibility' === e.target.name ) {
			var checked = document.querySelector( 'input[name="pricing_model"]:checked' );
			updateAccessSummary( checked ? checked.value : 'free' );
		}
	} );

	// Run once on load so the initial render shows the right field set.
	if ( document.readyState !== 'loading' ) {
		sync();
	} else {
		document.addEventListener( 'DOMContentLoaded', sync );
	}
} )();
