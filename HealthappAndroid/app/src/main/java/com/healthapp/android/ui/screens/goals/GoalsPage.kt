package com.healthapp.android.ui.screens.goals

import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.animateDpAsState
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
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.relocation.BringIntoViewRequester
import androidx.compose.foundation.relocation.bringIntoViewRequester
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.key
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.drawBehind
import androidx.compose.ui.draw.drawWithContent
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.platform.LocalGraphicsContext
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.disabled
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.onClick
import androidx.compose.ui.semantics.role
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.em
import androidx.compose.ui.unit.sp
import com.healthapp.android.data.AppData
import com.healthapp.android.data.Goal
import com.healthapp.android.data.GoalsEmpty
import com.healthapp.android.ui.app.Detail
import com.healthapp.android.ui.app.LocalShell
import com.healthapp.android.ui.app.Overlay
import com.healthapp.android.ui.app.PageColumn
import com.healthapp.android.ui.design.BoxShadow
import com.healthapp.android.ui.design.Btn
import com.healthapp.android.ui.design.CardStyle
import com.healthapp.android.ui.design.Chip
import com.healthapp.android.ui.design.Disclaimer
import com.healthapp.android.ui.design.IconTile
import com.healthapp.android.ui.design.JCard
import com.healthapp.android.ui.design.JIcon
import com.healthapp.android.ui.design.JStyle
import com.healthapp.android.ui.design.JoluIcons
import com.healthapp.android.ui.design.LocalScreen
import com.healthapp.android.ui.design.Meter
import com.healthapp.android.ui.design.RangeSwitch
import com.healthapp.android.ui.design.T
import com.healthapp.android.ui.design.chWidth
import com.healthapp.android.ui.design.countUpText
import com.healthapp.android.ui.design.drawBoxShadows
import com.healthapp.android.ui.design.press
import com.healthapp.android.ui.design.rememberPlayOnSight
import com.healthapp.android.ui.design.reveal
import com.healthapp.android.ui.theme.Accent
import com.healthapp.android.ui.theme.Jolu
import com.healthapp.android.ui.theme.JoluType
import com.healthapp.android.ui.theme.LocalAccent

/**
 * Doelen (pages/goals.php): what you are working toward, which one matters
 * most, how far you are and when it ends — Actief and Behaald, switched in
 * place — and the + that starts the wizard. Everything deeper is behind the
 * card, on the goal's own page.
 */
@Composable
fun GoalsPage(data: AppData, scroll: ScrollState) {
    val goals = data.goals
    val shell = LocalShell.current
    val board = GoalBoard.board(goals)
    val view = GoalBoard.view ?: goals.defaultView
    val labels = goals.labels
    val addWizard = { shell.open(Overlay.Wizard) }

    // `[data-page="goals"] .app__main { padding-top: 12px }`
    PageColumn(scroll, mainTop = Jolu.Space3) {
        // .goals-intro
        Column(Modifier.fillMaxWidth().reveal(), verticalArrangement = Arrangement.spacedBy(Jolu.Space2)) {
            Row(
                Modifier.fillMaxWidth().padding(horizontal = Jolu.Space2),
                verticalAlignment = Alignment.Top,
                horizontalArrangement = Arrangement.spacedBy(Jolu.Space3)
            ) {
                Column(Modifier.weight(1f)) {
                    T(goals.title, JStyle.Section, Modifier.semantics { heading() })
                    if (goals.lede.isNotEmpty()) T(goals.lede, JStyle.Meta, Modifier.padding(top = Jolu.Space1))
                }
                AddButton(enabled = board.canAdd, label = labels["add_aria"].orEmpty(), onClick = addWizard)
            }
            RangeSwitch(
                options = goals.views,
                selected = view,
                onSelect = { GoalBoard.view = it },
                wide = true,
                label = "Actieve of behaalde doelen"
            )
        }

        if (view == "completed") CompletedView(data, board) else ActiveView(data, board, addWizard)

        if (data.disclaimer.isNotEmpty()) Disclaimer(data.disclaimer)
    }
}

