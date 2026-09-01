<?php
/**
 * Course business logic — the only place that orchestrates course lifecycle.
 *
 * @package Learnomy
 */

namespace Learnomy\Services;

defined( 'ABSPATH' ) || exit;

use Learnomy\DB\Relations;

use Learnomy\Models\Course;
use Learnomy\Models\Section;
use Learnomy\Models\Lesson;
use Learnomy\Models\CourseInstructor;
use Learnomy\Models\Enrollment;
use Learnomy\Models\Progress;
use Learnomy\Models\Review;
use Learnomy\Models\Quiz;
use Learnomy\Models\Tag;
use Learnomy\Permissions\Permission_Engine;
use function Learnomy\now;

class Course_Service {

	public function create( array $data, int $author_id ): int {
		if ( empty( $data['title'] ) ) {
			return 0;
		}

		$data['author_id'] = $author_id;

		if ( empty( $data['slug'] ) ) {
			$data['slug'] = $this->unique_slug( sanitize_title( $data['title'] ) );
		}

		$data = apply_filters( 'learnomy_course_create_data', $data, $author_id );

		$course_id = Course::create( $data );

		if ( ! $course_id ) {
			return 0;
		}

		CourseInstructor::add_instructor( $course_id, $author_id, 100.00, true );

		do_action( 'learnomy_course_created', $course_id, $data );

		// A course created directly in the published state is also a publish
		// event — fire the canonical hook so integrators (e.g. a forum bridge)
		// get one reliable signal regardless of how the course was published.
		if ( isset( $data['status'] ) && 'published' === $data['status'] ) {
			do_action( 'learnomy_course_published', $course_id );
		}

		return $course_id;
	}

	public function update( int $id, array $data ): bool {
		$data['updated_at'] = now();

		$data = apply_filters( 'learnomy_course_update_data', $data, $id );

		// Detect a transition INTO the published state so the canonical
		// `learnomy_course_published` hook fires on the everyday admin-form
		// save path too — not only via Course_Service::publish() (the dedicated
		// /publish endpoint the admin form never calls). Without this, any
		// listener on course-publish (forum auto-create, notifications, an
		// external integration) silently misses admin publishes. Read the prior
		// status first so the event fires once, on the draft -> published edge.
		$publishing    = isset( $data['status'] ) && 'published' === $data['status'];
		$was_published = false;
		if ( $publishing ) {
			$existing      = Course::find( $id );
			$was_published = $existing && 'published' === ( $existing->status ?? '' );
		}

		$result = Course::update( $id, $data );

		if ( $result ) {
			do_action( 'learnomy_course_updated', $id, $data );

			if ( $publishing && ! $was_published ) {
				do_action( 'learnomy_course_published', $id );
			}
		}

		return $result;
	}

	public function publish( int $id ): bool {
		// Marketplace approval gate. A non-admin instructor's publish is
		// downgraded to `pending_review` (awaiting admin approval) when the
		// course is gated — see requires_approval(). Admins always bypass:
		// that's exactly how the Pending Review "Approve" action publishes.
		if ( ! current_user_can( 'manage_options' ) && $this->requires_approval( $id ) ) {
			$result = Course::update(
				$id,
				[
					'status'     => 'pending_review',
					'updated_at' => now(),
				]
			);
			if ( $result ) {
				do_action( 'learnomy_course_pending_review', $id );
			}
			return $result;
		}

		$result = Course::update(
			$id,
			[
				'status'     => 'published',
				'updated_at' => now(),
			]
		);

		if ( $result ) {
			do_action( 'learnomy_course_published', $id );
		}

		return $result;
	}

	/**
	 * Whether a course's publish must be routed through admin approval.
	 *
	 * Gated when EITHER (a) the site-wide "instructors self-publish" setting is
	 * off, OR (b) the course's owning instructor carries a per-user override
	 * (`learnomy_require_course_approval` user meta), which lets a site require
	 * review for a single instructor while everyone else self-publishes.
	 *
	 * Both halves live in Permission_Engine now — this service used to read the
	 * option and the meta itself, and the engine read only the option, so the
	 * REST controller (which writes `status` from the engine's answer) let a
	 * flagged instructor publish straight past the override that this method
	 * enforced. One policy needs one reader.
	 *
	 * The question is asked about the COURSE AUTHOR, not whoever triggered the
	 * publish, so the gate follows the course's owner.
	 *
	 * @param int $course_id Course being published.
	 */
	private function requires_approval( int $course_id ): bool {
		$course    = Course::find( $course_id );
		$author_id = $course ? (int) $course->author_id : 0;

		if ( $author_id <= 0 ) {
			// Authorless row (should not happen): fall back to the site policy
			// alone rather than treating "unknown author" as approved.
			return ! Permission_Engine::can( get_current_user_id(), 'self_publish_course' );
		}

		return ! Permission_Engine::can( $author_id, 'self_publish_course' );
	}

