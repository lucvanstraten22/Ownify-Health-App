package com.healthapp.android.ui.design

import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.animateDpAsState
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxWithConstraints
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
import androidx.compose.foundation.layout.widthIn
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
import androidx.compose.ui.graphics.drawscope.rotate
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.graphics.vector.rememberVectorPainter
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.platform.LocalGraphicsContext
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.role
import androidx.compose.ui.semantics.selected
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.stateDescription
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.text.rememberTextMeasurer
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.TextUnit
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.em
import androidx.compose.ui.unit.sp
import com.healthapp.android.ui.theme.Accent
import com.healthapp.android.ui.theme.Jolu
import com.healthapp.android.ui.theme.JoluType
import com.healthapp.android.ui.theme.LocalAccent

// ---------------------------------------------------------------------------
// Type — the website's text classes
// ---------------------------------------------------------------------------

object JStyle {
    /** `.card__title`, `.page-intro__title`, `.score-ring__label`. */
    val Section = JoluType.style(Jolu.FsSection, FontWeight.SemiBold, tracking = (-0.02).em)

    /** `.card__eyebrow`. */
    val Eyebrow = JoluType.style(Jolu.FsLabel, FontWeight.SemiBold)

    /** `.card__caption`, `.settings-eyebrow`: small caps, spaced. */
    val Caption = JoluType.style(Jolu.FsTiny, FontWeight.SemiBold, Jolu.TextMuted, tracking = Jolu.TrackingWide)

    /** `.card__subtitle`, `.goal__name`, `.confirm__title`. */
    val Subtitle = JoluType.style(17.sp, FontWeight.SemiBold, tracking = (-0.015).em)

    val Meta = JoluType.style(Jolu.FsSmall, color = Jolu.TextMuted)
    val Lede = JoluType.style(Jolu.FsSmall, color = Jolu.TextSecondary)
    val Small = JoluType.style(Jolu.FsSmall)
    val Label = JoluType.style(Jolu.FsLabel)
    val Tiny = JoluType.style(Jolu.FsTiny, color = Jolu.TextMuted)
    val Body = JoluType.Base
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
    BasicText(
        text = if (uppercase) text.uppercase(java.util.Locale.forLanguageTag("nl")) else text,
        modifier = modifier,
        style = style.let { s ->
            var out = s
            if (color != null) out = out.copy(color = color)
            if (align != null) out = out.copy(textAlign = align)
            out
        },
        maxLines = maxLines,
        overflow = if (ellipsis) TextOverflow.Ellipsis else TextOverflow.Clip,
        softWrap = maxLines != 1
    )
}

/** `max-width: <n>ch` — n widths of the digit zero in [style]. */
@Composable
fun chWidth(style: TextStyle, n: Float): Dp {
    val measurer = rememberTextMeasurer()
    val density = LocalDensity.current
    val width = remember(style, n) { measurer.measure("0", style).size.width * n }
    return with(density) { width.toDp() }
}

// ---------------------------------------------------------------------------
// Icons
// ---------------------------------------------------------------------------

