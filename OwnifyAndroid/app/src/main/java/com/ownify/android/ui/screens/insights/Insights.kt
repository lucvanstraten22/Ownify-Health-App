package com.ownify.android.ui.screens.insights

import android.content.Context
import android.content.Intent
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.animateDpAsState
import androidx.compose.animation.core.tween
import androidx.compose.foundation.ScrollState
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.interaction.collectIsPressedAsState
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.onClick
import androidx.compose.ui.semantics.role
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.IntOffset
import androidx.compose.ui.unit.dp
import androidx.core.net.toUri
import com.ownify.android.data.CorrelationChart
import com.ownify.android.data.CorrelationDetail
import com.ownify.android.data.Correlations
import com.ownify.android.data.Insight
import com.ownify.android.i18n.tr
import com.ownify.android.ui.app.Detail
import com.ownify.android.ui.app.DetailColumn
import com.ownify.android.ui.app.LocalShell
import com.ownify.android.ui.design.GlassChip
import com.ownify.android.ui.design.JIcon
import com.ownify.android.ui.design.JStyle
import com.ownify.android.ui.design.LocalScreen
import com.ownify.android.ui.design.Meter
import com.ownify.android.ui.design.OwnifyIcons
import com.ownify.android.ui.design.PageIntro
import com.ownify.android.ui.design.T
import com.ownify.android.ui.design.chWidth
import com.ownify.android.ui.design.cssPadding
import com.ownify.android.ui.design.reveal
import com.ownify.android.ui.design.rememberPlayOnSight
import com.ownify.android.ui.screens.research.ResearchEvidence
import com.ownify.android.ui.screens.settings.SettingsCard
import com.ownify.android.ui.screens.settings.SettingsEyebrow
import com.ownify.android.ui.screens.settings.SettingsNote
import com.ownify.android.ui.screens.settings.settingsRow
import com.ownify.android.ui.theme.Accent
import com.ownify.android.ui.theme.InButton
import com.ownify.android.ui.theme.Ownify
import com.ownify.android.ui.theme.OwnifyType

/*
 * Inzichten (docs/CORRELATIONS.md, 31.0) on the phone, as the website has it:
 *
 *   InsightsPage       pages/insights.php: every combined insight, grouped by
 *                      label strongest first, each row opening its own page
 *   InsightDetailPage  pages/insight-detail.php: the label and what it means,
 *                      Chart A (the Scorekompas's rows with the Meter), the
 *                      numbers, the research at the person's age, the method
 *
 * Every word and number is the server's (`correlations` in the state); the
 * app only lays them out, with Instellingen' own pieces — no new kind of page.
 */

/** Inzichten: the page intro, then each label's group — its rows, and a note of what the label means. */
@Composable
fun InsightsPage(correlations: Correlations, scroll: ScrollState) {
    val shell = LocalShell.current
    DetailColumn(scroll, back = correlations.back, backAria = tr("Terug naar Overzicht")) {
        PageIntro(correlations.title, correlations.lede, Modifier.reveal())

        if (correlations.groups.isEmpty()) {
            SettingsNote("info", correlations.empty, Modifier.reveal())
        }

        correlations.groups.forEach { group ->
            Column(Modifier.fillMaxWidth().reveal()) {
                SettingsEyebrow(group.label)
                SettingsCard {
                    group.rows.forEachIndexed { i, row ->
                        InsightRow(row, divided = i > 0) { row.detail?.let { shell.openDetail(Detail.InsightPage(it)) } }
                    }
                }
                SettingsNote("info", group.means, Modifier.padding(top = Ownify.Space3))
            }
        }
    }
}

/** `.settings-row` with an area's mark: the insight's name, its sentence under it, a chevron. */
@Composable
private fun InsightRow(row: Insight, divided: Boolean, onClick: () -> Unit) {
    NavRow(markOf(row.accent), row.title, row.body, divided, description = row.title + " — " + row.body, onClick = onClick)
}

/** The outline mark of the area an insight is about (heart belongs to Training). */
private fun markOf(accent: String): ImageVector = when (accent) {
    "sleep" -> OwnifyIcons.moon
    "nutrition" -> OwnifyIcons.utensils
    else -> OwnifyIcons.bolt
}

