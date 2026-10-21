package com.ownify.android.ui.screens.community

import com.ownify.android.i18n.tr
import androidx.compose.foundation.ScrollState
import androidx.compose.ui.graphics.PathEffect
import androidx.compose.ui.graphics.drawscope.Stroke
import com.ownify.android.ui.design.Btn
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.asPaddingValues
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawing
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.runtime.Composable
import androidx.compose.runtime.SideEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.setValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.drawBehind
import androidx.compose.ui.draw.drawWithContent
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.addOutline
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.layout.onGloballyPositioned
import androidx.compose.ui.layout.positionInParent
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.platform.LocalGraphicsContext
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Spacer
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.onClick
import androidx.compose.ui.semantics.role
import com.ownify.android.ui.app.AvatarPhoto
import com.ownify.android.ui.app.LocalShell
import com.ownify.android.ui.app.Overlay
import com.ownify.android.ui.design.press
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.zIndex
import com.ownify.android.data.AppData
import com.ownify.android.data.BoardEntry
import com.ownify.android.data.Community
import com.ownify.android.ui.app.dockClearance
import com.ownify.android.ui.app.Detail
import com.ownify.android.ui.app.DetailHeader
import com.ownify.android.ui.app.PageColumn
import com.ownify.android.ui.app.detailHeaderSpace
import com.ownify.android.ui.design.PageIntro
import com.ownify.android.ui.design.reveal
import com.ownify.android.ui.theme.Accent
import androidx.compose.ui.graphics.vector.ImageVector
import com.ownify.android.ui.design.BoxShadow
import com.ownify.android.ui.design.IconTile
import com.ownify.android.ui.design.JCard
import com.ownify.android.ui.design.JIcon
import com.ownify.android.ui.design.JStyle
import com.ownify.android.ui.design.OwnifyIcons
import com.ownify.android.ui.design.LocalScreen
import com.ownify.android.ui.design.RangeSwitch
import com.ownify.android.ui.design.T
import com.ownify.android.ui.design.chWidth
import com.ownify.android.ui.design.cssPadding
import com.ownify.android.ui.design.drawBoxShadows
import com.ownify.android.ui.design.drawBorder
import com.ownify.android.ui.theme.Ownify
import com.ownify.android.ui.theme.OwnifyType
import java.text.DecimalFormat
import java.text.DecimalFormatSymbols
import java.util.zip.CRC32

/**
 * Community (pages/community.php): what is happening with the people you
 * know, content first. Two cards ("Twee vensters"), each a way in and a
 * picture of what is behind it — Ranglijst, your friends' top three this
 * month on a podium, opening the full board ([LeaderboardDetail]); with
 * nobody to compare to, the open places add a friend. Uitdagingen, your
 * invitations, the challenge ending soonest on top of a stack of the others,
 * and Nieuwe uitdaging and Code invoeren side by side, opening Uitdagingen
 * ([ChallengesDetail]). Challenges have nothing to do with points: the line
 * under the cards says so.
 */
