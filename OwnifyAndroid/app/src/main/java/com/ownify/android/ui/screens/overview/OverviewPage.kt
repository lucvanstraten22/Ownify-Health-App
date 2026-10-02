package com.ownify.android.ui.screens.overview

import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.animateDpAsState
import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.foundation.hoverable
import androidx.compose.foundation.interaction.collectIsHoveredAsState
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.layout.Layout
import androidx.compose.ui.unit.Constraints
import kotlinx.coroutines.delay
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.tween
import androidx.compose.foundation.ScrollState
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.interaction.PressInteraction
import androidx.compose.foundation.interaction.collectIsPressedAsState
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
import androidx.compose.ui.semantics.onClick
import androidx.compose.ui.semantics.role
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.testTag
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.IntOffset
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.em
import com.ownify.android.data.AppData
import com.ownify.android.data.Contributor
import com.ownify.android.data.GoalCard
import com.ownify.android.data.Insights
import com.ownify.android.data.Patterns
import com.ownify.android.data.Recommendation
import com.ownify.android.ui.app.Detail
import com.ownify.android.ui.app.LocalShell
import com.ownify.android.ui.app.PageColumn
import com.ownify.android.ui.app.dockClearance
import com.ownify.android.ui.design.Btn
import com.ownify.android.ui.design.CardHint
import com.ownify.android.ui.design.CardStyle
import com.ownify.android.ui.design.Chip
import com.ownify.android.ui.design.Disclaimer
import com.ownify.android.ui.design.IconTile
import com.ownify.android.ui.design.JCard
import com.ownify.android.ui.design.JIcon
import com.ownify.android.ui.design.JStyle
import com.ownify.android.ui.design.OwnifyIcons
import com.ownify.android.ui.design.Legend
import com.ownify.android.ui.design.LegendItem
import com.ownify.android.ui.design.LocalScreen
import com.ownify.android.ui.design.LocalStillMotion
import com.ownify.android.ui.design.Meter
import com.ownify.android.ui.design.ScoreRing
import com.ownify.android.ui.design.T
import com.ownify.android.ui.design.chWidth
import com.ownify.android.ui.design.countUpText
import com.ownify.android.ui.design.cssPadding
import com.ownify.android.ui.design.press
import com.ownify.android.ui.design.rememberPlayOnSight
import com.ownify.android.ui.design.reveal
import com.ownify.android.ui.design.loopValue
import com.ownify.android.ui.theme.Accent
import com.ownify.android.ui.theme.Ownify
import com.ownify.android.ui.theme.ScoreBand
import com.ownify.android.ui.theme.OwnifyType
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

/**
 * `components/health-score.php`: the dominant block — the ring, its number
 * and what it is made of. The whole card opens the Scorekompas
 * ([ScoreCompassDetail]), and says where the score is heading.
 */
