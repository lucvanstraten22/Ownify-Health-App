package com.ownify.android.ui.screens.health

import androidx.compose.foundation.ScrollState
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.gestures.awaitEachGesture
import androidx.compose.foundation.gestures.awaitFirstDown
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.runtime.Composable
import androidx.compose.runtime.setValue
import androidx.compose.runtime.getValue
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.input.pointer.PointerEventPass
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.layout.SubcomposeLayout
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.Constraints
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.em
import com.ownify.android.data.AreaChart
import com.ownify.android.data.HeartChart
import com.ownify.android.data.HeartPlot
import com.ownify.android.data.HeartZones
import com.ownify.android.data.Metric
import com.ownify.android.data.TrainingSession
import com.ownify.android.data.TrainingSessions
import com.ownify.android.data.TrainingView
import com.ownify.android.ui.app.Detail
import com.ownify.android.ui.app.DetailColumn
import com.ownify.android.ui.app.LocalOwnedAreas
import com.ownify.android.ui.app.LocalShell
import com.ownify.android.ui.app.ownsGestures
import com.ownify.android.ui.design.HistoryPlot
import com.ownify.android.ui.design.IconTile
import com.ownify.android.ui.design.JCard
import com.ownify.android.ui.design.JIcon
import com.ownify.android.ui.design.JStyle
import com.ownify.android.ui.design.LocalScreen
import com.ownify.android.ui.design.OwnifyIcons
import com.ownify.android.ui.design.PageIntro
import com.ownify.android.ui.design.PlotBand
import com.ownify.android.ui.design.PlotLine
import com.ownify.android.ui.design.RangeSwitch
import com.ownify.android.ui.design.T
import com.ownify.android.ui.design.press
import com.ownify.android.ui.design.reveal
import com.ownify.android.ui.theme.Accent
import com.ownify.android.ui.theme.InButton
import com.ownify.android.ui.theme.LocalAccent
import com.ownify.android.ui.theme.Ownify
import com.ownify.android.ui.theme.OwnifyType
import kotlin.math.abs

/*
 * Training, drawn (docs/TRAINING.md) — the website's
 * components/training-view.php, training-sessions.php, heart-chart.php,
 * heart-plot.php, heart-zones.php and pages/training-session.php: the latest
 * sessions beside the sessions per day, four charts two by two, the heart
 * rate across the page with its zones, HRV and Hartbelasting under it, and a
 * session's own page. Everything shown is the server's
 * (lib/hydrate-training.php).
 */

/** A swipe sideways (training.js SWIPE), and how quick one over the chart must be (FLICK). */
private val SwipeDistance = 48.dp
private const val FLICK_MS = 350L

/**
 * `components/training-view.php`: the sessions beside the sessions per day,
 * the four charts two by two, the heart rate, and HRV beside Hartbelasting.
 */
@Composable
fun TrainingViewContent(view: TrainingView) {
    val charts = view.charts.associateBy { it.id }
    TrainingTop(view.sessions, charts[view.perDay])
    AreaChartsGrid("training", view.grid.mapNotNull { charts[it] })
    HeartCard(view.heart)
    AreaChartsGrid("training", view.lower.mapNotNull { charts[it] })
}

// ---------------------------------------------------------------------------
// The top section
// ---------------------------------------------------------------------------

/**
 * `.training-top`: one card, two halves — the sessions and the sessions per
 * day, a hairline between them — as tall as 1.2 times a half's width (the
 * list of four sessions fits), or taller should the list need it.
 */
@Composable
private fun TrainingTop(sessions: TrainingSessions, perDay: AreaChart?) {
    val shell = LocalShell.current
    val density = LocalDensity.current
    JCard(Modifier.fillMaxWidth().reveal(), padding = PaddingValues(0.dp)) {
        SubcomposeLayout(Modifier.fillMaxWidth()) { constraints ->
            val line = 1.dp.roundToPx()
            val w = (constraints.maxWidth - line) / 2
            // The list's own height at that width, asked of it rather than measured: composed once.
            val list = subcompose("sessions") { SessionsCard(sessions, Modifier.fillMaxHeight()) }.first()
            val h = maxOf((w * 1.2f).toInt(), list.minIntrinsicHeight(w))
            val left = list.measure(Constraints.fixed(w, h))
            val divider = subcompose("line") { Box(Modifier.fillMaxSize().background(Ownify.GlassHairline)) }.first()
                .measure(Constraints.fixed(line, h))
            val right = perDay?.let { chart ->
                subcompose("chart") {
                    AreaChartMini(chart, Accent.TRAINING, with(density) { h.toDp() }, bare = true) { shell.openDetail(Detail.AreaChart("training", chart.id)) }
                }.first().measure(Constraints.fixed(w, h))
            }
            layout(constraints.maxWidth, h) {
                left.place(0, 0)
                divider.place(w, 0)
                right?.place(w + line, 0)
            }
        }
    }
}

