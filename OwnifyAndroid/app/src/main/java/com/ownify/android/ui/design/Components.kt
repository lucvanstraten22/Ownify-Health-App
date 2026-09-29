package com.ownify.android.ui.design

import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.animateDpAsState
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.RowScope
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicText
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.drawBehind
import androidx.compose.ui.draw.drawWithContent
import androidx.compose.ui.draw.paint
import androidx.compose.ui.focus.onFocusChanged
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.addOutline
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.ColorFilter
import androidx.compose.ui.graphics.PathEffect
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.layout.layout
import androidx.compose.ui.graphics.vector.rememberVectorPainter
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.platform.LocalGraphicsContext
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.role
import androidx.compose.ui.semantics.selected
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.text.rememberTextMeasurer
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.IntOffset
import androidx.compose.ui.unit.TextUnit
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.em
import androidx.compose.ui.unit.isUnspecified
import androidx.compose.ui.unit.offset
import androidx.compose.ui.unit.sp
import kotlin.math.ceil
import kotlin.math.roundToInt
import com.ownify.android.ui.theme.Accent
import com.ownify.android.ui.theme.Ownify
import com.ownify.android.ui.theme.InButton
import com.ownify.android.ui.theme.OwnifyType
import com.ownify.android.ui.theme.LocalTracking
import com.ownify.android.ui.theme.LocalAccent
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.withFrameNanos
import androidx.compose.ui.platform.LocalFocusManager
import androidx.compose.ui.semantics.heading

// ---------------------------------------------------------------------------
// Type — the website's text classes
// ---------------------------------------------------------------------------

object JStyle {
    /** `.card__title`, `.page-intro__title`, `.score-ring__label`. */
    val Section = OwnifyType.style(Ownify.FsSection, FontWeight.SemiBold, tracking = (-0.02).em)

    /** `.card__eyebrow` (its own -0.01em). */
    val Eyebrow = OwnifyType.style(Ownify.FsLabel, FontWeight.SemiBold, tracking = (-0.01).em)

    /** `.card__caption`, `.settings-eyebrow`: small caps, spaced. */
    val Caption = OwnifyType.style(Ownify.FsTiny, FontWeight.SemiBold, Ownify.TextMuted, tracking = Ownify.TrackingWide)

    /** `.card__subtitle`, `.goal__name`, `.confirm__title`. */
    val Subtitle = OwnifyType.style(17.sp, FontWeight.SemiBold, tracking = (-0.015).em)

    val Meta = OwnifyType.style(Ownify.FsSmall, color = Ownify.TextMuted)
    val Lede = OwnifyType.style(Ownify.FsSmall, color = Ownify.TextSecondary)
    val Small = OwnifyType.style(Ownify.FsSmall)
    val Label = OwnifyType.style(Ownify.FsLabel)
    val Tiny = OwnifyType.style(Ownify.FsTiny, color = Ownify.TextMuted)
    val Body = OwnifyType.Base
}

/** Text in one of [JStyle]'s styles, without Material's defaults. */
@Composable
fun T(
    text: String,
    style: TextStyle,
    modifier: Modifier = Modifier,
    color: Color? = null,
    align: TextAlign? = null,
    maxLines: Int = Int.MAX_VALUE,
    ellipsis: Boolean = false,
    uppercase: Boolean = false
) {
    val tracking = LocalTracking.current
    val resolved = style.let { s ->
        var out = s
        if (out.letterSpacing.isUnspecified) out = out.copy(letterSpacing = tracking)
        if (color != null) out = out.copy(color = color)
        if (align != null) out = out.copy(textAlign = align)
        out
    }
    BasicText(
        text = if (uppercase) text.uppercase(java.util.Locale.forLanguageTag("nl")) else text,
        modifier = modifier.cssLineBox(resolved),
        style = resolved,
        maxLines = maxLines,
        overflow = if (ellipsis) TextOverflow.Ellipsis else TextOverflow.Clip,
        softWrap = maxLines != 1
    )
}

