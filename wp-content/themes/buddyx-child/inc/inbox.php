<?php
/**
 * Mutual-Follow Inbox (1-on-1 Chat) for BuddyNext My Profile
 *
 * - Adds "Inbox" tab next to "Posts" in My Profile (self only).
 * - Only allows chatting between users who mutually follow each other.
 * - Stores messages in {$wpdb->prefix}pt_inbox_messages.
 */

defined('ABSPATH') || exit;

define('PT_INBOX_DB_VERSION', '1.0.1');

/**
 * Create/update the inbox messages table once.
 */
add_action('init', 'pt_inbox_maybe_create_table');
function pt_inbox_maybe_create_table(): void
{
    if (get_option('pt_inbox_db_version') === PT_INBOX_DB_VERSION) {
        return;
    }

    global $wpdb;
    $table_name      = $wpdb->prefix . 'pt_inbox_messages';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table_name} (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        sender_id BIGINT(20) UNSIGNED NOT NULL,
        receiver_id BIGINT(20) UNSIGNED NOT NULL,
        reply_to_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        message TEXT NOT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY idx_pair (sender_id, receiver_id, id),
        KEY idx_receiver_read (receiver_id, is_read)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    update_option('pt_inbox_db_version', PT_INBOX_DB_VERSION);
}

/**
 * Get BuddyNext FollowService instance if available.
 */
function pt_inbox_follow_service()
{
    if (function_exists('buddynext_service')) {
        return buddynext_service('follow_service');
    }
    return null;
}

/**
 * Check if two users mutually follow each other.
 */
function pt_inbox_is_mutual_follow(int $user_a, int $user_b): bool
{
    if ($user_a <= 0 || $user_b <= 0 || $user_a === $user_b) {
        return false;
    }

    $follow_service = pt_inbox_follow_service();
    if (!$follow_service || !method_exists($follow_service, 'is_following')) {
        return false;
    }

    return (bool) $follow_service->is_following($user_a, $user_b)
        && (bool) $follow_service->is_following($user_b, $user_a);
}

/**
 * Get all user IDs that mutually follow with $user_id.
 *
 * @return int[]
 */
function pt_inbox_get_mutual_follow_ids(int $user_id): array
{
    if ($user_id <= 0) {
        return [];
    }

    $follow_service = pt_inbox_follow_service();
    if (!$follow_service || !method_exists($follow_service, 'followers') || !method_exists($follow_service, 'following')) {
        return [];
    }

    $followers = array_map('intval', (array) $follow_service->followers($user_id));
    $following = array_map('intval', (array) $follow_service->following($user_id));

    $mutual = array_values(array_unique(array_intersect($followers, $following)));
    return array_values(array_filter($mutual, static fn(int $id): bool => $id > 0 && $id !== $user_id));
}

/**
 * Check if user has a non-free (paid) membership and can see new messages.
 */
function pt_inbox_can_view_new_messages(int $user_id): bool
{
    if ($user_id <= 0) {
        return false;
    }

    if (user_can($user_id, 'manage_options')) {
        return true;
    }

    return function_exists('pt_user_has_paid_pmpro') && pt_user_has_paid_pmpro($user_id);
}

/**
 * Get total unread inbox messages for a user (from mutual followers).
 */
function pt_inbox_get_unread_count(int $user_id): int
{
    if ($user_id <= 0) {
        return 0;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'pt_inbox_messages';

    return (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} WHERE receiver_id = %d AND is_read = 0",
            $user_id
        )
    );
}

/**
 * Add "Inbox" tab to BuddyNext My Profile right next to "Posts".
 */
add_filter('buddynext_nav_items', 'pt_buddynext_add_inbox_tab', 20, 2);
function pt_buddynext_add_inbox_tab($items, $ctx)
{
    if (!is_array($items) || !is_object($ctx) || ($ctx->surface ?? '') !== 'profile') {
        return $items;
    }

    $subject_id = (int) ($ctx->subject_id ?? 0);
    $viewer_id  = (int) ($ctx->viewer_id ?? 0);
    if (!$subject_id || !$viewer_id || $subject_id !== $viewer_id) {
        return $items;
    }

    $items[] = [
        'id'       => 'inbox',
        'surface'  => 'profile',
        'layer'    => 'primary',
        'label'    => __('Inbox', 'buddyx-child'),
        'tab'      => 'inbox',
        'priority' => 12,
        'after'    => 'posts',
        'count'    => static fn(): int => pt_inbox_get_unread_count($viewer_id),
    ];

    return $items;
}

