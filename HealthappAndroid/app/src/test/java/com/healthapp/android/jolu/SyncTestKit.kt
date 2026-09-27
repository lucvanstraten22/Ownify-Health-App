package com.healthapp.android.jolu

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

/**
 * The credential kept in memory instead of the Android Keystore, which the
 * JVM does not have. Everything that reads or forgets it is the real code.
 * Setting only [token] means what it always did: a phone paired with a code.
 */
internal object MemoryTokenStorage : JoluTokenStorage {
    @Volatile
    var token: String? = null

    @Volatile
    var scope: JoluScope = JoluScope.SYNC

    override fun load(): JoluCredential? = token?.let { JoluCredential(it, scope) }

    override fun save(credential: JoluCredential) {
        synchronized(this) {
            token = credential.token
            scope = credential.scope
        }
    }

    override fun clear() {
        synchronized(this) {
            token = null
            scope = JoluScope.SYNC
        }
    }

    fun signedIn(token: String = ACCOUNT_TOKEN) {
        this.token = token
        scope = JoluScope.ACCOUNT
    }
}

/**
 * The phone as the tests see it: the real token handling (JoluConnection over
 * [MemoryTokenStorage]), the real status file, the real JoluApi — pointed at
 * [api] — and Health Connect as the only stand-in, since the JVM has none.
 */
internal object TestSyncEnvironment : JoluSyncEnvironment {
    lateinit var context: Context

    override var api: JoluApi = JoluApi("http://127.0.0.1:1/")

    /** What Health Connect would say about access. */
    @Volatile
    var access: HealthAccess = HealthAccess.AVAILABLE

    /** What Health Connect would return. */
    @Volatile
    var records: List<Record> = emptyList()

    @Volatile
    var reads = 0

    override val status: JoluSyncStatusStore get() = JoluSyncStatusPrefs(context)

    override suspend fun token(): String? = JoluConnection.storedToken(context)

    override suspend fun tokenRejected(token: String) = JoluConnection.rejected(context, token)

    override suspend fun healthAccess(automatic: Boolean): HealthAccess = access

    override suspend fun read(from: Instant, to: Instant): HealthConnectSyncReader.Reading {
        reads++
        return HealthConnectSyncReader.Reading(records, emptyList())
    }
}

/**
 * A JoLu server on localhost that answers like the real one and remembers
 * every request.
 *
 * Tokens work as on the real server: [TEST_TOKEN] is what pairing issues,
 * [ACCOUNT_TOKEN] what signing in issues — and signing in with the paired
 * phone's token as the bearer turns that phone's row into the account row,
 * so [TEST_TOKEN] stops working ([revoked]), as does a signed-out token.
 */
class FakeJoluServer {

    class Request(val path: String, val headers: Map<String, String>, val body: JSONObject)

    enum class IngestMode { OK, UNAUTHORIZED, SERVER_ERROR }

    /** How app-login.php answers. */
    enum class LoginMode { OK, WRONG_PASSWORD, THROTTLED, SERVER_ERROR, NOT_AN_ACCOUNT_TOKEN }

    @Volatile
    var ingestMode = IngestMode.OK

    @Volatile
    var loginMode = LoginMode.OK

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

    val requests = CopyOnWriteArrayList<Request>()

    val ingests: List<Request> get() = requests.filter { it.path.endsWith("/ingest.php") }

    fun requestsTo(endpoint: String): List<Request> = requests.filter { it.path.endsWith("/$endpoint") }

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
        requests += Request(path, headers, JSONObject(body.ifEmpty { "{}" }))

        val bearer = headers["authorization"]?.removePrefix("Bearer ")
        val tokenWorks = bearer != null && bearer !in revoked

        when {
            path.endsWith("/pair.php") -> reply(exchange, 200, """{"ok":true,"token":"$TEST_TOKEN"}""")
            path.endsWith("/app-login.php") -> login(exchange, bearer)
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
                """{"ok":true,"account":{"username":"sanne","age":36,"gender":"female","height_cm":172.5,"weight_kg":67.4,"activity_level":"moderate"}}""")
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

    /** The phone's own paired token of this account, sent along, is replaced: that row becomes the account row. */
    private fun signedIn(exchange: HttpExchange, bearer: String?, username: String) {
        if (bearer == TEST_TOKEN) {
            revoked += TEST_TOKEN
        }

        revoked -= ACCOUNT_TOKEN
        reply(exchange, 200,
            """{"ok":true,"token":"$ACCOUNT_TOKEN","scope":"account","provider":"password","account":{"username":"$username","avatar":null,"created_at":"2026-09-27 12:00:00","age":36}}""")
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
