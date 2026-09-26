package com.healthapp.android.jolu

import android.content.Context
import androidx.work.CoroutineWorker
import androidx.work.WorkerParameters

/**
 * The automatic sync, run by WorkManager (scheduled by JoluBackgroundSync).
 *
 * It runs the same pipeline as the "Sync to JoLu" button — JoluSyncRunner,
 * through JoluSync.runAutomatic — and only decides what WorkManager should do
 * next:
 *
 *   synced                          done; the next run comes on schedule
 *   no token, or the server said 401  done, and automatic sync is switched off
 *                                   until the phone is paired again — an
 *                                   invalid token is never tried twice
 *   no Health Connect access        done; nothing was sent, and the next
 *                                   scheduled run looks again (no network)
 *   offline, server trouble, a      try again later, with WorkManager's
 *   Health Connect read error       backoff, at most [MAX_RETRIES] times; the
 *                                   token is always kept
 */
class JoluSyncWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {

    override suspend fun doWork(): Result {
        val outcome = JoluSync.runAutomatic(applicationContext)

        return when (decide(outcome, runAttemptCount)) {
            Decision.DONE -> Result.success()
            Decision.RETRY -> Result.retry()
            Decision.STOP -> {
                JoluBackgroundSync.stop(applicationContext)
                Result.success()
            }
        }
    }

    internal enum class Decision { DONE, RETRY, STOP }

    internal companion object {

        /** Retries after a failed run before waiting for the next scheduled one. */
        const val MAX_RETRIES = 3

        fun decide(outcome: JoluSyncOutcome, attempt: Int): Decision =
            when (outcome) {
                is JoluSyncOutcome.Synced,
                is JoluSyncOutcome.NoAccess,
                JoluSyncOutcome.Busy -> Decision.DONE

                JoluSyncOutcome.NotConnected,
                JoluSyncOutcome.Unauthorized -> Decision.STOP

                JoluSyncOutcome.ReadFailed ->
                    if (attempt < MAX_RETRIES) Decision.RETRY else Decision.DONE

                is JoluSyncOutcome.SendFailed ->
                    if (worthRetrying(outcome.failure) && attempt < MAX_RETRIES) Decision.RETRY else Decision.DONE
            }

        /** No answer, or an answer that says "later": offline, a timeout, a server error, too many requests. */
        private fun worthRetrying(failure: JoluResult.Failure): Boolean =
            when (failure) {
                JoluResult.NetworkError, JoluResult.InvalidResponse -> true
                is JoluResult.HttpError -> failure.status >= 500 || failure.status == 408 || failure.status == 429
                is JoluResult.Unauthorized -> false
            }
    }
}
