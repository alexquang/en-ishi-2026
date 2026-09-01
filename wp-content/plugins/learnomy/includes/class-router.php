<?php
/**
 * Frontend router — registers rewrite rules and dispatches template rendering.
 *
 * @package Learnomy
 */

namespace Learnomy;

defined( 'ABSPATH' ) || exit;

class Router {

	public function __construct() {
		add_action( 'init', array( $this, 'add_rewrite_rules' ), 10 );
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
		// Correct the main query's own idea of what it is BEFORE anything reads it
		// (SEO plugins, the theme, body_class). See correct_virtual_query_flags().
		add_action( 'parse_query', array( $this, 'correct_virtual_query_flags' ) );
		// After the main query resolves (its post set is deliberately empty),
		// install the virtual page the corrected flags promise. `wp` fires
		// before template_redirect, so every theme/builder reader that runs
		// during header rendering sees a real queried object.
		add_action( 'wp', array( $this, 'install_virtual_page' ) );
		// ...and stop core's handle_404() undoing it: it runs AFTER the query, sees
		// our deliberately-empty post set, and calls the route a 404.
		add_filter( 'pre_handle_404', array( $this, 'skip_404_for_virtual_routes' ), 10, 2 );
		add_action( 'template_redirect', array( $this, 'handle_request' ) );
		// 301 retired front URLs to their current route before the 404 renders.
		add_action( 'template_redirect', array( $this, 'redirect_legacy_slugs' ), 1 );
		// Never let WordPress's canonical redirect add a trailing slash to the
		// Service Worker URL. A SW script served behind a redirect (301
		// /learnomy-sw.js -> /learnomy-sw.js/) is REJECTED by browsers on the
		// update check, so a buggy SW could never be replaced — the root cause
		// of "white screen on every URL" from a stuck old worker.
		add_filter( 'redirect_canonical', array( $this, 'skip_canonical_for_service_worker' ), 10, 2 );
		// Module-bound Free sections only render when their module is enabled.
		add_filter( 'learnomy_route_enabled', array( $this, 'gate_module_routes' ), 10, 2 );
	}

	/**
	 * Tell the main query the truth about a Learnomy route.
	 *
	 * Our rewrite rules resolve to `index.php?learnomy_route=…`, and
	 * `learnomy_route` is a private query var — WP_Query has never heard of it.
	 * A query with no recognised var is, to WP_Query, the blog home: it sets
	 * `is_home = true`, which resolves to whatever page is configured under
	 * Settings → Reading → "Posts page". We then render the real course on
	 * template_redirect and nobody notices... except everything that asks the
	 * QUERY what this page is, rather than asking us.
	 *
	 * That is one bug with many faces (Basecamp #10101955828): with Yoast active
	 * every route reported the Posts page's title, canonical and OG — a whole
	 * course catalog presenting itself to search engines as duplicates of /blog/.
	 * Yoast was only the messenger; it read `is_home` and believed it. Any SEO
	 * plugin, any theme branching on is_home(), and body_class() would all draw
	 * the same wrong conclusion from the same wrong state.
	 *
	 * So fix the state, not each reader. `is_home = false` + `is_404 = false` +
	 * an empty post set is the honest description of a Learnomy route: it is not
	 * the blog, it is not missing, and it has no WP posts behind it. Every reader
	 * — including SEO plugins we have never integrated with and ones that do not
	 * exist yet — gets a true answer for free.
	 *
	 * Main query only: a widget/shortcode running its own WP_Query on our page
	 * must keep its own flags.
	 *
	 * @param \WP_Query $query The query being parsed.
	 */
	public function correct_virtual_query_flags( $query ): void {
		if ( ! $query instanceof \WP_Query || ! $query->is_main_query() || is_admin() ) {
			return;
		}

		$route = $query->get( 'learnomy_route' );
		if ( ! $route ) {
			return;
		}

		$query->is_home    = false;
		$query->is_404     = false;
		$query->is_archive = false;
		$query->is_single  = false;

		// A Learnomy route PRESENTS AS A PAGE. The first cut of this fix set
		// every flag false — "not the blog, not missing, no WP posts behind
		// it" — but "nothing at all" turned out to be the wrong answer for the
		// readers that matter most: theme builders (Total's Dynamic Templates,
		// and the same class everywhere) decide whether to run their
		// header/footer through the page-builder's shortcode parser by asking
		// is_page()/get_queried_object(), and on "nothing" they skip it — so
		// sites whose chrome is built with WPBakery printed raw [vc_row]
		// markup on every Learnomy route while the rest of the site rendered
		// fine (Basecamp 10174434620). A rendered, page-like surface is a
		// page to everything that asks; what stays false is what the route is
		// NOT: the blog home, a 404, an archive, a single post. The Yoast
		// is_home fix (Basecamp 10101955828) is preserved — is_home and
		// is_404 stay false — and install_virtual_page() (on `wp`, after the
		// main query resolves) supplies the queried object these flags
		// promise.
		$query->is_singular = true;
		$query->is_page     = true;

		// No WP posts back a Learnomy route, so stop the loop resolving one.
		// Without this the queried object stays whatever is_home pointed at.
		// install_virtual_page() replaces the empty result with the virtual
		// post once the query has run.
		$query->set( 'post__in', array( 0 ) );
		$query->queried_object    = null;
		$query->queried_object_id = 0;

		/**
		 * Fires once the main query has been told it is on a Learnomy route.
		 *
		 * @since 1.7.0
		 *
		 * @param \WP_Query $query The corrected main query.
		 * @param string    $route The Learnomy route name.
		 */
		do_action( 'learnomy_virtual_query_corrected', $query, (string) $route );
	}

