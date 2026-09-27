package com.healthapp.android.jolu

import java.io.ByteArrayOutputStream
import java.io.InputStream
import java.net.HttpURLConnection
import java.net.URI
import java.net.URLEncoder
import kotlin.math.roundToInt
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONArray
import org.json.JSONException
import org.json.JSONObject

/**
 * The JoLu endpoints this app uses: pairing, profile, nutrition targets and
 * ingest for the sync, and signing in, registering and signing out as an
 * account (docs/APP-AUTH.md on the server).
 *
 * Every call is a POST with a JSON body, as the backend requires. A token
 * travels only in the Authorization header, and no request ever carries a
 * user id: whose data comes back is decided by the server from the token
 * alone.
 *
 * HttpURLConnection and org.json are both part of Android, so these small
 * calls add no library to the app. Nothing here logs — not the pairing code,
 * not a password, not a token, not the profile.
 */
class JoluApi(private val baseUrl: String = BASE_URL) {

    /** The website itself, for the few things only it can do (linking Google, a provider's consent screen). */
    val siteUrl: String get() = baseUrl


    /** Exchanges a pairing code from the JoLu website for this phone's own token. */
    suspend fun pair(code: String, label: String): JoluResult<String> {
        val body = JSONObject()
            .put("code", code)
            .put("label", label)
            .put("platform", "android")

        return post("api/integrations/pair.php", body, token = null) { json ->
            (json.opt("token") as? String)?.takeIf { TOKEN_FORMAT.matches(it) }
        }
    }

    /**
     * Signs in as an account with a username (or e-mail address) and password.
     *
     * [current] is the token this phone already holds, if any. It goes along
     * as the bearer token so the server can turn this phone's row into the
     * account row instead of adding a second one; the server leaves a token
     * of another account alone. A 401 here means the name or password was
     * wrong — never that [current] is.
     */
    suspend fun login(identifier: String, password: String, label: String, current: String?): JoluResult<JoluSession> {
        val body = JSONObject()
            .put("identifier", identifier)
            .put("password", password)
            .put("label", label)
            .put("platform", "android")

        return post("api/auth/app-login.php", body, current) { json -> readSession(json) }
    }

    /** Creates an account and signs in as it, exactly as [login] does afterwards. */
    suspend fun register(
        email: String,
        username: String,
        password: String,
        label: String,
        current: String?
    ): JoluResult<JoluSession> {
        val body = JSONObject()
            .put("email", email)
            .put("username", username)
            .put("password", password)
            .put("label", label)
            .put("platform", "android")

        return post("api/auth/app-register.php", body, current) { json -> readSession(json) }
    }

    /**
     * Signs this account token out on the server. Success whatever state the
     * token was in: `true` when it was revoked just now, `false` when there
     * was nothing left to revoke.
     */
    suspend fun logout(token: String): JoluResult<Boolean> =
        post("api/auth/app-logout.php", JSONObject(), token) { json -> json.opt("revoked") as? Boolean }

    /** The profile of the account this token belongs to. */
    suspend fun profile(token: String): JoluResult<JoluProfile> =
        post("api/integrations/profile.php", JSONObject(), token) { json ->
            json.optJSONObject("account")?.let { account ->
                JoluProfile(
                    username = account.text("username"),
                    age = account.number("age")?.roundToInt(),
                    gender = account.text("gender"),
                    heightCm = account.number("height_cm"),
                    weightKg = account.number("weight_kg"),
                    activityLevel = account.text("activity_level")
                )
            }
        }

    /** The daily targets the server works out from that account's profile. */
    suspend fun nutritionTargets(token: String): JoluResult<JoluTargets> =
        post("api/integrations/nutrition-targets.php", JSONObject(), token) { json ->
            json.optJSONObject("targets")?.let { targets ->
                JoluTargets(
                    calorieTargetKcal = targets.number("calorie_target_kcal")?.roundToInt(),
                    proteinTargetG = targets.number("protein_target_g")?.roundToInt(),
                    bmrKcal = targets.number("bmr_kcal")?.roundToInt(),
                    tdeeKcal = targets.number("tdee_kcal")?.roundToInt()
                )
            }
        }

    /**
     * Sends Health Connect records (IngestPayload) to ingest.php. The server
     * maps, stores and deduplicates them, and answers with what it did.
     */
    suspend fun ingest(token: String, records: JSONArray): JoluResult<IngestResult> =
        post(
            "api/integrations/ingest.php",
            JSONObject().put("records", records),
            token,
            readTimeoutMs = INGEST_TIMEOUT_MS
        ) { json -> readIngestResult(json) }

