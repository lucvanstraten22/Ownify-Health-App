package com.ownify.android.ui.screens.health

import com.ownify.android.i18n.trf
import com.ownify.android.i18n.tr
import androidx.compose.foundation.background
import androidx.compose.foundation.gestures.awaitEachGesture
import androidx.compose.foundation.gestures.awaitFirstDown
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.runtime.Composable
import androidx.compose.runtime.setValue
import androidx.compose.runtime.getValue
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
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
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.em
import androidx.compose.ui.unit.sp
import com.ownify.android.data.SleepNight
import androidx.compose.foundation.layout.Spacer
import androidx.compose.runtime.key
import androidx.compose.runtime.saveable.rememberSaveable
import com.ownify.android.data.SleepStackPeriod
import com.ownify.android.ui.design.HistoryPlot
import com.ownify.android.ui.design.Legend
import com.ownify.android.ui.design.LegendItem
import com.ownify.android.ui.design.PlotBars
import com.ownify.android.ui.design.RangeSwitch
import com.ownify.android.ui.theme.Accent
import com.ownify.android.ui.app.LocalOwnedAreas
import com.ownify.android.ui.app.ownsGestures
import com.ownify.android.ui.design.JCard
import com.ownify.android.ui.design.JStyle
import com.ownify.android.ui.design.ReadingTip
import com.ownify.android.ui.design.T
import com.ownify.android.ui.design.TickAxis
import com.ownify.android.ui.design.reveal
import com.ownify.android.ui.theme.Ownify
import com.ownify.android.ui.theme.OwnifyType
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import com.ownify.android.ui.screens.research.ResearchEvidence
import com.ownify.android.data.Evidence

/*
 * Slaap, drawn (docs/SLEEP.md) — the website's components/sleep-view.php
 * and components/sleep-night.php: the last night's stages as a timeline,
 * then its four charts over time two by two (AreaCharts.kt). Everything
 * shown is the server's (lib/hydrate-sleep.php).
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

// ---------------------------------------------------------------------------
// The night
// ---------------------------------------------------------------------------

/**
 * `components/sleep-night.php` (40.1): Training's heart-rate switch over the
 * night. Vandaag shows one night — this morning's and the six before it, an
 * arrow either side and a swipe, opened on the newest night there is: its
 * name, how long was slept and how much of the time in bed, then Wakker,
 * Rusteloosheid, REM, Licht and Diep as rows, each recorded period of a stage
 * a block on its row from bedtime to wake time; a finger reads the period
 * there. 7 dagen to 1 jaar show each night's stages as one bar, Diep at the
 * bottom to Wakker at the top, read as every chart over time is.
 */
/** [evidence]: how far a wearable's stages can be trusted (docs/INSIGHTS.md), beside a night that has them. */
@Composable
fun SleepNightCard(night: SleepNight, evidence: Evidence? = null, ageEvidence: Evidence? = null) {
    val nights = night.nights.ifEmpty { listOf(night) }
    val opened = nights.indexOfFirst { it.key == night.defaultKey }.coerceAtLeast(0)
    val last = nights.lastIndex
    var selected by rememberSaveable { mutableStateOf(night.defaultKey) }
    var day by rememberSaveable { mutableIntStateOf(opened) }
    val period = night.periods.firstOrNull { it.period.key == selected }
    val switched = night.options.isNotEmpty()
    val step = { by: Int -> day = (day + by).coerceIn(0, last) }
    val one = nights[day.coerceIn(0, last)]
    val filled = nights.any { it.staged } || night.periods.any { it.period.hasData }

    JCard(Modifier.fillMaxWidth().reveal()) {
        T(night.title, JStyle.Eyebrow, Modifier.semantics { heading() })
        // From a server before 40.1: no switch, the night's date under the title as before.
        if (night.options.isEmpty()) night.date?.let { T(it, JStyle.Tiny, Modifier.padding(top = Ownify.Space1, bottom = Ownify.Space4)) }

        if (switched) {
            // Five options, closer together than four (`.heart-chart__switch`).
            RangeSwitch(
                night.options,
                selected,
                { key ->
                    selected = key
                    if (key == night.defaultKey) day = opened          // Vandaag again: the newest night
                },
                Modifier.padding(top = Ownify.Space4),
                wide = true,
                optionPadding = 4.dp,
                label = night.switchLabel
            )
        }

        if (period == null) {
            if (switched) DayRow(one.heading ?: one.date.orEmpty(), night.prev, night.next, day, last, step)
            Column(Modifier.fillMaxWidth().padding(top = if (switched) Ownify.Space3 else 0.dp).then(if (switched) Modifier.flicks { by -> step(by) } else Modifier)) {
                NightBody(one)
            }
        } else {
            StagePlot(night, period)
        }

        if (filled) {
            // How far a wearable's stages can be trusted, and (31.0, D9) that deep sleep and efficiency change with age.
            ResearchEvidence(listOf(evidence, ageEvidence))
            T(if (period == null) night.hint else night.periodHint, JStyle.Footnote, Modifier.fillMaxWidth().padding(top = Ownify.Space3), align = TextAlign.Center)
        }
    }
}

