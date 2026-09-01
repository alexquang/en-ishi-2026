<?php
/**
 * Setup wizard — standalone 5-step onboarding.
 *
 * No WP admin chrome. Exits before wp_footer so the admin toolbar and
 * admin.css never load. Styles and JS are inlined: this page only runs
 * during install/re-run, so the overhead never reaches normal admin pages.
 *
 * Steps: Welcome · Configure · Payment · Demo · Success.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Recommended
$lrn_is_rerun = isset( $_GET['rerun'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['rerun'] ) );
// phpcs:enable WordPress.Security.NonceVerification.Recommended

$lrn_settings    = get_option( 'learnomy_settings', array() );
$lrn_demo_exists = (bool) get_option( 'learnomy_demo_data', false );

$lrn_base_slug       = isset( $lrn_settings['base_slug'] ) && $lrn_settings['base_slug']
	? $lrn_settings['base_slug']
	: 'courses';
$lrn_currency        = isset( $lrn_settings['currency'] ) && $lrn_settings['currency']
	? $lrn_settings['currency']
	: 'USD';
$lrn_enrollment_mode = isset( $lrn_settings['enrollment_mode'] ) && $lrn_settings['enrollment_mode']
	? $lrn_settings['enrollment_mode']
	: 'open';
$lrn_payment_mode    = isset( $lrn_settings['payment_mode'] ) ? $lrn_settings['payment_mode'] : 'sandbox';
// The canonical stored key is `stripe_test_publishable` (no `_key` suffix) — align with
// sanitize_settings() in class-admin.php (line 1195) and learnomy.php defaults.
$lrn_stripe_pk = isset( $lrn_settings['stripe_test_publishable'] ) ? (string) $lrn_settings['stripe_test_publishable'] : '';
$lrn_stripe_sk = isset( $lrn_settings['stripe_test_secret'] ) ? (string) $lrn_settings['stripe_test_secret'] : '';

$lrn_current_user = wp_get_current_user();
$lrn_first_name   = '';
if ( $lrn_current_user ) {
	$lrn_first_name = $lrn_current_user->first_name ? $lrn_current_user->first_name : $lrn_current_user->display_name;
}

$rest_nonce = wp_create_nonce( 'wp_rest' );
$rest_url   = rest_url( 'learnomy/v1/' );
$home_url   = trailingslashit( home_url() );

/*
 * Learnomy is route-based: it creates NO WordPress "pages". The catalog,
 * account area, etc. are rewrite routes that exist the moment the plugin is
 * active. Surface the two key route paths (from the Route Registry, never
 * hardcoded) so a first-run owner isn't left hunting for pages that don't
 * exist. Card 10062950092.
 */
$lrn_catalog_path = wp_parse_url( \Learnomy\route_url( 'catalog' ), PHP_URL_PATH );
$lrn_account_path = wp_parse_url( \Learnomy\route_url( 'student-dashboard' ), PHP_URL_PATH );
$lrn_catalog_path = is_string( $lrn_catalog_path ) ? $lrn_catalog_path : '/courses/';
$lrn_account_path = is_string( $lrn_account_path ) ? $lrn_account_path : '/account/';

// Create-screen URLs for the post-setup "next steps" signposts (Step 5). These
// point at the real admin editors so an owner whose preset enabled a module
// (but seeded no content) has a one-click path to fill it, instead of landing
// on a confusing empty list.
$lrn_new_course_url = admin_url( 'admin.php?page=learnomy-new-course' );
$lrn_new_quiz_url   = admin_url( 'admin.php?page=learnomy-edit-quiz' );
$lrn_new_plan_url   = admin_url( 'admin.php?page=learnomy-membership-edit' );
$lrn_coupons_url    = admin_url( 'admin.php?page=learnomy-coupons' );
// Internal-training path: the first thing that owner needs is their PEOPLE in
// the system, not a shopfront. Students is where they add them and where the
// bulk enrol-by-email lives.
$lrn_students_url = admin_url( 'admin.php?page=learnomy-students' );
// School/team-seats path: seats are sold via Spaces (Pro). When the Spaces
// extension is loaded the signpost deep-links its Space Plans admin; on a
// Free-only site it links the bundled What's in Pro catalog - an honest
// upsell with a real destination, never a dead screen. Guarded class check
// per Rule 9 (Free must never assume Pro).
$lrn_spaces_ready = class_exists( '\\Learnomy_Pro\\Extensions\\Spaces\\Extension' );
$lrn_spaces_url   = admin_url(
	$lrn_spaces_ready ? 'admin.php?page=learnomy-space-plans' : 'admin.php?page=learnomy-pro-features'
);

/*
 * This is a standalone full-page view: it renders its own HTML document and
 * exits before `wp_footer`, so the normal admin enqueue stack never runs here.
 * Register and enqueue the page's own assets, then emit them through WP's
 * script/style printers (`wp_print_styles()` in the head, `wp_print_footer_scripts()`
 * before `</body>`) instead of hand-writing `<link>`/`<script>` tags. That keeps
 * the wizard's assets off every other admin page while still going through the
 * proper pipeline (versioning, dependency ordering, no raw inline asset tags).
 * Handles match the shared registrations in class-admin.php so there is no
 * collision if those ever load first.
 */
$lrn_ver = LEARNOMY_VERSION;

