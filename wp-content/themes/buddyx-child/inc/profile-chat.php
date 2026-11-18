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

    // Kiểm tra user có tài khoản trả phí hay không
    $is_paid = false;
    if ( current_user_can( 'manage_options' ) ) {
        $is_paid = true;
    } elseif ( function_exists( 'pt_user_has_paid_pmpro' ) ) {
        $is_paid = pt_user_has_paid_pmpro( $current_user_id );
    }

    $upgrade_url = function_exists( 'pmpro_url' ) ? pmpro_url( 'levels' ) : home_url( '/membership-levels/' );

    // Xác định ID tin nhắn cũ nhất mà user khác gửi cho mình (sender_id = $recipient_id, recipient_id = $current_user_id)
    $first_incoming_msg_id = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM {$table_name} 
             WHERE sender_id = %d AND recipient_id = %d 
             ORDER BY id ASC LIMIT 1",
            $recipient_id,
            $current_user_id
        )
    );

    $messages = array();
    if ( ! empty( $rows ) ) {
        foreach ( $rows as $row ) {
            $msg_id  = (int) $row['id'];
            $is_mine = ( (int) $row['sender_id'] === $current_user_id );

            // Tài khoản Free:
            // - Tin do chính mình gửi ($is_mine): luôn xem được
            // - Tin user khác gửi cho mình (! $is_mine): CHỈ hiển thị tin nhắn cũ nhất ($first_incoming_msg_id), còn lại bị che
            $is_first_incoming = ( $msg_id === $first_incoming_msg_id );
            $locked            = ( ! $is_paid && ! $is_mine && ! $is_first_incoming );

            $messages[] = array(
                'id'        => $msg_id,
                'sender_id' => (int) $row['sender_id'],
                'is_mine'   => $is_mine,
                'locked'    => $locked,
                'message'   => $locked ? '' : stripslashes( $row['message'] ),
                'time'      => mysql2date( 'H:i', $row['created_at'] ),
            );
        }
    }

    wp_send_json_success( array(
        'messages'    => $messages,
        'is_paid'     => $is_paid,
        'upgrade_url' => $upgrade_url,
    ) );
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

    $msg_id      = (int) $wpdb->insert_id;
    $sender_user = get_userdata( $current_user_id );
    $sender_name = $sender_user ? ( $sender_user->display_name ?: $sender_user->user_login ) : 'Someone';
    $notif_text  = sprintf( 'You have a new message from %s: "%s"', $sender_name, wp_trim_words( $message, 10, '...' ) );
    $chat_url    = function_exists('pt_get_user_messages_profile_url') 
        ? pt_get_user_messages_profile_url($recipient_id) 
        : (function_exists( 'buddynext_profile_url' ) 
            ? add_query_arg( 'tab', 'chat', trailingslashit( buddynext_profile_url( $recipient_id ) ) ) 
            : home_url( '/members/' . ( $sender_user ? $sender_user->user_login : '' ) . '/messages/' ));

    // 1. Tạo Notification trong BuddyNext
    if ( function_exists( 'buddynext_service' ) ) {
        try {
            $notif_svc = buddynext_service( 'notifications' );
            if ( is_object( $notif_svc ) && method_exists( $notif_svc, 'create' ) ) {
                $notif_svc->create( array(
                    'recipient_id' => $recipient_id,
                    'actor_id'     => $current_user_id,
                    'sender_id'    => $current_user_id,
                    'type'         => 'message',
                    'object_type'  => 'profile_chat',
                    'object_id'    => $msg_id,
                    'data'         => array(
                        'url'     => $chat_url,
                        'message' => $notif_text,
                    ),
                    'url'          => $chat_url,
                    'title'        => 'New message',
                    'message'      => $notif_text,
                    'body'         => $notif_text,
                ) );
            }
        } catch ( \Throwable $e ) {}
    }

    // 2. Tạo Notification trong BuddyPress nếu có
    if ( function_exists( 'bp_notifications_add_notification' ) ) {
        bp_notifications_add_notification( array(
            'user_id'           => $recipient_id,
            'item_id'           => $msg_id,
            'secondary_item_id' => $current_user_id,
            'component_name'    => 'messages',
            'component_action'  => 'new_chat_message',
            'date_notified'     => $now,
            'is_new'            => 1,
        ) );
    }

    wp_send_json_success( array(
        'message' => array(
            'id'        => $msg_id,
            'sender_id' => $current_user_id,
            'is_mine'   => true,
            'message'   => $message,
            'time'      => mysql2date( 'H:i', $now ),
        )
    ) );
}

