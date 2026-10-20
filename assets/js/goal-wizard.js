/**
 * goal-wizard.js — Nieuw doel: three steps and a review (docs/GOALS.md).
 *
 *   1 Meting     search + six category bubbles + a short list; choosing a
 *                measurement highlights it, nothing more. Eigen gewoonte asks
 *                for its category here too.
 *   2 Doel       a name (ideas from the measurement) and Mijlpaal, Streak or
 *                Optellen — each card with an example for this measurement
 *   3 Instellen  number + unit, which way is better (two round buttons, the
 *                explanation on a long press), the baseline for a Mijlpaal,
 *                and the period: start, end, or one of five looptijden
 *   Controleren  everything, read-only, and "Doel aanmaken"
 *
 * Nothing here decides what is stored: api/goals/create.php checks every
 * field again with includes/goal-create.php. What this file does work out —
 * an end date from a looptijd, a figure in another unit — is the same
 * arithmetic as includes/goal-catalogue.php and GoalWizard.kt, so what the
 * review says is what the goal will be.
 */

(function () {
    'use strict';

    var T = window.OwnifyI18n;     // language and units (assets/js/i18n.js)

    var root = document.querySelector('[data-goal-wizard]');
    if (!root) { return; }

    var copy = (window.GoalCopy && window.GoalCopy.measurements) ? window.GoalCopy : null;
    if (!copy) {
        var source = document.querySelector('[data-goals-copy]');
        try { copy = JSON.parse(source.textContent); } catch (e) { return; }
    }

    var words   = copy.wizard;
    var STEPS   = 3;

    var body      = root.querySelector('[data-wizard-body]');
    var count     = root.querySelector('[data-wizard-count]');
    var backBtn   = root.querySelector('[data-wizard-back]');
    var nextBtn   = root.querySelector('[data-wizard-next]');
    var nextLabel = root.querySelector('[data-wizard-next-label]');
    var foot      = root.querySelector('[data-wizard-foot]');
    var errorSlot = root.querySelector('[data-wizard-error]');

    var search    = root.querySelector('[data-wizard-search]');
    var clearBtn  = root.querySelector('[data-wizard-search-clear]');
    var list      = root.querySelector('[data-wizard-list]');
    var listLabel = root.querySelector('[data-wizard-list-label]');
    var empty     = root.querySelector('[data-wizard-empty]');
    var customHint= root.querySelector('[data-wizard-custom-hint]');
    var nameInput = root.querySelector('[data-wizard-name]');
    var ideas     = root.querySelector('[data-wizard-ideas]');
    var setup     = root.querySelector('[data-wizard-setup]');
    var review    = root.querySelector('[data-wizard-review]');
    var calendar  = root.querySelector('[data-wizard-calendar]');

    var step = 1;               // 1, 2, 3 or 'review'
    var lastFocus = null;
    var saving = false;
    var draft = blank();

    function blank() {
        return {
            token: token(),
            measurement: null,      // a code, or 'custom'
            category: null,         // the bubble chosen — and an Eigen gewoonte's category
            query: '',
            name: '',
            type: null,             // milestone | streak | accumulate
            variant: null,          // a streak's: daily | period
            unit: null,             // a unit code, or 'custom'
            customUnit: '',
            target: '',             // as typed
            direction: null,        // increase | decrease
            frequency: '',
            period: 'week',
            baseline: { state: 'idle', value: null, on: null, measurement: null, start: null },
            baselineTyped: '',
            start: today(),
            end: null,
            duration: null,
            calendar: null          // 'start' | 'end' while the calendar is open
        };
    }

    /** A one-time key for this goal: the server makes it once, however often it is sent. */
    function token() {
        if (window.crypto && window.crypto.randomUUID) { return window.crypto.randomUUID(); }
        return 'w' + Date.now().toString(36) + Math.random().toString(36).slice(2, 12);
    }

    /* ============================================================ helpers */

    /* Copy from the catalogue is in the reader's language already: only its %s is filled. */
    function fill(text, value) {
        return String(text).replace('%s', value);
    }

    function each(selector, fn, scope) {
        Array.prototype.forEach.call((scope || root).querySelectorAll(selector), fn);
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) { node.className = className; }
        if (text !== undefined && text !== null) { node.textContent = text; }
        return node;
    }

    function iconMarkup(name) {
        var template = root.querySelector('template[data-wizard-icon="' + name + '"]');
        return template ? template.innerHTML : '';
    }

    function has(list, value) { return (list || []).indexOf(value) !== -1; }

    /** Lower case, no accents: "Slaapefficiëntie" is found by "efficientie". */
    function fold(text) {
        return String(text || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    }

    /* ---------------------------------------------------------- catalogue */

    function measurementOf(code) {
        if (code === 'custom') {
            var custom = Object.assign({ code: 'custom', custom: true, source_kind: 'manual' }, copy.custom);
            custom.category = draft.category;
            return custom;
        }
        var m = copy.measurements[code];
        return m ? Object.assign({ code: code, custom: false }, m) : null;
    }

    function current() { return draft.measurement ? measurementOf(draft.measurement) : null; }

    /** Read from this person's data (it has some) — or kept by hand on the goal's page. */
    function isAuto(m) {
        return !!m && !m.custom && m.source_kind !== 'manual' && has(copy.user_auto, m.code);
    }

    function unitOf(code) {
        if (code === 'custom') {
            return { code: null, label: draft.customUnit.trim(), one: draft.customUnit.trim(), factor: 1, decimals: 2, attached: false };
        }
        var u = copy.goal_units[code];
        if (!u) { return { code: code, label: '', one: '', factor: 1, decimals: 2, attached: false }; }
        return { code: code, label: u.label, one: u.one || u.label, factor: Number(u.factor), decimals: Number(u.decimals), attached: !!u.attached };
    }

    /** The unit a measurement starts in: Imperiaal's own where it has one. */
    function defaultUnit(m) {
        var codes = m.unit_codes || [];
        if (copy.units_system === 'imperial' && m.imperial_unit && has(codes, m.imperial_unit)) { return m.imperial_unit; }
        return codes[0] || m.unit_code;
    }

    /** A per-period streak counts times — trainingen, or keer. */
    function countUnit(m) { return m.unit_code === 'sessions' ? 'sessions' : 'times'; }

    function typeAllowed(m, type) { return !!m && has(m.types, type); }

    /* ------------------------------------------------------------ numbers */

    /* As goal_parse_number(): "10.000" in Dutch (or "10,000" in English) is
       ten thousand, a whole number grouped the reader's way; else as typed. */
    function parsed(text) {
        var clean = String(text).replace(/[\s\u00A0]/g, '');
        if (clean === '') { return null; }
        var grouped = T.locale === 'en' ? /^-?[1-9][0-9]{0,2}(,[0-9]{3})+$/ : /^-?[1-9][0-9]{0,2}(\.[0-9]{3})+$/;
        if (grouped.test(clean)) { return Number(clean.replace(T.locale === 'en' ? /,/g : /\./g, '')); }
        return T.parseNumber(clean);
    }

    function positive(text) {
        var n = parsed(text);
        return n !== null && isFinite(n) && n > 0 && n <= 1e9;
    }

    function round(n, decimals) {
        var f = Math.pow(10, decimals);
        return Math.round(n * f) / f;
    }

    /** "10.000 stappen", "7,5 uur", "85%": a stored figure in a unit. */
    function valueText(stored, unit) {
        var shown = stored / unit.factor;
        var text = T.numberTrim(shown, unit.decimals);
        if (!unit.label) { return text; }
        var word = Math.abs(round(shown, unit.decimals) - 1) < 1e-9 ? unit.one : unit.label;
        return unit.attached ? text + word : text + ' ' + word;
    }

    function typedText(typed, unit) {
        var n = parsed(typed);
        return n === null ? '—' : valueText(n * unit.factor, unit);
    }

    /* -------------------------------------------------------------- dates */

    function today() {
        var d = new Date();
        d.setHours(0, 0, 0, 0);
        return d;
    }

    function dayAfter(d, n) {
        var x = new Date(d.getFullYear(), d.getMonth(), d.getDate() + n);
        x.setHours(0, 0, 0, 0);
        return x;
    }

    /** Calendar months on, the day clamped to the month's last (goal_add_months()). */
    function addMonths(d, months) {
        var index = d.getFullYear() * 12 + d.getMonth() + months;
        var year = Math.floor(index / 12);
        var month = index - year * 12;
        var last = new Date(year, month + 1, 0).getDate();
        return new Date(year, month, Math.min(d.getDate(), last));
    }

    function spanAdd(d, span, times) {
        if (span.months) { return addMonths(d, span.months * times); }
        return dayAfter(d, (span.days || 0) * times);
    }

    /** A looptijd's last day: the day before the same date a week, month… on (goal_duration_end()). */
    function durationEnd(start, key) {
        var span = copy.durations[key];
        return span ? dayAfter(spanAdd(start, span, 1), -1) : null;
    }

    function sameDay(a, b) {
        return !!a && !!b && a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
    }

    function iso(d) {
        var m = d.getMonth() + 1, day = d.getDate();
        return d.getFullYear() + '-' + (m < 10 ? '0' : '') + m + '-' + (day < 10 ? '0' : '') + day;
    }

    function daysBetween(a, b) {
        return Math.round((Date.UTC(b.getFullYear(), b.getMonth(), b.getDate()) - Date.UTC(a.getFullYear(), a.getMonth(), a.getDate())) / 86400000) + 1;
    }

    function dateText(d) {
        return d.getFullYear() === today().getFullYear() ? T.date(d, 'day_mon') : T.date(d, 'day_mon_year');
    }

    /** What a per-period streak asks in all: a short last period its share, a half up (goal_periods()). */
    function periodAsks(start, end, unit, frequency) {
        var span = copy.periods[unit];
        var total = 0;
        if (!span || frequency < 1 || !end) { return 0; }
        for (var k = 0; k < 1000; k++) {
            var from = spanAdd(start, span, k);
            if (from > end) { break; }
            var fullEnd = dayAfter(spanAdd(start, span, k + 1), -1);
            var to = fullEnd > end ? end : fullEnd;
            var full = daysBetween(from, fullEnd);
            var days = daysBetween(from, to);
            total += days === full ? frequency : Math.floor(frequency * days / full + 0.5 + 1e-9);
        }
        return total;
    }

    /* ============================================================ step 1 */

    function chooseCategory(key) {
        draft.category = draft.category === key ? null : key;
        each('[data-wizard-category]', function (bubble) {
            bubble.setAttribute('aria-pressed', bubble.getAttribute('data-wizard-category') === draft.category ? 'true' : 'false');
        });
        renderList();
        refresh();
    }

    function matches(m, query) {
        if (!query) { return true; }
        var haystack = fold([m.label, (copy.categories[m.category] || {}).label].concat(m.keywords || []).join(' '));
        return fold(query).split(/\s+/).every(function (word) { return haystack.indexOf(word) !== -1; });
    }

    function renderList() {
        var query = draft.query.trim();
        var codes = Object.keys(copy.measurements);
        var shown;

        if (!query && !draft.category) {
            shown = codes.filter(function (c) { return copy.measurements[c].popular; })
                .sort(function (a, b) { return copy.measurements[a].popular - copy.measurements[b].popular; });
            listLabel.textContent = words.popular;
        } else {
            shown = codes.filter(function (c) {
                var m = copy.measurements[c];
                return (!draft.category || m.category === draft.category) && matches(m, query);
            });
            listLabel.textContent = draft.category && !query ? copy.categories[draft.category].label : words.results;
        }

        list.innerHTML = '';
        shown.forEach(function (code) { list.appendChild(option(code, measurementOf(code))); });

        var customFits = !query || matches(Object.assign({ label: copy.custom.label, keywords: copy.custom.keywords }, {}), query) || shown.length === 0;
        if (customFits || draft.measurement === 'custom') {
            list.appendChild(option('custom', measurementOf('custom')));
        }

        empty.hidden = shown.length > 0 || !query;
        customHint.hidden = !(draft.measurement === 'custom' && !draft.category);

        clearBtn.hidden = draft.query === '';
    }

    function option(code, m) {
        var chosen = draft.measurement === code;
        var category = m.custom ? (draft.category ? copy.categories[draft.category] : null) : copy.categories[m.category];
        var button = el('button', 'goal-option press');
        button.type = 'button';
        button.setAttribute('role', 'radio');
        button.setAttribute('aria-checked', chosen ? 'true' : 'false');
        button.setAttribute('data-wizard-measure', code);
        if (category) { button.setAttribute('data-accent', category.accent); }

        var mark = el('span', 'goal-option__icon');
        mark.setAttribute('aria-hidden', 'true');
        mark.innerHTML = iconMarkup(m.custom ? 'custom' : category.icon);

        var text = el('span', 'goal-option__text');
        text.appendChild(el('span', 'goal-option__label', m.label));
        var meta = m.custom
            ? (m.hint || '')
            : category.label + ' · ' + (isAuto(m) ? words.auto : words.manual);
        text.appendChild(el('span', 'goal-option__meta', meta));

        button.appendChild(mark);
        button.appendChild(text);
        return button;
    }

    function chooseMeasurement(code) {
        if (draft.measurement !== code) {
            draft.measurement = code;
            resetSetup();
            var m = current();
            if (draft.type && !typeAllowed(m, draft.type)) { draft.type = null; }
            if (m.types.length === 1) { draft.type = m.types[0]; }
        }
        each('[data-wizard-measure]', function (row) {
            row.setAttribute('aria-checked', row.getAttribute('data-wizard-measure') === code ? 'true' : 'false');
        }, list);
        customHint.hidden = !(code === 'custom' && !draft.category);
        refresh();
    }

    /** What step three asked for, when the measurement changes: back to its defaults. */
    function resetSetup() {
        draft.variant = null;
        draft.unit = null;
        draft.customUnit = '';
        draft.target = '';
        draft.direction = null;
        draft.frequency = '';
        draft.period = 'week';
        draft.baseline = { state: 'idle', value: null, on: null, measurement: null, start: null };
        draft.baselineTyped = '';
    }

    /* ============================================================ step 2 */

    function renderDefinition() {
        var m = current();
        if (!m) { return; }

        var names = m.names || [];
        nameInput.placeholder = names[0] || '';
        ideas.innerHTML = '';
        names.forEach(function (text) {
            var chip = el('button', 'glass glass--chip goal-idea press', text);
            chip.type = 'button';
            chip.setAttribute('data-wizard-idea', text);
            ideas.appendChild(chip);
        });

        each('[data-wizard-type]', function (card) {
            var type = card.getAttribute('data-wizard-type');
            var allowed = typeAllowed(m, type);
            var example = card.querySelector('[data-wizard-type-example]');
            card.disabled = !allowed;
            card.setAttribute('aria-disabled', allowed ? 'false' : 'true');
            card.setAttribute('aria-checked', draft.type === type ? 'true' : 'false');
            example.textContent = allowed
                ? fill(words.example, (m.examples && m.examples[type]) || copy.types[type].example)
                : fill(words.type_unavailable, m.custom ? m.label : m.label.toLowerCase());
        });
    }

    function chooseType(type) {
        var m = current();
        if (!typeAllowed(m, type)) { return; }
        if (draft.type !== type) {
            draft.type = type;
            draft.variant = null;
            draft.direction = null;
        }
        each('[data-wizard-type]', function (card) {
            card.setAttribute('aria-checked', card.getAttribute('data-wizard-type') === type ? 'true' : 'false');
        });
        refresh();
    }

    /* ============================================================ step 3 */

    /** The defaults step three starts from, once a measurement and type are known. */
    function settle() {
        var m = current();
        if (draft.type === 'streak' && !draft.variant) { draft.variant = m.variants[0]; }
        if (draft.type !== 'streak') { draft.variant = null; }
        if (!draft.unit || (draft.unit !== 'custom' && !has(m.unit_codes, draft.unit))) { draft.unit = defaultUnit(m); }
        if (!draft.direction) { draft.direction = m.direction === 'both' ? m.default_direction : m.direction; }
        if (draft.type === 'accumulate' || draft.variant === 'period') { draft.direction = 'increase'; }
        if (draft.end === null && draft.duration === null) {
            draft.duration = 'month';
            draft.end = durationEnd(draft.start, 'month');
        }
    }

    function needsDirection() {
        var m = current();
        return !!m && m.direction === 'both' && (draft.type === 'milestone' || draft.variant === 'daily');
    }

    function renderSetup() {
        var m = current();
        settle();
        setup.innerHTML = '';

        /* A streak's two forms, where both are open to this measurement. */
        if (draft.type === 'streak' && m.variants.length > 1) {
            setup.appendChild(el('p', 'wizard__label', words.variant_label));
            var variants = el('div', 'goal-bubbles goal-bubbles--tight');
            variants.setAttribute('role', 'radiogroup');
            variants.setAttribute('aria-label', words.variant_label);
            m.variants.forEach(function (key) {
                var b = el('button', 'glass glass--chip goal-bubble press', copy.streak_variants[key].label);
                b.type = 'button';
                b.setAttribute('role', 'radio');
                b.setAttribute('aria-checked', draft.variant === key ? 'true' : 'false');
                b.setAttribute('data-wizard-variant', key);
                variants.appendChild(b);
            });
            setup.appendChild(variants);
            setup.appendChild(el('p', 'wizard__hint goal-setup__hint', copy.streak_variants[draft.variant].hint));
        }

        if (draft.variant === 'period') {
            setup.appendChild(periodRow(m));
        } else {
            var which = draft.type === 'milestone' ? 'milestone' : (draft.type === 'accumulate' ? 'accumulate' : 'daily');
            setup.appendChild(numberRow('goal-target', words.target[which], draft.target, 'target', m, true));
        }

        if (needsDirection()) { setup.appendChild(directionRow()); }

        if (draft.type === 'milestone') {
            var box = el('div', 'goal-baseline');
            box.setAttribute('data-wizard-baseline', '');
            setup.appendChild(box);
            loadBaseline();
            renderBaseline();
        }

        var problem = el('p', 'wizard__error goal-setup__error');
        problem.setAttribute('data-wizard-setup-error', '');
        problem.setAttribute('role', 'alert');
        problem.hidden = true;
        setup.appendChild(problem);

        setup.appendChild(datesBlock());
        validateSetup();
    }

    /** A number on the left, its unit on the right — Doelwaarde, Per dag, Totaal. */
    function numberRow(id, label, value, field, m, withUnit) {
        var wrap = el('div', 'goal-field');
        var l = el('label', 'wizard__label', label);
        l.setAttribute('for', id);
        wrap.appendChild(l);

        var row = el('div', 'goal-row');
        var input = el('input', 'wizard__input goal-row__number');
        input.id = id;
        input.type = 'text';
        input.inputMode = 'decimal';
        input.autocomplete = 'off';
        input.setAttribute('enterkeyhint', 'done');
        input.setAttribute('data-wizard-field', field);
        input.value = value;
        input.placeholder = placeholder(m);
        row.appendChild(input);

        if (withUnit) { row.appendChild(unitSelect(m)); }
        wrap.appendChild(row);

        if (withUnit && draft.unit === 'custom') {
            var custom = el('input', 'wizard__input goal-row__custom');
            custom.type = 'text';
            custom.maxLength = 20;
            custom.autocomplete = 'off';
            custom.placeholder = words.unit_custom_placeholder;
            custom.setAttribute('aria-label', words.unit_custom_label);
            custom.setAttribute('data-wizard-field', 'custom-unit');
            custom.value = draft.customUnit;
            wrap.appendChild(custom);
        }
        return wrap;
    }

    /** The placeholder in the unit on screen: 75 kg is 165 lb. */
    function placeholder(m) {
        var key = draft.variant === 'period' ? 'period' : draft.type;
        var base = m.placeholder && m.placeholder[key];
        if (base === undefined || base === null) { return ''; }
        if (draft.variant === 'period' || draft.unit === 'custom') { return T.numberTrim(base, 2); }
        var first = unitOf((m.unit_codes || [])[0] || draft.unit);
        var unit = unitOf(draft.unit);
        return T.numberTrim(round(base * first.factor / unit.factor, unit.decimals), unit.decimals);
    }

    /** A select in the glass (`.glass--select`), its chevron drawn over it. */
    function glassSelect(label, attribute, options, value) {
        var wrap = el('label', 'glass glass--select goal-row__unit');
        var select = el('select');
        select.setAttribute('aria-label', label);
        select.setAttribute(attribute, '');
        options.forEach(function (pair) {
            var o = el('option', null, pair[1]);
            o.value = pair[0];
            select.appendChild(o);
        });
        select.value = value;
        wrap.appendChild(select);
        wrap.insertAdjacentHTML('beforeend', iconMarkup('chevron-down'));
        return wrap;
    }

    function unitSelect(m) {
        var options = (m.unit_codes || []).map(function (code) { return [code, unitOf(code).label]; });
        if (m.custom) { options.push(['custom', words.unit_custom]); }
        return glassSelect(words.unit_label, 'data-wizard-unit', options, draft.unit);
    }

    /** Per periode: "[3] trainingen per [week ▾]". */
    function periodRow(m) {
        var wrap = el('div', 'goal-field');
        var l = el('label', 'wizard__label', words.target.period);
        l.setAttribute('for', 'goal-frequency');
        wrap.appendChild(l);

        var row = el('div', 'goal-row goal-row--period');
        var input = el('input', 'wizard__input goal-row__number goal-row__number--short');
        input.id = 'goal-frequency';
        input.type = 'text';
        input.inputMode = 'numeric';
        input.autocomplete = 'off';
        input.setAttribute('data-wizard-field', 'frequency');
        input.value = draft.frequency;
        input.placeholder = placeholder(m);
        row.appendChild(input);
        row.appendChild(el('span', 'goal-row__word', m.count || T.t('keer')));
        row.appendChild(el('span', 'goal-row__word goal-row__word--quiet', words.per));

        row.appendChild(glassSelect(words.period_label, 'data-wizard-period',
            Object.keys(copy.periods).map(function (key) { return [key, copy.periods[key].label]; }), draft.period));
        wrap.appendChild(row);
        return wrap;
    }

    /* --------------------------------------------- which way is better */

    function directionWords() {
        return draft.type === 'milestone' ? words.direction : words.direction_daily;
    }

    function directionRow() {
        var wrap = el('div', 'goal-direction');
        var label = el('p', 'wizard__label goal-direction__label', words.direction_label);
        label.id = 'goal-direction-label';
        wrap.appendChild(label);

        var group = el('div', 'goal-direction__buttons');
        group.setAttribute('role', 'radiogroup');
        group.setAttribute('aria-labelledby', 'goal-direction-label');

        var w = directionWords();
        ['increase', 'decrease'].forEach(function (key) {
            var b = el('button', 'glass glass--round goal-direction__button press');
            b.type = 'button';
            b.setAttribute('role', 'radio');
            b.setAttribute('aria-checked', draft.direction === key ? 'true' : 'false');
            b.setAttribute('aria-label', w[key].label);
            b.setAttribute('aria-describedby', 'goal-direction-hint-' + key);
            b.setAttribute('data-wizard-direction', key);
            b.innerHTML = iconMarkup(key === 'increase' ? 'arrow-up' : 'arrow-down');
            group.appendChild(b);

            var hint = el('span', 'sr-only', w[key].hint);
            hint.id = 'goal-direction-hint-' + key;
            wrap.appendChild(hint);
        });

        wrap.appendChild(group);

        var tip = el('span', 'goal-direction__tip');
        tip.setAttribute('aria-hidden', 'true');
        tip.setAttribute('data-wizard-tip', '');
        tip.hidden = true;
        wrap.appendChild(tip);
        return wrap;
    }

    function chooseDirection(key) {
        draft.direction = key;
        each('[data-wizard-direction]', function (b) {
            b.setAttribute('aria-checked', b.getAttribute('data-wizard-direction') === key ? 'true' : 'false');
        }, setup);
        validateSetup();
    }

    /* Press and hold: the explanation, above the buttons, for as long as it is held. */
    var hold = { timer: 0, shown: false, button: null };

    function showTip(button) {
        var tip = setup.querySelector('[data-wizard-tip]');
        if (!tip) { return; }
        tip.textContent = directionWords()[button.getAttribute('data-wizard-direction')].hint;
        tip.hidden = false;
        tip.classList.add('is-shown');
        hold.shown = true;
        if (navigator.vibrate) { try { navigator.vibrate(8); } catch (e) { /* not allowed */ } }
    }

    function hideTip() {
        window.clearTimeout(hold.timer);
        var tip = setup.querySelector('[data-wizard-tip]');
        if (tip) { tip.classList.remove('is-shown'); tip.hidden = true; }
    }

    /* ------------------------------------------------------------ baseline */

    function loadBaseline() {
        var m = current();
        var start = iso(draft.start);

        if (!isAuto(m)) {
            draft.baseline = { state: 'none', value: null, on: null, measurement: m.code, start: start };
            return;
        }

        if (draft.baseline.measurement === m.code && draft.baseline.start === start
            && (draft.baseline.state === 'measured' || draft.baseline.state === 'none' || draft.baseline.state === 'loading')) {
            return;
        }

        draft.baseline = { state: 'loading', value: null, on: null, measurement: m.code, start: start };

        var form = new FormData();
        form.append('csrf', csrf());
        form.append('measurement', m.code);
        form.append('start_date', start);

        fetch('api/goals/baseline.php', { method: 'POST', body: form, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
            .catch(function () { return { ok: false }; })
            .then(function (result) {
                if (draft.baseline.measurement !== m.code || draft.baseline.start !== start) { return; }
                if (result && result.ok && result.baseline) {
                    draft.baseline = { state: 'measured', value: Number(result.baseline.value), on: result.baseline.label, measurement: m.code, start: start };
                } else {
                    draft.baseline = { state: 'none', value: null, on: null, measurement: m.code, start: start };
                }
                renderBaseline();
                validateSetup();
            });
    }

    function renderBaseline() {
        var box = setup.querySelector('[data-wizard-baseline]');
        if (!box) { return; }
        box.innerHTML = '';
        var unit = unitOf(draft.unit);

        if (draft.baseline.state === 'measured') {
            var row = el('div', 'goal-baseline__measured');
            row.appendChild(el('span', 'goal-baseline__label', words.baseline_label));
            var value = el('span', 'goal-baseline__value', valueText(draft.baseline.value, unit));
            row.appendChild(value);
            box.appendChild(row);
            box.appendChild(el('p', 'wizard__hint', fill(words.baseline_measured, draft.baseline.on)));
            return;
        }

        if (draft.baseline.state === 'loading') {
            box.appendChild(el('p', 'wizard__hint goal-baseline__loading', words.baseline_loading));
            return;
        }

        var wrap = el('div', 'goal-field');
        var l = el('label', 'wizard__label', words.baseline_label);
        l.setAttribute('for', 'goal-baseline');
        wrap.appendChild(l);
        var line = el('div', 'goal-row');
        var input = el('input', 'wizard__input goal-row__number');
        input.id = 'goal-baseline';
        input.type = 'text';
        input.inputMode = 'decimal';
        input.autocomplete = 'off';
        input.setAttribute('data-wizard-field', 'baseline');
        input.value = draft.baselineTyped;
        line.appendChild(input);
        line.appendChild(el('span', 'goal-row__word', unit.label));
        wrap.appendChild(line);
        wrap.appendChild(el('p', 'wizard__hint', words.baseline_manual));
        box.appendChild(wrap);
    }

    /* --------------------------------------------------------------- dates */

    function datesBlock() {
        var wrap = el('div', 'goal-field goal-dates-field');
        wrap.appendChild(el('p', 'wizard__label', words.dates_label));

        var row = el('div', 'goal-dates');
        row.appendChild(dateButton('start', words.start, dateText(draft.start)));
        var arrow = el('span', 'goal-dates__to');
        arrow.setAttribute('aria-hidden', 'true');
        arrow.innerHTML = iconMarkup('chevron-right');
        row.appendChild(arrow);
        row.appendChild(dateButton('end', words.end, draft.end ? dateText(draft.end) : words.end_choose));
        wrap.appendChild(row);

        var bubbles = el('div', 'goal-bubbles goal-durations');
        bubbles.setAttribute('role', 'group');
        bubbles.setAttribute('aria-label', words.durations_label);
        Object.keys(copy.durations).forEach(function (key) {
            var b = el('button', 'glass glass--chip goal-bubble press', copy.durations[key].label);
            b.type = 'button';
            b.setAttribute('aria-pressed', draft.duration === key ? 'true' : 'false');
            b.setAttribute('data-wizard-duration', key);
            bubbles.appendChild(b);
        });
        wrap.appendChild(bubbles);
        return wrap;
    }

    function dateButton(which, label, value) {
        var b = el('button', 'glass goal-date press');
        b.type = 'button';
        b.setAttribute('data-wizard-date', which);
        b.setAttribute('aria-label', label + ': ' + value);
        b.appendChild(el('span', 'goal-date__label', label));
        b.appendChild(el('span', 'goal-date__value' + (which === 'end' && !draft.end ? ' is-empty' : ''), value));
        return b;
    }

    function chooseDuration(key) {
        draft.duration = key;
        draft.end = durationEnd(draft.start, key);
        refreshDates();
    }

    /** After the start or the end moved: the buttons, the bubbles, the baseline. */
    function refreshDates() {
        var start = setup.querySelector('[data-wizard-date="start"]');
        var end = setup.querySelector('[data-wizard-date="end"]');
        if (start) {
            start.querySelector('.goal-date__value').textContent = dateText(draft.start);
            start.setAttribute('aria-label', words.start + ': ' + dateText(draft.start));
        }
        if (end) {
            var v = end.querySelector('.goal-date__value');
            v.textContent = draft.end ? dateText(draft.end) : words.end_choose;
            v.classList.toggle('is-empty', !draft.end);
            end.setAttribute('aria-label', words.end + ': ' + v.textContent);
        }
        each('[data-wizard-duration]', function (b) {
            b.setAttribute('aria-pressed', b.getAttribute('data-wizard-duration') === draft.duration ? 'true' : 'false');
        }, setup);
        if (draft.type === 'milestone') {
            loadBaseline();
            renderBaseline();
        }
        validateSetup();
    }

    /* ------------------------------------------------------------ calendar */

    var view = null;    // the month on screen

    function openCalendar(which) {
        draft.calendar = which;
        var selected = which === 'start' ? draft.start : (draft.end || dayAfter(draft.start, 1));
        view = new Date(selected.getFullYear(), selected.getMonth(), 1);
        paintCalendar();

        var section = root.querySelector('.wizard__step.is-active');
        if (section) { section.hidden = true; }
        calendar.hidden = false;
        foot.hidden = true;
        body.scrollTop = 0;
        enter(calendar, 1, carry());
        var chosen = calendar.querySelector('[aria-selected="true"]') || calendar.querySelector('[data-calendar-day]:not([disabled])');
        if (chosen) { chosen.focus({ preventScroll: true }); }
    }

    function closeCalendar() {
        draft.calendar = null;
        calendar.hidden = true;
        foot.hidden = false;
        var section = root.querySelector('[data-wizard-step="3"]');
        section.hidden = false;
        enter(section, -1, carry());
        refreshDates();
        var back = setup.querySelector('[data-wizard-date="end"]');
        if (back) { back.focus({ preventScroll: true }); }
    }

    function bounds() {
        var now = today();
        if (draft.calendar === 'start') { return { min: dayAfter(now, -365), max: now }; }
        var min = dayAfter(draft.start, 1);
        if (min < now) { min = now; }
        return { min: min, max: addMonths(draft.start, 36) };
    }

    function weekStartsSunday() { return copy.week_start === 'sunday'; }

    function paintCalendar() {
        var range = bounds();
        calendar.querySelector('[data-calendar-title]').textContent = draft.calendar === 'start' ? words.pick_start : words.pick_end;
        calendar.querySelector('[data-calendar-month]').textContent = T.date(view, 'month_year');

        var prev = calendar.querySelector('[data-calendar-prev]');
        var next = calendar.querySelector('[data-calendar-next]');
        prev.disabled = new Date(view.getFullYear(), view.getMonth(), 0) < range.min;
        next.disabled = new Date(view.getFullYear(), view.getMonth() + 1, 1) > range.max;

        var weekdays = calendar.querySelector('[data-calendar-weekdays]');
        weekdays.innerHTML = '';
        var first = weekStartsSunday() ? 0 : 1;
        for (var i = 0; i < 7; i++) { weekdays.appendChild(el('li', null, T.weekday((first + i) % 7, true))); }

        var grid = calendar.querySelector('[data-calendar-grid]');
        grid.innerHTML = '';
        var lead = (new Date(view.getFullYear(), view.getMonth(), 1).getDay() - first + 7) % 7;
        var days = new Date(view.getFullYear(), view.getMonth() + 1, 0).getDate();
        var rowEl = null;

        for (var cell = 0; cell < lead + days; cell++) {
            if (cell % 7 === 0) {
                rowEl = el('div', 'goal-calendar__row');
                rowEl.setAttribute('role', 'row');
                grid.appendChild(rowEl);
            }
            if (cell < lead) {
                var gap = el('span', 'goal-calendar__gap');
                gap.setAttribute('role', 'gridcell');
                rowEl.appendChild(gap);
                continue;
            }
            var d = new Date(view.getFullYear(), view.getMonth(), cell - lead + 1);
            var b = el('button', 'goal-calendar__day press', String(d.getDate()));
            b.type = 'button';
            b.setAttribute('role', 'gridcell');
            b.setAttribute('data-calendar-day', iso(d));
            b.setAttribute('aria-label', T.date(d, 'weekday_day_month') + ' ' + d.getFullYear());
            var isStart = sameDay(d, draft.start);
            var isEnd = sameDay(d, draft.end);
            var selected = draft.calendar === 'start' ? isStart : isEnd;
            b.setAttribute('aria-selected', selected ? 'true' : 'false');
            if (isStart) { b.classList.add('is-start'); }
            if (isEnd) { b.classList.add('is-end'); }
            if (draft.end && d > draft.start && d < draft.end) { b.classList.add('is-between'); }
            if (sameDay(d, today())) { b.classList.add('is-today'); }
            b.disabled = d < range.min || d > range.max;
            rowEl.appendChild(b);
        }
    }

    function pickDay(text) {
        var parts = text.split('-');
        var d = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
        if (draft.calendar === 'start') {
            draft.start = d;
            if (draft.duration) {
                draft.end = durationEnd(d, draft.duration);
            } else if (draft.end && draft.end <= d) {
                draft.end = null;
            }
        } else {
            draft.end = d;
            draft.duration = null;
            Object.keys(copy.durations).some(function (key) {
                if (sameDay(durationEnd(draft.start, key), d)) { draft.duration = key; return true; }
                return false;
            });
        }
        paintCalendar();
        var again = calendar.querySelector('[data-calendar-day="' + text + '"]');
        if (again) { again.focus({ preventScroll: true }); }
    }

    function calendarDone() {
        if (draft.calendar === 'start') {
            /* On to the end date, from the start just chosen. */
            draft.calendar = 'end';
            var selected = draft.end || dayAfter(draft.start, 1);
            view = new Date(selected.getFullYear(), selected.getMonth(), 1);
            paintCalendar();
            enter(calendar, 1, carry());
            return;
        }
        closeCalendar();
    }

    /* ---------------------------------------------------------- checking */

    function setupProblem() {
        var m = current();
        if (!m) { return null; }

        if (draft.unit === 'custom' && draft.variant !== 'period') {
            var word = draft.customUnit.trim();
            if (word === '' || word.length > 20) { return ''; }
        }

        if (draft.variant === 'period') {
            var n = Number(String(draft.frequency).trim());
            var most = m.source_kind === 'manual' || m.custom ? { week: 7, month: 28, quarter: 89, year: 365 }[draft.period] : 999;
            if (String(draft.frequency).trim() === '' || !isFinite(n) || n < 1 || Math.floor(n) !== n) { return ''; }
            if (n > most) { return T.tf('Vul een heel aantal keer in, van 1 tot %d.', most); }
            if (draft.end && periodAsks(draft.start, draft.end, draft.period, n) < 1) {
                return T.t('Deze periode is te kort voor dit doel. Kies een latere einddatum of een kortere periode.');
            }
        } else {
            if (!positive(draft.target)) { return ''; }
            if (draft.unit === 'pct' && parsed(draft.target) > 100) { return T.t('Een percentage kan niet hoger zijn dan 100.'); }
        }

        if (draft.type === 'milestone') {
            var unit = unitOf(draft.unit);
            var base = null;
            if (draft.baseline.state === 'measured') { base = draft.baseline.value; }
            else if (draft.baseline.state === 'none') {
                var typed = parsed(draft.baselineTyped);
                if (typed === null || typed < 0) { return ''; }
                base = typed * unit.factor;
            } else { return ''; }
            var target = parsed(draft.target) * unit.factor;
            var ahead = draft.direction === 'decrease' ? target < base - 1e-9 : target > base + 1e-9;
            if (!ahead) {
                return draft.direction === 'decrease'
                    ? T.tf('Je doelwaarde moet lager zijn dan je startwaarde (%s).', valueText(base, unit))
                    : T.tf('Je doelwaarde moet hoger zijn dan je startwaarde (%s).', valueText(base, unit));
            }
        }

        if (!draft.end || draft.end <= draft.start) { return ''; }
        return null;
    }

    function validateSetup() {
        var slot = setup.querySelector('[data-wizard-setup-error]');
        var problem = setupProblem();
        if (slot) {
            slot.textContent = problem || '';
            slot.hidden = !problem;
        }
        refresh();
    }

    function isComplete(which) {
        if (which === 1) {
            return draft.measurement !== null && (draft.measurement !== 'custom' || draft.category !== null);
        }
        if (which === 2) { return draft.name.trim().length >= 2 && draft.type !== null && typeAllowed(current(), draft.type); }
        if (which === 3) { return setupProblem() === null; }
        return true;
    }

    function refresh() {
        nextBtn.disabled = saving || !isComplete(step);
        nextBtn.setAttribute('aria-disabled', nextBtn.disabled ? 'true' : 'false');
    }

    /* ============================================================ review */

    function targetSummary() {
        var m = current();
        if (draft.variant === 'period') {
            return draft.frequency + ' ' + (m.count || T.t('keer')) + ' ' + words.per + ' ' + copy.periods[draft.period].label;
        }
        var text = typedText(draft.target, unitOf(draft.unit));
        if (draft.variant === 'daily') {
            text = isAuto(m)
                ? (draft.direction === 'decrease' ? T.tf('Elke dag hoogstens %s', text) : T.tf('Elke dag minstens %s', text))
                : T.tf('Elke dag %s', text);
        }
        return text;
    }

    function renderReview() {
        var m = current();
        var category = copy.categories[m.custom ? draft.category : m.category];
        var s = words.summary;
        var rows = [
            [s.name, draft.name.trim()],
            [s.measure, m.label + ' · ' + category.label],
            [s.type, copy.types[draft.type].label + (draft.variant ? ' · ' + copy.streak_variants[draft.variant].label : '')],
            [s.target, targetSummary()]
        ];
        if (needsDirection()) { rows.push([s.direction, directionWords()[draft.direction].label]); }
        if (draft.type === 'milestone') {
            var unit = unitOf(draft.unit);
            rows.push([s.baseline, draft.baseline.state === 'measured'
                ? valueText(draft.baseline.value, unit) + ' · ' + draft.baseline.on
                : typedText(draft.baselineTyped, unit)]);
        }
        rows.push([s.dates, dateText(draft.start) + ' – ' + dateText(draft.end)]);
        rows.push([s.tracking, isAuto(m) ? words.tracking_auto : words.tracking_manual]);

        review.innerHTML = '';
        rows.forEach(function (row) {
            var line = el('div', 'goal-review__row');
            line.appendChild(el('dt', null, row[0]));
            line.appendChild(el('dd', null, row[1]));
            review.appendChild(line);
        });
    }

    /* ============================================================ moving */

    var entering = null;
    var reduceQuery = window.matchMedia('(prefers-reduced-motion: reduce)');

    /** Where the step on its way in is now, and stop it there; null when none is moving. */
    function carry() {
        if (!entering || entering.anim.playState !== 'running') { return null; }
        var style = window.getComputedStyle(entering.el);
        var x = 0;
        if (style.transform && style.transform !== 'none' && typeof window.DOMMatrixReadOnly === 'function') {
            x = new window.DOMMatrixReadOnly(style.transform).m41;
        }
        var now = { opacity: parseFloat(style.opacity), dist: Math.abs(x) };
        entering.anim.cancel();
        entering = null;
        return now;
    }

    /* The step arriving slides in 14 px from the side it came from and fades
       in — interruptible: a tap while one is on its way carries on from where
       it is on screen. Reduced motion: a 180 ms fade. */
    function enter(section, direction, from) {
        if (!section || typeof section.animate !== 'function' || root.hidden) { return; }
        var opacity = from ? from.opacity : 0;
        var frames, timing;
        if (reduceQuery.matches) {
            frames = [{ opacity: opacity }, { opacity: 1 }];
            timing = { duration: 180, easing: 'ease-out' };
        } else {
            var x = (from ? from.dist : 14) * (direction === -1 ? -1 : 1);
            var css = window.getComputedStyle(document.documentElement);
            frames = [{ opacity: opacity, transform: 'translateX(' + x + 'px)' }, { opacity: 1, transform: 'none' }];
            timing = {
                duration: parseFloat(css.getPropertyValue('--screen-duration')) || 280,
                easing: css.getPropertyValue('--screen-ease').trim() || 'cubic-bezier(0.22, 1, 0.36, 1)'
            };
        }
        entering = { el: section, anim: section.animate(frames, timing) };
    }

    function paint(direction) {
        var from = carry();
        var arriving = null;
        each('[data-wizard-step]', function (section) {
            var isActive = section.getAttribute('data-wizard-step') === String(step);
            section.hidden = !isActive;
            section.classList.toggle('is-active', isActive);
            if (isActive) { arriving = section; }
        });
        calendar.hidden = true;
        foot.hidden = false;
        enter(arriving, direction, from);

        var reached = step === 'review' ? STEPS : step;
        each('[data-wizard-bar]', function (bar) {
            bar.classList.toggle('is-done', Number(bar.getAttribute('data-wizard-bar')) <= reached);
        });

        count.textContent = step === 'review'
            ? words.review.label
            : T.tf('Stap %1$d van %2$d', step, STEPS) + ' · ' + words.steps[step].label;

        nextLabel.textContent = step === 'review' ? words.create : words.next;
        nextBtn.classList.toggle('is-final', step === 'review');
        showError('');
        refresh();
    }

    function go(to, direction) {
        step = to;
        if (to === 1) { renderList(); }
        if (to === 2) { renderDefinition(); }
        if (to === 3) { renderSetup(); }
        if (to === 'review') { renderReview(); }
        paint(direction);
        body.scrollTop = 0;

        /* The new screen's title, for whoever reads it out. */
        var title = root.querySelector('[data-wizard-step="' + to + '"] .wizard__title');
        if (title && direction !== 0) {
            title.setAttribute('tabindex', '-1');
            title.focus({ preventScroll: true });
        }
    }

    function forward() {
        if (!isComplete(step)) { return; }
        if (step === 'review') { finish(); return; }
        go(step === STEPS ? 'review' : step + 1, 1);
    }

    function backward() {
        if (saving) { return; }
        if (draft.calendar) { closeCalendar(); return; }
        if (step === 1) { close(); return; }
        go(step === 'review' ? STEPS : step - 1, -1);
    }

    /* ============================================================ saving */

    function csrf() {
        /* The account panel's, or — on the setup, which has none — the page's (pages/setup.php). */
        var panel = document.querySelector('[data-account]') || document.querySelector('[data-csrf]');
        return panel ? (panel.getAttribute('data-csrf') || '') : '';
    }

    /** What the wizard collected, in the fields api/goals/create.php takes. */
    function payload() {
        var m = current();
        var form = new FormData();
        form.append('csrf', csrf());
        form.append('create_token', draft.token);
        form.append('name', draft.name.trim());
        form.append('measurement', draft.measurement);
        if (m.custom) { form.append('category', draft.category); }
        form.append('type', draft.type);
        if (draft.variant) { form.append('variant', draft.variant); }
        form.append('start_date', iso(draft.start));
        form.append('end_date', iso(draft.end));

        if (draft.variant === 'period') {
            form.append('frequency', String(draft.frequency).trim());
            form.append('period', draft.period);
        } else {
            form.append('target_value', String(draft.target).trim());
            form.append('unit', draft.unit);
            if (draft.unit === 'custom') { form.append('custom_unit', draft.customUnit.trim()); }
            form.append('direction', draft.direction);
        }

        if (draft.type === 'milestone' && draft.baseline.state !== 'measured') {
            form.append('baseline_value', String(draft.baselineTyped).trim());
        }
        return form;
    }

    function showError(message) {
        errorSlot.textContent = message || '';
        errorSlot.hidden = !message;
    }

    function setSaving(on) {
        saving = on;
        root.classList.toggle('is-saving', on);
        nextLabel.textContent = on ? words.creating : (step === 'review' ? words.create : words.next);
        backBtn.disabled = on;
        refresh();
    }

    /**
     * Makes the goal. Once: the button is out of reach while it is on its
     * way, and the one-time key makes a second request the same goal. A
     * failure keeps every answer and says why.
     */
    function finish() {
        if (saving) { return; }
        setSaving(true);
        showError('');

        fetch('api/goals/create.php', { method: 'POST', body: payload(), credentials: 'same-origin' })
            .then(function (response) {
                return response.json().catch(function () {
                    return { ok: false, error: T.t('Onverwacht antwoord van de server.') };
                });
            })
            .catch(function () {
                return { ok: false, error: T.t('Ownify is niet bereikbaar. Controleer je internetverbinding en probeer het opnieuw.') };
            })
            .then(function (result) {
                if (!result || !result.ok) {
                    setSaving(false);
                    showError((result && result.error) || T.t('Dit doel kon niet worden opgeslagen.'));
                    return;
                }
                var id = result.goal_id ? String(result.goal_id) : null;
                var name = draft.name.trim();
                setSaving(false);
                close(true);
                document.dispatchEvent(new CustomEvent('goalwizard:saved', { detail: { id: id, name: name } }));
                showNewGoal(id);
            });
    }

    /** The board on Actief, with the new goal's card in view (goals.js). */
    function showNewGoal(id) {
        if (!window.GoalBoard) { return; }
        window.GoalBoard.refresh().then(function (shown) {
            if (!shown) { return; }
            window.GoalBoard.show('active');
            var card = id ? document.querySelector('[data-goals] [data-goal-card="' + id + '"]') : null;
            if (card) {
                card.classList.add('is-new');
                if (card.scrollIntoView) { card.scrollIntoView({ block: 'nearest', behavior: reduceQuery.matches ? 'auto' : 'smooth' }); }
                window.setTimeout(function () { card.classList.remove('is-new'); }, 1600);
            }
        });
    }

    /* ======================================================= open/close */

    function reset() {
        draft = blank();
        saving = false;
        root.classList.remove('is-saving');
        backBtn.disabled = false;
        search.value = '';
        nameInput.value = '';
        each('[data-wizard-category]', function (b) { b.setAttribute('aria-pressed', 'false'); });
        each('[data-wizard-type]', function (b) { b.setAttribute('aria-checked', 'false'); });
        step = 1;
        renderList();
        paint(0);
    }

    function open(trigger) {
        lastFocus = trigger || document.activeElement;
        reset();
        root.hidden = false;
        window.requestAnimationFrame(function () { root.classList.add('is-open'); });
        carry();
        enter(root.querySelector('.wizard__step.is-active'), 1, null);
        var first = root.querySelector('[data-wizard-category]');
        if (first) { first.focus({ preventScroll: true }); }
    }

    function close(saved) {
        if (saving) { return; }
        hideTip();
        root.classList.remove('is-open');
        window.setTimeout(function () { root.hidden = true; }, 200);
        if (!saved && lastFocus && lastFocus.focus) { lastFocus.focus({ preventScroll: true }); }
    }

    /* ============================================================ events */

    root.addEventListener('click', function (event) {
        var t = event.target;
        var hit;

        if (t.closest('[data-wizard-close]')) { close(); return; }
        if (t.closest('[data-wizard-back]')) { backward(); return; }
        if (t.closest('[data-wizard-next]')) { forward(); return; }

        if ((hit = t.closest('[data-wizard-category]'))) { chooseCategory(hit.getAttribute('data-wizard-category')); return; }
        if ((hit = t.closest('[data-wizard-measure]'))) { chooseMeasurement(hit.getAttribute('data-wizard-measure')); return; }
        if (t.closest('[data-wizard-search-clear]')) {
            search.value = '';
            draft.query = '';
            renderList();
            search.focus();
            return;
        }
        if ((hit = t.closest('[data-wizard-idea]'))) {
            nameInput.value = hit.getAttribute('data-wizard-idea');
            draft.name = nameInput.value;
            nameInput.focus({ preventScroll: true });
            refresh();
            return;
        }
        if ((hit = t.closest('[data-wizard-type]'))) { chooseType(hit.getAttribute('data-wizard-type')); return; }
        if ((hit = t.closest('[data-wizard-variant]'))) {
            draft.variant = hit.getAttribute('data-wizard-variant');
            draft.direction = null;
            renderSetup();
            var again = setup.querySelector('[data-wizard-variant="' + draft.variant + '"]');
            if (again) { again.focus({ preventScroll: true }); }
            return;
        }
        if ((hit = t.closest('[data-wizard-direction]'))) {
            if (hold.shown) { hold.shown = false; return; }   // a long press explains, it does not choose
            chooseDirection(hit.getAttribute('data-wizard-direction'));
            return;
        }
        if ((hit = t.closest('[data-wizard-duration]'))) { chooseDuration(hit.getAttribute('data-wizard-duration')); return; }
        if ((hit = t.closest('[data-wizard-date]'))) { openCalendar(hit.getAttribute('data-wizard-date')); return; }

        if ((hit = t.closest('[data-calendar-day]'))) { pickDay(hit.getAttribute('data-calendar-day')); return; }
        if (t.closest('[data-calendar-prev]')) { view = new Date(view.getFullYear(), view.getMonth() - 1, 1); paintCalendar(); return; }
        if (t.closest('[data-calendar-next]')) { view = new Date(view.getFullYear(), view.getMonth() + 1, 1); paintCalendar(); return; }
        if (t.closest('[data-calendar-done]')) { calendarDone(); return; }
    });

    root.addEventListener('input', function (event) {
        var field = event.target;

        if (field === search) {
            draft.query = search.value;
            renderList();
            return;
        }
        if (field === nameInput) { draft.name = nameInput.value; refresh(); return; }

        switch (field.getAttribute('data-wizard-field')) {
            case 'target': draft.target = field.value; break;
            case 'frequency': draft.frequency = field.value; break;
            case 'baseline': draft.baselineTyped = field.value; break;
            case 'custom-unit':
                draft.customUnit = field.value;
                renderBaseline();
                break;
            default: return;
        }
        validateSetup();
    });

    root.addEventListener('change', function (event) {
        var field = event.target;

        if (field.hasAttribute('data-wizard-unit')) {
            /* Another unit: what was typed keeps its meaning — 80 kg is 176,4 lb. */
            var before = unitOf(draft.unit);
            draft.unit = field.value;
            var after = unitOf(draft.unit);
            if (draft.unit !== 'custom' && before.code) {
                ['target', 'baselineTyped'].forEach(function (key) {
                    var n = parsed(draft[key]);
                    if (n !== null) { draft[key] = T.numberTrim(round(n * before.factor / after.factor, after.decimals), after.decimals); }
                });
            }
            renderSetup();
            var select = setup.querySelector('[data-wizard-unit]');
            if (select) { select.focus({ preventScroll: true }); }
            return;
        }

        if (field.hasAttribute('data-wizard-period')) {
            draft.period = field.value;
            validateSetup();
        }
    });

    /* Press and hold on a direction button: its explanation. */
    root.addEventListener('pointerdown', function (event) {
        var button = event.target.closest('[data-wizard-direction]');
        if (!button) { return; }
        hold.shown = false;
        window.clearTimeout(hold.timer);
        hold.timer = window.setTimeout(function () { showTip(button); }, 450);
    });

    ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (type) {
        root.addEventListener(type, function (event) {
            if (!event.target.closest || !event.target.closest('[data-wizard-direction]')) { return; }
            window.clearTimeout(hold.timer);
            if (hold.shown) { window.setTimeout(hideTip, 900); }
        }, true);
    });

    root.addEventListener('contextmenu', function (event) {
        if (event.target.closest('[data-wizard-direction]')) { event.preventDefault(); }
    });

    root.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' && event.target.matches && event.target.matches('input')) {
            event.preventDefault();
            if (!nextBtn.disabled && !draft.calendar) { forward(); }
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape' || root.hidden) { return; }
        if (draft.calendar) { closeCalendar(); return; }
        close();
    });

    window.GoalWizard = { open: open, close: close };
}());
