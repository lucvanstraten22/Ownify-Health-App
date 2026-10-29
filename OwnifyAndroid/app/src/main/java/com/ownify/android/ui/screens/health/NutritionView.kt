package com.ownify.android.ui.screens.health

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.tween
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.shrinkVertically
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.gestures.awaitEachGesture
import androidx.compose.foundation.gestures.awaitFirstDown
import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.relocation.BringIntoViewRequester
import androidx.compose.foundation.relocation.bringIntoViewRequester
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.rotate
import androidx.compose.ui.draw.scale
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Rect
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.PathEffect
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.layout.IntrinsicMeasurable
import androidx.compose.ui.layout.IntrinsicMeasureScope
import androidx.compose.ui.layout.Layout
import androidx.compose.ui.layout.Measurable
import androidx.compose.ui.layout.MeasureResult
import androidx.compose.ui.layout.MeasureScope
import androidx.compose.ui.node.LayoutModifierNode
import androidx.compose.ui.node.ModifierNodeElement
import androidx.compose.ui.platform.InspectorInfo
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.semantics.ProgressBarRangeInfo
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.disabled
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.progressBarRangeInfo
import androidx.compose.ui.semantics.role
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.setProgress
import androidx.compose.ui.semantics.stateDescription
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.Constraints
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.em
import com.ownify.android.data.Evidence
import com.ownify.android.data.NutritionDay
import com.ownify.android.data.NutritionMacro
import com.ownify.android.data.NutritionMeter
import com.ownify.android.data.NutritionView
import com.ownify.android.data.OwnifyActions
import com.ownify.android.data.Outcome
import com.ownify.android.i18n.OwnifyFormat
import com.ownify.android.i18n.tr
import com.ownify.android.ui.app.LocalOwnedAreas
import com.ownify.android.ui.app.ownsGestures
import com.ownify.android.ui.design.GlassLook
import com.ownify.android.ui.design.GlassRound
import com.ownify.android.ui.design.IconTile
import com.ownify.android.ui.design.JCard
import com.ownify.android.ui.design.JIcon
import com.ownify.android.ui.design.JStyle
import com.ownify.android.ui.design.LocalReducedMotion
import com.ownify.android.ui.design.LocalScreen
import com.ownify.android.ui.design.LocalStillMotion
import com.ownify.android.ui.design.OwnifyIcons
import com.ownify.android.ui.design.StatusBadge
import com.ownify.android.ui.design.StatusTone
import com.ownify.android.ui.design.T
import com.ownify.android.ui.design.cssPadding
import com.ownify.android.ui.design.glass
import com.ownify.android.ui.design.press
import com.ownify.android.ui.design.reveal
import com.ownify.android.ui.theme.Ownify
import com.ownify.android.ui.theme.OwnifyType
import com.ownify.android.ui.screens.research.ResearchEvidence
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlin.math.PI
import kotlin.math.atan2
import kotlin.math.cbrt
import kotlin.math.cos
import kotlin.math.hypot
import kotlin.math.pow
import kotlin.math.roundToInt
import kotlin.math.sin

/*
 * Voeding, drawn — the website's components/nutrition-view.php,
 * assets/css/nutrition.css and assets/js/nutrition.js (docs/NUTRITION.md),
 * under the score:
 *
 *   1  Hoe at je?     the cijfer as a slider, saved when it is let go, for the
 *                     day shown
 *   2  Samenstelling  the day — today or one of the six before, ‹ › — as a
 *                     doughnut of the energy from carbohydrates, protein and
 *                     fat, the size of the score's ring, and those three as
 *                     rows that open in place (a tap on the ring opens its row)
 *   3  Energie, Water two of Ownify's small area charts, side by side
 *
 * Every number and word is the server's (lib/hydrate-nutrition.php).
 */

/** How long a row takes to open (nutrition.css: 280ms, the strong ease-out). */
private const val OPEN_MS = 280

/**
 * [references]: the research beside Voeding's own reference values
 * (`insights.references`, docs/INSIGHTS.md) — the cijfer's (what the
 * guidelines count as eating well), fat's range and that carbohydrates have
 * none, each in its own place.
 */
