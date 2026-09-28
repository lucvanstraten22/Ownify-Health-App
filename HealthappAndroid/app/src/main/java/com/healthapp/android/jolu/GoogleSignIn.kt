package com.healthapp.android.jolu

import android.content.Context
import androidx.credentials.ClearCredentialStateRequest
import androidx.credentials.CredentialManager
import androidx.credentials.CustomCredential
import androidx.credentials.GetCredentialRequest
import androidx.credentials.exceptions.GetCredentialCancellationException
import androidx.credentials.exceptions.GetCredentialException
import androidx.credentials.exceptions.GetCredentialInterruptedException
import androidx.credentials.exceptions.GetCredentialProviderConfigurationException
import androidx.credentials.exceptions.GetCredentialUnsupportedException
import androidx.credentials.exceptions.NoCredentialException
import com.google.android.libraries.identity.googleid.GetSignInWithGoogleOption
import com.google.android.libraries.identity.googleid.GoogleIdTokenCredential
import com.google.android.libraries.identity.googleid.GoogleIdTokenParsingException
import kotlin.coroutines.cancellation.CancellationException

/**
 * What Google's sign-in on the phone gave back.
 *
 * Only [Token] goes anywhere: to the JoLu server, which verifies it
 * (api/auth/app-google.php) before anything in it is believed. The app reads
 * nothing out of it and keeps nothing of it.
 */
sealed interface GoogleIdResult {

    /** An ID token for the JoLu server, for its Web client and with its nonce. */
    class Token(val idToken: String) : GoogleIdResult {
        /** Never the token. */
        override fun toString(): String = "Token(…)"
    }

    /** The person closed Google's account chooser. Nothing to say about it. */
    data object Cancelled : GoogleIdResult

    /**
     * Google had no account to offer: none on the phone. (During set-up this is
     * also what a missing or wrong Android OAuth client looks like — see
     * docs/APP-AUTH.md.)
     */
    data object NoAccount : GoogleIdResult

    /** This phone cannot do it: no Google Play services, or too old for Credential Manager. */
    data object Unavailable : GoogleIdResult

    /** Interrupted — the app went to the background, another sign-in started. Worth trying again. */
    data object Interrupted : GoogleIdResult

    /** Anything else Credential Manager or Google refused. */
    data object Failed : GoogleIdResult
}

/** Google's side of signing in. The app uses [CredentialManagerGoogle]; tests stand in for Google here. */
interface GoogleIdTokens {

    /**
     * Shows Google's account chooser and asks for an ID token for
     * [serverClientId] — the JoLu server's Web client, the token's audience —
     * with [nonce], which the server handed out for this sign-in and checks
     * in the token. [activity] shows the chooser: an activity, not the app.
     */
    suspend fun request(activity: Context, serverClientId: String, nonce: String): GoogleIdResult

    /**
     * Signed out of JoLu: Credential Manager is told, so it does not pick a
     * Google account on its own next time (Google's own advice). Never fails.
     */
    suspend fun signedOut(context: Context)
}

/**
 * The real one: Android Credential Manager with Google's "Sign in with
 * Google" option — the flow for a Sign in with Google button, with Google's
 * own account chooser (every account on the phone, and adding one).
 *
 * No Google token is kept: the ID token goes to the JoLu server once and is
 * dropped, and staying signed in is JoLu's own account token
 * (JoluTokenStore), so reopening the app never asks Google again.
 */
object CredentialManagerGoogle : GoogleIdTokens {

    /** The credential types a Google ID token comes back as: the chooser's, and Credential Manager's bottom sheet's. */
    private val googleIdTypes = setOf(
        GoogleIdTokenCredential.TYPE_GOOGLE_ID_TOKEN_SIWG_CREDENTIAL,
        GoogleIdTokenCredential.TYPE_GOOGLE_ID_TOKEN_CREDENTIAL
    )

    override suspend fun request(activity: Context, serverClientId: String, nonce: String): GoogleIdResult {
        val option = GetSignInWithGoogleOption.Builder(serverClientId)
            .setNonce(nonce)
            .build()
        val request = GetCredentialRequest.Builder()
            .addCredentialOption(option)
            .build()

        return try {
            val credential = CredentialManager.create(activity).getCredential(activity, request).credential

            if (credential is CustomCredential && credential.type in googleIdTypes) {
                GoogleIdResult.Token(GoogleIdTokenCredential.createFrom(credential.data).idToken)
            } else {
                GoogleIdResult.Failed
            }
        } catch (e: GetCredentialCancellationException) {
            GoogleIdResult.Cancelled
        } catch (e: NoCredentialException) {
            GoogleIdResult.NoAccount
        } catch (e: GetCredentialProviderConfigurationException) {
            GoogleIdResult.Unavailable
        } catch (e: GetCredentialUnsupportedException) {
            GoogleIdResult.Unavailable
        } catch (e: GetCredentialInterruptedException) {
            GoogleIdResult.Interrupted
        } catch (e: GetCredentialException) {
            GoogleIdResult.Failed
        } catch (e: GoogleIdTokenParsingException) {
            GoogleIdResult.Failed
        }
    }

    override suspend fun signedOut(context: Context) {
        try {
            CredentialManager.create(context.applicationContext).clearCredentialState(ClearCredentialStateRequest())
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            // Nothing to clear, or no provider on this phone: signing out is done either way.
        }
    }
}
