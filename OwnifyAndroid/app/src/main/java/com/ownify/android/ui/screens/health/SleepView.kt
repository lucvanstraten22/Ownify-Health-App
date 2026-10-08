package com.ownify.android.ui.screens.health

import androidx.compose.foundation.ScrollState
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.gestures.awaitEachGesture
import androidx.compose.foundation.gestures.awaitFirstDown
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.drawBehind
import androidx.compose.ui.geometry.CornerRadius
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.input.pointer.PointerEventPass
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.layout.SubcomposeLayout
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.semantics.CustomAccessibilityAction
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.customActions
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.stateDescription
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.Hyphens
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.Constraints
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.em
import androidx.compose.ui.unit.sp
import com.ownify.android.data.SleepChart
import com.ownify.android.data.SleepNight
import com.ownify.android.data.SleepPeriod
import com.ownify.android.data.SleepSeries
import com.ownify.android.data.SleepView
import com.ownify.android.ui.app.Detail
import com.ownify.android.ui.app.DetailColumn
import com.ownify.android.ui.app.LocalOwnedAreas
import com.ownify.android.ui.app.LocalShell
import com.ownify.android.ui.app.ownsGestures
import com.ownify.android.ui.design.JCard
import com.ownify.android.ui.design.JIcon
import com.ownify.android.ui.design.JStyle
import com.ownify.android.ui.design.Legend
import com.ownify.android.ui.design.LegendItem
import com.ownify.android.ui.design.OwnifyIcons
import com.ownify.android.ui.design.PageIntro
import com.ownify.android.ui.design.PlotBars
import com.ownify.android.ui.design.PlotLine
import com.ownify.android.ui.design.HistoryPlot
import com.ownify.android.ui.design.RangeSwitch
import com.ownify.android.ui.design.ReadingTip
import com.ownify.android.ui.design.T
import com.ownify.android.ui.design.TickAxis
import com.ownify.android.ui.design.press
import com.ownify.android.ui.design.reveal
import com.ownify.android.ui.theme.Accent
import com.ownify.android.ui.theme.InButton
import com.ownify.android.ui.theme.Ownify
import com.ownify.android.ui.theme.OwnifyType
import kotlin.math.abs
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/*
 * Slaap, drawn (docs/SLEEP.md) — the website's components/sleep-night.php,
 * components/sleep-chart.php and pages/sleep-metric.php: the last night's
 * stages as a timeline, four charts over time two by two, and each chart on
 * a page of its own. Everything shown is the server's (lib/hydrate-sleep.php).
 */

/** How long a touch reading stays after the finger lifts (sleep.js LINGER). */
private const val NIGHT_LINGER_MS = 1600L

/** A row of the night (`--row-h`) and the names' column (`--label-w`). */
private val RowHeight = 34.dp
private val LabelWidth = 96.dp

/**
 * The stages, calm and told apart (sleep.css): sleep in the Slaap colour —
 * deep the full colour, REM its lighter shade, light sleep half of it — and
 * being awake in the text colour, Wakker the brighter of the two.
 */
private fun stageColor(key: String): Color = when (key) {
    "deep" -> Ownify.Sleep
    "rem" -> Ownify.SleepLight
    "light" -> Ownify.Sleep.copy(alpha = 0.52f)
    "restless" -> Ownify.ink(0.30f)
    "awake" -> Ownify.ink(0.58f)
    else -> Ownify.ink(0.3f)
}

/** Tijd in bed's bars; a line alone in the Slaap colour, beside bars in its lighter shade. */
private val BarColor: Color get() = Ownify.Sleep.copy(alpha = 0.58f)

private fun seriesColor(series: SleepSeries, mixed: Boolean): Color = when {
    series.kind == "bars" -> BarColor
    mixed -> Ownify.SleepLight
    else -> Ownify.Sleep
}

// ---------------------------------------------------------------------------
// The night
// ---------------------------------------------------------------------------

