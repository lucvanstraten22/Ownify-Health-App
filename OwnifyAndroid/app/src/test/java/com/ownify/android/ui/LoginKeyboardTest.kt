package com.ownify.android.ui

import android.content.Context
import android.view.View
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.geometry.Rect
import androidx.compose.ui.platform.LocalView
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.test.SemanticsNodeInteraction
import androidx.compose.ui.test.getBoundsInRoot
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasSetTextAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onLast
import androidx.compose.ui.test.onRoot
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performSemanticsAction
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.core.graphics.Insets
import androidx.core.view.ViewCompat
import androidx.core.view.WindowInsetsCompat
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
import com.ownify.android.ui.theme.OwnifyTheme
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
 * The sign-in panel with the software keyboard open: the window runs edge to
 * edge, so the keyboard arrives as insets, not as a smaller window. Whatever
 * its height, the field being typed in and the button to sign in stay on the
 * screen above it — scrolled to, when there is not room for everything — and
 * when it closes the panel is as it was.
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [36], qualifiers = "w412dp-h915dp-port")
class LoginKeyboardTest {

    @get:Rule
    val compose = createComposeRule()

    private lateinit var context: Context
    private lateinit var server: FakeOwnifyServer
    private lateinit var view: View

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
        OwnifySyncStatusPrefs(context).clear()
    }

    @After
    fun tearDown() {
        server.stop()
        OwnifyAppState.clear()
    }

    private fun show() = compose.setContent {
        view = LocalView.current
        OwnifyTheme {
            CompositionLocalProvider(LocalStillMotion provides true) {
                OwnifyApp(OwnifyScreens.shell, OwnifyScreens.signedOut)
            }
        }
    }

    private val density get() = context.resources.displayMetrics.density

    /** The system bars as a phone has them, and a keyboard [keyboard] tall (0: closed). */
    private fun keyboard(keyboard: Dp) {
        val px = { dp: Dp -> (dp.value * density).toInt() }
        val bars = Insets.of(0, px(24.dp), 0, px(48.dp))
        val insets = WindowInsetsCompat.Builder()
            .setInsets(WindowInsetsCompat.Type.statusBars(), Insets.of(0, px(24.dp), 0, 0))
            .setInsets(WindowInsetsCompat.Type.navigationBars(), Insets.of(0, 0, 0, px(48.dp)))
            .setInsets(WindowInsetsCompat.Type.systemBars(), bars)
            .setInsets(WindowInsetsCompat.Type.ime(), Insets.of(0, 0, 0, px(keyboard)))
            .setVisible(WindowInsetsCompat.Type.ime(), keyboard > 0.dp)
            .build()
        compose.runOnUiThread { ViewCompat.dispatchApplyWindowInsets(view, insets) }
        compose.waitForIdle()
    }

    private fun field(label: String): SemanticsNodeInteraction =
        compose.onNode(hasContentDescription(label) and hasSetTextAction())

    /** The panel's own Inloggen: the last one, after the opening screen's. */
    private fun signInButton(): SemanticsNodeInteraction =
        compose.onAllNodes(hasText("Inloggen") and hasClickAction()).onLast()

    private fun SemanticsNodeInteraction.bounds(): Rect = with(compose.density) {
        val b = getBoundsInRoot()
        Rect(b.left.toPx(), b.top.toPx(), b.right.toPx(), b.bottom.toPx())
    }

    /** The part of the screen the keyboard leaves: under the status bar, above the keys. */
    private fun visibleArea(keyboard: Dp): Rect = with(compose.density) {
        val root = compose.onRoot().bounds()
        Rect(root.left, 24.dp.toPx(), root.right, root.bottom - maxOf(keyboard, 48.dp).toPx())
    }

    private fun assertOnScreen(what: String, node: SemanticsNodeInteraction, area: Rect) {
        val b = node.bounds()
        assertTrue("$what at $b, not inside $area", b.height > 0f && b.top >= area.top - 0.5f && b.bottom <= area.bottom + 0.5f)
    }

    private fun openSignIn() {
        show()
        keyboard(0.dp)
        compose.waitUntil(15_000) { compose.onAllNodesWithText("Registreren").fetchSemanticsNodes().isNotEmpty() }
        signInButton().performClick()
        compose.waitUntil(5_000) { compose.onAllNodes(hasContentDescription("Gebruikersnaam") and hasSetTextAction()).fetchSemanticsNodes().isNotEmpty() }
        compose.waitForIdle()
    }

    private fun usableWith(keyboard: Dp) {
        keyboard(keyboard)
        val area = visibleArea(keyboard)

        for (label in listOf("Gebruikersnaam", "Wachtwoord")) {
            field(label).performScrollTo().performSemanticsAction(SemanticsActions.RequestFocus)
            compose.waitForIdle()
            assertOnScreen("$label, being typed in, with a ${keyboard.value.toInt()} dp keyboard", field(label), area)
        }

        signInButton().performScrollTo()
        compose.waitForIdle()
        assertOnScreen("Inloggen with a ${keyboard.value.toInt()} dp keyboard", signInButton(), area)
    }

    @Test
    fun `a keyboard of ordinary height - the field being typed in and Inloggen stay above it`() {
        openSignIn()
        usableWith(300.dp)
    }

    @Test
    fun `a very large keyboard - still every field and Inloggen, scrolled to inside the panel`() {
        openSignIn()
        usableWith(560.dp)
    }

    @Test
    fun `tapped first, then the keyboard rises - the field being typed in is kept in view by itself`() {
        openSignIn()
        for (label in listOf("Gebruikersnaam", "Wachtwoord")) {
            keyboard(0.dp)
            field(label).performClick()
            compose.waitForIdle()
            keyboard(560.dp)
            assertOnScreen("$label, focused before the keyboard rose", field(label), visibleArea(560.dp))
        }
    }

    @Test
    fun `the keyboard closes - the panel is exactly as it was before it opened`() {
        openSignIn()
        val before = listOf(field("Gebruikersnaam").bounds(), field("Wachtwoord").bounds(), signInButton().bounds())

        usableWith(560.dp)
        keyboard(0.dp)
        compose.waitForIdle()
        // Scrolled back as far as the panel needs: with the room back, nothing is scrolled.
        val after = listOf(field("Gebruikersnaam").bounds(), field("Wachtwoord").bounds(), signInButton().bounds())
        assertEquals(before, after)
    }
}
