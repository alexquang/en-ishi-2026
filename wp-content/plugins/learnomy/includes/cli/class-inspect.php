<?php
/**
 * `wp learnomy inspect …` — dump every row related to a given entity.
 *
 * This is the "read-only answer to what is actually saved right now?" command.
 * It deliberately goes through the model layer so the inspect output reflects
 * exactly what the rest of the plugin sees. No raw SQL except for the
 * table-row-count snapshot in `inspect db`, which lives here rather than in
 * `includes/db/` because it is strictly a reporting helper.
 *
 * @package Learnomy
 */

namespace Learnomy\CLI;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Certificate;
use Learnomy\Models\CertificateTemplate;
use Learnomy\Models\Commission;
use Learnomy\Models\CommissionRule;
use Learnomy\Models\Course;
use Learnomy\Models\CourseInstructor;
use Learnomy\Models\EngagementMonthly;
use Learnomy\Models\Enrollment;
use Learnomy\Models\Lesson;
use Learnomy\Models\MembershipPlan;
use Learnomy\Models\MembershipPlanCourse;
use Learnomy\Models\Progress;
use Learnomy\Models\Question;
use Learnomy\Models\QuestionResponse;
use Learnomy\Models\QuizAttempt;
use Learnomy\Models\QuizSlot;
use Learnomy\Models\Review;
use Learnomy\Models\Section;
use Learnomy\Models\Subscription;
use Learnomy\Models\Transaction;
use Learnomy\DB\Schema;

class Inspect {

	/**
	 * Inspect a course and every row tied to it.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The course ID to inspect.
	 *
	 * [--format=<format>]
	 * : Output format. Accepts: table, json, yaml. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp learnomy inspect course 42
	 *     wp learnomy inspect course 42 --format=json
	 *
	 * @subcommand course
	 */
	public function course( array $args, array $assoc_args ): void {
		$id     = absint( $args[0] ?? 0 );
		$course = $id ? Course::find( $id ) : null;

		if ( ! $course ) {
			\WP_CLI::error(
				/* translators: %d: course ID */
				sprintf( __( 'Course %d not found.', 'learnomy' ), $id )
			);
		}

		$instructors = array_map(
			static function ( $row ) {
				$user = get_userdata( (int) $row->user_id );
				return [
					'user_id'    => (int) $row->user_id,
					'name'       => $user ? $user->display_name : '(deleted)',
					'share_pct'  => (float) $row->share_pct,
					'is_primary' => (bool) $row->is_primary,
				];
			},
			CourseInstructor::get_for_course( $id )
		);

		$sections = array_map(
			static fn( $s ) => [
				'id'         => (int) $s->id,
				'title'      => $s->title,
				'sort_order' => (int) $s->sort_order,
			],
			Section::get_for_course( $id )
		);

		$lessons = array_map(
			static fn( $l ) => [
				'id'         => (int) $l->id,
				'title'      => $l->title,
				'section_id' => (int) $l->section_id,
				'status'     => $l->status,
				'sort_order' => (int) $l->sort_order,
			],
			Lesson::get_for_course( $id, 0, 0, null ) // Diagnostic dump: every status (each row reports its own).
		);

		$enrollments = Enrollment::get_for_course( $id );
		$review_agg  = Review::get_aggregate( $id );

		$payload = [
			'course'               => $this->object_to_array( $course ),
			'instructors'          => $instructors,
			'sections'             => $sections,
			'lessons'              => $lessons,
			'enrollment_count'     => count( $enrollments ),
			'enrollment_by_source' => $this->group_count( $enrollments, 'source' ),
			'denormalized'         => [
				'student_count' => (int) $course->student_count,
				'avg_rating'    => (float) $course->avg_rating,
				'review_count'  => (int) $course->review_count,
			],
			'reviews_aggregate'    => $this->object_to_array( $review_agg ),
			'commission_rule'      => $this->object_to_array( CommissionRule::get_for_entity( 'course', $id ) ),
		];

		$this->print_payload( $payload, $assoc_args );
	}

