/**
 * compass-history.js — reading a score history with a finger, a cursor or
 * keys: the Scorekompas's line (components/compass-history.php) and
 * Gezondheid's three lines (components/health-history.php), read the same
 * way.
 *
 * The periods are drawn server-side and health-trend.js switches between
 * them. Every point — a day, or a week or month as one — arrives already
 * written in Dutch with its period: its date or days, its scores, its
 * categories and their parts — so nothing here formats a number or a date.
 * This file only finds the point the reader means and shows it: its date
 * and score(s) above the chart, as a goal's Verloop does (goal-chart.js),
 * and on the Scorekompas the whole point in the panel below.
 *
 *   touch   press and slide sideways along the chart; a vertical drag still
 *           scrolls the page. The reading above the chart stays a moment
 *           after lifting; the Scorekompas's panel keeps the day until
 *           another is read.
 *   mouse   hover.
 *   keys    arrows step through the days, Home/End jump to either end,
 *           Escape puts the reading away. Announced through a live region.
 *
 * A day without a score is read like any other: its date, and no number.
 * Switching the period puts the reading away (and the Scorekompas's panel
 * back on today).
 */

(function () {
    'use strict';

    var LINGER = 1600;      // ms a touch reading stays after the finger lifts

    Array.prototype.forEach.call(document.querySelectorAll('[data-compass-history]'), compass);
    Array.prototype.forEach.call(document.querySelectorAll('[data-health-history]'), health);

    function text(value) {
        return value === null || value === undefined ? '—' : String(value);
    }

    function band(element, value) {
        if (value) { element.setAttribute('data-score', value); } else { element.removeAttribute('data-score'); }
    }

    function daysIn(card, selector) {
        var source = card.querySelector(selector);

        try {
            return JSON.parse(source ? source.textContent : '[]');
        } catch (error) {
            return [];
        }
    }

    /* ======================================== the Scorekompas: one score */

    function compass(card) {
        var panel  = card.querySelector('[data-compass-day]');
        var live   = card.querySelector('[data-compass-live]');
        var latest = card.querySelector('[data-compass-latest]');

        try {
            latest = latest ? JSON.parse(latest.textContent) : null;
        } catch (error) {
            latest = null;
        }

        if (!latest || !panel) { return; }

        /* ------------------------------------------------ the panel */

        var dayDate   = panel.querySelector('[data-day-date]');
        var dayDetail = panel.querySelector('[data-day-detail]');
        var dayScore  = panel.querySelector('[data-day-score]');
        var dayValue  = panel.querySelector('[data-day-value]');
        var dayNote   = panel.querySelector('[data-day-note]');
        var shown     = latest;

        /* A day, or a week or month as one: its date or days, that its scores
           are their mean, its score and each category's. textContent only,
           never innerHTML: these strings came from the server, and nothing
           here needs markup. */
        function fill(day) {
            if (!day || day === shown) { return; }

            shown = day;
            dayDate.textContent  = day.label;
            if (dayDetail) {
                dayDetail.textContent = day.detail || '';
                dayDetail.hidden      = !day.detail;
            }
            dayValue.textContent = text(day.value);
            band(dayScore, day.band);
            dayNote.textContent = day.note || '';
            dayNote.hidden      = !day.note;

            (day.categories || []).forEach(function (category) {
                var row = panel.querySelector('[data-day-cat="' + category.id + '"]');
                if (!row) { return; }

                var parts = row.querySelector('[data-cat-parts]');
                row.querySelector('[data-cat-value]').textContent = text(category.value);
                band(row.querySelector('[data-cat-score]'), category.band);
                parts.textContent = category.parts || '';
                parts.hidden      = !category.parts;
                row.classList.toggle('is-filled', category.value !== null);
                row.classList.toggle('is-empty', category.value === null);
            });
        }

        /* ------------------------------------------------ the periods */

        var ranges = [];

        Array.prototype.forEach.call(card.querySelectorAll('.chart__range[data-range]'), function (range) {
            var plot   = range.querySelector('[data-compass-plot]');
            var points = daysIn(range, '[data-compass-points]');
            var at;

            try {
                at = JSON.parse(range.getAttribute('data-at') || '[]');
            } catch (error) {
                at = [];
            }

            ranges.push(plot && at.length && points.length ? line(plot, at, points) : null);
        });

        /* After health-trend.js has switched the chart: the chip of the
           period now shown, today in the panel, and any reading put away. */
        card.addEventListener('click', function (event) {
            var option = event.target.closest('[data-range-option]');
            if (!option) { return; }

            var key = option.getAttribute('data-range-option');
            Array.prototype.forEach.call(card.querySelectorAll('[data-range-chip]'), function (chip) {
                chip.hidden = chip.getAttribute('data-range-chip') !== key;
            });

            ranges.forEach(function (r) { if (r) { r.hide(); } });
            fill(latest);
        });

        /* One period's line: its date or days and score above it, a dot on it. */
        function line(plot, at, points) {
            var cross = plot.querySelector('[data-compass-cross]');
            var focus = plot.querySelector('[data-compass-focus]');
            var tip   = plot.querySelector('[data-compass-tip]');

            if (!cross || !focus || !tip) { return null; }

            var tipValue  = tip.querySelector('[data-tip-value]');
            var tipDate   = tip.querySelector('[data-tip-date]');
            var tipDetail = tip.querySelector('[data-tip-detail]');

            return read(plot, at.map(function (point) { return point[0]; }), cross, tip, {
                has: function (index) { return !!points[index]; },

                show: function (index, changed, announce) {
                    var point = at[index];
                    var day   = points[index];

                    if (changed) {
                        tipDate.textContent  = day.label;
                        tipValue.textContent = text(day.value);
                        if (tipDetail) {
                            tipDetail.textContent = day.detail || '';
                            tipDetail.hidden      = !day.detail;
                        }
                    }

                    /* No score that day: no dot on a line that is not there. */
                    focus.hidden = point[1] === null;
                    if (changed && point[1] !== null) {
                        focus.style.left = point[0] + '%';
                        focus.style.top  = point[1] + '%';
                    }

                    fill(day);

                    if (announce && live) {
                        live.textContent = day.label + (day.detail ? ', ' + day.detail : '') + ', ' + text(day.value)
                            + (day.note ? '. ' + day.note : '');
                    }
                },

                hide: function () { focus.hidden = true; }
            });
        }
    }

    /* ============================ Gezondheid: Slaap, Voeding, Training */

    function health(card) {
        var live   = card.querySelector('[data-reading-live]');
        var ranges = [];

        Array.prototype.forEach.call(card.querySelectorAll('.chart__range[data-range]'), function (range) {
            var plot   = range.querySelector('[data-history-plot]');
            var points = daysIn(range, '[data-history-points]');
            var xs;
            var ys;

            try {
                xs = JSON.parse(range.getAttribute('data-x') || '[]');
                ys = JSON.parse(range.getAttribute('data-y') || '{}');
            } catch (error) {
                xs = [];
                ys = {};
            }

            ranges.push(plot && xs.length && points.length ? lines(plot, xs, ys, points) : null);
        });

        /* After health-trend.js has switched the chart: any reading put away. */
        card.addEventListener('click', function (event) {
            if (!event.target.closest('[data-range-option]')) { return; }
            ranges.forEach(function (r) { if (r) { r.hide(); } });
        });

        /* One period's lines: the point's date — a day's, or a week's or
           month's days and that its scores are their mean — and each
           category's score above them, a dot on each line that had one. */
        function lines(plot, xs, ys, points) {
            var cross = plot.querySelector('[data-reading-cross]');
            var tip   = plot.querySelector('[data-reading-tip]');

            if (!cross || !tip) { return null; }

            var tipDate   = tip.querySelector('[data-tip-date]');
            var tipDetail = tip.querySelector('[data-tip-detail]');
            var none      = tip.querySelector('[data-tip-none]');
            var rows      = Array.prototype.map.call(tip.querySelectorAll('[data-tip-row]'), function (row) {
                var id = row.getAttribute('data-tip-row');

                return {
                    id:    id,
                    row:   row,
                    label: row.querySelector('.health-history__tip-label').textContent,
                    value: row.querySelector('[data-tip-value]'),
                    focus: plot.querySelector('[data-reading-focus="' + id + '"]')
                };
            });

            return read(plot, xs, cross, tip, {
                has: function (index) { return !!points[index]; },

                /* A point is [its date or days, what its scores are, its
                   note, each category's score in the order of the rows]. */
                show: function (index, changed, announce) {
                    var point  = points[index];
                    var detail = point[1];
                    var note   = point[2];
                    var said   = [];

                    rows.forEach(function (r, k) {
                        var value = point[3 + k];
                        var y     = (ys[r.id] || [])[index];
                        var has   = value !== null && value !== undefined;

                        if (changed) {
                            r.row.hidden = !has;
                            r.value.textContent = has ? String(value) : '';
                        }

                        if (r.focus) {
                            r.focus.hidden = !has || y === null || y === undefined;
                            if (changed && !r.focus.hidden) {
                                r.focus.style.left = xs[index] + '%';
                                r.focus.style.top  = y + '%';
                            }
                        }

                        if (has) { said.push(r.label + ' ' + value); }
                    });

                    /* Only the scores it had; none at all, and it says so. */
                    if (changed) {
                        none.textContent = said.length ? '' : (note || '');
                        none.hidden      = said.length > 0 || !note;
                        tipDate.textContent = point[0];
                        if (tipDetail) {
                            tipDetail.textContent = detail || '';
                            tipDetail.hidden      = !detail;
                        }
                    }

                    if (announce && live) {
                        live.textContent = point[0] + (detail ? ', ' + detail : '') + ': '
                            + (said.length ? said.join(', ') : (note || text(null)))
                            + (said.length && note ? '. ' + note : '');
                    }
                },

                hide: function () {
                    rows.forEach(function (r) { if (r.focus) { r.focus.hidden = true; } });
                }
            });
        }
    }

    /* ==================================== reading one period's chart ---

       The day under a finger, a cursor or the keys. `xs` is each day's
       place, in % from the left of the plot. `view` says what a day looks
       like: has(index) whether there is one, show(index, changed, announce)
       puts its words and dots in place (changed: another day than the one
       shown), hide() takes its dots away. The crosshair, the tip — above
       the chart, centred over the day, pushed back inside at either end —
       and every gesture are the same for each chart, and handled here.
       ---------------------------------------------------------------------- */

    function read(plot, xs, cross, tip, view) {
        var current  = -1;
        var pending  = null;
        var frame    = 0;
        var linger   = 0;
        var pressing = false;

        /** The day nearest to a horizontal position, in % of the plot. */
        function nearest(percent) {
            var best = 0;
            var gap  = Infinity;

            for (var i = 0; i < xs.length; i++) {
                var d = Math.abs(xs[i] - percent);
                if (d < gap) { gap = d; best = i; }
            }

            return best;
        }

        function show(index, announce) {
            if (xs[index] === undefined || !view.has(index)) { return; }

            window.clearTimeout(linger);

            var changed = index !== current;
            if (changed) {
                current = index;
                cross.style.left = xs[index] + '%';
            }

            view.show(index, changed, announce);

            cross.hidden = false;
            tip.hidden   = false;
            plot.classList.add('is-reading');

            place(xs[index]);
        }

        /** Centred over its day, pushed back inside the plot at either end. */
        function place(x) {
            var width = plot.clientWidth;
            var own   = tip.offsetWidth;
            var left  = x / 100 * width - own / 2;

            tip.style.left = (own >= width ? (width - own) / 2 : Math.max(0, Math.min(width - own, left))) + 'px';
        }

        function hide() {
            window.clearTimeout(linger);
            current = -1;
            cross.hidden = true;
            tip.hidden   = true;
            view.hide();
            plot.classList.remove('is-reading');
        }

        function percentOf(event) {
            var box = plot.getBoundingClientRect();
            return box.width ? (event.clientX - box.left) / box.width * 100 : 0;
        }

        /* One update per frame, however fast the pointer reports. */
        function follow(percent) {
            pending = percent;
            if (frame) { return; }

            frame = window.requestAnimationFrame(function () {
                frame = 0;
                if (pending !== null) { show(nearest(pending), false); }
                pending = null;
            });
        }

        plot.addEventListener('pointerdown', function (event) {
            if (event.pointerType === 'mouse' && event.button !== 0) { return; }

            pressing = event.pointerType !== 'mouse';
            show(nearest(percentOf(event)), false);

            if (pressing && plot.setPointerCapture) {
                try { plot.setPointerCapture(event.pointerId); } catch (error) { /* not critical */ }
            }
        });

        plot.addEventListener('pointermove', function (event) {
            if (event.pointerType === 'mouse' || pressing) { follow(percentOf(event)); }
        });

        plot.addEventListener('pointerup', function (event) {
            if (event.pointerType === 'mouse') { return; }

            pressing = false;
            window.clearTimeout(linger);
            linger = window.setTimeout(hide, LINGER);
        });

        /* The browser took the gesture for a scroll: get out of the way. */
        plot.addEventListener('pointercancel', function () {
            pressing = false;
            hide();
        });

        plot.addEventListener('pointerleave', function (event) {
            if (event.pointerType === 'mouse') { hide(); }
        });

        plot.addEventListener('keydown', function (event) {
            var last = xs.length - 1;
            var next;

            switch (event.key) {
                case 'ArrowRight': next = current < 0 ? last : Math.min(last, current + 1); break;
                case 'ArrowLeft':  next = current < 0 ? last : Math.max(0, current - 1);    break;
                case 'Home':       next = 0;    break;
                case 'End':        next = last; break;
                case 'Escape':     hide(); return;
                default:           return;
            }

            event.preventDefault();
            show(next, true);
        });

        /* Arriving by Tab shows today straight away; a tap shows the day it touched. */
        plot.addEventListener('focus', function () {
            var visible = true;
            try { visible = plot.matches(':focus-visible'); } catch (error) { /* older engines */ }
            if (visible && current < 0) { show(xs.length - 1, true); }
        });

        plot.addEventListener('blur', function () {
            if (!pressing) { hide(); }
        });

        window.addEventListener('resize', function () {
            if (current >= 0) { place(xs[current]); }
        });

        return { hide: hide };
    }
}());
