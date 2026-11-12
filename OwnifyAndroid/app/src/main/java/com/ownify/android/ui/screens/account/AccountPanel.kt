package com.ownify.android.ui.screens.account
import com.ownify.android.ui.app.Detail
import com.ownify.android.ui.design.SplitRow
import com.ownify.android.ui.design.NoticePlace
import com.ownify.android.ui.design.notice
import com.ownify.android.ui.design.insetRow
import com.ownify.android.ui.design.well

import java.time.LocalDate
import com.ownify.android.i18n.trn
import com.ownify.android.i18n.trf
import com.ownify.android.i18n.OwnifyFormat
import com.ownify.android.i18n.tr
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
import androidx.compose.foundation.layout.PaddingValues
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
import androidx.compose.ui.unit.em
import com.ownify.android.data.AppData
import com.ownify.android.data.OwnifyActions
import com.ownify.android.data.OwnifyAppState
import com.ownify.android.data.Outcome
import com.ownify.android.data.Person
import com.ownify.android.connection.OwnifyAuthState
import com.ownify.android.connection.OwnifyConnection
import com.ownify.android.ui.app.Avatar
import com.ownify.android.ui.app.Avatars
import com.ownify.android.ui.app.LocalShell
import com.ownify.android.ui.app.Overlay
import com.ownify.android.ui.app.OverlayFrame
import com.ownify.android.ui.app.PanelColumn
import com.ownify.android.ui.app.PanelHead
import com.ownify.android.ui.app.RoundButton
import com.ownify.android.ui.design.Btn
import com.ownify.android.ui.design.BtnLook
import com.ownify.android.ui.design.JIcon
import com.ownify.android.ui.design.JInput
import com.ownify.android.ui.design.JStyle
import com.ownify.android.ui.design.OwnifyIcons
import com.ownify.android.ui.design.T
import com.ownify.android.ui.design.Toggle
import com.ownify.android.ui.design.cssPadding
import com.ownify.android.ui.design.press
import com.ownify.android.ui.theme.ChromeText
import com.ownify.android.ui.theme.Ownify
import com.ownify.android.ui.theme.InButton
import com.ownify.android.ui.theme.OwnifyType
import java.io.ByteArrayOutputStream
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import org.json.JSONObject
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import com.ownify.android.ui.design.rememberFocusOnOpen
import com.ownify.android.ui.design.focusSafely
import androidx.compose.runtime.withFrameNanos
import com.ownify.android.ui.design.blurring
import com.ownify.android.ui.screens.community.FRIENDS_ADD
import com.ownify.android.ui.design.GlassRound
import androidx.compose.foundation.layout.offset

/** A picture larger than this is not read at all; the server's own limit (3 MB) answers below it. */
private const val MAX_PICTURE_BYTES = 8 * 1024 * 1024

/** The server's words for a picture over its limit (user_set_avatar()), for one too large to send. */
private val PICTURE_TOO_LARGE: String get() = tr("Kies een afbeelding van maximaal 3 MB.")

/** The server's words for an upload that did not arrive, for a picture the phone could not read. */
private val PICTURE_UNREADABLE: String get() = tr("Uploaden is niet gelukt.")

/**
 * The account panel, signed in (components/account-modal.php): who is
 * signed in, the username and picture, the way to Vrienden, and Uitloggen —
 * and behind Vrienden the panel's second page (components/account-friends.php,
 * run by friends.js). The panel always opens on the account.
 */
@Composable
fun AccountPanel(overlay: Overlay.Account, data: AppData) {
    val shell = LocalShell.current
    // Vrienden toevoegen on the Vrienden leaderboard opens it on Vrienden, Vriend toevoegen open.
    val addFriend = overlay.view == FRIENDS_ADD
    var page by remember { mutableStateOf(if (addFriend) "friends" else "main") }
    val friends = page == "friends"
    val title = if (friends) tr("Vrienden") else tr("Account")
    val scroll = rememberScrollState()

    // Each page starts at its top, as showPage() scrolls the sheet back.
    LaunchedEffect(page) { scroll.scrollTo(0) }
    // open(): the first control on screen takes focus — the username field.
    val focusFirst = rememberFocusOnOpen()

    OverlayFrame(overlay, title = title) { panelModifier ->
        PanelColumn(panelModifier, scroll = scroll) {
            PanelHead(
                title,
                onClose = { shell.close(overlay) },
                leading = if (friends) ({ RoundButton(OwnifyIcons.chevronLeft, tr("Terug naar account"), { page = "main" }) }) else null
            )
            if (friends) FriendsView(data, searchFirst = addFriend) else AccountView(
                data, onFriends = { page = "friends" },
                onAccount = {
                    // Instellingen first, then its Account screen on top of it.
                    shell.close(overlay)
                    shell.goTo("settings")
                    shell.openDetail(Detail.SettingsPage("account"))
                },
                focusFirst = focusFirst
            )
        }
    }
}

