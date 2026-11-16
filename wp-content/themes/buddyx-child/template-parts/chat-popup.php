<?php
/**
 * Template: Facebook-style Popup Chat Window
 * File: wp-content/themes/buddyx-child/template-parts/chat-popup.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<div id="bx-messenger-popup" class="bx-messenger-popup" style="display: none;">
    <!-- Popup Header -->
    <div class="bx-messenger-header">
        <div class="bx-messenger-user-meta">
            <div class="bx-messenger-avatar-wrap">
                <img id="bx-messenger-avatar" src="" alt="" class="bx-messenger-avatar" />
                <span class="bx-messenger-status-dot"></span>
            </div>
            <div class="bx-messenger-title-wrap">
                <span id="bx-messenger-name" class="bx-messenger-name"><?php esc_html_e( 'Trò chuyện', 'buddyx-child' ); ?></span>
                <span class="bx-messenger-status-text"><?php esc_html_e( 'Đang hoạt động', 'buddyx-child' ); ?></span>
            </div>
        </div>
        <div class="bx-messenger-header-actions">
            <button type="button" id="bx-messenger-min-btn" class="bx-messenger-ctrl-btn" title="<?php esc_attr_e( 'Thu nhỏ', 'buddyx-child' ); ?>">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
            </button>
            <button type="button" id="bx-messenger-close-btn" class="bx-messenger-ctrl-btn" title="<?php esc_attr_e( 'Đóng', 'buddyx-child' ); ?>">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </button>
        </div>
    </div>

    <!-- Messages Container -->
    <div id="bx-messenger-body" class="bx-messenger-body">
        <div class="bx-messenger-loading"><?php esc_html_e( 'Đang tải tin nhắn...', 'buddyx-child' ); ?></div>
    </div>

    <!-- Message Input Footer -->
    <form id="bx-messenger-form" class="bx-messenger-footer">
        <input type="text" 
               id="bx-messenger-input" 
               class="bx-messenger-input" 
               placeholder="<?php esc_attr_e( 'Nhập tin nhắn...', 'buddyx-child' ); ?>" 
               autocomplete="off" 
               required />
        <button type="submit" id="bx-messenger-send-btn" class="bx-messenger-send-btn" title="<?php esc_attr_e( 'Gửi', 'buddyx-child' ); ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
            </svg>
        </button>
    </form>
</div>