/**
 * 8. AJAX Polling kiểm tra tin nhắn mới & thông báo realtime dạng Toast Popup
 */
add_action( 'wp_ajax_pt_chat_poll_incoming', 'pt_ajax_chat_poll_incoming' );
function pt_ajax_chat_poll_incoming() {
    check_ajax_referer( 'pt_profile_chat_nonce', 'nonce' );

    $current_user_id = get_current_user_id();
    if ( ! $current_user_id ) {
        wp_send_json_error( array( 'message' => 'Unauthorized' ), 401 );
    }

    $last_check = isset( $_POST['last_id'] ) ? (int) $_POST['last_id'] : 0;

    global $wpdb;
    $table_name = $wpdb->prefix . 'pt_profile_chat_messages';

    $sql = "SELECT m.id, m.sender_id, m.message, m.created_at, u.display_name, u.user_login
            FROM {$table_name} m
            LEFT JOIN {$wpdb->users} u ON u.ID = m.sender_id
            WHERE m.recipient_id = %d AND m.is_read = 0";

    $params = array( $current_user_id );

    if ( $last_check > 0 ) {
        $sql .= " AND m.id > %d";
        $params[] = $last_check;
    }

    $sql .= " ORDER BY m.id DESC LIMIT 5";

    $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

    $notifications = array();
    if ( ! empty( $rows ) ) {
        foreach ( $rows as $r ) {
            $sender_name = $r['display_name'] ?: ( $r['user_login'] ?: 'Someone' );
            $sender_id   = (int) $r['sender_id'];
            $notifications[] = array(
                'id'          => (int) $r['id'],
                'sender_id'   => $sender_id,
                'sender_name' => $sender_name,
                'sender_avatar' => get_avatar_url( $sender_id, array( 'size' => 64 ) ),
                'message'     => wp_trim_words( stripslashes( $r['message'] ), 8, '...' ),
                'notice'      => sprintf( 'You have a new message from %s', $sender_name ),
                'created_at'  => $r['created_at'],
            );
        }
    }

    wp_send_json_success( array(
        'notifications' => $notifications,
    ) );
}

/**
 * 9. AJAX Search all users (kể cả chưa follow) để có thể chat
 */
add_action( 'wp_ajax_pt_chat_search_users', 'pt_ajax_chat_search_users' );
function pt_ajax_chat_search_users() {
    check_ajax_referer( 'pt_profile_chat_nonce', 'nonce' );

    $current_user_id = get_current_user_id();
    if ( ! $current_user_id ) {
        wp_send_json_error( array( 'message' => 'Unauthorized' ), 401 );
    }

    $term = isset( $_POST['term'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['term'] ) ) ) : '';
    if ( $term === '' ) {
        wp_send_json_success( array( 'users' => array() ) );
    }

    global $wpdb;
    $like = '%' . $wpdb->esc_like( $term ) . '%';

    $users = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT ID, user_login, display_name 
             FROM {$wpdb->users} 
             WHERE ID != %d 
               AND (user_login LIKE %s OR display_name LIKE %s)
             ORDER BY display_name ASC 
             LIMIT 20",
            $current_user_id,
            $like,
            $like
        ),
        ARRAY_A
    );

    $results = array();
    if ( ! empty( $users ) ) {
        foreach ( $users as $u ) {
            $uid = (int) $u['ID'];
            $results[] = array(
                'id'       => $uid,
                'name'     => $u['display_name'] ?: $u['user_login'],
                'username' => $u['user_login'],
                'avatar'   => get_avatar_url( $uid, array( 'size' => 80 ) ),
            );
        }
    }

    wp_send_json_success( array( 'users' => $results ) );
}
