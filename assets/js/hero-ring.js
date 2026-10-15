/* ==========================================================================
   Ownify — the Gezondheidsscore ring on Overzicht (components/health-score.php)

   Faint where it starts and at its brightest at 100: the gradient belongs to
   the circle, so a higher score reaches further into the light. The light it
   gives off grows with the score, and from 80 up it blooms once as the ring
   arrives. It arrives as a light — a bright head with a fading tail, the
   colour filling in behind it, one ripple where it stops — and a small light
   stays at the tip. Drawn on a canvas, as Compose draws it (Components.kt
   ScoreRing, glow = true); the SVG stays underneath as the fallback.

   Colours: --ring-from, --ring-mid, --ring-peak, --ring-head, --ring-tail
   (theme.css). Reduced motion: the ring is simply there, a short fade.
   ========================================================================== */
(function () {
    'use strict';

    var TAU = Math.PI * 2;
    var TRAVEL = 2500;          /* the head's way to the score */
    var SETTLE = 320;           /* the colour filling in behind it */
    var RIPPLE = 700;
    var BLOOM = 1000;
    var reduceQuery = window.matchMedia('(prefers-reduced-motion: reduce)');

    /* -------------------------------------------------------------- colour */

    var probe = null;
    var pxc = null;

    /** Any CSS colour (a token, a color-mix) as [r, g, b, a]. */
    function rgb(value) {
        if (!probe) {
            probe = document.createElement('span');
            probe.style.display = 'none';
            document.body.appendChild(probe);
            var px = document.createElement('canvas');
            px.width = px.height = 1;
            pxc = px.getContext('2d', { willReadFrequently: true });
        }
        probe.style.color = '';
        probe.style.color = value;
        pxc.clearRect(0, 0, 1, 1);
        pxc.fillStyle = '#000';
        pxc.fillStyle = getComputedStyle(probe).color;
        pxc.fillRect(0, 0, 1, 1);
        var d = pxc.getImageData(0, 0, 1, 1).data;
        return [d[0], d[1], d[2], d[3] / 255];
    }

    function lin(c) { c /= 255; return c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4); }
    function delin(c) {
        c = c <= 0.0031308 ? 12.92 * c : 1.055 * Math.pow(c, 1 / 2.4) - 0.055;
        return Math.max(0, Math.min(255, Math.round(c * 255)));
    }
    function lab(c) {
        var r = lin(c[0]), g = lin(c[1]), b = lin(c[2]);
        var l = Math.cbrt(0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b);
        var m = Math.cbrt(0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b);
        var s = Math.cbrt(0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b);
        return [0.2104542553 * l + 0.7936177850 * m - 0.0040720468 * s,
                1.9779984951 * l - 2.4285922050 * m + 0.4505937099 * s,
                0.0259040371 * l + 0.7827717662 * m - 0.8086757660 * s];
    }
    function unlab(L) {
        var l = L[0] + 0.3963377774 * L[1] + 0.2158037573 * L[2];
        var m = L[0] - 0.1055613458 * L[1] - 0.0638541728 * L[2];
        var s = L[0] - 0.0894841775 * L[1] - 1.2914855480 * L[2];
        l = l * l * l; m = m * m * m; s = s * s * s;
        return [delin(4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s),
                delin(-1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s),
                delin(-0.0041960863 * l - 0.5115414066 * m + 1.2962634700 * s)];
    }
    /** Mixed in OKLab (Compose's lerp does the same), so the light stays even. */
    function mix(a, b, t) {
        t = clamp01(t);
        var A = lab(a), B = lab(b);
        var c = unlab([A[0] + (B[0] - A[0]) * t, A[1] + (B[1] - A[1]) * t, A[2] + (B[2] - A[2]) * t]);
        c.push(a[3] + (b[3] - a[3]) * t);
        return c;
    }
    function alpha(c, a) { return [c[0], c[1], c[2], c[3] * a]; }
    function css(c) { return 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',' + (+c[3].toFixed(3)) + ')'; }
    function clamp01(v) { return Math.max(0, Math.min(1, v)); }
    function easeOut(t) { t = clamp01(t); return 1 - Math.pow(1 - t, 3); }

    var pal = null;
    function palette() {
        pal = {
            from: rgb('var(--ring-from)'), mid: rgb('var(--ring-mid)'), peak: rgb('var(--ring-peak)'),
            head: rgb('var(--ring-head)'), tail: rgb('var(--ring-tail)'),
            health: rgb('var(--health)'), track: rgb('rgba(var(--ink), 0.08)'),
            capShadow: rgb('rgba(var(--shade), 0.45)'),
            light: document.documentElement.getAttribute('data-theme') === 'light'
        };
    }

    /** The colour at x (0..1) of the circle: faint, the green, then the light. */
    function at(x) {
        return x < 0.6 ? mix(pal.from, pal.mid, x / 0.6) : mix(pal.mid, pal.peak, Math.pow((x - 0.6) / 0.4, 0.9));
    }

    /** How far above "good" the score is: 0 at 60 or less, 1 at 100. */
    function height(share) { return clamp01((share * 100 - 60) / 40); }

    /* ------------------------------------------------------------- drawing */

    function draw(ring, state, now) {
        var canvas = state.canvas, ctx = state.ctx;
        var s = canvas.clientWidth;
        if (!s) { return; }
        var dpr = Math.min(3, window.devicePixelRatio || 1);
        if (canvas.width !== Math.round(s * dpr)) { canvas.width = canvas.height = Math.round(s * dpr); }
        var k = ring.clientWidth / 160;
        var c = s / 2, R = 68 * k, w = 8 * k;

        function point(angle, radius) {
            var a = -Math.PI / 2 + angle;
            return [c + Math.cos(a) * radius, c + Math.sin(a) * radius];
        }
        function dot(angle, r, color) {
            var p = point(angle, R);
            ctx.beginPath();
            ctx.arc(p[0], p[1], r, 0, TAU);
            ctx.fillStyle = css(color);
            ctx.fill();
        }
        function bloom(angle, color, radius, strength) {
            var p = point(angle, R);
            var gr = ctx.createRadialGradient(p[0], p[1], 0, p[0], p[1], radius);
            gr.addColorStop(0, css(alpha(color, strength)));
            gr.addColorStop(1, css(alpha(color, 0)));
            ctx.fillStyle = gr;
            ctx.beginPath();
            ctx.arc(p[0], p[1], radius, 0, TAU);
            ctx.fill();
        }
        /* An arc from a0 over sweep, coloured along its length by colorAt(t):
           a sweep gradient with round ends (Brush.sweepGradient in Compose). */
        function arc(a0, sweep, colorAt, opts) {
            if (sweep <= 0.0004) { return; }
            opts = opts || {};
            var cap = (w / 2) / R;
            var closed = sweep > TAU - 2.4 * cap;
            var grad = ctx.createConicGradient(-Math.PI / 2 + a0, c, c);
            var n = opts.stops || 28, tail = Math.min(1, sweep / TAU);
            for (var i = 0; i <= n; i++) { grad.addColorStop(Math.min(1, (i / n) * tail), css(colorAt(i / n))); }
            if (!closed) {
                /* Past the end its round end; before the start, the start's. */
                var e = cap / TAU * 1.15;
                grad.addColorStop(tail + e, css(colorAt(1)));
                grad.addColorStop(1 - e, css(colorAt(0)));
                grad.addColorStop(1, css(colorAt(0)));
            }
            ctx.beginPath();
            ctx.arc(c, c, R, -Math.PI / 2 + a0, -Math.PI / 2 + a0 + Math.min(sweep, TAU));
            ctx.strokeStyle = grad;
            ctx.lineWidth = w;
            ctx.lineCap = opts.cap === false || closed ? 'butt' : 'round';
            ctx.stroke();
            ctx.lineCap = 'butt';
            if (closed && opts.cap !== false) {
                /* Closed: the end passes over its own start, as on a watch. */
                dot(a0, w / 2, colorAt(0));
                ctx.save();
                if (opts.shadow !== false) {
                    ctx.shadowColor = css(pal.capShadow);
                    ctx.shadowBlur = w * 0.9 * dpr;
                    ctx.shadowOffsetX = Math.cos(a0 + sweep) * w * 0.25 * dpr;
                    ctx.shadowOffsetY = Math.sin(a0 + sweep) * w * 0.25 * dpr;
                }
                dot(a0 + sweep, w / 2, colorAt(1));
                ctx.restore();
            }
        }

        var elapsed = now - state.start;
        var moving = state.moving;
        var t = moving ? clamp01(elapsed / state.duration) : 1;
        var p = state.from + (state.to - state.from) * easeOut(t);
        state.shown = p;
        var after = elapsed - state.duration;
        var settle = moving ? easeOut(after / SETTLE) : 1;
        var ripple = moving ? after / RIPPLE : 1;
        var q = height(state.to);
        var reached = clamp01(p / Math.max(0.0001, state.to));
        var swell = moving && state.to >= 0.8 ? Math.sin(Math.PI * clamp01((after + 250) / BLOOM)) : 0;

        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, s, s);

        ctx.beginPath();
        ctx.arc(c, c, R, 0, TAU);
        ctx.strokeStyle = css(pal.track);
        ctx.lineWidth = w;
        ctx.stroke();

        var sweep = p * TAU;
        /* While the head travels the arc behind it is only lit faintly. */
        var body = moving && state.fresh ? 0.1 + 0.9 * settle : 1;
        var colorAt = function (x) { return alpha(at(x * p), body); };

        ctx.save();
        ctx.shadowColor = css(alpha(pal.health, ((pal.light ? 0.35 : 0.55) + 0.35 * q) * body));
        ctx.shadowBlur = (4 + 16 * q * reached + 10 * swell) * k * dpr;
        arc(0, sweep, colorAt, { stops: 36 });
        ctx.restore();
        arc(0, sweep, colorAt, { stops: 36 });

        if (moving && settle < 1) {
            var tailLen = Math.min(sweep, TAU * 0.28), fade = 1 - settle;
            arc(sweep - tailLen, tailLen, function (x) {
                return alpha(mix(pal.tail, pal.head, x * x), Math.pow(x, 1.3) * fade);
            }, { cap: false, stops: 16 });
            bloom(sweep, pal.head, w * 1.7, (pal.light ? 0.45 : 0.65) * fade);
        }
        if (moving && ripple > 0 && ripple < 1) {
            var e = point(sweep, R);
            ctx.beginPath();
            ctx.arc(e[0], e[1], w * (0.5 + 1.9 * easeOut(ripple)), 0, TAU);
            ctx.strokeStyle = css(alpha(at(p), 0.6 * (1 - easeOut(ripple))));
            ctx.lineWidth = 1.5 * k;
            ctx.stroke();
        }
        /* At rest: a small light at the tip, where the score is. */
        if (sweep > 0.01) { dot(sweep, w * 0.2, alpha(pal.head, 0.92)); }

        ring.style.setProperty('--ring-halo-k', String(0.55 + 1.25 * q * reached + 0.6 * swell));
        ring.style.setProperty('--ring-halo-scale', String(0.92 + 0.14 * q + 0.05 * swell));
        ring.style.setProperty('--ring-glow', String(q * reached));

        /* The number counts with the ring. */
        if (state.number && state.count) {
            var v = Math.round(p * state.max);
            state.number.textContent = window.OwnifyI18n ? window.OwnifyI18n.number(v, 0, false) : String(v);
        }
        return moving && elapsed < state.duration + Math.max(SETTLE, RIPPLE, BLOOM);
    }

    function loop(ring) {
        var state = ring.__heroRing;
        if (!state || state.raf) { return; }
        state.raf = window.requestAnimationFrame(function step(now) {
            state.raf = 0;
            if (!ring.isConnected) { return; }
            if (draw(ring, state, now)) { state.raf = window.requestAnimationFrame(step); }
        });
    }

    /* --------------------------------------------------------------- play */

    /**
     * Draws the ring to its data-progress: from nothing (fresh), or from
     * `from` (0..1, where a ring it replaces stood), so a changed score moves
     * rather than starting again.
     */
    function play(ring, from) {
        if (!window.CanvasRenderingContext2D || !CanvasRenderingContext2D.prototype.createConicGradient) { return false; }
        if (ring.closest('.is-empty')) { return false; }       // no score yet: the calm dotted track
        if (!pal) { palette(); }
        var state = ring.__heroRing;
        if (!state) {
            var canvas = document.createElement('canvas');
            canvas.className = 'score-ring__canvas';
            canvas.setAttribute('aria-hidden', 'true');
            ring.insertBefore(canvas, ring.querySelector('.score-ring__center'));
            state = ring.__heroRing = { canvas: canvas, ctx: canvas.getContext('2d'), shown: 0, raf: 0 };
            ring.classList.add('has-canvas');
        }
        var number = ring.querySelector('[data-count-to]');
        var raw = number ? number.getAttribute('data-count-to') : '';
        var fresh = from == null;
        var still = reduceQuery.matches;

        state.number = number;
        state.count = raw !== null && raw !== '' && fresh;
        state.max = raw ? parseFloat(raw) / Math.max(0.0001, parseFloat(ring.getAttribute('data-progress'))) : 100;
        state.from = fresh ? 0 : clamp01(from);
        state.to = clamp01(parseFloat(ring.getAttribute('data-progress')) || 0);
        state.start = performance.now();
        state.duration = fresh ? TRAVEL : 1100;
        state.fresh = fresh;
        state.moving = !still;

        if (still && fresh) {
            state.canvas.classList.remove('is-fading');
            void state.canvas.offsetWidth;
            state.canvas.classList.add('is-fading');
        }
        if (state.number && state.count && !still) { state.number.textContent = '0'; }
        loop(ring);
        return true;
    }

    function shown(ring) { return ring && ring.__heroRing ? ring.__heroRing.shown : null; }

    /* A theme change repaints the rings already drawn. */
    new MutationObserver(function () {
        if (!pal) { return; }
        palette();
        Array.prototype.forEach.call(document.querySelectorAll('.score-ring.has-canvas'), function (ring) {
            var state = ring.__heroRing;
            draw(ring, state, state.start + state.duration + 5000);
        });
    }).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

    window.OwnifyHeroRing = { play: play, shown: shown };
}());
