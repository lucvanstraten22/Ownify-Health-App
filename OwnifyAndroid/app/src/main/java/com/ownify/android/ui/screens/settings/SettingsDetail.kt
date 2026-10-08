package com.ownify.android.ui.screens.settings

import androidx.compose.ui.semantics.heading
import android.content.Intent
import android.net.Uri
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.tween
import androidx.compose.foundation.ScrollState
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.interaction.collectIsPressedAsState
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.layout.Layout
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalGraphicsContext
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.disabled
import androidx.compose.ui.semantics.onClick
import androidx.compose.ui.semantics.role
import androidx.compose.ui.semantics.selected
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.stateDescription
import androidx.compose.ui.semantics.toggleableState
import androidx.compose.ui.state.ToggleableState
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.em
import androidx.compose.ui.unit.sp
import androidx.core.net.toUri
import com.ownify.android.data.AppData
import com.ownify.android.data.Integration
import com.ownify.android.data.Outcome
import com.ownify.android.data.OwnifyActions
import com.ownify.android.data.OwnifyAppState
import com.ownify.android.data.ProfileField
import com.ownify.android.data.SettingsBlock
import com.ownify.android.data.SettingsPage
import com.ownify.android.connection.OwnifyConnection
import com.ownify.android.data.ToggleItem
import com.ownify.android.ui.app.DetailColumn
import com.ownify.android.ui.app.LocalShell
import com.ownify.android.ui.app.Overlay
import com.ownify.android.ui.design.Btn
import androidx.compose.foundation.layout.height
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.liveRegion
import com.ownify.android.data.ActionItem
import com.ownify.android.data.OwnifyAssistant
import com.ownify.android.ui.design.BtnLook
import com.ownify.android.ui.design.CardStyle
import com.ownify.android.ui.design.Chip
import com.ownify.android.ui.design.Disclaimer
import com.ownify.android.ui.design.IconTile
import com.ownify.android.ui.design.JCard
import com.ownify.android.ui.design.JIcon
import com.ownify.android.ui.design.JStyle
import com.ownify.android.ui.design.OwnifyIcons
import com.ownify.android.ui.design.LocalScreen
import com.ownify.android.ui.design.PageIntro
import com.ownify.android.ui.design.T
import com.ownify.android.ui.design.Toggle
import com.ownify.android.ui.design.cardShape
import com.ownify.android.ui.design.chWidth
import com.ownify.android.ui.design.cssPadding
import com.ownify.android.ui.design.press
import com.ownify.android.ui.design.reveal
import com.ownify.android.ui.screens.account.AvatarCircle
import com.ownify.android.ui.screens.account.LinkButton
import com.ownify.android.ui.screens.devices.StatusDot
import com.ownify.android.ui.theme.Ownify
import com.ownify.android.ui.theme.InButton
import com.ownify.android.ui.theme.OwnifyThemeChoice
import com.ownify.android.ui.theme.OwnifyThemeStore
import com.ownify.android.ui.theme.OwnifyType
import kotlinx.coroutines.launch
import androidx.compose.runtime.CompositionLocalProvider
import com.ownify.android.ui.theme.LocalTracking

/**
 * One settings screen (pages/settings-detail.php), built from the blocks
 * the server lists for it — identity, fields, sign-in, sources, choices,
 * facts, switches, rows, notes and section headings — and, when it offers a choice that is
 * not kept, the line that says so (the theme's is kept).
 */
@Composable
fun SettingsDetail(data: AppData, page: SettingsPage, scroll: ScrollState) {
    DetailColumn(scroll, back = "Instellingen", backAria = "Terug naar Instellingen") {
        PageIntro(page.title, page.lede, Modifier.reveal())
        page.blocks.forEachIndexed { n, block ->
            when (block) {
                SettingsBlock.Identity -> IdentityHero(data)
                is SettingsBlock.Fields -> FieldsBlock(data, block)
                is SettingsBlock.Signin -> SigninBlock(data, block)
                is SettingsBlock.Integrations -> IntegrationsBlock(data, block)
                is SettingsBlock.Choice -> ChoiceBlock(page.id, n, block)
                is SettingsBlock.States -> StatesBlock(block)
                is SettingsBlock.Toggles -> TogglesBlock(block)
                is SettingsBlock.Rows -> RowsBlock(block)
                is SettingsBlock.Note -> NoteBlock(block)
                is SettingsBlock.Actions -> ActionsBlock(block)
                is SettingsBlock.Section -> SectionHead(block)
            }
        }
        if (page.blocks.any { it is SettingsBlock.Choice && !it.saves }) Disclaimer(data.settings.notSaved)
    }
}

