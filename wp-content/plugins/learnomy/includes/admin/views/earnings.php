<?php
/**
 * Admin earnings overview view.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Commission;
use Learnomy\Models\Course;
use Learnomy\Models\Subscription;
use Learnomy\Models\Transaction;
use Learnomy\Models\Withdrawal;

$currency = strtoupper( (string) ( get_option( 'learnomy_settings', array() )['currency'] ?? 'USD' ) );

// The shared glyph map. This file used to carry its own copy and feed it
// through the same filter name, so the two disagreed on everything the copy
// had not heard of and a filter written for one applied twice to the other.
$lrn_currency_symbols = \Learnomy\currency_symbols();
$symbol               = $lrn_currency_symbols[ $currency ] ?? $currency . ' ';

$mrr_stats = Subscription::get_mrr_stats();

$now          = current_time( 'mysql', true );
$month_start  = gmdate( 'Y-m-01 00:00:00' );
$last_month_s = gmdate( 'Y-m-01 00:00:00', strtotime( '-1 month' ) );
$last_month_e = gmdate( 'Y-m-t 23:59:59', strtotime( '-1 month' ) );

// phpcs:disable WordPress.Security.NonceVerification.Recommended
$txn_paged    = isset( $_GET['txn_paged'] ) ? max( 1, absint( $_GET['txn_paged'] ) ) : 1;
$txn_per_page = 20;
// phpcs:enable

$recent_transactions = Transaction::where(
	array( 'status' => 'completed' ),
	'created_at',
	'DESC',
	$txn_per_page,
	( $txn_paged - 1 ) * $txn_per_page
);
$txn_total           = Transaction::count( array( 'status' => 'completed' ) );
$txn_total_pages     = (int) ceil( $txn_total / $txn_per_page );

// Platform/instructor split per transaction, batched (the amounts live in
// lrn_commissions, never on lrn_transactions — the old `$txn->platform_amount`
// columns don't exist, so the share columns always showed $0.00).
$lrn_txn_splits = Commission::split_for_transactions(
	array_map(
		static function ( $lrn_t ) {
			return (int) $lrn_t->id;
		},
		$recent_transactions
	)
);

// phpcs:disable WordPress.Security.NonceVerification.Recommended
$wd_paged    = isset( $_GET['wd_paged'] ) ? max( 1, absint( $_GET['wd_paged'] ) ) : 1;
$wd_per_page = 20;
// phpcs:enable

// Paginate the withdrawals list — a busy marketplace can accumulate hundreds
// of requests; loading them all was an unbounded query. The status filter lets
// admins reach processed / rejected history, not just pending.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$wd_status = isset( $_GET['wd_status'] ) ? sanitize_key( wp_unslash( $_GET['wd_status'] ) ) : 'pending';
if ( ! in_array( $wd_status, array( 'pending', 'processed', 'rejected', 'all' ), true ) ) {
	$wd_status = 'pending';
}
$wd_where            = 'all' === $wd_status ? array() : array( 'status' => $wd_status );
$pending_withdrawals = Withdrawal::where(
	$wd_where,
	'requested_at',
	'DESC',
	$wd_per_page,
	( $wd_paged - 1 ) * $wd_per_page
);
$wd_total            = Withdrawal::count( $wd_where );
$wd_total_pages      = (int) ceil( $wd_total / $wd_per_page );

// Revenue cards run off dedicated aggregate queries, NOT the paginated
// $recent_transactions slice. The old code summed only the current page (<=20
// rows) into "lifetime" and left this/last month hardcoded at 0, so every card
// was wrong on any site with real history.
$revenue_lifetime   = Transaction::total_completed_revenue();
$revenue_this_month = Transaction::completed_revenue_in_month( gmdate( 'Y-m' ) );
$revenue_last_month = Transaction::completed_revenue_in_month( gmdate( 'Y-m', strtotime( gmdate( 'Y-m-01' ) . ' -1 month' ) ) );
$pending_payouts    = 0;

// The headline stat is the sum across ALL pending withdrawals, not just the
// current page — a dedicated aggregate query, never a PHP loop over rows.
$pending_total = Withdrawal::sum_pending();
?>
<div class="wrap learnomy-admin">
	<div class="lrn-page-header">
		<div class="lrn-page-header__left">
			<h1 class="lrn-page-header__title">
				<i data-lucide="trending-up" class="lrn-page-header__icon"></i>
				<?php esc_html_e( 'Earnings', 'learnomy' ); ?>
			</h1>
		</div>
		<div class="lrn-page-header__actions">
			<a id="lrn-export-transactions" class="lrn-admin-btn"
				href="<?php echo esc_url( add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), rest_url( 'learnomy/v1/admin/transactions/export' ) ) ); ?>">
				<i data-lucide="download"></i>
				<?php esc_html_e( 'Export CSV', 'learnomy' ); ?>
			</a>
		</div>
	</div>

	<?php
	// `wp-header-end` marker — admin notices render below this line.
	// Without it, third-party plugin notices stack at the top of the
	// page and overlap the page header (round 3 audit-rule-8).
	?>
	<hr class="wp-header-end">

	<!-- Recurring revenue — snapshot of live subscriptions -->
	<h2 class="lrn-admin-section-title">
		<i data-lucide="repeat" class="lrn-icon--sm" aria-hidden="true"></i>
		<?php esc_html_e( 'Recurring revenue', 'learnomy' ); ?>
	</h2>
	<div class="lrn-admin-stats lrn-admin-stats--mrr">
		<div class="lrn-admin-stat-card">
			<div class="lrn-admin-stat-card__icon">
				<i data-lucide="users" aria-hidden="true"></i>
			</div>
			<div class="lrn-admin-stat-card__body">
				<span class="lrn-admin-stat-card__number"><?php echo esc_html( number_format_i18n( (int) $mrr_stats['active_count'] ) ); ?></span>
				<span class="lrn-admin-stat-card__label"><?php esc_html_e( 'Active subscribers', 'learnomy' ); ?></span>
			</div>
		</div>
		<div class="lrn-admin-stat-card">
			<div class="lrn-admin-stat-card__icon">
				<i data-lucide="trending-up" aria-hidden="true"></i>
			</div>
			<div class="lrn-admin-stat-card__body">
				<span class="lrn-admin-stat-card__number"><?php echo esc_html( \Learnomy\format_price( (float) $mrr_stats['monthly_recurring'], $currency ) ); ?></span>
				<span class="lrn-admin-stat-card__label"><?php esc_html_e( 'Monthly recurring', 'learnomy' ); ?></span>
			</div>
		</div>
		<div class="lrn-admin-stat-card">
			<div class="lrn-admin-stat-card__icon">
				<i data-lucide="sparkles" aria-hidden="true"></i>
			</div>
			<div class="lrn-admin-stat-card__body">
				<span class="lrn-admin-stat-card__number"><?php echo esc_html( number_format_i18n( (int) $mrr_stats['trial_conversions_this_month'] ) ); ?></span>
				<span class="lrn-admin-stat-card__label"><?php esc_html_e( 'Trial conversions this month', 'learnomy' ); ?></span>
			</div>
		</div>
	</div>

	<h2 class="lrn-admin-section-title">
		<i data-lucide="dollar-sign" class="lrn-icon--sm" aria-hidden="true"></i>
		<?php esc_html_e( 'Revenue summary', 'learnomy' ); ?>
	</h2>
	<div class="lrn-admin-stats">
		<div class="lrn-admin-stat-card">
			<div class="lrn-admin-stat-card__icon">
				<i data-lucide="calendar" aria-hidden="true"></i>
			</div>
			<div class="lrn-admin-stat-card__body">
				<span class="lrn-admin-stat-card__number"><?php echo esc_html( \Learnomy\format_price( $revenue_this_month, $currency ) ); ?></span>
				<span class="lrn-admin-stat-card__label"><?php esc_html_e( 'This Month', 'learnomy' ); ?></span>
			</div>
		</div>
		<div class="lrn-admin-stat-card">
			<div class="lrn-admin-stat-card__icon">
				<i data-lucide="history" aria-hidden="true"></i>
			</div>
			<div class="lrn-admin-stat-card__body">
				<span class="lrn-admin-stat-card__number"><?php echo esc_html( \Learnomy\format_price( $revenue_last_month, $currency ) ); ?></span>
				<span class="lrn-admin-stat-card__label"><?php esc_html_e( 'Last Month', 'learnomy' ); ?></span>
			</div>
		</div>
		<div class="lrn-admin-stat-card">
			<div class="lrn-admin-stat-card__icon">
				<i data-lucide="bar-chart-3" aria-hidden="true"></i>
			</div>
			<div class="lrn-admin-stat-card__body">
				<span class="lrn-admin-stat-card__number"><?php echo esc_html( \Learnomy\format_price( $revenue_lifetime, $currency ) ); ?></span>
				<span class="lrn-admin-stat-card__label"><?php esc_html_e( 'Lifetime Revenue', 'learnomy' ); ?></span>
			</div>
		</div>
		<div class="lrn-admin-stat-card">
			<div class="lrn-admin-stat-card__icon lrn-admin-stat-card__icon--warn">
				<i data-lucide="clock" aria-hidden="true"></i>
			</div>
			<div class="lrn-admin-stat-card__body">
				<span class="lrn-admin-stat-card__number"><?php echo esc_html( \Learnomy\format_price( $pending_total, $currency ) ); ?></span>
				<span class="lrn-admin-stat-card__label"><?php esc_html_e( 'Pending Withdrawals', 'learnomy' ); ?></span>
			</div>
		</div>
	</div>

	<!-- Commission Rules -->
	<?php
	// Override rules only — the global default now lives in Settings (migration
	// 3.2.0 retired the global CommissionRule), so filter any legacy global row.
	$rules                = array_values(
		array_filter(
			\Learnomy\Models\CommissionRule::where( array(), 'id', 'ASC', 50 ),
			static fn( $r ) => 'global' !== ( $r->rule_type ?? '' )
		)
	);
	$lrn_default_platform = \Learnomy\Services\Commission_Service::get_default_platform_rate();
	$lrn_default_instr    = round( 100 - $lrn_default_platform, 2 );
	$lrn_is_pro           = (bool) apply_filters( 'learnomy_is_pro', defined( 'LEARNOMY_PRO_VERSION' ) );
	// Attach-target: a commission rule scoped to a trashed course can never pay out.
	$lrn_rule_courses     = \Learnomy\Models\Course::selectable();
	$lrn_rule_instructors = \Learnomy\Models\Instructor::admin_list(
		array(
			'per_page' => 500,
			'orderby'  => 'name',
			'order'    => 'ASC',
		)
	)['rows'];
	?>
	<div class="lrn-admin-card lrn-mb-6">
		<h2 class="lrn-admin-card__title">
			<i data-lucide="percent" class="lrn-icon--sm" aria-hidden="true"></i>
			<?php esc_html_e( 'Commission Rules', 'learnomy' ); ?>
		</h2>
		<p class="lrn-admin-card__desc">
			<?php
			printf(
				/* translators: 1: instructor %, 2: platform %, 3: link to Settings */
				esc_html__( 'Default split: instructor %1$s%% / platform %2$s%% (change in %3$s). Add a rule below to override it for a specific course or instructor.', 'learnomy' ),
				esc_html( number_format_i18n( $lrn_default_instr, 2 ) ),
				esc_html( number_format_i18n( $lrn_default_platform, 2 ) ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=learnomy-settings#commissions' ) ) . '">' . esc_html__( 'Settings', 'learnomy' ) . '</a>'
			);
			?>
		</p>

		<form id="lrn-commission-rule-create" class="lrn-commission-rule-create lrn-admin-inline-form">
			<select id="lrn-cr-type" name="rule_type" aria-label="<?php esc_attr_e( 'Rule type', 'learnomy' ); ?>">
				<option value="course"><?php esc_html_e( 'Per course', 'learnomy' ); ?></option>
				<option value="instructor" <?php disabled( ! $lrn_is_pro ); ?>><?php esc_html_e( 'Per instructor (Pro)', 'learnomy' ); ?></option>
			</select>
			<select id="lrn-cr-course" name="course_id" data-entity="course" aria-label="<?php esc_attr_e( 'Course', 'learnomy' ); ?>">
				<option value=""><?php esc_html_e( 'Choose a course…', 'learnomy' ); ?></option>
				<?php foreach ( $lrn_rule_courses as $lrn_c ) : ?>
					<option value="<?php echo absint( $lrn_c->id ); ?>"><?php echo esc_html( (string) $lrn_c->title ); ?></option>
				<?php endforeach; ?>
			</select>
			<select id="lrn-cr-instructor" name="instructor_id" data-entity="instructor" aria-label="<?php esc_attr_e( 'Instructor', 'learnomy' ); ?>" hidden>
				<option value=""><?php esc_html_e( 'Choose an instructor…', 'learnomy' ); ?></option>
				<?php foreach ( $lrn_rule_instructors as $lrn_i ) : ?>
					<option value="<?php echo absint( $lrn_i->user_id ); ?>"><?php echo esc_html( (string) $lrn_i->display_name ); ?></option>
				<?php endforeach; ?>
			</select>
			<label class="lrn-commission-rate-wrap">
				<input type="number" id="lrn-cr-rate" name="instructor_rate" min="0" max="100" step="0.01" class="small-text"
					placeholder="<?php esc_attr_e( 'Instructor %', 'learnomy' ); ?>" required>
				<span class="lrn-commission-rate__unit" aria-hidden="true">%</span>
			</label>
			<button type="submit" class="lrn-admin-btn lrn-admin-btn--primary lrn-admin-btn--sm"><?php esc_html_e( 'Add rule', 'learnomy' ); ?></button>
			<span class="lrn-commission-rule-create__status" role="alert" aria-live="polite" hidden></span>
		</form>
		<div class="lrn-admin-table-wrap">
		<table class="lrn-admin-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Type', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Entity', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Instructor Rate', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'learnomy' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rules ) ) : ?>
					<tr>
						<td colspan="4" class="lrn-no-items">
							<?php
							$lrn_empty_state = array(
								'icon'        => 'percent',
								'title'       => __( 'No override rules yet', 'learnomy' ),
								'description' => __( 'The default split applies to every payout. Use the form above to override it for a specific course or instructor.', 'learnomy' ),
							);
							include LEARNOMY_DIR . 'includes/admin/views/partials/empty-state.php';
							?>
						</td>
					</tr>
				<?php else : ?>
					<?php
					foreach ( $rules as $rule ) :
						$lrn_entity = '';
						if ( 'course' === $rule->rule_type && $rule->entity_id ) {
							$lrn_rule_course = \Learnomy\Models\Course::find( (int) $rule->entity_id );
							$lrn_entity      = $lrn_rule_course ? (string) $lrn_rule_course->title : sprintf( '#%d', $rule->entity_id );
						} elseif ( 'instructor' === $rule->rule_type && $rule->entity_id ) {
							$lrn_rule_user = get_userdata( (int) $rule->entity_id );
							$lrn_entity    = $lrn_rule_user ? (string) $lrn_rule_user->display_name : sprintf( '#%d', $rule->entity_id );
						}
						?>
						<tr>
							<td><?php echo esc_html( \Learnomy\Admin\UX::enum_label( (string) $rule->rule_type, 'rule_type' ) ); ?></td>
							<td><?php echo esc_html( '' !== $lrn_entity ? $lrn_entity : '—' ); ?></td>
							<td>
								<label class="lrn-commission-rate-wrap" for="lrn-commission-rate-<?php echo absint( $rule->id ); ?>">
									<span class="screen-reader-text"><?php esc_html_e( 'Instructor rate (percent)', 'learnomy' ); ?></span>
									<input type="number"
										id="lrn-commission-rate-<?php echo absint( $rule->id ); ?>"
										class="lrn-commission-rate small-text"
										min="0" max="100" step="0.01"
										value="<?php echo esc_attr( (string) ( 100 - (float) ( $rule->platform_rate ?? 30 ) ) ); ?>"
										data-rule-id="<?php echo absint( $rule->id ); ?>">
									<span class="lrn-commission-rate__unit" aria-hidden="true">%</span>
								</label>
							</td>
							<td>
								<button type="button" class="lrn-admin-btn lrn-admin-btn--sm lrn-save-commission"
									data-action="learnomy_update_commission_rule"
									data-id="<?php echo absint( $rule->id ); ?>"
									data-id-field="rule_id">
									<?php esc_html_e( 'Save', 'learnomy' ); ?>
								</button>
								<?php
								// The global rule is the fallback for every course and
								// instructor without an override, so it is edited, never
								// removed — the REST route refuses it too, rather than
								// leaving the guard to the markup alone.
								if ( 'global' !== (string) $rule->rule_type ) :
									?>
									<button type="button" class="lrn-admin-btn lrn-admin-btn--sm lrn-admin-btn--danger"
										data-action="learnomy_delete_commission_rule"
										data-id="<?php echo absint( $rule->id ); ?>"
										data-id-field="rule_id"
										data-confirm="<?php esc_attr_e( 'Remove this commission rule? This entity will fall back to the default rate. Commissions already recorded keep the rate they were paid at.', 'learnomy' ); ?>">
										<?php esc_html_e( 'Delete', 'learnomy' ); ?>
									</button>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		</div>
	</div>

	<!-- Recent Transactions -->
	<div class="lrn-admin-card lrn-mb-6">
		<h2 class="lrn-admin-card__title">
			<i data-lucide="receipt" class="lrn-icon--sm" aria-hidden="true"></i>
			<?php esc_html_e( 'Recent Transactions', 'learnomy' ); ?>
		</h2>
		<div class="lrn-admin-table-wrap">
		<table class="lrn-admin-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Student', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Course', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Amount', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Platform Share', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Instructor Share', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Date', 'learnomy' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $recent_transactions ) ) : ?>
					<tr>
						<td colspan="6" class="lrn-no-items">
							<?php
							$lrn_empty_state = array(
								'icon'        => 'banknote',
								'title'       => __( 'No transactions yet', 'learnomy' ),
								'description' => __( 'Each enrollment, membership purchase, and refund lands here so you can audit revenue at a glance.', 'learnomy' ),
							);
							include LEARNOMY_DIR . 'includes/admin/views/partials/empty-state.php';
							?>
						</td>
					</tr>
				<?php else : ?>
					<?php
					foreach ( $recent_transactions as $txn ) :
						$student     = $txn->user_id ? get_userdata( (int) $txn->user_id ) : null;
						$txn_date    = $txn->created_at ? \Learnomy\date_label( $txn->created_at, '—', get_option( 'date_format' ) ) : '—';
						$course_name = '—';
						if ( ! empty( $txn->course_id ) ) {
							$txn_course  = Course::find( (int) $txn->course_id );
							$course_name = $txn_course ? $txn_course->title : sprintf( '#%d', $txn->course_id );
						}
						?>
						<?php
						// Fee/tax that came off the top before the commission split,
						// frozen on the transaction at calculation time. Read from
						// metadata (already loaded) so the owner can see the real net
						// the split ran on, not just the gross the customer paid.
						$lrn_breakdown = json_decode( (string) ( $txn->metadata_json ?? '' ), true );
						$lrn_breakdown = is_array( $lrn_breakdown ) && isset( $lrn_breakdown['commission_breakdown'] )
							? $lrn_breakdown['commission_breakdown']
							: null;
						$lrn_deducted  = $lrn_breakdown
							? (float) ( $lrn_breakdown['fee'] ?? 0 ) + (float) ( $lrn_breakdown['tax'] ?? 0 )
							: 0.0;
						?>
						<tr>
							<td><?php echo $student ? esc_html( $student->display_name ) : '<em>' . esc_html__( 'Deleted user', 'learnomy' ) . '</em>'; ?></td>
							<td><?php echo esc_html( $course_name ); ?></td>
							<td>
								<?php echo esc_html( \Learnomy\format_price( (float) $txn->amount, $currency ) ); ?>
								<?php if ( $lrn_deducted > 0 ) : ?>
									<span class="description lrn-note-block">
										<?php
										echo esc_html(
											sprintf(
												/* translators: 1: net amount after fee and tax, 2: amount deducted */
												__( 'Net %1$s after %2$s fee/tax', 'learnomy' ),
												\Learnomy\format_price( (float) ( $lrn_breakdown['net_base'] ?? 0 ), $currency ),
												\Learnomy\format_price( $lrn_deducted, $currency )
											)
										);
										?>
									</span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( \Learnomy\format_price( (float) ( $lrn_txn_splits[ (int) $txn->id ]['platform'] ?? 0 ), $currency ) ); ?></td>
							<td><?php echo esc_html( \Learnomy\format_price( (float) ( $lrn_txn_splits[ (int) $txn->id ]['instructor'] ?? 0 ), $currency ) ); ?></td>
							<td><?php echo esc_html( $txn_date ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		</div>

		<?php
		$lrn_pagination = array(
			'current'  => $txn_paged,
			'total'    => $txn_total_pages,
			'results'  => $txn_total,
			'per_page' => $txn_per_page,
			'param'    => 'txn_paged',
			'base_url' => admin_url( 'admin.php?page=learnomy-earnings' ),
		);
		require LEARNOMY_DIR . 'includes/admin/views/partials/pagination.php';
		?>
	</div>

	<!-- Withdrawal Requests -->
	<div class="lrn-admin-card">
		<h2 class="lrn-admin-card__title">
			<i data-lucide="wallet" class="lrn-icon--sm" aria-hidden="true"></i>
			<?php esc_html_e( 'Withdrawal Requests', 'learnomy' ); ?>
		</h2>
		<?php
		$lrn_wd_base = admin_url( 'admin.php?page=learnomy-earnings' );
		$lrn_wd_tabs = array(
			'pending'   => __( 'Pending', 'learnomy' ),
			'processed' => __( 'Processed', 'learnomy' ),
			'rejected'  => __( 'Rejected', 'learnomy' ),
			'all'       => __( 'All', 'learnomy' ),
		);
		?>
		<ul class="subsubsub">
			<?php foreach ( $lrn_wd_tabs as $lrn_slug => $lrn_label ) : ?>
				<li>
					<a href="<?php echo esc_url( add_query_arg( 'wd_status', $lrn_slug, $lrn_wd_base ) ); ?>"
						class="<?php echo esc_attr( $wd_status === $lrn_slug ? 'current' : '' ); ?>">
						<?php echo esc_html( $lrn_label ); ?>
					</a>
					<?php echo 'all' !== $lrn_slug ? ' |' : ''; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<div class="lrn-admin-table-wrap">
		<table class="lrn-admin-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Instructor', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Amount', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Method', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Requested', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'learnomy' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $pending_withdrawals ) ) : ?>
					<tr>
						<td colspan="6" class="lrn-no-items">
							<?php
							$lrn_empty_state = array(
								'icon'        => 'inbox',
								'title'       => __( 'No withdrawal requests here', 'learnomy' ),
								'description' => __( 'Instructors with payable balances submit requests here for you to approve or reject.', 'learnomy' ),
							);
							include LEARNOMY_DIR . 'includes/admin/views/partials/empty-state.php';
							?>
						</td>
					</tr>
				<?php else : ?>
					<?php
					foreach ( $pending_withdrawals as $withdrawal ) :
						$instructor   = $withdrawal->instructor_id ? get_userdata( (int) $withdrawal->instructor_id ) : null;
						$requested_at = $withdrawal->requested_at ? \Learnomy\date_label( $withdrawal->requested_at, '—', get_option( 'date_format' ) ) : '—';
						$wd_detail    = add_query_arg(
							array(
								'page'          => 'learnomy-withdrawal-detail',
								'withdrawal_id' => absint( $withdrawal->id ),
							),
							admin_url( 'admin.php' )
						);
						?>
						<tr>
							<td>
								<a class="lrn-admin-table__primary" href="<?php echo esc_url( $wd_detail ); ?>">
									<?php echo $instructor ? esc_html( $instructor->display_name ) : esc_html__( 'Unknown', 'learnomy' ); ?>
								</a>
							</td>
							<td><?php echo esc_html( \Learnomy\format_price( (float) $withdrawal->amount, $currency ) ); ?></td>
							<td><?php echo esc_html( $withdrawal->method ?? '—' ); ?></td>
							<td><?php echo \Learnomy\Admin\UX::status_badge( (string) ( $withdrawal->status ?? 'pending' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
							<td><?php echo esc_html( $requested_at ); ?></td>
							<td class="lrn-row-actions">
								<?php if ( 'pending' === ( $withdrawal->status ?? 'pending' ) ) : ?>
									<button type="button"
											class="lrn-admin-btn lrn-admin-btn--primary lrn-admin-btn--sm"
											data-action="learnomy_approve_withdrawal"
											data-id="<?php echo esc_attr( $withdrawal->id ); ?>"
											data-id-field="withdrawal_id"
											data-confirm="<?php esc_attr_e( 'Approve this withdrawal?', 'learnomy' ); ?>">
										<?php esc_html_e( 'Approve', 'learnomy' ); ?>
									</button>
									<button type="button"
											class="lrn-admin-btn lrn-admin-btn--sm"
											data-action="learnomy_reject_withdrawal"
											data-id="<?php echo esc_attr( $withdrawal->id ); ?>"
											data-id-field="withdrawal_id"
											data-prompt="<?php esc_attr_e( 'Tell the instructor why this withdrawal was rejected. They will see this reason.', 'learnomy' ); ?>"
											data-prompt-label="<?php esc_attr_e( 'Rejection reason', 'learnomy' ); ?>">
										<?php esc_html_e( 'Reject', 'learnomy' ); ?>
									</button>
								<?php else : ?>
									<a class="lrn-admin-btn lrn-admin-btn--sm" href="<?php echo esc_url( $wd_detail ); ?>">
										<?php esc_html_e( 'View', 'learnomy' ); ?>
									</a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		</div>

		<?php if ( $wd_total_pages > 1 ) : ?>
			<?php
			$lrn_pagination = array(
				'current'  => (int) $wd_paged,
				'total'    => (int) $wd_total_pages,
				'results'  => (int) $wd_total,
				'per_page' => (int) $wd_per_page,
				'base_url' => admin_url( 'admin.php?page=learnomy-earnings' ),
				'param'    => 'wd_paged',
			);
			require LEARNOMY_DIR . 'includes/admin/views/partials/pagination.php';
			?>
		<?php endif; ?>
	</div>
</div>