	/**
	 * Inspect every row tied to a WordPress user from Learnomy's perspective.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The WP user ID to inspect.
	 *
	 * [--format=<format>]
	 * : Output format. Accepts: table, json, yaml. Default: table.
	 *
	 * @subcommand user
	 */
	public function user( array $args, array $assoc_args ): void {
		$user_id = absint( $args[0] ?? 0 );
		$user    = $user_id ? get_userdata( $user_id ) : false;

		if ( ! $user instanceof \WP_User ) {
			// WP_CLI::error() exits internally, but PHPStan's stubs for the
			// WP-CLI package don't declare it as `@return never`, so we add a
			// `return` after the call to narrow $user to WP_User below.
			\WP_CLI::error(
				/* translators: %d: user ID */
				sprintf( __( 'User %d not found.', 'learnomy' ), $user_id )
			);
			return;
		}

		$payload = [
			'user'           => [
				'id'           => $user->ID,
				'login'        => $user->user_login,
				'email'        => $user->user_email,
				'display_name' => $user->display_name,
				'roles'        => $user->roles,
			],
			'enrollments'    => array_map(
				fn( $e ) => $this->object_to_array( $e ),
				Enrollment::get_for_user( $user_id )
			),
			'quiz_attempts'  => array_map(
				fn( $a ) => $this->object_to_array( $a ),
				QuizAttempt::get_for_user( $user_id )
			),
			'certificates'   => array_map(
				fn( $c ) => $this->object_to_array( $c ),
				Certificate::get_for_user( $user_id )
			),
			'transactions'   => array_map(
				fn( $t ) => $this->object_to_array( $t ),
				Transaction::get_for_user( $user_id )
			),
			'subscriptions'  => array_map(
				fn( $s ) => $this->object_to_array( $s ),
				Subscription::get_active_for_user( $user_id )
			),
			'commissions'    => array_map(
				fn( $c ) => $this->object_to_array( $c ),
				Commission::get_for_instructor( $user_id )
			),
			'pending_payout' => Commission::sum_unpaid_for_instructor( $user_id ),
		];

		$this->print_payload( $payload, $assoc_args );
	}

	/**
	 * Inspect an enrollment row and everything derived from it.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Enrollment ID.
	 *
	 * [--format=<format>]
	 * : Output format. Accepts: table, json, yaml. Default: table.
	 *
	 * @subcommand enrollment
	 */
	public function enrollment( array $args, array $assoc_args ): void {
		$id         = absint( $args[0] ?? 0 );
		$enrollment = $id ? Enrollment::find( $id ) : null;

		if ( ! $enrollment ) {
			\WP_CLI::error( __( 'Enrollment not found.', 'learnomy' ) );
		}

		$course = Course::find( (int) $enrollment->course_id );
		$user   = get_userdata( (int) $enrollment->user_id );

		$progress_rows = Progress::get_all_for_course( (int) $enrollment->user_id, (int) $enrollment->course_id );
		$completed     = array_filter( $progress_rows, static fn( $p ) => 100 === (int) ( $p->progress_pct ?? 0 ) || ! empty( $p->completed_at ) );
		$lessons_total = count( Lesson::get_for_course( (int) $enrollment->course_id ) );

		$payload = [
			'enrollment'    => $this->object_to_array( $enrollment ),
			'course'        => $course ? [
				'id'     => $course->id,
				'title'  => $course->title,
				'status' => $course->status,
			] : null,
			'user'          => $user ? [
				'id'           => $user->ID,
				'display_name' => $user->display_name,
			] : null,
			'progress'      => [
				'rows_tracked'      => count( $progress_rows ),
				'completed_items'   => count( $completed ),
				'lessons_in_course' => $lessons_total,
			],
			'quiz_attempts' => count(
				array_filter(
					QuizAttempt::get_for_user( (int) $enrollment->user_id ),
					static fn( $a ) => (int) $a->enrollment_id === (int) $enrollment->id
				)
			),
			'certificate'   => $this->first_certificate_for( (int) $enrollment->user_id, (int) $enrollment->course_id ),
		];

		$this->print_payload( $payload, $assoc_args );
	}

