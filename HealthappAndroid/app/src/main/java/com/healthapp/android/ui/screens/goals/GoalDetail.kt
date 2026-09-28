package com.healthapp.android.ui.screens.goals

import androidx.compose.foundation.ScrollState
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
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
import com.healthapp.android.data.AppData
import com.healthapp.android.data.Goal
import com.healthapp.android.data.GoalDay
import com.healthapp.android.data.GoalEntry
import com.healthapp.android.data.JoluActions
import com.healthapp.android.data.Outcome
import com.healthapp.android.ui.app.DetailColumn
import com.healthapp.android.ui.app.LocalShell
import com.healthapp.android.ui.design.AccentChip
import com.healthapp.android.ui.design.Btn
import com.healthapp.android.ui.design.BtnLook
import com.healthapp.android.ui.design.CardHint
import com.healthapp.android.ui.design.Chip
import com.healthapp.android.ui.design.IconTile
import com.healthapp.android.ui.design.JCard
import com.healthapp.android.ui.design.JIcon
import com.healthapp.android.ui.design.JInput
import com.healthapp.android.ui.design.JStyle
import com.healthapp.android.ui.design.JoluIcons
import com.healthapp.android.ui.design.LocalScreen
import com.healthapp.android.ui.design.Meter
import com.healthapp.android.ui.design.T
import com.healthapp.android.ui.design.countUpText
import com.healthapp.android.ui.design.rememberPlayOnSight
import com.healthapp.android.ui.design.reveal
import com.healthapp.android.ui.screens.health.EditorError
import com.healthapp.android.ui.theme.Accent
import com.healthapp.android.ui.theme.Jolu
import com.healthapp.android.ui.theme.JoluType
import com.healthapp.android.ui.theme.LocalAccent
import kotlinx.coroutines.launch

