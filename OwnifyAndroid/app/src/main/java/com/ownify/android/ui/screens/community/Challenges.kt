package com.ownify.android.ui.screens.community

import com.ownify.android.ui.design.NoticePlace
import com.ownify.android.ui.design.notice
import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.content.Intent
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.tween
import androidx.compose.foundation.ScrollState
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.ui.layout.Layout
import androidx.compose.foundation.layout.IntrinsicSize
import androidx.compose.ui.graphics.vector.ImageVector
import com.ownify.android.data.ChallengeIdea
import com.ownify.android.ui.design.Chip
import com.ownify.android.ui.design.GlassLook
import com.ownify.android.ui.design.LocalScreen
import com.ownify.android.ui.design.glass
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxHeight
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
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.drawBehind
import com.ownify.android.ui.design.cssPadding
import com.ownify.android.ui.design.note
import com.ownify.android.ui.design.NoteTone
import androidx.compose.ui.graphics.drawscope.clipRect
import androidx.compose.ui.draw.drawWithContent
import androidx.compose.ui.geometry.CornerRadius
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.PathEffect
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.TransformOrigin
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.role
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.testTag
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.em
import androidx.compose.ui.unit.sp
import com.ownify.android.data.AppData
import com.ownify.android.data.ChallengeBar
import com.ownify.android.data.ChallengeInvite
import com.ownify.android.data.ChallengePerson
import com.ownify.android.data.ChallengeView
import com.ownify.android.data.Faces
import com.ownify.android.i18n.tr
import com.ownify.android.i18n.trf
import com.ownify.android.ui.app.AvatarPhoto
import com.ownify.android.ui.app.DetailColumn
import com.ownify.android.ui.app.LocalShell
import com.ownify.android.ui.app.Overlay
import com.ownify.android.ui.app.OwnifyToast
import com.ownify.android.ui.app.heroName
import com.ownify.android.ui.design.Btn
import com.ownify.android.ui.design.BtnLook
import com.ownify.android.ui.design.CardStyle
import com.ownify.android.ui.design.IconTile
import com.ownify.android.ui.design.JCard
import com.ownify.android.ui.design.JIcon
import com.ownify.android.ui.design.JStyle
import com.ownify.android.ui.design.LocalReducedMotion
import com.ownify.android.ui.design.LocalStillMotion
import com.ownify.android.ui.design.OwnifyIcons
import com.ownify.android.ui.design.PageIntro
import com.ownify.android.ui.design.T
import com.ownify.android.ui.design.press
import com.ownify.android.ui.design.reveal
import com.ownify.android.ui.theme.Accent
import com.ownify.android.ui.theme.Ownify
import com.ownify.android.ui.theme.OwnifyType

/*
 * Uitdagingen on the phone (docs/CHALLENGES.md): the pieces a challenge is
 * drawn with — a person, the bars, a tile, a row — and its two pages, as
 * lib/challenges-ui.php, pages/challenges.php and pages/challenge-detail.php
 * draw them on the website (assets/css/challenges.css). Every figure and
 * sentence is the server's.
 */

/** Tags for tests. */
internal const val CHALLENGE_TILE = "challenge-tile"
internal const val CHALLENGE_ITEM = "challenge-item"

/** `.btn.ch-primary`: the page's own actions, in the Health colour. */
internal val PrimaryLook: BtnLook
    get() = BtnLook.Primary

/** `.btn.ch-danger`: leaving, in the attention colour. */
internal val DangerLook: BtnLook
    get() = BtnLook.Caution

// --------------------------------------------------------------- people

/**
 * `.ch-av`: a person's circle — their picture as the boards show it, over
 * their initial in their colour. Yours has the green hairline; one invited
 * and not in it yet is faded.
 */
@Composable
internal fun ChallengeAvatar(person: ChallengePerson, size: Dp = 26.dp, pending: Boolean = false, ring: Boolean = false) {
    val accent = Accent.of(person.accent).color
    Box(
        Modifier
            .size(size)
            .then(if (ring) Modifier.border(2.dp, Ownify.ground(1f), CircleShape) else Modifier)
            .padding(if (ring) 1.dp else 0.dp)
            .clip(CircleShape)
            .alpha(if (pending) 0.7f else 1f)
            .background(Ownify.fill(0.07f))
            .border(1.dp, if (person.you) Ownify.HealthEdge else Ownify.GlassHairline, CircleShape),
        contentAlignment = Alignment.Center
    ) {
        T(person.initial, OwnifyType.style((size.value * 0.4f).sp, FontWeight.SemiBold, Ownify.tint(accent, 0.78f)), maxLines = 1)
        person.avatar?.let { AvatarPhoto(it, Modifier.padding(1.dp).clip(CircleShape)) }
    }
}

