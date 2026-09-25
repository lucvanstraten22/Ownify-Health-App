/**
 * friends.js — the Vrienden page in the account panel.
 *
 * The panel has two pages when signed in: the account, and Vrienden behind
 * it. This moves between them, and runs everything on Vrienden:
 *
 *   Vriend toevoegen          looks the username up when Zoeken is pressed —
 *                             never while typing — and shows that one
 *                             account, with a request button if one can be
 *                             sent
 *   Accepteren / Weigeren     answer a request
 *   Verwijderen               asks first, in place, then ends the friendship
 *   Vriendverzoeken toestaan  the account's own switch
 *
 * Every request carries the CSRF token and at most the other person's id;
 * the server takes who is asking from the session and checks the pair
 * itself. After each change the page is fetched as the server now renders
 * it and the lists, the Vrienden button and the leaderboards are swapped in
 * — the way health-rating.js refreshes a score — so the Friends board shows
 * a new friend, or loses a removed one, without a reload.
 */

(function () {
    'use strict';

    var panel = document.querySelector('[data-account]');
    var view  = panel ? panel.querySelector('[data-account-view="friends"]') : null;
    if (!view) { return; }

    var main     = panel.querySelector('[data-account-view="main"]');
    var back     = panel.querySelector('[data-friends-back]');
    var title    = panel.querySelector('#account-title');
    var sheet    = panel.querySelector('.account__panel');
    var opener   = panel.querySelector('[data-friends-open]');
    var addBtn   = view.querySelector('[data-friends-add]');
    var form     = view.querySelector('[data-friends-search]');
    var input    = form.querySelector('input[name="username"]');
    var errorBox = view.querySelector('[data-friends-search-error]');
    var result   = view.querySelector('[data-friends-result]');
    var toggle   = view.querySelector('[data-friends-toggle]');
    var rowShape = view.querySelector('[data-friends-template]');
    var sentMark = view.querySelector('[data-friends-sent]');

    var accountTitle = title ? title.textContent.trim() : 'Account';

    /* ------------------------------------------------------------ helpers */

    function each(root, selector, fn) {
        Array.prototype.forEach.call(root.querySelectorAll(selector), fn);
    }

    function csrf() {
        return panel.getAttribute('data-csrf') || '';
    }

    /** Posts the fields and returns the parsed body, whatever the status. */
    function post(url, fields) {
        var body = new FormData();
        body.append('csrf', csrf());
        Object.keys(fields).forEach(function (key) { body.append(key, fields[key]); });

        return fetch(url, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (response) {
                return response.json().catch(function () {
                    return { ok: false, error: 'Onverwacht antwoord van de server.' };
                });
            })
            .catch(function () {
                return { ok: false, error: 'De server is niet bereikbaar.' };
            });
    }

    function button(label, action) {
        var node = document.createElement('button');
        node.type = 'button';
        node.className = 'btn press';
        node.setAttribute('data-friend-action', action);
        node.textContent = label;
        return node;
    }

    /* ------------------------------------------------------ the two pages */

    function showPage(name) {
        var friends = name === 'friends';

        view.hidden = !friends;
        if (main) { main.hidden = friends; }
        if (back) { back.hidden = !friends; }
        if (title) { title.textContent = friends ? 'Vrienden' : accountTitle; }
        if (sheet) { sheet.scrollTop = 0; }
    }

    panel.addEventListener('click', function (event) {
        if (event.target.closest('[data-friends-open]')) {
            showPage('friends');
            if (addBtn) { addBtn.focus({ preventScroll: true }); }
            /* Somebody may have asked, or answered, since this page loaded. */
            refresh({ lists: true, hold: 0 });
            return;
        }

        if (event.target.closest('[data-friends-back]')) {
            showPage('main');
            if (opener) { opener.focus({ preventScroll: true }); }
        }
    });

    /* The panel always opens on the account: once it has closed, the next
       opening starts there, whatever page it was left on. */
    if (typeof MutationObserver === 'function') {
        new MutationObserver(function () {
            if (panel.hidden) { showPage('main'); }
        }).observe(panel, { attributes: true, attributeFilter: ['hidden'] });
    }

    /* ---------------------------------------------------- Vriend toevoegen */

    function showSearchError(message) {
        errorBox.textContent = message || '';
        errorBox.hidden = !message;
    }

    addBtn.addEventListener('click', function () {
        var opening = form.hidden;

        form.hidden = !opening;
        addBtn.setAttribute('aria-expanded', opening ? 'true' : 'false');

        if (opening) {
            input.focus();
        } else {
            showSearchError(null);
            result.hidden = true;
            result.innerHTML = '';
        }
    });

    /* A new name clears the last answer; nothing is looked up until Zoeken. */
    input.addEventListener('input', function () { showSearchError(null); });

    form.addEventListener('submit', function (event) {
        event.preventDefault();

        var username = input.value.trim();
        var submit = form.querySelector('button[type="submit"]');

        showSearchError(null);

        if (!username) {
            showSearchError('Vul een gebruikersnaam in.');
            input.focus();
            return;
        }

        if (submit) { submit.disabled = true; }

        post('api/friends/search.php', { username: username }).then(function (answer) {
            if (submit) { submit.disabled = false; }

            if (!answer.ok || !answer.person) {
                result.hidden = true;
                result.innerHTML = '';
                showSearchError(answer.error || 'Er ging iets mis.');
                return;
            }

            showResult(answer.person);
        });
    });

    /** The one account the search found, in the same shape as the lists. */
    function showResult(person) {
        var row = rowShape.content.firstElementChild.cloneNode(true);

        row.setAttribute('data-user-id', String(person.id));
        row.setAttribute('data-username', person.username);
        row.querySelector('[data-friend-name]').textContent = person.username;

        if (person.avatar) {
            var slot = row.querySelector('[data-friend-avatar]');
            var image = document.createElement('img');
            image.src = person.avatar;
            image.alt = '';
            slot.innerHTML = '';
            slot.appendChild(image);
        }

        applyPerson(row, person);

        result.innerHTML = '';
        result.appendChild(row);
        result.hidden = false;
    }

    /**
     * Sets a search result to where the two now stand: the status line, and
     * the one thing that can be done about it, if anything.
     */
    function applyPerson(row, person) {
        var status  = row.querySelector('[data-friend-status]');
        var actions = row.querySelector('[data-friend-actions]');

        status.textContent = person.status;
        status.hidden = false;
        status.classList.remove('is-error');

        actions.innerHTML = '';
        actions.classList.remove('friend__actions--single');

        if (person.relation === 'none' && person.can_request) {
            actions.classList.add('friend__actions--single');
            actions.appendChild(button('Vriendverzoek sturen', 'request'));
        } else if (person.relation === 'outgoing') {
            /* The pill says it; the line above it would only repeat it. */
            actions.classList.add('friend__actions--single');
            actions.appendChild(sentMark.content.firstElementChild.cloneNode(true));
            status.hidden = true;
        } else if (person.relation === 'incoming') {
            actions.appendChild(button('Accepteren', 'accept'));
            actions.appendChild(button('Weigeren', 'decline'));
        }

        actions.hidden = actions.children.length === 0;
    }

    /* ----------------------------------------------------------- actions */

    function rowError(row, message) {
        var status = row.querySelector('[data-friend-status]');
        if (!status) { return; }

        status.textContent = message;
        status.hidden = false;
        status.classList.add('is-error');
    }

    function rowBusy(row, state) {
        each(row, 'button', function (node) { node.disabled = state; });
    }

    /** A row whose question has been answered: what happened, no buttons. */
    function rowDone(row, message) {
        var status  = row.querySelector('[data-friend-status]');
        var actions = row.querySelector('[data-friend-actions]');
        var confirm = row.querySelector('[data-friend-confirm]');

        if (status) {
            status.textContent = message;
            status.hidden = false;
            status.classList.remove('is-error');
        }
        if (actions) { actions.hidden = true; }
        if (confirm) { confirm.hidden = true; }
    }

    /** Keeps the search result in step when the same person is acted on in a list. */
    function syncResult(person, except) {
        if (!person) { return; }

        each(result, '[data-friend]', function (row) {
            if (row !== except && row.getAttribute('data-user-id') === String(person.id)) {
                applyPerson(row, person);
            }
        });
    }

    function confirmRemoval(row, open) {
        var actions = row.querySelector('[data-friend-actions]');
        var confirm = row.querySelector('[data-friend-confirm]');
        if (!confirm) { return; }

        confirm.hidden = !open;
        if (actions) { actions.hidden = open; }

        var focus = open
            ? confirm.querySelector('[data-friend-action="remove-cancel"]')
            : row.querySelector('[data-friend-action="remove-ask"]');
        if (focus) { focus.focus({ preventScroll: true }); }
    }

    view.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-friend-action]');
        if (!trigger || trigger.disabled) { return; }

        var row    = trigger.closest('[data-friend]');
        var action = trigger.getAttribute('data-friend-action');
        var id     = row ? row.getAttribute('data-user-id') : null;

        if (!row || !id) { return; }

        if (action === 'remove-ask') { confirmRemoval(row, true); return; }
        if (action === 'remove-cancel') { confirmRemoval(row, false); return; }

        rowBusy(row, true);

        post('api/friends/request.php', { user_id: id, action: action }).then(function (answer) {
            rowBusy(row, false);

            if (!answer.ok) {
                if (action === 'remove') { confirmRemoval(row, false); }
                /* A search result shows where things now stand; either way
                   the row says why, and keeps saying it — the lists are
                   left alone so the reason stays on screen. */
                if (answer.person && result.contains(row)) {
                    applyPerson(row, answer.person);
                }
                rowError(row, answer.error || 'Er ging iets mis.');
                syncResult(answer.person, row);
                refresh({ lists: false });
                return;
            }

            if (result.contains(row)) {
                applyPerson(row, answer.person);
                if (action !== 'request') {
                    row.querySelector('[data-friend-status]').textContent = answer.message;
                }
            } else {
                rowDone(row, answer.message);
            }

            syncResult(answer.person, row);

            /* The leaderboards change at once; the lists a moment later, so
               "Jullie zijn nu vrienden." can be read before the row moves. */
            refresh({ lists: true, hold: 1100 });
        });
    });

    /* ---------------------------------------------- Vriendverzoeken toestaan */

    function setToggle(on) {
        var mark = toggle.querySelector('.switch');
        var note = toggle.querySelector('[data-friends-toggle-note]');

        toggle.setAttribute('aria-checked', on ? 'true' : 'false');
        if (mark) { mark.classList.toggle('is-on', on); }
        if (note) {
            note.textContent = toggle.getAttribute(on ? 'data-note-on' : 'data-note-off');
            note.classList.remove('is-error');
        }
    }

    toggle.addEventListener('click', function () {
        var before = toggle.getAttribute('aria-checked') === 'true';

        setToggle(!before);
        toggle.disabled = true;

        post('api/friends/settings.php', { allow_requests: before ? '0' : '1' }).then(function (answer) {
            toggle.disabled = false;

            if (!answer.ok) {
                setToggle(before);
                var note = toggle.querySelector('[data-friends-toggle-note]');
                if (note) {
                    note.textContent = answer.error || 'Dit kon niet worden opgeslagen.';
                    note.classList.add('is-error');
                }
                return;
            }

            setToggle(!!answer.allow_requests);
        });
    });

    /* ----------------------------------------------------------- refresh */

    var latest = 0;

    function replace(current, next) {
        if (!current || !next || !current.parentNode) { return; }
        current.parentNode.replaceChild(document.importNode(next, true), current);
    }

    /**
     * Fetches the page as the server now renders it and swaps in what a
     * friendship changes: every leaderboard's rows — the Friends board gains
     * or loses the person at once — the Vrienden button's line, and, unless
     * told not to, the two lists here, no sooner than `hold` ms after the
     * change. Only the newest answer is used, however many refreshes
     * overlap.
     */
    function refresh(options) {
        var ticket  = ++latest;
        var lists   = !options || options.lists !== false;
        var readyAt = Date.now() + ((options && options.hold) || 0);

        return fetch(window.location.href.split('#')[0], {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'Accept': 'text/html' }
        })
            .then(function (response) {
                if (!response.ok) { throw new Error('HTTP ' + response.status); }
                return response.text();
            })
            .then(function (html) {
                if (ticket !== latest) { return; }

                var fresh = new DOMParser().parseFromString(html, 'text/html');
                var next  = fresh.querySelector('[data-account-view="friends"]');
                if (!next || !fresh.querySelector('[data-deck]')) { return; }

                if (lists) {
                    window.setTimeout(function () {
                        if (ticket !== latest) { return; }
                        replace(view.querySelector('[data-friends-requests]'), next.querySelector('[data-friends-requests]'));
                        replace(view.querySelector('[data-friends-list]'), next.querySelector('[data-friends-list]'));
                    }, Math.max(0, readyAt - Date.now()));
                }

                var summary = panel.querySelector('[data-friends-summary]');
                var nextSummary = fresh.querySelector('[data-friends-summary]');
                if (summary && nextSummary) { summary.innerHTML = nextSummary.innerHTML; }

                /* The dot on the account button, and what it says aloud. */
                var dot = document.querySelector('[data-friends-dot]');
                var nextDot = fresh.querySelector('[data-friends-dot]');
                if (dot && nextDot) {
                    dot.hidden = nextDot.hidden;
                    var button = dot.closest('[data-account-open]');
                    var nextButton = nextDot.closest('[data-account-open]');
                    if (button && nextButton) {
                        button.setAttribute('aria-label', nextButton.getAttribute('aria-label'));
                    }
                }

                /* The boards keep their own elements — community.js holds on
                   to them to switch scope and period — so only their rows
                   change. */
                each(document, '[data-board]', function (board) {
                    var nextBoard = fresh.querySelector('[data-board][data-scope="' + board.dataset.scope
                        + '"][data-period="' + board.dataset.period + '"]');

                    if (nextBoard) {
                        board.innerHTML = nextBoard.innerHTML;
                        each(board, '.reveal', function (item) { item.classList.add('is-visible'); });
                        each(board, '.card', function (item) { item.dataset.animated = 'true'; });
                    }
                });
            })
            .catch(function () { /* what was saved is saved; the next load shows it */ });
    }
}());
