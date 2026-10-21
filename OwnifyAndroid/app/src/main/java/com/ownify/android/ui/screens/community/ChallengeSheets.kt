package com.ownify.android.ui.screens.community
import com.ownify.android.ui.design.LocalGround
import com.ownify.android.ui.design.CardStyle

import com.ownify.android.ui.design.cardSurface
import androidx.compose.ui.platform.LocalGraphicsContext
import com.ownify.android.ui.design.hitArea
import com.ownify.android.ui.design.GlassLook
import com.ownify.android.ui.design.glass
import com.ownify.android.ui.design.well

import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.keyframes
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.focus.onFocusChanged
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalFocusManager
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.role
import androidx.compose.ui.semantics.selected
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.testTag
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.em
import androidx.compose.ui.unit.sp
import com.ownify.android.data.AppData
import com.ownify.android.data.ChallengePerson
import com.ownify.android.data.ChallengeReview
import com.ownify.android.data.OwnifyActions
import com.ownify.android.data.Outcome
import com.ownify.android.i18n.OwnifyFormat
import com.ownify.android.i18n.tr
import com.ownify.android.i18n.trf
import com.ownify.android.i18n.trn
import com.ownify.android.ui.app.Detail
import com.ownify.android.ui.app.LocalShell
import com.ownify.android.ui.app.Overlay
import com.ownify.android.ui.app.OwnifyToast
import com.ownify.android.ui.app.SheetFrame
import com.ownify.android.ui.app.SheetHead
import com.ownify.android.ui.design.Btn
import com.ownify.android.ui.design.IconTile
import com.ownify.android.ui.design.JIcon
import com.ownify.android.ui.design.JInput
import com.ownify.android.ui.design.JStyle
import com.ownify.android.ui.design.LocalReducedMotion
import com.ownify.android.ui.design.OwnifyIcons
import com.ownify.android.ui.design.RangeSwitch
import com.ownify.android.ui.design.T
import com.ownify.android.ui.design.cssPadding
import com.ownify.android.ui.design.press
import com.ownify.android.ui.design.rememberFocusOnOpen
import com.ownify.android.ui.theme.Accent
import com.ownify.android.ui.theme.Ownify
import com.ownify.android.ui.theme.OwnifyType
import kotlinx.coroutines.launch
import org.json.JSONArray
import org.json.JSONObject

/*
 * Uitdagingen's one bottom sheet, as components/challenge-sheet.php and
 * assets/js/challenges.js have it: Nieuwe uitdaging, Code invoeren, Bekijken,
 * Vrienden uitnodigen and Uitdaging verlaten. Each change goes to the same
 * endpoint the website posts to (api/challenges/), and the pages are read
 * again after it (OwnifyActions), so what shows is what the server has.
 */

/** Tags for tests. */
internal const val CODE_FIELD = "challenge-code"
internal const val CODE_MESSAGE = "challenge-code-message"
internal const val MADE_CODE = "challenge-made-code"

/** Opens a challenge's page once a sheet is done with: over Uitdagingen when that is in front, else over Community. */
private fun openChallenge(shell: com.ownify.android.ui.app.ShellState, id: Int) {
    val overList = shell.detail == Detail.Challenges && shell.detailShown
    shell.openDetail(Detail.Challenge(id, overList))
}

// ------------------------------------------------------------ Bekijken

/**
 * What a challenge is, before joining it: its icon and name, who invited
 * you or the code that found it, the facts, who is in it, what they would see
 * of you — and, when it is already on, that what you do from today counts.
 */
@Composable
private fun ReviewContent(review: ChallengeReview) {
    val accent = Accent.of(review.accent)
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)) {
        IconTile(
            OwnifyIcons.solid(review.icon) ?: OwnifyIcons.flag,
            color = Color.White,
            background = accent.color,
            border = accent.color
        )
        Column(Modifier.weight(1f)) {
            T(review.name, JStyle.Subtitle)
            if (review.by.isNotEmpty()) T(review.by, JStyle.Footnote)
        }
    }
    Column(Modifier.padding(top = Ownify.Space4)) {
        review.facts.forEachIndexed { i, (label, value) -> Fact(label, i > 0) { T(value, OwnifyType.style(Ownify.FsSmall, FontWeight.Medium), align = TextAlign.End) } }
        Fact(review.peopleLabel, divided = true) { FacesRow(review.faces) }
    }
    Note(OwnifyIcons.shield, review.privacy, Modifier.padding(top = Ownify.Space4))
    review.late?.let { Note(OwnifyIcons.calendar, it, Modifier.padding(top = Ownify.Space3)) }
}

