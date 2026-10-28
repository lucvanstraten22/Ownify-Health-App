/**
 * nutrition.js — Voeding, drawn (components/nutrition-view.php,
 * docs/NUTRITION.md). Every number and word is the server's; this only
 *
 *   - steps between the seven days (‹ ›), all drawn already;
 *   - opens a macronutrient's row in place — from the row, or from its part
 *     of the ring — one at a time, and lights its part of the ring; a cursor
 *     over the ring lights a part without opening anything, so nothing on
 *     the page moves under it;
 *   - moves the slider with the finger and saves the cijfer when it is let
 *     go (api/health/rating.php), for the day shown; the score and the
 *     points are then fetched again and swapped in (health-rating.js's
 *     OwnifyRefresh), and the Voeding dot goes (notices.js).
 */
(function () {
    'use strict';

    var card = document.querySelector('[data-nutrition]');
    var sliderCard = document.querySelector('[data-nutrition-slider]');
    if (!card || !sliderCard) { return; }

    var T = window.OwnifyI18n;
    var model = {};
    try { model = JSON.parse(card.querySelector('script[data-nutrition-days]').textContent || '{}'); } catch (e) { model = {}; }
    var days = model.days || [];
    var copy = model.rating || {};
    var shown = 0;
    var still = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
    var isStill = function () { return (still && still.matches) || document.documentElement.getAttribute('data-motion') === 'reduce'; };

    var format = function (text, args) {
        var i = 0;
        return String(text).replace(/%(\d\$)?s/g, function (m, n) { return args[n ? parseInt(n, 10) - 1 : i++]; });
    };

    /* ---------------------------------------------------------------- days */

    function dayEl(i) { return card.querySelector('[data-nutrition-day="' + i + '"]'); }

    function showDay(i) {
        if (i < 0 || i >= days.length) { return; }
        var from = dayEl(shown);
        if (from) { from.hidden = true; close(from); }
        shown = i;
        var to = dayEl(shown);
        if (to) { to.hidden = false; }
        card.querySelector('[data-nutrition-name]').textContent = days[i].name;
        card.querySelector('[data-nutrition-step="1"]').disabled = i >= days.length - 1;
        card.querySelector('[data-nutrition-step="-1"]').disabled = i <= 0;
        paintSlider();
    }

    card.addEventListener('click', function (event) {
        var step = event.target.closest('[data-nutrition-step]');
        if (step) { showDay(shown + parseInt(step.getAttribute('data-nutrition-step'), 10)); return; }

        var toggle = event.target.closest('[data-row-toggle]');
        if (toggle) { open(toggle.getAttribute('data-row-toggle'), false); return; }

        var part = event.target.closest('[data-part]');
        if (part) { open(part.getAttribute('data-part'), true); }
    });

    /* ---------------------------------------------------------------- rows */

    function close(day) {
        day.querySelectorAll('.nutrition-row.is-open').forEach(function (row) {
            row.classList.remove('is-open');
            row.querySelector('[data-row-toggle]').setAttribute('aria-expanded', 'false');
        });
        light(day, null);
    }

    function open(key, fromRing) {
        var day = dayEl(shown);
        if (!day) { return; }
        var row = day.querySelector('.nutrition-row[data-macro="' + key + '"]');
        var wasOpen = row && row.classList.contains('is-open');
        close(day);
        if (!row || wasOpen) { return; }
        row.classList.add('is-open');
        row.querySelector('[data-row-toggle]').setAttribute('aria-expanded', 'true');
        light(day, key);
        /* From the ring: bring its row into view once it has opened. */
        if (fromRing) {
            window.setTimeout(function () {
                row.scrollIntoView({ block: 'nearest', behavior: isStill() ? 'auto' : 'smooth' });
            }, isStill() ? 0 : 280);
        }
    }

    /* The ring: one part lit and its numbers in the middle, or the day's total. */
    function light(day, key) {
        var ring = day.querySelector('[data-ring-box]');
        if (!ring) { return; }
        var open = day.querySelector('.nutrition-row.is-open');
        var lit = key || (open ? open.getAttribute('data-macro') : null);
        ring.classList.toggle('is-focused', !!lit);
        ring.querySelectorAll('[data-part]').forEach(function (p) { p.classList.toggle('is-on', p.getAttribute('data-part') === lit); });
        var total = ring.querySelector('[data-ring-total]');
        if (total) { total.hidden = !!lit; }
        ring.querySelectorAll('[data-ring-macro]').forEach(function (m) { m.hidden = m.getAttribute('data-ring-macro') !== lit; });
    }

    card.addEventListener('pointerover', function (event) {
        if (event.pointerType !== 'mouse') { return; }
        var part = event.target.closest('[data-part]');
        if (part) { light(dayEl(shown), part.getAttribute('data-part')); }
    });
    card.addEventListener('pointerout', function (event) {
        if (event.pointerType !== 'mouse' || !event.target.closest('[data-part]')) { return; }
        if (event.relatedTarget && event.relatedTarget.closest && event.relatedTarget.closest('[data-part]')) { return; }
        light(dayEl(shown), null);
    });

    /* -------------------------------------------------------------- slider */

    var track = sliderCard.querySelector('[data-rating-track]');
    var shownValue = sliderCard.querySelector('[data-rating-shown]');
    var question = sliderCard.querySelector('[data-rating-question]');
    var saved = sliderCard.querySelector('[data-rating-saved]');
    var savedText = sliderCard.querySelector('[data-rating-saved-text]');
    var value = 6;
    var dragging = false;
    var busy = false;

    /* The day as it reads inside the question and the saved line (the server's). */
    function dayWord(i) { return days[i] ? String(days[i].word || days[i].name) : ''; }

    function place(v, empty) {
        track.style.setProperty('--at', String((v - 1) / 9));
        track.setAttribute('aria-valuenow', String(v));
        track.setAttribute('aria-valuetext', empty ? copy.unsaved : String(v));
        track.classList.toggle('is-empty', !!empty);
        shownValue.textContent = empty ? '–' : String(v);
    }

    function paintSlider() {
        var r = days[shown] ? days[shown].rating : null;
        value = r || 6;
        place(value, r === null || r === undefined);
        question.textContent = '/ 10 · ' + format(copy.question, [dayWord(shown)]);
        var on = r !== null && r !== undefined;
        saved.setAttribute('data-tone', on ? 'on' : 'neutral');
        savedText.textContent = on ? copy.saved + ' · ' + format(copy.saved_for, [String(r), dayWord(shown)]) : copy.unsaved;
    }

    function fromX(x) {
        var box = track.getBoundingClientRect();
        // The thumb's 14px half, in screen pixels (the desktop's zoom).
        var edge = 14 * (track.offsetWidth ? box.width / track.offsetWidth : 1);
        var f = Math.max(0, Math.min(1, (x - box.left - edge) / (box.width - 2 * edge)));
        return Math.round(f * 9) + 1;
    }

    function csrf() {
        var panel = document.querySelector('[data-account]');
        return panel ? (panel.getAttribute('data-csrf') || '') : '';
    }

    function save(v) {
        if (busy) { return; }
        busy = true;
        var day = shown;
        var body = new FormData();
        body.append('csrf', csrf());
        body.append('rating', String(v));
        body.append('date', days[day].date);
        fetch('api/health/rating.php', { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
            .catch(function () { return { ok: false }; })
            .then(function (result) {
                busy = false;
                if (!result || !result.ok) {
                    saved.setAttribute('data-tone', 'warn');
                    savedText.textContent = (result && result.error) || copy.error;
                    return;
                }
                days[day].rating = v;
                if (day === shown) { paintSlider(); }
                if (day === 0 && window.OwnifyNotices) { window.OwnifyNotices.done('rating'); }
                refresh();
            });
    }

    /* The score and points as they now are, swapped in (health-rating.js). */
    function refresh() {
        fetch(window.location.href.split('#')[0], { credentials: 'same-origin', cache: 'no-store', headers: { 'Accept': 'text/html' } })
            .then(function (r) { if (!r.ok) { throw new Error(); } return r.text(); })
            .then(function (html) {
                var fresh = new DOMParser().parseFromString(html, 'text/html');
                if (!fresh.querySelector('[data-deck]')) { return; }
                if (window.OwnifyRefresh && window.OwnifyRefresh.scores) { window.OwnifyRefresh.scores(fresh); }
                if (window.OwnifyNotices) { window.OwnifyNotices.read(fresh); }
            })
            .catch(function () { /* saved; the next load shows it */ });
    }

    track.addEventListener('pointerdown', function (event) {
        dragging = true;
        track.setPointerCapture(event.pointerId);
        track.classList.add('is-dragging');
        value = fromX(event.clientX);
        place(value, false);
    });
    track.addEventListener('pointermove', function (event) {
        if (!dragging) { return; }
        var v = fromX(event.clientX);
        if (v !== value) { value = v; place(value, false); }
    });
    var release = function () {
        if (!dragging) { return; }
        dragging = false;
        track.classList.remove('is-dragging');
        save(value);
    };
    track.addEventListener('pointerup', release);
    track.addEventListener('pointercancel', release);
    track.addEventListener('lostpointercapture', release);

    track.addEventListener('keydown', function (event) {
        var v = value;
        if (event.key === 'ArrowRight' || event.key === 'ArrowUp') { v = Math.min(10, value + 1); }
        else if (event.key === 'ArrowLeft' || event.key === 'ArrowDown') { v = Math.max(1, value - 1); }
        else if (event.key === 'Home') { v = 1; }
        else if (event.key === 'End') { v = 10; }
        else { return; }
        event.preventDefault();
        value = v;
        place(value, false);
        save(value);
    });

    paintSlider();
}());
