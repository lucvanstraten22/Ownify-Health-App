package com.healthapp.android.jolu

import android.content.Context
import androidx.test.core.app.ApplicationProvider
import androidx.work.Configuration
import androidx.work.ListenableWorker
import androidx.work.WorkInfo
import androidx.work.WorkManager
import androidx.work.testing.SynchronousExecutor
import androidx.work.testing.TestListenableWorkerBuilder
import androidx.work.testing.WorkManagerTestInitHelper
import java.util.concurrent.CountDownLatch
import java.util.concurrent.TimeUnit
import java.util.concurrent.atomic.AtomicReference
import kotlin.concurrent.thread
import kotlinx.coroutines.runBlocking
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/**
 * Signing in, registering and signing out as a JoLu account — through the
 * real JoluConnection, the real JoluApi over HTTP against a JoLu server on
 * localhost (FakeJoluServer, which replaces and revokes tokens as the real
 * one does), and the real automatic sync: WorkManager's test driver runs the
 * real JoluSyncWorker and pipeline.
 *
 * Only Health Connect (TestSyncEnvironment) and the Keystore
 * (MemoryTokenStorage) are stand-ins: the JVM has neither.
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [36])
class JoluAccountTest {

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
        TestSyncEnvironment.records = SyncFixtures.day("acc")
        TestSyncEnvironment.reads = 0
        JoluSyncRunner.environment = { TestSyncEnvironment }

        MemoryTokenStorage.clear()
        JoluConnection.reset()
        JoluSyncStatusPrefs(context).clear()
    }

    @After
    fun tearDown() {
        server.ingestGate?.countDown()
        work.cancelAllWork()
        server.stop()
    }

    // ------------------------------------------------------------ helpers

    private fun info(name: String): WorkInfo? = work.getWorkInfosForUniqueWork(name).get().singleOrNull()

    private fun periodic(): WorkInfo.State? = info(JoluBackgroundSync.PERIODIC_WORK)?.state

    private fun status() = JoluSyncStatusPrefs(context).read()

    private fun stored(): JoluCredential? = MemoryTokenStorage.load()

    private val connected get() = JoluConnection.state as? JoluState.Connected

    /** The app opens: the stored credential, if any, is used without asking for anything. */
    private fun openApp() {
        JoluConnection.start(context)
        waitUntil("the app knows where it stands") {
            JoluConnection.state !is JoluState.Checking && JoluConnection.state !is JoluState.Loading
        }
    }

    private fun signIn(identifier: String = "sanne", password: String = "geheim-wachtwoord") {
        JoluConnection.login(context, identifier, password)
        waitUntil("signed in") { connected?.scope == JoluScope.ACCOUNT }
    }

    /** One try at signing in that is expected to fail: its message. */
    private fun signInFails(identifier: String = "sanne", password: String = "geheim-wachtwoord"): String {
        JoluConnection.login(context, identifier, password)
        waitUntil("the sign-in failed") { JoluConnection.auth is JoluAuthState.Failed }
        return (JoluConnection.auth as JoluAuthState.Failed).message
    }

    private fun runWorker(): ListenableWorker.Result = runBlocking {
        TestListenableWorkerBuilder<JoluSyncWorker>(context).build().doWork()
    }

    private fun runScheduledOnce() {
        val id = info(JoluBackgroundSync.PERIODIC_WORK)!!.id
        val attempts = status().lastAttemptAt
        driver.setPeriodDelayMet(id)
        driver.setAllConstraintsMet(id)
        waitUntil("the scheduled run finished") {
            status().lastAttemptAt != attempts && periodic() != WorkInfo.State.RUNNING
        }
    }

    private fun bearerOf(request: FakeJoluServer.Request): String? = request.headers["authorization"]

    // ------------------------------------------------------------- login

    @Test
    fun `login - an existing account signs in, the account token is stored with its scope, and the session starts`() {
        openApp()
        assertEquals(JoluState.NotConnected(), JoluConnection.state)
        assertTrue(JoluConnection.canSignIn)

        signIn(identifier = "  sanne ", password = "geheim-wachtwoord")

        val login = server.requestsTo("app-login.php").single()
        assertEquals("sanne", login.body.getString("identifier"))
        assertEquals("geheim-wachtwoord", login.body.getString("password"))
        assertEquals("android", login.body.getString("platform"))
        assertTrue(login.body.getString("label").isNotBlank())
        assertFalse("no user id in any request", login.body.has("user_id"))
        assertNull("no token to send along: the phone had none", bearerOf(login))

        assertEquals(JoluCredential(ACCOUNT_TOKEN, JoluScope.ACCOUNT), stored())
        assertEquals("sanne", connected!!.profile.username)
        assertEquals(JoluAuthState.Idle, JoluConnection.auth)
        assertFalse("signed in: nothing to sign in to", JoluConnection.canSignIn)

        // The profile is read with the account token, and the automatic sync starts.
        assertEquals("Bearer $ACCOUNT_TOKEN", bearerOf(server.requestsTo("profile.php").single()))
        assertEquals(WorkInfo.State.ENQUEUED, periodic())
        assertEquals(WorkInfo.State.ENQUEUED, info(JoluBackgroundSync.SOON_WORK)!!.state)
    }

    @Test
    fun `login on a paired phone - its sync token goes along, is replaced, and the sync carries on with the account token`() {
        MemoryTokenStorage.token = TEST_TOKEN
        openApp()
        assertEquals(JoluScope.SYNC, connected!!.scope)
        assertTrue("paired, not signed in: signing in is on offer", JoluConnection.canSignIn)

        signIn()

        assertEquals("the phone's own token is the bearer, so its row is upgraded", "Bearer $TEST_TOKEN",
            bearerOf(server.requestsTo("app-login.php").single()))
        assertEquals(JoluCredential(ACCOUNT_TOKEN, JoluScope.ACCOUNT), stored())

        runScheduledOnce()

        assertEquals("Bearer $ACCOUNT_TOKEN", bearerOf(server.ingests.last()))
        assertEquals(JoluSyncOutcomeKind.SYNCED, status().lastOutcome)
        assertEquals("the automatic sync is still on", WorkInfo.State.ENQUEUED, periodic())
    }

    @Test
    fun `login failure - wrong password, too many attempts, server trouble, offline - a clear message, and the paired phone keeps syncing`() {
        MemoryTokenStorage.token = TEST_TOKEN
        openApp()
        assertEquals(WorkInfo.State.ENQUEUED, periodic())

        server.loginMode = FakeJoluServer.LoginMode.WRONG_PASSWORD
        assertEquals("Gebruikersnaam of wachtwoord klopt niet.", signInFails(password = "fout"))

        // A 401 from signing in is about the password — never about the stored token.
        assertEquals(JoluCredential(TEST_TOKEN, JoluScope.SYNC), stored())
        assertEquals(JoluScope.SYNC, connected!!.scope)
        assertEquals(WorkInfo.State.ENQUEUED, periodic())

        server.loginMode = FakeJoluServer.LoginMode.THROTTLED
        assertEquals("Te veel mislukte pogingen. Probeer het over 14 minuten opnieuw.", signInFails())

        server.loginMode = FakeJoluServer.LoginMode.SERVER_ERROR
        assertEquals("Er ging iets mis op de server. Probeer het opnieuw.", signInFails())

        JoluConnection.api = JoluApi("http://127.0.0.1:1/")
        assertTrue(signInFails().startsWith("Kan JoLu niet bereiken"))
        JoluConnection.api = JoluApi(server.url)

        assertEquals("still the paired phone, untouched", JoluCredential(TEST_TOKEN, JoluScope.SYNC), stored())
        assertTrue(server.revoked.isEmpty())
        assertEquals(WorkInfo.State.ENQUEUED, periodic())

        runScheduledOnce()
        assertEquals("Bearer $TEST_TOKEN", bearerOf(server.ingests.single()))
        assertEquals(JoluSyncOutcomeKind.SYNCED, status().lastOutcome)
    }

    @Test
    fun `login - empty fields are not sent`() {
        openApp()

        JoluConnection.login(context, "  ", "geheim-wachtwoord")
        assertEquals(JoluAuthState.Failed("Vul je gebruikersnaam en wachtwoord in."), JoluConnection.auth)

        JoluConnection.register(context, "nieuw", "", "geheim-wachtwoord")
        assertEquals(JoluAuthState.Failed("Vul een gebruikersnaam, e-mailadres en wachtwoord in."), JoluConnection.auth)

        assertTrue(server.requests.isEmpty())
    }

    // ------------------------------------------------------ registration

    @Test
    fun `registration - creates the account, stores the account token, and the session starts`() {
        openApp()

        JoluConnection.register(context, " nieuwe_naam ", " nieuw@example.invalid ", "geheim-wachtwoord")
        waitUntil("registered and signed in") { connected?.scope == JoluScope.ACCOUNT }

        val register = server.requestsTo("app-register.php").single()
        assertEquals("nieuwe_naam", register.body.getString("username"))
        assertEquals("nieuw@example.invalid", register.body.getString("email"))
        assertEquals("geheim-wachtwoord", register.body.getString("password"))
        assertEquals("android", register.body.getString("platform"))

        assertEquals(JoluCredential(ACCOUNT_TOKEN, JoluScope.ACCOUNT), stored())
        assertEquals(WorkInfo.State.ENQUEUED, periodic())
    }

    @Test
    fun `registration failure - the server's reason, and nothing stored`() {
        openApp()

        JoluConnection.register(context, "bezet", "bezet@example.invalid", "geheim-wachtwoord")
        waitUntil("refused") { JoluConnection.auth is JoluAuthState.Failed }

        assertEquals(JoluAuthState.Failed("Deze gebruikersnaam is al bezet."), JoluConnection.auth)
        assertNull(stored())
        assertTrue(JoluConnection.state is JoluState.NotConnected)
    }

    // ------------------------------------------------------------ logout

    @Test
    fun `logout - revoked on the server, forgotten here, automatic sync stopped - Health Connect untouched, and signing in again syncs at once`() {
        signedInAtStart()
        assertEquals(WorkInfo.State.ENQUEUED, periodic())
        val readsBefore = TestSyncEnvironment.reads

        JoluConnection.logout(context)
        waitUntil("signed out") { JoluConnection.state == JoluState.NotConnected(JoluConnection.LOGGED_OUT_MESSAGE) }

        assertEquals("Bearer $ACCOUNT_TOKEN", bearerOf(server.requestsTo("app-logout.php").single()))
        assertTrue("revoked on the server", ACCOUNT_TOKEN in server.revoked)
        assertNull("forgotten on the phone", stored())
        assertEquals(WorkInfo.State.CANCELLED, periodic())
        assertNull("the last session's sync status is gone", status().lastAttemptAt)
        assertEquals(JoluSyncState.Idle, JoluSync.state)
        assertEquals("Health Connect was not even asked", readsBefore, TestSyncEnvironment.reads)
        assertEquals(HealthAccess.AVAILABLE, TestSyncEnvironment.access)
        assertTrue(JoluConnection.canSignIn)

        // Signing in again: no Health Connect permission asked for again, the sync runs straight away.
        signIn()
        driver.setAllConstraintsMet(info(JoluBackgroundSync.SOON_WORK)!!.id)
        waitUntil("the first sync of the new session") { info(JoluBackgroundSync.SOON_WORK)?.state == WorkInfo.State.SUCCEEDED }

        assertEquals("Bearer $ACCOUNT_TOKEN", bearerOf(server.ingests.single()))
        assertEquals(JoluSyncOutcomeKind.SYNCED, status().lastOutcome)
        assertEquals(WorkInfo.State.ENQUEUED, periodic())
    }

    @Test
    fun `logout while JoLu cannot be reached - signed out on the phone anyway, and it says so`() {
        signedInAtStart()
        JoluConnection.api = JoluApi("http://127.0.0.1:1/")

        JoluConnection.logout(context)
        waitUntil("signed out") { JoluConnection.state is JoluState.NotConnected }

        assertEquals(JoluState.NotConnected(JoluConnection.LOGGED_OUT_UNREACHED_MESSAGE), JoluConnection.state)
        assertNull(stored())
        assertEquals(WorkInfo.State.CANCELLED, periodic())
    }

    @Test
    fun `logout with only a paired phone - nothing to sign out of, nothing changes`() {
        MemoryTokenStorage.token = TEST_TOKEN
        openApp()

        JoluConnection.logout(context)
        waitUntil("done") { JoluConnection.auth == JoluAuthState.Idle }

        assertEquals(JoluCredential(TEST_TOKEN, JoluScope.SYNC), stored())
        assertTrue(server.requestsTo("app-logout.php").isEmpty())
        assertEquals(WorkInfo.State.ENQUEUED, periodic())
    }

    // ------------------------------------------------ the session is kept

    private fun signedInAtStart() {
        openApp()
        signIn()
    }

    @Test
    fun `the session survives closing and opening the app - no password, and the automatic sync carries on`() {
        signedInAtStart()

        JoluConnection.reset()          // the process is gone; the stored credential is not
        openApp()

        assertEquals(JoluScope.ACCOUNT, connected!!.scope)
        assertEquals("not signed in again", 1, server.requestsTo("app-login.php").size)
        assertEquals("Bearer $ACCOUNT_TOKEN", bearerOf(server.requestsTo("profile.php").last()))
        assertEquals(WorkInfo.State.ENQUEUED, periodic())

        runScheduledOnce()
        assertEquals("Bearer $ACCOUNT_TOKEN", bearerOf(server.ingests.last()))
        assertEquals(JoluSyncOutcomeKind.SYNCED, status().lastOutcome)
    }

    @Test
    fun `a signed-in phone that cannot reach JoLu at start stays signed in`() {
        MemoryTokenStorage.signedIn()
        JoluConnection.api = JoluApi("http://127.0.0.1:1/")

        openApp()

        assertTrue(JoluConnection.state is JoluState.Failed)
        assertEquals(JoluScope.ACCOUNT, (JoluConnection.state as JoluState.Failed).scope)
        assertEquals(JoluCredential(ACCOUNT_TOKEN, JoluScope.ACCOUNT), stored())

        JoluConnection.api = JoluApi(server.url)
        JoluConnection.retry(context)
        waitUntil("connected again") { connected?.scope == JoluScope.ACCOUNT }
    }

    // ---------------------------------------------- invalid / expired

    @Test
    fun `an expired or revoked account token at start - signed out, and asked to sign in again`() {
        MemoryTokenStorage.signedIn()
        JoluBackgroundSync.schedule(context)
        server.revoked += ACCOUNT_TOKEN

        openApp()

        assertEquals(JoluState.NotConnected(JoluConnection.SESSION_EXPIRED_MESSAGE), JoluConnection.state)
        assertNull(stored())
        assertEquals(WorkInfo.State.CANCELLED, periodic())
        assertTrue(JoluConnection.canSignIn)
    }

    @Test
    fun `an account token revoked on the website - the next automatic sync gets 401, signs out and stops`() {
        signedInAtStart()
        server.revoked += ACCOUNT_TOKEN

        runScheduledOnce()

        assertNull(stored())
        assertEquals(JoluState.NotConnected(JoluConnection.SESSION_EXPIRED_MESSAGE), JoluConnection.state)
        assertEquals(WorkInfo.State.CANCELLED, periodic())
        assertEquals(JoluSyncOutcomeKind.EXPIRED, status().lastOutcome)
    }

    // ------------------------------------------------------ the race

    @Test
    fun `a stale 401 - the automatic sync was sending with the paired token while the phone signed in - does not sign the new session out`() {
        MemoryTokenStorage.token = TEST_TOKEN
        openApp()

        // The automatic sync starts with the paired token, and its request is held on the way.
        server.ingestGate = CountDownLatch(1)
        server.ingestArrived = CountDownLatch(1)
        val result = AtomicReference<ListenableWorker.Result>()
        val worker = thread { result.set(runWorker()) }
        assertTrue(server.ingestArrived.await(15, TimeUnit.SECONDS))

        // Meanwhile: signed in. The server replaces the paired token with the account token.
        signIn()
        assertTrue(TEST_TOKEN in server.revoked)
        assertEquals(JoluCredential(ACCOUNT_TOKEN, JoluScope.ACCOUNT), stored())

        // The held request is answered now — 401, for the token that is gone.
        server.ingestGate!!.countDown()
        worker.join(30_000)
        waitUntil("the worker finished") { result.get() != null }

        assertEquals(ListenableWorker.Result.success(), result.get())
        assertEquals("the new session is intact", JoluCredential(ACCOUNT_TOKEN, JoluScope.ACCOUNT), stored())
        assertEquals(JoluScope.ACCOUNT, connected!!.scope)
        assertEquals("the automatic sync is still on", WorkInfo.State.ENQUEUED, periodic())

        // The run started again, once, with the token stored now — and synced.
        assertEquals(listOf("Bearer $TEST_TOKEN", "Bearer $ACCOUNT_TOKEN"), server.ingests.map { bearerOf(it) })
        assertEquals(JoluSyncOutcomeKind.SYNCED, status().lastOutcome)
    }

    @Test
    fun `a stale 401 on the button's sync is harmless too`() {
        MemoryTokenStorage.token = TEST_TOKEN
        openApp()

        server.ingestGate = CountDownLatch(1)
        server.ingestArrived = CountDownLatch(1)
        JoluSync.sync(context)
        waitUntil("the button's request is on its way") { server.ingestArrived.count == 0L }

        signIn()
        server.ingestGate!!.countDown()
        waitUntil("the button's sync finished") { JoluSync.state !is JoluSyncState.Syncing && server.ingests.size == 2 }

        assertEquals(JoluCredential(ACCOUNT_TOKEN, JoluScope.ACCOUNT), stored())
        assertEquals(JoluScope.ACCOUNT, connected!!.scope)
        assertTrue(JoluSync.state is JoluSyncState.Synced)
        assertEquals(WorkInfo.State.ENQUEUED, periodic())
    }

    @Test
    fun `a late 401 after signing out does not undo anything or sign a later session out`() {
        signedInAtStart()

        server.ingestGate = CountDownLatch(1)
        server.ingestArrived = CountDownLatch(1)
        val result = AtomicReference<ListenableWorker.Result>()
        val worker = thread { result.set(runWorker()) }
        assertTrue(server.ingestArrived.await(15, TimeUnit.SECONDS))

        JoluConnection.logout(context)
        waitUntil("signed out") { JoluConnection.state is JoluState.NotConnected }

        server.ingestGate!!.countDown()
        worker.join(30_000)
        waitUntil("the worker finished") { result.get() != null }

        assertEquals(JoluState.NotConnected(JoluConnection.LOGGED_OUT_MESSAGE), JoluConnection.state)
        assertNull(stored())
        assertEquals(1, server.ingests.size)
    }

    // ------------------------------------------------------ scopes

    @Test
    fun `scopes - pairing makes a sync credential, and a sign-in answer that is not an account token is not stored`() {
        openApp()
        JoluConnection.connect(context, "ABCD2345")
        waitUntil("paired") { connected != null }
        assertEquals(JoluCredential(TEST_TOKEN, JoluScope.SYNC), stored())
        assertEquals(JoluScope.SYNC, connected!!.scope)

        server.loginMode = FakeJoluServer.LoginMode.NOT_AN_ACCOUNT_TOKEN
        assertEquals("JoLu gaf een onverwacht antwoord. Probeer het later opnieuw.", signInFails())
        assertEquals("the sync credential is kept", JoluCredential(TEST_TOKEN, JoluScope.SYNC), stored())

        server.loginMode = FakeJoluServer.LoginMode.OK
        signIn()
        val before = server.requests.size

        JoluConnection.login(context, "sanne", "geheim-wachtwoord")
        assertEquals("already signed in: not sent again", before, server.requests.size)
    }

    @Test
    fun `no token ever appears in a credential's or a session's text`() {
        assertFalse(JoluCredential(ACCOUNT_TOKEN, JoluScope.ACCOUNT).toString().contains(ACCOUNT_TOKEN))
        assertFalse(JoluSession(ACCOUNT_TOKEN, "sanne").toString().contains(ACCOUNT_TOKEN))
        assertFalse(JoluResult.Success(JoluSession(ACCOUNT_TOKEN, "sanne")).toString().contains(ACCOUNT_TOKEN))
    }
}