@Composable
private fun Fact(label: String, divided: Boolean, value: @Composable () -> Unit) {
    Column(Modifier.fillMaxWidth()) {
        if (divided) Box(Modifier.fillMaxWidth().height(1.dp).background(Ownify.GlassHairline))
        Row(
            Modifier.fillMaxWidth().heightIn(min = 40.dp).padding(vertical = Ownify.Space2),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(Ownify.Space4)
        ) {
            T(label, JStyle.Meta, maxLines = 1)
            Box(Modifier.weight(1f), contentAlignment = Alignment.CenterEnd) { value() }
        }
    }
}

/** `.ch-privacy`: a framed line with its icon. */
@Composable
private fun Note(icon: androidx.compose.ui.graphics.vector.ImageVector, text: String, modifier: Modifier = Modifier) {
    Row(
        modifier
            .fillMaxWidth()
            .well()
            .padding(Ownify.Space3),
        horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)
    ) {
        JIcon(icon, Modifier.padding(top = 1.dp), size = 15.dp, color = Ownify.TextMuted)
        T(text, OwnifyType.style(Ownify.FsFootnote, color = Ownify.TextSecondary), Modifier.weight(1f))
    }
}

/** `.ch-done`: the green check, a title and one line. */
@Composable
private fun ColumnScope.Done(title: String, line: String) {
    Column(Modifier.fillMaxWidth().padding(top = Ownify.Space4), horizontalAlignment = Alignment.CenterHorizontally) {
        Box(
            Modifier.size(52.dp).clip(CircleShape).background(Ownify.HealthFill).border(1.dp, Ownify.HealthEdge, CircleShape),
            contentAlignment = Alignment.Center
        ) { JIcon(OwnifyIcons.check, size = 24.dp, color = Ownify.Health) }
        T(title, JStyle.Subtitle, Modifier.padding(top = Ownify.Space3).semantics { liveRegion = LiveRegionMode.Polite }, align = TextAlign.Center)
        T(line, JStyle.Lede, Modifier.padding(top = Ownify.Space2).widthIn(max = 320.dp), align = TextAlign.Center)
    }
}

@Composable
private fun TwoButtons(left: @Composable (Modifier) -> Unit, right: @Composable (Modifier) -> Unit) {
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
        left(Modifier.weight(1f))
        right(Modifier.weight(1f))
    }
}

/**
 * Bekijken and Meedoen, for an invitation ([invited]) or what a code found
 * ([code]): Meedoen joins — always the person's own tap — and the sheet says
 * so, with the way to the challenge; Afwijzen turns an invitation down.
 */
@Composable
private fun ReviewSheet(overlay: Overlay, data: AppData, review: ChallengeReview, code: String?) {
    val shell = LocalShell.current
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val copy = data.challenges.copy["review"]
    var joined by remember { mutableStateOf<Int?>(null) }
    var busy by remember { mutableStateOf(false) }
    val close = { shell.close(overlay) }

    if (joined != null) {
        SheetFrame(
            overlay,
            title = copy["joined_title"].orEmpty(),
            foot = {
                TwoButtons(
                    { Btn(copy["close"].orEmpty(), onClick = close, modifier = it) },
                    { Btn(copy["see"].orEmpty(), onClick = { close(); openChallenge(shell, joined!!) }, modifier = it, look = PrimaryLook) }
                )
            }
        ) {
            Done(copy["joined_title"].orEmpty(), review.joined)
        }
        return
    }

    val invited = code == null && review.invited
    SheetFrame(
        overlay,
        title = review.title,
        head = { SheetHead(review.title, close) },
        foot = {
            TwoButtons(
                {
                    if (invited) {
                        Btn(copy["decline"].orEmpty(), enabled = !busy, modifier = it, onClick = {
                            busy = true
                            scope.launch {
                                val body = JSONObject().put("challenge_id", review.id).put("action", "decline")
                                when (val outcome = OwnifyActions.json(context, "api/challenges/respond.php", body, tr("Er ging iets mis. Probeer het nog eens."))) {
                                    is Outcome.Done -> { OwnifyToast.show(outcome.body.optString("message")); close() }
                                    is Outcome.Refused -> { busy = false; OwnifyToast.show(outcome.message) }
                                    Outcome.SignedOut -> Unit
                                }
                            }
                        })
                    } else {
                        Btn(copy["later"].orEmpty(), onClick = close, modifier = it)
                    }
                },
                {
                    Btn(copy["join"].orEmpty(), enabled = !busy, modifier = it, look = PrimaryLook, onClick = {
                        busy = true
                        scope.launch {
                            val body = if (code != null) JSONObject().put("code", code) else JSONObject().put("challenge_id", review.id)
                            when (val outcome = OwnifyActions.json(context, "api/challenges/join.php", body, tr("Er ging iets mis. Probeer het nog eens."))) {
                                is Outcome.Done -> joined = outcome.body.optInt("id", review.id)
                                is Outcome.Refused -> { busy = false; OwnifyToast.show(outcome.message) }
                                Outcome.SignedOut -> Unit
                            }
                        }
                    })
                }
            )
        }
    ) {
        ReviewContent(review)
    }
}

