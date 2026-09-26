package com.healthapp.android.jolu

import android.content.Context
import android.os.Build
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import kotlinx.coroutines.CoroutineExceptionHandler
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/** Where the connection to JoLu stands. The JoLu section on screen shows exactly this. */
sealed interface JoluState {

    /** Looking for a stored token at start-up. */
    data object Checking : JoluState

    /** No token: the pairing form. [message] says why, when there is a reason. */
    data class NotConnected(val message: String? = null) : JoluState

    /** A pairing code is being exchanged for a token. */
    data object Pairing : JoluState

    /** A token is stored; the profile and the targets are being read. */
    data object Loading : JoluState

    data class Connected(val profile: JoluProfile, val targets: JoluTargets) : JoluState

    /** A token is stored but this attempt failed: offline, or trouble on the server. The token is kept. */
    data class Failed(val message: String) : JoluState
}

/**
 * This phone's connection to a JoLu account.
 *
 * Pairing exchanges the 8-character code from the JoLu website for a device
 * token, which is stored encrypted (JoluTokenStore). The app never asks for a
 * JoLu username or password, and never sends a user id.
 *
 * From then on the stored token reconnects at every start. Only an HTTP 401 —
 * the phone was removed on the website, or the account is gone — asks for a
 * new code. Being offline, or the server having trouble, keeps the token.
 *
 * One object for the whole app rather than state inside the screen, so an
 * answer that arrives after the screen was rebuilt (a rotation) still lands,
 * and a token the server has just issued is always stored: a pairing code
 * works once, so a lost token would mean asking for a new code.
 */
object JoluConnection {

    const val EXPIRED_MESSAGE = "JoLu connection expired. Please pair the device again."

    private const val INVALID_CODE_MESSAGE =
        "Enter the 8-character pairing code from the JoLu website."
    private const val NOT_STORED_MESSAGE =
        "This phone could not store the JoLu connection securely. Please pair again with a new code."
    private const val UNEXPECTED_MESSAGE =
        "Something went wrong while connecting to JoLu. Please try again."

    var state: JoluState by mutableStateOf(JoluState.Checking)
        private set

    /** The JoLu server. Tests point it at their own; nothing else changes it. */
    internal var api = JoluApi()

    /** Where the token is kept: encrypted on the phone (JoluTokenStore). Tests keep it in memory. */
    internal var storage: (Context) -> JoluTokenStorage = { JoluTokenStore(it) }

    private val scope = CoroutineScope(
        SupervisorJob() + Dispatchers.Main.immediate + CoroutineExceptionHandler { _, _ ->
            // A safety net so the app never crashes over JoLu. Nothing is
            // logged: an exception could carry the token or profile data.
            state = JoluState.Failed(UNEXPECTED_MESSAGE)
        }
    )

    private var job: Job? = null
    private var tokenStore: JoluTokenStorage? = null

    /** The application context, never an activity: kept for the automatic sync's schedule. */
    private var appContext: Context? = null

    private val busy: Boolean
        get() = job?.isActive == true

    /** At start-up: a stored token reconnects without asking for a code. */
    fun start(context: Context) {
        if (state == JoluState.Checking) {
            reconnect(context)
        }
    }

    /** "Try again" after a failed attempt, with the token still stored. */
    fun retry(context: Context) {
        if (state is JoluState.Failed) {
            reconnect(context)
        }
    }

    /** Pairs this phone with the code the person typed in. */
    fun connect(context: Context, input: String) {
        if (busy || state !is JoluState.NotConnected) {
            return
        }

        val code = normalizePairingCode(input)

        if (code == null) {
            state = JoluState.NotConnected(INVALID_CODE_MESSAGE)
            return
        }

        val store = store(context)

        job = scope.launch {
            state = JoluState.Pairing

            when (val result = api.pair(code, deviceLabel())) {
                is JoluResult.Success -> {
                    val stored = withContext(Dispatchers.IO) {
                        runCatching { store.save(result.value) }.isSuccess
                    }

                    if (stored) {
                        // A new pairing, perhaps another account: the last
                        // sync shown belonged to the old connection.
                        val app = context.applicationContext
                        withContext(Dispatchers.IO) { JoluSyncRunner.environment(app).status.clear() }
                        JoluSync.refresh(app)

                        load(store, result.value)
                    } else {
                        state = JoluState.NotConnected(NOT_STORED_MESSAGE)
                    }
                }

                // The code was refused. The server says why: not valid, expired,
                // already used, or the maximum number of phones is reached.
                is JoluResult.Unauthorized ->
                    state = JoluState.NotConnected(
                        "Pairing failed: " + (result.message ?: "the code is not valid or has expired.")
                    )

                is JoluResult.Failure ->
                    state = JoluState.NotConnected(describe(result))
            }
        }
    }

