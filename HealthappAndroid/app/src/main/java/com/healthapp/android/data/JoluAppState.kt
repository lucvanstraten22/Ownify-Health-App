package com.healthapp.android.data

import android.content.Context
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import com.healthapp.android.jolu.JoluConnection
import com.healthapp.android.jolu.JoluResult
import kotlinx.coroutines.CoroutineExceptionHandler
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import org.json.JSONObject

/** Where the app's pages stand. */
sealed interface AppLoad {

    /** Not asked yet (not signed in as an account, or just signed out). */
    data object Idle : AppLoad

    /** The first read, with nothing to show yet. */
    data object Loading : AppLoad

    /** The pages as the server sent them; [refreshing] while a newer read is on its way. */
    data class Ready(val data: AppData, val refreshing: Boolean = false) : AppLoad

    /**
     * The read did not work — offline, or trouble on the server. [data] is
     * the last good read, still shown, or null when there never was one.
     */
    data class Failed(val message: String, val data: AppData?) : AppLoad

    /** The stored token is a pairing (sync) token: it may upload, not read the account. */
    data object SyncOnly : AppLoad
}

/**
 * The signed-in app's data: one read of api/app/state.php, kept in memory
 * only (no health data is written to the phone), read again after every
 * change — as the website reloads or re-fetches its page.
 *
 * A 401 is handled exactly as the sync handles one: the credential is
 * forgotten only if it is still the token that was refused
 * ([JoluConnection.rejected]); a token that signing in has just replaced
 * gets one more read with the current one. Nothing here ever clears a newer
 * session.
 */
object JoluAppState {

    const val UNREACHABLE = "De server is niet bereikbaar."
    const val SERVER_TROUBLE = "Er ging iets mis op de server. Probeer het opnieuw."
    const val UNEXPECTED = "Onverwacht antwoord van de server."

    var load: AppLoad by mutableStateOf(AppLoad.Idle)
        private set

    /** The pages being shown, whatever the last read did. */
    val data: AppData?
        get() = when (val l = load) {
            is AppLoad.Ready -> l.data
            is AppLoad.Failed -> l.data
            else -> null
        }

    private val scope = CoroutineScope(
        SupervisorJob() + Dispatchers.Main.immediate + CoroutineExceptionHandler { _, _ ->
            // Never a crash over a read, and nothing logged: it could carry health data.
            load = AppLoad.Failed(UNEXPECTED, data)
        }
    )

    private var job: Job? = null

    /** Reads the pages, unless a read is on its way. Shows what it has meanwhile. */
    fun refresh(context: Context) {
        if (job?.isActive == true) return
        val app = context.applicationContext
        job = scope.launch { read(app) }
    }

    /** Reads now and waits for it — after a write, so the next screen shows the result. */
    suspend fun reload(context: Context) {
        job?.join()
        read(context.applicationContext)
    }

    /** Signed out, or another account: nothing of the last one stays in memory. */
    fun clear() {
        job?.cancel()
        job = null
        load = AppLoad.Idle
    }

    /** Reads once more with the stored token when the first was refused for a token already replaced. */
    private suspend fun read(context: Context) {
        val before = data
        load = if (before == null) AppLoad.Loading else AppLoad.Ready(before, refreshing = true)

        repeat(2) {
            val token = JoluConnection.storedToken(context)
            if (token == null) {
                load = AppLoad.Idle
                return
            }

            when (val result = JoluConnection.api.appState(token)) {
                is JoluResult.Success -> {
                    val parsed = withContext(Dispatchers.Default) { runCatching { AppData.parse(result.value) }.getOrNull() }
                    load = if (parsed != null) AppLoad.Ready(parsed) else AppLoad.Failed(UNEXPECTED, before)
                    return
                }

                is JoluResult.Unauthorized -> {
                    if (JoluConnection.rejected(context, token)) {
                        // Forgotten, sync stopped; the connection now asks to sign in.
                        load = AppLoad.Idle
                        return
                    }
                    // Replaced while this read was on its way: once more, with the current one.
                }

                is JoluResult.HttpError -> {
                    load = if (result.status == 403) AppLoad.SyncOnly else AppLoad.Failed(result.message ?: SERVER_TROUBLE, before)
                    return
                }

                JoluResult.NetworkError -> {
                    load = AppLoad.Failed(UNREACHABLE, before)
                    return
                }

                JoluResult.InvalidResponse -> {
                    load = AppLoad.Failed(UNEXPECTED, before)
                    return
                }
            }
        }

        load = AppLoad.Idle
    }

    /** For tests: the parser on a fixture, as a Ready state. */
    internal fun show(data: JSONObject) {
        load = AppLoad.Ready(AppData.parse(data))
    }
}
