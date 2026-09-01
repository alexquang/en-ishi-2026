<?php
/**
 * View-data resolver for the student dashboard template.
 *
 * Replaces all Model calls in templates/student-dashboard.php with a
 * pre-resolved data array so the template has zero DB queries.
 *
 * @package Learnomy
 */

namespace Learnomy\Services\View_Data;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Enrollment;
use Learnomy\Models\Course;
use Learnomy\Models\Certificate;
use Learnomy\Models\Lesson;
use Learnomy\Models\Progress;
use Learnomy\Models\Notification;
use Learnomy\Models\QuizAttempt;
use Learnomy\Models\Quiz;
use Learnomy\Models\Subscription;
use Learnomy\Services\Resume;
use Learnomy\Models\Membership_Plan;

class Student_Dashboard_View_Data {

	/**
	 * Resolve all data needed by templates/student-dashboard.php.
	 *
	 * @param array $data Route data (typically just 'route' => 'student-dashboard').
	 * @return array Pre-fetched view data.
	 */
	public static function resolve( array $data ): array {
		$user_id = get_current_user_id();
		$user    = wp_get_current_user();

		// Use get_distinct_for_user() so the card grid shows one card per course
		// even when the student holds multiple enrollment rows for the same course
		// under different sources (e.g. free + manual). CF-007.
		$enrollments = Enrollment::get_distinct_for_user( $user_id );

		// Separate in-progress from completed enrollments, on the SAME predicate
		// the SQL tabs and counts use (Enrollment::is_in_progress) rather than a
		// second local definition. The old split had a third branch for
		// `progress_pct > 0` that sent zero-percent rows to $active_enrollments
		// only, so a learner who had opened lessons but finished none was counted
		// by the "In Progress" tile (SQL, correct) and then absent from the rail
		// and the resume hero that read this array (Basecamp 10183993951).
		//
		// $active_enrollments already held every non-completed row under the old
		// code — the honest set was sitting in the same loop under another name,
		// and the widgets simply read the wrong one of the two. It stays because
		// child themes overriding these partials receive it.
		$completed_enrollments   = array();
		$in_progress_enrollments = array();
		foreach ( $enrollments as $e ) {
			if ( Enrollment::is_completed( $e ) ) {
				$completed_enrollments[] = $e;
			} else {
				$in_progress_enrollments[] = $e;
			}
		}
		$active_enrollments = $in_progress_enrollments;

		// Celebration trigger.
		$celebrate_course = isset( $_GET['completed'] ) ? absint( $_GET['completed'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Certificates — active only, so a revoked/expired one doesn't show on the
		// student's dashboard as earned (Basecamp 10109147988).
		$certificates = Certificate::get_active_for_user( $user_id );

		// Wishlist courses. Pass the normalized id list through to the views
		// (lrn_wishlist below) so each course card reads the heart's filled
		// state from this one array instead of re-querying user meta per card
		// (an N+1 on the dashboard's course grids — CLAUDE.md rule 8).
		$lrn_wishlist     = \Learnomy\wishlist_ids( $user_id );
		$wishlist_courses = array();
		foreach ( $lrn_wishlist as $wl_course_id ) {
			$wl_course = Course::find( (int) $wl_course_id );
			if ( $wl_course && 'published' === $wl_course->status ) {
				$wishlist_courses[] = $wl_course;
			}
		}

		// Pre-fetch course objects for enrollments (used in all tabs).
		$enrollment_courses = array();
		foreach ( $enrollments as $enrollment ) {
			$c = Course::find( (int) $enrollment->course_id );
			if ( $c ) {
				$enrollment_courses[ (int) $enrollment->course_id ] = $c;
			}
		}

		// Recent quiz attempts — finished only (graded or submitted). The
		// attempt status enum is in_progress / submitted / graded; the old
		// query asked for 'completed', which never occurs, so this feed and
		// the average score were hardwired to empty/0 on every install.
		$recent_attempts = array();
		if ( class_exists( QuizAttempt::class ) ) {
			$recent_attempts = QuizAttempt::get_recent_finished_for_user( $user_id, 5 );
		}

		// null means "no quizzes sat yet", which is a different statement from an
		// average of zero. Returning 0 here put "0% Avg Quiz Score" on the first
		// screen a new learner sees after enrolling, telling them they had failed
		// something they had never opened.
		$avg_quiz_score = null;
		if ( $recent_attempts ) {
			// Filter on IS-NUMERIC, not on truthiness. array_filter()'s default
			// test drops every falsy value, so a genuine 0% sitting counts as no
			// sitting at all. It survives today only because the DB hands back
			// the string "0.00", which is truthy; the moment percentage is cast
			// to a float anywhere upstream, every student who bombed a quiz
			// quietly gets a flattering average instead.
			$scores = array_values(
				array_filter(
					array_column( $recent_attempts, 'percentage' ),
					static fn( $percentage ) => is_numeric( $percentage )
				)
			);
			if ( $scores ) {
				$avg_quiz_score = (int) round( array_sum( array_map( 'floatval', $scores ) ) / count( $scores ) );
			}
		}

		// Pre-fetch quiz objects for quiz attempts.
		$attempt_quizzes = array();
		if ( $recent_attempts && class_exists( Quiz::class ) ) {
			foreach ( $recent_attempts as $attempt ) {
				$quiz = Quiz::find( (int) $attempt->quiz_id );
				if ( $quiz ) {
					$attempt_quizzes[ (int) $attempt->quiz_id ] = $quiz;
				}
			}
		}

		// Membership subscription.
		$membership_sub  = null;
		$membership_plan = null;
		if ( class_exists( Subscription::class ) ) {
			$subs           = Subscription::get_active_for_user( $user_id );
			$membership_sub = $subs ? $subs[0] : null;
		}
		if ( $membership_sub && class_exists( Membership_Plan::class ) ) {
			$membership_plan = Membership_Plan::find( (int) $membership_sub->plan_id );
		}

		// Announcements.
		$announcements = Notification::where(
			array(
				'user_id' => $user_id,
				'type'    => 'announcement',
			),
			'created_at',
			'DESC',
			5,
			0
		);

		// Pre-fetch course and actor data for announcements.
		$announcement_courses = array();
		$announcement_actors  = array();
		foreach ( $announcements as $ann ) {
			if ( (int) $ann->object_id > 0 && ! isset( $announcement_courses[ (int) $ann->object_id ] ) ) {
				$announcement_courses[ (int) $ann->object_id ] = Course::find( (int) $ann->object_id );
			}
			if ( ! empty( $ann->actor_id ) && ! isset( $announcement_actors[ (int) $ann->actor_id ] ) ) {
				$announcement_actors[ (int) $ann->actor_id ] = get_user_by( 'ID', (int) $ann->actor_id );
			}
		}

		// Recent activity (progress entries).
		$recent_progress = array();
		if ( class_exists( Progress::class ) ) {
			$recent_progress = Progress::where(
				array( 'user_id' => $user_id ),
				'updated_at',
				'DESC',
				5,
				0
			);
		}

		// Pre-fetch lesson objects for recent progress.
		$progress_lessons = array();
		foreach ( $recent_progress as $p ) {
			$lesson = Lesson::find( (int) $p->item_id );
			if ( $lesson ) {
				$progress_lessons[ (int) $p->item_id ] = $lesson;
			}
		}

		// Stats — counted via dedicated COUNT(*) queries, NOT count() over the
		// capped enrollment list, so the "View all (N)" link on the home preview
		// stays accurate for a learner with hundreds of courses (the in-memory
		// list above is capped at 200 for the preview + resume target).
		$total_enrolled    = Enrollment::count_distinct_for_user( $user_id, 'all' );
		$total_completed   = Enrollment::count_distinct_for_user( $user_id, 'completed' );
		$total_in_progress = Enrollment::count_distinct_for_user( $user_id, 'in-progress' );
		$total_wishlist    = count( $wishlist_courses );

		// Rail order for the dashboard's compact course strip, which is headed
		// "Continue your Courses". Once a 0%-progress enrollment counts as in
		// progress — which it must — "in progress, newest enrollment first" is no
		// longer the same list as "what you were working on": a learner holding 60
		// membership courses would be offered six they have never opened under a
		// heading promising to continue them. This ORDERS, it does not filter, so
		// every in-progress course is still reachable through View all.
		//
		// Derived from the recent-progress rows already fetched above — no extra
		// query, and it stays correct on an account with thousands of enrollments.
		$touched_rank = array();
		foreach ( $recent_progress as $lrn_p ) {
			$lrn_lesson = $progress_lessons[ (int) $lrn_p->item_id ] ?? null;
			if ( $lrn_lesson && ! isset( $touched_rank[ (int) $lrn_lesson->course_id ] ) ) {
				$touched_rank[ (int) $lrn_lesson->course_id ] = count( $touched_rank );
			}
		}
		// Whether anything is genuinely being CONTINUED, which is a different
		// question from whether anything is in progress and is what the rail's
		// heading claims. A learner who has enrolled but opened nothing gets
		// "My Courses"; only real activity earns "Continue your Courses".
		$continue_has_activity = ! empty( $touched_rank );

		$continue_enrollments = $in_progress_enrollments;
		usort(
			$continue_enrollments,
			static function ( $a, $b ) use ( $touched_rank ) {
				$rank_a = $touched_rank[ (int) $a->course_id ] ?? PHP_INT_MAX;
				$rank_b = $touched_rank[ (int) $b->course_id ] ?? PHP_INT_MAX;
				if ( $rank_a !== $rank_b ) {
					return $rank_a <=> $rank_b;
				}
				// Then whoever is furthest along, so a part-finished course beats an
				// untouched one. PHP 8's sort is stable, so equal rows keep the
				// newest-enrollment-first order they arrived in.
				return (int) $b->progress_pct <=> (int) $a->progress_pct;
			}
		);

		// Resume target — powers the "Continue learning" hero widget. The
		// most-recently-touched in-progress enrollment + its first
		// non-completed lesson. Returns null when there's nothing to resume.
		$resume_target = self::resolve_resume_target(
			$user_id,
			$recent_progress,
			$progress_lessons,
			$in_progress_enrollments,
			$enrollment_courses
		);

		return array(
			'user_id'                 => $user_id,
			'user'                    => $user,
			'enrollments'             => $enrollments,
			'active_enrollments'      => $active_enrollments,
			'completed_enrollments'   => $completed_enrollments,
			'in_progress_enrollments' => $in_progress_enrollments,
			'continue_enrollments'    => $continue_enrollments,
			'continue_has_activity'   => $continue_has_activity,
			'celebrate_course'        => $celebrate_course,
			'certificates'            => $certificates,
			// What each credential says it is for, snapshot first. This replaced a
			// per-certificate Course::find() loop that only existed to print a
			// title the certificate can now state for itself.
			'certificate_titles'      => \Learnomy\Services\Certificate_Service::display_titles( $certificates ),
			'wishlist_courses'        => $wishlist_courses,
			'lrn_wishlist'            => $lrn_wishlist,
			'enrollment_courses'      => $enrollment_courses,
			'recent_attempts'         => $recent_attempts,
			'avg_quiz_score'          => $avg_quiz_score,
			'attempt_quizzes'         => $attempt_quizzes,
			'membership_sub'          => $membership_sub,
			'membership_plan'         => $membership_plan,
			'announcements'           => $announcements,
			'announcement_courses'    => $announcement_courses,
			'announcement_actors'     => $announcement_actors,
			'recent_progress'         => $recent_progress,
			'progress_lessons'        => $progress_lessons,
			'total_enrolled'          => $total_enrolled,
			'total_completed'         => $total_completed,
			'total_in_progress'       => $total_in_progress,
			'total_wishlist'          => $total_wishlist,
			'resume_target'           => $resume_target,
		);
	}

	/**
	 * Pick the lesson the student should "Continue" from. Strategy:
	 *
	 *   1. Walk recent_progress newest-first.
	 *   2. Skip rows whose course is not in `in_progress_enrollments`
	 *      (completed courses + practice quizzes outside any enrollment).
	 *   3. For the first match, compute lesson position + total lessons via
	 *      the course's lesson list, then return a render-ready bundle.
	 *   4. When that course has no lesson left to resume, fall back to the exam
	 *      it still gates completion on (`step_type` = quiz) before moving on.
	 *
	 * Returns null when there is nothing in progress. The widget's
	 * is_available() reads that null and silently drops the hero.
	 *
	 * @return array|null { course, lesson, url, step_type, position, total_lessons, status, progress_pct }
	 */
	private static function resolve_resume_target(
		int $user_id,
		array $recent_progress,
		array $progress_lessons,
		array $in_progress_enrollments,
		array $enrollment_courses
	): ?array {
		if ( ! $recent_progress ) {
			return null;
		}

		$in_progress_course_ids = array();
		foreach ( $in_progress_enrollments as $enr ) {
			$in_progress_course_ids[ (int) $enr->course_id ] = true;
		}
		if ( ! $in_progress_course_ids ) {
			return null;
		}

		foreach ( $recent_progress as $row ) {
			$lesson = $progress_lessons[ (int) $row->item_id ] ?? null;
			if ( ! $lesson ) {
				continue;
			}
			$course_id = (int) $lesson->course_id;
			if ( ! isset( $in_progress_course_ids[ $course_id ] ) ) {
				continue;
			}
			$course = $enrollment_courses[ $course_id ] ?? null;
			if ( ! $course || empty( $course->slug ) ) {
				continue;
			}

			// Same lesson source + resume logic as the single-course CTA and the
			// rail (via the shared Resume service) so the hero deep-links to the
			// exact lesson those surfaces do — the last-accessed incomplete
			// lesson, advancing past any the student already finished.
			$all_lessons   = Lesson::get_for_course( $course_id );
			$resume_lesson = Resume::lesson( $user_id, $course, $all_lessons );
			if ( ! $resume_lesson || empty( $resume_lesson->slug ) ) {
				// No lesson left, but the course may still gate completion on an
				// exam — the student's actual next step. This used to `continue`,
				// so the hero silently skipped their newest course and advertised
				// an older one (or vanished), while the single-course CTA and the
				// rail both deep-linked to that exam. Same divergence Basecamp
				// 10134140444 is about, on the one Continue surface that resolves
				// its own target instead of calling Resume::url().
				$pending_quiz = Resume::pending_quiz( $user_id, $course );
				if ( ! $pending_quiz ) {
					continue;
				}

				return array(
					'course'        => $course,
					// The exam IS the step here. Keeping the `lesson` key populated
					// (rather than null + a new key) means a theme override written
					// against this bundle still renders — it prints the exam title
					// instead of fatalling on a null.
					'lesson'        => $pending_quiz,
					'url'           => Resume::quiz_url( $course, $pending_quiz ),
					'step_type'     => 'quiz',
					// Zero suppresses the "Lesson %d of %d" line, which would be a
					// lie about a step that is not a lesson.
					'position'      => 0,
					'total_lessons' => 0,
					'status'        => (string) $row->status,
					'progress_pct'  => self::progress_pct_for_course( $in_progress_enrollments, $course_id ),
				);
			}

			$position      = 0;
			$total_lessons = count( $all_lessons );
			foreach ( $all_lessons as $i => $lex ) {
				if ( (int) $lex->id === (int) $resume_lesson->id ) {
					$position = $i + 1;
					break;
				}
			}

			return array(
				'course'        => $course,
				'lesson'        => $resume_lesson,
				'url'           => \Learnomy\route_url(
					'lesson',
					array(
						'slug'        => (string) $course->slug,
						'lesson_slug' => (string) $resume_lesson->slug,
					)
				),
				'step_type'     => 'lesson',
				'position'      => $position,
				'total_lessons' => $total_lessons,
				'status'        => (string) $row->status,
				'progress_pct'  => self::progress_pct_for_course( $in_progress_enrollments, $course_id ),
			);
		}
		return null;
	}

	private static function progress_pct_for_course( array $in_progress_enrollments, int $course_id ): int {
		foreach ( $in_progress_enrollments as $enr ) {
			if ( (int) $enr->course_id === $course_id ) {
				return (int) ( $enr->progress_pct ?? 0 );
			}
		}
		return 0;
	}
}
