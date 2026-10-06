package com.ownify.android.ui.screens.overview

import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.ScrollState
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.gestures.awaitEachGesture
import androidx.compose.foundation.gestures.awaitFirstDown
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.drawBehind
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.input.pointer.PointerEventPass
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.layout.Layout
import androidx.compose.ui.layout.boundsInWindow
import androidx.compose.ui.layout.onGloballyPositioned
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.platform.LocalGraphicsContext
import androidx.compose.ui.platform.LocalView
import androidx.compose.ui.semantics.CustomAccessibilityAction
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.customActions
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.stateDescription
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.TextUnit
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.em
import com.ownify.android.data.AppData
import com.ownify.android.data.CompassCategory
import com.ownify.android.data.CompassComparison
import com.ownify.android.data.CompassComposition
import com.ownify.android.data.CompassDay
import com.ownify.android.data.CompassDirection
import com.ownify.android.data.CompassOpportunity
import com.ownify.android.data.CompassPart
import com.ownify.android.data.CompassPeriod
import com.ownify.android.data.CompassReadout
import com.ownify.android.data.CompassRow
import com.ownify.android.data.CompassTick
import com.ownify.android.data.CompassTrend
import com.ownify.android.ui.app.DetailColumn
import com.ownify.android.ui.app.LocalOwnedAreas
import com.ownify.android.ui.app.ownsGestures
import com.ownify.android.ui.design.BoxShadow
import com.ownify.android.ui.design.CardHint
import com.ownify.android.ui.design.Chip
import com.ownify.android.ui.design.Disclaimer
import com.ownify.android.ui.design.IconTile
import com.ownify.android.ui.design.JCard
import com.ownify.android.ui.design.JIcon
import com.ownify.android.ui.design.JStyle
import com.ownify.android.ui.design.LocalScreen
import com.ownify.android.ui.design.LocalStillMotion
import com.ownify.android.ui.design.Meter
import com.ownify.android.ui.design.OwnifyIcons
import com.ownify.android.ui.design.RangeSwitch
import com.ownify.android.ui.design.ScoreRing
import com.ownify.android.ui.design.T
import com.ownify.android.ui.design.chWidth
import com.ownify.android.ui.design.cssPadding
import com.ownify.android.ui.design.drawBoxShadows
import com.ownify.android.ui.design.drawChartLine
import com.ownify.android.ui.design.rememberPlayOnSight
import com.ownify.android.ui.design.rememberSvgPaths
import com.ownify.android.ui.design.reveal
import com.ownify.android.ui.design.stretched
import com.ownify.android.ui.screens.health.Axis
import com.ownify.android.ui.theme.Accent
import com.ownify.android.ui.theme.LocalAccent
import com.ownify.android.ui.theme.Ownify
import com.ownify.android.ui.theme.OwnifyType
import com.ownify.android.ui.theme.ScoreBand
import kotlin.math.abs
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/** How long a touch reading stays after the finger lifts (compass-history.js LINGER). */
private const val READ_LINGER_MS = 1600L

/**
 * The Scorekompas (pages/score-compass.php): the Health Score explained,
 * opened from its card on Overzicht. Four questions in the order they are
 * asked — what it is made of, what is changing, how it compares with the
 * person's own past, where the most room is — and every number and sentence
 * the server's (includes/score-compass.php). A detail page like a health
 * area's, on the health green's ground.
 */
@Composable
fun ScoreCompassDetail(data: AppData, scroll: ScrollState) {
    val compass = data.compass
    CompositionLocalProvider(LocalAccent provides Accent.HEALTH) {
        DetailColumn(scroll, back = compass.back, backAria = "Terug naar ${compass.back}") {
            HeroCard(data)
            CompositionCard(compass.composition)
            TrendCard(compass.trend)
            ComparisonCard(compass.comparison)
            OpportunityCard(compass.opportunity)
            Disclaimer(compass.footnote, Modifier.reveal())
        }
    }
}

/**
 * `components/score-direction.php`: Stijgend, Stabiel or Dalend — one arrow,
 * turned — in the text colour: a direction is not a judgement.
 */