/** An invitation of yours, from Community or Uitdagingen. */
@Composable
fun ChallengeReviewSheet(overlay: Overlay.ChallengeReview, data: AppData) {
    val shell = LocalShell.current
    val review = data.challenges.invite(overlay.id)?.review
    if (review == null) {
        // Gone meanwhile (accepted elsewhere, or over): nothing to look at.
        androidx.compose.runtime.LaunchedEffect(Unit) { shell.close(overlay) }
        return
    }
    ReviewSheet(overlay, data, review, code = null)
}

// ------------------------------------------------------- Code invoeren

/**
 * Code invoeren: five boxes over one field (the number keyboard). The fifth
 * digit looks the code up; what it found appears in the same sheet to
 * review, a challenge you are in opens, and a wrong code says so under the
 * boxes and shakes them.
 */
@Composable
fun ChallengeCodeSheet(overlay: Overlay.ChallengeCode, data: AppData) {
    val shell = LocalShell.current
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val copy = data.challenges.copy["code"]
    var code by remember { mutableStateOf("") }
    var message by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf(false) }
    var found by remember { mutableStateOf<Pair<ChallengeReview, String>?>(null) }
    val shake = remember { Animatable(0f) }
    val reduced = LocalReducedMotion.current
    val focus = rememberFocusOnOpen()
    val focusManager = LocalFocusManager.current

    found?.let { (review, typed) ->
        ReviewSheet(overlay, data, review, typed)
        return
    }

    fun lookUp(value: String) {
        message = copy["searching"]
        error = false
        scope.launch {
            val outcome = OwnifyActions.json(context, "api/challenges/lookup.php", JSONObject().put("code", value), tr("Er ging iets mis. Probeer het nog eens."), reload = false)
            when (outcome) {
                is Outcome.Done -> {
                    val body = outcome.body
                    if (body.optString("status") == "member") {
                        shell.close(overlay)
                        OwnifyToast.show(body.optString("message"))
                        openChallenge(shell, body.optInt("id"))
                    } else {
                        val review = ChallengeReview.parse(body.optJSONObject("review"))
                        if (review != null) {
                            focusManager.clearFocus()
                            found = review to value
                        }
                    }
                }
                is Outcome.Refused -> {
                    message = outcome.message
                    error = true
                    if (!reduced) {
                        shake.animateTo(0f, keyframes {
                            durationMillis = 320
                            -6f at 64
                            5f at 144
                            -3f at 224
                        })
                    }
                }
                Outcome.SignedOut -> Unit
            }
        }
    }

    SheetFrame(overlay, title = copy["title"].orEmpty(), head = { SheetHead(copy["title"].orEmpty()) { shell.close(overlay) } }) {
        T(copy["hint"].orEmpty(), JStyle.Lede, Modifier.fillMaxWidth(), align = TextAlign.Center)
        var focused by remember { mutableStateOf(false) }
        BasicTextField(
            value = code,
            onValueChange = { typed ->
                val digits = typed.filter(Char::isDigit).take(5)
                if (digits != code) {
                    code = digits
                    error = false
                    if (digits.length < 5) message = null
                    if (digits.length == 5) lookUp(digits)
                }
            },
            modifier = Modifier
                .fillMaxWidth()
                .padding(top = Ownify.Space5)
                .focusRequester(focus)
                .onFocusChanged { focused = it.isFocused }
                .semantics { contentDescription = copy["label"].orEmpty(); testTag = CODE_FIELD },
            singleLine = true,
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword),
            textStyle = OwnifyType.style(16.sp, color = Color.Transparent),
            cursorBrush = SolidColor(Color.Transparent),
            decorationBox = { inner ->
                Box(Modifier.fillMaxWidth(), contentAlignment = Alignment.Center) {
                    // The real field, invisible, under the boxes: it takes the keys and the paste.
                    Box(Modifier.width(1.dp)) { inner() }
                    Row(
                        Modifier.graphicsLayer { translationX = shake.value * density },
                        horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)
                    ) {
                        for (i in 0 until 5) {
                            val next = focused && i == minOf(code.length, 4)
                            val shape = RoundedCornerShape(Ownify.RadiusSm)
                            val size = if (androidx.compose.ui.platform.LocalConfiguration.current.screenWidthDp < 360) 44.dp else 52.dp
                            Box(
                                Modifier
                                    .size(width = size, height = size * 1.18f)
                                    .then(if (next && !error) Modifier.border(3.dp, Ownify.HealthWash, shape) else Modifier)
                                    .padding(if (next && !error) 2.dp else 0.dp)
                                    .clip(shape)
                                    .background(Ownify.fill(0.06f))
                                    .border(1.dp, if (error) Ownify.AttentionEdge else if (next) Ownify.HealthStrong else Ownify.GlassBorder, shape),
                                contentAlignment = Alignment.Center
                            ) {
                                T(
                                    code.getOrNull(i)?.toString().orEmpty(),
                                    OwnifyType.style((size.value * 0.5f).sp, FontWeight.SemiBold, tabular = true).copy(fontFamily = FontFamily.Monospace),
                                    maxLines = 1
                                )
                            }
                        }
                    }
                }
            }
        )
        Row(
            Modifier
                .fillMaxWidth()
                .heightIn(min = 40.dp)
                .padding(top = Ownify.Space3)
                .semantics { liveRegion = LiveRegionMode.Polite; testTag = CODE_MESSAGE },
            horizontalArrangement = Arrangement.Center,
            verticalAlignment = Alignment.Top
        ) {
            val text = message
            if (text != null) {
                if (error) Box(Modifier.padding(top = 6.dp, end = Ownify.Space2).size(6.dp).background(Ownify.Attention, CircleShape))
                T(text, OwnifyType.style(Ownify.FsSmall, color = if (error) Ownify.TextPrimary else Ownify.TextMuted), align = TextAlign.Center)
            }
        }
    }
}

