<?php
/**
 * View-data resolver for the single course template.
 *
 * Replaces all Model calls in templates/single-course.php with a
 * pre-resolved data array so the template has zero DB queries.
 *
 * @package Learnomy
 */

namespace Learnomy\Services\View_Data;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Certificate;
use Learnomy\Models\Course;
use Learnomy\Models\Category;
use Learnomy\Models\Enrollment;
use Learnomy\Models\Notification;
use Learnomy\Models\Progress;
use Learnomy\Models\Review;
use Learnomy\Models\Section;
use Learnomy\Models\Lesson;
use Learnomy\Services\Resume;
use Learnomy\Models\Quiz;
use Learnomy\Services\Lesson_Service;
use Learnomy\Services\Progress_Service;

class Single_Course_View_Data {

	/**
	 * Resolve all data needed by templates/single-course.php.
	 *
	 * @param array $data Route data containing 'slug'.
	 * @return array Pre-fetched view data, or empty array with 'not_found' flag.
	 */
	public static function resolve( array $data ): array {
		$slug   = $data['slug'] ?? '';
		$course = Course::find_by_slug( $slug );

		if ( ! $course ) {
			return array( 'not_found' => true );
		}

		$user_id   = get_current_user_id();
		$course_id = (int) $course->id;

		// A non-published course is a 404 for the PUBLIC — the route resolved
		// any status, so a draft course rendered its full page (curriculum +
		// Enroll CTA) to everyone (Basecamp 10132217693). Two groups keep it:
		// editors (author / co-instructor / admin, for preview — mirroring the
		// lesson and quiz gates) and ENROLLED students — unpublishing must
		// never strand a paying customer mid-course with a dead link while
		// their My Courses lists (account page, blocks, shortcode) still show
		// it. New enrollments stay blocked in Enrollment_Service; a trashed
		// course is gone for everyone but editors.
		if ( 'published' !== (string) ( $course->status ?? 'draft' ) ) {
			$lrn_can_preview  = $user_id && (
				user_can( $user_id, 'manage_options' )
				|| (int) ( $course->author_id ?? 0 ) === $user_id
				|| in_array(
					$user_id,
					array_map( 'intval', wp_list_pluck( \Learnomy\Models\CourseInstructor::get_for_course( $course_id ), 'user_id' ) ),
					true
				)
			);
			$lrn_keeps_access = ! $lrn_can_preview
				&& 'trashed' !== (string) ( $course->status ?? '' )
				&& $user_id
				&& null !== Enrollment::find_for_user_course( $user_id, $course_id );
			if ( ! $lrn_can_preview && ! $lrn_keeps_access ) {
				return array( 'not_found' => true );
			}
		}
		$is_enrolled = $user_id ? Enrollment::is_enrolled( $user_id, $course_id ) : false;
		$enrollment  = $is_enrolled ? Enrollment::find_for_user_course( $user_id, $course_id ) : null;

		// Access decision routes through the canonical gate (Permission_Engine ->
		// learnomy_can_access_course) so membership + Spaces implicit access unlock
		// the course PAGE the same way they already unlock its lessons, not just a
		// raw enrollment-row check. $is_enrolled stays the literal row (drives the
		// enrolled-only progress UI); $has_access drives player-vs-paywall + the
		// private-visibility gate. A free, non-enrolled user is NOT has_access, so
		// the enroll CTA still shows.
		$has_access = $user_id
			? ( $is_enrolled || \Learnomy\Permissions\Permission_Engine::can( $user_id, 'access_course', $course_id ) )
			: false;

		$course_settings = Course::get_all_settings( $course_id );

		// Owner = the course author, a co-instructor (junction), or an admin.
		// Used both for the private-course gate below AND to drive the
		// single-course sidebar: an owner must NOT be shown the "Buy Now" /
		// price / coupon block for their own course (they get a manage view).
		// WHY the viewer gets the manage view, not just WHETHER — a site admin is
		// treated as owner so they don't see a Buy Now flow for content they can
		// edit, but they are not "the instructor". The template needs the reason
		// to say the right thing (Basecamp 10101289218). Check authorship/
		// co-instructor first, so an admin who also authored still reads as the
		// instructor; admin-only falls through to 'site_admin'.
		$owner_reason = '';
		if ( $user_id ) {
			$is_author       = (int) ( $course->author_id ?? 0 ) === $user_id;
			$is_coinstructor = in_array(
				$user_id,
				array_map( 'intval', wp_list_pluck( \Learnomy\Models\CourseInstructor::get_for_course( $course_id ), 'user_id' ) ),
				true
			);
			if ( $is_author || $is_coinstructor ) {
				$owner_reason = 'instructor';
			} elseif ( current_user_can( 'manage_options' ) ) {
				$owner_reason = 'site_admin';
			}
		}
		$lrn_is_owner   = '' !== $owner_reason;
		$owner_edit_url = $lrn_is_owner
			? (string) apply_filters(
				'learnomy_instructor_course_edit_url',
				admin_url( 'admin.php?page=learnomy-edit-course&course_id=' . $course_id ),
				$course_id
			)
			: '';
		// Only PRIVATE courses are gated to enrolled students / owners. Unlisted
		// courses are hidden from the catalog but openable by direct link so the
		// owner can share them and let people self-enrol.
		if ( 'private' === Course::visibility( $course_settings ) && ! $has_access && ! $lrn_is_owner ) {
			return array( 'not_found' => true );
		}

		// Instructor. `?: null` is load-bearing: get_userdata() returns FALSE,
		// not null, for an id that no longer resolves, and instructor_profile_url()
		// takes ?object — bool is not coercible to object, so passing the false
		// through was an uncaught TypeError and the whole page 500'd.
		//
		// An author row outlives the WP user: delete the account (staff leaving,
		// a GDPR erasure, a spam purge) and every course they wrote keeps their
		// old author_id. The course itself is undamaged and still has students,
		// so this took out live published courses, for anonymous visitors, with
		// no admin action on the course at all (Basecamp 10159762894).
		//
		// The co-instructor loop below already guards this correctly — the
		// primary-author path was simply never given the same treatment.
		$instructor     = $course->author_id ? ( get_userdata( (int) $course->author_id ) ?: null ) : null;
		$instructor_url = \Learnomy\instructor_profile_url( $instructor );

		// Co-instructors (junction rows other than the primary author) — credited
		// on the course alongside the lead, which is what a site owner expects
		// after adding a co-instructor. Each as { id, name, url, avatar }.
		$co_instructors    = array();
		$primary_author_id = (int) ( $course->author_id ?? 0 );
		foreach ( \Learnomy\Models\CourseInstructor::get_for_course( $course_id ) as $lrn_ci_row ) {
			$lrn_ci_id = (int) $lrn_ci_row->user_id;
			if ( $lrn_ci_id <= 0 || $lrn_ci_id === $primary_author_id ) {
				continue;
			}
			$lrn_ci_user = get_userdata( $lrn_ci_id );
			if ( ! $lrn_ci_user ) {
				continue;
			}
			$co_instructors[] = array(
				'id'     => $lrn_ci_id,
				'name'   => $lrn_ci_user->display_name,
				'url'    => \Learnomy\route_url( 'instructor-profile', array( 'slug' => (string) $lrn_ci_user->user_login ) ),
				'avatar' => get_avatar( $lrn_ci_id, 24, '', '', array( 'class' => 'lrn-hero__meta-avatar' ) ),
			);
		}

		// Course content counts. Draft (unpublished) lessons are visible only to
		// the course owner / co-instructor / site admin for preview — the model
		// defaults to status='published' (Basecamp 10124162733; the guard moved
		// from this call site into Lesson::get_for_course itself), so only an
		// owner opts back into every status here.
		$sections    = Section::get_for_course( $course_id );
		$all_lessons = Lesson::get_for_course( $course_id, 0, 0, $lrn_is_owner ? null : 'published' );
		// Quizzes follow the same owner-only-sees-drafts rule as lessons above.
		// They previously did not, so the course page's content counts included
		// draft quizzes for students (Basecamp 10135041249).
		$quizzes = Quiz::get_for_course( $course_id, $lrn_is_owner ? null : 'published' );
		// First page of approved reviews. The rest load on demand via the
		// reviews REST endpoint + the load-more button (the page used to render
		// only this first page with no way to reach the others — Basecamp
		// #9981647943). Keep the page-load fetch small for speed.
		$reviews_per_page = (int) apply_filters( 'learnomy_course_reviews_per_page', 10, $course_id );
		$reviews_per_page = max( 1, min( 50, $reviews_per_page ) );
		// Exclude reviews authored by anyone the viewer has blocked, on the
		// first-paint fetch too — the load-more REST endpoint already filters, so
		// without this the blocked author reappears on page load then vanishes on
		// "load more" (App Store 1.2 UGC gate consistency).
		$blocked_reviewers = \Learnomy\Models\Blocked_User::blocked_ids_for( get_current_user_id() );
		$reviews           = Review::get_for_course( $course_id, 1, $reviews_per_page, $blocked_reviewers );

		// Decorate every lesson row with drip-schedule lock state so the
		// course outline / curriculum partial can render the lock chip
		// without making per-lesson Lesson_Service calls inside the template.
		foreach ( $all_lessons as $al ) {
			if ( ! is_object( $al ) || empty( $al->id ) ) {
				continue;
			}
			$al_locked = ! Lesson_Service::is_available_to( (int) $al->id, (int) $user_id );
			$al_when   = $al_locked ? (string) ( $al->available_date ?? '' ) : '';
			$al_iso    = '';
			if ( $al_when ) {
				try {
					$utc    = new \DateTimeImmutable( $al_when, new \DateTimeZone( 'UTC' ) );
					$al_iso = $utc->format( 'c' );
				} catch ( \Exception $e ) {
					$al_iso = '';
				}
			}
			$al->drip_locked    = $al_locked;
			$al->unlocks_at     = $al_when;
			$al->unlocks_at_iso = $al_iso;
		}

		$section_count = count( $sections );
		$lesson_count  = count( $all_lessons );
		$quiz_count    = count( $quizzes );

		// Category for breadcrumb.
		$category = $course->category_id ? Category::find( (int) $course->category_id ) : null;

		// Duration formatting.
		$duration_secs  = (int) ( $course->duration_seconds ?? 0 );
		$duration_label = self::format_duration( $duration_secs );

		// Sale price logic — resolved through the shared Course::is_on_sale()
		// helper so the single-course page, the catalog, and the dashboard cards
		// all agree on what "on sale" means (Basecamp #9981514248).
		// Course::headline_price() rather than the `price` column: a recurring
		// course keeps its charge in `recurring_amount` and leaves `price` at 0,
		// so reading the column made a $19/month course render as "Free" with an
		// "Enroll Now" button while checkout charged monthly (Basecamp
		// 10184318257). The $price_suffix below already existed to render the
		// "/mo" — it just never got the chance, because the zero price took the
		// Free branch first.
		$original_price = \Learnomy\Models\Course::headline_price( $course );
		$sale_price     = (float) ( $course->sale_price ?? 0 );
		$has_sale       = \Learnomy\Models\Course::is_on_sale( $course );

		// Recurring (per-course subscription) price suffix shown to buyers so a
		// monthly course reads "$29.00/mo", not an indistinguishable one-time "$29.00".
		$is_recurring = \Learnomy\Models\Course::is_recurring( $course );
		$price_suffix = '';
		if ( $is_recurring ) {
			$price_suffix = 'year' === \Learnomy\Models\Course::recurring_interval( $course )
				? __( '/yr', 'learnomy' )
				: __( '/mo', 'learnomy' );
		}

		// Intro / preview video. wp_oembed_get() (the previous approach)
		// returns an empty string in the front-end HTTP context for most
		// hosts, so the preview never rendered. Instead we detect the
		// provider + embed id the same way the lesson player does and build
		// a self-contained iframe (or <video> for self-hosted files) in the
		// template - no oEmbed round-trip, no JS dependency.
		$preview_video = self::resolve_preview_video( (string) ( $course->video_url ?? '' ) );

		// Thumbnail.
		$thumbnail_url = '';
		if ( ! empty( $course->thumbnail_id ) ) {
			$thumbnail_url = wp_get_attachment_image_url( (int) $course->thumbnail_id, 'full' );
		}
		if ( ! $thumbnail_url && ! empty( $course->thumbnail_url ) ) {
			$thumbnail_url = $course->thumbnail_url;
		}

		// Enroll / continue URLs.
		$enroll_url = add_query_arg(
			array(
				'lrn_action' => 'enroll',
				'course_id'  => $course->id,
				'_wpnonce'   => wp_create_nonce( 'lrn_enroll_' . $course->id ),
			),
			home_url()
		);

		// Membership-claim affordance. When a course is reachable via a
		// membership plan (members_only or paid_or_membership) AND the viewer
		// has an active subscription that covers it, the single-course CTA
		// offers an "Activate via membership" button that enrolls without
		// payment. A non-member on a paid_or_membership course still sees
		// "Buy Now"; the router's resolve_enroll_source() mirrors this exact
		// entitlement decision so the CTA and the action path never disagree.
		$member_can_claim = false;
		if ( $user_id && ! $is_enrolled && Course::has_member_path( $course ) ) {
			$subscriptions    = new \Learnomy\Services\Subscription_Service();
			$member_can_claim = $subscriptions->has_access( $user_id, $course_id );
		}

		// Difficulty.
		$difficulty        = $course->difficulty ?? '';
		$difficulty_labels = array(
			'beginner'     => __( 'Beginner', 'learnomy' ),
			'intermediate' => __( 'Intermediate', 'learnomy' ),
			'advanced'     => __( 'Advanced', 'learnomy' ),
			'expert'       => __( 'Expert', 'learnomy' ),
		);
		$difficulty_label  = isset( $difficulty_labels[ $difficulty ] ) ? $difficulty_labels[ $difficulty ] : '';

		// Instructor stats. Both come from DISTINCT-aware, co-taught-inclusive
		// helpers: summing the denormalized per-course student_count double-counts
		// a student enrolled in two of the instructor's courses (and via two
		// sources), loads every course row into PHP just to count, and misses
		// co-taught courses (author_id only). See Course::sum_students_for_instructor().
		$instructor_course_count  = 0;
		$instructor_student_count = 0;
		if ( $instructor ) {
			// Public surface — the visibility-gated count, matching the profile
			// page this figure links to.
			$instructor_course_count  = Course::count_published_for_instructor( (int) $instructor->ID );
			$instructor_student_count = Course::sum_students_for_instructor( (int) $instructor->ID );
		}

		// Rating distribution + authoritative totals for the reviews section.
		// Both the histogram and the displayed count/avg come from the SAME
		// approved-review source (one GROUP BY + one aggregate query) so the
		// listing and the statistics can't drift apart (Basecamp #9981647943) —
		// and the histogram no longer pulls every review row into PHP to tally.
		// The card list pages through the same approved set via the REST endpoint.
		$rating_distribution = Review::get_rating_distribution( $course_id );
		$review_aggregate    = Review::get_aggregate( $course_id );
		$total_reviews       = (int) $review_aggregate->review_count;
		$reviews_avg_rating  = round( (float) $review_aggregate->avg_rating, 1 );

		// Refund days from settings.
		$refund_days = (int) ( get_option( 'learnomy_settings', array() )['refund_days'] ?? 30 );

		// Latest course-scoped announcement for the current student. Cheaper
		// here (one query, scoped) than the template's lazy fetch which
		// duplicated regardless of whether the announcements partial renders.
		$latest_announcement = null;
		if ( $user_id ) {
			$ann_rows            = Notification::where(
				array(
					'user_id'   => (int) $user_id,
					'type'      => 'announcement',
					'object_id' => $course_id,
				),
				'created_at',
				'DESC',
				1
			);
			$latest_announcement = ! empty( $ann_rows ) ? $ann_rows[0] : null;
		}

		// Per-course progress rows for the enrolled student — used by the
		// course-completion fallback and the curriculum lock state.
		$progress_rows = array();
		if ( $is_enrolled && $user_id ) {
			$progress_rows = Progress::get_all_for_course( (int) $user_id, $course_id );
		}

		// Prereq courses — JSON column on `lrn_courses` stores an array of
		// course IDs. Pre-resolve each so the template doesn't loop with
		// Course::find().
		$prereq_courses = array();
		if ( ! empty( $course->prerequisites ) ) {
			$prereq_ids = json_decode( (string) $course->prerequisites, true );
			if ( is_array( $prereq_ids ) ) {
				foreach ( $prereq_ids as $pid ) {
					$pid = (int) $pid;
					if ( $pid <= 0 ) {
						continue;
					}
					$pc = Course::find( $pid );
					if ( $pc ) {
						$prereq_courses[ $pid ] = $pc;
					}
				}
			}
		}

		// Settings + completion state — computed here so the array literal
		// below can reference both, AND so the certificate lookup can be
		// gated by both flags (only fetched when actually complete + cert-enabled).
		$is_course_complete = self::compute_is_course_complete( $enrollment, $progress_rows, $lesson_count, (int) $user_id, $course_id );

		// "Continue Learning" target — the next uncompleted lesson (full slug, so
		// it matches the `^courses/([^/]+)/lesson/([^/]+)` rewrite and never 404s).
		// Computed after $is_course_complete so a finished course routes to My
		// Courses instead of silently restarting at lesson 1.
		$continue_url = self::resolve_continue_url( $course, $user_id, $is_course_complete );

		// J-01.20 — review form data. The course template renders the form when
		// can_user_review is true and the buyer has no existing row; the
		// existing row (read-only) replaces the form once it exists.
		// Uses Course::reviews_enabled_for() so both the global Learnomy
		// Settings toggle AND the per-course flag govern the form.
		$user_review     = $user_id ? Review::get_user_review( (int) $user_id, $course_id ) : null;
		$can_user_review = ( $user_id > 0 )
			&& Course::reviews_enabled_for( $course_id )
			&& null !== $enrollment;

		// Reply affordance — the course author (or an admin) can post a public
		// instructor response to each review. The review-card partial reads
		// this off $GLOBALS so it doesn't need a per-row argument.
		$can_reply_reviews = ( $user_id > 0 )
			&& (
				current_user_can( 'manage_options' )
				|| ( (int) ( $course->author_id ?? 0 ) === $user_id )
			);

		// Wishlist state for the heart control on the enrollment CTA. Resolved
		// here (not in the template) so single-course.php stays logic-light and
		// uses the SAME usermeta key + Interactivity store as the catalog card.
		// Logged-out viewers never get a toggle — the card never renders one for
		// guests, so they can't silently write wishlist meta.
		$is_wishlisted = false;
		if ( $user_id > 0 ) {
			$is_wishlisted = in_array( $course_id, \Learnomy\wishlist_ids( $user_id ), true );
		}

		return array(
			'not_found'                => false,
			'course'                   => $course,
			'user_id'                  => $user_id,
			'is_enrolled'              => $is_enrolled,
			'has_access'               => $has_access,
			'is_owner'                 => $lrn_is_owner,
			'owner_reason'             => $owner_reason,
			'owner_edit_url'           => $owner_edit_url,
			'enrollment'               => $enrollment,
			'instructor'               => $instructor,
			'instructor_url'           => $instructor_url,
			'co_instructors'           => $co_instructors,
			'instructor_course_count'  => $instructor_course_count,
			'instructor_student_count' => $instructor_student_count,
			'sections'                 => $sections,
			'all_lessons'              => $all_lessons,
			'quizzes'                  => $quizzes,
			'reviews'                  => $reviews,
			'reviews_per_page'         => $reviews_per_page,
			'user_review'              => $user_review,
			'can_user_review'          => $can_user_review,
			'can_reply_reviews'        => $can_reply_reviews,
			'is_wishlisted'            => $is_wishlisted,
			'section_count'            => $section_count,
			'lesson_count'             => $lesson_count,
			'quiz_count'               => $quiz_count,
			'category'                 => $category,
			'duration_secs'            => $duration_secs,
			'duration_label'           => $duration_label,
			'has_sale'                 => $has_sale,
			'original_price'           => $original_price,
			'sale_price'               => $sale_price,
			'is_recurring'             => $is_recurring,
			'price_suffix'             => $price_suffix,
			'thumbnail_url'            => $thumbnail_url,
			'preview_video'            => $preview_video,
			'enroll_url'               => $enroll_url,
			'member_can_claim'         => $member_can_claim,
			'continue_url'             => $continue_url,
			'difficulty'               => $difficulty,
			'difficulty_label'         => $difficulty_label,
			'rating_distribution'      => $rating_distribution,
			'total_reviews'            => $total_reviews,
			'reviews_avg_rating'       => $reviews_avg_rating,
			'refund_days'              => $refund_days,
			'latest_announcement'      => $latest_announcement,
			'progress_rows'            => $progress_rows,
			'prereq_courses'           => $prereq_courses,

			// Drives 7 downstream consumers in single-course.php (welcome banner,
			// announcements/comments/Q&A/reviews toggles, sales-view outcomes &
			// target-audience) AND partials/course-completion.php. Template must
			// consume this variable per the "templates have zero logic" rule.
			'course_settings'          => $course_settings,

			// Completion + active certificate, computed once for the enrolled
			// branch + the course-completion partial. Conditionally pre-fetches
			// the certificate ONLY when the student has actually completed AND
			// the course has certificates enabled — keeps the catalog/sales
			// view DB-cheap.
			'is_course_complete'       => $is_course_complete,
			'active_certificate'       => self::resolve_active_certificate(
				$is_course_complete,
				\Learnomy\Models\Course::certificates_enabled( (int) $course->id ),
				(int) $user_id,
				(int) $course->id
			),
		);
	}

