package com.healthapp.android.ui.screens.account

import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.drawscope.scale
import androidx.compose.ui.graphics.vector.PathParser
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalFocusManager
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.disabled
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.unit.dp
import com.healthapp.android.jolu.JoluAuthState
import com.healthapp.android.jolu.JoluConnection
import com.healthapp.android.jolu.JoluLink
import com.healthapp.android.jolu.JoluState
import com.healthapp.android.ui.app.Overlay
import com.healthapp.android.ui.app.OverlayFrame
import com.healthapp.android.ui.app.PanelColumn
import com.healthapp.android.ui.app.PanelHead
import com.healthapp.android.ui.app.LocalShell
import com.healthapp.android.ui.design.Btn
import com.healthapp.android.ui.design.JInput
import com.healthapp.android.ui.design.JStyle
import com.healthapp.android.ui.design.T
import com.healthapp.android.ui.design.press
import com.healthapp.android.ui.theme.Jolu
import com.healthapp.android.ui.theme.JoluType
import com.healthapp.android.ui.theme.InButton

/** Room for any username or e-mail address the server accepts (191 for an address). */
private const val MAX_FIELD = 191

/** The server refuses passwords over 200 characters. */
private const val MAX_PASSWORD = 200

/** A code typed with spaces or a dash; anything longer is cut off. */
private const val MAX_CODE = 16

/**
 * The account panel before signing in (components/account-modal.php): one
 * flow at a time — Inloggen or Registreren, as the welcome screen's button
 * chose — with the same fields, the same messages and, behind them, the app's
 * sign-in endpoints (JoluConnection). [flow] "pair" is the Android phone's
 * pairing form, reached from a quiet link under the login form.
 */
@Composable
fun SignedOutPanel(overlay: Overlay.Account, notice: String?, link: JoluLink? = null) {
    val shell = LocalShell.current
    var flow by rememberSaveable { mutableStateOf(overlay.view) }
    val title = when (flow) {
        "register" -> "Registreren"
        "pair" -> "Koppelen"
        else -> "Inloggen"
    }

    OverlayFrame(overlay, title = title) { panelModifier ->
        PanelColumn(panelModifier) {
            PanelHead(title, onClose = { shell.close(overlay) })

            if (notice != null) Notice(notice, link)

            when (flow) {
                "pair" -> PairingForm(onBack = { flow = "login" })
                else -> EmailForm(register = flow == "register", onPair = { flow = "pair" })
            }
        }
    }
}

/**
 * `.account__notice`: something to know, said once — and, when the server
 * named one, the way onward (`.account__link`), opened beside the app.
 */
@Composable
fun Notice(text: String, link: JoluLink? = null) {
    val context = LocalContext.current
    val shape = RoundedCornerShape(Jolu.RadiusSm)
    Column(
        Modifier
            .fillMaxWidth()
            .padding(bottom = Jolu.Space4)
            .clip(shape)
            .background(Jolu.white(0.04f))
            .border(1.dp, Jolu.GlassHairline, shape)
            .padding(1.dp).padding(Jolu.Space3)
            .semantics(mergeDescendants = true) { liveRegion = LiveRegionMode.Polite }
    ) {
        T(text, JoluType.style(Jolu.FsSmall, color = Jolu.TextSecondary))
        if (link != null) {
            T(
                link.label,
                JoluType.style(Jolu.FsSmall, FontWeight.SemiBold).copy(textDecoration = TextDecoration.Underline),
                Modifier
                    .padding(top = Jolu.Space1)
                    .clickable(role = Role.Button) {
                        runCatching {
                            context.startActivity(
                                android.content.Intent(android.content.Intent.ACTION_VIEW, android.net.Uri.parse(link.href))
                                    .addFlags(android.content.Intent.FLAG_ACTIVITY_NEW_TASK)
                            )
                        }
                    }
            )
        }
    }
}

