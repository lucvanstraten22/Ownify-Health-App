package com.ownify.android.ui.theme

import androidx.compose.animation.core.CubicBezierEasing
import androidx.compose.foundation.text.selection.LocalTextSelectionColors
import androidx.compose.foundation.text.selection.TextSelectionColors
import androidx.compose.material3.LocalContentColor
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.Immutable
import androidx.compose.runtime.compositionLocalOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.PlatformTextStyle
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.LineHeightStyle
import androidx.compose.ui.text.style.TextMotion
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.TextUnit
import androidx.compose.ui.unit.em
import androidx.compose.ui.unit.sp

/**
 * Dark Mode or White Mode: the website's `<html data-theme>`. Chosen in
 * Instellingen → Thema & uiterlijk and kept on this phone (OwnifyThemeStore),
 * as the website keeps it in the browser; Dark unless White was chosen.
 */
enum class OwnifyMode(val key: String) {
    DARK("dark"),
    LIGHT("light");

    companion object {
        /** A stored or sent name; Dark for anything else, as on the website. */
        fun of(key: String?): OwnifyMode = if (key == LIGHT.key) LIGHT else DARK
    }
}

/**
 * Everything that differs between the two themes, for one of them:
 * assets/css/theme.css's `:root` ([Dark]) and `:root[data-theme="light"]`
 * ([Light]), one for one. What is the same in both — the category colours,
 * score-high, the app's green, sizes, type and motion — stays on [Ownify].
 *
 * Dark holds exactly the values the app drew with before it had a second
 * theme, so Dark Mode is drawn as it always was.
 */