/** `.goals-view[data-goal-panel="active"]`: the empty state or the primary goal, the others and the slot note. */
@Composable
private fun ActiveView(data: AppData, board: Board, onAdd: () -> Unit) {
    val goals = data.goals
    val labels = goals.labels
    val any = board.active > 0

    // Every slot is in the column whether or not it holds a card, as on the website.
    Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(Jolu.Space2)) {
        if (!any) EmptyCard(goals.emptyActive, JoluIcons.flag, if (board.canAdd) onAdd else null)
        if (any) Eyebrow(labels["primary"].orEmpty())

        Column(Modifier.fillMaxWidth().reveal(), verticalArrangement = Arrangement.spacedBy(Jolu.Space3)) {
            board.primary?.let { goal -> key(goal.id) { BoardCard(data, goal, "primary", goal.id in board.leaving) } }
        }

        if (board.secondary.isNotEmpty()) Eyebrow(labels["secondary"].orEmpty(), Modifier.padding(top = Jolu.Space1))

        Column(Modifier.fillMaxWidth().reveal(), verticalArrangement = Arrangement.spacedBy(Jolu.Space3)) {
            board.secondary.forEach { goal -> key(goal.id) { BoardCard(data, goal, "secondary", goal.id in board.leaving) } }
        }

        if (any) SlotNote(slotNote(board.slotsLeft, labels))
    }
}

/** `goals_slot_note()`: the slot note in the page's own words, as goals.js writes it after a change. */
internal fun slotNote(left: Int, labels: Map<String, String>): String = when (left) {
    0 -> labels["slots_full"].orEmpty()
    1 -> labels["slots_one"].orEmpty()
    else -> labels["slots_free"].orEmpty().replace("%d", left.toString())
}

/** `.goals-view[data-goal-panel="completed"]`. */
@Composable
private fun CompletedView(data: AppData, board: Board) {
    Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(Jolu.Space2)) {
        if (board.completed.isEmpty()) {
            EmptyCard(data.goals.emptyCompleted, JoluIcons.award, null)
        } else {
            Column(Modifier.fillMaxWidth().reveal(), verticalArrangement = Arrangement.spacedBy(Jolu.Space3)) {
                board.completed.forEach { goal -> key(goal.id) { BoardCard(data, goal, "completed", false) } }
            }
            SlotNote("Behaalde doelen tellen niet mee voor je drie actieve plekken.")
        }
    }
}

/** A card on the board: opens the goal, and scrolls into view when it is the one just made. */
@Composable
private fun BoardCard(data: AppData, goal: Goal, variant: String, leaving: Boolean) {
    val shell = LocalShell.current
    val labels = data.goals.labels
    val requester = remember { BringIntoViewRequester() }
    val reading = if (goal.percent != null) "${goal.percent} procent van ${goal.targetLabel.ifEmpty { "je doel" }}" else "nog geen voortgang"

    LaunchedEffect(GoalBoard.reveal) {
        if (GoalBoard.reveal == goal.id) {
            requester.bringIntoView()
            GoalBoard.reveal = null
        }
    }

    GoalCard(
        GoalCardModel.of(goal, labels["paused_chip"]),
        variant = variant,
        leaving = leaving,
        label = "${(labels["open_aria"] ?: "%s").replace("%s", goal.name)} — $reading",
        onClick = { shell.openDetail(Detail.GoalPage(goal.id)) },
        modifier = Modifier.bringIntoViewRequester(requester)
    )
}

/** `.goals-eyebrow`: small caps over a slot, 8 in from the column. */
@Composable
private fun Eyebrow(text: String, modifier: Modifier = Modifier) {
    T(text, JStyle.Caption, modifier.fillMaxWidth().padding(horizontal = Jolu.Space2).semantics { heading() }, uppercase = true)
}

/** `.goals-slots`: how many places are left. */
@Composable
private fun SlotNote(text: String) {
    T(text, JStyle.Tiny, Modifier.fillMaxWidth().padding(horizontal = Jolu.Space2))
}

/** `.goals-empty`: nothing here yet — its icon, what to know, and (on Actief) the way to start. */
@Composable
private fun EmptyCard(copy: GoalsEmpty, icon: androidx.compose.ui.graphics.vector.ImageVector, onAdd: (() -> Unit)?) {
    JCard(Modifier.fillMaxWidth().reveal(), padding = PaddingValues(horizontal = Jolu.Space5, vertical = Jolu.Space6)) {
        Column(Modifier.fillMaxWidth(), horizontalAlignment = Alignment.CenterHorizontally) {
            IconTile(icon)
            T(copy.title, JStyle.Section, Modifier.padding(top = Jolu.Space4).semantics { heading() }, align = TextAlign.Center)
            T(
                copy.body,
                JStyle.Lede,
                Modifier.padding(top = Jolu.Space2).widthIn(max = chWidth(JStyle.Lede, 32f)),
                align = TextAlign.Center
            )
            if (onAdd != null && !copy.cta.isNullOrEmpty()) {
                Btn(copy.cta, onClick = onAdd, icon = JoluIcons.plus, modifier = Modifier.padding(top = Jolu.Space5))
            }
        }
    }
}

/**
 * `.goals-add`: the green 44 circle beside the title; quiet and inert while
 * all three places are taken.
 */
