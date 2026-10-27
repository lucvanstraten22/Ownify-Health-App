package com.ownify.android.ui.screens.settings
import com.ownify.android.ui.design.NoteTone
import com.ownify.android.ui.design.note

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
import com.ownify.android.i18n.tr
import com.ownify.android.i18n.trf
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
import com.ownify.android.ui.screens.account.FieldLabel
import com.ownify.android.ui.screens.account.findActivity
import com.ownify.android.ui.app.OwnifyToast
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
    get() = BtnLook.Primary

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
    var feet by remember(overlay.key) { mutableStateOf(input?.feet.orEmpty()) }
    var inches by remember(overlay.key) { mutableStateOf(input?.inches.orEmpty()) }
    var saving by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(field) { if (field == null || input == null) shell.close(overlay) }
    if (field == null || input == null) return

    fun save() {
        // Imperial height is sent as inches, with height_unit=in (lib/settings.php).
        val sent = if (input.type == "feet_inches") {
            if (feet.isBlank() && inches.isBlank()) "" else ((feet.trim().toIntOrNull() ?: 0) * 12 + (inches.trim().toIntOrNull() ?: 0)).toString()
        } else value.trim()
        if (sent.isEmpty()) {
            error = tr("Vul eerst een waarde in.")
            return
        }
        focus.clearFocus()
        error = null
        saving = true
        val fields = buildMap {
            put(field.key, sent)
            if (input.postUnit.isNotEmpty()) put(field.key + "_unit", input.postUnit)
        }
        scope.launch {
            when (val outcome = OwnifyActions.form(context, input.endpoint, fields, tr("Opslaan is niet gelukt."))) {
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
            ConfirmBody(tr("Dit kun je één keer invullen. Daarna staat het vast."), align = TextAlign.Start, color = Ownify.Attention)
        }

        Column(Modifier.fillMaxWidth().padding(top = Ownify.Space4)) {
            when (input.type) {
                "choice" -> ChoiceControl(input, value) { value = it }
                "date" -> DateControl(input, value, field.label) { value = it }
                "feet_inches" -> {
                    // Feet and inches side by side, as the website's editor.
                    val first = rememberFocusOnOpen()
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
                        JInput(
                            feet, { feet = it.filter(Char::isDigit).take(1) },
                            modifier = Modifier.weight(1f).focusRequester(first),
                            label = tr("Voet"),
                            keyboard = KeyboardOptions(keyboardType = KeyboardType.Number, imeAction = ImeAction.Next)
                        )
                        T("ft", OwnifyType.style(Ownify.FsLabel, color = Ownify.TextSecondary))
                        JInput(
                            inches, { inches = it.filter(Char::isDigit).take(2) },
                            modifier = Modifier.weight(1f),
                            label = tr("Inch"),
                            keyboard = KeyboardOptions(keyboardType = KeyboardType.Number, imeAction = ImeAction.Done),
                            actions = KeyboardActions(onDone = { if (!saving) save() })
                        )
                        T("in", OwnifyType.style(Ownify.FsLabel, color = Ownify.TextSecondary))
                    }
                }
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
            { Btn(tr("Annuleren"), onClick = { shell.close(overlay) }, modifier = it) },
            { Btn(tr("Opslaan"), onClick = ::save, enabled = !saving, look = YesLook, modifier = it) }
        )
    }
}

/**
 * A phone's name (Instellingen › Apparaten & Gezondheid › Naam wijzigen): the
 * field editor's panel, as on the website, with the name its owner gave it —
 * empty while it has its own — and a line saying what saving does. Only the
 * name changes (api/integrations/device-rename.php): never the pairing, the
 * sync or the data; empty gives it its own name back.
 */
