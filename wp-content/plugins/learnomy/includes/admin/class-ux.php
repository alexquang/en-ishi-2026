<?php
/**
 * Admin UX helpers — the single source for the shared admin "family" widgets.
 *
 * Every admin list / report page renders its status pills and row actions
 * through these helpers so the whole admin looks like one product instead of
 * 20 hand-rolled tables. Add a widget here, never re-implement it in a view.
 *
 *   - status_badge()  maps a raw status to a CANONICAL badge variant
 *                     (success/warn/danger/info/muted/default) so views can
 *                     never emit an undefined `--<rawstatus>` class again.
 *   - row_actions()   renders the canonical actions cell: 1-2 primary actions
 *                     inline + everything else in a "⋮ More" overflow menu, so
 *                     a row with 2 actions and a row with 7 look identical and
 *                     never wrap.
 *
 * @package Learnomy
 */

namespace Learnomy\Admin;

defined( 'ABSPATH' ) || exit;

class UX {

	/**
	 * Canonical status → badge-variant map. Extend via the
	 * `learnomy_admin_status_variants` filter (Pro / site owners). Anything not
	 * mapped falls back to `default` so the badge is always styled.
	 *
	 * @return array<string, string>
	 */
	private static function status_variants(): array {
		$map = array(
			// success
			'active'             => 'success',
			'completed'          => 'success',
			'published'          => 'success',
			'approved'           => 'success',
			'enabled'            => 'success',
			'paid'               => 'success',
			'sent'               => 'success',
			'graded'             => 'success',
			// warn
			'pending'            => 'warn',
			'pending_review'     => 'warn',
			'past_due'           => 'warn',
			'paused'             => 'warn',
			// A suspended instructor or member is paused BY THE OWNER and is
			// meant to be resumed - warn, not danger. Danger is for states the
			// owner cannot undo from the row (cancelled, expired, revoked).
			'suspended'          => 'warn',
			'trialing'           => 'warn',
			'draft'              => 'warn',
			// A subscription with cancel_at_period_end set is still ACTIVE but is
			// on its way out. It is not a success state and not a cancellation
			// yet, so it must not read as either — it is the one state an owner
			// can still act on (resume it) before access lapses.
			'cancel_pending'     => 'warn',
			// danger
			'failed'             => 'danger',
			'rejected'           => 'danger',
			'cancelled'          => 'danger',
			'canceled'           => 'danger',
			'expired'            => 'danger',
			'revoked'            => 'danger',
			'refunded'           => 'muted',
			// A partial refund is not a full one: the sale is still (mostly) real
			// revenue and the learner still has access. Warn, not muted.
			'partially_refunded' => 'warn',
			// muted / default
			'inactive'           => 'muted',
			'disabled'           => 'muted',
			'archived'           => 'muted',
			'skipped'            => 'muted',
			// webhook event outcomes (webhook-events.php)
			'handled'            => 'success',
			'duplicate'          => 'info',
			'error'              => 'danger',
			// webhook providers
			'stripe'             => 'info',
			'paypal'             => 'info',
		);

		/**
		 * Filter the admin status → badge-variant map.
		 *
		 * @param array<string,string> $map status slug => variant.
		 */
		return (array) apply_filters( 'learnomy_admin_status_variants', $map );
	}

	/**
	 * Resolve a status slug to its canonical badge variant.
	 *
	 * @param string $status Raw status (e.g. "completed", "past_due").
	 */
	public static function status_variant( string $status ): string {
		$status = strtolower( trim( $status ) );
		$map    = self::status_variants();
		return $map[ $status ] ?? 'default';
	}

