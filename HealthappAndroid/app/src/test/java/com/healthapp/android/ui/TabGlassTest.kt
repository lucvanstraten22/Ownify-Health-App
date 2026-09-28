package com.healthapp.android.ui

import android.content.Context
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Rect
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.semantics.getOrNull
import androidx.compose.ui.test.SemanticsMatcher
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onRoot
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performTouchInput
import androidx.test.core.app.ApplicationProvider
import androidx.work.Configuration
import androidx.work.testing.SynchronousExecutor
import androidx.work.testing.WorkManagerTestInitHelper
import com.healthapp.android.data.JoluAppState
import com.healthapp.android.jolu.FakeJoluServer
import com.healthapp.android.jolu.JoluApi
import com.healthapp.android.jolu.JoluConnection
import com.healthapp.android.jolu.JoluSyncRunner
import com.healthapp.android.jolu.JoluSyncStatusPrefs
import com.healthapp.android.jolu.MemoryTokenStorage
import com.healthapp.android.jolu.TestSyncEnvironment
import com.healthapp.android.ui.app.JoluApp
import com.healthapp.android.ui.app.TabGlassTag
import com.healthapp.android.ui.design.LocalStillMotion
import com.healthapp.android.ui.screens.JoluScreens
import com.healthapp.android.ui.screens.goals.GoalBoard
import com.healthapp.android.ui.theme.JoluTheme
import kotlin.math.abs
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
 * The tab bar's chosen pane is one piece of glass that travels: sampled
 * frame by frame on the app's own clock, it leaves the old tab, stretches
 * on the way, lands centred on the new one at its resting size, keeps
 * course when sent somewhere else mid-way, follows a swipe under the
 * finger, and the tabs themselves never move.
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [36], qualifiers = "w412dp-h915dp-port")
class TabGlassTest {

    @get:Rule
    val compose = createComposeRule()

    private lateinit var context: Context
    private lateinit var server: FakeJoluServer

    private val tabs = listOf("Gezondheid", "Doelen", "Overzicht", "Community", "Instellingen")

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

    /** Signed in, on Overzicht, and then the clock is the test's. */
    private fun show(still: Boolean = false) {
        MemoryTokenStorage.signedIn()
        compose.setContent {
            JoluTheme {
                CompositionLocalProvider(LocalStillMotion provides still) {
                    JoluApp(JoluScreens.shell, JoluScreens.signedOut)
                }
            }
        }
        compose.waitUntil(15_000) { compose.onAllNodesWithText("Gezondheidsscore", substring = true).fetchSemanticsNodes().isNotEmpty() }
        compose.mainClock.autoAdvance = false
        frames(60)
    }

    private fun tab(label: String) =
        compose.onNode(SemanticsMatcher.expectValue(SemanticsProperties.Role, Role.Tab) and hasText(label))

    private fun tabBox(label: String): Rect = tab(label).fetchSemanticsNode().boundsInRoot

    private fun glass(): Rect {
        val nodes = compose.onAllNodes(SemanticsMatcher("glass") { it.config.getOrNull(SemanticsProperties.TestTag) == TabGlassTag }, useUnmergedTree = true)
            .fetchSemanticsNodes()
        assertEquals("exactly one glass", 1, nodes.size)
        return nodes.single().boundsInRoot
    }

    private fun selected(): String =
        tabs.single { tab(it).fetchSemanticsNode().config.getOrNull(SemanticsProperties.Selected) == true }

    private fun frames(n: Int) = repeat(n) { compose.mainClock.advanceTimeByFrame() }

    /** One frame's worth of the glass: its centre, its width. */
    private data class Frame(val centre: Float, val width: Float)

    /** Runs [n] frames, sampling the glass after each. */
    private fun record(n: Int): List<Frame> = List(n) {
        compose.mainClock.advanceTimeByFrame()
        glass().let { Frame(it.center.x, it.width) }
    }

    private fun assertRestsOn(label: String) {
        val g = glass()
        val t = tabBox(label)
        assertEquals("glass centred on $label", t.center.x, g.center.x, 0.6f)
        assertEquals("$label is the chosen tab", label, selected())
    }

