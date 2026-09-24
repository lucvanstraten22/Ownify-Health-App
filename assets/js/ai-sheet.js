/**
 * ai-sheet.js — the assistant, as a sheet pulled up over the current page.
 *
 *   swipe up from the dock          → open
 *   swipe down from the sheet's top → close
 *
 * The sheet lives above the rail and never touches it, so whichever page was
 * showing is still showing — same page, same scroll position — the moment the
 * sheet slides away.
 *
 * Both gestures are deliberately anchored to an area rather than allowed
 * anywhere: the dock to open, the sheet's header to close. Everywhere else
 * vertical movement is the browser's, which keeps scrolling intact and leaves
 * room for a future conversation to scroll inside the sheet.
 *
 * The sheet is always open, closed, on its way to one of them, or held by a
 * finger. A released drag always ends fully open or fully closed; a finger
 * can catch the sheet on its way and carry on from where it is.
 */

(function () {
    'use strict';

    var nav = window.AppNav;
    if (!nav || !nav.deck) { return; }

    var deck = nav.deck;
    var sheet = deck.querySelector('[data-sheet="ai"]');
    var scrim = deck.querySelector('[data-scrim]');
    if (!sheet) { return; }

    /* ----------------------------------------------------------- settings */

    var SCRIM_MAX = 0.5;            // the page below stays present, just dimmed

    /* -------------------------------------------------------------- state */

    var drag = null;          // { from, base, progress } while a finger holds the sheet
    var settleTimer = null;   // the move under way, until it has landed

    /* ------------------------------------------------------------ helpers */

    /** 0 = closed, 1 = open. */
    function place(value) {
        sheet.style.transform = 'translate3d(0, ' + ((1 - value) * 100) + '%, 0)';
        if (scrim) { scrim.style.opacity = (value * SCRIM_MAX).toFixed(3); }
    }

    /** Where the sheet is right now: mid-way included. */
    function shownProgress() {
        var height = sheet.offsetHeight || window.innerHeight;
        return height ? 1 - nav.translation(sheet).y / height : (nav.state.aiOpen ? 1 : 0);
    }

    function held(on) {
        sheet.classList.toggle('is-dragging', on);
        if (scrim) { scrim.classList.toggle('is-dragging', on); }
    }

    function inZone(target, selector) {
        return !!(target && target.closest && target.closest(selector));
    }

    function applyReachability(open) {
        sheet.inert = !open;
        if (open) { sheet.removeAttribute('aria-hidden'); }
        else { sheet.setAttribute('aria-hidden', 'true'); }

        nav.refresh();
    }

    function focusEntry(open) {
        var target = open
            ? sheet.querySelector('[data-ai-close]')
            : deck.querySelector('[data-ai-open]');

        if (target && typeof target.focus === 'function') {
            target.focus({ preventScroll: true });
        }
    }

    /* ------------------------------------------------------------- motion */

    /**
     * Sends the sheet fully open or fully closed from wherever it is. What
     * can be reached changes at once — an opening sheet can be used while it
     * slides up, a closing one is already out of the way — and focus moves
     * once it has arrived.
     */
    function settle(target, moveFocus) {
        var opening = target === 1;
        var changed = opening !== nav.state.aiOpen;

        drag = null;
        nav.state.aiOpen = opening;

        held(false);
        deck.dataset.aiState = 'moving';
        place(opening ? 1 : 0);
        applyReachability(opening);

        window.clearTimeout(settleTimer);
        settleTimer = window.setTimeout(function () {
            settleTimer = null;
            deck.dataset.aiState = opening ? 'open' : 'closed';
            if (changed && moveFocus) { focusEntry(opening); }
        }, nav.duration + 40);
    }

    function open(moveFocus) {
        if (drag || nav.state.aiOpen) { return; }
        settle(1, moveFocus !== false);
    }

    function close(moveFocus) {
        if (drag || !nav.state.aiOpen) { return; }
        settle(0, moveFocus !== false);
    }

    /* ------------------------------------------------------------- axis y */

    nav.register('y', {
        canStart: function (ctx) {
            // Opening: upward, and started on the dock — the one place that
            // hands vertical movement to us instead of to a scroller.
            if (!nav.state.aiOpen) {
                return ctx.dy < 0 && inZone(ctx.target, '[data-ai-grab]');
            }

            // Closing: downward, and started on the sheet's header.
            return ctx.dy > 0 && inZone(ctx.target, '[data-ai-dismiss]');
        },

        begin: function () {
            var state = nav.state.aiOpen ? 1 : 0;
            var from = settleTimer ? shownProgress() : state;

            window.clearTimeout(settleTimer);
            settleTimer = null;

            held(true);
            place(from);

            drag = {
                from: from,
                base: Math.abs(from - state) < 1 ? state : Math.round(from),
                progress: from
            };
            deck.dataset.aiState = 'moving';
        },

        move: function (ctx) {
            if (!drag) { return; }
            drag.progress = nav.clamp(drag.from - ctx.dy / ctx.height, 0, 1);
            place(drag.progress);
        },

        end: function (ctx) {
            if (!drag) { settle(nav.state.aiOpen ? 1 : 0, false); return; }
            var target = nav.resolve(ctx, drag.base, drag.progress, ctx.dy, ctx.vy, ctx.height);
            settle(nav.clamp(target, 0, 1), false);
        },

        cancel: function () {
            // Interrupted: back to how it was.
            settle(drag ? drag.base : (nav.state.aiOpen ? 1 : 0), false);
        }
    });

    /* ------------------------------------------------- taps and keyboard */

    deck.addEventListener('click', function (event) {
        if (event.target.closest('[data-ai-open]')) {
            event.preventDefault();
            open(true);
            return;
        }

        if (event.target.closest('[data-ai-close]')) {
            event.preventDefault();
            close(true);
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape' || !nav.state.aiOpen) { return; }
        // A panel in front of the sheet owns the keypress.
        if (nav.overlayOpen()) { return; }
        close(true);
    });

    /* --------------------------------------------------------------- init */

    deck.dataset.aiState = 'closed';
    applyReachability(false);
}());