@Composable
fun DirectionChip(direction: CompassDirection, modifier: Modifier = Modifier) {
    val turn = when (direction.key) {
        "flat" -> 90f
        "down" -> 180f
        else -> 0f
    }
    Chip(
        direction.label,
        modifier,
        quiet = true,
        leading = {
            JIcon(OwnifyIcons.arrowUp, Modifier.graphicsLayer { rotationZ = turn }, size = 13.dp, color = Ownify.TextSecondary)
        }
    )
}

/** `.card--hero`: the overall score's ring, as on Overzicht — its gradient, its glow. */
@Composable
private fun HeroCard(data: AppData) {
    val compass = data.compass
    val overall = data.overview.overall
    val (play, sight) = rememberPlayOnSight()
    val empty = compass.score == null

    JCard(Modifier.fillMaxWidth().reveal().then(sight)) {
        ScoreRing(
            value = compass.score,
            max = compass.max,
            scale = if (empty) overall.caption else "van ${compass.max}",
            label = overall.label,
            play = play,
            description = "${overall.label}: " + if (empty) "nog geen gegevens" else "${compass.score} van ${compass.max}",
            modifier = Modifier
                .align(Alignment.CenterHorizontally)
                .padding(top = Ownify.Space4, bottom = Ownify.Space3)
        )
        T(compass.title, JStyle.Section, Modifier.fillMaxWidth().semantics { heading() }, align = TextAlign.Center)
        compass.direction?.let { DirectionChip(it, Modifier.align(Alignment.CenterHorizontally).padding(top = Ownify.Space2)) }
        T(
            compass.lede,
            JStyle.Lede,
            Modifier
                .align(Alignment.CenterHorizontally)
                .padding(top = Ownify.Space2)
                .widthIn(max = chWidth(JStyle.Lede, 34f)),
            align = TextAlign.Center
        )
    }
}

// ---------------------------------------------------------------------------
// 1  What the score is made of
// ---------------------------------------------------------------------------

@Composable
private fun CompositionCard(composition: CompassComposition) {
    val (play, sight) = rememberPlayOnSight()

    JCard(Modifier.fillMaxWidth().reveal().then(sight)) {
        CompactHead(OwnifyIcons.rings, composition.title)
        T(composition.note, JStyle.Lede)

        // .compass-cats
        Column(Modifier.fillMaxWidth().padding(top = Ownify.Space4), verticalArrangement = Arrangement.spacedBy(Ownify.Space3)) {
            composition.categories.forEach { CategoryBox(it, play) }
        }
    }
}

/** `.compass-cat`: a quiet box per category — its head, then its summary or its components. */
@Composable
internal fun CategoryBox(category: CompassCategory, play: Boolean) {
    Column(Modifier.fillMaxWidth().quietBox()) {
        CategoryHead(category.label, category.meta, category.icon, category.accent, category.value, category.band)

        category.summary?.let {
            Column(Modifier.fillMaxWidth().padding(top = Ownify.Space3)) {
                Rule()
                T(it, JStyle.Lede, Modifier.padding(top = Ownify.Space3))
            }
        }

        if (category.parts.isNotEmpty()) {
            Column(Modifier.fillMaxWidth().padding(top = Ownify.Space3)) {
                Rule()
                category.parts.forEach { PartRow(it, play) }
            }
        }
    }
}

/**
 * `.compass-cat__head`: the category's tile in its own colour (which
 * category), its name and line, and its score with the band's dot (how high).
 */
@Composable
private fun CategoryHead(label: String, meta: String, icon: String?, accent: String?, value: Int?, band: String?) {
    val tint = Accent.of(accent).color
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)) {
        OwnifyIcons.solid(icon)?.let {
            IconTile(it, size = 32.dp, radius = 11.dp, iconSize = 17.dp, color = Color.White, background = tint, border = tint)
        }
        Column(Modifier.weight(1f)) {
            T(label, OwnifyType.style(Ownify.FsLabel, FontWeight.SemiBold, tracking = (-0.01).em))
            if (meta.isNotEmpty()) T(meta, JStyle.Tiny, Modifier.padding(top = 2.dp))
        }
        ScoreValue(value, band)
    }
}

/**
 * `.compass-row`: a component's name, its weight in its category, its
 * score; the 4 dp meter in its band's colour; what it was worked out from.
 */
