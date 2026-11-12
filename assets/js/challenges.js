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

    /* -------------------------------------------------------------- toast */

    /* The one toast (`.ch-toast`, index.php): what an action did, for a
       moment — anyone's to use (settings.js after a save, 50.0), as the
       app's OwnifyToast. Ready even when challenges are not. */
    var toastEl = document.querySelector('[data-challenge-toast]');
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
    window.OwnifyToast = { show: toast };

    var sheetEl = document.querySelector('[data-challenge-sheet]');
    var dataEl = document.querySelector('[data-challenges-data]');
    if (!sheetEl || !dataEl) { return; }

    var T = window.OwnifyI18n;
    var nav = window.AppNav || {};
    var panel = sheetEl.querySelector('[data-sheet-panel]');
    var content = sheetEl.querySelector('[data-sheet-content]');
    var grab = sheetEl.querySelector('[data-sheet-grab]');
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

    /* -------------------------------------------------------------- sheet */

    var sheetOpen = false;
    var closeTimer = null;
    var lastFocus = null;
    var onClosed = null;

    function head(title) {
        return '<div class="ch-sheet__head">'
            + '<h2 class="ch-sheet__title" id="ch-sheet-title">' + esc(title) + '</h2>'
            + '<button type="button" class="ch-sheet__close press" data-sheet-close aria-label="' + esc(C.review.close) + '">'
            + icon('close') + '</button></div>';
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
            /* Where the sheet asks for it; otherwise the sheet itself (50.0). */
            var target = (focusSelector && content.querySelector(focusSelector))
                || content.querySelector('[data-sheet-focus]');
            if (target && target.focus) { target.focus({ preventScroll: true }); }
            else if (window.AppNav && window.AppNav.focusLayer) { window.AppNav.focusLayer(panel); }
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
                if (window.OwnifyNotices) { window.OwnifyNotices.read(fresh); }
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
            + '<div class="ch-sheet__foot"><div class="ch-row-btns split" role="group">'
            + (invited
                ? '<button type="button" class="split__part" data-review-decline>' + esc(C.review.decline) + '</button>'
                : '<button type="button" class="split__part" data-sheet-close>' + esc(C.review.later) + '</button>')
            + '<button type="button" class="split__part" data-review-join data-sheet-focus>' + esc(C.review.join) + '</button>'
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
                /* An answered invitation: its dot goes now. */
                if (window.OwnifyNotices && r.id) { window.OwnifyNotices.done('invite:' + r.id); }
                var ready = refresh();

                show('<div class="ch-sheet__body ch-done">'
                    + '<span class="ch-done__mark" aria-hidden="true">' + icon('check') + '</span>'
                    + '<h2 class="ch-sheet__title" id="ch-sheet-title">' + esc(C.review.joined_title) + '</h2>'
                    + '<p class="ch-hint">' + esc(r.joined) + '</p></div>'
                    + '<div class="ch-sheet__foot"><div class="ch-row-btns split" role="group">'
                    + '<button type="button" class="split__part" data-sheet-close>' + esc(C.review.close) + '</button>'
                    + '<button type="button" class="split__part" data-review-see data-sheet-focus>' + esc(C.review.see) + '</button>'
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
                    if (window.OwnifyNotices) { window.OwnifyNotices.done('invite:' + r.id); }
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

    /** Litres, km and hours keep a decimal; the rest is whole. */
    function decimalsOf(metric) { return metric.unit || metric.key === 'sleep_hours' ? 1 : 0; }

    function amount(metric, value) {
        var text = decimalsOf(metric) ? T.numberTrim(value, 1) : T.number(value, 0);
        return format(value === 1 ? metric.one : metric.many, text);
    }

    /* ------------------------------------------------ dates (49.1) */
    /* The goal wizard's Periode (assets/js/goal-wizard.js): Start → Einde
       on Ownify's own calendar, or a looptijd. */

    function day(d) { var x = new Date(d.getFullYear(), d.getMonth(), d.getDate()); x.setHours(0, 0, 0, 0); return x; }
    function dayAfter(d, n) { return day(new Date(d.getFullYear(), d.getMonth(), d.getDate() + n)); }
    function addMonths(d, months) {
        var index = d.getFullYear() * 12 + d.getMonth() + months;
        var year = Math.floor(index / 12), month = index - year * 12;
        return new Date(year, month, Math.min(d.getDate(), new Date(year, month + 1, 0).getDate()));
    }
    function parseDay(text) { var p = String(text).split('-'); return new Date(Number(p[0]), Number(p[1]) - 1, Number(p[2])); }
    function isoDay(d) {
        var m = d.getMonth() + 1, x = d.getDate();
        return d.getFullYear() + '-' + (m < 10 ? '0' : '') + m + '-' + (x < 10 ? '0' : '') + x;
    }
    function sameDay(a, b) { return !!a && !!b && isoDay(a) === isoDay(b); }
    function spanDays(a, b) { return Math.round((Date.UTC(b.getFullYear(), b.getMonth(), b.getDate()) - Date.UTC(a.getFullYear(), a.getMonth(), a.getDate())) / 86400000) + 1; }
    function durationEnd(start, key) {
        var span = null;
        (D.durations || []).forEach(function (d) { if (d.key === key) { span = d; } });
        if (!span) { return null; }
        return dayAfter(span.months ? addMonths(start, span.months) : dayAfter(start, span.days || 0), -1);
    }
    function durationOf(start, end) {
        var found = null;
        (D.durations || []).forEach(function (d) { if (!found && sameDay(durationEnd(start, d.key), end)) { found = d.key; } });
        return found;
    }
    function todayDay() { return D.today ? parseDay(D.today) : day(new Date()); }
    function dayText(d) { return d.getFullYear() === todayDay().getFullYear() ? T.date(d, 'day_mon') : T.date(d, 'day_mon_year'); }
    function periodText(draft) { return dayText(draft.start) + ' – ' + dayText(draft.end); }

    function suggestTarget(draft) {
        var m = metricOf(draft.metric);
        if (!m) { return 0; }
        var weeks = spanDays(draft.start, draft.end) / 7;
        return Math.max(m.step, Math.round(m.per_week * weeks / m.step) * m.step);
    }

    function suggestName(draft) {
        var metric = metricOf(draft.metric);
        if (!metric) { return ''; }
        if (draft.kind === 'versus') { return metric.versus; }
        var p = D.name_patterns || {};
        var days = spanDays(draft.start, draft.end);
        var pattern = p[durationOf(draft.start, draft.end) || (days === 14 ? '2w' : '')] || String(p.days || '%s').replace('%d', String(days));
        return format(pattern, format(metric.many, decimalsOf(metric) ? T.numberTrim(draft.target, 1) : T.number(draft.target, 0)));
    }

    /**
     * Nieuwe uitdaging (49.0): the goal wizard's steps and look
     * (components/goal-wizard.php) for a group — 1 Wat, 2 Soort, 3 Instellen
     * in the indicator; Controleren and Uitnodigen after them, outside it,
     * as the goal wizard's review is. Each screen ends in its back button and
     * the one way forward; back keeps every answer. Sent as before
     * (api/challenges/create.php), then the code sheet (made()).
     */
    var wiz = null;

    function wizardShell() {
        if (wiz) { return wiz; }
        var cc = C.create;
        var root = document.createElement('div');
        root.className = 'wizard goal-wizard challenge-wizard';
        root.setAttribute('data-overlay', '');
        root.hidden = true;
        root.innerHTML = '<div class="wizard__scrim" data-cw-close></div>'
            + '<div class="wizard__panel card" role="dialog" aria-modal="true" aria-labelledby="cw-title">'
            + '<div class="wizard__head"><h2 class="card__eyebrow" id="cw-title">' + esc(cc.title) + '</h2>'
            + '<button type="button" class="account__close press" data-cw-close aria-label="' + esc(cc.close) + '">' + icon('close') + '</button></div>'
            + '<div class="wizard__progress"><ol class="wizard__bars" role="list" aria-hidden="true">'
            + '<li class="wizard__bar" data-cw-bar="1"></li><li class="wizard__bar" data-cw-bar="2"></li><li class="wizard__bar" data-cw-bar="3"></li></ol>'
            + '<p class="wizard__count" data-cw-count aria-live="polite"></p></div>'
            + '<div class="wizard__body" data-cw-body></div>'
            + '<div class="wizard__foot goal-wizard__foot"><div class="goal-wizard__nav">'
            + '<button type="button" class="glass glass--round press" data-cw-back aria-label="' + esc(cc.back) + '">' + icon('chevron-left') + '</button>'
            + '<button type="button" class="glass glass--cta goal-wizard__next press" data-cw-next><span class="goal-wizard__spinner" aria-hidden="true"></span><span data-cw-next-label></span></button>'
            + '</div></div></div>';
        document.body.appendChild(root);
        wiz = { root: root, body: root.querySelector('[data-cw-body]'), count: root.querySelector('[data-cw-count]'),
            next: root.querySelector('[data-cw-next]'), label: root.querySelector('[data-cw-next-label]') };
        return wiz;
    }

    function create(preset) {
        var cc = C.create;
        var w = wizardShell();
        var start = todayDay();
        var draft = { step: 1, category: null, find: '', kind: 'together', metric: null, target: 0, touched: false,
            start: start, end: durationEnd(start, 'week') || dayAfter(start, 6), duration: 'week',
            name: '', invite: [], query: '' };
        var saving = false;
        var STEPS = 3;

        if (preset && (preset.kind === 'together' || preset.kind === 'versus')) { draft.kind = preset.kind; }
        if (preset && preset.metric && metricOf(preset.metric)) {
            draft.metric = preset.metric;
            draft.target = suggestTarget(draft);
        }

        var categoryLabel = function (key) {
            var label = key;
            (D.categories || []).forEach(function (c) { if (c.key === key) { label = c.label; } });
            return label;
        };
        var head = function (n) { return '<h3 class="wizard__title" tabindex="-1">' + esc(cc['step' + n + '_title'] || n) + '</h3><p class="wizard__lede">' + esc(cc['step' + n + '_lede'] || '') + '</p>'; };
        var fold = function (text) { return String(text || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); };

        function row(attrs, accent, mark, label, meta, chosen, role) {
            return '<button type="button" class="goal-option press" role="' + role + '" aria-checked="' + chosen + '" ' + attrs
                + (accent ? ' data-accent="' + esc(accent) + '"' : '') + '>'
                + '<span class="goal-option__icon" aria-hidden="true">' + mark + '</span>'
                + '<span class="goal-option__text"><span class="goal-option__label">' + esc(label) + '</span>'
                + '<span class="goal-option__meta">' + esc(meta) + '</span></span></button>';
        }

        function search(attr, value, placeholder) {
            return '<div class="goal-search">' + icon('search').replace('<svg', '<svg class="goal-search__icon"')
                + '<input class="goal-search__input" type="search" ' + attr + ' enterkeyhint="search" autocomplete="off" spellcheck="false" placeholder="'
                + esc(placeholder) + '" aria-label="' + esc(placeholder) + '" value="' + esc(value) + '"></div>';
        }

        function metricList() {
            var words = fold(draft.find).split(/\s+/).filter(Boolean);
            var shown = D.metrics.filter(function (m) {
                if (draft.category && m.category !== draft.category) { return false; }
                var hay = fold(m.label + ' ' + m.what + ' ' + categoryLabel(m.category));
                return words.every(function (word) { return hay.indexOf(word) !== -1; });
            });
            if (!shown.length) { return '<p class="wizard__hint goal-list__empty">' + esc(cc.no_metric) + '</p>'; }
            return '<div class="glass goal-list"><div class="goal-list__scroll" role="radiogroup" aria-label="' + esc(cc.metric) + '">'
                + shown.map(function (m) {
                    return row('data-cw-metric="' + esc(m.key) + '"', m.accent, icon(m.icon), m.label, categoryLabel(m.category), draft.metric === m.key, 'radio');
                }).join('') + '</div></div>';
        }

        function stepWhat() {
            return head(1)
                + search('data-cw-find', draft.find, cc.find)
                + '<div class="goal-bubbles" role="group">' + (D.categories || []).map(function (c) {
                    return '<button type="button" class="glass glass--chip goal-bubble press" data-cw-category="' + esc(c.key) + '" aria-pressed="' + (draft.category === c.key) + '">' + esc(c.label) + '</button>';
                }).join('') + '</div>'
                /* Where they come from, said once here — not on every row (50.0). */
                + '<p class="wizard__label goal-list__label">' + esc(cc.list_label + ' · ' + cc.auto) + '</p>'
                + '<div data-cw-list>' + metricList() + '</div>';
        }

        function stepKind() {
            var metric = metricOf(draft.metric);
            return head(2)
                + '<label class="wizard__label" for="cw-name">' + esc(cc.name) + '</label>'
                + '<input class="wizard__input" type="text" id="cw-name" maxlength="60" autocomplete="off" enterkeyhint="done" data-cw-name'
                + ' placeholder="' + esc(suggestName(draft)) + '" value="' + esc(draft.name) + '">'
                + '<div class="goal-types ch-gap" role="radiogroup">' + D.kinds.map(function (k) {
                    var example = k.key === 'versus' ? metric.versus : format(D.preview.together, amount(metric, suggestTarget(draft)));
                    return '<button type="button" class="glass goal-type press" role="radio" aria-checked="' + (draft.kind === k.key) + '" data-cw-kind="' + esc(k.key) + '">'
                        + '<span class="goal-type__label">' + esc(k.label) + '</span><span class="goal-type__hint">' + esc(k.hint) + '</span>'
                        + '<span class="goal-type__example">' + esc(example) + '</span></button>';
                }).join('') + '</div>';

        }

        /* Start | Einde: the split button (50.0); each half turns the date wheel (wired in paint). */
        function dateButton(which, label) {
            return '<label class="split__part goal-date"><span class="goal-date__label">' + esc(label) + '</span>'
                + '<input class="goal-date__value" type="text" data-cw-date="' + which + '" aria-label="' + esc(label) + '"></label>';
        }

        function stepSetup() {
            var metric = metricOf(draft.metric);
            var html = head(3);
            if (draft.kind === 'together') {
                html += '<label class="wizard__label" for="cw-target">' + esc(cc.target) + '</label>'
                    + '<div class="goal-row"><input class="wizard__input goal-row__number" type="text" id="cw-target" data-cw-target value="'
                    + esc(decimalsOf(metric) ? T.numberTrim(draft.target, 1, false) : T.number(draft.target, 0, false)) + '">'
                    + '<span class="goal-row__word">' + esc(format(metric.many, '').trim()) + '</span></div>';
            }
            return html
                + '<div class="goal-field goal-dates-field"><p class="wizard__label' + (draft.kind === 'together' ? ' wizard__label--spaced' : '') + '">' + esc(cc.dates_label) + '</p>'
                + '<div class="goal-dates split" role="group" aria-label="' + esc(cc.dates_label) + '">' + dateButton('start', cc.start)
                + dateButton('end', cc.end) + '</div>'
                + '<div class="goal-bubbles goal-durations" role="group" aria-label="' + esc(cc.durations_label) + '">'
                + (D.durations || []).map(function (d) {
                    return '<button type="button" class="glass glass--chip goal-bubble press" data-cw-duration="' + esc(d.key) + '" aria-pressed="' + (draft.duration === d.key) + '">' + esc(d.label) + '</button>';
                }).join('') + '</div></div>';
        }

        /** Where each date may go: the start within the next weeks, the end within the longest run. */
        function dateSpec(which) {
            var now = todayDay();
            var limits = D.dates || { start_within: 90, longest: 366 };
            if (which === 'start') { return { kind: 'date', min: isoDay(now), max: isoDay(dayAfter(now, limits.start_within)) }; }
            return { kind: 'date', min: isoDay(dayAfter(draft.start, 1)), max: isoDay(dayAfter(draft.start, limits.longest - 1)) };
        }

        function pickDay(which, text) {
            if (!text) { return; }
            var d = parseDay(text);
            if (which === 'start') {
                draft.start = d;
                var kept = draft.duration && durationEnd(d, draft.duration);
                if (kept) { draft.end = kept; } else if (draft.end <= d) { draft.end = dayAfter(d, 6); draft.duration = durationOf(d, draft.end); }
            } else {
                draft.end = d;
                draft.duration = durationOf(draft.start, d);
            }
            datesMoved();
            paint(0);
        }

        function datesMoved() {
            if (!draft.touched && draft.metric) { draft.target = suggestTarget(draft); }
        }

        function stepReview() {
            var metric = metricOf(draft.metric);
            var kind = draft.kind === 'versus' ? D.kinds[1] : D.kinds[0];
            var rows = [[cc.step1_label, metric.label], [cc.kind_label, kind.label], [cc.name, draft.name.trim() || suggestName(draft)]];
            if (draft.kind === 'together') { rows.push([cc.target, amount(metric, draft.target)]); }
            rows.push([cc.period, periodText(draft)]);
            return '<h3 class="wizard__title" tabindex="-1">' + esc(cc.review_title) + '</h3><p class="wizard__lede">' + esc(cc.review_lede) + '</p>'
                + '<dl class="glass goal-review">' + rows.map(function (r) {
                    return '<div class="goal-review__row"><dt>' + esc(r[0]) + '</dt><dd>' + esc(r[1]) + '</dd></div>';
                }).join('') + '</dl>';
        }

        function stepInvite() {
            var q = fold(draft.query.trim());
            var shown = D.friends.filter(function (f) { return !q || fold(f.name).indexOf(q) !== -1; });
            var list = !D.friends.length ? '<p class="wizard__hint">' + esc(cc.no_friends) + '</p>'
                : (shown.length
                    ? '<div class="glass goal-list"><div class="goal-list__scroll" role="group" aria-label="' + esc(cc.friends) + '">'
                        + shown.map(function (f) {
                            var on = draft.invite.indexOf(f.id) !== -1;
                            return row('data-cw-friend="' + f.id + '"', null, avatar(f, 30), f.name, on ? C.invite.picked : C.invite.pick, on, 'checkbox');
                        }).join('') + '</div></div>'
                    : '<p class="wizard__hint goal-list__empty">' + esc(cc.no_match) + '</p>');
            return '<h3 class="wizard__title" tabindex="-1">' + esc(cc.invite_title) + '</h3><p class="wizard__lede">' + esc(cc.friends_hint) + '</p>'
                + (D.friends.length ? search('data-cw-search', draft.query, cc.search) : '')
                /* A count is a label; the sentence for "nobody yet" is a hint, in sentence case (50.0). */
                + '<p class="' + (draft.invite.length ? 'wizard__label' : 'wizard__hint') + ' goal-list__label" aria-live="polite">' + esc(draft.invite.length ? String(cc.picked).replace('%d', draft.invite.length) : cc.none_picked) + '</p>'
                + list;
        }

        function complete() {
            if (draft.step === 1) { return !!draft.metric; }
            if (draft.step === 3) { return (draft.kind !== 'together' || draft.target > 0) && draft.end > draft.start; }
            return true;
        }

        function paint(direction) {
            var s = draft.step;
            w.body.innerHTML = '<section class="wizard__step is-active">'
                + (s === 1 ? stepWhat() : s === 2 ? stepKind() : s === 3 ? stepSetup() : s === 'review' ? stepReview() : stepInvite()) + '</section>';
            if (window.OwnifyWheel) {
                Array.prototype.forEach.call(w.body.querySelectorAll('[data-cw-date]'), function (input) {
                    var which = input.getAttribute('data-cw-date');
                    input._wheelAnchor = input.closest('.goal-date');
                    window.OwnifyWheel.attach(input, dateSpec(which));
                    window.OwnifyWheel.set(input, isoDay(which === 'start' ? draft.start : draft.end));
                    input.addEventListener('change', function () { pickDay(which, window.OwnifyWheel.value(input)); });
                });
            }
            var reached = typeof s === 'number' ? s : STEPS;
            Array.prototype.forEach.call(w.root.querySelectorAll('[data-cw-bar]'), function (b) { b.classList.toggle('is-done', Number(b.getAttribute('data-cw-bar')) <= reached); });
            w.count.textContent = s === 'review' ? cc.review_label : s === 'invite' ? cc.invite_label : T.tf('Stap %1$d van %2$d', s, STEPS) + ' · ' + cc['step' + s + '_label'];
            w.label.textContent = saving ? cc.making : s === 'invite' ? cc.make : cc.next;
            w.next.classList.toggle('is-final', s === 'invite');
            w.next.disabled = !complete() || saving;
            var target = w.body.querySelector('[data-cw-target]');
            if (target && window.OwnifyWheel) {
                var m = metricOf(draft.metric);
                var top = Math.max(m.step * 40, suggestTarget(draft) * 4);
                window.OwnifyWheel.attach(target, { kind: 'number', min: m.step, max: Math.round(top / m.step) * m.step, step: m.step, 'default': draft.target, unit: format(m.many, '').trim() });
            }
            if (direction) {
                var section = w.body.querySelector('.wizard__step');
                if (section.animate) { section.animate([{ opacity: 0, transform: 'translateX(' + (direction * 14) + 'px)' }, { opacity: 1, transform: 'none' }], { duration: 280, easing: 'cubic-bezier(0.22, 1, 0.36, 1)' }); }
                var title = w.body.querySelector('.wizard__title');
                if (title) { title.focus({ preventScroll: true }); }
                w.body.scrollTop = 0;
            }
        }

        function go(to, direction) { draft.step = to; paint(direction); }
        function close() {
            if (saving) { return; }
            w.root.classList.remove('is-open');
            window.setTimeout(function () { w.root.hidden = true; }, 200);
        }

        w.root.onclick = function (event) {
            var t = event.target, hit;
            if (t.closest('[data-cw-close]')) { close(); return; }
            if (t.closest('[data-cw-back]')) {
                if (saving) { return; }
                if (draft.step === 1) { close(); return; }
                go({ 2: 1, 3: 2, review: 3, invite: 'review' }[draft.step], -1);
                return;
            }
            if (t.closest('[data-cw-next]')) {
                if (!complete()) { return; }
                if (draft.step === 'invite') { make(); return; }
                go({ 1: 2, 2: 3, 3: 'review', review: 'invite' }[draft.step], 1);
                return;
            }
            if ((hit = t.closest('[data-cw-category]'))) { var c = hit.getAttribute('data-cw-category'); draft.category = draft.category === c ? null : c; paint(0); return; }
            if ((hit = t.closest('[data-cw-metric]'))) {
                draft.metric = hit.getAttribute('data-cw-metric');
                if (!draft.touched) { draft.target = suggestTarget(draft); }
                var scroll = w.body.querySelector('.goal-list__scroll'), y = scroll ? scroll.scrollTop : 0;
                paint(0);
                if (w.body.querySelector('.goal-list__scroll')) { w.body.querySelector('.goal-list__scroll').scrollTop = y; }
                return;
            }
            if ((hit = t.closest('[data-cw-kind]'))) { draft.kind = hit.getAttribute('data-cw-kind'); paint(0); return; }
            if ((hit = t.closest('[data-cw-duration]'))) {
                draft.duration = hit.getAttribute('data-cw-duration');
                draft.end = durationEnd(draft.start, draft.duration);
                datesMoved();
                paint(0);
                return;
            }
            if ((hit = t.closest('[data-cw-friend]'))) {
                var id = Number(hit.getAttribute('data-cw-friend'));
                var at = draft.invite.indexOf(id);
                if (at === -1) { draft.invite.push(id); } else { draft.invite.splice(at, 1); }
                var y2 = w.body.querySelector('.goal-list__scroll') ? w.body.querySelector('.goal-list__scroll').scrollTop : 0;
                paint(0);
                if (w.body.querySelector('.goal-list__scroll')) { w.body.querySelector('.goal-list__scroll').scrollTop = y2; }
            }
        };
        w.root.oninput = function (event) {
            var t = event.target;
            if (t.hasAttribute('data-cw-name')) { draft.name = t.value; return; }
            if (t.hasAttribute('data-cw-target')) {
                var n = T.parseNumber(t.value);
                var m = metricOf(draft.metric);
                draft.target = n === null ? 0 : (decimalsOf(m) ? Math.round(n * 10) / 10 : Math.round(n));
                draft.touched = true;
                w.next.disabled = !complete();
                return;
            }
            if (t.hasAttribute('data-cw-find')) {
                draft.find = t.value;
                w.body.querySelector('[data-cw-list]').innerHTML = metricList();
                return;
            }
            if (t.hasAttribute('data-cw-search')) {
                draft.query = t.value;
                var pos = t.selectionStart;
                paint(0);
                var again = w.body.querySelector('[data-cw-search]');
                again.focus();
                try { again.setSelectionRange(pos, pos); } catch (e) { /* type=search */ }
            }
        };

        function make() {
            var metric = metricOf(draft.metric);
            saving = true;
            w.root.classList.add('is-saving');
            paint(0);
            post('api/challenges/create.php', {
                kind: draft.kind,
                metric: draft.metric,
                target: draft.kind === 'together' ? draft.target : null,
                target_unit: D.units === 'imperial' ? (metric.unit === 'l' ? 'floz' : 'mi') : (metric.unit === 'l' ? 'l' : 'km'),
                start_date: isoDay(draft.start),
                end_date: isoDay(draft.end),
                name: draft.name.trim(),
                invite: draft.invite
            }).then(function (answer) {
                saving = false;
                w.root.classList.remove('is-saving');
                if (!answer.ok) { paint(0); toast(failed(answer)); return; }
                close();
                made(answer);
            });
        }

        w.root.hidden = false;
        window.requestAnimationFrame(function () { w.root.classList.add('is-open'); });
        go(1, 0);
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
            + '<div class="ch-row-btns split" role="group">'
            + '<button type="button" class="split__part" data-made-copy>' + icon('copy') + '<span>' + esc(cc.copy) + '</span></button>'
            + '<button type="button" class="split__part" data-made-share>' + icon('share') + '<span>' + esc(cc.share) + '</span></button>'
            + '</div></div>'
            + '<div class="ch-sheet__foot"><button type="button" class="btn btn--primary press ch-wide" data-made-open data-sheet-focus>' + esc(cc.open) + '</button></div>');

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
                + '<div class="ch-sheet__foot"><div class="ch-row-btns split" role="group">'
                + '<button type="button" class="split__part" data-sheet-close>' + esc(ci.cancel) + '</button>'
                + '<button type="button" class="split__part" data-invite-send' + (chosen.length ? '' : ' disabled') + '>'
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
            + '<div class="ch-sheet__foot"><div class="ch-row-btns split split--caution" role="group">'
            + '<button type="button" class="split__part" data-sheet-close data-sheet-focus>' + esc(cd.cancel) + '</button>'
            + '<button type="button" class="split__part" data-leave-yes>' + esc(cd.leave_confirm) + '</button>'
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