// ---------------------------------------------------------------- account

/**
 * B5 (50.0, the owner's pick "List"): the popup is a summary — you as the
 * first row (photo, username, real name), which opens Instellingen › Account
 * where everything about you is changed; Vrienden with its count and red
 * dot; Uitloggen with its icon (components/account-modal.php).
 */
@Composable
private fun ColumnScope.AccountView(data: AppData, onFriends: () -> Unit, onAccount: () -> Unit, focusFirst: FocusRequester?) {
    val context = LocalContext.current
    val auth = data.auth
    val signingOut = OwnifyConnection.auth is OwnifyAuthState.Working
    val friendCount = data.community.friends.size
    val profile = data.settings.profile
    val realName = listOfNotNull(profile.firstName, profile.lastName).joinToString(" ").trim()
    val username = auth.username.orEmpty()

    MeRow(auth.avatar, username, realName, onAccount, focusFirst)
    NavField(
        text = tr("Vrienden"),
        value = if (friendCount == 0) tr("Nog geen") else OwnifyFormat.number(friendCount.toDouble(), 0),
        notice = "friends",
        onClick = onFriends,
        label = tr("Vrienden"),
        modifier = Modifier.padding(top = Ownify.Space3)
    )

    // Uitloggen: the form's 16 and the button's own 16, its icon as in Instellingen.
    Btn(
        tr("Uitloggen"),
        onClick = { OwnifyConnection.logout(context) },
        enabled = !signingOut,
        icon = OwnifyIcons.logout,
        modifier = Modifier.fillMaxWidth().padding(top = Ownify.Space6)
    )
}

/** `.account__me`: you, as a Row a little taller than a plain one, with a friend's 40 photo. */
@Composable
private fun MeRow(avatar: String?, username: String, realName: String, onClick: () -> Unit, focus: FocusRequester?) {
    InButton {
        val interaction = remember { MutableInteractionSource() }
        val pressed by interaction.collectIsPressedAsState()
        val fill by animateColorAsState(if (pressed) Ownify.fill(0.08f) else Ownify.InsetFill, tween(Ownify.FastMs, easing = Ownify.Ease), label = "me")
        Row(
            Modifier
                .press(interaction)
                .fillMaxWidth()
                .heightIn(min = 64.dp)
                .clip(RoundedCornerShape(Ownify.RadiusSm))
                .background(fill)
                .then(if (focus != null) Modifier.focusRequester(focus) else Modifier)
                .clickable(interaction, indication = null, role = Role.Button, onClick = blurring(onClick))
                .semantics(mergeDescendants = true) { contentDescription = trf("%s — Account openen", username) }
                .padding(horizontal = Ownify.Space3),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)
        ) {
            AvatarCircle(avatar, 40.dp, 18.dp)
            Column(Modifier.weight(1f)) {
                T(username, OwnifyType.style(Ownify.FsBody, FontWeight.SemiBold), maxLines = 1, ellipsis = true)
                if (realName.isNotEmpty()) T(realName, OwnifyType.style(Ownify.FsSubhead, color = Ownify.TextSecondary), maxLines = 1, ellipsis = true)
            }
            JIcon(OwnifyIcons.chevronRight, size = 16.dp, color = Ownify.TextMuted)
        }
    }
}

/**
 * Reads the picked picture and sends it as the website's form does: the
 * file as `avatar`. Nothing picked sends the empty field a browser sends,
 * and the server says what it says to that.
 */
