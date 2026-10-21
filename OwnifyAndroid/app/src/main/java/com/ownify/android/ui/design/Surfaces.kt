package com.ownify.android.ui.design

import androidx.compose.foundation.background
import androidx.compose.foundation.border
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
import androidx.compose.ui.composed
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

/**
 * How a card is dressed (25.0: one card). The website's `.card` is the quiet
 * recipe — `--surface-gradient-quiet`, `--glass-border-soft`, a 10/24 shadow
 * and the inset top light — and [Dimmed] is what a card with nothing in it
 * yet steps down to.
 */
@Immutable
data class CardStyle(
    val from: Color,
    val to: Color,
    val border: Color,
    val shadows: List<BoxShadow>
) {
    companion object {
        /** `.card`. */
        val Default: CardStyle
            get() = CardStyle(
                from = Ownify.SurfaceQuietFrom,
                to = Ownify.SurfaceQuietTo,
                border = Ownify.GlassBorderSoft,
                shadows = listOf(
                    BoxShadow(y = 10.dp, blur = 24.dp, color = Ownify.shade(0.14f)),
                    BoxShadow(y = 1.dp, blur = 0.dp, color = Ownify.ShadowInset, inset = true)
                )
            )

        /**
         * `.card--insights.is-empty`, `.card--recommendation.is-empty`: what is
         * not there yet steps down from glass to a well — `--fill` at .03, the
         * hairline, no shadow (and no top light: [JCard]'s `highlight`).
         */
        val Dimmed: CardStyle
            get() = CardStyle(
                from = Ownify.fill(0.03f),
                to = Ownify.fill(0.03f),
                border = Ownify.GlassHairline,
                shadows = emptyList()
            )

        /** The same card: the quiet one is the only one since 25.0. */
        val Quiet: CardStyle
            get() = Default
    }
}

// ---------------------------------------------------------------------------
// Inside a card: a Well, a Row, a Note, an empty Slot (theme.css --inset-fill)
// ---------------------------------------------------------------------------

/** A Well — a block inside a card: [Ownify.RadiusMd], the faint fill, the hairline. */
fun Modifier.well(shape: Shape = RoundedCornerShape(Ownify.RadiusMd)): Modifier =
    this.clip(shape).background(Ownify.InsetFill, shape).border(1.dp, Ownify.GlassHairline, shape)

/** A Row — a list row or a choice to tap: [Ownify.RadiusSm], the faint fill, no edge. */
fun Modifier.insetRow(shape: Shape = RoundedCornerShape(Ownify.RadiusSm)): Modifier =
    this.clip(shape).background(Ownify.InsetFill, shape)

/** The tones a Note takes: the green, the attention gold, or a category's own colour. */
enum class NoteTone { Positive, Attention }

