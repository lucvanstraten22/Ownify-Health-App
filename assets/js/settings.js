/**
 * settings.js — Instellingen.
 *
 * Three small things, and nothing that pretends to save:
 *
 *   choices        pick one option; the tick moves, nothing is stored
 *   switches       Privacy's switches that save, at once (api/profile/privacy.php)
 *   integrations   expand a health source in place
 *   account        sign out, and deleting the account — two confirmations,
 *                  then api/profile/delete.php, which really deletes it
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

    /* ------------------------------------------------- switches that save */

    /**
     * A switch with `data-setting-toggle` (Instellingen → Privacy) saves at
     * once. It moves when pressed, waits while the server answers, and moves
     * back — saying why — when it could not be saved. What it changes on the
     * leaderboards is swapped in straight after.
     */
    function setSwitch(toggle, on, error) {
        var mark = toggle.querySelector('.switch');
        var note = toggle.querySelector('[data-setting-toggle-note]');

        toggle.setAttribute('aria-checked', on ? 'true' : 'false');
        if (mark) { mark.classList.toggle('is-on', on); }
        if (note) {
            note.textContent = error || toggle.getAttribute(on ? 'data-note-on' : 'data-note-off');
            note.classList.toggle('is-error', !!error);
        }
    }

    document.addEventListener('click', function (event) {
        var toggle = event.target.closest('[data-setting-toggle]');
        if (!toggle || toggle.disabled) { return; }

        var key    = toggle.getAttribute('data-setting-toggle');
        var before = toggle.getAttribute('aria-checked') === 'true';
        var panel  = document.querySelector('[data-account]');
        var body   = new FormData();

        body.append('csrf', panel ? (panel.getAttribute('data-csrf') || '') : '');
        body.append(key, before ? '0' : '1');

        setSwitch(toggle, !before);
        toggle.disabled = true;

        fetch('api/profile/privacy.php', { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (response) {
                return response.json().catch(function () {
                    return { ok: false, error: 'Onverwacht antwoord van de server.' };
                });
            })
            .catch(function () { return { ok: false, error: 'De server is niet bereikbaar.' }; })
            .then(function (answer) {
                toggle.disabled = false;

                if (!answer || !answer.ok) {
                    setSwitch(toggle, before, (answer && answer.error) || 'Dit kon niet worden opgeslagen.');
                    return;
                }

                setSwitch(toggle, !!answer[key]);
                if (window.AppBoards) { window.AppBoards.refresh(); }
            });
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

    /* --------------------------------------------------------- koppelingen */

    /**
     * Connecting and disconnecting an outside health source.
     *
     * Connecting is a redirect, not a fetch: the provider's consent screen has
     * to be the top-level page the user is looking at, so they can see whose
     * it is and what it is asking for. A consent screen inside a fetch would
     * be a phishing pattern, and every provider blocks it.
     *
     * Disconnecting is a normal request, then a reload — the row, the count on
     * the settings page and the sync summary all follow from stored state, so
     * re-reading is both simpler and more truthful than patching three places.
     */
    document.addEventListener('click', function (event) {
        var connect = event.target.closest('[data-integration-connect]');
        if (connect) {
            window.location.href = 'api/integrations/'
                + encodeURIComponent(connect.getAttribute('data-integration-connect'))
                + '/start.php';
            return;
        }

        var pair = event.target.closest('[data-integration-pair]');
        if (pair) {
            openPairing(pair.getAttribute('data-integration-pair'));
            return;
        }

        /* One phone, not the whole source. Same shape of request as
           disconnecting, deliberately — it is the same kind of decision at a
           smaller scale, and the page already teaches what that looks like. */
        var revoke = event.target.closest('[data-device-revoke]');
        if (revoke) {
            postAndReload(revoke, 'api/integrations/device-revoke.php',
                'device', revoke.getAttribute('data-device-revoke'));
            return;
        }

        var disconnect = event.target.closest('[data-integration-disconnect]');
        if (!disconnect) { return; }

        postAndReload(disconnect, 'api/integrations/disconnect.php',
            'provider', disconnect.getAttribute('data-integration-disconnect'));
    });

    /**
     * Posts one named field with the CSRF token, then reloads on success.
     *
     * Both buttons here change what the server holds and then need the page to
     * say so, and the page is rendered server-side — so a reload is the honest
     * way to show the result rather than patching the DOM into a shape the
     * next render might disagree with.
     *
     * The button is re-enabled on failure. Leaving it disabled would mean a
     * network blip costs the user the ability to try again without reloading.
     */
    function postAndReload(button, url, field, value) {
        var panel = document.querySelector('[data-account]');
        var body = new FormData();

        body.append('csrf', panel ? (panel.getAttribute('data-csrf') || '') : '');
        body.append(field, value || '');

        button.disabled = true;

        fetch(url, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (response) {
                return response.json().catch(function () { return { ok: false }; });
            })
            .catch(function () { return { ok: false }; })
            .then(function (result) {
                if (result && result.ok) {
                    window.location.reload();
                } else {
                    button.disabled = false;
                }
            });
    }

    /* ------------------------------------------------------ koppelcode */

    /**
     * Shows a pairing code for a source that lives on a phone.
     *
     * Fetched when the panel opens, never rendered into the page: a code in
     * the HTML would be minted on every visit to settings, whether or not
     * anybody wanted one, and each one expires in ten minutes.
     */
    var pairing = document.querySelector('[data-settings-pairing]');
    var pairingProvider = null;

    function openPairing(provider) {
        if (!pairing) { return; }

        pairingProvider = provider;
        pairing.hidden = false;
        window.requestAnimationFrame(function () { pairing.classList.add('is-open'); });
        requestPairingCode();
    }

    function closePairing() {
        if (!pairing) { return; }
        pairing.classList.remove('is-open');
        window.setTimeout(function () { pairing.hidden = true; }, 200);
        pairingProvider = null;
        // A closed panel must not leave the last code on screen behind it.
        pairing.querySelector('[data-pairing-code]').textContent = '••••••••';
        pairing.querySelector('[data-pairing-expiry]').textContent = '';
    }

    function requestPairingCode() {
        if (!pairing || !pairingProvider) { return; }

        var codeEl   = pairing.querySelector('[data-pairing-code]');
        var expiryEl = pairing.querySelector('[data-pairing-expiry]');
        var errorEl  = pairing.querySelector('[data-pairing-error]');

        codeEl.textContent = '••••••••';
        expiryEl.textContent = '';
        errorEl.hidden = true;

        var panel = document.querySelector('[data-account]');
        var body = new FormData();
        body.append('csrf', panel ? (panel.getAttribute('data-csrf') || '') : '');
        body.append('provider', pairingProvider);

        fetch('api/integrations/pairing-code.php', {
            method: 'POST', body: body, credentials: 'same-origin'
        })
            .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
            .catch(function () { return { ok: false }; })
            .then(function (result) {
                if (!result || !result.ok) {
                    errorEl.textContent = (result && result.error) || 'Er kon geen code worden gemaakt.';
                    errorEl.hidden = false;
                    return;
                }

                codeEl.textContent = result.code;
                expiryEl.textContent = 'Geldig voor ' + Math.round((result.expires_in || 600) / 60) + ' minuten.';
            });
    }

    if (pairing) {
        pairing.addEventListener('click', function (event) {
            if (event.target.closest('[data-pairing-refresh]')) { requestPairingCode(); return; }
            if (event.target.closest('[data-pairing-close]')) { closePairing(); }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !pairing.hidden) {
                event.stopPropagation();
                closePairing();
            }
        });
    }

    /* ------------------------------------------------------- field editor */

    /**
     * Editing one profile field.
     *
     * One panel serves every field: what it renders comes from the field's own
     * data attributes, so adding a field to config/settings.php needs no
     * change here. The row is updated from the server's answer rather than
     * from what was typed — the endpoint is what decides what was stored.
     */
    var editor = document.querySelector('[data-settings-editor]');

    if (editor) {
        var form     = editor.querySelector('[data-editor-form]');
        var titleEl  = editor.querySelector('[data-editor-title]');
        var onceEl   = editor.querySelector('[data-editor-once]');
        var control  = editor.querySelector('[data-editor-control]');
        var errorEl  = editor.querySelector('[data-editor-error]');
        var saveBtn  = editor.querySelector('[data-editor-save]');
        var current  = null;        // the field being edited
        var lastEdit = null;        // where focus goes back to

        function csrf() {
            var panel = document.querySelector('[data-account]');
            return panel ? (panel.getAttribute('data-csrf') || '') : '';
        }

        function setError(message) {
            errorEl.textContent = message || '';
            errorEl.hidden = !message;
        }

        /** Builds the input for one field and returns how to read it back. */
        function renderControl(spec) {
            control.innerHTML = '';

            if (spec.type === 'choice') {
                var chosen = spec.value;
                var wrap = document.createElement('div');
                wrap.className = 'field-editor__options';

                Object.keys(spec.options).forEach(function (key) {
                    var option = document.createElement('button');
                    option.type = 'button';
                    option.className = 'field-editor__option press'
                        + (key === chosen ? ' is-active' : '');
                    option.textContent = spec.options[key];
                    option.setAttribute('aria-pressed', key === chosen ? 'true' : 'false');

                    option.addEventListener('click', function () {
                        chosen = key;
                        Array.prototype.forEach.call(wrap.children, function (other) {
                            var active = other === option;
                            other.classList.toggle('is-active', active);
                            other.setAttribute('aria-pressed', active ? 'true' : 'false');
                        });
                    });

                    wrap.appendChild(option);
                });

                control.appendChild(wrap);
                return function () { return chosen; };
            }

            var input = document.createElement('input');
            input.className = 'field-editor__input';
            input.type = spec.type === 'number' ? 'number' : spec.type;
            input.value = spec.value || '';

            if (spec.maxlength) { input.maxLength = spec.maxlength; }
            if (spec.min !== undefined) { input.min = spec.min; }
            if (spec.max !== undefined) { input.max = spec.max; }
            if (spec.step) { input.step = spec.step; }
            // A decimal keypad for a weight, a plain one for a year.
            if (spec.type === 'number') { input.inputMode = 'decimal'; }

            /* A unit belongs beside the number, not in a label above it. */
            if (spec.unit) {
                var row = document.createElement('div');
                row.className = 'field-editor__measure';
                row.appendChild(input);

                var unit = document.createElement('span');
                unit.className = 'field-editor__unit';
                unit.textContent = spec.unit;
                row.appendChild(unit);

                control.appendChild(row);
            } else {
                control.appendChild(input);
            }

            return function () { return input.value.trim(); };
        }

        function openEditor(trigger) {
            var spec;

            try {
                spec = JSON.parse(trigger.getAttribute('data-field-input'));
            } catch (e) {
                return;
            }

            lastEdit = trigger;
            current = {
                key: trigger.getAttribute('data-field-edit'),
                once: trigger.getAttribute('data-field-once') === '1',
                endpoint: spec.endpoint,
                read: renderControl(spec)
            };

            titleEl.textContent = trigger.getAttribute('data-field-label') || '';
            onceEl.hidden = !current.once;
            setError('');
            saveBtn.disabled = false;

            editor.hidden = false;
            // One frame before the class, so the transition has a start state.
            window.requestAnimationFrame(function () { editor.classList.add('is-open'); });

            var first = control.querySelector('input, button');
            if (first) { first.focus({ preventScroll: true }); }
        }

        function closeEditor() {
            editor.classList.remove('is-open');
            window.setTimeout(function () { editor.hidden = true; }, 200);
            current = null;

            if (lastEdit && lastEdit.focus) { lastEdit.focus({ preventScroll: true }); }
        }

        function save() {
            if (!current) { return; }

            var value = current.read();

            if (value === '') {
                setError('Vul eerst een waarde in.');
                return;
            }

            var body = new FormData();
            body.append('csrf', csrf());
            body.append(current.key, value);

            saveBtn.disabled = true;
            setError('');

            fetch(current.endpoint, { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function (response) {
                    return response.json().catch(function () {
                        return { ok: false, error: 'Onverwacht antwoord van de server.' };
                    });
                })
                .catch(function () {
                    return { ok: false, error: 'De server is niet bereikbaar.' };
                })
                .then(function (result) {
                    saveBtn.disabled = false;

                    if (!result || !result.ok) {
                        setError((result && result.error) || 'Opslaan is niet gelukt.');
                        return;
                    }

                    /* The row, the lock and the derived age all follow from
                       what was stored, so the page is re-read rather than
                       patched in three places from what was typed. */
                    window.location.reload();
                });
        }

        document.addEventListener('click', function (event) {
            var trigger = event.target.closest('[data-field-edit]');
            if (trigger) {
                event.preventDefault();
                openEditor(trigger);
                return;
            }

            if (event.target.closest('[data-editor-close]')) {
                closeEditor();
            }
        });

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            save();
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !editor.hidden) {
                event.stopPropagation();
                closeEditor();
            }
        });
    }

    /* ------------------------------------------------------------- delete */

    /**
     * Deleting the account: two steps in one sheet, and nothing leaves the
     * browser until the second.
     *
     *   1  what goes — "Ja, verwijderen" only moves to step 2
     *   2  "Weet je het zeker?" — "Definitief verwijderen" calls the endpoint
     *
     * Closing the sheet, from either step, always starts over at step 1.
     */
    var confirmPanel = document.querySelector('[data-settings-confirm]');
    var lastFocus = null;

    function showStep(step) {
        Array.prototype.forEach.call(confirmPanel.querySelectorAll('[data-confirm-step]'), function (panel) {
            panel.hidden = panel.getAttribute('data-confirm-step') !== String(step);
        });

        var error = confirmPanel.querySelector('[data-confirm-error]');
        if (error) { error.hidden = true; error.textContent = ''; }

        /* Focus the safe answer of whichever step is showing. */
        var current = confirmPanel.querySelector('[data-confirm-step="' + step + '"]');
        var safe = current ? current.querySelector('button[data-confirm-close]') : null;
        if (safe) { safe.focus({ preventScroll: true }); }
    }

    function openConfirm(trigger) {
        if (!confirmPanel) { return; }

        lastFocus = trigger || document.activeElement;
        confirmPanel.hidden = false;
        // One frame before the class, so the transition has a start state.
        window.requestAnimationFrame(function () { confirmPanel.classList.add('is-open'); });

        showStep(1);
    }

    function closeConfirm() {
        if (!confirmPanel) { return; }

        confirmPanel.classList.remove('is-open');
        window.setTimeout(function () {
            confirmPanel.hidden = true;
            showStep(1);
        }, 200);

        if (lastFocus && lastFocus.focus) { lastFocus.focus({ preventScroll: true }); }
    }

    /** Step 2's one real action. The account comes from the session. */
    function deleteAccount(button) {
        var panel = document.querySelector('[data-account]');
        var body = new FormData();
        body.append('csrf', panel ? (panel.getAttribute('data-csrf') || '') : '');
        body.append('confirm', 'verwijderen');

        button.disabled = true;

        fetch('api/profile/delete.php', { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (response) {
                return response.json().catch(function () { return { ok: false }; });
            })
            .catch(function () {
                return { ok: false, error: 'De server is niet bereikbaar. Er is niets verwijderd.' };
            })
            .then(function (result) {
                if (result && result.ok) {
                    /* Gone. An account with Google makes one short trip past
                       Google so it can forget Ownify too; otherwise the page
                       reloads, signed out, and says what happened. */
                    if (result.redirect) {
                        window.location.assign(result.redirect);
                    } else {
                        window.location.reload();
                    }
                    return;
                }

                button.disabled = false;

                var error = confirmPanel.querySelector('[data-confirm-error]');
                if (error) {
                    error.textContent = (result && result.error) || 'Je account kon niet worden verwijderd.';
                    error.hidden = false;
                }
            });
    }

    var deleteButton = page.querySelector('[data-settings-delete]');
    if (deleteButton) {
        deleteButton.addEventListener('click', function () {
            if (!deleteButton.disabled) { openConfirm(deleteButton); }
        });
    }

    if (confirmPanel) {
        confirmPanel.addEventListener('click', function (event) {
            if (event.target.closest('[data-confirm-close]')) { closeConfirm(); return; }
            if (event.target.closest('[data-confirm-next]')) { showStep(2); return; }

            var final = event.target.closest('[data-confirm-delete]');
            if (final && !final.disabled) { deleteAccount(final); }
        });
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && confirmPanel && !confirmPanel.hidden) { closeConfirm(); }
    });
}());