@Composable
private fun AddButton(enabled: Boolean, label: String, onClick: () -> Unit) {
    val interaction = remember { MutableInteractionSource() }
    val shadows = LocalGraphicsContext.current.shadowContext
    Box(
        Modifier
            .press(interaction, scale = 0.94f, enabled = enabled)
            .size(44.dp)
            .drawWithContent {
                if (enabled) drawBoxShadows(CircleShape, listOf(BoxShadow(y = 6.dp, blur = 16.dp, color = Color.Black.copy(alpha = 0.22f))), shadows)
                drawContent()
            }
            .clip(CircleShape)
            .background(
                if (enabled) Brush.verticalGradient(listOf(Jolu.Health.copy(alpha = 0.22f), Jolu.Health.copy(alpha = 0.10f)))
                else SolidColor(Jolu.GlassSoft)
            )
            .drawBehind {
                // --shadow-inset: the top light, over the fill and under the icon.
                if (enabled) drawBoxShadows(CircleShape, listOf(BoxShadow(y = 1.dp, blur = 0.dp, color = Jolu.white(0.08f), inset = true)), shadows)
            }
            .border(1.dp, if (enabled) Jolu.mix(Jolu.Health, 0.30f, Jolu.GlassBorder) else Jolu.GlassBorderSoft, CircleShape)
            .clickable(interaction, indication = null, enabled = enabled, role = Role.Button, onClick = onClick)
            .semantics {
                contentDescription = label
                if (!enabled) disabled()
            },
        contentAlignment = Alignment.Center
    ) {
        JIcon(JoluIcons.plus, size = 22.dp, color = if (enabled) Jolu.TextPrimary else Jolu.TextMuted)
    }
}

// ---------------------------------------------------------------------------
// The goal card — the board's, the wizard preview's
// ---------------------------------------------------------------------------

/** What a goal card shows, whether it is a stored goal or the wizard's preview of one. */
data class GoalCardModel(
    val name: String,
    val categoryLabel: String,
    val icon: String,
    val accent: String,
    val percent: Int?,
    val ratio: Double,
    val targetLabel: String,
    val paused: Boolean,
    val completed: Boolean,
    val deadline: String,
    val pausedChip: String?
) {
    companion object {
        fun of(goal: Goal, pausedChip: String?) = GoalCardModel(
            name = goal.name,
            categoryLabel = goal.categoryLabel,
            icon = goal.icon,
            accent = goal.accent,
            percent = goal.percent,
            ratio = goal.ratio,
            targetLabel = goal.targetLabel.ifEmpty { "—" },
            paused = goal.isPaused,
            completed = goal.isCompleted,
            deadline = goal.deadlineLine,
            pausedChip = pausedChip
        )
    }
}

/**
 * `components/goal-card.php`: one goal — what it is, how far, when it ends.
 * The same card for the primary goal, a secondary one and a completed one;
 * only its size and surface change. [onClick] null is the wizard's preview.
 */
