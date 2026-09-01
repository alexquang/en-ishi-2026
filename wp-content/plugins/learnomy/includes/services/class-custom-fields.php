<?php
/**
 * Custom account fields — site-owner-defined registration / profile fields.
 *
 * A no-code layer ON TOP of the `learnomy_account_fields` (render) and
 * `learnomy_account_fields_save` (save) hooks: the site owner defines fields in
 * Settings, and this class is a first-party consumer of those same hooks, so
 * admin-defined fields and developer-registered fields render through one seam.
 *
 * Definitions live in learnomy_settings['custom_account_fields'] (no schema
 * change); values live in user meta as learnomy_field_<key>.
 *
 * @package Learnomy
 */

namespace Learnomy\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Renders + saves + validates site-owner-defined account fields.
 */
class Custom_Fields {

	/** Settings key holding the field definitions. */
	public const SETTING_KEY = 'custom_account_fields';

	/** User-meta prefix + form field-name prefix for stored values. */
	public const META_PREFIX = 'learnomy_field_';

	/** Allowed field types. */
	public const TYPES = array( 'text', 'email', 'tel', 'textarea', 'select', 'checkbox' );

	/**
	 * Allowed render locations.
	 *
	 * `registration` means BOTH signup forms, which is what it has always meant
	 * and stays the back-compatible value. The two per-form values exist because
	 * the forms ask different audiences different things: an instructor
	 * application wants a portfolio link and years of experience, a student
	 * signup wants none of that, and before this a field could only be on both
	 * or neither.
	 */
	public const LOCATIONS = array( 'registration', 'registration_student', 'registration_instructor', 'account', 'both' );

	/**
	 * Render contexts (concrete surfaces) mapped to the locations each shows.
	 *
	 * Written out rather than derived, because the interesting case is not
	 * derivable: the instructor PROFILE screen shows the fields the instructor
	 * application asked for, so somebody who typed their certification number
	 * into the application can correct it later. It deliberately does not repeat
	 * the plain account fields — those live on Account details, and printing the
	 * same input on two screens of the same account area is how you get two
	 * answers to one question.
	 */
	public const CONTEXT_LOCATIONS = array(
		'registration_student'    => array( 'both', 'registration', 'registration_student' ),
		'registration_instructor' => array( 'both', 'registration', 'registration_instructor' ),
		'account'                 => array( 'both', 'account' ),
		'account_instructor'      => array( 'registration_instructor' ),
	);

	/**
	 * Wire the render + save consumers onto the shared hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'learnomy_account_fields', array( $this, 'render' ), 10, 2 );
		add_action( 'learnomy_account_fields_save', array( $this, 'save' ), 10, 2 );
	}

	/**
	 * Normalized field definitions, keyed by field key.
	 *
	 * @return array<string,array>
	 */
	public function definitions(): array {
		$settings = get_option( 'learnomy_settings', array() );
		$raw      = ( is_array( $settings ) && isset( $settings[ self::SETTING_KEY ] ) ) ? (array) $settings[ self::SETTING_KEY ] : array();

		$out = array();
		foreach ( $raw as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$key = sanitize_key( (string) ( $field['key'] ?? '' ) );
			if ( '' === $key ) {
				continue;
			}
			$out[ $key ] = array(
				'key'      => $key,
				'label'    => (string) ( $field['label'] ?? $key ),
				'type'     => in_array( $field['type'] ?? '', self::TYPES, true ) ? (string) $field['type'] : 'text',
				'required' => ! empty( $field['required'] ),
				'location' => in_array( $field['location'] ?? '', self::LOCATIONS, true ) ? (string) $field['location'] : 'both',
				'options'  => array_values( array_filter( array_map( 'strval', (array) ( $field['options'] ?? array() ) ) ) ),
			);
		}
		return $out;
	}

	/**
	 * Definitions that apply to a given location (location match OR 'both').
	 *
	 * @param string $location registration|account.
	 * @return array<string,array>
	 */
	private function for_location( string $location ): array {
		return array_filter(
			$this->definitions(),
			static fn( array $f ): bool => 'both' === $f['location'] || $location === $f['location']
		);
	}

	/**
	 * Coerce a caller-supplied context to one we render for.
	 *
	 * Accepts the legacy 'registration' (which meant both forms) and anything
	 * unrecognised, so a consumer calling the action the old way keeps working.
	 *
	 * @param string $context  Caller's context.
	 * @param string $fallback Context to use when the caller gave nothing usable.
	 * @return string One of self::CONTEXTS.
	 */
	private function normalize_context( string $context, string $fallback = 'registration_student' ): string {
		if ( isset( self::CONTEXT_LOCATIONS[ $context ] ) ) {
			return $context;
		}
		if ( 'registration' === $context ) {
			return 'registration_student';
		}
		return $fallback;
	}

