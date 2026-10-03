package com.ownify.android.ui.screens.setup

import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
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
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawing
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.windowInsetsPadding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.key
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
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
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.em
import androidx.health.connect.client.HealthConnectClient
import androidx.health.connect.client.PermissionController
import com.ownify.android.connection.OwnifyBackgroundSync
import com.ownify.android.connection.OwnifyConnection
import com.ownify.android.data.AppData
import com.ownify.android.data.OwnifyActions
import com.ownify.android.data.Outcome
import com.ownify.android.data.Setup
import com.ownify.android.data.SetupField
import com.ownify.android.data.SetupFocusOption
import com.ownify.android.ui.app.LocalShell
import com.ownify.android.ui.app.Overlay
import com.ownify.android.ui.app.ShellState
import com.ownify.android.ui.design.Btn
import com.ownify.android.ui.design.BtnLook
import com.ownify.android.ui.design.IconTile
import com.ownify.android.ui.design.JIcon
import com.ownify.android.ui.design.JInput
import com.ownify.android.ui.design.JStyle
import com.ownify.android.ui.design.LocalBackdrop
import com.ownify.android.ui.design.LocalScreen
import com.ownify.android.ui.design.OwnifyIcons
import com.ownify.android.ui.design.T
import com.ownify.android.ui.design.blurring
import com.ownify.android.ui.design.cssPadding
import com.ownify.android.ui.design.press
import com.ownify.android.ui.design.recordBackdrop
import com.ownify.android.ui.design.rememberBackdrop
import com.ownify.android.ui.screens.account.LinkButton
import com.ownify.android.ui.screens.goals.GoalWizard
import com.ownify.android.ui.screens.goals.StepIn
import com.ownify.android.ui.screens.settings.DateControl
import com.ownify.android.ui.screens.settings.HealthPermissions
import com.ownify.android.ui.screens.settings.PhoneHealth
import com.ownify.android.ui.screens.settings.SyncResult
import com.ownify.android.ui.screens.settings.openHealthConnectStore
import com.ownify.android.ui.screens.settings.rememberPhoneHealth
import com.ownify.android.ui.theme.Accent
import com.ownify.android.ui.design.Ground
import com.ownify.android.ui.design.GroundPlacement
import com.ownify.android.ui.design.LocalGround
import com.ownify.android.ui.design.ground
import com.ownify.android.ui.theme.InButton
import com.ownify.android.ui.theme.Ownify
import com.ownify.android.ui.theme.OwnifyType
import kotlinx.coroutines.launch

/**
 * The setup a new account starts with (pages/setup.php): "Hoe moet Ownify
 * voor jou werken?" — before the pages, while the server says it is pending
 * (`setup`, includes/setup.php), and never again once finished.
 *
 *   1  Focus      what the person most wants to understand
 *   2  Gegevens   Health Connect, asked for here on this phone
 *   3  Over jou   a birth date, a height, a weight — each with its reason
 *   4  Doel       an optional first goal: the normal wizard, and a
 *                 suggestion when the person's own data can carry one
 *
 * Every word is the server's, apart from the phone's own Health Connect
 * buttons (as in Instellingen, [com.ownify.android.ui.screens.settings.PhoneSection]).
 * Every answer is saved by the endpoint that always saves it, and the pages
 * are read again after each (OwnifyActions), so what shows is what the
 * server has. Finishing is api/setup/finish.php; the server then says it is
 * the app, and OwnifyApp shows it.
 */
@Composable
fun SetupScreen(data: AppData, start: String? = null) {
    val setup = data.setup
    val scope = rememberCoroutineScope()
    // A panel host of its own, as the opening screen has: the goal wizard opens in it.
    val host = remember { ShellState(listOf("setup"), "setup", scope) }
    val (backdrop, layer) = rememberBackdrop()
    val ground = remember { GroundPlacement(Ground.App) }
    val order = setup.order

    // Where it opens: past the focus once it was chosen (a restart); [start] for the screenshots.
    var step by rememberSaveable { mutableStateOf((start ?: setup.resume).takeIf { it in order } ?: order.first()) }
    var direction by remember { mutableIntStateOf(1) }
    val index = order.indexOf(step).coerceAtLeast(0)

    fun go(to: String, dir: Int) {
        direction = dir
        step = to
    }

    BackHandler(enabled = host.overlayOpen || index > 0) {
        if (host.overlayOpen) host.back() else go(order[index - 1], -1)
    }

    CompositionLocalProvider(LocalShell provides host) {
        Box(Modifier.fillMaxSize()) {
            Box(Modifier.fillMaxSize().recordBackdrop(backdrop, layer).ground(ground)) {
                CompositionLocalProvider(LocalGround provides ground) {
                    SetupColumn(data, setup, step, index, direction, ::go)
                }
            }
            CompositionLocalProvider(LocalBackdrop provides backdrop) {
                for (overlay in host.overlays.toList()) {
                    if (overlay is Overlay.Wizard) GoalWizard(overlay, data)
                }
            }
        }
    }
}

