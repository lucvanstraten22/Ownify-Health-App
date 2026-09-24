/**
 * account.js — the sign-in / account panel.
 *
 * Every request goes to an endpoint under api/, carries the CSRF token, and
 * never sends a user id: the server takes that from the session. Signing in or
 * out reloads the page so the whole app picks up the new state; changing a
 * username or picture updates in place.
 *
 * Google is a round trip through Google's own pages, so it is started here
 * and finished by a redirect back (api/auth/google-callback.php). The page
 * that comes back says how it went: the panel opens by itself, on the
 * username step for somebody new or with a message, and a link started from
 * Settings returns to Settings > Account.
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

        var flash = panel.querySelector('[data-account-flash]');
        if (flash) { flash.hidden = true; }

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

    /* ------------------------------------------------------------ providers */

    /**
     * Asks the server to start a sign-in, then goes where it says. The server
     * keeps the state, nonce and PKCE verifier in the session; the browser
     * only ever carries the address. Apple answers "not linked yet".
     */
    function startProvider(provider, mode, button, onError) {
        var body = new FormData();
        body.append('provider', provider);
        body.append('mode', mode);

        if (button) { button.disabled = true; }

        send('api/auth/oauth.php', body).then(function (result) {
            if (result.ok && result.redirect) {
                window.location.assign(result.redirect);
                return;
            }

            if (button) { button.disabled = false; }
            onError(result.error || 'Deze aanmelding is nog niet beschikbaar.');
        });
    }

    Array.prototype.forEach.call(panel.querySelectorAll('[data-account-provider]'), function (button) {
        button.addEventListener('click', function () {
            showError(null);
            startProvider(button.getAttribute('data-account-provider'), 'login', button, showError);
        });
    });

    /* Settings > Account: linking Google to the account that is signed in. */
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-google-link]');
        if (!button || button.disabled) { return; }

        event.preventDefault();

        var block = button.closest('[data-signin]');
        var slot  = block ? block.querySelector('[data-signin-error]') : null;
        if (slot) { slot.hidden = true; }

        startProvider('google', 'link', button, function (message) {
            if (!slot) { return; }
            slot.textContent = message;
            slot.hidden = false;
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
        } else if (kind === 'google-username') {
            url = 'api/auth/google-username.php';
        } else if (kind === 'google-cancel') {
            url = 'api/auth/google-username.php';
            body.append('action', 'cancel');
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

            /* The Google identity waiting for a username has gone (expired,
               or somebody else took it meanwhile): the server has left the
               reason for the next page, which opens this panel again on the
               ordinary sign-in form. */
            if (!result.ok && result.expired) {
                window.location.reload();
                return;
            }

            if (!result.ok) {
                showError(result.error || 'Er ging iets mis.');
                return;
            }

            // A change of who is signed in changes the whole app.
            if (kind === 'email' || kind === 'logout' || kind === 'google-username' || kind === 'google-cancel') {
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

    /* ---------------------------------------------------- back from Google */

    /* The panel opens by itself when there is something to say or a username
       still to choose. */
    if (panel.hasAttribute('data-account-autoopen')) {
        open(null);
    }

    /* A link started from Settings comes back to Settings > Account, where
       the Inloggen block says how it went. */
    var linkResult = document.querySelector('[data-signin-flash]');
    var nav = window.AppNav;

    if (linkResult && nav && nav.pages && nav.details) {
        nav.pages.goToId('settings');
        nav.details.open('settings-account');

        window.setTimeout(function () {
            linkResult.scrollIntoView({ block: 'center', behavior: 'smooth' });
        }, (nav.duration || 280) + 120);
    }

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