	/**
	 * Inspect a quiz attempt — the row itself, every response, the slot marks
	 * applied, the computed score, and the official score under the quiz's
	 * grading method.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Attempt ID.
	 *
	 * [--format=<format>]
	 * : Output format. Accepts: table, json, yaml. Default: table.
	 *
	 * @subcommand attempt
	 */
	public function attempt( array $args, array $assoc_args ): void {
		$id      = absint( $args[0] ?? 0 );
		$attempt = $id ? QuizAttempt::find( $id ) : null;

		if ( ! $attempt ) {
			\WP_CLI::error( __( 'Attempt not found.', 'learnomy' ) );
		}

		$slots     = QuizSlot::get_for_quiz( (int) $attempt->quiz_id );
		$marks_map = [];
		foreach ( $slots as $slot ) {
			$marks_map[ (int) $slot->question_id ] = (float) $slot->marks;
		}

		$responses = array_map(
			function ( $r ) use ( $marks_map ) {
				$question                    = Question::find( (int) $r->question_id );
				$row                         = $this->object_to_array( $r );
				$row['question_type']        = $question->type ?? null;
				$row['question_current_ver'] = $question->version ?? null;
				$row['slot_marks']           = $marks_map[ (int) $r->question_id ] ?? null;
				return $row;
			},
			QuestionResponse::get_for_attempt( $id )
		);

		$payload = [
			'attempt'        => $this->object_to_array( $attempt ),
			'slot_marks'     => $marks_map,
			'responses'      => $responses,
			'ungraded_count' => QuestionResponse::count_ungraded( $id ),
			'official_score' => ( new \Learnomy\Services\Quiz_Service() )->get_official_score( (int) $attempt->user_id, (int) $attempt->quiz_id ),
		];

		$this->print_payload( $payload, $assoc_args );
	}

	/**
	 * Inspect a transaction and the commission rows it produced.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Transaction ID.
	 *
	 * [--format=<format>]
	 * : Output format. Accepts: table, json, yaml. Default: table.
	 *
	 * @subcommand transaction
	 */
	public function transaction( array $args, array $assoc_args ): void {
		$id          = absint( $args[0] ?? 0 );
		$transaction = $id ? Transaction::find( $id ) : null;

		if ( ! $transaction ) {
			\WP_CLI::error( __( 'Transaction not found.', 'learnomy' ) );
		}

		$commissions     = array_filter(
			Commission::get_for_instructor( 0 ), // platform rows
			static fn( $c ) => (int) $c->transaction_id === $id
		);
		$all_commissions = $this->get_commissions_for_transaction( $id );

		$platform_sum   = 0.0;
		$instructor_sum = 0.0;
		foreach ( $all_commissions as $c ) {
			if ( 'platform' === $c->recipient_type ) {
				$platform_sum += (float) $c->amount;
			} else {
				$instructor_sum += (float) $c->amount;
			}
		}

		$payload = [
			'transaction'     => $this->object_to_array( $transaction ),
			'commissions'     => array_map( fn( $c ) => $this->object_to_array( $c ), $all_commissions ),
			'split_totals'    => [
				'platform_sum'   => round( $platform_sum, 2 ),
				'instructor_sum' => round( $instructor_sum, 2 ),
				'total'          => round( $platform_sum + $instructor_sum, 2 ),
				'transaction'    => (float) $transaction->amount,
				'matches_amount' => round( $platform_sum + $instructor_sum, 2 ) === round( (float) $transaction->amount, 2 ),
			],
			'commission_rule' => $this->object_to_array( CommissionRule::get_for_entity( 'course', (int) $transaction->course_id ) ),
		];

		$this->print_payload( $payload, $assoc_args );
	}

