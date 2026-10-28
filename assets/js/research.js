/**
 * research.js — "Uit onderzoek blijkt": reading a publication's PDF
 * (pages/research-detail.php, docs/RESEARCH.md).
 *
 * "Lees de pdf" is a plain link the browser can always follow, to Ownify's
 * own CC BY copy or to the publisher's PDF. Where this browser can show a PDF
 * inside a page (navigator.pdfViewerEnabled) and the publisher allows it
 * (`embed`), the link opens a frame in the detail instead — loaded only then,
 * never before. Everywhere else (Chrome on Android, a publisher that forbids
 * framing) it stays a link: the browser opens the PDF, or downloads it.
 * Offline, nothing loads and the detail says why.
 *
 * Nothing goes through Ownify: the browser fetches the publisher's file
 * itself, and Ownify's own copies are ordinary files.
 */
(function () {
    'use strict';

    var T = window.OwnifyI18n || { t: function (s) { return s; } };

    var canFrame = navigator.pdfViewerEnabled === true;

    /* A browser that cannot show a PDF inside a page reads every "Lees de pdf"
       as what it does here: open it. */
    function label() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-research-read]'), function (link) {
            var inline = link.getAttribute('data-research-read') === 'inline' && canFrame;
            link.textContent = link.getAttribute(inline ? 'data-label-inline' : 'data-label-browser');
        });
    }

    function offline(detail, on) {
        var note = detail.querySelector('[data-research-offline]');
        if (note) { note.hidden = !on; }
    }

    document.addEventListener('click', function (event) {
        var link = event.target.closest('[data-research-read]');
        if (!link) { return; }

        var detail = link.closest('.research-detail');
        if (!detail) { return; }

        if (navigator.onLine === false) {
            event.preventDefault();
            offline(detail, true);
            return;
        }
        offline(detail, false);

        if (link.getAttribute('data-research-read') !== 'inline' || !canFrame) {
            return;   // the browser opens it, in a new tab
        }

        var holder = detail.querySelector('[data-research-frame]');
        if (!holder) { return; }
        event.preventDefault();

        if (!holder.querySelector('iframe')) {
            /* Named after the publication, in its own language. */
            var title = detail.querySelector('[data-research-title]');
            var frame = document.createElement('iframe');
            frame.src = link.getAttribute('href');
            frame.title = title ? title.textContent : T.t('Uit onderzoek blijkt');
            if (title && title.lang) { frame.lang = title.lang; }
            frame.loading = 'lazy';
            frame.referrerPolicy = 'no-referrer';
            holder.appendChild(frame);
        }

        holder.hidden = false;
        holder.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });
    });

    window.addEventListener('online', function () {
        Array.prototype.forEach.call(document.querySelectorAll('.research-detail'), function (detail) { offline(detail, false); });
    });

    label();
})();
