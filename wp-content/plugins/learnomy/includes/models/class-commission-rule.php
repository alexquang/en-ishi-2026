<?php
/**
 * Commission rules — per-instructor or per-course overrides with global fallback.
 *
 * @package Learnomy
 */

namespace Learnomy\Models;

defined( 'ABSPATH' ) || exit;

use function Learnomy\now;

class CommissionRule extends Model {

	protected const INT_COLUMNS = [
		'entity_id',
	];

	protected const FLOAT_COLUMNS = [
		'platform_rate',
	];

	protected static function table_name(): string {
		return 'commission_rules';
	}

	public static function create( array $rule ): int {
		return static::insert( array_merge( [ 'created_at' => now() ], $rule ) );
	}

	/**
	 * Looks up a rule for the given entity (instructor or course), falling back to
	 * the global rule when no specific override exists. Returns null only when no
	 * rule is defined at all — callers should handle that as "use platform default".
	 */
	public static function get_for_entity( string $rule_type, int $entity_id ): ?object {
		return static::get_for_entity_exact( $rule_type, $entity_id ) ?: static::get_global();
	}

	/**
	 * Exact (rule_type, entity_id) match with NO global fallback. Used where the
	 * caller must distinguish a real override from the global default — e.g. the
	 * rate resolver and the create-rule duplicate guard.
	 */
	public static function get_for_entity_exact( string $rule_type, int $entity_id ): ?object {
		return static::db()->get_row(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE rule_type = %s AND entity_id = %d LIMIT 1',
				$rule_type,
				$entity_id
			)
		) ?: null;
	}

	public static function get_global(): ?object {
		return static::db()->get_row(
			'SELECT * FROM ' . static::table() . " WHERE rule_type = 'global' LIMIT 1"
		) ?: null;
	}

	/**
	 * Set (or clear) the per-course platform-commission override. Passing null
	 * removes the course rule so resolution falls back to the instructor rule
	 * and then the global rate (see Commission_Service::resolve_platform_rate).
	 * Upserts on the (rule_type='course', entity_id) unique key.
	 *
	 * @param int        $course_id Course ID.
	 * @param float|null $rate      Platform rate 0–100, or null to inherit.
	 */
	public static function set_course_rate( int $course_id, ?float $rate ): void {
		if ( $course_id <= 0 ) {
			return;
		}
		$existing = static::get_for_entity_exact( 'course', $course_id );
		if ( null === $rate ) {
			if ( $existing ) {
				static::delete( (int) $existing->id );
			}
			return;
		}
		$rate = max( 0.0, min( 100.0, $rate ) );
		if ( $existing ) {
			static::update( (int) $existing->id, array( 'platform_rate' => $rate ) );
		} else {
			static::create(
				array(
					'rule_type'     => 'course',
					'entity_id'     => $course_id,
					'platform_rate' => $rate,
				)
			);
		}
	}
}
