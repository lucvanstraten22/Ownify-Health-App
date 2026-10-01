package com.ownify.android.ui

import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.compositeOver
import androidx.compose.ui.graphics.toArgb
import com.ownify.android.data.Contributor
import com.ownify.android.ui.screens.overview.overviewLegend
import com.ownify.android.ui.theme.Accent
import com.ownify.android.ui.theme.Ownify
import com.ownify.android.ui.theme.OwnifyMode
import com.ownify.android.ui.theme.OwnifyPalette
import com.ownify.android.ui.theme.ScoreBand
import java.io.File
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * The two colour systems, kept apart: a category's colour says WHICH part of
 * health it is and never changes; a score's colour says HOW HIGH it is and is
 * the same for every category. The band a score is in is the server's
 * (score_colour_band(), config/scoring.php) — this checks the app draws it.
 */
class ColourSystemTest {

    private fun hex(c: Color) = "#%06X".format(c.toArgb() and 0xFFFFFF)

    @Test
    fun `each category always has its own colour and lighter shade`() {
        assertEquals("#5B64C7", hex(Accent.of("sleep").color))
        assertEquals("#747CDA", hex(Accent.of("sleep").light))
        assertEquals("#477B61", hex(Accent.of("nutrition").color))
        assertEquals("#67997D", hex(Accent.of("nutrition").light))
        assertEquals("#C97867", hex(Accent.of("training").color))
        assertEquals("#D99586", hex(Accent.of("training").light))

        // training's name from before, from a server that has not caught up
        assertEquals(Accent.TRAINING, Accent.of("activity"))
        // no category: the app's own green
        assertEquals(Accent.HEALTH, Accent.of("health"))
        assertEquals(Accent.HEALTH, Accent.of(null))

        val colours = listOf(Accent.SLEEP, Accent.NUTRITION, Accent.TRAINING).map { it.color }
        assertEquals("three categories, three colours", 3, colours.toSet().size)
    }

    @Test
    fun `score colours - one per band, the same for every category`() {
        assertEquals("#4E9F70", hex(ScoreBand.of("high")!!.color))
        assertEquals("#AECA0F", hex(ScoreBand.of("mid")!!.color))
        assertEquals("#C99A45", hex(ScoreBand.of("low")!!.color))
        assertNull("no score, no band", ScoreBand.of(null))
        assertNull(ScoreBand.of(""))
    }

    /** What the server sends for a score (config/scoring.php 'colour_bands'): 80–100, 60–79, 0–59. */
    private fun band(score: Int?) = when {
        score == null -> null
        score >= 80 -> "high"
        score >= 60 -> "mid"
        else -> "low"
    }

    private fun pillars(sleep: Int?, nutrition: Int?, training: Int?) = listOf(
        Contributor("sleep", "Slaap", "sleep", sleep, band(sleep)),
        Contributor("nutrition", "Voeding", "nutrition", nutrition, band(nutrition)),
        Contributor("training", "Sport", "training", training, band(training)),
    )

    @Test
    fun `the Overzicht dot is the score's colour at every boundary`() {
        for ((score, colour) in listOf(
            100 to Ownify.ScoreHigh, 80 to Ownify.ScoreHigh,
            79 to Ownify.ScoreMid, 60 to Ownify.ScoreMid,
            59 to Ownify.ScoreLow, 0 to Ownify.ScoreLow,
        )) {
            for (item in overviewLegend(pillars(score, score, score))) {
                assertEquals("${item.label} at $score", colour, item.dot)
            }
        }
        for (item in overviewLegend(pillars(null, null, null))) {
            assertEquals("no score: a faint dot", Ownify.TextFaint, item.dot)
        }
    }

    @Test
    fun `a score changes only the dot, never the category colour`() {
        val low = overviewLegend(pillars(40, 40, 40))
        val high = overviewLegend(pillars(95, 95, 95))

        for ((a, b) in low.zip(high)) {
            assertEquals("${a.label}: category unchanged", a.accent, b.accent)
            assertNotEquals("${a.label}: dot follows the score", a.dot, b.dot)
        }
        assertEquals(listOf(Accent.SLEEP, Accent.NUTRITION, Accent.TRAINING), high.map { it.accent })
        // The same score is the same dot in every category.
        assertEquals(1, high.map { it.dot }.toSet().size)
    }

