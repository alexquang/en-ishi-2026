<?php
/**
 * BuddyNext Profile - Chat Tab Template
 * File: wp-content/themes/buddyx-child/templates/profile-chat-tab.php
 *
 * @var string $panel_id
 * @var string $active_tab
 * @var array  $followers
 */

defined( 'ABSPATH' ) || exit;
?>

<div
    class="bn-card pt-profile-chat-panel bg-white border border-slate-200/90 rounded-2xl shadow-sm mt-3 overflow-hidden"
    id="<?php echo esc_attr( $panel_id ); ?>"
    data-tab-panel="chat"
    data-wp-context='<?php echo esc_attr( wp_json_encode( array( 'tabSlug' => 'chat' ) ) ); ?>'
    data-wp-bind--hidden="!state.isActiveTab"
    <?php echo ( 'chat' === $active_tab ) ? '' : 'hidden'; ?>
>
    <div class="pt-chat-wrapper">
        <!-- Cột trái: Danh sách Followers -->
        <aside class="pt-chat-sidebar">
            <div class="pt-chat-sidebar-head">
                <h4 class="pt-chat-sidebar-title">
                    <?php esc_html_e( 'Followers', 'buddyx-child' ); ?>
                    <span class="pt-chat-badge"><?php echo count( $followers ); ?></span>
                </h4>
                <div class="pt-chat-search-wrap">
                    <input type="search" id="pt-chat-search-input" placeholder="<?php esc_attr_e( 'Tìm kiếm follower...', 'buddyx-child' ); ?>" autocomplete="off" />
                </div>
            </div>

            <div class="pt-chat-user-list" id="pt-chat-user-list">
                <?php if ( ! empty( $followers ) ) : ?>
                    <?php foreach ( $followers as $follower_id ) : 
                        $u = get_userdata( $follower_id );
                        if ( ! $u ) continue;
                        $avatar = get_avatar_url( $follower_id, array( 'size' => 80 ) );
                        $name   = $u->display_name ?: $u->user_login;
                    ?>
                        <div class="pt-chat-user-item" 
                             data-user-id="<?php echo esc_attr( $follower_id ); ?>"
                             data-user-name="<?php echo esc_attr( $name ); ?>"
                             data-user-avatar="<?php echo esc_url( $avatar ); ?>">
                            <div class="pt-chat-avatar-wrap">
                                <img src="<?php echo esc_url( $avatar ); ?>" alt="" class="pt-chat-avatar" />
                                <span class="pt-chat-online-dot"></span>
                            </div>
                            <div class="pt-chat-user-meta">
                                <span class="pt-chat-name"><?php echo esc_html( $name ); ?></span>
                                <span class="pt-chat-username">@<?php echo esc_html( $u->user_login ); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else : ?>
                    <div class="pt-chat-no-users">
                        <?php esc_html_e( 'Chưa có user nào follow bạn.', 'buddyx-child' ); ?>
                    </div>
                <?php endif; ?>
            </div>
        </aside>

        <!-- Cột phải: Màn hình Chat -->
        <section class="pt-chat-main">
            <!-- Header cuộc trò chuyện -->
            <div class="pt-chat-header" id="pt-chat-header" style="display: none;">
                <div class="pt-chat-active-user">
                    <img id="pt-chat-header-avatar" src="" alt="" class="pt-chat-header-avatar" />
                    <div>
                        <div id="pt-chat-header-name" class="pt-chat-header-name"></div>
                        <div class="pt-chat-header-status"><?php esc_html_e( 'Đang hoạt động', 'buddyx-child' ); ?></div>
                    </div>
                </div>
            </div>

            <!-- Vùng hiển thị tin nhắn -->
            <div class="pt-chat-messages" id="pt-chat-messages">
                <div class="pt-chat-placeholder">
                    <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5">
                        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                    </svg>
                    <p><?php esc_html_e( 'Chọn một user từ danh sách bên trái để bắt đầu chat.', 'buddyx-child' ); ?></p>
                </div>
            </div>

            <!-- Thanh chọn Emoji -->
            <div class="pt-chat-emoji-picker" id="pt-chat-emoji-picker" style="display: none;">
                <span class="pt-emoji-btn" data-emoji="😀">😀</span>
                <span class="pt-emoji-btn" data-emoji="😁">😁</span>
                <span class="pt-emoji-btn" data-emoji="😂">😂</span>
                <span class="pt-emoji-btn" data-emoji="😍">😍</span>
                <span class="pt-emoji-btn" data-emoji="🥰">🥰</span>
                <span class="pt-emoji-btn" data-emoji="😎">😎</span>
                <span class="pt-emoji-btn" data-emoji="👍">👍</span>
                <span class="pt-emoji-btn" data-emoji="❤️">❤️</span>
                <span class="pt-emoji-btn" data-emoji="🔥">🔥</span>
                <span class="pt-emoji-btn" data-emoji="🎉">🎉</span>
                <span class="pt-emoji-btn" data-emoji="🙏">🙏</span>
                <span class="pt-emoji-btn" data-emoji="👏">👏</span>
                <span class="pt-emoji-btn" data-emoji="🥺">🥺</span>
                <span class="pt-emoji-btn" data-emoji="😭">😭</span>
                <span class="pt-emoji-btn" data-emoji="☕">☕</span>
            </div>

            <!-- Form soạn tin nhắn -->
            <form class="pt-chat-composer" id="pt-chat-composer" style="display: none;">
                <button type="button" class="pt-chat-emoji-toggle" id="pt-chat-emoji-toggle" title="<?php esc_attr_e( 'Chọn Emoji', 'buddyx-child' ); ?>">
                    😊
                </button>
                <textarea id="pt-chat-input" class="pt-chat-input" rows="1" placeholder="<?php esc_attr_e( 'Nhập tin nhắn...', 'buddyx-child' ); ?>"></textarea>
                <button type="submit" id="pt-chat-send-btn" class="pt-chat-send-btn" title="<?php esc_attr_e( 'Gửi', 'buddyx-child' ); ?>">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
                    </svg>
                </button>
            </form>
        </section>
    </div>
</div>
