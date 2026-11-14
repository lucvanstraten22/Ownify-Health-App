package com.ownify.android.ui.design

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.focusable
import androidx.compose.foundation.gestures.snapping.rememberSnapFlingBehavior
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.derivedStateOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.runtime.snapshotFlow
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.drawWithContent
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.graphics.BlendMode
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.CompositingStrategy
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.hapticfeedback.HapticFeedbackType
import androidx.compose.ui.layout.onGloballyPositioned
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.platform.LocalHapticFeedback
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.setText
import androidx.compose.ui.semantics.insertTextAtCursor
import androidx.compose.ui.semantics.stateDescription
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.IntOffset
import androidx.compose.ui.unit.IntRect
import androidx.compose.ui.unit.IntSize
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.window.Popup
import androidx.compose.ui.window.PopupPositionProvider
import androidx.compose.ui.window.PopupProperties
import com.ownify.android.data.GoalUnit
import com.ownify.android.i18n.OwnifyFormat
import com.ownify.android.i18n.OwnifyLanguage
import com.ownify.android.i18n.tr
import com.ownify.android.ui.theme.InButton
import com.ownify.android.ui.theme.Ownify
import com.ownify.android.ui.theme.OwnifyType
import kotlinx.coroutines.launch
import java.time.LocalDate
import java.time.YearMonth
import kotlin.math.abs
import kotlin.math.ceil
import kotlin.math.floor
import kotlin.math.roundToInt
import kotlin.math.roundToLong

/**
 * The wheel (48.0, docs/THEME.md "The wheel") — the website's
 * assets/js/wheel-picker.js, the same in the app: every number, duration,
 * time of day, date and list is picked on it, never a system picker. A tap
 * on the field floats the thick Liquid Glass from it (Panes.Panel), a column
 * per part — hours and minutes; hours : minutes; day, month, year; the whole
 * and its fraction; one column of choices — the middle row a Well band, the
 * value in the field as it turns (a list's choice when it closes). A tap
 * anywhere outside closes it, and does nothing else.
 */
data class WheelSpec(
    val kind: String = "number",       // number, duration (whole minutes), clock ("HH:MM"), date ("YYYY-MM-DD"), list
    val min: Double = 0.0,
    val max: Double = 1000.0,
    val step: Double = 1.0,
    val start: Double? = null,          // a number's or duration's value when the field is empty
    val unit: String = "",
    val first: String? = null,          // a date's earliest, "YYYY-MM-DD"
    val last: String? = null,           // a date's latest
    val startText: String? = null,      // a clock's or date's start when empty
    val options: List<Pair<String, String>> = emptyList()   // a list's (value, label)
) {
    companion object {
        /** The server's spec (`wheel` beside a field: lib/settings.php, hydrate_goal_wheel()). */
        fun parse(o: org.json.JSONObject?): WheelSpec? = o?.let {
            val kind = it.optString("kind", "number")
            val text = { key: String -> if (it.has(key) && !it.isNull(key)) it.optString(key) else null }
            WheelSpec(
                kind = kind,
                min = if (kind == "date") 0.0 else it.optDouble("min", 0.0),
                max = if (kind == "date") 0.0 else it.optDouble("max", 1000.0),
                step = it.optDouble("step", 1.0),
                start = if (kind != "date" && kind != "clock" && it.has("default") && !it.isNull("default")) it.optDouble("default") else null,
                unit = it.optString("unit", ""),
                first = if (kind == "date") text("min") else null,
                last = if (kind == "date") text("max") else null,
                startText = if (kind == "date" || kind == "clock") text("default") else null
            )
        }

        fun clock(start: String = "12:00") = WheelSpec(kind = "clock", startText = start)

        fun date(first: String?, last: String?, start: String? = null) = WheelSpec(kind = "date", first = first, last = last, startText = start)

        fun list(options: List<Pair<String, String>>) = WheelSpec(kind = "list", options = options)
    }
}

object Wheel {
    const val ROWS_MAX = 400               // a column never longer: a long range turns in bigger steps

    /** One column: its values, their labels, the row it stands on, a unit beside it, a ":" after it. */
    data class Column(val values: List<Double>, val labels: List<String>, val index: Int, val unit: String = "", val sep: String = "", val part: String = "")