@Composable
fun NutritionViewContent(view: NutritionView, references: Map<String, Evidence> = emptyMap()) {
    // The day shown: 0 today, up to 6 days back. Both cards follow it.
    var shown by rememberSaveable { mutableIntStateOf(0) }
    val index = shown.coerceIn(0, view.days.lastIndex)
    RatingSliderCard(view, index, references["nutrition.rating"])
    CompositionCard(view, index, references) { shown = it.coerceIn(0, view.days.lastIndex) }
    AreaChartsGrid("nutrition", view.charts)
}

// ---------------------------------------------------------------------------
// Hoe at je? — the slider: the pill track, a glass thumb, the health green
// ---------------------------------------------------------------------------

@Composable
private fun RatingSliderCard(view: NutritionView, index: Int, evidence: Evidence?) {
    val copy = view.rating
    val day = view.days[index]
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val owned = LocalOwnedAreas.current
    val ownKey = remember { Any() }
    DisposableEffect(ownKey) { onDispose { owned.set(ownKey, null) } }

    // What the day has, and what the finger has made of it since.
    var saved by remember(day.date, day.rating) { mutableStateOf(day.rating) }
    var value by remember(day.date, day.rating) { mutableIntStateOf(day.rating ?: 6) }
    var touched by remember(day.date) { mutableStateOf(false) }
    var dragging by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var error by remember(day.date) { mutableStateOf<String?>(null) }
    val empty = saved == null && !touched
    val word = day.word

    fun save(v: Int) {
        if (busy) return
        busy = true
        val date = day.date
        scope.launch {
            val outcome = OwnifyActions.form(context, "api/health/rating.php", mapOf("rating" to v.toString(), "date" to date), copy.error)
            busy = false
            when (outcome) {
                // The page is read again with it (the score, the points, the dot).
                is Outcome.Done -> { saved = v; error = null }
                is Outcome.Refused -> error = outcome.message
                Outcome.SignedOut -> Unit
            }
        }
    }

    JCard(Modifier.fillMaxWidth().reveal()) {
        CardHead(OwnifyIcons.solidSparkle, copy.title, notice = "rating")

        // `.rating-slider__top`: the cijfer, and the question for the day shown.
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
            T(
                if (empty) "–" else value.toString(),
                OwnifyType.style(Ownify.FsFigure, FontWeight.Bold, tracking = Ownify.TrackingTitle, lineHeight = 1.em, tabular = true),
                Modifier.alignByBaseline().width(28.dp)
            )
            T("/ 10 · " + copy.question.format(word), OwnifyType.style(Ownify.FsSmall, color = Ownify.TextMuted), Modifier.alignByBaseline())
        }

        val thumbScale by animateFloatAsState(if (dragging) 1.12f else 1f, tween(Ownify.PressOutMs, easing = Ownify.EaseOutStrong), label = "thumb")
        BoxWithConstraints(
            Modifier
                .fillMaxWidth()
                .padding(top = Ownify.Space2)
                .height(44.dp)
                .ownsGestures(owned, ownKey)
                .semantics {
                    contentDescription = copy.label
                    stateDescription = if (empty) copy.unsaved else value.toString()
                    progressBarRangeInfo = ProgressBarRangeInfo(value.toFloat(), 1f..10f, steps = 8)
                    setProgress { target ->
                        val v = target.roundToInt().coerceIn(1, 10)
                        value = v; touched = true; save(v); true
                    }
                }
                .pointerInput(day.date) {
                    val edge = 14.dp.toPx()
                    fun fromX(x: Float): Int = ((((x - edge) / (size.width - 2 * edge)).coerceIn(0f, 1f)) * 9f).roundToInt() + 1
                    awaitEachGesture {
                        val down = awaitFirstDown()
                        down.consume()
                        dragging = true
                        touched = true
                        value = fromX(down.position.x)
                        while (true) {
                            val change = awaitPointerEvent().changes.firstOrNull { it.id == down.id } ?: break
                            if (!change.pressed) break
                            change.consume()
                            value = fromX(change.position.x)
                        }
                        // Let go: saved for the day shown.
                        dragging = false
                        save(value)
                    }
                }
        ) {
            val at = (value - 1) / 9f
            val track = maxWidth - 28.dp
            // The rail.
            Box(
                Modifier
                    .align(Alignment.CenterStart)
                    .fillMaxWidth()
                    .height(8.dp)
                    .clip(RoundedCornerShape(50))
                    .background(Ownify.fill(0.08f))
                    .border(1.dp, Ownify.GlassHairline, RoundedCornerShape(50))
            )
            // The fill: the health green, up to the thumb.
            Box(
                Modifier
                    .align(Alignment.CenterStart)
                    .width(14.dp + track * at)
                    .height(8.dp)
                    .alpha(if (empty) 0.45f else 1f)
                    .clip(RoundedCornerShape(50))
                    .background(Brush.horizontalGradient(listOf(Ownify.HealthEdge, Ownify.Health)))
            )
            // Ten ticks, one a cijfer.
            Canvas(Modifier.fillMaxSize().padding(horizontal = 14.dp)) {
                val r = 1.dp.toPx()
                for (i in 0..9) drawCircle(Ownify.ink(0.35f), r, Offset(size.width * i / 9f, size.height / 2f))
            }
            // The thumb: glass.
            Box(
                Modifier
                    .align(Alignment.CenterStart)
                    .padding(start = track * at)
                    .size(28.dp)
                    .scale(thumbScale)
                    .glass(CircleShape, GlassLook())
            )
        }
        // `.rating-slider__scale`
        Row(Modifier.fillMaxWidth().padding(top = Ownify.Space2, start = 9.dp, end = 9.dp), horizontalArrangement = Arrangement.SpaceBetween) {
            val scale = OwnifyType.style(Ownify.FsTiny, color = Ownify.TextFaint)
            T("1", scale); T("5", scale); T("10", scale)
        }
        // `.status`: saved, not yet, or why not.
        StatusBadge(
            text = error ?: if (saved != null) copy.saved + " · " + copy.savedFor.format(saved.toString(), word) else copy.unsaved,
            tone = if (error != null) StatusTone.Warn else if (saved != null) StatusTone.On else StatusTone.Neutral,
            modifier = Modifier.padding(top = Ownify.Space2),
            wrap = true
        )
        // What the Dutch guidelines count as eating well: context for the cijfer, never a score of it.
        ResearchEvidence(evidence)
    }
}

