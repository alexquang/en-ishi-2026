<?php
/**
 * Admin instructor applications view.
 *
 * @package Learnomy
 * @var object[] $applications  Filtered application rows.
 * @var string   $status_filter Active status filter slug.
 */

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\InstructorApplication;

$status_filter = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'pending'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$paged         = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$per_page      = 20;

$status_tabs = array(
	'pending'  => __( 'Pending', 'learnomy' ),
	'approved' => __( 'Approved', 'learnomy' ),
	'rejected' => __( 'Rejected', 'learnomy' ),
);

$where = array();
if ( array_key_exists( $status_filter, $status_tabs ) ) {
	$where['status'] = $status_filter;
}

$applications = InstructorApplication::where( $where, 'created_at', 'DESC', $per_page, ( $paged - 1 ) * $per_page );
$total        = InstructorApplication::count( $where );

// Prime the user + meta cache for every applicant on this page in a single
// query so the per-row get_userdata()/get_avatar() calls below are cache hits
// instead of one DB round-trip per row (was O(rows) get_userdata queries).
$lrn_applicant_ids = array_values(
	array_unique(
		array_filter(
			array_map(
				static function ( $app ) {
					return (int) ( $app->user_id ?? 0 );
				},
				$applications
			)
		)
	)
);
if ( ! empty( $lrn_applicant_ids ) ) {
	new \WP_User_Query(
		array(
			'include'     => $lrn_applicant_ids,
			'number'      => count( $lrn_applicant_ids ),
			'count_total' => false,
			'fields'      => 'all_with_meta',
		)
	);
}
$total_pages = (int) ceil( $total / $per_page );
$base_url    = admin_url( 'admin.php?page=learnomy-applications' );

