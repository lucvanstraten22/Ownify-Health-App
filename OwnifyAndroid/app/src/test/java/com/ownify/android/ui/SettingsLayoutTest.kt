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
import androidx.compose.ui.unit.dp
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
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/**
 * Instellingen as the server lists it (config/settings.php): Meldingen,
 * Thema & uiterlijk, Taal and Voorkeuren under App — Voorkeuren one row,
 * opening one screen with Eenheden, Eerste dag van de week and
 * Toegankelijkheid — and Over de app without Gebouwd met, its Hulp without
 * Contact.
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [36], qualifiers = "w412dp-h915dp-port-420dpi")
class SettingsLayoutTest {

    @get:Rule
    val compose = createComposeRule()

    private lateinit var shell: ShellState

    private fun show() {
        val state = JSONObject(javaClass.classLoader!!.getResource("state-demo.json").readText()).getJSONObject("data")
        compose.setContent {
            OwnifyTheme {
                BoxWithConstraints(Modifier.fillMaxSize()) {
                    CompositionLocalProvider(LocalScreen provides ScreenMetrics(maxWidth, maxHeight), LocalStillMotion provides true) {
                        AppShell(AppData.parse(state), OwnifyScreens.shell, onShell = { shell = it })
                    }
                }
            }
        }
        compose.waitForIdle()
        compose.runOnUiThread { shell.tab("settings") }
        compose.waitForIdle()
    }

    /** A main-page row, by what it says: "Eenheden — Metrisch. Open instellingen." */
    private fun row(label: String) = compose.onNode(hasContentDescription("$label — ", substring = true) and hasClickAction())

    private fun heading(text: String) = compose.onNode(hasText(text) and SemanticsMatcher.keyIsDefined(SemanticsProperties.Heading), useUnmergedTree = true)

    private fun text(text: String) = compose.onNode(hasText(text), useUnmergedTree = true)

    private fun none(text: String) = compose.onAllNodes(hasText(text), useUnmergedTree = true).fetchSemanticsNodes().isEmpty() &&
        compose.onAllNodes(hasContentDescription(text, substring = true), useUnmergedTree = true).fetchSemanticsNodes().isEmpty()

    // Where it is laid out, below the fold too (boundsInRoot is cut to the screen).
    private val SemanticsNodeInteraction.top get() = fetchSemanticsNode().positionInRoot.y
    private val SemanticsNodeInteraction.bottom get() = fetchSemanticsNode().let { it.positionInRoot.y + it.size.height }

    @Test
    fun `the main page - App holds Meldingen, Thema & uiterlijk, Taal and Voorkeuren - no row of its own for the three in Voorkeuren`() {
        show()

        val order = listOf(
            heading("APP"), row("Meldingen"), row("Thema & uiterlijk"), row("Taal"), row("Voorkeuren"),
            heading("OVER"), row("Over de app")
        ).map { it.top }
        assertEquals("top to bottom", order.sorted(), order)
        for (gone in listOf("Eenheden — ", "Eerste dag van de week — ", "Toegankelijkheid — ")) assertTrue(gone, none(gone))
        assertTrue("no group of its own", none("VOORKEUREN"))
    }

    @Test
    fun `Voorkeuren opens one screen with Eenheden, Eerste dag van de week and Toegankelijkheid, their content as before`() {
        show()

        // As a screen reader opens it: the lower rows sit under the tab bar until scrolled.
        row("Voorkeuren").performSemanticsAction(SemanticsActions.OnClick)
        compose.waitForIdle()
        val tops = listOf(
            "Eenheden" to "Hoe lengte, gewicht en afstand worden getoond.",
            "Eerste dag van de week" to "Bepaalt waar je week begint in overzichten en grafieken.",
            "Toegankelijkheid" to "De app volgt je systeeminstellingen waar dat kan."
        ).map { (title, lede) ->
            heading(title).assertExists()
            text(lede).assertExists()
            heading(title).top
        }
        assertEquals("in that order", tops.sorted(), tops)
        // Each setting's own content.
        for (kept in listOf("Metrisch", "Imperiaal", "Gewicht", "Maandag", "Zondag", "Minder beweging", "Grotere tekst")) text(kept).assertExists()
    }

    @Test
    fun `Over de app - no Gebouwd met, and Hulp without Contact - its heading over the note, where a card would sit`() {
        show()
        compose.runOnUiThread { shell.openDetail(Detail.SettingsPage("about")) }
        compose.waitForIdle()

        for (kept in listOf("Naam", "Versie", "Privacyverklaring", "Voorwaarden", "Licenties")) text(kept).assertExists()
        // The Versie row's value, whatever the version (the main page says "Versie Beta …").
        assertTrue(compose.onAllNodes(hasText("Beta ", substring = true), useUnmergedTree = true).fetchSemanticsNodes()
            .any { node -> node.config.getOrNull(SemanticsProperties.Text).orEmpty().any { it.text.startsWith("Beta ") } })
        heading("JURIDISCH").assertExists()
        assertTrue("Gebouwd met is gone", none("Gebouwd met"))
        assertTrue("Contact is gone", none("Contact"))

        val hulp = heading("HULP")
        val note = text("Ownify is geen medisch hulpmiddel. De scores en suggesties zijn bedoeld om je eigen ritme te volgen, niet om een diagnose te stellen.")
        // The eyebrow's 8 below it, as over every card on these screens, then the
        // note's own frame and padding (1 + 16) to its text.
        val gap = with(compose.density) { (note.top - hulp.bottom).toDp() }
        assertTrue("the note right under Hulp: $gap", gap > 24.dp && gap < 26.dp)
    }
}
