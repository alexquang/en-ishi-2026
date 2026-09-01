<?php
/**
 * Lessons REST controller — lesson CRUD, completion, and progress recording.
 *
 * @package Learnomy
 */

namespace Learnomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Learnomy\CompletionRulesNotMet;
use Learnomy\LessonNotReachable;
use Learnomy\Models\Course;
use Learnomy\Models\Section;
use Learnomy\Models\Lesson;
use Learnomy\Models\Lesson_Unlock;
use Learnomy\Models\Quiz;
use Learnomy\Models\Tag;
use Learnomy\Services\Lesson_Service;
use Learnomy\Services\Progress_Service;
use Learnomy\Services\Quiz_Service;

class Lessons_Controller extends Base_Controller {

	public function __construct() {
		parent::__construct();
		$this->rest_base = 'lessons';
	}

	public function register_routes(): void {
		// Register the attachments sub-resource alongside the lesson routes.
		// The Api class loads this file via its hardcoded list; wiring the
		// attachments controller here keeps REST registration in a single
		// pass without editing the Api class.
		( new Lesson_Attachments_Controller() )->register_routes();

		// Flat list — supports `?section_id=` and `?parent_only=1` for dependent
		// dropdowns (lesson editor's parent-lesson picker). Returns top-level
		// rows only when parent_only is set; otherwise all rows in the scope.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'list_lessons' ],
					'permission_callback' => [ $this, 'permission_logged_in' ],
					'args'                => [
						'course_id'   => [
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'description'       => __( 'Filter to lessons in a specific course.', 'learnomy' ),
						],
						'section_id'  => [
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'description'       => __( 'Filter to lessons in a specific section.', 'learnomy' ),
						],
						'parent_only' => [
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Return only top-level lessons (parent_id is NULL or 0).', 'learnomy' ),
						],
						'page'        => [
							'type'              => 'integer',
							'default'           => 1,
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
							'description'       => __( 'Page of picker results to return.', 'learnomy' ),
						],
						'per_page'    => [
							'type'              => 'integer',
							'default'           => 50,
							'minimum'           => 1,
							'maximum'           => 100,
							'sanitize_callback' => 'absint',
							'description'       => __( 'Picker results per page (max 100).', 'learnomy' ),
						],
					],
				],
			]
		);

		// Flat list of all lessons for a course.
		register_rest_route(
			$this->namespace,
			'/courses/(?P<course_id>[\d]+)/lessons',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_for_course' ],
					'permission_callback' => [ $this, 'permission_logged_in' ],
					'args'                => [
						'course_id'      => [
							'type'     => 'integer',
							'required' => true,
						],
						'per_page'       => [
							'type'    => 'integer',
							'default' => 50,
							'minimum' => 1,
							'maximum' => 100,
						],
						'page'           => [
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						],
						// Curriculum-tree callers (the mobile course screen) need the
						// sub-lessons too. Off by default: every other consumer of this
						// route expects top-level rows only.
						'include_topics' => [
							'type'    => 'boolean',
							'default' => false,
						],
					],
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_lesson' ],
					'permission_callback' => [ $this, 'can_write_for_course' ],
					'args'                => array_merge(
						[
							'course_id'  => [
								'type'     => 'integer',
								'required' => true,
							],
							'section_id' => [
								'type'    => 'integer',
								'default' => 0,
							],
						],
						$this->lesson_args()
					),
				],
			]
		);

		// Lessons scoped to a section.
		register_rest_route(
			$this->namespace,
			'/sections/(?P<section_id>[\d]+)/lessons',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_for_section' ],
					'permission_callback' => [ $this, 'permission_logged_in' ],
					'args'                => [
						'section_id' => [
							'type'     => 'integer',
							'required' => true,
						],
						'per_page'   => [
							'type'    => 'integer',
							'default' => 50,
							'minimum' => 1,
							'maximum' => 100,
						],
						'page'       => [
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						],
					],
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_lesson_in_section' ],
					'permission_callback' => [ $this, 'can_write_for_section' ],
					'args'                => array_merge(
						[
							'section_id' => [
								'type'     => 'integer',
								'required' => true,
							],
						],
						$this->lesson_args()
					),
				],
			]
		);

		// Single lesson routes.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_lesson' ],
					// NOT permission_logged_in. A lesson marked is_preview exists
					// precisely so a visitor can read it WITHOUT enrolling -- the
					// setting's own help text says "tick to let visitors watch or
					// read this lesson without enrolling" -- and requiring a login
					// made the feature unreachable for the audience it was built
					// for, which is people deciding whether to buy.
					//
					// The product already disagreed with itself: this route
					// answered 401 while /lessons/{id}/attachments answered 200 for
					// the same anonymous visitor on the same preview lesson. So the
					// files were readable and the lesson was not, which is the
					// wrong way round. (Basecamp 10154109319)
					'permission_callback' => [ $this, 'can_read_lesson' ],
					'args'                => [
						'id' => [
							'type'     => 'integer',
							'required' => true,
						],
					],
				],
				[
					'methods'             => 'PATCH',
					'callback'            => [ $this, 'update_lesson' ],
					'permission_callback' => [ $this, 'can_write_for_lesson' ],
					'args'                => array_merge(
						[
							'id' => [
								'type'     => 'integer',
								'required' => true,
							],
						],
						$this->lesson_args( false )
					),
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_lesson' ],
					'permission_callback' => [ $this, 'can_write_for_lesson' ],
					'args'                => [
						'id' => [
							'type'     => 'integer',
							'required' => true,
						],
					],
				],
			]
		);

		// Copy a lesson into a section — the reuse half of the Course
		// Builder's Attach Existing flow (Basecamp 10153330214). Attach is a
		// MOVE (course_id follows the section); this mints an independent row
		// so the source course keeps its lesson. can_write_for_lesson covers
		// BOTH sides: manage_course on the source lesson's course, and
		// require_parent_ownership on the section_id param for the target.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/copy',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'copy_lesson' ],
				'permission_callback' => [ $this, 'can_write_for_lesson' ],
				'args'                => [
					'id'         => [
						'type'     => 'integer',
						'required' => true,
					],
					// section_id 0 = the course root (the builder's "no
					// section" pool); course_id then names the target course.
					'section_id' => [
						'type'     => 'integer',
						'required' => true,
					],
					'course_id'  => [
						'type'    => 'integer',
						'default' => 0,
					],
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/sections/(?P<section_id>[\d]+)/lessons/reorder',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'reorder_lessons' ],
				'permission_callback' => [ $this, 'can_write_for_section' ],
				'args'                => [
					'section_id' => [
						'type'     => 'integer',
						'required' => true,
					],
					'order'      => [
						'type'     => 'array',
						'required' => true,
						'items'    => [ 'type' => 'integer' ],
					],
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/complete',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'mark_complete' ],
				'permission_callback' => [ $this, 'is_enrolled_student' ],
				'args'                => [
					'id'         => [
						'type'     => 'integer',
						'required' => true,
					],
					// CF-008: time the student spent on the lesson before clicking
					// Mark Complete. Stored in lrn_progress.time_spent_seconds (the
					// player's JS sends accumulated seconds from the session). If the
					// client omits this param the stored time is left as-is — the
					// /progress heartbeat has already been accumulating it throughout
					// the session, so the two paths are additive, not competing.
					'time_spent' => [
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'Seconds the student spent on this lesson during the current session. Accumulated into lrn_progress.time_spent_seconds.', 'learnomy' ),
					],
					'video_pct'  => [
						'type'        => 'integer',
						'minimum'     => 0,
						'maximum'     => 100,
						'description' => __( 'Live video watch percentage at click time. Used to satisfy the auto_video completion rule alongside the persisted high-water mark.', 'learnomy' ),
					],
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/progress',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'record_progress' ],
				'permission_callback' => [ $this, 'is_enrolled_student' ],
				'args'                => [
					'id'            => [
						'type'     => 'integer',
						'required' => true,
					],
					'time_seconds'  => [
						'type'    => 'integer',
						'minimum' => 0,
					],
					'watch_percent' => [
						'type'    => 'integer',
						'minimum' => 0,
						'maximum' => 100,
					],
					'pct'           => [
						'type'    => 'integer',
						'minimum' => 0,
						'maximum' => 100,
					],
				],
			]
		);

		// Per-student unlock datetime for relative-drip lessons. Returns
		// `{ data: { unlocks_at: ISO8601|null } }` so the frontend countdown
		// chip and the lesson-locked card can render the correct UTC moment
		// without recomputing it from the lesson + enrolment in JS.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/my-unlock',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_my_unlock' ],
				'permission_callback' => [ $this, 'can_read_progress' ],
				'args'                => [
					'id' => [
						'type'     => 'integer',
						'required' => true,
					],
				],
			]
		);

		// Read-only completion status — drives the progress strip rendered
		// above the Mark Complete button. Safe to poll every 10s while the
		// student has the tab focused because it hits the cached progress
		// row and never writes.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/completion-status',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_completion_status' ],
				'permission_callback' => [ $this, 'can_read_progress' ],
				'args'                => [
					'id'  => [
						'type'     => 'integer',
						'required' => true,
					],
					'pct' => [
						'type'        => 'integer',
						'minimum'     => 0,
						'maximum'     => 100,
						'description' => __( 'Client-reported latest video watch percent. Used for transient evaluation only.', 'learnomy' ),
					],
				],
			]
		);
	}

	// -------------------------------------------------------------------------
	// Callbacks
	// -------------------------------------------------------------------------

	/**
	 * May this viewer read THIS lesson?
	 *
	 * Delegates to the one rule that already owns the question --
	 * `Lesson_Service::user_can_read_body()` -- which the attachments route has
	 * been using all along. That rule already handles the private-academy gate,
	 * per-course visibility, a draft course (a free-preview lesson inside an
	 * unpublished course stays invisible), enrolment, authorship and admins.
	 *
	 * Reimplementing any of that here is how the two routes came to disagree in
	 * the first place.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function can_read_lesson( WP_REST_Request $request ): bool|WP_Error {
		$lesson = Lesson::find( (int) $request->get_param( 'id' ) );
		if ( ! $lesson ) {
			return $this->not_found( 'Lesson' );
		}

		if ( \Learnomy\Services\Lesson_Service::user_can_read_body( $lesson, get_current_user_id() ) ) {
			return true;
		}

		return $this->permission_error();
	}

	/**
	 * Flat lesson listing with course / section / parent filters.
	 *
	 * Drives the dependent-dropdown pattern on the lesson + quiz admin forms.
	 * Publishes only `id`, `title`, `slug`, `parent_id`, `section_id`,
	 * `course_id` — enough for the picker, without leaking draft content.
	 */
	public function list_lessons( WP_REST_Request $request ): WP_REST_Response {
		$course_id            = absint( $request->get_param( 'course_id' ) );
		$section_id           = absint( $request->get_param( 'section_id' ) );
		$parent_only          = (bool) $request->get_param( 'parent_only' );
		$unattached_to_course = absint( $request->get_param( 'unattached_to_course' ) );
		$search               = sanitize_text_field( (string) $request->get_param( 'search' ) );

		// Require at least one scope to avoid dumping every lesson. The
		// Course Builder "Attach Existing" modal supplies
		// `unattached_to_course` + `search` so it can find lessons that
		// aren't already in the current course (bug card #7).
		if (
			$course_id <= 0
			&& $section_id <= 0
			&& ! $parent_only
			&& $unattached_to_course <= 0
		) {
			return $this->error_response(
				$this->validation_error( __( 'At least one of course_id, section_id, parent_only, or unattached_to_course is required.', 'learnomy' ) )
			);
		}

		$filters = [
			'course_id'            => $course_id,
			'section_id'           => $section_id,
			'parent_only'          => $parent_only,
			'unattached_to_course' => $unattached_to_course,
			'search'               => $search,
		];

		// Cap the picker so a 5k-lesson course never streams every row into the
		// modal. The total comes from count_for_picker so the page still knows
		// how many candidates exist and can offer paging.
		$per_page = min( 100, max( 1, absint( $request->get_param( 'per_page' ) ) ?: 50 ) );
		$offset   = $this->resolve_offset( $request, (int) $per_page );

		$rows  = Lesson::list_for_picker( $filters, $per_page, $offset );
		$total = Lesson::count_for_picker( $filters );

		return $this->paginated_response( $rows, $total, $offset );
	}

	public function get_for_course( WP_REST_Request $request ): WP_REST_Response {
		$course_id = (int) $request->get_param( 'course_id' );
		$course    = Course::find( $course_id );
		if ( ! $course ) {
			return $this->error_response( $this->not_found( 'Course' ) );
		}

		if ( ! $this->can_view_course_content( $course ) ) {
			return $this->error_response( $this->not_found( 'Course' ) );
		}

		$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 50 ) );
		$offset   = $this->resolve_offset( $request, (int) $per_page );

		// Sub-lessons are opt-in: a curriculum tree wants them, every other caller
		// of this route wants top-level rows only. Count and rows read the SAME
		// flag so "N of M" can never disagree with what was returned.
		$include_topics = (bool) $request->get_param( 'include_topics' );

		// Use a dedicated COUNT(*) so the total is accurate for the full
		// curriculum even when the client requests a single page of lessons.
		$total   = Lesson::count_for_course( $course_id, 'published', $include_topics );
		$lessons = Lesson::get_for_course( $course_id, $per_page, $offset, 'published', $include_topics );

		// Dual-format read (R1/INV-8): list rows must match the single-GET — the
		// `content` field stays Editor.js JSON (+ content_html) whatever the row
		// stores, so the app never sees raw HTML in a JSON field after the cutover.
		$lessons = \Learnomy\Content\Content_HTML::normalize_rows( $lessons, 'content' );

		// Viewer enrichment (WI-4): per-lesson completion for the curriculum
		// tree — the app's ✅/▶ row states. ONE query for the whole course via
		// Progress::get_all_for_course (big-site rule 3: never per-row).
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			$progress_map = [];
			foreach ( \Learnomy\Models\Progress::get_all_for_course( $user_id, $course_id ) as $row ) {
				$progress_map[ (int) $row->item_id ] = $row;
			}

			// Sequential/lock-next state for the whole course in ONE walk. Drip is
			// a SEPARATE gate — see below — so both are asked, never one.
			$locked_ids = Lesson_Service::locked_lesson_ids( $course_id, $user_id );

			foreach ( $lessons as $i => $lesson ) {
				$lesson_id = (int) ( is_array( $lesson ) ? $lesson['id'] : $lesson->id );
				$progress  = $progress_map[ $lesson_id ] ?? null;
				$row       = is_array( $lesson ) ? (object) $lesson : $lesson;

				// RESOLVED lock state, not the raw gate columns.
				//
				// A lesson can gate on drip mode, a per-student unlock row, a
				// section date, course-level require_sequential, or its
				// predecessor's lock_next_until_complete — in any combination a
				// site owner chooses. A native client cannot evaluate that: it is
				// one binary across every site, and a gate added in a later
				// release would render as "open" on a phone already in someone's
				// pocket. So the server answers, and the client renders.
				//
				// is_row_available_to() rather than is_available_to(): we are
				// holding the row, and the by-id entry point re-reads it once per
				// lesson (Basecamp 10119142606). resolve_unlocks_at() is this
				// controller's existing helper — the same one GET
				// /lessons/{id}/my-unlock uses, so the countdown a learner sees in
				// the curriculum and the one the single-lesson route reports can
				// never disagree.
				$drip_open   = Lesson_Service::is_row_available_to( $row, $user_id );
				$seq_locked  = isset( $locked_ids[ $lesson_id ] );
				$unlocks_at  = $drip_open ? null : $this->resolve_unlocks_at( $row, $user_id );
				$lock_reason = null;
				if ( ! $drip_open ) {
					$lock_reason = 'drip';
				} elseif ( $seq_locked ) {
					$lock_reason = 'sequential';
				}

				$viewer = [
					'is_completed'      => null !== $progress && 'completed' === ( $progress->status ?? '' ),
					'video_watch_pct'   => null !== $progress ? (int) ( $progress->video_watch_pct ?? 0 ) : 0,
					'is_locked'         => ( ! $drip_open || $seq_locked ),
					'lock_reason'       => $lock_reason,
					'unlocks_at'        => $unlocks_at,
					// Translated HERE, in the site's language, because the client
					// cannot phrase a reason it does not model.
					'lock_message'      => $this->lock_message( $lock_reason, $unlocks_at ),
					'requirement_label' => $this->requirement_label( $row ),
				];

				if ( is_array( $lesson ) ) {
					$lessons[ $i ]['viewer'] = $viewer;
				} else {
					$lessons[ $i ]->viewer = $viewer;
				}
			}

			$lessons = $this->attach_lesson_quizzes( $lessons, $course_id, $user_id );
		}

		return $this->paginated_response( $lessons, $total, $offset );
	}


	/**
	 * Hang each lesson's quizzes off the lesson, with their own resolved lock
	 * state.
	 *
	 * The student curriculum route returned `viewer{}` for lessons and no
	 * quizzes at all - not nested, not at top level - so an app could not draw a
	 * course's quizzes in the curriculum, and could only discover that a quiz
	 * was locked by starting it and catching the 422 (Basecamp 10184327427).
	 * The web player has shown a padlock on those rows since 1.9.2 because the
	 * lock is server-resolved; the app had no way to ask.
	 *
	 * Both answers are reused rather than re-derived. `Quiz::split_by_lesson()`
	 * is the same splitter the three web surfaces share, so a quiz can never
	 * appear under a different lesson here than it does on the site; and
	 * `Quiz_Service::lesson_lock_applies()` is the single predicate behind the
	 * server gate and the padlock, so a client cannot disagree with the route
	 * that would refuse the attempt.
	 *
	 * A quiz whose lesson is locked reports the LESSON's lock verbatim - reason,
	 * unlock time and translated message - because that is precisely why the
	 * quiz is shut, and phrasing it a second way here would be a second opinion.
	 *
	 * @param array $lessons   Lesson rows already carrying `viewer`.
	 * @param int   $course_id Course.
	 * @param int   $user_id   Viewer.
	 * @return array
	 */
	private function attach_lesson_quizzes( array $lessons, int $course_id, int $user_id ): array {
		$lesson_ids = array();
		foreach ( $lessons as $lesson ) {
			$lesson_ids[] = (int) ( is_array( $lesson ) ? $lesson['id'] : $lesson->id );
		}

		$quizzes = Quiz::get_for_course( $course_id );
		$split   = Quiz::split_by_lesson( $quizzes, $lesson_ids );

		foreach ( $lessons as $i => $lesson ) {
			$lesson_id     = (int) ( is_array( $lesson ) ? $lesson['id'] : $lesson->id );
			$lesson_viewer = (array) ( is_array( $lesson ) ? ( $lesson['viewer'] ?? array() ) : ( $lesson->viewer ?? array() ) );
			$rows          = $split['by_lesson'][ $lesson_id ] ?? array();

			$out = array();
			foreach ( $rows as $quiz ) {
				$inherits = Quiz_Service::lesson_lock_applies( $quiz, $user_id );
				$locked   = $inherits && ! empty( $lesson_viewer['is_locked'] );

				$item           = Quiz::to_typed_array( $quiz ) ?? array();
				$item['viewer'] = array(
					'is_locked'    => $locked,
					'lock_reason'  => $locked ? ( $lesson_viewer['lock_reason'] ?? 'lesson_locked' ) : null,
					'unlocks_at'   => $locked ? ( $lesson_viewer['unlocks_at'] ?? null ) : null,
					'lock_message' => $locked ? ( $lesson_viewer['lock_message'] ?? '' ) : '',
				);

				$out[] = $item;
			}

			// Always present, even when empty: a client that has to distinguish
			// "no quizzes" from "the key is missing" ends up guessing.
			if ( is_array( $lesson ) ) {
				$lessons[ $i ]['quizzes'] = $out;
			} else {
				$lessons[ $i ]->quizzes = $out;
			}
		}

		return $lessons;
	}

	public function get_for_section( WP_REST_Request $request ): WP_REST_Response {
		$section_id = (int) $request->get_param( 'section_id' );
		$section    = Section::find( $section_id );
		if ( ! $section ) {
			return $this->error_response( $this->not_found( 'Section' ) );
		}

		$course = Course::find( (int) $section->course_id );
		if ( ! $course || ! $this->can_view_course_content( $course ) ) {
			return $this->error_response( $this->not_found( 'Section' ) );
		}

		$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 50 ) );
		$offset   = $this->resolve_offset( $request, (int) $per_page );

		// COUNT(*) for accuracy; avoid counting in-memory arrays.
		$total   = Lesson::count_for_section( $section_id );
		$lessons = Lesson::get_for_section( $section_id, $per_page, $offset );

		// Dual-format read (R1/INV-8) — see get_for_course.
		$lessons = \Learnomy\Content\Content_HTML::normalize_rows( $lessons, 'content' );

		return $this->paginated_response( $lessons, $total, $offset );
	}

	public function get_lesson( WP_REST_Request $request ): WP_REST_Response {
		$lesson = Lesson::find( (int) $request->get_param( 'id' ) );
		if ( ! $lesson ) {
			return $this->error_response( $this->not_found( 'Lesson' ) );
		}

		// Preview lessons are public; others require course access.
		$course = Course::find( (int) $lesson->course_id );
		if ( ! $lesson->is_preview ) {
			if ( ! $course || ! $this->can_view_course_content( $course ) ) {
				return $this->error_response( $this->permission_error() );
			}
		}

		// Entitlement passed is not the same as reachable. The check above asks
		// only "is this person enrolled", so an enrolled student received the full
		// body of a lesson the player had locked behind a drip date — text, HTML,
		// plain text AND the video URL, with `available_date` sitting in the same
		// response announcing the lock (Basecamp 10143220266).
		//
		// A 403 would be the wrong answer: the app has to render the locked row
		// (title, duration, unlock date) exactly as the curriculum does, and
		// erroring would cost it a second request for data it just had. So the
		// response stays 200 and loses its body fields, which is also the shape
		// the card asked for.
		//
		// `locked` is computed BEFORE the ETag on purpose. It is part of what the
		// response says, and it changes for one user without the row changing —
		// leaving it out would let a 304 replay a pre-unlock body after the drip
		// date passed, and (worse) hand a cached body to the next reader.
		$user_id   = get_current_user_id();
		$is_locked = $course && ! Lesson_Service::user_can_read_body( $lesson, $user_id, $course );

		// Viewer + live enrichment (mobile-API contract WI-4). Both participate
		// in the ETag: completing the lesson (viewer) or the session clock
		// crossing start/end (live.state) must invalidate a cached 304.
		$viewer = null;
		if ( $user_id > 0 ) {
			$progress = \Learnomy\Models\Progress::get_for_user_and_item( $user_id, 'lesson', (int) $lesson->id );
			$viewer   = [
				'is_completed'    => null !== $progress && 'completed' === ( $progress->status ?? '' ),
				'video_watch_pct' => null !== $progress ? (int) ( $progress->video_watch_pct ?? 0 ) : 0,
			];
		}
		// Server-computed session state — the app renders countdown → Join →
		// "ended" from this without trusting the device clock. Same helper the
		// lesson-player template uses (one computation, no drift).
		$live = 'live' === (string) ( $lesson->content_type ?? '' )
			? \Learnomy\live_session_state( $lesson )
			: null;

		$etag         = $this->generate_etag( [ $lesson, $viewer, $live, $is_locked ] );
		$not_modified = $this->maybe_not_modified( $etag, $request );
		if ( $not_modified ) {
			return $not_modified;
		}

		$filtered           = $this->filter_fields( Lesson::to_typed_array( $lesson ) ?? [], $request );
		$filtered['viewer'] = $viewer;
		if ( null !== $live ) {
			$filtered['live'] = $live;
		}

		// Locked: metadata only. `locked` is always present so a client can trust
		// its absence to mean nothing rather than having to infer the state from
		// which fields came back empty.
		$filtered['locked'] = $is_locked;
		if ( $is_locked ) {
			$filtered = Lesson_Service::strip_body_fields( $filtered );
		}

		// Dual-format read (INV-8 / R1): the REST `content` field always returns
		// Editor.js JSON, normalised from whatever the row stores — so the app
		// and the Pro builder keep parsing JSON even after a site flips storage
		// to HTML. content_plain stays the search projection.
		if ( isset( $filtered['content'] ) && is_string( $filtered['content'] ) ) {
			$raw                      = $filtered['content'];
			$filtered['content']      = \Learnomy\Content\Content_HTML::for_editor( $raw );  // Editor.js JSON — the app today.
			$filtered['content_html'] = \Learnomy\Content\Content_HTML::for_storage( $raw );  // Canonical HTML — the app can adopt this (Phase 6) on its own schedule.
		}

		/**
		 * Filter the single-lesson REST payload.
		 *
		 * A content type registered by a Pro module often needs one extra field
		 * before a client can play it — the way `live` carries its server-computed
		 * session state above. Without a seam, either Free learns about every such
		 * type, or the client builds URLs out of raw columns it cannot validate.
		 *
		 * H5P is the worked example: the lesson row stores a NUMERIC H5P content
		 * id in `video_url`, which is useless to an app on its own, and only the
		 * H5P module knows how to turn it into something loadable.
		 *
		 * Add keys. Do not remove or rewrite the ones already here.
		 *
		 * @since 1.9.2
		 *
		 * @param array            $filtered The response payload.
		 * @param object           $lesson   The lesson row.
		 * @param \WP_REST_Request $request  The request.
		 */
		$filtered = (array) apply_filters( 'learnomy_rest_lesson_payload', $filtered, $lesson, $request );

		$response = $this->single_response( $filtered );

		// Per-user (viewer progress + live state): revalidate every read so a
		// just-completed lesson is never served stale (Zoho #40979).
		return $this->add_etag( $response, $etag, true );
	}

	public function create_lesson( WP_REST_Request $request ): WP_REST_Response {
		$course_id  = (int) $request->get_param( 'course_id' );
		$section_id = (int) $request->get_param( 'section_id' );

		$course = Course::find( $course_id );
		if ( ! $course ) {
			return $this->error_response( $this->not_found( 'Course' ) );
		}

		$data = $this->extract_lesson_fields( $request );
		if ( isset( $data['_drip_error'] ) ) {
			return $this->error_response( $data['_drip_error'] );
		}
		$data['course_id'] = $course_id;

		if ( $section_id > 0 ) {
			$data['section_id'] = $section_id;
		}

		$error = $this->validate_placement( $data );
		if ( $error ) {
			return $this->error_response( $error );
		}

		$lesson_id = Lesson::create( $data );
		if ( ! $lesson_id ) {
			return $this->error_response(
				$this->validation_error( __( 'Could not create lesson. Please try again.', 'learnomy' ) )
			);
		}

		$this->maybe_sync_lesson_tags( $request, $lesson_id );

		do_action( 'learnomy_lesson_created', $lesson_id, $course_id, $data );
		/** Documented in create_lesson_in_section(). */
		do_action( 'learnomy_lesson_saved', $lesson_id, $request );

		$response = $this->single_response( Lesson::to_typed_array( Lesson::find( $lesson_id ) ) );
		$response->set_status( 201 );

		return $response;
	}

	public function create_lesson_in_section( WP_REST_Request $request ): WP_REST_Response {
		$section_id = (int) $request->get_param( 'section_id' );
		$section    = Section::find( $section_id );
		if ( ! $section ) {
			return $this->error_response( $this->not_found( 'Section' ) );
		}

		$data = $this->extract_lesson_fields( $request );
		if ( isset( $data['_drip_error'] ) ) {
			return $this->error_response( $data['_drip_error'] );
		}
		$data['course_id']  = (int) $section->course_id;
		$data['section_id'] = $section_id;

		$error = $this->validate_placement( $data );
		if ( $error ) {
			return $this->error_response( $error );
		}

		$lesson_id = Lesson::create( $data );
		if ( ! $lesson_id ) {
			return $this->error_response(
				$this->validation_error( __( 'Could not create lesson. Please try again.', 'learnomy' ) )
			);
		}

		$this->maybe_sync_lesson_tags( $request, $lesson_id );

		do_action( 'learnomy_lesson_created', $lesson_id, (int) $section->course_id, $data );
		/**
		 * Fires after a lesson is created or updated via REST, carrying the
		 * request, so an integration can persist its own lesson fields (rendered
		 * via learnomy_lesson_editor_fields). Covers sub-lessons (same editor).
		 *
		 * @param int             $lesson_id The saved lesson.
		 * @param WP_REST_Request $request   The create/update request.
		 */
		do_action( 'learnomy_lesson_saved', $lesson_id, $request );

		$response = $this->single_response( Lesson::to_typed_array( Lesson::find( $lesson_id ) ) );
		$response->set_status( 201 );

		return $response;
	}

	public function update_lesson( WP_REST_Request $request ): WP_REST_Response {
		$lesson = Lesson::find( (int) $request->get_param( 'id' ) );
		if ( ! $lesson ) {
			return $this->error_response( $this->not_found( 'Lesson' ) );
		}

		$data = $this->extract_lesson_fields( $request, $lesson );

		// Drip schedule sentinel — extract_lesson_fields() flags an unparseable
		// available_date here so we can return a structured 422 instead of
		// silently dropping the field.
		if ( isset( $data['_drip_error'] ) ) {
			return $this->error_response( $data['_drip_error'] );
		}

		// A tags-only PATCH carries no column data but is still a valid update.
		$has_tags = $request->has_param( 'tag_ids' );
		if ( empty( $data ) && ! $has_tags ) {
			return $this->error_response(
				$this->validation_error( __( 'No valid fields provided for update.', 'learnomy' ) )
			);
		}

		// `content_plain` regenerates in Lesson::search_projections() on every
		// write, so no per-controller derivation here.

		// Resolve slug uniqueness within the (possibly new) course, keeping this
		// lesson's own slug rather than bumping it to `-2`. create() does the
		// equivalent for new lessons.
		if ( isset( $data['slug'] ) ) {
			$scope_course = isset( $data['course_id'] ) ? (int) $data['course_id'] : (int) $lesson->course_id;
			$data['slug'] = Lesson::unique_slug( $scope_course, (string) $data['slug'], (int) $lesson->id );
			if ( '' === $data['slug'] ) {
				unset( $data['slug'] );
			}
		}

		// Validate any placement changes against the existing row so a PATCH
		// that only updates parent_id still resolves course/section correctly.
		if ( isset( $data['course_id'] ) || isset( $data['section_id'] ) || array_key_exists( 'parent_id', $data ) ) {
			$error = $this->validate_placement( $data, $lesson );
			if ( $error ) {
				return $this->error_response( $error );
			}
		}

		if ( ! empty( $data ) ) {
			Lesson::update( (int) $lesson->id, $data );
		}

		$this->maybe_sync_lesson_tags( $request, (int) $lesson->id );

		do_action( 'learnomy_lesson_updated', (int) $lesson->id, $data );
		/** Documented in create_lesson_in_section(). */
		do_action( 'learnomy_lesson_saved', (int) $lesson->id, $request );

		return $this->single_response( Lesson::to_typed_array( Lesson::find( (int) $lesson->id ) ) );
	}

	/**
	 * Replace a lesson's tag-map rows when the request carries a `tag_ids`
	 * array. Absent param = leave tags untouched (so non-tag PATCHes don't
	 * clear them); an empty array clears them.
	 */
	private function maybe_sync_lesson_tags( WP_REST_Request $request, int $lesson_id ): void {
		if ( $lesson_id <= 0 || ! $request->has_param( 'tag_ids' ) ) {
			return;
		}
		$tag_ids = $request->get_param( 'tag_ids' );
		Tag::sync_object_tags( $lesson_id, 'lesson', is_array( $tag_ids ) ? $tag_ids : array() );
	}

	/**
	 * Copy a lesson into a section (POST /lessons/{id}/copy). Returns the NEW
	 * lesson, 201 — the source row is untouched, which is the whole point.
	 */
	public function copy_lesson( WP_REST_Request $request ): WP_REST_Response {
		$lesson = Lesson::find( (int) $request->get_param( 'id' ) );
		if ( ! $lesson ) {
			return $this->error_response( $this->not_found( 'Lesson' ) );
		}

		$section_id = (int) $request->get_param( 'section_id' );
		$course_id  = (int) $request->get_param( 'course_id' );
		if ( $section_id > 0 && ! Section::find( $section_id ) ) {
			return $this->error_response( $this->not_found( 'Section' ) );
		}
		if ( $section_id <= 0 && ( $course_id <= 0 || ! Course::find( $course_id ) ) ) {
			return $this->error_response( $this->not_found( 'Course' ) );
		}

		$new_id = ( new \Learnomy\Services\Lesson_Service() )->copy_to_section( (int) $lesson->id, $section_id, $course_id );
		if ( ! $new_id ) {
			return $this->error_response(
				$this->validation_error( __( 'Could not copy the lesson. Please try again.', 'learnomy' ) )
			);
		}

		$response = $this->single_response( Lesson::to_typed_array( Lesson::find( $new_id ) ) );
		$response->set_status( 201 );

		return $response;
	}

	public function delete_lesson( WP_REST_Request $request ): WP_REST_Response {
		$lesson = Lesson::find( (int) $request->get_param( 'id' ) );
		if ( ! $lesson ) {
			return $this->error_response( $this->not_found( 'Lesson' ) );
		}

		$course_id = (int) $lesson->course_id;

		// Single chokepoint: Lesson_Service::delete() removes the row AND fires
		// `learnomy_lesson_deleted` so extension cleanup runs. The course-delete
		// cascade routes through the same method, so both paths stay in lockstep.
		Lesson_Service::delete( (int) $lesson->id, $course_id );

		return $this->single_response(
			[
				'deleted' => true,
				'id'      => (int) $lesson->id,
			]
		);
	}

	public function reorder_lessons( WP_REST_Request $request ): WP_REST_Response {
		$section_id = (int) $request->get_param( 'section_id' );
		$order      = $request->get_param( 'order' );

		Lesson::reorder( $section_id, $order );

		do_action( 'learnomy_lessons_reordered', $section_id, $order );

		return $this->single_response( [ 'reordered' => true ] );
	}

	public function mark_complete( WP_REST_Request $request ): WP_REST_Response {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $this->error_response( $user_id );
		}

		$lesson = Lesson::find( (int) $request->get_param( 'id' ) );
		if ( ! $lesson ) {
			return $this->error_response( $this->not_found( 'Lesson' ) );
		}

		$service = new Progress_Service();

		// CF-008: plumb the client-reported session time into mark_lesson_complete
		// so it lands in lrn_progress.time_spent_seconds alongside the completion.
		// null → 0 means "client didn't send it" — service treats 0 as "no-op".
		$time_spent = null !== $request->get_param( 'time_spent' )
			? absint( $request->get_param( 'time_spent' ) )
			: 0;

		// Client-side completion signals — the lesson player passes
		// `scrolled_to_end=true` here when the scroll-to-end auto-fire path
		// triggers `actions.completeLesson()`. The service forwards it to the
		// rule evaluator so the scroll requirement (which the server has no
		// way to observe on its own) can be marked met for this request.
		// See Basecamp #9925249672.
		$client_signals = array(
			'scrolled_to_end' => (bool) $request->get_param( 'scrolled_to_end' ),
			'video_pct'       => max( 0, min( 100, (int) $request->get_param( 'video_pct' ) ) ),
		);

		try {
			$service->mark_lesson_complete( $user_id, (int) $lesson->id, false, $time_spent, $client_signals );
		} catch ( LessonNotReachable $e ) {
			// 403, not 422. The lesson is shut, so there is no requirements list
			// for the client to render and usually nothing the student can do
			// today — a "0 of 1 met" toast for a date lock sends them hunting for
			// a control that does not exist. `reason` lets the client say either
			// "unlocks later" or "finish the previous lesson first".
			return new WP_REST_Response(
				array(
					'error' => array(
						'code'    => 'lesson_not_reachable',
						'message' => 'sequence' === $e->get_reason()
							? __( 'Finish the earlier lessons first.', 'learnomy' )
							: __( 'This lesson has not unlocked yet.', 'learnomy' ),
						'details' => array(
							'lesson_id' => $e->get_lesson_id(),
							'reason'    => $e->get_reason(),
						),
					),
				),
				403
			);
		} catch ( CompletionRulesNotMet $e ) {
			// Structured 422 — the client uses `details.requirements` to render
			// the "X of Y met" toast and keep the strip in sync.
			$response = new WP_REST_Response(
				[
					'error' => [
						'code'    => 'completion_rules_not_met',
						'message' => __( 'Completion requirements are not yet met for this lesson.', 'learnomy' ),
						'details' => $e->get_status(),
					],
				],
				422
			);
			return $response;
		}

		$progress_pct = $service->calculate_course_progress( $user_id, (int) $lesson->course_id );

		// progress-view.js auto-advance reads completed_count + total_count + next_lesson_url.
		// These were missing from the response, silently breaking the next-lesson redirect.
		$counts          = $this->compute_completion_counts( $user_id, (int) $lesson->course_id );
		$next_lesson_url = $this->resolve_next_lesson_url( (int) $lesson->id, (int) $lesson->course_id );

		return $this->single_response(
			[
				'lesson_id'       => (int) $lesson->id,
				'completed'       => true,
				'progress_pct'    => $progress_pct,
				'completed_count' => $counts['completed'],
				'total_count'     => $counts['total'],
				'next_lesson_url' => $next_lesson_url,
			]
		);
	}

	/**
	 * Returns ['completed' => N, 'total' => M] for a course's lessons.
	 *
	 * Used by mark_complete responses so progress-view.js can render the
	 * "X of Y lessons" pill without a second round-trip.
	 *
	 * @return array{completed:int,total:int}
	 */
	private function compute_completion_counts( int $user_id, int $course_id ): array {
		// Count top-level lessons with a dedicated COUNT(*) instead of pulling the
		// whole ordered curriculum just to count it (big-site checklist #4).
		// count_for_course() applies the identical top-level WHERE clause as
		// get_for_course(), so the total is unchanged.
		$total = Lesson::count_for_course( $course_id );

		if ( 0 === $total ) {
			return [
				'completed' => 0,
				'total'     => 0,
			];
		}

		$progress_rows = \Learnomy\Models\Progress::get_all_for_course( $user_id, $course_id );
		$completed     = count(
			array_filter(
				$progress_rows,
				static fn( $p ) => 'completed' === $p->status
			)
		);

		return [
			'completed' => $completed,
			'total'     => $total,
		];
	}

	/**
	 * Returns the permalink of the lesson immediately following $current_lesson_id
	 * in sort_order, or null if $current_lesson_id is the last lesson in the course.
	 *
	 * Uses Lesson::find_next_after() — a single O(1) DB query — instead of
	 * loading the entire course curriculum into memory on every /complete call.
	 */
	private function resolve_next_lesson_url( int $current_lesson_id, int $course_id ): ?string {
		$course = Course::find( $course_id );
		if ( ! $course ) {
			return null;
		}

		// Walk the FLATTENED sequence (parent → its sub-lessons → next lesson),
		// not just top-level lessons — otherwise completing a parent lesson
		// auto-advances past its own sub-lessons. Sub-lessons are standalone
		// player pages, so each gets its own permalink.
		$next = Lesson::find_next_in_sequence( $current_lesson_id, $course_id );
		if ( ! $next ) {
			return null;
		}

		return Lesson::get_permalink( $next, $course );
	}

	/**
	 * Live completion status for the student-facing progress strip.
	 *
	 * Evaluates the lesson's `completion_mode` against the student's stored
	 * time-on-page / video-watch state and returns the requirement list the
	 * Interactivity store renders. A `pct` query arg lets the client feed its
	 * locally-tracked `highestPercent` in — we don't persist it, it's only
	 * used for this one evaluation so the video requirement reflects the
	 * live session without a round-trip through the progress write path.
	 */
	public function get_completion_status( WP_REST_Request $request ): WP_REST_Response {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $this->error_response( $user_id );
		}

		$lesson = Lesson::find( (int) $request->get_param( 'id' ) );
		if ( ! $lesson ) {
			return $this->error_response( $this->not_found( 'Lesson' ) );
		}

		$video_pct = null !== $request->get_param( 'pct' )
			? (int) $request->get_param( 'pct' )
			: 0;

		$service = new Progress_Service();
		$status  = $service->evaluate_completion_rules( $user_id, (int) $lesson->id, $video_pct );

		// Drip schedule overlay — admins / authors / lessons without a drip
		// always report `locked: false`. The `unlocks_at` value is the raw
		// stored UTC datetime; clients render the countdown / format it for
		// the site timezone themselves.
		$status['locked']     = ! Lesson_Service::is_available_to( (int) $lesson->id, $user_id );
		$status['unlocks_at'] = empty( $lesson->available_date ) ? null : (string) $lesson->available_date;

		return $this->single_response( $status );
	}

	/**
	 * Per-student unlock datetime for the current student.
	 *
	 * Returns `{ data: { unlocks_at: ISO8601|null } }`:
	 *   - For a relative-drip lesson with a stored unlock row, the value is
	 *     the row's `unlocks_at` rendered as an ISO 8601 UTC string.
	 *   - For a fixed-date lesson, returns the lesson's own
	 *     `available_date` (also ISO) — the frontend then renders the same
	 *     countdown chip regardless of drip mode.
	 *   - Falls back to the section's `available_date` when neither the
	 *     lesson nor a per-student row supplies a value.
	 *   - Returns `null` when the lesson is available immediately or has
	 *     already unlocked for the student.
	 */
	public function get_my_unlock( WP_REST_Request $request ): WP_REST_Response {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $this->error_response( $user_id );
		}

		$lesson = Lesson::find( (int) $request->get_param( 'id' ) );
		if ( ! $lesson ) {
			return $this->error_response( $this->not_found( 'Lesson' ) );
		}

		// Already-available lessons — nothing to count down to.
		if ( Lesson_Service::is_available_to( (int) $lesson->id, $user_id ) ) {
			return $this->single_response( [ 'unlocks_at' => null ] );
		}

		$unlocks_at = $this->resolve_unlocks_at( $lesson, $user_id );

		return $this->single_response( [ 'unlocks_at' => $unlocks_at ] );
	}

	/**
	 * Resolve the ISO 8601 UTC unlock datetime for a lesson + user.
	 *
	 * Mirrors the resolution priority in `Lesson_Service::is_available_to`
	 * but returns the timestamp instead of a boolean. Used by
	 * `get_my_unlock`.
	 *
	 * @return string|null ISO 8601 UTC datetime, or null when no schedule applies.
	 */
	private function resolve_unlocks_at( object $lesson, int $user_id ): ?string {
		$drip_mode = isset( $lesson->drip_mode ) ? (string) $lesson->drip_mode : 'fixed';

		if ( 'relative' === $drip_mode ) {
			$unlock = Lesson_Unlock::find_for_user_lesson( $user_id, (int) $lesson->id );
			if ( $unlock && ! empty( $unlock->unlocks_at ) ) {
				return $this->iso_from_utc_mysql( (string) $unlock->unlocks_at );
			}
			return null;
		}

		if ( ! empty( $lesson->available_date ) ) {
			return $this->iso_from_utc_mysql( (string) $lesson->available_date );
		}

		if ( ! empty( $lesson->section_id ) ) {
			$section = Section::find( (int) $lesson->section_id );
			if ( $section && ! empty( $section->available_date ) ) {
				return $this->iso_from_utc_mysql( (string) $section->available_date );
			}
		}

		return null;
	}

	/**
	 * The sentence a learner reads instead of a bare padlock.
	 *
	 * Rendered server-side, in the site's language and timezone, because the
	 * client cannot phrase a reason it does not model — and a lock with no
	 * explanation is the dead end the curriculum exists to avoid.
	 *
	 * @param string|null $reason     'drip' | 'sequential' | null.
	 * @param string|null $unlocks_at ISO 8601 UTC, when known.
	 */
	private function lock_message( ?string $reason, ?string $unlocks_at ): ?string {
		if ( 'sequential' === $reason ) {
			return __( 'Finish the previous lesson to unlock this one.', 'learnomy' );
		}

		if ( 'drip' !== $reason ) {
			return null;
		}

		if ( empty( $unlocks_at ) ) {
			// Dripped, but the schedule cannot name a date — a relative drip with
			// no unlock row yet. Say the true thing rather than inventing one.
			return __( 'This lesson unlocks later in the course.', 'learnomy' );
		}

		try {
			$when = new \DateTimeImmutable( $unlocks_at );
		} catch ( \Exception $e ) {
			return __( 'This lesson unlocks later in the course.', 'learnomy' );
		}

		// Site timezone + site date format: the learner reads the date their
		// site owner configured, not UTC.
		return sprintf(
			/* translators: %s: date the lesson becomes available, e.g. "12 March 2026". */
			__( 'Available on %s', 'learnomy' ),
			wp_date( (string) get_option( 'date_format', 'F j, Y' ), $when->getTimestamp() )
		);
	}

	/**
	 * What it takes to complete this lesson, in a phrase.
	 *
	 * Derived from the lesson's own completion columns so a learner knows the
	 * bar before they start. Returns null for a plain mark-complete lesson,
	 * where a requirement line would be noise.
	 */
	private function requirement_label( object $lesson ): ?string {
		$pct = (int) ( $lesson->min_video_watch_pct ?? 0 );
		if ( $pct > 0 ) {
			return sprintf(
				/* translators: %d: percentage of the video that must be watched. */
				__( 'Watch %d%% of the video', 'learnomy' ),
				$pct
			);
		}

		$seconds = (int) ( $lesson->min_time_seconds ?? 0 );
		if ( $seconds > 0 ) {
			$minutes = (int) max( 1, round( $seconds / 60 ) );
			return sprintf(
				/* translators: %d: number of minutes that must be spent on the lesson. */
				_n( 'Spend %d minute on this lesson', 'Spend %d minutes on this lesson', $minutes, 'learnomy' ),
				$minutes
			);
		}

		if ( ! empty( $lesson->require_quiz_pass ) ) {
			return __( 'Pass the quiz to complete this lesson', 'learnomy' );
		}

		if ( ! empty( $lesson->require_scroll_end ) ) {
			return __( 'Read to the end of the lesson', 'learnomy' );
		}

		return null;
	}

	/**
	 * Convert a UTC `Y-m-d H:i:s` string into ISO 8601 with explicit UTC
	 * offset. Returns null when the input is unparseable so the caller can
	 * surface `unlocks_at: null` rather than a malformed datetime.
	 */
	private function iso_from_utc_mysql( string $utc_mysql ): ?string {
		try {
			$dt = new \DateTimeImmutable( $utc_mysql, new \DateTimeZone( 'UTC' ) );
			return $dt->format( 'c' );
		} catch ( \Exception $e ) {
			return null;
		}
	}

	public function record_progress( WP_REST_Request $request ): WP_REST_Response {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $this->error_response( $user_id );
		}

		$lesson = Lesson::find( (int) $request->get_param( 'id' ) );
		if ( ! $lesson ) {
			return $this->error_response( $this->not_found( 'Lesson' ) );
		}

		$service = new Progress_Service();

		// Time-on-page — accepted as `seconds` (new name used by the
		// Interactivity store), `time_seconds` (legacy), or `pct` for the
		// video-watch channel. Zero-second calls are no-ops.
		$seconds = 0;
		foreach ( [ 'time_seconds', 'seconds' ] as $key ) {
			$candidate = $request->get_param( $key );
			if ( null !== $candidate ) {
				$seconds = absint( $candidate );
				break;
			}
		}

		if ( $seconds > 0 ) {
			$service->record_time( $user_id, (int) $lesson->id, $seconds );
		}

		$watch_pct = $request->get_param( 'pct' );
		if ( null === $watch_pct ) {
			$watch_pct = $request->get_param( 'watch_percent' );
		}
		$watch_pct = null !== $watch_pct ? max( 0, min( 100, (int) $watch_pct ) ) : 0;

		/**
		 * Fires when the lesson video reports a new high-water mark.
		 *
		 * Allows Pro modules (analytics, adaptive learning) to react without
		 * requiring the progress store to know about them.
		 */
		if ( $watch_pct > 0 ) {
			// Persist the high-water mark so the auto_video completion rule can
			// read it on the authoritative /complete call (it used to evaporate
			// into the hook below, leaving the rule stuck at 0%).
			\Learnomy\Models\Progress::record_video_pct( $user_id, (int) $lesson->id, $watch_pct );
			do_action( 'learnomy_lesson_video_progress', $user_id, (int) $lesson->id, $watch_pct );
		}

		return $this->single_response(
			[
				'lesson_id'     => (int) $lesson->id,
				'time_recorded' => $seconds,
				'watch_percent' => $watch_pct,
			]
		);
	}

	// -------------------------------------------------------------------------
	// Permission callbacks
	// -------------------------------------------------------------------------

	public function can_write_for_course( WP_REST_Request $request ): bool|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$course_id = (int) $request->get_param( 'course_id' );
		if ( ! $this->check_permission( 'manage_course', $course_id ) ) {
			return $this->permission_error();
		}

		return true;
	}

	public function can_write_for_section( WP_REST_Request $request ): bool|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$section_id = (int) $request->get_param( 'section_id' );
		$section    = Section::find( $section_id );
		if ( ! $section ) {
			return $this->permission_error();
		}

		if ( ! $this->check_permission( 'manage_course', (int) $section->course_id ) ) {
			return $this->permission_error();
		}

		return true;
	}

	public function can_write_for_lesson( WP_REST_Request $request ): bool|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$lesson_id = (int) $request->get_param( 'id' );
		$lesson    = Lesson::find( $lesson_id );
		if ( ! $lesson ) {
			return $this->permission_error();
		}

		if ( ! $this->check_permission( 'manage_course', (int) $lesson->course_id ) ) {
			return $this->permission_error();
		}

		// Owning the lesson WHERE IT IS says nothing about where it is being
		// moved TO. extract_lesson_fields() writes course_id / section_id /
		// parent_id straight from the body and validate_placement() only checks
		// FK integrity, so a PATCH that re-parents must clear the destination
		// too — otherwise "edit my own lesson" doubles as "inject a lesson into
		// someone else's course" (Basecamp 10133286758).
		return $this->require_parent_ownership(
			[
				'course'  => (int) $request->get_param( 'course_id' ),
				'section' => (int) $request->get_param( 'section_id' ),
				'lesson'  => (int) $request->get_param( 'parent_id' ),
			]
		);
	}

	public function is_enrolled_student( WP_REST_Request $request ): bool|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$lesson_id = (int) $request->get_param( 'id' );
		$lesson    = Lesson::find( $lesson_id );
		if ( ! $lesson ) {
			return $this->permission_error();
		}

		if ( ! $this->check_permission( 'access_course', (int) $lesson->course_id ) ) {
			return $this->permission_error();
		}

		return true;
	}

	/**
	 * Read-side twin of {@see is_enrolled_student()}: an enrolled student OR
	 * someone who manages the course.
	 *
	 * Instructors are never auto-enrolled in their own courses, so
	 * `is_enrolled_student()` refused them — and it guards the READ routes
	 * (`completion-status`, `my-unlock`) as well as the writes. The result was
	 * that an instructor previewing their own lesson could not even see the
	 * completion strip: the status endpoint 403'd (Basecamp 10134676326).
	 *
	 * Deliberately NOT applied to `/progress` or `/complete`. A preview must not
	 * write student data — that is what the preview bar above the player already
	 * promises — so the writes stay enrolled-only and the client stops firing them
	 * (see `canSaveProgress` in class-template-loader.php). Reading the rules for a
	 * lesson you own is a different act from recording progress against it.
	 */
	public function can_read_progress( WP_REST_Request $request ): bool|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$lesson = Lesson::find( (int) $request->get_param( 'id' ) );
		if ( ! $lesson ) {
			return $this->permission_error();
		}

		$course_id = (int) $lesson->course_id;
		if ( $this->check_permission( 'access_course', $course_id ) ) {
			return true;
		}
		if ( $this->check_permission( 'manage_course', $course_id ) ) {
			return true;
		}

		return $this->permission_error();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Can the current user view this course's (non-preview) lesson content?
	 *
	 * Gate on ENROLLMENT, not publish status (Basecamp 10104915939). The old
	 * `published → true` rule leaked paid, published course content to any
	 * logged-in non-enrolled user, and denied enrolled students of unpublished
	 * courses. Preview lessons bypass this before the call in get_lesson().
	 */
	private function can_view_course_content( object $course ): bool {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}
		if ( \Learnomy\Models\Enrollment::is_enrolled( $user_id, (int) $course->id ) ) {
			return true;
		}
		// The course's own instructors + admins (manage_course) can always view.
		return $this->check_permission( 'manage_course', (int) $course->id );
	}

	private function extract_lesson_fields( WP_REST_Request $request, ?object $existing = null ): array {
		$data = [];

		$title = $request->get_param( 'title' );
		if ( null !== $title ) {
			$data['title'] = sanitize_text_field( wp_unslash( $title ) );
		}

		$content = $request->get_param( 'content' );
		if ( null !== $content ) {
			// HTML-first storage cutover (ON by default, kill-switch). The editor posts Editor.js
			// JSON; for_storage() converts it to canonical semantic HTML through
			// the one shared server-side converter (INV-9). content_plain
			// regenerates from the result below. When the cutover is off, store
			// as before. Either way reads stay dual-format (INV-8).
			// Do NOT wp_unslash() here. REST JSON body params are not slashed by
			// WordPress, so unslashing strips the JSON-level \" escaping in the
			// Editor.js content (e.g. a shortcode attribute like [fluentform id="1"]),
			// producing invalid JSON that json_decode() rejects -> the page dumps raw
			// content. The value arrives ready to parse.
			$raw             = (string) $content;
			$data['content'] = \Learnomy\Content\Content_HTML::storage_enabled()
				? \Learnomy\Content\Content_HTML::for_storage( $raw )
				: wp_kses_post( $raw );
		}

		// Editable URL slug. Sanitised here; uniqueness within the course is
		// resolved by the caller (create() auto-dedupes; update_lesson()
		// excludes the current lesson). An empty value is dropped so a blank
		// field never wipes the existing slug — clearing is done by retitling.
		$slug = $request->get_param( 'slug' );
		if ( null !== $slug ) {
			$clean_slug = sanitize_title( wp_unslash( (string) $slug ) );
			if ( '' !== $clean_slug ) {
				$data['slug'] = $clean_slug;
			}
		}

		$content_type = $request->get_param( 'content_type' );
		if ( null !== $content_type ) {
			if ( in_array( $content_type, Lesson::content_types(), true ) ) {
				$data['content_type'] = $content_type;
			}
		}

		// Effective content type for sanitisation decisions below (notably
		// video_url): a PATCH may update video_url without re-sending
		// content_type, so fall back to the lesson's already-stored type.
		// Without this, the else-branch sanitiser runs against an existing
		// MediaShield lesson and esc_url_raw() mangles its bare post ID
		// (e.g. "77" → "http://77").
		$effective_content_type = $data['content_type'] ?? ( $existing->content_type ?? null );

		$is_preview = $request->get_param( 'is_preview' );
		if ( null !== $is_preview ) {
			$data['is_preview'] = (int) (bool) $is_preview;
		}

		$sort_order = $request->get_param( 'sort_order' );
		if ( null !== $sort_order ) {
			$data['sort_order'] = absint( $sort_order );
		}

		$duration = $request->get_param( 'duration_seconds' );
		if ( null !== $duration ) {
			$data['duration_seconds'] = absint( $duration );
		}

		$video_url = $request->get_param( 'video_url' );
		if ( null !== $video_url ) {
			// Some content-types store a bare numeric REFERENCE ID in this column,
			// not a URL — esc_url_raw() would strip an integer. MediaShield stores
			// a `mediashield_video` post ID; the H5P bridge stores an H5P content
			// ID. Both go through absint below. Filterable so a Pro content-type
			// can opt into the same treatment. Use the effective type so a PATCH
			// that omits content_type still treats an existing lesson's value
			// correctly.
			$numeric_ref_types = (array) apply_filters( 'learnomy_lesson_numeric_ref_types', array( 'mediashield' ) );
			if ( in_array( $effective_content_type, $numeric_ref_types, true ) ) {
				// Only persist a real, positive ID. Never let an empty/zero value
				// overwrite an already-attached video — that can happen when a save
				// fires before the picker resolves its selection, and would silently
				// detach the lesson's video. Clearing a protected video is done by
				// switching the content type, not by saving an empty picker.
				$ms_video_id = absint( wp_unslash( $video_url ) );
				if ( $ms_video_id > 0 ) {
					$data['video_url'] = (string) $ms_video_id;
				}
			} else {
				$data['video_url'] = esc_url_raw( wp_unslash( $video_url ) );
			}
		}

		$status = $request->get_param( 'status' );
		if ( null !== $status ) {
			$allowed_statuses = [ 'draft', 'published' ];
			if ( in_array( $status, $allowed_statuses, true ) ) {
				$data['status'] = $status;
			}
		}

		$content_plain = $request->get_param( 'content_plain' );
		if ( null !== $content_plain ) {
			$data['content_plain'] = sanitize_textarea_field( wp_unslash( $content_plain ) );
		}

		// Placement fields — only persisted when explicitly provided. FK
		// integrity is enforced by validate_placement() before the write.
		$course_id = $request->get_param( 'course_id' );
		if ( null !== $course_id ) {
			$data['course_id'] = absint( $course_id );
		}

		$section_id = $request->get_param( 'section_id' );
		if ( null !== $section_id ) {
			$data['section_id'] = absint( $section_id );
		}

		$parent_id = $request->get_param( 'parent_id' );
		if ( null !== $parent_id ) {
			// Accept 0 as "detach from parent" — stored as NULL.
			$parent_int        = absint( $parent_id );
			$data['parent_id'] = $parent_int > 0 ? $parent_int : null;
		}

		// ── Completion rules (Wave 1 schema, Wave 2 UI) ────────────────────
		// Single "mode" picker on the form writes six columns. The REST layer
		// mirrors the admin + Pro builder contract: each field is optional on
		// PATCH so a partial save does not clobber the others.
		$completion_mode = $request->get_param( 'completion_mode' );
		if ( null !== $completion_mode ) {
			$allowed_modes = [ 'manual', 'auto_time', 'auto_video', 'auto_scroll', 'auto_quiz_pass', 'auto_standard', 'combined' ];
			$mode          = sanitize_key( (string) $completion_mode );
			if ( in_array( $mode, $allowed_modes, true ) ) {
				$data['completion_mode'] = $mode;
			}
		}

		$min_time_seconds = $request->get_param( 'min_time_seconds' );
		if ( null !== $min_time_seconds ) {
			$data['min_time_seconds'] = absint( $min_time_seconds );
		}

		$min_video_watch_pct = $request->get_param( 'min_video_watch_pct' );
		if ( null !== $min_video_watch_pct ) {
			$pct                         = (int) $min_video_watch_pct;
			$data['min_video_watch_pct'] = max( 0, min( 100, $pct ) );
		}

		$require_scroll_end = $request->get_param( 'require_scroll_end' );
		if ( null !== $require_scroll_end ) {
			$data['require_scroll_end'] = (int) (bool) $require_scroll_end;
		}

		$require_quiz_pass = $request->get_param( 'require_quiz_pass' );
		if ( null !== $require_quiz_pass ) {
			$data['require_quiz_pass'] = (int) (bool) $require_quiz_pass;
		}

		$lock_next = $request->get_param( 'lock_next_until_complete' );
		if ( null !== $lock_next ) {
			$data['lock_next_until_complete'] = (int) (bool) $lock_next;
		}

		// Per-lesson completion enforcement override. Tri-state-plus-inherit:
		// null / empty string clears the override (resolution falls back to
		// course/site); otherwise must be one of strict/advisory/off.
		if ( $request->has_param( 'completion_enforcement' ) ) {
			$enf = $request->get_param( 'completion_enforcement' );
			if ( null === $enf || '' === $enf || 'inherit' === $enf ) {
				$data['completion_enforcement'] = null;
			} elseif ( in_array( $enf, array( 'strict', 'advisory', 'off' ), true ) ) {
				$data['completion_enforcement'] = (string) $enf;
			}
		}

		// ── Per-lesson comments toggle ─────────────────────────────────────
		// Tri-state: null = inherit from course, 0 = off, 1 = on. Clients send
		// the literal string "inherit" (or omit the param) to clear the
		// override — everything else is coerced to boolean.
		if ( $request->has_param( 'comments_enabled' ) ) {
			$value = $request->get_param( 'comments_enabled' );
			if ( null === $value || '' === $value || 'inherit' === $value ) {
				$data['comments_enabled'] = null;
			} else {
				$data['comments_enabled'] = (int) (bool) $value;
			}
		}

		// ── Drip schedule ──────────────────────────────────────────────────
		// Empty string from the form clears the drip (column becomes NULL).
		// Any other value must parse as a datetime in the site timezone — we
		// convert to UTC for storage so the gate can compare against the UTC
		// clock without timezone drift. Validation errors short-circuit the
		// PATCH via the `_drip_error` sentinel, which the caller surfaces.
		if ( $request->has_param( 'available_date' ) ) {
			$raw = $request->get_param( 'available_date' );
			if ( null === $raw || '' === $raw ) {
				$data['available_date'] = null;
			} else {
				$local = date_create( (string) $raw, wp_timezone() );
				if ( false === $local ) {
					$data['_drip_error'] = $this->validation_error(
						__( 'Invalid available_date format. Use YYYY-MM-DDTHH:MM in the site timezone.', 'learnomy' )
					);
				} else {
					$local->setTimezone( new \DateTimeZone( 'UTC' ) );
					$data['available_date'] = $local->format( 'Y-m-d H:i:s' );
				}
			}
		}

		// When a live session STARTS. Deliberately a different column from
		// available_date above: that one decides when the lesson unlocks, this one
		// decides when the session happens. They used to be the same column, which
		// meant every scheduled session was locked until the moment it began — so
		// nobody could see the countdown, which is the whole point of scheduling
		// one. Same site-timezone -> UTC conversion as the drip date.
		if ( $request->has_param( 'scheduled_start_at' ) ) {
			$raw = $request->get_param( 'scheduled_start_at' );
			if ( null === $raw || '' === $raw ) {
				$data['scheduled_start_at'] = null;
			} else {
				$local = date_create( (string) $raw, wp_timezone() );
				if ( false === $local ) {
					$data['_drip_error'] = $this->validation_error(
						__( 'Invalid scheduled_start_at format. Use YYYY-MM-DDTHH:MM in the site timezone.', 'learnomy' )
					);
				} else {
					$local->setTimezone( new \DateTimeZone( 'UTC' ) );
					$data['scheduled_start_at'] = $local->format( 'Y-m-d H:i:s' );
				}
			}
		}

		// How long the Join button stays up. 0 / empty clears it back to the
		// learnomy_live_session_duration default rather than storing a literal zero,
		// which would show the button for no time at all.
		if ( $request->has_param( 'live_duration_minutes' ) ) {
			$minutes = (int) $request->get_param( 'live_duration_minutes' );

			$data['live_duration_minutes'] = $minutes > 0
				? min( 1440, $minutes )
				: null;
		}

		// drip_mode toggles between fixed-date (1.9.0) and relative
		// (Plan 11). Anything outside the enum is silently dropped — clients
		// can't smuggle arbitrary strings into the column.
		if ( $request->has_param( 'drip_mode' ) ) {
			$raw = (string) $request->get_param( 'drip_mode' );
			if ( in_array( $raw, [ 'fixed', 'relative' ], true ) ) {
				$data['drip_mode'] = $raw;
			}
		}

		// drip_offset_days is only meaningful when drip_mode = 'relative'.
		// Empty string / null clears it (NULL in DB). We don't refuse the
		// write when drip_mode is fixed because a client may legitimately
		// switch modes back and forth and want to preserve the previous
		// offset; the gate ignores the column unless drip_mode is relative.
		if ( $request->has_param( 'drip_offset_days' ) ) {
			$raw = $request->get_param( 'drip_offset_days' );
			if ( null === $raw || '' === $raw ) {
				$data['drip_offset_days'] = null;
			} else {
				$data['drip_offset_days'] = max( 0, (int) $raw );
			}
		}

		return $data;
	}

	/**
	 * Validate placement FKs — course_id exists, section_id belongs to that
	 * course, parent_id is a top-level lesson in the same section.
	 *
	 * Returns a WP_Error on failure, null on success. Mutates `$data` so the
	 * caller writes a consistent row (forces section_id to match parent's
	 * section when a parent_id is set).
	 */
	private function validate_placement( array &$data, ?object $existing = null ): ?WP_Error {
		// Resolve the effective course/section/parent for validation — merge
		// the incoming payload with the existing row so a PATCH that only
		// updates parent_id still has access to course/section context.
		$course_id  = isset( $data['course_id'] ) ? (int) $data['course_id'] : ( $existing ? (int) $existing->course_id : 0 );
		$section_id = isset( $data['section_id'] ) ? (int) $data['section_id'] : ( $existing ? (int) $existing->section_id : 0 );
		$parent_id  = array_key_exists( 'parent_id', $data )
			? ( $data['parent_id'] === null ? 0 : (int) $data['parent_id'] )
			: ( $existing ? (int) ( $existing->parent_id ?? 0 ) : 0 );

		// When the client moves a lesson into a new section WITHOUT passing an
		// explicit course_id (the Course Builder "attach existing lesson" flow
		// sends only section_id), the target course must be derived from the
		// section being assigned — not the lesson's old course. Otherwise the
		// belongs-to check below compares the new section against the stale
		// course and rejects every cross-course attach with "Section does not
		// belong to the selected course." Resolve + persist the course so the
		// lesson's denormalized course_id moves with it.
		if ( $section_id > 0 && ! isset( $data['course_id'] ) ) {
			$target_section = Section::find( $section_id );
			if ( ! $target_section ) {
				return $this->not_found( 'Section' );
			}
			$course_id         = (int) $target_section->course_id;
			$data['course_id'] = $course_id;
		}

		// EXPLICIT all-zero placement = detach to the orphan pool. This is the
		// non-destructive half of curriculum removal: the builder's "Remove
		// lesson" takes the lesson out of the course while the content, its
		// children and student progress survive, findable again through
		// Attach Existing (which already searches orphans). Only the explicit
		// triple counts — a PATCH that merely omits course_id still resolves
		// from the existing row below, so partial updates cannot orphan a
		// lesson by accident (Basecamp 10180482336).
		if ( isset( $data['course_id'] ) && 0 === (int) $data['course_id']
			&& isset( $data['section_id'] ) && 0 === (int) $data['section_id']
			&& array_key_exists( 'parent_id', $data ) && 0 === (int) ( $data['parent_id'] ?? 0 ) ) {
			$data['course_id']  = 0;
			$data['section_id'] = 0;
			$data['parent_id']  = 0;
			return null;
		}

		if ( $course_id <= 0 ) {
			return $this->validation_error( __( 'course_id is required.', 'learnomy' ) );
		}

		$course = Course::find( $course_id );
		if ( ! $course ) {
			return $this->not_found( 'Course' );
		}

		// Never attach a lesson to a trashed course. The API refuses this
		// independently of what any picker offers — the UI half of this fix
		// stops it being chosen, this stops it being saved.
		//
		// Only an EXPLICIT target is checked. A lesson already sitting in a
		// trashed course must stay editable — including the PATCH that moves it
		// somewhere live — so inheriting course_id from the existing row is not
		// a placement decision and is not blocked. Rejecting that too would trap
		// exactly the lessons this bug stranded.
		$targets_explicit_course = isset( $data['course_id'] );
		if ( $targets_explicit_course && Course::STATUS_TRASHED === ( $course->status ?? '' ) ) {
			return $this->validation_error(
				sprintf(
					/* translators: %s: singular Course label */
					__( 'That %s is in the trash. Restore it before adding content to it.', 'learnomy' ),
					\Learnomy\course_label()
				)
			);
		}

		if ( $section_id > 0 ) {
			$section = Section::find( $section_id );
			if ( ! $section ) {
				return $this->not_found( 'Section' );
			}
			if ( (int) $section->course_id !== $course_id ) {
				return $this->validation_error( __( 'Section does not belong to the selected course.', 'learnomy' ) );
			}
		}

		if ( $parent_id > 0 ) {
			$parent = Lesson::find( $parent_id );
			if ( ! $parent ) {
				return $this->not_found( 'Parent lesson' );
			}
			if ( (int) $parent->course_id !== $course_id ) {
				return $this->validation_error( __( 'Parent lesson belongs to a different course.', 'learnomy' ) );
			}
			// Single-level nesting — the chosen parent must itself be top-level.
			if ( ! empty( $parent->parent_id ) ) {
				/* translators: 1: the Lesson label, 2: the Topic label. */
				return $this->validation_error(
					sprintf(
						__( 'That parent %1$s is already a %2$s; nesting is limited to one level.', 'learnomy' ),
						\Learnomy\lesson_label(),
						\Learnomy\topic_label()
					)
				);
			}
			// Sub-lessons inherit the parent's section — overwrite to keep
			// the row consistent.
			$data['section_id'] = (int) $parent->section_id;

			// A lesson cannot be its own parent.
			if ( $existing && (int) $existing->id === $parent_id ) {
				return $this->validation_error( __( 'A lesson cannot be its own parent.', 'learnomy' ) );
			}
		}

		return null;
	}

	private function lesson_args( bool $title_required = true ): array {
		return [
			'title'                    => [
				'type'              => 'string',
				'required'          => $title_required,
				'sanitize_callback' => 'sanitize_text_field',
			],
			// Editor.js JSON. NO arg-level sanitize_callback: wp_kses_post treats
			// '<'/'>' as tag delimiters and would corrupt the JSON (and any
			// shortcode with angle-bracket attributes) BEFORE it is parsed. The
			// storage layer sanitises correctly after parsing — for_storage()
			// (role-aware kses on the decoded HTML), or wp_kses_post() on the raw
			// when the HTML-first cutover is off. Mirrors the `stem` param.
			'content'                  => [
				'type' => 'string',
			],
			'content_type'             => [
				'type' => 'string',
				'enum' => Lesson::content_types(),
			],
			'is_preview'               => [
				'type'    => 'boolean',
				'default' => false,
			],
			'sort_order'               => [
				'type'    => 'integer',
				'minimum' => 0,
				'default' => 0,
			],
			'duration_seconds'         => [
				'type'    => 'integer',
				'minimum' => 0,
			],
			'video_url'                => [
				// No 'format' => 'uri': for content_type=mediashield this field
				// carries a bare mediashield_video post ID (e.g. "80"), and the
				// uri sanitizer rewrites "80" -> "http://80", which the handler's
				// absint() guard then drops (video silently detaches). The handler
				// branches on content_type and esc_url_raw()s real URLs itself.
				'type' => 'string',
			],
			// Live sessions. `scheduled_start_at` is WHEN THE SESSION IS; the drip
			// column `available_date` is when the LESSON UNLOCKS. Keep them apart:
			// they were one column until 3.18.0, and the result was that every
			// scheduled session stayed locked until the second it started.
			'scheduled_start_at'       => [
				'type'        => 'string',
				'description' => 'Live session start, YYYY-MM-DDTHH:MM in the site timezone. Empty string clears it.',
			],
			'live_duration_minutes'    => [
				'type'        => 'integer',
				'minimum'     => 0,
				'maximum'     => 1440,
				'description' => 'How long the Join button stays available, in minutes. 0 or omitted uses the 60-minute default.',
			],
			'status'                   => [
				'type' => 'string',
				'enum' => [ 'draft', 'published' ],
			],
			'content_plain'            => [ 'type' => 'string' ],
			'parent_id'                => [
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'Parent lesson ID for topics (child lesson rows). 0 = top-level.', 'learnomy' ),
			],
			'section_id'               => [
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'Section the lesson belongs to.', 'learnomy' ),
			],
			'course_id'                => [
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'Course the lesson belongs to.', 'learnomy' ),
			],
			'completion_mode'          => [
				'type'        => 'string',
				'enum'        => [ 'manual', 'auto_time', 'auto_video', 'auto_scroll', 'auto_quiz_pass', 'auto_standard', 'combined' ],
				'description' => __( 'How this lesson is marked complete.', 'learnomy' ),
			],
			'min_time_seconds'         => [
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'Minimum time-on-page (seconds) before Mark Complete enables for auto_time / combined modes.', 'learnomy' ),
			],
			'min_video_watch_pct'      => [
				'type'        => 'integer',
				'minimum'     => 0,
				'maximum'     => 100,
				'description' => __( 'Minimum video watched percentage for auto_video / combined modes.', 'learnomy' ),
			],
			'require_scroll_end'       => [
				'type'        => 'boolean',
				'description' => __( 'Require scroll-to-bottom before the lesson can be marked complete.', 'learnomy' ),
			],
			'require_quiz_pass'        => [
				'type'        => 'boolean',
				'description' => __( 'Require a linked quiz to be passed before the lesson can be marked complete.', 'learnomy' ),
			],
			'lock_next_until_complete' => [
				'type'        => 'boolean',
				'description' => __( 'Prevent access to the next lesson until this one is complete.', 'learnomy' ),
			],
			'completion_enforcement'   => [
				'type'        => array( 'string', 'null' ),
				'enum'        => array( null, '', 'inherit', 'strict', 'advisory', 'off' ),
				'description' => __( 'Per-lesson override of completion-rule strictness. NULL / inherit falls back to course or site setting; strict / advisory / off short-circuit the resolver.', 'learnomy' ),
			],
			'comments_enabled'         => [
				'description' => __( 'Tri-state comments toggle. true/false overrides the course setting; null (or "inherit") inherits.', 'learnomy' ),
			],
			'available_date'           => [
				'type'        => [ 'string', 'null' ],
				'description' => __( 'Drip schedule. Datetime in the site timezone (YYYY-MM-DDTHH:MM). Empty string clears the schedule. NULL or empty = lesson is available immediately.', 'learnomy' ),
			],
			'drip_mode'                => [
				'type'        => 'string',
				'enum'        => [ 'fixed', 'relative' ],
				'description' => __( 'Drip mode. fixed = use available_date. relative = unlock drip_offset_days after each student enrolls.', 'learnomy' ),
			],
			'drip_offset_days'         => [
				'type'        => [ 'integer', 'null' ],
				'minimum'     => 0,
				'description' => __( 'Days after enrollment when the lesson unlocks. Only used when drip_mode = relative.', 'learnomy' ),
			],
		];
	}
}
