<?php
/**
 * Admin certificates list view.
 *
 * @package Learnomy
 * @var object[]      $certificates  Paginated certificate rows.
 * @var int           $total         Total certificates (filtered).
 * @var int           $total_pages   Total pages.
 * @var int           $paged         Current page.
 * @var string        $search        Active search term.
 * @var string        $status_filter Active status slug ('all'|active|revoked|expired).
 * @var int           $course_filter Active course id filter (0 = all).
 * @var array<int,object> $cert_courses Courses with certificates, keyed by id, for the dropdown.
 */

defined( 'ABSPATH' ) || exit;

$base_url = admin_url( 'admin.php?page=learnomy-certificates' );

// Defensive defaults so the view renders even if included without the
// filter context (e.g. a stale call site).
$search        = isset( $search ) ? (string) $search : '';
$status_filter = isset( $status_filter ) ? (string) $status_filter : 'all';
$course_filter = isset( $course_filter ) ? (int) $course_filter : 0;
$cert_courses  = isset( $cert_courses ) && is_array( $cert_courses ) ? $cert_courses : array();

// Carry the active filters onto the pagination links so paging keeps the
// filtered view.
$lrn_paged_base = $base_url;
if ( '' !== $search ) {
	$lrn_paged_base = add_query_arg( 's', rawurlencode( $search ), $lrn_paged_base );
}
if ( in_array( $status_filter, array( 'active', 'revoked', 'expired' ), true ) ) {
	$lrn_paged_base = add_query_arg( 'status', $status_filter, $lrn_paged_base );
}
if ( $course_filter > 0 ) {
	$lrn_paged_base = add_query_arg( 'course_id', $course_filter, $lrn_paged_base );
}

$lrn_has_filters = ( '' !== $search ) || in_array( $status_filter, array( 'active', 'revoked', 'expired' ), true ) || $course_filter > 0;

$lrn_status_options = array(
	'all'     => __( 'All statuses', 'learnomy' ),
	'active'  => __( 'Active', 'learnomy' ),
	'revoked' => __( 'Revoked', 'learnomy' ),
	'expired' => __( 'Expired', 'learnomy' ),
);

