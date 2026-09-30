/**
 * ai-chat.js — Ownify AI inside the sheet (pages/ai.php).
 *
 * The sheet itself — pulling it up, pushing it down — is ai-sheet.js. This
 * is what is in it:
 *
 *   consent    the question before anything goes to Gemini: yes or not now
 *   declined   the way back to yes
 *   chat       the conversation: fetched when the sheet first opens
 *              (api/ai/state.php), asked in api/ai/chat.php, a change the
 *              assistant prepared confirmed in api/ai/action.php
 *
 * Everything the model says is drawn from the server's blocks with
 * textContent — text and bold, never HTML. Every failure is the server's own
 * sentence (or, with no answer at all, ours), never Gemini's error.
 */

(function () {
    'use strict';

    var sheet = document.querySelector('[data-ai]');
    if (!sheet) { return; }

    var copy     = parseJson(sheet.getAttribute('data-ai-copy')) || {};
    var session  = parseJson(sheet.getAttribute('data-ai-session')) || {};
    var errors   = copy.errors || {};

    var scroller = sheet.querySelector('[data-ai-scroller]');
    var tools    = sheet.querySelector('[data-ai-tools]');
    var composer = sheet.querySelector('[data-ai-composer]');
    var form     = sheet.querySelector('[data-ai-form]');
    var input    = sheet.querySelector('[data-ai-input]');
    var send     = sheet.querySelector('[data-ai-send]');
    var notice   = sheet.querySelector('[data-ai-notice]');
    var left     = sheet.querySelector('[data-ai-remaining]');
    var thread   = sheet.querySelector('[data-ai-thread]');
    var empty    = sheet.querySelector('[data-ai-empty]');
    var noData   = sheet.querySelector('[data-ai-no-data]');
    var history  = sheet.querySelector('[data-ai-history-panel]');
    var list     = sheet.querySelector('[data-ai-history-list]');
    var historyButton = sheet.querySelector('[data-ai-history]');
    var consentError  = sheet.querySelector('[data-ai-consent-error]');

    var state = {
        view: sheet.getAttribute('data-ai-view-current') || 'consent',
        loaded: false,
        loading: false,
        busy: false,
        conversation: null,
        conversations: [],
        usage: session.usage || { used: 0, limit: 0, remaining: 0 },
        available: session.available !== false,
        unavailableNotice: session.notice || null,
        lastFailed: null
    };

    /* ------------------------------------------------------------ helpers */

    function parseJson(text) {
        try { return JSON.parse(text || 'null'); } catch (e) { return null; }
    }

    function csrf() {
        var panel = document.querySelector('[data-account]');
        return panel ? (panel.getAttribute('data-csrf') || '') : '';
    }

    /** A form post as the website makes them: its session, its CSRF token. */
    function post(path, fields) {
        var body = new FormData();
        body.append('csrf', csrf());
        Object.keys(fields || {}).forEach(function (name) { body.append(name, fields[name]); });

        return fetch(path, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        }).then(function (response) {
            return response.json()
                .catch(function () { return null; })
                .then(function (answer) { return { status: response.status, body: answer }; });
        }, function () {
            return { status: 0, body: null };
        });
    }

    function icon(name) {
        var template = sheet.querySelector('template[data-ai-icon="' + name + '"]');
        return template ? template.content.firstElementChild.cloneNode(true) : document.createElement('span');
    }

    function atBottom() {
        return scroller.scrollHeight - scroller.scrollTop - scroller.clientHeight < 80;
    }

    function toBottom(smooth) {
        window.requestAnimationFrame(function () {
            scroller.scrollTo({ top: scroller.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
        });
    }

    /* -------------------------------------------------------------- views */

    function showView(view) {
        state.view = view;
        sheet.setAttribute('data-ai-view-current', view);

        Array.prototype.forEach.call(sheet.querySelectorAll('[data-ai-view]'), function (section) {
            section.hidden = section.getAttribute('data-ai-view') !== view;
        });

        var chat = view === 'chat';
        tools.hidden = !chat;
        composer.hidden = !chat;
        if (!chat) { closeHistory(); }

        scroller.scrollTop = 0;
    }

    /* ------------------------------------------------------------- notice */

    function setNotice(text, retry) {
        notice.textContent = '';

        if (!text) {
            notice.hidden = true;
            return;
        }

        var words = document.createElement('span');
        words.textContent = text;
        notice.appendChild(words);

        if (retry) {
            var again = document.createElement('button');
            again.type = 'button';
            again.className = 'ai-notice__retry';
            again.textContent = copy.retry || 'Opnieuw proberen';
            again.addEventListener('click', function () {
                setNotice(null);
                if (typeof retry === 'function') { retry(); }
            });
            notice.appendChild(again);
        }

        notice.hidden = false;
    }

    function setUsage(usage) {
        if (!usage) { return; }
        state.usage = usage;

        left.textContent = usage.limit > 0
            ? (copy.remaining || 'Nog %d van %d berichten vandaag').replace('%d', usage.remaining).replace('%d', usage.limit)
            : '';

        refreshComposer();
    }

    /** The field works unless the day's messages are used up; the button when there is something to send. */
    function refreshComposer() {
        var out = state.usage && state.usage.limit > 0 && state.usage.remaining <= 0;

        /* Never disabled while an answer is on its way: on a phone that
           would take the keyboard away. */
        input.disabled = out;
        send.disabled = out || state.busy || input.value.trim() === '';

        if (out && !state.busy) {
            setNotice(errors.limit || null);
        }
    }

    /* ---------------------------------------------------------- drawing */

    function spans(parent, list) {
        (list || []).forEach(function (span) {
            if (span.b) {
                var strong = document.createElement('strong');
                strong.textContent = span.t;
                parent.appendChild(strong);
            } else {
                parent.appendChild(document.createTextNode(span.t));
            }
        });
    }

    function blocksInto(bubble, message) {
        var blocks = message.blocks;

        if (!blocks || !blocks.length) {
            var p = document.createElement('p');
            p.textContent = message.text;
            bubble.appendChild(p);
            return;
        }

        blocks.forEach(function (block) {
            var node;

            if (block.type === 'ul' || block.type === 'ol') {
                node = document.createElement(block.type);
                (block.items || []).forEach(function (item) {
                    var li = document.createElement('li');
                    spans(li, item);
                    node.appendChild(li);
                });
            } else {
                node = document.createElement(block.type === 'h' ? 'h3' : 'p');
                spans(node, block.spans);
            }

            bubble.appendChild(node);
        });
    }

    function actionNode(message) {
        var action = message.action;
        var box = document.createElement('div');
        box.className = 'ai-action' + (action.state === 'done' ? ' ai-action--done' : '');

        var title = document.createElement('p');
        title.className = 'ai-action__title';
        title.textContent = action.title;
        box.appendChild(title);

        if (action.summary) {
            var summary = document.createElement('p');
            summary.className = 'ai-action__summary';
            summary.textContent = action.summary;
            box.appendChild(summary);
        }

        if (action.state === 'pending') {
            var buttons = document.createElement('div');
            buttons.className = 'ai-action__buttons';

            [['confirm', action.confirm, 'btn press ai-btn--primary'], ['decline', action.decline, 'btn press']].forEach(function (b) {
                var button = document.createElement('button');
                button.type = 'button';
                button.className = b[2];
                button.textContent = b[1];
                button.setAttribute('data-ai-act', b[0]);
                button.setAttribute('data-message', String(message.id));
                buttons.appendChild(button);
            });

            box.appendChild(buttons);
        } else if (action.status) {
            var status = document.createElement('p');
            status.className = 'ai-action__status';
            status.textContent = action.status;
            box.appendChild(status);
        }

        return box;
    }

    function messageNode(message) {
        var item = document.createElement('li');
        item.className = 'ai-msg ai-msg--' + message.role;
        if (message.id) { item.setAttribute('data-message-id', String(message.id)); }

        if (message.role === 'system') {
            var note = document.createElement('p');
            note.className = 'ai-msg__note';
            note.appendChild(icon('check'));
            var words = document.createElement('span');
            words.textContent = message.text;
            note.appendChild(words);
            item.appendChild(note);
            return item;
        }

        var bubble = document.createElement('div');
        bubble.className = 'ai-msg__bubble';

        if (message.role === 'assistant') {
            blocksInto(bubble, message);
            if (message.action) { bubble.appendChild(actionNode(message)); }
        } else {
            bubble.textContent = message.text;
        }

        item.appendChild(bubble);
        return item;
    }

    /** Adds a message, or redraws it when it is already there (a proposal just answered). */
    function putMessage(message) {
        var existing = message.id ? thread.querySelector('[data-message-id="' + message.id + '"]') : null;
        var node = messageNode(message);

        if (existing) {
            node.style.animation = 'none';
            thread.replaceChild(node, existing);
        } else {
            thread.appendChild(node);
        }
    }

    function showThread(messages) {
        thread.textContent = '';
        (messages || []).forEach(putMessage);

        var any = thread.children.length > 0;
        thread.hidden = !any;
        empty.hidden = any;
        toBottom(false);
    }

    function thinking() {
        var item = document.createElement('li');
        item.className = 'ai-msg ai-msg--assistant';
        item.setAttribute('data-ai-thinking', '');

        var bubble = document.createElement('div');
        bubble.className = 'ai-msg__bubble';

        var dots = document.createElement('span');
        dots.className = 'ai-typing';
        dots.setAttribute('role', 'status');
        dots.setAttribute('aria-label', copy.thinking || 'Ownify AI denkt na…');
        dots.innerHTML = '<span></span><span></span><span></span>';

        bubble.appendChild(dots);
        item.appendChild(bubble);
        return item;
    }

    /* ------------------------------------------------------------ loading */

    function applySession(next) {
        if (!next) { return; }

        state.available = next.available !== false;
        state.unavailableNotice = next.notice || null;
        setUsage(next.usage);
        if (noData) { noData.hidden = !!next.has_data; }

        if (!state.available && state.unavailableNotice) {
            setNotice(state.unavailableNotice);
        } else if (!(state.usage && state.usage.limit > 0 && state.usage.remaining <= 0)) {
            setNotice(null);
        }
    }

    function viewFor(consent) {
        return consent === 'accepted' ? 'chat' : (consent === 'declined' ? 'declined' : 'consent');
    }

    /** The sheet's content from the server: the last conversation, or the one asked for, or a new one. */
    function load(fields) {
        if (state.loading) { return Promise.resolve(); }
        state.loading = true;

        return post('api/ai/state.php', fields || {}).then(function (answer) {
            state.loading = false;

            if (!answer.body || !answer.body.ok) {
                failed(answer, function () { load(fields); });
                return;
            }

            var body = answer.body;
            state.loaded = true;
            applySession(body);
            showView(viewFor(body.consent));

            state.conversation = body.conversation || null;
            state.conversations = body.conversations || [];
            showThread(body.messages || []);
            drawHistory();
        });
    }

    /* ------------------------------------------------------------ failure */

    /**
     * What to show when something did not work: the server's sentence, or
     * ours when there was no answer at all. A few mean more than a notice.
     */
    function failed(answer, retry) {
        var body = answer.body || {};
        var code = body.code || null;

        if (body.usage) { setUsage(body.usage); }

        if (answer.status === 401 || answer.status === 419) {
            setNotice(errors.signed_out || 'Je bent niet meer ingelogd.');
            return;
        }

        if (code === 'consent') {
            showView('consent');
            return;
        }

        if (code === 'not_found') {
            state.conversation = null;
            showThread([]);
            setNotice(body.error || errors.not_found || null);
            return;
        }

        if (code === 'limit') {
            setNotice(body.error || errors.limit || null);
            refreshComposer();
            return;
        }

        var text = answer.status === 0 ? errors.network : (body.error || errors.unavailable);
        var canRetry = answer.status === 0 || code === 'timeout' || code === 'unavailable' || code === 'quota' || !code;

        setNotice(text || 'Er ging iets mis.', canRetry ? retry : null);
    }

    /* --------------------------------------------------------------- send */

    function autosize() {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 140) + 'px';
        refreshComposer();
    }

    function ask(text) {
        text = (text || '').trim();
        if (!text || state.busy) { return; }

        state.busy = true;
        setNotice(null);

        var mine = messageNode({ role: 'user', text: text });
        var dots = thinking();

        empty.hidden = true;
        thread.hidden = false;
        thread.appendChild(mine);
        thread.appendChild(dots);

        input.value = '';
        autosize();
        toBottom(true);

        var fields = { message: text };
        if (state.conversation) { fields.conversation_id = String(state.conversation.id); }

        post('api/ai/chat.php', fields).then(function (answer) {
            state.busy = false;
            if (dots.parentNode) { dots.parentNode.removeChild(dots); }

            if (answer.body && answer.body.ok) {
                if (mine.parentNode) { mine.parentNode.removeChild(mine); }

                var wasNew = !state.conversation;
                state.conversation = answer.body.conversation || state.conversation;
                (answer.body.messages || []).forEach(putMessage);
                setUsage(answer.body.usage);
                toBottom(true);

                if (wasNew || !state.conversations.length) { state.historyStale = true; }
                afterActions(answer.body.messages);
                refreshComposer();
                return;
            }

            /* Not answered, not stored: the question goes back in the field. */
            if (mine.parentNode) { mine.parentNode.removeChild(mine); }
            if (!thread.children.length) { thread.hidden = true; empty.hidden = false; }
            if (!input.value) { input.value = text; autosize(); }

            failed(answer, function () { ask(input.value || text); });
            refreshComposer();
        });

        refreshComposer();
    }

    /** A change that was just carried out shows on its own page too. */
    function afterActions(messages) {
        var done = (messages || []).some(function (m) { return m.action && m.action.state === 'done'; });
        if (done && window.GoalBoard) { window.GoalBoard.refresh(); }
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        ask(input.value);
    });

    input.addEventListener('input', autosize);

    /* Enter sends, Shift+Enter is a new line — on a keyboard; on a phone the
       return key stays a return key and the button sends. */
    input.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing && window.matchMedia('(pointer: fine)').matches) {
            event.preventDefault();
            ask(input.value);
        }
    });

    sheet.addEventListener('click', function (event) {
        var suggestion = event.target.closest('[data-ai-suggestion]');
        if (suggestion) {
            ask(suggestion.textContent);
            return;
        }

        var act = event.target.closest('[data-ai-act]');
        if (act && !act.disabled) {
            resolveAction(act);
            return;
        }

        var choice = event.target.closest('[data-ai-consent]');
        if (choice && !choice.disabled) {
            decide(choice);
            return;
        }

        if (event.target.closest('[data-ai-review]')) {
            showView('consent');
            return;
        }

        if (event.target.closest('[data-ai-new]')) {
            closeHistory();
            state.conversation = null;
            showThread([]);
            setNotice(state.available ? null : state.unavailableNotice);
            refreshComposer();
            input.focus();
            return;
        }

        if (event.target.closest('[data-ai-history]')) {
            if (history.hidden) { openHistory(); } else { closeHistory(); }
            return;
        }

        var open = event.target.closest('[data-ai-open-conversation]');
        if (open) {
            closeHistory();
            load({ conversation_id: open.getAttribute('data-ai-open-conversation') });
            return;
        }

        var remove = event.target.closest('[data-ai-delete-conversation]');
        if (remove) {
            deleteConversation(remove);
            return;
        }

        if (!history.hidden && event.target === history) { closeHistory(); }
    });

    /* ------------------------------------------------------------ actions */

    function resolveAction(button) {
        var buttons = button.parentNode.querySelectorAll('button');
        Array.prototype.forEach.call(buttons, function (b) { b.disabled = true; });

        post('api/ai/action.php', {
            message_id: button.getAttribute('data-message'),
            decision: button.getAttribute('data-ai-act')
        }).then(function (answer) {
            var messages = (answer.body && answer.body.messages) || [];

            if (answer.body && answer.body.ok) {
                messages.forEach(putMessage);
                afterActions(messages);
                toBottom(true);
                return;
            }

            messages.forEach(putMessage);
            Array.prototype.forEach.call(buttons, function (b) { b.disabled = false; });
            failed(answer, null);
        });
    }

    /* ------------------------------------------------------------ consent */

    function decide(button) {
        var accept = button.getAttribute('data-ai-consent') === 'accept';
        var buttons = sheet.querySelectorAll('[data-ai-consent]');
        Array.prototype.forEach.call(buttons, function (b) { b.disabled = true; });
        consentError.hidden = true;

        post('api/ai/consent.php', { decision: accept ? 'accept' : 'decline' }).then(function (answer) {
            Array.prototype.forEach.call(buttons, function (b) { b.disabled = false; });

            if (!answer.body || !answer.body.ok) {
                consentError.textContent = (answer.body && answer.body.error)
                    || (answer.status === 0 ? errors.network : errors.unavailable) || 'Dit kon niet worden opgeslagen.';
                consentError.hidden = false;
                return;
            }

            var consent = answer.body.consent;
            if (window.AppSettings) { window.AppSettings.setSwitch('ai_consent', consent === 'accepted'); }

            if (consent === 'accepted') {
                showView('chat');
                load({});
            } else {
                showView('declined');
            }
        });
    }

    /* The same answer, given in Instellingen → Privacy. */
    document.addEventListener('ownify:setting', function (event) {
        if (!event.detail || event.detail.key !== 'ai_consent') { return; }

        state.loaded = false;
        showView(event.detail.on ? 'chat' : 'declined');
        if (event.detail.on && isOpen()) { load({}); }
    });

    /* All conversations wiped in Privacy: none left here either. */
    document.addEventListener('ownify:setting-action', function (event) {
        if (!event.detail || event.detail.key !== 'ai_clear_history') { return; }

        state.conversation = null;
        state.conversations = [];
        showThread([]);
        drawHistory();
    });

    /* ------------------------------------------------------ conversations */

    function drawHistory() {
        list.textContent = '';

        if (!state.conversations.length) {
            var none = document.createElement('li');
            none.className = 'ai-history__empty';
            none.textContent = copy.empty || 'Nog geen gesprekken.';
            list.appendChild(none);
            return;
        }

        state.conversations.forEach(function (conversation) {
            var item = document.createElement('li');
            item.className = 'ai-history__item' + (state.conversation && state.conversation.id === conversation.id ? ' is-current' : '');

            var open = document.createElement('button');
            open.type = 'button';
            open.className = 'ai-history__open';
            open.setAttribute('data-ai-open-conversation', String(conversation.id));

            var name = document.createElement('span');
            name.className = 'ai-history__name';
            name.textContent = conversation.title;
            var when = document.createElement('span');
            when.className = 'ai-history__when';
            when.textContent = conversation.label;
            open.appendChild(name);
            open.appendChild(when);

            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'ai-history__delete';
            remove.setAttribute('data-ai-delete-conversation', String(conversation.id));
            remove.setAttribute('aria-label', (copy['delete'] || 'Gesprek verwijderen') + ': ' + conversation.title);
            remove.appendChild(icon('trash'));

            item.appendChild(open);
            item.appendChild(remove);
            list.appendChild(item);
        });
    }

    function openHistory() {
        history.hidden = false;
        historyButton.setAttribute('aria-expanded', 'true');

        /* Asked again when a conversation was started since: its title is new. */
        if (state.historyStale) {
            state.historyStale = false;
            post('api/ai/state.php', state.conversation ? { conversation_id: String(state.conversation.id) } : { 'new': '1' })
                .then(function (answer) {
                    if (answer.body && answer.body.ok) {
                        state.conversations = answer.body.conversations || [];
                        drawHistory();
                    }
                });
        }

        drawHistory();
    }

    function closeHistory() {
        if (history.hidden) { return; }
        history.hidden = true;
        historyButton.setAttribute('aria-expanded', 'false');
    }

    /** First press arms the button ("Verwijderen?"), the second deletes. */
    function deleteConversation(button) {
        if (!button.classList.contains('is-armed')) {
            button.classList.add('is-armed');
            button.textContent = (copy['delete'] || 'Gesprek verwijderen') + '?';
            return;
        }

        var id = button.getAttribute('data-ai-delete-conversation');
        button.disabled = true;

        post('api/ai/delete.php', { conversation_id: id }).then(function (answer) {
            if (!answer.body || !answer.body.ok) {
                button.disabled = false;
                failed(answer, null);
                return;
            }

            state.conversations = state.conversations.filter(function (c) { return String(c.id) !== id; });

            if (state.conversation && String(state.conversation.id) === id) {
                state.conversation = null;
                showThread([]);
            }

            drawHistory();
        });
    }

    /* ------------------------------------------------------ the keyboard */

    /**
     * On a phone the keyboard covers the bottom of the window without making
     * it smaller (iOS, and Chrome on Android by default). The visual viewport
     * says how much is covered; the conversation ends that much higher, so
     * the field stays above the keys.
     */
    function followKeyboard() {
        var viewport = window.visualViewport;
        if (!viewport) { return; }

        function update() {
            var covered = Math.max(0, window.innerHeight - viewport.height - viewport.offsetTop);
            var keep = atBottom();

            sheet.style.setProperty('--ai-keyboard', (covered > 60 ? covered : 0) + 'px');
            if (keep) { toBottom(false); }
        }

        viewport.addEventListener('resize', update);
        viewport.addEventListener('scroll', update);
    }

    /* ------------------------------------------------------------- opening */

    function isOpen() {
        return sheet.getAttribute('aria-hidden') !== 'true';
    }

    /* The conversation is fetched the first time the sheet opens, not with
       every page: most visits never pull it up. */
    new MutationObserver(function () {
        if (!isOpen()) {
            closeHistory();
            if (document.activeElement === input) { input.blur(); }
            return;
        }

        if (state.view === 'chat' && !state.loaded) { load({}); }
    }).observe(sheet, { attributes: true, attributeFilter: ['aria-hidden'] });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !history.hidden) {
            event.stopPropagation();
            closeHistory();
        }
    }, true);

    /* --------------------------------------------------------------- init */

    followKeyboard();
    applySession(session);
    drawHistory();
    refreshComposer();
}());
