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
            var screen = scroller.closest('[data-page], [data-sheet], [data-detail]');
            if (!screen) { return; }

            bindChrome(scroller, screen.querySelector('[data-header]'),
                screen.querySelector('[data-scroll-top]'));
        });

        sharedHeader();
        dockGutter();
    }

    /**
     * The tab bar is centred in the window; the page's column is centred in
     * what its scroller leaves beside a classic scrollbar (a laptop's, not a
     * phone's). The front scroller's scrollbar width goes to --scroll-gutter,
     * which the dock keeps free at its end, so the bar lines up with the
     * cards above it. Overlay scrollbars measure 0 and change nothing.
     */
    function dockGutter() {
        var dock = document.querySelector('.app-dock');
        if (!dock) { return; }

        var root = document.documentElement;
        var ticking = false;

        function frontScroller() {
            var details = document.querySelectorAll('[data-detail]:not([aria-hidden="true"]) [data-scroller]');
            if (details.length) { return details[details.length - 1]; }
            var page = document.querySelector('[data-page="' + root.getAttribute('data-active-page') + '"]');
            return page ? page.querySelector('[data-scroller]') : null;
        }

        function update() {
            ticking = false;
            var scroller = frontScroller();
            var gutter = scroller ? Math.max(0, scroller.offsetWidth - scroller.clientWidth) : 0;
            root.style.setProperty('--scroll-gutter', gutter + 'px');
        }

        function schedule() {
            if (ticking) { return; }
            ticking = true;
            window.requestAnimationFrame(update);
        }

        window.addEventListener('resize', schedule);

        if ('MutationObserver' in window) {
            /* Another page shown, a detail opened or closed, a detail added. */
            new MutationObserver(schedule).observe(document.body, {
                subtree: true,
                childList: true,
                attributes: true,
                attributeFilter: ['data-active-page', 'aria-hidden']
            });
            new MutationObserver(schedule).observe(root, {
                attributes: true,
                attributeFilter: ['data-active-page']
            });
        }

        /* A page whose content grows past the screen gains its scrollbar. */
        if ('ResizeObserver' in window) {
            var sizes = new ResizeObserver(schedule);
            Array.prototype.forEach.call(document.querySelectorAll('[data-scroller]'), function (scroller) {
                sizes.observe(scroller);
                if (scroller.firstElementChild) { sizes.observe(scroller.firstElementChild); }
            });
        }

        update();
    }

    /**
     * The five main pages share one header, above the rail rather than inside
     * any of them. It condenses for whichever page is showing: every page
     * reports its scroll, only the showing page's counts, and arriving on
     * another page re-reads that one. Community's head never scrolls, so on
     * Community the header stays clear, as it always has.
     */
    function sharedHeader() {
        var header = document.querySelector('[data-header-shared]');
        if (!header) { return; }

        var root = document.documentElement;
        var pages = document.querySelectorAll('[data-page]');
        var ticking = false;

        function showingScroller() {
            for (var i = 0; i < pages.length; i++) {
                if (pages[i].getAttribute('data-page') === root.getAttribute('data-active-page')) {
                    return pages[i].querySelector('[data-scroller]');
                }
            }
            return null;
        }

        function update() {
            ticking = false;
            var scroller = showingScroller();
            header.classList.toggle('is-scrolled', !!scroller && scroller.scrollTop > 8);
        }

        function schedule() {
            if (ticking) { return; }
            ticking = true;
            window.requestAnimationFrame(update);
        }

        Array.prototype.forEach.call(pages, function (page) {
            var scroller = page.querySelector('[data-scroller]');
            if (scroller) { scroller.addEventListener('scroll', schedule, { passive: true }); }
        });

        /* page-navigation.js names the page being moved to on <html>. */
        if ('MutationObserver' in window) {
            new MutationObserver(update).observe(root, {
                attributes: true,
                attributeFilter: ['data-active-page']
            });
        }

        /* While it was part of the page, a wheel over the header scrolled the
           page. It no longer is, so the wheel is handed to the showing page. */
        header.addEventListener('wheel', function (event) {
            var scroller = showingScroller();
            if (!scroller || event.ctrlKey || !event.deltaY) { return; }

            var unit = event.deltaMode === 1 ? 16 : (event.deltaMode === 2 ? scroller.clientHeight : 1);
            scroller.scrollBy(0, event.deltaY * unit);
        }, { passive: true });

        update();
    }

    /* For a screen added after load — a new goal's detail page. */
    window.AppChrome = {
        bind: function (scroller) {
            var screen = scroller.closest('[data-page], [data-sheet], [data-detail]');
            if (screen) {
                bindChrome(scroller, screen.querySelector('[data-header]'),
                    screen.querySelector('[data-scroll-top]'));
            }
        }
    };

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
