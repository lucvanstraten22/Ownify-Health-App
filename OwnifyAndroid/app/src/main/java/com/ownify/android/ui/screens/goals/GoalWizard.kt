package com.ownify.android.ui.screens.goals

import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.IntrinsicSize
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.Stable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.key
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.layout.Layout
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalFocusManager
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.selected
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.em
import com.ownify.android.data.AppData
import com.ownify.android.data.GoalSource
import com.ownify.android.data.Goals
import com.ownify.android.data.OwnifyActions
import com.ownify.android.data.Outcome
import com.ownify.android.ui.app.LocalShell
import com.ownify.android.ui.app.Overlay
import com.ownify.android.ui.app.OverlayFrame
import com.ownify.android.ui.app.RoundButton
import com.ownify.android.ui.app.panelGlass
import com.ownify.android.ui.design.Btn
import com.ownify.android.ui.design.BtnLook
import com.ownify.android.ui.design.IconTile
import com.ownify.android.ui.design.JIcon
import com.ownify.android.ui.design.JInput
import com.ownify.android.ui.design.JStyle
import com.ownify.android.ui.design.OwnifyIcons
import com.ownify.android.ui.design.LocalScreen
import com.ownify.android.ui.design.LocalStillMotion
import com.ownify.android.ui.design.RangeSwitch
import com.ownify.android.ui.design.T
import com.ownify.android.ui.design.cssPadding
import com.ownify.android.ui.design.press
import com.ownify.android.ui.screens.account.FieldLabel
import com.ownify.android.ui.theme.Accent
import com.ownify.android.ui.theme.Ownify
import com.ownify.android.ui.theme.InButton
import com.ownify.android.ui.theme.OwnifyType
import com.ownify.android.ui.theme.LocalAccent
import java.math.RoundingMode
import java.text.NumberFormat
import java.time.LocalDate
import java.util.Locale
import kotlinx.coroutines.launch
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import com.ownify.android.ui.design.focusSafely
import com.ownify.android.ui.design.blurring

private const val TOTAL = 6

/**
 * What the wizard has been told so far (goal-wizard.js `draft`). `null`
 * means nobody has said yet, which is different from an answer — "Geen data
 * mogelijk" is an answer.
 */
@Stable
private class Draft {
    var category by mutableStateOf<String?>(null)
    var name by mutableStateOf("")
    var type by mutableStateOf<String?>(null)
    var sourceKind by mutableStateOf<String?>(null)
    var sourceKey by mutableStateOf("")
    var sourceLabel by mutableStateOf("")
    var sourceUnit by mutableStateOf("")
    var measure by mutableStateOf<String?>(null)
    var value by mutableStateOf("")
    var unit by mutableStateOf("")
    var days by mutableStateOf("")
    var better by mutableStateOf<String?>(null)
    var floor by mutableStateOf("increase")
    var daily by mutableStateOf("")
    var duration by mutableStateOf<String?>(null)
    var priority by mutableStateOf("secondary")

    val auto: Boolean get() = sourceKind != null && sourceKind != "manual"

    /** Whether this goal counts days rather than an amount. */
    val countsDays: Boolean get() = type == "streak" || (type == "accumulate" && measure == "days")

    /** A day read from health data needs a threshold before it can count. */
    val needsDaily: Boolean get() = countsDays && auto

    /** The unit the target is typed in: the source's, or the person's own. */
    val amountUnit: String get() = if (auto) sourceUnit else unit
}

/**
 * The six-step create-a-goal flow (components/goal-wizard.php, run by
 * goal-wizard.js): category, definition, where progress comes from, the
 * target in that source's own unit, the period, and a summary — then the
 * goal as the board will show it. One question per step; the next step is
 * out of reach until this one has an answer. The server checks everything
 * again and decides what is stored.
 */
