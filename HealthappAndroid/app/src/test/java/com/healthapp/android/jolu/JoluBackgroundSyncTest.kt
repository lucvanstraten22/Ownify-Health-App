package com.healthapp.android.jolu

import android.content.Context
import androidx.test.core.app.ApplicationProvider
import androidx.work.BackoffPolicy
import androidx.work.Configuration
import androidx.work.ListenableWorker
import androidx.work.NetworkType
import androidx.work.WorkInfo
import androidx.work.WorkManager
import androidx.work.testing.SynchronousExecutor
import androidx.work.testing.TestListenableWorkerBuilder
import androidx.work.testing.WorkManagerTestInitHelper
import java.util.UUID
import java.util.concurrent.TimeUnit
import kotlinx.coroutines.runBlocking
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/**
 * The automatic sync, run the way the phone runs it: WorkManager (its test
 * driver standing in for time, network and battery) schedules the real
 * JoluSyncWorker, which runs the real pipeline — JoluSync, JoluSyncRunner,
 * IngestPayload, JoluApi over HTTP — against a JoLu server on localhost, with
 * the real JoluConnection handling the token and the real status file.
 *
 * Only Health Connect (TestSyncEnvironment) and the Keystore
 * (MemoryTokenStorage) are stand-ins: the JVM has neither.
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [36])
class JoluBackgroundSyncTest {

    private lateinit var context: Context
    private lateinit var server: FakeJoluServer
    private lateinit var work: WorkManager

    private val driver get() = WorkManagerTestInitHelper.getTestDriver(context)!!

    @Before
    fun setUp() {
        context = ApplicationProvider.getApplicationContext()
        WorkManagerTestInitHelper.initializeTestWorkManager(
            context,
            Configuration.Builder().setExecutor(SynchronousExecutor()).build()
        )
        work = WorkManager.getInstance(context)

        server = FakeJoluServer()

        JoluConnection.api = JoluApi(server.url)
        JoluConnection.storage = { MemoryTokenStorage }
        TestSyncEnvironment.context = context
        TestSyncEnvironment.api = JoluApi(server.url)
        TestSyncEnvironment.access = HealthAccess.AVAILABLE
        TestSyncEnvironment.records = SyncFixtures.day("bg")
        TestSyncEnvironment.reads = 0
        JoluSyncRunner.environment = { TestSyncEnvironment }

        MemoryTokenStorage.token = null
        JoluSyncStatusPrefs(context).clear()
    }

    @After
    fun tearDown() {
        work.cancelAllWork()
        server.stop()
    }

    // ------------------------------------------------------------ helpers

    private fun paired() {
        MemoryTokenStorage.token = TEST_TOKEN
    }

    private fun info(name: String): WorkInfo? = work.getWorkInfosForUniqueWork(name).get().singleOrNull()

    private fun periodicId(): UUID = info(JoluBackgroundSync.PERIODIC_WORK)!!.id

    /** One scheduled run: its hour has come and it has a network and battery. */
    private fun runScheduledOnce() {
        val id = periodicId()
        val attempts = JoluSyncStatusPrefs(context).read().lastAttemptAt
        driver.setPeriodDelayMet(id)
        driver.setAllConstraintsMet(id)
        waitUntil("the scheduled run finished") {
            JoluSyncStatusPrefs(context).read().lastAttemptAt != attempts &&
                info(JoluBackgroundSync.PERIODIC_WORK)?.state != WorkInfo.State.RUNNING
        }
    }

    private fun runWorker(attempt: Int = 0): ListenableWorker.Result = runBlocking {
        TestListenableWorkerBuilder<JoluSyncWorker>(context).setRunAttemptCount(attempt).build().doWork()
    }

    private fun status() = JoluSyncStatusPrefs(context).read()

    // ------------------------------------------------------- the schedule

    @Test
    fun `the schedule - hourly, only on a network and a battery that is not low, backing off from 10 minutes`() {
        val spec = JoluBackgroundSync.periodicRequest().workSpec
        assertEquals(TimeUnit.HOURS.toMillis(1), spec.intervalDuration)
        assertEquals(TimeUnit.MINUTES.toMillis(20), spec.flexDuration)
        assertEquals(NetworkType.CONNECTED, spec.constraints.requiredNetworkType)
        assertTrue(spec.constraints.requiresBatteryNotLow())
        assertFalse("no charging needed", spec.constraints.requiresCharging())
        assertEquals(BackoffPolicy.EXPONENTIAL, spec.backoffPolicy)
        assertEquals(TimeUnit.MINUTES.toMillis(10), spec.backoffDelayDuration)

        val soon = JoluBackgroundSync.soonRequest().workSpec
        assertFalse("a sync asked for now is not periodic", soon.isPeriodic)
        assertEquals(NetworkType.CONNECTED, soon.constraints.requiredNetworkType)
        assertFalse(soon.constraints.requiresBatteryNotLow())

        assertTrue("HTTPS only", JoluApi.BASE_URL.startsWith("https://"))
    }

    @Test
    fun `scheduling twice keeps one schedule`() {
        paired()
        JoluBackgroundSync.schedule(context)
        JoluBackgroundSync.schedule(context)
        val infos = work.getWorkInfosForUniqueWork(JoluBackgroundSync.PERIODIC_WORK).get()
        assertEquals(1, infos.size)
        assertEquals(TimeUnit.HOURS.toMillis(1), infos.single().periodicityInfo!!.repeatIntervalMillis)
    }

    // ------------------------------------------------------ 1. it runs

    @Test
    fun `1 - paired, with Health Connect access - the scheduled run sends the last 7 days, with the Bearer token only`() {
        paired()
        JoluBackgroundSync.schedule(context)

        runScheduledOnce()

        val request = server.ingests.single()
        assertEquals("Bearer $TEST_TOKEN", request.headers["authorization"])
        assertEquals("only the records are sent", setOf("records"), request.body.keys().asSequence().toSet())
        assertFalse("no user id", request.body.toString().contains("user_id"))
        assertFalse("the token is never in the body", request.body.toString().contains(TEST_TOKEN))

        val ids = (0 until request.body.getJSONArray("records").length())
            .map { request.body.getJSONArray("records").getJSONObject(it).getJSONObject("metadata").getString("id") }
        assertTrue("Health Connect's own ids go along", ids.containsAll(listOf("bg-sleep", "bg-steps-1", "bg-lunch")))

        val record = status()
        assertEquals(JoluSyncOutcomeKind.SYNCED, record.lastOutcome)
        assertTrue(record.lastWasAutomatic)
        assertNotNull(record.lastSuccessAt)
        assertEquals(ids.size, record.lastWritten)
        assertEquals("still scheduled", WorkInfo.State.ENQUEUED, info(JoluBackgroundSync.PERIODIC_WORK)!!.state)
        assertTrue(JoluSync.state is JoluSyncState.Synced)
    }

    @Test
    fun `1b - without a network or with a low battery the scheduled run does not start`() {
        paired()
        JoluBackgroundSync.schedule(context)

        driver.setPeriodDelayMet(periodicId())   // its hour has come, but the constraints are not met
        Thread.sleep(300)

        assertTrue(server.requests.isEmpty())
        assertEquals(0, TestSyncEnvironment.reads)
        assertEquals(WorkInfo.State.ENQUEUED, info(JoluBackgroundSync.PERIODIC_WORK)!!.state)
    }

    // ---------------------------------------------------- 2. no token

    @Test
    fun `2 - no token - nothing is read or sent, and the schedule switches itself off`() {
        JoluBackgroundSync.schedule(context)

        runScheduledOnce()

        assertTrue(server.requests.isEmpty())
        assertEquals("Health Connect not even read", 0, TestSyncEnvironment.reads)
        assertEquals(WorkInfo.State.CANCELLED, info(JoluBackgroundSync.PERIODIC_WORK)!!.state)
        assertEquals(JoluSyncOutcomeKind.NOT_CONNECTED, status().lastOutcome)
    }

    // ---------------------------------------------------- 3. revoked

    @Test
    fun `3 - revoked token (401) - forgotten, automatic sync off, and never tried again`() {
        paired()
        server.ingestMode = FakeJoluServer.IngestMode.UNAUTHORIZED
        JoluBackgroundSync.schedule(context)

        runScheduledOnce()

        assertEquals(1, server.ingests.size)
        assertNull("the token is forgotten", MemoryTokenStorage.token)
        assertEquals(JoluState.NotConnected(JoluConnection.EXPIRED_MESSAGE), JoluConnection.state)
        assertEquals(WorkInfo.State.CANCELLED, info(JoluBackgroundSync.PERIODIC_WORK)!!.state)
        assertEquals(JoluSyncOutcomeKind.EXPIRED, status().lastOutcome)

        // Even run by hand, the worker does not call the server again.
        assertEquals(ListenableWorker.Result.success(), runWorker())
        assertEquals(1, server.ingests.size)
    }

    // ---------------------------------------------------- 4. offline

    @Test
    fun `4 - offline - retried with backoff, the token kept, and no "failed for good"`() {
        paired()
        TestSyncEnvironment.api = JoluApi("http://127.0.0.1:1/")   // nothing listens there

        assertEquals(ListenableWorker.Result.retry(), runWorker(attempt = 0))
        assertEquals(ListenableWorker.Result.retry(), runWorker(attempt = 2))
        assertEquals("after 3 retries it waits for the next hour", ListenableWorker.Result.success(), runWorker(attempt = 3))

        assertEquals(TEST_TOKEN, MemoryTokenStorage.token)
        val record = status()
        assertEquals(JoluSyncOutcomeKind.OFFLINE, record.lastOutcome)
        assertTrue(record.lastOutcome!!.label.contains("will try again automatically"))
        assertNull("never synced, and not pretending it did", record.lastSuccessAt)
    }

    @Test
    fun `4b - offline through WorkManager - the run is retried and the schedule stays`() {
        paired()
        TestSyncEnvironment.api = JoluApi("http://127.0.0.1:1/")
        JoluBackgroundSync.schedule(context)

        runScheduledOnce()

        val info = info(JoluBackgroundSync.PERIODIC_WORK)!!
        assertEquals(WorkInfo.State.ENQUEUED, info.state)
        assertEquals("a retry is waiting", 1, info.runAttemptCount)
        assertEquals(TEST_TOKEN, MemoryTokenStorage.token)
    }

    @Test
    fun `4c - the server in trouble (503) - retried, token kept`() {
        paired()
        server.ingestMode = FakeJoluServer.IngestMode.SERVER_ERROR

        assertEquals(ListenableWorker.Result.retry(), runWorker())
        assertEquals(TEST_TOKEN, MemoryTokenStorage.token)
        assertEquals(JoluSyncOutcomeKind.SERVER_TROUBLE, status().lastOutcome)
    }

    // ------------------------------------------ 5. no Health Connect access

    @Test
    fun `5 - no Health Connect, no permission, or no background access - nothing sent, no retry, schedule and token kept`() {
        paired()
        JoluBackgroundSync.schedule(context)

        val cases = mapOf(
            HealthAccess.NOT_INSTALLED to JoluSyncOutcomeKind.NO_HEALTH_CONNECT,
            HealthAccess.NO_PERMISSION to JoluSyncOutcomeKind.NEEDS_HEALTH_ACCESS,
            HealthAccess.BACKGROUND_NOT_ALLOWED to JoluSyncOutcomeKind.NEEDS_BACKGROUND_ACCESS
        )

        for ((access, kind) in cases) {
            TestSyncEnvironment.access = access
            assertEquals("$access", ListenableWorker.Result.success(), runWorker())
            assertEquals("$access", kind, status().lastOutcome)
        }

        assertTrue(server.requests.isEmpty())
        assertEquals(0, TestSyncEnvironment.reads)
        assertEquals(TEST_TOKEN, MemoryTokenStorage.token)
        assertEquals(WorkInfo.State.ENQUEUED, info(JoluBackgroundSync.PERIODIC_WORK)!!.state)
        assertEquals(
            "Health Connect access is required for automatic syncing",
            JoluSyncOutcomeKind.NEEDS_HEALTH_ACCESS.label
        )
    }

    // ------------------------------------------------- 6. repeated runs

    @Test
    fun `6 - repeated runs send the same records with the same ids - the server's idempotency does the rest`() {
        paired()
        JoluBackgroundSync.schedule(context)

        runScheduledOnce()
        runScheduledOnce()

        assertEquals(2, server.ingests.size)
        assertEquals(
            "identical payloads: nothing is deduplicated or dropped on the phone",
            server.ingests[0].body.toString(),
            server.ingests[1].body.toString()
        )
    }

    // ----------------------------------------------- 7. one pipeline

    @Test
    fun `7 - the button and the automatic sync run the same pipeline and send the same request`() {
        paired()

        JoluSync.sync(context)
        waitUntil("the button's sync") { JoluSync.state !is JoluSyncState.Syncing && server.ingests.size == 1 }
        assertFalse(status().lastWasAutomatic)
        val button = status().lastSuccessAt

        assertEquals(ListenableWorker.Result.success(), runWorker())

        assertEquals(2, server.ingests.size)
        assertEquals(2, TestSyncEnvironment.reads)
        assertEquals(server.ingests[0].body.toString(), server.ingests[1].body.toString())
        assertEquals(server.ingests[0].headers["authorization"], server.ingests[1].headers["authorization"])
        assertTrue(status().lastWasAutomatic)
        assertTrue(!status().lastSuccessAt!!.isBefore(button))
    }

    // ------------------------------------------------- 8. pairing

    @Test
    fun `8 - pairing stores the token, loads the profile, schedules the hourly sync and syncs at once`() {
        runBlocking { JoluConnection.unauthorized(context) }   // start from "not paired"

        JoluConnection.connect(context, "abcd-2345")
        waitUntil("connected") { JoluConnection.state is JoluState.Connected }

        assertEquals(TEST_TOKEN, MemoryTokenStorage.token)
        assertEquals(
            listOf("/api/integrations/pair.php", "/api/integrations/profile.php", "/api/integrations/nutrition-targets.php"),
            server.requests.map { it.path }
        )
        assertEquals(WorkInfo.State.ENQUEUED, info(JoluBackgroundSync.PERIODIC_WORK)!!.state)

        val soon = info(JoluBackgroundSync.SOON_WORK)!!
        driver.setAllConstraintsMet(soon.id)
        waitUntil("the first sync") { info(JoluBackgroundSync.SOON_WORK)?.state == WorkInfo.State.SUCCEEDED }

        assertEquals(1, server.ingests.size)
        assertEquals(JoluSyncOutcomeKind.SYNCED, status().lastOutcome)
    }

    @Test
    fun `8b - pairing without Health Connect access - stays paired, says access is needed, keeps the schedule`() {
        TestSyncEnvironment.access = HealthAccess.NO_PERMISSION
        runBlocking { JoluConnection.unauthorized(context) }

        JoluConnection.connect(context, "ABCD2345")
        waitUntil("connected") { JoluConnection.state is JoluState.Connected }
        driver.setAllConstraintsMet(info(JoluBackgroundSync.SOON_WORK)!!.id)
        waitUntil("the first sync") { info(JoluBackgroundSync.SOON_WORK)?.state == WorkInfo.State.SUCCEEDED }

        assertTrue(server.ingests.isEmpty())
        assertTrue(JoluConnection.state is JoluState.Connected)
        assertEquals(TEST_TOKEN, MemoryTokenStorage.token)
        assertEquals(JoluSyncOutcomeKind.NEEDS_HEALTH_ACCESS, status().lastOutcome)
        assertEquals(WorkInfo.State.ENQUEUED, info(JoluBackgroundSync.PERIODIC_WORK)!!.state)

        // Access granted in the app: a sync follows as soon as there is a network.
        TestSyncEnvironment.access = HealthAccess.AVAILABLE
        JoluBackgroundSync.healthAccessGranted(context)
        driver.setAllConstraintsMet(info(JoluBackgroundSync.SOON_WORK)!!.id)
        waitUntil("the sync after access") { server.ingests.size == 1 }
    }

    // ------------------------------------------------- 9. unpairing

    @Test
    fun `9 - unpaired (a 401 from any JoLu call) - every automatic sync is cancelled`() {
        paired()
        JoluBackgroundSync.schedule(context)
        JoluBackgroundSync.syncSoon(context)

        runBlocking { JoluConnection.unauthorized(context) }

        assertEquals(WorkInfo.State.CANCELLED, info(JoluBackgroundSync.PERIODIC_WORK)!!.state)
        assertEquals(WorkInfo.State.CANCELLED, info(JoluBackgroundSync.SOON_WORK)!!.state)
        assertNull(MemoryTokenStorage.token)
        assertTrue(server.requests.isEmpty())
    }

    // ------------------------------------------- 10. last successful sync

    @Test
    fun `10 - the last successful sync is kept through later failures and moves on the next success`() {
        paired()

        assertEquals(ListenableWorker.Result.success(), runWorker())
        val first = status().lastSuccessAt!!

        Thread.sleep(5)
        TestSyncEnvironment.api = JoluApi("http://127.0.0.1:1/")
        runWorker()
        val afterFailure = status()
        assertEquals("a failure does not move it", first, afterFailure.lastSuccessAt)
        assertTrue(afterFailure.lastAttemptAt!!.isAfter(first))
        assertEquals(JoluSyncOutcomeKind.OFFLINE, afterFailure.lastOutcome)

        Thread.sleep(5)
        TestSyncEnvironment.api = JoluApi(server.url)
        runWorker()
        assertTrue("the next success does", status().lastSuccessAt!!.isAfter(first))
        assertEquals(JoluSyncOutcomeKind.SYNCED, status().lastOutcome)
        assertEquals("the screen sees the same", status(), JoluSync.record)
    }

    // ---------------------------------------------- what the worker decides

    @Test
    fun `the worker's decisions`() {
        val summary = JoluSyncSummary(
            java.time.Instant.EPOCH, java.time.Instant.EPOCH, emptyMap(), emptyList(),
            IngestResult(0, 0, emptyList(), emptyMap(), emptyList(), emptyList(), null)
        )
        val d = JoluSyncWorker.Decision.DONE
        val r = JoluSyncWorker.Decision.RETRY
        val s = JoluSyncWorker.Decision.STOP
        fun sent(f: JoluResult.Failure) = JoluSyncOutcome.SendFailed(f, 0, 1)

        assertEquals(d, JoluSyncWorker.decide(JoluSyncOutcome.Synced(summary), 0))
        assertEquals(s, JoluSyncWorker.decide(JoluSyncOutcome.NotConnected, 0))
        assertEquals(s, JoluSyncWorker.decide(JoluSyncOutcome.Unauthorized, 0))
        assertEquals(d, JoluSyncWorker.decide(JoluSyncOutcome.Busy, 0))
        assertEquals(d, JoluSyncWorker.decide(JoluSyncOutcome.NoAccess(HealthAccess.NO_PERMISSION), 0))
        assertEquals(r, JoluSyncWorker.decide(JoluSyncOutcome.ReadFailed, 0))
        assertEquals(r, JoluSyncWorker.decide(sent(JoluResult.NetworkError), 2))
        assertEquals(d, JoluSyncWorker.decide(sent(JoluResult.NetworkError), 3))
        assertEquals(r, JoluSyncWorker.decide(sent(JoluResult.HttpError(503, null)), 0))
        assertEquals(r, JoluSyncWorker.decide(sent(JoluResult.HttpError(429, null)), 0))
        assertEquals("a 4xx will not get better by retrying", d, JoluSyncWorker.decide(sent(JoluResult.HttpError(413, null)), 0))
        assertEquals(r, JoluSyncWorker.decide(sent(JoluResult.InvalidResponse), 0))
    }
}
