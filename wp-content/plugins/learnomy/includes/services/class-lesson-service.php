<?php
/**
 * Lesson business logic — drip availability and other lesson-level access
 * decisions that don't belong on the model.
 *
 * @package Learnomy
 */

namespace Learnomy\Services;

defined( 'ABSPATH' ) || exit;

use Learnomy\DB\Relations;

use Learnomy\Models\Course;
use Learnomy\Models\Lesson;
use Learnomy\Models\Lesson_Unlock;
use Learnomy\Models\Progress;
use Learnomy\Models\Section;

/**
 * Hooks fired by Lesson_Service and its callers
 * ---------------------------------------------
 *
 * Action: `learnomy_lesson_unlocked`
 *   Fires once per (lesson_id, user_id) pair when a previously-locked drip
 *   lesson becomes available for a specific student.
 *
 *   Args (do_action signature):
 *     - int $lesson_id Lesson row id that just became available.
 *     - int $user_id   Student user id the unlock applies to.
 *     - int $course_id Parent course id (denormalised on the lesson row).
 *
 *   Listened to by:
 *     - Notifier — sends in-app + email notification ("Your next lesson is unlocked!").
 *
 *   Fired by:
 *     - Drip_Cron::process() — hourly Action Scheduler job
 *       (`learnomy_unlock_drip_lessons`) that scans `lrn_lessons` for rows
 *       whose `available_date` just crossed NOW() and dispatches the action
 *       per enrolled student.
 *
 *   Idempotency contract:
 *     The cron persists a `learnomy_drip_last_run` option after each run so
 *     the BETWEEN window cannot fire twice for the same lesson. Listeners
 *     may still de-duplicate defensively (e.g. by checking a per-(lesson,
 *     user) sent flag) — fire-once is a best-effort guarantee, not a
 *     transactional one.
 *
 *   Filter: `learnomy_can_access_lesson`
 *     Allows extensions to override the drip lock decision. Receives
 *     `(bool $can_access, int $user_id, int $lesson_id)`. Pro modules use
 *     this filter rather than calling `Lesson::find()` directly so the
 *     bypass risk noted in plan-06 §12 R3 stays contained.
 */
class Lesson_Service {

	/**
	 * Decide whether a given user can access a lesson right now based on the
	 * lesson's drip schedule.
	 *
	 * Resolution priority (Plan 11 §"Drip resolution priority")
	 *   1. Admin (manage_options) bypass — preview / debug locked lessons.
	 *   2. Course-author bypass — instructors preview their own schedule.
	 *   3. Per-student unlock row in `lrn_lesson_unlocks` for relative-drip
	 *      lessons (drip_mode = 'relative'). Stored datetime is the
	 *      instructor's offset applied at enrolment time; the gate compares
	 *      the stored UTC value against the UTC clock. No row means the
	 *      student never enrolled (or the lesson was added after enrolment) —
	 *      treated as locked.
	 *   4. Lesson-level fixed `available_date` (1.9.0 behaviour).
	 *   5. Section-level `available_date` (new in 2.0.0) — only consulted
	 *      when the lesson row's own `available_date` is NULL, so per-lesson
	 *      overrides win.
	 *   6. Default: available.
	 *
	 * @param int $lesson_id Lesson row id.
	 * @param int $user_id   User id to evaluate access for. Pass 0 for anonymous
	 *                       visitors — the drip rule still applies (anonymous
	 *                       visitors only see the lesson once the date passes,
	 *                       and never see relative-drip lessons because they
	 *                       can never have an unlock row).
	 *
	 * @return bool True when the user may access the lesson, false otherwise.
	 */
	public static function is_available_to( int $lesson_id, int $user_id ): bool {
		return self::evaluate_availability( $lesson_id, $user_id, null );
	}