    /**
     * Everything the signed-in app shows (api/app/state.php): the data the
     * website's pages are rendered from, for the account [token]. A sync
     * token is refused with 403, a token that no longer works with 401.
     */
    suspend fun appState(token: String): JoluResult<JSONObject> =
        request(
            "api/app/state.php", JSON_TYPE, "{}".toByteArray(), token,
            TIMEOUT_MS, MAX_STATE_BYTES
        ) { json -> json.optJSONObject("data")?.takeIf { json.opt("version") == STATE_VERSION } }

    /**
     * One of the website's write endpoints, as the website sends it: a form
     * (application/x-www-form-urlencoded), with the account [token] instead
     * of its session. The whole answer comes back; its `error` is in the
     * failure.
     */
    suspend fun form(path: String, fields: Map<String, String>, token: String): JoluResult<JSONObject> {
        val body = fields.entries.joinToString("&") { (key, value) ->
            URLEncoder.encode(key, "UTF-8") + "=" + URLEncoder.encode(value, "UTF-8")
        }
        return request(path, FORM_TYPE, body.toByteArray(Charsets.UTF_8), token, TIMEOUT_MS, MAX_RESPONSE_BYTES) { it }
    }

    /** A JSON body to a write endpoint that reads one (api/goals/update.php). */
    suspend fun json(path: String, body: JSONObject, token: String): JoluResult<JSONObject> =
        post(path, body, token) { it }

    /** A file, as the website uploads the profile picture: multipart/form-data, field [field]. */
    suspend fun upload(
        path: String,
        field: String,
        fileName: String,
        mimeType: String,
        content: ByteArray,
        token: String
    ): JoluResult<JSONObject> {
        val boundary = "jolu" + java.util.UUID.randomUUID().toString().replace("-", "")
        val head = "--$boundary\r\nContent-Disposition: form-data; name=\"$field\"; filename=\"$fileName\"\r\n" +
            "Content-Type: $mimeType\r\n\r\n"
        val tail = "\r\n--$boundary--\r\n"
        val bytes = head.toByteArray(Charsets.UTF_8) + content + tail.toByteArray(Charsets.UTF_8)
        return request(
            path, "multipart/form-data; boundary=$boundary", bytes, token,
            UPLOAD_TIMEOUT_MS, MAX_RESPONSE_BYTES
        ) { it }
    }

    /** A file the server serves as it is — a profile picture — or null. Never with the token. */
    suspend fun image(path: String, maxBytes: Int = MAX_IMAGE_BYTES): ByteArray? = withContext(Dispatchers.IO) {
        try {
            val connection = URI.create(baseUrl + path.trimStart('/')).toURL().openConnection() as HttpURLConnection
            try {
                connection.connectTimeout = TIMEOUT_MS
                connection.readTimeout = TIMEOUT_MS
                connection.instanceFollowRedirects = false
                if (connection.responseCode != HttpURLConnection.HTTP_OK) return@withContext null
                connection.inputStream.use { stream ->
                    val out = ByteArrayOutputStream()
                    val buffer = ByteArray(8 * 1024)
                    while (true) {
                        val count = stream.read(buffer)
                        if (count < 0) break
                        out.write(buffer, 0, count)
                        if (out.size() > maxBytes) return@withContext null
                    }
                    out.toByteArray()
                }
            } finally {
                connection.disconnect()
            }
        } catch (e: Exception) {
            null
        }
    }

    /**
     * One call, reduced to a [JoluResult]. Never throws: no connection, a
     * server error and an answer that is not the expected JSON each come back
     * as their own failure.
     */
    private suspend fun <T : Any> post(
        path: String,
        body: JSONObject,
        token: String?,
        readTimeoutMs: Int = TIMEOUT_MS,
        read: (JSONObject) -> T?
    ): JoluResult<T> =
        request(path, JSON_TYPE, body.toString().toByteArray(Charsets.UTF_8), token, readTimeoutMs, MAX_RESPONSE_BYTES, read)

    /**
     * The same, for any body: [contentType] and its [bytes]. [maxBytes] is
     * how large an answer may be; anything larger is not read.
     */
    private suspend fun <T : Any> request(
        path: String,
        contentType: String,
        bytes: ByteArray,
        token: String?,
        readTimeoutMs: Int,
        maxBytes: Int,
        read: (JSONObject) -> T?
    ): JoluResult<T> = withContext(Dispatchers.IO) {
        val response = try {
            send(path, contentType, bytes, token, readTimeoutMs, maxBytes)
        } catch (e: Exception) {
            // Offline, unknown host, timeout, TLS: there was no answer at all.
            return@withContext JoluResult.NetworkError
        }

        val json = response.body?.let { text ->
            try {
                JSONObject(text)
            } catch (e: JSONException) {
                null
            }
        }

        when {
            response.status == HttpURLConnection.HTTP_UNAUTHORIZED ->
                JoluResult.Unauthorized(json?.text("error"))

            response.status !in 200..299 ->
                JoluResult.HttpError(response.status, json?.text("error"), json)

            json == null || json.opt("ok") != true ->
                JoluResult.InvalidResponse

            else ->
                read(json)?.let { JoluResult.Success(it) } ?: JoluResult.InvalidResponse
        }
    }