@Composable
fun GoalWizard(overlay: Overlay, data: AppData) {
    val shell = LocalShell.current
    val goals = data.goals
    val words = goals.wizard
    val context = LocalContext.current
    val focus = LocalFocusManager.current
    val scope = rememberCoroutineScope()
    val draft = remember { Draft() }
    var step by remember { mutableIntStateOf(1) }
    var direction by remember { mutableIntStateOf(1) }
    var done by remember { mutableStateOf(false) }
    var saving by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var createdId by remember { mutableStateOf<String?>(null) }
    val body = rememberScrollState()
    val hasPrimary = GoalBoard.board(goals).primary != null

    fun close() {
        shell.close(overlay)
        // Only after a save: the board on Actief, with the new card brought into view.
        if (done) {
            GoalBoard.view = "active"
            GoalBoard.reveal = createdId
        }
    }

    fun go(to: Int, dir: Int) {
        focus.clearFocus()
        direction = dir
        step = to
    }

    LaunchedEffect(step, done) { body.scrollTo(0) }

    fun finish() {
        saving = true
        error = null
        scope.launch {
            when (val outcome = OwnifyActions.form(context, "api/goals/create.php", payload(draft), "Dit doel kon niet worden opgeslagen.")) {
                is Outcome.Done -> {
                    createdId = outcome.body.opt("goal_id")?.toString()
                    done = true
                }
                is Outcome.Refused -> error = outcome.message
                Outcome.SignedOut -> Unit
            }
            saving = false
        }
    }

    val complete = isComplete(step, draft, goals)

    OverlayFrame(overlay, title = words["title"], maxWidth = 432.dp, onDismiss = ::close) { panelModifier ->
        Box(panelModifier.panelGlass().cssPadding(border = 1.dp)) {
            Column(Modifier.padding(Ownify.Space5)) {
                // .wizard__head
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)) {
                    T(words["title"], JStyle.Eyebrow, Modifier.weight(1f).semantics { heading() })
                    RoundButton(OwnifyIcons.chevronDown, words["close"], ::close)
                }

                // .wizard__progress: one segment per step and one line.
                Column(Modifier.fillMaxWidth().padding(top = Ownify.Space4)) {
                    Row(Modifier.fillMaxWidth().clearAndSetSemantics { }, horizontalArrangement = Arrangement.spacedBy(Ownify.Space1)) {
                        for (n in 1..TOTAL) {
                            val lit by animateColorAsState(
                                if (done || n <= step) Ownify.Health else Ownify.fill(0.10f),
                                tween(Ownify.SlowMs, easing = Ownify.EaseOut),
                                label = "bar"
                            )
                            Box(Modifier.weight(1f).height(3.dp).clip(RoundedCornerShape(50)).background(lit))
                        }
                    }
                    val stepLabel = words.steps.getOrNull(step - 1)?.label.orEmpty()
                    T(
                        if (done) words.steps.getOrNull(TOTAL - 1)?.label.orEmpty() else "Stap $step van $TOTAL · $stepLabel",
                        JStyle.Tiny,
                        Modifier.padding(top = Ownify.Space2).semantics { liveRegion = LiveRegionMode.Polite }
                    )
                }

                // .wizard__body: the step, scrolled on its own.
                Box(
                    Modifier
                        .padding(top = Ownify.Space4)
                        .weight(1f, fill = false)
                        .heightIn(min = 272.dp)
                        .verticalScroll(body)
                ) {
                    key(if (done) 0 else step) {
                        StepIn(direction) {
                            if (done) {
                                DoneStep(draft, goals)
                            } else {
                                when (step) {
                                    1 -> CategoryStep(draft, goals)
                                    2 -> DefinitionStep(draft, goals)
                                    3 -> SourceStep(draft, goals)
                                    4 -> TargetStep(draft, goals)
                                    5 -> DurationStep(draft, goals)
                                    else -> SummaryStep(draft, goals, hasPrimary)
                                }
                            }
                        }
                    }
                }

                // .wizard__foot
                Column(Modifier.fillMaxWidth().padding(top = Ownify.Space4)) {
                    Box(Modifier.fillMaxWidth().height(1.dp).background(Ownify.GlassHairline))
                    Foot(
                        Modifier.padding(top = Ownify.Space4),
                        back = if (step > 1 && !done) ({ Btn(words["back"], onClick = { go(maxOf(1, step - 1), -1) }) }) else null,
                        error = error,
                        next = {
                            val look = BtnLook(
                                border = if (done || complete) Ownify.Health.copy(alpha = 0.38f) else Ownify.GlassBorderSoft,
                                fill = if (done || complete) Ownify.Health.copy(alpha = 0.16f) else Ownify.GlassSoft
                            )
                            if (done) {
                                Btn(words["done_close"], onClick = ::close, look = look, modifier = Modifier.fillMaxWidth())
                            } else {
                                Btn(
                                    if (step == TOTAL) words["create"] else words["next"],
                                    onClick = {
                                        if (!isComplete(step, draft, goals)) return@Btn
                                        if (step == TOTAL) finish() else go(step + 1, 1)
                                    },
                                    enabled = complete && !saving,
                                    look = look,
                                    modifier = Modifier.fillMaxWidth()
                                )
                            }
                        }
                    )
                }
            }
        }
    }
}

/**
 * `.wizard__foot`: back at its own width, the error (when there is one)
 * between, and next taking the rest — never narrower than its own label.
 */
