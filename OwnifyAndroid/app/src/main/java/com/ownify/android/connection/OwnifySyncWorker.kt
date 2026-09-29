package com.ownify.android.connection

import android.content.Context
import androidx.work.CoroutineWorker
import androidx.work.WorkerParameters

/**
 * The automatic sync, run by WorkManager (scheduled by OwnifyBackgroundSync).
 *
 * It runs the same pipeline as the "Nu synchroniseren" button — OwnifySyncRunner,
 * through OwnifySync.runAutomatic — and only decides what WorkManager should do
 * next:
 *
 *   synced                          done; the next run comes on schedule
 *   no token, or the server said 401  done, and automatic sync is switched off
 *                                   until the phone is paired again — an
 *                                   invalid token is never tried twice
 *   401 for a token already replaced  done, and the schedule is kept: signing
 *                                   in or out changed the token mid-run, and
 *                                   that is not a reason to stop
 *   no Health Connect access        done; nothing was sent, and the next
 *                                   scheduled run looks again (no network)
 *   offline, server trouble, a      try again later, with WorkManager's
 *   Health Connect read error       backoff, at most [MAX_RETRIES] times; the
 *                                   token is always kept
 */
class OwnifySyncWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {

    override suspend fun doWork(): Result {
        val outcome = OwnifySync.runAutomatic(applicationContext)

        return when (decide(outcome, runAttemptCount)) {
            Decision.DONE -> Result.success()
            Decision.RETRY -> Result.retry()
            Decision.STOP -> {
                OwnifyBackgroundSync.stop(applicationContext)
                Result.success()
            }
        }
    }

    internal enum class Decision { DONE, RETRY, STOP }

    internal companion object {

        /** Retries after a failed run before waiting for the next scheduled one. */
        const val MAX_RETRIES = 3

        fun decide(outcome: OwnifySyncOutcome, attempt: Int): Decision =
            when (outcome) {
                is OwnifySyncOutcome.Synced,
                is OwnifySyncOutcome.NoAccess,
                OwnifySyncOutcome.TokenReplaced,
                OwnifySyncOutcome.Busy -> Decision.DONE

                OwnifySyncOutcome.NotConnected,
                OwnifySyncOutcome.Unauthorized -> Decision.STOP

                OwnifySyncOutcome.ReadFailed ->
                    if (attempt < MAX_RETRIES) Decision.RETRY else Decision.DONE

                is OwnifySyncOutcome.SendFailed ->
                    if (worthRetrying(outcome.failure) && attempt < MAX_RETRIES) Decision.RETRY else Decision.DONE
            }

        /** No answer, or an answer that says "later": offline, a timeout, a server error, too many requests. */
        private fun worthRetrying(failure: OwnifyResult.Failure): Boolean =
            when (failure) {
                OwnifyResult.NetworkError, OwnifyResult.InvalidResponse -> true
                is OwnifyResult.HttpError -> failure.status >= 500 || failure.status == 408 || failure.status == 429
                is OwnifyResult.Unauthorized -> false
            }
    }
}