	/**
	 * Source of truth: enrollment.status == 'completed', else fall back to
	 * asking the completion rule directly (covers seeds and legacy rows that
	 * never got flipped).
	 *
	 * The fallback used to be its own rule — "every lesson has a completed
	 * progress row" — which is not what completing a course means. On a
	 * `lessons_and_quiz` course it reported complete with the final exam
	 * unattempted, and because resolve_continue_url() short-circuits a complete
	 * course to My Courses, the Continue button stopped pointing at the pending
	 * quiz that was the student's actual next step (Basecamp 10134140444). It
	 * also ignored `completion_type` entirely, so a `percentage` course read
	 * incomplete at its own threshold and a `manual` course read complete
	 * without the admin ever marking it.
	 *
	 * The lesson tally survives as a CHEAP PRE-FILTER only: no completion type
	 * can be satisfied while lessons are still outstanding except `percentage`,
	 * whose enrollment row the completion path has already flipped by then. It
	 * keeps the extra queries off the common in-progress render and out of the
	 * catalog/sales view; the authoritative check runs only for a student who
	 * has actually finished the curriculum.
	 */
	private static function compute_is_course_complete( $enrollment, array $progress_rows, int $lesson_count, int $user_id, int $course_id ): bool {
		// Read the row through the canonical reader — the same one course-card,
		// the My Courses rail and the progress widget use. This branch tested
		// `status === 'completed'` by hand and so disagreed with those surfaces
		// on a row that carries completed_at without the status flip.
		if ( $enrollment && Enrollment::is_completed( $enrollment ) ) {
			return true;
		}
		if ( $user_id <= 0 || $lesson_count <= 0 || empty( $progress_rows ) ) {
			return false;
		}
		$completed_count = 0;
		foreach ( $progress_rows as $pr ) {
			if ( 'completed' === (string) ( $pr->status ?? '' ) ) {
				++$completed_count;
			}
		}
		if ( $completed_count < $lesson_count ) {
			return false;
		}

		return ( new Progress_Service() )->is_course_complete( $user_id, $course_id );
	}