/** `.ch-faces`: the first few people overlapping, and "+3". Read out as "5 deelnemers". */
@Composable
internal fun FacesRow(faces: Faces, pending: Boolean = false) {
    Row(
        Modifier.clearAndSetSemantics { contentDescription = faces.label },
        verticalAlignment = Alignment.CenterVertically
    ) {
        faces.people.forEachIndexed { i, p ->
            Box(Modifier.offset(x = (-5).dp * i)) { ChallengeAvatar(p, 30.dp, pending, ring = true) }
        }
        if (faces.more > 0) {
            T("+${faces.more}", OwnifyType.style(Ownify.FsFootnote, color = Ownify.TextMuted, tabular = true), Modifier.offset(x = (-5).dp * faces.people.size).padding(start = Ownify.Space2), maxLines = 1)
        }
    }
}

// ----------------------------------------------------------------- bars

/** The quiet ink of the others, in order (`.ch-seg.is-tone-1` … `-4`). */
internal fun toneColor(tone: Int): Color = Ownify.ink(
    when (tone) {
        1 -> 0.50f
        2 -> 0.38f
        3 -> 0.28f
        else -> 0.20f
    }
)

/**
 * The bar under a challenge. Samen (`.ch-stack`): everybody's piece of the
 * target side by side, a 2 dp gap between them, you in the colour and the
 * others in quiet ink, then what is still to go. Wedstrijd (`.ch-lead`): your
 * total against the leader's, growing in over 900 ms once it is drawn.
 */
@Composable
internal fun ChallengeBarView(bar: ChallengeBar, accent: Accent, big: Boolean = false, modifier: Modifier = Modifier) {
    val height = if (big) 14.dp else 8.dp
    val shape = RoundedCornerShape(50)
    val label = Modifier.clearAndSetSemantics { contentDescription = bar.label }

    if (bar.type == "lead") {
        val reduced = LocalReducedMotion.current || LocalStillMotion.current
        val grow = remember { Animatable(if (reduced) bar.value else 0f) }
        LaunchedEffect(bar.value, reduced) {
            if (reduced) grow.snapTo(bar.value) else grow.animateTo(bar.value, tween(900, easing = Ownify.EaseOut))
        }
        Box(modifier.fillMaxWidth().height(height).clip(shape).background(Ownify.fill(0.08f)).then(label)) {
            Box(
                Modifier
                    .fillMaxWidth()
                    .fillMaxHeight()
                    .graphicsLayer {
                        transformOrigin = TransformOrigin(0f, 0.5f)
                        scaleX = grow.value.coerceIn(0f, 1f)
                    }
                    .clip(shape)
                    .background(Brush.horizontalGradient(listOf(accent.color, accent.light)))
            )
        }
        return
    }

    Row(
        modifier.fillMaxWidth().height(height).clip(shape).then(label),
        horizontalArrangement = Arrangement.spacedBy(2.dp)
    ) {
        for (segment in bar.segments) {
            if (segment.part <= 0f) continue
            Box(
                Modifier
                    .weight(segment.part)
                    .widthIn(min = 2.dp)
                    .fillMaxHeight()
                    .then(
                        if (segment.you) Modifier.background(Brush.horizontalGradient(listOf(accent.color, accent.light)))
                        else Modifier.background(toneColor(segment.tone))
                    )
            )
        }
        if (bar.rest > 0f) {
            Box(Modifier.weight(bar.rest).fillMaxHeight().background(Ownify.fill(0.08f)))
        }
    }
}

// ---------------------------------------------------------------- lines

/** `.ch-kind`: "SAMEN · TRAININGEN" with the metric's icon in the colour. */
@Composable
internal fun KindLine(icon: String, text: String, accent: Accent, modifier: Modifier = Modifier) {
    Row(modifier, verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
        OwnifyIcons.named(icon)?.let { JIcon(it, size = 14.dp, color = accent.color) }
        T(text, JStyle.Caption, maxLines = 1, ellipsis = true, uppercase = true)
    }
}

/** A line in two weights: the figure in the primary colour, the rest muted. */
@Composable
private fun TwoWeights(strong: String, rest: String, style: androidx.compose.ui.text.TextStyle, modifier: Modifier = Modifier) {
    Row(modifier, verticalAlignment = Alignment.Bottom) {
        T(strong, style.copy(fontWeight = FontWeight.SemiBold, color = Ownify.TextPrimary), Modifier.alignByBaseline(), maxLines = 1)
        if (rest.isNotEmpty()) T(" $rest", style, Modifier.alignByBaseline(), maxLines = 1, ellipsis = true)
    }
}

/** `.ch-foot`: the faces on the left, a line on the right. */
@Composable
private fun Foot(faces: Faces, text: String) {
    Row(
        Modifier.fillMaxWidth().padding(top = Ownify.Space1),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)
    ) {
        FacesRow(faces)
        T(text, JStyle.Footnote, Modifier.weight(1f), align = TextAlign.End, maxLines = 2, ellipsis = true)
    }
}

// ------------------------------------------------- marks and actions

/**
 * `.icon-tile--solid.icon-tile--neutral`: the white solid mark on the
 * neutral grey, as Overzicht's tiles; [small] is `.ch-icon--sm` (30, icon 15).
 */
