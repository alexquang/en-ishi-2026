<?php
/**
 * Content_HTML — the lossless Editor.js-blocks ⇄ semantic-HTML engine.
 *
 * Phase-1 linchpin of the HTML-first content model ADR
 * (plan/archive/2026-06-26-content-model-html-first.md). A pure, deterministic trio:
 *
 *   - block_to_html() / to_html()  — blocks → semantic HTML. The ONE mapping:
 *     it is byte-identical to the legacy switch in render_editorjs_content(),
 *     which now delegates here, so there is no second renderer and no display
 *     change. Output uses clean tags + recoverable `lrn-*` marker classes.
 *   - to_blocks()                  — semantic HTML → Editor.js blocks. The new
 *     inbound parser (the old html_to_editorjs_document() only raw-wrapped).
 *     The `lrn-*` markers above are how custom blocks round-trip; anything
 *     unrecognised becomes a `raw` block so content is NEVER lost.
 *   - canonicalize()               — the normalized canonical form for the
 *     round-trip gate (idempotent; whitespace/attribute/entity normalization is
 *     not a loss) and the future stored shape. INV-1 normalization (strip
 *     Editor.js `cdx-*`/`ce-*` inline classes) lives HERE only, never in the
 *     display path, so wiring this into render kept rendering identical.
 *
 * Phase 1 builds + PROVES this engine; it is not yet wired into producers
 * (Phase 3) or the migration (Phase 7). Nothing here changes stored data.
 *
 * @package Learnomy\Content
 */

namespace Learnomy\Content;

use function Learnomy\sanitize_lesson_raw_html;

defined( 'ABSPATH' ) || exit;

class Content_HTML {

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode/DOMElement API properties (tagName, childNodes, ownerDocument, nodeType, textContent) are camelCase in PHP's DOM extension; not ours to rename.

	/** Text-only blocks that emit nothing when their text is blank. */
	private const TEXT_ONLY = array( 'paragraph', 'header', 'inlineCode', 'mark', 'marker' );

	/**
	 * Render one Editor.js block to semantic HTML. Single source of truth for
	 * the block→HTML mapping — render_editorjs_content() delegates here.
	 * Byte-identical to the historical switch, so delegation changes no output.
	 *
	 * @param array $block An Editor.js block (`type` + `data`).
	 * @return string Semantic HTML for the block ('' when empty).
	 */
	/**
	 * Image layout vocabulary — the ONE list, shared by both directions.
	 *
	 * We mirror WordPress's own alignment words (left / center / right) and a
	 * simple width scale rather than inventing a private vocabulary, because 10k+
	 * site owners already know what "align left, half width" means and their
	 * themes already reason in those terms.
	 *
	 * Defaults (width 100, align center) emit NO class. That keeps the HTML clean
	 * and, more importantly, keeps the round-trip a stable fixpoint: an image with
	 * default layout produces no layout classes, reads back with no layout tune,
	 * and re-emits identical HTML.
	 */
	private const IMAGE_WIDTHS = array( '25', '50', '75', '100' );
	private const IMAGE_ALIGNS = array( 'left', 'center', 'right' );

	/**
	 * Boolean style tunes the Editor.js image tool ships natively.
	 *
	 * These have ALWAYS been in the editor UI — the instructor could toggle
	 * "With border" / "With background" / "Stretch image" — and this converter
	 * silently dropped all three on save, so the toggle appeared to do nothing and
	 * reverted on reload. That is the bug behind "I can't use the image at the
	 * size I want": the only sizing affordance Editor.js gives you for free
	 * (stretch) was being thrown away here.
	 *
	 * @var array<string,string> data key => class suffix
	 */
	private const IMAGE_STYLE_TUNES = array(
		'withBorder'     => 'bordered',
		'withBackground' => 'bg',
		'stretched'      => 'stretched',
	);