	public function delete( int $id ): bool {
		// Full cascade so the course row vanishes cleanly:
		// 1. Enrollments (per-row delete() cascades progress + quiz attempts
		// and decrements student_count via the model override)
		// 2. Reviews (course_id orphans)
		// 3. Sections → lessons (curriculum content), each via
		// Lesson_Service::delete() so `learnomy_lesson_deleted` fires
		// 4. Course-attached quizzes, via the quiz cascade
		// 5. The instructor junction and the course's tag links
		// Order matters: take dependents before the parent so a mid-cascade
		// failure leaves a consistent state instead of mixed orphans.
		//
		// This is the ONE chokepoint for removing a course. Nothing should
		// hand-roll the list above — the GDPR eraser did, and its copy went
		// stale (Basecamp 10148252148).
		//
		// CERTIFICATES ARE NOT IN THIS LIST, DELIBERATELY. A certificate is not a
		// part of the course; it is the record of something a student did, and it
		// is the one artefact whose job is to stay true after the thing it
		// describes is gone. This cascade used to take them, so an owner tidying
		// their catalogue silently destroyed credentials their students had
		// already earned, shared, and linked from a CV — every /verify/ URL for
		// that course started 404ing, with no warning and no undo. Since 3.33.0
		// each certificate records what it certifies (see DB\Schema), so it
		// stands on its own and simply outlives the course.
		//
		// Retiring a course and voiding a credential are different intentions and
		// now have different tools: this deletes the course, and revoke (single
		// or bulk, in Learnomy > Certificates) voids a credential while KEEPING
		// the record, so /verify/{uuid} still resolves and honestly reports the
		// revocation instead of going blank (Basecamp 10148480574).
		Enrollment::delete_for_course( $id );
		Review::delete_for_course( $id );

		// Route every lesson removal through Lesson_Service::delete() so the
		// `learnomy_lesson_deleted` action fires per lesson — the same path the
		// REST delete endpoint uses. The bare Lesson::delete() base-model method
		// fires no hook, so calling it directly here used to silently orphan
		// every Pro child row (notes/bookmarks, lrn_pro_drip_rules,
		// lrn_pro_lesson_revisions, lesson unlocks) on course deletion.
		foreach ( Section::get_for_course( $id ) as $section ) {
			$this->delete_section_with_lessons( (int) $section->id, $id );
		}

		// Delete lessons that belong to the course but have no section. Every
		// status — a published-only cascade would orphan draft lessons and
		// their Pro child rows.
		$orphan_lessons = Lesson::get_for_course( $id, 0, 0, null );
		foreach ( $orphan_lessons as $lesson ) {
			Lesson_Service::delete( (int) $lesson->id, $id );
		}

		// Course-attached quizzes (course_id > 0) belong to the course, so they
		// go with it — each via the quiz cascade (responses -> attempts -> slots
		// -> quiz) so nothing is orphaned. Standalone quizzes (course_id = 0) are
		// independent and intentionally untouched.
		// The section pass above has already taken every quiz that belonged to a
		// section, and the lesson pass every quiz attached to a lesson. This
		// query is deliberately the widest of the three so a quiz pinned to
		// neither — a course-level quiz — still goes, and so does one whose
		// section_id points at a section that was already gone before this run.
		// Every status, not just published — a draft quiz still belongs to the
		// course and has to be cleaned up with it, or deleting the course
		// leaves its drafts orphaned in the table.
		$quiz_service = new Quiz_Service();
		foreach ( Quiz::get_for_course( $id, null ) as $quiz ) {
			$quiz_service->delete_quiz( (int) $quiz->id );
		}

		// The teaching-assignment junction and the course's tag links are pure
		// pointers at a course that is about to stop existing. Nothing else
		// removes them, so every course deletion — REST, admin, and the GDPR
		// eraser — used to leave them behind; the eraser was the only caller
		// that cleared the junction, by hand (Basecamp 10148252148).
		// Empty tag set clears lrn_tag_map AND recomputes each tag's
		// denormalised usage_count — the canonical chokepoint.
		CourseInstructor::delete_for_course( $id );
		Tag::sync_object_tags( $id, 'course', array() );

		// This course's id must not stay behind in other courses'
		// prerequisites — a dangling prerequisite id read as a forever-unmet
		// gate while displaying as nothing (Basecamp 10155834186).
		Course::remove_prerequisite_from_all( $id );

		$result = Course::delete( $id );

		if ( $result ) {
			do_action( 'learnomy_course_deleted', $id );
		}

		return $result;
	}

