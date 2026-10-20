package com.ownify.android.ui.screens.goals

import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.AnimationVector1D
import androidx.compose.animation.core.tween
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
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.Stable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.key
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.layout.layout
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalFocusManager
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.disabled
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.role
import androidx.compose.ui.semantics.selected
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.em
import com.ownify.android.data.AppData
import com.ownify.android.data.GoalMeasurement
import com.ownify.android.data.GoalSpan
import com.ownify.android.data.GoalUnit
import com.ownify.android.data.Goals
import com.ownify.android.data.OwnifyActions
import com.ownify.android.data.Outcome
import com.ownify.android.i18n.OwnifyFormat
import com.ownify.android.i18n.OwnifyLanguage
import com.ownify.android.i18n.OwnifyPreferences
import com.ownify.android.i18n.tr
import com.ownify.android.i18n.trf
import com.ownify.android.i18n.trn
import com.ownify.android.ui.app.LocalShell
import com.ownify.android.ui.app.Overlay
import com.ownify.android.ui.app.OverlayFrame
import com.ownify.android.ui.app.RoundButton
import com.ownify.android.ui.app.panelGlass
import com.ownify.android.ui.design.GlassChip
import com.ownify.android.ui.design.GlassCta
import com.ownify.android.ui.design.GlassLook
import com.ownify.android.ui.design.GlassRound
import com.ownify.android.ui.design.GlassSelect
import com.ownify.android.ui.design.JIcon
import com.ownify.android.ui.design.JInput
import com.ownify.android.ui.design.JStyle
import com.ownify.android.ui.design.LocalReducedMotion
import com.ownify.android.ui.design.LocalStillMotion
import com.ownify.android.ui.design.OwnifyIcons
import com.ownify.android.ui.design.T
import com.ownify.android.ui.design.blurring
import com.ownify.android.ui.design.cssPadding
import com.ownify.android.ui.design.glass
import com.ownify.android.ui.design.hitArea
import com.ownify.android.ui.design.press
import com.ownify.android.ui.design.reduceFadeIn
import com.ownify.android.ui.screens.account.FieldLabel
import com.ownify.android.ui.theme.Accent
import com.ownify.android.ui.theme.InButton
import com.ownify.android.ui.theme.Ownify
import com.ownify.android.ui.theme.OwnifyType
import java.text.Normalizer
import java.time.LocalDate
import java.time.temporal.ChronoUnit
import java.util.UUID
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

private const val STEPS = 3
private const val REVIEW = 4

/**
 * What the wizard has been told (goal-wizard.js `draft`). `null` is "not
 * said yet". Going back keeps all of it; a new measurement starts step
 * three again from its own defaults.
 */
@Stable
internal class Draft {
    val token: String = UUID.randomUUID().toString()
    var measurement by mutableStateOf<String?>(null)
    var category by mutableStateOf<String?>(null)
    var query by mutableStateOf("")
    var name by mutableStateOf("")
    var type by mutableStateOf<String?>(null)
    var variant by mutableStateOf<String?>(null)
    var unit by mutableStateOf<String?>(null)
    var customUnit by mutableStateOf("")
    var target by mutableStateOf("")
    var direction by mutableStateOf<String?>(null)
    var frequency by mutableStateOf("")
    var period by mutableStateOf("week")
    var baselineState by mutableStateOf("idle")   // idle | loading | measured | none
    var baselineValue by mutableStateOf<Double?>(null)
    var baselineOn by mutableStateOf<String?>(null)
    var baselineFor by mutableStateOf<String?>(null)
    var baselineTyped by mutableStateOf("")
    var start by mutableStateOf(LocalDate.now())
    var end by mutableStateOf<LocalDate?>(null)
    var duration by mutableStateOf<String?>(null)
    var calendar by mutableStateOf<String?>(null)   // "start" | "end" while it is open

    fun resetSetup() {
        variant = null; unit = null; customUnit = ""; target = ""; direction = null
        frequency = ""; period = "week"; baselineState = "idle"; baselineValue = null
        baselineOn = null; baselineFor = null; baselineTyped = ""
    }
}

/**
 * Nieuw doel (components/goal-wizard.php, goal-wizard.js): three steps —
 * Meting, Doel, Instellen — and a review without a number. Each screen ends
 * in the same pair: a small round back button and the one way forward.
 * The server checks everything again (includes/goal-create.php) and works out
 * what is stored; the arithmetic here — an end date, a figure in another
 * unit — is the same as the server's, so the review says what the goal will be.
 */