	/**
	 * Resolve an image block's <figure> classes. Deterministic order — the
	 * round-trip gate compares HTML, so a shuffled class list is a failed build.
	 *
	 * @param array $block Full Editor.js block (needs `data` and, for layout, `tunes`).
	 * @return string[]
	 */
	private static function image_classes( array $block ): array {
		$data    = (array) ( $block['data'] ?? array() );
		$layout  = (array) ( $block['tunes']['lrnImageLayout'] ?? array() );
		$classes = array( 'lrn-image' );

		$width = (string) ( $layout['width'] ?? '100' );
		if ( in_array( $width, self::IMAGE_WIDTHS, true ) && '100' !== $width ) {
			$classes[] = 'lrn-image--w-' . $width;
		}

		$align = (string) ( $layout['align'] ?? 'center' );
		if ( in_array( $align, self::IMAGE_ALIGNS, true ) && 'center' !== $align ) {
			$classes[] = 'lrn-image--' . $align;
		}

		foreach ( self::IMAGE_STYLE_TUNES as $key => $suffix ) {
			if ( ! empty( $data[ $key ] ) ) {
				$classes[] = 'lrn-image--' . $suffix;
			}
		}

		return $classes;
	}

	/**
	 * Read an image <figure>'s classes back into block data + layout tune.
	 *
	 * The exact inverse of image_classes(). These two must stay adjacent and stay
	 * symmetric: bin/content-roundtrip.php asserts that to_html(to_blocks(H)) is a
	 * fixpoint, so anything this fails to read back is silently erased from the
	 * author's content on the next save.
	 *
	 * @param string $class_attr The figure's class attribute.
	 * @return array{data:array<string,bool>,tunes:array<string,array<string,string>>}
	 */
	private static function image_layout_from_classes( string $class_attr ): array {
		$classes = preg_split( '/\s+/', trim( $class_attr ) ) ?: array();

		$data   = array();
		$layout = array();

		foreach ( self::IMAGE_STYLE_TUNES as $key => $suffix ) {
			if ( in_array( 'lrn-image--' . $suffix, $classes, true ) ) {
				$data[ $key ] = true;
			}
		}

		foreach ( self::IMAGE_WIDTHS as $width ) {
			if ( '100' !== $width && in_array( 'lrn-image--w-' . $width, $classes, true ) ) {
				$layout['width'] = $width;
			}
		}

		foreach ( self::IMAGE_ALIGNS as $align ) {
			if ( 'center' !== $align && in_array( 'lrn-image--' . $align, $classes, true ) ) {
				$layout['align'] = $align;
			}
		}

		return array(
			'data'  => $data,
			'tunes' => $layout ? array( 'lrnImageLayout' => $layout ) : array(),
		);
	}

