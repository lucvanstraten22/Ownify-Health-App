package com.ownify.android.ui.screens.health

import com.ownify.android.ui.design.WheelInput
import com.ownify.android.ui.design.WheelField
import com.ownify.android.ui.design.WheelSpec

import com.ownify.android.ui.design.glassPress
import android.text.format.DateFormat
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.asPaddingValues
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawing
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.ScrollState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import com.ownify.android.data.FieldInput
import com.ownify.android.data.ManualEntry
import com.ownify.android.data.OwnifyActions
import com.ownify.android.data.OwnifyAppState
import com.ownify.android.data.Outcome
import com.ownify.android.data.TrainingSession
import com.ownify.android.i18n.OwnifyFormat
import com.ownify.android.i18n.tr
import com.ownify.android.i18n.trf
import com.ownify.android.ui.app.Detail
import com.ownify.android.ui.app.DetailColumn
import com.ownify.android.ui.app.LocalShell
import com.ownify.android.ui.app.dockClearance
import com.ownify.android.ui.design.Btn
import com.ownify.android.ui.design.BtnLook
import com.ownify.android.ui.design.CardHint
import com.ownify.android.ui.design.GlassCta
import com.ownify.android.ui.design.GlassLook
import com.ownify.android.ui.design.GlassSelect
import com.ownify.android.ui.theme.InButton
import com.ownify.android.ui.design.JCard
import com.ownify.android.ui.design.JIcon
import com.ownify.android.ui.design.JInput
import com.ownify.android.ui.design.JStyle
import com.ownify.android.ui.design.LocalScreen
import com.ownify.android.ui.design.NoteTone
import com.ownify.android.ui.design.OwnifyIcons
import com.ownify.android.ui.design.PageIntro
import com.ownify.android.ui.design.T
import com.ownify.android.ui.design.cssPadding
import com.ownify.android.ui.design.glass
import com.ownify.android.ui.design.hitArea
import com.ownify.android.ui.design.note
import com.ownify.android.ui.design.press
import com.ownify.android.ui.design.reveal
import com.ownify.android.ui.screens.account.FieldHint
import com.ownify.android.ui.screens.account.FieldLabel
import com.ownify.android.ui.screens.settings.DateControl
import com.ownify.android.ui.theme.Ownify
import com.ownify.android.ui.theme.OwnifyThemeStore
import com.ownify.android.ui.theme.OwnifyType
import java.time.LocalDate
import kotlinx.coroutines.launch

/*
 * Sleep and training entered by hand, and a training removed (40.0,
 * docs/MANUAL-ENTRY.md) — pages/manual-entry.php, health-detail.php's +
 * and training-session.php's remove, in Compose.
 */

/**
 * Slaap's or Training's + (`.fab.glass--cta`): Doelen's + in the floating
 * control's place — on the column's right edge above the tab bar — opening
 * that area's own entry page. Drawn over [HealthDetail]; none on Voeding,
 * none before migration 030.
 */
@Composable
fun EntryFab(entry: ManualEntry?, area: String) {
    if (entry == null || area !in setOf("sleep", "training")) return
    val shell = LocalShell.current
    val screen = LocalScreen.current
    val bottom = WindowInsets.safeDrawing.asPaddingValues().calculateBottomPadding()
    val interaction = remember { MutableInteractionSource() }
    val label = entry.page(area)["add"].orEmpty()

    Box(Modifier.fillMaxWidth().fillMaxHeight(), contentAlignment = Alignment.BottomCenter) {
        Box(
            Modifier
                .padding(bottom = dockClearance(bottom) + Ownify.Space4)
                .offset(x = screen.shell / 2 - 44.dp + 22.dp)
                .hitArea(
                    Modifier
                        .clickable(interaction, indication = null, role = Role.Button) { shell.openDetail(Detail.ManualEntry(area)) }
                        .semantics { contentDescription = label }
                )
                .glassPress(interaction, CircleShape)
                .size(44.dp)
                .glass(CircleShape, GlassLook(cta = true)),
            contentAlignment = Alignment.Center
        ) {
            JIcon(OwnifyIcons.plus, size = 22.dp, color = Ownify.TextPrimary)
        }
    }
}

