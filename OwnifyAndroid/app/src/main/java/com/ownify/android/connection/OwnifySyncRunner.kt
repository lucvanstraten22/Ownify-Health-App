package com.ownify.android.connection

import android.app.ActivityManager
import android.content.Context
import androidx.health.connect.client.HealthConnectClient
import androidx.health.connect.client.permission.HealthPermission
import java.time.Duration
import java.time.Instant
import kotlin.coroutines.cancellation.CancellationException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.withContext
import org.json.JSONArray

/** Whether Health Connect can be read right now. */
internal enum class HealthAccess {
    AVAILABLE,

    /** Health Connect is not installed, or needs an update. */
    NOT_INSTALLED,

    /** Not one of the record types may be read. */
    NO_PERMISSION,

    /**
     * An automatic sync with Ownify not on screen, and "Access data in the
     * background" not granted: Health Connect only answers apps in the
     * foreground unless that permission is given.
     */
    BACKGROUND_NOT_ALLOWED
}

/**
 * Everything a sync needs from the phone. [AndroidSyncEnvironment] is the
 * only real one; tests give their own, so the pipeline they exercise is the
 * one the app runs.
 */
internal interface OwnifySyncEnvironment {

    val api: OwnifyApi

    val status: OwnifySyncStatusStore

    /** The stored token — a sync or an account token, both may upload — or null when there is none. */
    suspend fun token(): String?

    /**
     * The server answered 401 to [token]. When it is still the stored token,
     * the connection's own handling: forget it, stop automatic sync — and
     * true. When it is not (signing in or out replaced it while this run was
     * sending), nothing is touched — and false: that 401 was about a token
     * that is already gone, and must not sign anybody out.
     */
    suspend fun tokenRejected(token: String): Boolean

    /** Whether Health Connect can be read now. An [automatic] run may be in the background. */
    suspend fun healthAccess(automatic: Boolean): HealthAccess

    suspend fun read(from: Instant, to: Instant): HealthConnectSyncReader.Reading

    fun now(): Instant = Instant.now()
}

/** How one run of the pipeline ended. */
internal sealed interface OwnifySyncOutcome {

    data class Synced(val summary: OwnifySyncSummary) : OwnifySyncOutcome

    /** No token: nothing was read and nothing sent. */
    data object NotConnected : OwnifySyncOutcome

    /** The server refused the token; it has been forgotten. */
    data object Unauthorized : OwnifySyncOutcome

    /**
     * The server refused the token this run was sending with, but the phone
     * had already replaced it — signed in, or out, meanwhile — and a second
     * run with the current one was refused the same way. Nothing forgotten,
     * nothing stopped; the next run uses whatever is stored then.
     */
    data object TokenReplaced : OwnifySyncOutcome

    /** Health Connect could not be read, for the reason given. Nothing was sent. */
    data class NoAccess(val access: HealthAccess) : OwnifySyncOutcome

    /** Health Connect answered with an error while reading. Nothing was sent. */
    data object ReadFailed : OwnifySyncOutcome

    /** A batch did not arrive; [done] of [batches] had, and sending them again is safe. */
    data class SendFailed(val failure: OwnifyResult.Failure, val done: Int, val batches: Int) : OwnifySyncOutcome

    /** An automatic run found another sync already running, and left it to that one. */
    data object Busy : OwnifySyncOutcome
}

/**
 * THE sync — the only one. The "Nu synchroniseren" button (OwnifySync.sync) and the
 * background worker (OwnifySyncWorker) both run exactly this:
 *
 *   1. the stored token — none: nothing is read or sent;
 *   2. Health Connect access — none: nothing is sent;
 *   3. the records of the last [RANGE] (HealthConnectSyncReader), granted types only;
 *   4. the JSON (IngestPayload), Health Connect's own format;
 *   5. POST in batches (OwnifyApi.ingest), and the answers added up.
 *
 * Duplicates are the server's job: every record carries Health Connect's own
 * id, and the server updates a record it already has and counts overlapping
 * apps once, so sending the same days again is safe and earns nothing twice.
 * Nothing here adds up, removes or judges a record.
 *
 * One run at a time: an automatic run that finds one going leaves it to it;
 * the button waits for it and then runs, as it always has.
 *
 * A 401 forgets the token only if it is still the stored one. Signing in on a
 * paired phone replaces the sync token with an account token, and a run that
 * was already sending with the old one gets a 401 for it: that run starts
 * again, once, with the token stored now, rather than signing out the person
 * who just signed in.
 */
