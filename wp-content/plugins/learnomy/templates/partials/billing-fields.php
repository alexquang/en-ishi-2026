<?php
/**
 * Billing address fields — the ONE copy.
 *
 * Rendered by checkout and by the account Billing page. It used to exist only
 * inside `checkout.php`, which meant the only way a member could write their
 * billing address was to push a real purchase through. After checkout began
 * genuinely refusing incomplete details (Basecamp 10167587258), that stored
 * address became load-bearing and still had no account-side editor: a member
 * who mistyped their address could only correct it by buying something else
 * (Basecamp 10171338907).
 *
 * Extracted rather than re-typed on purpose. Two copies of an address form
 * drift, and the required set here has to stay identical to the one
 * `Billing_Profile::missing_required()` enforces, or the account page will
 * happily save a profile that checkout then rejects.
 *
 * Locals expected from the including template:
 *   $lrn_bill      array  Profile as returned by Billing_Profile::get().
 *   $lrn_countries array  code => name, the COMPLETE list. A partial list
 *                         silently excludes customers instead of failing
 *                         visibly, so it comes from one helper.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$lrn_bill      = $lrn_bill ?? array();
$lrn_countries = $lrn_countries ?? array();
?>
<div class="lrn-billing-row">
	<label for="lrn-billing-first-name"><?php esc_html_e( 'First name', 'learnomy' ); ?></label>
	<input type="text" id="lrn-billing-first-name" name="billing_first_name" autocomplete="given-name"
		value="<?php echo esc_attr( $lrn_bill['billing_first_name'] ?? '' ); ?>" required>
</div>
<div class="lrn-billing-row">
	<label for="lrn-billing-last-name"><?php esc_html_e( 'Last name', 'learnomy' ); ?></label>
	<input type="text" id="lrn-billing-last-name" name="billing_last_name" autocomplete="family-name"
		value="<?php echo esc_attr( $lrn_bill['billing_last_name'] ?? '' ); ?>" required>
</div>
<div class="lrn-billing-row">
	<label for="lrn-billing-country"><?php esc_html_e( 'Country', 'learnomy' ); ?></label>
	<select id="lrn-billing-country" name="billing_country" autocomplete="country" required>
		<option value=""><?php esc_html_e( 'Select a country', 'learnomy' ); ?></option>
		<?php foreach ( $lrn_countries as $lrn_code => $lrn_name ) : ?>
			<option value="<?php echo esc_attr( $lrn_code ); ?>"
				<?php selected( $lrn_code, $lrn_bill['billing_country'] ?? '' ); ?>>
				<?php echo esc_html( $lrn_name ); ?>
			</option>
		<?php endforeach; ?>
	</select>
</div>
<div class="lrn-billing-row">
	<label for="lrn-billing-address-1"><?php esc_html_e( 'Address', 'learnomy' ); ?></label>
	<input type="text" id="lrn-billing-address-1" name="billing_address_1" autocomplete="address-line1"
		value="<?php echo esc_attr( $lrn_bill['billing_address_1'] ?? '' ); ?>" required>
</div>
<div class="lrn-billing-row">
	<label for="lrn-billing-address-2"><?php esc_html_e( 'Apartment, suite, etc. (optional)', 'learnomy' ); ?></label>
	<input type="text" id="lrn-billing-address-2" name="billing_address_2" autocomplete="address-line2"
		value="<?php echo esc_attr( $lrn_bill['billing_address_2'] ?? '' ); ?>">
</div>
<div class="lrn-billing-row">
	<label for="lrn-billing-city"><?php esc_html_e( 'City', 'learnomy' ); ?></label>
	<input type="text" id="lrn-billing-city" name="billing_city" autocomplete="address-level2"
		value="<?php echo esc_attr( $lrn_bill['billing_city'] ?? '' ); ?>" required>
</div>
<div class="lrn-billing-grid">
	<div class="lrn-billing-row">
		<?php // Free text, not a select: a partial state list excludes people silently. ?>
		<label for="lrn-billing-state"><?php esc_html_e( 'State / Region (optional)', 'learnomy' ); ?></label>
		<input type="text" id="lrn-billing-state" name="billing_state" autocomplete="address-level1"
			value="<?php echo esc_attr( $lrn_bill['billing_state'] ?? '' ); ?>">
	</div>
	<div class="lrn-billing-row">
		<label for="lrn-billing-postcode"><?php esc_html_e( 'Postal code', 'learnomy' ); ?></label>
		<input type="text" id="lrn-billing-postcode" name="billing_postcode" autocomplete="postal-code"
			value="<?php echo esc_attr( $lrn_bill['billing_postcode'] ?? '' ); ?>" required>
	</div>
</div>
