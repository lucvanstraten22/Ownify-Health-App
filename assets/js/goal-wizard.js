/**
 * goal-wizard.js — the five-step create-a-goal flow.
 *
 * One question per step, and the next step is unreachable until the current
 * one has an answer. That is the whole of the validation: no error messages,
 * no red fields, just a button that stays quiet until there is something to
 * move on to.
 *
 * The third step is the reason the flow exists at all. A weight goal, a habit,
 * a streak and a milestone do not mean the same thing by "target", so the step
 * asks a different question depending on what was chosen in step 2 rather than
 * forcing every goal through one number field.
 *
 * Nothing is stored. The final screen shows the goal as it would appear on the
 * board and says plainly that saving arrives with the database, rather than
 * pretending a goal was created.
 */

(function () {
    'use strict';

    var root = document.querySelector('[data-goal-wizard]');
    if (!root) { return; }

    var copy = (window.GoalCopy && window.GoalCopy.wizard) ? window.GoalCopy : null;
    if (!copy) {
        var source = document.querySelector('[data-goals-copy]');
        try { copy = JSON.parse(source.textContent); } catch (e) { return; }
    }

    var words     = copy.wizard;
    var TOTAL     = 5;
    var MONTHS    = ['jan', 'feb', 'mrt', 'apr', 'mei', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec'];

    var body      = root.querySelector('.wizard__body');
    var count     = root.querySelector('[data-wizard-count]');
    var backBtn   = root.querySelector('[data-wizard-back]');
    var nextBtn   = root.querySelector('[data-wizard-next]');
    var doneBtn   = root.querySelector('[data-wizard-done]');
    var nameInput = root.querySelector('[data-wizard-name]');
    var suggests  = root.querySelector('[data-wizard-suggestions]');
    var unitList  = root.querySelector('[data-wizard-units]');
    var datesNote = root.querySelector('[data-wizard-dates]');
    var swapNote  = root.querySelector('[data-wizard-swap]');
    var preview   = root.querySelector('[data-wizard-preview]');

    var step = 1;
    var lastFocus = null;
    var saved = false;

    var draft = {
        category: null,
        name: '',
        type: null,
        value: '',
        unit: '',
        days: '',
        streak: '',
        duration: null,
        priority: 'secondary'
    };

    /* ------------------------------------------------------------ helpers */

    function dutchDate(date) {
        return date.getDate() + ' ' + MONTHS[date.getMonth()];
    }

    function spanText(days) {
        if (days >= 330) { return Math.round(days / 365) === 1 ? '1 jaar' : Math.round(days / 365) + ' jaar'; }
        if (days >= 60) { return Math.round(days / 30) + ' maanden'; }
        if (days >= 45) { return Math.round(days / 7) + ' weken'; }
        return days === 1 ? '1 dag' : days + ' dagen';
    }

    function endDate(days) {
        var date = new Date();
        date.setHours(0, 0, 0, 0);
        date.setDate(date.getDate() + days);
        return date;
    }

    function targetShape() {
        var type = draft.type ? copy.types[draft.type] : null;
        return type ? type.target : null;
    }

    /** The target as one readable phrase, whatever kind of goal this is. */
    function targetText() {
        var shape = targetShape();

        if (shape === 'number') {
            if (draft.value === '') { return null; }
            return (draft.unit ? draft.value + ' ' + draft.unit : draft.value);
        }

        if (shape === 'frequency') {
            return draft.days === '' ? null : draft.days + ' dagen';
        }

        if (shape === 'days') {
            return draft.streak === '' ? null : draft.streak + ' dagen op rij';
        }

        if (shape === 'none') { return 'Behaald of nog niet'; }

        return null;
    }

    /* ------------------------------------------------------- step control */

    function isComplete(which) {
        if (which === 1) { return draft.category !== null; }
        if (which === 2) { return draft.name.trim().length > 1 && draft.type !== null; }
        if (which === 3) { return targetText() !== null; }
        if (which === 4) { return draft.duration !== null; }
        return true;
    }

    function paint(direction) {
        body.setAttribute('data-dir', direction === -1 ? 'back' : 'forward');

        Array.prototype.forEach.call(root.querySelectorAll('[data-wizard-step]'), function (section) {
            var key = section.getAttribute('data-wizard-step');
            var isActive = key === String(step);
            section.hidden = !isActive;
            section.classList.toggle('is-active', isActive);
        });

        Array.prototype.forEach.call(root.querySelectorAll('[data-wizard-bar]'), function (bar) {
            bar.classList.toggle('is-done', Number(bar.getAttribute('data-wizard-bar')) <= step);
        });

        if (count) {
            count.textContent = 'Stap ' + step + ' van ' + TOTAL + ' · ' + words.steps[step].label;
        }

        backBtn.hidden = step === 1;
        nextBtn.hidden = false;
        doneBtn.hidden = true;
        nextBtn.textContent = step === TOTAL ? words.create : words.next;
        nextBtn.disabled = !isComplete(step);
    }

    function go(to, direction) {
        step = to;
        paint(direction);
        if (body) { body.scrollTop = 0; }
    }

    /* ------------------------------------------------------ 1 · category */

    function chooseCategory(key) {
        draft.category = key;

        Array.prototype.forEach.call(root.querySelectorAll('[data-wizard-category]'), function (tile) {
            tile.setAttribute('aria-pressed', tile.getAttribute('data-wizard-category') === key ? 'true' : 'false');
        });

        fillSuggestions(key);
        fillUnits(key);
        nextBtn.disabled = !isComplete(step);
    }

    /** Suggestions fill the name field. They never choose anything by themselves. */
    function fillSuggestions(key) {
        if (!suggests) { return; }

        suggests.innerHTML = '';
        var list = (words.suggestions && words.suggestions[key]) || [];

        list.forEach(function (text) {
            var item = document.createElement('li');
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'wizard-suggestion press';
            button.textContent = text;
            button.addEventListener('click', function () {
                nameInput.value = text;
                draft.name = text;
                nextBtn.disabled = !isComplete(step);
                nameInput.focus({ preventScroll: true });
            });
            item.appendChild(button);
            suggests.appendChild(item);
        });
    }

    function fillUnits(key) {
        if (!unitList) { return; }

        unitList.innerHTML = '';
        var category = copy.categories[key];
        var units = category ? category.units : [];

        units.forEach(function (unit) {
            var item = document.createElement('li');
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'wizard-suggestion press';
            button.textContent = unit;
            button.addEventListener('click', function () {
                var field = root.querySelector('[data-wizard-unit]');
                field.value = unit;
                draft.unit = unit;
                nextBtn.disabled = !isComplete(step);
            });
            item.appendChild(button);
            unitList.appendChild(item);
        });
    }

    /* ---------------------------------------------------------- 2 · type */

    function chooseType(key) {
        draft.type = key;

        Array.prototype.forEach.call(root.querySelectorAll('[data-wizard-type]'), function (option) {
            option.setAttribute('aria-pressed', option.getAttribute('data-wizard-type') === key ? 'true' : 'false');
        });

        showTargetField();
        nextBtn.disabled = !isComplete(step);
    }

    /* -------------------------------------------------------- 3 · target */

    /** Step three asks whatever question the chosen type actually implies. */
    function showTargetField() {
        var shape = targetShape();

        Array.prototype.forEach.call(root.querySelectorAll('[data-wizard-target]'), function (field) {
            field.hidden = field.getAttribute('data-wizard-target') !== shape;
        });
    }

    /* ------------------------------------------------------ 4 · duration */

    function chooseDuration(key) {
        draft.duration = key;

        Array.prototype.forEach.call(root.querySelectorAll('[data-wizard-duration]'), function (option) {
            option.setAttribute('aria-pressed', option.getAttribute('data-wizard-duration') === key ? 'true' : 'false');
        });

        var days = copy.durations[key] ? copy.durations[key].days : 0;
        var start = new Date();

        if (datesNote) {
            datesNote.hidden = false;
            datesNote.textContent = 'Van ' + dutchDate(start) + ' t/m ' + dutchDate(endDate(days))
                + ' · nog ' + spanText(days);
        }

        nextBtn.disabled = !isComplete(step);
    }

    /** Each duration tile carries the date it would run to. */
    function fillDurationMeta() {
        Array.prototype.forEach.call(root.querySelectorAll('[data-wizard-duration]'), function (option) {
            var meta = option.querySelector('[data-duration-meta]');
            if (!meta) { return; }
            meta.textContent = 't/m ' + dutchDate(endDate(Number(option.getAttribute('data-days'))));
        });
    }

    /* ------------------------------------------------------- 5 · summary */

    function fillSummary() {
        var category = draft.category ? copy.categories[draft.category] : null;
        var duration = draft.duration ? copy.durations[draft.duration] : null;

        set('name', draft.name.trim() || '—');
        set('category', category
            ? category.label + ' · ' + (copy.types[draft.type] ? copy.types[draft.type].label : '')
            : '—');
        set('target', targetText() || '—');
        set('duration', duration
            ? duration.label + ' · t/m ' + dutchDate(endDate(duration.days))
            : '—');

        function set(key, text) {
            var cell = root.querySelector('[data-summary="' + key + '"]');
            if (cell) { cell.textContent = text; }
        }
    }

    function choosePriority(key) {
        draft.priority = key;

        Array.prototype.forEach.call(root.querySelectorAll('[data-wizard-priority]'), function (option) {
            var isActive = option.getAttribute('data-wizard-priority') === key;
            option.classList.toggle('is-active', isActive);
            option.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });

        /* Only worth saying when there is in fact a primary goal to displace. */
        var current = document.querySelector('[data-goal-slot="primary"] [data-goal-card]');
        if (swapNote) { swapNote.hidden = !(key === 'primary' && current); }
    }

    /* --------------------------------------------------------- 5 · close */

    /**
     * Builds the goal as a card, using the page's own component markup, so the
     * last thing the flow shows is exactly what the board would show.
     */
    function buildPreview() {
        if (!preview) { return; }

        var category = draft.category ? copy.categories[draft.category] : null;
        var duration = draft.duration ? copy.durations[draft.duration] : null;
        var isPrimary = draft.priority === 'primary';

        var card = document.createElement('div');
        card.className = 'card goal-card goal-card--' + (isPrimary ? 'primary' : 'secondary');
        card.setAttribute('data-accent', category ? category.accent : 'health');

        var top = document.createElement('span');
        top.className = 'goal-card__top';

        /* The icon comes from the tile the user tapped, so the preview cannot
           drift away from the icon set. */
        var tile = root.querySelector('[data-wizard-category="' + draft.category + '"] .icon-tile');
        var mark = document.createElement('span');
        mark.className = 'icon-tile';
        mark.setAttribute('aria-hidden', 'true');
        if (tile) { mark.innerHTML = tile.innerHTML; }

        var heads = document.createElement('span');
        heads.className = 'goal-card__heads';

        var kind = document.createElement('span');
        kind.className = 'goal-card__category';
        kind.textContent = category ? category.label : '';

        var name = document.createElement('span');
        name.className = 'goal-card__name';
        name.textContent = draft.name.trim();

        heads.appendChild(kind);
        heads.appendChild(name);
        top.appendChild(mark);
        top.appendChild(heads);

        var figures = document.createElement('span');
        figures.className = 'goal-card__figures';

        var percent = document.createElement('span');
        percent.className = 'goal-card__percent';
        var value = document.createElement('span');
        value.className = 'goal-card__percent-value';
        value.textContent = '0';
        var sign = document.createElement('span');
        sign.className = 'goal-card__percent-sign';
        sign.textContent = '%';
        percent.appendChild(value);
        percent.appendChild(sign);

        var target = document.createElement('span');
        target.className = 'goal-card__target';
        target.textContent = targetText() || '—';

        figures.appendChild(percent);
        figures.appendChild(target);

        var meter = document.createElement('span');
        meter.className = 'meter meter--goal';
        meter.setAttribute('aria-hidden', 'true');
        var fill = document.createElement('span');
        fill.className = 'meter__fill';
        meter.appendChild(fill);

        var foot = document.createElement('span');
        foot.className = 'goal-card__foot';
        var deadline = document.createElement('span');
        deadline.className = 'goal-card__deadline';
        deadline.textContent = duration
            ? 'Nog ' + spanText(duration.days) + ' · t/m ' + dutchDate(endDate(duration.days))
            : '';
        foot.appendChild(deadline);

        card.appendChild(top);
        card.appendChild(figures);
        card.appendChild(meter);
        card.appendChild(foot);

        preview.innerHTML = '';
        preview.appendChild(card);
    }

    /* The token is minted server-side and rendered on the account panel; the
       endpoints reject anything without it. */
    function csrf() {
        var panel = document.querySelector('[data-account]');
        return panel ? (panel.getAttribute('data-csrf') || '') : '';
    }

    /** What the wizard collected, in the fields api/goals/create.php expects. */
    function payload() {
        var body = new FormData();

        body.append('csrf', csrf());
        body.append('name', draft.name);
        body.append('category', draft.category || 'other');
        body.append('type', draft.type || 'value');
        body.append('duration', draft.duration || '');
        body.append('priority', draft.priority || 'secondary');

        if (draft.type === 'value') {
            body.append('target_value', draft.value || '');
            body.append('target_unit', draft.unit || '');
        } else if (draft.type === 'habit') {
            body.append('target_value', draft.days || '');
            body.append('target_unit', 'dagen');
        } else if (draft.type === 'streak') {
            body.append('target_value', draft.streak || '');
            body.append('target_unit', 'dagen');
        }

        return body;
    }

    function showError(message) {
        var slot = root.querySelector('[data-wizard-error]');
        if (!slot) { return; }
        slot.textContent = message || '';
        slot.hidden = !message;
    }

    /**
     * Saves the goal, then shows the confirmation.
     *
     * The order matters: the last screen says the goal is on the board, so it
     * must not appear until the row exists. A failure keeps the user on step
     * five with their answers intact rather than claiming a goal was made.
     */
    function finish() {
        nextBtn.disabled = true;
        showError('');

        fetch('api/goals/create.php', { method: 'POST', body: payload(), credentials: 'same-origin' })
            .then(function (response) {
                return response.json().catch(function () {
                    return { ok: false, error: 'Onverwacht antwoord van de server.' };
                });
            })
            .catch(function () {
                return { ok: false, error: 'De server is niet bereikbaar.' };
            })
            .then(function (result) {
                nextBtn.disabled = false;

                if (!result || !result.ok) {
                    showError((result && result.error) || 'Dit doel kon niet worden opgeslagen.');
                    return;
                }

                saved = true;
                showDone();
            });
    }

    function showDone() {
        buildPreview();

        Array.prototype.forEach.call(root.querySelectorAll('[data-wizard-step]'), function (section) {
            var isDone = section.getAttribute('data-wizard-step') === 'done';
            section.hidden = !isDone;
            section.classList.toggle('is-active', isDone);
        });

        Array.prototype.forEach.call(root.querySelectorAll('[data-wizard-bar]'), function (bar) {
            bar.classList.add('is-done');
        });

        if (count) { count.textContent = words.steps[TOTAL].label; }

        backBtn.hidden = true;
        nextBtn.hidden = true;
        doneBtn.hidden = false;
        doneBtn.focus({ preventScroll: true });
    }

    /* ---------------------------------------------------------- open/close */

    function reset() {
        draft = {
            category: null, name: '', type: null, value: '', unit: '',
            days: '', streak: '', duration: null, priority: 'secondary'
        };

        Array.prototype.forEach.call(root.querySelectorAll('input'), function (field) {
            field.value = '';
        });

        Array.prototype.forEach.call(
            root.querySelectorAll('[data-wizard-category], [data-wizard-type], [data-wizard-duration]'),
            function (option) { option.setAttribute('aria-pressed', 'false'); }
        );

        Array.prototype.forEach.call(root.querySelectorAll('[data-wizard-target]'), function (field) {
            field.hidden = true;
        });

        if (suggests) { suggests.innerHTML = ''; }
        if (unitList) { unitList.innerHTML = ''; }
        if (datesNote) { datesNote.hidden = true; }
        if (swapNote) { swapNote.hidden = true; }

        choosePriority('secondary');
        fillDurationMeta();

        step = 1;
        paint(1);
    }

    function open(trigger) {
        lastFocus = trigger || document.activeElement;
        reset();

        root.hidden = false;
        window.requestAnimationFrame(function () { root.classList.add('is-open'); });

        var first = root.querySelector('[data-wizard-category]');
        if (first) { first.focus({ preventScroll: true }); }
    }

    function close() {
        root.classList.remove('is-open');
        window.setTimeout(function () { root.hidden = true; }, 200);

        if (lastFocus && lastFocus.focus) { lastFocus.focus({ preventScroll: true }); }

        /* The board is rendered server-side, so the new goal appears by asking
           the server again. Only after a save — closing a wizard the user
           abandoned should cost them nothing. */
        if (saved) {
            window.location.reload();
        }
    }

    /* ------------------------------------------------------------- events */

    root.addEventListener('click', function (event) {
        if (event.target.closest('[data-wizard-close]') || event.target.closest('[data-wizard-done]')) {
            close();
            return;
        }

        var category = event.target.closest('[data-wizard-category]');
        if (category) { chooseCategory(category.getAttribute('data-wizard-category')); return; }

        var type = event.target.closest('[data-wizard-type]');
        if (type) { chooseType(type.getAttribute('data-wizard-type')); return; }

        var duration = event.target.closest('[data-wizard-duration]');
        if (duration) { chooseDuration(duration.getAttribute('data-wizard-duration')); return; }

        var priority = event.target.closest('[data-wizard-priority]');
        if (priority) { choosePriority(priority.getAttribute('data-wizard-priority')); return; }

        if (event.target.closest('[data-wizard-back]')) { go(Math.max(1, step - 1), -1); return; }

        if (event.target.closest('[data-wizard-next]')) {
            if (!isComplete(step)) { return; }

            if (step === TOTAL) { finish(); return; }

            go(step + 1, 1);
            if (step === 5) { fillSummary(); }
        }
    });

    root.addEventListener('input', function (event) {
        var field = event.target;

        if (field.hasAttribute('data-wizard-name')) { draft.name = field.value; }
        else if (field.hasAttribute('data-wizard-value')) { draft.value = field.value.trim(); }
        else if (field.hasAttribute('data-wizard-unit')) { draft.unit = field.value.trim(); }
        else if (field.hasAttribute('data-wizard-days')) { draft.days = field.value.trim(); }
        else if (field.hasAttribute('data-wizard-streak')) { draft.streak = field.value.trim(); }
        else { return; }

        nextBtn.disabled = !isComplete(step);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !root.hidden) { close(); }
    });

    /* --------------------------------------------------------------- init */

    fillDurationMeta();

    window.GoalWizard = { open: open, close: close };
}());
