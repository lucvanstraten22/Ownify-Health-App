/**
 * swipe-navigation.js — the gesture that connects the two screens.
 *
 *   overview  --  swipe right-to-left  -->  assistant
 *   assistant --  swipe left-to-right  -->  overview
 *
 * The layers follow the finger: progress runs 0 (overview) to 1 (assistant)
 * and is written straight to two transforms plus one opacity, so a gesture
 * never touches layout. Vertical movement is left entirely to the browser —
 * `touch-action: pan-y` on the scrollers means a vertical pan scrolls
 * natively and cancels the gesture instead of competing with it.
 */

(function () {
    'use strict';

    var deck = document.querySelector('[data-deck]');
    if (!deck) { return; }

    var overview = deck.querySelector('[data-screen="overview"]');
    var assistant = deck.querySelector('[data-screen="ai"]');
    var scrim = deck.querySelector('[data-scrim]');

    if (!overview || !assistant) { return; }

    /* ----------------------------------------------------------- settings */

    var DISTANCE_THRESHOLD = 0.28;  // share of the screen width to complete
    var VELOCITY_THRESHOLD = 0.4;   // px/ms — a flick completes it too
    var DIRECTION_LOCK = 8;         // px before the axis is decided
    var VERTICAL_ESCAPE = 10;       // px of vertical drift that hands back to scroll
    var SCRIM_MAX = 0.85;

    var parallax = parseFloat(
        getComputedStyle(deck).getPropertyValue('--screen-parallax')
    ) || 24;

    var duration = parseFloat(
        getComputedStyle(deck).getPropertyValue('--screen-duration')
    ) || 280;

    /* -------------------------------------------------------------- state */

    var progress = 0;          // 0 = overview, 1 = assistant
    var startProgress = 0;
    var dragging = false;
    var decided = false;
    var busy = false;
    var activePointer = null;
    var startX = 0, startY = 0, lastX = 0, lastTime = 0, velocity = 0;
    var frameHandle = null, framedProgress = 0;
    var settleTimer = null;

    /* ------------------------------------------------------------ helpers */

    function screenWidth() {
        return deck.clientWidth || window.innerWidth || 1;
    }

    function clamp01(value) {
        return value < 0 ? 0 : (value > 1 ? 1 : value);
    }

    /** Writes the whole transition: two transforms and one opacity. */
    function paint(value) {
        assistant.style.transform = 'translate3d(' + ((1 - value) * 100) + '%, 0, 0)';
        overview.style.transform = 'translate3d(' + (-value * parallax) + '%, 0, 0)';
        if (scrim) { scrim.style.opacity = (value * SCRIM_MAX).toFixed(3); }
    }

    function paintOnFrame(value) {
        framedProgress = value;
        if (frameHandle !== null) { return; }

        frameHandle = window.requestAnimationFrame(function () {
            frameHandle = null;
            paint(framedProgress);
        });
    }

    /** Offscreen layers must not be reachable by tab, screen reader or click. */
    function applyReachability(open) {
        var hidden = open ? overview : assistant;
        var shown = open ? assistant : overview;

        hidden.setAttribute('aria-hidden', 'true');
        hidden.inert = true;
        shown.removeAttribute('aria-hidden');
        shown.inert = false;
    }

    function focusEntry(open) {
        var target = open
            ? assistant.querySelector('[data-navigate="overview"]')
            : overview.querySelector('[data-navigate="ai"]');

        if (target && typeof target.focus === 'function') {
            target.focus({ preventScroll: true });
        }
    }

    /* ------------------------------------------------------------- motion */

    /**
     * Animates to `target` (0 or 1) and finalises the screen state.
     * `moveFocus` is only true for taps and keys — a swipe should not move
     * focus out from under the finger.
     */
    function settle(target, moveFocus) {
        dragging = false;
        decided = false;
        deck.classList.remove('is-dragging');
        deck.dataset.state = 'moving';

        var changed = target !== progress;
        progress = target;
        busy = true;
        paint(target);

        window.clearTimeout(settleTimer);
        settleTimer = window.setTimeout(function () {
            busy = false;
            deck.dataset.state = target === 1 ? 'open' : 'closed';
            applyReachability(target === 1);
            if (changed && moveFocus) { focusEntry(target === 1); }
        }, duration + 40);
    }

    function navigate(name, moveFocus) {
        if (busy) { return; }
        settle(name === 'ai' ? 1 : 0, moveFocus !== false);
    }

    /* ------------------------------------------------------------ gesture */

    function onPointerDown(event) {
        if (busy || activePointer !== null) { return; }
        if (event.pointerType === 'mouse' && event.button !== 0) { return; }
        if (event.target.closest('[data-navigate]')) { return; }

        activePointer = event.pointerId;
        startProgress = progress;
        startX = lastX = event.clientX;
        startY = event.clientY;
        lastTime = event.timeStamp;
        velocity = 0;
        dragging = false;
        decided = false;
    }

    function onPointerMove(event) {
        if (activePointer === null || event.pointerId !== activePointer) { return; }

        var dx = event.clientX - startX;
        var dy = event.clientY - startY;

        if (!decided) {
            // Vertical intent wins: hand the gesture back to the scroller.
            if (Math.abs(dy) > VERTICAL_ESCAPE && Math.abs(dy) > Math.abs(dx)) {
                reset();
                return;
            }

            if (Math.abs(dx) > DIRECTION_LOCK && Math.abs(dx) > Math.abs(dy) * 1.2) {
                decided = true;
                dragging = true;
                deck.classList.add('is-dragging');
                deck.dataset.state = 'moving';

                if (deck.setPointerCapture) {
                    try { deck.setPointerCapture(event.pointerId); } catch (e) { /* not critical */ }
                }
            } else {
                return;
            }
        }

        if (!dragging) { return; }

        // Text selection would otherwise fight a mouse drag on desktop.
        if (event.pointerType !== 'touch' && event.cancelable) { event.preventDefault(); }

        var elapsed = event.timeStamp - lastTime;
        if (elapsed > 0) {
            velocity = (event.clientX - lastX) / elapsed;
            lastX = event.clientX;
            lastTime = event.timeStamp;
        }

        progress = clamp01(startProgress - dx / screenWidth());
        paintOnFrame(progress);
    }

    function onPointerUp() {
        if (activePointer === null) { return; }

        if (!dragging) { reset(); return; }

        var toAssistant = startProgress < 0.5;
        var travelled = Math.abs(progress - startProgress);
        var flicked = toAssistant
            ? velocity < -VELOCITY_THRESHOLD
            : velocity > VELOCITY_THRESHOLD;

        var complete = travelled > DISTANCE_THRESHOLD || flicked;
        var target = complete ? (toAssistant ? 1 : 0) : (toAssistant ? 0 : 1);

        activePointer = null;
        settle(target, false);
    }

    function onPointerCancel() {
        if (activePointer === null) { return; }

        if (dragging) {
            activePointer = null;
            settle(startProgress, false);
            return;
        }

        reset();
    }

    function reset() {
        activePointer = null;
        dragging = false;
        decided = true;   // stays decided until the pointer lifts
        deck.classList.remove('is-dragging');
    }

    /* --------------------------------------------------------------- wire */

    deck.addEventListener('pointerdown', onPointerDown, { passive: true });
    deck.addEventListener('pointermove', onPointerMove, { passive: false });
    deck.addEventListener('pointerup', onPointerUp, { passive: true });
    deck.addEventListener('pointercancel', onPointerCancel, { passive: true });

    // Tap or keyboard equivalents of the gesture.
    deck.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-navigate]');
        if (!trigger) { return; }

        event.preventDefault();
        navigate(trigger.getAttribute('data-navigate'), true);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && progress === 1) { navigate('overview', true); }
    });

    /* A translated layer still counts towards the deck's scrollable overflow.
       Nobody can scroll it by hand (overflow is hidden), but a programmatic
       scroll — focus landing on an offscreen node, say — would shift both
       layers permanently. Snap it back if that ever happens. */
    deck.addEventListener('scroll', function () {
        if (deck.scrollLeft !== 0) { deck.scrollLeft = 0; }
        if (deck.scrollTop !== 0) { deck.scrollTop = 0; }
    }, { passive: true });

    deck.dataset.state = 'closed';
    applyReachability(false);
}());
