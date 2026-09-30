package com.ownify.android.ui

import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.toArgb
import com.ownify.android.data.Contributor
import com.ownify.android.ui.screens.overview.overviewLegend
import com.ownify.android.ui.theme.Accent
import com.ownify.android.ui.theme.Ownify
import com.ownify.android.ui.theme.ScoreBand
import java.io.File
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
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
}