@Composable
fun GoalCard(
    model: GoalCardModel,
    variant: String,
    modifier: Modifier = Modifier,
    leaving: Boolean = false,
    label: String? = null,
    onClick: (() -> Unit)? = null
) {
    val narrow = LocalScreen.current.narrow
    val accent = Accent.of(model.accent)
    val primary = variant == "primary"
    val empty = model.percent == null
    // The wizard's preview is built without the card's state classes: no dashes, no muted dash.
    val preview = onClick == null
    val quiet = !primary || model.paused
    val (play, sight) = rememberPlayOnSight()
    val interaction = remember { MutableInteractionSource() }
    val pressed by interaction.collectIsPressedAsState()
    val nudge by animateDpAsState(if (pressed) 2.dp else 0.dp, tween(Jolu.FastMs, easing = Jolu.Ease), label = "chevron")
    val fade by animateFloatAsState(if (leaving) 0f else 1f, tween(Jolu.FastMs, easing = Jolu.Ease), label = "leave")

    // A pause turns the surface quiet over --transition-slow, as the website's card does.
    val slow = tween<Color>(Jolu.SlowMs, easing = Jolu.EaseOut)
    val from by animateColorAsState(if (quiet) CardStyle.Quiet.from else CardStyle.Default.from, slow, label = "from")
    val to by animateColorAsState(if (quiet) CardStyle.Quiet.to else CardStyle.Default.to, slow, label = "to")
    val border by animateColorAsState(if (quiet) Jolu.GlassBorderSoft else Jolu.GlassBorder, slow, label = "border")
    val bar by animateFloatAsState(if (model.paused) 0.35f else 1f, tween(Jolu.SlowMs, easing = Jolu.EaseOut), label = "bar")

    val padding = when {
        primary && narrow -> PaddingValues(Jolu.Space4)
        primary -> PaddingValues(horizontal = Jolu.Space5, vertical = Jolu.Space4)
        narrow -> PaddingValues(Jolu.Space3)
        else -> PaddingValues(horizontal = Jolu.Space4, vertical = Jolu.Space3)
    }

    CompositionLocalProvider(LocalAccent provides accent) {
        JCard(
            modifier
                .fillMaxWidth()
                .graphicsLayer {
                    alpha = fade
                    val s = 0.97f + 0.03f * fade
                    scaleX = s
                    scaleY = s
                }
                .then(if (onClick != null) Modifier.press(interaction, scale = 0.985f) else Modifier)
                .then(sight)
                .then(
                    if (onClick != null) Modifier
                        .clickable(interaction, indication = null, enabled = !leaving, onClick = onClick)
                        .clearAndSetSemantics {
                            role = Role.Button
                            contentDescription = label ?: model.name
                            onClick { onClick(); true }
                        }
                    else Modifier.clearAndSetSemantics { }
                )
                .drawWithContent {
                    drawContent()
                    // .goal-card--primary::after — the accent along the left edge.
                    if (primary) {
                        drawRect(
                            Brush.verticalGradient(listOf(accent.color, Jolu.mix(accent.color, 0.35f, Color.Transparent))),
                            topLeft = Offset.Zero,
                            size = Size(3.dp.toPx(), size.height),
                            alpha = bar
                        )
                    }
                },
            style = CardStyle.Default.copy(from = from, to = to, border = border),
            padding = padding
        ) {
            Column(verticalArrangement = Arrangement.spacedBy(if (primary) Jolu.Space3 else Jolu.Space2)) {
                // .goal-card__top
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Jolu.Space3)) {
                    JoluIcons.named(model.icon)?.let {
                        if (primary) IconTile(it, color = accent.color)
                        else IconTile(it, size = 32.dp, radius = 11.dp, iconSize = 17.dp, color = accent.color)
                    }
                    Column(Modifier.weight(1f)) {
                        T(model.categoryLabel, JStyle.Caption, uppercase = true, maxLines = 1, ellipsis = true)
                        T(
                            model.name,
                            JoluType.style(
                                if (primary && !narrow) 19.sp else 17.sp, FontWeight.SemiBold,
                                if (model.paused) Jolu.TextSecondary else Jolu.TextPrimary,
                                tracking = (-0.015).em
                            ),
                            Modifier.padding(top = 2.dp),
                            maxLines = 1,
                            ellipsis = true
                        )
                    }
                    if (model.completed) {
                        Box(
                            Modifier.size(30.dp).clip(CircleShape).background(Jolu.mix(accent.color, 0.18f, Color.Transparent)),
                            contentAlignment = Alignment.Center
                        ) {
                            JIcon(JoluIcons.award, size = 16.dp, color = accent.color)
                        }
                    }
                }

                // .goal-card__figures: the percentage and the target on one baseline.
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Jolu.Space3)) {
                    Row(Modifier.weight(1f).alignByBaseline()) {
                        T(
                            countUpText(model.percent?.toString() ?: "—", play && !empty),
                            JoluType.style(
                                if (primary && !narrow) Jolu.FsScoreSm else 24.sp, FontWeight.Bold,
                                if ((empty && !preview) || model.paused) Jolu.TextSecondary else Jolu.TextPrimary,
                                tracking = (-0.035).em, lineHeight = 1.em, tabular = true
                            ),
                            Modifier.alignByBaseline()
                        )
                        if (!empty) T("%", JStyle.Meta, Modifier.alignByBaseline().padding(start = 2.dp))
                    }
                    T(
                        model.targetLabel,
                        JoluType.style(if (narrow) Jolu.FsSmall else Jolu.FsLabel, FontWeight.SemiBold, Jolu.TextSecondary, tabular = true),
                        Modifier.alignByBaseline(),
                        maxLines = 1
                    )
                }

                Meter(
                    share = if (preview) 0f else if (empty) null else model.ratio.toFloat().coerceIn(0f, 1f),
                    play = play,
                    accent = accent.color,
                    height = if (primary) 10.dp else 8.dp,
                    emptyHeight = if (primary) 10.dp else 8.dp,
                    fill = if (model.paused) SolidColor(Jolu.white(0.22f)) else null
                )

                // .goal-card__foot
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Jolu.Space3)) {
                    if (model.paused && !model.completed && !model.pausedChip.isNullOrEmpty()) Chip(model.pausedChip, quiet = true)
                    T(model.deadline, JStyle.Meta, Modifier.weight(1f), maxLines = 1, ellipsis = true)
                    if (!preview) JIcon(JoluIcons.chevronRight, Modifier.offset(x = nudge), size = 15.dp, color = Jolu.TextMuted)
                }
            }
        }
    }
}
