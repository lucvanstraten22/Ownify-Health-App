package com.ownify.android.ui.screens.health

import androidx.compose.foundation.ScrollState
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.runtime.Composable
import androidx.compose.runtime.setValue
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.SubcomposeLayout
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.Hyphens
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.Constraints
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.em
import androidx.compose.ui.unit.sp
import com.ownify.android.data.AreaChart
import com.ownify.android.data.AreaPeriod
import com.ownify.android.data.AreaSeries
import com.ownify.android.ui.app.Detail
import com.ownify.android.ui.app.DetailColumn
import com.ownify.android.ui.app.LocalShell
import com.ownify.android.ui.design.HistoryPlot
import com.ownify.android.ui.design.JCard
import com.ownify.android.ui.design.JIcon
import com.ownify.android.ui.design.JStyle
import com.ownify.android.ui.design.Legend
import com.ownify.android.ui.design.LegendItem
import com.ownify.android.ui.design.OwnifyIcons
import com.ownify.android.ui.design.PageIntro
import com.ownify.android.ui.design.PlotBars
import com.ownify.android.ui.design.PlotLine
import com.ownify.android.ui.design.RangeSwitch
import com.ownify.android.ui.design.T
import com.ownify.android.ui.design.press
import com.ownify.android.ui.design.reveal
import com.ownify.android.ui.theme.Accent
import com.ownify.android.ui.theme.InButton
import com.ownify.android.ui.theme.Ownify
import com.ownify.android.ui.theme.OwnifyType

/*
 * An area's charts over time (docs/CHARTS.md) — the website's
 * components/area-chart.php, components/area-charts-grid.php and
 * pages/area-chart.php: small, two by two, on the area's page (Slaap's four,
 * Training's), each on a page of its own, large. Everything shown is the
 * server's (lib/area-charts.php).
 */

/** The area's colour (area-charts.css): its bars; a line alone in it, beside bars in its lighter shade. */
private fun barColor(accent: Accent): Color = accent.color.copy(alpha = 0.58f)

internal fun seriesColor(series: AreaSeries, mixed: Boolean, accent: Accent): Color = when {
    series.kind == "bars" -> barColor(accent)
    mixed -> accent.light
    else -> accent.color
}

// ---------------------------------------------------------------------------
// Two by two
// ---------------------------------------------------------------------------

/** The small chart's room under its head: 12 above the plot, the plot at least 48, the dates' two rows and the 4 above them. */
internal val MiniPlotMin = 48.dp

/**
 * `.area-charts`: two by two, each card square — as wide as half the row —
 * unless a long name needs a line more; then both cards of that row grow to
 * the taller, as the website's grid rows do.
 */
