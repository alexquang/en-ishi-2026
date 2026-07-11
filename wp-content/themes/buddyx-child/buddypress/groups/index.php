<?php

/**
 * BuddyPress Groups Directory Override
 */

defined('ABSPATH') || exit;

bp_nouveau_before_groups_directory_content();
?>
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
        gap: 30px;
    }

    /* LEFT */
    .pt-left-sidebar {
        width: 220px;
        min-width: 220px;
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

    .pt-left-sidebar {

        width: 220px;
        min-width: 220px;

        background: #fff;
        padding: 12px;
        position: sticky;
        top: 90px;

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

        transition:
            background .12s,
            color .12s;

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

        <?php if (! bp_nouveau_is_object_nav_in_sidebar()) : ?>
            <?php bp_get_template_part('common/nav/directory-nav'); ?>
        <?php endif; ?>

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

<?php bp_nouveau_after_directory_page(); ?>