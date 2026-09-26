package com.healthapp.android.jolu

import android.content.Context
import android.os.Looper
import androidx.health.connect.client.records.Record
import com.sun.net.httpserver.HttpExchange
import com.sun.net.httpserver.HttpServer
import java.net.InetSocketAddress
import java.time.Instant
import java.util.concurrent.CopyOnWriteArrayList
import org.json.JSONObject
import org.robolectric.Shadows.shadowOf

/** A device token in the format pair.php issues. */
const val TEST_TOKEN = "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef"

/**
 * The token kept in memory instead of the Android Keystore, which the JVM
 * does not have. Everything that reads or forgets it is the real code.
 */
internal object MemoryTokenStorage : JoluTokenStorage {
    @Volatile
    var token: String? = null

    override fun load(): String? = token
    override fun save(token: String) {
        this.token = token
    }
    override fun clear() {
        token = null
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

    override suspend fun tokenRejected() = JoluConnection.unauthorized(context)

    override suspend fun healthAccess(automatic: Boolean): HealthAccess = access

    override suspend fun read(from: Instant, to: Instant): HealthConnectSyncReader.Reading {
        reads++
        return HealthConnectSyncReader.Reading(records, emptyList())
    }
}

/** A JoLu server on localhost that answers like the real one and remembers every request. */
class FakeJoluServer {

    class Request(val path: String, val headers: Map<String, String>, val body: JSONObject)

    enum class IngestMode { OK, UNAUTHORIZED, SERVER_ERROR }

    @Volatile
    var ingestMode = IngestMode.OK

    val requests = CopyOnWriteArrayList<Request>()

    val ingests: List<Request> get() = requests.filter { it.path.endsWith("/ingest.php") }

    private val server: HttpServer = HttpServer.create(InetSocketAddress("127.0.0.1", 0), 0).apply {
        createContext("/") { exchange -> handle(exchange) }
        start()
    }

    val url: String get() = "http://127.0.0.1:${server.address.port}/"

    fun stop() = server.stop(0)

    private fun handle(exchange: HttpExchange) {
        val body = exchange.requestBody.readBytes().toString(Charsets.UTF_8)
        val path = exchange.requestURI.path
        requests += Request(
            path,
            exchange.requestHeaders.mapKeys { it.key.lowercase() }.mapValues { it.value.joinToString(",") },
            JSONObject(body.ifEmpty { "{}" })
        )

        when {
            path.endsWith("/pair.php") -> reply(exchange, 200, """{"ok":true,"token":"$TEST_TOKEN"}""")
            path.endsWith("/profile.php") -> reply(exchange, 200,
                """{"ok":true,"account":{"username":"sanne","age":36,"gender":"female","height_cm":172.5,"weight_kg":67.4,"activity_level":"moderate"}}""")
            path.endsWith("/nutrition-targets.php") -> reply(exchange, 200,
                """{"ok":true,"targets":{"calorie_target_kcal":2100,"protein_target_g":110,"bmr_kcal":1400,"tdee_kcal":2170}}""")
            path.endsWith("/ingest.php") -> when (ingestMode) {
                IngestMode.OK -> {
                    val count = JSONObject(body).getJSONArray("records").length()
                    reply(exchange, 200,
                        """{"ok":true,"written":$count,"skipped":0,"days":["2026-09-25"],"unmapped":[],"problems":[],"points":[],"scores":null}""")
                }
                IngestMode.UNAUTHORIZED -> reply(exchange, 401, """{"ok":false,"error":"Dit apparaat is niet gekoppeld."}""")
                IngestMode.SERVER_ERROR -> reply(exchange, 503, """{"ok":false,"error":"Onderhoud."}""")
            }
            else -> reply(exchange, 404, """{"ok":false}""")
        }
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