// ---------------------------------------------------- Nieuwe uitdaging

/**
 * Nieuwe uitdaging: one sheet that previews the challenge as it is filled
 * in — kind, what it counts, the shared target for Samen, how long and from
 * when, a name (or the one it suggests), friends to invite — then shows its
 * code to copy or share, and the way to it.
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
fun ChallengeCreateSheet(overlay: Overlay.ChallengeCreate, data: AppData) {
    val shell = LocalShell.current
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val ch = data.challenges
    val copy = ch.copy["create"]
    // An idea on Uitdagingen comes with its kind and metric chosen (challenges.js create(preset)).
    var kind by remember { mutableStateOf(overlay.kind?.takeIf { it == "together" || it == "versus" } ?: "together") }
    var metricKey by remember { mutableStateOf(overlay.metric?.takeIf { ch.metric(it) != null }) }
    var touched by remember { mutableStateOf(false) }
    var length by remember { mutableStateOf("1w") }
    var target by remember { mutableStateOf(ch.metric(metricKey)?.let { it.targets[length] ?: it.step } ?: 0.0) }
    var from by remember { mutableStateOf("today") }
    var name by remember { mutableStateOf("") }
    val invite = remember { mutableStateListOf<Int>() }
    var busy by remember { mutableStateOf(false) }
    var made by remember { mutableStateOf<JSONObject?>(null) }
    val close = { shell.close(overlay) }

    made?.let { answer -> MadeSheet(overlay, data, answer); return }

    val metric = ch.metric(metricKey)
    fun amount(value: Double): String {
        val m = metric ?: return ""
        return String.format(java.util.Locale.ROOT, if (value == 1.0) m.one else m.many, OwnifyFormat.number(value))
    }
    val suggested = when {
        metric == null -> ""
        kind == "versus" -> metric.versus
        else -> String.format(java.util.Locale.ROOT, ch.length(length)?.namePattern ?: "%s", amount(target))
    }

    SheetFrame(
        overlay,
        title = copy["title"].orEmpty(),
        head = { SheetHead(copy["title"].orEmpty(), close) },
        foot = {
            Btn(
                if (metric == null) copy["pick_first"].orEmpty() else copy["make"].orEmpty(),
                enabled = metric != null && !busy,
                modifier = Modifier.fillMaxWidth(),
                look = PrimaryLook,
                onClick = {
                    busy = true
                    scope.launch {
                        val body = JSONObject()
                            .put("kind", kind)
                            .put("metric", metricKey)
                            .put("length", length)
                            .put("from", from)
                            .put("name", name.trim())
                            .put("target_unit", if (data.i18n.units == "imperial") "mi" else "km")
                            .put("invite", JSONArray(invite.toList()))
                        if (kind == "together") body.put("target", target)
                        when (val outcome = OwnifyActions.json(context, "api/challenges/create.php", body, tr("De uitdaging kon niet worden gemaakt."))) {
                            is Outcome.Done -> made = outcome.body
                            is Outcome.Refused -> { busy = false; OwnifyToast.show(outcome.message) }
                            Outcome.SignedOut -> Unit
                        }
                    }
                }
            )
        }
    ) {
        // The preview: what it will be, as it is filled in.
        val accent = Accent.of(metric?.accent ?: "health")
        val kindLabel = ch.kinds.firstOrNull { it.key == kind }?.label.orEmpty()
        val period = ch.length(length)?.periods?.get(from).orEmpty()
        val line = when {
            metric == null -> copy["preview_empty"].orEmpty()
            kind == "together" -> String.format(java.util.Locale.ROOT, ch.preview["together"] ?: "%s", amount(target))
            else -> String.format(java.util.Locale.ROOT, ch.preview["versus"] ?: "%s", metric.what)
        }
        val people = ch.friends.filter { it.id in invite }
        Column(
            Modifier
                .fillMaxWidth()
                .cardSurface(RoundedCornerShape(Ownify.RadiusMd), CardStyle.Default, LocalGround.current, LocalGraphicsContext.current.shadowContext)
                .padding(Ownify.Space4)
                .semantics(mergeDescendants = true) { liveRegion = LiveRegionMode.Polite },
            verticalArrangement = Arrangement.spacedBy(Ownify.Space2)
        ) {
            KindLine(metric?.icon ?: "flag", kindLabel + (metric?.let { " · " + it.label } ?: ""), accent)
            T(name.trim().ifEmpty { if (metric != null) suggested else copy["preview_name"].orEmpty() }, JStyle.Subtitle)
            T("$line · $period", JStyle.Lede)
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Row {
                    (listOfNotNull(ch.you) + people.take(4)).forEachIndexed { i, p ->
                        Box(Modifier.offset(x = (-5).dp * i)) { ChallengeAvatar(p, 30.dp, pending = !p.you, ring = true) }
                    }
                }
                T(
                    if (invite.isEmpty()) ch.preview["alone"].orEmpty() else trn(invite.size, "%d uitgenodigd", "%d uitgenodigd"),
                    JStyle.Footnote,
                    Modifier.weight(1f),
                    align = TextAlign.End
                )
            }
        }

        Label(copy["kind_label"].orEmpty())
        RangeSwitch(ch.kinds.map { it.key to it.label }, kind, { kind = it }, wide = true, label = copy["kind_label"])
        T(ch.kinds.firstOrNull { it.key == kind }?.hint.orEmpty(), JStyle.Footnote, Modifier.padding(top = Ownify.Space2))

        Label(copy["metric"].orEmpty())
        FlowRow(horizontalArrangement = Arrangement.spacedBy(Ownify.Space2), verticalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
            ch.metrics.forEach { m ->
                MetricChip(m.label, m.icon, Accent.of(m.accent), selected = metricKey == m.key) {
                    metricKey = m.key
                    if (!touched) target = m.targets[length] ?: m.step
                }
            }
        }

        if (kind == "together" && metric != null) {
            Label(copy["target"].orEmpty())
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)) {
                Btn("−", enabled = target > metric.step, contentDescription = copy["less"], onClick = { target = maxOf(metric.step, target - metric.step); touched = true }) {
                    T("−", OwnifyType.style(Ownify.FsHeadline, FontWeight.SemiBold))
                }
                Row(Modifier.weight(1f), horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.Bottom) {
                    T(OwnifyFormat.number(target), OwnifyType.style(Ownify.FsTitle2, FontWeight.SemiBold, tabular = true), Modifier.alignByBaseline(), maxLines = 1)
                    T(" " + String.format(java.util.Locale.ROOT, metric.many, "").trim(), OwnifyType.style(Ownify.FsBody, color = Ownify.TextSecondary), Modifier.alignByBaseline(), maxLines = 1)
                }
                Btn("+", contentDescription = copy["more"], onClick = { target += metric.step; touched = true }) {
                    T("+", OwnifyType.style(Ownify.FsHeadline, FontWeight.SemiBold))
                }
            }
        }

        Label(copy["length"].orEmpty())
        RangeSwitch(ch.lengths.map { it.key to it.label }, length, { l ->
            length = l
            val m = metric
            if (m != null && !touched) target = m.targets[l] ?: m.step
        }, wide = true, label = copy["length"])
        RangeSwitch(ch.from, from, { from = it }, Modifier.padding(top = Ownify.Space2), wide = true, label = copy["length"])

        Label(copy["name"].orEmpty())
        JInput(
            name,
            { name = it.take(60) },
            placeholder = if (metric != null) suggested else copy["name_example"].orEmpty(),
            label = copy["name"]
        )

        Label(copy["friends"].orEmpty())
        T(copy["friends_hint"].orEmpty(), JStyle.Footnote, Modifier.padding(bottom = Ownify.Space3))
        if (ch.friends.isEmpty()) {
            T(copy["no_friends"].orEmpty(), JStyle.Footnote)
        } else {
            FlowRow(Modifier.fillMaxWidth(), maxItemsInEachRow = 4) {
                ch.friends.forEach { friend ->
                    val id = friend.id ?: return@forEach
                    PickPerson(friend, selected = id in invite, Modifier.weight(1f)) {
                        if (id in invite) invite.remove(id) else invite.add(id)
                    }
                }
                // Keep a short last row in the grid's columns.
                repeat((4 - ch.friends.size % 4) % 4) { Box(Modifier.weight(1f)) }
            }
        }
    }
}

/** `.ch-label`: a field's small caps. */
@Composable
private fun Label(text: String) {
    T(text, JStyle.Caption, Modifier.fillMaxWidth().padding(top = Ownify.Space5, bottom = Ownify.Space2), uppercase = true)
}

