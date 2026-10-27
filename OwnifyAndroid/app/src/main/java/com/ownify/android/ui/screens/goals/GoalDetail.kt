package com.ownify.android.ui.screens.goals
import com.ownify.android.ui.design.NoteTone
import com.ownify.android.ui.design.note

import com.ownify.android.i18n.OwnifyPreferences
import com.ownify.android.i18n.tr
import com.ownify.android.i18n.trf
import androidx.compose.foundation.ScrollState
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalFocusManager
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.em
import androidx.compose.ui.unit.sp
import com.ownify.android.data.AppData
import com.ownify.android.data.Goal
import com.ownify.android.data.GoalDay
import com.ownify.android.data.GoalEntry
import com.ownify.android.data.OwnifyActions
import com.ownify.android.data.Outcome
import com.ownify.android.ui.app.DetailColumn
import com.ownify.android.ui.app.LocalShell
import com.ownify.android.ui.design.AccentChip
import com.ownify.android.ui.design.Btn
import com.ownify.android.ui.design.BtnLook
import com.ownify.android.ui.design.CardHint
import com.ownify.android.ui.design.Chip
import com.ownify.android.ui.design.GlassChip
import com.ownify.android.ui.design.GlassCta
import com.ownify.android.ui.design.IconTile
import com.ownify.android.ui.design.JCard
import com.ownify.android.ui.design.JIcon
import com.ownify.android.ui.design.JInput
import com.ownify.android.ui.design.JStyle
import com.ownify.android.ui.design.RangeSwitch
import com.ownify.android.ui.design.OwnifyIcons
import com.ownify.android.ui.design.LocalScreen
import com.ownify.android.ui.design.Meter
import com.ownify.android.ui.design.T
import com.ownify.android.ui.design.countUpText
import com.ownify.android.ui.design.cssPadding
import com.ownify.android.ui.design.rememberPlayOnSight
import com.ownify.android.ui.design.reveal
import com.ownify.android.ui.screens.health.EditorError
import com.ownify.android.ui.theme.Accent
import com.ownify.android.ui.theme.Ownify
import com.ownify.android.ui.theme.OwnifyType
import com.ownify.android.ui.theme.LocalAccent
import kotlinx.coroutines.launch
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import com.ownify.android.ui.design.focusSafely
import androidx.compose.ui.text.style.LineBreak

/**
 * One goal in full (pages/goal-detail.php), in the order it matters: the goal
 * — how far you are, the numbers, the period; Zelf bijhouden, for a goal kept
 * by hand, right under it; the Verloop, which now also says what feeds the
 * line and, on each point, what the day did and how far the goal was then
 * (what Wat telt mee and Recent said); the days, for a goal that counts them;
 * and Aanpassen.
 */
@Composable
fun GoalDetail(data: AppData, stored: Goal, scroll: ScrollState) {
    val goals = data.goals
    val goal = GoalBoard.goal(stored, goals)
    val copy = goals.detail
    val shell = LocalShell.current

    // Deleting the open goal closes its page (goals.js remove()).
    LaunchedEffect(GoalBoard.deleting(goal.id)) {
        if (GoalBoard.deleting(goal.id)) shell.closeDetail()
    }

    CompositionLocalProvider(LocalAccent provides Accent.of(goal.accent)) {
        DetailColumn(scroll, back = copy["back"] ?: tr("Doelen"), backAria = tr("Terug naar Doelen")) {
            Hero(data, goal)
            val entry = goal.entry
            if (goal.needsInput && !goal.isCompleted && entry != null) ManualEntry(goal, entry, copy)
            GoalHistory(goal, copy) { SourceFooter(goal) }
            if (goal.days.isNotEmpty()) Days(goal, copy)
            if (goal.periods.isNotEmpty()) Periods(goal, copy)
            if (!goal.isCompleted) Manage(data, goal, copy)
        }
    }
}