@Composable
fun GoalWizard(overlay: Overlay, data: AppData) {
    val shell = LocalShell.current
    val goals = data.goals
    val words = goals.wizard
    val context = LocalContext.current
    val focus = LocalFocusManager.current
    val scope = rememberCoroutineScope()
    val draft = remember { Draft() }
    var step by remember { mutableIntStateOf(1) }
    var direction by remember { mutableIntStateOf(1) }
    var saving by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val body = rememberScrollState()
    val entry = remember { Animatable(0f) }

    fun close() {
        if (!saving) shell.close(overlay)
    }

    fun go(to: Int, dir: Int) {
        focus.clearFocus()
        error = null
        direction = dir
        // Step three's defaults are set on the way in, never while it draws.
        if (to == STEPS) settle(draft, goals)
        step = to
    }

    LaunchedEffect(step, draft.calendar) { body.scrollTo(0) }

    fun finish() {
        if (saving) return
        saving = true
        error = null
        scope.launch {
            when (val outcome = OwnifyActions.form(context, "api/goals/create.php", payload(draft, goals), tr("Dit doel kon niet worden opgeslagen."))) {
                is Outcome.Done -> {
                    // The board on Actief, the new card in view and marked.
                    GoalBoard.view = "active"
                    GoalBoard.reveal = outcome.body.opt("goal_id")?.toString()
                    saving = false
                    shell.close(overlay)
                    return@launch
                }
                is Outcome.Refused -> error = outcome.message
                Outcome.SignedOut -> Unit
            }
            saving = false
        }
    }

    fun back() {
        when {
            saving -> Unit
            draft.calendar != null -> draft.calendar = null
            step == 1 -> close()
            else -> go(if (step == REVIEW) STEPS else step - 1, -1)
        }
    }

    fun forward() {
        if (!complete(step, draft, goals)) return
        if (step == REVIEW) finish() else go(if (step == STEPS) REVIEW else step + 1, 1)
    }

    OverlayFrame(overlay, title = words["title"], maxWidth = 416.dp, onDismiss = ::close) { panelModifier ->
        Box(panelModifier.panelGlass().cssPadding(border = 1.dp)) {
            Column(Modifier.padding(Ownify.Space5)) {
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)) {
                    T(words["title"], JStyle.Eyebrow, Modifier.weight(1f).semantics { heading() })
                    RoundButton(OwnifyIcons.chevronDown, words["close"], ::close)
                }

                // Steps 1 to 3; the review fills all three and says so, without a number.
                Column(Modifier.fillMaxWidth().padding(top = Ownify.Space4)) {
                    Row(Modifier.fillMaxWidth().clearAndSetSemantics { }, horizontalArrangement = Arrangement.spacedBy(Ownify.Space1)) {
                        for (n in 1..STEPS) {
                            val lit by animateColorAsState(
                                if (n <= minOf(step, STEPS)) Ownify.Health else Ownify.fill(0.10f),
                                tween(Ownify.SlowMs, easing = Ownify.EaseOut),
                                label = "bar"
                            )
                            Box(Modifier.weight(1f).height(3.dp).clip(RoundedCornerShape(50)).background(lit))
                        }
                    }
                    T(
                        if (step == REVIEW) words.review.label
                        else trf("Stap %1\$d van %2\$d", step, STEPS) + " · " + words.steps.getOrNull(step - 1)?.label.orEmpty(),
                        JStyle.Tiny,
                        Modifier.padding(top = Ownify.Space2).semantics { liveRegion = LiveRegionMode.Polite }
                    )
                }

                // `.goal-wizard .wizard__body`: 10 dp of room each side (the panel's
                // padding, borrowed) and 14 below, so the glass's shadows and the
                // chips' 48 dp touch areas are never cut off where it scrolls.
                Box(
                    Modifier
                        .padding(top = Ownify.Space4)
                        .weight(1f, fill = false)
                        .heightIn(min = 272.dp)
                        .bleed(BodyBleed)
                        .verticalScroll(body)
                        .padding(start = BodyBleed, end = BodyBleed, top = 2.dp, bottom = 14.dp)
                ) {
                    key(if (draft.calendar != null) "calendar-" + draft.calendar else "step-$step") {
                        StepIn(direction, entry) {
                            if (draft.calendar != null) {
                                CalendarStep(draft, goals)
                            } else {
                                when (step) {
                                    1 -> MeasureStep(draft, goals)
                                    2 -> DefinitionStep(draft, goals)
                                    3 -> SetupStep(draft, goals)
                                    else -> ReviewStep(draft, goals)
                                }
                            }
                        }
                    }
                }

                if (draft.calendar == null) {
                    Column(Modifier.fillMaxWidth().padding(top = Ownify.Space4)) {
                        Box(Modifier.fillMaxWidth().height(1.dp).background(Ownify.GlassHairline))
                        error?.let {
                            T(
                                it,
                                OwnifyType.style(Ownify.FsSmall, color = Ownify.Error, lineHeight = 1.4.em),
                                Modifier.fillMaxWidth().padding(top = Ownify.Space3).semantics { liveRegion = LiveRegionMode.Assertive },
                                align = TextAlign.Center
                            )
                        }
                        Row(
                            Modifier.fillMaxWidth().padding(top = Ownify.Space4),
                            verticalAlignment = Alignment.CenterVertically,
                            horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)
                        ) {
                            GlassRound(OwnifyIcons.chevronLeft, words["back"], ::back, enabled = !saving)
                            GlassCta(
                                if (saving) words["creating"] else if (step == REVIEW) words["create"] else words["next"],
                                onClick = ::forward,
                                modifier = Modifier.weight(1f),
                                enabled = complete(step, draft, goals) && !saving,
                                loading = saving
                            )
                        }
                    }
                }
            }
        }
    }
}

/** The room the body borrows from the panel's padding on each side (`margin-inline: -10px`). */
private val BodyBleed = 10.dp

/** Wider than its parent by [x] on each side, as a negative CSS margin makes a box. */
private fun Modifier.bleed(x: Dp): Modifier = layout { measurable, constraints ->
    val px = x.roundToPx()
    val wider = constraints.copy(
        minWidth = constraints.minWidth + 2 * px,
        maxWidth = if (constraints.hasBoundedWidth) constraints.maxWidth + 2 * px else constraints.maxWidth
    )
    val placeable = measurable.measure(wider)
    layout((placeable.width - 2 * px).coerceAtLeast(0), placeable.height) { placeable.place(-px, 0) }
}

/** Where a step's arrival begins: where the one before it is, or the start when that one had arrived. */
internal fun stepFrom(now: Float, still: Boolean): Float = if (still) 1f else if (now >= 1f) 0f else now.coerceAtLeast(0f)

/**
 * `wizard-in`: a step arrives from 14 dp to the side it came from, and fades
 * in — interruptible through the shared [entry]: a step that comes while the
 * previous one is on its way picks up from where that one is. Reduced
 * motion: it only fades.
 */
@Composable
internal fun StepIn(
    direction: Int,
    entry: Animatable<Float, AnimationVector1D>? = null,
    content: @Composable ColumnScope.() -> Unit
) {
    val still = LocalStillMotion.current
    if (entry == null) {
        val progress = remember { Animatable(if (still) 1f else 0f) }
        LaunchedEffect(Unit) { progress.animateTo(1f, tween(Ownify.ScreenMs, easing = Ownify.ScreenEase)) }
        Column(
            Modifier.fillMaxWidth().graphicsLayer {
                alpha = progress.value
                translationX = (1f - progress.value) * 14.dp.toPx() * direction
            },
            content = content
        )
        return
    }
    val reduced = LocalReducedMotion.current
    val from = remember { stepFrom(entry.value, still) }
    var started by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) {
        entry.snapTo(from)
        started = true
        if (reduced) entry.reduceFadeIn() else entry.animateTo(1f, tween(Ownify.ScreenMs, easing = Ownify.ScreenEase))
    }
    Column(
        Modifier.fillMaxWidth().graphicsLayer {
            val p = if (started) entry.value else from
            alpha = p
            translationX = if (reduced) 0f else (1f - p) * 14.dp.toPx() * direction
        },
        content = content
    )
}

@Composable
private fun StepHead(title: String, lede: String) {
    T(title, JStyle.Subtitle, Modifier.semantics { heading() })
    T(lede, JStyle.Meta, Modifier.padding(top = Ownify.Space1))
}