@Composable
internal fun NeutralTile(icon: String, small: Boolean = false, modifier: Modifier = Modifier) {
    val vector = OwnifyIcons.solid(icon) ?: OwnifyIcons.solidTarget
    NeutralTile(vector, if (small) Ownify.TileSmall else 36.dp, if (small) Ownify.TileRadiusSmall else Ownify.TileRadius, if (small) 15.dp else 18.dp, modifier)
}

@Composable
internal fun NeutralTile(icon: ImageVector, size: Dp = 36.dp, radius: Dp = Ownify.TileRadius, iconSize: Dp = 18.dp, modifier: Modifier = Modifier) {
    IconTile(icon, modifier, size = size, radius = radius, iconSize = iconSize, color = Color.White, background = Ownify.Neutral, border = Ownify.Neutral)
}

/**
 * `.ch-acts`: Nieuwe uitdaging and Code invoeren side by side — two glass
 * tiles of one size (the sync capsule's material), each with the same solid
 * neutral mark: a plus for making one, a hash for joining with a code.
 */
@Composable
internal fun ChallengeActions(page: Map<String, String>, modifier: Modifier = Modifier) {
    val shell = LocalShell.current
    Row(modifier.fillMaxWidth().height(IntrinsicSize.Max), horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
        ActionTile(page["create"].orEmpty(), Modifier.weight(1f).fillMaxHeight(), onClick = { shell.open(Overlay.ChallengeCreate()) }) {
            NeutralTile(OwnifyIcons.plus, size = 30.dp, radius = 10.dp, iconSize = 16.dp)
        }
        ActionTile(page["join"].orEmpty(), Modifier.weight(1f).fillMaxHeight(), onClick = { shell.open(Overlay.ChallengeCode) }) {
            NeutralTile(OwnifyIcons.hash, size = 30.dp, radius = 10.dp, iconSize = 16.dp)
        }
    }
}

/** `.ch-act`: one of the two. */
@Composable
private fun ActionTile(label: String, modifier: Modifier, onClick: () -> Unit, mark: @Composable () -> Unit) {
    val narrow = LocalScreen.current.narrow
    val interaction = remember { MutableInteractionSource() }
    Row(
        modifier
            .press(interaction)
            .heightIn(min = 60.dp)
            .glass(RoundedCornerShape(Ownify.RadiusMd), GlassLook(shadow = 20.dp, lift = 8.dp))
            .clickable(interaction, indication = null, role = Role.Button, onClick = onClick)
            .semantics(mergeDescendants = true) { }
            .padding(horizontal = if (narrow) Ownify.Space2 else Ownify.Space3, vertical = Ownify.Space2),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(if (narrow) Ownify.Space2 else Ownify.Space3)
    ) {
        Box(Modifier.clearAndSetSemantics { }) { mark() }
        T(label, OwnifyType.style(Ownify.FsLabel, FontWeight.SemiBold, Ownify.TextPrimary, tracking = (-0.01).em, lineHeight = 1.2.em), Modifier.weight(1f))
    }
}

/** `.ch-art`: the empty target — dashed rings round the Uitdagingen mark. */
@Composable
internal fun TargetArt(modifier: Modifier = Modifier) {
    Box(
        modifier
            .size(112.dp)
            .clearAndSetSemantics { }
            .drawBehind {
                val dash = PathEffect.dashPathEffect(floatArrayOf(3.dp.toPx(), 3.dp.toPx()))
                val stroke = 1.dp.toPx()
                drawCircle(Ownify.fill(0.16f), radius = size.minDimension / 2 - stroke / 2, style = Stroke(stroke, pathEffect = dash))
                drawCircle(Ownify.fill(0.24f), radius = size.minDimension / 2 - 18.dp.toPx() - stroke / 2, style = Stroke(stroke, pathEffect = dash))
            },
        contentAlignment = Alignment.Center
    ) {
        NeutralTile(OwnifyIcons.solidTarget, size = 48.dp, radius = 16.dp, iconSize = 24.dp)
    }
}

// ------------------------------------------------- Community's stack

/**
 * `.cm-fan`: the challenge that ends first on top, up to two more as the
 * edges of cards behind it — each 12 dp narrower a side and 9 dp lower.
 */
@Composable
internal fun ChallengeFan(running: List<ChallengeView>, onOpen: (ChallengeView) -> Unit) {
    val front = running.firstOrNull() ?: return
    val behind = minOf(2, running.size - 1)
    val shape = RoundedCornerShape(Ownify.RadiusMd)
    Box(Modifier.fillMaxWidth().padding(bottom = 9.dp * behind)) {
        for (n in behind downTo 1) {
            Box(
                Modifier
                    .matchParentSize()
                    .padding(horizontal = 12.dp * n)
                    .offset(y = 9.dp * n)
                    .alpha(1f - n * 0.3f)
                    // Only its foot: the card in front is see-through (a Note)
                    // and the layer must not show through it.
                    .drawWithContent { clipRect(top = size.height - 9.dp.toPx()) { this@drawWithContent.drawContent() } }
                    .note(NoteTone.Positive, shape = shape)
            )
        }
        FanFront(front) { onOpen(front) }
    }
}