/** One night: its sum under its name, its rows and blocks, the times under them, and its line. */
@Composable
private fun NightBody(night: SleepNight) {
    night.asleep?.let { asleep ->
        // `.sleep-night__range .sleep-night__sum`: centred under the night's name.
        Column(Modifier.fillMaxWidth().padding(bottom = Ownify.Space4), horizontalAlignment = Alignment.CenterHorizontally) {
            Row {
                T(asleep, OwnifyType.style(Ownify.FsTitle, FontWeight.Bold, lineHeight = 1.1.em, tabular = true), Modifier.alignByBaseline())
                T(tr("u"), OwnifyType.style(Ownify.FsSubhead, FontWeight.Normal, Ownify.TextMuted, lineHeight = 1.1.em), Modifier.alignByBaseline().padding(start = 3.dp))
            }
            T(
                night.asleepLabel + (night.efficiency?.let { " · $it% ${night.efficiencyLabel}" } ?: ""),
                OwnifyType.style(Ownify.FsCaption, color = Ownify.TextMuted),
                Modifier.padding(top = 2.dp),
                maxLines = 1
            )
        }
    }

    Row(Modifier.fillMaxWidth()) {
        // `.sleep-night__labels`: each stage's name and time, its colour beside it.
        Column(Modifier.width(LabelWidth)) {
            night.rows.forEach { row ->
                Row(Modifier.height(RowHeight).fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                    Box(Modifier.size(6.dp).clip(RoundedCornerShape(2.dp)).background(stageColor(row.key)))
                    Column(Modifier.padding(start = 6.dp)) {
                        T(row.label, OwnifyType.style(Ownify.FsCaption, FontWeight.SemiBold, Ownify.TextSecondary), maxLines = 1, ellipsis = true)
                        row.total?.let { T(it, OwnifyType.style(Ownify.FsCaption, color = Ownify.TextMuted, tabular = true), maxLines = 1) }
                    }
                }
            }
        }
        key(night.key) { NightPlot(night, Modifier.padding(start = Ownify.Space3).weight(1f)) }
    }

    if (night.ticks.isNotEmpty()) {
        // Bedtime at the left edge, wake time at the right, hours between.
        TickAxis(night.ticks, Modifier.padding(start = LabelWidth + Ownify.Space3, top = Ownify.Space2))
    }
    night.note?.let {
        T(it, OwnifyType.style(Ownify.FsSubhead, color = Ownify.TextMuted), Modifier.fillMaxWidth().padding(top = Ownify.Space4), align = TextAlign.Center)
    }
}

/**
 * 7 dagen to 1 jaar: each night's stages as one bar (`.sleep-night__stacks`),
 * the Verloop's plot 160 dp tall with its levels in hours, each stage in its
 * own colour — a part square where the next sits on it, the top one rounded —
 * the reading each stage's time, and the stages named under it.
 */
