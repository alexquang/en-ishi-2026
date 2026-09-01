<?php
/**
 * Admin webhooks view.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Webhook;
use Learnomy\Services\Webhook_Events;

$webhooks = Webhook::where( array(), 'created_at', 'DESC' );

// Subscribable events come from the canonical catalogue that also drives the
// dispatch listeners, so the picker and the events that actually fire never
// drift. Extensions (e.g. Webhooks Pro) add their lifecycle events to it via
// the learnomy_webhook_event_map filter.
$available_events = Webhook_Events::labels();

/**
 * Human label for an event slug. Falls back to a prettified slug so legacy
 * rows (e.g. `course_enrolled`) still read cleanly instead of showing the raw
 * machine key in the list. Keeps the form and the table in agreement.
 *
 * @param string                $slug   Stored event slug.
 * @param array<string, string> $labels Slug → label map.
 */
$lrn_event_label = static function ( string $slug, array $labels ): string {
	if ( isset( $labels[ $slug ] ) ) {
		return $labels[ $slug ];
	}
	return ucwords( str_replace( array( '.', '_', '-' ), ' ', $slug ) );
};

$webhook_count = count( $webhooks );
$max_webhooks  = apply_filters( 'learnomy_max_webhooks', 3 );
$can_add       = $webhook_count < $max_webhooks;
// Pro lifts the cap to PHP_INT_MAX — treat any very high ceiling as unlimited
// so the usage badge never prints a meaningless 19-digit number.
$lrn_unlimited = $max_webhooks >= 1000;

