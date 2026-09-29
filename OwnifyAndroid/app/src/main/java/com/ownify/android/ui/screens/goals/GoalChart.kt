package com.ownify.android.ui.screens.goals

import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.drawBehind
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Rect
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.asAndroidPath
import androidx.compose.foundation.gestures.awaitEachGesture
import androidx.compose.foundation.gestures.awaitFirstDown
import androidx.compose.ui.input.pointer.PointerEventPass
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.layout.Layout
import androidx.compose.ui.layout.boundsInWindow
import androidx.compose.ui.layout.layout
import androidx.compose.ui.layout.onGloballyPositioned
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.platform.LocalGraphicsContext
import androidx.compose.ui.platform.LocalView
import androidx.compose.ui.semantics.CustomAccessibilityAction
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.customActions
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.stateDescription
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.rememberTextMeasurer
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.IntSize
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.em
import com.ownify.android.data.ChartPoint
import com.ownify.android.data.Goal
import com.ownify.android.data.GoalChart
import com.ownify.android.ui.app.LocalOwnedAreas
import com.ownify.android.ui.app.ownsGestures
import com.ownify.android.ui.design.BoxShadow
import com.ownify.android.ui.design.Chip
import com.ownify.android.ui.design.IconTile
import com.ownify.android.ui.design.JCard
import com.ownify.android.ui.design.JStyle
import com.ownify.android.ui.design.OwnifyIcons
import com.ownify.android.ui.design.LocalStillMotion
import com.ownify.android.ui.design.T
import com.ownify.android.ui.design.cssPadding
import com.ownify.android.ui.design.drawBoxShadows
import com.ownify.android.ui.design.drawChartLine
import com.ownify.android.ui.design.rememberSvgPaths
import com.ownify.android.ui.design.reveal
import com.ownify.android.ui.design.stretched
import com.ownify.android.ui.screens.health.Axis
import com.ownify.android.ui.theme.Ownify
import com.ownify.android.ui.theme.OwnifyType
import com.ownify.android.ui.theme.LocalTracking
import com.ownify.android.ui.theme.LocalAccent
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlin.math.abs

/** `--plot-h` and `--gutter`: the plot's height and the room for the Y labels beside it. */
private val PlotHeight = 132.dp
private val Gutter = 38.dp

/** How long a touch reading stays after the finger lifts (goal-chart.js LINGER). */
private const val LINGER_MS = 1600L

/**
 * Verloop (pages/goal-detail.php, section 3): the goal's real values against
 * real dates, drawn by the server (lib/goal-chart.php) — or, with nothing
 * recorded yet, the faint grid and one sentence, never an invented line.
 */
@Composable
fun GoalHistory(goal: Goal, copy: Map<String, String>) {
    val chart = goal.chart
    val history = copy["history"].orEmpty()
    val filled = goal.hasHistory && chart != null

    JCard(Modifier.fillMaxWidth().reveal()) {
        // .card__head
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)) {
            Row(Modifier.weight(1f), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)) {
                IconTile(OwnifyIcons.chart)
                T(history, JStyle.Eyebrow)
            }
            val target = chart?.target
            if (filled && target != null) {
                // The target line's key: a short stroke of the same line.
                Chip(target.label, muted = true, leading = {
                    Box(Modifier.width(12.dp).height(1.dp).background(Ownify.TextFaint))
                })
            }
        }

        if (filled) {
            Plot(goal, chart!!, "$history van ${goal.name}. ${chart.summary}")
        } else {
            EmptyHistory(goal, history, copy["history_empty"].orEmpty())
        }
    }
}

