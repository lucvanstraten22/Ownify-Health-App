package com.ownify.android.ui

import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.semantics.getOrNull
import androidx.compose.ui.test.SemanticsMatcher
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.isSelected
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onRoot
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performTouchInput
import com.ownify.android.data.AppData
import com.ownify.android.ui.app.AppShell
import com.ownify.android.ui.app.Detail
import com.ownify.android.ui.app.ShellState
import com.ownify.android.ui.design.LocalScreen
import com.ownify.android.ui.design.LocalStillMotion
import com.ownify.android.ui.design.ScreenMetrics
import com.ownify.android.ui.screens.OwnifyScreens
import com.ownify.android.ui.screens.goals.GoalBoard
import com.ownify.android.ui.theme.OwnifyTheme
import org.json.JSONArray
import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/**
 * A goal's page (pages/goal-detail.php) in the app: the goal, Zelf bijhouden,
 * the Verloop — which now says what feeds it, and on each point how far the
 * goal was that day — and Aanpassen with Primair | Secundair. The demo
 * account's goals: 301 primary (0%), 303 Bench press (85%, kept by hand) and
 * 302 (76%).
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [36], qualifiers = "w412dp-h915dp-port-420dpi")
class GoalDetailLayoutTest {

    @get:Rule
    val compose = createComposeRule()

    private lateinit var shell: ShellState

    private fun data(edit: (JSONObject) -> Unit = {}): AppData {
        val state = JSONObject(javaClass.classLoader!!.getResource("state-demo.json").readText()).getJSONObject("data")
        edit(state)
        return AppData.parse(state)
    }

    private fun show(data: AppData, goal: String) {
        compose.setContent {
            OwnifyTheme {
                BoxWithConstraints(Modifier.fillMaxSize()) {
                    CompositionLocalProvider(LocalScreen provides ScreenMetrics(maxWidth, maxHeight), LocalStillMotion provides true) {
                        AppShell(data, OwnifyScreens.shell, onShell = { shell = it })
                    }
                }
            }
        }
        compose.waitForIdle()
        compose.runOnUiThread {
            shell.tab("goals")
            shell.openDetail(Detail.GoalPage(goal))
        }
        compose.waitForIdle()
    }

    private fun nodes(matcher: SemanticsMatcher) = compose.onAllNodes(matcher, useUnmergedTree = true).fetchSemanticsNodes()

    /** Where it is laid out, below the fold too. A heading by its exact words; a sentence by its start. */
    private fun top(text: String, whole: Boolean = true): Float {
        // On screen sideways: Gezondheid's own Verloop sits on a page beside this one.
        val width = compose.onRoot().fetchSemanticsNode().size.width
        return nodes(hasText(text, substring = !whole)).first { it.positionInRoot.x >= 0f && it.positionInRoot.x < width }.positionInRoot.y
    }

    @Test
    fun `the page - the goal with its Periode, Zelf bijhouden, the Verloop and what feeds it, Aanpassen - no Wat telt mee, Recent or Beheer`() {
        show(data(), "303")

        val order = listOf(top("Periode"), top("Zelf bijhouden"), top("Verloop"), top("Je voortgang verandert alleen", whole = false), top("Aanpassen"))
        assertEquals("top to bottom", order.sorted(), order)
        assertTrue("Gestart and Eindigt in the Periode row", nodes(hasText("Gestart: ", substring = true)).isNotEmpty() && nodes(hasText("Eindigt: ", substring = true)).isNotEmpty())
        for (gone in listOf("Wat telt mee", "Recent", "Beheer")) {
            assertTrue("$gone is gone", nodes(hasText(gone, ignoreCase = true)).isEmpty())
        }
    }

    @Test
    fun `Aanpassen - Primair pressed on the primary goal, Secundair on a secondary one`() {
        show(data(), "301")
        // The option is one merged node: its words and that it is the one chosen.
        compose.onNode(hasText("Primair") and isSelected()).assertExists()
        assertTrue(compose.onAllNodes(hasText("Secundair") and isSelected()).fetchSemanticsNodes().isEmpty())
    }

    @Test
    fun `Aanpassen on a secondary goal - Secundair pressed`() {
        show(data(), "303")
        compose.onNode(hasText("Secundair") and isSelected()).assertExists()
        assertTrue(compose.onAllNodes(hasText("Primair") and isSelected()).fetchSemanticsNodes().isEmpty())
    }

    @Test
    fun `Secundair hands the slot to the goal a delete would - the first of Secundaire doelen`() {
        val goals = data().goals
        val board = GoalBoard.board(goals)
        assertEquals("Bench press, 85%, the furthest along", "303", GoalBoard.successor(board, "301"))
        assertNull("a secondary goal hands nothing on", GoalBoard.successor(board, "303"))
    }

    @Test
    fun `the only goal - Secundair cannot be chosen, and the page says why`() {
        show(data { it.getJSONObject("goals").put("secondary", JSONArray()) }, "301")
        compose.onNode(hasText("Secundair") and hasClickAction(), useUnmergedTree = false).assertIsNotEnabled()
        assertTrue(nodes(hasText("Je enige doel is altijd je primaire doel.")).isNotEmpty())
    }

    @Test
    fun `a point on the Verloop says how far the goal was that day`() {
        // The board reads `secondary`, the goal's page `all`: the note goes on the point in each.
        val data = data { state ->
            for (list in listOf("secondary", "active", "all")) {
                val goals = state.getJSONObject("goals").getJSONArray(list)
                for (i in 0 until goals.length()) {
                    val goal = goals.getJSONObject(i)
                    if (goal.getString("id") != "302") continue
                    val points = goal.getJSONObject("chart").getJSONArray("points")
                    points.getJSONObject(points.length() - 1).put("n", "76% van je doel")
                }
            }
        }
        val point = data.goals.secondary.first { it.id == "302" }.chart!!.points.last()
        show(data, "302")

        val plot = compose.onNode(hasContentDescription("Verloop van 15 uur trainen", substring = true), useUnmergedTree = true)
        plot.performScrollTo()
        plot.performTouchInput { down(Offset(width * point.x / 100f, height / 2f)) }
        compose.waitForIdle()
        val reading = plot.fetchSemanticsNode().config.getOrNull(SemanticsProperties.StateDescription).orEmpty()
        assertEquals("${point.date}, ${point.value}, 76% van je doel", reading)
    }
}
