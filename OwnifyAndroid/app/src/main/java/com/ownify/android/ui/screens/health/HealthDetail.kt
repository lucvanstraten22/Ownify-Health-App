package com.ownify.android.ui.screens.health

import androidx.compose.foundation.ScrollState
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.IntrinsicSize
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.runtime.Composable
import androidx.compose.runtime.setValue
import androidx.compose.runtime.getValue
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.drawBehind
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalFocusManager
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.em
import androidx.compose.ui.unit.sp
import com.ownify.android.data.AppData
import com.ownify.android.data.Area
import com.ownify.android.data.OwnifyActions
import com.ownify.android.data.Metric
import com.ownify.android.data.MetricGroup
import com.ownify.android.data.Outcome
import com.ownify.android.data.RatingCopy
import com.ownify.android.data.SleepView
import com.ownify.android.data.Timeline
import com.ownify.android.data.TrainingView
import com.ownify.android.data.numberText
import com.ownify.android.ui.app.DetailColumn
import com.ownify.android.ui.design.Btn
import com.ownify.android.ui.design.CardHint
import com.ownify.android.ui.design.CardStyle
import com.ownify.android.ui.design.IconTile
import com.ownify.android.ui.design.JCard
import com.ownify.android.ui.design.JIcon
import com.ownify.android.ui.design.JInput
import com.ownify.android.ui.design.JStyle
import com.ownify.android.ui.design.OwnifyIcons
import com.ownify.android.ui.design.LocalScreen
import com.ownify.android.ui.design.ScoreRing
import com.ownify.android.ui.design.T
import com.ownify.android.ui.design.chWidth
import com.ownify.android.ui.design.countUpText
import com.ownify.android.ui.design.cssPadding
import com.ownify.android.ui.design.rememberPlayOnSight
import com.ownify.android.ui.design.reveal
import com.ownify.android.ui.theme.Accent
import com.ownify.android.ui.theme.Ownify
import com.ownify.android.ui.theme.OwnifyType
import com.ownify.android.ui.theme.LocalAccent
import kotlinx.coroutines.launch
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import com.ownify.android.ui.design.focusSafely

/**
 * One health area in full (pages/health-detail.php), in the order it
 * matters: the score; for Voeding the day's own cijfer; the few numbers that
 * explain it — for Slaap, drawn: the night's stages and four charts over time
 * (docs/SLEEP.md); the long tail in groups; and the area's own Verloop. Everything shown is the server's.
 */
@Composable
fun HealthDetail(data: AppData, area: Area, scroll: ScrollState) {
    CompositionLocalProvider(LocalAccent provides Accent.of(area.accent)) {
        DetailColumn(scroll, back = "Gezondheid", backAria = "Terug naar Gezondheid") {
            HeroCard(area)
            area.rating?.let { NutritionRating(area, it) }
            // An area drawn — Slaap (docs/SLEEP.md), Training (docs/TRAINING.md) — instead
            // of the numbers and the bar of stages it shows.
            when (val view = area.view) {
                is SleepView -> {
                    SleepNightCard(view.night)
                    AreaChartsGrid(area.id, view.charts)
                }
                is TrainingView -> TrainingViewContent(view)
                else -> {
                    MetricTiles(area.highlights)
                    area.timeline?.let { SleepTimeline(it) }
                }
            }
            area.groups.forEach { MetricGroupCard(it) }
            // Its own Verloop: Gezondheid's, its one line (docs/CHARTS.md); a server from before it: the week and month.
            val history = data.health.history
            if (history != null) HistoryCard(history, data.compass.trend.days, only = area.id) else TrendCard(data, only = area.id)
        }
    }
}

/** `.card--hero`: the ring in the area's accent, its name, what the score says — or why there is none. */
@Composable
private fun HeroCard(area: Area) {
    val (play, sight) = rememberPlayOnSight()
    val score = area.score
    val empty = score.value == null

    JCard(Modifier.fillMaxWidth().reveal().then(sight)) {
        ScoreRing(
            value = score.value,
            max = score.max,
            scale = if (empty) "Nog geen gegevens" else "van ${score.max}",
            label = area.label,
            play = play,
            accent = LocalAccent.current,
            description = "${area.label}: " + if (empty) "nog geen gegevens" else "${score.value} van ${score.max}",
            modifier = Modifier
                .align(Alignment.CenterHorizontally)
                .padding(top = Ownify.Space4, bottom = Ownify.Space3)
        )
        T(area.label, JStyle.Section, Modifier.fillMaxWidth().semantics { heading() }, align = TextAlign.Center)
        T(
            area.summary,
            JStyle.Lede,
            Modifier
                .align(Alignment.CenterHorizontally)
                .padding(top = Ownify.Space2)
                .widthIn(max = chWidth(JStyle.Lede, 34f)),
            align = TextAlign.Center
        )
        if (empty) CardHint(area.empty, textAlign = TextAlign.Center)
    }
}

