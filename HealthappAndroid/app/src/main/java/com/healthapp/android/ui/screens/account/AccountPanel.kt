package com.healthapp.android.ui.screens.account

import android.content.Context
import android.net.Uri
import android.provider.OpenableColumns
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.interaction.collectIsPressedAsState
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateMapOf
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
import androidx.compose.ui.layout.layout
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalFocusManager
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.toggleableState
import androidx.compose.ui.state.ToggleableState
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import com.healthapp.android.data.AppData
import com.healthapp.android.data.JoluActions
import com.healthapp.android.data.JoluAppState
import com.healthapp.android.data.Outcome
import com.healthapp.android.data.Person
import com.healthapp.android.jolu.JoluAuthState
import com.healthapp.android.jolu.JoluConnection
import com.healthapp.android.ui.app.Avatar
import com.healthapp.android.ui.app.Avatars
import com.healthapp.android.ui.app.LocalShell
import com.healthapp.android.ui.app.Overlay
import com.healthapp.android.ui.app.OverlayFrame
import com.healthapp.android.ui.app.PanelColumn
import com.healthapp.android.ui.app.PanelHead
import com.healthapp.android.ui.app.RoundButton
import com.healthapp.android.ui.design.Btn
import com.healthapp.android.ui.design.BtnLook
import com.healthapp.android.ui.design.JIcon
import com.healthapp.android.ui.design.JInput
import com.healthapp.android.ui.design.JStyle
import com.healthapp.android.ui.design.JoluIcons
import com.healthapp.android.ui.design.T
import com.healthapp.android.ui.design.Toggle
import com.healthapp.android.ui.design.press
import com.healthapp.android.ui.theme.Jolu
import com.healthapp.android.ui.theme.JoluType
import java.io.ByteArrayOutputStream
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import org.json.JSONObject

/** A picture larger than this is not read at all; the server's own limit (3 MB) answers below it. */
private const val MAX_PICTURE_BYTES = 8 * 1024 * 1024

/** The server's words for a picture over its limit (user_set_avatar()), for one too large to send. */
private const val PICTURE_TOO_LARGE = "Kies een afbeelding van maximaal 3 MB."

/** The server's words for an upload that did not arrive, for a picture the phone could not read. */
private const val PICTURE_UNREADABLE = "Uploaden is niet gelukt."

/**
 * The account panel, signed in (components/account-modal.php): who is
 * signed in, the username and picture, the way to Vrienden, and Uitloggen —
 * and behind Vrienden the panel's second page (components/account-friends.php,
 * run by friends.js). The panel always opens on the account.
 */
@Composable
fun AccountPanel(overlay: Overlay.Account, data: AppData) {
    val shell = LocalShell.current
    var page by remember { mutableStateOf("main") }
    val friends = page == "friends"
    val title = if (friends) "Vrienden" else "Account"
    val scroll = rememberScrollState()

    // Each page starts at its top, as showPage() scrolls the sheet back.
    LaunchedEffect(page) { scroll.scrollTo(0) }

    OverlayFrame(overlay, title = title) { panelModifier ->
        PanelColumn(panelModifier, scroll = scroll) {
            PanelHead(
                title,
                onClose = { shell.close(overlay) },
                leading = if (friends) ({ RoundButton(JoluIcons.chevronLeft, "Terug naar account", { page = "main" }) }) else null
            )
            if (friends) FriendsView(data) else AccountView(data, onFriends = { page = "friends" })
        }
    }
}

// ---------------------------------------------------------------- account

