package com.ownify.android.ui.app

import androidx.compose.foundation.gestures.awaitEachGesture
import androidx.compose.foundation.gestures.awaitFirstDown
import androidx.compose.runtime.Stable
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Rect
import androidx.compose.ui.input.pointer.PointerEventPass
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.layout.boundsInRoot
import androidx.compose.ui.layout.onGloballyPositioned
import kotlin.coroutines.cancellation.CancellationException
import kotlin.math.abs

/**
 * navigation-core.js, for Compose: one gesture pipeline, two axes.
 *
 *   horizontal → the page rail, or closing a detail page
 *   vertical   → opening or closing the assistant sheet
 *
 * A gesture is routed once, when its axis is clear, and never re-routed; one
 * that is not ours (a page scroll) is left to the scroller for good. Every
 * distance is in dp, the website's px, and every rule below is the website's.
 */
object GestureRules {
    const val LOCK = 8f          // dp of travel before an axis is chosen
    const val RATIO = 1.15f      // how clearly that axis must win…
    const val FORCE = 24f        // …until this much travel, when the larger one simply wins

    const val FLICK = 0.3f       // dp/ms at the lift: a flick completes…
    const val FLICK_MIN = 16f    // …once the finger has travelled this far
    const val QUICK_MS = 300L    // a whole swipe this short completes…
    const val QUICK_MIN = 32f    // …once the finger has travelled this far
    const val SHARE = 0.25f      // otherwise past this share of the screen,
    const val HELD_SHARE = 0.5f  // or, released after being held still, past half
    const val HOLD_MS = 160L     // this still before the lift counts as held
    const val HOLD_SLOP = 4f     // dp a held finger may still wander
    const val VELOCITY_MS = 90L  // the lift's velocity is measured over this last stretch,
    const val TURN_MS = 40L      // unless the finger clearly turned back within this one:
    const val TURN_MIN = 8f      // at least this far, over at least
    const val TURN_SPAN = 24L    // this long

    /**
     * Where a released drag goes: one step on from [base], or back to it —
     * the same answer for the rail, the sheet and a detail page.
     *
     *   q     where the layer is at the lift (positions run against the finger)
     *   d, v  the finger's travel and velocity on the axis (dp, dp/ms)
     *   size  the length of one step, dp
     */
    fun resolve(ctx: GestureContext, base: Float, q: Float, d: Float, v: Float, size: Float): Float {
        val toward = when {
            d < 0 -> 1
            d > 0 -> -1
            q > base -> 1
            q < base -> -1
            else -> 0
        }
        if (toward == 0) return base

        val travel = abs(d)
        val along = (q - base) * toward * size
        val speed = -v * toward
        val share = if (ctx.held || speed < -0.1f) HELD_SHARE else SHARE

        val completes = when {
            speed >= FLICK -> travel >= FLICK_MIN
            speed <= -FLICK -> false
            else -> (ctx.duration <= QUICK_MS && travel >= QUICK_MIN) || along >= size * share
        }

        return if (completes) base + toward else base
    }

    /** Which axis a gesture is on, once it is clear; null while it is not. */
    fun axisOf(dx: Float, dy: Float): Axis? {
        val ax = abs(dx)
        val ay = abs(dy)
        val travel = maxOf(ax, ay)
        return when {
            travel < LOCK -> null
            ax > ay * RATIO -> Axis.X
            ay > ax * RATIO -> Axis.Y
            travel >= FORCE -> if (ax >= ay) Axis.X else Axis.Y
            else -> null
        }
    }
}

enum class Axis { X, Y }

/** One moment of a gesture, in dp and ms. */
data class GestureContext(
    val startX: Float,
    val startY: Float,
    val dx: Float,
    val dy: Float,
    val vx: Float = 0f,
    val vy: Float = 0f,
    val width: Float,
    val height: Float,
    val duration: Long,
    val held: Boolean = false
)

/** A layer that moves with a finger: `{ canStart, begin, move, end, cancel }`. After begin, exactly one of end or cancel follows. */
interface AxisController {
    fun canStart(ctx: GestureContext): Boolean
    fun begin(ctx: GestureContext)
    fun move(ctx: GestureContext)
    fun end(ctx: GestureContext)
    fun cancel()
}

/**
 * Elements that read horizontal drags themselves — the goal chart, scrubbed
 * with a finger — are not the deck's to route (`data-gesture-own`).
 */
@Stable
class OwnedAreas {
    private val areas = HashMap<Any, Rect>()

    fun set(key: Any, rect: Rect?) {
        if (rect == null) areas.remove(key) else areas[key] = rect
    }

    fun contains(point: Offset): Boolean = areas.values.any { it.contains(point) }
}

fun Modifier.ownsGestures(areas: OwnedAreas, key: Any): Modifier =
    onGloballyPositioned { areas.set(key, it.boundsInRoot()) }