internal object OwnifySyncRunner {

    /** How far back a sync reads, counted from the moment it starts. */
    val RANGE: Duration = Duration.ofDays(7)

    /** Records per request. ingest.php accepts up to 2000. */
    private const val BATCH_SIZE = 500

    /** The real phone. Tests replace it; nothing else does. */
    @Volatile
    internal var environment: (Context) -> OwnifySyncEnvironment = { AndroidSyncEnvironment(it) }

    private val running = Mutex()

    /**
     * One sync. [onStart] is called once it really starts — not when an
     * automatic run steps aside for one already going. Every run but that one
     * is written to [OwnifySyncEnvironment.status].
     */
    suspend fun run(
        env: OwnifySyncEnvironment,
        automatic: Boolean,
        onStart: () -> Unit = {}
    ): OwnifySyncOutcome {
        if (automatic) {
            if (!running.tryLock()) return OwnifySyncOutcome.Busy
        } else {
            running.lock()
        }

        try {
            onStart()
            val outcome = sync(env, automatic)
            record(env, outcome, automatic)
            return outcome
        } finally {
            running.unlock()
        }
    }

    /** A run, and — when its token was replaced while it was sending — one more with the current one. */
    private suspend fun sync(env: OwnifySyncEnvironment, automatic: Boolean): OwnifySyncOutcome {
        val outcome = attempt(env, automatic)

        return if (outcome == OwnifySyncOutcome.TokenReplaced) attempt(env, automatic) else outcome
    }

    private suspend fun attempt(env: OwnifySyncEnvironment, automatic: Boolean): OwnifySyncOutcome {
        val token = env.token() ?: return OwnifySyncOutcome.NotConnected

        val access = try {
            env.healthAccess(automatic)
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            return OwnifySyncOutcome.ReadFailed
        }

        if (access != HealthAccess.AVAILABLE) {
            return OwnifySyncOutcome.NoAccess(access)
        }

        val to = env.now()
        val from = to.minus(RANGE)

        val reading = try {
            env.read(from, to)
        } catch (e: CancellationException) {
            throw e
        } catch (e: SecurityException) {
            // Health Connect decided Ownify is not in the foreground after all.
            return if (automatic) OwnifySyncOutcome.NoAccess(HealthAccess.BACKGROUND_NOT_ALLOWED) else OwnifySyncOutcome.ReadFailed
        } catch (e: Exception) {
            return OwnifySyncOutcome.ReadFailed
        }

        if (reading.notGranted.size == IngestPayload.TYPES.size) {
            return OwnifySyncOutcome.NoAccess(HealthAccess.NO_PERMISSION)
        }

        val payload = withContext(Dispatchers.Default) { IngestPayload.of(reading.records) }

        // Nothing to send is still sent: the server records that the phone
        // synced, and answers "0 written" rather than the app guessing it.
        val batches = IngestPayload.batches(payload, BATCH_SIZE).ifEmpty { listOf(JSONArray()) }
        val results = mutableListOf<IngestResult>()

        for ((index, batch) in batches.withIndex()) {
            when (val result = env.api.ingest(token, batch)) {
                is OwnifyResult.Success -> results += result.value

                is OwnifyResult.Unauthorized ->
                    return if (env.tokenRejected(token)) OwnifySyncOutcome.Unauthorized else OwnifySyncOutcome.TokenReplaced

                is OwnifyResult.Failure -> return OwnifySyncOutcome.SendFailed(result, index, batches.size)
            }
        }

        return OwnifySyncOutcome.Synced(
            OwnifySyncSummary(
                from = from,
                to = to,
                sent = IngestPayload.countByType(payload),
                notGranted = reading.notGranted,
                result = combine(results)
            )
        )
    }

