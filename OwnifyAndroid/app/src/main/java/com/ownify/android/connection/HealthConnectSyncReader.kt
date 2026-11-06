package com.ownify.android.connection

import androidx.health.connect.client.HealthConnectClient
import androidx.health.connect.client.changes.DeletionChange
import androidx.health.connect.client.changes.UpsertionChange
import androidx.health.connect.client.permission.HealthPermission
import androidx.health.connect.client.records.Record
import androidx.health.connect.client.records.SleepSessionRecord
import androidx.health.connect.client.request.ChangesTokenRequest
import androidx.health.connect.client.request.ReadRecordsRequest
import androidx.health.connect.client.time.TimeRangeFilter
import java.time.Duration
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
        // 43.0: nights from a day earlier, so heart rate at the start of the
        // range still finds the night it was measured in (`samples`).
        val nights = TimeRangeFilter.between(from.minus(Duration.ofDays(1)), to)

        val records = mutableListOf<Record>()
        val notGranted = mutableListOf<String>()

        for (type in types) {
            if (HealthPermission.getReadPermission(type) in granted) {
                records += readAll(type, if (type == SleepSessionRecord::class) nights else range)
            } else {
                notGranted += type.simpleName.orEmpty().removeSuffix("Record")
            }
        }

        return Reading(records, notGranted)
    }

    /**
     * 43.0 — a changes token for [types]: from now on, Health Connect keeps
     * what is written or deleted in them, to be read with [changes].
     */
    suspend fun changesToken(types: List<KClass<out Record>>): String =
        client.getChangesToken(ChangesTokenRequest(types.toSet()))

    /** One page of what changed since [token], in the order it happened. */
    suspend fun changes(token: String): ChangesPage {
        val response = client.getChanges(token)
        return ChangesPage(
            changes = response.changes.mapNotNull { change ->
                when (change) {
                    is UpsertionChange -> HealthChange.Written(change.record)
                    is DeletionChange -> HealthChange.Deleted(change.recordId)
                    else -> null
                }
            },
            next = response.nextChangesToken,
            hasMore = response.hasMore,
            expired = response.changesTokenExpired
        )
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

/** 43.0: one thing that changed in Health Connect. */
internal sealed interface HealthChange {
    /** Written, or written again: the record as it is now. */
    data class Written(val record: Record) : HealthChange

    /** Deleted: only its id is left. */
    data class Deleted(val id: String) : HealthChange
}

/** A page of [HealthChange]s, the token to carry on from, and whether Health Connect had more or had let the token expire. */
internal data class ChangesPage(
    val changes: List<HealthChange>,
    val next: String,
    val hasMore: Boolean,
    val expired: Boolean
)
