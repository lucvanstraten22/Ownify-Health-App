package com.ownify.android.ui

import android.content.Context
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.SemanticsNodeInteraction
import androidx.compose.ui.test.assertIsOff
import androidx.compose.ui.test.assertIsOn
import androidx.compose.ui.test.assertIsSelected
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasSetTextAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.isHeading
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onLast
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performSemanticsAction
import androidx.compose.ui.test.performTextReplacement
import androidx.test.core.app.ApplicationProvider
import androidx.work.Configuration
import androidx.work.testing.SynchronousExecutor
import androidx.work.testing.WorkManagerTestInitHelper
import com.ownify.android.connection.ACCOUNT_TOKEN
import com.ownify.android.connection.FakeOwnifyServer
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
import java.time.LocalDate
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/**
 * Instellingen's Voorkeuren and Apparaten & Gezondheid as of 19.0, against a
 * server on localhost: the switches that used to be "Later instelbaar" save
 * to the account and act on the whole app at once, and are kept for the next
 * start; the week's first day is saved; Nu actief is a status that follows
 * them; a paired phone can be given a name of its own; and what was taken
 * away — Nu synchroniseren, Smartwatch, Slaapring — is gone.
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [36], qualifiers = "+w412dp-h915dp-port")
class SettingsPreferencesFlowTest {

    @get:Rule
    val compose = createComposeRule()

    private lateinit var context: Context
    private lateinit var server: FakeOwnifyServer

    @Before
    fun setUp() {
        context = ApplicationProvider.getApplicationContext()
        WorkManagerTestInitHelper.initializeTestWorkManager(context, Configuration.Builder().setExecutor(SynchronousExecutor()).build())
        server = FakeOwnifyServer()
        OwnifyConnection.api = OwnifyApi(server.url)
        OwnifyConnection.storage = { MemoryTokenStorage }
        TestSyncEnvironment.context = context
        TestSyncEnvironment.api = OwnifyApi(server.url)
        OwnifySyncRunner.environment = { TestSyncEnvironment }
        MemoryTokenStorage.clear()
        OwnifyConnection.reset()
        OwnifyAppState.clear()
        GoalBoard.clear()
        OwnifySyncStatusPrefs(context).clear()
        OwnifyThemeStore.forget(context)
        OwnifyPreferences.use()
        context.getSharedPreferences("ownify_preferences", Context.MODE_PRIVATE).edit().clear().commit()
        Ownify.use(OwnifyMode.DARK)
    }

    @After
    fun tearDown() {
        server.holdGate.countDown()
        server.stop()
        OwnifyAppState.clear()
        OwnifyPreferences.use()
        Ownify.useMoreContrast(false)
        Ownify.use(OwnifyMode.DARK)
    }

    private fun show() = compose.setContent {
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

    private fun gone(text: String) =
        assertTrue("$text is gone", compose.onAllNodesWithText(text, substring = true).fetchSemanticsNodes().isEmpty())

    /** Instellingen → [row], tapped as TalkBack taps it (the dock floats over the lower rows) — as are the switches below. */
    private fun open(row: String, expect: String) {
        waitFor("Gezondheidsscore")
        button("Instellingen").performClick()
        compose.onNode(hasContentDescription("$row —", substring = true) and hasClickAction())
            .performSemanticsAction(SemanticsActions.OnClick)
        // A text on it, or a card that speaks its name.
        compose.waitUntil(15_000) {
            compose.onAllNodes(hasText(expect, substring = true) or hasContentDescription(expect, substring = true))
                .fetchSemanticsNodes().isNotEmpty()
        }
    }

    private fun switch(label: String) = compose.onNode(hasText(label) and hasClickAction())

    private fun saved(form: String) = try {
        compose.waitUntil(15_000) { server.requestsTo("preferences.php").any { it.body.optString("form") == form } }
    } catch (e: Throwable) {
        throw AssertionError("no $form; sent: " + server.requestsTo("preferences.php").map { it.body }, e)
    }

    @Test
    fun `Grotere tekst - saved on the account, all text larger at once, Nu actief says so, kept for the next start, and off again`() {
        MemoryTokenStorage.signedIn()
        show()
        open("Voorkeuren", "Grotere tekst")

        val heading = compose.onNode(hasText("Toegankelijkheid") and isHeading())
        // Its laid-out height, wherever the screen is scrolled to.
        val before = heading.fetchSemanticsNode().size.height
        assertTrue(before > 0)
        switch("Grotere tekst").performScrollTo().assertIsOff()
        // Nu actief: the phone decides.
        assertTrue(compose.onAllNodesWithText("Volgt je systeem").fetchSemanticsNodes().size >= 2)

        switch("Grotere tekst").performSemanticsAction(SemanticsActions.OnClick)
        saved("text_large=1")
        assertEquals("Bearer $ACCOUNT_TOKEN", server.requestsTo("preferences.php").last().headers["authorization"])
        compose.waitUntil(10_000) { OwnifyPreferences.textLarge }
        compose.waitForIdle()
        switch("Grotere tekst").assertIsOn()
        waitFor("Alle tekst is groter, op elk apparaat.")
        // Nu actief follows: Groter, with what turned it on.
        waitFor("Groter")
        waitFor("Grotere tekst staat hieronder aan")
        val after = heading.fetchSemanticsNode().size.height
        assertTrue("the text grew: $before → $after", after > before * 1.08f)

        // The next start opens in it, before the first read.
        OwnifyPreferences.use()
        OwnifyPreferences.restore(context)
        assertTrue(OwnifyPreferences.textLarge)

        // Off again: saved, and the text back.
        compose.waitUntil(15_000) { server.requestsTo("state.php").size >= 2 }
        switch("Grotere tekst").performSemanticsAction(SemanticsActions.OnClick)
        saved("text_large=0")
        compose.waitUntil(10_000) { !OwnifyPreferences.textLarge }
        compose.waitForIdle()
        switch("Grotere tekst").assertIsOff()
        assertEquals(before, heading.fetchSemanticsNode().size.height)
    }

    @Test
    fun `Extra contrast and Animaties uit - saved, and the app follows at once, whatever the phone says`() {
        MemoryTokenStorage.signedIn()
        show()
        open("Voorkeuren", "Grotere tekst")
        assertFalse(Ownify.moreContrast)

        switch("Extra contrast").performScrollTo().performSemanticsAction(SemanticsActions.OnClick)
        saved("contrast_more=1")
        compose.waitUntil(10_000) { Ownify.moreContrast }
        waitFor("Extra contrast staat hieronder aan")

        switch("Animaties uit").performScrollTo().performSemanticsAction(SemanticsActions.OnClick)
        saved("motion_off=1")
        compose.waitUntil(10_000) { OwnifyPreferences.motionOff }
        // Minder beweging: Altijd, whatever the phone says.
        waitFor("Altijd")
        waitFor("Animaties uit staat hieronder aan")

        // Read back in the pages: still on.
        compose.waitUntil(15_000) { server.requestsTo("state.php").size >= 3 }
        compose.waitForIdle()
        switch("Extra contrast").assertIsOn()
        switch("Animaties uit").assertIsOn()
        assertTrue(Ownify.moreContrast)
    }

    @Test
    fun `Eerste dag van de week - Zondag is saved on the account and stays ticked, and the week then starts on Sunday`() {
        MemoryTokenStorage.signedIn()
        show()
        open("Voorkeuren", "Zondag")
        // Kept on the account: no "not kept" line on this screen.
        gone("wordt nog niet bewaard")

        button("Zondag").performScrollTo().performSemanticsAction(SemanticsActions.OnClick)
        saved("week=sunday")
        compose.waitUntil(10_000) { OwnifyPreferences.sundayFirst }
        compose.waitUntil(15_000) { server.requestsTo("state.php").size >= 2 }
        compose.waitForIdle()
        compose.onNode(hasText("Zondag") and hasClickAction()).assertIsSelected()

        // Where a week begins: the goal calendar's initials, and the first day of a week.
        assertEquals(listOf("Z", "M", "D", "W", "D", "V", "Z"), OwnifyPreferences.weekInitials())
        assertEquals(LocalDate.of(2026, 10, 11), OwnifyPreferences.weekFirstDay(LocalDate.of(2026, 10, 15)))
        OwnifyPreferences.use(weekStart = "monday")
        assertEquals(listOf("M", "D", "W", "D", "V", "Z", "Z"), OwnifyPreferences.weekInitials())
        assertEquals(LocalDate.of(2026, 10, 12), OwnifyPreferences.weekFirstDay(LocalDate.of(2026, 10, 15)))
    }

    @Test
    fun `Apparaten - disconnecting Polar keeps you on Apparaten and Gezondheid, the card updated in place`() {
        server.connectPolar()
        MemoryTokenStorage.signedIn()
        show()
        open("Apparaten & Gezondheid", "Polar")

        fun polar() = compose.onNode(hasContentDescription("Polar", substring = true) and hasClickAction())
        polar().performScrollTo().performSemanticsAction(SemanticsActions.OnClick)
        waitFor("Ontkoppelen")
        val reads = server.requestsTo("state.php").size
        button("Ontkoppelen").performScrollTo().performClick()

        compose.waitUntil(15_000) { server.requestsTo("disconnect.php").isNotEmpty() }
        assertEquals("provider=polar", server.requestsTo("disconnect.php").single().body.optString("form"))
        compose.waitUntil(15_000) { server.requestsTo("state.php").size > reads }
        compose.waitUntil(15_000) {
            ((OwnifyAppState.load as? com.ownify.android.data.AppLoad.Ready)?.data?.settings?.integrations
                ?.firstOrNull { it.provider == "polar" }?.connected) == false
        }
        compose.waitForIdle()
        // Still on Apparaten & Gezondheid, Polar's card there and open, now offering Koppelen.
        polar().assertIsDisplayed()
        waitFor("Koppelen")
        button("Koppelen").assertIsDisplayed()
    }

    @Test
    fun `Apparaten - no Nu synchroniseren, Smartwatch or Slaapring, and a phone given a name of its own, then its own again`() {
        MemoryTokenStorage.signedIn()
        show()
        open("Apparaten & Gezondheid", "Health Connect")
        gone("Smartwatch")
        gone("Slaapring")

        compose.onNode(hasContentDescription("Health Connect", substring = true) and hasClickAction())
            .performScrollTo()
            .performSemanticsAction(SemanticsActions.OnClick)
        waitFor("Demo Pixel")
        gone("Nu synchroniseren")

        compose.onNode(hasContentDescription("Naam van Demo Pixel wijzigen") and hasClickAction())
            .performScrollTo()
            .performSemanticsAction(SemanticsActions.OnClick)
        waitFor("Leeg laten geeft het de eigen naam terug: Demo Pixel")
        val field = compose.onNode(hasContentDescription("Apparaatnaam") and hasSetTextAction())
        field.performTextReplacement("Werktelefoon")
        button("Opslaan").performClick()

        compose.waitUntil(15_000) { server.requestsTo("device-rename.php").isNotEmpty() }
        assertEquals("device=392&name=Werktelefoon", server.requestsTo("device-rename.php").single().body.optString("form"))
        // Read again: the pages carry the new name, and the phone is listed by it (not the editor's text: it has closed).
        fun phone() = ((OwnifyAppState.load as? com.ownify.android.data.AppLoad.Ready)?.data?.settings?.integrations
            ?.single { it.key == "health_connect" })?.devices?.single()
        compose.waitUntil(15_000) { phone()?.label == "Werktelefoon" }
        assertEquals("Demo Pixel", phone()?.own)
        compose.waitUntil(15_000) { compose.onAllNodes(hasContentDescription("Apparaatnaam") and hasSetTextAction()).fetchSemanticsNodes().isEmpty() }
        waitFor("Werktelefoon")

        // Empty gives it its own name back.
        compose.onNode(hasContentDescription("Naam van Werktelefoon wijzigen") and hasClickAction())
            .performScrollTo()
            .performSemanticsAction(SemanticsActions.OnClick)
        waitFor("Apparaatnaam")
        compose.onNode(hasContentDescription("Apparaatnaam") and hasSetTextAction()).performTextReplacement("")
        button("Opslaan").performClick()
        compose.waitUntil(15_000) { server.requestsTo("device-rename.php").size == 2 }
        assertEquals("device=392&name=", server.requestsTo("device-rename.php").last().body.optString("form"))
        compose.waitUntil(15_000) { compose.onAllNodesWithText("Werktelefoon", substring = true).fetchSemanticsNodes().isEmpty() }
    }
}
