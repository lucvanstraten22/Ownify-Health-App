package com.ownify.android.ui.design

import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.gestures.awaitEachGesture
import androidx.compose.foundation.gestures.awaitFirstDown
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.Immutable
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
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Color
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
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.stateDescription
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import com.ownify.android.data.CompassTick
import com.ownify.android.ui.app.LocalOwnedAreas
import com.ownify.android.ui.app.ownsGestures
import com.ownify.android.ui.theme.Ownify
import com.ownify.android.ui.theme.OwnifyType
import kotlin.math.abs
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/** How long a touch reading stays after the finger lifts (compass-history.js LINGER). */
private const val READ_LINGER_MS = 1600L

/**
 * One line of a history chart: its colour, its paths and washes in the
 * chart's box, and each day's height on it in % from the top — null for a
 * day without a score.
 */
@Immutable
class PlotLine(val color: Color, val paths: List<String>, val washes: List<String>, val y: List<Float?>)

/**
 * A score's history, read with a finger (`.compass-plot`, compass-history.js)
 * — the Scorekompas's one line and Gezondheid's three: the grid, the washes,
 * the lines drawn on whenever the period ([key]) is shown, a dot for every
 * day ([dayDots], [small] where a month's days must stay apart) or for a day
 * no line reaches — a ring where the day's scores were [carried] — and the
 * dates under it. A finger on it reads a day, as on a goal's Verloop: the
 * nearest day, a crosshair, a dot on each line that had a score that day,
 * and [tip] above the chart; [onRead] hears which (an index into [xs], each
 * day's place in % from the left). A vertical drag still scrolls.
 */