	/**
	 * Same decision as {@see is_available_to()}, for a caller that ALREADY holds
	 * the lesson row.
	 *
	 * The curriculum outline renders every lesson in a loop and passed only the
	 * id, so `is_available_to()` ran `Lesson::find()` once per lesson to re-read
	 * a row the template was already holding — one query per lesson, on the
	 * most-visited page in the product. Admins never saw it: the bypass at the
	 * top returns before that query, so the cost is paid only by students
	 * (measured 2026-07-22: 39 queries as a student vs 24 as an admin on the
	 * same 12-lesson course). Basecamp 10119142606.
	 *
	 * The decision itself is shared with `is_available_to()` — one rule, two
	 * entry points, never two copies.
	 *
	 * @param object $lesson  Lesson row (must carry id, course_id, drip_mode,
	 *                        available_date, section_id).
	 * @param int    $user_id User id to evaluate access for; 0 for anonymous.
	 * @return bool True when the user may access the lesson.
	 */
	public static function is_row_available_to( object $lesson, int $user_id ): bool {
		return self::evaluate_availability( (int) ( $lesson->id ?? 0 ), $user_id, $lesson );
	}

	/**
	 * Whether a lesson is open to a user under BOTH lock rules — the drip
	 * schedule and the require-sequential cursor.
	 *
	 * The two rules live in separate methods because the outline evaluates them
	 * differently: it resolves the sequential cursor once for the whole course
	 * and then asks about drip per row. Anything holding a single lesson and
	 * asking the plain question "may they open this?" needs both, and answering
	 * it with only `is_row_available_to()` is how a quiz bound to a
	 * sequentially-locked lesson stayed startable (Basecamp 10181609428).
	 *
	 * Both underlying rules already bypass for admins and the course author, so
	 * this inherits that and needs no bypass of its own.
	 *
	 * @param object $lesson  Lesson row.
	 * @param int    $user_id User id; 0 for anonymous.
	 * @return bool True when neither the drip schedule nor the sequential cursor blocks it.
	 */
	public static function is_row_open_to( object $lesson, int $user_id ): bool {
		if ( ! self::is_row_available_to( $lesson, $user_id ) ) {
			return false;
		}

		$course_id = (int) ( $lesson->course_id ?? 0 );
		if ( $course_id <= 0 || $user_id <= 0 ) {
			return true;
		}

		return ! isset( self::locked_lesson_ids( $course_id, $user_id )[ (int) ( $lesson->id ?? 0 ) ] );
	}

