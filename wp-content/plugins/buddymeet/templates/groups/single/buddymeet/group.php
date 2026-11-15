<?php
if ( !defined( 'ABSPATH' ) ) exit;

$group = groups_get_current_group();
$group_name = $group ? $group->name : __('Group Meeting', 'buddymeet');
$meet_url = $group ? trailingslashit(bp_get_group_url($group)) . buddymeet_get_slug() . '/' : '';
?>

<div class="group-meet pt-buddymeet-container" style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:16px;box-shadow:0 2px 10px rgba(15,23,42,0.04);">
    <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px;padding-bottom:12px;border-bottom:1px solid #f1f5f9;">
        <div style="display:flex;align-items:center;gap:10px;">
            <span style="width:10px;height:10px;border-radius:9999px;background:#10b981;display:inline-block;"></span>
            <strong style="font-size:15px;color:#0f172a;"><?php echo esc_html($group_name); ?> — <?php esc_html_e('Group Meeting Room', 'buddymeet'); ?></strong>
        </div>
        <?php if ($meet_url) : ?>
            <button type="button"
                onclick="navigator.clipboard && navigator.clipboard.writeText('<?php echo esc_js($meet_url); ?>').then(() => { this.textContent = 'Copied!'; setTimeout(() => { this.textContent = 'Copy Room Link'; }, 2000); });"
                style="padding:7px 14px;border-radius:8px;border:1px solid #cbd5e1;background:#f8fafc;color:#334155;font-size:12.5px;font-weight:600;cursor:pointer;">
                <?php esc_html_e('Copy Room Link', 'buddymeet'); ?>
            </button>
        <?php endif; ?>
    </div>
    <?php buddymeet_render_jitsi_meet(); ?>
</div>
