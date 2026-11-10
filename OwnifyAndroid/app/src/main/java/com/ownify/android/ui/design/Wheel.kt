package com.ownify.android.ui.design

import android.app.AlertDialog
import android.content.Context
import android.view.Gravity
import android.view.ViewGroup
import android.widget.LinearLayout
import android.widget.NumberPicker
import android.widget.TextView
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.focus.onFocusChanged
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalSoftwareKeyboardController
import androidx.compose.ui.semantics.Role
import com.ownify.android.i18n.OwnifyFormat
import com.ownify.android.i18n.tr
import com.ownify.android.ui.theme.OwnifyThemeStore
import kotlin.math.abs
import kotlin.math.ceil
import kotlin.math.floor
import kotlin.math.max
import kotlin.math.min
import kotlin.math.pow
import kotlin.math.roundToInt

/**
 * The wheel (47.0, docs/THEME.md "The wheel") — the website's
 * assets/js/wheel-picker.js, natively: a number or a duration picked on the
 * system's own NumberPicker, a column per part (hours and minutes, the whole
 * and its decimal), in a system dialog as a time of day is picked in
 * TimePickerDialog. Klaar puts it in the field; Typen gives the keyboard
 * back for an exact value. A clock time stays TimePickerDialog.
 */
data class WheelSpec(
    val kind: String = "number",      // "number" or "duration" (whole minutes)
    val min: Double = 0.0,
    val max: Double = 1000.0,
    val step: Double = 1.0,
    val decimals: Int = 0,
    val start: Double? = null,         // the value when the field is empty
    val unit: String = ""
) {
    companion object {
        /** The server's spec (`wheel` beside a field: lib/settings.php, hydrate_goal_wheel()). */
        fun parse(o: org.json.JSONObject?): WheelSpec? = o?.let {
            WheelSpec(
                kind = it.optString("kind", "number"),
                min = it.optDouble("min", 0.0), max = it.optDouble("max", 1000.0), step = it.optDouble("step", 1.0),
                decimals = it.optInt("decimals", 0),
                start = if (it.has("default") && !it.isNull("default")) it.optDouble("default") else null,
                unit = it.optString("unit", "")
            )
        }
    }
}

object Wheel {
    /** One column: its values, the row it stands on, its unit beside it. */
    data class Column(val values: List<Int>, val index: Int, val unit: String, val decimal: Int = 0, val pad: Boolean = false)

    private fun range(from: Int, to: Int, step: Int): List<Int> = (from..to step max(1, step)).toList()

    /** The columns for [spec], standing on [current] (or its start), as the website's. */
    fun columns(spec: WheelSpec, current: Double?): List<Column> {
        if (spec.kind == "duration") {
            val top = spec.max.toInt()
            val total = (current ?: spec.start ?: 30.0).roundToInt().coerceIn(spec.min.toInt(), top)
            val step = max(1, spec.step.toInt())
            val hours = range(0, top / 60, 1)
            val minutes = range(0, 60 - step, step)
            return listOf(
                Column(hours, hours.indexOf(total / 60).coerceAtLeast(0), tr("u")),
                Column(minutes, minutes.indexOf((total % 60) / step * step).coerceAtLeast(0), tr("min"), pad = true)
            )
        }

        var d = spec.decimals
        var whole = max(1, spec.step.toInt())
        while ((spec.max - spec.min) / whole > 1000) { whole *= 10; d = 0 }        // a long range turns in bigger steps
        val v = (current ?: spec.start ?: spec.min).coerceIn(spec.min, spec.max)
        val from = (floor(spec.min / whole) * whole).toInt()
        val values = range(from, spec.max.toInt(), whole)
        val at = values.indexOfLast { it <= floor(v) }.coerceAtLeast(0)
        val cols = mutableListOf(Column(values, at, if (d == 0) spec.unit else ""))
        if (d > 0) {
            val tenth = if (d == 1) 1 else 5
            val scale = 10.0.pow(d).toInt()
            val decimals = range(0, scale - tenth, tenth)
            val part = (((v - floor(v)) * scale) / tenth).roundToInt() * tenth
            cols += Column(decimals, decimals.indexOf(part).coerceAtLeast(0), spec.unit, decimal = d)
        }
        return cols
    }

