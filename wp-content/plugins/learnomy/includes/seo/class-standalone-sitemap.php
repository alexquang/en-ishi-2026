<?php
/**
 * Learnomy's own sitemap, served regardless of which SEO plugin is installed.
 *
 * @package Learnomy\SEO
 */

namespace Learnomy\SEO;

defined( 'ABSPATH' ) || exit;

/**
 * Every sitemap system we can register with is somebody else's: core's is
 * switched off by Yoast and Rank Math, and each plugin's own API is a different
 * shape that can change under us (Yoast's paging is 1-based and undocumented in
 * the interface — assuming otherwise once published an empty sitemap here that
 * returned a perfectly valid HTTP 200).
 *
 * So Learnomy also serves its own, at a URL it owns, that no plugin can turn
 * off, and tells search engines about it through `robots.txt` — which every
 * crawler reads on every site, whichever SEO plugin is installed and none.
 * Yoast, Rank Math, All in One SEO, SEOPress and anything not written yet are
 * covered by the same few lines, with no integration to maintain per vendor.
 *
 * This is the floor, not the ceiling: `Yoast_Sitemap_Adapter` additionally puts
 * courses inside Yoast's own index so a Yoast user sees them where they expect.
 * If that ever breaks, discovery still works through here.
 *
 * URLs come from the same `WP_Sitemaps_Provider` classes core uses, so there is
 * one list of course URLs in this plugin, with one private-academy gate, one
 * page size and one lastmod rule.
 */
class Standalone_Sitemap {

	private const INDEX_VAR = 'learnomy_sitemap';
	private const PAGE_VAR  = 'learnomy_sitemap_page';

	/**
	 * Sitemap type => provider factory.
	 *
	 * @var array<string, callable>
	 */
	private array $types;

	public function __construct() {
		$this->types = array(
			'courses'     => static fn () => new Course_Sitemap_Provider(),
			'instructors' => static fn () => new Instructor_Sitemap_Provider(),
		);

		add_action( 'init', array( $this, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render' ), 0 );
		add_filter( 'robots_txt', array( $this, 'advertise_in_robots' ), 10, 2 );
	}

	/**
	 * Note the index URL: `learnomy-sitemap-index.xml`, not the obvious
	 * `learnomy-sitemap.xml`. Yoast registers a catch-all
	 * `([^/]+?)-sitemap([0-9]+)?\.xml$` rule that claims ANY `*-sitemap.xml`,
	 * so the obvious name resolved to Yoast's `sitemap=learnomy`, a type it has
	 * no provider for — a 404 on our own sitemap. Winning that fight by rule
	 * ordering would mean depending on which plugin registers last, which is not
	 * a thing to depend on. Sitting outside their namespace costs one word and
	 * cannot be taken away. The per-type URLs are already clear of it (the extra
	 * `-courses-1` segment does not match their pattern).
	 */
	public function add_rewrite_rules(): void {
		add_rewrite_rule( '^learnomy-sitemap-index\.xml$', 'index.php?' . self::INDEX_VAR . '=index', 'top' );
		add_rewrite_rule(
			'^learnomy-sitemap-([a-z]+)-([0-9]+)\.xml$',
			'index.php?' . self::INDEX_VAR . '=$matches[1]&' . self::PAGE_VAR . '=$matches[2]',
			'top'
		);
	}

	/**
	 * @param array<int, string> $vars Registered query vars.
	 * @return array<int, string>
	 */
	public function add_query_vars( array $vars ): array {
		$vars[] = self::INDEX_VAR;
		$vars[] = self::PAGE_VAR;

		return $vars;
	}

	/**
	 * Point crawlers at our sitemap from robots.txt. This is the whole
	 * cross-plugin discovery story: no SEO plugin has to know we exist.
	 *
	 * Appended, never replacing — an SEO plugin's own `Sitemap:` lines stay.
	 *
	 * @param string $output The robots.txt content.
	 * @param bool   $public Whether the site is public.
	 * @return string
	 */
	public function advertise_in_robots( $output, $public ) {
		if ( ! $public || \Learnomy\require_login_for_content() ) {
			// A private academy advertises nothing — same rule the providers use.
			return $output;
		}

		return $output . "\nSitemap: " . esc_url_raw( home_url( '/learnomy-sitemap-index.xml' ) ) . "\n";
	}

	public function maybe_render(): void {
		$type = get_query_var( self::INDEX_VAR );
		if ( ! $type ) {
			return;
		}

		if ( \Learnomy\require_login_for_content() ) {
			// Don't publish a catalogue the site itself hides from anon visitors.
			status_header( 404 );
			exit;
		}

		if ( 'index' === $type ) {
			$this->render_index();
		} elseif ( isset( $this->types[ $type ] ) ) {
			$this->render_urlset( $type, max( 1, (int) get_query_var( self::PAGE_VAR ) ) );
		} else {
			status_header( 404 );
		}

		exit;
	}

	private function render_index(): void {
		$this->send_headers();

		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		foreach ( $this->types as $type => $factory ) {
			$pages = (int) $factory()->get_max_num_pages();
			for ( $page = 1; $page <= $pages; $page++ ) {
				printf(
					"\t<sitemap><loc>%s</loc></sitemap>\n",
					esc_url( home_url( "/learnomy-sitemap-{$type}-{$page}.xml" ) )
				);
			}
		}

		echo '</sitemapindex>';
	}

	private function render_urlset( string $type, int $page ): void {
		$urls = $this->types[ $type ]()->get_url_list( $page );

		if ( empty( $urls ) ) {
			status_header( 404 );
			return;
		}

		$this->send_headers();

		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		foreach ( $urls as $url ) {
			if ( empty( $url['loc'] ) ) {
				continue;
			}

			echo "\t<url>\n\t\t<loc>" . esc_url( $url['loc'] ) . "</loc>\n";
			if ( ! empty( $url['lastmod'] ) ) {
				echo "\t\t<lastmod>" . esc_html( (string) $url['lastmod'] ) . "</lastmod>\n";
			}
			echo "\t</url>\n";
		}

		echo '</urlset>';
	}

	private function send_headers(): void {
		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex, follow', true );
	}
}
