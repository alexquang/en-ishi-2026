/**
 * EN-ISHI LIVESTREAM STUDIO & VIEWER CONTROLLER (Channel -> Video)
 * Supports LiveKit WebRTC Streaming + Local Camera Preview Fallback + Real-Time Chat & Reactions
 */
(function () {
    'use strict';

    const cfg = window.ptLiveConfig || {};
    const ajaxUrl = cfg.ajaxUrl || '/wp-admin/admin-ajax.php';
    const restTokenUrl = cfg.restTokenUrl || '/wp-json/enishi/v1/livekit-token';

    const state = {
        mode: cfg.initialMode || 'watch', // 'watch' | 'host'
        roomName: cfg.initialRoom || 'enishi-main',
        isLive: false,
        isPreviewing: false,
        camEnabled: true,
        micEnabled: true,
        screenSharing: false,
        lkRoom: null,
        hlsInstance: null,
        mediaRecorder: null,
        chunkSeq: 0,
        chunkPollTimer: null,
        lastChunkSeq: -1,
        localStream: null,
        screenStream: null,
        uptimeSeconds: 0,
        uptimeTimer: null,
        heartbeatTimer: null,
        chatPollTimer: null,
        lastChatId: 0,
        seenChatIds: new Set(),
        activeCategoryFilter: 'all',
        streams: Array.isArray(cfg.initialStreams) ? cfg.initialStreams : [],
        currentHostInfo: cfg.initialHostInfo || null,
    };

    const els = {};

    function cacheElements() {
        els.modeWatchBtn = document.getElementById('ptlive-mode-watch');
        els.modeHostBtn = document.getElementById('ptlive-mode-host');
        els.videoMount = document.getElementById('ptlive-video-mount');
        els.standbyOverlay = document.getElementById('ptlive-standby-overlay');
        els.standbyTitle = document.getElementById('ptlive-standby-title');
        els.standbyDesc = document.getElementById('ptlive-standby-desc');
        els.standbyPrimaryBtn = document.getElementById('ptlive-standby-primary-btn');
        els.statusBadge = document.getElementById('ptlive-status-badge');
        els.viewerCount = document.getElementById('ptlive-viewer-count');
        els.uptimeDisplay = document.getElementById('ptlive-uptime');
        els.roomTag = document.getElementById('ptlive-room-tag');
        els.chatRoomTag = document.getElementById('ptlive-chat-room-tag');
        els.reactionsLayer = document.getElementById('ptlive-reactions-layer');

        // Host controls
        els.hostControlsGroup = document.getElementById('ptlive-host-controls');
        els.previewBtn = document.getElementById('ptlive-btn-preview');
        els.startLiveBtn = document.getElementById('ptlive-btn-start');
        els.stopLiveBtn = document.getElementById('ptlive-btn-stop');
        els.toggleCamBtn = document.getElementById('ptlive-btn-cam');
        els.toggleMicBtn = document.getElementById('ptlive-btn-mic');
        els.shareScreenBtn = document.getElementById('ptlive-btn-screen');
        els.studioSetupPanel = document.getElementById('ptlive-studio-setup');

        // Inputs in Studio Setup
        els.streamTitleInput = document.getElementById('ptlive-input-title');
        els.streamCategorySelect = document.getElementById('ptlive-input-category');
        els.cameraSelect = document.getElementById('ptlive-select-camera');
        els.micSelect = document.getElementById('ptlive-select-mic');
        els.hlsUrlInput = document.getElementById('ptlive-input-hls-url');

        // Streamer Info Bar
        els.streamerAvatar = document.getElementById('ptlive-streamer-avatar');
        els.streamTitleDisplay = document.getElementById('ptlive-stream-title-display');
        els.streamerName = document.getElementById('ptlive-streamer-name');
        els.streamCategoryDisplay = document.getElementById('ptlive-stream-category-display');
        els.followBtn = document.getElementById('ptlive-btn-follow');
        els.shareLinkBtn = document.getElementById('ptlive-btn-share-link');
        els.fullscreenBtn = document.getElementById('ptlive-btn-fullscreen');

        // Chat
        els.chatMessages = document.getElementById('ptlive-chat-messages');
        els.chatForm = document.getElementById('ptlive-chat-form');
        els.chatInput = document.getElementById('ptlive-chat-input');

        // Directory
        els.streamsGrid = document.getElementById('ptlive-streams-grid');
        els.activeTotalCount = document.getElementById('ptlive-active-total-count');
        els.toast = document.getElementById('ptlive-toast');
    }

    function showToast(msg) {
        if (!els.toast) return;
        els.toast.textContent = msg;
        els.toast.classList.add('is-visible');
        clearTimeout(els.toast._timer);
        els.toast._timer = setTimeout(() => {
            els.toast.classList.remove('is-visible');
        }, 3200);
    }

    function formatDuration(sec) {
        const hrs = Math.floor(sec / 3600);
        const mins = Math.floor((sec % 3600) / 60);
        const s = sec % 60;
        const pad = (n) => (n < 10 ? '0' + n : '' + n);
        return hrs > 0
            ? `${pad(hrs)}:${pad(mins)}:${pad(s)}`
            : `${pad(mins)}:${pad(s)}`;
    }

    function startUptimeClock(initialSeconds = 0) {
        stopUptimeClock();
        state.uptimeSeconds = initialSeconds;
        if (els.uptimeDisplay) {
            els.uptimeDisplay.textContent = formatDuration(state.uptimeSeconds);
        }
        state.uptimeTimer = setInterval(() => {
            state.uptimeSeconds += 1;
            if (els.uptimeDisplay) {
                els.uptimeDisplay.textContent = formatDuration(state.uptimeSeconds);
            }
        }, 1000);
    }

    function stopUptimeClock() {
        if (state.uptimeTimer) {
            clearInterval(state.uptimeTimer);
            state.uptimeTimer = null;
        }
        if (els.uptimeDisplay) {
            els.uptimeDisplay.textContent = '00:00';
        }
    }

    function updateStatusUI(isLive, statusText) {
        if (!els.statusBadge) return;
        if (isLive) {
            els.statusBadge.classList.add('is-live');
            els.statusBadge.innerHTML = `<span class="ptlive-dot"></span> ${statusText || 'LIVE'}`;
        } else {
            els.statusBadge.classList.remove('is-live');
            els.statusBadge.innerHTML = `<i class="fa-solid fa-circle-pause"></i> ${statusText || 'OFFLINE'}`;
        }
    }

    /* ----------------------------------------------------------------------
       MEDIA DEVICES (CAMERA & MICROPHONE)
       ---------------------------------------------------------------------- */
    async function populateDevices() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices) return;
        try {
            const devices = await navigator.mediaDevices.enumerateDevices();
            const videoInputs = devices.filter((d) => d.kind === 'videoinput');
            const audioInputs = devices.filter((d) => d.kind === 'audioinput');

            if (els.cameraSelect && videoInputs.length > 0) {
                const currentCam = els.cameraSelect.value;
                els.cameraSelect.innerHTML = videoInputs
                    .map((d, idx) => `<option value="${d.deviceId}">${d.label || 'Camera ' + (idx + 1)}</option>`)
                    .join('');
                if (currentCam) els.cameraSelect.value = currentCam;
            }

            if (els.micSelect && audioInputs.length > 0) {
                const currentMic = els.micSelect.value;
                els.micSelect.innerHTML = audioInputs
                    .map((d, idx) => `<option value="${d.deviceId}">${d.label || 'Microphone ' + (idx + 1)}</option>`)
                    .join('');
                if (currentMic) els.micSelect.value = currentMic;
            }
        } catch (err) {
            console.warn('Could not enumerate devices:', err);
        }
    }

    async function startLocalCameraPreview() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            showToast('Your browser does not support Camera/Microphone access.');
            return null;
        }
        try {
            stopLocalStreams();
            const camId = els.cameraSelect && els.cameraSelect.value ? els.cameraSelect.value : undefined;
            const micId = els.micSelect && els.micSelect.value ? els.micSelect.value : undefined;

            const constraints = {
                video: camId ? { deviceId: { exact: camId }, width: { ideal: 1280 }, height: { ideal: 720 } } : { width: { ideal: 1280 }, height: { ideal: 720 } },
                audio: micId ? { deviceId: { exact: micId } } : true,
            };

            const stream = await navigator.mediaDevices.getUserMedia(constraints);
            state.localStream = stream;
            state.isPreviewing = true;

            attachMediaStreamToStage(stream, true);
            if (els.standbyOverlay) els.standbyOverlay.classList.add('is-hidden');
            await populateDevices();
            return stream;
        } catch (err) {
            console.error('Camera preview error:', err);
            showToast('Cannot access Camera/Microphone: ' + (err.message || 'Please grant permission'));
            return null;
        }
    }

    function attachMediaStreamToStage(stream, muted = false, isScreen = false) {
        if (!els.videoMount) return;
        els.videoMount.innerHTML = '';
        const video = document.createElement('video');
        video.autoplay = true;
        video.playsInline = true;
        video.muted = muted;
        if (isScreen) video.className = 'ptlive-screen-video';
        video.srcObject = stream;
        els.videoMount.appendChild(video);
    }

    function stopLocalStreams() {
        if (state.localStream) {
            state.localStream.getTracks().forEach((t) => t.stop());
            state.localStream = null;
        }
        if (state.screenStream) {
            state.screenStream.getTracks().forEach((t) => t.stop());
            state.screenStream = null;
        }
        state.isPreviewing = false;
        state.screenSharing = false;
        if (els.shareScreenBtn) els.shareScreenBtn.classList.remove('is-active-share');
    }

    /* ----------------------------------------------------------------------
       LIVEKIT & BROADCAST / WATCH ENGINE
       ---------------------------------------------------------------------- */
    /* ----------------------------------------------------------------------
       HLS.JS PLAYER & CHUNKED LIVE RELAY (1-HOST -> VIEWERS)
       ---------------------------------------------------------------------- */
    function destroyHlsPlayer() {
        if (state.hlsInstance) {
            try {
                state.hlsInstance.destroy();
            } catch (e) {}
            state.hlsInstance = null;
        }
    }

    function createVideoElement(muted = false) {
        if (!els.videoMount) return null;
        els.videoMount.innerHTML = '';
        const video = document.createElement('video');
        video.autoplay = true;
        video.playsInline = true;
        video.controls = true;
        video.muted = muted;
        video.style.width = '100%';
        video.style.height = '100%';
        video.style.objectFit = 'cover';
        els.videoMount.appendChild(video);
        return video;
    }

    function playHlsStream(hlsUrl, muted = false) {
        if (!hlsUrl) return false;
        destroyHlsPlayer();
        stopChunkViewer();

        const video = createVideoElement(muted);
        if (!video) return false;

        if (window.Hls && window.Hls.isSupported()) {
            const hls = new window.Hls({
                lowLatencyMode: true,
                enableWorker: true,
                liveSyncDurationCount: 3,
                liveMaxLatencyDurationCount: 6,
                backBufferLength: 30,
            });
            state.hlsInstance = hls;

            hls.loadSource(hlsUrl);
            hls.attachMedia(video);

            hls.on(window.Hls.Events.MANIFEST_PARSED, () => {
                if (els.standbyOverlay) els.standbyOverlay.classList.add('is-hidden');
                updateStatusUI(true, '🔴 LIVE');
                startUptimeClock(0);
                video.play().catch(() => {
                    video.muted = true;
                    video.play().catch(() => {});
                });
            });

            hls.on(window.Hls.Events.ERROR, (event, data) => {
                if (!data || !data.fatal) return;
                if (data.type === window.Hls.ErrorTypes.NETWORK_ERROR) {
                    hls.startLoad();
                } else if (data.type === window.Hls.ErrorTypes.MEDIA_ERROR) {
                    hls.recoverMediaError();
                } else {
                    destroyHlsPlayer();
                }
            });

            return true;
        }

        if (video.canPlayType('application/vnd.apple.mpegurl')) {
            video.src = hlsUrl;
            video.addEventListener('loadedmetadata', () => {
                if (els.standbyOverlay) els.standbyOverlay.classList.add('is-hidden');
                updateStatusUI(true, '🔴 LIVE');
                startUptimeClock(0);
                video.play().catch(() => {});
            });
            return true;
        }

        return false;
    }

    function pickSupportedRecorderMime() {
        if (typeof MediaRecorder === 'undefined') return '';
        const candidates = [
            'video/webm;codecs=vp8,opus',
            'video/webm;codecs=vp9,opus',
            'video/webm',
            'video/mp4',
        ];
        for (const mime of candidates) {
            if (MediaRecorder.isTypeSupported(mime)) return mime;
        }
        return '';
    }

    function startChunkRecorder() {
        stopChunkRecorder();
        if (!state.localStream || typeof MediaRecorder === 'undefined') return;

        const mime = pickSupportedRecorderMime();
        state.chunkSeq = 0;

        try {
            const recorder = mime
                ? new MediaRecorder(state.localStream, { mimeType: mime, videoBitsPerSecond: 900000 })
                : new MediaRecorder(state.localStream);
            state.mediaRecorder = recorder;
            state.recordedChunks = [];

            recorder.ondataavailable = async (ev) => {
                if (!state.isLive || !ev.data || ev.data.size === 0) return;
                state.recordedChunks.push(ev.data);
                const seq = state.chunkSeq++;
                const form = new FormData();
                form.append('action', 'pt_live_push_chunk');
                form.append('nonce', cfg.nonce || '');
                form.append('room', state.roomName);
                form.append('seq', String(seq));
                form.append('mime', ev.data.type || mime || 'video/webm');
                form.append('chunk', ev.data, `chunk_${seq}.webm`);

                try {
                    await fetch(ajaxUrl, {
                        method: 'POST',
                        body: form,
                        credentials: 'same-origin',
                    });
                } catch (e) {}
            };

            recorder.start(2000);
        } catch (e) {
            console.warn('Chunk recorder unavailable:', e);
        }
    }

    function stopChunkRecorder() {
        if (state.mediaRecorder) {
            try {
                if (state.mediaRecorder.state !== 'inactive') {
                    state.mediaRecorder.stop();
                }
            } catch (e) {}
            state.mediaRecorder = null;
        }
    }

    function stopChunkViewer() {
        if (state.chunkPollTimer) {
            clearInterval(state.chunkPollTimer);
            state.chunkPollTimer = null;
        }
        state.lastChunkSeq = -1;
    }

    function startChunkViewer(roomName) {
        stopChunkViewer();
        if (!els.videoMount) return;

        const video = createVideoElement(false);
        if (!video) return;

        let mediaSource = null;
        let sourceBuffer = null;
        const queue = [];
        let appending = false;
        let mseInitialized = false;

        function pumpQueue() {
            if (appending || !sourceBuffer || sourceBuffer.updating || queue.length === 0) return;
            appending = true;
            const buf = queue.shift();
            try {
                sourceBuffer.appendBuffer(buf);
            } catch (e) {
                appending = false;
            }
        }

        const pollChunks = async () => {
            if (state.roomName !== roomName || (state.isLive && state.mode === 'host')) {
                stopChunkViewer();
                return;
            }
            try {
                const res = await fetch(
                    `${ajaxUrl}?action=pt_live_get_chunks&room=${encodeURIComponent(roomName)}`,
                    { credentials: 'same-origin' }
                );
                const json = await res.json();
                if (!json || !json.success || !json.data || !json.data.active) {
                    return;
                }

                const chunks = Array.isArray(json.data.chunks) ? json.data.chunks : [];
                const mime = json.data.mime || 'video/webm;codecs=vp8,opus';
                if (chunks.length === 0) return;

                if (!mseInitialized && window.MediaSource && MediaSource.isTypeSupported(mime)) {
                    mseInitialized = true;
                    mediaSource = new MediaSource();
                    video.src = URL.createObjectURL(mediaSource);
                    mediaSource.addEventListener('sourceopen', () => {
                        try {
                            sourceBuffer = mediaSource.addSourceBuffer(mime);
                            sourceBuffer.mode = 'sequence';
                            sourceBuffer.addEventListener('updateend', () => {
                                appending = false;
                                pumpQueue();
                            });
                            pumpQueue();
                        } catch (e) {}
                    });
                }

                const newChunks = chunks.filter((c) => Number(c.seq) > state.lastChunkSeq);
                if (newChunks.length === 0) return;

                if (els.standbyOverlay) els.standbyOverlay.classList.add('is-hidden');
                updateStatusUI(true, '🔴 LIVE');
                if (!state.uptimeTimer) startUptimeClock(0);

                for (const c of newChunks) {
                    state.lastChunkSeq = Math.max(state.lastChunkSeq, Number(c.seq));
                    if (mseInitialized) {
                        const arrBuf = await fetch(c.url).then((r) => r.arrayBuffer());
                        queue.push(arrBuf);
                        pumpQueue();
                        video.play().catch(() => {
                            video.muted = true;
                            video.play().catch(() => {});
                        });
                    } else {
                        // Sequential fallback if MSE codec is not supported
                        video.src = c.url;
                        video.play().catch(() => {});
                    }
                }
            } catch (e) {}
        };

        pollChunks();
        state.chunkPollTimer = setInterval(pollChunks, 2000);
    }

    async function fetchLivekitToken(roomName, asHost = false) {
        const url = new URL(restTokenUrl, window.location.origin);
        url.searchParams.set('room', roomName);
        url.searchParams.set('role', asHost ? 'host' : 'viewer');

        const res = await fetch(url.toString(), { credentials: 'same-origin' });
        if (!res.ok) {
            throw new Error('Failed to fetch LiveKit token (' + res.status + ')');
        }
        return await res.json();
    }

    async function startBroadcast() {
        if (!cfg.isLoggedIn) {
            if (typeof window.openLoginModal === 'function') {
                window.openLoginModal();
            } else {
                showToast('Please log in to start a livestream (Go Live).');
            }
            return;
        }

        const myRoom = cfg.userRoom || ('channel-' + (cfg.userId || 'host'));
        state.roomName = myRoom;

        const streamTitle = (els.streamTitleInput && els.streamTitleInput.value.trim()) || ((cfg.userName || 'Host') + ' is live now');
        const streamCategory = (els.streamCategorySelect && els.streamCategorySelect.value) || 'Talk & Hangout';
        const hlsUrl = (els.hlsUrlInput && els.hlsUrlInput.value.trim()) || '';

        if (els.startLiveBtn) els.startLiveBtn.disabled = true;
        updateStatusUI(false, 'CONNECTING...');

        let connectedToLiveKit = false;
        if (hlsUrl) {
            // Host provided an HLS (.m3u8) stream URL (e.g., OBS / MediaMTX)
            playHlsStream(hlsUrl, true);
        } else {
            // Ensure local camera stream is ready first so broadcaster always sees video immediately
            if (!state.localStream) {
                const preview = await startLocalCameraPreview();
                if (!preview) {
                    if (els.startLiveBtn) els.startLiveBtn.disabled = false;
                    updateStatusUI(false, 'OFFLINE');
                    return;
                }
            }

            // Try connecting to LiveKit server if available
            if (window.LivekitClient) {
                try {
                    const tokenData = await fetchLivekitToken(state.roomName, true);
                    if (state.lkRoom) {
                        await state.lkRoom.disconnect();
                        state.lkRoom = null;
                    }

                    const lkRoom = new window.LivekitClient.Room({
                        adaptiveStream: true,
                        dynacast: true,
                    });

                    bindLiveKitRoomEvents(lkRoom);

                    await Promise.race([
                        lkRoom.connect(tokenData.url, tokenData.token),
                        new Promise((_, reject) => setTimeout(() => reject(new Error('LiveKit connect timeout')), 5000)),
                    ]);

                    // Publish tracks from localStream
                    if (state.localStream) {
                        for (const track of state.localStream.getTracks()) {
                            await lkRoom.localParticipant.publishTrack(track);
                        }
                    }

                    state.lkRoom = lkRoom;
                    connectedToLiveKit = true;
                } catch (lkErr) {
                    console.warn('LiveKit server fallback to chunk relay:', lkErr.message);
                }
            }
        }

        // Register active stream in WordPress backend so viewers see it in Channel -> Video & Place Live
        try {
            const body = new URLSearchParams({
                action: 'pt_live_start_stream',
                nonce: cfg.nonce || '',
                room: state.roomName,
                title: streamTitle,
                category: streamCategory,
                hls_url: hlsUrl,
                place_id: cfg.placeId || '',
                place_name: cfg.placeName || '',
                place_address: cfg.placeAddress || '',
            });
            const resp = await fetch(ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
                credentials: 'same-origin',
            });
            const json = await resp.json();
            if (json && json.success && json.data && json.data.streams) {
                state.streams = json.data.streams;
                renderStreamsDirectory();
            }
        } catch (e) {
            console.warn('Could not register stream in WP backend:', e);
        }

        state.isLive = true;
        updateStatusUI(true, '🔴 LIVE');
        startUptimeClock(0);
        if (els.standbyOverlay) els.standbyOverlay.classList.add('is-hidden');

        if (!hlsUrl && !connectedToLiveKit) {
            startChunkRecorder();
        }

        if (els.startLiveBtn) {
            els.startLiveBtn.disabled = false;
            els.startLiveBtn.style.display = 'none';
        }
        if (els.stopLiveBtn) {
            els.stopLiveBtn.style.display = 'inline-flex';
        }

        // Update header display
        if (els.streamTitleDisplay) els.streamTitleDisplay.textContent = streamTitle;
        if (els.streamCategoryDisplay) els.streamCategoryDisplay.textContent = streamCategory;
        if (els.streamerName) els.streamerName.textContent = cfg.userName || 'Host';
        if (els.streamerAvatar && cfg.userAvatar) els.streamerAvatar.src = cfg.userAvatar;
        if (els.roomTag) els.roomTag.textContent = state.roomName;
        if (els.chatRoomTag) els.chatRoomTag.textContent = '#' + state.roomName;

        appendSystemChatMessage(
            connectedToLiveKit
                ? '🎥 You are now live via LiveKit Server!'
                : '🎥 You are now live (Direct Camera Studio Mode)!'
        );
        showToast('Livestream started!');

        startHeartbeat();
    }

    async function stopBroadcast() {
        stopHeartbeat();
        stopUptimeClock();
        stopChunkRecorder();
        stopChunkViewer();
        destroyHlsPlayer();

        if (state.lkRoom) {
            try {
                await state.lkRoom.disconnect();
            } catch (e) {}
            state.lkRoom = null;
        }

        stopLocalStreams();
        state.isLive = false;

        if (els.videoMount) els.videoMount.innerHTML = '';
        if (els.standbyOverlay) els.standbyOverlay.classList.remove('is-hidden');
        updateStatusUI(false, 'OFFLINE');

        if (els.startLiveBtn) els.startLiveBtn.style.display = 'inline-flex';
        if (els.stopLiveBtn) els.stopLiveBtn.style.display = 'none';

        try {
            const body = new URLSearchParams({
                action: 'pt_live_stop_stream',
                nonce: cfg.nonce || '',
                room: state.roomName,
            });
            const resp = await fetch(ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
                credentials: 'same-origin',
            });
            const json = await resp.json();
            if (json && json.success && json.data && json.data.streams) {
                state.streams = json.data.streams;
                renderStreamsDirectory();
            }
        } catch (e) {}

        appendSystemChatMessage('⏹ The livestream session has ended.');
        showToast('Livestream ended.');
    }

    async function joinRoomAsViewer(roomName, streamMeta = null) {
        if (state.isLive && state.mode === 'host') {
            showToast('Please end your active livestream before switching channels.');
            return;
        }

        state.roomName = roomName;
        if (els.roomTag) els.roomTag.textContent = roomName;
        if (els.chatRoomTag) els.chatRoomTag.textContent = '#' + roomName;

        if (streamMeta) {
            state.currentHostInfo = streamMeta;
            if (els.streamTitleDisplay) els.streamTitleDisplay.textContent = streamMeta.title || 'Livestream Channel';
            if (els.streamerName) els.streamerName.textContent = streamMeta.host_name || 'Streamer';
            if (els.streamCategoryDisplay) els.streamCategoryDisplay.textContent = streamMeta.category || 'Livestream';
            if (els.streamerAvatar && streamMeta.host_avatar) els.streamerAvatar.src = streamMeta.host_avatar;
            if (els.viewerCount) els.viewerCount.textContent = streamMeta.viewers || 1;
        }

        // Reset chat history for new room
        state.lastChatId = 0;
        state.seenChatIds.clear();
        if (els.chatMessages) {
            els.chatMessages.innerHTML = '';
        }
        appendSystemChatMessage(`👋 Joined chat room #${roomName}`);
        fetchChatMessages(true);

        // Highlight active card
        document.querySelectorAll('.ptlive-stream-card').forEach((card) => {
            card.classList.toggle('is-watching', card.dataset.room === roomName);
        });

        // Reset previous HLS / chunk / LiveKit players before joining
        destroyHlsPlayer();
        stopChunkViewer();
        if (state.lkRoom) {
            try {
                await state.lkRoom.disconnect();
            } catch (e) {}
            state.lkRoom = null;
        }

        const matchedMeta = streamMeta || state.streams.find((s) => s && s.room === roomName) || null;
        const hlsUrl = matchedMeta && matchedMeta.hls_url ? matchedMeta.hls_url : '';

        // 1. Primary: Play HLS (.m3u8) stream via hls.js
        if (hlsUrl && playHlsStream(hlsUrl, false)) {
            return;
        }

        // 2. Fallback: LiveKit WebRTC if available, else WordPress chunked live relay
        let receivedLiveKitTrack = false;
        if (window.LivekitClient) {
            try {
                const tokenData = await fetchLivekitToken(roomName, false);
                const lkRoom = new window.LivekitClient.Room({
                    adaptiveStream: true,
                    dynacast: true,
                });

                lkRoom.on(window.LivekitClient.RoomEvent.TrackSubscribed, (track) => {
                    if (track.kind === 'video') {
                        receivedLiveKitTrack = true;
                        stopChunkViewer();
                    }
                });

                bindLiveKitRoomEvents(lkRoom);

                await Promise.race([
                    lkRoom.connect(tokenData.url, tokenData.token),
                    new Promise((_, reject) => setTimeout(() => reject(new Error('LiveKit timeout')), 4500)),
                ]);

                state.lkRoom = lkRoom;
                updateRemoteViewerCount();

                // If host isn't publishing via LiveKit (e.g. direct browser broadcast), switch to chunk viewer
                setTimeout(() => {
                    if (!receivedLiveKitTrack && state.roomName === roomName && !state.hlsInstance) {
                        startChunkViewer(roomName);
                    }
                }, 2500);
                return;
            } catch (err) {
                console.warn('Viewer LiveKit fallback to chunk relay:', err.message);
            }
        }

        startChunkViewer(roomName);
    }

    function bindLiveKitRoomEvents(lkRoom) {
        if (!window.LivekitClient) return;
        const RoomEvent = window.LivekitClient.RoomEvent;

        lkRoom.on(RoomEvent.TrackSubscribed, (track, publication, participant) => {
            if (track.kind === 'video') {
                if (els.videoMount) {
                    els.videoMount.innerHTML = '';
                    const el = track.attach();
                    el.autoplay = true;
                    el.playsInline = true;
                    els.videoMount.appendChild(el);
                }
                if (els.standbyOverlay) els.standbyOverlay.classList.add('is-hidden');
                updateStatusUI(true, '🔴 LIVE');
                startUptimeClock(0);
            } else if (track.kind === 'audio') {
                const audioEl = track.attach();
                document.body.appendChild(audioEl);
            }
            updateRemoteViewerCount();
        });

        lkRoom.on(RoomEvent.TrackUnsubscribed, (track) => {
            track.detach().forEach((el) => el.remove());
            updateRemoteViewerCount();
        });

        lkRoom.on(RoomEvent.ParticipantConnected, () => {
            updateRemoteViewerCount();
        });

        lkRoom.on(RoomEvent.ParticipantDisconnected, () => {
            updateRemoteViewerCount();
        });

        lkRoom.on(RoomEvent.DataReceived, (payload, participant) => {
            try {
                const raw = new TextDecoder().decode(payload);
                let data;
                try {
                    data = JSON.parse(raw);
                } catch (_) {
                    data = { type: 'chat', user: participant ? participant.identity : 'User', text: raw };
                }

                if (data.type === 'reaction') {
                    spawnFloatingReaction(data.emoji || '❤️');
                } else if (data.type === 'chat' && data.text) {
                    if (data.id && state.seenChatIds.has(String(data.id))) return;
                    if (data.id) state.seenChatIds.add(String(data.id));
                    appendChatMessage({
                        id: data.id || Date.now(),
                        user: data.user || (participant ? participant.identity : 'Guest'),
                        avatar: data.avatar || '',
                        text: data.text,
                        time: data.time || '',
                        is_host: !!data.is_host,
                    });
                }
            } catch (e) {
                console.warn('DataReceived parse error:', e);
            }
        });
    }

    function updateRemoteViewerCount() {
        if (!state.lkRoom || !els.viewerCount) return;
        const count = (state.lkRoom.remoteParticipants ? state.lkRoom.remoteParticipants.size : 0) + 1;
        els.viewerCount.textContent = count;
    }

    /* ----------------------------------------------------------------------
       CAMERA / MIC / SCREEN SHARE CONTROLS
       ---------------------------------------------------------------------- */
    async function toggleCamera() {
        state.camEnabled = !state.camEnabled;
        if (state.localStream) {
            state.localStream.getVideoTracks().forEach((t) => {
                t.enabled = state.camEnabled;
            });
        }
        if (els.toggleCamBtn) {
            els.toggleCamBtn.classList.toggle('is-off', !state.camEnabled);
            els.toggleCamBtn.innerHTML = state.camEnabled
                ? '<i class="fa-solid fa-video"></i> <span>Camera</span>'
                : '<i class="fa-solid fa-video-slash"></i> <span>Camera Off</span>';
        }
        showToast(state.camEnabled ? 'Camera turned on' : 'Camera turned off');
    }

    async function toggleMicrophone() {
        state.micEnabled = !state.micEnabled;
        if (state.localStream) {
            state.localStream.getAudioTracks().forEach((t) => {
                t.enabled = state.micEnabled;
            });
        }
        if (els.toggleMicBtn) {
            els.toggleMicBtn.classList.toggle('is-off', !state.micEnabled);
            els.toggleMicBtn.innerHTML = state.micEnabled
                ? '<i class="fa-solid fa-microphone"></i> <span>Microphone</span>'
                : '<i class="fa-solid fa-microphone-slash"></i> <span>Muted</span>';
        }
        showToast(state.micEnabled ? 'Microphone unmuted' : 'Microphone muted');
    }

    async function toggleScreenShare() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getDisplayMedia) {
            showToast('Your browser does not support screen sharing.');
            return;
        }

        if (state.screenSharing) {
            if (state.screenStream) {
                state.screenStream.getTracks().forEach((t) => t.stop());
                state.screenStream = null;
            }
            state.screenSharing = false;
            if (els.shareScreenBtn) els.shareScreenBtn.classList.remove('is-active-share');
            if (state.localStream) {
                attachMediaStreamToStage(state.localStream, true, false);
            }
            showToast('Screen sharing stopped.');
            return;
        }

        try {
            const screenStream = await navigator.mediaDevices.getDisplayMedia({
                video: true,
                audio: true,
            });
            state.screenStream = screenStream;
            state.screenSharing = true;
            if (els.shareScreenBtn) els.shareScreenBtn.classList.add('is-active-share');

            attachMediaStreamToStage(screenStream, true, true);
            if (els.standbyOverlay) els.standbyOverlay.classList.add('is-hidden');

            const videoTrack = screenStream.getVideoTracks()[0];
            if (videoTrack) {
                videoTrack.onended = () => {
                    state.screenSharing = false;
                    if (els.shareScreenBtn) els.shareScreenBtn.classList.remove('is-active-share');
                    if (state.localStream) {
                        attachMediaStreamToStage(state.localStream, true, false);
                    }
                };
            }

            if (state.lkRoom && state.lkRoom.localParticipant && videoTrack) {
                await state.lkRoom.localParticipant.publishTrack(videoTrack);
            }

            showToast('Sharing screen!');
        } catch (err) {
            console.warn('Screen share cancelled:', err);
        }
    }

    /* ----------------------------------------------------------------------
       MODE SWITCHING: WATCH VS BROADCAST STUDIO
       ---------------------------------------------------------------------- */
    function switchMode(mode) {
        state.mode = mode === 'host' ? 'host' : 'watch';

        if (els.modeWatchBtn) els.modeWatchBtn.classList.toggle('is-active', state.mode === 'watch');
        if (els.modeHostBtn) els.modeHostBtn.classList.toggle('is-active', state.mode === 'host');

        if (els.hostControlsGroup) {
            els.hostControlsGroup.style.display = state.mode === 'host' ? 'flex' : 'none';
        }
        if (els.studioSetupPanel) {
            els.studioSetupPanel.style.display = state.mode === 'host' ? 'block' : 'none';
        }

        if (state.mode === 'host') {
            const hostRoom = cfg.userRoom || ('channel-' + (cfg.userId || 'host'));
            state.roomName = hostRoom;
            if (els.roomTag) els.roomTag.textContent = hostRoom;
            if (els.chatRoomTag) els.chatRoomTag.textContent = '#' + hostRoom;

            if (!state.isLive && !state.isPreviewing) {
                if (els.standbyOverlay) els.standbyOverlay.classList.remove('is-hidden');
                if (els.standbyTitle) els.standbyTitle.textContent = 'Livestream Studio (Go Live)';
                if (els.standbyDesc) {
                    els.standbyDesc.textContent =
                        'Test your Camera & Microphone with "Camera Preview" or click "Start Live" to begin broadcasting right away.';
                }
                if (els.standbyPrimaryBtn) {
                    els.standbyPrimaryBtn.innerHTML = '<i class="fa-solid fa-tower-broadcast"></i> Start Livestream';
                }
            }
            populateDevices();
        } else {
            if (!state.isLive) {
                stopLocalStreams();
                if (els.videoMount) els.videoMount.innerHTML = '';
                if (els.standbyOverlay) els.standbyOverlay.classList.remove('is-hidden');
                if (els.standbyTitle) els.standbyTitle.textContent = 'Welcome to the Video Livestream Channel';
                if (els.standbyDesc) {
                    els.standbyDesc.textContent =
                        'Select an active channel below to watch, or switch to "Go Live" mode to start your own broadcast.';
                }
                if (els.standbyPrimaryBtn) {
                    els.standbyPrimaryBtn.innerHTML = '<i class="fa-solid fa-video"></i> Go Live Now';
                }
            }
        }
    }

    /* ----------------------------------------------------------------------
       CHAT & REACTIONS (LIVEKIT DATACHANNEL + WP AJAX PERSISTENCE)
       ---------------------------------------------------------------------- */
    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str == null ? '' : String(str);
        return div.innerHTML;
    }

    function appendChatMessage(msg) {
        if (!els.chatMessages) return;
        const row = document.createElement('div');
        row.className = 'ptlive-chat-msg';
        const avatar = msg.avatar || cfg.defaultAvatar || 'https://www.gravatar.com/avatar/?d=mp&s=64';
        const authorClass = msg.is_host ? 'ptlive-chat-author is-host' : 'ptlive-chat-author';

        row.innerHTML = `
            <img src="${escapeHtml(avatar)}" alt="${escapeHtml(msg.user)}">
            <div class="ptlive-chat-bubble">
                <span class="ptlive-chat-time">${escapeHtml(msg.time || '')}</span>
                <span class="${authorClass}">${escapeHtml(msg.user)}:</span>
                <span class="ptlive-chat-text">${escapeHtml(msg.text)}</span>
            </div>
        `;
        els.chatMessages.appendChild(row);
        els.chatMessages.scrollTop = els.chatMessages.scrollHeight;
    }

    function appendSystemChatMessage(text) {
        if (!els.chatMessages) return;
        const div = document.createElement('div');
        div.className = 'ptlive-chat-system';
        div.textContent = text;
        els.chatMessages.appendChild(div);
        els.chatMessages.scrollTop = els.chatMessages.scrollHeight;
    }

    async function sendChatMessage(text) {
        const trimmed = (text || '').trim();
        if (!trimmed) return;

        const msgId = 'msg_' + Date.now() + '_' + Math.random().toString(36).slice(2, 7);
        const nowStr = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        const payloadObj = {
            id: msgId,
            type: 'chat',
            room: state.roomName,
            user: cfg.userName || 'Guest',
            avatar: cfg.userAvatar || '',
            text: trimmed,
            time: nowStr,
            is_host: state.mode === 'host' && state.isLive,
        };

        state.seenChatIds.add(msgId);
        appendChatMessage(payloadObj);

        // Broadcast via LiveKit DataChannel if connected
        if (state.lkRoom && state.lkRoom.localParticipant) {
            try {
                await state.lkRoom.localParticipant.publishData(
                    new TextEncoder().encode(JSON.stringify(payloadObj)),
                    { reliable: true }
                );
            } catch (e) {}
        }

        // Persist via WordPress AJAX so all viewers receive it
        try {
            const body = new URLSearchParams({
                action: 'pt_live_send_chat',
                nonce: cfg.nonce || '',
                room: state.roomName,
                msg_id: msgId,
                text: trimmed,
                is_host: payloadObj.is_host ? '1' : '0',
            });
            await fetch(ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
                credentials: 'same-origin',
            });
        } catch (e) {}
    }

    async function fetchChatMessages(initial = false) {
        try {
            const url = new URL(ajaxUrl, window.location.origin);
            url.searchParams.set('action', 'pt_live_get_state');
            url.searchParams.set('room', state.roomName);

            const res = await fetch(url.toString(), { credentials: 'same-origin' });
            const json = await res.json();
            if (!json || !json.success || !json.data) return;

            const data = json.data;
            if (Array.isArray(data.messages)) {
                data.messages.forEach((m) => {
                    if (!m.id || state.seenChatIds.has(String(m.id))) return;
                    state.seenChatIds.add(String(m.id));
                    appendChatMessage(m);
                });
            }

            if (Array.isArray(data.streams)) {
                state.streams = data.streams;
                renderStreamsDirectory();
            }
        } catch (e) {}
    }

    function spawnFloatingReaction(emoji = '❤️') {
        if (!els.reactionsLayer) return;
        const span = document.createElement('span');
        span.className = 'ptlive-floating-heart';
        span.textContent = emoji;
        span.style.left = Math.floor(10 + Math.random() * 55) + 'px';
        els.reactionsLayer.appendChild(span);
        setTimeout(() => {
            span.remove();
        }, 2500);
    }

    async function sendReaction(emoji) {
        spawnFloatingReaction(emoji);
        if (state.lkRoom && state.lkRoom.localParticipant) {
            try {
                await state.lkRoom.localParticipant.publishData(
                    new TextEncoder().encode(JSON.stringify({ type: 'reaction', emoji })),
                    { reliable: false }
                );
            } catch (e) {}
        }
    }

    /* ----------------------------------------------------------------------
       DIRECTORY RENDERING & HEARTBEAT
       ---------------------------------------------------------------------- */
    function renderStreamsDirectory() {
        if (!els.streamsGrid) return;
        const list = state.streams.filter((s) => {
            if (state.activeCategoryFilter === 'all') return true;
            return (s.category || '').toLowerCase() === state.activeCategoryFilter.toLowerCase();
        });

        const liveCount = state.streams.filter((s) => s.is_live).length;
        if (els.activeTotalCount) {
            els.activeTotalCount.textContent = liveCount;
        }

        if (list.length === 0) {
            els.streamsGrid.innerHTML = `
                <div style="grid-column: 1 / -1; text-align:center; padding: 28px; color: var(--ptlive-muted);">
                    No channels found in this category yet. Click <b>Go Live</b> to start the first broadcast!
                </div>
            `;
            return;
        }

        els.streamsGrid.innerHTML = list
            .map((item) => {
                const isCurrent = item.room === state.roomName;
                const badgeClass = item.is_live ? 'ptlive-card-badge' : 'ptlive-card-badge is-standby';
                const badgeLabel = item.is_live ? '🔴 LIVE' : 'STANDBY';
                return `
                    <article class="ptlive-stream-card ${isCurrent ? 'is-watching' : ''}" data-room="${escapeHtml(item.room)}">
                        <div class="ptlive-card-thumb">
                            <img src="${escapeHtml(item.thumb || item.host_avatar)}" alt="${escapeHtml(item.title)}">
                            <span class="${badgeClass}">${badgeLabel}</span>
                            <span class="ptlive-card-viewers"><i class="fa-solid fa-eye"></i> ${Number(item.viewers || 1)}</span>
                        </div>
                        <div class="ptlive-card-body">
                            <img class="ptlive-card-avatar" src="${escapeHtml(item.host_avatar)}" alt="${escapeHtml(item.host_name)}">
                            <div class="ptlive-card-info">
                                <h4 class="ptlive-card-title">${escapeHtml(item.title)}</h4>
                                <div class="ptlive-card-host">
                                    <span>${escapeHtml(item.host_name)}</span>
                                    <span class="ptlive-category-tag">${escapeHtml(item.category || 'Live')}</span>
                                </div>
                            </div>
                        </div>
                    </article>
                `;
            })
            .join('');

        els.streamsGrid.querySelectorAll('.ptlive-stream-card').forEach((card) => {
            card.addEventListener('click', () => {
                const targetRoom = card.dataset.room;
                const found = state.streams.find((s) => s.room === targetRoom);
                if (state.mode === 'host' && !state.isLive) {
                    switchMode('watch');
                }
                joinRoomAsViewer(targetRoom, found || null);
                showToast('Switched to channel: ' + ((found && found.title) || targetRoom));
            });
        });
    }

    function startHeartbeat() {
        stopHeartbeat();
        state.heartbeatTimer = setInterval(async () => {
            if (!state.isLive) return;
            try {
                const body = new URLSearchParams({
                    action: 'pt_live_heartbeat',
                    nonce: cfg.nonce || '',
                    room: state.roomName,
                    viewers: String(
                        state.lkRoom && state.lkRoom.remoteParticipants
                            ? state.lkRoom.remoteParticipants.size + 1
                            : 1
                    ),
                });
                await fetch(ajaxUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString(),
                    credentials: 'same-origin',
                });
            } catch (e) {}
        }, 15000);
    }

    function stopHeartbeat() {
        if (state.heartbeatTimer) {
            clearInterval(state.heartbeatTimer);
            state.heartbeatTimer = null;
        }
    }

    function sendStopBeacon() {
        if (!state.isLive || !state.roomName) return;
        const payload = new URLSearchParams({
            action: 'pt_live_stop_stream',
            nonce: cfg.nonce || '',
            room: state.roomName,
        });
        try {
            if (navigator.sendBeacon) {
                const blob = new Blob([payload.toString()], {
                    type: 'application/x-www-form-urlencoded; charset=UTF-8',
                });
                navigator.sendBeacon(ajaxUrl, blob);
                return;
            }
        } catch (e) {}
        try {
            fetch(ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: payload.toString(),
                credentials: 'same-origin',
                keepalive: true,
            });
        } catch (e) {}
    }

    window.addEventListener('pagehide', sendStopBeacon);
    window.addEventListener('beforeunload', function (e) {
        sendStopBeacon();
        if (state.isLive && state.mode === 'broadcast') {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    function showSaveRecordingPrompt(targetUrl) {
        let modal = document.getElementById('ptLiveSaveModal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'ptLiveSaveModal';
            modal.style.cssText =
                'position:fixed;inset:0;z-index:999999;background:rgba(15,23,42,0.65);backdrop-filter:blur(4px);display:flex;align-items:center;justify-content:center;padding:16px;';
            modal.innerHTML =
                '<div style="width:100%;max-width:420px;background:#111827;color:#f8fafc;border:1px solid #334155;border-radius:16px;padding:24px;box-shadow:0 20px 50px rgba(0,0,0,0.45);text-align:center;">' +
                  '<h3 style="margin:0 0 8px;font-size:18px;color:#fff;">Lưu video livestream?</h3>' +
                  '<p style="margin:0 0 20px;font-size:13.5px;color:#94a3b8;line-height:1.5;">Bạn đang phát trực tiếp. Bạn có muốn lưu lại video trước khi rời trang không?</p>' +
                  '<div style="display:flex;flex-wrap:wrap;gap:10px;justify-content:center;">' +
                    '<button type="button" id="ptLiveSaveYesBtn" style="padding:10px 16px;border-radius:10px;border:0;background:#2563eb;color:#fff;font-size:13px;font-weight:700;cursor:pointer;">Lưu video & Tiếp tục</button>' +
                    '<button type="button" id="ptLiveSaveNoBtn" style="padding:10px 16px;border-radius:10px;border:1px solid #475569;background:#1e293b;color:#e2e8f0;font-size:13px;font-weight:600;cursor:pointer;">Không lưu</button>' +
                    '<button type="button" id="ptLiveSaveCancelBtn" style="padding:10px 16px;border-radius:10px;border:0;background:transparent;color:#94a3b8;font-size:13px;font-weight:600;cursor:pointer;">Ở lại</button>' +
                  '</div>' +
                '</div>';
            document.body.appendChild(modal);
        }
        modal.style.display = 'flex';

        const yesBtn = document.getElementById('ptLiveSaveYesBtn');
        const noBtn = document.getElementById('ptLiveSaveNoBtn');
        const cancelBtn = document.getElementById('ptLiveSaveCancelBtn');

        cancelBtn.onclick = function () {
            modal.style.display = 'none';
        };

        noBtn.onclick = function () {
            modal.style.display = 'none';
            sendStopBeacon();
            state.isLive = false;
            window.location.assign(targetUrl);
        };

        yesBtn.onclick = async function () {
            yesBtn.disabled = true;
            yesBtn.textContent = 'Đang lưu video...';
            try {
                if (state.recordedChunks && state.recordedChunks.length > 0) {
                    const blob = new Blob(state.recordedChunks, { type: 'video/webm' });
                    const form = new FormData();
                    form.append('action', 'pt_live_save_recording');
                    form.append('video', blob, 'livestream.webm');
                    await fetch(ajaxUrl, {
                        method: 'POST',
                        body: form,
                        credentials: 'same-origin',
                    });
                }
            } catch (err) {}
            sendStopBeacon();
            state.isLive = false;
            window.location.assign(targetUrl);
        };
    }

    document.addEventListener('click', function (e) {
        if (!state.isLive || state.mode !== 'broadcast') return;
        const link = e.target.closest('a[href]');
        if (!link) return;
        const href = link.getAttribute('href') || '';
        if (!href || href.startsWith('#') || href.startsWith('javascript:') || link.target === '_blank') return;
        e.preventDefault();
        showSaveRecordingPrompt(link.href);
    }, true);

    /* ----------------------------------------------------------------------
       INITIALIZATION & EVENT LISTENERS
       ---------------------------------------------------------------------- */
    function init() {
        cacheElements();
        if (!els.videoMount) return;

        // Mode buttons
        if (els.modeWatchBtn) {
            els.modeWatchBtn.addEventListener('click', () => switchMode('watch'));
        }
        if (els.modeHostBtn) {
            els.modeHostBtn.addEventListener('click', () => switchMode('host'));
        }

        // Standby primary action button
        if (els.standbyPrimaryBtn) {
            els.standbyPrimaryBtn.addEventListener('click', () => {
                if (state.mode !== 'host') {
                    switchMode('host');
                }
                startBroadcast();
            });
        }

        // Host controls
        if (els.previewBtn) {
            els.previewBtn.addEventListener('click', async () => {
                await startLocalCameraPreview();
                showToast('Camera preview started.');
            });
        }
        if (els.startLiveBtn) {
            els.startLiveBtn.addEventListener('click', startBroadcast);
        }
        if (els.stopLiveBtn) {
            els.stopLiveBtn.addEventListener('click', stopBroadcast);
        }
        if (els.toggleCamBtn) {
            els.toggleCamBtn.addEventListener('click', toggleCamera);
        }
        if (els.toggleMicBtn) {
            els.toggleMicBtn.addEventListener('click', toggleMicrophone);
        }
        if (els.shareScreenBtn) {
            els.shareScreenBtn.addEventListener('click', toggleScreenShare);
        }

        // Device selector changes while previewing/live
        if (els.cameraSelect) {
            els.cameraSelect.addEventListener('change', () => {
                if (state.isPreviewing || state.isLive) startLocalCameraPreview();
            });
        }
        if (els.micSelect) {
            els.micSelect.addEventListener('change', () => {
                if (state.isPreviewing || state.isLive) startLocalCameraPreview();
            });
        }

        // Fullscreen & Share Link & Follow
        if (els.fullscreenBtn) {
            els.fullscreenBtn.addEventListener('click', () => {
                const box = document.querySelector('.ptlive-video-box');
                if (!box) return;
                if (!document.fullscreenElement) {
                    box.requestFullscreen && box.requestFullscreen();
                } else {
                    document.exitFullscreen && document.exitFullscreen();
                }
            });
        }

        if (els.shareLinkBtn) {
            els.shareLinkBtn.addEventListener('click', () => {
                const shareUrl = new URL(window.location.href);
                shareUrl.searchParams.set('room', state.roomName);
                navigator.clipboard
                    .writeText(shareUrl.toString())
                    .then(() => showToast('Livestream channel link copied to clipboard!'))
                    .catch(() => showToast('Channel link: ' + shareUrl.toString()));
            });
        }

        if (els.followBtn) {
            els.followBtn.addEventListener('click', () => {
                const isFollowing = els.followBtn.classList.toggle('btn-accent');
                els.followBtn.innerHTML = isFollowing
                    ? '<i class="fa-solid fa-check"></i> Following'
                    : '<i class="fa-solid fa-heart"></i> Follow';
                showToast(isFollowing ? 'Following this channel!' : 'Unfollowed channel.');
            });
        }

        // Reactions
        document.querySelectorAll('.ptlive-react-btn').forEach((btn) => {
            btn.addEventListener('click', () => {
                sendReaction(btn.dataset.emoji || '❤️');
            });
        });

        // Chat submit
        if (els.chatForm) {
            els.chatForm.addEventListener('submit', (e) => {
                e.preventDefault();
                if (!els.chatInput) return;
                const val = els.chatInput.value;
                els.chatInput.value = '';
                sendChatMessage(val);
            });
        }

        // Category filters
        document.querySelectorAll('.ptlive-filter-pill').forEach((pill) => {
            pill.addEventListener('click', () => {
                document.querySelectorAll('.ptlive-filter-pill').forEach((p) => p.classList.remove('is-active'));
                pill.classList.add('is-active');
                state.activeCategoryFilter = pill.dataset.category || 'all';
                renderStreamsDirectory();
            });
        });

        // Render initial directory & connect to initial room
        renderStreamsDirectory();
        switchMode(state.mode);
        fetchChatMessages(true);

        if (state.mode === 'watch') {
            const initialStream = state.streams.find((s) => s.room === state.roomName) || state.streams[0];
            if (initialStream) {
                joinRoomAsViewer(initialStream.room, initialStream);
            } else {
                joinRoomAsViewer(state.roomName);
            }
        }

        // Poll chat & active streams every 4s
        state.chatPollTimer = setInterval(() => {
            fetchChatMessages(false);
        }, 4000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
