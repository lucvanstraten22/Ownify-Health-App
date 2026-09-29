package com.ownify.android.connection

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

/** Where the connection to Ownify stands. The Ownify section on screen shows exactly this. */
sealed interface OwnifyState {

    /** Looking for a stored token at start-up. */
    data object Checking : OwnifyState

    /**
     * No token: signing in, registering and the pairing form. [message] says
     * why, when there is a reason; [link] is a way onward from it (Google's
     * own page, after deleting an account that used Google).
     */
    data class NotConnected(val message: String? = null, val link: OwnifyLink? = null) : OwnifyState

    /** A pairing code is being exchanged for a token. */
    data object Pairing : OwnifyState

    /** A token is stored; the profile and the targets are being read. */
    data object Loading : OwnifyState

    /** [scope]: paired with a code (SYNC), or signed in as the account (ACCOUNT). */
    data class Connected(
        val profile: OwnifyProfile,
        val targets: OwnifyTargets,
        val scope: OwnifyScope = OwnifyScope.SYNC
    ) : OwnifyState

    /** A token is stored but this attempt failed: offline, or trouble on the server. The token is kept. */
    data class Failed(val message: String, val scope: OwnifyScope? = null) : OwnifyState
}

/** A fixed address the server named, opened beside the app, with its label. */
data class OwnifyLink(val href: String, val label: String)

/**
 * Somebody new after Google: the one step before their account exists —
 * choosing a username (the website's "Kies je gebruikersnaam"). [email] is the
 * address Google vouched for; [minutes] how long the step waits.
 */
data class GoogleUsernameStep(val email: String, val minutes: Int)

/** Signing in, registering or signing out: what is going on, or why the last attempt did not work. */
sealed interface OwnifyAuthState {

    data object Idle : OwnifyAuthState

    data class Working(val message: String) : OwnifyAuthState

    /** Nothing changed: the credential the phone had, it still has. */
    data class Failed(val message: String) : OwnifyAuthState
}

