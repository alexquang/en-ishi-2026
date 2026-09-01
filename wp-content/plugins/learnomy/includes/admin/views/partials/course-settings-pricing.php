<?php
/**
 * Pricing & Access section — flexible pricing-model UI.
 *
 * PR-A1 (Migration 2.3.0) extends `lrn_courses` with a `pricing_model`
 * column whose canonical values cover six shapes:
 *
 *   - free                 — no purchase, no membership
 *   - one_time             — pay once, lifetime access
 *   - fixed_term           — pay once, access for N days
 *   - recurring            — pay every month / year
 *   - members_only         — gated behind a membership plan
 *   - one_time_or_members  — either path grants access
 *
 * Legacy `access_type` is kept in sync so older code paths keep
 * rendering correctly during the bridge period.
 *
 * @package Learnomy
 *
 * @var array        $course_settings
 * @var object|null  $course
 * @var bool         $is_edit
 */

defined( 'ABSPATH' ) || exit;

/*
 * Through the RESOLVER, never the raw column.
 *
 * This was the only raw `->pricing_model` read left in either plugin, and it
 * cost real money. `Course::pricing_model()` carries a stale-column guard: when
 * the column says 'free' but access_type disagrees, access_type wins. Reading
 * the column directly skipped that guard, so a course stored as
 * access_type='paid' / pricing_model='free' / price=49.00 — which the demo
 * seeder produces, because it writes access_type without pricing_model —
 * rendered with the FREE radio checked.
 *
 * That was not merely a wrong label. The hidden access_type mirror below is
 * derived from this variable, so the editor then POSTed access_type='free' for
 * a paid course, and normalize_free_pricing() zeroed the price. An owner who
 * opened a course with three paying customers and edited only its TITLE
 * silently made it free and wiped the 49.00 (Basecamp 10171334397).
 *
 * Every other consumer — storefront, checkout, enrollment guard, admin list,
 * admin detail — already reads the resolver and correctly said PAID. This one
 * screen dissented, and it was the one that could write.
 */
$current_pricing = $is_edit ? \Learnomy\Models\Course::pricing_model( $course ) : '';
$current_access  = $is_edit ? ( $course->access_type ?? 'free' ) : 'free';
$current_price   = $is_edit ? ( $course->price ?? '' ) : '';
// Show the sale field blank when there is no sale (NULL or a legacy 0), so an
// empty sale price never reads back as "0.00" (Basecamp #9981555911).
$current_sale    = ( $is_edit && ! empty( $course->sale_price ) ) ? $course->sale_price : '';
$current_days    = $is_edit ? ( $course->access_duration_days ?? '' ) : '';
$current_rec_amt = $is_edit ? ( $course->recurring_amount ?? '' ) : '';
$current_rec_int = $is_edit ? ( $course->recurring_interval ?? 'month' ) : 'month';
// Closed-model custom CTA (label + external URL), stored in settings_json.
$current_cta_label = $is_edit ? (string) \Learnomy\Models\Course::get_setting( (int) $course->id, 'closed_cta_label', '' ) : '';
$current_cta_url   = $is_edit ? (string) \Learnomy\Models\Course::get_setting( (int) $course->id, 'closed_cta_url', '' ) : '';
$visibility        = \Learnomy\Models\Course::visibility( $course_settings );

$sale_starts = $is_edit && ! empty( $course->sale_starts )
	? wp_date( 'Y-m-d', strtotime( $course->sale_starts ) )
	: '';
$sale_ends   = $is_edit && ! empty( $course->sale_ends )
	? wp_date( 'Y-m-d', strtotime( $course->sale_ends ) )
	: '';

// Derive the canonical pricing_model from access_type when the column
// is empty (course rows written before Migration 2.3.0 ran). This way
// the form always presents a populated radio set.
if ( '' === $current_pricing ) {
	$current_pricing = \Learnomy\Models\Course::model_for_access( (string) $current_access );
}

// Build the radio set, gating each option on the matching module so
// admins never see a path they cannot fulfil.
$kernel             = \Learnomy\Modules\Module_Kernel::instance();
$payments_enabled   = $kernel->is_enabled( 'payments' );
$membership_enabled = $kernel->is_enabled( 'membership' );

// Recurring subscription depends on Stripe being configured AND enabled —
// without a Stripe secret key the adapter cannot create the recurring price,
// so the option silently saved but never activated. Hide it until Stripe is
// actually ready. (Basecamp #9925572434.)
$stripe_ready = false;
if ( $payments_enabled && class_exists( '\\Learnomy\\Adapters\\Stripe_Adapter' ) ) {
	$stripe_ready = ( new \Learnomy\Adapters\Stripe_Adapter() )->is_available();
}

