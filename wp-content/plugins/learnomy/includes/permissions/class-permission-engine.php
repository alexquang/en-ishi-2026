<?php
/**
 * Canonical permission entry point — all access checks flow through here.
 *
 * @package Learnomy
 */

namespace Learnomy\Permissions;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Enrollment;
use Learnomy\Models\Subscription;
use Learnomy\Models\MembershipPlanCourse;
use Learnomy\Models\CourseInstructor;
use Learnomy\Models\Course;
use Learnomy\Models\Quiz;
use Learnomy\Services\Lesson_Service;

class Permission_Engine {

	// Maps context-specific actions to the WP capability that governs them.
	// Without this, Permission_Engine would look for caps like learnomy_access_course
	// which don't exist — the registered caps use broader names.
	private const CAP_MAP = [
		'access_course'        => 'learnomy_learn',
		'access_lesson'        => 'learnomy_learn',
		// Learner baseline for self-service actions (enroll / complete-lesson /
		// subscribe on one's OWN record). No context — the cap + the
		// learnomy_permission_check filter are the gate, so these decisions flow
		// through Permission_Engine like every other. [card 10062744921]
		'learn'                => 'learnomy_learn',
		// `create_course`: the site-policy gate on AUTHORING A NEW COURSE, asked
		// without a course because none exists yet. It is a separate decision
		// from `manage_course` (may I edit THIS one), and the product has
		// always documented and shipped it as such — the wp-admin "Add New
		// Course" screen is gated on `learnomy_create_course`, the instructor
		// role grants it, and the docs name it. Only the REST route disagreed,
		// gating on `manage_own_courses`, so revoking the documented cap left
		// POST /courses wide open (Basecamp 10133286758).
		'create_course'        => 'learnomy_create_course',
		'manage_course'        => 'learnomy_manage_own_courses',
		'publish_course'       => 'learnomy_manage_own_courses',
		// `self_publish_course`: the SITE-POLICY half of publishing, asked
		// without a course. "May this instructor take their own work live
		// without an admin approving it?" It is the only half that can be
		// answered while the course does not exist yet — which is exactly the
		// create-a-course-and-publish-it-in-one-go case. See the case below.
		'self_publish_course'  => 'learnomy_manage_own_courses',
		'grade_quiz'           => 'learnomy_grade',
		// `apply-instructor`: any logged-in user with the WP baseline cap may
		// submit an instructor application. Mapping to `read` documents this
		// intent and lets Permission_Engine own the gate instead of templates
		// calling current_user_can() directly (Basecamp #9936826095).
		'apply-instructor'     => 'read',
		// `view_instructor_area`: may this person see the instructor console at
		// all — the rail, the dashboard, reports, students, grading, earnings.
		// Asked without a course, because the console exists before any course
		// does. Added for Basecamp 10190724992, where the routes gated on "is
		// anyone logged in?" and a student was shown the whole instructor UI.
		//
		// Deliberately NOT `create_course`, the other ability that happens to
		// separate the two roles today: an owner who revokes authoring rights
		// from an instructor should not thereby lock them out of the earnings
		// page for courses they already own. `learnomy_manage_own_courses` is
		// the cap that means "this person owns teaching content here", which is
		// the question the console actually turns on.
		'view_instructor_area' => 'learnomy_manage_own_courses',
	];

	public static function can( int $user_id, string $action, ?int $context_id = null ): bool {
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		$wp_cap = self::CAP_MAP[ $action ] ?? ( 'learnomy_' . $action );
		if ( ! user_can( $user_id, $wp_cap ) ) {
			return apply_filters( 'learnomy_permission_check', false, $user_id, $action, $context_id );
		}

		$result = self::check_context( $user_id, $action, $context_id );

		return (bool) apply_filters( 'learnomy_permission_check', $result, $user_id, $action, $context_id );
	}

