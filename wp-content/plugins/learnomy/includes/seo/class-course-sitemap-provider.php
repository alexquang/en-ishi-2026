<?php
/**
 * Sitemap provider for published courses.
 *
 * @package Learnomy\SEO
 */

namespace Learnomy\SEO;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Course;

class Course_Sitemap_Provider extends \WP_Sitemaps_Provider {

	public function __construct() {
		$this->name        = 'learnomycourses';
		$this->object_type = 'learnomy_course';
	}

	public function get_url_list( $page_num, $object_subtype = '' ) {
		// A private academy must not advertise its course URLs to crawlers. When
		// "require login for all content" is on the course pages redirect anon
		// visitors to login, so listing them in the public sitemap only leaks the
		// catalogue and feeds soft-404s. Same gate the catalog archive + blocks use.
		if ( \Learnomy\require_login_for_content() ) {
			return array();
		}

		$per_page = $this->get_max_num_entries();
		$offset   = ( max( 1, $page_num ) - 1 ) * $per_page;

		$rows = Course::get_sitemap_page( $per_page, $offset );

		if ( empty( $rows ) ) {
			return array();
		}

		$list = array();

		foreach ( $rows as $row ) {
			$entry = array(
				'loc' => \Learnomy\route_url( 'course', array( 'slug' => $row->slug ) ),
			);

			if ( ! empty( $row->updated_at ) && '0000-00-00 00:00:00' !== $row->updated_at ) {
				$entry['lastmod'] = gmdate( 'Y-m-d', strtotime( $row->updated_at ) );
			}

			$list[] = $entry;
		}

		return $list;
	}

	public function get_max_num_pages( $object_subtype = '' ) {
		if ( \Learnomy\require_login_for_content() ) {
			return 0;
		}

		// Viewer 0 — the same strictly-public question get_sitemap_page() asks, so
		// the page count and the pages themselves cover the same set even when an
		// administrator is the one triggering the render.
		$total = Course::count_published( 0 );
		return (int) ceil( $total / $this->get_max_num_entries() );
	}

	private function get_max_num_entries(): int {
		return (int) apply_filters( 'learnomy_sitemap_courses_per_page', 100 );
	}
}
