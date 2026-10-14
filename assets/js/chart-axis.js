/**
 * The dates under a chart over time (components/chart-axis.php,
 * docs/CHARTS.md) stand centred under their own day. A date at either end of
 * the axis that would stick out past it — "15 okt" running into the card's
 * edge — moves in by just its overflow, and no further: every other date
 * stays exactly where the standard puts it. The app does the same
 * (HistoryChart.kt TickAxis, GoalChart.kt XTicks).
 *
 * The shift is a CSS variable (--edge-shift) the tick's transform adds, so
 * the stylesheet keeps deciding everything else. Each axis is measured when
 * it gets a size — on load, when a period is switched in, when a goal's page
 * is added later, and on a resize.
 */
(function () {
    'use strict';

    var AXES = '.chart__axis--days, .goal-chart__x';
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

        Array.prototype.forEach.call(centred(axis), function (tick) {
            tick.style.removeProperty('--edge-shift');
            var at = tick.getBoundingClientRect();
            var shift = 0;
            if (at.left < box.left) {
                shift = box.left - at.left;
            } else if (at.right > box.right) {
                shift = box.right - at.right;
            }
            if (shift) { tick.style.setProperty('--edge-shift', Math.round(shift) + 'px'); }
        });
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