/** Nothing recorded yet: the trend card's empty chart, from the start to now. */
@Composable
private fun EmptyHistory(goal: Goal, history: String, empty: String) {
    Box(Modifier.fillMaxWidth().padding(top = Ownify.Space5)) {
        Column(Modifier.fillMaxWidth()) {
            Canvas(
                Modifier
                    .fillMaxWidth()
                    .height(100.dp)
                    .alpha(0.55f)
                    .clearAndSetSemantics { contentDescription = "$history: nog geen verloop" }
            ) {
                // A 300 × 96 view box, stretched: the lines at a quarter, half and three quarters.
                val sy = size.height / 96f
                for (line in listOf(0.25f, 0.5f, 0.75f)) {
                    val y = (12f + line * (96f - 24f)) * sy
                    drawLine(Ownify.white(0.055f), Offset(0f, y), Offset(size.width, y), 1.dp.toPx())
                }
            }
            Axis(listOf(goal.startLabel.ifEmpty { "Start" }, if (goal.isCompleted) "Behaald" else "Nu"), Modifier.padding(top = Ownify.Space2))
        }
        T(empty, OwnifyType.style(Ownify.FsSmall, color = Ownify.TextMuted), Modifier.fillMaxWidth().padding(top = 46.dp), align = TextAlign.Center)
    }
}

/**
 * `.goal-chart`: the unit, the plot with its Y labels beside it, and the
 * dates under it. A finger on the plot reads it — the nearest point by date,
 * a crosshair, the value above the plot — and a vertical drag still scrolls.
 */
@Composable
private fun Plot(goal: Goal, chart: GoalChart, description: String) {
    val accent = LocalAccent.current.color
    val density = LocalDensity.current
    val owned = LocalOwnedAreas.current
    val scope = rememberCoroutineScope()
    val points = chart.points
    var reading by remember(chart) { mutableIntStateOf(-1) }
    var linger by remember { mutableStateOf<Job?>(null) }
    val ownKey = remember { Any() }

    DisposableEffect(ownKey) { onDispose { owned.set(ownKey, null) } }

    fun show(index: Int) {
        linger?.cancel()
        reading = index.coerceIn(0, points.lastIndex)
    }

    fun hide() {
        linger?.cancel()
        reading = -1
    }

    /** The point nearest in time to a horizontal position, in % of the plot. */
    fun nearest(percent: Float): Int {
        var best = 0
        var gap = Float.MAX_VALUE
        points.forEachIndexed { i, p ->
            val d = abs(p.x - percent)
            if (d < gap) {
                gap = d
                best = i
            }
        }
        return best
    }

    Column(Modifier.fillMaxWidth().padding(top = Ownify.Space5)) {
        if (chart.axisUnit.isNotEmpty()) {
            T(chart.axisUnit, JStyle.Tiny, Modifier.fillMaxWidth().padding(bottom = 6.dp).clearAndSetSemantics { }, align = TextAlign.End)
        }

        // .goal-chart__frame: the plot, 8, the gutter.
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
            Box(
                Modifier
                    .weight(1f)
                    .height(PlotHeight)
                    .ownsGestures(owned, ownKey)
                    .semantics {
                        contentDescription = description
                        stateDescription = points.getOrNull(reading)?.let { "${it.date}, ${it.value}" } ?: ""
                        liveRegion = LiveRegionMode.Polite
                        // The keyboard's arrows, for a screen reader: step through the readings.
                        customActions = listOf(
                            CustomAccessibilityAction("Volgende meting") {
                                show(if (reading < 0) points.lastIndex else minOf(points.lastIndex, reading + 1)); true
                            },
                            CustomAccessibilityAction("Vorige meting") {
                                show(if (reading < 0) points.lastIndex else maxOf(0, reading - 1)); true
                            },
                            CustomAccessibilityAction("Meting verbergen") { hide(); true }
                        )
                    }
                    .pointerInput(points) {
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
                                    // Lifted: the reading stays a moment, so it can be read without a finger on it.
                                    linger?.cancel()
                                    linger = scope.launch {
                                        delay(LINGER_MS)
                                        reading = -1
                                    }
                                    break
                                }
                                if (change.isConsumed) {
                                    // The page took it for a scroll: out of the way at once.
                                    hide()
                                    break
                                }
                                if (size.width > 0) show(nearest(change.position.x / size.width * 100f))
                            }
                        }
                    }
            ) {
                PlotDrawing(chart, accent, reading)
                EndLabel(chart, reading >= 0)
                points.getOrNull(reading)?.let { Reading(it, accent) }
            }

            // .goal-chart__y: each label on its line, right-aligned in the gutter.
            YTicks(chart, Modifier.width(Gutter).height(PlotHeight))
        }

        // .goal-chart__x: under the plot only, each tick aligned as the server says.
        XTicks(chart, Modifier.padding(top = Ownify.Space2, end = Gutter + Ownify.Space2))
    }
}

