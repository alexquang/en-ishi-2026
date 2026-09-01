<?php
/**
 * The page a non-instructor gets when they open an instructor surface.
 *
 * ONE copy, rendered by all six instructor routes. The root `/instructor/`
 * carried this markup inline and the five section routes had nothing - they
 * returned 404, on the reasoning that a section "has no answer for a visitor
 * who is not an instructor". It has exactly the same answer the root gives,
 * and saying it is what let the plugin stop editing the site owner's menu:
 *
 *   404 on a section  ->  a menu item pointing there is a dead end
 *                     ->  so hide the owner's menu item
 *                     ->  so compare its URL path against route_url()
 *                     ->  and an unregistered route's home_url() fallback
 *                         matched, and ate the Home item on Free-only sites.
 *
 * Every step after the first exists only because of the first. A page that
 * explains itself removes the whole chain, and the site's navigation goes
 * back to belonging to the site.
 *
 * Expects:
 *
 * @var bool $has_pending_application Whether this viewer has applied already.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

?>
<main class="lrn-container lrn-access-gate">
	<?php if ( ! empty( $has_pending_application ) ) : ?>
		<?php // Applicant whose application is still under review - explain the wait instead of prompting them to apply again. ?>
		<div class="lrn-empty-state">
			<i data-lucide="clock" class="lrn-empty-state__icon" aria-hidden="true"></i>
			<h1 class="lrn-empty-state__title"><?php esc_html_e( 'Application Under Review', 'learnomy' ); ?></h1>
			<p class="lrn-empty-state__desc">
				<?php esc_html_e( 'Thanks for applying to become an instructor. Our team is reviewing your application and you will get an email as soon as it is approved. Until then you can keep learning as a student.', 'learnomy' ); ?>
			</p>
			<a href="<?php echo esc_url( \Learnomy\route_url( 'student-dashboard' ) ); ?>" class="lrn-btn lrn-btn--primary">
				<?php
				printf(
					/* translators: %s: plural label for courses (e.g. "Programmes"). */
					esc_html__( 'Go to My %s', 'learnomy' ),
					esc_html( \Learnomy\course_label( true ) )
				);
				?>
			</a>
		</div>
	<?php else : ?>
		<div class="lrn-empty-state">
			<i data-lucide="lock" class="lrn-empty-state__icon" aria-hidden="true"></i>
			<h1 class="lrn-empty-state__title"><?php printf( esc_html( /* translators: %s: singular label for an instructor (e.g. "Coach"). */ __( '%s access required', 'learnomy' ) ), esc_html( \Learnomy\instructor_label() ) ); ?></h1>
			<p class="lrn-empty-state__desc">
				<?php
				printf(
					/* translators: %s: singular label for an instructor (e.g. "Coach"). */
					esc_html__( 'You need %s access to view this page.', 'learnomy' ),
					esc_html( \Learnomy\instructor_label() )
				);
				// Article-free on purpose: the label is owner-supplied, so "an
				// %s account" produces "an Coach account" the moment somebody
				// renames the noun - which 1.9.3 lets them do.
				?>
			</p>
			<?php if ( \Learnomy\instructor_registration_enabled() ) : ?>
				<a href="<?php echo esc_url( \Learnomy\route_url( 'become-instructor' ) ); ?>" class="lrn-btn lrn-btn--primary">
					<?php printf( esc_html( /* translators: %s: singular label for an instructor (e.g. "Coach"). */ __( 'Apply as %s', 'learnomy' ) ), esc_html( \Learnomy\instructor_label() ) ); ?>
				</a>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</main>
