package com.ownify.android.ui.design

import androidx.compose.runtime.Composable
import androidx.compose.runtime.Immutable
import androidx.compose.runtime.Stable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.drawBehind
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.drawscope.DrawScope
import androidx.compose.ui.layout.onGloballyPositioned
import androidx.compose.ui.layout.positionInRoot
import androidx.compose.ui.unit.toSize
import com.ownify.android.ui.theme.Accent
import com.ownify.android.ui.theme.Ownify

/**
 * One `radial-gradient(<rx>% <ry>% at <x>% <y>%, <color>, transparent <fade>%)`.
 * An [eased] one is a light of theme.css's `--backdrop-lights`: it fades over
 * its whole radius on a smootherstep curve, in nine stops, as the website's.
 */
@Immutable
data class Wash(val rx: Float, val ry: Float, val x: Float, val y: Float, val color: Color, val fade: Float, val eased: Boolean = false, val white: Float = 0.52f)

/** A Randlicht light (`--backdrop-lights`): [alpha] in Dark Mode; drawn × .52 in White ([drawGround]). */
private fun light(rx: Float, ry: Float, x: Float, y: Float, r: Int, g: Int, b: Int, alpha: Float) =
    Wash(rx, ry, x, y, Color(r, g, b).copy(alpha = alpha), 1f, eased = true)

/** The nine stops of a smootherstep fade from [color] to nothing. */
internal fun easedStops(color: Color): Array<Stop> = Array(9) { i ->
    val t = i / 8f
    val s = t * t * t * (t * (t * 6f - 15f) + 10f)
    Stop(t, color.copy(alpha = color.alpha * (1f - s)))
}

/**
 * A page's ground: a few soft washes of colour over the vertical gradient
 * of the theme's ground ([Ownify.BgMain] through [Ownify.BgMid] to [Ownify.BgDeep]). Three exist on
 * the website — the app's (`.app__backdrop`), a detail page's (in its area's
 * accent) and the assistant sheet's (which paints its own light over it). The app's lights are about half as strong in White Mode.
 */
@Immutable
data class Ground(val washes: List<Wash>) {

    companion object {
        /**
         * `.app__backdrop`, fixed behind every page: Randlicht (25.0, Horizon
         * 28.1) — Slaap's indigo and a plum just above the top, Training's
         * coral and an ember just below the foot (at .45 of 25.0), Voeding's
         * green high on the right and low on the left, wide and far beyond
         * the edge, over the ink ground.
         */
        val App = Ground(
            listOf(
                light(0.95f, 0.44f, -0.08f, -0.04f, 104, 108, 175, 0.130f),
                light(0.85f, 0.34f, 0.86f, -0.16f, 135, 97, 142, 0.099f),
                light(0.86f, 0.64f, 1.20f, 0.28f, 84, 145, 120, 0.187f),
                light(0.86f, 0.64f, -0.20f, 0.74f, 84, 145, 120, 0.187f),
                light(1.10f, 0.34f, 0.16f, 1.16f, 175, 121, 112, 0.115f),
                light(0.80f, 0.27f, 0.96f, 1.18f, 183, 132, 100, 0.069f)
            )
        )

        /**
         * `.detail--area` (28.1, Twee randen): Slaap, Voeding and Training —
         * the area's accent as a wide wash from the top centre (16 %, the same
         * in both themes), the ground's green low and even at both middle edges.
         */
        fun areaDetail(accent: Accent) = Ground(
            listOf(
                Wash(1.20f, 0.44f, 0.50f, -0.08f, accent.color.copy(alpha = 0.16f), 1f, eased = true, white = 1f),
                light(0.76f, 0.58f, -0.18f, 0.56f, 84, 145, 120, 0.07f),
                light(0.76f, 0.58f, 1.18f, 0.56f, 84, 145, 120, 0.07f)
            )
        )

        /** `.detail`: the area's accent from the top right. */
        fun detail(accent: Accent) = Ground(
            listOf(
                Wash(0.88f, 0.46f, 0.82f, 0.00f, accent.color.copy(alpha = 0.17f), 0.62f),
                Wash(1.20f, 0.64f, 0.50f, 1.04f, Ownify.AmbientOlive.copy(alpha = 0.06f), 0.62f)
            )
        )

        /**
         * `.sheet--ai`: the theme's ground alone — the sheet draws its own light
         * over it, green from above and a dawn at the foot (AssistantSheet's AiSky).
         */
        val Assistant = Ground(emptyList())
    }
}

/**
 * Draws [ground] over a box of [box] at [origin] (in this scope's
 * coordinates). With [saturate] ≠ 1 every colour goes through CSS
 * `saturate()` first: the matrix is linear, so saturating the stops is the
 * same as saturating the painted ground — what a card's
 * `backdrop-filter: saturate(130%)` does to the ground behind it.
 */
fun DrawScope.drawGround(ground: Ground, box: Size, origin: Offset = Offset.Zero, saturate: Float = 1f, brightness: Float = 1f) {
    val matrix = if (saturate == 1f && brightness == 1f) null else cssSaturateBrightness(saturate, brightness)

    fun tone(color: Color): Color = matrix?.let { apply(it, color) } ?: color

    shifted(origin) {
        drawRect(
            brush = Brush.verticalGradient(
                0f to tone(Ownify.BgMain), 0.5f to tone(Ownify.BgMid), 1f to tone(Ownify.BgDeep),
                startY = 0f, endY = box.height
            ),
            size = box
        )
        for (wash in ground.washes) {
            val color = tone(wash.color)
            if (wash.eased) {
                val lit = if (Ownify.light) color.copy(alpha = color.alpha * wash.white) else color
                cssRadialGradient(wash.rx, wash.ry, wash.x, wash.y, *easedStops(lit), box = box)
            } else {
                cssRadialGradient(
                    wash.rx, wash.ry, wash.x, wash.y,
                    Stop(0f, color),
                    Stop(wash.fade, color.copy(alpha = 0f)),
                    box = box
                )
            }
        }
    }
}

private fun apply(matrix: androidx.compose.ui.graphics.ColorMatrix, color: Color): Color {
    val m = matrix.values
    val r = color.red
    val g = color.green
    val b = color.blue
    return Color(
        red = (m[0] * r + m[1] * g + m[2] * b).coerceIn(0f, 1f),
        green = (m[5] * r + m[6] * g + m[7] * b).coerceIn(0f, 1f),
        blue = (m[10] * r + m[11] * g + m[12] * b).coerceIn(0f, 1f),
        alpha = color.alpha
    )
}

/**
 * The ground a surface sits on, and where it is: set by whatever paints it
 * (the app shell, a detail page, the sheet), read by the cards on it.
 */
@Stable
class GroundPlacement(val ground: Ground) {
    /** The ground's top-left and size in root coordinates. */
    var origin by mutableStateOf(Offset.Zero)
    var size by mutableStateOf(Size.Zero)
}

val LocalGround = staticCompositionLocalOf<GroundPlacement?> { null }

/** Paints [placement]'s ground as this element's background and keeps its position current. */
fun Modifier.ground(placement: GroundPlacement): Modifier =
    this
        .onGloballyPositioned {
            placement.origin = it.positionInRoot()
            placement.size = it.size.toSize()
        }
        .drawBehind { drawGround(placement.ground, size) }

/** A surface's own position, for drawing the ground it sits on under it. */
@Stable
class SurfacePosition {
    var inRoot by mutableStateOf(Offset.Zero)
}

@Composable
fun groundAtHand(): GroundPlacement? = LocalGround.current
