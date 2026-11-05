package com.ownify.android.ui

import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.semantics.getOrNull
import androidx.compose.ui.test.SemanticsNodeInteraction
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.performSemanticsAction
import com.ownify.android.data.AppData
import com.ownify.android.data.SleepNight
import com.ownify.android.ui.design.LocalScreen
import com.ownify.android.ui.design.LocalStillMotion
import com.ownify.android.ui.design.ScreenMetrics
import com.ownify.android.ui.screens.health.SleepNightCard
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
 * 40.1: the Slaapverloop with Training's heart-rate switch and arrows, from
 * the night a server builds (sleep-night-periods.json, lib/hydrate-sleep.php):
 * Vandaag a night at a time, opened on the newest night there is, the arrows
 * back over seven mornings; 7 dagen to 1 jaar each night's stages as one
 * bar, read stage by stage, the stages named under it.
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [36], qualifiers = "+w412dp-h915dp-port-420dpi")
class SleepNightPeriodsTest {

    @get:Rule
    val compose = createComposeRule()

    private fun night() = SleepNight.parse(JSONObject(javaClass.classLoader!!.getResource("sleep-night-periods.json").readText()))

    private fun show(night: SleepNight = night()) {
        compose.setContent {
            OwnifyTheme {
                BoxWithConstraints(Modifier.fillMaxSize()) {
                    CompositionLocalProvider(LocalScreen provides ScreenMetrics(maxWidth, maxHeight), LocalStillMotion provides true) {
                        Column(Modifier.verticalScroll(rememberScrollState())) { SleepNightCard(night) }
                    }
                }
            }
        }
        compose.waitForIdle()
    }

    private fun shown(text: String) = compose.onAllNodes(hasText(text), useUnmergedTree = true).fetchSemanticsNodes().isNotEmpty()

    private fun click(node: SemanticsNodeInteraction) {
        node.performSemanticsAction(SemanticsActions.OnClick)
        compose.waitForIdle()
    }

    private fun reading(node: SemanticsNodeInteraction): String =
        node.fetchSemanticsNode().config.getOrNull(SemanticsProperties.StateDescription).orEmpty()

    @Test
    fun `as the server sends it - seven mornings, the switch, and the nights over four periods`() {
        val n = night()
        assertEquals("Slaapverloop", n.title)
        assertEquals("d2", n.defaultKey)
        assertEquals(listOf("Vandaag", "7 dagen", "30 dagen", "90 dagen", "1 jaar"), n.options.map { it.second })
        assertEquals(7, n.nights.size)
        assertEquals("Nacht van 2 op 3 nov", n.nights[2].heading)
        assertEquals(listOf("deep", "light", "rem", "restless", "awake"), n.stages.map { it.first })
        assertEquals(listOf("7", "30", "90", "365"), n.periods.map { it.period.key })
        val week = n.periods.first()
        val bar = week.stacks.first { it.isNotEmpty() }
        assertEquals("Diep at the bottom, Rusteloosheid its own, Wakker on top", listOf("deep", "light", "rem", "restless", "awake"), bar.map { it.stage })
        assertEquals(100f, bar.first().bottom)
        for (i in 1 until bar.size) assertEquals("each part on the one below it", bar[i - 1].top, bar[i].bottom)
    }

    @Test
    fun `Vandaag - the newest night, and the arrows back and on`() {
        show()
        assertTrue(shown("Slaapverloop"))
        assertTrue(shown("Nacht van 2 op 3 nov"))
        assertTrue(shown("110 min"))
        click(compose.onNode(hasContentDescription("Vorige nacht") and hasClickAction()))
        assertTrue(shown("Nacht van 1 op 2 nov"))
        click(compose.onNode(hasContentDescription("Volgende nacht") and hasClickAction()))
        click(compose.onNode(hasContentDescription("Volgende nacht") and hasClickAction()))
        assertTrue("a morning without a night says so", shown("4 nov") && shown("Geen nacht opgenomen op deze ochtend."))
        // Vandaag again: the newest night.
        click(compose.onAllNodes(hasText("Vandaag") and hasClickAction()).onFirst())
        assertTrue(shown("Nacht van 2 op 3 nov"))
    }

    @Test
    fun `7 dagen - each night one bar, read stage by stage, the stages named`() {
        show()
        click(compose.onAllNodes(hasText("7 dagen") and hasClickAction()).onFirst())
        assertTrue("no arrows over a period", !shown("Nacht van 2 op 3 nov"))
        for (stage in listOf("Wakker", "Rusteloosheid", "REM", "Licht", "Diep")) assertTrue(stage, shown(stage))
        assertTrue(shown("Tik of schuif over de grafiek om de fasen per nacht te zien."))
        val plot = compose.onNode(hasContentDescription("Slaapfasen per dag, de afgelopen 7 dagen"))
        fun act(label: String) {
            val action = plot.fetchSemanticsNode().config[SemanticsActions.CustomActions].first { it.label == label }
            compose.runOnUiThread { action.action() }
            compose.waitForIdle()
        }
        act("Volgende dag")
        assertEquals("today first: no night yet, and it says so", "5 november: Geen meting op deze dag.", reading(plot))
        act("Vorige dag")
        act("Vorige dag")
        assertEquals("3 november: Diep 1:17 u, Licht 4:02 u, REM 1:46 u, Rusteloosheid 0:02 u, Wakker 0:25 u", reading(plot))
    }
}