@Immutable
class OwnifyPalette(
    val mode: OwnifyMode,

    val bgMain: Color,
    val bgDeep: Color,
    val bgSecondary: Color,
    val glass: Color,
    val glassStrong: Color,
    val glassSoft: Color,
    val glassBorder: Color,
    val glassBorderSoft: Color,
    val glassHairline: Color,

    /**
     * The three accents below 3:1 on white as they are; deeper in White Mode,
     * same hue — the score colours to 3:1 (graphics only), attention to 4.5:1
     * (it is small text too).
     */
    val scoreMid: Color,
    val scoreLow: Color,
    val attention: Color,

    val textPrimary: Color,
    val textSecondary: Color,
    val textMuted: Color,
    val textFaint: Color,
    val error: Color,

    val paneSaturate: Float,
    val paneBrightness: Float,
    /** `--pane-rim`: its five stops, at 0, 22, 52, 78 and 100%. */
    val paneRim: List<Color>,
    /** `--surface-gradient`, from and to (135°). */
    val surface: Pair<Color, Color>,
    /** `--surface-gradient-quiet`. */
    val surfaceQuiet: Pair<Color, Color>,
    /** `--shadow-inset`'s light, `--shadow-card`'s and `--shadow-float`'s colours. */
    val shadowInset: Color,
    val shadowCard: Color,
    val shadowFloat: Color,

    // The roles (see [Ownify.ink] and the rest): a colour and, where it has
    // one, the factor the alpha a component writes is multiplied by.
    val ink: Color,
    val fill: Color,
    val fillK: Float,
    val lift: Color,
    val liftK: Float,
    val glintK: Float,
    val shade: Color,
    val shadeK: Float,
    val scrim: Color,
    val scrimK: Float,
    val ground: Color,
    val paneBody: Color,
    val tintBase: Color,
    val tintKeep: Float,

    /** The few surfaces that are near-solid rather than glass. */
    val tipSurface: Color,
    val tipSurfaceSolid: Color,
    val boardYou: Pair<Color, Color>,
    val switchKnob: Color
) {
    companion object {

        /** `:root` — the original design, value for value. */
        val Dark = OwnifyPalette(
            mode = OwnifyMode.DARK,
            bgMain = Color(0xFF302D2F),
            bgDeep = Color(0xFF252223),
            bgSecondary = Color(0xFF3A3638),
            glass = white(0.08f),
            glassStrong = white(0.11f),
            glassSoft = white(0.045f),
            glassBorder = white(0.13f),
            glassBorderSoft = white(0.08f),
            glassHairline = white(0.06f),
            scoreMid = Color(0xFFAECA0F),
            scoreLow = Color(0xFFC99A45),
            attention = Color(0xFFBFA863),
            textPrimary = Color(0xFFF7F7F5),
            textSecondary = white(0.74f),
            textMuted = white(0.60f),
            textFaint = white(0.42f),
            error = Color(0xFFFF8B8B),
            paneSaturate = 1.9f,
            paneBrightness = 1.08f,
            paneRim = listOf(white(0.46f), white(0.13f), white(0.045f), white(0.10f), white(0.26f)),
            surface = white(0.11f) to white(0.04f),
            surfaceQuiet = white(0.075f) to white(0.028f),
            shadowInset = white(0.08f),
            shadowCard = Color.Black.copy(alpha = 0.18f),
            shadowFloat = Color.Black.copy(alpha = 0.3f),
            ink = Color.White,
            fill = Color.White,
            fillK = 1f,
            lift = Color.White,
            liftK = 1f,
            glintK = 1f,
            shade = Color.Black,
            shadeK = 1f,
            scrim = Color.Black,
            scrimK = 1f,
            ground = Color(48, 45, 47),
            paneBody = Color(30, 28, 29),
            tintBase = Color.White,
            tintKeep = 1f,
            tipSurface = Color(40, 40, 42).copy(alpha = 0.92f),
            tipSurfaceSolid = Color(46, 42, 44).copy(alpha = 0.97f),
            boardYou = Color(46, 57, 52).copy(alpha = 0.97f) to Color(42, 52, 48).copy(alpha = 0.96f),
            switchKnob = white(0.55f)
        )

        /**
         * `:root[data-theme="light"]` — the same design in a light
         * environment. Role by role: the ground a warm off-white under the
         * same washes; glass on it white and translucent, lighter than what
         * is behind it as in the dark theme; wells in it a faint warm-dark
         * tint; marks in the text colour dark with the text; white light on
         * glass stronger; shadows softer and warmer.
         */
        val Light = OwnifyPalette(
            mode = OwnifyMode.LIGHT,
            bgMain = Color(0xFFFBFAFA),
            bgDeep = Color(0xFFF0EDEE),
            bgSecondary = Color(0xFFFFFFFF),
            glass = warm(0.06f),
            glassStrong = warm(0.085f),
            glassSoft = warm(0.04f),
            glassBorder = warm(0.10f),
            glassBorderSoft = warm(0.075f),
            glassHairline = warm(0.07f),
            scoreMid = Color(0xFF7D910B),
            scoreLow = Color(0xFFAC8032),
            attention = Color(0xFF7D6A33),
            textPrimary = Color(0xFF1D1A1C),
            textSecondary = text(0.74f),
            textMuted = text(0.66f),
            textFaint = text(0.48f),
            error = Color(0xFFC2453E),
            paneSaturate = 1.7f,
            paneBrightness = 1.04f,
            paneRim = listOf(white(0.95f), white(0.55f), text(0.06f), text(0.09f), white(0.70f)),
            surface = white(0.84f) to white(0.60f),
            surfaceQuiet = white(0.70f) to white(0.46f),
            shadowInset = white(0.95f),
            shadowCard = Color(62, 46, 54).copy(alpha = 0.07f),
            shadowFloat = Color(62, 46, 54).copy(alpha = 0.12f),
            ink = Color(29, 26, 28),
            fill = Color(36, 28, 32),
            fillK = 1f,
            lift = Color.White,
            liftK = 16f,
            glintK = 3f,
            shade = Color(62, 46, 54),
            shadeK = 0.38f,
            scrim = Color(38, 30, 34),
            scrimK = 0.5f,
            ground = Color(251, 250, 250),
            paneBody = Color(214, 210, 212),
            tintBase = Color(0xFF1D1A1C),
            tintKeep = 0.4f,
            tipSurface = white(0.94f),
            tipSurfaceSolid = white(0.97f),
            boardYou = Color(234, 245, 239).copy(alpha = 0.97f) to Color(226, 240, 233).copy(alpha = 0.96f),
            switchKnob = Color.White
        )

        fun of(mode: OwnifyMode): OwnifyPalette = if (mode == OwnifyMode.LIGHT) Light else Dark

        private fun white(alpha: Float) = Color.White.copy(alpha = alpha)
        private fun warm(alpha: Float) = Color(36, 28, 32).copy(alpha = alpha)
        private fun text(alpha: Float) = Color(29, 26, 28).copy(alpha = alpha)
    }
}

/**
 * The website's design tokens (assets/css/theme.css), one for one. CSS px are
 * dp here and rem is 16 sp, so a Ownify screen measures the same on a phone as
 * the website does in that phone's browser.
 *
 * The colours that differ between Dark and White Mode are read from [palette]
 * each time they are used, so a screen drawn with them is drawn again when the
 * theme changes ([use]) — in composition, layout or a draw call alike.
 */
object Ownify {