	private static function check_context( int $user_id, string $action, ?int $context_id ): bool {
		switch ( $action ) {
			case 'access_course':
				if ( ! $context_id ) {
					return false;
				}
				// Enrollment, an active membership covering this course, OR
				// AUTHORSHIP grants access.
				//
				// The author was missing, so an instructor was refused on their
				// own course: replying to a student question on their own lesson
				// returned 403 while any enrolled student succeeded, and the
				// documented workaround was for the instructor to self-enrol in
				// their own course (Basecamp 10163002943). `access_lesson` right
				// below already documents a course-author bypass, so the two
				// neighbouring decisions disagreed about the same person.
				$result = self::is_course_owner( $user_id, $context_id )
					|| self::has_enrollment( $user_id, $context_id )
					|| self::has_membership_access( $user_id, $context_id );
				return (bool) apply_filters( 'learnomy_can_access_course', $result, $user_id, $context_id );

			case 'access_lesson':
				if ( ! $context_id ) {
					return false;
				}
				// Drip schedule gate. Lesson_Service::is_available_to() handles
				// admin / course-author bypass, NULL available_date (immediate
				// availability), UTC clock comparison, AND applies the
				// learnomy_can_access_lesson filter itself — so Pro content-drip
				// is enforced for every caller of that method, not just this one.
				// Thin delegate; do not re-apply the filter (would double-run).
				return Lesson_Service::is_available_to( $context_id, $user_id );

			case 'manage_course':
				if ( ! $context_id ) {
					return false;
				}
				// Co-author model: only the MAIN AUTHOR manages a course
				// (settings, pricing, content, publish). Co-instructors get
				// credit + read-only student reporting, never management.
				return self::is_course_owner( $user_id, $context_id );

			case 'publish_course':
				if ( ! $context_id ) {
					return false;
				}
				// Two-stage gate: the user must own the course AND the site
				// must allow them to self-publish. The admin bypass in ::can()
				// already covered manage_options, so reaching this branch
				// implies a non-admin instructor — apply the site policy.
				return self::is_course_owner( $user_id, $context_id )
					&& self::self_publish_allowed( $user_id );

			case 'self_publish_course':
				// Deliberately context-free. Ownership is the OTHER half of
				// `publish_course`, and there are two moments when it cannot be
				// looked up but is nonetheless certain: creating a course (the
				// requester becomes its author) and asking on an author's
				// behalf (Course_Service::requires_approval). Answering "no"
				// because the row does not exist yet is a false negative, and
				// it is what sent an entitled instructor's brand-new course
				// into the approval queue on the create-and-publish path while
				// the identical publish from the editor went live (Basecamp
				// 10132217506).
				return self::self_publish_allowed( $user_id );

			case 'grade_quiz':
				if ( ! $context_id ) {
					return false;
				}
				return self::is_instructor_of_quiz_course( $user_id, $context_id );

			default:
				// For actions without context-specific logic the capability check is sufficient.
				return apply_filters( 'learnomy_permission_context_check', true, $user_id, $action, $context_id );
		}
	}

	/**
	 * Site policy: may this instructor take their own course live without an
	 * admin approving it?
	 *
	 * THE one reader of that policy. It has two halves, and they used to be
	 * implemented twice with different answers: this engine read only the
	 * site-wide setting, while Course_Service::requires_approval() read the
	 * setting AND the per-instructor override. The REST controller writes
	 * `status` straight through the engine's answer, so an instructor flagged
	 * for review could publish through the API while the same publish through
	 * the service was held. One policy, one reader — both callers now ask here.
	 *
	 * @param int $user_id The prospective (or actual) course author.
	 */
	private static function self_publish_allowed( int $user_id ): bool {
		$settings = get_option( 'learnomy_settings', array() );
		// Default-on for back-compat with sites that never saved the setting;
		// the admin Instructors tab now writes 0 explicitly when the toggle is
		// unchecked (hidden field + checkbox pair).
		$site_allows = ! is_array( $settings )
			|| ! array_key_exists( 'instructor_self_publish', $settings )
			|| ! empty( $settings['instructor_self_publish'] );

		if ( ! $site_allows ) {
			return false;
		}

		// Per-instructor override — a site can require review for one person
		// while everyone else self-publishes (admin Instructors detail screen).
		return ! get_user_meta( $user_id, 'learnomy_require_course_approval', true );
	}

