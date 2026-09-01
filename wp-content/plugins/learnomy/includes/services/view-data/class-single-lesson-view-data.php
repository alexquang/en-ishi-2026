<?php
/**
 * View-data resolver for the single lesson template.
 *
 * Replaces all Model calls in templates/single-lesson.php with a
 * pre-resolved data array so the template has zero DB queries.
 *
 * @package Learnomy
 */

namespace Learnomy\Services\View_Data;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Course;
use Learnomy\Models\Lesson;
use Learnomy\Models\Lesson_Attachment;
use Learnomy\Models\Section;
use Learnomy\Models\Category;
use Learnomy\Models\Enrollment;
use Learnomy\Models\Progress;
use Learnomy\Models\Quiz;
use Learnomy\Models\Lesson_Comment;
use Learnomy\Services\Lesson_Service;

class Single_Lesson_View_Data {

	/**
	 * Resolve all data needed by templates/single-lesson.php.
	 *
	 * @param array $data Route data containing 'slug' and 'lesson_slug'.
	 * @return array Pre-fetched view data, or array with 'not_found' flag.
	 */
	public static function resolve( array $data ): array {
		$slug        = $data['slug'] ?? '';
		$lesson_slug = $data['lesson_slug'] ?? '';

		$course = Course::find_by_slug( $slug );
		// Scope the lesson lookup to the resolved course (F-02.4 fix).
		// find_by_slug used to short-circuit to the first lesson with that
		// slug regardless of course — which let the lesson player render
		// paid content from one course on the URL of another course the
		// user happened to be enrolled in.
		$lesson = $course
			? Lesson::find_by_course_and_slug( (int) $course->id, $lesson_slug )
			: null;

		if ( ! $course || ! $lesson ) {
			return array( 'not_found' => true );
		}

		$user_id       = get_current_user_id();
		$course_id     = (int) $course->id;
		$is_admin      = current_user_can( 'manage_options' );
		$is_instructor = $user_id ? ( (int) ( $course->author_id ?? 0 ) === $user_id ) : false;
		$is_enrolled   = $user_id ? Enrollment::is_enrolled( $user_id, $course_id ) : false;

		// Membership access counts, and only the engine knows about it.
		//
		// This read `$is_enrolled || $is_admin || $is_instructor || is_preview`
		// — an enrolment-row check standing in for "may this person read the
		// course". A member whose active plan includes the course has no
		// enrolment row, so the course page offered "Start course" (the course
		// gate asks Permission_Engine and correctly said yes) and then every
		// non-preview lesson answered "Enrollment Required" (Basecamp
		// 10186511098). The site promised access on one screen and refused it
		// on the next.
		//
		// `access_course` is the canonical question, and it already folds in
		// enrolment, membership coverage and course authorship. The locals
		// above stay because the view uses them for other decisions (drip
		// bypass, draft preview), but they are no longer the gate.
		$has_course_access = $user_id
			? \Learnomy\Permissions\Permission_Engine::can( $user_id, 'access_course', $course_id )
			: false;
		$can_access        = $has_course_access || $is_admin || $is_instructor || ! empty( $lesson->is_preview );
		$enrollment    = $is_enrolled ? Enrollment::find_for_user_course( $user_id, $course_id ) : null;

		// A draft (unpublished) lesson is previewable only by someone who can edit
		// the course — author, co-instructor, or admin. An enrolled student who
		// guesses the URL must get not_found, matching the curriculum which now
		// hides drafts from students (Basecamp 10124162733). Access alone is not
		// enough: $is_enrolled is true for a paying student on a draft lesson.
		if ( 'published' !== (string) ( $lesson->status ?? 'published' ) ) {
			$can_preview_draft = $is_admin || $is_instructor;
			if ( ! $can_preview_draft && $user_id ) {
				foreach ( \Learnomy\Models\CourseInstructor::get_for_user( $user_id ) as $lrn_ci_row ) {
					if ( (int) ( $lrn_ci_row->course_id ?? 0 ) === $course_id ) {
						$can_preview_draft = true;
						break;
					}
				}
			}
			if ( ! $can_preview_draft ) {
				return array( 'not_found' => true );
			}
		}

		// Requirements layer ON TOP of enrollment: an integration can add an
		// access requirement that blocks even an enrolled member (e.g. a profile
		// field that must be completed before watching). It only applies to a
		// viewer who would otherwise have access, and never to a site manager
		// (admins/instructors are never locked out of their own content). A
		// non-empty return denies access and carries the gate reason
		// { key, title, message, cta_label, cta_url }.
		$access_requirement = array();
		if ( $can_access && ! $is_admin && ! $is_instructor ) {
			/**
			 * Filter extra lesson-access requirements layered on top of enrollment.
			 *
			 * @param array  $requirement Empty = no extra requirement. Non-empty
			 *                            (reason map) = access denied.
			 * @param object $lesson      The lesson.
			 * @param object $course      Its course.
			 * @param int    $user_id     The viewer.
			 */
			$access_requirement = (array) apply_filters( 'learnomy_lesson_access_requirements', array(), $lesson, $course, $user_id );
			if ( empty( $access_requirement ) ) {
				/**
				 * Course-level requirements gate EVERY lesson in the course
				 * (gate the course -> gate its lessons), so an integration can
				 * require something once per course rather than per lesson.
				 * Same reason shape { key, title, message, cta_label, cta_url }.
				 *
				 * @param array  $requirement Empty = none; non-empty = denied.
				 * @param object $course      The course.
				 * @param int    $user_id     The viewer.
				 */
				$access_requirement = (array) apply_filters( 'learnomy_course_access_requirements', array(), $course, $user_id );
			}
			if ( ! empty( $access_requirement ) ) {
				$can_access = false;
			}
		}

		// Completion state for this lesson.
		$progress     = $user_id
			? Progress::get_for_user_and_item( $user_id, 'lesson', (int) $lesson->id )
			: null;
		$is_completed = $progress && 'completed' === $progress->status;

		// Curriculum data.
		$sections    = Section::get_for_course( $course_id );
		$all_lessons = Lesson::get_for_course( $course_id );

		// Per-user completion lookup.
		$completed_ids = array();
		if ( $user_id ) {
			$progress_rows = Progress::get_all_for_course( $user_id, $course_id );
			foreach ( $progress_rows as $row ) {
				if ( 'completed' === $row->status ) {
					$completed_ids[ (int) $row->item_id ] = true;
				}
			}
		}

		// Derive the progress bar LIVE from completed lessons / total — the same
		// basis as the "X/N complete" curriculum count and
		// Progress_Service::calculate_course_progress(). Reading the denormalized
		// enrollment.progress_pct here let the bar drift out of sync with the
		// count (the field is recomputed on lesson completion, but can lag — e.g.
		// lessons added after a student progressed, or seeded data). The lesson
		// set + completion map are already loaded above, so this is free and can
		// never contradict the count the learner sees right next to it.
		$lesson_total = count( $all_lessons );
		$progress_pct = $lesson_total > 0
			? (int) round( ( count( $completed_ids ) / $lesson_total ) * 100 )
			: 0;

		// Current section.
		$current_section_id = ! empty( $lesson->section_id ) ? (int) $lesson->section_id : 0;

		// Prev / next lesson navigation. Walk the FLATTENED sequence (each
		// top-level lesson followed by its sub-lessons) — not the top-level-only
		// $all_lessons — so a sub-lesson page resolves real neighbours instead
		// of falling off the list and rendering blank prev/next buttons.
		$sequence      = Lesson::get_curriculum_sequence( $course_id );
		$current_index = -1;
		foreach ( $sequence as $i => $l ) {
			if ( (int) $l->id === (int) $lesson->id ) {
				$current_index = $i;
				break;
			}
		}
		$prev_lesson = $current_index > 0 ? $sequence[ $current_index - 1 ] : null;
		$next_lesson = isset( $sequence[ $current_index + 1 ] ) ? $sequence[ $current_index + 1 ] : null;

		$prev_url = $prev_lesson ? \Learnomy\route_url(
			'lesson',
			array(
				'slug'        => (string) $course->slug,
				'lesson_slug' => (string) $prev_lesson->slug,
			)
		) : '';
		$next_url = $next_lesson ? \Learnomy\route_url(
			'lesson',
			array(
				'slug'        => (string) $course->slug,
				'lesson_slug' => (string) $next_lesson->slug,
			)
		) : '';

		// Video provider detection — shared helper handles every YouTube /
		// Vimeo / Loom URL shape (watch, embed, shorts, m.*, player.vimeo,
		// etc.) plus self-hosted MP4/WebM falling back to html5. See
		// \Learnomy\resolve_video_url() and Basecamp #9925147314 bounce.
		$resolved        = \Learnomy\resolve_video_url( (string) ( $lesson->video_url ?? '' ) );
		$video_provider  = $resolved['provider'];
		$video_embed_id  = $resolved['embed_id'];
		$video_embed_url = $resolved['embed_url'];

		// Watch-before-complete threshold.
		$require_watch_pct = (int) apply_filters(
			'learnomy_lesson_require_watch_percent',
			0,
			$lesson,
			$course
		);

		// Course-level settings drive sidebar gating (require_sequential).
		$course_settings    = Course::get_all_settings( $course_id );
		$require_sequential = ! empty( $course_settings['require_sequential'] );

		// The locked-lesson set. The walk itself lives in
		// Lesson_Service::locked_lesson_ids() because it is a rule about the
		// COURSE, not about this page — while it lived here the page render was
		// the only thing enforcing it, and POSTing to /lessons/{id}/complete
		// completed a locked lesson with a 200 (Basecamp 10134140167). The
		// service now owns the rule and the write paths gate on it too; this
		// screen consumes the set for display and for the redirect below.
		//
		// Rules (unchanged): course-level `require_sequential` locks everything
		// from the first incomplete lesson onwards; per-lesson
		// `lock_next_until_complete` locks everything after a flagged,
		// not-yet-completed lesson (Basecamp #9925276246). Admins, the author and
		// co-instructors bypass both — the service applies that itself.
		$locked_lesson_ids = \Learnomy\Services\Lesson_Service::locked_lesson_ids( $course_id, $user_id );

		// Enforce the gate: if the requested lesson is locked, redirect to
		// the most-recent unlocked lesson the user CAN access (better UX than
		// a 403). Falls back to the course page when no unlocked lesson
		// exists. Happens here (in view-data) because resolve() runs before
		// any output — wp_safe_redirect can still set headers.
		if ( isset( $locked_lesson_ids[ (int) $lesson->id ] ) ) {
			$fallback_lesson = null;
			foreach ( $all_lessons as $walk_lesson ) {
				if ( isset( $locked_lesson_ids[ (int) $walk_lesson->id ] ) ) {
					break;
				}
				$fallback_lesson = $walk_lesson;
			}
			$destination = $fallback_lesson
				? \Learnomy\route_url(
					'lesson',
					array(
						'slug'        => (string) $course->slug,
						'lesson_slug' => (string) $fallback_lesson->slug,
					)
				)
				: \Learnomy\route_url( 'course', array( 'slug' => (string) $course->slug ) );
			wp_safe_redirect( add_query_arg( 'lesson_locked', '1', $destination ) );
			exit;
		}

		// Lesson attachments — pre-fetched once so the template doesn't run a
		// query inside its render. Empty array when the lesson has none.
		//
		// `url` is resolved HERE, not in the template. Templates used to call
		// wp_get_attachment_url() themselves, which is exactly how a paid
		// handout ended up linked at its raw uploads path on two separate
		// templates (Basecamp 10148249510). One resolver, one chokepoint:
		// Lesson_Attachment::get_url() returns the gated route.
		$lesson_attachments = Lesson_Attachment::get_for_lesson( (int) $lesson->id );
		foreach ( $lesson_attachments as $lrn_attachment_row ) {
			$lrn_attachment_row->url = Lesson_Attachment::get_url( $lrn_attachment_row );
		}

		// Drip schedule — does the current viewer have access to this lesson
		// right now? Admins / authors always pass; everyone else passes only
		// when `available_date` is empty or has elapsed (compared in UTC).
		// `unlocks_at` is the raw stored UTC datetime so the locked partial
		// can format it for the site timezone; `unlocks_at_iso` is an ISO 8601
		// string with the UTC offset so the JS countdown can parse it
		// reliably across browsers.
		$locked     = ! Lesson_Service::is_available_to( (int) $lesson->id, (int) $user_id );
		$unlocks_at = $locked ? (string) ( $lesson->available_date ?? '' ) : '';
		// Pro relative/date drip rules leave available_date empty, so let an
		// extension supply the unlock date for the CURRENTLY-VIEWED lesson too
		// (decorate_drip does the same for the sidebar steps). Without this the
		// viewed dripped lesson showed the generic "locked" with no countdown. [card 10062795757]
		if ( $locked && '' === $unlocks_at ) {
			$unlocks_at = (string) apply_filters( 'learnomy_lesson_unlock_date', $unlocks_at, (int) $lesson->id, (int) $user_id, $course );
		}
		$unlocks_at_iso = '';
		if ( $unlocks_at ) {
			try {
				$utc            = new \DateTimeImmutable( $unlocks_at, new \DateTimeZone( 'UTC' ) );
				$unlocks_at_iso = $utc->format( 'c' );
			} catch ( \Exception $e ) {
				$unlocks_at_iso = '';
			}
		}

		// Sidebar section lessons + topics (pre-fetch to avoid N+1 in template).
		$section_lessons_map = array();
		$lesson_topics_map   = array();
		foreach ( $sections as $section ) {
			$section_lessons                           = Lesson::get_for_section( (int) $section->id );
			$section_lessons_map[ (int) $section->id ] = $section_lessons;
			foreach ( $section_lessons as $sl ) {
				// Attach drip lock state to each sidebar row so the player's
				// curriculum nav can render the lock chip without per-row
				// service calls inside the template. Pass $course so the
				// admin/author bypass avoids a Course::find() per lesson.
				self::decorate_drip( $sl, (int) $user_id, $course );

				$topics = Lesson::get_topics( (int) $sl->id );
				if ( $topics ) {
					foreach ( $topics as $topic ) {
						self::decorate_drip( $topic, (int) $user_id, $course );
					}
					$lesson_topics_map[ (int) $sl->id ] = $topics;
				}
			}
		}

		// Section-less ("ungrouped") lessons — decorate them the same way and
		// expose under the id=0 pseudo-section key so the player sidebar can
		// surface them even when other lessons ARE grouped. Without this the
		// sidebar's section loop skips them entirely and they're reachable only
		// via Prev/Next (Basecamp #10009374408). Mirrors templates/partials/curriculum.php.
		$loose_lessons = Lesson::get_section_less_for_course( (int) $course->id );
		foreach ( $loose_lessons as $ll ) {
			self::decorate_drip( $ll, (int) $user_id, $course );

			$topics = Lesson::get_topics( (int) $ll->id );
			if ( $topics ) {
				foreach ( $topics as $topic ) {
					self::decorate_drip( $topic, (int) $user_id, $course );
				}
				$lesson_topics_map[ (int) $ll->id ] = $topics;
			}
		}
		$section_lessons_map[0] = $loose_lessons;

		// Quizzes for the sidebar nav, batched the same way. The player outline
		// listed lessons and topics but never a quiz, so a student working inside
		// the player could not see that a quiz existed, let alone jump back to
		// one — the only route to it was the CTA at the foot of the one lesson it
		// hangs off. The course page lists the same quizzes, which is how the two
		// screens ended up disagreeing about what the course contains
		// (Basecamp 10181082400).
		$section_quizzes_map = Quiz::get_for_sections(
			array_map( static fn( $s ) => (int) $s->id, $sections )
		);

		// Flat lesson list (used when the course has no sections) needs the
		// same decoration for the player's sidebar nav.
		foreach ( $all_lessons as $al ) {
			self::decorate_drip( $al, (int) $user_id, $course );
		}

		// Lesson comments data. The global admin toggle
		// `learnomy_settings[enable_lesson_comments]` is the master kill-switch
		// and forms the default for the filter; admin defaults that key to on,
		// so a missing entry is treated as enabled and only an explicit `0`
		// disables comments site-wide. Per-course and per-lesson controls layer
		// on top via the filter.
		// Resolve the effective flag through the model so the player honors all
		// three controls, not just the global kill-switch: global setting first,
		// then the per-lesson override (NULL = inherit), then the per-course
		// `comments_enabled` setting. Previously this only read the global key, so
		// an instructor disabling comments on a course or a single lesson had no
		// effect on the player (the admin toggles were silently dead). The model
		// is the single source of truth shared with the REST write-gate.
		$global_lesson_comments = Lesson::get_effective_comments_enabled( (int) $lesson->id );
		$comments_enabled       = (bool) apply_filters( 'learnomy_lesson_comments_enabled', $global_lesson_comments, $lesson, $course );
		$comment_tree           = array();
		$total_comments         = 0;
		$instructor_ids         = array( (int) $course->author_id );

		if ( $comments_enabled && ( $is_enrolled || $is_admin || $is_instructor ) ) {
			// Gather co-instructor IDs.
			$course_instructors = Course::get_instructors( $course_id );
			foreach ( $course_instructors as $ci ) {
				$instructor_ids[] = (int) $ci->user_id;
			}
			$instructor_ids = array_unique( array_filter( $instructor_ids ) );

			// Hide comments + replies authored by anyone the viewer has blocked,
			// on the server-rendered first paint too — not just the REST refetch
			// (App Store 1.2 UGC gate: a block must actually hide the content).
			$blocked_authors = \Learnomy\Models\Blocked_User::blocked_ids_for( get_current_user_id() );

			$top_comments = get_comments(
				array(
					'type'           => Lesson_Comment::COMMENT_TYPE,
					'parent'         => 0,
					'status'         => 'approve',
					'orderby'        => 'comment_date_gmt',
					'order'          => 'ASC',
					'author__not_in' => $blocked_authors,
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'   => Lesson_Comment::LESSON_META_KEY,
							'value' => (int) $lesson->id,
							'type'  => 'NUMERIC',
						),
					),
				)
			);

