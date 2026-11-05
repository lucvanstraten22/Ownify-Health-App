package com.ownify.android.ui

import android.content.Context
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.test.SemanticsMatcher
import androidx.compose.ui.test.SemanticsNodeInteraction
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onLast
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performSemanticsAction
import androidx.test.core.app.ApplicationProvider
import com.ownify.android.connection.FakeOwnifyServer
import com.ownify.android.connection.MemoryTokenStorage
import com.ownify.android.connection.OwnifyApi
import com.ownify.android.connection.OwnifyConnection
import com.ownify.android.data.AppData
import com.ownify.android.data.OwnifyAppState
import com.ownify.android.data.TrainingView
import com.ownify.android.ui.app.LocalShell
import com.ownify.android.ui.screens.health.RemoveTraining
import androidx.compose.foundation.layout.Column
import androidx.compose.runtime.rememberCoroutineScope
import com.ownify.android.ui.app.AppShell
import com.ownify.android.ui.app.Detail
import com.ownify.android.ui.app.ShellState
import com.ownify.android.ui.design.LocalScreen
import com.ownify.android.ui.design.LocalStillMotion
import com.ownify.android.ui.design.ScreenMetrics
import com.ownify.android.ui.screens.OwnifyScreens
import com.ownify.android.ui.theme.OwnifyTheme
import java.net.URLDecoder
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
 * Sleep and training entered by hand, and a training removed (40.0,
 * docs/MANUAL-ENTRY.md), on the phone: Slaap's and Training's own +, each
 * opening its own entry page over its area — the same layout, its own
 * fields — what is sent, the server's refusal shown as it says it, back on
 * the area once stored; no + on Voeding; and a training's page with
 * Doelen's delete and its confirmation, which says a synced one stays where
 * it came from.
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [36], qualifiers = "+w412dp-h915dp-port-420dpi")
class ManualEntryFlowTest {

    @get:Rule
    val compose = createComposeRule()

    private lateinit var shell: ShellState
    private lateinit var server: FakeOwnifyServer
    private val api = OwnifyConnection.api

    private fun json(name: String) = JSONObject(javaClass.classLoader!!.getResource(name).readText())

    /** The demo state with Training drawn (training-view.json): sessions with pages of their own. */
    private fun state(): JSONObject {
        val state = json("state-demo.json")
        state.getJSONObject("data").getJSONObject("health").getJSONObject("areas").getJSONObject("training")
            .put("view", json("training-view.json"))
        return state
    }

    @Before
    fun setUp() {
        val context = ApplicationProvider.getApplicationContext<Context>()
        server = FakeOwnifyServer()
        server.stateBody = state().toString()
        OwnifyConnection.api = OwnifyApi(server.url)
        OwnifyConnection.storage = { MemoryTokenStorage }
        MemoryTokenStorage.clear()
        MemoryTokenStorage.signedIn()
        OwnifyConnection.reset()
        OwnifyAppState.clear()
        context.hashCode()
    }

    @After
    fun tearDown() {
        server.stop()
        OwnifyConnection.api = api
        OwnifyAppState.clear()
    }

    private fun show(vararg details: Detail) {
        val data = AppData.parse(state().getJSONObject("data"))
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
        // One at a time, as a finger opens them: a page over its own.
        for (detail in details) {
            compose.runOnUiThread { shell.openDetail(detail) }
            compose.waitForIdle()
        }
    }

    private fun text(text: String) = compose.onAllNodes(hasText(text), useUnmergedTree = true)
    private fun shown(text: String) = text(text).fetchSemanticsNodes().isNotEmpty()
    private fun heading(text: String) =
        compose.onAllNodes(hasText(text) and SemanticsMatcher.keyIsDefined(SemanticsProperties.Heading), useUnmergedTree = true)
    private fun described(label: String) = compose.onAllNodes(hasContentDescription(label) and hasClickAction())
    private fun button(label: String) = compose.onAllNodes(hasText(label) and hasClickAction()).onLast()

    private fun click(node: SemanticsNodeInteraction) {
        try { node.performScrollTo() } catch (_: Throwable) { }
        node.performSemanticsAction(SemanticsActions.OnClick)
        compose.waitForIdle()
    }

    /** What a form to [endpoint] sent, as PHP reads it into $_POST. */
    private fun sent(endpoint: String): Map<String, String> {
        compose.waitUntil(10_000) { server.requestsTo(endpoint).isNotEmpty() }
        val body = server.requestsTo(endpoint).last().body.optString("form")
        return body.split('&').filter { it.contains('=') }.associate {
            URLDecoder.decode(it.substringBefore('='), "UTF-8") to URLDecoder.decode(it.substringAfter('='), "UTF-8")
        }
    }