@Composable
private fun Foot(
    modifier: Modifier,
    back: (@Composable () -> Unit)?,
    error: String?,
    next: @Composable () -> Unit
) {
    Layout(
        content = {
            Box { back?.invoke() }
            Box {
                if (error != null) {
                    T(
                        error,
                        OwnifyType.style(Ownify.FsSmall, color = Ownify.Error, lineHeight = 1.4.em),
                        Modifier.padding(bottom = Ownify.Space3).semantics { liveRegion = LiveRegionMode.Assertive },
                        align = TextAlign.Center
                    )
                }
            }
            Box { next() }
        },
        modifier = modifier.fillMaxWidth()
    ) { measurables, constraints ->
        val gap = Ownify.Space2.roundToPx()
        val width = constraints.maxWidth
        val loose = constraints.copy(minWidth = 0, minHeight = 0)
        val backPlaceable = measurables[0].measure(loose)
        val hasBack = backPlaceable.width > 0
        val hasError = error != null
        val nextMin = measurables[2].minIntrinsicWidth(constraints.maxHeight)
        val used = (if (hasBack) backPlaceable.width + gap else 0)
        val errorRoom = (width - used - nextMin - if (hasError) gap else 0).coerceAtLeast(0)
        val errorPlaceable = measurables[1].measure(loose.copy(maxWidth = if (hasError) errorRoom else 0))
        val errorWidth = if (hasError) errorPlaceable.width else 0
        val nextWidth = (width - used - errorWidth - if (hasError) gap else 0).coerceAtLeast(nextMin)
        val nextPlaceable = measurables[2].measure(loose.copy(minWidth = nextWidth, maxWidth = nextWidth))
        val height = maxOf(backPlaceable.height, errorPlaceable.height, nextPlaceable.height)
        layout(width, height) {
            var x = 0
            if (hasBack) {
                backPlaceable.place(x, (height - backPlaceable.height) / 2)
                x += backPlaceable.width + gap
            }
            if (hasError) {
                errorPlaceable.place(x, 0)
                x += errorWidth + gap
            }
            nextPlaceable.place(x, (height - nextPlaceable.height) / 2)
        }
    }
}

/** `wizard-in`: a step arrives from 14 dp to the side it came from, and fades in. */
@Composable
internal fun StepIn(direction: Int, content: @Composable ColumnScope.() -> Unit) {
    val still = LocalStillMotion.current
    val progress = remember { Animatable(if (still) 1f else 0f) }
    LaunchedEffect(Unit) { progress.animateTo(1f, tween(Ownify.ScreenMs, easing = Ownify.ScreenEase)) }
    Column(
        Modifier
            .fillMaxWidth()
            .graphicsLayer {
                alpha = progress.value
                translationX = (1f - progress.value) * 14.dp.toPx() * direction
            },
        content = content
    )
}

/** `.wizard__title` and `.wizard__lede`. */
@Composable
private fun StepHead(title: String, lede: String) {
    T(title, JStyle.Subtitle, Modifier.semantics { heading() })
    T(lede, JStyle.Meta, Modifier.padding(top = Ownify.Space1))
}

// ------------------------------------------------------------ 1 · categorie

@Composable
private fun CategoryStep(draft: Draft, goals: Goals) {
    val step = goals.wizard.steps.getOrNull(0)
    val narrow = LocalScreen.current.narrow
    StepHead(step?.title.orEmpty(), step?.lede.orEmpty())
    Grid(goals.categories, if (narrow) 1 else 2, Modifier.padding(top = Ownify.Space4)) { category, modifier ->
        val accent = Accent.of(category.accent).color
        Choice(
            chosen = draft.category == category.key,
            accent = accent,
            onClick = { draft.category = category.key },
            modifier = modifier
        ) {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)) {
                OwnifyIcons.named(category.icon)?.let { IconTile(it, size = 30.dp, radius = 10.dp, iconSize = 16.dp, color = accent) }
                T(category.label, OwnifyType.style(Ownify.FsSmall, FontWeight.SemiBold), Modifier.weight(1f), maxLines = 1, ellipsis = true)
            }
        }
    }
}

// ------------------------------------------------------------ 2 · definitie

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun DefinitionStep(draft: Draft, goals: Goals) {
    val words = goals.wizard
    val step = words.steps.getOrNull(1)
    StepHead(step?.title.orEmpty(), step?.lede.orEmpty())

    FieldLabel(words["name_label"], Modifier.padding(top = 0.dp))
    val nameField = remember { FocusRequester() }
    JInput(
        draft.name, { draft.name = it.take(120) },
        modifier = Modifier.focusRequester(nameField),
        placeholder = words["name_hint"],
        label = words["name_label"],
        keyboard = KeyboardOptions(capitalization = KeyboardCapitalization.Sentences, autoCorrectEnabled = false, imeAction = ImeAction.Done)
    )
    // Suggestions fill the name field; they never choose anything by themselves.
    val suggestions = draft.category?.let { words.suggestions[it] }.orEmpty()
    if (suggestions.isNotEmpty()) {
        FlowRow(
            Modifier.fillMaxWidth().padding(top = Ownify.Space3),
            horizontalArrangement = Arrangement.spacedBy(Ownify.Space2),
            verticalArrangement = Arrangement.spacedBy(Ownify.Space2)
        ) {
            // goal-wizard.js: the name is filled in and the cursor put in it.
            suggestions.forEach { text ->
                Suggestion(text) {
                    draft.name = text
                    nameField.focusSafely()
                }
            }
        }
    }

    FieldLabel(words["type_label"], Modifier.padding(top = Ownify.Space5))
    Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
        goals.types.forEach { type ->
            Option(type.label, type.hint, chosen = draft.type == type.key) {
                draft.type = type.key
                // A source chosen before that this type cannot use is cleared, not quietly misread.
                val source = chosenSource(draft, goals)
                if (draft.sourceKind != null && draft.sourceKind != "manual" && source != null && type.key !in source.types) clearSource(draft)
            }
        }
    }
}

