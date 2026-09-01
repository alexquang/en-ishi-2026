<?php
/**
 * One rectangle of visibility.
 *
 * @package Learnomy
 */

declare(strict_types=1);

namespace Learnomy\Permissions;

defined( 'ABSPATH' ) || exit;

/**
 * One (courses x learners) rectangle a viewer is allowed to read.
 *
 * One install serves many schools, companies and independent teachers at the
 * same time. Each of them sees the intersection of the learners in their group
 * and the courses that group is doing -- never a learner's whole record. A
 * physics teacher sees a student's physics work, not their chemistry grade;
 * a company sees its staff in the courses it bought, not what those people
 * study privately.
 *
 * Two axes, because a flat list of course ids cannot express that intersection.
 * The reason this class exists rather than an `int[]` is the viewer who wears
 * two hats -- teaches their own course AND manages a company. Merge those into
 * one course list and one learner list and you have authorised the cross
 * product, which contains the cell "this company's member, in a course the
 * company never bought". That cell is exactly the leak. So a viewer with two
 * hats gets TWO grants, and a viewer with none gets an empty array.
 *
 * `null` on an axis means unrestricted ON THAT AXIS:
 *
 *   - `course_ids === null`  -- every course (an administrator).
 *   - `learner_ids === null` -- every learner ENROLLED IN those courses. Not
 *     every user on the site. A teacher's grant leaves this null because their
 *     roster is defined by the courses; a company's grant names its members.
 *
 * An EMPTY array is the opposite of null and the two must never be confused:
 * `[]` means "no courses" / "no learners", so the grant authorises nothing.
 * Conflating them is not a style question -- it is how a viewer who teaches
 * nothing ends up seeing everything, which has already shipped once here.
 *
 * Immutable on purpose. A grant is an answer to a permission question; code
 * that wants a different answer asks a different question rather than editing
 * the reply.
 */
final class Scope_Grant {

	/**
	 * @param string     $source              Who granted this rectangle:
	 *                                        'instructor' | 'space' |
	 *                                        'space_group' | 'cohort'. Carried
	 *                                        so a caller can say WHY something
	 *                                        is visible, and so a UI can label
	 *                                        the lens the viewer is looking
	 *                                        through.
	 * @param int[]|null $course_ids          Courses in this rectangle, or null
	 *                                        for every course.
	 * @param int[]|null $learner_ids         Learners in this rectangle, or
	 *                                        null for every learner enrolled in
	 *                                        those courses.
	 * @param bool       $learners_truncated  True when `$learner_ids` was cut
	 *                                        short by a query bound and is
	 *                                        therefore INCOMPLETE. A miss
	 *                                        against a truncated list proves
	 *                                        nothing, so callers must fail
	 *                                        closed -- see
	 *                                        Permission_Engine::may_view_learner_in_course().
	 * @param array      $meta                Provenance for the grant's source,
	 *                                        e.g. [ 'space_id' => 7 ]. Never
	 *                                        read to make an access decision;
	 *                                        the two id axes are the decision.
	 */
	public function __construct(
		public readonly string $source,
		public readonly ?array $course_ids = null,
		public readonly ?array $learner_ids = null,
		public readonly bool $learners_truncated = false,
		public readonly array $meta = array()
	) {}

	/**
	 * Does this rectangle cover one learner in one course?
	 *
	 * Both axes must hold. A grant covering the right course but the wrong
	 * learner authorises nothing, which is the whole point of carrying two
	 * axes instead of one.
	 *
	 * @param int $learner_id Learner being looked at.
	 * @param int $course_id  Course the data belongs to.
	 * @return bool
	 */
	public function covers( int $learner_id, int $course_id ): bool {
		if ( null !== $this->course_ids && ! in_array( $course_id, $this->course_ids, true ) ) {
			return false;
		}

		if ( null !== $this->learner_ids && ! in_array( $learner_id, $this->learner_ids, true ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Would a MISS on this grant be inconclusive rather than a real refusal?
	 *
	 * Only when the course axis matched and the learner list is known to be
	 * incomplete. In that state the honest answer is "unknown", and the engine
	 * turns unknown into a refusal.
	 *
	 * @param int $course_id Course the data belongs to.
	 * @return bool
	 */
	public function miss_is_inconclusive( int $course_id ): bool {
		if ( ! $this->learners_truncated || null === $this->learner_ids ) {
			return false;
		}

		return null === $this->course_ids || in_array( $course_id, $this->course_ids, true );
	}
}
