<?php
/**
 * Module Kernel — controls which Learnomy features are enabled per site.
 *
 * Each feature group (membership, coupons, commissions, etc.) is gated by
 * a module flag. The kernel is the single source of truth for which modules
 * a site owner has enabled. Admin menus, REST routes, and feature hooks
 * consult `is_enabled()` before registering anything.
 *
 * This is the Free-side counterpart to Learnomy_Pro's extension toggle
 * system. Both contribute to the same Modules admin page.
 *
 * See plan: docs/plans/00-pre-release-foundation.md §3 (module system),
 * §1.5 D4 (default-enabled lists), §7 Phase 0 (kernel scope).
 *
 * @package Learnomy\Modules
 */

namespace Learnomy\Modules;

defined( 'ABSPATH' ) || exit;

final class Module_Kernel {

	/**
	 * Option key storing the array of enabled module slugs.
	 */
	private const OPTION = 'learnomy_modules_enabled';

	/**
	 * Option key storing modules the site owner has EXPLICITLY turned off.
	 *
	 * `is_enabled()` merges `defaults()` so a new default-on module shipped in
	 * an update is honoured on existing installs. The side effect used to be
	 * that an optional default-on module (reviews, quizzes, certificates, ...)
	 * could never be switched off: `disable()` dropped it from the enabled
	 * list, but the defaults-merge added it straight back, so the Modules
	 * toggle did nothing. Recording an explicit opt-out here lets the toggle
	 * actually disable an optional module while still auto-enabling brand-new
	 * default-on modules on upgrade (they are in `defaults()` and not in this
	 * list). Required modules are never added here.
	 */
	private const OPTION_DISABLED = 'learnomy_modules_disabled';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Returns true when the named module is enabled by the site owner.
	 *
	 * Always-on (required) modules return true unconditionally — see
	 * `required()`. Site owners cannot disable required modules from the
	 * Modules admin page; the toggle is locked.
	 */
	public function is_enabled( string $slug ): bool {
		if ( in_array( $slug, self::required(), true ) ) {
			return true;
		}

		// An explicit opt-out always wins (except for required modules, handled
		// above). This is what makes the Modules toggle able to switch off an
		// optional default-on module.
		if ( in_array( $slug, $this->disabled_modules(), true ) ) {
			return false;
		}

		$enabled = (array) get_option( self::OPTION, array() );

		// Merge defaults so a new release that adds a default-on module
		// is honoured on existing installs (where the option row already
		// exists and would otherwise short-circuit the defaults() fallback
		// in get_option). Without this merge a brand-new feature shipped
		// as "on by default" stayed silently OFF on every upgraded site.
		$enabled = array_values( array_unique( array_merge( self::defaults(), $enabled ) ) );

		$result = in_array( $slug, $enabled, true );

		/**
		 * Final say on whether an OPTIONAL module is active. Lets a site-wide
		 * mode (e.g. solo academy) force a module off without mutating the
		 * stored Modules option. Required modules already returned true above,
		 * so this can never switch a required module off.
		 *
		 * @param bool   $result Whether the module resolves as enabled.
		 * @param string $slug   Module slug.
		 */
		return (bool) apply_filters( 'learnomy_module_enabled', $result, $slug );
	}

	/**
	 * Modules the site owner has explicitly switched off from the Modules page.
	 *
	 * @return string[]
	 */
	private function disabled_modules(): array {
		$disabled = (array) get_option( self::OPTION_DISABLED, array() );
		// Required modules can never be disabled, so never honour a stale entry.
		return array_values( array_diff( $disabled, self::required() ) );
	}

