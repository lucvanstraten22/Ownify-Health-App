/*
 * The wheel (48.0, docs/THEME.md "The wheel"): every number, duration, time
 * of day and date in Ownify is picked on it — no native picker anywhere. A
 * tap on the field opens columns that turn under the finger, each part its
 * own column (hours and minutes; hours : minutes; day, month, year; the
 * whole and its decimal), the value in the field as it turns. A tap anywhere
 * outside it closes it, and does nothing else. On a computer a number or a
 * time can also be typed in the field.
 *
 * On a field: data-wheel='{"kind":"number","min":0,"max":50000,"step":500,
 * "default":8000,"unit":"stappen"}', '{"kind":"duration","default":30}'
 * (whole minutes), '{"kind":"clock"}' ("23:00") or '{"kind":"date",
 * "min":"2025-11-10","max":"2026-11-10"}'. From a script:
 * OwnifyWheel.attach(input, spec); OwnifyWheel.forUnit(unit, start) makes a
 * goal unit's spec from its `wheel` (config/goals.php). A date field shows
 * the date as a person reads it and keeps "YYYY-MM-DD" for the form:
 * OwnifyWheel.value(input); OwnifyWheel.set(input, "YYYY-MM-DD") puts one in. The Android app draws the same (ui/design/Wheel.kt).
 */
