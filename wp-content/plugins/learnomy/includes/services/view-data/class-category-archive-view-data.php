<?php
/**
 * View-data resolver for the category-archive template.
 *
 * Replaces direct Model calls in templates/category-archive.php with a
 * pre-resolved data array so the template has zero DB queries.
 *
 * @package Learnomy
 */

namespace Learnomy\Services\View_Data;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Category;
use Learnomy\Models\Course;

class Category_Archive_View_Data {

	/**
	 * Resolve all data needed by templates/category-archive.php.
	 *
	 * @param array $data Route data — must include 'slug' (the category slug).
	 * @return array Pre-fetched view data, or array(category => null) when not found.
	 */
	public static function resolve( array $data ): array {
		$slug = (string) ( $data['slug'] ?? '' );

		// Pre-load the wishlist so the course-card partial doesn't query
		// usermeta on every card.
		$lrn_wishlist = \Learnomy\wishlist_ids();

		$category = $slug ? Category::find_by_slug( $slug ) : null;

		// Unknown slug: signal a real 404 to the template loader. `not_found` is
		// honoured by Template_Loader::render() BEFORE get_header() runs, so the
		// HTTP status is set to 404 while headers can still be sent — the
		// template's own status_header(404) ran too late (after get_header).
		if ( ! $category ) {
			return array(
				'not_found'     => true,
				'category'      => null,
				'lrn_wishlist'  => $lrn_wishlist,
				'courses'       => array(),
				'subcategories' => array(),
				'page'          => 1,
				'total_pages'   => 1,
				'total_count'   => 0,
			);
		}

		// Page from the /courses/category/:slug/page/N/ rewrite (get_query_var
		// also covers a legacy ?lrn_page= query string).
		$page = \Learnomy\current_page();

		// Same page size + override hook as the main catalog so the two
		// archives stay visually consistent.
		$settings = get_option( 'learnomy_settings', array() );
		$per_page = isset( $settings['catalog_per_page'] ) ? (int) $settings['catalog_per_page'] : 0;
		$per_page = $per_page > 0 ? $per_page : 12;
		/** This filter is documented in includes/services/view-data/class-catalog-view-data.php */
		$per_page = max( 1, min( 60, (int) apply_filters( 'learnomy_courses_per_page', $per_page ) ) );

		$category_id = (int) $category->id;
		$total_count = Course::count_published_by_category( $category_id );
		$total_pages = (int) ceil( $total_count / $per_page );

		// Same rule as the catalog: a page past the end is a 404, not an empty
		// category. Page 1 keeps its 200 + empty state.
		if ( $page > 1 && $page > $total_pages ) {
			return array( 'not_found' => true );
		}

		$offset = ( $page - 1 ) * $per_page;

		// Same format badge the main catalog shows, so category archives match.
		$courses = Course::attach_content_type_summary(
			Course::get_published_by_category_page( $category_id, $per_page, $offset )
		);

		// Same enrolled-state supply as the catalog (one query per page, none for
		// anonymous visitors) — an enrolled student browsing a category must see
		// Continue / Review here too, not the price.
		$courses = Course::attach_viewer_enrollment( $courses );
		// Paired deliberately: claimable means covered by the member's plan AND
		// not already enrolled, so it reads the row the line above stamped.
		$courses = Course::attach_viewer_claimable( $courses );

		return array(
			'category'      => $category,
			'lrn_wishlist'  => $lrn_wishlist,
			'courses'       => $courses,
			'subcategories' => Category::list_children( $category_id ),
			'page'          => $page,
			'per_page'      => $per_page,
			'total_count'   => $total_count,
			'total_pages'   => max( 1, $total_pages ),
		);
	}
}