	/**
	 * Canonical status slug → translated display label. Extend via the
	 * `learnomy_admin_status_labels` filter. Any slug not mapped falls back to
	 * a humanised version of the raw slug (last resort for unknown vocabulary).
	 *
	 * @return array<string,string>
	 */
	private static function status_labels(): array {
		$labels = array(
			'active'             => __( 'Active', 'learnomy' ),
			'completed'          => __( 'Completed', 'learnomy' ),
			'published'          => __( 'Published', 'learnomy' ),
			'approved'           => __( 'Approved', 'learnomy' ),
			'enabled'            => __( 'Enabled', 'learnomy' ),
			'paid'               => __( 'Paid', 'learnomy' ),
			'sent'               => __( 'Sent', 'learnomy' ),
			'graded'             => __( 'Graded', 'learnomy' ),
			'pending'            => __( 'Pending', 'learnomy' ),
			'pending_review'     => __( 'Pending Review', 'learnomy' ),
			'past_due'           => __( 'Past Due', 'learnomy' ),
			'paused'             => __( 'Paused', 'learnomy' ),
			'trialing'           => __( 'Trialing', 'learnomy' ),
			'draft'              => __( 'Draft', 'learnomy' ),
			'cancel_pending'     => __( 'Cancel pending', 'learnomy' ),
			'failed'             => __( 'Failed', 'learnomy' ),
			'rejected'           => __( 'Rejected', 'learnomy' ),
			'cancelled'          => __( 'Cancelled', 'learnomy' ),
			'canceled'           => __( 'Cancelled', 'learnomy' ),
			'expired'            => __( 'Expired', 'learnomy' ),
			'revoked'            => __( 'Revoked', 'learnomy' ),
			'refunded'           => __( 'Refunded', 'learnomy' ),
			'partially_refunded' => __( 'Partially refunded', 'learnomy' ),
			'inactive'           => __( 'Inactive', 'learnomy' ),
			'disabled'           => __( 'Disabled', 'learnomy' ),
			'archived'           => __( 'Archived', 'learnomy' ),
			'skipped'            => __( 'Skipped', 'learnomy' ),
			'handled'            => __( 'Handled', 'learnomy' ),
			'duplicate'          => __( 'Duplicate', 'learnomy' ),
			'error'              => __( 'Error', 'learnomy' ),
			'stripe'             => __( 'Stripe', 'learnomy' ),
			'paypal'             => __( 'PayPal', 'learnomy' ),
			'processed'          => __( 'Processed', 'learnomy' ),
			'dismissed'          => __( 'Dismissed', 'learnomy' ),
			'actioned'           => __( 'Actioned', 'learnomy' ),
			'spam'               => __( 'Spam', 'learnomy' ),
		);

		/**
		 * Filter the admin status slug → translated label map.
		 *
		 * @param array<string,string> $labels status slug => display label.
		 */
		return (array) apply_filters( 'learnomy_admin_status_labels', $labels );
	}

	/**
	 * Resolve a status slug to its translated display label. Falls back to a
	 * humanised version of the slug for vocabulary not in the map.
	 *
	 * @param string $status Raw status slug.
	 */
	public static function status_label( string $status ): string {
		$key    = strtolower( trim( $status ) );
		$labels = self::status_labels();
		return $labels[ $key ] ?? ucwords( str_replace( array( '_', '-' ), ' ', $status ) );
	}

	/**
	 * Render a canonical status badge. Always lands on a defined variant.
	 *
	 * @param string      $status Raw status slug.
	 * @param string|null $label  Display label; defaults to a translated status.
	 */
	public static function status_badge( string $status, ?string $label = null ): string {
		$variant = self::status_variant( $status );
		$label   = null !== $label ? $label : self::status_label( $status );

		return sprintf(
			'<span class="lrn-status-badge lrn-status-badge--%s">%s</span>',
			esc_attr( $variant ),
			esc_html( $label )
		);
	}