/**
 * `components/nutrition-rating.php`: today's cijfer, 1 to 10 — the manual
 * entry's input and button — saved to api/health/rating.php; the line under
 * it then says what it earned (health-rating.js).
 */
@Composable
private fun NutritionRating(area: Area, copy: RatingCopy) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val focus = LocalFocusManager.current
    var value by rememberSaveable { mutableStateOf(area.ratingToday?.toString().orEmpty()) }
    var saving by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var status by rememberSaveable { mutableStateOf<String?>(null) }
    val field = remember { FocusRequester() }

    fun save() {
        val rating = value.trim()
        error = null
        if (!Regex("^(10|[1-9])$").matches(rating)) {
            error = "Kies een cijfer van 1 tot 10."
            // health-rating.js: back into the field.
            field.focusSafely()
            return
        }
        focus.clearFocus()
        saving = true
        scope.launch {
            val outcome = OwnifyActions.form(context, "api/health/rating.php", mapOf("rating" to rating), "Dit kon niet worden opgeslagen.")
            saving = false
            when (outcome) {
                is Outcome.Done -> status = outcome.body.optString("message").ifEmpty { "Opgeslagen." }
                is Outcome.Refused -> error = outcome.message
                Outcome.SignedOut -> Unit
            }
        }
    }

    JCard(Modifier.fillMaxWidth().reveal()) {
        Row(
            Modifier.fillMaxWidth().padding(bottom = Ownify.Space4),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)
        ) {
            IconTile(OwnifyIcons.check)
            T(copy.title, JStyle.Eyebrow, Modifier.semantics { heading() })
        }

        // .goal-entry: the number and its button on one line.
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
            JInput(
                value, { value = it.filter(Char::isDigit).take(4) },
                modifier = Modifier.weight(1f).focusRequester(field),
                placeholder = copy.placeholder,
                label = copy.label,
                keyboard = KeyboardOptions(keyboardType = KeyboardType.Number, imeAction = ImeAction.Done),
                actions = KeyboardActions(onDone = { if (!saving) save() })
            )
            Btn(copy.button, onClick = ::save, enabled = !saving)
        }

        error?.let { EditorError(it) }

        CardHint(
            status ?: copy.hint,
            icon = null,
            plain = true,
            modifier = Modifier.semantics { liveRegion = LiveRegionMode.Polite }
        )
    }
}

/** `.field-editor__error`: 12 above, the soft red, small. */
@Composable
fun EditorError(text: String, modifier: Modifier = Modifier) {
    T(
        text,
        OwnifyType.style(Ownify.FsSmall, color = Ownify.Error, lineHeight = 1.4.em),
        modifier
            .fillMaxWidth()
            .padding(top = Ownify.Space3)
            .semantics { liveRegion = LiveRegionMode.Assertive }
    )
}

/** `components/metric-tiles.php`: "Vandaag" and its numbers, two to a row. */
@Composable
internal fun MetricTiles(tiles: List<Metric>, title: String = "Vandaag", count: Boolean = true) {
    val (play, sight) = rememberPlayOnSight()
    val gap = if (LocalScreen.current.narrow) Ownify.Space2 else Ownify.Space3

    JCard(Modifier.fillMaxWidth().reveal().then(sight)) {
        T(title, JStyle.Eyebrow, Modifier.semantics { heading() })
        Column(Modifier.fillMaxWidth().padding(top = Ownify.Space4), verticalArrangement = Arrangement.spacedBy(gap)) {
            tiles.chunked(2).forEach { pair ->
                Row(Modifier.fillMaxWidth().height(IntrinsicSize.Max), horizontalArrangement = Arrangement.spacedBy(gap)) {
                    pair.forEach { MetricTile(it, play, Modifier.weight(1f).fillMaxHeight(), count) }
                    if (pair.size == 1) Box(Modifier.weight(1f))
                }
            }
        }
    }
}