@Composable
private fun HealthScoreCard(data: AppData) {
    val overall = data.overview.overall
    val compass = data.compass
    val screen = LocalScreen.current
    val shell = LocalShell.current
    val (play, sight) = rememberPlayOnSight()
    val empty = overall.value == null
    val interaction = remember { MutableInteractionSource() }
    val pressed by interaction.collectIsPressedAsState()
    val nudge by animateDpAsState(if (pressed) 1.dp else 0.dp, tween(Ownify.FastMs, easing = Ownify.Ease), label = "chevron")

    val open = { shell.openDetail(Detail.ScoreCompass) }

    // `.card--opens`: a tap anywhere on the card opens it, and the card presses
    // in — as the label's button covers the card on the website. Only the
    // label is the button for TalkBack, so the rest of the card reads as before.
    val opens = if (!compass.available) Modifier else Modifier
        .press(interaction)
        .pointerInput(Unit) {
            detectTapGestures(
                onPress = { at ->
                    val press = PressInteraction.Press(at)
                    interaction.emit(press)
                    interaction.emit(if (tryAwaitRelease()) PressInteraction.Release(press) else PressInteraction.Cancel(press))
                },
                onTap = { open() }
            )
        }

    JCard(Modifier.fillMaxWidth().reveal().then(sight).then(opens)) {
        // .card__head: the day and the chosen focus.
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)) {
            Column(Modifier.weight(1f)) {
                T(data.overview.title, JStyle.Section, Modifier.semantics { heading() })
                val date = if (screen.narrow) data.today.date else "${data.today.weekday} ${data.today.date}"
                T(date, JStyle.Meta, Modifier.padding(top = Ownify.Space1))
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
                .padding(top = Ownify.Space4, bottom = Ownify.Space3)
        )

        // The label, with the way in beside it (`.card__open-chevron`): the button,
        // still reading as its word, described as the website's button is.
        val label = if (!compass.available) Modifier else Modifier.semantics(mergeDescendants = true) {
            role = Role.Button
            contentDescription = overall.label + ": " +
                (if (empty) overall.caption.lowercase() else "${overall.value} van ${overall.max}") +
                (compass.direction?.let { ", " + it.label.lowercase() } ?: "")
            onClick(label = compass.open) { open(); true }
        }
        Row(Modifier.fillMaxWidth().then(label), horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically) {
            T(overall.label, JStyle.Section)
            if (compass.available) {
                JIcon(OwnifyIcons.chevronRight, Modifier.padding(start = 1.dp).offset { IntOffset(nudge.roundToPx(), 0) }, size = 15.dp, color = Ownify.TextMuted)
            }
        }
        compass.direction?.let { DirectionChip(it, Modifier.align(Alignment.CenterHorizontally).padding(top = Ownify.Space2)) }
        T(
            overall.description,
            JStyle.Lede,
            Modifier
                .align(Alignment.CenterHorizontally)
                .padding(top = Ownify.Space2)
                .widthIn(max = chWidth(JStyle.Lede, 34f)),
            align = TextAlign.Center
        )

        Legend(overviewLegend(data.overview.contributors), Modifier.padding(top = Ownify.Space4))

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
        CompactHead(OwnifyIcons.solidFlag, goal.title)

        // .goal__headline
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.Bottom, horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)) {
            T(
                if (set) goal.name.orEmpty() else goal.headline,
                JStyle.Subtitle.copy(color = if (set) Ownify.TextPrimary else Ownify.TextSecondary),
                Modifier.weight(1f)
            )
            Row(verticalAlignment = Alignment.Bottom) {
                T(
                    countUpText(goal.progress?.toString() ?: "—", play && set),
                    OwnifyType.style(Ownify.FsScoreSm, FontWeight.Bold, if (set) Ownify.TextPrimary else Ownify.TextSecondary, tracking = (-0.035).em, lineHeight = 1.em, tabular = true)
                )
                if (set) T("%", JStyle.Meta, Modifier.padding(start = 2.dp))
            }
        }

        GoalTrack(goal, set, play)

        // The reading lives on the bar now; only the "no goal yet" card keeps its line.
        if (!set) CardHint(goal.description, icon = null, plain = true)

        if (!set) {
            Row(
                Modifier.fillMaxWidth().padding(top = Ownify.Space4),
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)
            ) {
                Btn(goal.ctaLabel, onClick = { shell.tab("goals") }, enabled = goal.ctaEnabled)
                T(goal.ctaNote, JStyle.Tiny, Modifier.weight(1f))
            }
        }
    }
}

/**
 * `.goal__track`: the bar, its stops and — set — its reading. A finger held
 * on it for a moment (or a mouse over it) shows the reading above the fill's
 * end, as the website does on hover and on a held touch; a tap or a swipe
 * shows nothing, and letting go hides it.
 */
