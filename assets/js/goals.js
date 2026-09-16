/**
 * goals.js — the Doelen page: switching views, and managing a goal.
 *
 * Both views are already in the document, so Actief/Behaald is a class toggle
 * and each keeps its own scroll position — the same approach the community
 * boards use.
 *
 * Priority, pause and delete work on the cards and detail pages that are
 * already rendered. There is no goal storage yet, so a change lives for one
 * page view and the page says so; what matters for this version is that the
 * interaction, the ordering rules and the transitions are real.
 *
 * Two rules are enforced here rather than assumed:
 *   exactly one primary goal, always
 *   at most three active goals, paused ones included
 */

(function () {
    'use strict';

    var page = document.querySelector('[data-goals]');
    if (!page) { return; }

    var nav = window.AppNav;

    /* ---------------------------------------------------------------- copy */

    var copy = { labels: {}, detail: {}, limits: { active: 3 } };
    var source = page.querySelector('[data-goals-copy]');
    if (source) {
        try { copy = JSON.parse(source.textContent); } catch (e) { /* defaults stand */ }
    }
    window.GoalCopy = copy;

    var primarySlot   = page.querySelector('[data-goal-slot="primary"]');
    var secondarySlot = page.querySelector('[data-goal-slot="secondary"]');
    var emptyState    = page.querySelector('[data-goals-empty]');
    var slotsNote     = page.querySelector('[data-goal-slots]');
    var addButtons    = document.querySelectorAll('[data-goal-add]');

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
        var limit = (copy.limits && copy.limits.active) || 3;
        var left = Math.max(0, limit - active);

        var secondaryEyebrow = page.querySelector('[data-goal-eyebrow="secondary"]');
        var primaryEyebrow = page.querySelector('[data-goal-eyebrow="primary"]');

        if (secondaryEyebrow) {
            secondaryEyebrow.hidden = !secondarySlot || secondarySlot.children.length === 0;
        }
        if (primaryEyebrow) { primaryEyebrow.hidden = active === 0; }
        if (emptyState) { emptyState.hidden = active !== 0; }

        if (slotsNote) {
            slotsNote.hidden = active === 0;
            if (left === 0) { slotsNote.textContent = copy.labels.slots_full; }
            else if (left === 1) { slotsNote.textContent = copy.labels.slots_one; }
            else { slotsNote.textContent = (copy.labels.slots_free || '').replace('%d', left); }
        }

        Array.prototype.forEach.call(addButtons, function (button) {
            var full = left === 0;
            button.disabled = full;
            if (full) { button.setAttribute('aria-disabled', 'true'); }
            else { button.removeAttribute('aria-disabled'); }
        });
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

    function remove(id) {
        var card = cardOf(id);
        var detail = detailOf(id);
        var wasPrimary = card && card.dataset.goalPriority === 'primary';

        if (nav && nav.details && nav.details.currentId() === 'goal-' + id) {
            nav.details.close(false);
        }

        if (card) {
            card.classList.add('is-leaving');
            window.setTimeout(function () {
                if (card.parentNode) { card.parentNode.removeChild(card); }

                // The board must never be left without a primary goal.
                if (wasPrimary && secondarySlot && secondarySlot.firstElementChild) {
                    var next = secondarySlot.firstElementChild;
                    primarySlot.appendChild(next);
                    applyPriority(next.dataset.goalCard, 'primary');
                }

                sync();

                // Focus has nowhere to be once the card is gone: hand it to
                // the one control that is always on this page.
                var add = page.querySelector('[data-goal-add]');
                if (add && !add.disabled) { add.focus({ preventScroll: true }); }
            }, 200);
        }

        if (detail) {
            window.setTimeout(function () {
                if (nav && nav.details) { nav.details.forget(detail); }
                if (detail.parentNode) { detail.parentNode.removeChild(detail); }
            }, (nav ? nav.duration : 280) + 120);
        }
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
            return;
        }

        if (action === 'pause') {
            applyPaused(id, detail.dataset.goalStatus !== 'paused');
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
            remove(id);
        }
    });

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

    /* --------------------------------------------------------------- init */

    sync();
}());