	/**
	 * Give the main query the page it now claims to be.
	 *
	 * Sibling correct_virtual_query_flags() sets is_page/is_singular true but
	 * empties the post set (no WP post backs a Learnomy route), so without this the
	 * flags promise a page and get_queried_object() answers null — the exact
	 * mismatch that made Total Theme's Dynamic Templates skip WPBakery's
	 * shortcode parser and print raw [vc_row] markup in the site chrome
	 * (Basecamp 10174434620). A lightweight virtual WP_Post (the technique
	 * BuddyPress and BuddyNext use for their component pages) makes every
	 * singular-path consumer — theme builders, body_class(), template tags
	 * reading $GLOBALS['post'] — see a coherent page.
	 *
	 * ID stays 0: postmeta reads answer empty, the admin bar offers no edit
	 * link, and is_front_page() cannot be fooled — with a static front page
	 * it compares against a real page ID (> 0), and with show_on_front=posts
	 * it reduces to is_home(), which stays false.
	 *
	 * post_title carries the same title Template_Loader puts in <title>, so a
	 * reader that takes its heading from the queried object (SEO plugins on
	 * the singular path, theme breadcrumbs) agrees with the document title
	 * instead of showing an empty string. Canonicals stay OURS: Schema_Markup
	 * prints the route canonical and feeds the same URL to Yoast/Rank Math
	 * via their canonical filters, so the virtual post's meaningless
	 * permalink is never what search engines see.
	 *
	 * Runs on `wp` — after WP_Query::get_posts() has resolved (and found the
	 * deliberately-empty set), before template_redirect renders anything.
	 */
	public function install_virtual_page(): void {
		global $wp_query;

		if ( is_admin() || ! $wp_query instanceof \WP_Query || ! $wp_query->is_main_query() ) {
			return;
		}

		$route = (string) $wp_query->get( 'learnomy_route' );
		if ( '' === $route ) {
			return;
		}

		$data = array(
			'route'       => $route,
			'slug'        => (string) get_query_var( 'learnomy_slug', '' ),
			'lesson_slug' => (string) get_query_var( 'learnomy_lesson_slug', '' ),
			'quiz_slug'   => (string) get_query_var( 'learnomy_quiz_slug', '' ),
		);

		$virtual_post = new \WP_Post(
			(object) array(
				'ID'             => 0,
				'post_author'    => 0,
				'post_title'     => Template_Loader::build_title( $route, $data ),
				'post_name'      => $route,
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'post_parent'    => 0,
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
				'filter'         => 'raw',
			)
		);

		$GLOBALS['post']             = $virtual_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- intentional virtual post so singular-path template tags have a valid object on synthetic routes.
		$wp_query->post              = $virtual_post;
		$wp_query->posts             = array( $virtual_post );
		$wp_query->queried_object    = $virtual_post;
		$wp_query->queried_object_id = 0;
		$wp_query->post_count        = 1;
		$wp_query->found_posts       = 1;
	}

	/**
	 * A Learnomy route is never WordPress's 404.
	 *
	 * `correct_virtual_query_flags()` empties the post set on purpose (no WP post
	 * backs a course). Core's `handle_404()` runs after the query, sees zero posts
	 * on a non-singular request and declares a 404 — which would undo the fix and
	 * hand every route `error404` body classes and a 404 status header, on a page
	 * that renders perfectly well.
	 *
	 * Short-circuiting here keeps `is_404 = false` all the way through. A genuinely
	 * missing entity (a bad course slug) is still a real 404: Template_Loader calls
	 * `$wp_query->set_404()` + `status_header( 404 )` itself once it knows the row
	 * does not exist, which is the only place that can actually tell.
	 *
	 * @param bool      $preempt Whether to short-circuit core's 404 handling.
	 * @param \WP_Query $query   The main query.
	 * @return bool
	 */
	public function skip_404_for_virtual_routes( $preempt, $query ) {
		if ( $query instanceof \WP_Query && $query->get( 'learnomy_route' ) ) {
			return true;
		}

		return $preempt;
	}