/**
 * The text's box as CSS makes it: its lines times the line height, rounded
 * to the nearest pixel, the glyphs centred in it and free to spill over.
 *
 * Android keeps a line at least as tall as the font (about 1.17 em for
 * Roboto), so `line-height: 1` on a big number would otherwise stand 8 dp
 * taller than on the website; and it rounds every line up to a whole pixel,
 * which adds up down a page. The number of lines is read back from the
 * height Android gives the text, so intrinsic measurement (a row sized to
 * its tallest tile) comes out the same as the real one.
 *
 * Its width as CSS makes it too: the browser adds letter-spacing after every
 * letter, the last on a line included, and Android leaves it off the line's
 * end. So a line of tracked text is |spacing| narrower on the website — what
 * follows it sits closer, a centred line sits half that further right — and
 * with negative spacing a line fits |spacing| more text before it breaks.
 */
private fun Modifier.cssLineBox(style: TextStyle): Modifier = layout { measurable, constraints ->
    val spacing = style.letterSpacing.let { ls ->
        when {
            ls.isSp -> ls.toPx()
            ls.isEm && style.fontSize.isSp -> ls.value * style.fontSize.toPx()
            else -> 0f
        }
    }
    // The room the browser's lines have that Android's do not, to the nearest pixel.
    val room = if (spacing < 0f && constraints.hasBoundedWidth) (-spacing).roundToInt() else 0
    val fixed = constraints.minWidth == constraints.maxWidth
    val placeable = measurable.measure(
        if (room == 0) constraints
        else constraints.copy(minWidth = if (fixed) constraints.minWidth + room else constraints.minWidth, maxWidth = constraints.maxWidth + room)
    )
    // The website's box: the lines' widths with their last spacing, within the space given.
    val width = (if (fixed) constraints.maxWidth else (placeable.width + spacing).roundToInt())
        .coerceIn(constraints.minWidth, constraints.maxWidth)
    // Where the lines sit in it: a centred or end-aligned line moves by what it lost.
    val side = when (style.textAlign) {
        TextAlign.Center -> 0.5f
        TextAlign.End, TextAlign.Right -> 1f
        else -> 0f
    }
    val x = (side * (width - spacing - placeable.width)).roundToInt()
    val lineHeight = style.lineHeight
    val linePx = when {
        lineHeight.isSp -> lineHeight.toPx()
        lineHeight.isEm && style.fontSize.isSp -> style.fontSize.toPx() * lineHeight.value
        else -> Float.NaN
    }
    if (linePx.isNaN() || linePx <= 0f || !style.fontSize.isSp) {
        return@layout layout(width, placeable.height) { placeable.place(x, 0) }
    }
    // What Android made of it: whole-pixel lines, and the first line's top and
    // the last one's bottom never inside the font's own height.
    val androidLine = ceil(linePx).toInt()
    val extra = (NaturalLine.height(style.fontSize.toPx(), style.fontWeight) - androidLine).coerceAtLeast(0)
    val count = ((placeable.height - extra) / androidLine.toFloat()).roundToInt().coerceAtLeast(1)
    val height = (count * linePx).roundToInt().coerceIn(constraints.minHeight, constraints.maxHeight)
    val shift = ((placeable.height - height) / 2f).roundToInt()
    layout(width, height) { placeable.place(x, -shift) }
}

/**
 * Padding with its edges where the browser puts them. CSS keeps a box's
 * edges at fractional positions and rounds each to the pixel only when it
 * draws; Compose rounds every side on its own, so 12 dp above and below a
 * row (31.5 px each) come out 32 + 32 instead of 63, a pixel a row, which
 * adds up to several dp down a page of rows. Here each edge is rounded at
 * its place from the top of the box — [above] being a border drawn there
 * first — and the pair keeps its true total.
 */
fun Modifier.cssPadding(horizontal: Dp = 0.dp, top: Dp = 0.dp, bottom: Dp = 0.dp, above: Dp = 0.dp): Modifier =
    layout { measurable, constraints ->
        val start = above.toPx()
        val contentTop = (start + top.toPx()).roundToInt()
        val padTop = contentTop - start.roundToInt()
        val padBottom = (start + top.toPx() + bottom.toPx()).roundToInt() - contentTop
        val side = horizontal.roundToPx()
        val placeable = measurable.measure(constraints.offset(-2 * side, -(padTop + padBottom)))
        val width = (placeable.width + 2 * side).coerceIn(constraints.minWidth, constraints.maxWidth)
        val height = (placeable.height + padTop + padBottom).coerceIn(constraints.minHeight, constraints.maxHeight)
        layout(width, height) { placeable.placeRelative(side, padTop) }
    }

/**
 * A CSS border-box's insides: a [border] on every side taking room, then
 * [padding], each edge rounded where it falls — the box's width and height
 * keep their true totals instead of gaining a pixel per rounded side.
 */
