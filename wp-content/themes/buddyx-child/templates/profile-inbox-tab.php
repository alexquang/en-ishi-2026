<?php
/**
 * BuddyNext Profile - Inbox Tab Template
 *
 * @var string $panel_id
 * @var string $active_tab
 */

defined('ABSPATH') || exit;
?>
<div
    class="bn-card pt-bn-inbox-panel bg-white border border-slate-200/90 rounded-2xl shadow-sm mt-3 overflow-hidden"
    id="<?php echo esc_attr($panel_id); ?>"
    data-tab-panel="inbox"
    data-wp-context='<?php echo esc_attr(wp_json_encode(['tabSlug' => 'inbox'])); ?>'
    data-wp-bind--hidden="!state.isActiveTab"
    <?php echo ('inbox' === $active_tab) ? '' : 'hidden'; ?>
>
    <div class="pt-inbox-shell" id="pt-inbox-app">
        <!-- Left Sidebar: Mutual Followers List -->
        <aside class="pt-inbox-sidebar">
            <div class="pt-inbox-sidebar-header">
                <h3 class="pt-inbox-title"><?php esc_html_e('Inbox', 'buddyx-child'); ?></h3>
                <div class="pt-inbox-search-wrap">
                    <input
                        type="search"
                        id="pt-inbox-search"
                        class="pt-inbox-search"
                        placeholder="<?php esc_attr_e('Search mutual followers...', 'buddyx-child'); ?>"
                        autocomplete="off"
                    />
                </div>
            </div>
            <div class="pt-inbox-conversations" id="pt-inbox-conversations">
                <div class="pt-inbox-loading"><?php esc_html_e('Loading...', 'buddyx-child'); ?></div>
            </div>
        </aside>

        <!-- Right Main: Chat Thread -->
        <section class="pt-inbox-main">
            <div class="pt-inbox-chat-header" id="pt-inbox-chat-header" style="display:none;">
                <div class="pt-inbox-partner-info">
                    <img id="pt-inbox-partner-avatar" src="" alt="" class="pt-inbox-partner-avatar" />
                    <div>
                        <a id="pt-inbox-partner-name" href="#" class="pt-inbox-partner-name"></a>
                        <div id="pt-inbox-partner-handle" class="pt-inbox-partner-handle"></div>
                    </div>
                </div>
            </div>

            <div class="pt-inbox-messages" id="pt-inbox-messages">
                <div class="pt-inbox-empty-state">
                    <p><?php esc_html_e('Select a conversation to start messaging', 'buddyx-child'); ?></p>
                </div>
            </div>

            <div class="pt-inbox-reply-bar" id="pt-inbox-reply-bar" style="display:none;">
                <div class="pt-inbox-reply-bar-content">
                    <span class="pt-inbox-reply-bar-label" id="pt-inbox-reply-label"></span>
                    <span class="pt-inbox-reply-bar-text" id="pt-inbox-reply-text"></span>
                </div>
                <button type="button" class="pt-inbox-reply-cancel" id="pt-inbox-reply-cancel" aria-label="<?php esc_attr_e('Cancel reply', 'buddyx-child'); ?>">&times;</button>
            </div>

            <form class="pt-inbox-composer" id="pt-inbox-composer" style="display:none;">
                <textarea
                    id="pt-inbox-input"
                    class="pt-inbox-input"
                    rows="1"
                    placeholder="<?php esc_attr_e('Type a message...', 'buddyx-child'); ?>"
                ></textarea>
                <button type="submit" id="pt-inbox-send" class="pt-inbox-send-btn">
                    <?php esc_html_e('Send', 'buddyx-child'); ?>
                </button>
            </form>
        </section>
    </div>
</div>
