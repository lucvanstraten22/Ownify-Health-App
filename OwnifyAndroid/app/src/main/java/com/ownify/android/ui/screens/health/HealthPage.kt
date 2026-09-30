package com.ownify.android.ui.screens.health

import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.animateDpAsState
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.ScrollState
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.interaction.collectIsPressedAsState
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.IntrinsicSize
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.Layout
import androidx.compose.ui.layout.boundsInWindow
import androidx.compose.ui.layout.onGloballyPositioned
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.platform.LocalView
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.role
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.IntOffset
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.em
import androidx.compose.ui.unit.sp
import com.ownify.android.data.AppData
import com.ownify.android.data.Area
import com.ownify.android.ui.app.Detail
import com.ownify.android.ui.app.LocalShell
import com.ownify.android.ui.app.PageColumn
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
import com.ownify.android.ui.design.PageIntro
import com.ownify.android.ui.design.RangeSwitch
import com.ownify.android.ui.design.T
import com.ownify.android.ui.design.countUpText
import com.ownify.android.ui.design.drawChartLine
import com.ownify.android.ui.design.press
import com.ownify.android.ui.design.rememberPlayOnSight
import com.ownify.android.ui.design.rememberSvgPaths
import com.ownify.android.ui.design.reveal
import com.ownify.android.ui.design.stretched
import com.ownify.android.ui.theme.Accent
import com.ownify.android.ui.theme.Ownify
import com.ownify.android.ui.theme.InButton
import com.ownify.android.ui.theme.OwnifyType
import com.ownify.android.ui.theme.LocalAccent

/**
 * Gezondheid (pages/health.php): the three areas as one row of cards, the
 * trend of all three, and the line under the page. Everything else is on the
 * detail page behind each card.
 */
@Composable
fun HealthPage(data: AppData, scroll: ScrollState) {
    val health = data.health
    val screen = LocalScreen.current
    PageColumn(scroll) {
        PageIntro(health.title, health.lede, Modifier.reveal())

        // .grid.grid--three: three equal columns, the cards as tall as the tallest.
        Row(
            Modifier.fillMaxWidth().height(IntrinsicSize.Max).reveal(),
            horizontalArrangement = Arrangement.spacedBy(if (screen.wide) Ownify.Space4 else Ownify.Space3)
        ) {
            health.areas.forEach { area -> HealthCard(area, Modifier.weight(1f).fillMaxHeight()) }
        }

        TrendCard(data, only = null)

        if (data.disclaimer.isNotEmpty()) Disclaimer(data.disclaimer, Modifier.reveal())
    }
}

/**
 * `components/health-card.php`: one area — its icon, its score over its
 * maximum, the meter and its name. The whole card opens the detail.
 */