	/**
	 * Delete one section, everything it owns, and announce it.
	 *
	 * The ONE entry point for removing a section. Both the full course teardown
	 * (delete() above) and the standalone REST path
	 * (`DELETE /learnomy/v1/sections/{id}`) call this, so the two can never
	 * disagree about what goes with a section.
	 *
	 * What a section CONTAINS is not decided here — it is decided once, in
	 * {@see Section::delete()}. This method owns only the two things a model
	 * cannot: resolving the owning course for the event payload, and firing
	 * `learnomy_section_deleted` so extensions can drop their own
	 * section-keyed rows. That action used to be fired by the REST controller
	 * alone, which meant a section removed as part of a course teardown was
	 * silently invisible to every listener — the same one-path-knows,
	 * one-path-doesn't split that produced the orphan-quiz bug this method's
	 * cascade now fixes (Basecamp 10132809117).
	 *
	 * The name is kept for its callers; the cascade is no longer lessons-only.
	 *
	 * @param int $section_id Section to delete.
	 * @param int $course_id  Owning course (resolved from the section when 0).
	 * @return bool True when the section row was removed.
	 */
	public function delete_section_with_lessons( int $section_id, int $course_id = 0 ): bool {
		if ( $course_id <= 0 ) {
			$section   = Section::find( $section_id );
			$course_id = $section ? (int) $section->course_id : 0;
		}

		$deleted = Section::delete( $section_id );

		if ( $deleted ) {
			/**
			 * Fires after a section and everything it owned have been removed.
			 *
			 * Fires on EVERY section-removal path — the REST delete and the
			 * per-section loop inside a full course teardown. Extensions with
			 * section-keyed rows should clean up here rather than have Free
			 * hardcode knowledge of their tables.
			 *
			 * @param int $section_id Section row id that was deleted.
			 * @param int $course_id  Course the section belonged to.
			 */
			do_action( 'learnomy_section_deleted', $section_id, $course_id );
		}

		return $deleted;
	}

	public function duplicate( int $id ): int {
		$source = Course::find( $id );
		if ( ! $source ) {
			return 0;
		}

		$new_data       = (array) $source;
		$original_title = $new_data['title'];
		unset( $new_data['id'], $new_data['created_at'], $new_data['updated_at'] );
		$new_data['title']         = $original_title . ' (Copy)';
		$new_data['slug']          = $this->unique_slug( sanitize_title( $new_data['title'] ) );
		$new_data['status']        = 'draft';
		$new_data['student_count'] = 0;
		$new_data['avg_rating']    = 0.00;
		$new_data['review_count']  = 0;

		$new_course_id = Course::create( $new_data );
		if ( ! $new_course_id ) {
			return 0;
		}

		// Copy instructors.
		foreach ( CourseInstructor::get_for_course( $id ) as $instructor ) {
			CourseInstructor::add_instructor(
				$new_course_id,
				(int) $instructor->user_id,
				(float) $instructor->share_pct,
				(bool) $instructor->is_primary
			);
		}

		// Clone sections and their lessons.
		foreach ( Section::get_for_course( $id ) as $section ) {
			$section_data = (array) $section;
			unset( $section_data['id'], $section_data['created_at'] );
			$section_data['course_id'] = $new_course_id;

			$new_section_id = Section::create( $section_data );

			foreach ( Lesson::get_for_section( (int) $section->id, 0, 0, null ) as $lesson ) { // Duplicate copies the FULL curriculum, drafts included.
				$lesson_data = (array) $lesson;
				unset( $lesson_data['id'], $lesson_data['created_at'], $lesson_data['updated_at'] );
				$lesson_data['course_id']  = $new_course_id;
				$lesson_data['section_id'] = $new_section_id;
				$lesson_data['slug']       = $this->unique_lesson_slug( sanitize_title( $lesson_data['title'] ) );
				Lesson::create( $lesson_data );
			}
		}

		do_action( 'learnomy_course_duplicated', $new_course_id, $id );

		return $new_course_id;
	}

