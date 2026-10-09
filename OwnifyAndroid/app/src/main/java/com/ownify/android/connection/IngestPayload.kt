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
import androidx.health.connect.client.units.Mass
import java.time.Instant
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.ZoneOffset
import java.time.format.DateTimeFormatter
import org.json.JSONArray
import org.json.JSONObject

/**
 * Health Connect records as the JSON ingest.php reads — one object per record,
 * with Health Connect's own record and field names and its own units (metres,
 * kilocalories, grams), exactly as includes/health-connect-map.php on the
 * server expects. Nothing is summed, converted or made up here: the server
 * does the mapping, so a mapping fix is a deploy, not an app release.
 *
 * Every object carries metadata.id, Health Connect's own record id. That is
 * what makes sending the same record twice safe: the server updates it in
 * place instead of storing it again.
 */
internal object IngestPayload {

    /**
     * The record types a sync reads and sends — all twelve this app may read
     * (AndroidManifest.xml). The last five arrived with 13.0, for the
     * Training and Slaap pages: a phone that granted the first seven before
     * shows them as not yet granted until they are asked for.
     */
    val TYPES = listOf(
        SleepSessionRecord::class,
        ExerciseSessionRecord::class,
        StepsRecord::class,
        DistanceRecord::class,
        ActiveCaloriesBurnedRecord::class,
        HeartRateRecord::class,
        NutritionRecord::class,
        TotalCaloriesBurnedRecord::class,
        FloorsClimbedRecord::class,
        RestingHeartRateRecord::class,
        HeartRateVariabilityRmssdRecord::class,
        OxygenSaturationRecord::class
    )

    /**
     * Every record as its JSON object, in the order given.
     *
     * Heart rate is sent twice over. The server keeps the average heart rate
     * *during sleep* (the sleep card's "Hartslag in slaap") from `samples`, so
     * that holds only the samples taken inside one of the sleep sessions in
     * [records] — daytime heart rate is not sleeping heart rate. Every sample
     * goes in `allSamples`, which the server keeps as the mean of each minute
     * for the Training page's heart rate (docs/TRAINING.md). A server from
     * before 13.0 ignores `allSamples`.
     */
    fun of(records: List<Record>): List<JSONObject> {
        val nights = records.filterIsInstance<SleepSessionRecord>()

        return records.mapNotNull { record ->
            when (record) {
                is SleepSessionRecord -> sleep(record)
                is ExerciseSessionRecord -> exercise(record)
                is StepsRecord -> steps(record)
                is DistanceRecord -> distance(record)
                is ActiveCaloriesBurnedRecord -> activeCalories(record)
                is TotalCaloriesBurnedRecord -> totalCalories(record)
                is FloorsClimbedRecord -> floors(record)
                is RestingHeartRateRecord -> restingHeartRate(record)
                is HeartRateVariabilityRmssdRecord -> heartRateVariability(record)
                is OxygenSaturationRecord -> oxygenSaturation(record)
                is NutritionRecord -> nutrition(record)
                is HeartRateRecord -> heartRate(record, nights)
                else -> null
            }
        }
    }

    /** How many of each type [payload] holds, e.g. {"Steps": 212, "SleepSession": 7}. */
    fun countByType(payload: List<JSONObject>): Map<String, Int> =
        payload.groupingBy { it.optString("recordType") }.eachCount()

    /**
     * [payload] in batches for ingest.php, which takes up to 2000 records per
     * call: at most [size] records, and at most [bytes] of JSON, so a week of
     * second-by-second heart rate does not become one request the server
     * cannot take. A single record larger than [bytes] goes on its own.
     */
    fun batches(payload: List<JSONObject>, size: Int, bytes: Int = MAX_BATCH_BYTES): List<JSONArray> {
        val out = mutableListOf<JSONArray>()
        var batch = JSONArray()
        var length = 2

        for (record in payload) {
            val recordLength = record.toString().length + 1
            if (batch.length() > 0 && (batch.length() >= size || length + recordLength > bytes)) {
                out += batch
                batch = JSONArray()
                length = 2
            }
            batch.put(record)
            length += recordLength
        }
        if (batch.length() > 0) out += batch
        return out
    }

    /** About a megabyte: well inside any PHP post_max_size. */
    const val MAX_BATCH_BYTES = 1_000_000

    // ------------------------------------------------------------ record types

