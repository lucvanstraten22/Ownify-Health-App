package com.ownify.android.ui

import android.content.Context
import android.view.HapticFeedbackConstants
import android.view.View
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.platform.LocalView
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.semantics.getOrNull
import androidx.compose.ui.test.SemanticsMatcher
import androidx.compose.ui.test.SemanticsNodeInteraction
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.isSelected
import androidx.compose.ui.test.isHeading
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onLast
import androidx.compose.ui.test.onRoot
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performSemanticsAction
import androidx.compose.ui.test.performTouchInput
import androidx.test.core.app.ApplicationProvider
import androidx.work.Configuration
import androidx.work.testing.SynchronousExecutor
import androidx.work.testing.WorkManagerTestInitHelper
import com.ownify.android.connection.ACCOUNT_TOKEN
import com.ownify.android.connection.FakeOwnifyServer
import com.ownify.android.connection.HealthAccess
import com.ownify.android.connection.MemoryTokenStorage
import com.ownify.android.connection.OwnifyApi
import com.ownify.android.connection.OwnifyConnection
import com.ownify.android.connection.OwnifySyncRunner
import com.ownify.android.connection.OwnifySyncStatusPrefs
import com.ownify.android.connection.TestSyncEnvironment
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
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.Shadows.shadowOf
import org.robolectric.annotation.Config