/** `.setup`: the top, the progress, the step, and the way on. */
@Composable
private fun SetupColumn(data: AppData, setup: Setup, step: String, index: Int, direction: Int, go: (String, Int) -> Unit) {
    val context = LocalContext.current
    val shell = LocalShell.current
    val scope = rememberCoroutineScope()
    val screen = LocalScreen.current
    val order = setup.order
    val body = rememberScrollState()

    var busy by remember { mutableStateOf(false) }
    var error by remember(step) { mutableStateOf<String?>(null) }

    // The answers so far, kept across a restart of the app.
    var focus by rememberSaveable { mutableStateOf(setup.focus.options.firstOrNull { it.chosen }?.key) }
    var savedFocus by rememberSaveable { mutableStateOf(setup.focus.options.firstOrNull { it.chosen }?.key) }
    val values = rememberSaveable(saver = mapSaver()) { mutableStateOf(emptyMap<String, String>()) }
    var declined by rememberSaveable { mutableStateOf(false) }

    val health = rememberPhoneHealth()
    // This phone's own Health Connect access: the server's "connected" is
    // already true for any phone signed in, this one included.
    val linked = health.granted > 0

    LaunchedEffect(step) { body.scrollTo(0) }

    fun next() = go(order[index + 1], 1)

    /** Posts, then reads the pages again; [then] only when the server said yes. */
    fun post(path: String, fields: Map<String, String>, fallback: String, then: () -> Unit) {
        busy = true
        error = null
        scope.launch {
            when (val outcome = OwnifyActions.form(context, path, fields, fallback)) {
                is Outcome.Done -> then()
                is Outcome.Refused -> error = outcome.message
                Outcome.SignedOut -> Unit
            }
            busy = false
        }
    }

    /** The profile facts with something new in them, by the endpoint that saves each. */
    fun changed(): Map<String, Map<String, String>> =
        setup.profile.fields
            .filter { !it.locked && it.input != null }
            .mapNotNull { field ->
                val value = values.value[field.key]?.trim().orEmpty()
                if (value.isEmpty() || value == field.input!!.value) null else Triple(field.input.endpoint, field.key, value)
            }
            .groupBy({ it.first }, { it.second to it.third })
            .mapValues { (_, pairs) -> pairs.toMap() }

    Column(
        Modifier
            .fillMaxSize()
            .windowInsetsPadding(WindowInsets.safeDrawing),
        horizontalAlignment = Alignment.CenterHorizontally
    ) {
        Column(
            Modifier
                .width(minOf(screen.width * 0.92f, 480.dp))
                .weight(1f)
        ) {
            // .setup__top: the app's name, and a way out.
            Row(Modifier.fillMaxWidth().heightIn(min = 44.dp).padding(top = Ownify.Space3), verticalAlignment = Alignment.CenterVertically) {
                T(data.app.name, OwnifyType.style(Ownify.FsLabel, FontWeight.SemiBold, tracking = (-0.01).em), Modifier.weight(1f))
                LinkButton(setup.logout, enabled = !busy) { OwnifyConnection.logout(context) }
            }

            // .wizard__bars and .wizard__count
            Column(Modifier.fillMaxWidth().padding(top = Ownify.Space3)) {
                Row(Modifier.fillMaxWidth().clearAndSetSemantics { }, horizontalArrangement = Arrangement.spacedBy(Ownify.Space1)) {
                    order.forEachIndexed { n, _ ->
                        val lit by animateColorAsState(
                            if (n <= index) Ownify.Health else Ownify.fill(0.10f),
                            tween(Ownify.SlowMs, easing = Ownify.EaseOut),
                            label = "bar"
                        )
                        Box(Modifier.weight(1f).height(3.dp).clip(RoundedCornerShape(50)).background(lit))
                    }
                }
                T(
                    setup.countText(index) + " · " + setup.label(step),
                    JStyle.Tiny,
                    Modifier.padding(top = Ownify.Space2).semantics { liveRegion = LiveRegionMode.Polite }
                )
            }

            // The step, scrolled on its own; the way on stays above the keys.
            Box(Modifier.fillMaxWidth().weight(1f).padding(top = Ownify.Space6).verticalScroll(body)) {
                key(step) {
                    StepIn(direction) {
                        when (step) {
                            "focus" -> FocusStep(setup, focus) { focus = it }
                            "connect" -> ConnectStep(setup, health)
                            "profile" -> ProfileStep(setup, values.value) { key, value -> values.value = values.value + (key to value) }
                            else -> GoalStep(setup, declined, busy, onDecline = { declined = true }, onAdd = { input ->
                                post("api/goals/create.php", input, setup.goal.error) { }
                            }, onOwn = { shell.open(Overlay.Wizard) })
                        }
                        error?.let {
                            T(
                                it,
                                OwnifyType.style(Ownify.FsSmall, color = Ownify.Error, lineHeight = 1.4.em),
                                Modifier.padding(top = Ownify.Space4).semantics { liveRegion = LiveRegionMode.Assertive }
                            )
                        }
                        Box(Modifier.height(Ownify.Space4))
                    }
                }
            }

            // .setup__foot: back at its own width, the primary taking the rest.
            // Below 360 dp the three of Over jou take two rows, as on the
            // website: the primary on its own, then Terug and Overslaan.
            val saveProfile: () -> Unit = {
                val requests = changed().entries.toList()
                busy = true
                error = null
                scope.launch {
                    for ((path, fields) in requests) {
                        when (val outcome = OwnifyActions.form(context, path, fields, setup.profile.error)) {
                            is Outcome.Done -> Unit
                            is Outcome.Refused -> { error = outcome.message; busy = false; return@launch }
                            Outcome.SignedOut -> { busy = false; return@launch }
                        }
                    }
                    // Saved: what was typed is the server's value now.
                    values.value = emptyMap()
                    busy = false
                    next()
                }
            }
            val back: @Composable (Modifier) -> Unit = { m ->
                Btn(setup.back, onClick = { go(order[index - 1], -1) }, enabled = !busy, modifier = m.heightIn(min = 48.dp))
            }
            val skip: @Composable (Modifier) -> Unit = { m ->
                Btn(setup.profile.skip, onClick = { next() }, enabled = !busy, modifier = m.heightIn(min = 48.dp))
            }
            Column(Modifier.fillMaxWidth().padding(top = Ownify.Space4, bottom = Ownify.Space4)) {
                Box(Modifier.fillMaxWidth().height(1.dp).background(Ownify.GlassHairline))
                if (step == "profile" && screen.narrow) {
                    Primary(setup.profile.save, enabled = changed().isNotEmpty() && !busy, modifier = Modifier.fillMaxWidth().padding(top = Ownify.Space4), onClick = saveProfile)
                    Row(Modifier.fillMaxWidth().padding(top = Ownify.Space2), horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
                        back(Modifier.weight(1f))
                        skip(Modifier.weight(1f))
                    }
                } else Row(Modifier.fillMaxWidth().padding(top = Ownify.Space4), horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
                    if (index > 0) back(Modifier)
                    when (step) {
                        "focus" -> Primary(setup.next, enabled = focus != null && !busy, modifier = Modifier.weight(1f)) {
                            val chosen = focus ?: return@Primary
                            if (chosen == savedFocus) next()
                            else post("api/profile/update.php", mapOf("focus" to chosen), setup.focus.error) {
                                savedFocus = chosen
                                next()
                            }
                        }
                        "connect" -> Primary(if (linked) setup.next else setup.connect.skip, enabled = !busy, modifier = Modifier.weight(1f)) { next() }
                        "profile" -> {
                            skip(Modifier)
                            Primary(setup.profile.save, enabled = changed().isNotEmpty() && !busy, modifier = Modifier.weight(1f), onClick = saveProfile)
                        }
                        else -> Primary(setup.goal.finish, enabled = !busy, modifier = Modifier.weight(1f)) {
                            post("api/setup/finish.php", emptyMap(), setup.goal.error) { }
                        }
                    }
                }
            }
        }
    }
}

