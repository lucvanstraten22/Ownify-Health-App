package com.ownify.android.connection

import androidx.health.connect.client.records.ActiveCaloriesBurnedRecord
import androidx.health.connect.client.records.DistanceRecord
import androidx.health.connect.client.records.ExerciseSessionRecord
import androidx.health.connect.client.records.FloorsClimbedRecord
import androidx.health.connect.client.records.HeartRateRecord
import androidx.health.connect.client.records.HeartRateVariabilityRmssdRecord
import androidx.health.connect.client.records.NutritionRecord
import androidx.health.connect.client.records.OxygenSaturationRecord
import androidx.health.connect.client.records.Record
import androidx.health.connect.client.records.RestingHeartRateRecord
import androidx.health.connect.client.records.SleepSessionRecord
import androidx.health.connect.client.records.StepsRecord
import androidx.health.connect.client.records.TotalCaloriesBurnedRecord
import androidx.health.connect.client.units.Energy
import androidx.health.connect.client.units.Length
import androidx.health.connect.client.units.Mass
import androidx.health.connect.client.units.Percentage
import java.time.Instant
import java.time.OffsetDateTime
import java.time.ZoneOffset

/** Real Health Connect record objects, as readRecords() returns them, for one day in Amsterdam (+02:00). */
object SyncFixtures {

    val CEST: ZoneOffset = ZoneOffset.ofHours(2)

    fun at(local: String): Instant = OffsetDateTime.parse("$local+02:00").toInstant()

    fun meta(id: String, origin: String = "com.google.android.apps.fitness") =
        TestMetadata.of(id, origin)

    fun day(prefix: String): List<Record> = listOf<Record>(
        SleepSessionRecord(
            startTime = at("2026-09-24T23:10:00"), startZoneOffset = CEST,
            endTime = at("2026-09-25T06:40:00"), endZoneOffset = CEST,
            metadata = meta("$prefix-sleep"),
            stages = listOf(
                SleepSessionRecord.Stage(at("2026-09-24T23:10:00"), at("2026-09-25T00:50:00"), SleepSessionRecord.STAGE_TYPE_LIGHT),
                SleepSessionRecord.Stage(at("2026-09-25T00:50:00"), at("2026-09-25T02:30:00"), SleepSessionRecord.STAGE_TYPE_DEEP),
                SleepSessionRecord.Stage(at("2026-09-25T02:30:00"), at("2026-09-25T04:00:00"), SleepSessionRecord.STAGE_TYPE_REM),
                SleepSessionRecord.Stage(at("2026-09-25T04:00:00"), at("2026-09-25T04:10:00"), SleepSessionRecord.STAGE_TYPE_AWAKE),
                SleepSessionRecord.Stage(at("2026-09-25T04:10:00"), at("2026-09-25T06:40:00"), SleepSessionRecord.STAGE_TYPE_LIGHT)
            )
        ),
        // A run just after midnight: 00:30 on the 26th is still the 25th in UTC,
        // so only the offset puts it on the right day.
        ExerciseSessionRecord(
            startTime = at("2026-09-26T00:30:00"), startZoneOffset = CEST,
            endTime = at("2026-09-26T01:15:00"), endZoneOffset = CEST,
            metadata = meta("$prefix-run"),
            exerciseType = ExerciseSessionRecord.EXERCISE_TYPE_RUNNING,
            title = "Evening run"
        ),
        StepsRecord(at("2026-09-25T10:00:00"), CEST, at("2026-09-25T11:00:00"), CEST, 3000, meta("$prefix-steps-1")),
        StepsRecord(at("2026-09-25T18:00:00"), CEST, at("2026-09-25T19:00:00"), CEST, 4500, meta("$prefix-steps-2")),
        DistanceRecord(at("2026-09-25T10:00:00"), CEST, at("2026-09-25T11:00:00"), CEST, Length.meters(2400.0), meta("$prefix-distance")),
        ActiveCaloriesBurnedRecord(at("2026-09-25T10:00:00"), CEST, at("2026-09-25T11:00:00"), CEST, Energy.kilocalories(210.5), meta("$prefix-active")),
        TotalCaloriesBurnedRecord(at("2026-09-25T00:00:00"), CEST, at("2026-09-26T00:00:00"), CEST, Energy.kilocalories(2310.0), meta("$prefix-total")),
        FloorsClimbedRecord(at("2026-09-25T10:00:00"), CEST, at("2026-09-25T11:00:00"), CEST, 6.0, meta("$prefix-floors")),
        RestingHeartRateRecord(at("2026-09-25T07:00:00"), CEST, 54, meta("$prefix-resting")),
        HeartRateVariabilityRmssdRecord(at("2026-09-25T04:00:00"), CEST, 41.5, meta("$prefix-hrv")),
        OxygenSaturationRecord(at("2026-09-25T04:00:00"), CEST, Percentage(96.0), meta("$prefix-spo2")),
        // Night samples (52, 48) and a morning one (80) in one record; a daytime-only record.
        HeartRateRecord(
            at("2026-09-25T01:00:00"), CEST, at("2026-09-25T08:00:00"), CEST,
            listOf(
                HeartRateRecord.Sample(at("2026-09-25T01:00:00"), 52),
                HeartRateRecord.Sample(at("2026-09-25T03:00:00"), 48),
                HeartRateRecord.Sample(at("2026-09-25T08:00:00"), 80)
            ),
            meta("$prefix-hr-night")
        ),
        HeartRateRecord(
            at("2026-09-25T12:00:00"), CEST, at("2026-09-25T12:05:00"), CEST,
            listOf(HeartRateRecord.Sample(at("2026-09-25T12:00:00"), 90)),
            meta("$prefix-hr-day")
        ),
        NutritionRecord(
            startTime = at("2026-09-25T12:30:00"), startZoneOffset = CEST,
            endTime = at("2026-09-25T12:45:00"), endZoneOffset = CEST,
            metadata = meta("$prefix-lunch", "com.myfitnesspal.android"),
            name = "Salade",
            mealType = 2,
            energy = Energy.kilocalories(450.0),
            protein = Mass.grams(30.0),
            totalCarbohydrate = Mass.grams(40.0),
            totalFat = Mass.grams(15.0),
            sodium = Mass.grams(0.8)
        )
    )
}
