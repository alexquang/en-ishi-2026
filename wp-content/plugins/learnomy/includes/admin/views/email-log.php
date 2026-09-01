<?php
/**
 * Admin email / notification log view.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

use Learnomy\API\Email_Log_Controller;
use Learnomy\Models\AuditLog;
use Learnomy\Models\Notification;

// Per-page assets: the Resend button wiring + the filter/reason styling. Enqueued
// here (not in class-admin.php) so the Email Log owns its own surface. The base
// `learnomy-admin` handle carries window.learnomyAdmin (restUrl + restNonce) and
// pulls in learnomy-toast, so email-log.js needs no extra localisation.
wp_enqueue_style( 'learnomy-email-log', LEARNOMY_URL . 'assets/css/admin/email-log.css', array( 'learnomy-admin' ), LEARNOMY_VERSION );
wp_enqueue_script( 'learnomy-email-log', LEARNOMY_URL . 'assets/js/admin/email-log.js', array( 'learnomy-admin', 'wp-i18n' ), LEARNOMY_VERSION, true );
wp_set_script_translations( 'learnomy-email-log', 'learnomy', LEARNOMY_DIR . 'languages' );

$paged    = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$per_page = 25;
$offset   = ( $paged - 1 ) * $per_page;

// Filters. This is an "Email Log" but the table also carries in-app-only rows
// whose email was skipped, which is noisy at scale — let the admin scope to the
// delivery outcome, the recipient, and the date window they care about. Empty
// filters keep the full log. All are read via GET (server-rendered, paginated).
// phpcs:disable WordPress.Security.NonceVerification.Recommended
$lrn_status_filter = isset( $_GET['email_status'] ) ? sanitize_key( wp_unslash( $_GET['email_status'] ) ) : '';
$lrn_recipient     = isset( $_GET['recipient'] ) ? sanitize_text_field( wp_unslash( $_GET['recipient'] ) ) : '';
$lrn_date_from     = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '';
$lrn_date_to       = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '';
$lrn_type_filter   = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
// phpcs:enable WordPress.Security.NonceVerification.Recommended

// The type options come from the rows that actually exist, so a slug written by
// cron or by an extension is filterable too — the Type column has always shown
// them, there was simply no way to narrow to one.
$lrn_type_opts = Notification::log_types();
if ( '' !== $lrn_type_filter && ! in_array( $lrn_type_filter, $lrn_type_opts, true ) ) {
	$lrn_type_filter = '';
}

$lrn_status_opts = array( 'sent', 'failed', 'skipped' );
$lrn_search_args = array(
	'email_status' => in_array( $lrn_status_filter, $lrn_status_opts, true ) ? $lrn_status_filter : '',
	'type'         => $lrn_type_filter,
	'recipient'    => $lrn_recipient,
	'date_from'    => $lrn_date_from,
	'date_to'      => $lrn_date_to,
);
$lrn_has_filters = ( '' !== $lrn_search_args['email_status'] || '' !== $lrn_type_filter || '' !== $lrn_recipient || '' !== $lrn_date_from || '' !== $lrn_date_to );

$notifications = Notification::search_log( $lrn_search_args, $per_page, $offset );
$total         = Notification::count_log( $lrn_search_args );
$total_pages   = (int) ceil( $total / $per_page );

// Resend history for THIS page of rows, in one query — a resend no longer
// overwrites the original attempt, so each row can carry both events. Batched
// (never per row) so the log stays a fixed number of queries at any page size.
$lrn_resends = AuditLog::latest_for_objects(
	'notification',
	array_map( static fn( $n ) => (int) $n->id, $notifications ),
	Email_Log_Controller::RESEND_ACTION
);
?>
<div class="wrap learnomy-admin">
	<div class="lrn-page-header">
		<div class="lrn-page-header__left">
			<h1 class="lrn-page-header__title">
				<i data-lucide="mail" class="lrn-page-header__icon"></i>
				<?php esc_html_e( 'Email Log', 'learnomy' ); ?>
			</h1>
		</div>
	</div>

	<?php
	// `wp-header-end` marker — admin notices render below this line.
	// Without it, third-party plugin notices stack at the top of the
	// page and overlap the page header (round 3 audit-rule-8).
	?>
	<hr class="wp-header-end">

	<form method="get" class="lrn-admin-filters lrn-email-log-filters">
		<input type="hidden" name="page" value="learnomy-email-log">

		<div class="lrn-email-log-filters__field">
			<label for="lrn-email-recipient" class="screen-reader-text"><?php esc_html_e( 'Search by recipient', 'learnomy' ); ?></label>
			<input type="search" id="lrn-email-recipient" name="recipient" value="<?php echo esc_attr( $lrn_recipient ); ?>" placeholder="<?php esc_attr_e( 'Recipient email or name', 'learnomy' ); ?>" class="regular-text">
		</div>

		<div class="lrn-email-log-filters__field">
			<label for="lrn-email-date-from" class="screen-reader-text"><?php esc_html_e( 'From date', 'learnomy' ); ?></label>
			<input type="date" id="lrn-email-date-from" name="date_from" value="<?php echo esc_attr( $lrn_date_from ); ?>" aria-label="<?php esc_attr_e( 'From date', 'learnomy' ); ?>">
			<span class="lrn-email-log-filters__sep" aria-hidden="true">&ndash;</span>
			<label for="lrn-email-date-to" class="screen-reader-text"><?php esc_html_e( 'To date', 'learnomy' ); ?></label>
			<input type="date" id="lrn-email-date-to" name="date_to" value="<?php echo esc_attr( $lrn_date_to ); ?>" aria-label="<?php esc_attr_e( 'To date', 'learnomy' ); ?>">
		</div>

		<?php if ( ! empty( $lrn_type_opts ) ) : ?>
			<div class="lrn-email-log-filters__field">
				<label for="lrn-email-type" class="screen-reader-text"><?php esc_html_e( 'Filter by notification type', 'learnomy' ); ?></label>
				<select id="lrn-email-type" name="type">
					<option value="" <?php selected( '', $lrn_type_filter ); ?>><?php esc_html_e( 'All types', 'learnomy' ); ?></option>
					<?php foreach ( $lrn_type_opts as $lrn_type_opt ) : ?>
						<option value="<?php echo esc_attr( $lrn_type_opt ); ?>" <?php selected( $lrn_type_opt, $lrn_type_filter ); ?>>
							<?php echo esc_html( Notification::type_label( $lrn_type_opt ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
		<?php endif; ?>

		<div class="lrn-email-log-filters__field">
			<label for="lrn-email-status" class="screen-reader-text"><?php esc_html_e( 'Filter by delivery status', 'learnomy' ); ?></label>
			<select id="lrn-email-status" name="email_status">
				<option value="" <?php selected( '', $lrn_search_args['email_status'] ); ?>><?php esc_html_e( 'All statuses', 'learnomy' ); ?></option>
				<option value="sent" <?php selected( 'sent', $lrn_search_args['email_status'] ); ?>><?php esc_html_e( 'Sent', 'learnomy' ); ?></option>
				<option value="failed" <?php selected( 'failed', $lrn_search_args['email_status'] ); ?>><?php esc_html_e( 'Failed', 'learnomy' ); ?></option>
				<option value="skipped" <?php selected( 'skipped', $lrn_search_args['email_status'] ); ?>><?php esc_html_e( 'Skipped (in-app only)', 'learnomy' ); ?></option>
			</select>
		</div>

		<button type="submit" class="lrn-admin-btn lrn-admin-btn--sm"><?php esc_html_e( 'Filter', 'learnomy' ); ?></button>
		<?php if ( $lrn_has_filters ) : ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-email-log' ) ); ?>" class="lrn-admin-btn lrn-admin-btn--sm lrn-admin-btn--ghost"><?php esc_html_e( 'Clear', 'learnomy' ); ?></a>
		<?php endif; ?>
	</form>

	<div class="lrn-admin-table-wrap">
	<table class="lrn-admin-table">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Recipient', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Type', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Subject / Title', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Sent At', 'learnomy' ); ?></th>
				<th scope="col" class="lrn-admin-table__actions-col"><?php esc_html_e( 'Actions', 'learnomy' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $notifications ) ) : ?>
				<tr>
					<td colspan="6" class="lrn-no-items">
						<?php
						$lrn_empty_state = $lrn_has_filters
							? array(
								'icon'        => 'search-x',
								'title'       => __( 'No notifications match these filters', 'learnomy' ),
								'description' => __( 'Try a different recipient, widen the date range, or clear the filters to see the full log.', 'learnomy' ),
							)
							: array(
								'icon'        => 'mail',
								'title'       => __( 'No notifications logged yet', 'learnomy' ),
								'description' => __( 'Email notifications dispatched by Learnomy show up here so you can audit what each student saw.', 'learnomy' ),
							);
						include LEARNOMY_DIR . 'includes/admin/views/partials/empty-state.php';
						?>
					</td>
				</tr>
			<?php else : ?>
				<?php
				foreach ( $notifications as $note ) :
					$recipient = $note->user_id ? get_userdata( (int) $note->user_id ) : null;
					// Show WHO received the email — display name + email — not a bare
					// numeric user ID a site owner can't read. Deleted users fall back
					// to an explicit "User #ID (deleted)" rather than a naked number.
					if ( $recipient ) {
						$to_name  = $recipient->display_name ? $recipient->display_name : $recipient->user_login;
						$to_email = $recipient->user_email;
					} elseif ( $note->user_id ) {
						/* translators: %d: user ID */
						$to_name  = sprintf( __( 'User #%d (deleted)', 'learnomy' ), (int) $note->user_id );
						$to_email = '';
					} else {
						$to_name  = '—';
						$to_email = '';
					}
					$sent_at = $note->created_at ? \Learnomy\date_label( $note->created_at, '—', get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : '—';
					// Real delivery outcome captured at send time (Migration 3.8.0):
					// sent / failed / skipped. Rows written before the column existed
					// default to `skipped` rather than a phantom "sent" we cannot
					// actually vouch for.
					$status = ! empty( $note->email_status ) ? (string) $note->email_status : 'skipped';

					// Resend is offered whenever the row still has a reachable
					// recipient. The failure reason IS stored per row in
					// `email_error` (migration 3.16.0, captured from wp_mail_failed
					// at send time) and rendered below; rows written before that
					// column existed show a "resend to capture it" hint instead.
					$lrn_actions = array();
					if ( '' !== $to_email ) {
						$lrn_actions[] = array(
							'label'   => __( 'Resend', 'learnomy' ),
							'icon'    => 'send',
							'primary' => true,
							'attrs'   => array(
								'class'          => 'lrn-email-log-resend',
								'data-id'        => (int) $note->id,
								'data-recipient' => $to_email,
							),
						);
					}
					?>
					<tr data-email-log-row="<?php echo esc_attr( (int) $note->id ); ?>">
						<td>
							<div class="lrn-user-cell">
								<?php echo $note->user_id ? get_avatar( (int) $note->user_id, 32, '', '', array( 'class' => 'lrn-avatar lrn-avatar--sm' ) ) : ''; ?>
								<span class="lrn-user-cell__name lrn-user-cell__name--stacked">
									<strong><?php echo esc_html( $to_name ); ?></strong>
									<?php if ( '' !== $to_email ) : ?>
										<a href="mailto:<?php echo esc_attr( $to_email ); ?>" class="lrn-user-cell__email"><?php echo esc_html( $to_email ); ?></a>
									<?php endif; ?>
								</span>
							</div>
						</td>
						<td><?php echo esc_html( \Learnomy\Models\Notification::type_label( (string) ( $note->type ?? '' ) ) ); ?></td>
						<td><?php echo esc_html( $note->title ?? $note->subject ?? __( '(no subject)', 'learnomy' ) ); ?></td>
						<td>
							<div class="lrn-email-log-status" data-email-log-status>
								<?php echo \Learnomy\Admin\UX::status_badge( (string) $status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<?php if ( 'failed' === $status ) : ?>
									<?php $lrn_email_error = trim( (string) ( $note->email_error ?? '' ) ); ?>
									<?php if ( '' !== $lrn_email_error ) : ?>
										<span class="lrn-email-log-status__hint" data-email-log-reason title="<?php echo esc_attr( $lrn_email_error ); ?>"><?php echo esc_html( $lrn_email_error ); ?></span>
									<?php else : ?>
										<span class="lrn-email-log-status__hint" data-email-log-reason><?php esc_html_e( 'Reason not recorded (logged before error capture) - Resend to capture it.', 'learnomy' ); ?></span>
									<?php endif; ?>
								<?php endif; ?>

								<?php
								// The badge above is the ORIGINAL attempt and stays that way.
								// Every manual resend is its own event, shown here, so "did this
								// student get their receipt?" can be answered with the whole
								// story instead of only its latest line.
								$lrn_resend = $lrn_resends[ (int) $note->id ] ?? null;
								if ( $lrn_resend ) :
									$lrn_resend_meta   = json_decode( (string) ( $lrn_resend['latest']->metadata_json ?? '{}' ), true );
									$lrn_resend_meta   = is_array( $lrn_resend_meta ) ? $lrn_resend_meta : array();
									$lrn_resend_ok     = 'sent' === ( $lrn_resend_meta['email_status'] ?? '' );
									$lrn_resend_when   = date_i18n(
										get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
										strtotime( (string) ( $lrn_resend['latest']->created_at ?? '' ) )
									);
									$lrn_resend_reason = trim( (string) ( $lrn_resend_meta['error'] ?? '' ) );
									?>
									<span class="lrn-email-log-status__resend" data-email-log-resend>
										<?php
										if ( $lrn_resend_ok ) {
											printf(
												/* translators: %s: date and time of the most recent resend */
												esc_html__( 'Resent %s - delivered', 'learnomy' ),
												esc_html( $lrn_resend_when )
											);
										} else {
											printf(
												/* translators: %s: date and time of the most recent resend */
												esc_html__( 'Resent %s - failed', 'learnomy' ),
												esc_html( $lrn_resend_when )
											);
										}

										if ( (int) $lrn_resend['count'] > 1 ) {
											echo ' ';
											printf(
												/* translators: %s: total number of resend attempts */
												esc_html( _n( '(%s attempt)', '(%s attempts)', (int) $lrn_resend['count'], 'learnomy' ) ),
												esc_html( number_format_i18n( (int) $lrn_resend['count'] ) )
											);
										}
										?>
									</span>
									<?php if ( ! $lrn_resend_ok && '' !== $lrn_resend_reason ) : ?>
										<span class="lrn-email-log-status__hint" data-email-log-resend-reason title="<?php echo esc_attr( $lrn_resend_reason ); ?>"><?php echo esc_html( $lrn_resend_reason ); ?></span>
									<?php endif; ?>
								<?php endif; ?>
							</div>
						</td>
						<td><?php echo esc_html( $sent_at ); ?></td>
						<td class="lrn-admin-table__actions-col">
							<?php
							// UX::row_actions escapes every field internally.
							echo \Learnomy\Admin\UX::row_actions( $lrn_actions ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
	</div>

	<?php
	$lrn_pagination = array(
		'current'  => (int) $paged,
		'total'    => (int) $total_pages,
		'results'  => (int) $total,
		'per_page' => (int) $per_page,
	);
	require LEARNOMY_DIR . 'includes/admin/views/partials/pagination.php';
	?>
</div>
