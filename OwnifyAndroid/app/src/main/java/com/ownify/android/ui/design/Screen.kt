package com.ownify.android.ui.design

import androidx.compose.runtime.Immutable
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.TextUnit
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.min
import androidx.compose.ui.unit.sp
import com.ownify.android.ui.theme.Ownify

/**
 * The website's screen-size rules (dashboard.css), for the width this
 * screen has: below 360 dp a few things tighten, from 600 dp the column and
 * the gaps widen, from 900 dp the column is centred in whitespace and the
 * tab bar takes its width. Everything else is the same at every size.
 */
@Immutable
data class ScreenMetrics(val width: Dp, val height: Dp) {

    val narrow: Boolean get() = width < 360.dp
    val wide: Boolean get() = width >= 600.dp
    val desktop: Boolean get() = width >= 900.dp

    /** `--shell-w`: the one column everything aligns to. */
    val shell: Dp
        get() = when {
            desktop -> min(width * 0.70f, 608.dp)
            wide -> min(width * 0.88f, 576.dp)
            else -> min(width * Ownify.ShellShare, Ownify.ShellMax)
        }

    val cardRadius: Dp get() = if (narrow) 24.dp else Ownify.RadiusCard

    val fsScore: TextUnit
        get() = when {
            narrow -> 40.sp
            wide -> 49.6.sp
            else -> Ownify.FsScore
        }

    val fsBrand: TextUnit get() = if (narrow) 20.sp else Ownify.FsBrand

    /** `.tabbar` width: `min(94vw, 27.5rem)`, 97vw below 360, the column from 900. */
    val tabbarWidth: Dp
        get() = when {
            desktop -> shell
            narrow -> min(width * 0.97f, 440.dp)
            else -> min(width * 0.94f, 440.dp)
        }

    /** `--tab-edge: clamp(0px, 49.5px - 10.65vw, 12px)`. */
    val tabEdge: Dp get() = (49.5f - 0.1065f * width.value).coerceIn(0f, 12f).dp

    /** `.stack` gap. */
    val stackGap: Dp get() = if (wide) Ownify.Space5 else Ownify.Space4

    /** `.app__main` padding-top. */
    val mainTop: Dp get() = if (wide) Ownify.Space6 else Ownify.Space5

    /** `.score-ring` size: `min(50vw, 184px)`. */
    val ringSize: Dp get() = min(width * 0.5f, 184.dp)
}

val LocalScreen = staticCompositionLocalOf { ScreenMetrics(412.dp, 915.dp) }
