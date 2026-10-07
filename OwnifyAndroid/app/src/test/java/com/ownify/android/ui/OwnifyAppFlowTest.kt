package com.ownify.android.ui

import android.content.Context
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.test.SemanticsMatcher
import androidx.compose.ui.test.SemanticsNodeInteraction
import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.assertHeightIsAtLeast
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.assertIsEnabled
import androidx.compose.ui.test.assertIsNotSelected
import androidx.compose.ui.test.assertIsSelected
import androidx.compose.ui.test.assertIsFocused
import androidx.compose.ui.test.assertIsOff
import androidx.compose.ui.test.assertIsOn
import androidx.compose.ui.test.assertWidthIsAtLeast
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasAnyDescendant
import androidx.compose.ui.test.hasScrollAction
import androidx.compose.ui.semantics.SemanticsNode
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.onRoot
import androidx.compose.ui.test.hasSetTextAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.performTouchInput
import androidx.compose.ui.test.hasTestTag
import androidx.compose.ui.test.isHeading
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.onNodeWithText
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
import com.ownify.android.data.AppLoad
import com.ownify.android.data.OwnifyAppState
import com.ownify.android.connection.ACCOUNT_TOKEN
import com.ownify.android.connection.FakeOwnifyServer
import com.ownify.android.connection.OwnifyApi
import com.ownify.android.connection.OwnifyConnection
import com.ownify.android.connection.OwnifyCredential
import com.ownify.android.connection.OwnifyScope
import com.ownify.android.connection.OwnifyState
import com.ownify.android.connection.OwnifySyncRunner
import com.ownify.android.connection.OwnifySyncStatusPrefs
import com.ownify.android.connection.MemoryTokenStorage
import com.ownify.android.connection.TEST_TOKEN
import com.ownify.android.connection.TestSyncEnvironment
import com.ownify.android.ui.app.OwnifyApp
import com.ownify.android.ui.design.LocalStillMotion
import com.ownify.android.ui.screens.OwnifyScreens
import com.ownify.android.ui.screens.goals.GoalBoard
import com.ownify.android.ui.theme.Ownify
import com.ownify.android.ui.theme.OwnifyMode
import com.ownify.android.ui.theme.OwnifyTheme
import com.ownify.android.ui.theme.OwnifyThemeChoice
import com.ownify.android.ui.theme.OwnifyThemeStore
import kotlinx.coroutines.runBlocking
import org.json.JSONArray
import org.json.JSONObject
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Assume.assumeTrue
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
        OwnifyThemeStore.forget(context)
        Ownify.use(OwnifyMode.DARK)
    }

    @After
    fun tearDown() {
        server.holdGate.countDown()
        server.stop()
        OwnifyAppState.clear()
        Ownify.use(OwnifyMode.DARK)
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

    /** Instellingen → Account verwijderen, both confirmation steps, the final one tapped. */
    private fun deleteTheAccount() {
        button("Instellingen").performClick()
        button("Account verwijderen").performScrollTo().performSemanticsAction(SemanticsActions.OnClick)
        waitFor("Account verwijderen?")
        button("Ja, verwijderen").performSemanticsAction(SemanticsActions.OnClick)
        waitFor("Weet je het zeker?")
        button("Definitief verwijderen").performSemanticsAction(SemanticsActions.OnClick)
    }

    @Test
    fun `deleting the account - back to the opening screen with Inloggen and Registreren, and reopening stays signed out`() {
        MemoryTokenStorage.signedIn()
        show()
        waitForPages()

        deleteTheAccount()

        waitFor("Registreren")
        compose.onAllNodes(hasText("Inloggen") and hasClickAction()).onLast().assertIsDisplayed()
        compose.onAllNodes(hasText("Registreren") and hasClickAction()).onLast().assertIsDisplayed()
        waitFor("Je account is verwijderd")
        // Nothing of the account is left: not its pages, not its credential, not the sheet.
        assertTrue(compose.onAllNodesWithText("Gezondheidsscore", substring = true).fetchSemanticsNodes().isEmpty())
        assertTrue(compose.onAllNodesWithText("Weet je het zeker?").fetchSemanticsNodes().isEmpty())
        assertNull(MemoryTokenStorage.load())
        assertNull(OwnifyAppState.data)
        assertEquals("verwijderen", form(server.requestsTo("profile/delete.php").single())["confirm"])

        // Reopening the app: nothing to restore.
        val reads = server.requestsTo("app/state.php").size
        OwnifyConnection.reset()
        OwnifyConnection.start(context)
        waitFor("Registreren")
        assertTrue(OwnifyConnection.state is OwnifyState.NotConnected)
        assertEquals(reads, server.requestsTo("app/state.php").size)
    }

    @Test
    fun `deleting the account fails - the error in the sheet, still signed in, nothing forgotten`() {
        server.deleteFails = true
        MemoryTokenStorage.signedIn()
        show()
        waitForPages()

        deleteTheAccount()

        waitFor("Je account kon niet worden verwijderd.")
        compose.onAllNodes(hasText("Definitief verwijderen") and hasClickAction()).onLast().assertIsDisplayed()
        assertEquals(OwnifyCredential(ACCOUNT_TOKEN, OwnifyScope.ACCOUNT), MemoryTokenStorage.load())
        assertTrue(compose.onAllNodesWithText("Registreren").fetchSemanticsNodes().isEmpty())
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

        compose.onNode(hasContentDescription("Ownify AI openen", substring = true)).performClick()
        waitFor("Ownify AI gebruiken?")
    }

    // ------------------------------------------------------------ the first days

    @Test
    fun `setup - a new account opens on it, every step but the focus can be skipped, and finishing opens the app for good`() {
        server.setupPending = true
        server.setupFresh = true
        MemoryTokenStorage.signedIn()
        show()

        waitFor("Wat wil je het liefst begrijpen?")
        // None of the pages before the setup is finished.
        assertTrue(compose.onAllNodesWithText("Gezondheidsscore").fetchSemanticsNodes().isEmpty())
        compose.onNodeWithText("Stap 1 van 4 · Focus").assertExists()
        // One answer first: Verder waits for it.
        button("Verder").assertIsNotEnabled()

        compose.onNode(hasText("Slaap") and hasClickAction()).performClick()
        button("Verder").performClick()
        waitFor("Gebruik wat je al meet")
        assertEquals("sleep", form(server.requestsTo("update.php").single())["focus"])
        compose.onNodeWithText("Stap 2 van 4 · Gegevens").assertExists()

        button("Doorgaan zonder koppelen").performClick()
        waitFor("Een paar gegevens over jou")
        button("Overslaan").performClick()
        waitFor("Wil je meteen een doel stellen?")
        // Back finds every answer where it was.
        button("Terug").performClick()
        waitFor("Een paar gegevens over jou")
        button("Overslaan").performClick()
        waitFor("Wil je meteen een doel stellen?")
        button("Naar Ownify").performClick()

        waitForPages()
        assertEquals(1, server.requestsTo("finish.php").size)
        // Nothing was saved that was not answered.
        assertTrue(server.requestsTo("onboarding.php").isEmpty())
        assertTrue(server.requestsTo("create.php").isEmpty())
        assertTrue(compose.onAllNodesWithText("Wat wil je het liefst begrijpen?").fetchSemanticsNodes().isEmpty())
    }

    @Test
    fun `setup - a restart opens past a focus already chosen, and the setup is the server's to end, not the phone's`() {
        server.setupPending = true
        val state = JSONObject(javaClass.classLoader!!.getResource("setup-pending.json").readText())
        assertEquals("connect", state.getString("resume"))
        MemoryTokenStorage.signedIn()
        show()

        // The fixture's account chose Energie on its way through: it opens on Gegevens.
        waitFor("Gebruik wat je al meet")
        // Signing out from the setup: the opening screen, the token forgotten.
        compose.onNode(hasText("Uitloggen") and hasClickAction()).performClick()
        waitFor("Registreren")
        assertNull(MemoryTokenStorage.load())
    }

    @Test
    fun `setup - a birth date, a height and a weight go to the endpoints that always save them`() {
        server.setupPending = true
        MemoryTokenStorage.signedIn()
        show()

        waitFor("Gebruik wat je al meet")
        button("Doorgaan zonder koppelen").performClick()
        waitFor("Een paar gegevens over jou")
        // Each fact says why it is asked.
        compose.onNodeWithText("Met je leeftijd schat Ownify je maximale hartslag, en daarmee hoe zwaar een training was.").assertExists()
        field("Lengte").performTextInput("180")
        field("Gewicht").performTextInput("82,4")
        button("Opslaan en verder").performClick()

        waitFor("Wil je meteen een doel stellen?")
        val saved = form(server.requestsTo("update.php").single())
        assertEquals("180", saved["height"])
        assertEquals("82,4", saved["weight"])
        assertTrue(server.requestsTo("onboarding.php").isEmpty())
    }

    @Test
    fun `setup - a first goal from the person's own data, added through the normal goal endpoint`() {
        server.setupPending = true
        MemoryTokenStorage.signedIn()
        show()

        waitFor("Gebruik wat je al meet")
        button("Doorgaan zonder koppelen").performClick()
        waitFor("Een paar gegevens over jou")
        button("Overslaan").performClick()

        waitFor("5 nachten van minstens 7 uur")
        compose.onNodeWithText("Een mogelijk eerste doel", ignoreCase = true).assertExists()
        compose.onNodeWithText("Je sliep de afgelopen 4 nachten gemiddeld 6:41.").assertExists()
        button("Toevoegen").performClick()

        waitFor("Doel toegevoegd: 5 nachten van minstens 7 uur. Je vindt het bij Doelen.")
        val goal = form(server.requestsTo("create.php").single())
        assertEquals("accumulate", goal["type"])
        assertEquals("sleep_duration", goal["source_key"])
        assertEquals("7", goal["daily_target"])
        assertEquals("5", goal["target_value"])
        // A suggestion is a possibility, never a claim.
        assertTrue(compose.onAllNodesWithText("realistisch", substring = true, ignoreCase = true).fetchSemanticsNodes().isEmpty())
        assertTrue(compose.onAllNodesWithText("Een mogelijk eerste doel", ignoreCase = true).fetchSemanticsNodes().isEmpty())
    }

    @Test
    fun `setup - a suggestion can be left, and the normal wizard is there instead`() {
        server.setupPending = true
        MemoryTokenStorage.signedIn()
        show()

        waitFor("Gebruik wat je al meet")
        button("Doorgaan zonder koppelen").performClick()
        waitFor("Een paar gegevens over jou")
        button("Overslaan").performClick()
        waitFor("5 nachten van minstens 7 uur")
        button("Niet nu").performClick()
        compose.waitUntil(5_000) { compose.onAllNodesWithText("5 nachten van minstens 7 uur").fetchSemanticsNodes().isEmpty() }

        button("Zelf een doel instellen").performClick()
        waitFor("Waar gaat je doel over?")
        assertTrue(server.requestsTo("create.php").isEmpty())
    }

    @Test
    fun `first days - Overzicht opens on the baseline being built, in the server's words`() {
        server.calibration = JSONObject(javaClass.classLoader!!.getResource("calibration-building.json").readText())
        MemoryTokenStorage.signedIn()
        show()
        waitForPages()

        compose.onNodeWithText("Je basislijn wordt opgebouwd").assertExists()
        compose.onNodeWithText("Dag 2 van 3", ignoreCase = true).assertExists()
        compose.onNodeWithText("1 van 3 nachten").assertExists()
        compose.onNodeWithText("Je laatste nacht", substring = true).assertExists()
    }

    @Test
    fun `first days - the first score is the engine's own, with its components, and opens the Scorekompas`() {
        withCompass()
        server.calibration = JSONObject(javaClass.classLoader!!.getResource("calibration-first.json").readText())
        MemoryTokenStorage.signedIn()
        show()
        waitForPages()

        compose.onNodeWithText("Je eerste slaapscore").assertExists()
        compose.onNodeWithText("Slaapduur").assertExists()
        compose.onNodeWithText("Regelmaat").assertExists()
        compose.onNodeWithText("Je gezondheidsscore rust voorlopig alleen op slaap.", substring = true).assertExists()
        compose.onNode(hasContentDescription("Bekijk de opbouw in het Scorekompas") and hasClickAction()).performClick()
        compose.waitUntil(5_000) { compose.onAllNodes(hasText("Scorekompas") and isHeading()).fetchSemanticsNodes().isNotEmpty() }
    }

    @Test
    fun `first days - the starting point shows only what has enough data, and an account without the first days has no card`() {
        server.calibration = JSONObject(javaClass.classLoader!!.getResource("calibration-baseline.json").readText())
        MemoryTokenStorage.signedIn()
        show()
        waitForPages()
        compose.onNodeWithText("Je startpunt").assertExists()
        compose.onNodeWithText("Voeding en sport komen erbij zodra er 3 dagen van zijn.").assertExists()
    }

    @Test
    fun `Instellingen - the focus is changed later in the editor every field has, saved where the setup saves it`() {
        MemoryTokenStorage.signedIn()
        show()
        waitForPages()
        button("Instellingen").performClick()
        compose.onNode(hasContentDescription("Account openen") and hasClickAction())
            .performSemanticsAction(SemanticsActions.OnClick)
        waitFor("Focus")

        compose.onAllNodes(hasText("Focus") and hasClickAction()).onLast().performSemanticsAction(SemanticsActions.OnClick)
        waitFor("Fitheid")
        compose.onAllNodes(hasText("Slaap") and hasClickAction()).onLast().performClick()
        button("Opslaan").performClick()

        compose.waitUntil(5_000) { server.requestsTo("update.php").isNotEmpty() }
        assertEquals(mapOf("focus" to "sleep"), form(server.requestsTo("update.php").single()))
    }

    @Test
    fun `first days - an existing account sees neither the setup nor the card`() {
        MemoryTokenStorage.signedIn()
        show()
        waitForPages()
        assertTrue(compose.onAllNodesWithText("Je basislijn", substring = true).fetchSemanticsNodes().isEmpty())
        assertTrue(compose.onAllNodesWithText("Wat wil je het liefst begrijpen?").fetchSemanticsNodes().isEmpty())
    }

    // ------------------------------------------------------------ Scorekompas

    /** The demo pages with the Scorekompas the server adds to them (another day's: nothing here compares the two). */
    private fun withCompass() {
        val state = JSONObject(server.stateBody)
        state.getJSONObject("data").put("compass", JSONObject(javaClass.classLoader!!.getResource("compass-demo.json").readText()))
        server.stateBody = state.toString()
    }

    /** A node's own action by its label, as TalkBack's actions menu runs it. */
    private fun customAction(matcher: SemanticsMatcher, label: String) {
        val actions = compose.onNode(matcher).fetchSemanticsNode().config[SemanticsActions.CustomActions]
        compose.runOnIdle { actions.first { it.label == label }.action() }
        compose.waitForIdle()
    }

    private val scoreButton get() = compose.onNode(hasContentDescription("Gezondheidsscore:", substring = true) and hasClickAction())

    private fun backToOverview() {
        compose.onNode(hasContentDescription("Terug naar Overzicht") and hasClickAction()).performClick()
        compose.waitUntil(5_000) { compose.onAllNodes(hasText("Scorekompas") and isHeading()).fetchSemanticsNodes().isEmpty() }
        compose.onNode(hasText("Vandaag") and isHeading()).assertExists()
    }

    @Test
    fun `Scorekompas - the score card opens it, its four parts are the server's, and back returns to Overzicht`() {
        withCompass()
        MemoryTokenStorage.signedIn()
        show()
        waitForPages()

        // Overzicht says where the score is heading; the label is the button.
        compose.onNode(hasText("Dalend")).assertIsDisplayed()
        scoreButton.performClick()

        compose.onNode(hasText("Scorekompas") and isHeading()).assertExists()
        // The page under it is out of reach, as the website makes it inert.
        compose.onAllNodes(hasText("Vandaag") and isHeading()).assertCountEquals(0)

        for (title in listOf("Waar je score uit bestaat", "Wat er verandert", "Vergeleken met jezelf", "Grootste kans")) {
            compose.onNode(hasText(title) and isHeading()).performScrollTo().assertIsDisplayed()
        }
        // What is changing: the score's own week first, today read closely under it.
        compose.onNode(hasText("De afgelopen 7 dagen lag je score tussen 68 en 73.")).performScrollTo().assertIsDisplayed()
        compose.onNode(hasText("Dagcijfer 7,3")).performScrollTo().assertIsDisplayed()
        compose.onNode(hasText("Tik of schuif over de lijn om een dag te bekijken.")).performScrollTo().assertIsDisplayed()
        // Another period: its own sentences, the same score's history.
        compose.onNode(hasText("30 dagen") and hasClickAction()).performScrollTo().performClick()
        compose.onNode(hasText("Je score daalde van gemiddeld 71 in de week van 7 september naar 69 in de afgelopen week.")).performScrollTo().assertIsDisplayed()
        compose.onNode(hasText("1 jaar") and hasClickAction()).performScrollTo().performClick()
        compose.onNode(hasText("Je geschiedenis begint op 23 augustus.")).performScrollTo().assertIsDisplayed()
        // A screen reader steps through the days as the arrow keys do: the panel follows.
        repeat(2) { customAction(hasContentDescription("Je Gezondheidsscore per dag, het afgelopen jaar", substring = true), "Vorige dag") }
        compose.onNode(hasText("5 oktober")).performScrollTo().assertIsDisplayed()
        compose.onNode(hasText("Slaapduur 68 · Regelmaat 57 · Kwaliteit 66")).performScrollTo().assertIsDisplayed()
        compose.onNode(hasText("−1 ten opzichte van de 30 dagen daarvoor")).performScrollTo().assertIsDisplayed()
        compose.onNode(hasText("Hogere dagcijfers zouden samengaan met een hogere voedingsscore.")).performScrollTo().assertIsDisplayed()
        // The page's end, scrolled to as TalkBack scrolls (performScrollTo() keeps
        // trying on the last line of a column, in this version of Compose's test kit).
        compose.onNode(hasScrollAction() and hasAnyDescendant(hasText("Het Scorekompas beschrijft", substring = true)))
            .performSemanticsAction(SemanticsActions.ScrollBy) { it(0f, 100_000f) }
        compose.onNode(hasText("Het Scorekompas beschrijft", substring = true)).assertIsDisplayed()

        backToOverview()

        // As TalkBack opens it: the button's own action.
        scoreButton.performSemanticsAction(SemanticsActions.OnClick)
        compose.waitUntil(5_000) { compose.onAllNodes(hasText("Scorekompas") and isHeading()).fetchSemanticsNodes().isNotEmpty() }
        backToOverview()
    }

    @Test
    fun `Scorekompas - a server without it leaves the score card a card`() {
        MemoryTokenStorage.signedIn()
        show()
        waitForPages()

        compose.onAllNodes(hasContentDescription("Gezondheidsscore:", substring = true) and hasClickAction()).assertCountEquals(0)
        compose.onAllNodes(hasText("Dalend")).assertCountEquals(0)
    }

    // --------------------------------------------------------- Ownify AI

    private fun openAssistant() =
        compose.onNode(hasContentDescription("Ownify AI openen", substring = true)).performClick()

    private fun send(question: String) {
        field("Je vraag aan Ownify AI").performTextInput(question)
        compose.onNode(hasContentDescription("Versturen") and hasClickAction()).performClick()
    }

    /** A form post's fields, as PHP reads them into $_POST. */
    private fun form(request: FakeOwnifyServer.Request): Map<String, String> =
        request.body.optString("form").split('&').filter { it.contains('=') }.associate {
            java.net.URLDecoder.decode(it.substringBefore('='), "UTF-8") to java.net.URLDecoder.decode(it.substringAfter('='), "UTF-8")
        }

    @Test
    fun `Ownify AI - nothing goes to Gemini before the yes, then a question is answered and the conversation stays`() {
        MemoryTokenStorage.signedIn()
        show()
        waitForPages()

        openAssistant()
        waitFor("Ownify AI gebruiken?")
        // What happens with the data, said before anything happens — the free tier's terms included.
        waitFor("Google kan wat daar binnenkomt gebruiken om zijn producten te verbeteren")
        assertTrue(server.requestsTo("chat.php").isEmpty())

        button("Toestaan en beginnen").performClick()
        waitFor("Je persoonlijke gezondheidsassistent")
        assertEquals(mapOf("decision" to "accept"), form(server.requestsTo("consent.php").single()))
        assertEquals("accepted", server.aiConsent)

        server.aiAnswers += JSONObject().put("text", "Je sliep gemiddeld 7 u 35 min.").put(
            "blocks",
            org.json.JSONArray("""[{"type":"p","spans":[{"t":"Je sliep gemiddeld ","b":false},{"t":"7 u 35 min","b":true},{"t":".","b":false}]},{"type":"ul","items":[[{"t":"je bedtijd lag steeds rond 23:30","b":false}]]}]""")
        )
        button("Hoe was mijn gezondheid deze week?").performClick()
        waitFor("7 u 35 min")
        waitFor("je bedtijd lag steeds rond 23:30")
        waitFor("Nog 9 van 10 berichten vandaag")
        val asked = server.requestsTo("chat.php").single()
        assertEquals("Bearer $ACCOUNT_TOKEN", asked.headers["authorization"])
        assertEquals(mapOf("message" to "Hoe was mijn gezondheid deze week?"), form(asked))

        // Pulled down and up again: the conversation is still there, nothing asked twice.
        compose.onNode(hasContentDescription("Ownify AI sluiten", substring = true) and hasClickAction()).performClick()
        compose.waitUntil(5_000) { compose.onAllNodesWithText("7 u 35 min", substring = true).fetchSemanticsNodes().isEmpty() }
        openAssistant()
        waitFor("7 u 35 min")
        assertEquals(1, server.requestsTo("chat.php").size)

        // The next question carries on in the same conversation.
        send("En vorige week?")
        waitFor("Standaardantwoord.")
        assertEquals("7", form(server.requestsTo("chat.php").last())["conversation_id"])
    }

    @Test
    fun `Ownify AI - a goal it prepares is added only on a yes, and Doelen is read again`() {
        server.saveConsent("accepted")
        MemoryTokenStorage.signedIn()
        show()
        waitForPages()

        openAssistant()
        waitFor("Je persoonlijke gezondheidsassistent")
        server.aiAnswers += JSONObject()
            .put("text", "Ik kan hiervoor een mijlpaal toevoegen. Zal ik het toevoegen?")
            .put("action", JSONObject().put("title", "Nieuw doel: 5 km onder 25 minuten").put("summary", "Mijlpaal · 25 min · lager is beter")
                .put("state", "pending").put("confirm", "Doel toevoegen").put("decline", "Niet nu").put("status", JSONObject.NULL))
        send("Maak een doel om 5 km onder de 25 minuten te lopen")
        waitFor("Nieuw doel: 5 km onder 25 minuten")
        waitFor("Doel toevoegen")
        // Proposed, not done.
        assertTrue(server.requestsTo("action.php").isEmpty())
        val reads = server.requestsTo("app/state.php").size

        button("Doel toevoegen").performClick()
        waitFor("Doorgevoerd")
        waitFor("toegevoegd. Je vindt het bij Doelen.")
        assertEquals("confirm", form(server.requestsTo("action.php").single())["decision"])
        // Doelen shows it: the pages are read again.
        compose.waitUntil(10_000) { server.requestsTo("app/state.php").size > reads }
        assertTrue(compose.onAllNodes(hasText("Doel toevoegen") and hasClickAction()).fetchSemanticsNodes().isEmpty())
    }

    @Test
    fun `Ownify AI - the free quota used up, or today's limit reached, in the server's words, the question kept`() {
        server.saveConsent("accepted")
        MemoryTokenStorage.signedIn()
        show()
        waitForPages()
        openAssistant()
        waitFor("Je persoonlijke gezondheidsassistent")

        server.aiMode = FakeOwnifyServer.AiMode.QUOTA
        send("Hoe sliep ik?")
        waitFor("de gratis gebruikslimiet is bereikt")
        // Not answered, not lost: the question is back in the field, and it can be sent again.
        compose.onNode(hasText("Hoe sliep ik?") and hasSetTextAction()).assertExists()
        server.aiMode = FakeOwnifyServer.AiMode.OK
        button("Opnieuw proberen").performClick()
        waitFor("Standaardantwoord.")
        assertTrue(compose.onAllNodesWithText("gratis gebruikslimiet", substring = true).fetchSemanticsNodes().isEmpty())

        server.aiMode = FakeOwnifyServer.AiMode.LIMIT
        send("Nog een vraag")
        waitFor("Morgen kun je weer verder")
    }

    @Test
    fun `Ownify AI - said no, nothing is sent, and the question can be looked at again`() {
        MemoryTokenStorage.signedIn()
        show()
        waitForPages()
        openAssistant()
        waitFor("Ownify AI gebruiken?")

        button("Niet nu").performClick()
        waitFor("Ownify AI staat uit")
        assertEquals("declined", server.aiConsent)
        assertTrue(server.requestsTo("chat.php").isEmpty())

        button("Toestemming bekijken").performClick()
        waitFor("Ownify AI gebruiken?")
    }

    @Test
    fun `Privacy - Ownify AI's switch is the same answer as in the sheet, and the conversations can be wiped`() {
        server.saveConsent("accepted")
        MemoryTokenStorage.signedIn()
        show()
        waitForPages()
        button("Instellingen").performClick()
        compose.onNode(hasContentDescription("Privacy —", substring = true) and hasClickAction())
            .performSemanticsAction(SemanticsActions.OnClick)
        waitFor("Gegevens verwerken met Google Gemini")

        // Wiping asks first.
        compose.onNode(hasText("AI-gesprekken wissen", substring = true)).assertExists()
        compose.onAllNodes(hasText("Alles wissen") and hasClickAction()).onLast().performSemanticsAction(SemanticsActions.OnClick)
        waitFor("Al je AI-gesprekken wissen?")
        assertTrue(server.requestsTo("delete.php").isEmpty())
        compose.onAllNodes(hasText("Alles wissen") and hasClickAction()).onLast().performSemanticsAction(SemanticsActions.OnClick)
        waitFor("Je AI-gesprekken zijn gewist.")
        assertEquals(mapOf("all" to "1"), form(server.requestsTo("delete.php").single()))

        val toggle = compose.onNode(hasText("Gegevens verwerken met Google Gemini", substring = true) and hasClickAction())
        toggle.assertIsOn()
        toggle.performSemanticsAction(SemanticsActions.OnClick)
        waitFor("De assistent werkt niet, en er gaat niets naar Gemini.")
        toggle.assertIsOff()
        // The switch moves at once; the save is on its way.
        compose.waitUntil(10_000) { server.requestsTo("privacy.php").isNotEmpty() && server.aiConsent == "declined" }
        assertEquals("ai_consent=0", server.requestsTo("privacy.php").single().body.optString("form"))
        assertEquals("declined", server.aiConsent)
    }

    @Test
    fun `Gezondheid's intro is the server's - how many more days unlock a score, then the three pillars once there is one`() {
        val demo = server.stateBody
        server.stateBody = org.json.JSONObject(demo).apply {
            getJSONObject("data").getJSONObject("health").put("lede", "Je eerste score volgt na 3 dagen met gegevens: nog 2 dagen.")
        }.toString()
        MemoryTokenStorage.signedIn()
        show()
        waitForPages()

        button("Gezondheid").performClick()
        waitFor("Je eerste score volgt na 3 dagen met gegevens: nog 2 dagen.")

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
        // The switch moves first; the save may still be on its way.
        compose.waitUntil(15_000) { server.requestsTo("privacy.php").isNotEmpty() }
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

    // ----------------------------------------------------------------- Doelen

    /** The demo pages with these goals under Secundaire doelen, in this order — the server's (goals_prepare()). Their ids: order-0, order-1… */
    private fun boardWith(vararg sent: Pair<String, Int?>): JSONObject {
        val state = JSONObject(server.stateBody)
        val goals = state.getJSONObject("data").getJSONObject("goals")
        val template = goals.getJSONArray("secondary").getJSONObject(0)
        val secondary = JSONArray()
        sent.forEachIndexed { i, (name, percent) ->
            secondary.put(JSONObject(template.toString()).put("id", "order-$i").put("name", name).put("status", "active")
                .put("is_paused", false).put("percent", percent ?: JSONObject.NULL))
        }
        val active = JSONArray().put(goals.getJSONObject("primary"))
        for (i in 0 until secondary.length()) active.put(secondary.getJSONObject(i))
        val all = JSONArray()
        for (i in 0 until active.length()) all.put(active.getJSONObject(i))
        for (i in 0 until goals.getJSONArray("completed").length()) all.put(goals.getJSONArray("completed").getJSONObject(i))
        goals.put("secondary", secondary).put("active", active).put("all", all)
        server.stateBody = state.toString()
        return goals
    }

    // The eyebrow is shown in capitals, as on the website (text-transform).
    private fun nodes(text: String) = compose.onAllNodesWithText(text, ignoreCase = true).fetchSemanticsNodes()

    // Only the page on screen counts: the rail keeps its neighbours composed
    // beside it. Positions, not bounds: the last cards are below the fold.
    private fun onScreen(node: SemanticsNode): Boolean {
        val width = compose.onRoot().fetchSemanticsNode().size.width
        return node.positionInRoot.x >= 0f && node.positionInRoot.x < width
    }

    /** Where a goal's card is on the board: each card is one button, named for its goal. Null when it is not there. */
    private fun cardTop(name: String): Float? =
        compose.onAllNodes(hasContentDescription(name, substring = true) and hasClickAction()).fetchSemanticsNodes()
            .firstOrNull(::onScreen)?.positionInRoot?.y

    /** The goal in the primary slot: the card above Secundaire doelen (the only one, when that is gone). */
    private fun primaryShown(names: List<String>): String? {
        val line = nodes("Secundaire doelen").firstOrNull(::onScreen)?.positionInRoot?.y ?: Float.MAX_VALUE
        return names.firstOrNull { name -> cardTop(name)?.let { it < line } == true }
    }

    private fun openDoelen() {
        button("Doelen").performClick()
        compose.waitUntil(15_000) { nodes("Secundaire doelen").isNotEmpty() }
    }

    @Test
    fun `Doelen - Secundaire doelen in the order the server sends, under the primary goal`() {
        // The server's order (goals_prepare, lib/goals.php): furthest along
        // first, a real 0% before a goal with no percentage. The app sorts
        // nothing itself, so the website and the app show the same order.
        val sent = listOf("Hardlopen 82" to 82, "Zwemmen 41" to 41, "Fietsen 0" to 0, "Yoga zonder data" to null)
        val goals = boardWith(*sent.toTypedArray())

        MemoryTokenStorage.signedIn()
        show()
        waitForPages()
        openDoelen()

        val primary = goals.getJSONObject("primary").getString("name")
        val tops = listOf(cardTop(primary), nodes("Secundaire doelen").first(::onScreen).positionInRoot.y) + sent.map { cardTop(it.first) }
        assertEquals("from the top: $primary, Secundaire doelen, ${sent.map { it.first }}", tops.map { it!! }.sorted(), tops)
        assertTrue("the old name is gone", nodes("Overige doelen").isEmpty())
    }

    @Test
    fun `Doelen - deleting the primary goal - the first of Secundaire doelen takes its place, and keeps it`() {
        // 82%, 64%, 41% under the primary goal: the server's order. The server
        // used to give the place to the oldest goal while the app showed the
        // first one moving up — and the board changed again once it was read.
        val goals = boardWith("Fietsen 82" to 82, "Zwemmen 64" to 64, "Lopen 41" to 41)
        val main = goals.getJSONObject("primary").getString("name")
        val names = listOf(main, "Fietsen 82", "Zwemmen 64", "Lopen 41")

        MemoryTokenStorage.signedIn()
        show()
        waitForPages()
        openDoelen()
        assertEquals(main, primaryShown(names))

        // The primary goal's page: Doel verwijderen, then Verwijderen.
        val cards = compose.onAllNodes(hasContentDescription(main, substring = true) and hasClickAction())
        cards[cards.fetchSemanticsNodes().indexOfFirst(::onScreen)].performSemanticsAction(SemanticsActions.OnClick)
        waitFor("Doel verwijderen")
        button("Doel verwijderen").performScrollTo().performSemanticsAction(SemanticsActions.OnClick)
        waitFor("Weet je het zeker?")
        val reads = server.requestsTo("state.php").size
        button("Verwijderen").performScrollTo().performSemanticsAction(SemanticsActions.OnClick)

        // Which goal is in the primary slot, from the tap until the board has
        // been read again — and a while after: only ever the deleted goal
        // (fading) and then 82%, never another goal in between.
        val seen = mutableListOf<String>()
        fun look() { primaryShown(names)?.let { if (seen.lastOrNull() != it) seen += it } }
        compose.waitUntil(15_000) {
            look()
            server.requestsTo("goals/delete.php").isNotEmpty() && server.requestsTo("state.php").size > reads &&
                (OwnifyAppState.load as? AppLoad.Ready)?.refreshing == false && cardTop(main) == null
        }
        repeat(20) { compose.waitForIdle(); look() }
        assertEquals("the primary slot, in turn", listOf(main, "Fietsen 82"), seen)

        // The goal moved up is the goal the server was told — and stored.
        val form = server.requestsTo("goals/delete.php").single().body.getString("form")
        assertTrue("the delete names the goal moved up: $form", "successor=order-0" in form.split('&'))
        assertTrue("the others stay in order: 64%, 41%", cardTop("Zwemmen 64")!! < cardTop("Lopen 41")!!)

        // Read again, as when the app is opened again: the same goal is primary.
        runBlocking { OwnifyAppState.reload(context) }
        compose.waitForIdle()
        assertEquals("Fietsen 82", primaryShown(names))
    }

    /**
     * The same against a real Ownify server and its database — opt-in, and
     * the goal deleted is real:
     *
     *     -Downify.live=http://127.0.0.1:8260/ -Downify.live.token=<an ACCOUNT token>
     *
     * for an account whose board has a primary goal and secondary goals.
     */
    @Test
    fun `Doelen - deleting the primary goal - against a real server, opt-in`() {
        val live = System.getProperty("ownify.live").orEmpty()
        val token = System.getProperty("ownify.live.token").orEmpty()
        assumeTrue("no real server asked for", live.isNotEmpty() && token.isNotEmpty())
        OwnifyConnection.api = OwnifyApi(live)
        MemoryTokenStorage.save(OwnifyCredential(token, OwnifyScope.ACCOUNT))

        show()
        // Reading the screen lets the app's main thread run on (Robolectric); the pages, then the board.
        compose.waitUntil(30_000) { compose.onRoot().fetchSemanticsNode(); OwnifyAppState.data?.goals?.primary != null }
        val goals = OwnifyAppState.data!!.goals
        val main = goals.primary!!.name
        val expected = (goals.secondary.firstOrNull { !it.isPaused } ?: goals.secondary.first()).name
        val names = listOf(main) + goals.secondary.map { it.name }
        openDoelen()
        assertEquals(main, primaryShown(names))

        val cards = compose.onAllNodes(hasContentDescription(main, substring = true) and hasClickAction())
        cards[cards.fetchSemanticsNodes().indexOfFirst(::onScreen)].performSemanticsAction(SemanticsActions.OnClick)
        waitFor("Doel verwijderen")
        button("Doel verwijderen").performScrollTo().performSemanticsAction(SemanticsActions.OnClick)
        waitFor("Weet je het zeker?")
        button("Verwijderen").performScrollTo().performSemanticsAction(SemanticsActions.OnClick)

        val seen = mutableListOf<String>()
        fun look() { primaryShown(names)?.let { if (seen.lastOrNull() != it) seen += it } }
        compose.waitUntil(30_000) {
            look()
            OwnifyAppState.data?.goals?.all?.none { it.name == main } == true &&
                (OwnifyAppState.load as? AppLoad.Ready)?.refreshing == false && cardTop(main) == null
        }
        repeat(20) { compose.waitForIdle(); look() }
        assertEquals("the primary slot, in turn", listOf(main, expected), seen)

        runBlocking { OwnifyAppState.reload(context) }
        compose.waitForIdle()
        assertEquals("stored: read again, the same goal is primary", expected, OwnifyAppState.data?.goals?.primary?.name)
        assertEquals(expected, primaryShown(names))
        println("live: deleted \"$main\"; primary before and after reading again: \"$expected\"; seen in the slot: $seen")
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

    // --------------------------------------------------------------- the theme

    /** Instellingen → Thema & uiterlijk, the row tapped as TalkBack taps it. */
    private fun openTheme(expect: String) {
        button("Instellingen").performClick()
        compose.onNode(hasContentDescription("Thema & uiterlijk — $expect.", substring = true) and hasClickAction())
            .performScrollTo()
            .performSemanticsAction(SemanticsActions.OnClick)
        compose.waitUntil(5_000) { compose.onAllNodes(hasText("Hetzelfde glas, in het licht", substring = true)).fetchSemanticsNodes().isNotEmpty() }
    }

    /** One of the theme's three options, by its name and its line. */
    private fun option(label: String) = compose.onNode(
        hasText(label) and hasClickAction() and hasText(
            when (label) {
                "Licht" -> "Hetzelfde glas, in het licht"
                "Donker" -> "Het oorspronkelijke ontwerp"
                else -> "Volgt je apparaat"
            }
        )
    )

    @Test
    fun `Thema - nobody chose is Systeem, Licht turns the app light and is kept through signing out, Donker turns it back, Systeem follows the phone again`() {
        MemoryTokenStorage.signedIn()
        show()
        waitForPages()

        // Nobody chose: Systeem — the phone's way — and Donker and Licht can be chosen.
        openTheme("Systeem")
        option("Systeem").assertIsSelected()
        option("Donker").assertIsNotSelected().assertIsEnabled()
        option("Licht").assertIsNotSelected().assertIsEnabled()
        // The one choice that is kept: no "not kept" line under it.
        assertTrue(compose.onAllNodesWithText("Voorkeuren worden nog niet bewaard.").fetchSemanticsNodes().isEmpty())

        option("Licht").performClick()
        compose.waitForIdle()
        assertEquals(OwnifyMode.LIGHT, Ownify.mode)
        assertEquals("kept for the next start", OwnifyThemeChoice.LIGHT, OwnifyThemeStore.preference(context))
        option("Licht").assertIsSelected()
        option("Systeem").assertIsNotSelected()
        option("Donker").assertIsNotSelected()

        // Instellingen names it, as the website's row does.
        compose.onNode(hasContentDescription("Terug naar Instellingen") and hasClickAction()).performClick()
        compose.waitUntil(5_000) { compose.onAllNodes(hasContentDescription("Thema & uiterlijk — Licht.", substring = true)).fetchSemanticsNodes().isNotEmpty() }

        // A choice of this phone, not of the account: signing out keeps it.
        button("Uitloggen").performScrollTo().performSemanticsAction(SemanticsActions.OnClick)
        waitFor("Registreren")
        assertEquals(OwnifyMode.LIGHT, Ownify.mode)
        assertEquals(OwnifyThemeChoice.LIGHT, OwnifyThemeStore.preference(context))

        // Signed in again, still light — and Donker brings Dark back, kept too.
        server.revoked.clear()
        MemoryTokenStorage.signedIn()
        OwnifyConnection.reset()
        OwnifyConnection.start(context)
        waitForPages()
        openTheme("Licht")
        option("Donker").performClick()
        compose.waitForIdle()
        assertEquals(OwnifyMode.DARK, Ownify.mode)
        assertEquals(OwnifyThemeChoice.DARK, OwnifyThemeStore.preference(context))
        option("Donker").assertIsSelected()

        // Back to Systeem: the phone's appearance again — this phone is light.
        option("Systeem").performClick()
        compose.waitForIdle()
        assertEquals(OwnifyMode.LIGHT, Ownify.mode)
        assertEquals(OwnifyThemeChoice.SYSTEM, OwnifyThemeStore.preference(context))
        option("Systeem").assertIsSelected()
    }

    private fun androidx.compose.ui.test.junit4.ComposeContentTestRule.onNodeWithTextExact(text: String) =
        onNode(hasText(text, substring = false))
}