@Composable
private fun StagePlot(night: SleepNight, stacked: SleepStackPeriod) {
    val period = stacked.period
    val stages = night.stages
    Box(Modifier.fillMaxWidth().padding(top = Ownify.Space4)) {
        HistoryPlot(
            key = "sleep-stages" + period.key,
            viewBox = Size(period.width, period.height),
            xs = period.x,
            lines = emptyList(),
            filled = period.hasData,
            dayDots = false,
            small = false,
            carried = { false },
            axis = period.axis,
            centredAxis = true,
            aria = period.aria,
            readable = { i -> period.points.getOrNull(i) != null },
            describe = { i ->
                period.points.getOrNull(i)?.let { p ->
                    // Each point's texts: the night's whole time, then each stage's.
                    val said = listOfNotNull(p.texts.getOrNull(0)?.let { "${night.periodTotal} $it" }) +
                        stages.reversed().mapIndexedNotNull { k, (_, label) -> p.texts.getOrNull(k + 1)?.let { "$label $it" } }
                    p.label + (p.detail?.let { ", $it" } ?: "") + ": " + if (said.isEmpty()) p.note ?: "—" else said.joinToString(", ")
                }.orEmpty()
            },
            empty = night.periodEmpty,
            modifier = Modifier,
            onRead = { },
            height = 160.dp,
            levels = period.grid,
            gutter = if (period.grid.isNotEmpty()) 34.dp else 0.dp,
            bars = stages.map { (stage, _) ->
                PlotBars(
                    stageColor(stage),
                    stacked.w,
                    stacked.stacks.map { parts -> parts.firstOrNull { it.stage == stage }?.top },
                    bottom = stacked.stacks.map { parts -> parts.firstOrNull { it.stage == stage }?.bottom },
                    roundTop = stacked.stacks.map { parts -> parts.lastOrNull()?.stage == stage },
                    focusDot = false
                )
            }
        ) { i ->
            // `.health-history__tip`: the night or the days, then each stage's time.
            val p = period.points[i]
            val total = p.texts.getOrNull(0)
            // Top to bottom, as the bar, the legend and Vandaag's rows: Wakker first.
            val values = stages.reversed().mapIndexedNotNull { k, s -> p.texts.getOrNull(k + 1)?.let { s to it } }
            val detail = p.detail?.takeIf { values.isNotEmpty() }
            Row {
                T(p.label + if (detail != null) " · " else "", OwnifyType.style(Ownify.FsCaption, color = Ownify.TextMuted), maxLines = 1)
                detail?.let { T(it, OwnifyType.style(Ownify.FsCaption, color = Ownify.TextSecondary), maxLines = 1) }
            }
            if (values.isEmpty()) {
                p.note?.let { T(it, OwnifyType.style(Ownify.FsCaption, color = Ownify.TextMuted), maxLines = 1) }
            } else {
                // The night's whole time first (`.sleep-night__tip-total`), then each stage.
                total?.let {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        // No colour of its own: the key's place kept empty, so the name lines up with the stages'.
                        Spacer(Modifier.size(8.dp))
                        T(night.periodTotal, OwnifyType.style(Ownify.FsCaption, color = Ownify.TextSecondary), Modifier.padding(start = 6.dp), maxLines = 1)
                        Spacer(Modifier.width(8.dp))
                        T(it, OwnifyType.style(Ownify.FsSubhead, FontWeight.Bold, tabular = true), maxLines = 1)
                    }
                }
                for ((s, text) in values) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Box(Modifier.size(8.dp).clip(RoundedCornerShape(2.dp)).background(stageColor(s.first)))
                        T(s.second, OwnifyType.style(Ownify.FsCaption, color = Ownify.TextSecondary), Modifier.padding(start = 6.dp), maxLines = 1)
                        Spacer(Modifier.width(8.dp))
                        T(text, OwnifyType.style(Ownify.FsSubhead, FontWeight.Bold, tabular = true), maxLines = 1)
                    }
                }
            }
        }
    }
    if (period.hasData) {
        // The stages named, top to bottom as the bar has them: no stage told by its colour alone.
        Legend(stages.reversed().map { (key, label) -> LegendItem(label, null, Accent.of("sleep"), dot = stageColor(key)) }, Modifier.padding(top = Ownify.Space4), values = false, columns = 3)
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

    fun spoken(i: Int) = blocks.getOrNull(i)?.let { b -> night.rows.getOrNull(b.row)?.label.orEmpty() + ", " + trf("%1\$s tot %2\$s", b.began, b.ended) }.orEmpty()

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
                            CustomAccessibilityAction(tr("Volgende fase")) { show(if (reading < 0) 0 else minOf(blocks.lastIndex, reading + 1)); true },
                            CustomAccessibilityAction(tr("Vorige fase")) { show(if (reading < 0) 0 else maxOf(0, reading - 1)); true },
                            CustomAccessibilityAction(tr("Fase verbergen")) { hide(); true }
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
                T(night.rows.getOrNull(b.row)?.label.orEmpty(), OwnifyType.style(Ownify.FsSubhead, FontWeight.Bold), maxLines = 1)
                T("${b.began} – ${b.ended}", OwnifyType.style(Ownify.FsCaption, color = Ownify.TextMuted, tabular = true), maxLines = 1)
            }
        }
    }
}