/** `.cm-fan__front`: its mark, name and kind, its bar, where it stands and who is in it. */
@Composable
private fun FanFront(view: ChallengeView, onClick: () -> Unit) {
    val accent = Accent.of(view.accent)
    val shape = RoundedCornerShape(Ownify.RadiusMd)
    val interaction = remember { MutableInteractionSource() }
    // A Note in the health tone (docs/THEME.md): Ownify's green wash and
    // edge, inside the card like every other shape in a card (30.0).
    Column(
        Modifier
            .fillMaxWidth()
            .press(interaction)
            .note(NoteTone.Positive, shape = shape)
            .clickable(interaction, indication = null, role = Role.Button, onClick = onClick)
            .semantics { testTag = CHALLENGE_TILE }
            .cssPadding(PaddingValues(Ownify.Space4), border = 1.dp)
    ) {
        Column(verticalArrangement = Arrangement.spacedBy(Ownify.Space3)) {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)) {
                NeutralTile(view.icon, small = true)
                T(view.name, OwnifyType.style(Ownify.FsLabel, FontWeight.SemiBold, lineHeight = 1.25.em), Modifier.weight(1f), maxLines = 2, ellipsis = true)
                Chip(view.kindLabel)
            }
            ChallengeBarView(view.bar, accent)
            Row(
                Modifier.fillMaxWidth().padding(top = Ownify.Space1),
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)
            ) {
                TwoWeights(view.tile.strong, view.tile.rest, OwnifyType.style(Ownify.FsSmall, color = Ownify.TextSecondary, tabular = true), Modifier.weight(1f))
                FacesRow(view.faces)
            }
        }
    }
}

// ------------------------------------------------- Uitdagingen's cards

/**
 * `.ch-card2`: a challenge you are in, on Uitdagingen. Samen: where you
 * stand together, the bar in everybody's pieces and who brought what.
 * Wedstrijd: the top three, each with their bar against the leader.
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
internal fun ChallengeCard(view: ChallengeView, onClick: () -> Unit) {
    val accent = Accent.of(view.accent)
    val interaction = remember { MutableInteractionSource() }
    JCard(
        Modifier
            .fillMaxWidth()
            .press(interaction)
            .clickable(interaction, indication = null, role = Role.Button, onClick = onClick)
            .semantics { testTag = CHALLENGE_ITEM },
        padding = PaddingValues(Ownify.Space4)
    ) {
        Column(verticalArrangement = Arrangement.spacedBy(Ownify.Space3)) {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)) {
                NeutralTile(view.icon)
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    T(view.name, OwnifyType.style(Ownify.FsHeadline, FontWeight.SemiBold, tracking = (-0.015).em, lineHeight = 1.25.em))
                    T(view.deadline, JStyle.Footnote)
                }
            }
            if (!view.versus) {
                FigLine {
                    TwoWeights(view.line.strong, view.line.rest, OwnifyType.style(Ownify.FsSmall, color = Ownify.TextMuted, tabular = true))
                    T(view.line.sub, OwnifyType.style(Ownify.FsSmall, color = Ownify.TextMuted), align = TextAlign.End)
                }
                ChallengeBarView(view.bar, accent, big = true)
                FlowRow(horizontalArrangement = Arrangement.spacedBy(Ownify.Space3), verticalArrangement = Arrangement.spacedBy(Ownify.Space1)) {
                    view.people.forEach { person ->
                        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                            Box(Modifier.size(8.dp).clip(RoundedCornerShape(2.5.dp)).background(if (person.you) accent.color else toneColor(person.tone)))
                            T(person.label, JStyle.Footnote, maxLines = 1)
                            T(person.valueText, OwnifyType.style(Ownify.FsFootnote, FontWeight.SemiBold, Ownify.TextPrimary, tabular = true), maxLines = 1)
                        }
                    }
                }
            } else {
                Column(verticalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
                    view.people.take(3).forEach { person ->
                        Row(
                            Modifier.fillMaxWidth().clearAndSetSemantics { contentDescription = "${person.rank}. ${person.label}, ${person.valueText}" },
                            verticalAlignment = Alignment.CenterVertically,
                            horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)
                        ) {
                            T(person.rank.toString(), OwnifyType.style(Ownify.FsFootnote, color = Ownify.TextMuted, tabular = true), Modifier.width(14.dp), maxLines = 1)
                            ChallengeAvatar(person, 24.dp)
                            Box(Modifier.weight(1f)) { PersonBar(person.share, if (person.you) accent.color else Ownify.ink(0.42f), top = 0.dp) }
                            T(person.valueText, OwnifyType.style(Ownify.FsSmall, FontWeight.SemiBold, tabular = true), maxLines = 1)
                        }
                    }
                }
            }
        }
    }
}

/**
 * `.ch-item__fig`: the figure on the left and its note on the right, on one
 * line while both fit; otherwise the note on a line of its own, still at
 * the right (`flex-wrap: wrap` with `margin-left: auto`).
 */