// Incoming enrollment (Closed courses) recipe-panel data.
$lrn_incoming_url    = rest_url( 'learnomy/v1/webhooks/enrollment' );
$lrn_incoming_secret = (string) get_option( 'learnomy_incoming_webhook_secret', '' );
$lrn_closed_courses  = \Learnomy\Models\Course::where( array( 'access_type' => 'closed' ), 'title', 'ASC', 100 );
?>
<div class="wrap learnomy-admin">
	<div class="lrn-page-header">
		<div class="lrn-page-header__left">
			<h1 class="lrn-page-header__title">
				<i data-lucide="webhook" class="lrn-page-header__icon"></i>
				<?php
				// Heading = this page's own sidebar label, so a rename moves both
				// (Basecamp 10184393141). The fallback MATCHES that label rather
				// than restating the old heading: it only applies when the page has
				// no sidebar row, and a stale literal here made source reads report
				// the bug as unfixed even though the rendered H1 was correct.
				echo esc_html( \Learnomy\Admin\UX::page_title( __( 'Outgoing Webhooks', 'learnomy' ) ) );
				?>
			</h1>
			<p class="lrn-page-header__subtitle">
				<?php esc_html_e( 'Push Learnomy events to Zapier, your CRM, or any HTTPS endpoint. Each delivery is signed with the webhook secret.', 'learnomy' ); ?>
			</p>
		</div>
		<div class="lrn-page-header__actions">
			<span class="lrn-status-badge lrn-status-badge--<?php echo $can_add ? 'muted' : 'warn'; ?>">
				<?php
				if ( $lrn_unlimited ) {
					echo esc_html(
						sprintf(
							/* translators: %d: number of configured webhooks */
							_n( '%d webhook', '%d webhooks', (int) $webhook_count, 'learnomy' ),
							(int) $webhook_count
						)
					);
				} else {
					echo esc_html(
						sprintf(
							/* translators: 1: webhooks in use, 2: maximum allowed */
							__( '%1$d of %2$d used', 'learnomy' ),
							(int) $webhook_count,
							(int) $max_webhooks
						)
					);
				}
				?>
			</span>
		</div>
	</div>

	<?php
	// `wp-header-end` marker — admin notices render below this line.
	?>
	<hr class="wp-header-end">

	<div class="lrn-admin-card" id="lrn-incoming-enrollment">
		<div class="lrn-admin-card__head">
			<h2 class="lrn-admin-card__title"><?php esc_html_e( 'Incoming enrollment (external / closed courses)', 'learnomy' ); ?></h2>
			<p class="lrn-admin-card__subtitle"><?php esc_html_e( 'Sell a course on another platform (your own checkout, Gumroad, a CRM) and grant access here. Connect your store to these details, or send a test to confirm it works end to end.', 'learnomy' ); ?></p>
		</div>
		<div class="lrn-admin-card__body">

			<div class="lrn-form-row">
				<label for="lrn-incoming-url"><?php esc_html_e( 'Endpoint URL', 'learnomy' ); ?></label>
				<div class="lrn-secret-field">
					<input type="text" id="lrn-incoming-url" class="lrn-secret-field__input code" readonly
							value="<?php echo esc_attr( $lrn_incoming_url ); ?>" spellcheck="false">
					<button type="button" class="lrn-admin-btn lrn-admin-btn--ghost lrn-admin-btn--icon"
							data-webhook-action="copy-incoming-url"
							aria-label="<?php esc_attr_e( 'Copy endpoint URL', 'learnomy' ); ?>" title="<?php esc_attr_e( 'Copy', 'learnomy' ); ?>">
						<i data-lucide="copy" aria-hidden="true"></i>
					</button>
				</div>
				<p class="lrn-form-help"><?php esc_html_e( 'Your store POSTs a signed JSON body { "user_email": "…", "course_id": 123 } here to grant access. Add "action": "revoke" to pull access on a refund.', 'learnomy' ); ?></p>
			</div>

			<div class="lrn-form-row">
				<label for="lrn-incoming-secret"><?php esc_html_e( 'Signing secret', 'learnomy' ); ?></label>
				<div class="lrn-secret-field">
					<input type="password" id="lrn-incoming-secret" class="lrn-secret-field__input code" readonly
							value="<?php echo esc_attr( $lrn_incoming_secret ); ?>" spellcheck="false" autocomplete="off">
					<button type="button" class="lrn-admin-btn lrn-admin-btn--ghost lrn-admin-btn--icon"
							data-webhook-action="reveal-incoming-secret"
							aria-label="<?php esc_attr_e( 'Show / hide secret', 'learnomy' ); ?>" title="<?php esc_attr_e( 'Show / hide', 'learnomy' ); ?>">
						<i data-lucide="eye" aria-hidden="true"></i>
					</button>
					<button type="button" class="lrn-admin-btn lrn-admin-btn--ghost lrn-admin-btn--icon"
							data-webhook-action="copy-incoming-secret"
							aria-label="<?php esc_attr_e( 'Copy secret', 'learnomy' ); ?>" title="<?php esc_attr_e( 'Copy', 'learnomy' ); ?>">
						<i data-lucide="copy" aria-hidden="true"></i>
					</button>
					<button type="button" class="lrn-admin-btn lrn-admin-btn--ghost lrn-admin-btn--sm"
							data-webhook-action="regenerate-incoming-secret">
						<?php esc_html_e( 'Regenerate', 'learnomy' ); ?>
					</button>
				</div>
				<p class="lrn-form-help"><?php esc_html_e( 'Sign the exact JSON body with HMAC-SHA256 using this secret and send it as the X-Learnomy-Signature header. Regenerating breaks existing connections until you update the secret in them.', 'learnomy' ); ?></p>
			</div>

			<div class="lrn-form-row">
				<label><?php esc_html_e( 'Send a test enrollment', 'learnomy' ); ?></label>
				<?php if ( empty( $lrn_closed_courses ) ) : ?>
					<p class="lrn-form-help"><?php esc_html_e( 'No courses are set to external (closed) access yet. Set a course pricing to "Access granted externally" first, then come back to test.', 'learnomy' ); ?></p>
				<?php else : ?>
					<div class="lrn-form-inline">
						<select id="lrn-incoming-test-course" class="lrn-input">
							<?php foreach ( $lrn_closed_courses as $lrn_cc ) : ?>
								<option value="<?php echo esc_attr( (string) $lrn_cc->id ); ?>"><?php echo esc_html( (string) $lrn_cc->title ); ?></option>
							<?php endforeach; ?>
						</select>
						<input type="email" id="lrn-incoming-test-email" class="lrn-input"
								placeholder="buyer@example.com" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>">
						<button type="button" class="lrn-admin-btn lrn-admin-btn--primary" data-webhook-action="send-test-enrollment">
							<?php esc_html_e( 'Send test', 'learnomy' ); ?>
						</button>
					</div>
					<p id="lrn-incoming-test-result" class="lrn-form-help" role="status" hidden></p>
				<?php endif; ?>
			</div>

			<div class="lrn-form-row">
				<label><?php esc_html_e( 'Connect your store (Zapier / Make)', 'learnomy' ); ?></label>
				<ol class="lrn-form-help lrn-recipe-list">
					<li><?php esc_html_e( 'Trigger: "New sale" in your store (Gumroad, Stripe, WooCommerce, etc.).', 'learnomy' ); ?></li>
					<li><?php esc_html_e( 'Action: "Webhooks - POST" to the Endpoint URL above, JSON body { user_email, course_id }, header X-Learnomy-Signature set to the HMAC-SHA256 of the body with your secret.', 'learnomy' ); ?></li>
					<li><?php esc_html_e( 'Refund recipe: Trigger "Refund" -> same POST with "action": "revoke" to remove access automatically.', 'learnomy' ); ?></li>
				</ol>
			</div>

		</div>
	</div>

	<?php if ( $can_add ) : ?>
	<div class="lrn-admin-card">
		<div class="lrn-admin-card__head">
			<h2 class="lrn-admin-card__title" id="lrn-webhook-form-title"><?php esc_html_e( 'Add New Webhook', 'learnomy' ); ?></h2>
			<p class="lrn-admin-card__subtitle"><?php esc_html_e( 'Choose which events POST a signed JSON payload to your endpoint.', 'learnomy' ); ?></p>
		</div>
		<div class="lrn-admin-card__body">
		<form method="post" id="lrn-webhook-form" data-ajax-action="learnomy_create_webhook" class="lrn-form">

			<input type="hidden" name="webhook_id" id="lrn-webhook-id" value="">

			<div class="lrn-form-row">
				<label for="webhook-url"><?php esc_html_e( 'Delivery URL', 'learnomy' ); ?></label>
				<input type="url" id="webhook-url" name="url" class="large-text" required
						placeholder="https://example.com/webhook">
			</div>

			<div class="lrn-form-row">
				<div class="lrn-checkbox-group__head">
					<label><?php esc_html_e( 'Events to Send', 'learnomy' ); ?></label>
					<button type="button" class="lrn-link-btn" data-webhook-action="toggle-all">
						<?php esc_html_e( 'Select all', 'learnomy' ); ?>
					</button>
				</div>
				<?php
				// Group the (~29+) events by family — the slug prefix before the
				// dot (enrollment / payment / course / quiz / membership / …) — so a
				// long flat grid becomes scannable sub-sections, each with its own
				// select-all. `data-role="webhook-events"` stays on the outer wrap so
				// the global Select-all + the edit-mode checkbox restore still work.
				$lrn_event_groups = array();
				foreach ( $available_events as $event_slug => $event_label ) {
					$lrn_family                                     = strstr( (string) $event_slug, '.', true );
					$lrn_family                                     = false !== $lrn_family ? $lrn_family : 'other';
					$lrn_event_groups[ $lrn_family ][ $event_slug ] = $event_label;
				}

				// Translated titles for the known event families; unknown families
				// fall back to a humanised slug.
				$lrn_family_labels = array(
					'enrollment'  => __( 'Enrollment', 'learnomy' ),
					'payment'     => __( 'Payment', 'learnomy' ),
					'course'      => __( 'Course', 'learnomy' ),
					'quiz'        => __( 'Quiz', 'learnomy' ),
					'membership'  => __( 'Membership', 'learnomy' ),
					'certificate' => __( 'Certificate', 'learnomy' ),
					'review'      => __( 'Review', 'learnomy' ),
					'user'        => __( 'User', 'learnomy' ),
					'other'       => __( 'Other', 'learnomy' ),
				);
				?>
				<div class="lrn-webhook-events" data-role="webhook-events">
					<?php foreach ( $lrn_event_groups as $lrn_family => $lrn_family_events ) : ?>
						<div class="lrn-webhook-event-group" data-role="webhook-event-group">
							<div class="lrn-webhook-event-group__head">
								<span class="lrn-webhook-event-group__title"><?php echo esc_html( $lrn_family_labels[ $lrn_family ] ?? ucwords( str_replace( array( '_', '-' ), ' ', $lrn_family ) ) ); ?></span>
								<button type="button" class="lrn-link-btn lrn-link-btn--sm" data-webhook-action="toggle-group"><?php esc_html_e( 'Select all', 'learnomy' ); ?></button>
							</div>
							<div class="lrn-checkbox-group lrn-checkbox-group--grid">
								<?php foreach ( $lrn_family_events as $event_slug => $event_label ) : ?>
									<label class="lrn-checkbox-label">
										<input type="checkbox" name="events[]" value="<?php echo esc_attr( $event_slug ); ?>">
										<?php echo esc_html( $event_label ); ?>
									</label>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="lrn-form-row" id="lrn-webhook-secret-row">
				<label for="webhook-secret"><?php esc_html_e( 'Secret Key', 'learnomy' ); ?></label>
				<div class="lrn-secret-field">
					<input type="text" id="webhook-secret" name="secret" class="lrn-secret-field__input code"
							value="<?php echo esc_attr( wp_generate_password( 32, false ) ); ?>"
							spellcheck="false" autocomplete="off"
							placeholder="<?php esc_attr_e( 'Auto-generated', 'learnomy' ); ?>">
					<button type="button" class="lrn-admin-btn lrn-admin-btn--ghost lrn-admin-btn--icon"
							data-webhook-action="copy-secret"
							aria-label="<?php esc_attr_e( 'Copy secret key', 'learnomy' ); ?>"
							title="<?php esc_attr_e( 'Copy', 'learnomy' ); ?>">
						<i data-lucide="copy" aria-hidden="true"></i>
					</button>
					<button type="button" class="lrn-admin-btn lrn-admin-btn--ghost lrn-admin-btn--icon"
							data-webhook-action="regenerate-secret"
							aria-label="<?php esc_attr_e( 'Regenerate secret key', 'learnomy' ); ?>"
							title="<?php esc_attr_e( 'Regenerate', 'learnomy' ); ?>">
						<i data-lucide="refresh-cw" aria-hidden="true"></i>
					</button>
				</div>
				<p class="description"><?php esc_html_e( 'Signs every payload (HMAC-SHA256). Copy it now. For security it is not shown again after the webhook is saved.', 'learnomy' ); ?></p>
			</div>

			<div class="lrn-form-actions">
				<button type="submit" class="lrn-admin-btn lrn-admin-btn--primary" id="lrn-webhook-submit">
					<?php esc_html_e( 'Add Webhook', 'learnomy' ); ?>
				</button>
				<button type="button" class="lrn-admin-btn lrn-admin-btn--ghost" id="lrn-webhook-cancel" hidden>
					<?php esc_html_e( 'Cancel', 'learnomy' ); ?>
				</button>
			</div>
		</form>
		</div>
	</div>
		<?php
	else :
		// When the operator has hit the free webhook cap, point them at the
		// Pro Features catalog.
		$feature_slug  = 'webhooks-pro';
		$intro_message = sprintf(
			/* translators: %d: maximum number of free webhooks (3) */
			__( 'You have reached the %d-webhook limit included in Learnomy Free.', 'learnomy' ),
			(int) $max_webhooks
		);
		include \Learnomy\template_path( 'partials/admin-empty-state-pro.php' );
		unset( $feature_slug, $intro_message );
		?>
	<?php endif; ?>

	<div class="lrn-admin-table-wrap">
	<table class="lrn-admin-table lrn-webhook-table">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'URL', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Events', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Last Triggered', 'learnomy' ); ?></th>
				<th scope="col" class="lrn-col-actions"><?php esc_html_e( 'Actions', 'learnomy' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $webhooks ) ) : ?>
				<tr>
					<td colspan="5" class="lrn-no-items">
						<?php
						$lrn_empty_state = array(
							'icon'        => 'webhook',
							'title'       => __( 'No webhooks configured', 'learnomy' ),
							'description' => __( 'Webhooks fire on enrollment, completion, and other events. Add one to push events to Zapier, your CRM, or any HTTP endpoint.', 'learnomy' ),
						);
						include LEARNOMY_DIR . 'includes/admin/views/partials/empty-state.php';
						?>
					</td>
				</tr>
			<?php else : ?>
				<?php
				foreach ( $webhooks as $hook ) :
					$status       = $hook->status ?? 'active';
					$last_trigger = ! empty( $hook->last_triggered_at )
						? \Learnomy\date_label( $hook->last_triggered_at, '—', get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) )
						: __( 'Never', 'learnomy' );
					$hook_events  = ! empty( $hook->events_json ) ? (array) json_decode( $hook->events_json, true ) : array();
					$url_display  = strlen( $hook->url ) > 60 ? substr( $hook->url, 0, 57 ) . '…' : $hook->url;

					// The stored HTTP code is the only clue an owner has about WHY
					// an endpoint stopped working, so surface it beside the time.
					// 0 means the request never got an answer (DNS, TLS, timeout).
					$last_code = ( null === $hook->last_status_code || '' === (string) $hook->last_status_code )
						? ''
						: (int) $hook->last_status_code;
					if ( '' === $last_code ) {
						$last_code_label = '';
					} elseif ( 0 === $last_code ) {
						$last_code_label = __( 'No response', 'learnomy' );
					} else {
						/* translators: %d: HTTP status code returned by the endpoint. */
						$last_code_label = sprintf( __( 'HTTP %d', 'learnomy' ), $last_code );
					}
					?>
					<tr>
						<td class="lrn-webhook-url" title="<?php echo esc_attr( $hook->url ); ?>">
							<code><?php echo esc_html( $url_display ); ?></code>
						</td>
						<td>
							<?php if ( empty( $hook_events ) ) : ?>
								<span class="lrn-muted"><?php esc_html_e( 'None', 'learnomy' ); ?></span>
							<?php else : ?>
								<div class="lrn-event-badges">
									<?php foreach ( $hook_events as $evt ) : ?>
										<span class="lrn-event-badge" title="<?php echo esc_attr( $evt ); ?>">
											<?php echo esc_html( $lrn_event_label( (string) $evt, $available_events ) ); ?>
										</span>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>
						</td>
						<td>
							<?php
							// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							echo \Learnomy\Admin\UX::status_badge( $status );
							?>
							<?php if ( 'failed' === $status ) : ?>
								<p class="lrn-muted lrn-webhook-status-hint">
									<?php esc_html_e( 'Events are paused for this endpoint. Fix it, then click Test. A successful test turns it back on.', 'learnomy' ); ?>
								</p>
							<?php endif; ?>
						</td>
						<td class="lrn-webhook-fired">
							<?php echo esc_html( $last_trigger ); ?>
							<?php if ( '' !== $last_code_label ) : ?>
								<span class="lrn-muted lrn-webhook-last-code"><?php echo esc_html( $last_code_label ); ?></span>
							<?php endif; ?>
						</td>
						<td class="lrn-col-actions">
							<?php
							/**
							 * Fires inside the webhook row actions cell, after the
							 * built-in Edit / Test / Delete buttons. Pro's Webhooks
							 * Pro extension hooks here to prepend a "View log" action
							 * to the $row_actions array via the filter below.
							 *
							 * @param object $hook The webhook row object.
							 */
							$row_actions = array(
								array(
									'label'   => __( 'Edit', 'learnomy' ),
									'icon'    => 'pencil',
									'primary' => true,
									'attrs'   => array(
										'data-webhook-action' => 'edit',
										'data-webhook-id'  => (string) $hook->id,
										'data-webhook-url' => (string) $hook->url,
										'data-webhook-events' => (string) ( $hook->events_json ?? '[]' ),
									),
								),
								array(
									'label' => __( 'Test', 'learnomy' ),
									'icon'  => 'send',
									'attrs' => array(
										'data-action'   => 'learnomy_test_webhook',
										'data-id'       => (string) $hook->id,
										'data-id-field' => 'webhook_id',
									),
								),
								array(
									'label'  => __( 'Delete', 'learnomy' ),
									'icon'   => 'trash-2',
									'danger' => true,
									'attrs'  => array(
										'data-action'   => 'learnomy_delete_webhook',
										'data-id'       => (string) $hook->id,
										'data-id-field' => 'webhook_id',
										'data-confirm'  => __( 'Delete this webhook?', 'learnomy' ),
									),
								),
							);

							/**
							 * Filter the row actions for a webhook list row.
							 *
							 * Pro's Webhooks Pro extension uses this filter to
							 * prepend a "View log" action without modifying the
							 * core view file. Each action is an assoc array
							 * accepted by UX::row_actions().
							 *
							 * @param array  $row_actions Action specs.
							 * @param object $hook        The webhook row object.
							 */
							$row_actions = (array) apply_filters( 'learnomy_webhook_row_actions_list', $row_actions, $hook );

							// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							echo \Learnomy\Admin\UX::row_actions( $row_actions );
							do_action( 'learnomy_webhook_row_actions', $hook );
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
	</div>
</div>