/** An icon of the set, in [color] (`currentColor`), [size] square (`.icon` is 20). */
@Composable
fun JIcon(icon: ImageVector, modifier: Modifier = Modifier, size: Dp = 20.dp, color: Color = Jolu.TextPrimary) {
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
    radius: Dp = Jolu.RadiusSm,
    iconSize: Dp = 18.dp,
    color: Color = Jolu.TextSecondary,
    background: Color = Jolu.white(0.065f),
    border: Color = Jolu.GlassHairline
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
    border: Color = Jolu.GlassHairline,
    background: Color = Jolu.white(0.055f),
    color: Color = if (muted) Jolu.TextMuted else Jolu.TextSecondary,
    leading: (@Composable () -> Unit)? = null
) {
    val shape = RoundedCornerShape(50)
    Row(
        modifier
            .clip(shape)
            .background(background)
            .border(1.dp, border, shape)
            .padding(horizontal = Jolu.Space3, vertical = Jolu.Space1),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(if (leading != null) 6.dp else Jolu.Space2)
    ) {
        if (dot) Box(Modifier.size(6.dp).background(Jolu.Health, CircleShape))
        leading?.invoke()
        T(
            text,
            when {
                muted -> JoluType.style(Jolu.FsTiny, FontWeight.Medium, color, tracking = 0.em)
                quiet -> JoluType.style(Jolu.FsTiny, FontWeight.SemiBold, color, tracking = 0.em)
                else -> JoluType.style(Jolu.FsTiny, FontWeight.SemiBold, color, tracking = Jolu.TrackingWide)
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
        border = Jolu.mix(accent, 0.34f, Color.Transparent),
        background = Jolu.mix(accent, 0.16f, Color.Transparent),
        color = Jolu.mix(accent, 0.40f, Color.White)
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
    padding: PaddingValues = PaddingValues(start = Jolu.Space2, end = Jolu.Space3)
) {
    val interaction = remember { MutableInteractionSource() }
    val shape = RoundedCornerShape(50)
    Row(
        modifier
            .press(interaction)
            .heightIn(min = 42.dp)
            .clip(shape)
            .background(Jolu.GlassSoft)
            .border(1.dp, Jolu.GlassBorderSoft, shape)
            .clickable(interaction, indication = null, role = Role.Button, onClick = onClick)
            .semantics { if (contentDescription != null) this.contentDescription = contentDescription }
            .padding(padding),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Jolu.Space2)
    ) {
        if (icon != null) JIcon(icon, size = 18.dp, color = Jolu.TextSecondary)
        T(label, JoluType.style(Jolu.FsSmall, FontWeight.Medium, Jolu.TextSecondary), maxLines = 1)
    }
}

/**
 * What a button looks like beyond `.btn`: its border and fill (the confirm's
 * yes, the wizard's next), and how far it fades while disabled.
 */
data class BtnLook(
    val border: Color = Jolu.GlassBorder,
    val fill: Color = Jolu.Glass,
    val text: Color = Jolu.TextPrimary,
    val disabledAlpha: Float = 0.45f
) {
    companion object {
        /** `.confirm__yes--final`: the step that cannot be undone, in the miss red; 60% while it works. */
        val Final = BtnLook(border = Jolu.mix(Jolu.Miss, 0.60f, Color.Transparent), fill = Jolu.mix(Jolu.Miss, 0.24f, Color.Transparent), disabledAlpha = 0.6f)
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
            .clickable(interaction, indication = null, enabled = enabled, role = Role.Button, onClick = onClick)
            .semantics { if (contentDescription != null) this.contentDescription = contentDescription }
            .padding(horizontal = Jolu.Space4),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Jolu.Space2, Alignment.CenterHorizontally)
    ) {
        if (content != null) {
            content()
        } else {
            if (icon != null) JIcon(icon, size = iconSize, color = look.text)
            T(label, JoluType.style(Jolu.FsSmall, FontWeight.SemiBold, look.text), maxLines = 1)
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
    track: Color = Jolu.white(0.08f)
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
                        drawRect(Jolu.white(0.11f), topLeft = Offset(x, 0f), size = Size(minOf(on, size.width - x), size.height))
                        x += period
                    }
                    return@drawBehind
                }
                drawRect(track)
                val w = size.width * shown
                if (w > 0f) {
                    drawRoundRect(
                        brush = fill ?: Brush.horizontalGradient(
                            listOf(accent, Jolu.mix(accent, 0.65f, Color.White)),
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
    val glow = accent?.color ?: Jolu.Health

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
                    0f to Jolu.Health,
                    0.55f to Jolu.Activity,
                    1f to Jolu.Nutrition,
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
                JoluType.style(
                    numberSize, FontWeight.Bold,
                    if (empty) Jolu.TextSecondary else Jolu.TextPrimary,
                    tracking = (-0.045).em, lineHeight = 1.em, tabular = true
                )
            )
            Spacer(Modifier.height(Jolu.Space2))
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
    val shape = RoundedCornerShape(Jolu.RadiusMd)
    val box = modifier
        .fillMaxWidth()
        .clip(shape)
        .background(Jolu.white(0.035f))
        .border(1.dp, Jolu.GlassHairline, shape)
        .padding(vertical = Jolu.Space3, horizontal = Jolu.Space2)

    @Composable
    fun Item(item: LegendItem, rowModifier: Modifier) {
        Row(
            rowModifier,
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(Jolu.Space2, if (narrow) Alignment.Start else Alignment.CenterHorizontally)
        ) {
            Box(
                Modifier
                    .size(7.dp)
                    .drawBehind {
                        drawCircle(item.accent.color.copy(alpha = 0.18f), radius = size.width / 2f + 3.dp.toPx())
                        drawCircle(item.accent.color)
                    }
            )
            T(item.label, JoluType.style(Jolu.FsTiny, color = Jolu.TextSecondary), maxLines = 1)
            if (values) T(
                item.value?.toString() ?: "—",
                JoluType.style(
                    Jolu.FsTiny,
                    if (item.value == null) FontWeight.Medium else FontWeight.SemiBold,
                    if (item.value == null) Jolu.TextMuted else Jolu.TextPrimary,
                    tabular = true
                ),
                maxLines = 1
            )
        }
    }

    if (narrow) {
        Column(box, verticalArrangement = Arrangement.spacedBy(Jolu.Space2)) {
            items.forEach { Item(it, Modifier) }
        }
    } else {
        Row(box, horizontalArrangement = Arrangement.spacedBy(Jolu.Space2)) {
            items.forEach { Item(it, Modifier.weight(1f)) }
        }
    }
}

/**
 * `.card__hint`: a line under a hairline — centred with its icon, or
 * `--plain`: left-aligned, 12 above instead of 16.
 */
@Composable
fun CardHint(text: String, modifier: Modifier = Modifier, icon: ImageVector? = JoluIcons.lock, plain: Boolean = false) {
    Column(modifier.fillMaxWidth().padding(top = Jolu.Space4)) {
        Box(Modifier.fillMaxWidth().height(1.dp).background(Jolu.GlassHairline))
        Row(
            Modifier
                .fillMaxWidth()
                .padding(top = if (plain) Jolu.Space3 else Jolu.Space4),
            horizontalArrangement = Arrangement.spacedBy(Jolu.Space2, if (plain) Alignment.Start else Alignment.CenterHorizontally),
            verticalAlignment = Alignment.CenterVertically
        ) {
            if (icon != null) JIcon(icon, size = 14.dp, color = Jolu.TextMuted)
            T(text, JStyle.Tiny, align = if (plain) TextAlign.Start else TextAlign.Center)
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
    optionPadding: Dp = if (wide) Jolu.Space2 else Jolu.Space3,
    label: String? = null
) {
    val shape = RoundedCornerShape(50)
    val shadows = LocalGraphicsContext.current.shadowContext
    Row(
        modifier
            .then(if (wide) Modifier.fillMaxWidth() else Modifier)
            .clip(shape)
            .background(Jolu.white(0.04f))
            .border(1.dp, Jolu.GlassHairline, shape)
            .padding(3.dp)
            .semantics { if (label != null) contentDescription = label },
        horizontalArrangement = Arrangement.spacedBy(2.dp)
    ) {
        for ((key, text) in options) {
            val active = key == selected
            val color by animateColorAsState(
                if (active) Jolu.TextPrimary else Jolu.TextMuted,
                tween(Jolu.FastMs, easing = Jolu.Ease),
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
                            drawPath(path, Brush.verticalGradient(listOf(Jolu.white(0.12f), Jolu.white(0.03f))))
                            drawBoxShadows(shape, listOf(BoxShadow(y = 1.dp, blur = 0.dp, color = Jolu.white(0.08f), inset = true)), shadows, outline)
                            drawBorder(outline, Jolu.GlassHairline)
                        }
                        drawContent()
                    }
                    .clip(shape)
                    .clickable(role = Role.Button) { onSelect(key) }
                    .semantics { this.selected = active }
                    .padding(horizontal = optionPadding),
                contentAlignment = Alignment.Center
            ) {
                T(text, JoluType.style(Jolu.FsTiny, FontWeight.SemiBold, color), maxLines = 1)
            }
        }
    }
}

/** `.switch`: 42 × 25, the knob 17; on, the health green at 34% and a white knob moved 17. */
@Composable
fun Toggle(on: Boolean, modifier: Modifier = Modifier, dimmed: Boolean = false) {
    val shape = RoundedCornerShape(50)
    val x by animateDpAsState(if (on) 17.dp else 0.dp, tween(Jolu.FastMs, easing = Jolu.Ease), label = "knob")
    val fill = if (on) Jolu.Health.copy(alpha = 0.34f) else Jolu.white(0.07f)
    val border = if (on) Jolu.Health.copy(alpha = 0.34f) else Jolu.GlassBorderSoft
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
                .offset(x = 4.dp + x, y = 4.dp)
                .size(17.dp)
                .background(if (on) Color.White else Jolu.white(0.55f), CircleShape)
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
    radius: Dp = Jolu.RadiusSm,
    textSize: TextUnit = Jolu.FsLabel,
    label: String? = null
) {
    var focused by remember { mutableStateOf(false) }
    val shape = RoundedCornerShape(radius)
    val border = if (focused) Jolu.Health.copy(alpha = 0.5f) else Jolu.GlassBorderSoft
    val fill = if (focused) Jolu.white(0.07f) else Jolu.white(0.05f)
    val style = JoluType.style(textSize, color = Jolu.TextPrimary, lineHeight = 1.3.em)

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
        cursorBrush = SolidColor(Jolu.TextPrimary),
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
                    .padding(horizontal = Jolu.Space3),
                contentAlignment = Alignment.CenterStart
            ) {
                if (value.isEmpty() && placeholder.isNotEmpty()) {
                    T(placeholder, style.copy(color = Jolu.TextFaint), maxLines = 1)
                }
                inner()
            }
        }
    )
}

/** `.disclaimer`: the line under a page, when there is one. */
@Composable
fun Disclaimer(text: String, modifier: Modifier = Modifier) {
    if (text.isBlank()) return
    T(
        text,
        JStyle.Tiny,
        modifier.fillMaxWidth().padding(top = Jolu.Space2).padding(horizontal = Jolu.Space2),
        align = TextAlign.Center
    )
}

/** `.page-intro`: the page's title and one line under it, 8 in from the column. */
@Composable
fun PageIntro(title: String, lede: String?, modifier: Modifier = Modifier) {
    Column(modifier.fillMaxWidth().padding(horizontal = Jolu.Space2)) {
        T(title, JStyle.Section)
        if (!lede.isNullOrBlank()) {
            T(lede, JStyle.Meta, Modifier.padding(top = Jolu.Space1))
        }
    }
}

/** Width of the column (`--shell-w`), centred: `.shell`. */
@Composable
fun Modifier.shell(): Modifier = this.width(LocalScreen.current.shell)