/**
 * Gezondheid's pull to sync (PullSync.kt), against a server on localhost
 * with this phone's Health Connect stood in for: a pull past the point
 * syncs this phone and every source the server can ask, ticks once, says
 * so for as long as it takes and goes; a short one does nothing; a failure
 * says so and Opnieuw retries; a phone with nothing to send only refreshes
 * and says "Bijgewerkt"; and the gesture never takes a scroll, another
 * page, a sideways swipe or the assistant's.
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [36], qualifiers = "+w412dp-h915dp-port")
class PullSyncFlowTest {

    @get:Rule
    val compose = createComposeRule()

    private lateinit var context: Context
    private lateinit var server: FakeOwnifyServer
    private var view: View? = null

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
        server.holdGate.countDown()
        server.stop()
        OwnifyAppState.clear()
        TestSyncEnvironment.access = HealthAccess.AVAILABLE
        Ownify.use(OwnifyMode.DARK)
    }

    private fun show() = compose.setContent {
        view = LocalView.current
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

    private fun said(words: String) =
        compose.onAllNodes(hasContentDescription(words, substring = true), useUnmergedTree = true).fetchSemanticsNodes().isNotEmpty()

    private fun waitForSaid(words: String) = compose.waitUntil(15_000) { said(words) }

    private fun haptic() = shadowOf(view).lastHapticFeedbackPerformed()

    /** Opens Gezondheid, signed in. */
    private fun onHealth() {
        MemoryTokenStorage.signedIn()
        show()
        waitFor("Gezondheidsscore")
        button("Gezondheid").performClick()
        compose.waitForIdle()
        compose.mainClock.advanceTimeBy(600)
    }

    /** A finger from the middle of the page, down by [dy] dp; [held] runs before it lifts. */
    private fun pull(dy: Float, held: () -> Unit = {}) {
        compose.onRoot().performTouchInput {
            val start = Offset(width / 2f, height * 0.45f)
            down(start)
            val steps = 30
            val by = dy * density / steps
            repeat(steps) { moveBy(Offset(0f, by)) }
        }
        held()
        compose.onRoot().performTouchInput { up() }
    }

    @Test
    fun `a pull past the point syncs this phone and Polar, ticks once on letting go, says so, then goes`() {
        server.connectPolar()
        onHealth()
        val ingests = server.ingests.size
        val reads = server.requestsTo("state.php").size

        pull(300f) {
            // Past the point, still held: nothing sent, no tick yet.
            assertEquals(ingests, server.ingests.size)
            assertTrue("no tick while dragging", haptic() != HapticFeedbackConstants.GESTURE_THRESHOLD_ACTIVATE)
        }
        assertEquals("one tick, Android's threshold feedback", HapticFeedbackConstants.GESTURE_THRESHOLD_ACTIVATE, haptic())

        waitForSaid("Gesynchroniseerd")
        assertEquals("this phone's Health Connect sent once", ingests + 1, server.ingests.size)
        assertEquals("with the account token", "Bearer $ACCOUNT_TOKEN", server.ingests.last().headers["authorization"])
        assertEquals("Polar asked once", 1, server.requestsTo("sync.php").size)
        assertTrue("the pages read again", server.requestsTo("state.php").size > reads)

        // A moment, then away.
        compose.waitUntil(10_000) { !said("Gesynchroniseerd") }
    }

    @Test
    fun `a short pull goes back and syncs nothing`() {
        server.connectPolar()
        onHealth()
        val ingests = server.ingests.size
        pull(60f)
        compose.mainClock.advanceTimeBy(1_500)
        compose.waitForIdle()
        assertEquals(ingests, server.ingests.size)
        assertEquals(0, server.requestsTo("sync.php").size)
        assertTrue("no tick", haptic() != HapticFeedbackConstants.GESTURE_THRESHOLD_ACTIVATE)
        assertTrue(!said("Aan het synchroniseren"))
    }

    @Test
    fun `a failure says so with its reason, and Opnieuw syncs again`() {
        server.connectPolar()
        server.polarFails = true
        onHealth()
        pull(300f)
        waitForSaid("Synchroniseren mislukt")
        assertTrue("the reason is read out", said("Polar is nu niet bereikbaar."))

        server.polarFails = false
        compose.onNode(hasContentDescription("Synchroniseren mislukt", substring = true), useUnmergedTree = true)
            .performSemanticsAction(SemanticsActions.OnClick)
        waitForSaid("Gesynchroniseerd")
        assertEquals("asked twice: once failed, once again", 2, server.requestsTo("sync.php").size)
    }

    @Test
    fun `a phone with nothing to send only refreshes, and says Bijgewerkt — never Gesynchroniseerd`() {
        TestSyncEnvironment.access = HealthAccess.NOT_INSTALLED
        onHealth()
        val ingests = server.ingests.size
        val reads = server.requestsTo("state.php").size
        pull(300f)
        waitForSaid("Bijgewerkt")
        assertTrue(!said("Gesynchroniseerd"))
        assertEquals("nothing sent", ingests, server.ingests.size)
        assertTrue("the pages read again", server.requestsTo("state.php").size > reads)
    }

    @Test
    fun `TalkBack's Synchroniseren action syncs as a pull does`() {
        onHealth()
        val action = SemanticsMatcher("Synchroniseren action") { node ->
            node.config.getOrNull(SemanticsActions.CustomActions)?.any { it.label == "Synchroniseren" } == true
        }
        val node = compose.onAllNodes(action).fetchSemanticsNodes().first()
        compose.runOnUiThread { node.config[SemanticsActions.CustomActions].first { it.label == "Synchroniseren" }.action() }
        waitForSaid("Gesynchroniseerd")
    }

    /** A shorter phone, so Gezondheid can scroll at all. */
    @Test
    @Config(qualifiers = "w412dp-h560dp-port")
    fun `the gesture takes nothing else - a scroll, another page, a sideways swipe`() {
        server.connectPolar()
        onHealth()

        // Sideways is still the rail: on to Doelen.
        compose.onRoot().performTouchInput {
            down(Offset(width * 0.8f, height * 0.5f))
            repeat(12) { moveBy(Offset(-width * 0.05f, 2f)) }
            up()
        }
        compose.mainClock.advanceTimeBy(800)
        compose.waitForIdle()
        compose.onNode(hasText("Doelen") and isSelected()).assertExists()

        // On another page a pull is that page's scroll.
        pull(300f)
        compose.mainClock.advanceTimeBy(1_500)
        assertEquals(0, server.requestsTo("sync.php").size)

        // Back on Gezondheid, scrolled down: a pull down scrolls back, it does not sync.
        button("Gezondheid").performClick()
        compose.mainClock.advanceTimeBy(800)
        val title = compose.onNode(hasText("Gezondheid") and isHeading())
        val top = title.fetchSemanticsNode().positionInRoot.y
        compose.onRoot().performTouchInput {
            down(Offset(width / 2f, height * 0.7f))
            repeat(10) { moveBy(Offset(0f, -12f * density)) }
            up()
        }
        compose.mainClock.advanceTimeBy(1_000)
        compose.waitForIdle()
        assertTrue("the page scrolled down", title.fetchSemanticsNode().positionInRoot.y < top - 20f)
        // A short pull back down only scrolls it back up: not past its top, so not a pull of ours.
        pull(100f)
        compose.mainClock.advanceTimeBy(1_500)
        compose.waitForIdle()
        assertEquals(0, server.requestsTo("sync.php").size)
        assertTrue(!said("Aan het synchroniseren"))
    }
}