(function () {
    'use strict';

    var I18n = window.OwnifyI18n || {
        locale: 'nl',
        t: function (s) { return s; },
        number: function (v, d) { return Number(v).toFixed(d || 0); },
        parseNumber: function (s) { var n = Number(String(s).replace(',', '.')); return isFinite(n) && String(s).trim() !== '' ? n : null; },
        month: function (m) { return String(m); }
    };
    var ROW = 36;            // px a row is high: --wheel-row
    var MAX_ROWS = 400;      // a column never longer: a long range turns in bigger steps
    var coarse = window.matchMedia ? window.matchMedia('(pointer: coarse)') : { matches: false };
    var open = null;         // { input, spec, panel, columns }
    var swallow = 0;         // the click after a tap that closed the wheel does nothing

    function specOf(input) {
        if (input.tagName === 'SELECT') {
            return { kind: 'list', options: Array.prototype.map.call(input.options, function (o) { return { value: o.value, label: o.textContent.trim() }; }) };
        }
        if (input._wheel) { return input._wheel; }
        try { return JSON.parse(input.getAttribute('data-wheel') || '{}'); } catch (e) { return {}; }
    }

    /* ----------------------------------------------------- the numbers */

    function round(v) { return Math.round(v * 10000) / 10000; }

    function range(from, to, step) {
        var out = [];
        for (var i = 0, v = from; v <= to + 1e-9 && i < 5000; i++, v = round(from + i * step)) { out.push(v); }
        return out;
    }

    /** Decimals a step needs: 0,5 → 1; 0,25 → 2; 500 → 0. */
    function decimalsOf(step) {
        var s = String(step);
        return s.indexOf('.') === -1 ? 0 : s.length - s.indexOf('.') - 1;
    }

    /** The spec for a goal's unit (its `wheel` in config/goals.php), around where it starts. */
    function forUnit(unit, start) {
        var w = (unit && unit.wheel) || [0, 1000, 1];
        var step = Number(w[2]) || 1;
        var top = Number(w[1]);
        if (start !== null && start !== undefined && isFinite(start) && start * 1.5 > top) {
            top = Math.ceil(start * 2 / step) * step;            // a total over a month: room above it
        }
        return { kind: 'number', min: Number(w[0]), max: top, step: step,
            'default': start === null || start === undefined || !isFinite(start) ? null : start, unit: unit ? unit.label : '' };
    }

    function sep() { return I18n.number(1.5, 1).charAt(1); }

    function pad(n) { return (n < 10 ? '0' : '') + n; }

    function daysIn(y, m) { return new Date(y, m, 0).getDate(); }

    function iso(y, m, d) { return y + '-' + pad(m) + '-' + pad(d); }

    function parseIso(s) {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(s || ''));
        return m ? { y: +m[1], m: +m[2], d: +m[3] } : null;
    }

    /* ------------------------------------------------------ the columns */

    /** The columns for a spec standing on `current`, each {values, value, label(v), unit, sep}. */
    function columns(spec, current) {
        var plain = function (v) { return String(v); };

        if (spec.kind === 'clock' || spec.kind === 'duration') {
            var clock = spec.kind === 'clock';
            var total;
            if (clock) {
                var t = /^(\d{1,2}):(\d{2})/.exec(String(current || ''));
                var start = /^(\d{1,2}):(\d{2})/.exec(String(spec['default'] || '12:00'));
                total = t ? (+t[1]) * 60 + (+t[2]) : (+start[1]) * 60 + (+start[2]);
            } else {
                var n = I18n.parseNumber(current);
                total = Math.round(n === null ? (spec['default'] !== undefined ? spec['default'] : 30) : n);
                total = Math.max(spec.min || 0, Math.min(spec.max || 1440, total));
            }
            var step = spec.step || 1;
            var top = clock ? 23 : Math.floor((spec.max || 1440) / 60);
            return [
                { values: range(0, top, 1), value: Math.min(top, Math.floor(total / 60)), label: clock ? pad : plain, unit: clock ? '' : I18n.t('u'), sep: clock ? ':' : '' },
                { values: range(0, 60 - step, step), value: Math.round((total % 60) / step) * step % 60, label: pad, unit: clock ? '' : I18n.t('min') }
            ];
        }

        if (spec.kind === 'list') {
            var opts = spec.options || [];
            var at2 = opts.map(function (o) { return o.value; }).indexOf(String(current));
            return [{ values: range(0, Math.max(0, opts.length - 1), 1), value: Math.max(0, at2),
                label: function (i) { return opts[i] ? opts[i].label : ''; }, list: true }];
        }

        if (spec.kind === 'date') {
            var today = new Date();
            var lo = parseIso(spec.min) || { y: today.getFullYear() - 120, m: 1, d: 1 };
            var hi = parseIso(spec.max) || { y: today.getFullYear(), m: today.getMonth() + 1, d: today.getDate() };
            var at = parseIso(current) || parseIso(spec['default']) || hi;
            var day = { values: range(1, daysIn(at.y, at.m), 1), value: at.d, label: plain, part: 'd' };
            var month = { values: range(1, 12, 1), value: at.m, label: function (v) { return I18n.month(v, true); }, part: 'm', wide: true };
            var year = { values: range(lo.y, hi.y, 1), value: at.y, label: plain, part: 'y' };
            return I18n.locale === 'en' ? [month, day, year] : [day, month, year];
        }

        var step2 = Number(spec.step) || 1;
        var min = spec.min !== undefined ? Number(spec.min) : 0;
        var max = spec.max !== undefined ? Number(spec.max) : 1000;
        var d = decimalsOf(step2);
        var given = I18n.parseNumber(current);
        var v = given !== null ? given : (spec['default'] !== null && spec['default'] !== undefined ? Number(spec['default']) : min);
        v = Math.max(min, Math.min(max, v));
        var unit = spec.unit || '';

        /* A fine step over a long range: the whole and its fraction, each a column (72 | ,5 kg). */
        if (step2 < 1 && (max - min) / step2 > MAX_ROWS) {
            var whole = Math.floor(v);
            return [
                { values: range(Math.floor(min), Math.floor(max), 1), value: whole, label: function (x) { return I18n.number(x, 0); } },
                { values: range(0, round(1 - step2), step2), value: round(Math.round((v - whole) / step2) * step2) % 1,
                    label: function (x) { return sep() + I18n.number(x, d).split(sep())[1]; }, unit: unit, fraction: true }
            ];
        }
        while ((max - min) / step2 > MAX_ROWS) { step2 = step2 * (String(step2).charAt(0) === '2' ? 2.5 : 2); d = decimalsOf(step2); }
        var values = range(Math.ceil(min / step2 - 1e-9) * step2, max, step2).map(round);
        var near = values.reduce(function (b, x) { return Math.abs(x - v) < Math.abs(b - v) ? x : b; }, values[0]);
        return [{ values: values, value: near, label: function (x) { return I18n.number(x, d); }, unit: unit }];
    }

    /** The value the columns stand on now, as the field takes it. */
    function written(spec, cols, input) {
        if (spec.kind === 'list') { return spec.options[cols[0].value] ? spec.options[cols[0].value].value : ''; }
        if (spec.kind === 'clock') { return pad(cols[0].value) + ':' + pad(cols[1].value); }
        if (spec.kind === 'duration') {
            return String(Math.max(spec.min || 0, Math.min(spec.max || 1440, cols[0].value * 60 + cols[1].value)));
        }
        if (spec.kind === 'date') {
            var p = {};
            cols.forEach(function (c) { p[c.part] = c.value; });
            var out = iso(p.y, p.m, Math.min(p.d, daysIn(p.y, p.m)));
            if (spec.min && out < spec.min) { out = spec.min; }      // ISO dates compare as text
            if (spec.max && out > spec.max) { out = spec.max; }
            return out;
        }
        var v = cols[0].value + (cols[1] ? cols[1].value : 0);
        var d = cols[1] ? decimalsOf(spec.step) : decimalsOf(Number(spec.step) || 1);
        v = Math.max(spec.min !== undefined ? spec.min : -Infinity, Math.min(spec.max !== undefined ? spec.max : Infinity, round(v)));
        return input && input.type === 'number' ? v.toFixed(d) : I18n.number(v, d, false);
    }

    /** A date as a person reads it: 10 nov 2026, Nov 10, 2026. */
    function shown(spec, value) {
        if (spec.kind !== 'date') { return value; }
        var p = parseIso(value);
        return p && I18n.date ? I18n.date(new Date(p.y, p.m - 1, p.d), 'day_mon_year') : value;
    }

    function value(input) {
        return input && input._wheelOn && specOf(input).kind === 'date' ? (input.getAttribute('data-value') || '') : (input ? input.value : '');
    }

    function set(input, spec, text) {
        if (spec.kind === 'date') {
            input.setAttribute('data-value', text);
            input.value = shown(spec, text);
        } else {
            input.value = text;
        }
    }

    /* -------------------------------------------------------- the panel */

    function fill(list, col) {
        list.textContent = '';
        col.values.forEach(function (v) {
            var item = document.createElement('div');
            item.className = 'wheel__item';
            item.textContent = col.label(v);
            list.appendChild(item);
        });
    }

    function build(input, spec) {
        var panel = document.createElement('div');
        panel.className = 'wheel glass wheel--' + (spec.kind || 'number');
        panel.setAttribute('role', 'dialog');
        var name = input.getAttribute('aria-label') || (input.labels && input.labels[0] ? input.labels[0].textContent.trim() : '');
        panel.setAttribute('aria-label', name);

        var cols = columns(spec, value(input));
        var row = document.createElement('div');
        row.className = 'wheel__columns';

        cols.forEach(function (col, n) {
            var wrap = document.createElement('div');
            wrap.className = 'wheel__column' + (col.wide ? ' wheel__column--wide' : '');

            var list = document.createElement('div');
            list.className = 'wheel__list';
            list.tabIndex = 0;
            list.setAttribute('role', 'spinbutton');
            list.setAttribute('aria-label', name + (col.unit ? ' (' + col.unit + ')' : ''));
            fill(list, col);
            wrap.appendChild(list);

            if (col.unit) {
                var unit = document.createElement('span');
                unit.className = 'wheel__unit';
                unit.textContent = col.unit;
                wrap.appendChild(unit);
            }
            row.appendChild(wrap);
            if (col.sep) {
                var s = document.createElement('span');
                s.className = 'wheel__sep';
                s.setAttribute('aria-hidden', 'true');
                s.textContent = col.sep;
                row.appendChild(s);
            }

            col.list = list;
            col.index = Math.max(0, col.values.indexOf(col.value));
            list.addEventListener('scroll', function () { turned(col); }, { passive: true });
            list.addEventListener('keydown', function (e) {
                var by = e.key === 'ArrowUp' ? -1 : e.key === 'ArrowDown' ? 1 : e.key === 'PageUp' ? -10 : e.key === 'PageDown' ? 10 : 0;
                if (by) { e.preventDefault(); to(col, col.index + by, true); }
            });
            list.addEventListener('click', function (e) {
                if (list._dragged) { list._dragged = false; return; }   // the end of a drag is not a tap
                var item = e.target.closest('.wheel__item');
                if (item) { to(col, Array.prototype.indexOf.call(list.children, item), true); }
            });
            /* 50.6: held and dragged with a mouse, as a finger turns it — 1:1
               while held (snapping off), then onto the nearest row, carried a
               little further by the flick. Touch keeps the native scroll. */
            list.addEventListener('pointerdown', function (e) {
                if (e.pointerType !== 'mouse' || e.button !== 0) { return; }
                e.preventDefault();
                var startY = e.clientY, startTop = list.scrollTop, lastY = e.clientY, lastT = e.timeStamp, v = 0, moved = false;
                list.setPointerCapture(e.pointerId);
                list.style.scrollSnapType = 'none';
                list.style.cursor = 'grabbing';
                function move(ev) {
                    var dy = ev.clientY - startY;
                    if (Math.abs(dy) > 3) { moved = true; }
                    list.scrollTop = startTop - dy;
                    var dt = ev.timeStamp - lastT;
                    if (dt > 0) { v = (ev.clientY - lastY) / dt; }   // px per ms
                    lastY = ev.clientY; lastT = ev.timeStamp;
                }
                function up() {
                    list.removeEventListener('pointermove', move);
                    list.removeEventListener('pointerup', up);
                    list.removeEventListener('pointercancel', up);
                    list.style.cursor = '';
                    list._dragged = moved;
                    var projected = list.scrollTop - v * 120;          // a short flick carries on
                    list.style.scrollSnapType = '';
                    to(col, Math.round(projected / ROW), true);
                }
                list.addEventListener('pointermove', move);
                list.addEventListener('pointerup', up);
                list.addEventListener('pointercancel', up);
            });
            if (n === 0) { panel._first = list; }
        });

        var band = document.createElement('div');
        band.className = 'wheel__band';
        band.setAttribute('aria-hidden', 'true');
        row.appendChild(band);
        panel.appendChild(row);

        panel.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' || e.key === 'Enter') { e.preventDefault(); close(); input.focus({ preventScroll: true }); }
        });

        return { panel: panel, columns: cols };
    }

    /** A column turned by the finger: the row in the middle is its value. */
    function turned(col) {
        var i = Math.max(0, Math.min(col.values.length - 1, Math.round(col.list.scrollTop / ROW)));
        if (i === col.index) { return; }
        col.index = i;
        col.value = col.values[i];
        if (col.part === 'm' || col.part === 'y') { days(); }
        write();
    }

    /** A date's days follow its month: 31, 30, 29 or 28 of them. */
    function days() {
        var p = {};
        open.columns.forEach(function (c) { p[c.part] = c; });
        var n = daysIn(p.y.value, p.m.value);
        if (p.d.values.length === n) { return; }
        p.d.values = range(1, n, 1);
        fill(p.d.list, p.d);
        p.d.value = Math.min(p.d.value, n);
        p.d.index = p.d.value - 1;
        p.d.list.scrollTop = p.d.index * ROW;
    }

    function to(col, index, smooth) {
        var i = Math.max(0, Math.min(col.values.length - 1, index));
        col.list.scrollTo({ top: i * ROW, behavior: smooth && !reduced() ? 'smooth' : 'auto' });
        if (i !== col.index) { col.index = i; col.value = col.values[i]; write(); }
    }

    function reduced() {
        return document.documentElement.getAttribute('data-motion') === 'reduce'
            || (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }

    /** Into the field, as it turns — the way an iPhone's wheel writes. */
    function write() {
        if (!open) { return; }
        var typed = Date.now() - (open.typed || 0) < 400;      // following the keyboard: the field is the person's
        open.columns.forEach(function (col) {
            col.list.setAttribute('aria-valuenow', String(col.value));
            col.list.setAttribute('aria-valuetext', col.label(col.value) + (col.unit ? ' ' + col.unit : ''));
        });
        var text = written(open.spec, open.columns, open.input);
        /* Turned past today (or before the first day): back to the nearest allowed. */
        if (open.spec.kind === 'date') {
            var at = parseIso(text), cols = open.columns;
            cols.forEach(function (c) {
                var want = c.part === 'y' ? at.y : c.part === 'm' ? at.m : at.d;
                if (c.value !== want) { c.value = want; c.index = c.values.indexOf(want); window.setTimeout(function () { c.list.scrollTo({ top: c.index * ROW, behavior: reduced() ? 'auto' : 'smooth' }); }, 120); }
            });
        }
        if (!typed && value(open.input) !== text) {
            set(open.input, open.spec, text);
            /* A list is chosen when the wheel closes, as a menu is; the rest as it turns. */
            if (open.spec.kind !== 'list') { open.input.dispatchEvent(new Event('input', { bubbles: true })); }
        }
    }

    /* ---------------------------------------------------- open and close */

    function place(input, panel) {
        var r = (input._wheelAnchor || input).getBoundingClientRect();
        var h = panel.offsetHeight;
        var w = Math.min(320, Math.max(r.width, 240));
        var below = window.innerHeight - r.bottom;
        var top = below >= h + 12 || r.top < h + 12 ? r.bottom + 8 : r.top - h - 8;
        panel.style.width = w + 'px';
        panel.style.left = Math.max(12, Math.min(window.innerWidth - w - 12, r.left)) + 'px';
        panel.style.top = Math.max(12, Math.min(window.innerHeight - h - 12, top)) + 'px';
        panel.style.transformOrigin = (top > r.top ? 'top' : 'bottom') + ' left';
    }

    function show(input) {
        if (open && open.input === input) { return; }
        close();
        var spec = specOf(input);
        var made = build(input, spec);
        open = { input: input, spec: spec, panel: made.panel, columns: made.columns, before: value(input) };
        document.body.appendChild(made.panel);
        made.columns.forEach(function (col) { col.list.scrollTop = col.index * ROW; });
        place(input, made.panel);
        input.setAttribute('aria-expanded', 'true');
        if (value(input) === '') { write(); }          // the sensible starting value, in the field at once
        made.panel.classList.add('is-open');
        if (coarse.matches || spec.kind === 'date' || spec.kind === 'list') { made.panel._first.focus({ preventScroll: true }); }
    }

    function close() {
        if (!open) { return; }
        var was = open;
        open = null;
        was.input.setAttribute('aria-expanded', 'false');
        if (value(was.input) !== was.before) {
            if (was.spec.kind === 'list') { was.input.dispatchEvent(new Event('input', { bubbles: true })); }
            was.input.dispatchEvent(new Event('change', { bubbles: true }));
        }
        was.panel.classList.remove('is-open');
        was.panel.classList.add('is-closing');
        window.setTimeout(function () { was.panel.remove(); }, reduced() ? 0 : 160);
    }

    /* ------------------------------------------------------- the fields */

    function attach(input, spec) {
        if (spec) { input._wheel = spec; }
        var s = specOf(input);
        if (input._wheelOn) { return input; }
        input._wheelOn = true;
        input.setAttribute('aria-haspopup', 'dialog');
        input.setAttribute('aria-expanded', 'false');
        input.autocomplete = 'off';
        /* A tap opens the wheel, not the keyboard; a date is only ever turned. */
        if (coarse.matches || s.kind === 'date') { input.readOnly = true; }
        if (s.kind === 'date') { set(input, s, input.getAttribute('data-value') || input.value); }

        input.addEventListener('click', function () { show(input); });
        input.addEventListener('keydown', function (e) {
            if ((e.key === 'ArrowDown' && e.altKey) || (input.readOnly && (e.key === 'Enter' || e.key === ' '))) { e.preventDefault(); show(input); }
        });
        /* Typed on a computer: the wheel follows. */
        input.addEventListener('input', function () {
            if (!open || open.input !== input || document.activeElement !== input) { return; }
            open.typed = Date.now();
            var cols = columns(open.spec, value(input));
            cols.forEach(function (c, n) { to(open.columns[n], open.columns[n].values.indexOf(c.value), false); });
        });
        return input;
    }

    /**
     * A <select>: kept as it is, so the page reads and hears it as before, but
     * its native menu never opens — a tap on its glass opens the wheel, one
     * column of its choices, and the choice is put in the select.
     */
    function attachSelect(select) {
        if (select._wheelOn || select.multiple) { return select; }
        select._wheelOn = true;
        var anchor = select.closest('.glass--select, .split--menu') || select;
        select._wheelAnchor = anchor;
        anchor.classList.add('is-wheel');
        select.setAttribute('aria-haspopup', 'dialog');
        anchor.addEventListener('mousedown', function (e) { e.preventDefault(); });
        anchor.addEventListener('click', function (e) {
            e.preventDefault();
            if (!select.disabled) { show(select); }
        });
        select.addEventListener('keydown', function (e) {
            if (['Enter', ' ', 'ArrowDown', 'ArrowUp'].indexOf(e.key) !== -1) { e.preventDefault(); show(select); }
        });
        return select;
    }

    function scan(root) {
        Array.prototype.forEach.call((root || document).querySelectorAll('input[data-wheel]'), function (input) { attach(input); });
        Array.prototype.forEach.call((root || document).querySelectorAll('select'), attachSelect);
    }

    /* A tap outside closes it — and only closes it. */
    document.addEventListener('pointerdown', function (e) {
        if (!open || open.panel.contains(e.target) || e.target === open.input
            || (open.input._wheelAnchor && open.input._wheelAnchor.contains(e.target))) { return; }
        var other = e.target.closest && e.target.closest('input[data-wheel], .is-wheel');
        close();
        if (!other) { swallow = Date.now(); }
    }, true);
    document.addEventListener('click', function (e) {
        if (swallow && Date.now() - swallow < 600) {
            swallow = 0;
            e.preventDefault();
            e.stopPropagation();
        }
    }, true);
    window.addEventListener('resize', function () { if (open) { place(open.input, open.panel); } });
    document.addEventListener('scroll', function (e) {
        if (open && !open.panel.contains(e.target)) { close(); }
    }, true);

    /* Fields drawn later (a card read again, an editor opened) get theirs too. */
    if (window.MutationObserver) {
        new MutationObserver(function (changes) {
            changes.forEach(function (c) {
                Array.prototype.forEach.call(c.addedNodes, function (n) {
                    if (n.nodeType !== 1) { return; }
                    if (n.matches && n.matches('input[data-wheel]')) { attach(n); }
                    if (n.matches && n.matches('select')) { attachSelect(n); }
                    if (n.querySelectorAll) { scan(n); }
                });
            });
        }).observe(document.documentElement, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { scan(); });
    } else {
        scan();
    }

    window.OwnifyWheel = { attach: attach, scan: scan, value: value, forUnit: forUnit, columns: columns, written: written,
        set: function (input, text) { set(input, specOf(input), text); },
        close: close, ROW: ROW };
})();
