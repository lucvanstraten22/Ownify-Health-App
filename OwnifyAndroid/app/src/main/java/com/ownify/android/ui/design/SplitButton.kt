package com.ownify.android.ui.design

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.interaction.collectIsPressedAsState
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.IntrinsicSize
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.compositionLocalOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.drawBehind
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.TextUnit
import androidx.compose.ui.unit.dp
import com.ownify.android.ui.theme.ChromeText
import com.ownify.android.ui.theme.InButton
import com.ownify.android.ui.theme.Ownify
import com.ownify.android.ui.theme.OwnifyType

/*
 * The split button (50.0; style "Eén capsule", the owner's pick) — `.split`
 * (components.css). Every pair of buttons side by side is one: a single
 * Liquid Glass capsule across the width, its parts divided by a hairline.
 * The parts are not glass of their own; what each does is in its words — the
 * way back quiet (secondary, 500), a plain step the full ink at 600, the way
 * forward in a flow green ([Ownify.GoInk], `.split--go`), the step that cannot
 * be undone gold (`.split--caution`), both 700. Pressed, a part darkens in
 * place; not to be taken yet, its words are the muted ink. The way back sits
 * left, the way forward right.
 */

/** Which part of a split a [Btn] is drawn as, inside a [SplitRow]. */
enum class SplitSide { Back, Forward }

/** A [Btn]'s place in a [SplitRow]: its side, whether a hairline goes before it, and what its words say. */
data class SplitSlot(val side: SplitSide, val divider: Boolean, val go: Boolean = false, val quietBack: Boolean = true)

/** Set by [SplitRow]: a [Btn] under it draws itself as that part of the split. */
val LocalSplitSide = compositionLocalOf<SplitSlot?> { null }

private val Pill = RoundedCornerShape(50)

/** The split's one capsule (`.split`): the Liquid Glass pill its parts sit on. */
fun Modifier.splitCapsule(): Modifier = this.glass(Pill)

/** The hairline before a part (`.split__part ~ .split__part::before`): 1 wide, 11 in from top and bottom. */
private fun Modifier.splitDivider(on: Boolean): Modifier = if (!on) this else drawBehind {
    val inset = 11.dp.toPx()
    drawLine(Ownify.ink(0.16f), Offset(0.5f, inset), Offset(0.5f, size.height - inset), strokeWidth = 1.dp.toPx())
}

/**
 * The split for two existing [Btn]s: [back] on the left, [forward] on the
 * right, each given its part's modifier. A [Btn] with [BtnLook.Caution] on
 * the forward side reads gold; [go] makes the forward side the green way
 * forward. [quietBack] (the default) draws the left part as the way back;
 * two equal choices (Kopiëren | Delen) pass false.
 */
@Composable
fun SplitRow(
    back: @Composable (Modifier) -> Unit,
    forward: @Composable (Modifier) -> Unit,
    modifier: Modifier = Modifier,
    go: Boolean = false,
    quietBack: Boolean = true
) = SplitRow(listOf(back, forward), modifier, go, quietBack)

/** A split of any number of [Btn]s (Terug | Overslaan | Verder): all but the last the way back, the last the way forward. */
@Composable
fun SplitRow(
    parts: List<@Composable (Modifier) -> Unit>,
    modifier: Modifier = Modifier,
    go: Boolean = false,
    quietBack: Boolean = true
) {
    Row(modifier.fillMaxWidth().height(IntrinsicSize.Min).splitCapsule()) {
        parts.forEachIndexed { i, part ->
            val slot = SplitSlot(if (i == parts.lastIndex) SplitSide.Forward else SplitSide.Back, divider = i > 0, go = go, quietBack = quietBack)
            CompositionLocalProvider(LocalSplitSide provides slot) { part(Modifier.weight(1f).fillMaxHeight()) }
        }
    }
}

/** [Btn] inside a [SplitRow]: the part for [slot], its words in the role's ink. */
@Composable
internal fun SplitBtn(slot: SplitSlot, label: String, onClick: () -> Unit, modifier: Modifier, enabled: Boolean, icon: ImageVector?, caution: Boolean, contentDescription: String?, loading: Boolean = false) {
    // Saving (the wizard's last step): the spinner before the muted words, as `.is-saving` on the website.
    val canPress = enabled && !loading
    val (ink, weight) = partInk(slot, canPress, caution)
    Part(modifier.semantics { if (contentDescription != null) this.contentDescription = contentDescription }, canPress, onClick, Alignment.Center, divider = slot.divider) {
        CompositionLocalProvider(LocalSplitSide provides null) {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = androidx.compose.foundation.layout.Arrangement.spacedBy(Ownify.Space2)) {
                if (loading) Spinner(15.dp)
                if (icon != null) JIcon(icon, size = 16.dp, color = ink)
                if (label.isNotEmpty()) ChromeText { T(label, OwnifyType.style(Ownify.FsSubhead, weight, ink), maxLines = 2, align = TextAlign.Center) }
            }
        }
    }
}

