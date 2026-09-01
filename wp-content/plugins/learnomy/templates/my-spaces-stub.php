<?php
/**
 * Fallback "My Spaces" page rendered when the Pro Spaces extension is
 * inactive. Free owns the URL surface and the slug setting so a Pro
 * deactivation doesn't strand the page in a 404, but the actual owner +
 * member dashboard lives in Pro.
 *
 * Pro overrides this template via `learnomy_template_map` when active.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

// The Template_Loader owns the page shell (theme header/footer on web, the
// chromeless minimal shell in the app WebView). This template renders only
// its content — calling get_header()/get_footer() here would double the
// chrome on web and inject orphaned theme chrome inside the app WebView.
?>
<main id="lrn-my-spaces" class="lrn-container lrn-my-spaces-stub">
	<div class="lrn-card lrn-my-spaces-stub__card">
		<h1 class="lrn-my-spaces-stub__title"><?php esc_html_e( 'Learning Spaces', 'learnomy' ); ?></h1>
		<p>
			<?php esc_html_e( 'Learning Spaces lets distributors and partner organisations run their own mini-schools on top of your course catalogue. Members get scoped access; owners self-manage their roster and reports.', 'learnomy' ); ?>
		</p>
		<p>
			<?php
			printf(
				/* translators: %s: bold opening, closing tags */
				esc_html__( 'This feature is part of %1$sLearnomy Pro%2$s.', 'learnomy' ),
				'<strong>',
				'</strong>'
			);
			?>
		</p>
		<a class="lrn-btn lrn-btn--secondary" href="<?php echo esc_url( \Learnomy\route_url( 'catalog' ) ); ?>">
			<?php esc_html_e( 'Browse Courses', 'learnomy' ); ?>
		</a>
	</div>
</main>