@Composable
fun CommunityPage(data: AppData, scroll: ScrollState) {
    val shell = LocalShell.current
    val community = data.community
    val preview = community.preview
    val ch = data.challenges
    val addFriend = { shell.open(Overlay.Account(FRIENDS_ADD)) }

    PageColumn(scroll) {
        PageIntro(community.title, null, Modifier.reveal())

        // Ranglijst: the top three on a podium, you among them.
        JCard(Modifier.fillMaxWidth().reveal(), padding = PaddingValues(Ownify.Space4)) {
            Column(verticalArrangement = Arrangement.spacedBy(Ownify.Space4)) {
                CardHead(OwnifyIcons.solidRanking, preview.title, preview.sub) { shell.openDetail(Detail.Leaderboard) }
                Podium(preview.rows, addFriend)
                if (preview.rows.size <= 1) {
                    Btn(tr("Vriend toevoegen"), onClick = addFriend, modifier = Modifier.fillMaxWidth(), icon = OwnifyIcons.userPlus)
                } else if (preview.line.isNotEmpty()) {
                    T(preview.line, OwnifyType.style(Ownify.FsSmall, color = Ownify.TextSecondary))
                }
            }
        }

        if (ch.available) {
            // Uitdagingen: what you are in, as a stack of cards, and the two ways to start one.
            JCard(Modifier.fillMaxWidth().reveal(), padding = PaddingValues(Ownify.Space4)) {
                Column(verticalArrangement = Arrangement.spacedBy(Ownify.Space4)) {
                    CardHead(OwnifyIcons.solidTarget, ch.copy.text("page", "title"), ch.summary) { shell.openDetail(Detail.Challenges) }
                    ch.invites.forEach { invite ->
                        InviteBanner(invite, ch.copy.text("review", "see")) { shell.open(Overlay.ChallengeReview(invite.id)) }
                    }
                    if (ch.running.isNotEmpty()) {
                        ChallengeFan(ch.running) { view -> shell.openDetail(Detail.Challenge(view.id, overList = false)) }
                    } else {
                        // `.cm-none`: nothing yet, the empty target.
                        Column(
                            Modifier.fillMaxWidth().padding(top = Ownify.Space3, bottom = Ownify.Space1),
                            horizontalAlignment = Alignment.CenterHorizontally,
                            verticalArrangement = Arrangement.spacedBy(Ownify.Space3)
                        ) {
                            TargetArt()
                            T(ch.copy.text("page", "empty_title"), OwnifyType.style(Ownify.FsLabel, FontWeight.SemiBold), align = TextAlign.Center)
                        }
                    }
                    ChallengeActions(ch.copy["page"])
                }
            }
            T(ch.note, JStyle.Footnote, Modifier.fillMaxWidth().padding(horizontal = Ownify.Space2).reveal())
        }
    }
}

/**
 * `.cm-head`: a card's way in — its solid mark, title and one line, and a
 * chevron. The whole row is the control.
 */
@Composable
private fun CardHead(icon: ImageVector, title: String, sub: String, onClick: () -> Unit) {
    val interaction = remember { MutableInteractionSource() }
    Row(
        Modifier
            .fillMaxWidth()
            .heightIn(min = 44.dp)
            .press(interaction)
            .clickable(interaction, indication = null, role = Role.Button, onClick = onClick)
            .semantics(mergeDescendants = true) { },
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)
    ) {
        NeutralTile(icon)
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            T(title, JStyle.Subtitle, Modifier.semantics { heading() })
            if (sub.isNotEmpty()) T(sub, JStyle.Footnote)
        }
        JIcon(OwnifyIcons.chevronRight, size = 16.dp, color = Ownify.TextFaint)
    }
}

/** The steps' heights: first 84, second 64, third 50. */
private val STEPS = mapOf(1 to 84.dp, 2 to 64.dp, 3 to 50.dp)

/**
 * `.cm-podium`: second, first, third — whoever holds each place this
 * month, you with a ring; a place nobody holds yet is an open spot that
 * adds a friend.
 */
@Composable
private fun Podium(rows: List<BoardEntry>, onAdd: () -> Unit) {
    val narrow = LocalScreen.current.narrow
    val places = mutableMapOf<Int, BoardEntry>()
    rows.forEach { if (it.rank in 1..3 && it.rank !in places) places[it.rank] = it }
    Row(
        Modifier
            .fillMaxWidth()
            .padding(start = if (narrow) 0.dp else Ownify.Space2, end = if (narrow) 0.dp else Ownify.Space2, top = Ownify.Space1)
            .semantics { contentDescription = tr("De top drie van je vrienden deze maand") },
        horizontalArrangement = Arrangement.spacedBy(if (narrow) Ownify.Space2 else Ownify.Space3),
        verticalAlignment = Alignment.Bottom
    ) {
        for (place in listOf(2, 1, 3)) {
            PodiumColumn(place, places[place], onAdd, Modifier.weight(1f))
        }
    }
}

