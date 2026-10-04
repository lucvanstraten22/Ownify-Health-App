/**
 * The setup a new account starts with (pages/setup.php).
 *
 * Four steps in one page, switched with a class as the goal wizard switches
 * its own, so going back finds every answer where it was left. Each answer
 * is saved by the endpoint that always saves it — the focus and the body
 * measurements by api/profile/update.php, the birth date by
 * api/profile/onboarding.php, a goal by api/goals/create.php (the wizard's,
 * or the suggestion's) — and api/setup/finish.php ends it. Then the page is
 * read again, and the server, not this script, decides it is the app now.
 *
 * Every word comes from the page; nothing Dutch is written here except the
 * two that say the server could not be reached.
 */

(function () {
    'use strict';

    var root = document.querySelector('[data-setup]');
    if (!root) { return; }

    var ORDER    = ['focus', 'connect', 'profile', 'goal'];
    var body     = root.querySelector('[data-setup-body]');
    var count    = root.querySelector('[data-setup-count]');
    var backBtn  = root.querySelector('[data-setup-back]');
    var skipBtn  = root.querySelector('[data-setup-skip]');
    var total    = parseInt(root.getAttribute('data-total'), 10) || ORDER.length;
    var template = root.getAttribute('data-count') || '%1$d / %2$d';

    var current = 'focus';
    var busy = false;

    var chosen = root.querySelector('[data-setup-focus][aria-checked="true"]');
    var focus = chosen ? chosen.getAttribute('data-setup-focus') : null;
    var savedFocus = focus;

    function each(selector, fn) {
        Array.prototype.forEach.call(root.querySelectorAll(selector), fn);
    }

    function nextButton(id) {
        return root.querySelector('[data-setup-next="' + id + '"]');
    }

    function csrf() {
        return root.getAttribute('data-csrf') || '';
    }

    /** Posts a form and returns the parsed answer, whatever the status. */
    function post(url, fields) {
        var form = new FormData();
        form.append('csrf', csrf());
        Object.keys(fields).forEach(function (key) { form.append(key, fields[key]); });

        return fetch(url, { method: 'POST', body: form, credentials: 'same-origin' })
            .then(function (response) {
                return response.json().catch(function () {
                    return { ok: false, error: '' };
                });
            })
            .catch(function () {
                return { ok: false, error: 'Ownify is niet bereikbaar. Controleer je internetverbinding en probeer het opnieuw.' };
            });
    }

    function showError(step, message) {
        var slot = root.querySelector('[data-setup-error="' + step + '"]');
        if (!slot) { return; }
        slot.textContent = message ? message : '';
        slot.hidden = !message;
    }

    function fail(step, result) {
        var slot = root.querySelector('[data-setup-error="' + step + '"]');
        var fallback = slot ? slot.getAttribute('data-message') : '';
        showError(step, (result && result.error) || fallback);
    }

    function setBusy(state) {
        busy = state;
        each('.setup__foot .btn', function (button) {
            if (state) {
                button.setAttribute('data-was-disabled', button.disabled ? '1' : '0');
                button.disabled = true;
            } else if (button.hasAttribute('data-was-disabled')) {
                button.disabled = button.getAttribute('data-was-disabled') === '1';
                button.removeAttribute('data-was-disabled');
            }
        });
    }

    /* ----------------------------------------------------------- moving */

    function show(id, direction, quiet) {
        var index = ORDER.indexOf(id);
        var active = null;

        body.setAttribute('data-dir', direction < 0 ? 'back' : 'forward');

        each('[data-setup-step]', function (section) {
            var on = section.getAttribute('data-setup-step') === id;
            section.hidden = !on;
            section.classList.toggle('is-active', on && !quiet);
            if (on) { active = section; }
        });

        each('[data-setup-bar]', function (bar) {
            bar.classList.toggle('is-done', ORDER.indexOf(bar.getAttribute('data-setup-bar')) <= index);
        });

        if (count && active) {
            count.textContent = template.replace('%1$d', String(index + 1)).replace('%2$d', String(total))
                + ' · ' + active.getAttribute('data-label');
        }

        backBtn.hidden = index === 0;
        skipBtn.hidden = id !== 'profile';
        each('[data-setup-next]', function (button) {
            button.hidden = button.getAttribute('data-setup-next') !== id;
        });

        current = id;
        ORDER.forEach(function (step) { showError(step, ''); });

        if (!quiet) {
            window.scrollTo(0, 0);
            var title = active ? active.querySelector('.setup__title') : null;
            if (title) {
                title.setAttribute('tabindex', '-1');
                title.focus({ preventScroll: true });
            }
        }
    }

    function go(step) {
        show(ORDER[ORDER.indexOf(current) + step], step);
    }

    backBtn.addEventListener('click', function () {
        if (!busy && ORDER.indexOf(current) > 0) { go(-1); }
    });

    /* ------------------------------------------------------- 1 · focus */

    function choose(key) {
        focus = key;
        each('[data-setup-focus]', function (option) {
            option.setAttribute('aria-checked', option.getAttribute('data-setup-focus') === key ? 'true' : 'false');
        });
        nextButton('focus').disabled = false;
    }

    each('[data-setup-focus]', function (option) {
        option.addEventListener('click', function () {
            choose(option.getAttribute('data-setup-focus'));
        });
    });

    /* A radio group: the arrow keys move the answer, as a radio group does. */
    root.addEventListener('keydown', function (event) {
        var option = event.target.closest && event.target.closest('[data-setup-focus]');
        if (!option || ['ArrowDown', 'ArrowUp', 'ArrowRight', 'ArrowLeft'].indexOf(event.key) === -1) { return; }

        var options = Array.prototype.slice.call(root.querySelectorAll('[data-setup-focus]'));
        var step = event.key === 'ArrowDown' || event.key === 'ArrowRight' ? 1 : -1;
        var next = options[(options.indexOf(option) + step + options.length) % options.length];

        event.preventDefault();
        next.focus();
        choose(next.getAttribute('data-setup-focus'));
    });

    nextButton('focus').addEventListener('click', function () {
        if (busy || !focus) { return; }

        if (focus === savedFocus) {
            go(1);
            return;
        }

        setBusy(true);
        post('api/profile/update.php', { focus: focus }).then(function (result) {
            setBusy(false);
            if (!result.ok) {
                fail('focus', result);
                return;
            }
            savedFocus = focus;
            go(1);
        });
    });

    /* ----------------------------------------------------- 2 · gegevens */

    nextButton('connect').addEventListener('click', function () {
        if (!busy) { go(1); }
    });

    /* ----------------------------------------------------- 3 · over jou */

    var fields = Array.prototype.slice.call(root.querySelectorAll('[data-setup-field]'));

    /** The fields with something new in them. */
    function changed() {
        return fields.filter(function (field) {
            var value = field.value.trim();
            return value !== '' && value !== (field.getAttribute('data-initial') || '');
        });
    }

    function refreshSave() {
        nextButton('profile').disabled = changed().length === 0;
    }

    fields.forEach(function (field) {
        field.addEventListener('input', refreshSave);
        field.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                if (!nextButton('profile').disabled) { nextButton('profile').click(); }
            }
        });
    });
    refreshSave();

    nextButton('profile').addEventListener('click', function () {
        if (busy) { return; }

        /* One request per endpoint: the measurements together, the birth
           date on its own (it is the one that refuses a second answer). */
        var byEndpoint = {};
        changed().forEach(function (field) {
            var endpoint = field.getAttribute('data-endpoint');
            byEndpoint[endpoint] = byEndpoint[endpoint] || {};
            byEndpoint[endpoint][field.getAttribute('data-setup-field')] = field.value.trim();
        });

        var endpoints = Object.keys(byEndpoint);
        if (endpoints.length === 0) {
            go(1);
            return;
        }

        setBusy(true);
        showError('profile', '');

        var chain = Promise.resolve({ ok: true });
        endpoints.forEach(function (endpoint) {
            chain = chain.then(function (previous) {
                if (!previous.ok) { return previous; }
                return post(endpoint, byEndpoint[endpoint]).then(function (result) {
                    if (result.ok) {
                        /* Saved: not sent again if the person comes back here. */
                        Object.keys(byEndpoint[endpoint]).forEach(function (key) {
                            var field = root.querySelector('[data-setup-field="' + key + '"]');
                            if (field) { field.setAttribute('data-initial', field.value.trim()); }
                        });
                    }
                    return result;
                });
            });
        });

        chain.then(function (result) {
            setBusy(false);
            refreshSave();
            if (!result.ok) {
                fail('profile', result);
                return;
            }
            go(1);
        });
    });

    skipBtn.addEventListener('click', function () {
        if (!busy) { go(1); }
    });

    /* --------------------------------------------------------- 4 · doel */

    var suggestion = root.querySelector('[data-setup-suggestion]');
    var own = root.querySelector('[data-setup-own]');
    var added = root.querySelector('[data-setup-added]');

    /** A goal exists now: say so, and offer no second one here. */
    function goalAdded(name) {
        if (suggestion) { suggestion.hidden = true; }
        if (own) { own.hidden = true; }
        if (added) {
            added.querySelector('[data-setup-added-text]').textContent =
                (added.getAttribute('data-template') || '%s').replace('%s', name || '');
            added.hidden = false;
        }
    }

    if (suggestion) {
        suggestion.querySelector('[data-setup-suggestion-add]').addEventListener('click', function () {
            if (busy) { return; }

            var input;
            try {
                input = JSON.parse(suggestion.getAttribute('data-input'));
            } catch (e) {
                return;
            }

            setBusy(true);
            this.disabled = true;
            var button = this;

            post('api/goals/create.php', input).then(function (result) {
                setBusy(false);
                button.disabled = false;
                if (!result.ok) {
                    fail('goal', result);
                    return;
                }
                goalAdded(suggestion.getAttribute('data-name'));
            });
        });

        suggestion.querySelector('[data-setup-suggestion-decline]').addEventListener('click', function () {
            suggestion.hidden = true;
            if (own) { own.focus({ preventScroll: true }); }
        });
    }

    if (own) {
        own.addEventListener('click', function () {
            if (window.GoalWizard) { window.GoalWizard.open(own); }
        });
    }

    document.addEventListener('goalwizard:saved', function (event) {
        goalAdded(event.detail && event.detail.name);
    });

    nextButton('goal').addEventListener('click', function () {
        if (busy) { return; }

        setBusy(true);
        post('api/setup/finish.php', {}).then(function (result) {
            if (!result.ok) {
                setBusy(false);
                fail('goal', result);
                return;
            }
            /* The server says it is the app now. */
            window.location.reload();
        });
    });

    /* ---------------------------------------------------------- leaving */

    root.querySelector('[data-setup-logout]').addEventListener('click', function () {
        if (busy) { return; }
        setBusy(true);
        post('api/auth/logout.php', {}).then(function () {
            window.location.reload();
        });
    });

    /* A reload in the middle starts where it makes sense (lib/hydrate-setup.php). */
    var resume = root.getAttribute('data-resume');
    if (resume && resume !== 'focus' && ORDER.indexOf(resume) > 0) {
        show(resume, 1, true);
    }
})();
