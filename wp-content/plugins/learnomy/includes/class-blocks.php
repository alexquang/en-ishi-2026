<?php
/**
 * Block and widget registration.
 *
 * @package Learnomy
 */

namespace Learnomy;

defined( 'ABSPATH' ) || exit;

class Blocks {

	/** The 12 server-rendered block slugs, registered from blocks/<slug>/. */
	private const BLOCK_SLUGS = [
		'course-card',
		'course-catalog',
		'lesson-content',
		'quiz',
		'progress-bar',
		'student-dashboard',
		'certificate',
		'enrollment-cta',
		'course-grid',
		'membership-pricing',
		'my-progress',
		'instructor-card',
	];

	/**
	 * Block slug => the asset surface it renders.
	 *
	 * `style_handles` (see register()) declares each block's STYLESHEETS to
	 * WordPress, which is the right mechanism for CSS and also feeds the editor.
	 * It cannot express the rest of a surface: the Interactivity runtime a block's
	 * directives bind to, the icon replacer its `<i data-lucide>` placeholders
	 * need, the shared toast/confirm globals its store calls. Those come from the
	 * one dispatcher every Learnomy surface uses, keyed here.
	 *
	 * A ROUTE name means the block renders that route's surface and takes its
	 * whole bundle; a light surface key means it renders a fragment.
	 *
	 * @var array<string, string>
	 */
	private const BLOCK_SURFACES = [
		// Card grids — templates/partials/course-card.php, icons + wishlist store.
		'course-card'        => 'course-card',
		'course-grid'        => 'course-card',
		'course-catalog'     => 'course-card',
		// The pricing table, its billing-period toggle and coupon field: the
		// same surface /membership/ renders, so the same bundle.
		'membership-pricing' => 'membership-pricing',
		// Self-contained fragments.
		'certificate'        => 'embed',
		'enrollment-cta'     => 'embed',
		'instructor-card'    => 'embed',
		'lesson-content'     => 'embed',
		'my-progress'        => 'embed',
		'progress-bar'       => 'embed',
		'quiz'               => 'embed',
		'student-dashboard'  => 'embed',
	];

	public function __construct() {
		add_action( 'init', [ $this, 'register' ] );
		add_action( 'widgets_init', [ $this, 'register_widgets' ] );
		// Every block.json declares `"category": "learnomy"`, but nothing ever
		// registered that slug — and WordPress silently drops an unknown
		// category, filing the block under Uncategorized rather than warning.
		// So all twelve blocks sat in a bucket with everyone else's strays,
		// unfindable by browsing (Basecamp 10190122664).
		add_filter( 'block_categories_all', [ $this, 'register_block_category' ] );
		// Block front-end styles load via per-block `style_handles` registered in
		// register() (WP enqueues them on render, on any route) — no has_block
		// scan needed. Everything a block needs BEYOND its stylesheets comes from
		// the shared dispatcher, requested as the block is about to render.
		add_filter( 'render_block_data', [ $this, 'enqueue_block_surface' ] );
		// Editor: register the shared block UI (ServerSideRender preview +
		// per-block InspectorControls) so blocks are insertable and configurable
		// instead of showing "your site doesn't include support".
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_editor_assets' ] );
	}

	/**
	 * Request a block's asset surface as WordPress is about to render it.
	 *
	 * Runs for every block on the page, so it exits on the first character of a
	 * non-Learnomy block name. Returns the parsed block untouched — this is a
	 * side-effect hook, not a transform.
	 *
	 * @param array $parsed_block The block about to render.
	 * @return array The same block.
	 */
	public function enqueue_block_surface( $parsed_block ) {
		$name = is_array( $parsed_block ) ? ( $parsed_block['blockName'] ?? '' ) : '';

		if ( ! is_string( $name ) || ! str_starts_with( $name, 'learnomy/' ) ) {
			return $parsed_block;
		}

		$slug = substr( $name, strlen( 'learnomy/' ) );
		if ( isset( self::BLOCK_SURFACES[ $slug ] ) ) {
			Template_Loader::enqueue_for_surface( self::BLOCK_SURFACES[ $slug ] );
		}

		return $parsed_block;
	}