/** Sections 1 and 2: the name, the chips, the percentage against the target, the bar, the facts. */
@OptIn(ExperimentalLayoutApi::class)
/** Characters (code points) past which a goal's name is long (pages/goal-detail.php: mb_strlen > 40). */
internal const val GOAL_NAME_LONG = 40

@Composable
private fun Hero(data: AppData, goal: Goal) {
    val goals = data.goals
    val labels = goals.labels
    val copy = goals.detail
    val narrow = LocalScreen.current.narrow
    val accent = LocalAccent.current.color
    val empty = goal.percent == null
    val (play, sight) = rememberPlayOnSight()

    // The hero keeps its line short; the dates are in the rows underneath.
    val heroActive = if (goal.isCompleted) goal.lineActive else goal.remaining.orEmpty()
    val heroLine = if (goal.isPaused) labels["paused_line"].orEmpty() else heroActive

    JCard(Modifier.fillMaxWidth().reveal().then(sight)) {
        // .goal-hero__head
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)) {
            OwnifyIcons.named(goal.icon)?.let { IconTile(it, color = accent) }
            Column(Modifier.weight(1f)) {
                T("${goal.measurementLabel} · ${goal.kindLabel}", JStyle.Caption, uppercase = true)
                // A long name steps down to Title 3 with balanced lines (`.goal-hero__name.is-long`).
                val long = goal.name.codePointCount(0, goal.name.length) > GOAL_NAME_LONG
                T(
                    goal.name,
                    if (long) {
                        OwnifyType.style(Ownify.FsTitle3, FontWeight.SemiBold, tracking = Ownify.TrackingTitle, lineHeight = 1.25.em)
                            .copy(lineBreak = LineBreak.Heading)
                    } else {
                        OwnifyType.style(if (narrow) Ownify.FsTitle3 else Ownify.FsTitle1, FontWeight.SemiBold, tracking = (-0.025).em, lineHeight = 1.2.em)
                    },
                    Modifier.padding(top = Ownify.Space1).semantics { heading() }
                )
            }
        }

        // .goal-hero__chips — 16 above even when it holds nothing, as on the website.
        FlowRow(
            Modifier.fillMaxWidth().padding(top = Ownify.Space4),
            horizontalArrangement = Arrangement.spacedBy(Ownify.Space2),
            verticalArrangement = Arrangement.spacedBy(Ownify.Space2)
        ) {
            if (goal.isPrimary) AccentChip(labels["primary_chip"].orEmpty(), accent)
            if (goal.isPaused) Chip(labels["paused_chip"].orEmpty(), quiet = true)
            if (goal.isCompleted) AccentChip(if (goal.isReached) tr("Behaald") else tr("Afgelopen"), accent)
            Chip(goal.categoryLabel, muted = true)
            goal.durationLabel?.let { Chip(it, muted = true) }
        }

        // .goal-hero__figures: the percentage and the target on one baseline.
        Row(Modifier.fillMaxWidth().padding(top = Ownify.Space5), horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)) {
            Row(Modifier.weight(1f).alignByBaseline()) {
                T(
                    countUpText(goal.percent?.toString() ?: "—", play && !empty),
                    OwnifyType.style(
                        if (narrow) Ownify.FsScoreMd else LocalScreen.current.fsScore, FontWeight.Bold,
                        if (empty) Ownify.TextSecondary else Ownify.TextPrimary,
                        tracking = (-0.045).em, lineHeight = 1.em, tabular = true
                    ),
                    Modifier.alignByBaseline()
                )
                if (!empty) T("%", OwnifyType.style(Ownify.FsHeadline, color = Ownify.TextMuted), Modifier.alignByBaseline().padding(start = 3.dp))
            }
            Column(Modifier.alignByBaseline(), horizontalAlignment = Alignment.End) {
                T(labels["target"].orEmpty(), JStyle.Caption, uppercase = true, align = TextAlign.End)
                T(
                    goal.targetLabel.ifEmpty { "—" },
                    OwnifyType.style(Ownify.FsHeadline, FontWeight.SemiBold, tracking = (-0.015).em, tabular = true),
                    Modifier.padding(top = Ownify.Space1),
                    align = TextAlign.End
                )
            }
        }

        Meter(
            share = if (empty) null else goal.ratio.toFloat().coerceIn(0f, 1f),
            play = play,
            accent = accent,
            height = 12.dp,
            emptyHeight = 4.dp,
            modifier = Modifier
                .padding(top = Ownify.Space3)
                .semantics { contentDescription = if (empty) tr("nog geen voortgang") else trf("%d%% voltooid", goal.percent) }
        )

        T(heroLine, JStyle.Meta, Modifier.padding(top = Ownify.Space3))

        // .goal-hero__facts
        Column(Modifier.fillMaxWidth().padding(top = Ownify.Space4)) {
            Box(Modifier.fillMaxWidth().height(1.dp).background(Ownify.GlassHairline))
            Column(Modifier.fillMaxWidth().padding(top = Ownify.Space2)) {
                FactRow(goal.currentTitle.ifEmpty { labels["current"].orEmpty() }, goal.currentLabel.ifEmpty { null }, first = true)
                goal.extraFacts.forEach { (label, value) -> FactRow(label, value) }
                PeriodRow(
                    copy["period"] ?: tr("Periode"),
                    tr("Gestart") + ": " + goal.startLabel.ifEmpty { "—" },
                    tr("Eindigt") + ": " + (goal.endLabel ?: "—")
                )
            }
        }

        CardHint(if (goal.isPaused) copy["paused_body"].orEmpty() else goal.note.orEmpty(), icon = null, plain = true)
    }
}

