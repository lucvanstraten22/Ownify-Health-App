package com.healthapp.android.jolu

import android.content.Context
import androidx.test.core.app.ApplicationProvider
import androidx.work.Configuration
import androidx.work.WorkInfo
import androidx.work.WorkManager
import androidx.work.testing.SynchronousExecutor
import androidx.work.testing.WorkManagerTestInitHelper
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/**
 * Signing in with Google — through the real JoluConnection and the real
 * JoluApi over HTTP, against a JoLu server on localhost whose app-google.php
 * keeps its conversation in a session cookie and checks the ID token's
 * audience and nonce, as the real one does (FakeJoluServer).
 *
 * Google's account chooser is the stand-in (FakeGoogle): by default somebody
 * picks their account and Google hands back an ID token for exactly what the
 * app asked for. What the server makes of a verified token — the same account
 * as on the website, somebody new, an address a password account has — is
 * the real server's, tested end to end in tools/app-auth-test.php; here it is
 * what the phone does with each answer.
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [36])
class GoogleSignInTest {

    private lateinit var context: Context
    private lateinit var server: FakeJoluServer
    private lateinit var work: WorkManager

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
        JoluConnection.google = FakeGoogle
        TestSyncEnvironment.context = context
        TestSyncEnvironment.api = JoluApi(server.url)
        TestSyncEnvironment.access = HealthAccess.AVAILABLE
        TestSyncEnvironment.records = SyncFixtures.day("google")
        TestSyncEnvironment.reads = 0
        JoluSyncRunner.environment = { TestSyncEnvironment }

        FakeGoogle.reset()
        MemoryTokenStorage.clear()
        JoluConnection.reset()
        JoluSyncStatusPrefs(context).clear()
    }

    @After
    fun tearDown() {
        work.cancelAllWork()
        server.stop()
        JoluConnection.google = CredentialManagerGoogle
    }

    // ------------------------------------------------------------ helpers

    private val connected get() = JoluConnection.state as? JoluState.Connected

    private fun stored(): JoluCredential? = MemoryTokenStorage.load()

    private fun periodic(): WorkInfo.State? =
        work.getWorkInfosForUniqueWork(JoluBackgroundSync.PERIODIC_WORK).get().singleOrNull()?.state

    private fun bearerOf(request: FakeJoluServer.Request): String? = request.headers["authorization"]

    /** The jolu_session cookie a request carried, or null. */
    private fun sessionOf(request: FakeJoluServer.Request): String? =
        request.headers["cookie"]?.split(';')?.map { it.trim() }?.firstOrNull { it.startsWith("jolu_session=") }?.substringAfter('=')

    private fun openApp() {
        JoluConnection.start(context)
        waitUntil("the app knows where it stands") {
            JoluConnection.state !is JoluState.Checking && JoluConnection.state !is JoluState.Loading
        }
    }

    /**
     * "Doorgaan met Google" pressed, and waited for until [done] — by default
     * until the account has opened. (The panel is back to idle a moment before
     * the account's profile has arrived, so idle alone is not the end.)
     */
    private fun google(what: String = "signed in with Google", done: () -> Boolean = { connected?.scope == JoluScope.ACCOUNT }) {
        JoluConnection.signInWithGoogle(context)
        waitUntil(what, done = done)
    }

    /** Pressed, and somebody new is asked for a username. */
    private fun googleStep() = google("the username step") { JoluConnection.googleStep != null && JoluConnection.auth == JoluAuthState.Idle }

    /** Pressed, and refused: the message. */
    private fun googleRefused(): String {
        google("the sign-in with Google refused") { JoluConnection.auth is JoluAuthState.Failed }
        return failure()
    }

    private fun failure(): String = (JoluConnection.auth as JoluAuthState.Failed).message

    /** A username chosen on the step, and waited for until [done] — by default until the account has opened. */
    private fun chooseUsername(name: String, done: () -> Boolean = { connected?.scope == JoluScope.ACCOUNT }) {
        JoluConnection.chooseGoogleUsername(context, name)
        waitUntil("the username step answered", done = done)
    }

    /** A username chosen, and refused: the message. */
    private fun usernameRefused(name: String): String {
        chooseUsername(name) { JoluConnection.auth is JoluAuthState.Failed }
        return failure()
    }

    // ---------------------------------------------------- the same account

    @Test
    fun `an existing Google account - the server's nonce and Web client go to Google, the ID token to the server, and that account opens`() {
        openApp()
        assertTrue(JoluConnection.canSignIn)

        google()

        // Google was asked for a token for the server's own Web client, with the nonce the server handed out.
        val nonce = server.googleNonces.single()
        assertEquals(listOf(GOOGLE_WEB_CLIENT to nonce), FakeGoogle.asked.toList())

        // That token went to the server as it came, in the same server session as the nonce.
        val start = server.google("nonce").single()
        val verify = server.google("verify").single()
        assertEquals(fakeGoogleIdToken(GOOGLE_WEB_CLIENT, nonce), verify.body.getString("id_token"))
        assertNull("the first step has no session yet", sessionOf(start))
        assertTrue("the ID token goes back in the nonce's session", sessionOf(verify) != null)
        assertEquals("android", verify.body.getString("platform"))
        assertTrue(verify.body.getString("label").isNotBlank())
        assertFalse("nothing about the person is claimed by the app", verify.body.has("email") || verify.body.has("sub") || verify.body.has("user_id"))
        assertNull("no token to send along: the phone had none", bearerOf(verify))

        // Signed in as the account the server found, exactly as after a password.
        assertEquals(JoluCredential(ACCOUNT_TOKEN, JoluScope.ACCOUNT), stored())
        assertEquals("sanne", connected!!.profile.username)
        assertEquals(JoluAuthState.Idle, JoluConnection.auth)
        assertNull("no username to choose", JoluConnection.googleStep)
        assertTrue(server.google("username").isEmpty())
        assertEquals("the server's conversation is closed", 0, server.openGoogleSessions)
        assertEquals("Bearer $ACCOUNT_TOKEN", bearerOf(server.requestsTo("profile.php").last()))
        assertEquals("the automatic sync starts", WorkInfo.State.ENQUEUED, periodic())
        assertFalse("signed in: nothing to sign in to", JoluConnection.canSignIn)
    }

    @Test
    fun `staying signed in - reopening the app needs no Google, signing out does, with a fresh nonce`() {
        openApp()
        google()
        assertEquals(JoluScope.ACCOUNT, connected!!.scope)

        // Pressed again while signed in: not on offer, nothing happens.
        JoluConnection.signInWithGoogle(context)
        assertEquals(1, server.google("nonce").size)

        // The process ends; the encrypted account token stays. Opening the app asks nobody.
        JoluConnection.reset()
        openApp()
        assertEquals(JoluScope.ACCOUNT, connected!!.scope)
        assertEquals("Google was not asked again", 1, FakeGoogle.asked.size)
        assertEquals("the server was not asked about Google again", 1, server.google("nonce").size)
        assertEquals("Bearer $ACCOUNT_TOKEN", bearerOf(server.requestsTo("profile.php").last()))

        // Signing out: revoked on the server, forgotten here, and Credential Manager told.
        JoluConnection.logout(context)
        waitUntil("signed out") { JoluConnection.state is JoluState.NotConnected && FakeGoogle.signedOut == 1 }
        assertNull(stored())
        assertTrue(ACCOUNT_TOKEN in server.revoked)
        assertEquals("Bearer $ACCOUNT_TOKEN", bearerOf(server.requestsTo("app-logout.php").single()))

        // Reopened: signed out it stays, until Google again — with a nonce of its own.
        JoluConnection.reset()
        openApp()
        assertEquals(JoluState.NotConnected(), JoluConnection.state)
        google()
        assertEquals(JoluScope.ACCOUNT, connected!!.scope)
        assertEquals(2, FakeGoogle.asked.size)
        assertNotEquals("every sign-in its own nonce", server.googleNonces[0], server.googleNonces[1])
        assertEquals(server.googleNonces[1], FakeGoogle.asked[1].second)
    }

    @Test
    fun `a paired phone signs in with Google - its sync token goes along and is replaced, as with a password`() {
        server.googleMode = FakeJoluServer.GoogleMode.NEW
        MemoryTokenStorage.token = TEST_TOKEN
        openApp()
        assertEquals(JoluScope.SYNC, connected!!.scope)
        assertTrue("paired, not signed in: signing in is on offer", JoluConnection.canSignIn)

        googleStep()
        chooseUsername("nieuw")

        assertEquals("Bearer $TEST_TOKEN", bearerOf(server.google("verify").single()))
        assertEquals("the phone's own token is the bearer, so its row is upgraded", "Bearer $TEST_TOKEN", bearerOf(server.google("username").single()))
        assertTrue(TEST_TOKEN in server.revoked)
        assertEquals(JoluCredential(ACCOUNT_TOKEN, JoluScope.ACCOUNT), stored())
    }

    // ------------------------------------------------------- somebody new

    @Test
    fun `somebody new - one username, once, and the account is made and signed in`() {
        server.googleMode = FakeJoluServer.GoogleMode.NEW
        openApp()

        googleStep()

        // Nothing exists yet: the step, with the address Google vouched for, and how long it waits.
        assertEquals(GoogleUsernameStep("nieuw@gmail.com", 10), JoluConnection.googleStep)
        assertEquals(JoluAuthState.Idle, JoluConnection.auth)
        assertNull("nothing stored before the account exists", stored())
        assertTrue(JoluConnection.state is JoluState.NotConnected)

        chooseUsername("  nieuw ")

        val username = server.google("username").single()
        assertEquals("nieuw", username.body.getString("username"))
        assertEquals("android", username.body.getString("platform"))
        assertTrue(sessionOf(username) != null)
        assertNotEquals("in the session the server gave with the step, not the first one",
            sessionOf(server.google("verify").single()), sessionOf(username))

        assertNull(JoluConnection.googleStep)
        assertEquals(JoluCredential(ACCOUNT_TOKEN, JoluScope.ACCOUNT), stored())
        assertEquals("nieuw", connected!!.profile.username)
        assertEquals("the ID token was sent once", 1, server.google("verify").size)
        assertEquals(0, server.openGoogleSessions)
        assertEquals(WorkInfo.State.ENQUEUED, periodic())
    }

    @Test
    fun `the username step - empty is not sent, taken keeps the step with the server's words, then a free one works`() {
        server.googleMode = FakeJoluServer.GoogleMode.NEW
        openApp()
        googleStep()

        assertEquals("Kies een gebruikersnaam.", usernameRefused("   "))
        assertTrue(server.google("username").isEmpty())

        assertEquals("Deze gebruikersnaam is al bezet.", usernameRefused("bezet"))
        assertEquals("still on the step", GoogleUsernameStep("nieuw@gmail.com", 10), JoluConnection.googleStep)
        assertNull(stored())

        chooseUsername("vrij")
        assertEquals(JoluCredential(ACCOUNT_TOKEN, JoluScope.ACCOUNT), stored())
        assertEquals("vrij", connected!!.profile.username)
        assertEquals("Google was asked once", 1, FakeGoogle.asked.size)
    }

    @Test
    fun `the username step ran out on the server - back to the start with its words, and starting again works`() {
        server.googleMode = FakeJoluServer.GoogleMode.NEW
        openApp()
        googleStep()

        server.googleStepExpired = true
        assertEquals("Je Google-aanmelding is verlopen. Begin opnieuw met Google.", usernameRefused("nieuw"))
        assertNull("the step is gone", JoluConnection.googleStep)
        assertNull(stored())

        server.googleStepExpired = false
        googleStep()
        assertEquals(GoogleUsernameStep("nieuw@gmail.com", 10), JoluConnection.googleStep)
    }

    @Test
    fun `Annuleren on the username step - nothing is made, and the server forgets who Google said it was`() {
        server.googleMode = FakeJoluServer.GoogleMode.NEW
        openApp()
        googleStep()
        val waiting = sessionOf(server.google("verify").single())

        JoluConnection.cancelGoogle()

        assertNull(JoluConnection.googleStep)
        assertEquals(JoluAuthState.Idle, JoluConnection.auth)
        waitUntil("the server was told") { server.google("cancel").size == 1 && server.openGoogleSessions == 0 }
        val cancel = server.google("cancel").single()
        assertTrue(sessionOf(cancel) != null)
        assertNotEquals("the waiting person's session, as the server renamed it", waiting, sessionOf(cancel))
        assertNull(stored())
        assertTrue(server.google("username").isEmpty())
        assertTrue(JoluConnection.canSignIn)
    }

    // ------------------------------------------------ refused, and trouble

    @Test
    fun `an address a password account already has - refused with the server's words, never merged, nothing stored`() {
        server.googleMode = FakeJoluServer.GoogleMode.CONFLICT
        openApp()

        assertEquals("Er bestaat al een account met dit e-mailadres. Log in met je wachtwoord en koppel Google via Instellingen.", googleRefused())
        assertNull(stored())
        assertNull("no account is made either", JoluConnection.googleStep)
        assertTrue(JoluConnection.state is JoluState.NotConnected)
        assertTrue(server.requestsTo("profile.php").isEmpty())
        assertTrue(JoluConnection.canSignIn)
    }

    @Test
    fun `a token the server cannot verify - for another nonce or another client - one sentence, and nothing stored`() {
        openApp()

        FakeGoogle.answer = { audience, _ -> GoogleIdResult.Token(fakeGoogleIdToken(audience, "an-earlier-nonce")) }
        assertEquals("Inloggen met Google is niet gelukt. Probeer het opnieuw.", googleRefused())

        FakeGoogle.answer = { _, nonce -> GoogleIdResult.Token(fakeGoogleIdToken("2222-another-app.apps.googleusercontent.com", nonce)) }
        assertEquals("Inloggen met Google is niet gelukt. Probeer het opnieuw.", googleRefused())

        assertNull(stored())
        assertTrue(JoluConnection.state is JoluState.NotConnected)
    }

    @Test
    fun `the chooser closed - no message, and nothing goes to the server`() {
        openApp()
        FakeGoogle.answer = { _, _ -> GoogleIdResult.Cancelled }

        google("the chooser closed") { FakeGoogle.asked.size == 1 && JoluConnection.auth == JoluAuthState.Idle }

        assertEquals(JoluAuthState.Idle, JoluConnection.auth)
        assertTrue(server.google("verify").isEmpty())
        assertNull(stored())
        assertTrue(JoluConnection.canSignIn)
    }

    @Test
    fun `no Google account on the phone, no Play services, interrupted, refused - a clear message each, and nothing stored`() {
        openApp()

        val expected = mapOf(
            GoogleIdResult.NoAccount to
                "Er staat geen Google-account op deze telefoon. Voeg er een toe in de instellingen van je telefoon en probeer het opnieuw.",
            GoogleIdResult.Unavailable to
                "Inloggen met Google werkt niet op deze telefoon: Google Play-services ontbreken of zijn verouderd.",
            GoogleIdResult.Interrupted to "Inloggen met Google werd onderbroken. Probeer het opnieuw.",
            GoogleIdResult.Failed to "Inloggen met Google is niet gelukt. Probeer het opnieuw."
        )

        for ((result, message) in expected) {
            FakeGoogle.answer = { _, _ -> result }
            assertEquals("$result", message, googleRefused())
        }

        assertTrue(server.google("verify").isEmpty())
        assertNull(stored())
        assertTrue(JoluConnection.canSignIn)
    }

    @Test
    fun `offline, or Google's keys out of reach for the server - what kind of trouble, and nothing stored`() {
        openApp()

        JoluConnection.api = JoluApi("http://127.0.0.1:9/")
        assertEquals("Kan JoLu niet bereiken. Controleer je internetverbinding en probeer het opnieuw.", googleRefused())
        assertTrue("Google is not asked when JoLu cannot be reached", FakeGoogle.asked.isEmpty())

        JoluConnection.api = JoluApi(server.url)
        server.googleMode = FakeJoluServer.GoogleMode.GOOGLE_DOWN
        assertEquals("Google kon je aanmelding niet bevestigen. Probeer het opnieuw.", googleRefused())

        assertNull(stored())
    }

    @Test
    fun `a paired phone whose Google sign-in fails keeps its pairing and its sync`() {
        server.googleMode = FakeJoluServer.GoogleMode.CONFLICT
        MemoryTokenStorage.token = TEST_TOKEN
        openApp()

        googleRefused()
        assertEquals(JoluCredential(TEST_TOKEN, JoluScope.SYNC), stored())
        assertEquals(JoluScope.SYNC, connected!!.scope)
        assertFalse(TEST_TOKEN in server.revoked)
    }

    @Test
    fun `Google and the server say yes, but the phone cannot keep the session - nothing changes, and it says so`() {
        JoluConnection.storage = { Unsavable }
        JoluConnection.reset()
        openApp()

        assertEquals("Deze telefoon kon je sessie niet veilig bewaren. Log opnieuw in.", googleRefused())

        assertEquals("the server did sign in", 1, server.google("verify").size)
        assertTrue(JoluConnection.state is JoluState.NotConnected)
        assertTrue("nothing is read with a token the phone does not have", server.requestsTo("profile.php").isEmpty())
        assertNull(periodic())
    }

    /** A Keystore that refuses to keep anything. */
    private object Unsavable : JoluTokenStorage {
        override fun load(): JoluCredential? = null
        override fun save(credential: JoluCredential) = throw IllegalStateException("no key")
        override fun clear() = Unit
    }

    @Test
    fun `not set up on the server - the button's check says so, and pressing anyway says so without asking Google`() {
        server.googleMode = FakeJoluServer.GoogleMode.NOT_CONFIGURED
        openApp()
        assertNull("not asked yet", JoluConnection.googleAvailable)

        JoluConnection.checkGoogle()
        waitUntil("the server answered") { JoluConnection.googleAvailable != null }
        assertEquals(false, JoluConnection.googleAvailable)

        assertEquals("Inloggen met Google is in de app nog niet beschikbaar.", googleRefused())
        assertTrue(FakeGoogle.asked.isEmpty())

        server.googleMode = FakeJoluServer.GoogleMode.EXISTING
        JoluConnection.checkGoogle()
        waitUntil("the server answered again") { JoluConnection.googleAvailable == true }
    }

    @Test
    fun `no ID token or nonce appears in text`() {
        assertFalse(GoogleIdResult.Token("eyJ.secret.token").toString().contains("secret"))
        assertFalse(GoogleStart("the-nonce", GOOGLE_WEB_CLIENT).toString().contains("the-nonce"))
    }
}
