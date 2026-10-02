/**
 * health-trend.js — the week / month switch and the line draw-on.
 *
 * Both ranges are already in the document, drawn server-side, so switching is
 * a class toggle. The only work here is animating the stroke: each line is
 * dashed by its own length and the offset is released, which draws it left to
 * right without touching layout.
 */

(function () {
    'use strict';

    var charts = document.querySelectorAll('[data-chart]');
    if (!charts.length) { return; }

    var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function activeRange(chart) {
        return chart.querySelector('.chart__range.is-active');
    }

    /**
     * The line's length as it is drawn. Its stroke does not scale with the
     * stretched chart (vector-effect: non-scaling-stroke), so its dashes are
     * screen pixels while getTotalLength() is the 300 × 120 box's units — a
     * dash that long ended the line about a tenth short of its last point.
     */
    function drawnLength(line) {
        var total = line.getTotalLength();
        var matrix = line.getScreenCTM && line.getScreenCTM();
        if (!matrix || !total) { return total; }

        var steps = 64;
        var length = 0;
        var previous = null;

        for (var i = 0; i <= steps; i++) {
            var point = line.getPointAtLength(total * i / steps).matrixTransform(matrix);
            if (previous) { length += Math.sqrt(Math.pow(point.x - previous.x, 2) + Math.pow(point.y - previous.y, 2)); }
            previous = point;
        }

        // A dash longer than the line is harmless; a shorter one is the bug.
        return Math.ceil(length * 1.02) + 1;
    }

    /** Dashes each line by its own length, then releases it. */
    function draw(range) {
        if (!range) { return; }

        var lines = range.querySelectorAll('[data-draw]');
        if (!lines.length) { return; }

        Array.prototype.forEach.call(lines, function (line) {
            var length = 0;
            try { length = drawnLength(line); } catch (e) { return; }
            if (!length) { return; }

            line.style.setProperty('--length', length);
            line.classList.remove('is-drawn');
            line.classList.add('is-drawing');

            if (reduceMotion) {
                line.classList.remove('is-drawing');
                line.classList.add('is-drawn');
                return;
            }

            // One frame between the two states, or there is nothing to ease.
            window.requestAnimationFrame(function () {
                window.requestAnimationFrame(function () {
                    line.classList.remove('is-drawing');
                    line.classList.add('is-drawn');
                });
            });
        });
    }

    function select(chart, rangeKey) {
        Array.prototype.forEach.call(chart.querySelectorAll('[data-range]'), function (range) {
            range.classList.toggle('is-active', range.dataset.range === rangeKey);
        });

        var card = chart.closest('.card');
        if (card) {
            Array.prototype.forEach.call(card.querySelectorAll('[data-range-option]'), function (option) {
                var isActive = option.getAttribute('data-range-option') === rangeKey;
                option.classList.toggle('is-active', isActive);
                option.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            });
        }

        draw(activeRange(chart));
    }

    Array.prototype.forEach.call(charts, function (chart) {
        var card = chart.closest('.card');
        if (card) {
            card.addEventListener('click', function (event) {
                var option = event.target.closest('[data-range-option]');
                if (!option) { return; }
                select(chart, option.getAttribute('data-range-option'));
            });
        }

        // Draw the first time the chart is actually looked at.
        if (!('IntersectionObserver' in window)) { draw(activeRange(chart)); return; }

        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) { return; }
                draw(activeRange(chart));
                observer.unobserve(entry.target);
            });
        }, { threshold: 0.25 });

        observer.observe(chart);
    });
}());
