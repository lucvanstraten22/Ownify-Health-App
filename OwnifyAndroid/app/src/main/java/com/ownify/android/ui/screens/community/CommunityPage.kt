package com.ownify.android.ui.screens.community

import com.ownify.android.i18n.tr
import androidx.compose.foundation.ScrollState
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
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.drawBehind
import androidx.compose.ui.draw.drawWithContent
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
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
import com.ownify.android.ui.app.LocalOwnedAreas
import com.ownify.android.ui.app.PageColumn
import com.ownify.android.ui.app.detailHeaderSpace
import com.ownify.android.ui.app.ownsGestures
import com.ownify.android.ui.design.PageIntro
import com.ownify.android.ui.design.reveal
import com.ownify.android.ui.theme.Accent
import androidx.compose.foundation.gestures.FlingBehavior
import androidx.compose.foundation.gestures.ScrollScope
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.IntrinsicSize
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.rememberScrollState
import androidx.compose.runtime.DisposableEffect
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.layout.layout
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
 * know, content first. Two cards, each a way in and a preview of what is
 * behind it — Ranglijst, the top of your friends' board this month, opening
 * the full board ([LeaderboardDetail]); Uitdagingen, your invitations and the
 * challenges you are in on a carousel, opening Uitdagingen
 * ([ChallengesDetail]). Challenges have nothing to do with points: the line
 * under the cards says so.
 */
@Composable
fun CommunityPage(data: AppData, scroll: ScrollState) {
    val shell = LocalShell.current
    val community = data.community
    val preview = community.preview
    val ch = data.challenges

    PageColumn(scroll) {
        PageIntro(community.title, null, Modifier.reveal())

        // Ranglijst
        JCard(Modifier.fillMaxWidth().reveal(), padding = PaddingValues(Ownify.Space4)) {
            CardHead(OwnifyIcons.ranking, Accent.HEALTH, preview.title, preview.sub) { shell.openDetail(Detail.Leaderboard) }
            if (preview.rows.isNotEmpty()) {
                Column(Modifier.padding(top = Ownify.Space3), verticalArrangement = Arrangement.spacedBy(Ownify.Space1)) {
                    preview.rows.forEachIndexed { i, entry -> BoardRow(community, entry, topThree = i < 3, sticky = null, compact = true) }
                }
            }
            if (preview.line.isNotEmpty()) {
                Row(Modifier.padding(top = Ownify.Space3), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
                    Box(Modifier.size(6.dp).background(Ownify.Health, CircleShape))
                    T(preview.line, JStyle.Lede)
                }
            }
        }

        if (ch.available) {
            // Uitdagingen
            JCard(Modifier.fillMaxWidth().reveal(), padding = PaddingValues(Ownify.Space4)) {
                CardHead(OwnifyIcons.flag, Accent.TRAINING, ch.copy.text("page", "title"), ch.summary) { shell.openDetail(Detail.Challenges) }
                ch.invites.forEach { invite ->
                    Box(Modifier.padding(top = Ownify.Space3)) {
                        InviteBanner(invite, ch.copy.text("review", "see")) { shell.open(Overlay.ChallengeReview(invite.id)) }
                    }
                }
                ChallengeRail(data, Modifier.padding(top = Ownify.Space3))
            }
            T(ch.note, JStyle.Footnote, Modifier.fillMaxWidth().padding(horizontal = Ownify.Space2).reveal())
        }
    }
}

/**
 * `.ch-head`: a card's way in — its icon, title and one line, and Toon alles.
 * The whole row is the control.
 */
@Composable
private fun CardHead(icon: ImageVector, accent: Accent, title: String, sub: String, onClick: () -> Unit) {
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
        IconTile(icon, color = accent.color)
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            T(title, JStyle.Subtitle, Modifier.semantics { heading() })
            if (sub.isNotEmpty()) T(sub, JStyle.Footnote)
        }
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(2.dp)) {
            T(tr("Toon alles"), OwnifyType.style(Ownify.FsSmall, FontWeight.SemiBold, Ownify.TextSecondary), maxLines = 1)
            JIcon(OwnifyIcons.chevronRight, size = 16.dp, color = Ownify.TextSecondary)
        }
    }
}

/**
 * `.ch-rail`: the challenges on now, a carousel that snaps tile by tile and
 * bleeds to the card's edge so the next tile peeks in; Nieuwe uitdaging is
 * its last tile, the whole width when it is alone. Every tile as tall as the
 * tallest. It reads its own horizontal drags (`data-gesture-own`): the page
 * swipe starts outside it.
 */
@Composable
private fun ChallengeRail(data: AppData, modifier: Modifier) {
    val shell = LocalShell.current
    val ch = data.challenges
    val screen = LocalScreen.current
    val density = LocalDensity.current
    val owned = LocalOwnedAreas.current
    val ownKey = remember { Any() }
    DisposableEffect(ownKey) { onDispose { owned.set(ownKey, null) } }
    val bleed = Ownify.Space4
    val gap = Ownify.Space2
    val alone = ch.running.isEmpty()
    // The card's inside: the column less the card's padding and border.
    val inner = screen.shell - Ownify.Space4 * 2 - 2.dp
    val tileWidth = if (alone) inner else inner * 0.76f
    val scroll = rememberScrollState()
    val step = with(density) { (tileWidth + gap).toPx() }
    val snap = remember(scroll, step) { TileSnap(scroll, step) }

    Row(
        modifier
            .layout { measurable, constraints ->
                val extra = bleed.roundToPx() * 2
                val placeable = measurable.measure(constraints.copy(minWidth = constraints.maxWidth + extra, maxWidth = constraints.maxWidth + extra))
                layout(constraints.maxWidth, placeable.height) { placeable.place(-extra / 2, 0) }
            }
            .ownsGestures(owned, ownKey)
            .horizontalScroll(scroll, flingBehavior = snap)
            .height(IntrinsicSize.Max)
            .padding(horizontal = bleed)
            .semantics { contentDescription = tr("Lopende uitdagingen") },
        horizontalArrangement = Arrangement.spacedBy(gap)
    ) {
        ch.running.forEach { view ->
            ChallengeTile(view, Modifier.width(tileWidth).fillMaxHeight()) {
                shell.openDetail(Detail.Challenge(view.id, overList = false))
            }
        }
        NewChallengeTile(ch.copy.text("page", "create"), Modifier.width(tileWidth).fillMaxHeight()) {
            shell.open(Overlay.ChallengeCreate)
        }
    }
}

/**
 * Where a flick of the carousel comes to rest: projected from its speed as
 * a scroll decelerates (decelerationRate .99), then to the nearest tile's
 * start, the speed carried into the settle (`scroll-snap-type: x mandatory`).
 */
private class TileSnap(private val scroll: androidx.compose.foundation.ScrollState, private val step: Float) : FlingBehavior {
    override suspend fun ScrollScope.performFling(initialVelocity: Float): Float {
        val from = scroll.value.toFloat()
        val projected = from + initialVelocity / 1000f * 0.99f / (1f - 0.99f)
        val target = (kotlin.math.round(projected / step) * step).coerceIn(0f, scroll.maxValue.toFloat())
        var last = from
        androidx.compose.animation.core.animate(
            from, target, initialVelocity,
            androidx.compose.animation.core.spring(dampingRatio = 1f, stiffness = androidx.compose.animation.core.Spring.StiffnessMediumLow)
        ) { value, _ ->
            scrollBy(value - last)
            last = value
        }
        return 0f
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