// Bulk-action wiring — `revoke` is a sub-endpoint POST per certificate
// uuid: POST /certificates/{uuid}/revoke. The action-map in list-page.js
// translates `revoke` → POST with /revoke suffix appended.
$lrn_list_attrs = sprintf(
	' data-list-resource="%s" data-list-rest-base="%s" data-list-nonce="%s" data-list-action-map=\'%s\'',
	'certificates',
	esc_attr( rest_url( 'learnomy/v1/' ) ),
	esc_attr( wp_create_nonce( 'wp_rest' ) ),
	esc_attr(
		wp_json_encode(
			array(
				'revoke' => array(
					'method' => 'POST',
					'suffix' => '/revoke',
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
				<i data-lucide="award" class="lrn-page-header__icon"></i>
				<?php esc_html_e( 'Certificates', 'learnomy' ); ?>
			</h1>
		</div>
		<div class="lrn-page-header__actions">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-certificate-designer' ) ); ?>"
				class="lrn-admin-btn lrn-admin-btn--primary">
				<?php esc_html_e( 'Design New Template', 'learnomy' ); ?>
			</a>
		</div>
	</div>

	<?php
	// `wp-header-end` marker — admin notices render below this line.
	// Without it, third-party plugin notices stack at the top of the
	// page and overlap the page header (round 3 audit-rule-8).
	?>
	<hr class="wp-header-end">

	<?php
	// Certificate Templates — the EDIT entry point (customer feedback: "if I
	// notice a small problem I have to start over"). The designer already edits
	// via ?template_id=N; this list surfaces the door. `$certificate_templates`
	// comes from Admin::render_certificates().
	?>
	<?php if ( ! empty( $certificate_templates ) ) : ?>
		<div class="lrn-admin-section lrn-certificate-templates">
			<h2 class="lrn-admin-section__title"><?php esc_html_e( 'Certificate Templates', 'learnomy' ); ?></h2>
	<div class="lrn-bulk-bar lrn-bulk-bar--hidden">
		<div class="lrn-bulk-bar__left">
			<span class="lrn-bulk-bar__count">0 selected</span>
			<select id="lrn-bulk-action">
				<option value=""><?php esc_html_e( 'Bulk Actions', 'learnomy' ); ?></option>
				<option value="revoke"><?php esc_html_e( 'Revoke', 'learnomy' ); ?></option>
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
							<th scope="col"><?php esc_html_e( 'Template', 'learnomy' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Type', 'learnomy' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Available to', 'learnomy' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Orientation', 'learnomy' ); ?></th>
							<th scope="col" class="lrn-col-actions"><?php esc_html_e( 'Actions', 'learnomy' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $certificate_templates as $lrn_tpl ) : ?>
							<tr>
								<td>
									<strong><?php echo esc_html( '' !== (string) ( $lrn_tpl->title ?? '' ) ? (string) $lrn_tpl->title : __( '(untitled template)', 'learnomy' ) ); ?></strong>
									<?php if ( ! empty( $lrn_tpl->lrn_is_curated ) ) : ?>
										<span class="lrn-badge lrn-badge--blue"><?php esc_html_e( 'Curated', 'learnomy' ); ?></span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( (string) ( $lrn_tpl->lrn_kind_label ?? '' ) ); ?></td>
								<td>
									<span class="lrn-badge lrn-badge--<?php echo esc_attr( (string) ( $lrn_tpl->lrn_avail_variant ?? 'green' ) ); ?>">
										<?php echo esc_html( (string) ( $lrn_tpl->lrn_avail_label ?? '' ) ); ?>
									</span>
								</td>
								<td><?php echo esc_html( ucfirst( (string) ( $lrn_tpl->orientation ?? 'landscape' ) ) ); ?></td>
								<td>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-certificate-designer&template_id=' . (int) $lrn_tpl->id ) ); ?>"
										class="lrn-admin-btn lrn-admin-btn--sm">
										<i data-lucide="pencil"></i> <?php esc_html_e( 'Edit', 'learnomy' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	<?php endif; ?>

	<!-- Filters: search (student name / certificate ID) + status + course -->
	<div class="lrn-admin-filters">
		<form method="get" action="<?php echo esc_url( $base_url ); ?>" class="lrn-search-form">
			<input type="hidden" name="page" value="learnomy-certificates">

			<select name="status" aria-label="<?php esc_attr_e( 'Status', 'learnomy' ); ?>">
				<?php foreach ( $lrn_status_options as $lrn_value => $lrn_label ) : ?>
					<option value="<?php echo esc_attr( $lrn_value ); ?>" <?php selected( $status_filter, $lrn_value ); ?>>
						<?php echo esc_html( $lrn_label ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<?php if ( ! empty( $cert_courses ) ) : ?>
				<select name="course_id" aria-label="<?php esc_attr_e( 'Course', 'learnomy' ); ?>">
					<option value="0"><?php esc_html_e( 'All courses', 'learnomy' ); ?></option>
					<?php foreach ( $cert_courses as $lrn_course ) : ?>
						<option value="<?php echo absint( $lrn_course->id ); ?>" <?php selected( $course_filter, (int) $lrn_course->id ); ?>>
							<?php echo esc_html( $lrn_course->title ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			<?php endif; ?>

			<input type="search" name="s" aria-label="<?php esc_attr_e( 'Search', 'learnomy' ); ?>" value="<?php echo esc_attr( $search ); ?>"
				placeholder="<?php esc_attr_e( 'Search by student or certificate ID…', 'learnomy' ); ?>" class="regular-text">
			<button type="submit" class="lrn-admin-btn lrn-admin-btn--primary"><?php esc_html_e( 'Search', 'learnomy' ); ?></button>

			<?php if ( $lrn_has_filters ) : ?>
				<a href="<?php echo esc_url( $base_url ); ?>" class="lrn-admin-btn"><?php esc_html_e( 'Clear', 'learnomy' ); ?></a>
			<?php endif; ?>
		</form>
		<span class="lrn-admin-filter-count">
			<?php
			printf(
				/* translators: %s: formatted certificate count */
				esc_html__( '%s certificates', 'learnomy' ),
				esc_html( number_format_i18n( $total ) )
			);
			?>
		</span>
	</div>

	<!-- Certificates table -->
	<div class="lrn-admin-table-wrap">
	<table class="lrn-admin-table">
		<thead>
			<tr>
				<th class="lrn-col-check"><input type="checkbox" id="lrn-select-all"></th>
				<th scope="col"><?php esc_html_e( 'Student', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Course', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Verification ID', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Issued', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Expires', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Actions', 'learnomy' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $certificates ) ) : ?>
				<tr>
					<td colspan="8" class="lrn-no-items">
						<?php
						$lrn_empty_state = array(
							'icon'        => 'award',
							'title'       => __( 'No certificates yet', 'learnomy' ),
							'description' => __( 'Certificates are issued automatically when a student completes a qualifying course.', 'learnomy' ),
						);
						include LEARNOMY_DIR . 'includes/admin/views/partials/empty-state.php';
						?>
					</td>
				</tr>
			<?php else : ?>
				<?php
				// Each certificate names its own course, from the snapshot taken
				// when it was issued, so this list keeps working after a course is
				// retired (Basecamp 10148480574). Resolved for the whole page in
				// one pass — the old per-row Course::find() was an N+1 on a list
				// that pages 20 certificates at a time.
				$lrn_cert_titles = \Learnomy\Services\Certificate_Service::display_titles( $certificates );
				foreach ( $certificates as $cert ) :
					$student    = get_userdata( (int) $cert->user_id );
					$cert_title = $lrn_cert_titles[ (int) $cert->id ] ?? array();
					$status     = $cert->status ?? 'active';
					$uuid       = $cert->uuid ?? '';
					$short_uuid = $uuid ? substr( $uuid, 0, 8 ) . '…' : '&mdash;';
					?>
					<tr>
						<?php
						// Checkbox value carries the UUID, not the numeric id — the
						// revoke REST route matches `(?P<uuid>[a-f0-9\-]{36})`, so the
						// generic bulk handler must build /certificates/{uuid}/revoke.
						?>
						<td class="lrn-col-check"><input type="checkbox" class="lrn-row-check" value="<?php echo esc_attr( $uuid ); ?>"></td>
						<td>
							<?php if ( $student ) : ?>
								<div class="lrn-user-cell">
									<?php echo get_avatar( $student->ID, 28, '', '', array( 'class' => 'lrn-avatar lrn-avatar--sm' ) ); ?>
									<span><?php echo esc_html( $student->display_name ); ?></span>
								</div>
							<?php else : ?>
								<span class="lrn-muted"><?php esc_html_e( 'Deleted user', 'learnomy' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php
							if ( ! empty( $cert_title['recorded'] ) ) {
								echo esc_html( (string) $cert_title['title'] );
							} else {
								// Issued before certificates recorded their own
								// title, and the course row is gone — the name is
								// not recoverable, so say that rather than imply
								// the credential is broken.
								echo '<span class="lrn-muted">' . esc_html__( 'Course name not on record', 'learnomy' ) . '</span>';
							}
							?>
						</td>
						<td>
							<code title="<?php echo esc_attr( $uuid ); ?>"><?php echo esc_html( $short_uuid ); ?></code>
						</td>
						<td>
							<?php echo esc_html( \Learnomy\date_label( $cert->issued_at, '—', get_option( 'date_format' ) ) ); ?>
						</td>
						<td>
							<?php
							if ( ! empty( $cert->expires_at ) && '0000-00-00 00:00:00' !== $cert->expires_at ) {
								echo esc_html( \Learnomy\date_label( $cert->expires_at, '—', get_option( 'date_format' ) ) );
							} else {
								echo '<span class="lrn-muted">' . esc_html__( 'Never', 'learnomy' ) . '</span>';
							}
							?>
						</td>
						<td>
							<?php
							echo \Learnomy\Admin\UX::status_badge( (string) $status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							?>
						</td>
						<td class="lrn-table-actions">
							<?php
							$lrn_row_actions = array();
							if ( $uuid ) {
								$lrn_row_actions[] = array(
									'label'   => __( 'View', 'learnomy' ),
									'href'    => \Learnomy\route_url( 'certificate-verify', array( 'slug' => (string) $uuid ) ),
									'icon'    => 'eye',
									'primary' => true,
									'target'  => '_blank',
								);
							}
							if ( 'active' === $status && $uuid ) {
								$lrn_row_actions[] = array(
									'label'  => __( 'Revoke', 'learnomy' ),
									'icon'   => 'ban',
									'danger' => true,
									'attrs'  => array(
										'class'     => 'lrn-revoke-certificate',
										'data-uuid' => $uuid,
									),
								);
							}
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
		'base_url' => $lrn_paged_base,
	);
	require LEARNOMY_DIR . 'includes/admin/views/partials/pagination.php';
	?>

</div><!-- /.learnomy-admin -->
