<?php
/**
 * Single course page — premium sales/landing page (CLASSIC variant, default).
 *
 * Two modes:
 *   1. Sales view (not enrolled) — full course landing page with hero, curriculum, instructor, reviews.
 *   2. Learning view (enrolled)  — progress bar, curriculum with completion, continue button.
 *
 * Composed from shared partials under templates/course/partials/ so the hero,
 * body, and enroll card can be reused by the hero / focused / split variants.
 * The arrangement here (dark hero + two-column main/sticky-sidebar) reproduces
 * the original single template byte-for-byte.
 *
 * Variables available:
 *   $route (string) 'course'
 *   $slug  (string) course slug
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

// $view_data variables (injected by Template_Loader via Single_Course_View_Data):
// $not_found, $course, $user_id, $is_enrolled, $enrollment, $instructor,
// $instructor_url, $instructor_course_count, $instructor_student_count,
// $sections, $all_lessons, $quizzes, $reviews, $all_reviews, $section_count,
// $lesson_count, $quiz_count, $category, $duration_secs, $duration_label,
// $has_sale, $original_price, $sale_price, $thumbnail_url, $enroll_url,
// $continue_url, $difficulty, $difficulty_label, $rating_distribution,
// $total_reviews, $refund_days

// The not-found verdict is made by the view-data resolver and honoured by
// Template_Loader BEFORE any output, so this guard is a safety net only (a
// third party swapping the resolver via `learnomy_view_data_resolvers`). It
// routes through the one shared renderer instead of repeating the incantation.
if ( ! empty( $not_found ) ) {
	\Learnomy\Template_Loader::render_not_found();
	return;
}

// F-04.2 success-flash — the free-enroll path in class-router.php redirects
// here with ?lrn_enrolled=1 on a fresh enrollment. One-shot banner above
// the hero so the buyer gets explicit confirmation that enrollment
// succeeded; the flag clears on next page load. No session, transient, or
// cookie required.
// phpcs:disable WordPress.Security.NonceVerification.Recommended
$lrn_just_enrolled = $is_enrolled
	&& isset( $_GET['lrn_enrolled'] )
	&& '1' === sanitize_text_field( wp_unslash( $_GET['lrn_enrolled'] ) );
// phpcs:enable
?>

<?php if ( $lrn_just_enrolled ) : ?>
<div class="lrn-container">
	<div class="lrn-notice lrn-notice--success" role="status" aria-live="polite">
		<i data-lucide="check-circle" aria-hidden="true"></i>
		<span>
			<?php
			printf(
				/* translators: %s: course title */
				esc_html__( "You're enrolled in %s. Pick up where you left off any time.", 'learnomy' ),
				'<strong>' . esc_html( $course->title ) . '</strong>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
			?>
		</span>
	</div>
</div>
<?php endif; ?>

<?php if ( ! $is_enrolled ) : ?>
	<?php include \Learnomy\template_path( 'course/partials/hero.php' ); ?>
<?php endif; ?>

<?php
/**
 * Placement region above the course body (admin edit bar, sponsor / CTA).
 * Mirrors the other course variants so the hook fires on every layout.
 *
 * @param object $course Course row.
 */
do_action( 'learnomy_course_before_content', $course );
?>

<!-- ================================================================
	MAIN CONTENT + SIDEBAR
	================================================================ -->
<div class="lrn-container lrn-single-course">
	<div class="lrn-single-course__layout">

		<main class="lrn-single-course__main">
			<?php require \Learnomy\template_path( 'course/partials/body.php' ); ?>
		</main>

		<!-- ================================================================
			SIDEBAR (sticky)
			================================================================ -->
		<aside class="lrn-single-course__sidebar" aria-label="<?php esc_attr_e( 'Course enrollment', 'learnomy' ); ?>">
			<?php
			/**
			 * Top of the course sidebar (sponsor / CTA / related). Fires on every
			 * layout that renders a sidebar — classic + split — so a sidebar
			 * extension is not locked to a single template. Mirrors split.php.
			 *
			 * @param object $course Course row.
			 */
			do_action( 'learnomy_course_sidebar_top', $course );
			?>

			<div class="lrn-sidebar-card lrn-card">
				<?php require \Learnomy\template_path( 'course/partials/enroll-card.php' ); ?>
			</div>

			<?php
			/**
			 * Bottom of the course sidebar.
			 *
			 * @param object $course Course row.
			 */
			do_action( 'learnomy_course_sidebar_bottom', $course );
			?>
		</aside>

	</div>
</div>

<?php
/**
 * Placement region below the course body. Mirrors the hero / focused / split
 * variants so the hook fires on every layout, including the default (classic).
 *
 * @param object $course Course row.
 */
do_action( 'learnomy_course_after_content', $course );
?>
