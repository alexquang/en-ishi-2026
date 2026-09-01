<?php
/**
 * "My Courses" instructor widget body — table of authored courses.
 *
 * The first page (25 rows) renders server-side for instant paint / no CLS;
 * Prev/Next then swaps just this table's <tbody> via REST
 * (GET courses/instructor-page) so an instructor with hundreds of courses pages
 * through them without reloading the dashboard's other sections. Rows come from
 * the shared my-courses-rows partial — no row markup lives in JS.
 *
 * Locals: $my_courses, $create_url, $my_course_total, $my_courses_per_page
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$my_courses_per_page = 25;
$lrn_courses_total   = (int) ( $my_course_total ?? count( $my_courses ) );
$lrn_courses_pages   = (int) max( 1, (int) ceil( $lrn_courses_total / $my_courses_per_page ) );

if ( empty( $my_courses ) ) {
	?>
	<div class="lrn-empty-state">
		<i data-lucide="book-open" class="lrn-empty-state__icon" aria-hidden="true"></i>
		<h3 class="lrn-empty-state__title"><?php esc_html_e( 'No courses yet', 'learnomy' ); ?></h3>
		<p class="lrn-empty-state__desc"><?php esc_html_e( 'Create your first course to get started.', 'learnomy' ); ?></p>
		<a href="<?php echo esc_url( $create_url ); ?>" class="lrn-btn lrn-btn--primary">
			<?php esc_html_e( 'Create Your First Course', 'learnomy' ); ?>
		</a>
	</div>
	<?php
	return;
}
?>
<div class="lrn-table-wrap">
	<table class="lrn-table">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Title', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'learnomy' ); ?></th>
				<th scope="col"><?php echo esc_html( \Learnomy\student_label( true ) ); ?></th>
				<?php
				/*
				 * Rating and Revenue were columns here and are gone (Basecamp
				 * 10171602987). Six columns did not fit: the table scrolled
				 * sideways and cut its own Actions cell off mid-word, so the
				 * primary control on the screen was partly off-canvas while long
				 * titles wrapped to four lines each.
				 *
				 * Neither column earned that cost. Revenue was a hardcoded "N/A"
				 * on every row - it never carried data - and Instructor Earnings
				 * answers money properly, per course and per period. Rating has
				 * the reviews surface. A list an instructor cannot scan, whose
				 * actions they cannot reach, is a worse trade than a number they
				 * can get on the page that owns it.
				 *
				 * The rows partial must match this header exactly: it is ALSO
				 * rendered on its own by Courses_Controller for AJAX refresh, so
				 * a column added to one and not the other misaligns the table
				 * only after an in-place update.
				 */
				?>
				<th scope="col"><?php esc_html_e( 'Actions', 'learnomy' ); ?></th>
			</tr>
		</thead>
		<tbody data-lrn-courses-tbody>
			<?php require \Learnomy\template_path( 'dashboard-widgets/instructor/my-courses-rows.php' ); ?>
		</tbody>
	</table>

	<?php
	$lrn_manage_all_url = apply_filters(
		'learnomy_instructor_manage_courses_url',
		admin_url( 'admin.php?page=learnomy-courses' )
	);

	if ( ! empty( $viewing_all_courses ) ) :
		// Admin platform-wide fallback — the REST pager is scoped to the current
		// user's own courses, so it can't page a platform-wide view. Show a
		// static link to the (paginated) management screen instead.
		if ( ! empty( $my_courses_overflow ) ) :
			?>
			<p class="lrn-dashboard-widget__overflow">
				<?php
				printf(
					/* translators: 1: number shown, 2: total courses */
					esc_html__( 'Showing %1$d of %2$d courses.', 'learnomy' ),
					(int) count( $my_courses ),
					(int) $lrn_courses_total
				);
				?>
				<a href="<?php echo esc_url( $lrn_manage_all_url ); ?>">
					<?php esc_html_e( 'Manage all courses', 'learnomy' ); ?>
				</a>
			</p>
			<?php
		endif;
	elseif ( $lrn_courses_pages > 1 ) :
		// Real instructor — async Prev/Next pager. The first page is
		// server-rendered above; Prev/Next swaps the <tbody> via REST
		// (assets/js/instructor-courses.js) without reloading the dashboard's
		// other sections. A "Manage all courses" deep link stays as the escape.
		?>
		<nav class="lrn-courses-pager"
			aria-label="<?php esc_attr_e( 'My courses pages', 'learnomy' ); ?>"
			data-lrn-courses-pager
			data-page="1"
			data-total-pages="<?php echo esc_attr( (string) $lrn_courses_pages ); ?>">
			<p class="lrn-courses-pager__status" aria-live="polite" data-lrn-courses-status>
				<?php
				printf(
					/* translators: 1: current page, 2: total pages */
					esc_html__( 'Page %1$d of %2$d', 'learnomy' ),
					1,
					(int) $lrn_courses_pages
				);
				?>
			</p>
			<div class="lrn-courses-pager__controls">
				<button type="button"
					class="lrn-btn lrn-btn--secondary lrn-btn--sm"
					data-lrn-courses-prev
					disabled>
					<?php esc_html_e( 'Previous', 'learnomy' ); ?>
				</button>
				<button type="button"
					class="lrn-btn lrn-btn--secondary lrn-btn--sm"
					data-lrn-courses-next>
					<?php esc_html_e( 'Next', 'learnomy' ); ?>
				</button>
				<a class="lrn-courses-pager__manage" href="<?php echo esc_url( $lrn_manage_all_url ); ?>">
					<?php esc_html_e( 'Manage all courses', 'learnomy' ); ?>
				</a>
			</div>
		</nav>
	<?php endif; ?>
</div>