fun Modifier.cssPadding(padding: PaddingValues = PaddingValues(0.dp), border: Dp): Modifier =
    layout { measurable, constraints ->
        val b = border.toPx()
        val l = padding.calculateLeftPadding(layoutDirection).toPx()
        val r = padding.calculateRightPadding(layoutDirection).toPx()
        val t = padding.calculateTopPadding().toPx()
        val u = padding.calculateBottomPadding().toPx()
        val left = (b + l).roundToInt()
        val right = (2 * b + l + r).roundToInt() - left
        val top = (b + t).roundToInt()
        val bottom = (2 * b + t + u).roundToInt() - top
        val placeable = measurable.measure(constraints.offset(-(left + right), -(top + bottom)))
        val width = (placeable.width + left + right).coerceIn(constraints.minWidth, constraints.maxWidth)
        val height = (placeable.height + top + bottom).coerceIn(constraints.minHeight, constraints.maxHeight)
        layout(width, height) { placeable.place(left, top) }
    }

/** The height Android gives one line of the system font at a size: its ascent to its descent, in whole pixels. */
private object NaturalLine {
    private val cache = HashMap<Long, Int>()
    private val paint = android.graphics.Paint()

    fun height(sizePx: Float, weight: FontWeight?): Int {
        val w = weight?.weight ?: 400
        val key = (sizePx.toBits().toLong() shl 16) or w.toLong()
        return synchronized(cache) {
            cache.getOrPut(key) {
                paint.typeface = android.graphics.Typeface.create(android.graphics.Typeface.DEFAULT, w, false)
                paint.textSize = sizePx
                paint.fontMetricsInt.let { it.descent - it.ascent }
            }
        }
    }
}

/**
 * `max-width: <n>ch` — n advances of the digit zero in [style]. As CSS has
 * it: the glyph's own advance, without letter spacing and unrounded (ten
 * zeros measured, so no pixel rounding creeps in).
 */
@Composable
fun chWidth(style: TextStyle, n: Float): Dp {
    val measurer = rememberTextMeasurer()
    val density = LocalDensity.current
    val width = remember(style, n, density) {
        val zeros = measurer.measure("0000000000", style.copy(letterSpacing = 0.sp), softWrap = false)
        (zeros.getLineRight(0) - zeros.getLineLeft(0)) / 10f * n
    }
    return with(density) { width.toDp() }
}

// ---------------------------------------------------------------------------
// Icons
// ---------------------------------------------------------------------------

/** An icon of the set, in [color] (`currentColor`), [size] square (`.icon` is 20). */
@Composable
fun JIcon(icon: ImageVector, modifier: Modifier = Modifier, size: Dp = 20.dp, color: Color = Ownify.TextPrimary) {
    Box(
        modifier
            .size(size)
            .paint(rememberVectorPainter(icon), colorFilter = ColorFilter.tint(color))
    )
}

/** `.icon-tile`: 36 square, radius 14, hairline border, `.065` white, a secondary-coloured icon of 18. */
@Composable
fun IconTile(
    icon: ImageVector,
    modifier: Modifier = Modifier,
    size: Dp = 36.dp,
    radius: Dp = Ownify.RadiusSm,
    iconSize: Dp = 18.dp,
    color: Color = Ownify.TextSecondary,
    background: Color = Ownify.white(0.065f),
    border: Color = Ownify.GlassHairline
) {
    val shape = RoundedCornerShape(radius)
    Box(
        modifier
            .size(size)
            .clip(shape)
            .background(background)
            .border(1.dp, border, shape),
        contentAlignment = Alignment.Center
    ) {
        JIcon(icon, size = iconSize, color = color)
    }
}

// ---------------------------------------------------------------------------
// Chips, pills, buttons
// ---------------------------------------------------------------------------

/**
 * `.chip`: tiny, 600, uppercase and spaced, secondary; `.chip--muted`
 * keeps the case, 500, muted; `.chip--quiet` keeps the case at 600 in the
 * secondary colour. [dot] is `.chip__dot` in the health green.
 */