// =================================================================== 1 · meting

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun MeasureStep(draft: Draft, goals: Goals) {
    val words = goals.wizard
    val step = words.steps.getOrNull(0)
    StepHead(step?.title.orEmpty(), step?.lede.orEmpty())

    // The search field: a lens, the words, and a cross once there are some.
    Box(Modifier.fillMaxWidth().padding(top = Ownify.Space4)) {
        JInput(
            draft.query, { draft.query = it.take(40) },
            placeholder = words["search_label"],
            label = words["search_label"],
            radius = 22.dp,
            keyboard = KeyboardOptions(autoCorrectEnabled = false, imeAction = ImeAction.Search)
        )
        if (draft.query.isNotEmpty()) {
            Box(Modifier.align(Alignment.CenterEnd).padding(end = 6.dp)) {
                GlassRound(OwnifyIcons.close, words["search_clear"], { draft.query = "" }, size = 32.dp)
            }
        }
    }

    // The six categories: a bubble each, at the size of Health Connect's data chips.
    FlowRow(
        Modifier.fillMaxWidth().padding(top = Ownify.Space3).semantics { contentDescription = words["categories"] },
        horizontalArrangement = Arrangement.spacedBy(Ownify.Space2),
        verticalArrangement = Arrangement.spacedBy(Ownify.Space2)
    ) {
        goals.categories.forEach { c ->
            GlassChip(c.label, chosen = draft.category == c.key, onClick = {
                draft.category = if (draft.category == c.key) null else c.key
            })
        }
    }

    val (label, shown) = listed(draft, goals)
    FieldLabel(label, Modifier.padding(top = Ownify.Space4))

    // A short list, scrolled on its own: about five rows in view.
    val listScroll = rememberScrollState()
    Column(
        Modifier
            .fillMaxWidth()
            .glass(RoundedCornerShape(Ownify.RadiusMd))
            .heightIn(max = 250.dp)
            .verticalScroll(listScroll)
            .padding(4.dp)
            .semantics { contentDescription = words["results"] }
    ) {
        shown.forEachIndexed { i, m ->
            if (i > 0 && draft.measurement != m.code && draft.measurement != shown[i - 1].code) {
                Box(Modifier.fillMaxWidth().padding(horizontal = Ownify.Space2).height(1.dp).background(Ownify.GlassHairline))
            } else if (i > 0) {
                Box(Modifier.height(1.dp))
            }
            MeasureOption(m, goals, draft)
        }
    }

    if (shown.none { !it.custom } && draft.query.isNotBlank()) {
        T(words["no_results"], JStyle.Footnote, Modifier.padding(top = Ownify.Space2))
    }
    if (draft.measurement == "custom" && draft.category == null) {
        T(
            words["custom_category"],
            OwnifyType.style(Ownify.FsFootnote, color = Ownify.Attention),
            Modifier.padding(top = Ownify.Space2).semantics { liveRegion = LiveRegionMode.Polite }
        )
    }
}

/** The list's label and its measurements: the popular ones, a category's, or what the search finds — and Eigen gewoonte. */
private fun listed(draft: Draft, goals: Goals): Pair<String, List<GoalMeasurement>> {
    val words = goals.wizard
    val query = draft.query.trim()
    val shown = if (query.isEmpty() && draft.category == null) {
        goals.measurements.filter { it.popular != null }.sortedBy { it.popular }
    } else {
        goals.measurements.filter { (draft.category == null || it.category == draft.category) && matches(it, query, goals) }
    }
    val label = when {
        query.isEmpty() && draft.category == null -> words["popular"]
        query.isEmpty() -> goals.category(draft.category)?.label.orEmpty()
        else -> words["results"]
    }
    val customFits = query.isEmpty() || shown.isEmpty() || matches(goals.custom, query, goals) || draft.measurement == "custom"
    return label to (if (customFits) shown + goals.custom else shown)
}

/** Lower case, no accents: "Slaapefficiëntie" is found by "efficientie". */
internal fun fold(text: String): String =
    Normalizer.normalize(text.lowercase(), Normalizer.Form.NFD).replace(Regex("\\p{Mn}+"), "")

internal fun matches(m: GoalMeasurement, query: String, goals: Goals): Boolean {
    if (query.isBlank()) return true
    val haystack = fold((listOf(m.label, goals.category(m.category)?.label.orEmpty()) + m.keywords).joinToString(" "))
    return fold(query).split(Regex("\\s+")).filter { it.isNotEmpty() }.all { it in haystack }
}

@Composable
private fun MeasureOption(m: GoalMeasurement, goals: Goals, draft: Draft) {
    val words = goals.wizard
    val chosen = draft.measurement == m.code
    val category = if (m.custom) goals.category(draft.category) else goals.category(m.category)
    val accent = Accent.of(category?.accent ?: "health").color
    val interaction = remember { MutableInteractionSource() }
    val shape = RoundedCornerShape(Ownify.RadiusSm)
    InButton {
        Row(
            Modifier
                .fillMaxWidth()
                .heightIn(min = 46.dp)
                .clip(shape)
                .background(if (chosen) Ownify.HealthFill else Color.Transparent)
                .border(1.dp, if (chosen) Ownify.HealthEdge else Color.Transparent, shape)
                .clickable(interaction, indication = null, role = Role.RadioButton, onClick = blurring { choose(m, draft, goals) })
                .semantics(mergeDescendants = true) { selected = chosen }
                .padding(start = Ownify.Space2, end = Ownify.Space3, top = Ownify.Space1, bottom = Ownify.Space1),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)
        ) {
            Box(
                Modifier.size(28.dp).clip(RoundedCornerShape(9.dp)).background(Ownify.mix(accent, 0.16f, Color.Transparent)),
                contentAlignment = Alignment.Center
            ) {
                (if (m.custom) OwnifyIcons.sparkle else OwnifyIcons.named(category?.icon))?.let { JIcon(it, size = 15.dp, color = accent) }
            }
            Column(Modifier.weight(1f)) {
                T(m.label, OwnifyType.style(Ownify.FsSmall, FontWeight.SemiBold), maxLines = 1, ellipsis = true)
                T(
                    if (m.custom) m.hint else category?.label.orEmpty() + " · " + (if (isAuto(m, goals)) words["auto"] else words["manual"]),
                    OwnifyType.style(Ownify.FsTiny, color = Ownify.TextMuted),
                    maxLines = 1,
                    ellipsis = true
                )
            }
        }
    }
}

/** Choosing highlights it — nothing more; a new measurement starts step three again. */
private fun choose(m: GoalMeasurement, draft: Draft, goals: Goals) {
    if (draft.measurement != m.code) {
        draft.measurement = m.code
        draft.resetSetup()
        if (draft.type != null && draft.type !in m.types) draft.type = null
        if (m.types.size == 1) draft.type = m.types.first()
    }
}

/** Read from this person's data (it has some) — or kept by hand on the goal's page. */
internal fun isAuto(m: GoalMeasurement?, goals: Goals): Boolean =
    m != null && !m.custom && m.sourceKind != "manual" && m.code in goals.userAuto

private fun current(draft: Draft, goals: Goals): GoalMeasurement? = goals.measurement(draft.measurement)

