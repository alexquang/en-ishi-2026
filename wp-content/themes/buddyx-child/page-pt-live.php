<?php
/**
 * Template Name: Live Stream
 * Description: Livestream page for Channel -> Video (En-Ishi Live Studio & Viewer)
 */

defined('ABSPATH') || exit;

get_header();

$is_logged_in = is_user_logged_in();
$current_user = wp_get_current_user();
$user_id      = $is_logged_in ? (int) $current_user->ID : 0;
$user_name    = $is_logged_in ? ($current_user->display_name ?: $current_user->user_login) : 'Guest';
$user_avatar  = $is_logged_in ? get_avatar_url($user_id, ['size' => 96]) : 'https://www.gravatar.com/avatar/?d=mp&s=96';
$user_room    = $is_logged_in ? ('channel-' . sanitize_key($current_user->user_login ?: $user_id)) : 'enishi-main';

$requested_room  = isset($_GET['room']) ? sanitize_text_field(wp_unslash($_GET['room'])) : '';
$requested_mode  = isset($_GET['mode']) ? sanitize_text_field(wp_unslash($_GET['mode'])) : 'watch';
$place_id        = isset($_GET['place_id']) ? sanitize_text_field(wp_unslash($_GET['place_id'])) : '';
$place_name      = isset($_GET['place_name']) ? sanitize_text_field(wp_unslash($_GET['place_name'])) : '';
$place_address   = isset($_GET['place_address']) ? sanitize_text_field(wp_unslash($_GET['place_address'])) : '';

if ($is_logged_in && $place_id !== '' && $requested_mode === 'host') {
    $user_room = 'place-' . substr(md5($place_id), 0, 10) . '-u' . $user_id;
    if ($requested_room === '') {
        $requested_room = $user_room;
    }
}

$active_streams = function_exists('pt_get_active_livestreams_list')
    ? pt_get_active_livestreams_list()
    : [];

if ($requested_room === '') {
    $requested_room = !empty($active_streams[0]['room']) ? $active_streams[0]['room'] : 'enishi-main';
}