// ------------------------------------------------------------ 3 · bijhouden

@Composable
private fun SourceStep(draft: Draft, goals: Goals) {
    val words = goals.wizard
    val step = words.steps.getOrNull(2)
    StepHead(step?.title.orEmpty(), step?.lede.orEmpty())

    FieldLabel(words["source_label"])
    Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
        // "Geen data mogelijk" first: a real answer, not a failure to find something.
        Option(words["source_manual"], words["source_manual_hint"], chosen = draft.sourceKind == "manual") {
            draft.sourceKind = "manual"
            draft.sourceKey = ""
            draft.sourceUnit = ""
            draft.sourceLabel = words["source_manual"]
        }
        goals.wizardSources.forEach { group ->
            val fitting = group.sources.filter { fits(it, draft.type) }
            if (fitting.isNotEmpty()) {
                // `.wizard__label--spaced` in a flex column: its 24 margin and the 8 gap both count.
                FieldLabel(group.label, Modifier.padding(top = Ownify.Space5))
                fitting.forEach { source ->
                    Option(source.label, source.hint, chosen = draft.sourceKind == source.kind && draft.sourceKey == source.key) {
                        draft.sourceKind = source.kind
                        draft.sourceKey = source.key
                        draft.sourceUnit = source.unit
                        draft.sourceLabel = source.label
                    }
                }
            }
        }
    }
}

private fun fits(source: GoalSource, type: String?): Boolean = type == null || type in source.types

private fun chosenSource(draft: Draft, goals: Goals): GoalSource? =
    goals.wizardSources.flatMap { it.sources }.firstOrNull { it.kind == draft.sourceKind && it.key == draft.sourceKey }

private fun clearSource(draft: Draft) {
    draft.sourceKind = null
    draft.sourceKey = ""
    draft.sourceLabel = ""
    draft.sourceUnit = ""
}