    /** Only when, how, and how many — never what. */
    private fun record(env: OwnifySyncEnvironment, outcome: OwnifySyncOutcome, automatic: Boolean) {
        val kind = kindOf(outcome) ?: return
        val before = env.status.read()
        val now = env.now()
        val synced = outcome as? OwnifySyncOutcome.Synced

        env.status.write(
            before.copy(
                lastAttemptAt = now,
                lastOutcome = kind,
                lastWasAutomatic = automatic,
                lastSuccessAt = if (synced != null) now else before.lastSuccessAt,
                lastWritten = synced?.summary?.result?.written ?: before.lastWritten
            )
        )
    }

    internal fun kindOf(outcome: OwnifySyncOutcome): OwnifySyncOutcomeKind? =
        when (outcome) {
            is OwnifySyncOutcome.Synced -> OwnifySyncOutcomeKind.SYNCED
            OwnifySyncOutcome.NotConnected -> OwnifySyncOutcomeKind.NOT_CONNECTED
            OwnifySyncOutcome.Unauthorized -> OwnifySyncOutcomeKind.EXPIRED
            OwnifySyncOutcome.ReadFailed -> OwnifySyncOutcomeKind.READ_FAILED
            // Not this phone's state: the token it describes is already gone.
            OwnifySyncOutcome.TokenReplaced -> null
            OwnifySyncOutcome.Busy -> null
            is OwnifySyncOutcome.NoAccess -> when (outcome.access) {
                HealthAccess.NOT_INSTALLED -> OwnifySyncOutcomeKind.NO_HEALTH_CONNECT
                HealthAccess.NO_PERMISSION -> OwnifySyncOutcomeKind.NEEDS_HEALTH_ACCESS
                HealthAccess.BACKGROUND_NOT_ALLOWED -> OwnifySyncOutcomeKind.NEEDS_BACKGROUND_ACCESS
                HealthAccess.AVAILABLE -> null
            }
            is OwnifySyncOutcome.SendFailed ->
                if (outcome.failure == OwnifyResult.NetworkError) OwnifySyncOutcomeKind.OFFLINE else OwnifySyncOutcomeKind.SERVER_TROUBLE
        }
}

/** The phone itself: the encrypted token, Health Connect, and the Ownify server. */
internal class AndroidSyncEnvironment(context: Context) : OwnifySyncEnvironment {

    private val app = context.applicationContext

    override val api = OwnifyApi()

    override val status: OwnifySyncStatusStore = OwnifySyncStatusPrefs(app)

    override suspend fun token(): String? = OwnifyConnection.storedToken(app)

    override suspend fun tokenRejected(token: String): Boolean = OwnifyConnection.rejected(app, token)

    override suspend fun healthAccess(automatic: Boolean): HealthAccess {
        if (HealthConnectClient.getSdkStatus(app) != HealthConnectClient.SDK_AVAILABLE) {
            return HealthAccess.NOT_INSTALLED
        }

        val granted = HealthConnectClient.getOrCreate(app).permissionController.getGrantedPermissions()

        if (IngestPayload.TYPES.none { HealthPermission.getReadPermission(it) in granted }) {
            return HealthAccess.NO_PERMISSION
        }

        if (automatic && !inForeground() &&
            HealthPermission.PERMISSION_READ_HEALTH_DATA_IN_BACKGROUND !in granted
        ) {
            return HealthAccess.BACKGROUND_NOT_ALLOWED
        }

        return HealthAccess.AVAILABLE
    }

    override suspend fun read(from: Instant, to: Instant): HealthConnectSyncReader.Reading =
        HealthConnectSyncReader(HealthConnectClient.getOrCreate(app)).read(IngestPayload.TYPES, from, to)

    /** Whether Ownify is on screen: Health Connect reads freely for an app in the foreground. */
    private fun inForeground(): Boolean {
        val info = ActivityManager.RunningAppProcessInfo()
        ActivityManager.getMyMemoryState(info)
        return info.importance <= ActivityManager.RunningAppProcessInfo.IMPORTANCE_FOREGROUND
    }
}