	public function get_completion_status( int $user_id, int $course_id ): array {
		$course = Course::find( $course_id );
		if ( ! $course ) {
			return [
				'complete' => false,
				'reason'   => 'course_not_found',
			];
		}

		$all_lessons   = Lesson::get_for_course( $course_id );
		$total_lessons = count( $all_lessons );

		if ( 0 === $total_lessons ) {
			return [
				'complete' => false,
				'reason'   => 'no_lessons',
			];
		}

		$progress_rows     = Progress::get_all_for_course( $user_id, $course_id );
		$completed_lessons = array_filter( $progress_rows, fn( $p ) => 'completed' === $p->status );
		$completed_count   = count( $completed_lessons );

		$type = $course->completion_type ?? 'all_lessons';

		switch ( $type ) {
			case 'all_lessons':
				$complete = $completed_count >= $total_lessons;
				return [
					'complete'  => $complete,
					'completed' => $completed_count,
					'total'     => $total_lessons,
				];

			case 'percentage':
				// completion_value holds the required percentage threshold (e.g. 80).
				$threshold = (int) ( $course->completion_value ?? 100 );
				$pct       = $total_lessons > 0 ? (int) round( ( $completed_count / $total_lessons ) * 100 ) : 0;
				$complete  = $pct >= $threshold;
				return [
					'complete'  => $complete,
					'pct'       => $pct,
					'threshold' => $threshold,
				];

			case 'lessons_and_quiz':
				// All lessons must be done; quiz pass state is verified by the caller
				// because quiz results live in a separate domain (quiz attempts).
				$lessons_done = $completed_count >= $total_lessons;
				return [
					'complete'      => $lessons_done,
					'lessons_done'  => $lessons_done,
					'quiz_required' => true,
				];

			case 'manual':
				// Admins or instructors mark completion explicitly — service never auto-completes.
				return [
					'complete' => false,
					'reason'   => 'manual_completion',
				];

			default:
				return [
					'complete' => false,
					'reason'   => 'unknown_completion_type',
				];
		}
	}

	public function check_prerequisites( int $user_id, int $course_id ): bool {
		return array() === $this->unmet_prerequisites( $user_id, $course_id );
	}

	/**
	 * The prerequisite courses this user still has to COMPLETE before the
	 * given course accepts a new enrollment.
	 *
	 * The single decider behind every prerequisite gate — the enroll gate,
	 * the checkout guard and any CTA all read this one answer, so they can
	 * never disagree about who is blocked and on what. Returns full Course
	 * rows (not ids) so a caller can name the missing courses to the person
	 * being refused instead of failing generically.
	 *
	 * A prerequisite id that no longer resolves to a course is SKIPPED, not
	 * treated as unmet. Demo/import data and course deletion both leave
	 * dangling ids behind, and requiring completion of a course that does
	 * not exist is a gate nobody can ever pass: course 1477 shipped with
	 * prerequisites [55,66] pointing at nothing and was silently
	 * un-enrollable for every buyer while staying fully purchasable
	 * (Basecamp 10155834186 — money taken, no access, no message).
	 *
	 * @return object[] Unmet prerequisite Course rows; empty when clear.
	 */
	public function unmet_prerequisites( int $user_id, int $course_id ): array {
		$course = Course::find( $course_id );
		if ( ! $course || empty( $course->prerequisites ) ) {
			// No prerequisites configured means open to everyone.
			return array();
		}

		// One decoder — Course::prerequisite_ids(). See the docblock there: three
		// readers each re-derived the JSON format and Pro's builder guessed CSV,
		// which silently ate the first prerequisite.
		$prereq_ids = Course::prerequisite_ids( $course );
		if ( empty( $prereq_ids ) ) {
			return array();
		}

		$unmet = array();
		foreach ( $prereq_ids as $prereq_course_id ) {
			$prereq_course_id = (int) $prereq_course_id;

			// Dangling id — the course it named is gone. Skip: see docblock.
			$prereq_course = Course::find( $prereq_course_id );
			if ( ! $prereq_course ) {
				continue;
			}

			// find_for_user_course (active + completed), NOT find_active
			// (active only): completing a prerequisite flips its enrollment to
			// status='completed', which find_active() cannot see — so the gate
			// could NEVER pass once a prereq was genuinely finished (Zoho #40975,
			// find_active class hub 10108551275). The status check below still
			// requires 'completed'.
			$enrollment = Enrollment::find_for_user_course( $user_id, $prereq_course_id );

			// Prerequisites require a completed (not just active) enrollment.
			if ( ! $enrollment || 'completed' !== $enrollment->status ) {
				$unmet[] = $prereq_course;
			}
		}

		return $unmet;
	}

	private function unique_slug( string $base ): string {
		$slug    = $base;
		$counter = 1;

		// Skip slugs already taken AND reserved route segments — a course
		// slugged "category" / "lesson" / "quiz" would shadow the curriculum
		// sub-routes under /courses/ and become unreachable (Basecamp
		// #9937134001). Both cases bump to "{base}-{n}".
		while ( Course::find_by_slug( $slug ) || \Learnomy\is_reserved_slug( $slug ) ) {
			$slug = $base . '-' . $counter;
			++$counter;
		}

		return $slug;
	}

	private function unique_lesson_slug( string $base ): string {
		$slug    = $base;
		$counter = 1;

		while ( Lesson::find_by_slug( $slug ) ) {
			$slug = $base . '-' . $counter;
			++$counter;
		}

		return $slug;
	}
}