	/**
	 * The single availability decision. Callers reach it through
	 * {@see is_available_to()} (by id) or {@see is_row_available_to()} (by row).
	 *
	 * @param int         $lesson_id Lesson row id.
	 * @param int         $user_id   User id; 0 for anonymous.
	 * @param object|null $lesson    Pre-loaded lesson row, or null to fetch it.
	 * @return bool
	 */
	private static function evaluate_availability( int $lesson_id, int $user_id, ?object $lesson ): bool {
		// 1. Admins always bypass the drip gate so they can preview locked lessons.
		// (The temporary [learnomy drip] bypass diagnostic from the bug-card-#6
		// investigation was removed: the investigation closed, and the line
		// fired twice per lesson per page view on any WP_DEBUG_LOG site — a
		// 16-lesson course page appended 32 log lines per view.)
		if ( $user_id > 0 && user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		// Only hit the DB when the caller could not supply the row.
		if ( null === $lesson ) {
			$lesson = Lesson::find( $lesson_id );
		}
		if ( ! $lesson ) {
			return false;
		}

		// 2. Course author bypass — instructors must be able to preview their own
		// schedule without waiting for the unlock time.
		if ( $user_id > 0 ) {
			$course = Course::find( (int) $lesson->course_id );
			if ( $course && (int) ( $course->author_id ?? 0 ) === $user_id ) {
				return true;
			}
		}

		$drip_mode = isset( $lesson->drip_mode ) ? (string) $lesson->drip_mode : 'fixed';
		$now_utc   = current_time( 'mysql', true );

		// Resolve the Free drip decision (steps 3-6), then let Pro veto.
		$available = true;

		if ( 'relative' === $drip_mode ) {
			// 3. Relative-drip — consult the per-student unlock row. Anonymous
			// visitors and never-enrolled students have no row, so the lesson is
			// locked for them (a relative schedule has no meaning without an
			// enrolment to anchor it).
			if ( $user_id <= 0 ) {
				$available = false;
			} else {
				$unlock    = Lesson_Unlock::find_for_user_lesson( $user_id, $lesson_id );
				$available = $unlock ? ( strcmp( (string) $unlock->unlocks_at, $now_utc ) <= 0 ) : false;
			}
		} elseif ( ! empty( $lesson->available_date ) ) {
			// 4. Lesson-level fixed `available_date` — original 1.9.0 behaviour.
			$available = strcmp( (string) $lesson->available_date, $now_utc ) <= 0;
		} elseif ( ! empty( $lesson->section_id ) ) {
			// 5. Section-level fallback — only when the lesson row had no
			// available_date of its own (per-lesson overrides win).
			$section = Section::find( (int) $lesson->section_id );
			if ( $section && ! empty( $section->available_date ) ) {
				$available = strcmp( (string) $section->available_date, $now_utc ) <= 0;
			}
		}
		// 6. else: no drip configured anywhere — available immediately.

		/**
		 * Gate a lesson's availability. Pro's content-drip extension hooks this
		 * to enforce advanced rules (prerequisite completion, per-cohort
		 * schedules) the Free columns don't model. Applying the filter HERE —
		 * not only inside Permission_Engine — means every caller (the lesson
		 * player view-data, curriculum, REST status) gets Pro enforcement; the
		 * admin + course-author bypasses above are intentional hard previews and
		 * deliberately skip it.
		 *
		 * The lesson ROW is passed as a 4th argument so a listener does not have
		 * to re-`find()` a row Free is already holding. Pro's content-drip gate
		 * did exactly that, once per lesson, which put the curriculum outline's
		 * N+1 back after Free's side was batched — 12 extra queries on a
		 * 12-lesson course, paid only by students (admins bypass before the
		 * filter runs). Adding an argument is backward compatible: listeners
		 * registered with 3 args are unaffected. Basecamp 10119142606.
		 *
		 * @param bool   $available Free drip decision.
		 * @param int    $user_id   User being evaluated.
		 * @param int    $lesson_id Lesson id.
		 * @param object $lesson    The lesson row Free already resolved.
		 */
		return (bool) apply_filters( 'learnomy_can_access_lesson', $available, $user_id, $lesson_id, $lesson );
	}

	/**
	 * Permission_Engine-aligned alias for `is_available_to`.
	 *
	 * Returns true when the user is permitted to view the lesson player right
	 * now. False means the lesson is drip-locked and the user is neither an
	 * administrator nor the course author.
	 *
	 * Use this from REST controllers and template loaders that phrase access
	 * in permission terms; reach for `is_available_to` when the caller
	 * already thinks of the question as "is this lesson available". The two
	 * methods always return the same value — the alias exists purely so
	 * callsites read naturally next to other permission checks.
	 *
	 * @param int $lesson_id Lesson row id.
	 * @param int $user_id   User id to evaluate access for. Pass 0 for anonymous visitors.
	 *
	 * @return bool True when the user may access the lesson, false otherwise.
	 */
	public static function user_can_access_lesson( int $lesson_id, int $user_id ): bool {
		return self::is_available_to( $lesson_id, $user_id );
	}

	/**
	 * May this user READ the lesson's body content?
	 *
	 * `is_available_to()` / `user_can_access_lesson()` answer only the DRIP
	 * question. They deliberately say nothing about entitlement, so neither is
	 * sufficient to protect paid content — a guest passes the drip check on any
	 * lesson with no `available_date`. That gap is how the `learnomy/lesson-content`
	 * block shipped serving a paid course body to anonymous visitors
	 * (Basecamp 10143219688: 505 characters of a $49 course, no gate at all).
	 *
	 * This is the composite gate, and it mirrors the lesson player exactly —
	 * `Single_Lesson_View_Data::resolve()` computes the same four-way access test
	 * plus the draft rule plus the drip rule. Any surface that renders lesson
	 * BODY content must ask this and nothing else, so the answer cannot drift
	 * per surface.
	 *
	 * Order matters: course visibility first (a private-academy site hides
	 * everything from guests), then editors, then free preview, then entitlement,
	 * and drip last because an entitled student can still be waiting for a
	 * scheduled unlock.
	 *
	 * @param object      $lesson  Lesson row (needs id, course_id, status, is_preview).
	 * @param int         $user_id User id, 0 for an anonymous visitor.
	 * @param object|null $course  Parent course row when the caller already has it, to
	 *                             avoid a re-fetch inside a loop.
	 *
	 * @return bool True only when this user may see the rendered body.
	 */
	public static function user_can_read_body( object $lesson, int $user_id, ?object $course = null ): bool {
		$lesson_id = (int) ( $lesson->id ?? 0 );
		$course_id = (int) ( $lesson->course_id ?? 0 );
		if ( ! $lesson_id || ! $course_id ) {
			return false;
		}

		$is_admin = $user_id && user_can( $user_id, 'manage_options' );

		// A dangling course_id (course row deleted, lesson row left behind) has no
		// visibility or entitlement to evaluate, so it fails closed — with one
		// exception. An administrator reading such a lesson is looking at orphaned
		// data they can already open in wp-admin, and showing them a "locked" card
		// only hides the problem. Failing closed for everyone ELSE is the point.
		$course = $course ?: Course::find( $course_id );
		if ( ! $course ) {
			return $is_admin;
		}

		// Site-wide private-academy gate + per-course visibility (private /
		// unlisted). Owns the "should this person see this course at all"
		// question; do not duplicate its rules here. Note this also covers a DRAFT
		// course: a free-preview lesson inside an unpublished course is correctly
		// invisible to anonymous visitors, because the course itself is not live
		// yet.
		if ( ! \Learnomy\can_view_course( $course, $user_id ) ) {
			return false;
		}

		$is_instructor = false;
		if ( $user_id && ! $is_admin ) {
			$is_instructor = (int) ( $course->author_id ?? 0 ) === $user_id;
			if ( ! $is_instructor ) {
				foreach ( \Learnomy\Models\CourseInstructor::get_for_user( $user_id ) as $lrn_ci_row ) {
					if ( (int) ( $lrn_ci_row->course_id ?? 0 ) === $course_id ) {
						$is_instructor = true;
						break;
					}
				}
			}
		}

		// A draft lesson is readable only by someone who could edit it. Matches
		// the player, which returns not_found for an enrolled student on a draft
		// (Basecamp 10124162733) — being entitled is not the same as being an editor.
		if ( 'published' !== (string) ( $lesson->status ?? 'published' ) ) {
			return $is_admin || $is_instructor;
		}

		if ( $is_admin || $is_instructor ) {
			return true;
		}

		// A free-preview lesson is intentionally public — that is the point of
		// the flag, and the catalog links to it.
		if ( ! empty( $lesson->is_preview ) ) {
			return true;
		}

		// Entitlement. Covers direct enrollment and an active membership that
		// includes this course.
		if ( ! $user_id || ! \Learnomy\Permissions\Permission_Engine::can( $user_id, 'access_course', $course_id ) ) {
			return false;
		}

		// Finally the drip schedule. Row-based so a caller looping a curriculum
		// does not trigger a per-lesson re-read (the N+1 this method's
		// row-taking signature exists to avoid).
		return self::is_row_available_to( $lesson, $user_id );
	}

	/**
	 * Which of a course's lessons are sequence-locked for this user?
	 *
	 * Two independent rules can lock a lesson, and neither is about the lesson's
	 * own content:
	 *
	 *   1. Course-level `require_sequential` — from the first incomplete lesson
	 *      in play order onwards, everything is locked.
	 *   2. Per-lesson `lock_next_until_complete` — a flagged, not-yet-completed
	 *      lesson locks everything AFTER it, whether or not the course requires
	 *      sequential progress.
	 *
	 * Admins, the course author and co-instructors bypass both, so they can walk
	 * their own course out of order.
	 *
	 * This lived inside `Single_Lesson_View_Data::resolve()` and was enforced only
	 * as a redirect there, which made the page render the sole guard: POSTing
	 * straight to `/lessons/{id}/complete` completed a locked lesson with a 200
	 * (Basecamp 10134140167). It is a rule about the course, not about the page,
	 * so it lives here and the view-data consumes it.
	 *
	 * Returns the SET rather than a single verdict because the curriculum sidebar
	 * needs to render every locked lesson, not just ask about one.
	 *
	 * @param int $course_id Course to walk.
	 * @param int $user_id   User id; 0 for anonymous (nothing to sequence).
	 *
	 * @return array<int,true> Locked lesson ids as keys, for O(1) isset() lookups.
	 */
	public static function locked_lesson_ids( int $course_id, int $user_id ): array {
		if ( ! $course_id || ! $user_id ) {
			return array();
		}

		$course = Course::find( $course_id );
		if ( ! $course ) {
			return array();
		}

		// Editors walk their own course freely — same bypass the drip gate uses.
		if ( user_can( $user_id, 'manage_options' ) ) {
			return array();
		}
		if ( (int) ( $course->author_id ?? 0 ) === $user_id ) {
			return array();
		}
		foreach ( \Learnomy\Models\CourseInstructor::get_for_user( $user_id ) as $lrn_ci_row ) {
			if ( (int) ( $lrn_ci_row->course_id ?? 0 ) === $course_id ) {
				return array();
			}
		}

		$require_sequential = ! empty( Course::get_all_settings( $course_id )['require_sequential'] );

		$completed = array();
		foreach ( Progress::get_all_for_course( $user_id, $course_id ) as $row ) {
			if ( 'completed' === ( $row->status ?? '' ) ) {
				$completed[ (int) $row->item_id ] = true;
			}
		}

		$locked        = array();
		$cursor_locked = false;
		foreach ( Lesson::get_for_course( $course_id ) as $walk_lesson ) {
			$walk_id   = (int) $walk_lesson->id;
			$walk_done = isset( $completed[ $walk_id ] );

			if ( $cursor_locked ) {
				$locked[ $walk_id ] = true;
			}
			// Rule 1 — the first incomplete lesson closes everything after it.
			if ( $require_sequential && ! $cursor_locked && ! $walk_done ) {
				$cursor_locked = true;
			}
			// Rule 2 — independent of require_sequential.
			if ( ! $cursor_locked && ! empty( $walk_lesson->lock_next_until_complete ) && ! $walk_done ) {
				$cursor_locked = true;
			}
		}

		return $locked;
	}

	/**
	 * Is this lesson OPEN to this user right now?
	 *
	 * Reachability, which is a different question from both entitlement
	 * ({@see user_can_read_body()}) and from the lesson's own completion rules
	 * ({@see \Learnomy\Services\Progress_Service::evaluate_completion_rules()}).
	 * A student can be fully entitled, with every requirement met, and still be
	 * waiting for a drip date or for the previous lesson.
	 *
	 * Any WRITE against a lesson must ask this. It used to be answered only by
	 * the page renderer, so `/lessons/{id}/complete` and `/progress/sync` both
	 * completed locked lessons with a 200 (Basecamp 10143220028, 10134140167).
	 *
	 * @param object $lesson  Lesson row (needs id, course_id).
	 * @param int    $user_id User id; 0 for anonymous.
	 *
	 * @return bool True when neither the drip schedule nor the sequence blocks it.
	 */
	public static function is_reachable_by( object $lesson, int $user_id ): bool {
		if ( ! self::is_row_available_to( $lesson, $user_id ) ) {
			return false;
		}

		$locked = self::locked_lesson_ids( (int) ( $lesson->course_id ?? 0 ), $user_id );

		return ! isset( $locked[ (int) ( $lesson->id ?? 0 ) ] );
	}

	/**
	 * Remove every renderable body field from a lesson payload.
	 *
	 * The companion to {@see user_can_read_body()} for API-shaped callers. A REST
	 * client that fails the gate still needs the lesson's METADATA — title,
	 * duration, content_type, the drip columns — so it can render the same locked
	 * row the curriculum shows. It must not receive anything it could play or
	 * display as the lesson itself.
	 *
	 * The four fields below are that payload. `video_url` belongs on the list and
	 * is easy to miss: `GET /lessons/{id}` returned the YouTube URL of a
	 * drip-locked lesson alongside the text body (Basecamp 10143220266), and a URL
	 * is as renderable as the HTML.
	 *
	 * Deliberately a denylist, not an allowlist: `Lesson::to_typed_array()` grows
	 * with the schema, and an allowlist would silently drop new metadata the app
	 * needs. The trade-off is that a future body-bearing column must be added
	 * here — which is what the filter is for.
	 *
	 * @param array<string,mixed> $row Lesson payload, typically Lesson::to_typed_array().
	 *
	 * @return array<string,mixed> The payload without body fields.
	 */
	public static function strip_body_fields( array $row ): array {
		/**
		 * Fields that count as a lesson's renderable body.
		 *
		 * Pro extensions that add a playable or readable field to a lesson
		 * response (a SCORM launch URL, a transcript, a downloadable package)
		 * must register it here, or a locked lesson will hand that field to a
		 * student who has not reached it yet.
		 *
		 * @since 1.8.1
		 *
		 * @param string[] $fields Field names to remove from a locked lesson payload.
		 */
		$fields = (array) apply_filters(
			'learnomy_lesson_body_fields',
			array( 'content', 'content_html', 'content_plain', 'video_url' )
		);

		foreach ( $fields as $field ) {
			unset( $row[ (string) $field ] );
		}

		return $row;
	}

	/**
	 * Delete one lesson and fire the lifecycle hook so every extension can
	 * clean up its own child rows.
	 *
	 * This is the SINGLE chokepoint for lesson deletion. Both the REST
	 * `delete_lesson` endpoint and the course-delete cascade route through
	 * here so the `learnomy_lesson_deleted` action fires on every path. The
	 * bare base-model `Lesson::delete()` (a plain row delete) fires nothing, so
	 * calling it directly inside a cascade silently orphans every Pro table
	 * that hooks the action (notes/bookmarks blobs, `lrn_pro_drip_rules`,
	 * `lrn_pro_lesson_revisions`, …). The course cascade used to call the bare
	 * model method in two loops and leaked exactly those rows.
	 *
	 * @param int $lesson_id Lesson row id to delete.
	 * @param int $course_id Parent course id (denormalised on the lesson row).
	 *                       Pass 0 when the caller doesn't already have it and
	 *                       this method will resolve it from the lesson row.
	 *
	 * @return bool True when the underlying row was deleted.
	 */
	public static function delete( int $lesson_id, int $course_id = 0 ): bool {
		if ( $lesson_id <= 0 ) {
			return false;
		}

		// Resolve the parent course id when the caller didn't supply it, so the
		// hook payload is always complete (listeners scope cleanup by course).
		if ( $course_id <= 0 ) {
			$lesson    = Lesson::find( $lesson_id );
			$course_id = $lesson ? (int) $lesson->course_id : 0;
		}

		$deleted = Lesson::delete( $lesson_id );

		if ( $deleted ) {
			// Clean everything that declared a reference to this lesson.
			//
			// This path used to delete the lesson row and nothing else, while
			// TEN table definitions carry a lesson_id — attachments, unlocks,
			// comments, revisions, assignments, drip rules. The rows simply
			// outlived their parent, silently, and the only reason it was not
			// obviously broken is that most readers join and find nothing.
			//
			// The listeners on `learnomy_lesson_deleted` below still run; the
			// registry covers the tables nobody remembered to write a listener
			// for, and covers the next one automatically.
			Relations::cascade( 'lesson', $lesson_id );

			do_action( 'learnomy_lesson_deleted', $lesson_id, $course_id );
		}

		return $deleted;
	}

	/**
	 * Copy a lesson into a section — the reuse half of the Course Builder's
	 * "Attach Existing" flow.
	 *
	 * Attaching a lesson that belongs to another course is a MOVE (its
	 * denormalized course_id follows the section, so it leaves the original
	 * course — Basecamp 10153330214). Most of the time what an author wants
	 * is the CONTENT in a second course, not shared state: a lesson row is
	 * also the progress target (lrn_progress is UNIQUE per lesson), the drip
	 * anchor and the URL segment, so a true cross-course link would complete
	 * in both courses when finished in one. Copying sidesteps the whole
	 * class: the new course gets its own row, its own progress, its own URL.
	 *
	 * The copy is a fresh top-level lesson at the end of the target section:
	 * - parent_id resets to 0 — the parent is not coming along, and a
	 *   dangling parent reference would orphan the copy in the outline.
	 * - The slug is re-uniqued (slugs are globally unique across lessons).
	 * - created_at/updated_at restamp; everything else carries over,
	 *   including status, so a draft copies as a draft.
	 *
	 * @param int $lesson_id  Source lesson.
	 * @param int $section_id Target section, or 0 for the course root — the
	 *                        builder's "no section" pool ($course_id then
	 *                        names the course).
	 * @param int $course_id  Target course; required when $section_id is 0,
	 *                        ignored otherwise (the section owns the truth).
	 * @return int New lesson id, or 0 on failure.
	 */
	public function copy_to_section( int $lesson_id, int $section_id, int $course_id = 0 ): int {
		$source = Lesson::find( $lesson_id );
		if ( ! $source ) {
			return 0;
		}

		if ( $section_id > 0 ) {
			$section = Section::find( $section_id );
			if ( ! $section ) {
				return 0;
			}
			$course_id = (int) $section->course_id;
		} elseif ( $course_id <= 0 || ! Course::find( $course_id ) ) {
			return 0;
		}

		$data = (array) $source;
		unset( $data['id'], $data['created_at'], $data['updated_at'] );
		$data['course_id']  = $course_id;
		$data['section_id'] = $section_id;
		$data['parent_id']  = 0;
		$data['slug']       = $this->unique_copy_slug( (string) ( $source->slug ?: sanitize_title( (string) $source->title ) ) );
		$data['sort_order'] = $section_id > 0
			? count( Lesson::get_for_section( $section_id, 0, 0, null ) ) + 1
			: count( Lesson::get_for_course( $course_id, 0, 0, null ) ) + 1;

		$new_id = Lesson::create( $data );
		if ( ! $new_id ) {
			return 0;
		}

		do_action( 'learnomy_lesson_copied', $new_id, $lesson_id, (int) $section->course_id );

		return $new_id;
	}

	/**
	 * First free slug for a copied lesson. Lesson slugs are globally unique;
	 * the source's own slug is always taken, so this starts at `-2`
	 * ("react-hooks" → "react-hooks-2") and walks up.
	 */
	private function unique_copy_slug( string $base ): string {
		$counter = 2;
		$slug    = $base . '-' . $counter;

		while ( Lesson::find_by_slug( $slug ) ) {
			++$counter;
			$slug = $base . '-' . $counter;
		}

		return $slug;
	}
}
