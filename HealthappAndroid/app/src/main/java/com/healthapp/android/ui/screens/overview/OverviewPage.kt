package com.healthapp.android.ui.screens.overview

import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.tween
import androidx.compose.foundation.ScrollState
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.asPaddingValues
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawing
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.runtime.Composable
import androidx.compose.runtime.derivedStateOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.drawBehind
import androidx.compose.ui.draw.drawWithContent
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.PathEffect
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.em
import com.healthapp.android.data.AppData
import com.healthapp.android.data.GoalCard
import com.healthapp.android.data.Insights
import com.healthapp.android.data.Patterns
import com.healthapp.android.data.Recommendation
import com.healthapp.android.ui.app.LocalShell
import com.healthapp.android.ui.app.PageColumn
import com.healthapp.android.ui.app.dockClearance
import com.healthapp.android.ui.design.Btn
import com.healthapp.android.ui.design.CardHint
import com.healthapp.android.ui.design.CardStyle
import com.healthapp.android.ui.design.Chip
import com.healthapp.android.ui.design.Disclaimer
import com.healthapp.android.ui.design.IconTile
import com.healthapp.android.ui.design.JCard
import com.healthapp.android.ui.design.JIcon
import com.healthapp.android.ui.design.JStyle
import com.healthapp.android.ui.design.JoluIcons
import com.healthapp.android.ui.design.Legend
import com.healthapp.android.ui.design.LegendItem
import com.healthapp.android.ui.design.LocalScreen
import com.healthapp.android.ui.design.LocalStillMotion
import com.healthapp.android.ui.design.Meter
import com.healthapp.android.ui.design.ScoreRing
import com.healthapp.android.ui.design.T
import com.healthapp.android.ui.design.chWidth
import com.healthapp.android.ui.design.countUpText
import com.healthapp.android.ui.design.cssPadding
import com.healthapp.android.ui.design.press
import com.healthapp.android.ui.design.rememberPlayOnSight
import com.healthapp.android.ui.design.reveal
import com.healthapp.android.ui.design.loopValue
import com.healthapp.android.ui.theme.Accent
import com.healthapp.android.ui.theme.Jolu
import com.healthapp.android.ui.theme.JoluType
import kotlinx.coroutines.launch

/**
 * Overzicht (pages/overview.php): the day's score, the personal goal, the
 * insights, the patterns and the one suggestion — and the line under them
 * when there is one to say. The scroll-to-top control appears 360 dp down.
 */
@Composable
fun OverviewPage(data: AppData, scroll: ScrollState) {
    val overview = data.overview
    Box {
        PageColumn(scroll) {
            HealthScoreCard(data)
            GoalProgressCard(overview.goal)
            InsightsCard(overview.insights)
            PatternsCard(overview.patterns)
            RecommendationCard(overview.recommendation)
            if (data.disclaimer.isNotEmpty()) Disclaimer(data.disclaimer, Modifier.reveal())
        }
        ScrollTop(scroll)
    }
}

/** `components/health-score.php`: the dominant block — the ring, its number and what it is made of. */
@Composable
private fun HealthScoreCard(data: AppData) {
    val overall = data.overview.overall
    val screen = LocalScreen.current
    val (play, sight) = rememberPlayOnSight()
    val empty = overall.value == null

    JCard(Modifier.fillMaxWidth().reveal().then(sight)) {
        // .card__head: the day and the chosen focus.
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Jolu.Space3)) {
            Column(Modifier.weight(1f)) {
                T(data.overview.title, JStyle.Section, Modifier.semantics { heading() })
                val date = if (screen.narrow) data.today.date else "${data.today.weekday} ${data.today.date}"
                T(date, JStyle.Meta, Modifier.padding(top = Jolu.Space1))
            }
            Chip(data.focusLabel, dot = true, modifier = Modifier.semantics { contentDescription = "Gekozen focus: ${data.focusLabel}" })
        }

        ScoreRing(
            value = overall.value,
            max = overall.max,
            scale = if (empty) overall.caption else "van ${overall.max}",
            label = overall.label,
            play = play,
            modifier = Modifier
                .align(Alignment.CenterHorizontally)
                .padding(top = Jolu.Space4, bottom = Jolu.Space3)
        )

        T(overall.label, JStyle.Section, Modifier.fillMaxWidth(), align = TextAlign.Center)
        T(
            overall.description,
            JStyle.Lede,
            Modifier
                .align(Alignment.CenterHorizontally)
                .padding(top = Jolu.Space2)
                .widthIn(max = chWidth(JStyle.Lede, 34f)),
            align = TextAlign.Center
        )

        Legend(
            data.overview.contributors.map { LegendItem(it.label, it.value, Accent.of(it.accent)) },
            Modifier.padding(top = Jolu.Space4)
        )

        if (empty) CardHint(overall.emptyHint, textAlign = TextAlign.Center)
    }
}