    /** One POST. Throws when there is no answer; any HTTP status is an answer. */
    private fun send(
        path: String,
        contentType: String,
        bytes: ByteArray,
        token: String?,
        readTimeoutMs: Int,
        maxBytes: Int
    ): Response {
        val connection = URI.create(baseUrl + path).toURL().openConnection() as HttpURLConnection

        try {
            connection.requestMethod = "POST"
            connection.connectTimeout = TIMEOUT_MS
            connection.readTimeout = readTimeoutMs
            connection.useCaches = false
            // A redirect is not followed: it could carry the token somewhere else.
            connection.instanceFollowRedirects = false
            connection.doOutput = true
            connection.setRequestProperty("Content-Type", contentType)
            connection.setRequestProperty("Accept", "application/json")

            if (token != null) {
                connection.setRequestProperty("Authorization", "Bearer $token")
            }

            connection.outputStream.use { it.write(bytes) }

            val status = connection.responseCode
            val stream = if (status in 200..299) connection.inputStream else connection.errorStream

            return Response(status, stream?.use { readAtMost(it, maxBytes) })
        } finally {
            connection.disconnect()
        }
    }

    /** The body as text, or null when it is far larger than any answer these endpoints give. */
    private fun readAtMost(stream: InputStream, maxBytes: Int): String? {
        val out = ByteArrayOutputStream()
        val buffer = ByteArray(8 * 1024)

        while (true) {
            val count = stream.read(buffer)

            if (count < 0) {
                break
            }

            out.write(buffer, 0, count)

            if (out.size() > maxBytes) {
                return null
            }
        }

        return String(out.toByteArray(), Charsets.UTF_8)
    }

    private class Response(val status: Int, val body: String?)

    companion object {
        /** The JoLu server. HTTPS only — Android refuses plain HTTP anyway. */
        const val BASE_URL = "https://healthpreview.acits.nl/"

        private const val TIMEOUT_MS = 15_000
        private const val MAX_RESPONSE_BYTES = 64 * 1024

        /** The app's state: every page at once, charts included — far more than the other answers. */
        private const val MAX_STATE_BYTES = 4 * 1024 * 1024

        /** A profile picture: the server takes up to 3 MB. */
        private const val MAX_IMAGE_BYTES = 4 * 1024 * 1024
        private const val UPLOAD_TIMEOUT_MS = 60_000

        /** The state's shape this app reads (api/app/state.php `version`). */
        private const val STATE_VERSION = 1

        private const val JSON_TYPE = "application/json; charset=utf-8"
        private const val FORM_TYPE = "application/x-www-form-urlencoded; charset=utf-8"

        /**
         * An ingest batch is stored, then goals, points and the Health Score
         * are recalculated before the answer comes, so it may take longer.
         */
        private const val INGEST_TIMEOUT_MS = 60_000
    }
}

/**
 * What the server issues — a pairing token or an account token alike: 32
 * random bytes as 64 hex characters. Checked before a token is stored, and so
 * before it is ever put in a header.
 */
private val TOKEN_FORMAT = Regex("[0-9a-f]{64}")

/**
 * The pairing code as the server expects it, or null when it cannot be one:
 * eight characters from A–Z and 2–9, the rule pair.php applies. Spaces and
 * dashes are dropped and letters upper-cased, so "abcd-2345" works too.
 */
fun normalizePairingCode(input: String): String? =
    input.filterNot { it.isWhitespace() || it == '-' }
        .uppercase()
        .takeIf { PAIRING_CODE.matches(it) }

private val PAIRING_CODE = Regex("[A-Z2-9]{8}")

/** A JoLu call's outcome, reduced to what the app acts on. */
sealed interface JoluResult<out T> {

    data class Success<out T>(val value: T) : JoluResult<T>

    sealed interface Failure : JoluResult<Nothing>

    /**
     * HTTP 401. From pair: the code was refused. From every other call: the
     * token no longer works — revoked on the website, or the account is gone.
     */
    data class Unauthorized(val message: String?) : Failure

    /**
     * Any other status that is not a success, with the server's message if it
     * sent one — and its whole answer, for the few that say more (where two
     * friends now stand, after a refused request).
     */
    data class HttpError(val status: Int, val message: String?, val body: JSONObject? = null) : Failure

