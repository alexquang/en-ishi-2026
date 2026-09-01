<?php
/**
 * Route ownership — does a Learnomy URL still reach Learnomy?
 *
 * WHY THIS EXISTS
 * ---------------
 * Learnomy registers every front URL through the Route Registry, and every rule
 * goes in with `add_rewrite_rule( …, 'top' )`. "Top" sounds decisive and is not:
 * `extra_rules_top` is an ordered array, and `register_post_type()` puts a CPT's
 * archive rule in the SAME array. Whoever registered first is served first.
 *
 * Measured on lms.local with Tutor LMS 2.7.6 active (Basecamp 10183993627):
 * Tutor's `courses/?$ → post_type=courses` sits at index 22 of
 * `extra_rules_top`; Learnomy's `^courses/?$ → learnomy_route=catalog` sits at
 * 68. `/courses/` renders `post-type-archive-courses` with zero Learnomy course
 * cards — the catalog, which is the public front door for every sale and every
 * browse, is unreachable at its own address. Nothing in Learnomy noticed.
 *
 * It gets worse when the other LMS LEAVES. WordPress only rebuilds
 * `rewrite_rules` on a flush, so a plugin whose files are deleted (or whose
 * deactivation flush never ran) leaves its rules behind: the slug is then owned
 * by a rule pointing at a post type that no longer exists, and the request falls
 * through to the blog home. That is the state the card was filed from — 13
 * `tutor_*` options on the site and no Tutor plugin.
 *
 * WHAT THIS DOES, AND WHERE IT DELIBERATELY STOPS
 * -----------------------------------------------
 * It answers one question per route, the way WordPress answers it: walk the
 * stored rules in order, take the first pattern that matches the route's own
 * URL, and see whose it is. Then it classifies, because the two failures need
 * opposite handling:
 *
 *   STALE — our own rule is not in the stored table at all. The table predates
 *           this install (or this slug). A flush can only help.
 *   GHOST — the winning rule targets a post type or taxonomy nothing registers
 *           any more. It can never serve a correct page for anyone. A flush
 *           rebuilds the table from what is actually registered and it is gone.
 *   LIVE  — the winning rule belongs to something running right now.
 *
 * Stale and ghost are HEALED (one flush, once per signature). Live is REPORTED
 * and never taken. Two plugins both asking for `/courses/` is a decision about
 * the owner's site, not a bug with an obvious answer — silently seizing the slug
 * would break the other plugin's archive, and we would be guessing on their
 * behalf exactly the way the pricing fallbacks did. The notice names the
 * collision and offers the two real fixes (change our slug, or the other
 * plugin's). `learnomy_route_conflict_force_ownership` is the escape hatch for
 * an owner who has decided Learnomy should win.
 *
 * Why not just register earlier. Moving to `init:0` wins against Tutor and loses
 * against the next plugin that also moved to `init:0`. A race you currently win
 * is not ownership.
 *
 * @package Learnomy
 */

namespace Learnomy\Services;

defined( 'ABSPATH' ) || exit;

use Learnomy\Routes;

class Route_Conflicts {

	/** Option holding the last detection result. */
	public const OPTION_STATE = 'learnomy_route_conflicts';

	/** Transient throttling the admin-side re-check. */
	private const CHECK_TRANSIENT = 'learnomy_route_conflicts_checked';

	/** How often the admin re-checks. Rules only change on a flush. */
	private const CHECK_TTL = 12 * HOUR_IN_SECONDS;

	// -------------------------------------------------------------------------
	// Wiring
	// -------------------------------------------------------------------------

	public static function register(): void {
		add_action( 'admin_init', array( self::class, 'maybe_check' ) );
		add_action( 'admin_notices', array( self::class, 'render_notice' ) );
		add_filter( 'site_status_tests', array( self::class, 'register_site_health_test' ) );
		add_filter( 'rewrite_rules_array', array( self::class, 'maybe_force_ownership' ), 99 );

		// A slug change rewrites which paths we own, so the previous verdict
		// describes URLs that no longer exist.
		add_action( 'update_option_learnomy_settings', array( self::class, 'forget' ) );
	}

	// -------------------------------------------------------------------------
	// Detection
	// -------------------------------------------------------------------------

