package com.healthapp.android.data

import android.content.Context
import androidx.test.core.app.ApplicationProvider
import androidx.work.Configuration
import androidx.work.testing.SynchronousExecutor
import androidx.work.testing.WorkManagerTestInitHelper
import com.healthapp.android.jolu.ACCOUNT_TOKEN
import com.healthapp.android.jolu.FakeJoluServer
import com.healthapp.android.jolu.JoluApi
import com.healthapp.android.jolu.JoluConnection
import com.healthapp.android.jolu.JoluCredential
import com.healthapp.android.jolu.JoluScope
import com.healthapp.android.jolu.JoluState
import com.healthapp.android.jolu.JoluSyncRunner
import com.healthapp.android.jolu.JoluSyncStatusPrefs
import com.healthapp.android.jolu.MemoryTokenStorage
import com.healthapp.android.jolu.TEST_TOKEN
import com.healthapp.android.jolu.TestSyncEnvironment
import com.healthapp.android.jolu.waitUntil
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
class JoluAppStateTest {

    private lateinit var context: Context
    private lateinit var server: FakeJoluServer

    /** A session from before, still stored while the phone signs in again. */
    private val oldToken = "a".repeat(64)

    @Before
    fun setUp() {
        context = ApplicationProvider.getApplicationContext()
        WorkManagerTestInitHelper.initializeTestWorkManager(context, Configuration.Builder().setExecutor(SynchronousExecutor()).build())
        server = FakeJoluServer()
        JoluConnection.api = JoluApi(server.url)
        JoluConnection.storage = { MemoryTokenStorage }
        TestSyncEnvironment.context = context
        TestSyncEnvironment.api = JoluApi(server.url)
        JoluSyncRunner.environment = { TestSyncEnvironment }
        MemoryTokenStorage.clear()
        JoluConnection.reset()
        JoluAppState.clear()
        JoluSyncStatusPrefs(context).clear()
    }

    @After
    fun tearDown() {
        server.holdGate.countDown()
        server.stop()
        JoluAppState.clear()
    }

    private fun readPages() {
        JoluAppState.refresh(context)
        waitUntil("the read to end") { JoluAppState.load !is AppLoad.Loading && !(JoluAppState.load as? AppLoad.Ready)?.refreshing.orFalse() }
    }

    private fun Boolean?.orFalse() = this ?: false

    @Test
    fun `an account token reads the pages - one read, with the Bearer token only`() {
        MemoryTokenStorage.signedIn()

        readPages()

        val load = JoluAppState.load as AppLoad.Ready
        assertEquals("sanne_7001", load.data.auth.username)
        val read = server.requestsTo("state.php").single()
        assertEquals("Bearer $ACCOUNT_TOKEN", read.headers["authorization"])
        assertTrue(read.body.length() == 0)
    }

    @Test
    fun `a paired phone's sync token may not read the account - 403, and nothing is forgotten`() {
        MemoryTokenStorage.token = TEST_TOKEN

        readPages()

        assertEquals(AppLoad.SyncOnly, JoluAppState.load)
        assertEquals(JoluCredential(TEST_TOKEN, JoluScope.SYNC), MemoryTokenStorage.load())
    }

    @Test
    fun `offline - the read fails with the reason, the session stays, and the last pages stay on screen`() {
        MemoryTokenStorage.signedIn()
        readPages()
        val before = JoluAppState.data

        JoluConnection.api = JoluApi("http://127.0.0.1:9/")
        readPages()

        val load = JoluAppState.load as AppLoad.Failed
        assertEquals(JoluAppState.UNREACHABLE, load.message)
        assertEquals(before, load.data)
        assertEquals(JoluCredential(ACCOUNT_TOKEN, JoluScope.ACCOUNT), MemoryTokenStorage.load())
    }

    @Test
    fun `revoked on the website - the read gets 401 for the stored token, which is forgotten and the session ends`() {
        MemoryTokenStorage.signedIn()
        server.revoked += ACCOUNT_TOKEN

        readPages()

        assertEquals(AppLoad.Idle, JoluAppState.load)
        assertNull(MemoryTokenStorage.load())
        assertTrue(JoluConnection.state is JoluState.NotConnected)
    }

    @Test
    fun `a stale 401 on the read - the phone signed in again meanwhile - keeps the new session and reads again with it`() {
        MemoryTokenStorage.signedIn(oldToken)
        server.hold = "state.php"

        JoluAppState.refresh(context)
        waitUntil("the old read to arrive") { server.held.count == 0L }

        // While that read is on its way: signed in again, the old session revoked by the server.
        JoluConnection.reset()
        MemoryTokenStorage.signedIn(ACCOUNT_TOKEN)
        server.revoked += oldToken
        server.hold = null
        server.holdGate.countDown()

        waitUntil("the read to end") { JoluAppState.load is AppLoad.Ready }

        assertEquals(JoluCredential(ACCOUNT_TOKEN, JoluScope.ACCOUNT), MemoryTokenStorage.load())
        val reads = server.requestsTo("state.php").map { it.headers["authorization"] }
        assertEquals(listOf("Bearer $oldToken", "Bearer $ACCOUNT_TOKEN"), reads)
    }

    @Test
    fun `a write - done with the account token, then the pages are read again`() {
        MemoryTokenStorage.signedIn()

        val outcome = blocking { JoluActions.form(context, "api/health/rating.php", mapOf("rating" to "8"), "Er ging iets mis.") }

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

        val outcome = blocking { JoluActions.form(context, "api/health/rating.php", mapOf("rating" to "8"), "Er ging iets mis.") }

        assertEquals(Outcome.SignedOut, outcome)
        assertNull(MemoryTokenStorage.load())
        assertEquals(1, server.requestsTo("rating.php").size)
    }

    @Test
    fun `a stale 401 on a write - replaced meanwhile - is sent once more with the new token, and the session stays`() {
        MemoryTokenStorage.signedIn(oldToken)
        server.hold = "rating.php"

        val write = CoroutineScope(Dispatchers.IO).async {
            JoluActions.form(context, "api/health/rating.php", mapOf("rating" to "8"), "Er ging iets mis.", reload = false)
        }
        assertTrue("the old write to arrive", server.held.await(10, TimeUnit.SECONDS))

        MemoryTokenStorage.signedIn(ACCOUNT_TOKEN)
        server.revoked += oldToken
        server.hold = null
        server.holdGate.countDown()

        waitUntil("the write to end") { write.isCompleted }
        assertTrue(write.getCompleted() is Outcome.Done)
        assertEquals(JoluCredential(ACCOUNT_TOKEN, JoluScope.ACCOUNT), MemoryTokenStorage.load())
        assertEquals(listOf("Bearer $oldToken", "Bearer $ACCOUNT_TOKEN"), server.requestsTo("rating.php").map { it.headers["authorization"] })
    }

    @Test
    fun `signing out clears the pages from memory`() {
        MemoryTokenStorage.signedIn()
        readPages()
        assertTrue(JoluAppState.data != null)

        JoluAppState.clear()

        assertNull(JoluAppState.data)
        assertEquals(AppLoad.Idle, JoluAppState.load)
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