/** `components/goal-progress.php`: whichever goal is primary, or how to set one. */
@Composable
private fun GoalProgressCard(goal: GoalCard) {
    val shell = LocalShell.current
    val (play, sight) = rememberPlayOnSight()
    val set = goal.state != "unset" && goal.progress != null

    JCard(Modifier.fillMaxWidth().reveal().then(sight)) {
        CompactHead(JoluIcons.flag, goal.title, iconColor = Jolu.Health)

        // .goal__headline
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.Bottom, horizontalArrangement = Arrangement.spacedBy(Jolu.Space3)) {
            T(
                if (set) goal.name.orEmpty() else goal.headline,
                JStyle.Subtitle.copy(color = if (set) Jolu.TextPrimary else Jolu.TextSecondary),
                Modifier.weight(1f)
            )
            Row(verticalAlignment = Alignment.Bottom) {
                T(
                    countUpText(goal.progress?.toString() ?: "—", play && set),
                    JoluType.style(Jolu.FsScoreSm, FontWeight.Bold, if (set) Jolu.TextPrimary else Jolu.TextSecondary, tracking = (-0.035).em, lineHeight = 1.em, tabular = true)
                )
                if (set) T("%", JStyle.Meta, Modifier.padding(start = 2.dp))
            }
        }

        Meter(
            share = if (set) (goal.progress ?: 0) / 100f else null,
            play = play,
            accent = Jolu.Health,
            height = 10.dp,
            emptyHeight = 6.dp,
            modifier = Modifier
                .padding(top = Jolu.Space3)
                .semantics { contentDescription = if (set) "${goal.progress}% ${goal.unit}" else "Nog geen doel ingesteld" }
        )

        Milestones(goal)

        CardHint(if (set) goal.unit else goal.description, icon = null, plain = true)

        if (!set) {
            Row(
                Modifier.fillMaxWidth().padding(top = Jolu.Space4),
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.spacedBy(Jolu.Space3)
            ) {
                Btn(goal.ctaLabel, onClick = { shell.tab("goals") }, enabled = goal.ctaEnabled)
                T(goal.ctaNote, JStyle.Tiny, Modifier.weight(1f))
            }
        }
    }
}

/** `.milestones`: four stops along the bar; the first to the left, the last to the right. */
@Composable
private fun Milestones(goal: GoalCard) {
    Row(Modifier.fillMaxWidth().padding(top = Jolu.Space3)) {
        goal.milestones.forEachIndexed { i, milestone ->
            val align = when (i) {
                0 -> Alignment.Start
                goal.milestones.lastIndex -> Alignment.End
                else -> Alignment.CenterHorizontally
            }
            Column(Modifier.weight(1f), horizontalAlignment = align, verticalArrangement = Arrangement.spacedBy(Jolu.Space2)) {
                Box(
                    Modifier
                        .size(7.dp)
                        .drawBehind {
                            if (milestone.reached) {
                                drawCircle(Jolu.Health.copy(alpha = 0.18f), radius = size.width / 2f + 3.dp.toPx())
                                drawCircle(Jolu.Health)
                            } else {
                                drawCircle(Jolu.white(0.18f))
                            }
                        }
                )
                T(milestone.label, JStyle.Tiny.copy(color = if (milestone.reached) Jolu.TextSecondary else Jolu.TextMuted), maxLines = 1)
            }
        }
    }
}

