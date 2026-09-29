package com.ownify.android.data

import android.content.Context
import com.ownify.android.connection.OwnifyConnection
import com.ownify.android.connection.OwnifyResult
import org.json.JSONObject

/** How a change the person asked for went. */
sealed interface Outcome {

    /** Done; [body] is the server's answer (its `message`, a pairing code…). */
    data class Done(val body: JSONObject) : Outcome

    /**
     * Not done, for the reason the server gave — or ours when there was no
     * answer. [body] is the server's whole answer, when it sent one.
     */
    data class Refused(val message: String, val status: Int? = null, val body: JSONObject? = null) : Outcome

    /** The token no longer works: the phone has signed out, and the welcome screen says why. */
    data object SignedOut : Outcome
}

/**
 * The website's write endpoints, called with the account token the way the
 * website calls them with its session: the same forms, the same answers.
 * After a change the pages are read again ([OwnifyAppState.reload]), as the
 * website reloads — so what shows is what the server now has, never what the
 * phone assumes it did.
 *
 * A 401 is handled as everywhere else: the credential is forgotten only when
 * it is still the token that was refused, and a token replaced meanwhile
 * gets one more try with the current one.
 */
object OwnifyActions {

    /** A form post, e.g. `api/goals/create.php`. [fallback] is said when the server says nothing. */
    suspend fun form(context: Context, path: String, fields: Map<String, String>, fallback: String, reload: Boolean = true): Outcome =
        run(context, fallback, reload) { token -> OwnifyConnection.api.form(path, fields, token) }

    /** A JSON post, for `api/goals/update.php`. */
    suspend fun json(context: Context, path: String, body: JSONObject, fallback: String, reload: Boolean = true): Outcome =
        run(context, fallback, reload) { token -> OwnifyConnection.api.json(path, body, token) }

    /**
     * Deleting the account (api/profile/delete.php, with the confirmation the
     * endpoint insists on). Once the server says it is gone, the phone lets go
     * of the token that went with it and shows the server's words.
     */
    suspend fun deleteAccount(context: Context, fallback: String): Outcome =
        run(context, fallback, reload = false, after = { token, body ->
            val link = body.optJSONObject("link")?.let { l ->
                val href = l.optString("href")
                if (href.startsWith("https://")) com.ownify.android.connection.OwnifyLink(href, l.optString("label")) else null
            }
            OwnifyAppState.clear()
            OwnifyConnection.accountDeleted(context.applicationContext, token, body.optString("message"), link)
        }) { token -> OwnifyConnection.api.form("api/profile/delete.php", mapOf("confirm" to "verwijderen"), token) }

    /** The profile picture, as the website uploads it. */
    suspend fun upload(
        context: Context,
        path: String,
        field: String,
        fileName: String,
        mimeType: String,
        content: ByteArray,
        fallback: String
    ): Outcome =
        run(context, fallback, reload = true) { token -> OwnifyConnection.api.upload(path, field, fileName, mimeType, content, token) }

    private suspend fun run(
        context: Context,
        fallback: String,
        reload: Boolean,
        after: (suspend (token: String, body: JSONObject) -> Unit)? = null,
        call: suspend (token: String) -> OwnifyResult<JSONObject>
    ): Outcome {
        val app = context.applicationContext

        repeat(2) {
            val token = OwnifyConnection.storedToken(app) ?: return Outcome.SignedOut

            when (val result = call(token)) {
                is OwnifyResult.Success -> {
                    after?.invoke(token, result.value)
                    if (reload) OwnifyAppState.reload(app)
                    return Outcome.Done(result.value)
                }

                is OwnifyResult.Unauthorized -> {
                    if (OwnifyConnection.rejected(app, token)) {
                        OwnifyAppState.clear()
                        return Outcome.SignedOut
                    }
                    // Replaced meanwhile: once more with the current token.
                }

                is OwnifyResult.HttpError -> return Outcome.Refused(result.message ?: fallback, result.status, result.body)

                OwnifyResult.NetworkError -> return Outcome.Refused(OwnifyAppState.UNREACHABLE)

                OwnifyResult.InvalidResponse -> return Outcome.Refused(OwnifyAppState.UNEXPECTED)
            }
        }

        return Outcome.Refused(fallback)
    }
}
