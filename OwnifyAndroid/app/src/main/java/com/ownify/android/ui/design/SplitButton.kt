package com.ownify.android.ui.design

import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.IntrinsicSize
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.shape.CornerSize
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.compositionLocalOf
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
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

/** Which half of a split a [Btn] is drawn as, inside a [SplitRow]. */
enum class SplitSide { Back, Forward }

/** Set by [SplitRow]: a [Btn] under it draws itself as that half of the split. */
val LocalSplitSide = compositionLocalOf<SplitSide?> { null }

/**
 * The split for two existing [Btn]s: [back] on the left, [forward] on the
 * right, each given its half's modifier. A [Btn] with [BtnLook.Caution]
 * on the forward side reads gold; every other look reads as the split's ink.
 */
@Composable
fun SplitRow(back: @Composable (Modifier) -> Unit, forward: @Composable (Modifier) -> Unit, modifier: Modifier = Modifier) {
    Row(modifier.fillMaxWidth().height(IntrinsicSize.Min), horizontalArrangement = Arrangement.spacedBy(2.dp)) {
        CompositionLocalProvider(LocalSplitSide provides SplitSide.Back) { back(Modifier.weight(1f).fillMaxHeight()) }
        CompositionLocalProvider(LocalSplitSide provides SplitSide.Forward) { forward(Modifier.weight(1f).fillMaxHeight()) }
    }
}

/** [Btn] inside a [SplitRow]: the half for [side], its words in the split's ink. */
@Composable
internal fun SplitBtn(side: SplitSide, label: String, onClick: () -> Unit, modifier: Modifier, enabled: Boolean, icon: ImageVector?, caution: Boolean, contentDescription: String?) {
    val ink = if (side == SplitSide.Forward && caution) Ownify.Attention else Ownify.TextPrimary
    Half(if (side == SplitSide.Back) LeftShape else RightShape, modifier.semantics { if (contentDescription != null) this.contentDescription = contentDescription }, enabled, onClick, Alignment.Center) {
        CompositionLocalProvider(LocalSplitSide provides null) {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
                if (icon != null) JIcon(icon, size = 16.dp, color = ink)
                ChromeText { T(label, OwnifyType.style(Ownify.FsSubhead, FontWeight.SemiBold, ink), maxLines = 2, align = TextAlign.Center) }
            }
        }
    }
}

/**
 * One half of a split with content of its own — a date's label over its
 * value (the wizards' Start | Einde) — drawn and pressed as the split's halves.
 */
@Composable
fun SplitHalf(side: SplitSide, onClick: () -> Unit, modifier: Modifier = Modifier, contentDescription: String? = null, content: @Composable () -> Unit) {
    Half(
        if (side == SplitSide.Back) LeftShape else RightShape,
        modifier.semantics(mergeDescendants = true) { if (contentDescription != null) this.contentDescription = contentDescription },
        true, onClick, Alignment.CenterStart, minHeight = 50.dp, content = content
    )
}

/** One half of a [SplitButton]: its words, what it does, and whether it can. */
data class SplitPart(val label: String, val onClick: () -> Unit, val enabled: Boolean = true)

/** The split's two corner sets: the outer side round, the seam side at the small radius. */
private val LeftShape = RoundedCornerShape(CornerSize(50), CornerSize(Ownify.RadiusSm), CornerSize(Ownify.RadiusSm), CornerSize(50))
private val RightShape = RoundedCornerShape(CornerSize(Ownify.RadiusSm), CornerSize(50), CornerSize(50), CornerSize(Ownify.RadiusSm))

/**
 * `.split` (components.css, 50.0, the owner's pick "Quiet + Joined"): two
 * actions side by side as one block across the width — two halves of the
 * neutral Liquid Glass meeting at a 2 dp seam, the inner corners at the small
 * radius. Both halves read in the one full ink, the way back ([back]) left
 * and the way forward ([forward]) right; only a forward step with no way
 * back ([caution]) reads gold. Each half presses on its own.
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
    Row(modifier.fillMaxWidth().height(IntrinsicSize.Min), horizontalArrangement = Arrangement.spacedBy(2.dp)) {
        SplitHalf(back, Ownify.TextPrimary, LeftShape, Modifier.weight(1f).fillMaxHeight(), minHeight, textSize)
        SplitHalf(forward, if (caution) Ownify.Attention else Ownify.TextPrimary, RightShape, Modifier.weight(1f).fillMaxHeight(), minHeight, textSize)
    }
}

/**
 * `.split--menu`: a dropdown in the split's shape — the value 85 %, the
 * chevron 15 %; a tap on either half opens the choices ([onClick]).
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
        modifier.fillMaxWidth().height(IntrinsicSize.Min).semantics { if (contentDescription != null) this.contentDescription = contentDescription },
        horizontalArrangement = Arrangement.spacedBy(2.dp)
    ) {
        Half(LeftShape, Modifier.weight(0.85f).fillMaxHeight(), enabled, onClick, Alignment.CenterStart) {
            T(value, OwnifyType.style(Ownify.FsInput, color = Ownify.TextPrimary), maxLines = 2)
        }
        Half(RightShape, Modifier.weight(0.15f).fillMaxHeight(), enabled, onClick, Alignment.Center, padded = false) {
            JIcon(chevron, size = 14.dp, color = Ownify.TextMuted)
        }
    }
}

@Composable
private fun SplitHalf(part: SplitPart, ink: Color, shape: RoundedCornerShape, modifier: Modifier, minHeight: Dp, textSize: TextUnit) {
    Half(shape, modifier, part.enabled, part.onClick, Alignment.Center, minHeight = minHeight) {
        ChromeText { T(part.label, OwnifyType.style(textSize, FontWeight.SemiBold, ink), maxLines = 2, align = TextAlign.Center) }
    }
}

@Composable
private fun Half(
    shape: RoundedCornerShape,
    modifier: Modifier,
    enabled: Boolean,
    onClick: () -> Unit,
    align: Alignment,
    minHeight: Dp = 44.dp,
    padded: Boolean = true,
    content: @Composable () -> Unit
) {
    InButton {
        val interaction = remember { MutableInteractionSource() }
        Box(
            modifier
                .clickable(interaction, indication = null, enabled = enabled, role = Role.Button, onClick = blurring(onClick))
                .press(interaction, enabled = enabled)
                .alpha(if (enabled) 1f else 0.45f)
                .heightIn(min = minHeight)
                .glass(shape)
                .then(if (padded) Modifier.padding(horizontal = Ownify.Space4, vertical = 6.dp) else Modifier),
            contentAlignment = align
        ) { content() }
    }
}
