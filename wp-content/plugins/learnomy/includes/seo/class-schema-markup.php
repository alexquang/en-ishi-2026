<?php
/**
 * JSON-LD structured data output for Learnomy pages.
 *
 * Outputs Schema.org markup in wp_head so search engines can understand
 * course content, instructor profiles, and course catalog pages.
 *
 * @package Learnomy\SEO
 */

namespace Learnomy\SEO;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Course;
use Learnomy\Models\Review;
use Learnomy\Models\Certificate;
use Learnomy\Models\Category;

class Schema_Markup {

	/**
	 * Guards against a second canonical on routes whose schema method already
	 * printed one. Two canonicals is a worse signal than one.
	 *
	 * @var bool
	 */
	private bool $canonical_printed = false;

	/**
	 * Guards against a second <meta name="description">. Search engines take the
	 * first and treat duplicates as a quality signal against the page.
	 *
	 * @var bool
	 */
	private bool $description_printed = false;

	/**
	 * Whether the description about to be printed was typed by the site owner
	 * rather than derived from the entity. Authored text is never truncated —
	 * see print_meta_description().
	 */
	private bool $description_is_authored = false;

	/**
	 * Set once the route is judged hidden from the public (an unlisted or private
	 * course). It gates EVERY emitter below — JSON-LD, Open Graph, meta
	 * description and canonical alike — because they all answer the same question
	 * ("what is this page, for a crawler") and gating only the one that happened
	 * to be reported would leave the leak open through the other three.
	 *
	 * @var bool
	 */
	private bool $hidden_from_public = false;

	public function __construct() {
		add_action( 'wp_head', array( $this, 'output_schema' ) );

		// Learnomy routes now present as singular pages backed by a virtual
		// WP_Post with ID 0 (Router::install_virtual_page()), which puts SEO
		// plugins on their SINGULAR code path — where they derive a canonical
		// from the queried post's permalink. Post 0 has no meaningful
		// permalink, so left alone they would emit a second, wrong canonical
		// beside ours (two canonicals is a worse signal than one). Feed them
		// the same route URL we print, so whichever canonical wins, it is the
		// right one. Filters are cheap no-ops off Learnomy routes.
		add_filter( 'wpseo_canonical', array( $this, 'route_canonical_for_seo_plugins' ) );
		add_filter( 'wpseo_opengraph_url', array( $this, 'route_canonical_for_seo_plugins' ) );
		add_filter( 'rank_math/frontend/canonical', array( $this, 'route_canonical_for_seo_plugins' ) );
	}

	/**
	 * The route's own URL for an SEO plugin's canonical/og:url, replacing
	 * whatever it derived from the virtual post's meaningless permalink.
	 * Off Learnomy routes the incoming value passes through untouched.
	 *
	 * @param mixed $url The canonical the SEO plugin computed.
	 * @return mixed
	 */
	public function route_canonical_for_seo_plugins( $url ) {
		if ( empty( get_query_var( 'learnomy_route' ) ) ) {
			return $url;
		}

		$route_url = $this->current_route_url();

		return '' !== $route_url ? $route_url : $url;
	}

	public function output_schema(): void {
		$route = get_query_var( 'learnomy_route' );
		if ( empty( $route ) ) {
			return;
		}

		// A 404 has nothing to describe. `learnomy_route` survives set_404() (it
		// lives in query_vars, which set_404() does not clear) and wp_head still
		// runs inside the 404 body, so every emitter below used to fire for the
		// entity the route was DENIED. That is how a gated instructor profile
		// answered a 404 in the status line while <head> carried the person's
		// name, avatar and og:profile tags — the leak the body fix closed,
		// re-opened one hook later. One guard, all four emitters (JSON-LD, Open
		// Graph, meta description, canonical), because they all answer the same
		// question: "what is this page, for a crawler".
		if ( is_404() ) {
			return;
		}

		// A category filter turns the catalog into the category archive under a
		// different URL. Both describe one listing, so both get one identity —
		// otherwise /courses/?cat=design published the whole catalog's title,
		// description, canonical and ItemList over a twelve-course design grid,
		// and told crawlers the two URLs were different pages with the same
		// content (Basecamp 10134139480).
		if ( 'catalog' === $route && null !== $this->listed_category() ) {
			$route = 'category';
		}

		match ( $route ) {
			'course'              => $this->course_schema(),
			'catalog'             => $this->course_list_schema(),
			'category'            => $this->category_schema(),
			'instructor-profile'  => $this->person_schema(),
			'certificate-verify'  => $this->certificate_schema(),
			default               => $this->generic_route_meta( (string) $route ),
		};

		/*
		 * Only five routes carry structured data, but EVERY route needs to say
		 * which URL it is. The rest (membership, dashboards, account…) declared
		 * nothing, so with an SEO plugin active they inherited its guess — the
		 * Posts page — and without one they had no canonical at all. A page that
		 * does not name itself gets named by somebody else.
		 */
		if ( ! $this->canonical_printed ) {
			$this->print_canonical( $this->current_route_url() );
		}
	}

