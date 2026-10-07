/**
 * goals.js — the Doelen page: switching views, and managing a goal.
 *
 * Both views are already in the document, so Actief/Behaald is a class toggle
 * and each keeps its own scroll position — the same approach the community
 * boards use.
 *
 * Priority, pause and delete work on the cards and detail pages that are
 * already rendered: a change shows straight away and is saved through
 * api/goals/, and a refusal brings back what is stored (see persistence
 * below).
 *
 * Two rules are enforced here rather than assumed:
 *   exactly one primary goal, always
 *   at most `limits` → `active` active goals (config/goals.php), paused ones included
 */

(function () {
    'use strict';

    var page = document.querySelector('[data-goals]');
    if (!page) { return; }

    var nav = window.AppNav;

    /* ---------------------------------------------------------------- copy */

    var copy = { labels: {}, detail: {} };
    var source = page.querySelector('[data-goals-copy]');
    if (source) {
        try { copy = JSON.parse(source.textContent); } catch (e) { /* defaults stand */ }
    }
    window.GoalCopy = copy;

    var primarySlot, secondarySlot, emptyState, slotsNote, addButtons;

    /* Looked up again after the board is refreshed in place (see refresh()),
       because the elements they point at are then new ones. */
    function bind() {
        primarySlot   = page.querySelector('[data-goal-slot="primary"]');
        secondarySlot = page.querySelector('[data-goal-slot="secondary"]');
        emptyState    = page.querySelector('[data-goals-empty]');
        slotsNote     = page.querySelector('[data-goal-slots]');
        addButtons    = document.querySelectorAll('[data-goal-add]');
    }

    bind();

    /* ----------------------------------------------------- view switching */

    function showView(key) {
        Array.prototype.forEach.call(page.querySelectorAll('[data-goal-panel]'), function (panel) {
            var isActive = panel.dataset.goalPanel === key;
            panel.classList.toggle('is-active', isActive);
            panel.inert = !isActive;
            if (isActive) { panel.removeAttribute('aria-hidden'); }
            else { panel.setAttribute('aria-hidden', 'true'); }
        });

        Array.prototype.forEach.call(page.querySelectorAll('[data-goal-view]'), function (option) {
            var isActive = option.getAttribute('data-goal-view') === key;
            option.classList.toggle('is-active', isActive);
            option.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });
    }

    page.addEventListener('click', function (event) {
        var option = event.target.closest('[data-goal-view]');
        if (!option) { return; }
        showView(option.getAttribute('data-goal-view'));
    });

    /* ------------------------------------------------------------ helpers */

    function cards() {
        var list = [];
        if (primarySlot) { list = list.concat(Array.prototype.slice.call(primarySlot.children)); }
        if (secondarySlot) { list = list.concat(Array.prototype.slice.call(secondarySlot.children)); }
        return list;
    }

    function cardOf(id) {
        return page.querySelector('[data-goal-card="' + id + '"]');
    }

    function detailOf(id) {
        return document.querySelector('[data-goal-detail="' + id + '"]');
    }

    function primaryCard() {
        return primarySlot ? primarySlot.firstElementChild : null;
    }

    /** Keeps the heading, the empty state, the slot note and + in agreement. */
    function sync() {
        var active = cards().length;
        /* The limit is config/goals.php's, sent with the copy; without it the
           note and the buttons keep what the server rendered. */
        var limit = copy.limits ? copy.limits.active : null;
        var left = limit ? Math.max(0, limit - active) : null;

        var secondaryEyebrow = page.querySelector('[data-goal-eyebrow="secondary"]');
        var primaryEyebrow = page.querySelector('[data-goal-eyebrow="primary"]');

        if (secondaryEyebrow) {
            secondaryEyebrow.hidden = !secondarySlot || secondarySlot.children.length === 0;
        }
        if (primaryEyebrow) { primaryEyebrow.hidden = active === 0; }
        if (emptyState) { emptyState.hidden = active !== 0; }

        if (slotsNote) {
            slotsNote.hidden = active === 0;
            if (left !== null) { slotsNote.textContent = slotText(left, limit); }
        }

        if (left === null) { return; }

        Array.prototype.forEach.call(addButtons, function (button) {
            var full = left === 0;
            button.disabled = full;
            if (full) { button.setAttribute('aria-disabled', 'true'); }
            else { button.removeAttribute('aria-disabled'); }
        });
    }

    /** goals_slot_note() in lib/goals.php: %1$d places left, %2$d the limit. */
    function slotText(left, limit) {
        var key = left === 0 ? 'slots_full' : (left === 1 ? 'slots_one' : 'slots_free');
        return (copy.labels[key] || '').replace('%1$d', left).replace('%2$d', limit);
    }

    /* ---------------------------------------------------------- priority */

    /** Applies a priority to one goal's card and detail page, visuals included. */
    function applyPriority(id, priority) {
        var card = cardOf(id);
        var detail = detailOf(id);
        var isPrimary = priority === 'primary';

        if (card) {
            card.dataset.goalPriority = priority;
            card.classList.toggle('goal-card--primary', isPrimary);
            card.classList.toggle('goal-card--secondary', !isPrimary);
        }

        if (detail) {
            detail.dataset.goalPriority = priority;

            var chip = detail.querySelector('[data-goal-chip="primary"]');
            if (chip) { chip.hidden = !isPrimary; }

            var state = detail.querySelector('[data-goal-action="is-primary"]');
            if (state) { state.hidden = !isPrimary; }

            var promote = detail.querySelector('[data-goal-action="promote"]');
            if (promote) { promote.hidden = isPrimary; }
        }
    }

    /**
     * Promotion is always a swap, which is what guarantees there is exactly
     * one primary goal without anyone having to think about it.
     */
    function promote(id) {
        var card = cardOf(id);
        if (!card || !primarySlot || !secondarySlot) { return; }
        if (card.dataset.goalPriority === 'primary') { return; }

        var outgoing = primaryCard();
        var anchor = card.nextElementSibling;

        if (outgoing) {
            secondarySlot.insertBefore(outgoing, anchor);
            applyPriority(outgoing.dataset.goalCard, 'secondary');
        }

        primarySlot.appendChild(card);
        applyPriority(id, 'primary');

        sync();
    }

    /* ------------------------------------------------------------- pause */

    function applyPaused(id, paused) {
        var card = cardOf(id);
        var detail = detailOf(id);
        var status = paused ? 'paused' : 'active';

        if (card) {
            card.dataset.goalStatus = status;
            card.classList.toggle('is-paused', paused);

            var flag = card.querySelector('[data-goal-flag]');
            if (flag) { flag.hidden = !paused; }

            var line = card.querySelector('[data-goal-deadline]');
            if (line) {
                line.textContent = paused ? card.dataset.linePaused : card.dataset.lineActive;
            }
        }

        if (!detail) { return; }

        detail.dataset.goalStatus = status;

        var chip = detail.querySelector('[data-goal-chip="paused"]');
        if (chip) { chip.hidden = !paused; }

        var deadline = detail.querySelector('[data-goal-deadline]');
        if (deadline) {
            deadline.textContent = paused ? detail.dataset.linePaused : detail.dataset.lineActive;
        }

        var note = detail.querySelector('[data-goal-note]');
        if (note) {
            note.textContent = paused ? detail.dataset.notePaused : detail.dataset.noteActive;
        }

        var label = detail.querySelector('[data-goal-pause-label]');
        if (label) { label.textContent = paused ? copy.detail.resume : copy.detail.pause; }

        /* Both glyphs are in the button; only one is ever shown. */
        Array.prototype.forEach.call(detail.querySelectorAll('[data-goal-pause-icon]'), function (slot) {
            var wanted = paused ? 'play' : 'pause';
            slot.hidden = slot.getAttribute('data-goal-pause-icon') !== wanted;
        });
    }

    /* ------------------------------------------------------------ delete */

    /**
     * The goal that takes the primary goal's place: the first card under
     * Secundaire doelen, which the server ordered — a running goal before a
     * paused one, as goals_successor() (lib/goals.php) picks it. Its id goes
     * along with the delete, so the goal moved up here is the goal stored.
     */
    function successorOf(id) {
        if (!secondarySlot) { return null; }

        var staying = Array.prototype.filter.call(secondarySlot.children, function (other) {
            return other.dataset.goalCard && other.dataset.goalCard !== id && !other.classList.contains('is-leaving');
        });
        var running = staying.filter(function (other) { return other.dataset.goalStatus !== 'paused'; });
        var next = running[0] || staying[0];

        return next ? next.dataset.goalCard : null;
    }

    /** Takes the card off the board; says which goal moved up, and when the card is gone. */
    function remove(id) {
        var card = cardOf(id);
        var detail = detailOf(id);
        var wasPrimary = !!card && card.dataset.goalPriority === 'primary';
        var successor = wasPrimary ? successorOf(id) : null;

        if (nav && nav.details && nav.details.currentId() === 'goal-' + id) {
            nav.details.close(false);
        }

        var gone = new Promise(function (resolve) {
            if (!card) { resolve(); return; }

            card.classList.add('is-leaving');
            window.setTimeout(function () {
                if (card.parentNode) { card.parentNode.removeChild(card); }

                // The board must never be left without a primary goal.
                var next = successor !== null ? cardOf(successor) : null;
                if (next && primarySlot) {
                    primarySlot.appendChild(next);
                    applyPriority(successor, 'primary');
                }

                sync();

                // Focus has nowhere to be once the card is gone: hand it to
                // the one control that is always on this page.
                var add = page.querySelector('[data-goal-add]');
                if (add && !add.disabled) { add.focus({ preventScroll: true }); }
                resolve();
            }, 200);
        });

        if (detail) {
            window.setTimeout(function () {
                if (nav && nav.details) { nav.details.forget(detail); }
                if (detail.parentNode) { detail.parentNode.removeChild(detail); }
            }, (nav ? nav.duration : 280) + 120);
        }

        return { primary: wasPrimary, successor: successor, gone: gone };
    }

    /* ----------------------------------------------- actions on a detail */

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-goal-action]');
        if (!trigger) { return; }

        var detail = trigger.closest('[data-goal-detail]');
        if (!detail) { return; }

        var id = detail.dataset.goalDetail;
        var action = trigger.getAttribute('data-goal-action');
        var confirm = detail.querySelector('[data-goal-confirm]');

        if (action === 'promote') {
            promote(id);
            save('api/goals/update.php', { goal_id: id, action: 'primary' });
            return;
        }

        if (action === 'pause') {
            var pausing = detail.dataset.goalStatus !== 'paused';
            applyPaused(id, pausing);
            save('api/goals/update.php', { goal_id: id, action: pausing ? 'pause' : 'resume' });
            return;
        }

        if (action === 'delete') {
            if (confirm) { confirm.hidden = false; }
            trigger.hidden = true;
            return;
        }

        if (action === 'delete-cancel') {
            if (confirm) { confirm.hidden = true; }
            var button = detail.querySelector('[data-goal-action="delete"]');
            if (button) { button.hidden = false; }
            return;
        }

        if (action === 'delete-confirm') {
            if (confirm) { confirm.hidden = true; }
            var removal = remove(id);
            var fields = { goal_id: id };
            if (removal.successor !== null) { fields.successor = removal.successor; }
            var saved = save('api/goals/delete.php', fields);

            /* A new primary goal: once the card is gone and the delete is
               stored, the goal parts of the page are read again — Overzicht's
               goal card included — with the goal already moved up here. */
            if (removal.primary) {
                Promise.all([removal.gone, saved]).then(function (done) {
                    if (done[1] && done[1].ok) { refresh(); }
                });
            }
        }
    });

    /* ------------------------------------------------------ persistence ---

       The board keeps its optimistic behaviour: a tap moves the card straight
       away, because waiting on a round trip for a pause would feel broken.
       What changes is that the change is also sent, and a server that refuses
       it is not quietly ignored — the page reloads so what is on screen is
       what is actually stored.
       ---------------------------------------------------------------------- */

    function csrf() {
        var panel = document.querySelector('[data-account]');
        return panel ? (panel.getAttribute('data-csrf') || '') : '';
    }

    function save(url, fields) {
        var body = new FormData();
        body.append('csrf', csrf());

        Object.keys(fields).forEach(function (key) {
            body.append(key, fields[key]);
        });

        return fetch(url, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (response) {
                return response.json().catch(function () { return { ok: false }; });
            })
            .catch(function () { return { ok: false }; })
            .then(function (result) {
                if (!result || !result.ok) {
                    // The optimistic change did not stick: show the truth.
                    refresh();
                }

                return result;
            });
    }

    /* ------------------------------------------------ refresh in place ---

       Saving used to end in window.location.reload(). The shell always starts
       on Overzicht, so every save threw the person out of the goal they were
       in and back to the first page.

       Instead the page is asked for again in the background and only the goal
       parts of it are exchanged: the two board panels, each goal's detail
       page, and the Overzicht goal card. Every figure is still rendered by
       the server — nothing is recalculated here — but the rail stays on
       Doelen, an open goal stays open at its scroll position, and the view
       (Actief or Behaald) stays as it was.
       ---------------------------------------------------------------------- */

    function each(root, selector, fn) {
        Array.prototype.forEach.call(root.querySelectorAll(selector), fn);
    }

    /** Where every bar in a region stands now, so a new one can grow from it. */
    function barWidths(root) {
        var widths = {};
        each(root, '[data-bar]', function (bar, index) {
            var card = bar.closest('[data-goal-card]');
            widths[card ? 'card-' + card.dataset.goalCard : 'bar-' + index] = bar.style.width || '';
        });
        return widths;
    }

    /**
     * Brings freshly inserted markup to the state the page-load scripts would
     * have left it in: revealed, bars filled (from where they were, so a
     * changed bar moves rather than restarting), charts wired up.
     */
    function settle(root, widths) {
        if (root.classList.contains('reveal')) { root.classList.add('is-visible'); }
        each(root, '.reveal', function (item) { item.classList.add('is-visible'); });
        each(root, '.card', function (card) { card.dataset.animated = 'true'; });

        each(root, '[data-bar]', function (bar, index) {
            var card = bar.closest('[data-goal-card]');
            var from = widths ? widths[card ? 'card-' + card.dataset.goalCard : 'bar-' + index] : '';
            var to = Math.max(0, Math.min(1, parseFloat(bar.getAttribute('data-progress')) || 0));

            if (from) { bar.style.width = from; }
            window.requestAnimationFrame(function () {
                window.requestAnimationFrame(function () { bar.style.width = (to * 100) + '%'; });
            });
        });

        if (window.GoalChart) {
            each(root, '[data-goal-chart]', function (chart) { window.GoalChart.setup(chart); });
        }
    }

    function swapBoard(fresh) {
        ['active', 'completed'].forEach(function (key) {
            var panel = page.querySelector('[data-goal-panel="' + key + '"]');
            var next = fresh.querySelector('[data-goals] [data-goal-panel="' + key + '"]');
            if (!panel || !next) { return; }

            var widths = barWidths(panel);
            panel.innerHTML = next.innerHTML;     // the panel keeps its own view state
            settle(panel, widths);
        });

        /* The + in the page header sits outside both panels. */
        var add = page.querySelector('.goals-add[data-goal-add]');
        var nextAdd = fresh.querySelector('[data-goals] .goals-add[data-goal-add]');
        if (add && nextAdd) { add.disabled = nextAdd.disabled; }
    }

    function swapDetails(fresh) {
        var stack = document.querySelector('[data-detail-stack]');
        if (!stack) { return; }

        var seen = {};

        each(fresh, '[data-goal-detail]', function (next) {
            var id = next.dataset.goalDetail;
            var detail = detailOf(id);
            seen[id] = true;

            if (!detail) {
                /* A goal that did not exist when the page loaded. */
                detail = document.importNode(next, true);
                var goals = stack.querySelectorAll('[data-goal-detail]');
                var after = goals.length ? goals[goals.length - 1] : null;
                stack.insertBefore(detail, after ? after.nextSibling : null);

                settle(detail, null);
                if (nav && nav.details && nav.details.adopt) { nav.details.adopt(detail); }

                var scroller = detail.querySelector('[data-scroller]');
                if (scroller && window.AppChrome) { window.AppChrome.bind(scroller); }
                return;
            }

            /* The layer itself stays — its open state, its position, its
               scroller and so its scroll position. What it says is replaced. */
            Array.prototype.forEach.call(next.attributes, function (attribute) {
                if (/^data-/.test(attribute.name) && attribute.name !== 'data-detail') {
                    detail.setAttribute(attribute.name, attribute.value);
                }
            });

            var main = detail.querySelector('.app__main');
            var nextMain = next.querySelector('.app__main');
            if (main && nextMain) {
                var widths = barWidths(main);
                main.innerHTML = nextMain.innerHTML;
                settle(main, widths);
            }
        });

        /* A goal that is gone on the server leaves here too. */
        each(stack, '[data-goal-detail]', function (detail) {
            if (seen[detail.dataset.goalDetail]) { return; }
            if (nav && nav.details && nav.details.currentId() === detail.dataset.detail) { return; }
            if (nav && nav.details) { nav.details.forget(detail); }
            detail.parentNode.removeChild(detail);
        });
    }

    /* The Overzicht card follows the primary goal. */
    function swapOverview(fresh) {
        var card = document.querySelector('[data-page="overview"] .card--goal');
        var next = fresh.querySelector('[data-page="overview"] .card--goal');
        if (!card || !next) { return; }

        var replacement = document.importNode(next, true);
        var widths = barWidths(card);
        card.parentNode.replaceChild(replacement, card);
        settle(replacement, widths);
    }

    /**
     * Fetches the page as the server now renders it and swaps the goal parts
     * in. Resolves true when the page shows what is stored, false when the
     * page could not be fetched (the save itself has already happened).
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
                if (!fresh.querySelector('[data-goals]')) { throw new Error('no board'); }

                swapBoard(fresh);
                swapDetails(fresh);
                swapOverview(fresh);

                bind();
                sync();
                return true;
            })
            .catch(function () { return false; });
    }

    /* ------------------------------------------------------- new goal ---- */

    /**
     * The wizard is a separate module and a separate layer. This page only
     * says "a goal is wanted"; whether the flow is available is its problem.
     */
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-goal-add]');
        if (!button || button.disabled) { return; }

        event.preventDefault();

        if (window.GoalWizard && window.GoalWizard.open) {
            window.GoalWizard.open(button);
        }
    });

    /* ------------------------------------------- manual progress entry */

    /**
     * Saving where a manual goal stands.
     *
     * Only manual goals get here — an automatic one has no entry control, and
     * the endpoint refuses it anyway, so the two numbers can never disagree.
     *
     * On success the goal's parts are fetched again and swapped in (see
     * refresh()), rather than patching the bar here. The percentage, the bar,
     * the deadline line and whether the goal has just moved to Behaald are
     * all rendered server-side from one calculation; re-deriving any of that
     * here is how the two start disagreeing.
     */
    document.addEventListener('click', function (event) {
        var tick = event.target.closest('[data-goal-tick]');
        var save = event.target.closest('[data-goal-save]');

        if (!tick && !save) { return; }

        var button = tick || save;
        var goalId = button.getAttribute(tick ? 'data-goal-tick' : 'data-goal-save');
        var card   = button.closest('[data-goal-manual]');
        var input  = card ? card.querySelector('[data-goal-value]') : null;
        var slot   = card ? card.querySelector('[data-goal-error]') : null;

        event.preventDefault();

        if (slot) { slot.hidden = true; slot.textContent = ''; }

        /* A day is ticked off rather than measured, so a tick sends no value
           and the server marks the day. A result or an amount needs a number. */
        if (save && (!input || input.value.trim() === '')) {
            if (slot) { slot.textContent = 'Vul een waarde in.'; slot.hidden = false; }
            if (input) { input.focus(); }
            return;
        }

        var panel = document.querySelector('[data-account]');
        var body  = new FormData();

        body.append('csrf', panel ? (panel.getAttribute('data-csrf') || '') : '');
        body.append('goal_id', goalId);

        if (save && input) { body.append('value', input.value.trim()); }

        button.disabled = true;

        fetch('api/goals/progress.php', { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (response) {
                return response.json().catch(function () { return { ok: false }; });
            })
            .catch(function () { return { ok: false }; })
            .then(function (result) {
                if (!result || !result.ok) {
                    button.disabled = false;

                    if (slot) {
                        slot.textContent = (result && result.error) || 'Dit kon niet worden opgeslagen.';
                        slot.hidden = false;
                    }
                    return;
                }

                /* Saved. The goal stays open and its detail page is redrawn
                   in place with the new figures — no reload, which would land
                   on Overzicht. */
                refresh().then(function (shown) {
                    var detail = detailOf(goalId);

                    if (!shown) {
                        button.disabled = false;
                        if (input) { input.value = ''; }
                        if (slot) {
                            slot.textContent = 'Opgeslagen. Je nieuwe voortgang verschijnt zodra de pagina ververst.';
                            slot.hidden = false;
                        }
                        return;
                    }

                    /* The button that was pressed has been replaced; focus
                       goes to its successor so the keyboard stays in the goal. */
                    var next = null;
                    ['[data-goal-value]', '[data-goal-tick]:not([disabled])', '[data-detail-close]'].some(function (selector) {
                        next = detail ? detail.querySelector(selector) : null;
                        return !!next;
                    });
                    if (next && next.focus) { next.focus({ preventScroll: true }); }
                });
            });
    });

    /* --------------------------------------------------------------- init */

    window.GoalBoard = { refresh: refresh, show: showView };

    sync();
}());