@Composable
private fun PartRow(part: CompassPart, play: Boolean) {
    val empty = part.value == null
    val band = ScoreBand.of(part.band)?.color ?: Ownify.TextFaint

    Column(
        Modifier
            .fillMaxWidth()
            .padding(top = Ownify.Space3)
            .semantics(mergeDescendants = true) { },
        verticalArrangement = Arrangement.spacedBy(6.dp)
    ) {
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
            T(part.label, OwnifyType.style(Ownify.FsSmall, color = Ownify.TextSecondary), Modifier.weight(1f).alignByBaseline())
            part.weight?.let {
                T("$it%", OwnifyType.style(Ownify.FsTiny, color = Ownify.TextMuted, tabular = true), Modifier.alignByBaseline())
            }
            T(
                part.value?.toString() ?: "—",
                OwnifyType.style(
                    Ownify.FsSmall,
                    if (empty) FontWeight.Medium else FontWeight.SemiBold,
                    if (empty) Ownify.TextMuted else Ownify.TextPrimary,
                    tabular = true
                ),
                Modifier.alignByBaseline(),
                align = TextAlign.End
            )
        }
        Meter(
            share = part.value?.let { it / 100f },
            play = play,
            height = 4.dp,
            emptyHeight = 4.dp,
            fill = SolidColor(band)
        )
        part.note?.let { T(it, JStyle.Tiny) }
    }
}

// ---------------------------------------------------------------------------
// 2  What is changing
// ---------------------------------------------------------------------------

@Composable
private fun TrendCard(trend: CompassTrend) {
    // A server from before the periods: its 30 days, as they were shown.
    if (trend.periods.isEmpty()) {
        LegacyTrendCard(trend)
        return
    }

    var selected by rememberSaveable { mutableStateOf(trend.defaultPeriod) }
    val period = trend.periods.firstOrNull { it.key == selected } ?: trend.periods.first()
    // The day in the panel, in trend.days: today until another is read, and
    // today again when the period changes.
    var shown by remember(period.key, trend.days) { mutableIntStateOf(trend.days.lastIndex) }

    JCard(Modifier.fillMaxWidth().reveal()) {
        // .card__head: the icon and title, and the period's direction at the far end.
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)) {
            Row(Modifier.weight(1f), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)) {
                IconTile(OwnifyIcons.solidChart, color = Color.White, background = Ownify.Neutral, border = Ownify.Neutral)
                T(trend.title, JStyle.Eyebrow, Modifier.semantics { heading() })
            }
            period.direction?.let { DirectionChip(it) }
        }

        // .compass-history__switch: the four periods, the score's own week first.
        RangeSwitch(
            trend.periods.map { it.key to it.label },
            period.key,
            { selected = it },
            Modifier.padding(top = Ownify.Space4),
            wide = true,
            label = trend.switchLabel
        )

        Column(Modifier.fillMaxWidth().padding(top = Ownify.Space4)) {
            if (period.text.isNotEmpty() || period.since != null) {
                Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
                    period.text.forEach { T(it, JStyle.Lede) }
                    period.since?.let { T(it, OwnifyType.style(Ownify.FsSmall, color = Ownify.TextMuted)) }
                }
            }
            PeriodChart(
                period,
                trend.days,
                Modifier.padding(top = if (period.text.isNotEmpty() || period.since != null) Ownify.Space5 else 0.dp)
            ) { shown = it }
        }

        trend.days.getOrNull(shown)?.let { day ->
            DayPanel(day, trend.readout)
            T(
                trend.readout.hint,
                JStyle.Tiny,
                Modifier.fillMaxWidth().padding(top = Ownify.Space3),
                align = TextAlign.Center
            )
        }
    }
}

/**
 * One period's line (`.compass-plot`): the grid, the wash, the line drawn on
 * whenever the period is shown, a dot for every day of a week or for a day
 * on its own — a ring where an earlier score was carried — and the dates
 * under it. A finger on it reads a day, as on a goal's Verloop: the nearest
 * day, a crosshair, its date and score above the line, and [onRead] puts
 * the whole day in the panel. A vertical drag still scrolls.
 */