    private fun sleep(r: SleepSessionRecord): JSONObject =
        interval("SleepSession", r, r.startTime, r.startZoneOffset, r.endTime, r.endZoneOffset)
            .put("stages", JSONArray(r.stages.map { stage ->
                JSONObject()
                    // Stages carry no offset of their own; they share the session's.
                    .put("startTime", time(stage.startTime, r.startZoneOffset))
                    .put("endTime", time(stage.endTime, r.endZoneOffset))
                    .put("stage", stage.stage)
            }))

    private fun exercise(r: ExerciseSessionRecord): JSONObject =
        interval("ExerciseSession", r, r.startTime, r.startZoneOffset, r.endTime, r.endZoneOffset)
            .put("exerciseType", r.exerciseType)
            .putIfPresent("exerciseTypeName", EXERCISE_TYPE_NAMES[r.exerciseType])
            .putIfPresent("title", r.title)
            .putIfPresent("notes", r.notes)

    private fun steps(r: StepsRecord): JSONObject =
        interval("Steps", r, r.startTime, r.startZoneOffset, r.endTime, r.endZoneOffset)
            .put("count", r.count)

    private fun distance(r: DistanceRecord): JSONObject =
        interval("Distance", r, r.startTime, r.startZoneOffset, r.endTime, r.endZoneOffset)
            .put("distance", JSONObject().put("meters", r.distance.inMeters))

    private fun activeCalories(r: ActiveCaloriesBurnedRecord): JSONObject =
        interval("ActiveCaloriesBurned", r, r.startTime, r.startZoneOffset, r.endTime, r.endZoneOffset)
            .put("energy", JSONObject().put("kilocalories", r.energy.inKilocalories))

    private fun totalCalories(r: TotalCaloriesBurnedRecord): JSONObject =
        interval("TotalCaloriesBurned", r, r.startTime, r.startZoneOffset, r.endTime, r.endZoneOffset)
            .put("energy", JSONObject().put("kilocalories", r.energy.inKilocalories))

    private fun floors(r: FloorsClimbedRecord): JSONObject =
        interval("FloorsClimbed", r, r.startTime, r.startZoneOffset, r.endTime, r.endZoneOffset)
            .put("floors", r.floors)

    private fun restingHeartRate(r: RestingHeartRateRecord): JSONObject =
        instant("RestingHeartRate", r, r.time, r.zoneOffset)
            .put("beatsPerMinute", r.beatsPerMinute)

    private fun heartRateVariability(r: HeartRateVariabilityRmssdRecord): JSONObject =
        instant("HeartRateVariabilityRmssd", r, r.time, r.zoneOffset)
            .put("heartRateVariabilityMillis", r.heartRateVariabilityMillis)

    private fun oxygenSaturation(r: OxygenSaturationRecord): JSONObject =
        instant("OxygenSaturation", r, r.time, r.zoneOffset)
            .put("percentage", r.percentage.value)

    private fun nutrition(r: NutritionRecord): JSONObject =
        interval("Nutrition", r, r.startTime, r.startZoneOffset, r.endTime, r.endZoneOffset)
            .putIfPresent("name", r.name)
            .put("mealType", r.mealType)
            .putIfPresent("energy", r.energy?.let { JSONObject().put("kilocalories", it.inKilocalories) })
            .putGrams("protein", r.protein)
            .putGrams("totalCarbohydrate", r.totalCarbohydrate)
            .putGrams("totalFat", r.totalFat)
            .putGrams("saturatedFat", r.saturatedFat)
            .putGrams("dietaryFiber", r.dietaryFiber)
            .putGrams("sugar", r.sugar)
            .putGrams("sodium", r.sodium)

    /** `samples`: only those inside one of [nights]; `allSamples`: every one. Null without samples. */
    private fun heartRate(r: HeartRateRecord, nights: List<SleepSessionRecord>): JSONObject? {
        if (r.samples.isEmpty()) {
            return null
        }

        val asleep = r.samples.filter { sample ->
            nights.any { night -> !sample.time.isBefore(night.startTime) && !sample.time.isAfter(night.endTime) }
        }

        fun json(samples: List<HeartRateRecord.Sample>) = JSONArray(samples.map { sample ->
            JSONObject()
                .put("time", time(sample.time, r.startZoneOffset))
                .put("beatsPerMinute", sample.beatsPerMinute)
        })

        return interval("HeartRate", r, r.startTime, r.startZoneOffset, r.endTime, r.endZoneOffset)
            .put("samples", json(asleep))
            .put("allSamples", json(r.samples))
    }

    // ------------------------------------------------------------------ parts

