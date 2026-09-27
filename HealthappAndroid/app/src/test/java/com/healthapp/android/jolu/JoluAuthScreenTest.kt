package com.healthapp.android.jolu

import android.content.Context
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.ui.Modifier
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performTextInput
import androidx.test.core.app.ApplicationProvider
import androidx.work.Configuration
import androidx.work.testing.SynchronousExecutor
import androidx.work.testing.WorkManagerTestInitHelper
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Before
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/**
 * The temporary sign-in screen (JoluConnectionSection), rendered and used the
 * way a person would: type, tap "Inloggen", see who is signed in, tap
 * "Uitloggen". A test interface for a test interface — not the app's design.
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [36])
class JoluAuthScreenTest {

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
        JoluSyncStatusPrefs(context).clear()
    }

    @After
    fun tearDown() = server.stop()

    /** As MainActivity has it: in a scrolling column. */
    private fun show() = compose.setContent {
        Column(Modifier.verticalScroll(rememberScrollState())) { JoluConnectionSection() }
    }

    private fun waitFor(text: String) =
        compose.waitUntil(15_000) { compose.onAllNodesWithText(text).fetchSemanticsNodes().isNotEmpty() }

    @Test
    fun `signed out - sign in, see who is signed in, sign out`() {
        show()

        waitFor("Registreren")
        compose.onNodeWithText("Account aanmaken").performScrollTo().assertIsDisplayed()
        compose.onNodeWithText("E-mailadres").performScrollTo().assertIsDisplayed()
        compose.onNodeWithText("Connect JoLu").performScrollTo().assertIsDisplayed()   // pairing is still there

        compose.onAllNodesWithText("Gebruikersnaam")[0].performScrollTo().performTextInput("sanne")
        compose.onAllNodesWithText("Wachtwoord")[0].performScrollTo().performTextInput("geheim-wachtwoord")
        compose.onAllNodesWithText("Inloggen")[1].performScrollTo().performClick()   // [0] is the heading

        waitFor("Ingelogd als sanne")
        compose.onNodeWithText("Account: ingelogd").performScrollTo().assertIsDisplayed()
        compose.onNodeWithText("Sync to JoLu").performScrollTo().assertIsDisplayed()
        assertEquals(JoluCredential(ACCOUNT_TOKEN, JoluScope.ACCOUNT), MemoryTokenStorage.load())
        assertEquals("geheim-wachtwoord", server.requestsTo("app-login.php").single().body.getString("password"))

        compose.onNodeWithText("Uitloggen").performScrollTo().performClick()

        waitFor(JoluConnection.LOGGED_OUT_MESSAGE)
        assertNull(MemoryTokenStorage.load())
        compose.onNodeWithText("Registreren").performScrollTo().assertIsDisplayed()
    }

    @Test
    fun `a wrong password shows the server's message and stays signed out`() {
        server.loginMode = FakeJoluServer.LoginMode.WRONG_PASSWORD
        show()

        waitFor("Registreren")
        compose.onAllNodesWithText("Gebruikersnaam")[0].performScrollTo().performTextInput("sanne")
        compose.onAllNodesWithText("Wachtwoord")[0].performScrollTo().performTextInput("fout")
        compose.onAllNodesWithText("Inloggen")[1].performScrollTo().performClick()

        waitFor("Gebruikersnaam of wachtwoord klopt niet.")
        assertNull(MemoryTokenStorage.load())
    }
}
