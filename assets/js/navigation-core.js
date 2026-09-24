/**
 * navigation-core.js — one gesture pipeline, two axes.
 *
 * The app has two independent gestures and they must never fight:
 *
 *   horizontal → move between the five pages   (page-navigation.js)
 *   vertical   → open or close the assistant   (ai-sheet.js)
 *
 * This module owns the input, decides which axis a gesture is on, and hands it
 * to the controller that registered for that axis. A gesture is routed once
 * and never re-routed, so a page swipe can't turn into a sheet drag halfway
 * through.
 *
 * Touch is read as touch events, not pointer events. Once a gesture is ours,
 * cancelling its touchmoves is what every mobile browser reliably obeys: the
 * browser cannot start scrolling halfway through a swipe, and the swipe is
 * never taken away because the finger drifted. Mouse and pen are read as
 * pointer events. Vertical movement that is not a gesture of ours is never
 * cancelled, so the pages scroll exactly as before.
 *
 * Every gesture is a record that exists from touch-down to lift and is then
 * thrown away, so nothing of one gesture can leak into the next. One whose end
 * never arrives (the app was switched away from, the page was frozen, a system
 * gesture took the finger) is abandoned as soon as the next one starts or the
 * page is hidden, and whatever it was moving settles on a real position.
 */