	/**
	 * Definitions that apply to a render/save CONTEXT.
	 *
	 * A context is a concrete surface (the student form, the instructor form,
	 * the account screen); a location is what the owner picked. `registration`
	 * covers both forms, so it matches either registration context — that is the
	 * rule that keeps every field defined before this change behaving the same.
	 *
	 * @param string $context One of self::CONTEXTS.
	 * @return array<string,array>
	 */
	private function for_context( string $context ): array {
		$locations = self::CONTEXT_LOCATIONS[ $context ] ?? array();

		return array_filter(
			$this->definitions(),
			static fn( array $f ): bool => in_array( $f['location'], $locations, true )
		);
	}

	// ── Render (consumer of learnomy_account_fields) ──────────────────────────

	/**
	 * Render the defined fields. Location is inferred from $user_id: the
	 * registration forms fire with 0 (no account yet), account-details with the
	 * current user id (so values pre-fill).
	 *
	 * @param int $user_id 0 on registration, the user id on account-details.
	 * @return void
	 */
	public function render( $user_id, string $context = '' ): void {
		$user_id = (int) $user_id;

		// The action passes its context now. Anything calling it the old way
		// (one argument) still lands on the original behaviour, which could only
		// tell registration from account by whether a user existed yet.
		$context = $this->normalize_context( $context, $user_id > 0 ? 'account' : 'registration_student' );

		foreach ( $this->for_context( $context ) as $field ) {
			$value = $user_id > 0 ? (string) get_user_meta( $user_id, self::META_PREFIX . $field['key'], true ) : '';
			$this->render_field( $field, $value );
		}
	}

