package com.ownify.android.ui.design

import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.snap
import androidx.compose.animation.core.tween
import androidx.compose.runtime.getValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.composed
import androidx.compose.ui.draw.drawWithContent
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.unit.dp
import com.ownify.android.data.OwnifyNotices
import com.ownify.android.ui.theme.Ownify

/**
 * Where a red dot sits on what it belongs to (`.notice-dot`, docs/NOTICES.md).
 *
 *   [Tile]    a rounded tile: inside its top-right corner, 0.5 in
 *   [Circle]  a picture or a round button: in from the box's corner to the
 *             circle's own edge (14.64 % of the diameter), less 3.3 — so it
 *             peeks past the edge by the same little bit on any size
 *   [Beside]  a bare line icon (the tab bar): 3 above and 6 past its corner,
 *             where it does not cover the icon
 */
enum class NoticePlace { Tile, Circle, Beside }

/**
 * The red dot on the top-right corner of what this modifies, while
 * [OwnifyNotices] says [key] waits (lib/notices.php's notice_on()).
 */
fun Modifier.notice(key: String, place: NoticePlace = NoticePlace.Tile): Modifier =
    composed { noticeDot(OwnifyNotices.on(key), place) }

/**
 * The dot itself, flat in [Ownify.Notice]. On a tile or a picture it is 8
 * across, cut out of what it sits on by a 1.5 ring of the page's ground
 * (drawn as one circle behind it, so always exactly concentric); beside a
 * line icon it is 7, with a 2.5 halo of its own colour at 18 %. It grows out
 * of its own centre as it arrives (220 ms) and back as it goes (160 ms);
 * with less motion it simply is there, or not.
 *
 * Drawn over the content, outside its bounds: put it before any clip.
 */
fun Modifier.noticeDot(on: Boolean, place: NoticePlace = NoticePlace.Tile): Modifier = composed {
    val still = LocalStillMotion.current || LocalReducedMotion.current
    val shown by animateFloatAsState(
        if (on) 1f else 0f,
        if (still) snap() else tween(if (on) 220 else 160, easing = Ownify.EaseOut),
        label = "notice"
    )
    val notice = Ownify.Notice
    val ground = Ownify.BgMain
    drawWithContent {
        drawContent()
        if (shown <= 0f) return@drawWithContent
        when (place) {
            NoticePlace.Beside -> {
                val r = 3.5.dp.toPx()
                val c = Offset(size.width + 6.dp.toPx() - r, -3.dp.toPx() + r)
                drawCircle(notice.copy(alpha = 0.18f * shown), radius = (r + 2.5.dp.toPx()) * shown, center = c)
                drawCircle(notice.copy(alpha = shown), radius = r * shown, center = c)
            }
            else -> {
                val r = 4.dp.toPx()
                val inset = if (place == NoticePlace.Circle) size.width * 0.1464f - 3.3.dp.toPx() else 0.5.dp.toPx()
                val c = Offset(size.width - inset - r, inset + r)
                drawCircle(ground.copy(alpha = shown), radius = (r + 1.5.dp.toPx()) * shown, center = c)
                drawCircle(notice.copy(alpha = shown), radius = r * shown, center = c)
            }
        }
    }
}
