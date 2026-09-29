package com.ownify.android.ui

import android.app.Activity
import android.content.Context
import android.os.Looper
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.test.SemanticsMatcher
import androidx.compose.ui.test.assert
import androidx.compose.ui.test.assertHeightIsEqualTo
import androidx.compose.ui.test.assertWidthIsEqualTo
import androidx.compose.ui.unit.dp
import androidx.compose.ui.test.SemanticsNodeInteraction
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.assertIsEnabled
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasSetTextAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onLast
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performSemanticsAction
import androidx.compose.ui.test.performTextInput
import androidx.test.core.app.ApplicationProvider
import androidx.work.Configuration
import androidx.work.testing.SynchronousExecutor
import androidx.work.testing.WorkManagerTestInitHelper
import com.ownify.android.data.OwnifyAppState
import com.ownify.android.connection.ACCOUNT_TOKEN
import com.ownify.android.connection.CredentialManagerGoogle
import com.ownify.android.connection.FakeGoogle
import com.ownify.android.connection.FakeOwnifyServer
import com.ownify.android.connection.GOOGLE_WEB_CLIENT
import com.ownify.android.connection.GoogleIdResult
import com.ownify.android.connection.OwnifyApi
import com.ownify.android.connection.OwnifyAuthState
import com.ownify.android.connection.OwnifyConnection
import com.ownify.android.connection.OwnifyCredential
import com.ownify.android.connection.OwnifyScope
import com.ownify.android.connection.OwnifySyncRunner
import com.ownify.android.connection.OwnifySyncStatusPrefs
import com.ownify.android.connection.MemoryTokenStorage
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
import org.robolectric.Shadows.shadowOf
import org.robolectric.annotation.Config

