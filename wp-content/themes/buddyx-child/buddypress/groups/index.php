<?php

/**
 * BuddyPress Groups Directory Override
 */

defined('ABSPATH') || exit;

bp_nouveau_before_groups_directory_content();
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" />
<style>
    .fa-classic,
    .fa-regular,
    .fa-solid,
    .far,
    .fas {
        font-family: "Font Awesome 6 Free" !important;
    }

    .pt-groups-wrapper {
        display: flex;
        align-items: flex-start;
        gap: 24px;
    }

    /* LEFT */
    .pt-left-sidebar {
        width: 220px;
        min-width: 220px;
        background: #fff;
        padding: 12px;
        position: sticky;
        top: 90px;
    }

    /* CENTER */
    .pt-center-content {
        flex: 1;
        min-width: 0;
    }

    /* Menu */
    .pt-community-menu {
        margin: 0;
        padding: 0;
        list-style: none;
    }

    .pt-community-menu li {
        margin-bottom: 6px;
    }

    .pt-community-menu li a {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 12px 16px;
        border-radius: 10px;
        color: #1e293b;
        text-decoration: none;
        transition: .2s;
        font-weight: 500;
    }

    .pt-community-menu li a:hover {
        background: #f5f5f5;
    }

    .pt-community-menu .current-menu-item>a,
    .pt-community-menu .current-menu-parent>a {
        background: #2563eb;
        color: #fff;
    }

    @media(max-width:991px) {
        .pt-groups-wrapper {
            display: block;
        }

        .pt-left-sidebar {
            width: 100%;
            min-width: 100%;
            position: static;
            margin-bottom: 25px;
        }
    }

    /* BuddyPress Groups Directory */
    .groups.directory .site-sub-header {
        display: none !important;
    }

    /* Group Detail */
    .groups.single-item .site-sub-header {
        display: none !important;
    }

    .pt-community-nav {
        margin: 0;
        padding: 0;
        list-style: none;
    }

    .pt-community-nav li {
        margin: 2px 0;
    }

    .pt-community-nav li a {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 16px;
        font-size: var(--bn-text-base);
        font-weight: var(--font-weight-medium);
        line-height: 1.5;
        color: var(--bn-text-color, #0f2f87);
        text-decoration: none;
        border-radius: var(--bn-br-sm);
        transition: background .12s, color .12s;
    }

    .pt-community-nav li.active>a {
        background: #edf4ff;
        color: #0b52d6;
        font-weight: 600;
    }

    .pt-community-nav li i {
        width: 20px;
        text-align: center;
        font-size: 18px;
    }

    .pt-community-nav li a:hover {
        background: #f5f7fb;
    }

    @media (min-width: 1200px) {
        .container {
            width: 100% !important;
            max-width: 100% !important;
        }
    }

    /* =====================================================================
       FC2 LIVE CHANNEL STYLE FOR GROUPS DIRECTORY
       ===================================================================== */
    .pt-fc2-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        flex-wrap: wrap;
        margin: 6px 0 16px;
    }

    .pt-fc2-reset-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 6px 22px;
        border-radius: 999px;
        border: 1px solid #333 !important;
        background: #fff !important;
        color: #222 !important;
        font-size: 13px !important;
        font-weight: 600 !important;
        line-height: 1.3;
        cursor: pointer;
        transition: all 0.18s ease;
    }

    .pt-fc2-reset-btn:hover {
        background: #222 !important;
        color: #fff !important;
    }

    .pt-fc2-quick-filters {
        display: flex;
        align-items: center;
        gap: 6px;
        flex-wrap: wrap;
    }

    .pt-fc2-filter-chip {
        padding: 5px 13px;
        border-radius: 999px;
        border: 1px solid #d5d9e0 !important;
        background: #fff !important;
        color: #555 !important;
        font-size: 12px !important;
        font-weight: 600 !important;
        cursor: pointer;
        transition: all 0.15s ease;
    }

    .pt-fc2-filter-chip:hover,
    .pt-fc2-filter-chip.is-active {
        background: #1d80e2 !important;
        border-color: #1d80e2 !important;
        color: #fff !important;
    }

    /* GRID - Tối đa 5 cột mỗi dòng */
    #groups-list.pt-fc2-groups-grid {
        display: grid !important;
        grid-template-columns: repeat(5, minmax(0, 1fr)) !important;
        gap: 18px !important;
        margin: 0 !important;
        padding: 0 !important;
        list-style: none !important;
        width: 100% !important;
    }

    #groups-list.pt-fc2-groups-grid::before,
    #groups-list.pt-fc2-groups-grid::after,
    #groups-list.pt-fc2-groups-grid > li.pt-fc2-card-item::before,
    #groups-list.pt-fc2-groups-grid > li.pt-fc2-card-item::after,
    #groups-list.pt-fc2-groups-grid > li.pt-fc2-card-item > .list-wrap.pt-fc2-card::before,
    #groups-list.pt-fc2-groups-grid > li.pt-fc2-card-item > .list-wrap.pt-fc2-card::after {
        content: none !important;
        display: none !important;
    }

    #groups-list.pt-fc2-groups-grid > li.pt-fc2-card-item {
        margin: 0 !important;
        padding: 0 !important;
        width: 100% !important;
        max-width: none !important;
        flex: none !important;
        background: transparent !important;
        border: 0 !important;
        box-shadow: none !important;
    }

    #groups-list.pt-fc2-groups-grid > li.pt-fc2-card-item > .list-wrap.pt-fc2-card {
        display: flex !important;
        flex-direction: column !important;
        justify-content: space-between !important;
        height: 100% !important;
        min-height: 270px;
        background: #fff !important;
        border: 1px solid #e5e7eb !important;
        border-radius: 12px !important;
        padding: 0 !important;
        margin: 0 !important;
        overflow: hidden !important;
        box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04) !important;
        transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
        text-align: left !important;
    }

    #groups-list.pt-fc2-groups-grid > li.pt-fc2-card-item > .list-wrap.pt-fc2-card.is-live-card {
        border-color: rgba(239, 68, 68, 0.45) !important;
        box-shadow: 0 4px 14px rgba(239, 68, 68, 0.1) !important;
    }

    #groups-list.pt-fc2-groups-grid > li.pt-fc2-card-item > .list-wrap.pt-fc2-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.09) !important;
        border-color: #cbd5e1 !important;
    }

    /* THUMBNAIL AREA */
    .pt-fc2-thumb {
        position: relative;
        width: 100%;
        aspect-ratio: 4 / 3;
        background: #f1f5f9;
        overflow: hidden;
        flex-shrink: 0;
    }

    .pt-fc2-thumb-link {
        display: block;
        width: 100%;
        height: 100%;
    }

    .pt-fc2-thumb img.avatar {
        width: 100% !important;
        height: 100% !important;
        max-width: none !important;
        object-fit: cover !important;
        border-radius: 0 !important;
        margin: 0 !important;
        border: 0 !important;
        box-shadow: none !important;
        display: block !important;
        transition: transform 0.25s ease;
    }

    .pt-fc2-card:hover .pt-fc2-thumb img.avatar {
        transform: scale(1.04);
    }

    /* LIVE OVERLAY ON THUMBNAIL */
    .pt-fc2-thumb-live {
        position: absolute;
        top: 8px;
        left: 8px;
        z-index: 3;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 4px 9px;
        border-radius: 9999px;
        background: rgba(239, 68, 68, 0.94) !important;
        color: #ffffff !important;
        font-size: 11px !important;
        font-weight: 800 !important;
        letter-spacing: 0.4px;
        line-height: 1;
        text-decoration: none !important;
        box-shadow: 0 2px 8px rgba(239, 68, 68, 0.4);
    }

    .pt-fc2-live-dot {
        width: 7px;
        height: 7px;
        border-radius: 9999px;
        background: #ffffff;
        display: inline-block;
        animation: ptFc2LivePulse 1.3s ease-in-out infinite;
    }

    @keyframes ptFc2LivePulse {
        0%, 100% { opacity: 1; transform: scale(1); }
        50% { opacity: 0.45; transform: scale(0.8); }
    }

    /* FLAG OVERLAY BOTTOM RIGHT */
    .pt-fc2-flag {
        position: absolute;
        right: 8px;
        bottom: 8px;
        width: 24px;
        height: 16px;
        background: #fff;
        border: 1px solid rgba(0, 0, 0, 0.14);
        border-radius: 3px;
        box-shadow: 0 1px 4px rgba(0, 0, 0, 0.2);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        pointer-events: none;
        z-index: 2;
    }

    .pt-fc2-flag img {
        width: 100% !important;
        height: 100% !important;
        object-fit: cover !important;
        border-radius: 0 !important;
        display: block !important;
    }

    /* CARD BODY */
    .pt-fc2-body {
        padding: 12px 14px 12px !important;
        display: flex;
        flex-direction: column;
        flex: 1;
        min-width: 0;
    }

    .pt-fc2-badges {
        display: flex;
        align-items: center;
        gap: 6px;
        margin-bottom: 6px;
    }

    .pt-fc2-badge-live {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 5px;
        background: #ef4444 !important;
        color: #fff !important;
        font-size: 11px !important;
        font-weight: 700 !important;
        line-height: 1;
        padding: 4px 8px;
        border-radius: 6px;
        text-decoration: none !important;
        letter-spacing: 0.2px;
    }

    .pt-fc2-badge-live:hover {
        background: #dc2626 !important;
        color: #fff !important;
    }

    .pt-fc2-badge-extra {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        height: 17px;
        min-width: 17px;
        padding: 0 4px;
        border-radius: 2px;
        background: #2bb673;
        color: #fff;
        font-size: 10px;
        font-weight: 800;
        line-height: 1;
    }

    .pt-fc2-badge-extra i {
        font-size: 9px;
    }

    .pt-fc2-title {
        margin: 0 0 6px !important;
        padding: 0 !important;
        font-size: 14.5px !important;
        font-weight: 700 !important;
        line-height: 1.35 !important;
        color: #0f172a !important;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .pt-fc2-title a {
        color: #0f172a !important;
        text-decoration: none !important;
        font-size: inherit !important;
        font-weight: inherit !important;
    }

    .pt-fc2-title a:hover {
        color: #1d80e2 !important;
    }

    .pt-fc2-desc {
        font-size: 12.5px !important;
        line-height: 1.45 !important;
        color: #64748b !important;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
        text-overflow: ellipsis;
        min-height: 36px;
        word-break: break-word;
    }

    .pt-fc2-join-wrap {
        display: none;
    }

    /* CARD FOOTER (VIEWERS / MEMBERS + STAR) */
    .pt-fc2-footer {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 8px 14px !important;
        border-top: 1px solid #f1f5f9;
        background: #f8fafc;
        min-height: 36px;
    }

    .pt-fc2-viewers {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 12.5px;
        font-weight: 600;
        color: #334155;
        line-height: 1;
    }

    .pt-fc2-viewers i {
        color: #64748b;
        font-size: 12px;
    }

    .pt-fc2-viewers.is-live {
        color: #dc2626;
    }

    .pt-fc2-viewers.is-live i {
        color: #ef4444;
    }

    .pt-fc2-viewers.is-standby .pt-fc2-viewer-text {
        color: #475569;
        font-weight: 600;
    }

    .pt-fc2-fav-btn {
        border: 0 !important;
        background: transparent !important;
        padding: 2px !important;
        margin: 0 !important;
        color: #cbd5e1 !important;
        font-size: 15px !important;
        line-height: 1 !important;
        cursor: pointer;
        box-shadow: none !important;
        transition: color 0.15s ease, transform 0.15s ease;
    }

    .pt-fc2-fav-btn:hover {
        color: #f59e0b !important;
        transform: scale(1.12);
    }

    .pt-fc2-fav-btn.is-active {
        color: #f59e0b !important;
    }

    @media (max-width: 1280px) {
        #groups-list.pt-fc2-groups-grid {
            grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
            gap: 16px !important;
        }
    }

    @media (max-width: 960px) {
        #groups-list.pt-fc2-groups-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
            gap: 14px !important;
        }
    }

    @media (max-width: 575px) {
        #groups-list.pt-fc2-groups-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 10px !important;
        }
    }

    /* TOP NAV + CREATE GROUP BUTTON */
    .pt-groups-top-bar {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        padding-bottom: 12px;
        margin-bottom: 16px;
        border-bottom: 1px solid #e2e8f0;
    }

    .pt-groups-top-bar .bp-navs,
    .pt-groups-top-bar nav.main-navs {
        flex: 1 1 auto;
        min-width: 0;
        margin: 0 !important;
        border-bottom: 0 !important;
        background: transparent !important;
    }

    .pt-groups-top-bar .bp-navs ul {
        margin: 0 !important;
        border-bottom: 0 !important;
    }

    .pt-create-group-btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 10px 18px;
        border-radius: 10px;
        background: linear-gradient(135deg, #012068, #1d4ed8);
        color: #ffffff !important;
        font-size: 13.5px;
        font-weight: 700;
        text-decoration: none !important;
        border: none;
        cursor: pointer;
        box-shadow: 0 3px 10px rgba(1, 32, 104, 0.22);
        transition: transform 0.15s ease, filter 0.15s ease, box-shadow 0.15s ease;
        white-space: nowrap;
        margin-left: auto;
    }

    .pt-create-group-btn:hover {
        filter: brightness(1.08);
        transform: translateY(-1px);
        box-shadow: 0 6px 16px rgba(1, 32, 104, 0.28);
        color: #ffffff !important;
    }

    /* GOOGLE LOGIN POPUP FOR CREATE GROUP */
    .pt-group-login-dialog {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 999999;
        background: rgba(15, 23, 42, 0.62);
        backdrop-filter: blur(5px);
        align-items: center;
        justify-content: center;
        padding: 16px;
    }

    .pt-group-login-dialog.is-open {
        display: flex;
    }

    .pt-group-login-card {
        position: relative;
        width: 100%;
        max-width: 420px;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 20px;
        padding: 32px 26px 26px;
        text-align: center;
        box-shadow: 0 24px 60px rgba(15, 23, 42, 0.28);
        animation: ptGroupLoginFadeIn 0.18s ease-out;
    }

    @keyframes ptGroupLoginFadeIn {
        from { opacity: 0; transform: translateY(8px) scale(0.98); }
        to { opacity: 1; transform: translateY(0) scale(1); }
    }

    .pt-group-login-close {
        position: absolute;
        top: 14px;
        right: 14px;
        width: 34px;
        height: 34px;
        border-radius: 9999px;
        border: 1px solid #e2e8f0;
        background: #f8fafc;
        color: #475569;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: background-color 0.15s ease, color 0.15s ease;
    }

    .pt-group-login-close:hover {
        background: #e2e8f0;
        color: #0f172a;
    }

    .pt-group-login-icon {
        width: 56px;
        height: 56px;
        margin: 0 auto 16px;
        border-radius: 16px;
        background: linear-gradient(135deg, #012068, #2563eb);
        color: #ffffff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 22px;
        box-shadow: 0 6px 16px rgba(37, 99, 235, 0.28);
    }

    .pt-group-login-card h3 {
        margin: 0 0 8px;
        font-size: 20px;
        font-weight: 700;
        color: #0f172a;
    }

    .pt-group-login-card p {
        margin: 0 0 22px;
        font-size: 14px;
        line-height: 1.55;
        color: #64748b;
    }

    .pt-group-login-nsl {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 10px;
    }

    .pt-group-login-nsl .nsl-container,
    .pt-group-login-nsl .nsl-container-buttons {
        width: 100%;
        display: flex;
        justify-content: center;
    }

    .pt-group-login-nsl a.nsl-button {
        min-width: 240px;
        justify-content: center;
        border-radius: 10px !important;
        box-shadow: 0 2px 8px rgba(15, 23, 42, 0.1);
    }
</style>

<div class="pt-groups-wrapper">

    <!-- LEFT -->
    <aside class="pt-left-sidebar">

        <ul class="pt-community-nav">

            <li class="active">
                <a href="/activity/">
                    <i class="fa-solid fa-house"></i>
                    <span>Feed</span>
                </a>
            </li>

            <li>
                <a href="/activity/explore/">
                    <i class="fa-solid fa-globe"></i>
                    <span>Explore</span>
                </a>
            </li>

            <li>
                <a href="/spaces/">
                    <i class="fa-solid fa-hashtag"></i>
                    <span>Spaces</span>
                </a>
            </li>
            <li>
                <a href="/members/">
                    <i class="fa-solid fa-users"></i>
                    <span>Members</span>
                </a>
            </li>

            <li>
                <a href="/messages/">
                    <i class="fa-regular fa-comments"></i>
                    <span>Messages</span>
                </a>
            </li>

        </ul>

    </aside>

    <!-- CENTER -->
    <main class="pt-center-content">

        <?php bp_nouveau_template_notices(); ?>

        <div class="pt-groups-top-bar">
            <?php if (! bp_nouveau_is_object_nav_in_sidebar()) : ?>
                <?php bp_get_template_part('common/nav/directory-nav'); ?>
            <?php endif; ?>

            <?php
            $create_group_url = function_exists('bp_get_groups_directory_permalink')
                ? trailingslashit(bp_get_groups_directory_permalink()) . 'create/'
                : home_url('/groups/create/');
            ?>
            <?php if (! is_user_logged_in()) : ?>
                <a href="<?php echo esc_url($create_group_url); ?>"
                   id="ptCreateGroupGuestBtn"
                   class="pt-create-group-btn"
                   data-requires-login="1">
                    <i class="fa-solid fa-plus"></i>
                    <span><?php esc_html_e('Create a Group', 'buddyx-child'); ?></span>
                </a>
            <?php endif; ?>
        </div>

        <div class="screen-content">

            <?php bp_get_template_part('common/search-and-filters-bar'); ?>

            <div id="groups-dir-list"
                class="groups dir-list"
                data-bp-list="groups">

                <div id="bp-ajax-loader">
                    <?php bp_nouveau_user_feedback('directory-groups-loading'); ?>
                </div>

            </div>

            <?php bp_nouveau_after_groups_directory_content(); ?>

        </div>

    </main>
</div>

<?php if (! is_user_logged_in()) : ?>
    <div id="ptGroupLoginDialog" class="pt-group-login-dialog" role="dialog" aria-modal="true" aria-labelledby="ptGroupLoginTitle">
        <div class="pt-group-login-card">
            <button type="button" id="ptGroupLoginClose" class="pt-group-login-close" aria-label="<?php esc_attr_e('Close', 'buddyx-child'); ?>">
                <i class="fa-solid fa-xmark"></i>
            </button>
            <div class="pt-group-login-icon">
                <i class="fa-solid fa-users"></i>
            </div>
            <h3 id="ptGroupLoginTitle"><?php esc_html_e('Login Required', 'buddyx-child'); ?></h3>
            <p id="gmLoginRequiredText">
                <?php esc_html_e('Please log in with Google to create a new group.', 'buddyx-child'); ?>
            </p>
            <div id="gmNextendGoogleLogin" class="pt-group-login-nsl">
                <?php
                if (shortcode_exists('nextend_social_login')) {
                    echo do_shortcode('[nextend_social_login provider="google" redirect="' . esc_url($create_group_url) . '"]');
                } else {
                    echo '<a class="pt-create-group-btn" href="' . esc_url(wp_login_url($create_group_url)) . '">' . esc_html__('Log In', 'buddyx-child') . '</a>';
                }
                ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
(function () {
    const isLoggedIn = <?php echo is_user_logged_in() ? 'true' : 'false'; ?>;
    const createGroupUrl = <?php echo wp_json_encode($create_group_url); ?>;
    let pendingCreateGroup = false;

    function openGroupLoginPopup() {
        const dialog = document.getElementById('ptGroupLoginDialog');
        if (dialog) {
            dialog.classList.add('is-open');
            pendingCreateGroup = true;
            if (typeof window.ptInstallSeamlessGoogleLoginHook === 'function') {
                window.ptInstallSeamlessGoogleLoginHook();
            }
            return true;
        }
        return false;
    }

    function closeGroupLoginPopup() {
        const dialog = document.getElementById('ptGroupLoginDialog');
        if (dialog) {
            dialog.classList.remove('is-open');
        }
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            closeGroupLoginPopup();
        }
    });

    window.addEventListener('pt:google_login_success', function () {
        closeGroupLoginPopup();
        if (pendingCreateGroup) {
            pendingCreateGroup = false;
            window.location.assign(createGroupUrl);
        }
    });

    document.addEventListener('click', function (e) {
        // 0. Close Google Login popup
        if (e.target.closest('#ptGroupLoginClose') || e.target.id === 'ptGroupLoginDialog') {
            e.preventDefault();
            pendingCreateGroup = false;
            closeGroupLoginPopup();
            return;
        }

        // 0b. Intercept Create Group click when not logged in
        const createTrigger = e.target.closest(
            '#ptCreateGroupGuestBtn, a[href*="/groups/create"], #groups-create a, .group-create a, a.bp-title-button[href*="create"]'
        );
        if (createTrigger && !isLoggedIn && !document.body.classList.contains('logged-in')) {
            e.preventDefault();
            e.stopPropagation();
            if (!openGroupLoginPopup()) {
                window.location.assign(createGroupUrl);
            }
            return;
        }

        // 1. Reset Filter button
    const resetBtn = e.target.closest('#pt-reset-group-filter');
    if (resetBtn) {
        e.preventDefault();
        document.querySelectorAll('.pt-fc2-filter-chip').forEach(function (chip) {
            chip.classList.toggle('is-active', chip.dataset.filter === 'all');
        });
        document.querySelectorAll('#groups-list .pt-fc2-card-item').forEach(function (item) {
            item.style.display = '';
        });
        const searchInput = document.querySelector('#groups-dir-search input[type="search"], #groups_search');
        if (searchInput && searchInput.value) {
            searchInput.value = '';
            searchInput.dispatchEvent(new Event('keyup', { bubbles: true }));
        }
        return;
    }

    // 2. Quick filter chips (All / Live / Standby / Favorites)
    const filterChip = e.target.closest('.pt-fc2-filter-chip');
    if (filterChip) {
        e.preventDefault();
        const mode = filterChip.dataset.filter || 'all';
        document.querySelectorAll('.pt-fc2-filter-chip').forEach(function (c) {
            c.classList.toggle('is-active', c === filterChip);
        });

        document.querySelectorAll('#groups-list .pt-fc2-card-item').forEach(function (item) {
            if (mode === 'all') {
                item.style.display = '';
            } else if (mode === 'live') {
                item.style.display = item.dataset.liveState === 'live' ? '' : 'none';
            } else if (mode === 'standby') {
                item.style.display = item.dataset.liveState === 'standby' ? '' : 'none';
            } else if (mode === 'fav') {
                item.style.display = item.dataset.fav === '1' ? '' : 'none';
            }
        });
        return;
    }

    // 3. Favorite Star Toggle
    const favBtn = e.target.closest('.pt-fc2-fav-btn');
    if (favBtn) {
        e.preventDefault();
        const groupId = favBtn.dataset.groupId;
        const cardItem = favBtn.closest('.pt-fc2-card-item');
        const icon = favBtn.querySelector('i');
        const nextActive = !favBtn.classList.contains('is-active');

        favBtn.classList.toggle('is-active', nextActive);
        if (icon) {
            icon.className = nextActive ? 'fa-solid fa-star' : 'fa-regular fa-star';
        }
        if (cardItem) {
            cardItem.dataset.fav = nextActive ? '1' : '0';
        }

        // Persist in localStorage & WordPress AJAX
        try {
            const key = 'pt_fav_groups';
            const saved = JSON.parse(localStorage.getItem(key) || '[]');
            const idx = saved.indexOf(String(groupId));
            if (nextActive && idx === -1) saved.push(String(groupId));
            if (!nextActive && idx !== -1) saved.splice(idx, 1);
            localStorage.setItem(key, JSON.stringify(saved));
        } catch (_) {}

        const body = new URLSearchParams({
            action: 'pt_toggle_favorite_group',
            group_id: String(groupId),
            fav: nextActive ? '1' : '0'
        });
        fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
            credentials: 'same-origin'
        }).catch(function () {});
    }
});
})();
</script>

<?php bp_nouveau_after_directory_page(); ?>