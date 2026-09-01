<?php
/**
 * Admin reports moderation queue (Pattern B).
 *
 * A light, report-driven queue: members report a review / lesson comment /
 * course, the report lands in lrn_audit_log (type='report'), and a moderator
 * dismisses or acts on it here. Deliberately NOT a keyword/rule engine.
 *
 * Data layer:
 *   - Rows come from AuditLog::get_reports() so pagination stays correct at
 *     scale (the type_created index seeks the report slice).
 *   - Per-tab counts come from AuditLog::count_reports_by_status() — one
 *     GROUP BY for every tab.
 *   - Reporters + reported objects are pre-resolved by render_reports() into
 *     maps so this view stays logic-free (zero DB queries inside foreach).
 *
 * @package Learnomy
 * @var object[]              $reports        Paginated report rows.
 * @var int                   $total          Total rows for current filter.
 * @var int                   $total_pages    Total pages.
 * @var int                   $paged          Current page.
 * @var string                $status_filter  Tab slug (all|open|dismissed|actioned).
 * @var int                   $per_page       Rows per page.
 * @var array<string,int>     $status_counts  Map of status slug => row count.
 * @var array<int,\WP_User>   $reporters      Reporters keyed by user_id.
 * @var array<int,object>     $reviews        Reported review rows keyed by id.
 * @var array<int,object>     $courses        Reported course rows keyed by id.
 * @var array<int,\WP_Comment> $comments      Reported comments keyed by id.
 */

defined( 'ABSPATH' ) || exit;

$base_url = admin_url( 'admin.php?page=learnomy-reports' );

$status_tabs = array(
	'all'       => __( 'All', 'learnomy' ),
	'open'      => __( 'Open', 'learnomy' ),
	'dismissed' => __( 'Dismissed', 'learnomy' ),
	'actioned'  => __( 'Actioned', 'learnomy' ),
);

$status_label_map = array(
	'open'      => __( 'Open', 'learnomy' ),
	'dismissed' => __( 'Dismissed', 'learnomy' ),
	'actioned'  => __( 'Actioned', 'learnomy' ),
);

