/**
 * phone-sim.js — Telefoon on a wide screen: the website in an iPhone
 * (components/phone-simulator.php, assets/css/phone-sim.css, docs/DESKTOP.md).
 *
 * While <html data-layout="phone-frame"> (document-head.php):
 *   - the screen loads this page — the phone's own website, as a phone shows
 *     it; it is loaded when the layout is chosen and let go when it is left;
 *   - the app around it is inert (it is hidden by the stylesheet);
 *   - the device is fitted to the window: never larger than itself, always
 *     whole, with room around it;
 *   - the status bar shows the time and the ink the page in the phone asks
 *     for, following it when the theme changes in there.
 *
 * Leaving it, the page around it is reloaded if anything was done in the
 * phone, so Desktop never shows what the phone has since changed.
 */
(function () {
    'use strict';

    var root = document.documentElement;
    var sim = document.querySelector('[data-phone-sim]');
    if (!sim || root.getAttribute('data-frame') === 'phone') { return; }

    var device = sim.querySelector('[data-phone-sim-device]');
    var screen = sim.querySelector('[data-phone-screen]');
    var time = sim.querySelector('[data-phone-sim-time]');
    var DEVICE_W = 425 + 8;   // the device and its buttons
    var DEVICE_H = 884;

    var shown = false;
    var used = false;
    var clock = 0;
    var watcher = null;

    function layoutIsFrame() { return root.getAttribute('data-layout') === 'phone-frame'; }

    /* The app around the phone: out of reach while the phone is in front. */
    function setAppInert(on) {
        Array.prototype.forEach.call(document.body.children, function (child) {
            if (child === sim || child.tagName === 'SCRIPT' || child.classList.contains('app__backdrop')) { return; }
            if (on) { child.setAttribute('inert', ''); } else { child.removeAttribute('inert'); }
        });
    }

    function fit() {
        var w = window.innerWidth, h = window.innerHeight;
        var margin = Math.round(Math.max(24, Math.min(64, h * 0.05)));
        var k = Math.min(1, (h - 2 * margin) / DEVICE_H, (w - 2 * margin) / DEVICE_W);
        sim.style.setProperty('--sim-k', Math.max(0.4, k).toFixed(4));
    }

    function tick() {
        var now = new Date();
        var lang = root.getAttribute('lang') || 'nl';
        try {
            time.textContent = now.toLocaleTimeString(lang, { hour: 'numeric', minute: '2-digit' }).replace(/\s?[AP]M$/i, '');
        } catch (error) {
            time.textContent = now.getHours() + ':' + ('0' + now.getMinutes()).slice(-2);
        }
    }

    /* The ink of the status bar: what the page in the phone asks for. */
    function ink() {
        var theme = null;
        try {
            var inner = screen.contentDocument && screen.contentDocument.documentElement;
            theme = inner && inner.getAttribute('data-theme');
        } catch (error) { theme = null; }
        if (!theme && window.ownifyTheme && window.ownifyTheme.own) { theme = window.ownifyTheme.own(); }
        sim.setAttribute('data-ink', theme === 'light' ? 'dark' : 'light');
    }

    function watch() {
        if (watcher) { watcher.disconnect(); watcher = null; }
        var doc = null;
        try { doc = screen.contentDocument; } catch (error) { doc = null; }
        if (!doc) { return; }
        watcher = new MutationObserver(ink);
        watcher.observe(doc.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
        ['pointerdown', 'keydown', 'wheel', 'touchstart'].forEach(function (type) {
            doc.addEventListener(type, function () { used = true; }, { passive: true, capture: true });
        });
        ink();
    }

    function show() {
        if (shown) { return; }
        shown = true;
        used = false;
        setAppInert(true);
        fit();
        tick();
        ink();
        clock = window.setInterval(tick, 15000);
        screen.addEventListener('load', watch);
        screen.src = window.location.href.split('#')[0];
    }

    function hide() {
        if (!shown) { return; }
        shown = false;
        window.clearInterval(clock);
        if (watcher) { watcher.disconnect(); watcher = null; }
        screen.removeEventListener('load', watch);
        screen.removeAttribute('src');
        setAppInert(false);
        if (used) { window.location.reload(); }
    }

    function update() { if (layoutIsFrame()) { show(); } else { hide(); } }

    new MutationObserver(update).observe(root, { attributes: true, attributeFilter: ['data-layout'] });
    window.addEventListener('resize', function () { if (shown) { fit(); } });
    update();
}());