/** `.ch-chip`: what to count, chosen in the Health colour. */
@Composable
private fun MetricChip(label: String, icon: String, accent: Accent, selected: Boolean, onClick: () -> Unit) {
    val interaction = remember { MutableInteractionSource() }
    val shape = RoundedCornerShape(50)
    Row(
        Modifier
            .hitArea(
                Modifier
                    .clickable(interaction, indication = null, role = Role.Button, onClick = onClick)
                    .semantics { this.selected = selected },
                across = false
            )
            .press(interaction)
            .glass(shape, GlassLook(chosen = selected, shadow = 7.dp, lift = 2.dp))
            .cssPadding(PaddingValues(horizontal = Ownify.Space3, vertical = Ownify.Space1), border = 1.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)
    ) {
        OwnifyIcons.named(icon)?.let { JIcon(it, size = 14.dp, color = accent.color) }
        T(label, OwnifyType.style(Ownify.FsTiny, if (selected) FontWeight.SemiBold else FontWeight.Medium, if (selected) Ownify.TextPrimary else Ownify.TextSecondary, tracking = 0.em), maxLines = 1)
    }
}

/** `.ch-pick__person`: a friend to invite — their circle, a check once chosen, their name. */
@Composable
private fun PickPerson(person: ChallengePerson, selected: Boolean, modifier: Modifier, onClick: () -> Unit) {
    val interaction = remember { MutableInteractionSource() }
    Column(
        modifier
            .press(interaction)
            .clickable(interaction, indication = null, role = Role.Checkbox, onClick = onClick)
            .semantics(mergeDescendants = true) { this.selected = selected }
            .padding(vertical = Ownify.Space2),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.spacedBy(6.dp)
    ) {
        Box {
            Box(Modifier.then(if (selected) Modifier.border(2.dp, Ownify.Health, CircleShape) else Modifier).padding(if (selected) 1.dp else 0.dp)) {
                ChallengeAvatar(person, if (selected) 42.dp else 44.dp)
            }
            if (selected) {
                Box(
                    Modifier
                        .align(Alignment.TopEnd)
                        .offset(x = 6.dp, y = (-2).dp)
                        .size(20.dp)
                        .border(2.dp, Ownify.ground(1f), CircleShape)
                        .clip(CircleShape)
                        .background(Ownify.Health),
                    contentAlignment = Alignment.Center
                ) { JIcon(OwnifyIcons.check, size = 11.dp, color = Color.White) }
            }
        }
        T(person.name, OwnifyType.style(Ownify.FsTiny, color = Ownify.TextSecondary), maxLines = 1, ellipsis = true)
    }
}