@Composable
private fun GoalTrack(goal: GoalCard, set: Boolean, play: Boolean) {
    val scope = rememberCoroutineScope()
    val hover = remember { MutableInteractionSource() }
    val hovered by hover.collectIsHoveredAsState()
    var held by remember { mutableStateOf(false) }
    val share = ((goal.progress ?: 0) / 100f).coerceIn(0f, 1f)
    val reading = goal.reading ?: "${goal.progress}% van je doel"
    val shown = set && (held || hovered)
    val alpha by animateFloatAsState(if (shown) 1f else 0f, tween(Ownify.FastMs, easing = Ownify.Ease), label = "reading")
    val lift by animateDpAsState(if (shown) 4.dp else (-2).dp, tween(Ownify.FastMs, easing = Ownify.Ease), label = "reading-lift")
    val barTop = Ownify.Space3

    Layout(
        content = {
            Column(Modifier.fillMaxWidth()) {
                Meter(
                    share = if (set) share else null,
                    play = play,
                    accent = Ownify.Health,
                    height = 10.dp,
                    emptyHeight = 6.dp,
                    modifier = Modifier
                        .padding(top = barTop)
                        .semantics { contentDescription = if (set) "${goal.progress}% — $reading" else "Nog geen doel ingesteld" }
                )
                Milestones(goal)
            }
            // Only while it shows (or fades): at rest nothing is drawn, and TalkBack
            // already hears the reading in the bar's own description.
            if (set && (shown || alpha > 0f)) ReadingBubble(reading, Modifier.graphicsLayer { this.alpha = alpha })
        },
        modifier = if (!set) Modifier.fillMaxWidth() else Modifier
            .fillMaxWidth()
            .hoverable(hover)
            .pointerInput(Unit) {
                detectTapGestures(onPress = {
                    // A moment's rest before it shows: a tap or a swipe's start never does.
                    val wait = scope.launch {
                        delay(HOLD_MS)
                        held = true
                    }
                    tryAwaitRelease()
                    wait.cancel()
                    held = false
                })
            }
    ) { measurables, constraints ->
        val track = measurables[0].measure(constraints)
        val bubble = measurables.getOrNull(1)?.measure(Constraints(maxWidth = constraints.maxWidth))
        layout(track.width, track.height) {
            track.placeRelative(0, 0)
            bubble?.let {
                // The fill's end, at the same share of the bubble's width: over the bar at 0 and at 100 alike.
                val x = (share * track.width - share * it.width).toInt()
                val y = (barTop.toPx() - it.height - lift.toPx()).toInt()
                it.placeRelative(x, y, zIndex = 3f)
            }
        }
    }
}

private const val HOLD_MS = 280L

/** The reading bubble, for tests. */
const val GoalReadingTag = "goal-reading"

/** `.goal__tip`: the reading, in a small near-solid pill (`--tip-surface`). */
@Composable
private fun ReadingBubble(text: String, modifier: Modifier) {
    val shape = RoundedCornerShape(50)
    Box(
        modifier
            .clearAndSetSemantics { testTag = GoalReadingTag }
            .shadow(14.dp, shape, ambientColor = Ownify.shade(0.35f), spotColor = Ownify.shade(0.35f))
            .clip(shape)
            .background(Ownify.TipSurface)
            .border(1.dp, Ownify.GlassBorder, shape)
            .padding(horizontal = 10.dp, vertical = 5.dp)
    ) {
        T(text, OwnifyType.style(Ownify.FsSmall, FontWeight.SemiBold, Ownify.TextPrimary, tabular = true), maxLines = 1)
    }
}

/**
 * `.milestones`: each stop at its own place on the bar (`at`: 0, 50, 85,
 * 100) — the first from the left edge, the last to the right edge, the ones
 * between centred on their point. On the narrowest phones Bijna's word ends
 * at its dot instead, so it clears Doel; the dot stays where it is.
 */