@Composable
private fun PeriodChart(period: CompassPeriod, days: List<CompassDay>, modifier: Modifier, onRead: (Int) -> Unit) {
    val still = LocalStillMotion.current
    val view = LocalView.current
    val owned = LocalOwnedAreas.current
    val scope = rememberCoroutineScope()
    val draw = remember { Animatable(if (still) 1f else 0f) }
    var seen by remember { mutableStateOf(still) }
    val viewBox = Size(period.width, period.height)
    val lines = rememberSvgPaths(period.chart.line)
    val washes = rememberSvgPaths(period.chart.area)
    val filled = period.chart.hasData
    val color = Ownify.Health
    val at = period.at
    var reading by remember(period.key) { mutableIntStateOf(-1) }
    var linger by remember { mutableStateOf<Job?>(null) }
    val ownKey = remember { Any() }

    DisposableEffect(ownKey) { onDispose { owned.set(ownKey, null) } }

    LaunchedEffect(seen, period.key) {
        if (!seen || still) return@LaunchedEffect
        draw.snapTo(0f)
        draw.animateTo(1f, tween(900, easing = Ownify.EaseOut))
    }

    fun show(index: Int) {
        linger?.cancel()
        reading = index.coerceIn(0, at.lastIndex)
        onRead(period.start + reading)
    }

    fun hide() {
        linger?.cancel()
        reading = -1
    }

    /** The day nearest to a horizontal position, in % of the plot. */
    fun nearest(percent: Float): Int {
        var best = 0
        var gap = Float.MAX_VALUE
        at.forEachIndexed { i, (x, _) ->
            val d = abs(x - percent)
            if (d < gap) {
                gap = d
                best = i
            }
        }
        return best
    }

    val day = days.getOrNull(period.start + reading).takeIf { reading >= 0 }

    Box(modifier.fillMaxWidth()) {
        Column(Modifier.fillMaxWidth()) {
            Box(
                Modifier
                    .fillMaxWidth()
                    .height(100.dp)
                    .then(
                        if (!filled || at.isEmpty()) Modifier.clearAndSetSemantics { contentDescription = period.aria }
                        else Modifier
                            .ownsGestures(owned, ownKey)
                            .semantics {
                                contentDescription = period.aria
                                stateDescription = day?.let { "${it.label}, ${it.value ?: "—"}" + (it.note?.let { n -> ". $n" } ?: "") } ?: ""
                                liveRegion = LiveRegionMode.Polite
                                // The keyboard's arrows, for a screen reader: day by day.
                                customActions = listOf(
                                    CustomAccessibilityAction("Volgende dag") {
                                        show(if (reading < 0) at.lastIndex else minOf(at.lastIndex, reading + 1)); true
                                    },
                                    CustomAccessibilityAction("Vorige dag") {
                                        show(if (reading < 0) at.lastIndex else maxOf(0, reading - 1)); true
                                    },
                                    CustomAccessibilityAction("Dag verbergen") { hide(); true }
                                )
                            }
                            .pointerInput(at) {
                                // In the final pass: whatever the page's scroll made of the same touch is known by then.
                                awaitEachGesture {
                                    val down = awaitFirstDown(requireUnconsumed = false, pass = PointerEventPass.Final)
                                    if (size.width > 0) show(nearest(down.position.x / size.width * 100f))
                                    while (true) {
                                        val change = awaitPointerEvent(PointerEventPass.Final).changes.firstOrNull { it.id == down.id }
                                        if (change == null) {
                                            hide()
                                            break
                                        }
                                        if (!change.pressed) {
                                            // Lifted: the reading stays a moment; the panel keeps the day.
                                            linger?.cancel()
                                            linger = scope.launch {
                                                delay(READ_LINGER_MS)
                                                reading = -1
                                            }
                                            break
                                        }
                                        if (change.isConsumed) {
                                            hide()
                                            break
                                        }
                                        if (size.width > 0) show(nearest(change.position.x / size.width * 100f))
                                    }
                                }
                            }
                    )
            ) {
                Canvas(
                    Modifier
                        .fillMaxSize()
                        .alpha(if (filled) 1f else 0.55f)
                        .onGloballyPositioned { coordinates ->
                            if (seen) return@onGloballyPositioned
                            val bounds = coordinates.boundsInWindow()
                            val visible = (minOf(bounds.bottom, view.height.toFloat()) - maxOf(bounds.top, 0f)).coerceAtLeast(0f)
                            if (bounds.height > 0f && visible / bounds.height >= 0.25f && bounds.left < view.width && bounds.right > 0f) seen = true
                        }
                ) {
                    val sy = size.height / viewBox.height
                    for (line in listOf(0.25f, 0.5f, 0.75f)) {
                        val y = (12f + line * (viewBox.height - 24f)) * sy
                        drawLine(Ownify.ink(0.055f), Offset(0f, y), Offset(size.width, y), 1.dp.toPx())
                    }
                    washes.forEach { drawPath(it.stretched(viewBox, size), color.copy(alpha = 0.10f)) }
                    lines.forEach { drawChartLine(it.stretched(viewBox, size), color, 2.2.dp.toPx(), draw.value) }

                    // .compass-plot__dot: 7 across with a 2 px ring of the card, never stretched.
                    at.forEachIndexed { i, (x, y) ->
                        if (y == null) return@forEachIndexed
                        val alone = at.getOrNull(i - 1)?.second == null && at.getOrNull(i + 1)?.second == null
                        if (!period.dayDots && !alone) return@forEachIndexed
                        val c = Offset(x / 100f * size.width, y / 100f * size.height)
                        drawCircle(Ownify.BgSecondary, radius = 5.5.dp.toPx(), center = c)
                        drawCircle(color, radius = 3.5.dp.toPx(), center = c)
                        if (days.getOrNull(period.start + i)?.state == "carried") {
                            drawCircle(Ownify.BgSecondary, radius = 2.dp.toPx(), center = c)
                        }
                    }

                    // The reading: the crosshair, and the day's point if it had a score.
                    at.getOrNull(reading)?.let { (x, y) ->
                        val px = x / 100f * size.width
                        drawRect(Ownify.ink(0.28f), topLeft = Offset(px - 0.5.dp.toPx(), 0f), size = Size(1.dp.toPx(), size.height))
                        if (y != null) {
                            val c = Offset(px, y / 100f * size.height)
                            drawCircle(Ownify.mix(color, 0.22f, Color.Transparent), radius = 12.dp.toPx(), center = c)
                            drawCircle(Ownify.BgSecondary, radius = 8.dp.toPx(), center = c)
                            drawCircle(color, radius = 6.dp.toPx(), center = c)
                        }
                    }
                }

                if (day != null) at.getOrNull(reading)?.let { (x, _) -> DayTip(x, day) }
            }
            TickAxis(period.axis, Modifier.padding(top = Ownify.Space2))
        }

        if (!filled) {
            T(
                period.empty,
                OwnifyType.style(Ownify.FsSmall, color = Ownify.TextMuted),
                Modifier.fillMaxWidth().padding(top = 46.dp),
                align = TextAlign.Center
            )
        }
    }
}