/** The grid, the target, the fading wash, the line drawn on, and the dots. */
@Composable
private fun PlotDrawing(chart: GoalChart, accent: Color, reading: Int) {
    val still = LocalStillMotion.current
    val view = LocalView.current
    val draw = remember { Animatable(if (still) 1f else 0f) }
    var seen by remember { mutableStateOf(still) }
    val lines = rememberSvgPaths(chart.line)
    val areas = rememberSvgPaths(chart.area)
    val viewBox = Size(chart.width, chart.height)

    LaunchedEffect(seen) {
        if (seen && !still) {
            draw.snapTo(0f)
            draw.animateTo(1f, tween(900, easing = Ownify.EaseOut))
        }
    }

    Canvas(
        Modifier
            .fillMaxSize()
            .onGloballyPositioned { coordinates ->
                if (seen) return@onGloballyPositioned
                val bounds = coordinates.boundsInWindow()
                val visible = (minOf(bounds.bottom, view.height.toFloat()) - maxOf(bounds.top, 0f)).coerceAtLeast(0f)
                if (bounds.height > 0f && visible / bounds.height >= 0.25f && bounds.left < view.width && bounds.right > 0f) seen = true
            }
    ) {
        val sy = size.height / viewBox.height
        for (tick in chart.yTicks) {
            val y = tick.top / 100f * viewBox.height * sy
            drawLine(Ownify.white(0.055f), Offset(0f, y), Offset(size.width, y), 1.dp.toPx())
        }
        chart.target?.let { target ->
            val y = target.top / 100f * viewBox.height * sy
            drawLine(Ownify.TextFaint, Offset(0f, y), Offset(size.width, y), 1.dp.toPx())
        }
        for (area in areas) {
            val path = area.stretched(viewBox, size)
            val bounds = path.getBounds()
            // The gradient runs over the wash's own box, top to bottom: .16 to nothing.
            drawPath(
                path,
                Brush.verticalGradient(
                    listOf(accent.copy(alpha = 0.16f), accent.copy(alpha = 0f)),
                    startY = bounds.top,
                    endY = bounds.bottom.coerceAtLeast(bounds.top + 1f)
                )
            )
        }
        for (line in lines) drawChartLine(line.stretched(viewBox, size), accent, 2.2.dp.toPx(), draw.value)

        // The dots: 8 across with a 2 px ring of the card behind them, never stretched.
        for (point in chart.points) {
            if (!point.dot) continue
            val c = Offset(point.x / 100f * size.width, point.y / 100f * size.height)
            drawCircle(Ownify.BgSecondary, radius = 6.dp.toPx(), center = c)
            drawCircle(accent, radius = 4.dp.toPx(), center = c)
        }

        // The reading: the crosshair and the focused point.
        chart.points.getOrNull(reading)?.let { point ->
            val x = point.x / 100f * size.width
            drawRect(Ownify.white(0.28f), topLeft = Offset(x - 0.5.dp.toPx(), 0f), size = Size(1.dp.toPx(), size.height))
            val c = Offset(x, point.y / 100f * size.height)
            drawCircle(Ownify.mix(accent, 0.22f, Color.Transparent), radius = 12.dp.toPx(), center = c)
            drawCircle(Ownify.BgSecondary, radius = 8.dp.toPx(), center = c)
            drawCircle(accent, radius = 6.dp.toPx(), center = c)
        }
    }
}

/**
 * `.goal-chart__end`: the latest value beside its point — on the side the
 * server prefers if the line, a dot or the target does not run through it
 * there, else on the other side, else not at all (goal-chart.js settleEnd).
 * Hidden while the chart is being read.
 */