/**
 * `components/sleep-night.php`: the head — the night's date, how long was
 * slept and how much of the time in bed — then Wakker, Rusteloosheid, REM,
 * Licht and Diep as rows, each named with its time over the night, and each
 * recorded period of a stage a block on its row from bedtime at the left to
 * wake time at the right. A finger on the night reads the period there: its
 * stage, and when it began and ended.
 */
@Composable
fun SleepNightCard(night: SleepNight) {
    JCard(Modifier.fillMaxWidth().reveal()) {
        Row(Modifier.fillMaxWidth().padding(bottom = Ownify.Space5), horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)) {
            Column(Modifier.weight(1f)) {
                T(night.title, JStyle.Eyebrow, Modifier.semantics { heading() })
                night.date?.let { T(it, JStyle.Tiny, Modifier.padding(top = Ownify.Space1)) }
            }
            night.asleep?.let { asleep ->
                Column(horizontalAlignment = Alignment.End) {
                    Row {
                        T(asleep, OwnifyType.style(20.sp, FontWeight.Bold, tracking = (-0.03).em, lineHeight = 1.1.em, tabular = true), Modifier.alignByBaseline())
                        T("u", OwnifyType.style(Ownify.FsSmall, FontWeight.Medium, Ownify.TextMuted, lineHeight = 1.1.em), Modifier.alignByBaseline().padding(start = 3.dp))
                    }
                    T(
                        night.asleepLabel + (night.efficiency?.let { " · $it% ${night.efficiencyLabel}" } ?: ""),
                        OwnifyType.style(Ownify.FsTiny, color = Ownify.TextMuted),
                        Modifier.padding(top = 2.dp),
                        maxLines = 1
                    )
                }
            }
        }

        Row(Modifier.fillMaxWidth()) {
            // `.sleep-night__labels`: each stage's name and time, its colour beside it.
            Column(Modifier.width(LabelWidth)) {
                night.rows.forEach { row ->
                    Row(Modifier.height(RowHeight).fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                        Box(Modifier.size(6.dp).clip(RoundedCornerShape(2.dp)).background(stageColor(row.key)))
                        Column(Modifier.padding(start = 6.dp)) {
                            T(row.label, OwnifyType.style(Ownify.FsTiny, FontWeight.SemiBold, Ownify.TextSecondary, lineHeight = 1.25.em), maxLines = 1, ellipsis = true)
                            row.total?.let { T(it, OwnifyType.style(Ownify.FsTiny, color = Ownify.TextMuted, lineHeight = 1.25.em, tabular = true), maxLines = 1) }
                        }
                    }
                }
            }
            NightPlot(night, Modifier.padding(start = Ownify.Space3).weight(1f))
        }

        if (night.ticks.isNotEmpty()) {
            // Bedtime at the left edge, wake time at the right, hours between.
            TickAxis(night.ticks, Modifier.padding(start = LabelWidth + Ownify.Space3, top = Ownify.Space2))
        }
        night.note?.let {
            T(it, OwnifyType.style(Ownify.FsSmall, color = Ownify.TextMuted), Modifier.fillMaxWidth().padding(top = Ownify.Space4), align = TextAlign.Center)
        }
        if (night.staged) {
            T(night.hint, JStyle.Tiny, Modifier.fillMaxWidth().padding(top = Ownify.Space3), align = TextAlign.Center)
        }
    }
}