/** `.goal-chart__tip`: the day's date and score, just above the line, kept inside its width. */
@Composable
private fun DayTip(x: Float, day: CompassDay) {
    val density = LocalDensity.current
    val shadows = LocalGraphicsContext.current.shadowContext
    Layout(
        content = {
            val shape = RoundedCornerShape(12.dp)
            Column(
                Modifier
                    .drawBehind {
                        drawBoxShadows(shape, listOf(BoxShadow(y = 10.dp, blur = 28.dp, color = Ownify.shade(0.32f))), shadows)
                    }
                    .clip(shape)
                    .background(Ownify.TipSurfaceSolid)
                    .border(1.dp, Ownify.GlassBorder, shape)
                    .cssPadding(PaddingValues(start = 11.dp, end = 11.dp, top = 4.dp, bottom = 5.dp), border = 1.dp),
                horizontalAlignment = Alignment.CenterHorizontally
            ) {
                T(day.label, OwnifyType.style(Ownify.FsTiny, color = Ownify.TextMuted, lineHeight = 1.25.em), maxLines = 1)
                T(day.value?.toString() ?: "—", OwnifyType.style(Ownify.FsSmall, FontWeight.Bold, lineHeight = 1.25.em, tabular = true), maxLines = 1)
            }
        },
        modifier = Modifier.clearAndSetSemantics { }
    ) { measurables, constraints ->
        val tip = measurables.first().measure(constraints.copy(minWidth = 0, minHeight = 0, maxWidth = Int.MAX_VALUE))
        val width = constraints.maxWidth
        layout(width, constraints.maxHeight) {
            val left = if (tip.width >= width) (width - tip.width) / 2f
            else (x / 100f * width - tip.width / 2f).coerceIn(0f, (width - tip.width).toFloat())
            // bottom: calc(100% + 2px) — above the line, over whatever is there.
            tip.place(left.toInt(), -tip.height - with(density) { 2.dp.roundToPx() })
        }
    }
}

