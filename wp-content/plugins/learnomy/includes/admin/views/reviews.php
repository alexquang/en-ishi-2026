<?php
/**
 * Admin reviews moderation list view (Pattern B).
 *
 * Data layer:
 *   - Rows come from Review::get_paginated_admin() so the LIKE search hits
 *     the DB tier and pagination stays correct at 2000+ rows.
 *   - Status totals come from Review::count_by_status() — single GROUP BY
 *     query for every tab count.
 *   - Authors + courses are pre-resolved by render_reviews() so the view
 *     stays logic-free (zero DB queries inside foreach).
 *
 * @package Learnomy
 * @var object[]              $reviews        Paginated review rows.
 * @var int                   $total          Total rows for current filter.
 * @var int                   $total_pages    Total pages.
 * @var int                   $paged          Current page.
 * @var string                $status_filter  Status tab slug (all|pending|approved|rejected|spam).
 * @var string                $search         Search query.
 * @var string                $sort           date_desc | date_asc.
 * @var int                   $per_page       Rows per page.
 * @var array<string,int>     $status_counts  Map of status slug => row count.
 * @var array<int,\WP_User>   $authors        Pre-loaded display authors keyed by user_id.
 * @var array<int,object>     $courses        Pre-loaded courses keyed by id.
 */

defined( 'ABSPATH' ) || exit;

$base_url = admin_url( 'admin.php?page=learnomy-reviews' );

$status_tabs = array(
	'all'      => __( 'All', 'learnomy' ),
	'pending'  => __( 'Pending', 'learnomy' ),
	'approved' => __( 'Approved', 'learnomy' ),
	'rejected' => __( 'Rejected', 'learnomy' ),
	'spam'     => __( 'Spam', 'learnomy' ),
);