@Composable
private fun ColumnScope.AccountView(data: AppData, onFriends: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val focus = LocalFocusManager.current
    val auth = data.auth
    var error by remember { mutableStateOf<String?>(null) }
    var username by rememberSaveable(auth.username) { mutableStateOf(auth.username.orEmpty()) }
    var savingName by remember { mutableStateOf(false) }
    var picked by remember { mutableStateOf<Uri?>(null) }
    var uploading by remember { mutableStateOf(false) }
    val signingOut = JoluConnection.auth is JoluAuthState.Working
    val friendCount = data.community.friends.size
    val requestCount = data.community.pending.size

    val picker = rememberLauncherForActivityResult(ActivityResultContracts.PickVisualMedia()) { uri ->
        if (uri != null) picked = uri
    }

    fun saveName() {
        focus.clearFocus()
        error = null
        savingName = true
        scope.launch {
            val outcome = JoluActions.form(context, "api/profile/username.php", mapOf("username" to username), "Er ging iets mis.")
            savingName = false
            if (outcome is Outcome.Refused) error = outcome.message
        }
    }

    // .account__error — one line for the whole panel, above everything.
    error?.let { ErrorBox(it, Modifier.padding(bottom = Jolu.Space4)) }

    // .account__identity
    Row(
        Modifier.fillMaxWidth(),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Jolu.Space3)
    ) {
        AvatarCircle(auth.avatar, 52.dp, 22.dp)
        Column(Modifier.weight(1f)) {
            T(auth.username.orEmpty(), JStyle.Subtitle)
            T("Lid sinds ${memberSince(auth.createdAt)}", JStyle.Tiny, Modifier.padding(top = Jolu.Space1))
        }
    }

    // Gebruikersnaam — 24 below the identity (its margin and the form's collapse).
    FieldLabel("Gebruikersnaam", Modifier.padding(top = Jolu.Space5))
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Jolu.Space2)) {
        JInput(
            username, { username = it.take(30) },
            modifier = Modifier.weight(1f),
            label = "Gebruikersnaam",
            keyboard = KeyboardOptions(
                capitalization = KeyboardCapitalization.None, autoCorrectEnabled = false,
                keyboardType = KeyboardType.Ascii, imeAction = ImeAction.Done
            ),
            actions = KeyboardActions(onDone = { if (!savingName) saveName() })
        )
        Btn("Opslaan", onClick = ::saveName, enabled = !savingName)
    }

    // Profielfoto
    FieldLabel("Profielfoto", Modifier.padding(top = Jolu.Space4))
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Jolu.Space2)) {
        FileField(
            picked?.let { fileName(context, it) },
            onClick = { picker.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly)) },
            modifier = Modifier.weight(1f)
        )
        Btn("Uploaden", enabled = !uploading, onClick = {
            error = null
            uploading = true
            scope.launch {
                val outcome = upload(context, picked)
                uploading = false
                when (outcome) {
                    is Outcome.Done -> {
                        // form.reset(): the field is empty again, the new picture everywhere.
                        picked = null
                        Avatars.forget()
                    }
                    is Outcome.Refused -> error = outcome.message
                    Outcome.SignedOut -> Unit
                }
            }
        })
    }
    FieldHint("JPG, PNG of WebP, maximaal 3 MB.")

    // Vrienden: a field-shaped button that says where things stand.
    FieldLabel("Vrienden", Modifier.padding(top = Jolu.Space4))
    NavField(
        text = if (friendCount == 0) "Nog geen vrienden" else "$friendCount ${if (friendCount == 1) "vriend" else "vrienden"}",
        badge = if (requestCount > 0) "$requestCount ${if (requestCount == 1) "verzoek" else "verzoeken"}" else null,
        onClick = onFriends,
        label = "Vrienden"
    )

    // Uitloggen: the form's 16 and the button's own 16.
    Btn(
        "Uitloggen",
        onClick = { JoluConnection.logout(context) },
        enabled = !signingOut,
        modifier = Modifier.fillMaxWidth().padding(top = Jolu.Space6)
    )
}

/**
 * Reads the picked picture and sends it as the website's form does: the
 * file as `avatar`. Nothing picked sends the empty field a browser sends,
 * and the server says what it says to that.
 */
private suspend fun upload(context: Context, uri: Uri?): Outcome {
    val path = "api/profile/avatar.php"
    if (uri == null) {
        return JoluActions.upload(context, path, "avatar", "", "application/octet-stream", ByteArray(0), "Er ging iets mis.")
    }

    val resolver = context.contentResolver
    var tooLarge = false
    val bytes = withContext(Dispatchers.IO) {
        runCatching {
            resolver.openInputStream(uri)?.use { stream ->
                val out = ByteArrayOutputStream()
                val buffer = ByteArray(16 * 1024)
                while (true) {
                    val n = stream.read(buffer)
                    if (n < 0) break
                    out.write(buffer, 0, n)
                    if (out.size() > MAX_PICTURE_BYTES) {
                        tooLarge = true
                        break
                    }
                }
                out.toByteArray()
            }
        }.getOrNull()
    }
    if (tooLarge) return Outcome.Refused(PICTURE_TOO_LARGE)
    if (bytes == null) return Outcome.Refused(PICTURE_UNREADABLE)

    val mime = resolver.getType(uri) ?: "application/octet-stream"
    return JoluActions.upload(context, path, "avatar", fileName(context, uri), mime, bytes, "Er ging iets mis.")
}

