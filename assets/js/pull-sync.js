/**
 * pull-sync.js — any of the five pages (Gezondheid, Doelen, Overzicht,
 * Community, Instellingen), pulled down from its top, syncs.
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
 * api/integrations/polar/sync.php) — is asked, one after the other; then
 * the page is fetched once and every part that shows what a sync can change
 * is swapped in place — Gezondheid's cards and Verloop, Overzicht's score
 * and goal, Doelen's goals, Community's boards, the sources in Instellingen
 * and in the header — as health-rating.js and goals.js do (no reload, which
 * would land on Overzicht). A phone's Health Connect cannot be reached from a browser: its
 * phone sends on its own, so for it this is a refresh, and the capsule says
 * "Bijgewerkt", not "Gesynchroniseerd". There is no progress to report — one
 * request per source, then one page — so the arc turns; it never fills to
 * pretend.
 *
 * The gesture is the deck's (navigation-core.js), on its vertical axis: a
 * pull down that starts on the page in front while it is scrolled to its
 * top, with nothing in front of it. The capsule is the deck's, one for all
 * five, under the header: the page in front makes room for it — and if the
 * rail moves on while it syncs, the page arriving makes the room instead. The assistant's is a pull UP from the dock, the
 * rail's is sideways, so the three never meet; anything else — a scroll —
 * stays the browser's.
 *
 * The app does the same, and also syncs its own phone (PullSync.kt).
 */

