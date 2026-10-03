package com.ownify.android.ui.screens.settings

import android.app.DatePickerDialog
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalFocusManager
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.selected
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.em
import androidx.compose.ui.unit.sp
import com.ownify.android.data.AppData
import com.ownify.android.data.FieldInput
import com.ownify.android.data.OwnifyActions
import com.ownify.android.data.OwnifyAppState
import com.ownify.android.data.Outcome
import com.ownify.android.data.ProfileField
import com.ownify.android.data.SettingsBlock
import com.ownify.android.ui.app.LocalShell
import com.ownify.android.ui.app.Overlay
import com.ownify.android.ui.app.OverlayFrame
import com.ownify.android.ui.app.panelGlass
import com.ownify.android.ui.design.Btn
import com.ownify.android.ui.design.BtnLook
import com.ownify.android.ui.design.IconTile
import com.ownify.android.ui.design.JIcon
import com.ownify.android.ui.design.JInput
import com.ownify.android.ui.design.JStyle
import com.ownify.android.ui.design.OwnifyIcons
import com.ownify.android.ui.design.T
import com.ownify.android.ui.design.cssPadding
import com.ownify.android.ui.design.press
import com.ownify.android.ui.screens.health.EditorError
import com.ownify.android.ui.theme.Ownify
import com.ownify.android.ui.theme.InButton
import com.ownify.android.ui.theme.OwnifyThemeStore
import com.ownify.android.ui.theme.OwnifyType
import java.time.LocalDate
import kotlinx.coroutines.launch
import androidx.compose.ui.focus.focusRequester
import com.ownify.android.ui.design.rememberFocusOnOpen
import com.ownify.android.ui.design.blurring

/** `.confirm__yes`: the answer that goes ahead, in the caution colour. */
private val YesLook: BtnLook
    get() = BtnLook(border = Ownify.AttentionWash.copy(alpha = 0.45f), fill = Ownify.AttentionWash.copy(alpha = 0.18f))

/** `.confirm__scrim` is a little darker than the account panel's. */
private const val CONFIRM_SCRIM = 0.55f

/**
 * `.confirm`: the panel the delete confirmation, the field editor and the
 * pairing code share — the same scrim, the same card, 368 wide, centred —
 * because it is the same kind of moment every time.
 */
@Composable
private fun ConfirmFrame(
    overlay: Overlay,
    title: String,
    start: Boolean = false,
    onDismiss: (() -> Unit)? = null,
    content: @Composable ColumnScope.() -> Unit
) {
    OverlayFrame(overlay, title = title, scrim = CONFIRM_SCRIM, maxWidth = 368.dp, onDismiss = onDismiss) { panelModifier ->
        Box(panelModifier.panelGlass(scrim = CONFIRM_SCRIM).cssPadding(border = 1.dp)) {
            Column(
                Modifier
                    .verticalScroll(rememberScrollState())
                    .padding(Ownify.Space5),
                horizontalAlignment = if (start) Alignment.Start else Alignment.CenterHorizontally,
                content = content
            )
        }
    }
}

/** `.confirm__title`. */
@Composable
private fun ConfirmTitle(text: String, modifier: Modifier = Modifier, align: TextAlign = TextAlign.Center) {
    T(text, JStyle.Subtitle, modifier.fillMaxWidth().semantics { heading() }, align = align)
}

/** `.confirm__body`. */
@Composable
private fun ConfirmBody(text: String, modifier: Modifier = Modifier, align: TextAlign = TextAlign.Center, color: Color = Ownify.TextSecondary) {
    T(text, OwnifyType.style(Ownify.FsSmall, color = color), modifier.fillMaxWidth().padding(top = Ownify.Space2), align = align)
}

/** `.confirm__row`: two equal answers, 24 below what they answer. */
@Composable
private fun ConfirmRow(first: @Composable (Modifier) -> Unit, second: @Composable (Modifier) -> Unit) {
    Row(Modifier.fillMaxWidth().padding(top = Ownify.Space5), horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
        first(Modifier.weight(1f))
        second(Modifier.weight(1f))
    }
}

// ----------------------------------------------------------- the editor