	/**
	 * Translate a slug from a known enum vocabulary. Falls back to a humanised
	 * version of the slug for values not in the map (last resort).
	 *
	 * Shared so every admin view humanises transaction types, quiz modes,
	 * commission rule types, coupon scopes, and question types from ONE
	 * translated source instead of scattered `ucfirst()`/`ucwords()` calls.
	 *
	 * @param string $slug Raw enum value.
	 * @param string $set  Vocabulary key: transaction_type|quiz_mode|rule_type|applies_to|question_type|payment_provider.
	 */
	public static function enum_label( string $slug, string $set ): string {
		$key = strtolower( trim( $slug ) );

		$vocab = array(
			'transaction_type' => array(
				'purchase'           => __( 'Purchase', 'learnomy' ),
				'course_purchase'    => __( 'Course purchase', 'learnomy' ),
				'membership'         => __( 'Membership', 'learnomy' ),
				'membership_renewal' => __( 'Membership renewal', 'learnomy' ),
				'subscription'       => __( 'Subscription', 'learnomy' ),
				'renewal'            => __( 'Renewal', 'learnomy' ),
				'refund'             => __( 'Refund', 'learnomy' ),
				'withdrawal'         => __( 'Withdrawal', 'learnomy' ),
				'commission'         => __( 'Commission', 'learnomy' ),
				'external'           => __( 'External', 'learnomy' ),
			),
			'quiz_mode'        => array(
				'graded'   => __( 'Graded', 'learnomy' ),
				'practice' => __( 'Practice', 'learnomy' ),
				'survey'   => __( 'Survey', 'learnomy' ),
			),
			'rule_type'        => array(
				'global'     => __( 'Global', 'learnomy' ),
				'instructor' => __( 'Instructor', 'learnomy' ),
				'course'     => __( 'Course', 'learnomy' ),
			),
			'applies_to'       => array(
				'all'         => __( 'All', 'learnomy' ),
				'courses'     => __( 'Courses', 'learnomy' ),
				'course'      => __( 'Course', 'learnomy' ),
				'memberships' => __( 'Memberships', 'learnomy' ),
				'membership'  => __( 'Membership', 'learnomy' ),
			),
			'question_type'    => array(
				'true_false'   => __( 'True / False', 'learnomy' ),
				'single'       => __( 'Single choice', 'learnomy' ),
				'multiple'     => __( 'Multiple choice', 'learnomy' ),
				'short_answer' => __( 'Short answer', 'learnomy' ),
				'essay'        => __( 'Essay', 'learnomy' ),
				'fill_blank'   => __( 'Fill in the blank', 'learnomy' ),
				'ordering'     => __( 'Ordering', 'learnomy' ),
				'matching'     => __( 'Matching', 'learnomy' ),
				'numeric'      => __( 'Numeric', 'learnomy' ),
				'math'         => __( 'Math', 'learnomy' ),
				'calculated'   => __( 'Calculated', 'learnomy' ),
			),
			'payment_provider' => array(
				'stripe'      => __( 'Stripe', 'learnomy' ),
				'paypal'      => __( 'PayPal', 'learnomy' ),
				'woocommerce' => __( 'WooCommerce', 'learnomy' ),
				'test'        => __( 'Test', 'learnomy' ),
				'manual'      => __( 'Manual', 'learnomy' ),
			),
			'content_type'     => array(
				'text'     => __( 'Text', 'learnomy' ),
				'video'    => __( 'Video', 'learnomy' ),
				'audio'    => __( 'Audio', 'learnomy' ),
				'pdf'      => __( 'PDF', 'learnomy' ),
				'embed'    => __( 'Embed', 'learnomy' ),
				'download' => __( 'Download', 'learnomy' ),
			),
		);

		$map = $vocab[ $set ] ?? array();
		return $map[ $key ] ?? ucwords( str_replace( array( '_', '-' ), ' ', $slug ) );
	}

	/**
	 * Render the canonical row-actions cell.
	 *
	 * Up to `$inline_max` actions flagged `primary` render inline as compact
	 * buttons; the rest collapse into a "⋮ More" overflow menu (one shared
	 * component, wired by assets/js/admin/row-actions.js). A row never wraps
	 * and a 2-action row looks like a 7-action row.
	 *
	 * Each action is an assoc array:
	 *   - label   (string, required)
	 *   - href    (string)        — renders an <a>; omit for a <button>
	 *   - icon    (string)        — Lucide icon name
	 *   - primary (bool)          — render inline rather than in the menu
	 *   - danger  (bool)          — danger styling (Delete etc.)
	 *   - target  (string)        — e.g. "_blank"
	 *   - attrs   (array)         — extra attributes, VERBATIM (preserves the
	 *                               data-* / class hooks existing JS binds to,
	 *                               e.g. ['class'=>'lrn-space-edit','data-id'=>5])
	 *
	 * @param array<int,array<string,mixed>> $actions   Action specs.
	 * @param array<string,mixed>            $args      'inline_max' (default 1),
	 *                                                   'menu_label' (a11y).
	 */
	public static function row_actions( array $actions, array $args = array() ): string {
		$actions = array_values( array_filter( $actions, static fn( $a ) => is_array( $a ) && ! empty( $a['label'] ) ) );
		if ( empty( $actions ) ) {
			return '<span class="lrn-muted">—</span>';
		}

		// Show ALL actions inline by default. Admin action columns are wide (and
		// the table scrolls horizontally on narrow screens), so hiding View /
		// Delete behind a ⋮ just reads as "no actions here" and confuses admins
		// about what they can do. A list can still opt into a ⋮ overflow by
		// passing an explicit `inline_max`, but the default never hides.
		$inline_max = isset( $args['inline_max'] ) ? max( 1, (int) $args['inline_max'] ) : count( $actions );
		$menu_label = $args['menu_label'] ?? __( 'More actions', 'learnomy' );

		// Keep actions in their authored order, but float any `primary`-flagged
		// ones to the front so the main action is always one of the visible
		// inline buttons. Show up to $inline_max inline; the rest overflow.
		// The ⋮ menu only appears when actions actually exceed the inline
		// budget — a 2-action row shows BOTH inline, never hides one behind ⋮.
		$primary = array();
		$rest    = array();
		foreach ( $actions as $a ) {
			if ( ! empty( $a['primary'] ) ) {
				$primary[] = $a;
			} else {
				$rest[] = $a;
			}
		}
		$ordered = array_merge( $primary, $rest );
		$inline  = array_slice( $ordered, 0, $inline_max );
		$menu    = array_slice( $ordered, $inline_max );

		$html = '<div class="lrn-row-actions">';

		foreach ( $inline as $a ) {
			$html .= self::render_action( $a, 'btn' );
		}

		if ( ! empty( $menu ) ) {
			$html .= '<div class="lrn-row-actions__menu" data-lrn-menu>';
			$html .= sprintf(
				// Bordered like its sibling buttons, not --ghost: a borderless
				// glyph between bordered buttons read as decoration, and owners
				// did not realise more actions lived behind it (2026-08-07).
				// The title repeats the accessible name as a hover hint.
				'<button type="button" class="lrn-admin-btn lrn-admin-btn--sm lrn-admin-btn--icon lrn-row-actions__trigger" aria-haspopup="true" aria-expanded="false" aria-label="%1$s" title="%1$s"><i data-lucide="ellipsis-vertical" aria-hidden="true"></i></button>',
				esc_attr( $menu_label )
			);
			$html .= '<div class="lrn-row-actions__panel" role="menu" hidden>';
			foreach ( $menu as $a ) {
				$html .= self::render_action( $a, 'menuitem' );
			}
			$html .= '</div></div>';
		}

		$html .= '</div>';
		return $html;
	}

