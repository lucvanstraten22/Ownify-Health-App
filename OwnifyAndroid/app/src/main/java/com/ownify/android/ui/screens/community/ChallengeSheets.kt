package com.ownify.android.ui.screens.community

import com.ownify.android.ui.design.SplitRow
import com.ownify.android.ui.screens.goals.WizardFrame
import com.ownify.android.ui.screens.goals.WizardOption
import com.ownify.android.ui.screens.goals.WizardReview
import com.ownify.android.ui.screens.goals.StepHead
import com.ownify.android.ui.screens.goals.TypeCard
import com.ownify.android.ui.screens.goals.Dates
import com.ownify.android.ui.screens.goals.PeriodBounds
import com.ownify.android.ui.screens.goals.PeriodState
import com.ownify.android.ui.screens.goals.dateText
import com.ownify.android.ui.screens.goals.durationEnd
import com.ownify.android.ui.screens.goals.fold
import com.ownify.android.data.Challenges
import androidx.compose.runtime.Stable
import androidx.compose.ui.text.input.ImeAction
import java.time.LocalDate
import java.time.temporal.ChronoUnit
import com.ownify.android.ui.design.WheelField
import com.ownify.android.ui.design.WheelSpec
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import com.ownify.android.ui.design.GlassChip
import com.ownify.android.ui.screens.account.FieldLabel
import com.ownify.android.data.ChallengeMetric
import com.ownify.android.ui.design.glassPress
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
        review.facts.forEachIndexed { i, (label, value) -> Fact(label, i > 0) { T(value, OwnifyType.style(Ownify.FsSubhead, FontWeight.Normal), align = TextAlign.End) } }
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
        T(text, OwnifyType.style(Ownify.FsSubhead, color = Ownify.TextSecondary), Modifier.weight(1f))
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
    // The split button (50.0): the way back left, the way forward right.
    SplitRow(left, right)
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
                T(text, OwnifyType.style(Ownify.FsSubhead, color = if (error) Ownify.TextPrimary else Ownify.TextMuted), align = TextAlign.Center)
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
    val w = { key: String -> copy[key].orEmpty() }
    // An idea on Uitdagingen comes with its kind and metric chosen (challenges.js create(preset)).
    var kind by remember { mutableStateOf(overlay.kind?.takeIf { it == "together" || it == "versus" } ?: "together") }
    var metricKey by remember { mutableStateOf(overlay.metric?.takeIf { ch.metric(it) != null }) }
    var group by remember { mutableStateOf<String?>(null) }
    var find by remember { mutableStateOf("") }
    var touched by remember { mutableStateOf(false) }
    // 49.1: the goal wizard's Periode — Start → Einde on its calendar, or a looptijd; a week from today to begin with.
    val period = remember { ChallengePeriod(ch) }
    var target by remember { mutableStateOf(ch.metric(metricKey)?.let { suggestTarget(it, period) } ?: 0.0) }
    var name by remember { mutableStateOf("") }
    var query by remember { mutableStateOf("") }
    val invite = remember { mutableStateListOf<Int>() }
    var step by remember { mutableIntStateOf(1) }
    var direction by remember { mutableIntStateOf(1) }
    var busy by remember { mutableStateOf(false) }
    var made by remember { mutableStateOf<JSONObject?>(null) }
    val body = rememberScrollState()
    val entry = remember { androidx.compose.animation.core.Animatable(0f) }
    LaunchedEffect(step) { body.scrollTo(0) }

    made?.let { answer -> MadeSheet(overlay, data, answer); return }

    val metric = ch.metric(metricKey)
    fun amount(value: Double): String {
        val m = metric ?: return ""
        val text = if (m.decimals > 0) OwnifyFormat.numberTrim(value, 1) else OwnifyFormat.number(value)
        return String.format(java.util.Locale.ROOT, if (value == 1.0) m.one else m.many, text)
    }
    val suggested = when {
        metric == null -> ""
        kind == "versus" -> metric.versus
        else -> String.format(java.util.Locale.ROOT, period.namePattern(), String.format(java.util.Locale.ROOT, metric.many,
            if (metric.decimals > 0) OwnifyFormat.numberTrim(target, 1) else OwnifyFormat.number(target)))
    }
    val categoryOf = { key: String -> ch.categories.firstOrNull { it.first == key }?.second ?: key }
    val complete = when (step) { 1 -> metric != null; 3 -> (kind != "together" || target > 0) && period.end!! > period.start; else -> true }
    val moved = { if (!touched) metric?.let { target = suggestTarget(it, period) } }

    fun make() {
        busy = true
        scope.launch {
            val payload = JSONObject()
                .put("kind", kind).put("metric", metricKey).put("name", name.trim())
                .put("start_date", period.start.toString()).put("end_date", period.end.toString())
                .put("target_unit", if (data.i18n.units == "imperial") (if (metric?.unit == "l") "floz" else "mi") else (if (metric?.unit == "l") "l" else "km"))
                .put("invite", JSONArray(invite.toList()))
            if (kind == "together") payload.put("target", target)
            when (val outcome = OwnifyActions.json(context, "api/challenges/create.php", payload, tr("De uitdaging kon niet worden gemaakt."))) {
                is Outcome.Done -> made = outcome.body
                is Outcome.Refused -> { busy = false; OwnifyToast.show(outcome.message) }
                Outcome.SignedOut -> Unit
            }
        }
    }
    fun go(to: Int, dir: Int) { direction = dir; step = to }

    // 49.0: the goal wizard's frame and steps, for a group — 1 to 3 in the bars, Controleren and Uitnodigen after them.
    WizardFrame(
        overlay, w("title"), w("close"), w("back"),
        count = when (step) { 4 -> w("review_label"); 5 -> w("invite_label"); else -> trf("Stap %1\$d van %2\$d", step, 3) + " · " + w("step${step}_label") },
        lit = minOf(step, 3), steps = 3, scroll = body, stepKey = "step-$step",
        direction = direction, entry = entry, showFoot = true, error = null,
        cta = if (busy) w("making") else if (step == 5) w("make") else w("next"),
        ctaEnabled = complete && !busy, saving = busy,
        onClose = { if (!busy) shell.close(overlay) },
        onBack = { if (!busy) { if (period.calendar != null) period.calendar = null else if (step == 1) shell.close(overlay) else go(step - 1, -1) } },
        onForward = { if (complete) { if (step == 5) make() else go(step + 1, 1) } }
    ) {
        Column(Modifier.fillMaxWidth()) {
            when (step) {
                1 -> {
                    StepHead(w("step1_title"), w("step1_lede"))
                    // The goal wizard's step 1: the search field, the groups as bubbles, the list.
                    Box(Modifier.fillMaxWidth().padding(top = Ownify.Space4)) {
                        JInput(find, { find = it.take(40) }, placeholder = w("find"), label = w("find"),
                            keyboard = KeyboardOptions(autoCorrectEnabled = false, imeAction = ImeAction.Search))
                    }
                    FlowRow(Modifier.fillMaxWidth().padding(top = Ownify.Space3), horizontalArrangement = Arrangement.spacedBy(Ownify.Space2),
                        verticalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
                        ch.categories.forEach { (key, label) -> GlassChip(label, chosen = group == key, onClick = { group = if (group == key) null else key }) }
                    }
                    // Where they come from, said once here — not on every row (50.0).
                    FieldLabel(w("list_label") + " · " + w("auto"), Modifier.padding(top = Ownify.Space4))
                    val words = fold(find).split(Regex("\\s+")).filter { it.isNotEmpty() }
                    val shown = ch.metrics.filter { m ->
                        (group == null || m.category == group) && fold(m.label + " " + m.what + " " + categoryOf(m.category)).let { hay -> words.all { it in hay } }
                    }
                    if (shown.isEmpty()) T(w("no_metric"), JStyle.Footnote) else WizardList {
                        shown.forEach { m ->
                            WizardOption(m.label, categoryOf(m.category), chosen = metricKey == m.key, onClick = {
                                metricKey = m.key
                                if (!touched) target = suggestTarget(m, period)
                            }, accent = Accent.of(m.accent).color, icon = OwnifyIcons.named(m.icon))
                        }
                    }
                }
                2 -> {
                    StepHead(w("step2_title"), w("step2_lede"))
                    // 49.3: the name first, then Samen or Wedstrijd.
                    FieldLabel(w("name"), Modifier.padding(top = Ownify.Space4))
                    JInput(name, { name = it.take(60) }, placeholder = suggested, label = w("name"))
                    Column(Modifier.fillMaxWidth().padding(top = Ownify.Space4), verticalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
                        ch.kinds.forEach { k ->
                            val example = if (k.key == "versus") metric?.versus.orEmpty()
                                else String.format(java.util.Locale.ROOT, ch.preview["together"] ?: "%s", amount(metric?.let { suggestTarget(it, period) } ?: target))
                            TypeCard(k.label, k.hint, example, chosen = kind == k.key, enabled = true) { kind = k.key }
                        }
                    }
                }
                3 -> {
                    StepHead(w("step3_title"), w("step3_lede"))
                    val m = metric ?: return@Column
                    if (kind == "together") {
                        FieldLabel(w("target"), Modifier.padding(top = Ownify.Space4))
                        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
                            val unit = String.format(java.util.Locale.ROOT, m.many, "").trim()
                            val top = maxOf(m.step * 40, suggestTarget(m, period) * 4)
                            WheelField(
                                OwnifyFormat.number(target, m.decimals, grouping = false),
                                { v -> OwnifyFormat.parseNumber(v)?.let { target = if (m.decimals > 0) Math.round(it * 10) / 10.0 else Math.round(it).toDouble(); touched = true } },
                                WheelSpec(min = m.step, max = Math.round(top / m.step) * m.step, step = m.step, start = target, unit = unit),
                                Modifier.weight(1f), w("target")
                            )
                            T(unit, OwnifyType.style(Ownify.FsSubhead, color = Ownify.TextSecondary), maxLines = 1)
                        }
                    }
                    Box(Modifier.padding(top = if (kind == "together") Ownify.Space5 else Ownify.Space4)) {
                        Dates(period, w, ch.durations, period.bounds(), onChange = moved)
                    }
                }
                4 -> {
                    StepHead(w("review_title"), w("review_lede"))
                    val m = metric ?: return@Column
                    Box(Modifier.padding(top = Ownify.Space4)) {
                        WizardReview(buildList {
                            add(w("step1_label") to m.label)
                            add(w("kind_label") to ch.kinds.firstOrNull { it.key == kind }?.label.orEmpty())
                            add(w("name") to name.trim().ifEmpty { suggested })
                            if (kind == "together") add(w("target") to amount(target))
                            add(w("period") to dateText(period.start) + " – " + dateText(period.end!!))
                        })
                    }
                }
                else -> {
                    StepHead(w("invite_title"), w("friends_hint"))
                    if (ch.friends.isEmpty()) {
                        T(w("no_friends"), JStyle.Footnote, Modifier.padding(top = Ownify.Space4))
                    } else {
                        Box(Modifier.padding(top = Ownify.Space4)) {
                            JInput(query, { query = it.take(40) }, placeholder = w("search"), label = w("search"))
                        }
                        // A count is a label; the sentence for "nobody yet" is a hint, in sentence case (50.0).
                        if (invite.isEmpty()) T(w("none_picked"), JStyle.Footnote, Modifier.padding(top = Ownify.Space4, bottom = Ownify.Space2))
                        else FieldLabel(w("picked").replace("%d", invite.size.toString()), Modifier.padding(top = Ownify.Space4))
                        val shown = ch.friends.filter { query.isBlank() || it.name.contains(query.trim(), ignoreCase = true) }
                        if (shown.isEmpty()) T(w("no_match"), JStyle.Footnote) else WizardList {
                            shown.forEach { f ->
                                val id = f.id ?: return@forEach
                                val on = id in invite
                                WizardOption(f.name, ch.copy["invite"]?.get(if (on) "picked" else "pick").orEmpty(), chosen = on,
                                    onClick = { if (on) invite.remove(id) else invite.add(id) }, role = Role.Checkbox,
                                    mark = { ChallengeAvatar(f, 30.dp) })
                            }
                        }
                    }
                }
            }
        }
    }
}

