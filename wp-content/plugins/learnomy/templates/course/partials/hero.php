<?php
/**
 * Single course — dark hero header (shared partial).
 *
 * Extracted byte-identical from course/classic.php. Renders the dark hero
 * banner shown on the sales view (non-enrolled). Inherits the single-course
 * template scope ($course, $is_enrolled, $thumbnail_url, $category,
 * $difficulty_label, $instructor, $instructor_url, $duration_label, ...).
 *
 * The non-enrolled guard lives in the caller; this partial assumes it is only
 * included when the hero should render.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;
?>
<!-- ================================================================
	HERO BANNER (Sales view only)
	================================================================ -->
<div class="lrn-hero"
	<?php
	if ( $thumbnail_url ) :
		?>
	data-has-bg="true"<?php endif; ?>>
	<?php if ( $thumbnail_url ) : ?>
		<div class="lrn-hero__bg" role="img" aria-label="<?php echo esc_attr( $course->title ); ?>">
			<img src="<?php echo esc_url( $thumbnail_url ); ?>" alt="" loading="eager" class="lrn-hero__bg-img">
		</div>
	<?php else : ?>
		<span class="lrn-hero__initial" aria-hidden="true"><?php echo esc_html( mb_strtoupper( mb_substr( $course->title ?: 'L', 0, 1 ) ) ); ?></span>
	<?php endif; ?>
	<div class="lrn-hero__overlay"></div>
	<div class="lrn-hero__inner lrn-container">
		<?php
		// Root is the catalog for EVERY reader now — breadcrumb_root() no
		// longer swaps it for the account hub when someone is signed in
		// (Basecamp 10184419200). The Courses crumb that used to be appended
		// for logged-in readers would duplicate that root, so it is gone.
		$breadcrumb_items = [ \Learnomy\breadcrumb_root() ];
		if ( $category ) {
			$breadcrumb_items[] = [
				'label' => $category->name,
				'href'  => \Learnomy\route_url( 'category', array( 'slug' => (string) $category->slug ) ),
			];
		}
		$breadcrumb_items[] = [
			'label' => $course->title,
		];
		require \Learnomy\template_path( 'partials/breadcrumb.php' );
		?>

		<div class="lrn-hero__badges">
			<?php if ( $category ) : ?>
				<span class="lrn-badge lrn-badge--blue"><?php echo esc_html( $category->name ); ?></span>
			<?php endif; ?>
			<?php if ( $difficulty_label ) : ?>
				<span class="lrn-badge lrn-badge--outline"><?php echo esc_html( $difficulty_label ); ?></span>
			<?php endif; ?>
		</div>

		<h1 class="lrn-hero__title"><?php echo esc_html( $course->title ); ?></h1>

		<?php if ( ! empty( $course->short_description ) ) : ?>
			<p class="lrn-hero__tagline"><?php echo esc_html( $course->short_description ); ?></p>
		<?php elseif ( ! empty( $course->description ) ) : ?>
			<p class="lrn-hero__tagline"><?php echo esc_html( wp_trim_words( \Learnomy\render_editorjs_content( (string) $course->description, '', 'plain' ), 30 ) ); ?></p>
		<?php endif; ?>

		<div class="lrn-hero__meta">
			<?php if ( $instructor ) : ?>
				<span class="lrn-hero__meta-item">
					<?php echo get_avatar( $instructor->ID, 24, '', '', [ 'class' => 'lrn-hero__meta-avatar' ] ); ?>
					<a href="<?php echo esc_url( $instructor_url ); ?>" class="lrn-hero__meta-link">
						<?php echo esc_html( $instructor->display_name ); ?>
					</a>
				</span>
			<?php endif; ?>

			<?php // Co-instructors credited alongside the lead. ?>
			<?php foreach ( ( $co_instructors ?? array() ) as $lrn_co ) : ?>
				<span class="lrn-hero__meta-item">
					<?php echo wp_kses_post( (string) ( $lrn_co['avatar'] ?? '' ) ); ?>
					<a href="<?php echo esc_url( (string) ( $lrn_co['url'] ?? '' ) ); ?>" class="lrn-hero__meta-link">
						<?php echo esc_html( (string) ( $lrn_co['name'] ?? '' ) ); ?>
					</a>
				</span>
			<?php endforeach; ?>

			<?php if ( (float) $course->avg_rating > 0 ) : ?>
				<span class="lrn-hero__meta-item lrn-hero__meta-item--rating">
					<span class="lrn-hero__stars">&#9733; <?php echo esc_html( number_format_i18n( (float) $course->avg_rating, 1 ) ); ?></span>
					<span class="lrn-hero__meta-muted">(<?php echo esc_html( number_format_i18n( (int) $course->review_count ) ); ?> <?php esc_html_e( 'ratings', 'learnomy' ); ?>)</span>
				</span>
			<?php endif; ?>

			<?php
			$sc = (int) $course->student_count;
			if ( $sc > 0 ) :
				?>
				<span class="lrn-hero__meta-item">
					<i data-lucide="users" aria-hidden="true"></i>
					<?php
					printf(
						/* translators: %s: number of students */
						esc_html( _n( '%s student', '%s students', $sc, 'learnomy' ) ),
						esc_html( number_format_i18n( $sc ) )
					);
					?>
				</span>
			<?php endif; ?>

			<?php if ( $duration_label ) : ?>
				<span class="lrn-hero__meta-item">
					<i data-lucide="clock" aria-hidden="true"></i>
					<?php echo esc_html( $duration_label ); ?>
				</span>
			<?php endif; ?>
		</div>

		<?php
		// A MySQL zero-date ('0000-00-00 00:00:00') does not survive strtotime():
		// depending on the PHP build it yields either false or a pre-epoch
		// negative timestamp, and the negative one formatted as "Last updated
		// November 30, -0001" on the course page. Guard the same way the quiz
		// gate guards open_at/close_at, and say nothing rather than claim a date
		// the course does not have.
		$updated_ts = empty( $course->updated_at ) ? false : strtotime( $course->updated_at );
		if ( $updated_ts && $updated_ts > 0 ) :
			?>
			<p class="lrn-hero__updated">
				<?php
				printf(
					/* translators: %s: date */
					esc_html__( 'Last updated %s', 'learnomy' ),
					esc_html( date_i18n( get_option( 'date_format' ), $updated_ts ) )
				);
				?>
			</p>
		<?php endif; ?>
	</div>
</div>
