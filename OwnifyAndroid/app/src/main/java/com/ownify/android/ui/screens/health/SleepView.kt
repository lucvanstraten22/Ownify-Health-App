package com.ownify.android.ui.screens.health

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