	/**
	 * Permanent-redirect retired top-level slugs to their current route.
	 *
	 * The Route Registry consolidation (P2, 2026-05-29) moved `/my-courses/`
	 * under the account hub at `/account/courses/` without a shim, so old
	 * bookmarks / external links 404'd (Basecamp #9957577958). Only fires on a
	 * genuine 404 so it never hijacks a real page a site owner created at the
	 * same path.
	 */
	public function redirect_legacy_slugs(): void {
		if ( ! is_404() ) {
			return;
		}

		$path = trim( (string) wp_parse_url( (string) add_query_arg( array() ), PHP_URL_PATH ), '/' );

		$legacy = array(
			'my-courses' => 'account-courses',
		);

		if ( isset( $legacy[ $path ] ) ) {
			wp_safe_redirect( route_url( $legacy[ $path ] ), 301 );
			exit;
		}
	}

	/**
	 * Gate Free's module-bound account sections on their module toggle, so a
	 * disabled module's section 404s on direct access (consistent with it being
	 * hidden from the account nav). Pro sections gate themselves by only
	 * registering their route while the extension is active.
	 *
	 * @param bool   $enabled Whether the route renders.
	 * @param string $route   Route name.
	 * @return bool
	 */
	public function gate_module_routes( bool $enabled, string $route ): bool {
		// Instructor registration is a site-owner toggle, not a module: when the
		// owner turns it off, the public application + registration pages 404 so
		// direct-URL access matches a hidden nav link (Basecamp #10054524045).
		if ( in_array( $route, array( 'become-instructor', 'register-instructor' ), true )
			&& ! \Learnomy\instructor_registration_enabled() ) {
			return false;
		}

		// The route → module binding is declared on the route itself in the
		// Routes registry (single source of truth), so Pro and third-party
		// routes added via the `learnomy_routes` filter are gated the same way
		// without touching this method. Unbound routes (catalog, course, the
		// free `enroll` flow, account-billing) always render.
		$module = Routes::module_for( $route );
		if ( null !== $module ) {
			// A route flagged `keeps_commitment` is where a buyer manages what
			// they already paid for, so it stays reachable for anyone who still
			// holds a live commitment from that module even after the owner
			// switches it off. Everything else 404s as before.
			return $enabled && (
				Routes::keeps_commitment( $route )
					? \Learnomy\module_enabled_for_user( $module )
					: \Learnomy\module_enabled( $module )
			);
		}
		return $enabled;
	}

	/**
	 * Register all Learnomy rewrite rules.
	 *
	 * Course-related routes live under the configurable base slug (default: "courses").
	 * All other routes (dashboard, instructor, membership, etc.) are top-level.
	 */
	public function add_rewrite_rules(): void {
		// Single source of truth: every rewrite rule (and every front URL via
		// route_url()) is derived from the Routes registry, so a slug can never
		// desync between a link and the rule that matches it. Extensions add
		// routes on the declarative `learnomy_routes` filter (which backs both
		// the rewrite rule and route_url()); the deprecated
		// learnomy_register_routes filter was removed — it could not back
		// route_url(). See plan/2026-05-29-route-registry.md.
		Routes::register_rewrite_rules();
	}

	/**
	 * Register custom query vars so WordPress passes them through.
	 *
	 * @param array $vars Existing registered query vars.
	 * @return array
	 */
	public function add_query_vars( array $vars ): array {
		$vars[] = 'learnomy_route';
		$vars[] = 'learnomy_slug';
		$vars[] = 'learnomy_lesson_slug';
		$vars[] = 'learnomy_quiz_slug';
		$vars[] = 'learnomy_txn';
		// Gated lesson-handout delivery (/learnomy-file/{id}/{name}/).
		$vars[] = 'learnomy_attachment';
		$vars[] = 'learnomy_file';
		// Archive page number from the /{archive}/page/N/ rewrite rule. Public
		// so WP also populates it from a legacy ?lrn_page= query string — one
		// read (get_query_var) then covers both the pretty path and the query.
		$vars[] = 'lrn_page';
		$vars   = apply_filters( 'learnomy_query_vars', $vars );
		return $vars;
	}

