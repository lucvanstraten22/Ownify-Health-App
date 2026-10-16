/**
 * pull-sync.js — Gezondheid, pulled down from the top, syncs.
 *
 *   pull      the page follows the finger with growing resistance, and a
 *             glass circle comes out from under the header; its ring fills
 *             towards the point where letting go syncs, and there its arrow
 *             turns to point up
 *   let go    past that point: one short haptic tick where the browser has
 *             one, and the circle opens into a capsule under the header —
 *             "Aan het synchroniseren…" with a turning arc — that stays for
 *             as long as the sync really takes. Short of it: back, nothing.
 *   then      "Gesynchroniseerd" (a source was asked for new data and gave
 *             it), or "Bijgewerkt" (Ownify's own data read again, nothing
 *             to ask): a moment, and the capsule slides away. Failed:
 *             "Synchroniseren mislukt" and Opnieuw, the reason read out.
 *
 * What a sync is, honestly: every connected source the server can ask for
 * new data on request — a cloud source with its own sync endpoint (Polar,
 * api/integrations/polar/sync.php) — is asked, one after the other; then the
 * page's scores, cards and Verloop are fetched again and swapped in place,
 * as health-rating.js and goals.js do (no reload, which would land on
 * Overzicht). A phone's Health Connect cannot be reached from a browser: its
 * phone sends on its own, so for it this is a refresh, and the capsule says
 * "Bijgewerkt", not "Gesynchroniseerd". There is no progress to report — one
 * request per source, then one page — so the arc turns; it never fills to
 * pretend.
 *
 * The gesture is the deck's (navigation-core.js), on its vertical axis: a
 * pull down that starts on Gezondheid while it is scrolled to the top, with
 * nothing in front of it. The assistant's is a pull UP from the dock, the
 * rail's is sideways, so the three never meet; anything else — a scroll —
 * stays the browser's.
 *
 * The app does the same, and also syncs its own phone (PullSync.kt).
 */