private fun fileName(context: Context, uri: Uri): String =
    runCatching {
        context.contentResolver.query(uri, arrayOf(OpenableColumns.DISPLAY_NAME), null, null, null)?.use { c ->
            if (c.moveToFirst()) c.getString(0) else null
        }
    }.getOrNull() ?: "foto"

/** PHP's `date('j M Y')` of the account's start, as the panel prints it: "27 Sep 2026". */
internal fun memberSince(createdAt: String?): String {
    val parts = createdAt?.take(10)?.split("-") ?: return ""
    if (parts.size != 3) return ""
    val months = listOf("Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec")
    val month = parts[1].toIntOrNull()?.let { months.getOrNull(it - 1) } ?: return ""
    val day = parts[2].toIntOrNull() ?: return ""
    return "$day $month ${parts[0]}"
}

/** `.account__avatar`: a circle with a hairline, the picture or the muted user icon. */
@Composable
fun AvatarCircle(path: String?, size: Dp, iconSize: Dp, modifier: Modifier = Modifier) {
    Box(
        modifier
            .size(size)
            .clip(CircleShape)
            .background(Jolu.white(0.07f))
            .border(1.dp, Jolu.GlassHairline, CircleShape)
    ) {
        Avatar(path, iconSize = iconSize, iconColor = Jolu.TextMuted)
    }
}

/** The field look the panel's inputs share: at least 44 high, the soft border, `.05`. */
private fun Modifier.fieldBox(fill: Color = Jolu.white(0.05f)): Modifier {
    val shape = RoundedCornerShape(Jolu.RadiusSm)
    return this
        .heightIn(min = 44.dp)
        .clip(shape)
        .background(fill)
        .border(1.dp, Jolu.GlassBorderSoft, shape)
}

/**
 * `.account__file`: the picture field. On the website it is the browser's
 * file field (a "Bestand kiezen" button and the chosen name); here the same
 * field opens Android's photo picker.
 */
@Composable
private fun FileField(name: String?, onClick: () -> Unit, modifier: Modifier = Modifier) {
    val interaction = remember { MutableInteractionSource() }
    Row(
        modifier
            .fillMaxWidth()
            .fieldBox()
            .clickable(interaction, indication = null, role = Role.Button, onClickLabel = "Bestand kiezen", onClick = onClick)
            .semantics(mergeDescendants = true) { contentDescription = "Profielfoto: ${name ?: "Geen bestand gekozen"}" }
            .padding(horizontal = Jolu.Space3, vertical = Jolu.Space2),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Jolu.Space2)
    ) {
        val shape = RoundedCornerShape(50)
        T(
            "Bestand kiezen",
            JoluType.style(Jolu.FsTiny, FontWeight.SemiBold, Jolu.TextPrimary),
            Modifier
                .clip(shape)
                .background(Jolu.white(0.08f))
                .border(1.dp, Jolu.GlassBorderSoft, shape)
                .padding(horizontal = Jolu.Space2, vertical = 3.dp),
            maxLines = 1
        )
        T(
            name ?: "Geen bestand gekozen",
            JoluType.style(Jolu.FsSmall, color = Jolu.TextSecondary),
            Modifier.weight(1f),
            maxLines = 1,
            ellipsis = true
        )
    }
}

/** `.account__nav`: the text, a badge when requests wait, and a chevron; `.08` while pressed. */
@Composable
private fun NavField(text: String, badge: String?, onClick: () -> Unit, label: String) {
    val interaction = remember { MutableInteractionSource() }
    val pressed by interaction.collectIsPressedAsState()
    val fill by animateColorAsState(
        if (pressed) Jolu.white(0.08f) else Jolu.white(0.05f),
        tween(Jolu.FastMs, easing = Jolu.Ease),
        label = "nav"
    )
    Row(
        Modifier
            .press(interaction)
            .fillMaxWidth()
            .fieldBox(fill)
            .clickable(interaction, indication = null, role = Role.Button, onClick = onClick)
            .semantics(mergeDescendants = true) { contentDescription = "$label: $text${badge?.let { ", $it" }.orEmpty()}" }
            .padding(horizontal = Jolu.Space3),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Jolu.Space2)
    ) {
        // .account__nav-text: the line, and the badge pushed to its end (margin-left: auto).
        Row(Modifier.weight(1f), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Jolu.Space2)) {
            T(text, JoluType.style(Jolu.FsLabel), Modifier.weight(1f, fill = badge != null), maxLines = 1, ellipsis = true)
            if (badge != null) Badge(badge)
        }
        JIcon(JoluIcons.chevronRight, size = 16.dp, color = Jolu.TextMuted)
    }
}

