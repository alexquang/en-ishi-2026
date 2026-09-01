<?php
/**
 * Thank-you page — post-purchase landing for every gateway.
 *
 * Rendered by `Template_Loader::render()` after `Checkout_Thank_You_View_Data`
 * resolves the transaction (and falls back to `verify_payment_status()` when
 * the webhook race left no local row yet).
 *
 * Variables available (extracted from $view_data):
 *   $state              string  'paid' | 'pending' | 'failed'
 *   $session_id         string
 *   $gateway            string  Adapter id.
 *   $gateway_label      string  Human-readable provider name.
 *   $transaction        object|null
 *   $course             object|null
 *   $plan               object|null
 *   $subscription       object|null
 *   $is_plan_purchase   bool
 *   $is_course_purchase bool
 *   $included_courses   object[] Courses this membership plan unlocks (preview).
 *   $included_total     int      True number of courses in the plan.
 *   $currency           string  ISO code.
 *   $currency_symbol    string  '$', '€', etc.
 *   $amount             float
 *   $email_sent_to      string
 *   $receipt_link       string
 *   $primary_cta_url    string
 *   $primary_cta_label  string
 *   $secondary_cta_url  string
 *   $secondary_cta_label string
 *   $polling_seconds    int     For meta refresh on pending state.
 *   $retry_url          string
 *
 * Auth redirect handled by Template_Loader (route in $protected_routes).
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.PHP.DontExtract.extract_extract -- locals come from Template_Loader extract().

$state               = isset( $state ) ? (string) $state : 'pending';
$session_id          = isset( $session_id ) ? (string) $session_id : '';
$gateway             = isset( $gateway ) ? (string) $gateway : '';
$gateway_label       = isset( $gateway_label ) ? (string) $gateway_label : '';
$transaction         = isset( $transaction ) ? $transaction : null;
$course              = isset( $course ) ? $course : null;
$plan                = isset( $plan ) ? $plan : null;
$subscription        = isset( $subscription ) ? $subscription : null;
$is_plan_purchase    = ! empty( $is_plan_purchase );
$is_course_purchase  = ! empty( $is_course_purchase );
$currency            = isset( $currency ) ? (string) $currency : 'USD';
$currency_symbol     = isset( $currency_symbol ) ? (string) $currency_symbol : '$';
$amount              = isset( $amount ) ? (float) $amount : 0.0;
$is_test             = ! empty( $is_test );
$email_sent_to       = isset( $email_sent_to ) ? (string) $email_sent_to : '';
$receipt_link        = isset( $receipt_link ) ? (string) $receipt_link : \Learnomy\route_url( 'student-dashboard' );
$primary_cta_url     = isset( $primary_cta_url ) ? (string) $primary_cta_url : '';
$primary_cta_label   = isset( $primary_cta_label ) ? (string) $primary_cta_label : '';
$secondary_cta_url   = isset( $secondary_cta_url ) ? (string) $secondary_cta_url : '';
$secondary_cta_label = isset( $secondary_cta_label ) ? (string) $secondary_cta_label : '';
$polling_seconds     = isset( $polling_seconds ) ? (int) $polling_seconds : 3;
$polling_exhausted   = isset( $polling_exhausted ) ? (bool) $polling_exhausted : false;
$polling_next_url    = isset( $polling_next_url ) ? (string) $polling_next_url : '';
$retry_url           = isset( $retry_url ) ? (string) $retry_url : \Learnomy\route_url( 'enroll' );

// Item title — falls back gracefully so the pending state still says something.
$item_title = '';
if ( $course && ! empty( $course->title ) ) {
	$item_title = (string) $course->title;
} elseif ( $plan && ! empty( $plan->title ) ) {
	$item_title = (string) $plan->title;
}

// Order id for display — local txn id is more useful to support than the
// provider's session id.
$order_id_display = $transaction && ! empty( $transaction->id )
	? '#' . (int) $transaction->id
	: '';

// Pending state polls the page with meta refresh so the buyer doesn't need
// to refresh manually. We emit it BEFORE wp_head() has output </head> so
// the browser sees it; the actual <meta> goes inside the markup which is
// fine for refresh directives.
?>
<main class="lrn-container lrn-thank-you" id="lrn-thank-you" aria-labelledby="lrn-thank-you-heading">

	<?php if ( 'paid' === $state ) : ?>

		<section class="lrn-thank-you__card lrn-thank-you__card--paid lrn-card" role="region">
			<div class="lrn-thank-you__icon lrn-thank-you__icon--success" aria-hidden="true">
				<i data-lucide="circle-check-big"></i>
			</div>
			<h1 id="lrn-thank-you-heading" class="lrn-thank-you__heading">
				<?php esc_html_e( 'Thank you for your purchase', 'learnomy' ); ?>
			</h1>
			<p class="lrn-thank-you__lede">
				<?php if ( $is_plan_purchase ) : ?>
					<?php esc_html_e( 'Your membership is active and ready to go.', 'learnomy' ); ?>
				<?php elseif ( $is_course_purchase ) : ?>
					<?php esc_html_e( 'Your enrollment is confirmed. Time to start learning.', 'learnomy' ); ?>
				<?php else : ?>
					<?php esc_html_e( 'Your payment was received.', 'learnomy' ); ?>
				<?php endif; ?>
			</p>

			<div class="lrn-thank-you__summary" aria-label="<?php esc_attr_e( 'Order summary', 'learnomy' ); ?>">
				<h2 class="lrn-thank-you__summary-title">
					<?php esc_html_e( 'Order summary', 'learnomy' ); ?>
				</h2>
				<dl class="lrn-thank-you__details">
					<?php if ( $item_title ) : ?>
						<div class="lrn-thank-you__detail">
							<dt><?php echo $is_plan_purchase ? esc_html__( 'Membership', 'learnomy' ) : esc_html__( 'Course', 'learnomy' ); ?></dt>
							<dd><?php echo esc_html( $item_title ); ?></dd>
						</div>
					<?php endif; ?>
					<?php if ( $is_test || $amount > 0 ) : ?>
						<div class="lrn-thank-you__detail">
							<dt><?php echo $is_test ? esc_html__( 'Amount', 'learnomy' ) : esc_html__( 'Amount paid', 'learnomy' ); ?></dt>
							<dd class="lrn-thank-you__amount">
								<?php echo $is_test ? esc_html__( 'Test order - no charge', 'learnomy' ) : esc_html( \Learnomy\format_price( (float) $amount, $currency ) . ' ' . $currency ); ?>
							</dd>
						</div>
					<?php endif; ?>
					<?php if ( $gateway_label ) : ?>
						<div class="lrn-thank-you__detail">
							<dt><?php esc_html_e( 'Payment method', 'learnomy' ); ?></dt>
							<dd><?php echo esc_html( $gateway_label ); ?></dd>
						</div>
					<?php endif; ?>
					<?php if ( $order_id_display ) : ?>
						<div class="lrn-thank-you__detail">
							<dt><?php esc_html_e( 'Order ID', 'learnomy' ); ?></dt>
							<dd><code><?php echo esc_html( $order_id_display ); ?></code></dd>
						</div>
					<?php endif; ?>
				</dl>
			</div>

			<?php if ( $email_sent_to ) : ?>
				<p class="lrn-thank-you__receipt-note">
					<i data-lucide="mail" aria-hidden="true"></i>
					<?php // The sentence is wrapped so it is ONE flex item — see the note class in checkout-thank-you.css. ?>
					<span class="lrn-thank-you__note-text">
						<?php
						printf(
							/* translators: %s: buyer's email address */
							esc_html__( 'A receipt was emailed to %s.', 'learnomy' ),
							'<strong>' . esc_html( $email_sent_to ) . '</strong>'
						);
						?>
					</span>
				</p>
			<?php endif; ?>

			<?php
			// What the membership just unlocked. A buyer who has paid for a
			// bundle and is told only "Manage" and "Browse" has to go and look
			// for what they bought; this puts it in front of them with a link
			// straight into each course. Skipped entirely for a single-course
			// purchase (the primary CTA already is that course) and for a plan
			// that bundles nothing.
			if ( $is_plan_purchase && ! empty( $included_courses ) ) :
				?>
				<section class="lrn-thank-you__included" aria-labelledby="lrn-thank-you-included-title">
					<h2 id="lrn-thank-you-included-title" class="lrn-thank-you__included-title">
						<i data-lucide="graduation-cap" aria-hidden="true"></i>
						<?php esc_html_e( 'Courses included in your membership', 'learnomy' ); ?>
					</h2>
					<ul class="lrn-thank-you__included-list">
						<?php foreach ( $included_courses as $lrn_included_course ) : ?>
							<li class="lrn-thank-you__included-item">
								<span class="lrn-thank-you__included-name"><?php echo esc_html( $lrn_included_course->title ?? '' ); ?></span>
								<a
									href="<?php echo esc_url( \Learnomy\route_url( 'course', array( 'slug' => (string) ( $lrn_included_course->slug ?? '' ) ) ) ); ?>"
									class="lrn-btn lrn-btn--ghost lrn-btn--sm"
								>
									<?php esc_html_e( 'Start', 'learnomy' ); ?>
									<i data-lucide="arrow-right" aria-hidden="true"></i>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
					<?php if ( $included_total > count( $included_courses ) ) : ?>
						<p class="lrn-thank-you__included-more">
							<a href="<?php echo esc_url( \Learnomy\route_url( 'account-courses' ) ); ?>">
								<?php
								$lrn_included_remaining = $included_total - count( $included_courses );
								printf(
									/* translators: %d: how many further courses the plan includes */
									esc_html( _n( '%d more course in this plan', '%d more courses in this plan', $lrn_included_remaining, 'learnomy' ) ),
									esc_html( number_format_i18n( $lrn_included_remaining ) )
								);
								?>
							</a>
						</p>
					<?php endif; ?>
				</section>
			<?php endif; ?>

			<div class="lrn-thank-you__actions">
				<?php if ( $primary_cta_url && $primary_cta_label ) : ?>
					<a href="<?php echo esc_url( $primary_cta_url ); ?>" class="lrn-btn lrn-btn--primary lrn-btn--lg lrn-btn--wrap">
						<?php echo esc_html( $primary_cta_label ); ?>
					</a>
				<?php endif; ?>
				<?php if ( $secondary_cta_url && $secondary_cta_label ) : ?>
					<a href="<?php echo esc_url( $secondary_cta_url ); ?>" class="lrn-btn lrn-btn--secondary lrn-btn--lg lrn-btn--wrap">
						<?php echo esc_html( $secondary_cta_label ); ?>
					</a>
				<?php endif; ?>
			</div>
		</section>

	<?php elseif ( 'pending' === $state ) : ?>

		<?php // Auto-refresh until the webhook lands. Pure HTML so it works with JS off. ?>
		<?php if ( ! $polling_exhausted ) : ?>
			<meta http-equiv="refresh" content="<?php echo esc_attr( (string) $polling_seconds ); ?>; url=<?php echo esc_url( $polling_next_url ); ?>">
		<?php endif; ?>

		<section class="lrn-thank-you__card lrn-thank-you__card--pending lrn-card" role="region" aria-busy="<?php echo $polling_exhausted ? 'false' : 'true'; ?>">
			<div class="lrn-thank-you__icon lrn-thank-you__icon--pending" aria-hidden="true">
				<?php if ( $polling_exhausted ) : ?>
					<i data-lucide="clock"></i>
				<?php else : ?>
					<i data-lucide="loader-circle" class="lrn-spin"></i>
				<?php endif; ?>
			</div>
			<h1 id="lrn-thank-you-heading" class="lrn-thank-you__heading">
				<?php if ( $polling_exhausted ) : ?>
					<?php esc_html_e( 'Your payment went through', 'learnomy' ); ?>
				<?php else : ?>
					<?php esc_html_e( 'Setting up your enrollment', 'learnomy' ); ?>
				<?php endif; ?>
			</h1>
			<p class="lrn-thank-you__lede">
				<?php if ( $polling_exhausted ) : ?>
					<?php esc_html_e( 'Access is taking longer than usual to appear. Nothing further is needed from you, and you have not been charged twice.', 'learnomy' ); ?>
				<?php else : ?>
					<?php esc_html_e( 'Your payment is being confirmed. This page will refresh automatically.', 'learnomy' ); ?>
				<?php endif; ?>
			</p>

			<?php if ( $item_title ) : ?>
				<p class="lrn-thank-you__pending-item">
					<?php
					/* translators: %s: course or plan title */
					printf( esc_html__( 'Purchase: %s', 'learnomy' ), '<strong>' . esc_html( $item_title ) . '</strong>' );
					?>
				</p>
			<?php endif; ?>

			<?php if ( $polling_exhausted ) : ?>
				<p class="lrn-thank-you__pending-note">
					<?php // Wrapped so the sentence is ONE flex item — see checkout-thank-you.css. ?>
					<span class="lrn-thank-you__note-text">
						<?php if ( $email_sent_to ) : ?>
							<?php
							printf(
								/* translators: %s: buyer's email address */
								esc_html__( 'We will email %s the moment access is ready. If you have not heard within a few hours, contact the site owner and quote your payment reference.', 'learnomy' ),
								'<strong>' . esc_html( $email_sent_to ) . '</strong>'
							);
							?>
						<?php else : ?>
							<?php esc_html_e( 'We will email you the moment access is ready. If you have not heard within a few hours, contact the site owner and quote your payment reference.', 'learnomy' ); ?>
						<?php endif; ?>
					</span>
				</p>
				<?php if ( $session_id ) : ?>
					<p class="lrn-thank-you__pending-reference">
						<?php
						printf(
							/* translators: %s: payment reference from the provider */
							esc_html__( 'Payment reference: %s', 'learnomy' ),
							'<code>' . esc_html( $session_id ) . '</code>'
						);
						?>
					</p>
				<?php endif; ?>
			<?php elseif ( $email_sent_to ) : ?>
				<p class="lrn-thank-you__pending-note">
					<?php // Wrapped so the sentence is ONE flex item — see checkout-thank-you.css. ?>
					<span class="lrn-thank-you__note-text">
						<?php
						printf(
							/* translators: %s: buyer's email address */
							esc_html__( 'A confirmation will be emailed to %s as soon as the payment clears. You can close this window any time.', 'learnomy' ),
							'<strong>' . esc_html( $email_sent_to ) . '</strong>'
						);
						?>
					</span>
				</p>
			<?php else : ?>
				<p class="lrn-thank-you__pending-note">
					<?php esc_html_e( 'A confirmation will be emailed as soon as the payment clears. You can close this window any time.', 'learnomy' ); ?>
				</p>
			<?php endif; ?>

			<div class="lrn-thank-you__actions">
				<a href="<?php echo esc_url( \Learnomy\route_url( 'student-dashboard' ) ); ?>" class="lrn-btn lrn-btn--secondary lrn-btn--lg">
					<?php esc_html_e( 'Go to My Courses', 'learnomy' ); ?>
				</a>
			</div>
		</section>

	<?php else : // failed ?>

		<section class="lrn-thank-you__card lrn-thank-you__card--failed lrn-card" role="region">
			<div class="lrn-thank-you__icon lrn-thank-you__icon--failed" aria-hidden="true">
				<i data-lucide="circle-x"></i>
			</div>
			<h1 id="lrn-thank-you-heading" class="lrn-thank-you__heading">
				<?php esc_html_e( 'Your payment did not go through', 'learnomy' ); ?>
			</h1>
			<p class="lrn-thank-you__lede">
				<?php esc_html_e( 'No charge was made. You can try again with the same or a different payment method.', 'learnomy' ); ?>
			</p>

			<?php if ( $item_title ) : ?>
				<p class="lrn-thank-you__failed-item">
					<?php
					/* translators: %s: course or plan title */
					printf( esc_html__( 'Purchase: %s', 'learnomy' ), '<strong>' . esc_html( $item_title ) . '</strong>' );
					?>
				</p>
			<?php endif; ?>

			<div class="lrn-thank-you__actions">
				<a href="<?php echo esc_url( $retry_url ); ?>" class="lrn-btn lrn-btn--primary lrn-btn--lg">
					<?php esc_html_e( 'Try payment again', 'learnomy' ); ?>
				</a>
				<?php if ( $secondary_cta_url && $secondary_cta_label ) : ?>
					<a href="<?php echo esc_url( $secondary_cta_url ); ?>" class="lrn-btn lrn-btn--secondary lrn-btn--lg lrn-btn--wrap">
						<?php echo esc_html( $secondary_cta_label ); ?>
					</a>
				<?php endif; ?>
			</div>
		</section>

	<?php endif; ?>

	<?php
	/**
	 * Fires after the thank-you page has rendered. Pro analytics modules
	 * use this to ping conversion pixels, attach session metadata, or
	 * trigger drip sequences. Free does not listen.
	 *
	 * @since 2.2.0
	 *
	 * @param object|null $transaction Local transaction row (null when webhook race).
	 * @param int         $user_id     Current user.
	 */
	do_action( 'learnomy_thank_you_rendered', $transaction, get_current_user_id() );
	?>
</main>