// ===================================================================== 2 · doel

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun DefinitionStep(draft: Draft, goals: Goals) {
    val words = goals.wizard
    val step = words.steps.getOrNull(1)
    val m = current(draft, goals) ?: return
    StepHead(step?.title.orEmpty(), step?.lede.orEmpty())

    FieldLabel(words["name_label"], Modifier.padding(top = Ownify.Space4))
    JInput(
        draft.name, { draft.name = it.take(120) },
        placeholder = m.names.firstOrNull().orEmpty(),
        label = words["name_label"],
        keyboard = KeyboardOptions(capitalization = KeyboardCapitalization.Sentences, autoCorrectEnabled = false, imeAction = ImeAction.Done)
    )
    if (m.names.isNotEmpty()) {
        FlowRow(
            Modifier.fillMaxWidth().padding(top = Ownify.Space3).semantics { contentDescription = words["name_ideas"] },
            horizontalArrangement = Arrangement.spacedBy(Ownify.Space2),
            verticalArrangement = Arrangement.spacedBy(Ownify.Space2)
        ) {
            m.names.forEach { idea -> GlassChip(idea, chosen = false, onClick = { draft.name = idea }) }
        }
    }

    FieldLabel(words["type_label"], Modifier.padding(top = Ownify.Space5))
    Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
        goals.types.forEach { type ->
            val allowed = type.key in m.types
            val example = if (allowed) words["example"].replace("%s", m.examples[type.key] ?: type.example)
            else words["type_unavailable"].replace("%s", if (m.custom) m.label else m.label.lowercase(OwnifyFormat.locale()))
            TypeCard(type.label, type.hint, example, chosen = draft.type == type.key, enabled = allowed) {
                if (draft.type != type.key) {
                    draft.type = type.key
                    draft.variant = null
                    draft.direction = null
                }
            }
        }
    }
}

/** `.goal-type`: a glass card — its name, what it is, an example for this measurement. */
@Composable
private fun TypeCard(label: String, hint: String, example: String, chosen: Boolean, enabled: Boolean, onClick: () -> Unit) {
    InButton {
        val interaction = remember { MutableInteractionSource() }
        Column(
            Modifier
                .fillMaxWidth()
                .press(interaction, enabled = enabled)
                .alpha(if (enabled) 1f else 0.55f)
                .glass(RoundedCornerShape(Ownify.RadiusMd), GlassLook(chosen = chosen))
                .clickable(interaction, indication = null, enabled = enabled, role = Role.RadioButton, onClick = blurring(onClick))
                .semantics(mergeDescendants = true) {
                    selected = chosen
                    if (!enabled) disabled()
                }
                .padding(horizontal = Ownify.Space4, vertical = Ownify.Space3),
            verticalArrangement = Arrangement.spacedBy(2.dp)
        ) {
            T(label, OwnifyType.style(Ownify.FsLabel, FontWeight.SemiBold))
            T(hint, OwnifyType.style(Ownify.FsFootnote, color = Ownify.TextSecondary))
            T(example, OwnifyType.style(Ownify.FsFootnote, color = if (chosen) Ownify.TextSecondary else Ownify.TextMuted))
        }
    }
}

// ================================================================ 3 · instellen

/** Step three's defaults, once a measurement and a type are known (goal-wizard.js settle()). */
private fun settle(draft: Draft, goals: Goals) {
    val m = current(draft, goals) ?: return
    if (draft.type == "streak" && draft.variant == null) draft.variant = m.variants.firstOrNull()
    if (draft.type != "streak") draft.variant = null
    if (draft.unit == null || (draft.unit != "custom" && draft.unit !in m.unitCodes)) draft.unit = defaultUnit(m, goals)
    if (draft.direction == null) draft.direction = if (m.direction == "both") m.defaultDirection else m.direction
    if (draft.type == "accumulate" || draft.variant == "period") draft.direction = "increase"
    if (draft.end == null && draft.duration == null) {
        draft.duration = "month"
        draft.end = durationEnd(draft.start, goals.durations.first { it.key == "month" })
    }
}

/** The unit a measurement starts in: Imperiaal's own where it has one (goal_default_unit()). */
internal fun defaultUnit(m: GoalMeasurement, goals: Goals): String? {
    if (goals.unitsSystem == "imperial" && m.imperialUnit != null && m.imperialUnit in m.unitCodes) return m.imperialUnit
    return m.unitCodes.firstOrNull() ?: m.unitCode
}

private fun needsDirection(draft: Draft, goals: Goals): Boolean {
    val m = current(draft, goals) ?: return false
    return m.direction == "both" && (draft.type == "milestone" || draft.variant == "daily")
}

internal fun unitOf(code: String?, draft: Draft, goals: Goals): GoalUnit =
    if (code == "custom") GoalUnit.custom(draft.customUnit) else goals.units[code] ?: GoalUnit(code, "", "", null, 1.0, 2, false)

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun SetupStep(draft: Draft, goals: Goals) {
    val words = goals.wizard
    val step = words.steps.getOrNull(2)
    val m = current(draft, goals) ?: return
    val context = LocalContext.current
    StepHead(step?.title.orEmpty(), step?.lede.orEmpty())

    Column(Modifier.fillMaxWidth().padding(top = Ownify.Space4), verticalArrangement = Arrangement.spacedBy(Ownify.Space4)) {
        if (draft.type == "streak" && m.variants.size > 1) {
            Column {
                FieldLabel(words["variant_label"])
                FlowRow(horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
                    m.variants.forEach { key ->
                        GlassChip(goals.variants[key]?.label.orEmpty(), chosen = draft.variant == key, radio = true, onClick = {
                            if (draft.variant != key) {
                                draft.variant = key
                                draft.direction = null
                                settle(draft, goals)
                            }
                        })
                    }
                }
                T(goals.variants[draft.variant]?.hint.orEmpty(), JStyle.Footnote, Modifier.padding(top = Ownify.Space2))
            }
        }

        if (draft.variant == "period") {
            PeriodRow(draft, goals, m)
        } else {
            val which = when (draft.type) {
                "milestone" -> "milestone"
                "accumulate" -> "accumulate"
                else -> "daily"
            }
            TargetRow(draft, goals, m, words.target(which))
        }

        if (needsDirection(draft, goals)) DirectionRow(draft, goals)

        if (draft.type == "milestone") {
            LaunchedEffect(draft.measurement, draft.start) { loadBaseline(context, draft, goals) }
            Baseline(draft, goals)
        }

        problem(draft, goals)?.takeIf { it.isNotEmpty() }?.let {
            T(
                it,
                OwnifyType.style(Ownify.FsSmall, color = Ownify.Error, lineHeight = 1.4.em),
                Modifier.fillMaxWidth().semantics { liveRegion = LiveRegionMode.Assertive },
                align = TextAlign.Center
            )
        }

        Dates(draft, goals)
    }
}