@Composable
private fun PodiumColumn(place: Int, entry: BoardEntry?, onAdd: () -> Unit, modifier: Modifier) {
    val you = entry?.self == true
    val stepShape = RoundedCornerShape(topStart = 12.dp, topEnd = 12.dp, bottomStart = 4.dp, bottomEnd = 4.dp)
    Column(modifier, horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(4.dp)) {
        if (entry != null) {
            val name = entry.name.ifEmpty { "—" }
            val label = if (you) tr("Jij") else name
            Column(
                Modifier.fillMaxWidth().clearAndSetSemantics { contentDescription = "$place. $label, ${points(entry.points)}" },
                horizontalAlignment = Alignment.CenterHorizontally,
                verticalArrangement = Arrangement.spacedBy(4.dp)
            ) {
                PodiumAvatar(entry, you)
                T(label, OwnifyType.style(Ownify.FsFootnote, FontWeight.SemiBold), Modifier.padding(top = 2.dp), maxLines = 1, ellipsis = true)
                T(points(entry.points), OwnifyType.style(Ownify.FsTiny, color = Ownify.TextMuted, tabular = true), maxLines = 1)
            }
        } else {
            val interaction = remember { MutableInteractionSource() }
            val label = tr("Vriend toevoegen")
            Box(
                Modifier
                    .size(44.dp)
                    .press(interaction)
                    .clip(CircleShape)
                    .drawBehind {
                        val stroke = 1.5.dp.toPx()
                        drawCircle(
                            Ownify.fill(0.3f),
                            radius = size.minDimension / 2 - stroke / 2,
                            style = Stroke(stroke, pathEffect = PathEffect.dashPathEffect(floatArrayOf(4.dp.toPx(), 3.dp.toPx())))
                        )
                    }
                    .clickable(interaction, indication = null, role = Role.Button, onClick = onAdd)
                    .clearAndSetSemantics {
                        contentDescription = label
                        role = Role.Button
                        onClick { onAdd(); true }
                    },
                contentAlignment = Alignment.Center
            ) { JIcon(OwnifyIcons.plus, size = 18.dp, color = Ownify.TextMuted) }
        }
        Box(
            Modifier
                .padding(top = 4.dp)
                .fillMaxWidth()
                .height(STEPS.getValue(place))
                .clip(stepShape)
                .then(
                    when {
                        entry == null -> Modifier.dashedBorder(Ownify.fill(0.22f), 12.dp)
                        else -> Modifier.background(Ownify.fill(if (you) 0.14f else 0.07f)).border(1.dp, Ownify.GlassHairline, stepShape)
                    }
                )
                .clearAndSetSemantics { },
            contentAlignment = Alignment.Center
        ) {
            T(place.toString(), OwnifyType.style(Ownify.FsSmall, FontWeight.Bold, if (you) Ownify.TextPrimary else Ownify.TextSecondary), maxLines = 1)
        }
    }
}

/** `.cm-podium__av`: 44, the initial in its colour under the picture; yours ringed in the text colour. */
@Composable
private fun PodiumAvatar(entry: BoardEntry, you: Boolean) {
    val accent = Accent.of(avatarAccent(entry.name)).color
    val ground = Ownify.ground(1f)
    val ring = Ownify.TextPrimary
    Box(
        Modifier
            .size(44.dp)
            .drawBehind {
                if (you) {
                    // box-shadow: 0 0 0 2px ground, 0 0 0 3.5px text-primary
                    drawCircle(ring, radius = size.minDimension / 2 + 3.5.dp.toPx())
                    drawCircle(ground, radius = size.minDimension / 2 + 2.dp.toPx())
                }
            }
            .clip(CircleShape)
            .background(Ownify.fill(0.07f))
            .border(1.dp, Ownify.GlassHairline, CircleShape),
        contentAlignment = Alignment.Center
    ) {
        if (entry.name.isNotEmpty()) {
            T(initial(entry.name), OwnifyType.style(Ownify.FsLabel, FontWeight.SemiBold, Ownify.tint(accent, 0.78f)), maxLines = 1)
        } else {
            JIcon(OwnifyIcons.user, size = 18.dp, color = Ownify.TextMuted)
        }
        entry.avatar?.let { AvatarPhoto(it, Modifier.padding(1.dp).clip(CircleShape)) }
    }
}

/**
 * Ranglijst (pages/leaderboard.php): the leaderboard on its own page, behind
 * Community's card, as it always was. The title and the two switches stay
 * put under the back pill; only the board scrolls, and each of the six
 * boards (scope × period) keeps its own place.
 */