/**
 * The night or the training as the server takes it: the morning a night
 * ended and its two times, or a training's date, type, start, minutes and
 * calories. The website's assets/js/manual-entry.js, field for field.
 */
internal class EntryDraft(today: String, val kind: String, firstType: String) {
    var date by mutableStateOf(today)
    var bedtime by mutableStateOf("23:00")
    var wakeup by mutableStateOf("07:00")
    var type by mutableStateOf(firstType)
    var start by mutableStateOf("18:00")
    var minutes by mutableStateOf("30")
    var kcal by mutableStateOf("")

    val path: String get() = if (kind == "sleep") "api/health/sleep-entry.php" else "api/health/training-entry.php"

    fun fields(): Map<String, String> = if (kind == "sleep") {
        mapOf("date" to date, "bedtime" to bedtime, "wakeup" to wakeup)
    } else {
        mapOf("date" to date, "type" to type, "start" to start, "minutes" to minutes.trim(), "kcal" to kcal.trim())
    }

    /**
     * Slaap's line: "wo 4 nov 23:00 tot do 5 nov 07:00 · 8:00 u" — a bedtime
     * later in the day than the wake-up time is the evening before, as on
     * the server (health_manual_night_span()). Null while it makes no night.
     */
    fun span(format: String): String? {
        val morning = runCatching { LocalDate.parse(date) }.getOrNull() ?: return null
        val bed = clockMinutes(bedtime) ?: return null
        val wake = clockMinutes(wakeup) ?: return null
        if (bed == wake) return null
        val evening = if (bed > wake) morning.minusDays(1) else morning
        val length = if (bed > wake) 1440 - bed + wake else wake - bed
        val day = { d: LocalDate -> OwnifyFormat.weekday(d, short = true) + " " + OwnifyFormat.date(d, "day_mon") }
        val hours = "%d:%02d %s".format(length / 60, length % 60, tr("u"))
        return format.replace("%1\$s", "${day(evening)} $bedtime").replace("%2\$s", "${day(morning)} $wakeup").replace("%3\$s", hours)
    }

    companion object {
        fun clockMinutes(value: String): Int? {
            val m = Regex("""^(\d{1,2}):(\d{2})$""").find(value) ?: return null
            val (h, min) = m.destructured
            return if (h.toInt() > 23 || min.toInt() > 59) null else h.toInt() * 60 + min.toInt()
        }
    }
}

/**
 * pages/manual-entry.php: the back pill to the area, the intro, one card of
 * fields — the goal wizard's pill fields and its select in the glass — the
 * line under them, then the error and Annuleren | Opslaan. One layout for
 * both; only the fields and their words differ.
 */