	/**
	 * Which Learnomy routes are served by something other than Learnomy.
	 *
	 * @param array<string, string>|null $rules Stored rules; defaults to the live option.
	 * @return array<int, array<string, string>> One row per shadowed route.
	 */
	public static function detect( ?array $rules = null ): array {
		if ( null === $rules ) {
			$stored = get_option( 'rewrite_rules' );
			$rules  = is_array( $stored ) ? $stored : array();
		}

		// No stored rules at all means plain permalinks — every URL is a query
		// string, nothing shadows anything, and a flush would not change that.
		if ( empty( $rules ) ) {
			return array();
		}

		$conflicts = array();

		foreach ( Routes::sample_paths() as $route => $sample ) {
			$winner_pattern = '';
			$winner_query   = '';

			foreach ( $rules as $pattern => $query ) {
				if ( preg_match( "#^$pattern#", $sample['path'] ) ) {
					$winner_pattern = (string) $pattern;
					$winner_query   = (string) $query;
					break;
				}
			}

			// Ours, and pointed at the right route. The `&`/end anchor matters:
			// `learnomy_route=course` is a prefix of `learnomy_route=course-editor`.
			if ( preg_match( '/learnomy_route=' . preg_quote( $route, '/' ) . '(&|$)/', $winner_query ) ) {
				continue;
			}

			$conflicts[] = array(
				'route'   => $route,
				'path'    => $sample['path'],
				'pattern' => $sample['pattern'],
				'winner'  => $winner_pattern,
				'query'   => $winner_query,
				'kind'    => self::classify( $sample['pattern'], $winner_query, $rules ),
			);
		}

		return $conflicts;
	}

	/**
	 * Stale (our rule is missing), ghost (the winner points at nothing), or live.
	 *
	 * @param array<string, string> $rules Stored rules.
	 */
	private static function classify( string $our_pattern, string $winner_query, array $rules ): string {
		if ( ! isset( $rules[ $our_pattern ] ) ) {
			return 'stale';
		}

		if ( preg_match( '/[?&]post_type=([^&]+)/', $winner_query, $m ) && ! post_type_exists( urldecode( $m[1] ) ) ) {
			return 'ghost';
		}

		if ( preg_match( '/[?&]taxonomy=([^&]+)/', $winner_query, $m ) && ! taxonomy_exists( urldecode( $m[1] ) ) ) {
			return 'ghost';
		}

		return 'live';
	}

	// -------------------------------------------------------------------------
	// Healing
	// -------------------------------------------------------------------------

	/**
	 * Re-check on an admin page load, at most twice a day, and repair what a
	 * flush can repair.
	 */
	public static function maybe_check(): void {
		if ( get_transient( self::CHECK_TRANSIENT ) ) {
			return;
		}
		set_transient( self::CHECK_TRANSIENT, 1, self::CHECK_TTL );

		self::check_now();
	}

	/**
	 * Detect, heal once per signature, re-detect, store.
	 *
	 * The signature guard is what keeps this from becoming a flush loop: if a
	 * flush does not clear the healable conflicts (a case we have not thought
	 * of), the same signature comes back and we stop trying and report instead.
	 *
	 * @return array<int, array<string, string>> The conflicts left after healing.
	 */
	public static function check_now(): array {
		$state     = get_option( self::OPTION_STATE, array() );
		$state     = is_array( $state ) ? $state : array();
		$conflicts = self::detect();

		$healable  = array_values(
			array_filter(
				$conflicts,
				static fn( array $c ): bool => in_array( $c['kind'], array( 'stale', 'ghost' ), true )
			)
		);
		$signature = $healable ? md5( (string) wp_json_encode( wp_list_pluck( $healable, 'winner', 'route' ) ) ) : '';

		if ( '' !== $signature && ( $state['healed_signature'] ?? '' ) !== $signature ) {
			// `false` — rebuild the rules option only. Rewriting .htaccess is a
			// different, riskier job and nothing here needs it.
			flush_rewrite_rules( false );
			$state['healed_signature'] = $signature;
			$state['healed_at']        = gmdate( 'c' );
			$conflicts                 = self::detect();
		}

		// Nothing healable left: drop the guard so a future removal of another
		// LMS gets its own repair instead of matching a stale signature.
		if ( ! $healable ) {
			unset( $state['healed_signature'] );
		}

		$state['checked_at'] = gmdate( 'c' );
		$state['conflicts']  = $conflicts;
		update_option( self::OPTION_STATE, $state, false );

		return $conflicts;
	}