$pricing_options = array(
	'free' => array(
		'label'   => __( 'Free', 'learnomy' ),
		'help'    => __( 'Anyone signed in can enroll.', 'learnomy' ),
		'enabled' => true,
	),
);
if ( $payments_enabled ) {
	$pricing_options['one_time']   = array(
		'label'   => __( 'One-time payment (lifetime access)', 'learnomy' ),
		'help'    => __( 'Pay once, keep access forever.', 'learnomy' ),
		'enabled' => true,
	);
	$pricing_options['fixed_term'] = array(
		'label'   => __( 'One-time payment (time-limited access)', 'learnomy' ),
		'help'    => __( 'Access lasts a set number of days, then a Renew button appears.', 'learnomy' ),
		'enabled' => true,
	);
		// Recurring is always offered (so instructors know it exists) but is
		// disabled with guidance until Stripe is connected — Stripe is what
		// creates the recurring price (G2: don't silently hide a supported model).
		$pricing_options['recurring'] = array(
			'label'   => __( 'Recurring subscription', 'learnomy' ),
			'help'    => $stripe_ready
				? __( 'Customer pays every month or year while access remains active. Stripe creates the price automatically.', 'learnomy' )
				: __( 'Customer pays every month or year. Connect Stripe to enable this. Stripe creates the recurring price.', 'learnomy' ),
			'enabled' => $stripe_ready,
		);
}
if ( $membership_enabled ) {
	$pricing_options['members_only'] = array(
		'label'   => __( 'Members only', 'learnomy' ),
		'help'    => __( 'Needs an active membership that includes this course.', 'learnomy' ),
		'enabled' => true,
	);
}
if ( $payments_enabled && $membership_enabled ) {
	$pricing_options['one_time_or_members'] = array(
		'label'   => __( 'One-time payment OR Members', 'learnomy' ),
		'help'    => __( 'Buy outright, or use a membership.', 'learnomy' ),
		'enabled' => true,
	);
}
// Invite only - an administrator adds people, full stop. Not module-gated:
// a site that sells nothing still needs a way to say "staff are added, not
// enrolled". Kept SEPARATE from `closed` rather than folded into it, because
// the two ask the owner for different things: closed is "sold somewhere else,
// here is the link", invite-only needs no URL and no mention of selling. Same
// access rule underneath (access_type=closed), so nobody can self-enrol either
// way.
$pricing_options['invite_only'] = array(
	'label'   => __( 'Invite only (you add the students)', 'learnomy' ),
	'help'    => __( 'You add the students. Nothing is shown for sale.', 'learnomy' ),
	'enabled' => true,
);

// Closed / external access - NOT module-gated (that IS the point: no on-site
// sale, access granted from outside). Always available.
$pricing_options['closed'] = array(
	'label'   => __( 'Access granted externally (no on-site checkout)', 'learnomy' ),
	'help'    => __( 'Sold somewhere else. Optionally link to where.', 'learnomy' ),
	'enabled' => true,
);

// Pricing model is sticky once a course has paying customers — refuse to
// change it from the form. Admin can clone the course to relaunch with
// new pricing. (Clone tool lands in a follow-on PR.)
$pricing_locked = $is_edit && ! empty( $course->id )
	? \Learnomy\Models\Course::lock_pricing_model_if_purchased( (int) $course->id )
	: false;
$paying_count   = 0;
if ( $pricing_locked ) {
	$paying_count = \Learnomy\Models\Enrollment::count_paying_for_course( (int) $course->id );
}

// SS-3 preventive nudge: warn when a billable pricing model is chosen and
// no real-money gateway exists.
$lrn_show_gateway_warning = in_array( $current_pricing, array( 'one_time', 'fixed_term', 'recurring', 'one_time_or_members' ), true )
	&& ! \Learnomy\Services\Payment_Settings::has_any_gateway();
// The Test gateway can process a (simulated) purchase but isn't a real gateway,
// so it's excluded from has_any_gateway(). When it's the ONLY thing enabled,
// show a calm "test mode" note instead of the alarming "can't purchase" banner —
// the course IS demoable; the seller just needs a real gateway before launch.
$lrn_test_gateway_only     = $lrn_show_gateway_warning
	&& ! empty( ( get_option( 'learnomy_settings', array() ) )['enable_test_gateway'] );
$lrn_settings_payments_url = admin_url( 'admin.php?page=learnomy-settings#payments' );

