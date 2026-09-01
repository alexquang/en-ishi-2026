<?php
/**
 * Admin settings view — Pattern A: sidebar + hash routing.
 *
 * All sections are rendered simultaneously. JavaScript in settings-nav.js
 * shows/hides sections based on the URL hash. No page reloads on tab switch.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$saved = get_option( 'learnomy_settings', array() );

// Module Kernel — each settings tab is gated on the module it belongs to.
// Tabs whose module is disabled disappear from the sidebar; the panel still
// exists in the DOM so deep-links and the hash router don't 404.
$kernel = \Learnomy\Modules\Module_Kernel::instance();

// Navigation structure: groups → items. Each item lists an optional
// `module` key — when set, the item only appears in the sidebar if that
// module is enabled.
$nav_groups = array(
	'general'       => array(
		'label' => __( 'General', 'learnomy' ),
		'items' => array(
			'general'        => array(
				'icon'  => 'settings',
				'label' => __( 'General', 'learnomy' ),
			),
			// "URLs & Pages", not "Pages": to a WordPress owner "Pages" means
			// wp-admin/edit.php?post_type=page, and this tab holds the URL Slugs
			// card plus the read-only frontend page table. A tab label has to
			// predict its fields (Basecamp 10184391912). The `pages` key and the
			// #section-pages anchor are unchanged, so bookmarks still resolve.
			'pages'          => array(
				'icon'  => 'file-text',
				'label' => __( 'URLs & Pages', 'learnomy' ),
			),
			'labels'         => array(
				'icon'  => 'tag',
				'label' => __( 'Labels', 'learnomy' ),
			),
			'layouts'        => array(
				'icon'  => 'layout-dashboard',
				'label' => __( 'Layouts', 'learnomy' ),
			),
			'account-fields' => array(
				'icon'  => 'list-checks',
				'label' => __( 'Registration Fields', 'learnomy' ),
			),
		),
	),
	'commerce'      => array(
		'label' => __( 'Commerce', 'learnomy' ),
		'items' => array(
			'payments'        => array(
				'icon'   => 'credit-card',
				'label'  => __( 'Payments', 'learnomy' ),
				'module' => 'payments',
			),
			'instructors'     => array(
				'icon'   => 'users',
				'label'  => __( 'Instructors', 'learnomy' ),
				'module' => 'commissions',
			),
			'commissions'     => array(
				'icon'   => 'percent',
				'label'  => __( 'Commissions', 'learnomy' ),
				'module' => 'commissions',
			),
			// Gated like its neighbours. Without a `module` key this tab
			// rendered on every install, including ones with the membership
			// module switched OFF - so an owner who had deliberately turned
			// memberships off still got a settings tab for configuring the
			// membership sales page, which is a screen for a feature they had
			// said they do not use (IA plan P1.3, Basecamp 10184391878).
			'membership-page' => array(
				'icon'   => 'gem',
				'label'  => __( 'Membership Page', 'learnomy' ),
				'module' => 'membership',
			),
		),
	),
	'communication' => array(
		'label' => __( 'Communication', 'learnomy' ),
		'items' => array(
			'emails'  => array(
				'icon'   => 'mail',
				'label'  => __( 'Emails', 'learnomy' ),
				'module' => 'notifications',
			),
			'reviews' => array(
				'icon'   => 'star',
				'label'  => __( 'Reviews', 'learnomy' ),
				'module' => 'reviews',
			),
		),
	),
	'content'       => array(
		'label' => __( 'Content', 'learnomy' ),
		'items' => array(
			// NOTE: key `courses` (-> #courses / DOM id `section-courses`)
			// predates the 2026-05-01 rename to "Comments"; intentionally NOT
			// renamed so existing bookmarks + Pro refs to `section-courses`
			// keep resolving. The visible label is the user-facing truth.
			'courses'      => array(
				'icon'  => 'message-square',
				'label' => __( 'Course Comments', 'learnomy' ),
			),
			'certificates' => array(
				'icon'   => 'award',
				'label'  => __( 'Certificate Defaults', 'learnomy' ),
				'module' => 'certificates',
			),
			'quiz'         => array(
				'icon'   => 'help-circle',
				'label'  => __( 'Quiz Settings', 'learnomy' ),
				'module' => 'quizzes',
			),
			// The single discoverable home for every learning-standard
			// integration (xAPI, SCORM, LTI, H5P, QTI). The hub partial maps
			// all five to their one config entry point; Pro modules register
			// their live-settings card into the section below the hub via
			// `learnomy_integrations_cards`.
			//
			// Lives under Content because these are the formats a course can
			// be authored in and exported to. It used to be its own group,
			// whose label repeated its only child word for word — a header
			// that told the owner nothing the row underneath had not already
			// said. The key stays `integrations` (DOM id `section-integrations`)
			// so existing bookmarks and Pro nav refs keep resolving.
			'integrations' => array(
				'icon'  => 'graduation-cap',
				'label' => __( 'Learning Standards', 'learnomy' ),
			),
		),
	),
	'advanced'      => array(
		'label' => __( 'Advanced', 'learnomy' ),
		'items' => array(
			'advanced' => array(
				'icon'  => 'zap',
				'label' => __( 'API & Performance', 'learnomy' ),
			),
			'data'     => array(
				'icon'  => 'database',
				'label' => __( 'Data Management', 'learnomy' ),
			),
		),
	),
	// Declared empty. The plan is genuinely a different subject from every
	// feature setting, so it earns a group — but only Pro has anything to put
	// in it, and the empty-group drop below means a Free-only site never sees
	// the header.
	'license'       => array(
		'label' => __( 'License', 'learnomy' ),
		'items' => array(),
	),
);

/**
 * Register a settings tab into one of the groups above.
 *
 * **Group by what a setting DOES, not by which plugin ships it.** Five Pro
 * extensions used to emit their own `<div class="lrn-settings-nav__group">`
 * straight into the sidebar, and with no rule to follow each invented its own
 * answer: License made a group called "Pro", membership-pro made "Memberships
 * Pro", certificates-pro made "Integrations" (colliding with the top-level
 * Integrations menu, which means companion plugins), and AI Authoring and
 * White Label made none at all and rendered as orphans below every header.
 *
 * A tier is not a topic. An owner asks where badges are configured, never
 * which repo shipped them — and Pro features are not licence-gated here (an
 * inactive licence pauses updates, never features), so "Pro" advertises a
 * boundary the product deliberately refuses to enforce. `license` is the one
 * legitimately tier-named group, because a plan really is its own subject.
 *
 * Items accept the same shape as the core ones: `icon`, `label`, and an
 * optional `module` key that hides the row when that module is off.
 *
 * @param array $nav_groups Group key => [ 'label' => string, 'items' => array ].
 */
$nav_groups = apply_filters( 'learnomy_admin_settings_nav_groups', $nav_groups );

// Drop items whose `module` flag is disabled, and drop empty groups so the
// Commerce group label doesn't show on a free-only academy.
foreach ( $nav_groups as $group_key => $group ) {
	foreach ( $group['items'] as $item_key => $item ) {
		if ( isset( $item['module'] ) && ! $kernel->is_enabled( $item['module'] ) ) {
			unset( $nav_groups[ $group_key ]['items'][ $item_key ] );
			continue;
		}
	}
	if ( empty( $nav_groups[ $group_key ]['items'] ) ) {
		unset( $nav_groups[ $group_key ] );
	}
}

// `payments_enabled=1` is set by Admin::maybe_enable_payments_and_configure()
// after toggling the Payments module on from the dashboard CTA. Confirm to the
// admin that the module is now active so they understand why a new tab just
// appeared in the sidebar.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( ! empty( $_GET['payments_enabled'] ) && current_user_can( 'manage_options' ) ) {
	add_settings_error(
		'learnomy_settings',
		'learnomy_payments_enabled',
		esc_html__( 'Payments module enabled. Configure Stripe or PayPal below.', 'learnomy' ),
		'success'
	);
}

// Frontend pages data for the Pages section. These reference URLs read the
// configurable slugs through the helpers so the displayed paths always match
// the live rewrite rules (e.g. a German site shows /kurse/{course}/lektion/).
$base_slug       = \Learnomy\base_slug();
$lesson_seg      = \Learnomy\lesson_slug();
$quiz_seg        = \Learnomy\quiz_slug();
$category_seg    = \Learnomy\category_slug();
$instructor_base = \Learnomy\instructor_slug();
$verify_base     = \Learnomy\verify_slug();
$standalone_quiz = \Learnomy\standalone_quiz_slug();
$frontend_pages  = array(
	array(
		'label' => __( 'Course Catalog', 'learnomy' ),
		'url'   => home_url( '/' . $base_slug . '/' ),
		'icon'  => 'layout-grid',
		'auth'  => false,
	),
	array(
		'label' => __( 'Single Course', 'learnomy' ),
		'url'   => home_url( '/' . $base_slug . '/{course-slug}/' ),
		'icon'  => 'book-open',
		'auth'  => false,
	),
	array(
		'label' => __( 'Category Archive', 'learnomy' ),
		'url'   => home_url( '/' . $base_slug . '/' . $category_seg . '/{slug}/' ),
		'icon'  => 'folder-tree',
		'auth'  => false,
	),
	array(
		'label' => __( 'Lesson Player', 'learnomy' ),
		'url'   => home_url( '/' . $base_slug . '/{course}/' . $lesson_seg . '/{lesson}/' ),
		'icon'  => 'book-text',
		'auth'  => true,
	),
	array(
		'label' => __( 'Quiz Player', 'learnomy' ),
		'url'   => home_url( '/' . $base_slug . '/{course}/' . $quiz_seg . '/{quiz}/' ),
		'icon'  => 'help-circle',
		'auth'  => true,
	),
	array(
		'label' => __( 'Standalone Quiz', 'learnomy' ),
		'url'   => home_url( '/' . $standalone_quiz . '/{slug}/' ),
		'icon'  => 'clipboard',
		'auth'  => false,
	),
	array(
		'label' => __( 'Student Dashboard', 'learnomy' ),
		'url'   => \Learnomy\route_url( 'student-dashboard' ),
		'icon'  => 'graduation-cap',
		'auth'  => true,
	),
	array(
		'label' => __( 'Instructor Dashboard', 'learnomy' ),
		'url'   => home_url( '/' . $instructor_base . '/' ),
		'icon'  => 'trending-up',
		'auth'  => true,
	),
	array(
		'label' => __( 'Instructor Profile', 'learnomy' ),
		'url'   => home_url( '/' . $instructor_base . '/{username}/' ),
		'icon'  => 'user',
		'auth'  => false,
	),
	array(
		'label' => __( 'Certificate Verify', 'learnomy' ),
		'url'   => home_url( '/' . $verify_base . '/{uuid}/' ),
		'icon'  => 'award',
		'auth'  => false,
	),
	array(
		'label' => __( 'Membership Pricing', 'learnomy' ),
		'url'   => \Learnomy\route_url( 'membership-pricing' ),
		'icon'  => 'crown',
		'auth'  => false,
	),
	array(
		'label' => __( 'Manage Membership', 'learnomy' ),
		'url'   => \Learnomy\route_url( 'membership-manage' ),
		'icon'  => 'settings',
		'auth'  => true,
	),
	array(
		'label' => __( 'Become an Instructor', 'learnomy' ),
		'url'   => \Learnomy\route_url( 'become-instructor' ),
		'icon'  => 'pen-tool',
		'auth'  => true,
	),
);

/**
 * Allow Pro extensions to register additional public pages in the Pages
 * table. Each entry must have: `label` (string), `url` (string), `icon`
 * (Lucide name), `auth` (bool — whether login is required).
 *
 * Spaces is the first consumer (Pro PR 2): it adds /my-spaces/ and
 * /spaces/{slug}/manage/ rows. Pro registers the filter listener; Free
 * owns the table UI and the slug settings.
 *
 * @param array $frontend_pages Existing rows.
 */
