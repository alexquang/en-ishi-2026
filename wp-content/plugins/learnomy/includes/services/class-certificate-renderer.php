<?php
/**
 * Certificate renderer — converts a design_json template into HTML, then optionally PDF.
 *
 * The designer saves templates as JSON with elements at percentage-based positions.
 * This renderer resolves merge tags, builds a Dompdf-compatible HTML document
 * (absolute positioning, no flexbox), and hands it to Dompdf for PDF output.
 *
 * @package Learnomy
 */

namespace Learnomy\Services;

defined( 'ABSPATH' ) || exit;

class Certificate_Renderer {

	/**
	 * Width of the box we centre text in, in millimetres.
	 *
	 * This is the old `width: 300px` expressed in the units the rest of the
	 * geometry uses (300px @ 96dpi = 79.375mm). Centre-aligned text has no
	 * declared width in design_json, so we give it a fixed box and centre THAT on
	 * the element's x — which lands the text's centre on x without having to
	 * measure glyphs. Changing this number changes where existing text wraps, so
	 * it is deliberately the same value the renderer has always used.
	 */
	private const TEXT_BOX_MM = 79.375;

	/**
	 * Slack added when sizing a box to fit a person's name on one line.
	 *
	 * The width estimate is a per-character average, so it lands close but not
	 * exact - measured against a browser it came in 1.4% under for "Renata
	 * Kowalski". Rounding a name's box to exactly the estimate would leave the
	 * ones that sit on the boundary wrapping anyway, which is the whole bug.
	 */
	private const NAME_FIT_SLACK = 1.08;

	/**
	 * Floor for shrink-to-fit on a name, as a fraction of its designed size.
	 *
	 * Only reached by a name too long to fit the canvas at its designed size.
	 * Past this the text would be smaller than the body copy around it, and an
	 * unreadable name is worse than a wrapped one.
	 */
	private const NAME_MIN_SCALE = 0.55;

	/**
	 * Width of a signature block, in millimetres.
	 *
	 * A signature is shrink-to-fit in the designer — its width is essentially the
	 * rule above the label (120px, certificate-designer.js:618). Centring it in the
	 * full TEXT_BOX_MM instead would push it 39.7mm left of where it was designed,
	 * which is what threw course-modern's "Instructor" block clean off the left
	 * edge of the page.
	 */
	private const SIGNATURE_BOX_MM = 31.75;

	/** Points → millimetres. */
	private const PT_TO_MM = 0.352778;

	/**
	 * Rough width of one glyph as a fraction of the font size.
	 *
	 * Dompdf ships real font metrics, but they are only reachable once a Canvas
	 * exists — i.e. after layout, which is too late to influence it. So the wrap
	 * guard estimates instead. These ratios are tuned for the serif faces the
	 * seeded designs use; they only need to be good enough to tell "this will
	 * wrap into the element below" from "this will not".
	 */
	private const GLYPH_RATIO      = 0.50;
	private const GLYPH_RATIO_BOLD = 0.57;

	/** Line box as a multiple of font size. */
	private const LINE_HEIGHT = 1.2;

	/** Clearance we insist on between a text block and whatever sits below it. */
	private const COLLISION_GAP_MM = 2.0;

	/** Never shrink a font below this, however long the text. */
	private const MIN_FONT_PT = 9.0;

	/**
	 * Render a certificate as an HTML string.
	 *
	 * Works with or without a template. When no template is attached, a clean
	 * default layout is used so the certificate is always presentable.
	 *
	 * @param object      $certificate Certificate row from the database.
	 * @param object|null $template    CertificateTemplate row, or null for the default layout.
	 * @return string Full HTML document.
	 */
	public function render_html( object $certificate, ?object $template = null ): string {
		$merge_data = $this->get_merge_data( $certificate );

		if ( $template && ! empty( $template->design_json ) ) {
			$design = json_decode( $template->design_json, true );
			if ( is_array( $design ) && ! empty( $design['elements'] ) ) {
				// `! empty( elements )` only proves the array has rows, not that
				// they render anything — which is how a blank certificate shipped
				// (Basecamp 10146577178). build_html_from_design() returns '' when
				// no element produced content, so an unusable design falls through
				// to the default layout rather than out to the learner.
				$designed = $this->build_html_from_design( $design, $merge_data );
				if ( '' !== $designed ) {
					return $designed;
				}
			}
		}

		// Fallback: a sensible default when there is no design_json, or when the
		// design produced nothing renderable.
		return $this->build_default_html( $merge_data );
	}

	/**
	 * Resolve and sanitize the certificate footer text from Settings.
	 *
	 * Returns an empty string when the field is blank — callers should skip
	 * rendering the footer block entirely in that case so the cert layout
	 * doesn't gain an empty `<div>` with stray padding.
	 *
	 * Uses wp_kses_post so admins can include light HTML (bold, line breaks,
	 * inline links to terms of service) without opening an XSS surface on
	 * the rendered cert. Plain text passes through unchanged.
	 *
	 * Wave 2E (2026-05-16): row 2E-5 in plan/2026-05-16-token-ready-sweep/
	 * audit-B-data-flow.yaml. Closes the dead-field wiring gap where the
	 * admin Footer Text field was saved but never rendered.
	 */
	private function get_footer_html(): string {
		$settings = get_option( 'learnomy_settings', array() );
		$raw      = (string) ( $settings['certificate_footer'] ?? '' );

		if ( '' === trim( $raw ) ) {
			return '';
		}

		return wp_kses_post( $raw );
	}

	/**
	 * Render a certificate as a PDF binary string via Dompdf.
	 *
	 * Returns an empty string when Dompdf is not available — callers should
	 * check the return value and fall back to HTML if needed.
	 *
	 * @param object      $certificate Certificate row.
	 * @param object|null $template    CertificateTemplate row.
	 * @return string Raw PDF bytes, or empty string on failure.
	 */
	public function render_pdf( object $certificate, ?object $template = null ): string {
		if ( ! self::dompdf_available() ) {
			return '';
		}

		$html = $this->render_html( $certificate, $template );

		// Resolve orientation from the template's design_json, then its
		// top-level orientation column, then fall back to landscape (the
		// standard orientation for certificates). Pass it explicitly to the
		// static helper so setPaper() receives the correct axis hint.
		$orientation = 'landscape';
		if ( $template && ! empty( $template->design_json ) ) {
			$design      = json_decode( $template->design_json, true );
			$orientation = $design['orientation'] ?? $template->orientation ?? 'landscape';
		} elseif ( $template && ! empty( $template->orientation ) ) {
			$orientation = $template->orientation;
		}

		// Delegate to the static helper so the Dompdf-init block lives in
		// exactly one place (static render_html_to_pdf). The paper dimensions
		// below match A4 in the identified orientation; the static helper
		// normalises 'A4' + orientation the same way, so this delegate is
		// pixel-identical to the old inline init.
		return self::render_html_to_pdf(
			$html,
			array(
				'paper'       => 'A4',
				'orientation' => $orientation,
			)
		);
	}