/** A number on the left, its unit on the right — Doelwaarde, Per dag, Totaal. */
@Composable
private fun TargetRow(draft: Draft, goals: Goals, m: GoalMeasurement, label: String) {
    val words = goals.wizard
    Column {
        FieldLabel(label)
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
            JInput(
                draft.target, { draft.target = it.take(16) },
                modifier = Modifier.weight(1f),
                placeholder = placeholder(draft, goals, m),
                label = label,
                keyboard = KeyboardOptions(keyboardType = KeyboardType.Decimal, imeAction = ImeAction.Done)
            )
            val options = m.unitCodes.map { it to unitOf(it, draft, goals).label } + (if (m.custom) listOf("custom" to words["unit_custom"]) else emptyList())
            GlassSelect(options, draft.unit, { changeUnit(draft, goals, it) }, words["unit_label"])
        }
        if (draft.unit == "custom") {
            JInput(
                draft.customUnit, { draft.customUnit = it.take(20) },
                modifier = Modifier.padding(top = Ownify.Space2),
                placeholder = words["unit_custom_placeholder"],
                label = words["unit_custom_label"],
                keyboard = KeyboardOptions(autoCorrectEnabled = false, imeAction = ImeAction.Done)
            )
        }
    }
}

/** Another unit: what was typed keeps its meaning — 80 kg is 176,4 lb. */
private fun changeUnit(draft: Draft, goals: Goals, code: String) {
    val before = unitOf(draft.unit, draft, goals)
    draft.unit = code
    val after = unitOf(code, draft, goals)
    if (code == "custom" || before.code == null) return
    fun convert(text: String): String {
        val n = goalNumber(text) ?: return text
        return OwnifyFormat.numberTrim(roundTo(n * before.factor / after.factor, after.decimals), after.decimals)
    }
    draft.target = convert(draft.target)
    draft.baselineTyped = convert(draft.baselineTyped)
}

/** The placeholder in the unit on screen: 75 kg is 165 lb. */
private fun placeholder(draft: Draft, goals: Goals, m: GoalMeasurement): String {
    val key = if (draft.variant == "period") "period" else draft.type ?: return ""
    val base = m.placeholder[key] ?: return ""
    if (draft.variant == "period" || draft.unit == "custom") return OwnifyFormat.numberTrim(base, 2)
    val first = unitOf(m.unitCodes.firstOrNull() ?: draft.unit, draft, goals)
    val unit = unitOf(draft.unit, draft, goals)
    return OwnifyFormat.numberTrim(roundTo(base * first.factor / unit.factor, unit.decimals), unit.decimals)
}

/** Per periode: "[3] trainingen per [week ▾]". */
@Composable
private fun PeriodRow(draft: Draft, goals: Goals, m: GoalMeasurement) {
    val words = goals.wizard
    Column {
        FieldLabel(words.target("period"))
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
            JInput(
                draft.frequency, { draft.frequency = it.filter(Char::isDigit).take(3) },
                modifier = Modifier.width(80.dp),
                placeholder = placeholder(draft, goals, m),
                label = words.target("period"),
                keyboard = KeyboardOptions(keyboardType = KeyboardType.Number, imeAction = ImeAction.Done)
            )
            T(m.count ?: tr("keer"), OwnifyType.style(Ownify.FsSmall, FontWeight.Medium, Ownify.TextSecondary), maxLines = 1)
            T(words["per"], OwnifyType.style(Ownify.FsSmall, color = Ownify.TextMuted), maxLines = 1)
            GlassSelect(goals.periods.map { it.key to it.label }, draft.period, { draft.period = it }, words["period_label"])
        }
    }
}

/**
 * Which way is better: two round glass buttons, up and down. A long press
 * says what each means — also TalkBack's "explain" action — and chooses
 * nothing; no line of text stays on screen.
 */
@Composable
private fun DirectionRow(draft: Draft, goals: Goals) {
    val words = goals.wizard
    val daily = draft.type != "milestone"
    var tip by remember { mutableStateOf<String?>(null) }
    LaunchedEffect(tip) {
        if (tip != null) {
            delay(2400)
            tip = null
        }
    }
    Column {
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)) {
            T(words["direction_label"], JStyle.Caption, Modifier.weight(1f), uppercase = true)
            Row(horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
                listOf("increase" to OwnifyIcons.arrowUp, "decrease" to OwnifyIcons.arrowDown).forEach { (key, icon) ->
                    val (label, hint) = words.direction(key, daily)
                    GlassRound(
                        icon, label,
                        onClick = { draft.direction = key },
                        chosen = draft.direction == key,
                        longPressLabel = hint,
                        onLongPress = { tip = hint }
                    )
                }
            }
        }
        tip?.let {
            T(
                it,
                OwnifyType.style(Ownify.FsSmall),
                Modifier
                    .fillMaxWidth()
                    .padding(top = Ownify.Space2)
                    .clip(RoundedCornerShape(Ownify.RadiusSm))
                    .background(Ownify.TipSurface)
                    .padding(horizontal = Ownify.Space3, vertical = Ownify.Space2)
                    .semantics { liveRegion = LiveRegionMode.Polite }
            )
        }
    }
}

/** A Mijlpaal's start: measured (and fixed), or typed when there is nothing to start from. */
private suspend fun loadBaseline(context: android.content.Context, draft: Draft, goals: Goals) {
    val m = current(draft, goals) ?: return
    val key = m.code + "|" + draft.start
    if (!isAuto(m, goals)) {
        draft.baselineState = "none"
        draft.baselineFor = key
        return
    }
    if (draft.baselineFor == key && draft.baselineState != "idle") return
    draft.baselineFor = key
    draft.baselineState = "loading"
    val outcome = OwnifyActions.form(
        context, "api/goals/baseline.php",
        mapOf("measurement" to m.code, "start_date" to draft.start.toString()),
        tr("Startwaarde ophalen…"), reload = false
    )
    if (draft.baselineFor != key) return
    val found = (outcome as? Outcome.Done)?.body?.optJSONObject("baseline")
    if (found != null) {
        draft.baselineValue = found.optDouble("value")
        draft.baselineOn = found.optString("label")
        draft.baselineState = "measured"
    } else {
        draft.baselineState = "none"
    }
}