/** Made: the code to copy or share, and the way to the new challenge. */
@Composable
private fun MadeSheet(overlay: Overlay, data: AppData, answer: JSONObject) {
    val shell = LocalShell.current
    val context = LocalContext.current
    val copy = data.challenges.copy["create"]
    val code = answer.optString("code")
    val id = answer.optInt("id")
    val invited = answer.optInt("invited")

    SheetFrame(
        overlay,
        title = copy["made_title"].orEmpty(),
        foot = {
            Btn(copy["open"].orEmpty(), modifier = Modifier.fillMaxWidth(), look = PrimaryLook, onClick = {
                shell.close(overlay)
                openChallenge(shell, id)
            })
        }
    ) {
        Done(copy["made_title"].orEmpty(), answer.optString("name") + if (invited > 0) " · " + trn(invited, "%d uitgenodigd", "%d uitgenodigd") else "")
        Column(
            Modifier
                .fillMaxWidth()
                .padding(top = Ownify.Space4)
                .background(Ownify.InsetFill, RoundedCornerShape(Ownify.RadiusMd))
                .dashedBorder(Ownify.SlotEdge, Ownify.RadiusMd)
                .padding(Ownify.Space4),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.spacedBy(Ownify.Space3)
        ) {
            T(copy["made_code"].orEmpty(), JStyle.Caption, uppercase = true)
            Row(
                Modifier.clearAndSetSemantics { contentDescription = code.toCharArray().joinToString(" "); testTag = MADE_CODE },
                horizontalArrangement = Arrangement.spacedBy(6.dp)
            ) {
                code.forEach { digit ->
                    Box(
                        Modifier
                            .size(width = 38.dp, height = 45.dp)
                            .clip(RoundedCornerShape(Ownify.RadiusSm))
                            .background(Ownify.fill(0.07f))
                            .border(1.dp, Ownify.GlassHairline, RoundedCornerShape(Ownify.RadiusSm)),
                        contentAlignment = Alignment.Center
                    ) {
                        T(digit.toString(), OwnifyType.style(Ownify.FsLargeTitle, FontWeight.SemiBold, tabular = true).copy(fontFamily = FontFamily.Monospace), maxLines = 1)
                    }
                }
            }
            T(copy["made_hint"].orEmpty(), JStyle.Footnote, Modifier.widthIn(max = 280.dp), align = TextAlign.Center)
        }
        Row(Modifier.fillMaxWidth().padding(top = Ownify.Space4), horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
            Btn(copy["copy"].orEmpty(), onClick = { copyCode(context, code) }, modifier = Modifier.weight(1f), icon = OwnifyIcons.copy)
            Btn(copy["share"].orEmpty(), onClick = { shareCode(context, answer.optString("share_text"), code) }, modifier = Modifier.weight(1f), icon = OwnifyIcons.share)
        }
    }
}

