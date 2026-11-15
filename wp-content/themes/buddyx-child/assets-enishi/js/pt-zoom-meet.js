/**
 * Zoom-like Group Meeting Room powered by PeerJS (WebRTC) + WordPress AJAX room sync.
 */
(function () {
  "use strict";

  function createSilentAudioStream() {
    try {
      var AudioCtx = window.AudioContext || window.webkitAudioContext;
      if (!AudioCtx) return new MediaStream();
      var ctx = new AudioCtx();
      var osc = ctx.createOscillator();
      var dst = ctx.createMediaStreamDestination();
      var gain = ctx.createGain();
      gain.gain.value = 0;
      osc.connect(gain);
      gain.connect(dst);
      osc.start();
      return dst.stream;
    } catch (e) {
      return new MediaStream();
    }
  }

  function escapeHtml(str) {
    return String(str || "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  window.ptInitZoomMeet = function (cfg) {
    var root = document.querySelector(cfg.parentNode || "#meet");
    if (!root) return;

    var ajaxUrl = cfg.ajaxUrl || "/wp-admin/admin-ajax.php";
    var roomName = cfg.room || "enishi-room";
    var subject = cfg.subject || "Group Meeting";
    var userName = cfg.userName || "Guest";
    var userAvatar = cfg.userAvatar || "";

    var localStream = null;
    var screenStream = null;
    var peer = null;
    var myPeerId = "";
    var syncTimer = null;
    var isJoined = false;
    var isMicOn = true;
    var isCamOn = true;
    var isScreenSharing = false;
    var activeTab = "chat";
    var knownChatCount = 0;

    // peerId -> { call, stream, info }
    var remotePeers = {};

    root.innerHTML =
      '<div class="pt-zmeet-root">' +
        '<div class="pt-zmeet-topbar">' +
          '<div class="pt-zmeet-topbar-left">' +
            '<span class="pt-zmeet-status-dot"></span>' +
            '<span class="pt-zmeet-room-title">' + escapeHtml(subject) + "</span>" +
          "</div>" +
          '<div class="pt-zmeet-topbar-right">' +
            '<span class="pt-zmeet-pill" id="ptZmeetStatusText">Connecting...</span>' +
            '<span class="pt-zmeet-pill" id="ptZmeetCountPill"><i class="fa-solid fa-users"></i> 1</span>' +
          "</div>" +
        "</div>" +
        '<div class="pt-zmeet-main">' +
          '<div class="pt-zmeet-stage">' +
            '<div class="pt-zmeet-grid count-1" id="ptZmeetGrid"></div>' +
          "</div>" +
          '<aside class="pt-zmeet-sidebar" id="ptZmeetSidebar">' +
            '<div class="pt-zmeet-sidebar-tabs">' +
              '<button type="button" class="pt-zmeet-sidebar-tab is-active" data-ztab="chat">Chat</button>' +
              '<button type="button" class="pt-zmeet-sidebar-tab" data-ztab="participants">Participants</button>' +
            "</div>" +
            '<div class="pt-zmeet-chat-list" id="ptZmeetChatList"></div>' +
            '<div class="pt-zmeet-participants-list" id="ptZmeetParticipantsList" style="display:none;"></div>' +
            '<form class="pt-zmeet-chat-form" id="ptZmeetChatForm">' +
              '<input type="text" id="ptZmeetChatInput" placeholder="Send a message..." autocomplete="off" />' +
              '<button type="submit">Send</button>' +
            "</form>" +
          "</aside>" +
        "</div>" +
        '<div class="pt-zmeet-controls">' +
          '<button type="button" class="pt-zmeet-ctrl-btn" id="ptZmeetMicBtn"><i class="fa-solid fa-microphone"></i> <span>Mute</span></button>' +
          '<button type="button" class="pt-zmeet-ctrl-btn" id="ptZmeetCamBtn"><i class="fa-solid fa-video"></i> <span>Stop Video</span></button>' +
          '<button type="button" class="pt-zmeet-ctrl-btn" id="ptZmeetScreenBtn"><i class="fa-solid fa-display"></i> <span>Share Screen</span></button>' +
          '<button type="button" class="pt-zmeet-ctrl-btn" id="ptZmeetSidebarBtn"><i class="fa-solid fa-comments"></i> <span>Chat</span></button>' +
          '<button type="button" class="pt-zmeet-ctrl-btn is-leave" id="ptZmeetLeaveBtn"><i class="fa-solid fa-phone-slash"></i> <span>Leave</span></button>' +
        "</div>" +
      "</div>";

    var gridEl = root.querySelector("#ptZmeetGrid");
    var statusTextEl = root.querySelector("#ptZmeetStatusText");
    var countPillEl = root.querySelector("#ptZmeetCountPill");
    var sidebarEl = root.querySelector("#ptZmeetSidebar");
    var chatListEl = root.querySelector("#ptZmeetChatList");
    var participantsListEl = root.querySelector("#ptZmeetParticipantsList");
    var chatFormEl = root.querySelector("#ptZmeetChatForm");
    var chatInputEl = root.querySelector("#ptZmeetChatInput");
    var micBtn = root.querySelector("#ptZmeetMicBtn");
    var camBtn = root.querySelector("#ptZmeetCamBtn");
    var screenBtn = root.querySelector("#ptZmeetScreenBtn");
    var sidebarBtn = root.querySelector("#ptZmeetSidebarBtn");
    var leaveBtn = root.querySelector("#ptZmeetLeaveBtn");

    function updateGridClass() {
      var total = 1 + Object.keys(remotePeers).length;
      gridEl.className = "pt-zmeet-grid";
      if (total <= 4) {
        gridEl.classList.add("count-" + total);
      }
      countPillEl.innerHTML = '<i class="fa-solid fa-users"></i> ' + total;
    }

    function createOrUpdateTile(id, label, avatar, stream, isLocal, micEnabled, camEnabled) {
      var tileId = "pt-zmeet-tile-" + id;
      var tile = document.getElementById(tileId);
      if (!tile) {
        tile = document.createElement("div");
        tile.id = tileId;
        tile.className = "pt-zmeet-tile" + (isLocal ? " is-local" : "");
        tile.innerHTML =
          '<video autoplay playsinline ' + (isLocal ? "muted" : "") + "></video>" +
          '<div class="pt-zmeet-avatar-fallback">' +
            (avatar
              ? '<img src="' + escapeHtml(avatar) + '" alt="" />'
              : '<div class="pt-zmeet-avatar-initial">' + escapeHtml((label || "G").charAt(0).toUpperCase()) + "</div>") +
          "</div>" +
          '<div class="pt-zmeet-tile-label">' +
            '<i class="fa-solid fa-microphone-slash pt-zmeet-mic-off" style="display:none;"></i>' +
            "<span>" + escapeHtml(label) + (isLocal ? " (You)" : "") + "</span>" +
          "</div>";
        gridEl.appendChild(tile);
      }

      var videoEl = tile.querySelector("video");
      var fallbackEl = tile.querySelector(".pt-zmeet-avatar-fallback");
      var micSlashEl = tile.querySelector(".pt-zmeet-mic-off");

      if (stream && videoEl.srcObject !== stream) {
        videoEl.srcObject = stream;
        videoEl.play().catch(function () {});
      }

      var hasLiveVideo =
        camEnabled !== false &&
        stream &&
        stream.getVideoTracks().some(function (t) {
          return t.readyState === "live" && t.enabled;
        });

      fallbackEl.style.display = hasLiveVideo ? "none" : "flex";
      videoEl.style.display = hasLiveVideo ? "block" : "none";
      if (micSlashEl) {
        micSlashEl.style.display = micEnabled === false ? "inline-block" : "none";
      }
      if (isLocal) {
        tile.classList.toggle("is-screen", isScreenSharing);
      }
      updateGridClass();
    }

    function removeRemoteTile(peerId) {
      var tile = document.getElementById("pt-zmeet-tile-" + peerId);
      if (tile && tile.parentNode) {
        tile.parentNode.removeChild(tile);
      }
      updateGridClass();
    }

    function renderParticipantsList() {
      var html =
        '<div class="pt-zmeet-participant-row">' +
          "<span><strong>" + escapeHtml(userName) + "</strong> (You)</span>" +
          '<span>' + (isMicOn ? '<i class="fa-solid fa-microphone" style="color:#10b981;"></i>' : '<i class="fa-solid fa-microphone-slash" style="color:#ef4444;"></i>') + "</span>" +
        "</div>";

      Object.keys(remotePeers).forEach(function (pid) {
        var info = remotePeers[pid].info || {};
        html +=
          '<div class="pt-zmeet-participant-row">' +
            "<span>" + escapeHtml(info.user_name || "Member") + "</span>" +
            '<span>' + (info.mic_on !== false ? '<i class="fa-solid fa-microphone" style="color:#10b981;"></i>' : '<i class="fa-solid fa-microphone-slash" style="color:#ef4444;"></i>') + "</span>" +
          "</div>";
      });
      participantsListEl.innerHTML = html;
    }

    function renderChatMessages(messages) {
      if (!Array.isArray(messages)) return;
      if (messages.length === knownChatCount) return;
      knownChatCount = messages.length;

      chatListEl.innerHTML = messages
        .map(function (m) {
          return (
            '<div class="pt-zmeet-chat-msg">' +
              '<div class="pt-zmeet-chat-msg-head">' +
                "<strong>" + escapeHtml(m.user_name || "Member") + "</strong>" +
                "<span>" + escapeHtml(m.time || "") + "</span>" +
              "</div>" +
              "<div>" + escapeHtml(m.text || "") + "</div>" +
            "</div>"
          );
        })
        .join("");
      chatListEl.scrollTop = chatListEl.scrollHeight;
    }

    function bindCallEvents(call, remotePeerId) {
      if (!remotePeers[remotePeerId]) {
        remotePeers[remotePeerId] = { call: call, stream: null, info: {} };
      } else {
        remotePeers[remotePeerId].call = call;
      }

      call.on("stream", function (remoteStream) {
        if (!remotePeers[remotePeerId]) return;
        remotePeers[remotePeerId].stream = remoteStream;
        var info = remotePeers[remotePeerId].info || {};
        createOrUpdateTile(
          remotePeerId,
          info.user_name || "Member",
          info.avatar || "",
          remoteStream,
          false,
          info.mic_on !== false,
          info.cam_on !== false
        );
      });

      call.on("close", function () {
        delete remotePeers[remotePeerId];
        removeRemoteTile(remotePeerId);
        renderParticipantsList();
      });

      call.on("error", function () {
        delete remotePeers[remotePeerId];
        removeRemoteTile(remotePeerId);
        renderParticipantsList();
      });
    }

    function replaceOutgoingVideoTrack(newTrack) {
      Object.keys(remotePeers).forEach(function (pid) {
        var entry = remotePeers[pid];
        if (!entry || !entry.call || !entry.call.peerConnection) return;
        var senders = entry.call.peerConnection.getSenders();
        senders.forEach(function (sender) {
          if (sender.track && sender.track.kind === "video") {
            sender.replaceTrack(newTrack).catch(function () {});
          }
        });
      });
    }

    function syncRoomWithServer() {
      if (!isJoined || !myPeerId) return;
      var body = new URLSearchParams({
        action: "pt_meet_sync",
        room: roomName,
        peer_id: myPeerId,
        user_name: userName,
        avatar: userAvatar,
        mic_on: isMicOn ? "1" : "0",
        cam_on: isCamOn ? "1" : "0",
      });

      fetch(ajaxUrl, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: body.toString(),
        credentials: "same-origin",
      })
        .then(function (r) {
          return r.json();
        })
        .then(function (res) {
          if (!res || !res.success || !res.data) return;
          var peers = Array.isArray(res.data.peers) ? res.data.peers : [];
          var activeIds = {};

          peers.forEach(function (p) {
            if (!p || !p.peer_id || p.peer_id === myPeerId) return;
            activeIds[p.peer_id] = true;

            if (!remotePeers[p.peer_id]) {
              remotePeers[p.peer_id] = { call: null, stream: null, info: p };
              if (peer && localStream && myPeerId < p.peer_id) {
                var call = peer.call(p.peer_id, localStream);
                if (call) {
                  bindCallEvents(call, p.peer_id);
                }
              }
            } else {
              remotePeers[p.peer_id].info = p;
              if (remotePeers[p.peer_id].stream) {
                createOrUpdateTile(
                  p.peer_id,
                  p.user_name || "Member",
                  p.avatar || "",
                  remotePeers[p.peer_id].stream,
                  false,
                  p.mic_on !== false,
                  p.cam_on !== false
                );
              }
            }
          });

          Object.keys(remotePeers).forEach(function (pid) {
            if (!activeIds[pid]) {
              if (remotePeers[pid].call) {
                try {
                  remotePeers[pid].call.close();
                } catch (e) {}
              }
              delete remotePeers[pid];
              removeRemoteTile(pid);
            }
          });

          renderParticipantsList();
          renderChatMessages(res.data.messages || []);
        })
        .catch(function () {});
    }

    function leaveMeeting(showEndedOverlay) {
      isJoined = false;
      if (syncTimer) {
        clearInterval(syncTimer);
        syncTimer = null;
      }

      if (myPeerId) {
        var body = new URLSearchParams({
          action: "pt_meet_leave",
          room: roomName,
          peer_id: myPeerId,
        });
        if (navigator.sendBeacon) {
          navigator.sendBeacon(ajaxUrl, body);
        } else {
          fetch(ajaxUrl, {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: body.toString(),
            keepalive: true,
          }).catch(function () {});
        }
      }

      Object.keys(remotePeers).forEach(function (pid) {
        if (remotePeers[pid] && remotePeers[pid].call) {
          try {
            remotePeers[pid].call.close();
          } catch (e) {}
        }
      });
      remotePeers = {};

      if (peer) {
        try {
          peer.destroy();
        } catch (e) {}
        peer = null;
      }

      if (localStream) {
        localStream.getTracks().forEach(function (t) {
          t.stop();
        });
        localStream = null;
      }
      if (screenStream) {
        screenStream.getTracks().forEach(function (t) {
          t.stop();
        });
        screenStream = null;
      }

      if (showEndedOverlay) {
        var overlay = document.createElement("div");
        overlay.className = "pt-zmeet-overlay";
        overlay.innerHTML =
          "<h3>You left the meeting</h3>" +
          "<p>Click below to rejoin the group meeting room.</p>" +
          '<button type="button" class="pt-zmeet-primary-btn" id="ptZmeetRejoinBtn"><i class="fa-solid fa-rotate-right"></i> Rejoin Meeting</button>';
        root.querySelector(".pt-zmeet-root").appendChild(overlay);
        overlay.querySelector("#ptZmeetRejoinBtn").addEventListener("click", function () {
          window.ptInitZoomMeet(cfg);
        });
      }
    }

    async function startMeeting() {
      if (typeof window.Peer === "undefined") {
        statusTextEl.textContent = "PeerJS not loaded";
        return;
      }

      try {
        localStream = await navigator.mediaDevices.getUserMedia({ video: true, audio: true });
        isMicOn = true;
        isCamOn = true;
      } catch (e1) {
        try {
          localStream = await navigator.mediaDevices.getUserMedia({ audio: true });
          isMicOn = true;
          isCamOn = false;
        } catch (e2) {
          localStream = createSilentAudioStream();
          isMicOn = false;
          isCamOn = false;
        }
      }

      micBtn.classList.toggle("is-off", !isMicOn);
      micBtn.querySelector("span").textContent = isMicOn ? "Mute" : "Unmute";
      camBtn.classList.toggle("is-off", !isCamOn);
      camBtn.querySelector("span").textContent = isCamOn ? "Stop Video" : "Start Video";

      createOrUpdateTile("local", userName, userAvatar, localStream, true, isMicOn, isCamOn);
      renderParticipantsList();

      peer = new window.Peer(undefined, {
        debug: 1,
        config: {
          iceServers: [
            { urls: "stun:stun.l.google.com:19302" },
            { urls: "stun:stun1.l.google.com:19302" },
          ],
        },
      });

      peer.on("open", function (id) {
        myPeerId = id;
        isJoined = true;
        statusTextEl.textContent = "Connected";
        syncRoomWithServer();
        syncTimer = setInterval(syncRoomWithServer, 4000);
      });

      peer.on("call", function (call) {
        call.answer(localStream || createSilentAudioStream());
        bindCallEvents(call, call.peer);
      });

      peer.on("error", function () {
        statusTextEl.textContent = "Reconnecting...";
      });
    }

    // Controls
    micBtn.addEventListener("click", function () {
      if (!localStream) return;
      var audioTracks = localStream.getAudioTracks();
      if (!audioTracks.length) return;
      isMicOn = !isMicOn;
      audioTracks.forEach(function (t) {
        t.enabled = isMicOn;
      });
      micBtn.classList.toggle("is-off", !isMicOn);
      micBtn.querySelector("span").textContent = isMicOn ? "Mute" : "Unmute";
      createOrUpdateTile("local", userName, userAvatar, localStream, true, isMicOn, isCamOn);
      syncRoomWithServer();
    });

    camBtn.addEventListener("click", function () {
      if (!localStream) return;
      var videoTracks = localStream.getVideoTracks();
      if (!videoTracks.length) return;
      isCamOn = !isCamOn;
      videoTracks.forEach(function (t) {
        t.enabled = isCamOn;
      });
      camBtn.classList.toggle("is-off", !isCamOn);
      camBtn.querySelector("span").textContent = isCamOn ? "Stop Video" : "Start Video";
      createOrUpdateTile("local", userName, userAvatar, localStream, true, isMicOn, isCamOn);
      syncRoomWithServer();
    });

    screenBtn.addEventListener("click", async function () {
      if (!navigator.mediaDevices || !navigator.mediaDevices.getDisplayMedia) return;
      if (isScreenSharing) {
        if (screenStream) {
          screenStream.getTracks().forEach(function (t) {
            t.stop();
          });
          screenStream = null;
        }
        isScreenSharing = false;
        screenBtn.classList.remove("is-active");
        var camTrack = localStream ? localStream.getVideoTracks()[0] : null;
        if (camTrack) {
          replaceOutgoingVideoTrack(camTrack);
          createOrUpdateTile("local", userName, userAvatar, localStream, true, isMicOn, isCamOn);
        }
        return;
      }

      try {
        screenStream = await navigator.mediaDevices.getDisplayMedia({ video: true });
        var screenTrack = screenStream.getVideoTracks()[0];
        if (!screenTrack) return;
        isScreenSharing = true;
        screenBtn.classList.add("is-active");
        replaceOutgoingVideoTrack(screenTrack);
        createOrUpdateTile("local", userName, userAvatar, screenStream, true, isMicOn, true);

        screenTrack.onended = function () {
          if (isScreenSharing) {
            screenBtn.click();
          }
        };
      } catch (e) {}
    });

    sidebarBtn.addEventListener("click", function () {
      sidebarEl.classList.toggle("is-open");
      sidebarBtn.classList.toggle("is-active", sidebarEl.classList.contains("is-open"));
    });

    root.querySelectorAll(".pt-zmeet-sidebar-tab").forEach(function (tabBtn) {
      tabBtn.addEventListener("click", function () {
        activeTab = tabBtn.dataset.ztab || "chat";
        root.querySelectorAll(".pt-zmeet-sidebar-tab").forEach(function (b) {
          b.classList.toggle("is-active", b === tabBtn);
        });
        chatListEl.style.display = activeTab === "chat" ? "flex" : "none";
        chatFormEl.style.display = activeTab === "chat" ? "flex" : "none";
        participantsListEl.style.display = activeTab === "participants" ? "flex" : "none";
      });
    });

    chatFormEl.addEventListener("submit", function (e) {
      e.preventDefault();
      var text = chatInputEl.value.trim();
      if (!text) return;
      chatInputEl.value = "";
      var body = new URLSearchParams({
        action: "pt_meet_chat",
        room: roomName,
        user_name: userName,
        text: text,
      });
      fetch(ajaxUrl, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: body.toString(),
        credentials: "same-origin",
      })
        .then(function (r) {
          return r.json();
        })
        .then(function (res) {
          if (res && res.success && res.data && res.data.messages) {
            renderChatMessages(res.data.messages);
          }
        })
        .catch(function () {});
    });

    leaveBtn.addEventListener("click", function () {
      leaveMeeting(true);
    });

    window.addEventListener("pagehide", function () {
      leaveMeeting(false);
    });

    startMeeting();
  };
})();