/** `.metric-row--period`: Gestart and Eindigt in one row; on a narrow phone Eindigt moves under Gestart, never mid-date. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun PeriodRow(label: String, started: String, ends: String) {
    val style = OwnifyType.style(Ownify.FsSmall, FontWeight.SemiBold, Ownify.TextPrimary, tabular = true)
    Column(Modifier.fillMaxWidth()) {
        Box(Modifier.fillMaxWidth().height(1.dp).background(Ownify.GlassHairline))
        Row(
            Modifier
                .fillMaxWidth()
                .padding(top = Ownify.Space3, bottom = Ownify.Space3)
                .semantics(mergeDescendants = true) { },
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)
        ) {
            T(label, OwnifyType.style(Ownify.FsSmall, color = Ownify.TextSecondary))
            FlowRow(
                Modifier.weight(1f),
                horizontalArrangement = Arrangement.spacedBy(Ownify.Space2, Alignment.End),
                verticalArrangement = Arrangement.spacedBy(2.dp)
            ) {
                T(started, style, maxLines = 1)
                T("·", style.copy(color = Ownify.TextMuted), Modifier.clearAndSetSemantics { })
                T(ends, style, maxLines = 1)
            }
        }
    }
}

/** `.metric-row` as the hero's facts use it: the name and the value, a hairline between rows. */
@Composable
private fun FactRow(label: String, value: String?, first: Boolean = false) {
    val empty = value == null
    Column(Modifier.fillMaxWidth()) {
        if (!first) Box(Modifier.fillMaxWidth().height(1.dp).background(Ownify.GlassHairline))
        Row(
            Modifier
                .fillMaxWidth()
                .padding(top = if (first) 0.dp else Ownify.Space3, bottom = Ownify.Space3)
                .semantics(mergeDescendants = true) { },
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)
        ) {
            T(label, OwnifyType.style(Ownify.FsSmall, color = Ownify.TextSecondary), Modifier.weight(1f))
            T(
                value ?: "—",
                OwnifyType.style(
                    Ownify.FsSmall,
                    if (empty) FontWeight.Medium else FontWeight.SemiBold,
                    if (empty) Ownify.TextMuted else Ownify.TextPrimary,
                    tabular = true
                ),
                align = TextAlign.End
            )
        }
    }
}

