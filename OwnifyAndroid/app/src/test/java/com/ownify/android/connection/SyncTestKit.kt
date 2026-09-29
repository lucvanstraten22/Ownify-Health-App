package com.ownify.android.connection

import android.content.Context
import android.os.Looper
import androidx.health.connect.client.records.Record
import com.sun.net.httpserver.HttpExchange
import com.sun.net.httpserver.HttpServer
import java.net.InetSocketAddress
import java.time.Instant
import java.util.concurrent.CopyOnWriteArrayList
import java.util.concurrent.CountDownLatch
import java.util.concurrent.Executors
import java.util.concurrent.TimeUnit
import org.json.JSONObject
import org.robolectric.Shadows.shadowOf

/** A device token in the format pair.php issues. */
const val TEST_TOKEN = "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef"

/** An account token in the format app-login.php and app-register.php issue. */
const val ACCOUNT_TOKEN = "fedcba9876543210fedcba9876543210fedcba9876543210fedcba9876543210"

/** The Ownify server's Web client, which app-google.php names with its nonce: the ID token's audience. */
const val GOOGLE_WEB_CLIENT = "1111-web.apps.googleusercontent.com"

/**
 * An ID token as the stand-in for Google issues it, and FakeOwnifyServer checks
 * it: for [audience], with [nonce]. The real server checks the same two
 * things (and Google's signature, which a stand-in cannot have).
 */
fun fakeGoogleIdToken(audience: String, nonce: String): String = "google-id-token|$audience|$nonce"

/**
 * The credential kept in memory instead of the Android Keystore, which the
 * JVM does not have. Everything that reads or forgets it is the real code.
 * Setting only [token] means what it always did: a phone paired with a code.
 */
internal object MemoryTokenStorage : OwnifyTokenStorage {
    @Volatile
    var token: String? = null

    @Volatile
    var scope: OwnifyScope = OwnifyScope.SYNC

    override fun load(): OwnifyCredential? = token?.let { OwnifyCredential(it, scope) }

    override fun save(credential: OwnifyCredential) {
        synchronized(this) {
            token = credential.token
            scope = credential.scope
        }
    }

    override fun clear() {
        synchronized(this) {
            token = null
            scope = OwnifyScope.SYNC
        }
    }

    fun signedIn(token: String = ACCOUNT_TOKEN) {
        this.token = token
        scope = OwnifyScope.ACCOUNT
    }
}

/**
 * The phone as the tests see it: the real token handling (OwnifyConnection over
 * [MemoryTokenStorage]), the real status file, the real OwnifyApi — pointed at
 * [api] — and Health Connect as the only stand-in, since the JVM has none.
 */
internal object TestSyncEnvironment : OwnifySyncEnvironment {
    lateinit var context: Context

    override var api: OwnifyApi = OwnifyApi("http://127.0.0.1:1/")

    /** What Health Connect would say about access. */
    @Volatile
    var access: HealthAccess = HealthAccess.AVAILABLE

    /** What Health Connect would return. */
    @Volatile
    var records: List<Record> = emptyList()

    @Volatile
    var reads = 0

    override val status: OwnifySyncStatusStore get() = OwnifySyncStatusPrefs(context)

    override suspend fun token(): String? = OwnifyConnection.storedToken(context)

    override suspend fun tokenRejected(token: String) = OwnifyConnection.rejected(context, token)

    override suspend fun healthAccess(automatic: Boolean): HealthAccess = access

    override suspend fun read(from: Instant, to: Instant): HealthConnectSyncReader.Reading {
        reads++
        return HealthConnectSyncReader.Reading(records, emptyList())
    }
}

/**
 * Google's side of signing in, stood in for: the account chooser, and what
 * it hands back. By default somebody picks their account and gets an ID token
 * for exactly the audience and nonce the app asked for — which FakeOwnifyServer
 * then checks, as the real server does. Set [answer] for anything else: the
 * chooser closed, no account on the phone, a token for the wrong nonce.
 */
internal object FakeGoogle : GoogleIdTokens {

    private val picksAnAccount: (String, String) -> GoogleIdResult =
        { audience, nonce -> GoogleIdResult.Token(fakeGoogleIdToken(audience, nonce)) }