	/**
	 * Render the canonical on/off switch — the single source for every boolean
	 * toggle in the Learnomy admin (Free + Pro). It wraps an ordinary
	 * `<input type="checkbox">` (name/value/checked unchanged) in the
	 * `.lrn-toggle` switch markup, so converting a raw checkbox to a switch is
	 * purely presentational: the existing save loop keeps reading the same
	 * `name`/`value`. Every settings boolean routes through here so the two
	 * divergent hand-rolled toggle vocabularies (Free track/thumb vs Pro Credly
	 * slider) can never reappear.
	 *
	 * @param array<string,mixed> $args {
	 *     @type string $name     Input name (required).
	 *     @type string $id       Input id; derived from name when omitted.
	 *     @type bool   $checked  Whether the switch is on.
	 *     @type string $value    Submitted value (default '1').
	 *     @type string $label    Visible label text beside the switch.
	 *     @type string $help     Help text rendered under the switch.
	 *     @type bool   $disabled Render disabled.
	 *     @type mixed  $unchecked_value Value submitted when the switch is off,
	 *                            emitted as a hidden companion field before the
	 *                            checkbox (default '0'). Pass `false` to skip it.
	 *                            REQUIRED for options saved through a loop that
	 *                            keys on the submitted array — without it an
	 *                            unchecked box is absent from $_POST and the "off"
	 *                            state silently never saves.
	 *     @type array  $attrs    Extra input attributes, emitted verbatim
	 *                            (escaped) — preserves data-* / class JS hooks
	 *                            (e.g. the Modules instant-save binding).
	 * }
	 */
	public static function toggle( array $args ): string {
		$name = (string) ( $args['name'] ?? '' );
		if ( '' === $name ) {
			return '';
		}

		$id       = (string) ( $args['id'] ?? 'lrn-toggle-' . preg_replace( '/[^a-z0-9_-]+/i', '-', $name ) );
		$value    = (string) ( $args['value'] ?? '1' );
		$checked  = ! empty( $args['checked'] );
		$disabled = ! empty( $args['disabled'] );
		$label    = (string) ( $args['label'] ?? '' );
		$help     = (string) ( $args['help'] ?? '' );

		$extra_attrs = isset( $args['attrs'] ) && is_array( $args['attrs'] ) ? $args['attrs'] : array();
		$attr_str    = '';
		foreach ( $extra_attrs as $k => $v ) {
			$attr_str .= sprintf( ' %s="%s"', esc_attr( (string) $k ), esc_attr( (string) $v ) );
		}

		// Hidden companion so an unchecked switch still submits an explicit
		// "off" value (guards the array_key_exists() save loop). Suppress with
		// unchecked_value === false for the rare instant-save/JS-only toggle.
		$unchecked = array_key_exists( 'unchecked_value', $args ) ? $args['unchecked_value'] : '0';
		$hidden    = false === $unchecked
			? ''
			: sprintf( '<input type="hidden" name="%s" value="%s">', esc_attr( $name ), esc_attr( (string) $unchecked ) );

		$input = $hidden . sprintf(
			'<input type="checkbox" id="%1$s" name="%2$s" value="%3$s"%4$s%5$s%6$s>',
			esc_attr( $id ),
			esc_attr( $name ),
			esc_attr( $value ),
			$checked ? ' checked' : '',
			$disabled ? ' disabled' : '',
			$attr_str
		);

		$label_span = '' !== $label
			? sprintf( '<span class="lrn-toggle__label">%s</span>', esc_html( $label ) )
			: '';

		$switch = sprintf(
			'<label class="lrn-toggle%1$s" for="%2$s">%3$s<span class="lrn-toggle__track"><span class="lrn-toggle__thumb"></span></span>%4$s</label>',
			$disabled ? ' lrn-toggle--disabled' : '',
			esc_attr( $id ),
			$input,
			$label_span
		);

		if ( '' !== $help ) {
			$switch .= sprintf( '<p class="description">%s</p>', esc_html( $help ) );
		}

		return $switch;
	}

