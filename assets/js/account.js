/**
 * account.js — the sign-in / account panel.
 *
 * Every request goes to an endpoint under api/, carries the CSRF token, and
 * never sends a user id: the server takes that from the session. Signing in or
 * out reloads the page so the whole app picks up the new state; changing a
 * username or picture updates in place.
 */

(function () {
    'use strict';

    var panel = document.querySelector('[data-account]');
    if (!panel) { return; }

    var errorBox = panel.querySelector('[data-account-error]');
    var lastFocus = null;
    var mode = 'login';

    /* --------------------------------------------------------- open/close */

    function open(trigger) {
        lastFocus = trigger || document.activeElement;
        panel.hidden = false;
        // One frame before the class, so the transition has a start state.
        window.requestAnimationFrame(function () { panel.classList.add('is-open'); });

        var first = panel.querySelector('input, button:not([data-account-close])');
        if (first) { first.focus({ preventScroll: true }); }
    }

    function close() {
        panel.classList.remove('is-open');
        showError(null);

        window.setTimeout(function () { panel.hidden = true; }, 200);

        if (lastFocus && lastFocus.focus) { lastFocus.focus({ preventScroll: true }); }
    }

    /* The header renders on every page of the rail, so there is an account
       button per page. Delegate rather than bind one of them. */
    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-account-open]');
        if (!trigger) { return; }

        event.preventDefault();
        open(trigger);
    });

    panel.addEventListener('click', function (event) {
        if (event.target.closest('[data-account-close]')) { close(); }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !panel.hidden) { close(); }
    });

    /* ------------------------------------------------------------ helpers */

    function showError(message) {
        if (!errorBox) { return; }

        if (!message) {
            errorBox.hidden = true;
            errorBox.textContent = '';
            return;
        }

        errorBox.textContent = message;
        errorBox.hidden = false;
    }

    function csrf() {
        return panel.getAttribute('data-csrf') || '';
    }

    function busy(form, state) {
        var button = form.querySelector('button[type="submit"]');
        if (button) { button.disabled = state; }
    }

    /** Posts FormData and returns the parsed body, whatever the status. */
    function send(url, body) {
        body.append('csrf', csrf());

        return fetch(url, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (response) {
                return response.json().catch(function () {
                    return { ok: false, error: 'Onverwacht antwoord van de server.' };
                });
            })
            .catch(function () {
                return { ok: false, error: 'De server is niet bereikbaar.' };
            });
    }

    /* -------------------------------------------------- signed-out: mode */

    Array.prototype.forEach.call(panel.querySelectorAll('[data-account-mode]'), function (option) {
        option.addEventListener('click', function () {
            mode = option.getAttribute('data-account-mode');

            Array.prototype.forEach.call(panel.querySelectorAll('[data-account-mode]'), function (other) {
                var isActive = other === option;
                other.classList.toggle('is-active', isActive);
                other.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            });

            Array.prototype.forEach.call(panel.querySelectorAll('[data-account-only]'), function (field) {
                var wanted = field.getAttribute('data-account-only');
                field.hidden = wanted !== mode;
                var input = field.querySelector('input');
                if (input) { input.required = !field.hidden; }
            });

            var submit = panel.querySelector('[data-account-submit]');
            if (submit) { submit.textContent = mode === 'register' ? 'Account aanmaken' : 'Inloggen'; }

            var password = panel.querySelector('#account-password');
            if (password) {
                password.setAttribute('autocomplete', mode === 'register' ? 'new-password' : 'current-password');
            }

            showError(null);
        });
    });

    /* ----------------------------------------------- providers (not wired) */

    Array.prototype.forEach.call(panel.querySelectorAll('[data-account-provider]'), function (button) {
        button.addEventListener('click', function () {
            var body = new FormData();
            body.append('provider', button.getAttribute('data-account-provider'));

            send('api/auth/oauth.php', body).then(function (result) {
                showError(result.error || 'Deze aanmelding is nog niet beschikbaar.');
            });
        });
    });

    /* --------------------------------------------------------------- forms */

    panel.addEventListener('submit', function (event) {
        var form = event.target.closest('[data-account-form]');
        if (!form) { return; }

        event.preventDefault();
        showError(null);
        busy(form, true);

        var kind = form.getAttribute('data-account-form');
        var body = new FormData(form);
        var url;

        if (kind === 'email') {
            url = mode === 'register' ? 'api/auth/register.php' : 'api/auth/login.php';
        } else if (kind === 'logout') {
            url = 'api/auth/logout.php';
        } else if (kind === 'username') {
            url = 'api/profile/username.php';
        } else if (kind === 'avatar') {
            url = 'api/profile/avatar.php';
        } else {
            busy(form, false);
            return;
        }

        send(url, body).then(function (result) {
            busy(form, false);

            if (!result.ok) {
                showError(result.error || 'Er ging iets mis.');
                return;
            }

            // A change of who is signed in changes the whole app.
            if (kind === 'email' || kind === 'logout') {
                window.location.reload();
                return;
            }

            if (kind === 'username' && result.username) {
                var label = panel.querySelector('[data-account-username]');
                if (label) { label.textContent = result.username; }
            }

            if (kind === 'avatar' && result.avatar) {
                applyAvatar(result.avatar);
                form.reset();
            }
        });
    });

    /** Swaps the picture in the panel and in the header button. */
    function applyAvatar(path) {
        var source = path + '?v=' + Date.now();

        Array.prototype.forEach.call(document.querySelectorAll('[data-account-avatar]'), function (slot) {
            if (slot.tagName === 'IMG') {
                slot.src = source;
                return;
            }

            slot.innerHTML = '';
            var image = document.createElement('img');
            image.src = source;
            image.alt = '';
            slot.appendChild(image);
        });
    }
}());