/**
 * This phone's connection to a Ownify account, and the one credential behind it.
 *
 * Two ways to get one, as the server has them (docs/APP-AUTH.md):
 *
 *   pairing   the 8-character code from the Ownify website -> a SYNC token:
 *             Health Connect sync, profile, targets
 *   signing   username (or e-mail) and password, a new account, or Google
 *   in        -> an ACCOUNT token: all of that, and acting as the account
 *
 * Both are kept encrypted (OwnifyTokenStore) with their scope, and the phone
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
object OwnifyConnection {

    const val EXPIRED_MESSAGE = "De koppeling met Ownify is verlopen. Koppel deze telefoon opnieuw."
    const val SESSION_EXPIRED_MESSAGE = "Je sessie is verlopen. Log opnieuw in."
    const val LOGGED_OUT_MESSAGE = "Je bent uitgelogd."
    const val LOGGED_OUT_UNREACHED_MESSAGE =
        "Je bent uitgelogd op deze telefoon. Ownify was niet bereikbaar; " +
            "verwijder dit apparaat zo nodig via Instellingen op de website."

    private const val INVALID_CODE_MESSAGE =
        "Vul de koppelcode van 8 tekens van de Ownify-website in."
    private const val NOT_STORED_MESSAGE =
        "Deze telefoon kon de koppeling niet veilig bewaren. Koppel opnieuw met een nieuwe code."
    private const val UNEXPECTED_MESSAGE =
        "Er ging iets mis bij het verbinden met Ownify. Probeer het opnieuw."

    private const val GOOGLE_WORKING_MESSAGE = "Inloggen met Google..."
    private const val GOOGLE_FAILED_MESSAGE = "Inloggen met Google is niet gelukt. Probeer het opnieuw."
    private const val GOOGLE_NO_ACCOUNT_MESSAGE =
        "Er staat geen Google-account op deze telefoon. Voeg er een toe in de instellingen van je telefoon en probeer het opnieuw."
    private const val GOOGLE_UNAVAILABLE_MESSAGE =
        "Inloggen met Google werkt niet op deze telefoon: Google Play-services ontbreken of zijn verouderd."
    private const val GOOGLE_INTERRUPTED_MESSAGE = "Inloggen met Google werd onderbroken. Probeer het opnieuw."
    private const val GOOGLE_EXPIRED_MESSAGE = "Je Google-aanmelding is verlopen. Begin opnieuw met Google."
    private const val USERNAME_EMPTY_MESSAGE = "Kies een gebruikersnaam."

    private const val LOGIN_EMPTY_MESSAGE = "Vul je gebruikersnaam en wachtwoord in."
    private const val REGISTER_EMPTY_MESSAGE = "Vul een gebruikersnaam, e-mailadres en wachtwoord in."
    private const val ACCOUNT_NOT_STORED_MESSAGE =
        "Deze telefoon kon je sessie niet veilig bewaren. Log opnieuw in."

    var state: OwnifyState by mutableStateOf(OwnifyState.Checking)
        private set

    var auth: OwnifyAuthState by mutableStateOf(OwnifyAuthState.Idle)
        private set

    /**
     * Whether this server offers Google sign-in to the app: null until asked
     * ([checkGoogle]) or while it cannot be reached — then the button stays,
     * and pressing it says what is wrong.
     */
    var googleAvailable: Boolean? by mutableStateOf(null)
        private set

    /** Somebody new after Google, choosing their username; null otherwise. */
    var googleStep: GoogleUsernameStep? by mutableStateOf(null)
        private set

    /** Whether signing in or registering is on offer: not signed in as an account yet, nothing else going on. */
    val canSignIn: Boolean
        get() = !busy && (state is OwnifyState.NotConnected || (state as? OwnifyState.Connected)?.scope == OwnifyScope.SYNC)

    /** The Ownify server. Tests point it at their own; nothing else changes it. */
    internal var api = OwnifyApi()

    /** Where the token is kept: encrypted on the phone (OwnifyTokenStore). Tests keep it in memory. */
    internal var storage: (Context) -> OwnifyTokenStorage = { OwnifyTokenStore(it) }

    /** Google's side of signing in: Credential Manager. Tests stand in for Google. */
    internal var google: GoogleIdTokens = CredentialManagerGoogle

    /** The sign-in with Google waiting for a username: its server session lives in here. */
    private var googleConversation: OwnifyApi.GoogleConversation? = null

    private val scope = CoroutineScope(
        SupervisorJob() + Dispatchers.Main.immediate + CoroutineExceptionHandler { _, _ ->
            // A safety net so the app never crashes over Ownify. Nothing is
            // logged: an exception could carry the token or profile data.
            state = OwnifyState.Failed(UNEXPECTED_MESSAGE)
            auth = OwnifyAuthState.Idle
        }
    )

    /**
     * Every change to the stored credential happens under this lock —
     * storing a new one, and "forget it if it is still the one that was
     * refused" — so the two can never interleave.
     */
    private val credentialLock = Mutex()

    private var job: Job? = null
    private var tokenStore: OwnifyTokenStorage? = null

    /** The application context, never an activity: kept for the automatic sync's schedule. */
    private var appContext: Context? = null

    private val busy: Boolean
        get() = job?.isActive == true

    /** The last sign-in's error, put away: the form moved to another flow, or was closed. */
    fun dismissAuthMessage() {
        if (auth is OwnifyAuthState.Failed) {
            auth = OwnifyAuthState.Idle
        }
    }

    /** At start-up: a stored token reconnects without asking for a code or a password. */
    fun start(context: Context) {
        if (state == OwnifyState.Checking) {
            reconnect(context)
        }
    }

    /** "Try again" after a failed attempt, with the token still stored. */
    fun retry(context: Context) {
        if (state is OwnifyState.Failed) {
            reconnect(context)
        }
    }

    /** Pairs this phone with the code the person typed in. */
    fun connect(context: Context, input: String) {
        if (busy || state !is OwnifyState.NotConnected) {
            return
        }

        val code = normalizePairingCode(input)

        if (code == null) {
            state = OwnifyState.NotConnected(INVALID_CODE_MESSAGE)
            return
        }

        val store = store(context)

        job = scope.launch {
            state = OwnifyState.Pairing

            when (val result = api.pair(code, deviceLabel())) {
                is OwnifyResult.Success -> {
                    val credential = OwnifyCredential(result.value, OwnifyScope.SYNC)

                    if (save(store, credential)) {
                        // A new pairing, perhaps another account: the last
                        // sync shown belonged to the old connection.
                        val app = context.applicationContext
                        withContext(Dispatchers.IO) { OwnifySyncRunner.environment(app).status.clear() }
                        OwnifySync.refresh(app)

                        load(store, credential)
                    } else {
                        state = OwnifyState.NotConnected(NOT_STORED_MESSAGE)
                    }
                }

                // The code was refused. The server says why: not valid, expired,
                // already used, or the maximum number of phones is reached.
                is OwnifyResult.Unauthorized ->
                    state = OwnifyState.NotConnected(
                        "Koppelen is niet gelukt: " + (result.message ?: "de code is ongeldig of verlopen.")
                    )

                is OwnifyResult.Failure ->
                    state = OwnifyState.NotConnected(describe(result))
            }
        }
    }

    /**
     * Signs in as a Ownify account (app-login.php). On a paired phone the sync
     * token goes along, so the server makes this phone's row the account row;
     * the sync carries on with the new token.
     *
     * A wrong name or password changes nothing: the phone keeps what it had,
     * and the reason is in [auth].
     */
    fun login(context: Context, identifier: String, password: String) {
        val name = identifier.trim()

        if (name.isEmpty() || password.isEmpty()) {
            auth = OwnifyAuthState.Failed(LOGIN_EMPTY_MESSAGE)
            return
        }

        signIn(context, "Inloggen...") { current -> api.login(name, password, deviceLabel(), current) }
    }

    /** Creates a Ownify account (app-register.php) and signs in as it, like [login]. */
    fun register(context: Context, username: String, email: String, password: String) {
        val name = username.trim()
        val address = email.trim()

        if (name.isEmpty() || address.isEmpty() || password.isEmpty()) {
            auth = OwnifyAuthState.Failed(REGISTER_EMPTY_MESSAGE)
            return
        }

        signIn(context, "Account aanmaken...") { current -> api.register(address, name, password, deviceLabel(), current) }
    }

    /**
     * Asks the server whether it offers Google sign-in to the app, for the
     * button. Nothing changes when it cannot be reached.
     */
    fun checkGoogle() {
        scope.launch {
            val result = api.googleAvailable()
            if (result is OwnifyResult.Success) {
                googleAvailable = result.value
            }
        }
    }

    /**
     * Signs in with Google, through the Ownify server (app-google.php):
     *
     *   1. a nonce from the server, with its Web client's id;
     *   2. Google's account chooser on the phone (Credential Manager), which
     *      hands back an ID token for that client with that nonce;
     *   3. the ID token to the server, which verifies it — signature,
     *      audience, this app's Android client, expiry, nonce — and decides
     *      with the website's own rules: a Ownify account with this Google
     *      account is signed in; somebody new chooses a username first
     *      ([googleStep], [chooseGoogleUsername]); an address a password
     *      account already has is refused, never merged.
     *
     * The phone believes nothing in the ID token and keeps none of it. What it
     * keeps is the account token the server issues, exactly as after a
     * password — so staying signed in, reopening the app and signing out are
     * the same as for every account. [activity] shows Google's chooser.
     */
    fun signInWithGoogle(activity: Context) {
        if (!canSignIn) {
            return
        }

        val store = store(activity)
        cancelGoogleConversation()

        job = scope.launch {
            auth = OwnifyAuthState.Working(GOOGLE_WORKING_MESSAGE)

            val conversation = api.google()

            val start = when (val result = conversation.start()) {
                is OwnifyResult.Success -> result.value
                is OwnifyResult.Failure -> {
                    if (result is OwnifyResult.HttpError && result.status == 501) {
                        googleAvailable = false
                    }
                    auth = OwnifyAuthState.Failed(describeGoogle(result))
                    return@launch
                }
            }

            val idToken = when (val result = google.request(activity, start.serverClientId, start.nonce)) {
                is GoogleIdResult.Token -> result.idToken
                GoogleIdResult.Cancelled -> {
                    // Closed Google's chooser: back where they were, nothing to say.
                    auth = OwnifyAuthState.Idle
                    return@launch
                }
                GoogleIdResult.NoAccount -> return@launch googleFailed(GOOGLE_NO_ACCOUNT_MESSAGE)
                GoogleIdResult.Unavailable -> return@launch googleFailed(GOOGLE_UNAVAILABLE_MESSAGE)
                GoogleIdResult.Interrupted -> return@launch googleFailed(GOOGLE_INTERRUPTED_MESSAGE)
                GoogleIdResult.Failed -> return@launch googleFailed(GOOGLE_FAILED_MESSAGE)
            }

            val current = withContext(Dispatchers.IO) { store.load() }

            when (val result = conversation.verify(idToken, deviceLabel(), current?.token)) {
                is OwnifyResult.Success -> when (val outcome = result.value) {
                    is GoogleOutcome.SignedIn -> signedIn(activity, store, outcome.session)

                    is GoogleOutcome.ChooseUsername -> {
                        googleConversation = conversation
                        googleStep = GoogleUsernameStep(outcome.email, ((outcome.seconds + 59) / 60).coerceAtLeast(1))
                        auth = OwnifyAuthState.Idle
                    }
                }

                is OwnifyResult.Failure -> auth = OwnifyAuthState.Failed(describeGoogle(result))
            }
        }
    }

    /**
     * Somebody new after Google: their username, once. The server makes the
     * account, already linked to Google, and signs them in. A username that is
     * not valid or taken keeps them on the step with the server's words; the
     * step running out sends them back to the start.
     */
    fun chooseGoogleUsername(context: Context, username: String) {
        val conversation = googleConversation ?: return
        val name = username.trim()

        if (busy) {
            return
        }

        if (name.isEmpty()) {
            auth = OwnifyAuthState.Failed(USERNAME_EMPTY_MESSAGE)
            return
        }

        val store = store(context)

        job = scope.launch {
            auth = OwnifyAuthState.Working("Account aanmaken...")

            val current = withContext(Dispatchers.IO) { store.load() }

            when (val result = conversation.username(name, deviceLabel(), current?.token)) {
                is OwnifyResult.Success -> {
                    googleConversation = null
                    googleStep = null
                    signedIn(context, store, result.value)
                }

                is OwnifyResult.HttpError -> {
                    if (result.status == 410) {
                        googleConversation = null
                        googleStep = null
                        auth = OwnifyAuthState.Failed(result.message ?: GOOGLE_EXPIRED_MESSAGE)
                    } else {
                        auth = OwnifyAuthState.Failed(describeGoogle(result))
                    }
                }

                is OwnifyResult.Failure -> auth = OwnifyAuthState.Failed(describeGoogle(result))
            }
        }
    }

    /** "Annuleren" on the username step: nothing is made, the server forgets who Google said this was. */
    fun cancelGoogle() {
        if (busy) {
            return
        }
        cancelGoogleConversation()
        auth = OwnifyAuthState.Idle
    }

    private fun cancelGoogleConversation() {
        val conversation = googleConversation ?: return
        googleConversation = null
        googleStep = null
        scope.launch { conversation.cancel() }
    }

    private fun googleFailed(message: String) {
        auth = OwnifyAuthState.Failed(message)
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
            auth = OwnifyAuthState.Working("Uitloggen...")

            val signedOut = withContext(Dispatchers.IO) {
                credentialLock.withLock {
                    store.load()?.takeIf { it.scope == OwnifyScope.ACCOUNT }?.also { store.clear() }
                }
            }

            if (signedOut == null) {
                auth = OwnifyAuthState.Idle
                return@launch
            }

            val app = context.applicationContext
            OwnifyBackgroundSync.stop(app)
            withContext(Dispatchers.IO) { OwnifySyncRunner.environment(app).status.clear() }
            OwnifySync.forget(app)
            // Signed in with Google or not: Credential Manager is not to pick an account by itself
            // next time. Beside the sign-out, never in its way.
            scope.launch { google.signedOut(app) }

            val reached = when (val result = api.logout(signedOut.token)) {
                is OwnifyResult.Success, is OwnifyResult.Unauthorized -> true
                is OwnifyResult.HttpError -> result.status < 500
                OwnifyResult.NetworkError, OwnifyResult.InvalidResponse -> false
            }

            auth = OwnifyAuthState.Idle
            state = OwnifyState.NotConnected(if (reached) LOGGED_OUT_MESSAGE else LOGGED_OUT_UNREACHED_MESSAGE)
        }
    }

    /** The stored token for another Ownify call (the sync), or null when there is none. */
    internal suspend fun storedToken(context: Context): String? {
        val store = store(context)
        return withContext(Dispatchers.IO) { store.load()?.token }
    }

    /**
     * Another Ownify call (the sync, by the button or automatic) was answered
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
     * The account was deleted with [token] (api/profile/delete.php said ok):
     * the token went with it. Forgotten — if it is still the stored one — the
     * automatic sync stopped and its status cleared, and the opening screen
     * says what the server said. A token replaced meanwhile is left alone.
     */
    internal suspend fun accountDeleted(context: Context, token: String, message: String, link: OwnifyLink?) {
        val store = store(context)
        forget(store, token) ?: return

        val app = context.applicationContext
        OwnifyBackgroundSync.stop(app)
        withContext(Dispatchers.IO) { OwnifySyncRunner.environment(app).status.clear() }
        OwnifySync.forget(app)
        state = OwnifyState.NotConnected(message, link)
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

        ended(forgotten?.scope ?: OwnifyScope.SYNC)
    }

    /** As when the app process starts: nothing known yet. For tests, which cannot restart the process. */
    internal fun reset() {
        job?.cancel()
        job = null
        tokenStore = null
        googleConversation = null
        googleStep = null
        googleAvailable = null
        state = OwnifyState.Checking
        auth = OwnifyAuthState.Idle
    }

    /** Signing in or registering: the call, then — only on success — the new ACCOUNT credential. */
    private fun signIn(context: Context, working: String, call: suspend (current: String?) -> OwnifyResult<OwnifySession>) {
        if (!canSignIn) {
            return
        }

        val store = store(context)

        job = scope.launch {
            auth = OwnifyAuthState.Working(working)

            val current = withContext(Dispatchers.IO) { store.load() }

            when (val result = call(current?.token)) {
                is OwnifyResult.Success -> signedIn(context, store, result.value)
                is OwnifyResult.Failure -> auth = OwnifyAuthState.Failed(describeSignIn(result))
            }
        }
    }

    /**
     * Every way of signing in ends here: the new ACCOUNT token is stored —
     * encrypted, replacing whatever the phone had — and the account opens.
     * A token the phone could not store is not used: nothing changes then.
     */
    private suspend fun signedIn(context: Context, store: OwnifyTokenStorage, session: OwnifySession) {
        val credential = OwnifyCredential(session.token, OwnifyScope.ACCOUNT)

        if (!save(store, credential)) {
            auth = OwnifyAuthState.Failed(ACCOUNT_NOT_STORED_MESSAGE)
            return
        }

        auth = OwnifyAuthState.Idle

        // A new session: what the last one synced is not shown to this one.
        val app = context.applicationContext
        withContext(Dispatchers.IO) { OwnifySyncRunner.environment(app).status.clear() }
        OwnifySync.forget(app)

        load(store, credential)
    }

    private fun reconnect(context: Context) {
        if (busy) {
            return
        }

        val store = store(context)

        job = scope.launch {
            val credential = withContext(Dispatchers.IO) { store.load() }

            if (credential == null) {
                state = OwnifyState.NotConnected()
            } else {
                load(store, credential)
            }
        }
    }

    /** Profile, then targets. A 401 from either ends the connection; any other failure keeps it. */
    private suspend fun load(store: OwnifyTokenStorage, credential: OwnifyCredential) {
        state = OwnifyState.Loading

        val profile = when (val result = api.profile(credential.token)) {
            is OwnifyResult.Success -> result.value
            is OwnifyResult.Unauthorized -> return expire(store, credential)
            is OwnifyResult.Failure -> return fail(result, credential.scope)
        }

        val targets = when (val result = api.nutritionTargets(credential.token)) {
            is OwnifyResult.Success -> result.value
            is OwnifyResult.Unauthorized -> return expire(store, credential)
            is OwnifyResult.Failure -> return fail(result, credential.scope)
        }

        state = OwnifyState.Connected(profile, targets, credential.scope)

        // Paired, signed in, or reconnected at start-up: keep the automatic sync going.
        appContext?.let { OwnifyBackgroundSync.connected(it) }
    }

    /**
     * The server no longer accepts [credential]: forget it — if it is still
     * the stored one — stop the automatic sync so it is never tried again,
     * and ask to pair or sign in again.
     */
    private suspend fun expire(store: OwnifyTokenStorage, credential: OwnifyCredential) {
        if (forget(store, credential.token) != null) {
            return ended(credential.scope)
        }

        // Already replaced or forgotten by somebody else.
        when (val current = withContext(Dispatchers.IO) { store.load() }) {
            null -> state = OwnifyState.NotConnected(expiredMessage(credential.scope))
            else -> load(store, current)
        }
    }

    /** The token is gone: no automatic sync without one, and the screen says why. */
    private fun ended(scope: OwnifyScope) {
        appContext?.let { OwnifyBackgroundSync.stop(it) }
        state = OwnifyState.NotConnected(expiredMessage(scope))
    }

    /** Stored, or false when the phone could not store it; nothing changes then. */
    private suspend fun save(store: OwnifyTokenStorage, credential: OwnifyCredential): Boolean =
        withContext(Dispatchers.IO) {
            credentialLock.withLock { runCatching { store.save(credential) }.isSuccess }
        }

    /** Forgets the stored credential if its token is [token]; returns it then, or null when it is another one or none. */
    private suspend fun forget(store: OwnifyTokenStorage, token: String): OwnifyCredential? =
        withContext(Dispatchers.IO) {
            credentialLock.withLock {
                store.load()?.takeIf { it.token == token }?.also { store.clear() }
            }
        }

    private fun expiredMessage(scope: OwnifyScope): String =
        if (scope == OwnifyScope.ACCOUNT) SESSION_EXPIRED_MESSAGE else EXPIRED_MESSAGE

    private fun fail(failure: OwnifyResult.Failure, scope: OwnifyScope) {
        state = OwnifyState.Failed(describe(failure), scope)
    }

    internal fun describe(failure: OwnifyResult.Failure): String =
        when (failure) {
            is OwnifyResult.Unauthorized ->
                EXPIRED_MESSAGE

            is OwnifyResult.HttpError ->
                "Ownify is nu niet beschikbaar (HTTP ${failure.status}). Probeer het later opnieuw."

            OwnifyResult.NetworkError ->
                "Kan Ownify niet bereiken. Controleer je internetverbinding en probeer het opnieuw."

            OwnifyResult.InvalidResponse ->
                "Ownify gaf een onverwacht antwoord. Probeer het later opnieuw."
        }

    /**
     * Why signing in or registering did not work. The server's own sentence
     * when it sent one — a wrong name or password (the same words whether
     * the name exists or not), "too many attempts, try again in N minutes",
     * an address or username that is taken — otherwise what kind of trouble.
     */
    internal fun describeSignIn(failure: OwnifyResult.Failure, unauthorized: String = "Gebruikersnaam of wachtwoord klopt niet."): String =
        when (failure) {
            is OwnifyResult.Unauthorized ->
                failure.message ?: unauthorized

            is OwnifyResult.HttpError ->
                failure.message ?: when (failure.status) {
                    429 -> "Te veel pogingen. Probeer het later opnieuw."
                    else -> "Ownify is nu niet beschikbaar (HTTP ${failure.status}). Probeer het later opnieuw."
                }

            OwnifyResult.NetworkError ->
                "Kan Ownify niet bereiken. Controleer je internetverbinding en probeer het opnieuw."

            OwnifyResult.InvalidResponse ->
                "Ownify gaf een onverwacht antwoord. Probeer het later opnieuw."
        }

    /**
     * Why signing in with Google did not work, in the server's own words when
     * it sent some: a token it could not verify, a sign-in that ran out, an
     * address a password account already has, an account that is not
     * active, Google not set up on the server, too many phones.
     */
    internal fun describeGoogle(failure: OwnifyResult.Failure): String = describeSignIn(failure, GOOGLE_FAILED_MESSAGE)

    private fun store(context: Context): OwnifyTokenStorage {
        appContext = context.applicationContext
        return tokenStore ?: storage(context.applicationContext).also { tokenStore = it }
    }

    /**
     * How this phone is listed under the paired devices on the Ownify website,
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
