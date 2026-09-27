package com.healthapp.android.ui.theme

import androidx.compose.animation.core.CubicBezierEasing
import androidx.compose.foundation.text.selection.LocalTextSelectionColors
import androidx.compose.foundation.text.selection.TextSelectionColors
import androidx.compose.material3.LocalContentColor
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.Immutable
import androidx.compose.runtime.compositionLocalOf
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.PlatformTextStyle
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.LineHeightStyle
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.em
import androidx.compose.ui.unit.sp

/**
 * The website's design tokens (assets/css/theme.css), one for one. CSS px are
 * dp here and rem is 16 sp, so a JoLu screen measures the same on a phone as
 * the website does in that phone's browser.
 */
object Jolu {

    // --- surfaces ---------------------------------------------------------
    val BgMain = Color(0xFF302D2F)
    val BgDeep = Color(0xFF252223)
    val BgSecondary = Color(0xFF3A3638)

    val Glass = white(0.08f)
    val GlassStrong = white(0.11f)
    val GlassSoft = white(0.045f)
    val GlassBorder = white(0.13f)
    val GlassBorderSoft = white(0.08f)
    val GlassHairline = white(0.06f)

    // --- accents ----------------------------------------------------------
    val Health = Color(0xFF3C9E72)
    val Nutrition = Color(0xFFBFA863)
    val Activity = Color(0xFF78A560)

    /** A day that did not make it — graphics only. */
    val Miss = Color(0xFFA8625C)

    // --- text -------------------------------------------------------------
    val TextPrimary = Color(0xFFF7F7F5)
    val TextSecondary = white(0.74f)
    val TextMuted = white(0.60f)

    /** For graphics (inactive dots), never for text. */
    val TextFaint = white(0.42f)

    /** The error red the wizard, the editor and the rating use (#ff8b8b). */
    val Error = Color(0xFFFF8B8B)

    // --- radii ------------------------------------------------------------
    val RadiusCard = 28.dp
    val RadiusMd = 20.dp
    val RadiusSm = 14.dp

    // --- spacing ----------------------------------------------------------
    val Space1 = 4.dp
    val Space2 = 8.dp
    val Space3 = 12.dp
    val Space4 = 16.dp
    val Space5 = 24.dp
    val Space6 = 32.dp

    // --- glass ------------------------------------------------------------
    val BlurCard = 24.dp
    val BlurBar = 30.dp
    val PaneBlur = 10.dp
    const val PaneSaturate = 1.9f
    const val PaneBrightness = 1.08f

    // --- type sizes -------------------------------------------------------
    val FsBrand = 22.4.sp
    val FsSection = 20.8.sp
    val FsScore = 46.4.sp
    val FsScoreSm = 28.sp
    val FsBody = 15.sp
    val FsLabel = 14.sp
    val FsSmall = 13.sp
    val FsTiny = 11.sp
    val FsTab = 10.sp

    /** `--tracking-wide: 0.08em`, the uppercase labels. */
    val TrackingWide = 0.08.em

    // --- layout -----------------------------------------------------------
    val TabbarHeight = 54.dp
    val DockGap = 12.dp
    val TabIcon = 24.dp
    val TabGap = 3.dp
    val TabGlowInset = 4.dp
    val HeaderButton = 46.dp
    val HeaderIcon = 24.dp
    val SheenOverhang = 16.dp

    /** `--shell-w: min(92vw, 33.5rem)`. */
    val ShellMax = 536.dp
    const val ShellShare = 0.92f

    // --- motion -----------------------------------------------------------
    /** `--screen-ease`, with `--screen-duration` 280 ms. */
    val ScreenEase = CubicBezierEasing(0.22f, 1f, 0.36f, 1f)
    const val ScreenMs = 280

    /** `--ease-out`, and `--transition-slow` (420 ms). */
    val EaseOut = CubicBezierEasing(0.22f, 0.61f, 0.36f, 1f)
    const val SlowMs = 420

    /** CSS `ease`, and `--transition-fast` (180 ms). */
    val Ease = CubicBezierEasing(0.25f, 0.1f, 0.25f, 1f)
    const val FastMs = 180

