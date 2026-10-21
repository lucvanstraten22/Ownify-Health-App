package com.ownify.android.ui.design

import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.clickable
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.composed
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.drawWithContent
import androidx.compose.ui.draw.rotate
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.Shape
import androidx.compose.ui.graphics.addOutline
import androidx.compose.ui.graphics.drawscope.clipPath
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalGraphicsContext
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.disabled
import androidx.compose.ui.semantics.onClick
import androidx.compose.ui.semantics.onLongClick
import androidx.compose.ui.semantics.role
import androidx.compose.ui.semantics.selected
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.stateDescription
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.em
import com.ownify.android.ui.theme.InButton
import com.ownify.android.ui.theme.Ownify
import com.ownify.android.ui.theme.OwnifyType

/*
 * Floating glass, small — components.css `.glass`: the sync capsule's
 * material (PullSyncIndicator's Panes.HeaderButton) for controls. The same
 * tint, 177° face, top glint and 152° rim, in both themes. These sit on a
 * panel that is glass already, so what is behind them is the panel's own
 * frosted surface: they draw the material without a second blur, as the
 * website's look once the panel behind is blurred.
 *
 *   GlassChip    Health Connect's data chips' size (Chip): 4 × 12, 11 sp
 *   GlassRound   a 44 dp circle for one icon (34 dp small)
 *   GlassCta     the one way forward: the app's green in the glass
 *   GlassSelect  a unit or a period, chosen from a short list
 *
 * Chosen: the green in the glass and in its rim — restrained, no tick.
 */

/** How a piece of glass is drawn: chosen (the green in it), the primary (green), the cautious action (gold). */
data class GlassLook(val chosen: Boolean = false, val cta: Boolean = false, val caution: Boolean = false, val shadow: Dp = 20.dp, val lift: Dp = 8.dp)

/** `.glass`: the Liquid Glass (Panes, theme.css `--lg-*`), in [shape]. */
fun Modifier.glass(shape: Shape, look: GlassLook = GlassLook()): Modifier = composed {
    val small = look.shadow < 10.dp
    val base = if (small) Panes.Chip else Panes.Control
    val style = remember(look, base) {
        when {
            look.cta -> base.copy(extra = Panes.GreenWash, rim = Panes.GreenRim)
            look.caution -> base.copy(extra = Panes.GoldWash, rim = Panes.GoldRim)
            look.chosen -> base.copy(extra = ChosenWash, rim = Panes.GreenRim)
            else -> base
        }
    }
    this.liquidGlass(shape, style)
}

/** Chosen: `--health-fill` in the glass. */
private val ChosenWash: (Size) -> Brush = { Brush.verticalGradient(listOf(Ownify.HealthFill, Ownify.HealthFill)) }

/**
 * `.glass--chip`: a bubble the size of Health Connect's data chips, taking
 * taps over 48 dp (hitArea) without being drawn any larger.
 */
@Composable
fun GlassChip(
    text: String,
    chosen: Boolean,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    radio: Boolean = false,
    enabled: Boolean = true
) {
    InButton {
        val interaction = remember { MutableInteractionSource() }
        val shape = RoundedCornerShape(50)
        Box(
            modifier.hitArea(
                Modifier
                    .clickable(interaction, indication = null, enabled = enabled, role = if (radio) Role.RadioButton else Role.Button, onClick = blurring(onClick))
                    .semantics(mergeDescendants = true) { selected = chosen },
                across = false
            )
        ) {
            T(
                text,
                OwnifyType.style(Ownify.FsTiny, if (chosen) FontWeight.SemiBold else FontWeight.Medium, if (chosen) Ownify.TextPrimary else Ownify.TextSecondary, tracking = 0.em),
                Modifier
                    .press(interaction, enabled = enabled)
                    .alpha(if (enabled) 1f else 0.5f)
                    .glass(shape, GlassLook(chosen = chosen, shadow = 7.dp, lift = 2.dp))
                    .cssPadding(PaddingValues(horizontal = Ownify.Space3, vertical = Ownify.Space1), border = 1.dp),
                maxLines = 1
            )
        }
    }
}

/**
 * `.glass--round`: one icon in a 44 dp glass circle — the wizard's back
 * button, the direction buttons. [onLongPress]: what a long press says
 * (the direction buttons' explanation), also offered to TalkBack.
 */
@OptIn(ExperimentalFoundationApi::class)
@Composable
fun GlassRound(
    icon: ImageVector,
    label: String,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    chosen: Boolean? = null,
    size: Dp = 44.dp,
    enabled: Boolean = true,
    longPressLabel: String? = null,
    onLongPress: (() -> Unit)? = null
) {
    InButton {
        val interaction = remember { MutableInteractionSource() }
        Box(
            modifier
                .press(interaction, enabled = enabled)
                .size(size)
                .alpha(if (enabled) 1f else 0.5f)
                .glass(CircleShape, GlassLook(chosen = chosen == true))
                .hitArea(
                    Modifier
                        .combinedClickable(
                            interactionSource = interaction,
                            indication = null,
                            enabled = enabled,
                            role = if (chosen != null) Role.RadioButton else Role.Button,
                            onLongClick = onLongPress,
                            onClick = blurring(onClick)
                        )
                        .clearAndSetSemantics {
                            contentDescription = label
                            role = if (chosen != null) Role.RadioButton else Role.Button
                            if (chosen != null) selected = chosen
                            if (!enabled) disabled()
                            onClick(label) { onClick(); true }
                            if (onLongPress != null) onLongClick(longPressLabel ?: label) { onLongPress(); true }
                        }
                ),
            contentAlignment = Alignment.Center
        ) {
            JIcon(icon, size = if (size < 40.dp) 16.dp else 18.dp, color = if (chosen == true) Ownify.Health else Ownify.TextSecondary)
        }
    }
}