@Composable
fun DeviceNameEditor(overlay: Overlay.RenameDevice, data: AppData) {
    val shell = LocalShell.current
    val context = LocalContext.current
    val focus = LocalFocusManager.current
    val scope = rememberCoroutineScope()
    val labels = data.settings.integrationLabels
    val device = remember(overlay.device) { data.settings.integrations.flatMap { it.devices }.firstOrNull { it.id == overlay.device } }
    var value by remember(overlay.device) { mutableStateOf(if (device?.custom == true) device.label else "") }
    var saving by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(device) { if (device == null) shell.close(overlay) }
    if (device == null) return
    val own = device.own ?: device.label
    val title = labels["rename_title"] ?: tr("Apparaatnaam")

    fun save() {
        focus.clearFocus()
        error = null
        saving = true
        scope.launch {
            val fields = mapOf("device" to device.id.toString(), "name" to value.trim())
            when (val outcome = OwnifyActions.form(context, "api/integrations/device-rename.php", fields, tr("Opslaan is niet gelukt."))) {
                is Outcome.Done -> shell.close(overlay)
                is Outcome.Refused -> error = outcome.message
                Outcome.SignedOut -> Unit
            }
            saving = false
        }
    }

    ConfirmFrame(overlay, title = title, start = true) {
        ConfirmTitle(title, align = TextAlign.Start)
        val first = rememberFocusOnOpen()
        JInput(
            value, { value = it.take(DEVICE_NAME_MAX) },
            modifier = Modifier.fillMaxWidth().padding(top = Ownify.Space4).focusRequester(first),
            placeholder = own,
            label = title,
            keyboard = KeyboardOptions(keyboardType = KeyboardType.Text, imeAction = ImeAction.Done),
            actions = KeyboardActions(onDone = { if (!saving) save() })
        )
        // `.field-editor__note`: what saving does, and what empty gives back.
        T(
            listOfNotNull(labels["rename_note"], labels["rename_own"]?.replace("%s", own)).joinToString(" "),
            OwnifyType.style(Ownify.FsFootnote, color = Ownify.TextMuted, lineHeight = 1.4.em),
            Modifier.fillMaxWidth().padding(top = Ownify.Space2)
        )

        error?.let { EditorError(it) }

        ConfirmRow(
            { Btn(tr("Annuleren"), onClick = { shell.close(overlay) }, modifier = it) },
            { Btn(tr("Opslaan"), onClick = ::save, enabled = !saving, look = YesLook, modifier = it) }
        )
    }
}

/**
 * Wachtwoord wijzigen (Instellingen › Account › Inloggen, 28.0): the field
 * editor's panel with the three fields the website's editor has — the
 * current password, the new one, the new one again — each with the account
 * panel's label, and the line saying what saving does. The server checks
 * everything (api/account/password.php): this app stays signed in, every
 * other sign-in ends, health sync carries on.
 */
@Composable
fun PasswordEditor(overlay: Overlay) {
    val shell = LocalShell.current
    val context = LocalContext.current
    val focus = LocalFocusManager.current
    val scope = rememberCoroutineScope()
    // Only in these fields while the panel is open: never saved, never logged.
    var current by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var repeat by remember { mutableStateOf("") }
    var saving by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val title = tr("Wachtwoord wijzigen")

    fun save() {
        if (current.isEmpty() || password.isEmpty() || repeat.isEmpty()) {
            error = tr("Vul eerst een waarde in.")
            return
        }
        focus.clearFocus()
        error = null
        saving = true
        scope.launch {
            val fields = mapOf("current" to current, "password" to password, "password_confirm" to repeat)
            when (val outcome = OwnifyActions.form(context, "api/account/password.php", fields, tr("Je wachtwoord kon niet worden gewijzigd. Probeer het opnieuw."))) {
                is Outcome.Done -> {
                    OwnifyToast.show(outcome.body.optString("message").ifEmpty { null })
                    shell.close(overlay)
                }
                is Outcome.Refused -> error = outcome.message
                Outcome.SignedOut -> Unit
            }
            saving = false
        }
    }

    ConfirmFrame(overlay, title = title, start = true) {
        ConfirmTitle(title, align = TextAlign.Start)
        val first = rememberFocusOnOpen()
        Column(Modifier.fillMaxWidth().padding(top = Ownify.Space4), verticalArrangement = Arrangement.spacedBy(Ownify.Space3)) {
            listOf(
                Triple(tr("Huidig wachtwoord"), current) { v: String -> current = v },
                Triple(tr("Nieuw wachtwoord"), password) { v: String -> password = v },
                Triple(tr("Herhaal nieuw wachtwoord"), repeat) { v: String -> repeat = v }
            ).forEachIndexed { i, (label, value, set) ->
                Column {
                    FieldLabel(label)
                    JInput(
                        value, { set(it.take(PASSWORD_MAX)) },
                        modifier = if (i == 0) Modifier.focusRequester(first) else Modifier,
                        password = true,
                        label = label,
                        keyboard = KeyboardOptions(keyboardType = KeyboardType.Password, imeAction = if (i < 2) ImeAction.Next else ImeAction.Done),
                        actions = KeyboardActions(onDone = { if (!saving) save() })
                    )
                }
            }
        }
        // `.field-editor__note`: what saving does.
        T(
            tr("Minimaal 8 tekens. Je blijft hier ingelogd; op je andere apparaten word je uitgelogd."),
            OwnifyType.style(Ownify.FsFootnote, color = Ownify.TextMuted, lineHeight = 1.4.em),
            Modifier.fillMaxWidth().padding(top = Ownify.Space2)
        )

        error?.let { EditorError(it) }

        ConfirmRow(
            { Btn(tr("Annuleren"), onClick = { shell.close(overlay) }, modifier = it) },
            { Btn(tr("Opslaan"), onClick = ::save, enabled = !saving, look = YesLook, modifier = it) }
        )
    }
}