// Keep the legacy <select name="access_type"> live so existing save-path
// validation keeps accepting the form (the controller normalises both
// columns server-side from `pricing_model`). The model->access mapping is the
// single source of truth on the Course model.
$mirror_access = \Learnomy\Models\Course::access_for_model( (string) $current_pricing );
?>
<section class="lrn-settings-section is-open" data-section="pricing">
	<button type="button" class="lrn-settings-section__header" aria-expanded="true">
		<span class="lrn-settings-section__heading">
			<i data-lucide="credit-card"></i>
			<span><?php esc_html_e( 'Pricing & Access', 'learnomy' ); ?></span>
		</span>
		<i data-lucide="chevron-down" class="lrn-settings-section__chevron"></i>
	</button>
	<div class="lrn-settings-section__body">
		<p class="lrn-settings-section__help">
			<?php esc_html_e( 'Choose how customers pay for this course.', 'learnomy' ); ?>
		</p>

		<?php
		// Explain WHY some pricing radios are absent — a disabled module silently
		// drops its option, which otherwise reads as a missing feature.
		$lrn_hidden_pricing = array();
		if ( ! $payments_enabled ) {
			$lrn_hidden_pricing[] = __( 'one-time & subscription pricing', 'learnomy' );
		}
		if ( ! $membership_enabled ) {
			$lrn_hidden_pricing[] = __( 'members-only pricing', 'learnomy' );
		}
		if ( $lrn_hidden_pricing ) :
			?>
			<p class="lrn-settings-section__help lrn-pricing-module-hint">
				<i data-lucide="info" aria-hidden="true"></i>
				<?php
				// The Modules page is manage_options. Enabling a module is a site-owner
				// decision, so an instructor gets the same information without a link
				// that would 403 on them.
				if ( current_user_can( 'manage_options' ) ) {
					printf(
						/* translators: %1$s: comma-separated list of hidden pricing options; %2$s: Modules page URL */
						wp_kses( __( 'More options (%1$s) appear here once you enable the matching module on the <a href="%2$s">Modules</a> page.', 'learnomy' ), array( 'a' => array( 'href' => array() ) ) ),
						esc_html( implode( ', ', $lrn_hidden_pricing ) ),
						esc_url( admin_url( 'admin.php?page=learnomy-modules' ) )
					);
				} else {
					printf(
						/* translators: %s: comma-separated list of hidden pricing options */
						esc_html__( 'More options (%s) appear here once an administrator enables the matching module.', 'learnomy' ),
						esc_html( implode( ', ', $lrn_hidden_pricing ) )
					);
				}
				?>
			</p>
		<?php endif; ?>

		<?php if ( $pricing_locked ) : ?>
			<div class="notice notice-info inline lrn-pricing-locked-banner">
				<p>
					<?php
					printf(
						/* translators: %d: number of paying customers */
						esc_html( _n( '%d customer has purchased this course at the current pricing. Changing the pricing model would leave them in a half-state. To relaunch with new pricing, clone the course.', '%d customers have purchased this course at the current pricing. Changing the pricing model would leave them in a half-state. To relaunch with new pricing, clone the course.', $paying_count, 'learnomy' ) ),
						(int) $paying_count
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<fieldset class="lrn-form-field lrn-pricing-radio-group" <?php disabled( $pricing_locked ); ?>>
			<legend class="lrn-form-field__legend"><?php esc_html_e( 'Pricing model', 'learnomy' ); ?></legend>
			<?php
			foreach ( $pricing_options as $val => $option ) :
				$lrn_opt_disabled = $pricing_locked || empty( $option['enabled'] );
				?>
				<label class="lrn-radio-label<?php echo $lrn_opt_disabled && empty( $option['enabled'] ) ? ' lrn-radio-label--disabled' : ''; ?>" for="lrn-pricing-<?php echo esc_attr( $val ); ?>">
					<input type="radio"
						id="lrn-pricing-<?php echo esc_attr( $val ); ?>"
						name="pricing_model"
						value="<?php echo esc_attr( $val ); ?>"
						<?php checked( $current_pricing, $val ); ?>
						<?php disabled( $lrn_opt_disabled ); ?>>
					<strong><?php echo esc_html( $option['label'] ); ?></strong>
					<span class="lrn-form-help lrn-radio-label__help">
						<?php echo esc_html( $option['help'] ); ?>
					</span>
				</label>
			<?php endforeach; ?>
		</fieldset>

		<?php
		// Live plain-English summary of what a student will experience,
		// composed from visibility + pricing. Updates as the owner toggles
		// (course-pricing.js) so a misconfigured combo is caught before save.
		?>
		<p class="lrn-access-summary" role="status" aria-live="polite">
			<i data-lucide="info" aria-hidden="true"></i>
			<span id="lrn-access-summary-text"><?php echo esc_html( \Learnomy\Models\Course::compose_access_summary( (string) $visibility, (string) $current_pricing ) ); ?></span>
		</p>

		<?php // Hidden access_type mirror keeps the legacy save path working. ?>
		<input type="hidden" name="access_type" id="lrn-course-access" value="<?php echo esc_attr( $mirror_access ); ?>">

		<?php
		// Payment gateways are configured on Settings → Payments, which is
		// registered manage_options. An instructor still needs to KNOW their paid
		// course can't take money — that is their problem too — but the fix is not
		// theirs to make, and the CTA 403'd for them (Basecamp 10082715894). So
		// they get the same warning addressed to what they can actually do about
		// it: ask the site administrator.
		$lrn_can_configure_payments = current_user_can( 'manage_options' );
		?>
		<?php if ( $lrn_test_gateway_only ) : ?>
			<div class="notice notice-info inline lrn-pricing-gateway-warning">
				<p>
					<?php if ( $lrn_can_configure_payments ) : ?>
						<?php esc_html_e( 'Test gateway active. Purchases are simulated, so you can demo checkout now. Connect Stripe or PayPal before you launch.', 'learnomy' ); ?>
						<a href="<?php echo esc_url( $lrn_settings_payments_url ); ?>">
							<?php esc_html_e( 'Connect a live gateway', 'learnomy' ); ?>
						</a>
					<?php else : ?>
						<?php esc_html_e( 'Test gateway active. Purchases are simulated, so you can demo checkout now. An administrator needs to connect a live gateway before this course can take real payments.', 'learnomy' ); ?>
					<?php endif; ?>
				</p>
			</div>
		<?php elseif ( $lrn_show_gateway_warning ) : ?>
			<div class="notice notice-warning inline lrn-pricing-gateway-warning">
				<p>
					<?php if ( $lrn_can_configure_payments ) : ?>
						<?php esc_html_e( 'No payment gateway is configured. Customers cannot purchase this course until you connect one.', 'learnomy' ); ?>
						<a href="<?php echo esc_url( $lrn_settings_payments_url ); ?>">
							<?php esc_html_e( 'Configure payment gateway', 'learnomy' ); ?>
						</a>
					<?php else : ?>
						<?php esc_html_e( 'No payment gateway is configured, so customers cannot purchase this course yet. Ask an administrator to connect one.', 'learnomy' ); ?>
					<?php endif; ?>
				</p>
			</div>
		<?php endif; ?>

		<div class="lrn-pricing-fields" data-shows-for="one_time fixed_term one_time_or_members">
			<div class="lrn-form-grid lrn-form-grid--2">
				<div class="lrn-form-field">
					<label for="lrn-course-price"><?php esc_html_e( 'Regular price', 'learnomy' ); ?></label>
					<input type="number" id="lrn-course-price" name="price" min="0" step="0.01"
						value="<?php echo esc_attr( (string) $current_price ); ?>"
						class="small-text" placeholder="0.00">
				</div>
				<div class="lrn-form-field">
					<label for="lrn-course-sale-price"><?php esc_html_e( 'Sale price', 'learnomy' ); ?></label>
					<input type="number" id="lrn-course-sale-price" name="sale_price" min="0" step="0.01"
						value="<?php echo esc_attr( (string) $current_sale ); ?>"
						class="small-text" placeholder="0.00">
				</div>
			</div>

			<div class="lrn-form-grid lrn-form-grid--2">
				<div class="lrn-form-field">
					<label for="lrn-course-sale-start"><?php esc_html_e( 'Sale starts', 'learnomy' ); ?></label>
					<input type="date" id="lrn-course-sale-start" name="sale_starts"
						value="<?php echo esc_attr( $sale_starts ); ?>">
				</div>
				<div class="lrn-form-field">
					<label for="lrn-course-sale-end"><?php esc_html_e( 'Sale ends', 'learnomy' ); ?></label>
					<input type="date" id="lrn-course-sale-end" name="sale_ends"
						value="<?php echo esc_attr( $sale_ends ); ?>">
				</div>
			</div>
		</div>

		<div class="lrn-pricing-fields" data-shows-for="fixed_term">
			<div class="lrn-form-field">
				<label for="lrn-course-access-days"><?php esc_html_e( 'Access duration (days)', 'learnomy' ); ?></label>
				<input type="number" id="lrn-course-access-days" name="access_duration_days" min="1" step="1"
					value="<?php echo esc_attr( (string) $current_days ); ?>"
					class="small-text" placeholder="365">
				<p class="lrn-form-help"><?php esc_html_e( 'How long the customer keeps access after purchase. Customers see a Renew button when access expires.', 'learnomy' ); ?></p>
			</div>
		</div>

		<div class="lrn-pricing-fields" data-shows-for="recurring">
			<div class="lrn-form-grid lrn-form-grid--2">
				<div class="lrn-form-field">
					<label for="lrn-course-recurring-amount"><?php esc_html_e( 'Recurring amount', 'learnomy' ); ?></label>
					<input type="number" id="lrn-course-recurring-amount" name="recurring_amount" min="0" step="0.01"
						value="<?php echo esc_attr( (string) $current_rec_amt ); ?>"
						class="small-text" placeholder="29.00">
				</div>
				<div class="lrn-form-field">
					<label for="lrn-course-recurring-interval"><?php esc_html_e( 'Billing interval', 'learnomy' ); ?></label>
					<select id="lrn-course-recurring-interval" name="recurring_interval">
						<option value="month" <?php selected( $current_rec_int, 'month' ); ?>><?php esc_html_e( 'Monthly', 'learnomy' ); ?></option>
						<option value="year" <?php selected( $current_rec_int, 'year' ); ?>><?php esc_html_e( 'Yearly', 'learnomy' ); ?></option>
					</select>
				</div>
			</div>
			<p class="lrn-form-help">
				<?php esc_html_e( 'Stripe creates the matching price on first purchase. PayPal recurring courses are not supported yet.', 'learnomy' ); ?>
			</p>
		</div>

		<div class="lrn-pricing-fields" data-shows-for="invite_only">
			<p class="lrn-form-help">
				<?php esc_html_e( 'Add people under Course > Students, one at a time or by CSV. There is nothing else to configure - no price, no link, and no checkout.', 'learnomy' ); ?>
			</p>
		</div>

		<div class="lrn-pricing-fields" data-shows-for="closed">
			<p class="lrn-form-help">
				<?php esc_html_e( 'Students get in by: adding them under Course > Students (manually or by CSV), or connecting an external system on Settings > Webhooks.', 'learnomy' ); ?>
			</p>
			<div class="lrn-form-grid lrn-form-grid--2">
				<div class="lrn-form-field">
					<label for="lrn-course-closed-cta-label"><?php esc_html_e( 'Custom button label (optional)', 'learnomy' ); ?></label>
					<input type="text" id="lrn-course-closed-cta-label" name="closed_cta_label"
						value="<?php echo esc_attr( $current_cta_label ); ?>"
						class="regular-text" placeholder="<?php esc_attr_e( 'Enroll externally', 'learnomy' ); ?>">
				</div>
				<div class="lrn-form-field">
					<label for="lrn-course-closed-cta-url"><?php esc_html_e( 'Custom button URL (optional)', 'learnomy' ); ?></label>
					<input type="url" id="lrn-course-closed-cta-url" name="closed_cta_url"
						value="<?php echo esc_attr( $current_cta_url ); ?>"
						class="regular-text" placeholder="https://your-store.example/checkout">
				</div>
			</div>
			<p class="lrn-form-help">
				<?php esc_html_e( 'If a URL is set, the course page shows this button linking to where the course is sold. Leave both blank to show a plain "Enrollment is managed externally" note.', 'learnomy' ); ?>
			</p>
		</div>

		<div class="lrn-form-field">
			<label for="lrn-course-visibility"><?php esc_html_e( 'Visibility', 'learnomy' ); ?></label>
			<select id="lrn-course-visibility" name="visibility">
				<option value="public" <?php selected( $visibility, 'public' ); ?>>
					<?php esc_html_e( 'Public - listed in the catalog, anyone can enroll', 'learnomy' ); ?>
				</option>
				<option value="unlisted" <?php selected( $visibility, 'unlisted' ); ?>>
					<?php esc_html_e( 'Unlisted - hidden from the catalog, anyone with the link can enroll', 'learnomy' ); ?>
				</option>
				<option value="private" <?php selected( $visibility, 'private' ); ?>>
					<?php esc_html_e( 'Private - hidden from the catalog, only enrolled students and admins can open it', 'learnomy' ); ?>
				</option>
			</select>
			<p class="lrn-form-help"><?php esc_html_e( 'Choose Unlisted to share a course by direct link without putting it in the public catalog. Choose Private to restrict it to people you enroll.', 'learnomy' ); ?></p>
		</div>
	</div>
</section>

