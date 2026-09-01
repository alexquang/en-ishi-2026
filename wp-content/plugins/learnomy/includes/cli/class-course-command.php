<?php
/**
 * WP-CLI nested command group: wp learnomy course <subcommand>.
 *
 * @package Learnomy
 */

namespace Learnomy\CLI;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Course;
use Learnomy\Services\Course_Service;

/**
 * Manage Learnomy courses from the command line.
 *
 * Registered as `wp learnomy course <subcommand>`. WP-CLI's @subcommand
 * annotation only accepts a single token, so a nested command group has to be
 * its own class for the second word to resolve.
 */
class Course_Command {

	/**
	 * List courses.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Filter by status. One of: draft, published, archived.
	 *
	 * [--format=<format>]
	 * : Output format. Accepts: table, csv, json. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp learnomy course list
	 *     wp learnomy course list --status=published --format=json
	 *
	 * @subcommand list
	 */
	public function list_( array $args, array $assoc_args ): void {
		$status = isset( $assoc_args['status'] ) ? sanitize_text_field( $assoc_args['status'] ) : '';
		$format = isset( $assoc_args['format'] ) ? sanitize_text_field( $assoc_args['format'] ) : 'table';

		$where = $status ? [ 'status' => $status ] : [];
		$rows  = Course::where( $where, 'created_at', 'DESC' );

		if ( empty( $rows ) ) {
			\WP_CLI::warning( __( 'No courses found.', 'learnomy' ) );
			return;
		}

		$items = array_map(
			function ( $course ) {
				$instructor  = '';
				$instructors = Course::get_instructors( (int) $course->id );
				if ( ! empty( $instructors ) ) {
						$primary    = array_filter( $instructors, fn( $i ) => ! empty( $i->is_primary ) );
						$uid        = (int) ( $primary ? reset( $primary )->user_id : $instructors[0]->user_id );
						$user       = get_userdata( $uid );
						$instructor = $user ? $user->display_name : $uid;
				}

				return [
					'id'         => $course->id,
					'title'      => $course->title,
					'status'     => $course->status,
					'instructor' => $instructor,
					'students'   => $course->student_count,
					'price'      => $course->price,
					'created_at' => $course->created_at,
				];
			},
			$rows
		);

		\WP_CLI\Utils\format_items( $format, $items, [ 'id', 'title', 'status', 'instructor', 'students', 'price', 'created_at' ] );
	}

	/**
	 * Create a new course.
	 *
	 * ## OPTIONS
	 *
	 * --title=<title>
	 * : Course title.
	 *
	 * --author_id=<id>
	 * : WordPress user ID of the instructor.
	 *
	 * [--status=<status>]
	 * : Initial status. Default: draft.
	 *
	 * [--price=<price>]
	 * : Course price. Default: 0.
	 *
	 * ## EXAMPLES
	 *
	 *     wp learnomy course create --title="Intro to PHP" --author_id=3
	 *     wp learnomy course create --title="Advanced JS" --author_id=5 --status=published --price=49.99
	 *
	 * @subcommand create
	 */
	public function create( array $args, array $assoc_args ): void {
		if ( empty( $assoc_args['title'] ) || empty( $assoc_args['author_id'] ) ) {
			\WP_CLI::error( __( '--title and --author_id are required.', 'learnomy' ) );
		}

		$title     = sanitize_text_field( $assoc_args['title'] );
		$author_id = absint( $assoc_args['author_id'] );
		$status    = sanitize_text_field( $assoc_args['status'] ?? 'draft' );
		$price     = isset( $assoc_args['price'] ) ? (float) $assoc_args['price'] : 0.0;

		$service   = new Course_Service();
		$course_id = $service->create(
			[
				'title'  => $title,
				'status' => $status,
				'price'  => $price,
			],
			$author_id
		);

		if ( ! $course_id ) {
			\WP_CLI::error( __( 'Could not create course. Check that the author ID is valid.', 'learnomy' ) );
		}

		\WP_CLI::success(
			/* translators: %d: new course ID */
			sprintf( __( 'Course created (ID %d).', 'learnomy' ), $course_id )
		);
	}

	/**
	 * Delete a course and all its content.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The course ID to delete.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp learnomy course delete 42
	 *     wp learnomy course delete 42 --yes
	 *
	 * @subcommand delete
	 */
	public function delete( array $args, array $assoc_args ): void {
		$course_id = absint( $args[0] ?? 0 );
		if ( ! $course_id ) {
			\WP_CLI::error( __( 'Please supply a valid course ID.', 'learnomy' ) );
		}

		$course = Course::find( $course_id );
		if ( ! $course ) {
			\WP_CLI::error(
				/* translators: %d: course ID */
				sprintf( __( 'No course found with ID %d.', 'learnomy' ), $course_id )
			);
		}

		\WP_CLI::confirm(
			/* translators: %s: course title */
			sprintf( __( 'Delete "%s" and all its sections, lessons, and enrollments?', 'learnomy' ), $course->title ),
			$assoc_args
		);

		$service = new Course_Service();
		if ( ! $service->delete( $course_id ) ) {
			\WP_CLI::error( __( 'Delete failed. Check database permissions.', 'learnomy' ) );
		}

		\WP_CLI::success(
			/* translators: %d: course ID */
			sprintf( __( 'Course %d deleted.', 'learnomy' ), $course_id )
		);
	}
}