/** `.settings-hero`: the picture, the username, the name. */
@Composable
private fun IdentityHero(data: AppData) {
    val profile = data.settings.profile
    val name = listOfNotNull(profile.firstName, profile.lastName).joinToString(" ").trim()
    JCard(Modifier.fillMaxWidth().reveal()) {
        Column(Modifier.fillMaxWidth(), horizontalAlignment = Alignment.CenterHorizontally) {
            AvatarCircle(profile.avatar, 68.dp, 28.dp)
            T(profile.username ?: "Niet ingelogd", OwnifyType.style(20.sp, FontWeight.SemiBold, tracking = (-0.02).em), Modifier.padding(top = Ownify.Space3), align = TextAlign.Center)
            T(name.ifEmpty { "Naam nog niet ingevuld" }, JStyle.Meta, Modifier.padding(top = Ownify.Space1), align = TextAlign.Center)
        }
    }
}

/** A block's eyebrow and, when it has one, its lede (`.settings-block__lede`: 4 closer, 12 above the card). */
@Composable
private fun BlockHead(title: String, lede: String?) {
    SettingsEyebrow(title)
    if (!lede.isNullOrEmpty()) {
        T(
            lede,
            JStyle.Meta,
            // margin-top: -4px collapses into the eyebrow's 8, so the lede and
            // everything under it sit 4 higher: drawn 4 up, 4 less below.
            Modifier.fillMaxWidth().padding(horizontal = Ownify.Space2).padding(bottom = Ownify.Space3 - 4.dp).offset(y = (-4).dp)
        )
    }
}

@Composable
private fun FieldsBlock(data: AppData, block: SettingsBlock.Fields) {
    val shell = LocalShell.current
    Column(Modifier.fillMaxWidth().reveal()) {
        BlockHead(block.title, block.lede)
        SettingsCard {
            block.fields.forEachIndexed { i, field ->
                FieldRow(field, divided = i > 0) {
                    // Two open the account panel, as they did before the profile existed.
                    if (field.opens == "account") shell.open(Overlay.Account()) else shell.open(Overlay.EditField(field.key))
                }
            }
        }
    }
}

/**
 * `components/settings-field.php`: the label (and its note), the value or
 * the picture, and what a tap does — a chevron to change it, a lock once it
 * is fixed, nothing for a value worked out from another.
 */
@Composable
private fun FieldRow(field: ProfileField, divided: Boolean, onEdit: () -> Unit) {
    // A live field is a <button> (letter-spacing: normal); a locked or derived one is a <div>.
    CompositionLocalProvider(LocalTracking provides if (field.live) 0.sp else LocalTracking.current) {
        val narrow = LocalScreen.current.narrow
        val live = field.live
        val filled = field.value != null
        val interaction = remember { MutableInteractionSource() }
        val pressed by interaction.collectIsPressedAsState()
        val fill by animateColorAsState(
            when {
                live && pressed -> Ownify.fill(0.05f)
                !live -> Ownify.fill(0.022f)
                else -> Color.Transparent
            },
            tween(Ownify.FastMs, easing = Ownify.Ease),
            label = "field"
        )

        FieldColumns(
            Modifier
                .then(if (live) Modifier.press(interaction) else Modifier)
                .fillMaxWidth()
                .settingsRow(divided, fill)
                .then(if (live) Modifier.clickable(interaction, indication = null, role = Role.Button, onClick = onEdit) else Modifier)
                .semantics(mergeDescendants = true) { }
                .cssPadding(if (narrow) Ownify.Space3 else Ownify.Space4, top = Ownify.Space3, bottom = Ownify.Space3, above = if (divided) 1.dp else 0.dp),
            label = {
                Column {
                    T(field.label, OwnifyType.style(Ownify.FsLabel, FontWeight.Medium))
                    if (!field.note.isNullOrEmpty()) T(field.note, JStyle.Tiny, Modifier.padding(top = 2.dp))
                }
            },
            value = {
                if (field.kind == "image") {
                    AvatarCircle(field.value, 34.dp, 17.dp)
                } else {
                    T(
                        field.value ?: field.blank,
                        OwnifyType.style(
                            Ownify.FsSmall,
                            if (filled) FontWeight.SemiBold else FontWeight.Medium,
                            if (filled) Ownify.TextSecondary else Ownify.TextMuted
                        ),
                        align = TextAlign.End,
                        maxLines = 1,
                        ellipsis = true
                    )
                }
            },
            mark = {
                when {
                    field.state == "locked" -> JIcon(OwnifyIcons.lock, size = 15.dp, color = Ownify.TextMuted)
                    live -> JIcon(OwnifyIcons.chevronRight, size = 15.dp, color = Ownify.TextFaint)
                }
            }
        )
    }
}

/**
 * `.settings-field`'s `grid-template-columns: minmax(0, 1fr) auto auto`,
 * 12 apart: the mark and the value take what they need, the label what is
 * left, each centred on the row. The gap before the mark stays when there
 * is no mark, as a grid's gap does beside an empty track.
 */
