<?php
/**
 * Cancelled page — buyer abandoned at the gateway.
 *
 * Rendered by `Template_Loader::render()` after `Checkout_Cancelled_View_Data`
 * resolves the optional course/plan context. No DB writes — cancellations
 * leave no trace by design.
 *
 * Variables available (extracted from $view_data):
 *   $course_id        int
 *   $plan_id          int
 *   $course           object|null
 *   $plan             object|null
 *   $item_title       string
 *   $is_plan_purchase bool
 *   $retry_url        string  Back to /enroll/?course_id=N or ?plan_id=N
 *   $browse_url       string  /courses/ catalog
 *   $membership_url   string  /membership/ pricing
 *   $help_url         string
 *
 * Public route — no auth gate (the buyer may have already logged out at
 * the gateway, or be in a browser without their WP cookie).
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.PHP.DontExtract.extract_extract -- locals come from Template_Loader extract().

$course_id        = isset( $course_id ) ? (int) $course_id : 0;
$plan_id          = isset( $plan_id ) ? (int) $plan_id : 0;
$item_title       = isset( $item_title ) ? (string) $item_title : '';
$is_plan_purchase = ! empty( $is_plan_purchase );
$retry_url        = isset( $retry_url ) ? (string) $retry_url : \Learnomy\route_url( 'enroll' );
$browse_url       = isset( $browse_url ) ? (string) $browse_url : \Learnomy\route_url( 'catalog' );
$membership_url   = isset( $membership_url ) ? (string) $membership_url : \Learnomy\route_url( 'membership-pricing' );

?>
<main class="lrn-container lrn-thank-you" id="lrn-cancelled" aria-labelledby="lrn-cancelled-heading">

	<section class="lrn-thank-you__card lrn-thank-you__card--cancelled lrn-card" role="region">
		<div class="lrn-thank-you__icon lrn-thank-you__icon--cancelled" aria-hidden="true">
			<i data-lucide="info"></i>
		</div>
		<h1 id="lrn-cancelled-heading" class="lrn-thank-you__heading">
			<?php esc_html_e( 'Purchase cancelled', 'learnomy' ); ?>
		</h1>
		<p class="lrn-thank-you__lede">
			<?php esc_html_e( 'No charge was made. You can pick up where you left off whenever you are ready.', 'learnomy' ); ?>
		</p>

		<?php if ( $item_title ) : ?>
			<p class="lrn-thank-you__cancelled-item">
				<?php
				/* translators: %s: course or plan title */
				printf( esc_html__( 'Item: %s', 'learnomy' ), '<strong>' . esc_html( $item_title ) . '</strong>' );
				?>
			</p>
		<?php endif; ?>

		<div class="lrn-thank-you__actions">
			<?php if ( $course_id || $plan_id ) : ?>
				<a href="<?php echo esc_url( $retry_url ); ?>" class="lrn-btn lrn-btn--primary lrn-btn--lg">
					<?php esc_html_e( 'Try payment again', 'learnomy' ); ?>
				</a>
			<?php endif; ?>
			<?php if ( $is_plan_purchase ) : ?>
				<a href="<?php echo esc_url( $membership_url ); ?>" class="lrn-btn lrn-btn--secondary lrn-btn--lg">
					<?php esc_html_e( 'View Membership Plans', 'learnomy' ); ?>
				</a>
			<?php else : ?>
				<a href="<?php echo esc_url( $browse_url ); ?>" class="lrn-btn lrn-btn--secondary lrn-btn--lg">
					<?php esc_html_e( 'Browse other courses', 'learnomy' ); ?>
				</a>
			<?php endif; ?>
		</div>
	</section>

	<?php
	/**
	 * Fires after the cancelled page has rendered. Pro extensions can hook
	 * this to send abandoned-cart emails or attach a customer-support
	 * offer to the user's account.
	 *
	 * @since 2.2.0
	 *
	 * @param int $course_id Course the buyer was checking out (0 if plan).
	 * @param int $plan_id   Plan the buyer was checking out (0 if course).
	 */
	do_action( 'learnomy_cancelled_rendered', $course_id, $plan_id );
	?>
</main>
