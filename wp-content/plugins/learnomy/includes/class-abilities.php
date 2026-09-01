<?php
/**
 * Registers Learnomy abilities via the WordPress Abilities API (6.9+).
 *
 * Exposes all core LMS features as discoverable, executable abilities
 * so that 3rd-party AI agents can find and operate the learning platform.
 *
 * @package Learnomy
 * @since   1.0.0
 */

namespace Learnomy;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Course;
use Learnomy\Models\Enrollment;
use Learnomy\Models\Quiz;
use Learnomy\Models\QuizAttempt;
use Learnomy\Models\MembershipPlan;
use Learnomy\Models\Commission;
use Learnomy\Models\InstructorApplication;
use Learnomy\Services\Course_Service;
use Learnomy\Services\Enrollment_Service;
use Learnomy\Services\Progress_Service;
use Learnomy\Services\Quiz_Service;
use Learnomy\Services\Subscription_Service;
use Learnomy\Services\Commission_Service;
use Learnomy\Services\Coupon_Service;
use Learnomy\Permissions\Permission_Engine;
use WP_Error;

class Abilities {

	public function __construct() {
		add_action( 'wp_abilities_api_categories_init', [ $this, 'register_categories' ] );
		add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ] );
	}

	/*
	──────────────────────────────────────────────
	 *  Categories
	 * ──────────────────────────────────────────────*/

	public function register_categories(): void {
		wp_register_ability_category(
			'learnomy-courses',
			[
				'label'       => __( 'Course Management', 'learnomy' ),
				'description' => __( 'Create, publish, update, and manage LMS courses.', 'learnomy' ),
			]
		);

		wp_register_ability_category(
			'learnomy-learning',
			[
				'label'       => __( 'Learning & Progress', 'learnomy' ),
				'description' => __( 'Enroll students, track lesson progress, and manage course completions.', 'learnomy' ),
			]
		);

		wp_register_ability_category(
			'learnomy-quizzes',
			[
				'label'       => __( 'Quizzes & Assessments', 'learnomy' ),
				'description' => __( 'Create quizzes, manage attempts, and grade student responses.', 'learnomy' ),
			]
		);

		wp_register_ability_category(
			'learnomy-revenue',
			[
				'label'       => __( 'Revenue & Memberships', 'learnomy' ),
				'description' => __( 'Manage memberships, instructor earnings, withdrawals, and coupons.', 'learnomy' ),
			]
		);

		wp_register_ability_category(
			'learnomy-admin',
			[
				'label'       => __( 'Administration', 'learnomy' ),
				'description' => __( 'Approve instructors, moderate courses, and manage plugin settings.', 'learnomy' ),
			]
		);

		wp_register_ability_category(
			'learnomy-search',
			[
				'label'       => __( 'Course Search', 'learnomy' ),
				'description' => __( 'Search and browse the published course catalog.', 'learnomy' ),
			]
		);

		wp_register_ability_category(
			'learnomy-authoring',
			[
				'label'       => __( 'Content Authoring', 'learnomy' ),
				'description' => __( 'Build course content: sections, lessons, topics, questions, and taxonomy. Ownership-scoped.', 'learnomy' ),
			]
		);

		wp_register_ability_category(
			'learnomy-reporting',
			[
				'label'       => __( 'Reporting & Analytics', 'learnomy' ),
				'description' => __( 'Read-only academy reporting: course/quiz analytics, student rosters, at-risk learners. Instructor-scoped.', 'learnomy' ),
			]
		);
	}

	/*
	──────────────────────────────────────────────
	 *  Abilities
	 * ──────────────────────────────────────────────*/

	public function register_abilities(): void {
		$this->register_course_abilities();
		$this->register_learning_abilities();
		$this->register_quiz_abilities();
		$this->register_revenue_abilities();
		$this->register_admin_abilities();
		$this->register_search_abilities();
		$this->register_authoring_abilities();
		$this->register_reporting_abilities();

		// Extension abilities — Pro modules and third-party code register
		// their abilities by hooking this action and calling
		// wp_register_ability() directly. Fires inside wp_abilities_api_init
		// so listeners are within the proper API lifecycle.
		// Plan 00 §1.5 D7 / §7 Phase 0.
		do_action( 'learnomy_register_abilities' );
	}

	/* ── Course Management ──────────────────────── */

	private function register_course_abilities(): void {

		// ── Create Course ────────────────────────────
		wp_register_ability(
			'learnomy/create-course',
			[
				'label'               => __( 'Create Course', 'learnomy' ),
				'description'         => __( 'Create a new draft course with title, description, and access settings.', 'learnomy' ),
				'category'            => 'learnomy-courses',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'title'       => [
							'type'        => 'string',
							'description' => 'Course title.',
							'required'    => true,
							'minLength'   => 1,
						],
						'description' => [
							'type'        => 'string',
							'description' => 'Course description (HTML).',
						],
						'access_type' => [
							'type'        => 'string',
							'description' => 'Access model for the course.',
							'enum'        => \Learnomy\Models\Course::ACCESS_TYPES,
							'default'     => 'free',
						],
						'price'       => [
							'type'        => 'number',
							'description' => 'Course price (required when access_type is paid).',
							'minimum'     => 0,
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'id'     => [
							'type'        => 'integer',
							'description' => 'Created course ID.',
						],
						'title'  => [ 'type' => 'string' ],
						'status' => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => [ $this, 'execute_create_course' ],
				'permission_callback' => function () {
					$user_id = get_current_user_id();
					return $user_id && Permission_Engine::can( $user_id, 'create_course' );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── Get Course ───────────────────────────────
		wp_register_ability(
			'learnomy/get-course',
			[
				'label'               => __( 'Get Course', 'learnomy' ),
				'description'         => __( 'Retrieve a single course by ID, including its status, price, and instructor details.', 'learnomy' ),
				'category'            => 'learnomy-courses',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'course_id' => [
							'type'        => 'integer',
							'description' => 'Course ID.',
							'required'    => true,
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'id'            => [ 'type' => 'integer' ],
						'title'         => [ 'type' => 'string' ],
						'description'   => [ 'type' => 'string' ],
						'status'        => [ 'type' => 'string' ],
						'access_type'   => [ 'type' => 'string' ],
						'price'         => [ 'type' => 'number' ],
						'student_count' => [ 'type' => 'integer' ],
						'avg_rating'    => [ 'type' => 'number' ],
						'created_at'    => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => [ $this, 'execute_get_course' ],
				'permission_callback' => '__return_true',
				'meta'                => [
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── Update Course ────────────────────────────
		wp_register_ability(
			'learnomy/update-course',
			[
				'label'               => __( 'Update Course', 'learnomy' ),
				'description'         => __( 'Update course fields such as title, description, price, or access type.', 'learnomy' ),
				'category'            => 'learnomy-courses',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'course_id'   => [
							'type'        => 'integer',
							'description' => 'Course ID to update.',
							'required'    => true,
						],
						'title'       => [
							'type'        => 'string',
							'description' => 'New course title.',
						],
						'description' => [
							'type'        => 'string',
							'description' => 'New course description (HTML).',
						],
						'access_type' => [
							'type'        => 'string',
							'description' => 'New access model.',
							'enum'        => \Learnomy\Models\Course::ACCESS_TYPES,
						],
						'price'       => [
							'type'        => 'number',
							'description' => 'New price.',
							'minimum'     => 0,
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'updated' => [
							'type'        => 'boolean',
							'description' => 'Whether the update succeeded.',
						],
					],
				],
				'execute_callback'    => [ $this, 'execute_update_course' ],
				'permission_callback' => function ( $input ) {
					$user_id = get_current_user_id();
					return $user_id && Permission_Engine::can( $user_id, 'manage_course', (int) ( $input['course_id'] ?? 0 ) );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── Delete Course ────────────────────────────
		wp_register_ability(
			'learnomy/delete-course',
			[
				'label'               => __( 'Delete Course', 'learnomy' ),
				'description'         => __( 'Permanently delete a course and all its sections, lessons, and progress data.', 'learnomy' ),
				'category'            => 'learnomy-courses',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'course_id' => [
							'type'        => 'integer',
							'description' => 'Course ID to delete.',
							'required'    => true,
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'deleted' => [
							'type'        => 'boolean',
							'description' => 'Whether the deletion succeeded.',
						],
					],
				],
				'execute_callback'    => [ $this, 'execute_delete_course' ],
				'permission_callback' => function ( $input ) {
					$user_id = get_current_user_id();
					return $user_id && Permission_Engine::can( $user_id, 'manage_course', (int) ( $input['course_id'] ?? 0 ) );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── Publish Course ───────────────────────────
		wp_register_ability(
			'learnomy/publish-course',
			[
				'label'               => __( 'Publish Course', 'learnomy' ),
				'description'         => __( 'Publish a draft course to make it visible and enrollable by students.', 'learnomy' ),
				'category'            => 'learnomy-courses',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'course_id' => [
							'type'        => 'integer',
							'description' => 'Course ID to publish.',
							'required'    => true,
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'published' => [
							'type'        => 'boolean',
							'description' => 'Whether the course is now published. FALSE when the write succeeded but the site routed it to review — read `status` for what actually happened.',
						],
						'status'    => [
							'type'        => 'string',
							'description' => 'The status the course now carries: published, pending_review, or unchanged on failure.',
						],
					],
				],
				'execute_callback'    => [ $this, 'execute_publish_course' ],
				'permission_callback' => function ( $input ) {
					$user_id = get_current_user_id();
					// `publish_course` (vs `manage_course`) layers the admin
					// `instructor_self_publish` setting on top of the ownership
					// check — when the toggle is OFF, non-admin instructors get
					// a 403 from this ability and the UI must fall back to a
					// "save draft" path. Wired in Wave 2C row 2C-3.
					return $user_id && Permission_Engine::can( $user_id, 'publish_course', (int) ( $input['course_id'] ?? 0 ) );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── Duplicate Course ─────────────────────────
		wp_register_ability(
			'learnomy/duplicate-course',
			[
				'label'               => __( 'Duplicate Course', 'learnomy' ),
				'description'         => __( 'Clone an existing course into a new draft, copying all sections, lessons, and instructor assignments.', 'learnomy' ),
				'category'            => 'learnomy-courses',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'course_id' => [
							'type'        => 'integer',
							'description' => 'Course ID to duplicate.',
							'required'    => true,
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'new_course_id' => [
							'type'        => 'integer',
							'description' => 'ID of the newly created course copy.',
						],
					],
				],
				'execute_callback'    => [ $this, 'execute_duplicate_course' ],
				'permission_callback' => function ( $input ) {
					$user_id = get_current_user_id();
					return $user_id && Permission_Engine::can( $user_id, 'manage_course', (int) ( $input['course_id'] ?? 0 ) );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── List Courses ─────────────────────────────
		wp_register_ability(
			'learnomy/list-courses',
			[
				'label'               => __( 'List Courses', 'learnomy' ),
				'description'         => __( 'List published courses with pagination, returning title, price, rating, and student count.', 'learnomy' ),
				'category'            => 'learnomy-courses',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'page'     => [
							'type'        => 'integer',
							'description' => 'Page number (1-based).',
							'default'     => 1,
							'minimum'     => 1,
						],
						'per_page' => [
							'type'        => 'integer',
							'description' => 'Results per page (max 50).',
							'default'     => 20,
							'minimum'     => 1,
							'maximum'     => 50,
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'courses'  => [
							'type'  => 'array',
							'items' => [ 'type' => 'object' ],
						],
						'has_more' => [ 'type' => 'boolean' ],
					],
				],
				'execute_callback'    => [ $this, 'execute_list_courses' ],
				'permission_callback' => '__return_true',
				'meta'                => [
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── Get Course Stats ─────────────────────────
		wp_register_ability(
			'learnomy/get-course-stats',
			[
				'label'               => __( 'Get Course Stats', 'learnomy' ),
				'description'         => __( 'Retrieve aggregate statistics for a course: student count, average rating, and completion rate.', 'learnomy' ),
				'category'            => 'learnomy-courses',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'course_id' => [
							'type'        => 'integer',
							'description' => 'Course ID.',
							'required'    => true,
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'student_count'   => [ 'type' => 'integer' ],
						'avg_rating'      => [ 'type' => 'number' ],
						'review_count'    => [ 'type' => 'integer' ],
						'completion_rate' => [
							'type'        => 'number',
							'description' => 'Percentage of enrolled students who have completed the course.',
						],
					],
				],
				'execute_callback'    => [ $this, 'execute_get_course_stats' ],
				'permission_callback' => function ( $input ) {
					$user_id = get_current_user_id();
					return $user_id && Permission_Engine::can( $user_id, 'manage_course', (int) ( $input['course_id'] ?? 0 ) );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);
	}

	/* ── Learning & Progress ────────────────────── */

	private function register_learning_abilities(): void {

		// ── Enroll ───────────────────────────────────
		wp_register_ability(
			'learnomy/enroll',
			[
				'label'               => __( 'Enroll in Course', 'learnomy' ),
				'description'         => __( 'Enroll a student in a course, checking prerequisites before creating the enrollment record.', 'learnomy' ),
				'category'            => 'learnomy-learning',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'user_id'   => [
							'type'        => 'integer',
							'description' => 'Student user ID.',
							'required'    => true,
						],
						'course_id' => [
							'type'        => 'integer',
							'description' => 'Course ID to enroll in.',
							'required'    => true,
						],
						'source'    => [
							'type'        => 'string',
							'description' => 'Enrollment source.',
							'enum'        => [ 'free', 'purchase', 'membership', 'manual', 'webhook' ],
							'default'     => 'free',
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'enrollment_id' => [
							'type'        => 'integer',
							'description' => 'Created enrollment ID.',
						],
					],
				],
				'execute_callback'    => [ $this, 'execute_enroll' ],
				'permission_callback' => function ( $input ) {
					$user_id = get_current_user_id();
					if ( ! $user_id ) {
						return false;
					}
					// Users can enroll themselves; instructors/admins can enroll anyone.
					if ( (int) $input['user_id'] === $user_id ) {
						return Permission_Engine::can( $user_id, 'learn' );
					}
					return user_can( $user_id, 'manage_options' );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── Unenroll ─────────────────────────────────
		wp_register_ability(
			'learnomy/unenroll',
			[
				'label'               => __( 'Unenroll from Course', 'learnomy' ),
				'description'         => __( 'Cancel a student enrollment by enrollment ID, reducing the course student count.', 'learnomy' ),
				'category'            => 'learnomy-learning',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'enrollment_id' => [
							'type'        => 'integer',
							'description' => 'Enrollment ID to cancel.',
							'required'    => true,
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'unenrolled' => [
							'type'        => 'boolean',
							'description' => 'Whether the unenrollment succeeded.',
						],
					],
				],
				'execute_callback'    => [ $this, 'execute_unenroll' ],
				'permission_callback' => function ( $input ) {
					$user_id = get_current_user_id();
					if ( ! $user_id ) {
						return false;
					}
					$enrollment = Enrollment::find( (int) ( $input['enrollment_id'] ?? 0 ) );
					if ( ! $enrollment ) {
						return new WP_Error( 'not_found', __( 'Enrollment not found.', 'learnomy' ) );
					}
					// Students can unenroll themselves; admins can unenroll anyone.
					if ( (int) $enrollment->user_id === $user_id ) {
						return true;
					}
					return user_can( $user_id, 'manage_options' );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── Access Lesson ────────────────────────────
		wp_register_ability(
			'learnomy/access-lesson',
			[
				'label'               => __( 'Access Lesson', 'learnomy' ),
				'description'         => __( 'Check whether the current user can view a lesson right now, accounting for the drip schedule.', 'learnomy' ),
				'category'            => 'learnomy-learning',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'lesson_id' => [
							'type'        => 'integer',
							'description' => 'Lesson ID to evaluate.',
							'required'    => true,
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'can_access' => [
							'type'        => 'boolean',
							'description' => 'Whether the lesson is available to the current user.',
						],
					],
				],
				'execute_callback'    => [ $this, 'execute_access_lesson' ],
				'permission_callback' => '__return_true',
				'meta'                => [
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── Complete Lesson ──────────────────────────
		wp_register_ability(
			'learnomy/complete-lesson',
			[
				'label'               => __( 'Complete Lesson', 'learnomy' ),
				'description'         => __( 'Mark a lesson as completed for a student, updating course progress and triggering completion checks.', 'learnomy' ),
				'category'            => 'learnomy-learning',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'user_id'   => [
							'type'        => 'integer',
							'description' => 'Student user ID.',
							'required'    => true,
						],
						'lesson_id' => [
							'type'        => 'integer',
							'description' => 'Lesson ID to mark complete.',
							'required'    => true,
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'completed' => [
							'type'        => 'boolean',
							'description' => 'Whether the lesson was marked complete.',
						],
					],
				],
				'execute_callback'    => [ $this, 'execute_complete_lesson' ],
				'permission_callback' => function ( $input ) {
					$user_id = get_current_user_id();
					if ( ! $user_id ) {
						return false;
					}
					// Completing a lesson for someone else is an admin action.
					if ( (int) $input['user_id'] !== $user_id ) {
						return user_can( $user_id, 'manage_options' );
					}
					// Completing your own lesson requires access to its course
					// (admin / enrolled / active membership) — mirrors the REST
					// controllers' is_enrolled_student gate. The role-level 'learn'
					// cap alone is context-free, which let a non-enrolled student
					// write an orphan lrn_progress row through this ability
					// (data-integrity #10058278998).
					$lesson = \Learnomy\Models\Lesson::find( (int) ( $input['lesson_id'] ?? 0 ) );
					if ( ! $lesson ) {
						return false;
					}
					return Permission_Engine::can( $user_id, 'access_course', (int) $lesson->course_id );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── Get Progress ─────────────────────────────
		wp_register_ability(
			'learnomy/get-progress',
			[
				'label'               => __( 'Get Course Progress', 'learnomy' ),
				'description'         => __( 'Retrieve a student\'s progress percentage for a specific course.', 'learnomy' ),
				'category'            => 'learnomy-learning',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'user_id'   => [
							'type'        => 'integer',
							'description' => 'Student user ID.',
							'required'    => true,
						],
						'course_id' => [
							'type'        => 'integer',
							'description' => 'Course ID.',
							'required'    => true,
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'progress_pct' => [
							'type'        => 'integer',
							'description' => 'Completion percentage (0–100).',
						],
					],
				],
				'execute_callback'    => [ $this, 'execute_get_progress' ],
				'permission_callback' => function ( $input ) {
					$user_id = get_current_user_id();
					if ( ! $user_id ) {
						return false;
					}
					if ( (int) $input['user_id'] === $user_id ) {
						return true;
					}
					return user_can( $user_id, 'manage_options' ) || Permission_Engine::can( $user_id, 'manage_course', (int) ( $input['course_id'] ?? 0 ) );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── Restart Course ───────────────────────────
		wp_register_ability(
			'learnomy/restart-course',
			[
				'label'               => __( 'Restart Course', 'learnomy' ),
				'description'         => __( 'Reset all lesson progress and quiz attempts for a student in a course so they can start fresh.', 'learnomy' ),
				'category'            => 'learnomy-learning',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'user_id'   => [
							'type'        => 'integer',
							'description' => 'Student user ID.',
							'required'    => true,
						],
						'course_id' => [
							'type'        => 'integer',
							'description' => 'Course ID to restart.',
							'required'    => true,
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'restarted' => [
							'type'        => 'boolean',
							'description' => 'Whether the restart completed successfully.',
						],
					],
				],
				'execute_callback'    => [ $this, 'execute_restart_course' ],
				'permission_callback' => function ( $input ) {
					$user_id = get_current_user_id();
					if ( ! $user_id ) {
						return false;
					}
					// Restarting another user's course is an admin action.
					if ( (int) $input['user_id'] !== $user_id ) {
						return user_can( $user_id, 'manage_options' );
					}
					// Restarting your own course requires access to it (admin /
					// enrolled / membership) — same context gate as complete-lesson
					// so a non-member can't reset progress on a course they can't
					// take (data-integrity #10058278998, sibling of complete-lesson).
					return Permission_Engine::can( $user_id, 'access_course', (int) ( $input['course_id'] ?? 0 ) );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── Get Enrollment ───────────────────────────
		wp_register_ability(
			'learnomy/get-enrollment',
			[
				'label'               => __( 'Get Enrollment', 'learnomy' ),
				'description'         => __( 'Retrieve enrollment details for a specific student and course combination.', 'learnomy' ),
				'category'            => 'learnomy-learning',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'user_id'   => [
							'type'        => 'integer',
							'description' => 'Student user ID.',
							'required'    => true,
						],
						'course_id' => [
							'type'        => 'integer',
							'description' => 'Course ID.',
							'required'    => true,
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'id'           => [ 'type' => 'integer' ],
						'status'       => [ 'type' => 'string' ],
						'source'       => [ 'type' => 'string' ],
						'progress_pct' => [ 'type' => 'integer' ],
						'enrolled_at'  => [ 'type' => 'string' ],
						'completed_at' => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => [ $this, 'execute_get_enrollment' ],
				'permission_callback' => function ( $input ) {
					$user_id = get_current_user_id();
					if ( ! $user_id ) {
						return false;
					}
					if ( (int) $input['user_id'] === $user_id ) {
						return true;
					}
					return user_can( $user_id, 'manage_options' ) || Permission_Engine::can( $user_id, 'manage_course', (int) ( $input['course_id'] ?? 0 ) );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── List Enrollments ─────────────────────────
		wp_register_ability(
			'learnomy/list-enrollments',
			[
				'label'               => __( 'List Enrollments', 'learnomy' ),
				'description'         => __( 'List all course enrollments for a student, ordered by most recently enrolled.', 'learnomy' ),
				'category'            => 'learnomy-learning',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'user_id' => [
							'type'        => 'integer',
							'description' => 'Student user ID.',
							'required'    => true,
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'enrollments' => [
							'type'  => 'array',
							'items' => [ 'type' => 'object' ],
						],
					],
				],
				'execute_callback'    => [ $this, 'execute_list_enrollments' ],
				'permission_callback' => function ( $input ) {
					$user_id = get_current_user_id();
					if ( ! $user_id ) {
						return false;
					}
					if ( (int) $input['user_id'] === $user_id ) {
						return true;
					}
					return user_can( $user_id, 'manage_options' );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);
	}

	/* ── Quizzes & Assessments ──────────────────── */

	private function register_quiz_abilities(): void {

		// ── Start Quiz ───────────────────────────────
		wp_register_ability(
			'learnomy/start-quiz',
			[
				'label'               => __( 'Start Quiz', 'learnomy' ),
				'description'         => __( 'Begin a new quiz attempt for the current student, enforcing attempt limits and quiz availability windows.', 'learnomy' ),
				'category'            => 'learnomy-quizzes',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'quiz_id'       => [
							'type'        => 'integer',
							'description' => 'Quiz ID to start.',
							'required'    => true,
						],
						'enrollment_id' => [
							'type'        => 'integer',
							'description' => 'Enrollment ID to associate with the attempt (optional).',
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'attempt_id' => [
							'type'        => 'integer',
							'description' => 'Created quiz attempt ID.',
						],
					],
				],
				'execute_callback'    => [ $this, 'execute_start_quiz' ],
				'permission_callback' => function () {
					$user_id = get_current_user_id();
					return $user_id && Permission_Engine::can( $user_id, 'learn' );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── Submit Quiz ──────────────────────────────
		wp_register_ability(
			'learnomy/submit-quiz',
			[
				'label'               => __( 'Submit Quiz', 'learnomy' ),
				'description'         => __( 'Submit a quiz attempt for grading, auto-grading objective questions and flagging essays for manual review.', 'learnomy' ),
				'category'            => 'learnomy-quizzes',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'attempt_id' => [
							'type'        => 'integer',
							'description' => 'Quiz attempt ID to submit.',
							'required'    => true,
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'status'     => [
							'type'        => 'string',
							'description' => 'Attempt status after submission (graded or submitted).',
						],
						'score'      => [ 'type' => 'number' ],
						'max_score'  => [ 'type' => 'number' ],
						'percentage' => [ 'type' => 'number' ],
						'passed'     => [ 'type' => 'boolean' ],
					],
				],
				'execute_callback'    => [ $this, 'execute_submit_quiz' ],
				'permission_callback' => function ( $input ) {
					$user_id = get_current_user_id();
					if ( ! $user_id ) {
						return false;
					}
					$attempt = QuizAttempt::find( (int) ( $input['attempt_id'] ?? 0 ) );
					if ( ! $attempt ) {
						return new WP_Error( 'not_found', __( 'Quiz attempt not found.', 'learnomy' ) );
					}
					return (int) $attempt->user_id === $user_id;
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── Grade Question ───────────────────────────
		wp_register_ability(
			'learnomy/grade-question',
			[
				'label'               => __( 'Grade Question Response', 'learnomy' ),
				'description'         => __( 'Manually grade an essay or open-ended question response with a score and instructor feedback.', 'learnomy' ),
				'category'            => 'learnomy-quizzes',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'response_id' => [
							'type'        => 'integer',
							'description' => 'Question response ID to grade.',
							'required'    => true,
						],
						'score'       => [
							'type'        => 'number',
							'description' => 'Score awarded for this response.',
							'required'    => true,
							'minimum'     => 0,
						],
						'feedback'    => [
							'type'        => 'string',
							'description' => 'Written feedback for the student.',
							'default'     => '',
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'graded' => [
							'type'        => 'boolean',
							'description' => 'Whether the response was successfully graded.',
						],
					],
				],
				'execute_callback'    => [ $this, 'execute_grade_question' ],
				'permission_callback' => function () {
					$user_id = get_current_user_id();
					return $user_id && Permission_Engine::can( $user_id, 'grade_quiz' );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── Get Quiz Results ─────────────────────────
		wp_register_ability(
			'learnomy/get-quiz-results',
			[
				'label'               => __( 'Get Quiz Results', 'learnomy' ),
				'description'         => __( 'Retrieve the full details of a quiz attempt including score, percentage, pass/fail status, and grading state.', 'learnomy' ),
				'category'            => 'learnomy-quizzes',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'attempt_id' => [
							'type'        => 'integer',
							'description' => 'Quiz attempt ID.',
							'required'    => true,
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'id'         => [ 'type' => 'integer' ],
						'quiz_id'    => [ 'type' => 'integer' ],
						'user_id'    => [ 'type' => 'integer' ],
						'status'     => [ 'type' => 'string' ],
						'score'      => [ 'type' => 'number' ],
						'max_score'  => [ 'type' => 'number' ],
						'percentage' => [ 'type' => 'number' ],
						'passed'     => [ 'type' => 'boolean' ],
						'started_at' => [ 'type' => 'string' ],
						'graded_at'  => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => [ $this, 'execute_get_quiz_results' ],
				'permission_callback' => function ( $input ) {
					$user_id = get_current_user_id();
					if ( ! $user_id ) {
						return false;
					}
					$attempt = QuizAttempt::find( (int) ( $input['attempt_id'] ?? 0 ) );
					if ( ! $attempt ) {
						return new WP_Error( 'not_found', __( 'Quiz attempt not found.', 'learnomy' ) );
					}
					if ( (int) $attempt->user_id === $user_id ) {
						return true;
					}
					return Permission_Engine::can( $user_id, 'grade_quiz', (int) $attempt->quiz_id );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── Create Quiz ──────────────────────────────
		wp_register_ability(
			'learnomy/create-quiz',
			[
				'label'               => __( 'Create Quiz', 'learnomy' ),
				'description'         => __( 'Create a new quiz, optionally attaching it to a course and configuring attempt limits and a passing score.', 'learnomy' ),
				'category'            => 'learnomy-quizzes',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'title'         => [
							'type'        => 'string',
							'description' => 'Quiz title.',
							'required'    => true,
							'minLength'   => 1,
						],
						'course_id'     => [
							'type'        => 'integer',
							'description' => 'Course to attach the quiz to (omit for standalone).',
						],
						'passing_score' => [
							'type'        => 'number',
							'description' => 'Minimum percentage to pass (default 70).',
							'default'     => 70,
							'minimum'     => 0,
							'maximum'     => 100,
						],
						'attempt_limit' => [
							'type'        => 'integer',
							'description' => 'Maximum allowed attempts (0 = unlimited).',
							'default'     => 0,
							'minimum'     => 0,
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'quiz_id' => [
							'type'        => 'integer',
							'description' => 'Created quiz ID.',
						],
					],
				],
				'execute_callback'    => [ $this, 'execute_create_quiz' ],
				'permission_callback' => function ( $input ) {
					$user_id = get_current_user_id();
					if ( ! $user_id ) {
						return false;
					}
					$course_id = (int) ( $input['course_id'] ?? 0 );
					if ( $course_id ) {
						return Permission_Engine::can( $user_id, 'manage_course', $course_id );
					}
					return user_can( $user_id, 'learnomy_manage_own_courses' );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					],
					'show_in_rest' => true,
				],
			]
		);
	}

	/* ── Revenue & Memberships ──────────────────── */

	private function register_revenue_abilities(): void {

		// ── Subscribe ────────────────────────────────
		wp_register_ability(
			'learnomy/subscribe',
			[
				'label'               => __( 'Subscribe to Membership Plan', 'learnomy' ),
				'description'         => __( 'Subscribe a student to a membership plan, enrolling them in all courses the plan includes.', 'learnomy' ),
				'category'            => 'learnomy-revenue',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'user_id'         => [
							'type'        => 'integer',
							'description' => 'Student user ID.',
							'required'    => true,
						],
						'plan_id'         => [
							'type'        => 'integer',
							'description' => 'Membership plan ID.',
							'required'    => true,
						],
						'provider'        => [
							'type'        => 'string',
							'description' => 'Payment provider identifier.',
							'required'    => true,
						],
						'provider_sub_id' => [
							'type'        => 'string',
							'description' => 'Provider-side subscription ID (optional).',
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'subscription_id' => [
							'type'        => 'integer',
							'description' => 'Created subscription ID.',
						],
					],
				],
				'execute_callback'    => [ $this, 'execute_subscribe' ],
				'permission_callback' => function ( $input ) {
					$user_id = get_current_user_id();
					if ( ! $user_id ) {
						return false;
					}
					if ( (int) $input['user_id'] === $user_id ) {
						return Permission_Engine::can( $user_id, 'learn' );
					}
					return user_can( $user_id, 'manage_options' );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── Cancel Subscription ──────────────────────
		wp_register_ability(
			'learnomy/cancel-subscription',
			[
				'label'               => __( 'Cancel Membership Subscription', 'learnomy' ),
				'description'         => __( 'Cancel an active membership subscription; access continues until the end of the current billing period.', 'learnomy' ),
				'category'            => 'learnomy-revenue',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'subscription_id' => [
							'type'        => 'integer',
							'description' => 'Subscription ID to cancel.',
							'required'    => true,
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'cancelled' => [
							'type'        => 'boolean',
							'description' => 'Whether the cancellation succeeded.',
						],
					],
				],
				'execute_callback'    => [ $this, 'execute_cancel_subscription' ],
				'permission_callback' => function () {
					$user_id = get_current_user_id();
					return $user_id && Permission_Engine::can( $user_id, 'learn' );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── Get Earnings ─────────────────────────────
		wp_register_ability(
			'learnomy/get-earnings',
			[
				'label'               => __( 'Get Instructor Earnings', 'learnomy' ),
				'description'         => __( 'Retrieve an instructor\'s commission summary showing total earned, pending payout, and already paid amounts.', 'learnomy' ),
				'category'            => 'learnomy-revenue',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'instructor_id' => [
							'type'        => 'integer',
							'description' => 'Instructor user ID.',
							'required'    => true,
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'total_earned'     => [ 'type' => 'number' ],
						'pending'          => [ 'type' => 'number' ],
						'paid'             => [ 'type' => 'number' ],
						'commission_count' => [ 'type' => 'integer' ],
					],
				],
				'execute_callback'    => [ $this, 'execute_get_earnings' ],
				'permission_callback' => function ( $input ) {
					$user_id = get_current_user_id();
					if ( ! $user_id ) {
						return false;
					}
					if ( (int) $input['instructor_id'] === $user_id ) {
						return user_can( $user_id, 'learnomy_manage_own_courses' );
					}
					return user_can( $user_id, 'manage_options' );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── Request Withdrawal ───────────────────────
		wp_register_ability(
			'learnomy/request-withdrawal',
			[
				'label'               => __( 'Request Earnings Withdrawal', 'learnomy' ),
				'description'         => __( 'Submit a withdrawal request for pending commission earnings, specifying the amount and payout method.', 'learnomy' ),
				'category'            => 'learnomy-revenue',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'instructor_id' => [
							'type'        => 'integer',
							'description' => 'Instructor user ID requesting the withdrawal.',
							'required'    => true,
						],
						'amount'        => [
							'type'        => 'number',
							'description' => 'Amount to withdraw.',
							'required'    => true,
							'minimum'     => 0.01,
						],
						'currency'      => [
							'type'        => 'string',
							'description' => 'Three-letter currency code.',
							'required'    => true,
							'minLength'   => 3,
							'maxLength'   => 3,
						],
						'method'        => [
							'type'        => 'string',
							'description' => 'Payout method.',
							'required'    => true,
							'enum'        => [ 'paypal', 'bank_transfer', 'stripe' ],
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'withdrawal_id'    => [
							'type'        => 'integer',
							'description' => 'Created withdrawal request ID.',
						],
						'requested_amount' => [
							'type'        => 'number',
							'description' => 'The amount that was asked for.',
						],
						'settled_amount'   => [
							'type'        => 'number',
							'description' => 'What the request actually settles, and what will be paid. Earnings pay out as whole commission rows, so this resolves up to a row boundary and can exceed the requested amount.',
						],
					],
				],
				'execute_callback'    => [ $this, 'execute_request_withdrawal' ],
				'permission_callback' => function ( $input ) {
					$user_id = get_current_user_id();
					if ( ! $user_id ) {
						return false;
					}
					if ( (int) $input['instructor_id'] === $user_id ) {
						return user_can( $user_id, 'learnomy_manage_own_courses' );
					}
					return user_can( $user_id, 'manage_options' );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── Validate Coupon ──────────────────────────
		wp_register_ability(
			'learnomy/validate-coupon',
			[
				'label'               => __( 'Validate Coupon', 'learnomy' ),
				'description'         => __( 'Check whether a coupon code is valid for a given course or membership plan, returning the discount details.', 'learnomy' ),
				'category'            => 'learnomy-revenue',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'code'      => [
							'type'        => 'string',
							'description' => 'Coupon code to validate.',
							'required'    => true,
							'minLength'   => 1,
						],
						'course_id' => [
							'type'        => 'integer',
							'description' => 'Course ID the coupon is being applied to (optional).',
						],
						'plan_id'   => [
							'type'        => 'integer',
							'description' => 'Membership plan ID the coupon is being applied to (optional).',
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'valid'       => [
							'type'        => 'boolean',
							'description' => 'Whether the coupon is valid.',
						],
						'reason'      => [
							'type'        => 'string',
							'description' => 'Reason the coupon is invalid (present only when valid is false).',
						],
						'coupon_type' => [
							'type'        => 'string',
							'description' => 'Discount type: percent or fixed.',
						],
						'value'       => [
							'type'        => 'number',
							'description' => 'Discount value (percent or fixed amount).',
						],
					],
				],
				'execute_callback'    => [ $this, 'execute_validate_coupon' ],
				'permission_callback' => function () {
					return (bool) get_current_user_id();
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── List Membership Plans ────────────────────
		wp_register_ability(
			'learnomy/list-membership-plans',
			[
				'label'               => __( 'List Membership Plans', 'learnomy' ),
				'description'         => __( 'List all active membership plans ordered by display order, with pricing and included course counts.', 'learnomy' ),
				'category'            => 'learnomy-revenue',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'plans' => [
							'type'  => 'array',
							'items' => [ 'type' => 'object' ],
						],
					],
				],
				'execute_callback'    => [ $this, 'execute_list_membership_plans' ],
				'permission_callback' => '__return_true',
				'meta'                => [
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);
	}

	/* ── Administration ─────────────────────────── */

	private function register_admin_abilities(): void {

		// ── Approve Instructor ───────────────────────
		wp_register_ability(
			'learnomy/approve-instructor',
			[
				'label'               => __( 'Approve Instructor Application', 'learnomy' ),
				'description'         => __( 'Approve a pending instructor application and grant the user instructor capabilities.', 'learnomy' ),
				'category'            => 'learnomy-admin',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'application_id' => [
							'type'        => 'integer',
							'description' => 'Instructor application ID to approve.',
							'required'    => true,
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'approved' => [
							'type'        => 'boolean',
							'description' => 'Whether the application was approved.',
						],
					],
				],
				'execute_callback'    => [ $this, 'execute_approve_instructor' ],
				'permission_callback' => function () {
					// Admin by default (Permission_Engine admin-bypasses manage_options);
					// a site may delegate via the learnomy_approve_instructor cap.
					// [card 10062744921]
					return Permission_Engine::can( get_current_user_id(), 'approve_instructor' );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── Reject Instructor ────────────────────────
		wp_register_ability(
			'learnomy/reject-instructor',
			[
				'label'               => __( 'Reject Instructor Application', 'learnomy' ),
				'description'         => __( 'Reject a pending instructor application with an optional reason that will be visible to the applicant.', 'learnomy' ),
				'category'            => 'learnomy-admin',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'application_id' => [
							'type'        => 'integer',
							'description' => 'Instructor application ID to reject.',
							'required'    => true,
						],
						'reason'         => [
							'type'        => 'string',
							'description' => 'Reason for rejection shown to the applicant.',
							'default'     => '',
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'rejected' => [
							'type'        => 'boolean',
							'description' => 'Whether the rejection was recorded.',
						],
					],
				],
				'execute_callback'    => [ $this, 'execute_reject_instructor' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── Approve Course ───────────────────────────
		wp_register_ability(
			'learnomy/approve-course',
			[
				'label'               => __( 'Approve Course', 'learnomy' ),
				'description'         => __( 'Approve a course submitted for review in marketplace mode, publishing it to the catalog.', 'learnomy' ),
				'category'            => 'learnomy-admin',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'course_id' => [
							'type'        => 'integer',
							'description' => 'Course ID to approve.',
							'required'    => true,
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'approved' => [
							'type'        => 'boolean',
							'description' => 'Whether the course was approved and published.',
						],
					],
				],
				'execute_callback'    => [ $this, 'execute_approve_course' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── Reject Course ────────────────────────────
		wp_register_ability(
			'learnomy/reject-course',
			[
				'label'               => __( 'Reject Course', 'learnomy' ),
				'description'         => __( 'Reject a pending course submission in marketplace mode, returning it to draft status with feedback.', 'learnomy' ),
				'category'            => 'learnomy-admin',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'course_id' => [
							'type'        => 'integer',
							'description' => 'Course ID to reject.',
							'required'    => true,
						],
						'reason'    => [
							'type'        => 'string',
							'description' => 'Rejection reason shown to the instructor.',
							'default'     => '',
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'rejected' => [
							'type'        => 'boolean',
							'description' => 'Whether the course was rejected.',
						],
					],
				],
				'execute_callback'    => [ $this, 'execute_reject_course' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── Manage Settings ──────────────────────────
		wp_register_ability(
			'learnomy/manage-settings',
			[
				'label'               => __( 'Manage Plugin Settings', 'learnomy' ),
				'description'         => __( 'Get or update Learnomy plugin settings such as currency, marketplace mode, and commission rates.', 'learnomy' ),
				'category'            => 'learnomy-admin',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'action'   => [
							'type'        => 'string',
							'description' => 'Whether to get or update settings.',
							'enum'        => [ 'get', 'update' ],
							'default'     => 'get',
						],
						'settings' => [
							'type'        => 'object',
							'description' => 'Settings key-value pairs to update (required when action is update).',
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'settings' => [
							'type'        => 'object',
							'description' => 'Current plugin settings after the operation.',
						],
					],
				],
				'execute_callback'    => [ $this, 'execute_manage_settings' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── Manage Membership Plans ──────────────────
		// Gate for the admin membership-plan editor + subscription admin actions.
		// `Permission_Engine::can( $user_id, 'manage-plans' )` looks this up
		// via the learnomy_manage-plans capability on the user, or returns
		// true when the user already has `manage_options`.
		wp_register_ability(
			'learnomy/manage-plans',
			[
				'label'               => __( 'Manage Membership Plans', 'learnomy' ),
				'description'         => __( 'Create, edit, archive, and delete membership plans. Also governs administrative subscription actions (immediate cancel, extend period).', 'learnomy' ),
				'category'            => 'learnomy-admin',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'can_manage' => [
							'type'        => 'boolean',
							'description' => 'Whether the current user can manage membership plans.',
						],
					],
				],
				'execute_callback'    => [ $this, 'execute_manage_plans_check' ],
				'permission_callback' => function () {
					$user_id = get_current_user_id();
					return $user_id && (
						\Learnomy\Permissions\Permission_Engine::can( $user_id, 'manage-plans' )
						|| current_user_can( 'manage_options' )
					);
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);
	}

	/* ── Course Search ──────────────────────────── */

	private function register_search_abilities(): void {

		// ── Search Courses ───────────────────────────
		wp_register_ability(
			'learnomy/search-courses',
			[
				'label'               => __( 'Search Courses', 'learnomy' ),
				'description'         => __( 'Full-text search across the published course catalog by title and description, with optional access type filtering.', 'learnomy' ),
				'category'            => 'learnomy-search',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'query'       => [
							'type'        => 'string',
							'description' => 'Search query.',
							'required'    => true,
							'minLength'   => 1,
						],
						'access_type' => [
							'type'        => 'string',
							'description' => 'Filter by access type.',
							'enum'        => \Learnomy\Models\Course::ACCESS_TYPES,
						],
						'per_page'    => [
							'type'        => 'integer',
							'description' => 'Results per page (max 50).',
							'default'     => 20,
							'minimum'     => 1,
							'maximum'     => 50,
						],
						'page'        => [
							'type'        => 'integer',
							'description' => 'Page number (1-based).',
							'default'     => 1,
							'minimum'     => 1,
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'courses'  => [
							'type'  => 'array',
							'items' => [ 'type' => 'object' ],
						],
						'total'    => [ 'type' => 'integer' ],
						'has_more' => [ 'type' => 'boolean' ],
					],
				],
				'execute_callback'    => [ $this, 'execute_search_courses' ],
				'permission_callback' => '__return_true',
				'meta'                => [
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);
	}

	/*
	══════════════════════════════════════════════
	 *  Execute Callbacks
	 * ══════════════════════════════════════════════*/

	public function execute_create_course( $input ) {
		$user_id = get_current_user_id();
		$data    = [
			'title'       => sanitize_text_field( $input['title'] ),
			'description' => wp_kses_post( $input['description'] ?? '' ),
			'access_type' => sanitize_key( $input['access_type'] ?? 'free' ),
			'price'       => (float) ( $input['price'] ?? 0 ),
		];

		$service   = new Course_Service();
		$course_id = $service->create( $data, $user_id );

		if ( ! $course_id ) {
			return new WP_Error( 'create_failed', __( 'Failed to create course.', 'learnomy' ) );
		}

		$course = Course::find( $course_id );

		return [
			'id'     => $course_id,
			'title'  => $course->title ?? $data['title'],
			'status' => $course->status ?? 'draft',
		];
	}

	public function execute_get_course( $input ) {
		$course = Course::find( (int) $input['course_id'] );
		if ( ! $course ) {
			return new WP_Error( 'not_found', __( 'Course not found', 'learnomy' ) );
		}

		// This ability is `show_in_rest`, so it is a public door onto a course by
		// id — exactly like GET /courses/{id}. It must apply the same read gate,
		// or it hands every draft/pending/private course (title, full description,
		// price) to anonymous callers. Same 404 as the REST twin, so an
		// unauthorized caller cannot even distinguish "hidden" from "absent".
		if ( ! \Learnomy\can_view_course( $course, get_current_user_id() ) ) {
			return new WP_Error( 'not_found', __( 'Course not found', 'learnomy' ) );
		}

		return [
			'id'            => (int) $course->id,
			'title'         => $course->title ?? '',
			'description'   => $course->description ?? '',
			'status'        => $course->status ?? '',
			'access_type'   => $course->access_type ?? 'free',
			'price'         => (float) ( $course->price ?? 0 ),
			'student_count' => (int) ( $course->student_count ?? 0 ),
			'avg_rating'    => (float) ( $course->avg_rating ?? 0 ),
			'created_at'    => $course->created_at ?? '',
		];
	}

	public function execute_update_course( $input ) {
		$course_id = (int) $input['course_id'];
		$data      = [];

		if ( isset( $input['title'] ) ) {
			$data['title'] = sanitize_text_field( $input['title'] );
		}
		if ( isset( $input['description'] ) ) {
			$data['description'] = wp_kses_post( $input['description'] );
		}
		if ( isset( $input['access_type'] ) ) {
			$data['access_type'] = sanitize_key( $input['access_type'] );
		}
		if ( isset( $input['price'] ) ) {
			$data['price'] = (float) $input['price'];
		}

		if ( empty( $data ) ) {
			return new WP_Error( 'no_data', __( 'No fields provided to update.', 'learnomy' ) );
		}

		$service = new Course_Service();
		$updated = $service->update( $course_id, $data );

		return [ 'updated' => $updated ];
	}

	public function execute_delete_course( $input ) {
		$service = new Course_Service();
		$deleted = $service->delete( (int) $input['course_id'] );

		return [ 'deleted' => $deleted ];
	}

	public function execute_publish_course( $input ) {
		$course_id = (int) $input['course_id'];
		$service   = new Course_Service();
		// `publish()` returns whether the WRITE succeeded, which is not the
		// same question as whether the course went live: a site that holds
		// this author's work for review writes `pending_review` and returns
		// true. Reporting that as `published: true` is the same lie the
		// frontend builder's redirect used to tell (Basecamp 10132217506), so
		// read the row back and answer with what it actually says.
		$service->publish( $course_id );

		$status = (string) ( Course::find( $course_id )->status ?? '' );

		return [
			'published' => 'published' === $status,
			'status'    => $status,
		];
	}

	public function execute_duplicate_course( $input ) {
		$service       = new Course_Service();
		$new_course_id = $service->duplicate( (int) $input['course_id'] );

		if ( ! $new_course_id ) {
			return new WP_Error( 'duplicate_failed', __( 'Failed to duplicate course.', 'learnomy' ) );
		}

		return [ 'new_course_id' => $new_course_id ];
	}

	public function execute_list_courses( $input ) {
		// Private-academy gate. An Ability with show_in_rest IS a REST surface,
		// so it carries the same obligation as /courses/ — an anonymous caller
		// on a "require login for all content" site gets nothing, not the
		// catalogue. Empty rather than WP_Error: a private academy should not
		// confirm how many courses it has (Basecamp 10086765981).
		if ( \Learnomy\course_listing_blocked() ) {
			return [
				'courses'  => [],
				'has_more' => false,
			];
		}

		$page     = max( 1, (int) ( $input['page'] ?? 1 ) );
		$per_page = min( 50, max( 1, (int) ( $input['per_page'] ?? 20 ) ) );

		$courses = Course::list_published( $page, $per_page );
		$items   = [];

		foreach ( $courses as $course ) {
			$items[] = [
				'id'            => (int) $course->id,
				'title'         => $course->title ?? '',
				'access_type'   => $course->access_type ?? 'free',
				'price'         => (float) ( $course->price ?? 0 ),
				'student_count' => (int) ( $course->student_count ?? 0 ),
				'avg_rating'    => (float) ( $course->avg_rating ?? 0 ),
				'created_at'    => $course->created_at ?? '',
			];
		}

		return [
			'courses'  => $items,
			'has_more' => count( $items ) === $per_page,
		];
	}

	public function execute_get_course_stats( $input ) {
		$course_id = (int) $input['course_id'];
		$course    = Course::find( $course_id );

		if ( ! $course ) {
			return new WP_Error( 'not_found', __( 'Course not found', 'learnomy' ) );
		}

		// Two COUNT queries beat loading every enrollment row into PHP.
		// Denominator is total ever-enrolled (any status) so a high cancel
		// rate still shows up as a low completion rate; numerator is the
		// completed subset.
		// Distinct people on both sides so completion_rate is a true
		// "% of students" (was rows via count(); a multi-source learner skewed
		// the base — Basecamp 10109120366).
		$total_enrollments = Enrollment::count_distinct_students( array( $course_id ), array() );
		$completed         = Enrollment::count_distinct_students( array( $course_id ), array( 'completed' ) );
		$completion_rate   = $total_enrollments > 0
			? round( ( $completed / $total_enrollments ) * 100, 2 )
			: 0.0;

		return [
			'student_count'   => (int) ( $course->student_count ?? 0 ),
			'avg_rating'      => (float) ( $course->avg_rating ?? 0 ),
			'review_count'    => (int) ( $course->review_count ?? 0 ),
			'completion_rate' => $completion_rate,
		];
	}

	public function execute_enroll( $input ) {
		$user_id   = (int) $input['user_id'];
		$course_id = (int) $input['course_id'];
		$source    = sanitize_key( $input['source'] ?? 'free' );

		$service       = new Enrollment_Service();
		$enrollment_id = $service->enroll( $user_id, $course_id, $source );

		if ( ! $enrollment_id ) {
			return new WP_Error( 'enroll_failed', __( 'Enrollment failed. Prerequisites may not be met or the student is already enrolled.', 'learnomy' ) );
		}

		return [ 'enrollment_id' => $enrollment_id ];
	}

	public function execute_unenroll( $input ) {
		$service    = new Enrollment_Service();
		$unenrolled = $service->unenroll( (int) $input['enrollment_id'] );

		return [ 'unenrolled' => $unenrolled ];
	}

	public function execute_access_lesson( $input ) {
		$lesson_id = (int) ( $input['lesson_id'] ?? 0 );
		$user_id   = get_current_user_id();

		// Anonymous visitors and logged-in users go through the same gate so
		// the drip rule applies uniformly. Permission_Engine handles admin
		// and course-author bypass via Lesson_Service::is_available_to().
		$can_access = Permission_Engine::can( $user_id, 'access_lesson', $lesson_id );

		return [ 'can_access' => $can_access ];
	}

	public function execute_complete_lesson( $input ) {
		$user_id   = (int) $input['user_id'];
		$lesson_id = (int) $input['lesson_id'];

		$service = new Progress_Service();
		$service->mark_lesson_complete( $user_id, $lesson_id );

		return [ 'completed' => true ];
	}

	public function execute_get_progress( $input ) {
		$user_id   = (int) $input['user_id'];
		$course_id = (int) $input['course_id'];

		$service      = new Progress_Service();
		$progress_pct = $service->calculate_course_progress( $user_id, $course_id );

		return [ 'progress_pct' => $progress_pct ];
	}

	public function execute_restart_course( $input ) {
		$user_id   = (int) $input['user_id'];
		$course_id = (int) $input['course_id'];

		$service = new Progress_Service();
		$service->restart_course( $user_id, $course_id );

		return [ 'restarted' => true ];
	}

	public function execute_get_enrollment( $input ) {
		$user_id   = (int) $input['user_id'];
		$course_id = (int) $input['course_id'];

		$enrollment = Enrollment::find_for_user_course( $user_id, $course_id );
		if ( ! $enrollment ) {
			return new WP_Error( 'not_found', __( 'No active enrollment found for this student and course.', 'learnomy' ) );
		}

		return [
			'id'           => (int) $enrollment->id,
			'status'       => $enrollment->status ?? '',
			'source'       => $enrollment->source ?? '',
			'progress_pct' => (int) ( $enrollment->progress_pct ?? 0 ),
			'enrolled_at'  => $enrollment->enrolled_at ?? '',
			'completed_at' => $enrollment->completed_at ?? '',
		];
	}

	public function execute_list_enrollments( $input ) {
		$user_id     = (int) $input['user_id'];
		$enrollments = Enrollment::get_for_user( $user_id );
		$items       = [];

		foreach ( $enrollments as $enrollment ) {
			$items[] = [
				'id'           => (int) $enrollment->id,
				'course_id'    => (int) ( $enrollment->course_id ?? 0 ),
				'status'       => $enrollment->status ?? '',
				'source'       => $enrollment->source ?? '',
				'progress_pct' => (int) ( $enrollment->progress_pct ?? 0 ),
				'enrolled_at'  => $enrollment->enrolled_at ?? '',
				'completed_at' => $enrollment->completed_at ?? '',
			];
		}

		return [ 'enrollments' => $items ];
	}

	public function execute_start_quiz( $input ) {
		$user_id       = get_current_user_id();
		$quiz_id       = (int) $input['quiz_id'];
		$enrollment_id = isset( $input['enrollment_id'] ) ? (int) $input['enrollment_id'] : null;

		try {
			$service    = new Quiz_Service();
			$attempt_id = $service->start_attempt( $user_id, $quiz_id, $enrollment_id );
		} catch ( \Exception $e ) {
			return new WP_Error( 'start_failed', $e->getMessage() );
		}

		return [ 'attempt_id' => $attempt_id ];
	}

	public function execute_submit_quiz( $input ) {
		try {
			$service = new Quiz_Service();
			$attempt = $service->submit_attempt( (int) $input['attempt_id'] );
		} catch ( \Exception $e ) {
			return new WP_Error( 'submit_failed', $e->getMessage() );
		}

		return [
			'status'     => $attempt->status ?? '',
			'score'      => (float) ( $attempt->score ?? 0 ),
			'max_score'  => (float) ( $attempt->max_score ?? 0 ),
			'percentage' => (float) ( $attempt->percentage ?? 0 ),
			'passed'     => (bool) ( $attempt->passed ?? false ),
		];
	}

	public function execute_grade_question( $input ) {
		$grader_id   = get_current_user_id();
		$response_id = (int) $input['response_id'];
		$score       = (float) $input['score'];
		$feedback    = sanitize_textarea_field( $input['feedback'] ?? '' );

		try {
			$service = new Quiz_Service();
			$service->grade_question( $response_id, $score, $feedback, $grader_id );
		} catch ( \Exception $e ) {
			return new WP_Error( 'grade_failed', $e->getMessage() );
		}

		return [ 'graded' => true ];
	}

	public function execute_get_quiz_results( $input ) {
		$attempt = QuizAttempt::find( (int) $input['attempt_id'] );
		if ( ! $attempt ) {
			return new WP_Error( 'not_found', __( 'Quiz attempt not found.', 'learnomy' ) );
		}

		return [
			'id'         => (int) $attempt->id,
			'quiz_id'    => (int) ( $attempt->quiz_id ?? 0 ),
			'user_id'    => (int) ( $attempt->user_id ?? 0 ),
			'status'     => $attempt->status ?? '',
			'score'      => (float) ( $attempt->score ?? 0 ),
			'max_score'  => (float) ( $attempt->max_score ?? 0 ),
			'percentage' => (float) ( $attempt->percentage ?? 0 ),
			'passed'     => (bool) ( $attempt->passed ?? false ),
			'started_at' => $attempt->started_at ?? '',
			'graded_at'  => $attempt->graded_at ?? '',
		];
	}

	public function execute_create_quiz( $input ) {
		$title         = sanitize_text_field( $input['title'] );
		$course_id     = isset( $input['course_id'] ) ? (int) $input['course_id'] : null;
		$passing_score = (float) ( $input['passing_score'] ?? 70 );
		$attempt_limit = (int) ( $input['attempt_limit'] ?? 0 );

		$data = [
			'title'         => $title,
			'passing_score' => $passing_score,
			'attempt_limit' => $attempt_limit ?: null,
		];

		if ( null !== $course_id ) {
			$data['course_id'] = $course_id;
		}

		$quiz_id = Quiz::create( $data );

		if ( ! $quiz_id ) {
			return new WP_Error( 'create_failed', __( 'Failed to create quiz.', 'learnomy' ) );
		}

		return [ 'quiz_id' => $quiz_id ];
	}

	public function execute_subscribe( $input ) {
		$user_id         = (int) $input['user_id'];
		$plan_id         = (int) $input['plan_id'];
		$provider        = sanitize_text_field( $input['provider'] );
		$provider_sub_id = isset( $input['provider_sub_id'] ) ? sanitize_text_field( $input['provider_sub_id'] ) : null;

		$service         = new Subscription_Service();
		$subscription_id = $service->subscribe( $user_id, $plan_id, $provider, $provider_sub_id );

		if ( ! $subscription_id ) {
			return new WP_Error( 'subscribe_failed', __( 'Subscription failed. The plan may be inactive or the subscriber limit has been reached.', 'learnomy' ) );
		}

		return [ 'subscription_id' => $subscription_id ];
	}

	public function execute_cancel_subscription( $input ) {
		$service   = new Subscription_Service();
		$cancelled = $service->cancel( (int) $input['subscription_id'] );

		return [ 'cancelled' => $cancelled ];
	}

	public function execute_get_earnings( $input ) {
		$instructor_id = (int) $input['instructor_id'];

		// Aggregate as SQL SUMs over EVERY commission row. The previous loop
		// summed Commission::get_for_instructor(), whose default LIMIT is 50, so
		// an instructor with more than 50 commissions had every total truncated
		// to their 50 most recent rows. earnings_summary_for_instructor() keeps
		// the same buckets (recipient_id scope; paid vs not-paid) but sums the
		// full set in the database.
		$summary = Commission::earnings_summary_for_instructor( $instructor_id );

		return [
			'total_earned'     => round( $summary['total_earned'], 2 ),
			'pending'          => round( $summary['pending'], 2 ),
			'paid'             => round( $summary['paid'], 2 ),
			'commission_count' => $summary['count'],
		];
	}

	public function execute_request_withdrawal( $input ) {
		$instructor_id = (int) $input['instructor_id'];
		$amount        = (float) $input['amount'];
		$currency      = strtoupper( sanitize_text_field( $input['currency'] ) );
		$method        = sanitize_key( $input['method'] );

		$service       = new Commission_Service();
		$withdrawal_id = $service->request_withdrawal( $instructor_id, $amount, $currency, $method );

		if ( ! $withdrawal_id ) {
			return new WP_Error( 'withdrawal_failed', __( 'Withdrawal request failed. Check that the amount does not exceed your available balance.', 'learnomy' ) );
		}

		// Report the SETTLED total (read back off the row) alongside the
		// requested one. Earnings settle as whole commission rows, so the two
		// can differ — an agent that only saw the figure it sent would report a
		// payout amount the site will never pay.
		$withdrawal = \Learnomy\Models\Withdrawal::find( $withdrawal_id );

		return [
			'withdrawal_id'    => $withdrawal_id,
			'requested_amount' => round( $amount, 2 ),
			'settled_amount'   => round( (float) ( $withdrawal->amount ?? $amount ), 2 ),
		];
	}

	public function execute_validate_coupon( $input ) {
		$code      = sanitize_text_field( $input['code'] );
		$course_id = isset( $input['course_id'] ) ? (int) $input['course_id'] : null;
		$plan_id   = isset( $input['plan_id'] ) ? (int) $input['plan_id'] : null;
		$user_id   = get_current_user_id() ?: null;

		$service = new Coupon_Service();
		$result  = $service->validate( $code, $course_id, $plan_id, $user_id );

		if ( ! $result['valid'] ) {
			return [
				'valid'  => false,
				'reason' => $result['reason'] ?? __( 'Coupon is not valid.', 'learnomy' ),
			];
		}

		$coupon = $result['coupon'];

		return [
			'valid'       => true,
			'coupon_type' => $coupon->type ?? '',
			'value'       => (float) ( $coupon->value ?? 0 ),
		];
	}

	public function execute_list_membership_plans( $input ) {
		$plans = MembershipPlan::get_active();
		$items = [];

		foreach ( $plans as $plan ) {
			$items[] = [
				'id'         => (int) $plan->id,
				'title'      => $plan->title ?? '',
				'slug'       => $plan->slug ?? '',
				'price'      => (float) ( $plan->price ?? 0 ),
				'interval'   => $plan->billing_interval ?? '',
				'status'     => $plan->status ?? '',
				'sort_order' => (int) ( $plan->sort_order ?? 0 ),
			];
		}

		return [ 'plans' => $items ];
	}

	public function execute_approve_instructor( $input ) {
		$application_id = (int) $input['application_id'];
		$reviewer_id    = get_current_user_id();

		$approved = InstructorApplication::approve( $application_id, $reviewer_id );

		if ( $approved ) {
			$application = InstructorApplication::find( $application_id );
			if ( $application && $application->user_id ) {
				$user = get_user_by( 'ID', (int) $application->user_id );
				if ( $user ) {
					$user->add_role( 'lrn_instructor' );
					// Mirror the Admin_Controller approve path — drop the
					// student role so wp-admin Users list shows "Instructor"
					// instead of "Student". Basecamp #9936673357.
					if ( in_array( 'lrn_student', (array) $user->roles, true ) ) {
						$user->remove_role( 'lrn_student' );
					}
				}
				// Create + seed the lrn_instructors row so the approved
				// instructor actually appears in the Instructors list. The REST
				// approve path already did this; this AJAX path (the admin
				// Applications page button) did not, so approvals here left the
				// Instructors list empty. Shared method keeps both paths aligned.
				\Learnomy\Models\Instructor::provision_from_application( $application );
			}
			/**
			 * Fires when an instructor application is approved.
			 *
			 * The SAME action the Admin_Controller approve path fires, with the
			 * same signature. This path already mirrored that one for the data
			 * (roles, the lrn_instructors row); it fired a differently-named
			 * event with fewer arguments, so anything listening — including the
			 * published `instructor.approved` webhook — simply did not see an
			 * approval made through the Abilities API. Two names for one fact is
			 * the defect; firing the canonical one is the fix.
			 *
			 * @param int $application_id Application row id.
			 * @param int $user_id        The approved applicant.
			 */
			do_action( 'learnomy_instructor_application_approved', $application_id, (int) ( $application->user_id ?? 0 ) );

			/**
			 * Legacy alias, kept firing.
			 *
			 * @deprecated Use `learnomy_instructor_application_approved`.
			 * Nothing in this codebase listens to it, but a site's mu-plugin
			 * may, and silently removing a hook someone hung a workflow on is a
			 * worse bug than the one being fixed.
			 *
			 * @param int $application_id Application row id.
			 */
			do_action( 'learnomy_instructor_approved', $application_id );
		}

		return [ 'approved' => $approved ];
	}

	public function execute_reject_instructor( $input ) {
		$application_id = (int) $input['application_id'];
		$reviewer_id    = get_current_user_id();
		$reason         = sanitize_textarea_field( $input['reason'] ?? '' );

		$rejected = InstructorApplication::reject( $application_id, $reviewer_id, $reason );

		if ( $rejected ) {
			$application = InstructorApplication::find( $application_id );

			/**
			 * Fires when an instructor application is rejected. Same action and
			 * signature as the Admin_Controller reject path — see the approve
			 * twin above for why this path fired a different name.
			 *
			 * @param int    $application_id Application row id.
			 * @param int    $user_id        The rejected applicant.
			 * @param string $reason         Feedback given to the applicant.
			 */
			do_action( 'learnomy_instructor_application_rejected', $application_id, (int) ( $application->user_id ?? 0 ), $reason );

			/**
			 * Legacy alias, kept firing.
			 *
			 * @deprecated Use `learnomy_instructor_application_rejected`.
			 *
			 * @param int    $application_id Application row id.
			 * @param string $reason         Feedback given to the applicant.
			 */
			do_action( 'learnomy_instructor_rejected', $application_id, $reason );
		}

		return [ 'rejected' => $rejected ];
	}

	public function execute_approve_course( $input ) {
		$course_id = (int) $input['course_id'];
		$course    = Course::find( $course_id );

		if ( ! $course ) {
			return new WP_Error( 'not_found', __( 'Course not found', 'learnomy' ) );
		}

		$service  = new Course_Service();
		$approved = $service->publish( $course_id );

		if ( $approved ) {
			do_action( 'learnomy_course_approved', $course_id );
		}

		return [ 'approved' => $approved ];
	}

	public function execute_reject_course( $input ) {
		$course_id = (int) $input['course_id'];
		$reason    = sanitize_textarea_field( $input['reason'] ?? '' );
		$course    = Course::find( $course_id );

		if ( ! $course ) {
			return new WP_Error( 'not_found', __( 'Course not found', 'learnomy' ) );
		}

		$service  = new Course_Service();
		$rejected = $service->update( $course_id, [ 'status' => 'draft' ] );

		if ( $rejected ) {
			do_action( 'learnomy_course_rejected', $course_id, $reason );
		}

		return [ 'rejected' => $rejected ];
	}

	public function execute_manage_settings( $input ) {
		$action   = sanitize_key( $input['action'] ?? 'get' );
		$settings = get_option( 'learnomy_settings', [] );

		if ( 'update' === $action && ! empty( $input['settings'] ) && is_array( $input['settings'] ) ) {
			$incoming = $input['settings'];
			$allowed  = [
				'currency',
				'marketplace_mode',
				'default_commission_rate',
				'require_course_approval',
				'require_instructor_approval',
				'enrollments_per_page',
			];

			foreach ( $allowed as $key ) {
				if ( array_key_exists( $key, $incoming ) ) {
					$settings[ $key ] = sanitize_text_field( (string) $incoming[ $key ] );
				}
			}

			update_option( 'learnomy_settings', $settings );
		}

		return [ 'settings' => $settings ];
	}

	/**
	 * Probe whether the caller can manage membership plans. Returns the
	 * boolean decision — the heavy lifting lives in the permission_callback.
	 *
	 * @param array $input Unused.
	 * @return array{can_manage: bool}
	 */
	public function execute_manage_plans_check( $input ) {
		unset( $input );
		$user_id = get_current_user_id();
		$can     = $user_id && (
			Permission_Engine::can( $user_id, 'manage-plans' )
			|| current_user_can( 'manage_options' )
		);
		return [ 'can_manage' => (bool) $can ];
	}

	public function execute_search_courses( $input ) {
		// Private-academy gate — see execute_list_courses(). Search is the same
		// catalogue through a different door; gating the list and leaving search
		// open would hand back the whole catalogue one query at a time.
		if ( \Learnomy\course_listing_blocked() ) {
			return [
				'courses'  => [],
				'total'    => 0,
				'has_more' => false,
			];
		}

		$query       = sanitize_text_field( $input['query'] );
		$access_type = isset( $input['access_type'] ) ? sanitize_key( $input['access_type'] ) : '';
		$page        = max( 1, (int) ( $input['page'] ?? 1 ) );
		$per_page    = min( 50, max( 1, (int) ( $input['per_page'] ?? 20 ) ) );
		$offset      = ( $page - 1 ) * $per_page;

		$results = Course::search_like( $query, $access_type, $per_page, $offset );

		$items = [];
		foreach ( $results as $course ) {
			$items[] = [
				'id'            => (int) $course->id,
				'title'         => $course->title ?? '',
				'access_type'   => $course->access_type ?? 'free',
				'price'         => (float) ( $course->price ?? 0 ),
				'student_count' => (int) ( $course->student_count ?? 0 ),
				'avg_rating'    => (float) ( $course->avg_rating ?? 0 ),
			];
		}

		return [
			'courses'  => $items,
			'total'    => count( $items ),
			'has_more' => count( $items ) === $per_page,
		];
	}

	/*
	──────────────────────────────────────────────
	 *  Authoring abilities (AI Phase 1 — Free foundation)
	 *
	 *  Every authoring ability DELEGATES to the same service/model the manual
	 *  editors + REST controllers already use — AI/agents become a 4th producer
	 *  into one store, never a parallel write path (keeps the three-entry-point
	 *  rule). Each is Permission_Engine-gated with ownership scope: admin = all,
	 *  instructor = their own courses/questions, student = none.
	 * ────────────────────────────────────────────── */

	private function register_authoring_abilities(): void {

		// ── Create Section ───────────────────────────
		wp_register_ability(
			'learnomy/create-section',
			[
				'label'               => __( 'Create Section', 'learnomy' ),
				'description'         => __( 'Add a curriculum section (module) to a course.', 'learnomy' ),
				'category'            => 'learnomy-authoring',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'course_id'  => [
							'type'        => 'integer',
							'description' => 'Course the section belongs to.',
							'required'    => true,
						],
						'title'      => [
							'type'        => 'string',
							'description' => 'Section title.',
							'required'    => true,
							'minLength'   => 1,
						],
						'sort_order' => [
							'type'        => 'integer',
							'description' => 'Position within the course (0 = first).',
							'minimum'     => 0,
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'id'        => [ 'type' => 'integer' ],
						'course_id' => [ 'type' => 'integer' ],
						'title'     => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => [ $this, 'execute_create_section' ],
				'permission_callback' => function ( $input ) {
					$user_id = get_current_user_id();
					return $user_id && Permission_Engine::can( $user_id, 'manage_course', (int) ( $input['course_id'] ?? 0 ) );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── Update Section ───────────────────────────
		wp_register_ability(
			'learnomy/update-section',
			[
				'label'               => __( 'Update Section', 'learnomy' ),
				'description'         => __( 'Rename a section or change its position.', 'learnomy' ),
				'category'            => 'learnomy-authoring',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'section_id' => [
							'type'        => 'integer',
							'description' => 'Section to update.',
							'required'    => true,
						],
						'title'      => [
							'type'        => 'string',
							'description' => 'New title.',
						],
						'sort_order' => [
							'type'        => 'integer',
							'description' => 'New position.',
							'minimum'     => 0,
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [ 'updated' => [ 'type' => 'boolean' ] ],
				],
				'execute_callback'    => [ $this, 'execute_update_section' ],
				'permission_callback' => function ( $input ) {
					return $this->ability_can_manage_section( (int) ( $input['section_id'] ?? 0 ) );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── Reorder Curriculum ───────────────────────
		wp_register_ability(
			'learnomy/reorder-curriculum',
			[
				'label'               => __( 'Reorder Curriculum', 'learnomy' ),
				'description'         => __( 'Set the order of a course\'s sections from an ordered list of section IDs.', 'learnomy' ),
				'category'            => 'learnomy-authoring',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'course_id'   => [
							'type'        => 'integer',
							'description' => 'Course whose sections to reorder.',
							'required'    => true,
						],
						'section_ids' => [
							'type'        => 'array',
							'description' => 'Section IDs in the desired order.',
							'required'    => true,
							'items'       => [ 'type' => 'integer' ],
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'reordered' => [
							'type'        => 'integer',
							'description' => 'Number of sections repositioned.',
						],
					],
				],
				'execute_callback'    => [ $this, 'execute_reorder_curriculum' ],
				'permission_callback' => function ( $input ) {
					$user_id = get_current_user_id();
					return $user_id && Permission_Engine::can( $user_id, 'manage_course', (int) ( $input['course_id'] ?? 0 ) );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── Create Lesson ────────────────────────────
		wp_register_ability(
			'learnomy/create-lesson',
			[
				'label'               => __( 'Create Lesson', 'learnomy' ),
				'description'         => __( 'Add a lesson to a course section. Body content is Editor.js JSON or HTML, same as the manual editor.', 'learnomy' ),
				'category'            => 'learnomy-authoring',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'course_id'    => [
							'type'        => 'integer',
							'description' => 'Course the lesson belongs to.',
							'required'    => true,
						],
						'section_id'   => [
							'type'        => 'integer',
							'description' => 'Section to place the lesson in.',
						],
						'title'        => [
							'type'        => 'string',
							'description' => 'Lesson title.',
							'required'    => true,
							'minLength'   => 1,
						],
						'content'      => [
							'type'        => 'string',
							'description' => 'Lesson body — Editor.js JSON (preferred) or HTML.',
						],
						'content_type' => [
							'type'        => 'string',
							'description' => 'Lesson content type.',
							'enum'        => [ 'text', 'video', 'audio', 'pdf', 'embed', 'download', 'live' ],
							'default'     => 'text',
						],
						'status'       => [
							'type'        => 'string',
							'description' => 'Lesson status. Use draft for AI-generated content pending review.',
							'enum'        => [ 'draft', 'published' ],
							'default'     => 'published',
						],
						'sort_order'   => [
							'type'        => 'integer',
							'description' => 'Position within the section.',
							'minimum'     => 0,
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'id'        => [ 'type' => 'integer' ],
						'title'     => [ 'type' => 'string' ],
						'course_id' => [ 'type' => 'integer' ],
					],
				],
				'execute_callback'    => [ $this, 'execute_create_lesson' ],
				'permission_callback' => function ( $input ) {
					$user_id = get_current_user_id();
					return $user_id && Permission_Engine::can( $user_id, 'manage_course', (int) ( $input['course_id'] ?? 0 ) );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── Update Lesson ────────────────────────────
		wp_register_ability(
			'learnomy/update-lesson',
			[
				'label'               => __( 'Update Lesson', 'learnomy' ),
				'description'         => __( 'Update a lesson\'s title, body content, or content type.', 'learnomy' ),
				'category'            => 'learnomy-authoring',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'lesson_id'    => [
							'type'        => 'integer',
							'description' => 'Lesson to update.',
							'required'    => true,
						],
						'title'        => [
							'type'        => 'string',
							'description' => 'New title.',
						],
						'content'      => [
							'type'        => 'string',
							'description' => 'New body — Editor.js JSON or HTML.',
						],
						'content_type' => [
							'type' => 'string',
							'enum' => [ 'text', 'video', 'audio', 'pdf', 'embed', 'download', 'live' ],
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [ 'updated' => [ 'type' => 'boolean' ] ],
				],
				'execute_callback'    => [ $this, 'execute_update_lesson' ],
				'permission_callback' => function ( $input ) {
					return $this->ability_can_manage_lesson( (int) ( $input['lesson_id'] ?? 0 ) );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── Delete Lesson ────────────────────────────
		wp_register_ability(
			'learnomy/delete-lesson',
			[
				'label'               => __( 'Delete Lesson', 'learnomy' ),
				'description'         => __( 'Delete a lesson (and its progress + attached data) via the canonical service.', 'learnomy' ),
				'category'            => 'learnomy-authoring',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'lesson_id' => [
							'type'        => 'integer',
							'description' => 'Lesson to delete.',
							'required'    => true,
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [ 'deleted' => [ 'type' => 'boolean' ] ],
				],
				'execute_callback'    => [ $this, 'execute_delete_lesson' ],
				'permission_callback' => function ( $input ) {
					return $this->ability_can_manage_lesson( (int) ( $input['lesson_id'] ?? 0 ) );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── Create Topic ─────────────────────────────
		wp_register_ability(
			'learnomy/create-topic',
			[
				'label'               => __( 'Create Topic', 'learnomy' ),
				'description'         => __( 'Add a topic (a child lesson row) under a parent lesson.', 'learnomy' ),
				'category'            => 'learnomy-authoring',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'parent_lesson_id' => [
							'type'        => 'integer',
							'description' => 'Parent lesson this topic sits under.',
							'required'    => true,
						],
						'title'            => [
							'type'        => 'string',
							'description' => 'Topic title.',
							'required'    => true,
							'minLength'   => 1,
						],
						'content'          => [
							'type'        => 'string',
							'description' => 'Topic body — Editor.js JSON or HTML.',
						],
						'sort_order'       => [
							'type'    => 'integer',
							'minimum' => 0,
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'id'               => [ 'type' => 'integer' ],
						'parent_lesson_id' => [ 'type' => 'integer' ],
					],
				],
				'execute_callback'    => [ $this, 'execute_create_topic' ],
				'permission_callback' => function ( $input ) {
					return $this->ability_can_manage_lesson( (int) ( $input['parent_lesson_id'] ?? 0 ) );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── Create Question ──────────────────────────
		wp_register_ability(
			'learnomy/create-question',
			[
				'label'               => __( 'Create Question', 'learnomy' ),
				'description'         => __( 'Create a question-bank item with its answers. Covers all question types via the answers[] shape (content, is_correct, fraction, group_id, feedback). The same shape the editor + CSV import use.', 'learnomy' ),
				'category'            => 'learnomy-authoring',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'title'       => [
							'type'        => 'string',
							'description' => 'Question text / stem.',
							'required'    => true,
							'minLength'   => 1,
						],
						'type'        => [
							'type'        => 'string',
							'description' => 'Question type (drives grading). Free: mc_single, mc_multi, true_false, short_answer, fill_blank, essay. Pro (advanced-quizzes active): matching, ordering, numeric, math, calculated.',
							'required'    => true,
						],
						'difficulty'  => [
							'type'        => 'integer',
							'description' => '1 (easiest) to 5 (hardest).',
							'minimum'     => 1,
							'maximum'     => 5,
							'default'     => 3,
						],
						'status'      => [
							'type'    => 'string',
							'enum'    => [ 'draft', 'published' ],
							'default' => 'published',
						],
						'hint'        => [ 'type' => 'string' ],
						'category_id' => [
							'type'        => 'integer',
							'description' => 'Question-bank category.',
						],
						'answers'     => [
							'type'        => 'array',
							'description' => 'Answer rows. For essay leave empty; for mc_* / true_false set is_correct; for partial credit set fraction (0–1); for matching/ordering/fill_blank use group_id to pair/sequence.',
							'items'       => [
								'type'       => 'object',
								'properties' => [
									'content'    => [
										'type'     => 'string',
										'required' => true,
									],
									'is_correct' => [ 'type' => 'boolean' ],
									'fraction'   => [
										'type'    => 'number',
										'minimum' => 0,
										'maximum' => 1,
									],
									'group_id'   => [ 'type' => 'string' ],
									'feedback'   => [ 'type' => 'string' ],
								],
							],
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'id'              => [ 'type' => 'integer' ],
						'type'            => [ 'type' => 'string' ],
						'answers_created' => [ 'type' => 'integer' ],
					],
				],
				'execute_callback'    => [ $this, 'execute_create_question' ],
				'permission_callback' => function () {
					$user_id = get_current_user_id();
					return $user_id && Permission_Engine::can( $user_id, 'manage_own_courses' );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── Update Question ──────────────────────────
		wp_register_ability(
			'learnomy/update-question',
			[
				'label'               => __( 'Update Question', 'learnomy' ),
				'description'         => __( 'Update a question\'s fields and/or replace its answers. Bumps the question version so past attempts keep the version they answered.', 'learnomy' ),
				'category'            => 'learnomy-authoring',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'question_id' => [
							'type'        => 'integer',
							'description' => 'Question to update.',
							'required'    => true,
						],
						'title'       => [ 'type' => 'string' ],
						'difficulty'  => [
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 5,
						],
						'status'      => [
							'type' => 'string',
							'enum' => [ 'draft', 'published' ],
						],
						'hint'        => [ 'type' => 'string' ],
						'category_id' => [ 'type' => 'integer' ],
						'answers'     => [
							'type'        => 'array',
							'description' => 'When supplied, REPLACES the answer set (same shape as create-question).',
							'items'       => [ 'type' => 'object' ],
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [ 'updated' => [ 'type' => 'boolean' ] ],
				],
				'execute_callback'    => [ $this, 'execute_update_question' ],
				'permission_callback' => function ( $input ) {
					return $this->ability_can_manage_question( (int) ( $input['question_id'] ?? 0 ) );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── Add Question to Quiz ─────────────────────
		wp_register_ability(
			'learnomy/add-question-to-quiz',
			[
				'label'               => __( 'Add Question to Quiz', 'learnomy' ),
				'description'         => __( 'Attach an existing question-bank item to a quiz as a slot.', 'learnomy' ),
				'category'            => 'learnomy-authoring',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'quiz_id'     => [
							'type'        => 'integer',
							'description' => 'Quiz to add the question to.',
							'required'    => true,
						],
						'question_id' => [
							'type'        => 'integer',
							'description' => 'Question-bank item to attach.',
							'required'    => true,
						],
						'sort_order'  => [
							'type'    => 'integer',
							'minimum' => 0,
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [ 'slot_id' => [ 'type' => 'integer' ] ],
				],
				'execute_callback'    => [ $this, 'execute_add_question_to_quiz' ],
				'permission_callback' => function ( $input ) {
					return $this->ability_can_manage_quiz( (int) ( $input['quiz_id'] ?? 0 ) );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── Update Quiz ──────────────────────────────
		wp_register_ability(
			'learnomy/update-quiz',
			[
				'label'               => __( 'Update Quiz', 'learnomy' ),
				'description'         => __( 'Update quiz settings (title, mode, passing score, time limit, attempts, feedback timing, etc.).', 'learnomy' ),
				'category'            => 'learnomy-authoring',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'quiz_id'         => [
							'type'        => 'integer',
							'description' => 'Quiz to update.',
							'required'    => true,
						],
						'title'           => [ 'type' => 'string' ],
						'mode'            => [
							'type' => 'string',
							'enum' => [ 'graded', 'practice', 'survey' ],
						],
						'passing_score'   => [
							'type'    => 'integer',
							'minimum' => 0,
							'maximum' => 100,
						],
						'attempt_limit'   => [
							'type'    => 'integer',
							'minimum' => 0,
						],
						'feedback_timing' => [
							'type' => 'string',
							'enum' => [ 'immediate', 'after_question', 'after_submit', 'after_attempt', 'after_pass', 'never' ],
						],
						'status'          => [
							'type' => 'string',
							'enum' => [ 'draft', 'published', 'archived' ],
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [ 'updated' => [ 'type' => 'boolean' ] ],
				],
				'execute_callback'    => [ $this, 'execute_update_quiz' ],
				'permission_callback' => function ( $input ) {
					return $this->ability_can_manage_quiz( (int) ( $input['quiz_id'] ?? 0 ) );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── Create Course Category ───────────────────
		wp_register_ability(
			'learnomy/create-course-category',
			[
				'label'               => __( 'Create Course Category', 'learnomy' ),
				'description'         => __( 'Create a category in the course taxonomy.', 'learnomy' ),
				'category'            => 'learnomy-authoring',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'name'      => [
							'type'        => 'string',
							'description' => 'Category name.',
							'required'    => true,
							'minLength'   => 1,
						],
						'parent_id' => [
							'type'        => 'integer',
							'description' => 'Parent category (0 = top level).',
							'minimum'     => 0,
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'id'   => [ 'type' => 'integer' ],
						'name' => [ 'type' => 'string' ],
						'slug' => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => [ $this, 'execute_create_course_category' ],
				'permission_callback' => function () {
					$user_id = get_current_user_id();
					return $user_id && Permission_Engine::can( $user_id, 'manage_own_courses' );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── Create Question Category ─────────────────
		wp_register_ability(
			'learnomy/create-question-category',
			[
				'label'               => __( 'Create Question Category', 'learnomy' ),
				'description'         => __( 'Create a category in the question-bank taxonomy.', 'learnomy' ),
				'category'            => 'learnomy-authoring',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'name'      => [
							'type'        => 'string',
							'description' => 'Category name.',
							'required'    => true,
							'minLength'   => 1,
						],
						'parent_id' => [
							'type'        => 'integer',
							'description' => 'Parent category (0 = top level).',
							'minimum'     => 0,
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'id'   => [ 'type' => 'integer' ],
						'name' => [ 'type' => 'string' ],
						'slug' => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => [ $this, 'execute_create_question_category' ],
				'permission_callback' => function () {
					$user_id = get_current_user_id();
					return $user_id && Permission_Engine::can( $user_id, 'manage_own_courses' );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── Upload Media (set course featured image) ─
		wp_register_ability(
			'learnomy/upload-media',
			[
				'label'               => __( 'Set Course Image', 'learnomy' ),
				'description'         => __( 'Set a course featured image from an existing Media Library attachment ID, or by side-loading an image URL into the Media Library.', 'learnomy' ),
				'category'            => 'learnomy-authoring',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'course_id'     => [
							'type'        => 'integer',
							'description' => 'Course to set the image on.',
							'required'    => true,
						],
						'attachment_id' => [
							'type'        => 'integer',
							'description' => 'Existing Media Library attachment ID (preferred).',
						],
						'image_url'     => [
							'type'        => 'string',
							'description' => 'Image URL to side-load when no attachment_id is given.',
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'attachment_id' => [ 'type' => 'integer' ],
						'thumbnail_url' => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => [ $this, 'execute_upload_media' ],
				'permission_callback' => function ( $input ) {
					$user_id = get_current_user_id();
					return $user_id && Permission_Engine::can( $user_id, 'manage_course', (int) ( $input['course_id'] ?? 0 ) );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── Create Certificate Template ──────────────
		wp_register_ability(
			'learnomy/create-certificate-template',
			[
				'label'               => __( 'Create Certificate Template', 'learnomy' ),
				'description'         => __( 'Create a certificate design template (title, design JSON, orientation).', 'learnomy' ),
				'category'            => 'learnomy-authoring',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'title'           => [
							'type'        => 'string',
							'description' => 'Template name.',
							'required'    => true,
							'minLength'   => 1,
						],
						'design_json'     => [
							'type'        => 'string',
							'description' => 'Serialized design (elements/layout) JSON. Optional — defaults to an empty design.',
						],
						'orientation'     => [
							'type'    => 'string',
							'enum'    => [ 'landscape', 'portrait' ],
							'default' => 'landscape',
						],
						'expiration_days' => [
							'type'        => 'integer',
							'description' => 'Days until certificates from this template expire (omit = never).',
							'minimum'     => 0,
						],
						'status'          => [
							'type'    => 'string',
							'enum'    => [ 'active', 'inactive' ],
							'default' => 'active',
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'id'    => [ 'type' => 'integer' ],
						'title' => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => [ $this, 'execute_create_certificate_template' ],
				'permission_callback' => function () {
					$user_id = get_current_user_id();
					return $user_id && Permission_Engine::can( $user_id, 'manage_own_courses' );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					],
					'show_in_rest' => true,
				],
			]
		);
	}

	/* ── Authoring ownership helpers ─────────────── */

	/** A section is managed by whoever owns its course. */
	private function ability_can_manage_section( int $section_id ): bool {
		$user_id = get_current_user_id();
		$section = $section_id ? \Learnomy\Models\Section::find( $section_id ) : null;
		return $user_id && $section && Permission_Engine::can( $user_id, 'manage_course', (int) $section->course_id );
	}

	/** A lesson (or topic) is managed by whoever owns its course. */
	private function ability_can_manage_lesson( int $lesson_id ): bool {
		$user_id = get_current_user_id();
		$lesson  = $lesson_id ? \Learnomy\Models\Lesson::find( $lesson_id ) : null;
		return $user_id && $lesson && Permission_Engine::can( $user_id, 'manage_course', (int) $lesson->course_id );
	}

	/** A course quiz is owner-gated by its course; a standalone quiz by the manage cap. */
	private function ability_can_manage_quiz( int $quiz_id ): bool {
		$user_id = get_current_user_id();
		if ( ! $user_id || ! $quiz_id ) {
			return false;
		}
		$quiz = Quiz::find( $quiz_id );
		if ( ! $quiz ) {
			return false;
		}
		$course_id = (int) ( $quiz->course_id ?? 0 );
		return $course_id > 0
			? Permission_Engine::can( $user_id, 'manage_course', $course_id )
			: Permission_Engine::can( $user_id, 'manage_own_courses' );
	}

	/** A question-bank item is managed by its author (admin bypass). */
	private function ability_can_manage_question( int $question_id ): bool {
		$user_id = get_current_user_id();
		if ( ! $user_id || ! $question_id ) {
			return false;
		}
		if ( ! Permission_Engine::can( $user_id, 'manage_own_courses' ) ) {
			return false;
		}
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}
		$question = \Learnomy\Models\Question::find( $question_id );
		return $question && (int) $question->author_id === $user_id;
	}

	/**
	 * Persist an answers[] payload for a question, mirroring Question_CSV /
	 * the question editor: one Answer row per item, with partial-credit and
	 * grouping fields. Returns the count created.
	 */
	private function ability_save_answers( int $question_id, array $answers ): int {
		$created = 0;
		foreach ( array_values( $answers ) as $i => $answer ) {
			if ( ! is_array( $answer ) || '' === trim( (string) ( $answer['content'] ?? '' ) ) ) {
				continue;
			}
			$is_correct = ! empty( $answer['is_correct'] );
			\Learnomy\Models\Answer::create(
				[
					'question_id' => $question_id,
					'content'     => wp_kses_post( (string) $answer['content'] ),
					'is_correct'  => $is_correct ? 1 : 0,
					'fraction'    => isset( $answer['fraction'] ) ? (float) $answer['fraction'] : ( $is_correct ? 1.0 : 0.0 ),
					'feedback'    => isset( $answer['feedback'] ) ? sanitize_text_field( (string) $answer['feedback'] ) : '',
					'group_id'    => isset( $answer['group_id'] ) ? sanitize_text_field( (string) $answer['group_id'] ) : null,
					'sort_order'  => isset( $answer['sort_order'] ) ? (int) $answer['sort_order'] : $i,
				]
			);
			++$created;
		}
		return $created;
	}

	/* ── Authoring execute callbacks ─────────────── */

	public function execute_create_section( $input ) {
		$section_id = \Learnomy\Models\Section::create(
			[
				'course_id'  => (int) $input['course_id'],
				'title'      => sanitize_text_field( $input['title'] ),
				'sort_order' => (int) ( $input['sort_order'] ?? 0 ),
			]
		);
		if ( ! $section_id ) {
			return new WP_Error( 'create_failed', __( 'Failed to create section.', 'learnomy' ) );
		}
		return [
			'id'        => $section_id,
			'course_id' => (int) $input['course_id'],
			'title'     => sanitize_text_field( $input['title'] ),
		];
	}

	public function execute_update_section( $input ) {
		$data = [];
		if ( isset( $input['title'] ) ) {
			$data['title'] = sanitize_text_field( $input['title'] );
		}
		if ( isset( $input['sort_order'] ) ) {
			$data['sort_order'] = (int) $input['sort_order'];
		}
		if ( empty( $data ) ) {
			return new WP_Error( 'no_data', __( 'No fields provided to update.', 'learnomy' ) );
		}
		return [ 'updated' => \Learnomy\Models\Section::update( (int) $input['section_id'], $data ) ];
	}

	public function execute_reorder_curriculum( $input ) {
		$course_id   = (int) $input['course_id'];
		$section_ids = array_map( 'intval', (array) ( $input['section_ids'] ?? [] ) );
		$reordered   = 0;
		foreach ( $section_ids as $position => $section_id ) {
			$section = \Learnomy\Models\Section::find( $section_id );
			// Only touch sections that actually belong to this course — never let
			// a caller reorder another course's sections via a forged id list.
			if ( $section && (int) $section->course_id === $course_id
				&& \Learnomy\Models\Section::update( $section_id, [ 'sort_order' => $position ] ) ) {
				++$reordered;
			}
		}
		return [ 'reordered' => $reordered ];
	}

	public function execute_create_lesson( $input ) {
		$lesson_id = \Learnomy\Models\Lesson::create(
			[
				'course_id'    => (int) $input['course_id'],
				'section_id'   => (int) ( $input['section_id'] ?? 0 ),
				'title'        => sanitize_text_field( $input['title'] ),
				'content'      => wp_kses_post( $input['content'] ?? '' ),
				'content_type' => sanitize_key( $input['content_type'] ?? 'text' ),
				'status'       => sanitize_key( $input['status'] ?? 'published' ),
				'sort_order'   => (int) ( $input['sort_order'] ?? 0 ),
			]
		);
		if ( ! $lesson_id ) {
			return new WP_Error( 'create_failed', __( 'Failed to create lesson.', 'learnomy' ) );
		}
		return [
			'id'        => $lesson_id,
			'title'     => sanitize_text_field( $input['title'] ),
			'course_id' => (int) $input['course_id'],
		];
	}

	public function execute_update_lesson( $input ) {
		$data = [];
		if ( isset( $input['title'] ) ) {
			$data['title'] = sanitize_text_field( $input['title'] );
		}
		if ( isset( $input['content'] ) ) {
			// `content_plain` follows from Lesson::search_projections() on the
			// write below, derived from the SANITISED content rather than the raw
			// input this used to strip.
			$data['content'] = wp_kses_post( $input['content'] );
		}
		if ( isset( $input['content_type'] ) ) {
			$data['content_type'] = sanitize_key( $input['content_type'] );
		}
		if ( empty( $data ) ) {
			return new WP_Error( 'no_data', __( 'No fields provided to update.', 'learnomy' ) );
		}
		return [ 'updated' => \Learnomy\Models\Lesson::update( (int) $input['lesson_id'], $data ) ];
	}

	public function execute_delete_lesson( $input ) {
		$lesson = \Learnomy\Models\Lesson::find( (int) $input['lesson_id'] );
		if ( ! $lesson ) {
			return new WP_Error( 'not_found', __( 'Lesson not found.', 'learnomy' ) );
		}
		// Canonical chokepoint — removes the row AND fires the cascade/cleanup.
		$deleted = \Learnomy\Services\Lesson_Service::delete( (int) $lesson->id, (int) $lesson->course_id );
		return [ 'deleted' => (bool) $deleted ];
	}

	public function execute_create_topic( $input ) {
		$parent = \Learnomy\Models\Lesson::find( (int) $input['parent_lesson_id'] );
		if ( ! $parent ) {
			return new WP_Error( 'not_found', __( 'Parent lesson not found.', 'learnomy' ) );
		}
		$topic_id = \Learnomy\Models\Lesson::create(
			[
				'course_id'  => (int) $parent->course_id,
				'section_id' => (int) ( $parent->section_id ?? 0 ),
				'parent_id'  => (int) $parent->id,
				'title'      => sanitize_text_field( $input['title'] ),
				'content'    => wp_kses_post( $input['content'] ?? '' ),
				'status'     => sanitize_key( $input['status'] ?? 'published' ),
				'sort_order' => (int) ( $input['sort_order'] ?? 0 ),
			]
		);
		if ( ! $topic_id ) {
			return new WP_Error( 'create_failed', __( 'Failed to create topic.', 'learnomy' ) );
		}
		return [
			'id'               => $topic_id,
			'parent_lesson_id' => (int) $parent->id,
		];
	}

	public function execute_create_question( $input ) {
		$question_id = \Learnomy\Models\Question::create(
			[
				'title'       => sanitize_text_field( $input['title'] ),
				'type'        => sanitize_key( $input['type'] ),
				'difficulty'  => max( 1, min( 5, (int) ( $input['difficulty'] ?? 3 ) ) ),
				'status'      => sanitize_key( $input['status'] ?? 'published' ),
				'hint'        => sanitize_text_field( $input['hint'] ?? '' ),
				'content'     => wp_kses_post( $input['content'] ?? '' ),
				'category_id' => ! empty( $input['category_id'] ) ? (int) $input['category_id'] : null,
				'author_id'   => get_current_user_id(),
			]
		);
		if ( ! $question_id ) {
			return new WP_Error( 'create_failed', __( 'Failed to create question.', 'learnomy' ) );
		}
		$answers_created = is_array( $input['answers'] ?? null )
			? $this->ability_save_answers( $question_id, $input['answers'] )
			: 0;
		return [
			'id'              => $question_id,
			'type'            => sanitize_key( $input['type'] ),
			'answers_created' => $answers_created,
		];
	}

	public function execute_update_question( $input ) {
		$question_id = (int) $input['question_id'];
		$data        = [];
		if ( isset( $input['title'] ) ) {
			$data['title'] = sanitize_text_field( $input['title'] );
		}
		if ( isset( $input['difficulty'] ) ) {
			$data['difficulty'] = max( 1, min( 5, (int) $input['difficulty'] ) );
		}
		if ( isset( $input['status'] ) ) {
			$data['status'] = sanitize_key( $input['status'] );
		}
		if ( isset( $input['hint'] ) ) {
			$data['hint'] = sanitize_text_field( $input['hint'] );
		}
		if ( array_key_exists( 'category_id', $input ) ) {
			$data['category_id'] = ! empty( $input['category_id'] ) ? (int) $input['category_id'] : null;
		}

		$updated = false;
		if ( ! empty( $data ) ) {
			$updated = \Learnomy\Models\Question::update( $question_id, $data );
		}

		// Replacing answers bumps the version so in-flight attempts keep the
		// version they were served (the editor + CSV path do the same).
		if ( is_array( $input['answers'] ?? null ) ) {
			\Learnomy\Models\Answer::delete_for_question( $question_id );
			$this->ability_save_answers( $question_id, $input['answers'] );
			\Learnomy\Models\Question::increment_version( $question_id );
			$updated = true;
		}

		if ( ! $updated ) {
			return new WP_Error( 'no_data', __( 'No fields provided to update.', 'learnomy' ) );
		}
		return [ 'updated' => true ];
	}

	public function execute_add_question_to_quiz( $input ) {
		$slot_id = \Learnomy\Models\QuizSlot::add_question(
			(int) $input['quiz_id'],
			(int) $input['question_id'],
			(int) ( $input['sort_order'] ?? 0 )
		);
		if ( ! $slot_id ) {
			return new WP_Error( 'add_failed', __( 'Failed to add the question to the quiz.', 'learnomy' ) );
		}
		return [ 'slot_id' => $slot_id ];
	}

	public function execute_update_quiz( $input ) {
		$quiz_id = (int) $input['quiz_id'];
		$data    = [];
		if ( isset( $input['title'] ) ) {
			$data['title'] = sanitize_text_field( $input['title'] );
		}
		foreach ( [ 'mode', 'feedback_timing', 'status' ] as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$data[ $key ] = sanitize_key( $input[ $key ] );
			}
		}
		if ( isset( $input['passing_score'] ) ) {
			$data['passing_score'] = max( 0, min( 100, (int) $input['passing_score'] ) );
		}
		if ( isset( $input['attempt_limit'] ) ) {
			$data['attempt_limit'] = max( 0, (int) $input['attempt_limit'] );
		}
		if ( empty( $data ) ) {
			return new WP_Error( 'no_data', __( 'No fields provided to update.', 'learnomy' ) );
		}
		return [ 'updated' => Quiz::update( $quiz_id, $data ) ];
	}

	public function execute_create_course_category( $input ) {
		$name = sanitize_text_field( $input['name'] );
		$id   = \Learnomy\Models\Category::create(
			[
				'name'      => $name,
				'slug'      => sanitize_title( $name ),
				'type'      => 'course',
				'parent_id' => (int) ( $input['parent_id'] ?? 0 ),
			]
		);
		if ( ! $id ) {
			return new WP_Error( 'create_failed', __( 'Failed to create category.', 'learnomy' ) );
		}
		return [
			'id'   => $id,
			'name' => $name,
			'slug' => sanitize_title( $name ),
		];
	}

	public function execute_create_question_category( $input ) {
		$name = sanitize_text_field( $input['name'] );
		$id   = \Learnomy\Models\QuestionCategory::create(
			[
				'name'      => $name,
				'slug'      => sanitize_title( $name ),
				'parent_id' => (int) ( $input['parent_id'] ?? 0 ),
			]
		);
		if ( ! $id ) {
			return new WP_Error( 'create_failed', __( 'Failed to create question category.', 'learnomy' ) );
		}
		return [
			'id'   => $id,
			'name' => $name,
			'slug' => sanitize_title( $name ),
		];
	}

	public function execute_upload_media( $input ) {
		$course_id = (int) $input['course_id'];
		$course    = Course::find( $course_id );
		if ( ! $course ) {
			return new WP_Error( 'not_found', __( 'Course not found', 'learnomy' ) );
		}

		$attachment_id = (int) ( $input['attachment_id'] ?? 0 );

		// No attachment id supplied — side-load the given URL into the Media
		// Library (same helpers wp-admin uploads use), then attach it.
		if ( ! $attachment_id && ! empty( $input['image_url'] ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$attachment_id = media_sideload_image( esc_url_raw( $input['image_url'] ), 0, null, 'id' );
			if ( is_wp_error( $attachment_id ) ) {
				return $attachment_id;
			}
			$attachment_id = (int) $attachment_id;
		}

		if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
			return new WP_Error( 'invalid_media', __( 'Provide a valid attachment_id or an image_url to side-load.', 'learnomy' ) );
		}

		( new Course_Service() )->update( $course_id, [ 'thumbnail_id' => $attachment_id ] );

		return [
			'attachment_id' => $attachment_id,
			'thumbnail_url' => (string) ( wp_get_attachment_image_url( $attachment_id, 'full' ) ?: '' ),
		];
	}

	public function execute_create_certificate_template( $input ) {
		$title = sanitize_text_field( $input['title'] );
		$data  = [
			'title'       => $title,
			'design_json' => isset( $input['design_json'] ) ? wp_kses_post( (string) $input['design_json'] ) : '',
			'orientation' => in_array( ( $input['orientation'] ?? 'landscape' ), [ 'landscape', 'portrait' ], true ) ? $input['orientation'] : 'landscape',
			'status'      => 'inactive' === ( $input['status'] ?? 'active' ) ? 'inactive' : 'active',
			'author_id'   => get_current_user_id(),
		];
		if ( isset( $input['expiration_days'] ) ) {
			$data['expiration_days'] = max( 0, (int) $input['expiration_days'] );
		}

		$id = \Learnomy\Models\CertificateTemplate::create( $data );
		if ( ! $id ) {
			return new WP_Error( 'create_failed', __( 'Failed to create certificate template.', 'learnomy' ) );
		}
		return [
			'id'    => $id,
			'title' => $title,
		];
	}

	/*
	──────────────────────────────────────────────
	 *  Reporting abilities (AI Phase 1 — Free foundation)
	 *
	 *  Read-only academy reporting. Instructor-scoped via Permission_Engine /
	 *  CourseInstructor ownership — an instructor only ever sees their own
	 *  courses' data; admins see site-wide. Every list/aggregate read reuses an
	 *  existing model aggregate (no per-row scans) and paginates.
	 * ────────────────────────────────────────────── */

	private function register_reporting_abilities(): void {

		// ── Get Analytics ────────────────────────────
		wp_register_ability(
			'learnomy/get-analytics',
			[
				'label'               => __( 'Get Analytics', 'learnomy' ),
				'description'         => __( 'Academy KPIs. With a course_id: that course\'s enrollment/completion/rating. Without: the caller\'s overview. Admins get site-wide totals + revenue, instructors get their own completion funnel + earnings.', 'learnomy' ),
				'category'            => 'learnomy-reporting',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => false,
					'properties' => [
						'course_id' => [
							'type'        => 'integer',
							'description' => 'Scope to one course (omit for the caller\'s overview).',
						],
					],
				],
				'execute_callback'    => [ $this, 'execute_get_analytics' ],
				'permission_callback' => function ( $input ) {
					$user_id   = get_current_user_id();
					$course_id = (int) ( $input['course_id'] ?? 0 );
					if ( ! $user_id ) {
						return false;
					}
					return $course_id > 0
						? Permission_Engine::can( $user_id, 'manage_course', $course_id )
						: Permission_Engine::can( $user_id, 'manage_own_courses' );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── Get Quiz Analytics ───────────────────────
		wp_register_ability(
			'learnomy/get-quiz-analytics',
			[
				'label'               => __( 'Get Quiz Analytics', 'learnomy' ),
				'description'         => __( 'Per-quiz attempt/pass statistics plus per-question difficulty (lower avg score ratio = harder).', 'learnomy' ),
				'category'            => 'learnomy-reporting',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'quiz_id' => [
							'type'        => 'integer',
							'description' => 'Quiz to analyze.',
							'required'    => true,
						],
					],
				],
				'execute_callback'    => [ $this, 'execute_get_quiz_analytics' ],
				'permission_callback' => function ( $input ) {
					return $this->ability_can_manage_quiz( (int) ( $input['quiz_id'] ?? 0 ) );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── List Students ────────────────────────────
		wp_register_ability(
			'learnomy/list-students',
			[
				'label'               => __( 'List Students', 'learnomy' ),
				'description'         => __( 'Paginated student roster with enrollment + completion counts. Scoped to the caller\'s courses (admins see all). Optional course_id + search.', 'learnomy' ),
				'category'            => 'learnomy-reporting',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => false,
					'properties' => [
						'course_id' => [
							'type'        => 'integer',
							'description' => 'Limit to one course.',
						],
						'search'    => [
							'type'        => 'string',
							'description' => 'Name/email search.',
						],
						'days_inactive' => [
							'type'        => 'integer',
							'minimum'     => 0,
							'maximum'     => 365,
							'default'     => 0,
							'description' => 'Only students with no per-course activity for N+ days (0 = off). Same canonical definition as the Engagement Alerts count and the roster filter.',
						],
						'page'      => [
							'type'    => 'integer',
							'minimum' => 1,
							'default' => 1,
						],
						'per_page'  => [
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 20,
						],
					],
				],
				'execute_callback'    => [ $this, 'execute_list_students' ],
				'permission_callback' => function () {
					$user_id = get_current_user_id();
					return $user_id && Permission_Engine::can( $user_id, 'manage_own_courses' );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── Get At-Risk Students ─────────────────────
		wp_register_ability(
			'learnomy/get-at-risk-students',
			[
				'label'               => __( 'Get At-Risk Students', 'learnomy' ),
				'description'         => __( 'Students enrolled in the caller\'s courses who have been inactive for a while. This is the early-intervention list.', 'learnomy' ),
				'category'            => 'learnomy-reporting',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => false,
					'properties' => [
						'days_inactive' => [
							'type'        => 'integer',
							'description' => 'Inactivity threshold in days.',
							'minimum'     => 1,
							'default'     => 14,
						],
						'limit'         => [
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 20,
						],
					],
				],
				'execute_callback'    => [ $this, 'execute_get_at_risk_students' ],
				'permission_callback' => function () {
					$user_id = get_current_user_id();
					return $user_id && Permission_Engine::can( $user_id, 'manage_own_courses' );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── Get Instructor Report ────────────────────
		wp_register_ability(
			'learnomy/get-instructor-report',
			[
				'label'               => __( 'Get Instructor Report', 'learnomy' ),
				'description'         => __( 'The caller\'s teaching summary: course/student totals, completion funnel, earnings, and at-risk count.', 'learnomy' ),
				'category'            => 'learnomy-reporting',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => false,
					'properties' => [
						'instructor_id' => [
							'type'        => 'integer',
							'description' => 'Admin-only: report for another instructor (defaults to the caller).',
						],
					],
				],
				'execute_callback'    => [ $this, 'execute_get_instructor_report' ],
				'permission_callback' => function () {
					$user_id = get_current_user_id();
					return $user_id && Permission_Engine::can( $user_id, 'manage_own_courses' );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);
	}

	/* ── Reporting helpers + execute callbacks ───── */

	/** Course IDs the user instructs; null for an admin (= unscoped / all). */
	private function ability_instructor_scope( int $user_id ): ?array {
		// Was junction-only, which quietly disagreed with every other surface:
		// it EXCLUDED a course author who has no lrn_course_instructors row, so
		// an instructor could be denied their own students through the API
		// while seeing them fine in wp-admin. Same question, one answer.
		return \Learnomy\Permissions\Permission_Engine::visible_course_ids( $user_id, 'report' );
	}

	public function execute_get_analytics( $input ) {
		$user_id   = get_current_user_id();
		$course_id = (int) ( $input['course_id'] ?? 0 );

		if ( $course_id > 0 ) {
			$course = Course::find( $course_id );
			if ( ! $course ) {
				return new WP_Error( 'not_found', __( 'Course not found', 'learnomy' ) );
			}
			// Distinct people on both sides so completion_rate is a real
			// "% of students" and agrees with student_count / active beside it
			// (was rows via count() — a multi-source learner skewed the base;
			// Basecamp 10109120366).
			$total     = Enrollment::count_distinct_students( [ $course_id ], [] );
			$completed = Enrollment::count_distinct_students( [ $course_id ], [ 'completed' ] );
			return [
				'scope'           => 'course',
				'course_id'       => $course_id,
				'student_count'   => (int) ( $course->student_count ?? 0 ),
				'active'          => Enrollment::count_active_for_course( $course_id ),
				'completed'       => $completed,
				'completion_rate' => $total > 0 ? round( ( $completed / $total ) * 100, 2 ) : 0.0,
				'avg_progress'    => Enrollment::avg_progress_for_course( $course_id ),
				'avg_rating'      => (float) ( $course->avg_rating ?? 0 ),
				'review_count'    => (int) ( $course->review_count ?? 0 ),
			];
		}

		// Overview — admin = site-wide, instructor = own funnel + earnings.
		if ( user_can( $user_id, 'manage_options' ) ) {
			// Distinct people site-wide so completion_rate agrees with the
			// distinct student counts (was rows; Basecamp 10109120366).
			$total_enrollments = Enrollment::count_distinct_students( [], [] );
			$completed         = Enrollment::count_distinct_students( [], [ 'completed' ] );
			return [
				'scope'             => 'site',
				'published_courses' => Course::count( [ 'status' => 'published' ] ),
				'total_enrollments' => $total_enrollments,
				'completed'         => $completed,
				'completion_rate'   => $total_enrollments > 0 ? round( ( $completed / $total_enrollments ) * 100, 2 ) : 0.0,
				'total_revenue'     => round( \Learnomy\Models\Transaction::total_completed_revenue(), 2 ),
			];
		}

		return [
			'scope'          => 'instructor',
			'funnel'         => Enrollment::completion_funnel_for_instructor( $user_id ),
			'total_earned'   => round( Commission::sum_for_instructor( $user_id ), 2 ),
			'this_month'     => round( Commission::sum_for_instructor_since( $user_id, gmdate( 'Y-m-01 00:00:00' ) ), 2 ),
			'total_students' => Course::sum_students_for_instructor( $user_id ),
		];
	}

	public function execute_get_quiz_analytics( $input ) {
		$quiz_id = (int) $input['quiz_id'];
		$quiz    = Quiz::find( $quiz_id );
		if ( ! $quiz ) {
			return new WP_Error( 'not_found', __( 'Quiz not found.', 'learnomy' ) );
		}

		$slots        = \Learnomy\Models\QuizSlot::get_for_quiz( $quiz_id );
		$question_ids = array_map( static fn( $s ) => (int) $s->question_id, $slots );
		$stats        = \Learnomy\Models\QuestionResponse::stats_for_questions( $question_ids );

		$questions = [];
		foreach ( $question_ids as $qid ) {
			$s           = $stats[ $qid ] ?? [
				'times_answered'  => 0,
				'avg_score_ratio' => 0,
			];
			$questions[] = [
				'question_id'     => $qid,
				'times_answered'  => (int) $s['times_answered'],
				'avg_score_ratio' => (float) $s['avg_score_ratio'],
				'difficulty'      => round( 1 - (float) $s['avg_score_ratio'], 4 ),
			];
		}

		return [
			'quiz_id'   => $quiz_id,
			'attempts'  => \Learnomy\Models\QuizAttempt::pass_stats_for_quiz( $quiz_id ),
			'questions' => $questions,
		];
	}

	public function execute_list_students( $input ) {
		$user_id   = get_current_user_id();
		$scope     = $this->ability_instructor_scope( $user_id );
		$page      = max( 1, (int) ( $input['page'] ?? 1 ) );
		$per_page  = min( 100, max( 1, (int) ( $input['per_page'] ?? 20 ) ) );
		$offset    = ( $page - 1 ) * $per_page;
		$search    = sanitize_text_field( $input['search'] ?? '' );
		$course_id = (int) ( $input['course_id'] ?? 0 );

		// A requested course must be inside the caller's scope (admins: any).
		if ( $course_id > 0 && null !== $scope && ! in_array( $course_id, $scope, true ) ) {
			return new WP_Error( 'forbidden', __( 'You do not manage that course.', 'learnomy' ), [ 'status' => 403 ] );
		}

		$days_inactive = min( 365, max( 0, (int) ( $input['days_inactive'] ?? 0 ) ) );

		$paged    = Enrollment::get_distinct_user_ids_paginated( $course_id, $per_page, $offset, $search, $scope, $days_inactive );
		$user_ids = (array) ( $paged['user_ids'] ?? [] );
		$total    = (int) ( $paged['total'] ?? 0 );
		$counts   = Enrollment::counts_for_users( $user_ids, $scope );

		$students = [];
		foreach ( $user_ids as $uid ) {
			$u = get_userdata( $uid );
			if ( ! $u ) {
				continue;
			}
			$c          = $counts[ $uid ] ?? [];
			$students[] = [
				'id'        => (int) $uid,
				'name'      => $u->display_name,
				'email'     => $u->user_email,
				'enrolled'  => (int) ( $c['enrolled'] ?? 0 ),
				'completed' => (int) ( $c['completed'] ?? 0 ),
			];
		}

		return [
			'students' => $students,
			'page'     => $page,
			'per_page' => $per_page,
			'total'    => $total,
			'has_more' => ( $offset + count( $user_ids ) ) < $total,
		];
	}

	public function execute_get_at_risk_students( $input ) {
		$user_id       = get_current_user_id();
		$days_inactive = max( 1, (int) ( $input['days_inactive'] ?? 14 ) );
		$limit         = min( 100, max( 1, (int) ( $input['limit'] ?? 20 ) ) );

		$rows     = Enrollment::at_risk_for_instructor( $user_id, $days_inactive, $limit );
		$students = [];
		foreach ( (array) $rows as $r ) {
			$students[] = [
				'user_id'      => (int) ( $r->user_id ?? 0 ),
				'name'         => (string) ( $r->student_display_name ?? '' ),
				'course_id'    => (int) ( $r->course_id ?? 0 ),
				'course_title' => (string) ( $r->course_title ?? '' ),
				'last_active'  => (string) ( $r->last_activity ?? '' ),
				'enrolled_at'  => (string) ( $r->enrolled_at ?? '' ),
			];
		}

		return [
			'days_inactive' => $days_inactive,
			'students'      => $students,
		];
	}

	public function execute_get_instructor_report( $input ) {
		$user_id       = get_current_user_id();
		$instructor_id = $user_id;

		// Admins may report on another instructor; everyone else = themselves.
		if ( ! empty( $input['instructor_id'] ) && user_can( $user_id, 'manage_options' ) ) {
			$instructor_id = (int) $input['instructor_id'];
		}

		$at_risk = Enrollment::at_risk_for_instructor( $instructor_id, 14, 100 );

		return [
			'instructor_id'  => $instructor_id,
			'total_students' => Course::sum_students_for_instructor( $instructor_id ),
			'funnel'         => Enrollment::completion_funnel_for_instructor( $instructor_id ),
			'total_earned'   => round( Commission::sum_for_instructor( $instructor_id ), 2 ),
			'pending'        => round( Commission::sum_pending_for_instructor( $instructor_id ), 2 ),
			'this_month'     => round( Commission::sum_for_instructor_since( $instructor_id, gmdate( 'Y-m-01 00:00:00' ) ), 2 ),
			'at_risk_count'  => is_array( $at_risk ) ? count( $at_risk ) : 0,
		];
	}
}
