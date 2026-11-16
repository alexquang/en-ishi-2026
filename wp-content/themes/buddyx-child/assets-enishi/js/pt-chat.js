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

    // Lưu lại danh sách followers ban đầu để khôi phục khi xóa tìm kiếm
    const initialFollowersHtml = $('#pt-chat-user-list').html();
    let searchDebounce = null;

    // Tìm kiếm tất cả user trong hệ thống (kể cả chưa follow)
    $searchInput.on('input', function () {
        const q = $.trim($(this).val());
        clearTimeout(searchDebounce);

        if (!q) {
            $('#pt-chat-user-list').html(initialFollowersHtml);
            if (currentRecipientId > 0) {
                $(`.pt-chat-user-item[data-user-id="${currentRecipientId}"]`).addClass('is-active');
            }
            return;
        }

        // Lọc nhanh trên danh sách có sẵn
        let localFound = 0;
        $('.pt-chat-user-item').each(function () {
            const name = $(this).data('user-name').toString().toLowerCase();
            if (name.indexOf(q.toLowerCase()) !== -1) {
                $(this).show();
                localFound++;
            } else {
                $(this).hide();
            }
        });

        // Tìm kiếm toàn hệ thống qua AJAX sau 300ms
        searchDebounce = setTimeout(function () {
            $.ajax({
                url: config.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'pt_chat_search_users',
                    nonce: config.nonce,
                    term: q
                },
                success: function (res) {
                    if (res.success && res.data && res.data.users) {
                        const users = res.data.users;
                        if (!users.length && localFound === 0) {
                            $('#pt-chat-user-list').html('<div class="pt-chat-no-users">Không tìm thấy người dùng nào.</div>');
                            return;
                        }

                        let html = '';
                        users.forEach(function (u) {
                            const activeClass = (u.id === currentRecipientId) ? ' is-active' : '';
                            html += `
                                <div class="pt-chat-user-item${activeClass}" 
                                     data-user-id="${u.id}"
                                     data-user-name="${escapeHtml(u.name)}"
                                     data-user-avatar="${escapeHtml(u.avatar)}">
                                    <div class="pt-chat-avatar-wrap">
                                        <img src="${escapeHtml(u.avatar)}" alt="" class="pt-chat-avatar" />
                                        <span class="pt-chat-online-dot"></span>
                                    </div>
                                    <div class="pt-chat-user-meta">
                                        <span class="pt-chat-name">${escapeHtml(u.name)}</span>
                                        <span class="pt-chat-username">@${escapeHtml(u.username)}</span>
                                    </div>
                                </div>
                            `;
                        });
                        $('#pt-chat-user-list').html(html);
                    }
                }
            });
        }, 300);
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

    // Realtime Incoming Notification Polling
    let highestNotifId = 0;
    let $toastContainer = $('.pt-chat-toast-container');
    if (!$toastContainer.length) {
        $toastContainer = $('<div class="pt-chat-toast-container"></div>').appendTo('body');
    }

    function showToastNotification(notif) {
        const toastHtml = `
            <div class="pt-chat-toast" data-sender-id="${notif.sender_id}">
                <img src="${escapeHtml(notif.sender_avatar)}" alt="" class="pt-chat-toast-avatar" />
                <div class="pt-chat-toast-content">
                    <div class="pt-chat-toast-title">You have a new message from ${escapeHtml(notif.sender_name)}</div>
                    <div class="pt-chat-toast-text">${escapeHtml(notif.message)}</div>
                </div>
                <button type="button" class="pt-chat-toast-close">&times;</button>
            </div>
        `;
        const $toast = $(toastHtml).appendTo($toastContainer);

        $toast.on('click', function (e) {
            if ($(e.target).hasClass('pt-chat-toast-close')) {
                $toast.fadeOut(200, function () { $(this).remove(); });
                return;
            }

            // Click vào toast: Mở tab Chat nếu có và mở cuộc trò chuyện với người đó
            const $chatTabBtn = $('[data-tab="chat"]');
            if ($chatTabBtn.length) {
                $chatTabBtn.trigger('click');
            }
            openChatWithUser(notif.sender_id, notif.sender_name, notif.sender_avatar);
            $toast.fadeOut(200, function () { $(this).remove(); });
        });

        setTimeout(function () {
            $toast.fadeOut(300, function () { $(this).remove(); });
        }, 6000);
    }

    setInterval(function () {
        $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'pt_chat_poll_incoming',
                nonce: config.nonce,
                last_id: highestNotifId
            },
            success: function (res) {
                if (res.success && res.data && res.data.notifications && res.data.notifications.length) {
                    res.data.notifications.forEach(function (n) {
                        if (n.id > highestNotifId) {
                            highestNotifId = n.id;
                        }
                        // Nếu đang chat trực tiếp với người đó trong tab Chat thì không cần hiện toast
                        if (currentRecipientId === n.sender_id && $('[data-tab-panel="chat"]').is(':visible')) {
                            return;
                        }
                        showToastNotification(n);
                    });
                }
            }
        });
    }, 4000);

})(jQuery);
