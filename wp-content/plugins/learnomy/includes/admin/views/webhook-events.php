<?php
/**
 * Webhook events admin view — audit log + replay surface.
 *
 * This page is the production-stability anchor for webhook debugging. Each
 * row shows one inbound provider webhook delivery. Admins can view the raw
 * payload in a modal and replay failed events without waiting for the
 * provider's retry schedule.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Webhook_Event;
use Learnomy\Admin\UX;

// phpcs:disable WordPress.Security.NonceVerification.Recommended
$paged           = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
$provider_filter = isset( $_GET['provider'] ) ? sanitize_key( $_GET['provider'] ) : '';
$outcome_filter  = isset( $_GET['outcome'] ) ? sanitize_key( $_GET['outcome'] ) : '';
// phpcs:enable

$per_page = 50;
$offset   = ( $paged - 1 ) * $per_page;

// Validate enum values to prevent passing garbage to the model.
$allowed_providers = [ 'stripe', 'paypal' ];
$allowed_outcomes  = [ 'handled', 'duplicate', 'rejected', 'error', 'pending' ];

if ( ! in_array( $provider_filter, $allowed_providers, true ) ) {
	$provider_filter = '';
}
if ( ! in_array( $outcome_filter, $allowed_outcomes, true ) ) {
	$outcome_filter = '';
}

// Guard gracefully when the table does not exist yet (migration pending).
$table_ready = Webhook_Event::table_exists();
$events      = $table_ready ? Webhook_Event::recent( $provider_filter, $outcome_filter, $per_page, $offset ) : [];
$total       = $table_ready ? Webhook_Event::count_filtered( $provider_filter, $outcome_filter ) : 0;
$total_pages = (int) ceil( $total / $per_page );

// Outcome and provider labels used by UX::status_badge() below.
// The variant map is registered via the learnomy_admin_status_variants filter
// (see class-ux.php); handled/duplicate/rejected/error/pending and stripe/paypal
// all fall through to the canonical map or the 'default' fallback.
$lrn_outcome_labels  = array(
	'handled'   => __( 'Handled', 'learnomy' ),
	'duplicate' => __( 'Duplicate', 'learnomy' ),
	'rejected'  => __( 'Rejected', 'learnomy' ),
	'error'     => __( 'Error', 'learnomy' ),
	'pending'   => __( 'Pending', 'learnomy' ),
);
$lrn_provider_labels = array(
	'stripe' => 'Stripe',
	'paypal' => 'PayPal',
);
?>
<div class="wrap learnomy-admin">
	<div class="lrn-page-header">
		<div class="lrn-page-header__left">
			<h1 class="lrn-page-header__title">
				<i data-lucide="zap" class="lrn-page-header__icon"></i>
				<?php
				// Heading = this page's own sidebar label, so a rename moves both
				// (Basecamp 10184393141). The fallback MATCHES that label rather
				// than restating the old heading: it only applies when the page has
				// no sidebar row, and a stale literal here made source reads report
				// the bug as unfixed even though the rendered H1 was correct.
				echo esc_html( \Learnomy\Admin\UX::page_title( __( 'Payment Webhook Log', 'learnomy' ) ) );
				?>
			</h1>
			<p class="lrn-page-header__subtitle">
				<?php esc_html_e( 'Every inbound Stripe and PayPal webhook delivery. View payloads and replay failed events.', 'learnomy' ); ?>
			</p>
		</div>
	</div>

	<hr class="wp-header-end">

	<?php if ( ! $table_ready ) : ?>
		<div class="lrn-notice lrn-notice--warning">
			<i data-lucide="alert-triangle"></i>
			<?php esc_html_e( 'The webhook events table is not ready. Run the database migration to enable this feature.', 'learnomy' ); ?>
		</div>
	<?php else : ?>

	<!-- Filter bar -->
	<form method="get" action="" class="lrn-filter-bar">
		<input type="hidden" name="page" value="learnomy-webhook-events">

		<div class="lrn-filter-bar__group">
			<label for="lrn-wh-provider-filter" class="screen-reader-text">
				<?php esc_html_e( 'Filter by provider', 'learnomy' ); ?>
			</label>
			<select id="lrn-wh-provider-filter" name="provider" class="lrn-filter-select">
				<option value=""><?php esc_html_e( 'All providers', 'learnomy' ); ?></option>
				<option value="stripe" <?php selected( $provider_filter, 'stripe' ); ?>>
					<?php esc_html_e( 'Stripe', 'learnomy' ); ?>
				</option>
				<option value="paypal" <?php selected( $provider_filter, 'paypal' ); ?>>
					<?php esc_html_e( 'PayPal', 'learnomy' ); ?>
				</option>
			</select>

			<label for="lrn-wh-outcome-filter" class="screen-reader-text">
				<?php esc_html_e( 'Filter by outcome', 'learnomy' ); ?>
			</label>
			<select id="lrn-wh-outcome-filter" name="outcome" class="lrn-filter-select">
				<option value=""><?php esc_html_e( 'All outcomes', 'learnomy' ); ?></option>
				<option value="handled" <?php selected( $outcome_filter, 'handled' ); ?>>
					<?php esc_html_e( 'Handled', 'learnomy' ); ?>
				</option>
				<option value="duplicate" <?php selected( $outcome_filter, 'duplicate' ); ?>>
					<?php esc_html_e( 'Duplicate', 'learnomy' ); ?>
				</option>
				<option value="rejected" <?php selected( $outcome_filter, 'rejected' ); ?>>
					<?php esc_html_e( 'Rejected', 'learnomy' ); ?>
				</option>
				<option value="error" <?php selected( $outcome_filter, 'error' ); ?>>
					<?php esc_html_e( 'Error', 'learnomy' ); ?>
				</option>
				<option value="pending" <?php selected( $outcome_filter, 'pending' ); ?>>
					<?php esc_html_e( 'Pending', 'learnomy' ); ?>
				</option>
			</select>

			<button type="submit" class="lrn-admin-btn">
				<?php esc_html_e( 'Filter', 'learnomy' ); ?>
			</button>

			<?php if ( $provider_filter || $outcome_filter ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-webhook-events' ) ); ?>" class="lrn-admin-btn lrn-admin-btn--ghost">
					<?php esc_html_e( 'Clear', 'learnomy' ); ?>
				</a>
			<?php endif; ?>
		</div>
	</form>

	<!-- Events table -->
	<div class="lrn-admin-table-wrap">
		<table class="lrn-admin-table" id="lrn-webhook-events-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Provider', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Event Type', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Event ID', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Processed At', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Outcome', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Retries', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'learnomy' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $events ) ) : ?>
					<tr>
						<td colspan="7" class="lrn-no-items">
							<?php
							$lrn_empty_state = [
								'icon'        => 'zap',
								'title'       => __( 'No webhook events yet', 'learnomy' ),
								'description' => __( 'Inbound Stripe and PayPal webhook deliveries appear here after the first payment activity.', 'learnomy' ),
							];
							include LEARNOMY_DIR . 'includes/admin/views/partials/empty-state.php';
							?>
						</td>
					</tr>
				<?php else : ?>
					<?php foreach ( $events as $event ) : ?>
						<?php
						$lrn_outcome   = (string) ( $event->outcome ?? 'pending' );
						$lrn_provider  = (string) ( $event->provider ?? 'unknown' );
						$full_id       = $event->event_id ?? '';
						$short_id      = strlen( $full_id ) > 24 ? substr( $full_id, 0, 24 ) . '…' : $full_id;
						$processed     = $event->processed_at
							? date_i18n(
								get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
								strtotime( $event->processed_at )
							)
							: '—';
						$retry         = (int) ( $event->retry_count ?? 0 );
						$prov_label    = $lrn_provider_labels[ $lrn_provider ] ?? ucfirst( $lrn_provider );
						$outcome_label = $lrn_outcome_labels[ $lrn_outcome ] ?? ucfirst( $lrn_outcome );
						?>
						<tr data-row-id="<?php echo esc_attr( (int) $event->id ); ?>">
							<td>
								<?php echo UX::status_badge( $lrn_provider, $prov_label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</td>
							<td>
								<code class="lrn-wh-event-type"><?php echo esc_html( $event->event_type ?? '—' ); ?></code>
							</td>
							<td>
								<span
									class="lrn-wh-event-id"
									title="<?php echo esc_attr( $full_id ); ?>"
								><?php echo esc_html( $short_id ); ?></span>
							</td>
							<td><?php echo esc_html( $processed ); ?></td>
							<td class="lrn-wh-outcome-cell">
								<?php echo UX::status_badge( $lrn_outcome, $outcome_label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</td>
							<td>
								<?php if ( $retry > 0 ) : ?>
									<span class="lrn-badge lrn-badge--neutral">
										<?php echo esc_html( $retry ); ?>
									</span>
								<?php else : ?>
									<span class="lrn-text-muted">0</span>
								<?php endif; ?>
							</td>
							<td class="lrn-wh-actions">
								<button
									type="button"
									class="lrn-admin-btn lrn-admin-btn--sm lrn-wh-view-payload"
									data-event-id="<?php echo esc_attr( (int) $event->id ); ?>"
									aria-label="<?php esc_attr_e( 'View payload', 'learnomy' ); ?>"
								>
									<i data-lucide="eye" aria-hidden="true"></i>
									<?php esc_html_e( 'Payload', 'learnomy' ); ?>
								</button>
								<button
									type="button"
									class="lrn-admin-btn lrn-admin-btn--sm lrn-wh-replay"
									data-event-id="<?php echo esc_attr( (int) $event->id ); ?>"
									aria-label="<?php esc_attr_e( 'Replay this event', 'learnomy' ); ?>"
								>
									<i data-lucide="rotate-ccw" aria-hidden="true"></i>
									<?php esc_html_e( 'Replay', 'learnomy' ); ?>
								</button>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>

		<?php
		$lrn_pagination = [
			'current'  => (int) $paged,
			'total'    => (int) $total_pages,
			'results'  => (int) $total,
			'per_page' => (int) $per_page,
		];
		require LEARNOMY_DIR . 'includes/admin/views/partials/pagination.php';
		?>

	<?php endif; // table_ready ?>
</div>

<!-- Payload modal — accessible, keyboard-dismissible. -->
<div
	id="lrn-wh-payload-modal"
	class="lrn-modal lrn-modal--hidden"
	role="dialog"
	aria-modal="true"
	aria-labelledby="lrn-wh-payload-modal-title"
	hidden
>
	<div class="lrn-modal__backdrop" data-lrn-modal-close aria-hidden="true"></div>
	<div class="lrn-modal__box lrn-modal__box--lg">
		<div class="lrn-modal__header">
			<h2 id="lrn-wh-payload-modal-title" class="lrn-modal__title">
				<?php esc_html_e( 'Webhook Payload', 'learnomy' ); ?>
			</h2>
			<button
				type="button"
				class="lrn-modal__close"
				data-lrn-modal-close
				aria-label="<?php esc_attr_e( 'Close', 'learnomy' ); ?>"
			>
				<i data-lucide="x" aria-hidden="true"></i>
			</button>
		</div>
		<div class="lrn-modal__body">
			<p id="lrn-wh-payload-meta" class="lrn-wh-payload-meta"></p>
			<pre
				class="lrn-wh-payload-pre"
				id="lrn-wh-payload-pre"
				tabindex="0"
				aria-label="<?php esc_attr_e( 'Raw JSON payload', 'learnomy' ); ?>"
			></pre>
			<div class="lrn-wh-error-detail" id="lrn-wh-error-detail" hidden>
				<strong><?php esc_html_e( 'Error:', 'learnomy' ); ?></strong>
				<span id="lrn-wh-error-text"></span>
			</div>
		</div>
		<div class="lrn-modal__footer">
			<button type="button" class="lrn-admin-btn" data-lrn-modal-close>
				<?php esc_html_e( 'Close', 'learnomy' ); ?>
			</button>
		</div>
	</div>
</div>