/** `.card__head--compact`: the solid tile — greyscale, its icon white — and the card's name. */
@Composable
private fun CardHead(icon: androidx.compose.ui.graphics.vector.ImageVector, title: String, notice: String? = null) {
    Row(
        Modifier.fillMaxWidth().padding(bottom = Ownify.Space4),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)
    ) {
        IconTile(icon, color = Color.White, background = Ownify.Neutral, border = Ownify.Neutral, notice = notice)
        T(title, JStyle.Eyebrow, Modifier.semantics { heading() })
    }
}

// ---------------------------------------------------------------------------
// Samenstelling — the day, its doughnut and its three rows
// ---------------------------------------------------------------------------

@Composable
private fun CompositionCard(view: NutritionView, index: Int, references: Map<String, Evidence>, onDay: (Int) -> Unit) {
    val copy = view.composition
    val day = view.days[index]
    // One row open at a time, on the day shown; a new day starts closed.
    var open by remember(day.date) { mutableStateOf<String?>(null) }
    var fromRing by remember(day.date) { mutableIntStateOf(0) }

    JCard(Modifier.fillMaxWidth().reveal()) {
        CardHead(OwnifyIcons.solidUtensils, copy.title)

        // `.nutrition-daynav`: ‹ the day ›, back six days.
        Row(
            Modifier.fillMaxWidth().padding(top = Ownify.Space2, bottom = Ownify.Space4),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)
        ) {
            GlassRound(OwnifyIcons.chevronLeft, copy.older, { onDay(index + 1) }, size = 34.dp, enabled = index < view.days.lastIndex)
            T(
                day.name,
                OwnifyType.style(Ownify.FsBody, FontWeight.SemiBold),
                Modifier.weight(1f).semantics { stateDescription = day.name },
                align = TextAlign.Center
            )
            GlassRound(OwnifyIcons.chevronRight, copy.newer, { onDay(index - 1) }, size = 34.dp, enabled = index > 0)
        }

        BoxWithConstraints(Modifier.fillMaxWidth()) {
            val ringSize = LocalScreen.current.heroRingSize
            val ring = @Composable { modifier: Modifier ->
                Doughnut(day, open, ringSize, modifier) { key ->
                    open = if (open == key) null else key
                    if (open != null) fromRing++
                }
            }
            val rows = @Composable { modifier: Modifier ->
                Column(modifier, verticalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
                    day.macros.forEach { m ->
                        MacroRow(m, open == m.key, if (open == m.key) fromRing else 0, references["nutrition." + m.key], references) { open = if (open == m.key) null else m.key }
                    }
                }
            }
            // `@container page (min-width: 700px)`: the ring beside its rows.
            if (maxWidth >= 700.dp) {
                Row(horizontalArrangement = Arrangement.spacedBy(Ownify.Space4), verticalAlignment = Alignment.CenterVertically) {
                    ring(Modifier)
                    rows(Modifier.weight(1f))
                }
            } else {
                Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(Ownify.Space4)) {
                    ring(Modifier.align(Alignment.CenterHorizontally))
                    rows(Modifier.fillMaxWidth())
                }
            }
        }

        // The hint only where there is a ring to tap; an empty day says why in the ring itself.
        if (day.kcal != null) {
            T(copy.hint, OwnifyType.style(Ownify.FsTiny, color = Ownify.TextMuted, lineHeight = 1.4.em), Modifier.fillMaxWidth().padding(top = Ownify.Space3))
        }
        // 31.0 (D9): how the energy target in the ring is estimated (NUT-04).
        ResearchEvidence(references["nutrition.energy"])
    }
}

