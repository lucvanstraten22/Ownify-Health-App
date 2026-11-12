/**
 * account.js — the sign-in / account panel.
 *
 * Every request goes to an endpoint under api/, carries the CSRF token, and
 * never sends a user id: the server takes that from the session. Signing in,
 * registering or signing out reloads the page, and the server renders what the
 * session now calls for: the app, on Overzicht, or the opening screen.
 * Changing a username or picture updates in place.
 *
 * On the opening screen the panel is one flow at a time — Inloggen or
 * Registreren, whichever button opened it.
 *
 * Google is a round trip through Google's own pages, so it is started here
 * and finished by a redirect back (api/auth/google-callback.php). The page
 * that comes back says how it went: the panel opens by itself, on the
 * username step for somebody new or with a message, and a link started from
 * Settings returns to Settings > Account.
 */

(function () {
    'use strict';

    var T = window.OwnifyI18n;     // language and units (assets/js/i18n.js)

    var panel = document.querySelector('[data-account]');
    if (!panel) { return; }

    var errorBox = panel.querySelector('[data-account-error]');
    var lastFocus = null;
    var mode = 'login';

    /* --------------------------------------------------------- open/close */

    function open(trigger) {
        // The opening screen's two buttons say which flow the panel is on.
        var flow = trigger && trigger.getAttribute ? trigger.getAttribute('data-account-open') : '';
        if (flow === 'login' || flow === 'register') { setMode(flow); }

        lastFocus = trigger || document.activeElement;
        panel.hidden = false;
        // One frame before the class, so the transition has a start state.
        window.requestAnimationFrame(function () { panel.classList.add('is-open'); });

        // The first control that is on screen: signed in, the Vrienden page
        // and its back button are hidden until they are asked for.
        var first = Array.prototype.filter.call(
            panel.querySelectorAll('input, button:not([data-account-close])'),
            function (control) { return control.getClientRects().length > 0; }
        )[0];
        if (first) { first.focus({ preventScroll: true }); }
    }

    function close() {
        panel.classList.remove('is-open');
        showError(null);
        leaveLink();

        var flash = panel.querySelector('[data-account-flash]');
        if (flash) { flash.hidden = true; }

        window.setTimeout(function () { panel.hidden = true; }, 200);

        if (lastFocus && lastFocus.focus) { lastFocus.focus({ preventScroll: true }); }
    }

    /* B5: the popup is a summary; your row opens Instellingen › Account,
       where everything about you is changed. */
    panel.addEventListener('click', function (event) {
        var goto = event.target.closest('[data-account-goto]');
        if (!goto) { return; }
        event.preventDefault();
        var detail = goto.getAttribute('data-account-goto');
        close();
        var tab = document.querySelector('[data-nav="settings"]');
        if (tab) { tab.click(); }
        window.setTimeout(function () {
            var opener = document.querySelector('[data-detail-open="' + detail + '"]');
            if (opener) { opener.click(); }
        }, 260);
    });

    /* The account button in the app's header, and the opening screen's
       Inloggen and Registreren. */
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
                    return { ok: false, error: T.t('Onverwacht antwoord van de server.') };
                });
            })
            .catch(function () {
                return { ok: false, error: T.t('Ownify is niet bereikbaar. Controleer je internetverbinding en probeer het opnieuw.') };
            });
    }

    /* -------------------------------------------------- signed-out: flow */

    /**
     * Signed out, the panel is on one flow at a time — logging in or
     * registering, never both, with no way across from inside it. The form,
     * the fields and the endpoints are the same ones either way: registering
     * adds the e-mail address and asks for a new password rather than the
     * current one.
     */
    function setMode(next) {
        mode = next === 'register' ? 'register' : 'login';

        Array.prototype.forEach.call(panel.querySelectorAll('[data-account-only]'), function (field) {
            field.hidden = field.getAttribute('data-account-only') !== mode;
            var input = field.querySelector('input');
            if (input) { input.required = !field.hidden; }
        });

        /* Registering with e-mail closed (Release A): the form makes way for
           the one line that says so; Google stays below it. */
        var form = panel.querySelector('[data-account-form="email"]');
        if (form) { form.hidden = mode === 'register' && form.hasAttribute('data-account-register-closed'); }

        var title = panel.querySelector('[data-account-title]');
        if (title) { title.textContent = title.getAttribute('data-title-' + mode); }

        var submit = panel.querySelector('[data-account-submit]');
        if (submit) { submit.textContent = mode === 'register' ? T.t('Account aanmaken') : T.t('Inloggen'); }

        var password = panel.querySelector('#account-password');
        if (password) {
            password.setAttribute('autocomplete', mode === 'register' ? 'new-password' : 'current-password');
        }

        showError(null);
    }

    /* ------------------------------------------------------------ providers */

    /**
     * Asks the server to start a sign-in, then goes where it says. The server
     * keeps the state, nonce and PKCE verifier in the session; the browser
     * only ever carries the address.
     */
    function startProvider(provider, mode, button, onError) {
        var body = new FormData();
        body.append('provider', provider);
        body.append('mode', mode);

        if (button) { button.disabled = true; }

        send('api/auth/oauth.php', body).then(function (result) {
            if (result.ok && result.redirect) {
                window.ownifyLeave(result.redirect);   // the whole window, also from the iPhone on a wide screen
                return;
            }

            if (button) { button.disabled = false; }
            onError(result.error || T.t('Deze aanmelding is nog niet beschikbaar.'));
        });
    }

    Array.prototype.forEach.call(panel.querySelectorAll('[data-account-provider]'), function (button) {
        button.addEventListener('click', function () {
            showError(null);
            startProvider(button.getAttribute('data-account-provider'), 'login', button, showError);
        });
    });

    /* -------------------------------------------------------- the picture */

    /* The field shows the chosen file's name, as the app does. */
    function showFileName(input) {
        var name = input.closest('.account__file').querySelector('[data-account-file-name]');
        if (!name) { return; }
        var file = input.files && input.files[0];
        name.textContent = file ? file.name : name.getAttribute('data-empty');
    }

    panel.addEventListener('change', function (event) {
        if (event.target.matches('[data-account-file]')) { showFileName(event.target); }
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
        } else if (kind === 'verify-check' || kind === 'verify-resend' || kind === 'verify-cancel') {
            verifyAction(kind.slice(7), form);
            return;
        } else if (kind === 'forgot') {
            forgot(form, body);
            return;
        } else if (kind === 'forgot-back') {
            busy(form, false);
            showStep('form');
            return;
        } else if (kind === 'link') {
            useLink(form, body);
            return;
        } else if (kind === 'link-done') {
            window.location.assign(window.location.pathname);
            return;
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
                showError(result.error || T.t('Er ging iets mis.'));
                return;
            }

            /* Release B: a registration (or a sign-in to one) that waits
               for its link — the verification screen, nothing signed in. */
            if (kind === 'email' && result.pending) {
                showVerify(result.pending);
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
                var picked = form.querySelector('[data-account-file]');
                if (picked) { showFileName(picked); }
            }
        });
    });

    /* ------------------------------------------- Release B: the steps */

    /**
     * Signed out, the panel shows one step at a time: the form, the
     * verification screen, Wachtwoord vergeten — or, signed in or not, the
     * page a link opened. The others are hidden; the link page puts back what
     * it hid when the panel closes.
     */
    var hiddenForLink = [];

    function showStep(name) {
        Array.prototype.forEach.call(panel.querySelectorAll('[data-account-step]'), function (step) {
            step.hidden = step.getAttribute('data-account-step') !== name;
        });

        showError(null);
        var flash = panel.querySelector('[data-account-flash]');
        if (flash) { flash.hidden = true; }

        var target = panel.querySelector('[data-account-step="' + name + '"]');

        /* One title per step (A1): the panel head names the step it shows. */
        var head = panel.querySelector('[data-account-title]');
        if (head) {
            head.textContent = (target && target.getAttribute('data-step-title')) || head.getAttribute('data-title-' + mode) || head.textContent;
        }
        var first = target ? Array.prototype.filter.call(target.querySelectorAll('input, button'), function (control) {
            return control.getClientRects().length > 0;
        })[0] : null;
        if (first && !panel.hidden) { first.focus({ preventScroll: true }); }
    }

    function status(selector, message) {
        var line = panel.querySelector(selector);
        if (!line) { return; }
        line.textContent = message || '';
        line.hidden = !message;
    }

    /* ----------------------------------------- the verification screen */

    var resendTimer = null;

    /** The resend button waits as long as the server said, then is back. */
    function waitResend(seconds) {
        var button = panel.querySelector('[data-account-form="verify-resend"] button');
        if (!button || !seconds) { return; }

        button.disabled = true;
        window.clearTimeout(resendTimer);
        resendTimer = window.setTimeout(function () { button.disabled = false; }, seconds * 1000);
    }

    function showVerify(pending) {
        var line = panel.querySelector('[data-verify-line]');
        if (line && pending.email_masked) {
            line.textContent = (line.getAttribute('data-template') || '%s').replace('%s', pending.email_masked);
        }

        status('[data-verify-status]', null);
        showStep('verify');
        waitResend(pending.resend_after || 0);
    }

    function verifyAction(action, form) {
        var body = new FormData();
        body.append('action', action === 'check' ? 'status' : action);
        status('[data-verify-status]', null);

        send('api/auth/pending.php', body).then(function (result) {
            busy(form, false);

            if (action === 'cancel' || result.state === 'gone') {
                /* Back to the form, which still has what was typed. */
                setMode('register');
                showStep('form');
                if (result.state === 'gone' && action !== 'cancel') { showError(result.error || ''); }
                var email = panel.querySelector('#account-email');
                if (email) { email.focus({ preventScroll: true }); }
                return;
            }

            if (result.state === 'active') {
                window.location.reload();
                return;
            }

            if (action === 'resend') {
                if (result.resend_after) { waitResend(result.resend_after); }
                if (!result.ok && !result.resend_after) { showError(result.error || T.t('Er ging iets mis.')); return; }
            }

            status('[data-verify-status]', result.ok ? (result.message || '') : (result.error || T.t('Er ging iets mis.')));
        });
    }

    /* ------------------------------------------------ Wachtwoord vergeten */

    /** The answer takes the line's place; the form steps aside. */
    function forgot(form, body) {
        send('api/auth/forgot.php', body).then(function (result) {
            busy(form, false);

            if (!result.ok) {
                showError(result.error || T.t('Er ging iets mis.'));
                return;
            }

            panel.querySelector('[data-forgot-line]').textContent = result.message || '';
            form.hidden = true;
        });
    }

    function showForgot() {
        var line = panel.querySelector('[data-forgot-line]');
        var form = panel.querySelector('[data-account-form="forgot"]');
        if (line) { line.textContent = line.getAttribute('data-ask') || ''; }
        if (form) { form.hidden = false; }
        showStep('forgot');
    }

    panel.addEventListener('click', function (event) {
        var link = event.target.closest('[data-account-forgot]');
        if (!link) { return; }

        event.preventDefault();
        var typed = panel.querySelector('#account-username');
        var email = panel.querySelector('#account-forgot-email');
        if (typed && email && typed.value.indexOf('@') > 0) { email.value = typed.value.trim(); }
        showForgot();
    });

    /* ------------------------------------------------------- a link page */

    var linkToken = '';
    var linkWords = {};

    try { linkWords = JSON.parse((panel.querySelector('[data-account-step="link"]') || panel).getAttribute('data-link-words') || '{}'); } catch (e) { linkWords = {}; }

    function fill(template, view) {
        return (template || '')
            .replace('%1$s', view.email || '')
            .replace('%2$s', view.username || '')
            .replace('%3$s', view.old_email || '')
            .replace('%4$s', view.changed_at || '');
    }

    function leaveLink() {
        var eyebrow = panel.querySelector('[data-account-title]');
        if (eyebrow && hiddenForLink !== null) { eyebrow.textContent = eyebrow.getAttribute('data-title-' + mode) || eyebrow.textContent; }
        hiddenForLink.forEach(function (element) { element.hidden = false; });
        hiddenForLink = [];
        var step = panel.querySelector('[data-account-step="link"]');
        if (step && !step.hidden) {
            step.hidden = true;
            var form = panel.querySelector('[data-account-step="form"]');
            var verify = panel.querySelector('[data-account-step="verify"]');
            if (form && (!verify || verify.hidden)) { form.hidden = false; }
        }
    }

    function showLink(purpose, view, message) {
        var words = linkWords[purpose] || linkWords.gone || ['', '', ''];
        var step  = panel.querySelector('[data-account-step="link"]');
        if (!step) { return; }

        /* Signed in, the account and the Vrienden page make way for it. */
        Array.prototype.forEach.call(panel.querySelectorAll('[data-account-view]'), function (view) {
            if (!view.hidden) { view.hidden = true; hiddenForLink.push(view); }
        });

        /* Not Inloggen or Registreren: a link is about the account. */
        var eyebrow = panel.querySelector('#account-title');
        if (eyebrow) { eyebrow.textContent = 'Account'; }

        step.querySelector('[data-link-title]').textContent = words[0];
        step.querySelector('[data-link-line]').textContent = message || fill(words[1], view || {});

        var form = step.querySelector('[data-account-form="link"]');
        var done = step.querySelector('[data-account-form="link-done"]');
        var passwords = step.querySelector('[data-link-passwords]');

        form.hidden = purpose === 'gone';
        done.hidden = purpose !== 'gone';
        passwords.hidden = !(view && view.needs === 'password');
        Array.prototype.forEach.call(passwords.querySelectorAll('input'), function (input) {
            input.required = !passwords.hidden;
            input.value = '';
        });
        step.querySelector('[data-link-submit]').textContent = words[2];

        showStep('link');
    }

    function openLink(token) {
        linkToken = token;
        open(null);

        var body = new FormData();
        body.append('action', 'info');
        body.append('token', token);

        send('api/auth/link.php', body).then(function (result) {
            if (!result.ok) {
                showLink('gone', null, result.error);
                return;
            }

            showLink(result.link.purpose, result.link, null);
        });
    }

    function useLink(form, body) {
        body.append('action', 'use');
        body.append('token', linkToken);

        send('api/auth/link.php', body).then(function (result) {
            busy(form, false);

            if (!result.ok) {
                if (result.gone) {
                    showLink('gone', null, result.error);
                } else {
                    showError(result.error || T.t('Er ging iets mis.'));
                }
                return;
            }

            /* Done: what happened, and one way on. A registration confirmed
               in the browser that started it is signed in: straight in. */
            if (result.signed_in) {
                window.location.assign(window.location.pathname);
                return;
            }

            /* The message takes the line's place: one thing said, one way on. */
            form.hidden = true;
            panel.querySelector('[data-account-form="link-done"]').hidden = false;
            panel.querySelector('[data-link-line]').textContent = result.message || '';
        });
    }

    /* Opened from an e-mail: the token is taken out of the address bar at
       once, so it is in no history entry and no screenshot of the page. */
    (function () {
        var query = window.location.search || '';

        /* A wide screen: the iPhone on screen opens it (phone-sim.js hands
           it the whole address), not this page behind it. */
        if (document.documentElement.getAttribute('data-layout') === 'phone-frame' && document.querySelector('[data-phone-screen]')) { return; }

        if (/[?&]account-link(?:[=&]|$)/.test(query)) {
            var token = (window.location.hash || '').replace(/^#/, '');
            if (window.history && window.history.replaceState) {
                window.history.replaceState(null, '', window.location.pathname);
            }
            if (token) { openLink(token); } else { open(null); showLink('gone', null, null); }
            return;
        }

        if (/[?&]wachtwoord-vergeten(?:[=&]|$)/.test(query)) {
            if (window.history && window.history.replaceState) {
                window.history.replaceState(null, '', window.location.pathname);
            }
            if (panel.querySelector('[data-account-step="forgot"]') && panel.getAttribute('data-account-session') === 'signed-out') {
                open(null);
                showForgot();
            }
        }
    }());

    /* ---------------------------------------------------- back from Google */

    /* The panel opens by itself when there is something to say or a username
       still to choose. */
    if (panel.hasAttribute('data-account-autoopen')) {
        open(null);
    }

    /* A link started from Settings comes back to Settings > Account, where
       the Inloggen block says how it went — and connecting a source (Polar)
       comes back to Apparaten & Gezondheid, where Koppelingen says it. */
    var nav = window.AppNav;

    [['[data-signin-flash]', 'settings-account'], ['[data-integration-flash]', 'settings-devices']].forEach(function (pair) {
        var result = document.querySelector(pair[0]);

        if (result && nav && nav.pages && nav.details) {
            nav.pages.goToId('settings');
            nav.details.open(pair[1]);

            window.setTimeout(function () {
                result.scrollIntoView({ block: 'center', behavior: 'smooth' });
            }, (nav.duration || 280) + 120);
        }
    });

    /* ---------------------------------------------------- the right screen */

    /**
     * Which screen this page is — the app or the opening screen — was decided
     * by the server from the session the page was rendered for. A page
     * brought back from the back/forward cache, or looked at again after its
     * session ran out or was ended in another tab, may no longer match; it
     * asks, and if the answer has changed it is rendered anew, which puts the
     * right screen up. Only the session decides, never this page's memory.
     */
    var renderedFor = panel.getAttribute('data-account-session');
    var askedAt = 0;

    function recheck(force) {
        var now = Date.now();
        if (!force && now - askedAt < 10000) { return; }
        askedAt = now;

        fetch('api/auth/session.php', { credentials: 'same-origin', cache: 'no-store' })
            .then(function (response) { return response.ok ? response.json() : null; })
            .then(function (state) {
                if (!state || !state.ok) { return; }
                if ((state.signed_in ? 'signed-in' : 'signed-out') !== renderedFor) {
                    window.location.reload();
                }
            })
            .catch(function () { /* offline: nothing to decide on */ });
    }

    window.addEventListener('pageshow', function (event) {
        if (event.persisted) { recheck(true); }
    });

    /* An API call from the app that finds no session — not signed in (401),
       or a token from a session that is gone (419) — means the app is on
       screen for nobody: ask, and let the answer put the right screen up.
       The response itself is passed on untouched. */
    if (renderedFor === 'signed-in' && typeof window.fetch === 'function') {
        var nativeFetch = window.fetch;
        window.fetch = function () {
            return nativeFetch.apply(this, arguments).then(function (response) {
                if (response.status === 401 || response.status === 419) { recheck(true); }
                return response;
            });
        };
    }

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') { recheck(false); }
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
