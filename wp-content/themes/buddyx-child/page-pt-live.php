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
<div class="twitch-layout">

    <main class="stream-area">

        <div class="video-container">

            <video
                id="localVideo"
                autoplay
                muted
                playsinline>
            </video>

            <div class="live-badge" id="live-status">
                ⚫ OFFLINE
            </div>

        </div>

        <div class="stream-info">

            <h2 class="stream-title">
                🎥 PT Live Stream
            </h2>

            <div class="stream-meta">

                <div class="streamer">

                    <?php echo get_avatar($user_id, 48); ?>

                    <div>
                        <strong>
                            <?php echo wp_get_current_user()->display_name; ?>
                        </strong>
                    </div>

                </div>

                <div id="viewerCount" class="viewer-count">
                    👥 0 viewers
                </div>

            </div>

            <div class="stream-actions">

                <button id="startLive" class="btn-live">
                    Start Live
                </button>

                <button
                    id="stopLive"
                    class="btn-stop"
                    style="display:none;">
                    End Live
                </button>

                <button class="btn-follow">
                    Follow
                </button>

                <button class="btn-donate">
                    Donate
                </button>

            </div>

        </div>

    </main>

    <aside class="chat-sidebar">

        <div class="chat-header">
            💬 Stream Chat
        </div>

        <div id="chatMessages"></div>

        <div class="chat-input">

            <input
                type="text"
                id="chatInput"
                placeholder="Send a message">

            <button id="sendChat">
                Send
            </button>

        </div>

    </aside>

</div>

<style>

.twitch-layout{
    display:flex;
    gap:0;
    min-height:calc(100vh - 100px);
    background:#0e0e10;
}

.stream-area{
    flex:1;
    background:#18181b;
}

.video-container{
    position:relative;
    background:#000;
    aspect-ratio:16/9;
}

#localVideo{
    width:100%;
    height:100%;
    object-fit:cover;
}

.live-badge{
    position:absolute;
    top:15px;
    left:15px;
    background:#e91916;
    color:#fff;
    padding:6px 12px;
    border-radius:6px;
    font-weight:700;
}

.stream-info{
    background:#1f1f23;
    padding:20px;
    color:#fff;
}

.stream-title{
    color:#fff;
    margin-bottom:15px;
}

.stream-meta{
    display:flex;
    justify-content:space-between;
    align-items:center;
}

.streamer{
    display:flex;
    gap:12px;
    align-items:center;
}

.streamer img{
    border-radius:50%;
}

.viewer-count{
    font-weight:600;
}

.stream-actions{
    margin-top:20px;
    display:flex;
    gap:10px;
}

.stream-actions button{
    border:0;
    border-radius:8px;
    padding:10px 18px;
    cursor:pointer;
}

.btn-live{
    background:#9147ff;
    color:#fff;
}

.btn-stop{
    background:#ef4444;
    color:#fff;
}

.btn-follow{
    background:#2d2d33;
    color:#fff;
}

.btn-donate{
    background:#f59e0b;
    color:#fff;
}

.chat-sidebar{
    width:360px;
    background:#1f1f23;
    display:flex;
    flex-direction:column;
    border-left:1px solid #2f2f35;
}

.chat-header{
    padding:16px;
    color:#fff;
    font-weight:700;
    border-bottom:1px solid #2f2f35;
}

#chatMessages{
    flex:1;
    overflow-y:auto;
    padding:15px;
    color:#fff;
}

.chat-message{
    margin-bottom:12px;
}

.chat-user{
    color:#bf94ff;
    font-weight:600;
}

.chat-input{
    display:flex;
    gap:8px;
    padding:15px;
    border-top:1px solid #2f2f35;
}

.chat-input input{
    flex:1;
    background:#18181b;
    border:1px solid #333;
    color:#fff;
    border-radius:8px;
    padding:10px;
}

.chat-input button{
    background:#9147ff;
    color:#fff;
    border:0;
    border-radius:8px;
    padding:0 16px;
}

@media(max-width:991px){

    .twitch-layout{
        flex-direction:column;
    }

    .chat-sidebar{
        width:100%;
        height:400px;
    }

}

</style>

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