package com.ownify.android.connection

import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/**
 * The JSON a sync sends, record by record, as includes/health-connect-map.php
 * on the server reads it.
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [36])
class IngestPayloadTest {

    private val payload = IngestPayload.of(SyncFixtures.day("p"))

    private fun record(id: String): JSONObject =
        payload.single { it.getJSONObject("metadata").getString("id") == id }

    private fun bpm(record: JSONObject, field: String): List<Int> {
        val samples = record.getJSONArray(field)
        return (0 until samples.length()).map { samples.getJSONObject(it).getInt("beatsPerMinute") }
    }

    @Test
    fun `heart rate - samples holds the night, allSamples every sample`() {
        val night = record("p-hr-night")
        assertEquals("HeartRate", night.getString("recordType"))
        assertEquals("asleep: 01:00 and 03:00, not 08:00", listOf(52, 48), bpm(night, "samples"))
        assertEquals(listOf(52, 48, 80), bpm(night, "allSamples"))
        assertEquals("2026-09-25T08:00:00+02:00", night.getJSONArray("allSamples").getJSONObject(2).getString("time"))
    }

    @Test
    fun `heart rate - a daytime record is sent too, with no sleep samples`() {
        val day = record("p-hr-day")
        assertEquals(emptyList<Int>(), bpm(day, "samples"))
        assertEquals(listOf(90), bpm(day, "allSamples"))
    }

    @Test
    fun `the five types added in 13_0 - in Health Connect's own names and units`() {
        assertEquals(12, IngestPayload.TYPES.size)

        record("p-total").let {
            assertEquals("TotalCaloriesBurned", it.getString("recordType"))
            assertEquals(2310.0, it.getJSONObject("energy").getDouble("kilocalories"), 0.0)
            assertEquals("2026-09-26T00:00:00+02:00", it.getString("endTime"))
        }
        record("p-floors").let {
            assertEquals("FloorsClimbed", it.getString("recordType"))
            assertEquals(6.0, it.getDouble("floors"), 0.0)
        }
        record("p-resting").let {
            assertEquals("RestingHeartRate", it.getString("recordType"))
            assertEquals(54, it.getInt("beatsPerMinute"))
            assertEquals("2026-09-25T07:00:00+02:00", it.getString("time"))
        }
        record("p-hrv").let {
            assertEquals("HeartRateVariabilityRmssd", it.getString("recordType"))
            assertEquals(41.5, it.getDouble("heartRateVariabilityMillis"), 0.0)
        }
        record("p-spo2").let {
            assertEquals("OxygenSaturation", it.getString("recordType"))
            assertEquals(96.0, it.getDouble("percentage"), 0.0)
            assertEquals("com.google.android.apps.fitness", it.getJSONObject("metadata").getString("dataOrigin"))
        }
    }

    @Test
    fun `batches - at most size records and about bytes of JSON each, in order, nothing lost`() {
        val records = (1..10).map { JSONObject().put("n", it).put("pad", "x".repeat(100)) }

        val bySize = IngestPayload.batches(records, size = 4)
        assertEquals(listOf(4, 4, 2), bySize.map { it.length() })

        val byBytes = IngestPayload.batches(records, size = 500, bytes = 350)
        assertTrue(byBytes.all { it.toString().length <= 350 })
        assertEquals((1..10).toList(), byBytes.flatMap { b -> (0 until b.length()).map { b.getJSONObject(it).getInt("n") } })

        // A record larger than the limit is never dropped: it goes on its own.
        val huge = listOf(JSONObject().put("pad", "x".repeat(1000)), JSONObject().put("n", 1))
        assertEquals(listOf(1, 1), IngestPayload.batches(huge, size = 500, bytes = 350).map { it.length() })
    }
}