	/**
	 * Intercept requests for Learnomy routes and hand off to the template loader.
	 */
	public function handle_request(): void {
		// Commit a pending account-email change when the confirmation link
		// (sent to the new address) is opened.
		$this->maybe_confirm_email_change();

		// Handle ?lrn_action=enroll for free course enrollment (server-side form action).
		$this->maybe_handle_enroll_action();

		// Handle POST from the Become-an-Instructor application form.
		$this->maybe_handle_instructor_apply_action();

		// Optional homepage → catalog redirect.
		// Only triggers on the actual WordPress front page (not on /courses/ catalog).
		$route = get_query_var( 'learnomy_route' );
		if ( empty( $route ) && is_front_page() && ! is_paged() ) {
			$settings = get_option( 'learnomy_settings', array() );
			if ( ! empty( $settings['homepage_redirect'] ) ) {
				wp_safe_redirect( route_url( 'catalog' ), 302 );
				exit;
			}
		}

		$route = get_query_var( 'learnomy_route' );

		if ( empty( $route ) ) {
			return;
		}

		// Service Worker must be served as raw JavaScript from the root URL
		// so its scope covers all Learnomy routes.
		if ( 'service-worker' === $route ) {
			$this->serve_service_worker();
			return;
		}

		// PWA manifest served dynamically (same-origin JSON) so icon paths
		// follow plugins_url and start_url is non-redirecting.
		if ( 'pwa-manifest' === $route ) {
			$this->serve_pwa_manifest();
			return;
		}

		// Lesson handouts. Served here rather than as a REST route on purpose:
		// WP treats a cookie-authenticated REST request WITHOUT a nonce as
		// anonymous, so a plain <a download> link to /wp-json/ would 403 the
		// very student who is entitled. A front route is an ordinary request,
		// where the login cookie is simply the user. The permission check is
		// still the same one the REST listing uses.
		if ( 'lesson-attachment' === $route ) {
			\Learnomy\Services\Attachment_Download::serve( (int) get_query_var( 'learnomy_attachment' ) );
			return;
		}

		// Already-authenticated users have no use for the public registration
		// pages. Redirect here on template_redirect — BEFORE the theme prints
		// <head> — so the Location header actually goes out. The same redirect
		// done inside the template fires after wp_head() and trips
		// "Cannot modify header information - headers already sent"
		// (Basecamp #9919605705 / #9919587242).
		if ( 'register-instructor' === $route && is_user_logged_in() ) {
			wp_safe_redirect( route_url( 'become-instructor' ) );
			exit;
		}
		// Existing instructors hitting /become-instructor/ — bounce to the
		// instructor dashboard before any output so headers are still mutable.
		// The template used to do this inline but the redirect fired after
		// wp_head() and produced a "headers already sent" warning, leaving
		// the visitor on an empty page. Matches the register-* pattern above.
		if ( 'become-instructor' === $route && is_user_logged_in() ) {
			$current = wp_get_current_user();
			$is_inst = $current instanceof \WP_User
				&& ( in_array( 'lrn_instructor', (array) $current->roles, true )
					|| in_array( 'learnomy_instructor', (array) $current->roles, true ) );
			if ( $is_inst ) {
				wp_safe_redirect( route_url( 'instructor-dashboard' ) );
				exit;
			}
		}
		if ( 'register-student' === $route && is_user_logged_in() ) {
			$current_user = wp_get_current_user();
			// A logged-in user clicking "Register as Student" is consent to
			// take on the lrn_student role. The previous behavior — blanket
			// redirect to my-courses regardless of role — left WP-subscribers
			// (and any other non-student role) on a courses page they could
			// not actually enroll from. Grant the role on the fly so the
			// student dashboard is real, not a dead end. Existing students
			// are no-ops (add_role on an existing role is a noop in WP core).
			//
			// The grant needs proof the click was OURS. Without a nonce this was
			// a role write on a bare GET: any third-party page could point an
			// <img> or an <iframe> at it and a logged-in visitor's browser would
			// take the role, silently (Basecamp 10167485743). Reproduced with a
			// forged cross-site Referer on an administrator and on an instructor.
			//
			// Nothing is escalated by it — the grant is additive, cannot reach
			// instructor or admin, and lrn_student's capabilities are already
			// held by subscriber and administrator. The damage is state
			// pollution with visible consequences: an instructor forced into the
			// instructor+student pair that Basecamp #9936673357 specifically
			// removed, and a non-LMS role quietly losing its admin bar.
			//
			// The menu link carries a fresh nonce (see
			// Learnomy::nonce_register_student_menu_item). A request WITHOUT one
			// still redirects normally and simply does not write, so old
			// bookmarks and hand-written links keep working as navigation.
			$nonce           = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
			$may_grant       = (bool) wp_verify_nonce( $nonce, 'learnomy_register_student' );
			$just_registered = false;

			// An instructor is not a student. Approving an instructor
			// application DROPS lrn_student for exactly this reason — the pair
			// is mutually exclusive in our model (Basecamp #9936673357), and
			// WP then reports the instructor as "Student" everywhere it shows a
			// primary role. Granting it here would silently undo that.
			//
			// This check IS the rule, and as of 1.9.3 it is the only one. The
			// menu used to stop offering this link to instructors and admins;
			// that filter was deleted, because a plugin editing the site
			// owner's navigation is overreach and because a data invariant
			// cannot live in the absence of a button anyway - a stale
			// bookmark, a cached page, or a hand-written link all still reach
			// this route.
			// "If it renders, it is real" cuts both ways — the rule belongs
			// where the write happens.
			$is_staff = in_array( 'lrn_instructor', (array) $current_user->roles, true )
				|| user_can( $current_user, 'manage_options' );

			if ( $may_grant && ! $is_staff && $current_user instanceof \WP_User && ! in_array( 'lrn_student', (array) $current_user->roles, true ) ) {
				$current_user->add_role( 'lrn_student' );
				$just_registered = true;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$redirect_to_raw = isset( $_GET['redirect_to'] ) ? sanitize_text_field( wp_unslash( $_GET['redirect_to'] ) ) : '';
			$register_dest   = '' !== $redirect_to_raw ? wp_validate_redirect( $redirect_to_raw, route_url( 'student-dashboard' ) ) : route_url( 'student-dashboard' );
			// Append a one-shot ?registered=1 flag so the student dashboard
			// can show a welcome banner — silent role grants leave the user
			// confused about what just happened (Basecamp #9924598683 bounce).
			if ( $just_registered ) {
				$register_dest = add_query_arg( 'registered', '1', $register_dest );
			}
			wp_safe_redirect( $register_dest );
			exit;
		}

		// Receipt PDF download — handled outside Template_Loader so we can stream the bytes.
		if ( 'account-billing-receipt' === $route ) {
			if ( ! is_user_logged_in() ) {
				wp_safe_redirect( wp_login_url( home_url( add_query_arg( array(), false ) ) ) );
				exit;
			}
			$txn_id = (int) get_query_var( 'learnomy_txn', 0 );
			\Learnomy\Services\Receipt_PDF_Service::stream( $txn_id, get_current_user_id() );
			exit;
		}

		// PDF export of an attempt review — same page, ?export=pdf. Streams
		// and exits before the template renders; the service applies the same
		// owner-or-admin gate and reveal rules as the page itself.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only GET download of the viewer's own data.
		if ( 'account-quiz-results' === $route && isset( $_GET['export'] ) && 'pdf' === sanitize_key( wp_unslash( $_GET['export'] ) ) ) {
			if ( ! is_user_logged_in() ) {
				wp_safe_redirect( wp_login_url( home_url( add_query_arg( array(), false ) ) ) );
				exit;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only GET download of the viewer's own data.
			$attempt_id = isset( $_GET['attempt'] ) ? absint( wp_unslash( $_GET['attempt'] ) ) : 0;
			\Learnomy\Services\Quiz_Review_PDF_Service::stream( $attempt_id, get_current_user_id() );
			exit;
		}

		$data = array(
			'route'       => $route,
			'slug'        => get_query_var( 'learnomy_slug', '' ),
			'lesson_slug' => get_query_var( 'learnomy_lesson_slug', '' ),
			'quiz_slug'   => get_query_var( 'learnomy_quiz_slug', '' ),
		);

		Template_Loader::render( $data );
		exit;
	}

	/**
	 * Commit a staged account-email change from the confirmation link sent to
	 * the new address (`?lrn_confirm_email=<token>&uid=<id>`).
	 *
	 * The token is matched against the hash stored when the change was
	 * requested (REST `/auth/account`), and the link-clicker must be logged in
	 * as that user — so neither a stale link nor a third party can switch the
	 * email. Outcomes redirect back to the account-details page with a flag.
	 */
	private function maybe_confirm_email_change(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- token IS the capability; verified below.
		if ( empty( $_GET['lrn_confirm_email'] ) || empty( $_GET['uid'] ) ) {
			return;
		}
		$uid   = absint( $_GET['uid'] );
		$token = sanitize_text_field( wp_unslash( $_GET['lrn_confirm_email'] ) );
		// phpcs:enable

		$dest    = route_url( 'account-details' );
		$pending = (string) get_user_meta( $uid, 'learnomy_pending_email', true );
		$hash    = (string) get_user_meta( $uid, 'learnomy_pending_email_key', true );

		if ( '' === $pending || '' === $hash || ! wp_check_password( $token, $hash, $uid ) ) {
			wp_safe_redirect( add_query_arg( 'email_confirm', 'invalid', $dest ) );
			exit;
		}

		// The link goes to the new mailbox; the owner must be signed in as
		// themselves to complete the switch.
		if ( get_current_user_id() !== $uid ) {
			wp_safe_redirect( wp_login_url( add_query_arg( array(), false ) ) );
			exit;
		}

		// Guard against the address being claimed in the meantime.
		if ( email_exists( $pending ) ) {
			delete_user_meta( $uid, 'learnomy_pending_email' );
			delete_user_meta( $uid, 'learnomy_pending_email_key' );
			wp_safe_redirect( add_query_arg( 'email_confirm', 'taken', $dest ) );
			exit;
		}

		wp_update_user(
			array(
				'ID'         => $uid,
				'user_email' => $pending,
			)
		);
		delete_user_meta( $uid, 'learnomy_pending_email' );
		delete_user_meta( $uid, 'learnomy_pending_email_key' );
		wp_safe_redirect( add_query_arg( 'email_confirm', 'success', $dest ) );
		exit;
	}

	/**
	 * Process ?lrn_action=enroll for free courses.
	 *
	 * The single-course template generates enroll URLs with lrn_action=enroll,
	 * course_id, and a nonce. This handler catches them on template_redirect,
	 * enrolls the user via the Enrollment_Service, and redirects to the course.
	 */
	private function maybe_handle_enroll_action(): void {
		if ( empty( $_GET['lrn_action'] ) || 'enroll' !== $_GET['lrn_action'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		$course_id = absint( $_GET['course_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $course_id ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( add_query_arg( $_GET, home_url() ) ) );
			exit;
		}

		$nonce_action = 'lrn_enroll_' . $course_id;
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), $nonce_action ) ) {
			wp_die( esc_html__( 'Security check failed. Please try again.', 'learnomy' ), 403 );
		}

		// Capability gate — Permission_Engine resolves the per-course enroll ability.
		// Nonce alone protects against CSRF but does not authorize; pair with cap.
		// Engine takes the bare action name (`enroll`); the previous slashed
		// form `learnomy/enroll` was the Abilities-API style and produced the
		// nonsense cap `learnomy_learnomy/enroll` which no role carried, so
		// every student got 403 on the free-enroll URL action.
		if ( ! Permissions\Permission_Engine::can( get_current_user_id(), 'enroll', $course_id ) ) {
			wp_die( esc_html__( 'You are not allowed to enroll in this course.', 'learnomy' ), 403 );
		}

		$course = Models\Course::find( $course_id );
		if ( ! $course || 'published' !== $course->status ) {
			wp_safe_redirect( route_url( 'catalog' ) );
			exit;
		}

		$user_id = get_current_user_id();

		// Already enrolled? Redirect to course.
		if ( Models\Enrollment::is_enrolled( $user_id, $course_id ) ) {
			wp_safe_redirect( route_url( 'course', array( 'slug' => (string) $course->slug ) ) );
			exit;
		}

		// Derive the correct enrollment source for the course's access type.
		// The previous code hard-coded `source='free'` for every course. On a
		// membership / paid_or_membership course the Enrollment_Service vetoes
		// free-on-membership (402 WP_Error), but enroll() returns that error
		// rather than throwing — so the old catch (\RuntimeException only)
		// swallowed it and the handler fell through to the fake-success
		// redirect with NO row written. We now resolve the source up front and
		// route non-entitled users to the correct paid / membership path.
		$source = $this->resolve_enroll_source( $course, $user_id );

		// Not entitled to a free / membership claim → send to the right CTA
		// path instead of faking a success. `checkout` and `membership` are
		// the two paid acquisition routes; both end on a real purchase /
		// subscribe before an enrollment row is ever written.
		if ( 'checkout' === $source ) {
			wp_safe_redirect( add_query_arg( 'course_id', $course_id, route_url( 'enroll' ) ) );
			exit;
		}
		if ( 'membership-pricing' === $source ) {
			wp_safe_redirect( route_url( 'membership-pricing' ) );
			exit;
		}

		// Enroll. enroll() returns a WP_Error on a source/access-type mismatch
		// (it never throws) — surface it instead of silently redirecting to a
		// fake success.
		$service    = new Services\Enrollment_Service();
		$enrollment = $service->enroll( $user_id, $course_id, $source );
		if ( is_wp_error( $enrollment ) ) {
			$status = (int) ( $enrollment->get_error_data()['status'] ?? 400 );
			// A titled page with a back link, not a bare blank message — a gate
			// rejection (e.g. cohort-required) should explain itself and offer a
			// way back, not look like a broken page (Basecamp #9981599855).
			wp_die(
				esc_html( $enrollment->get_error_message() ),
				esc_html__( 'Enrollment unavailable', 'learnomy' ),
				array(
					'response'  => absint( $status ),
					'back_link' => true,
				)
			);
		}
		if ( $enrollment <= 0 ) {
			wp_die( esc_html__( 'Enrollment could not be completed. Please try again.', 'learnomy' ), 400 );
		}

		// F-04.2 success-flash: append ?lrn_enrolled=1 so the single-course
		// template can render a one-shot confirmation banner above the hero.
		// No session / cookie / transient — the flag clears on next page
		// load when the buyer navigates away or refreshes without it.
		wp_safe_redirect( add_query_arg( 'lrn_enrolled', '1', route_url( 'course', array( 'slug' => (string) $course->slug ) ) ) );
		exit;
	}

	/**
	 * Resolve the enrollment source for the single-course Enroll CTA.
	 *
	 * The Enroll CTA is the no-payment path: it only ever produces a free
	 * enrollment or a membership claim. Anyone who has to pay (one-time,
	 * fixed-term, recurring, or a non-member on a dual-path course) is routed
	 * to checkout / membership pricing instead of being enrolled here.
	 *
	 * Return values:
	 *   - 'free'               → access_type=free; create a free enrollment
	 *   - 'membership'         → member entitled via active subscription; claim it
	 *   - 'checkout'           → must purchase; caller redirects to /enroll/ checkout
	 *   - 'membership-pricing' → members-only course, viewer not a member;
	 *                            caller redirects to /membership/ pricing
	 */
	private function resolve_enroll_source( object $course, int $user_id ): string {
		// Free courses — and paid-model courses whose effective price is
		// zero — are a plain free enrollment, no entitlement required.
		// is_zero_charge() is the shared answer the CTA and the enroll
		// guards use; routing zero-charge to checkout added a detour page
		// to a claim that needs one click.
		if ( Models\Course::is_zero_charge( $course ) ) {
			return 'free';
		}

		// Any course reachable via a membership plan (members_only or the
		// dual-path one_time_or_members): an entitled member claims it via
		// their subscription with no payment.
		if ( Models\Course::has_member_path( $course ) ) {
			$subscriptions = new Services\Subscription_Service();
			if ( $subscriptions->has_access( $user_id, (int) $course->id ) ) {
				return 'membership';
			}

			// Not a member. A dual-path course still has a purchase route, so
			// send the viewer to checkout; a members-only course has no
			// purchase route, so send them to the membership pricing page.
			return Models\Course::is_members_only( $course ) ? 'membership-pricing' : 'checkout';
		}

		// Everything else (one_time, fixed_term, recurring) is purchase-only.
		return 'checkout';
	}

	/**
	 * Process POST from templates/become-instructor.php.
	 *
	 * The form posts back to the same page with `lrn_action=instructor_apply`
	 * + a nonce. This handler records the application row (status=pending),
	 * fires the `learnomy_instructor_application_submitted` hook so Notifier
	 * emails the admin, and redirects back to the same page so the
	 * "Application Under Review" state renders.
	 */
	private function maybe_handle_instructor_apply_action(): void {
		if ( 'POST' !== sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified explicitly below.
		if ( 'instructor_apply' !== sanitize_text_field( wp_unslash( $_POST['lrn_action'] ?? '' ) ) ) {
			return;
		}
		// Instructor registration closed (solo mode): the form route already
		// 404s, but a hand-crafted POST could still arrive — refuse it here too.
		if ( ! \Learnomy\instructor_registration_enabled() ) {
			return;
		}

		// Filterable white-label base for every redirect back to the form.
		$form_url = route_url( 'become-instructor' );

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( $form_url ) );
			exit;
		}

		if ( ! isset( $_POST['lrn_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lrn_nonce'] ) ), 'lrn_instructor_apply' ) ) {
			wp_die( esc_html__( 'Security check failed. Please try again.', 'learnomy' ), 403 );
		}