    /** What the chooser does next: from the audience and nonce asked for, to what Google gives back. */
    @Volatile
    var answer: (audience: String, nonce: String) -> GoogleIdResult = picksAnAccount

    /** Every time the app asked: the audience (the server's Web client) and the nonce. */
    val asked = CopyOnWriteArrayList<Pair<String, String>>()

    /** What showed the chooser each time: it has to be an activity on a phone. */
    val shownFrom = CopyOnWriteArrayList<Context>()

    /** How often the app told Credential Manager it signed out. */
    @Volatile
    var signedOut = 0

    override suspend fun request(activity: Context, serverClientId: String, nonce: String): GoogleIdResult {
        asked += serverClientId to nonce
        shownFrom += activity
        return answer(serverClientId, nonce)
    }

    override suspend fun signedOut(context: Context) {
        signedOut++
    }

    fun reset() {
        answer = picksAnAccount
        asked.clear()
        shownFrom.clear()
        signedOut = 0
    }
}

/**
 * A Ownify server on localhost that answers like the real one and remembers
 * every request.
 *
 * Tokens work as on the real server: [TEST_TOKEN] is what pairing issues,
 * [ACCOUNT_TOKEN] what signing in issues — and signing in with the paired
 * phone's token as the bearer turns that phone's row into the account row,
 * so [TEST_TOKEN] stops working ([revoked]), as does a signed-out token.
 */
class FakeOwnifyServer {

    class Request(val path: String, val headers: Map<String, String>, val body: JSONObject)

    enum class IngestMode { OK, UNAUTHORIZED, SERVER_ERROR }

    /** How app-login.php answers. */
    enum class LoginMode { OK, WRONG_PASSWORD, THROTTLED, SERVER_ERROR, NOT_AN_ACCOUNT_TOKEN }

    /** Who app-google.php finds once it has verified the ID token. */
    enum class GoogleMode {
        /** A Ownify account has this Google account: the demo account, sanne. */
        EXISTING,

        /** Nobody has it yet: somebody new, who chooses a username. */
        NEW,

        /** A password account already has the address Google gave: refused (409), never merged. */
        CONFLICT,

        /** The server offers no Google sign-in to the app: `available` false, and 501. */
        NOT_CONFIGURED,

        /** Google's keys could not be fetched: 502. */
        GOOGLE_DOWN
    }

    @Volatile
    var ingestMode = IngestMode.OK

    @Volatile
    var loginMode = LoginMode.OK

    @Volatile
    var googleMode = GoogleMode.EXISTING

    /** The address Google vouches for, in the ID token. */
    @Volatile
    var googleEmail = "nieuw@gmail.com"

    /** The username step has run out on the server, as after its ten minutes. */
    @Volatile
    var googleStepExpired = false

    /**
     * app-google.php's sessions by their ownify_session cookie, as PHP keeps
     * them: the nonce handed out, or somebody new waiting to choose a
     * username. A new id at every step, as session_regenerate_id() gives.
     */
    private val googleSessions = java.util.concurrent.ConcurrentHashMap<String, GoogleSession>()

    private val googleIds = java.util.concurrent.atomic.AtomicInteger()

    private sealed interface GoogleSession {
        class Started(val nonce: String) : GoogleSession
        class Waiting(val email: String) : GoogleSession
    }

    /** Tokens the server no longer accepts: replaced by a sign-in, signed out, or revoked on the website. */
    val revoked: MutableSet<String> = java.util.concurrent.ConcurrentHashMap.newKeySet()

    /**
     * When set, an ingest is held after it arrives — until the gate opens —
     * and only then answered, by the tokens as they are at that moment: a
     * sync caught mid-flight while the phone signs in.
     */
    @Volatile
    var ingestGate: CountDownLatch? = null

    /** Counts down each time an ingest arrives. */
    @Volatile
    var ingestArrived = CountDownLatch(1)

    /** What api/app/state.php answers a working account token with: the demo account's pages. */
    @Volatile
    var stateBody: String = FakeOwnifyServer::class.java.classLoader!!.getResource("state-demo.json").readText()

    /**
     * An endpoint ("state.php", "rating.php", …) whose requests are held after
     * they arrive — until [holdGate] opens — and only then answered, by the
     * tokens as they are at that moment: a read or a write caught mid-flight.
     */
    @Volatile
    var hold: String? = null