/** The three macronutrients told apart in the Voeding green — lightest, the green itself, deepest (nutrition.css). */
@Composable
private fun macroColor(key: String): Color {
    val lift = Ownify.palette.lift.copy(alpha = 1f)
    val shade = Ownify.palette.shade.copy(alpha = 1f)
    return when (key) {
        "carbs" -> oklabMix(Ownify.NutritionLight, if (Ownify.light) 0.60f else 0.55f, lift)
        "fat" -> oklabMix(Ownify.Nutrition, if (Ownify.light) 0.75f else 0.80f, shade)
        else -> Ownify.NutritionLight
    }
}

/**
 * The doughnut: the score's own size and outer edge (r 0.894 of the half, 12 %
 * wide), a part a macronutrient's share of the energy, a small gap between.
 * A tap on a part opens its row; the open one is lit, the others faint, and
 * the middle tells its numbers instead of the day's total.
 */
@Composable
private fun Doughnut(day: NutritionDay, open: String?, size: Dp, modifier: Modifier, onPart: (String) -> Unit) {
    val reduced = LocalReducedMotion.current || LocalStillMotion.current
    val parts = day.macros.filter { (it.share ?: 0.0) > 0.0 }
    val colors = day.macros.associate { it.key to macroColor(it.key) }
    val ink = Ownify.ink(0.30f)
    val aria = day.macros.joinToString(", ") { it.label + " " + OwnifyFormat.number(it.share ?: 0.0, 0) + "%" }

    Box(modifier.size(size), contentAlignment = Alignment.Center) {
        if (day.kcal == null) {
            Canvas(Modifier.fillMaxSize().clearAndSetSemantics { }) {
                val u = this.size.width / 244f
                val r = (122f * 0.894f - 244f * 0.12f / 2f) * u
                drawCircle(
                    ink, r,
                    style = Stroke(3f * u, cap = StrokeCap.Round, pathEffect = PathEffect.dashPathEffect(floatArrayOf(1f * u, 9f * u)))
                )
            }
            T(day.note.orEmpty(), OwnifyType.style(Ownify.FsTiny, color = Ownify.TextMuted, lineHeight = 1.35.em), Modifier.padding(horizontal = size * 0.22f), align = TextAlign.Center)
            return@Box
        }

        val alphas = parts.associate { p ->
            p.key to animateFloatAsState(
                if (open == null || open == p.key) 1f else 0.35f,
                tween(if (reduced) 0 else 150),
                label = "part"
            ).value
        }
        Canvas(
            Modifier
                .fillMaxSize()
                .semantics { contentDescription = aria }
                .pointerInput(parts) {
                    detectTapGestures { at ->
                        val c = this.size.width / 2f
                        val r = c * 0.894f
                        val ri = r - this.size.width * 0.12f
                        val d = hypot(at.x - c, at.y - c)
                        if (d < ri - 8.dp.toPx() || d > r + 8.dp.toPx()) return@detectTapGestures
                        // From twelve o'clock, clockwise, as the parts are drawn.
                        var a = atan2(at.y - c, at.x - c) + PI.toFloat() / 2f
                        if (a < 0f) a += 2f * PI.toFloat()
                        var from = 0f
                        for (p in parts) {
                            val to = from + (p.share!!.toFloat() / 100f) * 2f * PI.toFloat()
                            if (a in from..to) { onPart(p.key); break }
                            from = to
                        }
                    }
                }
        ) {
            val c = this.size.width / 2f
            val r = c * 0.894f
            val ri = r - this.size.width * 0.12f
            val gap = 0.035f
            var a = -PI.toFloat() / 2f
            for (p in parts) {
                val a1 = a + (p.share!!.toFloat() / 100f) * 2f * PI.toFloat()
                val path = arcPath(Offset(c, c), r, ri, a + gap / 2f, a1 - gap / 2f)
                drawPath(path, (colors[p.key] ?: Ownify.Nutrition).copy(alpha = alphas[p.key] ?: 1f))
                a = a1
            }
        }

        // `.nutrition-ring__center`: the day's total, or the open part's own numbers.
        val lit = day.macros.firstOrNull { it.key == open }
        Column(Modifier.padding(horizontal = size * 0.22f).clearAndSetSemantics { }, horizontalAlignment = Alignment.CenterHorizontally) {
            val value = OwnifyType.style(Ownify.FsScoreSm, FontWeight.Bold, tracking = Ownify.TrackingTitle, lineHeight = 1.1.em, tabular = true)
            val sub = OwnifyType.style(Ownify.FsTiny, color = Ownify.TextMuted, lineHeight = 1.35.em)
            if (lit == null) {
                T(day.centre?.value.orEmpty(), value, align = TextAlign.Center)
                T(day.centre?.sub.orEmpty(), sub, align = TextAlign.Center)
            } else {
                T(lit.label, OwnifyType.style(Ownify.FsSmall, FontWeight.SemiBold, Ownify.TextMuted), align = TextAlign.Center)
                T(OwnifyFormat.number(lit.share ?: 0.0, 0) + " %", value, align = TextAlign.Center)
                T(lit.facts.firstOrNull()?.second.orEmpty(), sub, align = TextAlign.Center)
            }
        }
    }
}