// -------------------------------------------------------------- 4 · streven

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun TargetStep(draft: Draft, goals: Goals) {
    val words = goals.wizard
    val step = words.steps.getOrNull(3)
    val type = draft.type
    val days = draft.countsDays
    val auto = draft.auto
    StepHead(step?.title.orEmpty(), words.targetLede[type].orEmpty())

    val measureShown = type == "accumulate"
    val amountShown = type == "milestone" || (type == "accumulate" && draft.measure == "amount")
    // With the measure switch above, the next field's label stands 24 below it.
    val firstAfterMeasure = if (measureShown) Ownify.Space5 else 0.dp

    if (measureShown) {
        FieldLabel(words["target_measure"])
        RangeSwitch(
            options = listOf("amount" to words["measure_amount"], "days" to words["measure_days"]),
            selected = draft.measure,
            onSelect = { draft.measure = it },
            wide = true,
            label = words["target_measure"]
        )
    }

    if (amountShown) {
        FieldLabel(if (type == "milestone") words["target_best"] else words["target_total"], Modifier.padding(top = firstAfterMeasure))
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
            JInput(
                draft.value, { draft.value = it.take(16) },
                modifier = Modifier.weight(8f),
                placeholder = "100",
                label = if (type == "milestone") words["target_best"] else words["target_total"],
                keyboard = KeyboardOptions(keyboardType = KeyboardType.Decimal, imeAction = ImeAction.Done)
            )
            if (!auto) {
                JInput(
                    draft.unit, { draft.unit = it.take(12) },
                    modifier = Modifier.weight(7f),
                    placeholder = words["target_unit"],
                    label = words["target_unit"],
                    keyboard = KeyboardOptions(autoCorrectEnabled = false, imeAction = ImeAction.Done)
                )
            } else if (draft.sourceUnit.isNotEmpty()) {
                T(draft.sourceUnit, JStyle.Meta)
            }
        }
        // Units for a goal kept by hand; "dagen" is its own choice, not a unit.
        val units = goals.categories.firstOrNull { it.key == draft.category }?.units.orEmpty().filter { it != "dagen" }
        if (!auto && units.isNotEmpty()) {
            FlowRow(
                Modifier.fillMaxWidth().padding(top = Ownify.Space3),
                horizontalArrangement = Arrangement.spacedBy(Ownify.Space2),
                verticalArrangement = Arrangement.spacedBy(Ownify.Space2)
            ) {
                units.forEach { unit -> Suggestion(unit) { draft.unit = unit } }
            }
        }
    }

    if (days) {
        FieldLabel(if (type == "streak") words["target_streak"] else words["target_days"], Modifier.padding(top = firstAfterMeasure))
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
            JInput(
                draft.days, { draft.days = it.take(4) },
                modifier = Modifier.weight(1f),
                placeholder = "30",
                label = if (type == "streak") words["target_streak"] else words["target_days"],
                keyboard = KeyboardOptions(keyboardType = KeyboardType.Number, imeAction = ImeAction.Done)
            )
            T(if (type == "streak") "dagen op rij" else "dagen", JStyle.Meta)
        }
    }

    if (type == "milestone") {
        // Asked outright: 80 kg is reached from above and from below alike.
        FieldLabel(words["target_better"], Modifier.padding(top = Ownify.Space5))
        RangeSwitch(
            options = listOf("increase" to words["better_up"], "decrease" to words["better_down"]),
            selected = draft.better,
            onSelect = { draft.better = it },
            wide = true,
            label = words["target_better"]
        )
    }

    if (days && auto) {
        FieldLabel(words["daily_label"], Modifier.padding(top = Ownify.Space5))
        RangeSwitch(
            options = listOf("increase" to words["daily_floor"], "decrease" to words["daily_ceiling"]),
            selected = draft.floor,
            onSelect = { draft.floor = it },
            wide = true,
            label = words["daily_label"]
        )
        Row(
            Modifier.padding(top = Ownify.Space3),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)
        ) {
            JInput(
                draft.daily, { draft.daily = it.take(16) },
                modifier = Modifier.weight(1f),
                placeholder = "10000",
                label = words["daily_label"],
                keyboard = KeyboardOptions(keyboardType = KeyboardType.Decimal, imeAction = ImeAction.Done)
            )
            if (draft.sourceUnit.isNotEmpty()) T(draft.sourceUnit, JStyle.Meta)
        }
        T(words["daily_hint"], JStyle.Tiny, Modifier.padding(top = Ownify.Space2))
    }

    if (days && !auto) {
        WizardNote(if (type == "streak") words["tick_streak"] else words["tick_days"], OwnifyIcons.check)
    }
}

// --------------------------------------------------------------- 5 · periode

@Composable
private fun DurationStep(draft: Draft, goals: Goals) {
    val words = goals.wizard
    val step = words.steps.getOrNull(4)
    val narrow = LocalScreen.current.narrow
    StepHead(step?.title.orEmpty(), step?.lede.orEmpty())

    // A period that can no longer hold the days asked for is let go.
    LaunchedEffect(draft.days, draft.type, draft.measure) {
        val chosen = draft.duration
        if (chosen != null && !fitsDuration(chosen, draft, goals)) draft.duration = null
    }

    Grid(goals.durations, if (narrow) 1 else 2, Modifier.padding(top = Ownify.Space4)) { duration, modifier ->
        val ok = fitsDuration(duration.key, draft, goals)
        Choice(
            chosen = draft.duration == duration.key,
            accent = Ownify.Health,
            green = true,
            enabled = ok,
            onClick = { draft.duration = duration.key },
            modifier = modifier
        ) {
            T(duration.label, OwnifyType.style(Ownify.FsSmall, FontWeight.SemiBold))
            T(
                if (ok) "t/m ${dutchDate(endDate(duration.days))}"
                else (words.copy["too_short"] ?: "Te kort voor %s").replaceFirst("%s", daysText(draft.days) + if (draft.type == "streak") " op rij" else ""),
                OwnifyType.style(Ownify.FsTiny, color = Ownify.TextMuted, tabular = true),
                Modifier.padding(top = 2.dp)
            )
        }
    }

    val chosen = goals.durations.firstOrNull { it.key == draft.duration }
    if (chosen != null) {
        WizardNote("Van ${dutchDate(LocalDate.now())} t/m ${dutchDate(endDate(chosen.days))} · nog ${spanText(chosen.days)}", null)
    }
}

/** Whether a period can hold the days asked for: from today to its end date, both days included. */
private fun fitsDuration(key: String, draft: Draft, goals: Goals): Boolean {
    if (!draft.countsDays || !wholeDays(draft.days)) return true
    val period = goals.durations.firstOrNull { it.key == key }?.let { it.days + 1 } ?: 0
    return jsNumber(draft.days)!! <= period
}