/** `.account__badge`: the requests waiting, in the green. */
@Composable
private fun Badge(text: String) {
    val shape = RoundedCornerShape(50)
    Box(
        Modifier
            .heightIn(min = 22.dp)
            .clip(shape)
            .background(Jolu.mix(Jolu.Health, 0.22f, Color.Transparent))
            .border(1.dp, Jolu.mix(Jolu.Health, 0.42f, Color.Transparent), shape)
            .padding(horizontal = Jolu.Space2),
        contentAlignment = Alignment.Center
    ) {
        T(text, JoluType.style(Jolu.FsTiny, FontWeight.SemiBold), maxLines = 1)
    }
}

// ---------------------------------------------------------------- Vrienden

/** Where one person's row stands after something was done with it (friends.js rowBusy/rowDone/rowError). */
private data class RowState(
    val message: String? = null,
    val error: Boolean = false,
    val busy: Boolean = false,
    val done: Boolean = false,
    val confirming: Boolean = false
)

/** The search's one account, as the server describes it (friend_person()). */
private data class Found(val person: Person, val relation: String, val status: String, val canRequest: Boolean) {
    companion object {
        fun parse(o: JSONObject?): Found? {
            if (o == null) return null
            val id = o.optInt("id", 0).takeIf { it > 0 } ?: return null
            val avatar = if (o.isNull("avatar")) null else o.optString("avatar").takeIf { it.isNotEmpty() }
            return Found(
                Person(id, o.optString("username"), avatar),
                o.optString("relation"), o.optString("status"), o.optBoolean("can_request")
            )
        }
    }
}

private const val RESULT = "result"

