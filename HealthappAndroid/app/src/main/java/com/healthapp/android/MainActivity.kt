package com.healthapp.android

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawingPadding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.Text
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import androidx.health.connect.client.HealthConnectClient
import androidx.health.connect.client.PermissionController
import androidx.health.connect.client.permission.HealthPermission
import androidx.health.connect.client.records.ActiveCaloriesBurnedRecord
import androidx.health.connect.client.records.DistanceRecord
import androidx.health.connect.client.records.ExerciseSessionRecord
import androidx.health.connect.client.records.HeartRateRecord
import androidx.health.connect.client.records.NutritionRecord
import androidx.health.connect.client.records.SleepSessionRecord
import androidx.health.connect.client.records.StepsRecord
import androidx.health.connect.client.request.AggregateRequest
import androidx.health.connect.client.request.ReadRecordsRequest
import androidx.health.connect.client.time.TimeRangeFilter
import com.healthapp.android.jolu.JoluConnectionSection
import com.healthapp.android.ui.theme.HealthappAndroidTheme
import java.time.Duration
import java.time.Instant
import java.time.LocalDate
import java.time.ZoneId
import kotlin.math.abs
import kotlin.math.roundToInt
import kotlinx.coroutines.launch

class MainActivity : ComponentActivity() {

    private lateinit var healthConnectClient: HealthConnectClient

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        healthConnectClient = HealthConnectClient.getOrCreate(this)

