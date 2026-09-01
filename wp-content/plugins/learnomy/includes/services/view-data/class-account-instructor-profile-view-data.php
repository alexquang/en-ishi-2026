<?php
/**
 * View-data resolver for the instructor self-edit profile (/account/instructor-profile/).
 *
 * Pre-fetches the instructor's current lrn_instructors row (with user_meta
 * fallback for any not-yet-backfilled install) plus the REST endpoint/nonce so
 * the template stays presentation-only. Saving happens via REST
 * (POST learnomy/v1/account/instructor-profile) from account-instructor-profile.js.
 *
 * @package Learnomy
 */

namespace Learnomy\Services\View_Data;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Instructor;

class Account_Instructor_Profile_View_Data {

	/**
	 * @param array $data Route data (unused).
	 * @return array Pre-fetched view data.
	 */
	public static function resolve( array $data ): array {
		unset( $data );

		$user_id = get_current_user_id();
		$profile = Instructor::find_by_user( $user_id );

		// Fallback to user_meta / user_url so the form is pre-filled even before
		// the migration backfill has run for this user (mirrors the public profile).
		$meta = static function ( $value, int $uid, string $key ) {
			$value = (string) $value;
			return '' !== trim( $value ) ? $value : (string) get_user_meta( $uid, $key, true );
		};
		$user = get_userdata( $user_id );

		// "View your public profile" only when there IS one. The public profile
		// exists once the instructor authors or co-teaches a published course, so
		// before that this link pointed at a 404 — the same predicate the route
		// uses decides whether to offer it. Viewer-scoped (not 0) so an instructor
		// whose only published course is unlisted still gets the preview link that
		// works for them.
		$profile_url = ( $user && Instructor::has_public_profile( $user_id ) )
			? \Learnomy\route_url( 'instructor-profile', array( 'slug' => (string) $user->user_nicename ) )
			: '';

		return array(
			'headline'       => $profile ? (string) ( $profile->headline ?? '' ) : '',
			'bio'            => $meta( $profile->bio ?? '', $user_id, 'description' ),
			'expertise'      => $meta( $profile->expertise ?? '', $user_id, 'learnomy_expertise' ),
			'website_url'    => '' !== trim( (string) ( $profile->website_url ?? '' ) )
				? (string) $profile->website_url
				: ( $user ? (string) $user->user_url : '' ),
			'twitter_handle' => $meta( $profile->twitter_handle ?? '', $user_id, 'twitter' ),
			'linkedin_url'   => $meta( $profile->linkedin_url ?? '', $user_id, 'linkedin' ),
			'profile_url'    => $profile_url,
			'rest_url'       => esc_url_raw( rest_url( 'learnomy/v1/account/instructor-profile' ) ),
			'rest_nonce'     => wp_create_nonce( 'wp_rest' ),
		);
	}
}
