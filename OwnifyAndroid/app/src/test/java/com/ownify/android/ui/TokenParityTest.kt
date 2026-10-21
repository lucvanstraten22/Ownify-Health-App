package com.ownify.android.ui

import androidx.compose.animation.core.CubicBezierEasing
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.toArgb
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.TextUnit
import com.ownify.android.ui.theme.Ownify
import com.ownify.android.ui.theme.OwnifyPalette
import java.io.File
import kotlin.math.abs
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * One design system, two apps: every token the website writes in
 * assets/css/theme.css (`:root`) and the app keeps in OwnifyTheme.kt is the
 * same number in both, so a change on one side that is not made on the other
 * fails here instead of drifting into two products. ColourSystemTest checks
 * the colours; this checks the rest — sizes, letter spacing, radii, spacing,
 * the floating chrome, motion, the tinted states, the focus halo, the chosen
 * tab and the more-contrast values.
 *
 * CSS px are dp, rem is 16 sp (OwnifyTheme.kt says so); em is em.
 */
class TokenParityTest {

    private val css: String by lazy {
        var dir: File? = File(System.getProperty("user.dir")).absoluteFile
        while (dir != null && !File(dir, "assets/css/theme.css").isFile) dir = dir.parentFile
        assertNotNull("assets/css/theme.css, next to the app", dir)
        File(dir, "assets/css/theme.css").readText()
    }

    private val root: String by lazy { css.substringAfter(":root {").substringBefore("\n}") }
    private val light: String by lazy { css.substringAfter(":root[data-theme=\"light\"] {").substringBefore("\n}") }

    /** `@media (prefers-contrast: more)`: its `:root` and its White Mode block. */
    private val moreDark: String by lazy {
        css.substringAfter("@media (prefers-contrast: more) {").substringAfter(":root {").substringBefore("}")
    }
    private val moreLight: String by lazy {
        css.substringAfter("@media (prefers-contrast: more) {").substringAfter(":root[data-theme=\"light\"] {").substringBefore("}")
    }

    private fun raw(name: String, block: String = root): String {
        val value = Regex("""--$name:\s*([^;]+);""").find(block)?.groupValues?.get(1)?.trim()
        assertNotNull("--$name in theme.css", value)
        return value!!
    }

    private fun rgba(text: String): Color {
        val (r, g, b, a) = Regex("""rgba\((\d+),\s*(\d+),\s*(\d+),\s*([0-9.]+)\)""").find(text)!!.destructured
        return Color(r.toInt(), g.toInt(), b.toInt()).copy(alpha = a.toFloat())
    }

    private fun argb(c: Color) = c.toArgb().let { listOf(it ushr 24, (it shr 16) and 255, (it shr 8) and 255, it and 255) }

    private fun same(label: String, css: Color, app: Color) {
        val a = argb(css)
        val b = argb(app)
        assertTrue("$label: theme.css $a, the app $b", a.zip(b).all { (x, y) -> abs(x - y) <= 1 })
    }

    /** A size in px: `1.0625rem` → 17, `24px` → 24. */
    private fun px(name: String): Float {
        val v = raw(name)
        return when {
            v.endsWith("rem") -> v.removeSuffix("rem").toFloat() * 16f
            v.endsWith("px") -> v.removeSuffix("px").toFloat()
            else -> error("--$name is $v, not a size")
        }
    }

    private fun ms(name: String): Int = Regex("""(\d+)ms""").find(raw(name))!!.groupValues[1].toInt()

    private fun em(name: String): Float = raw(name).removeSuffix("em").toFloat()

    private fun near(label: String, expected: Float, actual: Float) =
        assertTrue("$label: theme.css $expected, the app $actual", abs(expected - actual) < 0.01f)

    private fun sp(label: String, value: TextUnit) = near("--$label", px(label), value.value)
    private fun dp(label: String, value: Dp) = near("--$label", px(label), value.value)

