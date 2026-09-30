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

    /* ------------------------------------------------------------ refresh */

    /**
     * Puts the rows of a freshly rendered page into the boards. The boards
     * keep their own elements — switching scope and period holds on to them —
     * so only their rows change. For anything that changes what the boards
     * show without a reload: a friendship (friends.js), your picture on the
     * boards (settings.js).
     */
    function swap(fresh) {
        boards.forEach(function (board) {
            var next = fresh.querySelector('[data-board][data-scope="' + board.dataset.scope
                + '"][data-period="' + board.dataset.period + '"]');
            if (!next) { return; }

            board.innerHTML = next.innerHTML;
            Array.prototype.forEach.call(board.querySelectorAll('.reveal'), function (item) { item.classList.add('is-visible'); });
            Array.prototype.forEach.call(board.querySelectorAll('.card'), function (item) { item.dataset.animated = 'true'; });
        });
    }

    /** Fetches the page as the server now renders it, and swaps its board rows in. */
    function refresh() {
        return fetch(window.location.href.split('#')[0], {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'Accept': 'text/html' }
        })
            .then(function (response) {
                if (!response.ok) { throw new Error('HTTP ' + response.status); }
                return response.text();
            })
            .then(function (html) {
                var fresh = new DOMParser().parseFromString(html, 'text/html');
                if (fresh.querySelector('[data-deck]')) { swap(fresh); }
            })
            .catch(function () { /* what was saved is saved; the next load shows it */ });
    }

    window.AppBoards = { swap: swap, refresh: refresh };

    /* A picture that cannot be had (its file gone) leaves the row, so the
       initial under it shows instead of a broken image: one that already
       failed before this script ran, and any that fails later — errors do
       not bubble, so they are caught on the way down, rows swapped in
       included. */
    function dropPhoto(photo) {
        if (photo.parentNode) { photo.parentNode.removeChild(photo); }
    }

    Array.prototype.forEach.call(page.querySelectorAll('.board-row__photo'), function (photo) {
        if (photo.complete && photo.naturalWidth === 0) { dropPhoto(photo); }
    });

    document.addEventListener('error', function (event) {
        var photo = event.target;
        if (photo && photo.classList && photo.classList.contains('board-row__photo')) { dropPhoto(photo); }
    }, true);

    /* --------------------------------------------------------------- init */

    var firstActive = page.querySelector('[data-board].is-active') || boards[0];
    scope = firstActive.dataset.scope;
    period = firstActive.dataset.period;
    apply();
}());
