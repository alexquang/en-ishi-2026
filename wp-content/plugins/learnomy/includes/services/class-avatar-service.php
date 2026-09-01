<?php
/**
 * Avatar service — custom member profile photos.
 *
 * Members upload a square photo from /account/details/; it is stored as a
 * Media Library attachment and its id is kept in the `learnomy_avatar_id`
 * user meta. The `pre_get_avatar_data` filter then makes that photo the
 * avatar everywhere WordPress resolves one — Learnomy's Q&A, reviews,
 * dashboards and comments AND any theme/plugin (BuddyPress, Reign) that
 * calls get_avatar()/get_avatar_url(). When no custom photo is set the
 * filter is a no-op and the normal Gravatar fallback applies.
 *
 * @package Learnomy
 */

namespace Learnomy\Services;

defined( 'ABSPATH' ) || exit;

class Avatar_Service {

	/**
	 * User-meta key holding the avatar attachment id.
	 */
	public const META_KEY = 'learnomy_avatar_id';

	/**
	 * Accepted upload mime types. Square-cropped client output is PNG/JPEG;
	 * WebP is allowed for completeness. SVG is intentionally excluded.
	 */
	private const ALLOWED_MIMES = array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' );

	/**
	 * Max upload size in bytes (3 MB). A 512x512 cropped JPEG is ~50-150 KB,
	 * so this is generous headroom, not a target.
	 */
	private const MAX_BYTES = 3145728;

	/**
	 * Hook the avatar override. Called once at plugin load.
	 */
	public function register_hooks(): void {
		add_filter( 'pre_get_avatar_data', array( $this, 'filter_avatar_data' ), 10, 2 );
	}

	/**
	 * Replace the avatar URL with the user's uploaded photo when present.
	 * Covers both get_avatar() and get_avatar_url() — both resolve through
	 * get_avatar_data(), which fires this filter.
	 *
	 * @param array $args        Avatar data args (size, url, found_avatar, …).
	 * @param mixed $id_or_email User id, email, WP_User/WP_Post/WP_Comment, or hash.
	 * @return array
	 */
	public function filter_avatar_data( array $args, $id_or_email ): array {
		$user_id = $this->resolve_user_id( $id_or_email );
		if ( $user_id <= 0 ) {
			return $args;
		}

		$attachment_id = $this->avatar_id( $user_id );
		if ( $attachment_id <= 0 ) {
			return $args;
		}

		$size = isset( $args['size'] ) ? (int) $args['size'] : 96;
		$url  = wp_get_attachment_image_url( $attachment_id, array( $size, $size ) );
		if ( ! $url ) {
			// Attachment was deleted out from under the meta — self-heal so we
			// stop pointing at a dead id and fall back to Gravatar.
			delete_user_meta( $user_id, self::META_KEY );
			return $args;
		}

		$args['url']          = $url;
		$args['found_avatar'] = true;
		$args['default']      = $url;
		return $args;
	}

	/**
	 * The avatar attachment id for a user, or 0 when none is set.
	 */
	public function avatar_id( int $user_id ): int {
		return (int) get_user_meta( $user_id, self::META_KEY, true );
	}

	/**
	 * Store an uploaded image as the user's avatar. Validates the file, side-
	 * loads it into the Media Library, swaps it in atomically (the previous
	 * avatar attachment is deleted), and returns the new attachment id.
	 *
	 * @param int                                                               $user_id Target user.
	 * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file    A single $_FILES entry.
	 * @return int|\WP_Error Attachment id on success.
	 */
	public function set_from_upload( int $user_id, array $file ) {
		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return new \WP_Error( 'learnomy_avatar_no_user', __( 'Unknown user', 'learnomy' ), array( 'status' => 400 ) );
		}

		$check = $this->validate_upload( $file );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Sideload via the $_FILES array. media_handle_sideload moves the temp
		// file, validates the type again server-side, and builds the
		// attachment + intermediate sizes (the small avatar thumbnails we serve).
		$overrides = array(
			'test_form' => false,
			'mimes'     => array(
				'jpg|jpeg' => 'image/jpeg',
				'png'      => 'image/png',
				'webp'     => 'image/webp',
				'gif'      => 'image/gif',
			),
		);