@Composable
private fun FieldColumns(
    modifier: Modifier,
    label: @Composable () -> Unit,
    value: @Composable () -> Unit,
    mark: @Composable () -> Unit
) {
    Layout(contents = listOf(label, value, mark), modifier = modifier) { (labels, values, marks), constraints ->
        val gap = Ownify.Space3.roundToPx()
        val width = constraints.maxWidth
        val loose = constraints.copy(minWidth = 0, minHeight = 0)
        val markBoxes = marks.map { it.measure(loose) }
        val markWidth = markBoxes.maxOfOrNull { it.width } ?: 0
        val valueBoxes = values.map { it.measure(loose.copy(maxWidth = (width - markWidth - 2 * gap).coerceAtLeast(0))) }
        val valueWidth = valueBoxes.maxOfOrNull { it.width } ?: 0
        val labelBoxes = labels.map { it.measure(loose.copy(maxWidth = (width - markWidth - valueWidth - 2 * gap).coerceAtLeast(0))) }
        val height = (labelBoxes + valueBoxes + markBoxes).maxOfOrNull { it.height }
            .let { (it ?: 0).coerceIn(constraints.minHeight, constraints.maxHeight) }
        fun centre(h: Int) = Alignment.CenterVertically.align(h, height)
        layout(width, height) {
            labelBoxes.forEach { it.placeRelative(0, centre(it.height)) }
            valueBoxes.forEach { it.placeRelative(width - markWidth - gap - it.width, centre(it.height)) }
            markBoxes.forEach { it.placeRelative(width - it.width, centre(it.height)) }
        }
    }
}

/**
 * Inloggen: the ways into this account, in the fields' shape. Linking Google
 * to an account that has a password is the website's own flow — Google's
 * pages in a browser signed in to Ownify — so the row that links it opens the
 * website (docs/PARITY.md). Signing in with a Google account that is already
 * linked, or starting a new account with Google, is the sign-in panel's
 * "Doorgaan met Google".
 */
@Composable
private fun SigninBlock(data: AppData, block: SettingsBlock.Signin) {
    val context = LocalContext.current
    val auth = data.auth
    val email = auth.identities.firstOrNull { it.first == "email" }
    val google = auth.identities.firstOrNull { it.first == "google" }

    Column(Modifier.fillMaxWidth().reveal()) {
        SettingsEyebrow(block.title)
        SettingsCard {
            if (!auth.signedIn) {
                StaticField("Niet ingelogd", null, "—", filled = false)
            } else {
                StaticField(
                    "E-mail en wachtwoord",
                    if (email != null) "Inloggen met je gebruikersnaam of e-mailadres" else "Je logt in met Google",
                    email?.second ?: "Niet ingesteld",
                    filled = email != null
                )
                when {
                    google != null -> StaticField("Google", "Gekoppeld — je kunt ook met Google inloggen", google.second ?: "Gekoppeld", filled = true, check = true, divided = true)
                    auth.googleAvailable -> FieldRow(
                        ProfileField("google", "Google", "Koppel Google om daarmee in te loggen", null, null, null, "editable", null, "Koppel Google", null),
                        divided = true
                    ) {
                        runCatching {
                            context.startActivity(Intent(Intent.ACTION_VIEW, OwnifyConnection.api.siteUrl.toUri()).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
                        }
                    }
                    else -> StaticField("Google", null, "Nog niet beschikbaar", filled = false, divided = true)
                }
            }
        }
    }
}

/** A field row that is a fact, not a control. */
@Composable
private fun StaticField(label: String, note: String?, value: String, filled: Boolean, check: Boolean = false, divided: Boolean = false) {
    val narrow = LocalScreen.current.narrow
    FieldColumns(
        Modifier
            .fillMaxWidth()
            .settingsRow(divided)
            .semantics(mergeDescendants = true) { }
            .cssPadding(if (narrow) Ownify.Space3 else Ownify.Space4, top = Ownify.Space3, bottom = Ownify.Space3, above = if (divided) 1.dp else 0.dp),
        label = {
            Column {
                T(label, OwnifyType.style(Ownify.FsLabel, FontWeight.Medium))
                if (note != null) T(note, JStyle.Tiny, Modifier.padding(top = 2.dp))
            }
        },
        value = {
            T(
                value,
                OwnifyType.style(Ownify.FsSmall, if (filled) FontWeight.SemiBold else FontWeight.Medium, if (filled) Ownify.TextSecondary else Ownify.TextMuted),
                align = TextAlign.End,
                maxLines = 1,
                ellipsis = true
            )
        },
        mark = { if (check) JIcon(OwnifyIcons.check, size = 15.dp, color = Ownify.TextFaint) }
    )
}

@Composable
private fun IntegrationsBlock(data: AppData, block: SettingsBlock.Integrations) {
    Column(Modifier.fillMaxWidth().reveal()) {
        SettingsEyebrow(block.title)
        Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(Ownify.Space3)) {
            data.settings.integrations.forEach { IntegrationCard(data, it) }
        }
    }
}