    @Test
    fun `the app's colours are the website's, one for one`() {
        var dir: File? = File(System.getProperty("user.dir")).absoluteFile
        while (dir != null && !File(dir, "assets/css/theme.css").isFile) dir = dir.parentFile
        assertNotNull("assets/css/theme.css, next to the app", dir)
        val css = File(dir, "assets/css/theme.css").readText()

        fun token(name: String) =
            Regex("""--$name:\s*(#[0-9A-Fa-f]{6})\s*;""").find(css)?.groupValues?.get(1)?.uppercase()

        for ((name, colour) in listOf(
            "sleep" to Ownify.Sleep, "sleep-light" to Ownify.SleepLight,
            "nutrition" to Ownify.Nutrition, "nutrition-light" to Ownify.NutritionLight,
            "training" to Ownify.Training, "training-light" to Ownify.TrainingLight,
            "score-high" to Ownify.ScoreHigh, "score-mid" to Ownify.ScoreMid, "score-low" to Ownify.ScoreLow,
            "health" to Ownify.Health, "attention" to Ownify.Attention, "neutral" to Ownify.Neutral,
        )) {
            assertEquals("--$name", token(name), hex(colour))
        }
    }

    // ------------------------------------------------------------ White Mode

    @After
    fun dark() = Ownify.use(OwnifyMode.DARK)

    /** assets/css/theme.css, next to the app in the repository. */
    private fun themeCss(): String {
        var dir: File? = File(System.getProperty("user.dir")).absoluteFile
        while (dir != null && !File(dir, "assets/css/theme.css").isFile) dir = dir.parentFile
        assertNotNull("assets/css/theme.css, next to the app", dir)
        return File(dir, "assets/css/theme.css").readText()
    }

    /** One theme's declarations: `:root { … }` or `:root[data-theme="light"] { … }`. */
    private fun block(css: String, selector: String): String = css.substringAfter("$selector {").substringBefore("\n}")

    private fun value(block: String, name: String): String =
        Regex("""--$name:\s*([^;]+);""").find(block)?.groupValues?.get(1)?.trim() ?: throw AssertionError("--$name is not in the block")

    /** Every colour in a token's value, in order: #rrggbb, rgba(r, g, b, a), or bare channels "r, g, b". */
    private fun colours(block: String, name: String): List<Color> {
        val v = value(block, name)
        Regex("""^#([0-9A-Fa-f]{6})$""").find(v)?.let { return listOf(Color(0xFF000000 or it.groupValues[1].toLong(16))) }
        Regex("""^(\d+),\s*(\d+),\s*(\d+)$""").find(v)?.let { m ->
            val (r, g, b) = m.destructured
            return listOf(Color(r.toInt(), g.toInt(), b.toInt()))
        }
        return Regex("""rgba\((\d+),\s*(\d+),\s*(\d+),\s*([0-9.]+)\)""").findAll(v).map { m ->
            val (r, g, b, a) = m.destructured
            Color(r.toInt(), g.toInt(), b.toInt()).copy(alpha = a.toFloat())
        }.toList().ifEmpty { throw AssertionError("--$name: $v holds no colour") }
    }

    private fun colour(block: String, name: String): Color = colours(block, name).single()

    private fun number(block: String, name: String): Float {
        val v = value(block, name)
        return if (v.endsWith("%")) v.dropLast(1).toFloat() / 100f else v.toFloat()
    }