        setContent {
            HealthappAndroidTheme {

                var status by remember {
                    mutableStateOf("Checking Health Connect...")
                }

                var steps by remember {
                    mutableStateOf<Long?>(null)
                }

                var distance by remember {
                    mutableStateOf<Double?>(null)
                }

                var calories by remember {
                    mutableStateOf<Double?>(null)
                }

                var heartRate by remember {
                    mutableStateOf<Double?>(null)
                }

                var sleepHours by remember {
                    mutableStateOf<Double?>(null)
                }

                var nutritionCalories by remember {
                    mutableStateOf<Double?>(null)
                }

                var proteinGrams by remember {
                    mutableStateOf<Double?>(null)
                }

                var carbohydrateGrams by remember {
                    mutableStateOf<Double?>(null)
                }

                var fatGrams by remember {
                    mutableStateOf<Double?>(null)
                }

                var nutritionEntryCount by remember {
                    mutableStateOf<Int?>(null)
                }

                var workoutCount by remember {
                    mutableStateOf<Int?>(null)
                }

                var workoutMinutes by remember {
                    mutableStateOf<Double?>(null)
                }

                var hasPermission by remember {
                    mutableStateOf(false)
                }

                val scope = rememberCoroutineScope()

                val permissions = setOf(
                    HealthPermission.getReadPermission(StepsRecord::class),
                    HealthPermission.getReadPermission(DistanceRecord::class),
                    HealthPermission.getReadPermission(
                        ActiveCaloriesBurnedRecord::class
                    ),
                    HealthPermission.getReadPermission(
                        HeartRateRecord::class
                    ),
                    HealthPermission.getReadPermission(
                        SleepSessionRecord::class
                    ),
                    HealthPermission.getReadPermission(
                        NutritionRecord::class
                    ),
                    HealthPermission.getReadPermission(
                        ExerciseSessionRecord::class
                    )
                )

                suspend fun checkAndReadHealthData() {
                    try {

                        val grantedPermissions =
                            healthConnectClient.permissionController
                                .getGrantedPermissions()

                        hasPermission =
                            grantedPermissions.containsAll(permissions)

                        if (hasPermission) {

                            val data = readTodayHealthData()

                            steps = data.steps
                            distance = data.distance
                            calories = data.calories
                            heartRate = data.heartRate
                            sleepHours = data.sleepHours

                            nutritionCalories =
                                data.nutritionCalories

                            proteinGrams =
                                data.proteinGrams

                            carbohydrateGrams =
                                data.carbohydrateGrams

                            fatGrams =
                                data.fatGrams

                            nutritionEntryCount =
                                data.nutritionEntryCount

                            workoutCount =
                                data.workoutCount

                            workoutMinutes =
                                data.workoutMinutes

                            status =
                                "Health Connect connected"

                        } else {

                            status =
                                "Health Connect permissions required"
                        }

                    } catch (e: Exception) {

                        status =
                            "Could not connect to Health Connect"
                    }
                }

                val permissionLauncher =
                    rememberLauncherForActivityResult(
                        contract = PermissionController
                            .createRequestPermissionResultContract()
                    ) { grantedPermissions ->

                        if (grantedPermissions.containsAll(permissions)) {

                            hasPermission = true

                            status =
                                "Health Connect connected"

                            scope.launch {

                                try {

                                    val data =
                                        readTodayHealthData()

                                    steps = data.steps
                                    distance = data.distance
                                    calories = data.calories
                                    heartRate = data.heartRate
                                    sleepHours = data.sleepHours

                                    nutritionCalories =
                                        data.nutritionCalories

                                    proteinGrams =
                                        data.proteinGrams

                                    carbohydrateGrams =
                                        data.carbohydrateGrams

                                    fatGrams =
                                        data.fatGrams

                                    nutritionEntryCount =
                                        data.nutritionEntryCount

                                    workoutCount =
                                        data.workoutCount

                                    workoutMinutes =
                                        data.workoutMinutes

                                } catch (e: Exception) {

                                    status =
                                        "Could not read health data"
                                }
                            }

                        } else {

                            hasPermission = false

                            status =
                                "Health Connect permissions denied"
                        }
                    }

                LaunchedEffect(Unit) {
                    checkAndReadHealthData()
                }

                val sleepScore =
                    calculateSleepScore(sleepHours)

                val nutritionScore =
                    calculateNutritionScore(
                        nutritionCalories,
                        proteinGrams,
                        carbohydrateGrams,
                        fatGrams,
                        nutritionEntryCount
                    )

                val trainingScore =
                    calculateTrainingScore(workoutMinutes)

                val healthScore =
                    calculateHealthScore(
                        sleepScore,
                        nutritionScore,
                        trainingScore
                    )

                Column(
                    modifier = Modifier
                        .fillMaxSize()
                        .safeDrawingPadding()
                        .verticalScroll(rememberScrollState())
                        .padding(24.dp),
                    horizontalAlignment = Alignment.CenterHorizontally,
                    verticalArrangement = Arrangement.Center
                ) {

                    Text(
                        text = "Healthapp Android"
                    )

                    if (!hasPermission) {

                        Button(
                            onClick = {
                                permissionLauncher.launch(
                                    permissions
                                )
                            },
                            modifier = Modifier.padding(
                                top = 16.dp
                            )
                        ) {

                            Text(
                                "Connect to Health Connect"
                            )
                        }
                    }

                    Text(
                        text = status,
                        modifier = Modifier.padding(
                            top = 16.dp
                        )
                    )

                    Text(
                        text = if (healthScore != null) {
                            "Health Score: $healthScore / 100"
                        } else {
                            "Health Score: No data"
                        },
                        modifier = Modifier.padding(
                            top = 20.dp
                        )
                    )

                    Text(
                        text = if (sleepScore != null) {
                            "Sleep Score: $sleepScore / 100"
                        } else {
                            "Sleep Score: No data"
                        },
                        modifier = Modifier.padding(
                            top = 12.dp
                        )
                    )

                    Text(
                        text = if (nutritionScore != null) {
                            "Nutrition Score: $nutritionScore / 100"
                        } else {
                            "Nutrition Score: No data"
                        },
                        modifier = Modifier.padding(
                            top = 8.dp
                        )
                    )

                    Text(
                        text = if (trainingScore != null) {
                            "Training Score: $trainingScore / 100"
                        } else {
                            "Training Score: No data"
                        },
                        modifier = Modifier.padding(
                            top = 8.dp
                        )
                    )

                    Text(
                        text = if (nutritionCalories != null) {
                            "Nutrition calories: %.0f kcal"
                                .format(nutritionCalories)
                        } else {
                            "Nutrition calories: No data"
                        },
                        modifier = Modifier.padding(
                            top = 20.dp
                        )
                    )

                    Text(
                        text = if (proteinGrams != null) {
                            "Protein: %.1f g"
                                .format(proteinGrams)
                        } else {
                            "Protein: No data"
                        },
                        modifier = Modifier.padding(
                            top = 8.dp
                        )
                    )

                    Text(
                        text = if (carbohydrateGrams != null) {
                            "Carbohydrates: %.1f g"
                                .format(carbohydrateGrams)
                        } else {
                            "Carbohydrates: No data"
                        },
                        modifier = Modifier.padding(
                            top = 8.dp
                        )
                    )

                    Text(
                        text = if (fatGrams != null) {
                            "Fat: %.1f g"
                                .format(fatGrams)
                        } else {
                            "Fat: No data"
                        },
                        modifier = Modifier.padding(
                            top = 8.dp
                        )
                    )

                    Text(
                        text = if (nutritionEntryCount != null) {
                            "Nutrition entries: $nutritionEntryCount"
                        } else {
                            "Nutrition entries: No data"
                        },
                        modifier = Modifier.padding(
                            top = 8.dp
                        )
                    )

                    if (steps != null) {

                        Text(
                            text = "Steps today: $steps",
                            modifier = Modifier.padding(
                                top = 20.dp
                            )
                        )
                    }

                    if (distance != null) {

                        Text(
                            text = "Distance today: %.2f km"
                                .format(distance),
                            modifier = Modifier.padding(
                                top = 8.dp
                            )
                        )
                    }

                    if (calories != null) {

                        Text(
                            text = "Active calories today: %.0f kcal"
                                .format(calories),
                            modifier = Modifier.padding(
                                top = 8.dp
                            )
                        )
                    }

                    Text(
                        text = if (heartRate != null) {
                            "Average heart rate today: %.0f bpm"
                                .format(heartRate)
                        } else {
                            "Average heart rate today: No data"
                        },
                        modifier = Modifier.padding(
                            top = 8.dp
                        )
                    )

                    Text(
                        text = if (sleepHours != null) {
                            "Sleep last night: %.1f hours"
                                .format(sleepHours)
                        } else {
                            "Sleep last night: No data"
                        },
                        modifier = Modifier.padding(
                            top = 8.dp
                        )
                    )

                    Text(
                        text = if (
                            workoutCount != null &&
                            workoutCount!! > 0
                        ) {
                            "Workouts today: $workoutCount"
                        } else {
                            "Workouts today: No data"
                        },
                        modifier = Modifier.padding(
                            top = 8.dp
                        )
                    )

                    Text(
                        text = if (workoutMinutes != null) {
                            "Training time today: %.0f min"
                                .format(workoutMinutes)
                        } else {
                            "Training time today: No data"
                        },
                        modifier = Modifier.padding(
                            top = 8.dp
                        )
                    )

                    JoluConnectionSection()
                }
            }
        }
    }

    private fun calculateSleepScore(
        sleepHours: Double?
    ): Int? {

        if (sleepHours == null) {
            return null
        }

        val difference =
            abs(sleepHours - 8.0)

        val score =
            when {

                difference >= 4.0 ->
                    40.0

                difference >= 3.0 ->
                    55.0

                difference >= 2.0 ->
                    70.0

                difference >= 1.0 ->
                    85.0

                else ->
                    100.0
            }

        return score.roundToInt()
    }

    private fun calculateNutritionScore(
        nutritionCalories: Double?,
        proteinGrams: Double?,
        carbohydrateGrams: Double?,
        fatGrams: Double?,
        nutritionEntryCount: Int?
    ): Int? {

        if (
            nutritionCalories == null &&
            proteinGrams == null &&
            carbohydrateGrams == null &&
            fatGrams == null &&
            nutritionEntryCount == null
        ) {
            return null
        }

        if (
            nutritionEntryCount == null ||
            nutritionEntryCount == 0
        ) {
            return 50
        }

        var score = 50.0

        if (
            nutritionCalories != null &&
            nutritionCalories > 0
        ) {
            score += 15.0
        }

        if (
            proteinGrams != null &&
            proteinGrams > 0
        ) {
            score += 10.0
        }

        if (
            carbohydrateGrams != null &&
            carbohydrateGrams > 0
        ) {
            score += 10.0
        }

        if (
            fatGrams != null &&
            fatGrams > 0
        ) {
            score += 10.0
        }

        return score.coerceAtMost(100.0).roundToInt()
    }

    private fun calculateTrainingScore(
        workoutMinutes: Double?
    ): Int? {

        if (workoutMinutes == null) {
            return null
        }

        val score =
            when {

                workoutMinutes >= 60.0 ->
                    100.0

                workoutMinutes >= 45.0 ->
                    90.0

                workoutMinutes >= 30.0 ->
                    80.0

                workoutMinutes >= 20.0 ->
                    70.0

                workoutMinutes >= 10.0 ->
                    60.0

                else ->
                    40.0
            }

        return score.roundToInt()
    }

    private fun calculateHealthScore(
        sleepScore: Int?,
        nutritionScore: Int?,
        trainingScore: Int?
    ): Int? {

        val availableScores =
            listOfNotNull(
                sleepScore,
                nutritionScore,
                trainingScore
            )

        if (availableScores.isEmpty()) {
            return null
        }

        return availableScores
            .average()
            .roundToInt()
    }

    private suspend fun readTodayHealthData(): HealthData {

        val zoneId = ZoneId.systemDefault()

        val startOfDay =
            LocalDate.now(zoneId)
                .atStartOfDay(zoneId)
                .toInstant()

        val now = Instant.now()

        val aggregateResponse =
            healthConnectClient.aggregate(
                AggregateRequest(
                    metrics = setOf(
                        StepsRecord.COUNT_TOTAL,
                        DistanceRecord.DISTANCE_TOTAL,
                        ActiveCaloriesBurnedRecord.ACTIVE_CALORIES_TOTAL
                    ),
                    timeRangeFilter = TimeRangeFilter.between(
                        startOfDay,
                        now
                    )
                )
            )

        val steps =
            aggregateResponse[
                StepsRecord.COUNT_TOTAL
            ] ?: 0L

        val distanceMeters =
            aggregateResponse[
                DistanceRecord.DISTANCE_TOTAL
            ]?.inMeters ?: 0.0

        val calories =
            aggregateResponse[
                ActiveCaloriesBurnedRecord.ACTIVE_CALORIES_TOTAL
            ]?.inKilocalories ?: 0.0

        val heartRateRequest =
            ReadRecordsRequest(
                recordType = HeartRateRecord::class,
                timeRangeFilter = TimeRangeFilter.between(
                    startOfDay,
                    now
                )
            )

        val heartRateResponse =
            healthConnectClient.readRecords(
                heartRateRequest
            )

        val heartRateSamples =
            heartRateResponse.records
                .flatMap { record ->
                    record.samples
                }

        val averageHeartRate =
            if (heartRateSamples.isNotEmpty()) {

                heartRateSamples
                    .map {
                        it.beatsPerMinute.toDouble()
                    }
                    .average()

            } else {
                null
            }

        val sleepStart =
            LocalDate.now(zoneId)
                .minusDays(1)
                .atStartOfDay(zoneId)
                .toInstant()

        val sleepRequest =
            ReadRecordsRequest(
                recordType = SleepSessionRecord::class,
                timeRangeFilter = TimeRangeFilter.between(
                    sleepStart,
                    now
                )
            )

        val sleepResponse =
            healthConnectClient.readRecords(
                sleepRequest
            )

        val sleepDurationSeconds =
            sleepResponse.records.sumOf { session ->

                Duration.between(
                    session.startTime,
                    session.endTime
                ).seconds
            }

        val sleepHours =
            if (sleepDurationSeconds > 0) {

                sleepDurationSeconds / 3600.0

            } else {
                null
            }

        val nutritionRequest =
            ReadRecordsRequest(
                recordType = NutritionRecord::class,
                timeRangeFilter = TimeRangeFilter.between(
                    startOfDay,
                    now
                )
            )

        val nutritionResponse =
            healthConnectClient.readRecords(
                nutritionRequest
            )

        val nutritionRecords =
            nutritionResponse.records

        val nutritionCalories =
            nutritionRecords
                .mapNotNull { record ->
                    record.energy?.inKilocalories
                }
                .sum()

        val proteinGrams =
            nutritionRecords
                .mapNotNull { record ->
                    record.protein?.inGrams
                }
                .sum()

        val carbohydrateGrams =
            nutritionRecords
                .mapNotNull { record ->
                    record.totalCarbohydrate?.inGrams
                }
                .sum()

        val fatGrams =
            nutritionRecords
                .mapNotNull { record ->
                    record.totalFat?.inGrams
                }
                .sum()

        val nutritionEntryCount =
            nutritionRecords.size

        val hasNutritionData =
            nutritionRecords.isNotEmpty()

        val workoutRequest =
            ReadRecordsRequest(
                recordType =
                    ExerciseSessionRecord::class,
                timeRangeFilter =
                    TimeRangeFilter.between(
                        startOfDay,
                        now
                    )
            )

        val workoutResponse =
            healthConnectClient.readRecords(
                workoutRequest
            )

        val workoutRecords =
            workoutResponse.records

        val workoutCount =
            workoutRecords.size

        val workoutSeconds =
            workoutRecords.sumOf { workout ->

                Duration.between(
                    workout.startTime,
                    workout.endTime
                ).seconds
            }

        val workoutMinutes =
            if (workoutSeconds > 0) {

                workoutSeconds / 60.0

            } else {
                null
            }

        return HealthData(
            steps = steps,
            distance =
                distanceMeters / 1000.0,
            calories = calories,
            heartRate =
                averageHeartRate,
            sleepHours =
                sleepHours,
            nutritionCalories =
                if (hasNutritionData) {
                    nutritionCalories
                } else {
                    null
                },
            proteinGrams =
                if (hasNutritionData) {
                    proteinGrams
                } else {
                    null
                },
            carbohydrateGrams =
                if (hasNutritionData) {
                    carbohydrateGrams
                } else {
                    null
                },
            fatGrams =
                if (hasNutritionData) {
                    fatGrams
                } else {
                    null
                },
            nutritionEntryCount =
                if (hasNutritionData) {
                    nutritionEntryCount
                } else {
                    null
                },
            workoutCount =
                workoutCount,
            workoutMinutes =
                workoutMinutes
        )
    }

    data class HealthData(
        val steps: Long,
        val distance: Double,
        val calories: Double,
        val heartRate: Double?,
        val sleepHours: Double?,
        val nutritionCalories: Double?,
        val proteinGrams: Double?,
        val carbohydrateGrams: Double?,
        val fatGrams: Double?,
        val nutritionEntryCount: Int?,
        val workoutCount: Int,
        val workoutMinutes: Double?
    )
}