@Composable
fun Chip(
    text: String,
    modifier: Modifier = Modifier,
    muted: Boolean = false,
    dot: Boolean = false,
    quiet: Boolean = false,
    border: Color = Ownify.GlassHairline,
    background: Color = Ownify.white(0.055f),
    color: Color = if (muted) Ownify.TextMuted else Ownify.TextSecondary,
    leading: (@Composable () -> Unit)? = null
) {
    val shape = RoundedCornerShape(50)
    Row(
        modifier
            .clip(shape)
            .background(background)
            .border(1.dp, border, shape)
            .cssPadding(PaddingValues(horizontal = Ownify.Space3, vertical = Ownify.Space1), border = 1.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(if (leading != null) 6.dp else Ownify.Space2)
    ) {
        if (dot) Box(Modifier.size(6.dp).background(Ownify.Health, CircleShape))
        leading?.invoke()
        T(
            text,
            when {
                muted -> OwnifyType.style(Ownify.FsTiny, FontWeight.Medium, color, tracking = 0.em)
                quiet -> OwnifyType.style(Ownify.FsTiny, FontWeight.SemiBold, color, tracking = 0.em)
                else -> OwnifyType.style(Ownify.FsTiny, FontWeight.SemiBold, color, tracking = Ownify.TrackingWide)
            },
            maxLines = 1,
            uppercase = !muted && !quiet
        )
    }
}

/** `.chip--accent`: the chip in [accent] — its border at 34%, its fill at 16%, its text lightened. */
@Composable
fun AccentChip(text: String, accent: Color, modifier: Modifier = Modifier) {
    Chip(
        text,
        modifier,
        border = Ownify.mix(accent, 0.34f, Color.Transparent),
        background = Ownify.mix(accent, 0.16f, Color.Transparent),
        color = Ownify.mix(accent, 0.40f, Color.White)
    )
}

/**
 * `.pill`: at least 42 high, 12 either side, the soft border and `--glass-soft`,
 * in the secondary colour — the back and close controls.
 */
@Composable
fun Pill(
    label: String,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    icon: ImageVector? = null,
    contentDescription: String? = null,
    padding: PaddingValues = PaddingValues(start = Ownify.Space2, end = Ownify.Space3)
) {
    InButton {
        val interaction = remember { MutableInteractionSource() }
        val shape = RoundedCornerShape(50)
        Row(
            modifier
                .press(interaction)
                .heightIn(min = 42.dp)
                .clip(shape)
                .background(Ownify.GlassSoft)
                .border(1.dp, Ownify.GlassBorderSoft, shape)
                .clickable(interaction, indication = null, role = Role.Button, onClick = blurring(onClick))
                .semantics { if (contentDescription != null) this.contentDescription = contentDescription }
                .cssPadding(padding, border = 1.dp),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)
        ) {
            if (icon != null) JIcon(icon, size = 18.dp, color = Ownify.TextSecondary)
            T(label, OwnifyType.style(Ownify.FsSmall, FontWeight.Medium, Ownify.TextSecondary), maxLines = 1)
        }
    }
}

/**
 * What a button looks like beyond `.btn`: its border and fill (the confirm's
 * yes, the wizard's next), and how far it fades while disabled.
 */
data class BtnLook(
    val border: Color = Ownify.GlassBorder,
    val fill: Color = Ownify.Glass,
    val text: Color = Ownify.TextPrimary,
    val disabledAlpha: Float = 0.45f
) {
    companion object {
        /** `.confirm__yes--final`: the step that cannot be undone, in the miss red; 60% while it works. */
        val Final = BtnLook(border = Ownify.mix(Ownify.Miss, 0.60f, Color.Transparent), fill = Ownify.mix(Ownify.Miss, 0.24f, Color.Transparent), disabledAlpha = 0.6f)
    }
}

/**
 * `.btn`: a capsule at least 42 high, 16 either side, `--glass` over the
 * glass border, 13/600; disabled at 45%.
 */
@Composable
fun Btn(
    label: String,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    enabled: Boolean = true,
    icon: ImageVector? = null,
    iconSize: Dp = 16.dp,
    look: BtnLook = BtnLook(),
    contentDescription: String? = null,
    content: (@Composable RowScope.() -> Unit)? = null
) {
    InButton {
        val interaction = remember { MutableInteractionSource() }
        val shape = RoundedCornerShape(50)
        Row(
            modifier
                .press(interaction, enabled = enabled)
                .alpha(if (enabled) 1f else look.disabledAlpha)
                .heightIn(min = 42.dp)
                .clip(shape)
                .background(look.fill)
                .border(1.dp, look.border, shape)
                .clickable(interaction, indication = null, enabled = enabled, role = Role.Button, onClick = blurring(onClick))
                .semantics { if (contentDescription != null) this.contentDescription = contentDescription }
                .cssPadding(PaddingValues(horizontal = Ownify.Space4), border = 1.dp),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(Ownify.Space2, Alignment.CenterHorizontally)
        ) {
            if (content != null) {
                content()
            } else {
                if (icon != null) JIcon(icon, size = iconSize, color = look.text)
                T(label, OwnifyType.style(Ownify.FsSmall, FontWeight.SemiBold, look.text), maxLines = 1)
            }
        }
    }
}

