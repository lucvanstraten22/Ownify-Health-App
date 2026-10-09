package com.ownify.android.ui

import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.semantics.getOrNull
import androidx.compose.ui.test.SemanticsMatcher
import androidx.compose.ui.test.SemanticsNodeInteraction
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.performSemanticsAction
import com.ownify.android.data.AppData
import com.ownify.android.data.SleepView
import com.ownify.android.ui.app.AppShell
import com.ownify.android.ui.app.Detail
import com.ownify.android.ui.app.ShellState
import com.ownify.android.ui.design.LocalScreen
import com.ownify.android.ui.design.LocalStillMotion
import com.ownify.android.ui.design.ScreenMetrics
import com.ownify.android.ui.screens.OwnifyScreens
import com.ownify.android.ui.theme.OwnifyTheme
import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/**
 * Slaap, drawn (docs/SLEEP.md): the demo pages with the Slaap view a server
 * builds from forty nights of Health Connect records (sleep-view.json) — the
 * night's stages on five rows, read period by period; four charts two by
 * two, each square; and each opening its own page over Slaap, back going to
 * Slaap.
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [36], qualifiers = "w412dp-h915dp-port-420dpi")
class SleepViewTest {

    @get:Rule
    val compose = createComposeRule()

    private lateinit var shell: ShellState

    private fun json(name: String) = JSONObject(javaClass.classLoader!!.getResource(name).readText())

    private fun data(): AppData {
        val state = json("state-demo.json").getJSONObject("data")
        state.getJSONObject("health").getJSONObject("areas").getJSONObject("sleep").put("view", json("sleep-view.json"))
        return AppData.parse(state)
    }

    private fun show() {
        val data = data()
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
            shell.tab("health")
            shell.openDetail(Detail.HealthArea("sleep"))
        }
        compose.waitForIdle()
    }

    private fun text(text: String) = compose.onNode(hasText(text), useUnmergedTree = true)

    private fun none(text: String) = compose.onAllNodes(hasText(text), useUnmergedTree = true).fetchSemanticsNodes().isEmpty()

    private fun mini(title: String) = compose.onNode(hasContentDescription("Open $title", substring = true) and hasClickAction())

    private fun night() = compose.onNode(hasContentDescription("Slaapfasen van 23:01 tot 07:19"))

    private fun reading(node: SemanticsNodeInteraction): String =
        node.fetchSemanticsNode().config.getOrNull(SemanticsProperties.StateDescription).orEmpty()

    private fun act(node: SemanticsNodeInteraction, label: String) {
        val action = node.fetchSemanticsNode().config[SemanticsActions.CustomActions].first { it.label == label }
        compose.runOnUiThread { action.action() }
        compose.waitForIdle()
    }

    @Test
    fun `the view as the server sends it - the night's five rows and periods, four charts over four periods`() {
        val view = data().health.area("sleep")!!.view as SleepView
        assertEquals(listOf("Wakker", "Rusteloosheid", "REM", "Licht", "Diep"), view.night.rows.map { it.label })
        assertEquals("23:01", view.night.start)
        assertEquals("07:19", view.night.end)
        assertEquals(21, view.night.blocks.size)
        assertEquals(0f, view.night.blocks.first().from)
        assertEquals(100f, view.night.blocks.last().to)
        assertEquals(listOf("bed", "spo2", "skin_temp", "hrv"), view.charts.map { it.id })
        for (chart in view.charts) assertEquals(listOf("7", "30", "90", "365"), chart.periods.map { it.key })
        val bed = view.chart("bed")!!
        assertTrue("bars and a line", bed.mixed && bed.periods.first().bars.isNotEmpty() && bed.periods.first().lines.size == 1)
        assertTrue("beside bars, no level named", bed.periods.first().grid.isEmpty())
        assertTrue("a line alone: its levels named", view.chart("spo2")!!.periods.first().grid.isNotEmpty())
        assertEquals(listOf("97%"), view.chart("spo2")!!.latest!!.texts)
    }

    @Test
    fun `the Slaap page - the night, then the four charts, without the numbers they replace`() {
        show()
        text("Slaapverloop").assertExists()
        text("Nacht van 7 op 8 okt").assertExists()
        for (label in listOf("Wakker", "Rusteloosheid", "REM", "Licht", "Diep")) text(label).assertExists()
        for (total in listOf("6 min", "129 min", "230 min", "133 min")) text(total).assertExists()
        night().assertExists()
        for (title in listOf("Tijd in bed + Regelmaat", "SpO₂", "Huidtemperatuur", "Hartslag­variabiliteit")) mini(title).assertExists()
        // Shown by the charts now: no Vandaag tiles (Slaapduur…), no stage bar, no rows of their own.
        for (gone in listOf("Slaapduur", "Duur en timing", "Onderbrekingen", "Efficiëntie", "Bedtijd", "Wektijd")) assertTrue(gone, none(gone))
        text("Nachtelijke waarden").assertExists()
    }

    @Test
    fun `the four charts two by two, each about square, the two of a row side by side and as tall`() {
        show()
        val boxes = listOf("Tijd in bed + Regelmaat", "SpO₂", "Huidtemperatuur", "Hartslag­variabiliteit").map { title ->
            val node = mini(title).fetchSemanticsNode()
            node.positionInRoot to node.size
        }
        val (a, b, c, d) = boxes
        assertEquals("one row", a.first.y, b.first.y)
        assertEquals("the next", c.first.y, d.first.y)
        assertTrue("below the first", c.first.y > a.first.y + a.second.height)
        assertTrue("side by side", b.first.x > a.first.x + a.second.width)
        for ((_, size) in boxes) {
            assertEquals(boxes[0].second.width, size.width)
            val ratio = size.height.toFloat() / size.width
            assertTrue("about square: $ratio", ratio in 1f..1.2f)
        }
        assertEquals("a row as tall as its taller card", a.second.height, b.second.height)
    }

    @Test
    fun `the night read period by period - its stage, when it began and ended`() {
        show()
        val plot = night()
        act(plot, "Volgende fase")
        assertEquals("Wakker, 23:01 tot 23:07", reading(plot))
        text("23:01 – 23:07").assertExists()
        act(plot, "Volgende fase")
        assertEquals("Licht, 23:07 tot 23:31", reading(plot))
        act(plot, "Vorige fase")
        assertEquals("Wakker, 23:01 tot 23:07", reading(plot))
        act(plot, "Fase verbergen")
        assertEquals("", reading(plot))
    }

    @Test
    fun `a small chart opens its own page over Slaap, and back goes to Slaap`() {
        show()
        mini("SpO₂").performSemanticsAction(SemanticsActions.OnClick)
        compose.waitForIdle()
        assertEquals(Detail.AreaChart("sleep", "spo2"), shell.detail)
        assertEquals(Detail.HealthArea("sleep"), shell.under)
        compose.onNode(hasText("SpO₂") and SemanticsMatcher.keyIsDefined(SemanticsProperties.Heading), useUnmergedTree = true).assertExists()
        for (period in listOf("7 dagen", "30 dagen", "90 dagen", "1 jaar")) text(period).assertExists()
        val plot = compose.onNode(hasContentDescription("SpO₂ per dag, de afgelopen 7 dagen"))
        act(plot, "Volgende dag")
        assertEquals("8 oktober: SpO₂ 97%", reading(plot))

        compose.runOnUiThread { shell.back() }
        compose.waitForIdle()
        assertEquals(Detail.HealthArea("sleep"), shell.detail)
        assertNull(shell.under)
        assertTrue(shell.detailShown)
        text("Slaapverloop").assertExists()
    }

    @Test
    fun `leaving for another tab closes both`() {
        show()
        mini("Huidtemperatuur").performSemanticsAction(SemanticsActions.OnClick)
        compose.waitForIdle()
        assertNotNull(shell.under)
        compose.runOnUiThread { shell.tab("goals") }
        compose.waitForIdle()
        assertNull(shell.detail)
        assertNull(shell.under)
    }
}
