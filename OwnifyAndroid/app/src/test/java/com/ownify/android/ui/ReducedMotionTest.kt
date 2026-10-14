package com.ownify.android.ui

import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.Modifier
import androidx.compose.ui.test.junit4.createComposeRule
import com.ownify.android.data.AppData
import com.ownify.android.ui.app.AppShell
import com.ownify.android.ui.app.Detail
import com.ownify.android.ui.app.ShellState
import com.ownify.android.ui.design.LocalReducedMotion
import com.ownify.android.ui.design.LocalScreen
import com.ownify.android.ui.design.ScreenMetrics
import com.ownify.android.ui.screens.OwnifyScreens
import com.ownify.android.ui.theme.Ownify
import com.ownify.android.ui.theme.OwnifyTheme
import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/**
 * Reduced motion (the system's "Remove animations"), as the website's
 * `prefers-reduced-motion`: the rail, a detail and the sheet are placed at
 * once, and what arrives — or what is uncovered — fades in over
 * `--reduce-fade` (180 ms).
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [36], qualifiers = "+w412dp-h915dp-port-420dpi")
class ReducedMotionTest {

    @get:Rule
    val compose = createComposeRule()

    private lateinit var shell: ShellState

    private fun show() {
        val state = JSONObject(javaClass.classLoader!!.getResource("state-demo.json").readText()).getJSONObject("data")
        compose.setContent {
            OwnifyTheme {
                BoxWithConstraints(Modifier.fillMaxSize()) {
                    CompositionLocalProvider(LocalScreen provides ScreenMetrics(maxWidth, maxHeight), LocalReducedMotion provides true) {
                        AppShell(AppData.parse(state), OwnifyScreens.shell, onShell = { shell = it })
                    }
                }
            }
        }
        compose.waitForIdle()
        compose.mainClock.autoAdvance = false
    }

    /** Runs [action], then one frame: what moved is already in place, its fade just begun. */
    private fun step(action: ShellState.() -> Unit) {
        compose.runOnUiThread { shell.action() }
        compose.mainClock.advanceTimeByFrame()
    }

    private fun faded() = compose.mainClock.advanceTimeBy(Ownify.ReduceFadeMs + 64L)

    @Test
    fun `a tab places the page at once and fades it in`() {
        show()
        val goals = shell.pageIds.indexOf("goals")

        step { tab("goals") }
        assertEquals("no slide", goals.toFloat(), shell.rail)
        assertTrue("fading in", shell.layerAlpha(ShellState.Layer.Rail) < 1f)

        faded()
        assertEquals(1f, shell.layerAlpha(ShellState.Layer.Rail))
    }

    @Test
    fun `a detail opens in place and fades in, and closing fades the page back in`() {
        show()

        step { openDetail(Detail.HealthArea("sleep")) }
        assertEquals("no slide", 1f, shell.detailProgress)
        assertTrue("fading in", shell.layerAlpha(ShellState.Layer.Detail) < 1f)
        assertEquals("the page beneath stays", 1f, shell.layerAlpha(ShellState.Layer.Rail))
        faded()
        assertEquals(1f, shell.layerAlpha(ShellState.Layer.Detail))

        step { closeDetail() }
        assertEquals(null, shell.detail)
        assertTrue("the page it uncovers fades in", shell.layerAlpha(ShellState.Layer.Rail) < 1f)
        faded()
        assertEquals(1f, shell.layerAlpha(ShellState.Layer.Rail))
    }

    @Test
    fun `the sheet opens in place and fades in, and closing over a detail fades the detail in`() {
        show()
        step { openDetail(Detail.HealthArea("sleep")) }
        faded()

        step { openAi() }
        assertEquals("no slide", 1f, shell.aiProgress)
        assertTrue("fading in", shell.layerAlpha(ShellState.Layer.Sheet) < 1f)
        faded()

        step { closeAi() }
        assertEquals(0f, shell.aiProgress)
        assertTrue("the detail it uncovers fades in", shell.layerAlpha(ShellState.Layer.Detail) < 1f)
        assertEquals(1f, shell.layerAlpha(ShellState.Layer.Rail))
        faded()
        assertEquals(1f, shell.layerAlpha(ShellState.Layer.Detail))
    }
}
