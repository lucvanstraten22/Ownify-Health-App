package com.ownify.android.ui

import android.content.Context
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.semantics.getOrNull
import androidx.compose.ui.test.SemanticsNodeInteraction
import androidx.compose.ui.test.assertIsEnabled
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasSetTextAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onLast
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performTextClearance
import androidx.compose.ui.test.performTextInput
import androidx.compose.ui.test.performTouchInput
import androidx.compose.ui.test.longClick
import androidx.test.core.app.ApplicationProvider
import androidx.work.Configuration
import androidx.work.testing.SynchronousExecutor
import androidx.work.testing.WorkManagerTestInitHelper
import com.ownify.android.connection.FakeOwnifyServer
import com.ownify.android.connection.HealthAccess
import com.ownify.android.connection.MemoryTokenStorage
import com.ownify.android.connection.OwnifyApi
import com.ownify.android.connection.OwnifyConnection
import com.ownify.android.connection.OwnifySyncRunner
import com.ownify.android.connection.OwnifySyncStatusPrefs
import com.ownify.android.connection.TestSyncEnvironment
import com.ownify.android.data.AppData
import com.ownify.android.data.GoalSpan
import com.ownify.android.data.OwnifyAppState
import com.ownify.android.i18n.OwnifyLanguage
import com.ownify.android.i18n.OwnifyPreferences
import com.ownify.android.ui.app.OwnifyApp
import com.ownify.android.ui.design.LocalStillMotion
import com.ownify.android.ui.screens.OwnifyScreens
import com.ownify.android.ui.screens.goals.GoalBoard
import com.ownify.android.ui.screens.goals.durationEnd
import com.ownify.android.ui.screens.goals.goalNumber
import com.ownify.android.ui.screens.goals.periodAsks
import com.ownify.android.ui.screens.goals.valueText
import com.ownify.android.ui.theme.Ownify
import com.ownify.android.ui.theme.OwnifyMode
import com.ownify.android.ui.theme.OwnifyTheme
import com.ownify.android.ui.theme.OwnifyThemeStore
import java.time.LocalDate
import org.json.JSONObject
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/**
 * Nieuw doel in the app (GoalWizard.kt), against a server on localhost: the
 * three steps and the review, as components/goal-wizard.php and
 * goal-wizard.js run them — the measurement first, chosen without moving
 * on; the name and the type; the target, its unit, which way is better, the
 * baseline and the period; then everything at a glance and one goal made,
 * however often Doel aanmaken is tapped. And the date and unit arithmetic,
 * which must match the server's (tools/goal-engine-test.php) to the day.
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [36], qualifiers = "+w412dp-h915dp-port")
class GoalWizardFlowTest {

    @get:Rule
    val compose = createComposeRule()

    private lateinit var context: Context
    private lateinit var server: FakeOwnifyServer

    @Before
    fun setUp() {
        context = ApplicationProvider.getApplicationContext()
        WorkManagerTestInitHelper.initializeTestWorkManager(context, Configuration.Builder().setExecutor(SynchronousExecutor()).build())
        server = FakeOwnifyServer()
        OwnifyConnection.api = OwnifyApi(server.url)
        OwnifyConnection.storage = { MemoryTokenStorage }
        TestSyncEnvironment.context = context
        TestSyncEnvironment.api = OwnifyApi(server.url)
        TestSyncEnvironment.access = HealthAccess.AVAILABLE
        OwnifySyncRunner.environment = { TestSyncEnvironment }
        MemoryTokenStorage.clear()
        OwnifyConnection.reset()
        OwnifyAppState.clear()
        GoalBoard.clear()
        OwnifySyncStatusPrefs(context).clear()
        OwnifyThemeStore.forget(context)
        OwnifyPreferences.use()
        Ownify.use(OwnifyMode.DARK)
    }

    @After
    fun tearDown() {
        server.stop()
        OwnifyAppState.clear()
        GoalBoard.clear()
    }

    /** Doelen, signed in, and the wizard open from its +. */
    private fun openWizard() {
        MemoryTokenStorage.signedIn()
        compose.setContent {
            OwnifyTheme {
                CompositionLocalProvider(LocalStillMotion provides true) {
                    OwnifyApp(OwnifyScreens.shell, OwnifyScreens.signedOut)
                }
            }
        }
        waitFor("Gezondheidsscore")
        button("Doelen").performClick()
        compose.waitForIdle()
        compose.onAllNodes(hasContentDescription("Nieuw doel toevoegen") and hasClickAction()).onLast().performClick()
        waitFor("Wat wil je bijhouden?")
    }

    private fun waitFor(text: String, timeout: Long = 15_000) =
        compose.waitUntil(timeout) { compose.onAllNodesWithText(text, substring = true).fetchSemanticsNodes().isNotEmpty() }

    private fun button(text: String): SemanticsNodeInteraction = compose.onAllNodes(hasText(text) and hasClickAction()).onLast().inView()

    /** Scrolled into the step's view first, where it scrolls at all. */
    private fun SemanticsNodeInteraction.inView(): SemanticsNodeInteraction {
        try { performScrollTo() } catch (_: Throwable) { }
        return this
    }

    private fun tap(text: String) {
        button(text).performClick()
        compose.waitForIdle()
    }

    private fun described(words: String): SemanticsNodeInteraction =
        compose.onAllNodes(hasContentDescription(words, substring = true) and hasClickAction()).onLast().inView()

    private fun type(label: String, text: String) {
        val field = compose.onNode(hasContentDescription(label) and hasSetTextAction()).inView()
        field.performTextClearance()
        field.performTextInput(text)
        compose.waitForIdle()
    }

    private fun shows(text: String) = compose.onAllNodesWithText(text, substring = true).fetchSemanticsNodes().isNotEmpty()

    private fun fieldText(label: String): String =
        compose.onNode(hasContentDescription(label) and hasSetTextAction()).fetchSemanticsNode()
            .config.getOrNull(SemanticsProperties.EditableText)?.text.orEmpty()

    private fun form(request: FakeOwnifyServer.Request): Map<String, String> =
        request.body.optString("form").split('&').filter { it.contains('=') }.associate {
            java.net.URLDecoder.decode(it.substringBefore('='), "UTF-8") to java.net.URLDecoder.decode(it.substringAfter('='), "UTF-8")
        }

    // ---------------------------------------------------------------- step one

    @Test
    fun `step one - Volgende waits for a measurement, a bubble filters, search finds, and choosing does not move on`() {
        openWizard()
        button("Volgende").assertIsNotEnabled()
        assertTrue("the popular ones first", shows("Stappen") && shows("Slaapduur") && shows("Gewicht"))

        tap("Lichaam")
        assertTrue(shows("Vetpercentage"))
        assertTrue("another category's measurement is filtered out", !shows("Slaapduur"))
        tap("Lichaam")      // a second tap lets the category go again

        type("Zoek een meting", "efficientie")       // without its accent
        assertTrue(shows("Slaapefficiëntie"))
        type("Zoek een meting", "stap")
        tap("Stappen")
        assertTrue("still step one: choosing only highlights", shows("Wat wil je bijhouden?"))
        button("Volgende").assertIsEnabled()
        assertTrue("the indicator counts three steps", shows("Stap 1 van 3"))
    }

    @Test
    fun `Eigen gewoonte asks for its category before it moves on`() {
        openWizard()
        type("Zoek een meting", "gewoonte")
        tap("Eigen gewoonte")
        assertTrue(shows("Kies hierboven de categorie van je gewoonte."))
        button("Volgende").assertIsNotEnabled()
        tap("Herstel & balans")
        button("Volgende").assertIsEnabled()
    }

    // ------------------------------------------------------------- the whole way

    @Test
    fun `the whole way - a daily streak on steps, sent once, then Doelen on Actief with the new goal`() {
        openWizard()
        tap("Stappen")
        tap("Volgende")
        waitFor("Wat is je doel?")
        button("Volgende").assertIsNotEnabled()
        tap("10.000 stappen per dag")
        assertTrue("an example for this measurement", shows("Bijv. Elke dag 10.000 stappen"))
        tap("Streak")
        tap("Volgende")
        waitFor("Stel je doel in")
        assertTrue(shows("Stap 3 van 3"))
        type("Per dag", "8000")
        tap("3 maanden")
        tap("Volgende")

        waitFor("Klopt alles?")
        assertTrue("the review has no step number", shows("Controleren") && !shows("Stap 3 van 3"))
        assertTrue(shows("Elke dag minstens 8.000 stappen"))
        assertTrue(shows("Automatisch, uit je eigen gegevens"))

        // Doel aanmaken, twice in a row: one goal.
        button("Doel aanmaken").performClick()
        button("Doel aanmaken").performClick()
        compose.waitUntil(10_000) { server.requestsTo("create.php").isNotEmpty() && GoalBoard.reveal != null }
        val sent = server.requestsTo("create.php")
        assertEquals("one request, one goal", 1, sent.size)
        val goal = form(sent.single())
        val today = LocalDate.now()
        assertEquals("steps", goal["measurement"])
        assertEquals("streak", goal["type"])
        assertEquals("daily", goal["variant"])
        assertEquals("8000", goal["target_value"])
        assertEquals("steps", goal["unit"])
        assertEquals(today.toString(), goal["start_date"])
        assertEquals(today.plusMonths(3).minusDays(1).toString(), goal["end_date"])
        assertTrue("a one-time key", goal["create_token"].orEmpty().length >= 8)
        assertTrue("no priority is asked", "priority" !in goal)
        assertEquals("back on Actief, the new card in view", "active", GoalBoard.view)
        assertEquals("900", GoalBoard.reveal)
    }

    @Test
    fun `Mijlpaal in lb - the start is asked when nothing was measured, the target must lie past it, and the figures follow the unit`() {
        openWizard()
        tap("Gewicht")
        tap("Volgende")
        waitFor("Wat is je doel?")
        // Gewicht can only be a Mijlpaal: chosen for you.
        type("Naam", "Naar 75 kg")
        tap("Volgende")
        waitFor("Stel je doel in")
        type("Doelwaarde", "75")
        type("Startwaarde", "74")
        waitFor("Je doelwaarde moet lager zijn dan je startwaarde (74 kg).")
        button("Volgende").assertIsNotEnabled()
        type("Startwaarde", "82,4")
        button("Volgende").assertIsEnabled()

        // Lower is better was chosen for Gewicht; a long press explains and changes nothing.
        described("Lager is beter").assertExists()
        described("Hoger is beter").performTouchInput { longClick() }
        compose.waitForIdle()
        assertTrue(shows("Hoger is beter: je voortgang groeit als je waarde stijgt."))
        val decrease = described("Lager is beter").fetchSemanticsNode().config.getOrNull(SemanticsProperties.Selected)
        assertEquals("the long press chose nothing", true, decrease)

        // The unit: kg to lb — what was typed keeps its meaning.
        described("Eenheid").performClick()
        compose.waitForIdle()
        tap("lb")
        assertEquals("165,3", fieldText("Doelwaarde"))
        assertEquals("181,7", fieldText("Startwaarde"))

        tap("Volgende")
        waitFor("Klopt alles?")
        assertTrue(shows("165,3 lb"))
        assertTrue(shows("Handmatig, op de pagina van je doel"))
        tap("Doel aanmaken")
        compose.waitUntil(10_000) { server.requestsTo("create.php").isNotEmpty() }
        val goal = form(server.requestsTo("create.php").single())
        assertEquals("milestone", goal["type"])
        assertEquals("lb", goal["unit"])
        assertEquals("165,3", goal["target_value"])
        assertEquals("181,7", goal["baseline_value"])
        assertEquals("decrease", goal["direction"])
    }

    @Test
    fun `a measured start is shown, not asked`() {
        server.baseline = JSONObject().put("value", 82.4).put("on", "2026-10-18").put("source", "before_start").put("label", "18 okt")
        // Gewicht read from data: this account has some.
        server.stateBody = JSONObject(server.stateBody).also { s ->
            val goals = s.getJSONObject("data").getJSONObject("goals")
            goals.put("user_auto", goals.getJSONArray("user_auto").put("weight"))
        }.toString()
        openWizard()
        tap("Gewicht")
        tap("Volgende")
        type("Naam", "Naar 75 kg")
        tap("Volgende")
        waitFor("Gemeten op 18 okt")
        assertTrue(shows("82,4 kg"))
        assertTrue("nothing to type", compose.onAllNodes(hasContentDescription("Startwaarde") and hasSetTextAction()).fetchSemanticsNodes().isEmpty())
    }

    @Test
    fun `back keeps every answer, and back on step one closes the wizard`() {
        openWizard()
        tap("Stappen")
        tap("Volgende")
        type("Naam", "Mijn stappen")
        tap("Optellen")
        tap("Volgende")
        type("Totaal", "200000")
        described("Terug").performClick()
        compose.waitForIdle()
        assertEquals("Mijn stappen", fieldText("Naam"))
        described("Terug").performClick()
        compose.waitForIdle()
        button("Volgende").assertIsEnabled()      // Stappen is still chosen
        tap("Volgende")
        tap("Volgende")
        assertEquals("200000", fieldText("Totaal"))
        described("Terug").performClick()
        described("Terug").performClick()
        described("Terug").performClick()
        compose.waitForIdle()
        compose.waitUntil(5_000) { !shows("Wat wil je bijhouden?") }
    }

    @Test
    fun `a streak per week - how often, per which period, and a period too short asks nothing`() {
        openWizard()
        tap("Trainingen")
        tap("Volgende")
        tap("3 keer per week trainen")
        tap("Streak")
        tap("Volgende")
        waitFor("Per periode")
        type("Aantal keer", "3")
        assertTrue(shows("trainingen") && shows("per"))
        tap("1 week")
        button("Volgende").assertIsEnabled()
        described("Periode").performClick()
        compose.waitForIdle()
        tap("maand")
        // A month asked of one week: 3 × 7/30 rounds to 1 — still something.
        button("Volgende").assertIsEnabled()
        type("Aantal keer", "1")
        // 1 × 7/30 = 0,23: nothing to ask, so it cannot be made.
        waitFor("Deze periode is te kort voor dit doel.")
        button("Volgende").assertIsNotEnabled()
    }

    // ------------------------------------------- arithmetic, as the server's

    @Test
    fun `the five looptijden from the 31st, and leap years - the server's own end dates`() {
        val start = LocalDate.of(2026, 1, 31)
        val spans = mapOf(
            "week" to GoalSpan("week", "1 week", 7, 0),
            "month" to GoalSpan("month", "1 maand", 0, 1),
            "quarter" to GoalSpan("quarter", "3 maanden", 0, 3),
            "halfyear" to GoalSpan("halfyear", "6 maanden", 0, 6),
            "year" to GoalSpan("year", "1 jaar", 0, 12)
        )
        val expected = mapOf("week" to "2026-02-06", "month" to "2026-02-27", "quarter" to "2026-04-29", "halfyear" to "2026-07-30", "year" to "2027-01-30")
        for ((key, end) in expected) assertEquals(key, end, durationEnd(start, spans.getValue(key)).toString())
        assertEquals("leap year", "2024-02-28", durationEnd(LocalDate.of(2024, 1, 29), spans.getValue("month")).toString())
        assertEquals("from 29 feb", "2025-02-27", durationEnd(LocalDate.of(2024, 2, 29), spans.getValue("year")).toString())
        // 3 per week over 3 weeks: 9. A last period of 3 days of a month asks 3 × 3/30 → 0.
        assertEquals(9, periodAsks(LocalDate.of(2026, 10, 5), LocalDate.of(2026, 10, 25), GoalSpan("week", "week", 7, 0), 3))
        assertEquals(6, periodAsks(LocalDate.of(2026, 1, 31), LocalDate.of(2026, 4, 2), GoalSpan("month", "maand", 0, 1), 3))
    }

    @Test
    fun `a figure as it is typed - grouped in thousands the reader's way it is thousands, else a decimal (goal_parse_number())`() {
        OwnifyLanguage.use("nl")
        assertEquals(10000.0, goalNumber("10.000")!!, 0.0)
        assertEquals(2200.0, goalNumber("2.200")!!, 0.0)
        assertEquals(1234.5, goalNumber("1.234,5")!!, 1e-9)
        assertEquals(72.5, goalNumber("72,5")!!, 1e-9)
        assertEquals(72.5, goalNumber("72.5")!!, 1e-9)
        assertEquals(0.5, goalNumber("0.500")!!, 1e-9)
        OwnifyLanguage.use("en")
        assertEquals(10000.0, goalNumber("10,000")!!, 0.0)
        assertEquals(72.5, goalNumber("72,5")!!, 1e-9)
        OwnifyLanguage.use("nl")
    }

    @Test
    fun `figures in their unit - 7,5 uur of sleep is 450 minutes kept`() {
        val data = AppData.parse(JSONObject(javaClass.classLoader!!.getResource("state-demo.json").readText()).getJSONObject("data"))
        val units = data.goals.units
        assertEquals("7,5 uur", valueText(450.0, units.getValue("h")))
        assertEquals("1 training", valueText(1.0, units.getValue("sessions")))
        assertEquals("165,3 lb", valueText(74.98, units.getValue("lb")))
        assertEquals("85%", valueText(85.0, units.getValue("pct")))
    }
}