/** The wizard's next: the green pane, 48 high. */
@Composable
private fun Primary(label: String, enabled: Boolean, modifier: Modifier = Modifier, onClick: () -> Unit) {
    val look = BtnLook(
        border = if (enabled) Ownify.Health.copy(alpha = 0.38f) else Ownify.GlassBorderSoft,
        fill = if (enabled) Ownify.Health.copy(alpha = 0.16f) else Ownify.GlassSoft
    )
    Btn(label, onClick = onClick, enabled = enabled, look = look, modifier = modifier.heightIn(min = 48.dp))
}

/** `.setup__title` and `.setup__lede`. */
@Composable
private fun StepHead(title: String, lede: String) {
    T(title, OwnifyType.style(Ownify.FsSection, FontWeight.SemiBold, tracking = (-0.02).em, lineHeight = 1.25.em), Modifier.semantics { heading() })
    T(lede, OwnifyType.style(Ownify.FsBody, color = Ownify.TextSecondary), Modifier.padding(top = Ownify.Space2))
}

// ----------------------------------------------------------------- 1 · focus

@Composable
private fun ColumnScope.FocusStep(setup: Setup, focus: String?, onChoose: (String) -> Unit) {
    StepHead(setup.focus.title, setup.focus.lede)
    Column(Modifier.fillMaxWidth().padding(top = Ownify.Space5), verticalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
        setup.focus.options.forEach { option -> FocusChoice(option, option.key == focus) { onChoose(option.key) } }
    }
}