/**
 * `.settings-row`, as Instellingen draws it: a 30 dp mark, the name with
 * its value under it, a chevron that moves 2 dp on a press.
 */
@Composable
private fun NavRow(icon: ImageVector, label: String, value: String?, divided: Boolean, description: String, onClick: () -> Unit) {
    InButton {
        val narrow = LocalScreen.current.narrow
        val interaction = remember { MutableInteractionSource() }
        val pressed by interaction.collectIsPressedAsState()
        val fill by animateColorAsState(if (pressed) Ownify.fill(0.05f) else Color.Transparent, tween(Ownify.FastMs, easing = Ownify.Ease), label = "row")
        val nudge by animateDpAsState(if (pressed) 2.dp else 0.dp, tween(Ownify.FastMs, easing = Ownify.Ease), label = "chevron")
        Row(
            Modifier
                .fillMaxWidth()
                .settingsRow(divided, fill, inset = if (narrow) Ownify.Space3 + 30.dp + Ownify.Space2 else Ownify.Space4 + 30.dp + Ownify.Space3)
                .clickable(interaction, indication = null, onClick = onClick)
                .clearAndSetSemantics {
                    role = Role.Button
                    contentDescription = description
                    onClick { onClick(); true }
                }
                .cssPadding(if (narrow) Ownify.Space3 else Ownify.Space4, top = Ownify.Space3, bottom = Ownify.Space3, above = if (divided) 1.dp else 0.dp),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(if (narrow) Ownify.Space2 else Ownify.Space3)
        ) {
            val shape = RoundedCornerShape(10.dp)
            Box(
                Modifier.size(30.dp).clip(shape).background(Ownify.fill(0.055f)).border(1.dp, Ownify.GlassHairline, shape),
                contentAlignment = Alignment.Center
            ) { JIcon(icon, size = 16.dp, color = Ownify.TextSecondary) }
            Column(Modifier.weight(1f)) {
                T(label, OwnifyType.style(Ownify.FsLabel, FontWeight.Medium))
                if (!value.isNullOrEmpty()) T(value, JStyle.Meta, Modifier.padding(top = 2.dp))
            }
            JIcon(OwnifyIcons.chevronRight, Modifier.offset { IntOffset(nudge.roundToPx(), 0) }, size = 15.dp, color = Ownify.TextFaint)
        }
    }
}

/**
 * One combined insight: Wat Ownify zag (the label as a status chip, what it
 * means, that it is no cause; Chart A), In cijfers, Uit onderzoek blijkt —
 * or that it is the person's own data only — and Hoe Ownify dit berekent.
 */
@Composable
fun InsightDetailPage(correlations: Correlations, detail: CorrelationDetail, scroll: ScrollState) {
    val labels = correlations.labels
    val context = LocalContext.current
    DetailColumn(scroll, back = labels.back, backAria = tr("Terug naar Inzichten")) {
        PageIntro(detail.title, detail.body, Modifier.reveal())

        Block(labels.seen) {
            StateRow(divided = false) {
                GlassChip(detail.tierLabel, chosen = false, onClick = null, description = "${labels.seen}: ${detail.tierLabel}")
                Note(detail.tierMeans)
                detail.notCause?.let { Note(it) }
            }
            detail.chart?.let { chart -> StateRow(divided = true) { ChartA(chart, Accent.of(detail.accent).color) } }
        }

        Block(labels.numbers) {
            detail.numbers.forEachIndexed { i, (label, value) ->
                StateRow(divided = i > 0) {
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Ownify.Space4)) {
                        T(label, OwnifyType.style(Ownify.FsLabel, FontWeight.Medium), Modifier.weight(1f).alignByBaseline())
                        T(value, OwnifyType.style(Ownify.FsSmall, FontWeight.Medium, Ownify.TextSecondary), Modifier.alignByBaseline(), align = TextAlign.End)
                    }
                }
            }
        }

        Column(Modifier.fillMaxWidth().reveal()) {
            SettingsEyebrow(labels.research)
            if (detail.research.isNotEmpty()) {
                SettingsCard {
                    detail.research.forEachIndexed { i, item ->
                        StateRow(divided = i > 0) {
                            Note(item.statement, top = false)
                            ResearchEvidence(item.evidence)
                        }
                    }
                }
                detail.concordance?.let { SettingsNote("info", it, Modifier.padding(top = Ownify.Space3)) }
            } else {
                SettingsNote("info", detail.ownData.orEmpty())
            }
        }

        Column(Modifier.fillMaxWidth().reveal()) {
            SettingsEyebrow(labels.method)
            SettingsCard {
                StateRow(divided = false) { detail.method.forEach { Note(it) } }
            }
            if (detail.sources.isNotEmpty()) {
                SettingsEyebrow(detail.sourcesTitle, Modifier.padding(top = Ownify.Space4))
                SettingsCard {
                    detail.sources.forEachIndexed { i, source ->
                        NavRow(OwnifyIcons.chart, source.title, source.citation, divided = i > 0, description = source.title + ". " + source.citation) {
                            view(context, source.href)
                        }
                    }
                }
            }
        }
    }
}

