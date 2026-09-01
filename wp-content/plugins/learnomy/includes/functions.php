<?php
/**
 * Global helper functions.
 *
 * @package Learnomy
 */

namespace Learnomy;

defined( 'ABSPATH' ) || exit;

function table( string $name ): string {
	global $wpdb;
	return $wpdb->prefix . 'lrn_' . $name;
}

/**
 * URL to a shipped Learnomy logo asset.
 *
 * The brand SVGs live in `assets/images/` (they ship in the plugin zip — the
 * `docs/marketing/brand/` masters are dist-excluded). One builder so every
 * surface (admin chrome, setup wizard, Pro white-label default) points at the
 * same files instead of hardcoding paths.
 *
 * @param string $variant One of: mark, icon, horizontal, horizontal-dark.
 * @return string Absolute URL to the SVG.
 */
function logo_url( string $variant = 'mark' ): string {
	$allowed = array( 'mark', 'icon', 'horizontal', 'horizontal-dark' );
	if ( ! in_array( $variant, $allowed, true ) ) {
		$variant = 'mark';
	}
	return LEARNOMY_URL . 'assets/images/learnomy-' . $variant . '.svg';
}

/**
 * Base64 data URI for the admin-menu icon (the single-colour glyph).
 *
 * WordPress recolours `data:image/svg+xml;base64,…` menu icons to match the
 * active admin colour scheme, so the menu glyph is a one-fill silhouette
 * (`assets/images/learnomy-icon.svg`), not the gradient tile. Returns the
 * dashicon fallback if the file is unreadable.
 *
 * @return string A `data:image/svg+xml;base64,…` URI, or a dashicon slug fallback.
 */
function logo_menu_icon(): string {
	$path = LEARNOMY_DIR . 'assets/images/learnomy-icon.svg';
	if ( ! is_readable( $path ) ) {
		return 'dashicons-welcome-learn-more';
	}
	$svg = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local bundled asset, not remote.
	if ( false === $svg ) {
		return 'dashicons-welcome-learn-more';
	}
	return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- data URI, not obfuscation.
}

/**
 * Best-effort client IP that honours common reverse-proxy headers.
 *
 * Cloudflare, AWS ALB, Nginx, Fastly, etc. rewrite `REMOTE_ADDR` to the
 * proxy's own IP, collapsing every visitor into the same bucket. That breaks
 * per-IP rate limiting and per-IP audit logging. Probes a small ordered
 * list of well-known proxy headers, returns the first non-empty value, and
 * falls back to `REMOTE_ADDR`.
 *
 * `HTTP_X_FORWARDED_FOR` is comma-separated — keep the left-most entry (the
 * original client).
 *
 * Sites NOT behind a proxy see only `REMOTE_ADDR` (the headers aren't sent).
 * Sites behind a proxy must strip client-sent values for these headers at
 * the edge or an attacker can spoof their IP for rate-limit / audit
 * purposes.
 *
 * @return string Sanitized IP, or empty string when nothing usable was found.
 */