/** The night's rows and blocks, read with a finger (sleep.js), or by TalkBack period by period. */
@Composable
private fun NightPlot(night: SleepNight, modifier: Modifier) {
    val owned = LocalOwnedAreas.current
    val ownKey = remember { Any() }
    val scope = rememberCoroutineScope()
    var reading by remember(night) { mutableIntStateOf(-1) }
    var linger by remember { mutableStateOf<Job?>(null) }
    val blocks = night.blocks
    val rows = night.rows.size

    DisposableEffect(ownKey) { onDispose { owned.set(ownKey, null) } }

    fun show(index: Int) {
        linger?.cancel()
        reading = index.coerceIn(0, blocks.lastIndex)
    }

    fun hide() {
        linger?.cancel()
        reading = -1
    }

    /** The period at a place on the night, in %: the one there, or the nearest. */
    fun at(percent: Float): Int {
        var best = 0
        var gap = Float.MAX_VALUE
        blocks.forEachIndexed { i, b ->
            val d = if (percent < b.from) b.from - percent else if (percent > b.to) percent - b.to else 0f
            if (d < gap) {
                gap = d
                best = i
            }
        }
        return best
    }

    fun spoken(i: Int) = blocks.getOrNull(i)?.let { b -> "${night.rows.getOrNull(b.row)?.label.orEmpty()}, ${b.began} tot ${b.ended}" }.orEmpty()

    val dim = reading >= 0
    Box(
        modifier
            .height(RowHeight * rows)
            .then(
                if (!night.staged) Modifier.clearAndSetSemantics { contentDescription = night.aria }
                else Modifier
                    .ownsGestures(owned, ownKey)
                    .semantics {
                        contentDescription = night.aria
                        stateDescription = if (reading >= 0) spoken(reading) else ""
                        liveRegion = LiveRegionMode.Polite
                        customActions = listOf(
                            CustomAccessibilityAction("Volgende fase") { show(if (reading < 0) 0 else minOf(blocks.lastIndex, reading + 1)); true },
                            CustomAccessibilityAction("Vorige fase") { show(if (reading < 0) 0 else maxOf(0, reading - 1)); true },
                            CustomAccessibilityAction("Fase verbergen") { hide(); true }
                        )
                    }
                    .pointerInput(blocks) {
                        awaitEachGesture {
                            val down = awaitFirstDown(requireUnconsumed = false, pass = PointerEventPass.Final)
                            if (size.width > 0) show(at(down.position.x / size.width * 100f))
                            while (true) {
                                val change = awaitPointerEvent(PointerEventPass.Final).changes.firstOrNull { it.id == down.id }
                                if (change == null) {
                                    hide()
                                    break
                                }
                                if (!change.pressed) {
                                    linger?.cancel()
                                    linger = scope.launch {
                                        delay(NIGHT_LINGER_MS)
                                        reading = -1
                                    }
                                    break
                                }
                                if (change.isConsumed) {
                                    hide()
                                    break
                                }
                                if (size.width > 0) show(at(change.position.x / size.width * 100f))
                            }
                        }
                    }
            )
            .drawBehind {
                val row = RowHeight.toPx()
                val inset = 6.dp.toPx()
                // Each row a quiet lane, so an empty stretch reads as time, not as nothing.
                for (r in 0 until rows) {
                    drawRoundRect(Ownify.fill(0.035f), Offset(0f, r * row + inset), Size(size.width, row - 2 * inset), CornerRadius(6.dp.toPx()))
                }
                // Each period on its row — never thinner than a line, so a minute awake still shows.
                blocks.forEachIndexed { i, b ->
                    val key = night.rows.getOrNull(b.row)?.key.orEmpty()
                    val left = b.from / 100f * size.width
                    val width = maxOf(2.dp.toPx(), (b.to - b.from) / 100f * size.width)
                    val top = b.row * row + inset
                    val color = stageColor(key)
                    val read = i == reading
                    if (read) {
                        drawRoundRect(Ownify.BgSecondary, Offset(left - 2.dp.toPx(), top - 2.dp.toPx()), Size(width + 4.dp.toPx(), row - 2 * inset + 4.dp.toPx()), CornerRadius(6.dp.toPx()))
                        drawRoundRect(color, Offset(left - 3.5.dp.toPx(), top - 3.5.dp.toPx()), Size(width + 7.dp.toPx(), row - 2 * inset + 7.dp.toPx()), CornerRadius(7.dp.toPx()), style = Stroke(1.5.dp.toPx()))
                    }
                    drawRoundRect(color.copy(alpha = color.alpha * if (dim && !read) 0.38f else 1f), Offset(left, top), Size(width, row - 2 * inset), CornerRadius(4.dp.toPx()))
                }
            }
    ) {
        blocks.getOrNull(reading)?.let { b ->
            ReadingTip((b.from + b.to) / 2f) {
                T(night.rows.getOrNull(b.row)?.label.orEmpty(), OwnifyType.style(Ownify.FsSmall, FontWeight.Bold, lineHeight = 1.25.em), maxLines = 1)
                T("${b.began} – ${b.ended}", OwnifyType.style(Ownify.FsTiny, color = Ownify.TextMuted, lineHeight = 1.25.em, tabular = true), maxLines = 1)
            }
        }
    }
}