@Composable
private fun ColumnScope.FriendsView(data: AppData) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val focus = LocalFocusManager.current
    val community = data.community
    var searching by rememberSaveable { mutableStateOf(false) }
    var query by rememberSaveable { mutableStateOf("") }
    var searchError by remember { mutableStateOf<String?>(null) }
    var busySearch by remember { mutableStateOf(false) }
    var found by remember { mutableStateOf<Found?>(null) }
    val rows = remember { mutableStateMapOf<String, RowState>() }
    // The switch moves at once and settles on the server's answer (setToggle()).
    var shownAllowed by remember { mutableStateOf<Boolean?>(null) }
    var toggleBusy by remember { mutableStateOf(false) }
    var toggleError by remember { mutableStateOf<String?>(null) }

    // Somebody may have asked, or answered, since the pages were read.
    LaunchedEffect(Unit) { JoluAppState.refresh(context) }

    /** A search result showing where the two now stand, as applyPerson() does. */
    fun applyFound(person: JSONObject?) {
        Found.parse(person)?.let { found = it }
    }

    fun act(key: String, personId: Int, action: String) {
        val inResult = key == RESULT
        rows[key] = (rows[key] ?: RowState()).copy(busy = true)
        scope.launch {
            val outcome = JoluActions.form(
                context, "api/friends/request.php",
                mapOf("user_id" to personId.toString(), "action" to action),
                "Er ging iets mis.", reload = false
            )
            when (outcome) {
                is Outcome.Done -> {
                    val message = outcome.body.optString("message")
                    if (inResult) {
                        applyFound(outcome.body.optJSONObject("person"))
                        rows[key] = RowState(message = if (action == "request") null else message)
                    } else {
                        rows[key] = RowState(message = message, done = true)
                        // The same person in the search result follows (syncResult()).
                        if (found?.person?.id == personId) {
                            applyFound(outcome.body.optJSONObject("person"))
                            rows.remove(RESULT)
                        }
                    }
                    // The lists a moment later, so "Jullie zijn nu vrienden." can be read before the row moves.
                    delay(1_100)
                    JoluAppState.reload(context)
                    if (!inResult) rows.remove(key)
                }
                is Outcome.Refused -> {
                    // The row says why, and keeps saying it: the lists are left alone.
                    if (inResult) applyFound(outcome.body?.optJSONObject("person"))
                    rows[key] = RowState(message = outcome.message, error = true)
                }
                Outcome.SignedOut -> Unit
            }
        }
    }

    fun search() {
        focus.clearFocus()
        searchError = null
        val name = query.trim()
        if (name.isEmpty()) {
            searchError = "Vul een gebruikersnaam in."
            return
        }
        busySearch = true
        scope.launch {
            val outcome = JoluActions.form(context, "api/friends/search.php", mapOf("username" to name), "Er ging iets mis.", reload = false)
            busySearch = false
            val person = (outcome as? Outcome.Done)?.body?.optJSONObject("person")?.let(Found::parse)
            if (person != null) {
                found = person
                rows.remove(RESULT)
            } else if (outcome != Outcome.SignedOut) {
                found = null
                searchError = (outcome as? Outcome.Refused)?.message ?: "Er ging iets mis."
            }
        }
    }

    // ------------------------------------------------------ Vriend toevoegen
    Btn(
        "Vriend toevoegen",
        onClick = {
            searching = !searching
            if (!searching) {
                searchError = null
                found = null
            }
        },
        icon = JoluIcons.plus,
        modifier = Modifier.fillMaxWidth()
    )

    if (searching) {
        FieldLabel("Gebruikersnaam", Modifier.padding(top = Jolu.Space4))
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Jolu.Space2)) {
            JInput(
                query, {
                    query = it.take(30)
                    searchError = null
                },
                modifier = Modifier.weight(1f),
                placeholder = "Gebruikersnaam",
                label = "Gebruikersnaam",
                keyboard = KeyboardOptions(
                    capitalization = KeyboardCapitalization.None, autoCorrectEnabled = false,
                    keyboardType = KeyboardType.Ascii, imeAction = ImeAction.Search
                ),
                actions = KeyboardActions(onSearch = { if (!busySearch) search() })
            )
            Btn("Zoeken", onClick = ::search, enabled = !busySearch)
        }
        FieldHint("Vul de volledige gebruikersnaam in.")
        searchError?.let { ErrorBox(it, Modifier.padding(top = Jolu.Space3)) }

        found?.let { result ->
            val shape = RoundedCornerShape(Jolu.RadiusSm)
            Box(
                Modifier
                    .fillMaxWidth()
                    .padding(top = Jolu.Space3)
                    .clip(shape)
                    .background(Jolu.white(0.04f))
                    .border(1.dp, Jolu.GlassHairline, shape)
                    .padding(Jolu.Space3)
                    .semantics { liveRegion = LiveRegionMode.Polite }
            ) {
                SearchResult(result, rows[RESULT] ?: RowState()) { action -> act(RESULT, result.person.id, action) }
            }
        }
    }

    // ------------------------------------------------------ Vriendverzoeken
    FieldLabel("Vriendverzoeken", Modifier.padding(top = Jolu.Space5))
    if (community.pending.isEmpty()) {
        T("Geen nieuwe vriendverzoeken.", JStyle.Tiny)
    } else {
        Column(verticalArrangement = Arrangement.spacedBy(Jolu.Space3)) {
            community.pending.forEach { person ->
                val key = "in:${person.id}"
                val state = rows[key] ?: RowState()
                PersonRow(
                    person, state, status = "Wil vrienden met je worden",
                    below = if (!state.done) ({ AnswerButtons(state.busy, { act(key, person.id, "accept") }, { act(key, person.id, "decline") }) }) else null
                )
            }
        }
    }
    if (community.sent.isNotEmpty()) {
        FieldLabel("Verstuurd", Modifier.padding(top = Jolu.Space4))
        Column(verticalArrangement = Arrangement.spacedBy(Jolu.Space3)) {
            community.sent.forEach { person -> PersonRow(person, RowState(), status = "Verzoek verstuurd") }
        }
    }

    // --------------------------------------------- Vriendverzoeken toestaan
    val allowed = shownAllowed ?: community.allowRequests
    val shape = RoundedCornerShape(Jolu.RadiusSm)
    Row(
        Modifier
            .padding(top = Jolu.Space5)
            .fillMaxWidth()
            .alpha(if (toggleBusy) 0.7f else 1f)
            .heightIn(min = 56.dp)
            .clip(shape)
            .background(Jolu.white(0.05f))
            .border(1.dp, Jolu.GlassBorderSoft, shape)
            .clickable(enabled = !toggleBusy, role = Role.Switch) {
                val before = allowed
                shownAllowed = !before
                toggleError = null
                toggleBusy = true
                scope.launch {
                    val outcome = JoluActions.form(
                        context, "api/friends/settings.php",
                        mapOf("allow_requests" to if (before) "0" else "1"),
                        "Dit kon niet worden opgeslagen.", reload = false
                    )
                    toggleBusy = false
                    when (outcome) {
                        is Outcome.Done -> {
                            shownAllowed = outcome.body.optBoolean("allow_requests", !before)
                            JoluAppState.refresh(context)
                        }
                        is Outcome.Refused -> {
                            shownAllowed = before
                            toggleError = outcome.message
                        }
                        Outcome.SignedOut -> Unit
                    }
                }
            }
            .semantics(mergeDescendants = true) { toggleableState = ToggleableState(allowed) }
            .padding(Jolu.Space3),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Jolu.Space3)
    ) {
        Column(Modifier.weight(1f)) {
            T("Vriendverzoeken toestaan", JoluType.style(Jolu.FsLabel, FontWeight.Medium, Jolu.TextSecondary))
            T(
                toggleError ?: if (allowed) "Anderen kunnen je een vriendverzoek sturen."
                else "Niemand kan je een nieuw vriendverzoek sturen. Je vrienden blijven.",
                JoluType.style(Jolu.FsTiny, color = if (toggleError != null) Jolu.Nutrition else Jolu.TextMuted),
                Modifier.padding(top = 2.dp)
            )
        }
        Toggle(allowed)
    }

    // --------------------------------------------------------------- Vrienden
    FieldLabel("Vrienden (${community.friends.size})", Modifier.padding(top = Jolu.Space5))
    if (community.friends.isEmpty()) {
        T("Je hebt nog geen vrienden. Voeg iemand toe met zijn of haar gebruikersnaam.", JStyle.Tiny)
    } else {
        Column(verticalArrangement = Arrangement.spacedBy(Jolu.Space3)) {
            community.friends.forEach { person ->
                val key = "friend:${person.id}"
                val state = rows[key] ?: RowState()
                PersonRow(
                    person, state, status = null,
                    trailing = if (!state.confirming && !state.done) ({
                        LinkButton("Verwijderen", enabled = !state.busy) { rows[key] = state.copy(confirming = true) }
                    }) else null,
                    full = if (state.confirming && !state.done) ({
                        RemoveConfirm(
                            person.username,
                            busy = state.busy,
                            onCancel = { rows[key] = state.copy(confirming = false) },
                            onRemove = { act(key, person.id, "remove") }
                        )
                    }) else null
                )
            }
        }
    }
}