@Composable
private fun EndLabel(chart: GoalChart, reading: Boolean) {
    val end = chart.end ?: return
    val density = LocalDensity.current
    val measurer = rememberTextMeasurer()
    // Measured here to place it, so its inherited letter spacing is settled here too.
    val style = OwnifyType.style(Ownify.FsTiny, FontWeight.SemiBold, Ownify.TextSecondary, tabular = true, tracking = LocalTracking.current)
    val label = remember(end.label, style) { measurer.measure(end.label, style, maxLines = 1) }
    val shown by animateFloatAsState(if (reading) 0f else 1f, tween(Ownify.FastMs, easing = Ownify.Ease), label = "end")
    val lines = rememberSvgPaths(chart.line)
    var plot by remember { mutableStateOf(IntSize.Zero) }

    val below: Boolean? = remember(plot, label.size, chart) {
        if (plot.width == 0 || plot.height == 0) null
        else with(density) { settleEnd(chart, lines, Size(plot.width.toFloat(), plot.height.toFloat()), label.size, 9.dp.toPx(), 3.dp.toPx()) }
    }

    Box(
        Modifier
            .fillMaxSize()
            .onGloballyPositioned { plot = it.size }
            .clearAndSetSemantics { }
    ) {
        // Not measured yet, or nowhere free of the line: not shown.
        if (below == null) return@Box
        Layout(
            content = { T(end.label, style, maxLines = 1) },
            modifier = Modifier.alpha(shown)
        ) { measurables, constraints ->
            val p = measurables.first().measure(constraints.copy(minWidth = 0, minHeight = 0))
            layout(constraints.maxWidth, constraints.maxHeight) {
                val box = endBox(end.x, end.y, end.align, below, plot.width.toFloat(), plot.height.toFloat(), p.width.toFloat(), p.height.toFloat(), 9.dp.toPx())
                p.place(box.left.toInt(), box.top.toInt())
            }
        }
    }
}

/** Where the end label sits for a side: beside (x, y) as its alignment says, 9 above or below. */
private fun endBox(x: Float, y: Float, align: String, below: Boolean, w: Float, h: Float, lw: Float, lh: Float, gap: Float): Rect {
    val px = x / 100f * w
    val py = y / 100f * h
    val left = when (align) {
        "center" -> px - lw / 2f
        "start" -> px
        else -> px - lw
    }
    val top = if (below) py + gap else py - lh - gap
    return Rect(left, top, left + lw, top + lh)
}

/**
 * goal-chart.js settleEnd(): the preferred side if nothing drawn crosses the
 * label there (3 px of air), else the other side, else null — not shown.
 */
private fun settleEnd(chart: GoalChart, lines: List<Path>, plot: Size, label: IntSize, gap: Float, air: Float): Boolean? {
    val end = chart.end ?: return null
    val sx = plot.width / chart.width
    val sy = plot.height / chart.height
    val obstacles = ArrayList<Offset>()

    // Along every line and the target, every 6 view-box units.
    for (line in lines) {
        val measure = android.graphics.PathMeasure(line.asAndroidPath(), false)
        val at = FloatArray(2)
        do {
            var d = 0f
            val length = measure.length
            while (d <= length) {
                if (measure.getPosTan(d, at, null)) obstacles += Offset(at[0] * sx, at[1] * sy)
                d += 6f
            }
        } while (measure.nextContour())
    }
    chart.target?.let { target ->
        val ty = target.top / 100f * chart.height
        var x = 0f
        while (x <= chart.width) {
            obstacles += Offset(x * sx, ty * sy)
            x += 6f
        }
    }
    for (point in chart.points) if (point.dot) obstacles += Offset(point.x / 100f * plot.width, point.y / 100f * plot.height)

    fun clear(below: Boolean): Boolean {
        val r = endBox(end.x, end.y, end.align, below, plot.width, plot.height, label.width.toFloat(), label.height.toFloat(), gap)
        return obstacles.none { o -> o.x > r.left - air && o.x < r.right + air && o.y > r.top - air && o.y < r.bottom + air }
    }

    return when {
        clear(end.below) -> end.below
        clear(!end.below) -> !end.below
        else -> null
    }
}

