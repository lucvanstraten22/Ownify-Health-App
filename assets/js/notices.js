/**
 * notices.js — the red dots, kept true while the page stays open
 * (lib/notices.php, docs/NOTICES.md).
 *
 * The server draws every dot where it belongs, shown or [hidden], and puts
 * the summary they come from in <script data-notices>. This keeps them in
 * step without drawing anything:
 *
 *   - the Voeding dot appears at the account's hour on THIS device's clock
 *     (a timer, so it turns up at 20:00 on a page left open);
 *   - what the person just did clears its dot at once — and each parent's
 *     dot with it once nothing below it waits (OwnifyNotices.done());
 *   - a page another script fetched again brings the server's word
 *     (OwnifyNotices.read(doc)), and a part of the page drawn again in place
 *     is painted again on its own (a MutationObserver).
 *
 * Keys, as lib/notices.php's notice_on() reads them:
 *   rating · goal:<id> · friend:<user id> · invite:<id> · source:<provider>
 *   goals · friends · sources · account · tab:health|goals|community|settings
 */
(function () {
    'use strict';

    var model = parse(document);
    if (!model) { return; }

    var timer = 0;

    function parse(doc) {
        var node = doc.querySelector('script[data-notices]');
        if (!node) { return null; }
        try { return JSON.parse(node.textContent || '{}'); } catch (error) { return null; }
    }

    /* ------------------------------------------------------------ the rules */

    function ratingDue() {
        var rating = model.rating || {};
        return !!rating.waiting && new Date().getHours() >= (rating.hour || 20);
    }

    function has(list, id) {
        return (list || []).some(function (item) { return String(item) === String(id); });
    }

    function on(key) {
        var at = key.indexOf(':');
        var kind = at < 0 ? key : key.slice(0, at);
        var id = at < 0 ? null : key.slice(at + 1);

        switch (kind) {
            case 'rating':  return ratingDue();
            case 'goal':    return has(model.goals, id);
            case 'friend':  return has(model.friends, id);
            case 'invite':  return has(model.invites, id);
            case 'source':  return has(model.sources, id);
            case 'goals':   return (model.goals || []).length > 0;
            case 'sources': return (model.sources || []).length > 0;
            case 'friends':
            case 'account': return (model.friends || []).length > 0;
            case 'tab':
                if (id === 'health') { return ratingDue(); }
                if (id === 'goals') { return (model.goals || []).length > 0; }
                if (id === 'community') { return (model.invites || []).length > 0; }
                if (id === 'settings') { return (model.sources || []).length > 0; }
                return false;
            default: return false;
        }
    }

    /* ------------------------------------------------------------ painting */

    var still = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;

    function show(dot, visible) {
        if (visible === !dot.hidden && !dot.classList.contains('is-leaving')) { return; }

        dot.classList.remove('is-arriving', 'is-leaving');
        if (still && still.matches) { dot.hidden = !visible; return; }

        if (visible) {
            dot.hidden = false;
            void dot.offsetWidth;   // start the arrival from its first frame
            dot.classList.add('is-arriving');
        } else if (!dot.hidden) {
            dot.classList.add('is-leaving');
            dot.addEventListener('animationend', function gone() {
                dot.removeEventListener('animationend', gone);
                if (dot.classList.contains('is-leaving')) {
                    dot.classList.remove('is-leaving');
                    dot.hidden = true;
                }
            });
        }
    }

    function paint() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-notice]'), function (dot) {
            show(dot, on(dot.getAttribute('data-notice')));
        });
        schedule();
    }

    /* The Voeding dot at its hour, on a page left open past it. */
    function schedule() {
        window.clearTimeout(timer);
        var rating = model.rating || {};
        if (!rating.waiting || ratingDue()) { return; }
        var at = new Date();
        at.setHours(rating.hour || 20, 0, 0, 0);
        timer = window.setTimeout(paint, Math.max(1000, at.getTime() - Date.now() + 500));
    }

    /* ---------------------------------------------------------- the changes */

    /** What the person just did: its dot, and its parents', clear now. */
    function done(key) {
        var at = key.indexOf(':');
        var kind = at < 0 ? key : key.slice(0, at);
        var id = at < 0 ? null : key.slice(at + 1);
        var drop = function (list) { return (list || []).filter(function (item) { return String(item) !== String(id); }); };

        if (kind === 'rating') { model.rating = Object.assign({}, model.rating, { waiting: false }); }
        if (kind === 'goal') { model.goals = drop(model.goals); }
        if (kind === 'friend') { model.friends = drop(model.friends); }
        if (kind === 'invite') { model.invites = drop(model.invites); }
        if (kind === 'source') { model.sources = drop(model.sources); }
        paint();
    }

    /** Undone again (a tick taken back): it waits once more. */
    function waits(key) {
        var at = key.indexOf(':');
        var kind = key.slice(0, at);
        var id = key.slice(at + 1);
        if (kind === 'goal' && !has(model.goals, id)) { model.goals = (model.goals || []).concat([id]); }
        paint();
    }

    /** Meldingen › Stip bij Voeding chose another hour. */
    function hour(h) {
        model.rating = Object.assign({}, model.rating, { hour: h });
        paint();
    }

    /** A page fetched again: the server's word replaces ours. */
    function read(doc) {
        var fresh = doc ? parse(doc) : null;
        if (!fresh) { return; }
        model = fresh;
        var node = document.querySelector('script[data-notices]');
        if (node) { node.textContent = JSON.stringify(fresh); }
        paint();
    }

    /* A part of the page drawn again in place (a list, a goal, Instellingen)
       comes with the dots as the server drew them: paint them by the rules
       again, once, on the next frame. */
    var queued = false;
    new MutationObserver(function (records) {
        if (queued) { return; }
        var added = records.some(function (record) {
            return Array.prototype.some.call(record.addedNodes, function (node) {
                return node.nodeType === 1 && (node.matches('[data-notice]') || node.querySelector('[data-notice]'));
            });
        });
        if (!added) { return; }
        queued = true;
        window.requestAnimationFrame(function () { queued = false; paint(); });
    }).observe(document.body, { childList: true, subtree: true });

    /* Back to a tab left open overnight or past the hour. */
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) { paint(); }
    });

    window.OwnifyNotices = { done: done, waits: waits, hour: hour, read: read, paint: paint, on: on };

    paint();
}());
