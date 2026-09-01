<?php
/**
 * Admin UI and settings.
 *
 * @package Learnomy
 */

namespace Learnomy\Admin;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Course;
use Learnomy\Models\Enrollment;
use Learnomy\Models\Transaction;
use Learnomy\Models\Review;
use Learnomy\Models\InstructorApplication;
use Learnomy\Models\Certificate;
use Learnomy\Models\CertificateTemplate;
use Learnomy\Models\Category;
use Learnomy\Models\Tag;
use Learnomy\Models\Section;
use Learnomy\Models\Lesson;
use Learnomy\Models\Quiz;
use Learnomy\Models\Question;
use Learnomy\Models\QuestionCategory;
use Learnomy\Models\Coupon;
use Learnomy\Models\Notification;
use Learnomy\Models\Webhook;
use Learnomy\Models\Withdrawal;
use Learnomy\Models\MembershipPlan;
use Learnomy\Models\MembershipPlanCourse;
use Learnomy\Models\Subscription;
use Learnomy\Models\QuizAttempt;
use Learnomy\Models\QuestionResponse;
use Learnomy\Models\QuizSlot;
use Learnomy\Models\CourseInstructor;
use Learnomy\Permissions\Permission_Engine;

class Admin {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		// Academy Stack companion install (Integrations tab) — one-click free /
		// license-gated Pro install, gated by cap + nonce in the handler.
		add_action( 'admin_post_learnomy_install_companion', array( $this, 'handle_install_companion' ) );
		// Group the submenu into Manage / System sections after both Free and
		// Pro have registered their items. Priority 99 runs after every
		// extension's `learnomy_admin_menu_items` callback.
		add_action( 'admin_menu', array( $this, 'reorder_submenu_into_sections' ), 99 );
		// Keep the three Learnomy top-level menus (Learnomy / Commerce /
		// Settings) rendered as one contiguous block, no matter what menu
		// positions other plugins claim. Tight decimal positions (30 / 30.1 /
		// 30.2) already cluster them; this filter is the hard guarantee — it
		// only relocates OUR menus to sit together at Learnomy's spot and
		// leaves every other plugin's relative order untouched.
		add_filter( 'custom_menu_order', '__return_true' );
		add_filter( 'menu_order', array( $this, 'group_top_level_menus' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'maybe_render_setup_wizard' ) );
		add_action( 'admin_init', array( $this, 'maybe_enable_payments_and_configure' ) );
		// SS-2: when a super-admin reaches a module-gated commerce slug via
		// stale bookmark, redirect to Modules with a notice so the page is
		// recoverable instead of a dead-end WP 403.
		//
		// Hook ordering matters here. wp-admin/includes/menu.php fires
		// user_can_access_admin_page() AFTER admin_menu but BEFORE
		// admin_init — when the slug isn't registered (exactly the case
		// our gate creates), menu.php wp_die's a 403 before any admin_init
		// callback can intercept. So we hook admin_menu at PHP_INT_MAX
		// (after every other admin_menu callback has registered its pages)
		// and use admin_page_access_denied as a backstop in case any
		// future WP change reorders the dies.
		add_action( 'admin_menu', array( $this, 'maybe_redirect_module_gated_page' ), PHP_INT_MAX );
		add_action( 'admin_page_access_denied', array( $this, 'maybe_redirect_module_gated_page' ) );
		add_action( 'admin_notices', array( $this, 'maybe_render_module_gate_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Keep the Learnomy parent menu open on hidden submenu pages
		// (course editor, course builder, lesson editor, certificate designer, setup wizard).
		add_filter( 'parent_file', array( $this, 'fix_parent_file' ) );
		add_filter( 'submenu_file', array( $this, 'fix_submenu_file' ) );

		// Hide the hidden submenu pages from the sidebar via CSS.
		add_action( 'admin_head', array( $this, 'hide_hidden_submenus' ) );

		// Map the report-queue lifecycle states onto canonical badge variants so
		// UX::status_badge() colours them like every other admin status pill.
		add_filter( 'learnomy_admin_status_variants', array( $this, 'report_status_variants' ) );

		// Plan-08: render a Content Drip pointer in the lesson editor's
		// release/drip injection point when Pro isn't installed. The
		// partial self-bails when Pro is active so the real Pro panel
		// takes over without conflict.
		add_action( 'learnomy_lesson_release_settings', array( $this, 'render_lesson_drip_pro_pointer' ) );

		do_action( 'learnomy_admin_init', $this );
	}

	// ── Menu ──────────────────────────────────────────────────────────────────

	public function add_menu(): void {
		$menu_label = apply_filters( 'learnomy_admin_menu_label', __( 'Learnomy', 'learnomy' ) );
		$menu_icon  = apply_filters( 'learnomy_admin_menu_icon', \Learnomy\logo_menu_icon() );

		// Top-level menu + content-management submenus open to instructors.
		// Site-owner / system pages (Settings, Modules, Webhooks, etc.)
		// keep `manage_options`. The per-row data filtering inside each
		// `render_*` callback restricts an instructor to courses /
		// lessons / quizzes / students they own — admins still see
		// everything. (Basecamp #9919785119, #9919796872.)
		$content_cap = 'learnomy_manage_own_courses';

		add_menu_page(
			$menu_label,
			$menu_label,
			$content_cap,
			'learnomy',
			array( $this, 'render_dashboard' ),
			$menu_icon,
			30
		);

		add_submenu_page(
			'learnomy',
			__( 'Dashboard', 'learnomy' ),
			__( 'Dashboard', 'learnomy' ),
			$content_cap,
			'learnomy',
			array( $this, 'render_dashboard' )
		);

		add_submenu_page(
			'learnomy',
			\Learnomy\course_label( true ),
			\Learnomy\course_label( true ),
			$content_cap,
			'learnomy-courses',
			array( $this, 'render_courses' )
		);

		add_submenu_page(
			'learnomy',
			\Learnomy\lesson_label( true ),
			\Learnomy\lesson_label( true ),
			$content_cap,
			'learnomy-lessons',
			array( $this, 'render_lessons' )
		);

		// Module Kernel — gates every opt-in module. Required modules
		// (courses, enrollments, progress) always pass; optional modules
		// (quizzes, certificates, notifications, payments, etc.) reflect
		// what the site owner picked in the setup wizard or toggled on the
		// Modules page.
		$kernel = \Learnomy\Modules\Module_Kernel::instance();

		if ( $kernel->is_enabled( 'quizzes' ) ) {
			add_submenu_page(
				'learnomy',
				\Learnomy\quiz_label( true ),
				\Learnomy\quiz_label( true ),
				$content_cap,
				'learnomy-quizzes',
				array( $this, 'render_quizzes' )
			);

			add_submenu_page(
				'learnomy',
				__( 'Question Bank', 'learnomy' ),
				__( 'Question Bank', 'learnomy' ),
				$content_cap,
				'learnomy-questions',
				array( $this, 'render_questions' )
			);

			// Grading inbox — surfaces attempts holding at 'submitted' because
			// they contain ungraded essay (or other manually-graded) responses.
			// Without this page essay attempts never finalize for anyone working
			// in the browser. Gated by `learnomy_grade` so instructors reach it;
			// the list itself is ownership-scoped in render_quiz_grading().
			$lrn_pending_grading = $this->pending_grading_count();
			$lrn_grading_label   = __( 'Grading', 'learnomy' );
			if ( $lrn_pending_grading > 0 ) {
				$lrn_grading_label .= sprintf(
					' <span class="awaiting-mod"><span class="pending-count">%s</span></span>',
					esc_html( number_format_i18n( $lrn_pending_grading ) )
				);
			}
			add_submenu_page(
				'learnomy',
				__( 'Grading', 'learnomy' ),
				$lrn_grading_label,
				'learnomy_grade',
				'learnomy-grading',
				array( $this, 'render_quiz_grading' )
			);
		}

		add_submenu_page(
			'learnomy',
			__( 'Students', 'learnomy' ),
			__( 'Students', 'learnomy' ),
			'learnomy_view_own_students',
			'learnomy-students',
			array( $this, 'render_students' )
		);

		// Solo academy mode: the owner is the only instructor, so there is no
		// instructor roster to manage — hide the page (Basecamp #10054524045).
		if ( ! \Learnomy\solo_mode() ) {
			add_submenu_page(
				'learnomy',
				__( 'Instructors', 'learnomy' ),
				__( 'Instructors', 'learnomy' ),
				'manage_options',
				'learnomy-instructors',
				array( $this, 'render_instructors' )
			);
		}

		if ( $kernel->is_enabled( 'certificates' ) ) {
			add_submenu_page(
				'learnomy',
				__( 'Certificates', 'learnomy' ),
				__( 'Certificates', 'learnomy' ),
				'manage_options',
				'learnomy-certificates',
				array( $this, 'render_certificates' )
			);
		}

		add_submenu_page(
			'learnomy',
			\Learnomy\category_label( true ),
			\Learnomy\category_label( true ),
			'manage_options',
			'learnomy-categories',
			array( $this, 'render_categories' )
		);

		// Reviews moderation — always visible for admins so a moderator can
		// still clear the backlog even after the public review form has been
		// disabled site-wide. Gating to `enable_reviews` would strand pending
		// rows in the database with no UI to act on them.
		add_submenu_page(
			'learnomy',
			__( 'Reviews', 'learnomy' ),
			__( 'Reviews', 'learnomy' ),
			'manage_options',
			'learnomy-reviews',
			array( $this, 'render_reviews' )
		);

		// Reports queue — where member-filed reports on reviews / lesson
		// comments / courses land for a moderator to dismiss or act on. Always
		// visible for admins so the backlog is actionable; shows an open-count
		// badge so a moderator sees pending work without opening the page.
		$lrn_open_reports = \Learnomy\Models\AuditLog::count_reports( \Learnomy\Models\AuditLog::REPORT_OPEN );
		add_submenu_page(
			'learnomy',
			// 'Reported Content', not 'Reports' — this is the MODERATION queue
			// (flagged reviews/comments/courses). The bare 'Reports' label sat
			// two rows above the analytics 'Reports' section header, and the
			// same word meaning 'abuse queue' and 'analytics' in one menu is
			// exactly the collision the IA plan flagged (Basecamp 10132235933).
			// Slug unchanged - bookmarks keep resolving.
			__( 'Reported Content', 'learnomy' ),
			$lrn_open_reports > 0
				? sprintf(
					/* translators: %s: number of open reports */
					__( 'Reported Content %s', 'learnomy' ),
					'<span class="update-plugins count-' . (int) $lrn_open_reports . '"><span class="plugin-count">' . esc_html( number_format_i18n( $lrn_open_reports ) ) . '</span></span>'
				)
				: __( 'Reported Content', 'learnomy' ),
			'manage_options',
			'learnomy-reports',
			array( $this, 'render_reports' )
		);

		// Solo academy mode disables instructor registration, so no new
		// applications can arrive — hide the review page too.
		if ( ! \Learnomy\solo_mode() ) {
			add_submenu_page(
				'learnomy',
				__( 'Applications', 'learnomy' ),
				__( 'Applications', 'learnomy' ),
				'manage_options',
				'learnomy-applications',
				array( $this, 'render_applications' )
			);
		}

		if ( $kernel->is_enabled( 'notifications' ) ) {
			add_submenu_page(
				'learnomy',
				__( 'Announcements', 'learnomy' ),
				__( 'Announcements', 'learnomy' ),
				'manage_options',
				'learnomy-announcements',
				array( $this, 'render_announcements' )
			);
		}

		// Pro Tools hub — single sidebar slot that links into the 6 Pro
		// extension pages (Assignments, Cohorts, Content Drip, Learning
		// Paths, User Journeys, Spaces). The individual pages stay
		// registered so existing URLs work; the Pro side hides them from
		// the sidebar via the learnomy_admin_hidden_submenu_targets
		// filter.
		//
		// ALWAYS registered, sidebar row only when Pro is active. This used
		// to be registered only under is_pro_active(), so deactivating Pro
		// deregistered the page and WordPress answered a bookmarked hub URL
		// with 403 "You do not have permission" — a lie to an administrator
		// who has every permission and has simply switched an add-on off. The
		// honest answer already existed one layer down (pro-tools-hub.php
		// renders "No Pro extensions are registered" with a route to Modules)
		// and was unreachable (Basecamp 10132808878). Hiding rather than
		// unregistering is the same trade the extension pages already make.
		add_submenu_page(
			'learnomy',
			__( 'Pro Tools', 'learnomy' ),
			__( 'Pro Tools', 'learnomy' ),
			'manage_options',
			'learnomy-pro-tools',
			array( $this, 'render_pro_tools_hub' )
		);

		// Config + dev pages (Settings, Modules, Email Log, Webhook Events,
		// Webhooks, Pro Features) live under their own top-level menu —
		// "Learnomy Settings" — so the main menu stays focused on running the
		// academy instead of mixing day-to-day course work with system config.
		$this->register_settings_menu( $kernel );

		// Second top-level menu — Learnomy Commerce. Hosts everything related
		// to money-in / money-out (Memberships, Subscriptions, Coupons,
		// Earnings, plus Pro's Membership Analytics + Stripe Payouts). Only
		// registers when at least one commerce-shaped module is enabled —
		// otherwise the top-level menu would render empty.
		$this->register_commerce_menu( $kernel );

		do_action( 'learnomy_admin_menu_items' );

		// ── Hidden sub-pages ──────────────────────────────────────────────
		//
		// Each hidden page declares its actual parent (`learnomy` by default,
		// `learnomy-commerce` for membership-edit). They must be registered
		// under a real parent (not '') so WordPress's get_admin_page_parent()
		// resolves correctly and keeps the right top-level menu expanded.
		//
		// The pages remain in $submenu so user_can_access_admin_page() finds
		// them; they are hidden from the sidebar via the CSS emitted by
		// `hide_hidden_submenus()` below.
		foreach ( self::hidden_submenu_pages() as $slug => $config ) {
			$page_title = $config[0];
			$callback   = $config[1];
			$parent     = $config[2] ?? 'learnomy';
			// Optional 4th element overrides the default `manage_options`
			// capability. Course-creation/edit pages are instructor-accessible;
			// commerce + designer + question editor remain admin-only.
			$capability = $config[3] ?? 'manage_options';

			// Skip pages whose parent menu was not registered (e.g.
			// learnomy-membership-edit when Commerce menu is hidden because
			// no commerce module is enabled).
			if ( ! $this->parent_menu_exists( $parent ) ) {
				continue;
			}

			add_submenu_page(
				$parent,
				$page_title,
				$page_title,
				$capability,
				$slug,
				$callback
			);
		}
	}

	/**
	 * Register the Learnomy Commerce top-level menu and its child pages.
	 *
	 * The menu only appears when at least one commerce-shaped module
	 * (membership, commissions, coupons) is enabled, or when a Pro plugin
	 * forces it via the `learnomy_force_commerce_menu` filter. Children are
	 * each gated by their own module flag so the page set tracks what the
	 * site owner has turned on.
	 *
	 * @param \Learnomy\Modules\Module_Kernel $kernel Module kernel instance.
	 */
	private function register_commerce_menu( \Learnomy\Modules\Module_Kernel $kernel ): void {
		$has_commerce_module = $kernel->is_enabled( 'membership' )
			|| $kernel->is_enabled( 'commissions' )
			|| $kernel->is_enabled( 'coupons' );

		/**
		 * Allow Pro / third-party code to force the Commerce menu visible
		 * when no Free commerce module is enabled (e.g. a Pro-only commerce
		 * surface like Stripe Payouts on a site that doesn't run Free
		 * memberships).
		 *
		 * @param bool $force Default false.
		 */
		$force = apply_filters( 'learnomy_force_commerce_menu', false );

		if ( ! $has_commerce_module && ! $force ) {
			return;
		}

		// 'LMS Commerce', not 'Learnomy Commerce' — the 17-char label wrapped
		// to two sidebar rows (fits ~15 chars beside the icon). The three
		// menus stay adjacent via the scoped menu_order, so the family reads
		// without repeating the brand; 'LMS' keeps it distinct from
		// WooCommerce below and is white-label-neutral. Filter unchanged.
		$menu_label = apply_filters( 'learnomy_admin_commerce_menu_label', __( 'LMS Commerce', 'learnomy' ) );
		$menu_icon  = apply_filters( 'learnomy_admin_commerce_menu_icon', 'dashicons-cart' );

		add_menu_page(
			$menu_label,
			$menu_label,
			'manage_options',
			'learnomy-commerce',
			array( $this, 'render_commerce_overview' ),
			$menu_icon,
			30.1
		);

		// Override the auto-generated first submenu row so the URL
		// ?page=learnomy-commerce shows our overview, not a duplicate of
		// the menu title.
		add_submenu_page(
			'learnomy-commerce',
			__( 'Overview', 'learnomy' ),
			__( 'Overview', 'learnomy' ),
			'manage_options',
			'learnomy-commerce',
			array( $this, 'render_commerce_overview' )
		);

		if ( $kernel->is_enabled( 'membership' ) ) {
			add_submenu_page(
				'learnomy-commerce',
				__( 'Memberships', 'learnomy' ),
				__( 'Memberships', 'learnomy' ),
				'manage_options',
				'learnomy-memberships',
				array( $this, 'render_memberships' )
			);

			add_submenu_page(
				'learnomy-commerce',
				__( 'Subscriptions', 'learnomy' ),
				__( 'Subscriptions', 'learnomy' ),
				'manage_options',
				'learnomy-subscriptions',
				array( $this, 'render_subscriptions' )
			);
		}

		if ( $kernel->is_enabled( 'coupons' ) ) {
			add_submenu_page(
				'learnomy-commerce',
				__( 'Coupons', 'learnomy' ),
				__( 'Coupons', 'learnomy' ),
				'manage_options',
				'learnomy-coupons',
				array( $this, 'render_coupons' )
			);
		}

		if ( $kernel->is_enabled( 'commissions' ) ) {
			add_submenu_page(
				'learnomy-commerce',
				__( 'Earnings', 'learnomy' ),
				__( 'Earnings', 'learnomy' ),
				'manage_options',
				'learnomy-earnings',
				array( $this, 'render_earnings' )
			);
		}

		// Transactions list — always available so admin can refund regardless
		// of which commerce modules are enabled.
		add_submenu_page(
			'learnomy-commerce',
			__( 'Transactions', 'learnomy' ),
			__( 'Transactions', 'learnomy' ),
			'manage_options',
			'learnomy-transactions',
			array( $this, 'render_transactions' )
		);

		// Pro hooks into this action to register Membership Analytics and
		// Stripe Payouts under the Commerce parent.
		do_action( 'learnomy_admin_commerce_menu_items' );
	}

	/**
	 * Register the third top-level menu — "Learnomy Settings" — and move every
	 * config / dev page under it (Settings, Modules, Email Log, Webhook Events,
	 * Webhooks, Pro Features). Page slugs are unchanged, so every `?page=…`
	 * bookmark and the module-gating map keep working; only the parent menu
	 * (and therefore which top-level highlights) moves.
	 *
	 * The top-level slug IS `learnomy-settings` (the Settings page), so the
	 * menu opens straight onto Settings and the first submenu row is relabelled
	 * from the menu title to "Settings".
	 *
	 * @param \Learnomy\Modules\Module_Kernel $kernel Module kernel instance.
	 */
	private function register_settings_menu( \Learnomy\Modules\Module_Kernel $kernel ): void {
		// 'LMS Settings' for the same one-row reason as LMS Commerce — and
		// never bare 'Settings', which would collide with WP core's own
		// top-level Settings menu further down the sidebar.
		$menu_label = apply_filters( 'learnomy_admin_settings_menu_label', __( 'LMS Settings', 'learnomy' ) );
		$menu_icon  = apply_filters( 'learnomy_admin_settings_menu_icon', 'dashicons-admin-settings' );

		add_menu_page(
			$menu_label,
			$menu_label,
			'manage_options',
			'learnomy-settings',
			array( $this, 'render_settings' ),
			$menu_icon,
			30.2
		);

		// Relabel the auto-generated first row (it would otherwise repeat the
		// menu title) to "Settings".
		add_submenu_page(
			'learnomy-settings',
			__( 'Settings', 'learnomy' ),
			__( 'Settings', 'learnomy' ),
			'manage_options',
			'learnomy-settings',
			array( $this, 'render_settings' )
		);

		add_submenu_page(
			'learnomy-settings',
			__( 'Modules', 'learnomy' ),
			__( 'Modules', 'learnomy' ),
			'manage_options',
			'learnomy-modules',
			array( $this, 'render_modules' )
		);

		// Import — migrate courses/lessons/quizzes from another LMS (LearnDash,
		// Tutor, LifterLMS). Content-only; never touches money.
		add_submenu_page(
			'learnomy-settings',
			__( 'Import', 'learnomy' ),
			__( 'Import', 'learnomy' ),
			'manage_options',
			'learnomy-import',
			array( $this, 'render_import' )
		);

		// Email Log — only when notifications are on.
		if ( $kernel->is_enabled( 'notifications' ) ) {
			add_submenu_page(
				'learnomy-settings',
				__( 'Email Log', 'learnomy' ),
				__( 'Email Log', 'learnomy' ),
				'manage_options',
				'learnomy-email-log',
				array( $this, 'render_email_log' )
			);
		}

		// Payment Webhook Log — inbound payment-provider log; only with
		// payments on. Renamed from 'Webhook Events': it sat directly beside
		// 'Webhooks' (outgoing config) and the near-twin labels made owners
		// open the wrong one (IA plan P1.2). Slug unchanged.
		if ( $kernel->is_enabled( 'payments' ) ) {
			add_submenu_page(
				'learnomy-settings',
				__( 'Payment Webhook Log', 'learnomy' ),
				__( 'Payment Webhook Log', 'learnomy' ),
				'manage_options',
				'learnomy-webhook-events',
				array( $this, 'render_webhook_events' )
			);
		}

		// Outgoing webhooks — developer surface for syncing lifecycle events.
		// Label says 'Outgoing' so it cannot be confused with the inbound
		// Payment Webhook Log beside it (IA plan P1.2). Slug unchanged.
		add_submenu_page(
			'learnomy-settings',
			__( 'Outgoing Webhooks', 'learnomy' ),
			__( 'Outgoing Webhooks', 'learnomy' ),
			'manage_options',
			'learnomy-webhooks',
			array( $this, 'render_webhooks' )
		);

		// Integrations — the Academy Stack companions (MediaShield, Jetonomy,
		// WB Gamification, …): detect / one-click install / upgrade. Each works
		// standalone; this screen only reflects status + triggers installs.
		// 'Companion Plugins', not 'Integrations' — the word 'Integrations'
		// was carrying three different jobs across the admin (this companions
		// screen, the Learning Standards settings group, the Pro-catalog
		// category), so no owner could predict what any of them opened (IA
		// plan P0.3). This screen is literally the companion-plugin
		// installer, so the label now says that. Slug unchanged.
		add_submenu_page(
			'learnomy-settings',
			__( 'Companion Plugins', 'learnomy' ),
			__( 'Companion Plugins', 'learnomy' ),
			'manage_options',
			'learnomy-integrations',
			array( $this, 'render_integrations' )
		);

		// "What's in Pro" — informational catalog (renders on Free-only
		// installs from the bundled snapshot; no dependency on the Pro
		// plugin). Renamed from 'Pro Features': beside 'Modules' it read as a
		// second toggle screen, when it is a read-only catalog — the label now
		// says which one browses and which one switches (IA plan P1.1). Slug
		// unchanged.
		add_submenu_page(
			'learnomy-settings',
			__( "What's in Pro", 'learnomy' ),
			__( "What's in Pro", 'learnomy' ),
			'manage_options',
			'learnomy-pro-features',
			array( $this, 'render_pro_features' )
		);

		/**
		 * Pro / third-party config pages can register under the Settings menu
		 * here (mirrors learnomy_admin_menu_items + _commerce_menu_items).
		 */
		do_action( 'learnomy_admin_settings_menu_items' );
	}

	/**
	 * Force the three Learnomy top-level menus to render as one contiguous
	 * block. Returns the menu order with `learnomy-commerce` + `learnomy-settings`
	 * relocated to sit immediately after `learnomy`, at the slot `learnomy`
	 * already occupies. Every other plugin's menu keeps its relative order, so
	 * this is a surgical regroup, not a global reshuffle.
	 *
	 * @param array<int,string> $menu_order Ordered top-level menu slugs.
	 * @return array<int,string>
	 */
	public function group_top_level_menus( array $menu_order ): array {
		$ours = array( 'learnomy', 'learnomy-commerce', 'learnomy-settings' );

		// The ones actually present, in our canonical order.
		$block = array_values( array_filter( $ours, static fn( $s ) => in_array( $s, $menu_order, true ) ) );
		if ( count( $block ) < 2 || ! in_array( 'learnomy', $menu_order, true ) ) {
			return $menu_order; // Nothing to regroup.
		}

		// Everything that isn't ours, original order preserved.
		$rest = array_values( array_filter( $menu_order, static fn( $s ) => ! in_array( $s, $ours, true ) ) );

		// Insert our block where `learnomy` originally sat (counting only the
		// non-ours items that preceded it, so the block lands in the same spot).
		$before = 0;
		foreach ( $menu_order as $slug ) {
			if ( 'learnomy' === $slug ) {
				break;
			}
			if ( ! in_array( $slug, $ours, true ) ) {
				++$before;
			}
		}

		array_splice( $rest, $before, 0, $block );
		return $rest;
	}

	/**
	 * Map of module-gated admin slugs → the kernel module each requires.
	 *
	 * Used by:
	 *   - maybe_redirect_module_gated_page() to recover stale bookmarks
	 *   - tests/Unit/Admin/AdminPageModuleGatingTest.php (contract)
	 *
	 * Slugs not in this map are either always-on (Dashboard, Modules,
	 * Settings, etc.) or rely on a different gate (e.g. capability).
	 *
	 * @return array<string, string> slug => required module slug.
	 */
	public static function module_gated_admin_slugs(): array {
		$map = array(
			'learnomy-memberships'    => 'membership',
			'learnomy-subscriptions'  => 'membership',
			'learnomy-coupons'        => 'coupons',
			'learnomy-earnings'       => 'commissions',
			'learnomy-webhook-events' => 'payments',
			// Were missing, so these three still met WordPress's bare "Sorry,
			// you are not allowed to access this page." - which blames the
			// owner for a choice they made themselves. Switching a module off
			// is configuration, not a permission failure, and it is never
			// licence-gated: the recovery screen is the correct answer for
			// every one of them (Basecamp 10184590411 follow-up).
			'learnomy-announcements'  => 'notifications',
			'learnomy-commissions'    => 'commissions',
			'learnomy-quizzes'        => 'quizzes',
			'learnomy-certificates'   => 'certificates',
			// Email Log is gated by the same module as Announcements and was
			// left out of that earlier sweep, so it kept answering the bare
			// permission error while its neighbour recovered gracefully -
			// measured with notifications off: email-log 403, announcements
			// 200 at the recovery screen. Found by the 1.9.2 retag smoke.
			'learnomy-email-log'      => 'notifications',
		);

		/**
		 * Admin pages that disappear when their gating module is switched off.
		 *
		 * Pro joins here rather than re-implementing the recovery screen: an
		 * extension adds `slug => gate-key` and answers for that key on
		 * `learnomy_module_gate_enabled` below.
		 *
		 * @param array<string, string> $map Page slug => gate key.
		 */
		return (array) apply_filters( 'learnomy_module_gated_admin_slugs', $map );
	}

	/**
	 * Is the thing gating this page currently switched on?
	 *
	 * Free answers from the module kernel. Pro extensions are NOT modules - the
	 * kernel returns false for every extension slug, including enabled ones - so
	 * the decision is filtered, letting each side answer for keys it owns
	 * without Free learning what a Pro extension is.
	 *
	 * @param string $gate_key Module slug, or an extension key supplied via the map filter.
	 */
	private function module_gate_is_enabled( string $gate_key ): bool {
		$enabled = \Learnomy\Modules\Module_Kernel::instance()->is_enabled( $gate_key );

		/**
		 * Final say on whether a gated admin page's owner is active.
		 *
		 * @param bool   $enabled  Whether Free's module kernel considers it on.
		 * @param string $gate_key The key from the gated-slug map.
		 */
		return (bool) apply_filters( 'learnomy_module_gate_enabled', $enabled, $gate_key );
	}

	/**
	 * SS-2: recover the user when they reach a module-gated commerce page
	 * via a stale bookmark or external link.
	 *
	 * Without this guard, WordPress emits its native "Sorry, you are not
	 * allowed to access this page." with no breadcrumb back to enabling
	 * the gating module — the super-admin walks away thinking the plugin
	 * is broken (the first-impression disaster called out in
	 * `plan/2026-05-16-token-ready-sweep/customer-journey-walk.md` SS-2).
	 *
	 * With this guard, the same URL redirects to the Modules screen with
	 * a notice explaining which module unlocks the requested page.
	 *
	 * Runs only when:
	 *   - on an admin page
	 *   - GET parameter `page` matches a module-gated slug
	 *   - the gating module is currently disabled
	 *   - current user can manage Learnomy (else WP's own auth handles it)
	 *
	 * Adds `learnomy_module_required=<slug>` to the redirect URL so the
	 * notice renderer can name the module in plain English.
	 */
	public function maybe_redirect_module_gated_page(): void {
		if ( ! is_admin() ) {
			return;
		}

		// Skip AJAX, REST, cron — only catch real screen loads. wp_doing_ajax()
		// + DOING_CRON cover the common cases; REST never sets is_admin() true
		// for our purposes here.
		if ( wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( '' === $page ) {
			return;
		}

		$gated = self::module_gated_admin_slugs();

		// The Commerce overview is a special case: it doesn't have a single
		// gating module — it's the landing page for the Commerce parent
		// menu, which Free's register_commerce_menu() shows when ANY of
		// membership / commissions / coupons is enabled. So the redirect
		// only fires when ALL three are off. We pick `membership` as the
		// notice target because it's the most common first-enable.
		$is_commerce_overview = ( 'learnomy-commerce' === $page );
		if ( ! isset( $gated[ $page ] ) && ! $is_commerce_overview ) {
			return;
		}

		// Only act for users who could reach the page if the module were on.
		// Anyone else gets the standard WP capability error, which is correct.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$kernel = \Learnomy\Modules\Module_Kernel::instance();

		if ( $is_commerce_overview ) {
			$has_any = $kernel->is_enabled( 'membership' )
				|| $kernel->is_enabled( 'commissions' )
				|| $kernel->is_enabled( 'coupons' );
			if ( $has_any ) {
				return;
			}
			// A Pro extension (Spaces, Stripe Connect, Membership Pro) can force
			// the Commerce menu visible with no Free commerce module enabled.
			// Honour the same filter the menu uses so a deliberately-surfaced
			// Commerce item does not bounce straight back to Modules.
			if ( apply_filters( 'learnomy_force_commerce_menu', false ) ) {
				return;
			}
			$required_module = 'membership';
		} else {
			$required_module = $gated[ $page ];
			if ( $this->module_gate_is_enabled( $required_module ) ) {
				return;
			}
		}

		$redirect = add_query_arg(
			array(
				'page'                     => 'learnomy-modules',
				'learnomy_module_required' => $required_module,
				'learnomy_from_slug'       => $page,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Render the inline notice on the Modules page when a redirect from
	 * a gated slug brought the user here. Pairs with
	 * maybe_redirect_module_gated_page() above.
	 */
	public function maybe_render_module_gate_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['learnomy_module_required'] ) || empty( $_GET['page'] ) ) {
			return;
		}

		if ( 'learnomy-modules' !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}

		$module    = sanitize_key( wp_unslash( $_GET['learnomy_module_required'] ) );
		$from_slug = sanitize_key( wp_unslash( $_GET['learnomy_from_slug'] ?? '' ) );
		// phpcs:enable

		// Resolve human label for the module from the catalog. Falling back
		// to the raw slug keeps the notice useful if a future module isn't
		// registered in the catalog yet.
		$catalog      = \Learnomy\Modules\Module_Kernel::catalog();
		$module_label = isset( $catalog[ $module ]['name'] )
			? (string) $catalog[ $module ]['name']
			: $module;

		// Map the originating slug to a plain-English page label so the user
		// sees what they were trying to reach, not just the URL slug.
		$slug_labels = array(
			'learnomy-commerce'       => __( 'Commerce', 'learnomy' ),
			'learnomy-memberships'    => __( 'Memberships', 'learnomy' ),
			'learnomy-subscriptions'  => __( 'Subscriptions', 'learnomy' ),
			'learnomy-coupons'        => __( 'Coupons', 'learnomy' ),
			'learnomy-earnings'       => __( 'Earnings', 'learnomy' ),
			'learnomy-webhook-events' => __( 'Payment Webhook Log', 'learnomy' ),
			'learnomy-announcements'  => __( 'Announcements', 'learnomy' ),
			'learnomy-commissions'    => __( 'Commissions', 'learnomy' ),
			// Renamable noun - a site that calls them Assessments must read
			// "The Assessments page is part of..." (coding-rules Rule 22).
			'learnomy-quizzes'        => \Learnomy\quiz_label( true ),
			'learnomy-certificates'   => __( 'Certificates', 'learnomy' ),
			'learnomy-email-log'      => __( 'Email Log', 'learnomy' ),
		);

		/**
		 * Plain-English names for gated pages, so the recovery notice says what
		 * the owner was trying to open rather than echoing a URL slug. Pro adds
		 * its own alongside its map entries.
		 *
		 * @param array<string, string> $slug_labels Page slug => label.
		 */
		$slug_labels = (array) apply_filters( 'learnomy_module_gated_page_labels', $slug_labels );
		$page_label  = $slug_labels[ $from_slug ] ?? $from_slug;

		/**
		 * Plain-English name for the thing that gates the page. Free resolves
		 * module names from the catalog; a Pro extension key is not in it, so
		 * the owner would otherwise read a raw slug in the sentence.
		 *
		 * @param string $module_label Resolved label (or the raw key).
		 * @param string $module       The gate key.
		 */
		$module_label = (string) apply_filters( 'learnomy_module_gate_label', $module_label, $module );

		// The Commerce overview is a parent menu — its lifetime is gated on
		// ANY of the three commerce modules being on, not just one. Use a
		// different message so the user understands they have a choice.
		if ( 'learnomy-commerce' === $from_slug ) {
			$message = esc_html__(
				'Commerce tools become available once you turn on any of the commerce modules below (Membership, Coupons, or Commissions).',
				'learnomy'
			);
		} else {
			$message = esc_html(
				sprintf(
					/* translators: 1: requested page label, 2: module name */
					__( 'The %1$s page is part of the %2$s module. Enable the module below to access it.', 'learnomy' ),
					$page_label,
					$module_label
				)
			);
		}

		printf( '<div class="notice notice-info"><p>%s</p></div>', $message ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Whether a top-level menu slug has already been registered.
	 *
	 * @param string $slug Top-level menu slug (e.g. 'learnomy', 'learnomy-commerce').
	 */
	private function parent_menu_exists( string $slug ): bool {
		global $menu;

		if ( empty( $menu ) || ! is_array( $menu ) ) {
			return false;
		}

		foreach ( $menu as $item ) {
			if ( isset( $item[2] ) && $item[2] === $slug ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Group the Learnomy submenu into two visual sections: Manage and System.
	 * Inserts non-clickable section header rows between the groups so the
	 * operator scans logical groups instead of one long list.
	 *
	 * Commerce items live under their own top-level menu (`learnomy-commerce`)
	 * and don't appear in this submenu, so we no longer render a Commerce
	 * section header here.
	 *
	 * Hooked on `admin_menu` priority 99 — runs after both Free and Pro have
	 * registered all their submenu items. Pure presentational reorder: zero
	 * URL changes, capability resolution unchanged, hidden pages stay hidden
	 * (the existing CSS in `hide_hidden_submenus()` still hides them).
	 *
	 * Slugs that aren't in the group map are appended after System without a
	 * header, so future Pro extensions never silently disappear — add the new
	 * slug to the map below to assign it to a group.
	 */
	public function reorder_submenu_into_sections(): void {
		// Main Learnomy menu — grouped into scannable sections instead of one
		// long flat list. All maps/sections are filterable (see regroup_submenu).
		$this->regroup_submenu(
			'learnomy',
			array(
				'learnomy'                => 'dashboard',
				'learnomy-courses'        => 'content',
				'learnomy-lessons'        => 'content',
				'learnomy-quizzes'        => 'content',
				'learnomy-questions'      => 'content',
				'learnomy-categories'     => 'content',
				'learnomy-students'       => 'people',
				'learnomy-instructors'    => 'people',
				'learnomy-applications'   => 'people',
				'learnomy-announcements'  => 'engagement',
				'learnomy-reviews'        => 'engagement',
				'learnomy-reports'        => 'engagement',
				'learnomy-certificates'   => 'engagement',
				'learnomy-analytics'      => 'reports',
				'learnomy-grading'        => 'reports',
				'learnomy-assignments'    => 'pro',
				'learnomy-cohorts'        => 'pro',
				'learnomy-content-drip'   => 'pro',
				'learnomy-learning-paths' => 'pro',
				'learnomy-user-journeys'  => 'pro',
				'learnomy-pro-tools'      => 'pro',
				// Pro pages that used to fall into the unlabeled 'other'
				// bucket — a 7-row anonymous dump at the end of the menu with
				// all extensions on (admin IA plan 2026-07-25, Basecamp
				// 10132235933). Gradebook joins Reports (a daily grading
				// surface); the rest live under Extensions and are hub-only
				// (sidebar rows hidden, Pro Tools cards are their homes) — the
				// mapping matters only when a site unhides them via the
				// learnomy_admin_hidden_submenu_targets filter, so they land
				// in a LABELLED section, never the anonymous dump. Mapping a
				// slug that isn't registered (extension off) is a no-op.
				'learnomy-gradebook'      => 'reports',
				'learnomy-rubric-grading' => 'reports',
				'learnomy-scorm-tracking' => 'pro',
				'learnomy-qti-import'     => 'pro',
				'learnomy-lti-platforms'  => 'pro',
				'learnomy-milestones'     => 'pro',
				'learnomy-skills'         => 'pro',
			),
			array(
				'dashboard'  => '',
				'content'    => __( 'Content', 'learnomy' ),
				'people'     => __( 'People', 'learnomy' ),
				'engagement' => __( 'Engagement', 'learnomy' ),
				'reports'    => __( 'Reports', 'learnomy' ),
				// 'Extensions', not 'Pro Tools': the hub ITEM is titled Pro
				// Tools, and a section header repeating its own child's label
				// read as a double menu row (IA plan P1.6).
				'pro'        => __( 'Extensions', 'learnomy' ),
				'other'      => '',
			)
		);

		// Commerce menu — selling, money, spaces, and revenue reporting.
		$this->regroup_submenu(
			'learnomy-commerce',
			array(
				'learnomy-commerce'                => 'overview',
				'learnomy-memberships'             => 'sell',
				'learnomy-subscriptions'           => 'sell',
				'learnomy-coupons'                 => 'sell',
				'learnomy-space-plans'             => 'sell',
				'learnomy-earnings'                => 'money',
				'learnomy-transactions'            => 'money',
				'learnomy-refundable-transactions' => 'money',
				'learnomy-stripe-payouts'          => 'money',
				'learnomy-membership-analytics'    => 'reports',
				'learnomy-spaces'                  => 'spaces',
			),
			array(
				'overview' => '',
				'sell'     => __( 'Sell', 'learnomy' ),
				'money'    => __( 'Money', 'learnomy' ),
				// 'Teams', not '' and not 'Spaces'. The header was blanked because
				// its only child is also called Spaces and the pair read as a
				// duplicate row (IA plan P1.6) - but a section with no header
				// does not read as unlabelled, it reads as PART OF THE ONE
				// ABOVE. So Learning Spaces sat directly under MONEY, beside
				// Transactions, telling an owner that managing their teams and
				// seats is a ledger activity (Basecamp 10184391893).
				//
				// It is the wrong neighbourhood twice over: Learning Spaces is
				// daily operations - add a location, assign a manager, move
				// seats - often done by someone with no access to the money
				// side at all, while Transactions is an occasional financial
				// read. And CAPABILITIES.md leads with Spaces as THE B2B/teams
				// seat product, which the menu was presenting as an appendix to
				// the accounts.
				//
				// 'Teams' names the section without twinning its child, so the
				// header can exist at all. Space Plans stays under Sell: it is
				// the thing you sell, and Learning Spaces is the thing you then
				// run.
				'spaces'   => __( 'Teams', 'learnomy' ),
				'reports'  => __( 'Reports', 'learnomy' ),
				'other'    => '',
			)
		);

		// LMS Settings was the one top-level menu left as a flat list of seven
		// unrelated rows (IA plan P2.1, Basecamp 10184391878): the settings
		// screen, a bulk importer, two logs, a plugin installer and a Pro
		// catalogue, in one undifferentiated column. Its two siblings have been
		// sectioned since the July IA pass, so an owner learns the grouping
		// habit from Learnomy and LMS Commerce and then meets a menu that does
		// not follow it.
		//
		// Three groups because there are three genuinely different reasons to
		// open this menu: CONFIGURE the product, READ what it did, or GET more
		// of it. Import sits under Configure rather than beside the logs - it is
		// a one-off setup action, not a record of anything.
		$this->regroup_submenu(
			'learnomy-settings',
			array(
				'learnomy-settings'       => 'configure',
				'learnomy-modules'        => 'configure',
				'learnomy-import'         => 'configure',
				'learnomy-webhook-events' => 'logs',
				'learnomy-webhooks'       => 'logs',
				'learnomy-integrations'   => 'grow',
				'learnomy-pro-features'   => 'grow',
			),
			array(
				'configure' => __( 'Configure', 'learnomy' ),
				'logs'      => __( 'Logs', 'learnomy' ),
				'grow'      => __( 'Add More', 'learnomy' ),
				'other'     => '',
			)
		);
	}

	/**
	 * Group a top-level menu's submenu into labelled sections.
	 *
	 * Generic + fully filterable so developers can slot a page into any section,
	 * or define new sections, for ANY of our menus:
	 *
	 *   // Put a custom page in the People section of the main menu:
	 *   add_filter( 'learnomy_admin_menu_section_map', function ( $map, $parent ) {
	 *       if ( 'learnomy' === $parent ) { $map['my-page'] = 'people'; }
	 *       return $map;
	 *   }, 10, 2 );
	 *
	 *   // Add a whole new section (key => label; '' label = no header row):
	 *   add_filter( 'learnomy_admin_menu_sections', function ( $order, $parent ) {
	 *       if ( 'learnomy' === $parent ) { $order['integrations'] = __( 'Integrations', 'learnomy' ); }
	 *       return $order;
	 *   }, 10, 2 );
	 *
	 * Unmapped slugs land in the header-less 'other' bucket (nothing is ever
	 * dropped); hidden pages keep their place at the end (CSS hides them).
	 *
	 * @param string               $parent      Top-level menu slug.
	 * @param array<string,string> $groups      Default slug -> section-key map.
	 * @param array<string,string> $group_order Default ordered section-key -> label.
	 */
	private function regroup_submenu( string $parent, array $groups, array $group_order ): void {
		global $submenu;

		if ( empty( $submenu[ $parent ] ) ) {
			return;
		}

		/**
		 * Filter the slug -> section-key map for a menu. $parent says which menu.
		 *
		 * @param array<string,string> $groups Slug -> section-key.
		 * @param string               $parent Top-level menu slug.
		 */
		$groups = (array) apply_filters( 'learnomy_admin_menu_section_map', $groups, $parent );

		/**
		 * Filter the ordered section-key -> label list for a menu. Controls both
		 * section order and labels (an empty label renders no header row).
		 *
		 * @param array<string,string> $group_order Section-key -> label, in order.
		 * @param string               $parent      Top-level menu slug.
		 */
		$group_order = (array) apply_filters( 'learnomy_admin_menu_sections', $group_order, $parent );

		// Always keep a header-less catch-all so a custom/unmapped slug is never
		// dropped even if a filter removed 'other'.
		if ( ! array_key_exists( 'other', $group_order ) ) {
			$group_order['other'] = '';
		}

		/*
		 * BOTH hiding mechanisms, or a section keeps a header over nothing.
		 *
		 * hidden_submenu_pages() is the routable-but-unlisted set (editors,
		 * designers). hidden_submenu_targets() is what the CSS emitter blanks,
		 * and it carries entries the first list has never heard of — the Pro Tools
		 * hub on a Free-only site, and anything a Pro extension hides through
		 * `learnomy_admin_hidden_submenu_targets`.
		 *
		 * Reading only the first list bucketed the Pro Tools row as visible, so the
		 * "Extensions" section counted one child and printed its header — then the
		 * CSS hid the link underneath it. A Free-only owner, which is most of them,
		 * got a section label with nothing under it.
		 */
		$hidden_slugs      = array_unique(
			array_merge(
				array_keys( $this->hidden_submenu_pages() ),
				array_keys( $this->hidden_submenu_targets() )
			)
		);
		$buckets           = array_fill_keys( array_keys( $group_order ), array() );
		$buckets['hidden'] = array();

		foreach ( $submenu[ $parent ] as $item ) {
			// $item is [ menu_title, capability, menu_slug, page_title (optional) ].
			$slug = $item[2] ?? '';

			if ( in_array( $slug, $hidden_slugs, true ) ) {
				$buckets['hidden'][] = $item;
				continue;
			}

			$group = $groups[ $slug ] ?? 'other';
			if ( ! isset( $buckets[ $group ] ) ) {
				$group = 'other';
			}
			$buckets[ $group ][] = $item;
		}

		// Rebuild in section order, emitting a header before each labelled,
		// non-empty section. Empty-label sections render flush; hidden pages
		// stay last (invisible but must remain in $submenu for page resolution).
		$rebuilt = array();
		foreach ( $group_order as $group => $label ) {
			if ( empty( $buckets[ $group ] ) ) {
				continue;
			}
			if ( '' !== (string) $label ) {
				// Anchor is namespaced by parent so headers in different menus
				// never collide.
				$rebuilt[] = $this->build_section_header( $parent . '-' . $group, (string) $label );
			}
			$rebuilt = array_merge( $rebuilt, $buckets[ $group ] );
		}
		$rebuilt = array_merge( $rebuilt, $buckets['hidden'] );

		$submenu[ $parent ] = $rebuilt;
	}

	/**
	 * Build a non-clickable section header row for the submenu.
	 *
	 * The href is a no-op anchor (`#lrn-section-{slug}`) so clicking it does
	 * nothing beyond changing the URL hash. CSS in `hide_hidden_submenus()`
	 * styles the row as a small-caps label and disables hover.
	 *
	 * @param string $slug  Section identifier (manage / commerce / system).
	 * @param string $label Translated section label.
	 * @return array<int, string> WordPress submenu row tuple.
	 */
	private function build_section_header( string $slug, string $label ): array {
		return array(
			'<span class="lrn-menu-section">' . esc_html( $label ) . '</span>',
			'manage_options',
			'#lrn-section-' . $slug,
			esc_html( $label ),
		);
	}

	/**
	 * The hidden submenu pages — registered under their declared parent but
	 * not shown in the sidebar.
	 *
	 * Each entry is `[ page_title, callback, parent_slug? ]`. `parent_slug`
	 * defaults to `learnomy`. Membership Plan editing lives under the
	 * Commerce top-level menu (`learnomy-commerce`).
	 *
	 * @return array<string, array{0: string, 1: callable, 2?: string}>
	 */
	private function hidden_submenu_pages(): array {
		// 4th element: optional capability override (defaults to manage_options).
		//
		// Course-creation, course-edit, course-builder and lesson-editor are
		// instructor workflows — keeping them at manage_options locked out
		// every non-admin instructor with "Sorry, you are not allowed to
		// access this page." (bug cards #9,10,11). Granting access via
		// `learnomy_create_course` / `learnomy_manage_own_courses` lets
		// instructors reach the page; the per-resource ownership check
		// (course author == current_user_id) lives inside each render_*
		// callback so admins still see all courses while instructors only
		// see their own.
		//
		// Quiz + question editors are open to instructors for their OWN content
		// (cap learnomy_manage_own_courses, same as the course/lesson editors).
		// The render methods enforce per-resource ownership for non-admins, and
		// the REST controllers already gate writes by ownership. Membership +
		// certificate designer stay admin-only by design.
		return array(
			'learnomy-new-course'           => array( sprintf( /* translators: %s: singular Course label */ __( 'Add New %s', 'learnomy' ), \Learnomy\course_label() ), array( $this, 'render_new_course' ), 'learnomy', 'learnomy_create_course' ),
			'learnomy-edit-course'          => array( sprintf( /* translators: %s: singular Course label */ __( 'Edit %s', 'learnomy' ), \Learnomy\course_label() ), array( $this, 'render_edit_course' ), 'learnomy', 'learnomy_manage_own_courses' ),
			'learnomy-course-builder'       => array( sprintf( /* translators: %s: singular Course label */ __( '%s Builder', 'learnomy' ), \Learnomy\course_label() ), array( $this, 'render_course_builder' ), 'learnomy', 'learnomy_manage_own_courses' ),
			'learnomy-lesson-editor'        => array( sprintf( /* translators: %s: singular Lesson label */ __( 'Edit %s', 'learnomy' ), \Learnomy\lesson_label() ), array( $this, 'render_lesson_editor' ), 'learnomy', 'learnomy_manage_own_courses' ),
			'learnomy-edit-quiz'            => array( sprintf( /* translators: %s: singular Quiz label */ __( 'Edit %s', 'learnomy' ), \Learnomy\quiz_label() ), array( $this, 'render_quiz_editor' ), 'learnomy', 'learnomy_manage_own_courses' ),
			'learnomy-question-editor'      => array( __( 'Edit Question', 'learnomy' ), array( $this, 'render_question_editor' ), 'learnomy', 'learnomy_manage_own_courses' ),
			'learnomy-setup'                => array( __( 'Learnomy Setup', 'learnomy' ), array( $this, 'render_setup' ) ),
			'learnomy-certificate-designer' => array( __( 'Certificate Designer', 'learnomy' ), array( $this, 'render_certificate_designer' ) ),
			'learnomy-membership-edit'      => array( __( 'Edit Membership Plan', 'learnomy' ), array( $this, 'render_membership_edit' ), 'learnomy-commerce' ),
			'learnomy-instructor-detail'    => array( __( 'Instructor', 'learnomy' ), array( $this, 'render_instructor_detail' ), 'learnomy', 'manage_options' ),
			'learnomy-student-detail'       => array( __( 'Student', 'learnomy' ), array( $this, 'render_student_detail' ), 'learnomy', 'learnomy_view_own_students' ),
			'learnomy-course-detail'        => array( \Learnomy\course_label(), array( $this, 'render_course_detail' ), 'learnomy', 'manage_options' ),
			'learnomy-subscription-detail'  => array( __( 'Subscription', 'learnomy' ), array( $this, 'render_subscription_detail' ), 'learnomy-commerce', 'manage_options' ),
			'learnomy-transaction-detail'   => array( __( 'Transaction', 'learnomy' ), array( $this, 'render_transaction_detail' ), 'learnomy-commerce', 'manage_options' ),
			'learnomy-withdrawal-detail'    => array( __( 'Withdrawal', 'learnomy' ), array( $this, 'render_withdrawal_detail' ), 'learnomy-commerce', 'manage_options' ),
			'learnomy-coupon-detail'        => array( __( 'Coupon', 'learnomy' ), array( $this, 'render_coupon_detail' ), 'learnomy-commerce', 'manage_options' ),
			'learnomy-application-detail'   => array( __( 'Application', 'learnomy' ), array( $this, 'render_application_detail' ), 'learnomy', 'manage_options' ),
		);
	}

	/**
	 * Slugs that should be hidden from the sidebar but still routable.
	 *
	 * Free's own hidden pages (course builder, lesson editor, etc.) seed
	 * the list. Pro extensions add their slugs via the
	 * `learnomy_admin_hidden_submenu_targets` filter so the Pro Tools hub
	 * can collapse 6 extension pages into a single sidebar item without
	 * unregistering their URLs.
	 *
	 * @return array<string, string> slug => parent menu slug.
	 */
	/**
	 * Is a submenu row registered FOR THE CURRENT USER?
	 *
	 * `$GLOBALS['submenu']` only ever contains rows whose capability the
	 * current user satisfies, so presence here is the honest answer to "can
	 * this person reach that page from the sidebar" — no second capability
	 * table to keep in step with the one `add_submenu_page()` already used.
	 */
	private static function submenu_row_exists( string $slug ): bool {
		foreach ( (array) ( $GLOBALS['submenu'] ?? array() ) as $rows ) {
			foreach ( (array) $rows as $row ) {
				if ( isset( $row[2] ) && $slug === $row[2] ) {
					return true;
				}
			}
		}

		return false;
	}

	private function hidden_submenu_targets(): array {
		$targets = array();
		foreach ( $this->hidden_submenu_pages() as $slug => $config ) {
			$targets[ $slug ] = $config[2] ?? 'learnomy';
		}

		// Free-only site: the Pro Tools hub keeps its URL (so a bookmark
		// answers with its own empty state instead of 403) but takes no
		// sidebar row — an owner without Pro should not carry a menu item
		// for tools they do not have. "What's in Pro" is the upsell surface.
		if ( ! $this->is_pro_active() ) {
			$targets['learnomy-pro-tools'] = 'learnomy';
		}

		/**
		 * Allow Pro / third-party code to hide additional submenu pages
		 * from the sidebar without unregistering them. Each entry is
		 * `slug => parent_menu_slug`.
		 *
		 * @param array<string, string> $targets Existing slug → parent map.
		 */
		$filtered = apply_filters( 'learnomy_admin_hidden_submenu_targets', $targets );

		return is_array( $filtered ) ? $filtered : $targets;
	}

	/**
	 * Hide the registered hidden submenu pages from the sidebar via CSS.
	 *
	 * Using display:none in admin_head runs AFTER user_can_access_admin_page()
	 * so permission checks still pass. The $submenu entries remain intact.
	 */
	public function hide_hidden_submenus(): void {
		$selectors = array();
		foreach ( $this->hidden_submenu_targets() as $slug => $parent ) {
			// A row may declare the page that REPLACES it as a discovery
			// route — `slug => 'parent|hub-slug'`. Hiding it is only honest
			// when the current user can actually reach that hub.
			//
			// Without this the rule was blanket CSS: the Pro Tools hub
			// requires `manage_options`, so WordPress never gives an
			// instructor that row, while the CSS still hid Assignments,
			// Content Drip, Learning Paths and Grade Responses from them.
			// Those pages load fine for an instructor (200, no capability
			// denial) — they were simply unreachable from anywhere in the
			// admin unless somebody sent them a URL. Hiding a row is a
			// re-routing, and a re-route to a door you cannot open is a
			// dead end.
			$hub = '';
			if ( is_string( $parent ) && str_contains( $parent, '|' ) ) {
				list( $parent, $hub ) = array_pad( explode( '|', $parent, 2 ), 2, '' );
			}

			if ( '' !== $hub && ! self::submenu_row_exists( $hub ) ) {
				continue;
			}

			// Tolerate the value-append shape ($targets[] = 'slug') a filter
			// consumer may use: a numeric key means the VALUE is the slug and
			// the parent defaults to the main menu. Without this, that shape
			// silently produced a garbage `page=0` selector and the page
			// stayed visible (advanced-quizzes rubric grading did exactly
			// that until 2026-07-25).
			if ( is_int( $slug ) ) {
				$slug   = $parent;
				$parent = 'learnomy';
			}
			// Slugs and parents are hard-coded identifiers, safe to inject
			// into CSS attribute selectors. sanitize_key is belt-and-braces
			// to guarantee [a-z0-9_-] only.
			$safe_slug   = sanitize_key( (string) $slug );
			$safe_parent = sanitize_key( (string) $parent );
			$selectors[] = '#toplevel_page_' . $safe_parent . ' .wp-submenu a[href$="page=' . $safe_slug . '"]';
		}

		// Section header rows injected by reorder_submenu_into_sections() —
		// styled as small-caps labels, no hover effect, non-interactive.
		$section_css = '
			#adminmenu .wp-submenu li a .lrn-menu-section {
				display: inline-block;
				width: 100%;
				padding-top: 8px;
				padding-bottom: 4px;
				color: rgba(240, 246, 252, 0.5);
				font-size: 10px;
				font-weight: 700;
				letter-spacing: 0.05em;
				text-transform: uppercase;
				pointer-events: none;
			}
			#adminmenu .wp-submenu li a[href^="#lrn-section-"] {
				cursor: default;
				background: transparent !important;
				color: rgba(240, 246, 252, 0.5) !important;
			}
			#adminmenu .wp-submenu li a[href^="#lrn-section-"]:hover,
			#adminmenu .wp-submenu li a[href^="#lrn-section-"]:focus {
				background: transparent !important;
				color: rgba(240, 246, 252, 0.5) !important;
			}
			#adminmenu .wp-submenu li.current a[href^="#lrn-section-"],
			#adminmenu .wp-submenu li a[href^="#lrn-section-"].current {
				background: transparent !important;
				color: rgba(240, 246, 252, 0.5) !important;
			}
		';

		printf(
			'<style id="learnomy-hide-submenu">%1$s{display:none!important;}%2$s</style>',
			implode( ', ', $selectors ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $selectors are CSS strings composed from sanitize_key()'d slugs.
			$section_css // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static CSS string, no interpolation.
		);
	}

	/**
	 * Keep the right top-level menu highlighted when the user is on a
	 * hidden submenu page.
	 *
	 * Hidden pages are registered under their declared parent so WordPress's
	 * get_admin_page_parent() resolves correctly on its own. This filter is
	 * a belt-and-braces fallback in case third-party code has re-registered
	 * a hidden page with an empty parent slug. We resolve the correct parent
	 * from the same source-of-truth used by hidden_submenu_pages().
	 *
	 * @param string $parent_file The current parent file slug.
	 * @return string Corrected parent file slug.
	 */
	public function fix_parent_file( string $parent_file ): string {
		$current = $this->current_page();

		foreach ( $this->hidden_submenu_pages() as $slug => $config ) {
			if ( $slug === $current ) {
				return $config[2] ?? 'learnomy';
			}
		}

		return $parent_file;
	}

	/**
	 * Highlight the correct submenu item when on a hidden page.
	 *
	 * For example the Edit Course page should highlight "Courses" in the
	 * sidebar so the user knows where they are.
	 *
	 * @param string|null $submenu_file The current submenu file slug.
	 * @return string|null Corrected submenu file slug.
	 */
	public function fix_submenu_file( ?string $submenu_file ): ?string {
		$current = $this->current_page();

		// When the current page is a Pro extension that the Pro Tools hub
		// manages, highlight the Pro Tools submenu row. We derive the slug
		// list from the published card filter so Free never has to know
		// about specific Pro extensions.
		$pro_tool_cards = (array) apply_filters( 'learnomy_pro_tools_cards', array() );
		foreach ( $pro_tool_cards as $card ) {
			if ( ! empty( $card['slug'] ) && (string) $card['slug'] === $current ) {
				return 'learnomy-pro-tools';
			}
		}

		$map = array(
			'learnomy-new-course'           => 'learnomy-courses',
			'learnomy-edit-course'          => 'learnomy-courses',
			'learnomy-course-builder'       => 'learnomy-courses',
			'learnomy-lesson-editor'        => 'learnomy-lessons',
			'learnomy-edit-quiz'            => 'learnomy-quizzes',
			'learnomy-question-editor'      => 'learnomy-questions',
			'learnomy-certificate-designer' => 'learnomy-certificates',
			'learnomy-membership-edit'      => 'learnomy-memberships',
		);

		if ( isset( $map[ $current ] ) ) {
			return $map[ $current ];
		}

		return $submenu_file;
	}

	// ── Settings ──────────────────────────────────────────────────────────────

	public function register_settings(): void {
		register_setting(
			'learnomy_settings',
			'learnomy_settings',
			array(
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
			)
		);
	}

	/**
	 * Sanitize the settings option value before saving.
	 *
	 * @param mixed $raw Raw form input.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		// Merge with existing settings so that fields from other sections
		// (submitted via separate <form> tags) are not wiped out.
		$existing = get_option( 'learnomy_settings', array() );
		$clean    = is_array( $existing ) ? $existing : array();

		// ── Enum-clamped fields (Basecamp #9928023966) ───────────────
		// Constrained to a known set both for the form and for the REST
		// PATCH endpoint, so a hand-rolled curl can't write a bogus value
		// like 'totally_made_up' that downstream code silently falls
		// through on. Defaults match the wp-admin <select> default.
		$enum_fields = array(
			// enrollment_mode intentionally ABSENT: the control was removed
			// from Settings + the wizard until the approval feature is built
			// (half-cooked audit F1 — the value was stored but never read
			// anywhere, so 'Approval' silently behaved as 'open'). The
			// merge-based sanitize preserves any stored value untouched; the
			// approval feature re-adds this entry when it ships.
			'completion_enforcement' => array(
				// Matches the Settings <select> AND Progress_Service::resolve_enforcement()
				// which validates strict/advisory/off. The old soft/hard set could
				// never be saved — every save coerced to an unused value and the
				// reader fell back to 'strict', so Advisory/Off were unreachable.
				'allowed' => array( 'strict', 'advisory', 'off' ),
				'default' => 'strict',
			),
			'stripe_mode'            => array(
				'allowed' => array( 'test', 'live' ),
				'default' => 'test',
			),
			'paypal_mode'            => array(
				'allowed' => array( 'sandbox', 'live' ),
				'default' => 'sandbox',
			),
			'payout_period'          => array(
				'allowed' => array( 'weekly', 'biweekly', 'monthly', 'manual' ),
				'default' => 'monthly',
			),
			// Catalog grid density (desktop columns). Clamped 2-5 so a stray
			// value can't break the grid; read as int by the catalog view-data.
			'catalog_columns'        => array(
				'allowed' => array( '2', '3', '4', '5' ),
				'default' => '3',
			),
		);
		foreach ( $enum_fields as $key => $rule ) {
			if ( isset( $raw[ $key ] ) ) {
				$incoming      = sanitize_text_field( (string) $raw[ $key ] );
				$clean[ $key ] = in_array( $incoming, $rule['allowed'], true ) ? $incoming : $rule['default'];
			}
		}

		// ── Text fields (sanitize_text_field) ────────────────────────
		// enrollment_mode, completion_enforcement, stripe_mode, paypal_mode,
		// and payout_period are enum-clamped in the block above — they are
		// intentionally NOT in this list.
		$text_fields = array(
			'currency',
			'container_width',
			// Legacy Stripe flat key — kept for backward compat.
			'stripe_publishable_key',
			// Phase 3 Stripe mode-specific keys (mode itself is enum-clamped).
			'stripe_test_publishable',
			'stripe_live_publishable',
			// Legacy PayPal flat key — kept for backward compat.
			'paypal_client_id',
			// Phase 3 PayPal mode-specific keys (mode itself is enum-clamped).
			'paypal_sandbox_client',
			'paypal_sandbox_webhook_id',
			'paypal_live_client',
			'paypal_live_webhook_id',
			// `tax_handling` intentionally removed (Wave 2C row 2C-2 —
			// tax collection deferred from v1.0; tracked in plan/INVARIANTS.yaml).
			'email_sender_name',
			// `email_sender_email` is handled separately below — it needs
			// email-specific validation, not plain text sanitization.
			'quiz_show_answers',
			// Start-screen layout; quiz_start_style() clamps to its enum on
			// read, so plain text sanitization is enough here.
			'quiz_start_style',
			'business_name',
			// Membership pricing page content (Settings → Membership Page).
			'membership_headline',
			'membership_subtitle',
		);
		foreach ( $text_fields as $key ) {
			if ( isset( $raw[ $key ] ) ) {
				$clean[ $key ] = sanitize_text_field( $raw[ $key ] );
			}
		}

		// ── Custom account fields (no-code field builder) ────────────────
		// Nested array of field definitions; the service does the per-field
		// sanitization (label/key/type/required/location/options).
		if ( isset( $raw[ \Learnomy\Services\Custom_Fields::SETTING_KEY ] ) ) {
			$clean[ \Learnomy\Services\Custom_Fields::SETTING_KEY ] =
				( new \Learnomy\Services\Custom_Fields() )->sanitize_definitions( $raw[ \Learnomy\Services\Custom_Fields::SETTING_KEY ] );
		}

		// ── Layout selectors (validate against the layout registry) ──
		// Each surface's setting_key must hold one of that surface's variant
		// keys; anything else falls back to the surface default. No rewrite
		// flush — a layout change swaps the template, never the route/slug.
		foreach ( \Learnomy\layouts() as $lrn_cfg ) {
			$layout_key = $lrn_cfg['setting_key'];
			if ( ! isset( $raw[ $layout_key ] ) ) {
				continue;
			}
			$layout_val           = sanitize_key( (string) $raw[ $layout_key ] );
			$clean[ $layout_key ] = isset( $lrn_cfg['variants'][ $layout_val ] )
				? $layout_val
				: (string) $lrn_cfg['default'];
		}

		// ── URL fields (esc_url_raw) ──
		// Absolute URLs the site owner points at their own pages. Blank clears
		// the setting, which for `terms_url` also turns the sign-up agreement
		// off — see Consent_Service::is_required().
		foreach ( array( 'terms_url' ) as $url_key ) {
			if ( ! isset( $raw[ $url_key ] ) ) {
				continue;
			}
			$clean[ $url_key ] = esc_url_raw( trim( (string) $raw[ $url_key ] ) );
		}

		// ── URL slug fields (sanitize_title + auto-flush on change) ──
		// Every reslug-able entity is `sanitize_title`'d so the stored value
		// matches what the Router and the URL builders compute (both sides
		// pass the value through `sanitize_title`). When any slug actually
		// changes versus what's stored, the registered rewrite rules become
		// stale — the new path 404s until permalinks are flushed. We detect
		// the change here and set a one-time flag; `Learnomy::maybe_flush_for_slug_change()`
		// flushes on the next `init` (after the Router re-registers the rules).
		$slug_changed = false;
		foreach ( \Learnomy\reslug_keys() as $key => $meta ) {
			if ( ! isset( $raw[ $key ] ) ) {
				continue;
			}
			$new = sanitize_title( (string) $raw[ $key ] );
			if ( '' === $new ) {
				// Empty input falls back to the English default rather than
				// registering a broken empty-segment rewrite rule.
				$new = sanitize_title( $meta['default'] );
			}
			$old = isset( $existing[ $key ] ) ? sanitize_title( (string) $existing[ $key ] ) : sanitize_title( $meta['default'] );
			if ( $new !== $old ) {
				$slug_changed = true;
			}
			$clean[ $key ] = $new;
		}
		if ( $slug_changed ) {
			update_option( 'learnomy_pending_rewrite_flush', '1', false );
		}

		// ── Entity labels (no-code rename) ───────────────────────────
		// Nested `labels` array: one key per entity + form, e.g.
		// `labels[course_singular]`. Each value is plain text. A blank value
		// means "use the translatable default" — we drop the key entirely so
		// `entity_label()` falls through to its i18n default rather than
		// persisting an empty override. Only known entity keys are accepted.
		if ( isset( $raw['labels'] ) && is_array( $raw['labels'] ) ) {
			$label_clean = array();
			foreach ( \Learnomy\entity_label_defaults() as $entity_key => $forms ) {
				foreach ( array( 'singular', 'plural' ) as $form ) {
					$field = $entity_key . '_' . $form;
					if ( ! isset( $raw['labels'][ $field ] ) ) {
						continue;
					}
					$value = sanitize_text_field( wp_unslash( $raw['labels'][ $field ] ) );
					if ( '' !== $value ) {
						$label_clean[ $field ] = $value;
					}
				}
			}
			$clean['labels'] = $label_clean;
		}

		// ── Numeric fields (absint) ──────────────────────────────────
		$int_fields = array(
			'platform_commission',
			'refund_days',
			'data_retention_days',
			'email_log_retention_days',
			'quiz_pass_mark',
			'quiz_max_attempts',
			'quiz_time_limit',
			'quiz_tab_switch_limit',
		);
		foreach ( $int_fields as $key ) {
			if ( isset( $raw[ $key ] ) ) {
				$clean[ $key ] = absint( $raw[ $key ] );
			}
		}

		// ── Float fields (clamped) ────────────────────────────────────
		// Commission processing-fee model: a percent + a fixed per-transaction
		// amount, deducted from gross before the platform/instructor split.
		// Percent is bounded 0-100; the fixed component 0-9999.99 (a sane
		// ceiling — a fee larger than that is a typo, not a real processor cost).
		$float_fields = array(
			'commission_fee_percent' => array(
				'min' => 0.0,
				'max' => 100.0,
			),
			'commission_fee_fixed'   => array(
				'min' => 0.0,
				'max' => 9999.99,
			),
			// The payout-floor instructors must accumulate before requesting a
			// withdrawal. Stored as withdrawal_minimum — the key the earnings
			// endpoint actually enforces (the field used to save min_withdrawal,
			// which nothing read).
			'withdrawal_minimum'     => array(
				'min' => 0.0,
				'max' => 99999.99,
			),
		);
		foreach ( $float_fields as $key => $bounds ) {
			if ( isset( $raw[ $key ] ) ) {
				$value         = is_numeric( $raw[ $key ] ) ? (float) $raw[ $key ] : 0.0;
				$clean[ $key ] = round( max( $bounds['min'], min( $bounds['max'], $value ) ), 2 );
			}
		}

		// ── Clamped numeric fields ────────────────────────────────────
		// Bounded ranges so a typo doesn't ship an absurd value to the
		// rate limiter or object cache (Basecamp #9927979251).
		// api_rate_limit: 0 disables; cap at 10000 (167 req/sec) — anything
		// higher means the gate is effectively off and the customer should
		// turn the toggle off instead.
		// cache_ttl: 0 disables; cap at 86400 (24h) — longer TTLs masquerade
		// as "set and forget" until stale state burns the customer.
		$clamped_int_fields = array(
			'api_rate_limit' => array(
				'min'     => 0,
				'max'     => 10000,
				'default' => 60,
			),
			'cache_ttl'      => array(
				'min'     => 0,
				'max'     => 86400,
				'default' => 3600,
			),
		);
		foreach ( $clamped_int_fields as $key => $bounds ) {
			if ( isset( $raw[ $key ] ) ) {
				$value         = absint( $raw[ $key ] );
				$clean[ $key ] = max( $bounds['min'], min( $bounds['max'], $value ) );
			}
		}

		// ── Textarea fields ──────────────────────────────────────────
		if ( isset( $raw['certificate_footer'] ) ) {
			$clean['certificate_footer'] = sanitize_textarea_field( $raw['certificate_footer'] );
		}
		if ( isset( $raw['refund_policy_text'] ) ) {
			$clean['refund_policy_text'] = sanitize_textarea_field( $raw['refund_policy_text'] );
		}

		// ── Checkbox / toggle fields (value="1") ─────────────────────
		// Checkboxes are only present in $_POST when checked, so we must
		// explicitly set unchecked ones to 0 for the current form section.
		$checkbox_fields = array(
			\Learnomy\Services\Registration_Captcha::SETTING_KEY,
			'enable_stripe',
			'enable_paypal',
			'enable_woocommerce',
			'enable_test_gateway',
			'instructor_self_publish',
			'multi_instructor',
			'solo_mode',
			'commission_deduct_fee',
			'commission_tax_in_base',
			'payout_paypal',
			'payout_bank',
			'enable_reviews',
			'auto_approve_reviews',
			'enable_pwa',
			'enable_certificates',
			'enable_course_comments',
			'enable_lesson_comments',
			'quiz_randomize',
			'quiz_track_tabs',
			'delete_data_on_uninstall',
			// Fully-private academy: gate all course-content routes behind login.
			'require_login_all_content',
			// Uniform tax (level-1 compliance).
			'tax_enabled',
			// Membership pricing page section toggles.
			'show_membership_faq',
			'show_membership_gift',
			// Mobile app connect switch. Settings > General renders this toggle
			// and App_Config_Controller reads it, but it was never in this list,
			// so neither state was ever written: the reader's "unset means on"
			// default made the switch permanently ON and a site owner had no way
			// to stop the app connecting. Found by the 1.8.1 UX/stub audit.
			'mobile_app_enabled',
		);
		foreach ( $checkbox_fields as $key ) {
			if ( array_key_exists( $key, $raw ) ) {
				$clean[ $key ] = ! empty( $raw[ $key ] ) ? 1 : 0;
			}
		}

		// ── Uniform tax rate + label ─────────────────────────────────
		if ( array_key_exists( 'tax_rate', $raw ) ) {
			$clean['tax_rate'] = max( 0.0, min( 100.0, (float) $raw['tax_rate'] ) );
		}
		if ( array_key_exists( 'tax_label', $raw ) ) {
			$clean['tax_label'] = sanitize_text_field( wp_unslash( $raw['tax_label'] ) );
		}

		// ── Past-due grace window ────────────────────────────────────
		// Bounds mirror \Learnomy\subscription_grace_days() so the stored value and
		// the value the cron reads can never disagree. 0 = no grace.
		if ( array_key_exists( 'subscription_grace_days', $raw ) ) {
			$clean['subscription_grace_days'] = max( 0, min( 90, (int) $raw['subscription_grace_days'] ) );
		}

		// ── Mobile push ──────────────────────────────────────────────────
		// The service-account JSON is a credential, not prose: it must survive
		// verbatim (the PEM private key inside it is newline-sensitive), so it gets
		// wp_unslash + a JSON round-trip rather than a text sanitizer, which would
		// mangle the key and produce a credential that silently never authenticates.
		// A value that is not valid JSON is stored as an empty string — better a
		// clean "not configured" than a stored credential that can never work.
		if ( array_key_exists( 'push_enabled', $raw ) ) {
			$clean['push_enabled'] = empty( $raw['push_enabled'] ) ? 0 : 1;
		}

		if ( array_key_exists( 'push_fcm_service_account', $raw ) ) {
			$push_json = trim( (string) wp_unslash( $raw['push_fcm_service_account'] ) );

			if ( '' === $push_json ) {
				$clean['push_fcm_service_account'] = '';
			} else {
				$decoded                           = json_decode( $push_json, true );
				$clean['push_fcm_service_account'] = ( is_array( $decoded ) && JSON_ERROR_NONE === json_last_error() )
					? wp_json_encode( $decoded )
					: '';
			}
		}

		// ── Membership pricing-page FAQ (repeater; empty rows dropped) ───
		// Stored as an ordered array of { q, a }. Editors fill fixed rows in
		// the settings UI; blank rows are discarded on save. Rendered by
		// \Learnomy\membership_faq_items() with built-in defaults when empty.
		if ( isset( $raw['membership_faq'] ) && is_array( $raw['membership_faq'] ) ) {
			$faq_clean = array();
			foreach ( $raw['membership_faq'] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$q = isset( $row['q'] ) ? sanitize_text_field( wp_unslash( $row['q'] ) ) : '';
				$a = isset( $row['a'] ) ? sanitize_textarea_field( wp_unslash( $row['a'] ) ) : '';
				if ( '' !== trim( $q ) && '' !== trim( $a ) ) {
					$faq_clean[] = array(
						'q' => $q,
						'a' => $a,
					);
				}
			}
			$clean['membership_faq'] = $faq_clean;
		}

		// ── Email notification toggles ───────────────────────────────
		// Single-sourced from the notification service so the toggles, the
		// per-template copy editor, and this sanitiser can never drift apart.
		$email_types = array_keys( \Learnomy\Services\Notification_Service::email_template_types() );
		foreach ( $email_types as $type ) {
			$toggle_key = 'email_notify_' . $type;
			if ( array_key_exists( $toggle_key, $raw ) ) {
				$clean[ $toggle_key ] = ! empty( $raw[ $toggle_key ] ) ? 1 : 0;
			}

			$subject_key = 'email_subj_' . $type;
			if ( array_key_exists( $subject_key, $raw ) ) {
				$clean[ $subject_key ] = sanitize_text_field( wp_unslash( (string) $raw[ $subject_key ] ) );
			}

			$body_key = 'email_body_' . $type;
			if ( array_key_exists( $body_key, $raw ) ) {
				$clean[ $body_key ] = wp_kses_post( wp_unslash( (string) $raw[ $body_key ] ) );
			}
		}

		// Handle encrypted secret fields submitted via learnomy_encrypted[key].
		// Only update if a non-empty value was submitted (blank = keep current).
		// Mode-specific keys (Phase 3): stripe_test_secret, stripe_live_secret, etc.
		// Legacy keys preserved so existing stores don't break on re-save.
		$encrypted_map = array(
			// Stripe — legacy (pre-Phase 3).
			'stripe_secret_key'          => 'learnomy_stripe_secret_key',
			'stripe_webhook_secret'      => 'learnomy_stripe_webhook_secret',
			// Stripe — mode-specific (Phase 3+).
			'stripe_test_secret'         => 'learnomy_stripe_test_secret',
			'stripe_test_webhook_secret' => 'learnomy_stripe_test_webhook_secret',
			'stripe_live_secret'         => 'learnomy_stripe_live_secret',
			'stripe_live_webhook_secret' => 'learnomy_stripe_live_webhook_secret',
			// PayPal — legacy (pre-Phase 3).
			'paypal_secret'              => 'learnomy_paypal_secret',
			'paypal_webhook_id'          => 'learnomy_paypal_webhook_id',
			// PayPal — mode-specific (Phase 3+).
			'paypal_sandbox_secret'      => 'learnomy_paypal_sandbox_secret',
			'paypal_live_secret'         => 'learnomy_paypal_live_secret',
		);

		// Nonce is verified by options.php (register_setting flow); each value is
		// unslashed + sanitized at point of use in the loop below (sanitize_text_field).
		$encrypted_input = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['learnomy_encrypted'] ) && is_array( $_POST['learnomy_encrypted'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$encrypted_input = $_POST['learnomy_encrypted'];
		}

		foreach ( $encrypted_map as $field => $option_name ) {
			if ( ! empty( $encrypted_input[ $field ] ) ) {
				\Learnomy\Security\Encrypted_Option::set(
					$option_name,
					sanitize_text_field( wp_unslash( $encrypted_input[ $field ] ) )
				);
			}
		}

		// ── Email sender address (sanitize + validate) ──────────────
		// An invalid value (e.g. "not-an-email") must never persist — WordPress
		// rejects mail with a malformed From header. Mirror the enum-coercion
		// pattern below: keep the prior valid value when one exists, else fall
		// back to the site admin address so notifications still send.
		if ( isset( $raw['email_sender_email'] ) ) {
			$candidate = sanitize_email( wp_unslash( $raw['email_sender_email'] ) );
			if ( is_email( $candidate ) ) {
				$clean['email_sender_email'] = $candidate;
			} elseif ( ! empty( $clean['email_sender_email'] ) && is_email( $clean['email_sender_email'] ) ) {
				// Keep the previously stored valid address — drop the bad input.
				$clean['email_sender_email'] = $clean['email_sender_email'];
			} else {
				$clean['email_sender_email'] = get_option( 'admin_email' );
			}
		}

		// ── Enum coercion for mode fields ────────────────────────────
		if ( isset( $clean['stripe_mode'] ) ) {
			$clean['stripe_mode'] = in_array( $clean['stripe_mode'], array( 'test', 'live' ), true )
				? $clean['stripe_mode']
				: 'test';
		}
		if ( isset( $clean['paypal_mode'] ) ) {
			$clean['paypal_mode'] = in_array( $clean['paypal_mode'], array( 'sandbox', 'live' ), true )
				? $clean['paypal_mode']
				: 'sandbox';
		}

		return apply_filters( 'learnomy_sanitize_settings', $clean, $raw );
	}

	// ── Assets ────────────────────────────────────────────────────────────────

	/**
	 * Detect whether the current admin screen is a Learnomy page with the given slug.
	 *
	 * White-label customers can rename the menu label via
	 * `learnomy_admin_menu_label`. WordPress derives the screen-hook prefix
	 * from `sanitize_title( $menu_title )`, so renaming "Learnomy" to "Acme
	 * LMS" turns every hook from `learnomy_page_<slug>` into
	 * `acme-lms_page_<slug>`. Hard-coded comparisons against
	 * `learnomy_page_*` would silently fail for those customers, which
	 * is exactly how every admin CSS gate broke after the JourneyTest
	 * white-label test (5/1/2026). The slug suffix is stable; the prefix
	 * is not. Always match on the suffix.
	 *
	 * Handles all four hook shapes WordPress emits:
	 *   - `learnomy_page_<slug>`        — default, no white-label
	 *   - `<sanitized-rename>_page_<slug>` — white-label rename active
	 *   - `admin_page_<slug>`           — submenu hidden via $submenu trick
	 *   - `toplevel_page_<slug>`        — slug equals the parent menu slug
	 */
	private function is_learnomy_admin_page( string $hook, string $slug ): bool {
		return str_ends_with( $hook, '_page_' . $slug );
	}

	/**
	 * Check whether the current hook matches any of the given slugs.
	 *
	 * @param string   $hook  Current admin page hook suffix.
	 * @param string[] $slugs List of submenu slugs to match.
	 */
	private function is_any_learnomy_admin_page( string $hook, array $slugs ): bool {
		foreach ( $slugs as $slug ) {
			if ( $this->is_learnomy_admin_page( $hook, $slug ) ) {
				return true;
			}
		}
		return false;
	}

	public function enqueue_assets( string $hook ): void {
		// Not a Learnomy admin page — bail. The `_page_learnomy` suffix matches
		// both the top-level (`toplevel_page_learnomy`) and every submenu
		// (`<prefix>_page_learnomy-*`), regardless of the white-label menu label.
		if ( ! str_contains( $hook, '_page_learnomy' ) ) {
			return;
		}

		// Load the frontend stylesheet first so admin can consume the shared
		// design tokens (--lrn-*) defined there. This is the cascade
		// prerequisite for Wave 1D — admin.css no longer redeclares tokens
		// that already live in learnomy.css; it inherits them from this load.
		wp_enqueue_style(
			'learnomy',
			LEARNOMY_URL . 'assets/css/learnomy.css',
			array(),
			LEARNOMY_VERSION
		);

		wp_enqueue_style(
			'learnomy-admin',
			LEARNOMY_URL . 'assets/css/admin.css',
			array( 'learnomy' ),
			LEARNOMY_VERSION
		);

		wp_enqueue_script(
			'learnomy-admin',
			LEARNOMY_URL . 'assets/js/admin.js',
			array( 'jquery', 'wp-i18n', 'learnomy-confirm-modal', 'learnomy-toast' ),
			LEARNOMY_VERSION,
			true
		);
		wp_set_script_translations( 'learnomy-admin', 'learnomy', LEARNOMY_DIR . 'languages' );

		// Extension-field collector — completes the editor-fields seam by
		// gathering any control an integration prints inside a
		// [data-lrn-ext-fields] panel into the editor's REST body under
		// extension_fields. Registered here so the course + lesson editors can
		// declare it as a dependency. (The quiz editor's generic form
		// serializer already serializes nested field names, so it needs no
		// collector.)
		wp_register_script(
			'learnomy-ext-fields',
			LEARNOMY_URL . 'assets/js/admin/ext-fields.js',
			array(),
			LEARNOMY_VERSION,
			true
		);

		wp_localize_script(
			'learnomy-admin',
			'learnomyAdmin',
			array(
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'nonce'            => wp_create_nonce( 'learnomy_admin' ),
				'restUrl'          => rest_url( 'learnomy/v1/' ),
				'restNonce'        => wp_create_nonce( 'wp_rest' ),
				// Surfaced for the maintenance-tools confirm dialog so the
				// admin sees the actual recipient before sending a test mail
				// (Basecamp #9927979266).
				'currentUserEmail' => (string) wp_get_current_user()->user_email,
				'maintenanceTools' => array(
					'testEmailTitle'   => __( 'Send test email', 'learnomy' ),
					'testEmailMessage' => __( 'A test email will be sent to {email}. Continue?', 'learnomy' ),
					'testEmailConfirm' => __( 'Send', 'learnomy' ),
					'testEmailBusy'    => __( 'Sending…', 'learnomy' ),
					'flushTitle'       => __( 'Flush rewrite rules', 'learnomy' ),
					'flushMessage'     => __( 'WordPress will recompute every URL rule. On large sites visitors may briefly see 404s while rules rebuild. Continue?', 'learnomy' ),
					'flushConfirm'     => __( 'Flush', 'learnomy' ),
					'flushBusy'        => __( 'Flushing…', 'learnomy' ),
				),
			)
		);

		// Shared UI utilities — toast notifications, skeleton loaders, pagination, animations.
		wp_enqueue_style( 'learnomy-toast', LEARNOMY_URL . 'assets/css/shared/toast.css', array( 'learnomy-admin' ), LEARNOMY_VERSION );
		wp_enqueue_style( 'learnomy-skeleton', LEARNOMY_URL . 'assets/css/shared/skeleton.css', array( 'learnomy-admin' ), LEARNOMY_VERSION );
		wp_enqueue_style( 'learnomy-pagination', LEARNOMY_URL . 'assets/css/shared/pagination.css', array( 'learnomy-admin' ), LEARNOMY_VERSION );
		wp_enqueue_style( 'learnomy-notice', LEARNOMY_URL . 'assets/css/shared/notice.css', array( 'learnomy-admin' ), LEARNOMY_VERSION );
		wp_enqueue_style( 'learnomy-animations', LEARNOMY_URL . 'assets/css/shared/animations.css', array( 'learnomy-admin' ), LEARNOMY_VERSION );
		wp_enqueue_style( 'learnomy-confirm-modal', LEARNOMY_URL . 'assets/css/shared/confirm-modal.css', array( 'learnomy-admin' ), LEARNOMY_VERSION );
		wp_enqueue_script( 'learnomy-toast', LEARNOMY_URL . 'assets/js/shared/toast.js', array( 'wp-i18n' ), LEARNOMY_VERSION, true );
		wp_set_script_translations( 'learnomy-toast', 'learnomy', LEARNOMY_DIR . 'languages' );
		wp_enqueue_script( 'learnomy-animations', LEARNOMY_URL . 'assets/js/shared/animations.js', array(), LEARNOMY_VERSION, true );

		// Community & Discussion toggles. Registered across the admin rather
		// than per-screen because the section renders on four of them (course
		// editor, course builder, cohort, Learnomy Space), and the script
		// no-ops when no toggle is present — which is every screen on a site
		// with no provider registered.
		wp_enqueue_script( 'learnomy-object-integrations', LEARNOMY_URL . 'assets/js/object-integrations.js', array(), LEARNOMY_VERSION, true );
		wp_localize_script(
			'learnomy-object-integrations',
			'learnomyObjectIntegrations',
			array(
				'restUrl' => esc_url_raw( rest_url( 'learnomy/v1/object-integrations' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);

		// Confirmation modal — replaces native window.confirm() site-wide.
		// Depends on wp-i18n so the helper can localise default button labels.
		wp_enqueue_script( 'learnomy-confirm-modal', LEARNOMY_URL . 'assets/js/shared/confirm-modal.js', array( 'wp-i18n' ), LEARNOMY_VERSION, true );

		// Prompt modal — replaces native window.prompt() for inline title /
		// rename input. Reuses confirm-modal CSS so no extra stylesheet load.
		wp_enqueue_script( 'learnomy-prompt-modal', LEARNOMY_URL . 'assets/js/shared/prompt-modal.js', array( 'wp-i18n', 'learnomy-confirm-modal' ), LEARNOMY_VERSION, true );

		// Admin action button wiring — generic AJAX handler for data-action buttons.
		wp_enqueue_script( 'learnomy-actions', LEARNOMY_URL . 'assets/js/admin/actions.js', array( 'wp-i18n', 'learnomy-admin', 'learnomy-confirm-modal', 'learnomy-prompt-modal', 'learnomy-toast' ), LEARNOMY_VERSION, true );
		wp_set_script_translations( 'learnomy-actions', 'learnomy', LEARNOMY_DIR . 'languages' );

		// Inline tag quick-add for the editor taxonomy cards (admin-only markup).
		// No-op on pages without a `.lrn-tag-quick-add` row; reads REST config
		// from the learnomyAdmin global localized on learnomy-admin.
		wp_enqueue_script( 'learnomy-tag-quick-add', LEARNOMY_URL . 'assets/js/admin/tag-quick-add.js', array( 'wp-i18n', 'learnomy-admin' ), LEARNOMY_VERSION, true );
		wp_set_script_translations( 'learnomy-tag-quick-add', 'learnomy', LEARNOMY_DIR . 'languages' );

		// Shared row-actions overflow menu (the canonical admin "family"
		// pattern, rendered by \Learnomy\Admin\UX::row_actions()). Global on
		// every Learnomy admin page so any list can use it.
		wp_enqueue_script( 'learnomy-row-actions', LEARNOMY_URL . 'assets/js/admin/row-actions.js', array(), LEARNOMY_VERSION, true );

		// Shared file-field control — the canonical upload UI (hidden input +
		// .lrn-admin-btn "Choose file" trigger + filename), reused by Free and
		// every Pro extension so no admin screen ships a raw browser file input.
		wp_enqueue_script( 'learnomy-file-field', LEARNOMY_URL . 'assets/js/admin/file-field.js', array(), LEARNOMY_VERSION, true );

		// Lucide icon system — available on every Learnomy admin page, through
		// the SAME call the frontend and Pro's builder make. This block used to
		// restate the two enqueues by hand (pinning lucide to a literal '0.460.0'
		// while Template_Loader used LEARNOMY_VERSION), which is the third copy
		// of a two-line contract and the reason the live seam could be present
		// on one surface and missing on another (Basecamp 10171508068).
		\Learnomy\Template_Loader::enqueue_icons();
		wp_enqueue_script( 'learnomy-icons', LEARNOMY_URL . 'assets/js/admin/icons.js', array( 'lucide' ), LEARNOMY_VERSION, true );
		wp_enqueue_style( 'learnomy-icons', LEARNOMY_URL . 'assets/css/admin/icons.css', array( 'learnomy-admin' ), LEARNOMY_VERSION );

		// Pro Features page — informational catalog. Style only; no JS.
		if ( $this->is_learnomy_admin_page( $hook, 'learnomy-pro-features' ) ) {
			wp_enqueue_style(
				'learnomy-pro-features',
				LEARNOMY_URL . 'assets/css/admin/pro-features.css',
				array( 'learnomy-admin' ),
				LEARNOMY_VERSION
			);
		}

		// Commerce overview hub — uses the canonical hub template
		// (`.lrn-admin-hub-grid` / `__card`). Was previously a bespoke
		// `commerce-overview.css` duplicating the same recipe as
		// `pro-tools-hub.css`; consolidated 2026-05-23 per
		// plan/2026-05-23-admin-ux-audit/REPORT.md.
		if ( $this->is_learnomy_admin_page( $hook, 'learnomy-commerce' ) ) {
			wp_enqueue_style(
				'learnomy-admin-hub',
				LEARNOMY_URL . 'assets/css/admin/hub.css',
				array( 'learnomy-admin' ),
				LEARNOMY_VERSION
			);
		}

		// Courses list — bulk action handler (was inline in views/courses.php).
		if ( $this->is_learnomy_admin_page( $hook, 'learnomy-courses' ) ) {
			wp_enqueue_script( 'learnomy-admin-courses-bulk', LEARNOMY_URL . 'assets/js/admin/courses-bulk.js', array( 'learnomy-admin' ), LEARNOMY_VERSION, true );
		}

		// Grading detail — inline score + feedback save via the grade REST route.
		if ( $this->is_learnomy_admin_page( $hook, 'learnomy-grading' ) ) {
			wp_enqueue_style( 'learnomy-admin-quiz-grading', LEARNOMY_URL . 'assets/css/admin/quiz-grading.css', array( 'learnomy-admin' ), LEARNOMY_VERSION );
			wp_enqueue_script( 'learnomy-admin-quiz-grading', LEARNOMY_URL . 'assets/js/admin/quiz-grading.js', array( 'learnomy-admin', 'learnomy-toast' ), LEARNOMY_VERSION, true );
		}

		// Earnings — commission-rule save preprocessor.
		if ( $this->is_learnomy_admin_page( $hook, 'learnomy-earnings' ) ) {
			wp_enqueue_script( 'learnomy-admin-earnings-commission', LEARNOMY_URL . 'assets/js/admin/earnings-commission.js', array( 'learnomy-admin' ), LEARNOMY_VERSION, true );
			wp_set_script_translations( 'learnomy-admin-earnings-commission', 'learnomy', LEARNOMY_DIR . 'languages' );
		}

		// Pro Features — filter buttons.
		if ( $this->is_learnomy_admin_page( $hook, 'learnomy-pro-features' ) ) {
			wp_enqueue_script( 'learnomy-admin-pro-features-filter', LEARNOMY_URL . 'assets/js/admin/pro-features-filter.js', array(), LEARNOMY_VERSION, true );
		}

		// Modules — toast notification handler, plus the shared confirm modal
		// that shows a disable's live commitments before the toggle takes
		// effect (the 409 branch in modules.js).
		if ( $this->is_learnomy_admin_page( $hook, 'learnomy-modules' ) ) {
			wp_enqueue_script( 'learnomy-admin-modules', LEARNOMY_URL . 'assets/js/admin/modules.js', array( 'wp-i18n', 'learnomy-toast', 'learnomy-confirm-modal' ), LEARNOMY_VERSION, true );
			wp_set_script_translations( 'learnomy-admin-modules', 'learnomy', LEARNOMY_DIR . 'languages' );
		}

		// Import wizard — REST-driven, polls run progress.
		if ( $this->is_learnomy_admin_page( $hook, 'learnomy-import' ) ) {
			wp_enqueue_style( 'learnomy-admin-migration', LEARNOMY_URL . 'assets/css/admin/migration-wizard.css', array( 'learnomy-admin' ), LEARNOMY_VERSION );
			wp_enqueue_script( 'learnomy-admin-migration', LEARNOMY_URL . 'assets/js/admin/migration-wizard.js', array( 'wp-i18n', 'learnomy-toast', 'learnomy-confirm-modal', 'learnomy-icons' ), LEARNOMY_VERSION, true );
			wp_localize_script(
				'learnomy-admin-migration',
				'learnomyMigration',
				array(
					'restUrl' => esc_url_raw( rest_url( 'learnomy/v1/admin/migration/' ) ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
				)
			);
			// migration-wizard.js has ~49 wp.i18n __() calls — register its
			// translations so those strings localize on non-English sites.
			wp_set_script_translations( 'learnomy-admin-migration', 'learnomy', LEARNOMY_DIR . 'languages' );
		}

		// Settings — onboarding-reopen + payments demo-reset.
		if ( $this->is_learnomy_admin_page( $hook, 'learnomy-settings' ) ) {
			// Learning Standards hub — the single map of every learning-standard
			// integration (xAPI/SCORM/LTI/H5P/QTI) in the Settings section.
			wp_enqueue_style( 'learnomy-admin-integrations', LEARNOMY_URL . 'assets/css/admin/integrations.css', array( 'learnomy-admin' ), LEARNOMY_VERSION );

			// Registration-fields repeater (add/remove rows, options toggle).
			wp_enqueue_script( 'learnomy-admin-custom-fields', LEARNOMY_URL . 'assets/js/admin/custom-fields.js', array( 'learnomy-icons' ), LEARNOMY_VERSION, true );
			// Membership FAQ repeater (add/remove Q&A rows).
			wp_enqueue_script( 'learnomy-admin-faq-repeater', LEARNOMY_URL . 'assets/js/admin/faq-repeater.js', array( 'learnomy-icons' ), LEARNOMY_VERSION, true );

			wp_enqueue_script( 'learnomy-admin-settings-onboarding', LEARNOMY_URL . 'assets/js/admin/settings-onboarding.js', array( 'learnomy-admin' ), LEARNOMY_VERSION, true );
			wp_localize_script(
				'learnomy-admin-settings-onboarding',
				'lrnAdminSettings',
				array(
					'dashboardUrl' => esc_url( admin_url( 'admin.php?page=learnomy' ) ),
				)
			);

			wp_enqueue_script( 'learnomy-admin-settings-payments', LEARNOMY_URL . 'assets/js/admin/settings-payments.js', array( 'wp-i18n', 'learnomy-admin', 'learnomy-confirm-modal', 'learnomy-toast' ), LEARNOMY_VERSION, true );
			wp_set_script_translations( 'learnomy-admin-settings-payments', 'learnomy', LEARNOMY_DIR . 'languages' );
			wp_localize_script(
				'learnomy-admin-settings-payments',
				'lrnAdminSettingsPay',
				array(
					'i18n' => array(
						'confirmTitle' => __( 'Reset demo content', 'learnomy' ),
						'confirmReset' => sprintf( /* translators: %s: plural Course label */ __( 'This deletes the demo %s, students, and transactions and creates a fresh set. Continue?', 'learnomy' ), \Learnomy\course_label( true ) ),
						// Two distinct strings on purpose. `confirmLabel` is what
						// the owner PRESSES; `resetting` is what the button says
						// once it is working. Reusing one key for both made the
						// confirm dialog read "Resetting…" before anything had
						// been confirmed, i.e. the destructive action announced
						// itself as already under way (Basecamp 10160049014).
						'confirmLabel' => __( 'Reset', 'learnomy' ),
						'resetting'    => __( 'Resetting…', 'learnomy' ),
						'success'      => __( 'Demo content reset.', 'learnomy' ),
						'failed'       => __( 'Reset failed.', 'learnomy' ),
					),
				)
			);
		}

		// Course edit + new screens — pricing-model radio show/hide.
		if ( $this->is_learnomy_admin_page( $hook, 'learnomy-edit-course' )
			|| $this->is_learnomy_admin_page( $hook, 'learnomy-new-course' ) ) {
			wp_enqueue_script( 'learnomy-admin-course-pricing', LEARNOMY_URL . 'assets/js/admin/course-pricing.js', array(), LEARNOMY_VERSION, true );
			// Feed the pricing_model -> access_type mirror map from the single
			// source of truth on the Course model so the client mirror can never
			// drift from the server (BC #10052638309 consolidation).
			wp_localize_script(
				'learnomy-admin-course-pricing',
				'lrnPricingMap',
				\Learnomy\Models\Course::MODEL_ACCESS_MAP
			);
			// Clause maps for the live access-summary sentence (single source with
			// Course::access_summary, so the editor's live line never drifts).
			wp_localize_script(
				'learnomy-admin-course-pricing',
				'lrnAccessSummary',
				\Learnomy\Models\Course::access_summary_parts()
			);
		}

		// Gateway warning notice dismissal — fires on every admin page where
		// the .learnomy-gateway-warning notice may appear (course-edit, etc).
		// Only enqueue on Learnomy admin pages to stay polite.
		if ( false !== strpos( (string) $hook, 'learnomy' ) ) {
			wp_enqueue_script( 'learnomy-admin-gateway-warning', LEARNOMY_URL . 'assets/js/admin/gateway-warning.js', array(), LEARNOMY_VERSION, true );
		}

		// Transactions admin page — per-row Refund button handler.
		if ( $this->is_any_learnomy_admin_page( $hook, array( 'learnomy-transactions', 'learnomy-transaction-detail' ) ) ) {
			wp_enqueue_script(
				'learnomy-admin-transactions',
				LEARNOMY_URL . 'assets/js/admin/transactions.js',
				array( 'wp-i18n', 'learnomy-confirm-modal', 'learnomy-toast' ),
				LEARNOMY_VERSION,
				true
			);
			wp_set_script_translations( 'learnomy-admin-transactions', 'learnomy', LEARNOMY_DIR . 'languages' );
			wp_localize_script(
				'learnomy-admin-transactions',
				'lrnTransactionsAdmin',
				array(
					'restUrl' => esc_url_raw( rest_url( 'learnomy/v1/admin/transactions/' ) ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
					'i18n'    => array(
						'confirm'        => __( 'Refund {currency} {amount} for "{item}" ({customer})? This refunds via the original gateway and marks the transaction refunded.', 'learnomy' ),
						'confirm_title'  => __( 'Refund transaction', 'learnomy' ),
						'confirm_label'  => __( 'Refund', 'learnomy' ),
						'amount_label'   => __( 'Refund amount', 'learnomy' ),
						'amount_help'    => __( 'Defaults to the full amount still refundable. Enter less to refund part of it now and the rest later.', 'learnomy' ),
						'amount_invalid' => __( 'Enter an amount greater than zero and no more than the amount still refundable.', 'learnomy' ),
						'processing'     => __( 'Processing…', 'learnomy' ),
						'success'        => __( 'Refund issued.', 'learnomy' ),
						'error_prefix'   => __( 'Refund failed', 'learnomy' ),
					),
				)
			);
		}

		// Pro Tools hub — uses the canonical hub template (same as
		// Commerce Overview). `pro-tools-hub.css` was a byte-identical
		// twin of `commerce-overview.css`; both replaced by `hub.css`
		// per plan/2026-05-23-admin-ux-audit/REPORT.md.
		if ( $this->is_learnomy_admin_page( $hook, 'learnomy-pro-tools' ) ) {
			wp_enqueue_style(
				'learnomy-admin-hub',
				LEARNOMY_URL . 'assets/css/admin/hub.css',
				array( 'learnomy-admin' ),
				LEARNOMY_VERSION
			);
		}

		// Modules page — token-driven card grid + toast for the toggle UI.
		if ( $this->is_learnomy_admin_page( $hook, 'learnomy-modules' ) ) {
			wp_enqueue_style(
				'learnomy-modules',
				LEARNOMY_URL . 'assets/css/admin/modules.css',
				array( 'learnomy-admin' ),
				LEARNOMY_VERSION
			);
		}

		// Settings page — sidebar hash routing and settings-specific styles.
		if ( $this->is_learnomy_admin_page( $hook, 'learnomy-settings' ) ) {
			wp_enqueue_style(
				'learnomy-settings',
				LEARNOMY_URL . 'assets/css/admin/settings.css',
				array( 'learnomy-admin' ),
				LEARNOMY_VERSION
			);

			wp_enqueue_script(
				'learnomy-settings-nav',
				LEARNOMY_URL . 'assets/js/admin/settings-nav.js',
				array( 'lucide' ),
				LEARNOMY_VERSION,
				true
			);

			// Payments settings — mode toggle, webhook copy, test connection.
			wp_enqueue_script(
				'learnomy-payments-settings',
				LEARNOMY_URL . 'assets/js/admin/payments-settings.js',
				array( 'learnomy-admin' ),
				LEARNOMY_VERSION,
				true
			);
			wp_set_script_translations( 'learnomy-payments-settings', 'learnomy', LEARNOMY_DIR . 'languages' );

			// Maintenance Tools — Send Test Email + Flush Rewrite Rules.
			// Lightweight script wiring two buttons in the Developer & Data
			// tab to their REST endpoints. Depends on learnomy-admin so
			// learnomyAdmin (restUrl/restNonce) is localized, and on
			// learnomy-toast so window.lrnToast is available for feedback.
			wp_enqueue_script(
				'learnomy-maintenance-tools',
				LEARNOMY_URL . 'assets/js/admin/maintenance-tools.js',
				array( 'learnomy-admin', 'learnomy-toast', 'learnomy-confirm-modal' ),
				LEARNOMY_VERSION,
				true
			);
			wp_set_script_translations( 'learnomy-maintenance-tools', 'learnomy', LEARNOMY_DIR . 'languages' );
		}

		// List pages — shared checkbox, bulk actions, filter bar, toast behaviors.
		$list_page_slugs = array(
			'learnomy-courses',
			'learnomy-lessons',
			'learnomy-quizzes',
			'learnomy-questions',
			'learnomy-students',
			'learnomy-certificates',
			'learnomy-categories',
			'learnomy-applications',
			// Reviews moderation list uses the shared bulk-action + checkbox
			// helpers AND reviews-list.js declares `learnomy-list-page` as a
			// dependency. Without this slug the dep was unmet, so WordPress
			// silently dropped reviews-list.js and every Approve/Reject/Spam
			// row action was inert (#9972748809).
			'learnomy-reviews',
			// Reports moderation queue: reports-queue.js declares
			// `learnomy-list-page` as a dependency, so this slug must be here or
			// WordPress silently drops the script and Dismiss / Mark-actioned go
			// inert (same class as reviews #9972748809).
			'learnomy-reports',
			'learnomy-announcements',
			'learnomy-coupons',
			'learnomy-memberships',
			'learnomy-subscriptions',
			'learnomy-email-log',
			'learnomy-webhooks',
			'learnomy-webhook-events',
			// Hidden edit submenu reuses the list-page helpers.
			'learnomy-membership-edit',
		);
		if ( $this->is_any_learnomy_admin_page( $hook, $list_page_slugs ) ) {
			wp_enqueue_style(
				'learnomy-list-page',
				LEARNOMY_URL . 'assets/css/admin/list-page.css',
				array( 'learnomy-admin' ),
				LEARNOMY_VERSION
			);

			wp_enqueue_script(
				'learnomy-list-page',
				LEARNOMY_URL . 'assets/js/admin/list-page.js',
				array( 'wp-i18n', 'lucide', 'learnomy-confirm-modal' ),
				LEARNOMY_VERSION,
				true
			);
			wp_set_script_translations( 'learnomy-list-page', 'learnomy', LEARNOMY_DIR . 'languages' );
		}

		// Webhooks page — copy / regenerate secret + select-all events.
		if ( $this->is_learnomy_admin_page( $hook, 'learnomy-webhooks' ) ) {
			wp_enqueue_script(
				'learnomy-admin-webhooks',
				LEARNOMY_URL . 'assets/js/admin/webhooks.js',
				// confirm-modal gates the destructive "Regenerate signing
				// secret" action; declared so window.lrnConfirm is guaranteed.
				array( 'wp-i18n', 'learnomy-toast', 'learnomy-confirm-modal' ),
				LEARNOMY_VERSION,
				true
			);
			// REST base + nonce for the incoming-enrollment recipe panel
			// (regenerate secret + send test enrollment).
			wp_localize_script(
				'learnomy-admin-webhooks',
				'lrnWebhooksIncoming',
				array(
					'restBase' => esc_url_raw( rest_url( 'learnomy/v1/admin/webhooks/incoming/' ) ),
					'nonce'    => wp_create_nonce( 'wp_rest' ),
				)
			);
		}

		// Webhook events viewer — payload modal and replay button wiring.
		if ( $this->is_learnomy_admin_page( $hook, 'learnomy-webhook-events' ) ) {
			wp_enqueue_script(
				'learnomy-webhook-events-viewer',
				LEARNOMY_URL . 'assets/js/admin/webhook-events-viewer.js',
				array( 'learnomy-admin' ),
				LEARNOMY_VERSION,
				true
			);

			wp_add_inline_script(
				'learnomy-webhook-events-viewer',
				'window.learnomyWebhookEvents = ' . wp_json_encode(
					array(
						'i18n' => array(
							'replaying' => __( 'Replaying…', 'learnomy' ),
						),
					)
				) . ';',
				'before'
			);
		}

		// Dashboard — ApexCharts and dashboard analytics.
		if ( $this->is_learnomy_admin_page( $hook, 'learnomy' ) ) {
			wp_enqueue_script(
				'apexcharts',
				LEARNOMY_URL . 'assets/js/vendor/apexcharts.min.js',
				array(),
				'4.3.0',
				true
			);

			wp_enqueue_script(
				'learnomy-dashboard',
				LEARNOMY_URL . 'assets/js/admin/dashboard.js',
				array( 'wp-i18n', 'apexcharts' ),
				LEARNOMY_VERSION,
				true
			);
			wp_set_script_translations( 'learnomy-dashboard', 'learnomy', LEARNOMY_DIR . 'languages' );

			wp_localize_script( 'learnomy-dashboard', 'learnomyDashboard', $this->gather_chart_data() );

			wp_enqueue_style(
				'learnomy-dashboard',
				LEARNOMY_URL . 'assets/css/admin/dashboard.css',
				array( 'learnomy-admin' ),
				LEARNOMY_VERSION
			);

			// Onboarding panel — Getting Started guide at the top of the dashboard.
			if ( ! get_option( 'learnomy_onboarding_dismissed', false ) ) {
				wp_enqueue_style(
					'learnomy-onboarding',
					LEARNOMY_URL . 'assets/css/admin/onboarding.css',
					array( 'learnomy-admin' ),
					LEARNOMY_VERSION
				);

				wp_enqueue_script(
					'learnomy-onboarding',
					LEARNOMY_URL . 'assets/js/admin/onboarding.js',
					array( 'learnomy-admin', 'lucide', 'learnomy-toast' ),
					LEARNOMY_VERSION,
					true
				);

				$onboarding_resolved = \Learnomy\Admin\Onboarding_State::resolve();

				wp_localize_script(
					'learnomy-onboarding',
					'LrnOnboarding',
					array(
						'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
						'nonce'        => wp_create_nonce( 'learnomy_admin' ),
						'currentState' => $onboarding_resolved,
						'isAllDone'    => \Learnomy\Admin\Onboarding_State::is_all_done( $onboarding_resolved ),
						'i18n'         => array(
							'dismissed' => __( 'Getting Started guide dismissed.', 'learnomy' ),
							'error'     => __( 'Something went wrong. Please try again.', 'learnomy' ),
						),
					)
				);
			}
		}

		// Lesson editor — Editor.js and lesson editor scripts/styles.
		if ( $this->is_learnomy_admin_page( $hook, 'learnomy-lesson-editor' ) ) {
			$this->enqueue_lesson_editor_assets();
		}

		// Student detail — the "Grant course access" form (dynamic select);
		// every other action on the page rides the generic actions.js path.
		if ( $this->is_learnomy_admin_page( $hook, 'learnomy-student-detail' ) ) {
			wp_enqueue_script(
				'learnomy-student-detail',
				LEARNOMY_URL . 'assets/js/admin/student-detail.js',
				array( 'wp-i18n', 'learnomy-admin', 'learnomy-toast' ),
				LEARNOMY_VERSION,
				true
			);
			wp_set_script_translations( 'learnomy-student-detail', 'learnomy', LEARNOMY_DIR . 'languages' );
		}

		// Course overview — the "Enroll a student" by-email control.
		if ( $this->is_learnomy_admin_page( $hook, 'learnomy-course-detail' ) ) {
			wp_enqueue_script(
				'learnomy-course-detail',
				LEARNOMY_URL . 'assets/js/admin/course-detail.js',
				array( 'wp-i18n', 'learnomy-admin', 'learnomy-toast' ),
				LEARNOMY_VERSION,
				true
			);
			wp_set_script_translations( 'learnomy-course-detail', 'learnomy', LEARNOMY_DIR . 'languages' );
		}

		// Subscription detail — the "Extend" date control.
		if ( $this->is_learnomy_admin_page( $hook, 'learnomy-subscription-detail' ) ) {
			wp_enqueue_script(
				'learnomy-subscription-detail',
				LEARNOMY_URL . 'assets/js/admin/subscription-detail.js',
				array( 'wp-i18n', 'learnomy-admin', 'learnomy-toast' ),
				LEARNOMY_VERSION,
				true
			);
			wp_set_script_translations( 'learnomy-subscription-detail', 'learnomy', LEARNOMY_DIR . 'languages' );
		}

		// Question editor — per-type answers UI + hint field + token parser.
		if ( $this->is_learnomy_admin_page( $hook, 'learnomy-question-editor' ) ) {
			wp_enqueue_style(
				'learnomy-question-editor',
				LEARNOMY_URL . 'assets/css/admin/question-editor.css',
				array( 'learnomy-admin' ),
				LEARNOMY_VERSION
			);

			// A3: Editor.js vendor + the shared tools factory back the rich question
			// stem field, so they become dependencies of the editor script (EditorJS
			// + window.lrnEditorTools load before the mount runs).
			$lrn_q_editorjs_handles = $this->enqueue_editorjs_vendor();

			wp_enqueue_script(
				'learnomy-question-editor',
				LEARNOMY_URL . 'assets/js/admin/question-editor.js',
				// confirm-modal gates the Delete Question action; the editorjs
				// handles back the rich stem field.
				array_merge( array( 'wp-i18n', 'lucide', 'learnomy-confirm-modal', 'learnomy-toast' ), $lrn_q_editorjs_handles ),
				LEARNOMY_VERSION,
				true
			);
			wp_set_script_translations( 'learnomy-question-editor', 'learnomy', LEARNOMY_DIR . 'languages' );

			// A3: seed the rich stem editor with the saved stem (dual-format read,
			// INV-8: for_editor normalises HTML or Editor.js JSON to editor blocks)
			// + the REST base/nonce the shared tools factory needs.
			$lrn_q_edit_id = isset( $_GET['question_id'] ) ? absint( $_GET['question_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$lrn_q_edit    = $lrn_q_edit_id ? \Learnomy\Models\Question::find( $lrn_q_edit_id ) : null;

			wp_localize_script(
				'learnomy-question-editor',
				'learnomyQuestionEditor',
				array(
					'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
					'nonce'     => wp_create_nonce( 'learnomy_admin' ),
					'restUrl'   => esc_url_raw( rest_url( 'learnomy/v1/' ) ),
					'restNonce' => wp_create_nonce( 'wp_rest' ),
					'stem'      => $lrn_q_edit
						? json_decode( \Learnomy\Content\Content_HTML::for_editor( (string) $lrn_q_edit->stem ), true )
						: null,
					'i18n'      => array(
						'addAnswer'      => __( 'Add answer', 'learnomy' ),
						'removeAnswer'   => __( 'Remove', 'learnomy' ),
						'correct'        => __( 'Correct', 'learnomy' ),
						'feedback'       => __( 'Feedback (optional)', 'learnomy' ),
						'fraction'       => __( 'Credit', 'learnomy' ),
						'caseSense'      => __( 'Case-sensitive', 'learnomy' ),
						/* translators: %s: blank token name. */
							'blankLabel' => __( 'Blank %s accepted answers', 'learnomy' ),
						'blankHelp'      => __( 'Each row is one acceptable spelling for this blank.', 'learnomy' ),
						'addBlankLine'   => __( 'Add acceptable answer', 'learnomy' ),
						'leftItem'       => __( 'Left item', 'learnomy' ),
						'rightItem'      => __( 'Right pair', 'learnomy' ),
						'addPair'        => __( 'Add pair', 'learnomy' ),
						'orderingItem'   => __( 'Ordered item', 'learnomy' ),
						'addOrderItem'   => __( 'Add item', 'learnomy' ),
						'essayNote'      => __( 'Essays are graded manually by the instructor. The explanation field is shown to students, so it is not a scoring rubric.', 'learnomy' ),
						'noTokens'       => __( 'Add {{blank1}}, {{blank2}}… tokens in the question text to create blanks.', 'learnomy' ),
						'trueLabel'      => __( 'True', 'learnomy' ),
						'falseLabel'     => __( 'False', 'learnomy' ),
						'deleteTitle'    => __( 'Delete this question?', 'learnomy' ),
						'deleteMessage'  => sprintf( /* translators: %s: singular Quiz label */ __( 'This permanently removes the question and detaches it from every %s. Past attempt responses keep their recorded answer. This cannot be undone.', 'learnomy' ), \Learnomy\quiz_label() ),
					),
				)
			);
		}

		// Question bank list — picker filters + CSV import/export modals.
		// ALSO enqueued on the quiz editor page because the picker modal
		// lives there too (the "Add Questions from Bank" button on the quiz
		// edit screen depends on this script's click handler at line ~243
		// of question-bank.js). The quiz editor isn't in $list_page_slugs
		// above, so `learnomy-list-page` was missing as a dependency and
		// WordPress silently refused to enqueue this script — leaving the
		// button inert (bug card "Add Questions from Bank Not Working").
		// Force the list-page baseline whenever this script needs it.
		if ( $this->is_any_learnomy_admin_page( $hook, array( 'learnomy-questions', 'learnomy-edit-quiz' ) ) ) {
			if ( ! wp_script_is( 'learnomy-list-page', 'enqueued' ) ) {
				wp_enqueue_style(
					'learnomy-list-page',
					LEARNOMY_URL . 'assets/css/admin/list-page.css',
					array( 'learnomy-admin' ),
					LEARNOMY_VERSION
				);
				wp_enqueue_script(
					'learnomy-list-page',
					LEARNOMY_URL . 'assets/js/admin/list-page.js',
					array( 'wp-i18n', 'lucide', 'learnomy-confirm-modal' ),
					LEARNOMY_VERSION,
					true
				);
				wp_set_script_translations( 'learnomy-list-page', 'learnomy', LEARNOMY_DIR . 'languages' );
			}

			wp_enqueue_script(
				'learnomy-question-bank',
				LEARNOMY_URL . 'assets/js/admin/question-bank.js',
				array( 'wp-i18n', 'learnomy-list-page', 'learnomy-toast' ),
				LEARNOMY_VERSION,
				true
			);
			wp_set_script_translations( 'learnomy-question-bank', 'learnomy', LEARNOMY_DIR . 'languages' );

				// The "Add from question bank" picker (question-bank.js) reads its
				// REST config + the Type/Category filter options from the
				// learnomyQuizEditor global. Without this its Type and Category
				// dropdowns render with only the "All …" placeholder.
				wp_localize_script(
					'learnomy-question-bank',
					'learnomyQuizEditor',
					array(
						'restUrl'            => rest_url( 'learnomy/v1/' ),
						'restNonce'          => wp_create_nonce( 'wp_rest' ),
						'questionTypes'      => \Learnomy\QuestionTypes::all(),
						'questionCategories' => array_map(
							static function ( $category ) {
								return array(
									'id'   => (int) $category->id,
									'name' => (string) $category->name,
								);
							},
							\Learnomy\Models\QuestionCategory::where( array(), 'name', 'ASC' )
						),
					)
				);

			wp_localize_script(
				'learnomy-question-bank',
				'learnomyQuestionBank',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'learnomy_admin' ),
					'i18n'    => array(
						'importTitle'      => __( 'Import questions from CSV', 'learnomy' ),
						'importConfirm'    => __( 'Upload', 'learnomy' ),
						'importHelp'       => __( 'CSV columns: title, type, difficulty, category_slug, status, hint, body_text, answers_json.', 'learnomy' ),
						'cancel'           => __( 'Cancel', 'learnomy' ),
						'selectFile'       => __( 'Choose a CSV file', 'learnomy' ),
						/* translators: 1: number of rows imported. 2: number of rows skipped. */
							'importResult' => __( 'Imported %1$d, skipped %2$d.', 'learnomy' ),
						'exporting'        => __( 'Preparing download…', 'learnomy' ),
					),
				)
			);
		}

		// Course builder — separate stylesheet and vanilla JS module.
		if ( $this->is_learnomy_admin_page( $hook, 'learnomy-course-builder' ) ) {
			wp_enqueue_style(
				'learnomy-course-builder',
				LEARNOMY_URL . 'assets/css/admin/course-builder.css',
				array( 'learnomy-admin' ),
				LEARNOMY_VERSION
			);

			// Drag-drop visual states — shared with the quiz editor.
			wp_enqueue_style(
				'learnomy-drag-drop',
				LEARNOMY_URL . 'assets/css/admin/drag-drop.css',
				array( 'learnomy-course-builder' ),
				LEARNOMY_VERSION
			);

			wp_enqueue_script(
				'learnomy-course-builder',
				LEARNOMY_URL . 'assets/js/admin/course-builder.js',
				array( 'wp-i18n', 'learnomy-confirm-modal', 'learnomy-prompt-modal' ),
				LEARNOMY_VERSION,
				true
			);
			wp_set_script_translations( 'learnomy-course-builder', 'learnomy', LEARNOMY_DIR . 'languages' );

			wp_localize_script(
				'learnomy-course-builder',
				'learnomyBuilder',
				array(
					'restUrl'  => rest_url( 'learnomy/v1/' ),
					'nonce'    => wp_create_nonce( 'wp_rest' ),
					'courseId' => isset( $_GET['course_id'] ) ? absint( $_GET['course_id'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				)
			);
		}

		// Course form — media library, AJAX save handler, and the
		// collapsible-section styles that drive the 6 Phase 1 settings cards.
		if ( $this->is_any_learnomy_admin_page( $hook, array( 'learnomy-new-course', 'learnomy-edit-course' ) ) ) {
			wp_enqueue_media();
			// `wp_editor()` is still used by the Completion + Welcome
			// message helper fields — keep TinyMCE around for them.
			wp_enqueue_editor();

			wp_enqueue_style(
				'learnomy-course-form',
				LEARNOMY_URL . 'assets/css/admin/course-form.css',
				array( 'learnomy-admin' ),
				LEARNOMY_VERSION
			);

			wp_enqueue_script(
				'learnomy-course-form',
				LEARNOMY_URL . 'assets/js/admin/course-form.js',
				array( 'wp-i18n', 'learnomy-admin', 'learnomy-ext-fields' ),
				LEARNOMY_VERSION,
				true
			);
			wp_set_script_translations( 'learnomy-course-form', 'learnomy', LEARNOMY_DIR . 'languages' );

			$this->enqueue_course_description_editor_assets();
		}

		// Quiz editor — collapsible settings sections, template picker,
		// slot list. All assets are enqueued here (drag-drop.css and the
		// editor JS included) so the view template stays purely presentational.
		if ( $this->is_any_learnomy_admin_page( $hook, array( 'learnomy-new-quiz', 'learnomy-edit-quiz' ) ) ) {
			wp_enqueue_style(
				'learnomy-quiz-editor',
				LEARNOMY_URL . 'assets/css/admin/quiz-editor.css',
				array( 'learnomy-admin' ),
				LEARNOMY_VERSION
			);
			wp_enqueue_style(
				'learnomy-drag-drop',
				LEARNOMY_URL . 'assets/css/admin/drag-drop.css',
				array( 'learnomy-quiz-editor' ),
				LEARNOMY_VERSION
			);
			// Editor.js vendor bundle for the rich description field; its handles
			// become dependencies so EditorJS + tools load before the mount runs.
			$lrn_editorjs_handles = $this->enqueue_editorjs_vendor();

			wp_enqueue_script(
				'learnomy-quiz-editor',
				LEARNOMY_URL . 'assets/js/admin/quiz-editor.js',
				// confirm-modal gates the Delete Quiz action (declared so
				// window.lrnConfirm is present + load-ordered); the editorjs
				// handles back the rich description field.
				array_merge( array( 'wp-i18n', 'learnomy-confirm-modal' ), $lrn_editorjs_handles ),
				LEARNOMY_VERSION,
				true
			);
			wp_set_script_translations( 'learnomy-quiz-editor', 'learnomy', LEARNOMY_DIR . 'languages' );
		}

		// Certificate designer — Canva-like visual template builder.
		if ( $this->is_learnomy_admin_page( $hook, 'learnomy-certificate-designer' ) ) {
			wp_enqueue_media();

			wp_enqueue_style(
				'learnomy-cert-designer',
				LEARNOMY_URL . 'assets/css/admin/certificate-designer.css',
				array( 'learnomy-admin' ),
				LEARNOMY_VERSION
			);

			// Bundled, MIT-licensed QR generator (no external HTTP) used to
			// render a real QR preview on the designer canvas. See
			// assets/vendor/qrcode/README.md.
			wp_enqueue_script(
				'learnomy-qrcode',
				LEARNOMY_URL . 'assets/vendor/qrcode/qrcode.js',
				array(),
				LEARNOMY_VERSION,
				true
			);

			wp_enqueue_script(
				'learnomy-cert-designer',
				LEARNOMY_URL . 'assets/js/admin/certificate-designer.js',
				array( 'wp-i18n', 'learnomy-qrcode' ),
				LEARNOMY_VERSION,
				true
			);
			wp_set_script_translations( 'learnomy-cert-designer', 'learnomy', LEARNOMY_DIR . 'languages' );

			$designer_template_id = isset( $_GET['template_id'] ) ? absint( $_GET['template_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$designer_template    = $designer_template_id ? CertificateTemplate::find( $designer_template_id ) : null;

			wp_localize_script(
				'learnomy-cert-designer',
				'learnomyCertDesigner',
				array(
					'restUrl'         => rest_url( 'learnomy/v1/' ),
					'nonce'           => wp_create_nonce( 'wp_rest' ),
					// Signed token for the new-tab preview. See
					// Certificates_Controller::verify_preview_token() —
					// preview auth deliberately bypasses cookie+nonce so
					// `window.open()` works even when browsers don't carry
					// the auth cookie on programmatically-opened tabs.
					'previewToken'    => $designer_template_id
						? \Learnomy\API\Certificates_Controller::mint_preview_token( $designer_template_id )
						: '',
					'templateId'      => $designer_template_id,
					'designJson'      => $designer_template && ! empty( $designer_template->design_json )
						? json_decode( $designer_template->design_json, true )
						: null,
					'templateTitle'   => $designer_template ? $designer_template->title : '',
					'orientation'     => $designer_template ? ( $designer_template->orientation ?? 'landscape' ) : 'landscape',
					// Sample verify URL the designer encodes into the QR preview
					// so the canvas QR matches a real rendered certificate.
					'verifyUrlSample' => \Learnomy\route_url( 'certificate-verify', array( 'slug' => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890' ) ),
					'i18n'            => array(
						'saved'         => __( 'Template saved.', 'learnomy' ),
						'saveError'     => __( 'Could not save template.', 'learnomy' ),
						'titleRequired' => __( 'Please enter a template name.', 'learnomy' ),
						'deleteConfirm' => __( 'Delete this element?', 'learnomy' ),
						'untitled'      => __( 'Untitled Template', 'learnomy' ),
					),
				)
			);
		}

		// Announcements page — AJAX form submission.
		if ( $this->is_learnomy_admin_page( $hook, 'learnomy-announcements' ) ) {
			wp_enqueue_script(
				'learnomy-announcements',
				LEARNOMY_URL . 'assets/js/admin/announcements.js',
				array( 'learnomy-admin', 'learnomy-list-page' ),
				LEARNOMY_VERSION,
				true
			);
			wp_set_script_translations( 'learnomy-announcements', 'learnomy', LEARNOMY_DIR . 'languages' );
		}

		// Categories & tags page — REST-based CRUD.
		if ( $this->is_learnomy_admin_page( $hook, 'learnomy-categories' ) ) {
			wp_enqueue_script(
				'learnomy-categories',
				LEARNOMY_URL . 'assets/js/admin/categories.js',
				array( 'wp-i18n', 'learnomy-admin', 'learnomy-confirm-modal', 'learnomy-toast' ),
				LEARNOMY_VERSION,
				true
			);
			wp_set_script_translations( 'learnomy-categories', 'learnomy', LEARNOMY_DIR . 'languages' );
		}

		// Memberships pages (plans list, plan editor, subscriptions list).
		$membership_page_slugs = array(
			'learnomy-memberships',
			'learnomy-subscriptions',
			'learnomy-membership-edit',
		);
		if ( $this->is_any_learnomy_admin_page( $hook, $membership_page_slugs ) ) {
			// Editor uses wp.media for the thumbnail picker.
			if ( $this->is_learnomy_admin_page( $hook, 'learnomy-membership-edit' ) ) {
				wp_enqueue_media();
			}

			wp_enqueue_style(
				'learnomy-memberships',
				LEARNOMY_URL . 'assets/css/admin/memberships.css',
				array( 'learnomy-admin' ),
				LEARNOMY_VERSION
			);

			wp_enqueue_script(
				'learnomy-memberships',
				LEARNOMY_URL . 'assets/js/admin/memberships.js',
				array( 'learnomy-admin', 'learnomy-confirm-modal', 'learnomy-list-page' ),
				LEARNOMY_VERSION,
				true
			);
			wp_set_script_translations( 'learnomy-memberships', 'learnomy', LEARNOMY_DIR . 'languages' );
		}

		// Reviews moderation page.
		if ( $this->is_learnomy_admin_page( $hook, 'learnomy-reviews' ) ) {
			wp_enqueue_style(
				'learnomy-reviews-admin',
				LEARNOMY_URL . 'assets/css/admin/reviews.css',
				array( 'learnomy-admin' ),
				LEARNOMY_VERSION
			);

			wp_enqueue_script(
				'learnomy-reviews-admin',
				LEARNOMY_URL . 'assets/js/admin/reviews-list.js',
				array( 'wp-i18n', 'learnomy-admin', 'learnomy-confirm-modal', 'learnomy-list-page' ),
				LEARNOMY_VERSION,
				true
			);
			wp_set_script_translations( 'learnomy-reviews-admin', 'learnomy', LEARNOMY_DIR . 'languages' );
		}

		// Reports moderation queue.
		if ( $this->is_learnomy_admin_page( $hook, 'learnomy-reports' ) ) {
			wp_enqueue_style(
				'learnomy-reports-admin',
				LEARNOMY_URL . 'assets/css/admin/reports-queue.css',
				array( 'learnomy-admin' ),
				LEARNOMY_VERSION
			);

			wp_enqueue_script(
				'learnomy-reports-admin',
				LEARNOMY_URL . 'assets/js/admin/reports-queue.js',
				array( 'wp-i18n', 'learnomy-admin', 'learnomy-confirm-modal', 'learnomy-list-page' ),
				LEARNOMY_VERSION,
				true
			);
			wp_set_script_translations( 'learnomy-reports-admin', 'learnomy', LEARNOMY_DIR . 'languages' );
		}
	}

	// ── Render methods ────────────────────────────────────────────────────────

	public function render_dashboard(): void {
		$stats      = $this->gather_dashboard_stats();
		$chart_data = $this->gather_chart_data();
		$has_demo   = (bool) get_option( 'learnomy_demo_data' );
		include LEARNOMY_DIR . 'includes/admin/views/dashboard.php';
	}

	/**
	 * Whether the Learnomy Pro plugin is loaded on this site.
	 *
	 * Pro defines `LEARNOMY_PRO_VERSION` at file load time, so the constant
	 * is set well before `admin_menu` fires. Third-party code can override
	 * via the `learnomy_pro_active` filter — useful for tests that want to
	 * exercise the Free-only render path.
	 */
	private function is_pro_active(): bool {
		return (bool) apply_filters( 'learnomy_pro_active', defined( 'LEARNOMY_PRO_VERSION' ) );
	}

	/**
	 * Landing page for the Pro Tools sidebar slot.
	 *
	 * Renders a card grid of every registered Pro extension. Pro extensions
	 * publish their card via the `learnomy_pro_tools_cards` filter so this
	 * Free file never has to know about Pro internals.
	 */
	public function render_pro_tools_hub(): void {
		/**
		 * Filter: Pro Tools hub card list.
		 *
		 * Each card is `[ 'slug' => string, 'label' => string,
		 * 'icon' => string (lucide name), 'description' => string ]`.
		 * Pro extensions hook this filter from their `class-extension.php`
		 * to publish a card. The hub renders the cards in registration
		 * order; use `priority` on the filter call to control ordering.
		 *
		 * @param array<int, array<string, string>> $cards Existing cards.
		 */
		$raw_cards = apply_filters( 'learnomy_pro_tools_cards', array() );

		$cards = array();
		foreach ( (array) $raw_cards as $card ) {
			if ( empty( $card['slug'] ) || empty( $card['label'] ) ) {
				continue;
			}

			$cards[] = array(
				'slug'        => (string) $card['slug'],
				'label'       => (string) $card['label'],
				'icon'        => (string) ( $card['icon'] ?? 'square' ),
				'description' => (string) ( $card['description'] ?? '' ),
				// Carried through to the view, which groups the grid by it.
				// This normalisation used to drop any key it did not name, so
				// a card could declare a group and still land in "More tools"
				// with everything else — the grouping silently did nothing.
				'group'       => (string) ( $card['group'] ?? '' ),
				'url'         => admin_url( 'admin.php?page=' . rawurlencode( (string) $card['slug'] ) ),
			);
		}

		include LEARNOMY_DIR . 'includes/admin/views/pro-tools-hub.php';
	}

	/**
	 * Landing page for the Learnomy Commerce top-level menu.
	 *
	 * Renders a card grid of every commerce sub-page that is currently
	 * registered for the user — so the operator gets a single place to scan
	 * money flow without committing to a specific page first.
	 */
	public function render_commerce_overview(): void {
		// Build the list of registered Commerce children dynamically so the
		// overview matches whatever modules and Pro extensions are active.
		// Pro registers Membership Analytics + Stripe Payouts via the
		// `learnomy_admin_commerce_menu_items` action; both end up in
		// $submenu['learnomy-commerce'] before this method is called.
		global $submenu;

		$rows = $submenu['learnomy-commerce'] ?? array();

		// Hidden pages (Edit Membership Plan) live in the same submenu so
		// WordPress can resolve them, but they must not appear as cards.
		$hidden_slugs = array_keys( $this->hidden_submenu_pages() );

		$cards = array();
		foreach ( $rows as $row ) {
			$slug = $row[2] ?? '';

			// Skip the overview itself, section header rows, and hidden pages.
			if (
				'' === $slug
				|| 'learnomy-commerce' === $slug
				|| str_starts_with( $slug, '#' )
				|| in_array( $slug, $hidden_slugs, true )
			) {
				continue;
			}

			$cards[] = array(
				'slug'  => $slug,
				'label' => wp_strip_all_tags( $row[0] ?? $slug ),
				'url'   => admin_url( 'admin.php?page=' . rawurlencode( $slug ) ),
			);
		}

		// KPI row above the nav grid — gives the operator an at-a-glance
		// money-flow snapshot so the overview earns its name. Before
		// 2026-05-23 (REPORT.md §3) this page was nav cards only with
		// ~80% of the viewport blank — the subtitle promised "money flow
		// at a glance" but the page showed zero money. Now: MRR + active
		// subscribers (from Subscription::get_mrr_stats), lifetime revenue
		// + transaction count (from Transaction::total_completed_revenue +
		// get_revenue_summary). All values come from Free models so no
		// new SQL hits.
		$mrr_stats              = \Learnomy\Models\Subscription::get_mrr_stats();
		$revenue_by_status      = \Learnomy\Models\Transaction::get_revenue_summary();
		$completed_revenue      = \Learnomy\Models\Transaction::total_completed_revenue();
		$completed_transactions = (int) ( $revenue_by_status['completed']['count'] ?? 0 );
		$settings               = (array) get_option( 'learnomy_settings', array() );
		$currency_symbol        = isset( $settings['currency_symbol'] ) && '' !== $settings['currency_symbol']
			? (string) $settings['currency_symbol']
			: '$';

		$commerce_kpis = array(
			array(
				'icon'  => 'repeat',
				'value' => \Learnomy\format_price( (float) ( $mrr_stats['monthly_recurring'] ?? 0 ) ),
				'label' => __( 'Monthly recurring', 'learnomy' ),
			),
			array(
				'icon'  => 'users-round',
				'value' => number_format_i18n( (int) ( $mrr_stats['active_count'] ?? 0 ) ),
				'label' => __( 'Active subscribers', 'learnomy' ),
			),
			array(
				'icon'  => 'banknote',
				'value' => \Learnomy\format_price( $completed_revenue ),
				'label' => __( 'Lifetime revenue', 'learnomy' ),
			),
			array(
				'icon'  => 'receipt',
				'value' => number_format_i18n( $completed_transactions ),
				'label' => __( 'Lifetime transactions', 'learnomy' ),
			),
		);

		/*
		 * Three-jobs routing strip (Basecamp 10132713132). The nav cards below
		 * list our FEATURE names; an owner arrives with a JOB ("sell one
		 * course", "run a subscription", "sell seats to a school") and should
		 * not need to already know which feature does it. Each job routes to
		 * the screen that does the work, honestly module-state-aware: a job
		 * whose module is off routes to Modules to switch it on, and the seat
		 * job routes to Spaces (Pro) or to the What's in Pro catalog on a
		 * Free-only site - a real destination either way, never a dead link.
		 */
		$kernel        = \Learnomy\Modules\Module_Kernel::instance();
		$spaces_ready  = class_exists( '\\Learnomy_Pro\\Extensions\\Spaces\\Extension' );
		$commerce_jobs = array(
			array(
				'icon'  => 'tag',
				/* translators: %s: singular Course label */
				'label' => sprintf( __( 'Sell one %s', 'learnomy' ), \Learnomy\course_label() ),
				/* translators: %s: singular Course label */
				'hint'  => sprintf( __( 'Set a price in the %s editor. Buyers get lifetime or time-limited access.', 'learnomy' ), \Learnomy\course_label() ),
				'url'   => admin_url( 'admin.php?page=learnomy-courses' ),
			),
			$kernel->is_enabled( 'membership' )
				? array(
					'icon'  => 'repeat',
					'label' => __( 'Run an academy subscription', 'learnomy' ),
					/* translators: %s: plural Courses label */
					'hint'  => sprintf( __( 'One recurring plan covering many %s. Manage plans under Memberships.', 'learnomy' ), \Learnomy\course_label( true ) ),
					'url'   => admin_url( 'admin.php?page=learnomy-memberships' ),
				)
				: array(
					'icon'  => 'repeat',
					'label' => __( 'Run an academy subscription', 'learnomy' ),
					'hint'  => __( 'Needs the Memberships module. Turn it on to sell recurring access.', 'learnomy' ),
					'url'   => admin_url( 'admin.php?page=learnomy-modules' ),
				),
			$spaces_ready
				? array(
					'icon'  => 'school',
					'label' => __( 'Sell seats to a school or team', 'learnomy' ),
					'hint'  => __( 'A Space Plan bundles seats; the buyer gets invites and progress reports.', 'learnomy' ),
					'url'   => admin_url( 'admin.php?page=learnomy-space-plans' ),
				)
				: array(
					'icon'  => 'school',
					'label' => __( 'Sell seats to a school or team', 'learnomy' ),
					'hint'  => __( 'Powered by Spaces in Learnomy Pro. See what Pro adds.', 'learnomy' ),
					'url'   => admin_url( 'admin.php?page=learnomy-pro-features' ),
				),
		);

		include LEARNOMY_DIR . 'includes/admin/views/commerce-overview.php';
	}

	public function render_courses(): void {
		$paged           = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status_filter   = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search          = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$category_filter = isset( $_GET['category_id'] ) ? absint( $_GET['category_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tag_filter      = isset( $_GET['tag_id'] ) ? absint( $_GET['tag_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per_page        = 20;

		// Instructor scope — non-admins see only courses they authored.
		// Admins (current_user_can manage_options) see everything. Per
		// Basecamp #9919785119 / #9919796872 — instructors can't manage
		// content from the admin pages if those pages don't even show
		// their own rows.
		$author_scope = current_user_can( 'manage_options' ) ? 0 : get_current_user_id();

		// Trash-aware: a specific status shows just that status; the "all" view
		// excludes trashed courses (they appear only under the Trash tab).
		// Category is the direct lrn_courses.category_id column; the tag filter
		// JOINs lrn_tag_map (object_type='course') inside the model.
		$courses     = Course::list_for_admin( $status_filter, 'created_at', 'DESC', $per_page, ( $paged - 1 ) * $per_page, $author_scope, $category_filter, $tag_filter, $search );
		$total       = Course::count_for_admin( $status_filter, $author_scope, $category_filter, $tag_filter, $search );
		$total_pages = (int) ceil( $total / $per_page );

		// Filter dropdown data — course categories (with course counts) + the
		// course tag vocabulary.
		$all_categories = Category::list_with_course_count( 'course' );
		$all_tags       = Tag::where( array( 'type' => 'course' ), 'name', 'ASC' );

		// Batch the author display names in one WP_User_Query so the row view
		// doesn't call get_userdata() per row (big-site checklist #3, no N+1).
		$author_ids     = array_values( array_unique( array_filter( array_map( static fn( $c ) => (int) ( $c->author_id ?? 0 ), $courses ) ) ) );
		$course_authors = array();
		if ( $author_ids ) {
			foreach ( get_users(
				array(
					'include' => $author_ids,
					'fields'  => array( 'ID', 'display_name' ),
				)
			) as $u ) {
				$course_authors[ (int) $u->ID ] = $u->display_name;
			}
		}

		include LEARNOMY_DIR . 'includes/admin/views/courses.php';
	}

	/**
	 * The course set a filter dropdown may offer: every course the current user
	 * manages, or all of them for an administrator.
	 *
	 * Three list screens built this by hand as `Course::where(['author_id' =>
	 * me])`, which omits every course the user co-teaches — so a co-instructor
	 * filtering by course could not select the course whose lessons they were
	 * looking at. One reader now, ordered by title like the hand-rolled version.
	 *
	 * This is a FILTER source, not an attach target: it deliberately does NOT
	 * use Course::selectable(), because content stranded under a trashed course
	 * is found by filtering to it and that is the recovery path (Basecamp
	 * 10153289435).
	 *
	 * @return object[]
	 */
	private static function manageable_courses_for_filter(): array {
		$course_ids = Permission_Engine::owned_course_ids( get_current_user_id(), 'manage_course' );

		// null = administrator, no restriction. An empty array is the opposite
		// (manages nothing) and must return an empty dropdown, not everything.
		if ( null === $course_ids ) {
			return Course::where( array(), 'title', 'ASC' );
		}
		if ( empty( $course_ids ) ) {
			return array();
		}

		$courses = array_values( Course::find_in( $course_ids ) );
		usort( $courses, static fn( $a, $b ) => strcasecmp( (string) ( $a->title ?? '' ), (string) ( $b->title ?? '' ) ) );

		return $courses;
	}

	public function render_quizzes(): void {
		$paged         = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$type_filter   = isset( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$course_filter = isset( $_GET['course_id'] ) ? absint( $_GET['course_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tag_filter    = isset( $_GET['tag_id'] ) ? absint( $_GET['tag_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per_page      = 20;

		$where = array();
		if ( 'all' !== $type_filter ) {
			$where['mode'] = $type_filter;
		}
		if ( $course_filter ) {
			$where['course_id'] = $course_filter;
		}
		// Instructor scope — quizzes they authored, PLUS quizzes on a course
		// they manage. `author_id = me` alone hid an instructor's own final
		// exam the moment an admin created it, while that same quiz still
		// rendered to their students and still opened from the course builder
		// (Basecamp 10184327422). One answerer, so the list cannot disagree
		// with the ownership gate on the editor it links to.
		$scope = Permission_Engine::instructor_scope_sql( get_current_user_id(), 'manage_course', 'q.' );

		// The scope is an OR group, which Model::where() cannot express, so a
		// scoped list always routes through list_filtered/count_filtered. A tag
		// filter needs the lrn_tag_map JOIN for the same reason; plain
		// where()/count() stays the cheap path for an unscoped, untagged admin.
		if ( $tag_filter > 0 || null !== $scope ) {
			$quizzes = Quiz::list_filtered( $where, 'created_at', 'DESC', $per_page, ( $paged - 1 ) * $per_page, $tag_filter, $scope );
			$total   = Quiz::count_filtered( $where, $tag_filter, $scope );
		} else {
			$quizzes = Quiz::where( $where, 'created_at', 'DESC', $per_page, ( $paged - 1 ) * $per_page );
			$total   = Quiz::count( $where );
		}
		$total_pages = (int) ceil( $total / $per_page );

		// Batch the parent course + lesson lookups so the row view reads from
		// maps instead of ::find() per row (big-site checklist #3, no N+1).
		$courses_map = \Learnomy\Models\Course::find_in( array_map( static fn( $q ) => (int) ( $q->course_id ?? 0 ), $quizzes ) );
		$lessons_map = Lesson::find_in( array_map( static fn( $q ) => (int) ( $q->lesson_id ?? 0 ), $quizzes ) );

		// Live question counts. The denormalised lrn_quizzes.question_count column
		// is never maintained on slot add/remove, so it shows 0/stale — populate
		// each row from a single grouped slot count (matches the detail view;
		// Basecamp 10109341075). One query for the whole page, no N+1.
		$question_counts = \Learnomy\Models\QuizSlot::counts_for_quizzes(
			array_map( static fn( $q ) => (int) $q->id, $quizzes )
		);
		foreach ( $quizzes as $lrn_quiz ) {
			$lrn_quiz->question_count = $question_counts[ (int) $lrn_quiz->id ] ?? 0;
		}
		// Course filter dropdown — the same course set the list is scoped to,
		// so the filter can never offer a course with no visible rows or omit
		// one that has them.
		$all_courses = self::manageable_courses_for_filter();
		// Tag filter dropdown — quiz vocabulary only.
		$all_tags = Tag::where( array( 'type' => 'quiz' ), 'name', 'ASC' );

		include LEARNOMY_DIR . 'includes/admin/views/quizzes.php';
	}

	/**
	 * Course ids whose quizzes the current user may grade.
	 *
	 * One reader — Grading_Service — shared with REST and the front-end teaching
	 * shell, so all three surfaces list the same work and refuse the same work.
	 *
	 * @return int[]|null
	 */
	private function grading_scope_course_ids(): ?array {
		return \Learnomy\Services\Grading_Service::scope_course_ids( get_current_user_id() );
	}

	/** Pending-grading count for the current user, used by the menu badge. */
	private function pending_grading_count(): int {
		return QuestionResponse::count_pending_grading( $this->grading_scope_course_ids() );
	}

	/**
	 * Grading screen. Two modes:
	 *  - no `attempt` param: the inbox — submitted attempts awaiting manual
	 *    grading, ownership-scoped and paginated.
	 *  - `attempt` param: the per-attempt grader — every response with the
	 *    student's answer, an inline score + feedback control for ungraded ones,
	 *    saved through the existing `POST learnomy/v1/attempts/{id}/grade` route.
	 *
	 * Reuses `Quiz_Service::grade_question()` (via REST) for persistence — this
	 * page is UI only and adds no parallel grading logic.
	 */
	public function render_quiz_grading(): void {
		$attempt_id = isset( $_GET['attempt'] ) ? absint( $_GET['attempt'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$scope      = $this->grading_scope_course_ids();

		if ( $attempt_id > 0 ) {
			$this->render_quiz_grading_detail( $attempt_id, $scope );
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only GET navigation.
		$paged     = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
		$status    = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$course_id = isset( $_GET['course_id'] ) ? absint( $_GET['course_id'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$per_page  = 20;
		$status    = \Learnomy\Services\Grading_Service::normalize_status( $status );
		$effective = \Learnomy\Services\Grading_Service::apply_course_filter( $course_id, $scope );

		$queue       = \Learnomy\Services\Grading_Service::queue( $effective, $status, $per_page, ( $paged - 1 ) * $per_page );
		$pending     = $queue['rows'];
		$total       = (int) $queue['total'];
		$total_pages = (int) ceil( $total / $per_page );

		// The course filter offers exactly the queue's own scope, so a selection
		// can never name a course that returns nothing.
		$filter_courses = \Learnomy\Services\Grading_Service::filter_courses( get_current_user_id(), $scope );

		// Batch the student + quiz lookups so the row view issues no per-row
		// queries (big-site checklist #3).
		$students_map = array();
		foreach ( $pending as $row ) {
			$uid = (int) $row->user_id;
			if ( ! isset( $students_map[ $uid ] ) ) {
				$students_map[ $uid ] = get_userdata( $uid );
			}
		}

		include LEARNOMY_DIR . 'includes/admin/views/quiz-grading.php';
	}

	/**
	 * Per-attempt grader. Enforces grading ownership for non-admins via
	 * Permission_Engine before exposing a single student's answers.
	 *
	 * @param int        $attempt_id Attempt to grade.
	 * @param int[]|null $scope      Owned course ids (null = admin).
	 */
	private function render_quiz_grading_detail( int $attempt_id, ?array $scope ): void {
		unset( $scope );

		// Ownership + the whole per-attempt payload come from Grading_Service —
		// the same assembler the front-end grader uses, so the two screens can
		// never disagree about what an attempt contains or who may open it. It
		// returns null on both "missing" and "not yours", which is the only
		// answer that leaks nothing about another instructor's students.
		$detail = \Learnomy\Services\Grading_Service::attempt_detail( $attempt_id, get_current_user_id() );

		if ( null === $detail ) {
			// The marker WordPress relocates admin notices to. Without it,
			// common.js falls back to "after the first h1" - which here is inside
			// .lrn-page-header, a flex row, so a notice lands beside the title
			// instead of above the body (Basecamp 10203391193). The error notice
			// echoed immediately below is exactly what gets misplaced.
			echo '<div class="wrap learnomy-admin"><div class="lrn-page-header"><div class="lrn-page-header__left"><h1 class="lrn-page-header__title">' . esc_html__( 'Grading', 'learnomy' ) . '</h1></div></div><hr class="wp-header-end">';
			echo '<div class="notice notice-error"><p>' . esc_html__( 'That attempt is not available for grading.', 'learnomy' ) . '</p></div>';
			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=learnomy-grading' ) ) . '" class="lrn-admin-btn">' . esc_html__( 'Back to grading', 'learnomy' ) . '</a></p></div>';
			return;
		}

		$attempt           = $detail['attempt'];
		$quiz              = $detail['quiz'];
		$student           = $detail['student'];
		$rows              = $detail['rows'];
		$ip_address        = $detail['ip_address'];
		$tab_tracking      = $detail['tab_tracking'];
		$tab_switches      = $detail['tab_switches'];
		$tab_flagged       = $detail['tab_flagged'];
		$exam_late         = $detail['exam_late'];
		$exam_late_minutes = $detail['exam_late_minutes'];

		include LEARNOMY_DIR . 'includes/admin/views/quiz-grading-detail.php';
	}

	public function render_questions(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$paged             = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
		$category_filter   = isset( $_GET['category_id'] ) ? absint( $_GET['category_id'] ) : 0;
		$type_filter       = isset( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : 'all';
		$difficulty_filter = isset( $_GET['difficulty'] ) ? absint( $_GET['difficulty'] ) : 0;
		$status_filter     = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'all';
		$author_filter     = isset( $_GET['author_id'] ) ? absint( $_GET['author_id'] ) : 0;
		$tag_filter        = isset( $_GET['tag_id'] ) ? absint( $_GET['tag_id'] ) : 0;
		$search            = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:enable
		$per_page = 20;

		$where = array();
		if ( $category_filter ) {
			$where['category_id'] = $category_filter;
		}
		if ( 'all' !== $type_filter ) {
			// Column is `type` on lrn_questions. Using `question_type` would
			// generate a SQL error and silently return zero rows.
			$where['type'] = \Learnomy\QuestionTypes::normalize( $type_filter );
		}
		if ( $difficulty_filter > 0 ) {
			$where['difficulty'] = $difficulty_filter;
		}
		if ( 'all' !== $status_filter && in_array( $status_filter, array( 'draft', 'published', 'archived' ), true ) ) {
			$where['status'] = $status_filter;
		}
		if ( $author_filter > 0 ) {
			$where['author_id'] = $author_filter;
		}
		// Author scope — instructors see only their own questions; the admin
		// Author dropdown is ignored for them (forced to self). Admins pass through.
		$can_filter_by_author = current_user_can( 'manage_options' );
		if ( ! $can_filter_by_author ) {
			$where['author_id'] = get_current_user_id();
			$author_filter      = 0;
		}

		// Use the model helper so each row carries `use_count` — the list
		// column reads it directly without a per-row query from the view. The
		// tag filter JOINs lrn_tag_map, so the matching count must JOIN too.
		$questions      = Question::with_usage_counts( $where, 'created_at', 'DESC', $per_page, ( $paged - 1 ) * $per_page, $tag_filter, $search );
		$total          = Question::count_filtered( $where, $tag_filter, $search );
		$total_pages    = (int) ceil( $total / $per_page );
		$all_categories = QuestionCategory::where( array(), 'name', 'ASC' );
		$all_tags       = Tag::where( array( 'type' => 'question' ), 'name', 'ASC' );

		// Author dropdown: every user who has authored at least one question.
		//
		// The source is the bank itself (Question::author_ids()), not a
		// capability probe. Asking WP_User_Query for `capability__in =>
		// [manage_options, edit_others_posts]` answered "who can edit WP posts",
		// which no `lrn_instructor` can — so the dropdown listed administrators
		// and editors while omitting every instructor who had actually written
		// questions. Names are hydrated in ONE WP_User_Query, not per row.
		$all_authors = array();
		$author_ids  = $can_filter_by_author ? Question::author_ids( 200 ) : array();
		if ( ! empty( $author_ids ) ) {
			$author_users = ( new \WP_User_Query(
				array(
					'include' => $author_ids,
					'orderby' => 'display_name',
					'order'   => 'ASC',
					'number'  => count( $author_ids ),
					'fields'  => array( 'ID', 'display_name' ),
				)
			) )->get_results();

			$named = array();
			foreach ( $author_users as $author_user ) {
				$named[ (int) $author_user->ID ] = (string) $author_user->display_name;
			}

			foreach ( $author_ids as $author_id ) {
				$all_authors[] = (object) array(
					'ID'           => $author_id,
					// A deleted account still owns rows, and hiding it would
					// make those questions unreachable through the filter.
					'display_name' => $named[ $author_id ] ?? sprintf(
						/* translators: %d: user ID of a deleted account that still owns questions. */
						__( 'Deleted user #%d', 'learnomy' ),
						$author_id
					),
				);
			}

			// Named accounts first (already alphabetised by the user query),
			// deleted ids after them, so the common case reads cleanly.
			usort(
				$all_authors,
				static function ( $a, $b ) use ( $named ) {
					$a_named = isset( $named[ (int) $a->ID ] );
					$b_named = isset( $named[ (int) $b->ID ] );
					if ( $a_named !== $b_named ) {
						return $a_named ? -1 : 1;
					}
					return strcasecmp( (string) $a->display_name, (string) $b->display_name );
				}
			);
		}

		$question_types = \Learnomy\QuestionTypes::all();

		include LEARNOMY_DIR . 'includes/admin/views/question-bank.php';
	}

	public function render_students(): void {
		$paged     = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$course_id = isset( $_GET['course_id'] ) ? absint( $_GET['course_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per_page  = 20;

		// Enrollment-driven listing: every user with at least one
		// enrollment row (optionally scoped to one course). The prior
		// implementation queried `WP_User_Query` with `role='lrn_student'`
		// first, which silently dropped enrolled users who didn't carry
		// that role — admin-manual enrollments, purchase guests, demo
		// seeds — and forced loading 5000 enrollment rows into PHP to
		// build the `include` arg. The new `get_distinct_user_ids_paginated()`
		// pages at the DB and matches via SQL search. Basecamp #9932098717.
		$course_row = $course_id ? Course::find( $course_id ) : null;

		// The filter chip prints this course's TITLE. The row set below is
		// correctly scoped, so a foreign course_id yields an empty table — but
		// the chip above it still announced "Showing students enrolled in
		// Organic Chemistry II", confirming a course exists and naming it to
		// someone who does not teach it. Resolve the title only if it is theirs.
		// Both "a real course that is not yours" and "no such course" must land
		// in the SAME state, or the difference between them is the oracle. The
		// first attempt at this reset only the out-of-scope case, so a foreign
		// id fell back to the full roster while a nonsense id filtered to zero
		// rows -- which still told a prober which ids are real, just inverted.
		$lrn_title_scope = Permission_Engine::visible_course_ids( get_current_user_id(), 'report' );
		if ( $course_id && is_array( $lrn_title_scope )
			&& ( ! $course_row || ! in_array( (int) $course_row->id, $lrn_title_scope, true ) ) ) {
			$course_row = null;
			$course_id  = 0;
		}

		// Scope: an admin sees every student; a non-admin instructor sees only
		// students enrolled in THEIR courses (author OR co-instructor). Passing
		// the scope means they can't fish another instructor's roster by guessing
		// a course_id in the URL — the IN() filter rejects courses they don't teach.
		// One rule, and no 1000-course cap: a scope silently truncated does not
		// deny the 1001st course, it makes the owner's own students vanish.
		$scope_course_ids = Permission_Engine::visible_course_ids( get_current_user_id(), 'report' );

		$paginated = \Learnomy\Models\Enrollment::get_distinct_user_ids_paginated(
			$course_id,
			$per_page,
			( $paged - 1 ) * $per_page,
			$search,
			$scope_course_ids
		);

		$students = array();
		if ( ! empty( $paginated['user_ids'] ) ) {
			$user_query = new \WP_User_Query(
				array(
					'include' => $paginated['user_ids'],
					'orderby' => 'include',
					'number'  => $per_page,
				)
			);
			$students   = $user_query->get_results();
		}

		$total       = (int) $paginated['total'];
		$total_pages = (int) ceil( $total / $per_page );

		include LEARNOMY_DIR . 'includes/admin/views/students.php';
	}

	/**
	 * Instructors list — every instructor (one lrn_instructors row each), with
	 * course count + lifetime/pending earnings. Paginated + searchable + sortable;
	 * the per-page stats are two batched queries (no N+1). Each row links to the
	 * hidden per-instructor detail page.
	 */
	public function render_instructors(): void {
		$paged    = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$orderby  = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'joined'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order    = isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ? 'ASC' : 'DESC'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per_page = 20;

		$listing = \Learnomy\Models\Instructor::admin_list(
			array(
				'search'   => $search,
				'orderby'  => $orderby,
				'order'    => $order,
				'per_page' => $per_page,
				'paged'    => $paged,
			)
		);

		// Two batched queries decorate the whole page — never per-row.
		$user_ids     = array_map( static fn( $r ) => (int) $r->user_id, $listing['rows'] );
		$earnings_map = \Learnomy\Models\Commission::earnings_for_recipients( $user_ids );
		$course_map   = \Learnomy\Models\Course::course_counts_for_instructors( $user_ids );

		$instructors = $listing['rows'];
		$total       = (int) $listing['total'];
		$total_pages = (int) $listing['total_pages'];

		include LEARNOMY_DIR . 'includes/admin/views/instructors.php';
	}

	/**
	 * Per-instructor detail — profile, the courses they teach (author OR
	 * co-instructor), an earnings summary, recent withdrawals, and the effective
	 * commission rate. A handful of bounded queries; no per-row loops.
	 */
	public function render_instructor_detail(): void {
		$instructor_id = isset( $_GET['instructor_id'] ) ? absint( $_GET['instructor_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$user          = $instructor_id ? get_userdata( $instructor_id ) : false;
		if ( ! $user ) {
			$this->render_missing_notice(
				__( 'Instructor not found', 'learnomy' ),
				admin_url( 'admin.php?page=learnomy-instructors' ),
				__( 'Back to Instructors', 'learnomy' )
			);
			return;
		}

		$profile     = \Learnomy\Models\Instructor::find_by_user( $instructor_id );
		$application = \Learnomy\Models\InstructorApplication::find_latest_for_user( $instructor_id );
		$courses     = \Learnomy\Models\Course::get_for_instructor( $instructor_id, 100, 0 );
		$earnings    = \Learnomy\Models\Commission::earnings_for_recipients( array( $instructor_id ) );
		$earned      = $earnings[ $instructor_id ]['earned'] ?? 0.0;
		$pending     = $earnings[ $instructor_id ]['pending'] ?? 0.0;
		$withdrawals = \Learnomy\Models\Withdrawal::where( array( 'instructor_id' => $instructor_id ), 'requested_at', 'DESC', 10 );

		// Effective platform rate: per-instructor rule → global → default.
		$rule            = \Learnomy\Models\CommissionRule::get_for_entity( 'instructor', $instructor_id );
		$platform_rate   = ( $rule && 'instructor' === $rule->rule_type )
			? (float) $rule->platform_rate
			: \Learnomy\Services\Commission_Service::get_default_platform_rate();
		$has_custom_rate = (bool) ( $rule && 'instructor' === $rule->rule_type );

		$currency = strtoupper( (string) ( get_option( 'learnomy_settings', array() )['currency'] ?? 'USD' ) );
		$is_pro   = $this->is_pro_active();

		include LEARNOMY_DIR . 'includes/admin/views/instructor-detail.php';
	}

	/**
	 * Per-student detail — every Learnomy-specific record for one learner:
	 * profile, a learning summary, course enrollments (with progress), earned
	 * certificates, quiz attempts, and payment history. Drilled into from the
	 * Students list (the name + the View row action).
	 *
	 * Bounded, batched queries only — course/quiz titles are resolved with a
	 * single `find_in()` each, never per-row. Non-admin instructors may only
	 * open a student enrolled in one of their own courses (mirrors the list
	 * scope so a guessed user_id can't expose another instructor's roster).
	 */
	public function render_student_detail(): void {
		$student_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$user       = $student_id ? get_userdata( $student_id ) : false;
		if ( ! $user ) {
			$this->render_missing_notice(
				__( 'Student not found', 'learnomy' ),
				admin_url( 'admin.php?page=learnomy-students' ),
				__( 'Back to Students', 'learnomy' )
			);
			return;
		}

		// History view: cancelled + expired rows stay in the table, each with its
		// own status badge and a Restore action, so removing a student's access
		// is visible as a state change rather than as a row that never moved —
		// and stays reversible. The student's own surfaces are entitlement-
		// aligned instead; see Enrollment::live_rows_where() (Basecamp 10132808967).
		$enrollments = \Learnomy\Models\Enrollment::get_distinct_for_user( $student_id, 200, 0, true );

		// Which of this student's courses may the viewer see? null = an
		// administrator, who sees the whole record.
		//
		// This used to be an ADMISSION TEST and nothing more: "do we share any
		// course?" — and once it passed, every query below ran unscoped. A
		// teacher who shared ONE course with a student saw all 62 of that
		// student's enrolments, every certificate, every quiz attempt on the
		// site, and every payment including lifetime spend. Sharing a classroom
		// is not consent to a person's whole history.
		$lrn_scope = Permission_Engine::visible_course_ids( get_current_user_id(), 'report' );

		if ( is_array( $lrn_scope ) ) {
			$shares_a_course = false;
			foreach ( $enrollments as $lrn_enr ) {
				if ( in_array( (int) $lrn_enr->course_id, $lrn_scope, true ) ) {
					$shares_a_course = true;
					break;
				}
			}
			if ( ! $shares_a_course ) {
				$this->render_missing_notice(
					sprintf( /* translators: %s: plural Course label */ __( 'This student is not in your %s', 'learnomy' ), \Learnomy\course_label( true ) ),
					admin_url( 'admin.php?page=learnomy-students' ),
					__( 'Back to Students', 'learnomy' ),
					sprintf( /* translators: %s: singular Course label */ __( 'You can view a student once they enroll in a %s you teach.', 'learnomy' ), \Learnomy\course_label() )
				);
				return;
			}
		}

		$certificates = Certificate::get_for_user( $student_id, 50 );
		$attempts     = \Learnomy\Models\QuizAttempt::get_for_user( $student_id, 50 );
		$transactions = \Learnomy\Models\Transaction::get_for_user( $student_id, 50 );

		// Narrow the record to the viewer's own courses BEFORE anything is
		// counted. Every summary tile below is computed from these arrays, so
		// filtering here scopes the numbers too — and the numbers are the
		// subtle half of this leak. "61 enrolled / 0 completed / USD 118 spent"
		// still describes a colleague's classroom even with the course list
		// hidden.
		if ( is_array( $lrn_scope ) ) {
			$enrollments  = array_values(
				array_filter( $enrollments, static fn( $e ) => in_array( (int) $e->course_id, $lrn_scope, true ) )
			);
			$certificates = array_values(
				array_filter( $certificates, static fn( $c ) => in_array( (int) ( $c->course_id ?? 0 ), $lrn_scope, true ) )
			);

			// Payments are never a teacher's business. What a student spent,
			// on whose course, through which provider, answers a question a
			// teacher does not have. Admins keep the ledger.
			$transactions = array();
		}

		// Batch-resolve course titles (enrollments + certificates) and quiz
		// titles (attempts) — one query each, no per-row lookups.
		$course_ids = array_map( static fn( $e ) => (int) $e->course_id, $enrollments );
		foreach ( $certificates as $lrn_cert ) {
			$course_ids[] = (int) ( $lrn_cert->course_id ?? 0 );
		}
		$course_ids = array_values( array_unique( array_filter( $course_ids ) ) );
		$course_map = array();
		foreach ( Course::find_in( $course_ids ) as $lrn_c ) {
			$course_map[ (int) $lrn_c->id ] = $lrn_c->title;
		}

		$quiz_ids  = array_values( array_unique( array_filter( array_map( static fn( $a ) => (int) $a->quiz_id, $attempts ) ) ) );
		$quiz_rows = \Learnomy\Models\Quiz::find_in( $quiz_ids );

		// Attempts scope by the quiz's COURSE, which is only known once the
		// quiz rows are resolved — hence the second pass rather than filtering
		// alongside enrolments above. A quiz with no course (standalone) is not
		// in any teacher's classroom, so it is not theirs to read.
		if ( is_array( $lrn_scope ) ) {
			$lrn_quiz_in_scope = array();
			foreach ( $quiz_rows as $lrn_q ) {
				if ( in_array( (int) ( $lrn_q->course_id ?? 0 ), $lrn_scope, true ) ) {
					$lrn_quiz_in_scope[ (int) $lrn_q->id ] = true;
				}
			}
			$attempts  = array_values(
				array_filter( $attempts, static fn( $a ) => isset( $lrn_quiz_in_scope[ (int) $a->quiz_id ] ) )
			);
			$quiz_rows = array_values(
				array_filter( $quiz_rows, static fn( $q ) => isset( $lrn_quiz_in_scope[ (int) $q->id ] ) )
			);
			$quiz_ids  = array_keys( $lrn_quiz_in_scope );
		}

		$quiz_map = array();
		foreach ( $quiz_rows as $lrn_q ) {
			$quiz_map[ (int) $lrn_q->id ] = $lrn_q->title;
		}

		// One row per quiz the student has attempted, showing what the cap
		// actually is for THEM (quiz limit + anything granted) against what they
		// have used — the state an owner needs before deciding to hand out
		// another attempt, and the surface the grant button lives on. Capped
		// quizzes only: an unlimited quiz has nothing to grant.
		$quiz_attempt_limits = array();
		$quiz_used_counts    = \Learnomy\Models\QuizAttempt::count_graded_by_quiz( $student_id, $quiz_ids );
		foreach ( $quiz_rows as $lrn_q ) {
			$lrn_limit = \Learnomy\Models\Quiz::effective_attempt_limit_for_user( $lrn_q, $student_id );
			if ( $lrn_limit < 1 ) {
				continue;
			}
			$quiz_attempt_limits[] = (object) array(
				'quiz_id'   => (int) $lrn_q->id,
				'title'     => (string) $lrn_q->title,
				'used'      => (int) ( $quiz_used_counts[ (int) $lrn_q->id ] ?? 0 ),
				'limit'     => $lrn_limit,
				'granted'   => \Learnomy\Models\Quiz::granted_attempts( $student_id, (int) $lrn_q->id ),
				// Per-ROW, not per-page: an instructor may hand out an attempt on
				// a quiz in a course they own and only there, while an admin
				// (Permission_Engine short-circuits on manage_options) gets every
				// row. Mirrors the REST route's own gate so the button and the
				// endpoint can never disagree.
				'can_grant' => Permission_Engine::can( get_current_user_id(), 'grade_quiz', (int) $lrn_q->id ),
			);
		}

		// Learning summary (computed from the already-fetched rows — no extra
		// queries). "Completed" = enrollment with a completed_at or completed
		// status.
		//
		// The summary counts LIVE enrolments only, while the table below lists
		// every row including the revoked ones. A teacher who removes access
		// must see the number move; counting a cancelled row as "enrolled" is
		// how the action looked like it did nothing.
		$live_enrollments = array_values(
			array_filter(
				$enrollments,
				static fn( $e ) => in_array( (string) ( $e->status ?? '' ), array( 'active', 'completed' ), true )
			)
		);
		$enrolled_count   = count( $live_enrollments );
		$completed_count  = 0;
		foreach ( $live_enrollments as $lrn_enr ) {
			if ( ! empty( $lrn_enr->completed_at ) || 'completed' === ( $lrn_enr->status ?? '' ) ) {
				++$completed_count;
			}
		}
		$total_spent = 0.0;
		foreach ( $transactions as $lrn_txn ) {
			if ( 'completed' === ( $lrn_txn->status ?? '' ) ) {
				$total_spent += (float) ( $lrn_txn->amount ?? 0 );
			}
		}

		// The view hides the money surfaces for a scoped viewer. Passed as an
		// explicit flag rather than re-deriving `is_array( $lrn_scope )` in the
		// template, so the template stays logic-free.
		$lrn_sees_whole_record = ! is_array( $lrn_scope );

		$last_active = get_user_meta( $student_id, 'learnomy_last_active', true );
		$currency    = strtoupper( (string) ( get_option( 'learnomy_settings', array() )['currency'] ?? 'USD' ) );

		// Courses the student is NOT already enrolled in — the options for the
		// "Grant course access" picker. Bounded; a site with a huge catalogue
		// would warrant a search-driven control (future enhancement).
		//
		// Keyed off LIVE enrolments: a course whose access was removed has to
		// come back into the picker, or the teacher who just revoked it cannot
		// hand it back from the same screen.
		$grant_courses = array();
		if ( current_user_can( 'manage_options' ) ) {
			$enrolled_ids = array_map( static fn( $e ) => (int) $e->course_id, $live_enrollments );
			foreach ( Course::where( array( 'status' => 'published' ), 'title', 'ASC', 300 ) as $lrn_pub_course ) {
				if ( ! in_array( (int) $lrn_pub_course->id, $enrolled_ids, true ) ) {
					$grant_courses[] = $lrn_pub_course;
				}
			}
		}

		include LEARNOMY_DIR . 'includes/admin/views/student-detail.php';
	}

	/**
	 * Render the shared "you cannot see this record" state inside the admin page
	 * chrome — a family empty-state (icon + title + back CTA) instead of a bare
	 * wp_die() string.
	 *
	 * Calling wp_die() once a page has started rendering is the wrong tool. It
	 * prints its message and exits, so the admin header and sidebar are already
	 * out but the footer never arrives: the owner gets a truncated page with one
	 * unstyled sentence floating in it and no way back except the browser
	 * button. Correct before any output (see handle_install_companion()), wrong
	 * here.
	 *
	 * Covers both reasons a detail page has nothing to show — the record is gone,
	 * or it exists and is not yours. They read differently to the person on the
	 * screen: "it may have been deleted" is a dead end, while "only students in
	 * your courses" tells a teacher the rule and implies the fix. Same shape, so
	 * the two never drift into two different-looking screens.
	 *
	 * @param string $title       Headline, e.g. "Student not found".
	 * @param string $back_url    Where the CTA returns to.
	 * @param string $back_label  CTA text, e.g. "Back to Students".
	 * @param string $description Optional. Overrides the stale-link explanation
	 *                            when the record is withheld rather than missing.
	 */
	private function render_missing_notice( string $title, string $back_url, string $back_label, string $description = '' ): void {
		echo '<div class="wrap learnomy-admin">';
		$lrn_empty_state = array(
			'icon'        => 'search-x',
			'title'       => $title,
			'description' => '' !== $description
				? $description
				: __( 'It may have been deleted, or the link is out of date.', 'learnomy' ),
			'cta'         => array(
				'url'   => $back_url,
				'label' => $back_label,
				'icon'  => 'arrow-left',
			),
		);
		include LEARNOMY_DIR . 'includes/admin/views/partials/empty-state.php';
		echo '</div>';
	}

	/**
	 * Per-course overview — the management view (distinct from the content
	 * editor): enrolled students with progress, course reviews, payment
	 * history, and a summary (enrolled / active / rating / revenue), plus
	 * admin actions (publish toggle, enroll a student, per-student reset /
	 * remove). Bounded, batched queries — student + reviewer names resolve in
	 * one WP_User_Query, never per-row.
	 */
	public function render_course_detail(): void {
		$course_id = isset( $_GET['course_id'] ) ? absint( $_GET['course_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$course    = $course_id ? Course::find( $course_id ) : null;
		if ( ! $course ) {
			$this->render_missing_notice( sprintf( /* translators: %s: singular Course label */ __( '%s not found', 'learnomy' ), \Learnomy\course_label() ), admin_url( 'admin.php?page=learnomy-courses' ), sprintf( /* translators: %s: plural Course label */ __( 'Back to %s', 'learnomy' ), \Learnomy\course_label( true ) ) );
			return;
		}

		$enrollments  = \Learnomy\Models\Enrollment::get_for_course( $course_id, 100 );
		$reviews      = \Learnomy\Models\Review::get_for_course( $course_id, 1, 20 );
		$transactions = \Learnomy\Models\Transaction::get_for_course( $course_id, 50 );

		// One batched user lookup for every name shown (enrolled students,
		// reviewers, payers) — no per-row get_userdata().
		$user_ids = array();
		foreach ( $enrollments as $lrn_e ) {
			$user_ids[] = (int) $lrn_e->user_id;
		}
		foreach ( $reviews as $lrn_r ) {
			$user_ids[] = (int) $lrn_r->user_id;
		}
		foreach ( $transactions as $lrn_t ) {
			$user_ids[] = (int) ( $lrn_t->user_id ?? 0 );
		}
		$user_ids = array_values( array_unique( array_filter( $user_ids ) ) );
		$user_map = array();
		if ( $user_ids ) {
			$lrn_users = ( new \WP_User_Query(
				array(
					'include' => $user_ids,
					'number'  => count( $user_ids ),
				)
			) )->get_results();
			foreach ( $lrn_users as $lrn_u ) {
				$user_map[ (int) $lrn_u->ID ] = $lrn_u;
			}
		}

		// Distinct people ever enrolled (any status), shown beside the currently-
		// active count. Distinct so a member holding the course via two sources is
		// one person, not two (was count_for_course = rows).
		$enrolled_total = \Learnomy\Models\Enrollment::count_distinct_students( array( $course_id ), array() );
		$active_total   = \Learnomy\Models\Enrollment::count_active_for_course( $course_id );
		$txn_total      = \Learnomy\Models\Transaction::count_for_course( $course_id );

		// Revenue summed over the completed transactions fetched above. When the
		// course has more than the fetched window the figure is a recent-window
		// total; the view labels it accordingly.
		$revenue = 0.0;
		foreach ( $transactions as $lrn_t ) {
			if ( 'completed' === ( $lrn_t->status ?? '' ) ) {
				$revenue += (float) ( $lrn_t->amount ?? 0 );
			}
		}

		$currency = strtoupper( (string) ( get_option( 'learnomy_settings', array() )['currency'] ?? 'USD' ) );

		include LEARNOMY_DIR . 'includes/admin/views/course-detail.php';
	}

	/**
	 * Per-subscription detail — the full record for one membership subscription
	 * (status, plan, billing period, provider IDs, cancel-at-period-end state)
	 * plus its renewal/payment history and the admin actions the list can't
	 * hold: cancel at period end vs immediately, resume, and extend. Reached
	 * from the Subscriptions list (subscriber name links here).
	 */
	public function render_subscription_detail(): void {
		$subscription_id = isset( $_GET['subscription_id'] ) ? absint( $_GET['subscription_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$subscription    = $subscription_id ? Subscription::find( $subscription_id ) : null;
		if ( ! $subscription ) {
			$this->render_missing_notice(
				__( 'Subscription not found', 'learnomy' ),
				admin_url( 'admin.php?page=learnomy-subscriptions' ),
				__( 'Back to Subscriptions', 'learnomy' )
			);
			return;
		}

		$user         = get_userdata( (int) $subscription->user_id );
		$plan         = ! empty( $subscription->plan_id ) ? MembershipPlan::find( (int) $subscription->plan_id ) : null;
		$transactions = Transaction::get_for_subscription( (int) $subscription_id, 50 );
		$currency     = strtoupper( (string) ( get_option( 'learnomy_settings', array() )['currency'] ?? 'USD' ) );

		include LEARNOMY_DIR . 'includes/admin/views/subscription-detail.php';
	}

	/**
	 * Per-transaction detail — surfaces what the ledger row hides: the
	 * commission split, the coupon applied, the linked enrollment /
	 * subscription / course-or-plan, the refund relationship, and the raw
	 * gateway metadata. Plus the refund action (when refundable). Reached from
	 * the Transactions list (the #id links here).
	 */
	public function render_transaction_detail(): void {
		$transaction_id = isset( $_GET['transaction_id'] ) ? absint( $_GET['transaction_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$transaction    = $transaction_id ? Transaction::find( $transaction_id ) : null;
		if ( ! $transaction ) {
			$this->render_missing_notice(
				__( 'Transaction not found', 'learnomy' ),
				admin_url( 'admin.php?page=learnomy-transactions' ),
				__( 'Back to Transactions', 'learnomy' )
			);
			return;
		}

		$user        = ! empty( $transaction->user_id ) ? get_userdata( (int) $transaction->user_id ) : false;
		$course      = ! empty( $transaction->course_id ) ? Course::find( (int) $transaction->course_id ) : null;
		$plan        = ! empty( $transaction->plan_id ) ? MembershipPlan::find( (int) $transaction->plan_id ) : null;
		$coupon      = ! empty( $transaction->coupon_id ) ? Coupon::find( (int) $transaction->coupon_id ) : null;
		$commissions = \Learnomy\Models\Commission::get_for_transaction( $transaction_id );
		$refund_id   = Transaction::find_refund_id( $transaction_id );
		$original    = ! empty( $transaction->refund_of ) ? Transaction::find( (int) $transaction->refund_of ) : null;
		$currency    = strtoupper( (string) ( $transaction->currency ?? ( get_option( 'learnomy_settings', array() )['currency'] ?? 'USD' ) ) );

		$refundable = 'refund' !== ( $transaction->transaction_type ?? '' )
			&& 'completed' === ( $transaction->status ?? '' )
			&& ! $refund_id
			&& in_array( (string) ( $transaction->payment_provider ?? '' ), array( 'stripe', 'paypal', 'test' ), true );

		include LEARNOMY_DIR . 'includes/admin/views/transaction-detail.php';
	}

	/**
	 * Per-withdrawal detail — the payout record plus the commission lines it
	 * batches (each links to its transaction). Reached from the Earnings page
	 * withdrawals list (instructor name links here); works for any status, so
	 * processed/rejected payouts stay inspectable.
	 */
	public function render_withdrawal_detail(): void {
		$withdrawal_id = isset( $_GET['withdrawal_id'] ) ? absint( $_GET['withdrawal_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$withdrawal    = $withdrawal_id ? \Learnomy\Models\Withdrawal::find( $withdrawal_id ) : null;
		if ( ! $withdrawal ) {
			$this->render_missing_notice(
				__( 'Withdrawal not found', 'learnomy' ),
				admin_url( 'admin.php?page=learnomy-earnings' ),
				__( 'Back to Earnings', 'learnomy' )
			);
			return;
		}

		$instructor  = ! empty( $withdrawal->instructor_id ) ? get_userdata( (int) $withdrawal->instructor_id ) : false;
		$commissions = \Learnomy\Models\Commission::where( array( 'withdrawal_id' => $withdrawal_id ), 'created_at', 'DESC', 200 );
		$currency    = strtoupper( (string) ( $withdrawal->currency ?? 'USD' ) );

		include LEARNOMY_DIR . 'includes/admin/views/withdrawal-detail.php';
	}

	/**
	 * Per-coupon detail — the coupon config plus its redemption list (who used
	 * it, on which transaction, when), turning the list's dead usage count into
	 * a drillable record. Reached from the Coupons list (the code links here).
	 */
	public function render_coupon_detail(): void {
		$coupon_id = isset( $_GET['coupon_id'] ) ? absint( $_GET['coupon_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$coupon    = $coupon_id ? Coupon::find( $coupon_id ) : null;
		if ( ! $coupon ) {
			$this->render_missing_notice(
				__( 'Coupon not found', 'learnomy' ),
				admin_url( 'admin.php?page=learnomy-coupons' ),
				__( 'Back to Coupons', 'learnomy' )
			);
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$usage_paged = isset( $_GET['usage_paged'] ) ? max( 1, absint( $_GET['usage_paged'] ) ) : 1;
		// phpcs:enable
		$usage_per_page = 50;
		$usage_offset   = ( $usage_paged - 1 ) * $usage_per_page;

		$usages      = \Learnomy\Models\CouponUsage::get_for_coupon( $coupon_id, $usage_per_page, $usage_offset );
		$usage_total = \Learnomy\Models\CouponUsage::count_for_coupon( $coupon_id );

		// One batched user lookup for the redemption list — no per-row queries.
		$user_ids = array_values( array_unique( array_filter( array_map( static fn( $u ) => (int) $u->user_id, $usages ) ) ) );
		$user_map = array();
		if ( $user_ids ) {
			foreach ( ( new \WP_User_Query(
				array(
					'include' => $user_ids,
					'number'  => count( $user_ids ),
				)
			) )->get_results() as $lrn_u ) {
				$user_map[ (int) $lrn_u->ID ] = $lrn_u;
			}
		}

		$usage_total_pages = (int) ceil( $usage_total / $usage_per_page );
		$currency          = strtoupper( (string) ( get_option( 'learnomy_settings', array() )['currency'] ?? 'USD' ) );

		include LEARNOMY_DIR . 'includes/admin/views/coupon-detail.php';
	}

	/**
	 * Per-application detail — the full instructor application so an admin can
	 * read the complete bio / teaching experience / course idea (the list
	 * truncates them) before approving or rejecting. Reached from the
	 * Applications list (the applicant name links here).
	 */
	public function render_application_detail(): void {
		$application_id = isset( $_GET['application_id'] ) ? absint( $_GET['application_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$application    = $application_id ? \Learnomy\Models\InstructorApplication::find( $application_id ) : null;
		if ( ! $application ) {
			$this->render_missing_notice(
				__( 'Application not found', 'learnomy' ),
				admin_url( 'admin.php?page=learnomy-instructors' ),
				__( 'Back to Instructors', 'learnomy' )
			);
			return;
		}

		$user = ! empty( $application->user_id ) ? get_userdata( (int) $application->user_id ) : false;

		include LEARNOMY_DIR . 'includes/admin/views/application-detail.php';
	}

	public function render_certificates(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$paged         = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
		$search        = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$status_filter = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'all';
		$course_filter = isset( $_GET['course_id'] ) ? absint( $_GET['course_id'] ) : 0;
		// phpcs:enable
		$per_page = 20;

		$filters = array();
		if ( in_array( $status_filter, array( 'active', 'revoked', 'expired' ), true ) ) {
			$filters['status'] = $status_filter;
		}
		if ( $course_filter > 0 ) {
			$filters['course_id'] = $course_filter;
		}
		// A search term matches the certificate UUID or a student. Resolve the
		// term to user ids first (name / login / email LIKE) so certificates of
		// matching students surface even though the table only stores user_id.
		if ( '' !== $search ) {
			$filters['uuid_search'] = $search;
			$user_query             = new \WP_User_Query(
				array(
					'search'         => '*' . $search . '*',
					'search_columns' => array( 'display_name', 'user_login', 'user_email' ),
					'number'         => 200,
					'fields'         => 'ID',
				)
			);
			$filters['user_ids']    = array_map( 'intval', (array) $user_query->get_results() );
		}

		$certificates = Certificate::list_for_admin( $filters, 'issued_at', 'DESC', $per_page, ( $paged - 1 ) * $per_page );
		$total        = Certificate::count_for_admin( $filters );
		$total_pages  = (int) ceil( $total / $per_page );

		// Course filter dropdown — only courses that actually have a certificate.
		$cert_course_ids = Certificate::distinct_course_ids();
		$cert_courses    = $cert_course_ids ? Course::find_in( $cert_course_ids ) : array();

		// Designed templates, so the owner can EDIT one instead of starting over
		// (customer feedback: "if I notice a small problem I have to make a new
		// certificate"). The designer already supports edit via ?template_id=N;
		// this surfaces the entry point. Bounded — sites have a handful.
		$certificate_templates = CertificateTemplate::where( array(), 'id', 'DESC', 50 );

		// Enrich each row with presentational strings so the view stays
		// logic-free: the curated-library type + who-can-use-it badge.
		$lrn_avail_labels = array(
			CertificateTemplate::AVAILABILITY_ALL      => __( 'All instructors', 'learnomy' ),
			CertificateTemplate::AVAILABILITY_ADMINS   => __( 'Admins only', 'learnomy' ),
			CertificateTemplate::AVAILABILITY_SPECIFIC => __( 'Specific instructors', 'learnomy' ),
		);
		foreach ( $certificate_templates as $lrn_ct ) {
			$lrn_availability          = CertificateTemplate::availability( $lrn_ct );
			$lrn_ct->lrn_kind_label    = CertificateTemplate::kind_label( CertificateTemplate::kind( $lrn_ct ) );
			$lrn_ct->lrn_is_curated    = CertificateTemplate::is_curated( $lrn_ct );
			$lrn_ct->lrn_avail_label   = $lrn_avail_labels[ $lrn_availability ] ?? $lrn_avail_labels[ CertificateTemplate::AVAILABILITY_ALL ];
			$lrn_ct->lrn_avail_variant = CertificateTemplate::AVAILABILITY_ADMINS === $lrn_availability ? 'outline' : 'green';
		}

		include LEARNOMY_DIR . 'includes/admin/views/certificates.php';
	}

	public function render_certificate_designer(): void {
		$template_id = isset( $_GET['template_id'] ) ? absint( $_GET['template_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$template    = $template_id ? CertificateTemplate::find( $template_id ) : null;
		$is_edit     = (bool) $template;

		// Instructor-availability governance for the sidebar control. A new
		// template defaults to "Admins only" (private) per the curated-library
		// governance; an existing template reflects its saved meta.
		$availability_meta = $template
			? CertificateTemplate::meta( $template )
			: array(
				'kind'         => 'course_completion',
				'availability' => CertificateTemplate::AVAILABILITY_ADMINS,
				'instructors'  => array(),
			);

		// Choices for the "Specific instructors" picker. Capped + filterable so a
		// large-roster site never renders an unbounded <select> (this is the
		// advanced 10% case; the default flows never touch it).
		$instructor_choice_limit = (int) apply_filters( 'learnomy_certificate_instructor_choice_limit', 500 );
		$instructor_list         = \Learnomy\Models\Instructor::admin_list(
			array(
				'per_page' => $instructor_choice_limit,
				'orderby'  => 'name',
				'order'    => 'ASC',
			)
		);
		$instructor_choices      = array();
		foreach ( (array) ( $instructor_list['rows'] ?? array() ) as $lrn_row ) {
			$uid = (int) ( $lrn_row->user_id ?? 0 );
			if ( $uid ) {
				$instructor_choices[ $uid ] = (string) ( $lrn_row->display_name ?? ( '#' . $uid ) );
			}
		}
		// Keep already-granted instructors visible even if beyond the cap.
		foreach ( (array) $availability_meta['instructors'] as $granted_id ) {
			$granted_id = (int) $granted_id;
			if ( $granted_id && ! isset( $instructor_choices[ $granted_id ] ) ) {
				$granted_user                      = get_userdata( $granted_id );
				$instructor_choices[ $granted_id ] = $granted_user ? $granted_user->display_name : ( '#' . $granted_id );
			}
		}

		include LEARNOMY_DIR . 'includes/admin/views/certificate-designer.php';
	}

	public function render_categories(): void {
		// Bug fix Basecamp #9936653960: the denormalized course_count
		// column on wp_lrn_categories was never maintained — the admin
		// list always showed "0 courses" for every category. Use the
		// joined-on-read accessor instead so the count is always correct.
		$all_categories = Category::list_with_course_count( 'course' );

		// Per-type tag vocabularies. The Tags tab shows one content type at a
		// time (Course / Lesson / Quiz / Question) via a type switcher; the
		// list + create both scope to the active type.
		$tag_type_labels = array(
			'course'   => \Learnomy\course_label(),
			'lesson'   => \Learnomy\lesson_label(),
			'quiz'     => \Learnomy\quiz_label(),
			'question' => __( 'Question', 'learnomy' ),
		);
		$tag_types       = array();
		$tag_type_counts = array();
		foreach ( \Learnomy\registered_tag_types() as $lrn_t ) {
			$tag_types[ $lrn_t ]       = $tag_type_labels[ $lrn_t ] ?? ucfirst( $lrn_t );
			$tag_type_counts[ $lrn_t ] = Tag::count( array( 'type' => $lrn_t ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$active_tag_type = isset( $_GET['tag_type'] ) ? sanitize_key( wp_unslash( $_GET['tag_type'] ) ) : 'course';
		if ( ! isset( $tag_types[ $active_tag_type ] ) ) {
			$active_tag_type = 'course';
		}
		// Usage-aware list so the manager's count column reflects the active
		// vocabulary's own object_type (a quiz tag counts quizzes, not courses).
		// Paginated: the total comes from COUNT(*) (count_by_type), never from
		// count( $all_tags ), so the list query keeps its LIMIT at any tag count.
		$tags_per_page = 100;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tags_paged  = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		$tags_total  = Tag::count_by_type( $active_tag_type );
		$tags_pages  = (int) max( 1, (int) ceil( $tags_total / $tags_per_page ) );
		$tags_paged  = min( $tags_paged, $tags_pages );
		$tags_offset = ( $tags_paged - 1 ) * $tags_per_page;
		$all_tags    = Tag::list_with_usage( $active_tag_type, $tags_per_page, $tags_offset );

		// Plural content label for the count column header, per active type.
		$tag_count_labels = array(
			'course'   => \Learnomy\course_label( true ),
			'lesson'   => \Learnomy\lesson_label( true ),
			'quiz'     => \Learnomy\quiz_label( true ),
			'question' => __( 'Questions', 'learnomy' ),
		);
		$tag_count_label  = $tag_count_labels[ $active_tag_type ] ?? __( 'In use', 'learnomy' );

		include LEARNOMY_DIR . 'includes/admin/views/categories.php';
	}

	public function render_applications(): void {
		include LEARNOMY_DIR . 'includes/admin/views/instructor-applications.php';
	}

	public function render_earnings(): void {
		include LEARNOMY_DIR . 'includes/admin/views/earnings.php';
	}

	public function render_transactions(): void {
		include LEARNOMY_DIR . 'includes/admin/views/transactions.php';
	}

	public function render_announcements(): void {
		include LEARNOMY_DIR . 'includes/admin/views/announcements.php';
	}

	public function render_coupons(): void {
		include LEARNOMY_DIR . 'includes/admin/views/coupons.php';
	}

	/**
	 * Reviews moderation page — paginated list with status filter, search,
	 * bulk actions, and per-row approve / reject / spam / pending / delete.
	 *
	 * The view receives pre-counted status totals so the subsubsub tabs can
	 * render counts in one render pass instead of one COUNT(*) per tab.
	 */
	public function render_reviews(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$paged         = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
		$status_filter = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'all';
		$search        = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$sort          = isset( $_GET['sort'] ) && 'date_asc' === $_GET['sort'] ? 'date_asc' : 'date_desc';
		// phpcs:enable

		$valid_statuses = array( 'pending', 'approved', 'rejected', 'spam' );
		if ( ! in_array( $status_filter, $valid_statuses, true ) ) {
			$status_filter = 'all';
		}

		$per_page = 20;
		$offset   = ( max( 1, $paged ) - 1 ) * $per_page;

		$query_args = array(
			'status' => 'all' === $status_filter ? '' : $status_filter,
			'search' => $search,
			'sort'   => $sort,
			'limit'  => $per_page,
			'offset' => $offset,
		);

		$reviews     = Review::get_paginated_admin( $query_args );
		$total       = Review::count_admin(
			array(
				'status' => $query_args['status'],
				'search' => $search,
			)
		);
		$total_pages = (int) ceil( max( 1, $total ) / $per_page );

		// Status totals for the subsubsub tabs — one GROUP BY query covers
		// every tab so the counts don't cost one COUNT(*) per status.
		$status_counts = Review::count_by_status();
		$status_counts = array(
			'all'      => array_sum( $status_counts ),
			'pending'  => (int) ( $status_counts['pending'] ?? 0 ),
			'approved' => (int) ( $status_counts['approved'] ?? 0 ),
			'rejected' => (int) ( $status_counts['rejected'] ?? 0 ),
			'spam'     => (int) ( $status_counts['spam'] ?? 0 ),
		);

		// Pre-resolve author + course display data into one pass per page so
		// the view template stays logic-free (no DB calls inside foreach).
		$author_ids = array_values( array_unique( array_map( static fn( $r ) => (int) $r->user_id, $reviews ) ) );
		$course_ids = array_values( array_unique( array_map( static fn( $r ) => (int) $r->course_id, $reviews ) ) );

		$authors = array();
		if ( ! empty( $author_ids ) ) {
			$user_query = new \WP_User_Query(
				array(
					'include' => $author_ids,
					'fields'  => array( 'ID', 'display_name', 'user_email' ),
				)
			);
			foreach ( $user_query->get_results() as $u ) {
				$authors[ (int) $u->ID ] = $u;
			}
		}

		$courses = array();
		foreach ( $course_ids as $cid ) {
			$course = \Learnomy\Models\Course::find( $cid );
			if ( $course ) {
				$courses[ $cid ] = $course;
			}
		}

		include LEARNOMY_DIR . 'includes/admin/views/reviews.php';
	}

	/**
	 * Maps report-queue lifecycle states to canonical badge variants. Hooked on
	 * `learnomy_admin_status_variants` so the shared UX::status_badge() paints
	 * the report pills the same way as every other admin status.
	 *
	 * @param array<string,string> $map Existing status => variant map.
	 * @return array<string,string>
	 */
	public function report_status_variants( array $map ): array {
		$map['open']      = 'warn';
		$map['dismissed'] = 'muted';
		$map['actioned']  = 'success';
		return $map;
	}

	/**
	 * Reports moderation queue — member-filed reports on reviews, lesson
	 * comments, and courses. Mirrors the reviews-queue data shape: rows +
	 * per-status tab counts come from AuditLog (no raw $wpdb here), and the
	 * reported objects + reporters are pre-resolved into maps so the view stays
	 * logic-free (zero DB queries inside the foreach).
	 */
	public function render_reports(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$paged         = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
		$status_filter = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'all';
		// phpcs:enable

		$valid_statuses = array(
			\Learnomy\Models\AuditLog::REPORT_OPEN,
			\Learnomy\Models\AuditLog::REPORT_DISMISSED,
			\Learnomy\Models\AuditLog::REPORT_ACTIONED,
		);
		if ( ! in_array( $status_filter, $valid_statuses, true ) ) {
			$status_filter = 'all';
		}

		$per_page   = 20;
		$offset     = ( max( 1, $paged ) - 1 ) * $per_page;
		$status_arg = 'all' === $status_filter ? '' : $status_filter;

		$reports     = \Learnomy\Models\AuditLog::get_reports( $status_arg, $per_page, $offset );
		$total       = \Learnomy\Models\AuditLog::count_reports( $status_arg );
		$total_pages = (int) ceil( max( 1, $total ) / $per_page );

		// Per-tab counts — one GROUP BY query for every state.
		$by_status     = \Learnomy\Models\AuditLog::count_reports_by_status();
		$status_counts = array(
			'all'       => array_sum( $by_status ),
			'open'      => (int) ( $by_status['open'] ?? 0 ),
			'dismissed' => (int) ( $by_status['dismissed'] ?? 0 ),
			'actioned'  => (int) ( $by_status['actioned'] ?? 0 ),
		);

		// Pre-resolve reporters + reported objects into maps in one pass per page
		// so the view never queries inside its loop. Objects are batch-loaded per
		// type to avoid an N+1 across the 20-row page.
		$reporter_ids = array();
		$review_ids   = array();
		$course_ids   = array();
		$comment_ids  = array();
		foreach ( $reports as $report ) {
			$reporter_ids[] = (int) $report->user_id;
			switch ( (string) $report->object_type ) {
				case 'review':
					$review_ids[] = (int) $report->object_id;
					break;
				case 'course':
					$course_ids[] = (int) $report->object_id;
					break;
				case 'comment':
					$comment_ids[] = (int) $report->object_id;
					break;
			}
		}

		$reporters    = array();
		$reporter_ids = array_values( array_unique( array_filter( $reporter_ids ) ) );
		if ( ! empty( $reporter_ids ) ) {
			$user_query = new \WP_User_Query(
				array(
					'include' => $reporter_ids,
					'fields'  => array( 'ID', 'display_name' ),
				)
			);
			foreach ( $user_query->get_results() as $u ) {
				$reporters[ (int) $u->ID ] = $u;
			}
		}

		$reviews     = \Learnomy\Models\Review::find_in( $review_ids );
		$courses     = \Learnomy\Models\Course::find_in( $course_ids );
		$comments    = array();
		$comment_ids = array_values( array_unique( array_filter( $comment_ids ) ) );
		if ( ! empty( $comment_ids ) ) {
			foreach ( get_comments( array( 'comment__in' => $comment_ids ) ) as $comment ) {
				$comments[ (int) $comment->comment_ID ] = $comment;
			}
		}

		include LEARNOMY_DIR . 'includes/admin/views/reports-queue.php';
	}

	public function render_memberships(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$paged         = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
		$status_filter = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'all';
		$search        = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:enable
		$per_page = 20;

		$where = array();
		if ( 'all' !== $status_filter && in_array( $status_filter, array( 'active', 'inactive' ), true ) ) {
			$where['status'] = $status_filter;
		}

		$plans       = MembershipPlan::search( $where, $search, 'sort_order', 'ASC', $per_page, ( $paged - 1 ) * $per_page );
		$total       = MembershipPlan::count_search( $where, $search );
		$total_pages = (int) ceil( $total / $per_page );

		// Batch the per-row counts the list needs — one GROUP BY query each for
		// course links and active subscribers, keyed by plan_id — instead of two
		// queries per plan inside the view loop. Plans absent from a map have a
		// count of 0 (no rows in the junction / no active subscribers).
		$plan_ids          = array_map( static fn( $plan ) => (int) $plan->id, $plans );
		$course_counts     = MembershipPlanCourse::count_courses_by_plan( $plan_ids );
		$subscriber_counts = Subscription::count_active_by_plan( $plan_ids );

		include LEARNOMY_DIR . 'includes/admin/views/memberships.php';
	}

	public function render_membership_edit(): void {
		$plan_id = isset( $_GET['plan_id'] ) ? absint( $_GET['plan_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$plan    = $plan_id ? MembershipPlan::find( $plan_id ) : null;
		$is_edit = (bool) $plan;

		// Pre-load assigned courses so the editor can pre-check rows without
		// another round-trip in the view.
		$assigned_courses = array();
		if ( $is_edit ) {
			$links = MembershipPlanCourse::get_courses_for_plan( (int) $plan->id );
			foreach ( $links as $link ) {
				$course = \Learnomy\Models\Course::find( (int) $link->course_id );
				if ( $course ) {
					$assigned_courses[] = $course;
				}
			}

			global $title;
			/* translators: %s: plan title */
			$title = sprintf( __( 'Edit Membership Plan: %s', 'learnomy' ), $plan->title ?? '' );
		}

		include LEARNOMY_DIR . 'includes/admin/views/membership-edit.php';
	}

	public function render_subscriptions(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$paged         = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
		$status_filter = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'all';
		$plan_filter   = isset( $_GET['plan_id'] ) ? absint( $_GET['plan_id'] ) : 0;
		// phpcs:enable
		$per_page = 20;

		$where = array();
		// cancel_pending is a presentational state — flag-based, not a row value.
		if ( 'cancel_pending' === $status_filter ) {
			$where['status']               = 'active';
			$where['cancel_at_period_end'] = 1;
		} elseif ( 'all' !== $status_filter ) {
			$where['status'] = $status_filter;
		}
		if ( $plan_filter ) {
			$where['plan_id'] = $plan_filter;
		}

		$subscriptions = Subscription::where( $where, 'created_at', 'DESC', $per_page, ( $paged - 1 ) * $per_page );
		$total         = Subscription::count( $where );
		$total_pages   = (int) ceil( $total / $per_page );
		$all_plans     = MembershipPlan::where( array(), 'title', 'ASC', 100 );

		// Batch the per-row lookups the view needs — users and plans for the
		// current page — instead of get_userdata()/MembershipPlan::find() per
		// row in the loop. One WP_User_Query (with `include`) primes the user
		// cache so the view's get_userdata() calls are cache hits, and one
		// find_in() returns a plan_id => plan map the view reads directly.
		$user_ids = array();
		$plan_ids = array();
		foreach ( $subscriptions as $sub ) {
			$user_ids[] = (int) $sub->user_id;
			$plan_ids[] = (int) $sub->plan_id;
		}
		$user_ids = array_values( array_unique( array_filter( $user_ids ) ) );
		if ( $user_ids ) {
			// Primes WP's user cache so per-row get_userdata() resolves without
			// a query each; cache_users() pulls users + usermeta in one pass.
			cache_users( $user_ids );
		}
		$plans_by_id = MembershipPlan::find_in( $plan_ids );

		include LEARNOMY_DIR . 'includes/admin/views/subscriptions.php';
	}

	public function render_email_log(): void {
		include LEARNOMY_DIR . 'includes/admin/views/email-log.php';
	}

	public function render_webhook_events(): void {
		include LEARNOMY_DIR . 'includes/admin/views/webhook-events.php';
	}

	public function render_webhooks(): void {
		include LEARNOMY_DIR . 'includes/admin/views/webhooks.php';
	}

	public function render_settings(): void {
		include LEARNOMY_DIR . 'includes/admin/views/settings.php';
	}

	public function render_modules(): void {
		include LEARNOMY_DIR . 'includes/admin/views/modules.php';
	}

	public function render_import(): void {
		include LEARNOMY_DIR . 'includes/admin/views/import.php';
	}

	public function render_pro_features(): void {
		include LEARNOMY_DIR . 'includes/admin/views/pro-features.php';
	}

	/**
	 * Integrations tab — Academy Stack companions. The view reads
	 * Companion_Registry::status() per entry and renders install/activate/upgrade
	 * actions; this method only includes it.
	 */
	public function render_integrations(): void {
		include LEARNOMY_DIR . 'includes/admin/views/integrations.php';
	}

	/**
	 * admin-post handler: install (or activate) a companion from the Integrations
	 * tab. Caps + nonce gate every call; redirects back to the tab with a status
	 * the view surfaces as an admin notice.
	 */
	public function handle_install_companion(): void {
		$slug = isset( $_POST['companion'] ) ? sanitize_key( wp_unslash( $_POST['companion'] ) ) : '';
		$tier = isset( $_POST['tier'] ) && 'pro' === $_POST['tier'] ? 'pro' : 'free';

		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to install plugins.', 'learnomy' ), 403 );
		}
		check_admin_referer( 'learnomy_install_companion_' . $slug );

		$license = isset( $_POST['license'] ) ? sanitize_text_field( wp_unslash( $_POST['license'] ) ) : '';
		$result  = \Learnomy\Integrations\Companion_Installer::install( $slug, $tier, $license );

		$args = array( 'page' => 'learnomy-integrations' );
		if ( is_wp_error( $result ) ) {
			$args['lrn_install'] = 'error';
			$args['lrn_msg']     = rawurlencode( $result->get_error_message() );
		} else {
			$args['lrn_install'] = 'ok';
			$args['lrn_done']    = $slug;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Lesson editor injection: Content Drip pointer card.
	 *
	 * Hooked to `learnomy_lesson_release_settings`. The shared Pro empty-state
	 * partial bails out when Pro is active, so on a site that has the Pro
	 * Content Drip extension this method is a no-op and the extension's own
	 * panel renders unchanged.
	 */
	public function render_lesson_drip_pro_pointer(): void {
		$feature_slug  = 'content-drip';
		$intro_message = sprintf( /* translators: %s: plural Lesson label */ __( 'Drip-feed %s over time with Learnomy Pro.', 'learnomy' ), \Learnomy\lesson_label( true ) );
		include \Learnomy\template_path( 'partials/admin-empty-state-pro.php' );
	}

	/**
	 * Intercepts the setup wizard page before admin chrome loads.
	 * Runs on admin_init so headers haven't been sent yet.
	 */
	public function maybe_render_setup_wizard(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'] ) || 'learnomy-setup' !== $_GET['page'] ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$is_rerun = isset( $_GET['rerun'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['rerun'] ) );

		// Setup already complete + not explicitly re-running → bounce to dashboard.
		if ( get_option( 'learnomy_setup_complete' ) && ! $is_rerun ) {
			wp_safe_redirect( admin_url( 'admin.php?page=learnomy' ) );
			exit;
		}

		// The wizard renders its own standalone document and calls
		// wp_print_styles() directly, which is a FRONT-END printer. Core keeps a
		// back-compat binding of the deprecated print_emoji_styles() on
		// 'wp_print_styles' (wp-includes/default-filters.php) and only unhooks it
		// inside wp_enqueue_emoji_styles(), which runs on the front-end-only
		// 'wp_enqueue_scripts'. A standalone admin page never reaches that, so the
		// deprecated callback is still attached and fires a WP 6.4+ deprecation
		// notice straight into our <head> on any site with WP_DEBUG_DISPLAY on
		// (Basecamp 10153070197).
		//
		// Calling wp_enqueue_emoji_styles() is NOT a substitute: inside wp-admin it
		// targets 'admin_print_styles', so it would return early and leave the
		// 'wp_print_styles' binding exactly where it is. Unhook it explicitly.
		//
		// This belongs here, not in the view: the view is a template and stays
		// logic-free, and this is a consequence of the decision made HERE to render
		// outside the normal admin style pipeline.
		remove_action( 'wp_print_styles', 'print_emoji_styles' );

		include LEARNOMY_DIR . 'includes/admin/views/setup-wizard.php';
		exit;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Fallback render callback for the setup wizard menu item.
	 * Normally unreachable — maybe_render_setup_wizard() exits first.
	 */
	public function render_setup(): void {
		// Handled by maybe_render_setup_wizard() on admin_init.
	}

	/**
	 * Handle the dashboard "Connect a payment gateway" CTA.
	 *
	 * The CTA links to `?page=learnomy&learnomy_action=enable-payments-and-configure`
	 * because deep-linking to `?page=learnomy-settings#payments` falls back to
	 * the General tab silently when the Payments module is disabled (the
	 * Payments nav item is dropped server-side, so the hash router has nothing
	 * to activate). See SS-5 in `plan/2026-05-16-token-ready-sweep/customer-journey-walk.md`.
	 *
	 * Flow:
	 *  1. Verify the user has manage_options + a valid nonce.
	 *  2. Enable the `payments` module if it is not already enabled.
	 *  3. Redirect to Settings → Payments with a `payments_enabled` flag so the
	 *     settings page can render a confirmation notice.
	 *
	 * Idempotent: if the module is already enabled the redirect happens with
	 * the flag unset and no notice is shown.
	 */
	public function maybe_enable_payments_and_configure(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['learnomy_action'] ) ) {
			return;
		}
		if ( 'enable-payments-and-configure' !== sanitize_key( wp_unslash( $_GET['learnomy_action'] ) ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage payment gateways.', 'learnomy' ) );
		}

		$nonce = isset( $_GET['_lrn_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_lrn_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'learnomy_enable_payments' ) ) {
			wp_die( esc_html__( 'Security check failed. Please return to the dashboard and try again.', 'learnomy' ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$kernel              = \Learnomy\Modules\Module_Kernel::instance();
		$was_already_enabled = $kernel->is_enabled( 'payments' );

		if ( ! $was_already_enabled ) {
			$kernel->enable( 'payments' );
		}

		$redirect = admin_url( 'admin.php?page=learnomy-settings#payments' );
		if ( ! $was_already_enabled ) {
			$redirect = add_query_arg( 'payments_enabled', '1', $redirect );
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	public function render_new_course(): void {
		$course     = null;
		$categories = Category::where( array(), 'name', 'ASC' );
		$is_edit    = false;
		include LEARNOMY_DIR . 'includes/admin/views/course-form.php';
	}

	public function render_edit_course(): void {
		$course_id = isset( $_GET['course_id'] ) ? absint( $_GET['course_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$course    = $course_id ? Course::find( $course_id ) : null;

		if ( ! $course ) {
			$this->render_missing_notice( sprintf( /* translators: %s: singular Course label */ __( '%s not found', 'learnomy' ), \Learnomy\course_label() ), admin_url( 'admin.php?page=learnomy-courses' ), sprintf( /* translators: %s: plural Course label */ __( 'Back to %s', 'learnomy' ), \Learnomy\course_label( true ) ) );
			return;
		}

		// Per-resource ownership gate. The submenu's `learnomy_manage_own_courses`
		// cap lets instructors REACH the page; this check stops one instructor
		// from editing another instructor's course. Admins (manage_options)
		// keep blanket access for moderation.
		if ( ! Permission_Engine::can( get_current_user_id(), 'manage_course', (int) $course->id ) ) {
			wp_die(
				esc_html( sprintf( /* translators: %s: plural Course label */ __( 'You can only edit %s you own.', 'learnomy' ), \Learnomy\course_label( true ) ) ),
				esc_html__( 'Forbidden', 'learnomy' ),
				array( 'response' => 403 )
			);
		}

		// Set admin page title to avoid strip_tags() null warning.
		global $title;
		/* translators: %s: course title */
		$title = sprintf( /* translators: 1: singular Course label, 2: course title */ __( 'Edit %1$s: %2$s', 'learnomy' ), \Learnomy\course_label(), $course->title ?? '' );

		$categories = Category::where( array(), 'name', 'ASC' );
		$is_edit    = true;
		include LEARNOMY_DIR . 'includes/admin/views/course-form.php';
	}

	public function render_lessons(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$paged         = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
		$search        = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$status_filter = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'all';
		$course_filter = isset( $_GET['course_id'] ) ? absint( $_GET['course_id'] ) : 0;
		$tag_filter    = isset( $_GET['tag_id'] ) ? absint( $_GET['tag_id'] ) : 0;
		// phpcs:enable
		$per_page = 20;

		// Instructor scope — same rule as the Quizzes list: lessons they
		// authored, plus lessons on a course they manage (Basecamp 10184327422).
		$scope       = Permission_Engine::instructor_scope_sql( get_current_user_id(), 'manage_course', 'l.' );
		$result      = Lesson::search_paginated( $status_filter, $search, $per_page, $paged, $course_filter, $tag_filter, 0, $scope );
		$lessons     = $result['items'];
		$total       = $result['total'];
		$total_pages = (int) ceil( $total / $per_page );

		// Batch-fetch everything the row view needs so the list runs a fixed
		// number of queries regardless of row count (big-site checklist #3 —
		// no N+1). One query each for sub-lessons, parent courses, and sections;
		// the view reads from the maps instead of calling ::find() per row.
		$lesson_ids  = array_map( static fn( $l ) => (int) $l->id, $lessons );
		$sub_lessons = Lesson::get_topics_for_parents( $lesson_ids, null ); // Admin list: every status.
		foreach ( $lesson_ids as $lid ) {
			$sub_lessons[ $lid ] = $sub_lessons[ $lid ] ?? array();
		}

		$courses_map  = Course::find_in( array_map( static fn( $l ) => (int) $l->course_id, $lessons ) );
		$sections_map = Section::find_in( array_map( static fn( $l ) => (int) ( $l->section_id ?? 0 ), $lessons ) );

		// Course dropdown source. Scope to the instructor's own courses so the
		// filter never lists courses they can't see lessons for.
		//
		// This is a FILTER, not an attach target, so it deliberately does NOT use
		// Course::selectable(): lessons stranded under a trashed course are found
		// by filtering to it, and that is the recovery path. Anything that
		// ATTACHES to a course must use selectable() instead (Basecamp 10153289435).
		$all_courses = self::manageable_courses_for_filter();

		// Tag dropdown source — the Lesson tag vocabulary, so admins can find
		// lessons by tag on large sites.
		$all_tags = Tag::where( array( 'type' => 'lesson' ), 'name', 'ASC' );

		include LEARNOMY_DIR . 'includes/admin/views/lessons.php';
	}

	public function render_course_builder(): void {
		$course_id = isset( $_GET['course_id'] ) ? absint( $_GET['course_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$course    = $course_id ? Course::find( $course_id ) : null;

		if ( ! $course ) {
			$this->render_missing_notice( sprintf( /* translators: %s: singular Course label */ __( '%s not found', 'learnomy' ), \Learnomy\course_label() ), admin_url( 'admin.php?page=learnomy-courses' ), sprintf( /* translators: %s: plural Course label */ __( 'Back to %s', 'learnomy' ), \Learnomy\course_label( true ) ) );
			return;
		}

		// Per-resource ownership gate — instructors only see the builder for
		// their own courses; admins keep blanket access. The submenu's
		// `learnomy_manage_own_courses` cap controls page reachability.
		if ( ! Permission_Engine::can( get_current_user_id(), 'manage_course', (int) $course->id ) ) {
			wp_die(
				esc_html( sprintf( /* translators: %s: plural Course label */ __( 'You can only manage the curriculum for %s you own.', 'learnomy' ), \Learnomy\course_label( true ) ) ),
				esc_html__( 'Forbidden', 'learnomy' ),
				array( 'response' => 403 )
			);
		}

		// Sections ordered by sort_order ascending.
		$sections = Section::get_for_course( $course_id );

		// Lessons grouped by section — only top-level (no parent). ONE query for
		// the whole course (course_id-indexed), grouped by section in PHP —
		// replaces the per-section N+1 that also degraded to a scan because the
		// section_id-only predicate couldn't use the course_id-leading index.
		$section_lessons = Lesson::top_level_for_course_by_section( $course_id );
		foreach ( $sections as $section ) {
			$section_lessons[ $section->id ] = $section_lessons[ $section->id ] ?? array();
		}

		// Section-less ("ungrouped") lessons + quizzes. Sections are OPTIONAL, so a
		// course may hold top-level lessons/quizzes with no section (section_id
		// NULL/0). The builder used to iterate only existing sections, silently
		// dropping these; surface them so they render in an "Ungrouped" group.
		$loose_lessons = Lesson::get_section_less_for_course( $course_id, null ); // Admin builder: every status.
		$loose_quizzes = Quiz::get_section_less_for_course( $course_id );

		// Sub-lessons (topics) grouped by parent lesson. Batch-fetch every topic
		// for all parent lessons in ONE query (big-site checklist #3 — no N+1)
		// instead of calling get_topics() per lesson. get_topics_for_parents()
		// orders children by sort_order ASC, matching the per-lesson query, so the
		// rendered topic tree is identical. Includes the ungrouped lessons.
		$parent_lesson_ids = array();
		foreach ( $section_lessons as $lessons ) {
			foreach ( $lessons as $lesson ) {
				$parent_lesson_ids[] = (int) $lesson->id;
			}
		}
		foreach ( $loose_lessons as $lesson ) {
			$parent_lesson_ids[] = (int) $lesson->id;
		}
		$lesson_topics = Lesson::get_topics_for_parents( $parent_lesson_ids, null ); // Admin builder: every status.
		foreach ( $parent_lesson_ids as $pid ) {
			$lesson_topics[ $pid ] = $lesson_topics[ $pid ] ?? array();
		}

		// Quizzes grouped by section — ONE course-scoped query (was a per-section
		// Quiz::where() N+1), grouped by section in PHP.
		$section_quizzes = Quiz::for_course_by_section( $course_id );
		foreach ( $sections as $section ) {
			$section_quizzes[ $section->id ] = $section_quizzes[ $section->id ] ?? array();
		}

		include LEARNOMY_DIR . 'includes/admin/views/course-builder.php';
	}

	public function render_lesson_editor(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$lesson_id  = isset( $_GET['lesson_id'] ) ? absint( $_GET['lesson_id'] ) : 0;
		$lesson     = $lesson_id ? Lesson::find( $lesson_id ) : null;
		$is_edit    = (bool) $lesson;
		$categories = Category::where( array(), 'name', 'ASC' );

		// Per-resource ownership gate. The lesson belongs to a course; an
		// instructor may only edit lessons inside courses they author.
		if ( $is_edit && ! current_user_can( 'manage_options' ) ) {
			$parent_course = ! empty( $lesson->course_id ) ? Course::find( (int) $lesson->course_id ) : null;
			if ( ! $parent_course || ! Permission_Engine::can( get_current_user_id(), 'manage_course', (int) $parent_course->id ) ) {
				wp_die(
					esc_html( sprintf( /* translators: 1: plural Lesson label, 2: plural Course label */ __( 'You can only edit %1$s in %2$s you own.', 'learnomy' ), \Learnomy\lesson_label( true ), \Learnomy\course_label( true ) ) ),
					esc_html__( 'Forbidden', 'learnomy' ),
					array( 'response' => 403 )
				);
			}
		}

		// Courses list for the Course dropdown. Admins see all; instructors
		// see only their own (so they can't move a lesson into a course they
		// don't own). Trashed courses are excluded by Course::selectable() —
		// offering one meant an author could park a lesson inside a course
		// students cannot see, with a success message and nothing to show for it
		// (Basecamp 10153289435).
		$courses = Course::selectable( current_user_can( 'manage_options' ) ? 0 : get_current_user_id() );

		// Determine the active course context (for sections + parent choices).
		$context_course_id  = $is_edit ? (int) ( $lesson->course_id ?? 0 ) : ( isset( $_GET['course_id'] ) ? absint( $_GET['course_id'] ) : 0 );
		$context_section_id = $is_edit ? (int) ( $lesson->section_id ?? 0 ) : ( isset( $_GET['section_id'] ) ? absint( $_GET['section_id'] ) : 0 );
		// phpcs:enable

		$sections       = $context_course_id ? Section::get_for_course( $context_course_id ) : array();
		$parent_choices = $context_section_id ? Lesson::get_for_section( $context_section_id, 0, 0, null ) : array(); // Editor picker: every status.

		// Read-only metadata for the sidebar Details card (edit only). Resolved
		// here so the view stays logic-light.
		$lesson_author_name = '';
		if ( $is_edit && ! empty( $lesson->author_id ) ) {
			$author             = get_userdata( (int) $lesson->author_id );
			$lesson_author_name = $author ? $author->display_name : '';
		}

		// Tag taxonomies for the editor sidebar (edit only). Registry-driven so
		// adding a lesson taxonomy is a filter, not an editor change.
		$lesson_taxonomies = $is_edit ? \Learnomy\lesson_taxonomies() : array();
		$lesson_tag_ids    = $is_edit ? Tag::get_ids_for_object( (int) $lesson->id, 'lesson' ) : array();
		$taxonomy_terms    = array();
		foreach ( $lesson_taxonomies as $tax ) {
			$taxonomy_terms[ $tax['type'] ] = Tag::where( array( 'type' => $tax['type'] ), 'name', 'ASC' );
		}

		include LEARNOMY_DIR . 'includes/admin/views/lesson-editor.php';
	}

	public function render_quiz_editor(): void {
		$quiz_id = isset( $_GET['quiz_id'] ) ? absint( $_GET['quiz_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$quiz    = $quiz_id ? Quiz::find( $quiz_id ) : null;
		$is_edit = (bool) $quiz;

		// Per-resource ownership gate. An instructor may only edit a quiz they
		// authored OR a quiz attached to a course they own; admins edit any.
		// Mirrors the lesson editor + the quiz REST controller's can_manage.
		if ( $is_edit && ! current_user_can( 'manage_options' ) ) {
			$uid         = get_current_user_id();
			$owns_quiz   = (int) ( $quiz->author_id ?? 0 ) === $uid;
			$parent      = ! empty( $quiz->course_id ) ? Course::find( (int) $quiz->course_id ) : null;
			$owns_course = $parent && (int) ( $parent->author_id ?? 0 ) === $uid;
			if ( ! $owns_quiz && ! $owns_course ) {
				wp_die(
					esc_html( sprintf( /* translators: 1: plural Quiz label, 2: plural Course label */ __( 'You can only edit %1$s you own or that belong to your %2$s.', 'learnomy' ), \Learnomy\quiz_label( true ), \Learnomy\course_label( true ) ) ),
					esc_html__( 'Forbidden', 'learnomy' ),
					array( 'response' => 403 )
				);
			}
		}

		// Course dropdown — admins pick any course; instructors only their own.
		// Same attach-target rule as the lesson editor: no trashed courses.
		$courses = Course::selectable( current_user_can( 'manage_options' ) ? 0 : get_current_user_id() );

		// Front-of-site context for the View Quiz link + slug URL preview.
		// A quiz is either attached to a course (/courses/:course/quiz/:slug/)
		// or standalone (/quiz/:slug/). Resolved here so the view stays
		// logic-light and the URLs come from the route registry.
		$quiz_course      = ( $is_edit && ! empty( $quiz->course_id ) ) ? Course::find( (int) $quiz->course_id ) : null;
		$quiz_is_attached = $quiz_course && ! empty( $quiz_course->slug );
		$quiz_url_pattern = $quiz_is_attached
			? trailingslashit( \Learnomy\route_url( 'course', array( 'slug' => (string) $quiz_course->slug ) ) ) . 'quiz/<' . __( 'slug', 'learnomy' ) . '>/'
			: trailingslashit( \Learnomy\route_url( 'standalone-quiz', array( 'slug' => '__SLUG__' ) ) );
		$quiz_url_pattern = str_replace( '__SLUG__', '<' . __( 'slug', 'learnomy' ) . '>', $quiz_url_pattern );

		$quiz_front_url = '';
		if ( $is_edit && 'published' === ( $quiz->status ?? '' ) && ! empty( $quiz->slug ) ) {
			// The View Quiz link must land where the front end actually serves
			// the quiz, so it reads the same rule every front surface does.
			$quiz_front_url = Quiz::url( $quiz, $quiz_course );
		}

		// Tag taxonomies for the editor (registry-driven). Mirrors the lesson +
		// question editors: the card shows only on edit (a quiz must exist before
		// tags can be mapped), each registered quiz vocabulary lists its own
		// type-scoped terms, and the inline quick-add seeds the right type.
		$quiz_taxonomies = $is_edit ? \Learnomy\quiz_taxonomies() : array();
		$quiz_tag_ids    = $is_edit ? Tag::get_ids_for_object( (int) $quiz->id, 'quiz' ) : array();
		$taxonomy_terms  = array();
		foreach ( $quiz_taxonomies as $qz_tax ) {
			$taxonomy_terms[ $qz_tax['type'] ] = Tag::where( array( 'type' => $qz_tax['type'] ), 'name', 'ASC' );
		}

		include LEARNOMY_DIR . 'includes/admin/views/quiz-editor.php';
	}

	public function render_question_editor(): void {
		$question_id = isset( $_GET['question_id'] ) ? absint( $_GET['question_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$question    = $question_id ? Question::find( $question_id ) : null;
		$is_edit     = (bool) $question;

		// Per-resource ownership gate. An instructor may only edit a question
		// they authored; admins edit any. Mirrors Questions_Controller::can_manage.
		if ( $is_edit && ! current_user_can( 'manage_options' )
			&& (int) ( $question->author_id ?? 0 ) !== get_current_user_id() ) {
			wp_die(
				esc_html__( 'You can only edit questions you created.', 'learnomy' ),
				esc_html__( 'Forbidden', 'learnomy' ),
				array( 'response' => 403 )
			);
		}

		$question_categories = QuestionCategory::where( array(), 'name', 'ASC' );

		// Preload the answer rows so the view can render the per-type editor
		// pre-populated on reload. Empty array on the "new" form.
		$answers = $is_edit ? \Learnomy\Models\Answer::get_for_question( (int) $question->id ) : array();

		// Expose the canonical type registry to the view so the dropdown +
		// per-type layout switcher stay in sync with REST + Pro extensions.
		$question_types = \Learnomy\QuestionTypes::all();

		// Quizzes the question is currently in. Question editor exposes an
		// inline multi-select so authors can attach/detach the question
		// without bouncing to each quiz editor (Basecamp #9928064574).
		// Save fan-out happens in Questions_Controller via the quiz_ids param.
		$assigned_quiz_ids = $is_edit
			? \Learnomy\Models\QuizSlot::get_quiz_ids_for_question( (int) $question->id )
			: array();
		// Selectable quiz set — recent 100 published-or-draft quizzes. Sites
		// with more than 100 quizzes still get the inline attach for the
		// recent ones; older quizzes manage attachments from their own editor.
		// Scoped to the instructor's own quizzes (admins see all) so an
		// instructor can't attach their question to someone else's quiz.
		// Scoped the same way the Quizzes list is: quizzes this user authored,
		// plus quizzes on a course they manage. Author-only meant an instructor
		// could not attach their own question to a quiz sitting on their own
		// course, because someone else had created the quiz.
		$quiz_scope         = Permission_Engine::instructor_scope_sql( get_current_user_id(), 'manage_course', 'q.' );
		$selectable_quizzes = \Learnomy\Models\Quiz::list_filtered( array(), 'updated_at', 'DESC', 100, 0, 0, $quiz_scope );

		// Tag taxonomies for the editor (registry-driven) + read-only Details.
		$question_taxonomies = $is_edit ? \Learnomy\question_taxonomies() : array();
		$question_tag_ids    = $is_edit ? Tag::get_ids_for_object( (int) $question->id, 'question' ) : array();
		$taxonomy_terms      = array();
		foreach ( $question_taxonomies as $q_tax ) {
			$taxonomy_terms[ $q_tax['type'] ] = Tag::where( array( 'type' => $q_tax['type'] ), 'name', 'ASC' );
		}

		$question_author_name = '';
		if ( $is_edit && ! empty( $question->author_id ) ) {
			$q_author             = get_userdata( (int) $question->author_id );
			$question_author_name = $q_author ? $q_author->display_name : '';
		}

		include LEARNOMY_DIR . 'includes/admin/views/question-editor.php';
	}

	// ── Lesson editor assets ─────────────────────────────────────────────────

	/**
	 * Enqueue Editor.js core, all block plugins, and the lesson editor script/style.
	 *
	 * Called only on the lesson editor admin page.
	 */
	private function enqueue_lesson_editor_assets(): void {
		// ONE shared loader for the full, uniform Editor.js tool bundle. It also
		// runs wp_enqueue_media() (the Resources uploader needs the wp.media
		// frame — bug card #4) and enqueues the shared tools factory. Every
		// editor — lesson Content, course Description, and future content areas —
		// loads the exact same set, so they never drift apart again.
		$plugin_handles = $this->enqueue_editorjs_vendor();
		if ( empty( $plugin_handles ) ) {
			return;
		}

		// Confirm modal — gates the destructive "Delete Lesson" action so it can
		// never fire without an explicit confirmation (window.lrnConfirm).
		wp_enqueue_script( 'learnomy-confirm-modal', LEARNOMY_URL . 'assets/js/shared/confirm-modal.js', array( 'wp-i18n' ), LEARNOMY_VERSION, true );

		// Lesson editor JS — depends on core + all plugins + the confirm modal.
		wp_enqueue_script(
			'learnomy-lesson-editor',
			LEARNOMY_URL . 'assets/js/admin/lesson-editor.js',
			array_merge( $plugin_handles, array( 'wp-i18n', 'learnomy-confirm-modal', 'learnomy-ext-fields' ) ),
			LEARNOMY_VERSION,
			true
		);
		wp_set_script_translations( 'learnomy-lesson-editor', 'learnomy', LEARNOMY_DIR . 'languages' );

		// Build the localized config for the editor script.
		$lesson_id = isset( $_GET['lesson_id'] ) ? absint( $_GET['lesson_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$lesson    = $lesson_id ? Lesson::find( $lesson_id ) : null;

		wp_localize_script(
			'learnomy-lesson-editor',
			'learnomyLessonEditor',
			array(
				'restUrl'   => rest_url( 'learnomy/v1/' ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'lessonId'  => $lesson_id,
				// Dual-format read (INV-8): for_editor() normalises whatever the
				// row stores (legacy Editor.js JSON OR new semantic HTML) into the
				// Editor.js doc the editor expects, so the editor always seeds
				// structured blocks regardless of the storage format.
				'content'   => $lesson ? json_decode( \Learnomy\Content\Content_HTML::for_editor( (string) $lesson->content ), true ) : null,
				'courseId'  => $lesson ? (int) $lesson->course_id : 0,
				'sectionId' => $lesson ? (int) ( $lesson->section_id ?? 0 ) : 0,
				'parentId'  => $lesson ? (int) ( $lesson->parent_id ?? 0 ) : 0,
				'i18n'      => array(
					'placeholder'    => sprintf( /* translators: %s: singular Lesson label */ __( 'Start writing your %s content…', 'learnomy' ), \Learnomy\lesson_label() ),
					'saved'          => sprintf( /* translators: %s: singular Lesson label */ __( '%s saved.', 'learnomy' ), \Learnomy\lesson_label() ),
					'titleRequired'  => sprintf( /* translators: %s: singular Lesson label */ __( 'Please enter a %s title.', 'learnomy' ), \Learnomy\lesson_label() ),
					'courseRequired' => sprintf( /* translators: %s: singular Course label */ __( 'Please pick a %s.', 'learnomy' ), \Learnomy\course_label() ),
					'selectSection'  => sprintf( /* translators: %s: singular Course label */ __( 'No section (directly under the %s)', 'learnomy' ), \Learnomy\course_label() ),
					'parentNone'     => __( 'None (top-level)', 'learnomy' ),
					'update'         => sprintf( /* translators: %s: singular Lesson label */ __( 'Update %s', 'learnomy' ), \Learnomy\lesson_label() ),
					'deleteTitle'    => sprintf( /* translators: %s: singular Lesson label */ __( 'Delete this %s?', 'learnomy' ), \Learnomy\lesson_label() ),
					'deleteMessage'  => sprintf( /* translators: %s: singular Lesson label */ __( 'This permanently removes the %s along with its progress, comments and attachments. This cannot be undone.', 'learnomy' ), \Learnomy\lesson_label() ),
					'deleteConfirm'  => sprintf( /* translators: %s: singular Lesson label */ __( 'Delete %s', 'learnomy' ), \Learnomy\lesson_label() ),
					'deleteFailed'   => sprintf( /* translators: %s: singular Lesson label */ __( 'Could not delete the %s. Please try again.', 'learnomy' ), \Learnomy\lesson_label() ),
				),
			)
		);

		// Lesson editor CSS — depends on base admin styles.
		wp_enqueue_style(
			'learnomy-lesson-editor',
			LEARNOMY_URL . 'assets/css/admin/lesson-editor.css',
			array( 'learnomy-admin' ),
			LEARNOMY_VERSION
		);
	}

	/**
	 * Enqueue Editor.js core + tools + the shared admin block-editor mount on
	 * the Add/Edit Course admin pages.
	 *
	 * Reuses the same vendor bundle the lesson editor uses (one set of tools,
	 * one stable build pipeline) and localizes a minimal config object keyed
	 * `learnomyBlockEditor`. `block-editor.js` mounts every
	 * `.lrn-editor-host[data-input]` on the form (Description + Completion /
	 * Welcome messages) and, on submit, writes each editor's JSON back into its
	 * paired hidden input — so the course-form save path stays unchanged.
	 */
	/**
	 * Enqueue the Editor.js core + tool bundle and return the script handles
	 * so the caller can declare them as dependencies. Shared by every editor
	 * that mounts an Editor.js field (course description, quiz description,
	 * question content/explanation). Returns an empty array when the vendor
	 * bundle is missing so callers can skip mounting gracefully.
	 *
	 * @return string[] Script handles (`editorjs` + each available tool).
	 */
	private function enqueue_editorjs_vendor(): array {
		$vendor      = LEARNOMY_URL . 'assets/js/vendor/editorjs/';
		$vendor_path = LEARNOMY_DIR . 'assets/js/vendor/editorjs/';

		if ( ! file_exists( $vendor_path . 'editor.umd.js' ) ) {
			return array();
		}

		// The Image / Attaches tools open the WP media frame and post to the
		// Media REST controller, so every editor needs the media scripts.
		wp_enqueue_media();

		wp_enqueue_script( 'editorjs', $vendor . 'editor.umd.js', array(), '2.30.8', true );

		// THE single, full, uniform tool bundle. Every admin block editor — the
		// course Description, the lesson Content, and any future content area —
		// loads this exact set so they behave identically. Bundles missing on
		// disk are skipped silently (Editor.js degrades gracefully), which keeps
		// us safe across partial vendor updates.
		$plugins = array(
			'editorjs-header'      => 'header.umd.js',
			'editorjs-list'        => 'list.umd.js',
			'editorjs-image'       => 'image.umd.js',
			'editorjs-embed'       => 'embed.umd.js',
			'editorjs-table'       => 'table.umd.js',
			'editorjs-code'        => 'code.umd.js',
			'editorjs-quote'       => 'quote.umd.js',
			'editorjs-delimiter'   => 'delimiter.umd.js',
			'editorjs-checklist'   => 'checklist.umd.js',
			'editorjs-warning'     => 'warning.umd.js',
			'editorjs-marker'      => 'marker.umd.js',
			'editorjs-inline-code' => 'inline-code.umd.js',
			'editorjs-underline'   => 'underline.umd.js',
			'editorjs-attaches'    => 'attaches.umd.js',
			'editorjs-raw'         => 'raw.umd.js',
			'editorjs-link'        => 'link.umd.js',
			'editorjs-math'        => 'math.umd.js',
		);

		// KaTeX stylesheet for the math tool — only when shipped.
		if ( file_exists( LEARNOMY_DIR . 'assets/css/vendor/katex.min.css' ) ) {
			wp_enqueue_style( 'katex', LEARNOMY_URL . 'assets/css/vendor/katex.min.css', array(), '0.16.11' );
		}

		$plugin_handles = array( 'editorjs' );
		foreach ( $plugins as $handle => $file ) {
			if ( ! file_exists( $vendor_path . $file ) ) {
				continue;
			}
			wp_enqueue_script( $handle, $vendor . $file, array( 'editorjs' ), '1.0.0', true );
			$plugin_handles[] = $handle;
		}

		/**
		 * Open extension seam — it is the owner's site, so we never gate what an
		 * author may add. Any plugin/theme/mu-plugin can enqueue extra Editor.js
		 * tool bundles here; the bundle registers itself on the shared
		 * `window.learnomyEditorTools` global that editor-tools.js merges, so a
		 * tool added once works in every Learnomy editor (backend AND the Pro
		 * front-end builder, which applies the same filter on its own enqueue).
		 *
		 * @param array $bundles Map of script handle => script URL. Default empty.
		 */
		$extra_bundles = apply_filters( 'learnomy_editorjs_tools', array() );
		if ( is_array( $extra_bundles ) ) {
			foreach ( $extra_bundles as $extra_handle => $extra_src ) {
				if ( ! is_string( $extra_handle ) || ! is_string( $extra_src ) || '' === $extra_src ) {
					continue;
				}
				wp_enqueue_script( $extra_handle, $extra_src, array( 'editorjs' ), LEARNOMY_VERSION, true );
				$plugin_handles[] = $extra_handle;
			}
		}

		// Image width + alignment (a Block Tune on the image block). Must be a
		// DEPENDENCY of editor-tools.js, not merely enqueued near it — the tools
		// factory reads window.LrnImageLayout while building the tools map, so if
		// this loaded after it, the tune would simply never be registered and the
		// author would silently be back to having no size control.
		wp_enqueue_script(
			'learnomy-image-layout-tune',
			LEARNOMY_URL . 'assets/js/admin/image-layout-tune.js',
			array( 'editorjs' ),
			LEARNOMY_VERSION,
			true
		);
		// Seed the tune's i18n object. image-layout-tune.js reads its labels from
		// window.LearnomyEditor.i18n — which nothing defined, so Width/Align and
		// the Left/Center/Right labels always rendered their English fallbacks on
		// every locale. This is the delivery path the strings were waiting on.
		wp_localize_script(
			'learnomy-image-layout-tune',
			'LearnomyEditor',
			array(
				'i18n' => array(
					'imageWidth'  => __( 'Width', 'learnomy' ),
					'imageAlign'  => __( 'Align', 'learnomy' ),
					'alignLeft'   => __( 'Left', 'learnomy' ),
					'alignCenter' => __( 'Center', 'learnomy' ),
					'alignRight'  => __( 'Right', 'learnomy' ),
				),
			)
		);
		$plugin_handles[] = 'learnomy-image-layout-tune';

		// The ONE shared tools factory (window.lrnEditorTools). Depends on every
		// plugin bundle (core + filtered extras) so the tool globals exist when
		// an editor reads its tools.
		wp_enqueue_script(
			'learnomy-editor-tools',
			LEARNOMY_URL . 'assets/js/admin/editor-tools.js',
			array_merge( $plugin_handles, array( 'wp-i18n' ) ),
			LEARNOMY_VERSION,
			true
		);
		// Load the JS translations for the embed block's UI strings.
		wp_set_script_translations( 'learnomy-editor-tools', 'learnomy', LEARNOMY_DIR . 'languages' );
		$plugin_handles[] = 'learnomy-editor-tools';

		// The unified embed component CSS — the SAME shared file the player loads —
		// so the LrnEmbedBlock live preview is pixel-identical to the published
		// result (one definition, no dups).
		wp_enqueue_style(
			'learnomy-embed',
			LEARNOMY_URL . 'assets/css/shared/embed.css',
			array( 'learnomy-admin' ),
			LEARNOMY_VERSION
		);

		// Content-image layout — same shared file the player loads, same reason: the
		// width an author picks in the editor has to be the width the student sees.
		// Two stylesheets would mean two answers, and the author would only discover
		// which one was right after publishing.
		wp_enqueue_style(
			'learnomy-content-image',
			LEARNOMY_URL . 'assets/css/shared/content-image.css',
			array( 'learnomy-admin' ),
			LEARNOMY_VERSION
		);

		return $plugin_handles;
	}

	private function enqueue_course_description_editor_assets(): void {
		$plugin_handles = $this->enqueue_editorjs_vendor();
		if ( empty( $plugin_handles ) ) {
			return;
		}

		// One generic mount for every `.lrn-editor-host` on the course form
		// (Description + Completion / Welcome messages), so they all use the same
		// block editor instead of a mix of Editor.js and classic `wp_editor()`.
		wp_enqueue_script(
			'learnomy-block-editor',
			LEARNOMY_URL . 'assets/js/admin/block-editor.js',
			$plugin_handles,
			LEARNOMY_VERSION,
			true
		);

		wp_localize_script(
			'learnomy-block-editor',
			'learnomyBlockEditor',
			array(
				'restUrl' => rest_url( 'learnomy/v1/' ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'placeholder' => sprintf( /* translators: %s: singular Course label */ __( 'Write your %s description…', 'learnomy' ), \Learnomy\course_label() ),
				),
			)
		);
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Build a single stat card descriptor.
	 *
	 * @param string $label Human-readable label.
	 * @param int    $count Numeric value to display.
	 * @param string $icon  Lucide icon name (e.g. 'book-open').
	 * @return array{label: string, count: int, icon: string}
	 */
	public function get_stat( string $label, int $count, string $icon ): array {
		return array(
			'label' => $label,
			'count' => $count,
			'icon'  => $icon,
		);
	}

	/**
	 * Return the current admin page slug from the query string.
	 *
	 * @return string Page slug or empty string when not on an admin page.
	 */
	public function current_page(): string {
		return isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Gather data for the dashboard charts.
	 *
	 * Pulls 90 days of enrollments and revenue so the JS period selector can
	 * filter to 7d / 30d / 90d without extra AJAX round-trips.
	 *
	 * @return array Chart data keyed for wp_localize_script.
	 */
	private function gather_chart_data(): array {
		$ninety_days_ago = gmdate( 'Y-m-d', strtotime( '-90 days' ) );

		// Daily enrollments for the last 90 days.
		$daily_enrollments = Enrollment::get_daily_since( $ninety_days_ago );

		$enrollment_series = array();
		foreach ( $daily_enrollments as $row ) {
			$enrollment_series[] = array(
				'x' => $row->day,
				'y' => (int) $row->total,
			);
		}

		// Daily revenue for the last 90 days (completed transactions only).
		$daily_revenue = Transaction::get_daily_revenue_since( $ninety_days_ago );

		$revenue_series = array();
		$total_revenue  = 0;
		foreach ( $daily_revenue as $row ) {
			$amount           = (float) $row->total;
			$total_revenue   += $amount;
			$revenue_series[] = array(
				'x' => $row->day,
				'y' => round( $amount, 2 ),
			);
		}

		// Course completion breakdown — DISTINCT people per state, not enrollment
		// rows (a multi-source learner would otherwise inflate a bucket; Basecamp
		// 10109120366). ('not_started' is not a stored enrollment status, so it
		// resolves to 0 as before — a separate dead-value item, unchanged here.)
		$completion_completed   = Enrollment::count_distinct_students( [], [ 'completed' ] );
		$completion_active      = Enrollment::count_distinct_students( [], [ 'active' ] );
		$completion_not_started = Enrollment::count_distinct_students( [], [ 'not_started' ] );

		// Top 5 courses by student count.
		$top_courses_raw = Enrollment::get_top_courses( 5 );

		$top_courses = array();
		foreach ( $top_courses_raw as $course ) {
			// Sparkline: last 30 days of enrollments for this course.
			$sparkline_data = Enrollment::get_daily_for_course_since(
				(int) $course->id,
				gmdate( 'Y-m-d', strtotime( '-30 days' ) )
			);

			$sparkline = array();
			foreach ( $sparkline_data as $point ) {
				$sparkline[] = (int) $point->total;
			}

			$top_courses[] = array(
				'id'              => (int) $course->id,
				'title'           => $course->title,
				'students'        => (int) $course->student_count,
				'revenue'         => round( (float) $course->revenue, 2 ),
				'completion_rate' => (float) $course->completion_rate,
				'sparkline'       => $sparkline,
			);
		}

		// Currency symbol for display.
		$settings = get_option( 'learnomy_settings', array() );
		$currency = $settings['currency'] ?? 'USD';

		return array(
			'enrollments'  => $enrollment_series,
			'revenue'      => $revenue_series,
			'totalRevenue' => round( $total_revenue, 2 ),
			'completion'   => array(
				'completed'   => $completion_completed,
				'in_progress' => $completion_active,
				'not_started' => $completion_not_started,
			),
			'topCourses'   => $top_courses,
			'currency'     => $currency,
		);
	}

	/**
	 * Collect all numbers shown on the dashboard.
	 *
	 * Heavy queries are avoided: each model call hits a single indexed COUNT.
	 *
	 * @return array<string, int>
	 */
	private function gather_dashboard_stats(): array {
		return array(
			'total_courses'        => Course::count(),
			'active_enrollments'   => Enrollment::count( array( 'status' => 'active' ) ),
			'revenue_transactions' => Transaction::count(),
			'pending_reviews'      => Review::count( array( 'status' => 'pending' ) ),
			'pending_applications' => InstructorApplication::count( array( 'status' => 'pending' ) ),
			'total_certificates'   => Certificate::count(),
		);
	}
}