// ------------------------------------------------- Vrienden uitnodigen

/** Vrienden uitnodigen, from a challenge you are in: your friends not in it yet. */
@Composable
fun ChallengeInviteSheet(overlay: Overlay.ChallengeInvite, data: AppData) {
    val shell = LocalShell.current
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val copy = data.challenges.copy["invite"]
    val friends = data.challenges.view(overlay.id)?.invitable.orEmpty()
    val chosen = remember { mutableStateListOf<Int>() }
    var busy by remember { mutableStateOf(false) }
    val close = { shell.close(overlay) }

    SheetFrame(
        overlay,
        title = copy["title"].orEmpty(),
        head = { SheetHead(copy["title"].orEmpty(), close) },
        foot = {
            TwoButtons(
                { Btn(copy["cancel"].orEmpty(), onClick = close, modifier = it) },
                {
                    Btn(
                        if (chosen.size > 1) trf("%d uitnodigen", chosen.size) else copy["send"].orEmpty(),
                        enabled = chosen.isNotEmpty() && !busy,
                        modifier = it,
                        look = PrimaryLook,
                        onClick = {
                            busy = true
                            scope.launch {
                                val body = JSONObject().put("challenge_id", overlay.id).put("invite", JSONArray(chosen.toList()))
                                when (val outcome = OwnifyActions.json(context, "api/challenges/invite.php", body, tr("Er ging iets mis. Probeer het nog eens."))) {
                                    is Outcome.Done -> { OwnifyToast.show(outcome.body.optString("message")); close() }
                                    is Outcome.Refused -> { busy = false; OwnifyToast.show(outcome.message) }
                                    Outcome.SignedOut -> Unit
                                }
                            }
                        }
                    )
                }
            )
        }
    ) {
        T(copy["body"].orEmpty(), JStyle.Lede)
        if (friends.isEmpty()) {
            T(copy["none"].orEmpty(), JStyle.Footnote, Modifier.padding(top = Ownify.Space3))
        }
        Column(Modifier.padding(top = Ownify.Space3)) {
            friends.forEachIndexed { i, friend ->
                val id = friend.id ?: return@forEachIndexed
                val on = id in chosen
                if (i > 0) Box(Modifier.fillMaxWidth().height(1.dp).background(Ownify.GlassHairline))
                Row(
                    Modifier.fillMaxWidth().heightIn(min = 56.dp),
                    verticalAlignment = Alignment.CenterVertically,
                    horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)
                ) {
                    ChallengeAvatar(friend, 36.dp)
                    T(friend.name, JStyle.Label, Modifier.weight(1f), maxLines = 1, ellipsis = true)
                    Btn(
                        if (on) copy["picked"].orEmpty() else copy["pick"].orEmpty(),
                        onClick = { if (on) chosen.remove(id) else chosen.add(id) },
                        icon = if (on) OwnifyIcons.check else null,
                        look = if (on) PrimaryLook else com.ownify.android.ui.design.BtnLook(),
                        contentDescription = friend.name + ": " + (if (on) copy["picked"] else copy["pick"]).orEmpty()
                    )
                }
            }
        }
    }
}