@Composable
private fun MetricTile(metric: Metric, play: Boolean, modifier: Modifier, count: Boolean = true) {
    val empty = metric.value == null
    val shape = RoundedCornerShape(Ownify.RadiusMd)
    Column(
        modifier
            .clip(shape)
            .background(Ownify.fill(0.035f))
            .border(1.dp, Ownify.GlassHairline, shape)
            .cssPadding(PaddingValues(Ownify.Space3), border = 1.dp)
            .clearAndSetSemantics {
                contentDescription = "${metric.label}: " + (metric.value?.let { v -> if (metric.unit.isNotEmpty()) "$v ${metric.unit}" else v } ?: "nog geen gegevens")
            }
    ) {
        T(metric.label, JStyle.Tiny)
        Row(Modifier.padding(top = Ownify.Space1)) {
            T(
                // A figure written as it is ("1:12", "5:50", "1.468") is not counted up.
                if (count) countUpText(metric.value ?: "—", play && !empty) else metric.value ?: "—",
                OwnifyType.style(
                    20.sp,
                    if (empty) FontWeight.SemiBold else FontWeight.Bold,
                    if (empty) Ownify.TextSecondary else Ownify.TextPrimary,
                    tracking = (-0.03).em, lineHeight = 1.1.em, tabular = true
                ),
                Modifier.alignByBaseline()
            )
            if (!empty && metric.unit.isNotEmpty()) {
                T(
                    metric.unit,
                    // Inside the value, it keeps the value's spacing: -0.03em of 20, -0.6.
                    OwnifyType.style(Ownify.FsSmall, FontWeight.Medium, Ownify.TextMuted, lineHeight = 1.1.em, tracking = (-0.6).sp),
                    Modifier.alignByBaseline().padding(start = 3.dp)
                )
            }
        }
    }
}

/** `components/sleep-timeline.php`: the night as one bar of stages, and what each took. */
@Composable
private fun SleepTimeline(timeline: Timeline) {
    val narrow = LocalScreen.current.narrow
    val drawn = timeline.stages.filter { (it.share ?: 0.0) > 0.0 }
    val filled = timeline.stages.sumOf { (it.share ?: 0.0).toInt() } > 0

    JCard(Modifier.fillMaxWidth().reveal()) {
        Column(Modifier.fillMaxWidth()) {
            T(timeline.title, JStyle.Eyebrow, Modifier.semantics { heading() })
            T(timeline.hint, JStyle.Tiny, Modifier.padding(top = Ownify.Space1))
        }

        // .hypnogram
        val label = timeline.title + if (filled) "" else ": nog geen gegevens"
        if (filled) {
            Row(
                Modifier
                    .fillMaxWidth()
                    .padding(top = Ownify.Space4)
                    .height(14.dp)
                    .clearAndSetSemantics { contentDescription = label },
                horizontalArrangement = Arrangement.spacedBy(3.dp)
            ) {
                drawn.forEach { stage ->
                    Box(
                        Modifier
                            .weight(stage.share!!.toFloat())
                            .fillMaxHeight()
                            .clip(RoundedCornerShape(4.dp))
                            .background(stageColor(stage.tone))
                    )
                }
            }
        } else {
            Box(
                Modifier
                    .fillMaxWidth()
                    .padding(top = Ownify.Space4)
                    .height(10.dp)
                    .clip(RoundedCornerShape(50))
                    .drawBehind {
                        var x = 0f
                        val on = 2.dp.toPx()
                        val period = 8.dp.toPx()
                        while (x < size.width) {
                            drawRect(Ownify.ink(0.11f), topLeft = Offset(x, 0f), size = Size(minOf(on, size.width - x), size.height))
                            x += period
                        }
                    }
                    .clearAndSetSemantics { contentDescription = label }
            )
        }

        // .stage-legend: two columns, one below 360 dp.
        val columns = if (narrow) 1 else 2
        Column(Modifier.fillMaxWidth().padding(top = Ownify.Space4), verticalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
            timeline.stages.chunked(columns).forEach { row ->
                Row(horizontalArrangement = Arrangement.spacedBy(Ownify.Space4)) {
                    row.forEach { stage ->
                        Row(
                            Modifier.weight(1f),
                            verticalAlignment = Alignment.CenterVertically,
                            horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)
                        ) {
                            Box(Modifier.size(8.dp).clip(RoundedCornerShape(3.dp)).background(stageColor(stage.tone)))
                            T(stage.label, OwnifyType.style(Ownify.FsSmall, color = Ownify.TextSecondary), Modifier.weight(1f))
                            val share = stage.share
                            T(
                                if (share != null) numberText(share) + "%" else "—",
                                OwnifyType.style(
                                    Ownify.FsSmall,
                                    if (share != null) FontWeight.SemiBold else FontWeight.Medium,
                                    if (share != null) Ownify.TextPrimary else Ownify.TextMuted,
                                    tabular = true
                                )
                            )
                        }
                    }
                    if (row.size < columns) Box(Modifier.weight(1f))
                }
            }
        }
    }
}