    /** The fields every record shares: its type, its id and origin, and its times. */
    private fun interval(
        type: String,
        record: Record,
        start: Instant,
        startOffset: ZoneOffset?,
        end: Instant,
        endOffset: ZoneOffset?
    ): JSONObject =
        JSONObject()
            .put("recordType", type)
            .put(
                "metadata",
                JSONObject()
                    .put("id", record.metadata.id)
                    .put("dataOrigin", record.metadata.dataOrigin.packageName)
            )
            .put("startTime", time(start, startOffset))
            .put("endTime", time(end, endOffset))

    /** The fields a reading at one moment carries: its type, its id and origin, and its time. */
    private fun instant(type: String, record: Record, at: Instant, offset: ZoneOffset?): JSONObject =
        JSONObject()
            .put("recordType", type)
            .put(
                "metadata",
                JSONObject()
                    .put("id", record.metadata.id)
                    .put("dataOrigin", record.metadata.dataOrigin.packageName)
            )
            .put("time", time(at, offset))

    /**
     * The moment as ISO 8601 with the offset Health Connect recorded for it,
     * e.g. "2026-09-18T00:30:00+02:00" — the same instant as "…T22:30:00Z",
     * but the server keeps the local clock time it is given, so this is what
     * puts a night or a workout on the date the person lived it. A record
     * without an offset gets the phone's own offset at that moment.
     */
    fun time(instant: Instant, offset: ZoneOffset?): String {
        val zone = offset ?: ZoneId.systemDefault().rules.getOffset(instant)
        return OffsetDateTime.ofInstant(instant, zone).format(TIME_FORMAT)
    }

    private val TIME_FORMAT = DateTimeFormatter.ofPattern("yyyy-MM-dd'T'HH:mm:ssXXX")

