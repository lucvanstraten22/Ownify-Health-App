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
