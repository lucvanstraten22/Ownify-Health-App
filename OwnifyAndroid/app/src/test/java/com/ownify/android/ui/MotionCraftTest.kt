package com.ownify.android.ui

import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.Modifier
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onLast
import androidx.compose.ui.test.performClick
import com.ownify.android.data.AppData
import com.ownify.android.ui.app.AppShell
import com.ownify.android.ui.app.Overlay
import com.ownify.android.ui.app.PanelLook
import com.ownify.android.ui.app.ShellState
import com.ownify.android.ui.app.panelLook
import com.ownify.android.ui.design.LocalReducedMotion
import com.ownify.android.ui.design.LocalScreen
import com.ownify.android.ui.design.ScreenMetrics
import com.ownify.android.ui.screens.OwnifyScreens
import com.ownify.android.ui.screens.goals.stepFrom
import com.ownify.android.ui.theme.OwnifyTheme
import org.json.JSONObject
import kotlin.math.roundToInt
import kotlinx.coroutines.runBlocking
import com.ownify.android.ui.app.Detail
import com.ownify.android.ui.app.DETAIL_TITLE
import androidx.compose.ui.test.hasTestTag
import org.junit.Assert.assertEquals
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/**
 * The Phase 2 motion (B5), as the website's: a floating panel materialises —
 * its frost, its scale from .96 and its opacity together (`--panel-in`) — and
 * with reduced motion only fades; a goal-wizard step that arrives while the
 * one before it is still on its way picks up from where that one is, so quick
 * taps land on the right step without a flash or a jump.
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [36], qualifiers = "+w412dp-h915dp-port-420dpi")
class MotionCraftTest {

    @get:Rule
    val compose = createComposeRule()

    private lateinit var shell: ShellState

    @Test
    fun `a panel materialises - frost, scale and opacity together`() {
        assertEquals(PanelLook(0f, 0.96f, 0f), panelLook(0f, reduced = false))
        val half = panelLook(0.5f, reduced = false)
        assertEquals(0.5f, half.alpha, 1e-6f)
        assertEquals(0.98f, half.scale, 1e-6f)
        assertEquals(0.5f, half.frost, 1e-6f)
        assertEquals(PanelLook(1f, 1f, 1f), panelLook(1f, reduced = false))
        assertEquals("no overshoot past its place", PanelLook(1f, 1f, 1f), panelLook(1.2f, reduced = false))
    }

    @Test
    fun `reduced motion - a panel only fades, its frost there from the start`() {
        assertEquals(PanelLook(0f, 1f, 1f), panelLook(0f, reduced = true))
        assertEquals(PanelLook(0.5f, 1f, 1f), panelLook(0.5f, reduced = true))
        assertEquals(PanelLook(1f, 1f, 1f), panelLook(1f, reduced = true))
    }

    @Test
    fun `a step picks up where the one before it is`() {
        assertEquals("the one before had arrived: from the start", 0f, stepFrom(1f, still = false))
        assertEquals("still on its way: from there", 0.4f, stepFrom(0.4f, still = false))
        assertEquals("still motion (screenshots): already there", 1f, stepFrom(0.4f, still = true))
    }

    @Test
    fun `quick taps through the wizard land on the right step`() = quickTaps(reduced = false)

    @Test
    fun `quick taps through the wizard land on the right step, with reduced motion`() = quickTaps(reduced = true)

    private fun quickTaps(reduced: Boolean) {
        val state = JSONObject(javaClass.classLoader!!.getResource("state-new-account.json").readText()).getJSONObject("data")
        compose.setContent {
            OwnifyTheme {
                BoxWithConstraints(Modifier.fillMaxSize()) {
                    CompositionLocalProvider(LocalScreen provides ScreenMetrics(maxWidth, maxHeight), LocalReducedMotion provides reduced) {
                        AppShell(AppData.parse(state), OwnifyScreens.shell, onShell = { shell = it })
                    }
                }
            }
        }
        compose.waitForIdle()
        compose.runOnUiThread { shell.open(Overlay.Wizard) }
        compose.waitForIdle()
        tap("Activiteit")
        compose.waitForIdle()

        // Forward, back and forward again, each before the step before it has arrived.
        compose.mainClock.autoAdvance = false
        tap("Volgende")
        compose.mainClock.advanceTimeBy(80)
        tap("Terug")
        compose.mainClock.advanceTimeBy(60)
        shows("Waar gaat je doel over?", "Wat wil je bereiken?")
        tap("Volgende")
        compose.mainClock.advanceTimeByFrame()
        shows("Wat wil je bereiken?", "Waar gaat je doel over?")

        compose.mainClock.autoAdvance = true
        compose.waitForIdle()
        shows("Wat wil je bereiken?", "Waar gaat je doel over?")
    }

    /**
     * B4: a detail's bar takes the page's name only once the hero's name has
     * gone under it, and gives it back at the top — Slaap and the Scorekompas.
     */
    @Test
    fun `a detail's bar takes its title once the hero's name has gone under it`() {
        val state = JSONObject(javaClass.classLoader!!.getResource("state-demo.json").readText()).getJSONObject("data")
        // The Scorekompas comes with the server's compass (HealthHistoryTest does the same).
        state.put("compass", JSONObject(javaClass.classLoader!!.getResource("compass-demo.json").readText()))
        compose.setContent {
            OwnifyTheme {
                BoxWithConstraints(Modifier.fillMaxSize()) {
                    CompositionLocalProvider(LocalScreen provides ScreenMetrics(maxWidth, maxHeight)) {
                        AppShell(AppData.parse(state), OwnifyScreens.shell, onShell = { shell = it })
                    }
                }
            }
        }
        compose.waitForIdle()
        for (detail in listOf<Detail>(Detail.ScoreCompass, Detail.HealthArea("sleep"))) {
            compose.runOnUiThread { shell.openDetail(detail) }
            compose.waitForIdle()
            assertEquals("$detail is open", detail, shell.detail)
            assertEquals("$detail: no title at the top", 0, titles())
            scrollDetail(40)
            assertEquals("$detail: scrolled, but the name is still below the bar", 0, titles())
            scrollDetail(760)
            assertEquals("$detail: the name has gone under the bar", 1, titles())
            scrollDetail(0)
            assertEquals("$detail: back at the top", 0, titles())
            compose.runOnUiThread { shell.closeDetail() }
            compose.waitForIdle()
        }
    }

    private fun titles() = compose.onAllNodes(hasTestTag(DETAIL_TITLE), useUnmergedTree = true).fetchSemanticsNodes().size

    private fun scrollDetail(dp: Int) {
        val px = (dp * compose.density.density).roundToInt()
        compose.runOnUiThread { runBlocking { shell.detailScroll.scrollTo(px) } }
        compose.waitForIdle()
    }

    private fun tap(text: String) {
        compose.onAllNodes(hasText(text) and hasClickAction()).onLast().performClick()
    }

    /** [here] is on screen and [gone] is not. */
    private fun shows(here: String, gone: String) {
        assertEquals(here, 1, compose.onAllNodesWithText(here).fetchSemanticsNodes().size)
        assertEquals(gone, 0, compose.onAllNodesWithText(gone).fetchSemanticsNodes().size)
    }
}