/**
 * Render the "Inbox" tab panel in BuddyNext Profile.
 */
add_action('buddynext_part_profile_tab_panel_after', 'pt_buddynext_render_inbox_panel');
function pt_buddynext_render_inbox_panel($args): void
{
    $profile_user_id = (int) ($args['profile_user_id'] ?? 0);
    $viewer_id       = (int) ($args['viewer_id'] ?? get_current_user_id());
    $active_tab      = (string) ($args['active_tab'] ?? 'posts');

    if (!$profile_user_id || !$viewer_id || $profile_user_id !== $viewer_id) {
        return;
    }

    $panel_id      = function_exists('buddynext_nav_panel_id') ? buddynext_nav_panel_id('inbox') : 'bn-panel-inbox';
    $template_path = get_stylesheet_directory() . '/templates/profile-inbox-tab.php';

    if (file_exists($template_path)) {
        include $template_path;
    }
}

/**
 * Enqueue Inbox CSS & JS on profile pages when logged in.
 */
add_action('wp_enqueue_scripts', 'pt_inbox_enqueue_assets');
function pt_inbox_enqueue_assets(): void
{
    if (!is_user_logged_in()) {
        return;
    }

    $css_path = get_stylesheet_directory() . '/assets-enishi/css/pt-inbox.css';
    $js_path  = get_stylesheet_directory() . '/assets-enishi/js/pt-inbox.js';

    if (file_exists($css_path)) {
        wp_enqueue_style(
            'pt-inbox-style',
            get_stylesheet_directory_uri() . '/assets-enishi/css/pt-inbox.css',
            [],
            (string) filemtime($css_path)
        );
    }

    if (file_exists($js_path)) {
        wp_enqueue_script(
            'pt-inbox-script',
            get_stylesheet_directory_uri() . '/assets-enishi/js/pt-inbox.js',
            [],
            (string) filemtime($js_path),
            true
        );

        wp_localize_script('pt-inbox-script', 'ptInboxConfig', [
            'ajaxUrl'       => admin_url('admin-ajax.php'),
            'nonce'         => wp_create_nonce('pt_inbox_nonce'),
            'currentUserId' => get_current_user_id(),
            'i18n'          => [
                'noConversations' => __('No mutual followers yet. Follow each other to start chatting!', 'buddyx-child'),
                'selectChat'      => __('Select a conversation to start messaging', 'buddyx-child'),
                'noMessages'      => __('No messages yet. Say hello!', 'buddyx-child'),
                'sendError'       => __('Could not send message. Please try again.', 'buddyx-child'),
            ],
        ]);
    }
}

/**
 * AJAX: Get list of mutual-follow conversations with last message & unread count.
 */
