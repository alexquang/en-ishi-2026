<?php
/**
 * Main plugin class.
 *
 * @package Learnomy
 */

namespace Learnomy;

defined( 'ABSPATH' ) || exit;

final class Learnomy {
	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->register_hooks();
	}

	private function register_hooks(): void {
		register_activation_hook( LEARNOMY_FILE, array( $this, 'activate' ) );
		register_deactivation_hook( LEARNOMY_FILE, array( $this, 'deactivate' ) );
		add_action( 'plugins_loaded', array( $this, 'init' ) );

		// Plugin-level theme.json for baseline typography, spacing, and colors.
		add_filter( 'wp_theme_json_data_default', array( $this, 'register_plugin_theme_json' ) );

		// Every Free model that holds a foreign id declares it once; the
		// registry turns those declarations into the cascade, the integrity
		// check and the repair pass. Registering here rather than by scanning
		// the models directory keeps the set explicit and testable — and makes
		// a model that forgets to declare visible as an absence in one list
		// rather than as silent orphans months later.
		add_filter(
			'learnomy_relation_models',
			static function ( array $models ): array {
				return array_merge(
					$models,
					array(
						\Learnomy\Models\Answer::class,
						\Learnomy\Models\Certificate::class,
						\Learnomy\Models\Commission::class,
						\Learnomy\Models\CouponUsage::class,
						\Learnomy\Models\CourseInstructor::class,
						\Learnomy\Models\EngagementMonthly::class,
						\Learnomy\Models\Enrollment::class,
						\Learnomy\Models\Lesson::class,
						\Learnomy\Models\Lesson_Attachment::class,
						\Learnomy\Models\Lesson_Unlock::class,
						\Learnomy\Models\MembershipPlan::class,
						\Learnomy\Models\MembershipPlanCourse::class,
						\Learnomy\Models\Plan_Provider::class,
						\Learnomy\Models\QuestionResponse::class,
						\Learnomy\Models\Quiz::class,
						\Learnomy\Models\QuizAttempt::class,
						\Learnomy\Models\QuizSlot::class,
						\Learnomy\Models\Review::class,
						\Learnomy\Models\Section::class,
						\Learnomy\Models\Subscription::class,
						\Learnomy\Models\Transaction::class,
					)
				);
			}
		);

		// Solo academy mode forces the commissions module off so its whole
		// (already module-gated) earnings / commission / withdrawal surface
		// disappears — without mutating the stored Modules option.
		add_filter(
			'learnomy_module_enabled',
			static function ( bool $enabled, string $slug ): bool {
				return ( 'commissions' === $slug && \Learnomy\solo_mode() ) ? false : $enabled;
			},
			10,
			2
		);

		/*
		 * Nav-menu adjustments hang off `wp_get_nav_menu_items`, NOT
		 * `wp_nav_menu_objects`.
		 *
		 * `wp_nav_menu_objects` fires in exactly one place in core —
		 * `wp_nav_menu()` — so it covers classic themes and nothing else. The
		 * block editor's `core/navigation` builds its items from
		 * `wp_get_nav_menu_items()` and never calls `wp_nav_menu()`, so on a
		 * BLOCK theme neither of these filters ran at all: solo mode still
		 * showed dead instructor links, and the register-student nonce was
		 * never stamped — which silently turned the grant off, restoring the
		 * dead end #9924598683 fixed. Invisible to us because BuddyX and Reign
		 * are classic themes; broken for the majority of installs, which are
		 * not on our themes (Basecamp 10171572789).
		 *
		 * `wp_get_nav_menu_items` is the shared seam: core routes BOTH paths
		 * through it (`nav-menu-template.php` for classic, `blocks/navigation.php`
		 * for the block), so one standard filter covers every theme instead of
		 * a per-theme-type special case.
		 */
		add_filter( 'wp_get_nav_menu_items', array( $this, 'nonce_register_student_menu_item' ), 11, 3 );

		// Pure students never see the WP admin bar on the front end — an LMS
		// member is a customer, not a WordPress editor, and the bar reads as
		// leaked backend chrome (Basecamp 10132224001). Scope: only users who
		// hold the lrn_student role AND cannot edit content; instructors,
		// admins, and non-Learnomy roles are untouched. Site owners who want
		// the bar back flip the filter.
		add_filter(
			'show_admin_bar',
			static function ( bool $show ): bool {
				if ( ! $show || ! is_user_logged_in() ) {
					return $show;
				}
				$user            = wp_get_current_user();
				$is_pure_student = in_array( 'lrn_student', (array) $user->roles, true )
					&& ! user_can( $user, 'edit_posts' )
					&& ! user_can( $user, 'manage_options' )
					&& ! in_array( 'lrn_instructor', (array) $user->roles, true );
				if ( ! $is_pure_student ) {
					return $show;
				}
				/**
				 * Filter whether the WP admin bar is hidden for pure students.
				 *
				 * @param bool $hide Default true — hide the bar.
				 */
				return apply_filters( 'learnomy_hide_admin_bar_for_students', true ) ? false : $show;
			}
		);

		// Fully-private academy (Settings → General → "Require login for all
		// course content"): add the public content routes — catalog, category,
		// single course, lesson, quiz — to the protected set so logged-out
		// visitors are redirected to the login page. Reuses the existing
		// `learnomy_protected_routes` seam; no new redirect mechanism (Zoho
		// #40814).
		add_filter(
			'learnomy_protected_routes',
			static function ( array $routes ): array {
				if ( ! \Learnomy\require_login_for_content() ) {
					return $routes;
				}

				return array_values( array_unique( array_merge( $routes, \Learnomy\content_route_names() ) ) );
			}
		);
	}

	/**
	 * Whether nav items may be adjusted for the CURRENT VIEWER.
	 *
	 * `wp_get_nav_menu_items` is a data-layer filter: unlike the render-only
	 * `wp_nav_menu_objects`, it also fires when the owner is EDITING the menu in
	 * wp-admin and when the block editor loads items over REST. Filtering there
	 * would delete rows from under the person managing the menu, or hide them in
	 * the editor canvas. So viewer-scoped adjustments apply to front-end
	 * rendering only; the stored menu is never touched.
	 *
	 * @return bool
	 */
	private function may_adjust_nav_items(): bool {
		if ( is_admin() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		return true;
	}

	/**
	 * Does this menu item point at one of our routes?
	 *
	 * Compares normalised PATHS rather than whole URL strings. A nav item is a
	 * stored post whose URL was snapshotted when the owner added it, so the
	 * stored value routinely differs from what `route_url()` returns today by
	 * scheme (an http→https move), host (www), a trailing slash, or a tracking
	 * query arg — and exact string equality silently stops matching on every one
	 * of those, taking the solo-mode hiding and the security nonce with it.
	 *
	 * @param object $item  Menu item object.
	 * @param string $route Route name in the registry.
	 * @return bool
	 */
	private static function nav_item_targets_route( $item, string $route ): bool {
		if ( empty( $item->url ) ) {
			return false;
		}

		/*
		 * A route that is not registered cannot be the target of anything, and
		 * asking route_url() about one is worse than useless here: it returns
		 * home_url( '/' ), whose path is '' - which is also the path of the
		 * "Home" item in almost every WordPress menu ever built. The comparison
		 * below then reports a MATCH, and the caller hides Home.
		 *
		 * That is not hypothetical. Free's hidden-route list named
		 * 'instructor-reports', which only Pro's analytics extension registers,
		 * so on a Free-only site - 90% of installs - every non-instructor lost
		 * the Home link from the site navigation. Students and logged-out
		 * visitors both. Found in the 1.9.3 pre-release smoke, and invisible on
		 * the dev site until a Home item was added to a menu, because this
		 * install's menus happened not to have one.
		 *
		 * Guarding here rather than only at the list means the whole class is
		 * closed: any Pro-only route named by any list, on any site missing that
		 * extension, now matches nothing instead of matching the front page.
		 */
		if ( ! in_array( $route, \Learnomy\Routes::names(), true ) ) {
			return false;
		}

		return self::nav_url_path( (string) $item->url ) === self::nav_url_path( \Learnomy\route_url( $route ) );
	}

	/**
	 * Path component of a URL, trailing slash removed, for comparison.
	 *
	 * @param string $url Absolute or relative URL.
	 * @return string
	 */
	private static function nav_url_path( string $url ): string {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		return is_string( $path ) ? untrailingslashit( $path ) : '';
	}

	/**
	 * The menu belongs to the site, not to this plugin.
	 *
	 * A method called filter_instructor_menu_items() lived here and REMOVED
	 * items from the owner's navigation: instructor section links from non-instructors, an
	 * "apply to teach" link from people who already teach, a student-signup
	 * link from anyone signed in. All of it deleted on 2026-08-18.
	 *
	 * It was never ours to do. The owner built that menu deliberately; a plugin
	 * quietly dropping entries out of it is overreach even when it drops the
	 * right ones - and it did not always drop the right ones. Matching worked by
	 * comparing a menu item's URL path to route_url()'s, and an unregistered
	 * route returns home_url('/'), whose path is '' - the same as a Home item's.
	 * On Free-only sites that removed Home from every non-instructor's
	 * navigation.
	 *
	 * Nothing was protecting anything. Every route it hid answers correctly on
	 * its own, which is where a rule belongs:
	 *
	 *   - the five instructor sections render an access gate (200, "instructor
	 *     access required", no console markup) instead of the 404 that made a
	 *     link to them look like a dead end in the first place;
	 *   - /become-instructor/ greets somebody who already teaches by name and
	 *     offers no form;
	 *   - /register/student/ redirects a signed-in instructor to their dashboard
	 *     and has never granted the role from a click - the role invariant is in
	 *     the router, and a UI that stops showing a control was never the rule.
	 *
	 * One behaviour changes on purpose: in solo mode the two instructor-signup
	 * routes 404 through the learnomy_route_enabled gate, so an owner who turns
	 * instructor registration off and leaves the menu item behind now has a link
	 * that 404s rather than one that silently vanishes. That is the right way
	 * round. The feature is off because they turned it off, the stale link is
	 * theirs to remove, and WordPress does not hide menu items pointing at
	 * deleted pages either.
	 *
	 * The `learnomy_hidden_nav_routes` filter went with it. Nothing consumed it
	 * outside this method.
	 *
	 * nonce_register_student_menu_item() below is deliberately NOT part of this:
	 * it removes nothing. It stamps a nonce onto an owner-placed signup link so
	 * the link WORKS - the opposite of editing their menu against them.
	 */


	/**
	 * Give the "Register as Student" menu link a fresh nonce.
	 *
	 * That route GRANTS A ROLE, and it did so on a bare GET with no nonce and no
	 * confirmation, so any third-party page could make a logged-in visitor's
	 * browser take the role by loading an image or an iframe pointed at it
	 * (Basecamp 10167485743). The grant itself is deliberate — it exists so a
	 * subscriber clicking the link lands on a working dashboard instead of a
	 * dead end (#9924598683) — so the fix is to prove the click was ours, not to
	 * remove it.
	 *
	 * Nonced HERE, at render, rather than in the metabox: a nav item is a stored
	 * post whose URL is snapshotted when the owner adds it, so a nonce baked in
	 * at that moment would be long expired by the time anyone clicked it.
	 *
	 * A link without a valid nonce still WORKS — the router simply redirects
	 * without granting. Nothing breaks for a hand-written link or an old
	 * bookmark; it just stops being a way to write to somebody's account.
	 *
	 * @param array  $items Menu item objects.
	 * @param object $menu  The menu term. Unused.
	 * @param array  $args  Retrieval args. Unused.
	 * @return array
	 */
	public function nonce_register_student_menu_item( $items, $menu = null, $args = null ) {
		unset( $menu, $args );

		if ( ! is_array( $items ) || ! $this->may_adjust_nav_items() || ! is_user_logged_in() ) {
			return $items;
		}

		foreach ( $items as $item ) {
			if ( self::nav_item_targets_route( $item, 'register-student' ) ) {
				$item->url = wp_nonce_url( $item->url, 'learnomy_register_student' );
			}
		}

		return $items;
	}

	public function register_plugin_theme_json( $theme_json ) {
		$plugin_json_path = LEARNOMY_DIR . 'theme.json';
		if ( ! file_exists( $plugin_json_path ) ) {
			return $theme_json;
		}
		$plugin_data = json_decode( file_get_contents( $plugin_json_path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! $plugin_data ) {
			return $theme_json;
		}
		$theme_json->update_with( $plugin_data );
		return $theme_json;
	}

	/**
	 * Write the preset licence key the free plugin updates with.
	 *
	 * WHY THIS EXISTS, because it is not obvious and its absence is silent:
	 * the EDD SL SDK does NOT read the `license` value passed to
	 * `$registry->register()`. That value is effectively decorative. The SDK
	 * reads `get_option( "{slug}_license_key" )` — see
	 * Licensing\License::get_license_key() — and Handlers\Plugin returns
	 * BEFORE constructing an updater when that option is empty:
	 *
	 *     $license_key = $this->get_license_key();
	 *     if ( empty( $license_key ) && ! $this->supports_keyless_activation() ) {
	 *         return;
	 *     }
	 *
	 * With no updater there is no version request, no cached version info, and
	 * nothing injected into the update_plugins transient. WordPress is never
	 * told an update exists, so the plugin sits on its installed version
	 * forever, with no error anywhere and a store that is answering correctly.
	 *
	 * That is exactly what happened: free sites stayed on 1.8.0 while Pro
	 * updated to 1.9.0, and the version skew fatalled them. Every sibling free
	 * plugin seeds this option; Learnomy was the one that did not.
	 *
	 * Guarded so it never overwrites a key an owner has set themselves.
	 *
	 * @return void
	 */
	private function seed_free_license_key(): void {
		if ( ! get_option( 'learnomy_license_key' ) ) {
			update_option( 'learnomy_license_key', '7bd8ab074c1c90ef9a02e95b48c553e7' );
		}
	}

	public function activate(): void {
		// Flag the setup-wizard redirect FIRST, before any step that could fail.
		// It used to be the last line of activate(), so a failure in schema
		// creation / options merge left it unset and the wizard never launched.
		// A generous TTL survives a slow host between activation and the next
		// admin page load (was a fragile 30s) (Basecamp 10123221732).
		set_transient( 'learnomy_activation_redirect', true, MINUTE_IN_SECONDS * 5 );

		require_once LEARNOMY_DIR . 'includes/db/class-schema.php';
		DB\Schema::create_tables();

		update_option( 'learnomy_db_version', LEARNOMY_DB_VERSION );

		$this->seed_free_license_key();

		// Merge defaults into existing settings so new keys are always present
		// without overwriting values the site owner has already customized.
		$existing = get_option( 'learnomy_settings', array() );
		$defaults = self::settings_defaults();
		$merged   = array_merge( $defaults, $existing );
		update_option( 'learnomy_settings', $merged );

		// Standalone options — only set if absent.
		add_option( 'learnomy_email_from_name', get_bloginfo( 'name' ) );
		add_option( 'learnomy_email_from_address', get_option( 'admin_email', '' ) );
		add_option( 'learnomy_instructor_can_publish', '1' );

		// Module kernel — set the default-enabled list on first activation
		// per Plan 00 §1.5 D4. Only set if absent so existing installs are
		// not disturbed.
		add_option( 'learnomy_modules_enabled', \Learnomy\Modules\Module_Kernel::defaults() );

		if ( ! get_option( 'learnomy_incoming_webhook_secret' ) ) {
			update_option( 'learnomy_incoming_webhook_secret', wp_generate_password( 32, false ) );
		}

		// Register roles on activation so caps are available immediately.
		require_once LEARNOMY_DIR . 'includes/permissions/class-capabilities.php';
		( new Permissions\Capabilities() )->register();

		// Flag a rewrite flush for the next init — rules are not registered yet.
		delete_option( 'learnomy_permalinks_flushed_' . LEARNOMY_VERSION );
	}

	/**
	 * Returns the full set of default values for learnomy_settings.
	 * Used on activation and upgrade to fill in new keys.
	 */
	public static function settings_defaults(): array {
		return array(
			// General.
			'base_slug'                 => 'courses',
			'currency'                  => 'USD',
			'enrollment_mode'           => 'open',
			'homepage_redirect'         => false,

			// Commerce.
			'default_platform_rate'     => 30.0,
			'platform_commission'       => 30.0,
			'withdrawal_minimum'        => 25.0,
			'payout_period'             => 'monthly',
			'payout_paypal'             => true,
			'payout_bank'               => true,
			// `tax_handling` intentionally removed (Wave 2C row 2C-2 —
			// tax collection deferred from v1.0; tracked in plan/INVARIANTS.yaml).
			'refund_days'               => 30,
			'multi_instructor'          => false,
			'instructor_self_publish'   => true,

			// Payment gateways.
			'enable_stripe'             => false,
			// Legacy flat keys kept for backward compat — Phase 1 migration copied these
			// to mode-specific encrypted options but we never remove them (non-destructive).
			'stripe_publishable_key'    => '',
			'stripe_mode'               => 'test',
			'stripe_test_publishable'   => '',
			'stripe_live_publishable'   => '',
			'enable_paypal'             => false,
			// Legacy flat keys kept for backward compat.
			'paypal_client_id'          => '',
			'paypal_sandbox'            => true,
			'paypal_mode'               => 'sandbox',
			'paypal_sandbox_client'     => '',
			'paypal_sandbox_webhook_id' => '',
			'paypal_live_client'        => '',
			'paypal_live_webhook_id'    => '',
			'enable_woocommerce'        => false,
			'enable_test_gateway'       => true,

			// Content.
			'enable_certificates'       => true,
			'certificate_footer'        => '',
			'enable_reviews'            => true,
			'auto_approve_reviews'      => false,

			// Progressive Web App (offline mode + service worker).
			'enable_pwa'                => true,

			// Quiz.
			'quiz_time_limit'           => 0,
			'quiz_pass_mark'            => 70,
			'quiz_max_attempts'         => 3,
			'quiz_randomize'            => false,
			'quiz_show_answers'         => true,
			'quiz_track_tabs'           => false,

			// Email.
			'email_sender_name'         => '',
			'email_sender_email'        => '',

			// Advanced.
			'api_rate_limit'            => 60,
			'cache_ttl'                 => 3600,
			'data_retention_days'       => 365,
			// Email Log retention. Seeded for NEW installs only — an existing
			// site keeps everything until its owner sets a window, because an
			// upgrade must never quietly delete their history.
			'email_log_retention_days'  => 365,
			'delete_data_on_uninstall'  => false,
			'audit_log_retention_days'  => 90,
			'uninstall_data'            => false,
		);
	}

	public function deactivate(): void {
		delete_option( 'learnomy_permalinks_flushed_' . LEARNOMY_VERSION );
		flush_rewrite_rules();
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'learnomy', false, dirname( plugin_basename( LEARNOMY_FILE ) ) . '/languages' );
	}

	/**
	 * Stand the buy chrome down on a members-only course when the site is not
	 * selling memberships.
	 *
	 * Returns the sentence the enrol card shows in place of a CTA. Runs at
	 * priority 5 so a more specific gate (a cohort, say) still wins — this is
	 * the fallback for "there is no route in at all", not a claim on the slot.
	 *
	 * @param string $gate      Gate copy so far; non-empty means someone else answered.
	 * @param object $course    Course row.
	 * @param int    $user_id   Viewer.
	 */
	/**
	 * Stand the buy chrome down on a course whose instructor is suspended.
	 *
	 * Enrollment_Service::enroll() already refuses these, so without this the
	 * course page would show "Buy Now" and fail when pressed - the exact shape of
	 * Basecamp 10190724992, a control that exists and does not work. Answering
	 * through the gate seam means every surface stands down together (sidebar
	 * card, sticky bar, headbar) instead of each learning the rule separately.
	 *
	 * Says nothing about WHY. A buyer does not need to be told the site owner has
	 * paused somebody, and naming an instructor's suspension on a public page
	 * would be a reputational statement the owner never asked us to publish.
	 *
	 * Someone already enrolled never reaches this: the enrolled surfaces render
	 * from `has_access`, which the gate does not touch, and the resolver asks the
	 * gate below access for the same reason.
	 *
	 * @param string $gate    Gate HTML so far.
	 * @param object $course  Course row.
	 * @param int    $user_id Viewer id, 0 when logged out.
	 */
	public function gate_suspended_instructor( string $gate, $course, int $user_id ): string {
		if ( '' !== $gate || ! is_object( $course ) || empty( $course->id ) ) {
			return $gate;
		}

		$author_id = (int) ( $course->author_id ?? 0 );
		if ( $author_id <= 0 || ! Models\Instructor::is_suspended( $author_id ) ) {
			return $gate;
		}

		// The owner and the instructor keep the normal chrome so they can still
		// preview and manage the course, matching the service-layer bypass.
		if ( $user_id > 0 && ( $author_id === $user_id || user_can( $user_id, 'manage_options' ) ) ) {
			return $gate;
		}

		return '<p class="lrn-enroll-gate-note">'
			. esc_html__( 'This course is not accepting new enrollments right now.', 'learnomy' )
			. '</p>';
	}

	public function gate_members_only_without_membership( string $gate, $course, int $user_id ): string {
		if ( '' !== $gate || ! is_object( $course ) || empty( $course->id ) ) {
			return $gate;
		}

		if ( ! Models\Course::is_members_only( $course ) || membership_on_offer() ) {
			return $gate;
		}

		// Somebody who already holds access reads the enrolled flow, not a gate.
		if ( $user_id > 0 && Models\Enrollment::is_enrolled( $user_id, (int) $course->id ) ) {
			return $gate;
		}

		return __( 'This course is available through a membership. Memberships are not on sale right now.', 'learnomy' );
	}

	/**
	 * Record the current user's last-active timestamp for the Students admin
	 * column (Basecamp #9941181301 — the meta was read but never written).
	 *
	 * Throttled to one write per 15 minutes per user so we never add a usermeta
	 * write to every request; the get_user_meta read is object-cached and cheap.
	 */
	public function track_last_active(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}
		$this->touch_last_active( get_current_user_id() );
	}

	/**
	 * Record that a member did something, throttled to one write per 15 minutes.
	 *
	 * Kept separate from track_last_active() so it can be driven by an explicit
	 * user id — a student who learns through the app completes lessons over the
	 * REST API, where token auth resolves the current user only in the route's
	 * permission_callback, AFTER the `init` hook the page-load tracker runs on.
	 * That is why the Students list showed "Last active: Never" for members who
	 * had demonstrably finished a course: their activity never came through a web
	 * page load. Progress completion now stamps it directly.
	 *
	 * @param int $user_id The member who was active.
	 */
	public function touch_last_active( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		$now  = time();
		$last = (int) get_user_meta( $user_id, 'learnomy_last_active', true );
		if ( $now - $last >= 15 * MINUTE_IN_SECONDS ) {
			update_user_meta( $user_id, 'learnomy_last_active', $now );
		}
	}

	public function init(): void {
		$this->load_textdomain();
		$this->maybe_redirect_to_setup();

		// Also on init, not only on activation: every site already running
		// Learnomy has passed activation and will never run it again, so an
		// activation-only fix would leave exactly the sites that are broken
		// still broken. The option guard makes this one autoloaded read per
		// request once it is set.
		$this->seed_free_license_key();

		// functions.php must be required BEFORE check_db_version() so any
		// migration that uses Models (which call Learnomy\table() from
		// functions.php) can run. Migration_2_1_0 surfaced this ordering
		// bug — Model::where() needs Learnomy\table() resolved during
		// plugins_loaded. Other dependencies still load later in
		// load_dependencies(); this is the only file that must move up.
		require_once LEARNOMY_DIR . 'includes/functions.php';

		$this->check_db_version();
		$this->check_caps_version();
		$this->load_dependencies();

		/**
		 * Fires after Learnomy has fully initialized all dependencies.
		 *
		 * @param \Learnomy\Learnomy $learnomy The main plugin instance.
		 */
		do_action( 'learnomy_init', $this );
	}

	private function maybe_redirect_to_setup(): void {
		if ( ! get_transient( 'learnomy_activation_redirect' ) ) {
			return;
		}
		// Bail BEFORE consuming the transient. Consuming it first meant the first
		// background request after activation (AJAX heartbeat, a REST call, cron,
		// or a bulk/network activate) ate the flag and returned without redirecting,
		// so the admin never landed on the wizard (Basecamp 10123221732).
		if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_network_admin() || isset( $_GET['activate-multi'] ) ) {
			return;
		}
		delete_transient( 'learnomy_activation_redirect' );
		wp_safe_redirect( admin_url( 'admin.php?page=learnomy-setup' ) );
		exit;
	}

	private function check_db_version(): void {
		$current = get_option( 'learnomy_db_version', '0.0.0' );
		if ( version_compare( $current, LEARNOMY_DB_VERSION, '<' ) ) {
			require_once LEARNOMY_DIR . 'includes/db/class-migrator.php';
			DB\Migrator::run( $current );
		}
	}

	/**
	 * Re-run Capabilities::register() whenever the cap signature changes.
	 *
	 * Earlier this gate was bound to LEARNOMY_VERSION, which meant cap
	 * migrations only landed when the plugin's user-facing version was
	 * bumped. That's wrong: a code-only cap addition (e.g. `upload_files`
	 * for lrn_instructor) is invisible to existing sites until the next
	 * release bump, and customers report "Instructor still cannot upload"
	 * even after pulling the fix (Basecamp #9919728160 bounce).
	 *
	 * New approach: hash the registered cap arrays. Any add/remove changes
	 * the hash, and `register()` runs once to propagate it. Store the hash
	 * in `learnomy_caps_signature`. Cost is a single autoloaded
	 * `get_option` + a tiny md5 per page load.
	 */
	private function check_caps_version(): void {
		require_once LEARNOMY_DIR . 'includes/permissions/class-capabilities.php';
		$caps      = new Permissions\Capabilities();
		$signature = md5( wp_json_encode( $caps->get_all() ) );
		$stored    = get_option( 'learnomy_caps_signature', '' );
		if ( $signature !== $stored ) {
			$caps->register();
			update_option( 'learnomy_caps_signature', $signature );
		}
	}

	private function load_dependencies(): void {
		require_once LEARNOMY_DIR . 'includes/functions.php';

		// Committed, scoped runtime libraries (libs/) — loads Dompdf for PDF
		// certificates. Shipped in the repo so a fresh clone or customer
		// install works with no `composer install`; scoped under
		// Learnomy\Vendor\ so it can't collide with another plugin's dompdf.
		// Regenerate with `composer build-libs`.
		$libs_autoload = LEARNOMY_DIR . 'libs/autoload.php';
		if ( file_exists( $libs_autoload ) ) {
			require_once $libs_autoload;
		}

		if ( defined( 'WP_CLI' ) && \WP_CLI ) {
			require_once LEARNOMY_DIR . 'includes/cli/class-cli.php';
		}

		// Dompdf is shipped in the plugin's libs/ tree, so this fallback
		// path should never fire on a proper install. Keep the notice for
		// developer-machine clones (where someone removed libs/) but scope
		// it to manage_options so site owners + students never see it — and
		// drop the composer instructions in favour of a "reinstall the
		// plugin" message which is the right action for a customer.
		if (
			is_admin()
			&& current_user_can( 'manage_options' )
			&& ! class_exists( \Learnomy\Vendor\Dompdf\Dompdf::class )
		) {
			add_action(
				'admin_notices',
				static function (): void {
					$settings = get_option( 'learnomy_settings', array() );
					if ( empty( $settings['enable_certificates'] ) ) {
						return;
					}
					echo '<div class="notice notice-warning is-dismissible"><p>'
					. esc_html__( 'Learnomy: PDF library files are missing. Reinstall the latest plugin zip to restore direct PDF certificate downloads. Until then, certificates fall back to browser print-to-PDF.', 'learnomy' )
					. '</p></div>';
				}
			);
		}

		Adapters\Adapter_Registry::init();
		new Cron();

		// Background JSON→HTML content migration. The storage cutover is ON by
		// default (learnomy_store_content_as_html kill-switch); this writes a
		// one-time auto-backup then migrates existing content in chained AS
		// batches. Self-gates off when a site filters the cutover off.
		Content\Content_Migrator::register();

		// Curated certificate library: registers the extensible design registry
		// (`learnomy_certificate_designs` filter) and seeds the ready-to-use
		// designs once, so every instructor has professional certificates to
		// pick with zero setup.
		Services\Certificate_Library::register();

		// Bridge business-event hooks to the outbound webhook dispatcher so
		// real activity actually fires payloads. Stands down automatically
		// when Webhooks Pro owns delivery (learnomy_webhooks_core_dispatch_enabled).
		new Services\Webhook_Events();

		// Clean up all LMS data when a WP user is deleted. The service fires on
		// `deleted_user` (post-deletion) at priority 20 so Pro extension listeners
		// on `delete_user` (pre-deletion) can capture what they need first.
		( new Services\User_Cleanup_Service() )->register_hooks();

		// Engagement aggregator: listens on `learnomy_lesson_completed` and
		// bumps `lrn_engagement_monthly` for each (active membership × course
		// instructor) so the monthly engagement-split cron has rows to split.
		// Without this listener every membership-revenue payout to instructors
		// is silently zero — the production-side bug fixed in this PR.
		( new Services\Engagement_Service() )->register_hooks();

		// Member avatar override: when a member has uploaded a profile photo,
		// make it the avatar everywhere get_avatar()/get_avatar_url() resolves
		// one (Learnomy surfaces + theme/BuddyPress). No-op without a photo.
		( new Services\Avatar_Service() )->register_hooks();

		// Site-owner-defined registration/profile fields (no-code), rendered +
		// saved through the learnomy_account_fields / _save hooks.
		( new Services\Custom_Fields() )->register_hooks();
		( new Services\Registration_Captcha() )->register_hooks();

		// Provisioning: land externally-authenticated users (SSO / LTI) with the
		// right LMS role + optional auto-enrollment on first login. Fires
		// learnomy_user_provisioned (LTI fires the same hook at launch).
		( new Services\Provisioning_Service() )->register_hooks();

		// Wishlist: drop a saved course the moment the student gains access to
		// it, from ANY enrollment path — they all fire learnomy_student_enrolled.
		Services\Wishlist_Service::register_hooks();

		// Payout settlement: closes the ledger when an external rail reports a
		// withdrawal paid (`learnomy_withdrawal_paid`, fired by Pro's Stripe
		// Connect transfer). Registered unconditionally — the hook only fires
		// where a payout rail exists, and a site that disables the commissions
		// module has no withdrawals to settle.
		( new Services\Commission_Service() )->register_hooks();

		new Blocks();
		new API\Api();
		new API\Token_Auth();
		new API\Api_Key_Auth();
		new Router();
		new Admin_Bar();

		new Shortcodes();

		new SEO\Schema_Markup();
		new SEO\Sitemap();
		// Ours, always served — the one sitemap no SEO plugin can switch off.
		new SEO\Standalone_Sitemap();
		new Notifications\Notifier();
		new Privacy();

		// An instructor may upload, which in core means they may browse EVERY
		// attachment on the site — the owner's files included. Scope the library
		// to its own author for anyone who cannot manage others' content.
		Security\Media_Library_Scope::init();

		// WordPress Abilities API — register Learnomy's LMS abilities (lifecycle,
		// authoring, reporting) so AI agents / MCP clients and our own in-product
		// AI features can drive the LMS through one audited surface. The class
		// only hooks `wp_abilities_api_init` in its constructor, so it must be
		// instantiated here; nothing else news it up. Gated by the `abilities`
		// module and the presence of the core Abilities API (WP 6.9+).
		if (
			class_exists( 'WP_Abilities_Registry' )
			&& Modules\Module_Kernel::instance()->is_enabled( 'abilities' )
		) {
			new Abilities();
		}

		// Alias --lrn-* tokens onto the active theme's design tokens so Learnomy
		// follows the theme's palette + site-wide dark mode (Reign today; BuddyX
		// / Pro register via the learnomy_theme_token_maps filter).
		Integrations\Theme_Bridge::init();

		// Surface A (W1) — expose per-course language as a `<meta
		// http-equiv="content-language">` tag on the single-course page so
		// browsers / assistive tech / SEO tools see the instructor-declared
		// language regardless of the surrounding theme's `<html lang>` value.
		add_action( 'wp_head', array( $this, 'output_course_language' ) );

		// Surface A (W1) — subscribe to the `course-card` badge hook and
		// emit a "Featured" ribbon when a course has `is_featured=true` in
		// its settings JSON. Lives here (not in the template) so catalog
		// loops, related-course lists, and admin previews all get the badge
		// without duplicating logic.
		add_action( 'learnomy_course_card_badges', array( $this, 'render_featured_course_badge' ) );

		// Auto-issue certificates on course completion. Per-course toggle is
		// authoritative: when the instructor flipped `certificate_enabled` in
		// the course settings we issue regardless of the global option; when
		// they left it off we still honour the site-wide default so existing
		// courses don't regress.
		add_action(
			'learnomy_course_completed',
			array( $this, 'maybe_auto_issue_certificate' ),
			20,
			3
		);

		// Surface B (W6) — student-facing "Retake course" button on the
		// enrolled view POSTs to this AJAX action. Nonce-verified and gated
		// by the per-course `allow_retake` setting inside the service.
		add_action( 'wp_ajax_learnomy_retake_course', array( $this, 'ajax_retake_course' ) );

		if ( is_admin() ) {
			new Admin\Admin();
			( new Admin\Nav_Menu_Metabox() )->register();
		}

		// SS-3 / NB-2: publish-time payment-gateway watcher. Registered
		// unconditionally so the listener fires on the REST `update_course`
		// path too — `is_admin()` returns false during REST requests, which
		// is exactly where the admin form's "Update Course" button lands.
		// Inside the watcher:
		// - record_if_warranted() is cheap (one Course::find + Payment_Settings
		// read + one update_option) and only writes when a paid course
		// publishes without any gateway.
		// - render_notice() gates itself on current_user_can('manage_options')
		// AND is_learnomy_screen(), so it cannot leak onto front-end
		// requests, cron, or non-Learnomy admin screens.
		// - ajax_dismiss() runs only when its admin-ajax action fires.
		// Same pattern Pro uses for License_Admin (registered unconditionally so
		// rest_api_init fires for REST requests).
		( new Admin\Pricing_Gateway_Watcher() )->register();

		// Private storage for lesson handouts: the one-time backfill of files
		// uploaded before the store existed, plus the Site Health test that
		// reports whether the web server also refuses the folder. Registered
		// unconditionally — the backfill runs under Action Scheduler, which has
		// no admin context, and the notice/test gate themselves.
		Services\Protected_Uploads::register();

		// Route ownership: another LMS's CPT archive can take `/courses/` off us
		// (it registers into the same `extra_rules_top` array we do), and its
		// rules outlive its removal. Registered unconditionally — the class gates
		// its own surfaces on `admin_init` / `admin_notices` / Site Health, and
		// the `rewrite_rules_array` filter it adds must be in place on the front
		// end too for the owner escape hatch to work.
		Services\Route_Conflicts::register();

		// Flush rewrites once after activation. Version-keyed so URL
		// structure changes trigger a re-flush. Deferred to init:99
		// so Router::add_rewrite_rules (init:10) has run first.
		$flush_key = 'learnomy_permalinks_flushed_' . LEARNOMY_VERSION;
		if ( ! get_option( $flush_key ) ) {
			add_action(
				'init',
				static function () use ( $flush_key ) {
					flush_rewrite_rules();
					update_option( $flush_key, true );
				},
				99
			);
		}

		// Flush rewrites when a URL slug changed in Settings. The version-keyed
		// flush above only fires on a plugin upgrade, so editing `base_slug`
		// (or any reslug-able segment) used to leave the rewrite rules stale —
		// the new path 404'd until a manual flush. The settings sanitizer sets
		// `learnomy_pending_rewrite_flush` whenever a slug actually changed; we
		// flush on init:99 (after Router::add_rewrite_rules re-registers the
		// rules with the new slugs) and clear the flag so it runs exactly once.
		add_action( 'init', array( $this, 'maybe_flush_for_slug_change' ), 99 );

		// Email-verification magic link (Basecamp #9928203396). Runs at
		// priority 5 so we catch the request before the catalog router /
		// theme can intercept the home URL with its own query handling.
		add_action( 'init', array( '\Learnomy\Auth\Email_Verification', 'maybe_handle_request' ), 5 );

		// wp-login.php integration: land members on their own dashboard instead
		// of wp-admin/profile.php, and stop core's login form from answering
		// "does this account exist?" (Basecamp 10134139585). Registered here
		// rather than on a login-only hook because `login_redirect` fires
		// during the POST to wp-login.php, which never reaches `init` on the
		// front end.
		\Learnomy\Auth\Login_Flow::init();

		// Keep core's Application Passwords authorize screen reachable, so a
		// student or subscriber can connect the mobile app at all. WooCommerce
		// redirects every member without `edit_posts` / `manage_woocommerce` /
		// `view_admin_dashboard` away from all of wp-admin, which is where both
		// screens that mint the app's credential live. Registered here, before
		// `admin_init`, because that is when WooCommerce decides to redirect.
		\Learnomy\Auth\App_Authorize_Access::init();

		// One connect door per site (Wbcom App Auth standard): register the
		// app's deep-link scheme with BuddyNext's connect bridge when BN runs
		// beside us, so a Learnomy-app connection through the site's real
		// front door may deliver the credential to learnomyapp://. Harmless
		// no-op when BuddyNext is absent.
		\Learnomy\Auth\App_Connect::init();

		// Headless-credential parity: an unverified email blocks JWT issuance
		// (/auth/token), so it must equally block WordPress core Application
		// Passwords — the mobile app's credential — or the app becomes a
		// verification bypass. Web cookie login is deliberately untouched.
		add_action(
			'wp_authenticate_application_password_errors',
			array( '\Learnomy\Auth\Email_Verification', 'reject_unverified_app_password' ),
			10,
			2
		);

		// Track last-active timestamp for the Students admin column. The meta
		// was read but never written, so the column was always empty
		// (Basecamp #9941181301). Throttled inside the handler.
		add_action( 'init', array( $this, 'track_last_active' ) );
		// Also stamp last-active on real learning activity, not just web page
		// loads — an app student completing lessons over REST would otherwise
		// read "Never". Args: ($user_id, $lesson_id, $course_id).
		add_action(
			'learnomy_lesson_completed',
			function ( $user_id ) {
				$this->touch_last_active( (int) $user_id );
			}
		);

		// A members-only course on a site that is not selling memberships has
		// no way in. Its CTA used to link at the membership pricing route on
		// the sole basis that the course was members-only — but that route
		// only exists while the module is on, so with the module off every
		// such course advertised "Join to enroll" and delivered a 404. This
		// install had eleven published courses in exactly that state.
		//
		// Answered through the existing enrol-gate seam rather than a fourth
		// branch in each CTA: when a gate is in force the buy chrome already
		// stands down everywhere (headbar, sidebar card, sticky bar), which is
		// precisely the behaviour wanted here.
		add_filter( 'learnomy_course_enroll_gate', array( $this, 'gate_members_only_without_membership' ), 5, 3 );

		// Same seam, same reason, for a course whose instructor the owner has
		// paused. Priority 4 so it answers BEFORE the members-only gate: a
		// suspended instructor's course is not open on any terms, so offering a
		// membership as the way in would be a route that then refuses at the
		// service layer.
		add_filter( 'learnomy_course_enroll_gate', array( $this, 'gate_suspended_instructor' ), 4, 3 );

		// Role display names are persisted (site-locale) at activation; re-translate
		// the in-memory WP_Roles each request so they render in the current locale.
		add_action( 'init', array( '\Learnomy\Permissions\Capabilities', 'translate_role_names' ), 11 );

		// Catalog Facets — boot the default sidebar renderer. Customer
		// registrations land via add_action('init', ...) before priority 10,
		// at which point our renderer reads Facets::all() and emits markup.
		\Learnomy\Catalog\Facet_Renderer::boot();

		// Register the built-in facet for each course taxonomy at priority 5,
		// so a site owner registering or deregistering at the default 10 still
		// wins. Without this the course editor's tag pickers wrote rows nobody
		// could ever filter by (Basecamp #10143794593).
		add_action( 'init', array( '\Learnomy\Catalog\Facets', 'register_defaults' ), 5 );

		// Dashboard widget registry — Free's first-party widgets register
		// through the same `learnomy_dashboard_widgets` filter that Pro and
		// any third party use, so the dashboard templates have no
		// privileged in-template path.
		\Learnomy\Services\Free_Dashboard_Widgets::boot();

		// JS translations for shared / multi-path scripts. The modal helpers
		// (confirm / prompt / cancel) plus a few admin + block scripts are
		// registered from several enqueue paths (admin pages, frontend routes,
		// the block editor, the setup wizard). Attaching translations inline at
		// each registration would scatter the call and silently miss a path, so
		// we attach them once — late — to whichever of these handles is
		// registered in the current request. Page-specific scripts keep their
		// inline wp_set_script_translations() at their single enqueue site.
		add_action( 'admin_enqueue_scripts', array( $this, 'set_shared_script_translations' ), 100 );
		add_action( 'wp_enqueue_scripts', array( $this, 'set_shared_script_translations' ), 100 );
		add_action( 'enqueue_block_editor_assets', array( $this, 'set_shared_script_translations' ), 100 );
	}

	/**
	 * Attach the `learnomy` text domain to shared scripts that register from
	 * more than one enqueue path.
	 *
	 * `wp_set_script_translations()` must run after the handle is registered and
	 * before it prints; hooking enqueue at priority 100 satisfies both. Handles
	 * that aren't registered in this request are skipped.
	 */
	public function set_shared_script_translations(): void {
		$handles = array(
			'learnomy-confirm-modal',
			'learnomy-prompt-modal',
			'learnomy-cancel-modal',
			'learnomy-account-details',
			'learnomy-course-builder',
			'learnomy-quiz-editor',
			'learnomy-admin-webhooks',
			'learnomy-admin-quiz-grading',
			'learnomy-blocks-editor',
		);
		foreach ( $handles as $handle ) {
			if ( wp_script_is( $handle, 'registered' ) ) {
				wp_set_script_translations( $handle, 'learnomy', LEARNOMY_DIR . 'languages' );
			}
		}
	}

	/**
	 * Flush rewrite rules once after a Settings URL-slug change.
	 *
	 * Runs at init:99 so the Router (init:10) has already registered the
	 * rewrite rules built from the new slugs. The single-shot flag is set by
	 * Admin::sanitize_settings() only when a slug value actually changed.
	 */
	public function maybe_flush_for_slug_change(): void {
		if ( ! get_option( 'learnomy_pending_rewrite_flush' ) ) {
			return;
		}
		flush_rewrite_rules();
		delete_option( 'learnomy_pending_rewrite_flush' );
	}

	/**
	 * Output `<meta http-equiv="content-language">` for the single-course
	 * page when the instructor set a `language` on the course. Falls
	 * through silently on every other route — no resolver helper exists
	 * in core today, so we read the rewrite slug directly.
	 */
	public function output_course_language(): void {
		if ( 'course' !== get_query_var( 'learnomy_route' ) ) {
			return;
		}
		$slug = sanitize_title( (string) get_query_var( 'learnomy_slug', '' ) );
		if ( '' === $slug ) {
			return;
		}
		$course = Models\Course::find_by_slug( $slug );
		if ( ! $course ) {
			return;
		}
		$language = (string) Models\Course::get_setting( (int) $course->id, 'language', '' );
		if ( '' === $language ) {
			return;
		}
		printf( '<meta http-equiv="content-language" content="%s">' . "\n", esc_attr( $language ) );
	}

	/**
	 * Subscriber for the `learnomy_course_card_badges` action. Emits a
	 * "Featured" badge + Lucide star icon when the instructor flagged the
	 * course as featured.
	 *
	 * @param object $course Course row from lrn_courses.
	 */
	public function render_featured_course_badge( $course ): void {
		if ( ! is_object( $course ) || empty( $course->id ) ) {
			return;
		}
		$is_featured = (bool) Models\Course::get_setting( (int) $course->id, 'is_featured', false );
		if ( ! $is_featured ) {
			return;
		}
		?>
		<span class="lrn-badge lrn-badge--amber lrn-badge--featured">
			<?php
			// Lucide `star` SVG — inlined rather than rendered via
			// `<i data-lucide>` so the icon is present even in contexts
			// where Lucide's JS pass hasn't run yet (e.g. REST responses
			// piping the card into dashboards or RSS excerpts).
			?>
			<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
				<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>
			</svg>
			<?php esc_html_e( 'Featured', 'learnomy' ); ?>
		</span>
		<?php
	}

	/**
	 * Auto-issues a certificate when a course is completed.
	 *
	 * The enable-gate cascade is:
	 *   1. Per-course `certificate_enabled` (Course::get_all_settings) — if
	 *      the instructor turned it on, issue regardless of the global.
	 *   2. Global `learnomy_settings.enable_certificates` — legacy default
	 *      for courses that haven't touched the per-course toggle.
	 *
	 * Template cascade (inside `Certificate_Service::issue()`):
	 *   per-course `certificate_template_id` → first active template.
	 *
	 * @param int $enrollment_id Unused — signature required by hook.
	 * @param int $user_id
	 * @param int $course_id
	 */
	public function maybe_auto_issue_certificate( int $enrollment_id, int $user_id, int $course_id ): void {
		unset( $enrollment_id );

		// One definition, shared with the completion screen, the notifier and
		// app-config. This used to be a local copy of the cascade, and the
		// DISPLAY side had a different one (Basecamp 10171331421).
		if ( ! Models\Course::certificates_enabled( $course_id ) ) {
			return;
		}

		// Skip silently if an active certificate already exists — protects
		// against the hook firing twice for the same enrollment on edge-case
		// completion paths.
		$existing = Models\Certificate::where(
			[
				'user_id'   => $user_id,
				'course_id' => $course_id,
				'status'    => 'active',
			]
		);
		if ( ! empty( $existing ) ) {
			return;
		}

		// No template_id is passed ON PURPOSE. Certificate_Service::issue() owns
		// the template cascade (per-course setting, then the first active
		// template), and this method must not hold a second copy of it.
		//
		// It used to pass `$course_settings['certificate_template_id'] ?? 0` for
		// a `$course_settings` that is never defined in this method. That read
		// as a live bug and was filed as one (Basecamp 10183993762), but both
		// symptoms on the card are refuted: `??` suppresses the undefined-variable
		// diagnostic, so nothing was ever logged, and the value it produced —
		// `0` — is precisely the "caller did not specify" signal that makes
		// issue() consult the course's own template. The behaviour was right and
		// only the code lied. Verified on live rows: seven courses configured
		// with template 5 all issued 5, while a course configured with none fell
		// through to the first active template (12), so the per-course setting
		// demonstrably wins over the fallback.
		( new Services\Certificate_Service() )->issue( $user_id, $course_id );
	}

	/**
	 * Student-facing AJAX endpoint: re-opens a completed enrollment for
	 * another attempt. Wired to the "Retake course" button on the enrolled
	 * view completion screen.
	 *
	 * The heavy lifting (gating, progress wipe, hook dispatch) lives in
	 * `Enrollment_Service::retake()` — this handler is the thin
	 * authn + authz + JSON envelope around it.
	 */
	/**
	 * Legacy AJAX handler — DEPRECATED in favour of
	 * `POST /learnomy/v1/courses/{id}/retake`.
	 *
	 * Kept for one release so any third-party JS still POSTing to
	 * admin-ajax with `action=learnomy_retake_course` keeps working
	 * during the deprecation window. _doing_it_wrong fires on every
	 * call so the deprecation surfaces in WP_DEBUG_LOG. The handler
	 * body is unchanged from the original — same nonce, same response
	 * shape — so existing customer code is unaffected for one release
	 * cycle. Removable in v1.1.
	 */
	public function ajax_retake_course(): void {
		_doing_it_wrong(
			'wp_ajax_learnomy_retake_course',
			'Use REST endpoint POST /learnomy/v1/courses/{id}/retake instead. The AJAX action is deprecated and will be removed in v1.1.',
			'1.0.1'
		);

		check_ajax_referer( 'learnomy_retake_course', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error(
				array( 'message' => __( 'You must be logged in to retake a course.', 'learnomy' ) ),
				401
			);
		}

		$course_id = isset( $_POST['course_id'] ) ? absint( wp_unslash( $_POST['course_id'] ) ) : 0;
		if ( $course_id <= 0 ) {
			wp_send_json_error(
				array( 'message' => __( 'Missing course id.', 'learnomy' ) ),
				400
			);
		}

		$user_id = get_current_user_id();
		$service = new Services\Enrollment_Service();
		$ok      = $service->retake( $user_id, $course_id );

		if ( ! $ok ) {
			wp_send_json_error(
				array( 'message' => __( 'Retake is not available for this course.', 'learnomy' ) ),
				403
			);
		}

		$course       = Models\Course::find( $course_id );
		$redirect_url = $course ? route_url( 'course', array( 'slug' => (string) $course->slug ) ) : route_url( 'catalog' );

		wp_send_json_success(
			array(
				'message'  => __( 'Retake started. Reloading your course…', 'learnomy' ),
				'redirect' => $redirect_url,
			)
		);
	}
}