    private fun round(v: Double) = (v * 10_000).roundToLong() / 10_000.0

    private fun range(from: Double, to: Double, step: Double): List<Double> {
        val out = mutableListOf<Double>()
        var i = 0
        while (true) {
            val v = round(from + i * step)
            if (v > to + 1e-9 || i > 5000) break
            out += v
            i++
        }
        return out
    }

    /** Decimals a step needs: 0,5 → 1; 0,25 → 2; 500 → 0. */
    fun decimalsOf(step: Double): Int {
        val s = step.toBigDecimal().stripTrailingZeros().toPlainString()
        return if ('.' in s) s.length - s.indexOf('.') - 1 else 0
    }

    private fun pad(n: Int) = n.toString().padStart(2, '0')

    private fun nearest(values: List<Double>, v: Double) = values.indices.minByOrNull { abs(values[it] - v) } ?: 0

    /** A goal unit's wheel (config/goals.php `wheel`), around where it starts: a total above its top gets room to twice it. */
    fun forUnit(unit: GoalUnit, start: Double?): WheelSpec {
        val (lo, hi0, step) = unit.wheel
        val hi = if (start != null && start * 1.5 > hi0) ceil(start * 2 / step) * step else hi0
        return WheelSpec(min = lo, max = hi, step = step, start = start, unit = unit.label)
    }

    /** The columns for [spec] standing on [current] (the field's text), as the website's. */
    fun columns(spec: WheelSpec, current: String): List<Column> {
        when (spec.kind) {
            "clock", "duration" -> {
                val clock = spec.kind == "clock"
                val total = if (clock) {
                    val m = Regex("""^(\d{1,2}):(\d{2})""").find(current) ?: Regex("""^(\d{1,2}):(\d{2})""").find(spec.startText ?: "12:00")
                    m?.let { it.groupValues[1].toInt() * 60 + it.groupValues[2].toInt() } ?: 720
                } else {
                    (OwnifyFormat.parseNumber(current) ?: spec.start ?: 30.0).roundToInt().coerceIn(spec.min.toInt(), spec.max.toInt())
                }
                val step = spec.step.toInt().coerceAtLeast(1)
                val top = if (clock) 23 else spec.max.toInt() / 60
                val hours = range(0.0, top.toDouble(), 1.0)
                val minutes = range(0.0, (60 - step).toDouble(), step.toDouble())
                return listOf(
                    Column(hours, hours.map { if (clock) pad(it.toInt()) else it.toInt().toString() }, (total / 60).coerceAtMost(top),
                        unit = if (clock) "" else tr("u"), sep = if (clock) ":" else ""),
                    Column(minutes, minutes.map { pad(it.toInt()) }, ((total % 60) / step).coerceAtMost(minutes.size - 1), unit = if (clock) "" else tr("min"))
                )
            }
            "list" -> {
                val at = spec.options.indexOfFirst { it.first == current }.coerceAtLeast(0)
                return listOf(Column(spec.options.indices.map { it.toDouble() }, spec.options.map { it.second }, at))
            }
            "date" -> {
                val today = LocalDate.now()
                val lo = spec.first?.let { runCatching { LocalDate.parse(it) }.getOrNull() } ?: today.minusYears(120)
                val hi = spec.last?.let { runCatching { LocalDate.parse(it) }.getOrNull() } ?: today
                val at = runCatching { LocalDate.parse(current) }.getOrNull()
                    ?: spec.startText?.let { runCatching { LocalDate.parse(it) }.getOrNull() } ?: hi
                val days = range(1.0, YearMonth.of(at.year, at.monthValue).lengthOfMonth().toDouble(), 1.0)
                val months = range(1.0, 12.0, 1.0)
                val years = range(lo.year.toDouble(), hi.year.toDouble(), 1.0)
                val day = Column(days, days.map { it.toInt().toString() }, at.dayOfMonth - 1, part = "d")
                val month = Column(months, months.map { OwnifyFormat.month(it.toInt(), short = true) }, at.monthValue - 1, part = "m")
                val year = Column(years, years.map { it.toInt().toString() }, (at.year - lo.year).coerceIn(0, years.size - 1), part = "y")
                return if (OwnifyLanguage.english) listOf(month, day, year) else listOf(day, month, year)
            }
        }

        var step = spec.step.takeIf { it > 0 } ?: 1.0
        var d = decimalsOf(step)
        val v = (OwnifyFormat.parseNumber(current) ?: spec.start ?: spec.min).coerceIn(spec.min, spec.max)

        /* A fine step over a long range: the whole and its fraction, each a column (72 | ,5 kg). */
        if (step < 1 && (spec.max - spec.min) / step > ROWS_MAX) {
            val wholes = range(floor(spec.min), floor(spec.max), 1.0)
            val parts = range(0.0, round(1 - step), step)
            val whole = floor(v)
            val sep = OwnifyFormat.number(1.5, 1).substring(1, 2)
            return listOf(
                Column(wholes, wholes.map { OwnifyFormat.number(it, 0) }, nearest(wholes, whole)),
                Column(parts, parts.map { sep + OwnifyFormat.number(it, d).substringAfter(sep) }, nearest(parts, v - whole), unit = spec.unit)
            )
        }
        while ((spec.max - spec.min) / step > ROWS_MAX) { step *= if (step.toString().first() == '2') 2.5 else 2.0; d = decimalsOf(step) }
        val values = range(ceil(spec.min / step - 1e-9) * step, spec.max, step)
        return listOf(Column(values, values.map { OwnifyFormat.number(it, d) }, nearest(values, v), unit = spec.unit))
    }

