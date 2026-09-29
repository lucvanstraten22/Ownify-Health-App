package com.ownify.android.connection

import android.content.Context
import androidx.health.connect.client.HealthConnectClient
import androidx.health.connect.client.HealthConnectFeatures
import androidx.health.connect.client.permission.HealthPermission
import androidx.work.BackoffPolicy
import androidx.work.Constraints
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequest
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.PeriodicWorkRequest
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import java.time.Duration
import java.time.Instant
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.map

/**
 * When the automatic sync runs. WorkManager does the running: it keeps the
 * schedule when Ownify is closed and after a restart, waits for a network, and
 * spaces out retries — no service is kept running, and nothing wakes the
 * phone that the system would not.
 *
 *   every [INTERVAL]      while paired, on a network, battery not low
 *   soon                  after pairing, after Health Connect access is
 *                         granted, and on opening Ownify when the last sync is
 *                         older than [STALE_AFTER] — as soon as there is a network
 *   never                 once the token is gone: a 401, or not paired
 *
 * What runs is OwnifySyncWorker, which runs the same pipeline as the button.
 */
object OwnifyBackgroundSync {

    /** How often the automatic sync runs. */
    val INTERVAL: Duration = Duration.ofHours(1)

    /** WorkManager may run it anywhere in the last [FLEX] of each [INTERVAL], whenever suits the phone. */
    internal val FLEX: Duration = Duration.ofMinutes(20)

    /** The first wait after a failed run; it doubles each time. */
    internal val BACKOFF: Duration = Duration.ofMinutes(10)

    /** Opening Ownify syncs only when the last successful sync is older than this. */
    internal val STALE_AFTER: Duration = Duration.ofMinutes(30)

    internal const val PERIODIC_WORK = "ownify-sync-periodic"
    internal const val SOON_WORK = "ownify-sync-soon"

    /** "Access data in the background": Health Connect's permission to read while Ownify is not on screen. */
    const val BACKGROUND_PERMISSION = HealthPermission.PERMISSION_READ_HEALTH_DATA_IN_BACKGROUND

    /**
     * Ownify is connected — just paired, or reconnected with the stored token
     * when the app opens: keep the schedule, and sync now if it has been a while.
     */
    fun connected(context: Context) {
        val app = context.applicationContext
        schedule(app)

        val lastSuccess = OwnifySyncRunner.environment(app).status.read().lastSuccessAt

        if (lastSuccess == null || lastSuccess.isBefore(Instant.now().minus(STALE_AFTER))) {
            syncSoon(app)
        }
    }

    /** Health Connect access was just granted in the app: sync what can now be read. */
    fun healthAccessGranted(context: Context) {
        if (OwnifyConnection.state is OwnifyState.Connected) {
            syncSoon(context.applicationContext)
        }
    }

    /** Not paired any more: no automatic sync until the phone is paired again. */
    fun stop(context: Context) {
        val work = WorkManager.getInstance(context.applicationContext)
        work.cancelUniqueWork(PERIODIC_WORK)
        work.cancelUniqueWork(SOON_WORK)
    }

    /** Whether the automatic sync is scheduled, as it changes. */
    fun active(context: Context): Flow<Boolean> =
        WorkManager.getInstance(context.applicationContext)
            .getWorkInfosForUniqueWorkFlow(PERIODIC_WORK)
            .map { infos -> infos.any { !it.state.isFinished } }

    /** Whether Health Connect lets Ownify read while it is closed, and whether it can be asked to. */
    suspend fun backgroundRead(context: Context): BackgroundRead {
        val app = context.applicationContext

        if (HealthConnectClient.getSdkStatus(app) != HealthConnectClient.SDK_AVAILABLE) {
            return BackgroundRead.NO_HEALTH_CONNECT
        }

        val client = HealthConnectClient.getOrCreate(app)
        val feature = client.features.getFeatureStatus(HealthConnectFeatures.FEATURE_READ_HEALTH_DATA_IN_BACKGROUND)

        return when {
            feature != HealthConnectFeatures.FEATURE_STATUS_AVAILABLE -> BackgroundRead.NOT_SUPPORTED
            BACKGROUND_PERMISSION in client.permissionController.getGrantedPermissions() -> BackgroundRead.GRANTED
            else -> BackgroundRead.NOT_GRANTED
        }
    }

    internal fun schedule(context: Context) {
        WorkManager.getInstance(context).enqueueUniquePeriodicWork(
            PERIODIC_WORK,
            // A schedule that is already there keeps its timing; a changed
            // interval or constraint in a newer app version is applied to it.
            ExistingPeriodicWorkPolicy.UPDATE,
            periodicRequest()
        )
    }

    internal fun syncSoon(context: Context) {
        // One at a time: a sync already waiting or running is enough.
        WorkManager.getInstance(context).enqueueUniqueWork(SOON_WORK, ExistingWorkPolicy.KEEP, soonRequest())
    }

    internal fun periodicRequest(): PeriodicWorkRequest =
        PeriodicWorkRequestBuilder<OwnifySyncWorker>(INTERVAL, FLEX)
            .setConstraints(
                Constraints.Builder()
                    .setRequiredNetworkType(NetworkType.CONNECTED)
                    .setRequiresBatteryNotLow(true)
                    .build()
            )
            .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, BACKOFF)
            .build()

    /** Something the person just did asked for it, so only a network is needed. */
    internal fun soonRequest(): OneTimeWorkRequest =
        OneTimeWorkRequestBuilder<OwnifySyncWorker>()
            .setConstraints(
                Constraints.Builder()
                    .setRequiredNetworkType(NetworkType.CONNECTED)
                    .build()
            )
            .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, BACKOFF)
            .build()
}

/** Health Connect's answer to "may Ownify read while it is closed?". */
enum class BackgroundRead {
    GRANTED,

    /** Supported on this phone, not granted (yet). */
    NOT_GRANTED,

    /** This phone's Health Connect has no background reading: Ownify syncs while it is open. */
    NOT_SUPPORTED,

    NO_HEALTH_CONNECT
}