/** `components/training-sessions.php`: each session its kind, its day and time, and how long — opening its page. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun SessionsCard(sessions: TrainingSessions, modifier: Modifier) {
    val shell = LocalShell.current
    Column(modifier.fillMaxWidth().padding(Ownify.Space4)) {
        T(
            sessions.title,
            OwnifyType.style(Ownify.FsSmall, FontWeight.SemiBold, Ownify.TextSecondary, tracking = (-0.01).em, lineHeight = 1.25.em),
            Modifier.semantics { heading() }
        )
        if (sessions.items.isEmpty()) {
            Box(Modifier.fillMaxWidth().weight(1f, fill = false).padding(vertical = Ownify.Space5), contentAlignment = Alignment.Center) {
                T(sessions.empty, JStyle.Tiny, align = TextAlign.Center)
            }
        } else {
            Column(Modifier.fillMaxWidth().padding(top = Ownify.Space2)) {
                sessions.items.forEachIndexed { i, item ->
                    if (i > 0) Box(Modifier.fillMaxWidth().height(1.dp).background(Ownify.GlassHairline))
                    val interaction = remember { MutableInteractionSource() }
                    InButton {
                        Row(
                            Modifier
                                .fillMaxWidth()
                                .press(interaction)
                                .clickable(interaction, indication = null, role = Role.Button, onClickLabel = item.open) {
                                    shell.openDetail(Detail.TrainingSession(item.id))
                                }
                                .semantics { contentDescription = item.open }
                                .padding(vertical = 7.dp),
                            verticalAlignment = Alignment.CenterVertically
                        ) {
                            // A short bar in the Training colour marks each as one.
                            Box(Modifier.width(3.dp).height(30.dp).clip(RoundedCornerShape(2.dp)).background(Ownify.Training))
                            Column(Modifier.weight(1f).padding(start = 7.dp), verticalArrangement = Arrangement.spacedBy(1.dp)) {
                                T(
                                    item.label,
                                    OwnifyType.style(Ownify.FsSmall, FontWeight.Bold, tracking = (-0.01).em, lineHeight = 1.25.em),
                                    maxLines = 1,
                                    ellipsis = true
                                )
                                // On the narrowest phones the duration goes under the day (`.training-session__when`).
                                FlowRow(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                                    T("${item.date} · ${item.time}", OwnifyType.style(Ownify.FsTiny, color = Ownify.TextMuted, lineHeight = 1.3.em, tabular = true), Modifier.padding(end = Ownify.Space2), maxLines = 1)
                                    item.duration?.let {
                                        T(it, OwnifyType.style(Ownify.FsTiny, FontWeight.SemiBold, Ownify.TextSecondary, lineHeight = 1.3.em, tabular = true), maxLines = 1)
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

// ---------------------------------------------------------------------------
// The heart rate
// ---------------------------------------------------------------------------

/**
 * `components/heart-chart.php`: Vandaag — a whole day, the day's name
 * between an arrow back and an arrow on, a swipe over the name or a quick
 * one over the chart to an older or newer day, the last seven — or a period
 * of daily averages; the zones named under it.
 */