@Composable
private fun HealthCard(area: Area, modifier: Modifier) {
    InButton {
        val shell = LocalShell.current
        val narrow = LocalScreen.current.narrow
        val accent = Accent.of(area.accent)
        val score = area.score
        val empty = score.value == null
        val (play, sight) = rememberPlayOnSight()
        val interaction = remember { MutableInteractionSource() }
        val pressed by interaction.collectIsPressedAsState()
        val nudge by animateDpAsState(if (pressed) 1.dp else 0.dp, tween(Ownify.FastMs, easing = Ownify.Ease), label = "chevron")

        CompositionLocalProvider(LocalAccent provides accent) {
            JCard(
                modifier
                    .press(interaction)
                    .then(sight)
                    .clickable(interaction, indication = null) { shell.openDetail(Detail.HealthArea(area.id)) }
                    .clearAndSetSemantics {
                        role = Role.Button
                        contentDescription = "${area.label} — " +
                            (if (empty) "nog geen gegevens" else "${score.value} van ${score.max}") + ". Open details."
                    },
                padding = if (narrow) PaddingValues(horizontal = Ownify.Space2, vertical = Ownify.Space3) else PaddingValues(horizontal = Ownify.Space3, vertical = Ownify.Space4)
            ) {
                Column(
                    Modifier.fillMaxWidth(),
                    horizontalAlignment = Alignment.CenterHorizontally,
                    verticalArrangement = Arrangement.spacedBy(if (narrow) Ownify.Space2 else Ownify.Space3)
                ) {
                    OwnifyIcons.solid(area.icon)?.let {
                        IconTile(it, size = if (narrow) 28.dp else 32.dp, radius = 11.dp, iconSize = 17.dp, color = Color.White, background = accent.color, border = accent.color)
                    }

                    // .score-value--centred: the number and "/100" on one baseline.
                    Row(horizontalArrangement = Arrangement.spacedBy(2.dp)) {
                        T(
                            countUpText(score.value?.toString() ?: "—", play && !empty),
                            OwnifyType.style(
                                if (narrow) 24.sp else Ownify.FsScoreSm, FontWeight.Bold,
                                if (empty) Ownify.TextSecondary else Ownify.TextPrimary,
                                tracking = (-0.035).em, lineHeight = 1.em, tabular = true
                            ),
                            Modifier.alignByBaseline()
                        )
                        T(
                            "/${score.max}",
                            OwnifyType.style(if (narrow) Ownify.FsTiny else Ownify.FsSmall, color = Ownify.TextMuted),
                            Modifier.alignByBaseline()
                        )
                    }

                    Meter(
                        share = if (empty || score.max <= 0) null else (score.value!!.toFloat() / score.max).coerceIn(0f, 1f),
                        play = play,
                        accent = accent.color
                    )

                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(1.dp)) {
                        T(
                            area.label,
                            // `.health-card__label`'s own -0.01em.
                            OwnifyType.style(if (narrow) Ownify.FsTiny else Ownify.FsSmall, FontWeight.SemiBold, tracking = (-0.01).em),
                            maxLines = 1
                        )
                        JIcon(OwnifyIcons.chevronRight, Modifier.offset { IntOffset(nudge.roundToPx(), 0) }, size = 13.dp, color = Ownify.TextMuted)
                    }
                }
            }
        }
    }
}

/**
 * `components/health-trend.php`: week and month, one shown at a time, drawn
 * on the server; all three areas as lines, or — with [only] — one area with
 * its 10% wash. The lines draw on the first time the chart is looked at, and
 * again whenever the other range is chosen.
 */
@Composable
fun TrendCard(data: AppData, only: String?) {
    val trend = data.health.trend
    val areas = if (only != null) listOfNotNull(data.health.area(only)) else data.health.areas
    var range by rememberSaveable(only) { mutableStateOf(trend.ranges.firstOrNull()?.key) }
    val selected = trend.ranges.firstOrNull { it.key == range } ?: trend.ranges.firstOrNull()
    val hasData = areas.any { area -> trend.charts[area.id]?.values?.any { it.hasData } == true }

    JCard(Modifier.fillMaxWidth().reveal()) {
        // .card__head: the icon and title, and the switch at the far end.
        Row(
            Modifier.fillMaxWidth(),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)
        ) {
            Row(
                Modifier.weight(1f),
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)
            ) {
                IconTile(OwnifyIcons.solidChart, color = Color.White, background = Ownify.Neutral, border = Ownify.Neutral)
                T(trend.title, JStyle.Eyebrow, Modifier.semantics { heading() })
            }
            RangeSwitch(
                options = trend.ranges.map { it.key to it.label },
                selected = selected?.key,
                onSelect = { range = it },
                label = "Periode kiezen"
            )
        }

        if (selected != null) {
            Chart(
                data = data,
                areas = areas,
                rangeKey = selected.key,
                points = selected.points,
                filled = hasData,
                wash = only != null,
                description = "${trend.title} — ${selected.label}" + if (hasData) "" else ": nog geen gegevens",
                empty = if (hasData) null else trend.empty
            )
        }

        if (only == null) {
            Legend(
                areas.map { LegendItem(it.label, null, Accent.of(it.accent)) },
                Modifier.padding(top = Ownify.Space4),
                values = false
            )
        }
    }
}

