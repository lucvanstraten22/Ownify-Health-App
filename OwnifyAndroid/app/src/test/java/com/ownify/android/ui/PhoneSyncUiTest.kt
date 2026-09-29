package com.ownify.android.ui

import android.content.Context
import androidx.compose.foundation.layout.Column
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.unit.dp
import androidx.health.connect.client.HealthConnectClient
import androidx.test.core.app.ApplicationProvider
import androidx.work.Configuration
import androidx.work.testing.SynchronousExecutor
import androidx.work.testing.WorkManagerTestInitHelper
import com.ownify.android.connection.BackgroundRead
import com.ownify.android.connection.FakeOwnifyServer
import com.ownify.android.connection.HealthAccess
import com.ownify.android.connection.IngestPayload
import com.ownify.android.connection.OwnifyApi
import com.ownify.android.connection.OwnifyConnection
import com.ownify.android.connection.OwnifyCredential
import com.ownify.android.connection.OwnifyScope
import com.ownify.android.connection.OwnifyState
import com.ownify.android.connection.OwnifySync
import com.ownify.android.connection.OwnifySyncRunner
import com.ownify.android.connection.OwnifySyncState
import com.ownify.android.connection.OwnifySyncStatusPrefs
import com.ownify.android.connection.MemoryTokenStorage
import com.ownify.android.connection.SyncFixtures
import com.ownify.android.connection.TEST_TOKEN
import com.ownify.android.connection.TestSyncEnvironment
import com.ownify.android.connection.waitUntil
import com.ownify.android.ui.design.LocalScreen
import com.ownify.android.ui.design.LocalStillMotion
import com.ownify.android.ui.design.ScreenMetrics
import com.ownify.android.ui.screens.settings.HealthPermissions
import com.ownify.android.ui.screens.settings.PhoneHealth
import com.ownify.android.ui.screens.settings.PhoneSection
import com.ownify.android.ui.screens.settings.SyncNowButton
import com.ownify.android.ui.screens.settings.SyncResult
import com.ownify.android.ui.theme.OwnifyTheme
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
 * This phone's Health Connect section (Instellingen › Apparaten &
 * Gezondheid, and the paired phone's opening screen): what it says for each
 * state of Health Connect and its permissions, what it offers to fix, and
 * "Nu synchroniseren" through the real sync pipeline — the same one the
 * automatic sync runs — against a server on localhost.
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [36], qualifiers = "w412dp-h915dp-port")
class PhoneSyncUiTest {

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
        TestSyncEnvironment.access = HealthAccess.AVAILABLE
        TestSyncEnvironment.records = emptyList()
        OwnifySyncRunner.environment = { TestSyncEnvironment }
        MemoryTokenStorage.clear()
        OwnifyConnection.reset()
        OwnifySyncStatusPrefs(context).clear()
        OwnifySync.forget(context)
    }

    @After
    fun tearDown() {
        server.stop()
        OwnifySync.forget(context)
    }

    private fun health(sdk: Int, granted: Int = 0, background: BackgroundRead? = null) =
        PhoneHealth().apply {
            this.sdk = sdk
            this.granted = granted
            this.background = background
            known = true
        }

    private fun show(health: PhoneHealth) = compose.setContent {
        OwnifyTheme {
            CompositionLocalProvider(LocalStillMotion provides true, LocalScreen provides ScreenMetrics(412.dp, 915.dp)) {
                Column {
                    PhoneSection(health)
                    SyncNowButton()
                    SyncResult()
                }
            }
        }
    }

    private fun shows(text: String) = compose.onNodeWithText(text, substring = true).assertExists()

    private fun offers(label: String, yes: Boolean) =
        compose.onAllNodes(hasText(label) and hasClickAction()).assertCountEquals(if (yes) 1 else 0)

    // ------------------------------------------------------------ the states

    @Test
    fun `no Health Connect - it says so and offers to install it, nothing else`() {
        show(health(HealthConnectClient.SDK_UNAVAILABLE))

        shows("Niet beschikbaar")
        offers("Health Connect installeren", true)
        offers("Toegang geven", false)
        offers("Op de achtergrond toestaan", false)
    }

    @Test
    fun `Health Connect needing an update - it says so and offers the update`() {
        show(health(HealthConnectClient.SDK_UNAVAILABLE_PROVIDER_UPDATE_REQUIRED))

        shows("Moet worden bijgewerkt")
        offers("Health Connect bijwerken", true)
    }

    @Test
    fun `no read access - asks for it, and not yet for the background`() {
        show(health(HealthConnectClient.SDK_AVAILABLE, granted = 0, background = BackgroundRead.NOT_GRANTED))

        shows("Beschikbaar")
        shows("Geen toegang")
        offers("Toegang geven", true)
        offers("Op de achtergrond toestaan", false)
    }

    @Test
    fun `part of the read access - how much, and asks for the rest`() {
        show(health(HealthConnectClient.SDK_AVAILABLE, granted = 3, background = BackgroundRead.NOT_GRANTED))

        shows("3 van ${IngestPayload.TYPES.size} soorten")
        offers("Toegang geven", true)
        offers("Op de achtergrond toestaan", true)
    }

    @Test
    fun `all read access, not in the background - asks for the background only`() {
        show(health(HealthConnectClient.SDK_AVAILABLE, granted = IngestPayload.TYPES.size, background = BackgroundRead.NOT_GRANTED))

        shows("Alle gegevens")
        shows("Niet toegestaan")
        offers("Toegang geven", false)
        offers("Op de achtergrond toestaan", true)
    }

    @Test
    fun `everything allowed - nothing to ask for`() {
        show(health(HealthConnectClient.SDK_AVAILABLE, granted = IngestPayload.TYPES.size, background = BackgroundRead.GRANTED))

        shows("Alle gegevens")
        shows("Toegestaan")
        offers("Toegang geven", false)
        offers("Op de achtergrond toestaan", false)
        offers("Health Connect installeren", false)
    }

    @Test
    fun `the access asked for is one read permission per record type the sync sends, and only reads`() {
        assertEquals(IngestPayload.TYPES.size, HealthPermissions.size)
        assertTrue(HealthPermissions.all { it.startsWith("android.permission.health.READ_") })
    }

    // ----------------------------------------------------------- syncing now

    @Test
    fun `Nu synchroniseren - the day's records sent with the Bearer token, the result and the time shown`() {
        MemoryTokenStorage.token = TEST_TOKEN
        TestSyncEnvironment.records = SyncFixtures.day("a")
        show(health(HealthConnectClient.SDK_AVAILABLE, granted = IngestPayload.TYPES.size, background = BackgroundRead.GRANTED))

        shows("Nog nooit")
        compose.onNode(hasText("Nu synchroniseren") and hasClickAction()).performClick()

        // The sync's steps come back on the main thread: turn its looper while waiting.
        waitUntil("the sync to end") { OwnifySync.state is OwnifySyncState.Synced }
        compose.waitForIdle()
        shows("Gesynchroniseerd:")
        shows("Vandaag, ")
        val ingest = server.ingests.single()
        assertEquals("Bearer $TEST_TOKEN", ingest.headers["authorization"])
        assertTrue(ingest.body.getJSONArray("records").length() > 0)
    }

    @Test
    fun `a revoked pairing - the sync's 401 forgets it, the connection asks for a new code, and the status says it expired`() {
        MemoryTokenStorage.token = TEST_TOKEN
        server.revoked += TEST_TOKEN
        TestSyncEnvironment.records = SyncFixtures.day("a")
        show(health(HealthConnectClient.SDK_AVAILABLE, granted = IngestPayload.TYPES.size, background = BackgroundRead.GRANTED))

        compose.onNode(hasText("Nu synchroniseren") and hasClickAction()).performClick()

        waitUntil("the pairing to be forgotten") { MemoryTokenStorage.load() == null && OwnifySync.state !is OwnifySyncState.Syncing }
        compose.waitForIdle()
        assertTrue(OwnifyConnection.state is OwnifyState.NotConnected)
        // The button's line leaves it to the connection, as the sync always has.
        assertEquals(OwnifySyncState.Idle, OwnifySync.state)
        shows("De koppeling met Ownify is verlopen")
    }

    @Test
    fun `offline - the sync says Ownify could not be reached, and the pairing is kept`() {
        MemoryTokenStorage.token = TEST_TOKEN
        TestSyncEnvironment.records = SyncFixtures.day("a")
        TestSyncEnvironment.api = OwnifyApi("http://127.0.0.1:9/")
        show(health(HealthConnectClient.SDK_AVAILABLE, granted = IngestPayload.TYPES.size, background = BackgroundRead.GRANTED))

        compose.onNode(hasText("Nu synchroniseren") and hasClickAction()).performClick()

        waitUntil("the sync to end") { OwnifySync.state is OwnifySyncState.Failed }
        compose.waitForIdle()
        shows((OwnifySync.state as OwnifySyncState.Failed).message)
        assertEquals(OwnifyCredential(TEST_TOKEN, OwnifyScope.SYNC), MemoryTokenStorage.load())
        assertTrue(compose.onAllNodesWithText("bereikbaar", substring = true).fetchSemanticsNodes().isNotEmpty())
    }
}
