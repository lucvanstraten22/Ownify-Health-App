package com.ownify.android.ui.design

import android.graphics.BlurMaskFilter
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.tween
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.remember
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.drawscope.DrawScope
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.graphics.drawscope.drawIntoCanvas
import androidx.compose.ui.graphics.drawscope.rotate
import androidx.compose.ui.graphics.lerp
import androidx.compose.ui.graphics.nativeCanvas
import androidx.compose.ui.graphics.toArgb
import com.ownify.android.ui.theme.Ownify
import kotlin.math.PI
import kotlin.math.cos
import kotlin.math.pow
import kotlin.math.sin

/*
 * The Gezondheidsscore ring on Overzicht (hero-ring.js on the website):
 * faint where it starts and at its brightest at 100 — the gradient belongs to
 * the circle, so a higher score reaches further into the light. The light it
 * gives off grows with the score and, from 80 up, blooms once as it arrives.
 * It arrives as a light over 2.5 s: a bright head with a fading tail, the
 * colour filling in behind it, one ripple where it stops; a small light stays
 * at the tip. Reduced motion: it is simply there.
 */

private const val TRAVEL = 2500f
private const val SETTLE = 320f
private const val RIPPLE = 700f
private const val BLOOM = 1000f
private const val TOTAL = TRAVEL + BLOOM

/** The whole arrival, in ms: what the pillar rings wait for (hero-ring.js `remaining`). */
const val HeroArrivalMs = 3500

/** Where the arrival is: the ring's share now, and the moments after it. */
class HeroArrival(val shown: Float, val elapsed: Float, val moving: Boolean, val target: Float) {
    /** 0..1 of how far it has come towards the score (the number counts with it). */
    val reached: Float get() = if (target <= 0f) 1f else (shown / target).coerceIn(0f, 1f)
}

@Composable
fun heroArrival(target: Float, play: Boolean): HeroArrival {
    val still = LocalStillMotion.current || LocalReducedMotion.current
    val clock = remember { Animatable(if (still) 1f else 0f) }
    LaunchedEffect(play, still) {
        if (still) clock.snapTo(1f)
        else if (play && clock.value < 1f) clock.animateTo(1f, tween(TOTAL.toInt(), easing = LinearEasing))
    }
    val elapsed = clock.value * TOTAL
    val t = (elapsed / TRAVEL).coerceIn(0f, 1f)
    val eased = 1f - (1f - t).pow(3)
    return HeroArrival(target * eased, elapsed, moving = !still && clock.value < 1f, target = target)
}

private fun clamp01(v: Float) = v.coerceIn(0f, 1f)
private fun easeOut(t: Float) = 1f - (1f - clamp01(t)).pow(3)

/** How far above "good" the score is: 0 at 60 or less, 1 at 100. */
fun ringHeight(share: Float) = clamp01((share * 100f - 60f) / 40f)

/** The light behind the ring and on the number: [k] times the usual, at [scale]. */
class HeroLight(val k: Float, val scale: Float, val glow: Float)

fun heroLight(a: HeroArrival): HeroLight {
    val q = ringHeight(a.target)
    val swell = swell(a)
    return HeroLight(0.55f + 1.25f * q * a.reached + 0.6f * swell, 0.92f + 0.14f * q + 0.05f * swell, q * a.reached)
}

private fun swell(a: HeroArrival): Float =
    if (a.moving && a.target >= 0.8f) sin(PI.toFloat() * clamp01((a.elapsed - TRAVEL + 250f) / BLOOM)) else 0f

/** The colour at x (0..1) of the circle. */
private fun ringAt(x: Float): Color =
    if (x < 0.6f) lerp(Ownify.RingFrom, Ownify.RingMid, x / 0.6f)
    else lerp(Ownify.RingMid, Ownify.RingPeak, ((x - 0.6f) / 0.4f).pow(0.9f))

