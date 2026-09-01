<?php
/**
 * Terms-of-service consent checkbox, shared by both registration forms.
 *
 * One partial rather than a copy in each template: the student and instructor
 * forms must present the same agreement, and a divergence here is a legal
 * inconsistency, not a cosmetic one.
 *
 * Renders nothing when the site owner has published no legal page — see
 * Consent_Service::is_required(). The server enforces the same condition, so an
 * absent checkbox is never a bypass.
 *
 * The error <span data-field="accept_terms"> matches the contract register.js
 * already uses for every other field.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

use Learnomy\Services\Consent_Service;

if ( ! Consent_Service::is_required() ) {
	return;
}

$lrn_docs   = Consent_Service::documents();
$lrn_labels = array(
	'terms'   => __( 'Terms of Service', 'learnomy' ),
	'privacy' => __( 'Privacy Policy', 'learnomy' ),
);

$lrn_links = array();
foreach ( $lrn_docs as $lrn_kind => $lrn_url ) {
	$lrn_label   = $lrn_labels[ $lrn_kind ] ?? ucwords( str_replace( array( '-', '_' ), ' ', (string) $lrn_kind ) );
	$lrn_links[] = sprintf(
		'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
		esc_url( $lrn_url ),
		esc_html( $lrn_label )
	);
}
?>
<div class="lrn-form-group lrn-form-group--checkbox">
	<label class="lrn-checkbox" for="lrn-register-terms">
		<input type="checkbox"
			id="lrn-register-terms"
			name="accept_terms"
			value="1"
			required>
		<span class="lrn-checkbox__label">
			<?php
			// %l renders a localized list ("A and B" / "A, B and C") so the
			// sentence stays one translatable string however many documents
			// the owner configures.
			echo wp_kses(
				wp_sprintf(
					/* translators: %l: list of linked legal document names, e.g. "Terms of Service and Privacy Policy". */
					__( 'I agree to the %l.', 'learnomy' ),
					$lrn_links
				),
				array(
					'a' => array(
						'href'   => array(),
						'target' => array(),
						'rel'    => array(),
					),
				)
			);
			?>
			<span aria-hidden="true">*</span>
		</span>
	</label>
	<span class="lrn-form-error" data-field="accept_terms"></span>
</div>