			foreach ( $top_comments as $tc ) {
				++$total_comments;
				$replies         = get_comments(
					array(
						'type'           => Lesson_Comment::COMMENT_TYPE,
						'parent'         => $tc->comment_ID,
						'status'         => 'approve',
						'orderby'        => 'comment_date_gmt',
						'order'          => 'ASC',
						'author__not_in' => $blocked_authors,
					)
				);
				$total_comments += count( $replies );
				$comment_tree[]  = array(
					'comment' => $tc,
					'replies' => $replies,
				);
			}
		}

		// Lesson-meta region (used by the embedded sidebar layout's header):
		// the course's primary instructor, the course category breadcrumb,
		// the current section name, and human-readable duration / release
		// date. The focus variant ignores these; resolving them here keeps the
		// template logic-free (architecture rule: zero queries in templates).
		// `?: null` is load-bearing — see the identical note in
		// class-single-course-view-data.php. get_userdata() returns FALSE for a
		// deleted user, instructor_profile_url() takes ?object, and bool is not
		// coercible to object, so a course whose author account no longer exists
		// took every one of its lessons down with a TypeError too — the enrolled
		// student's actual learning surface (Basecamp 10159762894).
		$instructor     = ! empty( $course->author_id ) ? ( get_userdata( (int) $course->author_id ) ?: null ) : null;
		$instructor_url = \Learnomy\instructor_profile_url( $instructor );

		$category     = ! empty( $course->category_id ) ? Category::find( (int) $course->category_id ) : null;
		$category_url = $category ? \Learnomy\route_url( 'category', array( 'slug' => (string) $category->slug ) ) : '';

		$section_name = '';
		if ( $current_section_id ) {
			foreach ( $sections as $section ) {
				if ( (int) $section->id === $current_section_id ) {
					$section_name = (string) $section->title;
					break;
				}
			}
		}

		$lesson_duration_label = self::format_duration( (int) ( $lesson->duration_seconds ?? 0 ) );

		$lesson_release_label = '';
		if ( ! empty( $lesson->created_at ) && '0000-00-00 00:00:00' !== $lesson->created_at ) {
			$release_ts = strtotime( (string) $lesson->created_at );
			if ( $release_ts ) {
				$lesson_release_label = wp_date( (string) get_option( 'date_format' ), $release_ts );
			}
		}

		// Course-wide lesson tally for the persistent sidebar nav header
		// ("X/Y complete"). Topics are excluded — the curriculum nav counts
		// lessons, mirroring how progress_pct is computed.
		$lesson_total = count( $all_lessons );
		$lesson_done  = 0;
		foreach ( $all_lessons as $tally_lesson ) {
			if ( isset( $completed_ids[ (int) $tally_lesson->id ] ) ) {
				++$lesson_done;
			}
		}

		// Lesson-attached quiz (lrn_quizzes.lesson_id). At most one per
		// lesson; null when none. Templates render a "Take Quiz" CTA only
		// when this is non-null. Standalone quizzes (no course_id) use
		// the standalone URL; course-attached use the nested one.
		//
		// Orphan guard: if the quiz row references a course_id but the
		// course row is missing (data drift / migration), fall back to
		// the standalone URL rather than fataling on `quiz_url()`'s
		// string typehint. The course-id mismatch surfaces as a
		// data-integrity finding via `wp learnomy test data-integrity`
		// (smoke F-2, 2026-05-27).
		$attached_quiz     = Quiz::get_for_lesson( (int) $lesson->id );
		$attached_quiz_url = '';
		if ( $attached_quiz ) {
			// Passing the lesson's course saves a lookup in the normal case.
			// It is used only when it really is the quiz's own course, so an
			// attached quiz that belongs to a DIFFERENT course now resolves
			// against its own — this branch used to accept any course in hand
			// and build a URL that 404s.
			$attached_quiz_url = Quiz::url( $attached_quiz, $course );
		}

		return array(
			'not_found'           => false,
			'course'              => $course,
			'lesson'              => $lesson,
			'user_id'             => $user_id,
			'access_requirement'  => $access_requirement,
			'is_admin'            => $is_admin,
			'is_instructor'       => $is_instructor,
			'is_enrolled'         => $is_enrolled,
			'can_access'          => $can_access,
			'enrollment'          => $enrollment,
			'progress'            => $progress,
			'is_completed'        => $is_completed,
			'sections'            => $sections,
			'all_lessons'         => $all_lessons,
			'completed_ids'       => $completed_ids,
			'progress_pct'        => $progress_pct,
			'current_section_id'  => $current_section_id,
			'prev_lesson'         => $prev_lesson,
			'next_lesson'         => $next_lesson,
			'prev_url'            => $prev_url,
			'next_url'            => $next_url,
			'video_provider'      => $video_provider,
			'video_embed_id'      => $video_embed_id,
			'video_embed_url'     => $video_embed_url,
			'require_watch_pct'   => $require_watch_pct,
			'section_lessons_map' => $section_lessons_map,
			'section_quizzes_map' => $section_quizzes_map,
			'lesson_topics_map'   => $lesson_topics_map,
			'comments_enabled'    => $comments_enabled,
			'comment_tree'        => $comment_tree,
			'total_comments'      => $total_comments,
			'instructor_ids'      => $instructor_ids,
			'course_settings'     => $course_settings,
			'require_sequential'  => $require_sequential,
			'locked_lesson_ids'   => $locked_lesson_ids,
			'lesson_attachments'  => $lesson_attachments,
			'locked'              => $locked,
			'unlocks_at'          => $unlocks_at,
			'unlocks_at_iso'      => $unlocks_at_iso,
			'instructor'          => $instructor,
			'instructor_url'      => $instructor_url,
			'category'            => $category,
			'category_url'        => $category_url,
			'section_name'        => $section_name,
			'duration_label'      => $lesson_duration_label,
			'release_label'       => $lesson_release_label,
			'lesson_total'        => $lesson_total,
			'lesson_done'         => $lesson_done,
			'attached_quiz'       => $attached_quiz,
			'attached_quiz_url'   => $attached_quiz_url,
		);
	}

	/**
	 * Format a duration in seconds as a short human label (e.g. "1h 5m").
	 * Mirrors Single_Course_View_Data::format_duration so the lesson meta
	 * row reads identically to the course page. Empty string for 0 seconds.
	 *
	 * @param int $seconds Duration in seconds.
	 * @return string Human-readable label, or '' when zero.
	 */
	private static function format_duration( int $seconds ): string {
		if ( $seconds <= 0 ) {
			return '';
		}

		$hours   = (int) floor( $seconds / 3600 );
		$minutes = (int) floor( ( $seconds % 3600 ) / 60 );

		if ( $hours > 0 && $minutes > 0 ) {
			/* translators: 1: number of hours, 2: number of minutes */
			return sprintf( __( '%1$dh %2$dm', 'learnomy' ), $hours, $minutes );
		} elseif ( $hours > 0 ) {
			/* translators: %d: number of hours */
			return sprintf( _n( '%d hour', '%d hours', $hours, 'learnomy' ), $hours );
		}

		/* translators: %d: number of minutes */
		return sprintf( _n( '%d minute', '%d minutes', $minutes, 'learnomy' ), $minutes );
	}

	/**
	 * Attach drip-schedule lock state to a lesson row in-place.
	 *
	 * Sets `drip_locked` (bool), `unlocks_at` (raw UTC datetime string or empty),
	 * and `unlocks_at_iso` (ISO 8601 with UTC offset for JS) on the row.
	 *
	 * When $course is provided the admin/author bypass check runs without an
	 * extra DB query — pass the already-loaded course row from resolve() to
	 * avoid the N+1 that `Lesson_Service::is_available_to()` would otherwise
	 * incur (`Course::find()` per lesson × N lessons per sidebar build).
	 *
	 * @param object      $lesson_row Lesson or topic row (mutated in place).
	 * @param int         $user_id    Viewer user id (0 for anonymous).
	 * @param object|null $course     Parent course row (already loaded by caller).
	 */
	private static function decorate_drip( $lesson_row, int $user_id, ?object $course = null ): void {
		if ( ! is_object( $lesson_row ) || empty( $lesson_row->id ) ) {
			return;
		}

		$lesson_id = (int) $lesson_row->id;

		// Admin bypass — always available; skip all other checks.
		if ( $user_id > 0 && user_can( $user_id, 'manage_options' ) ) {
			$lesson_row->drip_locked    = false;
			$lesson_row->unlocks_at     = '';
			$lesson_row->unlocks_at_iso = '';
			return;
		}

		// Course-author bypass — uses the pre-loaded $course to avoid Course::find().
		if ( $user_id > 0 && $course && (int) ( $course->author_id ?? 0 ) === $user_id ) {
			$lesson_row->drip_locked    = false;
			$lesson_row->unlocks_at     = '';
			$lesson_row->unlocks_at_iso = '';
			return;
		}

		// Delegate to the service which handles relative-drip + fixed-date +
		// section-level fallback + the learnomy_can_access_lesson filter (Pro hook).
		// The service's own Lesson::find() and Course::find() calls are eliminated
		// by the early-return paths above for the two most common bypasses
		// (admin + author); only unenrolled/enrolled-student paths reach here.
		$row_locked = ! Lesson_Service::is_available_to( $lesson_id, $user_id );
		$row_when   = $row_locked ? (string) ( $lesson_row->available_date ?? '' ) : '';
		// When a lesson is locked but Free has no fixed available_date (a Pro
		// relative/date drip rule), let an extension supply the unlock datetime
		// so the existing countdown chip in lesson-locked.php renders. $course is
		// passed so the listener avoids re-loading it on the hot path. [card 10062795757]
		if ( $row_locked && '' === $row_when ) {
			$row_when = (string) apply_filters( 'learnomy_lesson_unlock_date', $row_when, $lesson_id, $user_id, $course );
		}
		$row_iso = '';
		if ( $row_when ) {
			try {
				$utc     = new \DateTimeImmutable( $row_when, new \DateTimeZone( 'UTC' ) );
				$row_iso = $utc->format( 'c' );
			} catch ( \Exception $e ) {
				$row_iso = '';
			}
		}

		$lesson_row->drip_locked    = $row_locked;
		$lesson_row->unlocks_at     = $row_when;
		$lesson_row->unlocks_at_iso = $row_iso;
	}
}
