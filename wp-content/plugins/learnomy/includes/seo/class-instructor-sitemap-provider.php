<?php
/**
 * Sitemap provider for instructor profile pages.
 *
 * @package Learnomy\SEO
 */

namespace Learnomy\SEO;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Course;

class Instructor_Sitemap_Provider extends \WP_Sitemaps_Provider {

	public function __construct() {
		$this->name        = 'learnomyinstructors';
		$this->object_type = 'learnomy_instructor';
	}

	public function get_url_list( $page_num, $object_subtype = '' ) {
		// A private academy must not publish a machine-readable roster of its
		// members. When "require login for all content" is on, the instructor
		// profile pages already redirect anonymous visitors to login — so
		// listing those URLs (with usernames) in the public sitemap only
		// discloses who the members are and feeds soft-404s. Same gate the
		// course sitemap provider, catalog archive and blocks use. This one
		// method covers all three consumers of this provider: WP core's native
		// sitemap, the standalone sitemap, and the Yoast adapter (both delegate
		// here).
		if ( \Learnomy\require_login_for_content() ) {
			return array();
		}

		$per_page = $this->get_max_num_entries();
		$offset   = ( max( 1, $page_num ) - 1 ) * $per_page;

		// Enumerate the people who HAVE a public profile, not the people who hold
		// the instructor role. Those are different sets: an approved instructor
		// with nothing published yet has no page (this used to publish their
		// username and hand a crawler a soft-404), and the administrator who
		// authors the courses on a single-owner academy does have one. Same
		// predicate as Instructor::has_public_profile(), in set form, so the
		// sitemap and the route can never disagree.
		$ids = Course::public_instructor_ids( $per_page, $offset );

		if ( empty( $ids ) ) {
			return array();
		}

		// One batched user lookup for the whole page — never a get_userdata()
		// per row. `include` preserves nothing about order, which is fine: a
		// urlset is a set, and the id ordering above is what keeps paging stable.
		$query = new \WP_User_Query(
			array(
				'include' => $ids,
				'number'  => count( $ids ),
				'fields'  => array( 'user_login', 'user_registered' ),
			)
		);

		$users = $query->get_results();

		if ( empty( $users ) ) {
			return array();
		}

		$list = array();

		foreach ( $users as $user ) {
			$entry = array(
				'loc' => \Learnomy\route_url( 'instructor-profile', array( 'slug' => (string) $user->user_login ) ),
			);

			if ( ! empty( $user->user_registered ) ) {
				$entry['lastmod'] = gmdate( 'Y-m-d', strtotime( $user->user_registered ) );
			}

			$list[] = $entry;
		}

		return $list;
	}

	public function get_max_num_pages( $object_subtype = '' ) {
		if ( \Learnomy\require_login_for_content() ) {
			return 0;
		}

		// Counted through the same predicate the pages are built from, so a page
		// this method promises always has rows in it.
		$total = Course::count_public_instructors();

		return (int) ceil( $total / $this->get_max_num_entries() );
	}

	private function get_max_num_entries(): int {
		return (int) apply_filters( 'learnomy_sitemap_instructors_per_page', 100 );
	}
}