	/**
	 * Enable a module. Idempotent.
	 */
	public function enable( string $slug ): void {
		// Clear any explicit opt-out first so the module turns back on.
		$disabled = (array) get_option( self::OPTION_DISABLED, array() );
		if ( in_array( $slug, $disabled, true ) ) {
			update_option( self::OPTION_DISABLED, array_values( array_diff( $disabled, array( $slug ) ) ) );
		}

		$enabled = (array) get_option( self::OPTION, self::defaults() );
		if ( ! in_array( $slug, $enabled, true ) ) {
			$enabled[] = $slug;
			update_option( self::OPTION, array_values( array_unique( $enabled ) ) );
		}

		/**
		 * Fires after a module is enabled. Lets the owning extension (Free or
		 * Pro) run collaborative setup — the counterpart to
		 * `learnomy_after_module_disabled`, which extensions use to clean up
		 * state they contributed to shared Free tables. Named with an `after_`
		 * prefix so it never collides with the `learnomy_module_enabled` FILTER
		 * that `is_enabled()` applies.
		 *
		 * @param string $slug Module slug that was enabled.
		 */
		do_action( 'learnomy_after_module_enabled', $slug );
	}

	/**
	 * Disable a module. Required modules cannot be disabled and are silently
	 * ignored — `is_enabled()` continues returning true for them.
	 *
	 * Records an explicit opt-out (not just removal from the enabled list) so
	 * an optional default-on module actually stays off; the defaults-merge in
	 * `is_enabled()` would otherwise re-enable it on the next read.
	 */
	public function disable( string $slug ): void {
		if ( in_array( $slug, self::required(), true ) ) {
			return;
		}

		$enabled = (array) get_option( self::OPTION, self::defaults() );
		$enabled = array_values( array_diff( $enabled, array( $slug ) ) );
		update_option( self::OPTION, $enabled );

		$disabled = (array) get_option( self::OPTION_DISABLED, array() );
		if ( ! in_array( $slug, $disabled, true ) ) {
			$disabled[] = $slug;
			update_option( self::OPTION_DISABLED, array_values( array_unique( $disabled ) ) );
		}

		/**
		 * Fires after a module is disabled. A module that wrote rows into a
		 * shared Free table (e.g. content-drip scheduling into
		 * `lrn_lesson_unlocks`) listens here to purge the state it contributed,
		 * so a disabled module stops driving Free behaviour instead of leaving
		 * orphans behind. The disabled module's own `boot()` no longer runs, so
		 * this action — fired by Free at toggle time while the extension class is
		 * still loaded — is the collaborative cleanup seam.
		 *
		 * @param string $slug Module slug that was disabled.
		 */
		do_action( 'learnomy_after_module_disabled', $slug );
	}

	// -------------------------------------------------------------------------
	// Live commitments — what a site still owes people after a module goes off
	// -------------------------------------------------------------------------