/** `.account__error`: what went wrong, in the nutrition accent the website uses for caution. */
@Composable
fun ErrorBox(text: String, modifier: Modifier = Modifier) {
    val shape = RoundedCornerShape(Jolu.RadiusSm)
    T(
        text,
        JoluType.style(Jolu.FsSmall, color = Jolu.TextPrimary),
        modifier
            .fillMaxWidth()
            .clip(shape)
            .background(Jolu.Nutrition.copy(alpha = 0.10f))
            .border(1.dp, Jolu.Nutrition.copy(alpha = 0.34f), shape)
            .padding(1.dp).padding(Jolu.Space3)
            .semantics { liveRegion = LiveRegionMode.Assertive }
    )
}

/** `.account__label`: small caps above a field, 8 above it. */
@Composable
fun FieldLabel(text: String, modifier: Modifier = Modifier) {
    T(text, JStyle.Caption, modifier.padding(bottom = Jolu.Space2), uppercase = true)
}

/** `.account__hint`: a line under a field. */
@Composable
fun FieldHint(text: String, modifier: Modifier = Modifier, centred: Boolean = false) {
    T(
        text,
        JStyle.Tiny,
        modifier.fillMaxWidth().padding(top = if (centred) Jolu.Space3 else Jolu.Space2),
        align = if (centred) TextAlign.Center else TextAlign.Start
    )
}

@Composable
private fun ColumnScope.EmailForm(register: Boolean, onPair: () -> Unit) {
    val context = LocalContext.current
    val focus = LocalFocusManager.current
    val auth = JoluConnection.auth
    val working = auth is JoluAuthState.Working
    var username by rememberSaveable { mutableStateOf("") }
    var email by rememberSaveable { mutableStateOf("") }
    // The password lives only in this field while it is on screen: never saved, never logged.
    var password by remember { mutableStateOf("") }

    fun submit() {
        focus.clearFocus()
        if (register) JoluConnection.register(context, username, email, password)
        else JoluConnection.login(context, username, password)
    }

    // Another flow starts without the last one's error, as setMode() clears it.
    LaunchedEffect(register) { JoluConnection.dismissAuthMessage() }

    if (auth is JoluAuthState.Failed) ErrorBox(auth.message, Modifier.padding(bottom = Jolu.Space4))

    Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(Jolu.Space3)) {
        Column {
            FieldLabel("Gebruikersnaam")
            JInput(
                username, { username = it.take(MAX_FIELD) },
                enabled = !working,
                label = "Gebruikersnaam",
                keyboard = KeyboardOptions(
                    capitalization = KeyboardCapitalization.None, autoCorrectEnabled = false,
                    keyboardType = KeyboardType.Ascii, imeAction = ImeAction.Next
                )
            )
        }
        if (register) {
            Column {
                FieldLabel("E-mailadres")
                JInput(
                    email, { email = it.take(MAX_FIELD) },
                    enabled = !working,
                    label = "E-mailadres",
                    keyboard = KeyboardOptions(autoCorrectEnabled = false, keyboardType = KeyboardType.Email, imeAction = ImeAction.Next)
                )
            }
        }
        Column {
            FieldLabel("Wachtwoord")
            JInput(
                password, { password = it.take(MAX_PASSWORD) },
                enabled = !working,
                password = true,
                label = "Wachtwoord",
                keyboard = KeyboardOptions(imeAction = ImeAction.Done),
                actions = KeyboardActions(onDone = { submit() })
            )
        }
    }

    Btn(
        if (register) "Account aanmaken" else "Inloggen",
        onClick = { submit() },
        enabled = !working && JoluConnection.canSignIn,
        modifier = Modifier.fillMaxWidth().padding(top = Jolu.Space4)
    )

    // Google is secondary: a small mark under the form. The app has no
    // Google sign-in of its own yet, so it reads as the website does when
    // Google is not set up (docs/PARITY.md).
    Row(Modifier.fillMaxWidth().padding(top = Jolu.Space4), horizontalArrangement = Arrangement.Center) {
        GoogleMark()
    }
    FieldHint("Google is nog niet gekoppeld.", centred = true)

    if (!register) {
        QuietLink("Of koppel deze telefoon met een koppelcode", onPair, Modifier.align(Alignment.CenterHorizontally).padding(top = Jolu.Space3))
    }
}

