<?php
/**
 * Grant chips for one learner on one course.
 *
 * Shared by every surface that lists people on a course, so the course-edit
 * panel, the course-detail roster and the instructor dashboard feed cannot
 * drift into three different vocabularies for the same fact. On a site where
 * instructors sell courses, "Purchase" vs "Free" is the difference between a
 * paying student and a comped one, so a roster that collapses a learner's rows
 * must still show which grants they hold.
 *
 * Basecamp 10167508313.
 *
 * Expects in scope:
 *   $lrn_grant_sources (string[]) Raw source values, already de-duplicated.
 *
 * @package Learnomy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$lrn_grant_sources = isset( $lrn_grant_sources ) ? (array) $lrn_grant_sources : array();

if ( ! $lrn_grant_sources ) {
	return;
}
?>
<span class="lrn-grant-chips">
	<?php foreach ( $lrn_grant_sources as $lrn_grant_source ) : ?>
		<?php
		$lrn_grant_source = (string) $lrn_grant_source;
		$lrn_grant_paid   = \Learnomy\Models\Enrollment::source_is_paid( $lrn_grant_source );
		?>
		<span class="lrn-grant-chip<?php echo $lrn_grant_paid ? ' lrn-grant-chip--paid' : ''; ?>">
			<?php echo esc_html( \Learnomy\Models\Enrollment::source_label( $lrn_grant_source ) ); ?>
		</span>
	<?php endforeach; ?>
</span>