		$attachment_id = media_handle_sideload( $file, 0, null, $overrides );
		if ( is_wp_error( $attachment_id ) ) {
			return new \WP_Error(
				'learnomy_avatar_upload_failed',
				$attachment_id->get_error_message(),
				array( 'status' => 400 )
			);
		}

		// Mark the attachment so it's identifiable and tie authorship to the
		// member (useful for cleanup + GDPR export).
		update_post_meta( (int) $attachment_id, '_learnomy_avatar_for', $user_id );
		wp_update_post(
			array(
				'ID'          => (int) $attachment_id,
				'post_author' => $user_id,
			)
		);

		// Swap in atomically: remember the old one, set the new, then delete old.
		$previous = $this->avatar_id( $user_id );
		update_user_meta( $user_id, self::META_KEY, (int) $attachment_id );
		if ( $previous > 0 && $previous !== (int) $attachment_id ) {
			wp_delete_attachment( $previous, true );
		}

		/**
		 * Fires after a member's avatar changes. Lets caches (CDN, object
		 * cache fragments) invalidate any rendered avatar.
		 *
		 * @param int $user_id       User whose avatar changed.
		 * @param int $attachment_id New avatar attachment id.
		 */
		do_action( 'learnomy_avatar_updated', $user_id, (int) $attachment_id );

		return (int) $attachment_id;
	}

	/**
	 * Remove a user's custom avatar, reverting them to the Gravatar fallback.
	 *
	 * @param int $user_id Target user.
	 * @return bool True if an avatar was removed.
	 */
	public function remove( int $user_id ): bool {
		$attachment_id = $this->avatar_id( $user_id );
		if ( $attachment_id <= 0 ) {
			return false;
		}
		delete_user_meta( $user_id, self::META_KEY );
		wp_delete_attachment( $attachment_id, true );

		/** @see set_from_upload() */
		do_action( 'learnomy_avatar_updated', $user_id, 0 );
		return true;
	}

	/**
	 * Validate the incoming upload before touching the filesystem.
	 *
	 * @param array $file A single $_FILES entry.
	 * @return true|\WP_Error
	 */
	private function validate_upload( array $file ) {
		if ( empty( $file['tmp_name'] ) || ! isset( $file['error'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new \WP_Error( 'learnomy_avatar_no_file', __( 'No image was received. Please choose a photo and try again.', 'learnomy' ), array( 'status' => 400 ) );
		}

		if ( (int) $file['size'] > self::MAX_BYTES ) {
			return new \WP_Error( 'learnomy_avatar_too_large', __( 'That image is too large. Please use one under 3 MB.', 'learnomy' ), array( 'status' => 400 ) );
		}

		// Trust the file contents, not the client-sent type: sniff the real
		// mime from the temp file.
		$info = function_exists( 'wp_getimagesize' ) ? wp_getimagesize( $file['tmp_name'] ) : @getimagesize( $file['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$mime = is_array( $info ) && ! empty( $info['mime'] ) ? (string) $info['mime'] : '';
		if ( ! in_array( $mime, self::ALLOWED_MIMES, true ) ) {
			return new \WP_Error( 'learnomy_avatar_bad_type', __( 'Please upload a JPG, PNG, WebP or GIF image.', 'learnomy' ), array( 'status' => 400 ) );
		}

		return true;
	}

	/**
	 * Resolve the many shapes get_avatar() accepts into a user id.
	 *
	 * @param mixed $id_or_email User id, email, WP_User/WP_Post/WP_Comment, or hash.
	 * @return int 0 when it cannot be tied to a registered user.
	 */
	private function resolve_user_id( $id_or_email ): int {
		if ( is_numeric( $id_or_email ) ) {
			return (int) $id_or_email;
		}
		if ( $id_or_email instanceof \WP_User ) {
			return (int) $id_or_email->ID;
		}
		if ( $id_or_email instanceof \WP_Post ) {
			return (int) $id_or_email->post_author;
		}
		if ( $id_or_email instanceof \WP_Comment ) {
			if ( ! empty( $id_or_email->user_id ) ) {
				return (int) $id_or_email->user_id;
			}
			$email = ! empty( $id_or_email->comment_author_email ) ? $id_or_email->comment_author_email : '';
			$user  = $email ? get_user_by( 'email', $email ) : false;
			return $user ? (int) $user->ID : 0;
		}
		if ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
			$user = get_user_by( 'email', $id_or_email );
			return $user ? (int) $user->ID : 0;
		}
		return 0;
	}
}