@Composable
private fun HeartCard(heart: HeartChart) {
    var selected by rememberSaveable { mutableStateOf(heart.defaultKey) }
    var day by rememberSaveable { mutableIntStateOf(0) }
    val last = heart.days.lastIndex
    val dayMode = selected == "d0"
    val plot = if (dayMode) heart.days[day.coerceIn(0, last)] else heart.periods.firstOrNull { it.key == selected } ?: heart.days.first()
    val filled = heart.days.any { it.hasData } || heart.periods.any { it.hasData }
    val step = { by: Int -> day = (day + by).coerceIn(0, last) }

    JCard(Modifier.fillMaxWidth().reveal()) {
        HeartHead(heart.title)

        // Five options, closer together than four (`.heart-chart__switch`).
        RangeSwitch(
            heart.options,
            selected,
            { key ->
                selected = key
                if (key == "d0") day = 0                    // Vandaag again: today
            },
            Modifier.padding(top = Ownify.Space4),
            wide = true,
            optionPadding = 4.dp,
            label = heart.switchLabel
        )

        if (dayMode) DayRow(plot.title.orEmpty(), heart, day, last, step)

        Box(
            Modifier
                .fillMaxWidth()
                .padding(top = if (dayMode) Ownify.Space3 else Ownify.Space4)
                .then(if (dayMode) Modifier.flicks { by -> step(by) } else Modifier)
        ) {
            HeartPlotView(plot, heart.label, plot.empty ?: heart.empty)
        }

        HeartZonesLegend(heart.zones)
        if (filled) {
            T(heart.hint, JStyle.Tiny, Modifier.fillMaxWidth().padding(top = Ownify.Space3), align = TextAlign.Center)
        }
    }
}

/** `.card__head`: the Training-coloured tile with the pulse, and the title. */
@Composable
private fun HeartHead(title: String) {
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)) {
        IconTile(OwnifyIcons.solidPulse, color = Color.White, background = Ownify.Training, border = Ownify.Training)
        T(title, JStyle.Eyebrow, Modifier.semantics { heading() })
    }
}

/** `.heart-chart__day`: ‹ older, the day's name, newer › — and a swipe over it. */
@Composable
private fun DayRow(title: String, heart: HeartChart, day: Int, last: Int, step: (Int) -> Unit) {
    val owned = LocalOwnedAreas.current
    val ownKey = remember { Any() }
    DisposableEffect(ownKey) { onDispose { owned.set(ownKey, null) } }
    val swipe = with(LocalDensity.current) { SwipeDistance.toPx() }

    Row(
        Modifier
            .fillMaxWidth()
            .padding(top = Ownify.Space4)
            .ownsGestures(owned, ownKey)
            .pointerInput(day) {
                awaitEachGesture {
                    val down = awaitFirstDown(requireUnconsumed = false)
                    var end = down.position
                    while (true) {
                        val change = awaitPointerEvent().changes.firstOrNull { it.id == down.id } ?: break
                        end = change.position
                        if (!change.pressed) break
                    }
                    val dx = end.x - down.position.x
                    val dy = end.y - down.position.y
                    if (abs(dx) >= swipe && abs(dx) > 1.5f * abs(dy)) step(if (dx > 0) 1 else -1)
                }
            },
        verticalAlignment = Alignment.CenterVertically
    ) {
        StepButton(OwnifyIcons.chevronLeft, heart.prev, enabled = day < last) { step(1) }
        T(
            title,
            OwnifyType.style(Ownify.FsBody, FontWeight.Bold, tracking = (-0.01).em),
            Modifier.weight(1f).semantics { liveRegion = LiveRegionMode.Polite },
            align = TextAlign.Center,
            maxLines = 1
        )
        StepButton(OwnifyIcons.chevronRight, heart.next, enabled = day > 0) { step(-1) }
    }
}

/** `.heart-chart__step`: a round arrow, dimmed at either end. */
@Composable
private fun StepButton(icon: androidx.compose.ui.graphics.vector.ImageVector, label: String, enabled: Boolean, onClick: () -> Unit) {
    val interaction = remember { MutableInteractionSource() }
    InButton {
        Box(
            Modifier
                .size(34.dp)
                .alpha(if (enabled) 1f else 0.35f)
                .clip(CircleShape)
                .background(Ownify.fill(0.04f))
                .press(interaction, enabled = enabled)
                .clickable(interaction, indication = null, enabled = enabled, role = Role.Button, onClickLabel = label, onClick = onClick)
                .semantics { contentDescription = label },
            contentAlignment = Alignment.Center
        ) {
            JIcon(icon, size = 16.dp, color = Ownify.TextSecondary)
        }
    }
}