@Composable
private fun FigLine(content: @Composable () -> Unit) {
    Layout(content, Modifier.fillMaxWidth()) { measurables, constraints ->
        val gap = Ownify.Space3.roundToPx()
        val rowGap = 2.dp.roundToPx()
        val loose = constraints.copy(minWidth = 0)
        val figure = measurables[0].measure(loose)
        val note = measurables.getOrNull(1)?.measure(loose)
        val width = constraints.maxWidth
        val inline = note == null || figure.width + gap + note.width <= width
        val height = if (note == null) figure.height else if (inline) maxOf(figure.height, note.height) else figure.height + rowGap + note.height
        layout(width, height) {
            figure.place(0, 0)
            note?.place(width - note.width, if (inline) 0 else figure.height + rowGap)
        }
    }
}

/** `.ch-idea`: a way to begin — Nieuwe uitdaging with this kind and metric chosen. */
@Composable
private fun IdeaRow(idea: ChallengeIdea, icon: String, onClick: () -> Unit) {
    val interaction = remember { MutableInteractionSource() }
    Row(
        Modifier
            .fillMaxWidth()
            .press(interaction)
            .heightIn(min = 56.dp)
            .clip(RoundedCornerShape(Ownify.RadiusMd))
            .background(Ownify.InsetFill)
            .dashedBorder(Ownify.SlotEdge, Ownify.RadiusMd)
            .clickable(interaction, indication = null, role = Role.Button, onClick = onClick)
            .semantics(mergeDescendants = true) { }
            .padding(horizontal = Ownify.Space3, vertical = Ownify.Space2),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)
    ) {
        NeutralTile(icon, small = true, modifier = Modifier.clearAndSetSemantics { })
        Column(Modifier.weight(1f)) {
            T(idea.title, OwnifyType.style(Ownify.FsLabel, FontWeight.SemiBold))
            T(idea.sub, JStyle.Tiny)
        }
        JIcon(OwnifyIcons.plus, size = 16.dp, color = Ownify.TextFaint)
    }
}

/** A dashed rounded outline (`border-style: dashed`). */
internal fun Modifier.dashedBorder(color: Color, radius: Dp): Modifier = this.drawBehind {
    val stroke = 1.dp.toPx()
    drawRoundRect(
        color = color,
        topLeft = Offset(stroke / 2, stroke / 2),
        size = Size(size.width - stroke, size.height - stroke),
        cornerRadius = CornerRadius(radius.toPx()),
        style = Stroke(width = stroke, pathEffect = PathEffect.dashPathEffect(floatArrayOf(4.dp.toPx(), 3.dp.toPx())))
    )
}

/** `.ch-item`: a challenge on Uitdagingen — one you are in, with its bar and figures. */
@Composable
internal fun ChallengeItem(view: ChallengeView, onClick: () -> Unit) {
    val accent = Accent.of(view.accent)
    val interaction = remember { MutableInteractionSource() }
    JCard(
        Modifier
            .fillMaxWidth()
            .press(interaction)
            .clickable(interaction, indication = null, role = Role.Button, onClick = onClick)
            .semantics { testTag = CHALLENGE_ITEM },
        padding = PaddingValues(Ownify.Space4)
    ) {
        Column(verticalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
            KindLine(view.icon, view.kindLine, accent)
            T(view.name, JStyle.Subtitle)
            ChallengeBarView(view.bar, accent)
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)) {
                TwoWeights(view.line.strong, view.line.rest, OwnifyType.style(Ownify.FsSmall, color = Ownify.TextMuted, tabular = true))
                T(view.line.sub, JStyle.Meta, Modifier.weight(1f), align = TextAlign.End)
            }
            Foot(view.faces, view.deadline)
        }
    }
}

/** An invitation on Uitdagingen: dashed, and Bekijken. */
@Composable
internal fun InviteItem(invite: ChallengeInvite, onClick: () -> Unit) {
    val accent = Accent.of(invite.accent)
    val interaction = remember { MutableInteractionSource() }
    JCard(
        Modifier
            .fillMaxWidth()
            .press(interaction)
            .clickable(interaction, indication = null, role = Role.Button, onClick = onClick)
            .semantics { testTag = CHALLENGE_ITEM },
        style = CardStyle.Quiet,
        padding = PaddingValues(Ownify.Space4)
    ) {
        Column(verticalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
            KindLine(invite.icon, invite.kindLine, accent)
            T(invite.name, JStyle.Subtitle)
            Foot(invite.faces, invite.fromText)
        }
    }
}

