package com.healthapp.android.jolu

import android.content.Context
import androidx.core.content.edit
import java.time.Instant

/**
 * How the last sync went — the only thing about syncing this phone keeps.
 *
 * No health data: a time, what kind of outcome it was, and how many records
 * the server stored. The server is where the data is; this is only so the
 * screen can say "Last sync: today, 18:42" without asking it.
 */
data class JoluSyncRecord(
    /** When a sync last finished successfully, or null when none has yet. */
    val lastSuccessAt: Instant? = null,
    /** When a sync last ran at all. */
    val lastAttemptAt: Instant? = null,
    val lastOutcome: JoluSyncOutcomeKind? = null,
    /** Whether the last run was the button or automatic. */
    val lastWasAutomatic: Boolean = false,
    /** Records the server stored in the last successful sync — a count, nothing more. */
    val lastWritten: Int? = null
)

/**
 * What kind of outcome a sync had. [label] is its plain English name; the
 * screen says it in Dutch (`outcomeText` in ui/screens/settings/PhoneSync.kt).
 */
enum class JoluSyncOutcomeKind(val label: String) {
    SYNCED("Synced"),
    OFFLINE("Could not reach JoLu — will try again automatically"),
    SERVER_TROUBLE("JoLu had a problem — will try again automatically"),
    READ_FAILED("Could not read Health Connect — will try again"),
    NEEDS_HEALTH_ACCESS("Health Connect access is required for automatic syncing"),
    NEEDS_BACKGROUND_ACCESS("Syncs while JoLu is open; allow background access to sync when it is closed"),
    NO_HEALTH_CONNECT("Health Connect is not available on this phone"),
    NOT_CONNECTED("Not connected to JoLu"),
    EXPIRED("JoLu connection expired — pair again")
}

/** Where [JoluSyncRecord] is kept. One implementation on the phone; tests keep it in memory. */
internal interface JoluSyncStatusStore {
    fun read(): JoluSyncRecord
    fun write(record: JoluSyncRecord)
    fun clear()
}

/**
 * shared_prefs/jolu_sync_status.xml. Left out of backups and device transfers
 * with the token (res/xml/backup_rules.xml): a "last sync" restored onto
 * another phone would describe a connection that phone does not have.
 */
internal class JoluSyncStatusPrefs(context: Context) : JoluSyncStatusStore {

    private val prefs = context.applicationContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)

    override fun read(): JoluSyncRecord =
        JoluSyncRecord(
            lastSuccessAt = instant(KEY_SUCCESS),
            lastAttemptAt = instant(KEY_ATTEMPT),
            lastOutcome = prefs.getString(KEY_OUTCOME, null)
                ?.let { name -> JoluSyncOutcomeKind.entries.firstOrNull { it.name == name } },
            lastWasAutomatic = prefs.getBoolean(KEY_AUTOMATIC, false),
            lastWritten = if (prefs.contains(KEY_WRITTEN)) prefs.getInt(KEY_WRITTEN, 0) else null
        )

    override fun write(record: JoluSyncRecord) {
        prefs.edit {
            putOrRemove(KEY_SUCCESS, record.lastSuccessAt)
            putOrRemove(KEY_ATTEMPT, record.lastAttemptAt)
            if (record.lastOutcome == null) remove(KEY_OUTCOME) else putString(KEY_OUTCOME, record.lastOutcome.name)
            putBoolean(KEY_AUTOMATIC, record.lastWasAutomatic)
            if (record.lastWritten == null) remove(KEY_WRITTEN) else putInt(KEY_WRITTEN, record.lastWritten)
        }
    }

    override fun clear() {
        prefs.edit { clear() }
    }

    private fun instant(key: String): Instant? =
        if (prefs.contains(key)) Instant.ofEpochMilli(prefs.getLong(key, 0)) else null

    private fun android.content.SharedPreferences.Editor.putOrRemove(key: String, value: Instant?) {
        if (value == null) remove(key) else putLong(key, value.toEpochMilli())
    }

    private companion object {
        /** The name the backup rules exclude. */
        const val PREFS_NAME = "jolu_sync_status"
        const val KEY_SUCCESS = "last_success_at"
        const val KEY_ATTEMPT = "last_attempt_at"
        const val KEY_OUTCOME = "last_outcome"
        const val KEY_AUTOMATIC = "last_was_automatic"
        const val KEY_WRITTEN = "last_written"
    }
}