// ---------------------------------------------------------------------------
// Meters and rings
// ---------------------------------------------------------------------------

/**
 * `.meter`: a 6 high track at `.08`, filled with the accent running to 65%
 * accent in white, over 1000 ms once the card is on screen. Empty, it is a
 * dashed 4 px line (2 on, 6 off) — [emptyHeight] 6 for the goal meter.
 */
@Composable
fun Meter(
    share: Float?,
    play: Boolean,
    modifier: Modifier = Modifier,
    accent: Color = LocalAccent.current.color,
    height: Dp = 6.dp,
    emptyHeight: Dp = 4.dp,
    fill: Brush? = null,
    track: Color = Ownify.white(0.08f)
) {
    val empty = share == null
    val shown = animatedShare((share ?: 0f).coerceIn(0f, 1f), play)
    Box(
        modifier
            .fillMaxWidth()
            .height(if (empty) emptyHeight else height)
            .clip(RoundedCornerShape(50))
            .drawBehind {
                if (empty) {
                    var x = 0f
                    val on = 2.dp.toPx()
                    val period = 8.dp.toPx()
                    while (x < size.width) {
                        drawRect(Ownify.white(0.11f), topLeft = Offset(x, 0f), size = Size(minOf(on, size.width - x), size.height))
                        x += period
                    }
                    return@drawBehind
                }
                drawRect(track)
                val w = size.width * shown
                if (w > 0f) {
                    drawRoundRect(
                        brush = fill ?: Brush.horizontalGradient(
                            listOf(accent, Ownify.mix(accent, 0.65f, Color.White)),
                            startX = 0f,
                            endX = w
                        ),
                        size = Size(w, size.height),
                        cornerRadius = androidx.compose.ui.geometry.CornerRadius(size.height / 2f)
                    )
                }
            }
    )
}

/**
 * `.score-ring`: a 10-wide track at `.07` round a glow, and the value drawn
 * from 12 o'clock clockwise with round caps over 1100 ms — in the health →
 * activity → nutrition gradient on Overzicht, in the area's accent on a
 * detail page. Empty, the track is a dotted line and the number reads "—".
 */
@Composable
fun ScoreRing(
    value: Int?,
    max: Int,
    scale: String,
    label: String,
    play: Boolean,
    modifier: Modifier = Modifier,
    size: Dp = LocalScreen.current.ringSize,
    accent: Accent? = null,
    numberSize: TextUnit = LocalScreen.current.fsScore,
    description: String? = null
) {
    val empty = value == null
    val target = if (value == null || max <= 0) 0f else (value.toFloat() / max).coerceIn(0f, 1f)
    val shown = animatedShare(target, play, durationMs = 1100)
    val number = countUpText(value?.toString() ?: "—", play && !empty)
    val glow = accent?.color ?: Ownify.Health

    Box(
        modifier
            .size(size)
            .clearAndSetSemantics {
                contentDescription = description ?: if (value != null) "$label: $value van $max" else "$label: $scale"
            },
        contentAlignment = Alignment.Center
    ) {
        Canvas(Modifier.fillMaxSize()) {
            val unit = this.size.width / 160f
            // ::before — inset 18%, a radial glow reaching transparent at 70% of the corner distance.
            val side = this.size.width * 0.64f
            val glowColor = if (empty) Color.White.copy(alpha = 0.05f) else glow.copy(alpha = 0.16f)
            drawCircle(
                brush = Brush.radialGradient(
                    0f to glowColor,
                    0.7f to glowColor.copy(alpha = 0f),
                    center = center,
                    radius = side * 0.70710677f
                ),
                radius = side / 2f,
                center = center
            )

            val r = 68f * unit
            if (empty) {
                drawCircle(
                    color = Color.White.copy(alpha = 0.26f),
                    radius = r,
                    style = Stroke(
                        width = 3.5f * unit,
                        cap = StrokeCap.Round,
                        pathEffect = PathEffect.dashPathEffect(floatArrayOf(0.5f * unit, 9f * unit))
                    )
                )
                return@Canvas
            }

            drawCircle(color = Color.White.copy(alpha = 0.07f), radius = r, style = Stroke(10f * unit))

            if (shown > 0f) {
                val topLeft = Offset(center.x - r, center.y - r)
                val arc = Size(r * 2, r * 2)
                // The SVG is turned -90°, gradient and all: from the bottom-left to the top-right.
                val brush = if (accent != null) SolidColor(accent.color) else Brush.linearGradient(
                    0f to Ownify.Health,
                    0.55f to Ownify.Activity,
                    1f to Ownify.Nutrition,
                    start = Offset(topLeft.x, topLeft.y + arc.height),
                    end = Offset(topLeft.x + arc.width, topLeft.y)
                )
                drawArc(
                    brush = brush,
                    startAngle = -90f,
                    sweepAngle = 360f * shown,
                    useCenter = false,
                    topLeft = topLeft,
                    size = arc,
                    style = Stroke(10f * unit, cap = StrokeCap.Round)
                )
            }
        }

        Column(horizontalAlignment = Alignment.CenterHorizontally) {
            T(
                number,
                OwnifyType.style(
                    numberSize, FontWeight.Bold,
                    if (empty) Ownify.TextSecondary else Ownify.TextPrimary,
                    tracking = (-0.045).em, lineHeight = 1.em, tabular = true
                )
            )
            Spacer(Modifier.height(Ownify.Space2))
            T(scale, JStyle.Tiny)
        }
    }
}