	/**
	 * Declare the current route off-limits to crawlers and stand every metadata
	 * emitter down for the rest of the request.
	 *
	 * A private course already 404s, so the noindex is belt-and-braces there; an
	 * unlisted course renders 200 by design (share-by-link), and without this it
	 * would be indexed like any other page — which is the opposite of unlisted.
	 */
	private function hide_from_search(): void {
		$this->hidden_from_public = true;

		printf( '<meta name="robots" content="%s">' . "\n", esc_attr( 'noindex, nofollow' ) );
	}

	/**
	 * The URL of the route being served, straight from the resolved request, so
	 * it tracks slug localization, pagination (/courses/page/2/) and any Pro
	 * route without needing a per-route builder here.
	 */
	private function current_route_url(): string {
		global $wp;

		$path = isset( $wp->request ) ? (string) $wp->request : '';
		if ( '' === $path ) {
			return '';
		}

		return user_trailingslashit( home_url( $path ) );
	}

	/**
	 * Public certificate verification page (/verify/{uuid}/).
	 *
	 * Emits an EducationalOccupationalCredential JSON-LD block plus Open
	 * Graph tags so the credential is machine-readable and link previews
	 * render. Only a VALID (active) certificate produces output — a revoked,
	 * expired, or missing certificate emits neither block, so an unverifiable
	 * URL never advertises a credential that doesn't stand up.
	 *
	 * `Certificate::verify()` increments the verify counter as a side effect;
	 * we deliberately do NOT call it here (the view-data layer already does on
	 * the same request) and instead read the row with the non-mutating
	 * find_by_uuid() so wp_head doesn't double-count a single page view.
	 */
	private function certificate_schema(): void {
		$uuid        = sanitize_text_field( get_query_var( 'learnomy_slug', '' ) );
		$certificate = $uuid ? Certificate::find_by_uuid( $uuid ) : null;

		if ( ! $certificate || 'active' !== ( $certificate->status ?? '' ) ) {
			return;
		}

		$course       = Course::find( (int) $certificate->course_id );
		$recipient    = get_userdata( (int) $certificate->user_id );
		$verify_url   = \Learnomy\route_url( 'certificate-verify', array( 'slug' => (string) $certificate->uuid ) );
		$course_name  = $course ? (string) $course->title : __( 'Course', 'learnomy' );
		$site_name    = get_bloginfo( 'name' );
		$student_name = $recipient ? $recipient->display_name : __( 'A learner', 'learnomy' );

		/* translators: 1: course title, 2: site name */
		$credential_name = sprintf( __( 'Certificate of Completion - %1$s', 'learnomy' ), $course_name );
		$og_description  = sprintf(
			/* translators: 1: student name, 2: course title, 3: site name */
			__( '%1$s completed %2$s on %3$s. This certificate is verified.', 'learnomy' ),
			$student_name,
			$course_name,
			$site_name
		);

		$schema = array(
			'@context'           => 'https://schema.org',
			'@type'              => 'EducationalOccupationalCredential',
			'name'               => $credential_name,
			'description'        => $og_description,
			'url'                => $verify_url,
			'credentialCategory' => 'Certificate',
			'recognizedBy'       => array(
				'@type' => 'Organization',
				'name'  => $site_name,
				'url'   => home_url( '/' ),
			),
			'about'              => array(
				'@type' => 'Course',
				'name'  => $course_name,
			),
		);

		if ( ! empty( $certificate->issued_at ) && '0000-00-00 00:00:00' !== $certificate->issued_at ) {
			$schema['dateCreated'] = gmdate( 'Y-m-d', strtotime( (string) $certificate->issued_at ) );
		}

		// The verify page previously emitted OG with no og:image, so link
		// previews rendered without artwork. Prefer the course thumbnail; fall
		// back to the site logo so a credential always carries an image.
		$og_image = $this->course_image_url( $course );
		if ( '' === $og_image ) {
			$og_image = $this->site_logo_url();
		}

		$this->print_schema( $schema );
		$this->print_open_graph( $credential_name, $og_description, $verify_url, 'website', $og_image );
	}