/**
 * `.goal-chart__tip`: the date and the value, just above the plot, centred
 * over the point and kept inside the plot's width.
 */
@Composable
private fun Reading(point: ChartPoint, accent: Color) {
    val density = LocalDensity.current
    val shadows = LocalGraphicsContext.current.shadowContext
    Layout(
        content = {
            val shape = RoundedCornerShape(12.dp)
            Column(
                Modifier
                    .drawBehind {
                        drawBoxShadows(shape, listOf(BoxShadow(y = 10.dp, blur = 28.dp, color = Color.Black.copy(alpha = 0.32f))), shadows)
                    }
                    .clip(shape)
                    .background(Color(46, 42, 44).copy(alpha = 0.97f))
                    .border(1.dp, Ownify.GlassBorder, shape)
                    .cssPadding(PaddingValues(start = 11.dp, end = 11.dp, top = 4.dp, bottom = 5.dp), border = 1.dp),
                horizontalAlignment = Alignment.CenterHorizontally
            ) {
                T(point.date, OwnifyType.style(Ownify.FsTiny, color = Ownify.TextMuted, lineHeight = 1.25.em), maxLines = 1)
                T(point.value, OwnifyType.style(Ownify.FsSmall, FontWeight.Bold, lineHeight = 1.25.em, tabular = true), maxLines = 1)
            }
        },
        modifier = Modifier.clearAndSetSemantics { }
    ) { measurables, constraints ->
        val tip = measurables.first().measure(constraints.copy(minWidth = 0, minHeight = 0, maxWidth = Int.MAX_VALUE))
        val width = constraints.maxWidth
        layout(width, constraints.maxHeight) {
            val at = point.x / 100f * width - tip.width / 2f
            val left = if (tip.width >= width) (width - tip.width) / 2f else at.coerceIn(0f, (width - tip.width).toFloat())
            // bottom: calc(100% + 2px) — above the plot, over whatever is there.
            tip.place(left.toInt(), -tip.height - with(density) { 2.dp.roundToPx() })
        }
    }
}

/** `.goal-chart__y`: the value labels, each centred on its gridline. */
@Composable
private fun YTicks(chart: GoalChart, modifier: Modifier) {
    val style = OwnifyType.style(Ownify.FsTiny, color = Ownify.TextMuted, tabular = true)
    Layout(
        content = { chart.yTicks.forEach { T(it.label, style, maxLines = 1) } },
        modifier = modifier.clearAndSetSemantics { }
    ) { measurables, constraints ->
        val placeables = measurables.map { it.measure(constraints.copy(minWidth = 0, minHeight = 0, maxWidth = Int.MAX_VALUE)) }
        layout(constraints.maxWidth, constraints.maxHeight) {
            placeables.forEachIndexed { i, p ->
                val top = chart.yTicks[i].top / 100f * constraints.maxHeight - p.height / 2f
                p.place(constraints.maxWidth - p.width, top.toInt())
            }
        }
    }
}

/** `.goal-chart__x`: the dates, each at its spot, aligned start, centre or end as the server says. */
@Composable
private fun XTicks(chart: GoalChart, modifier: Modifier) {
    val density = LocalDensity.current
    val style = OwnifyType.style(Ownify.FsTiny, color = Ownify.TextMuted)
    val height = with(density) { (Ownify.FsBody.toPx() * 1.2f).toDp() }
    Layout(
        content = { chart.xTicks.forEach { T(it.label, style, maxLines = 1) } },
        modifier = modifier.fillMaxWidth().height(height).clearAndSetSemantics { }
    ) { measurables, constraints ->
        val placeables = measurables.map { it.measure(constraints.copy(minWidth = 0, minHeight = 0, maxWidth = Int.MAX_VALUE)) }
        layout(constraints.maxWidth, constraints.maxHeight) {
            placeables.forEachIndexed { i, p ->
                val tick = chart.xTicks[i]
                val left = tick.left / 100f * constraints.maxWidth
                val x = when (tick.align) {
                    "start" -> left
                    "end" -> left - p.width
                    else -> left - p.width / 2f
                }
                p.place(x.toInt(), 0)
            }
        }
    }
}