/** A part of the ring: the outer arc from [a0] to [a1], the inner one back. */
private fun arcPath(c: Offset, r: Float, ri: Float, a0: Float, a1: Float): Path = Path().apply {
    val deg = 180f / PI.toFloat()
    arcTo(Rect(c, r), a0 * deg, (a1 - a0) * deg, forceMoveTo = true)
    lineTo(c.x + ri * cos(a1), c.y + ri * sin(a1))
    arcTo(Rect(c, ri), a1 * deg, -(a1 - a0) * deg, forceMoveTo = false)
    close()
}

/**
 * A row (`.nutrition-row`, the Row shape): its colour's key, its name and
 * grams, a chevron; the share and range under it, its bubble and meter; and
 * what opens in place — the facts, why, and its details.
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun MacroRow(m: NutritionMacro, open: Boolean, fromRing: Int, evidence: Evidence?, references: Map<String, Evidence>, onToggle: () -> Unit) {
    val reduced = LocalReducedMotion.current || LocalStillMotion.current
    val shape = RoundedCornerShape(Ownify.RadiusSm)
    val color = macroColor(m.key)
    val bring = remember { BringIntoViewRequester() }
    val interaction = remember { MutableInteractionSource() }
    val turn by animateFloatAsState(if (open) 90f else 0f, tween(if (reduced) 0 else 260, easing = Ownify.EaseOutStrong), label = "chevron")

    // From the ring: its row brought into view once it has opened.
    LaunchedEffect(fromRing) {
        if (fromRing > 0 && open) {
            delay(if (reduced) 0L else OPEN_MS.toLong())
            bring.bringIntoView()
        }
    }

    Column(
        Modifier
            .fillMaxWidth()
            .bringIntoViewRequester(bring)
            .clip(shape)
            .background(if (open) Ownify.fill(0.07f) else Ownify.InsetFill, shape)
            .border(1.dp, if (open) Ownify.GlassHairline else Color.Transparent, shape)
            .cssPadding(PaddingValues(Ownify.Space3), border = 1.dp)
    ) {
        // The head: a button that opens the row.
        Row(
            Modifier
                .fillMaxWidth()
                .heightIn(min = 28.dp)
                .press(interaction, enabled = m.logged)
                .clickable(interaction, indication = null, enabled = m.logged, role = Role.Button, onClick = onToggle)
                .semantics(mergeDescendants = true) {
                    if (m.logged) stateDescription = if (open) tr("Uitgeklapt") else tr("Ingeklapt") else disabled()
                },
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)
        ) {
            Box(Modifier.size(10.dp).clip(RoundedCornerShape(3.dp)).background(color))
            T(m.label, OwnifyType.style(Ownify.FsBody, FontWeight.SemiBold), Modifier.weight(1f))
            T(
                m.head,
                OwnifyType.style(Ownify.FsBody, if (m.logged) FontWeight.SemiBold else FontWeight.Medium, if (m.logged) Ownify.TextPrimary else Ownify.TextMuted, tabular = true)
            )
            if (m.logged) JIcon(OwnifyIcons.chevronRight, Modifier.rotate(turn), size = 14.dp, color = Ownify.TextFaint)
        }

        if (!m.logged) return@Column
        Column(Modifier.fillMaxWidth().padding(start = 10.dp + Ownify.Space3), verticalArrangement = Arrangement.spacedBy(Ownify.Space1)) {
            if (m.sub.isNotEmpty()) {
                FlowRow(Modifier.padding(top = Ownify.Space1), horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)) {
                    m.sub.forEach { T(it, OwnifyType.style(Ownify.FsTiny, color = Ownify.TextMuted)) }
                }
            }
            if (m.verdict != null && m.verdictText != null) Bubble(m.verdictText, m.verdict)
            m.meter?.let { Meter(it, color, Modifier.padding(top = Ownify.Space1)) }

            AnimatedVisibility(
                visible = open,
                enter = if (reduced) fadeIn(tween(0)) else expandVertically(tween(OPEN_MS, easing = Ownify.EaseOutStrong), expandFrom = Alignment.Top) + fadeIn(tween(150)),
                exit = if (reduced) fadeOut(tween(0)) else shrinkVertically(tween(OPEN_MS, easing = Ownify.EaseOutStrong), shrinkTowards = Alignment.Top) + fadeOut(tween(150))
            ) {
                Column(Modifier.fillMaxWidth()) {
                    FactGrid(m.facts, Modifier.padding(top = Ownify.Space3))
                    m.why?.let { T(it, OwnifyType.style(Ownify.FsTiny, color = Ownify.TextMuted, lineHeight = 1.4.em), Modifier.padding(top = Ownify.Space2)) }
                    if (m.details.isNotEmpty()) {
                        Column(Modifier.fillMaxWidth().padding(top = Ownify.Space2)) {
                            Box(Modifier.fillMaxWidth().height(1.dp).background(Ownify.GlassHairline))
                            Column(Modifier.padding(top = Ownify.Space2), verticalArrangement = Arrangement.spacedBy(Ownify.Space1)) {
                                m.details.forEach { d ->
                                    // Where a line is too narrow for all four (fibre's "per 1.000 kcal"
                                    // on a small phone), what does not fit moves under it: nothing runs
                                    // over the name, nothing is cut off (`.nutrition-row__details li`).
                                    // The name keeps its whole width and takes what is left
                                    // (`.nutrition-row__detail`, `flex: 1 0 auto`).
                                    FlowRow(
                                        Modifier.fillMaxWidth().semantics(mergeDescendants = true) { },
                                        horizontalArrangement = Arrangement.spacedBy(Ownify.Space2),
                                        verticalArrangement = Arrangement.spacedBy(Ownify.Space1),
                                        itemVerticalAlignment = Alignment.CenterVertically
                                    ) {
                                        T(d.label, OwnifyType.style(Ownify.FsSmall, color = Ownify.TextSecondary), Modifier.weight(1f).wholeWidth())
                                        if (d.value != null) {
                                            T(d.value, OwnifyType.style(Ownify.FsSmall, FontWeight.SemiBold, tabular = true), maxLines = 1)
                                            d.extra?.let { T(it, OwnifyType.style(Ownify.FsTiny, color = Ownify.TextMuted), maxLines = 1) }
                                            if (d.verdict != null && d.verdictText != null) Bubble(d.verdictText, d.verdict)
                                        } else {
                                            T(d.verdictText.orEmpty(), OwnifyType.style(Ownify.FsTiny, color = Ownify.TextMuted), maxLines = 1)
                                        }
                                    }
                                    // 31.0 (D9): why sugar has no verdict (free sugars, NUT-01).
                                    references["nutrition." + d.key]?.let { ResearchEvidence(it) }
                                }
                            }
                        }
                    }
                    // The research behind this row's guideline: fat's range, and that carbohydrates have none.
                    ResearchEvidence(evidence)
                }
            }
        }
    }
}

/**
 * A bubble: the goal wizard's glass chip as a status — green glass inside its
 * range, gold glass with gold text above it, plain under it or without a
 * verdict. Never pressed.
 */