/** One pillar of the legend: its accent, its name, its score (or "—"). */
data class LegendItem(val label: String, val value: Int?, val accent: Accent)

/**
 * `.legend`: three equal columns in a quiet 20-radius box, each a dot with
 * its 3 px halo, the name and the score. One column below 360 dp.
 */
@Composable
fun Legend(items: List<LegendItem>, modifier: Modifier = Modifier, values: Boolean = true) {
    val narrow = LocalScreen.current.narrow
    val shape = RoundedCornerShape(Ownify.RadiusMd)
    val box = modifier
        .fillMaxWidth()
        .clip(shape)
        .background(Ownify.white(0.035f))
        .border(1.dp, Ownify.GlassHairline, shape)
        .cssPadding(PaddingValues(vertical = Ownify.Space3, horizontal = Ownify.Space2), border = 1.dp)

    @Composable
    fun Item(item: LegendItem, rowModifier: Modifier) {
        Row(
            rowModifier,
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(Ownify.Space2, if (narrow) Alignment.Start else Alignment.CenterHorizontally)
        ) {
            Box(
                Modifier
                    .size(7.dp)
                    .drawBehind {
                        drawCircle(item.accent.color.copy(alpha = 0.18f), radius = size.width / 2f + 3.dp.toPx())
                        drawCircle(item.accent.color)
                    }
            )
            T(item.label, OwnifyType.style(Ownify.FsTiny, color = Ownify.TextSecondary), maxLines = 1)
            if (values) T(
                item.value?.toString() ?: "—",
                OwnifyType.style(
                    Ownify.FsTiny,
                    if (item.value == null) FontWeight.Medium else FontWeight.SemiBold,
                    if (item.value == null) Ownify.TextMuted else Ownify.TextPrimary,
                    tabular = true
                ),
                maxLines = 1
            )
        }
    }

    if (narrow) {
        Column(box, verticalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
            items.forEach { Item(it, Modifier) }
        }
    } else {
        Row(box, horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
            items.forEach { Item(it, Modifier.weight(1f)) }
        }
    }
}

/**
 * `.card__hint`: a line under a hairline — centred with its icon, or
 * `--plain`: left-aligned, 12 above instead of 16.
 */
@Composable
fun CardHint(
    text: String,
    modifier: Modifier = Modifier,
    icon: ImageVector? = OwnifyIcons.lock,
    plain: Boolean = false,
    // It takes its card's text-align: centred in a hero card, from the start elsewhere.
    textAlign: TextAlign = TextAlign.Start
) {
    Column(modifier.fillMaxWidth().padding(top = Ownify.Space4)) {
        Box(Modifier.fillMaxWidth().height(1.dp).background(Ownify.GlassHairline))
        Row(
            Modifier
                .fillMaxWidth()
                .padding(top = if (plain) Ownify.Space3 else Ownify.Space4),
            horizontalArrangement = Arrangement.spacedBy(Ownify.Space2, if (plain) Alignment.Start else Alignment.CenterHorizontally),
            verticalAlignment = Alignment.CenterVertically
        ) {
            if (icon != null) JIcon(icon, size = 14.dp, color = Ownify.TextMuted)
            T(text, JStyle.Tiny, align = if (plain) TextAlign.Start else textAlign)
        }
    }
}

