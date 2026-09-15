/**
 * page-navigation.js — horizontal movement between the five primary pages.
 *
 *   Gezondheid ↔ Doelen ↔ Overzicht ↔ Community ↔ Instellingen
 *
 * The rail holds every page side by side and moves as a single element, so a
 * swipe costs one transform. Each page keeps its own scroller, which is what
 * preserves its scroll position when you leave and come back.
 *
 * It knows nothing about the assistant beyond one flag: while the sheet is
 * open, the rail refuses to start a gesture.
 */

(function () {
    'use strict';

    var nav = window.AppNav;
    if (!nav || !nav.deck) { return; }

    var deck = nav.deck;
    var rail = deck.querySelector('[data-rail]');
    var dock = deck.querySelector('[data-ai-grab]');
    if (!rail) { return; }

    var pages = Array.prototype.slice.call(rail.querySelectorAll('[data-page]'));
    if (!pages.length) { return; }

    /* ----------------------------------------------------------- settings */

    var DISTANCE_THRESHOLD = 0.25;  // share of the screen width to change page
    var VELOCITY_THRESHOLD = 0.4;   // px/ms — a flick changes page too

    /* -------------------------------------------------------------- state */

    var index = 0;
    var startIndex = 0;
    var position = 0;
    var busy = false;
    var settleTimer = null;

    for (var i = 0; i < pages.length; i++) {
        if (pages[i].dataset.page === document.documentElement.dataset.activePage) { index = i; }
    }
    position = index;

    var paint = nav.painter(function (pos) {
        rail.style.transform = 'translate3d(' + (-pos * 100) + '%, 0, 0)';
    });

    /* ------------------------------------------------------------ helpers */

    function currentId() {
        return pages[index].dataset.page;
    }

    function indexOfId(id) {
        for (var n = 0; n < pages.length; n++) {
            if (pages[n].dataset.page === id) { return n; }
        }
        return -1;
    }

    function scrollerOf(page) {
        return page.querySelector('[data-scroller]');
    }

    /** Offscreen pages — and everything under the sheet — stay unreachable. */
    function refresh() {
        pages.forEach(function (page, n) {
            var hidden = n !== index || nav.state.aiOpen || nav.state.detailOpen;
            page.inert = hidden;
            if (hidden) { page.setAttribute('aria-hidden', 'true'); }
            else { page.removeAttribute('aria-hidden'); }
        });

        if (dock) {
            dock.inert = nav.state.aiOpen;
            if (nav.state.aiOpen) { dock.setAttribute('aria-hidden', 'true'); }
            else { dock.removeAttribute('aria-hidden'); }
        }
    }

    function syncTabs() {
        var id = currentId();

        Array.prototype.forEach.call(deck.querySelectorAll('[data-nav]'), function (tab) {
            var isActive = tab.getAttribute('data-nav') === id;
            tab.classList.toggle('is-active', isActive);
            if (isActive) { tab.setAttribute('aria-current', 'page'); }
            else { tab.removeAttribute('aria-current'); }
        });
    }

    /* ------------------------------------------------------------- motion */

    function settle(target) {
        index = target;
        position = target;
        busy = true;

        deck.classList.remove('is-dragging');
        deck.dataset.pageState = 'moving';
        rail.style.transform = 'translate3d(' + (-target * 100) + '%, 0, 0)';

        document.documentElement.dataset.activePage = currentId();
        syncTabs();

        window.clearTimeout(settleTimer);
        settleTimer = window.setTimeout(function () {
            busy = false;
            deck.dataset.pageState = 'idle';
            nav.refresh();
        }, nav.duration + 40);
    }

    function goToId(id) {
        var target = indexOfId(id);
        if (target < 0 || busy || target === index) { return; }
        settle(target);
    }

    /* ------------------------------------------------------------- axis x */

    nav.register('x', {
        canStart: function () {
            return !busy && !nav.state.aiOpen && !nav.state.detailOpen && pages.length > 1;
        },

        begin: function () {
            startIndex = index;
            deck.dataset.pageState = 'moving';
        },

        move: function (ctx) {
            position = nav.clamp(startIndex - ctx.dx / ctx.width, 0, pages.length - 1);
            paint(position);
        },

        end: function (ctx) {
            var travelled = position - startIndex;
            var flicked = Math.abs(ctx.vx) > VELOCITY_THRESHOLD;
            var target = startIndex;

            if (flicked || Math.abs(travelled) > DISTANCE_THRESHOLD) {
                var direction = flicked
                    ? (ctx.vx < 0 ? 1 : -1)
                    : (travelled > 0 ? 1 : -1);
                target = nav.clamp(startIndex + direction, 0, pages.length - 1);
            }

            settle(target);
        },

        cancel: function () {
            settle(startIndex);
        }
    });

    /* --------------------------------------------------------- tab clicks */

    deck.addEventListener('click', function (event) {
        var tab = event.target.closest('[data-nav]');
        if (!tab) { return; }

        var id = tab.getAttribute('data-nav');

        if (id === currentId()) {
            // Re-tapping the page you are on returns you to its top.
            var scroller = scrollerOf(pages[index]);
            if (scroller) {
                scroller.scrollTo({ top: 0, behavior: nav.reduceMotion ? 'auto' : 'smooth' });
            }
            return;
        }

        goToId(id);
    });

    /* --------------------------------------------------------------- init */

    nav.pages = {
        currentId: currentId,
        goToId: goToId
    };

    nav.onRefresh(refresh);

    deck.dataset.pageState = 'idle';
    document.documentElement.dataset.activePage = currentId();
    syncTabs();
    refresh();
}());
