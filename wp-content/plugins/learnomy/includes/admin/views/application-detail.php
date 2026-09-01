<?php
/**
 * Admin per-application detail.
 *
 * The full instructor application — complete bio / teaching experience /
 * course idea (the list truncates them) — so an admin can make an informed
 * approve/reject decision. Mirrors the other detail pages.
 *
 * @package Learnomy
 * @var object         $application lrn_instructor_applications row.
 * @var \WP_User|false $user        The applicant.
 */

defined( 'ABSPATH' ) || exit;

$list_url    = admin_url( 'admin.php?page=learnomy-applications' );
$date_format = get_option( 'date_format' );
$status      = (string) ( $application->status ?? 'pending' );
$is_pending  = 'pending' === $status;

$fmt = static function ( $dt ) use ( $date_format ) {
	return \Learnomy\date_label( $dt, '—', $date_format );
};
?>
<div class="wrap learnomy-admin">
	<div class="lrn-page-header">
		<div class="lrn-page-header__left">
			<h1 class="lrn-page-header__title">
				<i data-lucide="clipboard-check" class="lrn-page-header__icon"></i>
				<?php echo esc_html( $user ? $user->display_name : __( 'Instructor application', 'learnomy' ) ); ?>
			</h1>
		</div>
		<div class="lrn-page-header__actions">
			<a class="lrn-admin-btn" href="<?php echo esc_url( $list_url ); ?>">
				<i data-lucide="arrow-left"></i>
				<?php esc_html_e( 'All applications', 'learnomy' ); ?>
			</a>
			<?php if ( $is_pending ) : ?>
				<button type="button" class="lrn-admin-btn lrn-admin-btn--primary"
						data-action="learnomy_approve_application"
						data-id="<?php echo absint( $application->id ); ?>"
						data-id-field="application_id"
						data-confirm="<?php esc_attr_e( 'Approve this instructor application? The instructor role will be granted.', 'learnomy' ); ?>">
					<i data-lucide="check"></i>
					<?php esc_html_e( 'Approve', 'learnomy' ); ?>
				</button>
				<button type="button" class="lrn-admin-btn lrn-admin-btn--danger"
						data-action="learnomy_reject_application"
						data-id="<?php echo absint( $application->id ); ?>"
						data-id-field="application_id"
						data-prompt="<?php esc_attr_e( 'Tell the applicant why this application was declined. They will see this reason.', 'learnomy' ); ?>"
						data-prompt-label="<?php esc_attr_e( 'Rejection reason', 'learnomy' ); ?>">
					<i data-lucide="x"></i>
					<?php esc_html_e( 'Reject', 'learnomy' ); ?>
				</button>
			<?php endif; ?>
		</div>
	</div>

	<hr class="wp-header-end">

	<!-- Applicant -->
	<div class="lrn-admin-card">
		<h2 class="lrn-admin-card__title"><?php esc_html_e( 'Applicant', 'learnomy' ); ?></h2>
		<table class="form-table">
			<tr>
				<th scope="row"><?php esc_html_e( 'Name', 'learnomy' ); ?></th>
				<td>
					<?php if ( $user ) : ?>
						<a href="
						<?php
						echo esc_url(
							add_query_arg(
								array(
									'page'    => 'learnomy-student-detail',
									'user_id' => absint( $user->ID ),
								),
								admin_url( 'admin.php' )
							)
						);
						?>
									">
							<?php echo esc_html( $user->display_name ); ?>
						</a>
					<?php else : ?>
						<span class="lrn-muted"><?php esc_html_e( 'Unknown user', 'learnomy' ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
			<?php if ( $user ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Email', 'learnomy' ); ?></th>
					<td><a href="mailto:<?php echo esc_attr( $user->user_email ); ?>"><?php echo esc_html( $user->user_email ); ?></a></td>
				</tr>
			<?php endif; ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Status', 'learnomy' ); ?></th>
				<td><?php echo \Learnomy\Admin\UX::status_badge( $status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Submitted', 'learnomy' ); ?></th>
				<td><?php echo esc_html( $fmt( $application->created_at ?? '' ) ); ?></td>
			</tr>
			<?php if ( ! empty( $application->reviewed_at ) ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Reviewed', 'learnomy' ); ?></th>
					<td><?php echo esc_html( $fmt( $application->reviewed_at ) ); ?></td>
				</tr>
			<?php endif; ?>
		</table>
	</div>

	<!-- Application -->
	<div class="lrn-admin-card">
		<h2 class="lrn-admin-card__title"><?php esc_html_e( 'Application', 'learnomy' ); ?></h2>
		<table class="form-table">
			<?php if ( ! empty( $application->expertise ) ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Expertise', 'learnomy' ); ?></th>
					<td><?php echo esc_html( (string) $application->expertise ); ?></td>
				</tr>
			<?php endif; ?>
			<?php if ( ! empty( $application->teaching_experience ) ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Teaching experience', 'learnomy' ); ?></th>
					<td><?php echo nl2br( esc_html( (string) $application->teaching_experience ) ); ?></td>
				</tr>
			<?php endif; ?>
			<?php if ( ! empty( $application->bio ) ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Bio', 'learnomy' ); ?></th>
					<td><?php echo nl2br( esc_html( (string) $application->bio ) ); ?></td>
				</tr>
			<?php endif; ?>
			<?php if ( ! empty( $application->course_idea ) ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Course idea', 'learnomy' ); ?></th>
					<td><?php echo nl2br( esc_html( (string) $application->course_idea ) ); ?></td>
				</tr>
			<?php endif; ?>
			<?php if ( ! empty( $application->payout_method ) ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Payout method', 'learnomy' ); ?></th>
					<td><?php echo esc_html( (string) $application->payout_method ); ?></td>
				</tr>
			<?php endif; ?>
			<?php if ( 'rejected' === $status && ! empty( $application->rejection_reason ) ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Rejection reason', 'learnomy' ); ?></th>
					<td><?php echo esc_html( (string) $application->rejection_reason ); ?></td>
				</tr>
			<?php endif; ?>
		</table>
	</div>

</div><!-- /.learnomy-admin -->
