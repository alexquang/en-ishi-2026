<?php
/**
 * Embed — the ONE canonical URL→embed renderer for Learnomy.
 *
 * Implements decision D8 of the HTML-first content model ADR
 * (plan/archive/2026-06-26-content-model-html-first.md) and the uniform-embeds plan
 * (plan/2026-06-28-uniform-embeds/PLAN.md): every embed — the lesson-level
 * `embed` content-type, the Editor.js embed block in the lesson body, and the
 * importer — resolves through THIS class, so presentation is identical and
 * premium across the admin editor, the instructor builder, and the student
 * player. No per-service whitelist: we use WordPress core's oEmbed provider
 * list. When a URL is not oEmbeddable we render a clean link-preview card, never
 * a broken iframe.
 *
 * @package Learnomy\Content
 */

namespace Learnomy\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Resolve a URL into premium, responsive embed HTML.
 */
class Embed {

	/**
	 * Transient cache prefix + TTL (URL-keyed; closes ADR G3).
	 */
	private const CACHE_PREFIX = 'lrn_embed_';
	private const CACHE_TTL    = WEEK_IN_SECONDS;

	/**
	 * Render a URL as a premium, responsive embed.
	 *
	 * Tries WP oEmbed (provider iframe/player). On a miss/empty result, falls
	 * back to a clean link-preview card. Either way the output is wrapped in the
	 * shared `.lrn-embed` component so every surface looks identical.
	 *
	 * @param string $url  The URL the author pasted.
	 * @param array  $opts Optional: 'caption' (string), 'ratio' ('16x9'|'4x3'|'1x1'),
	 *                     'cache' (bool, default true).
	 * @return string Sanitized embed HTML, or '' for an empty/invalid URL.
	 */
	public static function render( string $url, array $opts = array() ): string {
		$url = trim( $url );
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return '';
		}

		$caption = isset( $opts['caption'] ) ? wp_kses_post( (string) $opts['caption'] ) : '';
		$ratio   = (string) ( $opts['ratio'] ?? '16x9' );
		if ( ! in_array( $ratio, array( '16x9', '4x3', '1x1' ), true ) ) {
			$ratio = '16x9';
		}
		$use_cache = $opts['cache'] ?? true;

		$cache_key = self::cache_key( $url, $ratio );
		$inner     = $use_cache ? get_transient( $cache_key ) : false;

		if ( false === $inner ) {
			$inner = self::resolve_inner( $url, $ratio );
			if ( $use_cache ) {
				set_transient( $cache_key, $inner, self::CACHE_TTL );
			}
		}

		$html = '<div class="lrn-embed lrn-embed--' . esc_attr( $ratio ) . '">' . $inner;
		if ( '' !== $caption ) {
			$html .= '<p class="lrn-embed__caption">' . $caption . '</p>';
		}
		$html .= '</div>';

