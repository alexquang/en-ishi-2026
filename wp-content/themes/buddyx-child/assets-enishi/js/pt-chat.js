/**
 * JavaScript logic for Profile Chat Tab
 * File: wp-content/themes/buddyx-child/assets-enishi/js/pt-chat.js
 */

(function ($) {
    'use strict';

    if (typeof window.ptChatConfig === 'undefined') {
        return;
    }

    const config = window.ptChatConfig;
    const $chatWrapper = $('.pt-chat-wrapper');
    if (!$chatWrapper.length) {
        return;
    }

    const $header = $('#pt-chat-header');
    const $headerAvatar = $('#pt-chat-header-avatar');
    const $headerName = $('#pt-chat-header-name');
    const $messages = $('#pt-chat-messages');
    const $composer = $('#pt-chat-composer');
    const $input = $('#pt-chat-input');
    const $emojiPicker = $('#pt-chat-emoji-picker');
    const $emojiToggle = $('#pt-chat-emoji-toggle');
    const $searchInput = $('#pt-chat-search-input');

    let currentRecipientId = 0;
    let lastMessageId = 0;
    let pollTimer = null;
    let isSending = false;

    function scrollToBottom() {
        if ($messages.length) {
            $messages.scrollTop($messages[0].scrollHeight);
        }
    }

    function escapeHtml(str) {
        return $('<div>').text(str || '').html();
    }

    function appendMessages(messages, replaceAll) {
        if (replaceAll) {
            $messages.empty();
        }

        if (replaceAll && (!messages || messages.length === 0)) {
            $messages.html('<div class="pt-chat-placeholder"><p>Chưa có tin nhắn nào. Hãy gửi lời chào đầu tiên!</p></div>');
            return;
        }

        $messages.find('.pt-chat-placeholder').remove();

        messages.forEach(function (msg) {
            if (msg.id > lastMessageId) {
                lastMessageId = msg.id;
            }

            if ($messages.find('[data-msg-id="' + msg.id + '"]').length > 0) {
                return;
            }

            const rowClass = msg.is_mine ? 'sent' : 'received';
            const rowHtml = `
                <div class="pt-chat-msg-row ${rowClass}" data-msg-id="${msg.id}">
                    <div class="pt-chat-msg-bubble">${escapeHtml(msg.message)}</div>
                    <div class="pt-chat-msg-time">${escapeHtml(msg.time)}</div>
                </div>
            `;
            $messages.append(rowHtml);
        });

        scrollToBottom();
    }

    function fetchMessages(recipientId, lastId, isInitial) {
        $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'pt_chat_get_messages',
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

    function openChatWithUser(userId, userName, userAvatar) {
        currentRecipientId = parseInt(userId, 10);
        lastMessageId = 0;

        $('.pt-chat-user-item').removeClass('is-active');
        $(`.pt-chat-user-item[data-user-id="${userId}"]`).addClass('is-active');

        $headerName.text(userName);
        $headerAvatar.attr('src', userAvatar);
        $header.show();
        $composer.show();
        $emojiPicker.hide();
        $messages.html('<div class="pt-chat-placeholder"><p>Đang tải tin nhắn...</p></div>');

        fetchMessages(currentRecipientId, 0, true);

        if (pollTimer) {
            clearInterval(pollTimer);
        }

        pollTimer = setInterval(function () {
            const $panel = $('[data-tab-panel="chat"]');
            if (currentRecipientId > 0 && $panel.length && !$panel.is(':hidden')) {
                fetchMessages(currentRecipientId, lastMessageId, false);
            }
        }, 3500);

        setTimeout(function () {
            $input.focus();
        }, 100);
    }

    // Click vào user trong list
    $(document).on('click', '.pt-chat-user-item', function () {
        const userId = $(this).data('user-id');
        const userName = $(this).data('user-name');
        const userAvatar = $(this).data('user-avatar');
        if (userId) {
            openChatWithUser(userId, userName, userAvatar);
        }
    });

    // Toggle emoji picker
    $emojiToggle.on('click', function () {
        $emojiPicker.toggle();
    });

    // Chèn emoji vào textarea
    $(document).on('click', '.pt-emoji-btn', function () {
        const emoji = $(this).data('emoji');
        const inputDom = $input[0];
        const start = inputDom.selectionStart || inputDom.value.length;
        const end = inputDom.selectionEnd || inputDom.value.length;
        inputDom.value = inputDom.value.substring(0, start) + emoji + inputDom.value.substring(end);
        inputDom.focus();
        inputDom.selectionStart = inputDom.selectionEnd = start + emoji.length;
    });

    // Tìm kiếm trong list follower
    $searchInput.on('input', function () {
        const q = $.trim($(this).val()).toLowerCase();
        $('.pt-chat-user-item').each(function () {
            const name = $(this).data('user-name').toString().toLowerCase();
            if (!q || name.indexOf(q) !== -1) {
                $(this).show();
            } else {
                $(this).hide();
            }
        });
    });

    // Enter để gửi tin nhắn
    $input.on('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            $composer.trigger('submit');
        }
    });

    // Gửi tin nhắn qua AJAX
    $composer.on('submit', function (e) {
        e.preventDefault();
        if (isSending || currentRecipientId <= 0) {
            return;
        }

        const text = $.trim($input.val());
        if (!text) {
            return;
        }

        isSending = true;
        const $sendBtn = $('#pt-chat-send-btn');
        $sendBtn.prop('disabled', true);

        $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'pt_chat_send_message',
                nonce: config.nonce,
                recipient_id: currentRecipientId,
                message: text
            },
            success: function (response) {
                if (response.success && response.data && response.data.message) {
                    $input.val('');
                    $emojiPicker.hide();
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
