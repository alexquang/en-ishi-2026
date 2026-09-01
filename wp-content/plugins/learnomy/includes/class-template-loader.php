<?php
/**
 * Template loader — resolves, enqueues assets for, and renders frontend templates.
 *
 * Templates can be overridden by placing files in `{theme}/learnomy/` — the same
 * pattern WooCommerce uses, so theme authors already know where to look.
 *
 * @package Learnomy
 */

namespace Learnomy;

defined( 'ABSPATH' ) || exit;

class Template_Loader {

	/**
	 * Map of route names to view-data resolver classes.
	 *
	 * Each resolver has a static `resolve( array $data ): array` method that
	 * pre-fetches all model data the template needs. Templates receive the
	 * returned array as `$view_data` and must NOT call models directly.
	 *
	 * @var array<string, string>
	 */
	private static array $view_data_resolvers = array(
		'course'                     => 'Learnomy\\Services\\View_Data\\Single_Course_View_Data',
		'lesson'                     => 'Learnomy\\Services\\View_Data\\Single_Lesson_View_Data',
		'student-dashboard'          => 'Learnomy\\Services\\View_Data\\Student_Dashboard_View_Data',
		'instructor-dashboard'       => 'Learnomy\\Services\\View_Data\\Instructor_Dashboard_View_Data',
		'instructor-courses'         => 'Learnomy\\Services\\View_Data\\Instructor_Dashboard_View_Data',
		'instructor-students'        => 'Learnomy\\Services\\View_Data\\Instructor_Students_View_Data',
		'instructor-earnings'        => 'Learnomy\\Services\\View_Data\\Instructor_Dashboard_View_Data',
		'instructor-grading'         => 'Learnomy\\Services\\View_Data\\Instructor_Grading_View_Data',
		'enroll'                     => 'Learnomy\\Services\\View_Data\\Checkout_View_Data',
		'enroll-thank-you'           => 'Learnomy\\Services\\View_Data\\Checkout_Thank_You_View_Data',
		'enroll-cancelled'           => 'Learnomy\\Services\\View_Data\\Checkout_Cancelled_View_Data',
		'membership-pricing'         => 'Learnomy\\Services\\View_Data\\Membership_Pricing_View_Data',
		'membership-manage'          => 'Learnomy\\Services\\View_Data\\Membership_Manage_View_Data',
		'become-instructor'          => 'Learnomy\\Services\\View_Data\\Become_Instructor_View_Data',
		'category'                   => 'Learnomy\\Services\\View_Data\\Category_Archive_View_Data',
		'certificate-verify'         => 'Learnomy\\Services\\View_Data\\Certificate_Verify_View_Data',
		'quiz'                       => 'Learnomy\\Services\\View_Data\\Single_Quiz_View_Data',
		'standalone-quiz'            => 'Learnomy\\Services\\View_Data\\Single_Quiz_View_Data',
		'instructor-profile'         => 'Learnomy\\Services\\View_Data\\Instructor_Profile_View_Data',
		'catalog'                    => 'Learnomy\\Services\\View_Data\\Catalog_View_Data',
		'account-billing'            => 'Learnomy\\Services\\View_Data\\Account_Billing_View_Data',
		'account-details'            => 'Learnomy\\Services\\View_Data\\Account_Details_View_Data',
		'account-instructor-profile' => 'Learnomy\\Services\\View_Data\\Account_Instructor_Profile_View_Data',
		'account-certificates'       => 'Learnomy\\Services\\View_Data\\Account_Certificates_View_Data',
		'account-courses'            => 'Learnomy\\Services\\View_Data\\Account_Courses_View_Data',
		'account-quiz-results'       => 'Learnomy\\Services\\View_Data\\Account_Quiz_Results_View_Data',
	);

	/**
	 * Routes that need a capability, not merely a login.
	 *
	 * `$protected_routes` below only ever asked "is anyone logged in?", so every
	 * instructor route answered for a student: the full instructor rail
	 * (Dashboard, Reports, My Courses, Students, Cohorts, Grading, Earnings)
	 * rendered, along with the Announcements composer and its Send button
	 * (Basecamp 10190724992). Nothing leaked - the list queries scope to the
	 * viewer's own courses and returned empty states, and the REST write is
	 * gated, so Send answered 403 - but a student was shown a instructor console
	 * and a control that fails when pressed.
	 *
	 * The engine had the right answer all along; the route never asked it. Map
	 * the route to its ability here rather than adding a check to each template,
	 * so a new instructor route inherits the gate by being listed.
	 *
	 * @var array<string, string> Route name => ability.
	 */
	private static array $capability_routes = array(
		// `instructor-dashboard` is deliberately NOT here - it renders its own
		// gate and listing it 404'd that gate out of existence (Basecamp
		// 10203282816). The five SECTION routes below are here for the access
		// check only; they no longer 404 a refused viewer, because
		// access_gate_routes() gives each of them the same explanation the root
		// gives. That claim - "the sections have no answer for a visitor who is
		// not an instructor" - used to live in this comment and was wrong, and
		// it was expensive: a 404 made a menu item pointing at a section a dead
		// end, so the plugin began deleting items from the site owner's menu to
		// hide them, and that machinery eventually deleted the wrong one.
		//
		// The gate is what a refused viewer sees; this map is still what stops
		// them seeing the console. Removing an entry here removes the access
		// check, not the 404.
		'instructor-reports'  => 'view_instructor_area',
		'instructor-courses'  => 'view_instructor_area',
		'instructor-students' => 'view_instructor_area',
		'instructor-grading'  => 'view_instructor_area',
		'instructor-earnings' => 'view_instructor_area',
	);

	/**
	 * Routes that require an authenticated user.
	 *
	 * @var array<string>
	 */
	private static array $protected_routes = array(
		'student-dashboard',
		'instructor-dashboard',
		'instructor-courses',
		'instructor-students',
		'instructor-earnings',
		'instructor-grading',
		'membership-manage',
		'become-instructor',
		'enroll',
		'enroll-thank-you',
		'account',
		'account-billing',
		'account-billing-receipt',
		'account-details',
		'account-instructor-profile',
		'account-certificates',
		'account-courses',
		'account-quiz-results',
		'my-spaces',
	);

	/**
	 * Map of route names to template file names.
	 *
	 * Filterable via `learnomy_template_map` so third-party code can add routes
	 * or swap templates without hacking core files.
	 *
	 * @var array<string, string>
	 */
	private static array $default_template_map = array(
		'catalog'                    => 'catalog/grid.php',
		'category'                   => 'category-archive.php',
		'course'                     => 'course/classic.php',
		'lesson'                     => 'lesson/focus.php',
		'quiz'                       => 'single-quiz.php',
		'standalone-quiz'            => 'single-quiz.php',
		'student-dashboard'          => 'student-dashboard.php',
		'instructor-dashboard'       => 'instructor-dashboard.php',
		'instructor-courses'         => 'instructor-section.php',
		'instructor-students'        => 'instructor-students.php',
		'instructor-earnings'        => 'instructor-section.php',
		'instructor-grading'         => 'instructor-grading.php',
		'certificate-verify'         => 'certificate-verify.php',
		'membership-pricing'         => 'membership-pricing.php',
		'membership-manage'          => 'membership-manage.php',
		'become-instructor'          => 'become-instructor.php',
		'instructor-profile'         => 'instructor-profile.php',
		'enroll'                     => 'checkout.php',
		'enroll-thank-you'           => 'checkout-thank-you.php',
		'enroll-cancelled'           => 'checkout-cancelled.php',
		'account-billing'            => 'account-billing.php',
		'account-details'            => 'account-details.php',
		'account-instructor-profile' => 'account-instructor-profile.php',
		'account-certificates'       => 'account-certificates.php',
		'account-courses'            => 'account-courses.php',
		'account-quiz-results'       => 'account-quiz-results.php',
		'register-student'           => 'register-student.php',
		'register-instructor'        => 'register-instructor.php',
		'offline'                    => 'offline.php',
		// Spaces dashboard — Free ships a stub that prompts to enable the
		// Pro Spaces extension. Pro overrides this entry via the
		// `learnomy_template_map` filter when active.
		'my-spaces'                  => 'my-spaces-stub.php',
	);

	/**
	 * Routes governed by the layout system, mapped to their registry surface.
	 * When a route is here AND the surface's active variant declares a template
	 * file, that variant file renders instead of the $default_template_map entry.
	 * Surfaces are migrated incrementally; see
	 * plan/2026-05-22-layout-system/PLAN.yaml.
	 *
	 * @var array<string, string>
	 */
	/**
	 * True once render() has opened the page shell (theme header or the
	 * distraction-free <head>), i.e. once output has started.
	 *
	 * Read by render_not_found() so a late in-template call renders only the
	 * 404 body instead of a second header/footer pair.
	 *
	 * @var bool
	 */
	private static bool $chrome_open = false;

	private static array $route_surface = array(
		'catalog' => 'archive',
		'course'  => 'single-course',
		'lesson'  => 'lesson',
	);