/**
 * One goal in full (pages/goal-detail.php), in the order it matters: how far
 * you are and the numbers behind it; the days, for a goal that counts them;
 * the entry, for a goal kept by hand; how it has moved; what feeds it; what
 * happened lately; and what you can do about it.
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
        DetailColumn(scroll, back = copy["back"] ?: "Doelen", backAria = "Terug naar Doelen") {
            Hero(data, goal)
            if (goal.days.isNotEmpty()) Days(goal, copy)
            val entry = goal.entry
            if (goal.needsInput && !goal.isCompleted && entry != null) ManualEntry(goal, entry, copy)
            GoalHistory(goal, copy)
            Sources(goal, copy)
            if (goal.activity.isNotEmpty()) Activity(goal, copy)
            if (!goal.isCompleted) Manage(goal, copy)
        }
    }
}

/** Sections 1 and 2: the name, the chips, the percentage against the target, the bar, the facts. */
@OptIn(ExperimentalLayoutApi::class)
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
    val heroActive = if (goal.isCompleted) {
        goal.endLabel?.let { (labels["completed_on"] ?: "%s").replace("%s", it) } ?: "Behaald"
    } else {
        goal.remaining.orEmpty()
    }
    val heroLine = if (goal.isPaused) labels["paused_line"].orEmpty() else heroActive

    JCard(Modifier.fillMaxWidth().reveal().then(sight)) {
        // .goal-hero__head
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Jolu.Space3)) {
            JoluIcons.named(goal.icon)?.let { IconTile(it, color = accent) }
            Column(Modifier.weight(1f)) {
                T("${goal.categoryLabel} · ${goal.typeLabel}", JStyle.Caption, uppercase = true)
                T(
                    goal.name,
                    JoluType.style(if (narrow) 19.sp else 22.sp, FontWeight.SemiBold, tracking = (-0.025).em, lineHeight = 1.2.em),
                    Modifier.padding(top = Jolu.Space1).semantics { heading() }
                )
            }
        }

        // .goal-hero__chips — 16 above even when it holds nothing, as on the website.
        FlowRow(
            Modifier.fillMaxWidth().padding(top = Jolu.Space4),
            horizontalArrangement = Arrangement.spacedBy(Jolu.Space2),
            verticalArrangement = Arrangement.spacedBy(Jolu.Space2)
        ) {
            if (goal.isPrimary) AccentChip(labels["primary_chip"].orEmpty(), accent)
            if (goal.isPaused) Chip(labels["paused_chip"].orEmpty(), quiet = true)
            if (goal.isCompleted) AccentChip(goals.views.firstOrNull { it.first == "completed" }?.second.orEmpty(), accent)
            goal.durationLabel?.let { Chip(it, muted = true) }
        }

        // .goal-hero__figures: the percentage and the target on one baseline.
        Row(Modifier.fillMaxWidth().padding(top = Jolu.Space5), horizontalArrangement = Arrangement.spacedBy(Jolu.Space3)) {
            Row(Modifier.weight(1f).alignByBaseline()) {
                T(
                    countUpText(goal.percent?.toString() ?: "—", play && !empty),
                    JoluType.style(
                        if (narrow) 38.4.sp else LocalScreen.current.fsScore, FontWeight.Bold,
                        if (empty) Jolu.TextSecondary else Jolu.TextPrimary,
                        tracking = (-0.045).em, lineHeight = 1.em, tabular = true
                    ),
                    Modifier.alignByBaseline()
                )
                if (!empty) T("%", JoluType.style(17.sp, color = Jolu.TextMuted), Modifier.alignByBaseline().padding(start = 3.dp))
            }
            Column(Modifier.alignByBaseline(), horizontalAlignment = Alignment.End) {
                T(labels["target"].orEmpty(), JStyle.Caption, uppercase = true, align = TextAlign.End)
                T(
                    goal.targetLabel.ifEmpty { "—" },
                    JoluType.style(17.sp, FontWeight.SemiBold, tracking = (-0.015).em, tabular = true),
                    Modifier.padding(top = Jolu.Space1),
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
                .padding(top = Jolu.Space3)
                .semantics { contentDescription = if (empty) "nog geen voortgang" else "${goal.percent}% voltooid" }
        )

        T(heroLine, JStyle.Meta, Modifier.padding(top = Jolu.Space3))

        // .goal-hero__facts
        Column(Modifier.fillMaxWidth().padding(top = Jolu.Space4)) {
            Box(Modifier.fillMaxWidth().height(1.dp).background(Jolu.GlassHairline))
            Column(Modifier.fillMaxWidth().padding(top = Jolu.Space2)) {
                FactRow(goal.currentTitle.ifEmpty { labels["current"].orEmpty() }, goal.currentLabel.ifEmpty { null }, first = true)
                goal.extraFacts.forEach { (label, value) -> FactRow(label, value) }
                FactRow("Gestart", goal.startLabel.ifEmpty { null })
                FactRow(
                    if (goal.isCompleted) "Looptijd" else "Eindigt",
                    if (goal.isCompleted) goal.tookLabel ?: "—" else goal.endLabel ?: labels["no_deadline"].orEmpty()
                )
            }
        }

        CardHint(if (goal.isPaused) copy["paused_body"].orEmpty() else goal.note.orEmpty(), icon = null, plain = true)
    }
}

/** `.metric-row` as the hero's facts use it: the name and the value, a hairline between rows. */
@Composable
private fun FactRow(label: String, value: String?, first: Boolean = false) {
    val empty = value == null
    Column(Modifier.fillMaxWidth()) {
        if (!first) Box(Modifier.fillMaxWidth().height(1.dp).background(Jolu.GlassHairline))
        Row(
            Modifier
                .fillMaxWidth()
                .padding(top = if (first) 0.dp else Jolu.Space3, bottom = Jolu.Space3)
                .semantics(mergeDescendants = true) { },
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(Jolu.Space3)
        ) {
            T(label, JoluType.style(Jolu.FsSmall, color = Jolu.TextSecondary), Modifier.weight(1f))
            T(
                value ?: "—",
                JoluType.style(
                    Jolu.FsSmall,
                    if (empty) FontWeight.Medium else FontWeight.SemiBold,
                    if (empty) Jolu.TextMuted else Jolu.TextPrimary,
                    tabular = true
                ),
                align = TextAlign.End
            )
        }
    }
}