(function () {
    'use strict';

    var nav = window.AppNav;
    if (!nav || !nav.deck) { return; }

    var root = nav.deck.querySelector('[data-pull-sync]');
    if (!root) { return; }

    var pages = Array.prototype.slice.call(nav.deck.querySelectorAll('[data-page]')).filter(function (page) {
        return page.querySelector('[data-scroller] [data-pull-content]');
    });
    if (!pages.length) { return; }

    function scrollerOf(page) { return page.querySelector('[data-scroller]'); }
    function contentOf(page) { return page.querySelector('[data-scroller] [data-pull-content]'); }

    /** The page in front, when it is one of the five. */
    function front() {
        var id = nav.pages ? nav.pages.currentId() : document.documentElement.dataset.activePage;
        for (var i = 0; i < pages.length; i++) {
            if (pages[i].dataset.page === id) { return pages[i]; }
        }
        return null;
    }

    var content = null;     // the column that is pulled, or held, now

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
        if (content) {
            content.style.transform = px > 0.5 ? 'translate3d(0, ' + px.toFixed(1) + 'px, 0)' : '';
            content.classList.toggle('is-pulled', px > 0.5);
        }
        root.style.setProperty('--pull', px.toFixed(1) + 'px');
        root.style.setProperty('--pull-in', held ? '1' : clamp((px - 8) / 40, 0, 1).toFixed(3));
        root.style.setProperty('--pull-p', held ? '1' : clamp(px / THRESHOLD, 0, 1).toFixed(3));
    }

    /** To `px`, eased — or at once with reduced motion. */
    function settle(px) {
        window.clearTimeout(settleTimer);
        var still = reduced();
        var moving = content;
        root.classList.add('is-settling');
        if (!still && moving) { moving.classList.add('is-settling'); }
        paint(px);
        settleTimer = window.setTimeout(function () {
            root.classList.remove('is-settling');
            if (moving) { moving.classList.remove('is-settling'); }
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

    /** A part replaced by its fresh copy. */
    function swap(current, next) {
        if (!current || !next || !current.parentNode) { return null; }
        var node = document.importNode(next, true);
        current.parentNode.replaceChild(node, current);
        return node;
    }

    /**
     * The page as the server renders it now, fetched once, and every part
     * that shows what a sync can change swapped in, wired as on page load:
     * Gezondheid's intro, cards and Verloop, Overzicht's score, the Voeding
     * page and the boards (health-rating.js); every goal (goals.js); the
     * sources in Instellingen — open or closed as they were — and in the
     * header. Resolves true when they show what is stored.
     */
    function refresh() {
        return fetch(window.location.href.split('#')[0], {
            credentials: 'same-origin', cache: 'no-store', headers: { 'Accept': 'text/html' }
        })
            .then(function (response) {
                if (!response.ok) { throw new Error('HTTP ' + response.status); }
                return response.text();
            })
            .then(function (html) {
                var fresh = new DOMParser().parseFromString(html, 'text/html');
                if (!fresh.querySelector('[data-deck]')) { throw new Error('no page'); }
                if (window.OwnifyNotices) { window.OwnifyNotices.read(fresh); }

                if (window.OwnifyRefresh && window.OwnifyRefresh.scores) { window.OwnifyRefresh.scores(fresh); }
                if (window.OwnifyGoals) { window.OwnifyGoals.apply(fresh); }

                /* The Verloop: a new card, wired as on page load. */
                var history = swap(document.querySelector('[data-page="health"] [data-health-history]'),
                    fresh.querySelector('[data-page="health"] [data-health-history]'));
                if (history) {
                    history.classList.add('is-visible');
                    each(history, '.reveal', function (item) { item.classList.add('is-visible'); });
                    each(history, '[data-chart]', function (chart) { if (window.OwnifyTrend) { window.OwnifyTrend.wire(chart); } });
                    if (window.OwnifyHistory) { window.OwnifyHistory.health(history); }
                }

                /* Instellingen: each source with its last sync, open or closed as it was. */
                var nextSources = fresh.querySelectorAll('[data-integration]');
                each(document, '[data-integration]', function (card, n) {
                    var head = card.querySelector('[data-integration-toggle]');
                    var open = head && head.getAttribute('aria-expanded') === 'true';
                    var node = swap(card, nextSources[n]);
                    var nextHead = node && node.querySelector('[data-integration-toggle]');
                    if (nextHead && open) {
                        nextHead.setAttribute('aria-expanded', 'true');
                        var body = document.getElementById(nextHead.getAttribute('aria-controls'));
                        if (body) { body.hidden = false; }
                    }
                });
                var values = fresh.querySelectorAll('[data-page="settings"] .settings-row__value');
                each(document, '[data-page="settings"] .settings-row__value', function (value, n) {
                    if (values[n]) { value.textContent = values[n].textContent; }
                });

                /* The header's quick look: which sources, and how they are. */
                swap(document.querySelector('[data-devices] .devices__panel > :first-child'),
                    fresh.querySelector('[data-devices] .devices__panel > :first-child'));

                /* The sources a pull asks: one disconnected meanwhile is not asked again. */
                var nextConfig = fresh.querySelector('[data-pull-sync]');
                if (nextConfig) {
                    try {
                        var next = JSON.parse(nextConfig.getAttribute('data-pull-sync') || '{}');
                        sources = (next.sources || []).filter(function (source) {
                            return /^[a-z0-9_]+$/.test(source.provider || '');
                        });
                    } catch (error) { /* keep the list it had */ }
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
        if (!content && front()) { content = contentOf(front()); }
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

    /**
     * The rail moved on while the capsule is there: the page that leaves
     * gives its room back, the one that arrives makes it, so the capsule
     * never sits on a title.
     */
    function follow() {
        var page = front();
        var next = page ? contentOf(page) : null;
        var held = state === 'syncing' || state === 'done' || state === 'failed';
        if (!held || !next || next === content) { return; }
        if (content) {
            content.classList.remove('is-settling', 'is-pulled');
            content.style.transform = '';
        }
        content = next;
        paint(offset);
    }

    new MutationObserver(follow).observe(document.documentElement, { attributes: true, attributeFilter: ['data-active-page'] });

    /* ----------------------------------------------------- the gesture */

    function atTop(page) {
        var scroller = page && scrollerOf(page);
        return !!scroller && scroller.scrollTop <= 0;
    }

    nav.register('y', {
        canStart: function (ctx) {
            return ctx.dy > 0
                && (state === 'idle' || state === 'failed')
                && !nav.state.aiOpen && !nav.state.detailOpen && !nav.overlayOpen()
                && nav.deck.dataset.pageState !== 'moving'
                && !!front() && !!ctx.target && front().contains(ctx.target)
                && atTop(front());
        },

        begin: function () {
            window.clearTimeout(timer);
            window.clearTimeout(settleTimer);
            root.classList.remove('is-settling');
            if (content) { content.classList.remove('is-settling'); }
            content = contentOf(front());
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
    pages.forEach(function (page) {
        var scroller = scrollerOf(page);
        function edge() { scroller.classList.toggle('is-at-top', scroller.scrollTop <= 0); }
        scroller.addEventListener('scroll', edge, { passive: true });
        edge();
    });

    /* Under the sheet or a detail page the capsule, and its button, are out of reach too. */
    nav.onRefresh(function () {
        var covered = !nav.sideBySide() && (nav.state.aiOpen || nav.state.detailOpen);
        root.inert = covered;
        if (covered) { root.setAttribute('aria-hidden', 'true'); } else { root.removeAttribute('aria-hidden'); }
    });

    /* Opnieuw, and the same sync for keyboards and screen readers. */
    if (pill) {
        pill.addEventListener('click', function () {
            if (state === 'failed') { start(); }
        });
    }

    var button = root.querySelector('[data-pull-start]');
    if (button) {
        button.addEventListener('click', function () {
            if (state === 'idle' || state === 'failed') { start(); }
        });
    }

    paint(0);

    /** For the browser tests: where it stands. */
    window.OwnifyPullSync = {
        state: function () { return state; },
        offset: function () { return offset; },
        /* Reads the page again and swaps in what it shows, where the user is
           (settings.js, after disconnecting): resolves true when it did. */
        refresh: refresh
    };
}());
