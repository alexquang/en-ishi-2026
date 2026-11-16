<?php
/**
 * Follower Chat Feature for BuddyNext Profile (Tab Chat kế bên Balance)
 * File: wp-content/themes/buddyx-child/inc/profile-chat.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'PT_PROFILE_CHAT_DB_VER', '1.0.0' );

/**
 * 1. Khởi tạo bảng tin nhắn trong DB
 */
add_action( 'init', 'pt_profile_chat_init_db' );
function pt_profile_chat_init_db() {
    if ( get_option( 'pt_profile_chat_db_version' ) === PT_PROFILE_CHAT_DB_VER ) {
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'pt_profile_chat_messages';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table_name} (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        sender_id BIGINT(20) UNSIGNED NOT NULL,
        recipient_id BIGINT(20) UNSIGNED NOT NULL,
        message TEXT NOT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_pair (sender_id, recipient_id, id),
        KEY idx_recipient (recipient_id, is_read)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    update_option( 'pt_profile_chat_db_version', PT_PROFILE_CHAT_DB_VER );
}

/**
 * 2. Lấy danh sách Followers của user
 */
function pt_profile_chat_get_followers( int $user_id ): array {
    if ( $user_id <= 0 ) {
        return array();
    }

    $followers = array();

    // 1. Thử qua BuddyNext service ('follows')
    if ( function_exists( 'buddynext_service' ) ) {
        try {
            $follow_svc = buddynext_service( 'follows' );
            if ( $follow_svc && method_exists( $follow_svc, 'followers' ) ) {
                $followers = $follow_svc->followers( $user_id );
            }
        } catch ( \Throwable $e ) {}
    }

    // 2. Query trực tiếp từ bảng bn_follows của BuddyNext
    if ( empty( $followers ) ) {
        global $wpdb;
        $bn_table = $wpdb->prefix . 'bn_follows';
        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $bn_table ) );
        if ( $table_exists === $bn_table ) {
            $rows = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT follower_id FROM {$bn_table} WHERE following_id = %d AND status = 'approved' ORDER BY id DESC",
                    $user_id
                )
            );
            if ( ! empty( $rows ) ) {
                $followers = array_map( 'intval', $rows );
            }
        }
    }

    // 3. BuddyPress Follow plugin
    if ( empty( $followers ) && function_exists( 'bp_follow_get_followers' ) ) {
        $followers = bp_follow_get_followers( array( 'user_id' => $user_id ) );
    } elseif ( empty( $followers ) && function_exists( 'bp_get_followers' ) ) {
        $followers = bp_get_followers( $user_id );
    } elseif ( empty( $followers ) && function_exists( 'friends_get_friend_user_ids' ) ) {
        $followers = friends_get_friend_user_ids( $user_id );
    }

    if ( ! is_array( $followers ) ) {
        return array();
    }

    return array_map( 'intval', array_values( array_unique( $followers ) ) );
}

/**
 * 3. Thêm tab "Chat" kế bên tab "Balance" trong BuddyNext Profile (chính chủ xem trang mình)
 */
add_filter( 'buddynext_nav_items', 'pt_buddynext_add_chat_tab', 25, 2 );
function pt_buddynext_add_chat_tab( $items, $ctx ) {
    if ( ! is_array( $items ) || ! is_object( $ctx ) || ( $ctx->surface ?? '' ) !== 'profile' ) {
        return $items;
    }

    $subject_id = (int) ( $ctx->subject_id ?? 0 );
    $viewer_id  = (int) ( $ctx->viewer_id ?? 0 );

    // Chỉ hiển thị trên trang cá nhân của chính mình (hoặc admin)
    if ( ! $subject_id || ( $subject_id !== $viewer_id && ! current_user_can( 'manage_options' ) ) ) {
        return $items;
    }

    $items[] = array(
        'id'       => 'chat',
        'surface'  => 'profile',
        'layer'    => 'primary',
        'label'    => __( 'Chat', 'buddyx-child' ),
        'tab'      => 'chat',
        'priority' => 95, // Balance là 90, đặt 95 để nằm kế bên Balance
    );

    return $items;
}

