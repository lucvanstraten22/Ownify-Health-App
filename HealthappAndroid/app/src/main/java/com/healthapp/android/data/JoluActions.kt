package com.healthapp.android.data

import android.content.Context
import com.healthapp.android.jolu.JoluConnection
import com.healthapp.android.jolu.JoluResult
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
 * After a change the pages are read again ([JoluAppState.reload]), as the
 * website reloads — so what shows is what the server now has, never what the
 * phone assumes it did.
 *
 * A 401 is handled as everywhere else: the credential is forgotten only when
 * it is still the token that was refused, and a token replaced meanwhile
 * gets one more try with the current one.
 */
object JoluActions {

    /** A form post, e.g. `api/goals/create.php`. [fallback] is said when the server says nothing. */
    suspend fun form(context: Context, path: String, fields: Map<String, String>, fallback: String, reload: Boolean = true): Outcome =
        run(context, fallback, reload) { token -> JoluConnection.api.form(path, fields, token) }

    /** A JSON post, for `api/goals/update.php`. */
    suspend fun json(context: Context, path: String, body: JSONObject, fallback: String, reload: Boolean = true): Outcome =
        run(context, fallback, reload) { token -> JoluConnection.api.json(path, body, token) }

    /**
     * Deleting the account (api/profile/delete.php, with the confirmation the
     * endpoint insists on). Once the server says it is gone, the phone lets go
     * of the token that went with it and shows the server's words.
     */
    suspend fun deleteAccount(context: Context, fallback: String): Outcome =
        run(context, fallback, reload = false, after = { token, body ->
            val link = body.optJSONObject("link")?.let { l ->
                val href = l.optString("href")
                if (href.startsWith("https://")) com.healthapp.android.jolu.JoluLink(href, l.optString("label")) else null
            }
            JoluAppState.clear()
            JoluConnection.accountDeleted(context.applicationContext, token, body.optString("message"), link)
        }) { token -> JoluConnection.api.form("api/profile/delete.php", mapOf("confirm" to "verwijderen"), token) }

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
        run(context, fallback, reload = true) { token -> JoluConnection.api.upload(path, field, fileName, mimeType, content, token) }

    private suspend fun run(
        context: Context,
        fallback: String,
        reload: Boolean,
        after: (suspend (token: String, body: JSONObject) -> Unit)? = null,
        call: suspend (token: String) -> JoluResult<JSONObject>
    ): Outcome {
        val app = context.applicationContext

        repeat(2) {
            val token = JoluConnection.storedToken(app) ?: return Outcome.SignedOut

            when (val result = call(token)) {
                is JoluResult.Success -> {
                    after?.invoke(token, result.value)
                    if (reload) JoluAppState.reload(app)
                    return Outcome.Done(result.value)
                }

                is JoluResult.Unauthorized -> {
                    if (JoluConnection.rejected(app, token)) {
                        JoluAppState.clear()
                        return Outcome.SignedOut
                    }
                    // Replaced meanwhile: once more with the current token.
                }

                is JoluResult.HttpError -> return Outcome.Refused(result.message ?: fallback, result.status, result.body)

                JoluResult.NetworkError -> return Outcome.Refused(JoluAppState.UNREACHABLE)

                JoluResult.InvalidResponse -> return Outcome.Refused(JoluAppState.UNEXPECTED)
            }
        }

        return Outcome.Refused(fallback)
    }
}