/** The field [key] names, wherever on the settings screens it is. */
private fun findField(data: AppData, key: String): ProfileField? =
    data.settings.pages.asSequence()
        .flatMap { it.blocks.asSequence() }
        .filterIsInstance<SettingsBlock.Fields>()
        .flatMap { it.fields.asSequence() }
        .firstOrNull { it.key == key }

/**
 * The field editor (components/settings-editor.php): one panel for every
 * field, built from what the server says the field takes — text, a number
 * beside its unit, a date, or one of a few answers. A field that can be
 * answered once says so before it is saved. The row then shows what the
 * server stored.
 */
@Composable
fun FieldEditor(overlay: Overlay.EditField, data: AppData) {
    val shell = LocalShell.current
    val context = LocalContext.current
    val focus = LocalFocusManager.current
    val scope = rememberCoroutineScope()
    val field = remember(overlay.key) { findField(data, overlay.key) }
    val input = field?.input
    var value by remember(overlay.key) { mutableStateOf(input?.value.orEmpty()) }
    var saving by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(field) { if (field == null || input == null) shell.close(overlay) }
    if (field == null || input == null) return

    fun save() {
        val sent = value.trim()
        if (sent.isEmpty()) {
            error = "Vul eerst een waarde in."
            return
        }
        focus.clearFocus()
        error = null
        saving = true
        scope.launch {
            when (val outcome = OwnifyActions.form(context, input.endpoint, mapOf(field.key to sent), "Opslaan is niet gelukt.")) {
                is Outcome.Done -> shell.close(overlay)
                is Outcome.Refused -> error = outcome.message
                Outcome.SignedOut -> Unit
            }
            saving = false
        }
    }

    ConfirmFrame(overlay, title = field.label, start = true) {
        ConfirmTitle(field.label, align = TextAlign.Start)
        if (field.state == "once") {
            ConfirmBody("Dit kun je één keer invullen. Daarna staat het vast.", align = TextAlign.Start, color = Ownify.Attention)
        }

        Column(Modifier.fillMaxWidth().padding(top = Ownify.Space4)) {
            when (input.type) {
                "choice" -> ChoiceControl(input, value) { value = it }
                "date" -> DateControl(input, value, field.label) { value = it }
                else -> {
                    // open(): the field takes focus, so the keyboard is there.
                    val first = rememberFocusOnOpen()
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
                        JInput(
                            value, { value = it.take(input.maxLength ?: 120) },
                            modifier = Modifier.weight(1f).focusRequester(first),
                            label = field.label,
                            keyboard = KeyboardOptions(
                                keyboardType = if (input.type == "number") KeyboardType.Decimal else KeyboardType.Text,
                                imeAction = ImeAction.Done
                            ),
                            actions = KeyboardActions(onDone = { if (!saving) save() })
                        )
                        if (input.unit.isNotEmpty()) T(input.unit, OwnifyType.style(Ownify.FsLabel, color = Ownify.TextSecondary))
                    }
                }
            }
        }

        error?.let { EditorError(it) }

        ConfirmRow(
            { Btn("Annuleren", onClick = { shell.close(overlay) }, modifier = it) },
            { Btn("Opslaan", onClick = ::save, enabled = !saving, look = YesLook, modifier = it) }
        )
    }
}

/** `.field-editor__options`: the answers, one under the other; the chosen one in the green. */
@Composable
private fun ChoiceControl(input: FieldInput, value: String, onChoose: (String) -> Unit) {
    InButton {
        Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
            input.options.forEach { (key, label) ->
                val active = key == value
                val interaction = remember { MutableInteractionSource() }
                val shape = RoundedCornerShape(Ownify.RadiusSm)
                val fast = tween<Color>(Ownify.FastMs, easing = Ownify.Ease)
                val border by animateColorAsState(if (active) Ownify.Health.copy(alpha = 0.5f) else Ownify.GlassBorderSoft, fast, label = "border")
                val fill by animateColorAsState(if (active) Ownify.Health.copy(alpha = 0.14f) else Ownify.fill(0.04f), fast, label = "fill")
                Row(
                    Modifier
                        .press(interaction)
                        .fillMaxWidth()
                        .heightIn(min = 44.dp)
                        .clip(shape)
                        .background(fill)
                        .border(1.dp, border, shape)
                        .clickable(interaction, indication = null, role = Role.RadioButton, onClick = blurring { onChoose(key) })
                        .semantics { selected = active }
                        .cssPadding(PaddingValues(horizontal = Ownify.Space3), border = 1.dp),
                    verticalAlignment = Alignment.CenterVertically
                ) {
                    T(label, OwnifyType.style(Ownify.FsLabel, color = if (active) Ownify.TextPrimary else Ownify.TextSecondary))
                }
            }
        }
    }
}