internal suspend fun upload(context: Context, uri: Uri?): Outcome {
    val path = "api/profile/avatar.php"
    if (uri == null) {
        return OwnifyActions.upload(context, path, "avatar", "", "application/octet-stream", ByteArray(0), tr("Er ging iets mis."))
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
    return OwnifyActions.upload(context, path, "avatar", fileName(context, uri), mime, bytes, tr("Er ging iets mis."))
}

private fun fileName(context: Context, uri: Uri): String =
    runCatching {
        context.contentResolver.query(uri, arrayOf(OpenableColumns.DISPLAY_NAME), null, null, null)?.use { c ->
            if (c.moveToFirst()) c.getString(0) else null
        }
    }.getOrNull() ?: "foto"

/** The account's start as the website's panel prints it (i18n_date day_mon_year): "27 sep 2026", "Sep 27, 2026". */
internal fun memberSince(createdAt: String?): String {
    val date = createdAt?.take(10)?.let { runCatching { LocalDate.parse(it) }.getOrNull() } ?: return ""
    return OwnifyFormat.date(date, "day_mon_year")
}

/** `.account__avatar`: a circle with a hairline, the picture or the muted user icon. */
@Composable
fun AvatarCircle(path: String?, size: Dp, iconSize: Dp, modifier: Modifier = Modifier) {
    Box(
        modifier
            .size(size)
            .clip(CircleShape)
            .background(Ownify.fill(0.07f))
            .border(1.dp, Ownify.GlassHairline, CircleShape)
    ) {
        Avatar(path, iconSize = iconSize, iconColor = Ownify.TextMuted)
    }
}

/** The one field (25.0): a pill at least 44 high, the soft border, `.05`. */
private fun Modifier.fieldBox(fill: Color = Ownify.fill(0.05f)): Modifier {
    val shape = RoundedCornerShape(50)
    return this
        .heightIn(min = 44.dp)
        .clip(shape)
        .background(fill)
        .border(1.dp, Ownify.GlassBorderSoft, shape)
}

/**
 * `.account__file`: the picture field — a "Bestand kiezen" pill and the
 * chosen name, the same on the website (which hides the browser's own file
 * control behind it); here it opens Android's photo picker. The name may take
 * two lines before it is cut, so "Geen bestand gekozen" fits beside the pill.
 */
@Composable
private fun FileField(name: String?, onClick: () -> Unit, modifier: Modifier = Modifier) {
    InButton {
        val interaction = remember { MutableInteractionSource() }
        Row(
            modifier
                .fillMaxWidth()
                .fieldBox()
                .clickable(interaction, indication = null, role = Role.Button, onClickLabel = tr("Bestand kiezen"), onClick = blurring(onClick))
                .semantics(mergeDescendants = true) { contentDescription = tr("Profielfoto") + ": " + (name ?: tr("Geen bestand gekozen")) }
                .padding(horizontal = Ownify.Space4, vertical = Ownify.Space2),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)
        ) {
            val shape = RoundedCornerShape(50)
            ChromeText {
                T(
                    tr("Bestand kiezen"),
                    OwnifyType.style(Ownify.FsCaption, FontWeight.SemiBold, Ownify.TextPrimary),
                    Modifier
                        .clip(shape)
                        .background(Ownify.fill(0.08f))
                        .border(1.dp, Ownify.GlassBorderSoft, shape)
                        .cssPadding(PaddingValues(horizontal = Ownify.Space2, vertical = 3.dp), border = 1.dp),
                    maxLines = 1
                )
            }
            T(
                name ?: tr("Geen bestand gekozen"),
                OwnifyType.style(Ownify.FsSubhead, color = Ownify.TextSecondary),
                Modifier.weight(1f),
                maxLines = 2,
                ellipsis = true
            )
        }
    }
}