    /** No answer: offline, server unreachable, timeout. */
    data object NetworkError : Failure

    /** An answer, but not the JSON this app expects. */
    data object InvalidResponse : Failure
}

/**
 * A successful sign-in: the account token (shown once by the server, never
 * again) and the account's username.
 */
class JoluSession(val token: String, val username: String?) {

    override fun equals(other: Any?): Boolean =
        other is JoluSession && other.token == token && other.username == username

    override fun hashCode(): Int = 31 * token.hashCode() + (username?.hashCode() ?: 0)

    /** Never the token. */
    override fun toString(): String = "JoluSession(username=$username)"
}

/**
 * A sign-in answer, or null when it is not one: a well-formed token that the
 * server says is an ACCOUNT token. Anything else is not stored.
 */
private fun readSession(json: JSONObject): JoluSession? {
    val token = (json.opt("token") as? String)?.takeIf { TOKEN_FORMAT.matches(it) } ?: return null

    if (json.opt("scope") != "account") {
        return null
    }

    return JoluSession(token, json.optJSONObject("account")?.text("username"))
}

/** The part of a JoLu profile this app shows. Null: the person has not filled it in. */
data class JoluProfile(
    val username: String?,
    val age: Int?,
    val gender: String?,
    val heightCm: Double?,
    val weightKg: Double?,
    val activityLevel: String?
)

/** Daily targets exactly as the server worked them out. Null: not enough profile data for it. */
data class JoluTargets(
    val calorieTargetKcal: Int?,
    val proteinTargetG: Int?,
    val bmrKcal: Int?,
    val tdeeKcal: Int?
)

/** What ingest.php did with one batch of records. */
data class IngestResult(
    /** Records stored — new ones and ones sent before, which are updated in place. */
    val written: Int,
    /** Records the server refused; [problems] says why. */
    val skipped: Int,
    /** The dates (yyyy-MM-dd) the batch touched. */
    val days: List<String>,
    /** Record types the server does not map yet, with how many were sent. */
    val unmapped: Map<String, Int>,
    /** The server's reasons for skipped records. */
    val problems: List<String>,
    /** Points this batch earned; a night or workout already paid for earns nothing again. */
    val points: List<IngestPoints>,
    /** The Health Scores after this batch, or null when nothing was written. */
    val scores: JoluScores?
)

data class IngestPoints(val points: Int, val label: String)

data class JoluScores(val sleep: Int?, val nutrition: Int?, val training: Int?, val overall: Int?)

/** The ingest answer, or null when it is not one: `written` and `skipped` must be numbers. */
private fun readIngestResult(json: JSONObject): IngestResult? {
    val written = json.number("written")?.roundToInt() ?: return null
    val skipped = json.number("skipped")?.roundToInt() ?: return null

    val days = json.optJSONArray("days").objects { it as? String }

    // PHP sends an empty map as [], a filled one as {"Type": count}.
    val unmapped = json.optJSONObject("unmapped")?.let { map ->
        map.keys().asSequence().mapNotNull { type ->
            map.number(type)?.roundToInt()?.let { type to it }
        }.toMap()
    } ?: emptyMap()

    val problems = json.optJSONArray("problems").objects { (it as? JSONObject)?.text("error") }

    val points = json.optJSONArray("points").objects { line ->
        (line as? JSONObject)?.let {
            val value = it.number("points")?.roundToInt()
            val label = it.text("label")
            if (value == null || label == null) null else IngestPoints(value, label)
        }
    }

    val scores = json.optJSONObject("scores")?.let {
        JoluScores(
            sleep = it.number("sleep")?.roundToInt(),
            nutrition = it.number("nutrition")?.roundToInt(),
            training = it.number("training")?.roundToInt(),
            overall = it.number("overall")?.roundToInt()
        )
    }

    return IngestResult(written, skipped, days, unmapped, problems, points, scores)
}

/** The array's items that [read] accepts; a missing array is an empty list. */
private fun <T : Any> JSONArray?.objects(read: (Any?) -> T?): List<T> =
    if (this == null) emptyList() else (0 until length()).mapNotNull { read(opt(it)) }

/** A non-blank string, or null for a missing value, JSON null or any other type. */
private fun JSONObject.text(name: String): String? =
    (opt(name) as? String)?.takeIf { it.isNotBlank() }

/** A finite number, or null. A numeric string counts too. */
private fun JSONObject.number(name: String): Double? =
    when (val value = opt(name)) {
        is Number -> value.toDouble()
        is String -> value.trim().toDoubleOrNull()
        else -> null
    }?.takeIf { it.isFinite() }