@Composable
fun LeaderboardDetail(data: AppData) {
    val community = data.community
    val screen = LocalScreen.current
    val shell = LocalShell.current
    var scope by rememberSaveable { mutableStateOf(community.defaultScope) }
    var period by rememberSaveable { mutableStateOf(community.defaultPeriod) }
    val scrolls = remember { HashMap<String, ScrollState>() }

    Box(Modifier.fillMaxSize()) {
        Column(Modifier.fillMaxSize()) {
            // .community__head — under the back pill, never scrolling.
            Column(
                Modifier
                    .align(Alignment.CenterHorizontally)
                    .width(screen.shell)
                    .padding(top = detailHeaderSpace(), bottom = Ownify.Space3),
                verticalArrangement = Arrangement.spacedBy(Ownify.Space2)
            ) {
                T(
                    community.labels["board_head"] ?: community.title,
                    JStyle.LargeTitle,
                    Modifier.padding(horizontal = Ownify.Space2).padding(top = Ownify.Space2, bottom = Ownify.Space1).semantics { heading() }
                )
                RangeSwitch(
                    options = community.scopes.map { it.key to it.label },
                    selected = scope,
                    onSelect = { scope = it },
                    wide = true,
                    optionPadding = if (screen.narrow) Ownify.Space1 else Ownify.Space2,
                    label = tr("Ranglijst kiezen")
                )
                RangeSwitch(
                    options = community.periods,
                    selected = period,
                    onSelect = { period = it },
                    wide = true,
                    optionPadding = if (screen.narrow) Ownify.Space1 else Ownify.Space2,
                    label = tr("Periode kiezen")
                )
            }

            val key = "$scope/$period"
            Board(
                community, scope, period, scrolls.getOrPut(key) { ScrollState(0) }, Modifier.weight(1f).fillMaxWidth(),
                // Vrienden only — Nederland never has it, whatever the period.
                addFriends = community.addFriends?.takeIf { scope == "friends" },
                onAddFriends = { shell.open(Overlay.Account(FRIENDS_ADD)) }
            )
        }
        DetailHeader(tr("Community"), tr("Terug naar Community"), scrolled = false, onBack = shell::closeDetail)
    }
}

/**
 * One board (components/leaderboard-board.php): the rows, and your own —
 * which sits in its place while it is on screen and docks to the edge it
 * would otherwise leave (`position: sticky`, one row, never a copy).
 */
