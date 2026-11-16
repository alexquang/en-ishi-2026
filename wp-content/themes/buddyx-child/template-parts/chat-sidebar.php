<?php
/**
 * Template Part: Chat Sidebar & Popup Box (Facebook-style)
 * File: wp-content/themes/buddyx-child/template-parts/chat-sidebar.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! is_user_logged_in() ) {
    return;
}

$current_user_id = get_current_user_id();

// Lấy danh sách Followers qua hàm tiêu chuẩn của BP Follow / BuddyBoss / Custom Fallback
$followers = array();
if ( function_exists( 'bp_follow_get_followers' ) ) {
    $followers = bp_follow_get_followers( array(
        'user_id' => $current_user_id,
    ) );
} elseif ( function_exists( 'bp_get_followers' ) ) {
    $followers = bp_get_followers( $current_user_id );
} elseif ( function_exists( 'friends_get_friend_user_ids' ) ) {
    $followers = friends_get_friend_user_ids( $current_user_id );
}
?>

<!-- Chat Sidebar Container -->
<div id="bx-chat-sidebar-wrapper" class="bx-chat-sidebar-wrapper">
    <div class="bx-chat-sidebar-header">
        <div class="bx-chat-title">
            <span class="bx-chat-icon">💬</span>
            <span>Followers</span>
        </div>
        <button type="button" class="bx-chat-toggle-btn" id="bx-chat-toggle-sidebar" title="Ẩn/Hiện">_</button>
    </div>

    <div class="bx-chat-followers-list" id="bx-chat-followers-list">
        <?php if ( ! empty( $followers ) && is_array( $followers ) ) : ?>
            <?php foreach ( $followers as $follower_id ) : 
                $follower_id = (int) $follower_id;
                $user_data = get_userdata( $follower_id );
                if ( ! $user_data ) {
                    continue;
                }
                $avatar_url = get_avatar_url( $follower_id, array( 'size' => 64 ) );
                $display_name = ! empty( $user_data->display_name ) ? $user_data->display_name : $user_data->user_login;
            ?>
                <div class="bx-chat-user-item" 
                     data-user-id="<?php echo esc_attr( $follower_id ); ?>" 
                     data-user-name="<?php echo esc_attr( $display_name ); ?>" 
                     data-user-avatar="<?php echo esc_url( $avatar_url ); ?>">
                    <div class="bx-avatar-container">
                        <img src="<?php echo esc_url( $avatar_url ); ?>" alt="<?php echo esc_attr( $display_name ); ?>" class="bx-user-avatar" />
                        <span class="bx-status-dot online"></span>
                    </div>
                    <div class="bx-user-info">
                        <span class="bx-user-name"><?php echo esc_html( $display_name ); ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else : ?>
            <div class="bx-chat-empty">
                Chưa có follower nào.
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Facebook Popup Chat Box -->
<div id="bx-chat-popup-window" class="bx-chat-popup-window" style="display: none;">
    <div class="bx-popup-header">
        <div class="bx-popup-user-meta">
            <div class="bx-popup-avatar-wrap">
                <img id="bx-popup-user-avatar" src="" alt="" class="bx-popup-avatar" />
                <span class="bx-status-dot online"></span>
            </div>
            <span id="bx-popup-user-name" class="bx-popup-name">Chat</span>
        </div>
        <div class="bx-popup-controls">
            <button type="button" id="bx-popup-minimize-btn" title="Thu nhỏ">-</button>
            <button type="button" id="bx-popup-close-btn" title="Đóng">&times;</button>
        </div>
    </div>

    <div class="bx-popup-body" id="bx-popup-messages-container">
        <!-- Messages rendered here -->
    </div>

    <form id="bx-popup-input-form" class="bx-popup-footer">
        <input type="text" id="bx-popup-message-input" placeholder="Nhập tin nhắn..." autocomplete="off" required />
        <button type="submit" id="bx-popup-send-btn">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor">
                <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"></path>
            </svg>
        </button>
    </form>
</div>

<style>
/* CSS Styles for Sidebar & Messenger Box */
.bx-chat-sidebar-wrapper {
    position: fixed;
    bottom: 0;
    right: 20px;
    width: 250px;
    background: #ffffff;
    border: 1px solid #e4e6eb;
    border-bottom: none;
    border-top-left-radius: 8px;
    border-top-right-radius: 8px;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
    z-index: 99990;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
}