/**
 * `components/settings-integration.php`: one health source, its settings
 * folded inside it. Health Connect on this phone also shows the phone's own
 * side, and its "Nu synchroniseren" works here (docs/PARITY.md).
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun IntegrationCard(data: AppData, item: Integration) {
    val shell = LocalShell.current
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val narrow = LocalScreen.current.narrow
    val labels = data.settings.integrationLabels
    val shadows = LocalGraphicsContext.current.shadowContext
    var open by rememberSaveable(item.key) { mutableStateOf(false) }
    var busy by remember { mutableStateOf<String?>(null) }
    val connected = item.connected
    val statusText = when (item.status) {
        "connected" -> labels["connected"]
        "revoked" -> labels["revoked"]
        "error" -> labels["error"]
        else -> labels["disconnected"]
    }.orEmpty()
    val thisPhone = item.key == "health_connect"
    val interaction = remember { MutableInteractionSource() }
    val pressed by interaction.collectIsPressedAsState()
    val headFill by animateColorAsState(if (pressed) Ownify.fill(0.04f) else Color.Transparent, tween(Ownify.FastMs, easing = Ownify.Ease), label = "head")
    val turn by animateFloatAsState(if (open) 180f else 0f, tween(Ownify.FastMs, easing = Ownify.Ease), label = "chevron")
    val border by animateColorAsState(
        if (connected) Ownify.mix(Ownify.Health, 0.28f, Color.Transparent) else Ownify.GlassBorderSoft,
        tween(Ownify.SlowMs, easing = Ownify.EaseOut),
        label = "border"
    )

    /** Posts one field and reads the pages again: the row, the count and the summary follow. */
    fun post(action: String, path: String, field: String, value: String) {
        busy = action
        scope.launch {
            OwnifyActions.form(context, path, mapOf(field to value), "Er ging iets mis.")
            busy = null
        }
    }

    JCard(
        Modifier.fillMaxWidth(),
        style = CardStyle.Quiet.copy(border = border),
        shape = cardShape(),
        padding = PaddingValues(0.dp)
    ) {
        // .integration__head
        val head: @Composable () -> Unit = {
            OwnifyIcons.named(item.icon)?.let {
                IconTile(it, size = 32.dp, radius = 11.dp, iconSize = 17.dp, color = if (connected) Ownify.Health else Ownify.TextSecondary)
            }
        }
        // A <button>: its text keeps letter-spacing: normal.
        InButton {
            Column(
                Modifier
                    .fillMaxWidth()
                    .heightIn(min = 64.dp)
                    .background(headFill)
                    .clickable(interaction, indication = null) { open = !open }
                    .clearAndSetSemantics {
                        role = Role.Button
                        contentDescription = (labels["expand"] ?: "%s").replace("%s", item.label) + ", $statusText"
                        stateDescription = if (open) "Uitgeklapt" else "Ingeklapt"
                        onClick { open = !open; true }
                    }
                    .cssPadding(if (narrow) Ownify.Space3 else Ownify.Space4, top = Ownify.Space3, bottom = Ownify.Space3),
                verticalArrangement = Arrangement.spacedBy(Ownify.Space1)
            ) {
                Row(Modifier.fillMaxWidth().heightIn(min = 40.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)) {
                    head()
                    Column(Modifier.weight(1f)) {
                        T(item.label, OwnifyType.style(Ownify.FsLabel, FontWeight.SemiBold), maxLines = 1, ellipsis = true)
                        T(item.note, JStyle.Tiny, Modifier.padding(top = 2.dp), maxLines = 1, ellipsis = true)
                    }
                    if (!narrow) StatusDot(statusText, connected)
                    JIcon(OwnifyIcons.chevronDown, Modifier.graphicsLayer { rotationZ = turn }, size = 16.dp, color = Ownify.TextFaint)
                }
                // Below 360 dp the status takes a line of its own, under the name.
                if (narrow) Row(Modifier.padding(start = 32.dp + Ownify.Space3)) { StatusDot(statusText, connected) }
            }
        }

        if (open) {
            Column(Modifier.fillMaxWidth()) {
                Hairline()
                Column(Modifier.fillMaxWidth().padding(start = Ownify.Space4, end = Ownify.Space4, bottom = Ownify.Space4)) {
                    Column(Modifier.fillMaxWidth().padding(top = Ownify.Space2)) {
                        PhoneRow(labels["status"].orEmpty(), statusText, first = true)
                        item.account?.let { PhoneRow(labels["account"].orEmpty(), it) }
                        PhoneRow(labels["last_sync"].orEmpty(), item.lastSync ?: labels["never"].orEmpty(), empty = item.lastSync == null)
                        PhoneRow(labels["permissions"].orEmpty(), labels["permissions_note"].orEmpty())
                    }

                    if (item.devices.isNotEmpty()) {
                        Caption(labels["devices"].orEmpty(), Modifier.padding(top = Ownify.Space4))
                        Column(Modifier.fillMaxWidth().padding(top = Ownify.Space2)) {
                            item.devices.forEachIndexed { i, device ->
                                if (i > 0) Hairline()
                                Row(
                                    Modifier.fillMaxWidth().padding(top = if (i == 0) 0.dp else Ownify.Space3, bottom = Ownify.Space3),
                                    verticalAlignment = Alignment.CenterVertically,
                                    horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)
                                ) {
                                    Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                                        T(device.label, OwnifyType.style(Ownify.FsSmall, FontWeight.SemiBold))
                                        T(device.lastSync ?: labels["never"].orEmpty(), JStyle.Tiny)
                                    }
                                    LinkButton(
                                        labels["revoke"].orEmpty(),
                                        Modifier.semantics { contentDescription = (labels["revoke_device"] ?: "%s").replace("%s", device.label) },
                                        enabled = busy == null
                                    ) { post("revoke:${device.id}", "api/integrations/device-revoke.php", "device", device.id.toString()) }
                                }
                            }
                        }
                    }

                    Caption(labels["categories"].orEmpty(), Modifier.padding(top = Ownify.Space4))
                    FlowRow(
                        Modifier.fillMaxWidth().padding(top = Ownify.Space2),
                        horizontalArrangement = Arrangement.spacedBy(Ownify.Space2),
                        verticalArrangement = Arrangement.spacedBy(Ownify.Space2)
                    ) {
                        item.categories.forEach { Chip(it, muted = true) }
                    }

                    if (!item.error.isNullOrEmpty()) {
                        IntegrationHint(item.error, OwnifyIcons.info, warn = true)
                    }

                    // This phone's own Health Connect, when this is it.
                    if (thisPhone && OwnifyConnection.state is com.ownify.android.connection.OwnifyState.Connected) {
                        PhoneSection(rememberPhoneHealth(), Modifier.padding(top = Ownify.Space4))
                    }

                    // .integration__actions
                    val actions: @Composable (Modifier) -> Unit = { each ->
                        if (connected) {
                            if (thisPhone) SyncNowButton(each)
                            else Btn(labels["sync_now"].orEmpty(), onClick = {}, enabled = false, modifier = each)
                            Btn(
                                labels["disconnect"].orEmpty(),
                                onClick = { post("disconnect", "api/integrations/disconnect.php", "provider", item.provider.orEmpty()) },
                                enabled = busy == null,
                                modifier = each
                            )
                        } else {
                            val canPair = item.available && !item.provider.isNullOrEmpty()
                            Btn(
                                if (item.status == "revoked") labels["reconnect"].orEmpty() else labels["connect"].orEmpty(),
                                onClick = {
                                    val provider = item.provider ?: return@Btn
                                    if (item.transport == "device") {
                                        shell.open(Overlay.Pairing(provider))
                                    } else {
                                        // A cloud source's consent screen is the provider's own page, in the browser.
                                        runCatching {
                                            context.startActivity(
                                                Intent(Intent.ACTION_VIEW, (OwnifyConnection.api.siteUrl + "api/integrations/" + Uri.encode(provider) + "/start.php").toUri())
                                                    .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                                            )
                                        }
                                    }
                                },
                                enabled = canPair,
                                modifier = each
                            )
                        }
                    }
                    if (narrow) {
                        Column(Modifier.fillMaxWidth().padding(top = Ownify.Space4), verticalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
                            actions(Modifier.fillMaxWidth())
                        }
                    } else {
                        Row(Modifier.fillMaxWidth().padding(top = Ownify.Space4), horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
                            actions(Modifier.weight(1f))
                        }
                    }
                    if (thisPhone && connected) SyncResult()

                    when {
                        !item.available && !connected && !item.blocked.isNullOrEmpty() -> IntegrationHint(item.blocked, OwnifyIcons.lock)
                        connected -> IntegrationHint(labels["disconnect_confirm"].orEmpty(), OwnifyIcons.lock)
                    }
                }
            }
        }
    }
}

