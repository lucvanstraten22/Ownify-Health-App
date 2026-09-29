package com.ownify.android.ui.design

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxScope
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.runtime.Composable
import androidx.compose.runtime.Immutable
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.drawWithContent
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.addOutline
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.ClipOp
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Outline
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.Shape
import androidx.compose.ui.graphics.drawscope.DrawScope
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.graphics.drawscope.clipPath
import androidx.compose.ui.graphics.drawscope.inset
import androidx.compose.ui.graphics.shadow.ShadowContext
import androidx.compose.ui.layout.onGloballyPositioned
import androidx.compose.ui.layout.positionInRoot
import androidx.compose.ui.platform.LocalGraphicsContext
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import com.ownify.android.ui.theme.Ownify

/** How a card is dressed: the website's `.card` and its quieter supporting variant. */
@Immutable
data class CardStyle(
    val from: Color,
    val to: Color,
    val border: Color,
    val shadows: List<BoxShadow>
) {
    companion object {
        /** `.card`: `--surface-gradient`, `--glass-border`, `--shadow-inset`, `--shadow-card`. */
        val Default = CardStyle(
            from = Ownify.white(0.11f),
            to = Ownify.white(0.04f),
            border = Ownify.GlassBorder,
            shadows = listOf(
                BoxShadow(y = 15.dp, blur = 35.dp, color = Color.Black.copy(alpha = 0.18f)),
                BoxShadow(y = 1.dp, blur = 0.dp, color = Ownify.white(0.08f), inset = true)
            )
        )

        /** `.card--patterns / --recommendation / --leaderboard`: `--surface-gradient-quiet`. */
        val Quiet = CardStyle(
            from = Ownify.white(0.075f),
            to = Ownify.white(0.028f),
            border = Ownify.GlassBorderSoft,
            shadows = listOf(
                BoxShadow(y = 10.dp, blur = 24.dp, color = Color.Black.copy(alpha = 0.14f)),
                BoxShadow(y = 1.dp, blur = 0.dp, color = Ownify.white(0.08f), inset = true)
            )
        )
    }
}

/**
 * The card surface, drawn in the website's order: its shadow outside the box,
 * the ground behind it through `saturate(130%)` (a blur leaves the smooth
 * ground as it is), the surface gradient at 135°, the inset top light, the
 * 1 px border — and after the content, the one restrained highlight along
 * the top edge (`.card::before`).
 */
fun Modifier.cardSurface(
    shape: Shape,
    style: CardStyle,
    ground: GroundPlacement?,
    shadows: ShadowContext?,
    highlight: Boolean = true
): Modifier {
    val position = SurfacePosition()

    return this
        .onGloballyPositioned { position.inRoot = it.positionInRoot() }
        .drawWithContent {
            val outline = shape.createOutline(size, layoutDirection, this)
            val box = Path().apply { addOutline(outline) }

            drawBoxShadows(shape, style.shadows.filter { !it.inset }, shadows, outline)

            clipPath(box) {
                if (ground != null) {
                    drawGround(
                        ground.ground,
                        ground.size,
                        origin = ground.origin - position.inRoot,
                        saturate = 1.3f
                    )
                }
                drawRect(cssLinearGradient(135f, size, Stop(0f, style.from), Stop(1f, style.to)))
            }

            drawBoxShadows(shape, style.shadows.filter { it.inset }, shadows, outline)
            drawBorder(outline, style.border)

            clipPath(box) { this@drawWithContent.drawContent() }

            if (highlight) {
                // inset-inline: 12%; top: 0 (inside the border); height: 1px
                val inset = size.width * 0.12f
                val y = 1.dp.toPx()
                drawRect(
                    brush = Brush.horizontalGradient(
                        0f to Color.White.copy(alpha = 0f),
                        0.5f to Color.White.copy(alpha = 0.28f),
                        1f to Color.White.copy(alpha = 0f),
                        startX = inset,
                        endX = size.width - inset
                    ),
                    topLeft = Offset(inset, y),
                    size = Size(size.width - inset * 2, 1.dp.toPx())
                )
            }
        }
}

