<?php
/**
 * Membership plan — defines what courses a tier unlocks and at what price.
 *
 * @package Learnomy
 */

namespace Learnomy\Models;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use function Learnomy\now;
use function Learnomy\table;

class MembershipPlan extends Model {

	/**
	 * KEEP. Deleting a space does not delete the plans that sell seats in it
	 * (Space_Service::delete() does not touch this table), and silently
	 * deleting a priced plan would remove a product owners still bill against.
	 *
	 * @var array<string, string|array{column:string,on_delete:string}>
	 */
	protected static array $references = array(
		'space' => array(
			'column'    => 'space_id',
			'on_delete' => \Learnomy\DB\Relations::KEEP,
		),
	);


	/**
	 * Default cap on free plans for the base plugin.
	 * Pro removes the cap via the `learnomy_membership_free_plan_limit` filter.
	 */
	public const FREE_PLAN_LIMIT = 3;

	protected static function table_name(): string {
		return 'membership_plans';
	}

	/**
	 * Insert a plan after enforcing the free-plan cap.
	 *
	 * @return int|WP_Error Plan ID on success, WP_Error when the free-plan cap is exceeded.
	 */
	public static function create( array $plan ): int|WP_Error {
		$limit_error = static::enforce_free_limit( $plan );
		if ( is_wp_error( $limit_error ) ) {
			return $limit_error;
		}

		return static::insert( array_merge( [ 'created_at' => now() ], $plan ) );
	}

	/** A plan is "free" when its price is zero. */
	public static function is_free( array|object $plan ): bool {
		$price = is_array( $plan ) ? ( $plan['price'] ?? 0 ) : ( $plan->price ?? 0 );
		return 0.0 === (float) $price;
	}

	/** Count currently-stored free plans (price = 0). */
	public static function count_free( ?int $exclude_id = null ): int {
		$sql  = 'SELECT COUNT(*) FROM ' . static::table() . ' WHERE price = 0';
		$args = [];

		if ( $exclude_id ) {
			$sql   .= ' AND id != %d';
			$args[] = $exclude_id;
		}

		$query = $args ? static::db()->prepare( $sql, $args ) : $sql;
		return (int) static::db()->get_var( $query );
	}

	/**
	 * Block creation/update of a free plan once the cap is hit.
	 *
	 * @param array    $plan       Incoming plan data.
	 * @param int|null $current_id Plan ID when updating (to exclude from count).
	 */
	public static function enforce_free_limit( array $plan, ?int $current_id = null ): ?WP_Error {
		if ( ! static::is_free( $plan ) ) {
			return null;
		}

		/**
		 * Filter the free-plan cap. Pro sets this to PHP_INT_MAX.
		 *
		 * @param int $limit Default: 3.
		 */
		$limit = (int) apply_filters( 'learnomy_membership_free_plan_limit', self::FREE_PLAN_LIMIT );

		if ( static::count_free( $current_id ) >= $limit ) {
			return new WP_Error(
				'learnomy_free_plan_limit',
				sprintf(
					/* translators: %d: maximum number of free plans allowed. */
					__( 'You can have at most %d free membership plans. Upgrade to Pro for unlimited free plans.', 'learnomy' ),
					$limit
				),
				[ 'status' => 422 ]
			);
		}

		return null;
	}