@Composable
private fun Baseline(draft: Draft, goals: Goals) {
    val words = goals.wizard
    val unit = unitOf(draft.unit, draft, goals)
    when (draft.baselineState) {
        "measured" -> Column {
            Row(Modifier.fillMaxWidth().semantics(mergeDescendants = true) { }, verticalAlignment = Alignment.CenterVertically) {
                T(words["baseline_label"], JStyle.Caption, Modifier.weight(1f), uppercase = true)
                T(valueText(draft.baselineValue ?: 0.0, unit), OwnifyType.style(Ownify.FsLabel, FontWeight.SemiBold, tabular = true))
            }
            T(words["baseline_measured"].replace("%s", draft.baselineOn.orEmpty()), JStyle.Footnote, Modifier.padding(top = Ownify.Space1))
        }
        "loading", "idle" -> T(words["baseline_loading"], JStyle.Footnote)
        else -> Column {
            FieldLabel(words["baseline_label"])
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
                JInput(
                    draft.baselineTyped, { draft.baselineTyped = it.take(16) },
                    modifier = Modifier.weight(1f),
                    label = words["baseline_label"],
                    keyboard = KeyboardOptions(keyboardType = KeyboardType.Decimal, imeAction = ImeAction.Done)
                )
                T(unit.label, OwnifyType.style(Ownify.FsSmall, FontWeight.Medium, Ownify.TextSecondary))
            }
            T(words["baseline_manual"], JStyle.Footnote, Modifier.padding(top = Ownify.Space2))
        }
    }
}

/** The period: start and end, and the five looptijden under them. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun Dates(draft: Draft, goals: Goals) {
    val words = goals.wizard
    Column {
        FieldLabel(words["dates_label"])
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
            DateButton(words["start"], dateText(draft.start), Modifier.weight(1f)) { draft.calendar = "start" }
            JIcon(OwnifyIcons.chevronRight, size = 14.dp, color = Ownify.TextFaint)
            DateButton(words["end"], draft.end?.let(::dateText) ?: words["end_choose"], Modifier.weight(1f), empty = draft.end == null) { draft.calendar = "end" }
        }
        FlowRow(
            Modifier.fillMaxWidth().padding(top = Ownify.Space3).semantics { contentDescription = words["durations_label"] },
            horizontalArrangement = Arrangement.spacedBy(Ownify.Space2),
            verticalArrangement = Arrangement.spacedBy(Ownify.Space2)
        ) {
            goals.durations.forEach { d ->
                GlassChip(d.label, chosen = draft.duration == d.key, onClick = {
                    draft.duration = d.key
                    draft.end = durationEnd(draft.start, d)
                })
            }
        }
    }
}

@Composable
private fun DateButton(label: String, value: String, modifier: Modifier, empty: Boolean = false, onClick: () -> Unit) {
    InButton {
        val interaction = remember { MutableInteractionSource() }
        Column(
            modifier
                .press(interaction)
                .heightIn(min = 50.dp)
                .glass(RoundedCornerShape(Ownify.RadiusSm))
                .clickable(interaction, indication = null, role = Role.Button, onClick = blurring(onClick))
                .semantics(mergeDescendants = true) { contentDescription = "$label: $value" }
                .padding(horizontal = Ownify.Space3, vertical = Ownify.Space2)
        ) {
            T(label, JStyle.Caption, uppercase = true)
            T(
                value,
                OwnifyType.style(Ownify.FsLabel, if (empty) FontWeight.Medium else FontWeight.SemiBold, if (empty) Ownify.TextSecondary else Ownify.TextPrimary, tabular = true),
                maxLines = 1
            )
        }
    }
}

// =================================================================== calendar

/**
 * The calendar over the step: the start first, then — after Gereed — the end
 * (goal-wizard.js openCalendar()). A start is today or up to a year back; an
 * end after the start, from today, up to three years on.
 */
@Composable
private fun CalendarStep(draft: Draft, goals: Goals) {
    val words = goals.wizard
    val mode = draft.calendar ?: return
    val today = LocalDate.now()
    val selected = if (mode == "start") draft.start else draft.end ?: draft.start.plusDays(1)
    var month by remember(mode) { mutableStateOf(selected.withDayOfMonth(1)) }
    val min = if (mode == "start") today.minusDays(365) else maxOf(draft.start.plusDays(1), today)
    val max = if (mode == "start") today else draft.start.plusMonths(36)

    T(if (mode == "start") words["pick_start"] else words["pick_end"], JStyle.Subtitle, Modifier.semantics { heading() })
    Row(Modifier.fillMaxWidth().padding(top = Ownify.Space3), verticalAlignment = Alignment.CenterVertically) {
        GlassRound(OwnifyIcons.chevronLeft, words["calendar_prev"], { month = month.minusMonths(1) }, size = 34.dp, enabled = month.minusDays(1) >= min)
        T(
            OwnifyFormat.date(month, "month_year").replaceFirstChar { it.uppercase() },
            OwnifyType.style(Ownify.FsLabel, FontWeight.SemiBold),
            Modifier.weight(1f).semantics { liveRegion = LiveRegionMode.Polite },
            align = TextAlign.Center
        )
        GlassRound(OwnifyIcons.chevronRight, words["calendar_next"], { month = month.plusMonths(1) }, size = 34.dp, enabled = month.plusMonths(1) <= max)
    }
    Row(Modifier.fillMaxWidth().padding(top = Ownify.Space3).clearAndSetSemantics { }) {
        OwnifyPreferences.weekInitials().forEach {
            T(it, OwnifyType.style(Ownify.FsTiny, color = Ownify.TextMuted, tracking = Ownify.TrackingWide), Modifier.weight(1f), align = TextAlign.Center)
        }
    }
    val first = OwnifyPreferences.weekFirstDay(month)
    val last = month.plusMonths(1).minusDays(1)
    Column(Modifier.fillMaxWidth().padding(top = Ownify.Space2)) {
        var day = first
        while (day <= last) {
            Row(Modifier.fillMaxWidth()) {
                repeat(7) {
                    val d = day
                    if (d.month != month.month) Box(Modifier.weight(1f).height(42.dp))
                    else DayCell(d, draft, mode, enabled = d in min..max, today = today, modifier = Modifier.weight(1f)) { pick(draft, goals, d) }
                    day = day.plusDays(1)
                }
            }
        }
    }
    GlassCta(words["calendar_done"], onClick = {
        if (mode == "start") draft.calendar = "end" else draft.calendar = null
    }, modifier = Modifier.fillMaxWidth().padding(top = Ownify.Space4))
}