/**
 * A quick sideways swipe over the chart (training.js): to the right an older
 * day, to the left a newer one. Only watched — never consumed — so a slow
 * slide is still the chart's reading.
 */
private fun Modifier.flicks(step: (Int) -> Unit): Modifier = pointerInput(Unit) {
    awaitEachGesture {
        val down = awaitFirstDown(requireUnconsumed = false, pass = PointerEventPass.Initial)
        var end = down.position
        var lifted = down.uptimeMillis
        while (true) {
            val change = awaitPointerEvent(PointerEventPass.Initial).changes.firstOrNull { it.id == down.id } ?: return@awaitEachGesture
            end = change.position
            lifted = change.uptimeMillis
            if (!change.pressed) break
        }
        val dx = end.x - down.position.x
        val dy = end.y - down.position.y
        if (lifted - down.uptimeMillis <= FLICK_MS && abs(dx) >= SwipeDistance.toPx() && abs(dx) > 1.5f * abs(dy)) {
            step(if (dx > 0) 1 else -1)
        }
    }
}

/**
 * `components/heart-plot.php`: a day, a period or a session — the line in
 * each zone's colour where it runs through it, zones 2 to 4 a faint band
 * behind it, a dot on every point of a period and only where a day's point
 * stands alone, and the reading: time or date, the zone, the bpm.
 */
@Composable
private fun HeartPlotView(plot: HeartPlot, label: String, empty: String) {
    val zy = plot.zonesY
    val bands = if (zy == null || !plot.hasData) emptyList() else (2..4).mapNotNull { z ->
        val bottom = zy[z - 2]
        val top = if (z == 4) 0f else zy[z - 1]
        if (bottom.coerceIn(0f, 100f) - top.coerceIn(0f, 100f) <= 0.05f) null
        else PlotBand(top, bottom, Ownify.zone(z).copy(alpha = when (z) { 2 -> 0.05f; 3 -> 0.07f; else -> 0.09f }))
    }
    val dotColor: (Int) -> Color = { i -> plot.zone.getOrNull(i)?.let { Ownify.zone(it) } ?: Ownify.Training }
    // On the narrowest phones a day's hours every six, still evenly spaced: eight times "00:00" does not fit.
    val axis = if (plot.group == "minutes" && LocalScreen.current.width < 380.dp) plot.axis.filterIndexed { i, _ -> i % 2 == 0 } else plot.axis

    HistoryPlot(
        key = "heart:" + plot.key,
        viewBox = Size(plot.width, plot.height),
        xs = plot.x,
        lines = listOf(PlotLine(Ownify.Training, plot.line, emptyList(), plot.y, zy?.let(::zoneStops), dotColor, plot.lone)),
        filled = plot.hasData,
        dayDots = true,
        small = plot.x.size > 7,
        carried = { false },
        axis = axis,
        centredAxis = true,
        aria = plot.aria,
        readable = { i -> plot.points.getOrNull(i) != null },
        describe = { i ->
            plot.points.getOrNull(i)?.let { p -> p.label + (p.detail?.let { ", $it" } ?: "") + ": " + (p.texts.firstOrNull() ?: p.note ?: "—") }.orEmpty()
        },
        empty = empty,
        modifier = Modifier,
        onRead = { },
        height = 160.dp,
        levels = plot.grid,
        gutter = if (plot.grid.isNotEmpty()) 26.dp else 0.dp,
        bands = bands
    ) { i ->
        // `.health-history__tip`: "14:35 · Zone 3", then Hartslag and its bpm.
        val p = plot.points[i]
        val value = p.texts.firstOrNull()
        Row {
            T(p.label + if (p.detail != null && value != null) " · " else "", OwnifyType.style(Ownify.FsTiny, color = Ownify.TextMuted, lineHeight = 1.25.em), maxLines = 1)
            if (value != null) p.detail?.let { T(it, OwnifyType.style(Ownify.FsTiny, color = Ownify.TextSecondary, lineHeight = 1.25.em), maxLines = 1) }
        }
        if (value == null) {
            p.note?.let { T(it, OwnifyType.style(Ownify.FsTiny, color = Ownify.TextMuted, lineHeight = 1.4.em), maxLines = 1) }
        } else {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.size(width = 10.dp, height = 2.5.dp).clip(RoundedCornerShape(2.dp)).background(dotColor(i)))
                T(label, OwnifyType.style(Ownify.FsTiny, color = Ownify.TextSecondary, lineHeight = 1.4.em), Modifier.padding(start = 6.dp), maxLines = 1)
                Spacer(Modifier.width(8.dp))
                T(value, OwnifyType.style(Ownify.FsSmall, FontWeight.Bold, lineHeight = 1.25.em, tabular = true), maxLines = 1)
            }
        }
    }
}