/**
 * "Doorgaan met Google" as a person uses it, from the opening screen: Google's
 * chooser (the stand-in, FakeGoogle), the Ownify server on localhost checking
 * the ID token, and the app opening on the account — or the username step,
 * or the server's reason in the panel. Everything but Google, the server and
 * Health Connect is the app's own.
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [36], qualifiers = "w412dp-h915dp-port")
class GoogleSignInFlowTest {

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
        OwnifyConnection.google = FakeGoogle
        TestSyncEnvironment.context = context
        TestSyncEnvironment.api = OwnifyApi(server.url)
        OwnifySyncRunner.environment = { TestSyncEnvironment }
        FakeGoogle.reset()
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
        OwnifyConnection.google = CredentialManagerGoogle
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

    private fun button(text: String): SemanticsNodeInteraction =
        compose.onAllNodes(hasText(text) and hasClickAction()).onLast()

    private fun field(label: String): SemanticsNodeInteraction =
        compose.onNode(hasContentDescription(label) and hasSetTextAction())

    private fun waitForPages() = waitFor("Gezondheidsscore")

    /**
     * Waits for something that is not on screen — the connection's state, a
     * request — letting the main thread run meanwhile, as the screen's own
     * waits do: the app's coroutines continue there.
     */
    private fun settle(done: () -> Boolean) = compose.waitUntil(15_000) {
        shadowOf(Looper.getMainLooper()).idle()
        done()
    }

    /** The opening screen, then its "Inloggen": the sign-in panel with Google under the form. */
    private fun openSignIn() {
        show()
        waitFor("Registreren")
        button("Inloggen").performClick()
        waitForGoogle()
    }

    /** The website's `.social` mark: no label, named "Doorgaan met Google" as its aria-label is. */
    private fun googleButton(): SemanticsNodeInteraction =
        compose.onNode(hasContentDescription("Doorgaan met Google") and hasClickAction())

    private fun waitForGoogle() =
        compose.waitUntil(15_000) { compose.onAllNodes(hasContentDescription("Doorgaan met Google")).fetchSemanticsNodes().isNotEmpty() }

    private fun google() = googleButton().performScrollTo().performClick()

    @Test
    fun `Doorgaan met Google - Google's chooser, and the app opens on the account's pages`() {
        openSignIn()
        settle { OwnifyConnection.googleAvailable == true }
        googleButton().assertIsEnabled()

        google()

        waitForPages()
        assertEquals(OwnifyCredential(ACCOUNT_TOKEN, OwnifyScope.ACCOUNT), MemoryTokenStorage.load())
        assertEquals("the chooser is shown from the app's activity", true, FakeGoogle.shownFrom.single() is Activity)
        assertEquals(GOOGLE_WEB_CLIENT, FakeGoogle.asked.single().first)
        assertEquals("Bearer $ACCOUNT_TOKEN", server.requestsTo("state.php").last().headers["authorization"])
    }

    @Test
    fun `the button is the website's Google mark - a 46 dp round button, the G and no label`() {
        openSignIn()

        googleButton().assertWidthIsEqualTo(46.dp).assertHeightIsEqualTo(46.dp)
        googleButton().assert(SemanticsMatcher.expectValue(SemanticsProperties.Role, Role.Button))
        assertTrue("no text label, as on the website", compose.onAllNodesWithText("Doorgaan met Google", substring = true).fetchSemanticsNodes().isEmpty())
        // Centred under the form, as `.account__socials` centres it.
        val panel = compose.onNode(hasSetTextAction() and hasContentDescription("Gebruikersnaam")).fetchSemanticsNode().boundsInRoot
        val mark = googleButton().fetchSemanticsNode().boundsInRoot
        assertEquals(panel.center.x, mark.center.x, 1f)
        assertTrue("under the form", mark.top > panel.bottom)
    }

    @Test
    fun `from Registreren too - Google is under that form as well`() {
        show()
        waitFor("Registreren")
        button("Registreren").performClick()
        waitForGoogle()

        google()

        waitForPages()
        assertEquals(OwnifyCredential(ACCOUNT_TOKEN, OwnifyScope.ACCOUNT), MemoryTokenStorage.load())
    }

    @Test
    fun `somebody new - the username step with the website's words, and then the app opens`() {
        server.googleMode = FakeOwnifyServer.GoogleMode.NEW
        openSignIn()

        google()

        waitFor("Kies je gebruikersnaam")
        compose.onNodeWithText("Google heeft nieuw@gmail.com bevestigd. Kies nog een gebruikersnaam; daarna is je account klaar.").assertIsDisplayed()
        waitFor("Deze stap verloopt over 10 minuten.")
        assertTrue("the sign-in form is gone meanwhile", compose.onAllNodes(hasContentDescription("Doorgaan met Google")).fetchSemanticsNodes().isEmpty())
        assertNull(MemoryTokenStorage.load())

        field("Gebruikersnaam").performTextInput("nieuw")
        button("Account aanmaken").performScrollTo().performClick()

        waitForPages()
        assertEquals("nieuw", server.google("username").single().body.getString("username"))
        assertEquals(OwnifyCredential(ACCOUNT_TOKEN, OwnifyScope.ACCOUNT), MemoryTokenStorage.load())
    }

    @Test
    fun `a taken username stays on the step with the message, and Annuleren goes back without making anything`() {
        server.googleMode = FakeOwnifyServer.GoogleMode.NEW
        openSignIn()
        google()
        waitFor("Kies je gebruikersnaam")

        field("Gebruikersnaam").performTextInput("bezet")
        button("Account aanmaken").performScrollTo().performClick()
        waitFor("Deze gebruikersnaam is al bezet.")
        compose.onNodeWithText("Kies je gebruikersnaam").assertIsDisplayed()

        button("Annuleren").performScrollTo().performClick()

        waitForGoogle()
        settle { server.google("cancel").size == 1 }
        assertNull(MemoryTokenStorage.load())
        assertTrue("the old message went with the step", compose.onAllNodesWithText("Deze gebruikersnaam is al bezet.").fetchSemanticsNodes().isEmpty())
    }

    @Test
    fun `an address a password account already has - the server's words in the panel, and still signed out`() {
        server.googleMode = FakeOwnifyServer.GoogleMode.CONFLICT
        openSignIn()

        google()

        waitFor("Er bestaat al een account met dit e-mailadres. Log in met je wachtwoord en koppel Google via Instellingen.")
        assertNull(MemoryTokenStorage.load())
        // The password form is right there to do just that.
        field("Gebruikersnaam").assertIsDisplayed()
        googleButton().assertIsEnabled()
    }

    @Test
    fun `the chooser closed - back on the form, and nothing to read`() {
        FakeGoogle.answer = { _, _ -> GoogleIdResult.Cancelled }
        openSignIn()

        google()

        settle { FakeGoogle.asked.isNotEmpty() && OwnifyConnection.auth == OwnifyAuthState.Idle }
        compose.waitForIdle()
        googleButton().assertIsEnabled()
        assertTrue(compose.onAllNodesWithText("niet gelukt", substring = true).fetchSemanticsNodes().isEmpty())
        assertTrue(server.google("verify").isEmpty())
    }

    @Test
    fun `no Google account on the phone - the panel says what to do`() {
        FakeGoogle.answer = { _, _ -> GoogleIdResult.NoAccount }
        openSignIn()

        google()

        waitFor("Er staat geen Google-account op deze telefoon.")
        assertNull(MemoryTokenStorage.load())
    }

    @Test
    fun `not set up on the server - the button is disabled, with the website's words`() {
        server.googleMode = FakeOwnifyServer.GoogleMode.NOT_CONFIGURED
        openSignIn()

        waitFor("Google is nog niet gekoppeld.")
        googleButton().assertIsNotEnabled()
        googleButton().performSemanticsAction(SemanticsActions.OnClick)
        compose.waitForIdle()
        assertTrue(FakeGoogle.asked.isEmpty())
        assertTrue(server.google("nonce").isEmpty())
    }

    @Test
    fun `signed in with Google, signed out in Instellingen - the opening screen, and Google again to come back`() {
        openSignIn()
        google()
        waitForPages()

        button("Instellingen").performClick()
        button("Uitloggen").performScrollTo().performSemanticsAction(SemanticsActions.OnClick)

        waitFor("Registreren")
        assertNull(MemoryTokenStorage.load())
        assertTrue(ACCOUNT_TOKEN in server.revoked)
        settle { FakeGoogle.signedOut == 1 }

        button("Inloggen").performClick()
        waitForGoogle()
        google()
        waitForPages()
        assertEquals("Google was asked again, with a new nonce", 2, FakeGoogle.asked.size)
        assertEquals(2, server.googleNonces.toSet().size)
    }
}