/**
 * One person (components/friend-person.php, `.friend`): the picture and
 * name, where you stand, and what can be done — beside the name ([trailing]),
 * under it ([below], the answers), or across the whole row ([full], the
 * removal's question).
 */
@Composable
private fun PersonRow(
    person: Person,
    state: RowState,
    status: String?,
    trailing: (@Composable () -> Unit)? = null,
    full: (@Composable () -> Unit)? = null,
    below: (@Composable () -> Unit)? = null
) {
    Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(Jolu.Space2)) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Jolu.Space3)) {
            AvatarCircle(person.avatar, 40.dp, 18.dp)
            Column(Modifier.weight(1f)) {
                T(person.username, JoluType.style(Jolu.FsLabel, FontWeight.SemiBold), maxLines = 1, ellipsis = true)
                val line = state.message ?: status
                if (!line.isNullOrEmpty()) {
                    T(
                        line,
                        JoluType.style(Jolu.FsTiny, color = if (state.error) Jolu.Nutrition else Jolu.TextMuted),
                        Modifier.padding(top = 2.dp).semantics { liveRegion = LiveRegionMode.Polite }
                    )
                }
            }
            trailing?.invoke()
        }
        if (below != null) {
            Box(Modifier.fillMaxWidth().padding(start = 40.dp + Jolu.Space3)) { below() }
        }
        full?.invoke()
    }
}