	public static function block_to_html( array $block ): string {
		$type = (string) ( $block['type'] ?? '' );
		$d    = (array) ( $block['data'] ?? array() );

		// Empty-state guard — bare text blocks with no content emit nothing.
		if ( in_array( $type, self::TEXT_ONLY, true ) && '' === trim( wp_strip_all_tags( (string) ( $d['text'] ?? '' ) ) ) ) {
			return '';
		}

		$html = '';
		switch ( $type ) {
			case 'paragraph':
				$html = '<p>' . wp_kses_post( $d['text'] ?? '' ) . '</p>';
				break;

			case 'header':
				$level = max( 2, min( 6, (int) ( $d['level'] ?? 2 ) ) );
				$html  = "<h{$level}>" . wp_kses_post( $d['text'] ?? '' ) . "</h{$level}>";
				break;

			case 'list':
				$items = self::filter_items( (array) ( $d['items'] ?? array() ) );
				if ( $items ) {
					$tag  = ( 'ordered' === ( $d['style'] ?? '' ) ) ? 'ol' : 'ul';
					$html = "<{$tag}>";
					foreach ( $items as $item ) {
						$text  = is_string( $item ) ? $item : (string) ( $item['content'] ?? $item['text'] ?? '' );
						$html .= '<li>' . wp_kses_post( $text ) . '</li>';
					}
					$html .= "</{$tag}>";
				}
				break;

			case 'checklist':
				$items = array_values(
					array_filter(
						(array) ( $d['items'] ?? array() ),
						static fn( $i ): bool => '' !== trim( wp_strip_all_tags( (string) ( $i['text'] ?? '' ) ) )
					)
				);
				if ( $items ) {
					$html = '<ul class="lrn-checklist">';
					foreach ( $items as $item ) {
						$checked = ! empty( $item['checked'] ) ? ' checked' : '';
						$html   .= '<li class="lrn-checklist__item' . $checked . '">' . wp_kses_post( (string) ( $item['text'] ?? '' ) ) . '</li>';
					}
					$html .= '</ul>';
				}
				break;

			case 'code':
				$html = '<pre class="lrn-code"><code>' . esc_html( (string) ( $d['code'] ?? '' ) ) . '</code></pre>';
				break;

			case 'quote':
				$text    = wp_kses_post( $d['text'] ?? '' );
				$caption = wp_kses_post( $d['caption'] ?? '' );
				$html    = '<blockquote class="lrn-quote"><p>' . $text . '</p>';
				if ( $caption ) {
					$html .= '<cite>' . $caption . '</cite>';
				}
				$html .= '</blockquote>';
				break;

			case 'warning':
				$title   = wp_kses_post( $d['title'] ?? '' );
				$message = wp_kses_post( $d['message'] ?? '' );
				$html    = '<div class="lrn-warning"><strong>' . $title . '</strong><p>' . $message . '</p></div>';
				break;

			case 'delimiter':
				$html = '<hr class="lrn-delimiter">';
				break;

			case 'image':
				$url     = esc_url( (string) ( $d['file']['url'] ?? $d['url'] ?? '' ) );
				$caption = wp_kses_post( $d['caption'] ?? '' );
				if ( $url ) {
					$classes = self::image_classes( $block );
					$html    = '<figure class="' . esc_attr( implode( ' ', $classes ) ) . '">'
						. '<img src="' . $url . '" alt="' . esc_attr( wp_strip_all_tags( $caption ) ) . '">';
					if ( $caption ) {
						$html .= '<figcaption>' . $caption . '</figcaption>';
					}
					$html .= '</figure>';
				}
				break;

			case 'embed':
				// STABLE, deterministic placeholder — NOT a live Embed::render() call.
				// Embed::render() does a (cached) oEmbed fetch, so calling it here
				// would make this mapping non-deterministic and break the round-trip
				// gate. Instead: `data-embed-url` carries the original (oEmbeddable)
				// URL, and the inline <iframe> is a no-JS fallback + the round-trip
				// recovery anchor (node_to_block reads it back). The display layer
				// (\Learnomy\Content\Embed::hydrate, called from render_editorjs_content)
				// resolves this through the ONE Embed renderer at render time, so every
				// embed surface — lesson type, content body, importer — looks identical.
				$url     = esc_url( (string) ( $d['source'] ?? $d['embed'] ?? '' ) );
				$frame   = esc_url( (string) ( $d['embed'] ?? $d['source'] ?? '' ) );
				$caption = wp_kses_post( $d['caption'] ?? '' );
				if ( $url ) {
					$html = '<div class="lrn-embed" data-embed-url="' . $url . '"><iframe src="' . $frame . '" frameborder="0" allowfullscreen></iframe>';
					if ( $caption ) {
						$html .= '<p class="lrn-embed__caption">' . $caption . '</p>';
					}
					$html .= '</div>';
				}
				break;

			case 'table':
				$rows = (array) ( $d['content'] ?? array() );
				if ( $rows ) {
					$html = '<table class="lrn-table">';
					foreach ( $rows as $i => $row ) {
						$tag   = ( 0 === $i && ! empty( $d['withHeadings'] ) ) ? 'th' : 'td';
						$html .= '<tr>';
						foreach ( (array) $row as $cell ) {
							$html .= "<{$tag}>" . wp_kses_post( (string) $cell ) . "</{$tag}>";
						}
						$html .= '</tr>';
					}
					$html .= '</table>';
				}
				break;

			case 'math':
				$formula = esc_html( (string) ( $d['text'] ?? '' ) );
				if ( $formula ) {
					$html = '<div class="lrn-math" data-formula="' . $formula . '">' . $formula . '</div>';
				}
				break;

			case 'attaches':
				$file_url  = esc_url( (string) ( $d['file']['url'] ?? '' ) );
				$file_name = esc_html( (string) ( $d['title'] ?? $d['file']['name'] ?? 'Download' ) );
				$file_size = esc_html( (string) ( $d['file']['size'] ?? '' ) );
				if ( $file_url ) {
					$html  = '<div class="lrn-attachment"><a href="' . $file_url . '" class="lrn-attachment__link" download>';
					$html .= '<span class="lrn-attachment__name">' . $file_name . '</span>';
					if ( $file_size ) {
						$html .= '<span class="lrn-attachment__size">' . $file_size . '</span>';
					}
					$html .= '</a></div>';
				}
				break;

			case 'raw':
				$raw = (string) ( $d['html'] ?? '' );
				if ( $raw ) {
					// Sanitise only — do NOT expand shortcodes here. block_to_html()
					// runs at STORAGE time (for_storage) as well as display, so
					// expanding a shortcode here would bake its output into storage
					// and lose the shortcode text. render_editorjs_content() runs the
					// one view-time do_shortcode() on the assembled output, so the
					// shortcode stays literal in the editor and renders only in view.
					$html = '<div class="lrn-raw">' . sanitize_lesson_raw_html( $raw ) . '</div>';
				}
				break;

			case 'linkTool':
				$link_url   = esc_url( (string) ( $d['link'] ?? '' ) );
				$link_meta  = (array) ( $d['meta'] ?? array() );
				$link_title = esc_html( (string) ( $link_meta['title'] ?? $link_url ) );
				$link_desc  = esc_html( (string) ( $link_meta['description'] ?? '' ) );
				$link_img   = esc_url( (string) ( $link_meta['image']['url'] ?? '' ) );
				if ( $link_url ) {
					$html = '<a href="' . $link_url . '" class="lrn-link-card" target="_blank" rel="noopener">';
					if ( $link_img ) {
						$html .= '<img src="' . $link_img . '" class="lrn-link-card__image" alt="">';
					}
					$html .= '<div class="lrn-link-card__body">';
					$html .= '<strong class="lrn-link-card__title">' . $link_title . '</strong>';
					if ( $link_desc ) {
						$html .= '<p class="lrn-link-card__desc">' . $link_desc . '</p>';
					}
					$html .= '<span class="lrn-link-card__url">' . esc_html( $link_url ) . '</span>';
					$html .= '</div></a>';
				}
				break;
		}

		return $html;
	}

