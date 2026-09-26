package com.healthapp.android.jolu

import android.content.Context
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.health.connect.client.HealthConnectClient
import java.time.Duration
import java.time.Instant
import kotlin.coroutines.cancellation.CancellationException
import kotlinx.coroutines.CoroutineExceptionHandler
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import org.json.JSONArray

/** Where the manual "Sync to JoLu" stands. The JoLu section shows exactly this. */
sealed interface JoluSyncState {

    data object Idle : JoluSyncState

    data object Syncing : JoluSyncState

    data class Synced(val summary: JoluSyncSummary) : JoluSyncState

    data class Failed(val message: String) : JoluSyncState
}

/** One finished sync: what was read and sent, and what the server did with it. */
data class JoluSyncSummary(
    val from: Instant,
    val to: Instant,
    /** Records sent, by Health Connect type. */
    val sent: Map<String, Int>,
    /** Types not read because their Health Connect permission is not granted. */
    val notGranted: List<String>,
    /** The server's answers for every batch, added together. */
    val result: IngestResult
)

/**
 * The manual sync: Health Connect records of the last [RANGE] go to
 * ingest.php, with the stored device token.
 *
 *   1. the stored token (JoluConnection) — none: nothing is read or sent;
 *   2. the records (HealthConnectSyncReader) — only granted types;
 *   3. the JSON (IngestPayload) — Health Connect's own format;
 *   4. POST in batches (JoluApi.ingest), and the answers added up.
 *
 * Duplicates are the server's job: every record carries Health Connect's own
 * id and the server updates a record it already has, so syncing the same
 * days again is safe and earns no points twice.
 *
 * No background work: it runs when the button is pressed, and only then.
 */
object JoluSync {

    /** How far back a sync reads, counted from the moment it starts. */
    val RANGE: Duration = Duration.ofDays(7)

    /** Records per request. ingest.php accepts up to 2000. */
    private const val BATCH_SIZE = 500

    private const val NOT_CONNECTED_MESSAGE = "Connect your JoLu account first."
    private const val NO_HEALTH_CONNECT_MESSAGE = "Health Connect is not available on this phone."
    private const val NO_PERMISSION_MESSAGE =
        "Allow Health Connect access first (\"Connect to Health Connect\")."
    private const val READ_FAILED_MESSAGE = "Could not read Health Connect data. Please try again."
    private const val UNEXPECTED_MESSAGE = "Something went wrong while syncing. Please try again."

    var state: JoluSyncState by mutableStateOf(JoluSyncState.Idle)
        private set

    private val api = JoluApi()

    private val scope = CoroutineScope(
        SupervisorJob() + Dispatchers.Main.immediate + CoroutineExceptionHandler { _, _ ->
            // Never a crash, and nothing logged: it could carry health data.
            state = JoluSyncState.Failed(UNEXPECTED_MESSAGE)
        }
    )

    private var job: Job? = null

    fun sync(context: Context) {
        if (job?.isActive == true) {
            return
        }

        val app = context.applicationContext

        job = scope.launch {
            state = JoluSyncState.Syncing
            state = run(app)
        }
    }

    private suspend fun run(context: Context): JoluSyncState {
        val token = JoluConnection.storedToken(context)
            ?: return JoluSyncState.Failed(NOT_CONNECTED_MESSAGE)

        if (HealthConnectClient.getSdkStatus(context) != HealthConnectClient.SDK_AVAILABLE) {
            return JoluSyncState.Failed(NO_HEALTH_CONNECT_MESSAGE)
        }

        val to = Instant.now()
        val from = to.minus(RANGE)

        val reading = try {
            HealthConnectSyncReader(HealthConnectClient.getOrCreate(context))
                .read(IngestPayload.TYPES, from, to)
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            return JoluSyncState.Failed(READ_FAILED_MESSAGE)
        }

        if (reading.notGranted.size == IngestPayload.TYPES.size) {
            return JoluSyncState.Failed(NO_PERMISSION_MESSAGE)
        }

        val payload = withContext(Dispatchers.Default) { IngestPayload.of(reading.records) }

        // Nothing to send is still sent: the server records that the phone
        // synced, and answers "0 written" rather than the app guessing it.
        val batches = IngestPayload.batches(payload, BATCH_SIZE).ifEmpty { listOf(JSONArray()) }
        val results = mutableListOf<IngestResult>()

        for ((index, batch) in batches.withIndex()) {
            when (val result = api.ingest(token, batch)) {
                is JoluResult.Success -> results += result.value

                // The token no longer works: the connection handles it as
                // always, and its status line asks for a new code.
                is JoluResult.Unauthorized -> {
                    JoluConnection.unauthorized(context)
                    return JoluSyncState.Idle
                }

                is JoluResult.Failure -> {
                    val done = if (index == 0) {
                        ""
                    } else {
                        " $index of ${batches.size} parts were already synced; syncing again is safe."
                    }
                    return JoluSyncState.Failed(JoluConnection.describe(result) + done)
                }
            }
        }

        return JoluSyncState.Synced(
            JoluSyncSummary(
                from = from,
                to = to,
                sent = IngestPayload.countByType(payload),
                notGranted = reading.notGranted,
                result = combine(results)
            )
        )
    }
}

/**
 * The answers to several batches as one: counts added, days and unmapped
 * types merged, every problem and point kept, and the Health Scores from the
 * last batch that had any — they are recalculated after every batch.
 */
internal fun combine(results: List<IngestResult>): IngestResult =
    IngestResult(
        written = results.sumOf { it.written },
        skipped = results.sumOf { it.skipped },
        days = results.flatMap { it.days }.distinct().sorted(),
        unmapped = results.flatMap { it.unmapped.entries }
            .groupingBy { it.key }
            .fold(0) { total, entry -> total + entry.value },
        problems = results.flatMap { it.problems },
        points = results.flatMap { it.points },
        scores = results.lastOrNull { it.scores != null }?.scores
    )