/**
 * The deck's input: every touch that starts on it is watched from touch-down
 * (before the pages see it), and once it is clearly horizontal or vertical
 * it is offered to the controllers for that axis, in order. The first that
 * takes it owns it until the lift; the pages below never see it again, so a
 * swipe is never also a scroll or a tap.
 */
fun Modifier.deckGestures(
    controllers: () -> Map<Axis, List<AxisController>>,
    owned: OwnedAreas,
    density: () -> Float
): Modifier = pointerInput(Unit) {
    awaitEachGesture {
        val down = awaitFirstDown(requireUnconsumed = false, pass = PointerEventPass.Initial)
        if (owned.contains(down.position)) return@awaitEachGesture

        val px = density()
        val start = down.position
        val startTime = down.uptimeMillis
        val samples = ArrayList<Sample>()
        samples += Sample(start.x / px, start.y / px, startTime)
        val width = size.width / px
        val height = size.height / px

        var controller: AxisController? = null
        var decided = false

        fun context(atLift: Boolean): GestureContext {
            val last = samples.last()
            val ctx = GestureContext(
                startX = start.x / px,
                startY = start.y / px,
                dx = last.x - start.x / px,
                dy = last.y - start.y / px,
                width = width,
                height = height,
                duration = last.t - startTime
            )
            return if (atLift) measure(samples, ctx) else ctx
        }

        try {
            while (true) {
                val event = awaitPointerEvent(PointerEventPass.Initial)
                val change = event.changes.firstOrNull { it.id == down.id }

                if (change == null) {
                    // The finger was taken away without a lift: back to where it belongs.
                    controller?.cancel()
                    return@awaitEachGesture
                }

                // A second finger before anything started is the system's (a pinch).
                if (controller == null && event.changes.count { it.pressed } > 1) return@awaitEachGesture

                samples += Sample(change.position.x / px, change.position.y / px, change.uptimeMillis)
                // Only the last stretch is ever looked at.
                while (samples.size > 2 && change.uptimeMillis - samples[0].t > 400) samples.removeAt(0)

                if (!change.pressed) {
                    val owner = controller ?: return@awaitEachGesture
                    change.consume()
                    owner.end(context(atLift = true))
                    return@awaitEachGesture
                }

                val owner = controller
                if (owner != null) {
                    change.consume()
                    owner.move(context(atLift = false))
                    continue
                }

                if (decided) continue

                val ctx = context(atLift = false)
                val axis = GestureRules.axisOf(ctx.dx, ctx.dy) ?: continue
                decided = true

                val claimed = controllers()[axis].orEmpty().firstOrNull { it.canStart(ctx) } ?: continue
                controller = claimed
                change.consume()
                claimed.begin(ctx)
                claimed.move(ctx)
            }
        } catch (e: CancellationException) {
            controller?.cancel()
            throw e
        }
    }
}

private class Sample(val x: Float, val y: Float, val t: Long)

/** Velocity at the lift on both axes, and whether the finger was held still before it. */
private fun measure(samples: List<Sample>, ctx: GestureContext): GestureContext {
    val now = samples.last().t
    val vx = velocityOn(samples, now) { it.x }
    val vy = velocityOn(samples, now) { it.y }

    var i = samples.size - 1
    while (i >= 0 && now - samples[i].t < GestureRules.HOLD_MS) i--

    var held = false
    if (i >= 0) {
        val anchor = samples[i]
        held = (i + 1 until samples.size).none { j ->
            abs(samples[j].x - anchor.x) > GestureRules.HOLD_SLOP || abs(samples[j].y - anchor.y) > GestureRules.HOLD_SLOP
        }
    }

    return ctx.copy(vx = vx, vy = vy, held = held)
}

private class Stretch(val d: Float, val t: Long, val v: Float)

/** Travel and velocity on one axis over the last [span] ms. */
private fun stretch(samples: List<Sample>, now: Long, span: Long, key: (Sample) -> Float): Stretch {
    val last = samples.last()
    var oldest: Sample? = null
    var i = samples.size - 1
    while (i >= 0 && now - samples[i].t <= span) {
        oldest = samples[i]
        i--
    }
    if (oldest == null || now - oldest.t < 8) return Stretch(0f, 0, 0f)
    val d = key(last) - key(oldest)
    val t = now - oldest.t
    return Stretch(d, t, d / t)
}

/**
 * The finger's velocity at the lift: averaged over the last stretch, so one
 * jittery event cannot fake a flick, and zero for a finger that stopped
 * before lifting. A finger that turned back at the very end is going where
 * it turned.
 */
private fun velocityOn(samples: List<Sample>, now: Long, key: (Sample) -> Float): Float {
    val whole = stretch(samples, now, GestureRules.VELOCITY_MS, key)
    val late = stretch(samples, now, GestureRules.TURN_MS, key)
    val turned = late.t >= GestureRules.TURN_SPAN && abs(late.d) >= GestureRules.TURN_MIN && late.v * whole.v < 0
    return if (turned) late.v else whole.v
}