/** A Note — wash and edge of one tone, at [Ownify.RadiusMd]; [accent] for a category's own. */
fun Modifier.note(tone: NoteTone = NoteTone.Positive, accent: Color? = null, shape: Shape = RoundedCornerShape(Ownify.RadiusMd)): Modifier {
    val (wash, edge) = when {
        accent != null -> Ownify.mix(accent, 0.10f, Color.Transparent) to Ownify.mix(accent, 0.30f, Color.Transparent)
        tone == NoteTone.Attention -> Ownify.AttentionWash to Ownify.AttentionEdge
        else -> Ownify.HealthWash to Ownify.HealthEdge
    }
    return this.clip(shape).background(wash, shape).border(1.dp, edge, shape)
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
                        0f to Ownify.glint(0f),
                        0.5f to Ownify.glint(0.28f),
                        1f to Ownify.glint(0f),
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
    highlight: Boolean = true,
    content: @Composable ColumnScope.() -> Unit
) {
    val shadows = LocalGraphicsContext.current.shadowContext
    Column(
        modifier = modifier
            .cardSurface(shape, style, LocalGround.current, shadows, highlight)
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
    val extra: ((Size) -> Brush)? = null,
    val rim: ((Size) -> Brush)? = null
)

/**
 * The Liquid Glass (theme.css `--lg-*`), the one material: the same tint over
 * the same backdrop filter, the same 177° face, the same rim. [Control] for
 * buttons, capsules and the island; [Chip] the same with a lighter shadow;
 * [Panel] the thick setting, for panels, sheets and tooltips; [Tabbar] the bar.
 */
object Panes {
    private val ControlShadows: List<BoxShadow>
        get() = listOf(
            BoxShadow(y = 8.dp, blur = 20.dp, color = Ownify.shade(0.26f)),
            BoxShadow(y = 1.dp, blur = 0.dp, color = Ownify.glint(0.13f), inset = true)
        )

    /** `--lg-shadow-float`. */
    private val FloatShadows: List<BoxShadow>
        get() = listOf(
            BoxShadow(y = 18.dp, blur = 44.dp, color = Ownify.shade(0.46f)),
            BoxShadow(y = 5.dp, blur = 14.dp, color = Ownify.shade(0.30f))
        )

    /** `.tabbar`. */
    val Tabbar: PaneStyle
        get() = PaneStyle(
            tint = Ownify.paneBody(Ownify.LgTint),
            faceMiddle = 0.46f,
            shadows = FloatShadows + listOf(
                BoxShadow(y = 1.dp, blur = 0.dp, color = Ownify.glint(0.14f), inset = true),
                BoxShadow(y = (-1).dp, blur = 0.dp, color = Ownify.glint(0.04f), inset = true)
            )
        )

    /** A glass control: the header's buttons, the island, round buttons, capsules. */
    val Control: PaneStyle
        get() = PaneStyle(tint = Ownify.paneBody(Ownify.LgTint), faceMiddle = 0.46f, shadows = ControlShadows)

    /** A glass chip, a segmented control's track: `--lg-shadow-small`. */
    val Chip: PaneStyle
        get() = Control.copy(
            shadows = listOf(
                BoxShadow(y = 2.dp, blur = 7.dp, color = Ownify.shade(0.16f)),
                BoxShadow(y = 1.dp, blur = 0.dp, color = Ownify.glint(0.13f), inset = true)
            )
        )

    /** The thick glass: panels, sheets, tooltips — `--lg-tint-thick`, the floating shadow. */
    val Panel: PaneStyle
        get() = PaneStyle(
            tint = Ownify.paneBody(Ownify.LgTintThick),
            faceMiddle = 0.46f,
            shadows = listOf(BoxShadow(y = 1.dp, blur = 0.dp, color = Ownify.glint(0.13f), inset = true)) + FloatShadows
        )

    /** A tooltip: the thick glass, with a control's shadow (`--lg-shadow`). */
    val Tip: PaneStyle
        get() = Panel.copy(shadows = ControlShadows)

    /** `.pill--devices` / `.pill--account`: a control. */
    val HeaderButton: PaneStyle
        get() = Control

    /** `.pill--devices:active`: the pane settles. */
    val HeaderButtonPressed: PaneStyle
        get() = Control.copy(
            shadows = listOf(
                BoxShadow(y = 3.dp, blur = 10.dp, color = Ownify.shade(0.26f)),
                BoxShadow(y = 1.dp, blur = 0.dp, color = Ownify.glint(0.10f), inset = true)
            )
        )

    /** `.welcome__button`: a control. */
    val WelcomeButton: PaneStyle
        get() = Control

    /** `.welcome__button--primary`: the control with the app's green in it (`--lg-green`). */
    val WelcomePrimary: PaneStyle
        get() = Control.copy(extra = GreenWash, rim = GreenRim)

    /** `--lg-green` / `--lg-gold`: one function each, so two looks of a button compare equal. */
    val GreenWash: (Size) -> Brush = { size -> Brush.verticalGradient(listOf(Ownify.HealthEdge, Ownify.HealthFill), startY = 0f, endY = size.height) }
    val GoldWash: (Size) -> Brush = { size -> Brush.verticalGradient(listOf(Ownify.AttentionEdge, Ownify.AttentionFill), startY = 0f, endY = size.height) }

    /** `--lg-green-rim` / `--lg-gold-rim`: the rim in the colour. */
    val GreenRim: (Size) -> Brush = { size -> cssLinearGradient(152f, size, Stop(0f, Ownify.HealthStrong), Stop(0.6f, Ownify.HealthEdge), Stop(1f, Ownify.HealthWash)) }
    val GoldRim: (Size) -> Brush = { size -> cssLinearGradient(152f, size, Stop(0f, Ownify.AttentionStrong), Stop(0.6f, Ownify.AttentionEdge), Stop(1f, Ownify.AttentionWash)) }
}

/** `--pane-rim`: 152°, bright at the top-left, a little back at the bottom-right. */
fun paneRim(size: Size): Brush {
    val rim = Ownify.PaneRim
    return cssLinearGradient(
        152f, size,
        Stop(0f, rim[0]),
        Stop(0.22f, rim[1]),
        Stop(0.52f, rim[2]),
        Stop(0.78f, rim[3]),
        Stop(1f, rim[4])
    )
}

/** `--lg-face`, the 177° face of the glass: `.115` at the top, [middle] `.042`, `.06` at the bottom. */
@Suppress("UNUSED_PARAMETER")
fun paneFace(size: Size, middle: Float, primary: Boolean = false): Brush = cssLinearGradient(
    177f, size,
    Stop(0f, Ownify.glint(0.115f)),
    Stop(middle, Ownify.glint(0.042f)),
    Stop(1f, Ownify.glint(0.06f))
)

/**
 * The Liquid Glass drawn in place, without a backdrop of its own: [style]'s
 * outer shadows, its tint, [PaneStyle.extra], the face, the inset lights, the
 * rim, then the content — clipped to [shape].
 */
fun Modifier.liquidGlass(shape: Shape, style: PaneStyle): Modifier = composed {
    val shadowContext = LocalGraphicsContext.current.shadowContext
    val outer = remember(style) { style.shadows.filter { !it.inset } }
    val inner = remember(style) { style.shadows.filter { it.inset } }
    drawWithContent {
        val outline = shape.createOutline(size, layoutDirection, this)
        drawBoxShadows(shape, outer, shadowContext, outline)
        val path = Path().apply { addOutline(outline) }
        clipPath(path) {
            drawRect(style.tint)
            style.extra?.let { drawRect(it(size)) }
            drawRect(paneFace(size, style.faceMiddle))
        }
        drawBoxShadows(shape, inner, shadowContext, outline)
        drawBorder(outline, style.rim?.invoke(size) ?: paneRim(size))
        drawContent()
    }.clip(shape)
}

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
                drawBorder(outline, style.rim?.invoke(size) ?: paneRim(size))
                drawContent()
            }
            .clip(shape),
        contentAlignment = contentAlignment,
        content = content
    )
}

/**
 * `--lg-chosen`: the chosen piece inside the glass — the tab bar's lozenge, a
 * segmented control's chosen option. Its small shadow, the brighter face, the
 * warm tint White Mode lays over it, the lit top edge.
 */
fun DrawScope.drawChosenGlass(shape: Shape, shadows: ShadowContext?) {
    val outline = shape.createOutline(size, layoutDirection, this)
    drawBoxShadows(shape, listOf(BoxShadow(y = 2.dp, blur = 7.dp, color = Ownify.shade(Ownify.TabSelectedShade))), shadows, outline)
    val path = Path().apply { addOutline(outline) }
    drawPath(path, cssLinearGradient(178f, size, Stop(0f, Ownify.glint(0.20f)), Stop(0.48f, Ownify.glint(0.115f)), Stop(1f, Ownify.glint(0.085f))))
    drawPath(path, Ownify.TabSelectedTint)
    drawBoxShadows(
        shape,
        listOf(
            BoxShadow(y = 1.dp, blur = 0.dp, color = Ownify.glint(0.28f), inset = true),
            BoxShadow(y = (-1).dp, blur = 0.dp, color = Ownify.glint(0.05f), inset = true)
        ),
        shadows, outline
    )
}