/** `components/insights.php`. */
@Composable
private fun InsightsCard(insights: Insights) {
    JCard(Modifier.fillMaxWidth().reveal()) {
        CompactHead(JoluIcons.pulse, insights.title, meta = insights.subtitle)
        Column(verticalArrangement = Arrangement.spacedBy(Jolu.Space3)) {
            for (item in insights.items) {
                val accent = Accent.of(item.accent).color
                val empty = item.state == "empty"
                val shape = RoundedCornerShape(Jolu.RadiusMd)
                Row(
                    Modifier
                        .fillMaxWidth()
                        .clip(shape)
                        .background(Jolu.white(0.035f))
                        .border(1.dp, Jolu.GlassHairline, shape)
                        .cssPadding(PaddingValues(Jolu.Space3), border = 1.dp),
                    horizontalArrangement = Arrangement.spacedBy(Jolu.Space3)
                ) {
                    Box(
                        Modifier.size(30.dp).clip(RoundedCornerShape(10.dp)).background(accent.copy(alpha = 0.16f)),
                        contentAlignment = Alignment.Center
                    ) {
                        JoluIcons.named(item.icon)?.let { JIcon(it, size = 16.dp, color = accent) }
                    }
                    Column(Modifier.weight(1f)) {
                        T(item.title, JoluType.style(Jolu.FsLabel, FontWeight.SemiBold, if (empty) Jolu.TextSecondary else Jolu.TextPrimary))
                        T(item.body, JStyle.Meta, Modifier.padding(top = Jolu.Space1))
                    }
                }
            }
        }
    }
}

/** `components/patterns.php`: seven flat bars shimmering until there are measurements. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun PatternsCard(patterns: Patterns) {
    JCard(Modifier.fillMaxWidth().reveal(), style = CardStyle.Quiet) {
        CompactHead(JoluIcons.chart, patterns.title, meta = patterns.range)

        // .trend — equal heights on purpose: a shaped skeleton would read as data.
        Row(
            Modifier
                .fillMaxWidth()
                .height(64.dp)
                .graphicsLayer { alpha = 0.6f }
                .drawBehind {
                    val y = size.height - 0.5.dp.toPx()
                    drawLine(
                        Jolu.GlassBorderSoft, Offset(0f, y), Offset(size.width, y), 1.dp.toPx(),
                        pathEffect = PathEffect.dashPathEffect(floatArrayOf(3.dp.toPx(), 3.dp.toPx()))
                    )
                }
                .padding(bottom = Jolu.Space2)
                .clearAndSetSemantics { },
            horizontalArrangement = Arrangement.spacedBy(Jolu.Space2),
            verticalAlignment = Alignment.Bottom
        ) {
            repeat(7) {
                Skeleton(Modifier.weight(1f).fillMaxHeight(0.46f), RoundedCornerShape(6.dp))
            }
        }
        Spacer(Modifier.height(Jolu.Space4))

        T(patterns.headline, JStyle.Subtitle, Modifier.padding(bottom = Jolu.Space1))
        T(patterns.description, JStyle.Meta)

        FlowRow(
            Modifier.fillMaxWidth().padding(top = Jolu.Space4),
            horizontalArrangement = Arrangement.spacedBy(Jolu.Space2),
            verticalArrangement = Arrangement.spacedBy(Jolu.Space2)
        ) {
            patterns.topics.forEach { Chip(it, muted = true) }
        }
    }
}

/** `components/recommendation.php`: at most one, and never an instruction. */
@Composable
private fun RecommendationCard(rec: Recommendation) {
    JCard(Modifier.fillMaxWidth().reveal(), style = CardStyle.Quiet) {
        CompactHead(JoluIcons.sparkle, rec.title, iconColor = Jolu.Nutrition)
        T(rec.headline, JStyle.Subtitle, Modifier.padding(bottom = Jolu.Space1))
        T(rec.description, JStyle.Meta)
        CardHint(rec.note, icon = null, plain = true)
    }
}

