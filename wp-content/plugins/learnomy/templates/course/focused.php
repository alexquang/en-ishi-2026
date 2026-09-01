<?php
/**
 * Single course — FOCUSED / content-first variant.
 *
 * No hero image. A tight, light header bar carries the title, primary meta, and
 * an inline Enroll CTA. The body sits centered and wide with generous
 * whitespace. On mobile the Enroll CTA becomes a sticky bottom bar so the
 * primary action is always reachable while reading.
 *
 * Reuses the shared body + enroll-card partials so both the enrolled and
 * non-enrolled branches match classic. Root class `.lrn-course--focused`.
 *
 * Variables: same scope as course/classic.php.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

// The not-found verdict is made by the view-data resolver and honoured by
// Template_Loader BEFORE any output, so this guard is a safety net only (a
// third party swapping the resolver via `learnomy_view_data_resolvers`). It
// routes through the one shared renderer instead of repeating the incantation.
if ( ! empty( $not_found ) ) {
	\Learnomy\Template_Loader::render_not_found();
	return;
}

// phpcs:disable WordPress.Security.NonceVerification.Recommended
$lrn_just_enrolled = $is_enrolled
	&& isset( $_GET['lrn_enrolled'] )
	&& '1' === sanitize_text_field( wp_unslash( $_GET['lrn_enrolled'] ) );
// phpcs:enable

// Inline / sticky CTA targets. For the enrolled view the CTA is "Continue";
// for the sales view it routes to enroll / register exactly like the card.
// Members-only courses with no real-money price must not display "Free" — the
// route to access is a membership, not a free enrollment. See enroll-card.php
// for the same price-label vocabulary.
$lrn_price_label = '';
if ( \Learnomy\Models\Course::is_members_only( $course ) ) {
	$lrn_price_label = __( 'Members only', 'learnomy' );
} elseif ( \Learnomy\Models\Course::is_free( $course ) || $original_price <= 0 ) {
	$lrn_price_label = __( 'Free', 'learnomy' );
} elseif ( $has_sale ) {
	$lrn_price_label = \Learnomy\format_price( $sale_price );
} else {
	$lrn_price_label = \Learnomy\format_price( $original_price );
}

// Sales-view CTA target. For members-only courses the inline / sticky CTA
// routes directly to the membership pricing page — Enroll Now would
// otherwise land the buyer on /membership/ via the router's
// resolve_enroll_source() with no explanation.
$lrn_members_only_no_access = \Learnomy\Models\Course::is_members_only( $course ) && empty( $member_can_claim );
$lrn_inline_cta_href        = $lrn_members_only_no_access ? \Learnomy\route_url( 'membership-pricing' ) : $enroll_url;
$lrn_inline_cta_label       = $lrn_members_only_no_access
	? __( 'Join to enroll', 'learnomy' )
	: ( ( 'free' !== ( $course->access_type ?? '' ) && isset( $current_price ) && (float) $current_price > 0 ) ? __( 'Buy Now', 'learnomy' ) : __( 'Enroll Now', 'learnomy' ) );

// An extension may replace the enrol CTA entirely — a cohort-delivered course
// answers "join a cohort", not "buy". enroll-card.php (included below) asks via
// this filter; this layout built its own label from price and access type alone
// and rendered it TWICE, so a cohort course contradicted its own enrol card in
// two places, at every viewport rather than just on mobile.
//
// Where a gate is in force these CTAs stand down and let the card speak: the
// headbar keeps the price but drops the button, and the layout's own sticky bar
// does not render. Asking the same filter with the same arguments, rather than
// re-deriving what "cohort" means here.
$lrn_enroll_gated = '' !== (string) apply_filters( 'learnomy_course_enroll_gate', '', $course, get_current_user_id() );
?>

<div class="lrn-course lrn-course--focused">

	<?php if ( $lrn_just_enrolled ) : ?>
		<div class="lrn-container lrn-container--narrow">
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

	<!-- ================================================================
		TIGHT LIGHT HEADER BAR (title + meta + inline Enroll)
		================================================================ -->
	<header class="lrn-course-headbar">
		<div class="lrn-container lrn-container--narrow lrn-course-headbar__inner">
			<div class="lrn-course-headbar__text">
				<div class="lrn-course-headbar__badges">
					<?php if ( $category ) : ?>
						<span class="lrn-badge lrn-badge--blue"><?php echo esc_html( $category->name ); ?></span>
					<?php endif; ?>
					<?php if ( $difficulty_label ) : ?>
						<span class="lrn-badge lrn-badge--outline"><?php echo esc_html( $difficulty_label ); ?></span>
					<?php endif; ?>
					<?php if ( $is_enrolled ) : ?>
						<span class="lrn-badge lrn-badge--green"><?php esc_html_e( 'Enrolled', 'learnomy' ); ?></span>
					<?php endif; ?>
				</div>
				<h1 class="lrn-course-headbar__title"><?php echo esc_html( $course->title ); ?></h1>
				<div class="lrn-course-headbar__meta">
					<?php if ( $instructor ) : ?>
						<a href="<?php echo esc_url( $instructor_url ); ?>" class="lrn-course-headbar__meta-item"><?php echo esc_html( $instructor->display_name ); ?></a>
					<?php endif; ?>
					<?php if ( (float) $course->avg_rating > 0 ) : ?>
						<span class="lrn-course-headbar__meta-item">&#9733; <?php echo esc_html( number_format_i18n( (float) $course->avg_rating, 1 ) ); ?></span>
					<?php endif; ?>
					<?php if ( $duration_label ) : ?>
						<span class="lrn-course-headbar__meta-item"><i data-lucide="clock" aria-hidden="true"></i> <?php echo esc_html( $duration_label ); ?></span>
					<?php endif; ?>
				</div>
			</div>

			<div class="lrn-course-headbar__actions">
				<?php if ( $is_enrolled ) : ?>
					<a href="<?php echo esc_url( $continue_url ); ?>" class="lrn-btn lrn-btn--primary lrn-btn--lg"><?php esc_html_e( 'Continue', 'learnomy' ); ?></a>
				<?php elseif ( $lrn_enroll_gated ) : ?>
					<span class="lrn-course-headbar__price"><?php echo esc_html( $lrn_price_label ); ?></span>
				<?php elseif ( is_user_logged_in() ) : ?>
					<span class="lrn-course-headbar__price"><?php echo esc_html( $lrn_price_label ); ?></span>
					<a href="<?php echo esc_url( $lrn_inline_cta_href ); ?>" class="lrn-btn lrn-btn--primary lrn-btn--lg"><?php echo esc_html( $lrn_inline_cta_label ); ?></a>
				<?php else : ?>
					<?php
					$lrn_course_url   = \Learnomy\route_url( 'course', array( 'slug' => (string) $course->slug ) );
					$lrn_register_url = add_query_arg( array( 'redirect_to' => rawurlencode( $lrn_course_url ) ), \Learnomy\route_url( 'register-student' ) );
					?>
					<span class="lrn-course-headbar__price"><?php echo esc_html( $lrn_price_label ); ?></span>
					<a href="<?php echo esc_url( $lrn_register_url ); ?>" class="lrn-btn lrn-btn--primary lrn-btn--lg"><?php esc_html_e( 'Sign up to enroll', 'learnomy' ); ?></a>
				<?php endif; ?>
			</div>
		</div>
	</header>

	<?php
	/**
	 * Placement region above the course body.
	 *
	 * @param object $course Course row.
	 */
	do_action( 'learnomy_course_before_content', $course );
	?>

	<!-- ================================================================
		CENTERED + WIDE BODY (single column, generous whitespace)
		================================================================ -->
	<div class="lrn-container lrn-container--narrow lrn-single-course lrn-course-focused-body">
		<main class="lrn-single-course__main lrn-course-focused-body__main">
			<?php require \Learnomy\template_path( 'course/partials/body.php' ); ?>

			<?php if ( ! $is_enrolled ) : ?>
				<!-- Full enroll card at the foot of the content for the desktop reader. -->
				<div class="lrn-course-focused-body__card lrn-card">
					<?php include \Learnomy\template_path( 'course/partials/enroll-card.php' ); ?>
				</div>
			<?php endif; ?>
		</main>
	</div>

	<?php
	/**
	 * Placement region below the course body.
	 *
	 * @param object $course Course row.
	 */
	do_action( 'learnomy_course_after_content', $course );
	?>

	<!-- Mobile sticky bottom enroll bar (CSS-shown below the mobile breakpoint). -->
	<?php if ( ! $is_enrolled && ! $lrn_enroll_gated ) : ?>
		<div class="lrn-course-stickybar" role="region" aria-label="<?php esc_attr_e( 'Course enrollment', 'learnomy' ); ?>">
			<span class="lrn-course-stickybar__price"><?php echo esc_html( $lrn_price_label ); ?></span>
			<?php if ( is_user_logged_in() ) : ?>
				<a href="<?php echo esc_url( $lrn_inline_cta_href ); ?>" class="lrn-btn lrn-btn--primary lrn-btn--full"><?php echo esc_html( $lrn_inline_cta_label ); ?></a>
			<?php else : ?>
				<?php
				$lrn_course_url   = \Learnomy\route_url( 'course', array( 'slug' => (string) $course->slug ) );
				$lrn_register_url = add_query_arg( array( 'redirect_to' => rawurlencode( $lrn_course_url ) ), \Learnomy\route_url( 'register-student' ) );
				?>
				<a href="<?php echo esc_url( $lrn_register_url ); ?>" class="lrn-btn lrn-btn--primary lrn-btn--full"><?php esc_html_e( 'Sign up to enroll', 'learnomy' ); ?></a>
			<?php endif; ?>
		</div>
	<?php else : ?>
		<div class="lrn-course-stickybar" role="region" aria-label="<?php esc_attr_e( 'Continue course', 'learnomy' ); ?>">
			<a href="<?php echo esc_url( $continue_url ); ?>" class="lrn-btn lrn-btn--primary lrn-btn--full"><?php esc_html_e( 'Continue', 'learnomy' ); ?></a>
		</div>
	<?php endif; ?>

</div>