	/**
	 * Returns the most-recent active certificate for this user+course, or null.
	 * The conditional gate (only when complete + cert-enabled) keeps the DB
	 * round-trip off the catalog/sales-view code path.
	 *
	 * Self-heal: if the course is complete, certificates are enabled, and no
	 * active certificate exists yet, issue one inline. This covers two real
	 * scenarios that left students stranded on the "Get Certificate" CTA:
	 *   1. Courses completed before the `learnomy_course_completed` auto-issue
	 *      hook was wired in (legacy data).
	 *   2. Edge cases where the completion action did not fire (manual
	 *      enrollment status flips, third-party plugins replacing the
	 *      completion flow).
	 * The Certificate_Service handles per-course template selection internally.
	 */
	private static function resolve_active_certificate( bool $is_complete, bool $cert_enabled, int $user_id, int $course_id ) {
		if ( ! $is_complete || ! $user_id ) {
			return null;
		}

		/*
		 * LOOK UP unconditionally; only ISSUE behind the flag.
		 *
		 * The flag used to gate both, so a student who HELD a certificate saw
		 * nothing on the screen that had just congratulated her — her only route
		 * to the thing she earned was guessing that the account nav had it
		 * (Basecamp 10171331421).
		 *
		 * Possession is not a setting. An owner switching certificates off means
		 * "stop awarding these", not "hide the ones people already earned".
		 * Withdrawing an issued certificate is what `status` / `revoked_at` are
		 * for, and revoked rows are already excluded by the status filter below.
		 */
		$rows = Certificate::where(
			array(
				'user_id'   => $user_id,
				'course_id' => $course_id,
				'status'    => 'active',
			),
			'issued_at',
			'DESC',
			1
		);
		if ( ! empty( $rows ) ) {
			return $rows[0];
		}

		// Nothing held yet, so from here on the owner's setting decides.
		if ( ! $cert_enabled ) {
			return null;
		}

		/*
		 * A REVOKED certificate stays revoked.
		 *
		 * The self-heal below treats "complete, enabled, no active row" as a
		 * reason to issue. Revocation produces exactly that state, so an owner
		 * who withdrew a credential had it minted again the next time the
		 * LEARNER opened the course page — a new row, a new uuid, verifying as
		 * valid, with the revoked row left behind as litter. Three revokes left
		 * three orphans (Basecamp 10171385437).
		 *
		 * Revocation is the documented way to withdraw a credential; `status`
		 * and `revoked_at` exist for it. A view resolver must never undo an
		 * administrative decision. Re-issuing after a revocation has to be a
		 * deliberate act by someone with the authority to make it, not a side
		 * effect of the person who lost it reloading a page.
		 *
		 * Scoped to this course only, so a revocation here never blocks a
		 * legitimate first issue elsewhere.
		 */
		$revoked = Certificate::where(
			array(
				'user_id'   => $user_id,
				'course_id' => $course_id,
				'status'    => 'revoked',
			),
			'revoked_at',
			'DESC',
			1
		);
		if ( ! empty( $revoked ) ) {
			return null;
		}

		// No active cert — issue one now. The service writes the row, fires
		// the issued action, and returns the new ID. Failures are logged but
		// non-fatal: returning null keeps the "Get Certificate" CTA visible
		// rather than crashing the page; the next render will re-attempt.
		$result = ( new \Learnomy\Services\Certificate_Service() )->issue( $user_id, $course_id );
		if ( is_wp_error( $result ) || ! $result ) {
			return null;
		}

		$rows = Certificate::where(
			array(
				'user_id'   => $user_id,
				'course_id' => $course_id,
				'status'    => 'active',
			),
			'issued_at',
			'DESC',
			1
		);
		return ! empty( $rows ) ? $rows[0] : null;
	}