/** `.account__nav`: the text, a badge when requests wait, and a chevron; `.08` while pressed. */
@Composable
private fun NavField(text: String, value: String?, onClick: () -> Unit, label: String, notice: String? = null, modifier: Modifier = Modifier) {
    InButton {
        val interaction = remember { MutableInteractionSource() }
        val pressed by interaction.collectIsPressedAsState()
        val fill by animateColorAsState(
            if (pressed) Ownify.fill(0.08f) else Ownify.InsetFill,
            tween(Ownify.FastMs, easing = Ownify.Ease),
            label = "nav"
        )
        Row(
            modifier
                .press(interaction)
                .fillMaxWidth()
                .heightIn(min = Ownify.RowMin)
                // A Row (`.account__nav`): the faint fill, a touch more while pressed.
                .clip(RoundedCornerShape(Ownify.RadiusSm))
                .background(fill)
                .clickable(interaction, indication = null, role = Role.Button, onClick = blurring(onClick))
                .semantics(mergeDescendants = true) { contentDescription = "$label${value?.let { ": $it" }.orEmpty()}" }
                .padding(horizontal = Ownify.Space3),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)
        ) {
            // .account__nav-text, its red dot beside the word; the value at the end (.settings-row__value).
            Row(Modifier.weight(1f), verticalAlignment = Alignment.CenterVertically) {
                T(text, OwnifyType.style(Ownify.FsBody), if (notice != null) Modifier.notice(notice, NoticePlace.Beside) else Modifier, maxLines = 1, ellipsis = true)
            }
            if (value != null) T(value, OwnifyType.style(Ownify.FsSubhead, color = Ownify.TextSecondary), maxLines = 1)
            JIcon(OwnifyIcons.chevronRight, size = 16.dp, color = Ownify.TextMuted)
        }
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
            .background(Ownify.mix(Ownify.Health, 0.22f, Color.Transparent))
            .border(1.dp, Ownify.mix(Ownify.Health, 0.42f, Color.Transparent), shape)
            .cssPadding(PaddingValues(horizontal = Ownify.Space2), border = 1.dp),
        contentAlignment = Alignment.Center
    ) {
        T(text, OwnifyType.style(Ownify.FsCaption, FontWeight.SemiBold), maxLines = 1)
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
private fun ColumnScope.FriendsView(data: AppData, searchFirst: Boolean = false) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val focus = LocalFocusManager.current
    val community = data.community
    var searching by rememberSaveable { mutableStateOf(searchFirst) }
    var query by rememberSaveable { mutableStateOf("") }
    val searchField = remember { FocusRequester() }
    var searchError by remember { mutableStateOf<String?>(null) }
    var busySearch by remember { mutableStateOf(false) }
    var found by remember { mutableStateOf<Found?>(null) }
    val rows = remember { mutableStateMapOf<String, RowState>() }

    // Somebody may have asked, or answered, since the pages were read.
    LaunchedEffect(Unit) { OwnifyAppState.refresh(context) }

    /** A search result showing where the two now stand, as applyPerson() does. */
    fun applyFound(person: JSONObject?) {
        Found.parse(person)?.let { found = it }
    }

    fun act(key: String, personId: Int, action: String) {
        val inResult = key == RESULT
        rows[key] = (rows[key] ?: RowState()).copy(busy = true)
        scope.launch {
            val outcome = OwnifyActions.form(
                context, "api/friends/request.php",
                mapOf("user_id" to personId.toString(), "action" to action),
                tr("Er ging iets mis."), reload = false
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
                    OwnifyAppState.reload(context)
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
            searchError = tr("Vul een gebruikersnaam in.")
            searchField.focusSafely()
            return
        }
        busySearch = true
        scope.launch {
            val outcome = OwnifyActions.form(context, "api/friends/search.php", mapOf("username" to name), tr("Er ging iets mis."), reload = false)
            busySearch = false
            val person = (outcome as? Outcome.Done)?.body?.optJSONObject("person")?.let(Found::parse)
            if (person != null) {
                found = person
                rows.remove(RESULT)
            } else if (outcome != Outcome.SignedOut) {
                found = null
                searchError = (outcome as? Outcome.Refused)?.message ?: tr("Er ging iets mis.")
            }
        }
    }

    // ------------------------------------------------------ Vriend toevoegen
    // Opening the form puts the cursor in its field (friends.js).
    LaunchedEffect(searching) {
        if (searching) {
            withFrameNanos { }
            searchField.focusSafely()
        }
    }
    Btn(
        tr("Vriend toevoegen"),
        onClick = {
            searching = !searching
            if (!searching) {
                searchError = null
                found = null
            }
        },
        icon = OwnifyIcons.plus,
        modifier = Modifier.fillMaxWidth()
    )

    if (searching) {
        FieldLabel(tr("Gebruikersnaam"), Modifier.padding(top = Ownify.Space4))
        // The search is in the field (50.0, account-friends.php): its round arrow, and the keyboard's search key.
        run {
            JInput(
                query, {
                    query = it.take(30)
                    searchError = null
                },
                modifier = Modifier.fillMaxWidth().focusRequester(searchField),
                placeholder = tr("Gebruikersnaam"),
                label = tr("Gebruikersnaam"),
                keyboard = KeyboardOptions(
                    capitalization = KeyboardCapitalization.None, autoCorrectEnabled = false,
                    keyboardType = KeyboardType.Ascii, imeAction = ImeAction.Search
                ),
                actions = KeyboardActions(onSearch = { if (!busySearch) search() }),
                trailing = {
                    GlassRound(
                        OwnifyIcons.chevronRight, tr("Zoeken"), ::search,
                        Modifier.align(Alignment.CenterEnd).offset(x = 10.dp),
                        size = 32.dp, enabled = !busySearch, keepFocus = true
                    )
                }
            )
        }
        FieldHint(tr("Vul de volledige gebruikersnaam in."))
        searchError?.let { ErrorBox(it, Modifier.padding(top = Ownify.Space3)) }

        found?.let { result ->
            Box(
                Modifier
                    .fillMaxWidth()
                    .padding(top = Ownify.Space3)
                    .well()
                    .cssPadding(PaddingValues(Ownify.Space3), border = 1.dp)
                    .semantics { liveRegion = LiveRegionMode.Polite }
            ) {
                SearchResult(result, rows[RESULT] ?: RowState()) { action -> act(RESULT, result.person.id, action) }
            }
        }
    }

    // ------------------------------------------------------ Vriendverzoeken
    FieldLabel(tr("Vriendverzoeken"), Modifier.padding(top = Ownify.Space5))
    if (community.pending.isEmpty()) {
        T(tr("Geen nieuwe vriendverzoeken."), JStyle.Footnote)
    } else {
        Column(verticalArrangement = Arrangement.spacedBy(Ownify.Space3)) {
            community.pending.forEach { person ->
                val key = "in:${person.id}"
                val state = rows[key] ?: RowState()
                PersonRow(
                    person, state, status = tr("Wil vrienden met je worden"),
                    below = if (!state.done) ({ AnswerButtons(state.busy, { act(key, person.id, "accept") }, { act(key, person.id, "decline") }) }) else null,
                    notice = if (!state.done) "friend:" + person.id else null
                )
            }
        }
    }
    if (community.sent.isNotEmpty()) {
        FieldLabel(tr("Verstuurd"), Modifier.padding(top = Ownify.Space4))
        Column(verticalArrangement = Arrangement.spacedBy(Ownify.Space3)) {
            community.sent.forEach { person -> PersonRow(person, RowState(), status = tr("Verzoek verstuurd")) }
        }
    }

    // --------------------------------------------- Vriendverzoeken toestaan
    // D6 (50.0): the switch lives in Instellingen › Privacy › Community; this
    // row says how it stands and opens it (components/account-friends.php).
    val shell = LocalShell.current
    NavField(
        text = tr("Vriendverzoeken toestaan"),
        value = if (community.allowRequests) tr("Aan") else tr("Uit"),
        onClick = {
            shell.closeAll()
            shell.goTo("settings")
            shell.openDetail(Detail.SettingsPage("privacy"))
        },
        label = tr("Vriendverzoeken toestaan"),
        modifier = Modifier.padding(top = Ownify.Space5)
    )

    // --------------------------------------------------------------- Vrienden
    FieldLabel(tr("Vrienden") + " (${community.friends.size})", Modifier.padding(top = Ownify.Space5))
    if (community.friends.isEmpty()) {
        T(tr("Je hebt nog geen vrienden. Voeg iemand toe met zijn of haar gebruikersnaam."), JStyle.Footnote)
    } else {
        Column(verticalArrangement = Arrangement.spacedBy(Ownify.Space3)) {
            community.friends.forEach { person ->
                val key = "friend:${person.id}"
                val state = rows[key] ?: RowState()
                PersonRow(
                    person, state, status = null,
                    trailing = if (!state.confirming && !state.done) ({
                        LinkButton(tr("Verwijderen"), enabled = !state.busy) { rows[key] = state.copy(confirming = true) }
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
    below: (@Composable () -> Unit)? = null,
    /** The red dot's key on their picture: a request waiting for you (docs/NOTICES.md). */
    notice: String? = null
) {
    Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)) {
            Box(if (notice != null) Modifier.notice(notice, NoticePlace.Circle) else Modifier) { AvatarCircle(person.avatar, 40.dp, 18.dp) }
            Column(Modifier.weight(1f)) {
                T(person.username, OwnifyType.style(Ownify.FsBody, FontWeight.SemiBold), maxLines = 1, ellipsis = true)
                val line = state.message ?: status
                if (!line.isNullOrEmpty()) {
                    T(
                        line,
                        OwnifyType.style(Ownify.FsSubhead, color = if (state.error) Ownify.Attention else Ownify.TextMuted),
                        Modifier.padding(top = 2.dp).semantics { liveRegion = LiveRegionMode.Polite }
                    )
                }
            }
            trailing?.invoke()
        }
        if (below != null) {
            Box(Modifier.fillMaxWidth().padding(start = 40.dp + Ownify.Space3)) { below() }
        }
        full?.invoke()
    }
}

/** Weigeren | Accepteren under the name: the split button (`.friend__actions--wide.split`). */
@Composable
private fun AnswerButtons(busy: Boolean, onAccept: () -> Unit, onDecline: () -> Unit) {
    SplitRow(
        { Btn(tr("Weigeren"), onClick = onDecline, enabled = !busy, modifier = it) },
        { Btn(tr("Accepteren"), onClick = onAccept, enabled = !busy, modifier = it) }
    )
}

/** `.friend__confirm`: the one step between a tap and a friendship gone; the safe answer first. */
@Composable
private fun RemoveConfirm(name: String, busy: Boolean, onCancel: () -> Unit, onRemove: () -> Unit) {
    Column(
        Modifier
            .fillMaxWidth()
            .well()
            .cssPadding(PaddingValues(Ownify.Space3), border = 1.dp),
        verticalArrangement = Arrangement.spacedBy(Ownify.Space3)
    ) {
        T(trf("%s verwijderen uit je vrienden?", name), OwnifyType.style(Ownify.FsSubhead, color = Ownify.TextSecondary))
        SplitRow(
            { Btn(tr("Annuleren"), onClick = onCancel, enabled = !busy, modifier = it) },
            { Btn(tr("Verwijderen"), onClick = onRemove, enabled = !busy, modifier = it, look = BtnLook.Final) }
        )
    }
}

/** The search's one account, in the lists' shape, with what can be done about it now (applyPerson()). */
@Composable
private fun SearchResult(found: Found, state: RowState, onAction: (String) -> Unit) {
    // The pill says tr("Verzoek verstuurd"); the line above it would only repeat it.
    val status = if (found.relation == "outgoing") null else found.status
    val below: (@Composable () -> Unit)? = when {
        found.relation == "none" && found.canRequest -> ({
            Btn(tr("Vriendverzoek sturen"), onClick = { onAction("request") }, enabled = !state.busy, modifier = Modifier.fillMaxWidth())
        })
        found.relation == "outgoing" -> ({ SentMark() })
        found.relation == "incoming" -> ({ AnswerButtons(state.busy, { onAction("accept") }, { onAction("decline") }) })
        else -> null
    }
    PersonRow(found.person, state, status = status, below = below)
}

/** `.friend__done`: tr("Verzoek verstuurd") once it is — a state, not a button. */
@Composable
private fun SentMark() {
    val shape = RoundedCornerShape(50)
    Row(
        Modifier
            .fillMaxWidth()
            .heightIn(min = 42.dp)
            .clip(shape)
            .background(Ownify.mix(Ownify.Health, 0.18f, Color.Transparent))
            .border(1.dp, Ownify.mix(Ownify.Health, 0.42f, Color.Transparent), shape)
            .cssPadding(PaddingValues(horizontal = Ownify.Space4), border = 1.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Ownify.Space2, Alignment.CenterHorizontally)
    ) {
        JIcon(OwnifyIcons.check, size = 15.dp, color = Ownify.TextPrimary)
        T(tr("Verzoek verstuurd"), OwnifyType.style(Ownify.FsSubhead, FontWeight.SemiBold))
    }
}

/**
 * `.btn--link`: quiet — no border, no fill — with a full-height target,
 * pulled 8 past the right edge so its text ends where the values above end
 * ([pull]; not beside an evidence chip, `.research-evidence .btn--link`).
 */
@Composable
fun LinkButton(text: String, modifier: Modifier = Modifier, enabled: Boolean = true, pull: Boolean = true, onClick: () -> Unit) {
    InButton {
        val interaction = remember { MutableInteractionSource() }
        Box(
            modifier
                .layout { measurable, constraints ->
                    val past = if (pull) Ownify.Space2.roundToPx() else 0
                    val placeable = measurable.measure(constraints)
                    layout((placeable.width - past).coerceAtLeast(0), placeable.height) { placeable.place(0, 0) }
                }
                .press(interaction, enabled = enabled)
                .alpha(if (enabled) 1f else 0.45f)
                .heightIn(min = 44.dp)
                .clip(RoundedCornerShape(50))
                .clickable(interaction, indication = null, enabled = enabled, role = Role.Button, onClick = blurring(onClick))
                .padding(horizontal = Ownify.Space2),
            contentAlignment = Alignment.Center
        ) {
            T(text, OwnifyType.style(Ownify.FsCaption, FontWeight.SemiBold, Ownify.TextSecondary), maxLines = 1)
        }
    }
}
