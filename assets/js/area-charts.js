/**
 * area-charts.js — an area's small charts (components/area-chart.php,
 * docs/CHARTS.md): Slaap's and Training's, two by two.
 *
 * Each is read by compass-history.js and opens its page with a tap or click
 * (detail-layer.js). A sideways drag that read a chart is not a tap: it
 * does not open the page.
 */

(function () {
    'use strict';

    var SLOP = 8;           // px a press may move and still be a tap

    Array.prototype.forEach.call(document.querySelectorAll('[data-area-mini]'), mini);

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
