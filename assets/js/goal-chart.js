/**
 * goal-chart.js — reading a goal's Verloop with a finger, a cursor or keys.
 *
 * The chart is drawn server-side (lib/goal-chart.php) and every point arrives
 * on a data attribute already written in Dutch — "12 september", "10.425
 * stappen" — so nothing here formats a number or a date. This file only finds
 * which point the reader means and shows it.
 *
 *   touch   press and slide sideways along the line; a vertical drag still
 *           scrolls the page (touch-action: pan-y). The reading stays a
 *           moment after lifting, so it can be read without a finger on it.
 *   mouse   hover.
 *   keys    arrows step through the points, Home/End jump to either end,
 *           Escape puts it away. Announced through a live region.
 *
 * The crosshair finds the X: the nearest point by date, so nobody has to land
 * on a 2px line. The reading sits above the plot, never on it, so it cannot
 * cover the line it is describing.
 */

(function () {
    'use strict';

    var charts = document.querySelectorAll('[data-goal-chart]');
    if (!charts.length) { return; }

    var LINGER = 1600;      // ms a touch reading stays after the finger lifts

    Array.prototype.forEach.call(charts, setup);

    function setup(root) {
        var points;

        try {
            points = JSON.parse(root.getAttribute('data-points') || '[]');
        } catch (error) {
            return;
        }

        var plot  = root.querySelector('[data-goal-plot]');
        var cross = root.querySelector('[data-goal-cross]');
        var focus = root.querySelector('[data-goal-focus]');
        var tip   = root.querySelector('[data-goal-tip]');
        var live  = root.querySelector('[data-goal-live]');

        if (!points.length || !plot || !cross || !focus || !tip) { return; }

        var tipValue = tip.querySelector('[data-tip-value]');
        var tipDate  = tip.querySelector('[data-tip-date]');

        var current  = -1;
        var pending  = null;
        var frame    = 0;
        var linger   = 0;
        var pressing = false;

        /** The point nearest in time to a horizontal position, in % of the plot. */
        function nearest(percent) {
            var best = 0;
            var gap  = Infinity;

            for (var i = 0; i < points.length; i++) {
                var d = Math.abs(points[i].x - percent);
                if (d < gap) { gap = d; best = i; }
            }

            return best;
        }

        function show(index, announce) {
            var point = points[index];
            if (!point) { return; }

            window.clearTimeout(linger);

            if (index !== current) {
                current = index;

                cross.style.left = point.x + '%';
                focus.style.left = point.x + '%';
                focus.style.top  = point.y + '%';

                /* textContent, never innerHTML: these strings came out of the
                   database, and a goal name or unit is user input. */
                tipValue.textContent = point.v;
                tipDate.textContent  = point.d;
            }

            cross.hidden = false;
            focus.hidden = false;
            tip.hidden   = false;
            root.classList.add('is-reading');

            place(point);

            if (announce && live) { live.textContent = point.d + ', ' + point.v; }
        }

        /**
         * Centres the reading over its point, then pushes it back inside the
         * chart if that would run it off either side — the end points are
         * exactly the ones people check most, and they sit at the edges.
         */
        function place(point) {
            /* The plot's width, not the chart's: letting the reading run into
               the gutter would cover the Y-axis numbers it is meant to be read
               against. For a narrow plot it may overhang both sides; centred
               on the plot is then the least bad. */
            var width = plot.clientWidth;
            var own   = tip.offsetWidth;
            var at    = point.x / 100 * width - own / 2;

            tip.style.left = (own >= width ? (width - own) / 2 : Math.max(0, Math.min(width - own, at))) + 'px';
        }

        function hide() {
            window.clearTimeout(linger);
            current = -1;
            cross.hidden = true;
            focus.hidden = true;
            tip.hidden   = true;
            root.classList.remove('is-reading');
        }

        function percentOf(event) {
            var box = plot.getBoundingClientRect();
            return box.width ? (event.clientX - box.left) / box.width * 100 : 0;
        }

        /* One update per frame, however fast the pointer reports: a finger
           sliding across a month of points should feel smooth, not busy. */
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

            /* Keep receiving the slide when the finger drifts off the plot. */
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

        /* The browser took the gesture for a scroll: the reader is scrolling,
           not reading, so get out of the way at once. */
        plot.addEventListener('pointercancel', function () {
            pressing = false;
            hide();
        });

        plot.addEventListener('pointerleave', function (event) {
            if (event.pointerType === 'mouse') { hide(); }
        });

        /* The same reading from the keyboard, point by point. */
        plot.addEventListener('keydown', function (event) {
            var last = points.length - 1;
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

        /* Arriving by Tab shows the latest value straight away; arriving by a
           tap does not, because the tap already shows the one it touched. */
        plot.addEventListener('focus', function () {
            var visible = true;
            try { visible = plot.matches(':focus-visible'); } catch (error) { /* older engines */ }
            if (visible && current < 0) { show(points.length - 1, true); }
        });

        plot.addEventListener('blur', function () {
            if (!pressing) { hide(); }
        });

        /* ------------------------------------------------ the end label */

        var endLabel = root.querySelector('.goal-chart__end');
        var prefersBelow = endLabel ? endLabel.classList.contains('is-below') : false;

        /**
         * Where the latest value can be written without sitting on the line.
         *
         * Measured, not guessed: the server cannot know how wide "10.050
         * stappen" is on this phone, or where a spiky daily line runs past the
         * last point. So the label tries its preferred side, then the other,
         * and if both land on the line, a dot or the target it steps aside
         * altogether — the value is still in the tooltip and the table, and a
         * number printed over the data is worse than no number.
         */
        function settleEnd() {
            if (!endLabel) { return; }

            var box = plot.getBoundingClientRect();
            if (!box.width || !box.height) { return; }   // not laid out yet (closed layer)

            var obstacles = [];
            var svg = root.querySelector('svg');
            var vb  = svg && svg.viewBox && svg.viewBox.baseVal;
            var sx  = vb && vb.width  ? box.width  / vb.width  : 1;
            var sy  = vb && vb.height ? box.height / vb.height : 1;

            Array.prototype.forEach.call(root.querySelectorAll('.chart__line, .goal-chart__target'), function (shape) {
                var length = 0;
                try { length = shape.getTotalLength(); } catch (error) { return; }

                for (var at = 0; at <= length; at += 6) {
                    var pt = shape.getPointAtLength(at);
                    obstacles.push([box.left + pt.x * sx, box.top + pt.y * sy]);
                }
            });

            Array.prototype.forEach.call(root.querySelectorAll('.goal-chart__dot'), function (dot) {
                var r = dot.getBoundingClientRect();
                obstacles.push([r.left + r.width / 2, r.top + r.height / 2]);
            });

            function clear() {
                var r = endLabel.getBoundingClientRect();
                for (var i = 0; i < obstacles.length; i++) {
                    var o = obstacles[i];
                    if (o[0] > r.left - 3 && o[0] < r.right + 3 && o[1] > r.top - 3 && o[1] < r.bottom + 3) {
                        return false;
                    }
                }
                return true;
            }

            endLabel.hidden = false;
            endLabel.classList.toggle('is-below', prefersBelow);
            if (clear()) { return; }

            endLabel.classList.toggle('is-below', !prefersBelow);
            if (clear()) { return; }

            endLabel.hidden = true;
        }

        settleEnd();

        /* The layer may open after load, and fonts may land late; either
           changes the geometry the label was settled against. */
        if (window.ResizeObserver) {
            new window.ResizeObserver(function () { settleEnd(); }).observe(plot);
        }
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(settleEnd);
        }

        /* A reading positioned for one width is wrong at another. */
        window.addEventListener('resize', function () {
            if (current >= 0) { place(points[current]); }
        });
    }
}());
