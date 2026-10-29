package com.ownify.android.ui.screens.overview

import com.ownify.android.ui.design.hitArea
import com.ownify.android.ui.design.glass
import com.ownify.android.ui.design.Panes
import com.ownify.android.ui.design.liquidGlass
import com.ownify.android.ui.design.insetRow

import com.ownify.android.i18n.OwnifyFormat
import com.ownify.android.i18n.trf
import com.ownify.android.i18n.tr
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
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
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
import com.ownify.android.ui.design.PillarRings
import com.ownify.android.ui.design.HeroArrivalMs
import com.ownify.android.ui.screens.research.ResearchEvidence

/**
 * Overzicht (pages/overview.php): in a new account's first days the card of
 * its baseline, then the day's score, the personal goal, the insights and
 * the one suggestion — and the line under them when there is one to say. The scroll-to-top control appears 360 dp down.
 */
@Composable
fun OverviewPage(data: AppData, scroll: ScrollState) {
    val overview = data.overview
    Box {
        PageColumn(scroll) {
            // The first days of a new account (docs/FIRST-DAYS.md); nothing outside them.
            CalibrationCard(data)
            HealthScoreCard(data)
            GoalProgressCard(overview.goal, data.goals.primary?.id)
            InsightsCard(overview.insights)
            RecommendationCard(overview.recommendation)
            if (data.disclaimer.isNotEmpty()) Disclaimer(data.disclaimer, Modifier.reveal())
        }
        ScrollTop(scroll)
    }
}

