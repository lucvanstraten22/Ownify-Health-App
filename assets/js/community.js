/**
 * community.js — switching scope and period, and the docked state of your row.
 *
 * Six boards are already in the document; switching is a class toggle, so
 * each board keeps its own scroll position.
 *
 * Your own row needs no JavaScript at all: `position: sticky` with both a top
 * and a bottom offset makes it sit in its own place while it is on screen and
 * dock to whichever edge it would otherwise leave. One element, never a
 * duplicate, and it survives any scroll the browser can do.
 */

(function () {
    'use strict';

    var page = document.querySelector('[data-community]');
    if (!page) { return; }

    var boards = Array.prototype.slice.call(page.querySelectorAll('[data-board]'));
    if (!boards.length) { return; }

    var scope = null;
    var period = null;

    /* ---------------------------------------------------------- switching */

    function activeBoard() {
        for (var i = 0; i < boards.length; i++) {
            if (boards[i].dataset.scope === scope && boards[i].dataset.period === period) {
                return boards[i];
            }
        }
        return null;
    }

    function syncOptions(attr, value) {
        Array.prototype.forEach.call(page.querySelectorAll('[' + attr + ']'), function (option) {
            var isActive = option.getAttribute(attr) === value;
            option.classList.toggle('is-active', isActive);
            option.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });
    }

    function apply() {
        boards.forEach(function (board) {
            var isActive = board.dataset.scope === scope && board.dataset.period === period;
            board.classList.toggle('is-active', isActive);
            board.inert = !isActive;
            if (isActive) { board.removeAttribute('aria-hidden'); }
            else { board.setAttribute('aria-hidden', 'true'); }
        });

        syncOptions('data-scope-option', scope);
        syncOptions('data-period-option', period);
    }

    page.addEventListener('click', function (event) {
        var scopeOption = event.target.closest('[data-scope-option]');
        if (scopeOption) {
            scope = scopeOption.getAttribute('data-scope-option');
            apply();
            return;
        }

        var periodOption = event.target.closest('[data-period-option]');
        if (periodOption) {
            period = periodOption.getAttribute('data-period-option');
            apply();
        }
    });

    /* --------------------------------------------------------------- init */

    var firstActive = page.querySelector('[data-board].is-active') || boards[0];
    scope = firstActive.dataset.scope;
    period = firstActive.dataset.period;
    apply();
}());