	/**
	 * Resolve a course intro/preview video URL into renderable embed data.
	 *
	 * Mirrors the lesson player's provider detection so the course sales view
	 * can render a YouTube/Vimeo iframe (or a self-hosted <video>) without
	 * depending on wp_oembed_get(), which returns empty in the front-end
	 * request context for most hosts.
	 *
	 * @param string $video_url The course `video_url` column value.
	 * @return array{provider:string,embed_id:string,embed_url:string,src:string} Empty provider when no video.
	 */
	private static function resolve_preview_video( string $video_url ): array {
		return \Learnomy\resolve_video_url( $video_url );
	}

	/**
	 * Format duration seconds into a human-readable label.
	 *
	 * @param int $seconds Total seconds.
	 * @return string Formatted duration or empty string.
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
	 * Build the "Continue Learning" URL for a viewer.
	 *
	 * For an enrolled student the target is resolved by the shared Resume
	 * service (the lesson they were last on, else the first curriculum gap,
	 * else the pending quiz) — the same resolver the My Courses rail, the
	 * progress widget, and the dashboard hero use, so every "Continue" surface
	 * deep-links to one place. Anonymous/preview viewers get the first lesson.
	 *
	 * When the course is already COMPLETE (enrollment.status === 'completed'),
	 * the lesson search below would find no uncompleted lesson and fall through
	 * to lesson 1 — silently restarting a finished course. So a completed course
	 * short-circuits to the student's My Courses list (the natural "what next"),
	 * BEFORE the lesson search. This does not touch the lessons_and_quiz branch:
	 * a course whose lessons are done but whose quiz is not yet passed is NOT
	 * complete, so it flows past this guard and still routes to the pending quiz.
	 *
	 * @param object $course             Course row.
	 * @param int    $user_id            Viewer (0 when anonymous — pre-login state).
	 * @param bool   $is_course_complete Authoritative completion flag from resolve().
	 */
	private static function resolve_continue_url( object $course, int $user_id, bool $is_course_complete = false ): string {
		if ( $is_course_complete && $user_id > 0 ) {
			/**
			 * Filter the "Continue Learning" destination for a course the viewer
			 * has already completed. Default: their My Courses list. A site can
			 * point this at the certificate, a follow-on course, or back into the
			 * course (route_url 'course') to restore the old replay behaviour.
			 *
			 * @param string $url     Default completion destination (My Courses).
			 * @param object $course  Course row.
			 * @param int    $user_id Viewer id.
			 */
			return (string) apply_filters(
				'learnomy_course_complete_continue_url',
				\Learnomy\route_url( 'account-courses' ),
				$course,
				$user_id
			);
		}

		// Not complete: resume where the student left off. Delegated to the
		// shared Resume resolver so this CTA, the My Courses rail, the progress
		// widget, and the dashboard hero all deep-link to the SAME lesson.
		return Resume::url( $user_id, $course );
	}
}