wp_register_style( 'learnomy-setup-wizard', LEARNOMY_URL . 'assets/css/admin/setup-wizard.css', array(), $lrn_ver );
wp_register_style( 'learnomy-confirm-modal', LEARNOMY_URL . 'assets/css/shared/confirm-modal.css', array(), $lrn_ver );
wp_enqueue_style( 'learnomy-setup-wizard' );
wp_enqueue_style( 'learnomy-confirm-modal' );

wp_register_script( 'lucide', LEARNOMY_URL . 'assets/js/vendor/lucide.min.js', array(), '0.460.0', true );
wp_register_script( 'learnomy-confetti', LEARNOMY_URL . 'assets/js/vendor/confetti.min.js', array(), $lrn_ver, true );
wp_register_script( 'learnomy-toast', LEARNOMY_URL . 'assets/js/shared/toast.js', array( 'wp-i18n' ), $lrn_ver, true );
wp_register_script( 'learnomy-confirm-modal', LEARNOMY_URL . 'assets/js/shared/confirm-modal.js', array( 'wp-i18n' ), $lrn_ver, true );
wp_register_script(
	'learnomy-setup-wizard',
	LEARNOMY_URL . 'assets/js/admin/setup-wizard.js',
	array( 'lucide', 'learnomy-confetti', 'learnomy-toast', 'learnomy-confirm-modal' ),
	$lrn_ver,
	true
);
wp_enqueue_script( 'learnomy-setup-wizard' );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php esc_html_e( 'Set Up Learnomy', 'learnomy' ); ?></title>
	<?php wp_print_styles(); ?>