    /** What the columns stand on at [indexes], as the field takes it. */
    fun written(spec: WheelSpec, cols: List<Column>, indexes: List<Int>): String {
        val at = { n: Int -> cols[n].values[indexes[n].coerceIn(0, cols[n].values.size - 1)] }
        return when (spec.kind) {
            "clock" -> pad(at(0).toInt()) + ":" + pad(at(1).toInt())
            "duration" -> (at(0) * 60 + at(1)).toInt().coerceIn(spec.min.toInt(), spec.max.toInt()).toString()
            "list" -> spec.options.getOrNull(at(0).toInt())?.first.orEmpty()
            "date" -> {
                val p = cols.indices.associate { cols[it].part to at(it).toInt() }
                val y = p.getValue("y"); val m = p.getValue("m")
                var out = LocalDate.of(y, m, p.getValue("d").coerceAtMost(YearMonth.of(y, m).lengthOfMonth()))
                spec.first?.let { runCatching { LocalDate.parse(it) }.getOrNull() }?.let { if (out < it) out = it }
                spec.last?.let { runCatching { LocalDate.parse(it) }.getOrNull() }?.let { if (out > it) out = it }
                out.toString()
            }
            else -> {
                val v = round(at(0) + if (cols.size > 1) at(1) else 0.0).coerceIn(spec.min, spec.max)
                OwnifyFormat.number(v, decimalsOf(spec.step.takeIf { cols.size > 1 } ?: cols[0].values.zipWithNext { a, b -> b - a }.firstOrNull() ?: spec.step), grouping = false)
            }
        }
    }

    /** The field's text as a person reads it: a date as 10 nov 2026, a list's choice by its name. */
    fun shown(spec: WheelSpec, value: String): String = when (spec.kind) {
        "date" -> runCatching { OwnifyFormat.date(LocalDate.parse(value), "day_mon_year") }.getOrDefault(value)
        "list" -> spec.options.firstOrNull { it.first == value }?.second ?: spec.options.firstOrNull()?.second.orEmpty()
        else -> value
    }
}

private val ROW: Dp = 36.dp

/** Floats the wheel from its field: below it, above when there is no room. */
private class Below(private val gap: Int) : PopupPositionProvider {
    override fun calculatePosition(anchor: IntRect, window: IntSize, layoutDirection: LayoutDirection, popup: IntSize): IntOffset {
        val x = anchor.left.coerceIn(gap, (window.width - popup.width - gap).coerceAtLeast(gap))
        val below = anchor.bottom + gap
        val y = if (below + popup.height <= window.height - gap || anchor.top - gap - popup.height < gap) below else anchor.top - gap - popup.height
        return IntOffset(x, y.coerceAtLeast(gap))
    }
}

