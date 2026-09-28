package com.healthapp.android.ui.screens.community

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
import androidx.compose.ui.graphics.RectangleShape
import androidx.compose.ui.graphics.addOutline
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.layout.onGloballyPositioned
import androidx.compose.ui.layout.positionInParent
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.platform.LocalGraphicsContext
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.zIndex
import com.healthapp.android.data.AppData
import com.healthapp.android.data.BoardEntry
import com.healthapp.android.data.Community
import com.healthapp.android.ui.app.dockClearance
import com.healthapp.android.ui.app.headerSpace
import com.healthapp.android.ui.design.BoxShadow
import com.healthapp.android.ui.design.IconTile
import com.healthapp.android.ui.design.JCard
import com.healthapp.android.ui.design.JIcon
import com.healthapp.android.ui.design.JStyle
import com.healthapp.android.ui.design.JoluIcons
import com.healthapp.android.ui.design.LocalScreen
import com.healthapp.android.ui.design.RangeSwitch
import com.healthapp.android.ui.design.T
import com.healthapp.android.ui.design.chWidth
import com.healthapp.android.ui.design.drawBoxShadows
import com.healthapp.android.ui.design.drawBorder
import com.healthapp.android.ui.theme.Accent
import com.healthapp.android.ui.theme.Jolu
import com.healthapp.android.ui.theme.JoluType
import java.text.DecimalFormat
import java.text.DecimalFormatSymbols
import java.util.zip.CRC32

/**
 * Community (pages/community.php): a leaderboard and nothing competing with
 * it. The title and the two switches stay put; only the board scrolls, and
 * each of the six boards (scope × period) keeps its own place.
 */
@Composable
fun CommunityPage(data: AppData) {
    val community = data.community
    val screen = LocalScreen.current
    var scope by rememberSaveable { mutableStateOf(community.defaultScope) }
    var period by rememberSaveable { mutableStateOf(community.defaultPeriod) }
    val scrolls = remember { HashMap<String, ScrollState>() }

    Column(Modifier.fillMaxSize()) {
        // .community__head — the header's room above the title, since this head never scrolls.
        Column(
            Modifier
                .align(Alignment.CenterHorizontally)
                .width(screen.shell)
                .padding(top = headerSpace(), bottom = Jolu.Space3),
            verticalArrangement = Arrangement.spacedBy(Jolu.Space2)
        ) {
            T(
                community.title,
                JStyle.Section,
                Modifier.padding(horizontal = Jolu.Space2).padding(bottom = Jolu.Space1).semantics { heading() }
            )
            RangeSwitch(
                options = community.scopes.map { it.key to it.label },
                selected = scope,
                onSelect = { scope = it },
                wide = true,
                optionPadding = if (screen.narrow) Jolu.Space1 else Jolu.Space2,
                label = "Ranglijst kiezen"
            )
            RangeSwitch(
                options = community.periods,
                selected = period,
                onSelect = { period = it },
                wide = true,
                optionPadding = if (screen.narrow) Jolu.Space1 else Jolu.Space2,
                label = "Periode kiezen"
            )
        }

        val key = "$scope/$period"
        Board(community, scope, period, scrolls.getOrPut(key) { ScrollState(0) }, Modifier.weight(1f).fillMaxWidth())
    }
}

/**
 * One board (components/leaderboard-board.php): the rows, and your own —
 * which sits in its place while it is on screen and docks to the edge it
 * would otherwise leave (`position: sticky`, one row, never a copy).
 */