    @Test
    fun `Slaap's + opens its own page - the morning, to bed, up, the night they make - and sends it, back on Slaap`() {
        show(Detail.HealthArea("sleep"))

        assertTrue("no Training + on Slaap", described("Training invoeren").fetchSemanticsNodes().isEmpty())
        click(described("Slaap invoeren").onLast())

        assertTrue(heading("Slaap invoeren").fetchSemanticsNodes().isNotEmpty())
        for (label in listOf("OCHTEND", "NAAR BED", "OPGESTAAN")) assertTrue(label, shown(label))
        assertTrue("its note", shown("De ochtend waarop je opstond. Een nacht hoort bij de ochtend waarop hij eindigt."))
        // 23:00 → 07:00 on the morning of Wednesday 4 November: from Tuesday evening.
        assertTrue("the night it makes", shown("di 3 nov 23:00 tot wo 4 nov 07:00 · 8:00 u"))
        assertTrue("only Slaap's fields", !shown("ACTIVITEIT") && !shown("DUUR"))

        click(button("Opslaan"))
        assertEquals(mapOf("date" to "2026-11-04", "bedtime" to "23:00", "wakeup" to "07:00"), sent("sleep-entry.php"))
        compose.waitUntil(10_000) { heading("Slaap invoeren").fetchSemanticsNodes().isEmpty() }
        assertEquals("back on Slaap", "health:sleep", shell.detail?.key)
    }

    @Test
    fun `Training's + opens its own page - the same layout, its own fields - and a training already there is refused, as the server says`() {
        server.entryOverlap = true
        show(Detail.HealthArea("training"))

        click(described("Training invoeren").onLast())
        assertTrue(heading("Training invoeren").fetchSemanticsNodes().isNotEmpty())
        for (label in listOf("DATUM", "ACTIVITEIT", "BEGINTIJD", "DUUR", "VERBRANDE CALORIEËN")) assertTrue(label, shown(label))
        assertTrue("Health Connect's types, the first chosen", shown("Hardlopen"))
        assertTrue("calories optional", shown("Optioneel"))
        assertTrue("only Training's fields", !shown("NAAR BED") && !shown("OPGESTAAN"))
        // The same buttons as Slaap's page.
        assertTrue(shown("Annuleren") && shown("Opslaan"))

        click(button("Opslaan"))
        assertEquals(mapOf("date" to "2026-11-04", "type" to "running", "start" to "18:00", "minutes" to "30", "kcal" to ""), sent("training-entry.php"))
        compose.waitUntil(10_000) { shown("Er staat al een activiteit geregistreerd op dit tijdstip.") }
        assertEquals("still on the entry page", "entry:training", shell.detail?.key)

        // Annuleren goes back to Training, nothing sent.
        click(button("Annuleren"))
        compose.waitUntil(5_000) { shell.detail?.key == "health:training" }
    }

    @Test
    fun `no + on Voeding`() {
        show(Detail.HealthArea("nutrition"))
        assertTrue(described("Slaap invoeren").fetchSemanticsNodes().isEmpty())
        assertTrue(described("Training invoeren").fetchSemanticsNodes().isEmpty())
    }

    /**
     * The training's page itself draws without end in Robolectric (its heart
     * plot), so its delete is composed here on its own, with the session as
     * the server sent it (training-view.json): synced, not entered by hand.
     */
    @Test
    fun `a training's page - Doelen's delete, its confirmation saying a synced one stays where it came from, then removed`() {
        val data = AppData.parse(state().getJSONObject("data"))
        val session = (data.health.area("training")!!.view as TrainingView).session("839")!!
        compose.setContent {
            OwnifyTheme {
                CompositionLocalProvider(
                    LocalShell provides ShellState(listOf("health"), "health", rememberCoroutineScope()),
                    LocalStillMotion provides true
                ) {
                    Column { RemoveTraining(data.manualEntry, session) }
                }
            }
        }
        compose.waitForIdle()

        click(button("Training verwijderen"))
        assertTrue(shown("Weet je het zeker? De training verdwijnt uit je overzichten, je score en je punten."))
        assertTrue("a synced one: only from Ownify",
            shown("Alleen uit Ownify: waar hij vandaan kwam, zoals Health Connect, blijft hij staan. Een volgende synchronisatie zet hem niet terug."))

        // Annuleren puts the button back, nothing sent.
        click(button("Annuleren"))
        assertTrue(shown("Training verwijderen"))
        assertTrue(server.requestsTo("training-delete.php").isEmpty())

        click(button("Training verwijderen"))
        click(button("Verwijderen"))
        assertEquals(mapOf("workout_id" to "839"), sent("training-delete.php"))
    }

    @Test
    fun `one entered by hand - no line about where it came from`() {
        val data = AppData.parse(state().getJSONObject("data"))
        val session = (data.health.area("training")!!.view as TrainingView).session("839")!!.copy(manual = true)
        compose.setContent {
            OwnifyTheme {
                CompositionLocalProvider(
                    LocalShell provides ShellState(listOf("health"), "health", rememberCoroutineScope()),
                    LocalStillMotion provides true
                ) {
                    Column { RemoveTraining(data.manualEntry, session) }
                }
            }
        }
        compose.waitForIdle()
        click(button("Training verwijderen"))
        assertTrue(shown("Weet je het zeker? De training verdwijnt uit je overzichten, je score en je punten."))
        assertTrue(!shown("Alleen uit Ownify: waar hij vandaan kwam, zoals Health Connect, blijft hij staan. Een volgende synchronisatie zet hem niet terug."))
    }
}
