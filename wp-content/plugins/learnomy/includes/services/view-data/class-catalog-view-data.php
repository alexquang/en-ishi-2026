<?php
/**
 * View-data resolver for the public course catalog page.
 *
 * Owns the wishlist pre-load, $_GET sanitization (search / category / sort
 * / page), category list, and the Course::search_catalog() result. The
 * template (templates/course-archive.php) consumes the resolver output and
 * renders the markup — no model imports or query reads in the template.
 *
 * The Interactivity API store takes over for subsequent filter changes
 * client-side (without full reloads), but the first paint must reflect
 * the URL params so a shared link loads the same catalog state the store
 * would produce.
 *
 * @package Learnomy
 */

namespace Learnomy\Services\View_Data;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Category;
use Learnomy\Models\Course;

class Catalog_View_Data {

	/**
	 * Resolve catalog page state from $_GET + the model layer.
	 *
	 * @param array $data Route data (unused on the catalog route).
	 * @return array Pre-fetched view data.
	 */
	public static function resolve( array $data ): array {
		// Wishlist pre-load — without this, the course-card partial would
		// query usermeta on every card (N+1 on a 12-card grid).
		$lrn_wishlist = \Learnomy\wishlist_ids();

		// $_GET reads happen here so the template stays presentation-only.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Catalog filters are GET-only navigation; no nonce expected.
		// Page comes from the /courses/page/N/ rewrite (get_query_var), which
		// Covers the pretty /page/N/ path and a legacy ?lrn_page= query string.
		$page = \Learnomy\current_page();
		$page = max( 1, $page );

		// Catalog page size — admin-configurable via the settings option,
		// with a fallback filter for sites that drive the value
		// programmatically. Default 12 keeps the existing 4×3 grid happy on
		// desktop. Bounds: 1 (anything less is meaningless) to 60 (further
		// pagination is a UX improvement, not a performance lever — the
		// catalog query is already paginated server-side).
		$settings            = get_option( 'learnomy_settings', array() );
		$configured_per_page = isset( $settings['catalog_per_page'] ) ? (int) $settings['catalog_per_page'] : 0;
		$per_page            = $configured_per_page > 0 ? $configured_per_page : 12;
		/**
		 * Filter the catalog page size.
		 *
		 * Use this to override the admin setting from code (e.g. shrink the
		 * page size on a category-restricted landing page).
		 *
		 * @param int $per_page Resolved page size from settings or default.
		 */
		$per_page = (int) apply_filters( 'learnomy_courses_per_page', $per_page );
		$per_page = max( 1, min( 60, $per_page ) );

		$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		$sort   = sanitize_key( $_GET['sort'] ?? 'latest' );
		// phpcs:enable

		// One accessor for "which category is this listing about", shared with
		// the <title> builder and the <head> metadata emitters — they used to
		// have no way to ask, so they described the unfiltered catalog over a
		// filtered grid (Basecamp 10134139480). It also sanitises with
		// sanitize_title() rather than sanitize_key(), which silently emptied
		// any category slug that was not plain lowercase ASCII.
		$current_cat = \Learnomy\current_category_slug();

		$categories = Category::list_top_level( 'course' );

		// Pull active facet selections (Catalog Facets framework). Each
		// registered facet declares its own URL param; the framework
		// sanitises values + builds WHERE clauses so neither this resolver
		// nor the template needs to know about specific facets.
		$facets = class_exists( \Learnomy\Catalog\Facets::class )
			? \Learnomy\Catalog\Facets::resolve_active_from_request()
			: array();

		$catalog_args = array(
			'search'   => $search,
			'category' => $current_cat,
			'sort'     => $sort,
			'page'     => $page,
			'per_page' => $per_page,
			'facets'   => $facets,
		);

		$courses = Course::search_catalog( $catalog_args );

		// Tag each card with its dominant lesson format (Video / Audio / Text)
		// so the catalog communicates delivery type at a glance — one grouped
		// query for the page, no per-card lookups.
		$courses = Course::attach_content_type_summary( $courses );

		// Resolve each card's category name + primary-instructor display name
		// and profile URL in two batched reads (Category::find_in +
		// WP_User_Query) so the course-card partial doesn't run a per-card
		// Category::find() + get_userdata() (N+1 on the grid).
		$courses = Course::attach_card_meta( $courses );

		// Resolve the viewer's own enrollment for the whole page in ONE query, so
		// a student already enrolled in a listed course sees Continue / Review
		// instead of its price. Anonymous visitors — most catalog traffic — cost
		// no extra query at all.
		$courses = Course::attach_viewer_enrollment( $courses );
		// Paired deliberately: claimable means covered by the member's plan AND
		// not already enrolled, so it reads the row the line above stamped.
		$courses = Course::attach_viewer_claimable( $courses );

		// Total + pages metadata for the template. Without these, the header
		// shows count($courses) (per-page count) and there is no way for the
		// template to render numbered pagination — both bug F-01.9 and
		// F-01.10 collapse onto this gap.
		$total_count = Course::count_catalog( $catalog_args );
		$total_pages = $per_page > 0 ? (int) ceil( $total_count / $per_page ) : 1;

		// A page past the end is a 404, not an empty catalogue. Serving 200 for
		// /courses/page/99/ makes an unbounded series of thin pages indexable
		// and, worse, tells a visitor the catalogue is empty when it is not.
		// Page 1 stays a 200 with the real empty state — "no courses yet" is a
		// true statement there and a legitimate destination.
		if ( $page > 1 && $page > $total_pages ) {
			return array( 'not_found' => true );
		}

		return array(
			'lrn_wishlist'    => is_array( $lrn_wishlist ) ? $lrn_wishlist : array(),
			'page'            => $page,
			'per_page'        => $per_page,
			'categories'      => $categories,
			'search'          => $search,
			'current_cat'     => $current_cat,
			'sort'            => $sort,
			'courses'         => $courses,
			'total_count'     => $total_count,
			'total_pages'     => $total_pages,
			// Site-wide catalog column count (2-5, default 3) — drives the
			// .lrn-courses-grid--cols-N modifier in results.php.
			'catalog_columns' => max( 2, min( 5, (int) ( $settings['catalog_columns'] ?? 3 ) ) ),
		);
	}
}
