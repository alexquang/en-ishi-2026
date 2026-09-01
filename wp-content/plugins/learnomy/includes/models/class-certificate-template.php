<?php
/**
 * Certificate template — reusable design applied when issuing certificates.
 *
 * @package Learnomy
 */

namespace Learnomy\Models;

defined( 'ABSPATH' ) || exit;

use function Learnomy\now;

class CertificateTemplate extends Model {

	protected const INT_COLUMNS = [
		'expiration_days',
		'author_id',
	];

	/**
	 * Instructor-availability scopes for the curated-library governance.
	 *
	 * ALL      — every instructor may pick it (the curated seeds ship this way).
	 * ADMINS   — private to administrators; hidden from instructor pickers.
	 * SPECIFIC — visible only to the instructor IDs listed in the meta blob.
	 */
	public const AVAILABILITY_ALL      = 'all';
	public const AVAILABILITY_ADMINS   = 'admins';
	public const AVAILABILITY_SPECIFIC = 'specific';

	/**
	 * Library metadata lives in the (historically dead) `triggers_json` column.
	 * It was defined in the schema but read/written nowhere, so we give it its
	 * purpose here instead of adding a column — no migration, no new table.
	 *
	 * Unstamped legacy rows fall back to AVAILABILITY_ALL so nothing disappears
	 * from an instructor picker on upgrade; the designer stamps new templates
	 * explicitly (defaulting to ADMINS). `kind` is a presentational label only —
	 * there is one issue trigger (course completion), so it never changes issuance.
	 */
	protected static function default_meta(): array {
		return [
			'kind'         => 'course_completion',
			'design_key'   => '',
			'source'       => 'custom',
			'availability' => self::AVAILABILITY_ALL,
			'instructors'  => [],
		];
	}

	protected static function table_name(): string {
		return 'certificate_templates';
	}

	public static function create( array $template ): int {
		return static::insert( array_merge( [ 'created_at' => now() ], $template ) );
	}

	/** All active templates ordered by name — the admin (unfiltered) picker. */
	public static function get_active(): array {
		return static::db()->get_results(
			'SELECT * FROM ' . static::table() . " WHERE status = 'active' ORDER BY title ASC"
		);
	}

	/**
	 * Active templates an instructor is allowed to pick — curated + anything the
	 * admin granted them (all-instructors or this instructor specifically).
	 * Admins should call {@see get_active()} directly for the full set.
	 *
	 * Filtered in PHP: the template table holds dozens of rows, not thousands,
	 * so a JSON-in-SQL predicate would buy nothing and cost MySQL 5.6 support.
	 */
	public static function get_for_instructor( int $user_id ): array {
		return array_values(
			array_filter(
				static::get_active(),
				static fn( $tpl ) => static::available_to( $tpl, $user_id )
			)
		);
	}

	/** Decoded meta blob for a template row, merged over the defaults. */
	public static function meta( object $tpl ): array {
		$raw = isset( $tpl->triggers_json ) ? json_decode( (string) $tpl->triggers_json, true ) : null;
		if ( ! is_array( $raw ) ) {
			$raw = [];
		}
		$meta                = array_merge( static::default_meta(), $raw );
		$meta['instructors'] = array_values( array_map( 'intval', (array) $meta['instructors'] ) );

		return $meta;
	}

	/** Encode a (partial) meta array into the storage column value. */
	public static function encode_meta( array $meta ): string {
		return (string) wp_json_encode( array_merge( static::default_meta(), $meta ) );
	}

	public static function availability( object $tpl ): string {
		return (string) static::meta( $tpl )['availability'];
	}

	public static function kind( object $tpl ): string {
		return (string) static::meta( $tpl )['kind'];
	}

	public static function design_key( object $tpl ): string {
		return (string) static::meta( $tpl )['design_key'];
	}

	public static function source( object $tpl ): string {
		return (string) static::meta( $tpl )['source'];
	}

	public static function is_curated( object $tpl ): bool {
		return 'curated' === static::source( $tpl );
	}

	/** Whether a non-admin instructor may pick this template. */
	public static function available_to( object $tpl, int $user_id ): bool {
		$meta = static::meta( $tpl );

		switch ( $meta['availability'] ) {
			case self::AVAILABILITY_ALL:
				return true;
			case self::AVAILABILITY_SPECIFIC:
				return in_array( $user_id, $meta['instructors'], true );
			case self::AVAILABILITY_ADMINS:
			default:
				return false;
		}
	}

	/** First template already seeded for a given curated design key, if any. */
	public static function find_by_design_key( string $design_key ): ?object {
		if ( '' === $design_key ) {
			return null;
		}
		foreach ( static::get_active() as $tpl ) {
			if ( static::design_key( $tpl ) === $design_key ) {
				return $tpl;
			}
		}

		return null;
	}

	/** Human label for a `kind` value — shown in the instructor picker + admin list. */
	public static function kind_label( string $kind ): string {
		$labels = [
			'course_completion' => __( 'Course Completion', 'learnomy' ),
			'quiz_completion'   => __( 'Quiz Completion', 'learnomy' ),
		];

		return $labels[ $kind ] ?? $labels['course_completion'];
	}
}