/** A card head: an icon tile and the eyebrow, 16 above the body (`.card__head--compact`). */
@Composable
private fun CompactHead(icon: ImageVector, title: String) {
    Row(
        Modifier.fillMaxWidth().padding(bottom = Jolu.Space4),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Jolu.Space3)
    ) {
        IconTile(icon)
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
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Jolu.Space3)) {
            Row(Modifier.weight(1f), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Jolu.Space3)) {
                IconTile(JoluIcons.check)
                T(copy["days"] ?: "Per dag", JStyle.Eyebrow, Modifier.semantics { heading() })
            }
            Chip(goal.daysChip ?: "${goal.daysMet} van ${goal.daysTotal}", muted = true)
        }

        // .day-calendar: the weekday initials, then the weeks.
        Column(Modifier.fillMaxWidth().padding(top = Jolu.Space3)) {
            Row(Modifier.fillMaxWidth().padding(bottom = 6.dp).clearAndSetSemantics { }, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                listOf("M", "D", "W", "D", "V", "Z", "Z").forEach {
                    T(
                        it,
                        JoluType.style(Jolu.FsTiny, FontWeight.SemiBold, Jolu.TextFaint, tracking = Jolu.TrackingWide),
                        Modifier.weight(1f),
                        align = TextAlign.Center
                    )
                }
            }
            Column(
                Modifier.fillMaxWidth().semantics { contentDescription = "${goal.daysMet} van ${goal.daysTotal} dagen gehaald" },
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
    val shape = RoundedCornerShape(Jolu.RadiusSm)
    if (day.state == "before") {
        Box(modifier.aspectRatio(1f).clearAndSetSemantics { })
        return
    }
    val (fill, border) = when (day.state) {
        "met" -> Jolu.Health to Color.Transparent
        "missed" -> Jolu.Miss to Color.Transparent
        "unknown" -> Color.Transparent to Jolu.GlassBorder
        "future" -> Jolu.GlassHairline to Color.Transparent
        "pending" -> Jolu.GlassHairline to Jolu.mix(Jolu.Health, 0.55f, Color.Transparent)
        else -> Jolu.GlassSoft to Color.Transparent
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
            JoluType.style(Jolu.FsTiny, color = if (coloured) Jolu.TextPrimary else Jolu.TextFaint, tabular = true),
            Modifier.alpha(if (coloured) 0.75f else 0.55f)
        )
    }
}

/**
 * The entry for a goal kept by hand: a result or an amount and its button,
 * or today's tick — saved to api/goals/progress.php, and the goal's page
 * then shows the server's new figures.
 */
@Composable
private fun ManualEntry(goal: Goal, entry: GoalEntry, copy: Map<String, String>) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val focus = LocalFocusManager.current
    var value by rememberSaveable(goal.id) { mutableStateOf("") }
    var saving by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    fun save(tick: Boolean) {
        error = null
        if (!tick && value.trim().isEmpty()) {
            error = "Vul een waarde in."
            return
        }
        focus.clearFocus()
        saving = true
        val fields = buildMap {
            put("goal_id", goal.id)
            if (!tick) put("value", value.trim())
        }
        scope.launch {
            when (val outcome = JoluActions.form(context, "api/goals/progress.php", fields, "Dit kon niet worden opgeslagen.")) {
                is Outcome.Done -> value = ""
                is Outcome.Refused -> error = outcome.message
                Outcome.SignedOut -> Unit
            }
            saving = false
        }
    }

    JCard(Modifier.fillMaxWidth().reveal()) {
        CompactHead(JoluIcons.check, copy["manual"].orEmpty())

        if (entry.kind == "tick") {
            Btn(
                if (entry.done) "Vandaag afgevinkt" else entry.label,
                onClick = { save(tick = true) },
                enabled = !entry.done && !saving,
                icon = JoluIcons.check,
                iconSize = 17.dp,
                modifier = Modifier.fillMaxWidth()
            )
        } else {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Jolu.Space2)) {
                JInput(
                    value, { value = it.take(16) },
                    modifier = Modifier.weight(1f),
                    placeholder = entry.placeholder ?: entry.label,
                    label = entry.label,
                    keyboard = KeyboardOptions(keyboardType = KeyboardType.Decimal, imeAction = ImeAction.Done),
                    actions = KeyboardActions(onDone = { if (!saving) save(tick = false) })
                )
                Btn(entry.button ?: copy["manual_save"] ?: "Opslaan", onClick = { save(tick = false) }, enabled = !saving)
            }
        }

        error?.let { EditorError(it) }
        CardHint(entry.note.orEmpty(), icon = null, plain = true)
    }
}