/** `.chart__axis`: each date at its day — the first from the left edge, the last to the right. */
@Composable
private fun TickAxis(ticks: List<CompassTick>, modifier: Modifier) {
    val density = LocalDensity.current
    val style = OwnifyType.style(Ownify.FsTiny, color = Ownify.TextMuted)
    // height: 1.2em of the card's 15 sp text.
    val height = with(density) { (Ownify.FsBody.toPx() * 1.2f).toDp() }
    Layout(
        content = { ticks.forEach { T(it.label, style, maxLines = 1) } },
        modifier = modifier.fillMaxWidth().height(height).clearAndSetSemantics { }
    ) { measurables, constraints ->
        val placeables = measurables.map { it.measure(constraints.copy(minWidth = 0, minHeight = 0, maxWidth = Int.MAX_VALUE)) }
        layout(constraints.maxWidth, constraints.maxHeight) {
            placeables.forEachIndexed { i, p ->
                val left = ticks[i].x / 100f * constraints.maxWidth
                val x = when (i) {
                    0 -> left
                    placeables.lastIndex -> left - p.width
                    else -> left - p.width / 2f
                }
                p.place(x.toInt(), 0)
            }
        }
    }
}

/**
 * `.compass-day`: one day read closely — its date and Health Score, why a
 * day without a score of its own still had one (or that it had none), and
 * each category with its score and its parts.
 */
@Composable
private fun DayPanel(day: CompassDay, readout: CompassReadout) {
    val names = remember(readout) { readout.categories.associateBy { it.id } }
    Column(Modifier.fillMaxWidth().padding(top = Ownify.Space4).quietBox()) {
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)) {
            Column(Modifier.weight(1f)) {
                T(day.label, OwnifyType.style(Ownify.FsLabel, FontWeight.SemiBold, tracking = (-0.01f).em))
                T(readout.score, JStyle.Tiny, Modifier.padding(top = 2.dp))
            }
            ScoreValue(day.value, day.band)
        }
        day.note?.let { T(it, JStyle.Tiny, Modifier.padding(top = Ownify.Space2)) }

        Column(Modifier.fillMaxWidth().padding(top = Ownify.Space3)) {
            Rule()
            Column(Modifier.fillMaxWidth().padding(top = Ownify.Space3), verticalArrangement = Arrangement.spacedBy(Ownify.Space3)) {
                day.categories.forEach { category ->
                    val name = names[category.id]
                    val accent = Accent.of(name?.accent ?: category.id).color
                    Row(
                        Modifier.fillMaxWidth().semantics(mergeDescendants = true) { },
                        verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)
                    ) {
                        // .legend__dot: the category's own colour.
                        Box(
                            Modifier
                                .size(7.dp)
                                .drawBehind {
                                    drawCircle(Ownify.mix(accent, 0.18f, Color.Transparent), radius = size.width / 2f + 3.dp.toPx())
                                    drawCircle(accent)
                                }
                        )
                        Column(Modifier.weight(1f)) {
                            T(name?.label ?: category.id, OwnifyType.style(Ownify.FsSmall, color = Ownify.TextSecondary))
                            category.parts?.let { T(it, JStyle.Tiny, Modifier.padding(top = 2.dp)) }
                        }
                        ScoreValue(category.value, category.band, textSize = Ownify.FsSmall)
                    }
                }
            }
        }
    }
}