/** A 1 px border inside the box's edge, following its corners, as CSS draws one. */
fun DrawScope.drawBorder(outline: Outline, color: Color, width: Dp = 1.dp) {
    if (color.alpha == 0f) return
    val stroke = width.toPx()
    // The stroke is centred on the path, so the path is the box inset by half.
    inset(stroke / 2f) {
        val inner = when (outline) {
            is Outline.Rounded -> {
                val r = outline.roundRect
                Outline.Rounded(
                    r.copy(
                        right = r.right - stroke, bottom = r.bottom - stroke,
                        topLeftCornerRadius = shrink(r.topLeftCornerRadius, stroke / 2f),
                        topRightCornerRadius = shrink(r.topRightCornerRadius, stroke / 2f),
                        bottomLeftCornerRadius = shrink(r.bottomLeftCornerRadius, stroke / 2f),
                        bottomRightCornerRadius = shrink(r.bottomRightCornerRadius, stroke / 2f)
                    )
                )
            }
            is Outline.Rectangle -> Outline.Rectangle(outline.rect.copy(right = outline.rect.right - stroke, bottom = outline.rect.bottom - stroke))
            is Outline.Generic -> outline
        }
        drawOutlineStroke(inner, color, stroke)
    }
}

private fun shrink(r: androidx.compose.ui.geometry.CornerRadius, by: Float) =
    androidx.compose.ui.geometry.CornerRadius((r.x - by).coerceAtLeast(0f), (r.y - by).coerceAtLeast(0f))

private fun DrawScope.drawOutlineStroke(outline: Outline, color: Color, width: Float) {
    val path = Path().apply { addOutline(outline) }
    drawPath(path, color, style = Stroke(width))
}

/** Same, with a brush: the pane rim. */
fun DrawScope.drawBorder(outline: Outline, brush: Brush, width: Dp = 1.dp) {
    val stroke = width.toPx()
    val path = Path().apply { addOutline(outline) }
    // Inside the edge only: clip to the box and draw the stroke twice as wide.
    clipPath(path, ClipOp.Intersect) {
        drawPath(path, brush, style = Stroke(stroke * 2f))
    }
}

/** The card's shape at this width: 28 dp, 24 dp below 360 dp. */
@Composable
fun cardShape(): Shape = RoundedCornerShape(LocalScreen.current.cardRadius)

/**
 * A card: `.card`, with its padding ([padding], 24 dp by default), clipped to
 * its corners like `overflow: hidden`.
 */
@Composable
fun JCard(
    modifier: Modifier = Modifier,
    style: CardStyle = CardStyle.Default,
    shape: Shape = cardShape(),
    padding: PaddingValues = PaddingValues(Ownify.Space5),
    content: @Composable ColumnScope.() -> Unit
) {
    val shadows = LocalGraphicsContext.current.shadowContext
    Column(
        modifier = modifier
            .cardSurface(shape, style, LocalGround.current, shadows)
            // The 1 px border takes room, as `.card`'s does (border-box).
            .cssPadding(padding, border = 1.dp),
        content = content
    )
}

/**
 * The floating glass the tab bar, the header buttons and the welcome buttons
 * are cut from: what is behind blurred 10px, saturated 190% and brightened
 * 108%; a dark tint; the 177° face gradient; its shadows; the rim.
 */
@Immutable
data class PaneStyle(
    val tint: Color,
    val faceMiddle: Float,
    val face: List<Brush.() -> Unit> = emptyList(),
    val shadows: List<BoxShadow>,
    val extra: ((Size) -> Brush)? = null
)

object Panes {
    /** `.tabbar`. */
    val Tabbar = PaneStyle(
        tint = Color(30, 28, 29).copy(alpha = 0.62f),
        faceMiddle = 0.44f,
        shadows = listOf(
            BoxShadow(y = 18.dp, blur = 44.dp, color = Color.Black.copy(alpha = 0.46f)),
            BoxShadow(y = 5.dp, blur = 14.dp, color = Color.Black.copy(alpha = 0.30f)),
            BoxShadow(y = 1.dp, blur = 0.dp, color = Ownify.white(0.14f), inset = true),
            BoxShadow(y = (-1).dp, blur = 0.dp, color = Ownify.white(0.04f), inset = true)
        )
    )

