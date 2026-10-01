package com.ownify.android.ui.design

import android.os.Build
import androidx.annotation.RequiresApi
import androidx.compose.runtime.Composable
import androidx.compose.runtime.Stable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.drawWithContent
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.addOutline
import androidx.compose.ui.graphics.ClipOp
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.Shape
import androidx.compose.ui.graphics.asAndroidColorFilter
import androidx.compose.ui.graphics.asComposeRenderEffect
import androidx.compose.ui.graphics.ColorFilter
import androidx.compose.ui.graphics.drawscope.DrawScope
import androidx.compose.ui.graphics.drawscope.clipPath
import androidx.compose.ui.graphics.drawscope.translate
import androidx.compose.ui.graphics.layer.GraphicsLayer
import androidx.compose.ui.graphics.layer.drawLayer
import androidx.compose.ui.graphics.rememberGraphicsLayer
import androidx.compose.ui.layout.LayoutCoordinates
import androidx.compose.ui.layout.onGloballyPositioned
import androidx.compose.ui.layout.positionInRoot
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.IntSize
import androidx.compose.ui.unit.dp
import com.ownify.android.ui.theme.Ownify
import kotlin.math.ceil

/**
 * `backdrop-filter`, for the surfaces that need to see what is behind them.
 *
 * A [Backdrop] is a layer of the screen recorded as it is drawn — the page
 * rail, or everything below the dock — and a glass surface above it draws
 * that recording again, blurred, saturated and brightened, clipped to its own
 * shape. The recording is a display list, not a copy of pixels, so drawing it
 * twice costs one more pass of the GPU over the glass's own area.
 *
 * Only the chrome that floats over moving content needs this: the tab bar,
 * the two header buttons, the scrolled header, the scroll-to-top control and
 * the panels over their scrim. A card's backdrop is the app's own smooth
 * ground, which a blur leaves as it was, so cards draw that ground instead
 * ([appGround]).
 *
 * Blur needs Android 12 (RenderEffect). Before that the surfaces keep their
 * own tint and draw nothing behind it — what the website does in a browser
 * without `backdrop-filter`.
 */
@Stable
class Backdrop {

    internal var layer: GraphicsLayer? by mutableStateOf(null)

    /** Where the recorded content sits, in root coordinates. */
    internal var origin by mutableStateOf(Offset.Zero)
}

/** Records this content into [backdrop] while drawing it as usual. */
fun Modifier.recordBackdrop(backdrop: Backdrop, layer: GraphicsLayer): Modifier =
    this
        .onGloballyPositioned { backdrop.origin = it.positionInRoot() }
        .drawWithContent {
            backdrop.layer = layer
            layer.record { this@drawWithContent.drawContent() }
            drawLayer(layer)
        }

/** A [Backdrop] with its own recording layer, for a composable to fill with [recordBackdrop]. */
@Composable
fun rememberBackdrop(): Pair<Backdrop, GraphicsLayer> {
    val layer = rememberGraphicsLayer()
    val backdrop = remember { Backdrop() }
    return backdrop to layer
}

/** What a glass surface does to what is behind it: `blur(b) saturate(s) brightness(l)`. */
data class GlassFilter(val blur: Dp, val saturate: Float = 1f, val brightness: Float = 1f)

/** `--pane-blur`, `--pane-saturate`, `--pane-brightness`: softer in White Mode. */
val PaneFilter: GlassFilter
    get() = GlassFilter(Ownify.PaneBlur, saturate = Ownify.PaneSaturate, brightness = Ownify.PaneBrightness)
val CardFilter = GlassFilter(24.dp, saturate = 1.3f)

/** The backdrops a surface can look through, innermost first; provided by the shell. */
val LocalBackdrop = staticCompositionLocalOf<Backdrop?> { null }

/**
 * Draws [backdrop] behind this element, through [filter], clipped to [shape].
 * Does nothing without a recording or before Android 12.
 *
 * [ground] is for a recording that holds only what floats over the ground
 * (the page rail, a detail page's column): the ground is drawn first, through
 * the same filter, so the element shows the whole backdrop as a browser
 * does — never the sharp content under it.
 */
fun Modifier.glassBackdrop(
    backdrop: Backdrop?,
    shape: Shape,
    filter: GlassFilter,
    blurLayer: GraphicsLayer?,
    ground: GroundPlacement? = null
): Modifier {
    if (backdrop == null || blurLayer == null || Build.VERSION.SDK_INT < Build.VERSION_CODES.S) {
        return this
    }

    var coordinates: LayoutCoordinates? = null

    return this
        .onGloballyPositioned { coordinates = it }
        .drawWithContent {
            val scene = backdrop.layer
            val here = coordinates
            if (scene != null && here != null && here.isAttached) {
                val at = here.positionInRoot()
                if (ground != null) {
                    val outline = shape.createOutline(size, layoutDirection, this)
                    clipPath(Path().apply { addOutline(outline) }, ClipOp.Intersect) {
                        drawGround(ground.ground, ground.size, origin = ground.origin - at, saturate = filter.saturate, brightness = filter.brightness)
                    }
                }
                drawThroughGlass(scene, blurLayer, at - backdrop.origin, shape, filter)
            }
            drawContent()
        }
}

@Composable
fun rememberBlurLayer(): GraphicsLayer? =
    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) rememberGraphicsLayer() else null

@RequiresApi(Build.VERSION_CODES.S)
private fun DrawScope.drawThroughGlass(
    scene: GraphicsLayer,
    blurLayer: GraphicsLayer,
    offset: Offset,
    shape: Shape,
    filter: GlassFilter
) {
    val sigma = filter.blur.toPx()
    // Enough of the scene around the glass for the blur to sample, as a
    // browser samples the backdrop past the element's edge.
    val margin = ceil(sigma * 3f)
    val width = size.width + margin * 2
    val height = size.height + margin * 2
    if (width <= 0f || height <= 0f) return

    blurLayer.renderEffect = glassEffect(sigma, filter)
    blurLayer.record(size = IntSize(width.toInt(), height.toInt())) {
        translate(-offset.x + margin, -offset.y + margin) { drawLayer(scene) }
    }

    val outline = shape.createOutline(size, layoutDirection, this)
    val clip = Path().apply { addOutline(outline) }
    clipPath(clip, ClipOp.Intersect) {
        translate(-margin, -margin) { drawLayer(blurLayer) }
    }
}

/**
 * CSS `blur(b)` is a Gaussian whose standard deviation is b. RenderEffect
 * takes a radius and turns it into a deviation as Skia does
 * (0.57735 × radius + 0.5), so the radius is that turned back.
 */
internal fun blurRadiusFor(sigma: Float): Float = ((sigma - 0.5f) / 0.57735f).coerceAtLeast(0.01f)

@RequiresApi(Build.VERSION_CODES.S)
private fun glassEffect(sigma: Float, filter: GlassFilter): androidx.compose.ui.graphics.RenderEffect {
    val radius = blurRadiusFor(sigma)
    val blur = android.graphics.RenderEffect.createBlurEffect(
        radius,
        radius,
        android.graphics.Shader.TileMode.CLAMP
    )

    if (filter.saturate == 1f && filter.brightness == 1f) {
        return blur.asComposeRenderEffect()
    }

    val color = android.graphics.RenderEffect.createColorFilterEffect(
        ColorFilter.colorMatrix(cssSaturateBrightness(filter.saturate, filter.brightness)).asAndroidColorFilter()
    )

    // Blur first, then colour: the order of the CSS filter list.
    return android.graphics.RenderEffect.createChainEffect(color, blur).asComposeRenderEffect()
}