	/**
	 * Whether Learnomy should yield canonical + Open Graph output to an active
	 * SEO plugin. Yoast and Rank Math emit a canonical link and Open Graph tags
	 * for the URLs they know about, so Learnomy emitting its own there produces
	 * duplicate, competing meta. When one of them is active Learnomy suppresses
	 * ONLY its canonical + OG output on those URLs — the Course / ItemList /
	 * Person JSON-LD stays on, because it is unique structured data those
	 * general SEO plugins do not provide.
	 *
	 * We never yield on a Learnomy route, because there is nobody to yield TO.
	 * A course is a row in `lrn_courses`, not a post — Yoast and Rank Math have
	 * no indexable, no editor box and no data for it, so standing down there
	 * left the page with NO canonical and NO Open Graph at all (verified: with
	 * Yoast active a course page emitted zero canonical tags). Before the
	 * query-state fix it was worse than nothing: the SEO plugin read
	 * `is_home = true`, believed it, and published the Posts page's title and a
	 * canonical pointing at /blog/ on every course in the catalog
	 * (Basecamp #10101955828). Yielding is only ever right where the other
	 * plugin actually owns the page.
	 *
	 * A site can override the decision with the `learnomy_seo_yield_to_plugin`
	 * filter — force it false to keep Learnomy's tags, or true to yield in a
	 * custom setup where another plugin genuinely owns canonical/OG for our
	 * routes (a bespoke Yoast integration, say).
	 *
	 * @return bool True when Learnomy should suppress its canonical + OG tags.
	 */
	private function should_yield(): bool {
		$seo_plugin_active = defined( 'WPSEO_VERSION' )
			|| class_exists( 'WPSEO_Frontend' )
			|| class_exists( 'RankMath' )
			|| defined( 'RANK_MATH_VERSION' );

		// Our own routes: we are the only one who knows what this page is.
		if ( $seo_plugin_active && get_query_var( 'learnomy_route' ) ) {
			$seo_plugin_active = false;
		}

		return (bool) apply_filters( 'learnomy_seo_yield_to_plugin', $seo_plugin_active );
	}

	/**
	 * Emit Open Graph tags for a page. Kept separate from the JSON-LD
	 * printer because OG is a set of <meta> tags, not a JSON blob.
	 *
	 * Suppressed when an SEO plugin owns the OG output (see should_yield()).
	 *
	 * @param string $title       og:title.
	 * @param string $description og:description.
	 * @param string $url         og:url (canonical page URL).
	 * @param string $type        og:type (e.g. website, profile). Defaults to website.
	 * @param string $image       og:image absolute URL. Omitted when empty.
	 */
	private function print_open_graph( string $title, string $description, string $url, string $type = 'website', string $image = '' ): void {
		if ( $this->hidden_from_public || $this->should_yield() ) {
			return;
		}

		$tags = array(
			'og:type'        => $type,
			'og:title'       => $title,
			'og:description' => $description,
			'og:url'         => $url,
			'og:site_name'   => get_bloginfo( 'name' ),
		);

		if ( '' !== $image ) {
			$tags['og:image'] = $image;
		}

		$url_properties = array( 'og:url', 'og:image' );
		foreach ( $tags as $property => $content ) {
			// URL-valued properties go through esc_url; text-valued through esc_attr.
			$escaped = in_array( $property, $url_properties, true )
				? esc_url( $content )
				: esc_attr( $content );
			printf(
				'<meta property="%1$s" content="%2$s">' . "\n",
				esc_attr( $property ),
				$escaped // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above per property type.
			);
		}

		// The plain meta description ships with OG rather than separately: they
		// answer the same question, and emitting one without the other is how a
		// page ends up sharing well on social and blank in search results.
		$this->print_meta_description( $description );
	}

	/**
	 * Emit `<meta name="description">` once per page.
	 *
	 * Every Learnomy route is a virtual URL with no post behind it, so WordPress
	 * emits nothing here and — with no SEO plugin claiming the route — Google
	 * composes its own snippet from whatever text it finds first, which on a
	 * course catalogue is usually navigation chrome. A site owner who bought an
	 * LMS to sell courses could not control how any of it appeared in search.
	 *
	 * Suppressed when an SEO plugin owns head output (see should_yield()).
	 *
	 * @param string $description Human description, already plain text.
	 */
	private function print_meta_description( string $description ): void {
		$description = trim( wp_strip_all_tags( $description ) );

		if ( '' === $description || $this->description_printed || $this->hidden_from_public || $this->should_yield() ) {
			return;
		}

		/**
		 * Filter the meta description for a Learnomy route.
		 *
		 * @param string $description The description about to be printed.
		 * @param string $route       Current Learnomy route name.
		 */
		$description = (string) apply_filters(
			'learnomy_meta_description',
			$description,
			(string) get_query_var( 'learnomy_route' )
		);

		if ( '' === trim( $description ) ) {
			return;
		}

		// Only DERIVED text is capped. The cap exists so machine-composed copy
		// cannot run long; an owner who typed a longer description meant it, and
		// truncating their sentence mid-word is worse than letting a search
		// engine elide the tail itself. Without this the two tags disagreed:
		// og:description carried the full authored text while this one was cut
		// with an ellipsis, from the same source string.
		if ( ! $this->description_is_authored ) {
			$description = self::truncate_description( $description );
		}

		printf( '<meta name="description" content="%s">' . "\n", esc_attr( $description ) );

		$this->description_printed = true;
	}

