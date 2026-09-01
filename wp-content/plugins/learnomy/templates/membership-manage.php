<?php
/**
 * Membership management — /account/membership/
 *
 * Rendered by `Template_Loader::render()` which first resolves data via
 * `Membership_Manage_View_Data`. The template performs zero DB work and
 * zero service calls — everything comes from the resolved `$view_data`.
 *
 * Variables available (extracted from `$view_data`):
 *   $active_subscriptions array<object> Live subscriptions for the user.
 *   $subscription_views   array         Flat array of { subscription, plan,
 *                                       courses, invoices, next_payment } —
 *                                       one entry per active subscription.
 *   $all_plans            array<object> Active plans for upgrade options.
 *   $current_user         WP_User      Current user object.
 *   $currency_symbol      string       Currency glyph, e.g. "$".
 *   $can_change_plan      bool         Pro-gated plan-switch ability.
 *   $pricing_url          string       /membership/ URL.
 *
 * Auth redirect is handled by Template_Loader (route is in $protected_routes).
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.PHP.DontExtract.extract_extract -- locals come from Template_Loader extract().

$active_subscriptions = isset( $active_subscriptions ) && is_array( $active_subscriptions ) ? $active_subscriptions : array();
$subscription_views   = isset( $subscription_views ) && is_array( $subscription_views ) ? $subscription_views : array();
$all_plans            = isset( $all_plans ) && is_array( $all_plans ) ? $all_plans : array();
$currency_symbol      = isset( $currency_symbol ) ? (string) $currency_symbol : '$';
$can_change_plan      = ! empty( $can_change_plan );
$pricing_url          = isset( $pricing_url ) ? (string) $pricing_url : \Learnomy\route_url( 'membership-pricing' );
$checkout_url         = isset( $checkout_url ) ? (string) $checkout_url : \Learnomy\route_url( 'enroll' );
$is_logged_in         = true; // Protected route — Template_Loader already verified.
$show_toggle          = false;
$current_plan_ids     = array();
foreach ( $active_subscriptions as $sub ) {
	$current_plan_ids[] = (int) $sub->plan_id;
}
$GLOBALS['lrn_viewer_has_any_sub'] = ! empty( $current_plan_ids );

$date_format = get_option( 'date_format' );

?>
<main
	class="lrn-container lrn-membership-manage"
	id="lrn-membership-manage"
	data-wp-interactive="learnomy/membership"
>

	<?php
	$breadcrumb_items = \Learnomy\account_breadcrumb( __( 'Manage Membership', 'learnomy' ) );
	require \Learnomy\template_path( 'partials/breadcrumb.php' );
	?>

	<div class="lrn-account-layout">
		<?php
		$account_nav_active = 'membership-manage';
		require \Learnomy\template_path( 'partials/account-nav.php' );
		?>
		<div class="lrn-account-layout__content">

	<?php
	$account_page = array(
		'icon'     => 'repeat',
		'title'    => __( 'Manage Membership', 'learnomy' ),
		'subtitle' => __( 'Your plan, billing, and the courses it unlocks.', 'learnomy' ),
	);
	require \Learnomy\template_path( 'partials/account-page-header.php' );
	?>

	<?php if ( empty( $subscription_views ) ) : ?>

		<div class="lrn-empty-state lrn-card">
			<i data-lucide="star" class="lrn-empty-state__icon" aria-hidden="true"></i>
			<h2 class="lrn-empty-state__title">
				<?php esc_html_e( "You're not a member yet", 'learnomy' ); ?>
			</h2>
			<p class="lrn-empty-state__desc">
				<?php esc_html_e( 'Join a membership plan to unlock every course on the platform.', 'learnomy' ); ?>
			</p>
			<a href="<?php echo esc_url( $pricing_url ); ?>" class="lrn-btn lrn-btn--primary">
				<?php esc_html_e( 'View Plans', 'learnomy' ); ?>
			</a>
		</div>

	<?php else : ?>

		<?php
		foreach ( $subscription_views as $view ) :
			$sub                 = $view['subscription'];
			$plan                = $view['plan'];
			$courses             = isset( $view['courses'] ) && is_array( $view['courses'] ) ? $view['courses'] : array();
			$courses_total       = isset( $view['courses_total'] ) ? (int) $view['courses_total'] : count( $courses );
			$invoices            = isset( $view['invoices'] ) && is_array( $view['invoices'] ) ? $view['invoices'] : array();
			$next_payment        = isset( $view['next_payment'] ) ? (float) $view['next_payment'] : 0.0;
			$update_payment      = isset( $view['update_payment'] ) && is_array( $view['update_payment'] ) ? $view['update_payment'] : array();
			$update_payment_url  = (string) ( $update_payment['url'] ?? '' );
			$update_payment_hint = (string) ( $update_payment['hint'] ?? '' );

			$sub_id            = (int) $sub->id;
			$status            = (string) ( $sub->status ?? 'active' );
			$cancel_eop        = ! empty( $sub->cancel_at_period_end );
			$period_start      = ! empty( $sub->current_period_start )
				? date_i18n( $date_format, strtotime( (string) $sub->current_period_start ) )
				: '—';
			$period_end        = ! empty( $sub->current_period_end )
				? date_i18n( $date_format, strtotime( (string) $sub->current_period_end ) )
				: '—';
			$trial_end_display = ! empty( $sub->trial_ends_at )
				? date_i18n( $date_format, strtotime( (string) $sub->trial_ends_at ) )
				: '';

			$badge_class = 'lrn-badge lrn-badge--green';
			if ( 'trialing' === $status ) {
				$badge_class = 'lrn-badge lrn-badge--blue';
			} elseif ( 'past_due' === $status ) {
				$badge_class = 'lrn-badge lrn-badge--red';
			} elseif ( $cancel_eop ) {
				$badge_class = 'lrn-badge lrn-badge--amber';
			}

			$status_label = \Learnomy\Services\Membership_Service::status_label( $sub );

			$next_payment_display = \Learnomy\format_price( $next_payment );
			?>

			<section
				class="lrn-membership-hero"
				data-wp-context='<?php echo esc_attr( wp_json_encode( array( 'subscriptionId' => $sub_id ) ) ); ?>'
				aria-label="<?php esc_attr_e( 'Your current plan', 'learnomy' ); ?>"
			>
				<div class="lrn-membership-hero__plan">
					<h2 class="lrn-membership-hero__plan-name">
						<?php echo esc_html( (string) ( $plan->title ?? '' ) ); ?>
						<span class="<?php echo esc_attr( $badge_class ); ?>">
							<?php echo esc_html( $status_label ); ?>
						</span>
					</h2>

					<dl class="lrn-membership-hero__meta">
						<div class="lrn-membership-hero__meta-item">
							<dt class="lrn-membership-hero__meta-label">
								<?php esc_html_e( 'Current period', 'learnomy' ); ?>
							</dt>
							<dd class="lrn-membership-hero__meta-value">
								<?php
								/* translators: 1: period start date, 2: period end date */
								echo esc_html( sprintf( __( '%1$s - %2$s', 'learnomy' ), $period_start, $period_end ) );
								?>
							</dd>
						</div>

						<div class="lrn-membership-hero__meta-item">
							<dt class="lrn-membership-hero__meta-label">
								<?php
								echo $cancel_eop
									? esc_html__( 'Ends on', 'learnomy' )
									: esc_html__( 'Renews on', 'learnomy' );
								?>
							</dt>
							<dd class="lrn-membership-hero__meta-value">
								<?php echo esc_html( $period_end ); ?>
							</dd>
						</div>

						<?php if ( ! $cancel_eop && $next_payment > 0 && 'past_due' !== $status ) : ?>
							<div class="lrn-membership-hero__meta-item">
								<dt class="lrn-membership-hero__meta-label">
									<?php esc_html_e( 'Next payment', 'learnomy' ); ?>
								</dt>
								<dd class="lrn-membership-hero__meta-value lrn-membership-hero__amount">
									<?php echo esc_html( $next_payment_display ); ?>
								</dd>
							</div>
						<?php endif; ?>

						<?php if ( ! empty( $sub->payment_provider ) ) : ?>
							<div class="lrn-membership-hero__meta-item">
								<dt class="lrn-membership-hero__meta-label">
									<?php esc_html_e( 'Billed via', 'learnomy' ); ?>
								</dt>
								<dd class="lrn-membership-hero__meta-value">
									<?php echo esc_html( ucfirst( (string) $sub->payment_provider ) ); ?>
								</dd>
							</div>
						<?php endif; ?>
					</dl>
				</div>
			</section>

			<?php
			/*
			------------------------------------------------------------
			 * Status banners (trial / past_due / cancel_at_period_end)
			 * ------------------------------------------------------------ */
			if ( 'trialing' === $status && '' !== $trial_end_display ) :
				?>
				<div class="lrn-membership-banner lrn-membership-banner--info" role="status">
					<i data-lucide="clock" aria-hidden="true"></i>
					<div class="lrn-membership-banner__body">
						<p>
							<?php
							printf(
								/* translators: 1: trial end date, 2: amount charged after trial, e.g. "$19.99". */
								esc_html__( 'Your trial ends on %1$s. You\'ll be charged %2$s on that date.', 'learnomy' ),
								esc_html( $trial_end_display ),
								esc_html( $next_payment_display )
							);
							?>
						</p>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( 'past_due' === $status ) : ?>
				<div class="lrn-membership-banner lrn-membership-banner--danger" role="alert">
					<i data-lucide="alert-triangle" aria-hidden="true"></i>
					<div class="lrn-membership-banner__body">
						<p><strong><?php esc_html_e( 'Payment failed', 'learnomy' ); ?></strong>
							<?php esc_html_e( 'Update your payment method to keep access.', 'learnomy' ); ?>
						</p>
						<?php if ( '' !== $update_payment_url ) : ?>
							<div>
								<a class="lrn-btn lrn-btn--primary lrn-btn--sm" href="<?php echo esc_url( $update_payment_url ); ?>">
									<?php esc_html_e( 'Update payment method', 'learnomy' ); ?>
								</a>
							</div>
						<?php endif; ?>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( $cancel_eop && 'cancelled' !== $status ) : ?>
				<div class="lrn-membership-banner lrn-membership-banner--warning" role="status">
					<i data-lucide="alert-circle" aria-hidden="true"></i>
					<div class="lrn-membership-banner__body">
						<p>
							<?php
							printf(
								/* translators: %s: date the subscription will end. */
								esc_html__( 'Your subscription ends on %s.', 'learnomy' ),
								esc_html( $period_end )
							);
							?>
						</p>
						<div>
							<button
								type="button"
								class="lrn-btn lrn-btn--primary lrn-btn--sm"
								data-wp-on--click="actions.resumeSubscription"
								data-wp-context='<?php echo esc_attr( wp_json_encode( array( 'subscriptionId' => $sub_id ) ) ); ?>'
								data-wp-bind--aria-busy="state.isResumeBusy"
								data-wp-class--is-busy="state.isResumeBusy"
								data-wp-bind--disabled="state.isResumeBusy"
							>
								<?php esc_html_e( 'Resume subscription', 'learnomy' ); ?>
							</button>
						</div>
					</div>
				</div>
			<?php endif; ?>

			<?php
			/*
			------------------------------------------------------------
			 * Action buttons
			 * ------------------------------------------------------------ */
			$default_actions = array();

			if ( ! $cancel_eop && 'cancelled' !== $status ) {
				$default_actions['cancel'] = array(
					'label'  => __( 'Cancel subscription', 'learnomy' ),
					'class'  => 'lrn-btn lrn-btn--ghost lrn-btn--danger',
					'action' => 'cancelSubscription',
				);
			}

			// Route the member to the gateway's hosted surface (computed in the
			// view-data per provider). Only shown when we have a real URL — never
			// a dead button. [card 10062795906]
			if ( '' !== $update_payment_url ) {
				$default_actions['update_payment'] = array(
					'label' => __( 'Update payment method', 'learnomy' ),
					'class' => 'lrn-btn lrn-btn--ghost',
					'href'  => $update_payment_url,
					'hint'  => $update_payment_hint,
				);
			}

			// A real link to the pricing page — the member picks a different plan
			// there. Pro may replace this with in-place swap via the actions
			// filter below; the pricing link is the honest Free-side path.
			$default_actions['change_plan'] = array(
				'label' => __( 'Change plan', 'learnomy' ),
				'class' => 'lrn-btn lrn-btn--ghost',
				'href'  => $pricing_url,
				'hint'  => __( 'Browse plans and switch on the pricing page.', 'learnomy' ),
			);

			/**
			 * Filter the action buttons shown on the manage page. Pro adds
			 * pause / swap-plan / reactivate-now entries here.
			 *
			 * @param array  $actions Map of action-key => attrs.
			 * @param object $sub     Subscription row.
			 */
			$actions_list = (array) apply_filters( 'learnomy_membership_manage_actions', $default_actions, $sub );
			?>

			<div
				class="lrn-membership-actions"
				data-wp-context='<?php echo esc_attr( wp_json_encode( array( 'subscriptionId' => $sub_id ) ) ); ?>'
			>
				<?php
				foreach ( $actions_list as $key => $attrs ) :
					$label       = isset( $attrs['label'] ) ? (string) $attrs['label'] : '';
					$variant     = isset( $attrs['variant'] ) ? (string) $attrs['variant'] : '';
					$variant_map = array(
						'primary'   => 'lrn-btn lrn-btn--primary',
						'secondary' => 'lrn-btn lrn-btn--secondary',
						'danger'    => 'lrn-btn lrn-btn--danger',
						'ghost'     => 'lrn-btn lrn-btn--ghost',
					);
					$class       = isset( $attrs['class'] ) ? (string) $attrs['class'] : ( $variant_map[ $variant ] ?? 'lrn-btn lrn-btn--ghost' );
					$href        = isset( $attrs['href'] ) ? (string) $attrs['href'] : '';
					$action      = isset( $attrs['action'] ) ? (string) $attrs['action'] : '';
					$data_action = isset( $attrs['data_action'] ) ? (string) $attrs['data_action'] : '';
					$icon        = isset( $attrs['icon'] ) ? (string) $attrs['icon'] : '';
					$disabled    = ! empty( $attrs['disabled'] );
					$hint        = isset( $attrs['hint'] ) ? (string) $attrs['hint'] : '';

					if ( '' === $label ) {
						continue;
					}

					$icon_markup = ( '' !== $icon )
						? '<i data-lucide="' . esc_attr( $icon ) . '" aria-hidden="true"></i> '
						: '';

					if ( $disabled ) :
						/*
						 * J-02.12 — disabled buttons used to show their hint
						 * only via `title=` (hover-only on touch and invisible
						 * to AT). Render the hint as a sibling caption so the
						 * Pro-gate is discoverable without hovering.
						 */
						$disabled_hint_id = $hint
							? 'lrn-action-hint-' . substr( md5( $label . $sub_id ), 0, 8 )
							: '';
						?>
						<span class="lrn-membership-actions__disabled">
							<button
								type="button"
								class="<?php echo esc_attr( $class ); ?>"
								disabled
								aria-disabled="true"
								<?php if ( $disabled_hint_id ) : ?>
								aria-describedby="<?php echo esc_attr( $disabled_hint_id ); ?>"
								<?php endif; ?>
							><?php echo $icon_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup is hardcoded with esc_attr on the icon name. ?><?php echo esc_html( $label ); ?></button>
							<?php if ( $hint ) : ?>
								<small id="<?php echo esc_attr( $disabled_hint_id ); ?>" class="lrn-membership-actions__hint">
									<?php echo esc_html( $hint ); ?>
								</small>
							<?php endif; ?>
						</span>
						<?php
					elseif ( '' !== $action ) :
						/*
						 * For known IAPI actions that have a matching busy-state derived
						 * getter we wire aria-busy + is-busy + disabled so the button
						 * self-disables while the async action is in flight.
						 * Map: action name → state getter.
						 */
						$busy_state_map = array(
							'cancelSubscription' => 'state.isCancelBusy',
							'resumeSubscription' => 'state.isResumeBusy',
						);
						$busy_expr      = $busy_state_map[ $action ] ?? '';
						?>
						<button
							type="button"
							class="<?php echo esc_attr( $class ); ?>"
							data-wp-on--click="actions.<?php echo esc_attr( $action ); ?>"
							<?php if ( '' !== $busy_expr ) : ?>
							data-wp-bind--aria-busy="<?php echo esc_attr( $busy_expr ); ?>"
							data-wp-class--is-busy="<?php echo esc_attr( $busy_expr ); ?>"
							data-wp-bind--disabled="<?php echo esc_attr( $busy_expr ); ?>"
							<?php endif; ?>
						><?php echo $icon_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup is hardcoded with esc_attr on the icon name. ?><?php echo esc_html( $label ); ?></button>
					<?php elseif ( '' !== $data_action ) : ?>
						<button
							type="button"
							class="<?php echo esc_attr( $class ); ?>"
							data-action="<?php echo esc_attr( $data_action ); ?>"
							data-subscription-id="<?php echo esc_attr( (string) $sub_id ); ?>"
						><?php echo $icon_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup is hardcoded with esc_attr on the icon name. ?><?php echo esc_html( $label ); ?></button>
					<?php elseif ( '' !== $href ) : ?>
						<a
							href="<?php echo esc_url( $href ); ?>"
							class="<?php echo esc_attr( $class ); ?>"
						><?php echo $icon_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup is hardcoded with esc_attr on the icon name. ?><?php echo esc_html( $label ); ?></a>
					<?php else : ?>
						<?php
						// J-02.12 — same disabled-with-visible-hint shape as
						// the explicit `disabled: true` branch above. This
						// fallback fires for actions whose href filter returns
						// empty (e.g. Update payment method on Free, where
						// `learnomy_update_payment_url` filter is unset).
						$fallback_hint_id = $hint
							? 'lrn-action-hint-' . substr( md5( $label . $sub_id . 'fallback' ), 0, 8 )
							: '';
						?>
						<span class="lrn-membership-actions__disabled">
							<button
								type="button"
								class="<?php echo esc_attr( $class ); ?>"
								disabled
								aria-disabled="true"
								<?php if ( $fallback_hint_id ) : ?>
								aria-describedby="<?php echo esc_attr( $fallback_hint_id ); ?>"
								<?php endif; ?>
							><?php echo $icon_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup is hardcoded with esc_attr on the icon name. ?><?php echo esc_html( $label ); ?></button>
							<?php if ( $hint ) : ?>
								<small id="<?php echo esc_attr( $fallback_hint_id ); ?>" class="lrn-membership-actions__hint">
									<?php echo esc_html( $hint ); ?>
								</small>
							<?php endif; ?>
						</span>
						<?php
					endif;
				endforeach;
				?>
			</div>

			<?php
			/**
			 * Fires after the primary action row but before the invoices
			 * table. Pro uses this for pause-and-resume controls and the
			 * mid-cycle plan-change modal.
			 *
			 * @param object $sub Subscription row.
			 */
			do_action( 'learnomy_membership_manage_extra_sections', $sub );
			?>

			<!-- Courses included ----------------------------------------- -->
			<section class="lrn-membership-section" aria-labelledby="lrn-courses-<?php echo esc_attr( (string) $sub_id ); ?>">
				<h2 id="lrn-courses-<?php echo esc_attr( (string) $sub_id ); ?>" class="lrn-membership-section__title">
					<?php esc_html_e( 'Courses included', 'learnomy' ); ?>
				</h2>

				<?php if ( empty( $courses ) ) : ?>
					<p class="lrn-invoice-table__empty">
						<?php
						// Attributes the empty plan to nobody, on purpose. A
						// membership plan is a site-level commerce product created by
						// the owner, not by an instructor - and it can bundle courses
						// from several instructors, so "your instructor" was not even
						// singular. The member reading this is paying for the plan;
						// what they need is that courses are coming, not a guess at
						// who is adding them.
						esc_html_e( 'Courses are still being added to this plan. Check back shortly.', 'learnomy' );
						?>
					</p>
				<?php else : ?>
					<div class="lrn-membership-courses">
						<?php
						foreach ( $courses as $course ) :
							$course_url = \Learnomy\route_url( 'course', array( 'slug' => (string) ( $course->slug ?? '' ) ) );
							$thumb      = (string) ( $course->thumbnail_url ?? '' );
							?>
							<article class="lrn-membership-course">
								<?php if ( '' !== $thumb ) : ?>
									<div
										class="lrn-membership-course__thumb"
										style="background-image: url('<?php echo esc_url( $thumb ); ?>');"
										role="img"
										aria-label="<?php echo esc_attr( (string) ( $course->title ?? '' ) ); ?>"
									></div>
								<?php endif; ?>
								<h3 class="lrn-membership-course__title">
									<?php echo esc_html( (string) ( $course->title ?? '' ) ); ?>
								</h3>
								<a href="<?php echo esc_url( $course_url ); ?>" class="lrn-btn lrn-btn--ghost lrn-btn--sm">
									<?php esc_html_e( 'Go to Course', 'learnomy' ); ?>
								</a>
							</article>
						<?php endforeach; ?>
					</div>
					<?php if ( $courses_total > count( $courses ) ) : ?>
						<p class="lrn-membership-courses__more">
							<?php
							$lrn_more = $courses_total - count( $courses );
							echo esc_html(
								sprintf(
									/* translators: %d: number of additional courses included in the plan */
									_n(
										'and %d more course included with this plan',
										'and %d more courses included with this plan',
										$lrn_more,
										'learnomy'
									),
									$lrn_more
								)
							);
							?>
							<a href="<?php echo esc_url( \Learnomy\route_url( 'catalog' ) ); ?>"><?php esc_html_e( 'Browse the catalog', 'learnomy' ); ?></a>
						</p>
					<?php endif; ?>
				<?php endif; ?>
			</section>

			<!-- Invoices -------------------------------------------------- -->
			<section class="lrn-membership-section" aria-labelledby="lrn-invoices-<?php echo esc_attr( (string) $sub_id ); ?>">
				<h2 id="lrn-invoices-<?php echo esc_attr( (string) $sub_id ); ?>" class="lrn-membership-section__title">
					<?php esc_html_e( 'Invoices', 'learnomy' ); ?>
				</h2>

				<div class="lrn-invoice-table-wrap">
					<table class="lrn-invoice-table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Date', 'learnomy' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Amount', 'learnomy' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Status', 'learnomy' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Receipt', 'learnomy' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $invoices ) ) : ?>
								<tr>
									<td colspan="4" class="lrn-invoice-table__empty">
										<?php esc_html_e( 'No invoices yet. Your first charge will appear here after it clears.', 'learnomy' ); ?>
									</td>
								</tr>
							<?php else : ?>
								<?php
								foreach ( $invoices as $invoice ) {
									// $invoice + $currency_symbol are consumed by the partial.
									include \Learnomy\template_path( 'partials/membership-invoice-row.php' );
								}
								?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</section>

		<?php endforeach; ?>

		<?php
		/*
		------------------------------------------------------------
		 * Upgrade options — shows every OTHER plan as a card.
		 * ------------------------------------------------------------ */
		$other_plans = array();
		foreach ( $all_plans as $candidate ) {
			if ( ! in_array( (int) $candidate->id, $current_plan_ids, true ) ) {
				$other_plans[] = $candidate;
			}
		}
		if ( ! empty( $other_plans ) ) :
			?>
			<section class="lrn-membership-upgrade" aria-labelledby="lrn-upgrade-title" data-wp-interactive="learnomy/pricing">
				<h2 id="lrn-upgrade-title" class="lrn-membership-section__title">
					<?php esc_html_e( 'Other plans', 'learnomy' ); ?>
				</h2>
				<ul class="lrn-pricing-grid" role="list">
					<?php
					foreach ( $other_plans as $plan ) :
						$is_current = false;
						?>
						<li>
							<?php include \Learnomy\template_path( 'partials/membership-plan-card.php' ); ?>
						</li>
						<?php
					endforeach;
					?>
				</ul>
			</section>
		<?php endif; ?>

	<?php endif; ?>

		</div><!-- .lrn-account-layout__content -->
	</div><!-- .lrn-account-layout -->

</main>
<?php
unset( $GLOBALS['lrn_viewer_has_any_sub'] );
// phpcs:enable WordPress.PHP.DontExtract.extract_extract