	/**
	 * What a site owner is about to walk away from by switching a module off.
	 *
	 * A module toggle is a UI/routing decision, but three of our modules also
	 * carry MONEY and EARNED STATE: switching membership off does not stop a
	 * single recurring charge, switching commissions off does not pay a single
	 * instructor, and switching certificates off does not un-earn a
	 * certificate. Turning any of them off silently is how a site starts
	 * billing people for something they can no longer see. So every disable
	 * asks this question first, and the answer is shown to the owner before
	 * the toggle takes effect (`Admin_Controller::toggle_module()` returns 409
	 * until the owner confirms) and again on the Modules card afterwards.
	 *
	 * Returns a list, newest concern first. Each entry is:
	 *   code    — stable identifier for tests and for Pro to recognise its own
	 *   count   — how many rows/people are affected (0 means "not applicable")
	 *   message — one translated sentence, already interpolated, safe to show
	 *
	 * An empty array means "nothing is owed, this toggle is free".
	 *
	 * @param string $slug Module slug being disabled.
	 * @return array<int, array{code: string, count: int, message: string}>
	 */
	public static function disable_impact( string $slug ): array {
		$impact = array();

		switch ( $slug ) {
			case 'membership':
				$live = \Learnomy\Models\Subscription::count_live();
				if ( $live > 0 ) {
					$impact[] = array(
						'code'    => 'live_subscriptions',
						'count'   => $live,
						'message' => sprintf(
							/* translators: %d: number of subscriptions that are still being billed. */
							_n(
								'%d member still has a live subscription. Turning this off does not cancel it. They keep being charged, and the plans and pricing pages stop being reachable.',
								'%d members still have live subscriptions. Turning this off does not cancel them. They keep being charged, and the plans and pricing pages stop being reachable.',
								$live,
								'learnomy'
							),
							$live
						),
					);
				}
				break;

			case 'commissions':
				$pending = \Learnomy\Models\Withdrawal::count_pending();
				if ( $pending > 0 ) {
					$impact[] = array(
						'code'    => 'pending_withdrawals',
						'count'   => $pending,
						'message' => sprintf(
							/* translators: %d: number of payout requests waiting to be paid. */
							_n(
								'%d payout request is still waiting to be paid. Turning this off hides the Earnings screen where you settle it.',
								'%d payout requests are still waiting to be paid. Turning this off hides the Earnings screen where you settle them.',
								$pending,
								'learnomy'
							),
							$pending
						),
					);
				}
				break;

			case 'certificates':
				$issued = \Learnomy\Models\Certificate::count_active();
				if ( $issued > 0 ) {
					$impact[] = array(
						'code'    => 'issued_certificates',
						'count'   => $issued,
						'message' => sprintf(
							/* translators: %d: number of certificates already awarded to students. */
							_n(
								'%d certificate has already been awarded. Turning this off hides it from the student who earned it and breaks its public verify link.',
								'%d certificates have already been awarded. Turning this off hides them from the students who earned them and breaks their public verify links.',
								$issued,
								'learnomy'
							),
							$issued
						),
					);
				}
				break;
		}

		/**
		 * Declare what ELSE a site loses by switching this module off.
		 *
		 * Free answers for its own three money/earned-state modules above. Pro
		 * extensions own their equivalents (sold space plans, seat packs, paid
		 * cohorts) and append here — the toggle endpoint and the Modules page
		 * both read this one list, so a Pro module gets the same warning
		 * without either surface learning about it.
		 *
		 * @param array<int, array{code: string, count: int, message: string}> $impact Concerns so far.
		 * @param string                                                       $slug   Module being disabled.
		 */
		return (array) apply_filters( 'learnomy_module_disable_impact', $impact, $slug );
	}

	/**
	 * Whether ONE person still holds something this module sold them.
	 *
	 * This is the other half of `disable_impact()`. The impact list warns the
	 * OWNER; this keeps the promise to the BUYER. A member who is still being
	 * charged for a plan must keep the page where they can see the charge and
	 * stop it, even after the owner hides memberships from everyone else — so
	 * `Router::gate_module_routes()` and the account surfaces ask this before
	 * 404ing a module-bound page, and routes opt in with `keeps_commitment`.
	 *
	 * Note the asymmetry, and that it is deliberate: the SALES pages
	 * (`membership-pricing`) stay off, because the owner has stopped selling.
	 * Only the MANAGE surface survives, because the owner has not stopped
	 * charging.
	 *
	 * @param string $slug    Module slug.
	 * @param int    $user_id User to ask about.
	 */
	public static function has_live_commitment( string $slug, int $user_id ): bool {
		$held = false;

		if ( $user_id > 0 && 'membership' === $slug ) {
			$held = \Learnomy\Models\Subscription::user_has_live( $user_id );
		}

		/**
		 * Whether this user still holds something the module sold them, and so
		 * keeps its management surface after the module is switched off.
		 *
		 * @param bool   $held    Whether Free found a live commitment.
		 * @param string $slug    Module slug.
		 * @param int    $user_id User being asked about.
		 */
		return (bool) apply_filters( 'learnomy_module_user_commitment', $held, $slug, $user_id );
	}

	/**
	 * Get the list of currently enabled module slugs (including required).
	 *
	 * @return string[]
	 */
	public function get_enabled(): array {
		$stored   = (array) get_option( self::OPTION, self::defaults() );
		$required = self::required();

		// Honour the defaults-merge (so default-on modules count) then remove
		// any explicit opt-out, then force required back on. Order matters:
		// required modules can never be removed by an opt-out.
		$effective = array_merge( self::defaults(), $stored );
		$effective = array_diff( $effective, $this->disabled_modules() );
		$effective = array_merge( $required, $effective );

		return array_values( array_unique( $effective ) );
	}