$initial_host = null;
foreach ($active_streams as $st) {
    if (!empty($st['room']) && $st['room'] === $requested_room) {
        $initial_host = $st;
        break;
    }
}
if (!$initial_host) {
    $initial_host = !empty($active_streams[0]) ? $active_streams[0] : [
        'room'        => $requested_room,
        'title'       => 'En-Ishi Official Live Channel - Community & Discovery',
        'category'    => 'Talk & Hangout',
        'host_name'   => 'En-Ishi Channel',
        'host_avatar' => $user_avatar,
        'viewers'     => 1,
        'is_live'     => true,
    ];
}
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" />
<link rel="stylesheet" href="<?php echo esc_url(get_stylesheet_directory_uri() . '/assets-enishi/css/pt-live.css?v=' . filemtime(get_stylesheet_directory() . '/assets-enishi/css/pt-live.css')); ?>" />
<script src="https://cdn.jsdelivr.net/npm/hls.js@1/dist/hls.min.js"></script>
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
                    <p>Real-time livestreaming &amp; community interaction on En-Ishi</p>
                </div>
            </div>

            <div class="ptlive-mode-tabs" role="tablist">
                <button type="button" id="ptlive-mode-watch" class="ptlive-mode-btn <?php echo $requested_mode !== 'host' ? 'is-active' : ''; ?>">
                    <i class="fa-solid fa-tv"></i>
                    <span>Watch Livestream</span>
                </button>
                <button type="button" id="ptlive-mode-host" class="ptlive-mode-btn mode-host <?php echo $requested_mode === 'host' ? 'is-active' : ''; ?>">
                    <i class="fa-solid fa-tower-broadcast"></i>
                    <span>Go Live</span>
                </button>
            </div>

            <div class="ptlive-top-stats">
                <div class="ptlive-stat-pill">
                    <i class="fa-solid fa-signal"></i>
                    <span>Live Now: <strong id="ptlive-active-total-count"><?php echo count($active_streams); ?></strong> channels</span>
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
                            <span class="ptlive-hud-pill" title="Viewers">
                                <i class="fa-solid fa-eye"></i>
                                <span id="ptlive-viewer-count"><?php echo esc_html((string) ($initial_host['viewers'] ?? 1)); ?></span>
                            </span>
                            <span class="ptlive-hud-pill" title="Stream Duration">
                                <i class="fa-regular fa-clock"></i>
                                <span id="ptlive-uptime">00:00</span>
                            </span>
                        </div>
                        <div class="ptlive-hud-right">
                            <button type="button" id="ptlive-btn-fullscreen" class="ptlive-hud-pill" style="cursor:pointer;" title="Fullscreen">
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
                        <h3 id="ptlive-standby-title">Welcome to the Video Livestream Channel</h3>
                        <p id="ptlive-standby-desc">
                            Select an active channel below to watch, or click <strong>Go Live</strong> to start your own broadcast.
                        </p>
                        <div class="ptlive-standby-actions">
                            <button type="button" id="ptlive-standby-primary-btn" class="ptlive-btn btn-primary">
                                <i class="fa-solid fa-video"></i> Go Live Now
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
                            <span>Camera Preview</span>
                        </button>
                        <button type="button" id="ptlive-btn-start" class="ptlive-btn btn-primary">
                            <i class="fa-solid fa-tower-broadcast"></i>
                            <span>Start Live</span>
                        </button>
                        <button type="button" id="ptlive-btn-stop" class="ptlive-btn btn-danger" style="display: none;">
                            <i class="fa-solid fa-stop"></i>
                            <span>End Live</span>
                        </button>
                        <button type="button" id="ptlive-btn-cam" class="ptlive-btn">
                            <i class="fa-solid fa-video"></i>
                            <span>Camera</span>
                        </button>
                        <button type="button" id="ptlive-btn-mic" class="ptlive-btn">
                            <i class="fa-solid fa-microphone"></i>
                            <span>Microphone</span>
                        </button>
                        <button type="button" id="ptlive-btn-screen" class="ptlive-btn">
                            <i class="fa-solid fa-desktop"></i>
                            <span>Share Screen</span>
                        </button>
                    </div>

                    <!-- Quick Reactions Bar (Available to both Viewers & Host) -->
                    <div class="ptlive-reaction-bar">
                        <span style="font-size:12px; color:var(--ptlive-muted); margin-right:4px;">Reactions:</span>
                        <button type="button" class="ptlive-react-btn" data-emoji="❤️" title="Love">❤️</button>
                        <button type="button" class="ptlive-react-btn" data-emoji="🔥" title="Fire">🔥</button>
                        <button type="button" class="ptlive-react-btn" data-emoji="👏" title="Clap">👏</button>
                        <button type="button" class="ptlive-react-btn" data-emoji="🎉" title="Party">🎉</button>
                        <button type="button" class="ptlive-react-btn" data-emoji="☕" title="Coffee">☕</button>
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
                            <i class="fa-solid fa-heart"></i> Follow
                        </button>
                        <button type="button" id="ptlive-btn-share-link" class="ptlive-btn">
                            <i class="fa-solid fa-share-nodes"></i> Share
                        </button>
                    </div>
                </div>

                <!-- BROADCASTER STUDIO SETUP (Visible in Host Mode) -->
                <div id="ptlive-studio-setup" class="ptlive-studio-setup" style="display: none;">
                    <div class="ptlive-studio-setup-head">
                        <h4><i class="fa-solid fa-sliders"></i> Livestream Studio Setup</h4>
                    </div>
                    <div class="ptlive-setup-grid">
                        <div class="ptlive-field">
                            <label for="ptlive-input-title">Stream Title</label>
                            <input type="text" id="ptlive-input-title" placeholder="Enter your livestream title..." value="<?php echo esc_attr($place_name !== '' ? ('Live at ' . $place_name) : ($user_name . ' is live now')); ?>" />
                        </div>
                        <div class="ptlive-field">
                            <label for="ptlive-input-category">Category / Topic</label>
                            <select id="ptlive-input-category">
                                <option value="Talk & Hangout">Talk &amp; Hangout</option>
                                <option value="Cafe & Review">Cafe &amp; Place Review</option>
                                <option value="Music">Music &amp; Entertainment</option>
                                <option value="Food & Life">Food &amp; Lifestyle</option>
                                <option value="Events">Featured Events</option>
                            </select>
                        </div>
                        <div class="ptlive-field">
                            <label for="ptlive-select-camera">Camera Device</label>
                            <select id="ptlive-select-camera">
                                <option value="">Default Camera</option>
                            </select>
                        </div>
                        <div class="ptlive-field">
                            <label for="ptlive-select-mic">Microphone Device</label>
                            <select id="ptlive-select-mic">
                                <option value="">Default Microphone</option>
                            </select>
                        </div>
                        <div class="ptlive-field" style="grid-column: 1 / -1;">
                            <label for="ptlive-input-hls-url">HLS Stream URL (.m3u8) <small style="opacity:0.7;">(Optional — leave blank to broadcast from Camera/Mic)</small></label>
                            <input type="url" id="ptlive-input-hls-url" placeholder="https://your-stream-server/live/stream.m3u8" value="" />
                        </div>
                    </div>
                </div>
            </div>

            <!-- RIGHT: REAL-TIME LIVE CHAT -->
            <aside class="ptlive-chat-card">
                <div class="ptlive-chat-header">
                    <h3><i class="fa-regular fa-comments"></i> Live Chat</h3>
                    <span id="ptlive-chat-room-tag" class="ptlive-chat-room-tag">#<?php echo esc_html($requested_room); ?></span>
                </div>

                <div id="ptlive-chat-messages" class="ptlive-chat-messages">
                    <div class="ptlive-chat-system">
                        Welcome to the live chat room! Please be respectful and friendly.
                    </div>
                </div>

                <div class="ptlive-chat-footer">
                    <form id="ptlive-chat-form" class="ptlive-chat-form" autocomplete="off">
                        <input
                            type="text"
                            id="ptlive-chat-input"
                            class="ptlive-chat-input"
                            placeholder="Send a chat message..."
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
                <h2><i class="fa-solid fa-fire" style="color:var(--ptlive-red);"></i> Live Video &amp; Stream Channels</h2>
                <div class="ptlive-filter-pills">
                    <button type="button" class="ptlive-filter-pill is-active" data-category="all">All</button>
                    <button type="button" class="ptlive-filter-pill" data-category="Talk & Hangout">Talk &amp; Hangout</button>
                    <button type="button" class="ptlive-filter-pill" data-category="Cafe & Review">Cafe &amp; Review</button>
                    <button type="button" class="ptlive-filter-pill" data-category="Music">Music</button>
                    <button type="button" class="ptlive-filter-pill" data-category="Food & Life">Food &amp; Life</button>
                    <button type="button" class="ptlive-filter-pill" data-category="Events">Events</button>
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
        'placeId'         => $place_id,
        'placeName'       => $place_name,
        'placeAddress'    => $place_address,
        'initialStreams'  => $active_streams,
        'initialHostInfo' => $initial_host,
    ]); ?>;
</script>
<script src="<?php echo esc_url(get_stylesheet_directory_uri() . '/assets-enishi/js/pt-live.js?v=' . filemtime(get_stylesheet_directory() . '/assets-enishi/js/pt-live.js')); ?>"></script>

<?php get_footer(); ?>