// Custom labels passed to UX::status_badge() so the canonical pill shows the
// review-domain wording (the helper owns the colour variant).
$status_label_map = array(
	'pending'    => __( 'Pending', 'learnomy' ),
	'approved'   => __( 'Approved', 'learnomy' ),
	'rejected'   => __( 'Rejected', 'learnomy' ),
	'spam'       => __( 'Spam', 'learnomy' ),
	'anonymized' => __( 'Anonymized', 'learnomy' ),
);
?>
<div class="wrap learnomy-admin">
	<div class="lrn-page-header">
		<div class="lrn-page-header__left">
			<h1 class="lrn-page-header__title">
				<i data-lucide="message-square-text" class="lrn-page-header__icon" aria-hidden="true"></i>
				<?php esc_html_e( 'Reviews', 'learnomy' ); ?>
			</h1>
			<p class="lrn-page-header__subtitle">
				<?php esc_html_e( 'Moderate course reviews submitted by students.', 'learnomy' ); ?>
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

		<form method="get" action="<?php echo esc_url( $base_url ); ?>" class="lrn-search-form">
			<input type="hidden" name="page" value="learnomy-reviews">
			<?php if ( 'all' !== $status_filter ) : ?>
				<input type="hidden" name="status" value="<?php echo esc_attr( $status_filter ); ?>">
			<?php endif; ?>
			<?php if ( 'date_desc' !== $sort ) : ?>
				<input type="hidden" name="sort" value="<?php echo esc_attr( $sort ); ?>">
			<?php endif; ?>
			<input type="search" name="s" aria-label="<?php esc_attr_e( 'Search', 'learnomy' ); ?>" value="<?php echo esc_attr( $search ); ?>"
					placeholder="<?php esc_attr_e( 'Search reviews', 'learnomy' ); ?>" class="regular-text">
			<button type="submit" class="lrn-admin-btn"><?php esc_html_e( 'Search', 'learnomy' ); ?></button>
		</form>

		<?php
		$sort_toggle_value = 'date_desc' === $sort ? 'date_asc' : 'date_desc';
		$sort_toggle_url   = add_query_arg( array( 'sort' => $sort_toggle_value ), $base_url );
		if ( 'all' !== $status_filter ) {
			$sort_toggle_url = add_query_arg( array( 'status' => $status_filter ), $sort_toggle_url );
		}
		if ( '' !== $search ) {
			$sort_toggle_url = add_query_arg( array( 's' => $search ), $sort_toggle_url );
		}
		?>
		<a class="lrn-admin-btn lrn-admin-btn--ghost" href="<?php echo esc_url( $sort_toggle_url ); ?>">
			<i data-lucide="<?php echo 'date_desc' === $sort ? 'arrow-down' : 'arrow-up'; ?>" aria-hidden="true"></i>
			<?php
			echo esc_html(
				'date_desc' === $sort
					? __( 'Newest first', 'learnomy' )
					: __( 'Oldest first', 'learnomy' )
			);
			?>
		</a>
	</div>

		<div class="lrn-bulk-bar lrn-bulk-bar--hidden">
			<div class="lrn-bulk-bar__left">
				<span class="lrn-bulk-bar__count">0 <?php esc_html_e( 'selected', 'learnomy' ); ?></span>
				<select id="lrn-bulk-action" aria-label="<?php esc_attr_e( 'Bulk action', 'learnomy' ); ?>">
					<option value=""><?php esc_html_e( 'Bulk actions', 'learnomy' ); ?></option>
					<option value="approve"><?php esc_html_e( 'Approve', 'learnomy' ); ?></option>
					<option value="reject"><?php esc_html_e( 'Reject', 'learnomy' ); ?></option>
					<option value="spam"><?php esc_html_e( 'Mark as spam', 'learnomy' ); ?></option>
					<option value="pending"><?php esc_html_e( 'Mark as pending', 'learnomy' ); ?></option>
					<option value="delete"><?php esc_html_e( 'Delete', 'learnomy' ); ?></option>
				</select>
				<button type="button" id="lrn-bulk-apply" class="lrn-admin-btn lrn-admin-btn--sm">
					<?php esc_html_e( 'Apply', 'learnomy' ); ?>
				</button>
			</div>
		</div>
	<div class="lrn-admin-table-wrap">
		<table class="lrn-admin-table" id="lrn-reviews-table">
			<thead>
				<tr>
					<th class="lrn-col-check"><input type="checkbox" id="lrn-select-all" aria-label="<?php esc_attr_e( 'Select all', 'learnomy' ); ?>"></th>
					<th scope="col"><?php esc_html_e( 'Review', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Author', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Course', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Rating', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Date', 'learnomy' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $reviews ) ) : ?>
					<tr>
						<td colspan="7" class="lrn-no-items">
							<?php
							$lrn_empty_state = array(
								'icon'        => 'message-square-text',
								'title'       => '' !== $search
									? __( 'No reviews match this search', 'learnomy' )
									: __( 'No reviews to moderate', 'learnomy' ),
								'description' => '' !== $search
									? __( 'Try a different keyword or clear the search.', 'learnomy' )
									: __( 'New student reviews will appear here for moderation.', 'learnomy' ),
							);
							include LEARNOMY_DIR . 'includes/admin/views/partials/empty-state.php';
							?>
						</td>
					</tr>
				<?php else : ?>
					<?php
					foreach ( $reviews as $review ) :
						$review_id      = (int) $review->id;
						$author         = $authors[ (int) $review->user_id ] ?? null;
						$author_name    = $author ? (string) $author->display_name : __( 'Unknown user', 'learnomy' );
						$course         = $courses[ (int) $review->course_id ] ?? null;
						$course_title   = $course ? (string) $course->title : sprintf( /* translators: %d: course id */ __( 'Course #%d', 'learnomy' ), (int) $review->course_id );
						$status         = (string) ( $review->status ?? 'pending' );
						$status_label   = $status_label_map[ $status ] ?? ucfirst( $status );
						$rating         = max( 0, min( 5, (int) $review->rating ) );
						$review_title   = (string) ( $review->title ?? '' );
						$review_text    = wp_strip_all_tags( (string) ( $review->content ?? '' ) );
						$review_excerpt = mb_strimwidth( $review_text, 0, 180, '…' );

						$author_link = '';
						if ( $author ) {
							$author_link = add_query_arg(
								array( 'user_id' => (int) $author->ID ),
								admin_url( 'user-edit.php' )
							);
						}

						$course_link = $course
							? add_query_arg(
								array(
									'page'      => 'learnomy-course-builder',
									'course_id' => (int) $course->id,
								),
								admin_url( 'admin.php' )
							)
							: '';
						?>
						<tr data-review-id="<?php echo esc_attr( (string) $review_id ); ?>"
							data-status="<?php echo esc_attr( $status ); ?>">
							<td class="lrn-col-check">
								<input type="checkbox" class="lrn-row-check" value="<?php echo esc_attr( (string) $review_id ); ?>" aria-label="<?php esc_attr_e( 'Select review', 'learnomy' ); ?>">
							</td>
							<td class="column-review">
								<?php if ( '' !== $review_title ) : ?>
									<strong class="lrn-review-title"><?php echo esc_html( $review_title ); ?></strong>
								<?php endif; ?>
								<p class="lrn-review-excerpt"><?php echo esc_html( $review_excerpt ); ?></p>
								<?php if ( mb_strlen( $review_text ) > mb_strlen( $review_excerpt ) ) : ?>
									<details class="lrn-review-fulltext">
										<summary><?php esc_html_e( 'Read full review', 'learnomy' ); ?></summary>
										<p class="lrn-review-fulltext__body"><?php echo esc_html( $review_text ); ?></p>
									</details>
								<?php endif; ?>
								<?php $lrn_existing_reply = trim( (string) ( $review->instructor_reply ?? '' ) ); ?>
								<?php if ( '' !== $lrn_existing_reply ) : ?>
									<p class="lrn-review-reply" data-role="review-reply-current">
										<strong><?php esc_html_e( 'Your reply:', 'learnomy' ); ?></strong>
										<span data-role="review-reply-text"><?php echo esc_html( $lrn_existing_reply ); ?></span>
									</p>
								<?php endif; ?>
								<div class="lrn-review-reply-form" data-role="review-reply-form" hidden>
									<label class="screen-reader-text" for="lrn-review-reply-<?php echo esc_attr( (string) $review_id ); ?>"><?php esc_html_e( 'Reply to this review', 'learnomy' ); ?></label>
									<textarea id="lrn-review-reply-<?php echo esc_attr( (string) $review_id ); ?>" class="lrn-review-reply-input" rows="2" maxlength="<?php echo esc_attr( (string) \Learnomy\Models\Review::MAX_REPLY_LENGTH ); ?>" placeholder="<?php esc_attr_e( 'Write a public reply to this review…', 'learnomy' ); ?>"><?php echo esc_textarea( $lrn_existing_reply ); ?></textarea>
									<div class="lrn-review-reply-form__actions">
										<button type="button" class="lrn-admin-btn lrn-admin-btn--primary lrn-admin-btn--sm" data-action="reply-save"><?php esc_html_e( 'Save reply', 'learnomy' ); ?></button>
										<button type="button" class="lrn-admin-btn lrn-admin-btn--ghost lrn-admin-btn--sm" data-action="reply-cancel"><?php esc_html_e( 'Cancel', 'learnomy' ); ?></button>
									</div>
								</div>
								<?php
								$lrn_review_actions = array();
								if ( 'approved' !== $status ) {
									$lrn_review_actions[] = array(
										'label'   => __( 'Approve', 'learnomy' ),
										'icon'    => 'check',
										'primary' => true,
										'attrs'   => array(
											'class'       => 'lrn-review-action',
											'data-action' => 'approve',
										),
									);
								}
								if ( 'rejected' !== $status ) {
									$lrn_review_actions[] = array(
										'label' => __( 'Reject', 'learnomy' ),
										'icon'  => 'x',
										'attrs' => array(
											'class'       => 'lrn-review-action',
											'data-action' => 'reject',
										),
									);
								}
								if ( 'spam' !== $status ) {
									$lrn_review_actions[] = array(
										'label' => __( 'Spam', 'learnomy' ),
										'icon'  => 'shield-alert',
										'attrs' => array(
											'class'       => 'lrn-review-action',
											'data-action' => 'spam',
										),
									);
								}
								if ( 'pending' !== $status ) {
									$lrn_review_actions[] = array(
										'label' => __( 'Mark Pending', 'learnomy' ),
										'icon'  => 'clock',
										'attrs' => array(
											'class'       => 'lrn-review-action',
											'data-action' => 'pending',
										),
									);
								}
								$lrn_review_actions[] = array(
									'label' => '' !== $lrn_existing_reply ? __( 'Edit reply', 'learnomy' ) : __( 'Reply', 'learnomy' ),
									'icon'  => 'reply',
									'attrs' => array(
										'class'       => 'lrn-review-action',
										'data-action' => 'reply',
									),
								);
								$lrn_review_actions[] = array(
									'label'  => __( 'Delete', 'learnomy' ),
									'icon'   => 'trash-2',
									'danger' => true,
									'attrs'  => array(
										'class'       => 'lrn-link-danger lrn-review-action',
										'data-action' => 'delete',
									),
								);
								echo \Learnomy\Admin\UX::row_actions( $lrn_review_actions, array( 'inline_max' => 2 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								?>
							</td>
							<td class="column-author">
								<?php if ( $author_link ) : ?>
									<a href="<?php echo esc_url( $author_link ); ?>"><?php echo esc_html( $author_name ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $author_name ); ?>
								<?php endif; ?>
							</td>
							<td class="column-course">
								<?php if ( $course_link ) : ?>
									<a href="<?php echo esc_url( $course_link ); ?>"><?php echo esc_html( $course_title ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $course_title ); ?>
								<?php endif; ?>
							</td>
							<td class="column-rating">
								<?php
								/* translators: %d: rating out of 5 */
								$rating_label = sprintf( __( '%d out of 5', 'learnomy' ), $rating );
								?>
								<span class="lrn-review-rating" aria-label="<?php echo esc_attr( $rating_label ); ?>">
									<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
										<i data-lucide="star"
											class="lrn-review-star <?php echo $i <= $rating ? 'lrn-review-star--filled' : ''; ?>"
											aria-hidden="true"></i>
									<?php endfor; ?>
								</span>
							</td>
							<td class="column-status">
								<?php echo \Learnomy\Admin\UX::status_badge( $status, $status_label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</td>
							<td class="column-date">
								<?php
								$created  = (string) ( $review->created_at ?? '' );
								$ts       = $created ? strtotime( $created ) : false;
								$abs_date = $ts ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) : '';
								echo esc_html( $abs_date );
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>

	<?php if ( ! empty( $reviews ) ) : ?>
	<?php endif; ?>

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