/** `.ch-invite`: an invitation on Community — who sent it, for what, and Bekijken. */
@Composable
internal fun InviteBanner(invite: ChallengeInvite, see: String, onReview: () -> Unit) {
    Row(
        Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(Ownify.RadiusMd))
            .background(Ownify.InsetFill)
            .dashedBorder(Ownify.SlotEdge, Ownify.RadiusMd)
            .padding(Ownify.Space3),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)
    ) {
        val who = invite.inviter
        // The red dot on the invitation's picture (docs/NOTICES.md).
        val dot = "invite:" + invite.id
        if (who != null) Box(Modifier.notice(dot, NoticePlace.Circle)) { ChallengeAvatar(who, 32.dp) }
        else NeutralTile(OwnifyIcons.solidTarget, modifier = Modifier.notice(dot))
        val text = if (who != null) "${who.name} ${invite.inviteText}" else invite.inviteText
        T(text, JStyle.Lede, Modifier.weight(1f))
        Btn(see, onClick = onReview)
    }
}

/** `.ch-person`: one person on a challenge's page. Samen: their colour key; Wedstrijd: their place and bar. */
@Composable
private fun PersonRow(person: ChallengePerson, versus: Boolean, accent: Accent, divided: Boolean) {
    Column(Modifier.fillMaxWidth()) {
        if (divided) Box(Modifier.fillMaxWidth().height(1.dp).background(Ownify.GlassHairline))
        Row(
            Modifier
                .fillMaxWidth()
                .heightIn(min = 52.dp)
                .padding(vertical = Ownify.Space2)
                .clearAndSetSemantics {
                    contentDescription = buildString {
                        if (versus) append("${person.rank}. ")
                        append(person.label).append(", ").append(person.valueText)
                        if (!versus && person.shareText.isNotEmpty()) append(", ").append(person.shareText)
                    }
                },
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)
        ) {
            if (versus) {
                T(
                    person.rank.toString(),
                    OwnifyType.style(Ownify.FsSmall, FontWeight.SemiBold, if (person.you) Ownify.TextPrimary else Ownify.TextMuted, tabular = true),
                    Modifier.widthIn(min = 22.dp),
                    align = TextAlign.End,
                    maxLines = 1
                )
            } else {
                Box(Modifier.size(10.dp).clip(RoundedCornerShape(3.dp)).background(if (person.you) accent.color else toneColor(person.tone)))
            }
            ChallengeAvatar(person, 32.dp)
            Column(Modifier.weight(1f)) {
                T(
                    person.label,
                    OwnifyType.style(Ownify.FsLabel, if (person.you) FontWeight.SemiBold else FontWeight.Normal, if (person.you) Ownify.TextPrimary else Ownify.TextSecondary),
                    maxLines = 1,
                    ellipsis = true
                )
                if (versus) {
                    PersonBar(person.share, if (person.you) accent.color else Ownify.ink(0.42f))
                }
            }
            Row(verticalAlignment = Alignment.Bottom) {
                T(person.valueText, OwnifyType.style(Ownify.FsLabel, FontWeight.SemiBold, tabular = true), Modifier.alignByBaseline(), maxLines = 1)
                if (!versus) {
                    T(person.shareText, OwnifyType.style(Ownify.FsTiny, FontWeight.Medium, Ownify.TextMuted), Modifier.alignByBaseline().padding(start = 4.dp), maxLines = 1)
                }
            }
        }
    }
}

@Composable
private fun PersonBar(share: Float, color: Color, top: Dp = 6.dp) {
    val reduced = LocalReducedMotion.current || LocalStillMotion.current
    val grow = remember { Animatable(if (reduced) share else 0f) }
    LaunchedEffect(share, reduced) {
        if (reduced) grow.snapTo(share) else grow.animateTo(share, tween(900, easing = Ownify.EaseOut))
    }
    Box(Modifier.padding(top = top).fillMaxWidth().height(4.dp).clip(RoundedCornerShape(50)).background(Ownify.fill(0.08f))) {
        Box(
            Modifier
                .fillMaxWidth()
                .fillMaxHeight()
                .graphicsLayer {
                    transformOrigin = TransformOrigin(0f, 0.5f)
                    scaleX = grow.value.coerceIn(0f, 1f)
                }
                .background(color)
        )
    }
}

/** `.ch-eyebrow`: a group's name on Uitdagingen. */
@Composable
private fun GroupEyebrow(text: String) {
    T(text, JStyle.Caption, Modifier.fillMaxWidth().padding(horizontal = Ownify.Space2).padding(bottom = Ownify.Space2).semantics { heading() }, uppercase = true)
}

// ---------------------------------------------------------- Uitdagingen

/**
 * Uitdagingen (pages/challenges.php): Nieuwe uitdaging and Code invoeren as
 * two tiles, then the invitations, Samen, Wedstrijd and the ones that ended.
 * With nothing at all, the empty target and three ways to begin, each
 * opening Nieuwe uitdaging with its kind and metric chosen.
 */