    @Volatile
    var holdGate = CountDownLatch(1)

    /** Counts down when a held request arrives. */
    @Volatile
    var held = CountDownLatch(1)

    /** The account the last sign-in was for, whose profile profile.php answers with. */
    @Volatile
    var accountUsername = "sanne"

    val requests = CopyOnWriteArrayList<Request>()

    val ingests: List<Request> get() = requests.filter { it.path.endsWith("/ingest.php") }

    fun requestsTo(endpoint: String): List<Request> = requests.filter { it.path.endsWith("/$endpoint") }

    /** The requests to app-google.php with this action: status, nonce, verify, username or cancel. */
    fun google(action: String): List<Request> = requestsTo("app-google.php").filter { it.body.optString("action") == action }

    /** The nonces app-google.php handed out, in order. */
    val googleNonces = CopyOnWriteArrayList<String>()

    /** How many sign-ins with Google are still open on the server: a nonce not used, or a username not chosen. */
    val openGoogleSessions: Int get() = googleSessions.size

    private val server: HttpServer = HttpServer.create(InetSocketAddress("127.0.0.1", 0), 0).apply {
        createContext("/") { exchange -> handle(exchange) }
        // Several at once, like a real server: a held ingest must not hold up a sign-in.
        executor = Executors.newCachedThreadPool()
        start()
    }

    val url: String get() = "http://127.0.0.1:${server.address.port}/"

    fun stop() = server.stop(0)

    private fun handle(exchange: HttpExchange) {
        val body = exchange.requestBody.readBytes().toString(Charsets.UTF_8)
        val path = exchange.requestURI.path
        val headers = exchange.requestHeaders.mapKeys { it.key.lowercase() }.mapValues { it.value.joinToString(",") }
        // The app's writes are forms, like the website's; everything else is JSON.
        requests += Request(path, headers, runCatching { JSONObject(body.ifEmpty { "{}" }) }.getOrElse { JSONObject().put("form", body) })

        hold?.let { endpoint ->
            if (path.endsWith("/$endpoint")) {
                held.countDown()
                holdGate.await(30, TimeUnit.SECONDS)
            }
        }

        val bearer = headers["authorization"]?.removePrefix("Bearer ")
        val tokenWorks = bearer != null && bearer !in revoked

        when {
            path.endsWith("/pair.php") -> reply(exchange, 200, """{"ok":true,"token":"$TEST_TOKEN"}""")
            path.endsWith("/app-login.php") -> login(exchange, bearer)
            path.endsWith("/app-google.php") -> answerGoogle(exchange, JSONObject(body.ifEmpty { "{}" }), bearer, headers["cookie"])
            path.endsWith("/app-register.php") && JSONObject(body).optString("username") == "bezet" ->
                reply(exchange, 422, """{"ok":false,"error":"Deze gebruikersnaam is al bezet."}""")
            // A new account: a token of the one before is somebody else's, and left alone.
            path.endsWith("/app-register.php") -> signedIn(exchange, bearer = null, JSONObject(body).optString("username"))
            path.endsWith("/app-logout.php") -> {
                val revokedNow = bearer != null && bearer !in revoked
                bearer?.let { revoked += it }
                reply(exchange, 200, """{"ok":true,"revoked":$revokedNow}""")
            }
            !tokenWorks && (path.endsWith("/profile.php") || path.endsWith("/nutrition-targets.php")) ->
                reply(exchange, 401, """{"ok":false,"error":"Dit apparaat is niet gekoppeld."}""")
            path.endsWith("/profile.php") -> reply(exchange, 200,
                """{"ok":true,"account":{"username":"$accountUsername","age":36,"gender":"female","height_cm":172.5,"weight_kg":67.4,"activity_level":"moderate"}}""")
            path.endsWith("/nutrition-targets.php") -> reply(exchange, 200,
                """{"ok":true,"targets":{"calorie_target_kcal":2100,"protein_target_g":110,"bmr_kcal":1400,"tdee_kcal":2170}}""")
            path.endsWith("/ingest.php") -> {
                ingestArrived.countDown()
                ingestGate?.await(30, TimeUnit.SECONDS)

                // Decided now, not when it arrived: the token may have been replaced meanwhile.
                val works = bearer != null && bearer !in revoked

                when {
                    ingestMode == IngestMode.UNAUTHORIZED || !works ->
                        reply(exchange, 401, """{"ok":false,"error":"Dit apparaat is niet gekoppeld."}""")
                    ingestMode == IngestMode.SERVER_ERROR ->
                        reply(exchange, 503, """{"ok":false,"error":"Onderhoud."}""")
                    else -> {
                        val count = JSONObject(body).getJSONArray("records").length()
                        reply(exchange, 200,
                            """{"ok":true,"written":$count,"skipped":0,"days":["2026-09-25"],"unmapped":[],"problems":[],"points":[],"scores":null}""")
                    }
                }
            }
            // The app's one read: its pages, for an account token only.
            path.endsWith("/app/state.php") -> {
                val works = bearer != null && bearer !in revoked
                when {
                    !works -> reply(exchange, 401, """{"ok":false,"error":"Log opnieuw in."}""")
                    bearer == TEST_TOKEN -> reply(exchange, 403, """{"ok":false,"error":"Deze koppeling mag je account niet lezen."}""")
                    else -> reply(exchange, 200, stateBody)
                }
            }
            // Two of the app's writes, with the account token.
            path.endsWith("/health/rating.php") || path.endsWith("/profile/username.php") -> {
                val works = bearer != null && bearer !in revoked && bearer != TEST_TOKEN
                if (works) reply(exchange, 200, """{"ok":true,"message":"Opgeslagen."}""")
                else reply(exchange, 401, """{"ok":false,"error":"Log opnieuw in."}""")
            }
            else -> reply(exchange, 404, """{"ok":false}""")
        }
    }