    /** The value the columns stand on, at [indexes]. */
    fun valueOf(spec: WheelSpec, cols: List<Column>, indexes: List<Int>): Double {
        if (spec.kind == "duration") {
            return (cols[0].values[indexes[0]] * 60.0 + cols[1].values[indexes[1]]).coerceIn(spec.min, spec.max)
        }
        val whole = cols[0].values[indexes[0]].toDouble()
        val part = if (cols.size > 1) cols[1].values[indexes[1]] / 10.0.pow(cols[1].decimal) else 0.0
        return (whole + part).coerceIn(spec.min, spec.max)
    }

    /** As the field takes it: minutes whole, a number as the person writes it ("72,5"). */
    fun written(spec: WheelSpec, value: Double): String =
        if (spec.kind == "duration") value.roundToInt().toString() else OwnifyFormat.number(value, spec.decimals, grouping = false)

    fun label(col: Column, value: Int): String = when {
        col.decimal > 0 -> OwnifyFormat.number(1.5, 1).substring(1, 2) + value.toString().padStart(col.decimal, '0')
        col.pad -> value.toString().padStart(2, '0')
        else -> OwnifyFormat.number(value.toDouble(), 0)
    }

    /** The system's dialog with a NumberPicker per column. */
    fun show(context: Context, title: String, spec: WheelSpec, current: String, onPick: (String) -> Unit, onType: () -> Unit) {
        val themed = OwnifyThemeStore.dialogContext(context)
        val cols = columns(spec, OwnifyFormat.parseNumber(current))
        val row = LinearLayout(themed).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER
            val pad = (16 * resources.displayMetrics.density).toInt()
            setPadding(pad, pad, pad, 0)
        }
        val pickers = cols.map { col ->
            val picker = NumberPicker(themed).apply {
                minValue = 0
                maxValue = col.values.size - 1
                displayedValues = col.values.map { label(col, it) }.toTypedArray()
                value = col.index
                wrapSelectorWheel = false
                descendantFocusability = ViewGroup.FOCUS_BLOCK_DESCENDANTS      // turned, not typed: Typen is for that
                contentDescription = "$title ${col.unit}".trim()
            }
            row.addView(picker)
            if (col.unit.isNotEmpty()) {
                row.addView(TextView(themed).apply {
                    text = col.unit
                    val gap = (8 * resources.displayMetrics.density).toInt()
                    setPadding(gap, 0, gap * 2, 0)
                })
            }
            picker
        }
        AlertDialog.Builder(themed)
            .setTitle(title)
            .setView(row)
            .setPositiveButton(tr("Klaar")) { _, _ -> onPick(written(spec, valueOf(spec, cols, pickers.map { it.value }))) }
            .setNeutralButton(tr("Typen")) { _, _ -> onType() }
            .setNegativeButton(tr("Annuleren"), null)
            .show()
    }

    /** A goal's number: one column of decimals at most, up to three times where it starts (hydrate_goal_wheel()). */
    fun around(start: Double?, decimals: Int, unit: String): WheelSpec {
        val from = start ?: 1.0
        return WheelSpec(min = 0.0, max = max(10.0, ceil(abs(from) * 3)), decimals = min(1, decimals), start = from, unit = unit)
    }
}

/**
 * JInput with the wheel: a tap opens [Wheel.show]; Typen focuses the field
 * with the keyboard, for an exact value, until it is left.
 */
@Composable
fun WheelInput(
    value: String,
    onValueChange: (String) -> Unit,
    spec: WheelSpec,
    modifier: Modifier = Modifier,
    placeholder: String = "",
    label: String? = null,
    keyboard: KeyboardOptions = KeyboardOptions.Default,
    actions: KeyboardActions = KeyboardActions.Default,
    focus: FocusRequester = remember { FocusRequester() }
) {
    val context = LocalContext.current
    val keys = LocalSoftwareKeyboardController.current
    var typing by remember { mutableStateOf(false) }
    Box(modifier) {
        JInput(
            value, onValueChange,
            modifier = Modifier.focusRequester(focus).onFocusChanged { if (!it.isFocused) typing = false },
            placeholder = placeholder, keyboard = keyboard, actions = actions, label = label
        )
        if (!typing) {
            Box(
                Modifier
                    .matchParentSize()
                    .clickable(remember { MutableInteractionSource() }, indication = null, role = Role.Button, onClickLabel = label) {
                        Wheel.show(context, label.orEmpty(), spec, value, onValueChange) { typing = true }
                    }
            )
        }
    }
    LaunchedEffect(typing) {
        if (typing) {
            focus.requestFocus()
            keys?.show()
        }
    }
}