@Composable
private fun Board(
    community: Community,
    scope: String,
    period: String,
    scroll: ScrollState,
    modifier: Modifier,
    addFriends: String? = null,
    onAddFriends: () -> Unit = {}
) {
    val screen = LocalScreen.current
    val density = LocalDensity.current
    val board = community.boards[scope]?.get(period)
    val config = community.scopes.firstOrNull { it.key == scope }
    val entries = board?.entries.orEmpty()
    val youRank = board?.youRank
    val youInList = youRank != null && youRank <= (config?.limit ?: Int.MAX_VALUE)
    val safeBottom = WindowInsets.safeDrawing.asPaddingValues().calculateBottomPadding()
    // --dock-clear: the board scrolls clear of the dock and the floating control.
    val dockClear = dockClearance(safeBottom) + Ownify.Space6 + Ownify.Space3
    var viewport by remember { mutableFloatStateOf(0f) }

    Box(modifier.onGloballyPositioned { viewport = it.size.height.toFloat() }) {
        Column(
            Modifier
                .fillMaxSize()
                .verticalScroll(scroll)
                .padding(bottom = dockClear),
            horizontalAlignment = Alignment.CenterHorizontally
        ) {
            // .board-add: 8 above, then the rows' own 4 to #1 (or 12 to the empty card).
            if (addFriends != null) {
                Box(Modifier.width(screen.shell).padding(top = Ownify.Space2)) {
                    AddFriendsRow(addFriends, onAddFriends)
                }
            }
            if (entries.isNotEmpty()) {
                // .board-list: 8 above, 9 below, 4 between rows. Your row is kept inside it.
                val stick = remember(scroll) { Sticky(scroll) }
                SideEffect {
                    with(density) {
                        stick.viewport = viewport
                        stick.top = Ownify.Space2.toPx()
                        stick.bottom = dockClear.toPx() + Ownify.Space2.toPx()
                    }
                }
                Column(
                    Modifier
                        .width(screen.shell)
                        .padding(top = if (addFriends != null) Ownify.Space1 else Ownify.Space2, bottom = Ownify.Space2 + 1.dp)
                        // After the padding: the list's content box, where a sticky row must stay.
                        .onGloballyPositioned {
                            stick.listTop = it.positionInParent().y
                            stick.listHeight = it.size.height.toFloat()
                        },
                    verticalArrangement = Arrangement.spacedBy(Ownify.Space1)
                ) {
                    entries.forEachIndexed { i, entry ->
                        BoardRow(community, entry, topThree = i < 3, sticky = if (entry.self) stick else null)
                    }
                    if (!youInList) {
                        GapRow(community.labels["outside"].orEmpty())
                        BoardRow(
                            community,
                            BoardEntry(youRank ?: 0, community.youName, board?.youPoints, self = true, avatar = community.youAvatar),
                            topThree = false,
                            sticky = stick,
                            rankText = youRank?.toString() ?: "—"
                        )
                    }
                }
            } else {
                // .board-empty, then your row on its own.
                JCard(
                    Modifier.width(screen.shell).padding(top = if (addFriends != null) Ownify.Space3 else 0.dp),
                    padding = PaddingValues(horizontal = Ownify.Space5, vertical = Ownify.Space6)
                ) {
                    Column(Modifier.fillMaxWidth(), horizontalAlignment = Alignment.CenterHorizontally) {
                        IconTile(OwnifyIcons.community)
                        T(config?.emptyTitle.orEmpty(), JStyle.Subtitle, Modifier.padding(top = Ownify.Space4).semantics { heading() }, align = TextAlign.Center)
                        T(
                            config?.emptyBody.orEmpty(),
                            JStyle.Meta,
                            Modifier.padding(top = Ownify.Space2).widthIn(max = chWidth(JStyle.Meta, 30f)),
                            align = TextAlign.Center
                        )
                    }
                }
                T(
                    community.labels["you_hint"].orEmpty(),
                    JStyle.Caption,
                    Modifier
                        .width(screen.shell)
                        .padding(top = Ownify.Space5, bottom = Ownify.Space2)
                        .padding(horizontal = Ownify.Space2),
                    uppercase = true
                )
                Column(Modifier.width(screen.shell)) {
                    BoardRow(
                        community,
                        BoardEntry(youRank ?: 0, community.youName, board?.youPoints, self = true, avatar = community.youAvatar),
                        topThree = true,
                        sticky = null,
                        rankText = youRank?.toString() ?: "—"
                    )
                }
            }
        }
    }
}

/**
 * Where your row may be drawn: in its own place, or docked 8 from the top
 * or 8 above the dock's room — never outside its list. One per board, its
 * measurements snapshot state, so the row moves as soon as any of them does;
 * until the list has been measured the row stays in its place.
 */
private class Sticky(val scroll: ScrollState) {
    var viewport by mutableFloatStateOf(0f)
    var top by mutableFloatStateOf(0f)
    var bottom by mutableFloatStateOf(0f)

    /** The list's content box in the scroller: its top and its height. */
    var listTop by mutableFloatStateOf(0f)
    var listHeight by mutableFloatStateOf(0f)

    fun shift(natural: Float, height: Float): Float {
        if (listHeight <= 0f || viewport <= 0f || height <= 0f) return 0f
        val y = listTop + natural
        val lowest = scroll.value + top
        val highest = scroll.value + viewport - bottom - height
        var shown = y
        if (shown < lowest) shown = minOf(lowest, listTop + listHeight - height)
        if (shown > highest) shown = maxOf(highest, listTop)
        return shown - y
    }
}

/**
 * `components/leaderboard-row.php`: position, picture, name, points. The
 * picture is the person's profile picture when they show it on the boards —
 * the server leaves it out otherwise — over their monogram, which is what
 * shows while it loads. Your own row is lit in the green; docked, it takes a
 * solid glass of its own.
 */