/** A card head: an icon tile and the eyebrow, 16 above the body (`.card__head--compact`). */
@Composable
private fun CompactHead(icon: ImageVector, title: String, notice: String? = null) {
    Row(
        Modifier.fillMaxWidth().padding(bottom = Ownify.Space4),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)
    ) {
        IconTile(icon, notice = notice)
        T(title, JStyle.Eyebrow, Modifier.semantics { heading() })
    }
}

/**
 * Per dag: one block per day of a goal that counts days. A day without data
 * is an outline — never red — and today, until it counts, is open.
 */
@Composable
private fun Days(goal: Goal, copy: Map<String, String>) {
    JCard(Modifier.fillMaxWidth().reveal()) {
        // .card__head: the eyebrow, and how many days made it.
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)) {
            Row(Modifier.weight(1f), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)) {
                IconTile(OwnifyIcons.check)
                T(copy["days"] ?: tr("Per dag"), JStyle.Eyebrow, Modifier.semantics { heading() })
            }
            Chip(goal.daysChip ?: trf("%1\$d van %2\$d", goal.daysMet, goal.daysTotal), muted = true)
        }

        // .day-calendar: the weekday initials, then the weeks — from the day
        // the account's week starts on (Voorkeuren; the server lays the days out from it too).
        Column(Modifier.fillMaxWidth().padding(top = Ownify.Space3)) {
            Row(Modifier.fillMaxWidth().padding(bottom = 6.dp).clearAndSetSemantics { }, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                OwnifyPreferences.weekInitials().forEach {
                    T(
                        it,
                        OwnifyType.style(Ownify.FsTiny, FontWeight.SemiBold, Ownify.TextFaint, tracking = Ownify.TrackingWide),
                        Modifier.weight(1f),
                        align = TextAlign.Center
                    )
                }
            }
            Column(
                Modifier.fillMaxWidth().semantics { contentDescription = trf("%d van %d dagen gehaald", goal.daysMet, goal.daysTotal) },
                verticalArrangement = Arrangement.spacedBy(6.dp)
            ) {
                goal.days.chunked(7).forEach { week ->
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        week.forEach { DayBlock(it, Modifier.weight(1f)) }
                        repeat(7 - week.size) { Box(Modifier.weight(1f)) }
                    }
                }
            }
        }

        val note = (goal.daysNote ?: copy["days_note"].orEmpty()) +
            if (goal.daysTotal > goal.days.size) " " + copy["days_window"].orEmpty() else ""
        CardHint(note.trim(), icon = null, plain = true)
    }
}

/** `.day-block`: a rounded square per day, coloured by how the day went. */
@Composable
private fun DayBlock(day: GoalDay, modifier: Modifier) {
    val shape = RoundedCornerShape(Ownify.RadiusSm)
    if (day.state == "before") {
        Box(modifier.aspectRatio(1f).clearAndSetSemantics { })
        return
    }
    val (fill, border) = when (day.state) {
        "met" -> Ownify.Health to Color.Transparent
        "missed" -> Ownify.Miss to Color.Transparent
        "unknown" -> Color.Transparent to Ownify.GlassBorder
        "future" -> Ownify.GlassHairline to Color.Transparent
        "pending" -> Ownify.GlassHairline to Ownify.mix(Ownify.Health, 0.55f, Color.Transparent)
        else -> Ownify.GlassSoft to Color.Transparent
    }
    val coloured = day.state == "met" || day.state == "missed"
    Box(
        modifier
            .aspectRatio(1f)
            .clip(shape)
            .background(fill)
            .border(1.dp, border, shape)
            .semantics { contentDescription = day.title.orEmpty() },
        contentAlignment = Alignment.Center
    ) {
        T(
            day.day?.toString().orEmpty(),
            // `color: var(--bg)` names no colour, so the number keeps the card's text colour.
            OwnifyType.style(Ownify.FsTiny, color = if (coloured) Ownify.TextPrimary else Ownify.TextFaint, tabular = true),
            Modifier.alpha(if (coloured) 0.75f else 0.55f)
        )
    }
}

/**
 * Logging a goal kept by hand — only ever here, on its own page
 * (api/goals/progress.php): a new measurement (Mijlpaal, the latest
 * counts), an amount (Optellen), or today ticked off (Streak) — and the tick
 * taken back. The page then shows the server's new figures.
 */
