/**
 * manual-entry.js — a night or a training entered by hand, and a training
 * removed (40.0, docs/MANUAL-ENTRY.md).
 *
 * The two entry pages (pages/manual-entry.php) are one layout and one
 * script: the fields are read by name, sent to the page's own endpoint, and
 * what the server says is wrong is shown above the buttons. Slaap's page
 * says, as the fields are typed, which night they make: a bedtime later in
 * the day than the wake-up time was the evening before.
 *
 * Once stored, the page is read again and opens where it was left — Slaap
 * or Training — so every part that reads the night or the training (the
 * area, its charts, the Verloop, the Scorekompas, Overzicht, the goals)
 * shows it, worked out by the server as after a sync. Removing a training,
 * from its own page, is goal-detail.php's delete and its confirmation.
 */

(function () {
    'use strict';

    var T = window.OwnifyI18n;
    var nav = window.AppNav;
    var RETURN = 'ownify-entry-return';

    function csrf() {
        var panel = document.querySelector('[data-account]');
        return panel ? (panel.getAttribute('data-csrf') || '') : '';
    }

    function post(url, fields) {
        var body = new FormData();
        body.append('csrf', csrf());
        Object.keys(fields).forEach(function (key) { body.append(key, fields[key]); });

        return fetch(url, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (response) { return response.json().catch(function () { return { ok: false }; }); })
            .catch(function () { return { ok: false, offline: true }; });
    }

    /** Read the page again and come back to `area`'s page, as it now is. */
    function reopen(area) {
        try { window.sessionStorage.setItem(RETURN, area); } catch (error) { /* reloads on Overzicht instead */ }
        window.location.reload();
    }

    /* After the reload: back on the area the entry was made from. */
    (function () {
        var area = null;
        try {
            area = window.sessionStorage.getItem(RETURN);
            window.sessionStorage.removeItem(RETURN);
        } catch (error) { area = null; }
        if (!area || !nav || !nav.pages || !nav.details) { return; }
        nav.pages.goToId('health');
        window.setTimeout(function () { nav.details.open(area); }, nav.duration + 60);
    }());

    /* ------------------------------------------------------- the two pages */

    /* A field's value for the form: a date on the wheel keeps "YYYY-MM-DD" beside what it shows. */
    function valueOf(input) {
        return window.OwnifyWheel ? window.OwnifyWheel.value(input) : input.value;
    }

    function minutesOf(value) {
        var m = /^(\d{1,2}):(\d{2})$/.exec(value || '');
        return m ? Number(m[1]) * 60 + Number(m[2]) : null;
    }

    function clock(total) {
        var h = Math.floor(total / 60), m = total % 60;
        return (h < 10 ? '0' : '') + h + ':' + (m < 10 ? '0' : '') + m;
    }

    function day(d) {
        return T.weekday(d.getDay(), true) + ' ' + T.date(d, 'day_mon');
    }

    /** Slaap's line: "zo 2 nov 23:00 tot ma 3 nov 07:00 · 8:00 u". */
    function paintSpan(page) {
        var line = page.querySelector('[data-entry-span]');
        if (!line) { return; }

        var date = valueOf(page.querySelector('[data-entry-field="date"]'));
        var bed = minutesOf(page.querySelector('[data-entry-field="bedtime"]').value);
        var wake = minutesOf(page.querySelector('[data-entry-field="wakeup"]').value);
        var parts = /^(\d{4})-(\d{2})-(\d{2})$/.exec(date || '');
        if (!parts || bed === null || wake === null || bed === wake) { line.textContent = ''; return; }

        var morning = new Date(Number(parts[1]), Number(parts[2]) - 1, Number(parts[3]));
        var evening = new Date(morning.getTime());
        if (bed > wake) { evening.setDate(evening.getDate() - 1); }
        var length = bed > wake ? 1440 - bed + wake : wake - bed;
        var hours = Math.floor(length / 60) + ':' + (length % 60 < 10 ? '0' : '') + (length % 60) + ' ' + T.t('u');

        /* The words are the server's, already in the reader's language. */
        var parts3 = [day(evening) + ' ' + clock(bed), day(morning) + ' ' + clock(wake), hours];
        line.textContent = line.getAttribute('data-format').replace(/%(\d)\$s/g, function (all, n) { return parts3[Number(n) - 1]; });
    }

    function showError(root, message) {
        var error = root.querySelector('[data-entry-error]');
        if (!error) { return; }
        error.textContent = message || '';
        error.hidden = !message;
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-manual-entry]'), function (page) {
        var kind = page.getAttribute('data-manual-entry');
        var form = page.querySelector('[data-entry-form]');
        var save = page.querySelector('[data-entry-save]');
        var label = save.textContent;
        var busy = false;

        if (kind === 'sleep') {
            form.addEventListener('input', function () { paintSpan(page); });
            paintSpan(page);
        }

        /* Enter in a field is Opslaan, as in the goal wizard. */
        form.addEventListener('submit', function (event) { event.preventDefault(); save.click(); });

        save.addEventListener('click', function () {
            if (busy) { return; }
            showError(page, null);

            var fields = {};
            Array.prototype.forEach.call(form.querySelectorAll('[data-entry-field]'), function (input) {
                fields[input.getAttribute('data-entry-field')] = (valueOf(input) || '').trim();
            });

            busy = true;
            save.disabled = true;
            save.textContent = save.getAttribute('data-saving');

            post(save.getAttribute('data-endpoint'), fields).then(function (result) {
                if (result && result.ok) { reopen(kind); return; }
                busy = false;
                save.disabled = false;
                save.textContent = label;
                showError(page, (result && !result.offline && result.error) || save.getAttribute('data-offline'));
            });
        });
    });

    /* ------------------------------------------------ removing a training */

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-entry-action]');
        if (!trigger) { return; }
        var section = trigger.closest('[data-entry-remove]');
        if (!section) { return; }

        var action = trigger.getAttribute('data-entry-action');
        var confirm = section.querySelector('[data-entry-confirm]');
        var button = section.querySelector('[data-entry-action="remove"]');

        if (action === 'remove') {
            confirm.hidden = false;
            button.hidden = true;
            return;
        }

        if (action === 'remove-cancel') {
            confirm.hidden = true;
            button.hidden = false;
            return;
        }

        if (action === 'remove-confirm') {
            if (trigger.disabled) { return; }
            trigger.disabled = true;
            showError(section, null);
            post('api/health/training-delete.php', { workout_id: section.getAttribute('data-entry-remove') }).then(function (result) {
                if (result && result.ok) { reopen('training'); return; }
                trigger.disabled = false;
                showError(section, (result && !result.offline && result.error) || T.t('Ownify is niet bereikbaar. Controleer je internetverbinding en probeer het opnieuw.'));
            });
        }
    });
}());
