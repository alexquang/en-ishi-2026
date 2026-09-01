<?php
/**
 * Admin instructors list view.
 *
 * @package Learnomy
 * @var object[]            $instructors  Rows from Instructor::admin_list().
 * @var array<int,array>    $earnings_map recipient_id → { earned, pending }.
 * @var array<int,int>      $course_map   user_id → course count.
 * @var int                 $total        Total instructor count.
 * @var int                 $total_pages  Total pages.
 * @var int                 $paged        Current page.
 * @var string              $search       Current search string.
 * @var string              $orderby      Current sort key.
 * @var string              $order        Current sort direction (ASC|DESC).
 */

defined( 'ABSPATH' ) || exit;

$base_url = admin_url( 'admin.php?page=learnomy-instructors' );
$currency = strtoupper( (string) ( get_option( 'learnomy_settings', array() )['currency'] ?? 'USD' ) );

/**
 * Build a sortable column-header link that flips direction when re-clicked.
 */
$lrn_sort_link = static function ( string $key, string $label ) use ( $base_url, $search, $orderby, $order ) {
	$next  = ( $orderby === $key && 'ASC' === $order ) ? 'desc' : 'asc';
	$arrow = $orderby === $key ? ( 'ASC' === $order ? ' ↑' : ' ↓' ) : '';
	$url   = add_query_arg(
		array(
			'orderby' => $key,
			'order'   => $next,
			's'       => $search,
		),
		$base_url
	);
	return '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . esc_html( $arrow ) . '</a>';
};
?>
<div class="wrap learnomy-admin">
	<div class="lrn-page-header">
		<div class="lrn-page-header__left">
			<h1 class="lrn-page-header__title">
				<i data-lucide="users" class="lrn-page-header__icon"></i>
				<?php esc_html_e( 'Instructors', 'learnomy' ); ?>
			</h1>
		</div>
	</div>

	<hr class="wp-header-end">

	<div class="lrn-admin-filters">
		<form method="get" action="<?php echo esc_url( $base_url ); ?>" class="lrn-search-form">
			<input type="hidden" name="page" value="learnomy-instructors">
			<input type="search" name="s" aria-label="<?php esc_attr_e( 'Search', 'learnomy' ); ?>" value="<?php echo esc_attr( $search ); ?>"
					placeholder="<?php esc_attr_e( 'Search by name or email…', 'learnomy' ); ?>" class="regular-text">
			<button type="submit" class="lrn-admin-btn lrn-admin-btn--primary"><?php esc_html_e( 'Search', 'learnomy' ); ?></button>
			<?php if ( '' !== $search ) : ?>
				<a href="<?php echo esc_url( $base_url ); ?>" class="lrn-admin-btn"><?php esc_html_e( 'Clear', 'learnomy' ); ?></a>
			<?php endif; ?>
		</form>
		<span class="lrn-admin-filter-count">
			<?php
			printf(
				/* translators: %s: formatted number */
				esc_html__( '%s instructors', 'learnomy' ),
				esc_html( number_format_i18n( $total ) )
			);
			?>
		</span>
	</div>

	<div class="lrn-admin-table-wrap">
	<table class="lrn-admin-table">
		<thead>
			<tr>
				<th scope="col"><?php echo wp_kses_post( $lrn_sort_link( 'name', __( 'Instructor', 'learnomy' ) ) ); ?></th>
				<th scope="col"><?php esc_html_e( 'Courses', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Lifetime earned', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Pending payout', 'learnomy' ); ?></th>
				<th scope="col"><?php echo wp_kses_post( $lrn_sort_link( 'joined', __( 'Joined', 'learnomy' ) ) ); ?></th>
				<th scope="col"><?php esc_html_e( 'Actions', 'learnomy' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $instructors ) ) : ?>
				<tr>
					<td colspan="6" class="lrn-no-items">
						<?php
						$lrn_empty_state = array(
							'icon'        => 'users',
							'title'       => __( 'No instructors yet', 'learnomy' ),
							'description' => __( 'Approved instructors appear here with their course count and earnings.', 'learnomy' ),
						);
						include LEARNOMY_DIR . 'includes/admin/views/partials/empty-state.php';
						?>
					</td>
				</tr>
			<?php else : ?>
				<?php
				foreach ( $instructors as $lrn_inst ) :
					$lrn_uid        = (int) $lrn_inst->user_id;
					$lrn_earned     = (float) ( $earnings_map[ $lrn_uid ]['earned'] ?? 0 );
					$lrn_pending    = (float) ( $earnings_map[ $lrn_uid ]['pending'] ?? 0 );
					$lrn_courses    = (int) ( $course_map[ $lrn_uid ] ?? 0 );
					$lrn_detail_url = add_query_arg(
						array(
							'page'          => 'learnomy-instructor-detail',
							'instructor_id' => $lrn_uid,
						),
						admin_url( 'admin.php' )
					);
					$lrn_suspended  = \Learnomy\Models\Instructor::STATUS_SUSPENDED === (string) ( $lrn_inst->status ?? \Learnomy\Models\Instructor::STATUS_ACTIVE );
					?>
					<tr>
						<td>
							<div class="lrn-user-cell">
								<?php echo get_avatar( $lrn_uid, 32, '', '', array( 'class' => 'lrn-avatar lrn-avatar--sm' ) ); ?>
								<span class="lrn-user-cell__name lrn-user-cell__name--stacked">
									<a href="<?php echo esc_url( $lrn_detail_url ); ?>" class="lrn-admin-table__primary">
										<strong><?php echo esc_html( $lrn_inst->display_name ); ?></strong>
									</a>
									<a href="mailto:<?php echo esc_attr( $lrn_inst->user_email ); ?>" class="lrn-user-cell__email">
										<?php echo esc_html( $lrn_inst->user_email ); ?>
									</a>
									<?php if ( ! empty( $lrn_inst->headline ) ) : ?>
										<span class="lrn-muted lrn-admin-table__sub"><?php echo esc_html( $lrn_inst->headline ); ?></span>
									<?php endif; ?>
									<?php
									// Only the paused state is badged. Active is the norm and
									// badging 450 rows "Active" would be noise that hides the
									// handful that are not.
									if ( $lrn_suspended ) {
										echo \Learnomy\Admin\UX::status_badge( 'suspended', __( 'Suspended', 'learnomy' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper.
									}
									?>
								</span>
							</div>
						</td>
						<td><?php echo esc_html( number_format_i18n( $lrn_courses ) ); ?></td>
						<td><?php echo esc_html( $currency . ' ' . number_format_i18n( $lrn_earned, 2 ) ); ?></td>
						<td>
							<?php if ( $lrn_pending > 0 ) : ?>
								<strong><?php echo esc_html( $currency . ' ' . number_format_i18n( $lrn_pending, 2 ) ); ?></strong>
							<?php else : ?>
								<span class="lrn-muted"><?php echo esc_html( $currency . ' 0.00' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( \Learnomy\date_label( $lrn_inst->user_registered, '—', get_option( 'date_format' ) ) ); ?></td>
					<td>
						<?php
						// Suspend, not Remove. The card asked for a Remove mirroring the
						// Students list, but that list is derived from enrollments while
						// this one is a profile table, and courses point at the WP user -
						// so deleting the row would wipe a bio irreversibly and leave the
						// person authoring live courses with students enrolled
						// (Basecamp 10203580675). Suspension is the reversible thing an
						// owner actually needs, and matches the Suspend / Resume pattern
						// Spaces already ships for members.
						$lrn_row_actions = array(
							array(
								'label'   => __( 'View', 'learnomy' ),
								'href'    => $lrn_detail_url,
								'icon'    => 'eye',
								'primary' => true,
							),
							array(
								'label'  => $lrn_suspended ? __( 'Resume', 'learnomy' ) : __( 'Suspend', 'learnomy' ),
								'icon'   => $lrn_suspended ? 'play' : 'pause',
								'danger' => ! $lrn_suspended,
								'attrs'  => array(
									'class'         => 'lrn-action-link' . ( $lrn_suspended ? '' : ' lrn-action-link--danger' ),
									'data-action'   => 'learnomy_set_instructor_status',
									'data-id'       => $lrn_uid,
									'data-id-field' => 'user_id',
									'data-status'   => $lrn_suspended
										? \Learnomy\Models\Instructor::STATUS_ACTIVE
										: \Learnomy\Models\Instructor::STATUS_SUSPENDED,
									// Says exactly what does and does not happen. The Students
									// confirm names its consequences the same way, and this one
									// has more to disclaim: an owner pausing somebody needs to
									// know their students are not being cut off.
									'data-confirm'  => $lrn_suspended
										? __( 'Resume this instructor? They will appear in the public directory again and their courses will accept new enrollments.', 'learnomy' )
										: __( 'Suspend this instructor? They will be hidden from the public directory and their courses will stop accepting new enrollments. Their courses stay published, students already enrolled keep full access, and earnings already owed are unaffected. You can resume them at any time.', 'learnomy' ),
								),
							),
						);
						echo \Learnomy\Admin\UX::row_actions( $lrn_row_actions ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
		'per_page' => isset( $per_page ) ? (int) $per_page : 20,
		'base_url' => add_query_arg(
			array(
				's'       => $search,
				'orderby' => $orderby,
				'order'   => strtolower( $order ),
			),
			$base_url
		),
	);
	require LEARNOMY_DIR . 'includes/admin/views/partials/pagination.php';
	?>

</div><!-- /.learnomy-admin -->