/**
 * `components/health-score.php`: the dominant block — the ring, its number
 * and what it is made of — standing on the ground, not in a card
 * (`.card--hero`): "Vandaag" is the page's title, as every page's is. A tap
 * anywhere on it opens the Scorekompas ([ScoreCompassDetail]); the ring and
 * its name settle under the finger. It says where the score is heading.
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

    // `.card--opens`: a tap anywhere on the hero opens it — as the label's
    // button covers it on the website. Only the label is the button for
    // TalkBack, so the rest reads as before. There is no card to press in:
    // the ring and its name settle instead.
    val settles = if (!compass.available) Modifier else Modifier.press(interaction)
    val opens = if (!compass.available) Modifier else Modifier
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

    Column(Modifier.fillMaxWidth().reveal().then(sight).then(opens).padding(top = Ownify.Space1, bottom = Ownify.Space2)) {
        // .card__head: the day and the chosen focus.
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)) {
            Column(Modifier.weight(1f)) {
                T(data.overview.title, JStyle.LargeTitle, Modifier.semantics { heading() })
                val date = if (screen.narrow) data.today.date else "${data.today.weekday} ${data.today.date}"
                T(date, OwnifyType.style(Ownify.FsBody, color = Ownify.TextSecondary), Modifier.padding(top = 2.dp))
            }
            Chip(data.focusLabel, dot = true, modifier = Modifier.semantics { contentDescription = tr("Gekozen focus") + ": " + data.focusLabel })
        }

        ScoreRing(
            value = overall.value,
            max = overall.max,
            scale = if (empty) overall.caption else trf("van %s", overall.max),
            label = overall.label,
            play = play,
            glow = true,
            modifier = Modifier
                .align(Alignment.CenterHorizontally)
                .padding(top = Ownify.Space6, bottom = Ownify.Space5)
                .then(settles)
        )

        // The label, with the way in beside it (`.card__open-chevron`): the button,
        // still reading as its word, described as the website's button is.
        val label = if (!compass.available) Modifier else Modifier.semantics(mergeDescendants = true) {
            role = Role.Button
            contentDescription = overall.label + ": " +
                (if (empty) overall.caption.lowercase(OwnifyFormat.locale()) else trf("%1\$s van %2\$s", overall.value, overall.max)) +
                (compass.direction?.let { ", " + it.label.lowercase(OwnifyFormat.locale()) } ?: "")
            onClick(label = compass.open) { open(); true }
        }
        Row(Modifier.fillMaxWidth().then(settles).then(label), horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically) {
            T(overall.label, JStyle.HeroLabel)
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

        // The pillars wait until the score ring has wholly arrived (none moves without a score).
        PillarRings(
            overviewLegend(data.overview.contributors), play,
            Modifier.align(Alignment.CenterHorizontally).padding(top = Ownify.Space5),
            after = if (empty) 0 else HeroArrivalMs
        )

        if (empty) CardHint(overall.emptyHint, textAlign = TextAlign.Center)
    }
}

/** `components/goal-progress.php`: whichever goal is primary, or how to set one. */
@Composable
private fun GoalProgressCard(goal: GoalCard, primaryId: String?) {
    val shell = LocalShell.current
    val (play, sight) = rememberPlayOnSight()
    val set = goal.state != "unset" && goal.progress != null
    val interaction = remember { MutableInteractionSource() }

    // `.card--opens`: a tap anywhere on the card opens the primary goal's page
    // (Doelen while there is none), as on the website.
    JCard(
        Modifier.fillMaxWidth().reveal().then(sight)
            .press(interaction)
            .clickable(interaction, indication = null, role = Role.Button, onClickLabel = if (primaryId != null) tr("doel openen") else tr("naar Doelen")) {
                if (primaryId != null) shell.openDetail(Detail.GoalPage(primaryId)) else shell.tab("goals")
            }
    ) {
        CompactHead(OwnifyIcons.solidChart, goal.title)

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
                T(goal.ctaNote, JStyle.Footnote, Modifier.weight(1f))
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
    val reading = goal.reading ?: trf("%d%% van je doel", goal.progress ?: 0)
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
                        .semantics { contentDescription = if (set) "${goal.progress}% — $reading" else tr("Nog geen doel ingesteld") }
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
            .liquidGlass(shape, Panes.Tip)
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
                                    drawCircle(Ownify.HealthFill, radius = size.width / 2f + 3.dp.toPx())
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

/**
 * `components/insights.php`: one row per area — Slaap, Voeding, Training —
 * each the day's stored insight with its evidence, or a quiet placeholder.
 * With nothing in it yet it stays, quieter (`.card--insights.is-empty`): a
 * well instead of glass, its rows divided by hairlines; a real insight gets
 * the full card back.
 *
 * With a [slot] it is that area's own card, on that area's page: the same
 * stored insight, the same row, and nothing at all when the area has none.
 */
@Composable
internal fun InsightsCard(insights: Insights, slot: String? = null) {
    // 31.0 (docs/CORRELATIONS.md): on Overzicht, with migration 029, the four
    // combined insights; the head opens Inzichten, a row still being gathered
    // (Nog onduidelijk) is as quiet as an empty one.
    val combined = slot == null && insights.combined
    val items = if (slot == null) insights.items else listOfNotNull(insights.context[slot])
    if (items.isEmpty() && !combined) return
    val dimmed = if (combined) insights.state != "filled" else items.all { it.state == "empty" }
    JCard(Modifier.fillMaxWidth().reveal(), style = if (dimmed) CardStyle.Dimmed else CardStyle.Default, highlight = !dimmed) {
        if (combined && insights.open != null) {
            val shell = LocalShell.current
            val interaction = remember { MutableInteractionSource() }
            // `.cm-head`: the head is the way in — the whole row, with a chevron.
            Row(
                Modifier
                    .fillMaxWidth()
                    .press(interaction)
                    .clickable(interaction, indication = null, role = Role.Button) { shell.openDetail(Detail.Insights) }
                    .semantics(mergeDescendants = true) { },
                verticalAlignment = Alignment.CenterVertically
            ) {
                Box(Modifier.weight(1f)) { CompactHead(OwnifyIcons.solidPulse, insights.title, meta = insights.subtitle, dimmed = dimmed) }
                JIcon(OwnifyIcons.chevronRight, Modifier.padding(bottom = Ownify.Space4), size = 16.dp, color = Ownify.TextFaint)
            }
        } else {
            CompactHead(OwnifyIcons.solidPulse, insights.title, meta = insights.subtitle.takeIf { slot == null }, dimmed = dimmed)
        }
        Column(verticalArrangement = Arrangement.spacedBy(Ownify.Space3)) {
            items.forEachIndexed { index, item ->
                val accent = Accent.of(item.accent).color
                val empty = if (combined) item.state != "filled" else item.state == "empty"
                // `.insights__item`: a Row inside the card.
                val row = if (!dimmed) Modifier
                    .insetRow()
                    .cssPadding(PaddingValues(Ownify.Space3), border = 1.dp)
                else Modifier
                    .drawBehind { if (index > 0) drawRect(Ownify.GlassHairline, size = size.copy(height = 1.dp.toPx())) }
                    .cssPadding(PaddingValues(vertical = Ownify.Space3), border = 1.dp)
                Row(
                    Modifier
                        .fillMaxWidth()
                        .then(row),
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
                        if (combined) {
                            // Its label, and "only your own data" where no research is behind it.
                            T(listOfNotNull(item.tierLabel.takeIf { it.isNotEmpty() }, item.own).joinToString(" · "), JStyle.Tiny, Modifier.padding(top = Ownify.Space2))
                        } else if (!empty) {
                            ResearchEvidence(item.evidence)
                        }
                    }
                }
            }
        }
    }
}

/**
 * `components/recommendation.php`: the day's one at most — from the
 * person's own data and approved research, always optional — with its
 * evidence. Without one it is the quiet empty card
 * (`.card--recommendation.is-empty`) saying so; a suggestion that no longer
 * fits newer data says that instead.
 */
@Composable
private fun RecommendationCard(rec: Recommendation) {
    val filled = rec.filled
    JCard(Modifier.fillMaxWidth().reveal(), style = if (filled) CardStyle.Default else CardStyle.Dimmed, highlight = filled) {
        CompactHead(OwnifyIcons.solidSparkle, rec.title, dimmed = !filled)
        T(rec.headline, JStyle.Subtitle, Modifier.padding(bottom = Ownify.Space1))
        T(rec.description, JStyle.Meta)
        if (filled) ResearchEvidence(rec.evidence)
    }
}

/**
 * `.card__head--compact`: a solid icon tile (`.icon-tile--solid
 * .icon-tile--neutral`: the neutral grey behind a white [icon]), the
 * card's eyebrow and — when there is one — a small line under it, 16 above
 * the body.
 */
@Composable
fun CompactHead(icon: androidx.compose.ui.graphics.vector.ImageVector, title: String, meta: String? = null, tile: Color = Ownify.Neutral, dimmed: Boolean = false) {
    Row(
        Modifier.fillMaxWidth().padding(bottom = Ownify.Space4),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)
    ) {
        IconTile(icon, color = Color.White, background = tile, border = tile)
        Column(Modifier.weight(1f)) {
            T(title, if (dimmed) JStyle.Eyebrow.copy(color = Ownify.TextSecondary) else JStyle.Eyebrow, Modifier.semantics { heading() })
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
                .offset(x = screen.shell / 2 - 44.dp + 22.dp)
                .graphicsLayer {
                    alpha = shown
                    translationY = (1f - shown) * 8.dp.toPx()
                }
                .hitArea(
                    Modifier
                        .clickable(interaction, indication = null, role = Role.Button, enabled = show) {
                            scope.launch { scroll.animateScrollTo(0) }
                        }
                        .semantics { contentDescription = tr("Terug naar je dagscore") }
                )
                .press(interaction, scale = 0.94f)
                .size(44.dp)
                .glass(CircleShape),
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
