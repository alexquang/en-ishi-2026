<?php
/**
 * "My Courses" tabbed grid — all / in-progress / completed / wishlist, plus
 * any tabs Pro modules register via `learnomy_dashboard_tab_buttons` +
 * `learnomy_dashboard_tab_panels` (legacy extension points, still honored).
 *
 * Locals: $enrollments, $in_progress_enrollments, $completed_enrollments,
 *         $wishlist_courses, $enrollment_courses, $total_enrolled,
 *         $total_in_progress, $total_completed, $total_wishlist
 *
 * Optional (set by Account_Courses_View_Data on the dedicated /account/courses/
 * section): $courses_has_more (per-tab bool map), $courses_per_page. When
 * present, each tab renders a "Load more" button that pages the rest in via
 * REST. Absent elsewhere, so the buttons simply don't render.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$lrn_courses_more = isset( $courses_has_more ) && is_array( $courses_has_more ) ? $courses_has_more : array();
$lrn_courses_per  = isset( $courses_per_page ) ? (int) $courses_per_page : 40;

/**
 * Render a "Load more" button for a tab when more rows exist. The button
 * carries the tab + the next offset (one page in) + the total so the JS can
 * stop when the window reaches the end.
 */
$lrn_load_more_button = static function ( string $tab, int $total ) use ( $lrn_courses_more, $lrn_courses_per ): void {
	if ( empty( $lrn_courses_more[ $tab ] ) ) {
		return;
	}
	?>
	<div class="lrn-load-more-wrap">
		<button type="button" class="lrn-btn lrn-btn--secondary lrn-load-more"
			data-tab="<?php echo esc_attr( $tab ); ?>"
			data-offset="<?php echo esc_attr( (string) $lrn_courses_per ); ?>"
			data-total="<?php echo esc_attr( (string) $total ); ?>">
			<?php esc_html_e( 'Load more', 'learnomy' ); ?>
		</button>
	</div>
	<?php
};
?>
<div class="lrn-tab-nav" role="tablist" aria-label="<?php esc_attr_e( 'Course tabs', 'learnomy' ); ?>">
	<button type="button" class="lrn-tab-nav__item lrn-tab-nav__item--active" role="tab"
		id="lrn-tab-btn-all" aria-selected="true" aria-controls="lrn-tab-all"
		data-tab="all" tabindex="0">
		<?php esc_html_e( 'All', 'learnomy' ); ?>
		<span class="lrn-tab-nav__count"><?php echo esc_html( (string) $total_enrolled ); ?></span>
	</button>
	<button type="button" class="lrn-tab-nav__item" role="tab"
		id="lrn-tab-btn-in-progress" aria-selected="false" aria-controls="lrn-tab-in-progress"
		data-tab="in-progress" tabindex="-1">
		<?php esc_html_e( 'In Progress', 'learnomy' ); ?>
		<span class="lrn-tab-nav__count"><?php echo esc_html( (string) $total_in_progress ); ?></span>
	</button>
	<button type="button" class="lrn-tab-nav__item" role="tab"
		id="lrn-tab-btn-completed" aria-selected="false" aria-controls="lrn-tab-completed"
		data-tab="completed" tabindex="-1">
		<?php esc_html_e( 'Completed', 'learnomy' ); ?>
		<span class="lrn-tab-nav__count"><?php echo esc_html( (string) $total_completed ); ?></span>
	</button>
	<button type="button" class="lrn-tab-nav__item" role="tab"
		id="lrn-tab-btn-wishlist" aria-selected="false" aria-controls="lrn-tab-wishlist"
		data-tab="wishlist" tabindex="-1">
		<?php esc_html_e( 'Wishlist', 'learnomy' ); ?>
		<span class="lrn-tab-nav__count"><?php echo esc_html( (string) $total_wishlist ); ?></span>
	</button>
	<?php
	/**
	 * Legacy extension point — kept for modules that registered tabs
	 * before the unified widget API existed (Notes & Bookmarks, User
	 * Journey). New modules should ship a top-level widget instead.
	 */
	do_action( 'learnomy_dashboard_tab_buttons' );
	?>
</div>

<!-- Tab: All -->
<div id="lrn-tab-all" class="lrn-tab-panel" role="tabpanel"
	aria-labelledby="lrn-tab-btn-all" data-tab="all" tabindex="0">
	<?php if ( $enrollments ) : ?>
		<div class="lrn-grid lrn-courses-grid" data-courses-grid="all">
			<?php
			foreach ( $enrollments as $enrollment ) :
				$course = $enrollment_courses[ (int) $enrollment->course_id ] ?? null;
				if ( ! $course ) {
					continue;
				}
				include \Learnomy\template_path( 'partials/course-card.php' );
			endforeach;
			?>
		</div>
		<?php $lrn_load_more_button( 'all', (int) $total_enrolled ); ?>
	<?php else : ?>
		<?php
		// Through the loader, not LEARNOMY_DIR: a child theme overriding this
		// partial must win, and a direct plugin path silently ignores it
		// (coding-rules Rule 11).
		$lrn_empty_state = \Learnomy\template_path( 'dashboard-widgets/student/partials/no-courses-empty-state.php' );
		if ( $lrn_empty_state ) {
			require $lrn_empty_state;
		}
		?>
	<?php endif; ?>