/**
 * The line's colours from the plot's top (0) to its bottom (1): zone 4 down
 * to where it begins, then 3, 2 and 1 — hard edges, as the website's
 * gradient has them. [zonesY]: where zones 2, 3 and 4 begin, % from the top.
 */
internal fun zoneStops(zonesY: List<Float>): List<Pair<Float, Color>> {
    val e = zonesY.map { (it / 100f).coerceIn(0f, 1f) }      // zone 2, 3, 4's lower edges
    val z4 = e.getOrElse(2) { 0f }
    val z3 = e.getOrElse(1) { 0f }.coerceAtLeast(z4)
    val z2 = e.getOrElse(0) { 0f }.coerceAtLeast(z3)
    return listOf(
        0f to Ownify.zone(4), z4 to Ownify.zone(4),
        z4 to Ownify.zone(3), z3 to Ownify.zone(3),
        z3 to Ownify.zone(2), z2 to Ownify.zone(2),
        z2 to Ownify.zone(1), 1f to Ownify.zone(1)
    )
}

/** `components/heart-zones.php`: each zone's colour, name and range — never colour alone — and what they are based on. */
@Composable
private fun HeartZonesLegend(zones: HeartZones) {
    Column(Modifier.fillMaxWidth().padding(top = Ownify.Space4)) {
        zones.bands.chunked(2).forEachIndexed { r, pair ->
            Row(Modifier.fillMaxWidth().padding(top = if (r > 0) Ownify.Space2 else 0.dp), horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)) {
                pair.forEachIndexed { k, (name, range) ->
                    Row(Modifier.weight(1f), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        Box(Modifier.size(10.dp).clip(RoundedCornerShape(3.dp)).background(Ownify.zone(r * 2 + k + 1)))
                        T(name, OwnifyType.style(Ownify.FsTiny, FontWeight.SemiBold, Ownify.TextSecondary), maxLines = 1)
                        T(range, OwnifyType.style(Ownify.FsTiny, color = Ownify.TextMuted, tabular = true), maxLines = 1)
                    }
                }
                if (pair.size == 1) Spacer(Modifier.weight(1f))
            }
        }
        T(zones.note, OwnifyType.style(Ownify.FsTiny, color = Ownify.TextMuted, lineHeight = 1.4.em), Modifier.padding(top = Ownify.Space2))
    }
}

// ---------------------------------------------------------------------------
// A session's own page
// ---------------------------------------------------------------------------

/**
 * `pages/training-session.php`: its kind, day and times; its heart rate
 * minute by minute with its zones; and every figure recorded for it. Opened
 * over Training; back goes to Training.
 */
@Composable
fun TrainingSessionDetail(session: TrainingSession, heart: HeartChart, scroll: ScrollState) {
    CompositionLocalProvider(LocalAccent provides Accent.TRAINING) {
        DetailColumn(scroll, back = session.back, backAria = "Terug naar ${session.back}") {
            PageIntro(session.title, "${session.date} · ${session.time}", Modifier.reveal())
            JCard(Modifier.fillMaxWidth().reveal()) {
                HeartHead(session.heartTitle)
                Box(Modifier.fillMaxWidth().padding(top = Ownify.Space4)) {
                    HeartPlotView(session.heart, heart.label, session.heart.empty ?: heart.empty)
                }
                if (session.heart.hasData) {
                    HeartZonesLegend(heart.zones)
                    T(heart.hint, JStyle.Tiny, Modifier.fillMaxWidth().padding(top = Ownify.Space3), align = TextAlign.Center)
                }
            }
            if (session.stats.isNotEmpty()) {
                MetricTiles(session.stats.map { Metric(it.key, it.label, it.unit, it.value, null) }, title = session.statsTitle, count = false)
            }
        }
    }
}
