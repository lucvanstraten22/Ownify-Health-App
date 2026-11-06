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

    /* 42.0 — the sheet lands on a spring that takes over the finger's speed,
       not on a fixed curve: critically damped (no bounce past open or
       closed), response 0.38 s — a flick arrives sooner, a slow lift settles
       gently. The same numbers as the app (ShellState.kt AiSpring). */
    var SPRING_K = Math.pow(2 * Math.PI / 0.38, 2);   // stiffness, per s²
    var SPRING_C = 2 * Math.sqrt(SPRING_K);            // damping ratio 1

    /* -------------------------------------------------------------- state */

    var drag = null;          // { from, base, progress } while a finger holds the sheet
    var settleTimer = null;   // the move under way, until it has landed
    var shown = 0;            // where the sheet is: 0 closed, 1 open
    var spring = null;        // { target, v, last, frame, done } while it settles

    /* ------------------------------------------------------------ helpers */

    /** 0 = closed, 1 = open. */
    function place(value) {
        shown = value;
        var rest = 1 - value;
        sheet.style.transform = 'translate3d(0, ' + (rest * 100).toFixed(3) + '%, 0)';
        if (scrim) { scrim.style.opacity = (value * SCRIM_MAX).toFixed(3); }
    }

    /** Where the sheet is right now: mid-way included. */
    function shownProgress() {
        return shown;
    }

    /** Stops a spring under way, where it is (a finger caught the sheet). */
    function stopSpring() {
        if (!spring) { return; }
        window.cancelAnimationFrame(spring.frame);
        spring = null;
    }

    /**
     * Carries the sheet to [target] from where it is, at [velocity] (sheet
     * heights per second — the finger's, at the lift), and calls [done] once
     * it rests. Stepped a few times a frame so a slow frame cannot overshoot.
     */
    function runSpring(target, velocity, done) {
        stopSpring();
        spring = { target: target, v: velocity || 0, last: 0, frame: 0, done: done };

        function tick(now) {
            var s = spring;
            if (!s) { return; }
            var dt = s.last ? Math.min(0.064, (now - s.last) / 1000) : 1 / 60;
            s.last = now;
            var x = shown;
            for (var i = 0; i < 4; i++) {
                var h = dt / 4;
                s.v += (-SPRING_K * (x - s.target) - SPRING_C * s.v) * h;
                x += s.v * h;
            }
            // Never past open or closed: it lands there.
            if ((s.target === 1 && x >= 1) || (s.target === 0 && x <= 0)) { x = s.target; s.v = 0; }
            if (Math.abs(x - s.target) < 0.0005 && Math.abs(s.v) < 0.01) {
                place(s.target);
                spring = null;
                if (s.done) { s.done(); }
                return;
            }
            place(x);
            s.frame = window.requestAnimationFrame(tick);
        }

        spring.frame = window.requestAnimationFrame(tick);
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

    /* --------------------------------------------------------------- hint */

    /**
     * 42.0 — a quiet invitation to swipe up (docs/AI.md): the handle's grip
     * rises a little and settles, twice, taking on its pressed look on the
     * way — nothing new on the screen. With reduced motion it only brightens.
     *
     *   never used OwnifyAI   every 6 to 8 seconds
     *   used it before        once, a few seconds after the app opens; not
     *                         again in this session (this tab)
     *
     * "Used" is the account's: it asked OwnifyAI something (the server's
     * `used`), or opened the sheet on this device (remembered under the
     * account's own key). A finger that starts the swipe ends the hints for
     * the session. Only on the five pages: not while the sheet, a detail or a
     * panel is in front, nor while the page is hidden.
     */
    var hint = (function () {
        var handle = deck.querySelector('.ai-handle');
        var session = {};
        try { session = JSON.parse(sheet.getAttribute('data-ai-session') || '{}') || {}; } catch (error) { session = {}; }
        var account = session.account || 'anon';
        var USED = 'ownify-ai-opened:' + account;
        var SEEN = 'ownify-ai-hint:' + account;
        var FIRST = 2500;            // ms after the app opens
        var SHOWN = 1500;            // ms the hint takes
        var timer = 0;

        function read(store, key) { try { return store.getItem(key); } catch (error) { return null; } }
        function write(store, key) { try { store.setItem(key, '1'); } catch (error) { /* private mode: forget */ } }

        function everUsed() { return !!session.used || read(window.localStorage, USED) === '1'; }
        function doneThisSession() { return read(window.sessionStorage, SEEN) === '1'; }

        function free() {
            // The desktop layout has no grip (hidden): nothing to hint at there.
            var seen = handle.getClientRects().length > 0 && getComputedStyle(handle).visibility !== 'hidden';
            return seen && !document.hidden && !nav.state.aiOpen && !drag && !spring
                && deck.dataset.detailState !== 'open' && !nav.overlayOpen();
        }

        function show() {
            if (!handle) { return; }
            var still = nav.reduceMotion || document.documentElement.getAttribute('data-motion') === 'reduce';
            var cls = still ? 'is-hint-lit' : 'is-hinting';
            handle.classList.remove('is-hinting', 'is-hint-lit');
            void handle.offsetWidth;   // the animation starts again from its beginning
            handle.classList.add(cls);
            window.setTimeout(function () { handle.classList.remove(cls); }, SHOWN);
        }

        function next(delay) {
            window.clearTimeout(timer);
            timer = window.setTimeout(function () {
                if (doneThisSession()) { return; }
                if (!free()) { next(2000); return; }      // try again soon, not over something
                show();
                if (everUsed()) { write(window.sessionStorage, SEEN); return; }
                next(6000 + Math.random() * 2000);
            }, delay);
        }

        if (handle && !doneThisSession()) { next(FIRST); }

        return {
            /** The sheet opened: this account has used OwnifyAI. */
            used: function () { write(window.localStorage, USED); this.found(); },
            /** The swipe started: no more hints this session. */
            found: function () {
                write(window.sessionStorage, SEEN);
                window.clearTimeout(timer);
                if (handle) { handle.classList.remove('is-hinting', 'is-hint-lit'); }
            }
        };
    }());

    /* ------------------------------------------------------------- motion */

    /**
     * Sends the sheet fully open or fully closed from wherever it is. What
     * can be reached changes at once — an opening sheet can be used while it
     * slides up, a closing one is already out of the way — and focus moves
     * once it has arrived.
     */
    function settle(target, moveFocus, velocity) {
        var opening = target === 1;
        var changed = opening !== nav.state.aiOpen;

        drag = null;
        nav.state.aiOpen = opening;
        if (opening) { hint.used(); }

        deck.dataset.aiState = 'moving';
        applyReachability(opening);
        window.clearTimeout(settleTimer);

        function arrived() {
            settleTimer = null;
            held(false);
            deck.dataset.aiState = opening ? 'open' : 'closed';
            if (changed && moveFocus) { focusEntry(opening); }
        }

        /* Reduced motion: no movement — the sheet arriving, or what it
           uncovers (an open detail, otherwise the page), fades in. */
        if (nav.reduceMotion) {
            stopSpring();
            held(false);
            place(opening ? 1 : 0);
            if (changed) {
                nav.fadeIn(opening ? sheet
                    : (deck.dataset.detailState === 'open' && deck.querySelector('[data-detail-stack]'))
                        || deck.querySelector('.rail'));
            }
            settleTimer = window.setTimeout(arrived, nav.duration + 40);
            return;
        }

        // Driven frame by frame, so the stylesheet's own transition stays out of it.
        held(true);
        settleTimer = true;
        runSpring(opening ? 1 : 0, velocity || 0, arrived);
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

            // Caught on its way: it stays where it is, and the finger carries on from there.
            stopSpring();
            window.clearTimeout(settleTimer);
            settleTimer = null;
            // A finger on the handle has found the gesture: no more hints this session.
            if (!nav.state.aiOpen) { hint.found(); }

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
            // The finger's speed at the lift (px/ms, down positive) as sheet heights per second, up positive.
            var velocity = ctx.height ? -ctx.vy * 1000 / ctx.height : 0;
            settle(nav.clamp(target, 0, 1), false, velocity);
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