@Composable
private fun Bubble(text: String, verdict: String) {
    val look = when (verdict) {
        "in" -> GlassLook(chosen = true, shadow = 7.dp, lift = 2.dp)
        "high" -> GlassLook(caution = true, shadow = 7.dp, lift = 2.dp)
        else -> GlassLook(shadow = 7.dp, lift = 2.dp)
    }
    val strong = verdict == "in" || verdict == "high"
    T(
        text,
        OwnifyType.style(
            Ownify.FsTiny,
            if (strong) FontWeight.SemiBold else FontWeight.Medium,
            when (verdict) { "in" -> Ownify.TextPrimary; "high" -> Ownify.Attention; else -> Ownify.TextSecondary },
            tracking = 0.em
        ),
        Modifier
            .glass(RoundedCornerShape(50), look)
            .cssPadding(PaddingValues(horizontal = Ownify.Space3, vertical = Ownify.Space1), border = 1.dp),
        maxLines = 1
    )
}

/** The meter: the range as a green band, how far the day went in the macro's colour, and its marker. */
@Composable
private fun Meter(meter: NutritionMeter, color: Color, modifier: Modifier) {
    val track = Ownify.fill(0.07f)
    val mark = Ownify.TextPrimary
    Canvas(modifier.fillMaxWidth().height(12.dp).clearAndSetSemantics { }) {
        val h = 6.dp.toPx()
        val top = (size.height - h) / 2f
        val radius = androidx.compose.ui.geometry.CornerRadius(h / 2f)
        fun x(v: Float) = (v / meter.max).coerceIn(0f, 1f) * size.width
        drawRoundRect(track, Offset(0f, top), Size(size.width, h), radius)
        if (meter.low != null && meter.high != null) {
            val l = x(meter.low)
            drawRoundRect(Ownify.HealthFill, Offset(l, top), Size(x(meter.high) - l, h), radius)
            drawRoundRect(Ownify.HealthEdge, Offset(l, top), Size(x(meter.high) - l, h), radius, style = Stroke(1.dp.toPx()))
        }
        drawRoundRect(Ownify.mix(color, 0.55f, Color.Transparent), Offset(0f, top), Size(x(meter.value), h), radius)
        val w = 3.dp.toPx()
        drawRoundRect(mark, Offset(x(meter.value) - w / 2f, 0f), Size(w, size.height), androidx.compose.ui.geometry.CornerRadius(2.dp.toPx()))
    }
}

