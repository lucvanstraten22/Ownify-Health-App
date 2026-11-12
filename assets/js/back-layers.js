/**
 * back-layers.js — the phone's back closes the layer in front (50.0).
 *
 * A detail page, the assistant, a popup, a wizard, the wheel: each is a
 * layer over the page, and each one open is one step in the browser's
 * history. So the back button — Android's, a browser's, a swipe from the
 * edge — closes the top layer and nothing else, as it does in the app; it
 * never leaves Ownify while something is open.
 *
 * Nothing here knows how a layer opens or closes. It counts what stands
 * open, keeps the history as deep as that, and on a back it sends the one
 * Escape every layer already answers — the layers decide among themselves
 * which of them is in front (navigation-core.js, overlayOpen()).
 *
 *   a layer opens            → one history step is added
 *   it closes by itself      → its step is taken back out
 *   the person goes back     → the top layer closes
 *
 * A layer that refuses to close (a wizard asking first) keeps its step.
 */

(function () {
    'use strict';

    var nav = window.AppNav;
    if (!nav || !window.history || typeof window.history.pushState !== 'function') { return; }

    var depth    = 0;       // history steps that are ours
    var ours     = 0;       // popstates we caused by taking steps out
    var settling = 0;       // until when a layer closed by "back" may still be on its way out
    var queued   = false;

    function open() {
        var n = document.querySelectorAll('[data-overlay]:not([hidden])').length;
        if (nav.state.aiOpen) { n += 1; }
        if (typeof nav.detailDepth === 'function') { n += nav.detailDepth(); }
        if (document.querySelector('.wheel.is-open')) { n += 1; }
        return n;
    }

    function sync() {
        queued = false;
        if (ours > 0) { return; }                    // a step is on its way out: its popstate syncs again

        var n = open();

        if (n > depth) {
            /* Closed by "back" and still sliding away: not a new layer. */
            if (Date.now() < settling) { later(160); return; }
            while (depth < n) {
                depth += 1;
                window.history.pushState({ ownifyLayer: depth }, '');
            }
        } else if (n < depth) {
            var out = depth - n;
            depth = n;
            ours += 1;
            window.history.go(-out);
        }
    }

    function later(ms) {
        if (queued) { return; }
        queued = true;
        window.setTimeout(sync, ms || 0);
    }

    window.addEventListener('popstate', function () {
        if (ours > 0) {                              // our own step taken out
            ours -= 1;
            later(0);
            return;
        }
        if (depth === 0) { return; }                 // a step from before a reload: nothing is open

        depth -= 1;
        settling = Date.now() + 700;

        if (document.querySelector('.wheel.is-open') && window.OwnifyWheel && window.OwnifyWheel.close) {
            window.OwnifyWheel.close();
        } else {
            document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
        }
        later(760);                                  // one that stayed open gets its step back
    });

    /* What can change the count: a popup shown or hidden, the deck's own
       states, the wheel. Counted once per frame, however much changed. */
    new MutationObserver(function () { later(0); }).observe(document.body, {
        subtree: true, attributes: true,
        attributeFilter: ['hidden', 'data-ai-state', 'data-detail-state', 'aria-expanded']
    });
    document.addEventListener('click', function () { later(60); }, true);
    nav.onRefresh(function () { later(0); });
})();
