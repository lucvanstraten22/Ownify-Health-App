/**
 * training.js — Training's heart rate (components/heart-chart.php,
 * docs/TRAINING.md): under Vandaag, the day shown, and the six before it.
 *
 *   the arrows   ‹ an older day, › a newer one; neither past the ends
 *   a swipe      over the day's name, or a quick one over the chart: to the
 *                right an older day, to the left a newer one. A slow slide
 *                over the chart is still a reading (compass-history.js).
 *
 * The switch keeps Vandaag chosen whichever day is shown; choosing it again
 * goes back to today (health-trend.js). Each day is drawn server-side; this
 * only shows another one, says which, and puts away a reading left on the
 * day it leaves.
 */

(function () {
    'use strict';

    var SWIPE = 48;         // px sideways a swipe travels
    var FLICK = 350;        // ms a swipe over the chart takes at most, or it was a reading

    Array.prototype.forEach.call(document.querySelectorAll('[data-heart-chart]'), chart);

    function chart(card) {
        var days  = Array.prototype.slice.call(card.querySelectorAll('[data-heart-day]'));
        var today = card.querySelector('[data-range-option="d0"]');
        var live  = card.querySelector('[data-reading-live]');
        if (!days.length || !today) { return; }

        function shown() {
            for (var i = 0; i < days.length; i++) {
                if (days[i].classList.contains('is-active')) { return i; }
            }
            return -1;
        }

        function quiet(range) {
            Array.prototype.forEach.call(range.querySelectorAll('[data-reading-tip], [data-reading-cross], [data-reading-focus]'), function (el) {
                el.hidden = true;
            });
            Array.prototype.forEach.call(range.querySelectorAll('.is-reading'), function (el) {
                el.classList.remove('is-reading');
            });
        }

        function go(index, focusStep) {
            var from = shown();
            if (from < 0 || index < 0 || index >= days.length || index === from) { return; }

            quiet(days[from]);
            Array.prototype.forEach.call(card.querySelectorAll('.chart__range[data-range]'), function (range) {
                range.classList.toggle('is-active', range === days[index]);
            });
            Array.prototype.forEach.call(card.querySelectorAll('[data-range-option]'), function (option) {
                var on = option === today;
                option.classList.toggle('is-active', on);
                option.setAttribute('aria-pressed', on ? 'true' : 'false');
            });

            var title = days[index].querySelector('.heart-chart__date');
            if (live && title) { live.textContent = title.textContent; }

            /* A step by an arrow keeps the focus on the arrow that is there. */
            if (focusStep) {
                var same = days[index].querySelector('[data-day-step="' + focusStep + '"]');
                var other = days[index].querySelector('[data-day-step]:not([disabled])');
                (same && !same.disabled ? same : other || title).focus({ preventScroll: true });
            }
        }

        card.addEventListener('click', function (event) {
            var step = event.target.closest('[data-day-step]');
            if (!step || step.disabled) { return; }
            go(shown() + Number(step.getAttribute('data-day-step')), step.getAttribute('data-day-step'));
        });

        days.forEach(function (range) {
            var start = null;

            range.addEventListener('pointerdown', function (event) {
                if (event.pointerType === 'mouse') { start = null; return; }
                start = { x: event.clientX, y: event.clientY, t: Date.now(), header: !!event.target.closest('[data-day-swipe]') };
            });

            range.addEventListener('pointerup', function (event) {
                if (!start) { return; }
                var dx = event.clientX - start.x;
                var dy = event.clientY - start.y;
                var quick = Date.now() - start.t <= FLICK;
                var sideways = Math.abs(dx) >= SWIPE && Math.abs(dx) > 1.5 * Math.abs(dy);
                var from = start;
                start = null;

                if (!sideways || (!from.header && !quick)) { return; }
                go(shown() + (dx > 0 ? 1 : -1));
            });

            range.addEventListener('pointercancel', function () { start = null; });
        });
    }
}());
