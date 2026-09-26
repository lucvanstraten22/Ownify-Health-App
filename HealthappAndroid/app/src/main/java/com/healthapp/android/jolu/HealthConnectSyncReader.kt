package com.healthapp.android.jolu

import androidx.health.connect.client.HealthConnectClient
import androidx.health.connect.client.permission.HealthPermission
import androidx.health.connect.client.records.Record
import androidx.health.connect.client.request.ReadRecordsRequest
import androidx.health.connect.client.time.TimeRangeFilter
import java.time.Instant
import kotlin.reflect.KClass

/**
 * Reads the individual Health Connect records a sync sends.
 *
 * The screen's own reading (MainActivity.readTodayHealthData) turns today's
 * records into today's totals and averages; a sync needs the records
 * themselves, each with its own id and times, over a longer window. So this
 * reads the same record types, with the same permissions and the same
 * HealthConnectClient API, but keeps every record as Health Connect gave it.
 * The screen's reading is left exactly as it was.
 */
internal class HealthConnectSyncReader(private val client: HealthConnectClient) {

    class Reading(
        /** Every record of every readable type, in the order the types are listed. */
        val records: List<Record>,
        /** Types that were not read because their permission is not granted. */
        val notGranted: List<String>
    )

    /**
     * All records of [types] between [from] and [to]. A type whose read
     * permission is not granted is skipped rather than failing the sync —
     * people grant Health Connect access per category.
     */
    suspend fun read(types: List<KClass<out Record>>, from: Instant, to: Instant): Reading {
        val granted = client.permissionController.getGrantedPermissions()
        val range = TimeRangeFilter.between(from, to)

        val records = mutableListOf<Record>()
        val notGranted = mutableListOf<String>()

        for (type in types) {
            if (HealthPermission.getReadPermission(type) in granted) {
                records += readAll(type, range)
            } else {
                notGranted += type.simpleName.orEmpty().removeSuffix("Record")
            }
        }

        return Reading(records, notGranted)
    }

    /** Every page, not only the first: Health Connect returns at most [PAGE_SIZE] records per read. */
    private suspend fun <T : Record> readAll(type: KClass<T>, range: TimeRangeFilter): List<T> {
        val all = mutableListOf<T>()
        var pageToken: String? = null

        do {
            val response = client.readRecords(
                ReadRecordsRequest(
                    recordType = type,
                    timeRangeFilter = range,
                    pageSize = PAGE_SIZE,
                    pageToken = pageToken
                )
            )

            all += response.records
            pageToken = response.pageToken?.takeIf { it.isNotEmpty() }
        } while (pageToken != null)

        return all
    }

    private companion object {
        const val PAGE_SIZE = 1000
    }
}
