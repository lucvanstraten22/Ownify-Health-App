package com.ownify.android.ui

import android.content.Context
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.test.SemanticsNodeInteraction
import androidx.compose.ui.test.assertIsEnabled
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasSetTextAction
import androidx.compose.ui.test.hasTestTag
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.onLast
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performSemanticsAction
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.test.performTextInput
import androidx.test.core.app.ApplicationProvider
import androidx.work.Configuration
import androidx.work.testing.SynchronousExecutor
import androidx.work.testing.WorkManagerTestInitHelper
import com.ownify.android.connection.FakeOwnifyServer
import com.ownify.android.connection.MemoryTokenStorage
import com.ownify.android.connection.OwnifyApi
import com.ownify.android.connection.OwnifyConnection
import com.ownify.android.connection.OwnifySyncRunner
import com.ownify.android.connection.OwnifySyncStatusPrefs
import com.ownify.android.connection.TestSyncEnvironment
import com.ownify.android.data.OwnifyAppState
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
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/**
 * Uitdagingen in the app, as a person uses it (docs/CHALLENGES.md): the two
 * cards on Community (the podium, the stack and the two ways to start one),
 * Uitdagingen in its groups and, with nothing yet, its ideas, a
 * code looked up and reviewed before joining, a new challenge made, an
 * invitation turned down, a challenge's page and leaving it — each change
 * posted to the endpoint the website posts to. The server is a stand-in
 * answering with what the real one answered (challenges-state.json,
 * challenge-lookup.json).
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [36], qualifiers = "+w412dp-h915dp-port")
class ChallengesFlowTest {

    @get:Rule
    val compose = createComposeRule()

    private lateinit var context: Context
    private lateinit var server: FakeOwnifyServer

    @Before
    fun setUp() {
        context = ApplicationProvider.getApplicationContext()
        WorkManagerTestInitHelper.initializeTestWorkManager(context, Configuration.Builder().setExecutor(SynchronousExecutor()).build())
        server = FakeOwnifyServer()
        server.withChallenges()
        OwnifyConnection.api = OwnifyApi(server.url)
        OwnifyConnection.storage = { MemoryTokenStorage }
        TestSyncEnvironment.context = context
        TestSyncEnvironment.api = OwnifyApi(server.url)
        OwnifySyncRunner.environment = { TestSyncEnvironment }
        MemoryTokenStorage.clear()
        OwnifyConnection.reset()
        OwnifyAppState.clear()
        GoalBoard.clear()
        OwnifySyncStatusPrefs(context).clear()
        OwnifyThemeStore.forget(context)
        Ownify.use(OwnifyMode.DARK)
        MemoryTokenStorage.signedIn()
    }

    @After
    fun tearDown() {
        server.holdGate.countDown()
        server.stop()
        OwnifyAppState.clear()
    }

    private fun show() = compose.setContent {
        OwnifyTheme {
            CompositionLocalProvider(LocalStillMotion provides true) {
                OwnifyApp(OwnifyScreens.shell, OwnifyScreens.signedOut)
            }
        }
    }

    private fun waitFor(text: String, timeout: Long = 15_000) =
        compose.waitUntil(timeout) { compose.onAllNodesWithText(text, substring = true).fetchSemanticsNodes().isNotEmpty() }

    private fun gone(text: String) = compose.onAllNodesWithText(text, substring = true).fetchSemanticsNodes().isEmpty()

    private fun button(text: String): SemanticsNodeInteraction =
        compose.onAllNodes(hasText(text, substring = true) and hasClickAction()).onLast()

    /** Signed in, on Community. */
    private fun community() {
        show()
        waitFor("Gezondheidsscore")
        compose.onAllNodes(hasText("Community") and hasClickAction()).onLast().performClick()
        waitFor("Uitdagingen staan los van je punten")
    }

    /** From Community, Uitdagingen's card: the list. */
    private fun challenges() {
        community()
        compose.onAllNodes(hasText("Uitdagingen") and hasClickAction()).onFirst().performClick()
        waitFor("Samen of tegen elkaar, met wie jij kiest.")
    }

    private fun lookupFixture() = javaClass.classLoader!!.getResource("challenge-lookup.json").readText()

    @Test
    fun `Community - the podium, the stack and the two ways to start one, no code field`() {
        community()
        waitFor("Ranglijst")
        waitFor("Vrienden · deze maand")
        compose.onNode(hasContentDescription("De top drie van je vrienden deze maand")).assertExists()
        waitFor("3 bezig · 1 uitnodiging")
        waitFor("bram_fietst nodigt je uit voor Samen 500 km fietsen")
        // The stack: one challenge on top, the one that ends first.
        assertEquals(1, compose.onAllNodes(hasTestTag("challenge-tile")).fetchSemanticsNodes().size)
        compose.onAllNodes(hasText("Nieuwe uitdaging") and hasClickAction()).onFirst().assertIsEnabled()
        compose.onAllNodes(hasText("Code invoeren") and hasClickAction()).onFirst().assertIsEnabled()
        waitFor("Uitdagingen staan los van je punten op de ranglijst.")
        assertTrue("no code field on Community", compose.onAllNodes(hasTestTag("challenge-code")).fetchSemanticsNodes().isEmpty())

        compose.onAllNodes(hasText("Uitdagingen") and hasClickAction()).onFirst().performClick()
        waitFor("Samen of tegen elkaar, met wie jij kiest.")
        button("Nieuwe uitdaging").assertIsEnabled()
        button("Code invoeren").assertIsEnabled()
    }

    @Test
    fun `Uitdagingen - the invitations, then Samen and Wedstrijd apart`() {
        challenges()
        waitFor("UITNODIGINGEN")
        waitFor("SAMEN")
        waitFor("WEDSTRIJD")
        waitFor("100 trainingen in oktober")
        waitFor("Wie zet de meeste stappen?")
    }

    @Test
    fun `Uitdagingen - with nothing yet, an idea opens Nieuwe uitdaging with its kind and metric chosen`() {
        server.withChallenges(empty = true)
        server.challengeReplies["create.php"] = 200 to """{"ok":true,"id":97,"code":"22222","name":"Wie zet de meeste stappen?","invited":0,"share_text":"…"}"""
        community()
        waitFor("Nog geen uitdagingen")
        compose.onAllNodes(hasText("Uitdagingen") and hasClickAction()).onFirst().performClick()
        waitFor("Begin met een van deze, of maak je eigen.")
        waitFor("Samen trainen")
        waitFor("Samen goed slapen")

        compose.onAllNodes(hasText("Wie zet de meeste stappen?") and hasClickAction()).onLast().performScrollTo().performClick()
        waitFor("Wie de meeste stappen haalt")
        button("Uitdaging maken").assertIsEnabled().performClick()
        waitFor("Je uitdaging staat klaar")

        val sent = server.requestsTo("create.php").single().body
        assertEquals("versus", sent.getString("kind"))
        assertEquals("steps", sent.getString("metric"))
    }

    @Test
    fun `Ranglijst - the card opens the full board, scopes and periods as before`() {
        community()
        compose.onAllNodes(hasText("Ranglijst") and hasClickAction()).onFirst().performClick()
        waitFor("All-time")
        button("Nederland").performClick()
        button("Jaar").performClick()
        compose.onNode(hasContentDescription("Terug naar Community") and hasClickAction()).performClick()
        compose.waitUntil(5_000) { gone("All-time") }
    }

    @Test
    fun `Code invoeren - the fifth digit looks it up, it is shown first, and only Meedoen joins`() {
        server.challengeReplies["lookup.php"] = 200 to lookupFixture()
        server.challengeReplies["join.php"] = 200 to """{"ok":true,"status":"joined","id":3,"name":"Samen 500 km fietsen","message":"Je doet mee"}"""
        challenges()

        button("Code invoeren").performClick()
        waitFor("Vul de 5 cijfers in")
        compose.onNode(hasContentDescription("Code van 5 cijfers") and hasSetTextAction()).performTextInput("1234")
        compose.waitForIdle()
        assertTrue("four digits look nothing up", server.requestsTo("lookup.php").isEmpty())
        compose.onNode(hasContentDescription("Code van 5 cijfers") and hasSetTextAction()).performTextInput("5")

        waitFor("Gevonden met code")
        assertEquals("12345", server.requestsTo("lookup.php").single().body.getString("code"))
        waitFor("Meedoen?")
        waitFor("Als je meedoet, zien de deelnemers je gebruikersnaam")
        assertTrue("looking joins nothing", server.requestsTo("join.php").isEmpty())

        button("Meedoen").performClick()
        waitFor("Je doet mee")
        assertEquals("12345", server.requestsTo("join.php").single().body.getString("code"))
    }

    @Test
    fun `Code invoeren - a wrong code says so under the boxes, and joins nothing`() {
        server.challengeReplies["lookup.php"] = 404 to """{"ok":false,"status":"none","error":"Geen uitdaging met deze code. Kijk de cijfers nog eens na."}"""
        challenges()

        button("Code invoeren").performClick()
        compose.onNode(hasContentDescription("Code van 5 cijfers") and hasSetTextAction()).performTextInput("00000")
        waitFor("Geen uitdaging met deze code. Kijk de cijfers nog eens na.")
        assertTrue(server.requestsTo("join.php").isEmpty())
    }

    @Test
    fun `Nieuwe uitdaging - nothing to make until it counts something, then its code, and the request the website sends`() {
        server.challengeReplies["create.php"] = 200 to JSONObject()
            .put("ok", true).put("id", 99).put("code", "48213").put("name", "Wie zet de meeste stappen?")
            .put("invited", 1).put("share_text", "Doe mee met “Wie zet de meeste stappen?” in Ownify: vul code 48213 in bij Community › Uitdagingen.")
            .put("message", "Je uitdaging staat klaar").toString()
        community()

        compose.onAllNodes(hasText("Nieuwe uitdaging") and hasClickAction()).onFirst().performScrollTo().performClick()
        // The form's labels are small caps, as on the website.
        waitFor("WAT TELLEN JULLIE?")
        button("Kies eerst wat jullie tellen").assertIsNotEnabled()

        button("Wedstrijd").performClick()
        button("Stappen").performScrollTo().performClick()
        waitFor("Wie zet de meeste stappen?")
        waitFor("Wie de meeste stappen haalt")
        val friend = compose.onAllNodes(hasText("lotte") and hasClickAction()).onLast()
        friend.performScrollTo().performClick()
        waitFor("1 uitgenodigd")

        button("Uitdaging maken").assertIsEnabled().performClick()
        waitFor("Je uitdaging staat klaar")
        compose.onNode(hasContentDescription("4 8 2 1 3")).assertExists()

        val sent = server.requestsTo("create.php").single().body
        assertEquals("versus", sent.getString("kind"))
        assertEquals("steps", sent.getString("metric"))
        assertEquals("1w", sent.getString("length"))
        assertEquals("today", sent.getString("from"))
        assertEquals(1, sent.getJSONArray("invite").length())
        assertTrue("a Wedstrijd has no target", !sent.has("target"))
    }

    @Test
    fun `Nieuwe uitdaging - Samen suggests a target and steps it, in what is shown`() {
        server.challengeReplies["create.php"] = 200 to """{"ok":true,"id":98,"code":"11111","name":"Samen 150 km in een week","invited":0,"share_text":"…"}"""
        challenges()

        button("Nieuwe uitdaging").performClick()
        button("Afstand").performScrollTo().performClick()
        waitFor("Samen 100 km in een week")
        compose.onNode(hasContentDescription("Meer") and hasClickAction()).performScrollTo().performClick()
        compose.onNode(hasContentDescription("Meer") and hasClickAction()).performClick()
        waitFor("Samen 150 km in een week")
        button("Uitdaging maken").performClick()
        waitFor("Je uitdaging staat klaar")

        val sent = server.requestsTo("create.php").single().body
        assertEquals("together", sent.getString("kind"))
        assertEquals(150.0, sent.getDouble("target"), 0.0)
        assertEquals("km", sent.getString("target_unit"))
    }

    @Test
    fun `an invitation - Bekijken shows what it is, Afwijzen turns it down`() {
        server.challengeReplies["respond.php"] = 200 to """{"ok":true,"message":"Uitnodiging afgewezen"}"""
        community()

        button("Bekijken").performClick()
        waitFor("bram_fietst nodigt je uit")
        waitFor("Gemaakt door")
        button("Afwijzen").performClick()

        compose.waitUntil(10_000) { server.requestsTo("respond.php").isNotEmpty() }
        val sent = server.requestsTo("respond.php").single().body
        assertEquals("decline", sent.getString("action"))
        assertTrue(sent.getInt("challenge_id") > 0)
        waitFor("Uitnodiging afgewezen")
    }

    @Test
    fun `a challenge's page - over Uitdagingen, its people and its code, and leaving asks first`() {
        server.challengeReplies["respond.php"] = 200 to """{"ok":true,"message":"Je doet niet meer mee aan 100 trainingen in oktober"}"""
        challenges()

        compose.onAllNodes(hasText("100 trainingen in oktober") and hasClickAction()).onLast().performScrollTo().performClick()
        waitFor("Wie draagt wat bij")
        compose.onNode(hasContentDescription("Terug naar Uitdagingen") and hasClickAction()).assertExists()
        waitFor("noor_e is uitgenodigd")
        waitFor("Iemand erbij halen")

        // Activated as TalkBack activates it: whatever the dock floats over.
        button("Uitdaging verlaten").performScrollTo().performSemanticsAction(SemanticsActions.OnClick)
        waitFor("Uitdaging verlaten?")
        compose.onAllNodes(hasText("Verlaten") and hasClickAction()).onLast().performClick()

        compose.waitUntil(10_000) { server.requestsTo("respond.php").isNotEmpty() }
        assertEquals("leave", server.requestsTo("respond.php").single().body.getString("action"))
    }

    @Test
    fun `the stack on Community - its page goes back to Community`() {
        community()
        compose.onAllNodes(hasTestTag("challenge-tile")).onFirst().performClick()
        compose.waitUntil(10_000) {
            compose.onAllNodes(hasContentDescription("Terug naar Community") and hasClickAction()).fetchSemanticsNodes().isNotEmpty()
        }
    }
}