/** `.chart`: the lines in a 100 dp box, three gridlines, the axis under it. */
@Composable
private fun Chart(
    data: AppData,
    areas: List<Area>,
    rangeKey: String,
    points: List<String>,
    filled: Boolean,
    wash: Boolean,
    description: String,
    empty: String?
) {
    val trend = data.health.trend
    val viewBox = Size(trend.width, trend.height)
    val still = LocalStillMotion.current
    val view = LocalView.current
    val draw = remember { Animatable(if (still) 1f else 0f) }
    var seen by remember { mutableStateOf(still) }

    // Drawn on when first looked at (25% of it on screen), and on each switch after that.
    LaunchedEffect(seen, rangeKey) {
        if (!seen || still) return@LaunchedEffect
        draw.snapTo(0f)
        draw.animateTo(1f, tween(900, easing = Ownify.EaseOut))
    }

    val series = areas.mapNotNull { area ->
        val chart = trend.charts[area.id]?.get(rangeKey) ?: return@mapNotNull null
        if (chart.line.isEmpty() && chart.dots.isEmpty()) null else Triple(area, chart, Accent.of(area.accent).color)
    }
    val lines = series.map { (_, chart, _) -> rememberSvgPaths(chart.line) }
    val washes = series.map { (_, chart, _) -> rememberSvgPaths(if (wash) chart.area else emptyList()) }

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
                    .clearAndSetSemantics { contentDescription = description }
            ) {
                val sy = size.height / viewBox.height
                val sx = size.width / viewBox.width
                // .chart__grid at a quarter, half and three quarters of the plot.
                for (line in listOf(0.25f, 0.5f, 0.75f)) {
                    val y = (12f + line * (viewBox.height - 24f)) * sy
                    drawLine(Ownify.white(0.055f), Offset(0f, y), Offset(size.width, y), 1.dp.toPx())
                }
                series.forEachIndexed { i, (_, chart, color) ->
                    washes[i].forEach { drawPath(it.stretched(viewBox, size), color.copy(alpha = 0.10f)) }
                    lines[i].forEach { drawChartLine(it.stretched(viewBox, size), color, 2.2.dp.toPx(), draw.value) }
                    for ((x, y) in chart.dots) {
                        // r = 2.5 in the view box, stretched with it.
                        val rx = 2.5f * sx
                        val ry = 2.5f * sy
                        drawOval(color, topLeft = Offset(x * sx - rx, y * sy - ry), size = Size(rx * 2, ry * 2))
                    }
                }
            }
            Axis(points, Modifier.padding(top = Ownify.Space2))
        }

        if (empty != null) {
            T(
                empty,
                OwnifyType.style(Ownify.FsSmall, color = Ownify.TextMuted),
                Modifier.fillMaxWidth().padding(top = 46.dp),
                align = TextAlign.Center
            )
        }
    }
}

/**
 * `.chart__axis`: the tick labels spread evenly, the first starting at the
 * left edge, the last ending at the right, the others centred on their spot.
 */
@Composable
fun Axis(labels: List<String>, modifier: Modifier = Modifier) {
    val density = LocalDensity.current
    val style = OwnifyType.style(Ownify.FsTiny, color = Ownify.TextMuted)
    // height: 1.2em of the card's 15 sp text.
    val height = with(density) { (Ownify.FsBody.toPx() * 1.2f).toDp() }
    Layout(
        content = { labels.forEach { T(it, style, maxLines = 1) } },
        modifier = modifier.fillMaxWidth().height(height).clearAndSetSemantics { }
    ) { measurables, constraints ->
        val placeables = measurables.map { it.measure(constraints.copy(minWidth = 0, maxWidth = Int.MAX_VALUE)) }
        layout(constraints.maxWidth, constraints.maxHeight) {
            val count = placeables.size
            placeables.forEachIndexed { i, p ->
                val left = if (count > 1) i.toFloat() / (count - 1) * constraints.maxWidth else constraints.maxWidth / 2f
                val x = when {
                    i == 0 -> left
                    i == count - 1 -> left - p.width
                    else -> left - p.width / 2f
                }
                p.place(x.toInt(), 0)
            }
        }
    }
}