    @Test
    fun `type sizes`() {
        for ((name, value) in listOf(
            "fs-brand" to Ownify.FsBrand, "fs-section" to Ownify.FsSection, "fs-score" to Ownify.FsScore,
            "fs-score-sm" to Ownify.FsScoreSm, "fs-body" to Ownify.FsBody, "fs-label" to Ownify.FsLabel,
            "fs-small" to Ownify.FsSmall, "fs-tiny" to Ownify.FsTiny, "fs-footnote" to Ownify.FsFootnote,
            "fs-input" to Ownify.FsInput, "fs-headline" to Ownify.FsHeadline, "fs-title-3" to Ownify.FsTitle3,
            "fs-title-2" to Ownify.FsTitle2, "fs-title-1" to Ownify.FsTitle1, "fs-figure" to Ownify.FsFigure,
            "fs-large-title" to Ownify.FsLargeTitle, "fs-score-md" to Ownify.FsScoreMd, "tab-label" to Ownify.FsTab,
        )) sp(name, value)
    }

    @Test
    fun `letter spacing per size`() {
        for ((name, value) in listOf(
            "tracking-small" to Ownify.TrackingSmall, "tracking-body" to Ownify.TrackingBody,
            "tracking-title" to Ownify.TrackingTitle, "tracking-large-title" to Ownify.TrackingLargeTitle,
            "tracking-wide" to Ownify.TrackingWide,
        )) near("--$name", em(name), value.value)
    }

    @Test
    fun `radii, spacing and the floating chrome`() {
        for ((name, value) in listOf(
            "radius-card" to Ownify.RadiusCard, "radius-md" to Ownify.RadiusMd, "radius-sm" to Ownify.RadiusSm,
            "space-1" to Ownify.Space1, "space-2" to Ownify.Space2, "space-3" to Ownify.Space3,
            "space-4" to Ownify.Space4, "space-5" to Ownify.Space5, "space-6" to Ownify.Space6,
            "blur-card" to Ownify.BlurCard, "blur-bar" to Ownify.BlurBar, "pane-blur" to Ownify.PaneBlur,
            "tabbar-height" to Ownify.TabbarHeight, "dock-gap" to Ownify.DockGap, "tab-icon" to Ownify.TabIcon,
            "tab-gap" to Ownify.TabGap, "header-btn" to Ownify.HeaderButton, "header-icon" to Ownify.HeaderIcon,
            "edge-fade" to Ownify.EdgeFade, "dock-fade" to Ownify.DockFade, "row-min" to Ownify.RowMin,
            "ai-handle-height" to Ownify.AiHandleHeight, "hero-ring" to Ownify.HeroRing,
        )) dp(name, value)
    }

    @Test
    fun `a floating panel's frost and a titled detail bar`() {
        // --panel-frost is the Liquid Glass's own filter (25.0) — the panel's glass on Android (panelGlass, PaneFilter).
        val frost = raw("panel-frost")
        assertTrue("--panel-frost is the glass filter: $frost",
            frost.contains("blur(var(--pane-blur))") && frost.contains("saturate(var(--pane-saturate))") && frost.contains("brightness(var(--pane-brightness))"))
        // One glass, two thicknesses (--lg-tint, --lg-tint-thick).
        near("--lg-tint", raw("lg-tint").toFloat(), Ownify.LgTint)
        near("--lg-tint-thick", raw("lg-tint-thick").toFloat(), Ownify.LgTintThick)
        near("--veil-deep-top", raw("veil-deep-top").toFloat(), Ownify.VeilDeepTop)
        near("--veil-deep-mid", raw("veil-deep-mid").toFloat(), Ownify.VeilDeepMid)
    }

    @Test
    fun `the focus halo and the smallest touch area`() {
        val halo = raw("focus-halo")
        near("--focus-halo's width", Regex("""0 0 0 (\d+)px""").find(halo)!!.groupValues[1].toFloat(), Ownify.FocusHaloWidth.value)
        same("--focus-halo", rgba(halo), Ownify.FocusHalo)
        // Each platform's own minimum, on purpose (PARITY.md): Apple's 44 pt on the web, Android's 48 dp.
        near("--hit-min", 44f, px("hit-min"))
        near("Ownify.HitMin", 48f, Ownify.HitMin.value)
    }

