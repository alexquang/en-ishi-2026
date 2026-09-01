<?php
/**
 * WP-CLI nested command group: wp learnomy certificate <subcommand>.
 *
 * @package Learnomy
 */

namespace Learnomy\CLI;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Certificate;
use Learnomy\Services\Certificate_Service;

/**
 * Manage Learnomy certificates from the command line.
 *
 * Registered as `wp learnomy certificate <subcommand>`.
 */
class Certificate_Command {

	/**
	 * Issue a certificate to a user for a course.
	 *
	 * ## OPTIONS
	 *
	 * --user-id=<id>
	 * : WordPress user ID. (Cannot use --user; that is a reserved WP-CLI global.)
	 *
	 * --course=<id>
	 * : Course ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp learnomy certificate generate --user-id=7 --course=12
	 *
	 * @subcommand generate
	 */
	public function generate( array $args, array $assoc_args ): void {
		$user_id   = absint( $assoc_args['user-id'] ?? $assoc_args['user'] ?? 0 );
		$course_id = absint( $assoc_args['course'] ?? 0 );

		if ( ! $user_id || ! $course_id ) {
			\WP_CLI::error( __( '--user-id and --course are required.', 'learnomy' ) );
		}

		$service        = new Certificate_Service();
		$certificate_id = $service->issue( $user_id, $course_id );

		if ( ! $certificate_id ) {
			\WP_CLI::error( __( 'Certificate could not be issued.', 'learnomy' ) );
		}

		$certificate = Certificate::find( $certificate_id );

		\WP_CLI::success(
			/* translators: %s: certificate UUID */
			sprintf( __( 'Certificate issued. UUID: %s', 'learnomy' ), $certificate ? $certificate->uuid : $certificate_id )
		);
	}

	/**
	 * Verify a certificate by its UUID.
	 *
	 * ## OPTIONS
	 *
	 * <uuid>
	 * : The certificate UUID to look up.
	 *
	 * ## EXAMPLES
	 *
	 *     wp learnomy certificate verify a1b2c3d4-e5f6-7890-abcd-ef1234567890
	 *
	 * @subcommand verify
	 */
	public function verify( array $args, array $assoc_args ): void {
		$uuid = sanitize_text_field( $args[0] ?? '' );
		if ( ! $uuid ) {
			\WP_CLI::error( __( 'Please supply a certificate UUID.', 'learnomy' ) );
		}

		$certificate = Certificate::verify( $uuid );

		if ( ! $certificate ) {
			\WP_CLI::warning( __( 'No certificate found for that UUID.', 'learnomy' ) );
			return;
		}

		$status_label = 'active' === $certificate->status
			? __( 'Valid', 'learnomy' )
			: sprintf(
				/* translators: %s: certificate status */
				__( 'Invalid (%s)', 'learnomy' ),
				$certificate->status
			);

		\WP_CLI::line( __( 'Status    : ', 'learnomy' ) . $status_label );
		\WP_CLI::line( __( 'UUID      : ', 'learnomy' ) . $certificate->uuid );
		\WP_CLI::line( __( 'User ID   : ', 'learnomy' ) . $certificate->user_id );
		\WP_CLI::line( __( 'Course ID : ', 'learnomy' ) . $certificate->course_id );
		\WP_CLI::line( __( 'Issued at : ', 'learnomy' ) . $certificate->issued_at );
		\WP_CLI::line( __( 'Verified  : ', 'learnomy' ) . $certificate->verification_count . __( ' time(s)', 'learnomy' ) );
	}
}