/** The longest password: includes/account-security.php's ACCOUNT_PASSWORD_MAX. */
private const val PASSWORD_MAX = 200

/** The longest name a phone can be given: includes/devices.php's DEVICE_NAME_MAX. */
private const val DEVICE_NAME_MAX = 40

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
                // A Row: no edge until chosen, then the green ring.
                val border by animateColorAsState(if (active) Ownify.HealthStrong else Color.Transparent, fast, label = "border")
                val fill by animateColorAsState(if (active) Ownify.HealthFill else Ownify.InsetFill, fast, label = "fill")
                Row(
                    Modifier
                        .press(interaction)
                        .fillMaxWidth()
                        .heightIn(min = Ownify.RowMin)
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
        val shape = RoundedCornerShape(50)
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
                .padding(horizontal = Ownify.Space4),
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
 *
 * The second step asks the account to prove it is itself again (28.0): its
 * password when it has one; otherwise Google, natively on this phone
 * ("Bevestig met Google": Google's chooser, then the deletion with the token
 * Google gave — OwnifyActions.deleteAccountWithGoogle).
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
    // An older server sends no `signin`: no proof is asked, as before.
    val signin = data.auth.signin
    val byPassword = signin?.password == true
    val byGoogle = signin != null && !signin.password
    var password by remember { mutableStateOf("") }

    /** What a refusal says, in the website's words for the two ways there is no answer. */
    fun said(outcome: Outcome.Refused): String? = when (outcome.message) {
        OwnifyAppState.UNREACHABLE -> tr("Ownify is niet bereikbaar, dus er is niets verwijderd. Controleer je internetverbinding en probeer het opnieuw.")
        OwnifyAppState.UNEXPECTED -> tr("Je account kon niet worden verwijderd.")
        "" -> null                                   // Google's chooser closed: nothing to say
        else -> outcome.message
    }

    ConfirmFrame(overlay, title = if (step == 1) copy["title"].orEmpty() else copy["final_title"].orEmpty()) {
        if (step == 1) {
            IconTile(OwnifyIcons.trash, color = Ownify.Attention, background = Ownify.AttentionFill)
            ConfirmTitle(copy["title"].orEmpty(), Modifier.padding(top = Ownify.Space3))
            ConfirmBody(copy["body"].orEmpty() + if (google) " " + copy["google"].orEmpty() else "")
            ConfirmRow(
                { Btn(copy["cancel"].orEmpty(), onClick = { shell.close(overlay) }, modifier = it) },
                { Btn(copy["confirm"].orEmpty(), onClick = { step = 2 }, look = BtnLook.Caution, modifier = it) }
            )
            // .confirm__note
            Column(Modifier.fillMaxWidth().padding(top = Ownify.Space4)) {
                Hairline()
                Row(Modifier.fillMaxWidth().padding(top = Ownify.Space4), horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
                    JIcon(OwnifyIcons.lock, Modifier.padding(top = 1.dp), size = 14.dp, color = Ownify.TextMuted)
                    T(copy["note"].orEmpty(), JStyle.Footnote, Modifier.weight(1f))
                }
            }
        } else {
            IconTile(OwnifyIcons.trash, color = Ownify.Attention, background = Ownify.AttentionFill)
            ConfirmTitle(copy["final_title"].orEmpty(), Modifier.padding(top = Ownify.Space3))
            ConfirmBody(copy["final_body"].orEmpty() + if (google) " " + copy["final_google"].orEmpty() else "")
            if (byPassword) {
                Column(Modifier.fillMaxWidth().padding(top = Ownify.Space4)) {
                    FieldLabel(tr("Wachtwoord"))
                    JInput(
                        password, { password = it.take(PASSWORD_MAX) },
                        password = true,
                        enabled = !deleting,
                        label = tr("Wachtwoord"),
                        keyboard = KeyboardOptions(keyboardType = KeyboardType.Password, imeAction = ImeAction.Done)
                    )
                }
            } else if (byGoogle) {
                ConfirmBody(tr("Bevestig met Google om je account te verwijderen."))
            }
            error?.let {
                T(
                    it,
                    OwnifyType.style(Ownify.FsSmall),
                    Modifier
                        .fillMaxWidth()
                        .padding(top = Ownify.Space3)
                        .note(NoteTone.Attention)
                        .cssPadding(PaddingValues(Ownify.Space3), border = 1.dp)
                        .semantics { liveRegion = LiveRegionMode.Assertive }
                )
            }
            // Swapped on purpose: the safe answer is where "Ja, verwijderen" was.
            ConfirmRow(
                {
                    Btn(
                        if (byGoogle) tr("Bevestig met Google") else copy["final_yes"].orEmpty(),
                        onClick = {
                            if (byPassword && password.isEmpty()) {
                                error = tr("Vul je wachtwoord in om dit te bevestigen.")
                                return@Btn
                            }
                            deleting = true
                            error = null
                            scope.launch {
                                val fallback = tr("Je account kon niet worden verwijderd.")
                                val outcome = if (byGoogle) {
                                    OwnifyActions.deleteAccountWithGoogle(context.findActivity() ?: context, fallback)
                                } else {
                                    OwnifyActions.deleteAccount(context, fallback, if (byPassword) password else null)
                                }
                                if (outcome is Outcome.Refused) {
                                    error = said(outcome)
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
            when (val outcome = OwnifyActions.form(context, "api/integrations/pairing-code.php", mapOf("provider" to overlay.provider), tr("Er kon geen code worden gemaakt."), reload = false)) {
                is Outcome.Done -> {
                    code = outcome.body.optString("code").takeIf { it.isNotEmpty() }
                    val seconds = outcome.body.optInt("expires_in", 600).takeIf { it > 0 } ?: 600
                    expiry = trf("Geldig voor %d minuten.", Math.round(seconds / 60.0))
                }
                is Outcome.Refused -> error = outcome.message
                Outcome.SignedOut -> Unit
            }
            asking = false
        }
    }

    LaunchedEffect(overlay) { request() }

    ConfirmFrame(overlay, title = tr("Koppelcode")) {
        ConfirmTitle(tr("Koppelcode"))
        ConfirmBody(tr("Open de Ownify-app op je telefoon en voer deze code in."))
        T(
            code ?: "••••••••",
            OwnifyType.style(Ownify.FsLargeTitle, FontWeight.SemiBold, tracking = 0.18.em).copy(fontFamily = FontFamily.Monospace),
            Modifier.fillMaxWidth().padding(top = Ownify.Space4).semantics { liveRegion = LiveRegionMode.Polite },
            align = TextAlign.Center
        )
        T(expiry.orEmpty(), JStyle.Footnote, Modifier.fillMaxWidth().padding(top = Ownify.Space2), align = TextAlign.Center)
        error?.let { EditorError(it) }
        ConfirmRow(
            { Btn(tr("Sluiten"), onClick = { shell.close(overlay) }, modifier = it) },
            { Btn(tr("Nieuwe code"), onClick = ::request, enabled = !asking, look = YesLook, modifier = it) }
        )
    }
}
