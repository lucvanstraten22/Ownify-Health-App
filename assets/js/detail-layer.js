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
 *
 * A detail is always open, closed, on its way to one of them, or held by a
 * finger; a released swipe always ends fully open or fully closed.
 *
 * One detail can open over another: a page whose [data-detail-parent] names
 * the detail in front (Slaap's charts, over Slaap). It slides in over it the
 * same way and leaves the same way, back to that detail as it was left —
 * its scroll, its focus. Leaving for another section closes both.
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

    /* -------------------------------------------------------------- state */

    var current = null;       // the detail in front, or on its way in or out
    var shown = false;        // whether current is open, or opening
    var drag = null;          // { from, base, progress } while a finger holds it
    var settleTimer = null;   // the move under way, until it has landed
    var opener = null;        // the card to hand focus back to
    var parent = null;        // the detail under current, when one opened over it
    var parentOpener = null;  // what opened that one

    /* ------------------------------------------------------------ helpers */

    /** 1 = open, 0 = parked off-screen right. */
    function place(value) {
        if (!current) { return; }
        current.style.transform = 'translate3d(' + ((1 - value) * 100) + '%, 0, 0)';
    }

    /** Where the current detail is right now: mid-way included. */
    function shownProgress() {
        var width = current.offsetWidth || window.innerWidth;
        return width ? 1 - nav.translation(current).x / width : (shown ? 1 : 0);
    }

    /** Back to the stylesheet's parking spot, off-screen right. */
    function park(detail) {
        detail.classList.remove('is-dragging', 'is-current');
        detail.style.transform = '';
    }

    /* ------------------------------------------------------ reachability */

    function refresh() {
        details.forEach(function (detail) {
            var live = detail === current && shown && !nav.state.aiOpen;
            detail.inert = !live;
            if (live) { detail.removeAttribute('aria-hidden'); }
            else { detail.setAttribute('aria-hidden', 'true'); }
        });
    }

    nav.onRefresh(refresh);

    /* ------------------------------------------------------------- motion */

    /**
     * Sends the current detail fully open or fully closed from wherever it
     * is. What can be reached changes at once; a closed detail is parked, and
     * focus moves, once it has arrived.
     */
    function settle(target, moveFocus) {
        var opening = target === 1;
        var detail = current;

        drag = null;
        shown = opening;
        nav.state.detailOpen = opening || !!parent;   // closing over a parent: still a detail in front

        if (detail) { detail.classList.remove('is-dragging'); }
        deck.dataset.detailState = 'moving';
        place(opening ? 1 : 0);
        nav.refresh();

        window.clearTimeout(settleTimer);
        settleTimer = window.setTimeout(function () {
            settleTimer = null;
            deck.dataset.detailState = opening ? 'open' : 'closed';

            if (!opening && detail && current === detail) {
                park(detail);
                current = null;

                /* Back to the detail it opened over, as it was. */
                if (parent) {
                    current = parent;
                    shown = true;
                    parent = null;
                    nav.state.detailOpen = true;
                    deck.dataset.detailState = 'open';
                    var back = opener;
                    opener = parentOpener;
                    parentOpener = null;
                    nav.refresh();
                    if (moveFocus && back && back.focus) { back.focus({ preventScroll: true }); }
                    return;
                }
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
        if (drag) { return; }

        var detail = null;
        details.forEach(function (candidate) {
            if (candidate.dataset.detail === id) { detail = candidate; }
        });
        if (!detail) { return; }

        if (current === detail && shown) { return; }   // already open, or opening

        /* Over the detail in front, when it is this one's own. */
        if (current && current !== detail && shown && !parent && !settleTimer
            && detail.dataset.detailParent && current.dataset.detail === detail.dataset.detailParent) {
            parent = current;
            parentOpener = opener;
            current = null;
        }

        if (current && current !== detail) {
            if (shown) { return; }   // another one is in front; its own way out comes first
            park(current);           // one still sliding out finishes at once
        }

        var returning = current === detail;   // caught on its way out: carry on from there

        current = detail;
        current.classList.add('is-current');   // the one the stylesheet lifts off the rail
        opener = trigger || null;

        // Every detail starts at the top, never where it was left.
        if (!returning) {
            var scroller = detail.querySelector('[data-scroller]');
            if (scroller) { scroller.scrollTop = 0; }
        }

        settle(1, true);
    }

    function close(moveFocus) {
        if (drag || !current || !shown) { return; }
        settle(0, moveFocus !== false);
    }

    /* ------------------------------------------------------------- axis x */

    nav.register('x', {
        canStart: function (ctx) {
            // Only a rightward drag, only while a detail is in front, and
            // never while the assistant covers it.
            return !!current && shown && !nav.state.aiOpen && ctx.dx > 0;
        },

        begin: function () {
            var from = settleTimer ? shownProgress() : 1;

            window.clearTimeout(settleTimer);
            settleTimer = null;

            current.classList.add('is-dragging');
            place(from);

            drag = { from: from, base: 1, progress: from };
            deck.dataset.detailState = 'moving';
        },

        move: function (ctx) {
            if (!drag || !current) { return; }
            drag.progress = nav.clamp(drag.from - ctx.dx / ctx.width, 0, 1);
            place(drag.progress);
        },

        end: function (ctx) {
            if (!drag || !current) { settle(shown ? 1 : 0, false); return; }
            var target = nav.resolve(ctx, drag.base, drag.progress, ctx.dx, ctx.vx, ctx.width);
            settle(nav.clamp(target, 0, 1), false);
        },

        cancel: function () {
            // Interrupted: it stays open — unless it was taken away meanwhile.
            settle(current ? 1 : 0, false);
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

        // Leaving for another section closes the detail behind you — and
        // the one it opened over, which goes with it.
        if (current && shown && event.target.closest('[data-nav]')) {
            if (parent) {
                park(current);
                current = parent;
                parent = null;
                opener = parentOpener;
                parentOpener = null;
                nav.refresh();
            }
            close(false);
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape' || !current || !shown || nav.state.aiOpen) { return; }
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
            if (parent === detail) { parent = null; parentOpener = null; }

            if (current === detail) {
                window.clearTimeout(settleTimer);
                settleTimer = null;
                detail.classList.remove('is-current');
                current = null;
                shown = false;
                drag = null;
                nav.state.detailOpen = false;
                deck.dataset.detailState = 'closed';
                nav.refresh();
            }
        },
        adopt: function (detail) {
            if (details.indexOf(detail) === -1) { details.push(detail); }
            refresh();
        }
    };

    deck.dataset.detailState = 'closed';
    refresh();
}());