// ------------------------------------------------------------- 6 · bevestigen

@Composable
private fun SummaryStep(draft: Draft, goals: Goals, hasPrimary: Boolean) {
    val words = goals.wizard
    val step = words.steps.getOrNull(5)
    StepHead(step?.title.orEmpty(), step?.lede.orEmpty())

    val category = goals.categories.firstOrNull { it.key == draft.category }
    val type = goals.types.firstOrNull { it.key == draft.type }
    val duration = goals.durations.firstOrNull { it.key == draft.duration }
    val rows = listOf(
        words["summary_goal"] to draft.name.trim().ifEmpty { "—" },
        words["summary_category"] to (category?.let { "${it.label} · ${type?.label.orEmpty()}" } ?: "—"),
        words["summary_target"] to (targetText(draft, goals) ?: "—"),
        words["summary_source"] to (if (draft.sourceKind == "manual") words.copy["source_manual"] ?: "Geen data mogelijk" else draft.sourceLabel.ifEmpty { "—" }),
        words["summary_duration"] to (duration?.let { "${it.label} · t/m ${dutchDate(endDate(it.days))}" } ?: "—")
    )

    // .wizard__summary
    Column(Modifier.fillMaxWidth().padding(top = Ownify.Space4)) {
        rows.forEachIndexed { i, (term, value) ->
            if (i > 0) Box(Modifier.fillMaxWidth().height(1.dp).background(Ownify.GlassHairline))
            Row(
                Modifier
                    .fillMaxWidth()
                    .padding(top = if (i == 0) 0.dp else Ownify.Space3, bottom = Ownify.Space3)
                    .semantics(mergeDescendants = true) { },
                horizontalArrangement = Arrangement.spacedBy(Ownify.Space4)
            ) {
                T(term, JStyle.Caption, Modifier.alignByBaseline(), uppercase = true)
                T(value, OwnifyType.style(Ownify.FsLabel, FontWeight.SemiBold), Modifier.weight(1f).alignByBaseline(), align = TextAlign.End)
            }
        }
    }

    FieldLabel(words["priority_label"], Modifier.padding(top = Ownify.Space5))
    RangeSwitch(
        options = listOf("primary" to goals.labels["primary_chip"].orEmpty(), "secondary" to "Secundair"),
        selected = draft.priority,
        onSelect = { draft.priority = it },
        wide = true,
        label = words["priority_label"]
    )
    // Only worth saying when there is in fact a primary goal to displace.
    if (draft.priority == "primary" && hasPrimary) {
        T(words["priority_swap"], JStyle.Tiny, Modifier.padding(top = Ownify.Space4))
    }
}

// ------------------------------------------------------------------ closing

@Composable
private fun DoneStep(draft: Draft, goals: Goals) {
    val words = goals.wizard
    val category = goals.categories.firstOrNull { it.key == draft.category }
    val duration = goals.durations.firstOrNull { it.key == draft.duration }

    Column(Modifier.fillMaxWidth(), horizontalAlignment = Alignment.CenterHorizontally) {
        IconTile(OwnifyIcons.flag, color = Ownify.Health, background = Ownify.Health.copy(alpha = 0.14f))
        T(words["done_title"], JStyle.Subtitle, Modifier.padding(top = Ownify.Space3).semantics { heading() }, align = TextAlign.Center)
    }

    // The goal as the board will show it: the page's own card, with nothing recorded yet.
    GoalCard(
        GoalCardModel(
            name = draft.name.trim(),
            categoryLabel = category?.label.orEmpty(),
            icon = category?.icon.orEmpty(),
            accent = category?.accent ?: "health",
            percent = null,
            ratio = 0.0,
            targetLabel = targetText(draft, goals) ?: "—",
            paused = false,
            completed = false,
            deadline = duration?.let { "Nog ${spanText(it.days)} · t/m ${dutchDate(endDate(it.days))}" }.orEmpty(),
            pausedChip = null
        ),
        variant = if (draft.priority == "primary") "primary" else "secondary",
        modifier = Modifier.padding(top = Ownify.Space4)
    )

    WizardNote(words["done_body"], OwnifyIcons.lock)
}

// ------------------------------------------------------------------- pieces

/** `.wizard__note`: a quiet box with an icon and a line. */
@Composable
private fun WizardNote(text: String, icon: androidx.compose.ui.graphics.vector.ImageVector?) {
    val shape = RoundedCornerShape(Ownify.RadiusSm)
    Row(
        Modifier
            .fillMaxWidth()
            .padding(top = Ownify.Space4)
            .clip(shape)
            .background(Ownify.fill(0.04f))
            .border(1.dp, Ownify.GlassHairline, shape)
            .cssPadding(PaddingValues(Ownify.Space3), border = 1.dp),
        horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)
    ) {
        if (icon != null) JIcon(icon, Modifier.padding(top = 2.dp), size = 15.dp, color = Ownify.TextSecondary)
        T(text, OwnifyType.style(Ownify.FsSmall, color = Ownify.TextSecondary), Modifier.weight(1f))
    }
}