@Composable
private fun ManualEntry(goal: Goal, entry: GoalEntry, copy: Map<String, String>) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val focus = LocalFocusManager.current
    var value by rememberSaveable(goal.id) { mutableStateOf("") }
    var saving by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val field = remember { FocusRequester() }

    fun save(done: Boolean?) {
        error = null
        if (done == null && value.trim().isEmpty()) {
            error = tr("Vul een waarde in.")
            field.focusSafely()
            return
        }
        focus.clearFocus()
        saving = true
        val fields = buildMap {
            put("goal_id", goal.id)
            // Typed in the goal's own unit; the server keeps it in the unit it stores.
            if (done == null) put("value", value.trim()) else put("done", if (done) "1" else "0")
        }
        scope.launch {
            when (val outcome = OwnifyActions.form(context, "api/goals/progress.php", fields, tr("Dit kon niet worden opgeslagen."))) {
                is Outcome.Done -> value = ""
                is Outcome.Refused -> error = outcome.message
                Outcome.SignedOut -> Unit
            }
            saving = false
        }
    }

    JCard(Modifier.fillMaxWidth().reveal()) {
        CompactHead(OwnifyIcons.check, copy["manual"].orEmpty(), notice = "goal:" + goal.id)

        if (entry.kind == "tick") {
            if (!entry.done) {
                GlassCta(entry.label, onClick = { save(done = true) }, modifier = Modifier.fillMaxWidth(), enabled = !saving, loading = saving)
            } else {
                Row(Modifier.fillMaxWidth().heightIn(min = 44.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)) {
                    Row(Modifier.weight(1f), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
                        JIcon(OwnifyIcons.check, size = 17.dp, color = Ownify.Health)
                        T(tr("Vandaag afgevinkt"), OwnifyType.style(Ownify.FsLabel, FontWeight.SemiBold, Ownify.Health))
                    }
                    GlassChip(entry.undo ?: tr("Vandaag ongedaan maken"), chosen = false, enabled = !saving, onClick = { save(done = false) })
                }
            }
        } else {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
                JInput(
                    value, { value = it.take(16) },
                    modifier = Modifier.weight(1f).focusRequester(field),
                    placeholder = entry.placeholder ?: entry.label,
                    label = entry.label,
                    keyboard = KeyboardOptions(keyboardType = KeyboardType.Decimal, imeAction = ImeAction.Done),
                    actions = KeyboardActions(onDone = { if (!saving) save(null) })
                )
                GlassCta(entry.button ?: copy["manual_save"] ?: tr("Opslaan"), onClick = { save(null) }, enabled = !saving, loading = saving)
            }
        }

        error?.let { EditorError(it) }
        CardHint(entry.note.orEmpty(), icon = null, plain = true)
    }
}

/**
 * Per periode: a per-period streak's weeks (months…), each on its own,
 * counted from the goal's start — met as soon as its count is reached,
 * missed once it is over without; a last one too short to ask anything
 * counts neither way.
 */
