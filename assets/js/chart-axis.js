/**
 * The dates under a chart over time (components/chart-axis.php,
 * docs/CHARTS.md) stand centred under their own day. A date at either end of
 * the axis that would stick out past it — "15 okt" running into the card's
 * edge — moves in by just its overflow, and no further: every other date
 * stays exactly where the standard puts it. The app does the same
 * (HistoryChart.kt TickAxis, GoalChart.kt XTicks).
 *
 * And no two dates ever touch (C56, 50.0): where they do not all fit, every
 * second one shows, then every third…, evenly spaced, both ends always.
 *
 * The shift is a CSS variable (--edge-shift) the tick's transform adds, so
 * the stylesheet keeps deciding everything else. Each axis is measured when
 * it gets a size — on load, when a period is switched in, when a goal's page
 * is added later, and on a resize.
 */
(function () {
    'use strict';

    var AXES = '.chart__axis--days, .goal-chart__x, [data-heart-day] .chart__axis';
    var seen = typeof WeakSet === 'function' ? new WeakSet() : null;
    var sizes = 'ResizeObserver' in window
        ? new ResizeObserver(function (entries) {
            entries.forEach(function (entry) { settle(entry.target); });
        })
        : null;

    /* The dates that are centred: all of a chart over time's; on a goal's
       chart, the ones not already set at an edge (is-start / is-end). */
    function centred(axis) {
        return axis.classList.contains('chart__axis--days')
            ? axis.querySelectorAll('.chart__tick')
            : axis.querySelectorAll('.chart__tick.is-center');
    }

    function settle(axis) {
        var box = axis.getBoundingClientRect();
        if (!box.width) { return; }
        // Measured on the screen, set in the axis's own pixels (the same
        // unless the axis is drawn scaled).
        var k = axis.offsetWidth ? box.width / axis.offsetWidth : 1;

        Array.prototype.forEach.call(centred(axis), function (tick) {
            tick.style.removeProperty('--edge-shift');
            var at = tick.getBoundingClientRect();
            var shift = 0;
            if (at.left < box.left) {
                shift = box.left - at.left;
            } else if (at.right > box.right) {
                shift = box.right - at.right;
            }
            if (shift) { tick.style.setProperty('--edge-shift', Math.round(shift / (k || 1)) + 'px'); }
        });

        /* C56/C57 (50.0, N5): dates never collide, and stay evenly spaced.
           Every date where they all fit; else every second, every third…
           (both ends always stand: the one before the end steps aside
           when only it is too close). 4 px is the least gap. */
        var ticks = Array.prototype.slice.call(axis.querySelectorAll('.chart__tick'));
        ticks.forEach(function (tick) { tick.style.removeProperty('visibility'); });
        if (ticks.length < 3) { return; }
        var GAP = 4 * (k || 1);
        var rects = ticks.map(function (tick) { return tick.getBoundingClientRect(); });
        var last = ticks.length - 1;
        /* A day's hours have no end to keep (21:00 is not the end of the
           day): there every n-th time, evenly, from 00:00. */
        var free = !!axis.closest('[data-heart-day]');
        var keep = null;
        for (var stride = 1; stride <= last && keep === null; stride++) {
            var shown = [];
            for (var n = 0; n < last; n += stride) { shown.push(n); }
            if (free) {
                if (last % stride === 0) { shown.push(last); }
                var even = true;
                for (var q = 1; q < shown.length; q++) { if (rects[shown[q]].left < rects[shown[q - 1]].right + 4 * (k || 1)) { even = false; break; } }
                if (even) { keep = shown; }
                continue;
            }
            shown.push(last);
            var clear = function (a, b) { return rects[b].left >= rects[a].right + GAP; };
            var ok = true;
            for (var m = 1; m < shown.length - 1; m++) { if (!clear(shown[m - 1], shown[m])) { ok = false; break; } }
            if (ok && !clear(shown[shown.length - 2], last)) {
                if (shown.length > 2 && clear(shown[shown.length - 3], last)) { shown.splice(shown.length - 2, 1); } else { ok = false; }
            }
            if (ok) { keep = shown; }
        }
        keep = keep || [0, last];
        ticks.forEach(function (tick, n) { if (keep.indexOf(n) === -1) { tick.style.visibility = 'hidden'; } });
    }

    function scan() {
        Array.prototype.forEach.call(document.querySelectorAll(AXES), function (axis) {
            if (seen) {
                if (seen.has(axis)) { return; }
                seen.add(axis);
            }
            if (sizes) { sizes.observe(axis); } else { settle(axis); }
        });
    }

    scan();

    /* A goal's page added after load brings its own chart. */
    if ('MutationObserver' in window) {
        var queued = false;
        new MutationObserver(function () {
            if (queued) { return; }
            queued = true;
            window.requestAnimationFrame(function () {
                queued = false;
                scan();
            });
        }).observe(document.body, { childList: true, subtree: true });
    }

    if (!sizes) {
        window.addEventListener('resize', function () {
            Array.prototype.forEach.call(document.querySelectorAll(AXES), settle);
        });
    }
})();
