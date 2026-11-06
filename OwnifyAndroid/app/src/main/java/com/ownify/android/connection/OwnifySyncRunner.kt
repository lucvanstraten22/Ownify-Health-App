package com.ownify.android.connection

import android.app.ActivityManager
import android.content.Context
import androidx.health.connect.client.HealthConnectClient
import androidx.health.connect.client.permission.HealthPermission
import androidx.health.connect.client.records.HeartRateRecord
import androidx.health.connect.client.records.NutritionRecord
import androidx.health.connect.client.records.Record
import androidx.health.connect.client.records.SleepSessionRecord
import java.time.Duration
import java.time.Instant
import kotlin.reflect.KClass
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

    /**
     * Meals only, between [from] and [to]: the one-time history (31.0, R7)
     * whose caffeine combined insights read. None by default.
     */
    suspend fun readMeals(from: Instant, to: Instant): HealthConnectSyncReader.Reading =
        HealthConnectSyncReader.Reading(emptyList(), emptyList())

    fun now(): Instant = Instant.now()

    /**
     * 43.0 — how far back the first sync of a newly connected phone reads:
     * the 30 days Health Connect lets an app see. Tests keep the week.
     */
    val historyRange: Duration get() = OwnifySyncRunner.HISTORY

    /** The record types Ownify may read now, of the ones it sends. */
    suspend fun grantedTypes(): List<KClass<out Record>> = IngestPayload.TYPES

    /** A changes token for [types], or null where there are no changes to read (a phone without them, a test). */
    suspend fun changesToken(types: List<KClass<out Record>>): String? = null

    /** What changed since [token]: one page. */
    suspend fun changes(token: String): ChangesPage = throw UnsupportedOperationException("no changes here")

    /** [types] only, between [from] and [to]: what a change needs around it (a night for heart rate, and the other way round). */
    suspend fun readTypes(types: List<KClass<out Record>>, from: Instant, to: Instant): HealthConnectSyncReader.Reading =
        HealthConnectSyncReader.Reading(emptyList(), emptyList())
}

/** 43.0: which kind of read a sync was. */
enum class SyncMode {
    /** The first sync of a newly connected phone: [OwnifySyncRunner.HISTORY], a week at a time. */
    HISTORY,

    /** Everything of the last [OwnifySyncRunner.RANGE] — once a day, and whenever the changes cannot be read. */
    FULL,

