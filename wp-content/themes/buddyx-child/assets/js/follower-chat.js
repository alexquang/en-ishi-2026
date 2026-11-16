/**
 * File: wp-content/themes/buddyx-child/assets/js/follower-chat.js
 * JavaScript logic for Follower Chat Popup & Realtime AJAX Sync
 */

(function ($) {
    'use strict';

    if (typeof window.bxChatConfig === 'undefined') {
        return;
    }

    const config = window.bxChatConfig;
    const $popup = $('#bx-messenger-popup');
    const $avatar = $('#bx-messenger-avatar');
    const $name = $('#bx-messenger-name');
    const $body = $('#bx-messenger-body');
    const $form = $('#bx-messenger-form');
    const $input = $('#bx-messenger-input');
    const $minBtn = $('#bx-messenger-min-btn');
    const $closeBtn = $('#bx-messenger-close-btn');

    let currentRecipientId = 0;
    let lastMessageId = 0;
    let pollTimer = null;
    let isSending = false;

    // Cuộn xuống cuối tin nhắn
    function scrollToBottom() {
        if ($body.length) {
            $body.scrollTop($body[0].scrollHeight);
        }
    }

    // Escape chuỗi HTML an toàn
    function escapeHtml(str) {
        return $('<div>').text(str || '').html();
    }

    // Render danh sách tin nhắn vào popup
    function appendMessages(messages, replaceAll) {
        if (replaceAll) {
            $body.empty();
        }

        if (replaceAll && (!messages || messages.length === 0)) {
            $body.html('<div class="bx-messenger-empty">Chưa có tin nhắn nào. Hãy gửi lời chào đầu tiên!</div>');
            return;
        }

        $body.find('.bx-messenger-loading, .bx-messenger-empty').remove();

        messages.forEach(function (msg) {
            if (msg.id > lastMessageId) {
                lastMessageId = msg.id;
            }

            if ($body.find('[data-msg-id="' + msg.id + '"]').length > 0) {
                return;
            }

            const rowClass = msg.is_mine ? 'sent' : 'received';
            const rowHtml = `
                <div class="bx-msg-row ${rowClass}" data-msg-id="${msg.id}">
                    <div class="bx-msg-bubble">${escapeHtml(msg.message)}</div>
                    <div class="bx-msg-time">${escapeHtml(msg.time)}</div>
                </div>
            `;
            $body.append(rowHtml);
        });

        scrollToBottom();
    }

    // Tải tin nhắn qua AJAX
    function fetchMessages(recipientId, lastId, isInitial) {
        $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'bx_get_chat_messages',
                nonce: config.nonce,
                recipient_id: recipientId,
                last_id: lastId
            },
            success: function (response) {
                if (response.success && response.data && response.data.messages) {
                    appendMessages(response.data.messages, isInitial);
                }
            }
        });
    }

    // Mở popup chat với user cụ thể
    function openChatWithUser(userId, userName, userAvatar) {
        currentRecipientId = parseInt(userId, 10);
        lastMessageId = 0;

        $name.text(userName);
        $avatar.attr('src', userAvatar);
        $popup.removeClass('minimized').show();
        $body.html('<div class="bx-messenger-loading">Đang tải tin nhắn...</div>');

        fetchMessages(currentRecipientId, 0, true);

        if (pollTimer) {
            clearInterval(pollTimer);
        }

        // Tự động kiểm tra tin nhắn mới mỗi 3.5 giây
        pollTimer = setInterval(function () {
            if (currentRecipientId > 0 && $popup.is(':visible') && !$popup.hasClass('minimized')) {
                fetchMessages(currentRecipientId, lastMessageId, false);
            }
        }, 3500);

        setTimeout(function () {
            $input.focus();
        }, 100);
    }

    // Bắt sự kiện click nút "Nhắn tin" trong danh sách Followers
    $(document).on('click', '.bx-open-chat-btn', function (e) {
        e.preventDefault();
        const userId = $(this).data('user-id');
        const userName = $(this).data('user-name');
        const userAvatar = $(this).data('user-avatar');

        if (userId) {
            openChatWithUser(userId, userName, userAvatar);
        }
    });

    // Thu nhỏ / Mở rộng popup chat
    $minBtn.on('click', function (e) {
        e.preventDefault();
        $popup.toggleClass('minimized');
    });

    // Đóng popup chat
    $closeBtn.on('click', function (e) {
        e.preventDefault();
        $popup.hide();
        currentRecipientId = 0;
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    });

    // Gửi tin nhắn qua AJAX
    $form.on('submit', function (e) {
        e.preventDefault();
        if (isSending || currentRecipientId <= 0) {
            return;
        }

        const messageText = $.trim($input.val());
        if (!messageText) {
            return;
        }

        isSending = true;
        const $sendBtn = $('#bx-messenger-send-btn');
        $sendBtn.prop('disabled', true);

        $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'bx_send_chat_message',
                nonce: config.nonce,
                recipient_id: currentRecipientId,
                message: messageText
            },
            success: function (response) {
                if (response.success && response.data && response.data.message) {
                    $input.val('');
                    appendMessages([response.data.message], false);
                } else {
                    alert((response.data && response.data.message) || 'Không thể gửi tin nhắn.');
                }
            },
            error: function () {
                alert('Lỗi kết nối máy chủ.');
            },
            complete: function () {
                isSending = false;
                $sendBtn.prop('disabled', false);
                $input.focus();
            }
        });
    });

})(jQuery);