		/**
		 * Filter the final embed HTML (after wrap + caption).
		 *
		 * @param string $html The rendered embed markup.
		 * @param string $url  The source URL.
		 * @param array  $opts The render options.
		 */
		return (string) apply_filters( 'learnomy_embed_html', $html, $url, $opts );
	}

	/**
	 * Resolve every stored embed placeholder in a block of rendered HTML through
	 * render(), so content-body embeds (the Editor.js embed block) match the
	 * lesson-level embed type and the importer — one renderer, one premium look.
	 *
	 * The placeholder is the deterministic, round-trip-safe form that
	 * Content_HTML::block_to_html() stores:
	 *   <div class="lrn-embed" data-embed-url="URL"><iframe ...></iframe>[caption]</div>
	 *
	 * This runs at DISPLAY time only (from render_editorjs_content), never at
	 * storage time, so the content round-trip gate (which exercises block_to_html /
	 * to_blocks / canonicalize, not this) is unaffected.
	 *
	 * @param string $html Rendered content HTML.
	 * @return string HTML with embed placeholders replaced by render() output.
	 */
	public static function hydrate( string $html ): string {
		if ( '' === $html || false === strpos( $html, 'data-embed-url' ) ) {
			return $html; // Fast path: no embed placeholders to resolve.
		}

		return (string) preg_replace_callback(
			// The .lrn-embed div carrying data-embed-url, up to its (un-nested) </div>.
			'#<div\b(?=[^>]*\bclass="[^"]*\blrn-embed\b)(?=[^>]*\bdata-embed-url="([^"]*)")[^>]*>(.*?)</div>#s',
			static function ( array $m ): string {
				$url = html_entity_decode( $m[1], ENT_QUOTES );
				if ( '' === trim( $url ) ) {
					return $m[0];
				}
				$caption = '';
				if ( preg_match( '#<p class="lrn-embed__caption">(.*?)</p>#s', $m[2], $cm ) ) {
					$caption = $cm[1];
				}
				return self::render( $url, array( 'caption' => $caption ) );
			},
			$html
		);
	}

	/**
	 * Convert WordPress `core/embed` blocks into the uniform embed placeholder
	 * BEFORE do_blocks() flattens them to a provider iframe. The block comment
	 * still carries the original (oEmbeddable) URL here, so migrated/imported
	 * embeds round-trip and render through the ONE Embed renderer at display —
	 * identical to authored embeds — instead of arriving as a bare iframe the
	 * hydrator can't recognise. Called from \Learnomy\render_wp_blocks (importer).
	 *
	 * @param string $html Source HTML that may contain core/embed block comments.
	 * @return string HTML with embed blocks replaced by the placeholder.
	 */
	public static function blocks_to_placeholders( string $html ): string {
		if ( false === strpos( $html, 'wp:embed' ) ) {
			return $html; // Fast path: no embed blocks.
		}

		return (string) preg_replace_callback(
			'/<!--\s*wp:embed\s+(\{.*?\})\s*-->.*?<!--\s*\/wp:embed\s*-->/s',
			static function ( array $m ): string {
				$attrs = json_decode( $m[1], true );
				$url   = ( is_array( $attrs ) && ! empty( $attrs['url'] ) ) ? esc_url( (string) $attrs['url'] ) : '';
				if ( '' === $url ) {
					return $m[0];
				}
				return '<div class="lrn-embed" data-embed-url="' . $url . '"><iframe src="' . $url . '" frameborder="0" allowfullscreen></iframe></div>';
			},
			$html
		);
	}

	/**
	 * Resolve the inner markup, in order:
	 *   1. Core-provider oEmbed (YouTube/Vimeo/… — `discover => false`, no fetch of
	 *      an arbitrary host, SSRF surface closed).
	 *   2. Federated-platform adapters (PeerTube …) — a URL-shape → sandboxed
	 *      iframe transform with ZERO server fetch, so every instance of a known
	 *      platform works without a per-domain list and adds no SSRF surface.
	 *   3. SSRF-guarded oEmbed discovery — for ANY other Fediverse/oEmbed source,
	 *      gated by Webhook_URL_Validator::is_safe() (the same guard that decides
	 *      webhook URLs). This is what makes discovery safe to turn on: an
	 *      author-supplied URL that resolves to a private/reserved host is never
	 *      fetched. Result (positive or negative) is cached by render()'s transient.
	 *   4. Premium link-card fallback — never a broken iframe.
	 *
	 * The whole point of (2)+(3): to the site owner the Fediverse is ONE thing, not
	 * a list of domains, so there is no instance to register — safe federated video
	 * just embeds.
	 */
	private static function resolve_inner( string $url, string $ratio ): string {
		// Owner/network moderation deny-list (a block-list, never an allow-list).
		if ( self::is_blocked_host( $url ) ) {
			return self::card( $url );
		}

		/**
		 * Filter the args passed to wp_oembed_get for the FIRST pass (core providers).
		 *
		 * `discover => false` here is deliberate: pass 1 is restricted to WordPress's
		 * built-in provider allow-list (~40 services) and never fetches an arbitrary
		 * URL to sniff for oEmbed tags. Discovery still happens — but only in pass 3,
		 * behind the is_safe() SSRF guard.
		 *
		 * @param array  $args oEmbed args.
		 * @param string $url  The source URL.
		 */
		$args = (array) apply_filters(
			'learnomy_embed_oembed_args',
			array(
				'maxwidth' => 1280,
				'discover' => false,
			),
			$url
		);

		// 1) Core-provider oEmbed.
		$frame = self::frame_from_oembed( $url, $args );
		if ( null !== $frame ) {
			return $frame;
		}

		// 2) Federated-platform adapters (zero fetch; sandboxed iframe).
		$adapter_html = self::match_platform_adapter( $url );
		if ( null !== $adapter_html ) {
			$safe = Content_HTML::sanitize( $adapter_html, 'render' );
			if ( '' !== trim( $safe ) ) {
				return '<div class="lrn-embed__frame">' . $safe . '</div>';
			}
		}

		// 3) SSRF-guarded oEmbed discovery — the general, list-free net.
		if ( self::discovery_enabled( $url ) && \Learnomy\Security\Webhook_URL_Validator::is_safe( $url ) ) {
			$frame = self::frame_from_oembed( $url, array_merge( $args, array( 'discover' => true ) ) );
			if ( null !== $frame ) {
				return $frame;
			}
		}

		// 4) Clean link-card (also the cached negative result).
		return self::card( $url );
	}

	/**
	 * Run wp_oembed_get with the given args and return the responsive frame, or
	 * null if the provider returned nothing usable. Provider HTML passes through
	 * the render floor so only the trusted (sandboxed) iframe set survives.
	 */
	private static function frame_from_oembed( string $url, array $args ): ?string {
		$oembed = function_exists( 'wp_oembed_get' ) ? wp_oembed_get( $url, $args ) : false;
		if ( is_string( $oembed ) && '' !== trim( $oembed ) ) {
			$safe = Content_HTML::sanitize( $oembed, 'render' );
			if ( '' !== trim( $safe ) ) {
				return '<div class="lrn-embed__frame">' . $safe . '</div>';
			}
		}
		return null;
	}

	/**
	 * Match a URL against the federated-platform adapters and return sandboxed
	 * iframe HTML, or null. Adapters do NO server-side HTTP — they transform a
	 * recognised URL shape into an embed iframe, so they carry zero SSRF surface
	 * and cover every instance of a platform without a per-domain list.
	 *
	 * @param string $url The source URL.
	 * @return string|null Iframe HTML, or null if no adapter matched.
	 */
	private static function match_platform_adapter( string $url ): ?string {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( '' === $host || '' === $path ) {
			return null;
		}

		/**
		 * Filter the federated-platform embed adapters. Each is
		 * callable( string $url, string $host, string $path ): ?string returning
		 * sandboxed iframe HTML for a URL it recognises, or null to pass. Add
		 * Owncast / PixelFed / etc. here without touching core.
		 *
		 * @param callable[] $adapters
		 */
		$adapters = (array) apply_filters(
			'learnomy_embed_platform_adapters',
			array( array( __CLASS__, 'peertube_adapter' ) )
		);

		foreach ( $adapters as $adapter ) {
			if ( is_callable( $adapter ) ) {
				$html = $adapter( $url, $host, $path );
				if ( is_string( $html ) && '' !== trim( $html ) ) {
					return $html;
				}
			}
		}
		return null;
	}

	/**
	 * PeerTube adapter — the dominant Fediverse video platform. Recognises the
	 * three PeerTube video URL shapes and returns the sandboxed embed iframe with
	 * no server fetch, so any PeerTube instance works with no domain registration.
	 *
	 *   /w/{id} · /videos/watch/{id} · /videos/embed/{id}   →   /videos/embed/{id}
	 *
	 * `{id}` is constrained to PeerTube's id shapes (22-ish char shortUUID, a full
	 * UUID, or a legacy numeric id) so a generic /w/… path elsewhere won't match.
	 * The iframe sandbox mirrors PeerTube's own oEmbed output.
	 *
	 * @param string $url  The source URL.
	 * @param string $host URL host.
	 * @param string $path URL path.
	 * @return string|null Iframe HTML, or null.
	 */
	public static function peertube_adapter( string $url, string $host, string $path ): ?string {
		$id_re = '(?:[A-Za-z0-9]{20,25}|[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}|[0-9]+)';
		if ( ! preg_match( '#^/(?:w|videos/(?:watch|embed))/(' . $id_re . ')/?$#', $path, $m ) ) {
			return null;
		}
		$embed = 'https://' . $host . '/videos/embed/' . rawurlencode( $m[1] );
		return sprintf(
			'<iframe src="%s" title="%s" frameborder="0" sandbox="allow-same-origin allow-scripts allow-popups allow-forms" allow="fullscreen" allowfullscreen loading="lazy"></iframe>',
			esc_url( $embed ),
			esc_attr__( 'PeerTube video', 'learnomy' )
		);
	}

	/**
	 * Whether SSRF-guarded oEmbed discovery (pass 3) is on. Default ON so federated
	 * video "just works"; the is_safe() guard keeps it safe regardless. Site owners
	 * can turn it off with the `learnomy_embed_fediverse` option or the filter.
	 *
	 * @param string $url The source URL (for filter context).
	 * @return bool
	 */
	private static function discovery_enabled( string $url ): bool {
		$enabled = (bool) get_option( 'learnomy_embed_fediverse', true );
		/**
		 * Filter whether guarded Fediverse/oEmbed discovery runs.
		 *
		 * @param bool   $enabled Default from the `learnomy_embed_fediverse` option (on).
		 * @param string $url     The source URL.
		 */
		return (bool) apply_filters( 'learnomy_embed_fediverse_enabled', $enabled, $url );
	}

	/**
	 * Owner/network moderation deny-list — block a specific instance host. Not an
	 * allow-list (that would be the per-domain treadmill this feature avoids).
	 *
	 * @param string $url The source URL.
	 * @return bool True if the host is blocked.
	 */
	private static function is_blocked_host( string $url ): bool {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host ) {
			return false;
		}
		$bare = preg_replace( '/^www\./', '', $host );
		/**
		 * Filter the embed host deny-list (lowercase hosts).
		 *
		 * @param string[] $blocked Blocked hosts.
		 * @param string   $url     The source URL.
		 */
		$blocked = array_map( 'strtolower', (array) apply_filters( 'learnomy_embed_blocked_hosts', array(), $url ) );
		return in_array( $host, $blocked, true ) || in_array( $bare, $blocked, true );
	}

	/**
	 * Premium link-preview card for URLs WP can't oEmbed (EM3). Never a broken
	 * iframe — a clean, intentional card with the host + an Open action.
	 *
	 * @param string $url The source URL.
	 * @return string Card markup.
	 */
	public static function card( string $url ): string {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$host = '' !== $host ? preg_replace( '/^www\./', '', $host ) : esc_html__( 'External link', 'learnomy' );

		// Lucide "external-link" glyph — no third-party favicon fetch (privacy + offline safe).
		$icon = '<svg class="lrn-embed-card__icon" xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/></svg>';

		return sprintf(
			'<div class="lrn-embed__frame lrn-embed__frame--card"><a class="lrn-embed-card" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s<span class="lrn-embed-card__body"><span class="lrn-embed-card__host">%3$s</span><span class="lrn-embed-card__url">%4$s</span></span><span class="lrn-embed-card__action">%5$s</span></a></div>',
			esc_url( $url ),
			$icon,
			esc_html( $host ),
			esc_html( self::truncate( $url, 64 ) ),
			esc_html__( 'Open', 'learnomy' )
		);
	}

	/**
	 * URL-keyed transient key (includes ratio so re-rendering at a new ratio is correct).
	 */
	public static function cache_key( string $url, string $ratio = '16x9' ): string {
		return self::CACHE_PREFIX . md5( $ratio . '|' . $url );
	}

	/**
	 * Clear the cached render for one URL (all ratios) — call on content save.
	 */
	public static function clear( string $url ): void {
		foreach ( array( '16x9', '4x3', '1x1' ) as $ratio ) {
			delete_transient( self::cache_key( $url, $ratio ) );
		}
	}

	/**
	 * Short, multibyte-safe URL label for the card.
	 */
	private static function truncate( string $value, int $len ): string {
		return mb_strlen( $value ) > $len ? mb_substr( $value, 0, $len - 1 ) . '…' : $value;
	}
}