	/**
	 * Render a full block list to semantic HTML.
	 *
	 * @param array $blocks Editor.js blocks.
	 * @return string
	 */
	public static function to_html( array $blocks ): string {
		$html = '';
		foreach ( $blocks as $block ) {
			$html .= self::block_to_html( (array) $block );
		}
		return $html;
	}

	/**
	 * Parse semantic HTML into Editor.js blocks. The renderer's `lrn-*` marker
	 * classes recover custom block types; unrecognised top-level nodes become a
	 * `raw` block so nothing is ever dropped.
	 *
	 * @param string $html Semantic HTML.
	 * @return array Editor.js blocks (pure structure — no id/time).
	 */
	public static function to_blocks( string $html ): array {
		$html = trim( $html );
		if ( '' === $html ) {
			return array();
		}
		$dom  = self::load_dom( $html );
		$body = $dom ? $dom->getElementsByTagName( 'body' )->item( 0 ) : null;
		if ( ! $body ) {
			return array( self::raw_block( $html ) );
		}

		$blocks = array();
		foreach ( iterator_to_array( $body->childNodes ) as $node ) {
			if ( XML_TEXT_NODE === $node->nodeType ) {
				$text = trim( $node->textContent );
				if ( '' !== $text ) {
					$blocks[] = array(
						'type' => 'paragraph',
						'data' => array( 'text' => esc_html( $text ) ),
					);
				}
				continue;
			}
			if ( XML_ELEMENT_NODE === $node->nodeType ) {
				$block = self::node_to_block( $node );
				if ( null !== $block ) {
					$blocks[] = $block;
				}
			}
		}
		return $blocks;
	}

	/**
	 * The normalized canonical form: parse + re-serialize (deterministic
	 * attribute order, whitespace, void-element + entity form) and strip
	 * Editor.js `cdx-*`/`ce-*` inline classes (INV-1). Used by the round-trip
	 * gate so legitimate normalization is never a "loss," and as the stored form.
	 *
	 * @param string $html HTML to canonicalize.
	 * @return string
	 */
	public static function canonicalize( string $html ): string {
		// The stored/canonical form = render-floor sanitize (INV-6: never carries
		// script/on* handlers, whoever produced it) THEN structural normalize.
		return self::normalize_structure( self::sanitize( $html, 'render' ) );
	}