add_action('wp_ajax_pt_inbox_get_conversations', 'pt_ajax_inbox_get_conversations');
function pt_ajax_inbox_get_conversations(): void
{
    check_ajax_referer('pt_inbox_nonce', 'nonce');

    $current_user_id = get_current_user_id();
    if (!$current_user_id) {
        wp_send_json_error(['message' => 'Unauthorized'], 401);
    }

    $mutual_ids = pt_inbox_get_mutual_follow_ids($current_user_id);
    if (empty($mutual_ids)) {
        wp_send_json_success(['conversations' => []]);
    }

    global $wpdb;
    $table_name   = $wpdb->prefix . 'pt_inbox_messages';
    $can_view_new = pt_inbox_can_view_new_messages($current_user_id);
    $conversations = [];

    foreach ($mutual_ids as $partner_id) {
        $user = get_userdata($partner_id);
        if (!$user) {
            continue;
        }

        if ($can_view_new) {
            $last_msg = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id, sender_id, message, created_at
                     FROM {$table_name}
                     WHERE (sender_id = %d AND receiver_id = %d)
                        OR (sender_id = %d AND receiver_id = %d)
                     ORDER BY id DESC
                     LIMIT 1",
                    $current_user_id,
                    $partner_id,
                    $partner_id,
                    $current_user_id
                ),
                ARRAY_A
            );
        } else {
            // Free users cannot see new (unread incoming) messages
            $last_msg = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id, sender_id, message, created_at
                     FROM {$table_name}
                     WHERE (sender_id = %d AND receiver_id = %d)
                        OR (sender_id = %d AND receiver_id = %d AND is_read = 1)
                     ORDER BY id DESC
                     LIMIT 1",
                    $current_user_id,
                    $partner_id,
                    $partner_id,
                    $current_user_id
                ),
                ARRAY_A
            );
        }

        $unread = $can_view_new
            ? (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table_name}
                     WHERE sender_id = %d AND receiver_id = %d AND is_read = 0",
                    $partner_id,
                    $current_user_id
                )
            )
            : 0;

        $profile_url = function_exists('buddynext_profile_url')
            ? buddynext_profile_url($partner_id)
            : get_author_posts_url($partner_id);

        $conversations[] = [
            'user_id'      => $partner_id,
            'display_name' => $user->display_name ?: $user->user_login,
            'username'     => $user->user_login,
            'avatar'       => get_avatar_url($partner_id, ['size' => 96]),
            'profile_url'  => $profile_url,
            'last_message' => $last_msg ? wp_trim_words($last_msg['message'], 10, '...') : '',
            'last_time'    => $last_msg ? mysql2date('H:i', $last_msg['created_at']) : '',
            'last_id'      => $last_msg ? (int) $last_msg['id'] : 0,
            'unread'       => $unread,
        ];
    }

    // Sort by latest message first, then alphabetically
    usort($conversations, static function (array $a, array $b): int {
        if ($a['last_id'] !== $b['last_id']) {
            return $b['last_id'] <=> $a['last_id'];
        }
        return strcasecmp($a['display_name'], $b['display_name']);
    });

    wp_send_json_success(['conversations' => $conversations]);
}

/**
 * Format a replied-to message snippet for display.
 */
function pt_inbox_get_reply_preview(int $reply_to_id, int $user_a, int $user_b): ?array
{
    if ($reply_to_id <= 0) {
        return null;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'pt_inbox_messages';
    $row        = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id, sender_id, message
             FROM {$table_name}
             WHERE id = %d
               AND ((sender_id = %d AND receiver_id = %d) OR (sender_id = %d AND receiver_id = %d))
             LIMIT 1",
            $reply_to_id,
            $user_a,
            $user_b,
            $user_b,
            $user_a
        ),
        ARRAY_A
    );

    if (!$row) {
        return null;
    }

    $sender      = get_userdata((int) $row['sender_id']);
    $sender_name = $sender ? ($sender->display_name ?: $sender->user_login) : __('User', 'buddyx-child');

    return [
        'id'          => (int) $row['id'],
        'sender_name' => $sender_name,
        'message'     => wp_trim_words($row['message'], 12, '...'),
    ];
}

/**
 * AJAX: Get messages with a specific mutual-follow partner.
 */