/** A server from before the periods: the 30 days, their sentences and their line. */
@Composable
private fun LegacyTrendCard(trend: CompassTrend) {
    JCard(Modifier.fillMaxWidth().reveal()) {
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)) {
            Row(Modifier.weight(1f), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)) {
                IconTile(OwnifyIcons.solidChart, color = Color.White, background = Ownify.Neutral, border = Ownify.Neutral)
                T(trend.title, JStyle.Eyebrow, Modifier.semantics { heading() })
            }
            trend.direction?.let { DirectionChip(it) }
        }

        if (trend.text.isNotEmpty()) {
            Column(Modifier.fillMaxWidth().padding(top = Ownify.Space4), verticalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
                trend.text.forEach { T(it, JStyle.Lede) }
            }
        }

        Chart(trend)
    }
}

/**
 * `.chart` of a server from before the periods: the 30 days' line in a 100 dp box, in the health green with its
 * wash, three gridlines and the axis — drawn on when first looked at, as
 * Gezondheid's trend is.
 */
@Composable
private fun Chart(trend: CompassTrend) {
    val still = LocalStillMotion.current
    val view = LocalView.current
    val draw = remember { Animatable(if (still) 1f else 0f) }
    var seen by remember { mutableStateOf(still) }
    val viewBox = Size(trend.width, trend.height)
    val lines = rememberSvgPaths(trend.chart.line)
    val washes = rememberSvgPaths(trend.chart.area)
    val filled = trend.chart.hasData
    val color = Ownify.Health

    LaunchedEffect(seen) {
        if (!seen || still) return@LaunchedEffect
        draw.snapTo(0f)
        draw.animateTo(1f, tween(900, easing = Ownify.EaseOut))
    }

    Box(Modifier.fillMaxWidth().padding(top = Ownify.Space5)) {
        Column(Modifier.fillMaxWidth()) {
            Canvas(
                Modifier
                    .fillMaxWidth()
                    .height(100.dp)
                    .alpha(if (filled) 1f else 0.55f)
                    .onGloballyPositioned { coordinates ->
                        if (seen) return@onGloballyPositioned
                        val bounds = coordinates.boundsInWindow()
                        val visible = (minOf(bounds.bottom, view.height.toFloat()) - maxOf(bounds.top, 0f)).coerceAtLeast(0f)
                        if (bounds.height > 0f && visible / bounds.height >= 0.25f && bounds.left < view.width && bounds.right > 0f) seen = true
                    }
                    .clearAndSetSemantics { contentDescription = trend.aria }
            ) {
                val sx = size.width / viewBox.width
                val sy = size.height / viewBox.height
                for (line in listOf(0.25f, 0.5f, 0.75f)) {
                    val y = (12f + line * (viewBox.height - 24f)) * sy
                    drawLine(Ownify.ink(0.055f), Offset(0f, y), Offset(size.width, y), 1.dp.toPx())
                }
                washes.forEach { drawPath(it.stretched(viewBox, size), color.copy(alpha = 0.10f)) }
                lines.forEach { drawChartLine(it.stretched(viewBox, size), color, 2.2.dp.toPx(), draw.value) }
                for ((x, y) in trend.chart.dots) {
                    val rx = 2.5f * sx
                    val ry = 2.5f * sy
                    drawOval(color, topLeft = Offset(x * sx - rx, y * sy - ry), size = Size(rx * 2, ry * 2))
                }
            }
            Axis(trend.axis, Modifier.padding(top = Ownify.Space2))
        }

        if (!filled) {
            T(
                trend.empty,
                OwnifyType.style(Ownify.FsSmall, color = Ownify.TextMuted),
                Modifier.fillMaxWidth().padding(top = 46.dp),
                align = TextAlign.Center
            )
        }
    }
}

// ---------------------------------------------------------------------------
// 3  Compared with yourself
// ---------------------------------------------------------------------------

@Composable
private fun ComparisonCard(comparison: CompassComparison) {
    JCard(Modifier.fillMaxWidth().reveal()) {
        CompactHead(OwnifyIcons.solidPulse, comparison.title)
        T(comparison.note, JStyle.Lede)

        Column(Modifier.fillMaxWidth().padding(top = Ownify.Space4)) {
            comparison.rows.forEachIndexed { i, row -> CompareRow(row, first = i == 0) }
        }

        comparison.delta?.let {
            Column(Modifier.fillMaxWidth().padding(top = Ownify.Space3)) {
                Rule()
                T(it, OwnifyType.style(Ownify.FsSmall, FontWeight.SemiBold, Ownify.TextPrimary), Modifier.padding(top = Ownify.Space3))
            }
        }
    }
}