	/**
	 * Deterministic structural form (DOM re-serialize + strip `cdx-*` + collapse
	 * inter-tag whitespace) WITHOUT sanitizing — so the save gate
	 * (within_allowlist) can compare like-for-like and still see what the
	 * allow-list would strip (incl. floor-level script).
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function normalize_structure( string $html ): string {
		$html = self::strip_cdx( trim( $html ) );
		if ( '' === $html ) {
			return '';
		}
		$dom  = self::load_dom( $html );
		$body = $dom ? $dom->getElementsByTagName( 'body' )->item( 0 ) : null;
		if ( ! $body ) {
			return $html;
		}
		$out = '';
		foreach ( $body->childNodes as $child ) {
			$out .= $dom->saveHTML( $child );
		}
		$out = preg_replace( '/>\s+</', '><', (string) $out );
		return trim( (string) $out );
	}

	// ── sanitization contract (Phase 2 / INV-6 / G1) ────────────────────

	/**
	 * The role-aware content allow-list. Base is `wp_kses_allowed_html('post')`
	 * (which already drops `<script>` + `on*` handlers) plus `<iframe>` for
	 * embeds and the engine's own attributes (math `data-formula`, attachment
	 * `download`). Reuses the same base as `render_lesson_raw_html()` /
	 * `Blocks::allowed_html()`. Filterable via `learnomy_content_allowed_html`.
	 *
	 * @param int|string $context Author user-id (save gate) or 'render' (the
	 *                            floor applied to every role's output).
	 * @return array<string, array<string, bool>> wp_kses allow-list.
	 */
	public static function allowed_html( $context = 'render' ): array {
		$allowed = wp_kses_allowed_html( 'post' );

		// Embeds + the engine's iframe-bearing blocks.
		$allowed['iframe'] = array(
			'src'             => true,
			'srcdoc'          => true,
			'width'           => true,
			'height'          => true,
			'frameborder'     => true,
			'allow'           => true,
			'allowfullscreen' => true,
			'loading'         => true,
			'referrerpolicy'  => true,
			'sandbox'         => true,
			'title'           => true,
			'class'           => true,
			'id'              => true,
			'style'           => true,
			'name'            => true,
		);
		// Attributes the engine emits that 'post' does not allow by default.
		$allowed['div'] = (array) ( $allowed['div'] ?? array() ) + array(
			'data-formula'   => true,
			'data-embed-url' => true, // embed placeholder carries the original URL for display-time hydration
		);
		$allowed['a']   = (array) ( $allowed['a'] ?? array() ) + array(
			'download'  => true,
			'data-name' => true,
			'target'    => true,
			'rel'       => true,
		);

		// Defensive: `wp_kses_allowed_html('post')` permits a few tags that have
		// no place in lesson/course body content and are real injection vectors
		// (object/embed/applet can load arbitrary content). Drop them outright.
		foreach ( array( 'object', 'embed', 'applet', 'form', 'input', 'button', 'select', 'textarea', 'style', 'link', 'meta', 'base' ) as $risky ) {
			unset( $allowed[ $risky ] );
		}

		// Role narrowing: only site owners may store a raw `<iframe>`. Instructors
		// embed through the Editor.js embed tool (a known YouTube/Vimeo iframe the
		// engine emits), so a raw iframe in their save is outside their allow-list
		// and is rejected (within_allowlist) rather than silently kept.
		$is_admin = ( 'render' === $context ) || ( is_numeric( $context ) && user_can( (int) $context, 'manage_options' ) );
		if ( ! $is_admin ) {
			unset( $allowed['iframe'] );
		}

		/**
		 * Filter the content allow-list (save gate + render floor).
		 *
		 * @param array      $allowed wp_kses allow-list map.
		 * @param int|string $context Author user-id, or 'render' for the floor.
		 */
		return (array) apply_filters( 'learnomy_content_allowed_html', $allowed, $context );
	}

	/**
	 * Sanitize content HTML against the (role-aware) allow-list.
	 *
	 * @param string     $html    Content HTML.
	 * @param int|string $context Author user-id, or 'render' for the floor.
	 * @return string
	 */
	public static function sanitize( string $html, $context = 'render' ): string {
		return wp_kses( $html, self::allowed_html( $context ) );
	}

	/**
	 * Whether $html stays intact under the author's role allow-list — i.e.
	 * nothing would be stripped.
	 *
	 * READY BUT NOT YET WIRED: this is the save-side reject primitive for the
	 * role-narrowing half of INV-6 (reject, not silently strip, content an
	 * author's role may not store). As of 1.2.1 no save path calls it — saves run
	 * the role-blind render floor via for_storage()/canonicalize(), which strips
	 * script tags, on* handlers and javascript: URLs for everyone (no XSS) but
	 * does NOT enforce the per-role narrowing (instructor raw iframe kept, not
	 * rejected).
	 * Wiring it on save (thread the author user-id through the save path) is a
	 * later phase; it is exercised today only by the round-trip gate + XSS corpus.
	 *
	 * @param string $html    Content HTML.
	 * @param int    $user_id Author.
	 * @return bool
	 */
	public static function within_allowlist( string $html, int $user_id ): bool {
		return self::normalize_structure( self::sanitize( $html, $user_id ) ) === self::normalize_structure( $html );
	}