		// Honeypot — bots fill hidden fields humans never see (Basecamp #9936829133).
		// Swallow silently with a success redirect so scrapers can't probe the failure shape.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- honeypot: only its emptiness is tested, the value is never stored or echoed.
		if ( '' !== trim( (string) wp_unslash( $_POST['lrn_hp'] ?? '' ) ) ) {
			wp_safe_redirect( add_query_arg( 'submitted', '1', $form_url ) );
			exit;
		}

		// Permission gate via Permission_Engine — `apply-instructor` is mapped
		// to the `read` cap, so any logged-in user qualifies, but the gate
		// is now owned by the engine instead of a direct current_user_can()
		// (Basecamp #9936826095).
		$user_id = get_current_user_id();
		if ( ! \Learnomy\Permissions\Permission_Engine::can( $user_id, 'apply-instructor' ) ) {
			wp_die( esc_html__( 'You are not allowed to apply.', 'learnomy' ), 403 );
		}

		// Rate-limit + cooldown per user — block resubmissions within 60s and a
		// per-window IP cap (Basecamp #9936827687). The IP cap stops a bot that
		// signs up many users from spamming applications from a single host.
		$ip          = \Learnomy\client_ip() ?: 'unknown';
		$ip_rate_key = 'lrn_apply_ip_' . md5( $ip );
		$ip_hits     = (int) get_transient( $ip_rate_key );
		if ( $ip_hits >= 5 ) {
			wp_safe_redirect( add_query_arg( 'lrn_error', 'rate_limited', $form_url ) );
			exit;
		}
		$user_cooldown_key = 'lrn_apply_user_' . $user_id;
		if ( get_transient( $user_cooldown_key ) ) {
			// `submitted=1` here was a lie: nothing was submitted, the cooldown
			// refused it. For a REJECTED applicant re-applying, the page then
			// redrew the same "Application Not Approved" card with no message
			// at all, so the attempt vanished with no success, no error and no
			// wait notice (Basecamp 10190101644). The applicant's only signal
			// that anything happened was the page flicker.
			wp_safe_redirect( add_query_arg( 'lrn_error', 'cooldown', $form_url ) );
			exit;
		}

