package com.ownify.android.connection

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
 * Signing in with Google — through the real OwnifyConnection and the real
 * OwnifyApi over HTTP, against a Ownify server on localhost whose app-google.php
 * keeps its conversation in a session cookie and checks the ID token's
 * audience and nonce, as the real one does (FakeOwnifyServer).
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
    private lateinit var server: FakeOwnifyServer
    private lateinit var work: WorkManager

    @Before
    fun setUp() {
        context = ApplicationProvider.getApplicationContext()
        WorkManagerTestInitHelper.initializeTestWorkManager(
            context,
            Configuration.Builder().setExecutor(SynchronousExecutor()).build()
        )
        work = WorkManager.getInstance(context)

        server = FakeOwnifyServer()

        OwnifyConnection.api = OwnifyApi(server.url)
        OwnifyConnection.storage = { MemoryTokenStorage }
        OwnifyConnection.google = FakeGoogle
        TestSyncEnvironment.context = context
        TestSyncEnvironment.api = OwnifyApi(server.url)
        TestSyncEnvironment.access = HealthAccess.AVAILABLE
        TestSyncEnvironment.records = SyncFixtures.day("google")
        TestSyncEnvironment.reads = 0
        OwnifySyncRunner.environment = { TestSyncEnvironment }

        FakeGoogle.reset()
        MemoryTokenStorage.clear()
        OwnifyConnection.reset()
        OwnifySyncStatusPrefs(context).clear()
    }

    @After
    fun tearDown() {
        work.cancelAllWork()
        server.stop()
        OwnifyConnection.google = CredentialManagerGoogle
    }

    // ------------------------------------------------------------ helpers

    private val connected get() = OwnifyConnection.state as? OwnifyState.Connected

    private fun stored(): OwnifyCredential? = MemoryTokenStorage.load()

    private fun periodic(): WorkInfo.State? =
        work.getWorkInfosForUniqueWork(OwnifyBackgroundSync.PERIODIC_WORK).get().singleOrNull()?.state

    private fun bearerOf(request: FakeOwnifyServer.Request): String? = request.headers["authorization"]

    /** The ownify_session cookie a request carried, or null. */
    private fun sessionOf(request: FakeOwnifyServer.Request): String? =
        request.headers["cookie"]?.split(';')?.map { it.trim() }?.firstOrNull { it.startsWith("ownify_session=") }?.substringAfter('=')

    private fun openApp() {
        OwnifyConnection.start(context)
        waitUntil("the app knows where it stands") {
            OwnifyConnection.state !is OwnifyState.Checking && OwnifyConnection.state !is OwnifyState.Loading
        }
    }

    /**
     * "Doorgaan met Google" pressed, and waited for until [done] — by default
     * until the account has opened. (The panel is back to idle a moment before
     * the account's profile has arrived, so idle alone is not the end.)
     */
    private fun google(what: String = "signed in with Google", done: () -> Boolean = { connected?.scope == OwnifyScope.ACCOUNT }) {
        OwnifyConnection.signInWithGoogle(context)
        waitUntil(what, done = done)
    }

    /** Pressed, and somebody new is asked for a username. */
    private fun googleStep() = google("the username step") { OwnifyConnection.googleStep != null && OwnifyConnection.auth == OwnifyAuthState.Idle }

    /** Pressed, and refused: the message. */
    private fun googleRefused(): String {
        google("the sign-in with Google refused") { OwnifyConnection.auth is OwnifyAuthState.Failed }
        return failure()
    }

    private fun failure(): String = (OwnifyConnection.auth as OwnifyAuthState.Failed).message

    /** A username chosen on the step, and waited for until [done] — by default until the account has opened. */
    private fun chooseUsername(name: String, done: () -> Boolean = { connected?.scope == OwnifyScope.ACCOUNT }) {
        OwnifyConnection.chooseGoogleUsername(context, name)
        waitUntil("the username step answered", done = done)
    }

    /** A username chosen, and refused: the message. */
    private fun usernameRefused(name: String): String {
        chooseUsername(name) { OwnifyConnection.auth is OwnifyAuthState.Failed }
        return failure()
    }

    // ---------------------------------------------------- the same account

    @Test
    fun `an existing Google account - the server's nonce and Web client go to Google, the ID token to the server, and that account opens`() {
        openApp()
        assertTrue(OwnifyConnection.canSignIn)

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
        assertEquals(OwnifyCredential(ACCOUNT_TOKEN, OwnifyScope.ACCOUNT), stored())
        assertEquals("sanne", connected!!.profile.username)
        assertEquals(OwnifyAuthState.Idle, OwnifyConnection.auth)
        assertNull("no username to choose", OwnifyConnection.googleStep)
        assertTrue(server.google("username").isEmpty())
        assertEquals("the server's conversation is closed", 0, server.openGoogleSessions)
        assertEquals("Bearer $ACCOUNT_TOKEN", bearerOf(server.requestsTo("profile.php").last()))
        assertEquals("the automatic sync starts", WorkInfo.State.ENQUEUED, periodic())
        assertFalse("signed in: nothing to sign in to", OwnifyConnection.canSignIn)
    }

    @Test
    fun `staying signed in - reopening the app needs no Google, signing out does, with a fresh nonce`() {
        openApp()
        google()
        assertEquals(OwnifyScope.ACCOUNT, connected!!.scope)

        // Pressed again while signed in: not on offer, nothing happens.
        OwnifyConnection.signInWithGoogle(context)
        assertEquals(1, server.google("nonce").size)

        // The process ends; the encrypted account token stays. Opening the app asks nobody.
        OwnifyConnection.reset()
        openApp()
        assertEquals(OwnifyScope.ACCOUNT, connected!!.scope)
        assertEquals("Google was not asked again", 1, FakeGoogle.asked.size)
        assertEquals("the server was not asked about Google again", 1, server.google("nonce").size)
        assertEquals("Bearer $ACCOUNT_TOKEN", bearerOf(server.requestsTo("profile.php").last()))

        // Signing out: revoked on the server, forgotten here, and Credential Manager told.
        OwnifyConnection.logout(context)
        waitUntil("signed out") { OwnifyConnection.state is OwnifyState.NotConnected && FakeGoogle.signedOut == 1 }
        assertNull(stored())
        assertTrue(ACCOUNT_TOKEN in server.revoked)
        assertEquals("Bearer $ACCOUNT_TOKEN", bearerOf(server.requestsTo("app-logout.php").single()))

        // Reopened: signed out it stays, until Google again — with a nonce of its own.
        OwnifyConnection.reset()
        openApp()
        assertEquals(OwnifyState.NotConnected(), OwnifyConnection.state)
        google()
        assertEquals(OwnifyScope.ACCOUNT, connected!!.scope)
        assertEquals(2, FakeGoogle.asked.size)
        assertNotEquals("every sign-in its own nonce", server.googleNonces[0], server.googleNonces[1])
        assertEquals(server.googleNonces[1], FakeGoogle.asked[1].second)
    }

    @Test
    fun `a paired phone signs in with Google - its sync token goes along and is replaced, as with a password`() {
        server.googleMode = FakeOwnifyServer.GoogleMode.NEW
        MemoryTokenStorage.token = TEST_TOKEN
        openApp()
        assertEquals(OwnifyScope.SYNC, connected!!.scope)
        assertTrue("paired, not signed in: signing in is on offer", OwnifyConnection.canSignIn)

        googleStep()
        chooseUsername("nieuw")

        assertEquals("Bearer $TEST_TOKEN", bearerOf(server.google("verify").single()))
        assertEquals("the phone's own token is the bearer, so its row is upgraded", "Bearer $TEST_TOKEN", bearerOf(server.google("username").single()))
        assertTrue(TEST_TOKEN in server.revoked)
        assertEquals(OwnifyCredential(ACCOUNT_TOKEN, OwnifyScope.ACCOUNT), stored())
    }

    // ------------------------------------------------------- somebody new

    @Test
    fun `somebody new - one username, once, and the account is made and signed in`() {
        server.googleMode = FakeOwnifyServer.GoogleMode.NEW
        openApp()

        googleStep()

        // Nothing exists yet: the step, with the address Google vouched for, and how long it waits.
        assertEquals(GoogleUsernameStep("nieuw@gmail.com", 10), OwnifyConnection.googleStep)
        assertEquals(OwnifyAuthState.Idle, OwnifyConnection.auth)
        assertNull("nothing stored before the account exists", stored())
        assertTrue(OwnifyConnection.state is OwnifyState.NotConnected)

        chooseUsername("  nieuw ")

        val username = server.google("username").single()
        assertEquals("nieuw", username.body.getString("username"))
        assertEquals("android", username.body.getString("platform"))
        assertTrue(sessionOf(username) != null)
        assertNotEquals("in the session the server gave with the step, not the first one",
            sessionOf(server.google("verify").single()), sessionOf(username))

        assertNull(OwnifyConnection.googleStep)
        assertEquals(OwnifyCredential(ACCOUNT_TOKEN, OwnifyScope.ACCOUNT), stored())
        assertEquals("nieuw", connected!!.profile.username)
        assertEquals("the ID token was sent once", 1, server.google("verify").size)
        assertEquals(0, server.openGoogleSessions)
        assertEquals(WorkInfo.State.ENQUEUED, periodic())
    }

    @Test
    fun `the username step - empty is not sent, taken keeps the step with the server's words, then a free one works`() {
        server.googleMode = FakeOwnifyServer.GoogleMode.NEW
        openApp()
        googleStep()

        assertEquals("Kies een gebruikersnaam.", usernameRefused("   "))
        assertTrue(server.google("username").isEmpty())

        assertEquals("Deze gebruikersnaam is al bezet.", usernameRefused("bezet"))
        assertEquals("still on the step", GoogleUsernameStep("nieuw@gmail.com", 10), OwnifyConnection.googleStep)
        assertNull(stored())

        chooseUsername("vrij")
        assertEquals(OwnifyCredential(ACCOUNT_TOKEN, OwnifyScope.ACCOUNT), stored())
        assertEquals("vrij", connected!!.profile.username)
        assertEquals("Google was asked once", 1, FakeGoogle.asked.size)
    }

    @Test
    fun `the username step ran out on the server - back to the start with its words, and starting again works`() {
        server.googleMode = FakeOwnifyServer.GoogleMode.NEW
        openApp()
        googleStep()

        server.googleStepExpired = true
        assertEquals("Je Google-aanmelding is verlopen. Begin opnieuw met Google.", usernameRefused("nieuw"))
        assertNull("the step is gone", OwnifyConnection.googleStep)
        assertNull(stored())

        server.googleStepExpired = false
        googleStep()
        assertEquals(GoogleUsernameStep("nieuw@gmail.com", 10), OwnifyConnection.googleStep)
    }

    @Test
    fun `Annuleren on the username step - nothing is made, and the server forgets who Google said it was`() {
        server.googleMode = FakeOwnifyServer.GoogleMode.NEW
        openApp()
        googleStep()
        val waiting = sessionOf(server.google("verify").single())

        OwnifyConnection.cancelGoogle()

        assertNull(OwnifyConnection.googleStep)
        assertEquals(OwnifyAuthState.Idle, OwnifyConnection.auth)
        waitUntil("the server was told") { server.google("cancel").size == 1 && server.openGoogleSessions == 0 }
        val cancel = server.google("cancel").single()
        assertTrue(sessionOf(cancel) != null)
        assertNotEquals("the waiting person's session, as the server renamed it", waiting, sessionOf(cancel))
        assertNull(stored())
        assertTrue(server.google("username").isEmpty())
        assertTrue(OwnifyConnection.canSignIn)
    }

    // ------------------------------------------------ refused, and trouble

    @Test
    fun `an address a password account already has - refused with the server's words, never merged, nothing stored`() {
        server.googleMode = FakeOwnifyServer.GoogleMode.CONFLICT
        openApp()

        assertEquals("Er bestaat al een account met dit e-mailadres. Log in met je wachtwoord en koppel Google via Instellingen.", googleRefused())
        assertNull(stored())
        assertNull("no account is made either", OwnifyConnection.googleStep)
        assertTrue(OwnifyConnection.state is OwnifyState.NotConnected)
        assertTrue(server.requestsTo("profile.php").isEmpty())
        assertTrue(OwnifyConnection.canSignIn)
    }

    @Test
    fun `a token the server cannot verify - for another nonce or another client - one sentence, and nothing stored`() {
        openApp()

        FakeGoogle.answer = { audience, _ -> GoogleIdResult.Token(fakeGoogleIdToken(audience, "an-earlier-nonce")) }
        assertEquals("Inloggen met Google is niet gelukt. Probeer het opnieuw.", googleRefused())

        FakeGoogle.answer = { _, nonce -> GoogleIdResult.Token(fakeGoogleIdToken("2222-another-app.apps.googleusercontent.com", nonce)) }
        assertEquals("Inloggen met Google is niet gelukt. Probeer het opnieuw.", googleRefused())

        assertNull(stored())
        assertTrue(OwnifyConnection.state is OwnifyState.NotConnected)
    }

    @Test
    fun `the chooser closed - no message, and nothing goes to the server`() {
        openApp()
        FakeGoogle.answer = { _, _ -> GoogleIdResult.Cancelled }

        google("the chooser closed") { FakeGoogle.asked.size == 1 && OwnifyConnection.auth == OwnifyAuthState.Idle }

        assertEquals(OwnifyAuthState.Idle, OwnifyConnection.auth)
        assertTrue(server.google("verify").isEmpty())
        assertNull(stored())
        assertTrue(OwnifyConnection.canSignIn)
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
        assertTrue(OwnifyConnection.canSignIn)
    }

    @Test
    fun `offline, or Google's keys out of reach for the server - what kind of trouble, and nothing stored`() {
        openApp()

        OwnifyConnection.api = OwnifyApi("http://127.0.0.1:9/")
        assertEquals("Kan Ownify niet bereiken. Controleer je internetverbinding en probeer het opnieuw.", googleRefused())
        assertTrue("Google is not asked when Ownify cannot be reached", FakeGoogle.asked.isEmpty())

        OwnifyConnection.api = OwnifyApi(server.url)
        server.googleMode = FakeOwnifyServer.GoogleMode.GOOGLE_DOWN
        assertEquals("Google kon je aanmelding niet bevestigen. Probeer het opnieuw.", googleRefused())

        assertNull(stored())
    }

    @Test
    fun `a paired phone whose Google sign-in fails keeps its pairing and its sync`() {
        server.googleMode = FakeOwnifyServer.GoogleMode.CONFLICT
        MemoryTokenStorage.token = TEST_TOKEN
        openApp()

        googleRefused()
        assertEquals(OwnifyCredential(TEST_TOKEN, OwnifyScope.SYNC), stored())
        assertEquals(OwnifyScope.SYNC, connected!!.scope)
        assertFalse(TEST_TOKEN in server.revoked)
    }

    @Test
    fun `Google and the server say yes, but the phone cannot keep the session - nothing changes, and it says so`() {
        OwnifyConnection.storage = { Unsavable }
        OwnifyConnection.reset()
        openApp()

        assertEquals("Deze telefoon kon je sessie niet veilig bewaren. Log opnieuw in.", googleRefused())

        assertEquals("the server did sign in", 1, server.google("verify").size)
        assertTrue(OwnifyConnection.state is OwnifyState.NotConnected)
        assertTrue("nothing is read with a token the phone does not have", server.requestsTo("profile.php").isEmpty())
        assertNull(periodic())
    }

    /** A Keystore that refuses to keep anything. */
    private object Unsavable : OwnifyTokenStorage {
        override fun load(): OwnifyCredential? = null
        override fun save(credential: OwnifyCredential) = throw IllegalStateException("no key")
        override fun clear() = Unit
    }

    @Test
    fun `not set up on the server - the button's check says so, and pressing anyway says so without asking Google`() {
        server.googleMode = FakeOwnifyServer.GoogleMode.NOT_CONFIGURED
        openApp()
        assertNull("not asked yet", OwnifyConnection.googleAvailable)

        OwnifyConnection.checkGoogle()
        waitUntil("the server answered") { OwnifyConnection.googleAvailable != null }
        assertEquals(false, OwnifyConnection.googleAvailable)

        assertEquals("Inloggen met Google is in de app nog niet beschikbaar.", googleRefused())
        assertTrue(FakeGoogle.asked.isEmpty())

        server.googleMode = FakeOwnifyServer.GoogleMode.EXISTING
        OwnifyConnection.checkGoogle()
        waitUntil("the server answered again") { OwnifyConnection.googleAvailable == true }
    }

    @Test
    fun `no ID token or nonce appears in text`() {
        assertFalse(GoogleIdResult.Token("eyJ.secret.token").toString().contains("secret"))
        assertFalse(GoogleStart("the-nonce", GOOGLE_WEB_CLIENT).toString().contains("the-nonce"))
    }
}