	// ── storage cutover (Phase 3 / INV-8 / INV-9) ───────────────────────

	/**
	 * Whether content is stored as semantic HTML (the cutover). ON by default —
	 * new edits store canonical HTML and the background migrator converts existing
	 * content (after a one-time auto-backup). Reads stay dual-format regardless
	 * (INV-8), and the REST `content` field is normalised back to Editor.js JSON
	 * so the app is unaffected. Set the `learnomy_store_content_as_html` filter to
	 * false to opt a site out (kill-switch); reverts are byte-exact via
	 * `wp learnomy content restore`.
	 *
	 * @return bool
	 */
	public static function storage_enabled(): bool {
		return (bool) apply_filters( 'learnomy_store_content_as_html', true );
	}

	/**
	 * Detect whether a stored/incoming value is an Editor.js JSON document.
	 *
	 * @param string $value Raw value.
	 * @return bool
	 */
	public static function is_blocks_json( string $value ): bool {
		$value = ltrim( $value );
		if ( '' === $value || '{' !== $value[0] ) {
			return false;
		}
		$decoded = json_decode( $value, true );
		return is_array( $decoded ) && isset( $decoded['blocks'] ) && is_array( $decoded['blocks'] );
	}

	/**
	 * Convert an incoming content value (Editor.js JSON from an editor, or raw
	 * HTML from an importer) into the canonical stored HTML. The ONE save-path
	 * converter (INV-9): every producer routes through here, so wp-admin and the
	 * Pro builder cannot diverge.
	 *
	 * @param string $incoming Editor.js JSON or HTML.
	 * @return string Canonical semantic HTML.
	 */
	public static function for_storage( string $incoming ): string {
		if ( self::is_blocks_json( $incoming ) ) {
			$decoded = json_decode( $incoming, true );
			return self::canonicalize( self::to_html( (array) ( $decoded['blocks'] ?? array() ) ) );
		}
		return self::canonicalize( $incoming );
	}

	/**
	 * Convert a stored value (legacy Editor.js JSON OR new HTML) into the
	 * Editor.js JSON document the editors + the REST `content` field expect, so
	 * every consumer sees a uniform shape no matter how the row is stored
	 * (INV-8). Keeps the app on JSON during the deprecation window.
	 *
	 * @param string $stored Stored content.
	 * @return string Editor.js JSON document.
	 */
	public static function for_editor( string $stored ): string {
		$stored = trim( $stored );
		if ( '' === $stored ) {
			return '';
		}
		if ( self::is_blocks_json( $stored ) ) {
			return $stored; // Already blocks JSON (legacy) — pass through.
		}
		return (string) wp_json_encode(
			array(
				'time'    => 0,
				'blocks'  => self::to_blocks( $stored ),
				'version' => 'html-first',
			)
		);
	}

	/**
	 * Normalise a content/description field across a set of REST list rows so
	 * LIST endpoints match the single-GET contract (R1/INV-8): the field is
	 * returned as Editor.js JSON (for_editor) and a sibling `<field>_html`
	 * carries canonical HTML — whatever the row stores. Mutates object rows in
	 * place and rewrites array rows; returns the set either way.
	 *
	 * @param array  $rows  Model rows (objects or arrays).
	 * @param string $field 'content' or 'description'.
	 * @return array
	 */
	public static function normalize_rows( array $rows, string $field ): array {
		foreach ( $rows as $i => $row ) {
			$is_obj = is_object( $row );
			$val    = $is_obj ? ( $row->$field ?? null ) : ( $row[ $field ] ?? null );
			if ( ! is_string( $val ) || '' === $val ) {
				continue;
			}
			$json = self::for_editor( $val );
			$html = self::for_storage( $val );
			if ( $is_obj ) {
				$row->$field             = $json;
				$row->{$field . '_html'} = $html;
			} else {
				$row[ $field ]           = $json;
				$row[ $field . '_html' ] = $html;
				$rows[ $i ]              = $row;
			}
		}
		return $rows;
	}

	// ── parser node mapping ─────────────────────────────────────────────