	/**
	 * Editor: register every Learnomy block on the JS side with a live
	 * ServerSideRender preview and auto-generated InspectorControls derived from
	 * each block's `attributes` (from block.json). Without a client-side
	 * registration the editor cannot render these server-rendered blocks and
	 * shows "your site doesn't include support for this block".
	 */
	public function enqueue_editor_assets(): void {
		wp_enqueue_script(
			'learnomy-blocks-editor',
			LEARNOMY_URL . 'assets/js/blocks/editor.js',
			[ 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-server-side-render', 'wp-i18n', 'wp-api-fetch' ],
			LEARNOMY_VERSION,
			true
		);

		// Hand the editor each block's name/title/category/attributes (read from
		// the server-side registration) so one shared script can register them
		// all with a ServerSideRender preview + auto-built InspectorControls.
		$registry = \WP_Block_Type_Registry::get_instance();
		$defs     = [];
		foreach ( self::BLOCK_SLUGS as $slug ) {
			$type = $registry->get_registered( 'learnomy/' . $slug );
			if ( ! $type ) {
				continue;
			}
			$defs[] = [
				'name'       => $type->name,
				'title'      => $type->title ? $type->title : $slug,
				'category'   => $type->category ? $type->category : 'learnomy',
				'icon'       => $type->icon ? $type->icon : 'screenoptions',
				'attributes' => $type->attributes ? $type->attributes : new \stdClass(),
			];
		}
		wp_add_inline_script(
			'learnomy-blocks-editor',
			'window.learnomyBlockDefs = ' . wp_json_encode( $defs ) . ';',
			'before'
		);

		// The editor canvas renders block previews via ServerSideRender, which
		// returns the same markup the front end uses — load the master
		// stylesheet so previews are styled inside the editor too.
		wp_enqueue_style(
			'learnomy',
			LEARNOMY_URL . 'assets/css/learnomy.css',
			[],
			LEARNOMY_VERSION
		);
	}

	/**
	 * Build the allowed-HTML set used to sanitise block output.
	 *
	 * Starts from the standard post context (`wp_kses_post()` allowlist) and adds
	 * the attributes our blocks legitimately emit that the post context does not
	 * cover: the WordPress Interactivity API directive family (`data-wp-*`) and a
	 * small set of block data hooks (`data-quiz-id`, `data-plan-slug`,
	 * `data-status`). These are added to every allowed tag so a directive on a
	 * `<div>`, `<button>`, `<ul>`, etc. survives sanitisation.
	 *
	 * Subscribers that need extra tags or attributes (Pro, third-party) extend the
	 * set through the `learnomy_block_allowed_html` filter rather than disabling
	 * the sanitiser.
	 *
	 * @param string $name Block slug, passed to the filter for per-block tuning.
	 * @return array<string,array<string,bool>> wp_kses-compatible allowed-HTML map.
	 */
	public static function allowed_html( string $name ): array {
		$allowed = wp_kses_allowed_html( 'post' );

		// Attributes our block markup relies on that the post context omits.
		// Added to every allowed tag so a directive / data hook on any element
		// (div, button, input, ...) survives sanitisation.
		//
		// NOTE on literal vs prefix keys: wp_kses() prefix-matches `data-*` only on
		// some elements (it dropped `data-wp-on--change` on `<input>` in testing
		// while keeping it on `<button>`). To be robust across every element we list
		// the EXACT directive/data keys our 12 blocks emit, not a `data-wp-on`
		// prefix. This also keeps the allowed surface minimal (only what we ship).
		$extra_attrs = array(
			// WordPress Interactivity API directives the blocks actually emit.
			'data-wp-interactive' => true,
			'data-wp-on--click'   => true,
			'data-wp-on--change'  => true,
			// ARIA value attributes for the progress-bar / dashboard meters.
			'aria-valuenow'       => true,
			'aria-valuemin'       => true,
			'aria-valuemax'       => true,
			'aria-pressed'        => true,
			// Block-specific data hooks read by our front-end JS.
			'data-quiz-id'        => true,
			'data-plan-slug'      => true,
			'data-status'         => true,
			'data-facet'          => true,
			'data-facet-param'    => true,
			'data-facet-value'    => true,
			'data-facet-multi'    => true,
		);

		foreach ( $allowed as $tag => $attrs ) {
			if ( is_array( $attrs ) ) {
				$allowed[ $tag ] = array_merge( $attrs, $extra_attrs );
			}
		}

		// Form controls the catalog/facet UI emits, which the post context omits
		// entirely. Without these, wp_kses() would strip the whole filter UI from
		// the course-catalog block. Each carries the shared $extra_attrs too.
		$global_attrs = array(
			'class'            => true,
			'id'               => true,
			'style'            => true,
			'title'            => true,
			'role'             => true,
			'tabindex'         => true,
			'hidden'           => true,
			'aria-label'       => true,
			'aria-labelledby'  => true,
			'aria-describedby' => true,
			'aria-hidden'      => true,
		);
		$form_tags    = array(
			'fieldset' => array(
				'disabled' => true,
				'form'     => true,
				'name'     => true,
			),
			'legend'   => array(),
			'label'    => array(
				'for'  => true,
				'form' => true,
			),
			'input'    => array(
				'type'         => true,
				'name'         => true,
				'value'        => true,
				'checked'      => true,
				'disabled'     => true,
				'readonly'     => true,
				'required'     => true,
				'placeholder'  => true,
				'min'          => true,
				'max'          => true,
				'step'         => true,
				'pattern'      => true,
				'autocomplete' => true,
			),
			'select'   => array(
				'name'     => true,
				'multiple' => true,
				'disabled' => true,
				'required' => true,
			),
			'optgroup' => array(
				'label'    => true,
				'disabled' => true,
			),
			'option'   => array(
				'value'    => true,
				'selected' => true,
				'disabled' => true,
			),
		);
		foreach ( $form_tags as $tag => $tag_attrs ) {
			$allowed[ $tag ] = array_merge(
				$allowed[ $tag ] ?? array(),
				$global_attrs,
				$tag_attrs,
				$extra_attrs
			);
		}

		/**
		 * Filter the allowed-HTML set used to sanitise Learnomy block output.
		 *
		 * Use this to register additional tags or attributes a custom block
		 * subscriber needs (for example an embed wrapper or an icon `<svg>`, or a
		 * new `data-wp-*` Interactivity directive). The map is the same shape
		 * `wp_kses()` expects.
		 *
		 * @param array<string,array<string,bool>> $allowed Allowed-HTML map.
		 * @param string                            $name    Block slug.
		 */
		return apply_filters( 'learnomy_block_allowed_html', $allowed, $name );
	}

	/**
	 * Apply the `learnomy_block_output` filter to a rendered block and echo it.
	 *
	 * Every block's render.php ends by emitting its buffered HTML through the
	 * `learnomy_block_output` extension filter. Centralising that one line here
	 * keeps the 12 blocks DRY and puts the single unavoidable output-escaping
	 * suppression in one reviewed place instead of 20 scattered render files.
	 *
	 * The filtered output is sanitised with `wp_kses()` against
	 * {@see Blocks::allowed_html()} before it is echoed. This is defence in
	 * depth: every block already escapes its own user data at generation, but a
	 * `learnomy_block_output` subscriber that interpolates a raw attribute into
	 * the returned markup would otherwise ship stored XSS across all 12 blocks at
	 * once. Sanitising here neutralises any injected `<script>` / event-handler
	 * attribute while preserving the markup our blocks legitimately emit
	 * (`data-wp-*` Interactivity directives, block data hooks).
	 *
	 * CONTRACT for `learnomy_block_output` subscribers: the `$html` passed in is
	 * already-safe HTML and a callback MUST return equally-safe HTML. Output is
	 * sanitised against the post context plus the Interactivity directive family;
	 * a subscriber that needs additional tags/attributes registers them via the
	 * `learnomy_block_allowed_html` filter (see {@see Blocks::allowed_html()})
	 * instead of returning unsanitised markup.
	 *
	 * @param string $name       Block slug (e.g. 'course-card'), passed to the filter.
	 * @param string $html       Buffered, already-escaped block markup (ob_get_clean()).
	 * @param array  $attributes Block attributes, passed to the filter.
	 */
	public static function render_filtered( string $name, string $html, array $attributes ): void {
		$filtered = apply_filters( 'learnomy_block_output', $html, $attributes, $name );

		echo wp_kses( $filtered, self::allowed_html( $name ) );
	}

	public function register(): void {
		// Register the front-end style handles the blocks depend on, then bind
		// them to each block via `style_handles`. WordPress enqueues a block's
		// style handles whenever it renders, on ANY route, so a block placed on
		// a regular page or post is styled without the Learnomy-route-only
		// Template_Loader enqueue. The master `learnomy` handle is shared with
		// Template_Loader (registering it here is a harmless no-op when that
		// already did). course-catalog and certificate add their own sheets.
		wp_register_style( 'learnomy', LEARNOMY_URL . 'assets/css/learnomy.css', [], LEARNOMY_VERSION );
		wp_register_style( 'learnomy-catalog', LEARNOMY_URL . 'assets/css/catalog.css', [ 'learnomy' ], LEARNOMY_VERSION );
		wp_register_style( 'learnomy-cert-verify', LEARNOMY_URL . 'assets/css/cert-verify.css', [ 'learnomy' ], LEARNOMY_VERSION );

		// slug => extra style handles beyond the shared master.
		$extra_styles = [
			'course-catalog' => [ 'learnomy-catalog' ],
			'certificate'    => [ 'learnomy-cert-verify' ],
		];

		foreach ( self::BLOCK_SLUGS as $block ) {
			$handles = array_merge( [ 'learnomy' ], $extra_styles[ $block ] ?? [] );
			register_block_type(
				LEARNOMY_DIR . 'blocks/' . $block,
				[ 'style_handles' => $handles ]
			);
		}
	}

	/**
	 * Register the `learnomy` block category the blocks already claim.
	 *
	 * Appended rather than prepended: the core categories (Text, Media, Design)
	 * are what an author reaches for most, and a plugin pushing itself to the
	 * top of everyone's inserter is the kind of thing that gets a plugin
	 * uninstalled. The label carries its own filter, matching how every other
	 * product-named surface here is made white-labelable (see
	 * `learnomy_admin_menu_label`), so a rebranded site does not find
	 * "Learnomy" sitting in its inserter.
	 *
	 * @param array<int, array<string, mixed>> $categories Registered categories.
	 * @return array<int, array<string, mixed>>
	 */
	public function register_block_category( array $categories ): array {
		foreach ( $categories as $category ) {
			if ( isset( $category['slug'] ) && 'learnomy' === $category['slug'] ) {
				return $categories;
			}
		}

		$categories[] = [
			'slug'  => 'learnomy',
			/**
			 * Label for the Learnomy block-inserter category.
			 *
			 * @param string $label Category title.
			 */
			'title' => apply_filters( 'learnomy_block_category_label', __( 'Learnomy', 'learnomy' ) ),
			'icon'  => null,
		];

		return $categories;
	}

	public function register_widgets(): void {
		require_once LEARNOMY_DIR . 'includes/widgets/class-popular-courses-widget.php';
		require_once LEARNOMY_DIR . 'includes/widgets/class-recent-courses-widget.php';
		require_once LEARNOMY_DIR . 'includes/widgets/class-course-categories-widget.php';
		require_once LEARNOMY_DIR . 'includes/widgets/class-course-progress-widget.php';
		require_once LEARNOMY_DIR . 'includes/widgets/class-instructor-widget.php';
		require_once LEARNOMY_DIR . 'includes/widgets/class-course-search-widget.php';

		register_widget( \Learnomy\Widgets\Popular_Courses_Widget::class );
		register_widget( \Learnomy\Widgets\Recent_Courses_Widget::class );
		register_widget( \Learnomy\Widgets\Course_Categories_Widget::class );
		register_widget( \Learnomy\Widgets\Course_Progress_Widget::class );
		register_widget( \Learnomy\Widgets\Instructor_Widget::class );
		register_widget( \Learnomy\Widgets\Course_Search_Widget::class );
	}
}
