<?php
/*
Template Name: Live Stream
*/

if (!is_user_logged_in()) {
    wp_redirect(wp_login_url());
    exit;
}

get_header();

$user_id = get_current_user_id();
?>

<div class="container py-4">

    <h2>🎥 PT Live</h2>

    <div id="live-status" style="margin-bottom:15px;">
        ⚫ Offline
    </div>


    <div id="viewerCount">
    👥 0 viewers
</div>

    <video id="localVideo"
        autoplay
        muted
        playsinline
        style="
                width:100%;
                max-width:900px;
                background:#000;
                border-radius:12px;
            ">
    </video>

    <div class="pt-chat">

        <div id="chatMessages"
            style="
            height:300px;
            overflow:auto;
            border:1px solid #ddd;
            padding:10px;
            margin-top:15px;
         ">
        </div>

        <div style="display:flex;gap:10px;margin-top:10px;">

            <input type="text"
                id="chatInput"
                placeholder="Nhập tin nhắn..."
                style="flex:1;">

            <button id="sendChat">
                Send
            </button>

        </div>

    </div>

    <br>


    <button id="startLive">
        🎥 Start Live
    </button>

    <button id="stopLive" style="display:none;">
        ⛔ End Live
    </button>

</div>

<script src="https://cdn.jsdelivr.net/npm/livekit-client/dist/livekit-client.umd.min.js"></script>

<script>
    const ROOM_NAME = 'live_user_<?php echo $user_id; ?>';

    let room = null;

    async function getToken() {

        const url =
            '/wp-json/enishi/v1/livekit-token?room=' +
            encodeURIComponent(ROOM_NAME);

        const response = await fetch(url, {
            credentials: 'same-origin'
        });

        if (!response.ok) {

            const text = await response.text();

            throw new Error(
                'Token API Error (' +
                response.status +
                '): ' +
                text
            );
        }

        return await response.json();
    }

    async function startLive() {

        try {

            document.getElementById('live-status')
                .innerHTML = '🟡 Connecting...';

            const data = await getToken();

            console.log('TOKEN RESPONSE', data);

            room = new LivekitClient.Room();

            await room.connect(
                data.url,
                data.token
            );



            updateViewerCount();

room.on(
    LivekitClient.RoomEvent.ParticipantConnected,
    () => {
        updateViewerCount();
    }
);

room.on(
    LivekitClient.RoomEvent.ParticipantDisconnected,
    () => {
        updateViewerCount();
    }
);


            room.on(
                LivekitClient.RoomEvent.DataReceived,
                (payload, participant) => {

                    const text =
                        new TextDecoder().decode(payload);

                    addMessage(
                        participant?.identity || 'Unknown',
                        text
                    );
                }
            );

            await room.localParticipant
                .enableCameraAndMicrophone();

            setTimeout(() => {

                room.localParticipant
                    .videoTrackPublications
                    .forEach(pub => {

                        if (!pub.track) {
                            return;
                        }

                        const track = pub.track;

                        const element = track.attach();

                        element.id = 'localVideo';

                        element.style.width = '100%';
                        element.style.maxWidth = '900px';
                        element.style.borderRadius = '12px';

                        const old =
                            document.getElementById('localVideo');

                        if (old) {
                            old.replaceWith(element);
                        }
                    });

            }, 1000);

            document.getElementById('live-status')
                .innerHTML = '🔴 LIVE';

            document.getElementById('startLive')
                .style.display = 'none';

            document.getElementById('stopLive')
                .style.display = 'inline-block';

            console.log('LIVE STARTED');

        } catch (e) {

            console.error(e);

            document.getElementById('live-status')
                .innerHTML = '❌ ' + e.message;

            alert(e.message);
        }
    }

    function stopLive() {

        if (room) {

            room.disconnect();

            room = null;
        }

        document.getElementById('live-status')
            .innerHTML = '⚫ Offline';

        document.getElementById('startLive')
            .style.display = 'inline-block';

        document.getElementById('stopLive')
            .style.display = 'none';
    }

    function addMessage(user, text) {

        const box =
            document.getElementById('chatMessages');

        box.innerHTML += `
        <div>
            <b>${user}</b>: ${text}
        </div>
    `;

        box.scrollTop = box.scrollHeight;
    }


    document
        .getElementById('sendChat')
        .addEventListener('click', async () => {

            const text =
                document.getElementById('chatInput').value;

            if (!text || !room) {
                return;
            }

            await room.localParticipant.publishData(
                new TextEncoder().encode(text), {
                    reliable: true
                }
            );

            addMessage('Me', text);

            document.getElementById('chatInput').value = '';
        });


        function updateViewerCount() {

    const total =
        room.remoteParticipants.size;

    document.getElementById('viewerCount')
        .innerHTML =
        '👥 ' + total + ' viewers';
}


    document
        .getElementById('startLive')
        .addEventListener('click', startLive);

    document
        .getElementById('stopLive')
        .addEventListener('click', stopLive);
</script>

<?php get_footer(); ?>