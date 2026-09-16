/**
 * ai-sheet.js — the assistant, as a sheet pulled up over the current page.
 *
 *   swipe up from the dock          → open
 *   swipe down from the sheet's top → close
 *
 * The sheet lives above the rail and never touches it, so whichever page was
 * showing is still showing — same page, same scroll position — the moment the
 * sheet slides away. `returnTo` records that page anyway and puts the user
 * back if anything else ever moves the rail while the sheet is up.
 *
 * Both gestures are deliberately anchored to an area rather than allowed
 * anywhere: the dock to open, the sheet's header to close. Everywhere else
 * vertical movement is the browser's, which keeps scrolling intact and leaves
 * room for a future conversation to scroll inside the sheet.
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

    var DISTANCE_THRESHOLD = 0.25;  // share of the screen height to complete
    var VELOCITY_THRESHOLD = 0.45;  // px/ms — a flick completes it too
    var SCRIM_MAX = 0.5;            // the page below stays present, just dimmed

    /* -------------------------------------------------------------- state */

    var progress = 0;   // 0 = closed, 1 = open
    var startProgress = 0;
    var busy = false;
    var settleTimer = null;

    var paint = nav.painter(function (value) {
        sheet.style.transform = 'translate3d(0, ' + ((1 - value) * 100) + '%, 0)';
        if (scrim) { scrim.style.opacity = (value * SCRIM_MAX).toFixed(3); }
    });

    function paintNow(value) {
        sheet.style.transform = 'translate3d(0, ' + ((1 - value) * 100) + '%, 0)';
        if (scrim) { scrim.style.opacity = (value * SCRIM_MAX).toFixed(3); }
    }

    /* ------------------------------------------------------------ helpers */

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

    function settle(target, moveFocus) {
        var opening = target === 1;
        var changed = target !== progress;

        if (opening && !nav.state.aiOpen && nav.pages) {
            nav.state.returnTo = nav.pages.currentId();
        }

        progress = target;
        busy = true;
        nav.state.aiOpen = opening;

        deck.classList.remove('is-dragging');
        deck.dataset.aiState = 'moving';
        paintNow(target);

        window.clearTimeout(settleTimer);
        settleTimer = window.setTimeout(function () {
            busy = false;
            deck.dataset.aiState = opening ? 'open' : 'closed';

            // Belt and braces: the rail was never moved, but make sure.
            if (!opening && nav.state.returnTo && nav.pages
                && nav.pages.currentId() !== nav.state.returnTo) {
                nav.pages.goToId(nav.state.returnTo);
            }

            applyReachability(opening);
            if (changed && moveFocus) { focusEntry(opening); }
        }, nav.duration + 40);
    }

    function open(moveFocus) {
        if (busy || progress === 1) { return; }
        settle(1, moveFocus !== false);
    }

    function close(moveFocus) {
        if (busy || progress === 0) { return; }
        settle(0, moveFocus !== false);
    }

    /* ------------------------------------------------------------- axis y */

    nav.register('y', {
        canStart: function (ctx) {
            if (busy) { return false; }

            // Opening: upward, and started on the dock — the one place that
            // hands vertical movement to us instead of to a scroller.
            if (!nav.state.aiOpen) {
                return ctx.dy < 0 && inZone(ctx.target, '[data-ai-grab]');
            }

            // Closing: downward, and started on the sheet's header.
            return ctx.dy > 0 && inZone(ctx.target, '[data-ai-dismiss]');
        },

        begin: function () {
            startProgress = progress;
            deck.dataset.aiState = 'moving';
        },

        move: function (ctx) {
            progress = nav.clamp(startProgress - ctx.dy / ctx.height, 0, 1);
            paint(progress);
        },

        end: function (ctx) {
            var opening = startProgress < 0.5;
            var travelled = Math.abs(progress - startProgress);
            var flicked = opening
                ? ctx.vy < -VELOCITY_THRESHOLD
                : ctx.vy > VELOCITY_THRESHOLD;

            var complete = travelled > DISTANCE_THRESHOLD || flicked;
            settle(complete ? (opening ? 1 : 0) : (opening ? 0 : 1), false);
        },

        cancel: function () {
            settle(startProgress, false);
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
