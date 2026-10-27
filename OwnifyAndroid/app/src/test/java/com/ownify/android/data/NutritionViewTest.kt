package com.ownify.android.data

import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/**
 * Voeding, drawn (lib/hydrate-nutrition.php): the view as the server sends
 * it (nutrition-view.json, a local test account's week) read the way the
 * website reads it — the seven days, the composition, the bubbles' verdicts
 * and Energie and Water with their bereik, target and second unit.
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [36])
class NutritionViewTest {

    private val view: NutritionView = NutritionView.parse(
        JSONObject(javaClass.classLoader!!.getResource("nutrition-view.json").readText())
    )!!

    @Test
    fun readsTheSevenDaysTodayFirst() {
        assertEquals(7, view.days.size)
        assertEquals("Vandaag", view.days[0].name)
        assertEquals("vandaag", view.days[0].word)
        assertEquals("Hoe at je?", view.rating.title)
        assertTrue(view.rating.question.contains("%s"))
        assertEquals("hoe at je vandaag?", view.rating.question.format(view.days[0].word))
    }

    @Test
    fun readsADaysComposition() {
        val day = view.days[0]
        assertNotNull(day.kcal)
        assertNotNull(day.centre)
        assertEquals(listOf("carbs", "protein", "fat"), day.macros.map { it.key })
        val carbs = day.macros[0]
        assertTrue(carbs.logged)
        assertEquals("in", carbs.verdict)
        assertEquals("Binnen bereik", carbs.verdictText)
        assertEquals(40f, carbs.meter!!.low)
        assertEquals(70f, carbs.meter!!.high)
        assertEquals(listOf("Gegeten", "Deel van je energie", "Richtlijn"), carbs.facts.map { it.first })
        assertEquals(listOf("fibre", "sugar"), carbs.details.map { it.key })
        assertEquals("none", carbs.details[1].verdict)
        // The shares make the whole ring.
        assertEquals(100.0, day.macros.sumOf { it.share ?: 0.0 }, 0.2)
    }

    @Test
    fun saturatedFatAboveItsRangeIsGold() {
        val sat = view.days[0].macros.first { it.key == "fat" }.details.single()
        assertEquals("saturated_fat", sat.key)
        assertEquals("high", sat.verdict)
        assertEquals("Boven bereik", sat.verdictText)
        assertNotNull(sat.extra)
    }

    @Test
    fun proteinHasNoBubbleButItsWhy() {
        val protein = view.days[0].macros.first { it.key == "protein" }
        assertNull(protein.verdict)
        assertNotNull(protein.why)
        assertNull(protein.meter!!.low)
    }

    @Test
    fun energyHasItsBereikTargetAndKilojoules() {
        val energy = view.chart("energy")!!
        assertEquals("kcal" to "kJ", energy.units)
        assertTrue(energy.latest!!.of!!.startsWith("van "))
        assertTrue(energy.latest!!.sub!!.endsWith("kJ"))
        val week = energy.periods.first()
        assertEquals(week.grid.size, week.gridAlt.size)
        val band = week.band!!
        assertTrue(band.top < band.bottom)
    }

    @Test
    fun waterInLitresWithMillilitresUnder() {
        val water = view.chart("water")!!
        assertNull(water.units)
        assertTrue(water.latest!!.texts[0]!!.endsWith(" L"))
        assertEquals("van 1,8 L", water.latest!!.of)
        assertTrue(water.latest!!.sub!!.endsWith(" ml"))
        assertTrue(water.periods.first().gridAlt.isEmpty())
        assertNotNull(water.periods.first().band)
    }

    @Test
    fun noViewWithoutDays() {
        assertNull(NutritionView.parse(JSONObject("""{"days":[]}""")))
        assertNull(NutritionView.parse(null))
        assertFalse(view.days.isEmpty())
    }
}