window.AppNav = (function () {
    'use strict';

    var deck = document.querySelector('[data-deck]');

    var axes = {};
    var refreshers = [];

    /* ----------------------------------------------------------- settings */

    var LOCK = 8;          // px of travel before an axis is chosen
    var RATIO = 1.15;      // how clearly that axis must win...
    var FORCE = 24;        // ...until this much travel, when the larger one simply wins

    /* How a released drag is resolved — see resolve() below. */
    var FLICK = 0.3;       // px/ms at the lift: a flick completes...
    var FLICK_MIN = 16;    // ...once the finger has travelled this far
    var QUICK_MS = 300;    // a whole swipe this short completes...
    var QUICK_MIN = 32;    // ...once the finger has travelled this far
    var SHARE = 0.25;      // otherwise it completes past this share of the screen,
    var HELD_SHARE = 0.5;  // or, released after being held still, past half: the nearer end
    var HOLD_MS = 160;     // this still before the lift counts as held
    var HOLD_SLOP = 4;     // px a held finger may still wander
    var VELOCITY_MS = 90;  // the lift's velocity is measured over this last stretch,
    var TURN_MS = 40;      // unless the finger clearly turned back within this one:
    var TURN_MIN = 8;      // at least this far, over at least
    var TURN_SPAN = 24;    // this long

    var api = {
        deck: deck,
        reduceMotion: window.matchMedia('(prefers-reduced-motion: reduce)').matches,
        duration: 280,

        /** Shared, so every controller can read what the others are doing. */
        state: { aiOpen: false, detailOpen: false },

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
         *
         * A controller is { canStart(ctx), begin(ctx), move(ctx), end(ctx),
         * cancel(ctx) }. After begin, exactly one of end or cancel follows.
         */
        register: function (axis, controller) {
            if (!axes[axis]) { axes[axis] = []; }
            axes[axis].unshift(controller);
        },

        /**
         * True while a panel outside the deck is in front of everything —
         * the account panel, the goal wizard, a confirmation.
         *
         * Every layer listens for Escape on the document, so without this one
         * keypress closes the panel AND the layer behind it. The layers inside
         * the deck stand down while a panel is up.
         */
        overlayOpen: function () {
            return document.querySelector('[data-overlay]:not([hidden])') !== null;
        },

        clamp: function (value, min, max) {
            return value < min ? min : (value > max ? max : value);
        },

        /**
         * Where an element's transform has it right now, in px — mid-way
         * through a transition included. Read once, when a finger catches a
         * layer that is still moving, so the drag starts where the layer is.
         */
        translation: function (element) {
            var transform = window.getComputedStyle(element).transform;
            if (!transform || transform === 'none') { return { x: 0, y: 0 }; }

            var values = transform.slice(transform.indexOf('(') + 1, transform.lastIndexOf(')'))
                .split(',').map(parseFloat);

            return values.length === 16
                ? { x: values[12] || 0, y: values[13] || 0 }
                : { x: values[4] || 0, y: values[5] || 0 };
        },

        /**
         * Where a released drag goes: one step on from base, or back to it.
         * One rule for every layer, so the rail, the sheet and a detail page
         * answer the same movement the same way:
         *
         *   a flick           completes, in the direction it is going
         *   a quick swipe     completes, however short, once it is clearly meant
         *   anything slower   completes past a quarter of the way
         *   held, then let go goes to whichever end is nearer
         *
         * Positions run against the finger: a finger moving left, or up,
         * raises them.
         *
         *   base  the position the drag belongs to
         *   q     where the layer is at the lift
         *   d, v  the finger's travel and velocity on the axis (px, px/ms)
         *   size  the length of one step, px
         */
        resolve: function (ctx, base, q, d, v, size) {
            var toward = d < 0 ? 1 : (d > 0 ? -1 : (q > base ? 1 : (q < base ? -1 : 0)));
            if (!toward) { return base; }

            var travel = Math.abs(d);
            var along = (q - base) * toward * size;   // how far the layer got
            var speed = -v * toward;                 // < 0: on its way back
            var share = ctx.held || speed < -0.1 ? HELD_SHARE : SHARE;

            var completes = speed >= FLICK ? travel >= FLICK_MIN
                : speed <= -FLICK ? false
                : (ctx.duration <= QUICK_MS && travel >= QUICK_MIN) || along >= size * share;

            return completes ? base + toward : base;
        }
    };

    if (!deck) { return api; }

    api.duration = parseFloat(
        getComputedStyle(deck).getPropertyValue('--screen-duration')
    ) || 280;

    /* ------------------------------------------------------------ gesture */

    var gesture = null;     // the one in progress, from touch-down to lift
    var swallowUntil = 0;   // a mouse drag's own click, which is not a click

    function owned(target) {
        /* An element that reads horizontal drags itself — the goal chart,
           scrubbed with a finger — is not the deck's to route. */
        return !!(target && target.closest && target.closest('[data-gesture-own]'));
    }

    function start(kind, id, x, y, time, target, pointerType) {
        gesture = {
            kind: kind,                 // 'touch' or 'pointer'
            id: id,                     // the touch identifier, or the pointerId
            pointerType: pointerType,
            target: target,
            startX: x, startY: y, startTime: time,
            x: x, y: y, time: time,
            samples: [{ x: x, y: y, t: time }],
            // Measured once: nothing during the gesture forces a layout.
            width: deck.clientWidth || window.innerWidth,
            height: deck.clientHeight || window.innerHeight,
            decided: false,             // the axis question has been answered
            controller: null,           // who owns it, if anyone
            unwatch: null,
            captured: false
        };
        return gesture;
    }

    function record(g, x, y, time) {
        g.x = x;
        g.y = y;
        g.time = time;
        g.samples.push({ x: x, y: y, t: time });

        // Only the last stretch is ever looked at.
        while (g.samples.length > 2 && time - g.samples[0].t > 400) { g.samples.shift(); }
    }

    function context(g, atLift) {
        var ctx = {
            startX: g.startX,
            startY: g.startY,
            x: g.x,
            y: g.y,
            dx: g.x - g.startX,
            dy: g.y - g.startY,
            vx: 0,
            vy: 0,
            width: g.width,
            height: g.height,
            target: g.target,
            pointerType: g.pointerType,
            duration: g.time - g.startTime,
            held: false
        };

        if (atLift) { measure(g, ctx); }
        return ctx;
    }

    /** Travel and velocity on one axis over the last `span` ms. */
    function stretch(samples, now, key, span) {
        var last = samples[samples.length - 1];
        var oldest = null;

        for (var i = samples.length - 1; i >= 0 && now - samples[i].t <= span; i--) {
            oldest = samples[i];
        }

        if (!oldest || now - oldest.t < 8) { return { d: 0, t: 0, v: 0 }; }

        var d = last[key] - oldest[key];
        return { d: d, t: now - oldest.t, v: d / (now - oldest.t) };
    }

    /**
     * The finger's velocity at the lift, on one axis. Averaged over the last
     * stretch, so one jittery event cannot fake a flick, and zero for a finger
     * that stopped before lifting, however fast it was going before. A finger
     * that turned back at the very end is going where it turned: that last
     * flick back is the intent, not the average of both directions.
     */
    function velocityOn(samples, now, key) {
        var whole = stretch(samples, now, key, VELOCITY_MS);
        var late = stretch(samples, now, key, TURN_MS);

        var turned = late.t >= TURN_SPAN && Math.abs(late.d) >= TURN_MIN && late.v * whole.v < 0;
        return turned ? late.v : whole.v;
    }

    /** Velocity at the lift, and whether the finger was held still before it. */
    function measure(g, ctx) {
        var samples = g.samples;
        var now = g.time;
        var i;

        ctx.vx = velocityOn(samples, now, 'x');
        ctx.vy = velocityOn(samples, now, 'y');

        // Where the finger was HOLD_MS ago, and whether it has left that spot.
        for (i = samples.length - 1; i >= 0; i--) {
            if (now - samples[i].t >= HOLD_MS) { break; }
        }

        if (i >= 0) {
            var anchor = samples[i];
            ctx.held = true;
            for (var j = i + 1; j < samples.length; j++) {
                if (Math.abs(samples[j].x - anchor.x) > HOLD_SLOP || Math.abs(samples[j].y - anchor.y) > HOLD_SLOP) {
                    ctx.held = false;
                    break;
                }
            }
        }
    }

    /** Which axis the gesture is on, once it is clear. */
    function axisOf(ctx) {
        var ax = Math.abs(ctx.dx);
        var ay = Math.abs(ctx.dy);
        var travel = Math.max(ax, ay);

        if (travel < LOCK) { return null; }
        if (ax > ay * RATIO) { return 'x'; }
        if (ay > ax * RATIO) { return 'y'; }
        if (travel >= FORCE) { return ax >= ay ? 'x' : 'y'; }
        return null;   // still ambiguous — wait for a clearer intent
    }

    function claim(axis, ctx) {
        var candidates = axes[axis] || [];
        for (var i = 0; i < candidates.length; i++) {
            if (candidates[i].canStart(ctx)) { return candidates[i]; }
        }
        return null;
    }

    function engage(g, controller, ctx) {
        g.controller = controller;

        /* A mouse press starts selecting text before it is known to be a
           swipe. Left alone, that selection stays — and the next press on it
           starts a native drag of the text, which cancels the next swipe. */
        if (g.kind === 'pointer') {
            deck.classList.add('is-pointer-drag');
            var selection = window.getSelection ? window.getSelection() : null;
            if (selection && selection.removeAllRanges) { selection.removeAllRanges(); }
        }

        controller.begin(ctx);
        controller.move(ctx);   // straight to where the finger already is
    }

    /** The lift: the controller decides where the layer goes. */
    function finish(g) {
        if (!g.controller) { return; }
        var ctx = context(g, true);

        try {
            g.controller.end(ctx);
        } catch (error) {
            // Whatever went wrong, the layer must not stay where the finger left it.
            try { g.controller.cancel(ctx); } catch (ignored) { /* nothing left to do */ }
            window.setTimeout(function () { throw error; });
        }
    }

    /**
     * Ends the gesture in progress without a lift: cancelled by the browser,
     * interrupted by the system, or found stale. What it was moving goes back
     * to where it belongs.
     */
    function abandon() {
        var g = gesture;
        if (!g) { return; }

        gesture = null;
        release(g);

        if (g.controller) {
            try { g.controller.cancel(context(g, true)); }
            catch (error) { window.setTimeout(function () { throw error; }); }
        }
    }

    function release(g) {
        if (g.unwatch) { g.unwatch(); g.unwatch = null; }
        if (g.kind === 'pointer') { deck.classList.remove('is-pointer-drag'); }

        if (g.captured) {
            g.captured = false;
            try { deck.releasePointerCapture(g.id); } catch (ignored) { /* already released */ }
        }
    }

    /* -------------------------------------------------------------- touch */

    function touchOf(list, id) {
        for (var i = 0; i < list.length; i++) {
            if (list[i].identifier === id) { return list[i]; }
        }
        return null;
    }

    /**
     * Touch events go to the element the finger first landed on, even once it
     * has been taken out of the page — a card replaced while the finger was on
     * it — and then no longer reach the deck. Listening on that element too
     * keeps such a gesture from losing its lift; while it is still in the
     * page, the deck hears the event and this stays out of the way.
     */
    function watch(g) {
        var node = g.target;
        if (!node || node === deck || typeof node.addEventListener !== 'function') { return; }

        var detached = function (handler) {
            return function (event) {
                if (!deck.contains(node)) { handler(event); }
            };
        };

        var move = detached(onTouchMove);
        var end = detached(onTouchEnd);
        var cancel = detached(onTouchCancel);

        node.addEventListener('touchmove', move, { passive: false });
        node.addEventListener('touchend', end, { passive: false });
        node.addEventListener('touchcancel', cancel, { passive: true });

        g.unwatch = function () {
            node.removeEventListener('touchmove', move);
            node.removeEventListener('touchend', end);
            node.removeEventListener('touchcancel', cancel);
        };
    }

    function onTouchStart(event) {
        var g = gesture;

        // Another finger joins one that is still down (one that is starting
        // cannot be that finger, even if the browser reuses its identifier).
        // Before anything has started, two fingers are the browser's (pinch
        // zoom); once a drag is under way, the first finger keeps it.
        if (g && g.kind === 'touch' && touchOf(event.touches, g.id) && !touchOf(event.changedTouches, g.id)) {
            if (!g.controller) { abandon(); }
            return;
        }

        // A gesture whose lift never arrived is over now.
        if (g) { abandon(); }

        if (event.touches.length !== 1 || owned(event.target)) { return; }

        var touch = event.changedTouches[0];
        start('touch', touch.identifier, touch.clientX, touch.clientY, event.timeStamp, event.target, 'touch');
        watch(gesture);
    }

    function onTouchMove(event) {
        var g = gesture;
        if (!g || g.kind !== 'touch') { return; }

        var touch = touchOf(event.changedTouches, g.id);
        if (!touch) { return; }

        record(g, touch.clientX, touch.clientY, event.timeStamp);

        if (g.controller) {
            // Ours for the rest of its life: the browser may not scroll it.
            if (event.cancelable) { event.preventDefault(); }
            g.controller.move(context(g, false));
            return;
        }

        if (g.decided) { return; }

        var ctx = context(g, false);
        var axis = axisOf(ctx);
        if (!axis) { return; }

        g.decided = true;

        var controller = claim(axis, ctx);

        // Not ours (a page scroll, a pinch), or the browser already started
        // scrolling it and keeps it: either way, hands off for good.
        if (!controller || !event.cancelable) { return; }

        event.preventDefault();
        engage(g, controller, ctx);
    }

    function onTouchEnd(event) {
        var g = gesture;
        if (!g || g.kind !== 'touch') { return; }

        var touch = touchOf(event.changedTouches, g.id);
        if (!touch) { return; }   // another finger lifted

        record(g, touch.clientX, touch.clientY, event.timeStamp);
        gesture = null;
        release(g);

        if (!g.controller) { return; }

        // A drag is not a tap: no click on whatever is under the finger.
        if (event.cancelable) { event.preventDefault(); }
        finish(g);
    }

    function onTouchCancel(event) {
        var g = gesture;
        if (!g || g.kind !== 'touch') { return; }

        // Someone else's finger was cancelled; this one is still down.
        if (!touchOf(event.changedTouches, g.id) && touchOf(event.touches, g.id)) { return; }

        abandon();
    }

    /* ---------------------------------------------------------- mouse, pen */

    function onPointerDown(event) {
        if (event.pointerType === 'touch') { return; }   // read as touch events
        if (event.pointerType === 'mouse' && event.button !== 0) { return; }

        swallowUntil = 0;
        if (gesture) { abandon(); }   // a new press: the old gesture is over
        if (owned(event.target)) { return; }

        start('pointer', event.pointerId, event.clientX, event.clientY, event.timeStamp,
            event.target, event.pointerType);
    }

    function onPointerMove(event) {
        var g = gesture;
        if (!g || g.kind !== 'pointer' || event.pointerId !== g.id) { return; }

        // A button that is no longer down was released somewhere this never
        // heard about: that was the lift.
        if (event.pointerType === 'mouse' && !(event.buttons & 1)) {
            onPointerUp(event);
            return;
        }

        record(g, event.clientX, event.clientY, event.timeStamp);

        if (g.controller) {
            // Text selection would otherwise fight the drag.
            if (event.cancelable) { event.preventDefault(); }
            g.controller.move(context(g, false));
            return;
        }

        if (g.decided) { return; }

        var ctx = context(g, false);
        var axis = axisOf(ctx);
        if (!axis) { return; }

        g.decided = true;

        var controller = claim(axis, ctx);
        if (!controller) { return; }

        engage(g, controller, ctx);

        try {
            deck.setPointerCapture(event.pointerId);
            g.captured = true;
        } catch (ignored) { /* not critical */ }
    }

    function onPointerUp(event) {
        var g = gesture;
        if (!g || g.kind !== 'pointer' || event.pointerId !== g.id) { return; }

        record(g, event.clientX, event.clientY, event.timeStamp);
        gesture = null;
        release(g);

        if (!g.controller) { return; }

        // The mouse fires a click after the drag; it is not one.
        swallowUntil = event.timeStamp + 400;
        finish(g);
    }

    function onPointerCancel(event) {
        var g = gesture;
        if (!g || g.kind !== 'pointer' || event.pointerId !== g.id) { return; }
        abandon();
    }

    function onLostCapture(event) {
        var g = gesture;
        if (!g || g.kind !== 'pointer' || event.pointerId !== g.id || !g.captured) { return; }
        g.captured = false;
        abandon();
    }

    /* ------------------------------------------------------------ wiring */

    deck.addEventListener('touchstart', onTouchStart, { passive: true });
    deck.addEventListener('touchmove', onTouchMove, { passive: false });
    deck.addEventListener('touchend', onTouchEnd, { passive: false });
    deck.addEventListener('touchcancel', onTouchCancel, { passive: true });

    deck.addEventListener('pointerdown', onPointerDown, { passive: true });
    deck.addEventListener('pointermove', onPointerMove, { passive: false });
    deck.addEventListener('pointerup', onPointerUp, { passive: true });
    deck.addEventListener('pointercancel', onPointerCancel, { passive: true });
    deck.addEventListener('lostpointercapture', onLostCapture, { passive: true });

    document.addEventListener('click', function (event) {
        if (!swallowUntil) { return; }

        var own = event.timeStamp <= swallowUntil;
        swallowUntil = 0;
        if (!own) { return; }

        event.stopPropagation();
        event.preventDefault();
    }, true);

    /* Whatever interrupts the app mid-gesture — switching apps, a system
       gesture, the page going into the back/forward cache and coming out
       again — must not leave a gesture half done. */
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') { abandon(); }
    });
    window.addEventListener('pagehide', abandon);
    window.addEventListener('blur', abandon);
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) { abandon(); }
    });

    /* A translated layer still counts towards the deck's scrollable overflow.
       Nothing can scroll it by hand, but a programmatic scroll would shift
       every layer for good. Snap it back if that ever happens. */
    deck.addEventListener('scroll', function () {
        if (deck.scrollLeft !== 0) { deck.scrollLeft = 0; }
        if (deck.scrollTop !== 0) { deck.scrollTop = 0; }
    }, { passive: true });

    return api;
}());
