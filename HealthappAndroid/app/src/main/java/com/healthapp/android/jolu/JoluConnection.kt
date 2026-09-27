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
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withContext

/** Where the connection to JoLu stands. The JoLu section on screen shows exactly this. */
sealed interface JoluState {

    /** Looking for a stored token at start-up. */
    data object Checking : JoluState

    /** No token: signing in, registering and the pairing form. [message] says why, when there is a reason. */
    data class NotConnected(val message: String? = null) : JoluState

    /** A pairing code is being exchanged for a token. */
    data object Pairing : JoluState

    /** A token is stored; the profile and the targets are being read. */
    data object Loading : JoluState

    /** [scope]: paired with a code (SYNC), or signed in as the account (ACCOUNT). */
    data class Connected(
        val profile: JoluProfile,
        val targets: JoluTargets,
        val scope: JoluScope = JoluScope.SYNC
    ) : JoluState

    /** A token is stored but this attempt failed: offline, or trouble on the server. The token is kept. */
    data class Failed(val message: String, val scope: JoluScope? = null) : JoluState
}

/** Signing in, registering or signing out: what is going on, or why the last attempt did not work. */
sealed interface JoluAuthState {

    data object Idle : JoluAuthState

    data class Working(val message: String) : JoluAuthState

    /** Nothing changed: the credential the phone had, it still has. */
    data class Failed(val message: String) : JoluAuthState
}

/**
 * This phone's connection to a JoLu account, and the one credential behind it.
 *
 * Two ways to get one, as the server has them (docs/APP-AUTH.md):
 *
 *   pairing   the 8-character code from the JoLu website -> a SYNC token:
 *             Health Connect sync, profile, targets
 *   signing   username (or e-mail) and password, or a new account -> an
 *   in        ACCOUNT token: all of that, and acting as the account
 *
 * Both are kept encrypted (JoluTokenStore) with their scope, and the phone
 * holds at most one: signing in on a paired phone sends the sync token along,
 * the server turns that phone's row into the account row with a new token,
 * and the old one stops working. The sync always uses whatever is stored.
 *
 * From then on the stored token reconnects at every start. Only an HTTP 401 —
 * the phone was removed on the website, the account is gone, the token lapsed
 * — ends it, and only for the token that 401 was about: the sync and the
 * screen can be sending with a token that signing in has just replaced, and
 * that late 401 must not sign out the person who just signed in
 * ([rejected]). Being offline, or the server having trouble, keeps the token.
 *
 * One object for the whole app rather than state inside the screen, so an
 * answer that arrives after the screen was rebuilt (a rotation) still lands,
 * and a token the server has just issued is always stored: the server shows
 * it once, so a lost token means pairing or signing in again.
 */
object JoluConnection {

    const val EXPIRED_MESSAGE = "JoLu connection expired. Please pair the device again."
    const val SESSION_EXPIRED_MESSAGE = "Je sessie is verlopen. Log opnieuw in."
    const val LOGGED_OUT_MESSAGE = "Je bent uitgelogd."
    const val LOGGED_OUT_UNREACHED_MESSAGE =
        "Je bent uitgelogd op deze telefoon. JoLu was niet bereikbaar; " +
            "verwijder dit apparaat zo nodig via Instellingen op de website."

    private const val INVALID_CODE_MESSAGE =
        "Enter the 8-character pairing code from the JoLu website."
    private const val NOT_STORED_MESSAGE =
        "This phone could not store the JoLu connection securely. Please pair again with a new code."
    private const val UNEXPECTED_MESSAGE =
        "Something went wrong while connecting to JoLu. Please try again."

    private const val LOGIN_EMPTY_MESSAGE = "Vul je gebruikersnaam en wachtwoord in."
    private const val REGISTER_EMPTY_MESSAGE = "Vul een gebruikersnaam, e-mailadres en wachtwoord in."
    private const val ACCOUNT_NOT_STORED_MESSAGE =
        "Deze telefoon kon je sessie niet veilig bewaren. Log opnieuw in."

    var state: JoluState by mutableStateOf(JoluState.Checking)
        private set

    var auth: JoluAuthState by mutableStateOf(JoluAuthState.Idle)
        private set

    /** Whether signing in or registering is on offer: not signed in as an account yet, nothing else going on. */
    val canSignIn: Boolean
        get() = !busy && (state is JoluState.NotConnected || (state as? JoluState.Connected)?.scope == JoluScope.SYNC)

    /** The JoLu server. Tests point it at their own; nothing else changes it. */
    internal var api = JoluApi()

    /** Where the token is kept: encrypted on the phone (JoluTokenStore). Tests keep it in memory. */
    internal var storage: (Context) -> JoluTokenStorage = { JoluTokenStore(it) }

    private val scope = CoroutineScope(
        SupervisorJob() + Dispatchers.Main.immediate + CoroutineExceptionHandler { _, _ ->
            // A safety net so the app never crashes over JoLu. Nothing is
            // logged: an exception could carry the token or profile data.
            state = JoluState.Failed(UNEXPECTED_MESSAGE)
            auth = JoluAuthState.Idle
        }
    )

