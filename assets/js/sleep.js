/**
 * sleep.js — Slaap, read (docs/SLEEP.md).
 *
 * The night (components/sleep-night.php): a finger, a cursor or the arrow
 * keys on the timeline show the period of a stage there — its name and when
 * it began and ended — above the chart, that block ringed and the others
 * stepped back, as a chart's reading is (compass-history.js):
 *
 *   touch   press and slide along the night; a vertical drag still scrolls.
 *           The reading stays a moment after lifting.
 *   mouse   hover.
 *   keys    arrows step from period to period in time, Home/End jump to the
 *           first and last, Escape puts the reading away. Announced through
 *           a live region.
 *
 * Where the finger is between two periods, the nearer one is read. Every
 * word and time is the server's; nothing here formats one.
 *
 * The four small charts (components/sleep-chart.php) are read by
 * compass-history.js and open their page with a tap or click
 * (detail-layer.js). A sideways drag that read a chart is not a tap: it
 * does not open the page.
 */

(function () {
    'use strict';

    var LINGER = 1600;      // ms a touch reading stays after the finger lifts
    var SLOP = 8;           // px a press may move and still be a tap

    Array.prototype.forEach.call(document.querySelectorAll('[data-sleep-night]'), night);
    Array.prototype.forEach.call(document.querySelectorAll('[data-sleep-mini]'), mini);

    /* =============================================================== night */

    function night(card) {
        var plot   = card.querySelector('[data-night-plot]');
        var tip    = card.querySelector('[data-night-tip]');
        var live   = card.querySelector('[data-night-live]');
        var source = card.querySelector('[data-night-blocks]');
        if (!plot || !tip || !source) { return; }

        var data;
        try { data = JSON.parse(source.textContent); } catch (error) { return; }

        var rows   = data.rows || [];
        var blocks = data.blocks || [];        // [row, from, to, began, ended], in time order
        var spans  = Array.prototype.slice.call(plot.querySelectorAll('[data-block]'));
        if (!blocks.length) { return; }

        var tipStage = tip.querySelector('[data-tip-stage]');
        var tipTime  = tip.querySelector('[data-tip-time]');
        var current  = -1;
        var linger   = 0;
        var pressing = false;
        var pending  = null;
        var frame    = 0;

        /** The period at a place on the night, in %: the one there, or the nearest. */
        function at(percent) {
            var best = 0;
            var gap  = Infinity;

            for (var i = 0; i < blocks.length; i++) {
                var from = blocks[i][1];
                var to   = blocks[i][2];
                var d    = percent < from ? from - percent : (percent > to ? percent - to : 0);
                if (d < gap) { gap = d; best = i; }
                if (d === 0) { break; }
            }

            return best;
        }

        function show(index, announce) {
            var block = blocks[index];
            if (!block) { return; }

            window.clearTimeout(linger);

            if (index !== current) {
                if (spans[current]) { spans[current].classList.remove('is-read'); }
                current = index;
                if (spans[index]) { spans[index].classList.add('is-read'); }
                tipStage.textContent = rows[block[0]] || '';
                tipTime.textContent  = block[3] + ' – ' + block[4];
            }

            tip.hidden = false;
            plot.classList.add('is-reading');
            place((block[1] + block[2]) / 2);

            if (announce && live) {
                live.textContent = (rows[block[0]] || '') + ', ' + block[3] + ' tot ' + block[4];
            }
        }

        /** Centred over its period, pushed back inside the plot at either end. */
        function place(x) {
            var width = plot.clientWidth;
            var own   = tip.offsetWidth;
            var left  = x / 100 * width - own / 2;

            tip.style.left = (own >= width ? (width - own) / 2 : Math.max(0, Math.min(width - own, left))) + 'px';
        }

        function hide() {
            window.clearTimeout(linger);
            if (spans[current]) { spans[current].classList.remove('is-read'); }
            current = -1;
            tip.hidden = true;
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
                if (pending !== null) { show(at(pending), false); }
                pending = null;
            });
        }

        plot.addEventListener('pointerdown', function (event) {
            if (event.pointerType === 'mouse' && event.button !== 0) { return; }

            pressing = event.pointerType !== 'mouse';
            show(at(percentOf(event)), false);

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
            var last = blocks.length - 1;
            var next;

            switch (event.key) {
                case 'ArrowRight': next = current < 0 ? 0 : Math.min(last, current + 1); break;
                case 'ArrowLeft':  next = current < 0 ? 0 : Math.max(0, current - 1);    break;
                case 'Home':       next = 0;    break;
                case 'End':        next = last; break;
                case 'Escape':     hide(); return;
                default:           return;
            }

            event.preventDefault();
            show(next, true);
        });

        /* Arriving by Tab shows the first period straight away. */
        plot.addEventListener('focus', function () {
            var visible = true;
            try { visible = plot.matches(':focus-visible'); } catch (error) { /* older engines */ }
            if (visible && current < 0) { show(0, true); }
        });

        plot.addEventListener('blur', function () {
            if (!pressing) { hide(); }
        });

        window.addEventListener('resize', function () {
            if (current >= 0) { place((blocks[current][1] + blocks[current][2]) / 2); }
        });
    }

    /* ======================================================= small charts */

    /* A press that slid sideways was a reading, not a tap: the click it
       ends with is kept from the detail layer. */
    function mini(card) {
        var start = null;
        var moved = false;

        card.addEventListener('pointerdown', function (event) {
            start = { x: event.clientX, y: event.clientY };
            moved = false;
        });

        card.addEventListener('pointermove', function (event) {
            if (!start || moved) { return; }
            if (Math.abs(event.clientX - start.x) > SLOP || Math.abs(event.clientY - start.y) > SLOP) {
                moved = event.pointerType !== 'mouse' || event.buttons !== 0;
            }
        });

        card.addEventListener('click', function (event) {
            if (moved && !event.target.closest('button')) {
                event.preventDefault();
                event.stopPropagation();
            }
            moved = false;
            start = null;
        }, true);
    }
}());
