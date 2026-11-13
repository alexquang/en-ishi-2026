<?php
/**
 * Template Name: Live Stream
 * Description: Trang Livestream cho menu Channel -> Video (En-Ishi Live Studio & Viewer)
 */

defined('ABSPATH') || exit;

get_header();

$is_logged_in = is_user_logged_in();
$current_user = wp_get_current_user();
$user_id      = $is_logged_in ? (int) $current_user->ID : 0;
$user_name    = $is_logged_in ? ($current_user->display_name ?: $current_user->user_login) : 'Khách';
$user_avatar  = $is_logged_in ? get_avatar_url($user_id, ['size' => 96]) : 'https://www.gravatar.com/avatar/?d=mp&s=96';
$user_room    = $is_logged_in ? ('channel-' . sanitize_key($current_user->user_login ?: $user_id)) : 'enishi-main';

$requested_room = isset($_GET['room']) ? sanitize_text_field(wp_unslash($_GET['room'])) : '';
$requested_mode = isset($_GET['mode']) ? sanitize_text_field(wp_unslash($_GET['mode'])) : 'watch';

$active_streams = function_exists('pt_get_active_livestreams_list')
    ? pt_get_active_livestreams_list()
    : [];

if ($requested_room === '') {
    $requested_room = !empty($active_streams[0]['room']) ? $active_streams[0]['room'] : 'enishi-main';
}

$initial_host = !empty($active_streams[0]) ? $active_streams[0] : [
    'room'        => $requested_room,
    'title'       => 'En-Ishi Official Live Channel - Giao lưu & Khám phá',
    'category'    => 'Giao lưu',
    'host_name'   => 'En-Ishi Channel',
    'host_avatar' => $user_avatar,
    'viewers'     => 1,
    'is_live'     => true,
];
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" />
<link rel="stylesheet" href="<?php echo esc_url(get_stylesheet_directory_uri() . '/assets-enishi/css/pt-live.css?v=' . filemtime(get_stylesheet_directory() . '/assets-enishi/css/pt-live.css')); ?>" />
<script src="https://cdn.jsdelivr.net/npm/livekit-client/dist/livekit-client.umd.min.js"></script>

