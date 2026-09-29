package com.ownify.android.connection

import android.content.Context
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import java.time.Duration
import java.time.Instant
import kotlinx.coroutines.CoroutineExceptionHandler
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch

/** Where the sync stands — the button's or an automatic one. The Ownify section shows exactly this. */
sealed interface OwnifySyncState {

    data object Idle : OwnifySyncState

    data object Syncing : OwnifySyncState

    data class Synced(val summary: OwnifySyncSummary) : OwnifySyncState

    data class Failed(val message: String) : OwnifySyncState
}

/** One finished sync: what was read and sent, and what the server did with it. */
data class OwnifySyncSummary(
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
 * The sync as the screen sees it: the "Nu synchroniseren" button, and the state
 * the Ownify section shows — for the button's runs and the automatic ones alike.
 *
 * The work itself is OwnifySyncRunner's, the one pipeline both the button and
 * the background worker (OwnifySyncWorker) run. Nothing here reads, converts
 * or sends a record.
 */
object OwnifySync {

    /** How far back a sync reads — the same for the button and the automatic sync. */
    val RANGE: Duration get() = OwnifySyncRunner.RANGE

    private const val NOT_CONNECTED_MESSAGE = "Log eerst in bij Ownify."
    private const val NO_HEALTH_CONNECT_MESSAGE = "Health Connect is niet beschikbaar op deze telefoon."
    private const val NO_PERMISSION_MESSAGE =
        "Geef Ownify eerst toegang tot Health Connect."
    private const val READ_FAILED_MESSAGE = "Health Connect kon niet worden gelezen. Probeer het opnieuw."
    private const val UNEXPECTED_MESSAGE = "Er ging iets mis bij het synchroniseren. Probeer het opnieuw."

    var state: OwnifySyncState by mutableStateOf(OwnifySyncState.Idle)
        private set

    /** How the last sync went, by the button or automatically — kept between launches. */
    var record: OwnifySyncRecord by mutableStateOf(OwnifySyncRecord())
        private set

    private val scope = CoroutineScope(
        SupervisorJob() + Dispatchers.Main.immediate + CoroutineExceptionHandler { _, _ ->
            // Never a crash, and nothing logged: it could carry health data.
            state = OwnifySyncState.Failed(UNEXPECTED_MESSAGE)
        }
    )

    private var job: Job? = null

    /** "Nu synchroniseren": now, in the app, whatever the automatic sync is doing. */
    fun sync(context: Context) {
        if (job?.isActive == true) {
            return
        }

        val env = OwnifySyncRunner.environment(context.applicationContext)

        job = scope.launch {
            state = OwnifySyncState.Syncing
            state = show(OwnifySyncRunner.run(env, automatic = false))
            record = env.status.read()
        }
    }

    /**
     * One automatic run (OwnifySyncWorker). The screen shows it while it runs
     * and shows its result when it synced; anything else it leaves to the
     * status line, rather than showing an error nobody asked about.
     */
    internal suspend fun runAutomatic(context: Context): OwnifySyncOutcome {
        val env = OwnifySyncRunner.environment(context.applicationContext)

        val outcome = OwnifySyncRunner.run(env, automatic = true) {
            state = OwnifySyncState.Syncing
        }

        if (outcome != OwnifySyncOutcome.Busy) {
            state = if (outcome is OwnifySyncOutcome.Synced) OwnifySyncState.Synced(outcome.summary) else OwnifySyncState.Idle
            record = env.status.read()
        }

        return outcome
    }

    /** Reads the kept status again, e.g. when the screen opens or a new account is paired. */
    internal fun refresh(context: Context) {
        record = OwnifySyncRunner.environment(context.applicationContext).status.read()
    }

    /**
     * Signed in or out: the last sync's result on screen — counts, points,
     * Health Scores — belonged to the session before, and is not shown to the
     * next one. The kept status is cleared by the caller.
     */
    internal fun forget(context: Context) {
        state = OwnifySyncState.Idle
        refresh(context)
    }

    /** The button's run, in the words it has always used. */
    private fun show(outcome: OwnifySyncOutcome): OwnifySyncState =
        when (outcome) {
            is OwnifySyncOutcome.Synced -> OwnifySyncState.Synced(outcome.summary)
            OwnifySyncOutcome.NotConnected -> OwnifySyncState.Failed(NOT_CONNECTED_MESSAGE)
            // The token no longer works: the connection handles it as always,
            // and its status line asks for a new code.
            OwnifySyncOutcome.Unauthorized -> OwnifySyncState.Idle
            OwnifySyncOutcome.TokenReplaced -> OwnifySyncState.Idle
            OwnifySyncOutcome.ReadFailed -> OwnifySyncState.Failed(READ_FAILED_MESSAGE)
            OwnifySyncOutcome.Busy -> OwnifySyncState.Idle
            is OwnifySyncOutcome.NoAccess -> OwnifySyncState.Failed(
                if (outcome.access == HealthAccess.NOT_INSTALLED) NO_HEALTH_CONNECT_MESSAGE else NO_PERMISSION_MESSAGE
            )
            is OwnifySyncOutcome.SendFailed -> {
                val done = if (outcome.done == 0) {
                    ""
                } else {
                    " ${outcome.done} van ${outcome.batches} delen waren al verstuurd; opnieuw synchroniseren is veilig."
                }
                OwnifySyncState.Failed(OwnifyConnection.describe(outcome.failure) + done)
            }
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