    @Test
    fun `the page's foot and the chosen tab, in both themes`() {
        fun channels(text: String) = text.split(",").map { it.trim().toInt() }.let { (r, g, b) -> Color(r, g, b) }
        same("--ground-deep", channels(raw("ground-deep")), OwnifyPalette.Dark.groundDeep)
        same("--ground-deep (White)", channels(raw("ground-deep", light)), OwnifyPalette.Light.groundDeep)
        same("--tab-selected-tint", rgba(raw("tab-selected-tint")), OwnifyPalette.Dark.tabSelectedTint)
        same("--tab-selected-tint (White)", rgba(raw("tab-selected-tint", light)), OwnifyPalette.Light.tabSelectedTint)
        near("--tab-selected-shade", raw("tab-selected-shade").toFloat(), OwnifyPalette.Dark.tabSelectedShade)
        near("--tab-selected-shade (White)", raw("tab-selected-shade", light).toFloat(), OwnifyPalette.Light.tabSelectedShade)
    }

    @Test
    fun `more contrast, in both themes`() {
        for ((block, palette) in listOf(moreDark to OwnifyPalette.Dark, moreLight to OwnifyPalette.Light)) {
            val theme = if (palette === OwnifyPalette.Light) " (White)" else ""
            for ((name, value) in listOf(
                "text-secondary" to palette.more.textSecondary, "text-muted" to palette.more.textMuted,
                "text-faint" to palette.more.textFaint, "glass-border" to palette.more.glassBorder,
                "glass-border-soft" to palette.more.glassBorderSoft, "glass-hairline" to palette.more.glassHairline,
            )) same("prefers-contrast: more --$name$theme", rgba(raw(name, block)), value)
        }
    }

    @Test
    fun motion() {
        assertEquals("--screen-duration", ms("screen-duration"), Ownify.ScreenMs)
        assertEquals("--transition-fast", ms("transition-fast"), Ownify.FastMs)
        assertEquals("--transition-slow", ms("transition-slow"), Ownify.SlowMs)
        assertEquals("--reduce-fade", ms("reduce-fade"), Ownify.ReduceFadeMs)
        assertEquals("--panel-in-duration", ms("panel-in-duration"), Ownify.PanelInMs)
        assertEquals("--panel-out-duration", ms("panel-out-duration"), Ownify.PanelOutMs)
        assertEquals("--press-in", ms("press-in"), Ownify.PressInMs)
        assertEquals("--press-out", ms("press-out"), Ownify.PressOutMs)
        for ((name, easing) in listOf("ease-out-strong" to Ownify.EaseOutStrong, "screen-ease" to Ownify.ScreenEase)) {
            val (a, b, c, d) = Regex("""cubic-bezier\(([^)]*)\)""").find(raw(name))!!.groupValues[1].split(",").map { it.trim().toFloat() }
            assertEquals("--$name", CubicBezierEasing(a, b, c, d), easing)
        }
    }

    @Test
    fun `tinted states`() {
        for ((name, value) in listOf(
            "health-wash" to Ownify.HealthWash, "health-fill" to Ownify.HealthFill,
            "health-edge" to Ownify.HealthEdge, "health-strong" to Ownify.HealthStrong,
            "attention-wash" to Ownify.AttentionWash, "attention-fill" to Ownify.AttentionFill,
            "attention-edge" to Ownify.AttentionEdge, "attention-strong" to Ownify.AttentionStrong,
        )) same("--$name", rgba(raw(name)), value)
    }

    @Test
    fun `no size is written out of the scale`() {
        // The website's stylesheets name a token for every font size; the
        // two narrow-screen squeezes left are relative (em) on purpose.
        val dir = File(System.getProperty("user.dir")).absoluteFile.let { d ->
            var x: File? = d; while (x != null && !File(x, "assets/css").isDirectory) x = x.parentFile; File(x, "assets/css")
        }
        val stray = dir.listFiles { f -> f.name.endsWith(".css") && f.name != "theme.css" }!!.flatMap { f ->
            f.readLines().mapIndexedNotNull { i, line ->
                if (Regex("""font-size:\s*[0-9.]+(rem|px)""").containsMatchIn(line)) "${f.name}:${i + 1} ${line.trim()}" else null
            }
        }
        assertTrue("font sizes outside the scale:\n" + stray.joinToString("\n"), stray.isEmpty())
    }
}
