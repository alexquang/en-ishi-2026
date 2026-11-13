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

    /* GRID */
    #groups-list.pt-fc2-groups-grid {
        display: grid !important;
        grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)) !important;
        gap: 14px !important;
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
        min-height: 248px;
        background: #fff !important;
        border: 1px solid #e1e3e8 !important;
        border-radius: 2px !important;
        padding: 0 !important;
        margin: 0 !important;
        overflow: hidden !important;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04) !important;
        transition: transform 0.16s ease, box-shadow 0.16s ease, border-color 0.16s ease;
        text-align: left !important;
    }

    #groups-list.pt-fc2-groups-grid > li.pt-fc2-card-item > .list-wrap.pt-fc2-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(0, 0, 0, 0.09) !important;
        border-color: #c5ccd6 !important;
    }

    /* THUMBNAIL AREA */
    .pt-fc2-thumb {
        position: relative;
        width: 100%;
        aspect-ratio: 4 / 3;
        background: #000;
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
    }

    /* FLAG OVERLAY BOTTOM RIGHT */
    .pt-fc2-flag {
        position: absolute;
        right: 6px;
        bottom: 6px;
        width: 22px;
        height: 15px;
        background: #fff;
        border: 1px solid rgba(0, 0, 0, 0.18);
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.25);
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
        padding: 8px 9px 10px !important;
        display: flex;
        flex-direction: column;
        flex: 1;
        min-width: 0;
    }

    .pt-fc2-badges {
        display: flex;
        align-items: center;
        gap: 4px;
        margin-bottom: 5px;
    }

    .pt-fc2-badge-live {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: #2389e6 !important;
        color: #fff !important;
        font-size: 11px !important;
        font-weight: 700 !important;
        line-height: 1;
        padding: 3px 7px;
        border-radius: 2px;
        text-decoration: none !important;
        letter-spacing: 0.2px;
    }

    .pt-fc2-badge-live:hover {
        background: #1772c7 !important;
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
        margin: 0 0 4px !important;
        padding: 0 !important;
        font-size: 13.5px !important;
        font-weight: 700 !important;
        line-height: 1.3 !important;
        color: #222 !important;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .pt-fc2-title a {
        color: #222 !important;
        text-decoration: none !important;
        font-size: inherit !important;
        font-weight: inherit !important;
    }

    .pt-fc2-title a:hover {
        color: #2389e6 !important;
    }

    .pt-fc2-desc {
        font-size: 11.5px !important;
        line-height: 1.38 !important;
        color: #8c939e !important;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
        text-overflow: ellipsis;
        min-height: 31px;
        word-break: break-word;
    }

    .pt-fc2-join-wrap {
        display: none;
    }

    /* CARD FOOTER (VIEWERS / STANDBY + STAR) */
    .pt-fc2-footer {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 5px 9px !important;
        border-top: 1px solid #eceef2;
        background: #fff;
        min-height: 29px;
    }

    .pt-fc2-viewers {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        font-size: 12px;
        font-weight: 600;
        color: #444;
        line-height: 1;
    }

    .pt-fc2-viewers i {
        color: #2389e6;
        font-size: 11.5px;
    }

    .pt-fc2-viewers.is-standby .pt-fc2-viewer-text {
        color: #6b7280;
        font-weight: 500;
    }

    .pt-fc2-fav-btn {
        border: 0 !important;
        background: transparent !important;
        padding: 2px !important;
        margin: 0 !important;
        color: #c5cad3 !important;
        font-size: 14px !important;
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

    @media (max-width: 575px) {
        #groups-list.pt-fc2-groups-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 10px !important;
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

<script>
document.addEventListener('click', function (e) {
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
</script>

<?php bp_nouveau_after_directory_page(); ?>