	private static function has_enrollment( int $user_id, int $course_id ): bool {
		return Enrollment::is_enrolled( $user_id, $course_id );
	}

	private static function has_membership_access( int $user_id, int $course_id ): bool {
		$active_subscriptions = Subscription::get_active_for_user( $user_id );

		if ( empty( $active_subscriptions ) ) {
			return false;
		}

		foreach ( $active_subscriptions as $subscription ) {
			if ( MembershipPlanCourse::has_course( (int) $subscription->plan_id, $course_id ) ) {
				return true;
			}
		}

		return false;
	}

	private static function is_instructor_of_quiz_course( int $user_id, int $quiz_id ): bool {
		$quiz = Quiz::find( $quiz_id );
		if ( ! $quiz || empty( $quiz->course_id ) ) {
			return false;
		}

		// Grading writes to a student's record, so it is a management action —
		// the main author only. Co-instructors can VIEW grades/reports (their
		// dashboard scopes to co-taught courses) but not assign them (read-only).
		return self::is_course_owner( $user_id, (int) $quiz->course_id );
	}

	/**
	 * Course "owner" — the only role allowed to MANAGE a course (settings,
	 * pricing, content, publish, grading). Under the co-author model that is the
	 * MAIN AUTHOR: the course's author_id, or the designated primary instructor
	 * (is_primary=1). Co-instructors are deliberately excluded — they get credit
	 * + read-only student reporting (the instructor dashboard already scopes to
	 * co-taught courses via Course::get_for_instructor), never management.
	 */
	private static function is_course_owner( int $user_id, int $course_id ): bool {
		$course = Course::find( $course_id );
		if ( ! $course ) {
			return false;
		}

		$assignment = null;
		foreach ( CourseInstructor::get_for_course( $course_id ) as $row ) {
			if ( (int) $row->user_id === $user_id ) {
				$assignment = $row;
				break;
			}
		}

		return self::decide_course_owner( $user_id, $course, $assignment );
	}

	/**
	 * The ownership rule itself, decided from rows the caller already has.
	 *
	 * Split out of {@see is_course_owner()} so the SET form
	 * ({@see gradable_course_ids()}) can answer the SAME question for a whole
	 * catalogue without a query per course. Both callers must keep using this —
	 * a second copy of the rule is how the grading queue and the grading
	 * permission ended up disagreeing (queue listed every co-taught course,
	 * permission allowed only owned ones).
	 *
	 * @param int         $user_id    User asking.
	 * @param object      $course     The course row (needs `id`, `author_id`).
	 * @param object|null $assignment This user's lrn_course_instructors row for
	 *                                that course, or null when not assigned.
	 */
	private static function decide_course_owner( int $user_id, object $course, ?object $assignment ): bool {
		if ( (int) $course->author_id === $user_id ) {
			return true;
		}

		if ( $assignment && ! empty( $assignment->is_primary ) ) {
			return true;
		}

		// Co-author collaboration is OPT-IN. By default only the main author
		// manages a course; co-instructors get credit + revenue + read-only
		// reports. But many site owners who add a co-instructor expect them to
		// help build the course, so a site can let assigned co-instructors
		// co-manage via the `learnomy_co_instructor_can_manage` filter. The
		// filter is only consulted for users who ARE assigned to the course, so
		// enabling it can never grant management to an unrelated user; the grant
		// is course-scoped and covers the full management surface, so it's a
		// deliberate site-owner choice.
		if ( ! $assignment ) {
			return false;
		}

		/**
		 * Allow an assigned co-instructor to co-manage a course (content,
		 * settings, etc.). Default false — only the primary author manages.
		 *
		 * @param bool $can       Whether this co-instructor may manage. Default false.
		 * @param int  $user_id   The co-instructor.
		 * @param int  $course_id The course.
		 */
		return (bool) apply_filters( 'learnomy_co_instructor_can_manage', false, $user_id, (int) $course->id );
	}