@Composable
private fun Periods(goal: Goal, copy: Map<String, String>) {
    JCard(Modifier.fillMaxWidth().reveal()) {
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)) {
            Row(Modifier.weight(1f), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)) {
                IconTile(OwnifyIcons.calendar)
                T(copy["periods"] ?: tr("Per periode"), JStyle.Eyebrow, Modifier.semantics { heading() })
            }
            goal.periodsChip?.let { Chip(it, muted = true) }
        }
        Column(Modifier.fillMaxWidth().padding(top = Ownify.Space3)) {
            goal.periods.forEachIndexed { i, p ->
                if (i > 0) Box(Modifier.fillMaxWidth().height(1.dp).background(Ownify.GlassHairline))
                val state = when (p.state) {
                    "met" -> tr("gehaald")
                    "missed" -> tr("niet gehaald")
                    "skipped" -> tr("telt niet mee")
                    else -> tr("nog bezig")
                }
                Row(
                    Modifier
                        .fillMaxWidth()
                        .padding(vertical = Ownify.Space2)
                        .alpha(if (p.state == "skipped") 0.55f else 1f)
                        .semantics(mergeDescendants = true) { contentDescription = "${p.label}, ${p.dates}, ${p.value}, $state" },
                    verticalAlignment = Alignment.CenterVertically,
                    horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)
                ) {
                    val met = p.state == "met"
                    Box(
                        Modifier
                            .size(24.dp)
                            .clip(CircleShape)
                            .background(if (met) Ownify.HealthFill else Color.Transparent)
                            .border(1.5.dp, if (met) Ownify.HealthStrong else if (p.current) Ownify.Health else Ownify.GlassBorder, CircleShape),
                        contentAlignment = Alignment.Center
                    ) {
                        if (met) JIcon(OwnifyIcons.check, size = 13.dp, color = Ownify.Health)
                    }
                    Column(Modifier.weight(1f)) {
                        T(p.label, OwnifyType.style(Ownify.FsSmall, FontWeight.SemiBold))
                        T(p.dates, OwnifyType.style(Ownify.FsTiny, color = Ownify.TextMuted))
                    }
                    T(p.value, OwnifyType.style(Ownify.FsSmall, FontWeight.SemiBold, if (met) Ownify.TextPrimary else Ownify.TextSecondary, tabular = true))
                }
            }
        }
    }
}

/**
 * `.goal-chart__source`, under the Verloop: what feeds the line — or that
 * nothing does — and how it moves. Wat telt mee said this in a block of its own.
 */
@Composable
private fun SourceFooter(goal: Goal) {
    Column(Modifier.fillMaxWidth().padding(top = Ownify.Space4)) {
        Box(Modifier.fillMaxWidth().height(1.dp).background(Ownify.GlassHairline))
        Column(Modifier.fillMaxWidth().padding(top = Ownify.Space3)) {
            if (goal.sourceList.isEmpty()) {
                T(tr("Dit doel is nog niet aan gezondheidsgegevens gekoppeld."), JStyle.Meta)
            } else {
                goal.sourceList.forEachIndexed { i, source ->
                    val accent = Accent.of(source.accent).color
                    RowFrame(first = i == 0) {
                        // The badge's own 12 and the row's 12.
                        Box(
                            Modifier
                                .padding(end = Ownify.Space3)
                                .size(30.dp)
                                .clip(RoundedCornerShape(10.dp))
                                .background(Ownify.mix(accent, 0.15f, Color.Transparent))
                                .clearAndSetSemantics { },
                            contentAlignment = Alignment.Center
                        ) {
                            OwnifyIcons.named(source.icon)?.let { JIcon(it, size = 14.dp, color = accent) }
                        }
                        LabelWithNote(source.label, source.note, Modifier.weight(1f))
                    }
                }
            }
            CardHint(
                if (goal.isManual) tr("Je voortgang verandert alleen door wat je zelf invult.")
                else tr("Je voortgang werkt zichzelf bij zodra er nieuwe gegevens binnenkomen.")
            )
        }
    }
}

/** A `.metric-row` frame: the hairline above all but the first, 12 either side. */
@Composable
private fun RowFrame(first: Boolean, content: @Composable androidx.compose.foundation.layout.RowScope.() -> Unit) {
    Column(Modifier.fillMaxWidth()) {
        if (!first) Box(Modifier.fillMaxWidth().height(1.dp).background(Ownify.GlassHairline))
        Row(
            Modifier
                .fillMaxWidth()
                .padding(top = if (first) 0.dp else Ownify.Space3, bottom = Ownify.Space3)
                .semantics(mergeDescendants = true) { },
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(Ownify.Space3),
            content = content
        )
    }
}

