package com.ownify.android.ui

import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.semantics.getOrNull
import androidx.compose.ui.test.SemanticsMatcher
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performSemanticsAction
import com.ownify.android.data.AppData
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
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/**
 * Combined insights (docs/CORRELATIONS.md, 31.0) on the phone: what a server
 * stored for a synthetic account (correlations-state.json, read from
 * api/app/state.php — 90 days of made-up records with three planted links)
 * — Overzicht's four with their labels, the head that opens Inzichten, the
 * groups there, an insight's own page with Chart A and its numbers, and the
 * research it opens over that page: back one page at a time.
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [36], qualifiers = "+w412dp-h915dp-port-420dpi")
class CorrelationsFlowTest {

    @get:Rule
    val compose = createComposeRule()

    private lateinit var shell: ShellState

    private fun json(name: String) = JSONObject(javaClass.classLoader!!.getResource(name).readText())

    /** The demo state with the stored combined insights, their pages and their research merged in. */
    private fun data(): AppData {
        val state = json("state-demo.json").getJSONObject("data")
        val stored = json("correlations-state.json")
        state.put("insights", stored.getJSONObject("insights"))
        state.put("research", stored.getJSONObject("research"))
        state.put("correlations", stored.getJSONObject("correlations"))
        return AppData.parse(state)
    }

    private fun show(data: AppData = data()) {
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
    }

    private fun text(text: String) = compose.onNode(hasText(text), useUnmergedTree = true)

    private fun count(text: String) = compose.onAllNodes(hasText(text), useUnmergedTree = true).fetchSemanticsNodes().size

    private fun heading(text: String) =
        compose.onNode(hasText(text) and SemanticsMatcher.keyIsDefined(SemanticsProperties.Heading), useUnmergedTree = true)

    /**
     * Taps the [matcher]'s last node: a detail is drawn after the page under
     * it, which stays composed (and in the tree) behind it.
     */
    private fun tap(matcher: SemanticsMatcher) {
        val nodes = compose.onAllNodes(matcher)
        val node = nodes[nodes.fetchSemanticsNodes().lastIndex]
        node.performScrollTo()
        node.performSemanticsAction(SemanticsActions.OnClick)
        compose.waitForIdle()
    }

    /** An insight's row on Inzichten: one button, its name and sentence said together. */
    private fun row(title: String) = SemanticsMatcher("row $title") { node ->
        node.config.getOrNull(SemanticsProperties.ContentDescription).orEmpty().any { it.startsWith("$title — ") }
    } and hasClickAction()

    private fun back() {
        compose.runOnUiThread { shell.back() }
        compose.waitForIdle()
    }

    @Test
    fun `the state as the server sends it - four combined insights, the groups, every page and its research`() {
        val data = data()
        val insights = data.overview.insights
        assertTrue(insights.combined)
        assertEquals("filled", insights.state)
        assertEquals("Alle inzichten", insights.openLabel)
        assertEquals(listOf("C14", "C03", "C01", "C02"), insights.items.map { it.code })
        assertEquals(listOf("Duidelijk patroon", "Duidelijk patroon", "Duidelijk patroon", "Nog onduidelijk"), insights.items.map { it.tierLabel })
        assertEquals(listOf("filled", "filled", "filled", "waiting"), insights.items.map { it.state })

        val correlations = data.correlations
        assertTrue(correlations.available)
        assertEquals("Inzichten", correlations.title)
        assertEquals(listOf("Duidelijk patroon", "Geen patroon", "Nog onduidelijk"), correlations.groups.map { it.label })
        assertEquals(17, correlations.groups.sumOf { it.rows.size })
        assertEquals(17, correlations.details.size)
        // Every row on Overzicht and on Inzichten opens a page that is there.
        val rows = insights.items + correlations.groups.flatMap { it.rows }
        assertTrue(rows.all { it.detail != null && correlations.detail(it.detail!!) != null })
        // Every "Bekijk onderzoek" on those pages opens a research detail that is there.
        val research = correlations.details.values.flatMap { it.research }
        assertTrue(research.isNotEmpty())
        assertTrue(research.all { it.evidence.research in data.research.details })

        val water = correlations.detail("insight-C14")!!
        assertEquals("clear", water.tier)
        assertEquals(listOf("Trainingsdagen", "Rustdagen"), water.chart!!.rows.map { it.label })
        assertTrue(water.chart!!.rows.all { it.fill in 0f..1f })
        assertEquals("Verschil" to "+0,26 l", water.numbers.first())
        assertEquals("Dit komt overeen met wat onderzoek gemiddeld vindt.", water.concordance)
        assertEquals(4, water.method.size)
        assertTrue(water.sources.isNotEmpty() && water.sources.all { it.href.startsWith("https://") })

        // Only the person's own data: no research, and it says so.
        val late = correlations.detail("insight-C11")!!
        assertTrue(late.research.isEmpty())
        assertEquals("Alleen uit je eigen gegevens: er is geen goedgekeurd onderzoek over dit verband.", late.ownData)
        // Still being gathered: no chart, the numbers so far.
        assertNull(correlations.detail("insight-C02")!!.chart)
    }

    @Test
    fun `Overzicht - the four with their labels, no chips, and the head that opens Inzichten`() {
        show()
        for (title in listOf("Water op trainingsdagen", "Zware training en HRV", "Training en slaap", "Late training en inslapen")) {
            assertTrue(title, count(title) >= 1)
        }
        assertEquals(3, count("Duidelijk patroon"))
        assertEquals(1, count("Nog onduidelijk"))
        // The research is on each insight's page, not on Overzicht's rows.
        assertEquals(0, compose.onAllNodes(hasText("Bekijk onderzoek") and hasClickAction()).fetchSemanticsNodes().size)

        tap(hasText("Nuttige inzichten") and hasClickAction())
        assertEquals(Detail.Insights, shell.detail)
        assertNull(shell.under)
        heading("Inzichten").assertExists()
        for (group in listOf("DUIDELIJK PATROON", "GEEN PATROON", "NOG ONDUIDELIJK")) heading(group).assertExists()
        text("Genoeg dagen, en het verschil was kleiner dan wat ertoe doet.").assertExists()
        // All seventeen, each one button.
        val titles = data().correlations.groups.flatMap { group -> group.rows.map { it.title } }
        assertEquals(17, titles.size)
        for (title in titles) assertEquals(title, 1, compose.onAllNodes(row(title)).fetchSemanticsNodes().size)
    }

    @Test
    fun `an insight's page over Inzichten - the label, Chart A, the numbers, the research, the method`() {
        show()
        compose.runOnUiThread { shell.openDetail(Detail.Insights) }
        compose.waitForIdle()
        tap(row("Water op trainingsdagen"))
        assertEquals(Detail.InsightPage("insight-C14"), shell.detail)
        assertEquals(Detail.Insights, shell.under)

        heading("Water op trainingsdagen").assertExists()
        for (eyebrow in listOf("WAT OWNIFY ZAG", "IN CIJFERS", "UIT ONDERZOEK BLIJKT", "HOE OWNIFY DIT BEREKENT")) heading(eyebrow).assertExists()
        text("Een samenhang in jouw gegevens, geen oorzaak.").assertExists()
        // Chart A: the two day groups, their days, their values.
        for (cell in listOf("Trainingsdagen", "46 dagen", "1,88 l", "Rustdagen", "43 dagen", "1,63 l")) text(cell).assertExists()
        text("Gecorrigeerd voor je weekdagen en je trend over de periode.").assertExists()
        for (cell in listOf("Verschil", "+0,26 l", "90%-interval", "+0,13 l tot +0,39 l", "Kans op toeval")) text(cell).assertExists()
        text("Dit komt overeen met wat onderzoek gemiddeld vindt.").assertExists()
        // The label is a status here: nothing to tap.
        assertEquals(0, compose.onAllNodes(hasText("Duidelijk patroon") and hasClickAction()).fetchSemanticsNodes().size)
        text("Controlling the false discovery rate: a practical and powerful approach to multiple testing").assertExists()
    }

    @Test
    fun `its research opens over the insight's page, and back goes one page at a time`() {
        show()
        compose.runOnUiThread { shell.openDetail(Detail.Insights) }
        compose.waitForIdle()
        tap(row("Water op trainingsdagen"))
        tap(hasText("Bekijk onderzoek") and hasClickAction())
        assertEquals(Detail.Research("c50-1", over = Detail.InsightPage("insight-C14")), shell.detail)
        assertEquals(listOf(Detail.Insights, Detail.InsightPage("insight-C14")), shell.beneath.map { it.detail })
        heading("Uit onderzoek blijkt").assertExists()

        back()
        assertEquals(Detail.InsightPage("insight-C14"), shell.detail)
        assertEquals(Detail.Insights, shell.under)
        back()
        assertEquals(Detail.Insights, shell.detail)
        assertNull(shell.under)
        back()
        assertFalse(shell.detailShown)
    }

    @Test
    fun `a tab closes the whole stack`() {
        show()
        compose.runOnUiThread { shell.openDetail(Detail.Insights) }
        compose.waitForIdle()
        tap(row("Water op trainingsdagen"))
        tap(hasText("Bekijk onderzoek") and hasClickAction())
        assertEquals(2, shell.beneath.size)
        compose.runOnUiThread { shell.tab("health") }
        compose.waitForIdle()
        assertTrue(shell.beneath.isEmpty())
        assertFalse(shell.detailShown)
    }

    @Test
    fun `only the person's own data - no chip, and it says so`() {
        show()
        compose.runOnUiThread { shell.openDetail(Detail.Insights) }
        compose.waitForIdle()
        tap(row("Laat eten en inslapen"))
        assertEquals(Detail.InsightPage("insight-C11"), shell.detail)
        text("Alleen uit je eigen gegevens: er is geen goedgekeurd onderzoek over dit verband.").assertExists()
        // The chips of the page beneath stay in the tree; none on this one.
        assertEquals(0, compose.onAllNodes(hasText("Bekijk onderzoek") and hasClickAction()).fetchSemanticsNodes().size)
    }

    @Test
    fun `without combined insights in the state - the cards as before, no way to Inzichten`() {
        val state = json("state-demo.json").getJSONObject("data")
        val data = AppData.parse(state)
        assertFalse(data.overview.insights.combined)
        assertFalse(data.correlations.available)
        show(data)
        assertEquals(0, compose.onAllNodes(hasText("Nuttige inzichten") and hasClickAction()).fetchSemanticsNodes().size)
        compose.runOnUiThread { shell.openDetail(Detail.Insights) }
        compose.waitForIdle()
        assertEquals(0, count("Inzichten"))
    }
}