@Composable
fun ManualEntryPage(entry: ManualEntry, kind: String, scroll: ScrollState) {
    val shell = LocalShell.current
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val copy = entry.page(kind)
    val common = entry.common
    val draft = remember(kind) { EntryDraft(entry.today, kind, entry.types.firstOrNull()?.first.orEmpty()) }
    var saving by remember { mutableStateOf(false) }
    var error by rememberSaveable(kind) { mutableStateOf<String?>(null) }
    val dateInput = remember(entry.today) { FieldInput("date", entry.today, "", "", null, null, entry.today, emptyList()) }

    fun save() {
        if (saving) return
        saving = true
        error = null
        scope.launch {
            // Done: OwnifyActions reads the state again; back on the area, it shows the night or the training.
            when (val outcome = OwnifyActions.form(context, draft.path, draft.fields(), common["offline"].orEmpty())) {
                is Outcome.Done -> shell.back()
                is Outcome.Refused -> error = if (outcome.message == OwnifyAppState.UNREACHABLE) common["offline"] else outcome.message
                else -> Unit
            }
            saving = false
        }
    }

    DetailColumn(scroll, back = copy["back"].orEmpty(), backAria = trf("Terug naar %s", copy["back"].orEmpty())) {
        PageIntro(copy["title"].orEmpty(), copy["lede"], Modifier.reveal())

        JCard(Modifier.fillMaxWidth().reveal()) {
            Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(Ownify.Space5)) {
                if (kind == "sleep") {
                    EntryField(copy["date"].orEmpty(), copy["date_note"]) {
                        DateControl(dateInput, draft.date, copy["date"].orEmpty()) { draft.date = it }
                    }
                    EntryField(copy["bedtime"].orEmpty()) { TimeControl(draft.bedtime, copy["bedtime"].orEmpty()) { draft.bedtime = it } }
                    EntryField(copy["wakeup"].orEmpty()) { TimeControl(draft.wakeup, copy["wakeup"].orEmpty()) { draft.wakeup = it } }
                } else {
                    EntryField(common["date"].orEmpty()) {
                        DateControl(dateInput, draft.date, common["date"].orEmpty()) { draft.date = it }
                    }
                    EntryField(copy["activity"].orEmpty()) {
                        GlassSelect(entry.types, draft.type, { draft.type = it }, copy["activity"].orEmpty(), Modifier.fillMaxWidth())
                    }
                    EntryField(copy["start"].orEmpty()) { TimeControl(draft.start, copy["start"].orEmpty()) { draft.start = it } }
                    EntryField(copy["duration"].orEmpty(), unit = common["minutes"]) {
                        WheelInput(
                            draft.minutes, { draft.minutes = it.filter(Char::isDigit).take(4) },
                            WheelSpec(kind = "duration", min = 1.0, max = 1440.0, start = 30.0),
                            modifier = Modifier.weight(1f),
                            label = copy["duration"],
                            keyboard = KeyboardOptions(keyboardType = KeyboardType.Number, imeAction = ImeAction.Next)
                        )
                    }
                    EntryField(copy["kcal"].orEmpty(), unit = common["kcal"]) {
                        WheelInput(
                            draft.kcal, { draft.kcal = it.filter(Char::isDigit).take(5) },
                            WheelSpec(min = 0.0, max = 5000.0, step = 10.0, start = 300.0, unit = common["kcal"].orEmpty()),
                            modifier = Modifier.weight(1f),
                            placeholder = common["optional"].orEmpty(),
                            label = copy["kcal"],
                            keyboard = KeyboardOptions(keyboardType = KeyboardType.Number, imeAction = ImeAction.Done)
                        )
                    }
                }
            }
            if (kind == "sleep") {
                draft.span(copy["span"].orEmpty())?.let {
                    T(it, OwnifyType.style(Ownify.FsSubhead, color = Ownify.TextMuted, tabular = true),
                        Modifier.fillMaxWidth().padding(top = Ownify.Space5).semantics { liveRegion = LiveRegionMode.Polite })
                }
            }
            CardHint(copy["note"].orEmpty(), icon = null, plain = true)
        }

        Column(Modifier.fillMaxWidth().reveal()) {
            error?.let {
                T(it, OwnifyType.style(Ownify.FsSubhead, color = Ownify.Error), Modifier.fillMaxWidth().padding(bottom = Ownify.Space3)
                    .semantics { liveRegion = LiveRegionMode.Assertive }, align = androidx.compose.ui.text.style.TextAlign.Center)
            }
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
                Btn(common["cancel"].orEmpty(), onClick = { shell.back() }, modifier = Modifier.weight(1f))
                GlassCta(if (saving) common["saving"].orEmpty() else common["save"].orEmpty(), onClick = ::save,
                    modifier = Modifier.weight(1f), loading = saving)
            }
        }
    }
}