/** `.wizard-suggestion`: a small pill that fills a field. */
@Composable
private fun Suggestion(text: String, onClick: () -> Unit) {
    InButton {
        val interaction = remember { MutableInteractionSource() }
        val shape = RoundedCornerShape(50)
        T(
            text,
            OwnifyType.style(Ownify.FsTiny, color = Ownify.TextSecondary),
            Modifier
                .press(interaction)
                .clip(shape)
                .background(Ownify.fill(0.05f))
                .border(1.dp, Ownify.GlassHairline, shape)
                .clickable(interaction, indication = null, role = Role.Button, onClick = blurring(onClick))
                .cssPadding(PaddingValues(horizontal = Ownify.Space3, vertical = Ownify.Space1), border = 1.dp),
            maxLines = 1
        )
    }
}

/**
 * `.wizard-type`: a label and a hint in a quiet box; chosen, it takes the
 * app's green (aria-pressed).
 */
@Composable
private fun Option(label: String, hint: String, chosen: Boolean, onClick: () -> Unit) {
    Choice(chosen = chosen, accent = Ownify.Health, green = true, onClick = onClick, modifier = Modifier.fillMaxWidth(), press = false) {
        T(label, OwnifyType.style(Ownify.FsSmall, FontWeight.SemiBold))
        if (hint.isNotEmpty()) T(hint, JStyle.Tiny, Modifier.padding(top = 2.dp))
    }
}

/**
 * The wizard's tiles (`.wizard-tile`, `.wizard-duration`, `.wizard-type`):
 * 12 around, a hairline, radius 20, `.035`; chosen, the border and fill take
 * the accent (40% and 14%) — the green (40% and 12%) for [green].
 */
@Composable
private fun Choice(
    chosen: Boolean,
    accent: Color,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    green: Boolean = false,
    enabled: Boolean = true,
    press: Boolean = true,
    content: @Composable ColumnScope.() -> Unit
) {
    InButton {
        val interaction = remember { MutableInteractionSource() }
        val shape = RoundedCornerShape(Ownify.RadiusMd)
        val fast = tween<Color>(Ownify.FastMs, easing = Ownify.Ease)
        val border by animateColorAsState(
            if (chosen) Ownify.mix(accent, 0.40f, Color.Transparent) else Ownify.GlassHairline, fast, label = "border"
        )
        val fill by animateColorAsState(
            if (chosen) Ownify.mix(accent, if (green) 0.12f else 0.14f, Color.Transparent) else Ownify.fill(0.035f), fast, label = "fill"
        )
        CompositionLocalProvider(LocalAccent provides LocalAccent.current) {
            Column(
                modifier
                    .then(if (press) Modifier.press(interaction, enabled = enabled) else Modifier)
                    .alpha(if (enabled) 1f else 0.45f)
                    .clip(shape)
                    .background(fill)
                    .border(1.dp, border, shape)
                    .clickable(interaction, indication = null, enabled = enabled, role = Role.Button, onClick = blurring(onClick))
                    .semantics(mergeDescendants = true) { selected = chosen }
                    .cssPadding(PaddingValues(Ownify.Space3), border = 1.dp),
                content = content
            )
        }
    }
}

/** A grid of [columns] equal columns, 8 apart, each row as tall as its tallest tile. */
@Composable
private fun <T> Grid(items: List<T>, columns: Int, modifier: Modifier, item: @Composable (T, Modifier) -> Unit) {
    Column(modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
        items.chunked(columns).forEach { row ->
            Row(Modifier.fillMaxWidth().height(IntrinsicSize.Max), horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
                row.forEach { item(it, Modifier.weight(1f).fillMaxHeight()) }
                repeat(columns - row.size) { Box(Modifier.weight(1f)) }
            }
        }
    }
}

// ------------------------------------------------------------ the answers

private fun isComplete(step: Int, draft: Draft, goals: Goals): Boolean = when (step) {
    1 -> draft.category != null
    2 -> draft.name.trim().length > 1 && draft.type != null
    // A source has to be chosen; "Geen data mogelijk" counts as choosing one.
    3 -> draft.sourceKind != null
    4 -> when {
        draft.type == "milestone" -> positive(draft.value) && draft.better != null
        draft.type == "accumulate" && draft.measure == null -> false
        draft.countsDays -> wholeDays(draft.days) && (!draft.needsDaily || positive(draft.daily))
        else -> positive(draft.value)
    }
    5 -> draft.duration != null && fitsDuration(draft.duration!!, draft, goals)
    else -> true
}