// ---------------------------------------------------------------------------
// Controls
// ---------------------------------------------------------------------------

/**
 * `.range-switch`: options in a hairline capsule, the chosen one a lit pane
 * of its own. [wide] is `--wide`: the options share the width equally.
 */
@Composable
fun RangeSwitch(
    options: List<Pair<String, String>>,
    selected: String?,
    onSelect: (String) -> Unit,
    modifier: Modifier = Modifier,
    wide: Boolean = false,
    optionHeight: Dp = if (wide) 34.dp else 32.dp,
    optionPadding: Dp = if (wide) Ownify.Space2 else Ownify.Space3,
    label: String? = null
) {
    InButton {
        val shape = RoundedCornerShape(50)
        val shadows = LocalGraphicsContext.current.shadowContext
        Row(
            modifier
                .then(if (wide) Modifier.fillMaxWidth() else Modifier)
                .clip(shape)
                .background(Ownify.white(0.04f))
                .border(1.dp, Ownify.GlassHairline, shape)
                .cssPadding(PaddingValues(3.dp), border = 1.dp)
                .semantics { if (label != null) contentDescription = label },
            horizontalArrangement = Arrangement.spacedBy(2.dp)
        ) {
            for ((key, text) in options) {
                val active = key == selected
                val color by animateColorAsState(
                    if (active) Ownify.TextPrimary else Ownify.TextMuted,
                    tween(Ownify.FastMs, easing = Ownify.Ease),
                    label = "option"
                )
                Box(
                    Modifier
                        .then(if (wide) Modifier.weight(1f) else Modifier)
                        .heightIn(min = optionHeight)
                        .drawWithContent {
                            if (active) {
                                val outline = shape.createOutline(size, layoutDirection, this)
                                val path = androidx.compose.ui.graphics.Path().apply { addOutline(outline) }
                                drawPath(path, Brush.verticalGradient(listOf(Ownify.white(0.12f), Ownify.white(0.03f))))
                                drawBoxShadows(shape, listOf(BoxShadow(y = 1.dp, blur = 0.dp, color = Ownify.white(0.08f), inset = true)), shadows, outline)
                                drawBorder(outline, Ownify.GlassHairline)
                            }
                            drawContent()
                        }
                        .clip(shape)
                        .clickable(role = Role.Button, onClick = blurring { onSelect(key) })
                        .semantics { this.selected = active }
                        // `border: 1px solid transparent` on every option: it takes room either way.
                        .cssPadding(PaddingValues(horizontal = optionPadding), border = 1.dp),
                    contentAlignment = Alignment.Center
                ) {
                    T(text, OwnifyType.style(Ownify.FsTiny, FontWeight.SemiBold, color), maxLines = 1)
                }
            }
        }
    }
}

/** `.switch`: 42 × 25, the knob 17; on, the health green at 34% and a white knob moved 17. */
@Composable
fun Toggle(on: Boolean, modifier: Modifier = Modifier, dimmed: Boolean = false) {
    val shape = RoundedCornerShape(50)
    val x by animateDpAsState(if (on) 17.dp else 0.dp, tween(Ownify.FastMs, easing = Ownify.Ease), label = "knob")
    val fill = if (on) Ownify.Health.copy(alpha = 0.34f) else Ownify.white(0.07f)
    val border = if (on) Ownify.Health.copy(alpha = 0.34f) else Ownify.GlassBorderSoft
    Box(
        modifier
            .alpha(if (dimmed) 0.55f else 1f)
            .size(42.dp, 25.dp)
            .clip(shape)
            .background(fill)
            .border(1.dp, border, shape)
    ) {
        Box(
            Modifier
                // top: 3px; left: 3px — inside the 1 px border.
                .offset { IntOffset((4.dp + x).roundToPx(), 4.dp.roundToPx()) }
                .size(17.dp)
                .background(if (on) Color.White else Ownify.white(0.55f), CircleShape)
        )
    }
}