@Composable
fun HistoryPlot(
    key: String,
    viewBox: Size,
    xs: List<Float>,
    lines: List<PlotLine>,
    filled: Boolean,
    dayDots: Boolean,
    small: Boolean,
    carried: (Int) -> Boolean,
    axis: List<CompassTick>,
    centredAxis: Boolean,
    aria: String,
    readable: (Int) -> Boolean,
    describe: (Int) -> String,
    empty: String,
    modifier: Modifier,
    onRead: (Int) -> Unit,
    tip: @Composable ColumnScope.(Int) -> Unit
) {
    val still = LocalStillMotion.current
    val view = LocalView.current
    val owned = LocalOwnedAreas.current
    val scope = rememberCoroutineScope()
    val draw = remember { Animatable(if (still) 1f else 0f) }
    var seen by remember { mutableStateOf(still) }
    val paths = lines.map { rememberSvgPaths(it.paths) }
    val washes = lines.map { rememberSvgPaths(it.washes) }
    var reading by remember(key) { mutableIntStateOf(-1) }
    var linger by remember { mutableStateOf<Job?>(null) }
    val ownKey = remember { Any() }

    DisposableEffect(ownKey) { onDispose { owned.set(ownKey, null) } }

    LaunchedEffect(seen, key) {
        if (!seen || still) return@LaunchedEffect
        draw.snapTo(0f)
        draw.animateTo(1f, tween(900, easing = Ownify.EaseOut))
    }

    fun show(index: Int) {
        linger?.cancel()
        reading = index.coerceIn(0, xs.lastIndex)
        onRead(reading)
    }

    fun hide() {
        linger?.cancel()
        reading = -1
    }

    /** The day nearest to a horizontal position, in % of the plot. */
    fun nearest(percent: Float): Int {
        var best = 0
        var gap = Float.MAX_VALUE
        xs.forEachIndexed { i, x ->
            val d = abs(x - percent)
            if (d < gap) {
                gap = d
                best = i
            }
        }
        return best
    }

    val shown = reading >= 0 && readable(reading)

    Box(modifier.fillMaxWidth()) {
        Column(Modifier.fillMaxWidth()) {
            Box(
                Modifier
                    .fillMaxWidth()
                    .height(100.dp)
                    .then(
                        if (!filled || xs.isEmpty()) Modifier.clearAndSetSemantics { contentDescription = aria }
                        else Modifier
                            .ownsGestures(owned, ownKey)
                            .semantics {
                                contentDescription = aria
                                stateDescription = if (shown) describe(reading) else ""
                                liveRegion = LiveRegionMode.Polite
                                // The keyboard's arrows, for a screen reader: day by day.
                                customActions = listOf(
                                    CustomAccessibilityAction("Volgende dag") {
                                        show(if (reading < 0) xs.lastIndex else minOf(xs.lastIndex, reading + 1)); true
                                    },
                                    CustomAccessibilityAction("Vorige dag") {
                                        show(if (reading < 0) xs.lastIndex else maxOf(0, reading - 1)); true
                                    },
                                    CustomAccessibilityAction("Dag verbergen") { hide(); true }
                                )
                            }
                            .pointerInput(xs) {
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
                    lines.forEachIndexed { i, line -> washes[i].forEach { drawPath(it.stretched(viewBox, size), line.color.copy(alpha = 0.10f)) } }
                    lines.forEachIndexed { i, line -> paths[i].forEach { drawChartLine(it.stretched(viewBox, size), line.color, 2.2.dp.toPx(), draw.value) } }

                    // .compass-plot__dot: 7 across with a 2 px ring of the card (5 and 1.5 when
                    // small), never stretched; a carried day's is a ring.
                    for (line in lines) {
                        line.y.forEachIndexed { i, y ->
                            if (y == null) return@forEachIndexed
                            val alone = line.y.getOrNull(i - 1) == null && line.y.getOrNull(i + 1) == null
                            if (!dayDots && !alone) return@forEachIndexed
                            val c = Offset(xs[i] / 100f * size.width, y / 100f * size.height)
                            val dot = if (small) 2.5.dp.toPx() else 3.5.dp.toPx()
                            drawCircle(Ownify.BgSecondary, radius = dot + (if (small) 1.5.dp else 2.dp).toPx(), center = c)
                            drawCircle(line.color, radius = dot, center = c)
                            if (carried(i)) drawCircle(Ownify.BgSecondary, radius = if (small) 1.25.dp.toPx() else 2.dp.toPx(), center = c)
                        }
                    }

                    // The reading: the crosshair, and each line's point if it had a score that day.
                    xs.getOrNull(reading)?.let { x ->
                        val px = x / 100f * size.width
                        drawRect(Ownify.ink(0.28f), topLeft = Offset(px - 0.5.dp.toPx(), 0f), size = Size(1.dp.toPx(), size.height))
                        for (line in lines) {
                            val y = line.y.getOrNull(reading) ?: continue
                            val c = Offset(px, y / 100f * size.height)
                            drawCircle(Ownify.mix(line.color, 0.22f, Color.Transparent), radius = 12.dp.toPx(), center = c)
                            drawCircle(Ownify.BgSecondary, radius = 8.dp.toPx(), center = c)
                            drawCircle(line.color, radius = 6.dp.toPx(), center = c)
                        }
                    }
                }

                if (shown) xs.getOrNull(reading)?.let { x -> ReadingTip(x) { tip(reading) } }
            }
            TickAxis(axis, Modifier.padding(top = Ownify.Space2), centred = centredAxis)
        }

        if (!filled) {
            T(
                empty,
                OwnifyType.style(Ownify.FsSmall, color = Ownify.TextMuted),
                Modifier.fillMaxWidth().padding(top = 46.dp),
                align = TextAlign.Center
            )
        }
    }
}

/** `.goal-chart__tip`: the day read, just above the chart, kept inside its width. */
@Composable
fun ReadingTip(x: Float, content: @Composable ColumnScope.() -> Unit) {
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
                horizontalAlignment = Alignment.CenterHorizontally,
                content = content
            )
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

/**
 * `.chart__axis`: each date at its day — the first from the left edge, the
 * last to the right; [centred] (`.chart__axis--days`, a date under every
 * day) each centred under its own day.
 */
@Composable
fun TickAxis(ticks: List<CompassTick>, modifier: Modifier, centred: Boolean = false) {
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
                val x = when {
                    centred -> left - p.width / 2f
                    i == 0 -> left
                    i == placeables.lastIndex -> left - p.width
                    else -> left - p.width / 2f
                }
                p.place(x.toInt(), 0)
            }
        }
    }
}
