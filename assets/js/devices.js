/**
 * devices.js — the quick look behind the header's devices button.
 *
 * Opens and closes the popup, and takes "Apparaat koppelen" to Instellingen >
 * Apparaten & Gezondheid: the settings page, then its devices screen, through
 * the same two layers a tap on the tab bar and the row would use. What the
 * popup lists is rendered by the server from the same data as that screen;
 * nothing here decides what is connected.
 */

(function () {
    'use strict';

    var popup = document.querySelector('[data-devices]');
    if (!popup) { return; }

    var trigger = null;
    var showing = false;
    var hideTimer = null;

    /* --------------------------------------------------------- open/close */

    function open(button) {
        trigger = button || null;
        showing = true;

        window.clearTimeout(hideTimer);
        popup.hidden = false;
        if (trigger) { trigger.setAttribute('aria-expanded', 'true'); }

        // One frame before the class, so the transition has a start state.
        window.requestAnimationFrame(function () { popup.classList.add('is-open'); });

        var add = popup.querySelector('[data-devices-add]');
        if (add) { add.focus({ preventScroll: true }); }
    }

    function close(returnFocus) {
        if (!showing) { return; }
        showing = false;

        popup.classList.remove('is-open');
        if (trigger) { trigger.setAttribute('aria-expanded', 'false'); }

        hideTimer = window.setTimeout(function () { popup.hidden = true; }, 200);

        if (returnFocus !== false && trigger && trigger.focus) {
            trigger.focus({ preventScroll: true });
        }
    }

    /** Instellingen first, then its devices screen on top of it. */
    function toDevicesScreen() {
        var nav = window.AppNav;
        if (!nav || !nav.pages || !nav.details) { return; }

        nav.pages.goToId('settings');
        nav.details.open('settings-devices', trigger);
    }

    /* ------------------------------------------------------------ wiring */

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-devices-open]');
        if (!button) { return; }

        event.preventDefault();
        open(button);
    });

    popup.addEventListener('click', function (event) {
        if (event.target.closest('[data-devices-add]')) {
            // Focus goes to the screen that opens, not back to the header.
            close(false);
            toDevicesScreen();
            return;
        }

        if (event.target.closest('[data-devices-close]')) { close(); }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && showing) { close(); }
    });
}());