	private static function node_to_block( \DOMElement $el ): ?array {
		$tag     = strtolower( $el->tagName );
		$classes = self::classes( $el );

		if ( preg_match( '/^h([1-6])$/', $tag, $m ) ) {
			return array(
				'type' => 'header',
				'data' => array(
					'text'  => self::inner( $el ),
					'level' => max( 2, (int) $m[1] ),
				),
			);
		}

		switch ( $tag ) {
			case 'p':
				return array(
					'type' => 'paragraph',
					'data' => array( 'text' => self::inner( $el ) ),
				);

			case 'hr':
				return array(
					'type' => 'delimiter',
					'data' => array(),
				);

			case 'ul':
				if ( in_array( 'lrn-checklist', $classes, true ) ) {
					$items = array();
					foreach ( $el->getElementsByTagName( 'li' ) as $li ) {
						$cls     = $li->getAttribute( 'class' );
						$items[] = array(
							'text'    => self::inner( $li ),
							'checked' => false !== strpos( $cls, 'checked' ),
						);
					}
					return array(
						'type' => 'checklist',
						'data' => array( 'items' => $items ),
					);
				}
				return array(
					'type' => 'list',
					'data' => array(
						'style' => 'unordered',
						'items' => self::list_items( $el ),
					),
				);

			case 'ol':
				return array(
					'type' => 'list',
					'data' => array(
						'style' => 'ordered',
						'items' => self::list_items( $el ),
					),
				);

			case 'pre':
				$code = $el->getElementsByTagName( 'code' )->item( 0 );
				return array(
					'type' => 'code',
					'data' => array( 'code' => $code ? $code->textContent : $el->textContent ),
				);

			case 'blockquote':
				$p    = $el->getElementsByTagName( 'p' )->item( 0 );
				$cite = $el->getElementsByTagName( 'cite' )->item( 0 );
				return array(
					'type' => 'quote',
					'data' => array(
						'text'    => $p ? self::inner( $p ) : self::inner( $el ),
						'caption' => $cite ? self::inner( $cite ) : '',
					),
				);

			case 'figure':
				$img = $el->getElementsByTagName( 'img' )->item( 0 );
				if ( $img ) {
					$cap = $el->getElementsByTagName( 'figcaption' )->item( 0 );

					// Read the layout back out of the classes. The exact inverse of
					// image_classes() — without this, every size/alignment the author
					// chose is erased the next time the content round-trips.
					$layout = self::image_layout_from_classes( $el->getAttribute( 'class' ) );

					$block = array(
						'type' => 'image',
						'data' => array(
							'file'    => array( 'url' => $img->getAttribute( 'src' ) ),
							'caption' => $cap ? self::inner( $cap ) : '',
						) + $layout['data'],
					);
					if ( $layout['tunes'] ) {
						$block['tunes'] = $layout['tunes'];
					}

					return $block;
				}
				break;

			case 'table':
				$content  = array();
				$headings = false;
				foreach ( $el->getElementsByTagName( 'tr' ) as $tr ) {
					$cells = array();
					foreach ( iterator_to_array( $tr->childNodes ) as $cell ) {
						if ( XML_ELEMENT_NODE !== $cell->nodeType ) {
							continue;
						}
						if ( 'th' === strtolower( $cell->tagName ) ) {
							$headings = true;
						}
						$cells[] = self::inner( $cell );
					}
					if ( $cells ) {
						$content[] = $cells;
					}
				}
				return array(
					'type' => 'table',
					'data' => array(
						'withHeadings' => $headings,
						'content'      => $content,
					),
				);

			case 'div':
				if ( in_array( 'lrn-warning', $classes, true ) ) {
					$strong = $el->getElementsByTagName( 'strong' )->item( 0 );
					$p      = $el->getElementsByTagName( 'p' )->item( 0 );
					return array(
						'type' => 'warning',
						'data' => array(
							'title'   => $strong ? self::inner( $strong ) : '',
							'message' => $p ? self::inner( $p ) : '',
						),
					);
				}
				if ( in_array( 'lrn-math', $classes, true ) ) {
					$formula = $el->getAttribute( 'data-formula' );
					return array(
						'type' => 'math',
						'data' => array( 'text' => '' !== $formula ? $formula : $el->textContent ),
					);
				}
				if ( in_array( 'lrn-embed', $classes, true ) ) {
					$iframe = $el->getElementsByTagName( 'iframe' )->item( 0 );
					$cap    = $el->getElementsByTagName( 'p' )->item( 0 );
					$src    = $iframe ? $iframe->getAttribute( 'src' ) : '';
					$source = $el->getAttribute( 'data-embed-url' );
					return array(
						'type' => 'embed',
						'data' => array(
							'embed'   => $src,
							// The original/oEmbeddable URL (carried in data-embed-url);
							// fall back to the iframe src for legacy rows that predate it.
							'source'  => '' !== $source ? $source : $src,
							'caption' => $cap ? self::inner( $cap ) : '',
						),
					);
				}
				if ( in_array( 'lrn-attachment', $classes, true ) ) {
					$a    = $el->getElementsByTagName( 'a' )->item( 0 );
					$name = $el->getElementsByTagName( 'span' )->item( 0 );
					return array(
						'type' => 'attaches',
						'data' => array(
							'file'  => array( 'url' => $a ? $a->getAttribute( 'href' ) : '' ),
							'title' => $name ? trim( $name->textContent ) : '',
						),
					);
				}
				if ( in_array( 'lrn-raw', $classes, true ) ) {
					return array(
						'type' => 'raw',
						'data' => array( 'html' => self::inner( $el ) ),
					);
				}
				break;

			case 'a':
				if ( in_array( 'lrn-link-card', $classes, true ) ) {
					$title = null;
					$desc  = null;
					$img   = $el->getElementsByTagName( 'img' )->item( 0 );
					foreach ( $el->getElementsByTagName( '*' ) as $child ) {
						$c = $child->getAttribute( 'class' );
						if ( false !== strpos( $c, 'lrn-link-card__title' ) ) {
							$title = trim( $child->textContent );
						} elseif ( false !== strpos( $c, 'lrn-link-card__desc' ) ) {
							$desc = trim( $child->textContent );
						}
					}
					$meta = array( 'title' => (string) $title );
					if ( null !== $desc ) {
						$meta['description'] = $desc;
					}
					if ( $img ) {
						$meta['image'] = array( 'url' => $img->getAttribute( 'src' ) );
					}
					return array(
						'type' => 'linkTool',
						'data' => array(
							'link' => $el->getAttribute( 'href' ),
							'meta' => $meta,
						),
					);
				}
				break;
		}

		return self::raw_block( self::outer( $el ) );
	}