// ---------------------------------------------------------------------------
// The four charts, two by two
// ---------------------------------------------------------------------------

/** The small chart's room under its head: 12 above the plot, the plot at least 48, the dates' two rows and the 4 above them. */
private val MiniPlotMin = 48.dp

/**
 * `.sleep-charts`: two by two, each card square — as wide as half the row —
 * unless a long name needs a line more; then both cards of that row grow to
 * the taller, as the website's grid rows do.
 */
@Composable
fun SleepChartsGrid(view: SleepView) {
    val shell = LocalShell.current
    val density = LocalDensity.current
    SubcomposeLayout(Modifier.fillMaxWidth()) { constraints ->
        val gap = Ownify.Space3.roundToPx()
        val w = (constraints.maxWidth - gap) / 2
        val pad = Ownify.Space4.roundToPx()
        val axis = with(density) { (Ownify.FsTiny.toPx() * 2.5f) }.toInt() + Ownify.Space1.roundToPx()
        val rest = 2 * pad + 2 + Ownify.Space3.roundToPx() + MiniPlotMin.roundToPx() + axis
        val heads = view.charts.mapIndexed { i, chart ->
            subcompose("head$i") { MiniHead(chart) }.firstOrNull()
                ?.measure(Constraints(maxWidth = (w - 2 * pad - 2).coerceAtLeast(0)))?.height ?: 0
        }
        val placed = ArrayList<Triple<androidx.compose.ui.layout.Placeable, Int, Int>>()
        var y = 0
        view.charts.chunked(2).forEachIndexed { r, row ->
            val h = maxOf(w, row.indices.maxOf { heads[r * 2 + it] } + rest)
            row.forEachIndexed { k, chart ->
                val i = r * 2 + k
                val card = subcompose("card$i") {
                    SleepChartMini(chart, with(density) { h.toDp() }) { shell.openDetail(Detail.SleepChart(chart.id)) }
                }.first().measure(Constraints.fixed(w, h))
                placed += Triple(card, k * (w + gap), y)
            }
            y += h + gap
        }
        layout(constraints.maxWidth, (y - gap).coerceAtLeast(0)) {
            placed.forEach { (p, x, top) -> p.place(x, top) }
        }
    }
}

/** The small card's head: its name — a chevron at its end — and the value its week ends on. */
@Composable
private fun MiniHead(chart: SleepChart) {
    Column(Modifier.fillMaxWidth()) {
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Ownify.Space1)) {
            T(
                chart.title,
                OwnifyType.style(Ownify.FsSmall, FontWeight.SemiBold, Ownify.TextSecondary, tracking = (-0.01).em, lineHeight = 1.25.em)
                    .copy(hyphens = Hyphens.Auto),
                Modifier.weight(1f)
            )
            JIcon(OwnifyIcons.chevronRight, Modifier.padding(top = 1.dp), size = 14.dp, color = Ownify.TextMuted)
        }
        Row(Modifier.padding(top = 2.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            val latest = chart.latest
            val valueStyle = OwnifyType.style(17.sp, FontWeight.Bold, tracking = (-0.02).em, lineHeight = 1.25.em, tabular = true)
            if (latest == null || latest.texts.all { it == null }) {
                T("—", valueStyle.copy(color = Ownify.TextMuted, fontWeight = FontWeight.SemiBold))
            } else {
                chart.series.forEachIndexed { k, s ->
                    val text = latest.texts.getOrNull(k) ?: return@forEachIndexed
                    if (chart.series.size > 1) {
                        val line = s.kind != "bars"
                        Box(
                            Modifier
                                .size(width = if (line) 10.dp else 8.dp, height = if (line) 2.5.dp else 8.dp)
                                .clip(RoundedCornerShape(2.dp))
                                .background(seriesColor(s, chart.mixed))
                        )
                    }
                    T(text, valueStyle, maxLines = 1)
                }
                latest.date?.let { T(it, OwnifyType.style(Ownify.FsTiny, color = Ownify.TextMuted), maxLines = 1) }
            }
        }
    }
}