/** The words of a part: muted when it cannot be taken, gold / green / quiet / full by its role. */
private fun partInk(slot: SplitSlot, enabled: Boolean, caution: Boolean): Pair<Color, FontWeight> = when {
    !enabled -> Ownify.TextMuted to if (slot.side == SplitSide.Back && slot.quietBack) FontWeight.Medium else FontWeight.SemiBold
    slot.side == SplitSide.Forward && caution -> Ownify.Attention to FontWeight.Bold
    slot.side == SplitSide.Forward && slot.go -> Ownify.GoInk to FontWeight.Bold
    slot.side == SplitSide.Back && slot.quietBack -> Ownify.TextSecondary to FontWeight.Medium
    else -> Ownify.TextPrimary to FontWeight.SemiBold
}

/**
 * One part of a split with content of its own — a date's label over its
 * value (the wizards' Start | Einde). The row it sits in wears
 * [splitCapsule]; [side] Forward draws the hairline before it.
 */
@Composable
fun SplitHalf(side: SplitSide, onClick: () -> Unit, modifier: Modifier = Modifier, contentDescription: String? = null, content: @Composable () -> Unit) {
    Part(
        modifier.semantics(mergeDescendants = true) { if (contentDescription != null) this.contentDescription = contentDescription },
        true, onClick, Alignment.CenterStart, minHeight = 50.dp, divider = side == SplitSide.Forward, content = content
    )
}

/** One half of a [SplitButton]: its words, what it does, and whether it can. */
data class SplitPart(val label: String, val onClick: () -> Unit, val enabled: Boolean = true)

/**
 * `.split` with two parts of its own words: [back] left, [forward] right,
 * both in the full ink (two equal choices: Inloggen | Registreren); only a
 * forward step with no way back ([caution]) reads gold.
 */
@Composable
fun SplitButton(
    back: SplitPart,
    forward: SplitPart,
    modifier: Modifier = Modifier,
    caution: Boolean = false,
    minHeight: Dp = 44.dp,
    textSize: TextUnit = Ownify.FsSubhead
) {
    Row(modifier.fillMaxWidth().height(IntrinsicSize.Min).splitCapsule()) {
        WordsPart(back, Ownify.TextPrimary, FontWeight.SemiBold, false, Modifier.weight(1f).fillMaxHeight(), minHeight, textSize)
        WordsPart(forward, if (caution) Ownify.Attention else Ownify.TextPrimary, if (caution) FontWeight.Bold else FontWeight.SemiBold, true, Modifier.weight(1f).fillMaxHeight(), minHeight, textSize)
    }
}

/**
 * `.split--menu`: a dropdown in the split's shape — the value 85 %, the
 * chevron 15 %; a tap on either part opens the choices ([onClick]).
 */
@Composable
fun SplitMenu(
    value: String,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    contentDescription: String? = null,
    enabled: Boolean = true,
    chevron: ImageVector = OwnifyIcons.chevronDown
) {
    Row(
        modifier.fillMaxWidth().height(IntrinsicSize.Min).splitCapsule().semantics { if (contentDescription != null) this.contentDescription = contentDescription }
    ) {
        Part(Modifier.weight(0.85f).fillMaxHeight(), enabled, onClick, Alignment.CenterStart) {
            T(value, OwnifyType.style(Ownify.FsInput, color = if (enabled) Ownify.TextPrimary else Ownify.TextMuted), maxLines = 2)
        }
        Part(Modifier.weight(0.15f).fillMaxHeight(), enabled, onClick, Alignment.Center, padded = false, divider = true) {
            JIcon(chevron, size = 14.dp, color = Ownify.TextMuted)
        }
    }
}

@Composable
private fun WordsPart(part: SplitPart, ink: Color, weight: FontWeight, divider: Boolean, modifier: Modifier, minHeight: Dp, textSize: TextUnit) {
    Part(modifier, part.enabled, part.onClick, Alignment.Center, minHeight = minHeight, divider = divider) {
        ChromeText { T(part.label, OwnifyType.style(textSize, weight, if (part.enabled) ink else Ownify.TextMuted), maxLines = 2, align = TextAlign.Center) }
    }
}

/** A part of the capsule: not glass of its own; pressed, it darkens in place (`.split__part:active`). */
@Composable
private fun Part(
    modifier: Modifier,
    enabled: Boolean,
    onClick: () -> Unit,
    align: Alignment,
    minHeight: Dp = 44.dp,
    padded: Boolean = true,
    divider: Boolean = false,
    content: @Composable () -> Unit
) {
    InButton {
        val interaction = remember { MutableInteractionSource() }
        val pressed by interaction.collectIsPressedAsState()
        Box(
            modifier
                .splitDivider(divider)
                .clip(Pill)
                .background(if (pressed && enabled) Ownify.ink(0.08f) else Color.Transparent)
                .clickable(interaction, indication = null, enabled = enabled, role = Role.Button, onClick = blurring(onClick))
                .heightIn(min = minHeight)
                .then(if (padded) Modifier.padding(horizontal = Ownify.Space4, vertical = 6.dp) else Modifier),
            contentAlignment = align
        ) { content() }
    }
}