@Composable
private fun Milestones(goal: GoalCard) {
    val narrow = LocalScreen.current.narrow
    val stops = goal.milestones
    Layout(
        content = {
            stops.forEachIndexed { i, milestone ->
                val align = when {
                    i == 0 -> Alignment.Start
                    i == stops.lastIndex -> Alignment.End
                    narrow && i == stops.lastIndex - 1 -> Alignment.End
                    else -> Alignment.CenterHorizontally
                }
                Column(horizontalAlignment = align, verticalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
                    Box(
                        Modifier
                            .size(DOT)
                            .drawBehind {
                                if (milestone.reached) {
                                    drawCircle(Ownify.Health.copy(alpha = 0.18f), radius = size.width / 2f + 3.dp.toPx())
                                    drawCircle(Ownify.Health)
                                } else {
                                    drawCircle(Ownify.ink(0.18f))
                                }
                            }
                    )
                    T(milestone.label, JStyle.Tiny.copy(color = if (milestone.reached) Ownify.TextSecondary else Ownify.TextMuted), maxLines = 1)
                }
            }
        },
        modifier = Modifier.fillMaxWidth().padding(top = Ownify.Space3)
    ) { measurables, constraints ->
        val items = measurables.map { it.measure(Constraints(maxWidth = constraints.maxWidth)) }
        val width = constraints.maxWidth
        val half = DOT.toPx() / 2f
        layout(width, items.maxOfOrNull { it.height } ?: 0) {
            items.forEachIndexed { i, item ->
                val point = stops[i].at.coerceIn(0, 100) / 100f * width
                val x = when {
                    i == 0 -> 0f
                    i == items.lastIndex -> (width - item.width).toFloat()
                    narrow && i == items.lastIndex - 1 -> point + half - item.width
                    else -> point - item.width / 2f
                }
                item.placeRelative(x.toInt(), 0)
            }
        }
    }
}

private val DOT = 7.dp