    /** The count-ups and the meters: 1000 ms, ease-out cubic. */
    val EaseOutCubic = CubicBezierEasing(0.33f, 1f, 0.68f, 1f)

    fun white(alpha: Float) = Color.White.copy(alpha = alpha)

    /**
     * `color-mix(in srgb, a p%, b)`, as a browser mixes: the gamma-encoded
     * sRGB channels, premultiplied by alpha — so mixing with `transparent`
     * keeps the colour and only lowers its alpha.
     */
    fun mix(a: Color, share: Float, b: Color): Color {
        val alpha = a.alpha * share + b.alpha * (1f - share)
        if (alpha <= 0f) return Color.Transparent
        fun channel(x: Float, y: Float) = ((x * a.alpha * share + y * b.alpha * (1f - share)) / alpha).coerceIn(0f, 1f)
        return Color(channel(a.red, b.red), channel(a.green, b.green), channel(a.blue, b.blue), alpha.coerceIn(0f, 1f))
    }
}

/** The three category colours: `data-accent="health|nutrition|activity"`. */
enum class Accent(val color: Color) {
    HEALTH(Jolu.Health),
    NUTRITION(Jolu.Nutrition),
    ACTIVITY(Jolu.Activity);

    companion object {
        /** The server's name for one; health for anything else, as `--accent` defaults. */
        fun of(name: String?): Accent =
            when (name) {
                "nutrition" -> NUTRITION
                "activity" -> ACTIVITY
                else -> HEALTH
            }
    }
}

/** `--accent` where an element opts into a category colour. */
val LocalAccent = compositionLocalOf { Accent.HEALTH }

/**
 * The body text: 15 sp, line height 1.5, letter spacing -0.01em, the
 * platform sans-serif. The website's stack is the system's sans-serif, which
 * on Android is Roboto — so is this.
 *
 * Leading is split evenly above and below the line, as CSS does, and no
 * extra font padding is added.
 */
@Immutable
object JoluType {

    val Base = TextStyle(
        fontFamily = FontFamily.Default,
        fontSize = Jolu.FsBody,
        lineHeight = 1.5.em,
        letterSpacing = (-0.01).em,
        color = Jolu.TextPrimary,
        platformStyle = PlatformTextStyle(includeFontPadding = false),
        lineHeightStyle = LineHeightStyle(
            alignment = LineHeightStyle.Alignment.Center,
            trim = LineHeightStyle.Trim.None
        )
    )

    /** `font-variant-numeric: tabular-nums`. */
    const val Tabular = "tnum"

    fun style(
        size: androidx.compose.ui.unit.TextUnit,
        weight: FontWeight = FontWeight.Normal,
        color: Color = Jolu.TextPrimary,
        tracking: androidx.compose.ui.unit.TextUnit = (-0.01).em,
        lineHeight: androidx.compose.ui.unit.TextUnit = 1.5.em,
        tabular: Boolean = false
    ): TextStyle = Base.copy(
        fontSize = size,
        fontWeight = weight,
        color = color,
        letterSpacing = tracking,
        lineHeight = lineHeight,
        fontFeatureSettings = if (tabular) Tabular else null
    )
}

/**
 * The JoLu theme. Material is only here for the few platform pieces that
 * read it (text selection, the cursor); nothing on screen takes its colours,
 * its type scale or its ripples.
 */
@Composable
fun JoluTheme(content: @Composable () -> Unit) {
    val scheme = darkColorScheme(
        primary = Jolu.Health,
        onPrimary = Jolu.TextPrimary,
        background = Jolu.BgDeep,
        onBackground = Jolu.TextPrimary,
        surface = Jolu.BgMain,
        onSurface = Jolu.TextPrimary,
        error = Jolu.Error
    )

    MaterialTheme(colorScheme = scheme) {
        CompositionLocalProvider(
            LocalContentColor provides Jolu.TextPrimary,
            LocalTextSelectionColors provides TextSelectionColors(
                handleColor = Jolu.Health,
                backgroundColor = Jolu.Health.copy(alpha = 0.35f)
            ),
            content = content
        )
    }
}