/** The target as one readable phrase, whatever kind of goal this is (targetText()). */
private fun targetText(draft: Draft, goals: Goals): String? {
    val words = goals.wizard
    return when {
        draft.type == "milestone" -> {
            if (!positive(draft.value)) return null
            withUnit(draft.value, draft.amountUnit) + when (draft.better) {
                "decrease" -> " · " + words["better_down"].lowercase(Locale.forLanguageTag("nl"))
                "increase" -> " · " + words["better_up"].lowercase(Locale.forLanguageTag("nl"))
                else -> ""
            }
        }
        draft.countsDays -> {
            if (!wholeDays(draft.days)) return null
            var text = daysText(draft.days) + if (draft.type == "streak") " op rij" else ""
            if (draft.needsDaily && positive(draft.daily)) {
                text += " · " + (if (draft.floor == "decrease") "hoogstens " else "minstens ") + withUnit(draft.daily, draft.sourceUnit) + " per dag"
            }
            text
        }
        draft.type == "accumulate" && draft.measure == "amount" -> if (positive(draft.value)) withUnit(draft.value, draft.amountUnit) else null
        else -> null
    }
}

/** What api/goals/create.php expects: only what applies to the chosen type. */
private fun payload(draft: Draft): Map<String, String> = buildMap {
    put("name", draft.name)
    put("category", draft.category ?: "other")
    put("type", draft.type.orEmpty())
    put("duration", draft.duration.orEmpty())
    put("priority", draft.priority)
    put("source_kind", draft.sourceKind.orEmpty())
    put("source_key", draft.sourceKey)
    if (draft.type == "accumulate") put("measure", draft.measure ?: "amount")
    if (draft.countsDays) {
        put("target_value", draft.days)
        if (draft.needsDaily) {
            put("daily_target", draft.daily)
            put("direction", draft.floor)
        }
    } else {
        put("target_value", draft.value)
        if (!draft.auto) put("target_unit", draft.unit)
        if (draft.type == "milestone") put("direction", draft.better.orEmpty())
    }
}

// ---------------------------------------------- goal-wizard.js, word for word

private val MONTHS = listOf("jan", "feb", "mrt", "apr", "mei", "jun", "jul", "aug", "sep", "okt", "nov", "dec")

/** "4 okt". */
internal fun dutchDate(date: LocalDate): String = "${date.dayOfMonth} ${MONTHS[date.monthValue - 1]}"

/** Today plus [days], on the phone's own calendar. */
internal fun endDate(days: Int): LocalDate = LocalDate.now().plusDays(days.toLong())

/** "3 maanden", "6 weken", "1 jaar". */
internal fun spanText(days: Int): String = when {
    days >= 330 -> Math.round(days / 365.0).let { if (it == 1L) "1 jaar" else "$it jaar" }
    days >= 60 -> "${Math.round(days / 30.0)} maanden"
    days >= 45 -> "${Math.round(days / 7.0)} weken"
    else -> if (days == 1) "1 dag" else "$days dagen"
}

/** "1 dag", "30 dagen" — the number as it was typed. */
internal fun daysText(n: String): String = if (jsNumber(n) == 1.0) "1 dag" else "$n dagen"

/** JavaScript's `Number(text)` for what can be typed here: blank is 0, anything unreadable is null. */
internal fun jsNumber(text: String): Double? {
    val t = text.trim()
    if (t.isEmpty()) return 0.0
    return t.toDoubleOrNull()?.takeIf { it.isFinite() }
}

internal fun positive(text: String): Boolean {
    val n = jsNumber(text.replaceFirst(',', '.')) ?: return false
    return text.trim().isNotEmpty() && n > 0
}

internal fun wholeDays(text: String): Boolean {
    val n = jsNumber(text) ?: return false
    return text.trim().isNotEmpty() && n >= 1 && Math.floor(n) == n && n <= 365
}

/** "10.000": the way the rest of the app writes a number (`toLocaleString('nl-NL')`, at most two decimals). */
internal fun dutchNumber(text: String): String {
    val n = jsNumber(text.replaceFirst(',', '.')) ?: return text
    val format = NumberFormat.getNumberInstance(Locale.forLanguageTag("nl-NL")).apply {
        maximumFractionDigits = 2
        roundingMode = RoundingMode.HALF_UP
    }
    // JavaScript rounds the shortest decimal that reads back as the number
    // ("2.355" → 2,36), not its binary value (2.35499…, which would give 2,35).
    return format.format(java.math.BigDecimal(n.toString()))
}

internal fun withUnit(number: String, unit: String): String =
    if (unit.isNotEmpty()) dutchNumber(number) + (if (unit == "%") "" else " ") + unit else dutchNumber(number)