	/**
	 * Cap a description at the length Google shows, on a word boundary.
	 *
	 * The previous cap used wp_trim_words() at 30 words, but a word count is not
	 * a length: 30 words is often ~165 chars, past Google's ~155-char snippet
	 * window, so the tail rendered cut off mid-clause ("…state, and hooks from").
	 * Cap by characters instead, trim back to the last whole word, drop any
	 * trailing punctuation, and append a single ellipsis so the truncation reads
	 * as deliberate. A description already inside the window is returned as-is.
	 *
	 * @param string $description Plain-text description.
	 * @return string
	 */
	private static function truncate_description( string $description ): string {
		/**
		 * Max meta-description length before truncation.
		 *
		 * @param int $max Character budget (default 155, Google's snippet window).
		 */
		$max = (int) apply_filters( 'learnomy_meta_description_max_length', 155 );
		$max = max( 20, $max );

		if ( mb_strlen( $description ) <= $max ) {
			return $description;
		}

		// Reserve one character for the ellipsis.
		$clipped = mb_substr( $description, 0, $max - 1 );

		// Back up to the last space so the snippet never ends mid-word.
		$last_space = mb_strrpos( $clipped, ' ' );
		if ( false !== $last_space && $last_space > 0 ) {
			$clipped = mb_substr( $clipped, 0, $last_space );
		}

		return rtrim( $clipped, " \t\n\r,;:.!?-" ) . '…';
	}

	/**
	 * OG + description for the routes that carry no bespoke structured data —
	 * membership pricing, dashboards, account pages, enrolment steps.
	 *
	 * Before this, only five routes emitted anything beyond a canonical, so
	 * `/membership/` — the pricing page, the single most-shared URL on a course
	 * site — produced no title, description or image when pasted into Slack,
	 * WhatsApp or a search result. Titles come from `Template_Loader::build_title()`
	 * so the head and the visible <title> can never disagree; there is one
	 * source, not two.
	 *
	 * @param string $route Current Learnomy route name.
	 */
	private function generic_route_meta( string $route ): void {
		if ( '' === $route || $this->should_yield() ) {
			return;
		}

		$title = \Learnomy\Template_Loader::build_title( $route, array() );
		if ( '' === $title ) {
			return;
		}

		$site_name = get_bloginfo( 'name' );

		/*
		 * Descriptions are deliberately concrete per route rather than one
		 * generic sentence — "Membership plans and pricing" is a useful search
		 * snippet, "Learnomy page" is not. Routes not listed fall back to the
		 * title plus the site name, which still beats letting the engine guess
		 * from nav chrome.
		 */
		$descriptions = array(
			'membership-pricing' => __( 'Membership plans and pricing. Compare what each plan includes and choose the one that fits.', 'learnomy' ),
			'membership-manage'  => __( 'Manage your membership, billing details and plan.', 'learnomy' ),
			'become-instructor'  => __( 'Apply to teach. Share your expertise and publish your own courses.', 'learnomy' ),
			'standalone-quiz'    => __( 'Take this quiz and check your understanding.', 'learnomy' ),
		);

		$description = $descriptions[ $route ] ?? sprintf(
			/* translators: 1: page title, 2: site name */
			__( '%1$s on %2$s.', 'learnomy' ),
			$title,
			$site_name
		);

		$this->print_open_graph( $title, $description, $this->current_route_url() );
	}

	/**
	 * Emit a self-referential canonical link for the current page. Kept beside
	 * the OG printer so every Learnomy route can declare its preferred URL in
	 * one place. URLs come from the `\Learnomy\*_url()` helpers so the canonical
	 * tracks any slug localization the site owner applied.
	 *
	 * Suppressed when an SEO plugin owns the canonical output (see should_yield()).
	 */
	private function print_canonical( string $url ): void {
		if ( '' === $url || $this->canonical_printed || $this->hidden_from_public || $this->should_yield() ) {
			return;
		}
		$this->canonical_printed = true;
		printf(
			'<link rel="canonical" href="%s">' . "\n",
			esc_url( $url )
		);
	}