/** `components/insights.php`. */
@Composable
private fun InsightsCard(insights: Insights) {
    JCard(Modifier.fillMaxWidth().reveal()) {
        CompactHead(OwnifyIcons.solidPulse, insights.title, meta = insights.subtitle)
        Column(verticalArrangement = Arrangement.spacedBy(Ownify.Space3)) {
            for (item in insights.items) {
                val accent = Accent.of(item.accent).color
                val empty = item.state == "empty"
                val shape = RoundedCornerShape(Ownify.RadiusMd)
                Row(
                    Modifier
                        .fillMaxWidth()
                        .clip(shape)
                        .background(Ownify.fill(0.035f))
                        .border(1.dp, Ownify.GlassHairline, shape)
                        .cssPadding(PaddingValues(Ownify.Space3), border = 1.dp),
                    horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)
                ) {
                    Box(
                        Modifier.size(30.dp).clip(RoundedCornerShape(10.dp)).background(accent),
                        contentAlignment = Alignment.Center
                    ) {
                        OwnifyIcons.solid(item.icon)?.let { JIcon(it, size = 16.dp, color = Color.White) }
                    }
                    Column(Modifier.weight(1f)) {
                        T(item.title, OwnifyType.style(Ownify.FsLabel, FontWeight.SemiBold, if (empty) Ownify.TextSecondary else Ownify.TextPrimary))
                        T(item.body, JStyle.Meta, Modifier.padding(top = Ownify.Space1))
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
        CompactHead(OwnifyIcons.solidChart, patterns.title, meta = patterns.range)

        // .trend — equal heights on purpose: a shaped skeleton would read as data.
        Row(
            Modifier
                .fillMaxWidth()
                .height(64.dp)
                .graphicsLayer { alpha = 0.6f }
                .drawBehind {
                    val y = size.height - 0.5.dp.toPx()
                    drawLine(
                        Ownify.GlassBorderSoft, Offset(0f, y), Offset(size.width, y), 1.dp.toPx(),
                        pathEffect = PathEffect.dashPathEffect(floatArrayOf(3.dp.toPx(), 3.dp.toPx()))
                    )
                }
                .padding(bottom = Ownify.Space2)
                .clearAndSetSemantics { },
            horizontalArrangement = Arrangement.spacedBy(Ownify.Space2),
            verticalAlignment = Alignment.Bottom
        ) {
            repeat(7) {
                Skeleton(Modifier.weight(1f).fillMaxHeight(0.46f), RoundedCornerShape(6.dp))
            }
        }
        Spacer(Modifier.height(Ownify.Space4))

        T(patterns.headline, JStyle.Subtitle, Modifier.padding(bottom = Ownify.Space1))
        T(patterns.description, JStyle.Meta)

        FlowRow(
            Modifier.fillMaxWidth().padding(top = Ownify.Space4),
            horizontalArrangement = Arrangement.spacedBy(Ownify.Space2),
            verticalArrangement = Arrangement.spacedBy(Ownify.Space2)
        ) {
            patterns.topics.forEach { Chip(it, muted = true) }
        }
    }
}

/** `components/recommendation.php`: at most one, and never an instruction. */
@Composable
private fun RecommendationCard(rec: Recommendation) {
    JCard(Modifier.fillMaxWidth().reveal(), style = CardStyle.Quiet) {
        CompactHead(OwnifyIcons.solidSparkle, rec.title)
        T(rec.headline, JStyle.Subtitle, Modifier.padding(bottom = Ownify.Space1))
        T(rec.description, JStyle.Meta)
        CardHint(rec.note, icon = null, plain = true)
    }
}

/**
 * `.card__head--compact`: a solid icon tile (`.icon-tile--solid
 * .icon-tile--neutral`: the neutral grey behind a white [icon]), the
 * card's eyebrow and — when there is one — a small line under it, 16 above
 * the body.
 */
@Composable
fun CompactHead(icon: androidx.compose.ui.graphics.vector.ImageVector, title: String, meta: String? = null, tile: Color = Ownify.Neutral) {
    Row(
        Modifier.fillMaxWidth().padding(bottom = Ownify.Space4),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)
    ) {
        IconTile(icon, color = Color.White, background = tile, border = tile)
        Column(Modifier.weight(1f)) {
            T(title, JStyle.Eyebrow, Modifier.semantics { heading() })
            if (meta != null) T(meta, JStyle.Tiny, Modifier.padding(top = Ownify.Space1))
        }
    }
}

/** `.skeleton`: a quiet placeholder with a light sweeping across it every 2.4 s. */
@Composable
fun Skeleton(modifier: Modifier, shape: androidx.compose.ui.graphics.Shape = RoundedCornerShape(50)) {
    val still = LocalStillMotion.current
    val sweep = loopValue(-1f, 1f, infiniteRepeatable(tween(2_400, easing = Ownify.EaseOut)))
    Box(
        modifier
            .clip(shape)
            .background(Ownify.fill(0.07f))
            .drawWithContent {
                drawContent()
                if (still) return@drawWithContent
                val x = sweep * size.width
                drawRect(
                    Brush.horizontalGradient(
                        listOf(Ownify.glint(0f), Ownify.glint(0.11f), Ownify.glint(0f)),
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
    val shown by animateFloatAsState(if (show) 1f else 0f, tween(Ownify.FastMs, easing = Ownify.Ease), label = "fab")
    val bottom = WindowInsets.safeDrawing.asPaddingValues().calculateBottomPadding()
    if (shown <= 0f) return

    val interaction = remember { MutableInteractionSource() }
    Box(Modifier.fillMaxWidth().fillMaxHeight(), contentAlignment = Alignment.BottomCenter) {
        Box(
            Modifier
                .padding(bottom = dockClearance(bottom) + Ownify.Space4)
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
                            0f to Ownify.ShadowFloat, 1f to Ownify.ShadowFloat.copy(alpha = 0f),
                            center = center + Offset(0f, 12.dp.toPx()), radius = size.width / 2f + 28.dp.toPx()
                        ),
                        radius = size.width / 2f + 28.dp.toPx(), center = center + Offset(0f, 12.dp.toPx())
                    )
                }
                .clip(CircleShape)
                .background(Ownify.ground(0.72f))
                .border(1.dp, Ownify.GlassBorder, CircleShape)
                .clickable(interaction, indication = null, role = Role.Button, enabled = show) {
                    scope.launch { scroll.animateScrollTo(0) }
                }
                .semantics { contentDescription = "Terug naar je dagscore" },
            contentAlignment = Alignment.Center
        ) {
            JIcon(OwnifyIcons.chevron, color = Ownify.TextSecondary)
        }
    }
}

/**
 * The legend under the Overzicht ring (`components/health-score.php`): each
 * pillar keeps its category colour, and its dot takes the colour of its score
 * band — the server's `score_band`, never the category. No score, a faint dot.
 */
internal fun overviewLegend(contributors: List<Contributor>): List<LegendItem> =
    contributors.map {
        LegendItem(it.label, it.value, Accent.of(it.accent), dot = ScoreBand.of(it.scoreBand)?.color ?: Ownify.TextFaint)
    }
