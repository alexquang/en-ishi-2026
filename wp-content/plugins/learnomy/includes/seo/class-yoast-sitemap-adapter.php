<?php
/**
 * Presents a Learnomy core-sitemap provider to Yoast.
 *
 * @package Learnomy\SEO
 */

namespace Learnomy\SEO;

defined( 'ABSPATH' ) || exit;

/**
 * Yoast does not use WordPress core's sitemaps — it disables them, redirects
 * /wp-sitemap.xml to its own index, and serves sitemaps it builds itself. Our
 * courses and instructors are registered ONLY with core, so on a Yoast site
 * they vanished from every sitemap: /wp-sitemap-learnomy-courses-1.xml 404'd
 * and sitemap_index.xml listed post/page/category/author and nothing else. The
 * entire catalog was undiscoverable, and no card ever reported it because the
 * pages themselves looked fine.
 *
 * This adapter re-presents an existing `WP_Sitemaps_Provider` through Yoast's
 * own provider interface. It is deliberately a translation layer and nothing
 * else: the URL list, the pagination, the lastmod, and the private-academy gate
 * all stay in the core providers, which remain the single source of truth. Add
 * a URL rule there and both sitemaps get it.
 *
 * The interfaces differ in ways that fail SILENTLY — a paging mistake here does
 * not error, it publishes a valid-looking sitemap with nothing in it:
 *   - BOTH page from 1. Yoast's `class-sitemaps.php` declares
 *     `private $current_page = 1` and only overwrites it when `sitemap_n > 0`.
 *     Read it, do not assume: guessing it was 0-based here shipped an HTTP 200
 *     with an empty <urlset> hiding all 72 courses.
 *   - core index entries use `lastmod` (Y-m-d); sitemap entries use `mod` (W3C).
 *   - Yoast asks each provider `handles_type()` before anything else.
 */
class Yoast_Sitemap_Adapter implements \WPSEO_Sitemap_Provider {

	/**
	 * The core provider doing the real work.
	 *
	 * @var \WP_Sitemaps_Provider
	 */
	private $provider;

	/**
	 * Sitemap type name, e.g. 'learnomy-courses'. Matches the core provider's
	 * name so one entity has one identity in both sitemaps.
	 *
	 * @var string
	 */
	private $type;

	/**
	 * @param \WP_Sitemaps_Provider $provider Core provider to wrap.
	 * @param string                $type     Sitemap type name.
	 */
	public function __construct( \WP_Sitemaps_Provider $provider, string $type ) {
		$this->provider = $provider;
		$this->type     = $type;
	}

	/**
	 * @param string $type Sitemap type being requested.
	 * @return bool
	 */
	public function handles_type( $type ) {
		return $this->type === $type;
	}

	/**
	 * One index entry per page of results.
	 *
	 * `$max_entries` is Yoast's page size, which a site owner can change
	 * (wpseo_sitemap_entries_per_page). We ignore it deliberately: the core
	 * provider owns its own page size (learnomy_sitemap_courses_per_page), and
	 * honouring Yoast's number here while the page builder used ours would
	 * publish index links to pages that do not exist.
	 *
	 * @param int $max_entries Yoast's entries-per-page (unused, see above).
	 * @return array<int, array<string, string|null>>
	 */
	public function get_index_links( $max_entries ) {
		$pages = (int) $this->provider->get_max_num_pages();
		if ( $pages < 1 ) {
			return array();
		}

		$links = array();
		for ( $page = 1; $page <= $pages; $page++ ) {
			$links[] = array(
				'loc'     => \WPSEO_Sitemaps_Router::get_base_url( $this->type . '-sitemap' . $page . '.xml' ),
				'lastmod' => null,
			);
		}

		return $links;
	}

	/**
	 * The URLs for one page of this sitemap.
	 *
	 * @param string $type         Sitemap type.
	 * @param int    $max_entries  Yoast's entries-per-page (unused, see get_index_links()).
	 * @param int    $current_page One-based page number (Yoast defaults it to 1).
	 * @return array<int, array<string, string>>
	 */
	public function get_sitemap_links( $type, $max_entries, $current_page ) {
		// Same base as core's provider — both count pages from 1.
		$page_num = max( 1, (int) $current_page );
		$urls     = $this->provider->get_url_list( $page_num );

		$links = array();
		foreach ( (array) $urls as $url ) {
			if ( empty( $url['loc'] ) ) {
				continue;
			}

			$link = array( 'loc' => $url['loc'] );
			if ( ! empty( $url['lastmod'] ) ) {
				// core: 'lastmod' (Y-m-d) -> Yoast: 'mod' (W3C datetime).
				$link['mod'] = gmdate( DATE_W3C, strtotime( (string) $url['lastmod'] ) );
			}

			$links[] = $link;
		}

		return $links;
	}
}