	/** Drop the cached verdict so the next admin load re-checks from scratch. */
	public static function forget(): void {
		delete_transient( self::CHECK_TRANSIENT );
	}

	/**
	 * What is still broken after the last heal attempt.
	 *
	 * The stored list is always post-heal, so anything in it is something a
	 * flush did not fix — a live collision, or a stale/ghost rule that survived
	 * (which the signature guard stops us retrying). Both need the owner, so
	 * both are reported. An earlier draft reported only `live` and would have
	 * hidden exactly the case where our own repair failed.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function unresolved(): array {
		$state = get_option( self::OPTION_STATE, array() );

		return is_array( $state ) && isset( $state['conflicts'] ) && is_array( $state['conflicts'] )
			? array_values( $state['conflicts'] )
			: array();
	}

	/** Whether any unresolved conflict is a leftover rule rather than a live one. */
	private static function has_leftover( array $conflicts ): bool {
		foreach ( $conflicts as $conflict ) {
			if ( in_array( $conflict['kind'] ?? '', array( 'stale', 'ghost' ), true ) ) {
				return true;
			}
		}

		return false;
	}

	// -------------------------------------------------------------------------
	// Owner escape hatch
	// -------------------------------------------------------------------------

	/**
	 * Move Learnomy's rules to the front of the final table when the owner has
	 * decided Learnomy owns its slugs.
	 *
	 * Off by default. This is the one place where taking the slug is not a
	 * guess, because somebody said so.
	 *
	 * @param array<string, string> $rules Merged rules, in match order.
	 * @return array<string, string>
	 */
	public static function maybe_force_ownership( array $rules ): array {
		/**
		 * Whether Learnomy's rewrite rules should outrank every other plugin's.
		 *
		 * @param bool $force Default false.
		 */
		if ( ! apply_filters( 'learnomy_route_conflict_force_ownership', false ) ) {
			return $rules;
		}

		$ours   = array();
		$theirs = array();
		foreach ( $rules as $pattern => $query ) {
			if ( false !== strpos( (string) $query, 'learnomy_route=' ) ) {
				$ours[ $pattern ] = $query;
			} else {
				$theirs[ $pattern ] = $query;
			}
		}

		return $ours + $theirs;
	}

	// -------------------------------------------------------------------------
	// Owner-facing surfaces
	// -------------------------------------------------------------------------