		// Block duplicates while a pending application is outstanding.
		$existing = Models\InstructorApplication::find_latest_for_user( $user_id );
		if ( $existing && 'pending' === $existing->status ) {
			wp_safe_redirect( add_query_arg( 'submitted', '1', $form_url ) );
			exit;
		}

		// Length cap BEFORE sanitize so we never sink CPU into stripping a
		// megabyte of payload only to reject it afterwards (Basecamp #9936827687).
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- deliberately unsanitized HERE: the length cap below must measure the raw payload, and each field is sanitized after it passes (see the comment above).
		$bio_raw       = (string) wp_unslash( $_POST['bio'] ?? '' );
		$expertise_raw = (string) wp_unslash( $_POST['expertise'] ?? '' );
		$exp_raw       = (string) wp_unslash( $_POST['teaching_experience'] ?? '' );
		$idea_raw      = (string) wp_unslash( $_POST['course_idea'] ?? '' );
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( strlen( $bio_raw ) > 5000 || strlen( $expertise_raw ) > 200 || strlen( $exp_raw ) > 5000 || strlen( $idea_raw ) > 5000 ) {
			wp_safe_redirect( add_query_arg( 'lrn_error', 'too_long', $form_url ) );
			exit;
		}

		$bio                 = sanitize_textarea_field( $bio_raw );
		$expertise           = sanitize_text_field( $expertise_raw );
		$teaching_experience = sanitize_textarea_field( $exp_raw );
		$course_idea         = sanitize_textarea_field( $idea_raw );

