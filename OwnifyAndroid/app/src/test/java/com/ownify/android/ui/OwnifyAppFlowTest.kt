package com.ownify.android.ui

import android.content.Context
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.test.SemanticsNodeInteraction
import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.assertHeightIsAtLeast
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.assertIsFocused
import androidx.compose.ui.test.assertIsOff
import androidx.compose.ui.test.assertIsOn
import androidx.compose.ui.test.assertWidthIsAtLeast
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasSetTextAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.performTouchInput
import androidx.compose.ui.test.hasTestTag
import androidx.compose.ui.test.isHeading
import androidx.compose.ui.test.isSelected
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onLast
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performSemanticsAction
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.test.performTextInput
import androidx.compose.ui.unit.dp
import androidx.test.core.app.ApplicationProvider
import androidx.work.Configuration
import androidx.work.testing.SynchronousExecutor
import androidx.work.testing.WorkManagerTestInitHelper
import com.ownify.android.data.OwnifyAppState
import com.ownify.android.connection.ACCOUNT_TOKEN
import com.ownify.android.connection.FakeOwnifyServer
import com.ownify.android.connection.OwnifyApi
import com.ownify.android.connection.OwnifyConnection
import com.ownify.android.connection.OwnifyCredential
import com.ownify.android.connection.OwnifyScope
import com.ownify.android.connection.OwnifySyncRunner
import com.ownify.android.connection.OwnifySyncStatusPrefs
import com.ownify.android.connection.MemoryTokenStorage
import com.ownify.android.connection.TEST_TOKEN
import com.ownify.android.connection.TestSyncEnvironment
import com.ownify.android.ui.app.OwnifyApp
import com.ownify.android.ui.design.LocalStillMotion
import com.ownify.android.ui.screens.OwnifyScreens
import com.ownify.android.ui.screens.goals.GoalBoard
import com.ownify.android.ui.theme.OwnifyTheme
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/**
 * The app as a person uses it, against a Ownify server on localhost: the
 * opening screen, signing in and up, the pages the server sends, moving
 * between them, signing out — and what the screen does when the server is
 * away or says the session is over. Only the server and Health Connect are
 * stand-ins; the connection, the token store's rules, the page read and
 * every screen are the app's own.
 */
/** A 1×1 PNG: a profile picture as the server serves it. */
private const val ONE_PIXEL_PNG = "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=="

