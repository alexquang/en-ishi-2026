<?php
/**
 * Settings section: Custom registration / profile fields (no-code builder).
 *
 * Repeater of field definitions stored at
 * learnomy_settings[custom_account_fields][<i>][{label,key,type,required,location,options}].
 * The Custom_Fields service renders + saves them on the registration + account
 * surfaces via the learnomy_account_fields / _save hooks.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'learnomy_render_account_field_row' ) ) {
	/**
	 * Render one repeater row. $index is the array index (or __INDEX__ for the
	 * JS clone template). $field is a normalized definition, or empty for a blank row.
	 *
	 * @param int|string $index Row index, or the literal __INDEX__ placeholder.
	 * @param array      $field Normalized field definition (empty for new rows).
	 * @return void
	 */
	function learnomy_render_account_field_row( $index, array $field = array() ): void {
		$base     = 'learnomy_settings[custom_account_fields][' . $index . ']';
		$key      = (string) ( $field['key'] ?? '' );
		$label    = (string) ( $field['label'] ?? '' );
		$type     = (string) ( $field['type'] ?? 'text' );
		$required = ! empty( $field['required'] );
		$location = (string) ( $field['location'] ?? 'both' );
		$options  = implode( "\n", (array) ( $field['options'] ?? array() ) );
		$types    = array(
			'text'     => __( 'Text', 'learnomy' ),
			'email'    => __( 'Email', 'learnomy' ),
			'tel'      => __( 'Phone', 'learnomy' ),
			'textarea' => __( 'Paragraph', 'learnomy' ),
			'select'   => __( 'Dropdown', 'learnomy' ),
			'checkbox' => __( 'Checkbox', 'learnomy' ),
		);

		/*
		 * Wording says what the owner GETS, not what the code calls it. The two
		 * "only" options are write-once from the member's side — asked at signup
		 * and never shown again — which was true before and said nowhere, so a
		 * typo was permanent and nobody knew that when choosing.
		 */
		$locations = array(
			'both'                    => __( 'Everyone - asked at signup, editable in the account', 'learnomy' ),
			'registration'            => __( 'Everyone - asked once at signup, cannot be changed later', 'learnomy' ),
			'registration_student'    => sprintf(
				/* translators: %s: plural label for students (e.g. "Learners"). */
				__( '%s only - asked once at signup, cannot be changed later', 'learnomy' ),
				\Learnomy\student_label( true )
			),
			'registration_instructor' => sprintf(
				/* translators: %s: plural label for instructors (e.g. "Coaches"). */
				__( '%s only - asked on the application, editable on their profile', 'learnomy' ),
				\Learnomy\instructor_label( true )
			),
			'account'                 => __( 'Everyone - account only, never asked at signup', 'learnomy' ),
		);
		?>
		<div class="lrn-account-field-row" data-lrn-field-row>
			<input type="hidden" name="<?php echo esc_attr( $base ); ?>[key]" value="<?php echo esc_attr( $key ); ?>" data-lrn-field-key>
			<div class="lrn-account-field-row__grid">
				<label class="lrn-form-field">
					<span><?php esc_html_e( 'Label', 'learnomy' ); ?></span>
					<input type="text" name="<?php echo esc_attr( $base ); ?>[label]" value="<?php echo esc_attr( $label ); ?>" class="regular-text" data-lrn-field-label>
				</label>
				<label class="lrn-form-field">
					<span><?php esc_html_e( 'Type', 'learnomy' ); ?></span>
					<select name="<?php echo esc_attr( $base ); ?>[type]" data-lrn-field-type>
						<?php foreach ( $types as $val => $text ) : ?>
							<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $type, $val ); ?>><?php echo esc_html( $text ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label class="lrn-form-field">
					<span><?php esc_html_e( 'Show on', 'learnomy' ); ?></span>
					<select name="<?php echo esc_attr( $base ); ?>[location]">
						<?php foreach ( $locations as $val => $text ) : ?>
							<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $location, $val ); ?>><?php echo esc_html( $text ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label class="lrn-form-field lrn-account-field-row__required">
					<input type="checkbox" name="<?php echo esc_attr( $base ); ?>[required]" value="1" <?php checked( $required ); ?>>
					<span><?php esc_html_e( 'Required', 'learnomy' ); ?></span>
				</label>
				<button type="button" class="lrn-admin-btn lrn-admin-btn--ghost lrn-admin-btn--icon lrn-admin-btn--danger" data-lrn-field-remove aria-label="<?php esc_attr_e( 'Remove field', 'learnomy' ); ?>">
					<i data-lucide="trash-2"></i>
				</button>
			</div>
			<label class="lrn-form-field lrn-account-field-row__options" data-lrn-field-options <?php echo 'select' === $type ? '' : 'hidden'; ?>>
				<span><?php esc_html_e( 'Dropdown options (one per line)', 'learnomy' ); ?></span>
				<textarea name="<?php echo esc_attr( $base ); ?>[options]" rows="3" class="large-text"><?php echo esc_textarea( $options ); ?></textarea>
			</label>
		</div>
		<?php
	}
}