    /** Only what changed in Health Connect since the last sync, deletions included. */
    CHANGES
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
 * THE sync — the only one. A run asked for in the app (OwnifySync.sync) and the
 * background worker (OwnifySyncWorker) both run exactly this:
 *
 *   1. the stored token — none: nothing is read or sent;
 *   2. Health Connect access — none: nothing is sent;
 *   3. the records (HealthConnectSyncReader), granted types only — one of
 *      three reads (43.0, [SyncMode]):
 *        HISTORY  the first sync of a newly connected phone: [HISTORY] back,
 *                 a week at a time, each week sent before the next is read,
 *                 carrying on where a stopped one left off;
 *        CHANGES  after that, only what changed in Health Connect since the
 *                 last sync (its changes token), deletions included — the
 *                 hourly sync reads a handful of records, not a week;
 *        FULL     the last [RANGE], as every sync did before 43.0: once a
 *                 day as a safety net, and whenever the changes cannot be
 *                 read (no token yet, an expired or refused one, other types
 *                 granted);
 *   4. the JSON (IngestPayload), Health Connect's own format;
 *   5. POST in batches (OwnifyApi.ingest), and the answers added up.
 *
 * Where the next sync carries on (the changes token, the history's place)
 * is stored only once everything up to it has arrived: a run that fails
 * reads the same again next time, and the server's upsert makes that safe.
 *
 * Duplicates are the server's job: every record carries Health Connect's own
 * id, and the server updates a record it already has and counts overlapping
 * apps once, so sending the same days again is safe and earns nothing twice.
 * Nothing here adds up, removes or judges a record.
 *
 * One run at a time: an automatic run that finds one going leaves it to it;
 * one asked for in the app waits for it and then runs, as it always has.
 *
 * A 401 forgets the token only if it is still the stored one. Signing in on a
 * paired phone replaces the sync token with an account token, and a run that
 * was already sending with the old one gets a 401 for it: that run starts
 * again, once, with the token stored now, rather than signing out the person
 * who just signed in.
 */
internal object OwnifySyncRunner {

    /** How far back a full sync reads, counted from the moment it starts. */
    val RANGE: Duration = Duration.ofDays(7)

    /** 43.0: how far back the first sync of a newly connected phone reads — the 30 days Health Connect gives an app. */
    val HISTORY: Duration = Duration.ofDays(30)

    /** 43.0: the history is read and sent this much at a time. */
    val HISTORY_WINDOW: Duration = Duration.ofDays(7)

    /** 43.0: a full read of the week at least this often, behind the changes. */
    val FULL_EVERY: Duration = Duration.ofHours(24)

    /** 43.0: at most this many pages of changes per run; the rest the next run (the token is where this one stopped). */
    private const val MAX_CHANGE_PAGES = 20

    /** Deleted ids per request. ingest.php accepts up to 2000. */
    private const val DELETED_BATCH = 1000

    /**
     * Once, on the first sync after 31.0: meals as far back as this, for
     * their caffeine (R7). Health Connect gives an app 30 days of history.
     */
    val MEAL_HISTORY: Duration = Duration.ofDays(30)

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

        val types = try {
            env.grantedTypes()
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            return OwnifySyncOutcome.ReadFailed
        }

        if (types.isEmpty()) {
            return OwnifySyncOutcome.NoAccess(HealthAccess.NO_PERMISSION)
        }

        val state = env.status.read()
        val now = env.now()
        val key = typesKey(types)

        return when {
            !state.historyDone -> history(env, token, automatic, now)
            state.changesToken != null && state.changesTypes == key &&
                state.lastFullAt != null && Duration.between(state.lastFullAt, now) < FULL_EVERY ->
                changes(env, token, automatic, now, state.changesToken, types)
            else -> full(env, token, automatic, now, types)
        }
    }

    /** The granted types as one string, to tell whether a changes token still covers them. */
    private fun typesKey(types: List<KClass<out Record>>): String =
        types.mapNotNull { it.simpleName }.sorted().joinToString(",")

    // ---------------------------------------------------------- HISTORY

    /**
     * The first sync: [OwnifySyncEnvironment.historyRange] back, the newest
     * week first so this week shows soonest, each week read and sent before
     * the next is read — a month of second-by-second heart rate is never in
     * memory at once. After every week the place is stored: a run that
     * stops carries on from there. Done, the next run is a [SyncMode.FULL],
     * which takes the changes token.
     */
    private suspend fun history(env: OwnifySyncEnvironment, token: String, automatic: Boolean, now: Instant): OwnifySyncOutcome {
        val oldest = now.minus(env.historyRange)
        var end = env.status.read().historyFrom?.takeIf { it.isAfter(oldest) && !it.isAfter(now) } ?: now
        val results = mutableListOf<IngestResult>()
        val sent = mutableMapOf<String, Int>()
        val notGranted = mutableSetOf<String>()
        var mealsRead: HealthConnectSyncReader.Reading? = null

        while (end.isAfter(oldest)) {
            val start = maxOf(oldest, end.minus(HISTORY_WINDOW))
            val reading = when (val read = readSafely(automatic) { env.read(start, end) }) {
                is Read.Ok -> read.reading
                is Read.Failed -> return read.outcome
            }
            if (reading.notGranted.size == IngestPayload.TYPES.size) {
                return OwnifySyncOutcome.NoAccess(HealthAccess.NO_PERMISSION)
            }
            notGranted += reading.notGranted

            // The usual week-only history before 43.0 still sends its month of meals once (31.0).
            val last = !start.isAfter(oldest)
            val meals = if (last) mealHistory(env, now, start) else null
            if (meals != null) mealsRead = meals

            val records = if (meals == null) reading.records else meals.records + reading.records
            val payload = withContext(Dispatchers.Default) { IngestPayload.of(records) }
            val batches = IngestPayload.batches(payload, BATCH_SIZE)
                .ifEmpty { if (last && results.isEmpty()) listOf(JSONArray()) else emptyList() }

            when (val outcome = send(env, token, batches, emptyList(), results)) {
                null -> Unit
                else -> return outcome
            }
            IngestPayload.countByType(payload).forEach { (type, n) -> sent[type] = (sent[type] ?: 0) + n }

            end = start
            env.status.write(env.status.read().copy(historyFrom = end))
        }

        val covered = env.historyRange >= MEAL_HISTORY && NutritionRecord::class.simpleName!!.removeSuffix("Record") !in notGranted
        env.status.write(
            env.status.read().copy(
                historyDone = true,
                historyFrom = null,
                // A month of history held the month of meals; otherwise as before 43.0.
                mealHistorySent = env.status.read().mealHistorySent || covered ||
                    (mealsRead != null && mealsRead.notGranted.isEmpty()),
                changesToken = null,
                lastFullAt = null
            )
        )

        return OwnifySyncOutcome.Synced(
            OwnifySyncSummary(oldest, now, sent, notGranted.toList(), combine(results), SyncMode.HISTORY)
        )
    }

    // ------------------------------------------------------------- FULL

    /**
     * The last [RANGE], every granted type — the sync as it was before
     * 43.0 — and a new changes token, asked for before reading, so nothing
     * written while this runs falls between the two.
     */
    private suspend fun full(
        env: OwnifySyncEnvironment,
        token: String,
        automatic: Boolean,
        now: Instant,
        types: List<KClass<out Record>>
    ): OwnifySyncOutcome {
        val changesToken = try {
            env.changesToken(types)
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            null        // the changes stay unread; the next run is a full one again
        }

        val from = now.minus(RANGE)
        val reading = when (val read = readSafely(automatic) { env.read(from, now) }) {
            is Read.Ok -> read.reading
            is Read.Failed -> return read.outcome
        }

        if (reading.notGranted.size == IngestPayload.TYPES.size) {
            return OwnifySyncOutcome.NoAccess(HealthAccess.NO_PERMISSION)
        }

        // Once (31.0, R7): the meals before the usual week, for their
        // caffeine. A failed read is tried again on the next run.
        val history = mealHistory(env, now, from)

        val records = if (history == null) reading.records else history.records + reading.records
        val payload = withContext(Dispatchers.Default) { IngestPayload.of(records) }

        // Nothing to send is still sent: the server records that the phone
        // synced, and answers "0 written" rather than the app guessing it.
        val batches = IngestPayload.batches(payload, BATCH_SIZE).ifEmpty { listOf(JSONArray()) }
        val results = mutableListOf<IngestResult>()

        send(env, token, batches, emptyList(), results)?.let { return it }

        // Sent — unless meals may not be read yet: then on a later run.
        val before = env.status.read()
        env.status.write(
            before.copy(
                mealHistorySent = before.mealHistorySent || (history != null && history.notGranted.isEmpty()),
                changesToken = changesToken,
                changesTypes = if (changesToken == null) null else typesKey(types),
                lastFullAt = now
            )
        )

        return OwnifySyncOutcome.Synced(
            OwnifySyncSummary(from, now, IngestPayload.countByType(payload), reading.notGranted, combine(results), SyncMode.FULL)
        )
    }

    /** The meals before the usual week (31.0), when they have not been sent yet and the read reaches less far. */
    private suspend fun mealHistory(env: OwnifySyncEnvironment, now: Instant, from: Instant): HealthConnectSyncReader.Reading? {
        val state = env.status.read()
        // A month of history holds the month of meals itself.
        if (state.mealHistorySent || (env.historyRange >= MEAL_HISTORY && !state.historyDone)) {
            return null
        }
        return try {
            env.readMeals(now.minus(MEAL_HISTORY), from)
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            null
        }
    }

    // ---------------------------------------------------------- CHANGES

    /**
     * What changed since [since], in the order it happened: a record written
     * and then deleted is deleted, one deleted and written again is sent.
     * A token Health Connect let expire, or one it refuses, falls back to
     * [full] — which takes a new one. Heart rate goes with the night around
     * it and a night with the heart rate in it, so a night's sleeping heart
     * rate is worked out from both however they arrived.
     */
    private suspend fun changes(
        env: OwnifySyncEnvironment,
        token: String,
        automatic: Boolean,
        now: Instant,
        since: String,
        types: List<KClass<out Record>>
    ): OwnifySyncOutcome {
        val written = LinkedHashMap<String, Record>()
        val deleted = LinkedHashSet<String>()
        var next = since
        var pages = 0

        try {
            do {
                val page = env.changes(next)
                if (page.expired) {
                    return full(env, token, automatic, now, types)
                }
                for (change in page.changes) {
                    when (change) {
                        is HealthChange.Written -> {
                            deleted -= change.record.metadata.id
                            written[change.record.metadata.id] = change.record
                        }
                        is HealthChange.Deleted -> {
                            written -= change.id
                            deleted += change.id
                        }
                    }
                }
                next = page.next
                pages++
            } while (page.hasMore && pages < MAX_CHANGE_PAGES)
        } catch (e: CancellationException) {
            throw e
        } catch (e: SecurityException) {
            return if (automatic) OwnifySyncOutcome.NoAccess(HealthAccess.BACKGROUND_NOT_ALLOWED) else OwnifySyncOutcome.ReadFailed
        } catch (e: Exception) {
            // A token Health Connect no longer knows: start again from a full read.
            return full(env, token, automatic, now, types)
        }

        val records = written.values.toMutableList()
        val context = when (val read = readSafely(automatic) { around(env, records) }) {
            is Read.Ok -> read.reading.records
            is Read.Failed -> return read.outcome
        }
        context.filter { it.metadata.id !in written && it.metadata.id !in deleted }.forEach { records += it }

        val payload = withContext(Dispatchers.Default) { IngestPayload.of(records) }
        val batches = IngestPayload.batches(payload, BATCH_SIZE)
        val gone = deleted.toList().chunked(DELETED_BATCH)
        val results = mutableListOf<IngestResult>()

        // Nothing changed is still a sync the server hears about.
        send(env, token, batches.ifEmpty { if (gone.isEmpty()) listOf(JSONArray()) else emptyList() }, emptyList(), results)?.let { return it }
        for (ids in gone) {
            send(env, token, listOf(JSONArray()), ids, results)?.let { return it }
        }

        env.status.write(env.status.read().copy(changesToken = next))

        return OwnifySyncOutcome.Synced(
            OwnifySyncSummary(
                from = env.status.read().lastSuccessAt ?: now,
                to = now,
                sent = IngestPayload.countByType(payload),
                notGranted = emptyList(),
                result = combine(results),
                mode = SyncMode.CHANGES,
                deleted = deleted.size
            )
        )
    }

    /** The nights around changed heart rate, and the heart rate in changed nights. */
    private suspend fun around(env: OwnifySyncEnvironment, records: List<Record>): HealthConnectSyncReader.Reading {
        val out = mutableListOf<Record>()
        val hearts = records.filterIsInstance<HeartRateRecord>()
        if (hearts.isNotEmpty()) {
            out += env.readTypes(
                listOf(SleepSessionRecord::class),
                hearts.minOf { it.startTime }.minus(Duration.ofDays(1)),
                hearts.maxOf { it.endTime }
            ).records
        }
        val nights = records.filterIsInstance<SleepSessionRecord>()
        if (nights.isNotEmpty()) {
            out += env.readTypes(listOf(HeartRateRecord::class), nights.minOf { it.startTime }, nights.maxOf { it.endTime }).records
        }
        return HealthConnectSyncReader.Reading(out.distinctBy { it.metadata.id }, emptyList())
    }

    // ------------------------------------------------------------ parts

    private sealed interface Read {
        class Ok(val reading: HealthConnectSyncReader.Reading) : Read
        class Failed(val outcome: OwnifySyncOutcome) : Read
    }

    /** A read from Health Connect, its failures as outcomes. */
    private suspend fun readSafely(automatic: Boolean, read: suspend () -> HealthConnectSyncReader.Reading): Read =
        try {
            Read.Ok(read())
        } catch (e: CancellationException) {
            throw e
        } catch (e: SecurityException) {
            // Health Connect decided Ownify is not in the foreground after all.
            Read.Failed(if (automatic) OwnifySyncOutcome.NoAccess(HealthAccess.BACKGROUND_NOT_ALLOWED) else OwnifySyncOutcome.ReadFailed)
        } catch (e: Exception) {
            Read.Failed(OwnifySyncOutcome.ReadFailed)
        }

    /** [batches] (and [deleted], with the first) to the server, the answers into [results]; null when all arrived. */
    private suspend fun send(
        env: OwnifySyncEnvironment,
        token: String,
        batches: List<JSONArray>,
        deleted: List<String>,
        results: MutableList<IngestResult>
    ): OwnifySyncOutcome? {
        for ((index, batch) in batches.withIndex()) {
            when (val result = env.api.ingest(token, batch, if (index == 0) deleted else emptyList())) {
                is OwnifyResult.Success -> results += result.value

                is OwnifyResult.Unauthorized ->
                    return if (env.tokenRejected(token)) OwnifySyncOutcome.Unauthorized else OwnifySyncOutcome.TokenReplaced

                is OwnifyResult.Failure -> return OwnifySyncOutcome.SendFailed(result, index, batches.size)
            }
        }
        return null
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

    override suspend fun grantedTypes(): List<KClass<out Record>> {
        val client = HealthConnectClient.getOrCreate(app)
        val granted = client.permissionController.getGrantedPermissions()
        return IngestPayload.availableTypes(client).filter { HealthPermission.getReadPermission(it) in granted }
    }

    override suspend fun changesToken(types: List<KClass<out Record>>): String =
        HealthConnectSyncReader(HealthConnectClient.getOrCreate(app)).changesToken(types)

    override suspend fun changes(token: String): ChangesPage =
        HealthConnectSyncReader(HealthConnectClient.getOrCreate(app)).changes(token)

    override suspend fun readTypes(types: List<KClass<out Record>>, from: Instant, to: Instant): HealthConnectSyncReader.Reading =
        HealthConnectSyncReader(HealthConnectClient.getOrCreate(app)).read(types, from, to)

    override suspend fun readMeals(from: Instant, to: Instant): HealthConnectSyncReader.Reading =
        HealthConnectSyncReader(HealthConnectClient.getOrCreate(app)).read(listOf(NutritionRecord::class), from, to)

    /** Whether Ownify is on screen: Health Connect reads freely for an app in the foreground. */
    private fun inForeground(): Boolean {
        val info = ActivityManager.RunningAppProcessInfo()
        ActivityManager.getMyMemoryState(info)
        return info.importance <= ActivityManager.RunningAppProcessInfo.IMPORTANCE_FOREGROUND
    }
}