		if ( '' === trim( $bio ) || '' === trim( $expertise ) || '' === trim( $course_idea ) ) {
			wp_safe_redirect( add_query_arg( 'lrn_error', 'missing_fields', $form_url ) );
			exit;
		}

		// Set cooldown + IP counter only AFTER all gates pass so a failed
		// submission doesn't lock the user out.
		set_transient( $user_cooldown_key, 1, 60 );
		set_transient( $ip_rate_key, $ip_hits + 1, 5 * MINUTE_IN_SECONDS );

		$application_id = Models\InstructorApplication::submit(
			$user_id,
			$bio,
			$expertise,
			$teaching_experience,
			$course_idea
		);

		if ( ! $application_id ) {
			wp_safe_redirect( add_query_arg( 'lrn_error', 'save_failed', $form_url ) );
			exit;
		}

		/**
		 * Fires when a student submits an instructor application. Notifier
		 * listens and emails the site admin.
		 *
		 * @param int $application_id New row ID.
		 * @param int $user_id        Applying user.
		 */
		do_action( 'learnomy_instructor_application_submitted', (int) $application_id, $user_id );

		wp_safe_redirect( add_query_arg( 'submitted', '1', $form_url ) );
		exit;
	}

	/**
	 * Serve the Service Worker JavaScript file with correct headers.
	 *
	 * The `Service-Worker-Allowed: /` header lets browsers accept a SW
	 * registered from `/learnomy-sw.js` with a scope of `/`, even though
	 * the actual file lives inside the plugin directory.
	 */
	/**
	 * Stop WordPress canonical-redirecting `/learnomy-sw.js` to a trailing-slash
	 * URL. Service Worker scripts must be served with a direct 200 — a redirect
	 * makes the browser reject the registration/update.
	 *
	 * @param string|false $redirect_url  The canonical URL WP wants to send.
	 * @param string       $requested_url The originally requested URL.
	 * @return string|false Original target, or false to cancel the redirect.
	 */
	public function skip_canonical_for_service_worker( $redirect_url, $requested_url ) {
		$req = (string) $requested_url;
		if ( false !== strpos( $req, '/learnomy-sw.js' ) || false !== strpos( $req, '/learnomy-manifest.json' ) ) {
			return false;
		}
		return $redirect_url;
	}

	/**
	 * Serve the PWA manifest dynamically as same-origin JSON.
	 *
	 * Replaces the static assets/manifest.json, which (a) referenced
	 * assets/icons/icon-{192,512}.png that were never committed (a manifest
	 * icon 404 on every page load), (b) set start_url to /my-courses/ which
	 * 301-redirects to /account/courses/ (a PWA start_url behind a redirect is
	 * the SW-behind-301 bug class), and (c) hardcoded /wp-content/plugins/...
	 * paths that break on custom content-dir installs. Building it here uses
	 * plugins_url for the icon, the canonical route for start_url, and exposes
	 * the app name + icon to filters so white-label can rebrand.
	 */
	private function serve_pwa_manifest(): void {
		$icon = LEARNOMY_URL . 'assets/images/learnomy-mark.svg';

		$manifest = array(
			'name'             => (string) apply_filters( 'learnomy_pwa_app_name', get_bloginfo( 'name' ) ),
			'short_name'       => (string) apply_filters( 'learnomy_pwa_app_short_name', 'Learnomy' ),
			'description'      => (string) get_bloginfo( 'description' ),
			'start_url'        => route_url( 'account-courses' ),
			'scope'            => home_url( '/' ),
			'display'          => 'standalone',
			'background_color' => '#ffffff',
			'theme_color'      => (string) apply_filters( 'learnomy_pwa_theme_color', '#2383e2' ),
			'orientation'      => 'any',
			'categories'       => array( 'education' ),
			'icons'            => array(
				array(
					'src'     => apply_filters( 'learnomy_pwa_icon', $icon ),
					'sizes'   => 'any',
					'type'    => 'image/svg+xml',
					'purpose' => 'any maskable',
				),
			),
		);

		/**
		 * Filter the whole PWA manifest before it is served.
		 *
		 * @param array $manifest The manifest array.
		 */
		$manifest = (array) apply_filters( 'learnomy_pwa_manifest', $manifest );

		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600' );
		header( 'X-Robots-Tag: noindex, nofollow' );

		echo wp_json_encode( $manifest );
		exit;
	}

	private function serve_service_worker(): void {
		$sw_path = LEARNOMY_DIR . 'assets/js/sw.js';

		if ( ! file_exists( $sw_path ) ) {
			status_header( 404 );
			exit;
		}

		// Prevent caching the SW itself — the browser handles update checks.
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Service-Worker-Allowed: /' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		header( 'X-Robots-Tag: noindex, nofollow' );

		readfile( $sw_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}
}
