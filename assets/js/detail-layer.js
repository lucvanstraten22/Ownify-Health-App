/**
 * detail-layer.js — drilling from a page into one of its items.
 *
 * Two pages use this: Gezondheid drills into a health area, Doelen drills into
 * a goal. They share one layer and one gesture because they are the same
 * movement — nothing about it is specific to what is being opened.
 *
 * A detail page is a layer above the rail and below the dock, so the tab bar
 * and the assistant stay reachable from inside it. It opens on a tap of
 * anything carrying [data-detail-open] and closes with a rightward swipe, the
 * back pill or Escape — the same direction you would swipe to go back anywhere
 * else, and safe to use here because the rail stands down while a detail is in
 * front of it.
 */

(function () {
    'use strict';

    var nav = window.AppNav;
    if (!nav || !nav.deck) { return; }

    var deck = nav.deck;
    var stack = deck.querySelector('[data-detail-stack]');
    if (!stack) { return; }

    var details = Array.prototype.slice.call(stack.querySelectorAll('[data-detail]'));
    if (!details.length) { return; }

    /* ----------------------------------------------------------- settings */

    var DISTANCE_THRESHOLD = 0.25;  // share of the screen width to dismiss
    var VELOCITY_THRESHOLD = 0.4;   // px/ms — a flick dismisses too

    /* -------------------------------------------------------------- state */

    var current = null;       // the open detail element, or null
    var progress = 0;         // 1 = fully open, 0 = parked off-screen right
    var startProgress = 0;
    var busy = false;
    var settleTimer = null;
    var opener = null;        // the card to hand focus back to

    function paint(value) {
        if (!current) { return; }
        current.style.transform = 'translate3d(' + ((1 - value) * 100) + '%, 0, 0)';
    }

    var paintFramed = nav.painter(paint);

    /* ------------------------------------------------------ reachability */

    function refresh() {
        details.forEach(function (detail) {
            var live = detail === current && progress === 1 && !nav.state.aiOpen;
            detail.inert = !live;
            if (live) { detail.removeAttribute('aria-hidden'); }
            else { detail.setAttribute('aria-hidden', 'true'); }
        });
    }

    nav.onRefresh(refresh);

    /* ------------------------------------------------------------- motion */

    function settle(target, moveFocus) {
        var opening = target === 1;

        progress = target;
        busy = true;
        nav.state.detailOpen = opening;

        deck.classList.remove('is-dragging');
        deck.dataset.detailState = 'moving';
        paint(target);

        window.clearTimeout(settleTimer);
        settleTimer = window.setTimeout(function () {
            busy = false;
            deck.dataset.detailState = opening ? 'open' : 'closed';

            if (!opening && current) {
                current.style.transform = '';
                current = null;
            }

            nav.refresh();

            if (moveFocus) {
                var target2 = opening
                    ? (current && current.querySelector('[data-detail-close]'))
                    : opener;
                if (target2 && target2.focus) { target2.focus({ preventScroll: true }); }
            }
        }, nav.duration + 40);
    }

    function open(id, trigger) {
        if (busy || current) { return; }

        var detail = null;
        details.forEach(function (candidate) {
            if (candidate.dataset.detail === id) { detail = candidate; }
        });
        if (!detail) { return; }

        current = detail;
        opener = trigger || null;

        // Every detail starts at the top, never where it was left.
        var scroller = detail.querySelector('[data-scroller]');
        if (scroller) { scroller.scrollTop = 0; }

        detail.inert = false;
        detail.removeAttribute('aria-hidden');
        settle(1, true);
    }

    function close(moveFocus) {
        if (busy || !current) { return; }
        settle(0, moveFocus !== false);
    }

    /* ------------------------------------------------------------- axis x */

    nav.register('x', {
        canStart: function (ctx) {
            // Only a rightward drag, only while a detail is in front, and
            // never while the assistant covers it.
            return !busy && !!current && !nav.state.aiOpen && ctx.dx > 0;
        },

        begin: function () {
            startProgress = progress;
            deck.dataset.detailState = 'moving';
        },

        move: function (ctx) {
            progress = nav.clamp(startProgress - ctx.dx / ctx.width, 0, 1);
            paintFramed(progress);
        },

        end: function (ctx) {
            var travelled = startProgress - progress;
            var flicked = ctx.vx > VELOCITY_THRESHOLD;
            settle(travelled > DISTANCE_THRESHOLD || flicked ? 0 : 1, false);
        },

        cancel: function () {
            settle(startProgress, false);
        }
    });

    /* ------------------------------------------------- taps and keyboard */

    deck.addEventListener('click', function (event) {
        var card = event.target.closest('[data-detail-open]');
        if (card) {
            event.preventDefault();
            open(card.getAttribute('data-detail-open'), card);
            return;
        }

        if (event.target.closest('[data-detail-close]')) {
            event.preventDefault();
            close(true);
            return;
        }

        // Leaving for another section closes the detail behind you.
        if (current && event.target.closest('[data-nav]')) { close(false); }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape' || !current || nav.state.aiOpen) { return; }
        // A panel in front of this one owns the keypress.
        if (nav.overlayOpen()) { return; }
        close(true);
    });

    /* --------------------------------------------------------------- init */

    /* Doelen deletes goals, and a deleted goal's detail page has to leave with
       it; a goal created without a reload brings a new one, which has to be
       known here before it can be opened. Nothing else here is public. */
    nav.details = {
        open: open,
        close: close,
        currentId: function () { return current ? current.dataset.detail : null; },
        forget: function (detail) {
            var at = details.indexOf(detail);
            if (at !== -1) { details.splice(at, 1); }
            if (current === detail) { current = null; progress = 0; }
        },
        adopt: function (detail) {
            if (details.indexOf(detail) === -1) { details.push(detail); }
            refresh();
        }
    };

    deck.dataset.detailState = 'closed';
    refresh();
}());