	/**
	 * Render ONE field as native .lrn-field markup (matches the surrounding form).
	 *
	 * @param array  $field Normalized field definition.
	 * @param string $value Current value (empty at registration).
	 * @return void
	 */
	private function render_field( array $field, string $value ): void {
		$id   = 'lrn-field-' . $field['key'];
		$name = self::META_PREFIX . $field['key'];
		$req  = $field['required'] ? ' required' : '';
		$star = $field['required'] ? ' <span class="required" aria-hidden="true">*</span>' : '';

		if ( 'checkbox' === $field['type'] ) {
			?>
			<p class="lrn-field">
				<label class="lrn-checkbox-label" for="<?php echo esc_attr( $id ); ?>">
					<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $value, '1' ); ?><?php echo esc_attr( $req ); ?>>
					<span class="lrn-field__label"><?php echo esc_html( $field['label'] ); ?><?php echo wp_kses_post( $star ); ?></span>
				</label>
			</p>
			<?php
			return;
		}
		?>
		<p class="lrn-field">
			<label class="lrn-field__label" for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $field['label'] ); ?><?php echo wp_kses_post( $star ); ?></label>
			<?php if ( 'textarea' === $field['type'] ) : ?>
				<textarea class="lrn-field__input" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>"<?php echo esc_attr( $req ); ?>><?php echo esc_textarea( $value ); ?></textarea>
			<?php elseif ( 'select' === $field['type'] ) : ?>
				<select class="lrn-field__input" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>"<?php echo esc_attr( $req ); ?>>
					<option value=""><?php esc_html_e( 'Select…', 'learnomy' ); ?></option>
					<?php foreach ( $field['options'] as $opt ) : ?>
						<option value="<?php echo esc_attr( $opt ); ?>" <?php selected( $value, $opt ); ?>><?php echo esc_html( $opt ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php else : ?>
				<input type="<?php echo esc_attr( $field['type'] ); ?>" class="lrn-field__input" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>"<?php echo esc_attr( $req ); ?>>
			<?php endif; ?>
		</p>
		<?php
	}

	// ── Save (consumer of learnomy_account_fields_save) ───────────────────────

	/**
	 * Persist submitted field values to user meta (sanitized per type). Only
	 * fields actually present in the request are written, so a registration
	 * request never clears an account-only field and vice versa.
	 *
	 * @param int   $user_id The user to save against.
	 * @param mixed $request The REST request (or any object with get_param()).
	 * @return void
	 */
	public function save( $user_id, $request ): void {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return;
		}
		// Every definition, not just this context's: a value only lands here if
		// the request actually carried it, and scoping the write would drop a
		// field an extension rendered through the hook on a surface we do not
		// know about.
		foreach ( $this->definitions() as $field ) {
			$raw = $this->request_value( $request, self::META_PREFIX . $field['key'], 'checkbox' === $field['type'] );
			if ( null === $raw ) {
				continue;
			}
			update_user_meta( $user_id, self::META_PREFIX . $field['key'], $this->sanitize_value( $field, $raw ) );
		}
	}

	// ── Validate (required enforcement, server-side) ──────────────────────────

	/**
	 * Required-field errors for a location, BEFORE the user is created/updated.
	 *
	 * @param mixed  $request  The REST request.
	 * @param string $location registration|account.
	 * @return string[] Human-readable error messages (empty = valid).
	 */
	public function validate( $request, string $context ): array {
		$errors = array();
		// Same matcher render() uses. With for_location() here a field scoped to
		// one form would be drawn on that form and then never enforced, which is
		// worse than not offering the scope at all: the asterisk would lie.
		foreach ( $this->for_context( $this->normalize_context( $context ) ) as $field ) {
			if ( ! $field['required'] ) {
				continue;
			}
			$raw = $this->request_value( $request, self::META_PREFIX . $field['key'], 'checkbox' === $field['type'] );
			if ( null === $raw || '' === trim( (string) $raw ) ) {
				/* translators: %s: field label */
				$errors[] = sprintf( __( '%s is required.', 'learnomy' ), $field['label'] );
			}
		}
		return $errors;
	}

	// ── Admin: sanitize the field definitions on save ─────────────────────────

	/**
	 * Sanitize the admin-submitted field-definition array (the Settings repeater).
	 * Drops empty rows + duplicate/blank keys; clamps type + location to the
	 * allowed sets; keeps options only for select.
	 *
	 * @param mixed $raw Raw submitted rows.
	 * @return array<int,array>
	 */
	public function sanitize_definitions( $raw ): array {
		$out  = array();
		$seen = array();
		foreach ( (array) $raw as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$label = sanitize_text_field( (string) ( $field['label'] ?? '' ) );
			if ( '' === trim( $label ) ) {
				continue;
			}
			// Key is immutable once created; derive from label only when absent.
			// Use sanitize_title (spaces -> dashes) then dashes -> underscores so
			// "Bar Number" yields a readable "bar_number", not "barnumber".
			$key = sanitize_key( (string) ( $field['key'] ?? '' ) );
			if ( '' === $key ) {
				$key = sanitize_key( str_replace( '-', '_', sanitize_title( $label ) ) );
			}
			if ( '' === $key || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;

			$type    = in_array( $field['type'] ?? '', self::TYPES, true ) ? (string) $field['type'] : 'text';
			$options = array();
			if ( 'select' === $type ) {
				$opt_raw = $field['options'] ?? '';
				if ( is_string( $opt_raw ) ) {
					$opt_raw = preg_split( '/\r\n|\r|\n/', $opt_raw );
				}
				$options = array_values(
					array_filter(
						array_map( static fn( $o ): string => sanitize_text_field( (string) $o ), (array) $opt_raw ),
						static fn( string $o ): bool => '' !== trim( $o )
					)
				);
			}

			$out[] = array(
				'key'      => $key,
				'label'    => $label,
				'type'     => $type,
				'required' => ! empty( $field['required'] ),
				'location' => in_array( $field['location'] ?? '', self::LOCATIONS, true ) ? (string) $field['location'] : 'both',
				'options'  => $options,
			);
		}
		return $out;
	}

	// ── Helpers ──────────────────────────────────────────────────────────────

	/**
	 * Read a value from the request. Returns null when the field was not
	 * submitted at all — except checkboxes, where "absent" means unchecked.
	 *
	 * @param mixed  $request     REST request (or object with get_param()).
	 * @param string $name        Field name.
	 * @param bool   $is_checkbox Whether absence should mean "0" instead of null.
	 * @return mixed|null
	 */
	private function request_value( $request, string $name, bool $is_checkbox ) {
		$value = null;
		if ( is_object( $request ) && method_exists( $request, 'get_param' ) ) {
			$value = $request->get_param( $name );
		}
		if ( null === $value && $is_checkbox ) {
			// A checkbox is only present in the request when checked; treat an
			// absent checkbox as an explicit "unchecked" so it can be cleared.
			return '';
		}
		return $value;
	}

	/**
	 * Sanitize ONE submitted value by its field type.
	 *
	 * @param array $field Normalized field definition.
	 * @param mixed $raw   Raw submitted value.
	 * @return string
	 */
	private function sanitize_value( array $field, $raw ): string {
		switch ( $field['type'] ) {
			case 'checkbox':
				return empty( $raw ) ? '' : '1';
			case 'email':
				return sanitize_email( (string) $raw );
			case 'textarea':
				return sanitize_textarea_field( wp_unslash( (string) $raw ) );
			case 'select':
				return in_array( (string) $raw, $field['options'], true ) ? (string) $raw : '';
			default:
				return sanitize_text_field( wp_unslash( (string) $raw ) );
		}
	}
}
