package com.ownify.android.ui

import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.semantics.getOrNull
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performTouchInput
import androidx.compose.ui.unit.dp
import androidx.compose.foundation.clickable
import com.ownify.android.ui.design.LocalReducedMotion
import com.ownify.android.ui.design.LocalStillMotion
import com.ownify.android.ui.design.RangeSwitch
import com.ownify.android.ui.design.glassPress
import com.ownify.android.ui.theme.OwnifyTheme
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/**
 * 41.0 — Liquid Glass under the finger, on its own: a period switch slid
 * along chooses only on the lift, and a glass control held grows a little
 * (none with reduced motion).
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [36], qualifiers = "+w412dp-h915dp-port")
class GlassInteractionTest {

    @get:Rule
    val compose = createComposeRule()

    private val periods = listOf("7" to "7 dagen", "30" to "30 dagen", "90" to "90 dagen", "365" to "1 jaar")

    private fun option(label: String) = compose.onNode(hasText(label), useUnmergedTree = true)

    private fun chosen(label: String) =
        compose.onNode(hasText(label)).fetchSemanticsNode().config.getOrNull(SemanticsProperties.Selected) == true

    @Test
    fun `a period switch slid along - the chosen glass follows, nothing is chosen until the lift`() {
        var selected by mutableStateOf("7")
        val chosenLog = mutableListOf<String>()
        compose.setContent {
            OwnifyTheme {
                CompositionLocalProvider(LocalStillMotion provides true) {
                    Column {
                        RangeSwitch(periods, selected, { selected = it; chosenLog += it }, Modifier.testTag("switch"), wide = true)
                    }
                }
            }
        }
        compose.waitForIdle()
        val from = option("7 dagen").fetchSemanticsNode().boundsInRoot.center
        val to = option("90 dagen").fetchSemanticsNode().boundsInRoot.center

        compose.onNodeWithTag("switch").performTouchInput {
            val origin = compose.onNodeWithTag("switch").fetchSemanticsNode().boundsInRoot.topLeft
            down(from - origin)
            repeat(8) { moveBy(Offset((to.x - from.x) / 8f, 0f)); advanceEventTime(16) }
        }
        compose.waitForIdle()
        assertTrue("shown chosen under the finger", chosen("90 dagen"))
        assertTrue("nothing chosen yet", chosenLog.isEmpty())
        assertEquals("7", selected)

        compose.onNodeWithTag("switch").performTouchInput { up() }
        compose.waitForIdle()
        assertEquals("chosen on the lift, once", listOf("90"), chosenLog)
        assertTrue(chosen("90 dagen"))

        // A tap is still a tap.
        option("30 dagen").performClick()
        compose.waitForIdle()
        assertEquals(listOf("90", "30"), chosenLog)
    }

    /** The glass's width held for 700 ms against at rest, and once let go. */
    private fun held(reduced: Boolean): Pair<Float, Float> {
        val interaction = MutableInteractionSource()
        compose.setContent {
            OwnifyTheme {
                CompositionLocalProvider(LocalReducedMotion provides reduced) {
                    Box(
                        Modifier
                            .glassPress(interaction, CircleShape)
                            // Inside the press's layer, so its bounds are drawn ones.
                            .testTag("glass")
                            .size(44.dp)
                            .clickable(interaction, indication = null) { }
                    )
                }
            }
        }
        compose.waitForIdle()
        fun width() = compose.onNodeWithTag("glass").fetchSemanticsNode().boundsInRoot.width
        val rest = width()
        compose.mainClock.autoAdvance = false
        compose.onNodeWithTag("glass").performTouchInput { down(center) }
        compose.mainClock.advanceTimeBy(700)
        val grown = width() / rest
        compose.onNodeWithTag("glass").performTouchInput { up() }
        compose.mainClock.advanceTimeBy(900)
        val after = width() / rest
        compose.mainClock.autoAdvance = true
        return grown to after
    }

    @Test
    fun `a glass control held - it grows 8 percent and settles back`() {
        val (grown, after) = held(reduced = false)
        assertEquals("held: 8 % larger", 1.08f, grown, 0.01f)
        assertEquals("let go: back to its size", 1f, after, 0.005f)
    }

    @Test
    fun `with reduced motion a glass control held does not grow`() {
        val (grown, _) = held(reduced = true)
        assertEquals(1f, grown, 0.005f)
    }
}