</head>
<body class="lrn-setup-body">
<div class="lrn-wiz">

	<aside class="lrn-wiz__rail">
		<div class="lrn-wiz__brand">
			<div class="lrn-wiz__brand-mark">
				<img src="<?php echo esc_url( \Learnomy\logo_url( 'mark' ) ); ?>" width="44" height="44" alt="" aria-hidden="true" class="lrn-wiz__brand-img" />
			</div>
			<span class="lrn-wiz__brand-text">Learnomy</span>
		</div>

		<div class="lrn-wiz__quote">
			<div class="lrn-wiz__quote-mark">&ldquo;</div>
			<p class="lrn-wiz__quote-body">
				<?php esc_html_e( 'The best academies are launched, not planned forever. Get your classroom online today.', 'learnomy' ); ?>
			</p>
			<p class="lrn-wiz__quote-author">
				<?php esc_html_e( 'Learnomy team', 'learnomy' ); ?>
			</p>
		</div>

		<p class="lrn-wiz__copyright">
			<?php
			printf(
				/* translators: %s: current year */
				esc_html__( '© %s Learnomy. Built for teachers who ship.', 'learnomy' ),
				esc_html( gmdate( 'Y' ) )
			);
			?>
		</p>
	</aside>

	<section class="lrn-wiz__pane">
		<div class="lrn-wiz__inner">

			<nav class="lrn-chips" id="lrn-chips" aria-label="<?php esc_attr_e( 'Setup progress', 'learnomy' ); ?>">
				<button type="button" class="lrn-chip" data-chip="1" aria-label="<?php esc_attr_e( 'Welcome', 'learnomy' ); ?>">
					<i data-lucide="sparkles" aria-hidden="true"></i>
					<span><?php esc_html_e( 'Welcome', 'learnomy' ); ?></span>
				</button>
				<span class="lrn-chip__sep" aria-hidden="true"></span>
				<button type="button" class="lrn-chip" data-chip="2" aria-label="<?php esc_attr_e( 'Configure', 'learnomy' ); ?>">
					<i data-lucide="settings-2" aria-hidden="true"></i>
					<span><?php esc_html_e( 'Configure', 'learnomy' ); ?></span>
				</button>
				<span class="lrn-chip__sep" aria-hidden="true"></span>
				<button type="button" class="lrn-chip" data-chip="3" aria-label="<?php esc_attr_e( 'Payment', 'learnomy' ); ?>">
					<i data-lucide="credit-card" aria-hidden="true"></i>
					<span><?php esc_html_e( 'Payment', 'learnomy' ); ?></span>
				</button>
				<span class="lrn-chip__sep" aria-hidden="true"></span>
				<button type="button" class="lrn-chip" data-chip="4" aria-label="<?php esc_attr_e( 'Demo', 'learnomy' ); ?>">
					<i data-lucide="package-open" aria-hidden="true"></i>
					<span><?php esc_html_e( 'Demo', 'learnomy' ); ?></span>
				</button>
				<span class="lrn-chip__sep" aria-hidden="true"></span>
				<button type="button" class="lrn-chip" data-chip="5" aria-label="<?php esc_attr_e( 'Success', 'learnomy' ); ?>">
					<i data-lucide="party-popper" aria-hidden="true"></i>
					<span><?php esc_html_e( 'Done', 'learnomy' ); ?></span>
				</button>
			</nav>

			<div class="lrn-notice" id="lrn-notice" role="alert"></div>

			<!-- ── Step 1: Welcome ──────────────────────────────────── -->
			<div class="lrn-step" data-step="1">
				<div class="lrn-step__icon"><i data-lucide="sparkles" aria-hidden="true"></i></div>
				<h2><?php esc_html_e( 'Welcome to Learnomy', 'learnomy' ); ?></h2>
				<p class="lrn-step__lede">
					<?php esc_html_e( 'Set up your academy in 5 minutes. Configure the basics, optionally connect payments, and load a ready-made school to explore.', 'learnomy' ); ?>
				</p>

				<div class="lrn-illus" aria-hidden="true">
					<svg viewBox="0 0 320 180" xmlns="http://www.w3.org/2000/svg" fill="none">
						<rect x="24" y="46" width="78" height="52" rx="8" fill="#ffffff" stroke="#d1e4f8" stroke-width="1.5"/>
						<rect x="32" y="56" width="44" height="4" rx="2" fill="#2383e2" opacity="0.7"/>
						<rect x="32" y="66" width="60" height="3" rx="1.5" fill="#d1e4f8"/>
						<rect x="32" y="74" width="52" height="3" rx="1.5" fill="#d1e4f8"/>
						<rect x="218" y="84" width="78" height="52" rx="8" fill="#ffffff" stroke="#d1e4f8" stroke-width="1.5"/>
						<rect x="226" y="94" width="40" height="4" rx="2" fill="#2383e2" opacity="0.7"/>
						<rect x="226" y="104" width="60" height="3" rx="1.5" fill="#d1e4f8"/>
						<rect x="226" y="112" width="46" height="3" rx="1.5" fill="#d1e4f8"/>
						<path d="M160 42 L112 66 L160 90 L208 66 Z" fill="#2383e2"/>
						<path d="M130 76 L130 100 Q160 112 190 100 L190 76" stroke="#2383e2" stroke-width="3" stroke-linecap="round" fill="none"/>
						<line x1="208" y1="66" x2="208" y2="96" stroke="#2383e2" stroke-width="2.5" stroke-linecap="round"/>
						<circle cx="208" cy="100" r="4" fill="#2383e2"/>
					</svg>
				</div>

				<div class="lrn-check-list">
					<div class="lrn-check-list__item">
						<span class="lrn-check-list__icon"><i data-lucide="check" aria-hidden="true"></i></span>
						<?php esc_html_e( 'Currency & catalog URL', 'learnomy' ); ?>
					</div>
					<div class="lrn-check-list__item">
						<span class="lrn-check-list__icon"><i data-lucide="check" aria-hidden="true"></i></span>
						<?php esc_html_e( 'Optional Stripe', 'learnomy' ); ?>
					</div>
					<div class="lrn-check-list__item">
						<span class="lrn-check-list__icon"><i data-lucide="check" aria-hidden="true"></i></span>
						<?php esc_html_e( 'Demo academy, pre-populated', 'learnomy' ); ?>
					</div>
				</div>

				<div class="lrn-note">
					<span class="lrn-note__icon"><i data-lucide="route" aria-hidden="true"></i></span>
					<div class="lrn-note__body">
						<p class="lrn-note__title"><?php esc_html_e( 'No pages to build - Learnomy runs on URL routes.', 'learnomy' ); ?></p>
						<p class="lrn-note__text"><?php esc_html_e( "You won't create any WordPress pages. Your academy is served at ready-made addresses the moment setup finishes:", 'learnomy' ); ?></p>
						<div class="lrn-note__routes">
							<span class="lrn-note__route">
								<code><?php echo esc_html( $lrn_catalog_path ); ?></code>
								<?php esc_html_e( 'course catalog', 'learnomy' ); ?>
							</span>
							<span class="lrn-note__route">
								<code><?php echo esc_html( $lrn_account_path ); ?></code>
								<?php esc_html_e( 'student account area', 'learnomy' ); ?>
							</span>
						</div>
					</div>
				</div>

				<div class="lrn-actions">
					<button type="button" class="lrn-btn lrn-btn--ghost" id="lrn-skip-btn">
						<?php esc_html_e( 'Skip setup', 'learnomy' ); ?>
					</button>
					<button type="button" class="lrn-btn lrn-btn--primary" data-go="2">
						<?php esc_html_e( 'Get Started', 'learnomy' ); ?>
						<i data-lucide="arrow-right" aria-hidden="true"></i>
					</button>
				</div>
			</div>

			<!-- ── Step 2: Configure ────────────────────────────────── -->
			<div class="lrn-step" data-step="2">
				<div class="lrn-step__icon"><i data-lucide="settings-2" aria-hidden="true"></i></div>
				<h2><?php esc_html_e( 'Configure your academy', 'learnomy' ); ?></h2>
				<p class="lrn-step__lede"><?php esc_html_e( 'You can change all of these later in Settings > General.', 'learnomy' ); ?></p>

				<div class="lrn-field" id="lrn-field-slug">
					<label for="lrn-base-slug"><?php esc_html_e( 'Course catalog slug', 'learnomy' ); ?></label>
					<input type="text" id="lrn-base-slug"
						value="<?php echo esc_attr( $lrn_base_slug ); ?>"
						placeholder="courses" maxlength="60" autocomplete="off">
					<p class="lrn-field-hint">
						<?php esc_html_e( 'Your catalog will live at', 'learnomy' ); ?>
						<span class="lrn-preview-chip" id="lrn-slug-preview"><?php echo esc_html( $home_url . $lrn_base_slug . '/' ); ?></span>
					</p>
					<p class="lrn-field-hint lrn-field-hint--note">
						<i data-lucide="info" aria-hidden="true"></i>
						<?php esc_html_e( 'This is a route, not a page - it works automatically, with no WordPress page to create.', 'learnomy' ); ?>
					</p>
					<p class="lrn-field-error">
						<?php esc_html_e( 'Use lowercase letters, numbers, and hyphens only.', 'learnomy' ); ?>
					</p>
				</div>

				<div class="lrn-field">
					<label for="lrn-currency"><?php esc_html_e( 'Currency', 'learnomy' ); ?></label>
					<select id="lrn-currency">
						<?php
						/*
						 * Built from the shared helpers. This was a hand-picked list with
						 * flag emoji, which meant the wizard, the settings screen and the
						 * charge each knew a different set of currencies. The flags went
						 * with it: several currencies are used by many countries (EUR, XOF,
						 * XAF) and one flag misrepresents the rest.
						 */
						$lrn_currency_opts = array();
						$lrn_wizard_names  = \Learnomy\currency_names();
						$lrn_wizard_syms   = \Learnomy\currency_symbols();
						foreach ( \Learnomy\supported_currencies() as $lrn_wc ) {
							$lrn_wizard_name              = $lrn_wizard_names[ $lrn_wc ] ?? $lrn_wc;
							$lrn_wizard_sym               = $lrn_wizard_syms[ $lrn_wc ] ?? '';
							$lrn_currency_opts[ $lrn_wc ] = ( '' !== $lrn_wizard_sym && $lrn_wizard_sym !== $lrn_wc )
								? $lrn_wc . ' - ' . $lrn_wizard_name . ' (' . $lrn_wizard_sym . ')'
								: $lrn_wc . ' - ' . $lrn_wizard_name;
						}
						foreach ( $lrn_currency_opts as $lrn_code => $lrn_label ) :
							?>
							<option value="<?php echo esc_attr( $lrn_code ); ?>" <?php selected( $lrn_currency, $lrn_code ); ?>>
								<?php echo esc_html( $lrn_label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>

				<?php
				// Enrollment Mode tiles removed UNTIL BUILT (half-cooked audit
				// F1): 'Approval required' promised sign-off the enroll path
				// never enforced — the worst place to make that promise is the
				// owner's very first five minutes. Stored value + default stay
				// (see settings.php note); the wizard payload simply no longer
				// sends the key.
				?>
				<?php
				// Preset picker — single-decision feature selection. Each tile
				// maps to a curated module list via
				// Module_Kernel::apply_preset(). Operators can fine-tune later
				// on the Modules page; this is the starting point.
				$lrn_presets        = \Learnomy\Modules\Module_Kernel::presets();
				$lrn_preset_icons   = array(
					'simple'     => 'book-open',
					'standard'   => 'graduation-cap',
					'internal'   => 'building-2',
					'paid'       => 'credit-card',
					'membership' => 'repeat',
					'school'     => 'school',
					'custom'     => 'sliders-horizontal',
				);
				$lrn_default_preset = 'standard';

				/*
				 * Map each preset to the "next step" signposts it should show on the
				 * success screen. Driven from the preset's own module list (the single
				 * source) — a preset that turns a module ON but seeds no content gets a
				 * one-click link to create the first item, so the owner never lands on a
				 * bare empty list wondering if it's broken. Every preset gets `course`
				 * (courses are always on); quiz/membership/coupon appear only when the
				 * matching module is enabled. Card 10062950092.
				 */
				$lrn_signpost_map = array();
				foreach ( $lrn_presets as $lrn_ps_slug => $lrn_ps ) {
					$lrn_ps_modules = (array) ( $lrn_ps['modules'] ?? array() );
					$lrn_ps_items   = array( 'course' );
					if ( in_array( 'quizzes', $lrn_ps_modules, true ) ) {
						$lrn_ps_items[] = 'quiz';
					}
					if ( in_array( 'membership', $lrn_ps_modules, true ) ) {
						$lrn_ps_items[] = 'membership';
					}
					if ( in_array( 'coupons', $lrn_ps_modules, true ) ) {
						$lrn_ps_items[] = 'coupon';
					}
					// Internal training is the one path whose first move is
					// people, not product: nothing is on sale, so "add your
					// team" is the step that makes the site real.
					if ( 'internal' === $lrn_ps_slug ) {
						$lrn_ps_items[] = 'team';
					}
					// School/team seats: the product that does the job is
					// Spaces (Pro), so the signpost is the route to it - the
					// Space Plans admin when Spaces is active, the What's in
					// Pro catalog when it is not. Either way the owner who
					// picked this tile lands somewhere that continues their
					// job instead of having to discover Spaces by accident
					// (Basecamp 10132713132).
					if ( 'school' === $lrn_ps_slug ) {
						$lrn_ps_items[] = 'spaces';
					}
					$lrn_signpost_map[ $lrn_ps_slug ] = $lrn_ps_items;
				}
				?>
				<div class="lrn-field" id="lrn-field-preset">
					<label><?php esc_html_e( 'What kind of academy?', 'learnomy' ); ?></label>
					<p class="lrn-field-hint lrn-field-hint--gap">
						<?php esc_html_e( 'Pick the closest match. We will enable only the relevant modules so your sidebar stays focused. You can change any of this later in Modules.', 'learnomy' ); ?>
					</p>
					<div class="lrn-tiles lrn-tiles--preset" data-radio="preset">
						<?php foreach ( $lrn_presets as $lrn_preset_slug => $lrn_preset ) : ?>
							<div class="lrn-tile <?php echo $lrn_preset_slug === $lrn_default_preset ? 'is-selected' : ''; ?>"
								data-value="<?php echo esc_attr( $lrn_preset_slug ); ?>"
								tabindex="0" role="radio"
								aria-checked="<?php echo $lrn_preset_slug === $lrn_default_preset ? 'true' : 'false'; ?>">
								<?php if ( 'standard' === $lrn_preset_slug ) : ?>
									<span class="lrn-tile__pill"><?php esc_html_e( 'Recommended', 'learnomy' ); ?></span>
								<?php endif; ?>
								<div class="lrn-tile__icon">
									<i data-lucide="<?php echo esc_attr( $lrn_preset_icons[ $lrn_preset_slug ] ?? 'package' ); ?>" aria-hidden="true"></i>
								</div>
								<h3><?php echo esc_html( $lrn_preset['name'] ); ?></h3>
								<p>
									<?php echo esc_html( $lrn_preset['description'] ); ?>
								</p>
							</div>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="lrn-actions">
					<button type="button" class="lrn-btn lrn-btn--outline" data-go="1">
						<i data-lucide="arrow-left" aria-hidden="true"></i>
						<?php esc_html_e( 'Back', 'learnomy' ); ?>
					</button>
					<button type="button" class="lrn-btn lrn-btn--primary" id="lrn-step2-next">
						<?php esc_html_e( 'Continue', 'learnomy' ); ?>
						<i data-lucide="arrow-right" aria-hidden="true"></i>
					</button>
				</div>
			</div>

			<!-- ── Step 3: Payment ──────────────────────────────────── -->
			<div class="lrn-step" data-step="3">
				<div class="lrn-step__icon"><i data-lucide="credit-card" aria-hidden="true"></i></div>
				<h2><?php esc_html_e( 'Accept payments (optional)', 'learnomy' ); ?></h2>
				<p class="lrn-step__lede"><?php esc_html_e( 'Sell courses or memberships. You can skip this and connect later.', 'learnomy' ); ?></p>

				<div class="lrn-tiles lrn-tiles--3" data-radio="payment">
					<div class="lrn-tile <?php echo 'sandbox' === $lrn_payment_mode ? 'is-selected' : ''; ?>" data-value="sandbox" tabindex="0" role="radio" aria-checked="<?php echo 'sandbox' === $lrn_payment_mode ? 'true' : 'false'; ?>">
						<span class="lrn-tile__pill"><?php esc_html_e( 'Recommended', 'learnomy' ); ?></span>
						<div class="lrn-tile__icon"><i data-lucide="flask-conical" aria-hidden="true"></i></div>
						<h3><?php esc_html_e( 'Sandbox demo mode', 'learnomy' ); ?></h3>
						<p><?php esc_html_e( 'Explore with fake transactions. No keys needed.', 'learnomy' ); ?></p>
					</div>
					<div class="lrn-tile <?php echo 'stripe_test' === $lrn_payment_mode ? 'is-selected' : ''; ?>" data-value="stripe_test" tabindex="0" role="radio" aria-checked="<?php echo 'stripe_test' === $lrn_payment_mode ? 'true' : 'false'; ?>">
						<div class="lrn-tile__icon"><i data-lucide="credit-card" aria-hidden="true"></i></div>
						<h3><?php esc_html_e( 'Connect Stripe', 'learnomy' ); ?></h3>
						<p><?php esc_html_e( 'Use your Stripe test environment.', 'learnomy' ); ?></p>
					</div>
					<div class="lrn-tile <?php echo 'deferred' === $lrn_payment_mode ? 'is-selected' : ''; ?>" data-value="deferred" tabindex="0" role="radio" aria-checked="<?php echo 'deferred' === $lrn_payment_mode ? 'true' : 'false'; ?>">
						<div class="lrn-tile__icon"><i data-lucide="clock" aria-hidden="true"></i></div>
						<h3><?php esc_html_e( 'Skip for now', 'learnomy' ); ?></h3>
						<p><?php esc_html_e( 'Set up later from Settings > Payments.', 'learnomy' ); ?></p>
					</div>
				</div>

				<div id="lrn-stripe-fields" style="display:<?php echo 'stripe_test' === $lrn_payment_mode ? 'block' : 'none'; ?>;">
					<div class="lrn-field" id="lrn-field-pk">
						<label for="lrn-stripe-pk"><?php esc_html_e( 'Publishable key', 'learnomy' ); ?></label>
						<input type="text" id="lrn-stripe-pk"
							value="<?php echo esc_attr( $lrn_stripe_pk ); ?>"
							placeholder="pk_test_…" autocomplete="off">
						<p class="lrn-field-error"><?php esc_html_e( 'Publishable key must start with pk_test_.', 'learnomy' ); ?></p>
					</div>
					<div class="lrn-field" id="lrn-field-sk">
						<label for="lrn-stripe-sk"><?php esc_html_e( 'Secret key', 'learnomy' ); ?></label>
						<input type="password" id="lrn-stripe-sk"
							value="<?php echo esc_attr( $lrn_stripe_sk ); ?>"
							placeholder="sk_test_…" autocomplete="off">
						<p class="lrn-field-error"><?php esc_html_e( 'Secret key must start with sk_test_.', 'learnomy' ); ?></p>
					</div>
				</div>

				<div class="lrn-actions">
					<button type="button" class="lrn-btn lrn-btn--outline" data-go="2">
						<i data-lucide="arrow-left" aria-hidden="true"></i>
						<?php esc_html_e( 'Back', 'learnomy' ); ?>
					</button>
					<button type="button" class="lrn-btn lrn-btn--primary" id="lrn-step3-next">
						<?php esc_html_e( 'Continue', 'learnomy' ); ?>
						<i data-lucide="arrow-right" aria-hidden="true"></i>
					</button>
				</div>
			</div>

			<!-- ── Step 4: Demo ─────────────────────────────────────── -->
			<div class="lrn-step" data-step="4">
				<div class="lrn-step__icon"><i data-lucide="package-open" aria-hidden="true"></i></div>
				<h2><?php esc_html_e( 'Start with a ready-made academy?', 'learnomy' ); ?></h2>
				<p class="lrn-step__lede">
					<?php esc_html_e( "We'll drop in 2 polished courses, sample students, a membership plan, transactions, and an issued certificate, so the dashboard feels alive on day one.", 'learnomy' ); ?>
				</p>

				<div id="lrn-demo-choice-wrap">
					<?php if ( $lrn_demo_exists ) : ?>
						<div class="lrn-locked">
							<?php esc_html_e( 'Demo content already loaded. Use Settings > Reset demo data to refresh.', 'learnomy' ); ?>
						</div>
					<?php else : ?>
						<div class="lrn-tiles lrn-tiles--2" data-radio="demo">
							<div class="lrn-tile is-selected" data-value="demo" tabindex="0" role="radio" aria-checked="true">
								<span class="lrn-tile__pill"><?php esc_html_e( 'Recommended', 'learnomy' ); ?></span>
								<div class="lrn-tile__icon"><i data-lucide="sparkles" aria-hidden="true"></i></div>
								<h3><?php esc_html_e( 'Load demo academy', 'learnomy' ); ?></h3>
								<p><?php esc_html_e( '2 courses · 5 students · Sample transactions · 1 certificate', 'learnomy' ); ?></p>
							</div>
							<div class="lrn-tile" data-value="empty" tabindex="0" role="radio" aria-checked="false">
								<div class="lrn-tile__icon"><i data-lucide="file-plus" aria-hidden="true"></i></div>
								<h3><?php esc_html_e( 'Start empty', 'learnomy' ); ?></h3>
								<p><?php esc_html_e( "I'll build from scratch.", 'learnomy' ); ?></p>
							</div>
						</div>

						<div class="lrn-previews" id="lrn-demo-preview-strip">
							<div class="lrn-preview-card" aria-hidden="true">
								<div class="lrn-preview-card__img" style="background-image:url('<?php echo esc_url( 'https://picsum.photos/seed/' . rawurlencode( 'react-fundamentals' ) . '/800/450' ); ?>');background-size:cover;background-position:center;"></div>
								<div class="lrn-preview-card__body">
									<div class="lrn-preview-card__title"><?php esc_html_e( 'React Fundamentals', 'learnomy' ); ?></div>
									<div class="lrn-preview-card__price">$49</div>
								</div>
							</div>
							<div class="lrn-preview-card" aria-hidden="true">
								<div class="lrn-preview-card__img" style="background-image:url('<?php echo esc_url( 'https://picsum.photos/seed/' . rawurlencode( 'mindful-productivity' ) . '/800/450' ); ?>');background-size:cover;background-position:center;"></div>
								<div class="lrn-preview-card__body">
									<div class="lrn-preview-card__title"><?php esc_html_e( 'Mindful Productivity', 'learnomy' ); ?></div>
									<div class="lrn-preview-card__price"><?php esc_html_e( 'Membership', 'learnomy' ); ?></div>
								</div>
							</div>
						</div>
					<?php endif; ?>
				</div>

				<div class="lrn-loading" id="lrn-loading" style="display:none;">
					<div class="lrn-spinner" aria-hidden="true"></div>
					<ul class="lrn-loading__list">
						<li class="lrn-loading__item">
							<span class="lrn-loading__tick"><i data-lucide="check" aria-hidden="true"></i></span>
							<?php esc_html_e( 'Creating courses (2)', 'learnomy' ); ?>
						</li>
						<li class="lrn-loading__item">
							<span class="lrn-loading__tick"><i data-lucide="check" aria-hidden="true"></i></span>
							<?php esc_html_e( 'Adding sections & lessons', 'learnomy' ); ?>
						</li>
						<li class="lrn-loading__item">
							<span class="lrn-loading__tick"><i data-lucide="check" aria-hidden="true"></i></span>
							<?php esc_html_e( 'Seeding students (5)', 'learnomy' ); ?>
						</li>
						<li class="lrn-loading__item">
							<span class="lrn-loading__tick"><i data-lucide="check" aria-hidden="true"></i></span>
							<?php esc_html_e( 'Recording sample transactions', 'learnomy' ); ?>
						</li>
						<li class="lrn-loading__item">
							<span class="lrn-loading__tick"><i data-lucide="check" aria-hidden="true"></i></span>
							<?php esc_html_e( 'Issuing a certificate', 'learnomy' ); ?>
						</li>
						<li class="lrn-loading__item">
							<span class="lrn-loading__tick"><i data-lucide="check" aria-hidden="true"></i></span>
							<?php esc_html_e( 'Activating membership plan', 'learnomy' ); ?>
						</li>
					</ul>
					<p class="lrn-loading__caption"><?php esc_html_e( 'This takes about 5 seconds.', 'learnomy' ); ?></p>
				</div>

				<div class="lrn-actions" id="lrn-step4-actions">
					<button type="button" class="lrn-btn lrn-btn--outline" data-go="3">
						<i data-lucide="arrow-left" aria-hidden="true"></i>
						<?php esc_html_e( 'Back', 'learnomy' ); ?>
					</button>
					<button type="button" class="lrn-btn lrn-btn--primary" id="lrn-finish-btn">
						<?php esc_html_e( 'Finish setup', 'learnomy' ); ?>
						<i data-lucide="arrow-right" aria-hidden="true"></i>
					</button>
				</div>
			</div>

			<!-- ── Step 5: Success ──────────────────────────────────── -->
			<div class="lrn-step" data-step="5">
				<div class="lrn-step__icon lrn-step__icon--success">
					<i data-lucide="party-popper" aria-hidden="true"></i>
				</div>
				<h2 id="lrn-success-headline"><?php esc_html_e( 'Your academy is live', 'learnomy' ); ?></h2>
				<p class="lrn-step__lede" id="lrn-success-lede">
					<?php
					if ( $lrn_first_name ) {
						printf(
							/* translators: %s: admin first name */
							esc_html__( "What's ready for you, %s.", 'learnomy' ),
							esc_html( $lrn_first_name )
						);
					} else {
						esc_html_e( "What's ready for you.", 'learnomy' );
					}
					?>
				</p>

				<div class="lrn-stats" id="lrn-stats">
					<div class="lrn-stat">
						<div class="lrn-stat__value" data-stat="courses">0</div>
						<div class="lrn-stat__label"><?php esc_html_e( 'Courses', 'learnomy' ); ?></div>
					</div>
					<div class="lrn-stat">
						<div class="lrn-stat__value" data-stat="students">0</div>
						<div class="lrn-stat__label"><?php esc_html_e( 'Students', 'learnomy' ); ?></div>
					</div>
					<div class="lrn-stat">
						<div class="lrn-stat__value" data-stat="revenue" data-currency="1">$0</div>
						<div class="lrn-stat__label"><?php esc_html_e( 'Revenue', 'learnomy' ); ?></div>
					</div>
					<div class="lrn-stat">
						<div class="lrn-stat__value" data-stat="certificates">0</div>
						<div class="lrn-stat__label"><?php esc_html_e( 'Certificates', 'learnomy' ); ?></div>
					</div>
				</div>

				<div class="lrn-previews" id="lrn-previews"></div>

				<div class="lrn-actions">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy&learnomy_onboarded=1' ) ); ?>" class="lrn-btn lrn-btn--primary" id="lrn-open-dashboard">
						<?php esc_html_e( 'Open Dashboard', 'learnomy' ); ?>
						<i data-lucide="arrow-right" aria-hidden="true"></i>
					</a>
					<a href="<?php echo esc_url( $home_url . $lrn_base_slug . '/' ); ?>" target="_blank" rel="noopener" class="lrn-btn lrn-btn--outline" id="lrn-view-catalog">
						<i data-lucide="external-link" aria-hidden="true"></i>
						<?php esc_html_e( 'View course catalog', 'learnomy' ); ?>
					</a>
				</div>

				<div class="lrn-next" id="lrn-next" hidden>
					<h3 class="lrn-next__title"><?php esc_html_e( 'Recommended next steps', 'learnomy' ); ?></h3>
					<p class="lrn-next__lede"><?php esc_html_e( 'Your modules are on, but they start empty. Remember: there are no pages to build - just add your first content and it appears at its route automatically.', 'learnomy' ); ?></p>
					<ul class="lrn-next__list">
						<li class="lrn-next__item" data-signpost="course">
							<a class="lrn-next__link" href="<?php echo esc_url( $lrn_new_course_url ); ?>">
								<span class="lrn-next__icon"><i data-lucide="book-open" aria-hidden="true"></i></span>
								<span class="lrn-next__body">
									<span class="lrn-next__label"><?php esc_html_e( 'Create your first course', 'learnomy' ); ?></span>
									<span class="lrn-next__hint"><?php esc_html_e( 'It appears in your catalog automatically.', 'learnomy' ); ?></span>
								</span>
								<i data-lucide="arrow-right" aria-hidden="true" class="lrn-next__arrow"></i>
							</a>
						</li>
						<li class="lrn-next__item" data-signpost="team" hidden>
							<a class="lrn-next__link" href="<?php echo esc_url( $lrn_students_url ); ?>">
								<span class="lrn-next__icon"><i data-lucide="users" aria-hidden="true"></i></span>
								<span class="lrn-next__body">
									<span class="lrn-next__label"><?php esc_html_e( 'Add your team', 'learnomy' ); ?></span>
									<span class="lrn-next__hint"><?php esc_html_e( 'Add people by email and give them a course. Nobody is asked to pay.', 'learnomy' ); ?></span>
								</span>
								<i data-lucide="arrow-right" aria-hidden="true" class="lrn-next__arrow"></i>
							</a>
						</li>
						<li class="lrn-next__item" data-signpost="spaces" hidden>
							<a class="lrn-next__link" href="<?php echo esc_url( $lrn_spaces_url ); ?>">
								<span class="lrn-next__icon"><i data-lucide="school" aria-hidden="true"></i></span>
								<span class="lrn-next__body">
									<?php if ( $lrn_spaces_ready ) : ?>
										<span class="lrn-next__label"><?php esc_html_e( 'Create a Learning Space Plan', 'learnomy' ); ?></span>
										<span class="lrn-next__hint"><?php esc_html_e( 'A seat bundle a school or company can buy. The buyer gets invites and progress reports.', 'learnomy' ); ?></span>
									<?php else : ?>
										<span class="lrn-next__label"><?php esc_html_e( 'Get Spaces (Learnomy Pro)', 'learnomy' ); ?></span>
										<span class="lrn-next__hint"><?php esc_html_e( 'Seat bundles for schools and teams are powered by Spaces. See what Pro adds.', 'learnomy' ); ?></span>
									<?php endif; ?>
								</span>
								<i data-lucide="arrow-right" aria-hidden="true" class="lrn-next__arrow"></i>
							</a>
						</li>
						<li class="lrn-next__item" data-signpost="quiz" hidden>
							<a class="lrn-next__link" href="<?php echo esc_url( $lrn_new_quiz_url ); ?>">
								<span class="lrn-next__icon"><i data-lucide="clipboard-list" aria-hidden="true"></i></span>
								<span class="lrn-next__body">
									<span class="lrn-next__label"><?php esc_html_e( 'Build your first quiz', 'learnomy' ); ?></span>
									<span class="lrn-next__hint"><?php esc_html_e( 'Quizzes are on - add one to any course.', 'learnomy' ); ?></span>
								</span>
								<i data-lucide="arrow-right" aria-hidden="true" class="lrn-next__arrow"></i>
							</a>
						</li>
						<li class="lrn-next__item" data-signpost="membership" hidden>
							<a class="lrn-next__link" href="<?php echo esc_url( $lrn_new_plan_url ); ?>">
								<span class="lrn-next__icon"><i data-lucide="repeat" aria-hidden="true"></i></span>
								<span class="lrn-next__body">
									<span class="lrn-next__label"><?php esc_html_e( 'Create a membership plan', 'learnomy' ); ?></span>
									<span class="lrn-next__hint"><?php esc_html_e( 'Memberships are on - publish a plan so members can subscribe.', 'learnomy' ); ?></span>
								</span>
								<i data-lucide="arrow-right" aria-hidden="true" class="lrn-next__arrow"></i>
							</a>
						</li>
						<li class="lrn-next__item" data-signpost="coupon" hidden>
							<a class="lrn-next__link" href="<?php echo esc_url( $lrn_coupons_url ); ?>">
								<span class="lrn-next__icon"><i data-lucide="ticket" aria-hidden="true"></i></span>
								<span class="lrn-next__body">
									<span class="lrn-next__label"><?php esc_html_e( 'Add your first coupon', 'learnomy' ); ?></span>
									<span class="lrn-next__hint"><?php esc_html_e( 'Coupons are on - create a discount code to launch with.', 'learnomy' ); ?></span>
								</span>
								<i data-lucide="arrow-right" aria-hidden="true" class="lrn-next__arrow"></i>
							</a>
						</li>
					</ul>
				</div>

				<p class="lrn-footer-link">
					<button type="button" id="lrn-reset-demo-footer"><?php esc_html_e( 'Reset demo data', 'learnomy' ); ?></button>
				</p>
			</div>

		</div>
	</section>
</div>

<canvas id="lrn-confetti" aria-hidden="true"></canvas>

<?php
$lrn_wizard_data = array(
	'isRerun'         => (bool) $lrn_is_rerun,
	'demoLocked'      => (bool) $lrn_demo_exists,
	'paymentMode'     => $lrn_payment_mode,
	'enrollment'      => $lrn_enrollment_mode,
	'homeUrl'         => $home_url,
	'baseSlug'        => $lrn_base_slug,
	'restUrl'         => $rest_url,
	'restNonce'       => $rest_nonce,
	'dashboardUrl'    => admin_url( 'admin.php?page=learnomy' ),
	'createCourseUrl' => admin_url( 'admin.php?page=learnomy-new-course' ),
	'presetSignposts' => $lrn_signpost_map,
	'i18n'            => array(
		'requestFailed'        => __( 'Request failed', 'learnomy' ),
		'networkError'         => __( 'A network error occurred. Please try again.', 'learnomy' ),
		'confirmSkipWizard'    => __( 'Are you sure? You can re-run this wizard any time from Settings > General.', 'learnomy' ),
		'saveSettingsFailed'   => __( 'Could not save settings.', 'learnomy' ),
		'savePaymentFailed'    => __( 'Could not save payment settings.', 'learnomy' ),
		'demoSeedFailed'       => __( 'Demo data could not be created.', 'learnomy' ),
		'retry'                => __( 'Retry', 'learnomy' ),
		'skipDemo'             => __( 'Skip demo', 'learnomy' ),
		'successHeadlineEmpty' => __( 'All set. Build your first course.', 'learnomy' ),
		'createFirstCourse'    => __( 'Create Your First Course', 'learnomy' ),
		'freeOrMembership'     => __( 'Free / Membership', 'learnomy' ),
		'confirmResetDemo'     => __( 'This deletes the demo courses, students, and transactions and creates a fresh set. Continue?', 'learnomy' ),
		'resetting'            => __( 'Resetting…', 'learnomy' ),
		'resetDemoData'        => __( 'Reset demo data', 'learnomy' ),
		'resetFailed'          => __( 'Reset failed.', 'learnomy' ),
	),
);
?>
<script type="application/json" id="lrn-wizard-data"><?php echo wp_json_encode( $lrn_wizard_data ); ?></script>
<?php wp_print_footer_scripts(); ?>
</body>
</html>