	/**
	 * Render a template preview with sample data — used by the certificate designer.
	 *
	 * @param object $template CertificateTemplate row.
	 * @return string Full HTML document with dummy merge-tag values.
	 */
	public function render_preview( object $template ): string {
		$sample = $this->get_sample_merge_data();

		if ( ! empty( $template->design_json ) ) {
			$design = json_decode( $template->design_json, true );
			if ( is_array( $design ) && ! empty( $design['elements'] ) ) {
				return $this->build_html_from_design( $design, $sample );
			}
		}

		return $this->build_default_html( $sample );
	}

	/**
	 * Check whether the Dompdf library is loaded and available.
	 *
	 * @return bool
	 */
	public static function dompdf_available(): bool {
		// Load the committed, scoped runtime libraries (libs/) if the main
		// bootstrap hasn't already. No `composer install` required.
		$autoloader = LEARNOMY_DIR . 'libs/autoload.php';
		if ( file_exists( $autoloader ) ) {
			require_once $autoloader;
		}

		// A partially-present libs/ tree (autoload.php there, but a class file
		// missing — e.g. a security scanner quarantined it, or a truncated
		// upload) makes the scoped autoloader hard-require a missing file,
		// which throws through class_exists(). Degrade to "unavailable" so the
		// certificate flow falls back to the print-to-PDF HTML response instead
		// of fataling — honouring this method's documented contract.
		try {
			return class_exists( \Learnomy\Vendor\Dompdf\Dompdf::class );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Render an HTML document to a PDF binary string through the scoped
	 * dompdf shipped in libs/.
	 *
	 * This is the single public entry point for HTML→PDF in the plugin pair.
	 * Pro surfaces (e.g. analytics report export) call this instead of
	 * touching the scoped `Learnomy\Vendor\Dompdf` namespace directly, so the
	 * vendored library stays a Free-internal implementation detail — Pro
	 * reuses Free, never reaches into its internals.
	 *
	 * Returns an empty string when dompdf is unavailable so callers can fall
	 * back to a print-to-PDF HTML response.
	 *
	 * @param string               $html Full HTML document.
	 * @param array<string, mixed> $args paper / orientation / default_font / remote_enabled overrides.
	 * @return string PDF bytes, or '' when dompdf is unavailable.
	 */
	public static function render_html_to_pdf( string $html, array $args = array() ): string {
		if ( ! self::dompdf_available() ) {
			return '';
		}

		$args = wp_parse_args(
			$args,
			array(
				'paper'          => 'A4',
				'orientation'    => 'portrait',
				'default_font'   => 'serif',
				'remote_enabled' => true,
			)
		);

		// Buffer every line that touches Dompdf. Callers stream the return value
		// as a binary body, so ANY stray byte emitted here ends up prepended to
		// the PDF and the file stops parsing — the browser just reports
		// "Failed to load PDF" (Basecamp 10135024132).
		//
		// The buffer spans the whole block rather than just the render call
		// because the bundled Dompdf leaks from more than one place.
		//
		// Helpers::dompdf_getimagesize() destructures the return of
		// getimagesize() unconditionally, so any image it cannot measure (an
		// SVG data-URI, or an unreachable remote URL) raises "Cannot use bool
		// as array". Separately, on PHP 8.4 both Options::__construct() and
		// Image\Cache emit deprecation notices, and those fire before
		// rendering even begins.
		//
		// With display_errors on, any one of them corrupts the download. It is
		// worse than a cosmetic prefix: Content-Length is computed from the PDF
		// alone, so the leaked bytes push the real trailer past the declared
		// length and the file loses its %%EOF entirely.
		//
		// The guard belongs here rather than at the call site because this is
		// the one place Dompdf runs: it covers Free's certificate download and
		// Pro's analytics report export in a single fix.
		$pdf = '';
		ob_start();
		try {
			$options = new \Learnomy\Vendor\Dompdf\Options();
			$options->set( 'isRemoteEnabled', (bool) $args['remote_enabled'] );
			$options->set( 'defaultFont', (string) $args['default_font'] );

			// Shared font/temp cache in the uploads dir so every PDF surface
			// warms one cache instead of polluting the plugin folder.
			$upload_dir = wp_upload_dir();
			$cache_dir  = trailingslashit( $upload_dir['basedir'] ) . 'learnomy-pdf-cache';
			if ( ! is_dir( $cache_dir ) ) {
				wp_mkdir_p( $cache_dir );
			}
			$options->set( 'fontDir', $cache_dir );
			$options->set( 'fontCache', $cache_dir );

			$dompdf = new \Learnomy\Vendor\Dompdf\Dompdf( $options );
			$dompdf->loadHtml( $html );
			$dompdf->setPaper( $args['paper'], $args['orientation'] );
			$dompdf->render();

			$pdf = (string) $dompdf->output();
		} finally {
			$leaked = (string) ob_get_clean();
		}

		// Surface the leak for developers instead of swallowing it silently —
		// it usually means a certificate image is unreachable and is missing
		// from the PDF, which is worth knowing even though the file is valid.
		if ( '' !== trim( $leaked ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Learnomy: PDF renderer emitted unexpected output (suppressed): ' . $leaked ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		return $pdf;
	}

	// -------------------------------------------------------------------------
	// Merge data
	// -------------------------------------------------------------------------

	/**
	 * Resolve merge-tag values from a real certificate row.
	 *
	 * Every value that the document ASSERTS comes from the certificate's own
	 * snapshot (`Certificate_Service::asserted_facts()`), not from a live lookup:
	 * renaming a course, renaming the site, an instructor leaving, or a later
	 * quiz retake must not rewrite a credential somebody already earned, and
	 * deleting the course must not blank the sentence in the middle of the page
	 * (Basecamp 10148480574). The live rows are consulted only for certificates
	 * issued before the snapshot columns existed.
	 *
	 * The three tags that stay live are the ones that are not assertions:
	 * `{verify_url}` is an address and has to follow the site wherever it moves,
	 * `{certificate_id}` is immutable already, and `{completion_date}` is
	 * formatted from the immutable `issued_at` so it prints in the reader's
	 * locale rather than in whatever locale was active years ago.
	 *
	 * @param object $certificate Certificate row.
	 * @return array<string, string> Map of merge tags to display values.
	 */
	private function get_merge_data( object $certificate ): array {
		$facts = Certificate_Service::asserted_facts( $certificate );

		$verify_url = \Learnomy\route_url( 'certificate-verify', array( 'slug' => (string) $certificate->uuid ) );

		// Best graded quiz score, as recorded. Certificates for courses without a
		// graded quiz leave {score} empty rather than rendering "0%".
		$score_text = null === $facts['score']
			? ''
			: rtrim( rtrim( number_format_i18n( (float) $facts['score'], 1 ), '0' ), '.' ) . '%';

		return array(
			'{student_name}'    => $facts['recipient_name'],
			// The printed line, so a certificate whose course record is gone
			// still reads as a sentence instead of trailing off after "has
			// successfully completed".
			'{course_title}'    => Certificate_Service::course_display_title( $facts ),
			'{completion_date}' => wp_date( get_option( 'date_format' ), strtotime( $certificate->issued_at ) ),
			'{score}'           => $score_text,
			'{hours}'           => null === $facts['hours'] ? '' : (string) round( (float) $facts['hours'], 1 ),
			'{certificate_id}'  => $certificate->uuid,
			'{instructor_name}' => $facts['instructor_name'],
			'{site_name}'       => $facts['issuer_name'],
			'{verify_url}'      => $verify_url,
		);
	}

	/**
	 * Sample merge-tag values for the template designer preview.
	 *
	 * @return array<string, string>
	 */
	private function get_sample_merge_data(): array {
		return array(
			'{student_name}'    => 'Jane Doe',
			'{course_title}'    => 'Introduction to Web Development',
			'{completion_date}' => wp_date( get_option( 'date_format' ) ),
			'{score}'           => '92%',
			'{hours}'           => '12.5',
			'{certificate_id}'  => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
			'{instructor_name}' => 'John Smith',
			'{site_name}'       => get_bloginfo( 'name' ),
			'{verify_url}'      => \Learnomy\route_url( 'certificate-verify', array( 'slug' => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890' ) ),
		);
	}

	// -------------------------------------------------------------------------
	// HTML builders
	// -------------------------------------------------------------------------

	/**
	 * Build a full HTML document from design_json elements.
	 *
	 * Uses only absolute positioning and basic CSS — Dompdf does not support
	 * flexbox, grid, or most modern CSS features.
	 *
	 * @param array $design     Decoded design_json.
	 * @param array $merge_data Merge-tag values.
	 * @return string
	 */
	private function build_html_from_design( array $design, array $merge_data ): string {
		$orientation = $design['orientation'] ?? 'landscape';
		$width       = 'landscape' === $orientation ? '297mm' : '210mm';
		$height      = 'landscape' === $orientation ? '210mm' : '297mm';

		$canvas_w = 'landscape' === $orientation ? 297.0 : 210.0;
		$canvas_h = 'landscape' === $orientation ? 210.0 : 297.0;

		$bg_color = $design['background']['color'] ?? '#ffffff';
		$bg_image = $design['background']['image'] ?? null;

		$bg_image_css = '';
		if ( $bg_image ) {
			$bg_image_url = esc_url( $bg_image );
			$bg_image_css = "background-image: url('{$bg_image_url}'); background-size: cover; background-position: center;";
		}

		$elements = array_values( (array) ( $design['elements'] ?? array() ) );

		// Resolve every element's real box first, THEN emit CSS. The geometry pass
		// has to happen up front because the wrap guard below needs to know what
		// sits underneath a block of text before it can decide whether that text
		// will collide with it.
		$boxes = array();
		foreach ( $elements as $i => $el ) {
			$boxes[ $i ] = $this->element_box( $el, $canvas_w, $canvas_h, $merge_data );
		}
		$boxes = $this->apply_wrap_guard( $boxes, $canvas_h );

		$element_styles = '';
		$element_html   = '';

		foreach ( $elements as $i => $el ) {
			$box   = $boxes[ $i ];
			$id    = sanitize_html_class( $el['id'] ?? wp_generate_uuid4() );
			$x     = floatval( $el['x'] ?? 50 );
			$y     = floatval( $el['y'] ?? 50 );
			$font  = sanitize_text_field( $el['font'] ?? 'Georgia' );
			$color = sanitize_hex_color( $el['color'] ?? '#000000' ) ?: '#000000';
			$bold  = ! empty( $el['bold'] ) ? 'font-weight: bold;' : '';
			$align = $box['align'];

			// Reproduce the designer's transform, which is the authoritative
			// coordinate contract: (x, y) is the element's CENTER.
			//
			// certificate-designer.js:688 offsets a sized element by translate(-50%,
			// -50%) — half its OWN width and half its OWN height. This renderer used
			// to fake that with a flat `margin-left: -150px; width: 300px`, and had
			// no vertical counterpart at all. Since `align` defaults to 'center' and
			// the seeded qr_code/image/line/signature elements carry no align key,
			// every one of them got that -150px and was dragged 39.69mm to the left
			// of where it was designed — which is the QR-over-"Director" overlap, the
			// signature that fell off the left edge of course-modern, and the border
			// frame that never rendered. Dompdf has no `transform`, so we do the same
			// arithmetic in millimetres, per element, per axis.
			// `left`/`top` put the box's LEADING edge on the coordinate; the margins
			// then shift it to where element_box() says it actually belongs. Deriving
			// the offset from the resolved box (instead of special-casing each type
			// here) means the CSS cannot drift from the geometry — centred, top-left
			// anchored, and text-aligned elements all fall out of the same two lines.
			$x_mm   = $x / 100 * $canvas_w;
			$y_mm   = $y / 100 * $canvas_h;
			$ml     = $box['left'] - $x_mm;
			$mt     = $box['top'] - $y_mm;
			$offset = sprintf( 'margin-left: %.3fmm; margin-top: %.3fmm; ', $ml, $mt );

			// Only centre-aligned text needs a declared width — it is the box its
			// centring is computed against. Left/right text stays shrink-to-fit, as
			// the designer draws it.
			if ( ! empty( $box['fixed_box'] ) ) {
				$offset .= sprintf( 'width: %.3fmm; ', $box['w'] );
			}

			$size_css = sprintf( 'font-size: %.1fpt; ', $box['font_pt'] );

			$element_styles .= ".el-{$id} { "
				. 'position: absolute; '
				. "left: {$x}%; top: {$y}%; "
				. sprintf( 'font-family: %s; ', self::font_stack( $font ) )
				. $size_css
				. "color: {$color}; "
				. "{$bold} "
				. "text-align: {$align}; "
				. $offset
				. "}\n";

			$element_html .= $this->render_element( $el, $id, $color, $merge_data, $box );
		}

		// A design that renders NOTHING must not become a certificate.
		//
		// render_html() used to accept any design whose `elements` key was a
		// non-empty array, which is not the same question. A template seeded or
		// hand-edited with elements that carry no `type` and no `text` — e.g.
		// `{"id":"el1","x":50,"y":50,"font":"Georgia","size":16}` — produced a
		// canvas of pure CSS: 839 characters of stylesheet and not one word of
		// content. Downstream that became a 1117-byte, visually blank PDF that
		// still returned HTTP 200 (Basecamp 10146577178).
		//
		// Signalling with '' lets render_html() fall back to build_default_html(),
		// which always carries recipient, course, date and UUID. A learner handing
		// a credential to an employer must never get a blank page; the default
		// layout is a worse-looking certificate but a real one.
		if ( '' === trim( $element_html ) ) {
			return '';
		}

		// Customer-configured footer overlays the bottom of the canvas so
		// designer-driven layouts (which position elements absolutely) still
		// pick up the org-wide footer text. Empty string → no footer block
		// rendered at all. Wave 2E row 2E-5.
		$footer_html  = $this->get_footer_html();
		$footer_block = '' !== $footer_html
			? '<div class="cert-footer">' . $footer_html . '</div>'
			: '';

		$html = '<!DOCTYPE html>'
			. '<html><head><meta charset="utf-8">'
			. '<style>'
			. '*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }'
			. '@page { size: ' . esc_attr( $width ) . ' ' . esc_attr( $height ) . '; margin: 0; }'
			. "body { margin: 0; padding: 0; width: {$width}; height: {$height}; }"
			. '.cert-canvas { '
				. "width: {$width}; height: {$height}; "
				. 'position: relative; '
				. "background: {$bg_color}; "
				. "{$bg_image_css} "
				. 'overflow: hidden; '
			. "}\n"
			. '.cert-footer { '
				. 'position: absolute; left: 0; right: 0; bottom: 8mm; '
				. 'text-align: center; font-size: 9pt; color: #555555; '
				. 'font-family: Georgia, "Times New Roman", serif; '
				. 'padding: 0 10mm; line-height: 1.4; '
			. "}\n"
			. $element_styles
			. '</style></head><body>'
			. '<div class="cert-canvas">'
			. $element_html
			. $footer_block
			. '</div></body></html>';

		return $html;
	}

	/**
	 * Element types the designer gives an explicit width AND height to.
	 *
	 * This list is the schema's disambiguator, and it must not drift from
	 * certificate-designer.js:679 (`hasWidth`). `size` is overloaded in
	 * design_json: for these three it is a DIMENSION (percent of canvas); for
	 * everything else it is a FONT SIZE. Reading it as the wrong one is how a QR
	 * ends up sized like a paragraph, or a signature sized like a QR.
	 */
	private const SIZED_TYPES = array( 'image', 'qr_code', 'line' );

	/**
	 * Resolve one element's real box, in millimetres, on the canvas.
	 *
	 * Returns the geometry the CSS pass needs: the box's own width/height (so it
	 * can be centred on its coordinate), the font size actually used, and the
	 * horizontal span (so the wrap guard can tell what is genuinely underneath
	 * a block of text and what merely shares a y range on the far side of the
	 * page).
	 *
	 * @param array $el         Element definition from design_json.
	 * @param float $canvas_w   Canvas width in mm.
	 * @param float $canvas_h   Canvas height in mm.
	 * @param array $merge_data Merge-tag values (text length drives wrap height).
	 * @return array{w:float,h:float,top:float,left:float,right:float,sized:bool,align:string,font_pt:float,text?:string,bold?:bool}
	 */
	/**
	 * Whether an element renders a human being's name.
	 *
	 * Only the merge tags that resolve to a person. A `signature` element is not
	 * one: its label is a role ("Instructor", "Director"), and it has its own
	 * narrower box.
	 *
	 * @param array<string, mixed> $el Element definition.
	 */
	private function is_person_name( array $el ): bool {
		if ( 'merge_tag' !== ( $el['type'] ?? 'text' ) ) {
			return false;
		}

		return in_array( (string) ( $el['tag'] ?? '' ), array( '{student_name}', '{instructor_name}' ), true );
	}

	/**
	 * A font family plus the generic that stands in for it when the renderer has
	 * no such face.
	 *
	 * Dompdf ships the 14 PDF core fonts and nothing else unless fonts are
	 * registered, so every family the designer offers except Times New Roman and
	 * Helvetica has to be substituted. What matters is that it is substituted
	 * with the right KIND of face.
	 *
	 * This used to emit `'<font>', Georgia, serif` for every element regardless
	 * of the choice. Dompdf knows neither the chosen family nor Georgia, so it
	 * fell through to `serif` and printed Times - which meant Arial, Inter,
	 * Verdana and Trebuchet MS, all sans-serifs, came out as a serif, and
	 * Courier New, a monospace, came out proportional. Six of the eight fonts in
	 * the picker did not survive to the PDF (`pdffonts` on a render of each one
	 * shows Times-Roman for all six).
	 *
	 * Arial -> Helvetica is not a compromise; that pair is metrically compatible
	 * by design.
	 *
	 * Courier New is deliberately ABSENT, and is no longer offered in the
	 * designer. Mapping it to `monospace` does work - Dompdf then embeds Courier
	 * - but Courier is far wider than Times, so a title that fits on two lines
	 * today wraps to three and prints straight through the line beneath it. That
	 * was reproduced before this change went in. The wrap guard cannot catch it
	 * because GLYPH_RATIO is a single average tuned for proportional faces. Left
	 * unmapped, a template that already stores 'Courier New' keeps rendering
	 * exactly as it does today rather than silently reflowing into an overlap.
	 * Re-enabling it needs per-family width ratios first, not another entry here.
	 *
	 * @param string $font Family chosen in the designer.
	 */
	private static function font_stack( string $font ): string {
		$generic = array(
			'georgia'         => 'serif',
			'times new roman' => 'serif',
			'inter'           => 'sans-serif',
			'helvetica'       => 'sans-serif',
			'arial'           => 'sans-serif',
			'verdana'         => 'sans-serif',
			'trebuchet ms'    => 'sans-serif',
		);

		$fallback = $generic[ strtolower( trim( $font ) ) ] ?? 'serif';

		return sprintf( "'%s', %s", $font, $fallback );
	}

	private function element_box( array $el, float $canvas_w, float $canvas_h, array $merge_data ): array {
		$type = $el['type'] ?? 'text';
		$x    = floatval( $el['x'] ?? 50 );
		$y    = floatval( $el['y'] ?? 50 );

		$x_mm = $x / 100 * $canvas_w;
		$top  = $y / 100 * $canvas_h;

		$align_raw = $el['align'] ?? 'center';
		$align     = in_array( $align_raw, array( 'left', 'center', 'right' ), true ) ? $align_raw : 'center';

		$sized = in_array( $type, self::SIZED_TYPES, true );

		if ( $sized ) {
			// Mirrors certificate-designer.js:683-684 — width/height fall back to
			// `size`, then to 10. For a qr_code, `size` IS the dimension.
			$w_pct = floatval( $el['width'] ?? $el['size'] ?? 10 );
			$h_pct = floatval( $el['height'] ?? $el['size'] ?? 10 );

			$w = $w_pct / 100 * $canvas_w;
			$h = $h_pct / 100 * $canvas_h;

			// A `line` is anchored TOP-LEFT, not centred — unlike image/qr_code.
			//
			// This is not a preference, it is what the stored data means. The frame
			// rules read `x:3, y:3, width:94` (start at 3%, run 94% across, end at
			// 97%) and `x:3, y:3, width:0.3, height:93` (a left border down the
			// page). Centre those and they'd span -44%..+50% — off the canvas. The
			// designer nonetheless ran them through translate(-50%, -50%), which is
			// why the "bordered" elegant designs have never actually drawn a border,
			// in the PDF *or* on the designer canvas. certificate-designer.js is
			// fixed to match this.
			//
			// Anchoring top-left (rather than rewriting the coordinates to be
			// centre-relative) is the fix that also repairs the rows 1.6.0 ALREADY
			// seeded into live databases — a seed-data change would only help sites
			// installing fresh.
			if ( 'line' === $type ) {
				return array(
					'w'         => $w,
					'h'         => $h,
					'top'       => $top,
					'left'      => $x_mm,
					'right'     => $x_mm + $w,
					'sized'     => true,
					'fixed_box' => false,
					'align'     => $align,
					'font_pt'   => 0.0,
				);
			}

			// image / qr_code — centred on both axes, so the box straddles its
			// coordinate, exactly as the designer draws it.
			return array(
				'w'         => $w,
				'h'         => $h,
				'top'       => $top - ( $h / 2 ),
				'left'      => $x_mm - ( $w / 2 ),
				'right'     => $x_mm + ( $w / 2 ),
				'sized'     => true,
				'fixed_box' => false,
				'align'     => $align,
				'font_pt'   => floatval( $el['size'] ?? 16 ),
			);
		}

		// Text-ish: `size` is the font size, and the designer applies no vertical
		// transform — so `top` is the top edge, not the middle.
		//
		// Only CENTRE-aligned text gets a fixed box. Left/right-aligned text is
		// shrink-to-fit in the designer (no declared width), so it never wraps —
		// which is exactly why course-modern's long left-aligned title stays on one
		// line while course-elegant's centred one folds into the date. Forcing a
		// width on left/right text here would make it start wrapping, i.e. it would
		// introduce the very bug we came to fix.
		$font_pt = floatval( $el['size'] ?? 16 );
		$bold    = ! empty( $el['bold'] );
		$text    = $this->element_text( $el, $merge_data );

		// Estimated INK width — where the glyphs actually sit. Used ONLY to describe
		// the element's visual extent for the overlap/wrap guard, NEVER to position
		// it. Positioning is exact per alignment below, so the estimate being a
		// little off can nudge a collision decision but can never move the text.
		$ratio = $bold ? self::GLYPH_RATIO_BOLD : self::GLYPH_RATIO;
		$len   = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
		$est_w = max( 1.0, $len * $font_pt * self::PT_TO_MM * $ratio );

		// Anchor each alignment on the coordinate exactly the way the designer does
		// (certificate-designer.js applyPositionToNode: translateX 0 / -50% / -100%
		// for left / center / right) but WITHOUT a transform (Dompdf has none) and
		// WITHOUT guessing the rendered width to position with:
		//
		// center — a box symmetric about the coordinate, CLAMPED so it can never
		// reach past a canvas edge; text-align:center lands the text on
		// the coordinate. Away from an edge the box keeps its intended
		// width so existing layouts don't move; near an edge it shrinks
		// rather than printing off-canvas (the clip bug).
		// right  — a box from the canvas LEFT edge to the coordinate; text-align
		// :right pins the text's right edge on the coordinate exactly,
		// with no glyph estimate (that estimate is what put right-aligned
		// text in a different place than the designer preview).
		// left   — shrink-to-fit one line from the coordinate, as the designer
		// draws it (a long left title stays on one line).
		if ( 'center' === $align ) {
			$intended = 'signature' === $type ? self::SIGNATURE_BOX_MM : self::TEXT_BOX_MM;

			// A person's name is never broken across lines. Everything else on a
			// certificate is a sentence or a title, where a wrap reads as normal
			// typesetting; a name is a proper noun, and "Renata / Kowalski" reads
			// as a mistake on the one line the document exists to state
			// (Basecamp 10202683896).
			//
			// The box grows to fit rather than the font shrinking, so the design
			// is untouched for every name that already fitted. Widening is safe
			// because centred text is anchored on the coordinate by
			// text-align:center, not by the box edge - the name does not move, it
			// just stops folding. The existing canvas clamp below still applies,
			// so a long name cannot push the box off the page.
			$is_name = $this->is_person_name( $el );
			if ( $is_name ) {
				$intended = max( $intended, $est_w * self::NAME_FIT_SLACK );
			}

			$box_w = max( 1.0, min( $intended, 2.0 * min( $x_mm, $canvas_w - $x_mm ) ) );

			// Only a name too long for the canvas itself reaches this: shrink it
			// to fit rather than let it wrap, floored so it stays readable.
			if ( $is_name && $est_w > $box_w ) {
				$font_pt = max(
					$font_pt * self::NAME_MIN_SCALE,
					$font_pt * ( $box_w / ( $est_w * self::NAME_FIT_SLACK ) )
				);
				$est_w   = max( 1.0, $len * $font_pt * self::PT_TO_MM * $ratio );
			}

			$left     = $x_mm - ( $box_w / 2 );
			$fixed    = true;
			$ink_half = min( $est_w, $box_w ) / 2;
			$ink_l    = $x_mm - $ink_half;
			$ink_r    = $x_mm + $ink_half;
		} elseif ( 'right' === $align ) {
			$box_w = max( 1.0, $x_mm );
			$left  = 0.0;
			$fixed = true;
			$ink_l = $x_mm - min( $est_w, $box_w );
			$ink_r = $x_mm;
		} else {
			$box_w = $est_w;
			$left  = $x_mm;
			$fixed = false;
			$ink_l = $x_mm;
			$ink_r = $x_mm + $est_w;
		}

		$h = $fixed
			? $this->estimate_text_height( $text, $font_pt, $box_w, $bold )
			: $font_pt * self::LINE_HEIGHT * self::PT_TO_MM;

		return array(
			'w'         => $box_w,
			'h'         => $h,
			'top'       => $top,
			'left'      => $left,
			'right'     => $left + $box_w,
			'ink_left'  => $ink_l,
			'ink_right' => $ink_r,
			'sized'     => false,
			'fixed_box' => $fixed,
			'align'     => $align,
			'font_pt'   => $font_pt,
			// Carried so the wrap guard can re-estimate at a smaller font without
			// having to re-derive the string.
			'text'      => $text,
			'bold'      => $bold,
		);
	}

	/**
	 * The literal string an element will render, for width estimation.
	 */
	private function element_text( array $el, array $merge_data ): string {
		switch ( $el['type'] ?? 'text' ) {
			case 'merge_tag':
				$tag = $el['tag'] ?? '';
				return (string) ( $merge_data[ $tag ] ?? $tag );
			case 'signature':
				return (string) ( $el['label'] ?? __( 'Signature', 'learnomy' ) );
			case 'text':
				return (string) ( $el['text'] ?? '' );
			default:
				return '';
		}
	}

	/**
	 * Estimate how tall a run of text will be once it wraps inside its box.
	 *
	 * This simulates WORD wrapping, not character wrapping. Dividing the character
	 * count by characters-per-line is the obvious shortcut and it is wrong: text
	 * breaks between words, so one long word forces an early break and costs a
	 * whole line. "React Fundamentals: Build Your First 3 Apps" is 43 characters
	 * and 18 fit per line at 21pt — the naive maths says 3 lines, the browser says
	 * 4, because "Fundamentals:" cannot share a line with "React". That one missing
	 * line is the difference between a title that clears the completion date and a
	 * title printed straight through it.
	 */
	private function estimate_text_height( string $text, float $font_pt, float $box_w_mm, bool $bold ): float {
		$text = trim( $text );
		if ( '' === $text || $font_pt <= 0 ) {
			return 0.0;
		}

		$ratio    = $bold ? self::GLYPH_RATIO_BOLD : self::GLYPH_RATIO;
		$glyph_mm = $font_pt * self::PT_TO_MM * $ratio;
		if ( $glyph_mm <= 0 ) {
			return 0.0;
		}

		$per_line = max( 1, (int) floor( $box_w_mm / $glyph_mm ) );
		$words    = preg_split( '/\s+/', $text ) ?: array( $text );

		$lines = 1;
		$used  = 0;
		foreach ( $words as $word ) {
			$w = function_exists( 'mb_strlen' ) ? mb_strlen( $word ) : strlen( $word );

			// A word longer than the box breaks mid-word — it gets its own line(s).
			if ( $w > $per_line ) {
				if ( $used > 0 ) {
					++$lines;
				}
				$lines += (int) ceil( $w / $per_line ) - 1;
				$used   = $w % $per_line;
				continue;
			}

			$need = $used > 0 ? $used + 1 + $w : $w;
			if ( $need > $per_line ) {
				++$lines;
				$used = $w;
			} else {
				$used = $need;
			}
		}

		return $lines * $font_pt * self::LINE_HEIGHT * self::PT_TO_MM;
	}

	/**
	 * Shrink a text element's font until it stops running into whatever is below it.
	 *
	 * Centre-aligned text gets a fixed-width box and NO height budget, so a long
	 * course title simply grows downward — into the completion date on
	 * course-elegant, and straight through the "with a score of" line on
	 * quiz-elegant. Nothing downstream knows the box got taller, because in an
	 * absolutely-positioned layout nothing reflows.
	 *
	 * Rather than invent a flow model (which design_json has no concept of), we
	 * step the offending element's font size down until its projected box clears
	 * the nearest element beneath it. It is an estimate, and it is allowed to be:
	 * the failure mode of over-shrinking is a slightly small title, versus the
	 * failure mode today, which is two strings printed on top of each other.
	 *
	 * @param array $boxes    Geometry from element_box(), indexed as $elements.
	 * @param float $canvas_h Canvas height in mm.
	 * @return array Boxes with font_pt/h adjusted where a collision was projected.
	 */
	private function apply_wrap_guard( array $boxes, float $canvas_h ): array {
		foreach ( $boxes as $i => $box ) {
			// Only centre-aligned text can wrap (it is the only kind with a declared
			// width). Shrink-to-fit text runs off to the side instead, which is a
			// different bug and not this one.
			if ( empty( $box['fixed_box'] ) || $box['h'] <= 0 ) {
				continue;
			}

			// The nearest thing below us that we actually overlap horizontally.
			// Compare INK extents (where the glyphs sit), not the layout box — a
			// right-aligned box runs to the canvas edge and a centred box is wider
			// than its text, so the raw box would report overlaps the eye doesn't
			// see. Sized elements (image/qr/line) have no ink extent; their box IS
			// their visual extent, so fall back to it.
			$box_l   = $box['ink_left'] ?? $box['left'];
			$box_r   = $box['ink_right'] ?? $box['right'];
			$ceiling = $canvas_h;
			foreach ( $boxes as $j => $other ) {
				if ( $i === $j || $other['top'] <= $box['top'] ) {
					continue;
				}
				$other_l    = $other['ink_left'] ?? $other['left'];
				$other_r    = $other['ink_right'] ?? $other['right'];
				$overlaps_x = $other_l < $box_r && $other_r > $box_l;
				if ( $overlaps_x && $other['top'] < $ceiling ) {
					$ceiling = $other['top'];
				}
			}

			$available = $ceiling - $box['top'] - self::COLLISION_GAP_MM;
			if ( $available <= 0 || $box['h'] <= $available ) {
				continue;
			}

			// Step down until it fits, or until we hit the floor and accept a clip.
			$font = $box['font_pt'];
			$h    = $box['h'];
			while ( $h > $available && $font > self::MIN_FONT_PT ) {
				--$font;
				$h = $this->estimate_text_height(
					$box['text'] ?? '',
					$font,
					$box['w'],
					(bool) ( $box['bold'] ?? false )
				);
				if ( $h <= 0 ) {
					break;
				}
			}

			$boxes[ $i ]['font_pt'] = $font;
			$boxes[ $i ]['h']       = $h;
		}

		return $boxes;
	}

	/**
	 * Render a single design element to HTML.
	 *
	 * @param array  $el         Element definition from design_json.
	 * @param string $id         Sanitized element ID.
	 * @param string $color      Sanitized hex color.
	 * @param array  $merge_data Merge-tag values.
	 * @param array  $box        Resolved geometry from element_box()/apply_wrap_guard().
	 * @return string HTML fragment.
	 */
	private function render_element( array $el, string $id, string $color, array $merge_data, array $box ): string {
		$type = $el['type'] ?? 'text';

		switch ( $type ) {
			case 'text':
				// An empty text element is not content. Returning the wrapper
				// anyway made `$element_html` non-empty, which is what let a design
				// of contentless elements pass for a rendered certificate
				// (Basecamp 10146577178). It also left a positioned empty div that
				// could still push the wrap guard around.
				$text = esc_html( $el['text'] ?? '' );
				if ( '' === trim( $text ) ) {
					return '';
				}
				return "<div class=\"el-{$id}\">{$text}</div>\n";

			case 'merge_tag':
				$tag = $el['tag'] ?? '';
				if ( '' === trim( (string) $tag ) ) {
					return '';
				}
				// An unresolved tag deliberately prints the tag itself rather than
				// nothing: a visible `{student_name}` on the certificate tells the
				// owner their template references a tag we do not supply, where a
				// silent blank would read as our bug.
				$value = esc_html( $merge_data[ $tag ] ?? $tag );
				return "<div class=\"el-{$id}\">{$value}</div>\n";

			case 'image':
				$src = esc_url( $el['src'] ?? '' );
				return sprintf(
					"<img class=\"el-%s\" src=\"%s\" style=\"width: %.3fmm; height: %.3fmm;\">\n",
					$id,
					$src,
					$box['w'],
					$box['h']
				);

			case 'qr_code':
				$qr_url = $merge_data['{verify_url}'] ?? '';
				$qr_src = $this->render_qr( $qr_url );
				if ( '' === $qr_src ) {
					return '';
				}
				return sprintf(
					"<img class=\"el-%s\" src=\"%s\" style=\"width: %.3fmm; height: %.3fmm;\">\n",
					$id,
					esc_attr( $qr_src ),
					$box['w'],
					$box['h']
				);

			case 'line':
				// The designer draws a rule as a box whose border-top thickness IS its
				// height (certificate-designer.js:601-604) — which is how the same
				// element type serves both the horizontal rules and the VERTICAL sides
				// of the elegant frame (a 0.3%-wide box with a 93%-tall border reads as
				// a vertical bar). The old <hr> ignored `height` and `lineStyle`
				// entirely, so those vertical sides came out as ~0.9mm horizontal ticks
				// and the bordered frame the design is named for never appeared.
				$style = $el['lineStyle'] ?? 'solid';
				$style = in_array( $style, array( 'solid', 'dashed', 'dotted', 'double' ), true ) ? $style : 'solid';
				$thick = max( 0.26, $box['h'] ); // ~1px floor, so a hairline still prints.
				return sprintf(
					"<div class=\"el-%s\" style=\"width: %.3fmm; height: 0; border-top: %.3fmm %s %s;\"></div>\n",
					$id,
					$box['w'],
					$thick,
					$style,
					$color
				);

			case 'signature':
				// Rule width + centring mirror certificate-designer.js:617-620
				// (120px, margin: 0 auto). The old 200px rule with no auto-margin sat
				// flush left inside the centring box, so it never lined up with the
				// label above it.
				$label = esc_html( $el['label'] ?? __( 'Signature', 'learnomy' ) );
				return "<div class=\"el-{$id}\">"
					. '<div style="border-top: 1px solid ' . $color . '; width: 31.75mm; margin: 0 auto 1.06mm;"></div>'
					. "<small>{$label}</small>"
					. "</div>\n";

			default:
				return '';
		}
	}

	/**
	 * Public wrapper around render_qr() for callers outside the renderer
	 * (e.g. the verify-page view-data resolver) that need the same locally
	 * generated QR data URI.
	 *
	 * @param string $data The string to encode (typically the verify URL).
	 * @return string A `data:image/svg+xml;base64,...` URI, or '' on failure.
	 */
	public function qr_data_uri( string $data ): string {
		return $this->render_qr( $data );
	}

	/**
	 * Build a QR code for the given data and return it as a self-contained
	 * `data:image/svg+xml;base64,...` URI usable in any `<img src>`.
	 *
	 * The QR matrix is generated locally with the bundled qrcode-generator
	 * library (MIT, see assets/vendor/qrcode/README.md) — no external HTTP
	 * call. We emit an inline SVG and base64-encode it into a data URI because
	 * dompdf renders data-URI raster/vector images reliably, whereas its
	 * inline `<svg>` support is patchier. The same URI also renders natively
	 * in the on-screen browser preview.
	 *
	 * Returns an empty string when there is nothing to encode or the library
	 * is unavailable — callers skip the element entirely in that case.
	 *
	 * @param string $data The string to encode (typically the verify URL).
	 * @return string A `data:image/svg+xml;base64,...` URI, or '' on failure.
	 */
	private function render_qr( string $data ): string {
		$data = trim( $data );
		if ( '' === $data ) {
			return '';
		}

		$lib = LEARNOMY_DIR . 'includes/vendor/qrcode/qrcode.php';
		if ( ! class_exists( '\QRCode' ) ) {
			if ( ! file_exists( $lib ) ) {
				return '';
			}
			require_once $lib;
		}

		// Error-correction level M balances density and scan reliability.
		$qr = \QRCode::getMinimumQRCode( $data, \QR_ERROR_CORRECT_LEVEL_M );

		$count  = $qr->getModuleCount();
		$margin = 1; // Quiet zone, in modules — recommended by the QR spec.
		$dim    = $count + ( $margin * 2 );

		// Build one filled <rect> per dark module on a white background. The
		// viewBox keeps the SVG resolution-independent so the existing
		// percentage-based width/height sizing on the <img> still drives the
		// rendered size.
		$rects = '';
		for ( $row = 0; $row < $count; $row++ ) {
			for ( $col = 0; $col < $count; $col++ ) {
				if ( $qr->isDark( $row, $col ) ) {
					$x      = $col + $margin;
					$y      = $row + $margin;
					$rects .= '<rect x="' . $x . '" y="' . $y . '" width="1" height="1"/>';
				}
			}
		}

		$svg = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $dim . ' ' . $dim . '" '
			. 'shape-rendering="crispEdges">'
			. '<rect width="' . $dim . '" height="' . $dim . '" fill="#ffffff"/>'
			. '<g fill="#000000">' . $rects . '</g>'
			. '</svg>';

		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	/**
	 * Build a clean default certificate HTML when no design_json is available.
	 *
	 * Uses the same visual style as the existing download_certificate endpoint
	 * but in a Dompdf-friendly layout (no flexbox, absolute positioning only).
	 *
	 * @param array $merge_data Merge-tag values.
	 * @return string Full HTML document.
	 */
	private function build_default_html( array $merge_data ): string {
		$student_name    = esc_html( $merge_data['{student_name}'] );
		$course_title    = esc_html( $merge_data['{course_title}'] );
		$completion_date = esc_html( $merge_data['{completion_date}'] );
		$certificate_id  = esc_html( strtoupper( substr( $merge_data['{certificate_id}'], 0, 8 ) ) );
		$verify_url      = esc_url( $merge_data['{verify_url}'] );
		$site_name       = esc_html( $merge_data['{site_name}'] );
		$qr_src          = $this->render_qr( $merge_data['{verify_url}'] ?? '' );

		// Customer-configured footer (Settings > Content > Footer Text).
		// Empty string → no footer block at all (no stray padding). Wave 2E
		// row 2E-5 — see get_footer_html() docblock for sanitization rationale.
		$footer_html  = $this->get_footer_html();
		$footer_block = '' !== $footer_html
			? '<div class="cert-footer">' . $footer_html . '</div>'
			: '';

		$qr_block = '' !== $qr_src
			? '<div class="cert-qr"><img src="' . esc_attr( $qr_src ) . '" width="100" height="100" alt=""></div>'
			: '';

		return '<!DOCTYPE html>
<html><head><meta charset="utf-8">
<style>
*, *::before, *::after { margin: 0; padding: 0; }
@page { size: 297mm 210mm; margin: 0; }
html, body { width: 297mm; height: 210mm; margin: 0; padding: 0; }
body { font-family: Georgia, "Times New Roman", serif; color: #37352f; }
/*
 * Dompdf 2.0.8 ships NO box-sizing support (always content-box), so any box
 * with an explicit width PLUS padding/border overflows by the padding+border
 * amount. The old layout set .cert to width:297mm AND padding:40px 60px AND a
 * 3px border, pushing the box ~33mm past the 297mm page — the right edge (and
 * a spill page) was cut off (Basecamp #9972911078). Fix: the page-filler is
 * exactly 297x210mm with no padding/border so it can never overflow, and the
 * decorative frames are absolutely-positioned inset boxes whose width is
 * computed from left+right (auto width, so their padding stays INSIDE the box).
 */
.cert {
	position: relative;
	width: 297mm; height: 210mm;
	background: #ffffff;
	overflow: hidden;
}
.cert-frame {
	position: absolute;
	top: 7mm; left: 7mm; right: 7mm; bottom: 7mm;
	border: 3px solid #6c5ce7;
}
.cert-inner {
	position: absolute;
	top: 13mm; left: 13mm; right: 13mm; bottom: 13mm;
	border: 1px solid #d9d3f7;
	text-align: center;
	padding: 16mm 14mm;
}
.cert-seal {
	width: 60px; height: 60px;
	border-radius: 50%;
	border: 4px solid #6c5ce7;
	margin: 0 auto 14px;
}
.cert-heading {
	font-size: 20pt; font-weight: 400; letter-spacing: 3px;
	text-transform: uppercase; color: #6c5ce7; margin-bottom: 20px;
}
.cert-label { font-size: 11pt; color: #9b9a97; margin-bottom: 4px; }
.cert-name { font-size: 28pt; font-weight: 700; margin-bottom: 20px; line-height: 1.2; }
.cert-course { font-size: 16pt; font-style: italic; margin-bottom: 24px; }
.cert-meta { margin-bottom: 20px; font-size: 11pt; color: #6b6b6b; }
.cert-meta-item { display: inline-block; margin: 0 24px; }
.cert-meta-label { font-weight: 600; color: #37352f; }
.cert-qr { margin-bottom: 8px; }
.cert-verify { font-size: 9pt; color: #9b9a97; }
.cert-org { margin-top: 16px; font-size: 12pt; font-weight: 600; color: #6c5ce7; }
.cert-footer { margin-top: 14px; font-size: 9pt; color: #6b6b6b; line-height: 1.4; }
</style>
</head><body>
<div class="cert">
<div class="cert-frame"></div>
<div class="cert-inner">
	<div class="cert-seal"></div>
	<div class="cert-heading">' . esc_html__( 'Certificate of Completion', 'learnomy' ) . '</div>
	<div class="cert-label">' . esc_html__( 'This certifies that', 'learnomy' ) . '</div>
	<div class="cert-name">' . $student_name . '</div>
	<div class="cert-label">' . esc_html__( 'has successfully completed', 'learnomy' ) . '</div>
	<div class="cert-course">' . $course_title . '</div>
	<div class="cert-meta">
		<span class="cert-meta-item">
			<span class="cert-meta-label">' . esc_html__( 'Issued', 'learnomy' ) . '</span><br>' . $completion_date . '
		</span>
		<span class="cert-meta-item">
			<span class="cert-meta-label">' . esc_html__( 'Credential ID', 'learnomy' ) . '</span><br>' . $certificate_id . '
		</span>
	</div>
	' . $qr_block . '
	<div class="cert-verify">' . esc_html__( 'Verify at:', 'learnomy' ) . ' ' . $verify_url . '</div>
	<div class="cert-org">' . $site_name . '</div>
	' . $footer_block . '
</div>
</div>
</body></html>';
	}
}
