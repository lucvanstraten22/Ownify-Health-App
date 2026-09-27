package com.healthapp.android.ui.design

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
import com.healthapp.android.ui.theme.Accent
import com.healthapp.android.ui.theme.Jolu

/** One `radial-gradient(<rx>% <ry>% at <x>% <y>%, <color>, transparent <fade>%)`. */
@Immutable
data class Wash(val rx: Float, val ry: Float, val x: Float, val y: Float, val color: Color, val fade: Float)

/**
 * A page's ground: a few soft washes of colour over the dark vertical
 * gradient. Three exist on the website — the app's (`.app__backdrop`), a
 * detail page's (in its area's accent) and the assistant sheet's.
 */
@Immutable
data class Ground(val washes: List<Wash>) {

    companion object {
        /** `.app__backdrop`, fixed behind every page. */
        val App = Ground(
            listOf(
                Wash(0.90f, 0.55f, 0.12f, 0.00f, Jolu.Health.copy(alpha = 0.14f), 0.62f),
                Wash(0.80f, 0.50f, 0.96f, 0.12f, Jolu.Nutrition.copy(alpha = 0.10f), 0.58f),
                Wash(1.20f, 0.70f, 0.50f, 1.04f, Jolu.Activity.copy(alpha = 0.08f), 0.60f)
            )
        )

        /** `.detail`: the area's accent from the top right. */
        fun detail(accent: Accent) = Ground(
            listOf(
                Wash(0.88f, 0.46f, 0.82f, 0.00f, accent.color.copy(alpha = 0.17f), 0.62f),
                Wash(1.20f, 0.64f, 0.50f, 1.04f, Jolu.Activity.copy(alpha = 0.06f), 0.62f)
            )
        )

        /** `.sheet--ai`: the app's ground, mirrored. */
        val Assistant = Ground(
            listOf(
                Wash(0.86f, 0.48f, 0.80f, 0.02f, Jolu.Health.copy(alpha = 0.16f), 0.62f),
                Wash(0.72f, 0.44f, 0.10f, 0.24f, Jolu.Nutrition.copy(alpha = 0.08f), 0.60f),
                Wash(1.20f, 0.64f, 0.50f, 1.04f, Jolu.Activity.copy(alpha = 0.08f), 0.62f)
            )
        )
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
            brush = Brush.verticalGradient(listOf(tone(Jolu.BgMain), tone(Jolu.BgDeep)), startY = 0f, endY = box.height),
            size = box
        )
        for (wash in ground.washes) {
            val color = tone(wash.color)
            cssRadialGradient(
                wash.rx, wash.ry, wash.x, wash.y,
                Stop(0f, color),
                Stop(wash.fade, color.copy(alpha = 0f)),
                box = box
            )
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