/**
 * `.sleep-chart--mini`: the name and latest value, then the week on Ownify's
 * time axis, its dates in two rows. A tap opens its page; a finger moved
 * sideways reads a day first, as the large chart is read.
 */
@Composable
private fun SleepChartMini(chart: SleepChart, height: Dp, onOpen: () -> Unit) {
    val week = chart.periods.first()
    val interaction = remember { MutableInteractionSource() }
    InButton {
        JCard(
            Modifier
                .fillMaxWidth()
                .height(height)
                .reveal()
                .press(interaction, scale = 0.985f)
                .clickable(interaction, indication = null, role = Role.Button, onClickLabel = chart.open, onClick = onOpen)
                .semantics { contentDescription = chart.open },
            padding = androidx.compose.foundation.layout.PaddingValues(Ownify.Space4)
        ) {
            MiniHead(chart)
            BoxWithConstraints(Modifier.fillMaxWidth().weight(1f).padding(top = Ownify.Space3)) {
                val axis = with(LocalDensity.current) { (Ownify.FsTiny.toPx() * 2.5f).toDp() } + Ownify.Space1
                SleepPlot(chart, week, Modifier, height = (maxHeight - axis).coerceAtLeast(MiniPlotMin), mini = true)
            }
        }
    }
}

/** A period of a chart, drawn and read as the Verloop is ([HistoryPlot]), its bars under its line. */
@Composable
private fun SleepPlot(chart: SleepChart, period: SleepPeriod, modifier: Modifier, height: Dp, mini: Boolean) {
    val mixed = chart.mixed
    val lineSeries = period.lines.mapNotNull { line -> chart.series.firstOrNull { it.key == line.key }?.let { it to line } }
    // A line's levels named on the large chart; otherwise none — the baseline alone, as on the website.
    val levels = if (mini) emptyList() else period.grid
    HistoryPlot(
        key = chart.id + period.key + if (mini) ":mini" else "",
        viewBox = Size(period.width, period.height),
        xs = period.x,
        lines = lineSeries.map { (s, line) -> PlotLine(seriesColor(s, mixed), line.line, emptyList(), line.y) },
        filled = period.hasData,
        dayDots = true,
        small = period.group == "day" && period.x.size > 7,
        carried = { i -> i in period.carried },
        axis = if (mini) period.axisRows ?: period.axis else period.axis,
        centredAxis = true,
        aria = period.aria,
        readable = { i -> period.points.getOrNull(i) != null },
        describe = { i ->
            period.points.getOrNull(i)?.let { p ->
                val said = chart.series.mapIndexedNotNull { k, s -> p.texts.getOrNull(k)?.let { "${s.label} $it" } }
                p.label + (p.detail?.let { ", $it" } ?: "") + ": " + if (said.isEmpty()) p.note ?: "—" else said.joinToString(", ") + (p.note?.let { ". $it" } ?: "")
            }.orEmpty()
        },
        empty = chart.empty,
        modifier = modifier,
        onRead = { },
        height = height,
        levels = levels,
        gutter = if (levels.isNotEmpty()) 26.dp else 0.dp,
        bars = period.bars.map { PlotBars(BarColor, it.w, it.top) },
        readOnDrag = mini,
        compact = mini
    ) { i ->
        // `.health-history__tip`: the date or days — a week's or month's, that it is their mean — then each value.
        val p = period.points[i]
        val values = chart.series.mapIndexedNotNull { k, s -> p.texts.getOrNull(k)?.let { s to it } }
        val detail = p.detail?.takeIf { values.isNotEmpty() }
        Row {
            T(p.label + if (detail != null) " · " else "", OwnifyType.style(Ownify.FsTiny, color = Ownify.TextMuted, lineHeight = 1.25.em), maxLines = 1)
            detail?.let { T(it, OwnifyType.style(Ownify.FsTiny, color = Ownify.TextSecondary, lineHeight = 1.25.em), maxLines = 1) }
        }
        if (values.isEmpty()) {
            p.note?.let { T(it, OwnifyType.style(Ownify.FsTiny, color = Ownify.TextMuted, lineHeight = 1.4.em), maxLines = 1) }
        } else if (chart.series.size == 1) {
            T(values.first().second, OwnifyType.style(Ownify.FsSmall, FontWeight.Bold, lineHeight = 1.25.em, tabular = true), maxLines = 1)
        } else {
            for ((s, text) in values) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    val line = s.kind != "bars"
                    Box(
                        Modifier
                            .size(width = if (line) 10.dp else 8.dp, height = if (line) 2.5.dp else 8.dp)
                            .clip(RoundedCornerShape(2.dp))
                            .background(seriesColor(s, mixed))
                    )
                    T(s.label, OwnifyType.style(Ownify.FsTiny, color = Ownify.TextSecondary, lineHeight = 1.4.em), Modifier.padding(start = 6.dp), maxLines = 1)
                    Spacer(Modifier.width(8.dp))
                    T(text, OwnifyType.style(Ownify.FsSmall, FontWeight.Bold, lineHeight = 1.25.em, tabular = true), maxLines = 1)
                }
            }
        }
    }
}