	/**
	 * Render a Learnomy frontend page.
	 *
	 * Handles auth redirects, asset enqueueing, dark-mode body class, SEO title,
	 * and finally renders the resolved template wrapped in the active theme's
	 * header/footer.
	 *
	 * @param array{route: string, slug: string, lesson_slug: string, quiz_slug: string} $data Route data from the router.
	 */
	public static function render( array $data ): void {
		$route = $data['route'];

		// Hybrid app: content screens render inside a native WebView. App mode
		// forces the chromeless minimal shell on EVERY route + adds the app
		// body class + safe-area stylesheet. Web visitors are unaffected.
		$is_app = \Learnomy\is_app_request();

		$protected_routes = apply_filters( 'learnomy_protected_routes', self::$protected_routes );

		// Redirect unauthenticated users away from protected pages.
		if ( in_array( $route, $protected_routes, true ) && ! is_user_logged_in() ) {
			// /become-instructor/ is a marketing CTA: anonymous visitors are
			// prospective instructors, so send them to the public apply +
			// register form (register-instructor) instead of a bare wp-login
			// screen that loses the signup context. Logged-in handling is
			// unchanged (Router bounces existing instructors to their dashboard).
			if ( 'become-instructor' === $route ) {
				wp_safe_redirect( \Learnomy\route_url( 'register-instructor' ) );
				exit;
			}
			wp_safe_redirect( wp_login_url( home_url( add_query_arg( array(), false ) ) ) );
			exit;
		}

		/**
		 * Capability gate. Being logged in is not the same as being allowed:
		 * the check above passes any authenticated user, which is how a student
		 * came to see the whole instructor console (Basecamp 10190724992).
		 *
		 * 404 rather than redirect-to-login, matching `learnomy_route_enabled`
		 * below: the visitor IS signed in, so bouncing them to a login form
		 * would loop, and telling them the page exists but is not theirs is
		 * information they have no use for.
		 *
		 * @param array<string, string> $capability_routes Route => ability map.
		 */
		$capability_routes = apply_filters( 'learnomy_route_capabilities', self::$capability_routes );

		if ( isset( $capability_routes[ $route ] ) ) {
			$lrn_ability = $capability_routes[ $route ];
			if ( ! Permissions\Permission_Engine::can( get_current_user_id(), $lrn_ability ) ) {
				/*
				 * A route that can explain itself does, instead of 404ing.
				 *
				 * The instructor SECTIONS used to 404 here on the reasoning
				 * that they "have no answer for a visitor who is not an
				 * instructor". They have the same answer the root has, and
				 * withholding it was load-bearing in a way nobody intended: a
				 * 404 made any menu item pointing at a section a dead end,
				 * which is why the plugin started deleting items out of the
				 * SITE OWNER'S menu, which is why it compared menu URLs
				 * against route_url(), which is how an unregistered route's
				 * home_url() fallback matched the Home item and removed it for
				 * every non-instructor on a Free-only site.
				 *
				 * The navigation belongs to the site, not to us. A page that
				 * says "instructor access required, here is how to apply" costs
				 * one query and lets us keep our hands off it.
				 *
				 * Access is unchanged: the gate returns before any console
				 * markup, exactly as the 404 did.
				 */
				if ( self::render_access_gate( $route ) ) {
					return;
				}

				self::render_not_found();
				return;
			}
		}

		/**
		 * Per-route enable gate. A route whose feature/module is turned off
		 * 404s here instead of rendering a surface that's hidden from the nav —
		 * so direct-URL access stays consistent with what's linked. Free gates
		 * its module-bound sections (e.g. account-certificates → certificates
		 * module); devs disable/hide any section by returning false.
		 *
		 * @param bool   $enabled Whether the route renders.
		 * @param string $route   Route name.
		 */
		if ( ! (bool) apply_filters( 'learnomy_route_enabled', true, $route ) ) {
			self::render_not_found();
			return;
		}

		// A logged-in Learnomy page is a per-user app surface — it shows the
		// viewer's own progress, enrolled/wishlist state, dashboard, account. Never
		// let a page cache / proxy serve one stale: force revalidation with
		// `private, no-cache` so the logged-in viewer always gets fresh content
		// (Zoho #40979 — the server page was fresh, a cache in front served it
		// hour-stale). Anonymous catalog/course pages send no header here and stay
		// cacheable. Filterable per-route for a site that knows a given logged-in
		// route is safe to cache.
		if ( is_user_logged_in() && ! headers_sent()
			&& (bool) apply_filters( 'learnomy_route_no_cache_for_user', true, $route ) ) {
			header( 'Cache-Control: private, no-cache, must-revalidate' );
		}

		// Resolve the template file, checking theme overrides first.
		$template_path = self::resolve_template( $route );

		if ( ! $template_path ) {
			// Route exists but template file is missing — 404.
			self::render_not_found();
			return;
		}

		// Enqueue plugin CSS + JS now (we are past the normal enqueue hooks, but
		// wp_enqueue_* still works here — WordPress will output them at wp_head /
		// wp_footer as long as we call before get_header()). Same entry point the
		// shortcodes, widgets and blocks use, so an embedded copy of this surface
		// asks for the identical bundle.
		self::enqueue_for_surface( $route );

		// Dark mode belongs to the THEME, not to us. Learnomy's palette is
		// token-driven and inherits the active theme (BuddyNext tokens ->
		// theme.json -> fallback), so it already renders in whatever scheme the
		// theme is in. Stamping our own `lrn-dark` on top of that is how a dark
		// Learnomy panel ends up sitting on a light theme page — the exact
		// mismatch Theme_Bridge exists to prevent on Reign and BuddyX.
		//
		// Following the OPERATING SYSTEM is not the same as following the theme,
		// and reproduces that mismatch on any theme that is light-only.
		//
		// So the standalone switch is HIDDEN by default rather than deleted: the
		// dark palette, the `learnomy_dark_mode` user meta and the toggle markup
		// all still ship, and a site owner running a theme with no dark mode of
		// its own can turn them back on with `learnomy_standalone_dark_mode`.
		if ( \Learnomy\standalone_dark_mode() ) {
			$lrn_user_id = get_current_user_id();

			if ( $lrn_user_id && get_user_meta( $lrn_user_id, 'learnomy_dark_mode', true ) ) {
				add_filter(
					'body_class',
					static function ( array $classes ): array {
						$classes[] = 'lrn-dark';
						return $classes;
					}
				);
			}
		}

		// `.lrn-app` carries the plugin's base reset — font-family/size,
		// line-height, link + img rules, box-sizing on every descendant
		// (assets/css/learnomy.css). It MUST wrap only the plugin's own content,
		// never the whole document: stamping it on <body> made the theme header
		// and footer (children of <body>) inherit the plugin's base font-size and
		// pick up `.lrn-app a` / `.lrn-app img`, visibly changing theme typography
		// on every in-theme route (e.g. /courses/). So `.lrn-app` goes on <body>
		// ONLY for chromeless routes (no theme chrome to touch — added to the
		// distraction-free <body> below) and is rendered as a wrapper <div> around
		// the template on standard routes (the get_header() branch below).

		// App-WebView mode: body hook + safe-area stylesheet so content screens
		// render correctly chromeless inside the native shell.
		if ( $is_app ) {
			add_filter(
				'body_class',
				static function ( array $classes ): array {
					$classes[] = 'lrn-app-mode';
					return $classes;
				}
			);
			// The native shell owns global navigation — the WP admin bar is
			// web-only chrome that has no place in the app WebView.
			add_filter( 'show_admin_bar', '__return_false' );
			wp_enqueue_style(
				'learnomy-app-mode',
				LEARNOMY_URL . 'assets/css/app-mode.css',
				array( 'learnomy' ),
				LEARNOMY_VERSION
			);
		}

		// Output container width CSS variable from settings. We always emit
		// it (even for the 960px default) so the value the admin picked is
		// authoritative against theme overrides — and so switching to
		// "Full Width" actually produces `none` instead of relying on the
		// hard-coded fallback in learnomy.css.
		$settings        = get_option( 'learnomy_settings', array() );
		$container_width = $settings['container_width'] ?? '960px';
		$safe_width      = sanitize_text_field( $container_width );
		if ( 'none' === $safe_width || 'full' === $safe_width ) {
			$safe_width = '100%';
		}
		add_action(
			'wp_head',
			static function () use ( $safe_width ): void {
				echo '<style>:root,.lrn-app{--lrn-container-width:' . esc_attr( $safe_width ) . ';}</style>' . "\n";
			},
			5
		);

		// NOTE: the page title is set AFTER the not_found 404 check below, not
		// here. Setting it here stamped a "Course - Learnomy" title onto the
		// theme's 404 body when a slug resolved to no entity (title and content
		// disagreed). On a real 404 the theme owns the title.

		// Distraction-free routes — no theme header/footer, just LMS chrome.
		// Chrome is derived from the layout system for every layout-governed
		// route; defaults reproduce today exactly: lesson = focus (df), course
		// quiz = embedded (in-theme), standalone quiz = immersive (df).
		//
		// Scope boundary (2026-05-23): we DO NOT force any other route to
		// bypass the theme. Removing `get_header()` / `get_footer()` for
		// transactional routes (checkout / register) was an earlier mistake
		// — the plugin owns the content area inside the theme, not the
		// theme chrome itself. If a site owner wants a stripped checkout,
		// that's a theme-level decision (page template, theme.json), not
		// a plugin decision. The site owner can still opt any route into
		// distraction-free via the `learnomy_distraction_free_route` filter.
		$chrome_surface   = array(
			'lesson'          => 'lesson',
			'quiz'            => 'quiz',
			'standalone-quiz' => 'standalone-quiz',
		);
		$distraction_free = isset( $chrome_surface[ $route ] )
			? \Learnomy\layout_is_distraction_free( $chrome_surface[ $route ] )
			: false;
		// The native app owns its own top/bottom chrome — every content screen
		// it loads must drop the theme header/footer.
		if ( $is_app ) {
			$distraction_free = true;
		}
		$distraction_free = apply_filters( 'learnomy_distraction_free_route', $distraction_free, $route );

		// Resolve view data for this route (pre-fetches all model data).
		$view_data = self::resolve_view_data( $route, $data );

		// ---------------------------------------------------------------------
		// THE not-found seam. Every route's "this URL has no entity the viewer
		// may see" verdict is answered HERE, before a single byte of output.
		//
		// It has to be here and nowhere else. A template body runs after
		// get_header() (in-theme routes) or after the distraction-free shell has
		// already printed <!DOCTYPE> + wp_head() — by then the 200 status is on
		// the wire, status_header( 404 ) is a silent no-op, and the theme has
		// already emitted a <title> carrying the draft/private entity's name.
		// That soft 404 + title leak is the whole bug class (Basecamp 10132809039).
		//
		// Two ways for a route to declare it, both pre-output. Preferred: its
		// view-data resolver returns `not_found => true`, because the resolver is
		// where the entity lookup already happens. Otherwise: a route with no
		// resolver of its own (a Pro extension view that resolves inline)
		// answers the filter below.
		//
		// @param bool   $not_found Whether the request is a 404.
		// @param string $route     Route name.
		// @param array  $data      Route data (slug, lesson_slug, quiz_slug…).
		// @param array  $view_data Resolved view data for the route.
		if ( (bool) apply_filters( 'learnomy_route_not_found', ! empty( $view_data['not_found'] ), $route, $data, $view_data ) ) {
			self::render_not_found();
			return;
		}

		// Same timing rule for any other non-200 status a route needs (403 on a
		// space the viewer may not manage, 410 on a retired URL). The route
		// renders its own body; only the status has to be decided before the
		// header flushes. Resolvers set `http_status`; routes without a resolver
		// use the filter.
		//
		// @param int    $status    HTTP status for this render.
		// @param string $route     Route name.
		// @param array  $data      Route data.
		// @param array  $view_data Resolved view data for the route.
		$lrn_status = (int) apply_filters( 'learnomy_route_http_status', (int) ( $view_data['http_status'] ?? 200 ), $route, $data, $view_data );
		if ( 200 !== $lrn_status && ! headers_sent() ) {
			status_header( $lrn_status );
			nocache_headers();
		}

		// SEO: set the page title now that we know the entity exists (skipped on
		// the 404 path above so the theme owns the not-found title — a 404 that
		// still prints the draft entity's name in <head> is not a 404).
		self::set_page_title( $route, $data );

		// Past this point the page shell is open and the 200 status is on the
		// wire — every not-found verdict had to be reached above.
		self::$chrome_open = true;

		if ( $distraction_free ) {
			// Minimal shell — wp_head for scripts/styles, template, wp_footer for JS.
			?>
			<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1<?php echo $is_app ? ', viewport-fit=cover' : ''; ?>">
			<?php wp_head(); ?>
</head>
<body <?php body_class( 'lrn-distraction-free lrn-app' ); ?>>
			<?php wp_body_open(); ?>
			<?php
			extract( $data, EXTR_SKIP );      // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
			if ( ! empty( $view_data ) ) {
				extract( $view_data, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
			}
			include $template_path;            // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
			?>
			<?php
			// Subtle "Powered by Learnomy" on the distraction-free player (the
			// surface that bypasses the theme footer). Pro White Label hides it
			// via the learnomy_show_branding filter.
			echo \Learnomy\powered_by_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the helper.
			?>
			<?php wp_footer(); ?>
</body>
</html>
			<?php
		} else {
			// Standard: theme header, template, footer. The template is wrapped
			// in `.lrn-app` so the plugin's base reset scopes to its own content
			// and never bleeds into the theme header/footer (see the scoping note
			// where the body class used to be added).
			get_header();

			extract( $data, EXTR_SKIP );      // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
			if ( ! empty( $view_data ) ) {
				extract( $view_data, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
			}
			echo '<div class="lrn-app lrn-app--in-theme">';
			include $template_path;            // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
			echo '</div>';

			get_footer();
		}
	}

	/**
	 * Routes that answer a refused viewer with an explanation, not a 404.
	 *
	 * Route name => partial under `templates/`. Filterable, so a Pro extension
	 * adding an instructor section inherits the gate by listing itself rather
	 * than by 404ing and hoping something hides its menu item.
	 *
	 * @return array<string,string>
	 */
	private static function access_gate_routes(): array {
		/**
		 * Filter the routes that render an access gate instead of a 404.
		 *
		 * @param array<string,string> $routes Route name => partial path.
		 */
		return (array) apply_filters(
			'learnomy_route_access_gates',
			array(
				'instructor-courses'  => 'partials/instructor-access-gate.php',
				'instructor-students' => 'partials/instructor-access-gate.php',
				'instructor-earnings' => 'partials/instructor-access-gate.php',
				'instructor-grading'  => 'partials/instructor-access-gate.php',
				'instructor-reports'  => 'partials/instructor-access-gate.php',
			)
		);
	}

	/**
	 * Render a route's access gate, if it has one.
	 *
	 * Returns false when the route has nothing to say, so the caller falls
	 * through to its 404 - the gate is an upgrade for routes that opt in, never
	 * a weakening of the ones that do not.
	 *
	 * The gate is a 200. The visitor is signed in and the page exists; what
	 * they are being told is that this surface needs an instructor account and
	 * how to get one. A 404 would be a lie, and it was the lie that pushed us
	 * into editing the site owner's menu to hide the evidence.
	 *
	 * @param string $route Route name.
	 * @return bool Whether a gate was rendered.
	 */
	private static function render_access_gate( string $route ): bool {
		$gates = self::access_gate_routes();
		if ( ! isset( $gates[ $route ] ) ) {
			return false;
		}

		// The one fact the gate needs beyond "not an instructor": whether they
		// already applied. Prompting an applicant to apply again reads as the
		// form having failed (Basecamp #9919574281).
		$lrn_user_id             = get_current_user_id();
		$lrn_latest              = $lrn_user_id ? Models\InstructorApplication::find_latest_for_user( $lrn_user_id ) : null;
		$has_pending_application = $lrn_latest && 'pending' === ( $lrn_latest->status ?? '' );

		// Theme override honoured through the same locate() every other template
		// goes through, so a site can restyle the gate without forking.
		$lrn_path = self::locate( (string) $gates[ $route ] );
		if ( null === $lrn_path ) {
			return false;
		}

		if ( ! headers_sent() ) {
			nocache_headers();
		}

		get_header();
		include $lrn_path; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
		get_footer();

		return true;
	}

	/**
	 * Render this request as a 404 — the ONE implementation, Free and Pro.
	 *
	 * Sends the status (when headers still can be sent), flags the query so
	 * `is_404()` and every SEO/analytics consumer agree with the status line,
	 * and renders the theme's 404 body inside the site chrome.
	 *
	 * Call it from `learnomy_route_not_found` / a resolver's `not_found` flag,
	 * which run before any output. It is public so a template that discovers a
	 * not-found state late still has ONE call to make instead of re-deriving
	 * the `set_404()` + `status_header()` + `nocache_headers()` +
	 * `get_template_part()` incantation — repeating that in ten templates is
	 * how the late-gate bug spread in the first place. Late calls still cannot
	 * fix the status (headers are gone); they only render the right body, so
	 * treat a late call as a bug to move into the seam above.
	 */
	public static function render_not_found(): void {
		global $wp_query;

		if ( $wp_query instanceof \WP_Query ) {
			$wp_query->set_404();
		}

		if ( ! headers_sent() ) {
			status_header( 404 );
			nocache_headers();
		}

		// Called from the seam (nothing rendered yet) we own the whole page and
		// wrap the 404 body in the site chrome. Called late from inside a
		// template, the chrome is already open around us — emitting a second
		// header/footer here would duplicate them, so render the body only.
		if ( self::$chrome_open ) {
			get_template_part( '404' );
			return;
		}

		get_header();
		echo '<div class="lrn-app lrn-app--in-theme">';
		get_template_part( '404' );
		echo '</div>';
		get_footer();
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Resolve a template file path, honouring theme overrides.
	 *
	 * @param string $route Learnomy route name.
	 * @return string|null Absolute path to the template file, or null if not found.
	 */
	/**
	 * Pre-fetch all model data for a route via its registered resolver.
	 *
	 * @param string $route Route name.
	 * @param array  $data  Route data (slug, course, lesson, etc.).
	 * @return array Resolved view data, or empty array if no resolver registered.
	 */
	private static function resolve_view_data( string $route, array $data ): array {
		$resolvers = apply_filters( 'learnomy_view_data_resolvers', self::$view_data_resolvers );

		if ( ! isset( $resolvers[ $route ] ) ) {
			return array();
		}

		$class = $resolvers[ $route ];

		if ( ! class_exists( $class ) || ! method_exists( $class, 'resolve' ) ) {
			return array();
		}

		return (array) $class::resolve( $data );
	}

	private static function resolve_template( string $route ): ?string {
		$template_map = apply_filters( 'learnomy_template_map', self::$default_template_map );

		if ( ! isset( $template_map[ $route ] ) ) {
			return null;
		}

		$template_file = (string) $template_map[ $route ];

		// Layout system: if this route maps to a surface whose active variant
		// declares a template file (e.g. catalog/grid.php), render that variant
		// instead of the default. Surfaces migrate incrementally.
		$surface = self::$route_surface[ $route ] ?? '';
		if ( '' !== $surface ) {
			$variant_file = \Learnomy\layout_template( $surface );
			if ( null !== $variant_file ) {
				// Degrade to the surface default if the chosen variant's file
				// isn't built yet (variants ship incrementally; Pro may register
				// a variant without shipping its file), so we never 404. Existence
				// is asked through the same locate() that resolves it, so there is
				// no second copy of the theme-then-plugin lookup to drift.
				if ( null === self::locate( $variant_file ) ) {
					$variant_file = \Learnomy\layout_default_template( $surface );
				}
				if ( null !== $variant_file ) {
					$template_file = $variant_file;
				}
			}
		}

		// Out-of-tree extensions (Pro modules, third-party add-ons) register
		// templates that live outside `LEARNOMY_DIR/templates/`. The filter
		// contract accepts either:
		// • a basename like `single-course.php` (resolved against the
		// theme override path then `LEARNOMY_DIR/templates/`), or
		// • an absolute path to a template file. We detect absolute by
		// prefix-matching common Unix/Windows roots so the resolver
		// doesn't accidentally double-prefix and 404.
		$is_absolute = (
			'' !== $template_file
			&& (
				'/' === $template_file[0]                                                    // POSIX absolute (/path/...)
				|| ( strlen( $template_file ) > 2 && ':' === $template_file[1] )             // Windows drive (C:\...)
				|| 0 === strpos( $template_file, '\\\\' )                                    // UNC (\\server\...)
			)
		);

		if ( $is_absolute ) {
			// Out-of-tree extension template given as an absolute path. Same one
			// resolver: the theme override is {theme}/learnomy/{basename} (a theme
			// can drop in `/learnomy/single-cohort.php` to override it), and the
			// plugin default is the file itself — expressed through locate() by
			// pointing its base at the file's own directory.
			$base = trailingslashit( dirname( $template_file ) );
			return self::locate( basename( $template_file ), $base );
		}

		return self::locate( $template_file );
	}

	/**
	 * The override lookup itself: active theme first, plugin default second.
	 * This is THE one place that decides where a template comes from — the
	 * route resolver above and every partial include go through it, so the
	 * "drop a file at {theme}/learnomy/{path} to override it" promise holds
	 * for one file exactly as it does for another.
	 *
	 * Returns null when neither exists, so a caller can decide whether a
	 * missing file is fatal (a route) or skippable (a partial).
	 *
	 * ONE override root for the whole product: `{theme}/learnomy/{file}`, Free
	 * AND Pro. A site owner learns a single rule — copy the plugin file to
	 * {theme}/learnomy/ preserving its path — and it holds everywhere, exactly
	 * the WooCommerce model. Pro's builder templates live in the Pro plugin dir,
	 * not Free's templates/, so a caller can point the PLUGIN-DEFAULT lookup at a
	 * different base while the THEME root stays the same; the relative path then
	 * carries the namespace (e.g. `frontend-builder/partials/quiz-editor.php`),
	 * so the override path still mirrors where the file lives. Free never has to
	 * know that base exists — the caller supplies it.
	 *
	 * @param string      $template_file Path relative to the theme's learnomy/ dir,
	 *                                   e.g. 'course/partials/enroll-card.php'.
	 * @param string|null $plugin_base   Absolute dir the plugin default lives under,
	 *                                   with trailing slash; defaults to Free's
	 *                                   templates/. Pass a Pro extension's templates
	 *                                   dir to resolve its files under the same
	 *                                   theme root.
	 * @param string      $plugin_rel    Path of the file UNDER $plugin_base, when it
	 *                                   differs from $template_file because the theme
	 *                                   path carries a namespace segment the plugin
	 *                                   dir does not. Defaults to $template_file.
	 */
	public static function locate( string $template_file, ?string $plugin_base = null, string $plugin_rel = '' ): ?string {
		// 1. Active theme override — the ONE root, Free and Pro alike.
		$theme_override = get_stylesheet_directory() . '/learnomy/' . $template_file;
		if ( file_exists( $theme_override ) ) {
			return $theme_override;
		}

		// 2. Plugin default: Free's templates/, unless the caller points elsewhere
		// (a Pro extension supplies its own dir + the path within it).
		$base    = $plugin_base ?? LEARNOMY_DIR . 'templates/';
		$default = $base . ( '' !== $plugin_rel ? $plugin_rel : $template_file );
		if ( file_exists( $default ) ) {
			return $default;
		}

		return null;
	}

	/**
	 * The asset bundles an EMBEDDED surface can ask for.
	 *
	 * A shortcode, widget or block renders Learnomy markup on a page the router
	 * never sees, so it cannot inherit a route's assets. It asks for them here
	 * instead — either by ROUTE name (the full bundle {@see enqueue_assets()}
	 * builds, so an embedded copy of a route surface can never drift from it),
	 * or by one of the light SURFACE keys below.
	 *
	 * The light keys exist because a route bundle is sized for a whole page. A
	 * sidebar "Popular Courses" widget draws five links; dragging in KaTeX, Plyr
	 * and the quiz player to do it would trade one bug for a slower site. So the
	 * tiers are declared HERE, next to the route path, and are assembled from the
	 * exact same primitives the route path runs — never from a second asset list.
	 *
	 * - `widget`      Sidebar-sized. Base stylesheet + the embedded-component
	 *                 sheet. NO JavaScript: no shipped widget emits an icon
	 *                 placeholder or binds a store.
	 * - `embed`       Shortcode-sized. Adds the shared UI layer (toast / notice /
	 *                 confirm-modal CSS + JS) and the Lucide icon replacer, which
	 *                 shortcode card markup does use.
	 * - `course-card` `embed` plus the wishlist store, for any surface that
	 *                 renders templates/partials/course-card.php.
	 *
	 * @var array<string, array<string, bool>>
	 */
	private const SURFACE_BUNDLES = array(
		'widget'      => array(
			'shared_ui' => false,
			'icons'     => false,
			'wishlist'  => false,
		),
		'embed'       => array(
			'shared_ui' => true,
			'icons'     => true,
			'wishlist'  => false,
		),
		'course-card' => array(
			'shared_ui' => true,
			'icons'     => true,
			'wishlist'  => true,
		),
	);

	/**
	 * The one public entry point to Learnomy's frontend assets.
	 *
	 * Every surface that puts Learnomy markup on a page goes through here: the
	 * router (with a route name), and every shortcode, widget and block (with a
	 * route name when it renders a route's template, or a light surface key from
	 * {@see SURFACE_BUNDLES} when it renders a fragment).
	 *
	 * Passing a route name runs the SAME dispatcher the router runs, so
	 * `[learnomy_membership_pricing]` on a site owner's own pricing page gets
	 * membership.css, membership-view.js, the Interactivity runtime and the icon
	 * set — exactly what /membership/ gets — with no second list to keep in sync.
	 *
	 * @param string $surface Route name, or a key of self::SURFACE_BUNDLES.
	 */
	public static function enqueue_for_surface( string $surface ): void {
		if ( isset( self::SURFACE_BUNDLES[ $surface ] ) ) {
			self::enqueue_surface_bundle( $surface );
			return;
		}

		self::enqueue_assets( $surface );
	}

	/**
	 * Assemble one light bundle from the shared primitives.
	 *
	 * @param string $surface Key of self::SURFACE_BUNDLES.
	 */
	private static function enqueue_surface_bundle( string $surface ): void {
		static $done = array();
		if ( isset( $done[ $surface ] ) ) {
			return;
		}
		$done[ $surface ] = true;

		$bundle = self::SURFACE_BUNDLES[ $surface ];

		self::enqueue_base_styles();

		// Components that exist ONLY on embedded surfaces — the widget lists, the
		// instructor/quiz/certificate embed cards, the block wrappers. They have
		// no home in a route stylesheet because no route renders them.
		wp_enqueue_style(
			'learnomy-embedded',
			LEARNOMY_URL . 'assets/css/shared/embedded.css',
			array( 'learnomy' ),
			LEARNOMY_VERSION
		);

		if ( $bundle['shared_ui'] ) {
			self::enqueue_shared_ui_styles();
			self::enqueue_shared_ui_scripts();
		}

		if ( $bundle['icons'] ) {
			self::enqueue_icons();
		}

		if ( $bundle['wishlist'] ) {
			\Learnomy\enqueue_wishlist_assets();
		}
	}

	/**
	 * The master stylesheet plus the content-image layout rules that ride with it.
	 *
	 * Content images land wherever authored content renders — a lesson body, a
	 * course description, a Pro learning-path summary — so the sheet belongs to
	 * the base, not to any one player.
	 */
	private static function enqueue_base_styles(): void {
		wp_enqueue_style(
			'learnomy',
			LEARNOMY_URL . 'assets/css/learnomy.css',
			array(),
			LEARNOMY_VERSION
		);

		// Same file the editor loads, so the preview and the published page
		// cannot disagree.
		wp_enqueue_style(
			'learnomy-content-image',
			LEARNOMY_URL . 'assets/css/shared/content-image.css',
			array( 'learnomy' ),
			LEARNOMY_VERSION
		);
	}

	/**
	 * Shared UI utilities — toast notifications, skeleton loaders, pagination,
	 * notices, animations, the confirm modal.
	 */
	private static function enqueue_shared_ui_styles(): void {
		$sheets = array(
			'learnomy-toast'         => 'toast.css',
			'learnomy-skeleton'      => 'skeleton.css',
			'learnomy-pagination'    => 'pagination.css',
			'learnomy-notice'        => 'notice.css',
			'learnomy-animations'    => 'animations.css',
			'learnomy-confirm-modal' => 'confirm-modal.css',
		);

		foreach ( $sheets as $handle => $file ) {
			wp_enqueue_style(
				$handle,
				LEARNOMY_URL . 'assets/css/shared/' . $file,
				array( 'learnomy' ),
				LEARNOMY_VERSION
			);
		}
	}

	/**
	 * The shared UI JavaScript layer — wp.apiFetch for Interactivity stores that
	 * call REST, plus window.lrnToast / lrnConfirm / lrnPrompt and the shared
	 * entrance animations. Every Learnomy surface that can talk back to the user
	 * expects these globals to exist.
	 */
	private static function enqueue_shared_ui_scripts(): void {
		// WordPress API Fetch — needed by Interactivity API stores that call REST endpoints.
		wp_enqueue_script( 'wp-api-fetch' );

		wp_enqueue_script(
			'learnomy-toast',
			LEARNOMY_URL . 'assets/js/shared/toast.js',
			array( 'wp-i18n' ),
			LEARNOMY_VERSION,
			true
		);
		wp_set_script_translations( 'learnomy-toast', 'learnomy', LEARNOMY_DIR . 'languages' );

		wp_enqueue_script(
			'learnomy-animations',
			LEARNOMY_URL . 'assets/js/shared/animations.js',
			array(),
			LEARNOMY_VERSION,
			true
		);

		// Confirmation modal — window.lrnConfirm() replaces native confirm()
		// for Interactivity API stores on quiz/builder/lesson pages.
		wp_enqueue_script(
			'learnomy-confirm-modal',
			LEARNOMY_URL . 'assets/js/shared/confirm-modal.js',
			array( 'wp-i18n' ),
			LEARNOMY_VERSION,
			true
		);

		// Prompt modal — window.lrnPrompt() for inline title / rename input.
		wp_enqueue_script(
			'learnomy-prompt-modal',
			LEARNOMY_URL . 'assets/js/shared/prompt-modal.js',
			array( 'wp-i18n', 'learnomy-confirm-modal' ),
			LEARNOMY_VERSION,
			true
		);
	}

	/**
	 * Lucide icons — replaces `<i data-lucide="…">` with inline SVGs.
	 *
	 * Safe to call from every caller (route bundle, embedded bundle, wishlist
	 * helper, Pro surfaces): `wp_enqueue_script()` is idempotent per handle, so
	 * repeat calls cost nothing.
	 *
	 * There is deliberately NO `wp_script_is( 'lucide' )` early return. One used
	 * to guard this whole method, which meant that whenever ANYTHING had already
	 * enqueued the `lucide` handle — another surface, an extension, a theme —
	 * this returned before reaching lucide-live, and the live conversion seam
	 * silently vanished for that request. The guard made correctness depend on
	 * hook order, and it was guarding against a duplicate WordPress already
	 * prevents (Basecamp 10171508068).
	 */
	public static function enqueue_icons(): void {
		wp_enqueue_script(
			'lucide',
			LEARNOMY_URL . 'assets/js/vendor/lucide.min.js',
			array(),
			LEARNOMY_VERSION,
			true
		);
		// One conversion seam, not one pass: lucide-live.js converts at load
		// AND keeps converting what the Interactivity API and AJAX render
		// later (Basecamp 10171508068 - the quiz player's bound icons never
		// converted, while six modules carried hand-rolled patches).
		wp_enqueue_script(
			'learnomy-lucide-live',
			LEARNOMY_URL . 'assets/js/lucide-live.js',
			array( 'lucide' ),
			LEARNOMY_VERSION,
			true
		);
	}

	/**
	 * Enqueue the master stylesheet, the Interactivity API view module, and any
	 * route-specific JS modules.
	 *
	 * Wp_enqueue_script_module() is used for Interactivity API modules so they
	 * are output with type="module" and participate in the module import graph.
	 *
	 * Reached through {@see enqueue_for_surface()} — the router passes the route
	 * it is about to render, an embedded surface passes the route whose template
	 * it renders. Runs once per route per request.
	 *
	 * @param string $route Current route name.
	 */
	private static function enqueue_assets( string $route ): void {
		static $done = array();
		if ( isset( $done[ $route ] ) ) {
			return;
		}
		$done[ $route ] = true;

		self::enqueue_base_styles();
		self::enqueue_shared_ui_styles();

		// Catalog slice — filter bar, view-toggle, grid breakpoints, list view.
		// Only the /courses/ and /courses/category/{slug}/ routes need it.
		if ( in_array( $route, array( 'catalog', 'category' ), true ) ) {
			wp_enqueue_style(
				'learnomy-catalog',
				LEARNOMY_URL . 'assets/css/catalog.css',
				array( 'learnomy' ),
				LEARNOMY_VERSION
			);

			// Per-variant layout slice for the catalog surface. The default
			// 'grid' lives in catalog.css; other variants add a stylesheet.
			if ( 'catalog' === $route ) {
				$catalog_variant = \Learnomy\layout_variant( 'archive' );
				if ( 'grid' !== $catalog_variant ) {
					wp_enqueue_style(
						'learnomy-catalog-' . $catalog_variant,
						LEARNOMY_URL . 'assets/css/catalog/' . $catalog_variant . '.css',
						array( 'learnomy-catalog' ),
						LEARNOMY_VERSION
					);
				}
			}
		}

		// Single-course slice — sidebar purchase card, curriculum accordion,
		// curriculum drawer (mobile bottom sheet), single-course layout grid.
		// Only the /courses/{slug}/ route needs it.
		if ( 'course' === $route ) {
			wp_enqueue_style(
				'learnomy-single-course',
				LEARNOMY_URL . 'assets/css/single-course.css',
				array( 'learnomy' ),
				LEARNOMY_VERSION
			);

			// Per-variant layout slice for the single-course surface. The
			// default 'classic' lives in single-course.css; other variants add
			// a stylesheet layered on top (handle learnomy-course-<variant>,
			// dep learnomy-single-course). Mirrors the catalog per-variant block.
			$course_variant = \Learnomy\layout_variant( 'single-course' );
			if ( 'classic' !== $course_variant ) {
				wp_enqueue_style(
					'learnomy-course-' . $course_variant,
					LEARNOMY_URL . 'assets/css/course/' . $course_variant . '.css',
					array( 'learnomy-single-course' ),
					LEARNOMY_VERSION
				);
			}
		}

		// Dashboards slice — KPI tiles, certificate cards, instructor
		// profile header, membership-card row, all .lrn-dashboard__* +
		// .lrn-stats-row grid rules. Loaded only on the dashboard
		// routes so non-dashboard pages stay slim. The Pro Space Owner
		// dashboard at /spaces/{slug}/manage/ (Pro `space-manage` route)
		// reuses these same classes — without this CSS, every KPI tile
		// collapsed into a stacked column with no breathing room.
		// Account sections (Certificates, etc.) reuse dashboard widget partials,
		// so they need the dashboard widget styles too.
		// Instructor rail section routes that render through the shared teaching
		// shell (instructor-section.php / the dashboard) and therefore need both
		// the dashboards + account stylesheets. Filterable so a Pro extension
		// that registers an instructor rail section (e.g. Cohorts adds
		// `instructor-cohorts`) inherits the shell CSS without forking core —
		// the matching seam to `learnomy_instructor_sections`. Without this the
		// section renders raw (unstyled nav, no two-column layout).
		$lrn_instructor_shell_routes = (array) apply_filters(
			'learnomy_instructor_shell_routes',
			array( 'instructor-dashboard', 'instructor-reports', 'instructor-courses', 'instructor-students', 'instructor-earnings', 'instructor-grading' )
		);

		$lrn_dash_css_routes = array_merge(
			array( 'student-dashboard', 'instructor-profile', 'space-manage' ),
			$lrn_instructor_shell_routes,
			\Learnomy\account_routes()
		);
		if ( in_array( $route, $lrn_dash_css_routes, true ) ) {
			wp_enqueue_style(
				'learnomy-dashboards',
				LEARNOMY_URL . 'assets/css/dashboards.css',
				array( 'learnomy' ),
				LEARNOMY_VERSION
			);
		}

		// Quiz player slice — shell shared by every quiz template (start screen,
		// top bar with progress ring + timer, question/answer chrome, gate
		// screens, results + review, anti-cheat warning, ordering/matching
		// question types). The per-template overlays in assets/css/quiz/{name}.css
		// are enqueued separately below.
		// The quiz player slice also styles the per-question review
		// (.lrn-quiz__review-*), which the account quiz-results page reuses for
		// its persistent attempt review — so load it there too.
		if ( in_array( $route, array( 'quiz', 'standalone-quiz', 'account-quiz-results' ), true ) ) {
			wp_enqueue_style(
				'learnomy-quiz-player',
				LEARNOMY_URL . 'assets/css/quiz-player.css',
				array( 'learnomy' ),
				LEARNOMY_VERSION
			);
			// Review filter chips — shared by the live results screen and the
			// account attempt review, so it rides beside the shared CSS.
			wp_enqueue_script(
				'learnomy-quiz-review',
				LEARNOMY_URL . 'assets/js/quiz-review.js',
				array(),
				LEARNOMY_VERSION,
				true
			);
		}

		// Lesson player slice — sidebar, Plyr overrides, completion strip,
		// comments thread, live lesson card, access-gate chrome. Only the
		// /courses/{slug}/lesson/{lesson-slug}/ route needs it.
		if ( 'lesson' === $route ) {
			wp_enqueue_style(
				'learnomy-lesson-player',
				LEARNOMY_URL . 'assets/css/lesson-player.css',
				array( 'learnomy' ),
				LEARNOMY_VERSION
			);

			// The unified embed component (.lrn-embed) lives in one shared
			// stylesheet so the exact same rules style the player AND every
			// authoring editor (no dups). Loaded here for the lesson body's embeds.
			wp_enqueue_style(
				'learnomy-embed',
				LEARNOMY_URL . 'assets/css/shared/embed.css',
				array( 'learnomy-lesson-player' ),
				LEARNOMY_VERSION
			);

			// Per-variant layout slice for the lesson surface. The default
			// 'focus' lives in lesson-player.css; other variants add a
			// stylesheet layered on top (handle learnomy-lesson-<variant>,
			// dep learnomy-lesson-player). Mirrors the single-course block.
			$lesson_variant = \Learnomy\layout_variant( 'lesson' );
			if ( 'focus' !== $lesson_variant ) {
				wp_enqueue_style(
					'learnomy-lesson-' . $lesson_variant,
					LEARNOMY_URL . 'assets/css/lesson/' . $lesson_variant . '.css',
					array( 'learnomy-lesson-player' ),
					LEARNOMY_VERSION
				);
			}
		}

		// Certificate verify slice — verify card, status banner, details
		// table, preview scaler, share/download buttons. Only the public
		// /verify/{uuid}/ route needs it.
		if ( 'certificate-verify' === $route ) {
			wp_enqueue_style(
				'learnomy-cert-verify',
				LEARNOMY_URL . 'assets/css/cert-verify.css',
				array( 'learnomy' ),
				LEARNOMY_VERSION
			);
		}

		// My Account area + the instructor teaching shell — both use the shared
		// vertical-nav + two-column layout (.lrn-account-layout/.lrn-account-nav),
		// so the instructor rail gets the same panel styling without forking CSS.
		$lrn_shell_routes = array_merge( \Learnomy\account_routes(), $lrn_instructor_shell_routes );
		if ( in_array( $route, $lrn_shell_routes, true ) ) {
			wp_enqueue_style(
				'learnomy-account',
				LEARNOMY_URL . 'assets/css/account.css',
				array( 'learnomy' ),
				LEARNOMY_VERSION
			);
			// Collapses the shell rail into its disclosure below 768px. The markup
			// ships expanded so a closed <details> — which Chrome and Safari do not
			// paint — can never blank the rail, which makes this script the piece
			// that produces the compact layout rather than an enhancement of it.
			// Dependency-free, and inert at rail width.
			wp_enqueue_script(
				'learnomy-shell-nav',
				LEARNOMY_URL . 'assets/js/shell-nav.js',
				array(),
				LEARNOMY_VERSION,
				true
			);
		}

		// Account billing — Stripe Customer Portal button handler. The button
		// only renders for Stripe subscriptions (the view-data flags it on the
		// row), so the JS is route-scoped to keep the bundle off pages that
		// don't need it.
		if ( 'account-billing' === $route ) {
			// The billing-address form on this page renders
			// partials/billing-fields.php, whose styles live in their own
			// sheet. Without this the fields came through as raw browser
			// inputs (Basecamp 10171338907).
			wp_enqueue_style(
				'learnomy-billing-fields',
				LEARNOMY_URL . 'assets/css/billing-fields.css',
				array( 'learnomy' ),
				LEARNOMY_VERSION
			);
			wp_enqueue_style(
				'learnomy-account-billing',
				LEARNOMY_URL . 'assets/css/account-billing.css',
				array( 'learnomy' ),
				LEARNOMY_VERSION
			);
			wp_enqueue_script(
				'learnomy-account-billing',
				LEARNOMY_URL . 'assets/js/account-billing.js',
				// `learnomy-toast` is how this file reports a failed portal
				// open, so it is a real dependency and must be declared. It was
				// only ever loaded incidentally by enqueue_shared_ui_scripts(),
				// which means any future reordering would have silently demoted
				// every portal error to a console.error the buyer never sees.
				// `wp-api-fetch` is NOT used here (the file calls fetch directly)
				// and has been dropped.
				array( 'learnomy-toast' ),
				LEARNOMY_VERSION,
				true
			);
			wp_localize_script(
				'learnomy-account-billing',
				'lrnAccountBilling',
				array(
					'restUrl'    => esc_url_raw( rest_url( 'learnomy/v1/account/billing/portal' ) ),
					// The member's own address, saved from this same page.
					'addressUrl' => esc_url_raw( rest_url( 'learnomy/v1/account/billing/address' ) ),
					'nonce'      => wp_create_nonce( 'wp_rest' ),
					'i18n'       => array(
						'portalFailed'  => __( 'Could not open the Stripe Customer Portal. Try again in a moment.', 'learnomy' ),
						'portalOpening' => __( 'Opening Stripe…', 'learnomy' ),
						'portalCta'     => __( 'Manage payment method', 'learnomy' ),
						'addressSaving' => __( 'Saving…', 'learnomy' ),
						'addressSaved'  => __( 'Address saved.', 'learnomy' ),
						'addressFailed' => __( 'Could not save your address. Try again.', 'learnomy' ),
					),
				)
			);
		}

		// Account details — self-service profile / email / password form, saved
		// via REST (POST learnomy/v1/auth/account).
		if ( 'account-details' === $route ) {
			wp_enqueue_script(
				'learnomy-account-details',
				LEARNOMY_URL . 'assets/js/account-details.js',
				// Depends on lrnConfirm (delete-account confirm) + wp.i18n.
				array( 'learnomy-confirm-modal', 'wp-i18n' ),
				LEARNOMY_VERSION,
				true
			);
			wp_localize_script(
				'learnomy-account-details',
				'lrnAccountDetails',
				array(
					'restUrl' => esc_url_raw( rest_url( 'learnomy/v1/auth/account' ) ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
					'i18n'    => array(
						'saved'        => __( 'Your changes have been saved.', 'learnomy' ),
						/* translators: %s: email address */
						'emailPending' => __( 'A confirmation link was sent to %s. Open it to switch your email.', 'learnomy' ),
						'genericError' => __( 'Something went wrong. Please try again.', 'learnomy' ),
					),
				)
			);
			wp_set_script_translations( 'learnomy-account-details', 'learnomy', LEARNOMY_DIR . 'languages' );

			// Avatar upload + square cropper (Profile section). Self-contained
			// (no third-party crop lib); depends on lrnConfirm for the remove
			// confirmation and lrnToast for success feedback.
			wp_enqueue_script(
				'learnomy-account-avatar',
				LEARNOMY_URL . 'assets/js/account-avatar.js',
				array( 'learnomy-confirm-modal', 'wp-i18n' ),
				LEARNOMY_VERSION,
				true
			);
			wp_set_script_translations( 'learnomy-account-avatar', 'learnomy', LEARNOMY_DIR . 'languages' );
		}

		// Instructor self-edit profile form, saved via REST
		// (POST learnomy/v1/account/instructor-profile).
		if ( 'account-instructor-profile' === $route ) {
			wp_enqueue_script(
				'learnomy-instructor-profile',
				LEARNOMY_URL . 'assets/js/account-instructor-profile.js',
				array(),
				LEARNOMY_VERSION,
				true
			);
			wp_localize_script(
				'learnomy-instructor-profile',
				'lrnInstructorProfile',
				array(
					'restUrl' => esc_url_raw( rest_url( 'learnomy/v1/account/instructor-profile' ) ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
					'i18n'    => array(
						'saved'        => __( 'Your profile has been saved.', 'learnomy' ),
						'genericError' => __( 'Something went wrong. Please try again.', 'learnomy' ),
					),
				)
			);
		}

		// Broadcast inline form — instructor compose-announcement widget on
		// /instructor/ and space-owner broadcast-to-members widget on
		// /spaces/{slug}/manage/ both render `.lrn-broadcast-form` markup
		// and share the same handler.
		if ( in_array( $route, array( 'instructor-dashboard', 'space-manage' ), true ) ) {
			wp_enqueue_script(
				'learnomy-broadcast-form',
				LEARNOMY_URL . 'assets/js/broadcast-form.js',
				array(),
				LEARNOMY_VERSION,
				true
			);
			wp_localize_script(
				'learnomy-broadcast-form',
				'learnomyBroadcast',
				array(
					'i18n' => array(
						'fillBoth'   => __( 'Please fill in both fields.', 'learnomy' ),
						'sending'    => __( 'Sending…', 'learnomy' ),
						'sendFailed' => __( 'Could not send. Please try again.', 'learnomy' ),
						'queuedOne'  => __( 'Queued for 1 recipient. They will receive it shortly.', 'learnomy' ),
						/* translators: %d: number of recipients the announcement was queued for */
						'queuedMany' => __( 'Queued for %d recipients. They will receive it shortly.', 'learnomy' ),
						'sentOne'    => __( 'Sent to 1 recipient.', 'learnomy' ),
						/* translators: %d: number of recipients the announcement was sent to */
						'sentMany'   => __( 'Sent to %d recipients.', 'learnomy' ),
						/* translators: %d: number of recipients (recently-sent list meta) */
						'metaOne'    => __( '1 recipient · just now', 'learnomy' ),
						/* translators: %d: number of recipients (recently-sent list meta) */
						'metaMany'   => __( '%d recipients · just now', 'learnomy' ),
					),
				)
			);
		}

		self::enqueue_shared_ui_scripts();

		// Cancel modal — save-the-customer flow (PR-D). Only enqueued on the
		// surfaces that actually surface a cancel button; the rest of the
		// frontend stays lean.
		if ( in_array( $route, array( 'membership-manage', 'account-billing' ), true ) ) {
			wp_enqueue_style(
				'learnomy-cancel-modal',
				LEARNOMY_URL . 'assets/css/shared/cancel-modal.css',
				array( 'learnomy' ),
				LEARNOMY_VERSION
			);
			wp_enqueue_script(
				'learnomy-cancel-modal',
				LEARNOMY_URL . 'assets/js/shared/cancel-modal.js',
				array( 'wp-i18n' ),
				LEARNOMY_VERSION,
				true
			);
			wp_localize_script(
				'learnomy-cancel-modal',
				'lrnCancelConfig',
				array(
					'restUrl' => esc_url_raw( rest_url( 'learnomy/v1' ) ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
				)
			);
		}

		// KaTeX — renders math formulas in lesson content. Both files were
		// referenced but never shipped, causing 404s on every lesson page.
		// Now defensive: the inline-script renderer is registered only when
		// both vendor files exist, so missing assets degrade gracefully
		// (math blocks fall back to their raw formula text instead of
		// breaking the page with console errors).
		$katex_css = LEARNOMY_DIR . 'assets/css/vendor/katex.min.css';
		$katex_js  = LEARNOMY_DIR . 'assets/js/vendor/editorjs/katex.min.js';
		if ( file_exists( $katex_css ) && file_exists( $katex_js ) ) {
			wp_enqueue_style( 'katex', LEARNOMY_URL . 'assets/css/vendor/katex.min.css', array(), '0.16.11' );
			wp_enqueue_script( 'katex', LEARNOMY_URL . 'assets/js/vendor/editorjs/katex.min.js', array(), '0.16.11', true );
			wp_add_inline_script( 'katex', 'document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll(".lrn-math[data-formula]").forEach(function(el){if(window.katex)katex.render(el.dataset.formula,el,{throwOnError:false});});});' );
		}

		// Lucide icons — replaces <i data-lucide="…"> with inline SVGs.
		self::enqueue_icons();

		// Core Interactivity API view module — always loaded.
		if ( function_exists( 'wp_enqueue_script_module' ) ) {
			wp_enqueue_script_module(
				'learnomy-view',
				LEARNOMY_URL . 'assets/js/view.js',
				array( '@wordpress/interactivity' ),
				LEARNOMY_VERSION
			);
		}

		// Route-specific modules loaded only where needed.
		$route_modules = array(
			'course'             => 'curriculum-drawer',
			'catalog'            => 'catalog-view',
			'category'           => 'catalog-view',
			'quiz'               => 'quiz-view',
			'standalone-quiz'    => 'quiz-view',
			'membership-pricing' => 'membership-view',
			'membership-manage'  => 'membership-view',
		);

		// Membership pages also use a dedicated stylesheet.
		if ( in_array( $route, array( 'membership-pricing', 'membership-manage' ), true ) ) {
			wp_enqueue_style(
				'learnomy-membership',
				LEARNOMY_URL . 'assets/css/membership.css',
				array( 'learnomy' ),
				LEARNOMY_VERSION
			);
		}

		if ( isset( $route_modules[ $route ] ) ) {
			$module_handle = 'learnomy-' . $route_modules[ $route ];
			$module_file   = LEARNOMY_URL . 'assets/js/' . $route_modules[ $route ] . '.js';

			if ( function_exists( 'wp_enqueue_script_module' ) ) {
				wp_enqueue_script_module(
					$module_handle,
					$module_file,
					array( 'learnomy-view' ),
					LEARNOMY_VERSION
				);
			}

			// Catalog route: pass translated strings for inline JS state labels
			// so `loadMoreLabel` is i18n-aware across all languages. The module
			// reads these from `window.lrnCatalog.i18n`.
			if ( in_array( $route, array( 'catalog', 'category' ), true ) ) {
				// Seed searchQuery into the store BEFORE hydration. The search
				// input binds data-wp-bind--value="state.searchQuery" (so typing
				// survives the typeahead re-render — an unbound input was reset
				// to its server-rendered value the moment the suggestion dropdown
				// flipped aria-expanded, wiping the customer's text after 2
				// characters). The JS default is '' and onInit runs only after
				// hydration, so without this seed a ?s= deep link would flash
				// empty before onInit refilled it.
				if ( function_exists( 'wp_interactivity_state' ) ) {
					wp_interactivity_state(
						'learnomy/catalog',
						array(
							// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only render of a public filter param.
							'searchQuery' => sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ),
						)
					);
				}
				wp_add_inline_script(
					'lucide',
					'window.lrnCatalog = window.lrnCatalog || {}; window.lrnCatalog.i18n = ' . wp_json_encode(
						array(
							'loading'   => __( 'Loading…', 'learnomy' ),
							/* translators: %s: plural Course label, renamable in Settings > Labels */
							'loadMore'  => sprintf( __( 'Load More %s', 'learnomy' ), \Learnomy\course_label( true ) ),
							'noResults' => sprintf( /* translators: %s: plural Course label */ __( 'No matching %s', 'learnomy' ), \Learnomy\course_label( true ) ),
							'searching' => __( 'Searching…', 'learnomy' ),
						)
					) . '; window.lrnCatalog.courseUrlTemplate = ' . wp_json_encode(
						// Use a lowercase-alphanumeric token so route_url's slug
						// sanitisation leaves it intact (a %SLUG% placeholder gets
						// URL-encoded to %25SLUG%25 and never matches in JS).
						\Learnomy\route_url( 'course', array( 'slug' => 'lrnslugtoken' ) )
					) . ';',
					'before'
				);
			}
		}

		// Membership pages: seed the cancel/resume dialog strings into
		// window.lrnMembership.i18n. membership-view.js is an ES module, which
		// cannot use wp_set_script_translations, so the strings are injected
		// server-side (same pattern as window.lrnQuiz / window.lrnCatalog).
		if ( in_array( $route, array( 'membership-pricing', 'membership-manage' ), true ) ) {
			wp_add_inline_script(
				'lucide',
				'window.lrnMembership = window.lrnMembership || {}; window.lrnMembership.i18n = ' . wp_json_encode(
					array(
						'cancelTitle'      => __( 'Cancel subscription', 'learnomy' ),
						'cancelConfirm'    => __( 'You will keep access until the end of your current paid period. Continue?', 'learnomy' ),
						'keepSubscription' => __( 'Keep subscription', 'learnomy' ),
						'offerAccepted'    => __( 'Offer accepted. Your subscription was kept.', 'learnomy' ),
						'cancelFailed'     => __( 'Unable to cancel. Please try again.', 'learnomy' ),
						'cancelled'        => __( 'Subscription cancelled. It stays active until the end of the period.', 'learnomy' ),
						'reverted'         => __( 'Cancellation reverted.', 'learnomy' ),
						'revertFailed'     => __( 'Unable to revert. Please refresh and try again.', 'learnomy' ),
						'resumeFailed'     => __( 'Unable to resume. Please try again.', 'learnomy' ),
					)
				) . ';',
				'before'
			);
		}

		// Quiz route: inject translated strings into window.lrnQuiz.i18n so the
		// quiz-view.js Interactivity store can surface locale-aware labels without
		// a build-step dependency on @wordpress/i18n.
		if ( in_array( $route, array( 'quiz', 'standalone-quiz' ), true ) ) {
			wp_add_inline_script(
				'lucide',
				'window.lrnQuiz = window.lrnQuiz || {}; window.lrnQuiz.i18n = ' . wp_json_encode(
					array(
						'loading'                 => __( 'Loading…', 'learnomy' ),
						/* translators: %s: singular Quiz label, renamable in Settings > Labels */
						'startQuiz'               => sprintf( __( 'Start %s', 'learnomy' ), \Learnomy\quiz_label() ),
						/* translators: %s: singular Quiz label, renamable in Settings > Labels */
						'retakeQuiz'              => sprintf( __( 'Retake %s', 'learnomy' ), \Learnomy\quiz_label() ),
						/* translators: Unicode right arrow appended to button label */
						'next'                    => __( 'Next', 'learnomy' ),
						/* translators: %s: singular Quiz label, renamable in Settings > Labels */
						'submitQuiz'              => sprintf( __( 'Submit %s', 'learnomy' ), \Learnomy\quiz_label() ),
						'congratulations'         => __( 'Congratulations!', 'learnomy' ),
						/* translators: %s: singular Quiz label, renamable in Settings > Labels */
						'quizComplete'            => sprintf( __( '%s complete', 'learnomy' ), \Learnomy\quiz_label() ),
						/* translators: %d: score percentage */
						'youScored'               => __( 'You scored %d%%', 'learnomy' ),
						'youPassed'               => sprintf( /* translators: %s: singular Quiz label */ __( 'You passed the %s!', 'learnomy' ), \Learnomy\quiz_label() ),
						'youDidNotPass'           => __( 'You did not reach the passing score. Try again!', 'learnomy' ),
						'correct'                 => __( 'Correct!', 'learnomy' ),
						'incorrect'               => __( 'Incorrect', 'learnomy' ),
						/* translators: %d: number of questions answered, %d: total questions */
						'answeredOf'              => __( '%1$d of %2$d answered', 'learnomy' ),
						'questionStillUnanswered' => __( '1 question still unanswered', 'learnomy' ),
						/* translators: %d: number of unanswered questions */
						'questionsUnanswered'     => __( '%d questions still unanswered', 'learnomy' ),
						/* translators: %d: count correct */
						'countCorrect'            => __( '%d correct', 'learnomy' ),
						/* translators: %d: count incorrect */
						'countIncorrect'          => __( '%d incorrect', 'learnomy' ),
						/* translators: %d: count pending */
						'countPending'            => __( '%d pending review', 'learnomy' ),
						/* translators: %d: blank number in a fill-in-the-blank question */
						'blankLabel'              => __( 'Blank %d', 'learnomy' ),
						'failedToStart'           => sprintf( /* translators: %s: singular Quiz label */ __( 'Failed to start %s.', 'learnomy' ), \Learnomy\quiz_label() ),
						'failedToSubmit'          => sprintf( /* translators: %s: singular Quiz label */ __( 'Failed to submit %s.', 'learnomy' ), \Learnomy\quiz_label() ),
						'incorrectCode'           => __( 'Incorrect access code.', 'learnomy' ),
						'apiUnavailable'          => __( 'API not available. Reload and try again.', 'learnomy' ),
						// Runner controls (flagging, pre-submit summary, resume banner).
						'flagged'                 => __( 'Flagged', 'learnomy' ),
						'flagForReview'           => __( 'Flag for review', 'learnomy' ),
						'oneUnanswered'           => __( '1 unanswered', 'learnomy' ),
						/* translators: %d: number of unanswered questions */
						'nUnanswered'             => __( '%d unanswered', 'learnomy' ),
						'oneFlagged'              => __( '1 flagged', 'learnomy' ),
						/* translators: %d: number of flagged questions */
						'nFlagged'                => __( '%d flagged', 'learnomy' ),
						'answerRestored'          => __( 'answer restored', 'learnomy' ),
						'answersRestored'         => __( 'answers restored', 'learnomy' ),
						/* translators: appended after a countdown, e.g. "12:34 left" */
						'timeLeft'                => __( 'left', 'learnomy' ),
						// Category grouping fallback (classic + review-board layouts).
						'generalCategory'         => __( 'General', 'learnomy' ),
						// Sectioned (SAT/GRE-style) layout controls.
						'oneQuestionInSection'    => __( '1 question in this section', 'learnomy' ),
						/* translators: %d: number of questions in the current section */
						'nQuestionsInSection'     => __( '%d questions in this section', 'learnomy' ),
						'endSection'              => __( 'End Section', 'learnomy' ),
						'endSectionTitle'         => __( 'End section', 'learnomy' ),
						'endSectionConfirm'       => __( 'Are you sure you want to end this section? You cannot return to it.', 'learnomy' ),
						/* translators: %d: section number */
						'sectionLabel'            => __( 'Section %d', 'learnomy' ),
						/* translators: 1: current section number, 2: total sections */
						'sectionBadge'            => __( 'Section %1$d of %2$d', 'learnomy' ),
						/* translators: 1: current question number, 2: questions in the section */
						'sectionQuestionBadge'    => __( 'Q%1$d of %2$d', 'learnomy' ),
						/* translators: 1: correct answers in the group, 2: questions in the group */
						'groupCorrectSummary'     => __( '%1$d of %2$d correct', 'learnomy' ),
					)
				) . ';',
				'before'
			);
		}

		// Load template-specific quiz JS module and CSS if they exist.
		// The base quiz-view module is already loaded above; this adds the
		// classic (or other) template overlay that extends the store.
		if ( in_array( $route, array( 'quiz', 'standalone-quiz' ), true ) ) {
			$quiz_slug_var   = get_query_var( 'learnomy_quiz_slug', '' );
			$course_slug_var = get_query_var( 'learnomy_slug', '' );

			// Course-attached: ?learnomy_quiz_slug present, scope by course.
			// Standalone: only ?learnomy_slug present, use that as quiz slug.
			if ( $quiz_slug_var ) {
				$course_for_quiz   = $course_slug_var
					? \Learnomy\Models\Course::find_by_slug( $course_slug_var )
					: null;
				$quiz_for_template = $course_for_quiz
					? \Learnomy\Models\Quiz::find_by_course_and_slug( (int) $course_for_quiz->id, $quiz_slug_var )
					: null;
			} else {
				$quiz_for_template = $course_slug_var
					? \Learnomy\Models\Quiz::find_standalone_by_slug( $course_slug_var )
					: null;
			}

			if ( $quiz_for_template ) {
				$tmpl = $quiz_for_template->display_template ?? 'focused';
				if ( 'focused' !== $tmpl ) {
					$tmpl_module = 'assets/js/quiz-view-' . $tmpl . '.js';
					if ( file_exists( LEARNOMY_DIR . $tmpl_module ) ) {
						wp_enqueue_script_module(
							'learnomy-quiz-view-' . $tmpl,
							LEARNOMY_URL . $tmpl_module,
							array( 'learnomy-quiz-view' ),
							LEARNOMY_VERSION
						);
					}
				}
				// Template-specific CSS.
				$tmpl_css = 'assets/css/quiz/' . $tmpl . '.css';
				if ( file_exists( LEARNOMY_DIR . $tmpl_css ) ) {
					wp_enqueue_style(
						'learnomy-quiz-' . $tmpl,
						LEARNOMY_URL . $tmpl_css,
						array( 'learnomy' ),
						LEARNOMY_VERSION
					);
				}
			}
		}

		// Checkout page: vanilla JS (no Interactivity API), Stripe/PayPal SDKs.
		if ( 'enroll' === $route ) {
			self::enqueue_checkout_assets();
		}

		// Thank-you + cancelled pages share a small token-driven CSS slice
		// that styles the lrn-card lock-up + CTAs. No JS needed — the pending
		// state uses a plain <meta http-equiv="refresh"> so the polling
		// behaviour survives even when JS is disabled or the page is
		// rendered inside the browser's back-forward cache.
		if ( in_array( $route, array( 'enroll-thank-you', 'enroll-cancelled' ), true ) ) {
			wp_enqueue_style(
				'learnomy-checkout-thank-you',
				LEARNOMY_URL . 'assets/css/checkout-thank-you.css',
				array( 'learnomy' ),
				LEARNOMY_VERSION
			);
		}

		// Auth / onboarding routes: register-student, register-instructor, and
		// become-instructor (application form) all share the auth slice.
		if ( in_array( $route, array( 'register-student', 'register-instructor', 'become-instructor' ), true ) ) {
			wp_enqueue_style(
				'learnomy-register',
				LEARNOMY_URL . 'assets/css/register.css',
				array( 'learnomy' ),
				LEARNOMY_VERSION
			);
		}

		// Registration pages: vanilla JS for client-side validation + REST submit.
		if ( in_array( $route, array( 'register-student', 'register-instructor' ), true ) ) {
			wp_enqueue_script(
				'learnomy-register',
				LEARNOMY_URL . 'assets/js/register.js',
				array( 'wp-i18n' ),
				LEARNOMY_VERSION,
				true
			);
			wp_set_script_translations( 'learnomy-register', 'learnomy', LEARNOMY_DIR . 'languages' );

			wp_localize_script(
				'learnomy-register',
				'learnomyRegister',
				array(
					'restUrl'       => esc_url_raw( rest_url( 'learnomy/v1/' ) ),
					'nonce'         => wp_create_nonce( 'wp_rest' ),
					'registerNonce' => wp_create_nonce( 'learnomy_register' ),
					'myCoursesUrl'  => esc_url( \Learnomy\route_url( 'student-dashboard' ) ),
				)
			);
		}

		// Instructor grading — the queue + per-attempt grader. The grader saves
		// through the SAME REST route as wp-admin and the app; the script only
		// binds when a grader is on the page, so the queue view costs nothing
		// beyond the stylesheet.
		if ( 'instructor-grading' === $route ) {
			wp_enqueue_style(
				'learnomy-instructor-grading',
				LEARNOMY_URL . 'assets/css/instructor-grading.css',
				array( 'learnomy', 'learnomy-notice' ),
				LEARNOMY_VERSION
			);

			wp_enqueue_script(
				'learnomy-instructor-grading',
				LEARNOMY_URL . 'assets/js/instructor-grading.js',
				array( 'learnomy-toast' ),
				LEARNOMY_VERSION,
				true
			);
			wp_localize_script(
				'learnomy-instructor-grading',
				'lrnGrading',
				array(
					'restUrl' => esc_url_raw( rest_url( 'learnomy/v1/' ) ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
					'i18n'    => array(
						'needScore'  => __( 'Enter a score first.', 'learnomy' ),
						/* translators: %s: maximum marks for the question */
						'scoreRange' => __( 'Score must be between 0 and %s.', 'learnomy' ),
						'saving'     => __( 'Saving…', 'learnomy' ),
						'saved'      => __( 'Grade saved.', 'learnomy' ),
						'graded'     => __( 'Graded', 'learnomy' ),
						'failed'     => __( 'Could not save the grade. Please try again.', 'learnomy' ),
					),
				)
			);
		}

		// Instructor dashboard — withdrawal button opens the payout modal + POSTs
		// to /earnings/withdraw. Gated on the route so it only loads where needed.
		// Both the main dashboard AND the Earnings rail section render the
		// withdraw button (templates/dashboard-widgets/instructor/earnings.php),
		// so the modal script has to load on both — otherwise the button on the
		// Earnings page has no handler and silently does nothing.
		if ( in_array( $route, array( 'instructor-dashboard', 'instructor-earnings' ), true ) ) {
			wp_enqueue_script(
				'learnomy-instructor-dashboard',
				LEARNOMY_URL . 'assets/js/instructor-dashboard.js',
				array( 'wp-api-fetch', 'wp-i18n', 'learnomy-toast', 'learnomy-confirm-modal' ),
				LEARNOMY_VERSION,
				true
			);
			wp_set_script_translations(
				'learnomy-instructor-dashboard',
				'learnomy',
				LEARNOMY_DIR . 'languages'
			);

			// Surface the per-method payout toggles to the withdrawal modal
			// so disabled methods don't appear in the dropdown. The REST
			// layer enforces the same gate (see Earnings_Controller::
			// validate_payout_method) for hand-crafted POSTs. Wired in
			// Wave 2C rows 2C-5 / 2C-6.
			$lrn_inst_settings = get_option( 'learnomy_settings', array() );
			$lrn_inst_settings = is_array( $lrn_inst_settings ) ? $lrn_inst_settings : array();
			$payout_methods    = array(
				// Default-on when the option key is missing — matches install
				// defaults in includes/class-learnomy.php.
				'paypal'        => ! array_key_exists( 'payout_paypal', $lrn_inst_settings )
					|| ! empty( $lrn_inst_settings['payout_paypal'] ),
				'bank_transfer' => ! array_key_exists( 'payout_bank', $lrn_inst_settings )
					|| ! empty( $lrn_inst_settings['payout_bank'] ),
				// Stripe has no per-method toggle yet — always available
				// when the gateway is enabled separately.
				'stripe'        => true,
			);
			// Minimum withdrawal — same resolution as Earnings_Controller
			// (canonical `withdrawal_minimum`, legacy `min_withdrawal` fallback,
			// default 25). The modal JS hardcoded $50, ignoring this setting and
			// disagreeing with the server gate.
			$lrn_withdraw_min = (float) ( $lrn_inst_settings['withdrawal_minimum']
				?? $lrn_inst_settings['min_withdrawal']
				?? 25.0 );

			// Prefill the PayPal payout email the instructor entered last time, so
			// they don't retype it every withdrawal. Readable, low-sensitivity — a
			// PayPal payee address is a "pay me here" identifier, not a secret.
			$lrn_payout_email = (string) get_user_meta( get_current_user_id(), 'learnomy_payout_email', true );

			wp_localize_script(
				'learnomy-instructor-dashboard',
				'learnomyInstructorDashboard',
				array(
					'payoutMethods' => $payout_methods,
					'withdrawalMin' => $lrn_withdraw_min,
					'payoutEmail'   => $lrn_payout_email,
				)
			);
		}

		// Offline page — retry button reloads.
		if ( 'offline' === $route ) {
			wp_enqueue_script(
				'learnomy-offline',
				LEARNOMY_URL . 'assets/js/offline.js',
				array(),
				LEARNOMY_VERSION,
				true
			);
		}

		// Single-course page — sidebar coupon applier.
		if ( 'course' === $route ) {
			wp_enqueue_script(
				'learnomy-single-course-coupon',
				LEARNOMY_URL . 'assets/js/single-course-coupon.js',
				array(),
				LEARNOMY_VERSION,
				true
			);

			// Keeps the sticky sidebar clear of a theme's pinned header.
			wp_enqueue_script(
				'learnomy-single-course-sticky',
				LEARNOMY_URL . 'assets/js/single-course-sticky.js',
				array(),
				LEARNOMY_VERSION,
				true
			);
			wp_localize_script(
				'learnomy-single-course-coupon',
				'lrnSingleCourseCoupon',
				array(
					'i18n' => array(
						'empty'      => __( 'Please enter a coupon code.', 'learnomy' ),
						'applied'    => __( 'Coupon applied.', 'learnomy' ),
						'invalid'    => __( 'Invalid coupon.', 'learnomy' ),
						'networkErr' => __( 'Could not validate coupon.', 'learnomy' ),
						'youPay'     => __( 'You pay', 'learnomy' ),
						/* translators: shown after a discount amount, e.g. "20% off" */
						'off'        => __( 'off', 'learnomy' ),
					),
				)
			);

			// J-01.20 review form — only loaded on the course route. The
			// partial decides whether to render the form or the read-only
			// posted card, so we always enqueue here and the JS no-ops when
			// the form id is absent.
			wp_enqueue_script(
				'learnomy-review-form',
				LEARNOMY_URL . 'assets/js/review-form.js',
				array( 'learnomy-toast' ),
				LEARNOMY_VERSION,
				true
			);
			wp_localize_script(
				'learnomy-review-form',
				'lrnReviewForm',
				array(
					'restUrl'    => esc_url_raw( rest_url( 'learnomy/v1/' ) ),
					'nonce'      => wp_create_nonce( 'wp_rest' ),
					// Same ceilings the REST schema and the service enforce —
					// seeded from the model so the counter can never advertise a
					// limit the server disagrees with.
					'maxContent' => \Learnomy\Models\Review::MAX_CONTENT_LENGTH,
					'maxTitle'   => \Learnomy\Models\Review::MAX_TITLE_LENGTH,
					'i18n'       => array(
						'pickRating'  => __( 'Pick a star rating before submitting.', 'learnomy' ),
						'needContent' => __( 'Write a short review so others can learn from your experience.', 'learnomy' ),
						'posting'     => __( 'Posting…', 'learnomy' ),
						'posted'      => __( 'Review posted. Thanks for sharing.', 'learnomy' ),
						'thanks'      => sprintf( /* translators: %s: singular Course label */ __( 'Thanks for reviewing this %s.', 'learnomy' ), \Learnomy\course_label() ),
						'failed'      => __( 'Could not post your review. Please try again.', 'learnomy' ),
						'network'     => __( 'Network error. Check your connection and try again.', 'learnomy' ),
						/* translators: %s: maximum number of characters allowed in a review. */
						'tooLong'     => __( 'Your review is too long. Please keep it under %s characters.', 'learnomy' ),
						/* translators: %s: number of characters still available. */
						'charsLeft'   => __( '%s characters left', 'learnomy' ),
					),
				)
			);

			// Instructor review-reply — posts a public response onto a review.
			// The form only renders for the course owner/admin (review-card
			// partial gates on $GLOBALS['lrn_can_reply_reviews']), so the JS
			// no-ops when no reply form is present.
			wp_enqueue_script(
				'learnomy-review-reply',
				LEARNOMY_URL . 'assets/js/review-reply.js',
				array( 'learnomy-toast' ),
				LEARNOMY_VERSION,
				true
			);
			wp_localize_script(
				'learnomy-review-reply',
				'lrnReviewReply',
				array(
					'restUrl'  => esc_url_raw( rest_url( 'learnomy/v1/' ) ),
					'nonce'    => wp_create_nonce( 'wp_rest' ),
					'maxReply' => \Learnomy\Models\Review::MAX_REPLY_LENGTH,
					'i18n'     => array(
						'empty'   => __( 'Write a response before posting.', 'learnomy' ),
						'posting' => __( 'Posting…', 'learnomy' ),
						'posted'  => __( 'Response posted.', 'learnomy' ),
						'label'   => sprintf( /* translators: %s: singular label for an instructor (e.g. "Coach"). */ __( '%s response', 'learnomy' ), \Learnomy\instructor_label() ),
						'failed'  => __( 'Could not post your response. Please try again.', 'learnomy' ),
						'network' => __( 'Network error. Please try again.', 'learnomy' ),
						/* translators: %s: maximum number of characters allowed in a reply. */
						'tooLong' => __( 'Your response is too long. Please keep it under %s characters.', 'learnomy' ),
					),
				)
			);

			// Reviews "Load more" — pages the reviews section via REST so a
			// course with hundreds of reviews shows them all (the page rendered
			// only the first 10 with no way to reach the rest — Basecamp
			// #9981647943). Cards arrive as HTML rendered by the same
			// review-card partial the page uses; no card markup lives in JS.
			wp_enqueue_script(
				'learnomy-single-course-reviews',
				LEARNOMY_URL . 'assets/js/single-course-reviews.js',
				array( 'learnomy-toast' ),
				LEARNOMY_VERSION,
				true
			);
			wp_localize_script(
				'learnomy-single-course-reviews',
				'lrnCourseReviews',
				array(
					'restRoot' => esc_url_raw( rest_url( 'learnomy/v1/courses/' ) ),
					'nonce'    => wp_create_nonce( 'wp_rest' ),
					'i18n'     => array(
						'loadMore' => __( 'Load more reviews', 'learnomy' ),
						'loading'  => __( 'Loading…', 'learnomy' ),
						'failed'   => __( 'Could not load more reviews. Please try again.', 'learnomy' ),
					),
				)
			);
		}

		// Certificate clipboard share (.lrn-copy-link) + tab nav. The certificate
		// widget (templates/dashboard-widgets/student/certificates.php) renders on
		// BOTH the dashboard home and the dedicated My Certificates page, so this
		// handler must load on both — otherwise the "Share" button is a dead
		// control on /account/certificates/ (Basecamp #9976516774).
		if ( in_array( $route, array( 'student-dashboard', 'account-certificates' ), true ) ) {
			wp_enqueue_script(
				'learnomy-student-dashboard',
				LEARNOMY_URL . 'assets/js/student-dashboard.js',
				array( 'learnomy-prompt-modal', 'learnomy-toast' ),
				LEARNOMY_VERSION,
				true
			);
			wp_localize_script(
				'learnomy-student-dashboard',
				'lrnStudentDashboard',
				array(
					'i18n' => array(
						'copied'     => __( 'Copied.', 'learnomy' ),
						'copyManual' => __( 'Press Ctrl/Cmd+C to copy.', 'learnomy' ),
					),
				)
			);
		}

		// Course-completion partial — retake form REST submit. Used on
		// single-course and lesson routes where the completion partial may
		// render at the end of the curriculum.
		if ( in_array( $route, array( 'course', 'lesson' ), true ) ) {
			wp_enqueue_script(
				'learnomy-course-completion',
				LEARNOMY_URL . 'assets/js/course-completion.js',
				array( 'wp-api-fetch' ),
				LEARNOMY_VERSION,
				true
			);
			wp_localize_script(
				'learnomy-course-completion',
				'lrnCourseCompletion',
				array(
					'i18n' => array(
						'defaultErr' => __( 'Could not start retake. Please try again.', 'learnomy' ),
						'networkErr' => __( 'Network error. Please try again.', 'learnomy' ),
					),
				)
			);
		}

		// Quiz gate — cooldown countdown + access-code REST verifier.
		if ( in_array( $route, array( 'quiz', 'standalone-quiz' ), true ) ) {
			wp_enqueue_script(
				'learnomy-quiz-gate',
				LEARNOMY_URL . 'assets/js/quiz-gate.js',
				array( 'wp-api-fetch' ),
				LEARNOMY_VERSION,
				true
			);
			// Strings localised via wp_localize_script() inside
			// templates/partials/quiz-gate.php so they only ship when
			// the gate is actually rendered (not every quiz page).
		}

		// Certificate verify — IAPI store for the copy-link button. Depends
		// on the classic `learnomy-prompt-modal` script: the copy-link
		// fallback path opens window.lrnPrompt() with the URL pre-filled
		// when clipboard + execCommand both fail (replaces window.prompt).
		// Script-module deps can't reference classic-script handles, so we
		// enqueue the prompt-modal handle explicitly to make the contract
		// visible at the call site.
		if ( 'certificate-verify' === $route && function_exists( 'wp_enqueue_script_module' ) ) {
			wp_enqueue_script( 'learnomy-prompt-modal' );
			wp_enqueue_script_module(
				'learnomy-certificate-verify-view',
				LEARNOMY_URL . 'assets/js/certificate-verify-view.js',
				array( '@wordpress/interactivity' ),
				LEARNOMY_VERSION
			);
		}

		// Become instructor — IAPI store for form submission busy state.
		if ( 'become-instructor' === $route && function_exists( 'wp_enqueue_script_module' ) ) {
			wp_enqueue_script_module(
				'learnomy-instructor-application-view',
				LEARNOMY_URL . 'assets/js/instructor-application-view.js',
				array( '@wordpress/interactivity' ),
				LEARNOMY_VERSION
			);
		}

		// Lesson sidebar — Interactivity API module for collapse/expand.
		if ( 'lesson' === $route && function_exists( 'wp_enqueue_script_module' ) ) {
			wp_enqueue_script_module(
				'learnomy-lesson-sidebar',
				LEARNOMY_URL . 'assets/js/lesson-sidebar.js',
				array( '@wordpress/interactivity' ),
				LEARNOMY_VERSION
			);

			// Progress store — owns the Mark Complete / recordTime actions for
			// the lesson player. Registers the `learnomy/progress` Interactivity
			// store that the lesson template binds to via data-wp-interactive.
			// Depends on wp-api-fetch so wp.apiFetch is globally available.
			wp_enqueue_script( 'wp-api-fetch' );
			wp_enqueue_script_module(
				'learnomy-progress-view',
				LEARNOMY_URL . 'assets/js/progress-view.js',
				array( '@wordpress/interactivity' ),
				LEARNOMY_VERSION
			);
			// Translatable toast strings for the progress module (Interactivity
			// modules can't use wp.i18n directly — feed them via server state).
			if ( function_exists( 'wp_interactivity_state' ) ) {
				wp_interactivity_state(
					'learnomy/progress',
					array(
						// Same flag, same reason as the video reporter's
						// canSaveProgress — see build_lesson_video_data(). There are
						// TWO client writers of POST /lessons/{id}/progress: the
						// video reporter and this store's recordTime() heartbeat.
						// Gating only the first still left an instructor previewing
						// their own course with 403s from the second, so the flag has
						// to reach both. Verified in the browser: silencing only
						// lesson-video.js, the heartbeat still fired one 403.
						'canSaveProgress' => self::viewer_can_save_progress(),
						'i18n'            => array(
							/* translators: %s: singular Lesson label, renamable in Settings > Labels */
							'lessonMarkedComplete' => sprintf( __( '%s marked complete.', 'learnomy' ), \Learnomy\lesson_label() ),
							'topicMarkedComplete'  => sprintf(
								/* translators: %s: the Topic label, renamable in Settings > Labels. */
								__( '%s marked complete.', 'learnomy' ),
								\Learnomy\topic_label()
							),
							'savedOffline'         => __( 'Saved offline. Will sync when you reconnect.', 'learnomy' ),
							/* translators: %s: singular Lesson label, renamable in Settings > Labels */
							'lessonComplete'       => sprintf( __( '%s complete!', 'learnomy' ), \Learnomy\lesson_label() ),
						),
					)
				);
			}
		}

		// Lesson comments — Interactivity API module on lesson pages.
		if ( 'lesson' === $route && function_exists( 'wp_enqueue_script_module' ) ) {
			wp_enqueue_script_module(
				'learnomy-lesson-comments',
				LEARNOMY_URL . 'assets/js/lesson-comments.js',
				array( '@wordpress/interactivity' ),
				LEARNOMY_VERSION
			);
			// The comments strings ride in a JSON <script> element printed
			// inside the comments section (templates/lesson/partials/
			// player-content.php). wp_localize_script / wp_add_inline_script
			// don't reliably reach a script MODULE — the old classic data
			// handle was orphaned, leaving learnomyComments undefined, which
			// made the post-success handler throw and silently drop the comment
			// (so a retry posted duplicates). The DOM element is module-safe.
		}

		// Drip schedule UI — countdown chip + locked-lesson card. Loaded on
		// the single-course and single-lesson routes since both render the
		// curriculum partial (which emits drip lock chips). The script is a
		// classic vanilla bundle (no Interactivity boundary needed) so it
		// runs even when the page has no other reactive store.
		if ( in_array( $route, array( 'course', 'lesson' ), true ) ) {
			wp_enqueue_style(
				'learnomy-lesson-locked',
				LEARNOMY_URL . 'assets/css/lesson-locked.css',
				array( 'learnomy' ),
				LEARNOMY_VERSION
			);

			wp_enqueue_script(
				'learnomy-lesson-countdown',
				LEARNOMY_URL . 'assets/js/lesson-countdown.js',
				array(),
				LEARNOMY_VERSION,
				true
			);

			wp_localize_script(
				'learnomy-lesson-countdown',
				'lrnDripStrings',
				array(
					/* translators: {d} = days remaining, {h} = hours remaining */
					'daysHours'    => __( 'Unlocks in {d}d {h}h', 'learnomy' ),
					/* translators: {h} = hours remaining, {m} = minutes remaining */
					'hoursMinutes' => __( 'Unlocks in {h}h {m}m', 'learnomy' ),
					/* translators: {m} = minutes remaining */
					'minutes'      => __( 'Unlocks in {m}m', 'learnomy' ),
					'lessThanMin'  => __( 'Unlocks shortly', 'learnomy' ),
					'refresh'      => __( 'Refresh to unlock', 'learnomy' ),
				)
			);
		}

		// Report affordance — the small "Report" button on reviews, lesson
		// comments, and courses. Logged-in only (the endpoint is auth-gated), on
		// the two routes that host the reportable content. Depends on the shared
		// prompt + toast so the reason modal and confirmation toast are present.
		if ( is_user_logged_in() && in_array( $route, array( 'course', 'lesson' ), true ) ) {
			wp_enqueue_style(
				'learnomy-report',
				LEARNOMY_URL . 'assets/css/report.css',
				array( 'learnomy' ),
				LEARNOMY_VERSION
			);
			wp_enqueue_script(
				'learnomy-report',
				LEARNOMY_URL . 'assets/js/report.js',
				array( 'learnomy-prompt-modal', 'learnomy-toast' ),
				LEARNOMY_VERSION,
				true
			);
			wp_localize_script(
				'learnomy-report',
				'lrnReport',
				array(
					'restUrl' => esc_url_raw( rest_url( 'learnomy/v1/' ) ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
					'i18n'    => array(
						'title'       => __( 'Report this content', 'learnomy' ),
						'message'     => __( 'Tell us briefly why you are reporting this. A moderator will review it.', 'learnomy' ),
						'label'       => __( 'Reason (optional)', 'learnomy' ),
						'placeholder' => __( 'e.g. spam, offensive, off-topic', 'learnomy' ),
						'submit'      => __( 'Submit report', 'learnomy' ),
						'cancel'      => __( 'Cancel', 'learnomy' ),
						'reported'    => __( 'Reported', 'learnomy' ),
						'thanks'      => __( 'Thanks - your report has been sent to a moderator.', 'learnomy' ),
						'failed'      => __( 'Could not submit your report. Please try again.', 'learnomy' ),
					),
				)
			);
			wp_set_script_translations( 'learnomy-report', 'learnomy', LEARNOMY_DIR . 'languages' );
		}

		// Block affordance — the "Block" button beside a review/comment author
		// (course/lesson) AND the "Blocked members" unblock list on the account
		// page. Web twin of the app's block control; shares the confirm/toast
		// helpers. One script covers block + unblock.
		if ( is_user_logged_in() && in_array( $route, array( 'course', 'lesson', 'account-details' ), true ) ) {
			wp_enqueue_script(
				'learnomy-block-user',
				LEARNOMY_URL . 'assets/js/block-user.js',
				array( 'learnomy-confirm-modal', 'learnomy-toast' ),
				LEARNOMY_VERSION,
				true
			);
			wp_localize_script(
				'learnomy-block-user',
				'lrnBlock',
				array(
					'restUrl' => esc_url_raw( rest_url( 'learnomy/v1/' ) ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
					'i18n'    => array(
						'title'         => __( 'Block member', 'learnomy' ),
						/* translators: %s: the member's display name. */
						'message'       => __( 'Block %s? You will no longer see their comments or reviews. You can unblock them from your account.', 'learnomy' ),
						'thisMember'    => __( 'this member', 'learnomy' ),
						'confirm'       => __( 'Block', 'learnomy' ),
						'cancel'        => __( 'Cancel', 'learnomy' ),
						'blocked'       => __( 'Member blocked. You will no longer see their content.', 'learnomy' ),
						'failed'        => __( 'Could not block this member. Please try again.', 'learnomy' ),
						'unblocked'     => __( 'Member unblocked.', 'learnomy' ),
						'unblockFailed' => __( 'Could not unblock this member. Please try again.', 'learnomy' ),
					),
				)
			);
			wp_set_script_translations( 'learnomy-block-user', 'learnomy', LEARNOMY_DIR . 'languages' );
		}

		// Notifications bell — Interactivity API module on all Learnomy pages.
		if ( is_user_logged_in() && function_exists( 'wp_enqueue_script_module' ) ) {
			wp_enqueue_script_module(
				'learnomy-notifications',
				LEARNOMY_URL . 'assets/js/notifications.js',
				array( '@wordpress/interactivity' ),
				LEARNOMY_VERSION
			);

			// Inject the notification bell partial via wp_footer.
			add_action(
				'wp_footer',
				static function (): void {
					include \Learnomy\template_path( 'partials/notification-bell.php' );
				}
			);
		}

		// Plyr video player — ONLY on lessons that actually render a player.
		//
		// This used to be gated on the route alone, so every lesson shipped
		// plyr.css + plyr.min.js + lesson-video.js + a `learnomyLesson` payload
		// carrying the lesson's video_url — including text/pdf/download lessons
		// with no player at all. A lesson switched away from video kept a
		// non-empty video_url (the editor writes the generic media input back on
		// save), so the URL an author had removed from view was still published
		// in page source. Cost: a video player on every lesson of a text-only
		// course, and a front end that contradicts its own page source.
		//
		// `#lrn-player` is Plyr's only target and `lesson-video.js` returns
		// immediately without it, so the assets are needed exactly where the
		// media partial emits that element — templates/lesson/partials/media.php
		// :90 (video embed), :103 (video/html5), :119 (audio). Loom (:56)
		// renders its own iframe with no #lrn-player, and mediashield/live use
		// their own players; none of those load Plyr. build_lesson_video_data()
		// sets needsPlayer only for the three player-emitting branches.
		$lesson_video_data = 'lesson' === $route ? self::build_lesson_video_data() : array();

		if ( ! empty( $lesson_video_data['needsPlayer'] ) ) {
			wp_enqueue_style(
				'plyr',
				LEARNOMY_URL . 'assets/css/vendor/plyr.css',
				array(),
				'3.7.8'
			);

			wp_enqueue_script(
				'plyr',
				LEARNOMY_URL . 'assets/js/vendor/plyr.min.js',
				array(),
				'3.7.8',
				true
			);

			wp_enqueue_script(
				'learnomy-lesson-video',
				LEARNOMY_URL . 'assets/js/lesson-video.js',
				array( 'plyr' ),
				LEARNOMY_VERSION,
				true
			);

			// Localize lesson data for the video player JS.
			unset( $lesson_video_data['needsPlayer'] );
			wp_localize_script( 'learnomy-lesson-video', 'learnomyLesson', $lesson_video_data );
		}

		// Tabbed course list — the All / In Progress / Completed / Wishlist
		// tablist lives on the dedicated My Courses section (account-courses).
		// The dashboard home no longer carries it.
		if ( 'account-courses' === $route ) {
			wp_enqueue_script(
				'learnomy-dashboard-tabs',
				LEARNOMY_URL . 'assets/js/dashboard-tabs.js',
				array(),
				LEARNOMY_VERSION,
				true
			);

			// "Load more" — pages the active tab 40 cards at a time via REST so a
			// learner with hundreds of courses navigates them all on the same URL
			// (no slug change), instead of rendering every card up-front.
			wp_enqueue_script(
				'learnomy-account-courses',
				LEARNOMY_URL . 'assets/js/account-courses.js',
				array( 'wp-api-fetch', 'learnomy-toast' ),
				LEARNOMY_VERSION,
				true
			);
			wp_localize_script(
				'learnomy-account-courses',
				'lrnAccountCourses',
				array(
					'restUrl' => esc_url_raw( rest_url( 'learnomy/v1/enrollments/page' ) ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
					'i18n'    => array(
						'loadMore' => __( 'Load more', 'learnomy' ),
						'loading'  => __( 'Loading…', 'learnomy' ),
						'failed'   => sprintf( /* translators: %s: plural Course label */ __( 'Could not load more %s. Please try again.', 'learnomy' ), \Learnomy\course_label( true ) ),
					),
				)
			);
		}

		// My Courses widget — Prev/Next paging that swaps just the table body via
		// REST so the rest of the instructor dashboard never reloads.
		//
		// Both routes that render the widget, not just the dashboard. The pager
		// markup lives in dashboard-widgets/instructor/my-courses.php, which the
		// SECTION page (/instructor/courses/) renders too — and that is the page
		// an instructor with more than 25 courses actually uses. Enqueued on the
		// dashboard alone, the section page rendered a working-looking pager with
		// no handler bound: Next did nothing at all, because `lrnInstructorCourses`
		// was undefined and the script bails without it (Basecamp 10190366108).
		// A control that renders enabled and does nothing is worse than no control.
		if ( in_array( $route, array( 'instructor-dashboard', 'instructor-courses' ), true ) ) {
			wp_enqueue_script(
				'learnomy-instructor-courses',
				LEARNOMY_URL . 'assets/js/instructor-courses.js',
				array( 'learnomy-toast' ),
				LEARNOMY_VERSION,
				true
			);
			wp_localize_script(
				'learnomy-instructor-courses',
				'lrnInstructorCourses',
				array(
					'restUrl' => esc_url_raw( rest_url( 'learnomy/v1/courses/instructor-page' ) ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
					'i18n'    => array(
						/* translators: 1: current page, 2: total pages */
						'pageOf' => __( 'Page %1$d of %2$d', 'learnomy' ),
						'failed' => __( 'Could not load that page. Please try again.', 'learnomy' ),
					),
				)
			);
		}

		// Confetti and celebrations — loaded on student dashboard.
		if ( 'student-dashboard' === $route ) {
			wp_enqueue_script(
				'confetti',
				LEARNOMY_URL . 'assets/js/vendor/confetti.min.js',
				array(),
				'1.9.3',
				true
			);

			wp_enqueue_script(
				'learnomy-celebrations',
				LEARNOMY_URL . 'assets/js/celebrations.js',
				array( 'confetti' ),
				LEARNOMY_VERSION,
				true
			);
		}

		// Wishlist: Interactivity API module on catalog, category, dashboard, and
		// single-course pages. The single-course sidebar now renders the same
		// heart control as the catalog card, so the toggle store must load there
		// too or the button would render inert.
		// Wishlist assets are enqueued through the single shared helper so the
		// route surfaces and the block/shortcode/instructor surfaces all load
		// the identical module + dependency set (no duplicated enqueue logic).
		// The helper is login-gated, matching course-card.php which only renders
		// the heart for logged-in users.
		$wishlist_routes = array( 'catalog', 'category', 'student-dashboard', 'account-courses', 'course', 'instructor-profile' );
		if ( in_array( $route, $wishlist_routes, true ) ) {
			\Learnomy\enqueue_wishlist_assets();
		}

		// PWA is opt-out-able: when disabled (learnomy_settings['enable_pwa'] =
		// false or the learnomy_enable_pwa filter), skip the service-worker
		// registration, the offline banner script, and the manifest head tags.
		if ( ! pwa_enabled() ) {
			return;
		}

		// Service Worker registration — loaded on every Learnomy page.
		// Small file that registers the SW and sets up online/offline handling.
		wp_enqueue_script(
			'learnomy-sw-register',
			LEARNOMY_URL . 'assets/js/sw-register.js',
			array(),
			LEARNOMY_VERSION,
			true
		);

		wp_localize_script(
			'learnomy-sw-register',
			'learnomySW',
			array(
				'i18n' => array(
					'offline'    => __( 'You are offline. Changes will sync when you reconnect.', 'learnomy' ),
					'backOnline' => __( 'Back online. Syncing progress.', 'learnomy' ),
				),
			)
		);

		// PWA: web app manifest and theme-color meta tag.
		self::add_pwa_head_tags();
	}

	/**
	 * Output the PWA manifest link and theme-color meta tag in <head>.
	 *
	 * Called once per page load from enqueue_assets(). Uses a one-shot
	 * wp_head hook so the tags appear in the right place regardless of
	 * when enqueue_assets() runs relative to wp_head.
	 */
	private static function add_pwa_head_tags(): void {
		static $added = false;
		if ( $added ) {
			return;
		}
		$added = true;

		add_action(
			'wp_head',
			static function (): void {
				// Served dynamically by the Router (Routes 'pwa-manifest') so icon
				// paths follow plugins_url and start_url does not 301. Same-origin
				// URL keeps the PWA installable.
				$manifest_url = home_url( '/learnomy-manifest.json' );
				echo '<link rel="manifest" href="' . esc_url( $manifest_url ) . '">' . "\n";
				echo '<meta name="theme-color" content="#2383e2">' . "\n";
				// Standard first (browsers now warn that the apple-* name is
				// deprecated); keep the apple-* one for older iOS Safari that still
				// only honours it. Both = no console warning + full coverage.
				echo '<meta name="mobile-web-app-capable" content="yes">' . "\n";
				echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
				echo '<meta name="apple-mobile-web-app-status-bar-style" content="default">' . "\n";
			},
			1
		);
	}

	/**
	 * Enqueue CSS, JS, and third-party SDKs for the /enroll/ checkout page.
	 *
	 * Vanilla JS by architecture decision — the checkout form needs direct
	 * control over Stripe Elements and PayPal Buttons, which don't integrate
	 * with the Interactivity API.
	 */
	private static function enqueue_checkout_assets(): void {
		$settings = get_option( 'learnomy_settings', array() );
		// Delegated to Payment_Settings so the same answer the checkout JS
		// gets matches what the view-data and adapters compute. See note in
		// Checkout_View_Data::resolve() for the 2026-05-13 schema migration
		// that retired the flat `stripe_secret_key` / `paypal_client_id`
		// keys this method used to read.
		$stripe_enabled = \Learnomy\Services\Payment_Settings::stripe_enabled();
		$paypal_enabled = \Learnomy\Services\Payment_Settings::paypal_enabled();
		$wc_active      = class_exists( 'WooCommerce' ) && ! empty( $settings['enable_woocommerce'] );
		$test_enabled   = ! empty( $settings['enable_test_gateway'] );

		// Shared with the account Billing page; see billing-fields.css.
		wp_enqueue_style(
			'learnomy-billing-fields',
			LEARNOMY_URL . 'assets/css/billing-fields.css',
			array( 'learnomy' ),
			LEARNOMY_VERSION
		);
		wp_enqueue_style(
			'learnomy-checkout',
			LEARNOMY_URL . 'assets/css/checkout.css',
			array( 'learnomy' ),
			LEARNOMY_VERSION
		);

		$js_deps = array();

		// Stripe.js — loaded from CDN only when Stripe gateway is configured.
		if ( $stripe_enabled ) {
			wp_register_script( 'stripe-js', 'https://js.stripe.com/v3/', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
			$js_deps[] = 'stripe-js';
		}

		// PayPal SDK — not loaded. The former Smart-Buttons approach required the
		// PayPal JS SDK so that actions.order.create() could build the order
		// client-side, but that path bypassed PayPal_Adapter::create_checkout()
		// and left custom_id unset (H4 ship-blocker). The new server-driven flow
		// POSTs to /learnomy/v1/checkout and redirects to PayPal's hosted
		// checkout page, so the JS SDK is no longer needed.

		wp_enqueue_script(
			'learnomy-checkout',
			LEARNOMY_URL . 'assets/js/checkout.js',
			$js_deps,
			LEARNOMY_VERSION,
			true
		);

		// Build the localized data the JS needs to operate.
		$checkout_data = self::build_checkout_data( $settings, $stripe_enabled, $paypal_enabled, $wc_active, $test_enabled );

		wp_localize_script( 'learnomy-checkout', 'learnomyCheckout', $checkout_data );
	}

	/**
	 * Assemble the localized object for the checkout JS.
	 *
	 * Reads the course or plan from query params and returns pricing, gateway
	 * keys (public only — never the secret), and REST endpoints.
	 *
	 * @param array $settings       Plugin settings.
	 * @param bool  $stripe_enabled Whether Stripe is configured.
	 * @param bool  $paypal_enabled Whether PayPal is configured.
	 * @param bool  $wc_active      Whether WooCommerce adapter should handle checkout.
	 * @param bool  $test_enabled   Whether the test gateway is active.
	 * @return array
	 */
	private static function build_checkout_data( array $settings, bool $stripe_enabled, bool $paypal_enabled, bool $wc_active, bool $test_enabled = false ): array {
		$course_id = absint( $_GET['course_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$plan_id   = absint( $_GET['plan_id'] ?? 0 );   // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		// The billing cycle the buyer picked on the pricing page. It has to reach
		// the JS, because the JS is what POSTs to /checkout/subscribe — a cycle
		// that stops at the template is a cycle the charge never hears about.
		$billing_cycle = sanitize_key( (string) ( $_GET['billing'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$price      = 0;
		$sale_price = 0;
		$currency   = strtoupper( $settings['currency'] ?? 'USD' );
		$is_free    = true;
		$item_title = '';
		$thumbnail  = '';
		$instructor = '';

		if ( $course_id ) {
			$course = \Learnomy\Models\Course::find( $course_id );
			if ( $course ) {
				// One resolver, and this caller passes no columns.
				//
				// This block used to read `price`/`sale_price` straight off the row
				// and hand them to is_purchasable_free(). A RECURRING course keeps
				// its price in `recurring_amount` and leaves `price` at 0, so a live
				// $19/month course came out is_free = true here - while
				// Checkout_View_Data, which does read recurring_amount, said the
				// opposite about the same course on the same page render
				// (Basecamp 10198954861).
				//
				// price_view() also keeps the closed/invite-only distinction that
				// comment below was protecting: such a course carries no on-site
				// price BY CONSTRUCTION and must never read as "free to enrol", or
				// the checkout offers "Enroll for Free" on a course nobody may
				// enrol themselves into.
				$lrn_price  = \Learnomy\Models\Course::price_view( $course );
				$price      = $lrn_price['amount'];
				$sale_price = 0.0;
				$is_free    = $lrn_price['is_free'];
				$item_title = $course->title ?? '';
				$thumbnail  = $course->thumbnail_url ?? '';

				$author = $course->author_id ? get_userdata( (int) $course->author_id ) : null;
				if ( $author ) {
					$instructor = $author->display_name;
				}
			}
		} elseif ( $plan_id ) {
			$plan = \Learnomy\Models\MembershipPlan::find( $plan_id );
			if ( $plan ) {
				// Same resolver as the page render and the charge.
				$terms         = \Learnomy\Models\MembershipPlan::price_for_cycle( $plan, $billing_cycle );
				$billing_cycle = (string) $terms['cycle'];
				$price         = (float) $terms['amount'];
				$is_free       = ( $price <= 0 );
				$item_title    = $plan->title ?? '';
				$thumbnail     = $plan->thumbnail_url ?? '';
				$currency      = strtoupper( \Learnomy\charge_currency( $plan ) );
			}
		}

		// Uniform tax (level-1 compliance) — shown in the order summary and
		// charged on the Stripe Payment Element. Per-region tax lives in Woo.
		$tax_enabled = ! empty( $settings['tax_enabled'] );
		$tax_rate    = $tax_enabled ? max( 0.0, min( 100.0, (float) ( $settings['tax_rate'] ?? 0 ) ) ) : 0.0;
		$tax_label   = ( ! empty( $settings['tax_label'] ) ) ? (string) $settings['tax_label'] : __( 'Tax', 'learnomy' );

		// Where Stripe sends the buyer after Payment Element confirms the card.
		$return_ctx = $course_id ? array( 'course_id' => $course_id ) : array( 'plan_id' => $plan_id );
		$return_url = \Learnomy\API\Checkout_Controller::thank_you_url( 'stripe', $return_ctx );

		// Prefill the Stripe Address Element from the buyer's WP account so the
		// name + billing address are pre-populated (and present — Indian Stripe
		// accounts require name + address on export transactions). Country
		// defaults to US when the account has none on file.
		$buyer       = wp_get_current_user();
		$buyer_name  = ( $buyer && $buyer->exists() ) ? trim( (string) $buyer->display_name ) : '';
		$buyer_email = ( $buyer && $buyer->exists() ) ? (string) $buyer->user_email : '';
		// Default billing country for the Stripe Address Element. The buyer
		// confirms/changes it in the form; filterable so a site can set its own
		// default (e.g. its primary market).
		$buyer_ctry = strtoupper( (string) apply_filters( 'learnomy_checkout_default_country', 'US' ) );
		if ( strlen( $buyer_ctry ) !== 2 ) {
			$buyer_ctry = 'US';
		}

		return array(
			'restUrl'              => esc_url_raw( rest_url( 'learnomy/v1/' ) ),
			'nonce'                => wp_create_nonce( 'wp_rest' ),
			'courseId'             => $course_id,
			'planId'               => $plan_id,
			'billingCycle'         => $billing_cycle,
			'stripeKey'            => $stripe_enabled ? \Learnomy\Services\Payment_Settings::stripe_publishable() : '',
			'paypalClientId'       => $paypal_enabled ? \Learnomy\Services\Payment_Settings::paypal_client_id() : '',
			'stripeEnabled'        => $stripe_enabled,
			'paypalEnabled'        => $paypal_enabled,
			'returnUrl'            => esc_url_raw( $return_url ),
			'buyerName'            => $buyer_name,
			'buyerEmail'           => $buyer_email,
			'buyerCountry'         => $buyer_ctry,
			'taxEnabled'           => $tax_enabled && $tax_rate > 0,
			'taxRate'              => $tax_rate,
			'taxLabel'             => $tax_label,
			// Gateways that run their own tax engine and so receive our NET
			// price (WooCommerce today; any future EDD / SureCart adapter).
			// Asked of the adapters rather than hardcoded here, so a gateway
			// that declares handles_tax() is reflected on this screen without
			// anyone remembering to update a list.
			'taxDelegatedGateways' => self::tax_delegated_gateways(),
			'price'                => $price,
			'salePrice'            => $sale_price,
			'currency'             => $currency,
			'isFreeCourse'         => $is_free,
			'woocommerceActive'    => $wc_active,
			// Where a member actually enters billing details. The WooCommerce
			// hand-off has no billing form on the page, so a refusal for missing
			// details needs somewhere to send them.
			'billingUrl'           => \Learnomy\route_url( 'account-billing' ),
			'woocommerceCartUrl'   => $wc_active ? esc_url( wc_get_cart_url() ) : '',
			'itemTitle'            => $item_title,
			'thumbnail'            => $thumbnail,
			'instructor'           => $instructor,
			'myCoursesUrl'         => esc_url( \Learnomy\route_url( 'student-dashboard' ) ),
			'testEnabled'          => $test_enabled,
			// JS-side strings — translated here so checkout.js stays i18n-clean.
			'i18n'                 => array(
				'enterCouponCode'      => __( 'Please enter a coupon code.', 'learnomy' ),
				'couponApplied'        => __( 'Coupon applied.', 'learnomy' ),
				'couponRemoved'        => __( 'Coupon removed.', 'learnomy' ),
				'invalidCoupon'        => __( 'Invalid coupon.', 'learnomy' ),
				'couponValidateFailed' => __( 'Could not validate coupon. Please try again.', 'learnomy' ),
				'enrollmentFailed'     => __( 'Enrollment failed. Please try again.', 'learnomy' ),
				'enrolling'            => __( 'Enrolling…', 'learnomy' ),
				'wcRedirecting'        => __( 'Redirecting to secure checkout…', 'learnomy' ),
				'wcRedirectFailed'     => __( 'Could not start the payment redirect. Please try again.', 'learnomy' ),
				// The WooCommerce hand-off carries no billing form of its own, so
				// a refusal for missing billing details has to say where to go.
				// Naming the error without offering the fix is still a dead end.
				'addBillingDetails'    => __( 'Add billing details', 'learnomy' ),
				'stripeNotInit'        => __( 'Payment processing is not available. Please refresh the page.', 'learnomy' ),
				'testPaymentFailed'    => __( 'Test payment failed. Please try again.', 'learnomy' ),
				'paymentFailed'        => __( 'Payment failed. Please check your card details and try again.', 'learnomy' ),
				'payWithPayPal'        => __( 'Pay with PayPal', 'learnomy' ),
				'paypalError'          => __( 'PayPal encountered an error. Please try again.', 'learnomy' ),
				'requestFailed'        => __( 'Request failed. Please try again.', 'learnomy' ),
				'billingRequired'      => __( 'Add your billing details to continue.', 'learnomy' ),
			),
		);
	}

	/**
	 * Ids of active gateways that apply tax themselves.
	 *
	 * A store gateway (WooCommerce, and any future EDD / SureCart / FluentCart
	 * adapter) runs its own tax engine, so it is handed our NET price and adds
	 * tax at its own checkout. The order summary must not quote our rate for
	 * those, or it names a figure the buyer is never charged and then shows them
	 * a different total one screen later.
	 *
	 * Read from the adapters via the same `handles_tax()` declaration the
	 * pricing seam uses, so this screen cannot disagree with what is charged.
	 *
	 * @return string[] Gateway ids.
	 */
	private static function tax_delegated_gateways(): array {
		$ids = array();

		foreach ( \Learnomy\Adapters\Adapter_Registry::get_active() as $id => $adapter ) {
			if ( method_exists( $adapter, 'handles_tax' ) && $adapter->handles_tax() ) {
				$ids[] = (string) $id;
			}
		}

		return $ids;
	}

	/**
	 * Build the localized data the lesson video JS needs.
	 *
	 * Reads lesson and course from the current query vars so this method
	 * can be called from enqueue_assets() without parameters.
	 *
	 * @return array
	 */
	/**
	 * May the CURRENT viewer's progress on the current lesson page be saved?
	 *
	 * Two bugs met here, pointing in opposite directions (Basecamp 10134676326):
	 *
	 *   - An instructor previewing their own course is never auto-enrolled, so
	 *     `access_course` refuses `POST /lessons/{id}/progress` and the client fired
	 *     8+ 403s per preview. Nothing looked broken on screen, which is why it
	 *     survived: the video played fine and only the console showed it.
	 *   - An ADMIN previewing DOES pass `access_course`, so their preview silently
	 *     WROTE progress rows — while the preview bar above the player promises
	 *     "Student data is not affected". Verified: `POST /lessons/5594/progress`
	 *     returned 200 and stored `video_watch_pct = 42` on a course the admin was
	 *     not enrolled in.
	 *
	 * So the fix is NOT the card's suggested "give the author `access_course`" —
	 * that would spread the admin's wrong behaviour to instructors and start
	 * writing author progress rows onto their own courses. A preview writes
	 * nothing, for anybody, which is what our own copy already claims.
	 *
	 * Progress belongs to an enrolled student, so enrollment is the whole test.
	 * Capability is deliberately NOT consulted: an editor previewing gets `false`.
	 *
	 * Read by BOTH client writers — the video reporter (`canSaveProgress` in
	 * `build_lesson_video_data()`) and the `learnomy/progress` store's
	 * `recordTime()` heartbeat. Gating only one leaves the other 403ing.
	 *
	 * NOTE for anyone changing the type: `wp_localize_script` stringifies, so
	 * `false` reaches JS as `''` (falsy) and `true` as `'1'`. Returning an INT
	 * would send `'0'`, which is TRUTHY in JS and would silently re-enable the
	 * writes. Keep it a real bool.
	 */
	private static function viewer_can_save_progress(): bool {
		$course_slug = sanitize_text_field( get_query_var( 'learnomy_slug', '' ) );
		if ( '' === $course_slug ) {
			return false;
		}

		$course = \Learnomy\Models\Course::find_by_slug( $course_slug );
		if ( ! $course ) {
			return false;
		}

		return \Learnomy\Models\Enrollment::is_enrolled( get_current_user_id(), (int) $course->id );
	}

	private static function build_lesson_video_data(): array {
		$lesson_slug = sanitize_text_field( get_query_var( 'learnomy_lesson_slug', '' ) );
		$course_slug = sanitize_text_field( get_query_var( 'learnomy_slug', '' ) );

		$lesson_id         = 0;
		$require_watch_pct = 0;
		$video_url         = '';
		$video_provider    = '';
		$video_embed_id    = '';
		$video_embed_url   = '';
		$needs_player      = false;
		// Declared out here because the canSaveProgress check below needs it, and
		// it is only assigned inside the branch.
		$course = null;

		if ( $lesson_slug ) {
			// Resolve course first, then scope the lesson lookup to it (F-02.4).
			$course = $course_slug
				? \Learnomy\Models\Course::find_by_slug( $course_slug )
				: null;
			$lesson = $course
				? \Learnomy\Models\Lesson::find_by_course_and_slug( (int) $course->id, $lesson_slug )
				: null;
			if ( $lesson ) {
				$lesson_id    = (int) $lesson->id;
				$content_type = (string) ( $lesson->content_type ?? 'text' );
				$raw_url      = (string) ( $lesson->video_url ?? '' );

				// Only video/audio lessons with a source can possibly render a
				// player. A text/pdf/download lesson carrying a stale video_url
				// (the editor keeps the generic media input on save) resolves
				// nothing here, so its URL never reaches the page — the leak the
				// route-only gate used to publish.
				if ( in_array( $content_type, array( 'video', 'audio' ), true ) && '' !== $raw_url ) {
					// Normalize via the shared helper so the JS sees the same
					// provider/embed the PHP template renders (embed / shorts /
					// m.youtube / player.vimeo all parsed one way). Basecamp
					// #9925147314.
					$resolved        = \Learnomy\resolve_video_url( $raw_url );
					$video_provider  = (string) $resolved['provider'];
					$video_embed_id  = (string) $resolved['embed_id'];
					$video_embed_url = (string) $resolved['embed_url'];

					// Plyr loads only for the providers Plyr actually plays:
					// native audio, native (html5) video, and the youtube/vimeo
					// iframes the media partial wraps with data-plyr-provider.
					// Everything else — Loom today, any future embed tomorrow —
					// is just iframe content the browser plays on its own, so it
					// gets no Plyr. Framed as the positive set rather than a
					// per-provider carve-out, so a new iframe provider is
					// correctly excluded by default instead of silently dragging
					// Plyr onto a page it can't drive. youtube/vimeo need a
					// resolved embed to render; html5 plays the raw url.
					if ( 'audio' === $content_type ) {
						$needs_player = true;
					} elseif ( 'video' === $content_type ) {
						$needs_player = 'html5' === $video_provider
							|| ( in_array( $video_provider, array( 'youtube', 'vimeo' ), true ) && '' !== $video_embed_url );
					}

					if ( $needs_player ) {
						$video_url = $raw_url;
					} else {
						// Iframe content (Loom) or an unparseable URL: the media
						// partial still renders it, but no Plyr payload ships.
						$video_provider  = '';
						$video_embed_id  = '';
						$video_embed_url = '';
					}
				}

				// Deliberately defaults to 0 and is NOT seeded from
				// $lesson->min_video_watch_pct. The watch gate is owned by the
				// Interactivity store: the server builds a mode-aware
				// `requirements` array (class-progress-service.php:204-231 —
				// min_video_watch_pct is active only for auto_video /
				// auto_combined, never for manual), progress-view.js exposes it
				// as state.completeDisabled, and completion.php binds the button
				// with data-wp-bind--disabled. Seeding a value here would let
				// lesson-video.js re-implement that gate imperatively and
				// mode-blind, disabling Mark Complete on manual lessons that
				// merely carry a stale column value. See
				// plan/2026-07-30-gates-that-do-not-gate.md (C2) for the full
				// trace and the intended single-owner fix.
				$require_watch_pct = (int) apply_filters(
					'learnomy_lesson_require_watch_percent',
					0,
					$lesson,
					$course
				);
			}
		}

		return array(
			// Consumed by enqueue_assets() to decide whether to load Plyr at
			// all, then stripped before localizing — it is not JS-facing data.
			'needsPlayer'         => $needs_player,
			'lessonId'            => $lesson_id,
			'canSaveProgress'     => self::viewer_can_save_progress(),
			'requireWatchPercent' => $require_watch_pct,
			'videoUrl'            => $video_url,
			'videoProvider'       => $video_provider,
			'videoEmbedId'        => $video_embed_id,
			'videoEmbedUrl'       => $video_embed_url,
			'restUrl'             => esc_url_raw( rest_url( 'learnomy/v1/' ) ),
			'nonce'               => wp_create_nonce( 'wp_rest' ),
			'i18n'                => array(
				'watchComplete' => sprintf( /* translators: %s: singular Lesson label */ __( 'You can now mark this %s as complete.', 'learnomy' ), \Learnomy\lesson_label() ),
			),
		);
	}

	/**
	 * Set a sensible <title> for the current page via wp_title filter.
	 *
	 * @param string $route Current route name.
	 * @param array  $data  Route data.
	 */
	private static function set_page_title( string $route, array $data ): void {
		$title = self::build_title( $route, $data );

		if ( $title ) {
			add_filter(
				'wp_title',
				static function () use ( $title ): string {
					return $title;
				},
				20
			);

			// Also covers themes using document_title_parts (Twenty* and block themes).
			add_filter(
				'document_title_parts',
				static function ( array $parts ) use ( $title ): array {
					$parts['title'] = $title;
					return $parts;
				},
				20
			);
		}
	}

	/**
	 * Build a human-readable title string for a given route.
	 *
	 * @param string $route Route name.
	 * @param array  $data  Route data.
	 * @return string
	 */
	public static function build_title( string $route, array $data ): string {
		switch ( $route ) {
			case 'catalog':
				// /courses/?cat=design is the SAME listing as
				// /courses/category/design/ — the grid filters, so the name
				// above it has to as well. It used to read "All Courses" over
				// twelve design courses, and the canonical, description and
				// ItemList agreed with the title rather than the page
				// (Basecamp 10134139480). One resolver decides which category
				// a listing is about; both routes ask it.
				$catalog_category = \Learnomy\current_category_slug();
				if ( '' !== $catalog_category ) {
					return self::build_title( 'category', array( 'slug' => $catalog_category ) );
				}
				/* translators: %s: plural Course label, renamable in Settings > Labels (e.g. "Courses", "Classes") */
				return sprintf( __( 'All %s', 'learnomy' ), \Learnomy\course_label( true ) );

			case 'category':
				// Use the stored category name rather than deriving from the URL slug.
				$slug     = (string) ( $data['slug'] ?? \Learnomy\current_category_slug() );
				$category = $slug ? \Learnomy\Models\Category::find_by_slug( $slug ) : null;
				$label    = $category ? (string) $category->name : '';
				return $label
					/* translators: 1: category name, 2: plural Course label, renamable in Settings > Labels */
					? sprintf( __( '%1$s %2$s', 'learnomy' ), $label, \Learnomy\course_label( true ) )
					/* translators: 1: singular Course label, 2: singular Category label, both renamable in Settings > Labels */
					: sprintf( __( '%1$s %2$s', 'learnomy' ), \Learnomy\course_label(), \Learnomy\category_label() );

			case 'course':
				// Load the actual course title from the DB — slug-derived strings
				// produce wrong casing and include trailing "Lrn Demo" artifacts.
				$slug   = $data['slug'] ?? '';
				$course = $slug ? \Learnomy\Models\Course::find_by_slug( $slug ) : null;
				if ( ! $course ) {
					return \Learnomy\course_label();
				}
				// A per-course SEO title overrides the display title here as well
				// as in og:title — a site owner setting one means it to be the
				// title in search, and the <title> tag is what search reads.
				$seo_title = trim( (string) ( $course->seo_title ?? '' ) );
				return '' !== $seo_title ? $seo_title : (string) $course->title;

			case 'lesson':
				// Load the lesson title from the DB — the lesson slug is a URL-safe
				// derivative and does not round-trip cleanly back to the display name.
				// Scope to the course in the URL so cross-course slug collisions
				// don't resolve to the wrong lesson title (F-02.4).
				$lesson_slug        = $data['lesson_slug'] ?? '';
				$lesson_course_slug = $data['slug'] ?? '';
				$lesson_course      = $lesson_course_slug
					? \Learnomy\Models\Course::find_by_slug( $lesson_course_slug )
					: null;
				$lesson             = ( $lesson_slug && $lesson_course )
					? \Learnomy\Models\Lesson::find_by_course_and_slug( (int) $lesson_course->id, $lesson_slug )
					: null;
				return $lesson ? (string) $lesson->title : \Learnomy\lesson_label();

			case 'quiz':
			case 'standalone-quiz':
				// Two route shapes — scope course-attached lookups by course
				// (F-03.X), keep standalone slug-only because Quiz::create()
				// enforces standalone-slug uniqueness at insert time.
				$quiz_qslug       = (string) ( $data['quiz_slug'] ?? '' );
				$quiz_course_slug = (string) ( $data['slug'] ?? '' );
				if ( '' !== $quiz_qslug ) {
					$quiz_course = $quiz_course_slug
						? \Learnomy\Models\Course::find_by_slug( $quiz_course_slug )
						: null;
					$quiz        = $quiz_course
						? \Learnomy\Models\Quiz::find_by_course_and_slug( (int) $quiz_course->id, $quiz_qslug )
						: null;
				} else {
					$quiz = $quiz_course_slug
						? \Learnomy\Models\Quiz::find_standalone_by_slug( $quiz_course_slug )
						: null;
				}
				return $quiz ? (string) $quiz->title : \Learnomy\quiz_label();

			case 'student-dashboard':
				return __( 'Dashboard', 'learnomy' );

			case 'account-courses':
				/* translators: %s: plural Course label, renamable in Settings > Labels */
				return sprintf( __( 'My %s', 'learnomy' ), \Learnomy\course_label( true ) );

			case 'instructor-dashboard':
				return sprintf( /* translators: %s: singular label for an instructor (e.g. "Coach"). */ __( '%s Dashboard', 'learnomy' ), \Learnomy\instructor_label() );

			case 'certificate-verify':
				return __( 'Verify Certificate', 'learnomy' );

			case 'membership-pricing':
				return __( 'Membership Plans', 'learnomy' );

			case 'membership-manage':
				return __( 'Manage Membership', 'learnomy' );

			case 'become-instructor':
				return sprintf( /* translators: %s: singular label for an instructor (e.g. "Coach"). */ __( 'Apply as %s', 'learnomy' ), \Learnomy\instructor_label() );

			case 'instructor-profile':
				// Resolve the instructor's display name from WP users (login first,
				// nicename fallback) rather than mangling the URL slug.
				$slug       = $data['slug'] ?? '';
				$instructor = $slug ? get_user_by( 'login', $slug ) : false;
				if ( ! $instructor && $slug ) {
					$instructor = get_user_by( 'slug', $slug );
				}
				// A name in <head> is as public as a name in the body. This switch
				// used to answer "is there a WP user by this slug?", so it named
				// every registered member — students included — independently of
				// what the body decided. The not-found seam in render() means a
				// gated slug never reaches here today, but build_title() is public
				// and generic_route_meta() calls it, so the same predicate as the
				// body is applied here rather than trusting one caller's ordering.
				return ( $instructor && \Learnomy\Models\Instructor::has_public_profile( (int) $instructor->ID ) )
					? (string) $instructor->display_name
					: \Learnomy\instructor_label();

			case 'enroll':
				return __( 'Checkout', 'learnomy' );

			case 'enroll-thank-you':
				return __( 'Thank you for your purchase', 'learnomy' );

			case 'enroll-cancelled':
				return __( 'Purchase cancelled', 'learnomy' );

			case 'register-student':
				return sprintf( /* translators: %s: singular label for a student (e.g. "Learner"). */ __( '%s registration', 'learnomy' ), \Learnomy\student_label() );

			case 'register-instructor':
				return sprintf( /* translators: %s: singular label for an instructor (e.g. "Coach"). */ __( '%s application', 'learnomy' ), \Learnomy\instructor_label() );

			case 'offline':
				return __( 'You Are Offline', 'learnomy' );

			// Member account sections (PAGETITLE-01). "My …" for the member's
			// own collections, matching account-courses ("My Courses").
			case 'my-spaces':
				return ( isset( \Learnomy\entity_label_defaults()['space'] ) ? sprintf( /* translators: %s: plural label for a space (e.g. "Teams"). */ __( 'My %s', 'learnomy' ), \Learnomy\entity_label( 'space', 'plural' ) ) : __( 'My Learning Spaces', 'learnomy' ) );

			case 'account-certificates':
				return __( 'My Certificates', 'learnomy' );

			case 'account-quiz-results':
				return sprintf( /* translators: %s: singular Quiz label */ __( 'My %s Attempts', 'learnomy' ), \Learnomy\quiz_label() );

			case 'account-billing':
				return __( 'Billing', 'learnomy' );

			case 'account-billing-receipt':
				return __( 'Receipt', 'learnomy' );

			case 'account-details':
				return __( 'Account Details', 'learnomy' );

			case 'account-instructor-profile':
				return sprintf( /* translators: %s: singular label for an instructor (e.g. "Coach"). */ __( '%s Profile', 'learnomy' ), \Learnomy\instructor_label() );

			default:
				/**
				 * Filter the <title> for a route with no built-in title — the
				 * extension seam Pro uses to name its own member routes (notes,
				 * learning paths, cohorts, assignments) without editing this
				 * switch. Also lets a site owner override any untitled route.
				 *
				 * @since 1.5.1
				 *
				 * @param string $title Empty string (no built-in title).
				 * @param string $route Route name.
				 * @param array  $data  Route data.
				 */
				$filtered = (string) apply_filters( 'learnomy_route_title', '', $route, $data );
				if ( '' !== $filtered ) {
					return $filtered;
				}

				// Safe default: derive a readable label from the route name so a
				// known Learnomy route never silently drops to the bare site
				// name (PAGETITLE-01). e.g. 'account-notes' -> 'Notes'.
				return self::derive_route_title( $route );
		}
	}

	/**
	 * Fallback <title> derived from a route name — strips the account/student
	 * prefix and title-cases the remainder. Last resort so a route with no
	 * explicit case and no filter still gets a sensible title.
	 */
	private static function derive_route_title( string $route ): string {
		$slug = (string) preg_replace( '/^(account|student|instructor)-/', '', $route );
		$slug = trim( str_replace( '-', ' ', $slug ) );
		return '' !== $slug ? ucwords( $slug ) : '';
	}
}