<main class="ptlive-wrapper">
    <div class="ptlive-container">

        <!-- TOP BAR -->
        <header class="ptlive-topbar">
            <div class="ptlive-brand">
                <span class="ptlive-brand-badge">
                    <span class="ptlive-dot"></span> LIVE CHANNEL
                </span>
                <div class="ptlive-brand-title">
                    <h1>Channel &rarr; Video Livestream</h1>
                    <p>Phát trực tiếp &amp; giao lưu thời gian thực cùng cộng đồng En-Ishi</p>
                </div>
            </div>

            <div class="ptlive-mode-tabs" role="tablist">
                <button type="button" id="ptlive-mode-watch" class="ptlive-mode-btn <?php echo $requested_mode !== 'host' ? 'is-active' : ''; ?>">
                    <i class="fa-solid fa-tv"></i>
                    <span>Xem Livestream</span>
                </button>
                <button type="button" id="ptlive-mode-host" class="ptlive-mode-btn mode-host <?php echo $requested_mode === 'host' ? 'is-active' : ''; ?>">
                    <i class="fa-solid fa-tower-broadcast"></i>
                    <span>Phát Trực Tiếp (Go Live)</span>
                </button>
            </div>

            <div class="ptlive-top-stats">
                <div class="ptlive-stat-pill">
                    <i class="fa-solid fa-signal"></i>
                    <span>Đang phát: <strong id="ptlive-active-total-count"><?php echo count($active_streams); ?></strong> kênh</span>
                </div>
                <div class="ptlive-stat-pill">
                    <i class="fa-solid fa-hashtag"></i>
                    <span id="ptlive-room-tag"><?php echo esc_html($requested_room); ?></span>
                </div>
            </div>
        </header>

        <!-- MAIN STAGE + LIVE CHAT -->
        <section class="ptlive-main-grid">

            <!-- LEFT: VIDEO PLAYER & STUDIO -->
            <div class="ptlive-stage-card">
                <div class="ptlive-video-box">

                    <!-- HUD Top Overlay -->
                    <div class="ptlive-video-hud-top">
                        <div class="ptlive-hud-left">
                            <span id="ptlive-status-badge" class="ptlive-status-badge">
                                <i class="fa-solid fa-circle-pause"></i> OFFLINE
                            </span>
                            <span class="ptlive-hud-pill" title="Số người đang xem">
                                <i class="fa-solid fa-eye"></i>
                                <span id="ptlive-viewer-count"><?php echo esc_html((string) ($initial_host['viewers'] ?? 1)); ?></span>
                            </span>
                            <span class="ptlive-hud-pill" title="Thời lượng phát">
                                <i class="fa-regular fa-clock"></i>
                                <span id="ptlive-uptime">00:00</span>
                            </span>
                        </div>
                        <div class="ptlive-hud-right">
                            <button type="button" id="ptlive-btn-fullscreen" class="ptlive-hud-pill" style="cursor:pointer;" title="Toàn màn hình">
                                <i class="fa-solid fa-expand"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Video Mount Container -->
                    <div id="ptlive-video-mount" class="ptlive-video-mount"></div>

                    <!-- Standby Overlay -->
                    <div id="ptlive-standby-overlay" class="ptlive-standby-overlay">
                        <div class="ptlive-standby-icon">
                            <i class="fa-solid fa-tower-broadcast"></i>
                        </div>
                        <h3 id="ptlive-standby-title">Chào mừng đến với Kênh Livestream Video</h3>
                        <p id="ptlive-standby-desc">
                            Chọn một kênh đang phát trực tiếp bên dưới để xem hoặc bấm <strong>Phát Trực Tiếp (Go Live)</strong> để bắt đầu buổi phát sóng của riêng bạn.
                        </p>
                        <div class="ptlive-standby-actions">
                            <button type="button" id="ptlive-standby-primary-btn" class="ptlive-btn btn-primary">
                                <i class="fa-solid fa-video"></i> Phát Trực Tiếp Ngay
                            </button>
                        </div>
                    </div>

                    <!-- Floating Reactions -->
                    <div id="ptlive-reactions-layer" class="ptlive-reactions-layer"></div>
                </div>

                <!-- CONTROLS BAR -->
                <div class="ptlive-controls-bar">
                    <!-- Host Broadcast Controls -->
                    <div id="ptlive-host-controls" class="ptlive-controls-group" style="display: none;">
                        <button type="button" id="ptlive-btn-preview" class="ptlive-btn">
                            <i class="fa-solid fa-camera"></i>
                            <span>Xem trước Camera</span>
                        </button>
                        <button type="button" id="ptlive-btn-start" class="ptlive-btn btn-primary">
                            <i class="fa-solid fa-tower-broadcast"></i>
                            <span>Bắt đầu Live</span>
                        </button>
                        <button type="button" id="ptlive-btn-stop" class="ptlive-btn btn-danger" style="display: none;">
                            <i class="fa-solid fa-stop"></i>
                            <span>Kết thúc Live</span>
                        </button>
                        <button type="button" id="ptlive-btn-cam" class="ptlive-btn">
                            <i class="fa-solid fa-video"></i>
                            <span>Camera</span>
                        </button>
                        <button type="button" id="ptlive-btn-mic" class="ptlive-btn">
                            <i class="fa-solid fa-microphone"></i>
                            <span>Micro</span>
                        </button>
                        <button type="button" id="ptlive-btn-screen" class="ptlive-btn">
                            <i class="fa-solid fa-desktop"></i>
                            <span>Chia sẻ màn hình</span>
                        </button>
                    </div>

                    <!-- Quick Reactions Bar (Available to both Viewers & Host) -->
                    <div class="ptlive-reaction-bar">
                        <span style="font-size:12px; color:var(--ptlive-muted); margin-right:4px;">Thả cảm xúc:</span>
                        <button type="button" class="ptlive-react-btn" data-emoji="❤️" title="Thả tim">❤️</button>
                        <button type="button" class="ptlive-react-btn" data-emoji="🔥" title="Cháy quá">🔥</button>
                        <button type="button" class="ptlive-react-btn" data-emoji="👏" title="Vỗ tay">👏</button>
                        <button type="button" class="ptlive-react-btn" data-emoji="🎉" title="Chúc mừng">🎉</button>
                        <button type="button" class="ptlive-react-btn" data-emoji="☕" title="Cafe">☕</button>
                    </div>
                </div>

                <!-- STREAMER PROFILE & STREAM INFO BAR -->
                <div class="ptlive-info-bar">
                    <div class="ptlive-streamer-profile">
                        <img id="ptlive-streamer-avatar" class="ptlive-streamer-avatar" src="<?php echo esc_url($initial_host['host_avatar']); ?>" alt="Streamer Avatar" />
                        <div class="ptlive-streamer-meta">
                            <h2 id="ptlive-stream-title-display" class="ptlive-stream-title-display">
                                <?php echo esc_html($initial_host['title']); ?>
                            </h2>
                            <div class="ptlive-streamer-sub">
                                <span id="ptlive-streamer-name" class="ptlive-streamer-name"><?php echo esc_html($initial_host['host_name']); ?></span>
                                <span id="ptlive-stream-category-display" class="ptlive-category-tag"><?php echo esc_html($initial_host['category']); ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="ptlive-info-actions">
                        <button type="button" id="ptlive-btn-follow" class="ptlive-btn">
                            <i class="fa-solid fa-heart"></i> Theo dõi
                        </button>
                        <button type="button" id="ptlive-btn-share-link" class="ptlive-btn">
                            <i class="fa-solid fa-share-nodes"></i> Chia sẻ
                        </button>
                    </div>
                </div>

                <!-- BROADCASTER STUDIO SETUP (Visible in Host Mode) -->
                <div id="ptlive-studio-setup" class="ptlive-studio-setup" style="display: none;">
                    <div class="ptlive-studio-setup-head">
                        <h4><i class="fa-solid fa-sliders"></i> Cấu hình Phòng Phát Trực Tiếp (Livestream Studio)</h4>
                    </div>
                    <div class="ptlive-setup-grid">
                        <div class="ptlive-field">
                            <label for="ptlive-input-title">Tiêu đề buổi phát trực tiếp</label>
                            <input type="text" id="ptlive-input-title" placeholder="Nhập tiêu đề livestream của bạn..." value="<?php echo esc_attr($user_name . ' đang phát trực tiếp'); ?>" />
                        </div>
                        <div class="ptlive-field">
                            <label for="ptlive-input-category">Chủ đề / Danh mục</label>
                            <select id="ptlive-input-category">
                                <option value="Giao lưu">Giao lưu &amp; Trò chuyện</option>
                                <option value="Cafe & Review">Cafe &amp; Review Địa điểm</option>
                                <option value="Âm nhạc">Âm nhạc &amp; Giải trí</option>
                                <option value="Ẩm thực">Ẩm thực &amp; Đời sống</option>
                                <option value="Sự kiện">Sự kiện nổi bật</option>
                            </select>
                        </div>
                        <div class="ptlive-field">
                            <label for="ptlive-select-camera">Thiết bị Camera</label>
                            <select id="ptlive-select-camera">
                                <option value="">Camera mặc định</option>
                            </select>
                        </div>
                        <div class="ptlive-field">
                            <label for="ptlive-select-mic">Thiết bị Microphone</label>
                            <select id="ptlive-select-mic">
                                <option value="">Microphone mặc định</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- RIGHT: REAL-TIME LIVE CHAT -->
            <aside class="ptlive-chat-card">
                <div class="ptlive-chat-header">
                    <h3><i class="fa-regular fa-comments"></i> Trò chuyện trực tiếp</h3>
                    <span id="ptlive-chat-room-tag" class="ptlive-chat-room-tag">#<?php echo esc_html($requested_room); ?></span>
                </div>

                <div id="ptlive-chat-messages" class="ptlive-chat-messages">
                    <div class="ptlive-chat-system">
                        Chào mừng bạn đến với phòng chat trực tiếp! Hãy giữ thái độ văn minh, lịch sự.
                    </div>
                </div>

                <div class="ptlive-chat-footer">
                    <form id="ptlive-chat-form" class="ptlive-chat-form" autocomplete="off">
                        <input
                            type="text"
                            id="ptlive-chat-input"
                            class="ptlive-chat-input"
                            placeholder="Nhập tin nhắn trò chuyện..."
                            maxlength="300"
                        />
                        <button type="submit" class="ptlive-btn btn-accent">
                            <i class="fa-solid fa-paper-plane"></i>
                        </button>
                    </form>
                </div>
            </aside>

        </section>

        <!-- DISCOVER ACTIVE LIVESTREAMS & CHANNELS -->
        <section class="ptlive-directory-section">
            <div class="ptlive-section-head">
                <h2><i class="fa-solid fa-fire" style="color:var(--ptlive-red);"></i> Danh sách Kênh Video &amp; Livestream</h2>
                <div class="ptlive-filter-pills">
                    <button type="button" class="ptlive-filter-pill is-active" data-category="all">Tất cả</button>
                    <button type="button" class="ptlive-filter-pill" data-category="Giao lưu">Giao lưu</button>
                    <button type="button" class="ptlive-filter-pill" data-category="Cafe & Review">Cafe &amp; Review</button>
                    <button type="button" class="ptlive-filter-pill" data-category="Âm nhạc">Âm nhạc</button>
                    <button type="button" class="ptlive-filter-pill" data-category="Ẩm thực">Ẩm thực</button>
                    <button type="button" class="ptlive-filter-pill" data-category="Sự kiện">Sự kiện</button>
                </div>
            </div>

            <div id="ptlive-streams-grid" class="ptlive-streams-grid"></div>
        </section>

    </div>
</main>

<div id="ptlive-toast" class="ptlive-toast"></div>

<script>
    window.ptLiveConfig = <?php echo wp_json_encode([
        'ajaxUrl'         => admin_url('admin-ajax.php'),
        'restTokenUrl'    => rest_url('enishi/v1/livekit-token'),
        'nonce'           => wp_create_nonce('pt_live_nonce'),
        'isLoggedIn'      => $is_logged_in,
        'userId'          => $user_id,
        'userName'        => $user_name,
        'userAvatar'      => $user_avatar,
        'userRoom'        => $user_room,
        'initialRoom'     => $requested_room,
        'initialMode'     => $requested_mode === 'host' ? 'host' : 'watch',
        'initialStreams'  => $active_streams,
        'initialHostInfo' => $initial_host,
    ]); ?>;
</script>
<script src="<?php echo esc_url(get_stylesheet_directory_uri() . '/assets-enishi/js/pt-live.js?v=' . filemtime(get_stylesheet_directory() . '/assets-enishi/js/pt-live.js')); ?>"></script>

<?php get_footer(); ?>