	/**
	 * Inspect a membership subscription — the plan, the enrollments it
	 * created, and any engagement rows for the current period.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Subscription ID.
	 *
	 * [--format=<format>]
	 * : Output format. Accepts: table, json, yaml. Default: table.
	 *
	 * @subcommand subscription
	 */
	public function subscription( array $args, array $assoc_args ): void {
		$id           = absint( $args[0] ?? 0 );
		$subscription = $id ? Subscription::find( $id ) : null;

		if ( ! $subscription ) {
			\WP_CLI::error( __( 'Subscription not found.', 'learnomy' ) );
		}

		$plan             = MembershipPlan::find( (int) $subscription->plan_id );
		$plan_courses     = MembershipPlanCourse::get_courses_for_plan( (int) $subscription->plan_id );
		$user_id          = (int) $subscription->user_id;
		$plan_id          = (int) $subscription->plan_id;
		$user_enrollments = array_filter(
			Enrollment::get_for_user( $user_id ),
			static fn( $e ) => 'membership' === $e->source && (int) ( $e->plan_id ?? 0 ) === $plan_id
		);

		$period     = gmdate( 'Y-m', strtotime( $subscription->current_period_start ?? 'now' ) );
		$engagement = array_filter(
			EngagementMonthly::get_for_period( $period ),
			static fn( $r ) => (int) $r->plan_id === $plan_id
		);

		$payload = [
			'subscription'               => $this->object_to_array( $subscription ),
			'plan'                       => $this->object_to_array( $plan ),
			'plan_course_ids'            => array_map( static fn( $r ) => (int) $r->course_id, $plan_courses ),
			'enrollments_from_this_plan' => array_map( fn( $e ) => $this->object_to_array( $e ), $user_enrollments ),
			'engagement_rows'            => array_values( array_map( fn( $r ) => $this->object_to_array( $r ), $engagement ) ),
		];

		$this->print_payload( $payload, $assoc_args );
	}

	/**
	 * Inspect a certificate. Verifies the stored Ed25519 signature against
	 * the public key that was generated the first time a cert was issued.
	 *
	 * ## OPTIONS
	 *
	 * <uuid>
	 * : Certificate UUID.
	 *
	 * [--format=<format>]
	 * : Output format. Accepts: table, json, yaml. Default: table.
	 *
	 * @subcommand cert
	 */
	public function cert( array $args, array $assoc_args ): void {
		$uuid        = sanitize_text_field( $args[0] ?? '' );
		$certificate = $uuid ? Certificate::find_by_uuid( $uuid ) : null;

		if ( ! $certificate ) {
			\WP_CLI::error( __( 'Certificate not found.', 'learnomy' ) );
		}

		$template = $certificate->template_id ? CertificateTemplate::find( (int) $certificate->template_id ) : null;

		$payload = [
			'certificate'        => $this->object_to_array( $certificate ),
			'template'           => $this->object_to_array( $template ),
			'signature_valid'    => $this->verify_signature( $certificate ),
			'is_revoked'         => 'revoked' === $certificate->status,
			'verification_count' => (int) $certificate->verification_count,
		];

		$this->print_payload( $payload, $assoc_args );
	}

