<?php
/**
 * Route Registry — the single declarative source for every front URL.
 *
 * One ordered route table drives BOTH the rewrite rules (Router) and the URL
 * builder (route_url()), so a slug can never desync between the link and the
 * rule that matches it. See plan/2026-05-29-route-registry.md.
 *
 * Phase 1 (engine flip): this generates the exact same rewrite rules the Router
 * used to hand-write, in the same order, and the *_url() helpers now delegate to
 * route_url(). Behaviour is identical; later phases delete the helpers, make the
 * 4 literal slugs filterable, and add declarative pagination.
 *
 * Segment shapes inside a route's `path`:
 *   [ 'slug'  => 'base_slug' ]                          → \Learnomy\base_slug() (white-label)
 *   [ 'lit'   => 'register/student' ]                   → fixed literal (may contain '/')
 *   [ 'param' => [ 'arg' => 'slug', 'var' => 'learnomy_slug', 're' => '[^/]+' ] ]
 *
 * @package Learnomy
 */

namespace Learnomy;

defined( 'ABSPATH' ) || exit;

class Routes {

	/**
	 * Ordered route table. ORDER MATTERS: more-specific patterns first so they
	 * win (category before course, lesson/quiz before course, membership-manage
	 * before membership-pricing, instructor-dashboard before instructor-profile).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function definitions(): array {
		$p = static fn( string $arg, string $var, string $re = '[^/]+' ): array =>
			array(
				'param' => array(
					'arg' => $arg,
					'var' => $var,
					're'  => $re,
				),
			);

		$defs = array(
			array(
				'name'      => 'category',
				'path'      => array( array( 'slug' => 'base_slug' ), array( 'slug' => 'category_slug' ), $p( 'slug', 'learnomy_slug' ) ),
				'paginated' => true,
			),
			array(
				'name' => 'lesson',
				'path' => array( array( 'slug' => 'base_slug' ), $p( 'slug', 'learnomy_slug' ), array( 'slug' => 'lesson_slug' ), $p( 'lesson_slug', 'learnomy_lesson_slug' ) ),
			),
			array(
				'name' => 'quiz',
				'path' => array( array( 'slug' => 'base_slug' ), $p( 'slug', 'learnomy_slug' ), array( 'slug' => 'quiz_slug' ), $p( 'quiz_slug', 'learnomy_quiz_slug' ) ),
			),
			array(
				'name' => 'course',
				'path' => array( array( 'slug' => 'base_slug' ), $p( 'slug', 'learnomy_slug' ) ),
			),
			array(
				'name'      => 'catalog',
				'path'      => array( array( 'slug' => 'base_slug' ) ),
				'archive'   => true,
				'paginated' => true,
			),
			array(
				'name' => 'standalone-quiz',
				'path' => array( array( 'slug' => 'standalone_quiz_slug' ), $p( 'slug', 'learnomy_slug' ) ),
			),
			array(
				// The account home (dashboard) lives at /account/ — the My
				// Account hub root (WooCommerce-style). Every other account
				// section sits beneath it (/account/billing/, /account/membership/…).
				'name'    => 'student-dashboard',
				'path'    => array( array( 'slug' => 'account_slug' ) ),
				'archive' => true,
			),
			array(
				'name' => 'my-spaces',
				'path' => array( array( 'slug' => 'my_spaces_slug' ) ),
			),
			array(
				'name' => 'account-billing-receipt',
				'path' => array( array( 'slug' => 'billing_slug' ), $p( 'txn', 'learnomy_txn', '[0-9]+' ), array( 'lit' => 'receipt' ) ),
			),
			array(
				'name' => 'account-billing',
				'path' => array( array( 'slug' => 'billing_slug' ) ),
			),
			array(
				'name' => 'account-details',
				'path' => array( array( 'slug' => 'account_slug' ), array( 'lit' => 'details' ) ),
			),
			array(
				'name' => 'account-instructor-profile',
				'path' => array( array( 'slug' => 'account_slug' ), array( 'lit' => 'instructor-profile' ) ),
			),
			array(
				'name'   => 'account-certificates',
				'path'   => array( array( 'slug' => 'account_slug' ), array( 'lit' => 'certificates' ) ),
				'module' => 'certificates',
			),
			array(
				// The full, paginated "My Courses" list — its own focused section
				// at /account/courses/ so the dashboard home (/account/) stays a
				// lean overview instead of carrying a 1000-row course grid.
				'name' => 'account-courses',
				'path' => array( array( 'slug' => 'account_slug' ), array( 'lit' => 'courses' ) ),
			),
			array(
				// The learner's quiz attempt history + per-attempt review (what
				// they answered, what was right). An optional `?attempt=N` query
				// arg switches the page from the list to the review — no second
				// route needed.
				'name'      => 'account-quiz-results',
				'path'      => array( array( 'slug' => 'account_slug' ), array( 'lit' => 'quiz-results' ) ),
				'module'    => 'quizzes',
				// Paginated list — emit the /page/N/ rule so its pager resolves
				// instead of 404ing (Basecamp 10121743260).
				'paginated' => true,
			),
			array(
				'name' => 'instructor-dashboard',
				'path' => array( array( 'slug' => 'instructor_slug' ) ),
			),
			// Instructor rail sections — the long dashboard split into focused
			// pages. MUST be declared before instructor-profile so their literal
			// paths win over the greedy `/instructor/{username}/` rule.
			array(
				'name' => 'instructor-courses',
				'path' => array( array( 'slug' => 'instructor_slug' ), array( 'lit' => 'courses' ) ),
			),
			array(
				'name'      => 'instructor-students',
				'path'      => array( array( 'slug' => 'instructor_slug' ), array( 'lit' => 'students' ) ),
				// Roster of 20/page — the pager 404'd because no /page/N/ rule was
				// emitted, so only the first page was reachable (Basecamp 10121743260).
				'paginated' => true,
			),
			array(
				'name' => 'instructor-earnings',
				'path' => array( array( 'slug' => 'instructor_slug' ), array( 'lit' => 'earnings' ) ),
			),
			array(
				// Manual grading, in the teaching shell. Sending a teacher into
				// wp-admin to mark an essay is a context switch into a system
				// they never signed up for, so the grading inbox and the
				// per-attempt grader both live out here as well (Basecamp
				// 10132708064). Bound to the quizzes module: with quizzes off
				// there is nothing to grade, so the page 404s rather than
				// rendering an empty promise. Paginated — a backlog of
				// thousands pages at /instructor/grading/page/N/.
				'name'      => 'instructor-grading',
				'path'      => array( array( 'slug' => 'instructor_slug' ), array( 'lit' => 'grading' ) ),
				'module'    => 'quizzes',
				'paginated' => true,
			),
			array(
				'name' => 'instructor-profile',
				'path' => array( array( 'slug' => 'instructor_slug' ), $p( 'slug', 'learnomy_slug' ) ),
			),
			array(
				'name' => 'certificate-verify',
				'path' => array( array( 'slug' => 'verify_slug' ), $p( 'slug', 'learnomy_slug' ) ),
			),
			array(
				'name'             => 'membership-manage',
				'path'             => array( array( 'slug' => 'account_slug' ), array( 'lit' => 'membership' ) ),
				// Bound to the module, but NOT withdrawn from someone who still
				// has a live subscription — see `keeps_commitment` below. This is
				// the page where a member sees and cancels a recurring charge;
				// hiding it while the charge continues is the one outcome a
				// module toggle must never produce.
				'module'           => 'membership',
				'keeps_commitment' => true,
			),
			array(
				'name'    => 'membership-pricing',
				'path'    => array( array( 'slug' => 'membership_slug' ) ),
				'archive' => true,
				'module'  => 'membership',
			),
			array(
				'name' => 'enroll',
				'path' => array( array( 'slug' => 'enroll_slug' ) ),
			),
			array(
				'name'          => 'enroll-thank-you',
				'path'          => array( array( 'slug' => 'thank_you_slug' ) ),
				'skip_if_empty' => true,
			),
			array(
				'name'          => 'enroll-cancelled',
				'path'          => array( array( 'slug' => 'cancelled_slug' ) ),
				'skip_if_empty' => true,
			),
			// Filterable white-label slugs (P5). Each resolves through a
			// *_slug() helper backed by a learnomy_*_slug filter, so a rename
			// moves the rewrite rule AND every route_url() link together.
			array(
				'name' => 'become-instructor',
				'path' => array( array( 'slug' => 'become_instructor_slug' ) ),
			),
			array(
				'name' => 'register-student',
				'path' => array( array( 'slug' => 'register_student_slug' ) ),
			),
			array(
				'name' => 'register-instructor',
				'path' => array( array( 'slug' => 'register_instructor_slug' ) ),
			),
			array(
				// Gated file delivery. The ONLY published URL for a lesson
				// handout — the bytes themselves live off the public path in
				// Protected_Uploads, so this route is the single door, not a
				// convenience wrapper over a URL that also works directly.
				// The filename segment is cosmetic (it gives the browser a
				// sensible default "save as"); lookup is by id alone, so a
				// renamed handout never breaks an existing link.
				'name' => 'lesson-attachment',
				'path' => array(
					array( 'lit' => 'learnomy-file' ),
					$p( 'attachment', 'learnomy_attachment', '[0-9]+' ),
					$p( 'file', 'learnomy_file', '[^/]+' ),
				),
			),
			array(
				'name'           => 'service-worker',
				'raw_pattern'    => '^learnomy-sw\.js$',
				'trailing_slash' => false,
			),
			array(
				// Dynamic PWA manifest served same-origin (installability needs
				// it) with plugins_url icon paths (custom content dirs) and a
				// non-redirecting start_url. Replaces the static assets/manifest.json
				// that 404'd on missing icons and pointed start_url at a 301.
				'name'           => 'pwa-manifest',
				'raw_pattern'    => '^learnomy-manifest\.json$',
				'trailing_slash' => false,
			),
			array(
				'name' => 'offline',
				'path' => array( array( 'slug' => 'offline_slug' ) ),
			),
		);

		// When the PWA is disabled (learnomy_settings['enable_pwa'] = false or
		// the learnomy_enable_pwa filter returns false), drop the service-worker,
		// manifest and offline routes so they never register.
		if ( ! pwa_enabled() ) {
			$defs = array_values(
				array_filter(
					$defs,
					static function ( $def ) {
						return ! in_array( $def['name'] ?? '', array( 'service-worker', 'pwa-manifest', 'offline' ), true );
					}
				)
			);
		}

		/**
		 * Filter the route table. Pro + third-party code add/modify routes here
		 * (the declarative successor to learnomy_register_routes).
		 *
		 * @param array $defs Ordered route definitions.
		 */
		return (array) apply_filters( 'learnomy_routes', $defs );
	}

	/**
	 * The module a route belongs to, or null if the route is always available.
	 *
	 * A route definition may declare `'module' => '<id>'` to bind itself to a
	 * feature module (e.g. membership-pricing → 'membership'). The Router's
	 * `learnomy_route_enabled` gate reads this so a disabled module's pages 404
	 * instead of rendering. Routes with no binding (catalog, course, enroll —
	 * the free flow) always render. This is the single source of truth for the
	 * mapping; do not hardcode it anywhere else.
	 *
	 * @param string $route Route name.
	 * @return string|null Module id, or null when unbound.
	 */
	public static function module_for( string $route ): ?string {
		foreach ( self::definitions() as $def ) {
			if ( ( $def['name'] ?? '' ) === $route && ! empty( $def['module'] ) ) {
				return (string) $def['module'];
			}
		}
		return null;
	}

	/**
	 * Whether a module-bound route survives its module being switched off, for
	 * a visitor who still holds something that module sold them.
	 *
	 * A route declares `'keeps_commitment' => true` when it is the place a
	 * buyer manages what they already paid for. The Router reads it, so Pro
	 * routes added through the `learnomy_routes` filter get the same behaviour
	 * without either side learning about the other. Sales pages deliberately
	 * do NOT set it: the owner has stopped selling, and that part of the
	 * toggle is exactly what they asked for.
	 *
	 * @param string $route Route name.
	 */
	public static function keeps_commitment( string $route ): bool {
		foreach ( self::definitions() as $def ) {
			if ( ( $def['name'] ?? '' ) === $route ) {
				return ! empty( $def['keeps_commitment'] );
			}
		}
		return false;
	}

	/**
	 * One concrete request path per route, plus the rewrite pattern that is
	 * supposed to serve it.
	 *
	 * WHY THIS LIVES HERE. `Route_Conflicts` needs to ask "when a visitor opens
	 * this Learnomy URL, which rewrite rule actually wins?" — and to ask it, it
	 * needs a real path, not a regex. Only the registry knows how a route's
	 * segments assemble, so building the sample anywhere else would be a second
	 * implementation of {@see register_rewrite_rules()} that drifts from it the
	 * first time a segment shape is added.
	 *
	 * Param segments get a token chosen to satisfy their own `re` — `1` for
	 * `[0-9]+`, `sample` for `[^/]+`, and so on. A route whose sample does not
	 * match its own pattern is DROPPED rather than reported: an unsamplable
	 * route (`scorm-content`, whose file segment is `.+`) would otherwise fall
	 * through to core's catch-all page rule and be reported as a conflict every
	 * time. Under-reporting a route we cannot model is honest; inventing a
	 * conflict is not.
	 *
	 * @return array<string, array{path: string, pattern: string}> Keyed by route name.
	 */
	public static function sample_paths(): array {
		// Ordered widest-first: the first token that fully satisfies a param's
		// own regex wins, so `[0-9]+` takes '1' and `[a-f0-9]+` takes 'a1b2c3'.
		$tokens = array( '1', 'sample', 'a1b2c3', 'sample.txt' );
		$out    = array();

		foreach ( self::definitions() as $def ) {
			$name = (string) ( $def['name'] ?? '' );

			// Raw-pattern routes bypass the segment builder, so there is no
			// segment list to walk and nothing faithful to sample.
			if ( '' === $name || isset( $def['raw_pattern'] ) ) {
				continue;
			}

			$path_parts    = array();
			$pattern_parts = array();
			$skip          = false;

			foreach ( (array) $def['path'] as $segment ) {
				if ( isset( $segment['param'] ) ) {
					$re    = (string) $segment['param']['re'];
					$token = '';
					foreach ( $tokens as $candidate ) {
						// `#` delimits, because a param's `re` is almost always
						// `[^/]+` and `/` would close a `/…/` pattern early.
						// WP embeds these fragments in `#…#` for the same reason.
						if ( preg_match( '#^(?:' . $re . ')$#', $candidate ) ) {
							$token = $candidate;
							break;
						}
					}
					if ( '' === $token ) {
						$skip = true;
						break;
					}
					$path_parts[]    = $token;
					$pattern_parts[] = '(' . $re . ')';
					continue;
				}

				$value = self::segment_value( $segment, array() );
				if ( '' === $value ) {
					$skip = true;
					break;
				}
				$path_parts[]    = $value;
				$pattern_parts[] = preg_quote( $value, '/' );
			}

			if ( $skip ) {
				continue;
			}

			$trailing = ( $def['trailing_slash'] ?? true ) ? '/?$' : '$';
			$pattern  = '^' . implode( '/', $pattern_parts ) . $trailing;
			$path     = implode( '/', $path_parts ) . ( '/?$' === $trailing ? '/' : '' );

			// Self-check: a sample that its own rule would not serve tells us
			// nothing about who else might serve it.
			if ( ! preg_match( "#^$pattern#", $path ) ) {
				continue;
			}

			$out[ $name ] = array(
				'path'    => $path,
				'pattern' => $pattern,
			);
		}

		return $out;
	}

	/** Resolve a path segment's literal string (slug helper / param / lit). */
	private static function segment_value( array $segment, array $args ): string {
		if ( isset( $segment['slug'] ) ) {
			$fn = __NAMESPACE__ . '\\' . $segment['slug'];
			return function_exists( $fn ) ? (string) $fn() : '';
		}
		if ( isset( $segment['lit'] ) ) {
			return (string) $segment['lit'];
		}
		if ( isset( $segment['param'] ) ) {
			$arg = $segment['param']['arg'];
			return self::encode_segment( (string) ( $args[ $arg ] ?? '' ) );
		}
		return '';
	}

	/**
	 * Percent-encode one path segment WITHOUT re-encoding what is already encoded.
	 *
	 * Every slug we store comes from `sanitize_title()`, and for any non-Latin
	 * title that function returns percent-encoded text — `Тестовый курс` is
	 * stored literally as `%d1%82%d0%b5…`. The builder used to run a flat
	 * `rawurlencode()` over that, turning each stored `%` into `%25` and
	 * emitting `/courses/%25d1%2582…/`. Measured on lms.local: the stored slug
	 * used verbatim returns **200**, the double-encoded form the builder
	 * produced returns **404** — so every auto-generated internal link to a
	 * course with a non-Latin title was dead, in the catalog card, the
	 * canonical, the JSON-LD `url` and the sitemap alike (Basecamp 10134139449).
	 *
	 * Encoding is still required — `route_url()` is public API and a caller can
	 * pass anything, including a `/` that would forge an extra path segment past
	 * the `[^/]+` the rewrite rule expects. So rather than trusting the input or
	 * decoding it first (which would destroy a literal `%` a caller genuinely
	 * meant), an existing valid `%XX` triplet is passed through untouched and
	 * everything else outside the RFC 3986 unreserved set is encoded. A lone
	 * `%` that is not a valid escape falls through to the character class and
	 * still becomes `%25`, so the function is idempotent on its own output —
	 * which is the property the old one lacked.
	 *
	 * This mirrors WordPress core, whose `get_permalink()` never re-encodes
	 * `post_name` for exactly this reason.
	 *
	 * @param string $value Raw or already-encoded segment value.
	 * @return string Safe, singly-encoded path segment.
	 */
	private static function encode_segment( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		return (string) preg_replace_callback(
			'/%[0-9A-Fa-f]{2}|[^A-Za-z0-9\-._~]/',
			static function ( array $match ): string {
				// A valid escape is 3 chars and is kept as-is; anything the
				// character class matched is a single char that needs encoding.
				return 1 === strlen( $match[0] ) ? rawurlencode( $match[0] ) : $match[0];
			},
			$value
		);
	}

	/**
	 * Build a front URL for a named route.
	 *
	 * @param string               $name Route name (the learnomy_route value).
	 * @param array<string, mixed> $args Param values (slug, lesson_slug, txn, …).
	 *                                    Pass 'paged' => N for an archive page.
	 */
	public static function url( string $name, array $args = array() ): string {
		foreach ( self::definitions() as $def ) {
			if ( ( $def['name'] ?? '' ) !== $name || empty( $def['path'] ) ) {
				continue;
			}
			$segments = array();
			$expected = array();
			$missing  = array();

			foreach ( $def['path'] as $segment ) {
				if ( isset( $segment['param']['arg'] ) ) {
					$expected[] = (string) $segment['param']['arg'];
				}

				$value = self::segment_value( $segment, $args );
				if ( '' !== $value ) {
					$segments[] = $value;
					continue;
				}

				// A param segment that resolved to nothing is a CALLER ERROR,
				// not an optional segment. Skipping it silently is what makes
				// this class of bug so expensive: passing `lesson` where the
				// registry wants `lesson_slug` returns
				// `/courses/<course>/lesson/` - a broken link with an empty
				// trailing segment, no notice, no warning, no false return -
				// and the label rendered beside it stays correct, so nothing on
				// the page looks wrong (Basecamp 10184488113).
				if ( isset( $segment['param'] ) ) {
					$missing[] = (string) $segment['param']['arg'];
				}
			}

			self::warn_about_args( $name, $args, $expected, $missing );

			$path = implode( '/', $segments );

			// Archive pagination: /{archive}/page/N/. Routes flagged
			// `paginated` emit the matching rewrite rule in
			// register_rewrite_rules(), so this canonical URL always resolves.
			$paged = isset( $args['paged'] ) ? (int) $args['paged'] : 0;
			if ( ! empty( $def['paginated'] ) && $paged > 1 ) {
				$path .= '/page/' . $paged;
			}

			return home_url( '/' . $path . '/' );
		}

		/*
		 * The name matched no route at all.
		 *
		 * The loop above treats a missing ARG as a caller error and says so
		 * loudly, for a reason it spells out: a URL that is silently short a
		 * segment is a broken link that looks correct. An unknown NAME is the
		 * same mistake one step earlier and was the one case still landing
		 * without a word - it returned the site root, which renders a real
		 * page, so a "My account" link pointing at the homepage looks like a
		 * design decision rather than a typo.
		 *
		 * Found by walking the pre-release smoke: route_url( 'account' )
		 * returns home_url(). There is no such route - the account home is
		 * registered as 'student-dashboard' - and CLAUDE.md's own route-name
		 * list said 'account', so the documentation was teaching a name that
		 * silently produces the wrong URL. No shipped caller used it, so
		 * nothing was broken; the next person to follow the docs would have
		 * been.
		 *
		 * Still returns home_url( '/' ): a notice is for the developer, and
		 * changing the return to '' or false at this point would turn a
		 * cosmetic bug into a fatal one on any site already relying on the
		 * fallback.
		 */
		_doing_it_wrong(
			'Learnomy\\route_url',
			sprintf(
				/* translators: 1: the route name that was asked for, 2: comma-separated list of valid route names */
				esc_html__( 'Unknown route "%1$s". No such route is registered, so the site root was returned - a link that resolves to a real page and therefore does not look broken. Valid names: %2$s.', 'learnomy' ),
				esc_html( $name ),
				esc_html( implode( ', ', self::names() ) )
			),
			'1.9.3'
		);

		return home_url( '/' );
	}

	/**
	 * Every registered route name, for error messages and for callers that
	 * need to validate a name before building a URL.
	 *
	 * @return array<int,string>
	 */
	public static function names(): array {
		$names = array();
		foreach ( self::definitions() as $def ) {
			$name = (string) ( $def['name'] ?? '' );
			if ( '' !== $name ) {
				$names[] = $name;
			}
		}
		sort( $names );

		return $names;
	}

	/**
	 * Tell a developer, loudly, when a `route_url()` call cannot build the URL
	 * it was asked for.
	 *
	 * Two mistakes, one symptom. A MISSING required arg drops a segment; an
	 * UNKNOWN key (the common one - `lesson` instead of `lesson_slug`) does
	 * both, since the value never lands and the typo never surfaces. Either way
	 * the builder returned a plausible-looking short URL and the caller shipped
	 * it.
	 *
	 * `_doing_it_wrong()` is the right severity: it is silent in production and
	 * shouts on any dev install with `WP_DEBUG`, which is where a wrong arg key
	 * gets typed. Nothing throws - a half-built URL on a live page is bad, and
	 * a fatal on a live page is worse.
	 *
	 * @param string   $name     Route name.
	 * @param array    $args     Args the caller passed.
	 * @param string[] $expected Arg keys this route declares.
	 * @param string[] $missing  Declared args that resolved to nothing.
	 */
	private static function warn_about_args( string $name, array $args, array $expected, array $missing ): void {
		if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			return;
		}

		// `paged` is handled by the paginated branch, not by a path segment.
		$supplied = array_diff( array_keys( $args ), array( 'paged' ) );
		$unknown  = array_diff( $supplied, $expected );

		if ( empty( $missing ) && empty( $unknown ) ) {
			return;
		}

		$notes = array();
		if ( ! empty( $missing ) ) {
			$notes[] = sprintf( 'missing required arg(s): %s', implode( ', ', $missing ) );
		}
		if ( ! empty( $unknown ) ) {
			$notes[] = sprintf( 'unknown arg(s): %s', implode( ', ', $unknown ) );
		}

		_doing_it_wrong(
			'Learnomy\\route_url',
			sprintf(
				/* translators: 1: route name, 2: what is wrong, 3: the args the route accepts */
				esc_html__( 'Route "%1$s" - %2$s. This route accepts: %3$s. The URL was built WITHOUT the missing segment, which produces a broken link that looks correct.', 'learnomy' ),
				esc_html( $name ),
				esc_html( implode( '; ', $notes ) ),
				esc_html( $expected ? implode( ', ', $expected ) : 'no arguments' )
			),
			'1.9.2'
		);
	}

	/**
	 * Register every route's rewrite rule, in table order, plus the legacy
	 * extension filter. Called by Router::add_rewrite_rules().
	 */
	public static function register_rewrite_rules(): void {
		foreach ( self::definitions() as $def ) {
			$name = (string) ( $def['name'] ?? '' );
			if ( '' === $name ) {
				continue;
			}

			// Raw-pattern routes (e.g. the service worker file) bypass the
			// segment builder.
			if ( isset( $def['raw_pattern'] ) ) {
				add_rewrite_rule( $def['raw_pattern'], 'index.php?learnomy_route=' . $name, 'top' );
				continue;
			}

			$pattern_parts = array();
			$query         = 'index.php?learnomy_route=' . $name;
			$match_index   = 1;
			$skip          = false;

			foreach ( (array) $def['path'] as $segment ) {
				if ( isset( $segment['slug'] ) ) {
					$value = self::segment_value( $segment, array() );
					if ( '' === $value && ! empty( $def['skip_if_empty'] ) ) {
						$skip = true;
						break;
					}
					$pattern_parts[] = preg_quote( $value, '/' );
				} elseif ( isset( $segment['lit'] ) ) {
					$pattern_parts[] = preg_quote( (string) $segment['lit'], '/' );
				} elseif ( isset( $segment['param'] ) ) {
					$pattern_parts[] = '(' . $segment['param']['re'] . ')';
					$query          .= '&' . $segment['param']['var'] . '=$matches[' . $match_index . ']';
					++$match_index;
				}
			}

			if ( $skip ) {
				continue;
			}

			$base     = '^' . implode( '/', $pattern_parts );
			$trailing = ( $def['trailing_slash'] ?? true ) ? '/?$' : '$';

			// Paginated archives get a /page/N/ variant, registered BEFORE the
			// base rule so the longer (more specific) pattern wins. lrn_page
			// takes the next free $matches index after the route's own params.
			if ( ! empty( $def['paginated'] ) ) {
				add_rewrite_rule(
					$base . '/page/([0-9]+)' . $trailing,
					$query . '&lrn_page=$matches[' . $match_index . ']',
					'top'
				);
			}

			add_rewrite_rule( $base . $trailing, $query, 'top' );
		}

		// The deprecated `learnomy_register_routes` filter was removed here: a
		// rule added through it got an add_rewrite_rule() but was NOT in the
		// registry, so route_url() could not build it — a hole in the very gate
		// this registry locks (Basecamp 10119141311). Nothing in Free or Pro
		// registers through it (verified: zero add_filter callers; Pro cohorts
		// and spaces both use learnomy_routes). Extensions add routes on the
		// declarative `learnomy_routes` filter — see get_route_defs() — which
		// feeds BOTH the rewrite rule and route_url() from one definition.
	}
}