/**
 * `.glass--cta`: the one way forward, with the app's green in the glass —
 * quiet while it cannot be taken; a spinner while [loading].
 */
@Composable
fun GlassCta(text: String, onClick: () -> Unit, modifier: Modifier = Modifier, enabled: Boolean = true, loading: Boolean = false, caution: Boolean = false) {
    InButton {
        val interaction = remember { MutableInteractionSource() }
        Row(
            modifier
                .press(interaction, enabled = enabled && !loading)
                .heightIn(min = 44.dp)
                .glass(RoundedCornerShape(50), GlassLook(cta = enabled && !caution, caution = enabled && caution))
                .clickable(interaction, indication = null, enabled = enabled && !loading, role = Role.Button, onClick = blurring(onClick))
                .semantics { if (loading) stateDescription = text }
                .padding(horizontal = Ownify.Space5),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(Ownify.Space2, Alignment.CenterHorizontally)
        ) {
            if (loading) Spinner(15.dp)
            T(
                text,
                OwnifyType.style(Ownify.FsLabel, FontWeight.SemiBold, if (enabled) Ownify.TextPrimary else Ownify.TextMuted),
                maxLines = 1
            )
        }
    }
}

/**
 * `.glass--select`: the chosen option and a chevron in the glass; a tap
 * opens the short list. Always there — also with one option, so a unit reads
 * the same whatever can be chosen.
 */
@Composable
fun GlassSelect(
    options: List<Pair<String, String>>,
    selected: String?,
    onSelect: (String) -> Unit,
    label: String,
    modifier: Modifier = Modifier
) {
    var open by remember { mutableStateOf(false) }
    val current = options.firstOrNull { it.first == selected }?.second ?: options.firstOrNull()?.second.orEmpty()
    InButton {
        val interaction = remember { MutableInteractionSource() }
        Box(modifier) {
            Row(
                Modifier
                    .press(interaction)
                    .heightIn(min = 44.dp)
                    .glass(RoundedCornerShape(50))
                    .clickable(interaction, indication = null, role = Role.DropdownList) { open = true }
                    .semantics { contentDescription = "$label: $current" }
                    .padding(start = Ownify.Space4, end = Ownify.Space3),
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)
            ) {
                // As wide as its widest option, like the website's <select>: choosing
                // never moves what is beside it, nor the menu hanging from it.
                Box {
                    options.forEach { (_, text) ->
                        T(text, OwnifyType.style(Ownify.FsInput, FontWeight.Medium), Modifier.alpha(0f).clearAndSetSemantics { }, maxLines = 1)
                    }
                    T(current, OwnifyType.style(Ownify.FsInput, FontWeight.Medium), maxLines = 1)
                }
                JIcon(OwnifyIcons.chevronDown, size = 14.dp, color = Ownify.TextMuted)
            }
            DropdownMenu(
                expanded = open,
                onDismissRequest = { open = false },
                shape = RoundedCornerShape(Ownify.RadiusSm),
                containerColor = Ownify.TipSurfaceSolid,
                shadowElevation = 8.dp
            ) {
                options.forEach { (key, text) ->
                    DropdownMenuItem(
                        text = {
                            T(
                                text,
                                OwnifyType.style(Ownify.FsLabel, if (key == selected) FontWeight.SemiBold else FontWeight.Normal,
                                    if (key == selected) Ownify.Health else Ownify.TextPrimary)
                            )
                        },
                        onClick = {
                            open = false
                            onSelect(key)
                        },
                        modifier = Modifier.semantics { this.selected = key == selected }
                    )
                }
            }
        }
    }
}

/** An arc turning on its track — saving, no progress to report. Still with reduced motion. */
@Composable
fun Spinner(size: Dp) {
    val angle = loopValue(0f, 360f, infiniteRepeatable(tween(800, easing = LinearEasing)), still = 0f)
    val track = Ownify.HealthEdge
    val color = Ownify.Health
    Canvas(Modifier.size(size).rotate(angle)) {
        val inset = 1.dp.toPx()
        val box = Size(this.size.width - 2 * inset, this.size.height - 2 * inset)
        val at = androidx.compose.ui.geometry.Offset(inset, inset)
        drawArc(track, 0f, 360f, false, at, box, style = Stroke(2.dp.toPx()))
        drawArc(color, -90f, 108f, false, at, box, style = Stroke(2.dp.toPx(), cap = StrokeCap.Round))
    }
}