	/**
	 * Course ids whose quiz attempts this user may grade — the SET form of
	 * `can( $user_id, 'grade_quiz', $quiz_id )`.
	 *
	 * A grading QUEUE has to answer "what may I grade?" before it knows any
	 * attempt id, and the only correct answer is the same one the write route
	 * gives per attempt. Listing courses the caller cannot actually grade fills
	 * a teacher's inbox with 403s; omitting courses they can grade hides their
	 * own students' work from them. Both happened: the queue was scoped by
	 * "has an lrn_course_instructors row", which included co-instructors (who
	 * may not grade) and excluded course authors with no assignment row (who
	 * may, and whose essays therefore never appeared for anyone but an admin).
	 *
	 * Two queries regardless of catalogue size: the candidate courses, and this
	 * user's assignment rows.
	 *
	 * @param int $user_id User asking.
	 * @return int[]|null Course ids, or null for "no restriction" (admins).
	 */
	public static function gradable_course_ids( int $user_id ): ?array {
		return self::owned_course_ids( $user_id, 'grade_quiz' );
	}

	/**
	 * Course ids this user may act on for `$ability` — the SET form of
	 * `can( $user_id, $ability, $course_id )`.
	 *
	 * Every teaching surface that lists or filters BY COURSE needs this before
	 * it knows any single id: a grading queue, a drip schedule, a report, a
	 * gradebook, an export. Each one that answers the question for itself
	 * answers it differently, and the differences are invisible until someone
	 * compares two screens:
	 *
	 *   - "has an lrn_course_instructors row" includes co-instructors who may
	 *     not act, and EXCLUDES an author with no assignment row who may.
	 *   - "author_id = me" excludes every co-instructor entirely, so a teacher
	 *     added to a colleague's course sees an empty list and concludes the
	 *     feature is broken.
	 *
	 * Both shipped. `decide_course_owner()` is the one rule; this returns it as
	 * a set so a list and the write route that follows it cannot disagree.
	 *
	 * A `null` return means "no restriction" (administrators) and must be
	 * carried through as null rather than expanded into a list of every course
	 * — an admin on a large site would otherwise page through a 50k-id IN().
	 * An empty array means "this user owns no courses", which is NOT the same
	 * thing and must never be treated as no-restriction.
	 *
	 * Two queries regardless of catalogue size: the candidate courses, and this
	 * user's assignment rows.
	 *
	 * @param int    $user_id User asking.
	 * @param string $ability Ability from CAP_MAP, e.g. 'grade_quiz',
	 *                        'manage_course'.
	 * @return int[]|null Course ids, or null for "no restriction" (admins).
	 */
	/**
	 * Course ids this viewer may READ for `$purpose`.
	 *
	 * Read and write are different questions and conflating them has broken
	 * this product in both directions:
	 *
	 *   - Reading (reports, rosters, exports) is author OR co-instructor. The
	 *     co-author model already promises a co-instructor read-only student
	 *     reporting, so the narrow rule would hide a colleague's own students
	 *     from them.
	 *   - Writing (grade, schedule drip, edit) is decide_course_owner() -- the
	 *     author, or a co-instructor marked is_primary. The wide rule would let
	 *     any co-teacher change a grade or move when a colleague's lessons
	 *     unlock.
	 *
	 * So read is deliberately a SUPERSET of write, and both live here so the
	 * relationship is visible in one file instead of inferred across six.
	 *
	 * For a course LIST only. Never use the return value to decide whether a
	 * LEARNER may be looked at -- that needs both axes, so use
	 * may_view_learner_in_course(). A viewer who both teaches and manages a
	 * company legitimately sees courses from each hat, and flattening the two
	 * into one list authorises the cross product.
	 *
	 * @param int    $viewer_id Viewer asking.
	 * @param string $purpose   'report' | 'roster' (read) or 'grade' (write).
	 * @return int[]|null Course ids, or null for "no restriction" (admins).
	 */
	public static function visible_course_ids( int $viewer_id, string $purpose = 'report' ): ?array {
		if ( 'grade' === $purpose ) {
			return self::owned_course_ids( $viewer_id, 'grade_quiz' );
		}

		if ( user_can( $viewer_id, 'manage_options' ) ) {
			return null;
		}

		// Unbounded on purpose: a truncated scope does not deny a course, it
		// makes the viewer's own course disappear from their own reports.
		return Course::ids_for_instructor( $viewer_id, 0, 0 );
	}

