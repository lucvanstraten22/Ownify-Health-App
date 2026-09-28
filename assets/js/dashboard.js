/**
 * dashboard.js — turns the data attributes rendered by PHP into visual state.
 *
 * The DOM is the contract: every animated element carries its own target
 * value, so nothing here needs to know about PHP, routes or data sources.
 *
 *   [data-ring]      data-progress="0..1"   primary score ring
 *   [data-bar]       data-progress="0..1"   meters and goal progress
 *   [data-count-to]  "" | "0..100"          numeric read-out ("" = no data)
 */

(function () {
    'use strict';

    var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var DURATION = 1000;

    /* ------------------------------------------------------------- helpers */

    function clamp01(value) {
        if (isNaN(value)) { return 0; }
        return Math.min(1, Math.max(0, value));
    }

    function progressOf(el) {
        return clamp01(parseFloat(el.getAttribute('data-progress')));
    }

    function easeOutCubic(t) {
        return 1 - Math.pow(1 - t, 3);
    }

    /** Runs fn(progress 0..1) each frame for `duration` ms. */
    function animate(duration, fn) {
        if (reduceMotion) { fn(1); return; }

        var start = null;

        function step(now) {
            if (start === null) { start = now; }
            var t = Math.min(1, (now - start) / duration);
            fn(easeOutCubic(t));
            if (t < 1) { window.requestAnimationFrame(step); }
        }

        window.requestAnimationFrame(step);
    }

    /* --------------------------------------------------------- score rings */

    function drawRing(ring) {
        var circle = ring.querySelector('[data-ring-value]');
        if (!circle) { return; }

        var radius = circle.r && circle.r.baseVal ? circle.r.baseVal.value : 68;
        var circumference = 2 * Math.PI * radius;
        var target = progressOf(ring);

        circle.style.strokeDasharray = circumference;
        circle.style.strokeDashoffset = circumference;

        if (target <= 0) { return; }

        // The CSS transition does the easing; one frame of delay makes it run.
        window.requestAnimationFrame(function () {
            circle.style.strokeDashoffset = circumference * (1 - target);
        });
    }

    /* --------------------------------------------------------------- bars */

    function fillBar(bar) {
        var target = progressOf(bar);
        if (target <= 0) { return; }

        window.requestAnimationFrame(function () {
            bar.style.width = (target * 100) + '%';
        });
    }

    /* ---------------------------------------------------------- read-outs */

    function countUp(el) {
        var raw = el.getAttribute('data-count-to');
        if (raw === null || raw === '') { return; }      // placeholder state

        var target = parseFloat(raw);
        if (isNaN(target)) { return; }

        var decimals = (raw.split('.')[1] || '').length;

        animate(DURATION, function (t) {
            el.textContent = (target * t).toFixed(decimals);
        });
    }

    /* ------------------------------------------------------------- runner */

    /** Plays a block's animations once, the first time it is on screen. */
    function activate(root) {
        if (root.dataset.animated === 'true') { return; }
        root.dataset.animated = 'true';

        Array.prototype.forEach.call(root.querySelectorAll('[data-ring]'), drawRing);
        Array.prototype.forEach.call(root.querySelectorAll('[data-bar]'), fillBar);
        Array.prototype.forEach.call(root.querySelectorAll('[data-count-to]'), countUp);
    }

    function init() {
        var blocks = document.querySelectorAll('.card');
        if (!blocks.length) { return; }

        if (!('IntersectionObserver' in window)) {
            Array.prototype.forEach.call(blocks, activate);
            return;
        }

        // The viewport is the root: intersection already accounts for the
        // clipping of every scroller in between, and there is now more than
        // one of them — a page each, plus the assistant sheet.
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) { return; }
                activate(entry.target);
                observer.unobserve(entry.target);
            });
        }, { rootMargin: '0px 0px -10% 0px', threshold: 0.2 });

        Array.prototype.forEach.call(blocks, function (block) {
            observer.observe(block);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());

/**
 * The goal bar's reading, by touch. A pointer shows it by hovering (CSS) and
 * the keyboard by focus; a finger shows it by resting on the bar for a
 * moment, and it stays while the finger does. Moving first is a swipe or a
 * scroll, not a hold, and leaves the page to it. Delegated, so a card that
 * is swapped in keeps working.
 */
(function () {
    'use strict';

    var HOLD_MS = 280;      // long enough not to fire on a tap or a swipe's start
    var SLOP    = 10;       // px a resting finger may drift before it counts as moving

    var track = null;
    var timer = 0;
    var startX = 0;
    var startY = 0;

    function end() {
        window.clearTimeout(timer);
        timer = 0;
        if (track) { track.classList.remove('is-reading'); }
        track = null;
    }

    document.addEventListener('pointerdown', function (event) {
        if (event.pointerType === 'mouse') { return; }

        var hit = event.target.closest && event.target.closest('[data-goal-track]');
        if (!hit) { return; }

        end();
        track = hit;
        startX = event.clientX;
        startY = event.clientY;
        timer = window.setTimeout(function () {
            if (track) { track.classList.add('is-reading'); }
        }, HOLD_MS);
    }, { passive: true });

    document.addEventListener('pointermove', function (event) {
        if (!track || track.classList.contains('is-reading')) { return; }
        if (Math.abs(event.clientX - startX) > SLOP || Math.abs(event.clientY - startY) > SLOP) { end(); }
    }, { passive: true });

    ['pointerup', 'pointercancel'].forEach(function (type) {
        document.addEventListener(type, function () { if (track) { end(); } }, { passive: true });
    });

    /* A held finger is not asking for the browser's own menu. */
    document.addEventListener('contextmenu', function (event) {
        if (event.target.closest && event.target.closest('[data-goal-track]')) { event.preventDefault(); }
    });

    window.addEventListener('blur', end);
}());