@RunWith(RobolectricTestRunner::class)
@Config(sdk = [36], qualifiers = "w412dp-h915dp-port")
class OwnifyAppFlowTest {

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
        OwnifySyncRunner.environment = { TestSyncEnvironment }
        MemoryTokenStorage.clear()
        OwnifyConnection.reset()
        OwnifyAppState.clear()
        GoalBoard.clear()
        OwnifySyncStatusPrefs(context).clear()
    }

    @After
    fun tearDown() {
        server.holdGate.countDown()
        server.stop()
        OwnifyAppState.clear()
    }

    /** The app as MainActivity shows it; motion held still so the test can settle. */
    private fun show() = compose.setContent {
        OwnifyTheme {
            CompositionLocalProvider(LocalStillMotion provides true) {
                OwnifyApp(OwnifyScreens.shell, OwnifyScreens.signedOut)
            }
        }
    }

    private fun waitFor(text: String, timeout: Long = 15_000) =
        compose.waitUntil(timeout) { compose.onAllNodesWithText(text, substring = true).fetchSemanticsNodes().isNotEmpty() }

    private fun button(text: String): SemanticsNodeInteraction =
        compose.onAllNodes(hasText(text) and hasClickAction()).onLast()

    private fun field(label: String): SemanticsNodeInteraction =
        compose.onNode(hasContentDescription(label) and hasSetTextAction())

    /** The overview's score card: the pages have arrived. */
    private fun waitForPages() = waitFor("Gezondheidsscore")

    // ------------------------------------------------------------- signed out

    @Test
    fun `signed out - the opening screen, sign in, and the app opens on Overzicht with the account's pages`() {
        show()

        waitFor("Registreren")
        compose.onNodeWithTextExact("Ownify").assertIsDisplayed()
        button("Inloggen").performClick()

        field("Gebruikersnaam").performTextInput("sanne")
        field("Wachtwoord").performTextInput("geheim-wachtwoord")
        button("Inloggen").performClick()

        waitForPages()
        assertEquals(OwnifyCredential(ACCOUNT_TOKEN, OwnifyScope.ACCOUNT), MemoryTokenStorage.load())
        assertEquals("geheim-wachtwoord", server.requestsTo("app-login.php").single().body.getString("password"))
        assertEquals("Bearer $ACCOUNT_TOKEN", server.requestsTo("state.php").last().headers["authorization"])
    }

    @Test
    fun `a wrong password - the server's message in the panel, and still signed out`() {
        server.loginMode = FakeOwnifyServer.LoginMode.WRONG_PASSWORD
        show()

        waitFor("Registreren")
        button("Inloggen").performClick()
        field("Gebruikersnaam").performTextInput("sanne")
        field("Wachtwoord").performTextInput("fout")
        button("Inloggen").performClick()

        waitFor("Gebruikersnaam of wachtwoord klopt niet.")
        assertNull(MemoryTokenStorage.load())
        assertTrue(server.requestsTo("state.php").isEmpty())
    }

    @Test
    fun `registration - a new account, and the app opens`() {
        show()

        waitFor("Registreren")
        button("Registreren").performClick()
        field("Gebruikersnaam").performTextInput("nieuw")
        field("E-mailadres").performTextInput("nieuw@example.com")
        field("Wachtwoord").performTextInput("lang-genoeg-wachtwoord")
        button("Account aanmaken").performScrollTo().performClick()

        waitForPages()
        assertEquals(OwnifyCredential(ACCOUNT_TOKEN, OwnifyScope.ACCOUNT), MemoryTokenStorage.load())
        assertEquals("nieuw", server.requestsTo("app-register.php").single().body.getString("username"))
    }

    // -------------------------------------------------------------- signed in

    @Test
    fun `the session is restored - a stored account token opens the app without a password`() {
        MemoryTokenStorage.signedIn()
        show()

        waitForPages()
        assertTrue(server.requestsTo("app-login.php").isEmpty())
        assertEquals(OwnifyCredential(ACCOUNT_TOKEN, OwnifyScope.ACCOUNT), MemoryTokenStorage.load())
    }

    @Test
    fun `signing out in Instellingen - revoked on the server, forgotten here, back to the opening screen`() {
        MemoryTokenStorage.signedIn()
        show()
        waitForPages()

        button("Instellingen").performClick()
        // At the bottom of the page, where the dock floats over it: activated
        // as TalkBack activates it, without a finger landing on the dock.
        button("Uitloggen").performScrollTo().performSemanticsAction(SemanticsActions.OnClick)

        waitFor("Registreren")
        assertNull(MemoryTokenStorage.load())
        assertEquals("Bearer $ACCOUNT_TOKEN", server.requestsTo("app-logout.php").single().headers["authorization"])
        assertTrue(ACCOUNT_TOKEN in server.revoked)
    }

    @Test
    fun `offline at start - still signed in, the screen says the pages could not be loaded, and trying again loads them`() {
        MemoryTokenStorage.signedIn()
        OwnifyConnection.api = OwnifyApi("http://127.0.0.1:9/")
        show()

        waitFor("Je gegevens konden niet worden geladen")
        assertEquals(OwnifyCredential(ACCOUNT_TOKEN, OwnifyScope.ACCOUNT), MemoryTokenStorage.load())

        OwnifyConnection.api = OwnifyApi(server.url)
        button("Opnieuw proberen").performClick()
        waitForPages()
    }

    @Test
    fun `a session revoked on the website - signed out to the opening screen, the credential gone`() {
        MemoryTokenStorage.signedIn()
        server.revoked += ACCOUNT_TOKEN
        show()

        waitFor("Registreren")
        assertNull(MemoryTokenStorage.load())
        assertTrue(server.requestsTo("state.php").all { it.headers["authorization"] == "Bearer $ACCOUNT_TOKEN" })
    }

    @Test
    fun `a phone paired with a code - the opening screen with its sync, and no account pages read`() {
        MemoryTokenStorage.token = TEST_TOKEN
        show()

        waitFor("Gekoppeld aan sanne")
        compose.onAllNodesWithText("Nu synchroniseren").fetchSemanticsNodes().isNotEmpty().let(::assertTrue)
        assertTrue(server.requestsTo("state.php").isEmpty())
        assertEquals(OwnifyCredential(TEST_TOKEN, OwnifyScope.SYNC), MemoryTokenStorage.load())
    }

    // ------------------------------------------------------ moving around

    @Test
    fun `the tabs, a detail page and back, the account panel and the assistant`() {
        MemoryTokenStorage.signedIn()
        show()
        waitForPages()

        // Overzicht is where the app opens, and its tab says so.
        compose.onNode(hasText("Overzicht") and isSelected()).assertExists()

        button("Gezondheid").performClick()
        compose.onNode(hasText("Gezondheid") and isSelected()).assertExists()
        compose.onNode(hasText("Gezondheid") and isHeading()).assertExists()

        compose.onNode(hasContentDescription("Slaap —", substring = true) and hasClickAction()).performClick()
        compose.onNode(hasText("Slaap") and isHeading()).assertExists()
        // The page under the detail is out of reach, as the website makes it inert.
        compose.onAllNodes(hasContentDescription("Voeding —", substring = true)).assertCountEquals(0)

        compose.onNode(hasContentDescription("Terug naar Gezondheid") and hasClickAction()).performClick()
        compose.waitUntil(5_000) { compose.onAllNodes(hasContentDescription("Voeding —", substring = true)).fetchSemanticsNodes().isNotEmpty() }

        compose.onNode(hasContentDescription("Account en profiel openen", substring = true)).performClick()
        waitFor("Lid sinds")
        compose.onNode(hasContentDescription("Sluiten") and hasClickAction()).performClick()
        compose.waitUntil(5_000) { compose.onAllNodesWithText("Lid sinds", substring = true).fetchSemanticsNodes().isEmpty() }

        compose.onNode(hasContentDescription("Assistent openen", substring = true)).performClick()
        waitFor("Binnenkort beschikbaar")
    }

    @Test
    fun `Gezondheid's intro is the server's - how many more days unlock a score, then the three pillars once there is one`() {
        val demo = server.stateBody
        server.stateBody = org.json.JSONObject(demo).apply {
            getJSONObject("data").getJSONObject("health").put("lede", "Je hebt nog 2 dagen data nodig om een score te ontgrendelen.")
        }.toString()
        MemoryTokenStorage.signedIn()
        show()
        waitForPages()

        button("Gezondheid").performClick()
        waitFor("Je hebt nog 2 dagen data nodig om een score te ontgrendelen.")

        // New data on the server (a sync, a rating): the app's next read of its pages brings the pillars back.
        server.stateBody = demo
        OwnifyAppState.refresh(context)
        waitFor("Je drie pijlers. Tik op een onderdeel voor de details.")
        assertTrue(compose.onAllNodesWithText("nog 2 dagen", substring = true).fetchSemanticsNodes().isEmpty())
    }

    @Test
    fun `every icon the server names exists here, Voeding's fork and knife among them`() {
        for (fixture in listOf("state-demo.json", "state-new-account.json")) {
            val text = javaClass.classLoader!!.getResource(fixture).readText()
            val names = Regex("\"icon\"\\s*:\\s*\"([a-z-]+)\"").findAll(text).map { it.groupValues[1] }.toSet()
            assertTrue("$fixture names icons", names.isNotEmpty())
            names.forEach { assertTrue("$fixture: $it", it in com.ownify.android.ui.design.OwnifyIcons.byName) }
            val nutrition = org.json.JSONObject(text).getJSONObject("data").getJSONObject("health")
                .getJSONObject("areas").getJSONObject("nutrition").getString("icon")
            assertEquals("utensils", nutrition)
        }
        assertTrue("the leaf is gone", "leaf" !in com.ownify.android.ui.design.OwnifyIcons.byName)
    }

    @Test
    fun `Community - Vrienden toevoegen above #1 on every Vrienden board, never on Nederland, and it opens Vriend toevoegen`() {
        MemoryTokenStorage.signedIn()
        show()
        waitForPages()
        button("Community").performClick()
        waitFor("Vrienden")

        val add = hasContentDescription("Vrienden toevoegen") and hasClickAction()
        val first = androidx.compose.ui.test.SemanticsMatcher("rank 1") { node ->
            node.config.getOrElse(androidx.compose.ui.semantics.SemanticsProperties.ContentDescription) { emptyList() }
                .any { it.startsWith("1. ") || it.contains(": 1. ") }
        }
        fun adds() = compose.onAllNodes(add).fetchSemanticsNodes()

        for (period in listOf("Maand", "Jaar", "All-time")) {
            button(period).performClick()
            compose.waitForIdle()
            val row = adds().single().boundsInRoot
            val one = compose.onAllNodes(first).fetchSemanticsNodes().first().boundsInRoot
            assertTrue("Vrienden · $period: directly above #1 ($row / $one)", row.bottom <= one.top && one.top - row.bottom <= 5 * compose.density.density)
            assertEquals("Vrienden · $period: #1's width", one.width, row.width, 0.5f)
            assertEquals("Vrienden · $period: a row's height", one.height, row.height, 0.5f)
        }

        button("Nederland").performClick()
        for (period in listOf("Maand", "Jaar", "All-time")) {
            button(period).performClick()
            compose.waitForIdle()
            assertTrue("Nederland · $period: no Vrienden toevoegen", adds().isEmpty())
            assertTrue(compose.onAllNodesWithText("Vrienden toevoegen", substring = true, useUnmergedTree = true).fetchSemanticsNodes().isEmpty())
        }

        button("Vrienden").performClick()
        compose.waitForIdle()
        assertEquals("back on Vrienden: there again", 1, adds().size)

        compose.onNode(add).performClick()
        compose.waitUntil(5_000) { compose.onAllNodes(hasText("Vrienden") and isHeading()).fetchSemanticsNodes().isNotEmpty() }
        compose.waitUntil(5_000) { compose.onAllNodes(hasContentDescription("Gebruikersnaam") and hasSetTextAction()).fetchSemanticsNodes().isNotEmpty() }
        field("Gebruikersnaam").assertIsFocused()
    }

    @Test
    fun `Community - a row with a profile picture shows it, fetched from the server`() {
        val path = "uploads/avatars/u776-ea125a85cccfbfc9.png"
        server.files["/$path"] = android.util.Base64.decode(ONE_PIXEL_PNG, android.util.Base64.DEFAULT)
        MemoryTokenStorage.signedIn()
        show()
        waitForPages()
        button("Community").performClick()
        waitFor("Vrienden")

        compose.waitUntil(15_000) { com.ownify.android.ui.app.Avatars.cached(path) != null }
        assertEquals("fetched once, as a plain file", 1, server.requests.count { it.path == "/$path" })
    }

    @Test
    fun `Privacy - Profielfoto op de ranglijst saves at once, and moves back saying why when it cannot`() {
        MemoryTokenStorage.signedIn()
        show()
        waitForPages()
        button("Instellingen").performClick()
        // Activated as TalkBack activates them: whatever the dock floats over.
        compose.onNode(hasContentDescription("Privacy —", substring = true) and hasClickAction())
            .performSemanticsAction(SemanticsActions.OnClick)
        waitFor("Profielfoto op de ranglijst")

        val toggle = compose.onNode(hasText("Profielfoto op de ranglijst", substring = true) and hasClickAction())
        toggle.assertIsOn()
        compose.onAllNodesWithText("Anderen zien je profielfoto naast je naam.", substring = true).fetchSemanticsNodes().isNotEmpty().let(::assertTrue)

        toggle.performSemanticsAction(SemanticsActions.OnClick)
        waitFor("Op de ranglijst staat je initiaal in plaats van je foto.")
        toggle.assertIsOff()
        val saved = server.requestsTo("privacy.php").single()
        assertEquals("Bearer $ACCOUNT_TOKEN", saved.headers["authorization"])
        assertEquals("leaderboard_avatar=0", saved.body.optString("form"))

        // The pages read again say the same: off stays off.
        compose.waitUntil(15_000) { server.requestsTo("state.php").size >= 2 }
        compose.waitForIdle()
        toggle.assertIsOff()

        // Before migration 014 the server cannot save it: back as it was, and why.
        server.privacySaves = false
        toggle.performSemanticsAction(SemanticsActions.OnClick)
        waitFor("Deze instelling kan nog niet worden opgeslagen.")
        toggle.assertIsOff()
    }

    @Test
    fun `Persoonlijk doel - stops at 0, 50, 85 and 100, no line under the bar, and the reading shows while a finger rests on it`() {
        MemoryTokenStorage.signedIn()
        show()
        waitForPages()

        val bar = compose.onNode(hasContentDescription("0% — 0 dagen van 14 dagen op rij"))
        bar.performScrollTo()
        compose.waitForIdle()
        val track = bar.fetchSemanticsNode().boundsInRoot
        fun label(text: String) = compose.onAllNodesWithText(text).fetchSemanticsNodes().single().boundsInRoot
        val tolerance = 1.5f * compose.density.density
        assertEquals("Start from the left edge", track.left, label("Start").left, tolerance)
        assertEquals("Halverwege centred at 50%", track.left + track.width * 0.50f, label("Halverwege").center.x, tolerance)
        assertEquals("Bijna centred at 85%", track.left + track.width * 0.85f, label("Bijna").center.x, tolerance)
        assertEquals("Doel to the right edge", track.right, label("Doel").right, tolerance)

        // The line that said it under the bar is gone; the reading is on the bar, and only when asked.
        val reading = hasTestTag(com.ownify.android.ui.screens.overview.GoalReadingTag)
        assertTrue(compose.onAllNodesWithText("0 dagen van 14 dagen op rij", substring = true).fetchSemanticsNodes().isEmpty())
        assertTrue(compose.onAllNodes(reading, useUnmergedTree = true).fetchSemanticsNodes().isEmpty())

        // A tap: nothing.
        bar.performTouchInput { down(center); up() }
        compose.mainClock.advanceTimeBy(600)
        assertTrue("a tap shows nothing", compose.onAllNodes(reading, useUnmergedTree = true).fetchSemanticsNodes().isEmpty())

        // A finger at rest on it: the reading, until it lets go.
        bar.performTouchInput { down(center) }
        compose.mainClock.advanceTimeBy(600)
        compose.onNode(reading, useUnmergedTree = true).assertExists()
        val bubble = compose.onNode(reading, useUnmergedTree = true).fetchSemanticsNode().boundsInRoot
        assertTrue("above the bar ($bubble / $track)", bubble.bottom <= track.top)
        bar.performTouchInput { up() }
        compose.mainClock.advanceTimeBy(600)
        assertTrue("letting go hides it", compose.onAllNodes(reading, useUnmergedTree = true).fetchSemanticsNodes().isEmpty())
    }

    @Test
    fun `accessibility - named controls, touch targets as large as the website's, and one heading per page`() {
        MemoryTokenStorage.signedIn()
        show()
        waitForPages()

        // The header's two round buttons: 46 across, named for what they open.
        compose.onNode(hasContentDescription("Account en profiel openen", substring = true)).assertWidthIsAtLeast(46.dp).assertHeightIsAtLeast(46.dp)
        compose.onNode(hasContentDescription("Verbonden apparaten beheren")).assertWidthIsAtLeast(46.dp).assertHeightIsAtLeast(46.dp)
        compose.onNode(hasContentDescription("Hoofdnavigatie")).assertExists()

        for (tab in listOf("Gezondheid", "Doelen", "Community", "Instellingen")) {
            button(tab).performClick()
            compose.onNode(hasText(tab) and isSelected()).assertExists()
            compose.onAllNodes(hasText(tab) and isHeading()).fetchSemanticsNodes().isNotEmpty().let {
                assertTrue("$tab has its heading", it)
            }
        }
    }

    private fun androidx.compose.ui.test.junit4.ComposeContentTestRule.onNodeWithTextExact(text: String) =
        onNode(hasText(text, substring = false))
}