@Composable
fun ChallengesDetail(data: AppData, scroll: ScrollState) {
    val shell = LocalShell.current
    val ch = data.challenges
    val page = ch.copy["page"]
    val title = page["title"].orEmpty()
    val open = { view: ChallengeView -> shell.openDetail(com.ownify.android.ui.app.Detail.Challenge(view.id, overList = true)) }

    DetailColumn(scroll, back = page["back"].orEmpty(), backAria = tr("Terug naar Community"), title = title, titleBeside = true) {
        Column(Modifier.fillMaxWidth().reveal().heroName()) {
            PageIntro(title, page["lede"])
        }
        ChallengeActions(page, Modifier.reveal())

        if (ch.invites.isEmpty() && ch.running.isEmpty() && ch.ended.isEmpty()) {
            JCard(Modifier.fillMaxWidth().reveal(), padding = PaddingValues(start = Ownify.Space4, end = Ownify.Space4, top = Ownify.Space5, bottom = Ownify.Space4)) {
                Column(Modifier.fillMaxWidth(), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
                    TargetArt()
                    T(page["empty_title"].orEmpty(), JStyle.Subtitle, Modifier.padding(top = Ownify.Space2).semantics { heading() }, align = TextAlign.Center)
                    T(page["ideas_lede"].orEmpty(), JStyle.Meta, align = TextAlign.Center)
                    Column(Modifier.fillMaxWidth().padding(top = Ownify.Space3), verticalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
                        ch.copy.ideas().forEach { idea ->
                            IdeaRow(idea, ch.metric(idea.metric)?.icon ?: "target") {
                                shell.open(Overlay.ChallengeCreate(idea.kind, idea.metric))
                            }
                        }
                    }
                }
            }
        }

        if (ch.invites.isNotEmpty()) {
            Group(page["invites"].orEmpty()) {
                ch.invites.forEach { invite -> InviteItem(invite) { shell.open(Overlay.ChallengeReview(invite.id)) } }
            }
        }
        val together = ch.running.filter { !it.versus }
        val versus = ch.running.filter { it.versus }
        if (together.isNotEmpty()) Group(page["together"].orEmpty()) { together.forEach { ChallengeCard(it) { open(it) } } }
        if (versus.isNotEmpty()) Group(page["versus"].orEmpty()) { versus.forEach { ChallengeCard(it) { open(it) } } }
        if (ch.ended.isNotEmpty()) Group(page["ended"].orEmpty()) { ch.ended.forEach { ChallengeItem(it) { open(it) } } }
    }
}

/** `.ch-group`: a group's name and its cards. */
@Composable
private fun Group(title: String, content: @Composable () -> Unit) {
    Column(Modifier.fillMaxWidth().reveal()) {
        GroupEyebrow(title)
        Column(verticalArrangement = Arrangement.spacedBy(Ownify.Space2)) { content() }
    }
}

// ------------------------------------------------------- a challenge

/**
 * One challenge (pages/challenge-detail.php): what it is, where it stands,
 * the people, bringing someone in, and the small print.
 */
