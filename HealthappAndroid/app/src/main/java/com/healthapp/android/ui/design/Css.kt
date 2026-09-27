package com.healthapp.android.ui.design

import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.addOutline
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.ClipOp
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.ColorMatrix
import androidx.compose.ui.graphics.Outline
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.PathOperation
import androidx.compose.ui.graphics.Shape
import androidx.compose.ui.graphics.drawscope.DrawScope
import androidx.compose.ui.graphics.drawscope.clipPath
import androidx.compose.ui.graphics.drawscope.scale
import androidx.compose.ui.graphics.drawscope.translate
import androidx.compose.ui.graphics.shadow.Shadow
import androidx.compose.ui.graphics.shadow.ShadowContext
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.DpOffset
import androidx.compose.ui.unit.dp
import kotlin.math.abs
import kotlin.math.cos
import kotlin.math.sin

/**
 * The few CSS drawing primitives the website's stylesheets are written in,
 * drawn the way a browser draws them — so a gradient at 152deg, an ellipse
 * at "57% 94% at 24.2% 0%" or a `box-shadow` land where the stylesheet says.
 */

/** One colour stop: `rgba(…) 44%`. */
data class Stop(val at: Float, val color: Color)

/**
 * `linear-gradient(<angle>deg, …)` over a box of [size]: 0deg points up and
 * angles turn clockwise; the line is as long as the box needs for its corners
 * to reach the first and last stop, as in CSS.
 */
fun cssLinearGradient(angleDeg: Float, size: Size, vararg stops: Stop): Brush {
    val rad = Math.toRadians(angleDeg.toDouble())
    val dx = sin(rad).toFloat()
    val dy = -cos(rad).toFloat()
    val half = (abs(size.width * dx) + abs(size.height * dy)) / 2f
    val center = Offset(size.width / 2f, size.height / 2f)

    return Brush.linearGradient(
        colorStops = stops.map { it.at to it.color }.toTypedArray(),
        start = center - Offset(dx * half, dy * half),
        end = center + Offset(dx * half, dy * half)
    )
}

/**
 * `radial-gradient(<rx>% <ry>% at <x>% <y>%, …)` over the box: an ellipse
 * with its radii as shares of the box's width and height, drawn by scaling a
 * circular gradient vertically.
 */
fun DrawScope.cssRadialGradient(
    rx: Float,
    ry: Float,
    atX: Float,
    atY: Float,
    vararg stops: Stop,
    box: Size = size
) {
    val radiusX = rx * box.width
    val radiusY = ry * box.height
    if (radiusX <= 0f || radiusY <= 0f) return

    val center = Offset(atX * box.width, atY * box.height)
    val brush = Brush.radialGradient(
        colorStops = stops.map { it.at to it.color }.toTypedArray(),
        center = center,
        radius = radiusX
    )
    val ratio = radiusY / radiusX

    scale(scaleX = 1f, scaleY = ratio, pivot = center) {
        // The gradient's last stop continues to the box's edges, as in CSS.
        drawRect(
            brush = brush,
            topLeft = Offset(0f, center.y - (center.y) / ratio),
            size = Size(box.width, box.height / ratio)
        )
    }
}

/** `saturate(s) brightness(b)` as one colour matrix, in that order. */
fun cssSaturateBrightness(saturation: Float, brightness: Float = 1f): ColorMatrix {
    val s = saturation
    val m = floatArrayOf(
        0.213f + 0.787f * s, 0.715f - 0.715f * s, 0.072f - 0.072f * s, 0f, 0f,
        0.213f - 0.213f * s, 0.715f + 0.285f * s, 0.072f - 0.072f * s, 0f, 0f,
        0.213f - 0.213f * s, 0.715f - 0.715f * s, 0.072f + 0.928f * s, 0f, 0f,
        0f, 0f, 0f, 1f, 0f
    )
    if (brightness != 1f) {
        for (row in 0 until 3) for (col in 0 until 3) m[row * 5 + col] *= brightness
    }
    return ColorMatrix(m)
}

/** One `box-shadow` layer: `<x> <y> <blur> <color>` (outer), or `inset …`. */
data class BoxShadow(
    val x: Dp = 0.dp,
    val y: Dp,
    val blur: Dp,
    val color: Color,
    val spread: Dp = 0.dp,
    val inset: Boolean = false
)

/**
 * Draws a list of `box-shadow` layers for [shape] the way a browser does:
 * outer shadows only outside the box (a translucent box shows none through
 * itself), inset shadows only inside it. [context] is
 * `LocalGraphicsContext.current.shadowContext`.
 */
fun DrawScope.drawBoxShadows(
    shape: Shape,
    shadows: List<BoxShadow>,
    context: ShadowContext?,
    outline: Outline = shape.createOutline(size, layoutDirection, this)
) {
    val box = Path().apply { addOutline(outline) }

    for (shadow in shadows) {
        if (shadow.inset) {
            if (shadow.blur.value == 0f && shadow.spread.value == 0f) {
                // A hairline of light: the box minus itself moved by the offset.
                val moved = Path().apply {
                    addPath(box, Offset(shadow.x.toPx(), shadow.y.toPx()))
                }
                val band = Path().apply { op(box, moved, PathOperation.Difference) }
                drawPath(band, shadow.color)
            } else if (context != null) {
                val painter = context.createInnerShadowPainter(
                    shape,
                    Shadow(
                        radius = cssShadowRadius(shadow.blur),
                        color = shadow.color,
                        spread = shadow.spread,
                        offset = DpOffset(shadow.x, shadow.y)
                    )
                )
                with(painter) { draw(size) }
            }
            continue
        }

        if (context == null) continue

        val painter = context.createDropShadowPainter(
            shape,
            Shadow(
                radius = cssShadowRadius(shadow.blur),
                color = shadow.color,
                spread = shadow.spread,
                offset = DpOffset(shadow.x, shadow.y)
            )
        )

        clipPath(box, ClipOp.Difference) {
            with(painter) { draw(size) }
        }
    }
}

/**
 * A `box-shadow` blur radius B blurs with a Gaussian of deviation B/2. A
 * Compose [Shadow] radius is Android's blur radius, which Skia turns into a
 * deviation as 0.57735 × r + 0.5 — so r is that, turned back.
 */
internal fun DrawScope.cssShadowRadius(blur: Dp): Dp {
    val sigma = blur.toPx() / 2f
    return if (sigma <= 0.5f) 0.dp else ((sigma - 0.5f) / 0.57735f).toDp()
}

/** Draws [block] shifted by [offset], for layers laid out elsewhere. */
inline fun DrawScope.shifted(offset: Offset, block: DrawScope.() -> Unit) =
    translate(offset.x, offset.y, block)