    /** `.pill--devices` / `.pill--account`. */
    val HeaderButton = PaneStyle(
        tint = Color(30, 28, 29).copy(alpha = 0.46f),
        faceMiddle = 0.46f,
        shadows = listOf(
            BoxShadow(y = 8.dp, blur = 20.dp, color = Color.Black.copy(alpha = 0.30f)),
            BoxShadow(y = 1.dp, blur = 0.dp, color = Ownify.white(0.13f), inset = true)
        )
    )

    /** `.pill--devices:active`: the pane settles. */
    val HeaderButtonPressed = HeaderButton.copy(
        shadows = listOf(
            BoxShadow(y = 3.dp, blur = 10.dp, color = Color.Black.copy(alpha = 0.26f)),
            BoxShadow(y = 1.dp, blur = 0.dp, color = Ownify.white(0.10f), inset = true)
        )
    )

    /** `.welcome__button`. */
    val WelcomeButton = PaneStyle(
        tint = Color(30, 28, 29).copy(alpha = 0.46f),
        faceMiddle = 0.46f,
        shadows = listOf(
            BoxShadow(y = 12.dp, blur = 28.dp, color = Color.Black.copy(alpha = 0.34f)),
            BoxShadow(y = 1.dp, blur = 0.dp, color = Ownify.white(0.13f), inset = true)
        )
    )

    /** `.welcome__button--primary`: the same pane with the app's green in it. */
    val WelcomePrimary = WelcomeButton.copy(
        extra = { size ->
            Brush.verticalGradient(
                listOf(Ownify.Health.copy(alpha = 0.30f), Ownify.Health.copy(alpha = 0.18f)),
                startY = 0f,
                endY = size.height
            )
        }
    )
}

/** `--pane-rim`: 152°, bright at the top-left, a little back at the bottom-right. */
fun paneRim(size: Size): Brush = cssLinearGradient(
    152f, size,
    Stop(0f, Ownify.white(0.46f)),
    Stop(0.22f, Ownify.white(0.13f)),
    Stop(0.52f, Ownify.white(0.045f)),
    Stop(0.78f, Ownify.white(0.10f)),
    Stop(1f, Ownify.white(0.26f))
)

/** The 177° face of a pane: `.115` at the top, [middle] `.042`, `.06` at the bottom. */
fun paneFace(size: Size, middle: Float, primary: Boolean = false): Brush = cssLinearGradient(
    177f, size,
    Stop(0f, Ownify.white(if (primary) 0.13f else 0.115f)),
    Stop(middle, Ownify.white(if (primary) 0.045f else 0.042f)),
    Stop(1f, Ownify.white(0.06f))
)

/**
 * A pane of the floating glass, in the website's paint order: outer shadows,
 * the backdrop through the pane filter, the tint, [extra] (the welcome
 * button's green), the face, the inset lights, [sheen] (the tab bar's
 * reflection), the rim, then the content.
 */
@Composable
fun Pane(
    modifier: Modifier = Modifier,
    style: PaneStyle,
    shape: Shape = CircleShape,
    primary: Boolean = false,
    sheen: (DrawScope.() -> Unit)? = null,
    contentAlignment: Alignment = Alignment.Center,
    content: @Composable BoxScope.() -> Unit
) {
    val shadowContext = LocalGraphicsContext.current.shadowContext
    val backdrop = LocalBackdrop.current
    val blurLayer = rememberBlurLayer()
    val outer = remember(style) { style.shadows.filter { !it.inset } }
    val inner = remember(style) { style.shadows.filter { it.inset } }

    Box(
        modifier = modifier
            .drawWithContent {
                val outline = shape.createOutline(size, layoutDirection, this)
                drawBoxShadows(shape, outer, shadowContext, outline)
                drawContent()
            }
            .glassBackdrop(backdrop, shape, PaneFilter, blurLayer)
            .drawWithContent {
                val outline = shape.createOutline(size, layoutDirection, this)
                val path = Path().apply { addOutline(outline) }
                clipPath(path) {
                    drawRect(style.tint)
                    style.extra?.let { drawRect(it(size)) }
                    drawRect(paneFace(size, style.faceMiddle, primary))
                }
                drawBoxShadows(shape, inner, shadowContext, outline)
                sheen?.invoke(this)
                drawBorder(outline, paneRim(size))
                drawContent()
            }
            .clip(shape),
        contentAlignment = contentAlignment,
        content = content
    )
}