(function () {
    'use strict';

    var nav = window.AppNav;
    if (!nav || !nav.deck) { return; }

    var page = nav.deck.querySelector('[data-page="health"]');
    var root = page && page.querySelector('[data-pull-sync]');
    var scroller = page && page.querySelector('[data-scroller]');
    var content = scroller && scroller.querySelector('[data-pull-content]');
    if (!root || !scroller || !content) { return; }

    var pill  = root.querySelector('[data-pull-pill]');
    var label = root.querySelector('[data-pull-label]');
    var retry = root.querySelector('[data-pull-retry]');
    var live  = root.querySelector('[data-pull-live]');

    var config = {};
    try { config = JSON.parse(root.getAttribute('data-pull-sync') || '{}'); } catch (error) { config = {}; }
    var text = config.text || {};
    var sources = (config.sources || []).filter(function (source) {
        return /^[a-z0-9_]+$/.test(source.provider || '');
    });

    /* ---------------------------------------------------------- settings */

    var THRESHOLD = 72;     // px the page must come down for letting go to sync
    var HELD = 56;          // px it stays down while the capsule is there: 36 + 10 above and below
    var REACH = 180;        // px it can never pass, however far the finger goes
    var SETTLE_MS = 420;    // letting go, opening, closing (--ease-out-strong)
    var MIN_MS = 600;       // the capsule is on screen at least this long, so it can be read
    var SHOWN_MS = 1400;    // how long "Gesynchroniseerd" stays
    var FAILED_MS = 9000;   // how long a failure stays, Opnieuw with it; a new pull retries too
    var CIRCLE = 36;

    /* ------------------------------------------------------------- state */

    var state = 'idle';     // idle | pulling | armed | syncing | done | failed
    var drag = null;        // { base } while a finger holds it
    var offset = 0;
    var timer = 0;
    var settleTimer = 0;
    var run = 0;            // the sync in progress, so a stale one cannot finish a newer one

    function reduced() {
        return nav.reduceMotion || document.documentElement.getAttribute('data-motion') === 'reduce';
    }

    function clamp(value, min, max) { return value < min ? min : (value > max ? max : value); }

    /** The page's resistance: one to one at first, then less and less, never past REACH. */
    function rubber(distance) {
        return distance <= 0 ? 0 : REACH * distance / (REACH + distance);
    }

    function setState(next) {
        state = next;
        root.setAttribute('data-state', next);
    }

    /** Where everything is for a page pulled down by `px`. */
    function paint(px) {
        offset = px;
        var held = state === 'syncing' || state === 'done' || state === 'failed';
        content.style.transform = px > 0.5 ? 'translate3d(0, ' + px.toFixed(1) + 'px, 0)' : '';
        content.classList.toggle('is-pulled', px > 0.5);
        root.style.setProperty('--pull', px.toFixed(1) + 'px');
        root.style.setProperty('--pull-in', held ? '1' : clamp((px - 8) / 40, 0, 1).toFixed(3));
        root.style.setProperty('--pull-p', held ? '1' : clamp(px / THRESHOLD, 0, 1).toFixed(3));
    }

    /** To `px`, eased — or at once with reduced motion. */
    function settle(px) {
        window.clearTimeout(settleTimer);
        var still = reduced();
        root.classList.add('is-settling');
        if (!still) { content.classList.add('is-settling'); }
        paint(px);
        settleTimer = window.setTimeout(function () {
            root.classList.remove('is-settling');
            content.classList.remove('is-settling');
        }, still ? 200 : SETTLE_MS);
    }

    /** The capsule as wide as what it says; the circle when it says nothing. */
    function fit(open) {
        if (!pill) { return; }
        if (!open) { pill.style.width = CIRCLE + 'px'; return; }
        var width = pill.style.width;
        pill.style.width = 'auto';
        var wanted = Math.ceil(pill.scrollWidth) + 1;
        pill.style.width = width || CIRCLE + 'px';
        void pill.offsetWidth;
        pill.style.width = wanted + 'px';
    }

    function say(words, aloud) {
        if (label) { label.textContent = words; }
        if (live && aloud) { live.textContent = aloud; }
    }

    /** One short tick, where the browser can (Android's Chrome; not Safari). Never twice a gesture. */
    function tick() {
        try {
            if (typeof navigator.vibrate === 'function') { navigator.vibrate(10); }
        } catch (error) { /* no haptics here: the capsule says it */ }
    }

    /* ------------------------------------------------------------- sync */

    function csrf() {
        var panel = document.querySelector('[data-account]');
        return panel ? (panel.getAttribute('data-csrf') || '') : '';
    }

    /** One source asked for new data: { ok, error }. */
    function ask(source) {
        var body = new FormData();
        body.append('csrf', csrf());

        return fetch('api/integrations/' + source.provider + '/sync.php', {
            method: 'POST', body: body, credentials: 'same-origin', cache: 'no-store'
        })
            .then(function (response) {
                return response.json().catch(function () { return { ok: false }; });
            })
            .then(function (answer) {
                if (answer && answer.ok) { return { ok: true }; }
                return { ok: false, error: (answer && (answer.error || answer.message)) || text.error };
            })
            .catch(function () { return { ok: false, error: text.offline }; });
    }

    function each(rootNode, selector, fn) {
        Array.prototype.forEach.call(rootNode.querySelectorAll(selector), fn);
    }

    /**
     * The page as the server renders it now, its health parts swapped in:
     * Gezondheid's intro, its three cards and its Verloop, Overzicht's
     * score, and the header's list of sources with their status. Resolves
     * true when they show what is stored.
     */
    function refresh() {
        var parts = window.OwnifyRefresh;

        return fetch(window.location.href.split('#')[0], {
            credentials: 'same-origin', cache: 'no-store', headers: { 'Accept': 'text/html' }
        })
            .then(function (response) {
                if (!response.ok) { throw new Error('HTTP ' + response.status); }
                return response.text();
            })
            .then(function (html) {
                var fresh = new DOMParser().parseFromString(html, 'text/html');
                var nextPage = fresh.querySelector('[data-page="health"]');
                if (!nextPage) { throw new Error('no page'); }

                var lede = page.querySelector('.page-intro__lede');
                var nextLede = nextPage.querySelector('.page-intro__lede');
                if (lede && nextLede) { lede.textContent = nextLede.textContent; }

                if (parts) {
                    each(page, '.health-card[data-detail-open]', function (card) {
                        var id = card.getAttribute('data-detail-open');
                        parts.replace(card, nextPage.querySelector('.health-card[data-detail-open="' + id + '"]'));
                    });

                    parts.replace(document.querySelector('[data-page="overview"] .card--hero'),
                        fresh.querySelector('[data-page="overview"] .card--hero'));
                }

                /* The Verloop: a new card, wired as on page load. */
                var history = page.querySelector('[data-health-history]');
                var nextHistory = nextPage.querySelector('[data-health-history]');
                if (history && nextHistory) {
                    var node = document.importNode(nextHistory, true);
                    history.parentNode.replaceChild(node, history);
                    node.classList.add('is-visible');
                    each(node, '.reveal', function (item) { item.classList.add('is-visible'); });
                    each(node, '[data-chart]', function (chart) { if (window.OwnifyTrend) { window.OwnifyTrend.wire(chart); } });
                    if (window.OwnifyHistory) { window.OwnifyHistory.health(node); }
                }

                /* The header's quick look: which sources, and how they are. */
                var list = document.querySelector('[data-devices] .devices__panel > :first-child');
                var nextList = fresh.querySelector('[data-devices] .devices__panel > :first-child');
                if (list && nextList && list.parentNode) {
                    list.parentNode.replaceChild(document.importNode(nextList, true), list);
                }

                return true;
            })
            .catch(function () { return false; });
    }

    /** Asks every source that can be asked, then reads the page again. */
    function sync() {
        var steps = Promise.resolve();
        var synced = 0;
        var problem = null;

        sources.forEach(function (source) {
            steps = steps.then(function () {
                return ask(source).then(function (answer) {
                    if (answer.ok) { synced++; }
                    else if (!problem) { problem = source.label ? source.label + ': ' + answer.error : answer.error; }
                });
            });
        });

        // Read again whatever happened: what did arrive is shown either way.
        return steps
            .then(refresh)
            .then(function (shown) {
                if (!shown && !problem) { problem = text.offline; }
                return { synced: synced, problem: problem };
            });
    }

    /* ------------------------------------------------------- the stages */

    function start() {
        window.clearTimeout(timer);
        var mine = ++run;
        var began = Date.now();

        setState('syncing');
        if (retry) { retry.hidden = true; }
        if (pill) { pill.removeAttribute('title'); }
        say(text.syncing, text.syncing);
        settle(HELD);
        fit(true);

        sync().then(function (result) {
            // The capsule stays for the real duration — and long enough to be read.
            var wait = Math.max(0, MIN_MS - (Date.now() - began));
            window.setTimeout(function () {
                if (mine !== run) { return; }
                if (result.problem) { failed(result.problem); }
                else { done(result.synced > 0 ? text.synced : text.refreshed); }
            }, wait);
        });
    }

    function done(words) {
        setState('done');
        say(words, words);
        fit(true);
        timer = window.setTimeout(dismiss, SHOWN_MS);
    }

    function failed(reason) {
        setState('failed');
        if (retry) { retry.hidden = false; }
        if (pill) { pill.title = reason; }
        say(text.failed, text.failed + '. ' + reason);
        fit(true);
        timer = window.setTimeout(dismiss, FAILED_MS);
    }

    function dismiss() {
        window.clearTimeout(timer);
        if (retry) { retry.hidden = true; }
        setState('idle');
        fit(false);
        settle(0);
    }

    /* ----------------------------------------------------- the gesture */

    function atTop() { return scroller.scrollTop <= 0; }

    nav.register('y', {
        canStart: function (ctx) {
            return ctx.dy > 0
                && (state === 'idle' || state === 'failed')
                && !nav.state.aiOpen && !nav.state.detailOpen && !nav.overlayOpen()
                && !!nav.pages && nav.pages.currentId() === 'health'
                && nav.deck.dataset.pageState !== 'moving'
                && !!ctx.target && page.contains(ctx.target)
                && atTop();
        },

        begin: function () {
            window.clearTimeout(timer);
            window.clearTimeout(settleTimer);
            root.classList.remove('is-settling');
            content.classList.remove('is-settling');
            // Pulled again while it says it failed: from where the page is held.
            var base = state === 'failed' ? offset : 0;
            if (state === 'failed') {
                if (retry) { retry.hidden = true; }
                fit(false);
            }
            setState('pulling');
            drag = { base: base };
        },

        move: function (ctx) {
            if (!drag) { return; }
            var px = clamp(drag.base + rubber(ctx.dy), 0, REACH);
            var armed = px >= THRESHOLD;
            if (armed !== (state === 'armed')) {
                setState(armed ? 'armed' : 'pulling');
                if (live) { live.textContent = armed ? (text.release || '') : ''; }
            }
            paint(px);
        },

        end: function () {
            var armed = state === 'armed';
            drag = null;
            if (armed) {
                tick();
                start();
                return;
            }
            setState('idle');
            settle(0);
        },

        cancel: function () {
            drag = null;
            setState('idle');
            settle(0);
        }
    });

    /* Scrolled to the very top, the page does not bounce on a pull down —
       the pull is ours from its first pixel, so a phone's browser cannot
       take it for its own scroll or its own pull-to-refresh. Elsewhere the
       page scrolls and bounces as before. */
    function edge() { scroller.classList.toggle('is-at-top', atTop()); }
    scroller.addEventListener('scroll', edge, { passive: true });
    edge();

    /* Opnieuw, and the same sync for keyboards and screen readers. */
    if (pill) {
        pill.addEventListener('click', function () {
            if (state === 'failed') { start(); }
        });
    }

    var button = page.querySelector('[data-pull-start]');
    if (button) {
        button.addEventListener('click', function () {
            if (state === 'idle' || state === 'failed') { start(); }
        });
    }

    paint(0);

    /** For the browser tests: where it stands. */
    window.OwnifyPullSync = {
        state: function () { return state; },
        offset: function () { return offset; }
    };
}());