/**
 * Nieuwe uitdaging's Periode (49.1): the goal wizard's [PeriodState], from
 * today to a week on to begin with. A start is today or later, within
 * `start_within` days; an end after it, at most `longest` days on.
 */
@Stable
internal class ChallengePeriod(private val ch: Challenges) : PeriodState {
    override var start by mutableStateOf(LocalDate.now())
    override var end by mutableStateOf<LocalDate?>(
        ch.durations.firstOrNull { it.key == "week" }?.let { durationEnd(LocalDate.now(), it) } ?: LocalDate.now().plusDays(6)
    )
    override var duration by mutableStateOf<String?>("week")
    override var calendar by mutableStateOf<String?>(null)

    fun bounds(today: LocalDate = LocalDate.now()) = PeriodBounds(
        today..today.plusDays(ch.startWithin.toLong()),
        { s -> s.plusDays(1)..s.plusDays(ch.longest - 1L) }
    )

    val days: Long get() = ChronoUnit.DAYS.between(start, end ?: start) + 1

    /** "Samen %s in een maand": a looptijd's words, else its days. */
    fun namePattern(): String {
        val key = duration ?: if (days == 14L) "2w" else null
        return key?.let { ch.namePatterns[it] } ?: (ch.namePatterns["days"] ?: "%s").replace("%d", days.toString())
    }
}

