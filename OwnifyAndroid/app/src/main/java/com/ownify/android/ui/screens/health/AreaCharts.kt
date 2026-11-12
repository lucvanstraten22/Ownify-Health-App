package com.ownify.android.ui.screens.health

import androidx.compose.foundation.shape.CircleShape
import com.ownify.android.i18n.trf
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
import com.ownify.android.data.AreaAbout
import com.ownify.android.ui.design.LocalScreen
import com.ownify.android.ui.design.cssPadding
import com.ownify.android.ui.screens.research.Points
import com.ownify.android.ui.screens.settings.SettingsCard
import com.ownify.android.ui.screens.settings.SettingsEyebrow
import com.ownify.android.ui.screens.settings.settingsRow
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
import com.ownify.android.ui.design.PlotBand
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

/** "9.956 stappen" → ("9.956", "stappen"): the number and its unit apart (components/area-chart.php); null for a % or a bare value. */
internal fun splitUnit(text: String): Pair<String, String>? {
    val m = Regex("^([\\d.,:\\s\u2212-]*\\d)\\s?([^\\d\\s][^\\d]*)$").find(text) ?: return null
    val unit = m.groupValues[2]
    return if (unit == "%") null else m.groupValues[1] to unit
}

/** The area's colour (area-charts.css): its bars; a line alone in it, beside bars in its lighter shade. */
// N11 (50.0): the bars at 75 % (`--bar`), so they stand out against the card.
private fun barColor(accent: Accent): Color = accent.color.copy(alpha = 0.75f)

internal fun seriesColor(series: AreaSeries, mixed: Boolean, accent: Accent): Color = when {
    series.kind == "bars" -> barColor(accent)
    mixed -> accent.light
    else -> accent.color
}

// ---------------------------------------------------------------------------
// Two by two
// ---------------------------------------------------------------------------