@Composable
private fun DayCell(d: LocalDate, draft: Draft, mode: String, enabled: Boolean, today: LocalDate, modifier: Modifier, onClick: () -> Unit) {
    val isStart = d == draft.start
    val isEnd = d == draft.end
    val chosen = if (mode == "start") isStart else isEnd
    val between = draft.end != null && d > draft.start && d < draft.end
    val interaction = remember { MutableInteractionSource() }
    Box(
        modifier
            .height(42.dp)
            .background(if (between) Ownify.HealthWash else Color.Transparent)
            .clickable(interaction, indication = null, enabled = enabled, role = Role.Button, onClick = onClick)
            .semantics {
                contentDescription = OwnifyFormat.date(d, "weekday_day_month") + " " + d.year
                selected = chosen
            },
        contentAlignment = Alignment.Center
    ) {
        Box(
            Modifier
                .size(40.dp)
                .clip(CircleShape)
                .background(if (chosen) Ownify.HealthEdge else if (isStart || isEnd) Ownify.HealthFill else Color.Transparent)
                .border(if (chosen) 1.5.dp else 1.dp, if (chosen) Ownify.Health else if (isStart || isEnd) Ownify.HealthStrong else Color.Transparent, CircleShape),
            contentAlignment = Alignment.Center
        ) {
            T(
                d.dayOfMonth.toString(),
                OwnifyType.style(Ownify.FsSmall, if (chosen || isStart || isEnd) FontWeight.SemiBold else FontWeight.Normal,
                    if (enabled) Ownify.TextPrimary else Ownify.TextFaint, tabular = true),
                Modifier.alpha(if (enabled) 1f else 0.5f)
            )
            if (d == today) Box(Modifier.align(Alignment.BottomCenter).padding(bottom = 5.dp).size(4.dp).clip(CircleShape).background(Ownify.Health))
        }
    }
}

private fun pick(draft: Draft, goals: Goals, d: LocalDate) {
    if (draft.calendar == "start") {
        draft.start = d
        val span = goals.durations.firstOrNull { it.key == draft.duration }
        if (span != null) draft.end = durationEnd(d, span)
        else if (draft.end != null && draft.end!! <= d) draft.end = null
    } else {
        draft.end = d
        draft.duration = goals.durations.firstOrNull { durationEnd(draft.start, it) == d }?.key
    }
}

// ===================================================================== review

@Composable
private fun ReviewStep(draft: Draft, goals: Goals) {
    val words = goals.wizard
    val m = current(draft, goals) ?: return
    val category = goals.category(if (m.custom) draft.category else m.category)
    StepHead(words.review.title, words.review.lede)

    val rows = buildList {
        add(words.summary("name") to draft.name.trim())
        add(words.summary("measure") to m.label + " · " + category?.label.orEmpty())
        add(words.summary("type") to (goals.types.firstOrNull { it.key == draft.type }?.label.orEmpty() +
            (draft.variant?.let { " · " + goals.variants[it]?.label.orEmpty() } ?: "")))
        add(words.summary("target") to targetSummary(draft, goals))
        if (needsDirection(draft, goals)) add(words.summary("direction") to words.direction(draft.direction ?: "increase", draft.type != "milestone").first)
        if (draft.type == "milestone") {
            val unit = unitOf(draft.unit, draft, goals)
            add(words.summary("baseline") to if (draft.baselineState == "measured") valueText(draft.baselineValue ?: 0.0, unit) + " · " + draft.baselineOn.orEmpty() else typedText(draft.baselineTyped, unit))
        }
        add(words.summary("dates") to dateText(draft.start) + " – " + (draft.end?.let(::dateText) ?: "—"))
        add(words.summary("tracking") to if (isAuto(m, goals)) words["tracking_auto"] else words["tracking_manual"])
    }

    Column(
        Modifier
            .fillMaxWidth()
            .padding(top = Ownify.Space4)
            .glass(RoundedCornerShape(Ownify.RadiusMd))
            .padding(horizontal = Ownify.Space4, vertical = Ownify.Space1)
    ) {
        rows.forEachIndexed { i, (term, value) ->
            if (i > 0) Box(Modifier.fillMaxWidth().height(1.dp).background(Ownify.GlassHairline))
            Row(
                Modifier.fillMaxWidth().padding(vertical = Ownify.Space3).semantics(mergeDescendants = true) { },
                horizontalArrangement = Arrangement.spacedBy(Ownify.Space4)
            ) {
                T(term, JStyle.Caption, Modifier.alignByBaseline(), uppercase = true)
                T(value, OwnifyType.style(Ownify.FsSmall, FontWeight.SemiBold), Modifier.weight(1f).alignByBaseline(), align = TextAlign.End)
            }
        }
    }
}

private fun targetSummary(draft: Draft, goals: Goals): String {
    val m = current(draft, goals) ?: return "—"
    val words = goals.wizard
    if (draft.variant == "period") {
        return draft.frequency + " " + (m.count ?: tr("keer")) + " " + words["per"] + " " + (goals.periods.firstOrNull { it.key == draft.period }?.label.orEmpty())
    }
    val text = typedText(draft.target, unitOf(draft.unit, draft, goals))
    if (draft.variant == "daily") {
        return if (isAuto(m, goals)) (if (draft.direction == "decrease") trf("Elke dag hoogstens %s", text) else trf("Elke dag minstens %s", text))
        else trf("Elke dag %s", text)
    }
    return text
}

// ================================================================== checking

/** What is in the way of step three — "" for "not yet", a sentence for a real reason, null for nothing. */
internal fun problem(draft: Draft, goals: Goals): String? {
    val m = current(draft, goals) ?: return ""
    if (draft.unit == "custom" && draft.variant != "period") {
        val word = draft.customUnit.trim()
        if (word.isEmpty() || word.length > 20) return ""
    }
    if (draft.variant == "period") {
        val n = draft.frequency.trim().toIntOrNull() ?: return ""
        val most = if (m.sourceKind == "manual" || m.custom) mapOf("week" to 7, "month" to 28, "quarter" to 89, "year" to 365)[draft.period] ?: 7 else 999
        if (n < 1) return ""
        if (n > most) return trf("Vul een heel aantal keer in, van 1 tot %d.", most)
        val span = goals.periods.firstOrNull { it.key == draft.period }
        val end = draft.end
        if (span != null && end != null && periodAsks(draft.start, end, span, n) < 1) {
            return tr("Deze periode is te kort voor dit doel. Kies een latere einddatum of een kortere periode.")
        }
    } else {
        if (!positive(draft.target)) return ""
        if (draft.unit == "pct" && (goalNumber(draft.target) ?: 0.0) > 100) return tr("Een percentage kan niet hoger zijn dan 100.")
    }
    if (draft.type == "milestone") {
        val unit = unitOf(draft.unit, draft, goals)
        val base = when (draft.baselineState) {
            "measured" -> draft.baselineValue ?: return ""
            "none" -> (goalNumber(draft.baselineTyped)?.takeIf { it >= 0 } ?: return "") * unit.factor
            else -> return ""
        }
        val target = (goalNumber(draft.target) ?: return "") * unit.factor
        val ahead = if (draft.direction == "decrease") target < base - 1e-9 else target > base + 1e-9
        if (!ahead) {
            return if (draft.direction == "decrease") trf("Je doelwaarde moet lager zijn dan je startwaarde (%s).", valueText(base, unit))
            else trf("Je doelwaarde moet hoger zijn dan je startwaarde (%s).", valueText(base, unit))
        }
    }
    val end = draft.end ?: return ""
    if (end <= draft.start) return ""
    return null
}