/** `.nutrition-row__facts`: a name and its value a line, the names one column as wide as the widest. */
@Composable
private fun FactGrid(facts: List<Pair<String, String>>, modifier: Modifier) {
    val label = OwnifyType.style(Ownify.FsSmall, color = Ownify.TextMuted, lineHeight = 1.4.em)
    val value = OwnifyType.style(Ownify.FsSmall, FontWeight.SemiBold, lineHeight = 1.4.em, tabular = true)
    Layout(
        content = {
            facts.forEach { (l, v) ->
                T(l, label)
                T(v, value)
            }
        },
        modifier = modifier.fillMaxWidth().semantics(mergeDescendants = true) { }
    ) { measurables, constraints ->
        val gap = Ownify.Space3.roundToPx()
        val rowGap = 3.dp.roundToPx()
        val labels = measurables.filterIndexed { i, _ -> i % 2 == 0 }.map { it.measure(Constraints(maxWidth = constraints.maxWidth / 2)) }
        val col = labels.maxOfOrNull { it.width } ?: 0
        val values = measurables.filterIndexed { i, _ -> i % 2 == 1 }.map { it.measure(Constraints(maxWidth = (constraints.maxWidth - col - gap).coerceAtLeast(0))) }
        val heights = labels.indices.map { maxOf(labels[it].height, values[it].height) }
        val total = heights.sum() + rowGap * (heights.size - 1).coerceAtLeast(0)
        layout(constraints.maxWidth, total) {
            var y = 0
            labels.indices.forEach { i ->
                labels[i].place(0, y)
                values[i].place(col + gap, y)
                y += heights[i] + rowGap
            }
        }
    }
}