@Composable
fun ChallengeDetail(data: AppData, view: ChallengeView, overList: Boolean, scroll: ScrollState) {
    val shell = LocalShell.current
    val context = LocalContext.current
    val copy = data.challenges.copy["detail"]
    val accent = Accent.of(view.accent)

    DetailColumn(
        scroll,
        back = if (overList) copy["back"].orEmpty() else tr("Community"),
        backAria = if (overList) tr("Terug naar Uitdagingen") else tr("Terug naar Community"),
        title = view.name,
        titleBeside = true
    ) {
        // 1 — what it is
        Column(Modifier.fillMaxWidth().padding(horizontal = Ownify.Space2).reveal(), verticalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
            KindLine(view.icon, view.kindLine, accent)
            val long = view.name.length > 32
            T(
                view.name,
                if (long) OwnifyType.style(Ownify.FsTitle2, FontWeight.Bold, tracking = (-0.015).em, lineHeight = 1.25.em) else JStyle.LargeTitle,
                Modifier.heroName().semantics { heading() }
            )
            T(view.subtitle, JStyle.Meta)
        }

        // 2 — where it stands
        JCard(Modifier.fillMaxWidth().reveal()) {
            Row(verticalAlignment = Alignment.Bottom, horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
                T(view.big.figure, OwnifyType.style(Ownify.FsScore, FontWeight.Bold, tracking = (-0.05).em, lineHeight = 1.em, tabular = true), Modifier.alignByBaseline(), maxLines = 1)
                T(view.big.of, OwnifyType.style(Ownify.FsBody, color = Ownify.TextSecondary), Modifier.alignByBaseline().weight(1f, fill = false))
            }
            ChallengeBarView(view.bar, accent, big = true, modifier = Modifier.padding(top = Ownify.Space4))
            Row(Modifier.padding(top = Ownify.Space3), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
                Box(
                    Modifier.size(6.dp).background(
                        when (view.big.tone) {
                            "ahead" -> Ownify.Health
                            "behind" -> Ownify.Attention
                            else -> Ownify.ink(0.32f)
                        },
                        CircleShape
                    )
                )
                T(view.big.line, JStyle.Lede)
            }
        }

        // 3 — the people
        JCard(Modifier.fillMaxWidth().reveal()) {
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                T(view.peopleTitle, JStyle.Eyebrow, Modifier.weight(1f).semantics { heading() })
                T(view.countText, JStyle.Footnote, maxLines = 1)
            }
            Column(Modifier.padding(top = Ownify.Space2)) {
                view.people.forEachIndexed { i, p -> PersonRow(p, view.versus, accent, divided = i > 0) }
            }
            if (view.pending.isNotEmpty() && view.pendingText != null) {
                Box(Modifier.fillMaxWidth().padding(top = Ownify.Space2).height(1.dp).background(Ownify.GlassHairline))
                Row(Modifier.padding(top = Ownify.Space3), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
                    Row(Modifier.clearAndSetSemantics { }) {
                        view.pending.take(4).forEachIndexed { i, p -> Box(Modifier.offset(x = (-5).dp * i)) { ChallengeAvatar(p, 26.dp, pending = true) } }
                    }
                    T(view.pendingText, JStyle.Footnote)
                }
            }
        }

        // 4 — bringing someone in, while it runs
        val code = view.code
        if (code != null) {
            JCard(Modifier.fillMaxWidth().reveal()) {
                T(copy["share_title"].orEmpty(), JStyle.Eyebrow, Modifier.semantics { heading() })
                Row(Modifier.fillMaxWidth().padding(top = Ownify.Space3), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
                    T(
                        code.toCharArray().joinToString(" "),
                        OwnifyType.style(Ownify.FsTitle2, FontWeight.SemiBold, tracking = 0.12.em, tabular = true).copy(fontFamily = FontFamily.Monospace),
                        Modifier.weight(1f).clearAndSetSemantics { contentDescription = code.toCharArray().joinToString(" ") },
                        maxLines = 1
                    )
                    Btn("", onClick = { copyCode(context, code) }, contentDescription = copy["copy"]) {
                        JIcon(OwnifyIcons.copy, size = 18.dp, color = Ownify.TextPrimary)
                    }
                    Btn("", onClick = { shareCode(context, view.shareText ?: code, code) }, contentDescription = copy["share"]) {
                        JIcon(OwnifyIcons.share, size = 18.dp, color = Ownify.TextPrimary)
                    }
                }
                InviteRow(copy["invite"].orEmpty(), copy["invite_hint"].orEmpty()) { shell.open(Overlay.ChallengeInvite(view.id)) }
            }
        }

        // 5 — the small print
        T(view.note, JStyle.Footnote, Modifier.fillMaxWidth().padding(horizontal = Ownify.Space2).reveal())
        if (view.canLeave) {
            Box(Modifier.fillMaxWidth().reveal(), contentAlignment = Alignment.Center) {
                LinkButton(copy["leave"].orEmpty()) { shell.open(Overlay.ChallengeLeave(view.id)) }
            }
        }
    }
}

/** `.settings-row` in the share card: Vrienden uitnodigen, and what that means. */
@Composable
private fun InviteRow(label: String, hint: String, onClick: () -> Unit) {
    val interaction = remember { MutableInteractionSource() }
    Row(
        Modifier
            .fillMaxWidth()
            .padding(top = Ownify.Space3)
            .press(interaction)
            .clickable(interaction, indication = null, role = Role.Button, onClick = onClick)
            .heightIn(min = 52.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)
    ) {
        IconTile(OwnifyIcons.userPlus, size = 30.dp, radius = 10.dp, iconSize = 16.dp)
        Column(Modifier.weight(1f)) {
            T(label, OwnifyType.style(Ownify.FsLabel, FontWeight.Medium))
            T(hint, JStyle.Meta)
        }
        JIcon(OwnifyIcons.chevronRight, size = 16.dp, color = Ownify.TextMuted)
    }
}

/** `.btn--link`: a quiet text button. */
@Composable
internal fun LinkButton(label: String, onClick: () -> Unit) {
    val interaction = remember { MutableInteractionSource() }
    Box(
        Modifier
            .press(interaction)
            .heightIn(min = 44.dp)
            .clickable(interaction, indication = null, role = Role.Button, onClick = onClick)
            .semantics { role = Role.Button }
            .padding(horizontal = Ownify.Space3),
        contentAlignment = Alignment.Center
    ) {
        T(label, OwnifyType.style(Ownify.FsSmall, FontWeight.SemiBold, Ownify.TextMuted), maxLines = 1)
    }
}

// --------------------------------------------------- copying, sharing

/** Kopiëren: the code on the clipboard, and a toast that says so. */
internal fun copyCode(context: Context, code: String) {
    runCatching {
        val clipboard = context.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager
        clipboard.setPrimaryClip(ClipData.newPlainText("Ownify", code))
    }
    OwnifyToast.show(trf("Code %s gekopieerd", code))
}

/** Delen: the phone's own share sheet, with the sentence the server wrote. */
internal fun shareCode(context: Context, text: String, code: String) {
    val send = Intent(Intent.ACTION_SEND).apply {
        type = "text/plain"
        putExtra(Intent.EXTRA_TEXT, text)
    }
    val chooser = Intent.createChooser(send, null).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
    if (runCatching { context.startActivity(chooser) }.isFailure) copyCode(context, code)
}