// ---------------------------------------------------------------------------
// A chart on its own page
// ---------------------------------------------------------------------------

/**
 * `pages/sleep-metric.php`: the chart's name and the chart, large — its
 * switch over 7 dagen, 30 dagen, 90 dagen and 1 jaar, the plot 160 dp tall
 * with a line's levels named, two series' legend, and the hint. Opened over
 * Slaap; back goes to Slaap.
 */
@Composable
fun SleepChartDetail(chart: SleepChart, scroll: ScrollState) {
    DetailColumn(scroll, back = chart.back, backAria = "Terug naar ${chart.back}") {
        PageIntro(chart.title, null, Modifier.reveal())
        SleepChartCard(chart)
    }
}

@Composable
private fun SleepChartCard(chart: SleepChart) {
    var selected by rememberSaveable(chart.id) { mutableStateOf(chart.defaultPeriod) }
    val period = chart.periods.firstOrNull { it.key == selected } ?: chart.periods.first()
    val filled = chart.periods.any { it.hasData }

    JCard(Modifier.fillMaxWidth().reveal()) {
        RangeSwitch(
            chart.periods.map { it.key to it.label },
            period.key,
            { selected = it },
            Modifier.fillMaxWidth(),
            wide = true,
            label = chart.switchLabel
        )
        Box(Modifier.fillMaxWidth().padding(top = Ownify.Space4)) {
            SleepPlot(chart, period, Modifier, height = 160.dp, mini = false)
        }
        if (chart.series.size > 1) {
            Legend(
                chart.series.map { LegendItem(it.label, null, Accent.SLEEP, dot = seriesColor(it, chart.mixed)) },
                Modifier.padding(top = Ownify.Space4),
                values = false
            )
        }
        if (filled) {
            T(chart.hint, JStyle.Tiny, Modifier.fillMaxWidth().padding(top = Ownify.Space3), align = TextAlign.Center)
        }
    }
}