/**
 * The wheel itself, floating from the field it is anchored to. [onValue]
 * hears every turn ([commitOnClose]: only the last one, when it closes — a
 * list's choice); [onClose] when a tap outside closes it.
 */
@Composable
fun WheelPopup(spec: WheelSpec, value: String, anchorWidth: Dp, onValue: (String) -> Unit, onClose: () -> Unit, commitOnClose: Boolean = false) {
    val density = LocalDensity.current
    var current by remember { mutableStateOf(value) }
    // Empty: the sensible start, in the field at once.
    LaunchedEffect(Unit) {
        if (value.isEmpty() && !commitOnClose) {
            val cols = Wheel.columns(spec, "")
            current = Wheel.written(spec, cols, cols.map { it.index })
            onValue(current)
        }
    }
    val cols = Wheel.columns(spec, current)
    val shape = RoundedCornerShape(Ownify.RadiusMd)

    Popup(
        popupPositionProvider = remember { Below(with(density) { 8.dp.roundToPx() }) },
        onDismissRequest = {
            if (commitOnClose && current != value) onValue(current)
            onClose()
        },
        properties = PopupProperties(focusable = true)
    ) {
        Box(
            Modifier
                .width(anchorWidth.coerceIn(240.dp, 320.dp))
                .liquidGlass(shape, Panes.Panel)
                .padding(Ownify.Space3)
        ) {
            // The row it stands on: a Well band.
            Box(
                Modifier
                    .align(Alignment.Center)
                    .fillMaxWidth()
                    .height(ROW)
                    .clip(RoundedCornerShape(Ownify.RadiusSm))
                    .background(Ownify.fill(0.07f))
            )
            Row(
                Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.spacedBy(Ownify.Space2, Alignment.CenterHorizontally),
                verticalAlignment = Alignment.CenterVertically
            ) {
                cols.forEachIndexed { n, col ->
                    Row(
                        if (spec.kind == "list") Modifier.weight(1f) else Modifier,
                        verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.spacedBy(Ownify.Space1)
                    ) {
                        WheelColumn(col, wide = spec.kind == "list" || col.part == "m", key = "${col.part}${col.values.size}") { index ->
                            val text = Wheel.written(spec, cols, cols.mapIndexed { i, c -> if (i == n) index else c.index })
                            if (text != current) {
                                current = text
                                if (!commitOnClose) onValue(text)
                            }
                        }
                        if (col.unit.isNotEmpty()) T(col.unit, OwnifyType.style(Ownify.FsSubhead, color = Ownify.TextMuted), maxLines = 1)
                    }
                    if (col.sep.isNotEmpty()) T(col.sep, OwnifyType.style(Ownify.FsTitle, FontWeight.SemiBold))
                }
            }
        }
    }
}

