/**
 * navigation-core.js — one pointer pipeline, two axes.
 *
 * The app has two independent gestures and they must never fight:
 *
 *   horizontal → move between the five pages   (page-navigation.js)
 *   vertical   → open or close the assistant   (ai-sheet.js)
 *
 * This module owns the pointer events, decides which axis a gesture is on,
 * and hands it to the controller that registered for that axis. A gesture is
 * routed once and never re-routed, so a page swipe can't turn into a sheet
 * drag halfway through.
 *
 * What it deliberately does not do: fight the browser. Vertical panning is
 * left to the scrollers everywhere except the two areas that opt out with
 * `touch-action: none` (the dock and the sheet's header), which is why
 * ordinary scrolling still works exactly as before.
 */

window.AppNav = (function () {
    'use strict';

    var deck = document.querySelector('[data-deck]');

    var axes = {};
    var refreshers = [];

    var api = {
        deck: deck,
        reduceMotion: window.matchMedia('(prefers-reduced-motion: reduce)').matches,
        duration: 280,

        /** Shared, so every controller can read what the others are doing. */
        state: { aiOpen: false, detailOpen: false, returnTo: null },

        /**
         * Reachability is a property of the whole stack, not of one layer:
         * each layer registers how to re-apply its own inert state, and any
         * layer that changes can ask for all of them to be re-applied.
         */
        onRefresh: function (fn) { refreshers.push(fn); },
        refresh: function () {
            for (var i = 0; i < refreshers.length; i++) { refreshers[i](); }
        },

        /** Filled in by page-navigation.js once the rail is wired. */
        pages: null,

        /**
         * More than one controller can want the same axis — the rail and the
         * detail layer both use horizontal. They are told apart by canStart:
         * the first one that claims the gesture gets it, so their activation
         * zones must not overlap.
         */
        register: function (axis, controller) {
            if (!axes[axis]) { axes[axis] = []; }
            axes[axis].unshift(controller);
        },

        clamp: function (value, min, max) {
            return value < min ? min : (value > max ? max : value);
        },

        /** Coalesces writes to one per frame, with the latest value winning. */
        painter: function (draw) {
            var handle = null;
            var pending = 0;

            return function (value) {
                pending = value;
                if (handle !== null) { return; }

                handle = window.requestAnimationFrame(function () {
                    handle = null;
                    draw(pending);
                });
            };
        }
    };

    if (!deck) { return api; }

    api.duration = parseFloat(
        getComputedStyle(deck).getPropertyValue('--screen-duration')
    ) || 280;

    /* ----------------------------------------------------------- settings */

    var LOCK = 10;    // px of travel before an axis is chosen
    var RATIO = 1.2;  // how clearly that axis must win

    /* -------------------------------------------------------------- state */

    var pointerId = null;
    var active = null;
    var decided = false;
    var startX = 0, startY = 0, startTarget = null;
    var lastX = 0, lastY = 0, lastTime = 0, vx = 0, vy = 0;
    var swallowClick = false;

    function context(event) {
        return {
            startX: startX,
            startY: startY,
            x: event.clientX,
            y: event.clientY,
            dx: event.clientX - startX,
            dy: event.clientY - startY,
            vx: vx,
            vy: vy,
            width: deck.clientWidth || window.innerWidth,
            height: deck.clientHeight || window.innerHeight,
            target: startTarget,
            pointerType: event.pointerType
        };
    }

    function reset() {
        pointerId = null;
        active = null;
        decided = false;
        deck.classList.remove('is-dragging');
    }

    /* ------------------------------------------------------------ handlers */

    function onPointerDown(event) {
        if (pointerId !== null) { return; }
        if (event.pointerType === 'mouse' && event.button !== 0) { return; }

        pointerId = event.pointerId;
        startTarget = event.target;
        startX = lastX = event.clientX;
        startY = lastY = event.clientY;
        lastTime = event.timeStamp;
        vx = vy = 0;
        active = null;
        decided = false;
        swallowClick = false;
    }

    function onPointerMove(event) {
        if (pointerId === null || event.pointerId !== pointerId) { return; }

        var elapsed = event.timeStamp - lastTime;
        if (elapsed > 0) {
            vx = (event.clientX - lastX) / elapsed;
            vy = (event.clientY - lastY) / elapsed;
            lastX = event.clientX;
            lastY = event.clientY;
            lastTime = event.timeStamp;
        }

        var ctx = context(event);

        if (active) {
            // Text selection would otherwise fight a mouse drag on desktop.
            if (ctx.pointerType !== 'touch' && event.cancelable) { event.preventDefault(); }
            active.move(ctx);
            return;
        }

        if (decided) { return; }

        var ax = Math.abs(ctx.dx);
        var ay = Math.abs(ctx.dy);
        if (Math.max(ax, ay) < LOCK) { return; }

        var axis = null;
        if (ax > ay * RATIO) { axis = 'x'; }
        else if (ay > ax * RATIO) { axis = 'y'; }
        else { return; }   // still ambiguous — wait for a clearer intent

        decided = true;

        var candidates = axes[axis] || [];
        var controller = null;

        for (var i = 0; i < candidates.length; i++) {
            if (candidates[i].canStart(ctx)) { controller = candidates[i]; break; }
        }

        if (!controller) {
            // Not ours: leave the gesture to the browser for the rest of its life.
            return;
        }

        active = controller;
        deck.classList.add('is-dragging');

        if (deck.setPointerCapture) {
            try { deck.setPointerCapture(event.pointerId); } catch (e) { /* not critical */ }
        }

        controller.begin(ctx);
    }

    function onPointerUp(event) {
        if (pointerId === null || event.pointerId !== pointerId) { return; }

        if (active) {
            swallowClick = true;
            active.end(context(event));
        }

        reset();
    }

    function onPointerCancel(event) {
        if (pointerId === null || event.pointerId !== pointerId) { return; }

        if (active) { active.cancel(context(event)); }
        reset();
    }

    deck.addEventListener('pointerdown', onPointerDown, { passive: true });
    deck.addEventListener('pointermove', onPointerMove, { passive: false });
    deck.addEventListener('pointerup', onPointerUp, { passive: true });
    deck.addEventListener('pointercancel', onPointerCancel, { passive: true });

    /* A drag that ends on a button must not also fire its click. Exactly one
       click is swallowed, and only directly after a drag. */
    document.addEventListener('click', function (event) {
        if (!swallowClick) { return; }
        swallowClick = false;
        event.stopPropagation();
        event.preventDefault();
    }, true);

    /* A translated layer still counts towards the deck's scrollable overflow.
       Nothing can scroll it by hand, but a programmatic scroll would shift
       every layer for good. Snap it back if that ever happens. */
    deck.addEventListener('scroll', function () {
        if (deck.scrollLeft !== 0) { deck.scrollLeft = 0; }
        if (deck.scrollTop !== 0) { deck.scrollTop = 0; }
    }, { passive: true });

    return api;
}());
