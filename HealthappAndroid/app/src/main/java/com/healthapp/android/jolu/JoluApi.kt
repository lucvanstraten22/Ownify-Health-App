package com.healthapp.android.jolu

import java.io.ByteArrayOutputStream
import java.io.InputStream
import java.net.HttpURLConnection
import java.net.URI
import kotlin.math.roundToInt
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONArray
import org.json.JSONException
import org.json.JSONObject

/**
 * The three JoLu endpoints this app uses: pair, profile and nutrition-targets.
 *
 * Every call is a POST with a JSON body, as the backend requires. The device
 * token travels only in the Authorization header, and no request ever carries
 * a user id: whose data comes back is decided by the server from the token
 * alone.
 *
 * HttpURLConnection and org.json are both part of Android, so three small
 * calls add no library to the app. Nothing here logs — not the pairing code,
 * not the token, not the profile.
 */
class JoluApi(private val baseUrl: String = BASE_URL) {

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
    ): JoluResult<T> = withContext(Dispatchers.IO) {
        val response = try {
            send(path, body.toString(), token, readTimeoutMs)
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
                JoluResult.HttpError(response.status, json?.text("error"))

            json == null || json.opt("ok") != true ->
                JoluResult.InvalidResponse

            else ->
                read(json)?.let { JoluResult.Success(it) } ?: JoluResult.InvalidResponse
        }
    }

    /** One POST. Throws when there is no answer; any HTTP status is an answer. */
    private fun send(path: String, body: String, token: String?, readTimeoutMs: Int): Response {
        val bytes = body.toByteArray(Charsets.UTF_8)
        val connection = URI.create(baseUrl + path).toURL().openConnection() as HttpURLConnection

        try {
            connection.requestMethod = "POST"
            connection.connectTimeout = TIMEOUT_MS
            connection.readTimeout = readTimeoutMs
            connection.useCaches = false
            // A redirect is not followed: it could carry the token somewhere else.
            connection.instanceFollowRedirects = false
            connection.doOutput = true
            connection.setRequestProperty("Content-Type", "application/json; charset=utf-8")
            connection.setRequestProperty("Accept", "application/json")

            if (token != null) {
                connection.setRequestProperty("Authorization", "Bearer $token")
            }

            connection.outputStream.use { it.write(bytes) }

            val status = connection.responseCode
            val stream = if (status in 200..299) connection.inputStream else connection.errorStream

            return Response(status, stream?.use { readAtMost(it) })
        } finally {
            connection.disconnect()
        }
    }

    /** The body as text, or null when it is far larger than any answer these endpoints give. */
    private fun readAtMost(stream: InputStream): String? {
        val out = ByteArrayOutputStream()
        val buffer = ByteArray(8 * 1024)

        while (true) {
            val count = stream.read(buffer)

            if (count < 0) {
                break
            }

            out.write(buffer, 0, count)

            if (out.size() > MAX_RESPONSE_BYTES) {
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

        /**
         * An ingest batch is stored, then goals, points and the Health Score
         * are recalculated before the answer comes, so it may take longer.
         */
        private const val INGEST_TIMEOUT_MS = 60_000

        /**
         * What pair.php issues: 32 random bytes as 64 hex characters. Checked
         * before the token is stored, and so before it is ever put in a header.
         */
        private val TOKEN_FORMAT = Regex("[0-9a-f]{64}")
    }
}

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

    /** Any other status that is not a success, with the server's message if it sent one. */
    data class HttpError(val status: Int, val message: String?) : Failure

    /** No answer: offline, server unreachable, timeout. */
    data object NetworkError : Failure

    /** An answer, but not the JSON this app expects. */
    data object InvalidResponse : Failure
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