	/**
	 * Notice on Learnomy screens when another active plugin owns one of our URLs.
	 *
	 * Not dismissible: the catalog is the front door, and an owner who dismisses
	 * this is left with a store nobody can browse. It disappears the moment the
	 * collision does.
	 */
	public static function render_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || ! self::is_learnomy_screen() ) {
			return;
		}

		$conflicts = self::unresolved();
		if ( empty( $conflicts ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p><strong>'
			. esc_html__( 'Another plugin is answering Learnomy URLs.', 'learnomy' )
			. '</strong></p><ul style="list-style:disc;margin-left:20px;">';

		foreach ( $conflicts as $conflict ) {
			printf(
				'<li>%s</li>',
				esc_html(
					sprintf(
						/* translators: 1: site URL path, 2: what serves it instead. */
						__( '%1$s is served by %2$s, not by Learnomy.', 'learnomy' ),
						home_url( '/' . ltrim( (string) $conflict['path'], '/' ) ),
						self::describe_winner( (string) $conflict['query'] )
					)
				)
			);
		}

		echo '</ul><p>'
			. esc_html__( 'Your Learnomy pages are still reachable, but not at these addresses. Another rewrite rule claims them ahead of Learnomy, and WordPress serves whichever rule it finds first, so this will not settle on its own.', 'learnomy' )
			. '</p><p>'
			. sprintf(
				/* translators: %s: link to the URL slug settings. */
				esc_html__( 'Fix it by giving Learnomy a different URL base in %s, or by turning off the other plugin.', 'learnomy' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=learnomy-settings#section-pages' ) ) . '">'
					. esc_html__( 'LMS Settings, URL Slugs', 'learnomy' ) . '</a>'
			)
			. '</p>';

		if ( self::has_leftover( $conflicts ) ) {
			echo '<p>'
				. sprintf(
					/* translators: %s: link to Settings, Permalinks. */
					esc_html__( 'Some of these come from a plugin that is no longer installed. Learnomy already refreshed your permalinks once and they survived it, so open %s and press Save Changes.', 'learnomy' ),
					'<a href="' . esc_url( admin_url( 'options-permalink.php' ) ) . '">'
						. esc_html__( 'Settings, Permalinks', 'learnomy' ) . '</a>'
				)
				. '</p>';
		}

		echo '</div>';
	}

	/**
	 * @param array<string, mixed> $tests Site Health tests.
	 * @return array<string, mixed>
	 */
	public static function register_site_health_test( array $tests ): array {
		$tests['direct']['learnomy_route_conflicts'] = array(
			'label' => __( 'Learnomy course URLs', 'learnomy' ),
			'test'  => array( self::class, 'site_health_result' ),
		);

		return $tests;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function site_health_result(): array {
		$conflicts = self::unresolved();

		$result = array(
			'label'       => __( 'Your course URLs reach Learnomy', 'learnomy' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Learnomy', 'learnomy' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'Every Learnomy address - the course catalog, single courses, lessons, quizzes and the account pages - is answered by Learnomy. Leftover URL rules from a previously removed LMS are repaired automatically.', 'learnomy' ) . '</p>',
			'test'        => 'learnomy_route_conflicts',
		);

		if ( empty( $conflicts ) ) {
			return $result;
		}

		$rows = '';
		foreach ( $conflicts as $conflict ) {
			$rows .= '<li><code>' . esc_html( home_url( '/' . ltrim( (string) $conflict['path'], '/' ) ) ) . '</code> — '
				. esc_html( self::describe_winner( (string) $conflict['query'] ) ) . '</li>';
		}

		$result['label']          = __( 'Another plugin owns some of your Learnomy URLs', 'learnomy' );
		$result['status']         = 'critical';
		$result['badge']['color'] = 'red';
		$result['description']    = '<p>'
			. esc_html__( 'These addresses are answered by another rewrite rule, so visitors do not reach Learnomy there:', 'learnomy' )
			. '</p><ul>' . $rows . '</ul><p>'
			. esc_html__( 'Give Learnomy a different URL base under LMS Settings, URL Slugs, or turn off the plugin that claims the same address.', 'learnomy' )
			. '</p>';

		if ( self::has_leftover( $conflicts ) ) {
			$result['description'] .= '<p>'
				. esc_html__( 'At least one of these was left behind by a plugin that is no longer installed. Learnomy refreshed your permalinks once and the rule survived it - open Settings, Permalinks and press Save Changes.', 'learnomy' )
				. '</p>';
		}

		return $result;
	}

	// -------------------------------------------------------------------------
	// Internals
	// -------------------------------------------------------------------------

	/**
	 * Name the thing that took the URL, in an owner's vocabulary.
	 *
	 * We can name the content type but not the plugin behind it — WordPress does
	 * not record who registered a post type, and guessing from the slug would be
	 * wrong the first time two plugins share one.
	 */
	private static function describe_winner( string $query ): string {
		if ( preg_match( '/[?&]post_type=([^&]+)/', $query, $m ) ) {
			$type   = urldecode( $m[1] );
			$object = get_post_type_object( $type );
			if ( $object ) {
				return sprintf(
					/* translators: 1: post type label, 2: post type slug. */
					__( 'another plugin\'s "%1$s" archive (%2$s)', 'learnomy' ),
					$object->labels->name ?? $type,
					$type
				);
			}

			/* translators: %s: post type slug. */
			return sprintf( __( 'a leftover rule for the removed "%s" content type', 'learnomy' ), $type );
		}

		if ( preg_match( '/[?&]pagename=/', $query ) ) {
			return __( 'a WordPress page', 'learnomy' );
		}

		return __( 'another plugin', 'learnomy' );
	}

	private static function is_learnomy_screen(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}
		$screen = get_current_screen();
		if ( ! $screen ) {
			return false;
		}

		$id = (string) $screen->id;

		return 'toplevel_page_learnomy' === $id
			|| 'site-health' === $id
			|| false !== strpos( $id, 'page_learnomy' );
	}
}