    // --- the theme --------------------------------------------------------
    /** The palette on screen: state, so whatever was drawn with it follows a change. */
    var palette: OwnifyPalette by mutableStateOf(OwnifyPalette.Dark)
        private set

    val mode: OwnifyMode get() = palette.mode
    val light: Boolean get() = palette.mode == OwnifyMode.LIGHT

    /** Dark or White Mode from now on. Keeping the choice is OwnifyThemeStore's. */
    fun use(mode: OwnifyMode) {
        palette = OwnifyPalette.of(mode)
    }

    // --- surfaces ---------------------------------------------------------
    val BgMain: Color get() = palette.bgMain
    val BgDeep: Color get() = palette.bgDeep
    val BgSecondary: Color get() = palette.bgSecondary

    val Glass: Color get() = palette.glass
    val GlassStrong: Color get() = palette.glassStrong
    val GlassSoft: Color get() = palette.glassSoft
    val GlassBorder: Color get() = palette.glassBorder
    val GlassBorderSoft: Color get() = palette.glassBorderSoft
    val GlassHairline: Color get() = palette.glassHairline

    // --- category colours: WHICH part of health, whatever the score -------
    // Exactly the website's --sleep, --nutrition, --training (+ -light), in both themes.
    val Sleep = Color(0xFF5B64C7)
    val SleepLight = Color(0xFF747CDA)
    val Nutrition = Color(0xFF477B61)
    val NutritionLight = Color(0xFF67997D)
    val Training = Color(0xFFC97867)
    val TrainingLight = Color(0xFFD99586)

    // --- score colours: HOW HIGH a score is, whatever the category --------
    // Exactly the website's --score-high / -mid / -low. Which band a score is
    // in comes from the server (config/scoring.php): 80–100, 60–79, 0–59.
    // Mid and low are deeper in White Mode, as on the website: as they are,
    // they fall below 3:1 on white.
    val ScoreHigh = Color(0xFF4E9F70)
    val ScoreMid: Color get() = palette.scoreMid
    val ScoreLow: Color get() = palette.scoreLow

    // --- the app's own colours, not categories ----------------------------
    /** `--health`: the positive state (connected, done, reached) and the accent of what has no category. */
    val Health = Color(0xFF3C9E72)

    /** `--attention`: warnings, a confirmation that cannot be undone. Deeper in White Mode, for its text. */
    val Attention: Color get() = palette.attention

    /**
     * The attention gold as the website writes out its washes and borders —
     * `rgba(191, 168, 99, a)` — in both themes: only text and icons take
     * [Attention]'s deeper White Mode value.
     */
    val AttentionWash = Color(0xFFBFA863)

    /** `--neutral`: the solid tile of a card head that has no category — grey, not a colour. */
    val Neutral = Color(0xFF6B6769)

    /** The olive of the website's ambient backdrop washes — decoration, not a category. */
    val AmbientOlive = Color(0xFF78A560)

    /** A day that did not make it — graphics only. */
    val Miss = Color(0xFFA8625C)

    // --- text -------------------------------------------------------------
    val TextPrimary: Color get() = palette.textPrimary
    val TextSecondary: Color get() = palette.textSecondary
    val TextMuted: Color get() = palette.textMuted

    /** For graphics (inactive dots), never for text. */
    val TextFaint: Color get() = palette.textFaint

    /** The error red the wizard, the editor and the rating use (`--error`). */
    val Error: Color get() = palette.error

    // --- surfaces' light and shade ------------------------------------------
    /** `--surface-gradient`: a card's face, from its top-left to its bottom-right. */
    val SurfaceFrom: Color get() = palette.surface.first
    val SurfaceTo: Color get() = palette.surface.second

    /** `--surface-gradient-quiet`. */
    val SurfaceQuietFrom: Color get() = palette.surfaceQuiet.first
    val SurfaceQuietTo: Color get() = palette.surfaceQuiet.second

    /** `--shadow-inset`: the 1 px light along a surface's top edge. */
    val ShadowInset: Color get() = palette.shadowInset

    /** `--shadow-card` and `--shadow-float`: their colours. */
    val ShadowCard: Color get() = palette.shadowCard
    val ShadowFloat: Color get() = palette.shadowFloat

    /** `--tip-surface`: a value floating over a bar. */
    val TipSurface: Color get() = palette.tipSurface

    /** `--tip-surface-solid`: the chart's reading. */
    val TipSurfaceSolid: Color get() = palette.tipSurfaceSolid