/** The small chart's room under its head: 12 above the plot, the plot at least 48, the dates' one row (50.0: weekdays fit in one) and the 4 above them. */
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
        // A chart alone in its grid (Training's Hartbelasting) is as wide as the cards around it, as tall as a row of squares.
        val cw = if (charts.size == 1) constraints.maxWidth else w
        val pad = Ownify.Space4.roundToPx()
        val axis = with(density) { (Ownify.FsCaption.toPx() * 1.25f) }.toInt() + Ownify.Space1.roundToPx()
        val rest = 2 * pad + 2 + Ownify.Space3.roundToPx() + MiniPlotMin.roundToPx() + axis
        val heads = charts.mapIndexed { i, chart ->
            subcompose("head$i") { MiniHead(chart, accent) }.firstOrNull()
                ?.measure(Constraints(maxWidth = (cw - 2 * pad - 2).coerceAtLeast(0)))?.height ?: 0
        }
        val placed = ArrayList<Triple<androidx.compose.ui.layout.Placeable, Int, Int>>()
        var y = 0
        charts.chunked(2).forEachIndexed { r, row ->
            val h = maxOf(w, row.indices.maxOf { heads[r * 2 + it] } + rest)
            row.forEachIndexed { k, chart ->
                val i = r * 2 + k
                val card = subcompose("card$i") {
                    AreaChartMini(chart, accent, with(density) { h.toDp() }) { shell.openDetail(Detail.AreaChart(area, chart.id)) }
                }.first().measure(Constraints.fixed(cw, h))
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
                OwnifyType.style(Ownify.FsSubhead, FontWeight.SemiBold, Ownify.TextSecondary)
                    .copy(hyphens = Hyphens.Auto),
                Modifier.weight(1f)
            )
            JIcon(OwnifyIcons.chevronRight, Modifier.padding(top = 1.dp), size = 14.dp, color = Ownify.TextMuted)
        }
        // `.area-chart__now`: each series' key with its value, never apart; a pair that does not fit goes to the next line.
        FlowRow(Modifier.padding(top = 2.dp), horizontalArrangement = Arrangement.spacedBy(6.dp), itemVerticalAlignment = Alignment.CenterVertically) {
            val latest = chart.latest
            val valueStyle = OwnifyType.style(Ownify.FsHeadline, FontWeight.Bold, tabular = true)
            if (latest == null || latest.texts.all { it == null }) {
                T("—", valueStyle.copy(color = Ownify.TextMuted, fontWeight = FontWeight.SemiBold))
            } else {
                chart.series.forEachIndexed { k, s ->
                    val text = latest.texts.getOrNull(k) ?: return@forEachIndexed
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        if (chart.drawn.size > 1 && s.drawn) {
                            val line = s.kind != "bars"
                            // B11/C58 (50.0): a line's key is a dot, never a dash a minus could be read in.
                            Box(
                                Modifier
                                    .size(8.dp)
                                    .clip(if (line) CircleShape else RoundedCornerShape(2.dp))
                                    .background(seriesColor(s, chart.mixed, accent))
                            )
                        }
                        // D12: the unit after its number, small and grey (% stays with it).
                        val split = splitUnit(text)
                        if (split == null) T(text, valueStyle, Modifier.alignByBaseline(), maxLines = 1)
                        else {
                            T(split.first, valueStyle, Modifier.alignByBaseline(), maxLines = 1)
                            T(split.second, OwnifyType.style(Ownify.FsSubhead, FontWeight.Normal, Ownify.TextMuted), Modifier.alignByBaseline().padding(start = 2.dp), maxLines = 1)
                        }
                        // `.area-chart__of`: its target after the value, quieter — "van 2.300 kcal".
                        if (k == 0) latest.of?.let { T(it, OwnifyType.style(Ownify.FsCaption, FontWeight.SemiBold, Ownify.TextMuted), Modifier.alignByBaseline(), maxLines = 1) }
                    }
                }
                latest.date?.let { T(it, OwnifyType.style(Ownify.FsCaption, color = Ownify.TextMuted), maxLines = 1) }
                // `.area-chart__sub`: the same value in its second unit (kJ, ml), on a line of its own.
                latest.sub?.let { T(it, OwnifyType.style(Ownify.FsSubhead, color = Ownify.TextMuted), Modifier.fillMaxWidth(), maxLines = 1) }
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
            val axis = with(LocalDensity.current) { (Ownify.FsCaption.toPx() * 1.25f).toDp() } + Ownify.Space1   // one row (50.0)
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
    // A second scale (kJ) on the right of the large chart; its narrower plot takes 7 dagen's dates in two rows.
    val alt = if (mini) emptyList() else period.gridAlt
    // Levels of five characters or more ("2.000") need a wider gutter than "75" (`.has-wide-levels`).
    val wide = !mini && chart.periods.any { p -> p.grid.any { it.label.length >= 5 } }
    HistoryPlot(
        key = chart.id + period.key + if (mini) ":mini" else "",
        viewBox = Size(period.width, period.height),
        xs = period.x,
        lines = lineSeries.map { (s, line) -> PlotLine(seriesColor(s, mixed, accent), line.line, emptyList(), line.y) },
        filled = period.hasData,
        dayDots = true,
        small = period.group == "day" && period.x.size > 7,
        carried = { i -> i in period.carried },
        axis = if (mini || alt.isNotEmpty()) period.axisRows ?: period.axis else period.axis,
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
        gutter = if (levels.isNotEmpty()) (if (wide) 34.dp else 26.dp) else 0.dp,
        bars = period.bars.map { PlotBars(barColor(accent), it.w, it.top, focus = accent.color) },
        // `.area-chart__band`: the bereik, a green zone behind the bars, a line of the green above and below it.
        bands = period.band?.let { listOf(PlotBand(it.top, it.bottom, Ownify.HealthWash, edge = Ownify.HealthFill)) }.orEmpty(),
        levelsEnd = alt,
        gutterEnd = if (alt.isNotEmpty()) 40.dp else 0.dp,
        readOnDrag = mini,
        compact = mini
    ) { i ->
        // `.health-history__tip`: the date or days — a week's or month's, that it is their mean — then each value.
        val p = period.points[i]
        val values = chart.series.mapIndexedNotNull { k, s -> p.texts.getOrNull(k)?.let { s to it } }
        val detail = p.detail?.takeIf { values.isNotEmpty() }
        Row {
            T(p.label + if (detail != null) " · " else "", OwnifyType.style(Ownify.FsCaption, color = Ownify.TextMuted), maxLines = 1)
            detail?.let { T(it, OwnifyType.style(Ownify.FsCaption, color = Ownify.TextSecondary), maxLines = 1) }
        }
        if (values.isEmpty()) {
            p.note?.let { T(it, OwnifyType.style(Ownify.FsCaption, color = Ownify.TextMuted), maxLines = 1) }
        } else if (chart.series.size == 1) {
            T(values.first().second, OwnifyType.style(Ownify.FsSubhead, FontWeight.Bold, tabular = true), maxLines = 1)
        } else {
            for ((s, text) in values) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    val line = s.kind != "bars"
                    val keyed = chart.drawn.size > 1 && s.drawn
                    if (keyed) Box(
                        Modifier
                            .size(8.dp)
                            .clip(if (line) CircleShape else RoundedCornerShape(2.dp))
                            .background(seriesColor(s, mixed, accent))
                    )
                    T(s.label, OwnifyType.style(Ownify.FsCaption, color = Ownify.TextSecondary), if (keyed) Modifier.padding(start = 6.dp) else Modifier, maxLines = 1)
                    Spacer(Modifier.width(8.dp))
                    T(text, OwnifyType.style(Ownify.FsSubhead, FontWeight.Bold, tabular = true), maxLines = 1)
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
    DetailColumn(scroll, back = chart.back, backAria = trf("Terug naar %s", chart.back)) {
        PageIntro(chart.title, null, Modifier.reveal())
        AreaChartCard(chart, Accent.of(area))
        chart.about?.let { AreaAboutBlock(it) }
    }
}

/**
 * 40.1: "Over …" under the large chart, as on the website — Samenvatting
 * onderzoek's eyebrow, its card and its points (`.settings-block` >
 * `.settings-state` > `.research-detail__points`).
 */
@Composable
private fun AreaAboutBlock(about: AreaAbout) {
    Column(Modifier.fillMaxWidth().reveal()) {
        SettingsEyebrow(about.title)
        SettingsCard {
            val narrow = LocalScreen.current.narrow
            Column(
                Modifier
                    .fillMaxWidth()
                    .settingsRow(false)
                    .cssPadding(if (narrow) Ownify.Space3 else Ownify.Space4, top = Ownify.Space3, bottom = Ownify.Space3)
            ) {
                Points(about.points)
            }
        }
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
        // `.area-chart__units`: both units named over their scales, kcal on the left, kJ on the right.
        val units = chart.units
        if (units != null && period.gridAlt.isNotEmpty()) {
            Row(Modifier.fillMaxWidth().padding(top = Ownify.Space4), horizontalArrangement = Arrangement.SpaceBetween) {
                val unitStyle = OwnifyType.style(Ownify.FsCaption, FontWeight.SemiBold, Ownify.TextMuted)
                T(units.first, unitStyle)
                T(units.second, unitStyle)
            }
        }
        Box(Modifier.fillMaxWidth().padding(top = if (units != null && period.gridAlt.isNotEmpty()) Ownify.Space1 else Ownify.Space4)) {
            AreaPlot(chart, accent, period, Modifier, height = 160.dp, mini = false)
        }
        if (chart.drawn.size > 1) {
            Legend(
                chart.drawn.map { LegendItem(it.label, null, accent, dot = seriesColor(it, chart.mixed, accent)) },
                Modifier.padding(top = Ownify.Space4),
                values = false
            )
        }
        if (filled) {
            T(chart.hint, JStyle.Footnote, Modifier.fillMaxWidth().padding(top = Ownify.Space3), align = TextAlign.Center)
        }
    }
}