// Human labels + icons for the reported object type.
$type_labels = array(
	'review'  => __( 'Review', 'learnomy' ),
	'comment' => __( 'Comment', 'learnomy' ),
	'course'  => __( 'Course', 'learnomy' ),
);
$type_icons  = array(
	'review'  => 'star',
	'comment' => 'message-square',
	'course'  => 'book-open',
);
?>
<div class="wrap learnomy-admin">
	<div class="lrn-page-header">
		<div class="lrn-page-header__left">
			<h1 class="lrn-page-header__title">
				<i data-lucide="flag" class="lrn-page-header__icon" aria-hidden="true"></i>
				<?php
				// Read the registered menu label. Hardcoding it printed "Reports",
				// which collides with the REPORTS section header in the same menu
				// (Grading / Analytics / Gradebook) — so clicking "Reported Content"
				// landed on a page that looked like the reporting section.
				echo esc_html( \Learnomy\Admin\UX::page_title( __( 'Reported Content', 'learnomy' ) ) );
				?>
			</h1>
			<p class="lrn-page-header__subtitle">
				<?php esc_html_e( 'Member-flagged reviews, comments, and courses awaiting a moderator.', 'learnomy' ); ?>
			</p>
		</div>
	</div>

	<hr class="wp-header-end">

	<div class="lrn-admin-filters">
		<ul class="subsubsub">
			<?php
			$tab_index = 0;
			$tab_total = count( $status_tabs );
			foreach ( $status_tabs as $slug => $label ) :
				++$tab_index;
				$url        = 'all' === $slug
					? remove_query_arg( 'status', $base_url )
					: add_query_arg( array( 'status' => $slug ), $base_url );
				$is_current = ( $status_filter === $slug );
				$count      = (int) ( $status_counts[ $slug ] ?? 0 );
				?>
				<li>
					<a href="<?php echo esc_url( $url ); ?>"
						class="<?php echo esc_attr( $is_current ? 'current' : '' ); ?>">
						<?php echo esc_html( $label ); ?>
						<span class="count">(<?php echo esc_html( number_format_i18n( $count ) ); ?>)</span>
					</a>
					<?php echo $tab_index < $tab_total ? ' |' : ''; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>

	<div class="lrn-admin-table-wrap">
		<table class="lrn-admin-table" id="lrn-reports-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Reported item', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Type', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Reporter', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Reason', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Date', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'learnomy' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $reports ) ) : ?>
					<tr>
						<td colspan="7" class="lrn-no-items">
							<?php
							$lrn_empty_state = array(
								'icon'        => 'flag',
								'title'       => __( 'No reports to review', 'learnomy' ),
								'description' => __( 'When a member reports a review, comment, or course, it will appear here for moderation.', 'learnomy' ),
							);
							include LEARNOMY_DIR . 'includes/admin/views/partials/empty-state.php';
							?>
						</td>
					</tr>
				<?php else : ?>
					<?php
					foreach ( $reports as $report ) :
						$report_id   = (int) $report->id;
						$object_type = (string) $report->object_type;
						$object_id   = (int) $report->object_id;
						$status      = (string) ( $report->action ?? 'open' );

						$meta   = json_decode( (string) ( $report->metadata_json ?? '' ), true );
						$reason = is_array( $meta ) ? (string) ( $meta['reason'] ?? '' ) : '';

						$reporter      = $reporters[ (int) $report->user_id ] ?? null;
						$reporter_name = $reporter ? (string) $reporter->display_name : __( 'Unknown member', 'learnomy' );

						// Resolve the reported item's label + a link to inspect it,
						// entirely from the pre-loaded maps (no queries here).
						$item_label   = '';
						$item_excerpt = '';
						$item_link    = '';
						switch ( $object_type ) {
							case 'review':
								$review       = $reviews[ $object_id ] ?? null;
								$item_label   = $review && '' !== (string) ( $review->title ?? '' )
									? (string) $review->title
									: sprintf( /* translators: %d: review id */ __( 'Review #%d', 'learnomy' ), $object_id );
								$item_excerpt = $review ? wp_strip_all_tags( (string) ( $review->content ?? '' ) ) : '';
								$item_link    = admin_url( 'admin.php?page=learnomy-reviews' );
								break;
							case 'course':
								$course     = $courses[ $object_id ] ?? null;
								$item_label = $course
									? (string) $course->title
									: sprintf( /* translators: %d: course id */ __( 'Course #%d', 'learnomy' ), $object_id );
								$item_link  = $course
									? add_query_arg(
										array(
											'page'      => 'learnomy-course-builder',
											'course_id' => $object_id,
										),
										admin_url( 'admin.php' )
									)
									: '';
								break;
							case 'comment':
								$comment      = $comments[ $object_id ] ?? null;
								$item_label   = sprintf( /* translators: %d: comment id */ __( 'Comment #%d', 'learnomy' ), $object_id );
								$item_excerpt = $comment ? wp_strip_all_tags( (string) $comment->comment_content ) : '';
								$item_link    = $comment
									? admin_url( 'comment.php?action=editcomment&c=' . $object_id )
									: '';
								break;
						}
						$item_excerpt = '' !== $item_excerpt ? mb_strimwidth( $item_excerpt, 0, 140, '…' ) : '';

						$type_label = $type_labels[ $object_type ] ?? ucfirst( $object_type );
						$type_icon  = $type_icons[ $object_type ] ?? 'flag';
						?>
						<tr data-report-id="<?php echo esc_attr( (string) $report_id ); ?>"
							data-status="<?php echo esc_attr( $status ); ?>">
							<td class="column-item">
								<?php if ( $item_link ) : ?>
									<a href="<?php echo esc_url( $item_link ); ?>"><strong><?php echo esc_html( $item_label ); ?></strong></a>
								<?php else : ?>
									<strong><?php echo esc_html( $item_label ); ?></strong>
								<?php endif; ?>
								<?php if ( '' !== $item_excerpt ) : ?>
									<p class="lrn-report-excerpt"><?php echo esc_html( $item_excerpt ); ?></p>
								<?php endif; ?>
							</td>
							<td class="column-type">
								<span class="lrn-report-type">
									<i data-lucide="<?php echo esc_attr( $type_icon ); ?>" aria-hidden="true"></i>
									<?php echo esc_html( $type_label ); ?>
								</span>
							</td>
							<td class="column-reporter"><?php echo esc_html( $reporter_name ); ?></td>
							<td class="column-reason">
								<?php echo '' !== $reason ? esc_html( $reason ) : '<span class="lrn-muted">' . esc_html__( 'No reason given', 'learnomy' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</td>
							<td class="column-status">
								<?php echo \Learnomy\Admin\UX::status_badge( $status, $status_label_map[ $status ] ?? ucfirst( $status ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</td>
							<td class="column-date">
								<?php
								$created  = (string) ( $report->created_at ?? '' );
								$ts       = $created ? strtotime( $created ) : false;
								$abs_date = $ts ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) : '';
								echo esc_html( $abs_date );
								?>
							</td>
							<td class="column-actions">
								<?php
								$lrn_report_actions = array();
								if ( 'open' === $status ) {
									$lrn_report_actions[] = array(
										'label'   => __( 'Dismiss', 'learnomy' ),
										'icon'    => 'check',
										'primary' => true,
										'attrs'   => array(
											'class'       => 'lrn-report-action',
											'data-action' => 'dismiss',
										),
									);
									$lrn_report_actions[] = array(
										'label' => __( 'Mark actioned', 'learnomy' ),
										'icon'  => 'shield-check',
										'attrs' => array(
											'class'       => 'lrn-report-action',
											'data-action' => 'act',
										),
									);
								}
								if ( $item_link ) {
									$lrn_report_actions[] = array(
										'label' => __( 'View item', 'learnomy' ),
										'icon'  => 'external-link',
										'href'  => $item_link,
									);
								}
								echo \Learnomy\Admin\UX::row_actions( $lrn_report_actions ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
		'base_url' => $base_url,
	);
	require LEARNOMY_DIR . 'includes/admin/views/partials/pagination.php';
	?>
</div>
