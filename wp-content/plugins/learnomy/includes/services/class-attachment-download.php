<?php
/**
 * Lesson attachment delivery — the only public way to read a handout's bytes.
 *
 * Everything a lesson attachment is worth protecting for is decided by ONE
 * question: may this person read the lesson body? {@see Lesson_Service::user_can_read_body()}
 * already answers it for the player, the `lesson-content` block and the REST
 * listing, so this route asks the same method and nothing else. There is
 * deliberately no second, weaker notion of "may read this handout" — that split
 * is what let the REST listing gate drift from the player in the first place
 * (Basecamp 10143220266).
 *
 * The bytes themselves live in {@see Protected_Uploads}, off the public path, so
 * this route is not a convenience wrapper over a URL that also works directly.
 * It is the only door.
 *
 * @package Learnomy
 */

namespace Learnomy\Services;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Lesson;
use Learnomy\Models\Lesson_Attachment;

class Attachment_Download {

	/**
	 * MIME types safe to render in the browser tab. Everything else downloads.
	 *
	 * `text/html` and `image/svg+xml` are absent on purpose: served inline from
	 * our own origin they are a stored-XSS vector, and a course handout has no
	 * reason to be either.
	 */
	private const INLINE_TYPES = [
		'application/pdf',
		'text/plain',
		'image/jpeg',
		'image/png',
		'image/gif',
		'image/webp',
		'image/avif',
		'audio/mpeg',
		'audio/ogg',
		'audio/wav',
		'video/mp4',
		'video/webm',
		'video/ogg',
	];

	/**
	 * Serve one attachment. Always exits.
	 *
	 * @param int $attachment_id Row id in `lrn_lesson_attachments`.
	 */
	public static function serve( int $attachment_id ): void {
		nocache_headers();

		$row = $attachment_id > 0 ? Lesson_Attachment::find( $attachment_id ) : null;
		if ( ! $row ) {
			self::deny( 404 );
		}

		$lesson = Lesson::find( (int) $row->lesson_id );
		if ( ! $lesson ) {
			self::deny( 404 );
		}

		// The one gate. Covers private-academy visibility, draft lessons,
		// instructors and co-instructors, the free-preview flag, entitlement
		// (enrollment or membership) and the drip schedule.
		if ( ! Lesson_Service::user_can_read_body( $lesson, get_current_user_id() ) ) {
			self::deny( 403 );
		}

		$path = Lesson_Attachment::get_path( $row );
		if ( '' === $path || ! is_readable( $path ) ) {
			self::deny( 404 );
		}

		/**
		 * Fires immediately before a handout's bytes go out, after the
		 * permission check has passed. Download counters and audit trails hook
		 * here rather than wrapping the route.
		 *
		 * @param int    $attachment_id Attachment row id.
		 * @param int    $lesson_id     Lesson the handout belongs to.
		 * @param int    $user_id       Reader (0 for an anonymous free-preview read).
		 */
		do_action( 'learnomy_lesson_attachment_downloaded', $attachment_id, (int) $row->lesson_id, get_current_user_id() );

		self::stream( $path, $row );
	}

	/**
	 * Send the file, honouring a Range request.
	 *
	 * Range matters for real content: without `Accept-Ranges`, Safari refuses to
	 * play an mp4 at all and PDF.js downloads the whole document before showing
	 * page one. The old delivery path was the web server, which did this for
	 * free — a PHP route that ignores Range is a visible regression for video
	 * and large PDFs, not a detail.
	 */
	private static function stream( string $path, object $row ): void {
		$size = (int) filesize( $path );
		$mime = (string) ( $row->mime_type ?? '' );
		if ( '' === $mime ) {
			$type = wp_check_filetype( $path );
			$mime = $type['type'] ? (string) $type['type'] : 'application/octet-stream';
		}

		$filename    = (string) ( $row->filename ?? '' );
		$filename    = '' !== $filename ? $filename : wp_basename( $path );
		$disposition = in_array( $mime, self::INLINE_TYPES, true ) ? 'inline' : 'attachment';

		$start  = 0;
		$end    = $size - 1;
		$ranged = false;

		$range = isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : '';
		if ( '' !== $range && preg_match( '/^bytes=(\d*)-(\d*)$/', $range, $m ) ) {
			$req_start = '' !== $m[1] ? (int) $m[1] : null;
			$req_end   = '' !== $m[2] ? (int) $m[2] : null;

			if ( null === $req_start && null !== $req_end ) {
				// Suffix range: the last N bytes.
				$start = max( 0, $size - $req_end );
			} else {
				$start = (int) $req_start;
				$end   = null !== $req_end ? min( $req_end, $size - 1 ) : $size - 1;
			}

			if ( $start > $end || $start >= $size ) {
				header( 'Content-Range: bytes */' . $size );
				self::deny( 416 );
			}
			$ranged = true;
		}

		$length = $end - $start + 1;

		if ( $ranged ) {
			status_header( 206 );
			header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $size );
		}

		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . $length );
		header( 'Accept-Ranges: bytes' );
		header( 'Content-Disposition: ' . $disposition . '; filename="' . str_replace( '"', '', $filename ) . '"' );
		// Private: an entitled reader may cache it, a shared proxy may not, and
		// no crawler should ever index it.
		header( 'Cache-Control: private, max-age=0, must-revalidate' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow' );

		// Long files must not be buffered into memory — readfile()/WP_Filesystem
		// both load the whole thing, which dies on a 500MB lecture recording.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a validated local file in chunks; WP_Filesystem has no streaming read.
		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			self::deny( 404 );
		}

		if ( $start > 0 ) {
			fseek( $handle, $start );
		}

		$remaining = $length;
		while ( $remaining > 0 && ! feof( $handle ) ) {
			$chunk = fread( $handle, (int) min( 1024 * 256, $remaining ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw file bytes, not markup.
			$remaining -= strlen( $chunk );
			flush();
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Refuse without leaking whether the file exists. Sends no body — an error
	 * page rendered into a `download` attribute just saves a file full of HTML.
	 */
	private static function deny( int $code ): void {
		status_header( $code );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		exit;
	}
}