/** `.social`: the Google mark in a 46 circle of soft glass — here disabled, at 50%. */
@Composable
private fun GoogleMark() {
    Box(
        Modifier
            .alpha(0.5f)
            .size(46.dp)
            .clip(CircleShape)
            .background(Jolu.GlassSoft)
            .border(1.dp, Jolu.GlassBorderSoft, CircleShape)
            .semantics {
                contentDescription = "Doorgaan met Google"
                disabled()
            },
        contentAlignment = Alignment.Center
    ) {
        Canvas(Modifier.size(21.dp)) {
            val scale = size.width / 24f
            val parts = listOf(
                Color(0xFF4285F4) to "M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09Z",
                Color(0xFF34A853) to "M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23Z",
                Color(0xFFFBBC05) to "M5.84 14.1c-.22-.66-.35-1.36-.35-2.1s.13-1.44.35-2.1V7.07H2.18A10.99 10.99 0 0 0 1 12c0 1.78.43 3.45 1.18 4.93l3.66-2.83Z",
                Color(0xFFEA4335) to "M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.83C6.71 7.31 9.14 5.38 12 5.38Z"
            )
            scale(scale, scale, pivot = androidx.compose.ui.geometry.Offset.Zero) {
                for ((color, data) in parts) drawPath(PathParser().parsePathString(data).toPath(), color)
            }
        }
    }
}

/** A quiet text link, as `.settings-delete` is quiet: muted, underlined, a full-height target. */
@Composable
fun QuietLink(text: String, onClick: () -> Unit, modifier: Modifier = Modifier, enabled: Boolean = true) {
    InButton {
        val interaction = remember { MutableInteractionSource() }
        T(
            text,
            JoluType.style(Jolu.FsSmall, color = Jolu.TextMuted).copy(textDecoration = TextDecoration.Underline),
            modifier
                .clip(RoundedCornerShape(50))
                .clickable(interaction, indication = null, enabled = enabled, role = Role.Button, onClick = onClick)
                .padding(horizontal = Jolu.Space4, vertical = Jolu.Space2)
                .alpha(if (enabled) 1f else 0.45f),
            align = TextAlign.Center
        )
    }
}

/**
 * The Android phone's own way in without an account: a pairing code from
 * the website (Instellingen › Apparaten & Gezondheid › Health Connect ›
 * Koppelen). The phone then syncs Health Connect; signing in later turns it
 * into the account's own phone.
 */
@Composable
private fun ColumnScope.PairingForm(onBack: () -> Unit) {
    val context = LocalContext.current
    val focus = LocalFocusManager.current
    val state = JoluConnection.state
    val pairing = state == JoluState.Pairing || state == JoluState.Loading
    var code by rememberSaveable { mutableStateOf("") }
    var attempted by rememberSaveable { mutableStateOf(false) }

    fun submit() {
        focus.clearFocus()
        attempted = true
        JoluConnection.connect(context, code)
    }

    val message = (state as? JoluState.NotConnected)?.message
    if (attempted && message != null) ErrorBox(message, Modifier.padding(bottom = Jolu.Space4))

    T(
        "Heb je nog geen account in de app? Koppel deze telefoon met een koppelcode van de website. Je gezondheidsgegevens worden dan gesynchroniseerd; inloggen kan later.",
        JoluType.style(Jolu.FsSmall, color = Jolu.TextSecondary),
        Modifier.padding(bottom = Jolu.Space4)
    )

    FieldLabel("Koppelcode")
    JInput(
        code, { code = it.take(MAX_CODE) },
        enabled = !pairing,
        placeholder = "8 tekens",
        label = "Koppelcode",
        keyboard = KeyboardOptions(
            capitalization = KeyboardCapitalization.Characters, autoCorrectEnabled = false,
            keyboardType = KeyboardType.Ascii, imeAction = ImeAction.Done
        ),
        actions = KeyboardActions(onDone = { submit() })
    )
    FieldHint("Maak een code op de website: Instellingen › Apparaten & Gezondheid › Health Connect › Koppelen.")

    Btn(
        if (pairing) "Koppelen…" else "Koppelen",
        onClick = { submit() },
        enabled = !pairing,
        modifier = Modifier.fillMaxWidth().padding(top = Jolu.Space4)
    )

    QuietLink("Terug naar inloggen", onBack, Modifier.align(Alignment.CenterHorizontally).padding(top = Jolu.Space3), enabled = !pairing)
}