/** `.setup-choice`: a wizard tile as a row — the focus's tile, its name and line, and the answer's ring. */
@Composable
private fun FocusChoice(option: SetupFocusOption, chosen: Boolean, onClick: () -> Unit) {
    InButton {
        val accent = Accent.of(option.accent).color
        val interaction = remember { MutableInteractionSource() }
        val shape = RoundedCornerShape(Ownify.RadiusMd)
        val fast = tween<Color>(Ownify.FastMs, easing = Ownify.Ease)
        val border by animateColorAsState(if (chosen) Ownify.mix(accent, 0.42f, Color.Transparent) else Ownify.GlassHairline, fast, label = "border")
        val fill by animateColorAsState(if (chosen) Ownify.mix(accent, 0.13f, Color.Transparent) else Ownify.fill(0.035f), fast, label = "fill")
        val ring by animateColorAsState(if (chosen) accent else Ownify.GlassBorder, fast, label = "ring")
        val dot by animateColorAsState(if (chosen) Ownify.mix(accent, 0.20f, Color.Transparent) else Color.Transparent, fast, label = "dot")
        Row(
            Modifier
                .fillMaxWidth()
                .press(interaction)
                .heightIn(min = 64.dp)
                .clip(shape)
                .background(fill)
                .border(1.dp, border, shape)
                .clickable(interaction, indication = null, role = Role.RadioButton, onClick = blurring(onClick))
                .semantics(mergeDescendants = true) { selected = chosen }
                .cssPadding(androidx.compose.foundation.layout.PaddingValues(start = Ownify.Space3, end = Ownify.Space4, top = Ownify.Space3, bottom = Ownify.Space3), border = 1.dp),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)
        ) {
            OwnifyIcons.named(option.icon)?.let { IconTile(it, size = 36.dp, radius = 12.dp, iconSize = 18.dp, color = accent) }
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                T(option.label, OwnifyType.style(Ownify.FsLabel, FontWeight.SemiBold, tracking = (-0.01).em))
                T(option.line, JStyle.Tiny)
            }
            Box(
                Modifier.size(22.dp).clip(CircleShape).background(dot).border(1.5.dp, ring, CircleShape),
                contentAlignment = Alignment.Center
            ) {
                if (chosen) JIcon(OwnifyIcons.check, size = 13.dp, color = accent)
            }
        }
    }
}

// ------------------------------------------------------------- 2 · gegevens