	/**
	 * Default-enabled module slugs on a fresh install.
	 *
	 * Per Plan 00 §1.5 D4: 10 modules default-on, 5 opt-in. The 5 opt-in are
	 * payments, membership, commissions, coupons, pwa — anything the site
	 * owner doesn't strictly need on day one.
	 *
	 * @return string[]
	 */
	public static function defaults(): array {
		return array(
			'courses',
			'enrollments',
			'progress',
			'quizzes',
			'reviews',
			'notifications',
			'abilities',

			// `certificates` is NOT here. It is the single biggest contributor
			// to day-one admin clutter — its guard wraps 5 of the 26 visible
			// sidebar items — and a brand-new academy with no courses yet has
			// nothing to certify, so all five are noise until the owner has
			// something to award. Every preset except `simple` switches it on
			// (see presets()), so anyone who answers the setup wizard still
			// gets certificates immediately; only an owner who skips the
			// wizard entirely starts without them, and the Modules page is one
			// click away.
			//
			// EXISTING installs are unaffected: Migration 3.27.0 writes each
			// site's effective module set into the option before this list
			// changed, so a site already running certificates keeps them.
			// Removing a slug from defaults() without that migration is a
			// silent downgrade — see the migration docblock.
			// `payments` is on by default — pricing + checkout UI is what
			// most LMS customers want out of the box. Sites running a
			// lesson-only academy can disable it from the Modules page;
			// existing installs pick this up via the defaults-merge in
			// `is_enabled()` (shipped 2026-05-27). Without `payments` the
			// Edit Course form silently hides the entire pricing partial
			// — instructors saw no pricing UI and reported "settings not
			// saving" (Basecamp #9932303730).
			'payments',
		);
	}

	/**
	 * Modules that cannot be disabled via the Modules admin page.
	 *
	 * Per Plan 00 §10 Q8 (resolved): core-infra modules (progress, enrollments,
	 * notifications) are not user-disableable; the Modules page shows them
	 * locked-on with explanation.
	 *
	 * @return string[]
	 */
	public static function required(): array {
		return array(
			'courses',
			'enrollments',
			'progress',
			// Payments is essential: selling courses is core LMS value, and
			// turning it off silently hides the entire pricing UI (recurrence
			// of #9932303730). A free-only academy simply never sets a paid
			// price — payments-on costs them nothing.
			'payments',
		);
	}