/**
 * Chart A (D2): the two day groups as the Scorekompas's rows — the group,
 * its days, its value — each with the Meter from zero in the page's accent,
 * filling once the card is on screen; then that it is adjusted for the
 * person's weekdays and trend.
 */
@Composable
private fun ChartA(chart: CorrelationChart, accent: Color) {
    val (play, seen) = rememberPlayOnSight()
    Column(Modifier.fillMaxWidth().then(seen)) {
        chart.rows.forEachIndexed { i, row ->
            Column(
                Modifier.fillMaxWidth().padding(top = if (i > 0) Ownify.Space3 else 0.dp).semantics(mergeDescendants = true) { },
                verticalArrangement = Arrangement.spacedBy(6.dp)
            ) {
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
                    T(row.label, OwnifyType.style(Ownify.FsSmall, color = Ownify.TextSecondary), Modifier.weight(1f).alignByBaseline())
                    T(row.count, OwnifyType.style(Ownify.FsTiny, color = Ownify.TextMuted, tabular = true), Modifier.alignByBaseline())
                    T(row.value, OwnifyType.style(Ownify.FsSmall, FontWeight.SemiBold, Ownify.TextPrimary, tabular = true), Modifier.alignByBaseline(), align = TextAlign.End)
                }
                Meter(share = row.fill, play = play, height = 4.dp, emptyHeight = 4.dp, accent = accent)
            }
        }
        Note(chart.note)
    }
}

/** A block: its eyebrow, and its card of rows (`.settings-block` > `.card.settings-card`). */
@Composable
private fun Block(title: String, rows: @Composable ColumnScope.() -> Unit) {
    Column(Modifier.fillMaxWidth().reveal()) {
        SettingsEyebrow(title)
        SettingsCard(content = rows)
    }
}

/** `.settings-state`: a row of the card — 12 above and below, 16 in (12 on the narrowest), its hairline after the first. */
@Composable
private fun StateRow(divided: Boolean, content: @Composable ColumnScope.() -> Unit) {
    val narrow = LocalScreen.current.narrow
    Column(
        Modifier
            .fillMaxWidth()
            .settingsRow(divided)
            .semantics(mergeDescendants = true) { }
            .cssPadding(if (narrow) Ownify.Space3 else Ownify.Space4, top = Ownify.Space3, bottom = Ownify.Space3, above = if (divided) 1.dp else 0.dp),
        content = content
    )
}

/** `.settings-state__note`: the footnote, 4 above, 44 characters at most. */
@Composable
private fun Note(text: String, top: Boolean = true) {
    if (text.isEmpty()) return
    T(text, JStyle.Footnote, Modifier.padding(top = if (top) Ownify.Space1 else 0.dp).widthIn(max = chWidth(JStyle.Footnote, 44f)))
}

/** The browser, or whatever opens it — as the website's links do. */
private fun view(context: Context, url: String) {
    runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, url.toUri()).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) }
}
