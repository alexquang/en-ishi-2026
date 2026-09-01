<?php
/**
 * Public instructor profile page.
 *
 * Variables available:
 *   $route (string) 'instructor-profile'
 *   $slug  (string) instructor's user_login or user_nicename
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

// $instructor, $courses, $total_courses, $total_students, $avg_rating,
// $avatar_url, $bio, $expertise, $social are all supplied by
// Instructor_Profile_View_Data via Template_Loader's extract().
// $not_found flips when the slug matches nobody OR matches somebody who is not
// a public instructor (Instructor::has_public_profile) — render 404. Both cases
// give the SAME verdict on purpose: a distinguishable "user exists but is not an
// instructor" response is a username-existence oracle.
// The not-found verdict is made by the view-data resolver and honoured by
// Template_Loader BEFORE any output, so this guard is a safety net only (a
// third party swapping the resolver via `learnomy_view_data_resolvers`). It
// routes through the one shared renderer instead of repeating the incantation.
if ( ! empty( $not_found ) ) {
	\Learnomy\Template_Loader::render_not_found();
	return;
}
?>
<main class="lrn-container lrn-instructor-profile" id="lrn-instructor-profile">

	<div class="lrn-instructor-profile__header lrn-card">
		<div class="lrn-instructor-profile__avatar-wrap">
			<img class="lrn-instructor-profile__avatar"
				src="<?php echo esc_url( $avatar_url ); ?>"
				alt="<?php echo esc_attr( $instructor->display_name ); ?>"
				width="120" height="120" loading="lazy">
		</div>

		<div class="lrn-instructor-profile__info">
			<h1 class="lrn-instructor-profile__name"><?php echo esc_html( $instructor->display_name ); ?></h1>

				<?php if ( ! empty( $headline ) ) : ?>
					<p class="lrn-instructor-profile__headline"><?php echo esc_html( $headline ); ?></p>
				<?php endif; ?>

			<?php if ( $expertise ) : ?>
				<p class="lrn-instructor-profile__expertise">
					<?php foreach ( array_filter( array_map( 'trim', explode( ',', $expertise ) ) ) as $tag ) : ?>
						<span class="lrn-badge lrn-badge--blue"><?php echo esc_html( $tag ); ?></span>
					<?php endforeach; ?>
				</p>
			<?php endif; ?>

			<?php if ( $bio ) : ?>
				<p class="lrn-instructor-profile__bio"><?php echo esc_html( $bio ); ?></p>
			<?php endif; ?>

			<div class="lrn-instructor-profile__stats">
				<span>
					<strong><?php echo esc_html( $total_courses ); ?></strong>
					<?php echo esc_html( _n( 'course', 'courses', $total_courses, 'learnomy' ) ); ?>
				</span>
				<span>
					<strong><?php echo esc_html( number_format_i18n( $total_students ) ); ?></strong>
					<?php esc_html_e( 'students', 'learnomy' ); ?>
				</span>
				<?php if ( $avg_rating > 0 ) : ?>
					<span>
						&#9733; <strong><?php echo esc_html( number_format_i18n( $avg_rating, 1 ) ); ?></strong>
						<?php esc_html_e( 'avg rating', 'learnomy' ); ?>
					</span>
				<?php endif; ?>
			</div>

			<div class="lrn-instructor-profile__social">
				<?php if ( $social['website'] ) : ?>
					<a href="<?php echo esc_url( $social['website'] ); ?>" class="lrn-social-link" target="_blank" rel="noopener noreferrer" aria-label="<?php esc_attr_e( 'Website', 'learnomy' ); ?>">
						<i data-lucide="globe" aria-hidden="true"></i>
					</a>
				<?php endif; ?>
				<?php if ( $social['twitter'] ) : ?>
					<a href="<?php echo esc_url( 'https://twitter.com/' . ltrim( $social['twitter'], '@' ) ); ?>" class="lrn-social-link" target="_blank" rel="noopener noreferrer" aria-label="<?php esc_attr_e( 'Twitter / X', 'learnomy' ); ?>">
						<i data-lucide="twitter" aria-hidden="true"></i>
					</a>
				<?php endif; ?>
				<?php if ( $social['linkedin'] ) : ?>
					<a href="<?php echo esc_url( $social['linkedin'] ); ?>" class="lrn-social-link" target="_blank" rel="noopener noreferrer" aria-label="<?php esc_attr_e( 'LinkedIn', 'learnomy' ); ?>">
						<i data-lucide="linkedin" aria-hidden="true"></i>
					</a>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<!-- Instructor's published courses -->
	<section class="lrn-instructor-profile__courses" aria-labelledby="instructor-courses-heading">
		<h2 id="instructor-courses-heading">
			<?php
			/* translators: %s: instructor display name */
			echo esc_html( sprintf( __( 'Courses by %s', 'learnomy' ), $instructor->display_name ) );
			?>
		</h2>

		<?php if ( $courses ) : ?>
			<div class="lrn-grid lrn-courses-grid" role="list">
				<?php foreach ( $courses as $course ) : ?>
					<div role="listitem">
						<?php include \Learnomy\template_path( 'partials/course-card.php' ); ?>
					</div>
				<?php endforeach; ?>
			</div>

			<?php if ( $total_pages > 1 ) : ?>
				<?php
				// Build the base profile URL then append ?page=N as a query arg.
				// The route only carries the slug path segment; paging is a query
				// param so existing rewrite rules stay unchanged.
				$lrn_profile_base = \Learnomy\route_url( 'instructor-profile', array( 'slug' => $instructor->user_login ) );
				?>
				<nav class="lrn-pagination" aria-label="<?php esc_attr_e( 'Courses pagination', 'learnomy' ); ?>">
					<?php if ( $page > 1 ) : ?>
						<a class="lrn-pagination__btn lrn-btn lrn-btn--ghost lrn-btn--sm"
							href="<?php echo esc_url( add_query_arg( 'page', $page - 1, $lrn_profile_base ) ); ?>">
							&#8592; <?php esc_html_e( 'Previous', 'learnomy' ); ?>
						</a>
					<?php endif; ?>
					<span class="lrn-pagination__info lrn-muted">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: current page, 2: total pages */
								__( 'Page %1$s of %2$s', 'learnomy' ),
								number_format_i18n( $page ),
								number_format_i18n( $total_pages )
							)
						);
						?>
					</span>
					<?php if ( $page < $total_pages ) : ?>
						<a class="lrn-pagination__btn lrn-btn lrn-btn--ghost lrn-btn--sm"
							href="<?php echo esc_url( add_query_arg( 'page', $page + 1, $lrn_profile_base ) ); ?>">
							<?php esc_html_e( 'Next', 'learnomy' ); ?> &#8594;
						</a>
					<?php endif; ?>
				</nav>
			<?php endif; ?>
		<?php else : ?>
			<div class="lrn-empty-state">
				<p class="lrn-empty-state__desc">
					<?php
					/* translators: %s: instructor display name */
					echo esc_html( sprintf( __( '%s has no published courses yet.', 'learnomy' ), $instructor->display_name ) );
					?>
				</p>
			</div>
		<?php endif; ?>
	</section>

</main>
