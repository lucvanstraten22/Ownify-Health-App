/**
 * page-navigation.js — horizontal movement between the five primary pages.
 *
 *   Gezondheid ↔ Doelen ↔ Overzicht ↔ Community ↔ Instellingen
 *
 * The rail holds every page side by side and moves as a single element, so a
 * swipe costs one transform. Each page keeps its own scroller, which is what
 * preserves its scroll position when you leave it and come back.
 *
 * The rail is always either on a page, on its way to one, or held by a
 * finger. Nothing ever makes the next swipe or tap wait: a finger can catch
 * the rail mid-way and carry on from where it is, and a tab can redirect it
 * mid-way. A swipe moves at most one page, and a released one always ends on
 * a page — never between two.
 *
 * It knows nothing about the assistant beyond one flag: while the sheet is
 * open, the rail refuses to start a gesture.
 */

(function () {
    'use strict';

    var nav = window.AppNav;
    if (!nav || !nav.deck) { return; }

    var deck = nav.deck;
    var rail = deck.querySelector('[data-rail]');
    var dock = deck.querySelector('[data-ai-grab]');
    var header = deck.querySelector('[data-header-shared]');
    if (!rail) { return; }

    var pages = Array.prototype.slice.call(rail.querySelectorAll('[data-page]'));
    if (!pages.length) { return; }

    /* -------------------------------------------------------------- state */

    var index = 0;           // the page shown, or being moved to
    var drag = null;         // { from, base, position } while a finger holds the rail
    var settleTimer = null;  // the move under way, until it has landed

    for (var i = 0; i < pages.length; i++) {
        if (pages[i].dataset.page === document.documentElement.dataset.activePage) { index = i; }
    }

    /* ------------------------------------------------------------ helpers */

    function place(position) {
        rail.style.transform = 'translate3d(' + (-position * 100) + '%, 0, 0)';
    }

    /** Where the rail is right now, in pages: mid-way to one included. */
    function shownPosition() {
        var width = rail.offsetWidth || window.innerWidth;
        return width ? -nav.translation(rail).x / width : index;
    }

    function currentId() {
        return pages[index].dataset.page;
    }

    function indexOfId(id) {
        for (var n = 0; n < pages.length; n++) {
            if (pages[n].dataset.page === id) { return n; }
        }
        return -1;
    }

    function scrollerOf(page) {
        return page.querySelector('[data-scroller]');
    }

    /** Offscreen pages — and everything under the sheet — stay unreachable. */
    function refresh() {
        pages.forEach(function (page, n) {
            var hidden = n !== index || nav.state.aiOpen || nav.state.detailOpen;
            page.inert = hidden;
            if (hidden) { page.setAttribute('aria-hidden', 'true'); }
            else { page.removeAttribute('aria-hidden'); }
        });

        if (dock) {
            dock.inert = nav.state.aiOpen;
            if (nav.state.aiOpen) { dock.setAttribute('aria-hidden', 'true'); }
            else { dock.removeAttribute('aria-hidden'); }
        }

        /* The pages' shared header is theirs: covered, and out of reach, by
           whatever covers them. */
        if (header) {
            var covered = nav.state.aiOpen || nav.state.detailOpen;
            header.inert = covered;
            if (covered) { header.setAttribute('aria-hidden', 'true'); }
            else { header.removeAttribute('aria-hidden'); }
        }
    }

    function syncTabs() {
        var id = currentId();

        Array.prototype.forEach.call(deck.querySelectorAll('[data-nav]'), function (tab) {
            var isActive = tab.getAttribute('data-nav') === id;
            tab.classList.toggle('is-active', isActive);
            if (isActive) { tab.setAttribute('aria-current', 'page'); }
            else { tab.removeAttribute('aria-current'); }
        });
    }

    /* ---------------------------------------------------------- the glass */

    /**
     * The tab bar's selected pane is one piece of glass, not five panes that
     * take turns: choosing a tab sends it there from wherever it is, and a
     * swipe carries it along under the finger. It moves on a damped spring
     * in tab units — the same spring, the same numbers, as the Android app's
     * — so a far tab is reached in the same time as the next one, only
     * faster. While it moves it stretches along the bar with its speed and
     * thins a little, and it settles with the spring's small overshoot.
     *
     * The rail and the bar are built from the same list, so a page's place
     * on the rail is its tab's place in the bar. Everything measured is
     * measured when the bar changes size; a frame only writes a transform.
     */
    var glass = (function () {
        var bar = deck.querySelector('.tabbar');
        var pane = bar && bar.querySelector('[data-tab-glass]');
        var tabs = bar ? Array.prototype.slice.call(bar.querySelectorAll('[data-nav]')) : [];
        if (!pane || !tabs.length) { return null; }

        // spring(dampingRatio 0.8, stiffness 340): arrives in about 190 ms,
        // 1.5 % past the mark, at rest by about 430 ms.
        var STIFFNESS = 340;
        var DAMPING = 0.8;
        // At most 30 % longer at speed (tabs per second), a quarter of that thinner.
        var STRETCH = 0.30;
        var STRETCH_SPEED = 9;
        var THIN = 0.25;

        var omega = Math.sqrt(STIFFNESS);
        var decay = DAMPING * omega;
        var ring = omega * Math.sqrt(1 - DAMPING * DAMPING);

        var slots = [];       // each tab's pane: its left edge, px from the bar
        var x = index;        // where the glass is, in tabs
        var v = 0;            // and how fast it is going, in tabs per second
        var target = index;
        var from = 0;         // the spring's start: offset from the target, and speed
        var fromSpeed = 0;
        var startedAt = 0;
        var frame = 0;

        function measure() {
            var box = bar.getBoundingClientRect();
            var glow = tabs[0].querySelector('.tab__glow');
            var inset = glow ? parseFloat(getComputedStyle(glow).top) || 0 : 0;
            var first = tabs[0].getBoundingClientRect();
            // The pane is placed inside the bar's border (the desktop bar has one).
            var originX = box.left + bar.clientLeft;
            var originY = box.top + bar.clientTop;

            slots = tabs.map(function (tab) {
                return tab.getBoundingClientRect().left - originX + inset;
            });
            pane.style.top = (first.top - originY + inset) + 'px';
            pane.style.width = Math.max(0, first.width - 2 * inset) + 'px';
            pane.style.height = Math.max(0, first.height - 2 * inset) + 'px';
            draw();
        }

        /** The pane's left edge at a place in tabs, between and past the ends too. */
        function left(at) {
            var last = slots.length - 1;
            if (last < 1) { return slots[0] || 0; }
            var n = nav.clamp(Math.floor(at), 0, last - 1);
            return slots[n] + (slots[n + 1] - slots[n]) * (at - n);
        }

        function draw() {
            if (!slots.length) { return; }
            var long = 1 + STRETCH * (1 - Math.exp(-Math.abs(v) / STRETCH_SPEED));
            var thin = 1 - THIN * (long - 1);
            pane.style.transform = 'translate3d(' + left(x).toFixed(2) + 'px, 0, 0) scale('
                + long.toFixed(4) + ', ' + thin.toFixed(4) + ')';
        }

        /** The spring at a moment: x and v, from its start. */
        function sample(now) {
            var t = Math.max(0, now - startedAt) / 1000;
            var a = from;
            var b = (fromSpeed + decay * a) / ring;
            var e = Math.exp(-decay * t);
            var cos = Math.cos(ring * t);
            var sin = Math.sin(ring * t);
            x = target + e * (a * cos + b * sin);
            v = e * (-decay * (a * cos + b * sin) + ring * (b * cos - a * sin));
        }

        function tick(now) {
            frame = 0;
            sample(now);
            if (Math.abs(x - target) < 0.001 && Math.abs(v) < 0.01) {
                x = target;
                v = 0;
                draw();
                return;
            }
            draw();
            frame = window.requestAnimationFrame(tick);
        }

        /** Sends the glass to a place in tabs, from wherever it is and at whatever speed. */
        function to(place, now) {
            var at = window.performance.now();
            if (frame) { sample(at); }

            if (now || nav.reduceMotion) {
                window.cancelAnimationFrame(frame);
                frame = 0;
                x = target = place;
                v = 0;
                draw();
                return;
            }
            if (place === target && !frame && x === place) { return; }

            from = x - place;
            fromSpeed = v;
            target = place;
            startedAt = at;
            if (!frame) { frame = window.requestAnimationFrame(tick); }
        }

        measure();
        bar.classList.add('tabbar--glass');

        if ('ResizeObserver' in window) {
            new ResizeObserver(measure).observe(bar);
        } else {
            window.addEventListener('resize', measure);
        }

        return { to: to };
    }());

    /* ------------------------------------------------------------- motion */

    /**
     * Sends the rail to a page from wherever it is — resting, under a finger,
     * or on its way somewhere else. Everything about arriving happens now:
     * the page is named, its tab lit, and it can be tapped and scrolled while
     * it slides in. Only the idle mark waits for the slide to finish.
     */
    function settle(target) {
        target = nav.clamp(target, 0, pages.length - 1);
        drag = null;
        index = target;

        rail.classList.remove('is-dragging');
        deck.dataset.pageState = 'moving';
        place(target);

        document.documentElement.dataset.activePage = currentId();
        syncTabs();
        if (glass) { glass.to(target); }
        refresh();

        window.clearTimeout(settleTimer);
        settleTimer = window.setTimeout(function () {
            settleTimer = null;
            deck.dataset.pageState = 'idle';
            nav.refresh();
        }, nav.duration + 40);
    }

    function goToId(id) {
        var target = indexOfId(id);
        // A finger on the rail has the say until it lifts.
        if (target < 0 || target === index || drag) { return; }
        settle(target);
    }

    /* ------------------------------------------------------------- axis x */

    nav.register('x', {
        canStart: function () {
            return !nav.state.aiOpen && !nav.state.detailOpen && pages.length > 1;
        },

        begin: function () {
            // Caught on its way to a page, the rail stays where it is and the
            // swipe carries on from there — no jump, no waiting.
            var from = settleTimer ? shownPosition() : index;

            window.clearTimeout(settleTimer);
            settleTimer = null;

            rail.classList.add('is-dragging');
            place(from);

            drag = {
                from: from,
                // The page the swipe belongs to: the one the rail was on or
                // heading to, unless a tab had sent it further than that.
                base: Math.abs(from - index) < 1 ? index : Math.round(from),
                position: from
            };
            deck.dataset.pageState = 'moving';
        },

        move: function (ctx) {
            if (!drag) { return; }

            // One page either way, and not past the ends.
            var lowest = Math.max(0, drag.base - 1);
            var highest = Math.min(pages.length - 1, drag.base + 1);

            drag.position = nav.clamp(drag.from - ctx.dx / ctx.width, lowest, highest);
            place(drag.position);
            // The glass goes where the finger takes the page.
            if (glass) { glass.to(drag.position); }
        },

        end: function (ctx) {
            if (!drag) { settle(index); return; }
            settle(nav.resolve(ctx, drag.base, drag.position, ctx.dx, ctx.vx, ctx.width));
        },

        cancel: function () {
            // Interrupted: back to the page it belongs to.
            settle(drag ? drag.base : index);
        }
    });

    /* --------------------------------------------------------- tab clicks */

    deck.addEventListener('click', function (event) {
        var tab = event.target.closest('[data-nav]');
        if (!tab) { return; }

        var id = tab.getAttribute('data-nav');

        if (id === currentId()) {
            // Re-tapping the page you are on returns you to its top.
            var scroller = scrollerOf(pages[index]);
            if (scroller) {
                scroller.scrollTo({ top: 0, behavior: nav.reduceMotion ? 'auto' : 'smooth' });
            }
            return;
        }

        goToId(id);
    });

    /* --------------------------------------------------------------- init */

    nav.pages = {
        currentId: currentId,
        goToId: goToId
    };

    nav.onRefresh(refresh);

    deck.dataset.pageState = 'idle';
    document.documentElement.dataset.activePage = currentId();
    syncTabs();
    refresh();
}());