.bx-chat-sidebar-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 14px;
    background: #0084ff;
    color: #ffffff;
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    border-top-left-radius: 8px;
    border-top-right-radius: 8px;
}

.bx-chat-toggle-btn {
    background: transparent;
    border: none;
    color: #ffffff;
    font-size: 16px;
    cursor: pointer;
    font-weight: bold;
    padding: 0 4px;
}

.bx-chat-followers-list {
    max-height: 320px;
    overflow-y: auto;
    padding: 6px 0;
}

.bx-chat-user-item {
    display: flex;
    align-items: center;
    padding: 8px 12px;
    cursor: pointer;
    transition: background 0.15s;
}

.bx-chat-user-item:hover {
    background: #f0f2f5;
}

.bx-avatar-container {
    position: relative;
    width: 36px;
    height: 36px;
    margin-right: 10px;
    flex-shrink: 0;
}

.bx-user-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    object-fit: cover;
    display: block;
}

.bx-status-dot {
    position: absolute;
    bottom: 0;
    right: 0;
    width: 9px;
    height: 9px;
    border-radius: 50%;
    border: 1.5px solid #ffffff;
}

.bx-status-dot.online {
    background-color: #31a24c;
}

.bx-user-name {
    font-size: 13px;
    color: #050505;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.bx-chat-empty {
    padding: 15px;
    font-size: 12px;
    color: #65676b;
    text-align: center;
}

/* Facebook-style Popup Window */
.bx-chat-popup-window {
    position: fixed;
    bottom: 0;
    right: 290px;
    width: 320px;
    height: 400px;
    background: #ffffff;
    border: 1px solid #ced0d4;
    border-bottom: none;
    border-top-left-radius: 8px;
    border-top-right-radius: 8px;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
    display: flex;
    flex-direction: column;
    z-index: 99995;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
}

.bx-popup-header {
    height: 44px;
    padding: 0 10px;
    background: #ffffff;
    border-bottom: 1px solid #e4e6eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-top-left-radius: 8px;
    border-top-right-radius: 8px;
}

.bx-popup-user-meta {
    display: flex;
    align-items: center;
    overflow: hidden;
}

.bx-popup-avatar-wrap {
    position: relative;
    width: 30px;
    height: 30px;
    margin-right: 8px;
    flex-shrink: 0;
}

.bx-popup-avatar {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    object-fit: cover;
}

.bx-popup-name {
    font-size: 13px;
    font-weight: 600;
    color: #050505;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.bx-popup-controls button {
    background: transparent;
    border: none;
    font-size: 16px;
    color: #0084ff;
    cursor: pointer;
    padding: 4px;
    line-height: 1;
}

.bx-popup-body {
    flex: 1;
    overflow-y: auto;
    padding: 12px;
    background: #ffffff;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.bx-chat-message-row {
    display: flex;
    flex-direction: column;
    max-width: 75%;
    font-size: 13px;
    line-height: 1.4;
}

.bx-chat-message-row.sent {
    align-self: flex-end;
}

.bx-chat-message-row.sent .bx-msg-bubble {
    background-color: #0084ff;
    color: #ffffff;
    border-top-left-radius: 14px;
    border-top-right-radius: 14px;
    border-bottom-left-radius: 14px;
    border-bottom-right-radius: 4px;
}

.bx-chat-message-row.received {
    align-self: flex-start;
}

.bx-chat-message-row.received .bx-msg-bubble {
    background-color: #f0f2f5;
    color: #050505;
    border-top-left-radius: 14px;
    border-top-right-radius: 14px;
    border-bottom-right-radius: 14px;
    border-bottom-left-radius: 4px;
}

.bx-msg-bubble {
    padding: 8px 12px;
    word-break: break-word;
}

.bx-popup-footer {
    display: flex;
    align-items: center;
    padding: 8px;
    background: #ffffff;
    border-top: 1px solid #e4e6eb;
    margin: 0;
}

.bx-popup-footer input {
    flex: 1;
    border: none;
    background: #f0f2f5;
    border-radius: 16px;
    padding: 8px 12px;
    font-size: 13px;
    outline: none;
}

.bx-popup-footer button {
    background: transparent;
    border: none;
    color: #0084ff;
    margin-left: 6px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 4px;
}
</style>
