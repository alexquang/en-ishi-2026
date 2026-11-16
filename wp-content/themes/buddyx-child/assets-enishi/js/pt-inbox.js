(function () {
    'use strict';

    if (typeof window.ptInboxConfig === 'undefined') {
        return;
    }

    var config = window.ptInboxConfig;
    var app = document.getElementById('pt-inbox-app');
    if (!app) {
        return;
    }

    var convContainer = document.getElementById('pt-inbox-conversations');
    var searchInput = document.getElementById('pt-inbox-search');
    var chatHeader = document.getElementById('pt-inbox-chat-header');
    var partnerAvatar = document.getElementById('pt-inbox-partner-avatar');
    var partnerName = document.getElementById('pt-inbox-partner-name');
    var partnerHandle = document.getElementById('pt-inbox-partner-handle');
    var messagesEl = document.getElementById('pt-inbox-messages');
    var composer = document.getElementById('pt-inbox-composer');
    var inputEl = document.getElementById('pt-inbox-input');
    var sendBtn = document.getElementById('pt-inbox-send');
    var replyBar = document.getElementById('pt-inbox-reply-bar');
    var replyLabel = document.getElementById('pt-inbox-reply-label');
    var replyText = document.getElementById('pt-inbox-reply-text');
    var replyCancel = document.getElementById('pt-inbox-reply-cancel');

    var conversations = [];
    var activePartnerId = 0;
    var activePartnerName = '';
    var replyToId = 0;
    var lastMessageId = 0;
    var pollTimer = null;
    var isSending = false;

    function postAjax(action, data) {
        var formData = new FormData();
        formData.append('action', action);
        formData.append('nonce', config.nonce);

        Object.keys(data || {}).forEach(function (key) {
            formData.append(key, data[key]);
        });

        return fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        }).then(function (res) {
            return res.json();
        });
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str == null ? '' : String(str);
        return div.innerHTML;
    }

    function renderConversations(filterText) {
        var query = (filterText || '').trim().toLowerCase();
        var filtered = conversations.filter(function (c) {
            if (!query) return true;
            return (
                c.display_name.toLowerCase().indexOf(query) !== -1 ||
                c.username.toLowerCase().indexOf(query) !== -1
            );
        });

        if (filtered.length === 0) {
            convContainer.innerHTML =
                '<div class="pt-inbox-empty-state">' +
                escapeHtml(config.i18n.noConversations) +
                '</div>';
            return;
        }

        convContainer.innerHTML = filtered
            .map(function (c) {
                var activeClass = c.user_id === activePartnerId ? ' is-active' : '';
                var badge =
                    c.unread > 0
                        ? '<span class="pt-inbox-unread-badge">' + c.unread + '</span>'
                        : '';
                return (
                    '<div class="pt-inbox-conv-item' +
                    activeClass +
                    '" data-user-id="' +
                    c.user_id +
                    '">' +
                    '<img class="pt-inbox-conv-avatar" src="' +
                    escapeHtml(c.avatar) +
                    '" alt="" />' +
                    '<div class="pt-inbox-conv-meta">' +
                    '<div class="pt-inbox-conv-top">' +
                    '<span class="pt-inbox-conv-name">' +
                    escapeHtml(c.display_name) +
                    '</span>' +
                    '<span class="pt-inbox-conv-time">' +
                    escapeHtml(c.last_time) +
                    '</span>' +
                    '</div>' +
                    '<div class="pt-inbox-conv-bottom">' +
                    '<span class="pt-inbox-conv-snippet">' +
                    escapeHtml(c.last_message || '@' + c.username) +
                    '</span>' +
                    badge +
                    '</div>' +
                    '</div>' +
                    '</div>'
                );
            })
            .join('');
    }

    function loadConversations() {
        return postAjax('pt_inbox_get_conversations', {}).then(function (res) {
            if (res && res.success && res.data) {
                conversations = res.data.conversations || [];
                renderConversations(searchInput ? searchInput.value : '');
            }
        });
    }

    function setReplyTarget(msgId, senderName, text) {
        replyToId = msgId;
        if (replyBar && replyLabel && replyText) {
            replyLabel.textContent = 'Replying to ' + senderName;
            replyText.textContent = text;
            replyBar.style.display = 'flex';
        }
        if (inputEl) {
            inputEl.focus();
        }
    }

    function clearReplyTarget() {
        replyToId = 0;
        if (replyBar) {
            replyBar.style.display = 'none';
        }
    }

    function appendMessages(msgs, replaceAll) {
        if (replaceAll) {
            messagesEl.innerHTML = '';
        }

        if (replaceAll && (!msgs || msgs.length === 0)) {
            messagesEl.innerHTML =
                '<div class="pt-inbox-empty-state">' +
                escapeHtml(config.i18n.noMessages) +
                '</div>';
            return;
        }

        if (!msgs || msgs.length === 0) {
            return;
        }

        var emptyState = messagesEl.querySelector('.pt-inbox-empty-state');
        if (emptyState) {
            emptyState.remove();
        }

        var wasNearBottom =
            messagesEl.scrollHeight - messagesEl.scrollTop - messagesEl.clientHeight < 80;

        msgs.forEach(function (m) {
            if (m.id > lastMessageId) {
                lastMessageId = m.id;
            }
            if (messagesEl.querySelector('[data-msg-id="' + m.id + '"]')) {
                return;
            }

            var quoteHtml = '';
            if (m.reply_to) {
                quoteHtml =
                    '<div class="pt-inbox-msg-quote">' +
                    '<div class="pt-inbox-msg-quote-name">' +
                    escapeHtml(m.reply_to.sender_name) +
                    '</div>' +
                    '<div class="pt-inbox-msg-quote-text">' +
                    escapeHtml(m.reply_to.message) +
                    '</div>' +
                    '</div>';
            }

            var senderDisplay = m.is_mine ? 'You' : activePartnerName;
            var row = document.createElement('div');
            row.className = 'pt-inbox-msg-row ' + (m.is_mine ? 'is-mine' : 'is-theirs');
            row.setAttribute('data-msg-id', String(m.id));
            row.setAttribute('data-sender-name', senderDisplay);
            row.setAttribute('data-msg-text', m.message);
            row.innerHTML =
                '<div class="pt-inbox-msg-bubble">' +
                quoteHtml +
                escapeHtml(m.message) +
                '</div>' +
                '<div class="pt-inbox-msg-time">' +
                '<span>' + escapeHtml(m.time) + '</span>' +
                '<button type="button" class="pt-inbox-reply-btn">Reply</button>' +
                '</div>';
            messagesEl.appendChild(row);
        });

        if (replaceAll || wasNearBottom) {
            messagesEl.scrollTop = messagesEl.scrollHeight;
        }
    }

    function openConversation(userId) {
        var partner = conversations.find(function (c) {
            return c.user_id === userId;
        });
        if (!partner) {
            return;
        }

        activePartnerId = userId;
        activePartnerName = partner.display_name || '';
        clearReplyTarget();
        lastMessageId = 0;
        partner.unread = 0;
        renderConversations(searchInput ? searchInput.value : '');

        partnerAvatar.src = partner.avatar;
        partnerName.textContent = partner.display_name;
        partnerName.href = partner.profile_url || '#';
        partnerHandle.textContent = '@' + partner.username;

        chatHeader.style.display = 'flex';
        composer.style.display = 'flex';
        messagesEl.innerHTML = '<div class="pt-inbox-loading">...</div>';

        postAjax('pt_inbox_get_messages', {
            partner_id: activePartnerId,
            after_id: 0
        }).then(function (res) {
            if (res && res.success && res.data) {
                appendMessages(res.data.messages || [], true);
            }
        });
    }

    function pollNewMessages() {
        var panel = document.querySelector('[data-tab-panel="inbox"]');
        if (!panel || panel.hasAttribute('hidden')) {
            return;
        }

        loadConversations();

        if (activePartnerId > 0) {
            postAjax('pt_inbox_get_messages', {
                partner_id: activePartnerId,
                after_id: lastMessageId
            }).then(function (res) {
                if (res && res.success && res.data && res.data.messages.length > 0) {
                    appendMessages(res.data.messages, false);
                }
            });
        }
    }

    convContainer.addEventListener('click', function (e) {
        var item = e.target.closest('.pt-inbox-conv-item');
        if (!item) return;
        var uid = parseInt(item.getAttribute('data-user-id'), 10);
        if (uid > 0) {
            openConversation(uid);
        }
    });

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            renderConversations(searchInput.value);
        });
    }

    if (inputEl) {
        inputEl.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                composer.dispatchEvent(new Event('submit', { cancelable: true }));
            }
        });
    }

    messagesEl.addEventListener('click', function (e) {
        var btn = e.target.closest('.pt-inbox-reply-btn');
        if (!btn) return;
        var row = btn.closest('.pt-inbox-msg-row');
        if (!row) return;
        var msgId = parseInt(row.getAttribute('data-msg-id'), 10);
        var senderName = row.getAttribute('data-sender-name') || '';
        var msgText = row.getAttribute('data-msg-text') || '';
        if (msgId > 0) {
            setReplyTarget(msgId, senderName, msgText);
        }
    });

    if (replyCancel) {
        replyCancel.addEventListener('click', clearReplyTarget);
    }

    composer.addEventListener('submit', function (e) {
        e.preventDefault();
        if (isSending || !activePartnerId) {
            return;
        }

        var text = (inputEl.value || '').trim();
        if (!text) {
            return;
        }

        isSending = true;
        sendBtn.disabled = true;

        postAjax('pt_inbox_send_message', {
            partner_id: activePartnerId,
            reply_to_id: replyToId,
            message: text
        })
            .then(function (res) {
                if (res && res.success && res.data && res.data.message) {
                    inputEl.value = '';
                    clearReplyTarget();
                    appendMessages([res.data.message], false);
                    messagesEl.scrollTop = messagesEl.scrollHeight;
                    loadConversations();
                } else {
                    alert((res && res.data && res.data.message) || config.i18n.sendError);
                }
            })
            .catch(function () {
                alert(config.i18n.sendError);
            })
            .finally(function () {
                isSending = false;
                sendBtn.disabled = false;
                inputEl.focus();
            });
    });

    loadConversations();
    pollTimer = setInterval(pollNewMessages, 4000);
})();
