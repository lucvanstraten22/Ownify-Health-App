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

    function scrollChrome() {
        var header = document.querySelector('[data-header]');
        var fab = document.querySelector('[data-scroll-top]');
        var ticking = false;

        if (!header && !fab) { return; }

        function update() {
            ticking = false;
            var y = window.scrollY || window.pageYOffset;

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

        window.addEventListener('scroll', function () {
            if (ticking) { return; }
            ticking = true;
            window.requestAnimationFrame(update);
        }, { passive: true });

        if (fab) {
            fab.addEventListener('click', function () {
                window.scrollTo({ top: 0, behavior: reduceMotion ? 'auto' : 'smooth' });
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

    /* ------------------------------------------------------ bottom tab bar */

    function tabbar() {
        var tabs = document.querySelectorAll('[data-nav]');
        if (!tabs.length) { return; }

        Array.prototype.forEach.call(tabs, function (tab) {
            tab.addEventListener('click', function () {
                // The active tab is the only destination that exists in this
                // version; it returns the user to the top of the overview.
                // Other tabs stay inert rather than faking navigation.
                if (tab.classList.contains('is-active')) {
                    window.scrollTo({ top: 0, behavior: reduceMotion ? 'auto' : 'smooth' });
                }
                tab.blur();
            });
        });
    }

    function init() {
        scrollChrome();
        reveal();
        tabbar();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
