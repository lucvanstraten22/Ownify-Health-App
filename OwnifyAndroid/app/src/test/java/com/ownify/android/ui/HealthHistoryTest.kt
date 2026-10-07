package com.ownify.android.ui

import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.semantics.getOrNull
import androidx.compose.ui.test.SemanticsNodeInteraction
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.isSelected
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performTouchInput
import com.ownify.android.data.AppData
import com.ownify.android.data.CompassDay
import com.ownify.android.ui.app.AppShell
import com.ownify.android.ui.app.ShellState
import com.ownify.android.ui.design.LocalScreen
import com.ownify.android.ui.design.LocalStillMotion
import com.ownify.android.ui.design.ScreenMetrics
import com.ownify.android.ui.screens.OwnifyScreens
import com.ownify.android.ui.theme.OwnifyTheme
import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/**
 * Gezondheid's Verloop (components/health-history.php): Slaap, Voeding and
 * Training per day over the Scorekompas's periods, read as the Scorekompas's
 * line is — here on the demo pages with the history the server builds from
 * compass-demo.json (health-history-demo.json), checked against that same
 * list of days.
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [36], qualifiers = "w412dp-h915dp-port-420dpi")
class HealthHistoryTest {

    @get:Rule
    val compose = createComposeRule()

    private lateinit var shell: ShellState

    private fun json(name: String) = JSONObject(javaClass.classLoader!!.getResource(name).readText())

    /** The demo pages as a server with the Verloop sends them: [history] beside the Scorekompas it is read from. */
    private fun data(history: String? = "health-history-demo.json", compass: String = "compass-demo.json"): AppData {
        val state = json("state-demo.json").getJSONObject("data")
        state.put("compass", json(compass))
        if (history != null) state.getJSONObject("health").put("history", json(history))
        return AppData.parse(state)
    }

    private fun show(data: AppData) {
        compose.setContent {
            OwnifyTheme {
                BoxWithConstraints(Modifier.fillMaxSize()) {
                    CompositionLocalProvider(LocalScreen provides ScreenMetrics(maxWidth, maxHeight), LocalStillMotion provides true) {
                        AppShell(data, OwnifyScreens.shell, onShell = { shell = it })
                    }
                }
            }
        }
        compose.waitForIdle()
        compose.runOnUiThread { shell.tab("health") }
        compose.waitForIdle()
    }

    private fun plot(period: String) = compose.onNode(hasContentDescription("Slaap, Voeding en Training per dag, $period"))

    private fun reading(node: SemanticsNodeInteraction): String =
        node.fetchSemanticsNode().config.getOrNull(SemanticsProperties.StateDescription).orEmpty()

    private fun act(node: SemanticsNodeInteraction, label: String) {
        val action = node.fetchSemanticsNode().config[SemanticsActions.CustomActions].first { it.label == label }
        compose.runOnUiThread { action.action() }
        compose.waitForIdle()
    }

    /** What a day reads, worked out from the Scorekompas's own day: "5 oktober: Slaap 75, Voeding 75, Training 59". */
    private fun reads(day: CompassDay): String {
        val said = listOf("sleep" to "Slaap", "nutrition" to "Voeding", "training" to "Training").mapNotNull { (id, label) ->
            day.categories.first { it.id == id }.value?.let { "$label $it" }
        }.joinToString(", ")
        return "${day.label}: " + when {
            said.isEmpty() -> day.note
            day.note != null -> "$said. ${day.note}"
            else -> said
        }
    }

    @Test
    fun `the Verloop opens on 7 dagen, with the Scorekompas's switch, the three categories' legend and the hint`() {
        show(data())

        compose.onNodeWithText("Verloop").performScrollTo()
        for (period in listOf("7 dagen", "30 dagen", "90 dagen", "1 jaar")) {
            compose.onNode(hasText(period) and hasClickAction()).assertExists()
        }
        compose.onNode(hasText("7 dagen") and isSelected()).assertExists()
        plot("de afgelopen 7 dagen").assertExists()
        // The legend: the cards above speak their names as a whole, so this is the legend's.
        for (label in listOf("Slaap", "Voeding", "Training")) {
            assertTrue(label, compose.onAllNodesWithText(label).fetchSemanticsNodes().isNotEmpty())
        }
        compose.onNodeWithText("Tik of schuif over de lijnen om een dag te bekijken.").assertExists()

        // Another period: its own chart, and back.
        for ((option, spoken) in listOf("30 dagen" to "de afgelopen 30 dagen", "90 dagen" to "de afgelopen 90 dagen", "1 jaar" to "het afgelopen jaar")) {
            compose.onNode(hasText(option) and hasClickAction()).performClick()
            compose.waitForIdle()
            compose.onNode(hasText(option) and isSelected()).assertExists()
            plot(spoken).assertExists()
        }
        compose.onNodeWithText("Je geschiedenis begint op 23 augustus.").assertExists()
    }

    @Test
    fun `the Verloop read day by day, as a screen reader steps through it - the date and each category's score that day`() {
        val data = data()
        val days = data.compass.trend.days
        show(data)

        val week = plot("de afgelopen 7 dagen")
        week.performScrollTo()
        assertEquals("", reading(week))

        // Nothing read yet: today first, then the day before, and before that.
        act(week, "Vorige dag")
        assertEquals(reads(days[days.lastIndex]), reading(week))
        act(week, "Vorige dag")
        assertEquals(reads(days[days.lastIndex - 1]), reading(week))
        act(week, "Vorige dag")
        assertEquals(reads(days[days.lastIndex - 2]), reading(week))
        act(week, "Volgende dag")
        assertEquals(reads(days[days.lastIndex - 1]), reading(week))
        act(week, "Dag verbergen")
        assertEquals("", reading(week))
    }

    @Test
    fun `a finger on the Verloop reads the day under it - a carried day keeps its scores, a day without any says so`() {
        val data = data()
        val days = data.compass.trend.days
        val period = data.health.history!!.periods.first { it.key == "90" }
        show(data)

        compose.onNodeWithText("Verloop").performScrollTo()
        compose.onNode(hasText("90 dagen") and hasClickAction()).performClick()
        compose.waitForIdle()
        val chart = plot("de afgelopen 90 dagen")
        chart.performScrollTo()

        val carried = period.x.indices.first { days[period.start + it].state == "carried" }
        val none = period.x.indices.first { days[period.start + it].state == "none" }

        chart.performTouchInput { down(Offset(width * period.x[carried] / 100f, height / 2f)) }
        compose.waitForIdle()
        assertEquals(reads(days[period.start + carried]), reading(chart))
        assertTrue("its scores, never a 0", days[period.start + carried].categories.any { it.value != null && it.value!! > 0 })

        chart.performTouchInput { moveTo(Offset(width * period.x[none] / 100f, height / 2f)) }
        compose.waitForIdle()
        assertEquals("${days[period.start + none].label}: Geen score op deze dag.", reading(chart))

        // Lifted: the reading stays a moment, then goes.
        chart.performTouchInput { up() }
        compose.mainClock.advanceTimeBy(400)
        assertEquals("${days[period.start + none].label}: Geen score op deze dag.", reading(chart))
        compose.mainClock.advanceTimeBy(1_600)
        compose.waitForIdle()
        assertEquals("", reading(chart))
    }

    @Test
    fun `a new account's Verloop - the chart says why it is empty, and there is nothing to read`() {
        show(data("health-history-new-account.json", "compass-new-account.json"))

        compose.onNodeWithText("Verloop").performScrollTo()
        compose.onNode(hasText("7 dagen") and isSelected()).assertExists()
        compose.onNodeWithText("Zodra er meetmomenten zijn, verschijnt hier je verloop.").assertExists()
        assertTrue(compose.onAllNodesWithText("Tik of schuif over de lijnen", substring = true).fetchSemanticsNodes().isEmpty())
    }

    @Test
    fun `a server from before the Verloop - the week and month of all three, as before`() {
        show(data(history = null))

        compose.onNode(hasText("Week") and hasClickAction()).assertExists()
        compose.onNode(hasText("Maand") and hasClickAction()).assertExists()
        assertTrue(compose.onAllNodesWithText("7 dagen").fetchSemanticsNodes().isEmpty())
    }
}