// -------------------------------------------------- Uitdaging verlaten

/** Uitdaging verlaten, after "are you sure?": out, and what you contributed with you. */
@Composable
fun ChallengeLeaveSheet(overlay: Overlay.ChallengeLeave, data: AppData) {
    val shell = LocalShell.current
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val copy = data.challenges.copy["detail"]
    var busy by remember { mutableStateOf(false) }
    val close = { shell.close(overlay) }

    SheetFrame(
        overlay,
        title = copy["leave_title"].orEmpty(),
        head = { SheetHead(copy["leave_title"].orEmpty(), close) },
        foot = {
            TwoButtons(
                { Btn(copy["cancel"].orEmpty(), onClick = close, modifier = it) },
                {
                    Btn(copy["leave_confirm"].orEmpty(), enabled = !busy, modifier = it, look = DangerLook, onClick = {
                        busy = true
                        scope.launch {
                            val body = JSONObject().put("challenge_id", overlay.id).put("action", "leave")
                            when (val outcome = OwnifyActions.json(context, "api/challenges/respond.php", body, tr("Er ging iets mis. Probeer het nog eens."))) {
                                is Outcome.Done -> {
                                    OwnifyToast.show(outcome.body.optString("message"))
                                    close()
                                    shell.closeDetail()
                                }
                                is Outcome.Refused -> { busy = false; OwnifyToast.show(outcome.message) }
                                Outcome.SignedOut -> Unit
                            }
                        }
                    })
                }
            )
        }
    ) {
        T(copy["leave_body"].orEmpty(), JStyle.Lede)
    }
}