/** `.integration__hint`: a tiny line with its icon — in the caution colour for a failure. */
@Composable
private fun IntegrationHint(text: String, icon: androidx.compose.ui.graphics.vector.ImageVector, warn: Boolean = false) {
    val color = if (warn) Ownify.Attention else Ownify.TextMuted
    Row(
        Modifier.fillMaxWidth().padding(top = Ownify.Space3),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)
    ) {
        JIcon(icon, size = 14.dp, color = color)
        T(text, OwnifyType.style(Ownify.FsTiny, color = color), Modifier.weight(1f))
    }
}

/**
 * A choice: one option ticked. The tick moves; nothing is stored (settings.js)
 * — except the theme's, which is kept on this phone (OwnifyThemeStore) and
 * turns the app Dark or White at once, or the phone's way on Systeem; its tick
 * is the choice kept.
 */
@Composable
private fun ChoiceBlock(pageId: String, index: Int, block: SettingsBlock.Choice) {
    val narrow = LocalScreen.current.narrow
    val context = LocalContext.current
    val theme = block.name == "theme"
    var picked by rememberSaveable("$pageId/$index") { mutableStateOf(block.selected) }
    val selected = if (theme) OwnifyThemeStore.choice.key else picked
    Column(Modifier.fillMaxWidth().reveal()) {
        SettingsEyebrow(block.title)
        InButton {
            SettingsCard {
                block.options.forEachIndexed { i, option ->
                    val on = option.key == selected
                    val interaction = remember { MutableInteractionSource() }
                    val pressed by interaction.collectIsPressedAsState()
                    val fill by animateColorAsState(if (pressed && !option.disabled) Ownify.fill(0.05f) else Color.Transparent, tween(Ownify.FastMs, easing = Ownify.Ease), label = "option")
                    val mark by animateFloatAsState(if (on) 1f else 0f, tween(Ownify.FastMs, easing = Ownify.Ease), label = "mark")
                    Row(
                        Modifier
                            .press(interaction, enabled = !option.disabled)
                            .fillMaxWidth()
                            .settingsRow(i > 0, fill)
                            .clickable(interaction, indication = null, enabled = !option.disabled, role = Role.RadioButton) {
                                if (theme) OwnifyThemeStore.choose(context, OwnifyThemeChoice.of(option.key)) else picked = option.key
                            }
                            .semantics(mergeDescendants = true) {
                                this.selected = on
                                if (option.disabled) disabled()
                            }
                            .cssPadding(if (narrow) Ownify.Space3 else Ownify.Space4, top = Ownify.Space3, bottom = Ownify.Space3, above = if (i > 0) 1.dp else 0.dp),
                        verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)
                    ) {
                        Column(Modifier.weight(1f)) {
                            T(
                                option.label,
                                OwnifyType.style(
                                    Ownify.FsLabel,
                                    if (on) FontWeight.SemiBold else FontWeight.Medium,
                                    if (option.disabled) Ownify.TextSecondary else Ownify.TextPrimary
                                )
                            )
                            if (!option.note.isNullOrEmpty()) T(option.note, JStyle.Tiny, Modifier.padding(top = 2.dp))
                        }
                        Box(
                            Modifier.size(22.dp).graphicsLayer {
                                alpha = mark
                                val s = 0.7f + 0.3f * mark
                                scaleX = s
                                scaleY = s
                            },
                            contentAlignment = Alignment.Center
                        ) {
                            JIcon(OwnifyIcons.check, size = 18.dp, color = Ownify.Health)
                        }
                    }
                }
            }
        }
    }
}