// Bulk-action wiring — approve/reject hit sub-endpoints under
// /admin/instructor-applications/{id}/{action}. Resource path uses the
// admin namespace because that's where the destructive mutation lives.
$lrn_list_attrs = sprintf(
	' data-list-resource="%s" data-list-rest-base="%s" data-list-nonce="%s" data-list-action-map=\'%s\'',
	'admin/instructor-applications',
	esc_attr( rest_url( 'learnomy/v1/' ) ),
	esc_attr( wp_create_nonce( 'wp_rest' ) ),
	esc_attr(
		wp_json_encode(
			array(
				'approve' => array(
					'method' => 'POST',
					'suffix' => '/approve',
				),
				'reject'  => array(
					'method' => 'POST',
					'suffix' => '/reject',
				),
			)
		)
	)
);
?>
<div class="wrap learnomy-admin"<?php echo $lrn_list_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- values escaped above. ?>>
	<div class="lrn-page-header">
		<div class="lrn-page-header__left">
			<h1 class="lrn-page-header__title">
				<i data-lucide="clipboard-check" class="lrn-page-header__icon"></i>
				<?php esc_html_e( 'Instructor Applications', 'learnomy' ); ?>
			</h1>
		</div>
		<div class="lrn-page-header__actions"></div>
	</div>

	<?php
	// `wp-header-end` marker — admin notices render below this line.
	// Without it, third-party plugin notices stack at the top of the
	// page and overlap the page header (round 3 audit-rule-8).
	?>
	<hr class="wp-header-end">

	<div class="lrn-admin-filters">
		<ul class="subsubsub">
			<?php
			foreach ( $status_tabs as $slug => $label ) :
				$tab_where = array( 'status' => $slug );
				$tab_count = InstructorApplication::count( $tab_where );
				$is_active = ( $status_filter === $slug );
				?>
				<li>
					<a href="<?php echo esc_url( add_query_arg( 'status', $slug, $base_url ) ); ?>"
						class="<?php echo esc_attr( $is_active ? 'current' : '' ); ?>">
						<?php echo esc_html( $label ); ?>
						<span class="count">(<?php echo esc_html( number_format_i18n( $tab_count ) ); ?>)</span>
					</a>
					<?php echo 'rejected' !== $slug ? ' |' : ''; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>

	<div class="lrn-bulk-bar lrn-bulk-bar--hidden">
		<div class="lrn-bulk-bar__left">
			<span class="lrn-bulk-bar__count">0 selected</span>
			<select id="lrn-bulk-action">
				<option value=""><?php esc_html_e( 'Bulk Actions', 'learnomy' ); ?></option>
				<option value="approve"><?php esc_html_e( 'Approve', 'learnomy' ); ?></option>
				<option value="reject"><?php esc_html_e( 'Reject', 'learnomy' ); ?></option>
			</select>
			<button type="button" id="lrn-bulk-apply" class="lrn-admin-btn lrn-admin-btn--sm">
				<?php esc_html_e( 'Apply', 'learnomy' ); ?>
			</button>
		</div>
	</div>
	<div class="lrn-admin-table-wrap">
	<table class="lrn-admin-table">
		<thead>
			<tr>
				<th class="lrn-col-check"><input type="checkbox" id="lrn-select-all"></th>
				<th scope="col"><?php esc_html_e( 'Applicant', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Email', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Bio', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Course Idea', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Applied', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Actions', 'learnomy' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $applications ) ) : ?>
				<tr>
					<td colspan="8" class="lrn-no-items">
						<?php
						$lrn_empty_state = array(
							'icon'        => 'user-check',
							'title'       => __( 'No applications yet', 'learnomy' ),
							'description' => __( 'Instructor applications appear here as soon as anyone submits one. Promote the Become Instructor page to get the first ones in.', 'learnomy' ),
						);
						include LEARNOMY_DIR . 'includes/admin/views/partials/empty-state.php';
						?>
					</td>
				</tr>
			<?php else : ?>
				<?php
				foreach ( $applications as $app ) :
					$user        = get_userdata( (int) $app->user_id );
					$name        = $user ? $user->display_name : __( 'Unknown User', 'learnomy' );
					$email       = $user ? $user->user_email : '';
					$bio         = wp_trim_words( $app->bio ?? '', 20, '…' );
					$course_idea = wp_trim_words( $app->course_idea ?? '', 20, '…' );
					$applied_at  = $app->created_at ? \Learnomy\date_label( $app->created_at, '—', get_option( 'date_format' ) ) : '—';
					?>
					<tr>
						<td class="lrn-col-check"><input type="checkbox" class="lrn-row-check" value="<?php echo absint( $app->id ); ?>"></td>
						<td>
							<div class="lrn-user-cell">
								<?php echo get_avatar( (int) $app->user_id, 32, '', '', array( 'class' => 'lrn-avatar lrn-avatar--sm' ) ); ?>
								<span class="lrn-user-cell__name">
									<a class="lrn-admin-table__primary" href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-application-detail&application_id=' . absint( $app->id ) ) ); ?>">
										<strong><?php echo esc_html( $name ); ?></strong>
									</a>
								</span>
							</div>
						</td>
						<td>
							<?php if ( '' !== $email ) : ?>
								<a href="mailto:<?php echo esc_attr( $email ); ?>"><?php echo esc_html( $email ); ?></a>
							<?php else : ?>
								<span class="lrn-muted">—</span>
							<?php endif; ?>
						</td>
						<td class="lrn-bio-cell"><?php echo esc_html( '' !== $bio ? $bio : '—' ); ?></td>
						<td class="lrn-bio-cell"><?php echo esc_html( '' !== $course_idea ? $course_idea : '—' ); ?></td>
						<td><?php echo esc_html( $applied_at ); ?></td>
						<td>
							<?php echo \Learnomy\Admin\UX::status_badge( (string) $app->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</td>
						<td class="lrn-row-actions">
							<?php
							if ( 'pending' === $app->status ) :
								$lrn_app_actions = array(
									array(
										'label'   => __( 'Approve', 'learnomy' ),
										'icon'    => 'check',
										'primary' => true,
										'attrs'   => array(
											'class'        => 'lrn-admin-btn lrn-admin-btn--primary',
											'data-action'  => 'learnomy_approve_application',
											'data-id'      => $app->id,
											'data-id-field' => 'application_id',
											'data-confirm' => __( 'Approve this instructor application?', 'learnomy' ),
										),
									),
									array(
										'label'  => __( 'Reject', 'learnomy' ),
										'icon'   => 'x',
										'danger' => true,
										// data-prompt, not data-confirm: rejecting asks the
										// admin for a reason and stashes it as `reason`,
										// which actions.js maps to the REST `feedback`
										// param and the applicant then reads. A plain
										// confirm sent {} and every rejected applicant
										// got the generic message, with no way for the
										// admin to say why (Basecamp 10190089031).
										// application-detail.php already did this; the
										// list - the screen admins actually work from -
										// did not.
										'attrs'  => array(
											'class'       => 'lrn-admin-btn',
											'data-action' => 'learnomy_reject_application',
											'data-id'     => $app->id,
											'data-id-field' => 'application_id',
											'data-prompt' => __( 'Tell the applicant why this application was declined. They will see this reason.', 'learnomy' ),
											'data-prompt-label' => __( 'Rejection reason', 'learnomy' ),
										),
									),
								);
								echo \Learnomy\Admin\UX::row_actions( $lrn_app_actions ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							else :
								?>
								<span class="lrn-muted"><?php esc_html_e( 'No actions available', 'learnomy' ); ?></span>
								<?php
							endif;
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
		'current'  => $paged,
		'total'    => $total_pages,
		'results'  => $total,
		'per_page' => $per_page,
		'base_url' => add_query_arg( 'status', $status_filter, $base_url ),
	);
	require LEARNOMY_DIR . 'includes/admin/views/partials/pagination.php';
	?>
</div>