@Composable
private fun ColumnScope.ConnectStep(setup: Setup, health: PhoneHealth) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val c = setup.connect
    val source = c.source
    val askRead = rememberLauncherForActivityResult(PermissionController.createRequestPermissionResultContract()) { granted ->
        // Something can be read now: the sync picks it up at once, as in Instellingen.
        if (granted.isNotEmpty()) OwnifyBackgroundSync.healthAccessGranted(context)
        scope.launch { health.read(context) }
    }
    val linked = health.granted > 0

    StepHead(c.title, c.lede)

    val shape = RoundedCornerShape(Ownify.RadiusMd)
    val border by animateColorAsState(
        if (linked) Ownify.mix(Ownify.Health, 0.30f, Color.Transparent) else Ownify.GlassHairline,
        tween(Ownify.SlowMs, easing = Ownify.EaseOut),
        label = "border"
    )
    Column(
        Modifier
            .fillMaxWidth()
            .padding(top = Ownify.Space5)
            .clip(shape)
            .background(Ownify.fill(0.035f))
            .border(1.dp, border, shape)
            .cssPadding(androidx.compose.foundation.layout.PaddingValues(Ownify.Space4), border = 1.dp)
    ) {
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)) {
            OwnifyIcons.named(source.icon)?.let { IconTile(it, size = 36.dp, radius = 12.dp, iconSize = 18.dp, color = if (linked) Ownify.Health else Ownify.TextSecondary) }
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                T(source.label, OwnifyType.style(Ownify.FsLabel, FontWeight.SemiBold))
                T(source.note, JStyle.Tiny)
            }
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                Box(Modifier.size(7.dp).clip(CircleShape).background(if (linked) Ownify.Health else Ownify.TextFaint))
                T(if (linked) source.connectedLabel else source.notConnectedLabel, OwnifyType.style(Ownify.FsTiny, FontWeight.SemiBold, Ownify.TextSecondary))
            }
        }
        Box(Modifier.fillMaxWidth().padding(top = Ownify.Space3).height(1.dp).background(Ownify.GlassHairline))
        T(source.app, OwnifyType.style(Ownify.FsSmall, color = Ownify.TextSecondary), Modifier.padding(top = Ownify.Space3))

        // What can be asked for on this phone, only while it is missing.
        val needsInstall = health.known && !health.available
        val needsRead = health.available && health.granted == 0
        if (needsInstall || needsRead) {
            Btn(
                when {
                    needsRead -> "Toegang geven"
                    health.sdk == HealthConnectClient.SDK_UNAVAILABLE_PROVIDER_UPDATE_REQUIRED -> "Health Connect bijwerken"
                    else -> "Health Connect installeren"
                },
                onClick = { if (needsRead) askRead.launch(HealthPermissions) else openHealthConnectStore(context) },
                modifier = Modifier.fillMaxWidth().padding(top = Ownify.Space4)
            )
        }
        if (health.granted > 0) SyncResult()
    }

    Quiet(OwnifyIcons.utensils, c.manual, Modifier.padding(top = Ownify.Space4))
    Quiet(OwnifyIcons.sliders, c.later, Modifier.padding(top = Ownify.Space3))
}

/** `.setup__quiet`: a muted line with its icon. */
@Composable
private fun Quiet(icon: androidx.compose.ui.graphics.vector.ImageVector, text: String, modifier: Modifier = Modifier) {
    Row(modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
        JIcon(icon, Modifier.padding(top = 2.dp), size = 15.dp, color = Ownify.TextMuted)
        T(text, OwnifyType.style(Ownify.FsSmall, color = Ownify.TextMuted), Modifier.weight(1f))
    }
}

// ------------------------------------------------------------- 3 · over jou

@Composable
private fun ColumnScope.ProfileStep(setup: Setup, values: Map<String, String>, onChange: (String, String) -> Unit) {
    val focus = LocalFocusManager.current
    StepHead(setup.profile.title, setup.profile.lede)
    Column(Modifier.fillMaxWidth().padding(top = Ownify.Space5), verticalArrangement = Arrangement.spacedBy(Ownify.Space5)) {
        setup.profile.fields.forEach { field -> ProfileField(field, values[field.key] ?: field.input?.value.orEmpty(), onChange, onDone = { focus.clearFocus() }) }
    }
}