    private fun login(exchange: HttpExchange, bearer: String?) {
        when (loginMode) {
            LoginMode.OK -> signedIn(exchange, bearer, "sanne")
            LoginMode.WRONG_PASSWORD ->
                reply(exchange, 401, """{"ok":false,"error":"Gebruikersnaam of wachtwoord klopt niet."}""")
            LoginMode.THROTTLED -> {
                exchange.responseHeaders.add("Retry-After", "840")
                reply(exchange, 429, """{"ok":false,"error":"Te veel mislukte pogingen. Probeer het over 14 minuten opnieuw."}""")
            }
            LoginMode.SERVER_ERROR ->
                reply(exchange, 500, """{"ok":false,"error":"Er ging iets mis op de server. Probeer het opnieuw."}""")
            LoginMode.NOT_AN_ACCOUNT_TOKEN ->
                reply(exchange, 200, """{"ok":true,"token":"$ACCOUNT_TOKEN","scope":"sync","provider":"password","account":{"username":"sanne"}}""")
        }
    }

    /**
     * app-google.php as the real one answers, with its session in a
     * ownify_session cookie: status; a nonce with the Web client's id; the ID
     * token checked for that audience and that nonce — once, whatever the
     * outcome — then signed in, a username to choose, or refused; the
     * username; cancel. A step without the session it belongs to is refused.
     */
    private fun answerGoogle(exchange: HttpExchange, input: JSONObject, bearer: String?, cookie: String?) {
        val action = input.optString("action")

        if (action == "status") {
            val available = googleMode != GoogleMode.NOT_CONFIGURED
            return reply(exchange, 200, """{"ok":true,"available":$available}""")
        }

        if (googleMode == GoogleMode.NOT_CONFIGURED) {
            return reply(exchange, 501, """{"ok":false,"error":"Inloggen met Google is in de app nog niet beschikbaar."}""")
        }

        val id = cookie?.split(';')?.map { it.trim() }?.firstOrNull { it.startsWith("ownify_session=") }?.substringAfter('=')
        val session = id?.let { googleSessions.remove(it) }

        /** A new session id, as PHP's session_regenerate_id() gives: the old one is gone. */
        fun renew(next: GoogleSession?) {
            val fresh = "sess${googleIds.incrementAndGet()}"
            next?.let { googleSessions[fresh] = it }
            exchange.responseHeaders.add("Set-Cookie", "ownify_session=$fresh; path=/; secure; HttpOnly; SameSite=Lax")
        }

        when (action) {
            "nonce" -> {
                val nonce = "nonce-${googleIds.incrementAndGet()}-${java.util.UUID.randomUUID()}"
                googleNonces += nonce
                renew(GoogleSession.Started(nonce))
                reply(exchange, 200, """{"ok":true,"nonce":"$nonce","expires_in":600,"client_id":"$GOOGLE_WEB_CLIENT"}""")
            }

            "verify" -> {
                if (session !is GoogleSession.Started) {
                    return reply(exchange, 410, """{"ok":false,"error":"Deze aanmelding is verlopen of al gebruikt. Begin opnieuw."}""")
                }
                if (googleMode == GoogleMode.GOOGLE_DOWN) {
                    return reply(exchange, 502, """{"ok":false,"error":"Google kon je aanmelding niet bevestigen. Probeer het opnieuw."}""")
                }
                if (input.optString("id_token") != fakeGoogleIdToken(GOOGLE_WEB_CLIENT, session.nonce)) {
                    return reply(exchange, 401, """{"ok":false,"error":"Inloggen met Google is niet gelukt. Probeer het opnieuw."}""")
                }

                when (googleMode) {
                    GoogleMode.EXISTING -> {
                        renew(null)
                        signedIn(exchange, bearer, "sanne", provider = "google", extra = "\"status\":\"signed_in\",")
                    }
                    GoogleMode.CONFLICT -> reply(exchange, 409,
                        """{"ok":false,"error":"Er bestaat al een account met dit e-mailadres. Log in met je wachtwoord en koppel Google via Instellingen."}""")
                    else -> {
                        renew(GoogleSession.Waiting(googleEmail))
                        reply(exchange, 200, """{"ok":true,"status":"choose_username","email":"$googleEmail","expires_in":600}""")
                    }
                }
            }

            "username" -> {
                val name = input.optString("username")
                when {
                    session !is GoogleSession.Waiting || googleStepExpired ->
                        reply(exchange, 410, """{"ok":false,"error":"Je Google-aanmelding is verlopen. Begin opnieuw met Google.","expired":true}""")
                    name == "bezet" -> {
                        renew(session)
                        reply(exchange, 422, """{"ok":false,"error":"Deze gebruikersnaam is al bezet.","expired":false}""")
                    }
                    else -> {
                        renew(null)
                        signedIn(exchange, bearer, name, provider = "google", extra = "\"status\":\"signed_in\",")
                    }
                }
            }

            "cancel" -> {
                renew(null)
                reply(exchange, 200, """{"ok":true}""")
            }

            else -> reply(exchange, 400, """{"ok":false,"error":"Onbekende actie."}""")
        }
    }

