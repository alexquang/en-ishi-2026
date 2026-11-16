<?php
/**
 * Template: Followers List in Member Profile (Tab Messages)
 * File: wp-content/themes/buddyx-child/template-parts/follower-messages-list.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$displayed_user_id = function_exists( 'bp_displayed_user_id' ) ? bp_displayed_user_id() : get_current_user_id();
if ( ! $displayed_user_id ) {
    $displayed_user_id = get_current_user_id();
}

$followers = bx_follower_chat_get_user_followers( $displayed_user_id );
?>

<div class="bx-follower-chat-container">
    <div class="bx-follower-chat-header">
        <h3 class="bx-follower-chat-title">
            <?php esc_html_e( 'Danh sách Followers', 'buddyx-child' ); ?> 
            <span class="bx-follower-count">(<?php echo count( $followers ); ?>)</span>
        </h3>
        <p class="bx-follower-chat-subtitle"><?php esc_html_e( 'Chọn một người theo dõi bên dưới để bắt đầu nhắn tin trực tiếp.', 'buddyx-child' ); ?></p>
    </div>

    <div class="bx-follower-list-grid">
        <?php if ( ! empty( $followers ) ) : ?>
            <?php foreach ( $followers as $follower_id ) : 
                $user_info = get_userdata( $follower_id );
                if ( ! $user_info ) {
                    continue;
                }
                $avatar_url = get_avatar_url( $follower_id, array( 'size' => 120 ) );
                $display_name = ! empty( $user_info->display_name ) ? $user_info->display_name : $user_info->user_login;
                $user_profile = function_exists( 'bp_core_get_user_domain' ) ? bp_core_get_user_domain( $follower_id ) : get_author_posts_url( $follower_id );
            ?>
                <div class="bx-follower-card" data-user-id="<?php echo esc_attr( $follower_id ); ?>">
                    <div class="bx-follower-avatar-wrap">
                        <a href="<?php echo esc_url( $user_profile ); ?>">
                            <img src="<?php echo esc_url( $avatar_url ); ?>" alt="<?php echo esc_attr( $display_name ); ?>" class="bx-follower-avatar" />
                        </a>
                        <span class="bx-follower-online-dot"></span>
                    </div>

                    <div class="bx-follower-info">
                        <a href="<?php echo esc_url( $user_profile ); ?>" class="bx-follower-name">
                            <?php echo esc_html( $display_name ); ?>
                        </a>
                        <span class="bx-follower-username">@<?php echo esc_html( $user_info->user_login ); ?></span>
                    </div>

                    <div class="bx-follower-action">
                        <button type="button" 
                                class="bx-open-chat-btn" 
                                data-user-id="<?php echo esc_attr( $follower_id ); ?>"
                                data-user-name="<?php echo esc_attr( $display_name ); ?>"
                                data-user-avatar="<?php echo esc_url( $avatar_url ); ?>">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M20 2H4c-1.1 0-1.99.9-1.99 2L2 22l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zM6 9h12v2H6V9zm8 5H6v-2h8v2zm4-6H6V6h12v2z"/>
                            </svg>
                            <span><?php esc_html_e( 'Nhắn tin', 'buddyx-child' ); ?></span>
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else : ?>
            <div class="bx-follower-empty-state">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                    <circle cx="9" cy="7" r="4"></circle>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                </svg>
                <p><?php esc_html_e( 'Hiện chưa có user nào follow bạn.', 'buddyx-child' ); ?></p>
            </div>
        <?php endif; ?>
    </div>
</div>
