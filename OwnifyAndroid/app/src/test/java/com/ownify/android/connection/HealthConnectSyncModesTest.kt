package com.ownify.android.connection

import android.content.Context
import androidx.health.connect.client.records.BodyTemperatureMeasurementLocation
import androidx.health.connect.client.records.BodyTemperatureRecord
import androidx.health.connect.client.records.HeartRateRecord
import androidx.health.connect.client.records.LeanBodyMassRecord
import androidx.health.connect.client.records.Record
import androidx.health.connect.client.records.RespiratoryRateRecord
import androidx.health.connect.client.records.SkinTemperatureRecord
import androidx.health.connect.client.records.SleepSessionRecord
import androidx.health.connect.client.records.StepsRecord
import androidx.health.connect.client.units.Mass
import androidx.health.connect.client.units.Temperature
import androidx.health.connect.client.units.TemperatureDelta
import androidx.test.core.app.ApplicationProvider
import java.time.Duration
import java.time.Instant
import java.util.concurrent.CopyOnWriteArrayList
import kotlin.reflect.KClass
import kotlinx.coroutines.runBlocking
import org.json.JSONObject
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/**
 * 43.0 — the three reads of the one sync (OwnifySyncRunner, docs/HEALTH-CONNECT.md),
 * run as the phone runs them — the real runner, IngestPayload, OwnifyApi
 * over HTTP to a Ownify server on localhost, the real status file — with
 * Health Connect stood in for by [Phone], which answers reads by their time
 * range and keeps a changes log the way Health Connect does:
 *
 *   HISTORY  a newly connected phone: 30 days, a week at a time, newest
 *            first, carrying on where a stopped one left off
 *   FULL     the week, once a day and whenever the changes cannot be read,
 *            taking a changes token
 *   CHANGES  only what changed since, deletions included, in order; the
 *            token stored only once everything arrived
 *
 * and the four types added in 43.0 in Health Connect's own shape.
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [36])
class HealthConnectSyncModesTest {

    private lateinit var context: Context
    private lateinit var server: FakeOwnifyServer
    private lateinit var phone: Phone

    /** Health Connect on a phone: records by time, a changes log, and its answers counted. */
    private inner class Phone(var clock: Instant) : OwnifySyncEnvironment {
        /** The server, until [failAfter] requests have gone to it: then nothing answers. */
        var live = OwnifyApi(server.url)
        var failAfter = Int.MAX_VALUE
        private var calls = 0
        override val api: OwnifyApi get() = if (calls++ < failAfter) live else OwnifyApi("http://127.0.0.1:1/")
        override val status: OwnifySyncStatusStore get() = OwnifySyncStatusPrefs(context)
        override suspend fun token(): String? = OwnifyConnection.storedToken(context)
        override suspend fun tokenRejected(token: String) = OwnifyConnection.rejected(context, token)
        override suspend fun healthAccess(automatic: Boolean) = HealthAccess.AVAILABLE
        override fun now(): Instant = clock

        val records = mutableListOf<Record>()
        var granted: List<KClass<out Record>> = IngestPayload.TYPES
        val reads = CopyOnWriteArrayList<Pair<Instant, Instant>>()
        val typeReads = CopyOnWriteArrayList<KClass<out Record>>()
        val tokensAsked = CopyOnWriteArrayList<String>()

        /** Health Connect's changes log: every change since the beginning; a token is a place in it. */
        val log = mutableListOf<HealthChange>()
        var expired = false
        var refuse = false
        var pageSize = 100

        fun write(record: Record) {
            records.removeAll { it.metadata.id == record.metadata.id }
            records += record
            log += HealthChange.Written(record)
        }

        fun delete(id: String) {
            records.removeAll { it.metadata.id == id }
            log += HealthChange.Deleted(id)
        }

        private fun inRange(r: Record, from: Instant, to: Instant): Boolean = when (r) {
            is SleepSessionRecord -> r.endTime > from && r.startTime < to
            is HeartRateRecord -> r.endTime > from && r.startTime < to
            is StepsRecord -> r.endTime > from && r.startTime < to
            is RespiratoryRateRecord -> r.time >= from && r.time < to
            else -> true
        }

        override suspend fun read(from: Instant, to: Instant): HealthConnectSyncReader.Reading {
            reads += from to to
            return HealthConnectSyncReader.Reading(records.filter { inRange(it, from, to) }, emptyList())
        }

        override suspend fun grantedTypes() = granted

        override suspend fun changesToken(types: List<KClass<out Record>>): String {
            tokensAsked += types.joinToString { it.simpleName.orEmpty() }
            return "t" + log.size
        }

        override suspend fun changes(token: String): ChangesPage {
            if (refuse) throw IllegalArgumentException("unknown token")
            val at = token.removePrefix("t").toInt()
            if (expired) return ChangesPage(emptyList(), token, hasMore = false, expired = true)
            val page = log.drop(at).take(pageSize)
            val next = at + page.size
            return ChangesPage(page, "t$next", hasMore = next < log.size, expired = false)
        }

        override suspend fun readTypes(types: List<KClass<out Record>>, from: Instant, to: Instant): HealthConnectSyncReader.Reading {
            typeReads += types
            return HealthConnectSyncReader.Reading(records.filter { r -> types.any { it.isInstance(r) } && inRange(r, from, to) }, emptyList())
        }
    }

    private val now = SyncFixtures.at("2026-09-30T12:00:00")

    @Before
    fun setUp() {
        context = ApplicationProvider.getApplicationContext()
        server = FakeOwnifyServer()
        OwnifyConnection.api = OwnifyApi(server.url)
        OwnifyConnection.storage = { MemoryTokenStorage }
        MemoryTokenStorage.clear()
        OwnifyConnection.reset()
        OwnifySyncStatusPrefs(context).clear()
        MemoryTokenStorage.token = TEST_TOKEN
        phone = Phone(now)
    }

    @After
    fun tearDown() {
        server.stop()
    }

    private fun sync(): OwnifySyncOutcome = runBlocking { OwnifySyncRunner.run(phone, automatic = true) }

    private fun status() = OwnifySyncStatusPrefs(context).read()

    private fun steps(id: String, day: String) =
        StepsRecord(SyncFixtures.at("${day}T10:00:00"), SyncFixtures.CEST, SyncFixtures.at("${day}T10:30:00"), SyncFixtures.CEST, 1000, SyncFixtures.meta(id))

    private fun sentIds(from: Int = 0): List<String> = server.ingests.drop(from).flatMap { request ->
        val records = request.body.getJSONArray("records")
        (0 until records.length()).map { records.getJSONObject(it).getJSONObject("metadata").getString("id") }
    }

    private fun sentDeleted(from: Int = 0): List<String> = server.ingests.drop(from).flatMap { request ->
        val deleted = request.body.optJSONArray("deleted") ?: return@flatMap emptyList<String>()
        (0 until deleted.length()).map { deleted.getString(it) }
    }

    // ------------------------------------------------------------- HISTORY

    @Test
    fun `a newly connected phone - 30 days, a week at a time, newest first, then done`() {
        for (day in listOf("2026-09-29", "2026-09-20", "2026-09-10", "2026-09-02")) phone.write(steps("s-$day", day))
        phone.write(steps("too-old", "2026-08-20"))

        val outcome = sync()
        assertTrue(outcome is OwnifySyncOutcome.Synced)
        assertEquals(SyncMode.HISTORY, (outcome as OwnifySyncOutcome.Synced).summary.mode)
        assertEquals("five reads: four weeks and the two days before them", 5, phone.reads.size)
        assertEquals("newest first", now, phone.reads.first().second)
        assertEquals("back to 30 days", now.minus(Duration.ofDays(30)), phone.reads.last().first)
        for ((from, to) in phone.reads) assertTrue("no read longer than a week", Duration.between(from, to) <= Duration.ofDays(7))
        assertEquals("a week's records are sent before the next is read", listOf("s-2026-09-29", "s-2026-09-20", "s-2026-09-10", "s-2026-09-02"), sentIds())
        assertFalse("not older than 30 days", "too-old" in sentIds())
        assertTrue(status().historyDone)
        assertTrue("the month held the month of meals", status().mealHistorySent)
        assertNull(status().historyFrom)
        assertNull("no changes token yet: the next run is a full one", status().changesToken)
    }

    @Test
    fun `a history that stops carries on where it left off - nothing sent twice that arrived`() {
        for (day in listOf("2026-09-29", "2026-09-20", "2026-09-10")) phone.write(steps("s-$day", day))
        server.ingestMode = FakeOwnifyServer.IngestMode.OK

        // The server goes down after the first week arrives.
        phone.failAfter = 1
        assertTrue(sync() is OwnifySyncOutcome.SendFailed)
        assertFalse(status().historyDone)
        assertEquals("the first week is behind it", now.minus(Duration.ofDays(7)), status().historyFrom)

        phone.failAfter = Int.MAX_VALUE
        phone.reads.clear()
        assertTrue(sync() is OwnifySyncOutcome.Synced)
        assertEquals("from where it stopped", now.minus(Duration.ofDays(7)), phone.reads.first().second)
        assertEquals(listOf("s-2026-09-29", "s-2026-09-20", "s-2026-09-10"), sentIds())
        assertTrue(status().historyDone)
    }

    @Test
    fun `a phone that synced before 43_0 has had its first sync`() {
        context.getSharedPreferences("ownify_sync_status", Context.MODE_PRIVATE).edit()
            .putLong("last_success_at", now.minus(Duration.ofHours(1)).toEpochMilli()).commit()
        assertTrue(status().historyDone)
        phone.write(steps("s1", "2026-09-29"))
        val outcome = sync() as OwnifySyncOutcome.Synced
        assertEquals("straight to the week", SyncMode.FULL, outcome.summary.mode)
        assertEquals(1, phone.reads.size)
    }

    // ----------------------------------------------------- FULL, then CHANGES

    @Test
    fun `after the history - a full week with a token, then only the changes`() {
        phone.write(steps("s1", "2026-09-29"))
        sync()                                          // history
        val full = sync() as OwnifySyncOutcome.Synced   // full: the week, and a token
        assertEquals(SyncMode.FULL, full.summary.mode)
        assertEquals("t1", status().changesToken)
        assertEquals(now, status().lastFullAt)

        val before = server.ingests.size
        phone.reads.clear()
        phone.clock = now.plus(Duration.ofHours(1))
        phone.write(steps("s2", "2026-09-30"))
        val changes = sync() as OwnifySyncOutcome.Synced
        assertEquals(SyncMode.CHANGES, changes.summary.mode)
        assertEquals("Health Connect not read by range at all", 0, phone.reads.size)
        assertEquals("only the new record", listOf("s2"), sentIds(before))
        assertEquals("t2", status().changesToken)

        // Nothing changed: one empty request, so the server knows the phone synced.
        val quiet = server.ingests.size
        phone.clock = now.plus(Duration.ofHours(2))
        assertTrue(sync() is OwnifySyncOutcome.Synced)
        assertEquals(1, server.ingests.size - quiet)
        assertEquals(0, server.ingests.last().body.getJSONArray("records").length())
    }

    @Test
    fun `deletions go to the server, in order with what was written`() {
        phone.write(steps("keep", "2026-09-29"))
        phone.write(steps("gone", "2026-09-29"))
        sync(); sync()
        val before = server.ingests.size
        phone.clock = now.plus(Duration.ofHours(1))

        phone.delete("gone")                        // deleted
        phone.write(steps("brief", "2026-09-30"))   // written, then deleted: only deleted
        phone.delete("brief")
        phone.delete("back")                        // deleted, then written again: sent
        phone.write(steps("back", "2026-09-30"))
        val outcome = sync() as OwnifySyncOutcome.Synced

        assertEquals(listOf("back"), sentIds(before))
        assertEquals(listOf("gone", "brief"), sentDeleted(before))
        assertEquals(2, outcome.summary.deleted)
    }

    @Test
    fun `a failed send keeps the old token - the same changes are read again`() {
        phone.write(steps("s1", "2026-09-29"))
        sync(); sync()
        val token = status().changesToken
        phone.clock = now.plus(Duration.ofHours(1))
        phone.write(steps("s2", "2026-09-30"))

        phone.failAfter = 0
        assertTrue(sync() is OwnifySyncOutcome.SendFailed)
        assertEquals("not moved on", token, status().changesToken)

        phone.failAfter = Int.MAX_VALUE
        val before = server.ingests.size
        sync()
        assertEquals(listOf("s2"), sentIds(before))
    }

    @Test
    fun `an expired or refused token falls back to the full week, with a new token`() {
        phone.write(steps("s1", "2026-09-29"))
        sync(); sync()
        phone.clock = now.plus(Duration.ofHours(1))

        phone.expired = true
        var outcome = sync() as OwnifySyncOutcome.Synced
        assertEquals(SyncMode.FULL, outcome.summary.mode)
        assertTrue("s1" in sentIds(server.ingests.size - 1))
        phone.expired = false

        phone.clock = now.plus(Duration.ofHours(2))
        phone.refuse = true
        outcome = sync() as OwnifySyncOutcome.Synced
        assertEquals(SyncMode.FULL, outcome.summary.mode)
        assertEquals(3, phone.tokensAsked.size)
    }

    @Test
    fun `once a day the full week again - and when other types are granted`() {
        phone.write(steps("s1", "2026-09-29"))
        sync(); sync()
        phone.clock = now.plus(Duration.ofHours(23))
        assertEquals(SyncMode.CHANGES, (sync() as OwnifySyncOutcome.Synced).summary.mode)
        phone.clock = now.plus(Duration.ofHours(24))
        assertEquals(SyncMode.FULL, (sync() as OwnifySyncOutcome.Synced).summary.mode)

        phone.clock = now.plus(Duration.ofHours(25))
        assertEquals(SyncMode.CHANGES, (sync() as OwnifySyncOutcome.Synced).summary.mode)
        phone.granted = IngestPayload.TYPES - RespiratoryRateRecord::class
        phone.clock = now.plus(Duration.ofHours(26))
        assertEquals("the token covered other types", SyncMode.FULL, (sync() as OwnifySyncOutcome.Synced).summary.mode)
    }

    @Test
    fun `many changes - at most twenty pages a run, the rest the next run`() {
        sync(); sync()
        phone.clock = now.plus(Duration.ofHours(1))
        phone.pageSize = 2
        for (i in 1..50) phone.write(steps("m$i", "2026-09-30"))
        val before = server.ingests.size
        sync()
        assertEquals(40, sentIds(before).size)
        val after = server.ingests.size
        phone.clock = now.plus(Duration.ofHours(2))
        sync()
        assertEquals((41..50).map { "m$it" }, sentIds(after))
    }

    @Test
    fun `heart rate that changed goes with the night around it, for its sleeping heart rate`() {
        val night = SyncFixtures.day("n").first { it is SleepSessionRecord }
        phone.write(night)
        sync(); sync()
        phone.clock = now.plus(Duration.ofHours(1))
        val before = server.ingests.size
        phone.write(SyncFixtures.day("n").first { it.metadata.id == "n-hr-night" })
        sync()
        val sent = server.ingests.drop(before).flatMap { r -> (0 until r.body.getJSONArray("records").length()).map { r.body.getJSONArray("records").getJSONObject(it) } }
        val hr = sent.first { it.getString("recordType") == "HeartRate" }
        assertEquals("the samples in the night", 2, hr.getJSONArray("samples").length())
        assertTrue(phone.typeReads.contains(SleepSessionRecord::class))
    }

    // --------------------------------------------------- the four new types

    @Test
    fun `the four types added in 43_0 - in Health Connect's own names and units`() {
        val at = SyncFixtures.at("2026-09-25T03:00:00")
        val sent = IngestPayload.of(
            listOf(
                RespiratoryRateRecord(at, SyncFixtures.CEST, 14.5, SyncFixtures.meta("rr")),
                LeanBodyMassRecord(at, SyncFixtures.CEST, Mass.kilograms(58.2), SyncFixtures.meta("lbm")),
                BodyTemperatureRecord(at, SyncFixtures.CEST, SyncFixtures.meta("bt"), Temperature.celsius(34.1),
                    BodyTemperatureMeasurementLocation.MEASUREMENT_LOCATION_WRIST),
                SkinTemperatureRecord(
                    SyncFixtures.at("2026-09-25T00:00:00"), SyncFixtures.CEST, SyncFixtures.at("2026-09-25T06:00:00"), SyncFixtures.CEST,
                    SyncFixtures.meta("skin"),
                    listOf(SkinTemperatureRecord.Delta(SyncFixtures.at("2026-09-25T01:00:00"), TemperatureDelta.celsius(0.2))),
                    Temperature.celsius(33.4),
                    SkinTemperatureRecord.MEASUREMENT_LOCATION_FINGER
                )
            )
        ).associateBy { it.getJSONObject("metadata").getString("id") }

        assertEquals(14.5, sent.getValue("rr").getDouble("rate"), 1e-9)
        assertEquals("2026-09-25T03:00:00+02:00", sent.getValue("rr").getString("time"))
        assertEquals(58.2, sent.getValue("lbm").getJSONObject("mass").getDouble("kilograms"), 1e-9)
        sent.getValue("bt").let {
            assertEquals(34.1, it.getJSONObject("temperature").getDouble("celsius"), 1e-9)
            assertEquals(BodyTemperatureMeasurementLocation.MEASUREMENT_LOCATION_WRIST, it.getInt("measurementLocation"))
        }
        sent.getValue("skin").let {
            assertEquals("SkinTemperature", it.getString("recordType"))
            assertEquals(33.4, it.getJSONObject("baseline").getDouble("celsius"), 1e-9)
            val delta: JSONObject = it.getJSONArray("deltas").getJSONObject(0)
            assertEquals(0.2, delta.getJSONObject("delta").getDouble("celsius"), 1e-9)
            assertEquals("2026-09-25T06:00:00+02:00", it.getString("endTime"))
        }
        assertTrue("asked for, and declared", IngestPayload.permissions().containsAll(listOf(
            "android.permission.health.READ_RESPIRATORY_RATE", "android.permission.health.READ_SKIN_TEMPERATURE",
            "android.permission.health.READ_BODY_TEMPERATURE", "android.permission.health.READ_LEAN_BODY_MASS"
        )))
    }
}