/**
 * `.card__head--compact`: an icon tile, the card's eyebrow and — when
 * there is one — a small line under it, 16 above the body.
 */
@Composable
fun CompactHead(icon: androidx.compose.ui.graphics.vector.ImageVector, title: String, meta: String? = null, iconColor: Color = Jolu.TextSecondary) {
    Row(
        Modifier.fillMaxWidth().padding(bottom = Jolu.Space4),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Jolu.Space3)
    ) {
        IconTile(icon, color = iconColor)
        Column(Modifier.weight(1f)) {
            T(title, JStyle.Eyebrow, Modifier.semantics { heading() })
            if (meta != null) T(meta, JStyle.Tiny, Modifier.padding(top = Jolu.Space1))
        }
    }
}

/** `.skeleton`: a quiet placeholder with a light sweeping across it every 2.4 s. */
@Composable
fun Skeleton(modifier: Modifier, shape: androidx.compose.ui.graphics.Shape = RoundedCornerShape(50)) {
    val still = LocalStillMotion.current
    val sweep = loopValue(-1f, 1f, infiniteRepeatable(tween(2_400, easing = Jolu.EaseOut)))
    Box(
        modifier
            .clip(shape)
            .background(Jolu.white(0.07f))
            .drawWithContent {
                drawContent()
                if (still) return@drawWithContent
                val x = sweep * size.width
                drawRect(
                    Brush.horizontalGradient(
                        listOf(Color.White.copy(alpha = 0f), Color.White.copy(alpha = 0.11f), Color.White.copy(alpha = 0f)),
                        startX = x, endX = x + size.width
                    )
                )
            }
    )
}

/**
 * `.fab` on Overzicht: "Terug naar je dagscore", on the column's right edge
 * above the tab bar, once the page is more than 360 dp down.
 */
@Composable
private fun ScrollTop(scroll: ScrollState) {
    val screen = LocalScreen.current
    val scope = rememberCoroutineScope()
    val density = androidx.compose.ui.platform.LocalDensity.current
    val threshold = with(density) { 360.dp.toPx() }
    val show by remember(scroll, threshold) { derivedStateOf { scroll.value > threshold } }
    val shown by animateFloatAsState(if (show) 1f else 0f, tween(Jolu.FastMs, easing = Jolu.Ease), label = "fab")
    val bottom = WindowInsets.safeDrawing.asPaddingValues().calculateBottomPadding()
    if (shown <= 0f) return

    val interaction = remember { MutableInteractionSource() }
    Box(Modifier.fillMaxWidth().fillMaxHeight(), contentAlignment = Alignment.BottomCenter) {
        Box(
            Modifier
                .padding(bottom = dockClearance(bottom) + Jolu.Space4)
                .offset(x = screen.shell / 2 - 50.dp + 25.dp)
                .graphicsLayer {
                    alpha = shown
                    translationY = (1f - shown) * 8.dp.toPx()
                }
                .press(interaction, scale = 0.94f)
                .size(50.dp)
                .drawBehind {
                    // --shadow-float
                    drawCircle(
                        Brush.radialGradient(
                            0f to Color.Black.copy(alpha = 0.3f), 1f to Color.Black.copy(alpha = 0f),
                            center = center + Offset(0f, 12.dp.toPx()), radius = size.width / 2f + 28.dp.toPx()
                        ),
                        radius = size.width / 2f + 28.dp.toPx(), center = center + Offset(0f, 12.dp.toPx())
                    )
                }
                .clip(CircleShape)
                .background(Color(48, 45, 47).copy(alpha = 0.72f))
                .border(1.dp, Jolu.GlassBorder, CircleShape)
                .clickable(interaction, indication = null, role = Role.Button, enabled = show) {
                    scope.launch { scroll.animateScrollTo(0) }
                }
                .semantics { contentDescription = "Terug naar je dagscore" },
            contentAlignment = Alignment.Center
        ) {
            JIcon(JoluIcons.chevron, color = Jolu.TextSecondary)
        }
    }
}