internal fun complete(step: Int, draft: Draft, goals: Goals): Boolean = when (step) {
    1 -> draft.measurement != null && (draft.measurement != "custom" || draft.category != null)
    2 -> draft.name.trim().length >= 2 && draft.type != null && draft.type in (current(draft, goals)?.types ?: emptyList())
    3 -> problem(draft, goals) == null
    else -> true
}

/** What api/goals/create.php takes — the server checks it all again. */
internal fun payload(draft: Draft, goals: Goals): Map<String, String> = buildMap {
    val m = current(draft, goals)
    put("create_token", draft.token)
    put("name", draft.name.trim())
    put("measurement", draft.measurement.orEmpty())
    if (m?.custom == true) put("category", draft.category.orEmpty())
    put("type", draft.type.orEmpty())
    draft.variant?.let { put("variant", it) }
    put("start_date", draft.start.toString())
    put("end_date", draft.end?.toString().orEmpty())
    if (draft.variant == "period") {
        put("frequency", draft.frequency.trim())
        put("period", draft.period)
    } else {
        put("target_value", draft.target.trim())
        put("unit", draft.unit.orEmpty())
        if (draft.unit == "custom") put("custom_unit", draft.customUnit.trim())
        put("direction", draft.direction ?: "increase")
    }
    if (draft.type == "milestone" && draft.baselineState != "measured") put("baseline_value", draft.baselineTyped.trim())
}

// ============================================== the arithmetic, as the server's

/** A span on from a date, [times] over: calendar months clamp to the month's end (goal_span_add()). */
internal fun spanAdd(date: LocalDate, span: GoalSpan, times: Int = 1): LocalDate =
    if (span.months > 0) date.plusMonths((span.months * times).toLong()) else date.plusDays((span.days * times).toLong())

/** A looptijd's last day: the day before the same date a week, month… on (goal_duration_end()). */
internal fun durationEnd(start: LocalDate, span: GoalSpan): LocalDate = spanAdd(start, span).minusDays(1)

/** What a per-period streak asks in all: a short last period its share, a half up (goal_periods()). */
internal fun periodAsks(start: LocalDate, end: LocalDate, span: GoalSpan, frequency: Int): Int {
    var total = 0
    for (k in 0 until 1000) {
        val from = spanAdd(start, span, k)
        if (from > end) break
        val fullEnd = spanAdd(start, span, k + 1).minusDays(1)
        val to = if (fullEnd > end) end else fullEnd
        val full = ChronoUnit.DAYS.between(from, fullEnd) + 1
        val days = ChronoUnit.DAYS.between(from, to) + 1
        total += if (days == full) frequency else Math.floor(frequency * days.toDouble() / full + 0.5 + 1e-9).toInt()
    }
    return total
}

internal fun roundTo(n: Double, decimals: Int): Double {
    val f = Math.pow(10.0, decimals.toDouble())
    return Math.round(n * f) / f
}

/** "10.000 stappen", "7,5 uur", "85%": a stored figure in a unit (goal_value_text()). */
internal fun valueText(stored: Double, unit: GoalUnit): String {
    val shown = stored / unit.factor
    val text = OwnifyFormat.numberTrim(shown, unit.decimals)
    if (unit.label.isEmpty()) return text
    val word = if (Math.abs(roundTo(shown, unit.decimals) - 1.0) < 1e-9) unit.one else unit.label
    return if (unit.attached) text + word else "$text $word"
}

private fun typedText(typed: String, unit: GoalUnit): String =
    goalNumber(typed)?.let { valueText(it * unit.factor, unit) } ?: "—"

private fun dateText(d: LocalDate): String =
    if (d.year == LocalDate.now().year) OwnifyFormat.date(d, "day_mon") else OwnifyFormat.date(d, "day_mon_year")

/**
 * A figure as somebody types it for a goal (goal_parse_number()): a whole
 * number grouped in threes the reader's way — "10.000" in Dutch, "10,000" in
 * English — is thousands; anything else as OwnifyFormat.parseNumber() reads it.
 */
internal fun goalNumber(text: String): Double? {
    val clean = text.replace(" ", "").replace("\u00A0", "").trim()
    val group = if (OwnifyLanguage.locale == "en") "," else "."
    if (Regex("^-?[1-9][0-9]{0,2}(" + Regex.escape(group) + "[0-9]{3})+$").matches(clean)) return clean.replace(group, "").toDoubleOrNull()
    return OwnifyFormat.parseNumber(clean)
}

internal fun positive(text: String): Boolean {
    val n = goalNumber(text) ?: return false
    return text.trim().isNotEmpty() && n > 0 && n <= 1e9
}

// ---------------------------------------------- shared words (goals.js, the tests)

/** "4 okt", "Oct 4": dates in the reader's language, as lib/goals.php writes them. */
internal fun dutchDate(date: LocalDate): String = OwnifyFormat.date(date, "day_mon")

/** A unit as the reader sees it ("lb", "steps"), from the one kept ("kg", "stappen"). */
internal fun unitLabel(unit: String, labels: Map<String, String>): String = labels[unit] ?: unit

/** …and back: what was typed or tapped, as the unit kept. */
internal fun unitKey(text: String, labels: Map<String, String>): String {
    val typed = text.trim()
    return labels.entries.lastOrNull { it.value == typed }?.key ?: typed
}

/** "3 maanden", "6 weken", "1 jaar". */
internal fun spanText(days: Int): String = when {
    days >= 330 -> Math.round(days / 365.0).let { if (it == 1L) tr("1 jaar") else trf("%d jaar", it) }
    days >= 60 -> trf("%d maanden", Math.round(days / 30.0))
    days >= 45 -> trf("%d weken", Math.round(days / 7.0))
    else -> trn(days, "%d dag", "%d dagen")
}

/** "1 dag", "30 dagen". */
internal fun daysText(n: String): String = trn(n.trim().toDoubleOrNull()?.toInt() ?: 0, "%d dag", "%d dagen")

/** "10.000" or "10,000": the way the rest of the app writes a number, at most two decimals. */
internal fun dutchNumber(text: String): String {
    val n = goalNumber(text) ?: return text
    return OwnifyFormat.numberTrim(n, 2)
}