/** `.metric-row`: the period and what it is, the score with its band's dot; a hairline between rows. */
@Composable
private fun CompareRow(row: CompassRow, first: Boolean) {
    Column(Modifier.fillMaxWidth()) {
        if (!first) Rule()
        Row(
            Modifier
                .fillMaxWidth()
                .cssPadding(top = if (first) 0.dp else Ownify.Space3, bottom = Ownify.Space3, above = if (first) 0.dp else 1.dp)
                .semantics(mergeDescendants = true) { },
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)
        ) {
            Column(Modifier.weight(1f)) {
                T(row.label, OwnifyType.style(Ownify.FsSmall, color = Ownify.TextSecondary))
                T(row.note, JStyle.Tiny, Modifier.padding(top = 2.dp))
            }
            ScoreValue(row.value, row.band)
        }
    }
}

// ---------------------------------------------------------------------------
// 4  Where the most room is
// ---------------------------------------------------------------------------

@Composable
private fun OpportunityCard(opportunity: CompassOpportunity) {
    JCard(Modifier.fillMaxWidth().reveal()) {
        CompactHead(OwnifyIcons.solidSparkle, opportunity.title)

        if (opportunity.filled) {
            Box(Modifier.fillMaxWidth().quietBox()) {
                CategoryHead(
                    opportunity.name.orEmpty(), opportunity.label.orEmpty(), opportunity.icon, opportunity.accent,
                    opportunity.value, opportunity.band
                )
            }
            Column(Modifier.fillMaxWidth().padding(top = Ownify.Space4), verticalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
                opportunity.fact?.let { T(it, OwnifyType.style(Ownify.FsSmall, color = Ownify.TextPrimary)) }
                opportunity.relation?.let { T(it, JStyle.Lede) }
            }
            opportunity.gainText?.let { CardHint(it, icon = null, plain = true) }
        } else {
            T(opportunity.empty.orEmpty(), OwnifyType.style(Ownify.FsSmall, color = Ownify.TextMuted))
        }
    }
}

// ---------------------------------------------------------------------------
// Shared parts
// ---------------------------------------------------------------------------

/**
 * `.compass-score`: a score with its band's dot and 3 dp halo — the colour
 * says how high, never which category. No score: a faint dot and "—".
 */
@Composable
internal fun ScoreValue(value: Int?, band: String?, textSize: TextUnit = Ownify.FsLabel) {
    val scored = ScoreBand.of(band)
    val dot = scored?.color ?: Ownify.TextFaint
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
        Box(
            Modifier
                .size(7.dp)
                .drawBehind {
                    drawCircle(Ownify.mix(dot, 0.18f, Color.Transparent), radius = size.width / 2f + 3.dp.toPx())
                    drawCircle(dot)
                }
        )
        T(
            value?.toString() ?: "—",
            OwnifyType.style(
                textSize,
                if (scored != null) FontWeight.SemiBold else FontWeight.Medium,
                if (scored != null) Ownify.TextPrimary else Ownify.TextMuted,
                tabular = true
            )
        )
    }
}

/** The quiet box of `.compass-cat` and `.compass-chance__head`: hairline, radius 20, `.035` fill. */
@Composable
private fun Modifier.quietBox(): Modifier {
    val shape = RoundedCornerShape(Ownify.RadiusMd)
    val horizontal = if (LocalScreen.current.narrow) Ownify.Space2 else Ownify.Space3
    return this
        .clip(shape)
        .background(Ownify.fill(0.035f))
        .border(1.dp, Ownify.GlassHairline, shape)
        .cssPadding(PaddingValues(horizontal = horizontal, vertical = Ownify.Space3), border = 1.dp)
}

/** A hairline between two parts of a card. */
@Composable
private fun Rule() {
    Box(Modifier.fillMaxWidth().height(1.dp).background(Ownify.GlassHairline))
}