/** Section 4: what feeds the number — or that nothing does — and how it moves. */
@Composable
private fun Sources(goal: Goal, copy: Map<String, String>) {
    JCard(Modifier.fillMaxWidth().reveal()) {
        CompactHead(JoluIcons.pulse, copy["sources"].orEmpty())
        if (goal.sourceList.isEmpty()) {
            T("Dit doel is nog niet aan gezondheidsgegevens gekoppeld.", JStyle.Meta)
        } else {
            Column(Modifier.fillMaxWidth()) {
                goal.sourceList.forEachIndexed { i, source ->
                    val accent = Accent.of(source.accent).color
                    RowFrame(first = i == 0) {
                        // The badge's own 12 and the row's 12.
                        Box(
                            Modifier
                                .padding(end = Jolu.Space3)
                                .size(28.dp)
                                .clip(RoundedCornerShape(10.dp))
                                .background(Jolu.mix(accent, 0.15f, Color.Transparent))
                                .clearAndSetSemantics { },
                            contentAlignment = Alignment.Center
                        ) {
                            JoluIcons.named(source.icon)?.let { JIcon(it, size = 14.dp, color = accent) }
                        }
                        LabelWithNote(source.label, source.note, Modifier.weight(1f))
                    }
                }
            }
        }
        CardHint(
            if (goal.isManual) "Je voortgang verandert alleen door wat je zelf invult."
            else "Je voortgang werkt zichzelf bij zodra er nieuwe gegevens binnenkomen."
        )
    }
}

/** Recent activity: what happened to this goal lately, newest first. */
@Composable
private fun Activity(goal: Goal, copy: Map<String, String>) {
    JCard(Modifier.fillMaxWidth().reveal()) {
        CompactHead(JoluIcons.ranking, copy["activity"].orEmpty())
        Column(Modifier.fillMaxWidth()) {
            goal.activity.forEachIndexed { i, entry ->
                RowFrame(first = i == 0) {
                    LabelWithNote(entry.label, entry.meta, Modifier.weight(1f))
                    T(entry.value, JoluType.style(Jolu.FsSmall, FontWeight.SemiBold, tabular = true), align = TextAlign.End)
                }
            }
        }
    }
}

/** A `.metric-row` frame: the hairline above all but the first, 12 either side. */
@Composable
private fun RowFrame(first: Boolean, content: @Composable androidx.compose.foundation.layout.RowScope.() -> Unit) {
    Column(Modifier.fillMaxWidth()) {
        if (!first) Box(Modifier.fillMaxWidth().height(1.dp).background(Jolu.GlassHairline))
        Row(
            Modifier
                .fillMaxWidth()
                .padding(top = if (first) 0.dp else Jolu.Space3, bottom = Jolu.Space3)
                .semantics(mergeDescendants = true) { },
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(Jolu.Space3),
            content = content
        )
    }
}

/** `.goal-source__label`: the name, strong, and a tiny line under it. */
@Composable
private fun LabelWithNote(label: String, note: String, modifier: Modifier) {
    Column(modifier, verticalArrangement = Arrangement.spacedBy(2.dp)) {
        T(label, JoluType.style(Jolu.FsSmall, FontWeight.SemiBold))
        if (note.isNotEmpty()) T(note, JStyle.Tiny)
    }
}

/**
 * Section 5: primary or not, pause or resume, delete — which asks first, in
 * place. The board moves at once; the server hears of it at the same time.
 */