    @Test
    fun `both themes are the website's, token for token`() {
        val css = themeCss()
        for ((selector, p) in listOf(":root" to OwnifyPalette.Dark, ":root[data-theme=\"light\"]" to OwnifyPalette.Light)) {
            val b = block(css, selector)
            fun same(name: String, expected: Any) = assertEquals("$selector --$name", expected, if (expected is Float) number(b, name) else if (expected is List<*>) colours(b, name) else colour(b, name))

            same("bg-main", p.bgMain); same("bg-deep", p.bgDeep); same("bg-secondary", p.bgSecondary)
            same("glass", p.glass); same("glass-strong", p.glassStrong); same("glass-soft", p.glassSoft)
            same("glass-border", p.glassBorder); same("glass-border-soft", p.glassBorderSoft); same("glass-hairline", p.glassHairline)
            same("score-mid", p.scoreMid); same("score-low", p.scoreLow); same("attention", p.attention)
            same("text-primary", p.textPrimary); same("text-secondary", p.textSecondary)
            same("text-muted", p.textMuted); same("text-faint", p.textFaint); same("error", p.error)
            same("pane-saturate", p.paneSaturate); same("pane-brightness", p.paneBrightness)
            same("pane-rim", p.paneRim)
            same("surface-gradient", p.surface.toList()); same("surface-gradient-quiet", p.surfaceQuiet.toList())
            same("shadow-inset", p.shadowInset); same("shadow-card", p.shadowCard); same("shadow-float", p.shadowFloat)
            same("ink", p.ink); same("fill", p.fill); same("lift", p.lift); same("shade", p.shade); same("scrim", p.scrim)
            same("ground", p.ground); same("pane-body", p.paneBody); same("tint-base", p.tintBase)
            same("fill-k", p.fillK); same("lift-k", p.liftK); same("glint-k", p.glintK)
            same("shade-k", p.shadeK); same("scrim-k", p.scrimK); same("tint-keep", p.tintKeep)
            same("tip-surface", p.tipSurface); same("tip-surface-solid", p.tipSurfaceSolid)
            same("board-you-surface", p.boardYou.toList()); same("switch-knob", p.switchKnob)
        }
    }

    @Test
    fun `in Dark Mode every role is exactly the white or black it took the place of`() {
        assertEquals(OwnifyMode.DARK, Ownify.mode)
        for (a in listOf(0f, 0.022f, 0.035f, 0.04f, 0.055f, 0.07f, 0.08f, 0.11f, 0.13f, 0.26f, 0.28f, 0.46f, 0.55f, 1f)) {
            assertEquals(Color.White.copy(alpha = a), Ownify.ink(a))
            assertEquals(Color.White.copy(alpha = a), Ownify.fill(a))
            assertEquals(Color.White.copy(alpha = a), Ownify.lift(a))
            assertEquals(Color.White.copy(alpha = a), Ownify.glint(a))
            assertEquals(Color.Black.copy(alpha = a), Ownify.shade(a))
            assertEquals(Color.Black.copy(alpha = a), Ownify.scrim(a))
            assertEquals(Color(48, 45, 47).copy(alpha = a), Ownify.ground(a))
            assertEquals(Color(30, 28, 29).copy(alpha = a), Ownify.paneBody(a))
        }
        for (accent in Accent.entries) for (share in listOf(0.34f, 0.40f, 0.78f)) {
            assertEquals("${accent.name} at $share", Ownify.mix(accent.color, share, Color.White), Ownify.tint(accent.color, share))
        }
    }

    @Test
    fun `in White Mode the roles turn as the website's light block turns them - dark marks, warm wells, stronger light, softer shade`() {
        Ownify.use(OwnifyMode.LIGHT)
        assertEquals(Color(29, 26, 28).copy(alpha = 0.26f), Ownify.ink(0.26f))
        assertEquals(Color(36, 28, 32).copy(alpha = 0.05f), Ownify.fill(0.05f))
        assertEquals(Color.White.copy(alpha = 0.56f), Ownify.lift(0.035f))
        assertEquals(Color.White.copy(alpha = 0.84f), Ownify.glint(0.28f))
        assertEquals("light is clamped as CSS clamps it", 1f, Ownify.glint(0.46f).alpha)
        assertEquals(Color(62, 46, 54).copy(alpha = 0.30f * 0.38f), Ownify.shade(0.30f))
        assertEquals(Color(38, 30, 34).copy(alpha = 0.26f), Ownify.scrim(0.52f))
        assertEquals(Color(251, 250, 250).copy(alpha = 0.72f), Ownify.ground(0.72f))
        assertEquals(Ownify.mix(Ownify.Health, 1f - 0.66f * 0.4f, Color(0xFF1D1A1C)), Ownify.tint(Ownify.Health, 0.34f))
    }