	/**
	 * Print a row count for every `lrn_*` table. Great as a before/after
	 * snapshot when debugging a flow that touches many tables.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. Accepts: table, json, csv, yaml. Default: table.
	 *
	 * @subcommand db
	 */
	public function db( array $args, array $assoc_args ): void {
		global $wpdb;

		// F-1: kept here because this is a schema-introspection reporting helper
		// that iterates every table returned by Schema::get_table_names() and
		// issues a COUNT(*) per table. There is no Model method that maps to
		// "count rows in every table" — adding one would couple the Model layer
		// to the schema inventory, which is DB\Schema's concern. The $full
		// identifier is composed from $wpdb->prefix + a whitelisted table short
		// name returned by Schema, so the interpolation is safe.
		$rows = [];
		foreach ( Schema::get_table_names() as $short ) {
			$full = $wpdb->prefix . $short;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$full}`" );
			$rows[] = [
				'table' => $short,
				'rows'  => $count,
			];
		}

		$format = $this->resolve_format( $assoc_args, [ 'table', 'json', 'yaml', 'csv' ] );
		\WP_CLI\Utils\format_items( $format, $rows, [ 'table', 'rows' ] );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Serialize $payload using the requested format. Defaults to a
	 * readable YAML-ish dump so the table/json contract stays predictable.
	 */
	private function print_payload( array $payload, array $assoc_args ): void {
		$format = $this->resolve_format( $assoc_args, [ 'table', 'json', 'yaml' ] );

		if ( 'json' === $format ) {
			\WP_CLI::line( wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}
		if ( 'yaml' === $format ) {
			\WP_CLI::line( $this->yamlish( $payload, 0 ) );
			return;
		}

		// table: print top-level keys as rows. Complex values are JSON-encoded
		// so the user always gets a single scannable view.
		$rows = [];
		foreach ( $payload as $key => $value ) {
			$rows[] = [
				'key'   => $key,
				'value' => is_scalar( $value ) || null === $value ? (string) $value : $this->one_line_json( $value ),
			];
		}
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'key', 'value' ] );
	}

	private function resolve_format( array $assoc_args, array $allowed ): string {
		$requested = sanitize_key( $assoc_args['format'] ?? 'table' );
		return in_array( $requested, $allowed, true ) ? $requested : 'table';
	}

	private function yamlish( mixed $value, int $indent ): string {
		$pad = str_repeat( '  ', $indent );
		if ( is_array( $value ) ) {
			if ( [] === $value ) {
				return $pad . '[]';
			}
			$out     = [];
			$is_list = array_is_list( $value );
			foreach ( $value as $k => $v ) {
				$prefix = $is_list ? $pad . '- ' : $pad . $k . ': ';
				if ( is_array( $v ) || is_object( $v ) ) {
					$out[] = rtrim( $prefix ) . "\n" . $this->yamlish( (array) $v, $indent + 1 );
				} else {
					$out[] = $prefix . $this->scalar( $v );
				}
			}
			return implode( "\n", $out );
		}
		return $pad . $this->scalar( $value );
	}

	private function scalar( mixed $v ): string {
		if ( is_bool( $v ) ) {
			return $v ? 'true' : 'false';
		}
		if ( null === $v ) {
			return 'null';
		}
		return (string) $v;
	}

	private function one_line_json( mixed $value ): string {
		$encoded = wp_json_encode( $value, JSON_UNESCAPED_SLASHES );
		if ( false === $encoded ) {
			return gettype( $value );
		}
		return strlen( $encoded ) > 160 ? substr( $encoded, 0, 157 ) . '…' : $encoded;
	}

	private function object_to_array( mixed $object ): ?array {
		if ( null === $object ) {
			return null;
		}
		if ( is_array( $object ) ) {
			return $object;
		}
		return (array) $object;
	}

	/**
	 * @param iterable $rows     Rows to group (objects).
	 * @param string   $property Property to group by.
	 */
	private function group_count( iterable $rows, string $property ): array {
		$out = [];
		foreach ( $rows as $row ) {
			$key         = $row->$property ?? 'unknown';
			$out[ $key ] = ( $out[ $key ] ?? 0 ) + 1;
		}
		return $out;
	}

	private function first_certificate_for( int $user_id, int $course_id ): ?array {
		foreach ( Certificate::get_for_user( $user_id ) as $cert ) {
			if ( (int) $cert->course_id === $course_id ) {
				return [
					'uuid'   => $cert->uuid,
					'status' => $cert->status,
				];
			}
		}
		return null;
	}

	private function get_commissions_for_transaction( int $transaction_id ): array {
		return Commission::get_for_transaction( $transaction_id );
	}

	private function verify_signature( object $certificate ): bool {
		if ( empty( $certificate->signature ) ) {
			return false;
		}
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return false;
		}

		$public_b64 = get_option( 'learnomy_cert_public_key', '' );
		if ( ! $public_b64 ) {
			// Kick off key generation by calling the service; it will write the
			// public key option as a side effect. We don't want inspect to
			// actually create keys on a cold site, so if the option is missing
			// we simply report false and let the user call the issue flow.
			return false;
		}

		$public_key = base64_decode( $public_b64, true );
		$signature  = base64_decode( $certificate->signature, true );
		if ( false === $public_key || false === $signature || '' === $public_key || '' === $signature ) {
			return false;
		}

		$data = $certificate->uuid . '|' . (int) $certificate->user_id . '|' . (int) $certificate->course_id;

		try {
			return sodium_crypto_sign_verify_detached( $signature, $data, $public_key );
		} catch ( \SodiumException $e ) {
			return false;
		}
	}
}