	public static function find_by_slug( string $slug ): ?object {
		return static::db()->get_row(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE slug = %s LIMIT 1',
				$slug
			)
		) ?: null;
	}

	/**
	 * Paginated plan list with an optional title search pushed into the WHERE
	 * clause so pagination totals stay correct across pages.
	 *
	 * @param array  $where   Key-value equality conditions (e.g. ['status'=>'active']).
	 * @param string $search  Optional title substring to filter by (LIKE '%…%').
	 * @param string $orderby Column name to sort by.
	 * @param string $order   ASC or DESC.
	 * @param int    $limit   Rows per page.
	 * @param int    $offset  Row offset.
	 * @return object[]
	 */
	public static function search(
		array $where,
		string $search,
		string $orderby = 'sort_order',
		string $order = 'ASC',
		int $limit = 20,
		int $offset = 0
	): array {
		$db         = static::db();
		$table      = static::table();
		$conditions = array();
		$args       = array();

		foreach ( $where as $col => $val ) {
			$conditions[] = $db->prepare( "`{$col}` = %s", $val ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( '' !== $search ) {
			$conditions[] = $db->prepare( 'title LIKE %s', '%' . $db->esc_like( $search ) . '%' );
		}

		$where_sql = $conditions ? ( 'WHERE ' . implode( ' AND ', $conditions ) ) : '';
		$order_sql = 'ASC' === strtoupper( $order ) ? 'ASC' : 'DESC';
		$sql       = $db->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$table} {$where_sql} ORDER BY `{$orderby}` {$order_sql} LIMIT %d OFFSET %d",
			$limit,
			$offset
		);
		return (array) $db->get_results( $sql );
	}

	/**
	 * Total plan count matching the same conditions as search() — ensures the
	 * pagination math uses the filtered total, not the unfiltered table count.
	 *
	 * @param array  $where  Key-value equality conditions.
	 * @param string $search Optional title substring filter.
	 * @return int
	 */
	public static function count_search( array $where, string $search ): int {
		$db         = static::db();
		$table      = static::table();
		$conditions = array();

		foreach ( $where as $col => $val ) {
			$conditions[] = $db->prepare( "`{$col}` = %s", $val ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( '' !== $search ) {
			$conditions[] = $db->prepare( 'title LIKE %s', '%' . $db->esc_like( $search ) . '%' );
		}

		$where_sql = $conditions ? ( 'WHERE ' . implode( ' AND ', $conditions ) ) : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $db->get_var( "SELECT COUNT(*) FROM {$table} {$where_sql}" );
	}

	/**
	 * THE price resolver — what this plan costs for a given billing cycle.
	 *
	 * A plan carries up to three price columns: the flat `price` (with the
	 * plan's own `billing_interval`), plus optional `price_monthly` /
	 * `price_yearly` for the dual-cycle toggle on the pricing page. Four
	 * surfaces used to answer "what does this cost?" independently — the
	 * pricing card, the checkout view-data, the checkout JS payload, and
	 * `Checkout_Controller::process_subscribe()` — and three of them read
	 * `price ?? price_monthly`, which is why a buyer could toggle Yearly,
	 * be shown the yearly figure on the card, and be charged the monthly one
	 * (Basecamp 10143220483). Every one of those surfaces now calls this.
	 *
	 * The requested cycle is honoured only when the plan actually offers it.
	 * A cycle the plan does not sell falls back to the plan's own interval and
	 * its own price — never to the other cycle's amount, which would be the
	 * same mis-charge in the other direction.
	 *
	 * @param object $plan  Plan row.
	 * @param string $cycle Requested cycle ('monthly'|'yearly'|''). Empty means
	 *                      "whatever the plan itself bills at".
	 * @return array{amount:float,cycle:string,interval_count:int,offers_dual:bool}
	 */
	public static function price_for_cycle( object $plan, string $cycle = '' ): array {
		$monthly = (float) ( $plan->price_monthly ?? 0 );
		$yearly  = (float) ( $plan->price_yearly ?? 0 );
		$flat    = (float) ( $plan->price ?? 0 );
		$own     = \Learnomy\Services\Subscription_Service::normalize_interval(
			(string) ( $plan->billing_interval ?? 'monthly' )
		);
		$wanted  = \Learnomy\Services\Subscription_Service::normalize_interval( $cycle );

		$offers_dual = ( $monthly > 0 && $yearly > 0 );

		if ( 'yearly' === $wanted && $yearly > 0 ) {
			return array(
				'amount'         => $yearly,
				'cycle'          => 'yearly',
				'interval_count' => 1,
				'offers_dual'    => $offers_dual,
			);
		}

		if ( 'monthly' === $wanted && $monthly > 0 ) {
			return array(
				'amount'         => $monthly,
				'cycle'          => 'monthly',
				'interval_count' => 1,
				'offers_dual'    => $offers_dual,
			);
		}

		// No explicit (or no available) cycle: the plan's own terms. The flat
		// `price` column is authoritative; `price_monthly` only stands in when
		// the owner filled the dual fields and left `price` at zero.
		return array(
			'amount'         => $flat > 0 ? $flat : $monthly,
			'cycle'          => $own,
			'interval_count' => max( 1, (int) ( $plan->interval_count ?? 1 ) ),
			'offers_dual'    => $offers_dual,
		);
	}

	/** Plans ordered by sort_order for display in pricing tables. */
	public static function get_active(): array {
		return static::db()->get_results(
			'SELECT * FROM ' . static::table() . " WHERE status = 'active' ORDER BY sort_order ASC"
		);
	}

	/**
	 * Whether a plan should be offered for sale anywhere — the public pricing
	 * page, the gift-purchase form, and plan-switch options.
	 *
	 * A plan that grants NO courses (no bundled courses AND no course_limit)
	 * sells access to nothing, so it is hidden from the storefront (it stays in
	 * the Memberships admin so the owner can finish configuring it). This is the
	 * single reader of that "T2" rule so the pricing page, gift form, and switch
	 * options never drift — the gift form used to default to a hidden empty plan.
	 *
	 * @param object   $plan          Plan row.
	 * @param int|null $bundled_count Pre-resolved bundled-course count, when the
	 *                                caller already has it (avoids a per-plan query).
	 */
	public static function is_sellable( object $plan, ?int $bundled_count = null ): bool {
		if ( (int) ( $plan->course_limit ?? 0 ) > 0 ) {
			return true;
		}
		if ( null === $bundled_count ) {
			$bundled_count = isset( $plan->bundled_course_count )
				? (int) $plan->bundled_course_count
				: count( \Learnomy\Models\MembershipPlanCourse::get_courses_for_plan( (int) ( $plan->id ?? 0 ) ) );
		}
		return $bundled_count > 0;
	}

	/** Returns courses belonging to this plan via the junction table. */
	public static function get_courses( int $plan_id ): array {
		$courses_table = table( 'courses' );
		$junction      = table( 'membership_plan_courses' );

		return static::db()->get_results(
			static::db()->prepare(
				"SELECT c.* FROM {$courses_table} c
				INNER JOIN {$junction} pc ON pc.course_id = c.id
				WHERE pc.plan_id = %d AND c.status = 'published'
				ORDER BY c.title ASC",
				$plan_id
			)
		);
	}

	/**
	 * The first N published courses in a plan, plus the real total.
	 *
	 * Every surface that shows "what this plan gets you" needs the same two
	 * numbers: a short preview list to render, and the true count so the UI can
	 * say "+N more" instead of implying the plan holds only what fits on screen.
	 * Plans in the wild bundle sixty courses, so no surface may load all of them
	 * to display eight.
	 *
	 * Ordered by the plan's own junction order, not by title, so the course the
	 * owner listed first is the one a new member is offered first.
	 *
	 * @param int $plan_id Plan id.
	 * @param int $limit   Maximum rows to return in `courses`.
	 * @return array{courses: array<int, object>, total: int}
	 */
	public static function preview_courses( int $plan_id, int $limit = 12 ): array {
		$empty = array(
			'courses' => array(),
			'total'   => 0,
		);

		if ( $plan_id <= 0 ) {
			return $empty;
		}

		$links = MembershipPlanCourse::get_courses_for_plan( $plan_id );
		$ids   = array_values( array_filter( array_map( static fn( $link ) => (int) $link->course_id, $links ) ) );
		if ( empty( $ids ) ) {
			return $empty;
		}

		// Batch-fetch every PUBLISHED course in one query (no per-course
		// Course::find() N+1). Resolve across the WHOLE set before slicing: a
		// plan's junction can point at deleted or draft courses (orphaned rows
		// from a reseed), and slicing first could leave the preview empty even
		// though the plan really does bundle courses.
		$rows  = Course::find_published_in( $ids );
		$by_id = array();
		foreach ( $rows as $course ) {
			$by_id[ (int) $course->id ] = $course;
		}

		$published = array();
		foreach ( $ids as $course_id ) {
			if ( isset( $by_id[ $course_id ] ) ) {
				$published[] = $by_id[ $course_id ];
			}
		}

		return array(
			'courses' => array_slice( $published, 0, max( 1, $limit ) ),
			'total'   => count( $published ),
		);
	}
}