    @Test
    fun `White Mode keeps every category, the app's green and the score bands - only mid, low and attention deepen`() {
        val dark = Accent.entries.associateWith { it.color to it.light }
        val high = Ownify.ScoreHigh
        Ownify.use(OwnifyMode.LIGHT)

        assertEquals(dark, Accent.entries.associateWith { it.color to it.light })
        assertEquals(high, Ownify.ScoreHigh)
        assertEquals("#7D910B", hex(ScoreBand.MID.color))
        assertEquals("#AC8032", hex(ScoreBand.LOW.color))
        assertEquals("#7D6A33", hex(Ownify.Attention))
        // The same hue: deeper, not another colour.
        for ((was, now) in listOf(OwnifyPalette.Dark.scoreMid to Ownify.ScoreMid, OwnifyPalette.Dark.scoreLow to Ownify.ScoreLow, OwnifyPalette.Dark.attention to Ownify.Attention)) {
            assertEquals(hue(was), hue(now), 1.5f)
            assertTrue(luminance(now) < luminance(was))
        }
        // The Overzicht dot keeps its thresholds and meaning.
        for ((score, colour) in listOf(100 to Ownify.ScoreHigh, 80 to Ownify.ScoreHigh, 79 to Ownify.ScoreMid, 60 to Ownify.ScoreMid, 59 to Ownify.ScoreLow, 0 to Ownify.ScoreLow)) {
            for (item in overviewLegend(pillars(score, score, score))) assertEquals("${item.label} at $score", colour, item.dot)
        }
    }

    private fun luminance(c: Color): Double {
        fun lin(v: Float): Double = if (v <= 0.04045f) v / 12.92 else Math.pow((v + 0.055) / 1.055, 2.4)
        return 0.2126 * lin(c.red) + 0.7152 * lin(c.green) + 0.0722 * lin(c.blue)
    }

    private fun contrast(a: Color, b: Color): Double {
        val x = luminance(a)
        val y = luminance(b)
        return (maxOf(x, y) + 0.05) / (minOf(x, y) + 0.05)
    }

    /** The hue, in degrees. */
    private fun hue(c: Color): Float {
        val max = maxOf(c.red, c.green, c.blue)
        val d = max - minOf(c.red, c.green, c.blue)
        if (d == 0f) return 0f
        val h = 60f * when (max) {
            c.red -> ((c.green - c.blue) / d).mod(6f)
            c.green -> (c.blue - c.red) / d + 2f
            else -> (c.red - c.green) / d + 4f
        }
        return h
    }

    /** Where text sits, in the theme on screen: a card on the ground, a well in that card, the ground at its deepest. */
    private fun surfaces(): Map<String, Color> {
        val card = Ownify.SurfaceFrom.compositeOver(Ownify.BgMain)
        return mapOf("card" to card, "well" to Ownify.fill(0.05f).compositeOver(card), "ground" to Ownify.BgDeep)
    }

    /** The worst a text colour does on [surfaces]. */
    private fun worst(text: Color): Double = surfaces().values.minOf { contrast(text.compositeOver(it), it) }

    @Test
    fun `White Mode's text is legible - AA for every tier and warning, at least what Dark Mode gives it, 3 to 1 for graphics`() {
        val tiers = mapOf<String, () -> Color>(
            "primary" to { Ownify.TextPrimary }, "secondary" to { Ownify.TextSecondary },
            "muted" to { Ownify.TextMuted }, "attention" to { Ownify.Attention }
        )
        val dark = tiers.mapValues { worst(it.value()) }
        val darkError = contrast(Ownify.Error, surfaces().getValue("card"))

        Ownify.use(OwnifyMode.LIGHT)
        for ((name, colour) in tiers) {
            val light = worst(colour())
            assertTrue("$name: $light", light >= 4.5)
            assertTrue("$name: $light, not below Dark Mode's ${dark[name]}", light >= dark.getValue(name))
        }
        val card = surfaces().getValue("card")
        // Errors sit in a panel or a card.
        assertTrue(contrast(Ownify.Error, card) >= 4.5)
        assertTrue(contrast(Ownify.Error, card) >= darkError)
        // Graphics: the faint dots, the score bands, the meter's green.
        for (graphic in listOf(Ownify.TextFaint.compositeOver(card), Ownify.ScoreMid, Ownify.ScoreLow, Ownify.ScoreHigh, Ownify.Health)) {
            assertTrue(contrast(graphic, card) >= 3.0)
        }
    }
}
