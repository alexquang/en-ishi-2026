<?php
/**
 * BuddyNext Profile - Balance Tab Template (Tailwind CSS)
 *
 * @var array  $summary
 * @var array  $orders
 * @var string $last_date
 * @var string $panel_id
 * @var string $active_tab
 * @var string $levels_url
 * @var string $account_url
 */

defined('ABSPATH') || exit;
?>
<div
    class="bn-card pt-bn-balance-panel p-5 sm:p-6 bg-white border border-slate-200/90 rounded-2xl shadow-sm mt-3"
    id="<?php echo esc_attr($panel_id); ?>"
    data-tab-panel="balance"
    data-wp-context='<?php echo esc_attr(wp_json_encode(['tabSlug' => 'balance'])); ?>'
    data-wp-bind--hidden="!state.isActiveTab"
    <?php echo ('balance' === $active_tab) ? '' : 'hidden'; ?>
>
    <!-- Header -->
    <div class="pt-bal-header flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-5 mb-6 border-b border-slate-100">
        <div class="pt-bal-title-wrap flex items-start gap-3.5">
            <div class="pt-bal-icon w-11 h-11 rounded-xl bg-gradient-to-br from-[#012068] to-blue-700 text-white flex items-center justify-center shrink-0 shadow-sm">
                <i class="fa-solid fa-wallet text-base"></i>
            </div>
            <div>
                <h3 class="pt-bal-title text-lg font-bold text-slate-900 m-0 leading-tight">
                    <?php esc_html_e('Account Balance & Deposits', 'buddyx-child'); ?>
                </h3>
                <p class="pt-bal-subtitle text-xs sm:text-sm text-slate-500 mt-1 mb-0">
                    <?php esc_html_e('Summary of your Paid Memberships Pro deposits and payment history.', 'buddyx-child'); ?>
                </p>
            </div>
        </div>

        <div class="pt-bal-actions flex flex-wrap items-center gap-2.5">
            <?php if ($levels_url) : ?>
                <a
                    href="<?php echo esc_url($levels_url); ?>"
                    class="pt-bal-btn-primary inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-xs sm:text-sm font-semibold text-white bg-gradient-to-r from-[#012068] to-[#1a3a8f] hover:brightness-110 shadow-sm transition-all no-underline whitespace-nowrap"
                >
                    <i class="fa-solid fa-plus text-xs"></i>
                    <span><?php esc_html_e('Deposit / Upgrade', 'buddyx-child'); ?></span>
                </a>
            <?php endif; ?>
            <?php if ($account_url) : ?>
                <a
                    href="<?php echo esc_url($account_url); ?>"
                    class="pt-bal-btn-secondary inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-xs sm:text-sm font-semibold text-slate-700 bg-slate-50 hover:bg-slate-100 border border-slate-200 transition-all no-underline whitespace-nowrap"
                >
                    <i class="fa-solid fa-file-invoice-dollar text-xs text-slate-500"></i>
                    <span><?php esc_html_e('Billing Account', 'buddyx-child'); ?></span>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Stats Grid -->
    <div class="pt-bal-stats-grid grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3.5 mb-6">
        <!-- Total Deposited -->
        <div class="pt-bal-stat-card pt-bal-stat-card--deposit rounded-2xl p-4 bg-gradient-to-br from-emerald-50 to-teal-50/70 border border-emerald-200/80 flex flex-col justify-between">
            <div class="pt-bal-stat-top flex items-center justify-between mb-2">
                <span class="pt-bal-stat-label text-xs font-semibold uppercase tracking-wider text-emerald-700">
                    <?php esc_html_e('Total Deposited', 'buddyx-child'); ?>
                </span>
                <span class="pt-bal-stat-badge w-7 h-7 rounded-lg bg-emerald-500/15 text-emerald-700 flex items-center justify-center text-xs">
                    <i class="fa-solid fa-coins"></i>
                </span>
            </div>
            <div class="pt-bal-stat-value text-xl font-extrabold text-emerald-700 tracking-tight">
                <?php echo wp_kses_post($summary['formatted']); ?>
            </div>
        </div>

        <!-- Membership Level -->
        <div class="pt-bal-stat-card pt-bal-stat-card--level rounded-2xl p-4 bg-gradient-to-br from-amber-50 to-orange-50/60 border border-amber-200/80 flex flex-col justify-between">
            <div class="pt-bal-stat-top flex items-center justify-between mb-2">
                <span class="pt-bal-stat-label text-xs font-semibold uppercase tracking-wider text-amber-700">
                    <?php esc_html_e('Membership Level', 'buddyx-child'); ?>
                </span>
                <span class="pt-bal-stat-badge w-7 h-7 rounded-lg bg-amber-500/15 text-amber-600 flex items-center justify-center text-xs">
                    <i class="fa-solid fa-medal"></i>
                </span>
            </div>
            <div class="pt-bal-stat-value text-lg font-bold text-slate-900 truncate">
                <?php echo esc_html($summary['level_name']); ?>
            </div>
        </div>

        <!-- Completed Orders -->
        <div class="pt-bal-stat-card rounded-2xl p-4 bg-slate-50/80 border border-slate-200/80 flex flex-col justify-between">
            <div class="pt-bal-stat-top flex items-center justify-between mb-2">
                <span class="pt-bal-stat-label text-xs font-semibold uppercase tracking-wider text-slate-500">
                    <?php esc_html_e('Completed Orders', 'buddyx-child'); ?>
                </span>
                <span class="pt-bal-stat-badge w-7 h-7 rounded-lg bg-blue-500/10 text-blue-700 flex items-center justify-center text-xs">
                    <i class="fa-solid fa-receipt"></i>
                </span>
            </div>
            <div class="pt-bal-stat-value text-xl font-bold text-slate-900">
                <?php echo esc_html((string) $summary['orders_count']); ?>
            </div>
        </div>

        <!-- Latest Payment -->
        <div class="pt-bal-stat-card rounded-2xl p-4 bg-slate-50/80 border border-slate-200/80 flex flex-col justify-between">
            <div class="pt-bal-stat-top flex items-center justify-between mb-2">
                <span class="pt-bal-stat-label text-xs font-semibold uppercase tracking-wider text-slate-500">
                    <?php esc_html_e('Latest Payment', 'buddyx-child'); ?>
                </span>
                <span class="pt-bal-stat-badge w-7 h-7 rounded-lg bg-indigo-500/10 text-indigo-700 flex items-center justify-center text-xs">
                    <i class="fa-regular fa-calendar-check"></i>
                </span>
            </div>
            <div class="pt-bal-stat-value text-base font-bold text-slate-900">
                <?php echo esc_html($last_date); ?>
            </div>
        </div>
    </div>

    <!-- Payment History -->
    <div>
        <div class="pt-bal-history-head flex items-center justify-between mb-3">
            <h4 class="pt-bal-history-title text-sm font-bold text-slate-800 m-0 flex items-center gap-2">
                <i class="fa-solid fa-clock-rotate-left text-slate-400"></i>
                <span><?php esc_html_e('Recent Payment History', 'buddyx-child'); ?></span>
            </h4>
            <?php if (!empty($orders)) : ?>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-slate-100 text-slate-600">
                    <?php echo esc_html(sprintf(_n('%d order', '%d orders', count($orders), 'buddyx-child'), count($orders))); ?>
                </span>
            <?php endif; ?>
        </div>

        <?php if (!empty($orders)) : ?>
            <div class="pt-bal-table-wrap overflow-x-auto rounded-xl border border-slate-200 bg-white">
                <table class="pt-bal-table w-full text-left border-collapse text-sm">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-200 text-[11px] font-semibold uppercase tracking-wider text-slate-500">
                            <th class="py-3 px-4"><?php esc_html_e('Order', 'buddyx-child'); ?></th>
                            <th class="py-3 px-4"><?php esc_html_e('Date', 'buddyx-child'); ?></th>
                            <th class="py-3 px-4"><?php esc_html_e('Level', 'buddyx-child'); ?></th>
                            <th class="py-3 px-4"><?php esc_html_e('Amount', 'buddyx-child'); ?></th>
                            <th class="py-3 px-4 text-right"><?php esc_html_e('Status', 'buddyx-child'); ?></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        <?php foreach ($orders as $ord) :
                            $ord_amount = function_exists('pmpro_formatPrice')
                                ? pmpro_formatPrice((float) ($ord['total'] ?? 0))
                                : number_format((float) ($ord['total'] ?? 0), 2);
                            $ord_date = !empty($ord['timestamp'])
                                ? date_i18n(get_option('date_format'), strtotime((string) $ord['timestamp']))
                                : '—';
                            $ord_status = !empty($ord['status']) ? strtolower((string) $ord['status']) : 'success';
                            $is_ok      = in_array($ord_status, ['success', 'completed'], true);
                            $badge_cls  = $is_ok
                                ? 'bg-emerald-100 text-emerald-700'
                                : (in_array($ord_status, ['refunded', 'error', 'cancelled'], true)
                                    ? 'bg-rose-100 text-rose-700'
                                    : 'bg-amber-100 text-amber-700');
                            $badge_style = $is_ok
                                ? 'background:#d1fae5;color:#047857;'
                                : (in_array($ord_status, ['refunded', 'error', 'cancelled'], true)
                                    ? 'background:#ffe4e6;color:#be123c;'
                                    : 'background:#fef3c7;color:#b45309;');
                        ?>
                            <tr class="hover:bg-slate-50/80 transition-colors">
                                <td class="py-3 px-4 font-mono text-xs font-semibold text-slate-700">
                                    #<?php echo esc_html((string) ($ord['code'] ?? '')); ?>
                                </td>
                                <td class="py-3 px-4 text-slate-600 whitespace-nowrap">
                                    <?php echo esc_html($ord_date); ?>
                                </td>
                                <td class="py-3 px-4 font-medium text-slate-800">
                                    <?php echo esc_html((string) ($ord['level_name'] ?: '—')); ?>
                                </td>
                                <td class="py-3 px-4 font-bold text-slate-900 whitespace-nowrap">
                                    <?php echo wp_kses_post($ord_amount); ?>
                                </td>
                                <td class="py-3 px-4 text-right">
                                    <span
                                        class="pt-bal-status-pill inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold <?php echo esc_attr($badge_cls); ?>"
                                        style="<?php echo esc_attr($badge_style); ?>"
                                    >
                                        <?php echo esc_html(ucfirst($ord_status)); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else : ?>
            <div class="rounded-xl border border-dashed border-slate-200 bg-slate-50/60 p-8 text-center">
                <div class="w-10 h-10 rounded-full bg-slate-200/70 text-slate-500 flex items-center justify-center mx-auto mb-2.5">
                    <i class="fa-solid fa-receipt"></i>
                </div>
                <p class="text-sm font-medium text-slate-700 m-0">
                    <?php esc_html_e('No payment records yet.', 'buddyx-child'); ?>
                </p>
                <p class="text-xs text-slate-500 mt-1 mb-0">
                    <?php esc_html_e('Your Paid Memberships Pro deposits and orders will appear here.', 'buddyx-child'); ?>
                </p>
            </div>
        <?php endif; ?>
    </div>
</div>