/** `color-mix(in oklab, a share, b)`, as the website mixes the macronutrients' greens. */
internal fun oklabMix(a: Color, share: Float, b: Color): Color {
    fun lin(c: Float) = if (c <= 0.04045f) c / 12.92f else ((c + 0.055f) / 1.055f).pow(2.4f)
    fun gam(c: Float) = (if (c <= 0.0031308f) 12.92f * c else 1.055f * c.pow(1f / 2.4f) - 0.055f).coerceIn(0f, 1f)
    fun lab(c: Color): FloatArray {
        val r = lin(c.red); val g = lin(c.green); val bl = lin(c.blue)
        val l = cbrt(0.4122214708f * r + 0.5363325363f * g + 0.0514459929f * bl)
        val m = cbrt(0.2119034982f * r + 0.6806995451f * g + 0.1073969566f * bl)
        val s = cbrt(0.0883024619f * r + 0.2817188376f * g + 0.6299787005f * bl)
        return floatArrayOf(
            0.2104542553f * l + 0.7936177850f * m - 0.0040720468f * s,
            1.9779984951f * l - 2.4285922050f * m + 0.4505937099f * s,
            0.0259040371f * l + 0.7827717662f * m - 0.8086757660f * s
        )
    }
    val x = lab(a); val y = lab(b)
    val L = x[0] * share + y[0] * (1 - share)
    val A = x[1] * share + y[1] * (1 - share)
    val B = x[2] * share + y[2] * (1 - share)
    val l = (L + 0.3963377774f * A + 0.2158037573f * B).pow(3)
    val m = (L - 0.1055613458f * A - 0.0638541728f * B).pow(3)
    val s = (L - 0.0894841775f * A - 1.2914855480f * B).pow(3)
    return Color(
        gam(4.0767416621f * l - 3.3077115913f * m + 0.2309699292f * s),
        gam(-1.2684380046f * l + 2.6097574011f * m - 0.3413193965f * s),
        gam(-0.0041960863f * l - 0.7034186147f * m + 1.7076147010f * s)
    )
}

/**
 * `flex: 1 0 auto` for a weighted item in a [FlowRow]: the line breaks at
 * its whole width. FlowRow asks a weighted item only for its narrowest width
 * (its longest word), and would then squeeze it into what is left and cut it
 * off — "Suikers (tot" on the narrowest phones.
 */
private fun Modifier.wholeWidth(): Modifier = this then WholeWidthElement

private data object WholeWidthElement : ModifierNodeElement<WholeWidthNode>() {
    override fun create() = WholeWidthNode()
    override fun update(node: WholeWidthNode) = Unit
    override fun InspectorInfo.inspectableProperties() {
        name = "wholeWidth"
    }
}

private class WholeWidthNode : Modifier.Node(), LayoutModifierNode {
    override fun MeasureScope.measure(measurable: Measurable, constraints: Constraints): MeasureResult {
        val placeable = measurable.measure(constraints)
        return layout(placeable.width, placeable.height) { placeable.place(0, 0) }
    }

    override fun IntrinsicMeasureScope.minIntrinsicWidth(measurable: IntrinsicMeasurable, height: Int): Int =
        measurable.maxIntrinsicWidth(height)
}