	/**
	 * Render a single action as either an inline button (`btn`) or a menu row
	 * (`menuitem`). Output is fully escaped; `attrs` are emitted verbatim
	 * (escaped per key/value) so JS hooks survive.
	 *
	 * @param array<string,mixed> $a    Action spec.
	 * @param string              $mode 'btn' | 'menuitem'.
	 */
	private static function render_action( array $a, string $mode ): string {
		$is_link = ! empty( $a['href'] );
		$tag     = $is_link ? 'a' : 'button';

		$classes = 'menuitem' === $mode
			? array( 'lrn-row-actions__item' )
			: array( 'lrn-admin-btn', 'lrn-admin-btn--sm' );

		if ( ! empty( $a['danger'] ) ) {
			$classes[] = 'menuitem' === $mode ? 'lrn-row-actions__item--danger' : 'lrn-admin-btn--danger';
		}

		// Caller-supplied class (JS hook) merges in.
		$extra_attrs = isset( $a['attrs'] ) && is_array( $a['attrs'] ) ? $a['attrs'] : array();
		if ( ! empty( $extra_attrs['class'] ) ) {
			$classes[] = (string) $extra_attrs['class'];
			unset( $extra_attrs['class'] );
		}

		$attr_str = '';
		foreach ( $extra_attrs as $k => $v ) {
			$attr_str .= sprintf( ' %s="%s"', esc_attr( (string) $k ), esc_attr( (string) $v ) );
		}

		if ( $is_link ) {
			$attr_str .= sprintf( ' href="%s"', esc_url( (string) $a['href'] ) );
			if ( ! empty( $a['target'] ) ) {
				$attr_str .= sprintf( ' target="%s" rel="noopener"', esc_attr( (string) $a['target'] ) );
			}
			if ( 'menuitem' === $mode ) {
				$attr_str .= ' role="menuitem"';
			}
		} else {
			$attr_str .= ' type="button"';
			if ( 'menuitem' === $mode ) {
				$attr_str .= ' role="menuitem"';
			}
		}

		$icon = ! empty( $a['icon'] )
			? sprintf( '<i data-lucide="%s" aria-hidden="true"></i>', esc_attr( (string) $a['icon'] ) )
			: '';

		return sprintf(
			'<%1$s class="%2$s"%3$s>%4$s%5$s</%1$s>',
			$tag,
			esc_attr( implode( ' ', $classes ) ),
			$attr_str,
			$icon,
			esc_html( (string) $a['label'] )
		);
	}

