/**
 * challenges.js — Uitdagingen on the website (docs/CHALLENGES.md).
 *
 * Community shows the challenges you are in; Uitdagingen lists them with
 * Nieuwe uitdaging and Code invoeren; each challenge has a page of its own.
 * Those are server-rendered (pages/community.php, challenges.php,
 * challenge-detail.php). This file is everything that happens in the one
 * bottom sheet (components/challenge-sheet.php):
 *
 *   Nieuwe uitdaging     one sheet that previews the challenge as it is filled
 *                        in, then shows its code
 *   Code invoeren        five boxes; the fifth digit looks the code up, and
 *                        what it found appears in the same sheet to review
 *   Bekijken             an invitation or a found code: what it is, who is in
 *                        it, what they would see — and only then Meedoen
 *   Vrienden uitnodigen  from a challenge you are in
 *   Uitdaging verlaten   after "are you sure?"
 *
 * After anything that changes a challenge the page is read again and the
 * parts that show challenges are swapped in place — Community's card, the
 * list, the challenge pages — as Doelen does after a goal changes.
 *
 * Every sentence comes from the server, in the reader's language: the copy
 * and the metrics in [data-challenges-data], the answers of the endpoints.
 */

(function () {
    'use strict';

    var sheetEl = document.querySelector('[data-challenge-sheet]');
    var dataEl = document.querySelector('[data-challenges-data]');
    if (!sheetEl || !dataEl) { return; }

    var T = window.OwnifyI18n;
    var nav = window.AppNav || {};
    var panel = sheetEl.querySelector('[data-sheet-panel]');
    var content = sheetEl.querySelector('[data-sheet-content]');
    var grab = sheetEl.querySelector('[data-sheet-grab]');
    var toastEl = document.querySelector('[data-challenge-toast]');
    var reduce = window.matchMedia('(prefers-reduced-motion: reduce)');

    var D = read();
    var C = D.copy;

    function read() {
        try { return JSON.parse(dataEl.textContent || '{}'); } catch (e) { return {}; }
    }

    /* ------------------------------------------------------------ helpers */

    function esc(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function icon(name) { return (D.icons && D.icons[name]) || ''; }
    function solid(name) { return (D.solid && D.solid[name]) || icon(name); }

    function format(pattern, value) { return String(pattern).replace('%s', value); }

    function csrf() {
        var account = document.querySelector('[data-account]');
        return account ? (account.getAttribute('data-csrf') || '') : '';
    }

    /** POST to an endpoint; resolves with its JSON answer, whatever its status. */
    function post(path, fields) {
        var body = new FormData();
        body.append('csrf', csrf());
        Object.keys(fields).forEach(function (key) {
            var value = fields[key];
            if (Array.isArray(value)) {
                value.forEach(function (item) { body.append(key + '[]', item); });
            } else if (value !== null && value !== undefined) {
                body.append(key, value);
            }
        });

        return fetch(path, { method: 'POST', body: body, credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (response) {
                return response.json().catch(function () { return { ok: false }; });
            })
            .catch(function () { return { ok: false }; });
    }

    function failed(answer) {
        return (answer && answer.error) || T.t('Er ging iets mis. Probeer het nog eens.');
    }

    function avatar(person, size, pending) {
        var classes = 'ch-av' + (person.you ? ' ch-av--you' : '') + (pending ? ' ch-av--pending' : '');
        var style = size ? ' style="--av: ' + size + 'px"' : '';
        var photo = person.avatar ? '<img class="ch-av__photo" src="' + esc(person.avatar) + '" alt="" loading="lazy" decoding="async">' : '';
        return '<span class="' + classes + '" data-accent="' + esc(person.accent || 'health') + '"' + style + ' aria-hidden="true">'
            + esc(person.initial || '') + photo + '</span>';
    }

    function faces(f) {
        return '<span class="ch-faces" role="img" aria-label="' + esc(f.label) + '">'
            + f.people.map(function (p) { return avatar(p); }).join('')
            + (f.more > 0 ? '<span class="ch-faces__more" aria-hidden="true">+' + f.more + '</span>' : '')
            + '</span>';
    }

    /* -------------------------------------------------------------- toast */

    var toastTimer = null;

    function toast(text) {
        if (!toastEl || !text) { return; }
        toastEl.textContent = text;
        toastEl.classList.remove('is-on');
        void toastEl.offsetWidth;
        toastEl.classList.add('is-on');
        window.clearTimeout(toastTimer);
        toastTimer = window.setTimeout(function () { toastEl.classList.remove('is-on'); }, 2600);
    }

    /* -------------------------------------------------------------- sheet */

    var sheetOpen = false;
    var closeTimer = null;
    var lastFocus = null;
    var onClosed = null;

    function head(title) {
        return '<div class="ch-sheet__head">'
            + '<h2 class="ch-sheet__title" id="ch-sheet-title">' + esc(title) + '</h2>'
            + '<button type="button" class="ch-sheet__close press" data-sheet-close aria-label="' + esc(C.review.close) + '">'
            + icon('chevron-down') + '</button></div>';
    }

    /** Puts content in the sheet; opens it if it is not open yet. */
    function show(html, focusSelector) {
        window.clearTimeout(closeTimer);
        content.innerHTML = html;

        if (!sheetOpen) {
            content.onclick = null;
            lastFocus = document.activeElement;
            sheetOpen = true;
            sheetEl.hidden = false;
            panel.style.transform = '';
            void panel.offsetWidth;
            window.requestAnimationFrame(function () { sheetEl.classList.add('is-open'); });
        } else {
            content.classList.remove('is-step');
            void content.offsetWidth;
            content.classList.add('is-step');
        }

        /* After the sheet has arrived: a field that opens the keyboard
           mid-rise makes the page jump. */
        window.setTimeout(function () {
            var target = (focusSelector && content.querySelector(focusSelector))
                || content.querySelector('[data-sheet-focus]')
                || content.querySelector('button:not([disabled]), input');
            if (target && target.focus) { target.focus({ preventScroll: true }); }
        }, reduce.matches ? 0 : 180);
    }

    function hide() {
        if (!sheetOpen) { return; }
        sheetOpen = false;
        content.onclick = null;
        sheetEl.classList.remove('is-open');
        panel.classList.remove('is-dragging');
        panel.style.transform = '';

        var done = onClosed;
        onClosed = null;

        closeTimer = window.setTimeout(function () {
            sheetEl.hidden = true;
            content.innerHTML = '';
        }, 240);

        if (lastFocus && lastFocus.focus && document.contains(lastFocus)) { lastFocus.focus({ preventScroll: true }); }
        if (done) { done(); }
    }

    sheetEl.addEventListener('click', function (event) {
        if (event.target.closest('[data-sheet-close]')) { event.preventDefault(); hide(); }
    });

    document.addEventListener('keydown', function (event) {
        if (!sheetOpen) { return; }

        if (event.key === 'Escape') {
            event.preventDefault();
            hide();
            return;
        }

        /* Tab stays inside the sheet while it is open. */
        if (event.key === 'Tab') {
            var items = Array.prototype.filter.call(
                panel.querySelectorAll('button:not([disabled]), input:not([disabled]), [href], [tabindex]:not([tabindex="-1"])'),
                function (el) { return el.offsetParent !== null || el === document.activeElement; }
            );
            if (!items.length) { return; }
            var first = items[0], last = items[items.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        }
    });

    /* Dragged down by its grip or its head: it follows the finger 1:1, and a
       release past a third of its height, or a flick, sends it away. Up, it
       resists. */
    (function () {
        var drag = null;

        function startDrag(event) {
            if (!sheetOpen || event.button > 0) { return; }
            if (event.target.closest('button, input, a')) { return; }
            drag = { y: event.clientY, dy: 0, t: performance.now(), v: 0, last: event.clientY, h: panel.offsetHeight || 1 };
            panel.classList.add('is-dragging');
            panel.setPointerCapture && panel.setPointerCapture(event.pointerId);
        }

        function moveDrag(event) {
            if (!drag) { return; }
            var now = performance.now();
            var dy = event.clientY - drag.y;
            drag.v = (event.clientY - drag.last) / Math.max(1, now - drag.t);
            drag.t = now;
            drag.last = event.clientY;
            drag.dy = dy > 0 ? dy : -Math.sqrt(-dy) * 2;   // a little give upwards, no more
            panel.style.transform = 'translate3d(0, ' + drag.dy + 'px, 0)';
        }

        function endDrag() {
            if (!drag) { return; }
            var d = drag;
            drag = null;
            panel.classList.remove('is-dragging');
            /* A finger that stopped before lifting carries no speed. */
            var v = performance.now() - d.t < 100 ? d.v : 0;
            if (d.dy > d.h / 3 || v > 0.55) { hide(); }
            else { panel.style.transform = ''; }
        }

        panel.addEventListener('pointerdown', function (event) {
            if (event.target.closest('[data-sheet-grab], .ch-sheet__head')) { startDrag(event); }
        });
        panel.addEventListener('pointermove', moveDrag);
        panel.addEventListener('pointerup', endDrag);
        panel.addEventListener('pointercancel', endDrag);
    }());

    /* ------------------------------------------------- refreshing the page */

    /** Reveals in swapped content are already in place: no second entrance. */
    function settle(scope) {
        Array.prototype.forEach.call(scope.querySelectorAll('.reveal'), function (el) { el.classList.add('is-visible'); });
        if (scope.classList && scope.classList.contains('reveal')) { scope.classList.add('is-visible'); }
    }

    /**
     * Reads the page again and swaps what shows challenges: Community's card,
     * Uitdagingen's list, each challenge's page (a new one adopted, a left
     * one taken away) and the sheets' data.
     */
    function refresh() {
        return fetch(window.location.href.split('#')[0], {
            credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'text/html' }
        })
            .then(function (response) { if (!response.ok) { throw new Error('page'); } return response.text(); })
            .then(function (html) {
                var fresh = new DOMParser().parseFromString(html, 'text/html');
                swap(fresh);
            })
            .catch(function () { /* what was saved is saved; the next load shows it */ });
    }

    function swapInner(selector, fresh) {
        var mine = document.querySelector(selector);
        var theirs = fresh.querySelector(selector);
        if (mine && theirs) {
            mine.innerHTML = theirs.innerHTML;
            settle(mine);
        }
    }

    function swap(fresh) {
        swapInner('[data-community-home]', fresh);
        swapInner('[data-challenges-list]', fresh);

        var freshData = fresh.querySelector('[data-challenges-data]');
        if (freshData) {
            dataEl.textContent = freshData.textContent;
            D = read();
            C = D.copy;
        }

        var stack = document.querySelector('[data-detail-stack]');
        if (!stack) { return; }

        var seen = {};
        Array.prototype.forEach.call(fresh.querySelectorAll('[data-challenge-detail]'), function (theirs) {
            var id = theirs.getAttribute('data-challenge-detail');
            var mine = stack.querySelector('[data-challenge-detail="' + id + '"]');
            seen[id] = true;

            if (mine) {
                var main = mine.querySelector('.app__main');
                var freshMain = theirs.querySelector('.app__main');
                if (main && freshMain) { main.innerHTML = freshMain.innerHTML; settle(main); }
                return;
            }

            var added = document.importNode(theirs, true);
            stack.appendChild(added);
            settle(added);
            if (nav.details) { nav.details.adopt(added); }
            var scroller = added.querySelector('[data-scroller]');
            if (scroller && window.AppChrome) { window.AppChrome.bind(scroller); }
        });

        Array.prototype.forEach.call(stack.querySelectorAll('[data-challenge-detail]'), function (detail) {
            if (seen[detail.getAttribute('data-challenge-detail')]) { return; }
            if (nav.details) { nav.details.forget(detail); }
            detail.parentNode.removeChild(detail);
        });
    }

    /* ----------------------------------------------- opening a challenge */

    /**
     * A challenge's page goes back to where it was opened from: Uitdagingen
     * when it opened over it, Community when its tile there was tapped.
     */
    function backTo(detail, from) {
        var pill = detail ? detail.querySelector('[data-back-list]') : null;
        if (!pill) { return; }
        var home = from === 'home';
        var label = pill.querySelector('.pill__label');
        if (label) { label.textContent = pill.getAttribute(home ? 'data-back-home' : 'data-back-list'); }
        pill.setAttribute('aria-label', pill.getAttribute(home ? 'data-back-home-aria' : 'data-back-list-aria'));
    }

    document.addEventListener('click', function (event) {
        var opener = event.target.closest('[data-detail-open^="challenge-"]');
        if (!opener) { return; }
        var detail = document.querySelector('[data-detail="' + opener.getAttribute('data-detail-open') + '"]');
        backTo(detail, opener.closest('[data-challenges-list]') ? 'list' : 'home');
    }, true);

    /** Opens a challenge's page from a sheet, once the page has it. */
    function openChallenge(id) {
        var key = 'challenge-' + id;
        var detail = document.querySelector('[data-detail="' + key + '"]');
        if (!detail || !nav.details) { return; }
        backTo(detail, nav.details.currentId() === 'challenges' ? 'list' : 'home');
        nav.details.open(key, null);
    }

    /* ------------------------------------------------------ Code invoeren */

    function codeInput(host, onComplete) {
        host.innerHTML = '<div class="ch-code">'
            + '<input class="ch-code__field" type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code"'
            + ' maxlength="5" spellcheck="false" autocapitalize="off" aria-label="' + esc(C.code.label) + '" aria-describedby="ch-code-hint" data-sheet-focus>'
            + '<span class="ch-code__boxes" aria-hidden="true">' + new Array(6).join('<span class="ch-code__box"></span>') + '</span></div>';

        var wrap = host.firstChild;
        var field = wrap.querySelector('input');
        var boxes = wrap.querySelectorAll('.ch-code__box');
        var done = '';

        function paint() {
            var value = field.value;
            Array.prototype.forEach.call(boxes, function (box, i) {
                box.textContent = value.charAt(i);
                box.classList.toggle('is-next', i === Math.min(value.length, 4));
            });
        }

        field.addEventListener('input', function () {
            var value = field.value.replace(/\D/g, '').slice(0, 5);
            if (value !== field.value) { field.value = value; }
            wrap.classList.remove('is-error');
            paint();
            if (value.length < 5) { done = ''; }
            /* Once per complete code: a repeated input event never looks it up twice. */
            if (value.length === 5 && value !== done) { done = value; onComplete(value); }
        });
        field.addEventListener('focus', function () { wrap.classList.add('is-focus'); });
        field.addEventListener('blur', function () { wrap.classList.remove('is-focus'); });
        paint();

        return {
            field: field,
            error: function () {
                done = '';
                wrap.classList.add('is-error');
                wrap.classList.remove('ch-shake');
                void wrap.offsetWidth;
                wrap.classList.add('ch-shake');
            }
        };
    }

    function codeSheet() {
        show(head(C.code.title)
            + '<div class="ch-sheet__body ch-codesheet">'
            + '<p class="ch-hint" id="ch-code-hint">' + esc(C.code.hint) + '</p>'
            + '<div class="ch-codesheet__boxes" data-code-host></div>'
            + '<p class="ch-code-msg" data-code-msg role="status" aria-live="polite"></p></div>', 'input');

        var msg = content.querySelector('[data-code-msg]');
        var input = codeInput(content.querySelector('[data-code-host]'), function (code) {
            msg.textContent = C.code.searching;
            msg.classList.remove('is-error');

            post('api/challenges/lookup.php', { code: code }).then(function (answer) {
                if (!sheetOpen) { return; }
                msg.textContent = '';

                if (answer.ok && answer.status === 'found') {
                    input.field.blur();
                    review(answer.review, { code: code });
                    return;
                }

                if (answer.ok && answer.status === 'member') {
                    hide();
                    toast(answer.message);
                    openChallenge(answer.id);
                    return;
                }

                msg.textContent = failed(answer);
                msg.classList.add('is-error');
                input.error();
            });
        });

        input.field.addEventListener('input', function () {
            if (input.field.value.length < 5) { msg.textContent = ''; msg.classList.remove('is-error'); }
        });
    }

    /* ----------------------------------------------------------- Bekijken */

    function reviewBody(r) {
        var facts = r.facts.map(function (fact) {
            return '<div class="ch-fact"><dt>' + esc(fact[0]) + '</dt><dd>' + esc(fact[1]) + '</dd></div>';
        }).join('');

        return '<div class="ch-review">'
            + '<div class="ch-review__head"><span class="icon-tile icon-tile--solid" data-accent="' + esc(r.accent) + '" aria-hidden="true">' + solid(r.icon) + '</span>'
            + '<div class="ch-review__heads"><p class="ch-review__name">' + esc(r.name) + '</p>'
            + (r.by ? '<p class="ch-review__by">' + esc(r.by) + '</p>' : '') + '</div></div>'
            + '<dl class="ch-facts">' + facts
            + '<div class="ch-fact"><dt>' + esc(r.people_label) + '</dt><dd>' + faces(r.faces) + '</dd></div></dl>'
            + '<p class="ch-privacy">' + icon('shield') + '<span>' + esc(r.privacy) + '</span></p>'
            + (r.late ? '<p class="ch-privacy">' + icon('calendar') + '<span>' + esc(r.late) + '</span></p>' : '')
            + '</div>';
    }

    /**
     * What a challenge is, before joining it. `via.code` when a code found
     * it; otherwise it is an invitation, which can also be turned down.
     */
    function review(r, via) {
        via = via || {};
        var invited = !via.code && r.invited;

        show(head(r.title)
            + '<div class="ch-sheet__body">' + reviewBody(r) + '</div>'
            + '<div class="ch-sheet__foot"><div class="ch-row-btns">'
            + (invited
                ? '<button type="button" class="btn press" data-review-decline>' + esc(C.review.decline) + '</button>'
                : '<button type="button" class="btn press" data-sheet-close>' + esc(C.review.later) + '</button>')
            + '<button type="button" class="btn ch-primary press" data-review-join data-sheet-focus>' + esc(C.review.join) + '</button>'
            + '</div></div>');

        var join = content.querySelector('[data-review-join]');
        var decline = content.querySelector('[data-review-decline]');

        join.addEventListener('click', function () {
            join.disabled = true;
            post('api/challenges/join.php', via.code ? { code: via.code } : { challenge_id: r.id }).then(function (answer) {
                if (!answer.ok) {
                    join.disabled = false;
                    toast(failed(answer));
                    return;
                }

                var id = answer.id;
                var ready = refresh();

                show('<div class="ch-sheet__body ch-done">'
                    + '<span class="ch-done__mark" aria-hidden="true">' + icon('check') + '</span>'
                    + '<h2 class="ch-sheet__title" id="ch-sheet-title">' + esc(C.review.joined_title) + '</h2>'
                    + '<p class="ch-hint">' + esc(r.joined) + '</p></div>'
                    + '<div class="ch-sheet__foot"><div class="ch-row-btns">'
                    + '<button type="button" class="btn press" data-sheet-close>' + esc(C.review.close) + '</button>'
                    + '<button type="button" class="btn ch-primary press" data-review-see data-sheet-focus>' + esc(C.review.see) + '</button>'
                    + '</div></div>');

                content.querySelector('[data-review-see]').addEventListener('click', function () {
                    hide();
                    ready.then(function () { openChallenge(id); });
                });
            });
        });

        if (decline) {
            decline.addEventListener('click', function () {
                decline.disabled = true;
                post('api/challenges/respond.php', { challenge_id: r.id, action: 'decline' }).then(function (answer) {
                    if (!answer.ok) { decline.disabled = false; toast(failed(answer)); return; }
                    hide();
                    toast(answer.message);
                    refresh();
                });
            });
        }
    }

    /* --------------------------------------------------- Nieuwe uitdaging */

    function metricOf(key) {
        for (var i = 0; i < D.metrics.length; i++) { if (D.metrics[i].key === key) { return D.metrics[i]; } }
        return null;
    }

    function lengthOf(key) {
        for (var i = 0; i < D.lengths.length; i++) { if (D.lengths[i].key === key) { return D.lengths[i]; } }
        return D.lengths[0];
    }

    function amount(metric, value) {
        return format(value === 1 ? metric.one : metric.many, T.number(value, 0));
    }

    function suggestName(draft) {
        var metric = metricOf(draft.metric);
        if (!metric) { return ''; }
        if (draft.kind === 'versus') { return metric.versus; }
        return format(lengthOf(draft.length).name_pattern, format(metric.many, T.number(draft.target, 0)));
    }

    function segmented(name, value, options, label) {
        return '<div class="range-switch range-switch--wide" role="group" aria-label="' + esc(label) + '">'
            + options.map(function (option) {
                var on = option.key === value;
                return '<button type="button" class="range-switch__option' + (on ? ' is-active' : '') + '" data-' + name + '="' + esc(option.key) + '"'
                    + ' aria-pressed="' + on + '">' + esc(option.label) + '</button>';
            }).join('') + '</div>';
    }

    function create(preset) {
        var draft = { kind: 'together', metric: null, target: 0, touched: false, length: '1w', from: 'today', name: '', invite: [] };
        var cc = C.create;

        /* A way to begin on an empty Uitdagingen comes with its kind and metric. */
        if (preset && (preset.kind === 'together' || preset.kind === 'versus')) { draft.kind = preset.kind; }
        if (preset && preset.metric && metricOf(preset.metric)) {
            draft.metric = preset.metric;
            draft.target = metricOf(draft.metric).targets[draft.length];
        }

        function preview() {
            var metric = metricOf(draft.metric);
            var period = lengthOf(draft.length).periods[draft.from];
            var kind = draft.kind === 'versus' ? D.kinds[1] : D.kinds[0];
            var name = draft.name.trim() || (metric ? suggestName(draft) : cc.preview_name);
            var line = !metric ? cc.preview_empty
                : draft.kind === 'together' ? format(D.preview.together, amount(metric, draft.target))
                : format(D.preview.versus, metric.what);
            var invited = draft.invite.length;
            var people = D.friends.filter(function (f) { return draft.invite.indexOf(f.id) !== -1; });

            return '<div class="ch-preview" data-accent="' + esc(metric ? metric.accent : 'health') + '">'
                + '<span class="ch-kind">' + icon(metric ? metric.icon : 'target') + '<span>' + esc(kind.label + (metric ? ' · ' + metric.label : '')) + '</span></span>'
                + '<span class="ch-preview__name">' + esc(name) + '</span>'
                + '<span class="ch-preview__line">' + esc(line + ' · ' + period) + '</span>'
                + '<span class="ch-foot"><span class="ch-faces" aria-hidden="true">'
                + avatar(D.you || { you: true, initial: '', accent: 'health' })
                + people.slice(0, 4).map(function (p) { return avatar(p, 0, true); }).join('')
                + '</span><span>' + esc(invited ? T.tn(invited, '%d uitgenodigd', '%d uitgenodigd') : D.preview.alone) + '</span></span></div>';
        }

        function friendsGrid() {
            if (!D.friends.length) { return '<p class="ch-hint">' + esc(cc.no_friends) + '</p>'; }
            return '<div class="ch-pick">' + D.friends.map(function (f) {
                var on = draft.invite.indexOf(f.id) !== -1;
                return '<button type="button" class="ch-pick__person press" data-pick="' + f.id + '" aria-pressed="' + on + '">'
                    + avatar(f, 44) + (on ? '<span class="ch-pick__check" aria-hidden="true">' + icon('check') + '</span>' : '')
                    + '<span class="ch-pick__name">' + esc(f.name) + '</span></button>';
            }).join('') + '</div>';
        }

        function paint() {
            var metric = metricOf(draft.metric);
            var kindHint = draft.kind === 'versus' ? D.kinds[1].hint : D.kinds[0].hint;

            var html = head(cc.title)
                + '<div class="ch-sheet__body ch-create">'
                + '<div class="ch-preview-host" data-preview aria-live="polite">' + preview() + '</div>'

                + '<p class="ch-label">' + esc(cc.kind_label) + '</p>'
                + segmented('kind', draft.kind, D.kinds, cc.kind_label)
                + '<p class="ch-hint">' + esc(kindHint) + '</p>'

                + '<p class="ch-label">' + esc(cc.metric) + '</p>'
                + '<div class="ch-chips" role="group" aria-label="' + esc(cc.metric) + '">' + D.metrics.map(function (m) {
                    var on = draft.metric === m.key;
                    return '<button type="button" class="ch-chip press" data-metric="' + esc(m.key) + '" data-accent="' + esc(m.accent) + '" aria-pressed="' + on + '">'
                        + icon(m.icon) + '<span>' + esc(m.label) + '</span></button>';
                }).join('') + '</div>'

                + (draft.kind === 'together' && metric
                    ? '<p class="ch-label">' + esc(cc.target) + '</p>'
                        + '<div class="ch-stepper">'
                        + '<button type="button" class="btn press ch-icon-btn" data-step="-1" aria-label="' + esc(cc.less) + '"' + (draft.target <= metric.step ? ' disabled' : '') + '>−</button>'
                        + '<span class="ch-stepper__value" aria-live="polite"><b>' + esc(T.number(draft.target, 0)) + '</b> '
                        + esc(format(metric.many, '').trim()) + '</span>'
                        + '<button type="button" class="btn press ch-icon-btn" data-step="1" aria-label="' + esc(cc.more) + '">+</button></div>'
                    : '')

                + '<p class="ch-label">' + esc(cc.length) + '</p>'
                + segmented('length', draft.length, D.lengths, cc.length)
                + '<div class="ch-gap">' + segmented('from', draft.from, D.from, cc.length) + '</div>'

                + '<p class="ch-label"><label for="ch-name">' + esc(cc.name) + '</label></p>'
                + '<input class="ch-input" id="ch-name" type="text" maxlength="60" autocomplete="off" enterkeyhint="done"'
                + ' placeholder="' + esc(metric ? suggestName(draft) : cc.name_example) + '" value="' + esc(draft.name) + '" data-name>'

                + '<p class="ch-label">' + esc(cc.friends) + '</p>'
                + '<p class="ch-hint ch-hint--tight">' + esc(cc.friends_hint) + '</p>'
                + friendsGrid()
                + '</div>'

                + '<div class="ch-sheet__foot">'
                + '<button type="button" class="btn ch-primary press ch-wide" data-make' + (metric ? '' : ' disabled') + '>'
                + esc(metric ? cc.make : cc.pick_first) + '</button></div>';

            var body = content.querySelector('.ch-sheet__body');
            var y = body ? body.scrollTop : 0;
            var active = document.activeElement;
            var activeKey = active && active.closest && active.closest('.ch-sheet__body')
                ? (active.getAttribute('data-metric') || active.getAttribute('data-kind') || active.getAttribute('data-length')
                    || active.getAttribute('data-from') || active.getAttribute('data-pick') || active.getAttribute('data-step'))
                : null;

            if (!sheetOpen) { show(html); } else { content.innerHTML = html; }

            body = content.querySelector('.ch-sheet__body');
            if (body) { body.scrollTop = y; }

            /* The control that was tapped keeps the focus across the repaint. */
            if (activeKey !== null) {
                var again = content.querySelector('[data-metric="' + activeKey + '"], [data-kind="' + activeKey + '"], [data-length="' + activeKey + '"],'
                    + ' [data-from="' + activeKey + '"], [data-pick="' + activeKey + '"], [data-step="' + activeKey + '"]');
                if (again && !again.disabled) { again.focus({ preventScroll: true }); }
            }

            var name = content.querySelector('[data-name]');
            name.addEventListener('input', function () {
                draft.name = name.value;
                content.querySelector('[data-preview]').innerHTML = preview();
            });
        }

        paint();

        content.onclick = function (event) {
            var t = event.target.closest('button');
            if (!t || !content.contains(t) || t.disabled) { return; }

            if (t.hasAttribute('data-kind')) { draft.kind = t.getAttribute('data-kind'); paint(); return; }
            if (t.hasAttribute('data-metric')) {
                draft.metric = t.getAttribute('data-metric');
                if (!draft.touched) { draft.target = metricOf(draft.metric).targets[draft.length]; }
                paint();
                return;
            }
            if (t.hasAttribute('data-step')) {
                var metric = metricOf(draft.metric);
                draft.target = Math.max(metric.step, draft.target + Number(t.getAttribute('data-step')) * metric.step);
                draft.touched = true;
                paint();
                return;
            }
            if (t.hasAttribute('data-length')) {
                draft.length = t.getAttribute('data-length');
                if (draft.metric && !draft.touched) { draft.target = metricOf(draft.metric).targets[draft.length]; }
                paint();
                return;
            }
            if (t.hasAttribute('data-from')) { draft.from = t.getAttribute('data-from'); paint(); return; }
            if (t.hasAttribute('data-pick')) {
                var id = Number(t.getAttribute('data-pick'));
                var at = draft.invite.indexOf(id);
                if (at === -1) { draft.invite.push(id); } else { draft.invite.splice(at, 1); }
                paint();
                return;
            }
            if (t.hasAttribute('data-make')) { make(t); }
        };

        function make(button) {
            button.disabled = true;
            post('api/challenges/create.php', {
                kind: draft.kind,
                metric: draft.metric,
                target: draft.kind === 'together' ? draft.target : null,
                target_unit: D.units === 'imperial' ? 'mi' : 'km',
                length: draft.length,
                from: draft.from,
                name: draft.name.trim(),
                invite: draft.invite
            }).then(function (answer) {
                if (!answer.ok) {
                    button.disabled = false;
                    toast(failed(answer));
                    return;
                }

                content.onclick = null;
                made(answer);
            });
        }
    }

    /** The new challenge's code, to copy or share — and the way to it. */
    function made(answer) {
        var cc = C.create;
        var ready = refresh();
        var digits = String(answer.code).split('');

        show('<div class="ch-sheet__body ch-done">'
            + '<span class="ch-done__mark" aria-hidden="true">' + icon('check') + '</span>'
            + '<h2 class="ch-sheet__title" id="ch-sheet-title">' + esc(cc.made_title) + '</h2>'
            + '<p class="ch-hint">' + esc(answer.name) + (answer.invited ? ' · ' + esc(T.tn(answer.invited, '%d uitgenodigd', '%d uitgenodigd')) : '') + '</p>'
            + '<div class="ch-ticket">'
            + '<span class="ch-ticket__label">' + esc(cc.made_code) + '</span>'
            + '<span class="ch-ticket__code" aria-label="' + esc(digits.join(' ')) + '">'
            + digits.map(function (d) { return '<span aria-hidden="true">' + esc(d) + '</span>'; }).join('') + '</span>'
            + '<span class="ch-ticket__hint">' + esc(cc.made_hint) + '</span></div>'
            + '<div class="ch-row-btns">'
            + '<button type="button" class="btn press" data-made-copy>' + icon('copy') + '<span>' + esc(cc.copy) + '</span></button>'
            + '<button type="button" class="btn press" data-made-share>' + icon('share') + '<span>' + esc(cc.share) + '</span></button>'
            + '</div></div>'
            + '<div class="ch-sheet__foot"><button type="button" class="btn ch-primary press ch-wide" data-made-open data-sheet-focus>' + esc(cc.open) + '</button></div>');

        content.querySelector('[data-made-copy]').addEventListener('click', function () { copy(answer.code); });
        content.querySelector('[data-made-share]').addEventListener('click', function () { share(answer.share_text, answer.code); });
        content.querySelector('[data-made-open]').addEventListener('click', function () {
            hide();
            ready.then(function () { openChallenge(answer.id); });
        });
    }

    /* ------------------------------------------------ Vrienden uitnodigen */

    function invite(id) {
        var detail = document.querySelector('[data-challenge-detail="' + id + '"]');
        var source = detail ? detail.querySelector('[data-challenge-invitable]') : null;
        var friends = [];
        try { friends = JSON.parse(source ? source.textContent : '[]') || []; } catch (e) { friends = []; }

        var chosen = [];
        var ci = C.invite;

        function paint() {
            var html = head(ci.title)
                + '<div class="ch-sheet__body">'
                + '<p class="ch-hint ch-hint--top">' + esc(ci.body) + '</p>'
                + (friends.length
                    ? '<ul class="ch-friends" role="list">' + friends.map(function (f) {
                        var on = chosen.indexOf(f.id) !== -1;
                        return '<li class="ch-friend">' + avatar(f, 36)
                            + '<span class="ch-friend__name">' + esc(f.name) + '</span>'
                            + '<button type="button" class="btn press ch-friend__pick' + (on ? ' is-on' : '') + '" data-invite-pick="' + f.id + '" aria-pressed="' + on + '">'
                            + (on ? icon('check') + '<span>' + esc(ci.picked) + '</span>' : '<span>' + esc(ci.pick) + '</span>') + '</button></li>';
                    }).join('') + '</ul>'
                    : '<p class="ch-note">' + esc(ci.none) + '</p>')
                + '</div>'
                + '<div class="ch-sheet__foot"><div class="ch-row-btns">'
                + '<button type="button" class="btn press" data-sheet-close>' + esc(ci.cancel) + '</button>'
                + '<button type="button" class="btn ch-primary press" data-invite-send' + (chosen.length ? '' : ' disabled') + '>'
                + esc(chosen.length > 1 ? T.tf('%d uitnodigen', chosen.length) : ci.send) + '</button></div></div>';

            var focusKey = document.activeElement && document.activeElement.getAttribute
                ? document.activeElement.getAttribute('data-invite-pick') : null;
            if (!sheetOpen) { show(html); } else { content.innerHTML = html; }
            if (focusKey) {
                var again = content.querySelector('[data-invite-pick="' + focusKey + '"]');
                if (again) { again.focus({ preventScroll: true }); }
            }
        }

        paint();

        content.onclick = function (event) {
            var pick = event.target.closest('[data-invite-pick]');
            if (pick) {
                var fid = Number(pick.getAttribute('data-invite-pick'));
                var at = chosen.indexOf(fid);
                if (at === -1) { chosen.push(fid); } else { chosen.splice(at, 1); }
                paint();
                return;
            }

            var send = event.target.closest('[data-invite-send]');
            if (send && !send.disabled) {
                send.disabled = true;
                post('api/challenges/invite.php', { challenge_id: id, invite: chosen }).then(function (answer) {
                    if (!answer.ok) { send.disabled = false; toast(failed(answer)); return; }
                    content.onclick = null;
                    hide();
                    toast(answer.message);
                    refresh();
                });
            }
        };
    }

    /* ------------------------------------------------- Uitdaging verlaten */

    function leave(id) {
        var cd = C.detail;

        show(head(cd.leave_title)
            + '<div class="ch-sheet__body"><p class="ch-hint ch-hint--top">' + esc(cd.leave_body) + '</p></div>'
            + '<div class="ch-sheet__foot"><div class="ch-row-btns">'
            + '<button type="button" class="btn press" data-sheet-close data-sheet-focus>' + esc(cd.cancel) + '</button>'
            + '<button type="button" class="btn ch-danger press" data-leave-yes>' + esc(cd.leave_confirm) + '</button>'
            + '</div></div>');

        var yes = content.querySelector('[data-leave-yes]');
        yes.addEventListener('click', function () {
            yes.disabled = true;
            post('api/challenges/respond.php', { challenge_id: id, action: 'leave' }).then(function (answer) {
                if (!answer.ok) { yes.disabled = false; toast(failed(answer)); return; }
                hide();
                toast(answer.message);
                if (nav.details && nav.details.currentId() === 'challenge-' + id) { nav.details.close(true); }
                /* The page leaves once it has slid away. */
                window.setTimeout(refresh, (nav.duration || 280) + 80);
            });
        });
    }

    /* ------------------------------------------------- copying and sharing */

    function copy(code) {
        var text = String(code);
        var done = function () { toast(T.tf('Code %s gekopieerd', text)); };

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done, function () { fallback(text); done(); });
        } else {
            fallback(text);
            done();
        }
    }

    function fallback(text) {
        var area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        try { document.execCommand('copy'); } catch (e) { /* nothing more to try */ }
        document.body.removeChild(area);
    }

    function share(text, code) {
        if (navigator.share) {
            navigator.share({ text: text }).catch(function () { /* closed: nothing to say */ });
        } else {
            copy(code);
        }
    }

    /* -------------------------------------------------------------- wiring */

    document.addEventListener('click', function (event) {
        var t = event.target.closest('[data-challenge-create], [data-challenge-join], [data-challenge-review],'
            + ' [data-challenge-copy], [data-challenge-share], [data-challenge-invite], [data-challenge-leave]');
        if (!t || sheetEl.contains(t)) { return; }

        /* Read afresh: another script may have swapped it (community.js). */
        D = read();
        C = D.copy;

        if (t.hasAttribute('data-challenge-create')) {
            create({ kind: t.getAttribute('data-kind'), metric: t.getAttribute('data-metric') });
            return;
        }
        if (t.hasAttribute('data-challenge-join')) { codeSheet(); return; }
        if (t.hasAttribute('data-challenge-review')) {
            var r = D.reviews && D.reviews[t.getAttribute('data-challenge-review')];
            if (r) { review(r, {}); }
            return;
        }
        if (t.hasAttribute('data-challenge-copy')) { copy(t.getAttribute('data-challenge-copy')); return; }
        if (t.hasAttribute('data-challenge-share')) { share(t.getAttribute('data-share-text'), t.getAttribute('data-challenge-share')); return; }
        if (t.hasAttribute('data-challenge-invite')) { invite(Number(t.getAttribute('data-challenge-invite'))); return; }
        if (t.hasAttribute('data-challenge-leave')) { leave(Number(t.getAttribute('data-challenge-leave'))); }
    });

    /* For the tests, and nothing else. */
    window.AppChallenges = { refresh: refresh, open: openChallenge };
}());
