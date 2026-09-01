<?php
/**
 * Publishes Learnomy URLs to whichever sitemap system the site actually uses.
 *
 * Registers two providers — courses and instructor profiles — with WordPress
 * core sitemaps (5.5+), and re-presents the same providers to Yoast when Yoast
 * is the one serving sitemaps.
 *
 * @package Learnomy\SEO
 */

namespace Learnomy\SEO;

defined( 'ABSPATH' ) || exit;

class Sitemap {

	public function __construct() {
		// WordPress core sitemaps are available from 5.5. Bail silently on older installs.
		if ( ! function_exists( 'wp_register_sitemap_provider' ) ) {
			return;
		}

		add_filter( 'wp_sitemaps_post_types', array( $this, 'remove_default' ) );
		add_action( 'init', array( $this, 'register_providers' ) );

		/*
		 * Yoast turns core sitemaps off and serves its own, which has never heard
		 * of a course — so registering with core alone meant the whole catalog
		 * silently disappeared from every sitemap on a Yoast site (verified:
		 * /wp-sitemap-learnomy-courses-1.xml -> 404, and sitemap_index.xml listed
		 * post/page/category/author only). The pages looked fine, so nobody
		 * reported it. Same providers, second doorway.
		 */
		add_filter( 'wpseo_sitemaps_providers', array( $this, 'register_yoast_providers' ) );
	}

	/**
	 * Learnomy does not use WP post types for courses, so there is nothing
	 * meaningful in the default post-type sitemap to preserve. Other post types
	 * (pages, etc.) are untouched.
	 */
	public function remove_default( array $post_types ): array {
		return $post_types;
	}

	public function register_providers(): void {
		wp_register_sitemap_provider( 'learnomycourses', new Course_Sitemap_Provider() );
		wp_register_sitemap_provider( 'learnomyinstructors', new Instructor_Sitemap_Provider() );
	}

	/**
	 * Hand Yoast the same providers, wrapped in its own interface.
	 *
	 * Runs only when Yoast is active (nothing else fires this filter), and the
	 * interface it type-hints only exists then — hence the guard, which also
	 * keeps this safe if Yoast ever moves the class.
	 *
	 * @param array<int, object> $providers Yoast's external providers.
	 * @return array<int, object>
	 */
	public function register_yoast_providers( $providers ) {
		if ( ! interface_exists( '\WPSEO_Sitemap_Provider' ) || ! class_exists( '\WPSEO_Sitemaps_Router' ) ) {
			return $providers;
		}

		$providers[] = new Yoast_Sitemap_Adapter( new Course_Sitemap_Provider(), 'learnomycourses' );
		$providers[] = new Yoast_Sitemap_Adapter( new Instructor_Sitemap_Provider(), 'learnomyinstructors' );

		return $providers;
	}
}