/** Read-only facts: a label and its value on one line, a sentence under them. */
@Composable
private fun StatesBlock(block: SettingsBlock.States) {
    val narrow = LocalScreen.current.narrow
    Column(Modifier.fillMaxWidth().reveal()) {
        BlockHead(block.title, block.lede)
        SettingsCard {
            block.items.forEachIndexed { i, item ->
                Column(
                    Modifier
                        .fillMaxWidth()
                        .settingsRow(i > 0)
                        .semantics(mergeDescendants = true) { }
                        .cssPadding(if (narrow) Ownify.Space3 else Ownify.Space4, top = Ownify.Space3, bottom = Ownify.Space3, above = if (i > 0) 1.dp else 0.dp)
                ) {
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Ownify.Space4)) {
                        T(item.label, OwnifyType.style(Ownify.FsLabel, FontWeight.Medium), Modifier.weight(1f).alignByBaseline())
                        T(
                            item.value ?: "—",
                            OwnifyType.style(
                                Ownify.FsSmall,
                                if (item.value != null) FontWeight.SemiBold else FontWeight.Medium,
                                if (item.value != null) Ownify.tint(Ownify.Health, 0.34f) else Ownify.TextMuted
                            ),
                            Modifier.alignByBaseline(),
                            align = TextAlign.End
                        )
                    }
                    if (!item.note.isNullOrEmpty()) {
                        T(item.note, JStyle.Tiny, Modifier.padding(top = Ownify.Space1).widthIn(max = chWidth(JStyle.Tiny, 44f)))
                    }
                }
            }
        }
    }
}

