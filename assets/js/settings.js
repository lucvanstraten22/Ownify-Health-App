/**
 * settings.js — Instellingen.
 *
 * Three small things, and nothing that pretends to save:
 *
 *   choices        pick one option; the tick moves, nothing is stored
 *   integrations   expand a health source in place
 *   account        sign out (the endpoint is real), and the delete
 *                  confirmation (which confirms and then stops)
 *
 * Every screen that offers a choice says at its foot that the choice is not
 * kept, so the interaction can be judged without the page claiming otherwise.
 */

(function () {
    'use strict';

    var page = document.querySelector('[data-settings]');
    if (!page) { return; }

    /* ------------------------------------------------------------ choices */

    /**
     * One selection per named group. Radio semantics live on the buttons, so
     * the only work here is moving `is-selected` and aria-checked.
     */
    document.addEventListener('click', function (event) {
        var option = event.target.closest('[data-choice]');
        if (!option || option.disabled) { return; }

        var name = option.getAttribute('data-choice');

        Array.prototype.forEach.call(
            document.querySelectorAll('[data-choice="' + name + '"]'),
            function (other) {
                var isOn = other === option;
                other.classList.toggle('is-selected', isOn);
                other.setAttribute('aria-checked', isOn ? 'true' : 'false');
            }
        );
    });

    /* ------------------------------------------------------- integrations */

    document.addEventListener('click', function (event) {
        var head = event.target.closest('[data-integration-toggle]');
        if (!head) { return; }

        var body = document.getElementById(head.getAttribute('aria-controls'));
        if (!body) { return; }

        var open = head.getAttribute('aria-expanded') === 'true';
        head.setAttribute('aria-expanded', open ? 'false' : 'true');
        body.hidden = open;
    });

    /* ------------------------------------------------------------- logout */

    /**
     * The one action here that really acts. The endpoint already exists and
     * takes the user id from the session, never from this request; a settings
     * page whose sign-out does not sign you out would be worse than none.
     */
    var logout = page.querySelector('[data-settings-logout]');
    if (logout) {
        logout.addEventListener('click', function () {
            var panel = document.querySelector('[data-account]');
            var token = panel ? panel.getAttribute('data-csrf') : '';

            var body = new FormData();
            body.append('csrf', token || '');

            logout.disabled = true;

            fetch('api/auth/logout.php', { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function () { window.location.reload(); })
                .catch(function () { logout.disabled = false; });
        });
    }

    /* ------------------------------------------------------------- delete */

    var confirmPanel = document.querySelector('[data-settings-confirm]');
    var lastFocus = null;

    function openConfirm(trigger) {
        if (!confirmPanel) { return; }

        lastFocus = trigger || document.activeElement;
        confirmPanel.hidden = false;
        // One frame before the class, so the transition has a start state.
        window.requestAnimationFrame(function () { confirmPanel.classList.add('is-open'); });

        var cancel = confirmPanel.querySelector('[data-confirm-close]:not(.confirm__scrim)');
        if (cancel) { cancel.focus({ preventScroll: true }); }
    }

    function closeConfirm() {
        if (!confirmPanel) { return; }

        confirmPanel.classList.remove('is-open');
        window.setTimeout(function () { confirmPanel.hidden = true; }, 200);

        if (lastFocus && lastFocus.focus) { lastFocus.focus({ preventScroll: true }); }
    }

    var deleteButton = page.querySelector('[data-settings-delete]');
    if (deleteButton) {
        deleteButton.addEventListener('click', function () { openConfirm(deleteButton); });
    }

    if (confirmPanel) {
        confirmPanel.addEventListener('click', function (event) {
            if (event.target.closest('[data-confirm-close]')) { closeConfirm(); }
        });
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && confirmPanel && !confirmPanel.hidden) { closeConfirm(); }
    });
}());
