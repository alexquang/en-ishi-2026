<?php
/**
 * Instructor applications — submission and admin review workflow.
 *
 * @package Learnomy
 */

namespace Learnomy\Models;

defined( 'ABSPATH' ) || exit;

use function Learnomy\now;

class InstructorApplication extends Model {

	protected static function table_name(): string {
		return 'instructor_applications';
	}

	/**
	 * Create a new instructor application from an associative array.
	 *
	 * This is the array-based entry point consumed by Auth_Controller during
	 * self-registration (`POST /learnomy/v1/auth/register` with role=instructor).
	 * It sanitizes each field, injects `created_at`, and delegates to
	 * `static::insert()` so cache invalidation happens through the base class.
	 *
	 * Accepted keys: user_id (int), bio (string), expertise (string),
	 * teaching_experience (string), course_idea (string), status (string).
	 * Any other keys are ignored.
	 *
	 * @param array<string,mixed> $data Field map from the controller.
	 * @return int Inserted row ID, or 0 on failure.
	 */
	public static function create( array $data ): int {
		return static::insert(
			[
				'user_id'             => isset( $data['user_id'] ) ? absint( $data['user_id'] ) : 0,
				'bio'                 => sanitize_textarea_field( $data['bio'] ?? '' ),
				'expertise'           => sanitize_textarea_field( $data['expertise'] ?? '' ),
				'teaching_experience' => sanitize_textarea_field( $data['teaching_experience'] ?? '' ),
				'course_idea'         => sanitize_textarea_field( $data['course_idea'] ?? '' ),
				'status'              => sanitize_key( $data['status'] ?? 'pending' ),
				'created_at'          => now(),
			]
		);
	}

	/**
	 * Submits a new instructor application with individual fields as defined in
	 * the schema (no JSON blob — each column is stored separately).
	 *
	 * @param int    $user_id
	 * @param string $bio
	 * @param string $expertise
	 * @param string $teaching_experience
	 * @param string $course_idea
	 * @param string $payout_method
	 * @param string $payout_details_encrypted  Pre-encrypted payout details.
	 * @return int  Inserted row ID.
	 */
	public static function submit(
		int $user_id,
		string $bio = '',
		string $expertise = '',
		string $teaching_experience = '',
		string $course_idea = '',
		string $payout_method = '',
		string $payout_details_encrypted = ''
	): int {
		return static::insert(
			[
				'user_id'                  => $user_id,
				'bio'                      => $bio,
				'expertise'                => $expertise,
				'teaching_experience'      => $teaching_experience,
				'course_idea'              => $course_idea,
				'payout_method'            => $payout_method,
				'payout_details_encrypted' => $payout_details_encrypted,
				'status'                   => 'pending',
				'created_at'               => now(),
			]
		);
	}

	public static function get_pending(): array {
		return static::db()->get_results(
			'SELECT * FROM ' . static::table() . " WHERE status = 'pending' ORDER BY created_at ASC"
		);
	}

	public static function approve( int $id, int $reviewed_by ): bool {
		return static::update(
			$id,
			[
				'status'      => 'approved',
				'reviewed_by' => $reviewed_by,
				'reviewed_at' => now(),
			]
		);
	}

	public static function reject( int $id, int $reviewed_by, string $reason ): bool {
		return static::update(
			$id,
			[
				'status'           => 'rejected',
				'reviewed_by'      => $reviewed_by,
				'reviewed_at'      => now(),
				'rejection_reason' => $reason,
			]
		);
	}

	public static function get_for_user( int $user_id ): array {
		return static::where( [ 'user_id' => $user_id ], 'created_at', 'DESC' );
	}

	/** Newest application for a user, or null if they have none. */
	public static function find_latest_for_user( int $user_id ): ?object {
		$rows = static::where( [ 'user_id' => $user_id ], 'created_at', 'DESC', 1, 0 );
		return $rows ? $rows[0] : null;
	}
}