/** Switches that do nothing yet: shown, never moved — "a switch that moves but changes nothing is a lie". */
@Composable
private fun TogglesBlock(block: SettingsBlock.Toggles) {
    val narrow = LocalScreen.current.narrow
    Column(Modifier.fillMaxWidth().reveal()) {
        BlockHead(block.title, block.lede)
        InButton {
            SettingsCard {
                block.items.forEachIndexed { i, item ->
                    if (item.key != null) {
                        LiveToggleRow(item, item.key, divided = i > 0)
                        return@forEachIndexed
                    }
                    Row(
                        Modifier
                            .fillMaxWidth()
                            .settingsRow(i > 0)
                            .semantics(mergeDescendants = true) {
                                toggleableState = ToggleableState(item.on)
                                role = Role.Switch
                                disabled()
                            }
                            .cssPadding(if (narrow) Ownify.Space3 else Ownify.Space4, top = Ownify.Space3, bottom = Ownify.Space3, above = if (i > 0) 1.dp else 0.dp),
                        verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)
                    ) {
                        Column(Modifier.weight(1f)) {
                            T(item.label, OwnifyType.style(Ownify.FsLabel, FontWeight.Medium, Ownify.TextSecondary))
                            if (!item.note.isNullOrEmpty()) T(item.note, JStyle.Tiny, Modifier.padding(top = 2.dp))
                        }
                        Toggle(item.on, dimmed = true)
                    }
                }
            }
        }
    }
}

/**
 * A switch that saves (`.settings-toggle--live`: a toggles item with a
 * [key]). It moves when pressed, waits dimmed while the server answers
 * (api/profile/privacy.php), and moves back — saying why — when it could not
 * be saved; the boards follow once it is.
 */
@Composable
private fun LiveToggleRow(item: ToggleItem, key: String, divided: Boolean) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val narrow = LocalScreen.current.narrow
    var shown by remember(item.on) { mutableStateOf<Boolean?>(null) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val on = shown ?: item.on
    val interaction = remember { MutableInteractionSource() }
    val pressed by interaction.collectIsPressedAsState()
    val fill by animateColorAsState(if (pressed) Ownify.fill(0.05f) else Color.Transparent, tween(Ownify.FastMs, easing = Ownify.Ease), label = "toggle")

    Row(
        Modifier
            .fillMaxWidth()
            .alpha(if (busy) 0.7f else 1f)
            .settingsRow(divided, fill)
            .clickable(interaction, indication = null, enabled = !busy, role = Role.Switch) {
                val before = on
                shown = !before
                error = null
                busy = true
                scope.launch {
                    val outcome = OwnifyActions.form(
                        context, "api/profile/privacy.php",
                        mapOf(key to if (before) "0" else "1"),
                        "Dit kon niet worden opgeslagen.", reload = false
                    )
                    busy = false
                    when (outcome) {
                        is Outcome.Done -> {
                            shown = outcome.body.optBoolean(key, !before)
                            // The boards follow. A read already on its way may have left
                            // before the save, so this waits for it and reads once more.
                            OwnifyAppState.reload(context)
                        }
                        is Outcome.Refused -> {
                            shown = before
                            error = outcome.message
                        }
                        Outcome.SignedOut -> Unit
                    }
                }
            }
            .semantics(mergeDescendants = true) { toggleableState = ToggleableState(on) }
            .cssPadding(if (narrow) Ownify.Space3 else Ownify.Space4, top = Ownify.Space3, bottom = Ownify.Space3, above = if (divided) 1.dp else 0.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)
    ) {
        Column(Modifier.weight(1f)) {
            T(item.label, OwnifyType.style(Ownify.FsLabel, FontWeight.Medium, Ownify.TextSecondary))
            val note = error ?: (if (on) item.noteOn else item.noteOff) ?: item.note
            if (!note.isNullOrEmpty()) {
                T(note, OwnifyType.style(Ownify.FsTiny, color = if (error != null) Ownify.Attention else Ownify.TextMuted), Modifier.padding(top = 2.dp))
            }
        }
        Toggle(on)
    }
}

/**
 * Buttons that do one thing (`.settings-action`): "AI-gesprekken wissen".
 * The button asks first, in place; yes posts to the item's endpoint as the
 * website does, and the note under the label says what happened.
 */
@Composable
private fun ActionsBlock(block: SettingsBlock.Actions) {
    Column(Modifier.fillMaxWidth().reveal()) {
        SettingsEyebrow(block.title)
        InButton {
            SettingsCard {
                block.items.forEachIndexed { i, item -> ActionRow(item, divided = i > 0) }
            }
        }
    }
}