    /** The stored token for another JoLu call (the sync), or null when this phone is not paired. */
    internal suspend fun storedToken(context: Context): String? {
        val store = store(context)
        return withContext(Dispatchers.IO) { store.load() }
    }

    /**
     * Another JoLu call (the sync, by the button or automatic) was answered
     * with 401: handled exactly like a 401 on the profile — the token is
     * forgotten, automatic sync stops, and a new code is asked for.
     */
    internal suspend fun unauthorized(context: Context) {
        expire(store(context))
    }

    private fun reconnect(context: Context) {
        if (busy) {
            return
        }

        val store = store(context)

        job = scope.launch {
            val token = withContext(Dispatchers.IO) { store.load() }

            if (token == null) {
                state = JoluState.NotConnected()
            } else {
                load(store, token)
            }
        }
    }

    /** Profile, then targets. A 401 from either ends the connection; any other failure keeps it. */
    private suspend fun load(store: JoluTokenStorage, token: String) {
        state = JoluState.Loading

        val profile = when (val result = api.profile(token)) {
            is JoluResult.Success -> result.value
            is JoluResult.Unauthorized -> return expire(store)
            is JoluResult.Failure -> return fail(result)
        }

        val targets = when (val result = api.nutritionTargets(token)) {
            is JoluResult.Success -> result.value
            is JoluResult.Unauthorized -> return expire(store)
            is JoluResult.Failure -> return fail(result)
        }

        state = JoluState.Connected(profile, targets)

        // Paired, or reconnected at start-up: keep the automatic sync going.
        appContext?.let { JoluBackgroundSync.connected(it) }
    }

    /**
     * The server no longer accepts the token: forget it, stop the automatic
     * sync so it is never tried again, and ask for a new code.
     */
    private suspend fun expire(store: JoluTokenStorage) {
        withContext(Dispatchers.IO) { store.clear() }
        appContext?.let { JoluBackgroundSync.stop(it) }
        state = JoluState.NotConnected(EXPIRED_MESSAGE)
    }

    private fun fail(failure: JoluResult.Failure) {
        state = JoluState.Failed(describe(failure))
    }

    internal fun describe(failure: JoluResult.Failure): String =
        when (failure) {
            is JoluResult.Unauthorized ->
                EXPIRED_MESSAGE

            is JoluResult.HttpError ->
                "JoLu is not available right now (HTTP ${failure.status}). Please try again later."

            JoluResult.NetworkError ->
                "Could not reach JoLu. Check your internet connection and try again."

            JoluResult.InvalidResponse ->
                "JoLu sent an unexpected response. Please try again later."
        }

    private fun store(context: Context): JoluTokenStorage {
        appContext = context.applicationContext
        return tokenStore ?: storage(context.applicationContext).also { tokenStore = it }
    }

    /**
     * How this phone is listed under the paired devices on the JoLu website,
     * e.g. "Google Pixel 8" or "Samsung SM-S911B".
     */
    private fun deviceLabel(): String {
        val manufacturer = Build.MANUFACTURER.orEmpty().trim()
        val model = Build.MODEL.orEmpty().trim()

        val label = when {
            model.isEmpty() -> manufacturer
            manufacturer.isEmpty() || model.startsWith(manufacturer, ignoreCase = true) -> model
            else -> manufacturer.replaceFirstChar { it.uppercase() } + " " + model
        }

        return label.ifEmpty { "Android" }.take(80)
    }
}
