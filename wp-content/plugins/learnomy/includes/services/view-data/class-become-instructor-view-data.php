<?php
/**
 * View-data resolver for the become-instructor template.
 *
 * Replaces direct Model calls in templates/become-instructor.php with
 * a pre-resolved data array so the template has zero DB queries.
 *
 * @package Learnomy
 */

namespace Learnomy\Services\View_Data;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\InstructorApplication;

class Become_Instructor_View_Data {

	/**
	 * Resolve all data needed by templates/become-instructor.php.
	 *
	 * @param array $data Route data (typically just 'route' => 'become-instructor').
	 * @return array Pre-fetched view data.
	 */
	public static function resolve( array $data ): array {
		$user_id = get_current_user_id();
		$user    = wp_get_current_user();

		// Detect the Learnomy instructor role. The role slug is `lrn_instructor`
		// (Capabilities::register_roles) — the earlier `learnomy_instructor`
		// check matched neither the role slug nor any capability the
		// instructor role carries, so existing instructors still saw the
		// "Become an instructor" application form on the page. Both `lrn_*`
		// and `learnomy_*` aliases are accepted in case downstream code or
		// add-ons still set the old slug.
		$is_instructor = in_array( 'lrn_instructor', (array) $user->roles, true )
			|| in_array( 'learnomy_instructor', (array) $user->roles, true )
			|| $user->has_cap( 'learnomy_manage_own_courses' );

		// Look up the latest application so the template can render the
		// status page when one exists.
		$existing_apps = InstructorApplication::get_for_user( $user_id );
		$latest_app    = $existing_apps ? $existing_apps[0] : null;
		$app_status    = $latest_app ? $latest_app->status : '';

		return array(
			'user_id'       => $user_id,
			'user'          => $user,
			'is_instructor' => $is_instructor,
			'existing_apps' => $existing_apps,
			'latest_app'    => $latest_app,
			'app_status'    => $app_status,
			'nonce'         => wp_create_nonce( 'lrn_instructor_apply' ),
		);
	}
}
