package com.ownify.android.ui

import android.content.Context
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.semantics.getOrNull
import androidx.compose.ui.test.SemanticsNodeInteraction
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onLast
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
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
import com.ownify.android.data.OwnifyAppState
import com.ownify.android.i18n.OwnifyPreferences
import com.ownify.android.ui.app.OwnifyApp
import com.ownify.android.ui.design.LocalStillMotion
import com.ownify.android.ui.screens.OwnifyScreens
import com.ownify.android.ui.screens.goals.GoalBoard
import com.ownify.android.ui.theme.Ownify
import com.ownify.android.ui.theme.OwnifyMode
import com.ownify.android.ui.theme.OwnifyTheme
import com.ownify.android.ui.theme.OwnifyThemeStore
import org.json.JSONObject
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/**
 * Voorgesteld (docs/GOALS.md › Suggestions) in the app: what the server
 * worked out for a synthetic account (goal-suggestions-state.json, from
 * lib/hydrate-goals.php with includes/goal-suggest.php) — the list in its
 * order with its reasons, the type cards' examples and the sentence under
 * the target — shown exactly as the website shows it, nothing worked out
 * on the phone.
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [36], qualifiers = "+w412dp-h915dp-port")
class GoalSuggestionFlowTest {

    @get:Rule
    val compose = createComposeRule()

    private lateinit var context: Context
    private lateinit var server: FakeOwnifyServer

    private fun json(name: String) = JSONObject(javaClass.classLoader!!.getResource(name).readText())

    private val suggestions get() = json("goal-suggestions-state.json")

    /** The demo state with the server's suggestions in every measurement. */
    private fun state(): JSONObject {
        val state = json("state-demo.json")
        val goals = state.getJSONObject("data").getJSONObject("goals")
        val measurements = goals.getJSONObject("measurements")
        val planned = suggestions.getJSONObject("measurements")
        planned.keys().forEach { code ->
            val target = measurements.optJSONObject(code) ?: return@forEach
            val fields = planned.getJSONObject(code)
            fields.keys().forEach { key -> target.put(key, fields.get(key)) }
        }
        return state
    }

    @Before
    fun setUp() {
        context = ApplicationProvider.getApplicationContext()
        WorkManagerTestInitHelper.initializeTestWorkManager(context, Configuration.Builder().setExecutor(SynchronousExecutor()).build())
        server = FakeOwnifyServer()
        server.stateBody = state().toString()
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

    private fun SemanticsNodeInteraction.inView(): SemanticsNodeInteraction {
        try { performScrollTo() } catch (_: Throwable) { }
        return this
    }

    private fun tap(text: String) {
        button(text).performClick()
        compose.waitForIdle()
    }

    private fun shows(text: String) = compose.onAllNodesWithText(text, substring = true).fetchSemanticsNodes().isNotEmpty()

    private fun dump(name: String) {
        val out = System.getProperty("ownify.tree").orEmpty()
        if (out.isNotEmpty()) java.io.File(out, "$name.txt").writeText(
            compose.onAllNodes(androidx.compose.ui.test.isRoot()).fetchSemanticsNodes().joinToString("\n\n") { root ->
                fun walk(n: androidx.compose.ui.semantics.SemanticsNode, d: Int): String =
                    "  ".repeat(d) + (n.config.getOrNull(androidx.compose.ui.semantics.SemanticsProperties.Text)?.joinToString { it.text } ?: "") + "\n" +
                        n.children.joinToString("") { walk(it, d + 1) }
                walk(root, 0)
            })
    }

    private fun top(text: String): Float =
        compose.onAllNodesWithText(text).fetchSemanticsNodes().first().boundsInRoot.top

    @Test
    fun `the state as the server sends it - the order, the reasons, the targets, no popular`() {
        val data = AppData.parse(state().getJSONObject("data"))
        val planned = suggestions.getJSONObject("measurements")
        val order = data.goals.measurements.filter { it.suggested != null }.sortedBy { it.suggested }.map { it.code }
        val expected = planned.keys().asSequence().filter { !planned.getJSONObject(it).isNull("suggested") }
            .sortedBy { planned.getJSONObject(it).getInt("suggested") }.toList()
        assertEquals("the server's order, as sent", expected, order)
        assertEquals("steps", order.first())

        val steps = data.goals.measurement("steps")!!
        assertEquals("Nu 8.300 · optimaal 10.000 stappen", steps.suggestionReason)
        assertEquals(10000.0, steps.placeholder.getValue("streak"), 0.0)
        assertTrue(steps.suggestionNotes.getValue("streak").startsWith("Voorgesteld: elke dag minstens 10.000 stappen."))
        assertTrue("research, said not to be a minimum", steps.suggestionNotes.getValue("streak").contains("geen ondergrens"))
        assertEquals("Elke dag minstens 10.000 stappen", steps.names.first())

        val workouts = data.goals.measurement("workouts")!!
        assertEquals("a streak per week: its own note and count", 5.0, workouts.placeholder.getValue("period"), 0.0)
        assertTrue(workouts.suggestionNotes.getValue("period").startsWith("Voorgesteld: 5 keer per week."))

        assertNull("not suggested: no place in the list", data.goals.measurement("distance")!!.suggested)
        assertEquals("Voorgesteld", data.goals.wizard["suggested"])
    }

    @Test
    fun `step one - Voorgesteld, in the server's order, each with why`() {
        openWizard()
        dump("step-one")
        assertTrue("the label (an eyebrow, in capitals)", shows("VOORGESTELD"))
        assertFalse("never Populair", shows("Populair") || shows("POPULAIR"))
        assertTrue("steps' reason", shows("Nu 8.300 · optimaal 10.000 stappen"))
        assertTrue("sleep's reason", shows("Nu 7:40 · optimaal 8:00"))
        assertTrue("steps first, then sleep", top("Stappen") < top("Slaapduur"))

        // A category's list is the category, not the suggestion.
        tap("Activiteit")
        assertFalse("a category: no reasons", shows("Nu 8.300 · optimaal 10.000 stappen"))
        assertTrue("a category: its own meta", shows("Activiteit · "))
    }

    @Test
    fun `steps two and three - the example and the target worked out for this person, and why`() {
        openWizard()
        tap("Stappen")
        tap("Volgende")
        assertTrue("the type card's example", shows("Bijv. Elke dag minstens 10.000 stappen"))
        // The first name idea is the suggestion itself.
        compose.onAllNodes(hasText("Elke dag minstens 10.000 stappen") and hasClickAction()).onLast().inView().performClick()
        compose.waitForIdle()
        tap("Streak")
        tap("Volgende")
        dump("step-three")
        assertTrue("under the target: what, and why", shows("Voorgesteld: elke dag minstens 10.000 stappen."))
        assertTrue(shows("Tot ongeveer 10.000 stappen ziet onderzoek bij jouw leeftijd nog wat winst"))
    }
}