add_action('wp_ajax_pt_inbox_get_messages', 'pt_ajax_inbox_get_messages');
function pt_ajax_inbox_get_messages(): void
{
    check_ajax_referer('pt_inbox_nonce', 'nonce');

    $current_user_id = get_current_user_id();
    $partner_id      = isset($_POST['partner_id']) ? (int) $_POST['partner_id'] : 0;
    $after_id        = isset($_POST['after_id']) ? (int) $_POST['after_id'] : 0;

    if (!$current_user_id || !$partner_id) {
        wp_send_json_error(['message' => 'Invalid parameters'], 400);
    }

    if (!pt_inbox_is_mutual_follow($current_user_id, $partner_id)) {
        wp_send_json_error(['message' => __('You can only chat with users who follow you back.', 'buddyx-child')], 403);
    }

    global $wpdb;
    $table_name   = $wpdb->prefix . 'pt_inbox_messages';
    $can_view_new = pt_inbox_can_view_new_messages($current_user_id);

    if ($can_view_new) {
        // Mark incoming messages as read only for non-free users
        $wpdb->update(
            $table_name,
            ['is_read' => 1],
            [
                'sender_id'   => $partner_id,
                'receiver_id' => $current_user_id,
                'is_read'     => 0,
            ],
            ['%d'],
            ['%d', '%d', '%d']
        );
    }

    $incoming_filter = $can_view_new
        ? '(sender_id = %d AND receiver_id = %d)'
        : '(sender_id = %d AND receiver_id = %d AND is_read = 1)';

    if ($after_id > 0) {
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, sender_id, receiver_id, reply_to_id, message, created_at
                 FROM {$table_name}
                 WHERE id > %d
                   AND ((sender_id = %d AND receiver_id = %d) OR {$incoming_filter})
                 ORDER BY id ASC
                 LIMIT 100",
                $after_id,
                $current_user_id,
                $partner_id,
                $partner_id,
                $current_user_id
            ),
            ARRAY_A
        );
    } else {
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, sender_id, receiver_id, reply_to_id, message, created_at
                 FROM {$table_name}
                 WHERE (sender_id = %d AND receiver_id = %d)
                    OR {$incoming_filter}
                 ORDER BY id ASC
                 LIMIT 100",
                $current_user_id,
                $partner_id,
                $partner_id,
                $current_user_id
            ),
            ARRAY_A
        );
    }

    $messages = array_map(static function (array $row) use ($current_user_id, $partner_id): array {
        $reply_to_id = (int) ($row['reply_to_id'] ?? 0);
        return [
            'id'         => (int) $row['id'],
            'sender_id'  => (int) $row['sender_id'],
            'is_mine'    => (int) $row['sender_id'] === $current_user_id,
            'message'    => $row['message'],
            'reply_to'   => $reply_to_id > 0 ? pt_inbox_get_reply_preview($reply_to_id, $current_user_id, $partner_id) : null,
            'time'       => mysql2date('H:i', $row['created_at']),
            'created_at' => $row['created_at'],
        ];
    }, $rows ?: []);

    wp_send_json_success(['messages' => $messages]);
}

/**
 * AJAX: Send a message to a mutual-follow partner.
 */
add_action('wp_ajax_pt_inbox_send_message', 'pt_ajax_inbox_send_message');
function pt_ajax_inbox_send_message(): void
{
    check_ajax_referer('pt_inbox_nonce', 'nonce');

    $current_user_id = get_current_user_id();
    $partner_id      = isset($_POST['partner_id']) ? (int) $_POST['partner_id'] : 0;
    $reply_to_id     = isset($_POST['reply_to_id']) ? (int) $_POST['reply_to_id'] : 0;
    $raw_message     = isset($_POST['message']) ? wp_unslash($_POST['message']) : '';
    $message         = trim(sanitize_textarea_field($raw_message));

    if (!$current_user_id || !$partner_id || $message === '') {
        wp_send_json_error(['message' => 'Message cannot be empty'], 400);
    }

    if (!pt_inbox_is_mutual_follow($current_user_id, $partner_id)) {
        wp_send_json_error(['message' => __('You can only chat with users who follow you back.', 'buddyx-child')], 403);
    }

    $reply_preview = $reply_to_id > 0 ? pt_inbox_get_reply_preview($reply_to_id, $current_user_id, $partner_id) : null;
    if (!$reply_preview) {
        $reply_to_id = 0;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'pt_inbox_messages';
    $now        = current_time('mysql');

    $inserted = $wpdb->insert(
        $table_name,
        [
            'sender_id'   => $current_user_id,
            'receiver_id' => $partner_id,
            'reply_to_id' => $reply_to_id,
            'message'     => $message,
            'is_read'     => 0,
            'created_at'  => $now,
        ],
        ['%d', '%d', '%d', '%s', '%d', '%s']
    );

    if (!$inserted) {
        wp_send_json_error(['message' => 'Database error'], 500);
    }

    wp_send_json_success([
        'message' => [
            'id'         => (int) $wpdb->insert_id,
            'sender_id'  => $current_user_id,
            'is_mine'    => true,
            'message'    => $message,
            'reply_to'   => $reply_preview,
            'time'       => mysql2date('H:i', $now),
            'created_at' => $now,
        ],
    ]);
}