	/**
	 * Every rectangle this viewer may read, one per lens they hold.
	 *
	 * A viewer with two hats gets TWO grants, never one merged rectangle. See
	 * Scope_Grant for why that distinction is the whole design.
	 *
	 * Free contributes the teaching grant: the courses they teach, with the
	 * learner axis left null because a teacher's roster is DEFINED by their
	 * courses -- everyone enrolled in them, nobody else. Pro adds one grant per
	 * space or group a viewer manages, naming that tenant's members explicitly,
	 * because a company's roster is defined by membership rather than by
	 * enrolment.
	 *
	 * Pro extends through the filter below and supplies ids from its own
	 * lrn_pro_* tables. With no listeners this returns just the teaching grant,
	 * which is byte-for-byte the behaviour of a Free-only install.
	 *
	 * @param int    $viewer_id Viewer asking.
	 * @param string $purpose   'report' | 'roster' | 'grade'.
	 * @return Scope_Grant[] Possibly empty. Empty means "sees nothing".
	 */
	public static function visibility_grants( int $viewer_id, string $purpose = 'report' ): array {
		$grants = array();

		$course_ids = self::visible_course_ids( $viewer_id, $purpose );

		// An administrator is one unrestricted rectangle, not a list of every
		// course on the site. Expanding it would put a 50k-id IN() on every
		// report on a large install.
		if ( null === $course_ids || $course_ids ) {
			$grants[] = new Scope_Grant( 'instructor', $course_ids, null );
		}

		/**
		 * Filters the rectangles a viewer may read.
		 *
		 * Add a grant per tenant the viewer manages. Never widen an existing
		 * grant and never merge two -- a merged rectangle authorises the cross
		 * product, which is the cell this whole seam exists to keep empty.
		 *
		 * @param Scope_Grant[] $grants    Grants so far.
		 * @param int           $viewer_id Viewer asking.
		 * @param string        $purpose   'report' | 'roster' | 'grade'.
		 */
		$grants = (array) apply_filters( 'learnomy_visibility_grants', $grants, $viewer_id, $purpose );

		return array_values(
			array_filter( $grants, static fn( $grant ) => $grant instanceof Scope_Grant )
		);
	}

	/**
	 * THE per-cell question: may this viewer see this learner's data in this
	 * course?
	 *
	 * Every report row, every roster line and every exported cell asks this.
	 * An export that skips it is the same leak with a file attached.
	 *
	 * Fails CLOSED when the answer is unknowable: if some grant covered the
	 * course but its learner list was truncated, a miss proves nothing, and a
	 * maybe must not read as a yes.
	 *
	 * @param int    $viewer_id  Viewer asking.
	 * @param int    $learner_id Learner being looked at.
	 * @param int    $course_id  Course the data belongs to.
	 * @param string $purpose    'report' | 'roster' | 'grade'.
	 * @return bool
	 */
	public static function may_view_learner_in_course( int $viewer_id, int $learner_id, int $course_id, string $purpose = 'report' ): bool {
		foreach ( self::visibility_grants( $viewer_id, $purpose ) as $grant ) {
			if ( $grant->covers( $learner_id, $course_id ) ) {
				return true;
			}

			if ( $grant->miss_is_inconclusive( $course_id ) ) {
				return false;
			}
		}

		return false;
	}