    /** `--board-you-surface`: your own row pinned to the board's foot, top and bottom. */
    val BoardYouFrom: Color get() = palette.boardYou.first
    val BoardYouTo: Color get() = palette.boardYou.second

    /** `--switch-knob`: a switch's knob while it is off. */
    val SwitchKnob: Color get() = palette.switchKnob

    // --- roles ------------------------------------------------------------
    // theme.css's roles. Every colour a component writes for itself is one of
    // these, so the theme changes the role and never the component; in Dark
    // Mode each is exactly the white or black the component wrote before.
    // [alpha] is the alpha the website's rule writes.

    /** `rgba(var(--ink), a)`: marks in the text colour — tracks, grips, dots, dashes, grid lines, rings. */
    fun ink(alpha: Float): Color = palette.ink.copy(alpha = alpha)

    /** `--fill`: wells and controls inside a surface — rows, chips, fields, tiles, a meter's track. */
    fun fill(alpha: Float): Color = palette.fill.copy(alpha = cssAlpha(alpha * palette.fillK))

    /** `--lift`: a faint panel on the page itself, not in a surface — the board's rows, a framed note. */
    fun lift(alpha: Float): Color = palette.lift.copy(alpha = cssAlpha(alpha * palette.liftK))

    /** Glint: white light on glass — lit edges, reflections, the sheen. White in both themes, stronger on light glass. */
    fun glint(alpha: Float): Color = Color.White.copy(alpha = cssAlpha(alpha * palette.glintK))

    /** `--shade`: shadows. */
    fun shade(alpha: Float): Color = palette.shade.copy(alpha = cssAlpha(alpha * palette.shadeK))

    /** `--scrim`: what dims the page behind a panel. */
    fun scrim(alpha: Float): Color = palette.scrim.copy(alpha = cssAlpha(alpha * palette.scrimK))

    /** `--ground`: [BgMain] as a veil of the page itself. */
    fun ground(alpha: Float): Color = palette.ground.copy(alpha = alpha)

    /** `--pane-body`: the floating glass's own tint under its sheen. */
    fun paneBody(alpha: Float): Color = palette.paneBody.copy(alpha = alpha)

    /**
     * Text tinted by [accent] (a chip, a status): `color-mix(in srgb, accent
     * calc(100% - b% × --tint-keep), --tint-base)`, where [share] is the
     * accent's share in Dark Mode (100% - b%). Dark Mode lightens the accent
     * with white; White Mode deepens it with the text colour, and by less.
     */
    fun tint(accent: Color, share: Float): Color =
        mix(accent, (1.0 - (1.0 - share) * palette.tintKeep).toFloat(), palette.tintBase)

    /** CSS keeps an alpha between 0 and 1, whatever a calc() makes of it. */
    private fun cssAlpha(alpha: Float) = alpha.coerceIn(0f, 1f)

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
    val PaneSaturate: Float get() = palette.paneSaturate
    val PaneBrightness: Float get() = palette.paneBrightness

    /** `--pane-rim`'s stops, at 0, 22, 52, 78 and 100%. */
    val PaneRim: List<Color> get() = palette.paneRim

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

    /** White, in both themes: text on a solid accent tile, a switch's knob while on. */
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

/**
 * `data-accent="sleep|nutrition|training|health"`: the three category colours,
 * each with its lighter shade — fixed per category, never by score — and the
 * app's own green for what belongs to no category. The same in both themes.
 */
enum class Accent(val color: Color, val light: Color) {
    SLEEP(Ownify.Sleep, Ownify.SleepLight),
    NUTRITION(Ownify.Nutrition, Ownify.NutritionLight),
    TRAINING(Ownify.Training, Ownify.TrainingLight),
    HEALTH(Ownify.Health, Ownify.mix(Ownify.Health, 0.65f, Color.White));

    companion object {
        /**
         * The server's name for one; health for anything else, as `--accent`
         * defaults. `activity` is training's name from before the category
         * colours, still understood from a server that has not caught up.
         */
        fun of(name: String?): Accent =
            when (name) {
                "sleep" -> SLEEP
                "nutrition" -> NUTRITION
                "training", "activity" -> TRAINING
                else -> HEALTH
            }

        /** The accent whose colour this is, if it is one: for its lighter shade. */
        fun byColor(color: Color): Accent? = entries.firstOrNull { it.color == color }
    }
}

/**
 * `data-score="high|mid|low"`: how high a score is, as its colour — the same
 * for every category. The band itself is decided on the server
 * (config/scoring.php, `score_band` in the state); this only draws it, in the
 * theme on screen.
 */
enum class ScoreBand {
    HIGH,
    MID,
    LOW;