/**
 * `.account__input` (and the wizard's and editor's): at least 44 high, 12
 * either side, the soft border and `.05`, 14 sp; focused, the border turns
 * the green at 50% and the fill `.07`.
 */
@Composable
fun JInput(
    value: String,
    onValueChange: (String) -> Unit,
    modifier: Modifier = Modifier,
    placeholder: String = "",
    keyboard: KeyboardOptions = KeyboardOptions.Default,
    actions: KeyboardActions = KeyboardActions.Default,
    password: Boolean = false,
    enabled: Boolean = true,
    radius: Dp = Ownify.RadiusSm,
    textSize: TextUnit = Ownify.FsLabel,
    label: String? = null
) {
    var focused by remember { mutableStateOf(false) }
    val shape = RoundedCornerShape(radius)
    val border = if (focused) Ownify.Health.copy(alpha = 0.5f) else Ownify.GlassBorderSoft
    val fill = if (focused) Ownify.white(0.07f) else Ownify.white(0.05f)
    // A field's text, like a button's, keeps `letter-spacing: normal`.
    val style = OwnifyType.style(textSize, color = Ownify.TextPrimary, lineHeight = 1.3.em, tracking = 0.sp)

    BasicTextField(
        value = value,
        onValueChange = onValueChange,
        modifier = modifier
            .fillMaxWidth()
            .heightIn(min = 44.dp)
            .onFocusChanged { focused = it.isFocused }
            .semantics { if (label != null) contentDescription = label },
        enabled = enabled,
        singleLine = true,
        textStyle = style,
        cursorBrush = SolidColor(Ownify.TextPrimary),
        keyboardOptions = if (password) keyboard.copy(keyboardType = KeyboardType.Password) else keyboard,
        keyboardActions = actions,
        visualTransformation = if (password) PasswordVisualTransformation() else VisualTransformation.None,
        decorationBox = { inner ->
            Box(
                Modifier
                    .heightIn(min = 44.dp)
                    .clip(shape)
                    .background(fill)
                    .border(1.dp, border, shape)
                    .cssPadding(PaddingValues(horizontal = Ownify.Space3), border = 1.dp),
                contentAlignment = Alignment.CenterStart
            ) {
                if (value.isEmpty() && placeholder.isNotEmpty()) {
                    T(placeholder, style.copy(color = Ownify.TextFaint), maxLines = 1)
                }
                inner()
            }
        }
    )
}

/**
 * A panel's first field, focused as the website focuses it when the panel
 * opens — `first.focus()` in the same tap, so on a phone the keyboard comes
 * up with it. Once per panel, not each time the field is composed again.
 */
@Composable
fun rememberFocusOnOpen(): FocusRequester {
    val requester = remember { FocusRequester() }
    LaunchedEffect(requester) {
        withFrameNanos { }
        requester.focusSafely()
    }
    return requester
}

/**
 * [onClick], after letting go of the focus: a tapped `<button>` takes focus
 * on the website, so a field that had it loses it and the keyboard goes.
 */
@Composable
fun blurring(onClick: () -> Unit): () -> Unit {
    val focus = LocalFocusManager.current
    return {
        focus.clearFocus()
        onClick()
    }
}

/** Focus the field, as the website's `input.focus()` does; nothing if it is not on screen. */
fun FocusRequester.focusSafely() {
    runCatching { requestFocus() }
}

/** `.disclaimer`: the line under a page, when there is one. */
@Composable
fun Disclaimer(text: String, modifier: Modifier = Modifier) {
    if (text.isBlank()) return
    T(
        text,
        JStyle.Tiny,
        modifier.fillMaxWidth().padding(top = Ownify.Space2).padding(horizontal = Ownify.Space2),
        align = TextAlign.Center
    )
}

/** `.page-intro`: the page's title and one line under it, 8 in from the column. */
@Composable
fun PageIntro(title: String, lede: String?, modifier: Modifier = Modifier) {
    Column(modifier.fillMaxWidth().padding(horizontal = Ownify.Space2)) {
        // `h1.page-intro__title`: the page's heading.
        T(title, JStyle.Section, Modifier.semantics { heading() })
        if (!lede.isNullOrBlank()) {
            T(lede, JStyle.Meta, Modifier.padding(top = Ownify.Space1))
        }
    }
}

/** Width of the column (`--shell-w`), centred: `.shell`. */
@Composable
fun Modifier.shell(): Modifier = this.width(LocalScreen.current.shell)