@Composable
private fun Board(community: Community, scope: String, period: String, scroll: ScrollState, modifier: Modifier) {
    val screen = LocalScreen.current
    val density = LocalDensity.current
    val board = community.boards[scope]?.get(period)
    val config = community.scopes.firstOrNull { it.key == scope }
    val entries = board?.entries.orEmpty()
    val youRank = board?.youRank
    val youInList = youRank != null && youRank <= (config?.limit ?: Int.MAX_VALUE)
    val safeBottom = WindowInsets.safeDrawing.asPaddingValues().calculateBottomPadding()
    // --dock-clear: the board scrolls clear of the dock and the floating control.
    val dockClear = dockClearance(safeBottom) + Jolu.Space6 + Jolu.Space3
    var viewport by remember { mutableFloatStateOf(0f) }

    Box(modifier.onGloballyPositioned { viewport = it.size.height.toFloat() }) {
        Column(
            Modifier
                .fillMaxSize()
                .verticalScroll(scroll)
                .padding(bottom = dockClear),
            horizontalAlignment = Alignment.CenterHorizontally
        ) {
            if (entries.isNotEmpty()) {
                // .board-list: 8 above, 9 below, 4 between rows. Your row is kept inside it.
                val stick = remember(scroll) { Sticky(scroll) }
                SideEffect {
                    with(density) {
                        stick.viewport = viewport
                        stick.top = Jolu.Space2.toPx()
                        stick.bottom = dockClear.toPx() + Jolu.Space2.toPx()
                    }
                }
                Column(
                    Modifier
                        .width(screen.shell)
                        .padding(top = Jolu.Space2, bottom = Jolu.Space2 + 1.dp)
                        // After the padding: the list's content box, where a sticky row must stay.
                        .onGloballyPositioned {
                            stick.listTop = it.positionInParent().y
                            stick.listHeight = it.size.height.toFloat()
                        },
                    verticalArrangement = Arrangement.spacedBy(Jolu.Space1)
                ) {
                    entries.forEachIndexed { i, entry ->
                        BoardRow(community, entry, topThree = i < 3, sticky = if (entry.self) stick else null)
                    }
                    if (!youInList) {
                        GapRow(community.labels["outside"].orEmpty())
                        BoardRow(
                            community,
                            BoardEntry(youRank ?: 0, community.youName, board?.youPoints, self = true, avatar = null),
                            topThree = false,
                            sticky = stick,
                            rankText = youRank?.toString() ?: "—"
                        )
                    }
                }
            } else {
                // .board-empty, then your row on its own.
                JCard(
                    Modifier.width(screen.shell),
                    padding = PaddingValues(horizontal = Jolu.Space5, vertical = Jolu.Space6)
                ) {
                    Column(Modifier.fillMaxWidth(), horizontalAlignment = Alignment.CenterHorizontally) {
                        IconTile(JoluIcons.community)
                        T(config?.emptyTitle.orEmpty(), JStyle.Subtitle, Modifier.padding(top = Jolu.Space4).semantics { heading() }, align = TextAlign.Center)
                        T(
                            config?.emptyBody.orEmpty(),
                            JStyle.Meta,
                            Modifier.padding(top = Jolu.Space2).widthIn(max = chWidth(JStyle.Meta, 30f)),
                            align = TextAlign.Center
                        )
                    }
                }
                T(
                    community.labels["you_hint"].orEmpty(),
                    JStyle.Caption,
                    Modifier
                        .width(screen.shell)
                        .padding(top = Jolu.Space5, bottom = Jolu.Space2)
                        .padding(horizontal = Jolu.Space2),
                    uppercase = true
                )
                Column(Modifier.width(screen.shell)) {
                    BoardRow(
                        community,
                        BoardEntry(youRank ?: 0, community.youName, board?.youPoints, self = true, avatar = null),
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
 * `components/leaderboard-row.php`: position, monogram, name, points. Your
 * own row is lit in the green; docked, it takes a solid glass of its own.
 */
@Composable
private fun BoardRow(
    community: Community,
    entry: BoardEntry,
    topThree: Boolean,
    sticky: Sticky?,
    rankText: String = entry.rank.toString()
) {
    val narrow = LocalScreen.current.narrow
    val shadows = LocalGraphicsContext.current.shadowContext
    val shape = RoundedCornerShape(Jolu.RadiusMd)
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
            .heightIn(min = 52.dp)
            .drawWithContent {
                val outline = shape.createOutline(size, layoutDirection, this)
                if (sticky != null) {
                    drawBoxShadows(shape, listOf(BoxShadow(y = 10.dp, blur = 26.dp, color = Color.Black.copy(alpha = 0.32f))), shadows, outline)
                }
                val path = androidx.compose.ui.graphics.Path().apply { addOutline(outline) }
                drawPath(
                    path,
                    when {
                        sticky != null -> Brush.verticalGradient(listOf(Color(46, 57, 52).copy(alpha = 0.97f), Color(42, 52, 48).copy(alpha = 0.96f)))
                        you -> Brush.verticalGradient(listOf(Jolu.Health.copy(alpha = 0.10f), Jolu.Health.copy(alpha = 0.045f)))
                        else -> androidx.compose.ui.graphics.SolidColor(Jolu.white(0.035f))
                    }
                )
                if (sticky != null) {
                    drawBoxShadows(shape, listOf(BoxShadow(y = 1.dp, blur = 0.dp, color = Jolu.white(0.08f), inset = true)), shadows, outline)
                }
                if (you) drawBorder(outline, Jolu.Health.copy(alpha = 0.30f))
                drawContent()
            }
            .clearAndSetSemantics {
                contentDescription = (if (you) community.labels["you_hint"].orEmpty() + ": " else "") +
                    "$rankText. ${name ?: "—"}, ${points(entry.points)} $unit"
            }
            // `.board-row`'s border is there on every row, transparent but for yours: it takes room.
            .padding(1.dp)
            .padding(horizontal = if (narrow) Jolu.Space2 else Jolu.Space3, vertical = Jolu.Space1),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(if (narrow) Jolu.Space2 else Jolu.Space3)
    ) {
        T(
            rankText,
            JoluType.style(Jolu.FsSmall, if (lit) FontWeight.SemiBold else FontWeight.Normal, if (lit) Jolu.TextPrimary else Jolu.TextMuted, tabular = true),
            Modifier.width(if (narrow) 30.4.dp else 36.dp),
            align = TextAlign.End,
            maxLines = 1
        )
        Box(
            Modifier
                .size(if (narrow) 30.dp else 34.dp)
                .clip(CircleShape)
                .background(Jolu.white(0.07f))
                .border(1.dp, Jolu.GlassHairline, CircleShape),
            contentAlignment = Alignment.Center
        ) {
            if (name != null) {
                T(initial(name), JoluType.style(Jolu.FsSmall, FontWeight.SemiBold, Jolu.mix(accent, 0.78f, Color.White)), maxLines = 1)
            } else {
                JIcon(JoluIcons.user, size = 16.dp, color = Jolu.TextMuted)
            }
        }
        T(
            name ?: "—",
            JoluType.style(if (narrow) Jolu.FsSmall else Jolu.FsLabel, if (you) FontWeight.SemiBold else FontWeight.Normal, if (you) Jolu.TextPrimary else Jolu.TextSecondary),
            Modifier.weight(1f),
            maxLines = 1,
            ellipsis = true
        )
        Row {
            val empty = entry.points == null
            T(
                points(entry.points),
                JoluType.style(
                    if (narrow) Jolu.FsSmall else Jolu.FsLabel,
                    if (empty) FontWeight.Medium else FontWeight.SemiBold,
                    if (empty) Jolu.TextMuted else Jolu.TextPrimary,
                    tabular = true
                ),
                Modifier.alignByBaseline(),
                maxLines = 1
            )
            T(unit, JoluType.style(Jolu.FsTiny, FontWeight.Medium, Jolu.TextMuted), Modifier.alignByBaseline().padding(start = 3.dp), maxLines = 1)
        }
    }
}

/** `.board-gap`: a dotted rule either side of "the rest of the list". */
@Composable
private fun GapRow(label: String) {
    Row(
        Modifier.fillMaxWidth().padding(vertical = Jolu.Space2).clearAndSetSemantics { },
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Jolu.Space3)
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
                    drawRect(Jolu.white(0.16f), topLeft = Offset(x, 0f), size = Size(minOf(on, size.width - x), size.height))
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

/** `community_avatar_accent()`: a stable one of the app's three accents per name (crc32 % 3). */
internal fun avatarAccent(name: String?): String {
    val crc = CRC32().apply { update((name ?: "").toByteArray(Charsets.UTF_8)) }.value
    return listOf("health", "nutrition", "activity")[(crc % 3).toInt()]
}