    val color: Color
        get() = when (this) {
            HIGH -> Ownify.ScoreHigh
            MID -> Ownify.ScoreMid
            LOW -> Ownify.ScoreLow
        }

    companion object {
        /** The server's name for one; null for no score (or a name it does not know). */
        fun of(name: String?): ScoreBand? =
            when (name) {
                "high" -> HIGH
                "mid" -> MID
                "low" -> LOW
                else -> null
            }
    }
}

/**
 * The letter spacing of text whose own rule names none, as CSS inherits it:
 * body's `-0.01em`, worked out at body's 15px — so -0.15px at every size —
 * and `normal` (0) inside a button or a field, whose browser style resets it
 * (see [InButton]).
 */
val LocalTracking = compositionLocalOf { (-0.15).sp }

/** What a `<button>` holds: its text keeps `letter-spacing: normal` unless its own rule says otherwise. */
@Composable
fun InButton(content: @Composable () -> Unit) = CompositionLocalProvider(LocalTracking provides 0.sp, content = content)

/** `--accent` where an element opts into a category colour. */
val LocalAccent = compositionLocalOf { Accent.HEALTH }

/**
 * The body text: 15 sp, line height 1.5, letter spacing -0.01em, the
 * platform sans-serif. The website's stack is the system's sans-serif, which
 * on Android is Roboto — so is this.
 *
 * Leading is split evenly above and below the line, as CSS does, and no
 * extra font padding is added.
 *
 * Glyphs advance by their true widths (TextMotion.Animated: linear metrics,
 * subpixel positions), as the browser lays text out. Android's default rounds
 * each advance to a whole pixel, which makes a line a few percent wider or
 * narrower than the website's, and so breaks it in a different place.
 */
@Immutable
object OwnifyType {

    private val Plain = TextStyle(
        fontFamily = FontFamily.Default,
        fontSize = Ownify.FsBody,
        lineHeight = 1.5.em,
        // Unspecified: taken from where the text sits (LocalTracking), as CSS inherits it.
        letterSpacing = TextUnit.Unspecified,
        platformStyle = PlatformTextStyle(includeFontPadding = false),
        lineHeightStyle = LineHeightStyle(
            alignment = LineHeightStyle.Alignment.Center,
            trim = LineHeightStyle.Trim.None
        ),
        textMotion = TextMotion.Animated
    )

    /** The body text, in the theme's text colour. */
    val Base: TextStyle get() = Plain.copy(color = Ownify.TextPrimary)

    /** `font-variant-numeric: tabular-nums`. */
    const val Tabular = "tnum"

    fun style(
        size: androidx.compose.ui.unit.TextUnit,
        weight: FontWeight = FontWeight.Normal,
        color: Color = Ownify.TextPrimary,
        tracking: androidx.compose.ui.unit.TextUnit = TextUnit.Unspecified,
        lineHeight: androidx.compose.ui.unit.TextUnit = 1.5.em,
        tabular: Boolean = false
    ): TextStyle = Plain.copy(
        fontSize = size,
        fontWeight = weight,
        color = color,
        letterSpacing = tracking,
        lineHeight = lineHeight,
        fontFeatureSettings = if (tabular) Tabular else null
    )
}

/**
 * The Ownify theme. Material is only here for the few platform pieces that
 * read it (text selection, the cursor); nothing on screen takes its colours,
 * its type scale or its ripples.
 */
@Composable
fun OwnifyTheme(content: @Composable () -> Unit) {
    val scheme = if (Ownify.light) {
        lightColorScheme(
            primary = Ownify.Health,
            onPrimary = Ownify.TextPrimary,
            background = Ownify.BgDeep,
            onBackground = Ownify.TextPrimary,
            surface = Ownify.BgMain,
            onSurface = Ownify.TextPrimary,
            error = Ownify.Error
        )
    } else {
        darkColorScheme(
            primary = Ownify.Health,
            onPrimary = Ownify.TextPrimary,
            background = Ownify.BgDeep,
            onBackground = Ownify.TextPrimary,
            surface = Ownify.BgMain,
            onSurface = Ownify.TextPrimary,
            error = Ownify.Error
        )
    }

    MaterialTheme(colorScheme = scheme) {
        CompositionLocalProvider(
            LocalContentColor provides Ownify.TextPrimary,
            LocalTextSelectionColors provides TextSelectionColors(
                handleColor = Ownify.Health,
                backgroundColor = Ownify.Health.copy(alpha = 0.35f)
            ),
            content = content
        )
    }
}