/** The target a Samen challenge suggests: `per_week` for each week of its dates, on the stepper. */
internal fun suggestTarget(m: ChallengeMetric, period: ChallengePeriod): Double {
    val raw = m.perWeek * period.days / 7.0
    return maxOf(m.step, Math.round(raw / m.step) * m.step)
}

/** `.goal-list`: the wizard's short list in the glass, scrolled on its own. */
@Composable
private fun WizardList(content: @Composable () -> Unit) {
    Column(
        Modifier
            .fillMaxWidth()
            .glass(RoundedCornerShape(Ownify.RadiusMd))
            .heightIn(max = 250.dp)
            .verticalScroll(rememberScrollState())
            .padding(4.dp)
    ) { content() }
}

/** `.ch-label`: a field's small caps. */
@Composable
private fun Label(text: String) {
    T(text, JStyle.Caption, Modifier.fillMaxWidth().padding(top = Ownify.Space5, bottom = Ownify.Space2), uppercase = true)
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
        SplitRow(
            { Btn(copy["copy"].orEmpty(), onClick = { copyCode(context, code) }, modifier = it, icon = OwnifyIcons.copy) },
            { Btn(copy["share"].orEmpty(), onClick = { shareCode(context, answer.optString("share_text"), code) }, modifier = it, icon = OwnifyIcons.share) },
            Modifier.padding(top = Ownify.Space4),
            quietBack = false
        )
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
                        // `.ch-friend__pick.is-on`: the chosen one is the chosen glass (the website's), not the solid way forward.
                        look = if (on) com.ownify.android.ui.design.BtnLook(glass = com.ownify.android.ui.design.GlassLook(chosen = true)) else com.ownify.android.ui.design.BtnLook(),
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