/**
 * 4. Render nội dung panel tab "Chat"
 */
add_action( 'buddynext_part_profile_tab_panel_after', 'pt_buddynext_render_chat_panel' );
function pt_buddynext_render_chat_panel( $args ) {
    $profile_user_id = (int) ( $args['profile_user_id'] ?? 0 );
    $viewer_id       = (int) ( $args['viewer_id'] ?? get_current_user_id() );
    $active_tab      = (string) ( $args['active_tab'] ?? 'posts' );

    if ( ! $profile_user_id || ( $profile_user_id !== $viewer_id && ! current_user_can( 'manage_options' ) ) ) {
        return;
    }

    $panel_id = function_exists( 'buddynext_nav_panel_id' ) ? buddynext_nav_panel_id( 'chat' ) : 'bn-panel-chat';
    $followers = pt_profile_chat_get_followers( $profile_user_id );
    $template_path = get_stylesheet_directory() . '/templates/profile-chat-tab.php';

    if ( file_exists( $template_path ) ) {
        include $template_path;
    }
}

/**
 * 5. Enqueue assets CSS & JS cho Chat
 */
add_action( 'wp_enqueue_scripts', 'pt_profile_chat_enqueue_assets' );
function pt_profile_chat_enqueue_assets() {
    if ( ! is_user_logged_in() ) {
        return;
    }

    $css_path = get_stylesheet_directory() . '/assets-enishi/css/pt-chat.css';
    $js_path  = get_stylesheet_directory() . '/assets-enishi/js/pt-chat.js';

    if ( file_exists( $css_path ) ) {
        wp_enqueue_style(
            'pt-chat-style',
            get_stylesheet_directory_uri() . '/assets-enishi/css/pt-chat.css',
            array(),
            filemtime( $css_path )
        );
    }

    if ( file_exists( $js_path ) ) {
        wp_enqueue_script(
            'pt-chat-script',
            get_stylesheet_directory_uri() . '/assets-enishi/js/pt-chat.js',
            array( 'jquery' ),
            filemtime( $js_path ),
            true
        );

        wp_localize_script( 'pt-chat-script', 'ptChatConfig', array(
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( 'pt_profile_chat_nonce' ),
            'currentUserId' => get_current_user_id(),
        ) );
    }
}

/**
 * 6. AJAX: Lấy danh sách tin nhắn giữa 2 user
 */
add_action( 'wp_ajax_pt_chat_get_messages', 'pt_ajax_chat_get_messages' );
function pt_ajax_chat_get_messages() {
    check_ajax_referer( 'pt_profile_chat_nonce', 'nonce' );

    $current_user_id = get_current_user_id();
    $recipient_id    = isset( $_POST['recipient_id'] ) ? (int) $_POST['recipient_id'] : 0;
    $last_id         = isset( $_POST['last_id'] ) ? (int) $_POST['last_id'] : 0;

    if ( ! $current_user_id || ! $recipient_id ) {
        wp_send_json_error( array( 'message' => 'Missing parameter' ), 400 );
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'pt_profile_chat_messages';

    // Đánh dấu đã đọc
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
 * 7. AJAX: Gửi tin nhắn mới
 */
add_action( 'wp_ajax_pt_chat_send_message', 'pt_ajax_chat_send_message' );
function pt_ajax_chat_send_message() {
    check_ajax_referer( 'pt_profile_chat_nonce', 'nonce' );

    $current_user_id = get_current_user_id();
    $recipient_id    = isset( $_POST['recipient_id'] ) ? (int) $_POST['recipient_id'] : 0;
    $raw_message     = isset( $_POST['message'] ) ? wp_unslash( $_POST['message'] ) : '';
    $message         = trim( sanitize_textarea_field( $raw_message ) );

    if ( ! $current_user_id || ! $recipient_id || $message === '' ) {
        wp_send_json_error( array( 'message' => 'Nội dung tin nhắn trống' ), 400 );
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'pt_profile_chat_messages';
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
        wp_send_json_error( array( 'message' => 'Lỗi lưu tin nhắn' ), 500 );
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