	/**
	 * Module catalog: every module the Free plugin contributes to the
	 * Modules admin page, with metadata for rendering.
	 *
	 * Pro extensions contribute additional rows via the
	 * `learnomy_modules_catalog` filter.
	 *
	 * The `surfaces` array on each row is the human-readable map of what
	 * enabling / disabling the module turns on or off (admin menus, frontend
	 * pages, page-head output, ...). The Modules page renders it under each
	 * card so a site owner is never toggling blind — see modules.php. Pro rows
	 * added via the filter can supply their own `surfaces`; the view degrades
	 * gracefully when a row omits it.
	 *
	 * @return array<string, array{name: string, description: string, category: string, default: bool, surfaces?: string[], required?: bool, is_pro?: bool, pro_id?: string, status?: string}>
	 */
	public static function catalog(): array {
		$base = array(
			'courses'       => array(
				'name'        => __( 'Courses', 'learnomy' ),
				'description' => __( 'Course catalog, sections, lessons, and topics. Required core.', 'learnomy' ),
				'category'    => 'core',
				'default'     => true,
				'required'    => true,
				'surfaces'    => array(
					__( 'Admin: Courses, Lessons, and Categories menus', 'learnomy' ),
					__( 'Frontend: the /courses/ catalog and single-course pages', 'learnomy' ),
					__( 'REST: the courses, sections, and lessons endpoints', 'learnomy' ),
				),
			),
			'enrollments'   => array(
				'name'        => __( 'Enrollments', 'learnomy' ),
				'description' => __( 'Enrollment lifecycle, sources, and access gating. Required core.', 'learnomy' ),
				'category'    => 'core',
				'default'     => true,
				'required'    => true,
				'surfaces'    => array(
					__( 'Frontend: the enroll button and the /account/courses page', 'learnomy' ),
					__( 'Access gating that locks lessons until a student is enrolled', 'learnomy' ),
					__( 'Admin: the Students list and per-course enrollment counts', 'learnomy' ),
				),
			),
			'progress'      => array(
				'name'        => __( 'Progress', 'learnomy' ),
				'description' => __( 'Lesson, quiz, and topic completion tracking. Required core.', 'learnomy' ),
				'category'    => 'core',
				'default'     => true,
				'required'    => true,
				'surfaces'    => array(
					__( 'Frontend: lesson-player progress bars, resume, and mark-complete', 'learnomy' ),
					__( 'Course-completion detection that unlocks certificates', 'learnomy' ),
				),
			),
			'quizzes'       => array(
				'name'        => __( 'Quizzes', 'learnomy' ),
				'description' => __( 'Quiz builder, question bank, attempts, grading.', 'learnomy' ),
				'category'    => 'content',
				'default'     => true,
				'surfaces'    => array(
					__( 'Admin: Quizzes, Question Bank, and Grading menus', 'learnomy' ),
					__( 'Frontend: the quiz player on courses and standalone /quiz/ pages', 'learnomy' ),
				),
			),
			'certificates'  => array(
				'name'        => __( 'Certificates', 'learnomy' ),
				'description' => __( 'Ed25519-signed certificates with public verify URL and Canva-like designer.', 'learnomy' ),
				'category'    => 'student',
				'default'     => true,
				'surfaces'    => array(
					__( 'Admin: the Certificates menu and certificate designer', 'learnomy' ),
					__( 'Frontend: the /account/certificates page and the public /verify/ page', 'learnomy' ),
				),
			),
			'reviews'       => array(
				'name'        => __( 'Reviews', 'learnomy' ),
				'description' => __( 'Course reviews and ratings.', 'learnomy' ),
				'category'    => 'student',
				'default'     => true,
				'surfaces'    => array(
					__( 'Admin: the Reviews moderation menu', 'learnomy' ),
					__( 'Frontend: the review form and star ratings on catalog and course pages', 'learnomy' ),
				),
			),
			'notifications' => array(
				'name'        => __( 'Notifications', 'learnomy' ),
				'description' => __( 'Email and in-app notifications for enrollment, completion, and grading events.', 'learnomy' ),
				'category'    => 'operations',
				'default'     => true,
				'surfaces'    => array(
					__( 'Email and in-app notices for enrollment, completion, and grading', 'learnomy' ),
					__( 'Admin: the Email Log page and the frontend notification bell', 'learnomy' ),
				),
			),
			// `search` and `seo` were listed here until 1.7.1 and are gone on
			// purpose. Neither was ever read: a repo-wide sweep of every
			// module-check call form found ZERO gates for either slug, because
			// both are mandatory infrastructure rather than features an owner
			// chooses. FULLTEXT search lives inside the Course/Lesson model
			// queries, and Schema_Markup + Sitemap + Standalone_Sitemap are
			// instantiated unconditionally in class-learnomy.php ("Ours, always
			// served - the one sitemap no SEO plugin can switch off"). Rendering
			// switches for them told the owner they had a choice they did not
			// have; flipping either changed nothing at all.
			'abilities'     => array(
				'name'        => __( 'Abilities API', 'learnomy' ),
				'description' => __( 'WordPress Abilities API. Exposes 39 LMS abilities for AI agents.', 'learnomy' ),
				'category'    => 'operations',
				'default'     => true,
				'surfaces'    => array(
					__( 'The WordPress Abilities API endpoints that expose LMS actions to AI agents', 'learnomy' ),
				),
			),
			'payments'      => array(
				'name'        => __( 'Payments', 'learnomy' ),
				'description' => __( 'Stripe, PayPal, WooCommerce, and Test gateway adapters. Always on. Selling courses is core; a free-only academy just never sets a paid price.', 'learnomy' ),
				'category'    => 'commerce',
				'default'     => true,
				'required'    => true,
				'surfaces'    => array(
					__( 'Admin: the Learnomy Commerce menu with Transactions and Earnings', 'learnomy' ),
					__( 'Frontend: course pricing, the checkout flow, and payment gateways', 'learnomy' ),
					__( 'The course editor pricing panel', 'learnomy' ),
				),
			),
			'membership'    => array(
				'name'        => __( 'Membership', 'learnomy' ),
				'description' => __( 'Subscription plans with recurring billing. Enable to run a membership site.', 'learnomy' ),
				'category'    => 'commerce',
				'default'     => false,
				'surfaces'    => array(
					__( 'Admin: Commerce menu Memberships and Subscriptions pages', 'learnomy' ),
					__( 'Frontend: the /membership/ pricing page and members-only access', 'learnomy' ),
				),
			),
			'commissions'   => array(
				'name'        => __( 'Commissions & Payouts', 'learnomy' ),
				'description' => __( 'Revenue share for multi-instructor sites. Withdrawal request flow.', 'learnomy' ),
				'category'    => 'commerce',
				'default'     => false,
				'surfaces'    => array(
					__( 'Admin: Commerce menu Earnings and payout management', 'learnomy' ),
					__( 'Instructor dashboard: revenue share and the withdrawal-request flow', 'learnomy' ),
				),
			),
			'coupons'       => array(
				'name'        => __( 'Coupons', 'learnomy' ),
				'description' => __( 'Discount codes with usage limits and date windows.', 'learnomy' ),
				'category'    => 'commerce',
				'default'     => false,
				'surfaces'    => array(
					__( 'Admin: the Commerce menu Coupons page', 'learnomy' ),
					__( 'Frontend: the discount-code field at checkout', 'learnomy' ),
				),
			),
			'pwa'           => array(
				'name'        => __( 'PWA', 'learnomy' ),
				'description' => __( 'Service worker and manifest for installable web app experience. Beta.', 'learnomy' ),
				'category'    => 'operations',
				'default'     => false,
				'surfaces'    => array(
					__( 'Frontend: the service worker and web-app manifest for installable, offline-capable browsing', 'learnomy' ),
				),
			),
		);

		/**
		 * Filter the module catalog so Pro extensions and third parties
		 * can contribute additional rows to the Modules admin page.
		 *
		 * @param array $base Module catalog keyed by slug.
		 */
		return apply_filters( 'learnomy_modules_catalog', $base );
	}

