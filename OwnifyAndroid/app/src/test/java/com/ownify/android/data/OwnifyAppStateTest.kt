package com.ownify.android.data

import android.content.Context
import androidx.test.core.app.ApplicationProvider
import androidx.work.Configuration
import androidx.work.testing.SynchronousExecutor
import androidx.work.testing.WorkManagerTestInitHelper
import com.ownify.android.connection.ACCOUNT_TOKEN
import com.ownify.android.connection.FakeOwnifyServer
import com.ownify.android.connection.OwnifyApi
import com.ownify.android.connection.OwnifyConnection
import com.ownify.android.connection.OwnifyCredential
import com.ownify.android.connection.OwnifyScope
import com.ownify.android.connection.OwnifyState
import com.ownify.android.connection.OwnifySyncRunner
import com.ownify.android.connection.OwnifySyncStatusPrefs
import com.ownify.android.connection.MemoryTokenStorage
import com.ownify.android.connection.TEST_TOKEN
import com.ownify.android.connection.TestSyncEnvironment
import com.ownify.android.connection.waitUntil
import java.util.concurrent.CountDownLatch
import java.util.concurrent.TimeUnit
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.async
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/**
 * The signed-in app's read (api/app/state.php) and its writes, against a
 * server on localhost: what each answer does to the session. The rule is
 * the sync's: a 401 forgets the credential only if it is still the token
 * that was refused, so an answer about a token that signing in has already
 * replaced never signs the new session out.
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [36])
class OwnifyAppStateTest {

    private lateinit var context: Context
    private lateinit var server: FakeOwnifyServer

    /** A session from before, still stored while the phone signs in again. */
    private val oldToken = "a".repeat(64)

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
        OwnifySyncStatusPrefs(context).clear()
    }

    @After
    fun tearDown() {
        server.holdGate.countDown()
        server.stop()
        OwnifyAppState.clear()
    }

    private fun readPages() {
        OwnifyAppState.refresh(context)
        waitUntil("the read to end") { OwnifyAppState.load !is AppLoad.Loading && !(OwnifyAppState.load as? AppLoad.Ready)?.refreshing.orFalse() }
    }

    private fun Boolean?.orFalse() = this ?: false

    @Test
    fun `an account token reads the pages - one read, with the Bearer token only`() {
        MemoryTokenStorage.signedIn()

        readPages()

        val load = OwnifyAppState.load as AppLoad.Ready
        assertEquals("sanne_7001", load.data.auth.username)
        val read = server.requestsTo("state.php").single()
        assertEquals("Bearer $ACCOUNT_TOKEN", read.headers["authorization"])
        assertTrue(read.body.length() == 0)
    }

    @Test
    fun `a paired phone's sync token may not read the account - 403, and nothing is forgotten`() {
        MemoryTokenStorage.token = TEST_TOKEN

        readPages()

        assertEquals(AppLoad.SyncOnly, OwnifyAppState.load)
        assertEquals(OwnifyCredential(TEST_TOKEN, OwnifyScope.SYNC), MemoryTokenStorage.load())
    }

    @Test
    fun `offline - the read fails with the reason, the session stays, and the last pages stay on screen`() {
        MemoryTokenStorage.signedIn()
        readPages()
        val before = OwnifyAppState.data

        OwnifyConnection.api = OwnifyApi("http://127.0.0.1:9/")
        readPages()

        val load = OwnifyAppState.load as AppLoad.Failed
        assertEquals(OwnifyAppState.UNREACHABLE, load.message)
        assertEquals(before, load.data)
        assertEquals(OwnifyCredential(ACCOUNT_TOKEN, OwnifyScope.ACCOUNT), MemoryTokenStorage.load())
    }

    @Test
    fun `revoked on the website - the read gets 401 for the stored token, which is forgotten and the session ends`() {
        MemoryTokenStorage.signedIn()
        server.revoked += ACCOUNT_TOKEN

        readPages()

        assertEquals(AppLoad.Idle, OwnifyAppState.load)
        assertNull(MemoryTokenStorage.load())
        assertTrue(OwnifyConnection.state is OwnifyState.NotConnected)
    }

    @Test
    fun `a stale 401 on the read - the phone signed in again meanwhile - keeps the new session and reads again with it`() {
        MemoryTokenStorage.signedIn(oldToken)
        server.hold = "state.php"

        OwnifyAppState.refresh(context)
        waitUntil("the old read to arrive") { server.held.count == 0L }

        // While that read is on its way: signed in again, the old session revoked by the server.
        OwnifyConnection.reset()
        MemoryTokenStorage.signedIn(ACCOUNT_TOKEN)
        server.revoked += oldToken
        server.hold = null
        server.holdGate.countDown()

        waitUntil("the read to end") { OwnifyAppState.load is AppLoad.Ready }

        assertEquals(OwnifyCredential(ACCOUNT_TOKEN, OwnifyScope.ACCOUNT), MemoryTokenStorage.load())
        val reads = server.requestsTo("state.php").map { it.headers["authorization"] }
        assertEquals(listOf("Bearer $oldToken", "Bearer $ACCOUNT_TOKEN"), reads)
    }

    @Test
    fun `a write - done with the account token, then the pages are read again`() {
        MemoryTokenStorage.signedIn()

        val outcome = blocking { OwnifyActions.form(context, "api/health/rating.php", mapOf("rating" to "8"), "Er ging iets mis.") }

        assertTrue(outcome is Outcome.Done)
        val write = server.requestsTo("rating.php").single()
        assertEquals("Bearer $ACCOUNT_TOKEN", write.headers["authorization"])
        assertEquals("rating=8", write.body.getString("form"))
        assertTrue("the pages read again after the write", server.requestsTo("state.php").isNotEmpty())
    }

    @Test
    fun `a write refused with 401 for the stored token - signed out, nothing more sent`() {
        MemoryTokenStorage.signedIn()
        server.revoked += ACCOUNT_TOKEN

        val outcome = blocking { OwnifyActions.form(context, "api/health/rating.php", mapOf("rating" to "8"), "Er ging iets mis.") }

        assertEquals(Outcome.SignedOut, outcome)
        assertNull(MemoryTokenStorage.load())
        assertEquals(1, server.requestsTo("rating.php").size)
    }

    @Test
    fun `a stale 401 on a write - replaced meanwhile - is sent once more with the new token, and the session stays`() {
        MemoryTokenStorage.signedIn(oldToken)
        server.hold = "rating.php"

        val write = CoroutineScope(Dispatchers.IO).async {
            OwnifyActions.form(context, "api/health/rating.php", mapOf("rating" to "8"), "Er ging iets mis.", reload = false)
        }
        assertTrue("the old write to arrive", server.held.await(10, TimeUnit.SECONDS))

        MemoryTokenStorage.signedIn(ACCOUNT_TOKEN)
        server.revoked += oldToken
        server.hold = null
        server.holdGate.countDown()

        waitUntil("the write to end") { write.isCompleted }
        assertTrue(write.getCompleted() is Outcome.Done)
        assertEquals(OwnifyCredential(ACCOUNT_TOKEN, OwnifyScope.ACCOUNT), MemoryTokenStorage.load())
        assertEquals(listOf("Bearer $oldToken", "Bearer $ACCOUNT_TOKEN"), server.requestsTo("rating.php").map { it.headers["authorization"] })
    }

    @Test
    fun `signing out clears the pages from memory`() {
        MemoryTokenStorage.signedIn()
        readPages()
        assertTrue(OwnifyAppState.data != null)

        OwnifyAppState.clear()

        assertNull(OwnifyAppState.data)
        assertEquals(AppLoad.Idle, OwnifyAppState.load)
    }

    /** Runs [block] off the main thread and lets the main looper turn until it is done. */
    private fun <T> blocking(block: suspend () -> T): T {
        val done = CountDownLatch(1)
        val job = CoroutineScope(Dispatchers.IO).async { block().also { done.countDown() } }
        waitUntil("the call to end") { done.count == 0L }
        @Suppress("OPT_IN_USAGE")
        return job.getCompleted()
    }
}
