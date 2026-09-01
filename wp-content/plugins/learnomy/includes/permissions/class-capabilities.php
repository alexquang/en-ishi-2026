<?php
/**
 * Role and capability definitions for Learnomy.
 *
 * @package Learnomy
 */

namespace Learnomy\Permissions;

defined( 'ABSPATH' ) || exit;

class Capabilities {

	public function register(): void {
		$this->add_student_role();
		$this->add_instructor_role();
		$this->add_caps_to_existing_roles();
	}

	public function unregister(): void {
		remove_role( 'lrn_student' );
		remove_role( 'lrn_instructor' );
		$this->remove_caps_from_existing_roles();
	}

	/**
	 * Re-translate our custom role display names in the in-memory WP_Roles.
	 *
	 * The add_role() call persists the display name into the wp_user_roles option at
	 * activation time, so the __() call there is frozen to whatever locale the
	 * site ran under when activated. This mutates the runtime WP_Roles object
	 * on every request (after the textdomain is loaded on plugins_loaded) so
	 * the names render in the current locale everywhere WP reads them, without
	 * persisting anything. Hook on init.
	 */
	public static function translate_role_names(): void {
		$roles = wp_roles();
		$names = [
			'lrn_student'    => __( 'Student', 'learnomy' ),
			'lrn_instructor' => __( 'Instructor', 'learnomy' ),
		];
		foreach ( $names as $slug => $label ) {
			if ( isset( $roles->roles[ $slug ] ) ) {
				$roles->roles[ $slug ]['name'] = $label;
			}
			if ( isset( $roles->role_names[ $slug ] ) ) {
				$roles->role_names[ $slug ] = $label;
			}
		}
	}

	public function get_all(): array {
		return [
			'student'    => $this->student_caps(),
			'instructor' => $this->instructor_caps(),
			'admin'      => $this->admin_caps(),
		];
	}

	private function add_student_role(): void {
		$student_caps = $this->student_caps();

		// Only create if not already registered — safe to call on every activation.
		if ( ! get_role( 'lrn_student' ) ) {
			// `read` is WordPress's baseline capability for any registered
			// user. Without it, the role acts like an anonymous visitor for
			// every cap-gated WP feature — including the apply-instructor
			// flow which checks `current_user_can('read')` after the nonce
			// (bug card #13: "Student Unable to Submit Instructor Application").
			// All WP roles ship with `read`; the lrn_student role was the
			// outlier and the omission silently broke every cap check that
			// expected a logged-in user baseline.
			$caps         = array_fill_keys( $student_caps, true );
			$caps['read'] = true;
			add_role( 'lrn_student', __( 'Student', 'learnomy' ), $caps );
			return;
		}

		// Migrate existing student roles created before this fix:
		// 1. Add `read` if missing (apply-instructor flow gate).
		// 2. Re-apply every student cap so any drift between the registered
		// cap list and the role's stored caps is corrected. Without this,
		// a role created on an older plugin version that gained new caps
		// in a later release would never receive them on update — students
		// would hit "You are not allowed to enroll" 403s (bug card #1
		// round 3) because their stored role didn't carry `learnomy_enroll`.
		$role = get_role( 'lrn_student' );
		if ( ! $role ) {
			return;
		}
		if ( ! $role->has_cap( 'read' ) ) {
			$role->add_cap( 'read' );
		}
		foreach ( $student_caps as $cap ) {
			if ( ! $role->has_cap( $cap ) ) {
				$role->add_cap( $cap );
			}
		}
	}

	private function add_instructor_role(): void {
		$instructor_caps = array_merge( $this->student_caps(), $this->instructor_only_caps() );

		if ( ! get_role( 'lrn_instructor' ) ) {
			$caps = array_fill_keys( $instructor_caps, true );
			// Same rationale as `add_student_role()` — instructors need the
			// WP baseline `read` cap so dashboard / profile / authoring
			// flows work like any other authenticated WP user.
			$caps['read'] = true;
			add_role( 'lrn_instructor', __( 'Instructor', 'learnomy' ), $caps );
			return;
		}

		// Same migration shape as add_student_role() — heal cap drift.
		$role = get_role( 'lrn_instructor' );
		if ( ! $role ) {
			return;
		}
		if ( ! $role->has_cap( 'read' ) ) {
			$role->add_cap( 'read' );
		}
		foreach ( $instructor_caps as $cap ) {
			if ( ! $role->has_cap( $cap ) ) {
				$role->add_cap( $cap );
			}
		}
	}

	private function add_caps_to_existing_roles(): void {
		$subscriber = get_role( 'subscriber' );
		if ( $subscriber ) {
			foreach ( $this->student_caps() as $cap ) {
				$subscriber->add_cap( $cap );
			}
		}

		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$all_caps = array_merge(
				$this->student_caps(),
				$this->instructor_only_caps(),
				$this->admin_only_caps()
			);
			foreach ( $all_caps as $cap ) {
				$admin->add_cap( $cap );
			}
		}
	}

	private function remove_caps_from_existing_roles(): void {
		$all_caps = array_merge(
			$this->student_caps(),
			$this->instructor_only_caps(),
			$this->admin_only_caps()
		);

		foreach ( [ 'subscriber', 'administrator' ] as $role_slug ) {
			$role = get_role( $role_slug );
			if ( $role ) {
				foreach ( $all_caps as $cap ) {
					$role->remove_cap( $cap );
				}
			}
		}
	}

	private function student_caps(): array {
		return [
			'learnomy_enroll',
			'learnomy_learn',
			'learnomy_take_quiz',
			'learnomy_leave_review',
			'learnomy_view_courses',
		];
	}

	private function instructor_only_caps(): array {
		return [
			'learnomy_create_course',
			'learnomy_manage_own_courses',
			'learnomy_view_own_students',
			'learnomy_view_own_earnings',
			'learnomy_manage_quizzes',
			'learnomy_grade',
			// WordPress core cap — instructors author lessons / course images
			// / video uploads through the standard media library. Without it
			// the "Add Media" button on the course and lesson editors throws
			// "Sorry, you are not allowed to upload this file type." See
			// Basecamp #9919728160.
			'upload_files',
			// "This user belongs in wp-admin." Every instructor capability
			// above is exercised on a wp-admin screen -- Students, Quizzes,
			// Question Bank, Grading -- so the role's presence there is the
			// whole point of it, and saying so is stating a fact about the
			// role rather than working around any one plugin.
			//
			// WooCommerce reads it. prevent_admin_access() asks for ANY of
			// edit_posts / manage_woocommerce / view_admin_dashboard and
			// 302s to /my-account/ when it finds none, so on a WooCommerce
			// site an instructor was bounced off EVERY Learnomy admin page
			// including their own Students list (Basecamp 10132808931).
			//
			// This one, not the other two: `edit_posts` would hand over the
			// site's blog posts, and `manage_woocommerce` the whole store.
			// Neither is true of a teacher, and a capability granted as a
			// side effect is how a role quietly becomes an administrator.
			'view_admin_dashboard',
		];
	}

	private function instructor_caps(): array {
		return array_merge( $this->student_caps(), $this->instructor_only_caps() );
	}

	private function admin_only_caps(): array {
		return [
			'learnomy_manage_settings',
			'learnomy_manage_categories',
			'learnomy_manage_users',
			'learnomy_manage_certificates',
			'learnomy_approve_instructor',
			'learnomy_view_analytics',
		];
	}

	private function admin_caps(): array {
		return array_merge(
			$this->student_caps(),
			$this->instructor_only_caps(),
			$this->admin_only_caps()
		);
	}
}