	private function course_schema(): void {
		$slug   = get_query_var( 'learnomy_slug', '' );
		$course = Course::find_by_slug( sanitize_title( $slug ) );

		// Published only — the route 404s a non-published course for the
		// public, but this emitter resolved the slug independently and still
		// printed the draft's title/description as Course JSON-LD on that 404
		// (found while verifying Basecamp 10132217693). Schema for a page that
		// does not exist leaks unreleased content to crawlers.
		if ( ! $course || 'published' !== (string) ( $course->status ?? 'draft' ) ) {
			return;
		}

		// Same reasoning, the visibility half of it: a PRIVATE course 404s for the
		// public and an UNLISTED one is deliberately kept out of search, yet this
		// emitter re-resolved the slug and published the title, description, price
		// and rating anyway — on the 404 response itself (Basecamp 10134139449).
		// Viewer 0 asks the crawler's question, not "what can whoever loaded this
		// page see", so an administrator's own page view cannot mint public
		// metadata for a hidden course.
		if ( Course::is_hidden_from_public( $course, 0 ) ) {
			$this->hide_from_search();
			return;
		}

		$instructors  = Course::get_instructors( (int) $course->id );
		$primary      = $this->get_primary_instructor( $instructors );
		$provider_url = home_url( '/' );
		$course_url   = \Learnomy\route_url( 'course', array( 'slug' => (string) $course->slug ) );

		$schema = array(
			'@context'    => 'https://schema.org',
			'@type'       => 'Course',
			'name'        => $course->title,
			'description' => \Learnomy\render_editorjs_content( (string) ( $course->description ?? '' ), '', 'plain' ),
			'url'         => $course_url,
			'provider'    => array(
				'@type' => 'Organization',
				'name'  => get_bloginfo( 'name' ),
				'url'   => $provider_url,
			),
		);

		if ( $primary ) {
			$schema['instructor'] = $this->build_person_schema( $primary );
		}

		$price = (float) ( $course->sale_price ?? $course->price );
		if ( $price > 0 ) {
			$schema['offers'] = array(
				'@type'         => 'Offer',
				'price'         => number_format( $price, 2, '.', '' ),
				'priceCurrency' => ( get_option( 'learnomy_settings', array() )['currency'] ?? 'USD' ),
				'availability'  => 'https://schema.org/InStock',
				'url'           => $course_url,
			);
		} else {
			$schema['offers'] = array(
				'@type'         => 'Offer',
				'price'         => '0',
				'priceCurrency' => ( get_option( 'learnomy_settings', array() )['currency'] ?? 'USD' ),
				'availability'  => 'https://schema.org/InStock',
				'url'           => $course_url,
			);
		}

		if ( (float) $course->avg_rating > 0 && (int) $course->review_count > 0 ) {
			$schema['aggregateRating'] = array(
				'@type'       => 'AggregateRating',
				'ratingValue' => number_format( (float) $course->avg_rating, 1 ),
				'reviewCount' => (int) $course->review_count,
				'bestRating'  => '5',
				'worstRating' => '1',
			);
		}

		$schema['hasCourseInstance'] = array(
			'@type'          => 'CourseInstance',
			'courseMode'     => 'online',
			'courseWorkload' => $this->format_duration( (int) ( $course->duration_seconds ?? 0 ) ),
			'instructor'     => $primary ? $this->build_person_schema( $primary ) : null,
		);

		if ( ! empty( $course->prerequisites ) ) {
			$prereqs = json_decode( $course->prerequisites, true );
			if ( is_array( $prereqs ) ) {
				$schema['coursePrerequisites'] = $prereqs;
			}
		}

		$image_url = $this->course_image_url( $course );
		if ( '' !== $image_url ) {
			$schema['image'] = $image_url;
		}

		if ( ! empty( $course->created_at ) && '0000-00-00 00:00:00' !== $course->created_at ) {
			$schema['datePublished'] = gmdate( 'Y-m-d', strtotime( $course->created_at ) );
		}

		if ( ! empty( $course->updated_at ) && '0000-00-00 00:00:00' !== $course->updated_at ) {
			$schema['dateModified'] = gmdate( 'Y-m-d', strtotime( $course->updated_at ) );
		}

		$this->print_schema( $schema );

		// Open Graph + canonical so a shared course link renders a rich preview
		// instead of a bare URL (G7 — the course page emitted JSON-LD but no OG).
		// Per-entity overrides win over the derived values; anything the owner
		// left blank keeps deriving exactly as it did before this shipped.
		$overrides = $this->seo_overrides( $course );

		$og_title = '' !== $overrides['seo_title'] ? $overrides['seo_title'] : (string) $course->title;

		$og_desc = '' !== $overrides['meta_description']
			? $overrides['meta_description']
			: mb_substr( \Learnomy\render_editorjs_content( (string) ( $course->description ?? '' ), '', 'plain' ), 0, 200 );

		// Tell print_meta_description (called from print_open_graph) not to cap
		// this one, so og:description and the meta description cannot disagree.
		$this->description_is_authored = ( '' !== $overrides['meta_description'] );

		$og_image = '' !== $overrides['og_image'] ? $overrides['og_image'] : $this->course_image_url( $course );
		if ( '' === $og_image ) {
			$og_image = $this->site_logo_url();
		}

		$this->print_open_graph( $og_title, $og_desc, $course_url, 'website', $og_image );
		$this->print_canonical( $course_url );
		$this->print_robots( $overrides['meta_robots'] );
	}

	private function course_list_schema(): void {
		// Viewer 0 — the ItemList describes the catalog as the public sees it, so
		// an administrator loading /courses/ cannot publish unlisted or private
		// courses into the page's structured data.
		$courses = Course::list_published( 1, 50, 0 );

		if ( empty( $courses ) ) {
			return;
		}

		$items = array();
		foreach ( $courses as $position => $course ) {
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $position + 1,
				'item'     => array(
					'@type'       => 'Course',
					'name'        => $course->title,
					'description' => mb_substr( \Learnomy\render_editorjs_content( (string) ( $course->description ?? '' ), '', 'plain' ), 0, 200 ),
					'url'         => \Learnomy\route_url( 'course', array( 'slug' => (string) $course->slug ) ),
					'provider'    => array(
						'@type' => 'Organization',
						'name'  => get_bloginfo( 'name' ),
						'url'   => home_url( '/' ),
					),
				),
			);
		}