/** Draws the ring of radius [r] and width [w] (the track included). */
fun DrawScope.drawHeroRing(a: HeroArrival, r: Float, w: Float) {
    drawCircle(color = Ownify.ink(0.08f), radius = r, style = Stroke(w))
    val p = a.shown
    if (p <= 0.0004f) return

    val after = a.elapsed - TRAVEL
    val settle = if (a.moving) easeOut(after / SETTLE) else 1f
    val ripple = if (a.moving) after / RIPPLE else 1f
    val q = ringHeight(a.target)
    val sw = swell(a)
    // While the head travels the arc behind it is only lit faintly.
    val body = if (a.moving) 0.1f + 0.9f * settle else 1f
    val colorAt = { x: Float -> ringAt(x * p).let { it.copy(alpha = it.alpha * body) } }

    // The light it gives off: the arc, blurred, in the app's green.
    val blur = (4f + 16f * q * a.reached + 10f * sw) * (w / 8f)
    val glow = ((if (Ownify.light) 0.35f else 0.55f) + 0.35f * q) * body
    drawIntoCanvas { canvas ->
        val paint = android.graphics.Paint(android.graphics.Paint.ANTI_ALIAS_FLAG).apply {
            style = android.graphics.Paint.Style.STROKE
            strokeWidth = w
            strokeCap = android.graphics.Paint.Cap.ROUND
            color = Ownify.Health.copy(alpha = glow.coerceIn(0f, 1f)).toArgb()
            maskFilter = BlurMaskFilter(blur, BlurMaskFilter.Blur.NORMAL)
        }
        canvas.nativeCanvas.drawArc(center.x - r, center.y - r, center.x + r, center.y + r, -90f, 360f * p.coerceAtMost(1f), false, paint)
    }

    sweepArc(r, w, 0f, p, 36, colorAt = colorAt)

    if (a.moving && settle < 1f) {
        val tail = minOf(p, 0.28f)
        val fade = 1f - settle
        sweepArc(r, w, p - tail, tail, 16, cap = false) { x ->
            lerp(Ownify.RingTail, Ownify.RingHead, x * x).copy(alpha = x.pow(1.3f) * fade)
        }
        bloom(point(p, r), Ownify.RingHead, w * 1.7f, (if (Ownify.light) 0.45f else 0.65f) * fade)
    }
    if (a.moving && ripple > 0f && ripple < 1f) {
        drawCircle(
            color = ringAt(p).copy(alpha = 0.6f * (1f - easeOut(ripple))),
            radius = w * (0.5f + 1.9f * easeOut(ripple)),
            center = point(p, r),
            style = Stroke(1.5f * w / 8f)
        )
    }
    // At rest: a small light at the tip, where the score is.
    if (p > 0.0016f) drawCircle(Ownify.RingHead.copy(alpha = 0.92f), radius = w * 0.2f, center = point(p, r))
}

/** A point at [turn] (0..1 clockwise from twelve) on radius [r]. */
private fun DrawScope.point(turn: Float, r: Float): Offset {
    val a = -PI.toFloat() / 2f + turn * 2f * PI.toFloat()
    return Offset(center.x + cos(a) * r, center.y + sin(a) * r)
}

private fun DrawScope.bloom(at: Offset, color: Color, radius: Float, strength: Float) {
    drawCircle(
        brush = Brush.radialGradient(0f to color.copy(alpha = strength), 1f to color.copy(alpha = 0f), center = at, radius = radius),
        radius = radius,
        center = at
    )
}

/**
 * An arc from [a0] over [sweep] (turns, clockwise from twelve), coloured along
 * its length by [colorAt] — a sweep gradient with round ends, whose colour past
 * the end and before the start is the end's and the start's own.
 */
private fun DrawScope.sweepArc(r: Float, w: Float, a0: Float, sweep: Float, n: Int, cap: Boolean = true, colorAt: (Float) -> Color) {
    if (sweep <= 0.0004f) return
    val capTurn = (w / 2f) / r / (2f * PI.toFloat())
    val closed = sweep > 1f - 2.4f * capTurn
    val tail = sweep.coerceAtMost(1f)
    val stops = ArrayList<Pair<Float, Color>>()
    for (i in 0..n) stops += (i.toFloat() / n * tail).coerceAtMost(1f) to colorAt(i.toFloat() / n)
    if (!closed) {
        val e = capTurn * 1.15f
        stops += (tail + e) to colorAt(1f)
        stops += (1f - e) to colorAt(0f)
        stops += 1f to colorAt(0f)
    }
    rotate(-90f + a0 * 360f) {
        drawArc(
            brush = Brush.sweepGradient(*stops.toTypedArray(), center = center),
            startAngle = 0f,
            sweepAngle = 360f * tail,
            useCenter = false,
            topLeft = Offset(center.x - r, center.y - r),
            size = androidx.compose.ui.geometry.Size(r * 2, r * 2),
            style = Stroke(w, cap = if (!cap || closed) StrokeCap.Butt else StrokeCap.Round)
        )
    }
    if (closed && cap) {
        // Closed: the end passes over its own start, as on a watch.
        drawCircle(colorAt(0f), radius = w / 2f, center = point(a0, r))
        val end = point(a0 + sweep, r)
        drawCircle(Ownify.shade(0.3f), radius = w * 0.62f, center = end + Offset(w * 0.12f, w * 0.12f))
        drawCircle(colorAt(1f), radius = w / 2f, center = end)
    }
}