/** Accepteren / Weigeren, side by side under the name (`.friend__actions--wide`). */
@Composable
private fun AnswerButtons(busy: Boolean, onAccept: () -> Unit, onDecline: () -> Unit) {
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Jolu.Space2)) {
        Btn("Accepteren", onClick = onAccept, enabled = !busy, modifier = Modifier.weight(1f))
        Btn("Weigeren", onClick = onDecline, enabled = !busy, modifier = Modifier.weight(1f))
    }
}

/** `.friend__confirm`: the one step between a tap and a friendship gone; the safe answer first. */
@Composable
private fun RemoveConfirm(name: String, busy: Boolean, onCancel: () -> Unit, onRemove: () -> Unit) {
    val shape = RoundedCornerShape(Jolu.RadiusSm)
    Column(
        Modifier
            .fillMaxWidth()
            .clip(shape)
            .background(Jolu.white(0.04f))
            .border(1.dp, Jolu.GlassHairline, shape)
            .padding(Jolu.Space3),
        verticalArrangement = Arrangement.spacedBy(Jolu.Space3)
    ) {
        T("$name verwijderen uit je vrienden?", JoluType.style(Jolu.FsSmall, color = Jolu.TextSecondary))
        Row(horizontalArrangement = Arrangement.spacedBy(Jolu.Space2)) {
            Btn("Annuleren", onClick = onCancel, enabled = !busy, modifier = Modifier.weight(1f))
            Btn("Verwijderen", onClick = onRemove, enabled = !busy, modifier = Modifier.weight(1f), look = BtnLook.Final)
        }
    }
}

/** The search's one account, in the lists' shape, with what can be done about it now (applyPerson()). */
@Composable
private fun SearchResult(found: Found, state: RowState, onAction: (String) -> Unit) {
    // The pill says "Verzoek verstuurd"; the line above it would only repeat it.
    val status = if (found.relation == "outgoing") null else found.status
    val below: (@Composable () -> Unit)? = when {
        found.relation == "none" && found.canRequest -> ({
            Btn("Vriendverzoek sturen", onClick = { onAction("request") }, enabled = !state.busy, modifier = Modifier.fillMaxWidth())
        })
        found.relation == "outgoing" -> ({ SentMark() })
        found.relation == "incoming" -> ({ AnswerButtons(state.busy, { onAction("accept") }, { onAction("decline") }) })
        else -> null
    }
    PersonRow(found.person, state, status = status, below = below)
}

/** `.friend__done`: "Verzoek verstuurd" once it is — a state, not a button. */
@Composable
private fun SentMark() {
    val shape = RoundedCornerShape(50)
    Row(
        Modifier
            .fillMaxWidth()
            .heightIn(min = 42.dp)
            .clip(shape)
            .background(Jolu.mix(Jolu.Health, 0.18f, Color.Transparent))
            .border(1.dp, Jolu.mix(Jolu.Health, 0.42f, Color.Transparent), shape)
            .padding(horizontal = Jolu.Space4),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Jolu.Space2, Alignment.CenterHorizontally)
    ) {
        JIcon(JoluIcons.check, size = 15.dp, color = Jolu.TextPrimary)
        T("Verzoek verstuurd", JoluType.style(Jolu.FsSmall, FontWeight.SemiBold))
    }
}

/**
 * `.btn--link`: quiet — no border, no fill — with a full-height target,
 * pulled 8 past the right edge so its text ends where the values above end.
 */
@Composable
fun LinkButton(text: String, modifier: Modifier = Modifier, enabled: Boolean = true, onClick: () -> Unit) {
    val interaction = remember { MutableInteractionSource() }
    Box(
        modifier
            .layout { measurable, constraints ->
                val pull = Jolu.Space2.roundToPx()
                val placeable = measurable.measure(constraints)
                layout((placeable.width - pull).coerceAtLeast(0), placeable.height) { placeable.place(0, 0) }
            }
            .press(interaction, enabled = enabled)
            .alpha(if (enabled) 1f else 0.45f)
            .heightIn(min = 44.dp)
            .clip(RoundedCornerShape(50))
            .clickable(interaction, indication = null, enabled = enabled, role = Role.Button, onClick = onClick)
            .padding(horizontal = Jolu.Space2),
        contentAlignment = Alignment.Center
    ) {
        T(text, JoluType.style(Jolu.FsTiny, FontWeight.SemiBold, Jolu.TextSecondary), maxLines = 1)
    }
}
