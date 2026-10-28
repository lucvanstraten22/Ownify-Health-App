/**
 * desktop.js — the desktop layout's two pages and the view switch (docs/DESKTOP.md).
 *
 * The layout itself is CSS (desktop.css) on <html data-layout>, which the
 * head sets before anything is painted (components/document-head.php):
 *
 *   phone        narrower than 768px: the phone's own, no switch
 *   desktop      the sidebar, the page, and a second page on the right
 *   phone-frame  the phone's layout chosen on a wide screen (Telefoon)
 *
 * In the desktop layout whatever opens — a detail page, OwnifyAI, Nieuw doel,
 * the account and Apparaten — opens as the second page, one at a time: this
 * closes the one in front before another opens, keeps what opened it marked
 * in the page, and tells the stylesheet when there is one (.has-second), so
 * from 1200px the page makes room beside it. Both stay live: what a second
 * page saves refreshes the page in place (goals.js, settings.js,
 * pull-sync.js), so the two always show the same.
 *
 * The switch changes only the layout: no reload, no navigation — the page,
 * the open second page and every scroll position stay as they are.
 */
(function () {
    'use strict';

    var root = document.documentElement;
    var nav = window.AppNav;
    if (!nav || !nav.deck) { return; }

    var deck = nav.deck;
    var panels = {
        account: document.querySelector('[data-account]'),
        devices: document.querySelector('[data-devices]'),
        wizard: document.querySelector('[data-goal-wizard]')
    };
    var closers = {
        account: '[data-account-close].account__close',
        devices: '[data-devices-close]',
        wizard: '[data-wizard-close].account__close'
    };
    var source = null;   // what opened the second page, marked in the page

    /* ------------------------------------------------------------- state */

    function panelOpen(name) {
        var panel = panels[name];
        return !!panel && !panel.hidden && panel.classList.contains('is-open');
    }

    /** Which second page is open, if any. */
    function openKind() {
        if (nav.state.detailOpen) { return 'detail'; }
        if (nav.state.aiOpen) { return 'ai'; }
        for (var name in panels) {
            if (panelOpen(name)) { return name; }
        }
        return null;
    }

    function kindOf(target) {
        if (target.closest('[data-detail-open]')) { return 'detail'; }
        if (target.closest('[data-ai-open]')) { return 'ai'; }
        if (target.closest('[data-account-open]')) { return 'account'; }
        if (target.closest('[data-devices-open]')) { return 'devices'; }
        if (target.closest('[data-goal-add]')) { return 'wizard'; }
        return null;
    }

    function close(kind) {
        if (kind === 'detail' && nav.details) { nav.details.close(false); return; }
        if (kind === 'ai' && nav.ai) { nav.ai.close(false); return; }
        var panel = panels[kind];
        var button = panel && panel.querySelector(closers[kind]);
        if (button) { button.click(); }
    }

    function mark(element) {
        if (source && source !== element) { source.classList.remove('is-second-source'); }
        source = element || null;
        if (source && nav.isDesktop()) { source.classList.add('is-second-source'); }
    }

    function opened() { return nav.isDesktop() && openKind() !== null; }

    /* One second page closing as the next opens passes through a moment with
       neither (the observer runs between the click's own handlers): "none"
       waits until the click is over, so nothing flickers or loses its mark. */
    var pending = 0;
    function sync() {
        if (opened()) {
            window.clearTimeout(pending);
            root.classList.add('has-second');
            return;
        }
        window.clearTimeout(pending);
        pending = window.setTimeout(function () {
            if (opened()) { return; }
            root.classList.remove('has-second');
            // In the phone's layout the mark only rests, for the way back.
            if (source && (nav.isDesktop() || openKind() === null)) { mark(null); }
        }, 0);
    }

    /* -------------------------------------------- one second page at a time */

    document.addEventListener('click', function (event) {
        if (!nav.isDesktop() || !event.target.closest) { return; }
        var kind = kindOf(event.target);
        if (!kind) { return; }

        var current = openKind();

        /* The sidebar's buttons open their page and, pressed again, close it. */
        if (current === kind && kind !== 'detail' && event.target.closest('.app-dock')) {
            event.preventDefault();
            event.stopPropagation();
            close(kind);
            return;
        }

        if (current && current !== kind) { close(current); }

        var opener = event.target.closest('[data-detail-open], [data-ai-open], [data-account-open], [data-devices-open], [data-goal-add]');
        // The sidebar's own buttons are marked by their place, not a ring; a
        // small link in a card that opens marks the card it belongs to.
        var marked = opener && !opener.closest('.app-dock') ? (opener.closest('.card--opens') || opener) : null;
        mark(marked);
    }, true);

    /* Every way a second page opens or closes ends in one of these. */
    new MutationObserver(sync).observe(deck, { attributes: true, attributeFilter: ['data-detail-state', 'data-ai-state'] });
    Object.keys(panels).forEach(function (name) {
        if (panels[name]) {
            new MutationObserver(sync).observe(panels[name], { attributes: true, attributeFilter: ['class', 'hidden'] });
        }
    });

    /* ------------------------------------------------ the sidebar's length */

    /* The sidebar ends where the page's last block ends (desktop.css reads
       --nav-h, in screen pixels): never shorter than its own rows, never
       taller than the frame — a page that scrolls gets the frame's height.
       It eases to the new length when the page changes or grows. */
    var dock = document.querySelector('.app-dock');

    function px(name) { return parseFloat(window.getComputedStyle(root).getPropertyValue(name)) || 0; }

    function fitSidebar() {
        if (!dock || !nav.isDesktop()) { root.style.removeProperty('--nav-h'); return; }

        var page = document.querySelector('.rail-window [data-page="' + (root.dataset.activePage || '') + '"]')
            || document.querySelector('.rail-window [data-page]:not([inert])');
        var shell = page && page.querySelector('.app__main > .shell');
        var scroller = page && page.querySelector('[data-scroller]');
        if (!shell) { return; }

        var top = px('--frame-y');
        var height = px('--frame-h');

        // Its shortest: as tall as its own rows, measured without the ease.
        var transition = dock.style.transition;
        dock.style.transition = 'none';
        dock.style.height = 'auto';
        var rows = dock.getBoundingClientRect().height;
        dock.style.height = '';
        void dock.offsetHeight;
        dock.style.transition = transition;

        // Where the page's last block ends, wherever the page is scrolled —
        // and where it comes to rest: a block still sliding in (.reveal, 14px
        // lower until it is shown) is measured without that slide. The
        // page's own pixels are zoomed (desktop.css): k turns them to the
        // screen's.
        var k = shell.offsetWidth ? shell.getBoundingClientRect().width / shell.offsetWidth : 1;
        var scrolled = scroller ? scroller.scrollTop : 0;   // the scroller is not zoomed
        var bottom = 0;
        Array.prototype.forEach.call(shell.children, function (block) {
            if (!block.offsetParent) { return; }
            var box = block.getBoundingClientRect();
            if (!box.height) { return; }
            var moved = window.getComputedStyle(block).transform;
            var slide = moved && moved !== 'none' && window.DOMMatrixReadOnly ? new DOMMatrixReadOnly(moved).m42 * k : 0;
            bottom = Math.max(bottom, box.bottom - slide + scrolled);
        });

        var length = Math.min(height, Math.max(rows, bottom - top));
        root.style.setProperty('--nav-h', Math.round(length) + 'px');
    }

    var fitting = 0;
    function refit() {
        window.cancelAnimationFrame(fitting);
        fitting = window.requestAnimationFrame(fitSidebar);
    }

    new MutationObserver(refit).observe(root, { attributes: true, attributeFilter: ['data-active-page', 'data-layout'] });
    window.addEventListener('resize', refit);
    if ('ResizeObserver' in window) {
        var grows = new ResizeObserver(refit);
        Array.prototype.forEach.call(document.querySelectorAll('.rail-window .app__main > .shell'), function (shell) {
            grows.observe(shell);
        });
    }

    /* --------------------------------------------------------- the switch */

    var choices = Array.prototype.slice.call(document.querySelectorAll('[data-view-choice]'));

    function paintSwitch() {
        var layout = nav.layout();
        choices.forEach(function (button) {
            var on = button.getAttribute('data-view-choice') === (layout === 'desktop' ? 'desktop' : 'phone');
            button.classList.toggle('is-active', on);
            button.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
    }

    /** After the layout changed: every layer back where this layout keeps it. */
    function relayout() {
        paintSwitch();
        /* Beside the page a panel is not modal: the page stays the user's. */
        Object.keys(panels).forEach(function (name) {
            var panel = panels[name];
            if (panel && panel.hasAttribute('aria-modal')) {
                panel.setAttribute('aria-modal', nav.sideBySide() ? 'false' : 'true');
            }
        });
        if (nav.details && nav.details.replace) { nav.details.replace(); }
        if (nav.ai && nav.ai.replace) { nav.ai.replace(); }
        sync();
        if (source) { source.classList.toggle('is-second-source', opened()); }
        nav.refresh();
        refit();
    }

    choices.forEach(function (button) {
        button.addEventListener('click', function () {
            var choice = button.getAttribute('data-view-choice');
            try { window.localStorage.setItem('ownify_view', choice); } catch (error) { /* kept for this visit only */ }
            if (window.ownifyLayout) { window.ownifyLayout.apply(); }
            relayout();
        });
    });

    /* The window crossing 768 or 1200: the head moved the layout, or side by
       side became covering. */
    ['(min-width: 768px)', '(min-width: 1200px)'].forEach(function (query) {
        var media = window.matchMedia(query);
        var fn = function () { window.setTimeout(relayout, 0); };
        if (media.addEventListener) { media.addEventListener('change', fn); }
        else if (media.addListener) { media.addListener(fn); }
    });

    relayout();
}());