$frontend_pages = (array) apply_filters( 'learnomy_settings_pages_registry', $frontend_pages );
?>
<div class="wrap learnomy-admin">

	<!-- Global page header — sits above the bespoke sidebar+panels layout. -->
	<div class="lrn-page-header">
		<div class="lrn-page-header__left">
			<h1 class="lrn-page-header__title">
				<i data-lucide="settings" class="lrn-page-header__icon" aria-hidden="true"></i>
				<?php esc_html_e( 'Settings', 'learnomy' ); ?>
			</h1>
			<p class="lrn-page-header__subtitle">
				<?php esc_html_e( 'Configure Learnomy behaviour: commerce, communications, content, and advanced options.', 'learnomy' ); ?>
			</p>
		</div>
	</div>

	<?php
	// `wp-header-end` marker — admin notices render below this line.
	// Without it, third-party plugin notices stack at the top of the
	// page and overlap the page header (round 3 audit-rule-8).
	?>
	<hr class="wp-header-end">

	<?php settings_errors(); ?>

	<div class="lrn-settings-wrap">

		<!-- ── Sidebar ── -->
		<aside class="lrn-settings-sidebar">
			<div class="lrn-settings-brand">
				<span class="lrn-settings-brand__icon" aria-hidden="true">
					<img src="<?php echo esc_url( \Learnomy\logo_url( 'mark' ) ); ?>" width="36" height="36" alt="" class="lrn-settings-brand__img" />
				</span>
				<div>
					<p class="lrn-settings-brand__name">Learnomy</p>
					<p class="lrn-settings-brand__label"><?php esc_html_e( 'Settings', 'learnomy' ); ?></p>
				</div>
			</div>
			<nav class="lrn-settings-nav" aria-label="<?php esc_attr_e( 'Settings navigation', 'learnomy' ); ?>">
				<?php foreach ( $nav_groups as $group_key => $group ) : ?>
					<div class="lrn-settings-nav__group">
						<span class="lrn-settings-nav__group-label"><?php echo esc_html( $group['label'] ); ?></span>
						<?php foreach ( $group['items'] as $section_id => $item ) : ?>
							<a href="#<?php echo esc_attr( $section_id ); ?>"
								class="lrn-settings-nav__link"
								data-section="<?php echo esc_attr( $section_id ); ?>">
								<i data-lucide="<?php echo esc_attr( $item['icon'] ); ?>" aria-hidden="true"></i>
								<?php echo esc_html( $item['label'] ); ?>
							</a>
						<?php endforeach; ?>
					</div>
				<?php endforeach; ?>

				<?php
				// Pro and third-party plugins inject additional nav items. Extensions
				// that want a header self-group (License -> "Pro", membership-pro ->
				// "Memberships Pro", Credly -> "Integrations"), matching the core groups.
				do_action( 'learnomy_admin_settings_nav' );
				?>
			</nav>
		</aside>

		<!-- ── Main content ── -->
		<div class="lrn-settings-content">

			<!-- ═══════════════════════════════════════════════════════════
				GENERAL
				═══════════════════════════════════════════════════════════ -->
			<div class="lrn-settings-section" id="section-general">
				<form method="post" action="options.php">
					<?php settings_fields( 'learnomy_settings' ); ?>

					<div class="lrn-settings-card">
						<div class="lrn-settings-card__head">
							<h2 class="lrn-settings-card__title"><?php esc_html_e( 'Course Setup', 'learnomy' ); ?></h2>
							<p class="lrn-settings-card__desc"><?php esc_html_e( 'Configure how courses appear and behave on your site.', 'learnomy' ); ?></p>
						</div>
						<table class="form-table">
							<tr>
								<th scope="row">
									<label for="lrn-container-width"><?php esc_html_e( 'Container Width', 'learnomy' ); ?></label>
								</th>
								<td>
									<select id="lrn-container-width" name="learnomy_settings[container_width]">
										<?php
										$container_widths = array(
											'960px'  => '960px',
											'1100px' => '1100px',
											'1200px' => '1200px',
											'1400px' => '1400px',
											'none'   => __( 'Full Width', 'learnomy' ),
										);
										$current_width    = $saved['container_width'] ?? '960px';
										foreach ( $container_widths as $val => $label ) :
											?>
											<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $current_width, $val ); ?>><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
									<p class="description"><?php esc_html_e( 'Maximum width of the frontend content area.', 'learnomy' ); ?></p>
								</td>
							</tr>
							<?php
							// Enrollment Mode removed UNTIL BUILT (half-cooked
							// audit F1, Basecamp 10132251781): the select saved
							// open/approval but NOTHING read the value — an owner
							// choosing Approval believed students needed sign-off
							// while enrollments stayed instant. A dead control that
							// lies is worse than an absent one. The stored option
							// and its 'open' default are untouched, so the real
							// approval feature (pending state + approve UI +
							// notifications, carded separately) can revive the
							// stored value when it ships.
							?>
							<tr>
								<th scope="row">
									<label for="lrn-completion-enforcement"><?php esc_html_e( 'Completion Rules', 'learnomy' ); ?></label>
								</th>
								<td>
									<select id="lrn-completion-enforcement" name="learnomy_settings[completion_enforcement]">
										<option value="strict" <?php selected( $saved['completion_enforcement'] ?? 'strict', 'strict' ); ?>>
											<?php esc_html_e( 'Strict', 'learnomy' ); ?>
										</option>
										<option value="advisory" <?php selected( $saved['completion_enforcement'] ?? 'strict', 'advisory' ); ?>>
											<?php esc_html_e( 'Advisory', 'learnomy' ); ?>
										</option>
										<option value="off" <?php selected( $saved['completion_enforcement'] ?? 'strict', 'off' ); ?>>
											<?php esc_html_e( 'Off', 'learnomy' ); ?>
										</option>
									</select>
									<p class="description">
										<?php esc_html_e( 'Site-wide default; courses and lessons can override it.', 'learnomy' ); ?>
										<a href="<?php echo esc_url( \Learnomy\docs_url( 'completion-rules' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Learn more', 'learnomy' ); ?></a>
									</p>
								</td>
							</tr>
						</table>
					</div>

					<div class="lrn-settings-card">
						<div class="lrn-settings-card__head">
							<h2 class="lrn-settings-card__title"><?php esc_html_e( 'Access Control', 'learnomy' ); ?></h2>
							<p class="lrn-settings-card__desc"><?php esc_html_e( 'Control who can reach your course content.', 'learnomy' ); ?></p>
						</div>
						<table class="form-table">
							<tr>
								<th scope="row"><?php esc_html_e( 'Private site', 'learnomy' ); ?></th>
								<td>
									<?php
									// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- UX::toggle() returns escaped markup.
									echo \Learnomy\Admin\UX::toggle(
										array(
											'name'    => 'learnomy_settings[require_login_all_content]',
											'checked' => ! empty( $saved['require_login_all_content'] ),
											'label'   => __( 'Require login for all course content', 'learnomy' ),
										)
									);
									// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
									?>
									<p class="description">
										<?php esc_html_e( 'Redirect logged-out visitors to the login page for the course catalog, courses, lessons, and quizzes. Use for a fully private, members-only academy where sign-up and payment happen off-site.', 'learnomy' ); ?>
										<a href="<?php echo esc_url( \Learnomy\docs_url( 'private-site' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Learn more', 'learnomy' ); ?></a>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Mobile app', 'learnomy' ); ?></th>
								<td>
									<?php
									// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- UX::toggle() returns escaped markup.
									echo \Learnomy\Admin\UX::toggle(
										array(
											'name'    => 'learnomy_settings[mobile_app_enabled]',
											'checked' => ! isset( $saved['mobile_app_enabled'] ) || ! empty( $saved['mobile_app_enabled'] ),
											'label'   => __( 'Allow the Learnomy mobile app to connect to this site', 'learnomy' ),
										)
									);
									// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
									?>
									<p class="description">
										<?php esc_html_e( 'Students and instructors sign in from the app with a WordPress Application Password. The app is a Pro benefit: it also requires Learnomy Pro with an active license before it can connect.', 'learnomy' ); ?>
									</p>
								</td>
							</tr>
						</table>
					</div>

					<div class="lrn-settings-card">
						<div class="lrn-settings-card__head">
							<h2 class="lrn-settings-card__title"><?php esc_html_e( 'Academy Mode', 'learnomy' ); ?></h2>
							<p class="lrn-settings-card__desc"><?php esc_html_e( 'For a one-instructor academy with no instructor marketplace.', 'learnomy' ); ?></p>
						</div>
						<table class="form-table">
							<tr>
								<th scope="row"><?php esc_html_e( 'Solo academy', 'learnomy' ); ?></th>
								<td>
									<?php
									// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- UX::toggle() returns escaped markup.
									echo \Learnomy\Admin\UX::toggle(
										array(
											'name'    => 'learnomy_settings[solo_mode]',
											'checked' => ! empty( $saved['solo_mode'] ),
											'label'   => __( 'I am the only instructor (solo mode)', 'learnomy' ),
										)
									);
									// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
									?>
									<p class="description">
										<?php
										// "one-instructor", not "single-teacher": the toggle above
										// already says "instructor", the role is Instructor, and the
										// menu says Instructors. One card was using two words for one
										// role (Basecamp 10184391912).
										esc_html_e( 'Run as a one-instructor academy: hides instructor sign-up, commissions, and revenue-share.', 'learnomy' );
										?>
										<a href="<?php echo esc_url( \Learnomy\docs_url( 'academy-mode' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Learn more', 'learnomy' ); ?></a>
									</p>
								</td>
							</tr>
						</table>
					</div>

					<div class="lrn-settings-card">
						<div class="lrn-settings-card__head">
							<h2 class="lrn-settings-card__title"><?php esc_html_e( 'Currency & Payments', 'learnomy' ); ?></h2>
							<p class="lrn-settings-card__desc"><?php esc_html_e( 'Set the default currency for course pricing and transactions.', 'learnomy' ); ?></p>
						</div>
						<table class="form-table">
							<tr>
								<th scope="row">
									<label for="lrn-currency"><?php esc_html_e( 'Currency', 'learnomy' ); ?></label>
								</th>
								<td>
									<select id="lrn-currency" name="learnomy_settings[currency]">
										<?php
										/*
										 * Built from the shared helpers, not typed here.
										 * This list had six entries while the product
										 * supported eight, so a site could be charged in
										 * a currency its owner could not choose, and an
										 * owner outside those six had to write a filter to
										 * sell in their own money. One source, so the
										 * picker and the charge cannot disagree.
										 */
										$currencies = array();
										$lrn_names  = \Learnomy\currency_names();
										foreach ( \Learnomy\supported_currencies() as $lrn_code ) {
											$currencies[ $lrn_code ] = isset( $lrn_names[ $lrn_code ] )
												? $lrn_code . ' - ' . $lrn_names[ $lrn_code ]
												: $lrn_code;
										}
										foreach ( $currencies as $code => $cur_label ) :
											?>
											<option value="<?php echo esc_attr( $code ); ?>"
												<?php selected( $saved['currency'] ?? 'USD', $code ); ?>>
												<?php echo esc_html( $cur_label ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
						</table>
					</div>

					<div class="lrn-settings-section__footer">
						<?php submit_button( __( 'Save Changes', 'learnomy' ), 'primary', 'submit', false ); ?>
					</div>
				</form>

				<?php
				// The cards below are one-off ACTIONS, not saved settings — they run
				// immediately and are NOT governed by the Save Changes button above.
				// The divider makes that scope boundary explicit (audit #10056470788).
				?>
				<div class="lrn-settings-divider">
					<h3 class="lrn-settings-divider__title"><?php esc_html_e( 'Actions', 'learnomy' ); ?></h3>
					<p class="lrn-settings-divider__desc"><?php esc_html_e( 'One-off tools that run immediately. These are not governed by Save Changes above.', 'learnomy' ); ?></p>
				</div>

					<?php $lrn_onb_dismissed = (bool) get_option( 'learnomy_onboarding_dismissed', false ); ?>
				<div class="lrn-settings-card">
					<div class="lrn-settings-card__head">
						<h2 class="lrn-settings-card__title"><?php esc_html_e( 'Getting Started guide', 'learnomy' ); ?></h2>
						<p class="lrn-settings-card__desc">
							<?php esc_html_e( 'Show the onboarding checklist on the Learnomy dashboard to help launch your learning site.', 'learnomy' ); ?>
						</p>
					</div>
					<table class="form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'Dashboard guide', 'learnomy' ); ?></th>
							<td>
								<div class="lrn-onboarding-reopen-row">
									<?php if ( $lrn_onb_dismissed ) : ?>
										<button
											type="button"
											class="lrn-admin-btn"
											id="lrn-onboarding-reopen"
											data-nonce="<?php echo esc_attr( wp_create_nonce( 'learnomy_admin' ) ); ?>"
										>
											<?php esc_html_e( 'Show the guide again', 'learnomy' ); ?>
										</button>
										<p class="description"><?php esc_html_e( 'The Getting Started guide is currently hidden.', 'learnomy' ); ?></p>
									<?php else : ?>
										<p class="description"><?php esc_html_e( 'The Getting Started guide is visible on your Learnomy dashboard.', 'learnomy' ); ?></p>
									<?php endif; ?>
								</div>
							</td>
						</tr>
					</table>
				</div>

				<?php // Setup wizard controls — re-run + destructive reset. ?>
				<div class="lrn-settings-card">
					<div class="lrn-settings-card__head">
						<h2 class="lrn-settings-card__title"><?php esc_html_e( 'Setup wizard', 'learnomy' ); ?></h2>
						<p class="lrn-settings-card__desc"><?php esc_html_e( 'Re-run the onboarding flow or reset the demo academy.', 'learnomy' ); ?></p>
					</div>
					<table class="form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'Re-run setup wizard', 'learnomy' ); ?></th>
							<td>
								<a class="lrn-admin-btn"
									href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-setup&rerun=1' ) ); ?>">
									<?php esc_html_e( 'Open setup wizard', 'learnomy' ); ?>
								</a>
								<p class="description">
									<?php esc_html_e( 'Re-run the setup wizard with your current settings pre-filled.', 'learnomy' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Reset demo data', 'learnomy' ); ?></th>
							<td>
								<button type="button"
									class="lrn-admin-btn lrn-admin-btn--danger"
									id="lrn-reset-demo-btn"
									data-action="reset-demo">
									<?php esc_html_e( 'Reset demo data', 'learnomy' ); ?>
								</button>
								<p class="description">
									<?php esc_html_e( 'Delete all demo content and recreate it. Only demo data (tagged lrn_demo_*) is affected.', 'learnomy' ); ?>
								</p>
							</td>
						</tr>
					</table>
				</div>
			</div>

			<!-- ═══════════════════════════════════════════════════════════
				PAGES
				═══════════════════════════════════════════════════════════ -->
			<div class="lrn-settings-section" id="section-pages">

				<form method="post" action="options.php">
					<?php settings_fields( 'learnomy_settings' ); ?>
					<div class="lrn-settings-card">
						<div class="lrn-settings-card__head">
							<h2 class="lrn-settings-card__title"><?php esc_html_e( 'URL Slugs', 'learnomy' ); ?></h2>
							<p class="lrn-settings-card__desc"><?php esc_html_e( 'Localize the URL segments for your language (e.g. "kurse" / "lektion"). Saving any change refreshes your permalinks automatically. Leave a field blank to keep the default.', 'learnomy' ); ?></p>
						</div>
						<table class="form-table">
							<tr>
								<th scope="row">
									<label for="lrn-base-slug"><?php esc_html_e( 'Course URL Base', 'learnomy' ); ?></label>
								</th>
								<td>
									<input type="text" id="lrn-base-slug" name="learnomy_settings[base_slug]"
										value="<?php echo esc_attr( $saved['base_slug'] ?? 'courses' ); ?>"
										class="regular-text" placeholder="courses">
									<p class="description"><?php esc_html_e( 'The URL prefix for course pages (e.g., /courses/).', 'learnomy' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="lrn-lesson-slug"><?php esc_html_e( 'Lesson URL Segment', 'learnomy' ); ?></label>
								</th>
								<td>
									<input type="text" id="lrn-lesson-slug" name="learnomy_settings[lesson_slug]"
										value="<?php echo esc_attr( $saved['lesson_slug'] ?? 'lesson' ); ?>"
										class="regular-text" placeholder="lesson">
									<p class="description"><?php esc_html_e( 'The segment inside a lesson URL (e.g., /courses/x/lesson/y/). Localize it like "lektion" for German.', 'learnomy' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="lrn-quiz-slug"><?php esc_html_e( 'Course Quiz URL Segment', 'learnomy' ); ?></label>
								</th>
								<td>
									<input type="text" id="lrn-quiz-slug" name="learnomy_settings[quiz_slug]"
										value="<?php echo esc_attr( $saved['quiz_slug'] ?? 'quiz' ); ?>"
										class="regular-text" placeholder="quiz">
									<p class="description"><?php esc_html_e( 'The segment inside a course quiz URL (e.g., /courses/x/quiz/y/).', 'learnomy' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="lrn-category-slug"><?php esc_html_e( 'Category URL Segment', 'learnomy' ); ?></label>
								</th>
								<td>
									<input type="text" id="lrn-category-slug" name="learnomy_settings[category_slug]"
										value="<?php echo esc_attr( $saved['category_slug'] ?? 'category' ); ?>"
										class="regular-text" placeholder="category">
									<p class="description"><?php esc_html_e( 'The segment inside a category archive URL (e.g., /courses/category/y/).', 'learnomy' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="lrn-instructor-slug"><?php esc_html_e( 'Instructor URL Base', 'learnomy' ); ?></label>
								</th>
								<td>
									<input type="text" id="lrn-instructor-slug" name="learnomy_settings[instructor_slug]"
										value="<?php echo esc_attr( $saved['instructor_slug'] ?? 'instructor' ); ?>"
										class="regular-text" placeholder="instructor">
									<p class="description"><?php esc_html_e( 'The base for the instructor dashboard and public profiles (e.g., /instructor/username/).', 'learnomy' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="lrn-verify-slug"><?php esc_html_e( 'Certificate Verify URL Base', 'learnomy' ); ?></label>
								</th>
								<td>
									<input type="text" id="lrn-verify-slug" name="learnomy_settings[verify_slug]"
										value="<?php echo esc_attr( $saved['verify_slug'] ?? 'verify' ); ?>"
										class="regular-text" placeholder="verify">
									<p class="description"><?php esc_html_e( 'The base for public certificate verification links (e.g., /verify/uuid/).', 'learnomy' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="lrn-standalone-quiz-slug"><?php esc_html_e( 'Standalone Quiz URL Base', 'learnomy' ); ?></label>
								</th>
								<td>
									<input type="text" id="lrn-standalone-quiz-slug" name="learnomy_settings[standalone_quiz_slug]"
										value="<?php echo esc_attr( $saved['standalone_quiz_slug'] ?? 'quiz' ); ?>"
										class="regular-text" placeholder="quiz">
									<p class="description"><?php esc_html_e( 'The base for quizzes not attached to a course (e.g., /quiz/slug/).', 'learnomy' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="lrn-membership-slug"><?php esc_html_e( 'Membership URL Base', 'learnomy' ); ?></label>
								</th>
								<td>
									<input type="text" id="lrn-membership-slug" name="learnomy_settings[membership_slug]"
										value="<?php echo esc_attr( $saved['membership_slug'] ?? 'membership' ); ?>"
										class="regular-text" placeholder="membership">
									<p class="description"><?php esc_html_e( 'The base for the public membership pricing page (e.g., /membership/). The member-facing manage screen lives under the account hub at /account/membership/.', 'learnomy' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="lrn-my-spaces-slug"><?php esc_html_e( 'My Learning Spaces URL', 'learnomy' ); ?></label>
								</th>
								<td>
									<input type="text" id="lrn-my-spaces-slug" name="learnomy_settings[my_spaces_slug]"
										value="<?php echo esc_attr( $saved['my_spaces_slug'] ?? 'my-spaces' ); ?>"
										class="regular-text" placeholder="my-spaces">
									<p class="description"><?php esc_html_e( 'The Learning Spaces dashboard URL (e.g., /my-spaces/). Owners and members land here to see every space they belong to. Requires the Pro Spaces extension to render.', 'learnomy' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="lrn-enroll-slug"><?php esc_html_e( 'Enroll URL Base', 'learnomy' ); ?></label>
								</th>
								<td>
									<input type="text" id="lrn-enroll-slug" name="learnomy_settings[enroll_slug]"
										value="<?php echo esc_attr( $saved['enroll_slug'] ?? 'enroll' ); ?>"
										class="regular-text" placeholder="enroll">
									<p class="description"><?php esc_html_e( 'The base for checkout pages (e.g., /enroll/, /enroll/thank-you/, /enroll/cancelled/).', 'learnomy' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="lrn-account-slug"><?php esc_html_e( 'Account URL Base', 'learnomy' ); ?></label>
								</th>
								<td>
									<input type="text" id="lrn-account-slug" name="learnomy_settings[account_slug]"
										value="<?php echo esc_attr( $saved['account_slug'] ?? 'account' ); ?>"
										class="regular-text" placeholder="account">
									<p class="description"><?php esc_html_e( 'The base for the account + billing pages (e.g., /account/, /account/billing/).', 'learnomy' ); ?></p>
								</td>
							</tr>
						</table>
					</div>
					<div class="lrn-settings-section__footer">
						<?php submit_button( __( 'Save Changes', 'learnomy' ), 'primary', 'submit', false ); ?>
					</div>
				</form>

				<form method="post" action="options.php">
					<?php settings_fields( 'learnomy_settings' ); ?>
					<div class="lrn-settings-card">
						<div class="lrn-settings-card__head">
							<h2 class="lrn-settings-card__title"><?php esc_html_e( 'Legal', 'learnomy' ); ?></h2>
							<p class="lrn-settings-card__desc">
								<?php
								$lrn_privacy_url = get_privacy_policy_url();
								if ( $lrn_privacy_url ) {
									printf(
										/* translators: %s: the site's published privacy policy URL. */
										esc_html__( 'Set your Terms of Service page and new members must agree to it before their account is created. Each agreement is recorded with the date, the pages shown and the IP address. Your WordPress privacy policy (%s) is included automatically.', 'learnomy' ),
										'<code>' . esc_html( $lrn_privacy_url ) . '</code>'
									);
								} else {
									esc_html_e( 'Set your Terms of Service page and new members must agree to it before their account is created. Each agreement is recorded with the date, the pages shown and the IP address. Leave this blank and no agreement is requested.', 'learnomy' );
								}
								?>
							</p>
						</div>
						<table class="form-table">
							<tr>
								<th scope="row">
									<label for="lrn-terms-url"><?php esc_html_e( 'Terms of Service page', 'learnomy' ); ?></label>
								</th>
								<td>
									<input type="url" id="lrn-terms-url" name="learnomy_settings[terms_url]"
										value="<?php echo esc_attr( $saved['terms_url'] ?? '' ); ?>"
										class="regular-text" placeholder="https://example.com/terms/">
									<p class="description"><?php esc_html_e( 'Full URL of the page members agree to at sign-up. Leave blank to turn the agreement off.', 'learnomy' ); ?></p>
								</td>
							</tr>
						</table>
					</div>
					<div class="lrn-settings-section__footer">
						<?php submit_button( __( 'Save Changes', 'learnomy' ), 'primary', 'submit', false ); ?>
					</div>
				</form>

				<div class="lrn-settings-card">
					<div class="lrn-settings-card__head">
						<h2 class="lrn-settings-card__title"><?php esc_html_e( 'Frontend Pages', 'learnomy' ); ?></h2>
						<p class="lrn-settings-card__desc"><?php esc_html_e( 'All Learnomy pages available on the frontend. Dynamic pages use URL parameters shown in braces.', 'learnomy' ); ?></p>
					</div>
					<div class="lrn-admin-table-wrap">
						<table class="lrn-admin-table">
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( 'Page', 'learnomy' ); ?></th>
									<th scope="col"><?php esc_html_e( 'URL', 'learnomy' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Access', 'learnomy' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Actions', 'learnomy' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php
								foreach ( $frontend_pages as $page ) :
									// A registrant may hand us a placeholder URL whose braces got
									// URL-encoded by the URL builder (e.g. route_url() turns
									// `{slug}` into `%7Bslug%7D`). Decode them back so the pattern
									// always reads `{slug}` in the table AND so the dynamic-row
									// detection never mistakes an encoded placeholder for a real,
									// navigable URL (which would render a broken "View" link).
									$display_url = str_replace( array( '%7B', '%7D' ), array( '{', '}' ), $page['url'] );
									$is_dynamic  = false !== strpos( $display_url, '{' );
									?>
									<tr>
										<td>
											<div class="lrn-page-row">
												<i data-lucide="<?php echo esc_attr( $page['icon'] ); ?>" class="lrn-page-row__icon" aria-hidden="true"></i>
												<div>
													<strong class="lrn-row-title"><?php echo esc_html( $page['label'] ); ?></strong>
												</div>
											</div>
										</td>
										<td>
											<code class="lrn-slug"><?php echo esc_html( wp_make_link_relative( $display_url ) ); ?></code>
										</td>
										<td>
											<?php if ( $page['auth'] ) : ?>
												<span class="lrn-status-badge lrn-status-badge--warn"><?php esc_html_e( 'Login required', 'learnomy' ); ?></span>
											<?php else : ?>
												<span class="lrn-status-badge lrn-status-badge--success"><?php esc_html_e( 'Public', 'learnomy' ); ?></span>
											<?php endif; ?>
										</td>
										<td>
											<?php if ( ! $is_dynamic ) : ?>
												<a href="<?php echo esc_url( $page['url'] ); ?>" target="_blank" class="lrn-action-link">
													<?php esc_html_e( 'View', 'learnomy' ); ?>
													<i data-lucide="external-link" class="lrn-icon-xs" aria-hidden="true"></i>
												</a>
											<?php else : ?>
												<span class="lrn-text-muted"><?php esc_html_e( 'Dynamic', 'learnomy' ); ?></span>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>

				<div class="lrn-settings-card">
					<div class="lrn-settings-card__head">
						<h2 class="lrn-settings-card__title"><?php esc_html_e( 'Theme Override', 'learnomy' ); ?></h2>
						<p class="lrn-settings-card__desc"><?php esc_html_e( 'Customise any Learnomy page by copying the template into your theme.', 'learnomy' ); ?></p>
					</div>
					<table class="form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'Override Path', 'learnomy' ); ?></th>
							<td>
								<code><?php echo esc_html( get_stylesheet_directory() . '/learnomy/' ); ?></code>
								<p class="description"><?php esc_html_e( 'Copy any template from the plugin\'s templates/ directory into this folder to override it.', 'learnomy' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

			</div>

			<!-- ═══════════════════════════════════════════════════════════
				LABELS
				═══════════════════════════════════════════════════════════ -->
			<div class="lrn-settings-section" id="section-labels">
				<form method="post" action="options.php">
					<?php settings_fields( 'learnomy_settings' ); ?>

					<div class="lrn-settings-card">
						<div class="lrn-settings-card__head">
							<h2 class="lrn-settings-card__title"><?php esc_html_e( 'Entity Labels', 'learnomy' ); ?></h2>
							<p class="lrn-settings-card__desc">
								<?php esc_html_e( 'Rename the core content types across your site without code. Leave a field blank to keep the default name. To translate into another language, use a .po translation instead.', 'learnomy' ); ?>
							</p>
						</div>
						<table class="form-table">
							<?php
							$lrn_saved_labels = ( isset( $saved['labels'] ) && is_array( $saved['labels'] ) ) ? $saved['labels'] : array();
							foreach ( \Learnomy\entity_label_defaults() as $lrn_entity_key => $lrn_forms ) :
								$lrn_sing_field = $lrn_entity_key . '_singular';
								$lrn_plur_field = $lrn_entity_key . '_plural';
								?>
								<tr>
									<th scope="row">
										<label for="lrn-label-<?php echo esc_attr( $lrn_sing_field ); ?>">
											<?php echo esc_html( $lrn_forms['singular'] ); ?>
										</label>
									</th>
									<td>
										<div class="lrn-label-fields">
											<input type="text"
												id="lrn-label-<?php echo esc_attr( $lrn_sing_field ); ?>"
												name="learnomy_settings[labels][<?php echo esc_attr( $lrn_sing_field ); ?>]"
												value="<?php echo esc_attr( $lrn_saved_labels[ $lrn_sing_field ] ?? '' ); ?>"
												placeholder="<?php echo esc_attr( $lrn_forms['singular'] ); ?>"
												class="regular-text"
												aria-label="<?php echo esc_attr( sprintf( /* translators: %s: content type name */ __( '%s singular label', 'learnomy' ), $lrn_forms['singular'] ) ); ?>">
											<input type="text"
												id="lrn-label-<?php echo esc_attr( $lrn_plur_field ); ?>"
												name="learnomy_settings[labels][<?php echo esc_attr( $lrn_plur_field ); ?>]"
												value="<?php echo esc_attr( $lrn_saved_labels[ $lrn_plur_field ] ?? '' ); ?>"
												placeholder="<?php echo esc_attr( $lrn_forms['plural'] ); ?>"
												class="regular-text"
												aria-label="<?php echo esc_attr( sprintf( /* translators: %s: content type name */ __( '%s plural label', 'learnomy' ), $lrn_forms['plural'] ) ); ?>">
										</div>
										<p class="description">
											<?php
											printf(
												/* translators: 1: singular default label, 2: plural default label */
												esc_html__( 'Singular and plural. Defaults: %1$s / %2$s.', 'learnomy' ),
												esc_html( $lrn_forms['singular'] ),
												esc_html( $lrn_forms['plural'] )
											);
											?>
										</p>
									</td>
								</tr>
							<?php endforeach; ?>
						</table>
					</div>

					<div class="lrn-settings-section__footer">
						<?php submit_button( __( 'Save Changes', 'learnomy' ), 'primary', 'submit', false ); ?>
					</div>
				</form>
			</div>

			<!-- ═══════════════════════════════════════════════════════════
				LAYOUTS
				═══════════════════════════════════════════════════════════ -->
			<div class="lrn-settings-section" id="section-layouts">
				<form method="post" action="options.php">
					<?php settings_fields( 'learnomy_settings' ); ?>

					<div class="lrn-settings-card">
						<div class="lrn-settings-card__head">
							<h2 class="lrn-settings-card__title"><?php esc_html_e( 'Layouts', 'learnomy' ); ?></h2>
							<p class="lrn-settings-card__desc"><?php esc_html_e( 'This is the one place to pick the layout for each page: the course catalog, the course page, and the lesson. Switching a layout never changes the page URL. More surfaces become configurable as they are migrated to the layout system.', 'learnomy' ); ?></p>
						</div>
						<table class="form-table">
							<?php
							$lrn_layouts = \Learnomy\layouts();
							// Surfaces exposed in the UI this release (others land per phase).
							$lrn_layout_ui = array( 'archive', 'single-course', 'lesson', 'quiz', 'standalone-quiz' );
							foreach ( $lrn_layout_ui as $lrn_surface ) :
								if ( empty( $lrn_layouts[ $lrn_surface ] ) ) {
									continue;
								}
								$lrn_cfg     = $lrn_layouts[ $lrn_surface ];
								$lrn_key     = $lrn_cfg['setting_key'];
								$lrn_current = $saved[ $lrn_key ] ?? $lrn_cfg['default'];
								if ( 'archive' === $lrn_surface ) {
									$lrn_label = __( 'Course catalog', 'learnomy' );
								} elseif ( 'single-course' === $lrn_surface ) {
									$lrn_label = __( 'Course page', 'learnomy' );
								} elseif ( 'lesson' === $lrn_surface ) {
									$lrn_label = __( 'Lesson', 'learnomy' );
								} elseif ( 'quiz' === $lrn_surface ) {
									$lrn_label = __( 'Course quiz', 'learnomy' );
								} elseif ( 'standalone-quiz' === $lrn_surface ) {
									$lrn_label = __( 'Standalone quiz', 'learnomy' );
								} else {
									$lrn_label = ucfirst( str_replace( '-', ' ', $lrn_surface ) );
								}
								?>
								<tr>
									<th scope="row"><label for="lrn-layout-<?php echo esc_attr( $lrn_surface ); ?>"><?php echo esc_html( $lrn_label ); ?></label></th>
									<td>
										<select id="lrn-layout-<?php echo esc_attr( $lrn_surface ); ?>" name="learnomy_settings[<?php echo esc_attr( $lrn_key ); ?>]" class="lrn-select">
											<?php foreach ( $lrn_cfg['variants'] as $lrn_variant => $lrn_vcfg ) : ?>
												<option value="<?php echo esc_attr( $lrn_variant ); ?>" <?php selected( $lrn_current, $lrn_variant ); ?>>
													<?php echo esc_html( $lrn_vcfg['label'] ); ?>
												</option>
											<?php endforeach; ?>
										</select>
										<p class="description"><?php esc_html_e( 'The structural layout for this page.', 'learnomy' ); ?></p>
									</td>
								</tr>
							<?php endforeach; ?>
							<?php
							// Course catalog grid density — how many course cards per
							// row on desktop. Mobile/tablet stay responsive (1-2 cols).
							$lrn_cols = (int) ( $saved['catalog_columns'] ?? 3 );
							if ( $lrn_cols < 2 || $lrn_cols > 5 ) {
								$lrn_cols = 3;
							}
							?>
							<tr>
								<th scope="row"><label for="lrn-catalog-columns"><?php esc_html_e( 'Catalog columns', 'learnomy' ); ?></label></th>
								<td>
									<select id="lrn-catalog-columns" name="learnomy_settings[catalog_columns]" class="lrn-select">
										<?php foreach ( array( 2, 3, 4, 5 ) as $lrn_n ) : ?>
											<option value="<?php echo (int) $lrn_n; ?>" <?php selected( $lrn_cols, $lrn_n ); ?>>
												<?php
												/* translators: %d: number of columns */
												echo esc_html( sprintf( _n( '%d column', '%d columns', $lrn_n, 'learnomy' ), $lrn_n ) );
												?>
											</option>
										<?php endforeach; ?>
									</select>
									<p class="description"><?php esc_html_e( 'Course cards per row on desktop for the catalog grid. Tablet and mobile stay responsive (2 then 1 column).', 'learnomy' ); ?></p>
								</td>
							</tr>
						</table>
					</div>

					<div class="lrn-settings-section__footer">
						<?php submit_button( __( 'Save Changes', 'learnomy' ), 'primary', 'submit', false ); ?>
					</div>
				</form>
			</div>

			<!-- ═══════════════════════════════════════════════════════════
				REGISTRATION & PROFILE FIELDS (no-code custom fields)
				═══════════════════════════════════════════════════════════ -->
			<?php require __DIR__ . '/partials/settings-account-fields.php'; ?>

			<!-- ═══════════════════════════════════════════════════════════
				PAYMENTS
				═══════════════════════════════════════════════════════════ -->
			<div class="lrn-settings-section" id="section-payments">
				<form method="post" action="options.php">
					<?php settings_fields( 'learnomy_settings' ); ?>

					<?php
					// ── Resolve current saved values ──────────────────────────
					$stripe_mode        = $saved['stripe_mode'] ?? 'test';
					$stripe_test_pk     = $saved['stripe_test_publishable'] ?? '';
					$stripe_live_pk     = $saved['stripe_live_publishable'] ?? '';
					$has_stripe_test_sk = '' !== \Learnomy\Security\Encrypted_Option::get( 'learnomy_stripe_test_secret' );
					$has_stripe_test_wh = '' !== \Learnomy\Security\Encrypted_Option::get( 'learnomy_stripe_test_webhook_secret' );
					$has_stripe_live_sk = '' !== \Learnomy\Security\Encrypted_Option::get( 'learnomy_stripe_live_secret' );
					$has_stripe_live_wh = '' !== \Learnomy\Security\Encrypted_Option::get( 'learnomy_stripe_live_webhook_secret' );
					$stripe_webhook_url = home_url( '/wp-json/learnomy/v1/webhooks/stripe' );

					$paypal_mode            = $saved['paypal_mode'] ?? 'sandbox';
					$paypal_sandbox_client  = $saved['paypal_sandbox_client'] ?? '';
					$paypal_sandbox_wh_id   = $saved['paypal_sandbox_webhook_id'] ?? '';
					$paypal_live_client     = $saved['paypal_live_client'] ?? '';
					$paypal_live_wh_id      = $saved['paypal_live_webhook_id'] ?? '';
					$has_paypal_sb_secret   = '' !== \Learnomy\Security\Encrypted_Option::get( 'learnomy_paypal_sandbox_secret' );
					$has_paypal_live_secret = '' !== \Learnomy\Security\Encrypted_Option::get( 'learnomy_paypal_live_secret' );
					$paypal_webhook_url     = home_url( '/wp-json/learnomy/v1/webhooks/paypal' );

					// Encrypted secret fields are write-only — they render value=""
					// for security and never send the secret back to the browser
					// (the WooCommerce/Stripe-plugin standard). That leaves no
					// visual confirmation a value is stored, so a saved field gets
					// an explicit "Saved" status pill next to it. Reused by every
					// encrypted field below.
					$lrn_secret_saved_badge = static function ( bool $saved ): void {
						if ( ! $saved ) {
							return;
						}
						echo '<span class="lrn-secret-status" role="status">'
							. esc_html__( 'Saved', 'learnomy' )
							. '</span>';
					};
					?>

					<?php
					/*
					 * WooCommerce, once enabled, takes every paid checkout. Stripe
					 * and PayPal keep their keys, keep their "Connected" badge and
					 * keep claiming to accept payments - while not being used for a
					 * single purchase. An owner running all three (this is the
					 * common case: Woo was already on the site) is told twice over
					 * that cards are being accepted through Stripe when the buyer
					 * is in fact sent to the Woo cart.
					 *
					 * The Woo card further down does say "Leave off to keep
					 * Learnomy's built-in Stripe/PayPal checkout", but that is
					 * phrased as a consequence of leaving it OFF and sits below
					 * the two cards it silences. Nobody reads a settings page
					 * bottom-up.
					 *
					 * So each affected card says so on itself. The fields stay
					 * editable on purpose - an owner preparing to switch back to
					 * Stripe needs to enter keys before turning Woo off, and
					 * disabling the inputs would make that impossible.
					 */
					$lrn_wc_takeover = \Learnomy\Services\Payment_Settings::woocommerce_enabled();

					$lrn_wc_takeover_notice = static function ( string $gateway ) use ( $lrn_wc_takeover ) {
						if ( ! $lrn_wc_takeover ) {
							return;
						}
						?>
						<div class="notice notice-info inline lrn-payment-card__superseded">
							<p>
								<?php
								printf(
									/* translators: %s: gateway name, e.g. Stripe. */
									esc_html__( 'WooCommerce is handling checkout, so %s is not being used for any purchase. These settings are kept for when you switch back.', 'learnomy' ),
									esc_html( $gateway )
								);
								?>
								<a href="#lrn-woocommerce-card"><?php esc_html_e( 'WooCommerce setting', 'learnomy' ); ?></a>
							</p>
						</div>
						<?php
					};
					?>

					<!-- ── STRIPE CARD ──────────────────────────────────────── -->
					<div class="lrn-settings-card lrn-payment-card" id="lrn-stripe-card">
						<div class="lrn-settings-card__head lrn-payment-card__head">
							<div class="lrn-payment-card__title-row">
								<span class="lrn-payment-card__icon">
									<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>
								</span>
								<h2 class="lrn-settings-card__title"><?php esc_html_e( 'Stripe', 'learnomy' ); ?></h2>
								<?php if ( 'test' === $stripe_mode ) : ?>
									<span class="lrn-settings-badge lrn-settings-badge--test" id="lrn-stripe-mode-badge"><?php esc_html_e( 'TEST MODE', 'learnomy' ); ?></span>
								<?php else : ?>
									<span class="lrn-settings-badge lrn-settings-badge--live" id="lrn-stripe-mode-badge"><?php esc_html_e( 'LIVE MODE', 'learnomy' ); ?></span>
								<?php endif; ?>
								<?php // Persistent connected indicator: enabled AND a secret key is saved for the active mode. The Test Connection button below verifies the key is live. ?>
								<span class="lrn-settings-badge lrn-settings-badge--success" id="lrn-stripe-connected-badge"
									<?php echo \Learnomy\Services\Payment_Settings::stripe_enabled() ? '' : 'hidden'; ?>>
									<i data-lucide="check-circle" aria-hidden="true"></i><?php esc_html_e( 'Connected', 'learnomy' ); ?>
								</span>
							</div>
							<p class="lrn-settings-card__desc"><?php esc_html_e( 'Accept credit and debit card payments via Stripe Checkout.', 'learnomy' ); ?></p>
						</div>

						<?php $lrn_wc_takeover_notice( __( 'Stripe', 'learnomy' ) ); ?>

						<table class="form-table">
							<tr>
								<th scope="row"><?php esc_html_e( 'Enable Stripe', 'learnomy' ); ?></th>
								<td>
									<?php
									// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- UX::toggle() returns escaped markup.
									echo \Learnomy\Admin\UX::toggle(
										array(
											'name'    => 'learnomy_settings[enable_stripe]',
											'checked' => ! empty( $saved['enable_stripe'] ),
											'label'   => __( 'Accept payments via Stripe', 'learnomy' ),
										)
									);
									// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
									?>
									<p class="description"><?php esc_html_e( 'When unchecked, Stripe is completely disabled even if keys are saved.', 'learnomy' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Mode', 'learnomy' ); ?></th>
								<td>
									<div class="lrn-payment-mode-toggle" role="group" aria-label="<?php esc_attr_e( 'Stripe mode', 'learnomy' ); ?>">
										<label class="lrn-payment-mode-toggle__option">
											<input type="radio" name="learnomy_settings[stripe_mode]" value="test"
												<?php checked( $stripe_mode, 'test' ); ?>
												data-target="lrn-stripe-creds"
												data-badge="lrn-stripe-mode-badge"
												data-badge-test="<?php esc_attr_e( 'TEST MODE', 'learnomy' ); ?>"
												data-badge-live="<?php esc_attr_e( 'LIVE MODE', 'learnomy' ); ?>"
												class="lrn-payment-mode-radio lrn-payment-mode-radio--stripe">
											<?php esc_html_e( 'Test', 'learnomy' ); ?>
										</label>
										<label class="lrn-payment-mode-toggle__option">
											<input type="radio" name="learnomy_settings[stripe_mode]" value="live"
												<?php checked( $stripe_mode, 'live' ); ?>
												data-target="lrn-stripe-creds"
												data-badge="lrn-stripe-mode-badge"
												data-badge-test="<?php esc_attr_e( 'TEST MODE', 'learnomy' ); ?>"
												data-badge-live="<?php esc_attr_e( 'LIVE MODE', 'learnomy' ); ?>"
												class="lrn-payment-mode-radio lrn-payment-mode-radio--stripe">
											<?php esc_html_e( 'Live', 'learnomy' ); ?>
										</label>
									</div>
									<p class="description"><?php esc_html_e( 'Test mode uses Stripe test keys. Live mode charges real cards.', 'learnomy' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Webhook URL', 'learnomy' ); ?></th>
								<td>
									<div class="lrn-settings-webhook-url">
										<div class="lrn-settings-webhook-url__input">
											<input type="text" readonly
												value="<?php echo esc_attr( $stripe_webhook_url ); ?>"
												class="regular-text lrn-settings-webhook-url__field"
												aria-label="<?php esc_attr_e( 'Stripe webhook endpoint URL', 'learnomy' ); ?>">
											<button type="button"
												class="lrn-admin-btn lrn-admin-btn--sm lrn-settings-webhook-url__copy"
												data-copy="<?php echo esc_attr( $stripe_webhook_url ); ?>"
												aria-label="<?php esc_attr_e( 'Copy Stripe webhook URL', 'learnomy' ); ?>">
												<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
												<?php esc_html_e( 'Copy', 'learnomy' ); ?>
											</button>
										</div>
										<p class="description"><?php esc_html_e( 'Paste this into Stripe Dashboard &rarr; Developers &rarr; Webhooks &rarr; Add endpoint. Required events: checkout.session.completed, checkout.session.async_payment_succeeded, checkout.session.async_payment_failed, invoice.payment_succeeded, customer.subscription.deleted. The two async events are what clear delayed payment methods such as SEPA, Bacs, boleto, OXXO and Konbini, without them those buyers are charged but never enrolled.', 'learnomy' ); ?></p>
									</div>
								</td>
							</tr>
						</table>

						<!-- Test credentials fieldset -->
						<fieldset id="lrn-stripe-creds-test"
							class="lrn-payment-creds <?php echo 'test' === $stripe_mode ? 'lrn-payment-creds--active' : ''; ?>"
							data-mode="test">
							<legend class="lrn-payment-creds__legend"><?php esc_html_e( 'Test Credentials', 'learnomy' ); ?></legend>
							<table class="form-table">
								<tr>
									<th scope="row">
										<label for="lrn-stripe-test-pk"><?php esc_html_e( 'Test Publishable Key', 'learnomy' ); ?></label>
									</th>
									<td>
										<input type="text" id="lrn-stripe-test-pk"
											name="learnomy_settings[stripe_test_publishable]"
											value="<?php echo esc_attr( $stripe_test_pk ); ?>"
											class="regular-text" placeholder="pk_test_…">
									</td>
								</tr>
								<tr>
									<th scope="row">
										<label for="lrn-stripe-test-sk"><?php esc_html_e( 'Test Secret Key', 'learnomy' ); ?></label>
									</th>
									<td>
										<input type="password" id="lrn-stripe-test-sk"
											name="learnomy_encrypted[stripe_test_secret]"
											value="" class="regular-text"
											placeholder="<?php echo $has_stripe_test_sk ? '••••••••••••••••' : 'sk_test_…'; ?>">
											<?php $lrn_secret_saved_badge( $has_stripe_test_sk ); ?>
										<p class="description"><?php esc_html_e( 'Leave blank to keep the saved key.', 'learnomy' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row">
										<label for="lrn-stripe-test-wh"><?php esc_html_e( 'Test Webhook Signing Secret', 'learnomy' ); ?></label>
									</th>
									<td>
										<input type="password" id="lrn-stripe-test-wh"
											name="learnomy_encrypted[stripe_test_webhook_secret]"
											value="" class="regular-text"
											placeholder="<?php echo $has_stripe_test_wh ? '••••••••••••••••' : 'whsec_…'; ?>">
											<?php $lrn_secret_saved_badge( $has_stripe_test_wh ); ?>
										<p class="description"><?php esc_html_e( 'Found in Stripe Dashboard under Developers &rarr; Webhooks &rarr; your endpoint &rarr; Signing secret.', 'learnomy' ); ?></p>
									</td>
								</tr>
							</table>
						</fieldset>

						<!-- Live credentials fieldset -->
						<fieldset id="lrn-stripe-creds-live"
							class="lrn-payment-creds <?php echo 'live' === $stripe_mode ? 'lrn-payment-creds--active' : ''; ?>"
							data-mode="live">
							<legend class="lrn-payment-creds__legend"><?php esc_html_e( 'Live Credentials', 'learnomy' ); ?></legend>
							<table class="form-table">
								<tr>
									<th scope="row">
										<label for="lrn-stripe-live-pk"><?php esc_html_e( 'Live Publishable Key', 'learnomy' ); ?></label>
									</th>
									<td>
										<input type="text" id="lrn-stripe-live-pk"
											name="learnomy_settings[stripe_live_publishable]"
											value="<?php echo esc_attr( $stripe_live_pk ); ?>"
											class="regular-text" placeholder="pk_live_…">
									</td>
								</tr>
								<tr>
									<th scope="row">
										<label for="lrn-stripe-live-sk"><?php esc_html_e( 'Live Secret Key', 'learnomy' ); ?></label>
									</th>
									<td>
										<input type="password" id="lrn-stripe-live-sk"
											name="learnomy_encrypted[stripe_live_secret]"
											value="" class="regular-text"
											placeholder="<?php echo $has_stripe_live_sk ? '••••••••••••••••' : 'sk_live_…'; ?>">
											<?php $lrn_secret_saved_badge( $has_stripe_live_sk ); ?>
										<p class="description"><?php esc_html_e( 'Leave blank to keep the saved key.', 'learnomy' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row">
										<label for="lrn-stripe-live-wh"><?php esc_html_e( 'Live Webhook Signing Secret', 'learnomy' ); ?></label>
									</th>
									<td>
										<input type="password" id="lrn-stripe-live-wh"
											name="learnomy_encrypted[stripe_live_webhook_secret]"
											value="" class="regular-text"
											placeholder="<?php echo $has_stripe_live_wh ? '••••••••••••••••' : 'whsec_…'; ?>">
											<?php $lrn_secret_saved_badge( $has_stripe_live_wh ); ?>
										<p class="description"><?php esc_html_e( 'Found in Stripe Dashboard under Developers &rarr; Webhooks &rarr; your endpoint &rarr; Signing secret.', 'learnomy' ); ?></p>
									</td>
								</tr>
							</table>
						</fieldset>

						<div class="lrn-payment-card__actions">
							<button type="button"
								class="lrn-admin-btn lrn-admin-btn--sm lrn-payment-test-btn"
								data-provider="stripe"
								data-has-test-key="<?php echo $has_stripe_test_sk ? 'true' : 'false'; ?>"
								data-has-live-key="<?php echo $has_stripe_live_sk ? 'true' : 'false'; ?>"
								data-label-default="<?php esc_attr_e( 'Test Stripe Connection', 'learnomy' ); ?>"
								data-label-testing="<?php esc_attr_e( 'Testing…', 'learnomy' ); ?>">
								<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
								<?php esc_html_e( 'Test Stripe Connection', 'learnomy' ); ?>
							</button>
							<div class="lrn-payment-test-result" id="lrn-stripe-test-result" aria-live="polite"></div>
						</div>
					</div><!-- /.lrn-stripe-card -->

					<!-- ── PAYPAL CARD ──────────────────────────────────────── -->
					<div class="lrn-settings-card lrn-payment-card" id="lrn-paypal-card">
						<div class="lrn-settings-card__head lrn-payment-card__head">
							<div class="lrn-payment-card__title-row">
								<span class="lrn-payment-card__icon">
									<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="2" x2="22" y1="12" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
								</span>
								<h2 class="lrn-settings-card__title"><?php esc_html_e( 'PayPal', 'learnomy' ); ?></h2>
								<?php if ( 'sandbox' === $paypal_mode ) : ?>
									<span class="lrn-settings-badge lrn-settings-badge--test" id="lrn-paypal-mode-badge"><?php esc_html_e( 'SANDBOX MODE', 'learnomy' ); ?></span>
								<?php else : ?>
									<span class="lrn-settings-badge lrn-settings-badge--live" id="lrn-paypal-mode-badge"><?php esc_html_e( 'LIVE MODE', 'learnomy' ); ?></span>
								<?php endif; ?>
								<?php // Persistent connected indicator: enabled AND client + secret saved for the active mode. The Test Connection button below verifies the credentials are live. ?>
								<span class="lrn-settings-badge lrn-settings-badge--success" id="lrn-paypal-connected-badge"
									<?php echo \Learnomy\Services\Payment_Settings::paypal_enabled() ? '' : 'hidden'; ?>>
									<i data-lucide="check-circle" aria-hidden="true"></i><?php esc_html_e( 'Connected', 'learnomy' ); ?>
								</span>
							</div>
							<p class="lrn-settings-card__desc"><?php esc_html_e( 'Accept PayPal payments for one-time purchases and subscriptions.', 'learnomy' ); ?></p>
						</div>

						<?php $lrn_wc_takeover_notice( __( 'PayPal', 'learnomy' ) ); ?>

						<table class="form-table">
							<tr>
								<th scope="row"><?php esc_html_e( 'Enable PayPal', 'learnomy' ); ?></th>
								<td>
									<?php
									// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- UX::toggle() returns escaped markup.
									echo \Learnomy\Admin\UX::toggle(
										array(
											'name'    => 'learnomy_settings[enable_paypal]',
											'checked' => ! empty( $saved['enable_paypal'] ),
											'label'   => __( 'Accept payments via PayPal', 'learnomy' ),
										)
									);
									// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
									?>
									<p class="description"><?php esc_html_e( 'When unchecked, PayPal is completely disabled even if credentials are saved.', 'learnomy' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Mode', 'learnomy' ); ?></th>
								<td>
									<div class="lrn-payment-mode-toggle" role="group" aria-label="<?php esc_attr_e( 'PayPal mode', 'learnomy' ); ?>">
										<label class="lrn-payment-mode-toggle__option">
											<input type="radio" name="learnomy_settings[paypal_mode]" value="sandbox"
												<?php checked( $paypal_mode, 'sandbox' ); ?>
												data-target="lrn-paypal-creds"
												data-badge="lrn-paypal-mode-badge"
												data-badge-test="<?php esc_attr_e( 'SANDBOX MODE', 'learnomy' ); ?>"
												data-badge-live="<?php esc_attr_e( 'LIVE MODE', 'learnomy' ); ?>"
												class="lrn-payment-mode-radio lrn-payment-mode-radio--paypal">
											<?php esc_html_e( 'Sandbox', 'learnomy' ); ?>
										</label>
										<label class="lrn-payment-mode-toggle__option">
											<input type="radio" name="learnomy_settings[paypal_mode]" value="live"
												<?php checked( $paypal_mode, 'live' ); ?>
												data-target="lrn-paypal-creds"
												data-badge="lrn-paypal-mode-badge"
												data-badge-test="<?php esc_attr_e( 'SANDBOX MODE', 'learnomy' ); ?>"
												data-badge-live="<?php esc_attr_e( 'LIVE MODE', 'learnomy' ); ?>"
												class="lrn-payment-mode-radio lrn-payment-mode-radio--paypal">
											<?php esc_html_e( 'Live', 'learnomy' ); ?>
										</label>
									</div>
									<p class="description"><?php esc_html_e( 'Sandbox uses PayPal developer test accounts. Live charges real PayPal balances.', 'learnomy' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Webhook URL', 'learnomy' ); ?></th>
								<td>
									<div class="lrn-settings-webhook-url">
										<div class="lrn-settings-webhook-url__input">
											<input type="text" readonly
												value="<?php echo esc_attr( $paypal_webhook_url ); ?>"
												class="regular-text lrn-settings-webhook-url__field"
												aria-label="<?php esc_attr_e( 'PayPal webhook endpoint URL', 'learnomy' ); ?>">
											<button type="button"
												class="lrn-admin-btn lrn-admin-btn--sm lrn-settings-webhook-url__copy"
												data-copy="<?php echo esc_attr( $paypal_webhook_url ); ?>"
												aria-label="<?php esc_attr_e( 'Copy PayPal webhook URL', 'learnomy' ); ?>">
												<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
												<?php esc_html_e( 'Copy', 'learnomy' ); ?>
											</button>
										</div>
										<p class="description"><?php esc_html_e( 'Paste this into PayPal Developer Dashboard &rarr; My Apps &rarr; your app &rarr; Webhooks &rarr; Add webhook.', 'learnomy' ); ?></p>
									</div>
								</td>
							</tr>
						</table>

						<!-- Sandbox credentials fieldset -->
						<fieldset id="lrn-paypal-creds-sandbox"
							class="lrn-payment-creds <?php echo 'sandbox' === $paypal_mode ? 'lrn-payment-creds--active' : ''; ?>"
							data-mode="sandbox">
							<legend class="lrn-payment-creds__legend"><?php esc_html_e( 'Sandbox Credentials', 'learnomy' ); ?></legend>
							<table class="form-table">
								<tr>
									<th scope="row">
										<label for="lrn-paypal-sb-client"><?php esc_html_e( 'Sandbox Client ID', 'learnomy' ); ?></label>
									</th>
									<td>
										<input type="text" id="lrn-paypal-sb-client"
											name="learnomy_settings[paypal_sandbox_client]"
											value="<?php echo esc_attr( $paypal_sandbox_client ); ?>"
											class="regular-text" placeholder="AaBb…">
									</td>
								</tr>
								<tr>
									<th scope="row">
										<label for="lrn-paypal-sb-secret"><?php esc_html_e( 'Sandbox Secret', 'learnomy' ); ?></label>
									</th>
									<td>
										<input type="password" id="lrn-paypal-sb-secret"
											name="learnomy_encrypted[paypal_sandbox_secret]"
											value="" class="regular-text"
											placeholder="<?php echo $has_paypal_sb_secret ? '••••••••••••••••' : ''; ?>">
											<?php $lrn_secret_saved_badge( $has_paypal_sb_secret ); ?>
										<p class="description"><?php esc_html_e( 'Leave blank to keep the saved secret.', 'learnomy' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row">
										<label for="lrn-paypal-sb-wh-id"><?php esc_html_e( 'Sandbox Webhook ID', 'learnomy' ); ?></label>
									</th>
									<td>
										<input type="text" id="lrn-paypal-sb-wh-id"
											name="learnomy_settings[paypal_sandbox_webhook_id]"
											value="<?php echo esc_attr( $paypal_sandbox_wh_id ); ?>"
											class="regular-text" placeholder="1AB23456CD789012E">
										<p class="description"><?php esc_html_e( 'Found in PayPal Developer Dashboard &rarr; your app &rarr; Webhooks. Required for webhook signature verification.', 'learnomy' ); ?></p>
									</td>
								</tr>
							</table>
						</fieldset>

						<!-- Live credentials fieldset -->
						<fieldset id="lrn-paypal-creds-live"
							class="lrn-payment-creds <?php echo 'live' === $paypal_mode ? 'lrn-payment-creds--active' : ''; ?>"
							data-mode="live">
							<legend class="lrn-payment-creds__legend"><?php esc_html_e( 'Live Credentials', 'learnomy' ); ?></legend>
							<table class="form-table">
								<tr>
									<th scope="row">
										<label for="lrn-paypal-live-client"><?php esc_html_e( 'Live Client ID', 'learnomy' ); ?></label>
									</th>
									<td>
										<input type="text" id="lrn-paypal-live-client"
											name="learnomy_settings[paypal_live_client]"
											value="<?php echo esc_attr( $paypal_live_client ); ?>"
											class="regular-text" placeholder="AaBb…">
									</td>
								</tr>
								<tr>
									<th scope="row">
										<label for="lrn-paypal-live-secret"><?php esc_html_e( 'Live Secret', 'learnomy' ); ?></label>
									</th>
									<td>
										<input type="password" id="lrn-paypal-live-secret"
											name="learnomy_encrypted[paypal_live_secret]"
											value="" class="regular-text"
											placeholder="<?php echo $has_paypal_live_secret ? '••••••••••••••••' : ''; ?>">
											<?php $lrn_secret_saved_badge( $has_paypal_live_secret ); ?>
										<p class="description"><?php esc_html_e( 'Leave blank to keep the saved secret.', 'learnomy' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row">
										<label for="lrn-paypal-live-wh-id"><?php esc_html_e( 'Live Webhook ID', 'learnomy' ); ?></label>
									</th>
									<td>
										<input type="text" id="lrn-paypal-live-wh-id"
											name="learnomy_settings[paypal_live_webhook_id]"
											value="<?php echo esc_attr( $paypal_live_wh_id ); ?>"
											class="regular-text" placeholder="1AB23456CD789012E">
										<p class="description"><?php esc_html_e( 'Found in PayPal Developer Dashboard &rarr; your app &rarr; Webhooks. Required for webhook signature verification.', 'learnomy' ); ?></p>
									</td>
								</tr>
							</table>
						</fieldset>

						<div class="lrn-payment-card__actions">
							<button type="button"
								class="lrn-admin-btn lrn-admin-btn--sm lrn-payment-test-btn"
								data-provider="paypal"
								data-has-test-key="<?php echo $has_paypal_sb_secret ? 'true' : 'false'; ?>"
								data-has-live-key="<?php echo $has_paypal_live_secret ? 'true' : 'false'; ?>"
								data-label-default="<?php esc_attr_e( 'Test PayPal Connection', 'learnomy' ); ?>"
								data-label-testing="<?php esc_attr_e( 'Testing…', 'learnomy' ); ?>">
								<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
								<?php esc_html_e( 'Test PayPal Connection', 'learnomy' ); ?>
							</button>
							<div class="lrn-payment-test-result" id="lrn-paypal-test-result" aria-live="polite"></div>
						</div>
					</div><!-- /.lrn-paypal-card -->

					<!-- ── CHECKOUT OPTIONS CARD ────────────────────────────── -->
					<div class="lrn-settings-card">
						<div class="lrn-settings-card__head">
							<h2 class="lrn-settings-card__title"><?php esc_html_e( 'Checkout', 'learnomy' ); ?></h2>
							<p class="lrn-settings-card__desc"><?php esc_html_e( 'General checkout and pricing options.', 'learnomy' ); ?></p>
						</div>
						<table class="form-table">
							<tr>
								<th scope="row"><?php esc_html_e( 'Business Name', 'learnomy' ); ?></th>
								<td>
									<input type="text" name="learnomy_settings[business_name]"
										value="<?php echo esc_attr( $saved['business_name'] ?? '' ); ?>"
										class="regular-text"
										placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
									<p class="description"><?php esc_html_e( 'Appears on receipt PDFs and invoice emails. Defaults to your site title if blank.', 'learnomy' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Money-Back Guarantee', 'learnomy' ); ?></th>
								<td>
									<input type="number" name="learnomy_settings[refund_days]"
										value="<?php echo esc_attr( $saved['refund_days'] ?? 30 ); ?>"
										class="small-text" min="0">
									<p class="description"><?php esc_html_e( 'Days for refund guarantee. Set to 0 to disable. Surfaces on course sales pages and checkout.', 'learnomy' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="lrn-refund-policy"><?php esc_html_e( 'Refund Policy', 'learnomy' ); ?></label>
								</th>
								<td>
									<textarea
										id="lrn-refund-policy"
										name="learnomy_settings[refund_policy_text]"
										rows="5"
										class="large-text"
										placeholder="<?php esc_attr_e( 'We offer a 30-day money-back guarantee. To request a refund, email support with your order ID.', 'learnomy' ); ?>"><?php echo esc_textarea( $saved['refund_policy_text'] ?? '' ); ?></textarea>
									<p class="description"><?php esc_html_e( 'Shown at checkout and on receipt PDFs. Plain English; no HTML required.', 'learnomy' ); ?></p>
								</td>
							</tr>
						</table>
					</div>

					<!-- ── WOOCOMMERCE CARD ────────────────────────────────────
						Optional. Routes Learnomy purchases through WooCommerce
						checkout instead of Learnomy's own Stripe/PayPal flow.
						Installing WooCommerce does NOT force its use — this
						toggle is the only thing that surfaces it at checkout. -->
					<?php $lrn_wc_active = class_exists( 'WooCommerce' ); ?>
					<div class="lrn-settings-card lrn-payment-card" id="lrn-woocommerce-card">
						<div class="lrn-settings-card__head lrn-payment-card__head">
							<div class="lrn-payment-card__title-row">
								<span class="lrn-payment-card__icon">
									<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
								</span>
								<h2 class="lrn-settings-card__title"><?php esc_html_e( 'WooCommerce', 'learnomy' ); ?></h2>
								<?php if ( ! $lrn_wc_active ) : ?>
									<span class="lrn-settings-badge"><?php esc_html_e( 'PLUGIN NOT ACTIVE', 'learnomy' ); ?></span>
								<?php endif; ?>
							</div>
							<p class="lrn-settings-card__desc">
								<?php esc_html_e( 'Route Learnomy purchases through WooCommerce checkout. Optional: installing WooCommerce does not change anything on its own. When off, Learnomy uses its own Stripe and PayPal checkout.', 'learnomy' ); ?>
							</p>
						</div>

						<table class="form-table">
							<tr>
								<th scope="row"><?php esc_html_e( 'Use WooCommerce', 'learnomy' ); ?></th>
								<td>
									<?php
									// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- UX::toggle() returns escaped markup.
									echo \Learnomy\Admin\UX::toggle(
										array(
											'name'     => 'learnomy_settings[enable_woocommerce]',
											'checked'  => ! empty( $saved['enable_woocommerce'] ),
											'disabled' => ! $lrn_wc_active,
											'label'    => __( 'Send Learnomy purchases to the WooCommerce cart and checkout', 'learnomy' ),
										)
									);
									// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
									?>
									<p class="description">
										<?php
										if ( $lrn_wc_active ) {
											esc_html_e( "When enabled, paid courses and memberships check out through WooCommerce (one dynamic line item per purchase). Requires WooCommerce to stay active. Leave off to keep Learnomy's built-in Stripe/PayPal checkout.", 'learnomy' );
										} else {
											esc_html_e( 'Activate the WooCommerce plugin to use this option. Learnomy keeps using its own Stripe/PayPal checkout until then.', 'learnomy' );
										}
										?>
									</p>
								</td>
							</tr>
						</table>
					</div>

					<!-- ── TEST GATEWAY CARD ───────────────────────────────────
						Dev-only adapter that records a completed transaction +
						enrolls the user instantly, with no external HTTP call.
						Lives here so customers find it where they'd look for
						it (Payments tab), not buried under Course Setup. -->
					<div class="lrn-settings-card lrn-payment-card" id="lrn-test-gateway-card">
						<div class="lrn-settings-card__head lrn-payment-card__head">
							<div class="lrn-payment-card__title-row">
								<span class="lrn-payment-card__icon">
									<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
								</span>
								<h2 class="lrn-settings-card__title"><?php esc_html_e( 'Test Gateway', 'learnomy' ); ?></h2>
								<span class="lrn-settings-badge lrn-settings-badge--test"><?php esc_html_e( 'DEVELOPMENT ONLY', 'learnomy' ); ?></span>
							</div>
							<p class="lrn-settings-card__desc">
								<?php esc_html_e( 'Synchronous in-memory gateway that completes every checkout without hitting an external service. Useful for development, demos, and QA. Turn this off before going live.', 'learnomy' ); ?>
							</p>
						</div>

						<table class="form-table">
							<tr>
								<th scope="row"><?php esc_html_e( 'Enable Test Gateway', 'learnomy' ); ?></th>
								<td>
									<?php
									// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- UX::toggle() returns escaped markup.
									echo \Learnomy\Admin\UX::toggle(
										array(
											'name'    => 'learnomy_settings[enable_test_gateway]',
											'checked' => ! empty( $saved['enable_test_gateway'] ),
											'label'   => __( 'Show the Test Gateway option at checkout', 'learnomy' ),
										)
									);
									// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
									?>
									<p class="description">
										<?php esc_html_e( 'When enabled, paid courses show a "Test Gateway" option alongside Stripe and PayPal. Clicking it instantly records a completed transaction and enrolls the buyer.', 'learnomy' ); ?>
									</p>
								</td>
							</tr>
						</table>
					</div>

					<!-- ── TAX CARD ─────────────────────────────────────────── -->
					<?php
					$lrn_tax_enabled = ! empty( $saved['tax_enabled'] );
					$lrn_tax_rate    = isset( $saved['tax_rate'] ) ? (float) $saved['tax_rate'] : 0.0;
					$lrn_tax_label   = ( isset( $saved['tax_label'] ) && '' !== $saved['tax_label'] ) ? (string) $saved['tax_label'] : __( 'Tax', 'learnomy' );
					?>
					<div class="lrn-settings-card lrn-payment-card" id="lrn-tax-card">
						<div class="lrn-settings-card__head">
							<h2 class="lrn-settings-card__title"><?php esc_html_e( 'Tax', 'learnomy' ); ?></h2>
							<p class="lrn-settings-card__desc">
								<?php esc_html_e( 'Add one flat tax rate to every paid checkout, for basic compliance. Need per-region rates, exemptions, or reporting? Use the WooCommerce gateway, which brings its own tax engine.', 'learnomy' ); ?>
							</p>
							<p class="lrn-settings-card__desc">
								<i data-lucide="info" aria-hidden="true"></i>
								<strong><?php esc_html_e( 'Applies to Stripe and PayPal.', 'learnomy' ); ?></strong>
								<?php esc_html_e( 'Both gateways add this rate to one-time purchases and to subscriptions, so the same course costs the same whichever your customer picks. WooCommerce uses its own tax engine instead, which will override this. If you have also set a tax rate inside a PayPal billing plan, this rate replaces it rather than stacking on top.', 'learnomy' ); ?>
							</p>
						</div>
						<table class="form-table">
							<tr>
								<th scope="row"><?php esc_html_e( 'Collect tax', 'learnomy' ); ?></th>
								<td>
									<?php
									// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- UX::toggle() returns escaped markup.
									echo \Learnomy\Admin\UX::toggle(
										array(
											'name'    => 'learnomy_settings[tax_enabled]',
											'checked' => $lrn_tax_enabled,
											// Not "Stripe checkouts" any more: 1.7.0 made PayPal charge tax too,
											// which turned this label into a false statement about what the
											// toggle does.
											'label'   => __( 'Add a flat tax rate to paid checkouts', 'learnomy' ),
										)
									);
									// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
									?>
									<p class="description">
										<?php esc_html_e( 'When enabled, the tax shows on the order summary and on the secure card form, and is charged on top of the price.', 'learnomy' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="lrn-tax-rate"><?php esc_html_e( 'Tax rate (%)', 'learnomy' ); ?></label></th>
								<td>
									<input type="number" id="lrn-tax-rate" name="learnomy_settings[tax_rate]"
										value="<?php echo esc_attr( (string) $lrn_tax_rate ); ?>"
										step="0.01" min="0" max="100" class="small-text">
									<p class="description"><?php esc_html_e( 'A single percentage applied to every paid purchase, e.g. 20 for 20%.', 'learnomy' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="lrn-tax-label"><?php esc_html_e( 'Tax label', 'learnomy' ); ?></label></th>
								<td>
									<input type="text" id="lrn-tax-label" name="learnomy_settings[tax_label]"
										value="<?php echo esc_attr( $lrn_tax_label ); ?>"
										class="regular-text" placeholder="<?php esc_attr_e( 'Tax', 'learnomy' ); ?>">
									<p class="description"><?php esc_html_e( 'What to call the tax on the order summary, e.g. Tax, VAT, GST.', 'learnomy' ); ?></p>
								</td>
							</tr>
						</table>
					</div>

					<!-- ── SUBSCRIPTION GRACE CARD ──────────────────────────── -->
					<?php $lrn_grace_days = \Learnomy\subscription_grace_days(); ?>
					<div class="lrn-settings-card lrn-payment-card" id="lrn-grace-card">
						<div class="lrn-settings-card__head">
							<h2 class="lrn-settings-card__title"><?php esc_html_e( 'Failed payments', 'learnomy' ); ?></h2>
							<p class="lrn-settings-card__desc">
								<?php esc_html_e( 'When a renewal payment fails, the subscription is marked past due and the member keeps access for a short grace period while the card is retried. This was fixed at 7 days and could not be changed; now you decide.', 'learnomy' ); ?>
							</p>
						</div>
						<table class="form-table">
							<tr>
								<th scope="row"><label for="lrn-grace-days"><?php esc_html_e( 'Grace period', 'learnomy' ); ?></label></th>
								<td>
									<input type="number" id="lrn-grace-days" name="learnomy_settings[subscription_grace_days]"
										value="<?php echo esc_attr( (string) $lrn_grace_days ); ?>"
										step="1" min="0" max="90" class="small-text">
									<span><?php esc_html_e( 'days', 'learnomy' ); ?></span>
									<p class="description">
										<?php esc_html_e( 'How long a past-due member keeps access before it is revoked. Set 0 to cut access off as soon as the paid period ends. A member who cancels deliberately always loses access at the end of their period, with no grace.', 'learnomy' ); ?>
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

			<!-- MEMBERSHIP PAGE -->
			<div class="lrn-settings-section" id="section-membership-page">
				<form method="post" action="options.php">
					<?php settings_fields( 'learnomy_settings' ); ?>

					<div class="lrn-settings-card">
						<div class="lrn-settings-card__head">
							<h2 class="lrn-settings-card__title"><?php esc_html_e( 'Membership page content', 'learnomy' ); ?></h2>
							<p class="lrn-settings-card__desc"><?php esc_html_e( 'The headline, intro, and FAQ shown on the public membership pricing page.', 'learnomy' ); ?></p>
						</div>
						<table class="form-table">
							<tr>
								<th scope="row"><label for="lrn-membership-headline"><?php esc_html_e( 'Headline', 'learnomy' ); ?></label></th>
								<td>
									<input type="text" id="lrn-membership-headline" name="learnomy_settings[membership_headline]" value="<?php echo esc_attr( $saved['membership_headline'] ?? '' ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Simple, transparent pricing', 'learnomy' ); ?>">
									<p class="description"><?php esc_html_e( 'Leave blank to use the default.', 'learnomy' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="lrn-membership-subtitle"><?php esc_html_e( 'Subtitle', 'learnomy' ); ?></label></th>
								<td>
									<input type="text" id="lrn-membership-subtitle" name="learnomy_settings[membership_subtitle]" value="<?php echo esc_attr( $saved['membership_subtitle'] ?? '' ); ?>" class="large-text" placeholder="<?php esc_attr_e( 'One subscription. Cancel anytime.', 'learnomy' ); ?>">
								</td>
							</tr>
						</table>
					</div>

					<div class="lrn-settings-card">
						<div class="lrn-settings-card__head">
							<h2 class="lrn-settings-card__title"><?php esc_html_e( 'Page sections', 'learnomy' ); ?></h2>
							<p class="lrn-settings-card__desc"><?php esc_html_e( 'Show or hide optional sections on the membership page.', 'learnomy' ); ?></p>
						</div>
						<table class="form-table">
							<tr>
								<th scope="row"><?php esc_html_e( 'FAQ', 'learnomy' ); ?></th>
								<td>
									<input type="hidden" name="learnomy_settings[show_membership_faq]" value="0">
									<?php
									// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- UX::toggle() returns escaped markup.
									echo \Learnomy\Admin\UX::toggle(
										array(
											'name'    => 'learnomy_settings[show_membership_faq]',
											'checked' => ! isset( $saved['show_membership_faq'] ) || ! empty( $saved['show_membership_faq'] ),
											'label'   => __( 'Show the FAQ section', 'learnomy' ),
										)
									);
									// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
									?>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Gifting', 'learnomy' ); ?></th>
								<td>
									<input type="hidden" name="learnomy_settings[show_membership_gift]" value="0">
									<?php
									// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- UX::toggle() returns escaped markup.
									echo \Learnomy\Admin\UX::toggle(
										array(
											'name'    => 'learnomy_settings[show_membership_gift]',
											'checked' => ! isset( $saved['show_membership_gift'] ) || ! empty( $saved['show_membership_gift'] ),
											'label'   => __( 'Show gift redeem / buy-as-a-gift widgets (requires Learnomy Pro)', 'learnomy' ),
										)
									);
									// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
									?>
								</td>
							</tr>
						</table>
					</div>

					<div class="lrn-settings-card">
						<div class="lrn-settings-card__head">
							<h2 class="lrn-settings-card__title"><?php esc_html_e( 'Frequently asked questions', 'learnomy' ); ?></h2>
							<p class="lrn-settings-card__desc"><?php esc_html_e( 'Edit the Q&A shown on the membership page. Blank rows are ignored on save.', 'learnomy' ); ?></p>
						</div>
						<?php
						$lrn_faq_saved = \Learnomy\membership_faq_items();
						// One row of markup, shared by the saved rows and the <template>
						// the "Add question" button clones (INDEX placeholder swapped by
						// faq-repeater.js). Blank rows are dropped on save.
						$lrn_faq_row = static function ( $index, $question, $answer ) {
							ob_start();
							?>
							<div class="lrn-faq-repeater__row" data-lrn-faq-row>
								<div class="lrn-faq-repeater__fields">
									<input type="text" name="learnomy_settings[membership_faq][<?php echo esc_attr( (string) $index ); ?>][q]" value="<?php echo esc_attr( (string) $question ); ?>" class="large-text" placeholder="<?php esc_attr_e( 'Question', 'learnomy' ); ?>" aria-label="<?php esc_attr_e( 'Question', 'learnomy' ); ?>">
									<textarea name="learnomy_settings[membership_faq][<?php echo esc_attr( (string) $index ); ?>][a]" rows="2" class="large-text lrn-faq-answer" placeholder="<?php esc_attr_e( 'Answer', 'learnomy' ); ?>" aria-label="<?php esc_attr_e( 'Answer', 'learnomy' ); ?>"><?php echo esc_textarea( (string) $answer ); ?></textarea>
								</div>
								<button type="button" class="lrn-admin-btn lrn-admin-btn--ghost lrn-admin-btn--icon" data-lrn-faq-remove aria-label="<?php esc_attr_e( 'Remove this question', 'learnomy' ); ?>">
									<i data-lucide="trash-2" aria-hidden="true"></i>
								</button>
							</div>
							<?php
							return (string) ob_get_clean();
						};
						?>
						<div class="lrn-faq-repeater" data-lrn-faq-repeater>
							<?php
							foreach ( $lrn_faq_saved as $lrn_i => $lrn_item ) {
								// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- values escaped inside the closure.
								echo $lrn_faq_row( (int) $lrn_i, $lrn_item['q'] ?? '', $lrn_item['a'] ?? '' );
							}
							?>
						</div>
						<template id="lrn-faq-row-template">
							<?php
							// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the closure.
							echo $lrn_faq_row( '__INDEX__', '', '' );
							?>
						</template>
						<p>
							<button type="button" class="lrn-admin-btn lrn-admin-btn--ghost" data-lrn-faq-add>
								<i data-lucide="plus" aria-hidden="true"></i>
								<?php esc_html_e( 'Add Question', 'learnomy' ); ?>
							</button>
						</p>
					</div>

					<div class="lrn-settings-section__footer">
						<?php submit_button( __( 'Save Changes', 'learnomy' ), 'primary', 'submit', false ); ?>
					</div>
				</form>
			</div>

			<!-- ═══════════════════════════════════════════════════════════
				INSTRUCTORS
				═══════════════════════════════════════════════════════════ -->
			<div class="lrn-settings-section" id="section-instructors">
				<form method="post" action="options.php">
					<?php settings_fields( 'learnomy_settings' ); ?>

					<div class="lrn-settings-card">
						<div class="lrn-settings-card__head">
							<h2 class="lrn-settings-card__title"><?php esc_html_e( 'Instructor Permissions', 'learnomy' ); ?></h2>
							<p class="lrn-settings-card__desc"><?php esc_html_e( 'Control what instructors can do on your platform.', 'learnomy' ); ?></p>
						</div>
						<table class="form-table">
							<tr>
								<th scope="row"><?php esc_html_e( 'Self-Publish Courses', 'learnomy' ); ?></th>
								<td>
									<?php
									// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- UX::toggle() returns escaped markup.
									echo \Learnomy\Admin\UX::toggle(
										array(
											'name'    => 'learnomy_settings[instructor_self_publish]',
											'checked' => ! array_key_exists( 'instructor_self_publish', (array) $saved ) || ! empty( $saved['instructor_self_publish'] ),
											'label'   => __( 'Allow instructors to publish courses without admin approval', 'learnomy' ),
											'help'    => __( 'Turn this off to require admin approval: an instructor\'s course is submitted as Pending Review, and an admin approves or rejects it from the Courses list. You can also require approval for a single instructor from their profile, even while this is on.', 'learnomy' ),
										)
									);
									// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
									?>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Multi-Instructor', 'learnomy' ); ?></th>
								<td>
									<?php
									// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- UX::toggle() returns escaped markup.
									echo \Learnomy\Admin\UX::toggle(
										array(
											'name'    => 'learnomy_settings[multi_instructor]',
											'checked' => ! empty( $saved['multi_instructor'] ),
											'label'   => __( 'Allow multiple instructors per course with revenue share', 'learnomy' ),
										)
									);
									// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
									?>
								</td>
							</tr>
						</table>
					</div>

					<div class="lrn-settings-section__footer">
						<?php submit_button( __( 'Save Changes', 'learnomy' ), 'primary', 'submit', false ); ?>
					</div>
				</form>
			</div>

			<!-- ═══════════════════════════════════════════════════════════
				COMMISSIONS (separated from Instructors)
				═══════════════════════════════════════════════════════════ -->
			<div class="lrn-settings-section" id="section-commissions">
				<form method="post" action="options.php">
					<?php settings_fields( 'learnomy_settings' ); ?>

					<div class="lrn-settings-card">
						<div class="lrn-settings-card__head">
							<h2 class="lrn-settings-card__title"><?php esc_html_e( 'Commission Rules', 'learnomy' ); ?></h2>
							<p class="lrn-settings-card__desc"><?php esc_html_e( 'Revenue split between the platform and instructors.', 'learnomy' ); ?></p>
						</div>
						<table class="form-table">
							<tr>
								<th scope="row">
									<label for="lrn-default-commission"><?php esc_html_e( 'Platform Commission (%)', 'learnomy' ); ?></label>
								</th>
								<td>
									<input type="number" id="lrn-default-commission" name="learnomy_settings[platform_commission]"
										value="<?php echo esc_attr( $saved['platform_commission'] ?? 30 ); ?>"
										class="small-text" min="0" max="100" step="1">
									<p class="description"><?php esc_html_e( 'Percentage the platform keeps from each sale. The instructor receives the remainder.', 'learnomy' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="lrn-payout-period"><?php esc_html_e( 'Payout Period', 'learnomy' ); ?></label>
								</th>
								<td>
									<select id="lrn-payout-period" name="learnomy_settings[payout_period]">
										<option value="monthly" <?php selected( $saved['payout_period'] ?? 'monthly', 'monthly' ); ?>><?php esc_html_e( 'Monthly', 'learnomy' ); ?></option>
										<option value="biweekly" <?php selected( $saved['payout_period'] ?? 'monthly', 'biweekly' ); ?>><?php esc_html_e( 'Bi-weekly', 'learnomy' ); ?></option>
										<option value="weekly" <?php selected( $saved['payout_period'] ?? 'monthly', 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'learnomy' ); ?></option>
									</select>
								</td>
							</tr>
						</table>
					</div>

					<div class="lrn-settings-card">
						<div class="lrn-settings-card__head">
							<h2 class="lrn-settings-card__title"><?php esc_html_e( 'Payment Processing Fee', 'learnomy' ); ?></h2>
							<p class="lrn-settings-card__desc"><?php esc_html_e( 'Take the gateway fee off the top before splitting revenue, so the platform and instructor both share the processing cost instead of the platform absorbing all of it.', 'learnomy' ); ?></p>
						</div>
						<table class="form-table">
							<tr>
								<th scope="row"><?php esc_html_e( 'Deduct fee', 'learnomy' ); ?></th>
								<td>
									<?php
									// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- UX::toggle() returns escaped markup.
									echo \Learnomy\Admin\UX::toggle(
										array(
											'name'    => 'learnomy_settings[commission_deduct_fee]',
											'id'      => 'lrn-commission-deduct-fee',
											'checked' => ! empty( $saved['commission_deduct_fee'] ),
											'label'   => __( 'Deduct the processing fee before the commission split', 'learnomy' ),
										)
									);
									// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
									?>
									<p class="description"><?php esc_html_e( 'When off, commissions are calculated on the full amount the customer paid.', 'learnomy' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="lrn-commission-fee-percent"><?php esc_html_e( 'Fee (%)', 'learnomy' ); ?></label>
								</th>
								<td>
									<input type="number" id="lrn-commission-fee-percent" name="learnomy_settings[commission_fee_percent]"
										value="<?php echo esc_attr( $saved['commission_fee_percent'] ?? '2.9' ); ?>"
										class="small-text" min="0" max="100" step="0.1">
									<p class="description"><?php esc_html_e( 'Percentage of each sale the payment provider keeps. Most cards are around 2.9%.', 'learnomy' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="lrn-commission-fee-fixed"><?php esc_html_e( 'Fixed fee per sale', 'learnomy' ); ?></label>
								</th>
								<td>
									<input type="number" id="lrn-commission-fee-fixed" name="learnomy_settings[commission_fee_fixed]"
										value="<?php echo esc_attr( $saved['commission_fee_fixed'] ?? '0.30' ); ?>"
										class="small-text" min="0" max="9999.99" step="0.01">
									<p class="description"><?php esc_html_e( 'Flat amount the provider adds per transaction, in your store currency. Most cards add about 0.30.', 'learnomy' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Tax in base', 'learnomy' ); ?></th>
								<td>
									<?php
									// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- UX::toggle() returns escaped markup.
									echo \Learnomy\Admin\UX::toggle(
										array(
											'name'    => 'learnomy_settings[commission_tax_in_base]',
											'id'      => 'lrn-commission-tax-in-base',
											'checked' => ! empty( $saved['commission_tax_in_base'] ),
											'label'   => __( 'Include collected tax in the commission base', 'learnomy' ),
										)
									);
									// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
									?>
									<p class="description"><?php esc_html_e( 'Leave off so tax your gateway collects is set aside before the split and not paid out as revenue. Only matters when a gateway reports tax.', 'learnomy' ); ?></p>
								</td>
							</tr>
						</table>
					</div>

					<div class="lrn-settings-card">
						<div class="lrn-settings-card__head">
							<h2 class="lrn-settings-card__title"><?php esc_html_e( 'Withdrawals', 'learnomy' ); ?></h2>
							<p class="lrn-settings-card__desc"><?php esc_html_e( 'Instructor payout thresholds and methods.', 'learnomy' ); ?></p>
						</div>
						<table class="form-table">
							<tr>
								<th scope="row">
									<label for="lrn-min-withdrawal"><?php esc_html_e( 'Minimum Withdrawal ($)', 'learnomy' ); ?></label>
								</th>
								<td>
									<input type="number" id="lrn-min-withdrawal" name="learnomy_settings[withdrawal_minimum]"
										value="<?php echo esc_attr( $saved['withdrawal_minimum'] ?? 25 ); ?>"
										class="small-text" min="0" step="1">
									<p class="description"><?php esc_html_e( 'Instructors must accumulate at least this amount before requesting a payout.', 'learnomy' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Payout Methods', 'learnomy' ); ?></th>
								<td>
									<?php
									// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- UX::toggle() returns escaped markup.
									echo \Learnomy\Admin\UX::toggle(
										array(
											'name'    => 'learnomy_settings[payout_paypal]',
											'checked' => ! empty( $saved['payout_paypal'] ),
											'label'   => __( 'PayPal', 'learnomy' ),
										)
									);
									// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
									?>
									<?php
									// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- UX::toggle() returns escaped markup.
									echo \Learnomy\Admin\UX::toggle(
										array(
											'name'    => 'learnomy_settings[payout_bank]',
											'checked' => ! empty( $saved['payout_bank'] ),
											'label'   => __( 'Bank Transfer', 'learnomy' ),
										)
									);
									// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
									?>
								</td>
							</tr>
						</table>
					</div>

					<div class="lrn-settings-section__footer">
						<?php submit_button( __( 'Save Changes', 'learnomy' ), 'primary', 'submit', false ); ?>
					</div>
				</form>
			</div>

			<!-- ═══════════════════════════════════════════════════════════
				EMAILS
				═══════════════════════════════════════════════════════════ -->
			<div class="lrn-settings-section" id="section-emails">
				<form method="post" action="options.php">
					<?php settings_fields( 'learnomy_settings' ); ?>

					<div class="lrn-settings-card">
						<div class="lrn-settings-card__head">
							<h2 class="lrn-settings-card__title"><?php esc_html_e( 'Email Sender', 'learnomy' ); ?></h2>
							<p class="lrn-settings-card__desc"><?php esc_html_e( 'Configure the sender name and email for all Learnomy notifications.', 'learnomy' ); ?></p>
						</div>
						<table class="form-table">
							<tr>
								<th scope="row">
									<label for="lrn-sender-name"><?php esc_html_e( 'Sender Name', 'learnomy' ); ?></label>
								</th>
								<td>
									<input type="text" id="lrn-sender-name" name="learnomy_settings[email_sender_name]"
										value="<?php echo esc_attr( $saved['email_sender_name'] ?? '' ); ?>"
										placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"
										class="regular-text">
									<p class="description"><?php esc_html_e( 'Shown as the "From" name on every Learnomy email. Leave blank to use your site title.', 'learnomy' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="lrn-sender-email"><?php esc_html_e( 'Sender Email', 'learnomy' ); ?></label>
								</th>
								<td>
									<input type="email" id="lrn-sender-email" name="learnomy_settings[email_sender_email]"
										value="<?php echo esc_attr( $saved['email_sender_email'] ?? '' ); ?>"
										placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"
										class="regular-text">
									<p class="description"><?php esc_html_e( 'The "From" address on every Learnomy email. Leave blank to use your site admin email.', 'learnomy' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Test delivery', 'learnomy' ); ?></th>
								<td>
									<button type="button" class="lrn-admin-btn lrn-js-test-email">
										<i data-lucide="mail" aria-hidden="true"></i>
										<?php esc_html_e( 'Send test email', 'learnomy' ); ?>
									</button>
									<p class="description"><?php esc_html_e( 'Sends a sample email to your account address so you can confirm delivery and the sender identity above.', 'learnomy' ); ?></p>
								</td>
							</tr>
						</table>
					</div>

					<div class="lrn-settings-card">
						<div class="lrn-settings-card__head">
							<h2 class="lrn-settings-card__title"><?php esc_html_e( 'Email Notifications', 'learnomy' ); ?></h2>
							<p class="lrn-settings-card__desc"><?php esc_html_e( 'Choose which email notifications are sent to students and instructors.', 'learnomy' ); ?></p>
						</div>
						<table class="form-table">
							<?php
							// Single source: the same map drives the sanitiser + the copy override.
							$email_types    = \Learnomy\Services\Notification_Service::email_template_types();
							$lrn_email_tags = '{student_name}, {student_first_name}, {student_email}, {site_name}, {login_url}';
							foreach ( $email_types as $type_key => $type_label ) :
								$option_key      = 'email_notify_' . $type_key;
								$is_enabled      = ! isset( $saved[ $option_key ] ) || ! empty( $saved[ $option_key ] );
								$lrn_subj_key    = 'email_subj_' . $type_key;
								$lrn_body_key    = 'email_body_' . $type_key;
								$lrn_custom_subj = (string) ( $saved[ $lrn_subj_key ] ?? '' );
								$lrn_custom_body = (string) ( $saved[ $lrn_body_key ] ?? '' );
								$lrn_has_copy    = '' !== trim( $lrn_custom_subj ) || '' !== trim( $lrn_custom_body );
								?>
							<tr>
								<th scope="row"><?php echo esc_html( $type_label ); ?></th>
								<td>
									<?php
									// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- UX::toggle() returns escaped markup.
									echo \Learnomy\Admin\UX::toggle(
										array(
											'name'    => 'learnomy_settings[' . $option_key . ']',
											'checked' => $is_enabled,
											'label'   => __( 'Send email notification', 'learnomy' ),
										)
									);
									// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
									?>
									<details class="lrn-email-copy"<?php echo $lrn_has_copy ? ' open' : ''; ?>>
										<summary><?php echo $lrn_has_copy ? esc_html__( 'Custom copy', 'learnomy' ) : esc_html__( 'Customize copy', 'learnomy' ); ?></summary>
										<p class="description">
											<?php
											/* translators: %s: comma-separated list of merge tags. */
											printf( esc_html__( 'Leave blank to use the default. Merge tags: %s (plus event details like {course_title} where relevant).', 'learnomy' ), '<code>' . esc_html( $lrn_email_tags ) . '</code>' );
											?>
										</p>
										<p>
											<label for="lrn-emsub-<?php echo esc_attr( $type_key ); ?>"><?php esc_html_e( 'Subject', 'learnomy' ); ?></label><br>
											<input type="text" id="lrn-emsub-<?php echo esc_attr( $type_key ); ?>" class="large-text" name="learnomy_settings[<?php echo esc_attr( $lrn_subj_key ); ?>]" value="<?php echo esc_attr( $lrn_custom_subj ); ?>" placeholder="<?php esc_attr_e( 'Use the default subject', 'learnomy' ); ?>">
										</p>
										<p>
											<label for="lrn-embody-<?php echo esc_attr( $type_key ); ?>"><?php esc_html_e( 'Body', 'learnomy' ); ?></label><br>
											<textarea id="lrn-embody-<?php echo esc_attr( $type_key ); ?>" class="large-text" rows="4" name="learnomy_settings[<?php echo esc_attr( $lrn_body_key ); ?>]" placeholder="<?php esc_attr_e( 'Use the default body', 'learnomy' ); ?>"><?php echo esc_textarea( $lrn_custom_body ); ?></textarea>
										</p>
									</details>
								</td>
							</tr>
							<?php endforeach; ?>
						</table>
					</div>

					<?php
					/*
					 * Push notifications.
					 *
					 * The device-token endpoint has existed since 1.0 and nothing ever sent
					 * to the tokens it collected. This is the outlet. Firebase relays to both
					 * Android and iOS, so one credential covers both apps.
					 */
					$lrn_push_enabled = ! empty( $saved['push_enabled'] );
					$lrn_push_account = (string) ( $saved['push_fcm_service_account'] ?? '' );
					$lrn_push_ready   = \Learnomy\Services\Push_Service::is_configured();
					?>
					<div class="lrn-settings-card">
						<div class="lrn-settings-card__head">
							<h2 class="lrn-settings-card__title"><?php esc_html_e( 'Mobile push notifications', 'learnomy' ); ?></h2>
							<p class="lrn-settings-card__desc">
								<?php esc_html_e( 'Send notifications to the Learnomy mobile app. Only needed if you have an app: a site without one has no devices to send to, and this can stay off.', 'learnomy' ); ?>
							</p>
						</div>
						<table class="form-table">
							<tr>
								<th scope="row"><?php esc_html_e( 'Enable push', 'learnomy' ); ?></th>
								<td>
									<?php
									// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- UX::toggle() returns escaped markup.
									echo \Learnomy\Admin\UX::toggle(
										array(
											'name'    => 'learnomy_settings[push_enabled]',
											'checked' => $lrn_push_enabled,
											'label'   => __( 'Send notifications to registered mobile devices', 'learnomy' ),
										)
									);
									// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
									?>
									<p class="description">
										<?php esc_html_e( 'Members get the same notifications they already get in-app and by email, on their phone.', 'learnomy' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="lrn-push-account"><?php esc_html_e( 'Firebase service account', 'learnomy' ); ?></label>
								</th>
								<td>
									<textarea id="lrn-push-account" class="large-text code" rows="5"
										name="learnomy_settings[push_fcm_service_account]"
										placeholder='{"type": "service_account", "project_id": "...", "private_key": "...", "client_email": "..."}'><?php echo esc_textarea( $lrn_push_account ); ?></textarea>
									<p class="description">
										<?php esc_html_e( 'In the Firebase console: Project settings, Service accounts, Generate new private key. Paste the whole JSON file here. It works for both Android and iOS.', 'learnomy' ); ?>
									</p>
									<p class="description">
										<?php
										if ( $lrn_push_ready ) {
											echo '<strong>' . esc_html__( 'Push is configured and ready.', 'learnomy' ) . '</strong>';
										} elseif ( $lrn_push_enabled && '' !== $lrn_push_account ) {
											// Say exactly what is wrong. The commonest mistake is pasting the
											// web-app config instead of the service-account key: both are JSON
											// from the same console, and only one of them can sign anything.
											echo '<strong>' . esc_html__( 'This does not look like a service-account key. It needs project_id, client_email and private_key. The web app config from the Firebase console is a different file and will not work.', 'learnomy' ) . '</strong>';
										} else {
											echo esc_html__( 'Not configured. Nothing is sent to mobile devices.', 'learnomy' );
										}
										?>
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

			<!-- ═══════════════════════════════════════════════════════════
				REVIEWS
				═══════════════════════════════════════════════════════════ -->
			<div class="lrn-settings-section" id="section-reviews">
				<form method="post" action="options.php">
					<?php settings_fields( 'learnomy_settings' ); ?>

					<div class="lrn-settings-card">
						<div class="lrn-settings-card__head">
							<h2 class="lrn-settings-card__title"><?php esc_html_e( 'Student Reviews', 'learnomy' ); ?></h2>
							<p class="lrn-settings-card__desc"><?php esc_html_e( 'Control how course reviews are submitted and displayed.', 'learnomy' ); ?></p>
						</div>
						<table class="form-table">
							<tr>
								<th scope="row"><?php esc_html_e( 'Enable Reviews', 'learnomy' ); ?></th>
								<td>
									<?php
									// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- UX::toggle() returns escaped markup.
									echo \Learnomy\Admin\UX::toggle(
										array(
											'name'    => 'learnomy_settings[enable_reviews]',
											'checked' => ! empty( $saved['enable_reviews'] ),
											'label'   => __( 'Allow students to leave course reviews', 'learnomy' ),
										)
									);
									// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
									?>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Auto-Approve', 'learnomy' ); ?></th>
								<td>
									<?php
									// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- UX::toggle() returns escaped markup.
									echo \Learnomy\Admin\UX::toggle(
										array(
											'name'    => 'learnomy_settings[auto_approve_reviews]',
											'checked' => ! empty( $saved['auto_approve_reviews'] ),
											'label'   => __( 'Publish reviews immediately without manual approval', 'learnomy' ),
										)
									);
									// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
									?>
								</td>
							</tr>
						</table>
					</div>

					<div class="lrn-settings-section__footer">
						<?php submit_button( __( 'Save Changes', 'learnomy' ), 'primary', 'submit', false ); ?>
					</div>
				</form>
			</div>

			<!-- ═══════════════════════════════════════════════════════════
				COURSES (Content section)
				═══════════════════════════════════════════════════════════ -->
			<div class="lrn-settings-section" id="section-courses">
				<form method="post" action="options.php">
					<?php settings_fields( 'learnomy_settings' ); ?>

					<div class="lrn-settings-card">
						<div class="lrn-settings-card__head">
							<h2 class="lrn-settings-card__title"><?php esc_html_e( 'Comment Settings', 'learnomy' ); ?></h2>
							<p class="lrn-settings-card__desc"><?php esc_html_e( 'Control whether students can comment on lessons.', 'learnomy' ); ?></p>
						</div>
						<table class="form-table">
							<?php
							// Course-level comments (separate from lesson comments) ship in
							// v1.1 — the front-end list + post form is not built yet, so the
							// "Allow comments on courses" toggle is hidden to avoid a setting
							// that enables a non-functional section. The setting key remains
							// registered for forward-compat. Lesson comments below are fully
							// wired and unaffected.
							?>
							<tr>
								<th scope="row"><?php esc_html_e( 'Enable Lesson Comments', 'learnomy' ); ?></th>
								<td>
									<?php
									// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- UX::toggle() returns escaped markup.
									echo \Learnomy\Admin\UX::toggle(
										array(
											'name'    => 'learnomy_settings[enable_lesson_comments]',
											// Absent means ON, because that is what the
											// MODEL does: get_effective_comments_enabled()
											// only disables when the key EXISTS and is
											// empty. Reading it with ! empty() rendered the
											// toggle unchecked on any site that had never
											// saved this screen, so an owner was shown
											// "off" while comments worked perfectly well —
											// and toggling it on changed nothing, which
											// reads as a broken switch
											// (Basecamp 10199056673).
											'checked' => ! array_key_exists( 'enable_lesson_comments', (array) $saved )
												|| ! empty( $saved['enable_lesson_comments'] ),
											'label'   => __( 'Allow students to post comments on lessons', 'learnomy' ),
										)
									);
									// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
									?>
								</td>
							</tr>
						</table>
					</div>

					<div class="lrn-settings-section__footer">
						<?php submit_button( __( 'Save Changes', 'learnomy' ), 'primary', 'submit', false ); ?>
					</div>
				</form>
			</div>

			<!-- ═══════════════════════════════════════════════════════════
				CERTIFICATES
				═══════════════════════════════════════════════════════════ -->
			<div class="lrn-settings-section" id="section-certificates">
				<form method="post" action="options.php">
					<?php settings_fields( 'learnomy_settings' ); ?>

					<div class="lrn-settings-card">
						<div class="lrn-settings-card__head">
							<h2 class="lrn-settings-card__title"><?php esc_html_e( 'Certificate Defaults', 'learnomy' ); ?></h2>
							<p class="lrn-settings-card__desc"><?php esc_html_e( 'Defaults applied when issuing verifiable certificates on course completion.', 'learnomy' ); ?></p>
						</div>
						<table class="form-table">
							<tr>
								<th scope="row"><?php esc_html_e( 'Enable Certificates', 'learnomy' ); ?></th>
								<td>
									<?php
									// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- UX::toggle() returns escaped markup.
									echo \Learnomy\Admin\UX::toggle(
										array(
											'name'    => 'learnomy_settings[enable_certificates]',
											'checked' => ! empty( $saved['enable_certificates'] ),
											'label'   => __( 'Issue certificates when students complete a course', 'learnomy' ),
										)
									);
									// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
									?>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="lrn-cert-footer"><?php esc_html_e( 'Footer Text', 'learnomy' ); ?></label>
								</th>
								<td>
									<textarea id="lrn-cert-footer" name="learnomy_settings[certificate_footer]"
										class="large-text" rows="3"><?php echo esc_textarea( $saved['certificate_footer'] ?? '' ); ?></textarea>
									<p class="description"><?php esc_html_e( 'Displayed at the bottom of every certificate. Typically your organization name or legal text.', 'learnomy' ); ?></p>
								</td>
							</tr>
						</table>
					</div>

					<div class="lrn-settings-section__footer">
						<?php submit_button( __( 'Save Changes', 'learnomy' ), 'primary', 'submit', false ); ?>
					</div>
				</form>
			</div>

			<!-- ═══════════════════════════════════════════════════════════
				QUIZ SETTINGS (new section)
				═══════════════════════════════════════════════════════════ -->
			<div class="lrn-settings-section" id="section-quiz">
				<form method="post" action="options.php">
					<?php settings_fields( 'learnomy_settings' ); ?>

					<div class="lrn-settings-card">
						<div class="lrn-settings-card__head">
							<h2 class="lrn-settings-card__title"><?php esc_html_e( 'Quiz Defaults', 'learnomy' ); ?></h2>
							<p class="lrn-settings-card__desc"><?php esc_html_e( 'Default settings applied to new quizzes. Can be overridden per quiz.', 'learnomy' ); ?></p>
						</div>
						<table class="form-table">
							<tr>
								<th scope="row">
									<label for="lrn-quiz-pass-mark"><?php esc_html_e( 'Default Pass Mark (%)', 'learnomy' ); ?></label>
								</th>
								<td>
									<input type="number" id="lrn-quiz-pass-mark" name="learnomy_settings[quiz_pass_mark]"
										value="<?php echo esc_attr( $saved['quiz_pass_mark'] ?? 50 ); ?>"
										class="small-text" min="0" max="100" step="1">
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="lrn-quiz-max-attempts"><?php esc_html_e( 'Max Attempts', 'learnomy' ); ?></label>
								</th>
								<td>
									<input type="number" id="lrn-quiz-max-attempts" name="learnomy_settings[quiz_max_attempts]"
										value="<?php echo esc_attr( $saved['quiz_max_attempts'] ?? 0 ); ?>"
										class="small-text" min="0">
									<p class="description"><?php esc_html_e( '0 = unlimited attempts.', 'learnomy' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Randomize Questions', 'learnomy' ); ?></th>
								<td>
									<?php
									// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- UX::toggle() returns escaped markup.
									echo \Learnomy\Admin\UX::toggle(
										array(
											'name'    => 'learnomy_settings[quiz_randomize]',
											'checked' => ! empty( $saved['quiz_randomize'] ),
											'label'   => __( 'Shuffle question order on every attempt', 'learnomy' ),
										)
									);
									// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
									?>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Show Correct Answers', 'learnomy' ); ?></th>
								<td>
									<select name="learnomy_settings[quiz_show_answers]">
										<option value="after_submit" <?php selected( $saved['quiz_show_answers'] ?? 'after_submit', 'after_submit' ); ?>><?php esc_html_e( 'After submission', 'learnomy' ); ?></option>
										<option value="after_pass" <?php selected( $saved['quiz_show_answers'] ?? 'after_submit', 'after_pass' ); ?>><?php esc_html_e( 'Only after passing', 'learnomy' ); ?></option>
										<option value="never" <?php selected( $saved['quiz_show_answers'] ?? 'after_submit', 'never' ); ?>><?php esc_html_e( 'Never', 'learnomy' ); ?></option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Start Screen Layout', 'learnomy' ); ?></th>
								<td>
									<select name="learnomy_settings[quiz_start_style]">
										<option value="cover" <?php selected( $saved['quiz_start_style'] ?? 'cover', 'cover' ); ?>><?php esc_html_e( 'Cover - centered title, rules card, one button', 'learnomy' ); ?></option>
										<option value="brief" <?php selected( $saved['quiz_start_style'] ?? 'cover', 'brief' ); ?>><?php esc_html_e( 'Exam brief - context left, decision card right', 'learnomy' ); ?></option>
										<option value="boarding" <?php selected( $saved['quiz_start_style'] ?? 'cover', 'boarding' ); ?>><?php esc_html_e( 'Boarding pass - stat tiles and a checklist', 'learnomy' ); ?></option>
									</select>
									<p class="description"><?php esc_html_e( 'How the page a student sees before starting any quiz is laid out. Same information in all three; developers can override per quiz with the learnomy_quiz_start_style filter.', 'learnomy' ); ?></p>
								</td>
							</tr>
						</table>
					</div>

					<div class="lrn-settings-card">
						<div class="lrn-settings-card__head">
							<h2 class="lrn-settings-card__title"><?php esc_html_e( 'Anti-Cheat', 'learnomy' ); ?></h2>
							<p class="lrn-settings-card__desc"><?php esc_html_e( 'Measures to maintain quiz integrity.', 'learnomy' ); ?></p>
						</div>
						<table class="form-table">
							<tr>
								<th scope="row"><?php esc_html_e( 'Track Tab Switches', 'learnomy' ); ?></th>
								<td>
									<?php
									// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- UX::toggle() returns escaped markup.
									echo \Learnomy\Admin\UX::toggle(
										array(
											'name'    => 'learnomy_settings[quiz_track_tabs]',
											'checked' => ! empty( $saved['quiz_track_tabs'] ),
											'label'   => __( 'Record when students switch browser tabs during a quiz', 'learnomy' ),
										)
									);
									// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
									?>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="lrn-quiz-tab-switch-limit"><?php esc_html_e( 'Auto-submit after N tab switches', 'learnomy' ); ?></label>
								</th>
								<td>
									<input type="number" id="lrn-quiz-tab-switch-limit" name="learnomy_settings[quiz_tab_switch_limit]"
										value="<?php echo esc_attr( $saved['quiz_tab_switch_limit'] ?? 0 ); ?>"
										min="0" step="1" class="small-text">
									<p class="description"><?php esc_html_e( 'When tab tracking is on, a quiz auto-submits once the student switches away this many times. Set to 0 to never auto-submit.', 'learnomy' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="lrn-quiz-time-limit"><?php esc_html_e( 'Default Time Limit (min)', 'learnomy' ); ?></label>
								</th>
								<td>
									<input type="number" id="lrn-quiz-time-limit" name="learnomy_settings[quiz_time_limit]"
										value="<?php echo esc_attr( $saved['quiz_time_limit'] ?? 0 ); ?>"
										class="small-text" min="0">
									<p class="description"><?php esc_html_e( '0 = no time limit. Auto-submits when time runs out.', 'learnomy' ); ?></p>
								</td>
							</tr>
						</table>
					</div>

					<div class="lrn-settings-section__footer">
						<?php submit_button( __( 'Save Changes', 'learnomy' ), 'primary', 'submit', false ); ?>
					</div>
				</form>
			</div>

			<!-- ═══════════════════════════════════════════════════════════
				API & PERFORMANCE
				═══════════════════════════════════════════════════════════ -->
			<div class="lrn-settings-section" id="section-advanced">
				<form method="post" action="options.php">
					<?php settings_fields( 'learnomy_settings' ); ?>

					<div class="lrn-settings-card">
						<div class="lrn-settings-card__head">
							<h2 class="lrn-settings-card__title"><?php esc_html_e( 'API & Performance', 'learnomy' ); ?></h2>
							<p class="lrn-settings-card__desc"><?php esc_html_e( 'Rate limiting and caching for the REST API.', 'learnomy' ); ?></p>
						</div>
						<table class="form-table">
							<tr>
								<th scope="row">
									<label for="lrn-rate-limit"><?php esc_html_e( 'Sensitive endpoint limit (req/min per user)', 'learnomy' ); ?></label>
								</th>
								<td>
									<input type="number" id="lrn-rate-limit" name="learnomy_settings[api_rate_limit]"
										value="<?php echo esc_attr( $saved['api_rate_limit'] ?? 60 ); ?>"
										class="small-text" min="0" max="10000">
									<p class="description">
										<?php esc_html_e( 'Applies to write endpoints flagged sensitive: login, password reset, instructor application, withdrawal request, coupon validate, quiz start, comment post. Set 0 to disable. Max 10000.', 'learnomy' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="lrn-cache-ttl"><?php esc_html_e( 'Cache TTL (seconds)', 'learnomy' ); ?></label>
								</th>
								<td>
									<input type="number" id="lrn-cache-ttl" name="learnomy_settings[cache_ttl]"
										value="<?php echo esc_attr( $saved['cache_ttl'] ?? 3600 ); ?>"
										class="small-text" min="0" max="86400">
									<p class="description">
										<?php esc_html_e( 'Object-cache TTL for READ endpoints. Set 0 to disable caching. Max 86400 (24h).', 'learnomy' ); ?>
									</p>
								</td>
							</tr>
						</table>
					</div>

					<div class="lrn-settings-card">
						<div class="lrn-settings-card__head">
							<h2 class="lrn-settings-card__title"><?php esc_html_e( 'Progressive Web App', 'learnomy' ); ?></h2>
							<p class="lrn-settings-card__desc"><?php esc_html_e( 'Offline support and an installable app shell. Registers a site-wide service worker and shows an offline banner on Learnomy pages. Turn this off when Learnomy is one section of a larger site (such as a community) where a site-wide service worker is unwanted.', 'learnomy' ); ?></p>
						</div>
						<table class="form-table">
							<tr>
								<th scope="row"><?php esc_html_e( 'Enable PWA', 'learnomy' ); ?></th>
								<td>
									<?php
									// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- UX::toggle() returns escaped markup.
									echo \Learnomy\Admin\UX::toggle(
										array(
											'name'    => 'learnomy_settings[enable_pwa]',
											'checked' => ! isset( $saved['enable_pwa'] ) || ! empty( $saved['enable_pwa'] ),
											'label'   => __( 'Register the service worker and offline mode on Learnomy pages', 'learnomy' ),
										)
									);
									// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
									?>
								</td>
							</tr>
						</table>
					</div>

					<div class="lrn-settings-card">
						<div class="lrn-settings-card__head">
							<h2 class="lrn-settings-card__title"><?php esc_html_e( 'Tools', 'learnomy' ); ?></h2>
							<p class="lrn-settings-card__desc"><?php esc_html_e( 'Diagnostic and maintenance tools.', 'learnomy' ); ?></p>
						</div>
						<div class="lrn-settings-card__body-pad lrn-settings-card__body-pad--row">
							<button type="button" id="lrn-test-email" class="lrn-admin-btn lrn-js-test-email">
								<i data-lucide="mail" aria-hidden="true"></i>
								<?php esc_html_e( 'Send Test Email', 'learnomy' ); ?>
							</button>
							<button type="button" id="lrn-flush-rules" class="lrn-admin-btn">
								<i data-lucide="refresh-cw" aria-hidden="true"></i>
								<?php esc_html_e( 'Flush Rewrite Rules', 'learnomy' ); ?>
							</button>
						</div>
					</div>

					<?php
					// Renders only when the server is NOT refusing direct
					// requests to the private handout folder. The dashboard
					// notice is a one-liner pointing here; this is where the
					// message to send the host actually lives.
					\Learnomy\Services\Protected_Uploads::render_tools_panel();
					?>

					<div class="lrn-settings-section__footer">
						<?php submit_button( __( 'Save Changes', 'learnomy' ), 'primary', 'submit', false ); ?>
					</div>
				</form>
			</div>


			<!-- ═══════════════════════════════════════════════════════════
				DATA MANAGEMENT
				═══════════════════════════════════════════════════════════ -->
			<div class="lrn-settings-section" id="section-data">
				<form method="post" action="options.php">
					<?php settings_fields( 'learnomy_settings' ); ?>

					<div class="lrn-settings-card">
						<div class="lrn-settings-card__head">
							<h2 class="lrn-settings-card__title"><?php esc_html_e( 'Data Retention', 'learnomy' ); ?></h2>
							<p class="lrn-settings-card__desc"><?php esc_html_e( 'Data retention and cleanup settings.', 'learnomy' ); ?></p>
						</div>
						<table class="form-table">
							<tr>
								<th scope="row">
									<label for="lrn-data-retention"><?php esc_html_e( 'Audit Log Retention (days)', 'learnomy' ); ?></label>
								</th>
								<td>
									<input type="number" id="lrn-data-retention" name="learnomy_settings[data_retention_days]"
										value="<?php echo esc_attr( $saved['data_retention_days'] ?? 365 ); ?>"
										class="small-text" min="30">
									<p class="description"><?php esc_html_e( 'Entries older than this are removed automatically.', 'learnomy' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="lrn-email-log-retention"><?php esc_html_e( 'Email Log Retention (days)', 'learnomy' ); ?></label>
								</th>
								<td>
									<input type="number" id="lrn-email-log-retention" name="learnomy_settings[email_log_retention_days]"
										value="<?php echo esc_attr( $saved['email_log_retention_days'] ?? 0 ); ?>"
										class="small-text" min="0">
									<p class="description"><?php esc_html_e( 'Email Log entries older than this are removed automatically, once a week. Email records include names, addresses and message text, so keeping them forever is rarely what you want. Set 0 to keep everything.', 'learnomy' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'On Uninstall', 'learnomy' ); ?></th>
								<td>
									<?php
									// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- UX::toggle() returns escaped markup.
									echo \Learnomy\Admin\UX::toggle(
										array(
											'name'    => 'learnomy_settings[delete_data_on_uninstall]',
											'id'      => 'lrn-delete-data-on-uninstall',
											'checked' => ! empty( $saved['delete_data_on_uninstall'] ),
											'label'   => __( 'Remove all plugin data when the plugin is deleted', 'learnomy' ),
										)
									);
									// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
									?>
									<p class="description lrn-danger-note">
										<i data-lucide="alert-triangle" aria-hidden="true"></i>
										<?php esc_html_e( 'This permanently deletes every course, enrollment, certificate, and transaction when the plugin is removed. It cannot be undone. Leave off to keep your data if you reinstall later.', 'learnomy' ); ?>
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

			<!-- ═══════════════════════════════════════════════════════════
				LEARNING STANDARDS — the single hub for every learning-standard
				integration (xAPI/SCORM/LTI/H5P/QTI). The hub partial maps all
				five to their one config entry point; Pro modules register their
				live-settings card below the hub via `learnomy_integrations_cards`.
				DOM id stays `section-integrations` so bookmarks/anchors resolve.
				═══════════════════════════════════════════════════════════ -->
			<div class="lrn-settings-section" id="section-integrations">
				<div class="lrn-settings-card">
					<div class="lrn-settings-card__head">
						<h2 class="lrn-settings-card__title"><?php esc_html_e( 'Learning Standards', 'learnomy' ); ?></h2>
						<p class="lrn-settings-card__desc"><?php esc_html_e( 'Connect Learnomy to e-learning interoperability standards - xAPI, SCORM, LTI, H5P and QTI - and reach each one’s configuration from this single screen instead of hunting across five.', 'learnomy' ); ?></p>
					</div>
					<?php require LEARNOMY_DIR . 'includes/admin/views/partials/integrations-hub.php'; ?>
				</div>

				<?php
				/**
				 * Fires inside the Settings → Learning Standards section, below
				 * the hub. Pro standard modules (xAPI, SCORM, LTI, …) echo their
				 * own live-settings card here — each is responsible for escaping
				 * its output and rendering the canonical save-button footer. The
				 * hub above always maps every standard; these cards hold the
				 * settings for the ones that are enabled.
				 *
				 * @since 1.3.1
				 */
				ob_start();
				do_action( 'learnomy_integrations_cards' );
				$lrn_integrations_cards = trim( (string) ob_get_clean() );

				if ( '' !== $lrn_integrations_cards ) {
					echo $lrn_integrations_cards; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each module escapes its own card output (the hook contract).
				}
				?>
			</div>

			<?php
			// Pro and third-party plugins render additional sections here.
			do_action( 'learnomy_admin_settings_sections' );
			?>

		</div><!-- /.lrn-settings-content -->

	</div><!-- /.lrn-settings-wrap -->
</div>
