<?php
/**
 * Admin coupons view.
 *
 * The create and edit flows share one form. The "Edit" row action populates
 * hidden inputs and flips the form's AJAX action to the update handler.
 *
 * Schema reference (lrn_coupons):
 *   type:       'percent' | 'fixed'
 *   applies_to: 'course'  | 'membership' | 'both'
 *   status:     'active'  | 'inactive'
 *
 * Those vocabularies are authoritative — form options, edit prefill,
 * and the REST/AJAX handlers all use the same values end-to-end.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Coupon;

$paged    = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$per_page = 20;
// Site currency code (ISO 4217 — consistent with transaction-detail and coupon-detail).
$lrn_currency = strtoupper( (string) ( get_option( 'learnomy_settings', array() )['currency'] ?? 'USD' ) );

// Status filter so a long coupon list is navigable (active / inactive).
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$status_filter = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all';
if ( ! in_array( $status_filter, array( 'all', 'active', 'inactive' ), true ) ) {
	$status_filter = 'all';
}
$coupon_where = 'all' === $status_filter ? array() : array( 'status' => $status_filter );

$coupons     = Coupon::where( $coupon_where, 'created_at', 'DESC', $per_page, ( $paged - 1 ) * $per_page );
$total       = Coupon::count( $coupon_where );
$total_pages = (int) ceil( $total / $per_page );
$today       = current_time( 'Y-m-d' );
$coupon_base = admin_url( 'admin.php?page=learnomy-coupons' );
?>
<div class="wrap learnomy-admin">
	<div class="lrn-page-header">
		<div class="lrn-page-header__left">
			<h1 class="lrn-page-header__title">
				<i data-lucide="ticket" class="lrn-page-header__icon"></i>
				<?php esc_html_e( 'Coupons', 'learnomy' ); ?>
			</h1>
		</div>
	</div>

	<?php
	// `wp-header-end` marker — admin notices render below this line.
	// Without it, third-party plugin notices stack at the top of the
	// page and overlap the page header (round 3 audit-rule-8).
	?>
	<hr class="wp-header-end">

	<div class="lrn-admin-card" id="lrn-coupon-form">
		<h2 class="lrn-coupon-form__title"><?php esc_html_e( 'Create New Coupon', 'learnomy' ); ?></h2>
		<form method="post" data-ajax-action="learnomy_create_coupon">

			<div class="lrn-form-grid">
				<div class="lrn-form-field">
					<label for="coupon-code"><?php esc_html_e( 'Code', 'learnomy' ); ?> <span class="required" aria-hidden="true">*</span></label>
					<input type="text" id="coupon-code" name="code" required
							placeholder="<?php esc_attr_e( 'e.g. SUMMER25', 'learnomy' ); ?>">
				</div>
				<div class="lrn-form-field">
					<label for="coupon-type"><?php esc_html_e( 'Discount Type', 'learnomy' ); ?></label>
					<select id="coupon-type" name="type">
						<option value="percent"><?php esc_html_e( 'Percentage (%)', 'learnomy' ); ?></option>
						<option value="fixed"><?php esc_html_e( 'Fixed Amount', 'learnomy' ); ?></option>
					</select>
				</div>
				<div class="lrn-form-field">
					<label for="coupon-value"><?php esc_html_e( 'Value', 'learnomy' ); ?> <span class="required" aria-hidden="true">*</span></label>
					<input type="number" id="coupon-value" name="value" min="0" step="0.01" required>
				</div>
				<div class="lrn-form-field">
					<label for="coupon-applies-to"><?php esc_html_e( 'Applies To', 'learnomy' ); ?></label>
					<select id="coupon-applies-to" name="applies_to">
						<option value="course"><?php esc_html_e( 'Courses', 'learnomy' ); ?></option>
						<option value="membership"><?php esc_html_e( 'Membership Plans', 'learnomy' ); ?></option>
						<?php // 'all', not 'both': the label map and the stored rows already say 'all'. ?>
						<option value="all"><?php esc_html_e( 'Both', 'learnomy' ); ?></option>
					</select>
				</div>
				<div class="lrn-form-field">
					<label for="coupon-status"><?php esc_html_e( 'Status', 'learnomy' ); ?></label>
					<select id="coupon-status" name="status">
						<option value="active"><?php esc_html_e( 'Active', 'learnomy' ); ?></option>
						<option value="inactive"><?php esc_html_e( 'Inactive', 'learnomy' ); ?></option>
					</select>
				</div>
				<div class="lrn-form-field">
					<label for="coupon-usage-limit"><?php esc_html_e( 'Usage Limit', 'learnomy' ); ?></label>
					<input type="number" id="coupon-usage-limit" name="usage_limit" min="0"
							placeholder="<?php esc_attr_e( 'Unlimited', 'learnomy' ); ?>">
				</div>
				<div class="lrn-form-field">
					<label for="coupon-per-user"><?php esc_html_e( 'Per-User Limit', 'learnomy' ); ?></label>
					<input type="number" id="coupon-per-user" name="per_user_limit" min="0"
							placeholder="<?php esc_attr_e( 'Unlimited', 'learnomy' ); ?>">
				</div>
				<div class="lrn-form-field">
					<label for="coupon-min-purchase">
						<?php
						printf(
							/* translators: %s: currency code, e.g. USD */
							esc_html__( 'Min Purchase (%s)', 'learnomy' ),
							esc_html( $lrn_currency )
						);
						?>
					</label>
					<input type="number" id="coupon-min-purchase" name="min_purchase" min="0" step="0.01">
				</div>
				<div class="lrn-form-field">
					<label for="coupon-start"><?php esc_html_e( 'Start Date', 'learnomy' ); ?></label>
					<input type="date" id="coupon-start" name="starts_at" value="<?php echo esc_attr( $today ); ?>">
				</div>
				<div class="lrn-form-field">
					<label for="coupon-expiry"><?php esc_html_e( 'Expiry Date', 'learnomy' ); ?></label>
					<input type="date" id="coupon-expiry" name="expires_at">
				</div>
			</div>

			<div class="lrn-form-field lrn-coupon-scope" data-coupon-course-picker>
				<label for="lrn-coupon-course-search"><?php esc_html_e( 'Restrict to specific courses', 'learnomy' ); ?></label>
				<p class="description"><?php esc_html_e( 'Leave empty to apply to all courses. Add courses to limit this coupon to them only.', 'learnomy' ); ?></p>
				<div class="lrn-coupon-scope__chips" data-scope-chips></div>
				<div class="lrn-coupon-scope__search">
					<input type="text" id="lrn-coupon-course-search" autocomplete="off"
							role="combobox" aria-expanded="false" aria-controls="lrn-coupon-course-results" aria-autocomplete="list"
							placeholder="<?php esc_attr_e( 'Type a course name…', 'learnomy' ); ?>" data-scope-input>
					<ul id="lrn-coupon-course-results" class="lrn-coupon-scope__results" role="listbox" hidden data-scope-results></ul>
				</div>
			</div>

			<div class="lrn-form-actions">
				<button type="submit" class="lrn-admin-btn lrn-admin-btn--primary">
					<?php esc_html_e( 'Create Coupon', 'learnomy' ); ?>
				</button>
				<button type="button" class="lrn-admin-btn lrn-admin-btn--ghost" id="lrn-coupon-cancel-edit" hidden>
					<?php esc_html_e( 'Cancel', 'learnomy' ); ?>
				</button>
			</div>
		</form>
	</div>

	<?php
	// Wrap the status tabs in .lrn-admin-filters so they pick up the Learnomy
	// flex styling (float:none). A bare WP `.subsubsub` floats left, so the
	// table below rendered BESIDE it (indented, "in parallel with the nav")
	// instead of below it (Basecamp #9972830371). No `|` separators — the flex
	// gap spaces the tabs, matching the other admin list pages.
	?>
	<div class="lrn-admin-filters">
		<ul class="subsubsub">
			<?php
			$lrn_coupon_tabs = array(
				'all'      => __( 'All', 'learnomy' ),
				'active'   => __( 'Active', 'learnomy' ),
				'inactive' => __( 'Inactive', 'learnomy' ),
			);
			foreach ( $lrn_coupon_tabs as $lrn_slug => $lrn_label ) :
				?>
				<li>
					<a href="<?php echo esc_url( add_query_arg( 'status', $lrn_slug, $coupon_base ) ); ?>"
						class="<?php echo esc_attr( $status_filter === $lrn_slug ? 'current' : '' ); ?>">
						<?php echo esc_html( $lrn_label ); ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>

	<div class="lrn-admin-table-wrap">
	<table class="lrn-admin-table">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Code', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Type', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Value', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Applies To', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Usage', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Created', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Actions', 'learnomy' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $coupons ) ) : ?>
				<tr>
					<td colspan="8" class="lrn-no-items">
						<?php
						$lrn_empty_state = array(
							'icon'        => 'ticket-percent',
							'title'       => __( 'No coupons yet', 'learnomy' ),
							'description' => __( 'Create your first coupon to give discounts at checkout.', 'learnomy' ),
						);
						include LEARNOMY_DIR . 'includes/admin/views/partials/empty-state.php';
						?>
					</td>
				</tr>
			<?php else : ?>
				<?php
				foreach ( $coupons as $coupon ) :
					$status        = $coupon->status ?? 'active';
					$is_expired    = ! empty( $coupon->expires_at ) && substr( $coupon->expires_at, 0, 10 ) < $today;
					$is_inactive   = 'inactive' === $status;
					$status_slug   = $is_inactive ? 'inactive' : ( $is_expired ? 'expired' : 'active' );
					$status_label  = $is_inactive
						? __( 'Inactive', 'learnomy' )
						: ( $is_expired ? __( 'Expired', 'learnomy' ) : __( 'Active', 'learnomy' ) );
					$usage_label   = isset( $coupon->times_used ) ? (int) $coupon->times_used : 0;
					$limit_label   = $coupon->usage_limit ? (int) $coupon->usage_limit : '∞';
					$value_label   = 'percent' === $coupon->type
						? (int) $coupon->value . '%'
						: \Learnomy\format_price( (float) $coupon->value, $lrn_currency );
					$applies_map   = array(
						'course'     => __( 'Courses', 'learnomy' ),
						'membership' => __( 'Plans', 'learnomy' ),
						'both'       => __( 'Both', 'learnomy' ),
					);
					$applies_label = $applies_map[ $coupon->applies_to ] ?? $coupon->applies_to;
					$type_label    = 'percent' === $coupon->type
						? __( 'Percentage', 'learnomy' )
						: __( 'Fixed', 'learnomy' );
					$created_at    = $coupon->created_at ? \Learnomy\date_label( $coupon->created_at, '—', get_option( 'date_format' ) ) : '—';
					// Edit link carries every editable column so the form can prefill completely.
					$edit_data = array(
						'id'             => (int) $coupon->id,
						'code'           => $coupon->code,
						'type'           => $coupon->type,
						'value'          => $coupon->value,
						'applies_to'     => $coupon->applies_to,
						'status'         => $status,
						'usage_limit'    => $coupon->usage_limit,
						'per_user_limit' => $coupon->per_user_limit,
						'min_purchase'   => $coupon->min_purchase ?? '',
						'starts_at'      => $coupon->starts_at ? substr( $coupon->starts_at, 0, 10 ) : '',
						'expires_at'     => $coupon->expires_at ? substr( $coupon->expires_at, 0, 10 ) : '',
						// Course-scope ids so the editor rehydrates the course chips.
						'items'          => ( ! empty( $coupon->items_json ) && is_array( json_decode( $coupon->items_json, true ) ) )
							? array_map( 'intval', json_decode( $coupon->items_json, true ) )
							: array(),
					);
					?>
					<tr>
						<td>
						<a class="lrn-admin-table__primary" href="
						<?php
						echo esc_url(
							add_query_arg(
								array(
									'page'      => 'learnomy-coupon-detail',
									'coupon_id' => absint( $coupon->id ),
								),
								admin_url( 'admin.php' )
							)
						);
						?>
																	">
							<strong><?php echo esc_html( $coupon->code ); ?></strong>
						</a>
					</td>
						<td><?php echo esc_html( $type_label ); ?></td>
						<td><?php echo esc_html( $value_label ); ?></td>
						<td><?php echo esc_html( $applies_label ); ?></td>
						<td><?php echo esc_html( $usage_label . ' / ' . $limit_label ); ?></td>
						<td>
							<?php
							echo \Learnomy\Admin\UX::status_badge( (string) $status_slug, $status_label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							?>
						</td>
						<td><?php echo esc_html( $created_at ); ?></td>
						<td class="lrn-row-actions">
							<?php
							$row_actions = array(
								array(
									'label'   => __( 'Edit', 'learnomy' ),
									'href'    => '#lrn-coupon-form',
									'icon'    => 'pencil',
									'primary' => true,
									'attrs'   => array(
										'class'       => 'lrn-edit-coupon',
										'data-coupon' => wp_json_encode( $edit_data ),
									),
								),
								array(
									'label'  => __( 'Delete', 'learnomy' ),
									'icon'   => 'trash-2',
									'danger' => true,
									'attrs'  => array(
										'class'         => 'lrn-link-danger',
										'data-action'   => 'learnomy_delete_coupon',
										'data-id'       => $coupon->id,
										'data-id-field' => 'coupon_id',
										'data-confirm'  => __( 'Delete this coupon?', 'learnomy' ),
									),
								),
							);
							echo \Learnomy\Admin\UX::row_actions( $row_actions ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