function client_ip(): string {
	$candidates = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' );

	foreach ( $candidates as $header ) {
		if ( empty( $_SERVER[ $header ] ) ) {
			continue;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$raw   = (string) wp_unslash( $_SERVER[ $header ] );
		$first = trim( strtok( $raw, ',' ) );
		if ( '' === $first ) {
			continue;
		}
		$clean = sanitize_text_field( $first );
		if ( '' !== $clean ) {
			return $clean;
		}
	}

	return '';
}

/*
 * ─── Permission SDK — Free + Pro consume permissions through these
 *     helpers, not by reaching into Permission_Engine directly. Same
 *     pattern as Jetonomy: one canonical surface, Free owns it, Pro
 *     calls the same function.
 *
 *   • can( $user_id, 'admin' )                      — site administrator
 *   • can( $user_id, 'access_course', $course_id )  — context-aware course gate
 *   • can( $user_id, 'manage_course', $course_id )  — instructor / admin
 *
 * Pass an unknown action and the engine falls back to looking up
 * `learnomy_<action>` as a WP capability, then applies the
 * `learnomy_permission_check` filter so subsystems can hook their own
 * rules without depending on this file.
 */
function can( int $user_id, string $action, ?int $context_id = null ): bool {
	if ( ! class_exists( '\\Learnomy\\Permissions\\Permission_Engine' ) ) {
		return false;
	}
	return \Learnomy\Permissions\Permission_Engine::can( $user_id, $action, $context_id );
}

/**
 * Whether a user may read a single course's record.
 *
 * THE canonical course-read gate. Two rules, and every door that serves a course
 * by id must apply both:
 *
 *   1. A course that is not published is visible only to its owner or an admin.
 *   2. A course whose visibility is `private` is visible only to its owner, an
 *      admin, or a student enrolled in it. (`unlisted` stays openable by direct
 *      link — only `private` gates.)
 *
 * This lives here, in the permission SDK, because the rules were previously
 * inlined in ONE door (the REST controller, audit 2026-05-30) while a second
 * door — the `learnomy/get-course` ability, same data, same `Course::find()` —
 * kept serving drafts and private courses to anonymous callers. Two doors, one
 * set of rules: any new reader calls this instead of re-deriving them.
 *
 * @param object $course  A course row (needs at minimum ->id and ->status).
 * @param int    $user_id Viewer; 0 for anonymous.
 */
function can_view_course( object $course, int $user_id ): bool {
	$course_id = (int) ( $course->id ?? 0 );
	if ( ! $course_id ) {
		return false;
	}

	// Site-wide private-academy gate. Putting the check in THIS gate — the single
	// door every course read passes through — is what makes the toggle enforce on
	// every surface (REST /courses, the show_in_rest abilities, the catalog
	// blocks/shortcodes), not just the front-of-site route renderer that was its
	// only reader before. Anyone the gate admits falls through to the normal
	// per-course rules below.
	if ( content_blocked_for( $user_id ) ) {
		return false;
	}

	$manages = $user_id > 0 && can( $user_id, 'manage_course', $course_id );

	// Rule 1 — unpublished is owner/admin only.
	if ( 'published' !== ( $course->status ?? '' ) ) {
		return $manages;
	}

	// Rule 2 — private is owner/admin, or an enrolled student.
	$settings = Models\Course::get_all_settings( $course_id );
	if ( 'private' === Models\Course::visibility( $settings ) ) {
		return $manages
			|| ( $user_id > 0 && Models\Enrollment::is_enrolled( $user_id, $course_id ) );
	}

	return true;
}

/*
 * ─── Front-of-site URL system — THE standard (read this first) ──────────────
 *
 * Every front-of-site URL in Learnomy AND Learnomy Pro comes from ONE builder:
 *
 *     route_url( $name, $args )
 *
 * It resolves the URL from the Routes registry (includes/class-routes.php —
 * plan/2026-05-29-route-registry.md), the single declarative source that also
 * generates the rewrite rules. So a slug change moves the route AND every link
 * together; they can't drift. One rule, no exceptions:
 *
 *   • Build a link only with route_url( $name, $args ) — e.g.
 *     route_url('course', ['slug'=>$s]), route_url('catalog', ['paged'=>2]).
 *     There are NO per-route *_url() wrappers (deleted — pre-1.0, no dead weight).
 *   • Never hardcode home_url('/courses/…'); never concatenate a *_slug().
 *   • Call style: unqualified inside `namespace Learnomy;` (this file, the
 *     Router); fully-qualified \Learnomy\route_url() everywhere else — services,
 *     SEO, search, widgets, templates, blocks, AND all of Pro.
 *   • Pro adds its routes through the `learnomy_routes` table filter and reuses
 *     route_url() — it extends Free's registry, never duplicates routing.
 *
 * The *_slug() getters (base_slug, lesson_slug, …) are @internal — the slug
 * source the registry reads to build patterns + URLs. Nothing outside this file
 * + the Routes registry may call them.
 *
 * Enforced by Free bin/coding-rules-check.sh Rule 5 and Pro
 * bin/architecture-checks.sh U2.
 * ────────────────────────────────────────────────────────────────────────────
 */

/**
 * Resolve a single Learnomy entity URL slug.
 *
 * The slug for every Learnomy front-of-site entity is configurable so a site
 * owner can localize the whole URL (e.g. German `/kurse/x/lektion/y/`). The
 * resolution order for each slug is:
 *
 *   1. The `learnomy_settings[<option_key>]` value, if the admin set one.
 *   2. The hardcoded English default (so existing sites are unchanged).
 *   3. The matching `learnomy_<name>_slug` filter, applied LAST so code can
 *      always override the stored value (white-label / multilingual plugins).
 *
 * Every rewrite pattern in the Router AND every URL builder for that entity
 * MUST resolve its segment through one of these helpers — never a hardcoded
 * literal — so the registered route and every link that points at it move
 * together with no drift.
 *
 * @param string $filter      Filter name, e.g. `learnomy_lesson_slug`.
 * @param string $option_key  Key inside `learnomy_settings`, e.g. `lesson_slug`.
 * @param string $default     English fallback, e.g. `lesson`.
 * @return string             A `sanitize_title`'d slug, never empty.
 */
function entity_slug( string $filter, string $option_key, string $default ): string {
	$settings = get_option( 'learnomy_settings', array() );
	$value    = ( is_array( $settings ) && ! empty( $settings[ $option_key ] ) )
		? (string) $settings[ $option_key ]
		: $default;

	/**
	 * Filters a Learnomy entity URL slug. Applied after the stored setting so
	 * code overrides win. Receives the resolved value; return a new slug to
	 * change both the rewrite rule and every link for that entity.
	 *
	 * @param string $value The resolved slug (stored setting or default).
	 */
	// $filter is always a `learnomy_*` name built by the caller (see docblock);
	// the prefix can't be seen statically because the name is dynamic.
	$value = (string) apply_filters( $filter, $value ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- caller-supplied learnomy_* hook name.

	/**
	 * Uniform slug filter — THE single seam the route registry promised, so a
	 * new reslug-able route never needs its own bespoke `learnomy_<x>_slug`
	 * filter. Every slug funnels through entity_slug(), so applying it here
	 * covers all of them at once; a per-route filter still runs first, so a
	 * site already overriding a specific slug is unaffected. `$option_key`
	 * (e.g. `lesson_slug`) identifies which slug is being resolved. This is the
	 * end of the per-route-filter drift the registry was built to stop.
	 *
	 * @param string $value      The resolved slug (after the per-route filter).
	 * @param string $option_key The slug's settings key / route identity.
	 */
	$value = (string) apply_filters( 'learnomy_route_slug', $value, $option_key );

	$slug = sanitize_title( $value );

	return '' !== $slug ? $slug : sanitize_title( $default );
}

/*
 * @internal Content-segment slug getters. The single slug source for the
 * Router + the *_url() helpers — never call these to build a link (use the
 * *_url() helpers; see the URL system doc block above).
 */
function base_slug(): string {
	return entity_slug( 'learnomy_base_slug', 'base_slug', 'courses' );
}

/**
 * Route segments a user-chosen slug (username, course/space slug) must not
 * claim, because they shadow plugin URLs like /instructor/{username}/,
 * /courses/{slug}/, /spaces/{slug}/ (Basecamp #9937134001). A username
 * "create-course" would otherwise make that instructor's profile unreachable
 * behind the Pro builder route, etc.
 *
 * Filterable so white-label / Pro can extend it (e.g. Pro adds its own
 * frontend-builder + spaces child segments).
 *
 * @return string[] Lower-case reserved segments.
 */
function reserved_slugs(): array {
	return array_map(
		'strtolower',
		(array) apply_filters(
			'learnomy_reserved_slugs',
			array(
				// WP pagination segment.
				'page',
				// /instructor/ children (Pro frontend builder).
				'create-course',
				'edit-course',
				// /courses/ children.
				'category',
				'lesson',
				'quiz',
				// /spaces/ children (Pro).
				'plans',
				'buy',
				'manage',
				// /enroll/ + /account/ children.
				'thank-you',
				'cancelled',
				'billing',
			)
		)
	);
}

/**
 * Whether a slug/username collides with a reserved route segment.
 *
 * @param string $slug Candidate slug (already sanitised by the caller).
 */
function is_reserved_slug( string $slug ): bool {
	return in_array( strtolower( trim( $slug ) ), reserved_slugs(), true );
}

function lesson_slug(): string {
	return entity_slug( 'learnomy_lesson_slug', 'lesson_slug', 'lesson' );
}

function quiz_slug(): string {
	return entity_slug( 'learnomy_quiz_slug', 'quiz_slug', 'quiz' );
}

function category_slug(): string {
	return entity_slug( 'learnomy_category_slug', 'category_slug', 'category' );
}

function instructor_slug(): string {
	return entity_slug( 'learnomy_instructor_slug', 'instructor_slug', 'instructor' );
}

function verify_slug(): string {
	return entity_slug( 'learnomy_verify_slug', 'verify_slug', 'verify' );
}

function standalone_quiz_slug(): string {
	return entity_slug( 'learnomy_standalone_quiz_slug', 'standalone_quiz_slug', 'quiz' );
}

/*
 * @internal Primary front-of-site page slugs. Settings-backed (Settings ->
 * Pages -> URL Slugs) AND filterable, exactly like the content slugs above.
 * Internal like the content getters — call the *_url() helpers, not these. The Router and
 * the URL builders both resolve through these getters, so a slug change moves
 * the link and the route together. Sub-routes (manage / billing / thank-you /
 * cancelled) derive from their parent below, so renaming the parent renames
 * the whole branch.
 */
function membership_slug(): string {
	return entity_slug( 'learnomy_membership_slug', 'membership_slug', 'membership' );
}

/**
 * Slug for the Spaces owner-and-member dashboard. Configurable via
 * Settings > Pages so customers can match their own URL conventions.
 * Owned by Free even though the Spaces feature lives in Pro — Pro
 * deactivation must not orphan the slug in an inaccessible settings
 * page. Pro reads this via the `learnomy_my_spaces_slug` filter exactly
 * like every other slug.
 */
function my_spaces_slug(): string {
	return entity_slug( 'learnomy_my_spaces_slug', 'my_spaces_slug', 'my-spaces' );
}

function enroll_slug(): string {
	return entity_slug( 'learnomy_enroll_slug', 'enroll_slug', 'enroll' );
}

function account_slug(): string {
	return entity_slug( 'learnomy_account_slug', 'account_slug', 'account' );
}

function billing_slug(): string {
	return trim( (string) apply_filters( 'learnomy_billing_slug', account_slug() . '/billing' ), '/' );
}

function thank_you_slug(): string {
	return trim( (string) apply_filters( 'learnomy_thank_you_slug', enroll_slug() . '/thank-you' ), '/' );
}

function cancelled_slug(): string {
	return trim( (string) apply_filters( 'learnomy_cancelled_slug', enroll_slug() . '/cancelled' ), '/' );
}

/**
 * White-label slug for the instructor-application page. Default 'become-instructor'.
 */
function become_instructor_slug(): string {
	return trim( (string) apply_filters( 'learnomy_become_instructor_slug', 'become-instructor' ), '/' );
}

/**
 * Solo academy mode: the site owner is the only instructor.
 *
 * A single master switch (Settings → General → "Academy mode", default OFF)
 * for sites with no instructor marketplace. When ON it (1) turns public
 * instructor registration off, (2) forces the `commissions` module off so the
 * whole earnings/commission/withdrawal surface — which is already module-gated
 * — disappears, and (3) suppresses co-instructor assignment. Devs can force it
 * per-site with the `learnomy_solo_mode` filter (Basecamp #10054524045).
 */
function solo_mode(): bool {
	$settings = get_option( 'learnomy_settings', array() );
	$on       = is_array( $settings ) && ! empty( $settings['solo_mode'] );

	return (bool) apply_filters( 'learnomy_solo_mode', $on );
}

/**
 * Whether visitors may apply to become instructors. Off in solo mode; when off
 * the `become-instructor` + `register-instructor` routes 404 (gated in
 * Router::gate_module_routes) and every entry point to them is hidden.
 * Override with the `learnomy_instructor_registration_enabled` filter.
 */
function instructor_registration_enabled(): bool {
	return (bool) apply_filters( 'learnomy_instructor_registration_enabled', ! solo_mode() );
}

/**
 * Whether co-instructors + revenue-share are offered. Requires the
 * `multi_instructor` setting AND is force-off in solo mode (a solo academy has
 * no second instructor to split with). Filter: `learnomy_multi_instructor_enabled`.
 */
function multi_instructor_enabled(): bool {
	$settings = get_option( 'learnomy_settings', array() );
	$on       = is_array( $settings ) && ! empty( $settings['multi_instructor'] ) && ! solo_mode();

	return (bool) apply_filters( 'learnomy_multi_instructor_enabled', $on );
}

/**
 * Whether logged-out visitors are blocked from every course-content route
 * (catalog, category, single course, lesson, quiz). For a fully private,
 * members-only academy where membership and payment are handled off-site
 * (invoice, bank transfer, an external CRM). Off by default. The
 * `learnomy_protected_routes` filter is wired to consume this in
 * `Learnomy::register_hooks()`, so turning it on redirects those routes to
 * the login page. Force per-site with the `learnomy_require_login_for_content`
 * filter (Zoho #40814).
 */
function require_login_for_content(): bool {
	$settings = get_option( 'learnomy_settings', array() );
	$on       = is_array( $settings ) && ! empty( $settings['require_login_all_content'] );

	return (bool) apply_filters( 'learnomy_require_login_for_content', $on );
}

/**
 * Whether this viewer is allowed past the private-academy gate.
 *
 * The second half of the gate. `require_login_for_content()` answers "is the
 * academy private right now"; this answers "may THIS person in", and being
 * logged in is only the default answer.
 *
 * It exists because "logged in" is not what every host means by allowed. A
 * community running Learnomy inside it may hold a member who has not verified
 * their email, or admit only paying members — decisions Learnomy cannot see and
 * should not try to model. Splitting the gate in two lets the host answer the
 * half it owns while Learnomy keeps owning the toggle. Standalone Learnomy is
 * unaffected: nobody filters it, so the answer stays is_user_logged_in().
 *
 * @param int $user_id Viewer; 0 for anonymous.
 * @return bool
 */
function content_access_allowed( int $user_id ): bool {
	/**
	 * Filter whether a viewer may read course content while the academy is private.
	 *
	 * @since 1.9.1
	 *
	 * @param bool $allowed Default: whether the viewer is logged in.
	 * @param int  $user_id Viewer; 0 for anonymous.
	 */
	return (bool) apply_filters( 'learnomy_content_can_access', $user_id > 0, $user_id );
}

/**
 * THE private-academy predicate: is this viewer blocked from course content?
 *
 * Every surface asks this one question. It was previously three: the course
 * gate, the public-listing gate, and the REST base controller each spelled out
 * "anonymous AND private" in their own words — and the third one's docblock
 * says it was added so there would not be a FOURTH copy, while the second
 * already existed. Three copies of a rule is three places to change it and two
 * places to forget, which is how a private academy stays private on some
 * surfaces and leaks on the others.
 *
 * @param int $user_id Viewer; 0 for anonymous.
 * @return bool
 */
function content_blocked_for( int $user_id ): bool {
	return require_login_for_content() && ! content_access_allowed( $user_id );
}

/**
 * Whether this viewer must be blocked from a public course listing.
 *
 * THE predicate for the private-academy gate. Every public course-listing
 * surface answers this one question — rendering surfaces then wrap the answer
 * in markup via `course_listing_gate_notice()`, data surfaces (REST, the
 * `list-courses` / `search-courses` abilities) just return an empty result.
 *
 * It exists as its own function because the gate previously lived inline in the
 * blocks, so surfaces added later (the abilities, the shortcodes) re-derived the
 * rule or skipped it — the per-surface drift that made the toggle honest on two
 * surfaces and silently leaky on four (Basecamp 10086765981).
 *
 * A logged-in member is never blocked; only anonymous viewers are.
 */
function course_listing_blocked(): bool {
	return content_blocked_for( get_current_user_id() );
}

/**
 * The notice a public course-listing surface shows instead of the list when the
 * private-academy gate is on and the viewer is anonymous — or null when it
 * should render normally.
 *
 * The catalog block, the grid block, both catalogue shortcodes, and (via
 * `require_login_for_content()`) the course sitemap all read the same gate, so
 * "require login for all content" cannot be enforced on one public course
 * surface and silently leak on another.
 *
 * @return string|null Inner markup for the surface wrapper, or null to render normally.
 */
function course_listing_gate_notice(): ?string {
	if ( ! course_listing_blocked() ) {
		return null;
	}

	$login_url = wp_login_url( get_permalink() ?: home_url( '/' ) );

	return '<p class="lrn-empty-state">' . sprintf(
		/* translators: %s: login URL */
		wp_kses( __( 'Please <a href="%s">log in</a> to view courses.', 'learnomy' ), array( 'a' => array( 'href' => array() ) ) ),
		esc_url( $login_url )
	) . '</p>';
}

/**
 * The course-content route names gated by require_login_for_content(). The
 * catalog archive is intentionally in the set so a private site's course
 * listing is not browsable while logged out. Filterable so a Pro content
 * route can join the same gate.
 *
 * @return string[]
 */
function content_route_names(): array {
	return (array) apply_filters(
		'learnomy_content_route_names',
		array( 'catalog', 'category', 'course', 'lesson', 'quiz', 'standalone-quiz' )
	);
}

/**
 * White-label slug for the student registration page. Default 'register/student'
 * (a two-segment literal; the helper returns it whole so a rename moves the
 * rewrite rule and every link together).
 */
function register_student_slug(): string {
	return trim( (string) apply_filters( 'learnomy_register_student_slug', 'register/student' ), '/' );
}

/**
 * White-label slug for the instructor registration page. Default 'register/instructor'.
 */
function register_instructor_slug(): string {
	return trim( (string) apply_filters( 'learnomy_register_instructor_slug', 'register/instructor' ), '/' );
}

/**
 * White-label slug for the offline/PWA shell route. Default 'learnomy-offline'.
 */
function offline_slug(): string {
	return trim( (string) apply_filters( 'learnomy_offline_slug', 'learnomy-offline' ), '/' );
}

/**
 * Whether the Learnomy PWA (service worker + offline banner + manifest) is
 * active. Reads learnomy_settings['enable_pwa'] (default true when the key is
 * absent) and lets code override via the `learnomy_enable_pwa` filter. When
 * false, the service-worker registration script and the /learnomy-sw.js,
 * manifest and offline routes are not loaded — for sites where Learnomy is one
 * section of a larger app (e.g. a community) and a site-wide service worker and
 * offline banner are unwanted.
 *
 * @return bool
 */
function pwa_enabled(): bool {
	// The Modules page has a `pwa` toggle. Until 1.7.1 nothing read it: the
	// only real switch was learnomy_settings['enable_pwa'], which DEFAULTS TO
	// TRUE. So an owner could switch PWA off in Modules, see it render as off,
	// and still have the service worker and manifest served on every request —
	// the control was decorative, and "off" was a lie the UI told.
	//
	// The module toggle is the owner-facing switch, so it wins: off in Modules
	// means off. The setting stays as the finer-grained control for sites that
	// leave the module on (and for the `learnomy_enable_pwa` filter, which
	// still has the final say for programmatic overrides).
	if ( ! module_enabled( 'pwa' ) ) {
		return (bool) apply_filters( 'learnomy_enable_pwa', false );
	}

	$settings = get_option( 'learnomy_settings', array() );
	$enabled  = ! is_array( $settings ) || ! array_key_exists( 'enable_pwa', $settings ) || ! empty( $settings['enable_pwa'] );
	return (bool) apply_filters( 'learnomy_enable_pwa', $enabled );
}

/**
 * Ordered map of every reslug-able key in `learnomy_settings` to its filter +
 * default. Used by the admin sanitizer to detect a slug change (so it can
 * schedule a rewrite flush) and by the Settings UI to render one field per
 * slug. Keeping the list here means there is exactly one place to edit when a
 * new reslug-able entity is added.
 *
 * @return array<string, array{filter:string, default:string, label:string}>
 */
function reslug_keys(): array {
	return array(
		'base_slug'            => array(
			'filter'  => 'learnomy_base_slug',
			'default' => 'courses',
			'label'   => __( 'Course URL Base', 'learnomy' ),
		),
		'lesson_slug'          => array(
			'filter'  => 'learnomy_lesson_slug',
			'default' => 'lesson',
			'label'   => __( 'Lesson Segment', 'learnomy' ),
		),
		'quiz_slug'            => array(
			'filter'  => 'learnomy_quiz_slug',
			'default' => 'quiz',
			'label'   => __( 'Course Quiz Segment', 'learnomy' ),
		),
		'category_slug'        => array(
			'filter'  => 'learnomy_category_slug',
			'default' => 'category',
			'label'   => __( 'Category Segment', 'learnomy' ),
		),
		'instructor_slug'      => array(
			'filter'  => 'learnomy_instructor_slug',
			'default' => 'instructor',
			'label'   => __( 'Instructor Base', 'learnomy' ),
		),
		'verify_slug'          => array(
			'filter'  => 'learnomy_verify_slug',
			'default' => 'verify',
			'label'   => __( 'Certificate Verify Base', 'learnomy' ),
		),
		'standalone_quiz_slug' => array(
			'filter'  => 'learnomy_standalone_quiz_slug',
			'default' => 'quiz',
			'label'   => __( 'Standalone Quiz Base', 'learnomy' ),
		),
		'membership_slug'      => array(
			'filter'  => 'learnomy_membership_slug',
			'default' => 'membership',
			'label'   => __( 'Membership URL Base', 'learnomy' ),
		),
		'my_spaces_slug'       => array(
			'filter'  => 'learnomy_my_spaces_slug',
			'default' => 'my-spaces',
			'label'   => __( 'My Learning Spaces URL', 'learnomy' ),
		),
		'enroll_slug'          => array(
			'filter'  => 'learnomy_enroll_slug',
			'default' => 'enroll',
			'label'   => __( 'Enroll URL Base', 'learnomy' ),
		),
		'account_slug'         => array(
			'filter'  => 'learnomy_account_slug',
			'default' => 'account',
			'label'   => __( 'Account URL Base', 'learnomy' ),
		),
	);
}

/*
 * ─── Layout system — registry-driven, plugin-wide layouts ───────────────────
 *
 * Every front-of-site "surface" renders through a registered VARIANT. Each
 * variant declares its CHROME — 'distraction-free' (no theme header/footer) or
 * 'theme' (renders inside the active theme) — so the old hardcoded
 * distraction-free route list is derived from the chosen variants instead.
 *
 * This generalizes the per-quiz `display_template` dispatcher into one
 * mechanism for all surfaces. The quiz's own display_template (focused /
 * classic / …) is the INTERACTION axis and is unrelated — the layout system
 * governs only the quiz CHROME.
 *
 * Resolution per surface: learnomy_settings[<setting_key>] (if a valid variant)
 * → the surface default → apply_filters('learnomy_layout_<surface>', $variant).
 * Unknown values fall back to the default; never fatal.
 *
 * Pro / third-party register variants via the `learnomy_layouts` filter — they
 * never fork a surface. Contract: plan/2026-05-22-layout-system/PLAN.yaml.
 * ────────────────────────────────────────────────────────────────────────────
 */

/**
 * The layout registry: surface => { setting_key, default, variants{} }.
 * Each variant: { chrome: 'theme'|'distraction-free', file: ?string, pro: bool, label }.
 *
 * @return array<string, array{setting_key:string, default:string, variants:array<string, array{chrome:string, file:?string, pro:bool, label:string}>}>
 */
function layouts(): array {
	$surfaces = array(
		'archive'         => array(
			'setting_key' => 'catalog_layout',
			'default'     => 'grid',
			'variants'    => array(
				'grid'    => array(
					'chrome' => 'theme',
					'file'   => 'catalog/grid.php',
					'pro'    => false,
					'label'  => __( 'Grid (default)', 'learnomy' ),
				),
				'sidebar' => array(
					'chrome' => 'theme',
					'file'   => 'catalog/sidebar.php',
					'pro'    => false,
					'label'  => __( 'Filter sidebar', 'learnomy' ),
				),
			),
		),
		'single-course'   => array(
			'setting_key' => 'course_layout',
			'default'     => 'classic',
			'variants'    => array(
				'classic' => array(
					'chrome' => 'theme',
					'file'   => 'course/classic.php',
					'pro'    => false,
					'label'  => __( 'Classic: hero + sticky sidebar (default)', 'learnomy' ),
				),
				'hero'    => array(
					'chrome' => 'theme',
					'file'   => 'course/hero.php',
					'pro'    => false,
					'label'  => __( 'Hero / landing', 'learnomy' ),
				),
				'focused' => array(
					'chrome' => 'theme',
					'file'   => 'course/focused.php',
					'pro'    => false,
					'label'  => __( 'Focused (content-first)', 'learnomy' ),
				),
				'split'   => array(
					'chrome' => 'theme',
					'file'   => 'course/split.php',
					'pro'    => false,
					'label'  => __( 'Split / magazine', 'learnomy' ),
				),
			),
		),
		'lesson'          => array(
			'setting_key' => 'lesson_layout',
			'default'     => 'focus',
			'variants'    => array(
				'focus'    => array(
					'chrome' => 'distraction-free',
					'file'   => 'lesson/focus.php',
					'pro'    => false,
					'label'  => __( 'Focus (immersive, default)', 'learnomy' ),
				),
				'embedded' => array(
					'chrome' => 'theme',
					'file'   => 'lesson/embedded.php',
					'pro'    => false,
					'label'  => __( 'Embedded in theme', 'learnomy' ),
				),
			),
		),
		// Chrome-only surfaces: they keep single-quiz.php + its per-quiz
		// display_template; the layout setting only toggles theme chrome.
		// Defaults match today exactly — a course quiz renders inside the
		// theme, a standalone quiz renders distraction-free.
		'quiz'            => array(
			'setting_key' => 'quiz_chrome',
			'default'     => 'embedded',
			'variants'    => array(
				'embedded'  => array(
					'chrome' => 'theme',
					'file'   => null,
					'pro'    => false,
					'label'  => __( 'Embedded in theme', 'learnomy' ),
				),
				'immersive' => array(
					'chrome' => 'distraction-free',
					'file'   => null,
					'pro'    => false,
					'label'  => __( 'Immersive (distraction-free)', 'learnomy' ),
				),
			),
		),
		'standalone-quiz' => array(
			'setting_key' => 'standalone_quiz_chrome',
			'default'     => 'immersive',
			'variants'    => array(
				'immersive' => array(
					'chrome' => 'distraction-free',
					'file'   => null,
					'pro'    => false,
					'label'  => __( 'Immersive (distraction-free)', 'learnomy' ),
				),
				'embedded'  => array(
					'chrome' => 'theme',
					'file'   => null,
					'pro'    => false,
					'label'  => __( 'Embedded in theme', 'learnomy' ),
				),
			),
		),
	);

	/**
	 * Filters the layout registry. Pro / third-party code adds variants to a
	 * surface (or whole new surfaces) here — never by forking a template.
	 *
	 * @param array $surfaces The layout registry.
	 */
	return (array) apply_filters( 'learnomy_layouts', $surfaces );
}

/**
 * Resolve the active variant key for a surface (setting → default → filter).
 *
 * @param string $surface Surface id, e.g. 'single-course'.
 * @return string         A variant key that exists for the surface.
 */
function layout_variant( string $surface ): string {
	$surfaces = layouts();
	if ( ! isset( $surfaces[ $surface ] ) ) {
		return '';
	}
	$config   = $surfaces[ $surface ];
	$default  = (string) $config['default'];
	$variants = (array) $config['variants'];

	$settings = get_option( 'learnomy_settings', array() );
	$stored   = is_array( $settings ) && ! empty( $settings[ $config['setting_key'] ] )
		? (string) $settings[ $config['setting_key'] ]
		: '';

	$variant = ( '' !== $stored && isset( $variants[ $stored ] ) ) ? $stored : $default;

	/** Filters the resolved layout variant for one surface. */
	$variant = (string) apply_filters( "learnomy_layout_{$surface}", $variant );

	return isset( $variants[ $variant ] ) ? $variant : $default;
}

/**
 * The template file (relative to templates/) for a surface's active variant,
 * or null when the surface is chrome-only (e.g. quiz, which keeps its own
 * display_template dispatcher).
 *
 * @param string $surface Surface id.
 * @return string|null
 */
function layout_template( string $surface ): ?string {
	$surfaces = layouts();
	$variant  = layout_variant( $surface );
	if ( '' === $variant || ! isset( $surfaces[ $surface ]['variants'][ $variant ] ) ) {
		return null;
	}
	$file = $surfaces[ $surface ]['variants'][ $variant ]['file'] ?? null;
	return is_string( $file ) ? $file : null;
}

/**
 * Absolute path to a template/partial, honouring the child-theme override the
 * plugin advertises: {theme}/learnomy/{path} wins over templates/{path}.
 *
 * Use it wherever a template pulls in another template:
 *
 *   require \Learnomy\template_path( 'course/partials/enroll-card.php' );
 *
 * NOT a render function on purpose. Partials read the including template's
 * variables ($course, $lrn_is_owner, …) straight out of the enclosing scope, so
 * the include has to stay at the call site — only the PATH is ours to resolve.
 * A render_partial($file, $vars) wrapper would silently empty that scope in
 * every one of the ~150 call sites.
 *
 * Falls back to the plugin's own copy, so a theme overriding one partial never
 * has to ship the rest. Returns '' when the file exists in neither place — a
 * `require ''` fails loudly rather than including something unintended.
 *
 * @param string $template_file Path relative to templates/, e.g. 'partials/breadcrumb.php'.
 */
function template_path( string $template_file ): string {
	$located = \Learnomy\Template_Loader::locate( $template_file );

	return null === $located ? '' : $located;
}

/**
 * The template file for a surface's DEFAULT variant (the safe fallback used
 * when a chosen variant's file hasn't been built yet — variants ship
 * incrementally, and Pro/3rd-party may register variants without files).
 *
 * @param string $surface Surface id.
 * @return string|null
 */
function layout_default_template( string $surface ): ?string {
	$surfaces = layouts();
	if ( ! isset( $surfaces[ $surface ] ) ) {
		return null;
	}
	$default = (string) $surfaces[ $surface ]['default'];
	$file    = $surfaces[ $surface ]['variants'][ $default ]['file'] ?? null;
	return is_string( $file ) ? $file : null;
}

/**
 * Whether the surface's active variant renders distraction-free (no theme
 * header/footer).
 *
 * @param string $surface Surface id.
 */
function layout_is_distraction_free( string $surface ): bool {
	$surfaces = layouts();
	$variant  = layout_variant( $surface );
	if ( '' === $variant || ! isset( $surfaces[ $surface ]['variants'][ $variant ] ) ) {
		return false;
	}
	return 'distraction-free' === ( $surfaces[ $surface ]['variants'][ $variant ]['chrome'] ?? '' );
}

/**
 * Translatable English defaults for every renamable entity noun, in both forms.
 *
 * This is the i18n layer: each value is a `__()` call against the `learnomy`
 * text domain, so a .po translation still localizes the noun for the whole
 * language. The no-code Labels feature sits ON TOP of this — `entity_label()`
 * returns a site owner's custom label when one is set, otherwise it falls back
 * to the translatable default returned here. A site with no custom labels and a
 * German .po still reads "Kurs" / "Kurse"; that path is unchanged.
 *
 * Keeping the defaults here means there is exactly one place to read the
 * canonical English noun for an entity, and the Settings UI renders one field
 * per (key, form) pair from this same map.
 *
 * @return array<string, array{singular:string, plural:string}>
 */
function entity_label_defaults(): array {
	$defaults = array(
		'course'     => array(
			'singular' => __( 'Course', 'learnomy' ),
			'plural'   => __( 'Courses', 'learnomy' ),
		),
		'lesson'     => array(
			'singular' => __( 'Lesson', 'learnomy' ),
			'plural'   => __( 'Lessons', 'learnomy' ),
		),
		'quiz'       => array(
			'singular' => __( 'Quiz', 'learnomy' ),
			'plural'   => __( 'Quizzes', 'learnomy' ),
		),
		'section'    => array(
			'singular' => __( 'Section', 'learnomy' ),
			'plural'   => __( 'Sections', 'learnomy' ),
		),
		'topic'      => array(
			'singular' => __( 'Topic', 'learnomy' ),
			'plural'   => __( 'Topics', 'learnomy' ),
		),
		'category'   => array(
			'singular' => __( 'Category', 'learnomy' ),
			'plural'   => __( 'Categories', 'learnomy' ),
		),
		// The people nouns. Every key above names a piece of CONTENT; these two
		// name the humans, and they are the words an owner is most likely to
		// disagree with. Corporate training has trainees and trainers, coaching
		// platforms have clients, associations have members and mentors - none
		// of them run an academy full of Students. Renaming Course while the
		// registration page still says "Register as a Student" white-labels half
		// the product.
		'student'    => array(
			'singular' => __( 'Student', 'learnomy' ),
			'plural'   => __( 'Students', 'learnomy' ),
		),
		'instructor' => array(
			'singular' => __( 'Instructor', 'learnomy' ),
			'plural'   => __( 'Instructors', 'learnomy' ),
		),
	);

	/**
	 * Filters the set of renamable nouns.
	 *
	 * The whole rename pipeline reads this one array — the Settings > Labels
	 * screen renders a row per entry, the settings sanitizer accepts a key only
	 * if it appears here, and entity_label() resolves against it. So registering
	 * a noun here gives an extension the complete feature with no UI work and no
	 * save-path change.
	 *
	 * That is why it is a filter rather than a constant: the array named six
	 * pieces of Free content and nothing else could reach it, which left Pro's
	 * own nouns unrenamable. Spaces alone hardcodes the word in ~128 user-facing
	 * strings, and an owner who calls them Teams has as much right to say so as
	 * one who calls a Course a Class. Per the Free/Pro contract, the seam ships
	 * in Free.
	 *
	 * Each value must be an array with 'singular' and 'plural' keys, both
	 * translatable in the registering plugin's OWN text domain.
	 *
	 * @param array<string, array{singular: string, plural: string}> $defaults Renamable nouns.
	 */
	$filtered = apply_filters( 'learnomy_entity_labels', $defaults );

	if ( ! is_array( $filtered ) ) {
		return $defaults;
	}

	// A malformed registration must not blank a label or fatal the settings
	// screen: keep only entries carrying both forms as non-empty strings, and
	// never let a filter delete a built-in noun out from under the resolver.
	$clean = array();
	foreach ( $filtered as $key => $forms ) {
		if ( ! is_string( $key ) || '' === $key || ! is_array( $forms ) ) {
			continue;
		}
		$singular = isset( $forms['singular'] ) && is_string( $forms['singular'] ) ? trim( $forms['singular'] ) : '';
		$plural   = isset( $forms['plural'] ) && is_string( $forms['plural'] ) ? trim( $forms['plural'] ) : '';
		if ( '' === $singular || '' === $plural ) {
			continue;
		}
		$clean[ $key ] = array(
			'singular' => $singular,
			'plural'   => $plural,
		);
	}

	return $clean + $defaults;
}

/**
 * Resolve the display label for a core Learnomy entity noun.
 *
 * Single source of truth for the no-code Labels feature. Resolution order:
 *
 *   1. The site owner's custom label in `learnomy_settings[labels][<key>_<form>]`,
 *      if non-empty — this is the no-code rename layer.
 *   2. The translatable English default from `entity_label_defaults()` — so a
 *      site with no custom label still gets full-language translation via .po
 *      (the i18n layer is untouched).
 *   3. The `learnomy_label_<key>_<form>` filter, applied LAST so a developer or
 *      a white-label plugin can always override the resolved value.
 *
 * The returned string is RAW (not escaped) — callers escape at the point of use
 * with `esc_html()`, exactly like every other dynamic string in the codebase.
 *
 * @param string $key  Entity key: course, lesson, quiz, section, topic, category.
 * @param string $form Either 'singular' (default) or 'plural'.
 * @return string      The resolved label, never empty.
 */
function entity_label( string $key, string $form = 'singular' ): string {
	$defaults = entity_label_defaults();
	$form     = ( 'plural' === $form ) ? 'plural' : 'singular';

	// Unknown key: fall back to the key itself so a typo never returns ''.
	$default = $defaults[ $key ][ $form ] ?? ucfirst( $key );

	$settings   = get_option( 'learnomy_settings', array() );
	$labels     = ( is_array( $settings ) && isset( $settings['labels'] ) && is_array( $settings['labels'] ) )
		? $settings['labels']
		: array();
	$option_key = $key . '_' . $form;
	$value      = ( ! empty( $labels[ $option_key ] ) )
		? (string) $labels[ $option_key ]
		: (string) $default;

	/**
	 * Filters a resolved Learnomy entity label. Applied after the stored custom
	 * label and the translatable default, so code overrides always win.
	 *
	 * @param string $value The resolved label (custom setting or i18n default).
	 */
	$value = (string) apply_filters( "learnomy_label_{$key}_{$form}", $value );

	return '' !== $value ? $value : (string) $default;
}

/**
 * Convenience wrappers over `entity_label()`. These exist only to read more
 * naturally at the call site (`course_label( true )` vs the two-arg form); they
 * all delegate to the single resolver above so there is one source of truth.
 *
 * @param bool $plural Pass true for the plural form.
 */
function course_label( bool $plural = false ): string {
	return entity_label( 'course', $plural ? 'plural' : 'singular' );
}
function lesson_label( bool $plural = false ): string {
	return entity_label( 'lesson', $plural ? 'plural' : 'singular' );
}
function quiz_label( bool $plural = false ): string {
	return entity_label( 'quiz', $plural ? 'plural' : 'singular' );
}
function section_label( bool $plural = false ): string {
	return entity_label( 'section', $plural ? 'plural' : 'singular' );
}
function topic_label( bool $plural = false ): string {
	return entity_label( 'topic', $plural ? 'plural' : 'singular' );
}
function category_label( bool $plural = false ): string {
	return entity_label( 'category', $plural ? 'plural' : 'singular' );
}
function student_label( bool $plural = false ): string {
	return entity_label( 'student', $plural ? 'plural' : 'singular' );
}
function instructor_label( bool $plural = false ): string {
	return entity_label( 'instructor', $plural ? 'plural' : 'singular' );
}

/**
 * Which layout the quiz start screen uses, site-wide.
 *
 * Three variants of ONE partial (templates/partials/quiz-start-screen.php),
 * so every state fix - attempts gate, prior-attempt panel, retake label -
 * ships to all of them at once:
 *   - 'cover'    Centered cover page (the default).
 *   - 'brief'    Two-column exam brief: context left, decision card right.
 *   - 'boarding' Stat tiles + a "before you begin" checklist.
 *
 * Owner-set in Settings; the filter lets a theme or site override per
 * request (e.g. brief for final exams only).
 *
 * @return string One of 'cover' | 'brief' | 'boarding'.
 */
function quiz_start_style(): string {
	$settings = get_option( 'learnomy_settings', array() );
	$style    = is_array( $settings ) ? (string) ( $settings['quiz_start_style'] ?? 'cover' ) : 'cover';

	/**
	 * Filter the quiz start-screen layout.
	 *
	 * @param string $style One of 'cover' | 'brief' | 'boarding'.
	 */
	$style = (string) apply_filters( 'learnomy_quiz_start_style', $style );

	return in_array( $style, array( 'cover', 'brief', 'boarding' ), true ) ? $style : 'cover';
}

/**
 * Front-of-site URL builder — the single canonical entry point.
 *
 * The route_url( $name, $args ) call resolves a URL from the Routes registry
 * (plan/2026-05-29-route-registry.md), so a slug change moves every link AND its
 * rewrite rule together. Pass param values in $args (slug, lesson_slug,
 * quiz_slug, txn, …) and 'paged' => N for an archive page, e.g.:
 *   route_url( 'course',   array( 'slug' => $course->slug ) )
 *   route_url( 'lesson',   array( 'slug' => $c, 'lesson_slug' => $l ) )
 *   route_url( 'catalog',  array( 'paged' => 2 ) )
 *
 * There are NO per-route *_url() wrappers — call route_url() everywhere, in Free
 * and Pro alike. A route's slug is filterable (learnomy_route_slug) and Pro adds
 * its own routes through the learnomy_routes table, extending Free's registry
 * rather than duplicating any routing logic.
 */
function route_url( string $name, array $args = array() ): string {
	return Routes::url( $name, $args );
}

/**
 * Where an instructor's name links to.
 *
 * ONE seam for a link that eight templates render and two resolvers used to
 * build with identical, separately-maintained code. Templates consume this
 * value; they must never rebuild it, or a site override reaches some of them
 * and not others.
 *
 * The filter exists because plenty of sites already have a better page for a
 * person than ours: a BuddyPress or BuddyNext member profile, a WordPress author
 * archive, a corporate bio. Others do not want instructors linked at all —
 * returning an empty string is supported and the templates already handle it,
 * since that is what an instructor-less course renders today.
 *
 * @param object|null $instructor Instructor user row, or null when unresolved.
 * @return string Absolute URL, or '' for no link.
 */
function instructor_profile_url( ?object $instructor ): string {
	$url = $instructor
		? route_url( 'instructor-profile', array( 'slug' => (string) $instructor->user_login ) )
		: '';

	/**
	 * Filter the instructor profile URL used across course and lesson surfaces.
	 *
	 * @param string      $url        Learnomy's own instructor-profile route, or ''.
	 * @param object|null $instructor Instructor user row, or null.
	 */
	return (string) apply_filters( 'learnomy_instructor_profile_url', $url, $instructor );
}

/**
 * The first crumb of every front-end breadcrumb trail — one helper owns it.
 *
 * Learnomy breadcrumbs never leave Learnomy's world. The theme homepage may
 * be a shop, a blog, or a landing page; it is not an ancestor of any LMS
 * screen, so `home_url()` is never a crumb (owner ruling on Basecamp
 * 10155331475 — every template used to hardcode a Home crumb and each was a
 * copy of the same wrong idea). The root is the reader's own anchor:
 *
 *   - `content` (default) → the catalog, for every reader. A course, category
 *     or pricing page is a discovery destination and its parent is the
 *     storefront, whether or not somebody happens to be signed in.
 *   - `account` → the Account hub, for the account-side surfaces where that
 *     genuinely IS the parent (instructor dashboard, instructor profile).
 *
 * It used to branch on `is_user_logged_in()` for ALL callers, so logging in
 * re-rooted every public trail at "Account" with a person icon and pointed the
 * way-back link away from browsing (Basecamp 10184419200). The split is by
 * surface now, not by session state.
 *
 * Caller rule: do NOT append your own Courses crumb after this. The content
 * root already IS the catalog for everyone; appending renders "Courses ›
 * Courses". Three callers did exactly that under the old behaviour and were
 * cleaned up with this change.
 *
 * @param string $scope 'content' (default) or 'account'.
 * @return array{label: string, href: string, icon: string}
 */
function breadcrumb_root( string $scope = 'content' ): array {
	// Being signed in does not change what a course page's parent IS.
	//
	// This used to branch on `is_user_logged_in()` alone, so every public
	// trail - course, category, catalog, certificate verification, membership
	// pricing - rooted at "Account" with a person icon the moment a visitor
	// logged in (Basecamp 10184419200). A course page is a discovery
	// destination: its parent is the catalog, and the crumb is the way back to
	// browsing. Pointing it at the dashboard sent the student away from the
	// thing they were doing, and read as mislabelled to anyone signed out of
	// the account context in their head.
	//
	// The two account-side callers pass `account` and keep the old root, which
	// is correct THERE: on the instructor dashboard, "Account" really is the
	// parent. The split is by surface now, not by session state.
	$item = ( 'account' === $scope && is_user_logged_in() )
		? array(
			'label' => __( 'Account', 'learnomy' ),
			'href'  => route_url( 'student-dashboard' ),
			'icon'  => 'user',
		)
		: array(
			'label' => course_label( true ),
			'href'  => route_url( 'catalog' ),
			'icon'  => 'home',
		);

	/**
	 * Filter the shared breadcrumb root. 10k sites, more than one mind: a
	 * site that wants its trails anchored elsewhere overrides once, here,
	 * and every breadcrumb on the site follows.
	 *
	 * @param array $item Crumb: label, href, icon (Lucide name).
	 */
	return (array) apply_filters( 'learnomy_breadcrumb_root', $item );
}

/**
 * The current archive page number, for any route flagged `paginated`.
 *
 * THE accessor. The registry's /page/N/ rewrite writes `lrn_page`
 * (see Routes::add_rules()), so anything reading `paged` or `page` instead
 * gets 1 forever and every pager link becomes a no-op that still returns
 * HTTP 200 — a broken pager that looks like a working one. That has now
 * happened twice, so the var name lives here and callers ask for the value
 * rather than naming the var themselves.
 *
 * Order: the rewrite var first, then ?lrn_page= for a direct link, then core's
 * `paged`/`page` last so a theme or core archive context still resolves.
 *
 * @return int Current page, always >= 1.
 */
function current_page(): int {
	$page = (int) get_query_var( 'lrn_page' );

	if ( $page < 1 ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination value, cast to int.
		$page = isset( $_GET['lrn_page'] ) ? absint( wp_unslash( $_GET['lrn_page'] ) ) : 0;
	}

	if ( $page < 1 ) {
		$page = (int) get_query_var( 'paged' );
	}

	if ( $page < 1 ) {
		$page = (int) get_query_var( 'page' );
	}

	return max( 1, $page );
}

/**
 * The category slug the current listing is narrowed to, or '' for none.
 *
 * THE accessor, for the same reason {@see current_page()} is: the catalog and
 * the category archive are two URLs for one listing, and every surface that
 * needs to know "which category is this page about" was free to re-derive it.
 * Only the view-data resolver ever did, so `<title>`, the canonical link, the
 * meta description and the ItemList on `/courses/?cat=design/` all described
 * the UNFILTERED catalog while the grid below them showed one category
 * (Basecamp 10134139480).
 *
 * Reads the pretty route first (`/courses/category/{slug}/` puts the slug in
 * `learnomy_slug`), then the catalog's `?cat=` query arg. Sanitized with
 * `sanitize_title()` — the same function the category route uses and the same
 * one that produced the stored slug, so a non-ASCII slug survives the round
 * trip. `sanitize_key()` would lowercase-and-strip it to nothing.
 *
 * @return string Category slug, or '' when the listing is not category-scoped.
 */
function current_category_slug(): string {
	if ( 'category' === (string) get_query_var( 'learnomy_route' ) ) {
		return sanitize_title( (string) get_query_var( 'learnomy_slug', '' ) );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only catalog filter; GET-only navigation, no nonce expected.
	$cat = isset( $_GET['cat'] ) ? sanitize_title( wp_unslash( $_GET['cat'] ) ) : '';

	return (string) $cat;
}

/**
 * Is the current request being rendered inside the Learnomy native app shell?
 *
 * The hybrid app loads content screens (catalog / course / lesson / quiz …) in
 * a WebView. App mode is detected by a token the native WebView appends to its
 * User-Agent — it is sent automatically on every request (including in-WebView
 * link navigations) and a normal browser can never match it. `?lrn_app=1` /
 * `?lrn_app=0` force the mode on/off for a SINGLE request (manual testing /
 * preview) and DO NOT persist.
 *
 * This is deliberately stateless: there is no cookie, so a website visitor can
 * never get stuck in chromeless mode and the site owner's normal theme chrome
 * (header/footer) is always served to real browsers. When true, Template_Loader
 * renders the minimal shell + the `lrn-app-mode` body class + the app stylesheet.
 */
function is_app_request(): bool {
	// Explicit per-request override (no persistence) — testing / preview only.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only render-mode signal.
	if ( isset( $_GET['lrn_app'] ) ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$flag = sanitize_text_field( wp_unslash( $_GET['lrn_app'] ) );
		return '1' === $flag || 'true' === $flag;
	}

	/**
	 * Token the native app appends to its WebView User-Agent. White-label apps
	 * filter this to their own identifier; returning '' disables UA detection.
	 *
	 * @param string $token Default 'LearnomyApp'.
	 */
	$token = (string) apply_filters( 'learnomy_app_user_agent_token', 'LearnomyApp' );
	if ( '' === $token ) {
		return false;
	}
	$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
	return '' !== $ua && false !== stripos( $ua, $token );
}

/**
 * Whether a Learnomy module is enabled — a safe wrapper around the Module
 * Kernel so callers (nav items, route gates, templates) never fatal if the
 * kernel isn't loaded. A missing kernel defaults to true so a miswire can't
 * silently hide a surface.
 *
 * @param string $slug Module slug, e.g. 'certificates'.
 */
function module_enabled( string $slug ): bool {
	if ( ! class_exists( '\\Learnomy\\Modules\\Module_Kernel' ) ) {
		return true;
	}
	return \Learnomy\Modules\Module_Kernel::instance()->is_enabled( $slug );
}

/**
 * Whether a module's MANAGEMENT surfaces should render for one person.
 *
 * True when the module is on for everybody, and also when it is off but this
 * particular user still holds something it sold them. A site owner switching
 * memberships off is saying "stop selling plans" — they are not saying "stop
 * letting the eleven people I am still charging see or cancel their charge",
 * and the two used to be the same switch.
 *
 * Use this for a member's own manage/billing surfaces. Keep plain
 * `module_enabled()` for everything the owner genuinely meant to withdraw:
 * sales pages, catalog entries, admin menus.
 *
 * @param string   $slug    Module slug.
 * @param int|null $user_id User to ask about; defaults to the current user.
 */
/**
 * Can a visitor actually BUY a membership on this site right now?
 *
 * The single answer for every "join to enroll" / "view membership plans" CTA.
 *
 * Those CTAs used to link at `route_url( 'membership-pricing' )` on the sole
 * basis that the course was members-only. That route only exists while the
 * membership module is on, so on a site with the module off — and eleven
 * published courses still marked `access_type = membership` — every one of
 * those sales pages sent its visitor to a **404**. The only route to buy the
 * course was a broken link, on the page whose whole job is selling it.
 *
 * Deliberately NOT `module_enabled_for_user()`: that variant keeps MANAGEMENT
 * surfaces alive for someone still being charged after the owner stopped
 * selling. Being able to cancel a plan you already hold is not the same as
 * being able to buy a new one, and a join CTA is the second thing.
 */
function membership_on_offer(): bool {
	/**
	 * Filter whether membership sign-up CTAs may render.
	 *
	 * @param bool $on_offer Whether the membership module is enabled.
	 */
	return (bool) apply_filters( 'learnomy_membership_on_offer', module_enabled( 'membership' ) );
}

function module_enabled_for_user( string $slug, ?int $user_id = null ): bool {
	if ( module_enabled( $slug ) ) {
		return true;
	}
	if ( ! class_exists( '\\Learnomy\\Modules\\Module_Kernel' ) ) {
		return false;
	}

	$user_id = null === $user_id ? get_current_user_id() : $user_id;

	return \Learnomy\Modules\Module_Kernel::has_live_commitment( $slug, (int) $user_id );
}

/**
 * The configured store currency symbol.
 *
 * Prefers the explicit `currency_symbol` setting; otherwise maps the ISO 4217
 * `currency` code through the `learnomy_currency_symbols` filter (the same map
 * the earnings + analytics views use); falls back to the code plus a space.
 * The single reader so every surface (route templates, blocks, emails) shows
 * the same glyph instead of a bare number.
 *
 * @return string Currency glyph, e.g. "$" or "EUR ".
 */
/**
 * Days a past-due subscription keeps access before it is expired.
 *
 * Previously hardcoded to 7 in Cron::expire_subscriptions() with no setting and no
 * mention anywhere in the UI — so the number that decides how long a customer keeps
 * a paid course after their card fails was invisible and unchangeable.
 *
 * 0 means no grace: access ends the moment the paid period does.
 */
function subscription_grace_days(): int {
	$settings = get_option( 'learnomy_settings', array() );
	$days     = isset( $settings['subscription_grace_days'] )
		? (int) $settings['subscription_grace_days']
		: 7; // Preserve the historic behaviour for every existing site.

	/**
	 * Filter the past-due grace window (days).
	 *
	 * @param int $days Grace period in days. 0 = expire immediately.
	 */
	$days = (int) apply_filters( 'learnomy_subscription_grace_days', $days );

	return max( 0, min( 90, $days ) );
}

/**
 * Format a stored UTC datetime for a `<input type="datetime-local">`.
 *
 * THE inverse of what the REST handler does on the way in (site-local -> UTC).
 * They are a matched pair: if one of them ever stops agreeing with the other, a
 * saved time silently walks by the site's UTC offset every round trip. Keeping
 * both halves in one place each is the only reason that can't happen quietly.
 *
 * @param string|null $utc Stored UTC datetime, or null/empty for an unset field.
 * @return string `Y-m-d\TH:i` in the site timezone, or '' when there is nothing to show.
 */
function datetime_local_input( ?string $utc ): string {
	if ( empty( $utc ) ) {
		return '';
	}

	try {
		$dt = new \DateTimeImmutable( $utc, new \DateTimeZone( 'UTC' ) );
		return $dt->setTimezone( wp_timezone() )->format( 'Y-m-d\TH:i' );
	} catch ( \Exception $e ) {
		return '';
	}
}

/**
 * Convert a datetime-local input value (in the site timezone) to a UTC storage
 * string — the exact inverse of datetime_local_input().
 *
 * The admin picks a wall-clock time in the academy's timezone; storage + every
 * gate is UTC (an absolute instant, identical for every student wherever they
 * are). Keeping the two conversions paired in one place is the only reason a
 * "2pm" the admin set can't quietly become "2pm UTC" — which on a non-UTC site
 * opens the quiz hours early or late.
 *
 * @param string|null $local `Y-m-d\TH:i` (or any parseable datetime) in the site
 *                           timezone. Empty/null means "no value".
 * @return string|null UTC `Y-m-d H:i:s`, or null when empty or unparseable (an
 *                     unusable schedule value clears the field rather than
 *                     storing a wrong instant).
 */
function datetime_input_to_utc( ?string $local ): ?string {
	if ( null === $local || '' === trim( $local ) ) {
		return null;
	}

	$dt = date_create( $local, wp_timezone() );
	if ( false === $dt ) {
		return null;
	}

	return $dt->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
}

/**
 * How long a live session runs, in minutes.
 *
 * Drives one visible thing: how long the Join button stays up. From the session
 * start until start + this, the student sees "Join now"; after that, "This session
 * has ended".
 *
 * THE single reader of the per-lesson value and the default, so the editor's
 * preview sentence and the lesson player can never disagree about when the button
 * disappears.
 *
 * @param object|null $lesson Lesson row. Null (or no stored value) falls back to 60.
 */
/**
 * The full live-session timing block for a `live` lesson — ONE computation
 * shared by the lesson-player template and the REST lesson read (the mobile
 * app renders countdown → Join → "ended" from this).
 *
 * State semantics (mirrors templates/lesson/partials/media.php, extracted
 * 2026-07-16 so the two surfaces cannot drift):
 *  - `pre`  — scheduled, not yet started (countdown).
 *  - `live` — between start and start+duration (Join button). Also the
 *    fallback when NO start is set, so an unscheduled live lesson stays usable.
 *  - `post` — session ended.
 *
 * The start comes from `scheduled_start_at` (UTC), NEVER `available_date` —
 * that column is the DRIP unlock and reading the start from it locked every
 * scheduled session until the second it began (fixed 3.18.0).
 *
 * @param object $lesson Lesson row (needs scheduled_start_at,
 *                       live_duration_minutes, video_url).
 * @return array{state:string,starts_at:?string,duration_minutes:int,minutes_until:int,join_url:?string}
 */
function live_session_state( object $lesson ): array {
	$scheduled_raw = (string) ( $lesson->scheduled_start_at ?? '' );
	$start         = '' !== $scheduled_raw ? (int) strtotime( $scheduled_raw ) : 0;

	$duration_minutes = live_session_duration_minutes( $lesson );
	$end              = $start > 0 ? $start + ( $duration_minutes * 60 ) : 0;
	$now              = time();

	if ( $start > 0 && $now < $start ) {
		$state = 'pre';
	} elseif ( $start > 0 && $now <= $end ) {
		$state = 'live';
	} elseif ( $start > 0 ) {
		$state = 'post';
	} else {
		// No scheduled start — treat as live now so the lesson is usable.
		$state = 'live';
	}

	$join_url = (string) ( $lesson->video_url ?? '' );

	return array(
		'state'            => $state,
		'starts_at'        => '' !== $scheduled_raw ? $scheduled_raw : null,
		'duration_minutes' => $duration_minutes,
		'minutes_until'    => $start > 0 ? max( 0, (int) ceil( ( $start - $now ) / 60 ) ) : 0,
		'join_url'         => '' !== $join_url ? $join_url : null,
	);
}

function live_session_duration_minutes( ?object $lesson = null ): int {
	$minutes = (int) ( $lesson->live_duration_minutes ?? 0 );

	if ( $minutes <= 0 ) {
		/**
		 * Filter the default length of a live session, in minutes.
		 *
		 * @param int         $minutes Default 60.
		 * @param object|null $lesson  The lesson row, when one is in play.
		 */
		$minutes = (int) apply_filters( 'learnomy_live_session_duration', 60, $lesson );
	}

	// A session that runs past a day is a course, not a session; a zero-length one
	// would show the Join button for no time at all.
	return max( 1, min( 1440, $minutes ) );
}

/**
 * The length an author actually DECLARED for a lesson, in seconds.
 *
 * Two columns answer "how long is this", and which one is filled depends on the
 * content type:
 *  - `duration_seconds`      — the Duration field, for every ordinary lesson.
 *  - `live_duration_minutes` — the "Runs for" field on a live session.
 *
 * A live lesson leaves `duration_seconds` at 0, so reading only that column
 * reports a scheduled 45-minute session as having no length at all.
 *
 * Deliberately NOT `live_session_duration_minutes()`: that helper substitutes a
 * 60-minute default so the Join button always has a window to live in. Inventing
 * a length is right for the button and wrong for a label — it would tell students
 * "60 min" for a session whose author never said how long it runs. Here, unknown
 * stays unknown and the caller shows nothing.
 *
 * @param object $lesson Lesson row.
 * @return int Seconds, or 0 when no length was declared.
 */
function lesson_duration_seconds( object $lesson ): int {
	$seconds = (int) ( $lesson->duration_seconds ?? 0 );

	if ( $seconds > 0 ) {
		return $seconds;
	}

	if ( 'live' === (string) ( $lesson->content_type ?? '' ) ) {
		$seconds = (int) ( $lesson->live_duration_minutes ?? 0 ) * MINUTE_IN_SECONDS;
	}

	return max( 0, $seconds );
}

/**
 * Format a length as a compact label for a list row: "6 min", "1h 05m".
 *
 * Minutes-only below an hour, because "0h 06m" is noise on the 98% of lessons
 * that never reach one. Past an hour the minutes are zero-padded so a column of
 * them stays on a grid.
 *
 * The single-course HEADER keeps its own prose formatter ("45 minutes",
 * "2 hours") in Single_Course_View_Data — a page-level total reads as a
 * sentence, a row label reads as a chip. Different register, deliberately.
 *
 * @param int $seconds Length in seconds.
 * @return string Localized label, or '' when there is nothing to show.
 */
function format_duration_label( int $seconds ): string {
	if ( $seconds <= 0 ) {
		return '';
	}

	// Round to nearest, never below 1. Rounding UP was the first instinct — never
	// undersell the time — but it renders an author's "12 min 25 sec" as "13 min",
	// and the person most likely to notice a wrong-looking number is the one who
	// typed it. The floor keeps a 20-second lesson off "0 min".
	$minutes = max( 1, (int) round( $seconds / MINUTE_IN_SECONDS ) );

	if ( $minutes < 60 ) {
		/* translators: %d: number of minutes. */
		return sprintf( __( '%d min', 'learnomy' ), $minutes );
	}

	return sprintf(
		/* translators: 1: whole hours, 2: remaining minutes, zero-padded. */
		__( '%1$dh %2$02dm', 'learnomy' ),
		intdiv( $minutes, 60 ),
		$minutes % 60
	);
}

/**
 * Whether Learnomy runs its OWN dark-mode switch, independent of the theme.
 *
 * Off by default, deliberately. Learnomy's colours are token-driven and inherit
 * the active theme, so the plugin already renders in the theme's colour scheme
 * wherever it is embedded. A second, plugin-only dark switch does not add a
 * choice — it adds a way for the two to disagree, and the way it disagrees is
 * a dark Learnomy block sitting on an otherwise light page.
 *
 * On a bridge theme (Reign, BuddyX) the theme drives dark mode site-wide and
 * Theme_Bridge already follows it; overriding that is never right, so the seam
 * is closed there and the filter is not even consulted.
 *
 * Nothing is deleted: the dark palette, the `learnomy_dark_mode` user meta, the
 * persistence route and the toggle markup all still ship. A site owner running a
 * theme with no dark mode of its own can hand the switch back to their members
 * with `add_filter( 'learnomy_standalone_dark_mode', '__return_true' )`.
 *
 * THE single reader of this decision — the toggle's visibility and the body
 * class are two halves of one thing, and they must never be able to disagree.
 */
function standalone_dark_mode(): bool {
	// The theme owns the scheme. Not ours to override.
	if ( Integrations\Theme_Bridge::has_active_bridge() ) {
		return false;
	}

	/**
	 * Filter whether Learnomy shows its own dark-mode toggle and applies its own
	 * dark palette, independent of the active theme.
	 *
	 * Ignored on themes that bridge their dark mode into Learnomy — there the
	 * theme is the single source of truth.
	 *
	 * @param bool $enabled Default false: follow the theme.
	 */
	return (bool) apply_filters( 'learnomy_standalone_dark_mode', false );
}

/**
 * The uniform tax rate, as a percentage in [0, 100]. Zero when tax is off.
 *
 * THE single reader of the tax settings. It lives here, not in an adapter, because
 * it used to live in an adapter: Stripe kept `uniform_tax_rate()` private to itself
 * and the PayPal adapter simply never grew one. The string "tax" appeared ZERO times
 * in the PayPal adapter. So a site owner who enabled tax charged it to Stripe buyers
 * and not to PayPal buyers — same site, same course, two different totals, and no way
 * to notice short of reconciling the books.
 *
 * Any gateway that takes money MUST resolve tax through this. A private copy per
 * adapter is how the fourth gateway repeats the bug.
 */
function tax_rate(): float {
	$settings = get_option( 'learnomy_settings', array() );
	if ( ! is_array( $settings ) || empty( $settings['tax_enabled'] ) ) {
		return 0.0;
	}
	return max( 0.0, min( 100.0, (float) ( $settings['tax_rate'] ?? 0 ) ) );
}

/**
 * Tax owed on a subtotal at the uniform rate (exclusive). Zero when tax is off.
 *
 * @param float $subtotal Pre-tax amount in major units.
 * @return float Tax in major units, rounded to 2dp.
 */
function tax_amount( float $subtotal ): float {
	$rate = tax_rate();
	return $rate > 0 ? round( $subtotal * ( $rate / 100 ), 2 ) : 0.0;
}

/**
 * The currency an item is actually charged in.
 *
 * THE single resolver. A membership plan may override the site currency — the
 * column exists (`lrn_membership_plans.currency`), the plan editor offers a
 * `<select>` for it, and the admin plans list renders each plan's price in it.
 * Checkout, however, used to hardcode the GLOBAL setting on both paths
 * (Checkout_Controller `subscribe()` and `resolve_checkout_args()`), so a plan set
 * to EUR displayed "€49" back to the site owner and charged the customer 49 USD.
 * Saved, shown, and never applied — the owner had no way to notice until someone
 * complained.
 *
 * Courses have no currency column, so a course purchase legitimately falls back to
 * the site currency. Pass the plan (or null) and this decides.
 *
 * @param object|null $plan A membership plan row, or null for a course/site-level charge.
 */
function charge_currency( ?object $plan = null ): string {
	$settings = get_option( 'learnomy_settings', array() );
	$site     = strtoupper( (string) ( $settings['currency'] ?? 'USD' ) );

	$plan_currency = $plan ? strtoupper( trim( (string) ( $plan->currency ?? '' ) ) ) : '';
	if ( '' === $plan_currency ) {
		return $site;
	}

	// Only honour a code we can actually render and that a gateway will accept.
	// A junk value in the column must fall back to the site currency rather than
	// be handed to Stripe/PayPal, which would fail the charge outright.
	if ( ! in_array( $plan_currency, supported_currencies(), true ) ) {
		return $site;
	}

	return $plan_currency;
}

/**
 * Minor units per major unit for a currency (the ISO 4217 minor unit).
 *
 * "Zero-decimal" currencies (JPY, KRW, …) have no fractional unit — the smallest
 * unit IS the currency unit — so the multiplier is 1: ¥1000 is 1000 minor units,
 * NOT 100000. Everything else has cents and defaults to 100.
 *
 * Gateways that charge in the smallest unit (Stripe) will overcharge a
 * zero-decimal currency 100x if this is not applied — ¥1000 sent as 100000 =
 * ¥100,000. The list is Stripe's canonical zero-decimal set; it is filterable so
 * a site adding one of them via `learnomy_currency_symbols` also gets the correct
 * multiplier. PayPal (decimal strings) and WooCommerce (major-unit floats) do not
 * multiply, so this is only consumed by the Stripe adapter.
 *
 * @param string $currency ISO 4217 code.
 * @return int 1 for zero-decimal currencies, else 100.
 */
function currency_minor_units( string $currency ): int {
	$code = strtoupper( trim( $currency ) );

	/**
	 * Currencies whose smallest unit is a THOUSANDTH, not a hundredth.
	 *
	 * The Gulf dinars and the North African dinars divide into 1000 fils or
	 * millimes. Charging one of these at 100 sends a tenth of the price: KWD
	 * 10.500 becomes 1050 fils instead of 10500, and the customer is undercharged
	 * by 90%. It was unreachable while the picker offered eight currencies and
	 * none of them were these; opening the picker to the full set is what makes
	 * it reachable, so it is fixed in the same change.
	 *
	 * @param string[] $codes ISO 4217 codes with exponent 3.
	 */
	$three_decimal = (array) apply_filters(
		'learnomy_three_decimal_currencies',
		array( 'BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND' )
	);

	if ( in_array( $code, $three_decimal, true ) ) {
		return 1000;
	}

	$zero_decimal = (array) apply_filters(
		'learnomy_zero_decimal_currencies',
		array( 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' )
	);

	return in_array( $code, $zero_decimal, true ) ? 1 : 100;
}

/**
 * Convert a major-unit amount (e.g. 19.99, or 1000 for ¥1000) to the gateway
 * minor-unit integer, respecting zero-decimal currencies.
 */
function to_minor_unit( float $amount, string $currency ): int {
	return (int) round( $amount * currency_minor_units( $currency ) );
}

/**
 * Convert a gateway minor-unit integer back to a major-unit float, respecting
 * zero-decimal currencies (¥ amounts are already major units → divide by 1).
 *
 * @param int|float|string $minor Minor-unit amount from the gateway.
 */
function from_minor_unit( $minor, string $currency ): float {
	return (float) $minor / currency_minor_units( $currency );
}

/**
 * Decimal places for a currency: 0 for zero-decimal currencies (JPY, KRW, …),
 * else 2. Use this anywhere an amount is formatted or sent as a decimal STRING —
 * PayPal's Orders API rejects "1000.00" for JPY (the value must match the
 * currency's decimal count), and a JPY price should display as ¥1000, not ¥1000.00.
 */
function currency_decimals( string $currency ): int {
	// Derived from the multiplier so the two can never disagree: 1 -> 0, 100 -> 2,
	// 1000 -> 3. Asserting "0 or 2" was true while only those two existed and
	// would have rendered KWD 10.500 as "10.50" the moment a third appeared.
	return (int) log10( max( 1, currency_minor_units( $currency ) ) );
}

/**
 * Currency codes the product supports end to end (editor → checkout → display).
 *
 * Derived from the symbol map so the picker, the charge, and the rendered glyph
 * can never disagree about what is supported.
 *
 * @return string[]
 */
function supported_currencies(): array {
	return array_keys( currency_symbols() );
}

/**
 * ISO 4217 code => human name.
 *
 * Only a picker needs these: a list of 163 bare codes is a lookup table, not a
 * choice, and an owner should not have to know that MUR is the Mauritian rupee
 * to sell in it. Everything that RENDERS money uses the glyph map instead.
 *
 * @return array<string,string>
 */
function currency_names(): array {
	/**
	 * Filter the human names shown in a currency picker.
	 *
	 * @param array<string,string>  Code => name.
	 */
	return (array) apply_filters(
		'learnomy_currency_names',
		array(
			'AED' => __( 'United Arab Emirates dirham', 'learnomy' ),
			'AFN' => __( 'Afghan afghani', 'learnomy' ),
			'ALL' => __( 'Albanian lek', 'learnomy' ),
			'AMD' => __( 'Armenian dram', 'learnomy' ),
			'ANG' => __( 'Netherlands Antillean guilder', 'learnomy' ),
			'AOA' => __( 'Angolan kwanza', 'learnomy' ),
			'ARS' => __( 'Argentine peso', 'learnomy' ),
			'AUD' => __( 'Australian dollar', 'learnomy' ),
			'AWG' => __( 'Aruban florin', 'learnomy' ),
			'AZN' => __( 'Azerbaijani manat', 'learnomy' ),
			'BAM' => __( 'Bosnia and Herzegovina convertible mark', 'learnomy' ),
			'BBD' => __( 'Barbadian dollar', 'learnomy' ),
			'BDT' => __( 'Bangladeshi taka', 'learnomy' ),
			'BGN' => __( 'Bulgarian lev', 'learnomy' ),
			'BHD' => __( 'Bahraini dinar', 'learnomy' ),
			'BIF' => __( 'Burundian franc', 'learnomy' ),
			'BMD' => __( 'Bermudian dollar', 'learnomy' ),
			'BND' => __( 'Brunei dollar', 'learnomy' ),
			'BOB' => __( 'Bolivian boliviano', 'learnomy' ),
			'BRL' => __( 'Brazilian real', 'learnomy' ),
			'BSD' => __( 'Bahamian dollar', 'learnomy' ),
			'BTC' => __( 'Bitcoin', 'learnomy' ),
			'BTN' => __( 'Bhutanese ngultrum', 'learnomy' ),
			'BWP' => __( 'Botswana pula', 'learnomy' ),
			'BYN' => __( 'Belarusian ruble', 'learnomy' ),
			'BYR' => __( 'Belarusian ruble (old)', 'learnomy' ),
			'BZD' => __( 'Belize dollar', 'learnomy' ),
			'CAD' => __( 'Canadian dollar', 'learnomy' ),
			'CDF' => __( 'Congolese franc', 'learnomy' ),
			'CHF' => __( 'Swiss franc', 'learnomy' ),
			'CLP' => __( 'Chilean peso', 'learnomy' ),
			'CNY' => __( 'Chinese yuan', 'learnomy' ),
			'COP' => __( 'Colombian peso', 'learnomy' ),
			'CRC' => __( 'Costa Rican colón', 'learnomy' ),
			'CUC' => __( 'Cuban convertible peso', 'learnomy' ),
			'CUP' => __( 'Cuban peso', 'learnomy' ),
			'CVE' => __( 'Cape Verdean escudo', 'learnomy' ),
			'CZK' => __( 'Czech koruna', 'learnomy' ),
			'DJF' => __( 'Djiboutian franc', 'learnomy' ),
			'DKK' => __( 'Danish krone', 'learnomy' ),
			'DOP' => __( 'Dominican peso', 'learnomy' ),
			'DZD' => __( 'Algerian dinar', 'learnomy' ),
			'EGP' => __( 'Egyptian pound', 'learnomy' ),
			'ERN' => __( 'Eritrean nakfa', 'learnomy' ),
			'ETB' => __( 'Ethiopian birr', 'learnomy' ),
			'EUR' => __( 'Euro', 'learnomy' ),
			'FJD' => __( 'Fijian dollar', 'learnomy' ),
			'FKP' => __( 'Falkland Islands pound', 'learnomy' ),
			'GBP' => __( 'Pound sterling', 'learnomy' ),
			'GEL' => __( 'Georgian lari', 'learnomy' ),
			'GGP' => __( 'Guernsey pound', 'learnomy' ),
			'GHS' => __( 'Ghana cedi', 'learnomy' ),
			'GIP' => __( 'Gibraltar pound', 'learnomy' ),
			'GMD' => __( 'Gambian dalasi', 'learnomy' ),
			'GNF' => __( 'Guinean franc', 'learnomy' ),
			'GTQ' => __( 'Guatemalan quetzal', 'learnomy' ),
			'GYD' => __( 'Guyanese dollar', 'learnomy' ),
			'HKD' => __( 'Hong Kong dollar', 'learnomy' ),
			'HNL' => __( 'Honduran lempira', 'learnomy' ),
			'HRK' => __( 'Croatian kuna', 'learnomy' ),
			'HTG' => __( 'Haitian gourde', 'learnomy' ),
			'HUF' => __( 'Hungarian forint', 'learnomy' ),
			'IDR' => __( 'Indonesian rupiah', 'learnomy' ),
			'ILS' => __( 'Israeli new shekel', 'learnomy' ),
			'IMP' => __( 'Manx pound', 'learnomy' ),
			'INR' => __( 'Indian rupee', 'learnomy' ),
			'IQD' => __( 'Iraqi dinar', 'learnomy' ),
			'IRR' => __( 'Iranian rial', 'learnomy' ),
			'IRT' => __( 'Iranian toman', 'learnomy' ),
			'ISK' => __( 'Icelandic króna', 'learnomy' ),
			'JEP' => __( 'Jersey pound', 'learnomy' ),
			'JMD' => __( 'Jamaican dollar', 'learnomy' ),
			'JOD' => __( 'Jordanian dinar', 'learnomy' ),
			'JPY' => __( 'Japanese yen', 'learnomy' ),
			'KES' => __( 'Kenyan shilling', 'learnomy' ),
			'KGS' => __( 'Kyrgyzstani som', 'learnomy' ),
			'KHR' => __( 'Cambodian riel', 'learnomy' ),
			'KMF' => __( 'Comorian franc', 'learnomy' ),
			'KPW' => __( 'North Korean won', 'learnomy' ),
			'KRW' => __( 'South Korean won', 'learnomy' ),
			'KWD' => __( 'Kuwaiti dinar', 'learnomy' ),
			'KYD' => __( 'Cayman Islands dollar', 'learnomy' ),
			'KZT' => __( 'Kazakhstani tenge', 'learnomy' ),
			'LAK' => __( 'Lao kip', 'learnomy' ),
			'LBP' => __( 'Lebanese pound', 'learnomy' ),
			'LKR' => __( 'Sri Lankan rupee', 'learnomy' ),
			'LRD' => __( 'Liberian dollar', 'learnomy' ),
			'LSL' => __( 'Lesotho loti', 'learnomy' ),
			'LYD' => __( 'Libyan dinar', 'learnomy' ),
			'MAD' => __( 'Moroccan dirham', 'learnomy' ),
			'MDL' => __( 'Moldovan leu', 'learnomy' ),
			'MGA' => __( 'Malagasy ariary', 'learnomy' ),
			'MKD' => __( 'Macedonian denar', 'learnomy' ),
			'MMK' => __( 'Burmese kyat', 'learnomy' ),
			'MNT' => __( 'Mongolian tögrög', 'learnomy' ),
			'MOP' => __( 'Macanese pataca', 'learnomy' ),
			'MRU' => __( 'Mauritanian ouguiya', 'learnomy' ),
			'MUR' => __( 'Mauritian rupee', 'learnomy' ),
			'MVR' => __( 'Maldivian rufiyaa', 'learnomy' ),
			'MWK' => __( 'Malawian kwacha', 'learnomy' ),
			'MXN' => __( 'Mexican peso', 'learnomy' ),
			'MYR' => __( 'Malaysian ringgit', 'learnomy' ),
			'MZN' => __( 'Mozambican metical', 'learnomy' ),
			'NAD' => __( 'Namibian dollar', 'learnomy' ),
			'NGN' => __( 'Nigerian naira', 'learnomy' ),
			'NIO' => __( 'Nicaraguan córdoba', 'learnomy' ),
			'NOK' => __( 'Norwegian krone', 'learnomy' ),
			'NPR' => __( 'Nepalese rupee', 'learnomy' ),
			'NZD' => __( 'New Zealand dollar', 'learnomy' ),
			'OMR' => __( 'Omani rial', 'learnomy' ),
			'PAB' => __( 'Panamanian balboa', 'learnomy' ),
			'PEN' => __( 'Sol', 'learnomy' ),
			'PGK' => __( 'Papua New Guinean kina', 'learnomy' ),
			'PHP' => __( 'Philippine peso', 'learnomy' ),
			'PKR' => __( 'Pakistani rupee', 'learnomy' ),
			'PLN' => __( 'Polish złoty', 'learnomy' ),
			'PRB' => __( 'Transnistrian ruble', 'learnomy' ),
			'PYG' => __( 'Paraguayan guaraní', 'learnomy' ),
			'QAR' => __( 'Qatari riyal', 'learnomy' ),
			'RON' => __( 'Romanian leu', 'learnomy' ),
			'RSD' => __( 'Serbian dinar', 'learnomy' ),
			'RUB' => __( 'Russian ruble', 'learnomy' ),
			'RWF' => __( 'Rwandan franc', 'learnomy' ),
			'SAR' => __( 'Saudi riyal', 'learnomy' ),
			'SBD' => __( 'Solomon Islands dollar', 'learnomy' ),
			'SCR' => __( 'Seychellois rupee', 'learnomy' ),
			'SDG' => __( 'Sudanese pound', 'learnomy' ),
			'SEK' => __( 'Swedish krona', 'learnomy' ),
			'SGD' => __( 'Singapore dollar', 'learnomy' ),
			'SHP' => __( 'Saint Helena pound', 'learnomy' ),
			'SLL' => __( 'Sierra Leonean leone', 'learnomy' ),
			'SOS' => __( 'Somali shilling', 'learnomy' ),
			'SRD' => __( 'Surinamese dollar', 'learnomy' ),
			'SSP' => __( 'South Sudanese pound', 'learnomy' ),
			'STN' => __( 'São Tomé and Príncipe dobra', 'learnomy' ),
			'SYP' => __( 'Syrian pound', 'learnomy' ),
			'SZL' => __( 'Swazi lilangeni', 'learnomy' ),
			'THB' => __( 'Thai baht', 'learnomy' ),
			'TJS' => __( 'Tajikistani somoni', 'learnomy' ),
			'TMT' => __( 'Turkmenistan manat', 'learnomy' ),
			'TND' => __( 'Tunisian dinar', 'learnomy' ),
			'TOP' => __( 'Tongan paʻanga', 'learnomy' ),
			'TRY' => __( 'Turkish lira', 'learnomy' ),
			'TTD' => __( 'Trinidad and Tobago dollar', 'learnomy' ),
			'TWD' => __( 'New Taiwan dollar', 'learnomy' ),
			'TZS' => __( 'Tanzanian shilling', 'learnomy' ),
			'UAH' => __( 'Ukrainian hryvnia', 'learnomy' ),
			'UGX' => __( 'Ugandan shilling', 'learnomy' ),
			'USD' => __( 'United States (US) dollar', 'learnomy' ),
			'UYU' => __( 'Uruguayan peso', 'learnomy' ),
			'UZS' => __( 'Uzbekistani som', 'learnomy' ),
			'VEF' => __( 'Venezuelan bolívar (2008–2018)', 'learnomy' ),
			'VES' => __( 'Venezuelan bolívar', 'learnomy' ),
			'VND' => __( 'Vietnamese đồng', 'learnomy' ),
			'VUV' => __( 'Vanuatu vatu', 'learnomy' ),
			'WST' => __( 'Samoan tālā', 'learnomy' ),
			'XAF' => __( 'Central African CFA franc', 'learnomy' ),
			'XCD' => __( 'East Caribbean dollar', 'learnomy' ),
			'XOF' => __( 'West African CFA franc', 'learnomy' ),
			'XPF' => __( 'CFP franc', 'learnomy' ),
			'YER' => __( 'Yemeni rial', 'learnomy' ),
			'ZAR' => __( 'South African rand', 'learnomy' ),
			'ZMW' => __( 'Zambian kwacha', 'learnomy' ),
		)
	);
}

/**
 * ISO 4217 code => display glyph.
 *
 * The complete active set, not a shortlist. It was eight, which is the wrong
 * shape for this kind of data: the codes are a fixed, published standard of
 * fewer than 200 entries, and every gateway we support settles on the CODE, not
 * on anything we know about the currency. A shortlist therefore bought nothing
 * and silently excluded an owner in Lagos or Nairobi from selling in their own
 * money — the exact failure the account-and-billing standard names, where a
 * partial list excludes customers instead of failing visibly.
 *
 * A value that equals its own code is deliberate: plenty of currencies have no
 * distinct glyph in common use, and the renderer already falls back to showing
 * the code, so "AMD 500" is the correct rendering rather than a missing one.
 *
 * Filterable, so a site can override a glyph (or add a code the standard gains)
 * without waiting for a release.
 *
 * @return array<string,string>
 */
function currency_symbols(): array {
	/**
	 * Map of ISO 4217 currency code to display symbol.
	 *
	 * @param array<string,string> $symbols Code => glyph.
	 */
	return (array) apply_filters(
		'learnomy_currency_symbols',
		array(
			'AED' => 'د.إ',
			'AFN' => '؋',
			'ALL' => 'L',
			'AMD' => 'AMD',
			'ANG' => 'ƒ',
			'AOA' => 'Kz',
			'ARS' => '$',
			'AUD' => '$',
			'AWG' => 'Afl.',
			'AZN' => '₼',
			'BAM' => 'KM',
			'BBD' => '$',
			'BDT' => '৳ ',
			'BGN' => 'лв.',
			'BHD' => '.د.ب',
			'BIF' => 'Fr',
			'BMD' => '$',
			'BND' => '$',
			'BOB' => 'Bs.',
			'BRL' => 'R$',
			'BSD' => '$',
			'BTC' => '฿',
			'BTN' => 'Nu.',
			'BWP' => 'P',
			'BYN' => 'Br',
			'BYR' => 'Br',
			'BZD' => '$',
			'CAD' => '$',
			'CDF' => 'Fr',
			'CHF' => 'CHF',
			'CLP' => '$',
			'CNY' => '¥',
			'COP' => '$',
			'CRC' => '₡',
			'CUC' => '$',
			'CUP' => '$',
			'CVE' => '$',
			'CZK' => 'Kč',
			'DJF' => 'Fr',
			'DKK' => 'kr.',
			'DOP' => 'RD$',
			'DZD' => 'د.ج',
			'EGP' => 'EGP',
			'ERN' => 'Nfk',
			'ETB' => 'Br',
			'EUR' => '€',
			'FJD' => '$',
			'FKP' => '£',
			'GBP' => '£',
			'GEL' => '₾',
			'GGP' => '£',
			'GHS' => '₵',
			'GIP' => '£',
			'GMD' => 'D',
			'GNF' => 'Fr',
			'GTQ' => 'Q',
			'GYD' => '$',
			'HKD' => '$',
			'HNL' => 'L',
			'HRK' => 'kn',
			'HTG' => 'G',
			'HUF' => 'Ft',
			'IDR' => 'Rp',
			'ILS' => '₪',
			'IMP' => '£',
			'INR' => '₹',
			'IQD' => 'د.ع',
			'IRR' => '﷼',
			'IRT' => 'تومان',
			'ISK' => 'kr.',
			'JEP' => '£',
			'JMD' => '$',
			'JOD' => 'د.ا',
			'JPY' => '¥',
			'KES' => 'KSh',
			'KGS' => 'сом',
			'KHR' => '៛',
			'KMF' => 'Fr',
			'KPW' => '₩',
			'KRW' => '₩',
			'KWD' => 'د.ك',
			'KYD' => '$',
			'KZT' => '₸',
			'LAK' => '₭',
			'LBP' => 'ل.ل',
			'LKR' => 'රු',
			'LRD' => '$',
			'LSL' => 'L',
			'LYD' => 'د.ل',
			'MAD' => 'د.م.',
			'MDL' => 'MDL',
			'MGA' => 'Ar',
			'MKD' => 'ден',
			'MMK' => 'Ks',
			'MNT' => '₮',
			'MOP' => 'P',
			'MRU' => 'UM',
			'MUR' => '₨',
			'MVR' => '.ރ',
			'MWK' => 'MK',
			'MXN' => '$',
			'MYR' => 'RM',
			'MZN' => 'MT',
			'NAD' => 'N$',
			'NGN' => '₦',
			'NIO' => 'C$',
			'NOK' => 'kr',
			'NPR' => '₨',
			'NZD' => '$',
			'OMR' => 'ر.ع.',
			'PAB' => 'B/.',
			'PEN' => 'S/',
			'PGK' => 'K',
			'PHP' => '₱',
			'PKR' => '₨',
			'PLN' => 'zł',
			'PRB' => 'р.',
			'PYG' => '₲',
			'QAR' => 'ر.ق',
			'RON' => 'lei',
			'RSD' => 'рсд',
			'RUB' => '₽',
			'RWF' => 'Fr',
			'SAR' => 'ر.س',
			'SBD' => '$',
			'SCR' => '₨',
			'SDG' => 'ج.س.',
			'SEK' => 'kr',
			'SGD' => '$',
			'SHP' => '£',
			'SLL' => 'Le',
			'SOS' => 'Sh',
			'SRD' => '$',
			'SSP' => '£',
			'STN' => 'Db',
			'SYP' => 'ل.س',
			'SZL' => 'E',
			'THB' => '฿',
			'TJS' => 'ЅМ',
			'TMT' => 'm',
			'TND' => 'د.ت',
			'TOP' => 'T$',
			'TRY' => '₺',
			'TTD' => '$',
			'TWD' => 'NT$',
			'TZS' => 'Sh',
			'UAH' => '₴',
			'UGX' => 'UGX',
			'USD' => '$',
			'UYU' => '$',
			'UZS' => 'UZS',
			'VEF' => 'Bs F',
			'VES' => 'Bs.',
			'VND' => '₫',
			'VUV' => 'Vt',
			'WST' => 'T',
			'XAF' => 'CFA',
			'XCD' => '$',
			'XOF' => 'CFA',
			'XPF' => 'XPF',
			'YER' => '﷼',
			'ZAR' => 'R',
			'ZMW' => 'ZK',
		)
	);
}

function currency_symbol( ?string $code = null ): string {
	$settings = get_option( 'learnomy_settings', array() );

	// An explicit override only makes sense for the SITE currency. A plan charged
	// in another currency must not borrow the site's glyph, or a EUR plan renders
	// as "$49".
	if ( null === $code ) {
		$explicit = isset( $settings['currency_symbol'] ) ? trim( (string) $settings['currency_symbol'] ) : '';
		if ( '' !== $explicit ) {
			return $explicit;
		}
	}

	$currency = strtoupper( (string) ( $code ?? $settings['currency'] ?? 'USD' ) );
	$symbols  = currency_symbols();
	$symbol   = $symbols[ $currency ] ?? $currency;

	/*
	 * A code used AS the symbol needs a separator; a glyph does not. "$49.90"
	 * is right and "CHF49.90" is not, and roughly a hundred of the currencies
	 * we now list have no distinct glyph in common use.
	 *
	 * This used to be handled by the unknown-code fallback appending a space,
	 * which stopped applying the moment those codes were added to the map as
	 * real entries. Deciding it from the VALUE rather than from whether the
	 * lookup missed keeps it right either way.
	 */
	return ( $symbol === $currency ) ? $symbol . ' ' : $symbol;
}

/**
 * Where the currency symbol sits relative to the amount, for the current locale.
 *
 * English-speaking locales lead ("$49.99"); most of Europe trails, with a
 * non-breaking space ("49,99 €"). Emitting "€49,99" to a German buyer is a
 * format no German writes — small, but it is the kind of detail that makes a
 * checkout feel foreign at exactly the wrong moment.
 *
 * Derived from the locale rather than exposed as a setting: the locale already
 * answers this for the overwhelming majority, and a setting would be one more
 * thing to get wrong. The filter is the escape hatch for the rest.
 *
 * @param string $currency Resolved ISO currency code.
 * @return string 'before' or 'after'.
 */
function currency_symbol_position( string $currency = '' ): string {
	$locale = determine_locale();

	// Language codes that conventionally place the symbol AFTER the amount.
	// Keyed on language, not full locale, so de_DE / de_AT / de_CH all agree.
	$suffix_languages = array(
		'de',
		'fr',
		'es',
		'it',
		'pt',
		'nl',
		'sv',
		'da',
		'nb',
		'nn',
		'fi',
		'pl',
		'cs',
		'sk',
		'hu',
		'ro',
		'bg',
		'hr',
		'sl',
		'lt',
		'lv',
		'et',
		'el',
		'ru',
		'uk',
		'tr',
		'vi',
	);

	$language = strtolower( substr( (string) $locale, 0, 2 ) );
	$position = in_array( $language, $suffix_languages, true ) ? 'after' : 'before';

	/**
	 * Filter the currency symbol position.
	 *
	 * @param string $position 'before' or 'after'.
	 * @param string $currency Resolved ISO currency code.
	 * @param string $locale   Locale the decision was derived from.
	 */
	$position = (string) apply_filters( 'learnomy_currency_symbol_position', $position, $currency, $locale );

	return 'after' === $position ? 'after' : 'before';
}

/**
 * Format a monetary amount for display: currency symbol + locale-aware number,
 * with locale-aware symbol placement. Use this everywhere instead of hardcoding
 * '$' . number_format().
 *
 * Pass $currency to render a row in its OWN currency (a membership/space plan
 * can price in a currency other than the site default) rather than the site
 * currency. Null falls back to the site currency, so existing single-arg calls
 * are unchanged.
 *
 * @param float       $amount   Amount.
 * @param string|null $currency ISO code (e.g. 'EUR'); null = site currency.
 * @param int         $decimals Decimal places. -1 (default) auto-derives from the
 *                              currency: 0 for zero-decimal currencies (¥1000, not
 *                              ¥1000.00), else 2. Pass an explicit value to override.
 * @return string
 */
function format_price( float $amount, ?string $currency = null, int $decimals = -1 ): string {
	$resolved = $currency ?? charge_currency();

	if ( $decimals < 0 ) {
		$decimals = currency_decimals( $resolved );
	}

	// Resolve the symbol from the SAME value the decimals and position use.
	// This passed the raw `$currency`, which is null on every single-argument
	// call. It happened to work because currency_symbol( null ) falls back to
	// the site setting too — but that is a second, parallel resolution of the
	// same question, and the two would part company the moment either fallback
	// changed. One resolved value, three consumers.
	$symbol = currency_symbol( $resolved );

	// The sign leads; it belongs to the figure, not inside it. Formatting the
	// absolute value and prefixing gives "-$49.00" rather than "$-49.00", which
	// is what a refund row on the admin dashboard was rendering.
	$is_negative = $amount < 0;
	$number      = number_format_i18n( abs( $amount ), $decimals );
	$sign        = $is_negative ? '-' : '';

	if ( 'after' === currency_symbol_position( (string) $resolved ) ) {
		// Non-breaking space: the amount and its symbol must never wrap apart.
		return $sign . $number . "\u{00A0}" . trim( $symbol );
	}

	return $sign . $symbol . $number;
}

/**
 * Editor-only placeholder for a server-rendered block that has nothing to show.
 *
 * Server-rendered blocks return empty markup when unconfigured (or when their
 * subject was deleted), which the editor surfaces as a bare "Block rendered as
 * empty." A site owner can't tell whether that is a bug or just an unfinished
 * block. This returns a small, styled hint INSTEAD — but only inside the block
 * editor's preview request (`/wp/v2/block-renderer/...`) for users who can edit.
 * On the public front end it returns an empty string, so visitors still see
 * nothing for an unconfigured block. Blocks call this from their empty branch.
 *
 * @param string $message Guidance shown to the editor, already translated.
 * @return string Markup for the editor preview, or '' on the front end.
 */
function block_editor_placeholder( string $message ): string {
	$is_editor_preview = defined( 'REST_REQUEST' ) && REST_REQUEST && current_user_can( 'edit_posts' );
	if ( ! $is_editor_preview ) {
		return '';
	}

	return '<div class="lrn-block-placeholder">' . esc_html( $message ) . '</div>';
}

/**
 * A user's wishlist as a clean array of course IDs.
 *
 * The single, robust reader for the `learnomy_wishlist` user-meta. It tolerates
 * BOTH storage shapes: the canonical JSON string the wishlist controller writes,
 * AND a raw PHP array (which WordPress auto-unserializes on read — e.g. demo-
 * seeded rows). Passing an array to json_decode() throws a TypeError on PHP 8,
 * which was fataling /account for affected users. Every surface that needs the
 * wishlist must call this instead of decoding the meta itself.
 *
 * @param int $user_id Defaults to the current user.
 * @return array<int, int> Unique, integer course IDs.
 */
function wishlist_ids( int $user_id = 0 ): array {
	// Thin alias kept because ~10 templates and view-data classes call it.
	// The implementation lives in Wishlist_Service, which also owns the writes
	// and the enrollment listener, so read and write can never disagree about
	// the storage shape.
	return \Learnomy\Services\Wishlist_Service::ids( $user_id );
}

/**
 * Enqueue the assets the wishlist heart button needs to work.
 *
 * On the canonical Learnomy routes (catalog, category, dashboard, account-
 * courses, single-course) Template_Loader::enqueue_assets() already loads
 * Lucide + the Interactivity view module + the wishlist store. But course-card
 * markup also renders through the course-card / course-grid / course-catalog
 * blocks and the [learnomy_course*] shortcodes on arbitrary WP pages, plus the
 * instructor-profile template — and on those surfaces none of the wishlist
 * assets loaded, so the heart rendered as an inert, icon-less <i>. This helper
 * gives those surfaces the same toggle the routes get.
 *
 * Idempotent (each handle enqueues at most once) and gated to logged-in users,
 * since only they can have a wishlist.
 */
function enqueue_wishlist_assets(): void {
	if ( ! is_user_logged_in() ) {
		return;
	}

	// Lucide turns the <i data-lucide="heart"> placeholder into an SVG. One
	// definition of "load the icon set", shared with the route bundle.
	\Learnomy\Template_Loader::enqueue_icons();

	if ( ! function_exists( 'wp_enqueue_script_module' ) ) {
		return;
	}

	// The wishlist store calls wp.apiFetch — a classic global a script module
	// can't declare as a dependency — so enqueue it explicitly (it also wires
	// the REST root + nonce the toggle posts with).
	wp_enqueue_script( 'wp-api-fetch' );

	// The wishlist module is a script module, which can't take wp_localize_script;
	// inject its translatable strings as a global on the classic wp-api-fetch
	// handle (already enqueued above, loads before the module) so the failure
	// toast has a PHP __() home and is delivered translated.
	wp_add_inline_script(
		'wp-api-fetch',
		'window.lrnWishlistI18n = ' . wp_json_encode(
			array(
				'add'    => __( 'Add to wishlist', 'learnomy' ),
				'remove' => __( 'Remove from wishlist', 'learnomy' ),
				'error'  => __( 'Could not update your wishlist. Please refresh and try again.', 'learnomy' ),
			)
		) . ';',
		'before'
	);

	wp_enqueue_script_module( 'learnomy-view', LEARNOMY_URL . 'assets/js/view.js', array( '@wordpress/interactivity' ), LEARNOMY_VERSION );
	wp_enqueue_script_module( 'learnomy-wishlist-view', LEARNOMY_URL . 'assets/js/wishlist-view.js', array( 'learnomy-view' ), LEARNOMY_VERSION );
}

/**
 * The routes that make up the "My Account" area — they share the vertical
 * account nav so every customer surface stays linked.
 *
 * @return array<int, string>
 */
function account_routes(): array {
	// Surfaces wired with the shared account layout. Pro adds its own
	// (my-spaces, gift, …) via this filter when it wires them in.
	return (array) apply_filters(
		'learnomy_account_routes',
		array( 'student-dashboard', 'account-courses', 'account-quiz-results', 'account-billing', 'membership-manage', 'account-details', 'account-instructor-profile', 'account-certificates' )
	);
}

/**
 * Every route that renders an instructor surface — the dashboard and each of
 * its sections.
 *
 * The twin of account_routes(), and it exists for the same reason: anything
 * that needs to know "is this an instructor screen" was otherwise retyping the
 * list. Pro's dashboard-widget stylesheet did exactly that, listing only
 * `instructor-dashboard`, so every Pro widget rendered on a SECTION route —
 * Earnings, My Courses, Students, Reports, Grading — shipped with no CSS at
 * all and fell back to raw theme list styling: disc bullets, theme margins,
 * a one-line list adrift in a 24px card (Basecamp 10171616724).
 *
 * A hardcoded allowlist that must be edited every time a section is added is
 * a guarantee that one day it will not be, and the failure is silent: the
 * widget still renders, just unstyled.
 *
 * A Pro extension adding an instructor section must add its route here via the
 * filter, exactly as it does for the account layout.
 *
 * @return string[] Route names.
 */
function instructor_routes(): array {
	return (array) apply_filters(
		'learnomy_instructor_routes',
		// 'instructor-reports' is NOT here, and its absence is the point. That
		// route is registered by Pro's analytics extension, so on a Free-only
		// site - 90% of installs - it does not exist, and every caller of this
		// list asked route_url() for it and got home_url() back. Silently, until
		// route_url() learned to report an unknown name in 1.9.3; the pre-release
		// smoke then showed 44 of those notices on a single instructor page load.
		//
		// The docblock above already stated the rule - a Pro extension adding an
		// instructor section adds its route HERE VIA THE FILTER - and this array
		// was breaking it three lines below where it was written. Pro now does
		// what the contract says (Analytics_Extension::register_reports_nav()).
		array( 'instructor-dashboard', 'instructor-courses', 'instructor-students', 'instructor-earnings', 'instructor-grading' )
	);
}

/**
 * Items for the "My Account" vertical navigation (WooCommerce-style), so the
 * customer's surfaces (courses, orders, memberships, spaces, …) are never
 * disconnected.
 *
 * Developer surface — everything is filterable, nothing hardcoded:
 *
 *   • ADD a menu item:
 *       add_filter( 'learnomy_account_nav_items', function ( $items ) {
 *           $items[] = [ 'key' => 'badges', 'label' => __( 'Badges', 'learnomy' ),
 *                        'icon' => 'medal', 'url' => home_url( '/account/badges/' ) ];
 *           return $items;
 *       } );
 *   • HIDE / REMOVE an item (by its stable `key`):
 *       add_filter( 'learnomy_account_nav_items', fn( $i ) =>
 *           array_values( array_filter( $i, fn( $x ) => 'gift' !== $x['key'] ) ) );
 *   • REORDER: re-sort the returned array.
 *   • Each item is shown only when its owner registers it — Pro modules add
 *     theirs while active; Free's certificates item is gated by
 *     module_enabled('certificates'). Disable a whole section (404 + delink)
 *     via the `learnomy_route_enabled` filter.
 *
 * @param string $current Active account route key (for the highlighted item).
 * @return array<int, array{key:string,label:string,icon:string,url:string,current:bool}>
 */
function account_nav_items( string $current = '' ): array {
	$items = array(
		array(
			// The account home — a lean overview (Continue learning, what's new,
			// a small in-progress preview). The full course list is its own
			// section below (account-courses).
			'key'   => 'student-dashboard',
			'label' => __( 'Dashboard', 'learnomy' ),
			'icon'  => 'layout-dashboard',
			'url'   => route_url( 'student-dashboard' ),
		),
		array(
			'key'   => 'account-courses',
			/* translators: %s: the plural entity label, e.g. "Courses" or "Programs". */
			'label' => sprintf( __( 'My %s', 'learnomy' ), course_label( true ) ),
			'icon'  => 'graduation-cap',
			'url'   => route_url( 'account-courses' ),
		),
		array(
			// Wishlist was only reachable as a tab inside My Courses, so it was
			// undiscoverable. Deep-link straight to that tab (dashboard-tabs.js
			// reads ?tab=).
			'key'   => 'account-wishlist',
			'label' => __( 'Wishlist', 'learnomy' ),
			'icon'  => 'heart',
			'url'   => add_query_arg( 'tab', 'wishlist', route_url( 'account-courses' ) ),
		),
		array(
			'key'   => 'account-billing',
			// One page had three names: this row said "Order history", the
			// browser title said "Billing" and the heading said "Billing &
			// orders". A member clicking "Order history" landed somewhere that
			// never repeated the words they clicked. Same rule the admin
			// sidebar follows — the label predicts the page, and the heading
			// may elaborate on it, but it may not contradict it.
			'label' => __( 'Billing', 'learnomy' ),
			'icon'  => 'receipt',
			'url'   => route_url( 'account-billing' ),
		),
		array(
			'key'   => 'account-details',
			'label' => __( 'Account details', 'learnomy' ),
			'icon'  => 'settings',
			'url'   => route_url( 'account-details' ),
		),
	);

	// Memberships — active-subscription management. Shown when the membership
	// module is enabled, and also to a member who still has a live subscription
	// after the owner switched it off, because `membership-manage` keeps
	// rendering for them (Routes `keeps_commitment`). The nav and the route must
	// stay in lockstep either way. Slots after Order history.
	if ( module_enabled_for_user( 'membership' ) ) {
		$items[] = array(
			'key'   => 'membership-manage',
			'label' => __( 'Memberships', 'learnomy' ),
			'icon'  => 'repeat',
			'url'   => route_url( 'membership-manage' ),
		);
	}

	// Quiz Results — the learner's attempt history + per-attempt review, shown
	// only when the quizzes module is enabled (the route 404s when off, so the
	// menu must hide in lockstep).
	if ( module_enabled( 'quizzes' ) ) {
		$items[] = array(
			'key'   => 'account-quiz-results',
			'label' => __( 'Quiz Attempts', 'learnomy' ),
			'icon'  => 'clipboard-check',
			'url'   => route_url( 'account-quiz-results' ),
		);
	}

	// Certificates — a dedicated section, shown only when the certificates
	// module is enabled (the dashboard no longer carries a certificates widget).
	if ( module_enabled( 'certificates' ) ) {
		$items[] = array(
			'key'   => 'account-certificates',
			'label' => __( 'Certificates', 'learnomy' ),
			'icon'  => 'award',
			'url'   => route_url( 'account-certificates' ),
		);
	}

	// Instructors get a link to their teaching dashboard.
	$user = wp_get_current_user();
	if ( $user instanceof \WP_User && $user->ID
		&& ( in_array( 'lrn_instructor', (array) $user->roles, true )
			|| in_array( 'learnomy_instructor', (array) $user->roles, true )
			|| user_can( $user, 'manage_options' ) ) ) {
		$items[] = array(
			'key'   => 'instructor-dashboard',
			'label' => __( 'Instructor', 'learnomy' ),
			'icon'  => 'presentation',
			'url'   => route_url( 'instructor-dashboard' ),
		);
		$items[] = array(
			'key'   => 'account-instructor-profile',
			'label' => __( 'Instructor profile', 'learnomy' ),
			'icon'  => 'id-card',
			'url'   => route_url( 'account-instructor-profile' ),
		);
	}

	/**
	 * Filter the account navigation items. Pro adds its account surfaces here
	 * (Spaces, Gift, Learning Paths, Certificates) so the whole area stays
	 * linked. Each item: key, label, icon (Lucide name), url.
	 *
	 * @param array  $items   Ordered nav items.
	 * @param string $current Active route key.
	 */
	$items = (array) apply_filters( 'learnomy_account_nav_items', $items, $current );

	// Log out is always the final item.
	$items[] = array(
		'key'   => 'logout',
		'label' => __( 'Log out', 'learnomy' ),
		'icon'  => 'log-out',
		'url'   => wp_logout_url( route_url( 'catalog' ) ),
	);

	// Group items into labelled sections so the rail reads as an organised
	// app-shell ("Learning" / "Account" / "Teaching") instead of one long flat
	// list. Keyed by item key so Pro-added items (Spaces, Paths, Cohorts,
	// Assignments, Gift, Notes) land in the right section with no Pro change.
	// Anything unmapped falls into 'account' so a new item is never orphaned.
	$section_by_key = array(
		'student-dashboard'          => 'learning',
		'account-courses'            => 'learning',
		'account-quiz-results'       => 'learning',
		'account-paths'              => 'learning',
		'account-cohorts'            => 'learning',
		'account-assignments'        => 'learning',
		'account-certificates'       => 'learning',
		'account-notes'              => 'learning',
		'my-spaces'                  => 'learning',
		'account-billing'            => 'account',
		'membership-manage'          => 'account',
		'account-details'            => 'account',
		'gift'                       => 'account',
		'instructor-dashboard'       => 'teaching',
		'account-instructor-profile' => 'teaching',
		'logout'                     => '',
	);

	/**
	 * Filter the account-nav item -> section map, so a developer can move an
	 * item into a different section (or place their own item). Section labels +
	 * order are filterable too — see `learnomy_account_nav_sections` in
	 * templates/partials/account-nav.php.
	 *
	 * @param array<string,string> $section_by_key Item key -> section key.
	 */
	$section_by_key = (array) apply_filters( 'learnomy_account_nav_section_map', $section_by_key );

	// Flag the active item + tag its section for the template.
	foreach ( $items as $i => $item ) {
		$key                    = (string) ( $item['key'] ?? '' );
		$items[ $i ]['current'] = ( $key === $current );
		$items[ $i ]['section'] = $section_by_key[ $key ] ?? ( 'logout' === $key ? '' : 'account' );
	}

	return $items;
}

/**
 * Items for the instructor area's left rail (the teaching shell at
 * /instructor/...). Parallel to {@see account_nav_items} but for the teaching
 * context — it reuses the same `.lrn-account-nav` panel styling. Management
 * sections (Reports, Students, Earnings, Grading) attach via the
 * `learnomy_instructor_nav_items` filter as each front-end section ships, so a
 * never-built section is never a dead menu row.
 *
 * @param string $current Active route key (for highlight).
 * @return array<int, array<string, mixed>>
 */
function instructor_nav_items( string $current = '' ): array {
	$items = array(
		array(
			'key'   => 'instructor-dashboard',
			'label' => __( 'Dashboard', 'learnomy' ),
			'icon'  => 'layout-dashboard',
			'url'   => route_url( 'instructor-dashboard' ),
		),
		array(
			'key'   => 'instructor-courses',
			/* translators: %s: the plural entity label, e.g. "Courses" or "Programs". */
			'label' => sprintf( __( 'My %s', 'learnomy' ), course_label( true ) ),
			'icon'  => 'graduation-cap',
			'url'   => route_url( 'instructor-courses' ),
		),
		array(
			'key'   => 'instructor-students',
			'label' => student_label( true ),
			'icon'  => 'users',
			'url'   => route_url( 'instructor-students' ),
		),
		array(
			// Manual grading, in the teaching shell. Shown whenever quizzes are
			// on — not only when something is pending — because a teacher who
			// just graded still needs a way back to what they graded, and a row
			// that vanishes the moment the queue empties reads as a bug.
			// Hidden with the quizzes module, whose absence also 404s the route.
			'key'   => 'instructor-grading',
			'label' => __( 'Grading', 'learnomy' ),
			'icon'  => 'clipboard-check',
			'url'   => route_url( 'instructor-grading' ),
		),
		array(
			'key'   => 'instructor-earnings',
			'label' => __( 'Earnings', 'learnomy' ),
			'icon'  => 'wallet',
			'url'   => route_url( 'instructor-earnings' ),
		),
		array(
			// This routes to the profile EDIT form, so it was mislabelled
			// "Public profile" (which implies the live /instructor/<login>/
			// page). Relabel to match where it goes; the edit form itself links
			// out to the public profile.
			'key'   => 'account-instructor-profile',
			'label' => __( 'Edit profile', 'learnomy' ),
			'icon'  => 'id-card',
			'url'   => route_url( 'account-instructor-profile' ),
		),
	);

	/**
	 * Filter instructor rail items. Front-end teaching sections (Reports,
	 * Students, Earnings, Grading) register here as they ship. Each item:
	 * key, label, icon (Lucide), url.
	 *
	 * @param array  $items   Ordered nav items.
	 * @param string $current Active route key.
	 */
	// Drop rows whose route is bound to a disabled module — the Router 404s
	// those paths, so leaving the row is a link to a dead page.
	$items = array_values(
		array_filter(
			$items,
			static function ( $item ) {
				$module = Routes::module_for( (string) ( $item['key'] ?? '' ) );
				return null === $module || module_enabled( $module );
			}
		)
	);

	$items = (array) apply_filters( 'learnomy_instructor_nav_items', $items, $current );

	// A way back to the learner area is always the final item.
	$items[] = array(
		'key'   => 'account',
		'label' => __( 'My Account', 'learnomy' ),
		'icon'  => 'circle-user',
		'url'   => route_url( 'student-dashboard' ),
		'back'  => true,
	);

	foreach ( $items as $i => $item ) {
		$items[ $i ]['current'] = ( ( $item['key'] ?? '' ) === $current );
	}

	return $items;
}

/**
 * Breadcrumb items for an account-area page.
 *
 * The root links to the account home (My Courses / student-dashboard), NOT the
 * public site home — the account area is user-specific, so "up" means back to
 * the account hub, keeping the user in context (and not dead-ending the app
 * WebView).
 *
 * @param string $current_label Label for the current page. Pass '' on the
 *                              account home itself (renders a single crumb).
 * @return array<int, array<string, mixed>>
 */
function account_breadcrumb( string $current_label = '' ): array {
	$root = array(
		'label' => __( 'My Account', 'learnomy' ),
		'icon'  => 'circle-user',
	);
	if ( '' === $current_label ) {
		return array( $root );
	}
	$root['href'] = route_url( 'student-dashboard' );
	return array( $root, array( 'label' => $current_label ) );
}

/**
 * Email a confirmation link for a pending account-email change to the NEW
 * address. The change only takes effect when that link is clicked, so an email
 * is never switched without proof the user controls the new mailbox.
 *
 * @param int    $user_id   User changing their email.
 * @param string $new_email The proposed new address.
 * @param string $token     Plain confirmation token (its hash is stored in meta).
 */
function send_email_change_confirmation( int $user_id, string $new_email, string $token ): void {
	$url = add_query_arg(
		array(
			'lrn_confirm_email' => rawurlencode( $token ),
			'uid'               => $user_id,
		),
		route_url( 'account-details' )
	);

	$subject = __( 'Confirm your new email address', 'learnomy' );
	$message = sprintf(
		/* translators: %s: confirmation URL */
		__( "Confirm this as the new email for your account by opening this link:\n\n%s\n\nIf you didn't request this change, you can ignore this email.", 'learnomy' ),
		esc_url_raw( $url )
	);

	wp_mail( $new_email, $subject, $message );
}

/**
 * FAQ rows for the membership pricing page.
 *
 * Admin-editable via Settings → Membership Page (stored in
 * learnomy_settings['membership_faq'] as an array of {q, a}). Falls back to
 * the built-in defaults when the admin hasn't set any. Filterable.
 *
 * @return array<int, array{q:string,a:string}>
 */
function membership_faq_items(): array {
	$settings = get_option( 'learnomy_settings', array() );
	$faq      = ( isset( $settings['membership_faq'] ) && is_array( $settings['membership_faq'] ) )
		? $settings['membership_faq']
		: array();

	if ( empty( $faq ) ) {
		$faq = array(
			array(
				'q' => __( 'Can I cancel anytime?', 'learnomy' ),
				'a' => __( 'Yes, cancel anytime. You keep access until the end of your paid period.', 'learnomy' ),
			),
			array(
				'q' => __( 'Do you offer a refund?', 'learnomy' ),
				'a' => __( 'Contact support within 14 days of purchase for a full refund.', 'learnomy' ),
			),
			array(
				'q' => __( 'Can I switch plans?', 'learnomy' ),
				'a' => __( 'Yes, subscribers can upgrade or downgrade from the Manage Membership page.', 'learnomy' ),
			),
		);
	}

	/**
	 * Filter the membership FAQ rows. Each row: { q, a }.
	 *
	 * @param array $faq FAQ rows (admin-set or defaults).
	 */
	return (array) apply_filters( 'learnomy_membership_faq_items', $faq );
}


function now(): string {
	return current_time( 'mysql', true );
}

/**
 * Is this stored datetime the MySQL zero-date sentinel?
 *
 * `0000-00-00 00:00:00` is what a `datetime NOT NULL DEFAULT '0000-00-00
 * 00:00:00'` column holds when nobody set it, and 94 columns across Free and
 * Pro are declared exactly that way. It is a nasty value to test for, because
 * every intuitive check gets it wrong:
 *
 *   empty( $v )        → false. The string is non-empty.
 *   strtotime( $v )    → -62169984000. NEGATIVE, and therefore TRUTHY.
 *
 * So the natural guard `$ts = strtotime( $v ); echo $ts ? date_i18n( $ts ) :
 * '-';` formats it happily and prints **"November 30, -0001"** (Basecamp
 * 10184459033). The same value read as "long expired" made a coupon whose
 * expiry was left blank - the way you say "never expires" - get refused at
 * checkout (Basecamp 10163002840).
 *
 * That second case was solved once, on `Coupon::is_zero_date()`, where nothing
 * else could find it. This is that predicate promoted to where every reader can
 * reach it; the model method now delegates here.
 *
 * @param string|null $value Raw column value.
 */
function is_zero_date( ?string $value ): bool {
	$value = null === $value ? '' : trim( $value );

	return '' !== $value && 0 === strncmp( $value, '0000-00-00', 10 );
}

/**
 * A stored datetime rendered for a human, or a fallback when there is no date.
 *
 * THE renderer for any `*_at` column an owner reads. Four different absences -
 * NULL, empty string, the zero-date sentinel, and a timestamp that resolves to
 * a non-positive value - all mean the same thing to a reader ("we don't have
 * one") and must all produce the same dash. Every surface that guarded only
 * some of them eventually met one of the others.
 *
 * The `> 0` test is the load-bearing part: `strtotime( '0000-00-00 00:00:00' )`
 * is truthy, so a bare `$ts ? … : '-'` is exactly the bug this replaces.
 *
 * @param string|null $value    Raw column value.
 * @param string      $fallback Shown when there is no usable date.
 * @param string|null $format   `date_i18n()` format; site date format by default.
 */
function date_label( ?string $value, string $fallback = '—', ?string $format = null ): string {
	$value = null === $value ? '' : trim( $value );

	if ( '' === $value || is_zero_date( $value ) ) {
		return $fallback;
	}

	$timestamp = strtotime( $value );
	if ( false === $timestamp || $timestamp <= 0 ) {
		return $fallback;
	}

	return date_i18n( $format ?? (string) get_option( 'date_format' ), $timestamp );
}

/**
 * Render Editor.js JSON content for frontend display.
 *
 * Two render modes:
 *   - 'html' (default) — full block markup; what the lesson body, course
 *     description, comment, etc. surfaces want. Output is sanitized via
 *     `wp_kses_post()` per block.
 *   - 'plain' — collapsed plain text suitable for inline contexts where
 *     block-level markup is invalid (`<p>`, ARIA descriptions, meta tags).
 *     Code blocks preserve their newlines; lists become "• item" lines;
 *     no `<pre>`/`<ul>`/etc. ever appears in the output.
 *
 * Callers that previously wrapped the output in `wp_strip_all_tags()` should
 * pass `mode='plain'` instead — strip-after-the-fact destroys code-block
 * newlines and other structure that plain mode preserves.
 *
 * @param string $content    Raw content — either Editor.js JSON or HTML.
 * @param string $skip_title Optional H1/H2 text to skip if the first block matches.
 * @param string $mode       Either 'html' (default) or 'plain'.
 * @return string Rendered HTML or plain text per mode.
 */
/**
 * Whether a block-editor field actually holds renderable content.
 *
 * The shared Editor.js field serializes an *empty* editor to `{"blocks":[]}`,
 * a non-empty string that nonetheless renders nothing. A plain `'' !== trim()`
 * check treats that as content and emits an empty container. This helper
 * returns false for an empty string AND for an empty-blocks document, while
 * still treating legacy HTML / plain text as content.
 *
 * @param mixed $raw Stored field value (Editor.js JSON, legacy HTML, or '').
 * @return bool True when there is something to render.
 */
function editorjs_has_content( $raw ): bool {
	$raw = trim( (string) $raw );
	if ( '' === $raw ) {
		return false;
	}
	if ( '{' === $raw[0] ) {
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) && isset( $decoded['blocks'] ) && is_array( $decoded['blocks'] ) ) {
			return count( $decoded['blocks'] ) > 0;
		}
	}
	return true; // Legacy HTML / plain text.
}

/**
 * Render a raw-HTML lesson body — the Editor.js `raw` block and the legacy-HTML
 * fallback in {@see render_editorjs_content()}.
 *
 * Two things the plain `wp_kses_post()` it replaces could not do:
 *   1. Expand shortcodes — `[sproutvideo]`, `[embed]`, gallery shortcodes, etc.
 *      run through `do_shortcode()` so they render instead of printing literally.
 *   2. Keep embed `<iframe>`s — `wp_kses_post()`'s `post` context strips
 *      `<iframe>` outright, so a pasted SproutVideo/Vimeo/YouTube/Wistia embed
 *      vanished. The allowlist below is `post` + a locked-down `<iframe>`.
 *
 * Shortcodes expand FIRST so the markup they emit (usually an iframe) is what the
 * allowlist sanitises. The allowed-HTML map is filterable via
 * `learnomy_lesson_html_allowed_html` (mirrors `learnomy_block_allowed_html`) so a
 * site can widen or tighten it — e.g. drop iframes on a hardened install, or add
 * a `<video>`/`<source>` pair.
 *
 * @param string $raw Raw lesson HTML (legacy import or Editor.js raw block).
 * @return string Sanitised, shortcode-expanded HTML.
 */
/**
 * Wrap a raw-HTML body in an Editor.js document so the block editor can load it.
 *
 * Learnomy stores `lrn_lessons.content` as an Editor.js block document. Raw HTML
 * (a foreign LMS lesson body, a theme override) stored verbatim `json_decode()`s
 * to null, so the wp-admin block editor shows a blank body. Wrapping the whole
 * body in ONE `raw` block makes it editable (bundled RawTool) and rendered by the
 * `case 'raw'` branch of {@see render_editorjs_content()} — lossless for arbitrary
 * markup + shortcodes.
 *
 * Idempotent: an existing Editor.js document (or empty content) is returned
 * unchanged, so re-imports and repeat repair runs are safe.
 *
 * Single source of truth for the conversion — the migration importer and
 * `wp learnomy repair lesson-content` both call this.
 *
 * @param string $html Raw HTML body.
 * @return string Editor.js JSON document, or '' for an empty body.
 */
/**
 * Flatten WordPress block markup (`<!-- wp:* -->`) to its rendered HTML, for
 * migrated content that was authored in Gutenberg.
 *
 * Source LMS lesson/course bodies are WordPress `post_content`, so they may
 * contain block comments. Learnomy renders lesson bodies through its own
 * Editor.js / raw-HTML pipeline ({@see render_lesson_raw_html()}), which never
 * runs `the_content` — so a `<!-- wp:embed -->` block (YouTube/Vimeo/…) would be
 * stored as a bare URL and never become an `<iframe>`. Resolve the blocks ONCE
 * at import time:
 *   1. `do_blocks()` renders block structure to final HTML (core + any blocks the
 *      source plugin registers — its plugin is active during an in-place migration).
 *   2. `WP_Embed::autoembed()` converts the bare provider URLs that embed blocks
 *      (and plain pasted links) leave behind into the provider `<iframe>`
 *      (do_blocks alone does NOT do this — it is a `the_content` step).
 * The resulting iframe then survives `render_lesson_raw_html()`'s iframe-allowing
 * kses. No-op for non-block plain HTML, so it is safe on every source + on repair.
 *
 * @param string $content Raw WordPress post_content.
 * @return string Rendered HTML.
 */
function render_wp_blocks( string $content ): string {
	if ( '' === trim( $content ) ) {
		return '';
	}
	if ( function_exists( 'has_blocks' ) && has_blocks( $content ) && function_exists( 'do_blocks' ) ) {
		// Convert core/embed blocks to the uniform Learnomy embed placeholder while
		// they still carry the original (oEmbeddable) URL — so imported embeds
		// render through the one Embed renderer at display, just like authored ones,
		// instead of flattening to a bare iframe the hydrator can't recognise.
		$content = \Learnomy\Content\Embed::blocks_to_placeholders( $content );
		$content = do_blocks( $content );
	}
	global $wp_embed;
	if ( $wp_embed instanceof \WP_Embed ) {
		// run_shortcode handles [embed] tags; autoembed handles bare provider URLs.
		$content = $wp_embed->run_shortcode( $content );
		$content = $wp_embed->autoembed( $content );
	}
	return $content;
}

function html_to_editorjs_document( string $html ): string {
	$html = trim( $html );
	if ( '' === $html ) {
		return '';
	}

	// Already an Editor.js document — leave it alone.
	if ( '{' === $html[0] ) {
		$decoded = json_decode( $html, true );
		if ( is_array( $decoded ) && isset( $decoded['blocks'] ) && is_array( $decoded['blocks'] ) ) {
			return $html;
		}
	}

	return (string) wp_json_encode(
		array(
			'time'    => 0,
			'blocks'  => array(
				array(
					'type' => 'raw',
					'data' => array( 'html' => $html ),
				),
			),
			'version' => 'migration',
		)
	);
}

/**
 * Render a raw-HTML lesson body — the Editor.js `raw` block and the legacy-HTML
 * fallback in {@see render_editorjs_content()}.
 *
 * Two things the plain `wp_kses_post()` it replaces could not do:
 *   1. Expand shortcodes — `[sproutvideo]`, `[embed]`, gallery shortcodes, etc.
 *      run through `do_shortcode()` so they render instead of printing literally.
 *   2. Keep embed `<iframe>`s — `wp_kses_post()`'s `post` context strips
 *      `<iframe>` outright, so a pasted SproutVideo/Vimeo/YouTube/Wistia embed
 *      vanished. The allowlist below is `post` + a locked-down `<iframe>`.
 *
 * Shortcodes expand FIRST so the markup they emit (usually an iframe) is what the
 * allowlist sanitises. The allowed-HTML map is filterable via
 * `learnomy_lesson_html_allowed_html` (mirrors `learnomy_block_allowed_html`) so a
 * site can widen or tighten it — e.g. drop iframes on a hardened install, or add
 * a `<video>`/`<source>` pair.
 *
 * @param string $raw Raw lesson HTML (legacy import or Editor.js raw block).
 * @return string Sanitised, shortcode-expanded HTML.
 */
function render_lesson_raw_html( string $raw ): string {
	if ( '' === trim( $raw ) ) {
		return '';
	}

	// VIEW-time renderer for the legacy-HTML branch of render_editorjs_content()
	// (pre-block imported HTML, and HTML-first stored content). Order matters:
	// sanitise the raw HTML FIRST (strips any script/onclick the author typed),
	// THEN do_shortcode() so a form/embed shortcode's OWN output (which legitimately
	// includes <script> — FluentForms, WPForms, etc.) is NOT kses-stripped. This is
	// exactly how WordPress core the_content works: shortcodes are trusted plugin
	// output and run after kses, never through it. Doing it the other way round
	// leaves the form's inline JS on the page as visible text.
	// Editor.js `raw` blocks still call sanitize_lesson_raw_html() at STORAGE time
	// (no expansion) and rely on the single view-time do_shortcode() in
	// render_editorjs_content(), so the shortcode stays literal text in the editor.
	return do_shortcode( sanitize_lesson_raw_html( $raw ) );
}

/**
 * Sanitise raw lesson HTML (Editor.js `raw` block, or legacy import) WITHOUT
 * expanding shortcodes. The storage path uses this so a `[fluentform]`,
 * `[user_meta]` (WP Fusion), gallery, or any third-party shortcode is stored as
 * literal text: the editor shows the shortcode, and only the view-time
 * do_shortcode() in render_editorjs_content() renders it. Same allowed-HTML set
 * as render_lesson_raw_html() (post + iframe + embed placeholder), filterable
 * via `learnomy_lesson_html_allowed_html`.
 *
 * @param string $raw Raw lesson HTML.
 * @return string Sanitised HTML with shortcode text preserved.
 */
function sanitize_lesson_raw_html( string $raw ): string {
	if ( '' === trim( $raw ) ) {
		return '';
	}

	$allowed           = wp_kses_allowed_html( 'post' );
	$allowed['iframe'] = array(
		'src'             => true,
		'srcdoc'          => true,
		'width'           => true,
		'height'          => true,
		'frameborder'     => true,
		'marginwidth'     => true,
		'marginheight'    => true,
		'scrolling'       => true,
		'allow'           => true,
		'allowfullscreen' => true,
		'loading'         => true,
		'referrerpolicy'  => true,
		'sandbox'         => true,
		'name'            => true,
		'title'           => true,
		'class'           => true,
		'id'              => true,
		'style'           => true,
	);
	// Keep the embed placeholder's data-embed-url so Embed::hydrate() can resolve
	// HTML-stored embeds through the one renderer (it is stripped otherwise).
	$allowed['div'] = (array) ( $allowed['div'] ?? array() ) + array( 'data-embed-url' => true );

	/**
	 * Filter the allowed-HTML set used to sanitise raw lesson bodies (legacy
	 * imported HTML and Editor.js `raw` blocks). Same shape `wp_kses()` expects.
	 *
	 * @param array<string,array<string,bool>> $allowed Allowed-HTML map (post + iframe).
	 * @param string                            $raw     The HTML being sanitised (post-shortcode).
	 */
	$allowed = apply_filters( 'learnomy_lesson_html_allowed_html', $allowed, $raw );

	return wp_kses( $raw, $allowed );
}

/**
 * Prepare a question's rich stem (the dedicated `stem` column — A3) for storage.
 *
 * The stem is an optional passage / code / image shown above the answers, for ANY
 * question type (it lives in its own column, never overlapping fill_blank's token
 * body in `content`). Routes through the ONE content engine so it stores as
 * canonical semantic HTML — round-trip-safe + rendered identically to
 * lessons/courses. The `learnomy_store_content_as_html` kill-switch falls back to
 * wp_kses_post.
 *
 * @param string $raw Unslashed raw stem from the request (Editor.js JSON or HTML).
 * @return string Canonical HTML (or kses'd plain when the cutover is off).
 */
function prepare_question_stem( string $raw ): string {
	if ( ! \Learnomy\Content\Content_HTML::storage_enabled() ) {
		return wp_kses_post( $raw );
	}
	return \Learnomy\Content\Content_HTML::for_storage( $raw );
}

/**
 * Drop a leading h1/h2/h3 from HTML content when it just repeats the title.
 *
 * The HTML twin of the block-branch skip inside {@see render_editorjs_content()}
 * — same rule, same reason: the template already renders the title as the page
 * heading, so a body that opens by repeating it shows the headline twice and
 * puts two competing heading levels at the top of the document for a screen
 * reader.
 *
 * Deliberately narrow. Only the FIRST element is considered, only h1 to h3
 * (h4 and below are subheadings, not a title echo), and only an exact
 * case-insensitive text match is removed, so a real section heading that merely
 * starts the lesson is never eaten.
 *
 * @param string $content Raw HTML content.
 * @param string $title   Title the page already renders. Empty disables the skip.
 * @return string
 */
function strip_leading_title_heading( string $content, string $title ): string {
	$title = trim( wp_strip_all_tags( $title ) );

	if ( '' === $title || '' === trim( $content ) ) {
		return $content;
	}

	return (string) preg_replace_callback(
		'/^\s*<h([1-3])\b[^>]*>(.*?)<\/h\1>\s*/is',
		static function ( array $m ) use ( $title ): string {
			$heading = trim( wp_strip_all_tags( $m[2] ) );
			return 0 === strcasecmp( $heading, $title ) ? '' : $m[0];
		},
		$content,
		1
	);
}

function render_editorjs_content( string $content, string $skip_title = '', string $mode = 'html' ): string {
	if ( empty( $content ) ) {
		return '';
	}

	$data = json_decode( $content, true );

	// Not valid JSON or no blocks — treat as legacy HTML (e.g. content imported
	// from LearnDash/LifterLMS/Tutor before it was wrapped in blocks, or a theme
	// override that stored plain HTML). Route the HTML branch through
	// render_lesson_raw_html() so shortcodes expand and embed iframes survive.
	if ( ! is_array( $data ) || ! isset( $data['blocks'] ) ) {
		// $skip_title has to be honoured here too. The block branch below has
		// dropped a leading heading matching the page title since it was
		// written, and every lesson template passes the title in for exactly
		// that reason — but this branch returned before reading it, so any
		// lesson stored as HTML rendered its title twice: once as the page h1,
		// then again as the first line of the body. 52 of 1365 lessons on the
		// reference install do this (Basecamp 10167449585).
		$content = strip_leading_title_heading( $content, $skip_title );

		return 'plain' === $mode
			? trim( wp_strip_all_tags( $content ) )
			: \Learnomy\Content\Embed::hydrate( render_lesson_raw_html( $content ) );
	}

	if ( 'plain' === $mode ) {
		return render_editorjs_blocks_plain( (array) $data['blocks'], $skip_title );
	}

	$html          = '';
	$skipped_first = empty( $skip_title );

	foreach ( $data['blocks'] as $block ) {
		// Skip the first header block if its text matches the page title
		// to avoid duplicate headings (template already renders an h1).
		if ( ! $skipped_first && 'header' === ( $block['type'] ?? '' ) ) {
			$block_text = wp_strip_all_tags( $block['data']['text'] ?? '' );
			if ( strcasecmp( trim( $block_text ), trim( $skip_title ) ) === 0 ) {
				$skipped_first = true;
				continue;
			}
			$skipped_first = true;
		}

		// The block→HTML mapping is the single source of truth in
		// Content_HTML::block_to_html() (empty-state guard for blank text-only
		// blocks lives there). This loop keeps only the render-time concern that
		// the engine can't know about: the title-skip above. The engine is the
		// HTML-first content-model linchpin; this delegation removes the former
		// duplicate switch so there is exactly one renderer.
		$html .= \Learnomy\Content\Content_HTML::block_to_html( (array) $block );
	}

	// Run shortcodes on the assembled block HTML so a shortcode placed in a
	// paragraph/list block executes (e.g. [user_meta] from WP Fusion), matching
	// the legacy-HTML branch above which expands them via render_lesson_raw_html().
	// The two branches are mutually exclusive, so this never double-runs.
	$html = do_shortcode( $html );

	// Resolve content-body embed placeholders through the ONE Embed renderer
	// (oEmbed + premium component + card fallback) at display time — same look as
	// the lesson-level embed type. Storage/round-trip is untouched (Embed::hydrate
	// only runs on rendered output; block_to_html stays a deterministic fixpoint).
	return \Learnomy\Content\Embed::hydrate( $html );
}

/**
 * Plain-text form of stored content for seeding a plain editing field
 * (textarea / single-line input).
 *
 * `render_editorjs_content( …, 'plain' )` is tuned for machine output (SEO meta,
 * FULLTEXT, excerpts) and keeps HTML entities (`&amp;`). Feeding that straight
 * into a field value double-encodes once more (`esc_textarea` → `&amp;amp;`) or,
 * via a JS `data-wp-bind--value`, shows the literal `&amp;`. Decode the entities
 * so the author sees real characters (`R&D`, not `R&amp;D`). The caller still
 * escapes for its own context (`esc_textarea` / JSON).
 *
 * @param string $stored Stored content (Editor.js JSON, semantic HTML, or text).
 * @return string Decoded plain text.
 */
function editor_plain_text( string $stored ): string {
	return html_entity_decode(
		wp_strip_all_tags( render_editorjs_content( $stored, '', 'plain' ) ),
		ENT_QUOTES,
		'UTF-8'
	);
}

/**
 * Tag-stripped projection of stored content for the FULLTEXT search columns
 * (`lrn_courses.description_plain`, `lrn_lessons.content_plain`).
 *
 * ONE derivation, for every write path. It used to live at the individual save
 * sites instead: the REST course controller derived `description_plain` and
 * nothing else did, so a course created by CLI, the demo seeder, an importer or
 * the LearnDash migrator shipped with an empty projection and stayed invisible
 * to description search forever (Basecamp 10134139389) — the same shape that
 * left `instructors_json` stale. The derivation now belongs to the model layer
 * ({@see \Learnomy\Models\Model::search_projections()}), which every write
 * funnels through, and this is the single function it calls.
 *
 * Handles both storage shapes: Editor.js block JSON (legacy rows) and canonical
 * semantic HTML (post-cutover). Block boundaries become a space rather than
 * nothing, so `<p>audit</p><p>trail</p>` indexes as two searchable words instead
 * of the unsearchable "audittrail".
 *
 * @param string $stored Stored content (Editor.js JSON, semantic HTML, or text).
 * @return string Plain text, safe to store in a FULLTEXT-indexed column.
 */
function search_text_projection( string $stored ): string {
	if ( '' === trim( $stored ) ) {
		return '';
	}

	// Editor.js JSON is already collapsed block-by-block by the renderer; raw
	// HTML falls through its legacy branch straight into strip_tags, which
	// concatenates adjacent blocks. Separate the boundaries first. Running this
	// on JSON only ever inserts a space inside a block's own text, which is
	// harmless (and still valid JSON).
	$spaced = preg_replace(
		'#<(?:br|/(?:p|div|li|ul|ol|h[1-6]|tr|td|th|blockquote|pre|figure|figcaption|section|article))\b[^>]*>#i',
		' $0',
		$stored
	);

	$plain = wp_strip_all_tags( render_editorjs_content( (string) $spaced, '', 'plain' ) );

	return trim( (string) preg_replace( '/\s+/u', ' ', $plain ) );
}

/**
 * Collapse an Editor.js block list to a plain-text string for inline contexts
 * (e.g. live-lesson countdown notes, ARIA descriptions, meta tags) where
 * block-level markup is invalid.
 *
 * Each block contributes one or more lines:
 *   paragraph / header / quote → text on its own line
 *   list / checklist           → each item prefixed with "• " (ordered keeps the number)
 *   code                       → raw `$d['code']` with newlines PRESERVED
 *   warning                    → "title: message"
 *   delimiter                  → "---"
 *   image / embed / attaches   → caption or filename, skipped if absent
 *
 * Output is fully stripped of HTML (so inline tools like `<strong>` inside a
 * paragraph become their textual content) and trimmed. Callers that
 * previously did `wp_strip_all_tags( render_editorjs_content(...) )` lost
 * code-block newlines + list bullets in the strip step; this helper
 * preserves them by formatting per block type rather than rendering HTML
 * first and stripping after.
 *
 * @param array<int, array<string, mixed>> $blocks     Editor.js blocks.
 * @param string                           $skip_title Optional first-header text to skip.
 * @return string Trimmed plain text with `\n` separating logical lines.
 */
function render_editorjs_blocks_plain( array $blocks, string $skip_title = '' ): string {
	$lines         = array();
	$skipped_first = '' === $skip_title;

	foreach ( $blocks as $block ) {
		$type = (string) ( $block['type'] ?? '' );
		$d    = (array) ( $block['data'] ?? array() );

		if ( ! $skipped_first && 'header' === $type ) {
			$header_text = trim( wp_strip_all_tags( (string) ( $d['text'] ?? '' ) ) );
			if ( 0 === strcasecmp( $header_text, trim( $skip_title ) ) ) {
				$skipped_first = true;
				continue;
			}
			$skipped_first = true;
		}

		switch ( $type ) {
			case 'paragraph':
			case 'header':
				$text = trim( wp_strip_all_tags( (string) ( $d['text'] ?? '' ) ) );
				if ( '' !== $text ) {
					$lines[] = $text;
				}
				break;

			case 'list':
			case 'checklist':
				$items   = (array) ( $d['items'] ?? array() );
				$ordered = 'list' === $type && 'ordered' === (string) ( $d['style'] ?? '' );
				$i       = 0;
				foreach ( $items as $item ) {
					$raw  = is_string( $item ) ? $item : (string) ( $item['content'] ?? $item['text'] ?? '' );
					$text = trim( wp_strip_all_tags( $raw ) );
					if ( '' === $text ) {
						continue;
					}
					++$i;
					$prefix  = $ordered ? ( $i . '. ' ) : '• ';
					$lines[] = $prefix . $text;
				}
				break;

			case 'code':
				$code = (string) ( $d['code'] ?? '' );
				if ( '' !== trim( $code ) ) {
					$lines[] = $code;
				}
				break;

			case 'quote':
				$text    = trim( wp_strip_all_tags( (string) ( $d['text'] ?? '' ) ) );
				$caption = trim( wp_strip_all_tags( (string) ( $d['caption'] ?? '' ) ) );
				if ( '' !== $text ) {
					$lines[] = '' !== $caption ? $text . ' — ' . $caption : $text;
				}
				break;

			case 'warning':
				$title   = trim( wp_strip_all_tags( (string) ( $d['title'] ?? '' ) ) );
				$message = trim( wp_strip_all_tags( (string) ( $d['message'] ?? '' ) ) );
				if ( '' !== $title || '' !== $message ) {
					$lines[] = ( '' !== $title && '' !== $message )
						? $title . ': ' . $message
						: $title . $message;
				}
				break;

			case 'delimiter':
				$lines[] = '---';
				break;

			case 'image':
			case 'attaches':
				$caption = trim( wp_strip_all_tags( (string) ( $d['caption'] ?? $d['title'] ?? '' ) ) );
				if ( '' !== $caption ) {
					$lines[] = $caption;
				}
				break;

			case 'embed':
				$caption = trim( wp_strip_all_tags( (string) ( $d['caption'] ?? '' ) ) );
				if ( '' !== $caption ) {
					$lines[] = $caption;
				}
				break;
		}
	}

	return trim( implode( "\n", $lines ) );
}

/**
 * Resolve a user-pasted video URL into provider, id, and an iframe-ready embed URL.
 *
 * Recognized formats (each produces the same provider + canonical embed URL):
 *
 *   YouTube                                     id source
 *   ----------------------------------------  --------------
 *   https://www.youtube.com/watch?v=ID         ?v=
 *   https://m.youtube.com/watch?v=ID           ?v=
 *   https://youtu.be/ID                        first path segment
 *   https://www.youtube.com/embed/ID           path /embed/ID
 *   https://www.youtube.com/shorts/ID          path /shorts/ID
 *   https://www.youtube.com/v/ID               path /v/ID
 *   https://www.youtube-nocookie.com/embed/ID  path /embed/ID
 *
 *   Vimeo
 *   ----------------------------------------
 *   https://vimeo.com/ID                       first path segment
 *   https://vimeo.com/ID/HASH (private link)   first path segment
 *   https://player.vimeo.com/video/ID          last path segment after /video/
 *
 * Falls back to provider=html5 with `src` set when the URL is none of the above
 * (lets the template render a native <video> tag against the URL).
 *
 * @param string $video_url Raw URL stored on the lesson / course.
 * @return array{provider:string,embed_id:string,embed_url:string,src:string}
 *               Empty provider when $video_url is empty.
 */
function resolve_video_url( string $video_url ): array {
	$empty = array(
		'provider'  => '',
		'embed_id'  => '',
		'embed_url' => '',
		'src'       => '',
	);

	$video_url = trim( $video_url );
	if ( '' === $video_url ) {
		return $empty;
	}

	$parsed = wp_parse_url( $video_url );
	$host   = strtolower( $parsed['host'] ?? '' );
	$path   = (string) ( $parsed['path'] ?? '' );

	// YouTube — any of youtube.com, youtu.be, youtube-nocookie.com, m.youtube.com.
	if ( preg_match( '~(^|\.)(youtube\.com|youtu\.be|youtube-nocookie\.com)$~i', $host ) ) {
		$embed_id = '';

		if ( false !== strpos( $host, 'youtu.be' ) ) {
			$embed_id = ltrim( $path, '/' );
		} elseif ( preg_match( '~^/(?:embed|shorts|v)/([^/?#]+)~', $path, $m ) ) {
			$embed_id = $m[1];
		} else {
			parse_str( $parsed['query'] ?? '', $qs );
			$embed_id = isset( $qs['v'] ) ? (string) $qs['v'] : '';
		}

		$embed_id = sanitize_text_field( $embed_id );
		if ( '' === $embed_id ) {
			return $empty;
		}
		return array(
			'provider'  => 'youtube',
			'embed_id'  => $embed_id,
			'embed_url' => 'https://www.youtube.com/embed/' . rawurlencode( $embed_id ),
			'src'       => '',
		);
	}

	// Vimeo — vimeo.com and player.vimeo.com.
	if ( preg_match( '~(^|\.)vimeo\.com$~i', $host ) ) {
		$segments = array_values( array_filter( explode( '/', ltrim( $path, '/' ) ) ) );
		// player.vimeo.com/video/ID — drop the leading "video" segment.
		if ( ! empty( $segments ) && 'video' === $segments[0] ) {
			array_shift( $segments );
		}
		$embed_id = sanitize_text_field( $segments[0] ?? '' );
		if ( '' === $embed_id || ! ctype_digit( $embed_id ) ) {
			return $empty;
		}
		return array(
			'provider'  => 'vimeo',
			'embed_id'  => $embed_id,
			'embed_url' => 'https://player.vimeo.com/video/' . rawurlencode( $embed_id ),
			'src'       => '',
		);
	}

	// Loom share URL → embed URL.
	if ( preg_match( '~(^|\.)loom\.com$~i', $host ) ) {
		$segments = array_values( array_filter( explode( '/', ltrim( $path, '/' ) ) ) );
		$embed_id = sanitize_text_field( end( $segments ) ?: '' );
		if ( '' === $embed_id ) {
			return $empty;
		}
		return array(
			'provider'  => 'loom',
			'embed_id'  => $embed_id,
			'embed_url' => 'https://www.loom.com/embed/' . rawurlencode( $embed_id ),
			'src'       => '',
		);
	}

	// Self-hosted MP4/WebM — let the template render a native <video>.
	return array(
		'provider'  => 'html5',
		'embed_id'  => '',
		'embed_url' => '',
		'src'       => esc_url_raw( $video_url ),
	);
}

/**
 * "Powered by Learnomy" attribution markup for learner-facing pages.
 *
 * Returns an empty string when the `learnomy_show_branding` filter is false —
 * Pro's White Label extension returns false once a site owner enables "Hide
 * branding", which is the whole point of buying white-label. The destination
 * URL is filterable (`learnomy_branding_url`); set it to '' for plain text with
 * no link.
 *
 * @return string Escaped HTML, or '' when branding is hidden.
 */
function powered_by_html(): string {
	/**
	 * Whether to show the Learnomy attribution on the learner-facing site.
	 * Default true; Pro White Label flips it to false when "Hide branding" is on.
	 *
	 * @param bool $show Whether to show the attribution.
	 */
	if ( ! apply_filters( 'learnomy_show_branding', true ) ) {
		return '';
	}

	/**
	 * Destination for the attribution link. Return '' to render plain text.
	 *
	 * @param string $url Default https://learnomy.com.
	 */
	$url   = (string) apply_filters( 'learnomy_branding_url', 'https://learnomy.com' );
	$label = __( 'Powered by Learnomy', 'learnomy' );

	if ( '' !== $url ) {
		return sprintf(
			'<div class="lrn-powered-by"><a href="%s" target="_blank" rel="noopener nofollow">%s</a></div>',
			esc_url( $url ),
			esc_html( $label )
		);
	}

	return '<div class="lrn-powered-by">' . esc_html( $label ) . '</div>';
}

/**
 * Registered course taxonomies for the admin editor + catalog filters.
 *
 * Each entry is a tag `type` (lrn_tags.type) surfaced as its own term picker.
 * Free ships one — "Tags" (type `course`). Site owners and Pro extensions add
 * more (e.g. Level, Format, Topic) via the `learnomy_course_taxonomies` filter
 * WITHOUT touching the editor or the save path: the course editor renders a
 * checklist card per registered taxonomy, and they all post through the same
 * `tag_ids[]` field, which Courses_Controller::sync_course_tags() maps to the
 * course regardless of type. The catalog can consume the same registry to
 * render a facet per taxonomy.
 *
 * @return array<int,array{type:string,label:string,icon?:string,help?:string}>
 */
function course_taxonomies(): array {
	$default = array(
		array(
			'type'  => 'course',
			'label' => __( 'Tags', 'learnomy' ),
			'icon'  => 'tags',
			'help'  => __( 'Tags help students find this course in the catalog.', 'learnomy' ),
		),
	);

	$taxonomies = (array) apply_filters( 'learnomy_course_taxonomies', $default );

	return normalize_taxonomies( $taxonomies );
}

/**
 * Registered lesson taxonomies for the admin editor + large-site filtering.
 *
 * Mirrors {@see course_taxonomies()}. Free ships one — "Tags" — drawing from
 * the SAME shared tag vocabulary as courses (`lrn_tags.type` = `course`), so a
 * "Beginner" tag tags both. Lesson assignments are kept separate from course
 * assignments by `lrn_tag_map.object_type` (`lesson` vs `course`). Site owners
 * and Pro add lesson-specific taxonomies (e.g. Skill, Format) via the
 * `learnomy_lesson_taxonomies` filter without touching the editor or the save
 * path: the lesson editor renders a checklist card per registered taxonomy and
 * they all post through `tag_ids[]`, which Tag::sync_object_tags() maps to the
 * lesson; the Lessons admin list filters by the same tags.
 *
 * @return array<int,array{type:string,label:string,icon?:string,help?:string}>
 */
function lesson_taxonomies(): array {
	$default = array(
		array(
			'type'  => 'lesson',
			'label' => __( 'Tags', 'learnomy' ),
			'icon'  => 'tags',
			'help'  => __( 'Lesson tags group lessons within and across courses so you can find them quickly.', 'learnomy' ),
		),
	);

	$taxonomies = (array) apply_filters( 'learnomy_lesson_taxonomies', $default );

	return normalize_taxonomies( $taxonomies );
}

/**
 * Registered question taxonomies for the question editor + Question Bank
 * filtering. Questions are the highest-cardinality entity, so tags (a
 * cross-cutting label on top of the single `category_id`) are how authors
 * keep a large bank navigable. Reuses the shared tag vocabulary
 * (`lrn_tags.type` = `course`); question assignments are isolated by
 * `lrn_tag_map.object_type` = `question`. Add more via the
 * `learnomy_question_taxonomies` filter.
 *
 * @return array<int,array{type:string,label:string,icon?:string,help?:string}>
 */
function question_taxonomies(): array {
	$default = array(
		array(
			'type'  => 'question',
			'label' => __( 'Tags', 'learnomy' ),
			'icon'  => 'tags',
			'help'  => __( 'Question tags cut across categories so you can find questions in a large bank (e.g. exam year, topic, recurring theme).', 'learnomy' ),
		),
	);

	$taxonomies = (array) apply_filters( 'learnomy_question_taxonomies', $default );

	return normalize_taxonomies( $taxonomies );
}

/**
 * Registered quiz taxonomies for the quiz editor + Quizzes-list filtering.
 * Mirrors the other content types; default one "Tags" vocabulary (type `quiz`).
 * Add more via the `learnomy_quiz_taxonomies` filter.
 *
 * @return array<int,array{type:string,label:string,icon?:string,help?:string}>
 */
function quiz_taxonomies(): array {
	$default = array(
		array(
			'type'  => 'quiz',
			'label' => __( 'Tags', 'learnomy' ),
			'icon'  => 'tags',
			'help'  => __( 'Quiz tags group quizzes (e.g. by exam, difficulty band, or topic) so you can find them in a large bank.', 'learnomy' ),
		),
	);

	$taxonomies = (array) apply_filters( 'learnomy_quiz_taxonomies', $default );

	return normalize_taxonomies( $taxonomies );
}

/**
 * The set of tag `type` values backing a content-type vocabulary, collected
 * from every registered taxonomy ({@see course_taxonomies()} /
 * {@see lesson_taxonomies()} / {@see quiz_taxonomies()} /
 * {@see question_taxonomies()} + any added via the filters). The single
 * allow-list the REST layer validates a tag `type` against so a client can't
 * create a tag in an unregistered vocabulary.
 *
 * @return string[]
 */
function registered_tag_types(): array {
	$types = array();
	foreach ( array_merge( course_taxonomies(), lesson_taxonomies(), quiz_taxonomies(), question_taxonomies() ) as $tax ) {
		$types[] = $tax['type'];
	}
	/**
	 * Extend the allow-list for vocabularies not surfaced by the three core
	 * registries (e.g. quiz tags, or a Pro content type).
	 *
	 * @param string[] $types
	 */
	$types = (array) apply_filters( 'learnomy_registered_tag_types', $types );
	return array_values( array_unique( array_filter( array_map( 'strval', $types ) ) ) );
}

/**
 * Normalise a taxonomy registry so callers can rely on the shape without
 * per-entry guards. Shared by {@see course_taxonomies()} +
 * {@see lesson_taxonomies()}.
 *
 * @param array<int,array<string,mixed>> $taxonomies
 * @return array<int,array{type:string,label:string,icon:string,help:string}>
 */
function normalize_taxonomies( array $taxonomies ): array {
	$clean = array();
	foreach ( $taxonomies as $tax ) {
		if ( empty( $tax['type'] ) ) {
			continue;
		}
		$clean[] = array(
			'type'  => (string) $tax['type'],
			'label' => isset( $tax['label'] ) ? (string) $tax['label'] : ucfirst( (string) $tax['type'] ),
			'icon'  => isset( $tax['icon'] ) ? (string) $tax['icon'] : 'tag',
			'help'  => isset( $tax['help'] ) ? (string) $tax['help'] : '',
		);
	}
	return $clean;
}

/*
 * ─── Asset cache-busting (dev/QA) ───────────────────────────────────────────
 *
 * On a development / QA install the plugin VERSION constant does not change
 * between builds, so a static `?ver=x.y.z` lets browsers keep serving stale
 * JS/CSS after a fix lands. That is the cause of repeated "this was already
 * fixed" bug bounces (Basecamp #290, #9972849611, #9971757970): QA pulls new
 * code but the browser re-runs the cached module. These three filters rewrite
 * the `?ver` of OUR asset URLs (Free + Pro — Free always loads first, so its
 * filter covers Pro's URLs too) to include the source file's modified-time, so
 * every change busts the cache. Classic scripts, styles, AND Interactivity API
 * modules are all covered (script_module_loader_src exists since WP 6.7). It is
 * a no-op for every non-Learnomy URL and on production (WP_DEBUG off), which
 * keeps the clean version for CDN-friendly long-lived caching.
 */

/**
 * Whether asset `?ver` should carry the source file's filemtime. True on any
 * NON-production install (development / QA / local), so a browser never serves
 * stale JS/CSS after a fix — the cause of repeated "already fixed" bounces.
 *
 * Detects dev via, in order: `wp_get_environment_type()` (the canonical WP
 * signal — Local-by-Flywheel reports `local`), the `SCRIPT_DEBUG` / `WP_DEBUG`
 * flags, then a local-style hostname (`.local` / `.test` / `localhost` /
 * `.vap`). Production (env `production`, debug off, public host) keeps the clean
 * version constant for CDN-friendly long-lived caching. Overridable site-wide
 * via the `learnomy_asset_filemtime` filter.
 *
 * @return bool
 */
function is_asset_filemtime_enabled(): bool {
	$on = false;

	// Pre-release builds (alpha/beta/rc/dev) are inherently unstable AND the
	// version string does NOT advance between dev iterations of the same
	// pre-release (1.0.0-beta.1 stays 1.0.0-beta.1 across many builds). A QA /
	// staging server frequently reports env=production with WP_DEBUG off on a
	// public host, so none of the checks below fire there and it keeps serving
	// stale JS/CSS after a fix — the exact recurring "already fixed" bounce.
	// Bust on filemtime for any pre-release version so QA always gets fresh
	// code; a STABLE release (no suffix) falls through to the env checks and
	// keeps the clean CDN-friendly version.
	$version = defined( 'LEARNOMY_VERSION' ) ? (string) LEARNOMY_VERSION : '';
	if ( '' !== $version && preg_match( '/-(?:alpha|beta|rc|dev)|\.dev$/i', $version ) ) {
		$on = true;
	} elseif ( function_exists( 'wp_get_environment_type' ) && 'production' !== wp_get_environment_type() ) {
		$on = true;
	} elseif ( ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) || ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
		$on = true;
	} else {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : '';
		if ( '' !== $host && (
			str_ends_with( $host, '.local' )
			|| str_ends_with( $host, '.test' )
			|| str_ends_with( $host, '.vap' )
			|| false !== strpos( $host, 'localhost' )
		) ) {
			$on = true;
		}
	}

	return (bool) apply_filters( 'learnomy_asset_filemtime', $on );
}

/**
 * Append the source file's modified-time to the `?ver` of a Learnomy /
 * Learnomy Pro asset URL when filemtime busting is enabled. Scoped to our own
 * asset URLs; a no-op for every other URL and on production.
 *
 * Wired to `script_loader_src`, `style_loader_src`, and
 * `script_module_loader_src` so classic scripts, styles, and IAPI modules are
 * all covered with no per-enqueue change.
 *
 * @param mixed $src Full asset URL (may already carry `?ver=`).
 * @return mixed The rewritten URL, or the input unchanged.
 */
function filter_asset_filemtime_ver( $src ) {
	if ( ! is_string( $src ) || '' === $src || ! is_asset_filemtime_enabled() ) {
		return $src;
	}

	$path    = null;
	$version = defined( 'LEARNOMY_VERSION' ) ? LEARNOMY_VERSION : '';

	if ( defined( 'LEARNOMY_URL' ) && defined( 'LEARNOMY_DIR' ) && 0 === strpos( $src, LEARNOMY_URL ) ) {
		$rel  = explode( '?', substr( $src, strlen( LEARNOMY_URL ) ) )[0];
		$path = LEARNOMY_DIR . $rel;
	} elseif ( defined( 'LEARNOMY_PRO_URL' ) && defined( 'LEARNOMY_PRO_DIR' ) && 0 === strpos( $src, LEARNOMY_PRO_URL ) ) {
		$rel     = explode( '?', substr( $src, strlen( LEARNOMY_PRO_URL ) ) )[0];
		$path    = LEARNOMY_PRO_DIR . $rel;
		$version = defined( 'LEARNOMY_PRO_VERSION' ) ? LEARNOMY_PRO_VERSION : $version;
	}

	if ( null === $path || ! is_readable( $path ) ) {
		return $src;
	}

	$mtime = filemtime( $path );
	if ( ! $mtime ) {
		return $src;
	}

	return add_query_arg( 'ver', $version . '.' . $mtime, $src );
}

/**
 * Build a link into the public Learnomy documentation. Keeps admin help text
 * short — a one-line hint plus "Learn more" beats a paragraph of docs pasted
 * into a settings field. White-label sites repoint every link via one filter.
 *
 * @param string $path Optional doc path appended to the docs base (e.g. 'completion-rules').
 */
function docs_url( string $path = '' ): string {
	/**
	 * Filter the documentation base URL.
	 *
	 * @param string $base Default https://learnomy.app/docs/.
	 */
	$base = (string) apply_filters( 'learnomy_docs_url', 'https://learnomy.app/docs/' );
	$path = ltrim( $path, '/' );

	return '' === $path ? $base : trailingslashit( $base ) . $path;
}

/**
 * Certificate templates a user may pick for a course or learning path.
 *
 * Admins (who manage templates) see every active template; instructors see the
 * curated library plus anything an admin granted them. This is the single
 * source for all three picker surfaces — the wp-admin course editor, the Pro
 * front-end course builder, and Pro learning paths — so they never drift.
 *
 * @param int $user_id User building the course/path (usually current user).
 * @return object[] Active, pickable certificate template rows.
 */
function certificate_pick_list( int $user_id ): array {
	$sees_all = $user_id > 0 && Permissions\Permission_Engine::can( $user_id, 'manage_settings' );

	return $sees_all
		? Models\CertificateTemplate::get_active()
		: Models\CertificateTemplate::get_for_instructor( $user_id );
}

/**
 * Every country a buyer can be in, as ISO 3166-1 alpha-2 => name.
 *
 * The complete list, deliberately. A partial one does not fail visibly — it
 * silently excludes customers, and nobody notices because the people it
 * excludes simply cannot buy and go elsewhere. A sibling product shipped 31
 * currencies while claiming to cover every supported one, and an owner in
 * Kenya, Chile, Israel, Bangladesh or Pakistan just could not pick theirs.
 *
 * WooCommerce's list wins when Woo is active, so the two can never disagree on
 * a site running both. Without Woo this is the source, because most sites do
 * not run it and the checkout still has to work.
 *
 * @since 1.8.2
 *
 * @return array<string,string> Country code => localised name.
 */
function countries(): array {
	if ( function_exists( 'WC' ) && WC() && isset( WC()->countries ) ) {
		$wc = WC()->countries->get_countries();
		if ( is_array( $wc ) && ! empty( $wc ) ) {
			/** This filter is documented below. */
			return (array) apply_filters( 'learnomy_countries', $wc );
		}
	}

	$countries = array(
		'AF' => __( 'Afghanistan', 'learnomy' ),
		'AX' => __( 'Åland Islands', 'learnomy' ),
		'AL' => __( 'Albania', 'learnomy' ),
		'DZ' => __( 'Algeria', 'learnomy' ),
		'AS' => __( 'American Samoa', 'learnomy' ),
		'AD' => __( 'Andorra', 'learnomy' ),
		'AO' => __( 'Angola', 'learnomy' ),
		'AI' => __( 'Anguilla', 'learnomy' ),
		'AQ' => __( 'Antarctica', 'learnomy' ),
		'AG' => __( 'Antigua and Barbuda', 'learnomy' ),
		'AR' => __( 'Argentina', 'learnomy' ),
		'AM' => __( 'Armenia', 'learnomy' ),
		'AW' => __( 'Aruba', 'learnomy' ),
		'AU' => __( 'Australia', 'learnomy' ),
		'AT' => __( 'Austria', 'learnomy' ),
		'AZ' => __( 'Azerbaijan', 'learnomy' ),
		'BS' => __( 'Bahamas', 'learnomy' ),
		'BH' => __( 'Bahrain', 'learnomy' ),
		'BD' => __( 'Bangladesh', 'learnomy' ),
		'BB' => __( 'Barbados', 'learnomy' ),
		'BY' => __( 'Belarus', 'learnomy' ),
		'BE' => __( 'Belgium', 'learnomy' ),
		'BZ' => __( 'Belize', 'learnomy' ),
		'BJ' => __( 'Benin', 'learnomy' ),
		'BM' => __( 'Bermuda', 'learnomy' ),
		'BT' => __( 'Bhutan', 'learnomy' ),
		'BO' => __( 'Bolivia', 'learnomy' ),
		'BQ' => __( 'Bonaire, Sint Eustatius and Saba', 'learnomy' ),
		'BA' => __( 'Bosnia and Herzegovina', 'learnomy' ),
		'BW' => __( 'Botswana', 'learnomy' ),
		'BV' => __( 'Bouvet Island', 'learnomy' ),
		'BR' => __( 'Brazil', 'learnomy' ),
		'IO' => __( 'British Indian Ocean Territory', 'learnomy' ),
		'BN' => __( 'Brunei', 'learnomy' ),
		'BG' => __( 'Bulgaria', 'learnomy' ),
		'BF' => __( 'Burkina Faso', 'learnomy' ),
		'BI' => __( 'Burundi', 'learnomy' ),
		'CV' => __( 'Cabo Verde', 'learnomy' ),
		'KH' => __( 'Cambodia', 'learnomy' ),
		'CM' => __( 'Cameroon', 'learnomy' ),
		'CA' => __( 'Canada', 'learnomy' ),
		'KY' => __( 'Cayman Islands', 'learnomy' ),
		'CF' => __( 'Central African Republic', 'learnomy' ),
		'TD' => __( 'Chad', 'learnomy' ),
		'CL' => __( 'Chile', 'learnomy' ),
		'CN' => __( 'China', 'learnomy' ),
		'CX' => __( 'Christmas Island', 'learnomy' ),
		'CC' => __( 'Cocos (Keeling) Islands', 'learnomy' ),
		'CO' => __( 'Colombia', 'learnomy' ),
		'KM' => __( 'Comoros', 'learnomy' ),
		'CG' => __( 'Congo', 'learnomy' ),
		'CD' => __( 'Congo (Democratic Republic)', 'learnomy' ),
		'CK' => __( 'Cook Islands', 'learnomy' ),
		'CR' => __( 'Costa Rica', 'learnomy' ),
		'CI' => __( 'Côte d\'Ivoire', 'learnomy' ),
		'HR' => __( 'Croatia', 'learnomy' ),
		'CU' => __( 'Cuba', 'learnomy' ),
		'CW' => __( 'Curaçao', 'learnomy' ),
		'CY' => __( 'Cyprus', 'learnomy' ),
		'CZ' => __( 'Czechia', 'learnomy' ),
		'DK' => __( 'Denmark', 'learnomy' ),
		'DJ' => __( 'Djibouti', 'learnomy' ),
		'DM' => __( 'Dominica', 'learnomy' ),
		'DO' => __( 'Dominican Republic', 'learnomy' ),
		'EC' => __( 'Ecuador', 'learnomy' ),
		'EG' => __( 'Egypt', 'learnomy' ),
		'SV' => __( 'El Salvador', 'learnomy' ),
		'GQ' => __( 'Equatorial Guinea', 'learnomy' ),
		'ER' => __( 'Eritrea', 'learnomy' ),
		'EE' => __( 'Estonia', 'learnomy' ),
		'SZ' => __( 'Eswatini', 'learnomy' ),
		'ET' => __( 'Ethiopia', 'learnomy' ),
		'FK' => __( 'Falkland Islands', 'learnomy' ),
		'FO' => __( 'Faroe Islands', 'learnomy' ),
		'FJ' => __( 'Fiji', 'learnomy' ),
		'FI' => __( 'Finland', 'learnomy' ),
		'FR' => __( 'France', 'learnomy' ),
		'GF' => __( 'French Guiana', 'learnomy' ),
		'PF' => __( 'French Polynesia', 'learnomy' ),
		'TF' => __( 'French Southern Territories', 'learnomy' ),
		'GA' => __( 'Gabon', 'learnomy' ),
		'GM' => __( 'Gambia', 'learnomy' ),
		'GE' => __( 'Georgia', 'learnomy' ),
		'DE' => __( 'Germany', 'learnomy' ),
		'GH' => __( 'Ghana', 'learnomy' ),
		'GI' => __( 'Gibraltar', 'learnomy' ),
		'GR' => __( 'Greece', 'learnomy' ),
		'GL' => __( 'Greenland', 'learnomy' ),
		'GD' => __( 'Grenada', 'learnomy' ),
		'GP' => __( 'Guadeloupe', 'learnomy' ),
		'GU' => __( 'Guam', 'learnomy' ),
		'GT' => __( 'Guatemala', 'learnomy' ),
		'GG' => __( 'Guernsey', 'learnomy' ),
		'GN' => __( 'Guinea', 'learnomy' ),
		'GW' => __( 'Guinea-Bissau', 'learnomy' ),
		'GY' => __( 'Guyana', 'learnomy' ),
		'HT' => __( 'Haiti', 'learnomy' ),
		'HM' => __( 'Heard Island and McDonald Islands', 'learnomy' ),
		'HN' => __( 'Honduras', 'learnomy' ),
		'HK' => __( 'Hong Kong', 'learnomy' ),
		'HU' => __( 'Hungary', 'learnomy' ),
		'IS' => __( 'Iceland', 'learnomy' ),
		'IN' => __( 'India', 'learnomy' ),
		'ID' => __( 'Indonesia', 'learnomy' ),
		'IR' => __( 'Iran', 'learnomy' ),
		'IQ' => __( 'Iraq', 'learnomy' ),
		'IE' => __( 'Ireland', 'learnomy' ),
		'IM' => __( 'Isle of Man', 'learnomy' ),
		'IL' => __( 'Israel', 'learnomy' ),
		'IT' => __( 'Italy', 'learnomy' ),
		'JM' => __( 'Jamaica', 'learnomy' ),
		'JP' => __( 'Japan', 'learnomy' ),
		'JE' => __( 'Jersey', 'learnomy' ),
		'JO' => __( 'Jordan', 'learnomy' ),
		'KZ' => __( 'Kazakhstan', 'learnomy' ),
		'KE' => __( 'Kenya', 'learnomy' ),
		'KI' => __( 'Kiribati', 'learnomy' ),
		'KW' => __( 'Kuwait', 'learnomy' ),
		'KG' => __( 'Kyrgyzstan', 'learnomy' ),
		'LA' => __( 'Laos', 'learnomy' ),
		'LV' => __( 'Latvia', 'learnomy' ),
		'LB' => __( 'Lebanon', 'learnomy' ),
		'LS' => __( 'Lesotho', 'learnomy' ),
		'LR' => __( 'Liberia', 'learnomy' ),
		'LY' => __( 'Libya', 'learnomy' ),
		'LI' => __( 'Liechtenstein', 'learnomy' ),
		'LT' => __( 'Lithuania', 'learnomy' ),
		'LU' => __( 'Luxembourg', 'learnomy' ),
		'MO' => __( 'Macao', 'learnomy' ),
		'MG' => __( 'Madagascar', 'learnomy' ),
		'MW' => __( 'Malawi', 'learnomy' ),
		'MY' => __( 'Malaysia', 'learnomy' ),
		'MV' => __( 'Maldives', 'learnomy' ),
		'ML' => __( 'Mali', 'learnomy' ),
		'MT' => __( 'Malta', 'learnomy' ),
		'MH' => __( 'Marshall Islands', 'learnomy' ),
		'MQ' => __( 'Martinique', 'learnomy' ),
		'MR' => __( 'Mauritania', 'learnomy' ),
		'MU' => __( 'Mauritius', 'learnomy' ),
		'YT' => __( 'Mayotte', 'learnomy' ),
		'MX' => __( 'Mexico', 'learnomy' ),
		'FM' => __( 'Micronesia', 'learnomy' ),
		'MD' => __( 'Moldova', 'learnomy' ),
		'MC' => __( 'Monaco', 'learnomy' ),
		'MN' => __( 'Mongolia', 'learnomy' ),
		'ME' => __( 'Montenegro', 'learnomy' ),
		'MS' => __( 'Montserrat', 'learnomy' ),
		'MA' => __( 'Morocco', 'learnomy' ),
		'MZ' => __( 'Mozambique', 'learnomy' ),
		'MM' => __( 'Myanmar', 'learnomy' ),
		'NA' => __( 'Namibia', 'learnomy' ),
		'NR' => __( 'Nauru', 'learnomy' ),
		'NP' => __( 'Nepal', 'learnomy' ),
		'NL' => __( 'Netherlands', 'learnomy' ),
		'NC' => __( 'New Caledonia', 'learnomy' ),
		'NZ' => __( 'New Zealand', 'learnomy' ),
		'NI' => __( 'Nicaragua', 'learnomy' ),
		'NE' => __( 'Niger', 'learnomy' ),
		'NG' => __( 'Nigeria', 'learnomy' ),
		'NU' => __( 'Niue', 'learnomy' ),
		'NF' => __( 'Norfolk Island', 'learnomy' ),
		'KP' => __( 'North Korea', 'learnomy' ),
		'MK' => __( 'North Macedonia', 'learnomy' ),
		'MP' => __( 'Northern Mariana Islands', 'learnomy' ),
		'NO' => __( 'Norway', 'learnomy' ),
		'OM' => __( 'Oman', 'learnomy' ),
		'PK' => __( 'Pakistan', 'learnomy' ),
		'PW' => __( 'Palau', 'learnomy' ),
		'PS' => __( 'Palestine', 'learnomy' ),
		'PA' => __( 'Panama', 'learnomy' ),
		'PG' => __( 'Papua New Guinea', 'learnomy' ),
		'PY' => __( 'Paraguay', 'learnomy' ),
		'PE' => __( 'Peru', 'learnomy' ),
		'PH' => __( 'Philippines', 'learnomy' ),
		'PN' => __( 'Pitcairn', 'learnomy' ),
		'PL' => __( 'Poland', 'learnomy' ),
		'PT' => __( 'Portugal', 'learnomy' ),
		'PR' => __( 'Puerto Rico', 'learnomy' ),
		'QA' => __( 'Qatar', 'learnomy' ),
		'RE' => __( 'Réunion', 'learnomy' ),
		'RO' => __( 'Romania', 'learnomy' ),
		'RU' => __( 'Russia', 'learnomy' ),
		'RW' => __( 'Rwanda', 'learnomy' ),
		'BL' => __( 'Saint Barthélemy', 'learnomy' ),
		'SH' => __( 'Saint Helena', 'learnomy' ),
		'KN' => __( 'Saint Kitts and Nevis', 'learnomy' ),
		'LC' => __( 'Saint Lucia', 'learnomy' ),
		'MF' => __( 'Saint Martin (French part)', 'learnomy' ),
		'PM' => __( 'Saint Pierre and Miquelon', 'learnomy' ),
		'VC' => __( 'Saint Vincent and the Grenadines', 'learnomy' ),
		'WS' => __( 'Samoa', 'learnomy' ),
		'SM' => __( 'San Marino', 'learnomy' ),
		'ST' => __( 'Sao Tome and Principe', 'learnomy' ),
		'SA' => __( 'Saudi Arabia', 'learnomy' ),
		'SN' => __( 'Senegal', 'learnomy' ),
		'RS' => __( 'Serbia', 'learnomy' ),
		'SC' => __( 'Seychelles', 'learnomy' ),
		'SL' => __( 'Sierra Leone', 'learnomy' ),
		'SG' => __( 'Singapore', 'learnomy' ),
		'SX' => __( 'Sint Maarten (Dutch part)', 'learnomy' ),
		'SK' => __( 'Slovakia', 'learnomy' ),
		'SI' => __( 'Slovenia', 'learnomy' ),
		'SB' => __( 'Solomon Islands', 'learnomy' ),
		'SO' => __( 'Somalia', 'learnomy' ),
		'ZA' => __( 'South Africa', 'learnomy' ),
		'GS' => __( 'South Georgia and the South Sandwich Islands', 'learnomy' ),
		'KR' => __( 'South Korea', 'learnomy' ),
		'SS' => __( 'South Sudan', 'learnomy' ),
		'ES' => __( 'Spain', 'learnomy' ),
		'LK' => __( 'Sri Lanka', 'learnomy' ),
		'SD' => __( 'Sudan', 'learnomy' ),
		'SR' => __( 'Suriname', 'learnomy' ),
		'SJ' => __( 'Svalbard and Jan Mayen', 'learnomy' ),
		'SE' => __( 'Sweden', 'learnomy' ),
		'CH' => __( 'Switzerland', 'learnomy' ),
		'SY' => __( 'Syria', 'learnomy' ),
		'TW' => __( 'Taiwan', 'learnomy' ),
		'TJ' => __( 'Tajikistan', 'learnomy' ),
		'TZ' => __( 'Tanzania', 'learnomy' ),
		'TH' => __( 'Thailand', 'learnomy' ),
		'TL' => __( 'Timor-Leste', 'learnomy' ),
		'TG' => __( 'Togo', 'learnomy' ),
		'TK' => __( 'Tokelau', 'learnomy' ),
		'TO' => __( 'Tonga', 'learnomy' ),
		'TT' => __( 'Trinidad and Tobago', 'learnomy' ),
		'TN' => __( 'Tunisia', 'learnomy' ),
		'TR' => __( 'Türkiye', 'learnomy' ),
		'TM' => __( 'Turkmenistan', 'learnomy' ),
		'TC' => __( 'Turks and Caicos Islands', 'learnomy' ),
		'TV' => __( 'Tuvalu', 'learnomy' ),
		'UG' => __( 'Uganda', 'learnomy' ),
		'UA' => __( 'Ukraine', 'learnomy' ),
		'AE' => __( 'United Arab Emirates', 'learnomy' ),
		'GB' => __( 'United Kingdom', 'learnomy' ),
		'US' => __( 'United States', 'learnomy' ),
		'UM' => __( 'United States Minor Outlying Islands', 'learnomy' ),
		'UY' => __( 'Uruguay', 'learnomy' ),
		'UZ' => __( 'Uzbekistan', 'learnomy' ),
		'VU' => __( 'Vanuatu', 'learnomy' ),
		'VA' => __( 'Vatican City', 'learnomy' ),
		'VE' => __( 'Venezuela', 'learnomy' ),
		'VN' => __( 'Vietnam', 'learnomy' ),
		'VG' => __( 'Virgin Islands (British)', 'learnomy' ),
		'VI' => __( 'Virgin Islands (U.S.)', 'learnomy' ),
		'WF' => __( 'Wallis and Futuna', 'learnomy' ),
		'EH' => __( 'Western Sahara', 'learnomy' ),
		'YE' => __( 'Yemen', 'learnomy' ),
		'ZM' => __( 'Zambia', 'learnomy' ),
		'ZW' => __( 'Zimbabwe', 'learnomy' ),
	);

	/**
	 * The country list offered at checkout and on the account screen.
	 *
	 * @param array<string,string> $countries Code => name.
	 */
	return (array) apply_filters( 'learnomy_countries', $countries );
}

/**
 * Describe what a purchase was FOR, in terms a gateway can put on an order.
 *
 * Every gateway that records an order needs the same three facts — what kind of
 * thing this was, what it was called, and where to find it — and none of them
 * should work that out for itself. WooCommerce sells every Learnomy purchase
 * through one reusable line-item product, so without this an order read
 * "Learnomy Checkout" with no way to tell a course from a membership from space
 * seats, and every order on the site linked to the same hidden product. Any
 * future store adapter (EDD, SureCart, FluentCart) has exactly that problem.
 *
 * Filterable, because the purchase TYPES are open-ended: `space` and
 * `space_seats` come from Pro, and a third-party extension can sell something
 * Free has never heard of. Anything unrecognised returns empty strings rather
 * than a guess, so a gateway prints nothing instead of something wrong.
 *
 * @since 1.8.2
 *
 * @param string $type    Purchase type slug ('course', 'membership', …).
 * @param int    $item_id Entity id the purchase refers to.
 * @return array{type_label:string,title:string,url:string}
 */
function purchase_descriptor( string $type, int $item_id ): array {
	$descriptor = array(
		'type_label' => '',
		'title'      => '',
		'url'        => '',
	);

	if ( 'course' === $type && $item_id > 0 ) {
		$course = Models\Course::find( $item_id );
		if ( $course ) {
			$descriptor['type_label'] = course_label();
			$descriptor['title']      = (string) ( $course->title ?? '' );
			$descriptor['url']        = ! empty( $course->slug )
				? route_url( 'course', array( 'slug' => (string) $course->slug ) )
				: '';
		}
	} elseif ( 'membership' === $type && $item_id > 0 ) {
		$plan = Models\MembershipPlan::find( $item_id );
		if ( $plan ) {
			$descriptor['type_label'] = __( 'Membership plan', 'learnomy' );
			$descriptor['title']      = (string) ( $plan->title ?? '' );
			// Anchored on the pricing page, not a per-plan path.
			//
			// This appended the plan slug to the pricing URL, producing
			// /membership/{slug}/ — a path no rewrite rule matches, because
			// `membership-pricing` is ONE page listing every plan. The same
			// mistake shipped in the admin editor's preview link and 404'd for
			// every plan on every site (Basecamp 10195368664). This copy is
			// worse placed: the URL lands on a gateway's order record, so the
			// dead link outlives the session in the payment provider's dashboard.
			$descriptor['url'] = ! empty( $plan->id )
				? route_url( 'membership-pricing' ) . '#lrn-plan-' . absint( $plan->id )
				: route_url( 'membership-pricing' );
		}
	}

	/**
	 * Describe a purchase type for display on a gateway's order record.
	 *
	 * Pro extensions and third-party sellers register their own types here.
	 * Return `type_label` (what kind of thing), `title` (its name) and `url`
	 * (where to see it); leave any of them empty and the gateway omits that line.
	 *
	 * @since 1.8.2
	 *
	 * @param array  $descriptor {type_label, title, url}.
	 * @param string $type       Purchase type slug.
	 * @param int    $item_id    Entity id.
	 */
	return (array) apply_filters( 'learnomy_purchase_descriptor', $descriptor, $type, $item_id );
}

/**
 * What a subscription's period-end date MEANS to the member.
 *
 * The same date is either the day they are billed again or the day their access
 * stops, and only `cancel_at_period_end` distinguishes the two. Four surfaces
 * showed that date and only ONE of them asked the question, so a member who had
 * just cancelled was told "Renews September 1" on their billing page, their
 * dashboard widget and (for a space owner) their space billing panel — while the
 * manage screen correctly said "Ends on". Contradicting yourself about whether
 * someone is about to be charged is how a cancellation becomes a support ticket.
 *
 * Every surface asks here. Phrasing stays local because the layouts genuinely
 * differ ("Renews" as a definition-list term, "Renews %s" inline); what must not
 * be re-authored is the DECISION.
 *
 * @param object|array|null $subscription Subscription row or view-data array.
 * @return bool True when the period end is the END of access, not a renewal.
 */
function subscription_ends_at_period_end( $subscription ): bool {
	if ( is_array( $subscription ) ) {
		return ! empty( $subscription['cancel_at_period_end'] );
	}

	return is_object( $subscription ) && ! empty( $subscription->cancel_at_period_end );
}

add_filter( 'script_loader_src', __NAMESPACE__ . '\\filter_asset_filemtime_ver', 20 );
add_filter( 'style_loader_src', __NAMESPACE__ . '\\filter_asset_filemtime_ver', 20 );
add_filter( 'script_module_loader_src', __NAMESPACE__ . '\\filter_asset_filemtime_ver', 20 );

/*
 * ---------------------------------------------------------------------------
 * Extension-point contracts applied outside Free
 * ---------------------------------------------------------------------------
 * Documented here so the seam is discoverable from Free even though the filter
 * is *applied* by a Pro extension. Free only emits the source events (e.g.
 * `learnomy_quiz_question_answered` from Quiz_Service, `learnomy_lesson_completed`,
 * `learnomy_quiz_graded`, `learnomy_student_enrolled`); the Pro xAPI dispatcher
 * builds a statement from each and runs it through this filter before delivery.
 *
 * Filter: learnomy_xapi_statement
 *   Lets a site edit, enrich, or drop a single xAPI statement just before it is
 *   queued for delivery to the configured LRS. Return null to suppress emission.
 *
 *   @since 1.3.1
 *
 *   @param array|null $statement The assembled xAPI statement (actor/verb/object
 *                                /result/context), or null to drop it.
 *   @param string     $event     The Learnomy event that produced it — one of
 *                                'question_answered', 'lesson_completed',
 *                                'quiz_graded', 'student_enrolled'.
 *   @param array      $context   Event payload the builder used (ids + raw args),
 *                                for site-specific enrichment.
 */

/**
 * The "Report" button for a review, lesson comment or course.
 *
 * One implementation for all four places this button appears, because the state
 * it has to render is not derivable from the markup alone: a member who has
 * already reported this object must see it as reported, and that answer comes
 * from the database. Four hand-written copies of the button meant four places
 * to add that lookup and four chances to add it inconsistently — which is the
 * shape of the bug this fixes (Basecamp 10198559977).
 *
 * The reported state is rendered SERVER-SIDE, on first paint. `report.js`
 * already switched the button the moment a report succeeded, but that lived
 * only in the page's memory: after a reload — or on any later visit, or on
 * another device — the member saw a plain "Report" button on a review they had
 * already reported, and pressed it again. The server refused the duplicate
 * (correctly), so nothing broke; the member simply had no way to tell.
 *
 * The lookup behind this is `AuditLog::has_reported()`, which answers from a
 * per-request map of everything this user has reported of this type. Calling it
 * once per row of a long review list is therefore one query for the page, not
 * one per row.
 *
 * @param string $object_type 'review' | 'comment' | 'course'.
 * @param int    $object_id   Object being reported.
 * @param array  $args        {
 *     @type string $label       Button label when NOT yet reported.
 *     @type string $class       Extra classes appended to `lrn-report-btn`.
 *     @type bool   $icon        Render the flag icon. Default true.
 * }
 */
function report_button( string $object_type, int $object_id, array $args = array() ): void {
	$args = wp_parse_args(
		$args,
		array(
			'label' => __( 'Report', 'learnomy' ),
			'class' => '',
			'icon'  => true,
		)
	);

	$reported = \Learnomy\Models\AuditLog::has_reported( get_current_user_id(), $object_type, $object_id );

	$classes = 'lrn-report-btn';
	if ( $args['class'] ) {
		$classes .= ' ' . $args['class'];
	}
	if ( $reported ) {
		// Same class report.js adds when it marks a button reported, so a button
		// that arrives reported and one that becomes reported look identical.
		$classes .= ' lrn-report-btn--done';
	}
	?>
	<button type="button" class="<?php echo esc_attr( $classes ); ?>"
		data-object-type="<?php echo esc_attr( $object_type ); ?>"
		data-object-id="<?php echo esc_attr( (string) $object_id ); ?>"
		<?php disabled( $reported ); ?>>
		<?php if ( $args['icon'] ) : ?>
			<i data-lucide="flag" aria-hidden="true"></i>
		<?php endif; ?>
		<span class="lrn-report-btn__label">
			<?php echo esc_html( $reported ? __( 'Reported', 'learnomy' ) : $args['label'] ); ?>
		</span>
	</button>
	<?php
}
