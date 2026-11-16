<?php
/**
 * BuddyX Child - Follower Chat AJAX & Database Handler
 * File: wp-content/themes/buddyx-child/inc/follower-chat.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'BX_FOLLOWER_CHAT_DB_VER', '1.0.0' );

/**
 * 1. Khởi tạo bảng lưu trữ tin nhắn custom
 */
add_action( 'init', 'bx_follower_chat_create_db' );
function bx_follower_chat_create_db() {
    if ( get_option( 'bx_follower_chat_db_version' ) === BX_FOLLOWER_CHAT_DB_VER ) {
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'bx_chat_messages';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table_name} (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        sender_id BIGINT(20) UNSIGNED NOT NULL,
        recipient_id BIGINT(20) UNSIGNED NOT NULL,
        message TEXT NOT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_users (sender_id, recipient_id, id),
        KEY idx_recipient (recipient_id, is_read)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    update_option( 'bx_follower_chat_db_version', BX_FOLLOWER_CHAT_DB_VER );
}

/**
 * 2. Lấy danh sách Followers của user hiện tại
 */
function bx_follower_chat_get_user_followers( $user_id ) {
    $user_id = (int) $user_id;
    if ( $user_id <= 0 ) {
        return array();
    }

    $followers = array();

    // BuddyPress Follow plugin
    if ( function_exists( 'bp_follow_get_followers' ) ) {
        $followers = bp_follow_get_followers( array( 'user_id' => $user_id ) );
    } elseif ( function_exists( 'bp_get_followers' ) ) {
        $followers = bp_get_followers( $user_id );
    } elseif ( function_exists( 'buddynext_service' ) ) {
        // Hỗ trợ BuddyNext service
        try {
            $follow_svc = buddynext_service( 'follow_service' );
            if ( $follow_svc && method_exists( $follow_svc, 'followers' ) ) {
                $followers = $follow_svc->followers( $user_id );
            }
        } catch ( \Throwable $e ) {}
    } elseif ( function_exists( 'friends_get_friend_user_ids' ) ) {
        // Fallback Friends nếu không kích hoạt Follow
        $followers = friends_get_friend_user_ids( $user_id );
    }

    if ( ! is_array( $followers ) ) {
        return array();
    }

    return array_map( 'intval', array_values( array_unique( $followers ) ) );
}

/**
 * 3. Enqueue script và style
 */
add_action( 'wp_enqueue_scripts', 'bx_follower_chat_enqueue_assets' );
function bx_follower_chat_enqueue_assets() {
    if ( ! is_user_logged_in() ) {
        return;
    }

    $css_path = get_stylesheet_directory() . '/assets/css/follower-chat.css';
    $js_path  = get_stylesheet_directory() . '/assets/js/follower-chat.js';

    if ( file_exists( $css_path ) ) {
        wp_enqueue_style(
            'bx-follower-chat-style',
            get_stylesheet_directory_uri() . '/assets/css/follower-chat.css',
            array(),
            filemtime( $css_path )
        );
    }

    if ( file_exists( $js_path ) ) {
        wp_enqueue_script(
            'bx-follower-chat-script',
            get_stylesheet_directory_uri() . '/assets/js/follower-chat.js',
            array( 'jquery' ),
            filemtime( $js_path ),
            true
        );

        wp_localize_script( 'bx-follower-chat-script', 'bxChatConfig', array(
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( 'bx_follower_chat_nonce' ),
            'currentUserId' => get_current_user_id(),
        ) );
    }
}

/**
 * 4. Chèn popup HTML vào chân trang
 */
add_action( 'wp_footer', 'bx_follower_chat_render_popup' );
function bx_follower_chat_render_popup() {
    if ( ! is_user_logged_in() ) {
        return;
    }
    $popup_template = get_stylesheet_directory() . '/template-parts/chat-popup.php';
    if ( file_exists( $popup_template ) ) {
        include $popup_template;
    }
}

/**
 * 5. AJAX: Tải tin nhắn giữa 2 user
 */
add_action( 'wp_ajax_bx_get_chat_messages', 'bx_ajax_get_chat_messages' );
function bx_ajax_get_chat_messages() {
    check_ajax_referer( 'bx_follower_chat_nonce', 'nonce' );

    $current_user_id = get_current_user_id();
    $recipient_id    = isset( $_POST['recipient_id'] ) ? (int) $_POST['recipient_id'] : 0;
    $last_id         = isset( $_POST['last_id'] ) ? (int) $_POST['last_id'] : 0;

    if ( ! $current_user_id || ! $recipient_id ) {
        wp_send_json_error( array( 'message' => 'Tham số không hợp lệ' ), 400 );
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'bx_chat_messages';

    // Đánh dấu đã đọc tin nhắn gửi đến
    $wpdb->update(
        $table_name,
        array( 'is_read' => 1 ),
        array(
            'sender_id'    => $recipient_id,
            'recipient_id' => $current_user_id,
            'is_read'      => 0,
        ),
        array( '%d' ),
        array( '%d', '%d', '%d' )
    );

    $sql = "SELECT id, sender_id, recipient_id, message, created_at
            FROM {$table_name}
            WHERE ((sender_id = %d AND recipient_id = %d) OR (sender_id = %d AND recipient_id = %d))";

    $params = array( $current_user_id, $recipient_id, $recipient_id, $current_user_id );

    if ( $last_id > 0 ) {
        $sql .= " AND id > %d";
        $params[] = $last_id;
    }

    $sql .= " ORDER BY id ASC LIMIT 100";

    $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

    $messages = array();
    if ( ! empty( $rows ) ) {
        foreach ( $rows as $row ) {
            $messages[] = array(
                'id'        => (int) $row['id'],
                'sender_id' => (int) $row['sender_id'],
                'is_mine'   => ( (int) $row['sender_id'] === $current_user_id ),
                'message'   => stripslashes( $row['message'] ),
                'time'      => mysql2date( 'H:i', $row['created_at'] ),
            );
        }
    }

    wp_send_json_success( array( 'messages' => $messages ) );
}

/**
 * 6. AJAX: Gửi tin nhắn mới
 */
add_action( 'wp_ajax_bx_send_chat_message', 'bx_ajax_send_chat_message' );
function bx_ajax_send_chat_message() {
    check_ajax_referer( 'bx_follower_chat_nonce', 'nonce' );

    $current_user_id = get_current_user_id();
    $recipient_id    = isset( $_POST['recipient_id'] ) ? (int) $_POST['recipient_id'] : 0;
    $message         = isset( $_POST['message'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) ) : '';

    if ( ! $current_user_id || ! $recipient_id || $message === '' ) {
        wp_send_json_error( array( 'message' => 'Nội dung tin nhắn trống' ), 400 );
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'bx_chat_messages';
    $now        = current_time( 'mysql' );

    $inserted = $wpdb->insert(
        $table_name,
        array(
            'sender_id'    => $current_user_id,
            'recipient_id' => $recipient_id,
            'message'      => $message,
            'is_read'      => 0,
            'created_at'   => $now,
        ),
        array( '%d', '%d', '%s', '%d', '%s' )
    );

    if ( ! $inserted ) {
        wp_send_json_error( array( 'message' => 'Không thể lưu tin nhắn' ), 500 );
    }

    wp_send_json_success( array(
        'message' => array(
            'id'        => (int) $wpdb->insert_id,
            'sender_id' => $current_user_id,
            'is_mine'   => true,
            'message'   => $message,
            'time'      => mysql2date( 'H:i', $now ),
        )
    ) );
}