	public static function owned_course_ids( int $user_id, string $ability ): ?array {
		if ( user_can( $user_id, 'manage_options' ) ) {
			return null;
		}

		$cap = self::CAP_MAP[ $ability ] ?? ( 'learnomy_' . $ability );
		if ( ! user_can( $user_id, $cap ) ) {
			return array();
		}

		$assignments = array();
		foreach ( CourseInstructor::get_for_user( $user_id ) as $row ) {
			$assignments[ (int) $row->course_id ] = $row;
		}

		// Unbounded on purpose. A row cap here does not deny the 501st course,
		// it makes the owner's own course disappear from their lists, filters
		// and grading queue with no message anywhere (Basecamp 10184655982).
		// The reader projects two columns so "no limit" stays cheap.
		$owned = array();
		foreach ( Course::ownership_rows_for_instructor( $user_id ) as $course ) {
			if ( self::decide_course_owner( $user_id, $course, $assignments[ (int) $course->id ] ?? null ) ) {
				$owned[] = (int) $course->id;
			}
		}

		return array_values( array_unique( $owned ) );
	}

	/**
	 * The instructor scope as a SQL predicate — the SQL form of the same rule
	 * {@see owned_course_ids()} returns as a set.
	 *
	 * Every wp-admin list that shows an instructor "their" content answered this
	 * with `author_id = me`, and that is the exact shape
	 * {@see owned_course_ids()}'s docblock already names as a shipped bug: it
	 * excludes every co-instructor, and it hides content on the instructor's OWN
	 * course as soon as anyone else created it. An admin who adds a final exam
	 * to an instructor's course makes it vanish from that instructor's Quizzes
	 * list while it still renders to their students and still opens from the
	 * course builder — three surfaces, three answers (Basecamp 10184327422).
	 *
	 * The predicate is a UNION, not a replacement: `author_id = me` stays, so a
	 * standalone quiz with no course is still the author's own. Which courses
	 * count is `decide_course_owner()`'s decision, so co-instructor access here
	 * follows the site's `learnomy_co_instructor_can_manage` setting instead of
	 * a second opinion about it.
	 *
	 * Callers pass the result straight into a model's `$scope` argument; nobody
	 * hand-rolls this SQL, or we are back to N answers for one question.
	 *
	 * @param int    $user_id    User asking.
	 * @param string $ability    Ability from CAP_MAP, e.g. 'manage_course'.
	 * @param string $alias      Table alias including the dot, e.g. 'q.'. May be ''.
	 * @param string $author_col Column holding the row's author.
	 * @param string $course_col Column holding the row's course id.
	 * @return array{0:string,1:array<int,int>}|null [ sql, values ], or null for
	 *         "no restriction" (administrators). Null must be carried through as
	 *         null — an empty id list means "manages nothing", which is the
	 *         opposite of unrestricted.
	 */
	public static function instructor_scope_sql(
		int $user_id,
		string $ability,
		string $alias = '',
		string $author_col = 'author_id',
		string $course_col = 'course_id'
	): ?array {
		$course_ids = self::owned_course_ids( $user_id, $ability );
		if ( null === $course_ids ) {
			return null;
		}

		$author = $alias . '`' . esc_sql( $author_col ) . '`';
		$values = array( $user_id );

		if ( empty( $course_ids ) ) {
			return array( "( {$author} = %d )", $values );
		}

		$course       = $alias . '`' . esc_sql( $course_col ) . '`';
		$placeholders = implode( ', ', array_fill( 0, count( $course_ids ), '%d' ) );

		return array(
			"( {$author} = %d OR {$course} IN ( {$placeholders} ) )",
			array_merge( $values, array_map( 'absint', $course_ids ) ),
		);
	}
}
