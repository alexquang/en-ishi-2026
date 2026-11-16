/**
 * File: wp-content/themes/buddyx-child/assets/js/chat-widget.js
 * Handle Facebook-style Chat Popup & messaging logic
 */

document.addEventListener('DOMContentLoaded', function () {
    const sidebarToggle = document.getElementById('bx-chat-toggle-sidebar');
    const followersList = document.getElementById('bx-chat-followers-list');
    const popupWindow = document.getElementById('bx-chat-popup-window');
    const popupCloseBtn = document.getElementById('bx-popup-close-btn');
    const popupMinimizeBtn = document.getElementById('bx-popup-minimize-btn');
    const popupAvatar = document.getElementById('bx-popup-user-avatar');
    const popupName = document.getElementById('bx-popup-user-name');
    const messagesContainer = document.getElementById('bx-popup-messages-container');
    const chatForm = document.getElementById('bx-popup-input-form');
    const chatInput = document.getElementById('bx-popup-message-input');

    let currentRecipientId = null;
    let pollInterval = null;

    // Toggle hiển thị/ẩn danh sách Follower ở Sidebar
    if (sidebarToggle && followersList) {
        sidebarToggle.addEventListener('click', function (e) {
            e.stopPropagation();
            if (followersList.style.display === 'none') {
                followersList.style.display = 'block';
                sidebarToggle.textContent = '_';
            } else {
                followersList.style.display = 'none';
                sidebarToggle.textContent = '+';
            }
        });
    }

    // Cuộn xuống tin nhắn mới nhất
    function scrollToBottom() {
        if (messagesContainer) {
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }
    }

    // Thêm một tin nhắn vào khung chat
    function appendMessage(text, type = 'sent') {
        const row = document.createElement('div');
        row.className = `bx-chat-message-row ${type}`;

        const bubble = document.createElement('div');
        bubble.className = 'bx-msg-bubble';
        bubble.textContent = text;

        row.appendChild(bubble);
        messagesContainer.appendChild(row);
        scrollToBottom();
    }

    // Load lịch sử tin nhắn với user được chọn
    function loadMessages(recipientId) {
        const restUrl = window.wpApiSettings ? window.wpApiSettings.root : '/wp-json/';
        const nonce = window.wpApiSettings ? window.wpApiSettings.nonce : '';

        fetch(`${restUrl}buddyx/v1/chat/messages?recipient_id=${recipientId}`, {
            method: 'GET',
            headers: {
                'X-WP-Nonce': nonce,
                'Content-Type': 'application/json'
            }
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(messages => {
            messagesContainer.innerHTML = '';
            if (Array.isArray(messages)) {
                messages.forEach(msg => {
                    const type = (msg.sender_id == (window.currentUserId || 0)) ? 'sent' : 'received';
                    appendMessage(msg.message, type);
                });
            }
            scrollToBottom();
        })
        .catch(err => {
            console.warn('API fetch messages fallback:', err);
        });
    }

    // Mở Popup Chat khi click vào một user trong list
    document.querySelectorAll('.bx-chat-user-item').forEach(item => {
        item.addEventListener('click', function () {
            const userId = this.getAttribute('data-user-id');
            const userName = this.getAttribute('data-user-name');
            const userAvatar = this.getAttribute('data-user-avatar');

            currentRecipientId = userId;

            popupName.textContent = userName;
            popupAvatar.src = userAvatar;
            popupWindow.style.display = 'flex';
            messagesContainer.innerHTML = '';

            loadMessages(currentRecipientId);

            // Bắt đầu chu kỳ polling nhận tin nhắn mới
            if (pollInterval) {
                clearInterval(pollInterval);
            }
            pollInterval = setInterval(() => {
                if (currentRecipientId && popupWindow.style.display !== 'none') {
                    loadMessages(currentRecipientId);
                }
            }, 5000);

            chatInput.focus();
        });
    });

    // Đóng Popup Chat
    if (popupCloseBtn) {
        popupCloseBtn.addEventListener('click', function () {
            popupWindow.style.display = 'none';
            currentRecipientId = null;
            if (pollInterval) {
                clearInterval(pollInterval);
            }
        });
    }

    // Thu nhỏ / Phóng to Popup Chat
    if (popupMinimizeBtn) {
        popupMinimizeBtn.addEventListener('click', function () {
            if (messagesContainer.style.display === 'none') {
                messagesContainer.style.display = 'flex';
                chatForm.style.display = 'flex';
                popupWindow.style.height = '400px';
                popupMinimizeBtn.textContent = '-';
            } else {
                messagesContainer.style.display = 'none';
                chatForm.style.display = 'none';
                popupWindow.style.height = '44px';
                popupMinimizeBtn.textContent = '+';
            }
        });
    }

    // Gửi tin nhắn
    if (chatForm) {
        chatForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const message = chatInput.value.trim();
            if (!message || !currentRecipientId) {
                return;
            }

            appendMessage(message, 'sent');
            chatInput.value = '';

            const restUrl = window.wpApiSettings ? window.wpApiSettings.root : '/wp-json/';
            const nonce = window.wpApiSettings ? window.wpApiSettings.nonce : '';

            fetch(`${restUrl}buddyx/v1/chat/send`, {
                method: 'POST',
                headers: {
                    'X-WP-Nonce': nonce,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    recipient_id: currentRecipientId,
                    message: message
                })
            })
            .then(response => response.json())
            .then(data => {
                if (!data.success) {
                    console.warn('Gửi tin nhắn qua REST API chưa nhận phản hồi thành công:', data);
                }
            })
            .catch(error => {
                console.error('Lỗi gửi tin nhắn:', error);
            });
        });
    }
});