/** One column: native fling with snapping to a row, a tick as each row passes, a tap on a row turns to it. */
@Composable
private fun WheelColumn(col: Wheel.Column, wide: Boolean, key: String, onIndex: (Int) -> Unit) {
    val state = rememberLazyListState(initialFirstVisibleItemIndex = col.index)
    val scope = rememberCoroutineScope()
    val haptics = LocalHapticFeedback.current
    val rowPx = with(LocalDensity.current) { ROW.toPx() }
    val picked by remember(key) {
        derivedStateOf { (state.firstVisibleItemIndex + if (state.firstVisibleItemScrollOffset > rowPx / 2) 1 else 0).coerceIn(0, col.values.size - 1) }
    }
    LaunchedEffect(key) {
        snapshotFlow { picked }.collect { i ->
            if (i != col.index) {
                haptics.performHapticFeedback(HapticFeedbackType.TextHandleMove)
                onIndex(i)
            }
        }
    }
    // Turned past what may be chosen (a date after today): back to the nearest allowed.
    LaunchedEffect(col.index, key) {
        if (!state.isScrollInProgress && picked != col.index) state.animateScrollToItem(col.index)
    }
    LazyColumn(
        state = state,
        flingBehavior = rememberSnapFlingBehavior(state),
        contentPadding = PaddingValues(vertical = ROW * 2),
        modifier = Modifier
            .height(ROW * 5)
            .widthIn(min = if (wide) 64.dp else 48.dp)
            .graphicsLayer(compositingStrategy = CompositingStrategy.Offscreen)
            .drawWithContent {
                drawContent()
                drawRect(
                    Brush.verticalGradient(0f to Color.Transparent, 0.32f to Color.Black, 0.68f to Color.Black, 1f to Color.Transparent),
                    blendMode = BlendMode.DstIn
                )
            }
            .semantics { stateDescription = col.labels.getOrElse(picked) { "" } + if (col.unit.isNotEmpty()) " ${col.unit}" else "" }
    ) {
        itemsIndexed(col.labels) { i, label ->
            Box(
                Modifier
                    .height(ROW)
                    .fillMaxWidth()
                    .clickable(remember { MutableInteractionSource() }, indication = null) { scope.launch { state.animateScrollToItem(i) } },
                contentAlignment = Alignment.Center
            ) {
                T(label, OwnifyType.style(Ownify.FsTitle, color = Ownify.TextPrimary, tabular = true), maxLines = 1, align = TextAlign.Center)
            }
        }
    }
}

/**
 * The pill field with the wheel: the value as a person reads it, a tap
 * floats the wheel from it. Every number, duration, time and date field.
 */
@Composable
fun WheelField(
    value: String,
    onValue: (String) -> Unit,
    spec: WheelSpec,
    modifier: Modifier = Modifier,
    label: String? = null,
    placeholder: String = "",
    focus: FocusRequester = remember { FocusRequester() },
    /** 50.6: the value given only when the wheel closes (a choice that is saved), as a list on the website. */
    commitOnClose: Boolean = false
) {
    var open by remember { mutableStateOf(false) }
    var width by remember { mutableStateOf(0.dp) }
    val density = LocalDensity.current
    val shape = RoundedCornerShape(50)
    val shown = if (value.isEmpty()) "" else Wheel.shown(spec, value)
    InButton {
        Box(
            modifier
                .heightIn(min = 44.dp)
                .onGloballyPositioned { width = with(density) { it.size.width.toDp() } }
                .clip(shape)
                .background(if (open) Ownify.fill(0.07f) else Ownify.fill(0.05f))
                .border(1.dp, if (open) Ownify.HealthStrong else Ownify.GlassBorderSoft, shape)
                .focusRequester(focus)
                .focusable()
                .clickable(remember { MutableInteractionSource() }, indication = null, role = Role.Button, onClickLabel = label) { open = true }
                .semantics {
                    if (label != null) contentDescription = label
                    stateDescription = shown.ifEmpty { placeholder }
                    // An exact value from assistive technology (TalkBack's text entry): the field takes it as typed.
                    setText { onValue(it.text); true }
                    insertTextAtCursor { onValue(value + it.text); true }
                }
                .padding(horizontal = Ownify.Space4),
            contentAlignment = Alignment.CenterStart
        ) {
            T(shown.ifEmpty { placeholder }, OwnifyType.style(Ownify.FsInput, color = if (shown.isEmpty()) Ownify.TextMuted else Ownify.TextPrimary, tabular = true), maxLines = 1)
            if (open) WheelPopup(spec, value, width, onValue, onClose = { open = false }, commitOnClose = commitOnClose)
        }
    }
}

/** 47.0's name, kept: every number and duration field is a [WheelField] (48.0: no keyboard — the wheel is the input). */
@Composable
fun WheelInput(
    value: String,
    onValueChange: (String) -> Unit,
    spec: WheelSpec,
    modifier: Modifier = Modifier,
    placeholder: String = "",
    label: String? = null,
    @Suppress("UNUSED_PARAMETER") keyboard: KeyboardOptions = KeyboardOptions.Default,
    @Suppress("UNUSED_PARAMETER") actions: KeyboardActions = KeyboardActions.Default,
    focus: FocusRequester = remember { FocusRequester() }
) = WheelField(value, onValueChange, spec, modifier.fillMaxWidth(), label, placeholder, focus)
