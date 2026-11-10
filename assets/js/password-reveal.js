/**
 * password-reveal.js — the eye in every password field (45.0).
 *
 * Registering, signing in, a new or changed password, confirming with the
 * current one: each password field gets one round button at its right, the
 * in-field button of the goal search (`.goal-search__clear`, goals.css),
 * with the eye from icons.php. A tap shows what was typed; another hides it.
 *
 *   - It is added to the fields on the page and to every one a panel builds
 *     later (the Settings editors), so no screen is left without it.
 *   - It never takes the field's focus or moves the caret: the field keeps
 *     what it was doing, as if the eye were not there.
 *   - Sending the form, or closing the panel it is in, hides the password
 *     again — a browser and a password manager only ever see a password
 *     field leave, never a text field.
 */
(function () {
    'use strict';

    var T = window.OwnifyI18n;
    var icons = document.querySelector('template[data-password-icons]');
    if (!icons) { return; }

    function iconMarkup(name) {
        var node = icons.content.querySelector('[data-icon="' + name + '"]');
        return node ? node.innerHTML : '';
    }

    function say(button, shown) {
        button.setAttribute('aria-pressed', shown ? 'true' : 'false');
        button.setAttribute('aria-label', shown ? T.t('Wachtwoord verbergen') : T.t('Wachtwoord tonen'));
        button.innerHTML = iconMarkup(shown ? 'eye-off' : 'eye');
    }

    function hide(input) {
        var button = input.__ownifyReveal;
        if (!button || input.type === 'password') { return; }
        input.type = 'password';
        say(button, false);
    }

    function enhance(input) {
        if (input.__ownifyReveal || input.closest('.password-field')) { return; }

        var wrap = document.createElement('span');
        wrap.className = 'password-field';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'password-field__toggle press';
        say(button, false);
        wrap.appendChild(button);
        input.__ownifyReveal = button;

        /* The field keeps its focus and its caret. */
        button.addEventListener('pointerdown', function (event) { event.preventDefault(); });
        button.addEventListener('click', function () {
            var shown = input.type === 'password';
            var start = input.selectionStart;
            var end = input.selectionEnd;
            input.type = shown ? 'text' : 'password';
            say(button, shown);
            /* The browser puts the caret back at the start when the type
               changes, a moment later: put it back where it was after that. */
            if (document.activeElement === input && start !== null) {
                var restore = function () {
                    try { input.setSelectionRange(start, end); } catch (error) { /* not every type has one */ }
                };
                restore();
                window.requestAnimationFrame(restore);
            }
        });

        var form = input.form;
        if (form && !form.__ownifyReveal) {
            form.__ownifyReveal = true;
            /* Capture: hidden again before the form's own handler reads it. */
            form.addEventListener('submit', function () {
                Array.prototype.forEach.call(form.querySelectorAll('.password-field input'), hide);
            }, true);
        }
    }

    function scan(root) {
        if (root.nodeType !== 1) { return; }
        if (root.matches && root.matches('input[type="password"]')) { enhance(root); }
        Array.prototype.forEach.call(root.querySelectorAll('input[type="password"]'), enhance);
    }

    scan(document.body);

    /* The fields a panel builds later — the Settings editors. */
    new MutationObserver(function (records) {
        records.forEach(function (record) {
            Array.prototype.forEach.call(record.addedNodes, scan);
        });
    }).observe(document.body, { childList: true, subtree: true });

    /* A panel that closes takes what was shown with it. */
    document.addEventListener('click', function (event) {
        if (!event.target.closest('[data-account-close], [data-editor-close], [data-confirm-close]')) { return; }
        Array.prototype.forEach.call(document.querySelectorAll('.password-field input'), hide);
    });
}());