@Composable
private fun Manage(goal: Goal, copy: Map<String, String>) {
    val context = LocalContext.current
    val accent = LocalAccent.current.color
    var confirming by remember(goal.id) { mutableStateOf(false) }

    JCard(Modifier.fillMaxWidth().reveal()) {
        CompactHead(JoluIcons.sliders, copy["manage"].orEmpty())

        Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(Jolu.Space2)) {
            if (goal.isPrimary) {
                // .goal-manage__state: this is the primary goal, and how to change that.
                val shape = RoundedCornerShape(Jolu.RadiusMd)
                Row(
                    Modifier
                        .fillMaxWidth()
                        .clip(shape)
                        .background(Jolu.mix(accent, 0.12f, Color.Transparent))
                        .border(1.dp, Jolu.mix(accent, 0.28f, Color.Transparent), shape)
                        .padding(1.dp).padding(horizontal = Jolu.Space4, vertical = Jolu.Space3)
                        .semantics(mergeDescendants = true) { },
                    horizontalArrangement = Arrangement.spacedBy(Jolu.Space3)
                ) {
                    JIcon(JoluIcons.flag, size = 17.dp, color = accent)
                    Column(Modifier.weight(1f)) {
                        T(copy["is_primary"].orEmpty(), JoluType.style(Jolu.FsSmall, FontWeight.SemiBold))
                        T(
                            "Kies bij een ander doel “${copy["make_primary"].orEmpty()}” om te wisselen.",
                            JoluType.style(Jolu.FsTiny, color = Jolu.TextSecondary),
                            Modifier.padding(top = Jolu.Space1)
                        )
                    }
                }
            } else {
                ManageButton(JoluIcons.flag, copy["make_primary"].orEmpty()) { GoalBoard.promote(context, goal.id) }
            }

            ManageButton(
                if (goal.isPaused) JoluIcons.play else JoluIcons.pause,
                if (goal.isPaused) copy["resume"].orEmpty() else copy["pause"].orEmpty()
            ) { GoalBoard.pause(context, goal.id, !goal.isPaused) }

            if (!confirming) {
                ManageButton(JoluIcons.trash, copy["delete"].orEmpty(), text = Jolu.TextSecondary) { confirming = true }
            } else {
                // .goal-confirm
                val shape = RoundedCornerShape(Jolu.RadiusMd)
                Column(
                    Modifier
                        .fillMaxWidth()
                        .padding(top = Jolu.Space2)
                        .clip(shape)
                        .background(Jolu.Nutrition.copy(alpha = 0.10f))
                        .border(1.dp, Jolu.Nutrition.copy(alpha = 0.34f), shape)
                        .padding(1.dp).padding(Jolu.Space4)
                ) {
                    T(copy["delete_confirm"].orEmpty(), JoluType.style(Jolu.FsSmall))
                    Row(Modifier.fillMaxWidth().padding(top = Jolu.Space3), horizontalArrangement = Arrangement.spacedBy(Jolu.Space2)) {
                        Btn(copy["delete_no"].orEmpty(), onClick = { confirming = false }, modifier = Modifier.weight(1f))
                        Btn(
                            copy["delete_yes"].orEmpty(),
                            onClick = {
                                confirming = false
                                GoalBoard.delete(context, goal.id)
                            },
                            modifier = Modifier.weight(1f),
                            look = BtnLook(border = Jolu.Nutrition.copy(alpha = 0.45f), fill = Jolu.Nutrition.copy(alpha = 0.18f))
                        )
                    }
                }
            }
        }
    }
}

/** `.goal-manage__button`: full width, its icon and label from the left. */
@Composable
private fun ManageButton(icon: ImageVector, label: String, text: Color = Jolu.TextPrimary, onClick: () -> Unit) {
    Btn(label, onClick = onClick, modifier = Modifier.fillMaxWidth(), look = BtnLook(text = text)) {
        Row(Modifier.weight(1f), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Jolu.Space3)) {
            JIcon(icon, size = 17.dp, color = Jolu.TextMuted)
            T(label, JoluType.style(Jolu.FsSmall, FontWeight.SemiBold, text), maxLines = 1)
        }
    }
}
