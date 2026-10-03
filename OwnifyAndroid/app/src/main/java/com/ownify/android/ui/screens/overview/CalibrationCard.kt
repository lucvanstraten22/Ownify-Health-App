package com.ownify.android.ui.screens.overview

import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.em
import com.ownify.android.data.AppData
import com.ownify.android.data.Calibration
import com.ownify.android.data.CalibrationProgress
import com.ownify.android.data.CalibrationStart
import com.ownify.android.ui.app.Detail
import com.ownify.android.ui.app.LocalShell
import com.ownify.android.ui.design.CardHint
import com.ownify.android.ui.design.IconTile
import com.ownify.android.ui.design.JCard
import com.ownify.android.ui.design.JIcon
import com.ownify.android.ui.design.JStyle
import com.ownify.android.ui.design.OwnifyIcons
import com.ownify.android.ui.design.T
import com.ownify.android.ui.design.blurring
import com.ownify.android.ui.design.press
import com.ownify.android.ui.design.rememberPlayOnSight
import com.ownify.android.ui.design.reveal
import com.ownify.android.ui.theme.Accent
import com.ownify.android.ui.theme.InButton
import com.ownify.android.ui.theme.Ownify
import com.ownify.android.ui.theme.OwnifyType

/**
 * The first days, at the top of Overzicht (components/calibration.php):
 * while Ownify builds the baseline, the first score when it is there, and
 * the starting point after it. Gone after day 5, and never there for an
 * account from before the setup. Every word and number is the server's
 * (`calibration`, includes/setup.php) — the engine's own scores, the
 * Scorekompas's own rows.
 */
@Composable
fun CalibrationCard(data: AppData) {
    val cal = data.calibration ?: return
    val shell = LocalShell.current
    val (play, sight) = rememberPlayOnSight()

    JCard(Modifier.fillMaxWidth().reveal().then(sight)) {
        T(cal.eyebrow, JStyle.Caption, uppercase = true)
        T(cal.title, JStyle.Subtitle, Modifier.padding(top = Ownify.Space1).semantics { heading() })
        cal.lede?.let { T(it, JStyle.Lede, Modifier.padding(top = Ownify.Space2)) }

        if (cal.progress.isNotEmpty()) {
            Column(Modifier.fillMaxWidth().padding(top = Ownify.Space5), verticalArrangement = Arrangement.spacedBy(Ownify.Space4)) {
                cal.progress.forEach { ProgressRow(it) }
            }
        }

        cal.first?.let {
            Box(Modifier.fillMaxWidth().padding(top = Ownify.Space4)) { CategoryBox(it, play) }
        }

        if (cal.baseline.isNotEmpty()) {
            Column(Modifier.fillMaxWidth().padding(top = Ownify.Space5), verticalArrangement = Arrangement.spacedBy(Ownify.Space4)) {
                cal.baseline.forEach { StartRow(it) }
            }
        }

        cal.observation?.let { CardHint(it, icon = OwnifyIcons.sparkle, plain = true) }

        cal.note?.let { T(it, JStyle.Lede, Modifier.padding(top = Ownify.Space4)) }

        if (cal.open != null && data.compass.available) OpenCompass(cal) { shell.openDetail(Detail.ScoreCompass) }
    }
}

/** The category's tile, solid in its own colour: which category, never how good. */
@Composable
private fun Tile(icon: String, accent: String) {
    val tint = Accent.of(accent).color
    OwnifyIcons.solid(icon)?.let {
        IconTile(it, size = 32.dp, radius = 11.dp, iconSize = 17.dp, color = Color.White, background = tint, border = tint)
    }
}

/** `.calibration-row`: "2 van 3 nachten", one step per day the first score needs, and what the days hold. */
@Composable
private fun ProgressRow(row: CalibrationProgress) {
    val accent = Accent.of(row.accent).color
    Row(
        Modifier.fillMaxWidth().semantics(mergeDescendants = true) { },
        horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)
    ) {
        Tile(row.icon, row.accent)
        Column(Modifier.weight(1f)) {
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
                T(row.label, OwnifyType.style(Ownify.FsLabel, FontWeight.SemiBold, tracking = (-0.01).em), Modifier.weight(1f))
                T(row.count, OwnifyType.style(Ownify.FsTiny, FontWeight.SemiBold, Ownify.TextSecondary, tabular = true), maxLines = 1)
            }
            Row(Modifier.fillMaxWidth().padding(top = Ownify.Space2).clearAndSetSemantics { }, horizontalArrangement = Arrangement.spacedBy(Ownify.Space1)) {
                for (n in 1..row.needed.coerceAtLeast(1)) {
                    val lit by animateColorAsState(
                        if (n <= row.days) accent else Ownify.fill(0.10f),
                        tween(Ownify.SlowMs, easing = Ownify.EaseOut),
                        label = "step"
                    )
                    Box(Modifier.weight(1f).height(4.dp).clip(RoundedCornerShape(50)).background(lit))
                }
            }
            when {
                row.detail != null -> T(row.detail, OwnifyType.style(Ownify.FsTiny, color = Ownify.TextSecondary), Modifier.padding(top = Ownify.Space2))
                row.how != null -> T(row.how, JStyle.Tiny, Modifier.padding(top = Ownify.Space2))
            }
        }
    }
}

/** `.calibration-start`: a category in the starting point — its score with its band's dot, and one fact. */
@Composable
private fun StartRow(row: CalibrationStart) {
    Row(
        Modifier.fillMaxWidth().semantics(mergeDescendants = true) { },
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)
    ) {
        Tile(row.icon, row.accent)
        Column(Modifier.weight(1f)) {
            T(row.label, OwnifyType.style(Ownify.FsLabel, FontWeight.SemiBold, tracking = (-0.01).em))
            row.fact?.let { T(it, JStyle.Tiny, Modifier.padding(top = 2.dp)) }
        }
        ScoreValue(row.value, row.band)
    }
}

/** `.calibration__open`: the way into the Scorekompas, where the score is explained in full. */
@Composable
private fun OpenCompass(cal: Calibration, onOpen: () -> Unit) {
    InButton {
        val interaction = remember { MutableInteractionSource() }
        Row(
            Modifier
                .padding(top = Ownify.Space2)
                .heightIn(min = 44.dp)
                .press(interaction)
                .clickable(interaction, indication = null, role = Role.Button, onClick = blurring(onOpen))
                .semantics { contentDescription = cal.open.orEmpty() },
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(2.dp)
        ) {
            T(cal.open.orEmpty(), OwnifyType.style(Ownify.FsSmall, FontWeight.SemiBold))
            JIcon(OwnifyIcons.chevronRight, size = 15.dp, color = Ownify.TextMuted)
        }
    }
}