@Composable
fun AreaChartsGrid(area: String, charts: List<AreaChart>) {
    val accent = Accent.of(area)
    val shell = LocalShell.current
    val density = LocalDensity.current
    SubcomposeLayout(Modifier.fillMaxWidth()) { constraints ->
        val gap = Ownify.Space3.roundToPx()
        val w = (constraints.maxWidth - gap) / 2
        val pad = Ownify.Space4.roundToPx()
        val axis = with(density) { (Ownify.FsTiny.toPx() * 2.5f) }.toInt() + Ownify.Space1.roundToPx()
        val rest = 2 * pad + 2 + Ownify.Space3.roundToPx() + MiniPlotMin.roundToPx() + axis
        val heads = charts.mapIndexed { i, chart ->
            subcompose("head$i") { MiniHead(chart, accent) }.firstOrNull()
                ?.measure(Constraints(maxWidth = (w - 2 * pad - 2).coerceAtLeast(0)))?.height ?: 0
        }
        val placed = ArrayList<Triple<androidx.compose.ui.layout.Placeable, Int, Int>>()
        var y = 0
        charts.chunked(2).forEachIndexed { r, row ->
            val h = maxOf(w, row.indices.maxOf { heads[r * 2 + it] } + rest)
            row.forEachIndexed { k, chart ->
                val i = r * 2 + k
                val card = subcompose("card$i") {
                    AreaChartMini(chart, accent, with(density) { h.toDp() }) { shell.openDetail(Detail.AreaChart(area, chart.id)) }
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
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun MiniHead(chart: AreaChart, accent: Accent) {
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
        // `.area-chart__now`: each series' key with its value, never apart; a pair that does not fit goes to the next line.
        FlowRow(Modifier.padding(top = 2.dp), horizontalArrangement = Arrangement.spacedBy(6.dp), itemVerticalAlignment = Alignment.CenterVertically) {
            val latest = chart.latest
            val valueStyle = OwnifyType.style(17.sp, FontWeight.Bold, tracking = (-0.02).em, lineHeight = 1.25.em, tabular = true)
            if (latest == null || latest.texts.all { it == null }) {
                T("—", valueStyle.copy(color = Ownify.TextMuted, fontWeight = FontWeight.SemiBold))
            } else {
                chart.series.forEachIndexed { k, s ->
                    val text = latest.texts.getOrNull(k) ?: return@forEachIndexed
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        if (chart.series.size > 1) {
                            val line = s.kind != "bars"
                            Box(
                                Modifier
                                    .size(width = if (line) 10.dp else 8.dp, height = if (line) 2.5.dp else 8.dp)
                                    .clip(RoundedCornerShape(2.dp))
                                    .background(seriesColor(s, chart.mixed, accent))
                            )
                        }
                        T(text, valueStyle, maxLines = 1)
                    }
                }
                latest.date?.let { T(it, OwnifyType.style(Ownify.FsTiny, color = Ownify.TextMuted), maxLines = 1) }
            }
        }
    }
}

/**
 * `.area-chart--mini`: the name and latest value, then the week on Ownify's
 * time axis, its dates in two rows. A tap opens its page; a finger moved
 * sideways reads a day first, as the large chart is read.
 */
@Composable
internal fun AreaChartMini(chart: AreaChart, accent: Accent, height: Dp, bare: Boolean = false, onOpen: () -> Unit) {
    val week = chart.periods.first()
    val interaction = remember { MutableInteractionSource() }
    val open = Modifier
        .fillMaxWidth()
        .height(height)
        .clickable(interaction, indication = null, role = Role.Button, onClickLabel = chart.open, onClick = onOpen)
        .semantics { contentDescription = chart.open }
    val content: @Composable androidx.compose.foundation.layout.ColumnScope.() -> Unit = {
        MiniHead(chart, accent)
        BoxWithConstraints(Modifier.fillMaxWidth().weight(1f).padding(top = Ownify.Space3)) {
            val axis = with(LocalDensity.current) { (Ownify.FsTiny.toPx() * 2.5f).toDp() } + Ownify.Space1
            AreaPlot(chart, accent, week, Modifier, height = (maxHeight - axis).coerceAtLeast(MiniPlotMin), mini = true)
        }
    }
    InButton {
        if (bare) {
            // Inside another card (Training's top section, `.area-chart.is-bare`): no card of its own.
            Column(open.padding(Ownify.Space4), content = content)
        } else {
            JCard(
                Modifier.reveal().press(interaction, scale = 0.985f).then(open),
                padding = androidx.compose.foundation.layout.PaddingValues(Ownify.Space4),
                content = content
            )
        }
    }
}

/** A period of a chart, drawn and read as the Verloop is ([HistoryPlot]), its bars under its line. */
@Composable
private fun AreaPlot(chart: AreaChart, accent: Accent, period: AreaPeriod, modifier: Modifier, height: Dp, mini: Boolean) {
    val mixed = chart.mixed
    val lineSeries = period.lines.mapNotNull { line -> chart.series.firstOrNull { it.key == line.key }?.let { it to line } }
    // A line's levels named on the large chart; otherwise none — the baseline alone, as on the website.
    val levels = if (mini) emptyList() else period.grid
    HistoryPlot(
        key = chart.id + period.key + if (mini) ":mini" else "",
        viewBox = Size(period.width, period.height),
        xs = period.x,
        lines = lineSeries.map { (s, line) -> PlotLine(seriesColor(s, mixed, accent), line.line, emptyList(), line.y) },
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
        bars = period.bars.map { PlotBars(barColor(accent), it.w, it.top, focus = accent.color) },
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
                            .background(seriesColor(s, mixed, accent))
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
 * `pages/area-chart.php`: the chart's name and the chart, large — its
 * switch over 7 dagen, 30 dagen, 90 dagen and 1 jaar, the plot 160 dp tall
 * with its levels named, two series' legend, and the hint. Opened over the
 * area's page; back goes to it.
 */
@Composable
fun AreaChartDetail(chart: AreaChart, area: String, scroll: ScrollState) {
    DetailColumn(scroll, back = chart.back, backAria = "Terug naar ${chart.back}") {
        PageIntro(chart.title, null, Modifier.reveal())
        AreaChartCard(chart, Accent.of(area))
    }
}

@Composable
private fun AreaChartCard(chart: AreaChart, accent: Accent) {
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
            AreaPlot(chart, accent, period, Modifier, height = 160.dp, mini = false)
        }
        if (chart.series.size > 1) {
            Legend(
                chart.series.map { LegendItem(it.label, null, accent, dot = seriesColor(it, chart.mixed, accent)) },
                Modifier.padding(top = Ownify.Space4),
                values = false
            )
        }
        if (filled) {
            T(chart.hint, JStyle.Tiny, Modifier.fillMaxWidth().padding(top = Ownify.Space3), align = TextAlign.Center)
        }
    }
}
