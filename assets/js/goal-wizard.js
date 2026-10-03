/**
 * goal-wizard.js — the six-step create-a-goal flow.
 *
 * One question per step, and the next step is unreachable until the current
 * one has an answer. That is the whole of the validation: no error messages,
 * no red fields, just a button that stays quiet until there is something to
 * move on to.
 *
 * The three types are three different questions, not three labels:
 *
 *   Mijlpaal   one result to reach, and which way is better   -> best result
 *   Streak     days in a row, and what makes a day count      -> consecutive days
 *   Optellen   a total or a number of days                    -> everything added
 *
 * So the source is asked before the target (step 3, then 4): the target is
 * typed in the source's own unit, and whether a day needs a threshold depends
 * on where the day's figure comes from.
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
    var TOTAL     = 6;
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
    var createdId = null;
    var draft = blank();

    function blank() {
        return {
            category: null,
            name: '',
            type: null,          // 'milestone' | 'streak' | 'accumulate'

            /* Where progress comes from. null means nobody has said yet, which
               is different from 'manual' — that is an answer. */
            sourceKind: null,
            sourceKey: '',
            sourceLabel: '',
            sourceUnit: '',

            measure: null,       // Optellen: 'amount' | 'days'
            value: '',           // the result or the total
            unit: '',            // typed, for a goal kept by hand
            days: '',            // days in a row, or days in total
            better: null,        // Mijlpaal: 'increase' | 'decrease' — asked, never assumed
            floor: 'increase',   // a day's threshold: at least ('increase') or at most
            daily: '',           // that threshold

            duration: null,
            priority: 'secondary'
        };
    }

    /* ------------------------------------------------------------ helpers */

    function each(selector, fn) {
        Array.prototype.forEach.call(root.querySelectorAll(selector), fn);
    }

    function press(selector, attribute, value) {
        each(selector, function (option) {
            var on = option.getAttribute(attribute) === value;
            option.setAttribute('aria-pressed', on ? 'true' : 'false');
            option.classList.toggle('is-active', on);
        });
    }

    function dutchDate(date) {
        return date.getDate() + ' ' + MONTHS[date.getMonth()];
    }

    function spanText(days) {
        if (days >= 330) { return Math.round(days / 365) === 1 ? '1 jaar' : Math.round(days / 365) + ' jaar'; }
        if (days >= 60) { return Math.round(days / 30) + ' maanden'; }
        if (days >= 45) { return Math.round(days / 7) + ' weken'; }
        return days === 1 ? '1 dag' : days + ' dagen';
    }

    function daysText(n) {
        return Number(n) === 1 ? '1 dag' : n + ' dagen';
    }

    function endDate(days) {
        var date = new Date();
        date.setHours(0, 0, 0, 0);
        date.setDate(date.getDate() + days);
        return date;
    }

    /** "10.000": the way the rest of the app writes a number. */
    function dutchNumber(text) {
        var n = Number(String(text).replace(',', '.'));
        if (!isFinite(n)) { return String(text); }
        return n.toLocaleString('nl-NL', { maximumFractionDigits: 2 });
    }

    function positive(text) {
        var n = Number(String(text).replace(',', '.'));
        return String(text).trim() !== '' && isFinite(n) && n > 0;
    }

    function wholeDays(text) {
        var n = Number(text);
        return String(text).trim() !== '' && isFinite(n) && n >= 1 && Math.floor(n) === n && n <= 365;
    }

    function isAuto() {
        return draft.sourceKind !== null && draft.sourceKind !== 'manual';
    }

    /** Whether this goal counts days rather than an amount. */
    function countsDays() {
        return draft.type === 'streak' || (draft.type === 'accumulate' && draft.measure === 'days');
    }

    /** A day read from health data needs a threshold before it can count. */
    function needsDaily() {
        return countsDays() && isAuto();
    }

    /** The unit the target is typed in: the source's, or the person's own. */
    function amountUnit() {
        return isAuto() ? draft.sourceUnit : draft.unit;
    }

    function withUnit(number, unit) {
        return unit ? dutchNumber(number) + (unit === '%' ? '' : ' ') + unit : dutchNumber(number);
    }

    /** The target as one readable phrase, whatever kind of goal this is. */
    function targetText() {
        if (draft.type === 'milestone') {
            if (!positive(draft.value)) { return null; }
            return withUnit(draft.value, amountUnit())
                + (draft.better === 'decrease' ? ' · ' + words.better_down.toLowerCase()
                    : draft.better === 'increase' ? ' · ' + words.better_up.toLowerCase() : '');
        }

        if (countsDays()) {
            if (!wholeDays(draft.days)) { return null; }
            var text = daysText(draft.days) + (draft.type === 'streak' ? ' op rij' : '');

            if (needsDaily() && positive(draft.daily)) {
                text += ' · ' + (draft.floor === 'decrease' ? 'hoogstens ' : 'minstens ')
                    + withUnit(draft.daily, draft.sourceUnit) + ' per dag';
            }
            return text;
        }

        if (draft.type === 'accumulate' && draft.measure === 'amount') {
            return positive(draft.value) ? withUnit(draft.value, amountUnit()) : null;
        }

        return null;
    }

    /* ------------------------------------------------------- step control */

    function isComplete(which) {
        if (which === 1) { return draft.category !== null; }
        if (which === 2) { return draft.name.trim().length > 1 && draft.type !== null; }

        /* A source has to be chosen, and "Geen data mogelijk" counts as
           choosing one. What is not allowed is arriving at a finished goal
           without anybody having said where its progress comes from. */
        if (which === 3) { return draft.sourceKind !== null; }

        if (which === 4) {
            if (draft.type === 'milestone') {
                return positive(draft.value) && draft.better !== null;
            }

            if (draft.type === 'accumulate' && draft.measure === null) { return false; }

            if (countsDays()) {
                return wholeDays(draft.days) && (!needsDaily() || positive(draft.daily));
            }

            return positive(draft.value);
        }

        if (which === 5) { return draft.duration !== null && fits(draft.duration); }
        return true;
    }

    function refresh() {
        nextBtn.disabled = !isComplete(step);
    }

    function paint(direction) {
        body.setAttribute('data-dir', direction === -1 ? 'back' : 'forward');

        each('[data-wizard-step]', function (section) {
            var isActive = section.getAttribute('data-wizard-step') === String(step);
            section.hidden = !isActive;
            section.classList.toggle('is-active', isActive);
        });

        each('[data-wizard-bar]', function (bar) {
            bar.classList.toggle('is-done', Number(bar.getAttribute('data-wizard-bar')) <= step);
        });

        if (count) {
            count.textContent = 'Stap ' + step + ' van ' + TOTAL + ' · ' + words.steps[step].label;
        }

        if (step === 4) { showTarget(); }
        if (step === 5) { fillDurationMeta(); }

        backBtn.hidden = step === 1;
        nextBtn.hidden = false;
        doneBtn.hidden = true;
        nextBtn.textContent = step === TOTAL ? words.create : words.next;
        refresh();
    }

    function go(to, direction) {
        step = to;
        paint(direction);
        if (body) { body.scrollTop = 0; }
    }

    /* ------------------------------------------------------ 1 · category */

    function chooseCategory(key) {
        draft.category = key;
        press('[data-wizard-category]', 'data-wizard-category', key);
        fillSuggestions(key);
        fillUnits(key);
        refresh();
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
                refresh();
                nameInput.focus({ preventScroll: true });
            });
            item.appendChild(button);
            suggests.appendChild(item);
        });
    }

    /**
     * Unit suggestions for a goal kept by hand. "dagen" is left out: a count
     * of days is its own choice (Streak, or Optellen in days), not a unit.
     */
    function fillUnits(key) {
        if (!unitList) { return; }

        unitList.innerHTML = '';
        var category = copy.categories[key];
        var units = (category ? category.units : []).filter(function (unit) { return unit !== 'dagen'; });

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
                refresh();
            });
            item.appendChild(button);
            unitList.appendChild(item);
        });
    }

    /* ---------------------------------------------------------- 2 · type */

    function chooseType(key) {
        draft.type = key;
        press('[data-wizard-type]', 'data-wizard-type', key);
        filterSources();
        refresh();
    }

    /* ----------------------------------------------------- 3 · bijhouden */

    /**
     * Shows only the sources this type can be measured from. A weight can be
     * a Mijlpaal but not a Streak; only what builds up over a day can be
     * added up. A choice that no longer fits — the type was changed after
     * stepping back — is cleared rather than kept and quietly misread.
     */
    function filterSources() {
        var shown = {};

        each('button[data-wizard-source]', function (button) {
            var types = (button.getAttribute('data-source-types') || '').split(' ');
            var fits  = draft.type === null || types.indexOf(draft.type) !== -1;

            button.hidden = !fits;
            if (fits) { shown[button.getAttribute('data-source-group') || ''] = true; }

            if (!fits && button.getAttribute('aria-pressed') === 'true') {
                button.setAttribute('aria-pressed', 'false');
                button.classList.remove('is-chosen');
                draft.sourceKind = null;
                draft.sourceKey = '';
                draft.sourceLabel = '';
                draft.sourceUnit = '';
            }
        });

        each('p[data-source-group]', function (label) {
            label.hidden = !shown[label.getAttribute('data-source-group')];
        });
    }

    function chooseSource(button) {
        draft.sourceKind = button.getAttribute('data-source-kind');
        draft.sourceKey  = button.getAttribute('data-source-key') || '';
        draft.sourceUnit = button.getAttribute('data-source-unit') || '';

        var label = button.querySelector('.wizard-type__label');
        draft.sourceLabel = label ? label.textContent.trim() : '';

        each('[data-wizard-source]', function (other) {
            other.setAttribute('aria-pressed', other === button ? 'true' : 'false');
            other.classList.toggle('is-chosen', other === button);
        });

        refresh();
    }

    /* ------------------------------------------------------- 4 · streven */

    function block(name, show) {
        var node = root.querySelector('[data-wizard-block="' + name + '"]');
        if (node) { node.hidden = !show; }
    }

    function text(selector, value) {
        var node = root.querySelector(selector);
        if (node) { node.textContent = value; }
    }

    /**
     * Step four asks whatever the chosen type and source actually imply, in
     * the source's own unit. Nothing typed is thrown away when a block hides;
     * it is simply not sent unless it applies.
     */
    function showTarget() {
        var type = draft.type;
        var auto = isAuto();
        var days = countsDays();

        text('[data-wizard-target-lede]', (words.target_lede && words.target_lede[type]) || '');

        block('measure', type === 'accumulate');
        block('amount', type === 'milestone' || (type === 'accumulate' && draft.measure === 'amount'));
        block('days', days);
        block('direction', type === 'milestone');
        block('daily', days && auto);
        block('tick', days && !auto);

        text('[data-wizard-amount-label]', type === 'milestone' ? words.target_best : words.target_total);
        text('[data-wizard-days-label]', type === 'streak' ? words.target_streak : words.target_days);
        text('[data-wizard-days-suffix]', type === 'streak' ? 'dagen op rij' : 'dagen');
        text('[data-wizard-tick-note]', type === 'streak' ? words.tick_streak : words.tick_days);

        /* Read from health data, the unit is the source's and is shown, not
           typed: 8 next to "uur" is hours of sleep and nothing else. */
        var unitInput = root.querySelector('[data-wizard-unit]');
        var unitFixed = root.querySelector('[data-wizard-unit-fixed]');
        if (unitInput) { unitInput.hidden = auto; }
        if (unitFixed) {
            unitFixed.hidden = !auto || draft.sourceUnit === '';
            unitFixed.textContent = draft.sourceUnit;
        }
        if (unitList) { unitList.hidden = auto; }

        text('[data-wizard-daily-unit]', draft.sourceUnit);
    }

    function chooseMeasure(key) {
        draft.measure = key;
        press('[data-wizard-measure]', 'data-wizard-measure', key);
        showTarget();
        refresh();
    }

    function chooseBetter(key) {
        draft.better = key;
        press('[data-wizard-better]', 'data-wizard-better', key);
        refresh();
    }

    function chooseFloor(key) {
        draft.floor = key;
        press('[data-wizard-floor]', 'data-wizard-floor', key);
        refresh();
    }

    /* ------------------------------------------------------ 5 · duration */

    /**
     * Whether a period can hold the days asked for. Thirty days in a row do
     * not fit in a week, and a period that cannot be finished is not offered.
     * A period runs from today to its end date, both days included.
     */
    function fits(key) {
        if (!countsDays() || !wholeDays(draft.days)) { return true; }
        var period = copy.durations[key] ? copy.durations[key].days + 1 : 0;
        return Number(draft.days) <= period;
    }

    function chooseDuration(key) {
        if (!fits(key)) { return; }

        draft.duration = key;
        press('[data-wizard-duration]', 'data-wizard-duration', key);

        var days = copy.durations[key] ? copy.durations[key].days : 0;
        var start = new Date();

        if (datesNote) {
            datesNote.hidden = false;
            datesNote.textContent = 'Van ' + dutchDate(start) + ' t/m ' + dutchDate(endDate(days))
                + ' · nog ' + spanText(days);
        }

        refresh();
    }

    /** Each duration tile carries the date it would run to, or why it cannot. */
    function fillDurationMeta() {
        each('[data-wizard-duration]', function (option) {
            var key  = option.getAttribute('data-wizard-duration');
            var meta = option.querySelector('[data-duration-meta]');
            var ok   = fits(key);

            option.disabled = !ok;

            if (meta) {
                meta.textContent = ok
                    ? 't/m ' + dutchDate(endDate(Number(option.getAttribute('data-days'))))
                    : (words.too_short || 'Te kort voor %s').replace('%s', daysText(draft.days) + (draft.type === 'streak' ? ' op rij' : ''));
            }

            if (!ok && draft.duration === key) {
                draft.duration = null;
                option.setAttribute('aria-pressed', 'false');
                option.classList.remove('is-active');
                if (datesNote) { datesNote.hidden = true; }
            }
        });
    }

    /* ------------------------------------------------------- 6 · summary */

    function fillSummary() {
        var category = draft.category ? copy.categories[draft.category] : null;
        var duration = draft.duration ? copy.durations[draft.duration] : null;

        set('name', draft.name.trim() || '—');
        set('category', category
            ? category.label + ' · ' + (copy.types[draft.type] ? copy.types[draft.type].label : '')
            : '—');
        set('target', targetText() || '—');
        set('source', draft.sourceKind === 'manual'
            ? (words.source_manual || 'Geen data mogelijk')
            : (draft.sourceLabel || '—'));
        set('duration', duration
            ? duration.label + ' · t/m ' + dutchDate(endDate(duration.days))
            : '—');

        function set(key, value) {
            var cell = root.querySelector('[data-summary="' + key + '"]');
            if (cell) { cell.textContent = value; }
        }
    }

    function choosePriority(key) {
        draft.priority = key;
        press('[data-wizard-priority]', 'data-wizard-priority', key);

        /* Only worth saying when there is in fact a primary goal to displace. */
        var current = document.querySelector('[data-goal-slot="primary"] [data-goal-card]');
        if (swapNote) { swapNote.hidden = !(key === 'primary' && current); }
    }

    /* --------------------------------------------------------- 6 · close */

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

        /* No percentage until something is recorded — the board shows the
           same dash, and the preview must not promise a 0 it does not have. */
        var percent = document.createElement('span');
        percent.className = 'goal-card__percent';
        var value = document.createElement('span');
        value.className = 'goal-card__percent-value';
        value.textContent = '—';
        percent.appendChild(value);

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
        /* The account panel's, or — on the setup, which has no account
           panel — the page's own (pages/setup.php). */
        var panel = document.querySelector('[data-account]') || document.querySelector('[data-csrf]');
        return panel ? (panel.getAttribute('data-csrf') || '') : '';
    }

    /**
     * What the wizard collected, in the fields api/goals/create.php expects.
     * Only what applies to the chosen type is sent; the server checks every
     * one of them again and refuses a goal it could not measure.
     */
    function payload() {
        var form = new FormData();

        form.append('csrf', csrf());
        form.append('name', draft.name);
        form.append('category', draft.category || 'other');
        form.append('type', draft.type || '');
        form.append('duration', draft.duration || '');
        form.append('priority', draft.priority || 'secondary');

        /* The chosen source, verbatim. The server checks it against the same
           catalogue this list was built from and refuses anything else. */
        form.append('source_kind', draft.sourceKind || '');
        form.append('source_key', draft.sourceKey || '');

        if (draft.type === 'accumulate') {
            form.append('measure', draft.measure || 'amount');
        }

        if (countsDays()) {
            form.append('target_value', draft.days);

            if (needsDaily()) {
                form.append('daily_target', draft.daily);
                form.append('direction', draft.floor);
            }
        } else {
            form.append('target_value', draft.value);
            if (!isAuto()) { form.append('target_unit', draft.unit); }
            if (draft.type === 'milestone') { form.append('direction', draft.better || ''); }
        }

        return form;
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
     * must not appear until the row exists. A failure keeps the user on the
     * last step with their answers intact rather than claiming a goal was made.
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
                createdId = result.goal_id ? String(result.goal_id) : null;
                showDone();
            });
    }

    function showDone() {
        buildPreview();

        each('[data-wizard-step]', function (section) {
            var isDone = section.getAttribute('data-wizard-step') === 'done';
            section.hidden = !isDone;
            section.classList.toggle('is-active', isDone);
        });

        each('[data-wizard-bar]', function (bar) { bar.classList.add('is-done'); });

        if (count) { count.textContent = words.steps[TOTAL].label; }

        backBtn.hidden = true;
        nextBtn.hidden = true;
        doneBtn.hidden = false;
        doneBtn.focus({ preventScroll: true });
    }

    /* ---------------------------------------------------------- open/close */

    /** Everything back to unanswered, including what the buttons show. */
    function reset() {
        draft = blank();
        showError('');

        each('input', function (field) { field.value = ''; field.hidden = false; });

        each('[data-wizard-category], [data-wizard-type], [data-wizard-source], [data-wizard-duration], '
            + '[data-wizard-measure], [data-wizard-better]', function (option) {
            option.setAttribute('aria-pressed', 'false');
            option.classList.remove('is-active', 'is-chosen');
            option.hidden = false;
            option.disabled = false;
        });

        each('p[data-source-group]', function (label) { label.hidden = false; });
        each('[data-wizard-block]', function (node) { node.hidden = true; });

        chooseFloor('increase');

        if (suggests) { suggests.innerHTML = ''; }
        if (unitList) { unitList.innerHTML = ''; unitList.hidden = false; }
        if (datesNote) { datesNote.hidden = true; }
        if (swapNote) { swapNote.hidden = true; }

        choosePriority('secondary');
        fillDurationMeta();

        step = 1;
        paint(1);
    }

    function open(trigger) {
        lastFocus = trigger || document.activeElement;
        saved = false;
        createdId = null;
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
           the server again — in the background, with the goal parts swapped
           in (goals.js), not with a reload: the shell starts on Overzicht, so
           a reload used to land there instead of on Doelen. Only after a
           save; closing a wizard the user abandoned should cost them nothing. */
        if (saved) {
            saved = false;

            /* For whoever opened it outside Doelen — the setup's goal step —
               which goal was made. */
            document.dispatchEvent(new CustomEvent('goalwizard:saved', {
                detail: { id: createdId, name: draft.name }
            }));

            showNewGoal(createdId);
        }
    }

    /** Puts the board on Actief and brings the new goal's card into view. */
    function showNewGoal(id) {
        if (!window.GoalBoard) { return; }

        window.GoalBoard.refresh().then(function (shown) {
            if (!shown) { return; }

            window.GoalBoard.show('active');

            var card = id ? document.querySelector('[data-goals] [data-goal-card="' + id + '"]') : null;
            if (card && card.scrollIntoView) {
                card.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            }
        });
    }

    /* ------------------------------------------------------------- events */

    root.addEventListener('click', function (event) {
        if (event.target.closest('[data-wizard-close]') || event.target.closest('[data-wizard-done]')) {
            close();
            return;
        }

        var hit;

        if ((hit = event.target.closest('[data-wizard-category]'))) { chooseCategory(hit.getAttribute('data-wizard-category')); return; }
        if ((hit = event.target.closest('[data-wizard-type]'))) { chooseType(hit.getAttribute('data-wizard-type')); return; }
        if ((hit = event.target.closest('[data-wizard-source]'))) { chooseSource(hit); return; }
        if ((hit = event.target.closest('[data-wizard-measure]'))) { chooseMeasure(hit.getAttribute('data-wizard-measure')); return; }
        if ((hit = event.target.closest('[data-wizard-better]'))) { chooseBetter(hit.getAttribute('data-wizard-better')); return; }
        if ((hit = event.target.closest('[data-wizard-floor]'))) { chooseFloor(hit.getAttribute('data-wizard-floor')); return; }
        if ((hit = event.target.closest('[data-wizard-duration]'))) { chooseDuration(hit.getAttribute('data-wizard-duration')); return; }
        if ((hit = event.target.closest('[data-wizard-priority]'))) { choosePriority(hit.getAttribute('data-wizard-priority')); return; }

        if (event.target.closest('[data-wizard-back]')) { go(Math.max(1, step - 1), -1); return; }

        if (event.target.closest('[data-wizard-next]')) {
            if (!isComplete(step)) { return; }

            if (step === TOTAL) { finish(); return; }

            go(step + 1, 1);
            if (step === TOTAL) { fillSummary(); }
        }
    });

    root.addEventListener('input', function (event) {
        var field = event.target;

        if (field.hasAttribute('data-wizard-name')) { draft.name = field.value; }
        else if (field.hasAttribute('data-wizard-value')) { draft.value = field.value.trim(); }
        else if (field.hasAttribute('data-wizard-unit')) { draft.unit = field.value.trim(); }
        else if (field.hasAttribute('data-wizard-days')) { draft.days = field.value.trim(); }
        else if (field.hasAttribute('data-wizard-daily-input')) { draft.daily = field.value.trim(); }
        else { return; }

        refresh();
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !root.hidden) { close(); }
    });

    /* --------------------------------------------------------------- init */

    fillDurationMeta();

    window.GoalWizard = { open: open, close: close };
}());