</div>

<!-- Tab: In Progress -->
<div id="lrn-tab-in-progress" class="lrn-tab-panel" role="tabpanel"
	aria-labelledby="lrn-tab-btn-in-progress" data-tab="in-progress" hidden tabindex="0">
	<?php if ( $in_progress_enrollments ) : ?>
		<div class="lrn-grid lrn-courses-grid" data-courses-grid="in-progress">
			<?php
			foreach ( $in_progress_enrollments as $enrollment ) :
				$course = $enrollment_courses[ (int) $enrollment->course_id ] ?? null;
				if ( ! $course ) {
					continue;
				}
				include \Learnomy\template_path( 'partials/course-card.php' );
			endforeach;
			?>
		</div>
		<?php $lrn_load_more_button( 'in-progress', (int) $total_in_progress ); ?>
	<?php else : ?>
		<div class="lrn-empty-state">
			<i data-lucide="clock" class="lrn-empty-state__icon" aria-hidden="true"></i>
			<h3 class="lrn-empty-state__title"><?php esc_html_e( 'No courses in progress', 'learnomy' ); ?></h3>
			<p class="lrn-empty-state__desc"><?php esc_html_e( 'Start a course and your progress will appear here.', 'learnomy' ); ?></p>
		</div>
	<?php endif; ?>
</div>

<!-- Tab: Completed -->
<div id="lrn-tab-completed" class="lrn-tab-panel" role="tabpanel"
	aria-labelledby="lrn-tab-btn-completed" data-tab="completed" hidden tabindex="0">
	<?php if ( $completed_enrollments ) : ?>
		<div class="lrn-grid lrn-courses-grid" data-courses-grid="completed">
			<?php
			foreach ( $completed_enrollments as $enrollment ) :
				$course = $enrollment_courses[ (int) $enrollment->course_id ] ?? null;
				if ( ! $course ) {
					continue;
				}
				include \Learnomy\template_path( 'partials/course-card.php' );
			endforeach;
			?>
		</div>
		<?php $lrn_load_more_button( 'completed', (int) $total_completed ); ?>
	<?php else : ?>
		<div class="lrn-empty-state">
			<i data-lucide="check-circle" class="lrn-empty-state__icon" aria-hidden="true"></i>
			<h3 class="lrn-empty-state__title"><?php esc_html_e( 'No completed courses', 'learnomy' ); ?></h3>
			<p class="lrn-empty-state__desc"><?php esc_html_e( 'Complete your first course to see it here.', 'learnomy' ); ?></p>
		</div>
	<?php endif; ?>
</div>

<!-- Tab: Wishlist -->
<div id="lrn-tab-wishlist" class="lrn-tab-panel" role="tabpanel"
	aria-labelledby="lrn-tab-btn-wishlist" data-tab="wishlist" hidden tabindex="0"
	data-wp-interactive="learnomy/wishlist">
	<?php if ( $wishlist_courses ) : ?>
		<div class="lrn-grid lrn-courses-grid" data-courses-grid="wishlist">
			<?php
			// Wishlist cards carry no enrollment/progress. Null the shared
			// $enrollment local so a leftover from the tabs above can't paint a
			// wrong progress here (the card also guards by course_id).
			$enrollment = null;
			foreach ( $wishlist_courses as $course ) :
				include \Learnomy\template_path( 'partials/course-card.php' );
			endforeach;
			?>
		</div>
		<?php $lrn_load_more_button( 'wishlist', (int) $total_wishlist ); ?>
	<?php endif; ?>
	<?php
	// Always present (hidden when the wishlist has courses) so the wishlist
	// store can reveal it the moment the last course is removed in-tab,
	// instead of leaving a blank grid behind.
	?>
	<div class="lrn-empty-state" data-wishlist-empty<?php echo $wishlist_courses ? ' hidden' : ''; ?>>
		<i data-lucide="heart" class="lrn-empty-state__icon" aria-hidden="true"></i>
		<h3 class="lrn-empty-state__title"><?php esc_html_e( 'Wishlist is empty', 'learnomy' ); ?></h3>
		<p class="lrn-empty-state__desc"><?php esc_html_e( 'Save courses you want to take later.', 'learnomy' ); ?></p>
	</div>
</div>

<?php
/**
 * Legacy panel extension point for modules registered against
 * `learnomy_dashboard_tab_panels` (Notes & Bookmarks, User Journey).
 */
do_action( 'learnomy_dashboard_tab_panels' );
?>