	/**
	 * The four questions an owner is really answering, in the order they think
	 * about them: am I just publishing courses, am I training my own staff, am
	 * I selling courses, or am I running a subscription. `internal` is the one
	 * that was missing — a company giving training material to its team takes
	 * no money from anyone, ever, and had to work that out by picking a preset
	 * named after something else and then not setting prices (owner direction
	 * 2026-08-01, Basecamp 10132713132). Reviews are deliberately off for it:
	 * staff do not rate their compulsory compliance course. Certificates are
	 * on, because evidencing completion is usually the whole point.
	 *
	 * Setup wizard presets. Each preset is one click in the wizard and
	 * maps to a curated list of modules. Required modules are always
	 * included implicitly via `apply_preset()` — preset arrays only need
	 * to list opt-in modules.
	 *
	 * Operators can change any module later from the Modules admin page;
	 * presets are a starting point, not a lock-in.
	 *
	 * @return array<string, array{name: string, description: string, modules: string[]}>
	 */
	public static function presets(): array {
		$presets = array(
			'simple'     => array(
				'name'         => __( 'Simple courses', 'learnomy' ),
				'description'  => __( 'Just courses with lessons and topics. No quizzes, certificates, or payments.', 'learnomy' ),
				'modules'      => array(),
			),
			'standard'   => array(
				'name'         => __( 'Courses + quizzes', 'learnomy' ),
				'description'  => __( 'Add quizzes, certificates, reviews, and email notifications. Free academy.', 'learnomy' ),
				'modules'      => array( 'quizzes', 'certificates', 'reviews', 'notifications' ),
			),
			'internal'   => array(
				'name'         => __( 'Train my own team', 'learnomy' ),

				/*
				 * Kept to the same length and shape as its neighbours - one line
				 * for the situation, one for what comes with it. The first draft
				 * ran to three sentences and opened with "Nobody pays for
				 * anything", which both crowded the tile and read as a permanent
				 * restriction rather than a starting point. Every preset is
				 * changeable from Modules; none of them is a commitment.
				 */
				'description'  => __( 'Train your staff, with nothing put up for sale. Includes quizzes and certificates to evidence completion.', 'learnomy' ),
				'modules'      => array( 'quizzes', 'certificates', 'notifications' ),
			),
			'paid'       => array(
				'name'         => __( 'Paid courses', 'learnomy' ),
				'description'  => __( 'Sell individual courses. Includes payments, coupons, and revenue split for multi-instructor sites.', 'learnomy' ),
				'modules'      => array( 'quizzes', 'certificates', 'reviews', 'notifications', 'payments', 'commissions', 'coupons' ),
			),
			'membership' => array(
				'name'         => __( 'Membership site', 'learnomy' ),
				'description'  => __( 'Recurring subscription access to all courses. Includes payments, coupons, and certificates.', 'learnomy' ),
				'modules'      => array( 'quizzes', 'certificates', 'reviews', 'notifications', 'payments', 'membership', 'coupons' ),
			),

			/*
			 * The third selling job: seats, not seats-of-one. An owner whose
			 * customer is an organisation ("sell 50 seats to a company, give
			 * them a manager dashboard") had no tile that named their job, so
			 * they had to already know Spaces exists (Basecamp 10132713132 -
			 * the card's headline ask). The seat product itself is Spaces in
			 * Pro; the tile names that honestly so on a Free-only site it is
			 * an upsell with a real destination, never a dead screen. Free
			 * modules mirror `paid` minus coupons/commissions - seat buyers
			 * do not use discount codes at the course level.
			 */
			'school'     => array(
				'name'         => __( 'School or team seats', 'learnomy' ),
				'description'  => __( 'Sell seat bundles to schools and teams. Powered by Spaces in Learnomy Pro.', 'learnomy' ),
				'modules'      => array( 'quizzes', 'certificates', 'reviews', 'notifications' ),
			),
			'custom'     => array(
				'name'         => __( 'Custom', 'learnomy' ),
				'description'  => __( 'Pick exactly which features you need.', 'learnomy' ),
				'modules'      => self::defaults(),
			),
		);

		/**
		 * Filter the wizard presets. Custom builds can rename, replace, or
		 * add presets for white-labelled installs.
		 *
		 * @param array $presets Preset registry keyed by slug.
		 */
		return apply_filters( 'learnomy_module_presets', $presets );
	}

	/**
	 * Apply a wizard preset. Writes the option in one go so the admin
	 * sidebar reshapes immediately on the next request.
	 *
	 * Required modules are merged in automatically, so a preset author
	 * cannot accidentally disable them. Unknown preset slugs fall through
	 * silently — caller is responsible for validating against `presets()`.
	 */
	public function apply_preset( string $preset_slug ): bool {
		$presets = self::presets();
		if ( ! isset( $presets[ $preset_slug ] ) ) {
			return false;
		}

		$modules = array_values(
			array_unique(
				array_merge(
					self::required(),
					(array) $presets[ $preset_slug ]['modules']
				)
			)
		);

		update_option( self::OPTION, $modules );

		/**
		 * Fires after a wizard preset has been applied. Listeners can seed
		 * preset-specific demo data, send analytics, or trigger follow-up
		 * onboarding.
		 *
		 * @param string $preset_slug The preset that was applied.
		 * @param string[] $modules The resulting enabled-module list.
		 */
		do_action( 'learnomy_module_preset_applied', $preset_slug, $modules );

		return true;
	}
}
