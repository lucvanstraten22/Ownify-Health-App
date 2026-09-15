/**
 * interactions.js — micro-interactions only.
 *
 * Everything here is progressive: without JavaScript the dashboard still
 * renders completely, only without reveal, condensing header and the
 * scroll-to-top control.
 */

(function () {
    'use strict';

    var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* ------------------------------------------------ scroll-linked chrome */

    /**
     * Every page scrolls inside its own container, so the condensing header
     * and the floating control are bound per screen rather than to the window.
     */
    function scrollChrome() {
        var scrollers = document.querySelectorAll('[data-scroller]');

        Array.prototype.forEach.call(scrollers, function (scroller) {
            var screen = scroller.closest('[data-page], [data-sheet]');
            if (!screen) { return; }

            bindChrome(scroller, screen.querySelector('[data-header]'),
                screen.querySelector('[data-scroll-top]'));
        });
    }

    function bindChrome(scroller, header, fab) {
        var ticking = false;

        if (!header && !fab) { return; }

        function update() {
            ticking = false;
            var y = scroller.scrollTop;

            if (header) {
                header.classList.toggle('is-scrolled', y > 8);
            }

            if (fab) {
                var show = y > 360;
                if (show && fab.hidden) {
                    fab.hidden = false;
                    window.requestAnimationFrame(function () { fab.classList.add('is-visible'); });
                } else if (!show && !fab.hidden) {
                    fab.classList.remove('is-visible');
                    window.setTimeout(function () {
                        if (!fab.classList.contains('is-visible')) { fab.hidden = true; }
                    }, 200);
                }
            }
        }

        scroller.addEventListener('scroll', function () {
            if (ticking) { return; }
            ticking = true;
            window.requestAnimationFrame(update);
        }, { passive: true });

        if (fab) {
            fab.addEventListener('click', function () {
                scroller.scrollTo({ top: 0, behavior: reduceMotion ? 'auto' : 'smooth' });
                var main = document.getElementById('main');
                if (main) { main.focus({ preventScroll: true }); }
            });
        }

        update();
    }

    /* ------------------------------------------------------ section reveal */

    function reveal() {
        var items = document.querySelectorAll('.reveal');
        if (!items.length) { return; }

        if (reduceMotion || !('IntersectionObserver' in window)) {
            Array.prototype.forEach.call(items, function (item) {
                item.classList.add('is-visible');
            });
            return;
        }

        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry, index) {
                if (!entry.isIntersecting) { return; }
                var delay = Math.min(index, 4) * 60;
                window.setTimeout(function () {
                    entry.target.classList.add('is-visible');
                }, delay);
                observer.unobserve(entry.target);
            });
        }, { rootMargin: '0px 0px -8% 0px', threshold: 0.12 });

        Array.prototype.forEach.call(items, function (item) { observer.observe(item); });
    }

    function init() {
        scrollChrome();
        reveal();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