	// ── helpers ─────────────────────────────────────────────────────────

	public static function strip_cdx( string $html ): string {
		return (string) preg_replace_callback(
			'/\sclass="([^"]*)"/',
			static function ( array $m ): string {
				$kept = array_filter(
					(array) preg_split( '/\s+/', $m[1] ),
					static fn( $c ): bool => '' !== $c && ! preg_match( '/^(cdx-|ce-)|^inline-code$/', $c )
				);
				return $kept ? ' class="' . implode( ' ', $kept ) . '"' : '';
			},
			$html
		);
	}

	private static function filter_items( array $items ): array {
		return array_values(
			array_filter(
				$items,
				static function ( $item ): bool {
					$text = is_string( $item ) ? $item : ( $item['content'] ?? $item['text'] ?? '' );
					return '' !== trim( wp_strip_all_tags( (string) $text ) );
				}
			)
		);
	}

	private static function list_items( \DOMElement $el ): array {
		$items = array();
		foreach ( iterator_to_array( $el->childNodes ) as $li ) {
			if ( XML_ELEMENT_NODE === $li->nodeType && 'li' === strtolower( $li->tagName ) ) {
				$items[] = self::inner( $li );
			}
		}
		return $items;
	}

	private static function classes( \DOMElement $el ): array {
		return array_values( array_filter( (array) preg_split( '/\s+/', trim( $el->getAttribute( 'class' ) ) ) ) );
	}

	private static function inner( \DOMNode $node ): string {
		$html = '';
		foreach ( $node->childNodes as $child ) {
			$html .= $node->ownerDocument->saveHTML( $child );
		}
		return self::strip_cdx( trim( $html ) );
	}

	private static function outer( \DOMNode $node ): string {
		return (string) $node->ownerDocument->saveHTML( $node );
	}

	private static function raw_block( string $html ): array {
		return array(
			'type' => 'raw',
			'data' => array( 'html' => trim( $html ) ),
		);
	}

	private static function load_dom( string $html ): ?\DOMDocument {
		$dom      = new \DOMDocument( '1.0', 'UTF-8' );
		$previous = libxml_use_internal_errors( true );
		$ok       = $dom->loadHTML(
			'<?xml encoding="UTF-8"><body>' . $html . '</body>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		return $ok ? $dom : null;
	}
}