    /**
     * A name for the exercise type number, e.g. 56 -> "running": the public
     * EXERCISE_TYPE_ constants, named the way Health Connect names them. The
     * server reads the name (health-signals.php knows "running", "biking",
     * "strength_training" and so on); the number is sent as well, so a type
     * missing from this list still arrives as itself.
     * (Health Connect's own ExerciseSessionRecord.EXERCISE_TYPE_INT_TO_STRING_MAP
     * is internal to the library and may not be used by apps.)
     */
    private val EXERCISE_TYPE_NAMES: Map<Int, String> = mapOf(
        ExerciseSessionRecord.EXERCISE_TYPE_OTHER_WORKOUT to "other_workout",
        ExerciseSessionRecord.EXERCISE_TYPE_BADMINTON to "badminton",
        ExerciseSessionRecord.EXERCISE_TYPE_BASEBALL to "baseball",
        ExerciseSessionRecord.EXERCISE_TYPE_BASKETBALL to "basketball",
        ExerciseSessionRecord.EXERCISE_TYPE_BIKING to "biking",
        ExerciseSessionRecord.EXERCISE_TYPE_BIKING_STATIONARY to "biking_stationary",
        ExerciseSessionRecord.EXERCISE_TYPE_BOOT_CAMP to "boot_camp",
        ExerciseSessionRecord.EXERCISE_TYPE_BOXING to "boxing",
        ExerciseSessionRecord.EXERCISE_TYPE_CALISTHENICS to "calisthenics",
        ExerciseSessionRecord.EXERCISE_TYPE_CRICKET to "cricket",
        ExerciseSessionRecord.EXERCISE_TYPE_DANCING to "dancing",
        ExerciseSessionRecord.EXERCISE_TYPE_ELLIPTICAL to "elliptical",
        ExerciseSessionRecord.EXERCISE_TYPE_EXERCISE_CLASS to "exercise_class",
        ExerciseSessionRecord.EXERCISE_TYPE_FENCING to "fencing",
        ExerciseSessionRecord.EXERCISE_TYPE_FOOTBALL_AMERICAN to "football_american",
        ExerciseSessionRecord.EXERCISE_TYPE_FOOTBALL_AUSTRALIAN to "football_australian",
        ExerciseSessionRecord.EXERCISE_TYPE_FRISBEE_DISC to "frisbee_disc",
        ExerciseSessionRecord.EXERCISE_TYPE_GOLF to "golf",
        ExerciseSessionRecord.EXERCISE_TYPE_GUIDED_BREATHING to "guided_breathing",
        ExerciseSessionRecord.EXERCISE_TYPE_GYMNASTICS to "gymnastics",
        ExerciseSessionRecord.EXERCISE_TYPE_HANDBALL to "handball",
        ExerciseSessionRecord.EXERCISE_TYPE_HIGH_INTENSITY_INTERVAL_TRAINING to "high_intensity_interval_training",
        ExerciseSessionRecord.EXERCISE_TYPE_HIKING to "hiking",
        ExerciseSessionRecord.EXERCISE_TYPE_ICE_HOCKEY to "ice_hockey",
        ExerciseSessionRecord.EXERCISE_TYPE_ICE_SKATING to "ice_skating",
        ExerciseSessionRecord.EXERCISE_TYPE_MARTIAL_ARTS to "martial_arts",
        ExerciseSessionRecord.EXERCISE_TYPE_PADDLING to "paddling",
        ExerciseSessionRecord.EXERCISE_TYPE_PARAGLIDING to "paragliding",
        ExerciseSessionRecord.EXERCISE_TYPE_PILATES to "pilates",
        ExerciseSessionRecord.EXERCISE_TYPE_RACQUETBALL to "racquetball",
        ExerciseSessionRecord.EXERCISE_TYPE_ROCK_CLIMBING to "rock_climbing",
        ExerciseSessionRecord.EXERCISE_TYPE_ROLLER_HOCKEY to "roller_hockey",
        ExerciseSessionRecord.EXERCISE_TYPE_ROWING to "rowing",
        ExerciseSessionRecord.EXERCISE_TYPE_ROWING_MACHINE to "rowing_machine",
        ExerciseSessionRecord.EXERCISE_TYPE_RUGBY to "rugby",
        ExerciseSessionRecord.EXERCISE_TYPE_RUNNING to "running",
        ExerciseSessionRecord.EXERCISE_TYPE_RUNNING_TREADMILL to "running_treadmill",
        ExerciseSessionRecord.EXERCISE_TYPE_SAILING to "sailing",
        ExerciseSessionRecord.EXERCISE_TYPE_SCUBA_DIVING to "scuba_diving",
        ExerciseSessionRecord.EXERCISE_TYPE_SKATING to "skating",
        ExerciseSessionRecord.EXERCISE_TYPE_SKIING to "skiing",
        ExerciseSessionRecord.EXERCISE_TYPE_SNOWBOARDING to "snowboarding",
        ExerciseSessionRecord.EXERCISE_TYPE_SNOWSHOEING to "snowshoeing",
        ExerciseSessionRecord.EXERCISE_TYPE_SOCCER to "soccer",
        ExerciseSessionRecord.EXERCISE_TYPE_SOFTBALL to "softball",
        ExerciseSessionRecord.EXERCISE_TYPE_SQUASH to "squash",
        ExerciseSessionRecord.EXERCISE_TYPE_STAIR_CLIMBING to "stair_climbing",
        ExerciseSessionRecord.EXERCISE_TYPE_STAIR_CLIMBING_MACHINE to "stair_climbing_machine",
        ExerciseSessionRecord.EXERCISE_TYPE_STRENGTH_TRAINING to "strength_training",
        ExerciseSessionRecord.EXERCISE_TYPE_STRETCHING to "stretching",
        ExerciseSessionRecord.EXERCISE_TYPE_SURFING to "surfing",
        ExerciseSessionRecord.EXERCISE_TYPE_SWIMMING_OPEN_WATER to "swimming_open_water",
        ExerciseSessionRecord.EXERCISE_TYPE_SWIMMING_POOL to "swimming_pool",
        ExerciseSessionRecord.EXERCISE_TYPE_TABLE_TENNIS to "table_tennis",
        ExerciseSessionRecord.EXERCISE_TYPE_TENNIS to "tennis",
        ExerciseSessionRecord.EXERCISE_TYPE_VOLLEYBALL to "volleyball",
        ExerciseSessionRecord.EXERCISE_TYPE_WALKING to "walking",
        ExerciseSessionRecord.EXERCISE_TYPE_WATER_POLO to "water_polo",
        ExerciseSessionRecord.EXERCISE_TYPE_WEIGHTLIFTING to "weightlifting",
        ExerciseSessionRecord.EXERCISE_TYPE_WHEELCHAIR to "wheelchair",
        ExerciseSessionRecord.EXERCISE_TYPE_YOGA to "yoga"
    )

    private fun JSONObject.putIfPresent(name: String, value: Any?): JSONObject =
        if (value == null || (value is String && value.isBlank())) this else put(name, value)

    private fun JSONObject.putGrams(name: String, mass: Mass?): JSONObject =
        putIfPresent(name, mass?.let { JSONObject().put("grams", it.inGrams) })
}