@Composable
private fun ActionRow(item: ActionItem, divided: Boolean) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val narrow = LocalScreen.current.narrow
    var asking by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var said by remember { mutableStateOf<String?>(null) }
    var failed by remember { mutableStateOf(false) }
    val look = if (item.danger) BtnLook.Final else BtnLook()

    Column(
        Modifier
            .fillMaxWidth()
            .settingsRow(divided)
            .cssPadding(if (narrow) Ownify.Space3 else Ownify.Space4, top = Ownify.Space3, bottom = Ownify.Space3, above = if (divided) 1.dp else 0.dp)
    ) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)) {
            Column(Modifier.weight(1f)) {
                T(item.label, OwnifyType.style(Ownify.FsLabel, FontWeight.Medium, Ownify.TextSecondary))
                val note = said ?: item.note
                if (!note.isNullOrEmpty()) {
                    T(
                        note,
                        OwnifyType.style(Ownify.FsTiny, color = if (failed) Ownify.Attention else Ownify.TextMuted),
                        Modifier.padding(top = 2.dp).semantics { liveRegion = LiveRegionMode.Polite }
                    )
                }
            }
            if (!asking) Btn(item.confirm, onClick = { asking = true }, look = look)
        }

        if (asking) {
            Column(Modifier.fillMaxWidth().padding(top = Ownify.Space3)) {
                Box(Modifier.fillMaxWidth().height(1.dp).background(Ownify.GlassHairline))
                T(item.question, OwnifyType.style(Ownify.FsSmall), Modifier.padding(top = Ownify.Space3))
                Row(
                    Modifier.fillMaxWidth().padding(top = Ownify.Space3),
                    horizontalArrangement = Arrangement.spacedBy(Ownify.Space2, Alignment.End)
                ) {
                    Btn(item.cancel, onClick = { asking = false }, enabled = !busy)
                    Btn(item.confirm, enabled = !busy, look = look, onClick = {
                        busy = true
                        scope.launch {
                            val outcome = OwnifyActions.form(context, item.endpoint, item.fields, "Dit kon niet worden gedaan.", reload = false)
                            busy = false
                            asking = false
                            when (outcome) {
                                is Outcome.Done -> {
                                    said = outcome.body.optString("message").ifEmpty { null }
                                    failed = false
                                    // The assistant's own list follows: none of them are left.
                                    if (item.key == "ai_clear_history") OwnifyAssistant.cleared()
                                }
                                is Outcome.Refused -> {
                                    said = outcome.message
                                    failed = true
                                }
                                Outcome.SignedOut -> Unit
                            }
                        }
                    })
                }
            }
        }
    }
}

/** Plain label and value, in an ordinary card. */
@Composable
private fun RowsBlock(block: SettingsBlock.Rows) {
    Column(Modifier.fillMaxWidth().reveal()) {
        SettingsEyebrow(block.title)
        JCard(Modifier.fillMaxWidth()) {
            block.items.forEachIndexed { i, (label, value) -> PhoneRow(label, value, first = i == 0) }
        }
    }
}

/** A note on its own, or under its eyebrow when it has a title — where a card would sit. */
@Composable
private fun NoteBlock(block: SettingsBlock.Note) {
    val title = block.title
    if (title == null) {
        SettingsNote(block.icon, block.text, Modifier.reveal())
        return
    }
    Column(Modifier.fillMaxWidth().reveal()) {
        SettingsEyebrow(title)
        SettingsNote(block.icon, block.text)
    }
}

/** `.settings-section`: a heading over the blocks after it — its icon tile, its name, its one line. */
@Composable
private fun SectionHead(block: SettingsBlock.Section) {
    Row(
        Modifier.fillMaxWidth().padding(top = Ownify.Space3).padding(horizontal = Ownify.Space2).reveal(),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)
    ) {
        OwnifyIcons.named(block.icon)?.let { IconTile(it) }
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            T(block.title, OwnifyType.style(Ownify.FsLabel, FontWeight.SemiBold, tracking = (-0.01f).em), Modifier.semantics { heading() })
            block.lede?.let { T(it, OwnifyType.style(Ownify.FsSmall, color = Ownify.TextMuted)) }
        }
    }
}

/** `.settings-note`: one framed line, its icon at the top. */
@Composable
fun SettingsNote(icon: String, text: String, modifier: Modifier = Modifier) {
    val shape = RoundedCornerShape(Ownify.RadiusMd)
    Row(
        modifier
            .fillMaxWidth()
            .clip(shape)
            .background(Ownify.lift(0.035f))
            .border(1.dp, Ownify.GlassHairline, shape)
            .cssPadding(PaddingValues(Ownify.Space4), border = 1.dp),
        horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)
    ) {
        OwnifyIcons.named(icon)?.let { JIcon(it, Modifier.padding(top = 2.dp), size = 16.dp, color = Ownify.TextMuted) }
        T(text, OwnifyType.style(Ownify.FsSmall, color = Ownify.TextSecondary), Modifier.weight(1f))
    }
}
