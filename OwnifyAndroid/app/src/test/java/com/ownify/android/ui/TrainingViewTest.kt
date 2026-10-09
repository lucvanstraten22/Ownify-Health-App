package com.ownify.android.ui

import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.semantics.getOrNull
import androidx.compose.ui.test.SemanticsMatcher
import androidx.compose.ui.test.SemanticsNodeInteraction
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performSemanticsAction
import com.ownify.android.data.AppData
import com.ownify.android.data.TrainingView
import com.ownify.android.ui.app.AppShell
import com.ownify.android.ui.app.Detail
import com.ownify.android.ui.app.ShellState
import com.ownify.android.ui.design.LocalScreen
import com.ownify.android.ui.design.LocalStillMotion
import com.ownify.android.ui.design.ScreenMetrics
import com.ownify.android.ui.screens.OwnifyScreens
import com.ownify.android.ui.theme.OwnifyTheme
import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/**
 * Training, drawn (docs/TRAINING.md): the demo pages with the Training view
 * a server builds from forty days of Health Connect records
 * (training-view.json) — the latest sessions beside the sessions per day,
 * four charts two by two, the heart rate over a day (back over seven) or a
 * period with its four zones, HRV and Hartbelasting; a session's own page.
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [36], qualifiers = "w412dp-h915dp-port-420dpi")
class TrainingViewTest {

    @get:Rule
    val compose = createComposeRule()

    private lateinit var shell: ShellState

    private fun json(name: String) = JSONObject(javaClass.classLoader!!.getResource(name).readText())

    private fun data(): AppData {
        val state = json("state-demo.json").getJSONObject("data")
        state.getJSONObject("health").getJSONObject("areas").getJSONObject("training").put("view", json("training-view.json"))
        return AppData.parse(state)
    }

    private fun show() {
        val data = data()
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
        compose.runOnUiThread {
            shell.tab("health")
            shell.openDetail(Detail.HealthArea("training"))
        }
        compose.waitForIdle()
    }

    private fun text(text: String) = compose.onNode(hasText(text), useUnmergedTree = true)

    private fun none(text: String) = compose.onAllNodes(hasText(text), useUnmergedTree = true).fetchSemanticsNodes().isEmpty()

    /** At least once: a word that is both an option and a title, or on the page under this one too. */
    private fun some(text: String) = assertFalse("\"$text\" is shown", none(text))

    /** The day's name above its chart: the live region that says which day is shown. */
    private fun day(name: String) =
        compose.onNode(hasText(name) and SemanticsMatcher.keyIsDefined(SemanticsProperties.LiveRegion), useUnmergedTree = true)

    private fun button(label: String) = compose.onNode(hasContentDescription(label) and hasClickAction())

    private fun reading(node: SemanticsNodeInteraction): String =
        node.fetchSemanticsNode().config.getOrNull(SemanticsProperties.StateDescription).orEmpty()

    private fun act(node: SemanticsNodeInteraction, label: String) {
        val action = node.fetchSemanticsNode().config[SemanticsActions.CustomActions].first { it.label == label }
        compose.runOnUiThread { action.action() }
        compose.waitForIdle()
    }

    private fun click(node: SemanticsNodeInteraction) {
        node.performScrollTo()
        node.performSemanticsAction(SemanticsActions.OnClick)
        compose.waitForIdle()
    }

    @Test
    fun `the view as the server sends it - sessions, seven charts, seven days and four periods of heart rate, four zones`() {
        val view = data().health.area("training")!!.view as TrainingView
        assertEquals(listOf("Hardlopen", "Fietsen", "Krachttraining", "Hardlopen"), view.sessions.items.map { it.label })
        assertEquals(listOf("sessions", "steps", "energy", "floors", "active_minutes", "hrv", "training_load"), view.charts.map { it.id })
        assertEquals("sessions", view.perDay)
        assertEquals(listOf("steps", "energy", "floors", "active_minutes"), view.grid)
        assertEquals(listOf("hrv", "training_load"), view.lower)
        assertEquals(listOf("Vandaag", "Gisteren", "Eergisteren", "5 oktober", "4 oktober", "3 oktober", "2 oktober"), view.heart.days.map { it.title })
        assertFalse("a day without heart rate is empty, never made up", view.heart.days[4].hasData)
        assertEquals(listOf("d0", "7", "30", "90", "365"), view.heart.options.map { it.first })
        assertEquals("over 90 dagen a point per day", "day", view.heart.periods.first { it.key == "90" }.group)
        assertEquals(listOf("Zone 1" to "< 93", "Zone 2" to "93–105", "Zone 3" to "106–131", "Zone 4" to "≥ 132"), view.heart.zones.bands)
        assertEquals(3, view.heart.days[0].zonesY!!.size)
        assertEquals(listOf("00:00", "03:00", "06:00", "09:00", "12:00", "15:00", "18:00", "21:00"), view.heart.days[0].axis.map { it.label })
        assertEquals(4, view.details.size)
        assertTrue("Hartbelasting has no source: no value, no line", view.chart("training_load")!!.periods.none { it.hasData })
    }

    @Test
    fun `the Training page - sessions and sessions per day, four charts, the heart rate, HRV and Hartbelasting`() {
        show()
        text("Recente trainingen").assertExists()
        button("Open Hardlopen van vandaag").assertExists()
        button("Open Krachttraining van 6 okt").assertExists()
        for (title in listOf("Trainingen per dag", "Stappen + Afstand", "Actieve + Totale calorieën", "Verdiepingen", "Actieve minuten", "HRV", "Hartbelasting")) {
            button("Open $title").assertExists()
        }
        text("Hartslag").assertExists()
        for (option in listOf("Vandaag", "7 dagen", "30 dagen", "90 dagen", "1 jaar")) some(option)
        for (zone in listOf("Zone 1", "Zone 2", "Zone 3", "Zone 4")) text(zone).assertExists()
        text("Op basis van je rusthartslag (55 bpm) en maximale hartslag (183 bpm).").assertExists()
        // Shown by the charts and the sessions now: no tiles, no rows of their own.
        for (gone in listOf("Activiteit", "Trainingen", "Trainingsduur", "Hartslagzones")) assertTrue(gone, none(gone))
        text("Conditie en herstel").assertExists()
    }

    @Test
    fun `the day back over seven - its name above it, an empty day said so, Vandaag again goes to today`() {
        show()
        val back = button("Vorige dag")
        click(back)
        day("Gisteren").assertExists()
        click(back)
        day("Eergisteren").assertExists()
        click(back)
        click(back)
        day("4 oktober").assertExists()
        text("Geen hartslag gemeten op deze dag.").assertExists()
        click(back)
        click(back)
        day("2 oktober").assertExists()
        compose.onNode(hasContentDescription("Vorige dag")).assertIsNotEnabled()       // no further than seven days
        click(button("Volgende dag"))
        day("3 oktober").assertExists()

        // 7 dagen, then Vandaag: today again.
        click(compose.onNode(hasText("7 dagen") and hasClickAction()))
        compose.onNode(hasContentDescription("Hartslag per dag, de afgelopen 7 dagen", substring = true)).assertExists()
        click(compose.onNode(hasText("Vandaag") and hasClickAction()))
        day("Vandaag").assertExists()
        assertTrue(none("3 oktober"))
        compose.onNode(hasContentDescription("Volgende dag")).assertIsNotEnabled()      // nothing newer than today
    }

    @Test
    fun `the heart rate read - its time, its zone and its bpm`() {
        show()
        val plot = compose.onNode(hasContentDescription("Hartslag op vandaag, per 5 minuten"))
        act(plot, "Volgende dag")
        assertEquals("23:00, Zone 1: 57 bpm", reading(plot))
        act(plot, "Vorige dag")
        assertTrue(reading(plot), reading(plot).matches(Regex("""\d\d:\d\d, Zone [1-4]: \d+ bpm""")))
    }

    @Test
    fun `a session opens its own page over Training - its heart rate and figures - and back goes to Training`() {
        show()
        click(button("Open Fietsen van gisteren"))
        assertEquals(Detail.TrainingSession("837"), shell.detail)
        assertEquals(Detail.HealthArea("training"), shell.under)
        compose.onNode(hasText("Fietsen") and SemanticsMatcher.keyIsDefined(SemanticsProperties.Heading), useUnmergedTree = true).assertExists()
        text("Woensdag 7 oktober · 17:35 – 18:05").assertExists()
        text("Hartslag tijdens de training").assertExists()
        text("Gegevens").assertExists()
        for (label in listOf("Duur", "Gem. hartslag", "Max. hartslag")) text(label).assertExists()
        // Also the names of Training's charts, on the page under this one.
        for (label in listOf("Afstand", "Actieve calorieën", "Stappen", "Verdiepingen")) some(label)
        text("1.030").assertExists()
        // Figures it does not have are not shown.
        assertTrue(none("Tempo") && none("Snelheid") && none("Hoogtemeters"))

        compose.runOnUiThread { shell.back() }
        compose.waitForIdle()
        assertEquals(Detail.HealthArea("training"), shell.detail)
        assertNull(shell.under)
    }

    @Test
    fun `a small chart opens its own page over Training`() {
        show()
        click(button("Open Stappen + Afstand"))
        assertEquals(Detail.AreaChart("training", "steps"), shell.detail)
        assertEquals(Detail.HealthArea("training"), shell.under)
        for (period in listOf("7 dagen", "30 dagen", "90 dagen", "1 jaar")) some(period)
        some("Stappen")
        some("Afstand")
    }
}
