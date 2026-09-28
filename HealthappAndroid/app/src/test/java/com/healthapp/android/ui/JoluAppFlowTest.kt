package com.healthapp.android.ui

import android.content.Context
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.test.SemanticsNodeInteraction
import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.assertHeightIsAtLeast
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.assertWidthIsAtLeast
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasSetTextAction
import androidx.compose.ui.test.hasText
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
import com.healthapp.android.data.JoluAppState
import com.healthapp.android.jolu.ACCOUNT_TOKEN
import com.healthapp.android.jolu.FakeJoluServer
import com.healthapp.android.jolu.JoluApi
import com.healthapp.android.jolu.JoluConnection
import com.healthapp.android.jolu.JoluCredential
import com.healthapp.android.jolu.JoluScope
import com.healthapp.android.jolu.JoluSyncRunner
import com.healthapp.android.jolu.JoluSyncStatusPrefs
import com.healthapp.android.jolu.MemoryTokenStorage
import com.healthapp.android.jolu.TEST_TOKEN
import com.healthapp.android.jolu.TestSyncEnvironment
import com.healthapp.android.ui.app.JoluApp
import com.healthapp.android.ui.design.LocalStillMotion
import com.healthapp.android.ui.screens.JoluScreens
import com.healthapp.android.ui.screens.goals.GoalBoard
import com.healthapp.android.ui.theme.JoluTheme
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
 * The app as a person uses it, against a JoLu server on localhost: the
 * opening screen, signing in and up, the pages the server sends, moving
 * between them, signing out — and what the screen does when the server is
 * away or says the session is over. Only the server and Health Connect are
 * stand-ins; the connection, the token store's rules, the page read and
 * every screen are the app's own.
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [36], qualifiers = "w412dp-h915dp-port")
class JoluAppFlowTest {

    @get:Rule
    val compose = createComposeRule()

    private lateinit var context: Context
    private lateinit var server: FakeJoluServer

    @Before
    fun setUp() {
        context = ApplicationProvider.getApplicationContext()
        WorkManagerTestInitHelper.initializeTestWorkManager(context, Configuration.Builder().setExecutor(SynchronousExecutor()).build())
        server = FakeJoluServer()
        JoluConnection.api = JoluApi(server.url)
        JoluConnection.storage = { MemoryTokenStorage }
        TestSyncEnvironment.context = context
        TestSyncEnvironment.api = JoluApi(server.url)
        JoluSyncRunner.environment = { TestSyncEnvironment }
        MemoryTokenStorage.clear()
        JoluConnection.reset()
        JoluAppState.clear()
        GoalBoard.clear()
        JoluSyncStatusPrefs(context).clear()
    }

    @After
    fun tearDown() {
        server.holdGate.countDown()
        server.stop()
        JoluAppState.clear()
    }

    /** The app as MainActivity shows it; motion held still so the test can settle. */
    private fun show() = compose.setContent {
        JoluTheme {
            CompositionLocalProvider(LocalStillMotion provides true) {
                JoluApp(JoluScreens.shell, JoluScreens.signedOut)
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
        compose.onNodeWithTextExact("NaamKomtNog!!").assertIsDisplayed()
        button("Inloggen").performClick()

        field("Gebruikersnaam").performTextInput("sanne")
        field("Wachtwoord").performTextInput("geheim-wachtwoord")
        button("Inloggen").performClick()

        waitForPages()
        assertEquals(JoluCredential(ACCOUNT_TOKEN, JoluScope.ACCOUNT), MemoryTokenStorage.load())
        assertEquals("geheim-wachtwoord", server.requestsTo("app-login.php").single().body.getString("password"))
        assertEquals("Bearer $ACCOUNT_TOKEN", server.requestsTo("state.php").last().headers["authorization"])
    }

    @Test
    fun `a wrong password - the server's message in the panel, and still signed out`() {
        server.loginMode = FakeJoluServer.LoginMode.WRONG_PASSWORD
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
        assertEquals(JoluCredential(ACCOUNT_TOKEN, JoluScope.ACCOUNT), MemoryTokenStorage.load())
        assertEquals("nieuw", server.requestsTo("app-register.php").single().body.getString("username"))
    }

    // -------------------------------------------------------------- signed in

    @Test
    fun `the session is restored - a stored account token opens the app without a password`() {
        MemoryTokenStorage.signedIn()
        show()

        waitForPages()
        assertTrue(server.requestsTo("app-login.php").isEmpty())
        assertEquals(JoluCredential(ACCOUNT_TOKEN, JoluScope.ACCOUNT), MemoryTokenStorage.load())
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
        JoluConnection.api = JoluApi("http://127.0.0.1:9/")
        show()

        waitFor("Je gegevens konden niet worden geladen")
        assertEquals(JoluCredential(ACCOUNT_TOKEN, JoluScope.ACCOUNT), MemoryTokenStorage.load())

        JoluConnection.api = JoluApi(server.url)
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
        assertEquals(JoluCredential(TEST_TOKEN, JoluScope.SYNC), MemoryTokenStorage.load())
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