    /**
     * Every change to the stored credential happens under this lock —
     * storing a new one, and "forget it if it is still the one that was
     * refused" — so the two can never interleave.
     */
    private val credentialLock = Mutex()

    private var job: Job? = null
    private var tokenStore: JoluTokenStorage? = null

    /** The application context, never an activity: kept for the automatic sync's schedule. */
    private var appContext: Context? = null

    private val busy: Boolean
        get() = job?.isActive == true

    /** At start-up: a stored token reconnects without asking for a code or a password. */
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
                    val credential = JoluCredential(result.value, JoluScope.SYNC)

                    if (save(store, credential)) {
                        // A new pairing, perhaps another account: the last
                        // sync shown belonged to the old connection.
                        val app = context.applicationContext
                        withContext(Dispatchers.IO) { JoluSyncRunner.environment(app).status.clear() }
                        JoluSync.refresh(app)

                        load(store, credential)
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

    /**
     * Signs in as a JoLu account (app-login.php). On a paired phone the sync
     * token goes along, so the server makes this phone's row the account row;
     * the sync carries on with the new token.
     *
     * A wrong name or password changes nothing: the phone keeps what it had,
     * and the reason is in [auth].
     */
    fun login(context: Context, identifier: String, password: String) {
        val name = identifier.trim()

        if (name.isEmpty() || password.isEmpty()) {
            auth = JoluAuthState.Failed(LOGIN_EMPTY_MESSAGE)
            return
        }

        signIn(context, "Inloggen...") { current -> api.login(name, password, deviceLabel(), current) }
    }

    /** Creates a JoLu account (app-register.php) and signs in as it, like [login]. */
    fun register(context: Context, username: String, email: String, password: String) {
        val name = username.trim()
        val address = email.trim()

        if (name.isEmpty() || address.isEmpty() || password.isEmpty()) {
            auth = JoluAuthState.Failed(REGISTER_EMPTY_MESSAGE)
            return
        }

        signIn(context, "Account aanmaken...") { current -> api.register(address, name, password, deviceLabel(), current) }
    }

    /**
     * Signs the account out: the token is forgotten on this phone and the
     * automatic sync stopped first — so nothing starting now can use it —
     * then revoked on the server (app-logout.php), which also ends it if the
     * server cannot be reached right now: the phone signs out either way.
     *
     * Health Connect's permissions are not touched: signing in again syncs
     * straight away, without asking for them again. A phone that was paired
     * before signing in was the same row on the server, so it is signed out
     * too — the server's design, one row per phone.
     */
    fun logout(context: Context) {
        if (busy) {
            return
        }

        val store = store(context)

        job = scope.launch {
            auth = JoluAuthState.Working("Uitloggen...")

            val signedOut = withContext(Dispatchers.IO) {
                credentialLock.withLock {
                    store.load()?.takeIf { it.scope == JoluScope.ACCOUNT }?.also { store.clear() }
                }
            }

            if (signedOut == null) {
                auth = JoluAuthState.Idle
                return@launch
            }

            val app = context.applicationContext
            JoluBackgroundSync.stop(app)
            withContext(Dispatchers.IO) { JoluSyncRunner.environment(app).status.clear() }
            JoluSync.forget(app)

            val reached = when (val result = api.logout(signedOut.token)) {
                is JoluResult.Success, is JoluResult.Unauthorized -> true
                is JoluResult.HttpError -> result.status < 500
                JoluResult.NetworkError, JoluResult.InvalidResponse -> false
            }

            auth = JoluAuthState.Idle
            state = JoluState.NotConnected(if (reached) LOGGED_OUT_MESSAGE else LOGGED_OUT_UNREACHED_MESSAGE)
        }
    }

    /** The stored token for another JoLu call (the sync), or null when there is none. */
    internal suspend fun storedToken(context: Context): String? {
        val store = store(context)
        return withContext(Dispatchers.IO) { store.load()?.token }
    }

    /**
     * Another JoLu call (the sync, by the button or automatic) was answered
     * with 401 for [token]. If that is still the stored token: handled
     * exactly like a 401 on the profile — forgotten, automatic sync stopped,
     * the screen asks to pair or sign in again — and true.
     *
     * If the stored token is another one by now (signed in, or out, while
     * that call was on its way), the 401 was about a token that is already
     * gone: nothing changes, and false.
     */
    internal suspend fun rejected(context: Context, token: String): Boolean {
        val store = store(context)
        val forgotten = forget(store, token) ?: return false

        ended(forgotten.scope)
        return true
    }

    /**
     * Forgets whatever is stored, as if the server had refused it — for when
     * the phone is to start from "not connected".
     */
    internal suspend fun unauthorized(context: Context) {
        val store = store(context)
        val forgotten = withContext(Dispatchers.IO) {
            credentialLock.withLock { store.load().also { store.clear() } }
        }

        ended(forgotten?.scope ?: JoluScope.SYNC)
    }

    /** As when the app process starts: nothing known yet. For tests, which cannot restart the process. */
    internal fun reset() {
        job?.cancel()
        job = null
        tokenStore = null
        state = JoluState.Checking
        auth = JoluAuthState.Idle
    }

    /** Signing in or registering: the call, then — only on success — the new ACCOUNT credential. */
    private fun signIn(context: Context, working: String, call: suspend (current: String?) -> JoluResult<JoluSession>) {
        if (!canSignIn) {
            return
        }

        val store = store(context)

        job = scope.launch {
            auth = JoluAuthState.Working(working)

            val current = withContext(Dispatchers.IO) { store.load() }

            when (val result = call(current?.token)) {
                is JoluResult.Success -> {
                    val credential = JoluCredential(result.value.token, JoluScope.ACCOUNT)

                    if (!save(store, credential)) {
                        auth = JoluAuthState.Failed(ACCOUNT_NOT_STORED_MESSAGE)
                        return@launch
                    }

                    auth = JoluAuthState.Idle

                    // A new session: what the last one synced is not shown to this one.
                    val app = context.applicationContext
                    withContext(Dispatchers.IO) { JoluSyncRunner.environment(app).status.clear() }
                    JoluSync.forget(app)

                    load(store, credential)
                }

                is JoluResult.Failure -> auth = JoluAuthState.Failed(describeSignIn(result))
            }
        }
    }

    private fun reconnect(context: Context) {
        if (busy) {
            return
        }

        val store = store(context)

        job = scope.launch {
            val credential = withContext(Dispatchers.IO) { store.load() }

            if (credential == null) {
                state = JoluState.NotConnected()
            } else {
                load(store, credential)
            }
        }
    }

    /** Profile, then targets. A 401 from either ends the connection; any other failure keeps it. */
    private suspend fun load(store: JoluTokenStorage, credential: JoluCredential) {
        state = JoluState.Loading

        val profile = when (val result = api.profile(credential.token)) {
            is JoluResult.Success -> result.value
            is JoluResult.Unauthorized -> return expire(store, credential)
            is JoluResult.Failure -> return fail(result, credential.scope)
        }

        val targets = when (val result = api.nutritionTargets(credential.token)) {
            is JoluResult.Success -> result.value
            is JoluResult.Unauthorized -> return expire(store, credential)
            is JoluResult.Failure -> return fail(result, credential.scope)
        }

        state = JoluState.Connected(profile, targets, credential.scope)

        // Paired, signed in, or reconnected at start-up: keep the automatic sync going.
        appContext?.let { JoluBackgroundSync.connected(it) }
    }

    /**
     * The server no longer accepts [credential]: forget it — if it is still
     * the stored one — stop the automatic sync so it is never tried again,
     * and ask to pair or sign in again.
     */
    private suspend fun expire(store: JoluTokenStorage, credential: JoluCredential) {
        if (forget(store, credential.token) != null) {
            return ended(credential.scope)
        }

        // Already replaced or forgotten by somebody else.
        when (val current = withContext(Dispatchers.IO) { store.load() }) {
            null -> state = JoluState.NotConnected(expiredMessage(credential.scope))
            else -> load(store, current)
        }
    }

    /** The token is gone: no automatic sync without one, and the screen says why. */
    private fun ended(scope: JoluScope) {
        appContext?.let { JoluBackgroundSync.stop(it) }
        state = JoluState.NotConnected(expiredMessage(scope))
    }

    /** Stored, or false when the phone could not store it; nothing changes then. */
    private suspend fun save(store: JoluTokenStorage, credential: JoluCredential): Boolean =
        withContext(Dispatchers.IO) {
            credentialLock.withLock { runCatching { store.save(credential) }.isSuccess }
        }

    /** Forgets the stored credential if its token is [token]; returns it then, or null when it is another one or none. */
    private suspend fun forget(store: JoluTokenStorage, token: String): JoluCredential? =
        withContext(Dispatchers.IO) {
            credentialLock.withLock {
                store.load()?.takeIf { it.token == token }?.also { store.clear() }
            }
        }

    private fun expiredMessage(scope: JoluScope): String =
        if (scope == JoluScope.ACCOUNT) SESSION_EXPIRED_MESSAGE else EXPIRED_MESSAGE

    private fun fail(failure: JoluResult.Failure, scope: JoluScope) {
        state = JoluState.Failed(describe(failure), scope)
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

    /**
     * Why signing in or registering did not work. The server's own sentence
     * when it sent one — a wrong name or password (the same words whether
     * the name exists or not), "too many attempts, try again in N minutes",
     * an address or username that is taken — otherwise what kind of trouble.
     */
    internal fun describeSignIn(failure: JoluResult.Failure): String =
        when (failure) {
            is JoluResult.Unauthorized ->
                failure.message ?: "Gebruikersnaam of wachtwoord klopt niet."

            is JoluResult.HttpError ->
                failure.message ?: when (failure.status) {
                    429 -> "Te veel pogingen. Probeer het later opnieuw."
                    else -> "JoLu is nu niet beschikbaar (HTTP ${failure.status}). Probeer het later opnieuw."
                }

            JoluResult.NetworkError ->
                "Kan JoLu niet bereiken. Controleer je internetverbinding en probeer het opnieuw."

            JoluResult.InvalidResponse ->
                "JoLu gaf een onverwacht antwoord. Probeer het later opnieuw."
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