$lrn_account_fields = ( new \Learnomy\Services\Custom_Fields() )->definitions();
?>
<div class="lrn-settings-section" id="section-account-fields">
	<form method="post" action="options.php">
		<?php settings_fields( 'learnomy_settings' ); ?>

		<div class="lrn-settings-card">
			<div class="lrn-settings-card__head">
				<h2 class="lrn-settings-card__title"><?php esc_html_e( 'Registration &amp; profile fields', 'learnomy' ); ?></h2>
				<p class="lrn-settings-card__desc"><?php esc_html_e( 'Add custom fields to the sign-up form and account page - license numbers, company, phone, consent. No code required; they save to the member profile and show in the admin.', 'learnomy' ); ?></p>
			</div>

			<div id="lrn-account-fields-repeater" class="lrn-account-fields-repeater">
				<?php
				$lrn_i = 0;
				foreach ( $lrn_account_fields as $lrn_field ) {
					learnomy_render_account_field_row( $lrn_i, $lrn_field );
					++$lrn_i;
				}
				?>
			</div>
			<p class="lrn-account-fields-empty" <?php echo empty( $lrn_account_fields ) ? '' : 'hidden'; ?>>
				<?php esc_html_e( 'No custom fields yet. Add one below.', 'learnomy' ); ?>
			</p>

			<button type="button" class="lrn-admin-btn lrn-admin-btn--ghost" id="lrn-account-fields-add">
				<i data-lucide="plus"></i> <?php esc_html_e( 'Add field', 'learnomy' ); ?>
			</button>

			<template id="lrn-account-field-template">
				<?php learnomy_render_account_field_row( '__INDEX__' ); ?>
			</template>
		</div>

		<?php
		// Sits with the sign-up fields because it is one: a question added to
		// the same two forms, saved by the same Save Changes button.
		// Reads the option directly: this partial never declares $saved, and
		// is_enabled() applies the override filter — a checkbox must show what is
		// STORED, or an owner sees it ticked and cannot untick it.
		$lrn_captcha_settings = get_option( 'learnomy_settings', array() );
		$lrn_captcha_on       = is_array( $lrn_captcha_settings )
			&& ! empty( $lrn_captcha_settings[ \Learnomy\Services\Registration_Captcha::SETTING_KEY ] );
		?>
		<div class="lrn-settings-card">
			<div class="lrn-settings-card__head">
				<h2 class="lrn-settings-card__title"><?php esc_html_e( 'Anti-spam question', 'learnomy' ); ?></h2>
				<p class="lrn-settings-card__desc">
					<?php esc_html_e( 'Adds a simple sum to both sign-up forms. It calls no outside service, sends no visitor data anywhere, needs no account or key, and a screen reader can answer it.', 'learnomy' ); ?>
				</p>
			</div>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Anti-spam question', 'learnomy' ); ?></th>
					<td>
						<label class="lrn-form-field__checkbox-row">
							<input type="checkbox"
								name="learnomy_settings[<?php echo esc_attr( \Learnomy\Services\Registration_Captcha::SETTING_KEY ); ?>]"
								value="1" <?php checked( $lrn_captcha_on ); ?>>
							<span><?php esc_html_e( 'Ask a simple sum before creating an account', 'learnomy' ); ?></span>
						</label>
						<p class="description">
							<?php esc_html_e( 'Stops the bots that post at any form they find. It will not stop someone targeting your site specifically, so it works alongside the hidden-field trap, sign-up rate limits and email confirmation rather than replacing them.', 'learnomy' ); ?>
						</p>
					</td>
				</tr>
			</table>
		</div>

		<div class="lrn-settings-section__footer">
			<?php submit_button( __( 'Save Changes', 'learnomy' ), 'primary', 'submit', false ); ?>
		</div>
	</form>
</div>