/** `.goal-source__label`: the name, strong, and a tiny line under it. */
@Composable
private fun LabelWithNote(label: String, note: String, modifier: Modifier) {
    Column(modifier, verticalArrangement = Arrangement.spacedBy(2.dp)) {
        T(label, OwnifyType.style(Ownify.FsSmall, FontWeight.SemiBold))
        if (note.isNotEmpty()) T(note, JStyle.Footnote)
    }
}

/**
 * Aanpassen: Primair | Secundair, pause or resume, delete — which asks first,
 * in place. The board moves at once; the server hears of it at the same time,
 * and once it has answered the state is read again, so the board, this page
 * and Overzicht show what is stored.
 */
@Composable
private fun Manage(data: AppData, goal: Goal, copy: Map<String, String>) {
    val context = LocalContext.current
    var confirming by remember(goal.id) { mutableStateOf(false) }
    // A goal on its own has no one to hand the primary slot to (goal_set_secondary()).
    val alone = goal.isPrimary && GoalBoard.board(data.goals).active < 2

    JCard(Modifier.fillMaxWidth().reveal()) {
        CompactHead(OwnifyIcons.sliders, copy["manage"].orEmpty())

        Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
            // `.goal-priority`: the shared segmented switch, the one this goal is pressed.
            RangeSwitch(
                options = listOf("primary" to copy["priority_primary"].orEmpty(), "secondary" to copy["priority_secondary"].orEmpty()),
                selected = if (goal.isPrimary) "primary" else "secondary",
                onSelect = { chosen ->
                    when {
                        chosen == "primary" && !goal.isPrimary -> GoalBoard.promote(context, goal.id)
                        chosen == "secondary" && goal.isPrimary -> GoalBoard.demote(context, goal.id)
                    }
                },
                modifier = Modifier.padding(bottom = Ownify.Space1),
                wide = true,
                label = copy["priority"],
                disabled = if (alone) setOf("secondary") else emptySet()
            )
            if (alone) {
                T(
                    copy["priority_alone"].orEmpty(),
                    OwnifyType.style(Ownify.FsTiny, color = Ownify.TextMuted),
                    Modifier.padding(horizontal = Ownify.Space1).padding(bottom = Ownify.Space1)
                )
            }

            ManageButton(
                if (goal.isPaused) OwnifyIcons.play else OwnifyIcons.pause,
                if (goal.isPaused) copy["resume"].orEmpty() else copy["pause"].orEmpty()
            ) { GoalBoard.pause(context, goal.id, !goal.isPaused) }

            if (!confirming) {
                ManageButton(OwnifyIcons.trash, copy["delete"].orEmpty(), text = Ownify.TextSecondary) { confirming = true }
            } else {
                // .goal-confirm
                Column(
                    Modifier
                        .fillMaxWidth()
                        .padding(top = Ownify.Space2)
                        .note(NoteTone.Attention)
                        .cssPadding(PaddingValues(Ownify.Space4), border = 1.dp)
                ) {
                    T(copy["delete_confirm"].orEmpty(), OwnifyType.style(Ownify.FsSmall))
                    Row(Modifier.fillMaxWidth().padding(top = Ownify.Space3), horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
                        Btn(copy["delete_no"].orEmpty(), onClick = { confirming = false }, modifier = Modifier.weight(1f))
                        Btn(
                            copy["delete_yes"].orEmpty(),
                            onClick = {
                                confirming = false
                                GoalBoard.delete(context, goal.id)
                            },
                            modifier = Modifier.weight(1f),
                            look = BtnLook.Caution
                        )
                    }
                }
            }
        }
    }
}

/** `.goal-manage__button`: full width, its icon and label from the left. */
@Composable
private fun ManageButton(icon: ImageVector, label: String, text: Color = Ownify.TextPrimary, onClick: () -> Unit) {
    Btn(label, onClick = onClick, modifier = Modifier.fillMaxWidth(), look = BtnLook(text = text)) {
        Row(Modifier.weight(1f), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)) {
            JIcon(icon, size = 17.dp, color = Ownify.TextMuted)
            T(label, OwnifyType.style(Ownify.FsSmall, FontWeight.SemiBold, text), maxLines = 1)
        }
    }
}
