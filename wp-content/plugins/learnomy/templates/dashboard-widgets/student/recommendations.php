<?php
/**
 * Student recommendations widget — courses they might like.
 *
 * Algorithm (Course::recommended_for_user):
 *   1. Find categories of courses the user is enrolled in
 *   2. Surface published courses in those categories, NOT yet enrolled
 *   3. Sort by student_count DESC then avg_rating DESC
 *   4. Fall back to popular published courses (still de-duped against
 *      enrollments) when category overlap is empty
 *
 * Boundary: own data only — recommendations are tailored to MY history,
 * not the global top.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$recs = \Learnomy\Models\Course::recommended_for_user( (int) $user_id, 3 );
if ( empty( $recs ) ) {
	return;
}
?>
<div class="lrn-grid lrn-courses-grid lrn-recs-grid">
	<?php
	foreach ( $recs as $course ) :
		// Re-use the existing course-card partial for visual consistency
		// with the enrollments grid — single source of truth for the
		// card layout. The partial reads $course from local scope.
		?>
		<?php include \Learnomy\template_path( 'partials/course-card.php' ); ?>
	<?php endforeach; ?>
</div>
