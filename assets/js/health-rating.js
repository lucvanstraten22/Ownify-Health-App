/**
 * health-rating.js — the daily nutrition cijfer on the Voeding page.
 *
 * Saves to api/health/rating.php, which answers with what the rating earned
 * and the scores as they now are. The hint line under the field then says
 * what it earned ("+25 punten — Voeding beoordeeld"), and the parts of the
 * page that show a score or points are fetched again and swapped in, the way
 * goals.js refreshes a goal — no reload, which would land on Overzicht.
 *
 * Saving the same day again replaces the day's cijfer on the server, so a
 * double tap is one rating and one reward.
 */

(function () {
    'use strict';

    var card = document.querySelector('[data-nutrition-rating]');
    if (!card) { return; }

    var input  = card.querySelector('[data-rating-value]');
    var button = card.querySelector('[data-rating-save]');
    var error  = card.querySelector('[data-rating-error]');
    var status = card.querySelector('[data-rating-status]');

    function csrf() {
        var panel = document.querySelector('[data-account]');
        return panel ? (panel.getAttribute('data-csrf') || '') : '';
    }

    function showError(message) {
        if (!error) { return; }
        error.textContent = message || '';
        error.hidden = !message;
    }

    /* ------------------------------------------------------------- saving */

    function save() {
        var value = (input.value || '').trim();

        showError(null);

        if (!/^(10|[1-9])$/.test(value)) {
            showError('Kies een cijfer van 1 tot 10.');
            input.focus();
            return;
        }

        var body = new FormData();
        body.append('csrf', csrf());
        body.append('rating', value);

        button.disabled = true;

        fetch('api/health/rating.php', { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (response) {
                return response.json().catch(function () { return { ok: false }; });
            })
            .catch(function () {
                return { ok: false, error: 'Ownify is niet bereikbaar. Controleer je internetverbinding en probeer het opnieuw.' };
            })
            .then(function (result) {
                button.disabled = false;

                if (!result || !result.ok) {
                    showError((result && result.error) || 'Dit kon niet worden opgeslagen.');
                    return;
                }

                if (status) { status.textContent = result.message || 'Opgeslagen.'; }
                refresh();
            });
    }

    button.addEventListener('click', save);

    input.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            save();
        }
    });

    /* ------------------------------------------- the rest of the page */

    function each(root, selector, fn) {
        Array.prototype.forEach.call(root.querySelectorAll(selector), fn);
    }

    function clamp01(value) {
        return isNaN(value) ? 0 : Math.min(1, Math.max(0, value));
    }

    /**
     * Brings swapped-in markup to the state the page-load scripts leave it
     * in: revealed, and its rings and bars drawn — moving from where the old
     * ones stood, so a changed score slides rather than restarting.
     */
    function settle(node, rings, bars) {
        if (node.classList && node.classList.contains('reveal')) { node.classList.add('is-visible'); }
        if (node.classList && node.classList.contains('card')) { node.dataset.animated = 'true'; }
        each(node, '.reveal', function (item) { item.classList.add('is-visible'); });
        each(node, '.card', function (item) { item.dataset.animated = 'true'; });

        each(node, '[data-ring]', function (ring, index) {
            var circle = ring.querySelector('[data-ring-value]');
            if (!circle) { return; }

            var radius = circle.r && circle.r.baseVal ? circle.r.baseVal.value : 68;
            var length = 2 * Math.PI * radius;
            var target = clamp01(parseFloat(ring.getAttribute('data-progress')));

            circle.style.strokeDasharray = length;
            circle.style.strokeDashoffset = rings[index] || length;

            window.requestAnimationFrame(function () {
                window.requestAnimationFrame(function () {
                    circle.style.strokeDashoffset = length * (1 - target);
                });
            });
        });

        each(node, '[data-bar]', function (bar, index) {
            var target = clamp01(parseFloat(bar.getAttribute('data-progress')));

            if (bars[index]) { bar.style.width = bars[index]; }

            window.requestAnimationFrame(function () {
                window.requestAnimationFrame(function () {
                    bar.style.width = (target * 100) + '%';
                });
            });
        });
    }

    function replace(current, next) {
        if (!current || !next || !current.parentNode) { return; }

        var rings = [];
        var bars  = [];
        each(current, '[data-ring-value]', function (circle) { rings.push(circle.style.strokeDashoffset); });
        each(current, '[data-bar]', function (bar) { bars.push(bar.style.width); });

        var node = document.importNode(next, true);
        current.parentNode.replaceChild(node, current);
        settle(node, rings, bars);
    }

    /**
     * Fetches the page as the server now renders it and swaps in what a
     * rating can change: the three cards on Gezondheid, the ring on
     * Overzicht, the Voeding page's score and numbers (not this card, and not
     * the trend, whose charts are wired once), and each leaderboard's rows.
     */
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
                if (!fresh.querySelector('[data-deck]')) { return; }

                /* Gezondheid's intro: "nog N dagen" until the first score,
                   the three pillars once there is one. */
                var lede = document.querySelector('[data-page="health"] .page-intro__lede');
                var nextLede = fresh.querySelector('[data-page="health"] .page-intro__lede');
                if (lede && nextLede) { lede.textContent = nextLede.textContent; }

                each(document, '[data-page="health"] .health-card[data-detail-open]', function (area) {
                    var id = area.getAttribute('data-detail-open');
                    replace(area, fresh.querySelector('[data-page="health"] .health-card[data-detail-open="' + id + '"]'));
                });

                replace(document.querySelector('[data-page="overview"] .card--hero'),
                        fresh.querySelector('[data-page="overview"] .card--hero'));

                var detail = document.querySelector('[data-detail="nutrition"]');
                var next   = fresh.querySelector('[data-detail="nutrition"]');

                if (detail && next) {
                    replace(detail.querySelector('.card--hero'), next.querySelector('.card--hero'));
                    replace(detail.querySelector('.card--tiles'), next.querySelector('.card--tiles'));

                    var groups = detail.querySelectorAll('.card--group');
                    var nextGroups = next.querySelectorAll('.card--group');

                    for (var i = 0; i < groups.length && i < nextGroups.length; i++) {
                        replace(groups[i], nextGroups[i]);
                    }
                }

                /* The boards keep their own elements — community.js holds on
                   to them to switch scope and period — so only their rows
                   change. */
                each(document, '[data-board]', function (board) {
                    var nextBoard = fresh.querySelector('[data-board][data-scope="' + board.dataset.scope
                        + '"][data-period="' + board.dataset.period + '"]');

                    if (nextBoard) {
                        board.innerHTML = nextBoard.innerHTML;
                        settle(board, [], []);
                    }
                });
            })
            .catch(function () { /* the save itself has happened; the next load shows it */ });
    }
}());
