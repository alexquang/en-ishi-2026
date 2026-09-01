<?php
/**
 * Media Library scoping — an instructor sees their OWN uploads, not everyone's.
 *
 * WordPress gates the Media Library on `upload_files` alone. It does not ask
 * WHOSE files you may look at, so any role that can upload can browse every
 * attachment on the site: the owner's invoices, another instructor's unreleased
 * course art, a member's uploaded ID. On a single-author blog that is harmless;
 * on a marketplace where instructors are strangers to each other it is not.
 *
 * Measured on lms.local before this class: an `lrn_instructor` opening the
 * Media Library got 24 attachments, 0 of them their own (Basecamp 10158410834).
 *
 * WHO IS SCOPED. Anyone who can `upload_files` but cannot `edit_others_posts`
 * — the capability WordPress itself uses to mean "may manage other people's
 * content". Administrators and editors keep the full library, because managing
 * everyone's media is their job.
 *
 * WHAT IS NOT SCOPED, deliberately: the public `wp/v2/media` REST collection.
 * Attachments of published posts are public content in core — an anonymous
 * visitor already lists all 24 on this install — so filtering it here would
 * break front-end galleries and embeds while hiding nothing that is not already
 * public. That is a separate decision about attachment visibility, not about
 * who may browse the library, and it is not what this class claims to fix.
 *
 * @package Learnomy
 */

namespace Learnomy\Security;

defined( 'ABSPATH' ) || exit;

use WP_Query;

class Media_Library_Scope {

	public static function init(): void {
		// The wp.media modal / grid — the lesson editor's image picker included.
		add_filter( 'ajax_query_attachments_args', [ self::class, 'scope_ajax_query' ] );
		// The admin Media > Library list table.
		add_action( 'pre_get_posts', [ self::class, 'scope_admin_query' ] );
	}

	/**
	 * Whether the CURRENT user should only ever see their own uploads.
	 *
	 * Site owners who run a shared brand-asset library can opt a role back out
	 * with this filter; the default is the private reading, because a leak is
	 * the expensive mistake and a shared library is a deliberate choice.
	 */
	public static function should_scope(): bool {
		$scope = current_user_can( 'upload_files' ) && ! current_user_can( 'edit_others_posts' );

		/**
		 * Filter whether the Media Library is limited to the user's own uploads.
		 *
		 * @param bool $scope   True to show only the current user's attachments.
		 * @param int  $user_id The current user.
		 */
		return (bool) apply_filters( 'learnomy_scope_media_to_author', $scope, get_current_user_id() );
	}

	/**
	 * wp.media picker (admin AND any front-end builder that opens it).
	 *
	 * @param array $query Core's attachment query args.
	 * @return array
	 */
	public static function scope_ajax_query( $query ): array {
		$query = is_array( $query ) ? $query : [];

		if ( ! self::should_scope() ) {
			return $query;
		}

		$query['author'] = get_current_user_id();

		return $query;
	}

	/**
	 * The Media > Library list table and grid.
	 *
	 * Guarded on is_admin() + the main query so this never touches a front-end
	 * loop, and on the attachment post type so ordinary post queries are
	 * untouched.
	 */
	public static function scope_admin_query( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( 'attachment' !== $query->get( 'post_type' ) ) {
			return;
		}

		if ( ! self::should_scope() ) {
			return;
		}

		$query->set( 'author', get_current_user_id() );
	}
}