/**
 * A date, as a phone's browser asks for one: the field shows it, a tap opens
 * the system's date picker, between the earliest and latest the server allows.
 */
@Composable
internal fun DateControl(input: FieldInput, value: String, label: String, onPick: (String) -> Unit) {
    InButton {
        val context = LocalContext.current
        val interaction = remember { MutableInteractionSource() }
        val shape = RoundedCornerShape(Ownify.RadiusSm)
        val date = runCatching { LocalDate.parse(value) }.getOrNull()
        val shown = date?.let { "%02d-%02d-%04d".format(it.dayOfMonth, it.monthValue, it.year) }

        Box(
            Modifier
                .fillMaxWidth()
                .heightIn(min = 44.dp)
                .clip(shape)
                .background(Ownify.fill(0.05f))
                .border(1.dp, Ownify.GlassBorderSoft, shape)
                .clickable(interaction, indication = null, role = Role.Button, onClickLabel = label) {
                    val start = date ?: LocalDate.now().minusYears(30)
                    DatePickerDialog(OwnifyThemeStore.dialogContext(context), { _, y, m, d -> onPick(LocalDate.of(y, m + 1, d).toString()) }, start.year, start.monthValue - 1, start.dayOfMonth).apply {
                        input.min?.let { runCatching { datePicker.minDate = LocalDate.parse(it).toEpochDay() * 86_400_000L } }
                        input.max?.let { runCatching { datePicker.maxDate = LocalDate.parse(it).toEpochDay() * 86_400_000L } }
                    }.show()
                }
                .padding(horizontal = Ownify.Space3),
            contentAlignment = Alignment.CenterStart
        ) {
            T(shown ?: "dd-mm-jjjj", OwnifyType.style(Ownify.FsLabel, color = if (shown != null) Ownify.TextPrimary else Ownify.TextFaint, tabular = true))
        }
    }
}

// ------------------------------------------------------ the confirmation

/**
 * Deleting the account (components/settings-confirm.php): two steps, and
 * nothing leaves the phone until the second. The answers swap sides between
 * them, so a double tap cancels instead of deleting. Closing, from either
 * step, starts over at the first.
 */