    @Test
    fun `a tab sends the one glass there - it travels, stretches, overshoots a little and settles, in every direction`() {
        show()
        val cell = tabBox("Doelen").center.x - tabBox("Gezondheid").center.x
        val rest = glass().width
        val bar = tabs.associateWith { tabBox(it) }
        assertRestsOn("Overzicht")

        val trips = listOf(
            "Overzicht" to "Gezondheid", "Gezondheid" to "Doelen", "Doelen" to "Gezondheid", "Gezondheid" to "Instellingen",
            "Instellingen" to "Gezondheid", "Doelen" to "Overzicht", "Overzicht" to "Community", "Community" to "Instellingen",
            "Instellingen" to "Overzicht"
        )
        for ((from, to) in trips) {
            if (selected() != from) {
                tab(from).performClick(); frames(40)
            }
            val start = glass().center.x
            val end = tabBox(to).center.x
            val distance = abs(end - start) / cell

            tab(to).performClick()
            val path = record(40)

            // It travels: it passes through the tabs between, never faster than the spring's peak
            // (0.13 of the way in a 60 Hz frame).
            val steps = path.zipWithNext { a, b -> abs(b.centre - a.centre) }.plus(abs(path.first().centre - start))
            assertTrue("$from→$to moves in steps, not a jump (largest ${steps.max() / cell} tab)", steps.max() < 0.15f * distance * cell + 1f)
            assertTrue("$from→$to passes the middle of the way", path.any { abs(it.centre - (start + end) / 2) < 0.25f * cell * distance + 1 })
            // It stretches with its speed: more for a far tab than a near one, never more than 30 %.
            val stretch = path.maxOf { it.width } / rest - 1
            assertTrue("$from→$to stretches ($stretch)", stretch > 0.10f && stretch <= 0.301f)
            // A little past the mark and back — about 1.5 % of the way.
            val over = path.maxOf { (it.centre - end) * Math.signum(end - start) }
            assertTrue("$from→$to overshoots only a little (${over}px)", over in 0f..(0.03f * distance * cell + 1f))
            // At rest on the new tab, at its resting size, within about 400 ms.
            val arrived = path.indexOfFirst { abs(it.centre - end) < 0.05f * cell }
            assertTrue("$from→$to arrives in time (frame $arrived)", arrived in 5..20)
            assertRestsOn(to)
            assertEquals("back to its resting width", rest, glass().width, 0.5f)
            // The tabs never moved.
            tabs.forEach { assertEquals("$it stays put", bar.getValue(it), tabBox(it)) }
        }
    }

    @Test
    fun `tapped quickly, the glass turns on its way to each new tab and ends on the last one`() {
        show()
        tab("Gezondheid").performClick(); frames(40)
        val cell = tabBox("Doelen").center.x - tabBox("Gezondheid").center.x

        val path = mutableListOf<Frame>()
        for (label in listOf("Doelen", "Community", "Instellingen")) {
            tab(label).performClick()
            path += record(3)
        }
        path += record(40)
        assertTrue("never a jump", path.zipWithNext { a, b -> abs(b.centre - a.centre) }.max() < 0.15f * 3 * cell + 1f)
        assertRestsOn("Instellingen")

        // Back and forth, faster than it can arrive.
        for (label in listOf("Gezondheid", "Instellingen", "Doelen", "Overzicht")) {
            tab(label).performClick()
            frames(2)
        }
        frames(40)
        assertRestsOn("Overzicht")
    }

    @Test
    fun `a swipe carries the glass under the finger and it lands with the page`() {
        show()
        val cell = tabBox("Community").center.x - tabBox("Overzicht").center.x
        val start = glass().center.x
        val width = compose.onRoot().fetchSemanticsNode().size.width.toFloat()

        // Touch input is delivered as the clock runs.
        compose.mainClock.autoAdvance = true

        // A finger drags the page 40 % of the way towards Community, and holds.
        compose.onRoot().performTouchInput {
            down(Offset(width * 0.8f, height * 0.3f))
            repeat(8) { moveBy(Offset(-width * 0.05f, 0f)); advanceEventTime(16) }
        }
        compose.mainClock.advanceTimeBy(500)
        val share = (glass().center.x - start) / cell
        assertTrue("the glass is under the finger's share of the way ($share)", share in 0.3f..0.5f)
        assertEquals("the tab does not change while held", "Overzicht", selected())

        // Let go after a quick move on: the page and the glass go on to Community.
        compose.onRoot().performTouchInput {
            repeat(4) { moveBy(Offset(-width * 0.06f, 0f)); advanceEventTime(8) }
            up()
        }
        compose.mainClock.advanceTimeBy(800)
        assertRestsOn("Community")

        // And a short drag, held and let go, goes back — the glass with it.
        compose.onRoot().performTouchInput {
            down(Offset(width * 0.3f, height * 0.3f))
            repeat(5) { moveBy(Offset(width * 0.05f, 0f)); advanceEventTime(16) }
        }
        compose.mainClock.advanceTimeBy(500)
        assertTrue("carried back towards Overzicht", glass().center.x < tabBox("Community").center.x - 0.15f * cell)
        compose.onRoot().performTouchInput { up() }
        compose.mainClock.advanceTimeBy(800)
        assertRestsOn("Community")
    }

    @Test
    fun `with motion held still, the glass goes straight to the tab`() {
        show(still = true)
        tab("Instellingen").performClick()
        compose.mainClock.advanceTimeByFrame()
        assertRestsOn("Instellingen")
        tab("Gezondheid").performClick()
        compose.mainClock.advanceTimeByFrame()
        assertRestsOn("Gezondheid")
    }

}