@Composable
private fun ProfileField(field: SetupField, value: String, onChange: (String, String) -> Unit, onDone: () -> Unit) {
    Column(Modifier.fillMaxWidth()) {
        T(field.label, JStyle.Caption, Modifier.padding(bottom = Ownify.Space2), uppercase = true)
        val input = field.input
        when {
            field.locked || input == null -> Row(
                Modifier.heightIn(min = 44.dp),
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.spacedBy(6.dp)
            ) {
                JIcon(OwnifyIcons.lock, size = 13.dp, color = Ownify.TextMuted)
                T(field.value.orEmpty(), OwnifyType.style(Ownify.FsLabel, FontWeight.SemiBold))
            }
            input.type == "date" -> DateControl(input, value, field.label) { onChange(field.key, it) }
            else -> Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
                JInput(
                    value,
                    { onChange(field.key, it.filter { c -> c.isDigit() || c == ',' || c == '.' }.take(6)) },
                    modifier = Modifier.weight(1f),
                    label = field.label,
                    keyboard = KeyboardOptions(keyboardType = KeyboardType.Decimal, imeAction = ImeAction.Done),
                    actions = androidx.compose.foundation.text.KeyboardActions(onDone = { onDone() })
                )
                if (input.unit.isNotEmpty()) T(input.unit, OwnifyType.style(Ownify.FsSmall, color = Ownify.TextMuted))
            }
        }
        T(field.reason, JStyle.Tiny, Modifier.padding(top = Ownify.Space2))
        if (!field.locked && field.note != null) {
            Row(Modifier.padding(top = Ownify.Space1), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                JIcon(OwnifyIcons.lock, size = 13.dp, color = Ownify.TextMuted)
                T(field.note, JStyle.Tiny)
            }
        }
    }
}

// ----------------------------------------------------------------- 4 · doel

@Composable
private fun ColumnScope.GoalStep(
    setup: Setup,
    declined: Boolean,
    busy: Boolean,
    onDecline: () -> Unit,
    onAdd: (Map<String, String>) -> Unit,
    onOwn: () -> Unit
) {
    val g = setup.goal
    StepHead(g.title, g.lede)

    val suggestion = g.suggestion
    if (suggestion != null && !declined && g.goals.isEmpty()) {
        val shape = RoundedCornerShape(Ownify.RadiusMd)
        Column(
            Modifier
                .fillMaxWidth()
                .padding(top = Ownify.Space5)
                .clip(shape)
                .background(Ownify.mix(Ownify.Health, 0.08f, Color.Transparent))
                .border(1.dp, Ownify.mix(Ownify.Health, 0.28f, Color.Transparent), shape)
                .cssPadding(androidx.compose.foundation.layout.PaddingValues(Ownify.Space4), border = 1.dp)
        ) {
            T(suggestion.eyebrow, JStyle.Caption, uppercase = true)
            T(suggestion.name, OwnifyType.style(Ownify.FsLabel, FontWeight.SemiBold, tracking = (-0.01).em), Modifier.padding(top = Ownify.Space2))
            T(suggestion.basis, OwnifyType.style(Ownify.FsSmall, color = Ownify.TextSecondary), Modifier.padding(top = Ownify.Space1))
            T(suggestion.summary, JStyle.Tiny, Modifier.padding(top = Ownify.Space2))
            Row(Modifier.padding(top = Ownify.Space4), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)) {
                Primary(suggestion.add, enabled = !busy) { onAdd(suggestion.input) }
                LinkButton(suggestion.decline, enabled = !busy) { onDecline() }
            }
        }
    }

    if (g.goals.isNotEmpty()) {
        val shape = RoundedCornerShape(Ownify.RadiusSm)
        Row(
            Modifier
                .fillMaxWidth()
                .padding(top = Ownify.Space4)
                .clip(shape)
                .background(Ownify.mix(Ownify.Health, 0.09f, Color.Transparent))
                .border(1.dp, Ownify.mix(Ownify.Health, 0.30f, Color.Transparent), shape)
                .cssPadding(androidx.compose.foundation.layout.PaddingValues(Ownify.Space3), border = 1.dp)
                .semantics { liveRegion = LiveRegionMode.Polite },
            horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)
        ) {
            JIcon(OwnifyIcons.check, Modifier.padding(top = 2.dp), size = 15.dp, color = Ownify.Health)
            T(g.addedText(g.goals.first()), OwnifyType.style(Ownify.FsSmall, color = Ownify.TextSecondary), Modifier.weight(1f))
        }
    } else if (g.canAdd) {
        Btn(
            g.own,
            onClick = onOwn,
            enabled = !busy,
            icon = OwnifyIcons.plus,
            modifier = Modifier.fillMaxWidth().padding(top = Ownify.Space4).heightIn(min = 48.dp)
        )
    }
}

/** The profile answers as typed, kept across a restart of the app. */
private fun mapSaver() = androidx.compose.runtime.saveable.Saver<androidx.compose.runtime.MutableState<Map<String, String>>, ArrayList<String>>(
    save = { state -> ArrayList(state.value.flatMap { listOf(it.key, it.value) }) },
    restore = { list -> mutableStateOf(list.chunked(2).associate { it[0] to it[1] }) }
)