/** The stage tones: deep in sleep's colour, REM in its lighter shade, light and awake in the text colour. */
private fun stageColor(tone: String): Color = when (tone) {
    "rem" -> Ownify.SleepLight
    "light" -> Ownify.ink(0.26f)
    "awake" -> Ownify.ink(0.13f)
    else -> Ownify.Sleep
}

/**
 * `components/metric-group.php`: the long tail, one group a card. A group
 * that only a connected device can fill is marked with a lock and a quieter
 * card — never faked, never hidden.
 */
@Composable
private fun MetricGroupCard(group: MetricGroup) {
    val style = if (group.locked) {
        CardStyle.Default.copy(from = CardStyle.Quiet.from, to = CardStyle.Quiet.to, border = Ownify.GlassBorderSoft)
    } else {
        CardStyle.Default
    }

    JCard(Modifier.fillMaxWidth().reveal(), style = style) {
        Row(
            Modifier.fillMaxWidth().padding(bottom = Ownify.Space4),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)
        ) {
            // .card__head: space-between — the headings take the row, the lock sits at its end.
            Column(Modifier.weight(1f)) {
                T(group.title, JStyle.Eyebrow, Modifier.semantics { heading() })
                if (!group.hint.isNullOrEmpty()) T(group.hint, JStyle.Tiny, Modifier.padding(top = Ownify.Space1))
            }
            if (group.locked) {
                Box(
                    Modifier
                        .size(28.dp)
                        .clip(RoundedCornerShape(10.dp))
                        .background(Ownify.fill(0.055f))
                        .clearAndSetSemantics { },
                    contentAlignment = Alignment.Center
                ) {
                    JIcon(OwnifyIcons.lock, size = 14.dp, color = Ownify.TextMuted)
                }
            }
        }

        Column(Modifier.fillMaxWidth()) {
            group.metrics.forEachIndexed { i, metric -> MetricRow(metric, first = i == 0) }
        }
    }
}

/** `.metric-row`: the name and the value, a hairline between rows. */
@Composable
private fun MetricRow(metric: Metric, first: Boolean) {
    val empty = metric.value == null
    Column(Modifier.fillMaxWidth()) {
        if (!first) Box(Modifier.fillMaxWidth().height(1.dp).background(Ownify.GlassHairline))
        Row(
            Modifier
                .fillMaxWidth()
                .cssPadding(top = if (first) 0.dp else Ownify.Space3, bottom = Ownify.Space3, above = if (first) 0.dp else 1.dp)
                .semantics(mergeDescendants = true) { },
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)
        ) {
            T(metric.label, OwnifyType.style(Ownify.FsSmall, color = Ownify.TextSecondary), Modifier.weight(1f))
            Row {
                T(
                    metric.value ?: "—",
                    OwnifyType.style(
                        Ownify.FsSmall,
                        if (empty) FontWeight.Medium else FontWeight.SemiBold,
                        if (empty) Ownify.TextMuted else Ownify.TextPrimary,
                        tabular = true
                    ),
                    Modifier.alignByBaseline(),
                    align = TextAlign.End
                )
                if (!empty && metric.unit.isNotEmpty()) {
                    T(
                        metric.unit,
                        OwnifyType.style(Ownify.FsTiny, FontWeight.Medium, Ownify.TextMuted),
                        Modifier.alignByBaseline().padding(start = 3.dp)
                    )
                }
            }
        }
    }
}