@Composable
fun DeleteConfirm(overlay: Overlay, data: AppData) {
    val shell = LocalShell.current
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val copy = data.settings.delete
    val google = data.auth.hasGoogle
    var step by remember { mutableIntStateOf(1) }
    var deleting by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    ConfirmFrame(overlay, title = if (step == 1) copy["title"].orEmpty() else copy["final_title"].orEmpty()) {
        if (step == 1) {
            IconTile(OwnifyIcons.trash, color = Ownify.Attention, background = Ownify.AttentionWash.copy(alpha = 0.16f))
            ConfirmTitle(copy["title"].orEmpty(), Modifier.padding(top = Ownify.Space3))
            ConfirmBody(copy["body"].orEmpty() + if (google) " " + copy["google"].orEmpty() else "")
            ConfirmRow(
                { Btn(copy["cancel"].orEmpty(), onClick = { shell.close(overlay) }, modifier = it) },
                { Btn(copy["confirm"].orEmpty(), onClick = { step = 2 }, look = YesLook, modifier = it) }
            )
            // .confirm__note
            Column(Modifier.fillMaxWidth().padding(top = Ownify.Space4)) {
                Hairline()
                Row(Modifier.fillMaxWidth().padding(top = Ownify.Space4), horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
                    JIcon(OwnifyIcons.lock, Modifier.padding(top = 1.dp), size = 14.dp, color = Ownify.TextMuted)
                    T(copy["note"].orEmpty(), JStyle.Tiny, Modifier.weight(1f))
                }
            }
        } else {
            IconTile(OwnifyIcons.trash, color = Ownify.Miss, background = Ownify.mix(Ownify.Miss, 0.18f, Color.Transparent))
            ConfirmTitle(copy["final_title"].orEmpty(), Modifier.padding(top = Ownify.Space3))
            ConfirmBody(copy["final_body"].orEmpty() + if (google) " " + copy["final_google"].orEmpty() else "")
            error?.let {
                val shape = RoundedCornerShape(Ownify.RadiusSm)
                T(
                    it,
                    OwnifyType.style(Ownify.FsSmall),
                    Modifier
                        .fillMaxWidth()
                        .padding(top = Ownify.Space3)
                        .clip(shape)
                        .background(Ownify.AttentionWash.copy(alpha = 0.10f))
                        .border(1.dp, Ownify.AttentionWash.copy(alpha = 0.34f), shape)
                        .cssPadding(PaddingValues(Ownify.Space3), border = 1.dp)
                        .semantics { liveRegion = LiveRegionMode.Assertive }
                )
            }
            // Swapped on purpose: the safe answer is where "Ja, verwijderen" was.
            ConfirmRow(
                {
                    Btn(
                        copy["final_yes"].orEmpty(),
                        onClick = {
                            deleting = true
                            error = null
                            scope.launch {
                                val outcome = OwnifyActions.deleteAccount(context, "Je account kon niet worden verwijderd.")
                                if (outcome is Outcome.Refused) {
                                    // The website's words for the two ways there is no answer.
                                    error = when (outcome.message) {
                                        OwnifyAppState.UNREACHABLE -> "De server is niet bereikbaar. Er is niets verwijderd."
                                        OwnifyAppState.UNEXPECTED -> "Je account kon niet worden verwijderd."
                                        else -> outcome.message
                                    }
                                    deleting = false
                                }
                            }
                        },
                        enabled = !deleting,
                        look = BtnLook.Final,
                        modifier = it
                    )
                },
                { Btn(copy["final_no"].orEmpty(), onClick = { shell.close(overlay) }, modifier = it) }
            )
        }
    }
}

// --------------------------------------------------------- the code

/**
 * The pairing code (components/settings-pairing.php): asked for when the
 * panel opens, never before; shown once, valid for ten minutes, and gone
 * from the screen when the panel closes.
 */
@Composable
fun PairingPanel(overlay: Overlay.Pairing, data: AppData) {
    val shell = LocalShell.current
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var code by remember { mutableStateOf<String?>(null) }
    var expiry by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var asking by remember { mutableStateOf(false) }

    fun request() {
        code = null
        expiry = null
        error = null
        asking = true
        scope.launch {
            when (val outcome = OwnifyActions.form(context, "api/integrations/pairing-code.php", mapOf("provider" to overlay.provider), "Er kon geen code worden gemaakt.", reload = false)) {
                is Outcome.Done -> {
                    code = outcome.body.optString("code").takeIf { it.isNotEmpty() }
                    val seconds = outcome.body.optInt("expires_in", 600).takeIf { it > 0 } ?: 600
                    expiry = "Geldig voor ${Math.round(seconds / 60.0)} minuten."
                }
                is Outcome.Refused -> error = outcome.message
                Outcome.SignedOut -> Unit
            }
            asking = false
        }
    }

    LaunchedEffect(overlay) { request() }

    ConfirmFrame(overlay, title = "Koppelcode") {
        ConfirmTitle("Koppelcode")
        ConfirmBody("Open de Ownify-app op je telefoon en voer deze code in.")
        T(
            code ?: "••••••••",
            OwnifyType.style(28.sp, FontWeight.SemiBold, tracking = 0.18.em).copy(fontFamily = FontFamily.Monospace),
            Modifier.fillMaxWidth().padding(top = Ownify.Space4).semantics { liveRegion = LiveRegionMode.Polite },
            align = TextAlign.Center
        )
        T(expiry.orEmpty(), JStyle.Tiny, Modifier.fillMaxWidth().padding(top = Ownify.Space2), align = TextAlign.Center)
        error?.let { EditorError(it) }
        ConfirmRow(
            { Btn("Sluiten", onClick = { shell.close(overlay) }, modifier = it) },
            { Btn("Nieuwe code", onClick = ::request, enabled = !asking, look = YesLook, modifier = it) }
        )
    }
}
