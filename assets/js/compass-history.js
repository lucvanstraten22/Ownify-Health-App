/**
 * compass-history.js — reading the Scorekompas's history with a finger, a
 * cursor or keys (components/compass-history.php).
 *
 * The periods are drawn server-side and health-trend.js switches between
 * them, as on Gezondheid. Every day arrives already written in Dutch — its
 * date, its score, its categories and their parts — so nothing here formats
 * a number or a date. This file only finds the day the reader means and
 * shows it: its date and score above the line, as a goal's Verloop does
 * (goal-chart.js), and the whole day in the panel below the chart.
 *
 *   touch   press and slide sideways along the line; a vertical drag still
 *           scrolls the page. The reading above the line stays a moment
 *           after lifting; the panel keeps the day until another is read.
 *   mouse   hover.
 *   keys    arrows step through the days, Home/End jump to either end,
 *           Escape puts the reading away. Announced through a live region.
 *
 * A day without a score is read like any other: its date, no number, and
 * the panel saying so. Switching the period puts the panel back on today.
 */

(function () {
    'use strict';

    var LINGER = 1600;      // ms a touch reading stays after the finger lifts

    Array.prototype.forEach.call(document.querySelectorAll('[data-compass-history]'), setup);

    function setup(card) {
        var source = card.querySelector('[data-compass-days]');
        var panel  = card.querySelector('[data-compass-day]');
        var live   = card.querySelector('[data-compass-live]');
        var days;

        try {
            days = JSON.parse(source ? source.textContent : '[]');
        } catch (error) {
            return;
        }

        if (!days.length || !panel) { return; }

        /* ------------------------------------------------ the panel */

        var dayDate  = panel.querySelector('[data-day-date]');
        var dayScore = panel.querySelector('[data-day-score]');
        var dayValue = panel.querySelector('[data-day-value]');
        var dayNote  = panel.querySelector('[data-day-note]');
        var shown    = days.length - 1;

        function text(value) {
            return value === null || value === undefined ? '—' : String(value);
        }

        function band(element, value) {
            if (value) { element.setAttribute('data-score', value); } else { element.removeAttribute('data-score'); }
        }

        /* textContent only, never innerHTML: these strings came from the
           server, and nothing here needs markup. */
        function fill(index) {
            var day = days[index];
            if (!day || index === shown) { return; }

            shown = index;
            dayDate.textContent  = day.label;
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
            var plot = range.querySelector('[data-compass-plot]');
            var at;

            try {
                at = JSON.parse(range.getAttribute('data-at') || '[]');
            } catch (error) {
                at = [];
            }

            ranges.push(plot && at.length ? reader(plot, at, parseInt(range.getAttribute('data-start'), 10) || 0) : null);
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
            fill(days.length - 1);
        });

        /* ------------------------------------------------ one period's line */

        function reader(plot, at, start) {
            var cross = plot.querySelector('[data-compass-cross]');
            var focus = plot.querySelector('[data-compass-focus]');
            var tip   = plot.querySelector('[data-compass-tip]');

            if (!cross || !focus || !tip) { return null; }

            var tipValue = tip.querySelector('[data-tip-value]');
            var tipDate  = tip.querySelector('[data-tip-date]');

            var current  = -1;
            var pending  = null;
            var frame    = 0;
            var linger   = 0;
            var pressing = false;

            /** The day nearest to a horizontal position, in % of the plot. */
            function nearest(percent) {
                var best = 0;
                var gap  = Infinity;

                for (var i = 0; i < at.length; i++) {
                    var d = Math.abs(at[i][0] - percent);
                    if (d < gap) { gap = d; best = i; }
                }

                return best;
            }

            function show(index, announce) {
                var point = at[index];
                var day   = days[start + index];
                if (!point || !day) { return; }

                window.clearTimeout(linger);

                if (index !== current) {
                    current = index;

                    cross.style.left = point[0] + '%';
                    tipDate.textContent  = day.label;
                    tipValue.textContent = text(day.value);

                    /* No score that day: no dot on a line that is not there. */
                    focus.hidden = point[1] === null;
                    if (point[1] !== null) {
                        focus.style.left = point[0] + '%';
                        focus.style.top  = point[1] + '%';
                    }
                } else {
                    focus.hidden = point[1] === null;
                }

                cross.hidden = false;
                tip.hidden   = false;
                plot.classList.add('is-reading');

                place(point);
                fill(start + index);

                if (announce && live) {
                    live.textContent = day.label + ', ' + text(day.value) + (day.note ? '. ' + day.note : '');
                }
            }

            /** Centred over its day, pushed back inside the plot at either end. */
            function place(point) {
                var width = plot.clientWidth;
                var own   = tip.offsetWidth;
                var x     = point[0] / 100 * width - own / 2;

                tip.style.left = (own >= width ? (width - own) / 2 : Math.max(0, Math.min(width - own, x))) + 'px';
            }

            function hide() {
                window.clearTimeout(linger);
                current = -1;
                cross.hidden = true;
                focus.hidden = true;
                tip.hidden   = true;
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
                var last = at.length - 1;
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
                if (visible && current < 0) { show(at.length - 1, true); }
            });

            plot.addEventListener('blur', function () {
                if (!pressing) { hide(); }
            });

            window.addEventListener('resize', function () {
                if (current >= 0) { place(at[current]); }
            });

            return { hide: hide };
        }
    }
}());