    /** The phone's own paired token of this account, sent along, is replaced: that row becomes the account row. */
    private fun signedIn(exchange: HttpExchange, bearer: String?, username: String, provider: String = "password", extra: String = "") {
        if (bearer == TEST_TOKEN) {
            revoked += TEST_TOKEN
        }

        revoked -= ACCOUNT_TOKEN
        accountUsername = username
        reply(exchange, 200,
            """{"ok":true,$extra"token":"$ACCOUNT_TOKEN","scope":"account","provider":"$provider","account":{"username":"$username","avatar":null,"created_at":"2026-09-27 12:00:00","age":36}}""")
    }

    private fun reply(exchange: HttpExchange, status: Int, body: String) {
        val bytes = body.toByteArray(Charsets.UTF_8)
        exchange.responseHeaders.add("Content-Type", "application/json; charset=utf-8")
        exchange.sendResponseHeaders(status, bytes.size.toLong())
        exchange.responseBody.use { it.write(bytes) }
    }
}

/**
 * Lets the main thread and the IO threads take turns until [done]: coroutines
 * on Dispatchers.Main only run when Robolectric's main looper is idled.
 */
fun waitUntil(what: String, timeoutMs: Long = 15_000, done: () -> Boolean) {
    val until = System.currentTimeMillis() + timeoutMs
    while (System.currentTimeMillis() < until) {
        shadowOf(Looper.getMainLooper()).idle()
        if (done()) return
        Thread.sleep(10)
    }
    throw AssertionError("Timed out waiting for: $what")
}
