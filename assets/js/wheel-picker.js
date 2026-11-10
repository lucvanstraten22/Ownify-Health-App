/*
 * The wheel (47.0, docs/THEME.md "The wheel"): a number or a duration
 * picked as a phone picks one — a tap on the field opens columns that turn
 * under the finger, each part (hours and minutes, the whole and its
 * decimal) its own column, the value in the field as it turns. A clock time
 * is the browser's own <input type="time"> (a wheel on an iPhone, the
 * system's dialog on Android); this is for what the web has no native
 * picker for. Typen puts the keyboard back for an exact value; on a
 * computer the field can be typed in all along.
 *
 * On a field: data-wheel='{"kind":"duration","default":30}' (minutes, an
 * hours and a minutes column) or '{"kind":"number","min":0,"max":300,
 * "step":1,"decimals":1,"default":70,"unit":"kg"}'. From a script:
 * OwnifyWheel.attach(input, spec). The field keeps its own value as typed
 * ("72,5"; a duration in whole minutes), and gets input and change events
 * as the wheel turns. The Android app has the same, natively
 * (ui/design/Wheel.kt: the system's NumberPicker).
 */
(function () {
    'use strict';

    var I18n = window.OwnifyI18n || {
        t: function (s) { return s; },
        number: function (v, d) { return Number(v).toFixed(d || 0); },
        parseNumber: function (s) { var n = Number(String(s).replace(',', '.')); return isFinite(n) && String(s).trim() !== '' ? n : null; }
    };
    var ROW = 36;            // px a row is high: --wheel-row
    var SHOWN = 5;           // rows in sight, the middle one picked
    var coarse = window.matchMedia ? window.matchMedia('(pointer: coarse)') : { matches: false };
    var open = null;         // { input, spec, panel, columns }

    function specOf(input) {
        if (input._wheel) { return input._wheel; }
        try { return JSON.parse(input.getAttribute('data-wheel') || '{}'); } catch (e) { return {}; }
    }

    /* ------------------------------------------------------ the columns */

    function range(from, to, step) {
        var out = [];
        for (var v = from; v <= to + 1e-9; v += step) { out.push(Math.round(v * 1000) / 1000); }
        return out;
    }

    /** The columns for a spec, each {values, labels, unit, value}. */
    function columns(spec, current) {
        if (spec.kind === 'duration') {
            var max = spec.max || 1439;
            var total = current === null ? (spec['default'] !== undefined ? spec['default'] : 30) : Math.round(current);
            total = Math.max(spec.min || 0, Math.min(max, total));
            var step = spec.step || 1;
            return [
                { values: range(0, Math.floor(max / 60), 1), unit: I18n.t('u'), value: Math.floor(total / 60) },
                { values: range(0, 60 - step, step), unit: I18n.t('min'), value: Math.round((total % 60) / step) * step, pad: true }
            ];
        }

        var d = spec.decimals || 0;
        var min = spec.min !== undefined ? spec.min : 0;
        var top = spec.max !== undefined ? spec.max : 1000;
        var v = current === null ? (spec['default'] !== undefined ? spec['default'] : min) : current;
        v = Math.max(min, Math.min(top, v));
        var whole = spec.step && spec.step >= 1 ? spec.step : 1;
        while ((top - min) / whole > 1000) { whole *= 10; d = 0; }      // a long range turns in bigger steps; Typen for the exact one
        var cols = [{ values: range(Math.floor(min / whole) * whole, top, whole), value: Math.round(Math.floor(v) / whole) * whole, unit: d === 0 ? (spec.unit || '') : '' }];
        if (d > 0) {
            /* One decimal column: tenths (or hundredths, in steps of 5). */
            var tenth = d === 1 ? 1 : 5;
            var scale = Math.pow(10, d);
            cols.push({
                values: range(0, scale - tenth, tenth),
                value: Math.round(((v - Math.floor(v)) * scale) / tenth) * tenth,
                decimal: d, unit: spec.unit || ''
            });
        }

        return cols;
    }

    function label(col, value) {
        if (col.decimal) { return (I18n.number(1.5, 1).charAt(1)) + String(value).padStart(col.decimal, '0'); }
        if (col.pad) { return String(value).padStart(2, '0'); }
        return I18n.number(value, 0);
    }

    /** The value the columns stand on now. */
    function valueOf(spec, cols) {
        if (spec.kind === 'duration') {
            return Math.max(spec.min || 0, Math.min(spec.max || 1439, cols[0].value * 60 + cols[1].value));
        }
        var v = cols[0].value + (cols[1] ? cols[1].value / Math.pow(10, cols[1].decimal) : 0);
        return Math.max(spec.min !== undefined ? spec.min : -Infinity, Math.min(spec.max !== undefined ? spec.max : Infinity, v));
    }

    /* As the field takes it: a number field only "72.5", any other as the person writes it. */
    function written(spec, value, input) {
        if (spec.kind === 'duration') { return String(value); }
        return input && input.type === 'number' ? Number(value).toFixed(spec.decimals || 0) : I18n.number(value, spec.decimals || 0, false);
    }

    function current(input, spec) {
        var v = I18n.parseNumber(input.value);
        return v === null ? null : v;
    }

    /* -------------------------------------------------------- the panel */

    function build(input, spec) {
        var panel = document.createElement('div');
        panel.className = 'wheel glass';
        panel.setAttribute('role', 'dialog');
        var name = input.getAttribute('aria-label') || (input.labels && input.labels[0] ? input.labels[0].textContent.trim() : '');
        panel.setAttribute('aria-label', name);

        var cols = columns(spec, current(input, spec));
        var row = document.createElement('div');
        row.className = 'wheel__columns';

        cols.forEach(function (col, n) {
            var wrap = document.createElement('div');
            wrap.className = 'wheel__column';

            var list = document.createElement('div');
            list.className = 'wheel__list';
            list.tabIndex = 0;
            list.setAttribute('role', 'spinbutton');
            list.setAttribute('aria-label', name + (col.unit ? ' (' + col.unit + ')' : ''));
            col.values.forEach(function (v) {
                var item = document.createElement('div');
                item.className = 'wheel__item';
                item.textContent = label(col, v);
                list.appendChild(item);
            });
            wrap.appendChild(list);

            if (col.unit) {
                var unit = document.createElement('span');
                unit.className = 'wheel__unit';
                unit.textContent = col.unit;
                wrap.appendChild(unit);
            }
            row.appendChild(wrap);

            col.list = list;
            col.index = Math.max(0, col.values.indexOf(col.value));
            list.addEventListener('scroll', function () { turned(col); }, { passive: true });
            list.addEventListener('keydown', function (e) {
                var by = e.key === 'ArrowUp' ? -1 : e.key === 'ArrowDown' ? 1 : e.key === 'PageUp' ? -10 : e.key === 'PageDown' ? 10 : 0;
                if (by) { e.preventDefault(); to(col, col.index + by, true); }
            });
            list.addEventListener('click', function (e) {
                var item = e.target.closest('.wheel__item');
                if (item) { to(col, Array.prototype.indexOf.call(list.children, item), true); }
            });
            if (n === 0) { panel._first = list; }
        });

        var band = document.createElement('div');
        band.className = 'wheel__band';
        band.setAttribute('aria-hidden', 'true');
        row.appendChild(band);
        panel.appendChild(row);

        var foot = document.createElement('div');
        foot.className = 'wheel__foot';
        var type = document.createElement('button');
        type.type = 'button';
        type.className = 'glass glass--chip press';
        type.textContent = I18n.t('Typen');
        type.addEventListener('click', function () { keyboard(input); });
        var done = document.createElement('button');
        done.type = 'button';
        done.className = 'glass glass--chip glass--cta press';
        done.textContent = I18n.t('Klaar');
        done.addEventListener('click', function () { close(true); });
        foot.appendChild(type);
        foot.appendChild(done);
        panel.appendChild(foot);

        panel.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { e.preventDefault(); close(true); }
            if (e.key === 'Enter') { e.preventDefault(); close(true); }
        });

        return { panel: panel, columns: cols };
    }

    /** A column turned by the finger: the row in the middle is its value. */
    function turned(col) {
        var i = Math.max(0, Math.min(col.values.length - 1, Math.round(col.list.scrollTop / ROW)));
        if (i === col.index) { return; }
        col.index = i;
        col.value = col.values[i];
        write();
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
            col.list.setAttribute('aria-valuetext', label(col, col.value) + (col.unit ? ' ' + col.unit : ''));
        });
        var text = written(open.spec, valueOf(open.spec, open.columns), open.input);
        if (!typed && open.input.value !== text) {
            open.input.value = text;
            open.input.dispatchEvent(new Event('input', { bubbles: true }));
        }
    }

    /* ---------------------------------------------------- open and close */

    function place(input, panel) {
        var r = input.getBoundingClientRect();
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
        close(true);
        var spec = specOf(input);
        var made = build(input, spec);
        open = { input: input, spec: spec, panel: made.panel, columns: made.columns, before: input.value };
        document.body.appendChild(made.panel);
        made.columns.forEach(function (col) { col.list.scrollTop = col.index * ROW; });
        place(input, made.panel);
        input.setAttribute('aria-expanded', 'true');
        if (input.value === '') { write(); }           // the sensible starting value, in the field at once
        made.panel.classList.add('is-open');
        if (coarse.matches) { made.panel._first.focus({ preventScroll: true }); }
    }

    function close(keep) {
        if (!open) { return; }
        var was = open;
        open = null;
        was.input.setAttribute('aria-expanded', 'false');
        if (!keep) { was.input.value = was.before; }
        if (was.input.value !== was.before) { was.input.dispatchEvent(new Event('change', { bubbles: true })); }
        was.panel.classList.remove('is-open');
        was.panel.classList.add('is-closing');
        window.setTimeout(function () { was.panel.remove(); }, reduced() ? 0 : 160);
    }

    /** Typen: the keyboard back, for an exact value. */
    function keyboard(input) {
        close(true);
        input.readOnly = false;
        input._typing = true;
        input.focus();
        try { input.select(); } catch (e) { /* not every field selects */ }
    }

    /* ------------------------------------------------------- the fields */

    function attach(input, spec) {
        if (spec) { input._wheel = spec; }
        if (input._wheelOn) { return input; }
        input._wheelOn = true;
        input.setAttribute('aria-haspopup', 'dialog');
        input.setAttribute('aria-expanded', 'false');
        if (coarse.matches) { input.readOnly = true; }     // a tap opens the wheel, not the keyboard

        input.addEventListener('click', function () {
            if (input._typing && coarse.matches) { return; }
            show(input);
        });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown' && e.altKey) { e.preventDefault(); show(input); }
        });
        /* Typed on a computer: the wheel follows. */
        input.addEventListener('input', function () {
            if (!open || open.input !== input || document.activeElement !== input) { return; }
            open.typed = Date.now();
            var cols = columns(open.spec, current(input, open.spec));
            cols.forEach(function (c, n) { to(open.columns[n], open.columns[n].values.indexOf(c.value), false); });
        });
        input.addEventListener('blur', function () {
            if (input._typing) {
                input._typing = false;
                if (coarse.matches) { input.readOnly = true; }
            }
        });
        return input;
    }

    function scan(root) {
        Array.prototype.forEach.call((root || document).querySelectorAll('input[data-wheel]'), function (input) { attach(input); });
    }

    document.addEventListener('pointerdown', function (e) {
        if (open && !open.panel.contains(e.target) && e.target !== open.input) { close(true); }
    }, true);
    window.addEventListener('resize', function () { if (open) { place(open.input, open.panel); } });
    document.addEventListener('scroll', function (e) {
        if (open && !open.panel.contains(e.target)) { close(true); }
    }, true);

    /* Fields drawn later (a card read again, an editor opened) get theirs too. */
    if (window.MutationObserver) {
        new MutationObserver(function (changes) {
            changes.forEach(function (c) {
                Array.prototype.forEach.call(c.addedNodes, function (n) {
                    if (n.nodeType !== 1) { return; }
                    if (n.matches && n.matches('input[data-wheel]')) { attach(n); }
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

    window.OwnifyWheel = { attach: attach, scan: scan, close: function () { close(true); }, ROW: ROW, SHOWN: SHOWN };
})();