@Composable
private fun BoardRow(
    community: Community,
    entry: BoardEntry,
    topThree: Boolean,
    sticky: Sticky?,
    rankText: String = entry.rank.toString(),
    /** On Community's card: 44 high instead of 52. */
    compact: Boolean = false
) {
    val narrow = LocalScreen.current.narrow
    val shadows = LocalGraphicsContext.current.shadowContext
    val shape = RoundedCornerShape(Ownify.RadiusMd)
    val you = entry.self
    val name = entry.name.takeIf { it.isNotEmpty() }
    val unit = community.labels["unit"].orEmpty()
    val accent = Accent.of(avatarAccent(entry.name)).color
    var natural by remember { mutableFloatStateOf(0f) }
    var height by remember { mutableFloatStateOf(0f) }
    val lit = topThree || you

    Row(
        Modifier
            .zIndex(if (sticky != null) 2f else 0f)
            .onGloballyPositioned {
                natural = it.positionInParent().y
                height = it.size.height.toFloat()
            }
            .graphicsLayer { translationY = sticky?.shift(natural, height) ?: 0f }
            .fillMaxWidth()
            .heightIn(min = if (compact) 44.dp else 52.dp)
            .drawWithContent {
                val outline = shape.createOutline(size, layoutDirection, this)
                if (sticky != null) {
                    drawBoxShadows(shape, listOf(BoxShadow(y = 10.dp, blur = 26.dp, color = Ownify.shade(0.32f))), shadows, outline)
                }
                val path = androidx.compose.ui.graphics.Path().apply { addOutline(outline) }
                drawPath(
                    path,
                    when {
                        sticky != null -> Brush.verticalGradient(listOf(Ownify.BoardYouFrom, Ownify.BoardYouTo))
                        you -> Brush.verticalGradient(listOf(Ownify.Health.copy(alpha = 0.10f), Ownify.Health.copy(alpha = 0.045f)))
                        else -> androidx.compose.ui.graphics.SolidColor(Ownify.lift(0.035f))
                    }
                )
                if (sticky != null) {
                    drawBoxShadows(shape, listOf(BoxShadow(y = 1.dp, blur = 0.dp, color = Ownify.ShadowInset, inset = true)), shadows, outline)
                }
                if (you) drawBorder(outline, Ownify.HealthEdge)
                drawContent()
            }
            .clearAndSetSemantics {
                contentDescription = (if (you) community.labels["you_hint"].orEmpty() + ": " else "") +
                    "$rankText. ${name ?: "—"}, ${points(entry.points)} $unit"
            }
            // `.board-row`'s border is there on every row, transparent but for yours: it takes room.
            .cssPadding(PaddingValues(horizontal = if (narrow) Ownify.Space2 else Ownify.Space3, vertical = Ownify.Space1), border = 1.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(if (narrow) Ownify.Space2 else Ownify.Space3)
    ) {
        T(
            rankText,
            OwnifyType.style(Ownify.FsSmall, if (lit) FontWeight.SemiBold else FontWeight.Normal, if (lit) Ownify.TextPrimary else Ownify.TextMuted, tabular = true),
            Modifier.width(if (narrow) 30.4.dp else 36.dp),
            align = TextAlign.End,
            maxLines = 1
        )
        Box(
            Modifier
                .size(if (narrow) 30.dp else 34.dp)
                .clip(CircleShape)
                .background(Ownify.fill(0.07f))
                .border(1.dp, Ownify.GlassHairline, CircleShape),
            contentAlignment = Alignment.Center
        ) {
            if (name != null) {
                T(initial(name), OwnifyType.style(Ownify.FsSmall, FontWeight.SemiBold, Ownify.tint(accent, 0.78f)), maxLines = 1)
            } else {
                JIcon(OwnifyIcons.user, size = 16.dp, color = Ownify.TextMuted)
            }
            // `.board-row__photo`: inside the hairline, over the monogram.
            entry.avatar?.let { AvatarPhoto(it, Modifier.padding(1.dp).clip(CircleShape)) }
        }
        T(
            name ?: "—",
            OwnifyType.style(if (narrow) Ownify.FsSmall else Ownify.FsLabel, if (you) FontWeight.SemiBold else FontWeight.Normal, if (you) Ownify.TextPrimary else Ownify.TextSecondary),
            Modifier.weight(1f),
            maxLines = 1,
            ellipsis = true
        )
        Row {
            val empty = entry.points == null
            T(
                points(entry.points),
                OwnifyType.style(
                    if (narrow) Ownify.FsSmall else Ownify.FsLabel,
                    if (empty) FontWeight.Medium else FontWeight.SemiBold,
                    if (empty) Ownify.TextMuted else Ownify.TextPrimary,
                    tabular = true
                ),
                Modifier.alignByBaseline(),
                maxLines = 1
            )
            T(unit, OwnifyType.style(Ownify.FsTiny, FontWeight.Medium, Ownify.TextMuted), Modifier.alignByBaseline().padding(start = 3.dp), maxLines = 1)
        }
    }
}

/** The account panel's view for [AccountPanel]: its Vrienden page with Vriend toevoegen open. */
const val FRIENDS_ADD = "friends-add"

/**
 * `.board-row--add`: Vrienden toevoegen, a row of the board's own kind — the
 * same height, glass, corners, padding, grid and type as [BoardRow], the
 * user-plus in the avatar's circle and the rank's column left empty so it
 * lines up with the rows under it. Settles under the finger as `.press` does.
 */
@Composable
private fun AddFriendsRow(label: String, onClick: () -> Unit) {
    val narrow = LocalScreen.current.narrow
    val shape = RoundedCornerShape(Ownify.RadiusMd)
    val interaction = remember { MutableInteractionSource() }

    Row(
        Modifier
            .press(interaction)
            .fillMaxWidth()
            .heightIn(min = 52.dp)
            .clip(shape)
            .background(Ownify.lift(0.035f))
            .clickable(interaction, indication = null, role = Role.Button, onClick = onClick)
            .clearAndSetSemantics {
                contentDescription = label
                role = Role.Button
                onClick { onClick(); true }
            }
            .cssPadding(PaddingValues(horizontal = if (narrow) Ownify.Space2 else Ownify.Space3, vertical = Ownify.Space1), border = 1.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(if (narrow) Ownify.Space2 else Ownify.Space3)
    ) {
        Spacer(Modifier.width(if (narrow) 30.4.dp else 36.dp))
        Box(
            Modifier
                .size(if (narrow) 30.dp else 34.dp)
                .clip(CircleShape)
                .background(Ownify.fill(0.07f))
                .border(1.dp, Ownify.GlassHairline, CircleShape),
            contentAlignment = Alignment.Center
        ) {
            JIcon(OwnifyIcons.userPlus, size = 16.dp, color = Ownify.TextMuted)
        }
        T(
            label,
            OwnifyType.style(if (narrow) Ownify.FsSmall else Ownify.FsLabel, FontWeight.Normal, Ownify.TextSecondary),
            Modifier.weight(1f),
            maxLines = 1,
            ellipsis = true
        )
    }
}

/** `.board-gap`: a dotted rule either side of "the rest of the list". */
@Composable
private fun GapRow(label: String) {
    Row(
        Modifier.fillMaxWidth().padding(vertical = Ownify.Space2).clearAndSetSemantics { },
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)
    ) {
        Dots(Modifier.weight(1f))
        T(label, JStyle.Tiny, maxLines = 1)
        Dots(Modifier.weight(1f))
    }
}

@Composable
private fun Dots(modifier: Modifier) {
    Box(
        modifier
            .height(1.dp)
            .drawBehind {
                var x = 0f
                val on = 2.dp.toPx()
                val period = 8.dp.toPx()
                while (x < size.width) {
                    drawRect(Ownify.ink(0.16f), topLeft = Offset(x, 0f), size = Size(minOf(on, size.width - x), size.height))
                    x += period
                }
            }
    )
}

/** `community_points()`: whole points with the Dutch thousands dot, or a dash. */
internal fun points(value: Int?): String {
    if (value == null) return "—"
    val symbols = DecimalFormatSymbols().apply {
        groupingSeparator = '.'
        decimalSeparator = ','
    }
    return DecimalFormat("#,##0", symbols).format(value)
}

/** `community_initial()`: the first letter, upper-cased. */
internal fun initial(name: String): String {
    val trimmed = name.trim()
    if (trimmed.isEmpty()) return ""
    return String(Character.toChars(trimmed.codePointAt(0))).uppercase(java.util.Locale.ROOT)
}

/** `community_avatar_accent()`: a stable one of the three category colours per name (crc32 % 3). */
internal fun avatarAccent(name: String?): String {
    val crc = CRC32().apply { update((name ?: "").toByteArray(Charsets.UTF_8)) }.value
    return listOf("sleep", "nutrition", "training")[(crc % 3).toInt()]
}