/** `.manual-entry__field`: the label over the pill, a unit after it, a note under it. */
@Composable
private fun EntryField(label: String, note: String? = null, unit: String? = null, control: @Composable androidx.compose.foundation.layout.RowScope.() -> Unit) {
    Column(Modifier.fillMaxWidth()) {
        FieldLabel(label)
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
            control()
            if (unit != null) T(unit, OwnifyType.style(Ownify.FsSubhead, color = Ownify.TextMuted), maxLines = 1)
        }
        if (note != null) FieldHint(note)
    }
}

/** A time of day on the wheel (48.0): hours : minutes, "HH:MM" — as the website's. */
@Composable
private fun TimeControl(value: String, label: String, onPick: (String) -> Unit) {
    WheelField(value, onPick, WheelSpec.clock(value.ifEmpty { "12:00" }), Modifier.fillMaxWidth(), label)
}

/**
 * training-session.php's remove: Doelen's delete and its confirmation, as
 * on a goal's page. One that was synced is removed from Ownify only, and
 * the confirmation says so.
 */
@Composable
fun RemoveTraining(entry: ManualEntry?, session: TrainingSession) {
    if (entry == null) return
    val shell = LocalShell.current
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val copy = entry.remove
    var confirming by remember(session.id) { mutableStateOf(false) }
    var removing by remember(session.id) { mutableStateOf(false) }
    var error by remember(session.id) { mutableStateOf<String?>(null) }

    Column(Modifier.fillMaxWidth().reveal(), verticalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
        if (!confirming) {
            Btn(copy["button"].orEmpty(), onClick = { confirming = true }, modifier = Modifier.fillMaxWidth(), look = BtnLook(text = Ownify.TextSecondary)) {
                Row(Modifier.weight(1f), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)) {
                    JIcon(OwnifyIcons.trash, size = 17.dp, color = Ownify.TextMuted)
                    T(copy["button"].orEmpty(), OwnifyType.style(Ownify.FsSubhead, androidx.compose.ui.text.font.FontWeight.SemiBold, Ownify.TextSecondary), maxLines = 1)
                }
            }
        } else {
            // .goal-confirm
            Column(
                Modifier
                    .fillMaxWidth()
                    .padding(top = Ownify.Space2)
                    .note(NoteTone.Attention)
                    .cssPadding(PaddingValues(Ownify.Space4), border = 1.dp)
            ) {
                T(copy["confirm"].orEmpty(), OwnifyType.style(Ownify.FsSubhead))
                if (!session.manual) {
                    T(copy["imported"].orEmpty(), OwnifyType.style(Ownify.FsSubhead, color = Ownify.TextSecondary), Modifier.padding(top = Ownify.Space2))
                }
                Row(Modifier.fillMaxWidth().padding(top = Ownify.Space3), horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
                    Btn(copy["no"].orEmpty(), onClick = { confirming = false }, modifier = Modifier.weight(1f))
                    Btn(
                        copy["yes"].orEmpty(),
                        onClick = {
                            if (removing) return@Btn
                            removing = true
                            error = null
                            scope.launch {
                                when (val outcome = OwnifyActions.form(context, "api/health/training-delete.php", mapOf("workout_id" to session.id), entry.common["offline"].orEmpty())) {
                                    // Gone: back on Training, read again without it.
                                    is Outcome.Done -> shell.back()
                                    is Outcome.Refused -> error = if (outcome.message == OwnifyAppState.UNREACHABLE) entry.common["offline"] else outcome.message
                                    else -> Unit
                                }
                                removing = false
                            }
                        },
                        modifier = Modifier.weight(1f),
                        enabled = !removing,
                        look = BtnLook.Caution
                    )
                }
            }
        }
        error?.let { T(it, OwnifyType.style(Ownify.FsSubhead, color = Ownify.Error), Modifier.semantics { liveRegion = LiveRegionMode.Assertive }) }
    }
}