		$schema = array(
			'@context'        => 'https://schema.org',
			'@type'           => 'ItemList',
			'name'            => sprintf(
				/* translators: %s: site name */
				__( 'Courses on %s', 'learnomy' ),
				get_bloginfo( 'name' )
			),
			'url'             => \Learnomy\route_url( 'catalog' ),
			'numberOfItems'   => count( $items ),
			'itemListElement' => $items,
		);

		$this->print_schema( $schema );

		// Open Graph + canonical for the catalog (G7).
		$catalog_url = \Learnomy\route_url( 'catalog' );
		$this->print_open_graph(
			/* translators: %s: site name */
			sprintf( __( 'Courses on %s', 'learnomy' ), get_bloginfo( 'name' ) ),
			/* translators: %s: site name */
			sprintf( __( 'Browse the course catalog on %s.', 'learnomy' ), get_bloginfo( 'name' ) ),
			$catalog_url,
			'website',
			$this->site_logo_url()
		);
		$this->print_canonical( $catalog_url );
	}

	private function person_schema(): void {
		$username = (string) get_query_var( 'learnomy_slug', '' );
		$user     = '' !== $username ? get_user_by( 'login', sanitize_user( $username ) ) : false;
		// Nicename fallback so this resolves the SAME person the profile route
		// resolved (Instructor_Profile_View_Data tries login then nicename). It
		// only looked up the login, so a profile reached by its nicename silently
		// shipped no Person block and no link preview at all.
		if ( ! $user && '' !== $username ) {
			$user = get_user_by( 'slug', $username );
		}

		// Structured data about a person is a publication of that person. Gate it
		// on the same predicate as the page body, so a member who has no public
		// instructor profile is never described to a crawler — belt and braces
		// beside the is_404() guard above, which already covers the gated render.
		if ( ! $user || ! \Learnomy\Models\Instructor::has_public_profile( (int) $user->ID ) ) {
			return;
		}

		$profile_url = \Learnomy\route_url( 'instructor-profile', array( 'slug' => (string) $user->user_login ) );

		// Source profile fields from the consolidated lrn_instructors row, with
		// user_meta fallback (matches the public profile read path).
		$profile   = \Learnomy\Models\Instructor::find_by_user( (int) $user->ID );
		$bio_raw   = $profile && '' !== trim( (string) ( $profile->bio ?? '' ) )
			? (string) $profile->bio
			: (string) get_user_meta( $user->ID, 'description', true );
		$headline  = $profile ? trim( (string) ( $profile->headline ?? '' ) ) : '';
		$expertise = $profile && '' !== trim( (string) ( $profile->expertise ?? '' ) )
			? (string) $profile->expertise
			: (string) get_user_meta( $user->ID, 'learnomy_expertise', true );

		$schema = array(
			'@context'    => 'https://schema.org',
			'@type'       => 'Person',
			'name'        => $user->display_name,
			'url'         => $profile_url,
			'description' => wp_strip_all_tags( $bio_raw ),
		);

		// Richer Person properties for SEO when the instructor filled them in.
		if ( '' !== $headline ) {
			$schema['jobTitle'] = $headline;
		}
		$knows_about = array_values( array_filter( array_map( 'trim', explode( ',', $expertise ) ) ) );
		if ( ! empty( $knows_about ) ) {
			$schema['knowsAbout'] = $knows_about;
		}

		$avatar_url = get_avatar_url( $user->ID, array( 'size' => 200 ) );
		if ( $avatar_url ) {
			$schema['image'] = $avatar_url;
		}

		$this->print_schema( $schema );

		// Open Graph for the public profile so link previews render. Mirrors the
		// certificate-verify OG mechanism; og:type is `profile` (Person), the
		// image is the instructor avatar, and the URL is the localized
		// instructor-profile URL helper.
		$bio = wp_strip_all_tags( $bio_raw );
		if ( '' === $bio ) {
			/* translators: %s: site name */
			$bio = sprintf( __( 'Instructor on %s', 'learnomy' ), get_bloginfo( 'name' ) );
		}
		$this->print_open_graph(
			(string) $user->display_name,
			$bio,
			$profile_url,
			'profile',
			(string) $avatar_url
		);
	}

	/**
	 * Category archive (/courses/category/{slug}/).
	 *
	 * Emits a self-referential canonical link plus a CollectionPage JSON-LD
	 * block listing the published courses in the category. Mirrors the
	 * course-list ItemList shape so the catalog and category archive describe
	 * their listings the same way.
	 */
	private function category_schema(): void {
		$category = $this->listed_category();

		if ( ! $category ) {
			return;
		}

		// The canonical names THIS page, including which page of the archive it
		// is. Pointing page 2 at page 1 tells a crawler the two are duplicates
		// and that everything below the first twelve courses is not worth
		// keeping; a paginated archive self-canonicalises per page.
		$paged        = \Learnomy\current_page();
		$category_url = \Learnomy\route_url(
			'category',
			array(
				'slug'  => (string) $category->slug,
				'paged' => $paged,
			)
		);

		// Self-referential canonical so the archive doesn't compete with the
		// catalog or the ?cat= form for the same listing.
		$this->print_canonical( $category_url );

		// Viewer 0 — see course_list_schema(); the CollectionPage must list only
		// what the public can reach. Page 1 only: the list is capped at 50 while
		// the archive pages at 12, so on page 2 an ItemList built from offset 0
		// would describe courses that are not on the page it now self-canonicals
		// to. A wrong list is worse than no list.
		$courses = 1 === $paged
			? Course::get_published_by_category( (int) $category->id, 50, 0 )
			: array();

		$items = array();
		foreach ( $courses as $position => $course ) {
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $position + 1,
				'item'     => array(
					'@type'       => 'Course',
					'name'        => $course->title,
					'description' => mb_substr( \Learnomy\render_editorjs_content( (string) ( $course->description ?? '' ), '', 'plain' ), 0, 200 ),
					'url'         => \Learnomy\route_url( 'course', array( 'slug' => (string) $course->slug ) ),
					'provider'    => array(
						'@type' => 'Organization',
						'name'  => get_bloginfo( 'name' ),
						'url'   => home_url( '/' ),
					),
				),
			);
		}

		$schema = array(
			'@context'   => 'https://schema.org',
			'@type'      => 'CollectionPage',
			'name'       => sprintf(
				/* translators: %s: category name */
				__( '%s courses', 'learnomy' ),
				(string) $category->name
			),
			'url'        => $category_url,
			'mainEntity' => array(
				'@type'           => 'ItemList',
				'numberOfItems'   => count( $items ),
				'itemListElement' => $items,
			),
		);

		if ( ! empty( $items ) ) {
			$this->print_schema( $schema );
		}

		// The description the site owner typed into the category is the one
		// they want in a search result. Only when it is empty do we compose
		// one, which beats letting the engine guess from nav chrome.
		$description = trim( wp_strip_all_tags( (string) ( $category->description ?? '' ) ) );
		if ( '' === $description ) {
			$description = sprintf(
				/* translators: 1: category name 2: site name */
				__( '%1$s courses on %2$s.', 'learnomy' ),
				(string) $category->name,
				get_bloginfo( 'name' )
			);
		}

		// Open Graph for the category archive (canonical already printed above) (G7).
		$this->print_open_graph(
			/* translators: %s: category name */
			sprintf( __( '%s courses', 'learnomy' ), (string) $category->name ),
			$description,
			$category_url,
			'website',
			$this->site_logo_url()
		);
	}

	/**
	 * The category this listing is about, or null when it is the whole catalog.
	 *
	 * Resolves through `\Learnomy\current_category_slug()`, the one accessor
	 * that knows both spellings of the same page: the pretty archive route and
	 * the catalog's `?cat=` filter. Memoized because output_schema() asks once
	 * to pick a route and category_schema() asks again to render it.
	 *
	 * @return object|null Category row, or null.
	 */
	private function listed_category(): ?object {
		if ( false !== $this->listed_category ) {
			return $this->listed_category;
		}

		$slug                  = \Learnomy\current_category_slug();
		$this->listed_category = '' !== $slug ? Category::find_by_slug( $slug ) : null;

		return $this->listed_category;
	}

	/**
	 * Resolved {@see self::listed_category()}. `false` means "not yet asked",
	 * which null cannot express here (null is a real answer).
	 *
	 * @var object|null|false
	 */
	private $listed_category = false;

	/**
	 * Absolute URL of a course's thumbnail, or '' when it has none.
	 */
	/**
	 * Per-entity SEO overrides for a course, normalised.
	 *
	 * The fallback chain is override -> derived -> nothing. Every value is
	 * optional and an unset one leaves the derived behaviour exactly as it was,
	 * which is what makes this safe to add to every existing course at once.
	 *
	 * NOTE ON PRECEDENCE. `should_yield()` deliberately does NOT yield to Yoast
	 * or RankMath on our own routes, so this override is not competing with
	 * them — it is the ONLY control a site owner has over a course's snippet.
	 * Courses live in a custom table, so those plugins cannot see them at all.
	 *
	 * @param object|null $course Course row.
	 * @return array{seo_title:string, meta_description:string, og_image:string, meta_robots:string}
	 */
	private function seo_overrides( ?object $course ): array {
		$empty = array(
			'seo_title'        => '',
			'meta_description' => '',
			'og_image'         => '',
			'meta_robots'      => '',
		);

		if ( ! $course ) {
			return $empty;
		}

		// Share image: a direct URL wins, then a media-library id — the same
		// order thumbnail_url/thumbnail_id already use, because a course seeded
		// with an external image has no attachment to resolve.
		$og_image = trim( (string) ( $course->og_image_url ?? '' ) );
		if ( '' === $og_image && ! empty( $course->og_image_id ) ) {
			$og_image = (string) ( wp_get_attachment_image_url( (int) $course->og_image_id, 'large' ) ?: '' );
		}

		return array(
			'seo_title'        => trim( (string) ( $course->seo_title ?? '' ) ),
			// NOT truncated. truncate_description() caps DERIVED text so machine
			// copy cannot run long; an owner who typed 200 characters meant them,
			// and cutting their sentence mid-word is worse than a search engine
			// eliding the tail itself.
			'meta_description' => trim( (string) ( $course->meta_description ?? '' ) ),
			'og_image'         => $og_image,
			'meta_robots'      => self::normalise_robots( (string) ( $course->meta_robots ?? '' ) ),
		);
	}

	/**
	 * Accept only known robots directives.
	 *
	 * Free text here is a footgun: a typo like "no-index" is silently ignored by
	 * crawlers, so the owner believes a course is hidden when it is indexed. An
	 * unrecognised value is dropped rather than emitted.
	 *
	 * @param string $value Raw stored directive.
	 */
	private static function normalise_robots( string $value ): string {
		$value = strtolower( trim( $value ) );
		if ( '' === $value ) {
			return '';
		}

		$allowed = array( 'index', 'noindex', 'follow', 'nofollow', 'noarchive', 'nosnippet', 'noimageindex' );

		$parts = array();
		foreach ( preg_split( '/\s*,\s*/', $value ) as $part ) {
			if ( in_array( $part, $allowed, true ) && ! in_array( $part, $parts, true ) ) {
				$parts[] = $part;
			}
		}

		return implode( ',', $parts );
	}

	/**
	 * Emit `<meta name="robots">` for an explicit per-entity directive.
	 *
	 * Separate from hide_from_search(), which is the plugin deciding a page must
	 * not be indexed (draft, private, unlisted). This is the site owner deciding.
	 *
	 * @param string $robots Normalised directive.
	 */
	private function print_robots( string $robots ): void {
		if ( '' === $robots ) {
			return;
		}

		printf( '<meta name="robots" content="%s">' . "\n", esc_attr( $robots ) );
	}

	private function course_image_url( ?object $course ): string {
		if ( ! $course ) {
			return '';
		}
		// Mirror the resolution order used by the course card
		// (templates/partials/course-card.php): a direct thumbnail_url wins,
		// then a media-library thumbnail_id. Courses seeded with an external
		// image URL have no attachment ID, so checking thumbnail_id alone
		// dropped their artwork from the share preview.
		if ( ! empty( $course->thumbnail_url ) ) {
			return (string) $course->thumbnail_url;
		}
		if ( ! empty( $course->thumbnail_id ) ) {
			$url = wp_get_attachment_image_url( (int) $course->thumbnail_id, 'large' );
			return $url ?: '';
		}
		return '';
	}

	/**
	 * Absolute URL of the site's custom logo, or '' when none is set.
	 */
	private function site_logo_url(): string {
		$logo_id = (int) get_theme_mod( 'custom_logo' );
		if ( $logo_id <= 0 ) {
			return '';
		}
		$url = wp_get_attachment_image_url( $logo_id, 'full' );
		return $url ?: '';
	}

	private function get_primary_instructor( array $instructors ): ?object {
		foreach ( $instructors as $instructor ) {
			if ( ! empty( $instructor->is_primary ) ) {
				$wp_user = get_user_by( 'ID', (int) $instructor->user_id );
				return $wp_user ?: null;
			}
		}

		if ( ! empty( $instructors[0]->user_id ) ) {
			$wp_user = get_user_by( 'ID', (int) $instructors[0]->user_id );
			return $wp_user ?: null;
		}

		return null;
	}

	private function build_person_schema( \WP_User $user ): array {
		return array(
			'@type' => 'Person',
			'name'  => $user->display_name,
			'url'   => \Learnomy\route_url( 'instructor-profile', array( 'slug' => (string) $user->user_login ) ),
		);
	}

	private function format_duration( int $seconds ): string {
		if ( $seconds <= 0 ) {
			return 'PT0S';
		}

		$hours   = floor( $seconds / 3600 );
		$minutes = floor( ( $seconds % 3600 ) / 60 );
		$secs    = $seconds % 60;

		$iso = 'PT';
		if ( $hours > 0 ) {
			$iso .= $hours . 'H';
		}
		if ( $minutes > 0 ) {
			$iso .= $minutes . 'M';
		}
		if ( $secs > 0 || ( 0 === $hours && 0 === $minutes ) ) {
			$iso .= $secs . 'S';
		}

		return $iso;
	}

	private function print_schema( array $schema ): void {
		if ( $this->hidden_from_public ) {
			return;
		}
		// Remove null values to keep the JSON clean.
		$schema = array_filter( $schema, static fn( $v ) => null !== $v );
		echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