	/**
	 * Empty-state help text for a taxonomy picker, told truthfully per role.
	 *
	 * The course / lesson / quiz / question editors are all open to instructors,
	 * but the Categories page they point at is registered manage_options. So the
	 * old shared line — "No tags yet. Create them under Categories → Tags, then
	 * assign them here." — sent an instructor to a page that answers "Sorry, you
	 * are not allowed to access this page" (Basecamp 10082715994), and asked them
	 * to do something their role cannot do anyway: the quick-add block right below
	 * each of these pickers is already gated on manage_options.
	 *
	 * Guard on the target page's REGISTERED capability, not Permission_Engine.
	 * WordPress enforces manage_options on the page itself, and Permission_Engine
	 * short-circuits on manage_options — so a user granted learnomy_manage_settings
	 * without it would pass a Permission_Engine check and still eat the 403.
	 *
	 * Lives here rather than in the four editors because it was already the same
	 * sentence in all four, and a role-aware version copy-pasted four times is how
	 * it drifts.
	 *
	 * @param string $label      Taxonomy label, e.g. "Tags".
	 * @param string $manage_url Admin URL of the Categories page for this taxonomy.
	 * @return string Escaped HTML, ready to echo inside a <p>.
	 */
	public static function taxonomy_empty_help( string $label, string $manage_url ): string {
		$label = strtolower( $label );

		if ( ! current_user_can( 'manage_options' ) ) {
			return sprintf(
				/* translators: %s: taxonomy label, e.g. "tags". */
				esc_html__( 'No %s yet. An administrator needs to create them before you can assign them here.', 'learnomy' ),
				esc_html( $label )
			);
		}

		return sprintf(
			/* translators: 1: taxonomy label, 2: link to the Categories admin page (Tags tab) */
			wp_kses_post( __( 'No %1$s yet. Create them under %2$s, then assign them here.', 'learnomy' ) ),
			esc_html( $label ),
			'<a href="' . esc_url( $manage_url ) . '">' . esc_html__( 'Categories &rarr; Tags', 'learnomy' ) . '</a>'
		);
	}

	/**
	 * The heading a page should carry: the words its own sidebar row uses.
	 *
	 * An owner clicks "Companion Plugins" and lands on a page headed
	 * "Integrations", clicks "Payment Webhook Log" and gets "Webhook Events",
	 * clicks "What's in Pro" and gets "Module catalog: Free and Pro" (Basecamp
	 * 10184393141). Every one of those menu labels was chosen deliberately
	 * during the 2026-07-25 IA pass so an owner could predict what a page
	 * contains - and then the headings, which live in the views, were left
	 * behind. The rename reads as half-done to exactly the person it was for.
	 *
	 * Fixing the four strings by hand would have them drift again at the next
	 * rename, because nothing connects a menu label to its page. So the page
	 * ASKS: this reads the label WordPress actually registered for the current
	 * submenu slug, which means renaming a row moves its heading for free and a
	 * future mismatch cannot be introduced without deleting this call.
	 *
	 * `$fallback` covers the cases the lookup cannot serve - a hidden page with
	 * no sidebar row, or a heading that is deliberately richer than its label.
	 *
	 * @param string $fallback Heading to use when no menu label is registered.
	 * @return string Unescaped; the caller escapes.
	 */
	public static function page_title( string $fallback = '' ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading the current screen, not acting on input.
		$slug = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( '' === $slug ) {
			return $fallback;
		}

		$submenu = $GLOBALS['submenu'] ?? array();
		if ( ! is_array( $submenu ) ) {
			return $fallback;
		}

		foreach ( $submenu as $rows ) {
			foreach ( (array) $rows as $row ) {
				// [0] = menu title, [2] = slug.
				if ( isset( $row[2], $row[0] ) && $slug === $row[2] ) {
					$raw = (string) $row[0];

					// Drop count bubbles WITH their contents. This block used to
					// be a bare wp_strip_all_tags() under a comment claiming it
					// stripped them — it removed the <span> and left the number
					// behind, so a page heading read "Reported Content 1" and
					// would read "Grading 15". A count is a sidebar affordance;
					// a heading that carries one renames the page every time the
					// queue changes length.
					$raw = (string) preg_replace(
						'#<span[^>]*class=["\'][^"\']*(?:count-|awaiting-mod|update-plugins|pending-count)[^"\']*["\'][^>]*>.*?</span>#is',
						'',
						$raw
					);

					// Texturized, because WordPress texturizes the sidebar label
					// when it renders it. Without this the heading reads
					// "What's in Pro" with a straight apostrophe beside a menu
					// row showing a curly one - the two ARE the same string, and
					// they still would not look identical.
					$label = wptexturize( wp_strip_all_tags( $raw ) );

					// Collapse whitespace the removed bubble left behind.
					$label = trim( (string) preg_replace( '/\s+/u', ' ', $label ) );

					return '' !== $label ? $label : $fallback;
				}
			}
		}

		return $fallback;
	}
}
