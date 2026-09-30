package com.ownify.android.data

import android.content.Context
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import kotlinx.coroutines.CoroutineExceptionHandler
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import org.json.JSONObject

/** One run of an answer's text, bold or not (includes/ai/format.php). */
data class AiSpan(val text: String, val bold: Boolean)

/**
 * An answer, in the blocks the server makes of it — the same ones the
 * website draws. Text and bold only: nothing the model writes is rendered as
 * markup.
 */
sealed interface AiBlock {
    data class Paragraph(val spans: List<AiSpan>) : AiBlock
    data class Heading(val spans: List<AiSpan>) : AiBlock
    data class Bullets(val items: List<List<AiSpan>>) : AiBlock
    data class Steps(val items: List<List<AiSpan>>) : AiBlock

    companion object {
        private fun spans(array: org.json.JSONArray?): List<AiSpan> =
            array.map { AiSpan(it.str("t").orEmpty(), it.bool("b")) }

        private fun items(array: org.json.JSONArray?): List<List<AiSpan>> {
            if (array == null) return emptyList()
            return (0 until array.length()).mapNotNull { i -> array.optJSONArray(i)?.let(::spans) }
        }

        fun parse(o: JSONObject): AiBlock? = when (o.str("type")) {
            "p" -> Paragraph(spans(o.arr("spans")))
            "h" -> Heading(spans(o.arr("spans")))
            "ul" -> Bullets(items(o.arr("items")))
            "ol" -> Steps(items(o.arr("items")))
            else -> null
        }
    }
}

/** A change the assistant prepared: waiting ([pending]) for a yes, or answered ([status]). */
data class AiAction(
    val title: String,
    val summary: String,
    val state: String,
    val confirm: String,
    val decline: String,
    val status: String?
) {
    val pending: Boolean get() = state == "pending"

    companion object {
        fun parse(o: JSONObject?): AiAction? = o?.let {
            AiAction(
                it.str("title").orEmpty(), it.str("summary").orEmpty(), it.str("state") ?: "expired",
                it.str("confirm").orEmpty(), it.str("decline").orEmpty(), it.str("status")
            )
        }
    }
}

/** One message: the person's ("user"), the assistant's, or a note from Ownify ("system"). */
data class AiMessage(
    val id: Long,
    val role: String,
    val text: String,
    val blocks: List<AiBlock>,
    val action: AiAction?
) {
    companion object {
        fun parse(o: JSONObject): AiMessage? {
            val role = o.str("role") ?: return null
            if (role !in setOf("user", "assistant", "system")) return null
            return AiMessage(
                id = o.optLong("id", 0L),
                role = role,
                text = o.str("text").orEmpty(),
                blocks = o.arr("blocks").map(AiBlock::parse),
                action = AiAction.parse(o.obj("action"))
            )
        }
    }
}

data class AiConversation(val id: Long, val title: String, val label: String) {
    companion object {
        fun parse(o: JSONObject?): AiConversation? {
            val id = o?.optLong("id", 0L) ?: 0L
            if (id <= 0L) return null
            return AiConversation(id, o.str("title").orEmpty(), o.str("label").orEmpty())
        }
    }
}

/**
 * Why the assistant cannot go on, in the server's words; with [retry] the
 * question to send again, or [reload] to read the conversation again.
 */
data class AiNotice(val text: String, val retry: String? = null, val reload: Boolean = false)

/** Which of the sheet's three screens is showing. */
enum class AiView { Consent, Declined, Chat }

/**
 * Ownify AI in the app: the same backend as the website (the api/ai endpoints), its
 * state kept here rather than in the sheet, which is not composed while it
 * is closed — so a question on its way is not lost by pulling the sheet
 * down, and reopening it shows the conversation as it was.
 *
 * Every answer is the server's: the conversation, the day's count, whether
 * the assistant can answer, and every sentence about why not. Nothing is
 * written to the phone, and [clear] forgets it all when the account signs out.
 */
object OwnifyAssistant {

    /** The answer to the consent question as this holder last heard it; null: as the pages say. */
    var consent: String? by mutableStateOf(null)
        private set

    /** Said no, and looking at the question again ("Toestemming bekijken"). */
    var reviewing by mutableStateOf(false)

    var available by mutableStateOf<Boolean?>(null)
        private set
    var usage by mutableStateOf<AiUsage?>(null)
        private set
    var hasData by mutableStateOf<Boolean?>(null)
        private set

    var conversation by mutableStateOf<AiConversation?>(null)
        private set
    var conversations by mutableStateOf<List<AiConversation>>(emptyList())
        private set
    var messages by mutableStateOf<List<AiMessage>>(emptyList())
        private set

    /** Read from the server since the sheet last needed it. */
    var loaded by mutableStateOf(false)
        private set
    var loading by mutableStateOf(false)
        private set

    /** The question on its way, shown until the answer comes. */
    var sending by mutableStateOf<String?>(null)
        private set

    var notice by mutableStateOf<AiNotice?>(null)
        private set

    /** What is typed, kept while the sheet is closed. */
    var draft by mutableStateOf("")

    var consentBusy by mutableStateOf(false)
        private set
    var consentError by mutableStateOf<String?>(null)
        private set

    /** The proposal being answered (its message id), while the server carries it out. */
    var answering by mutableStateOf<Long?>(null)
        private set

    var historyOpen by mutableStateOf(false)

    /** A new conversation's title is not in [conversations] yet. */
    private var historyStale = false

    private val scope = CoroutineScope(
        SupervisorJob() + Dispatchers.Main.immediate + CoroutineExceptionHandler { _, _ ->
            // Never a crash over the assistant, and nothing logged: it could carry health data.
            loading = false
            sending = null
            consentBusy = false
            answering = null
        }
    )

    /** The screen for this moment: the pages' answer ([session]) unless this holder heard a newer one. */
    fun view(session: AiSession): AiView = when (consent ?: session.consent) {
        "accepted" -> AiView.Chat
        "declined" -> if (reviewing) AiView.Consent else AiView.Declined
        else -> AiView.Consent
    }

    /** Today's count as best known: the server's last answer, or the pages'. */
    fun usage(session: AiSession): AiUsage = usage ?: session.usage

    /**
     * The pages were read again with an answer this holder did not give
     * (Instellingen → Privacy): follow it, and read the conversation again
     * the next time the sheet needs it.
     */
    fun follow(session: AiSession) {
        val known = consent ?: return
        if (known != session.consent) {
            consent = session.consent
            reviewing = false
            loaded = false
            historyOpen = false
        }
    }

    /* ------------------------------------------------------------- reading */

    /** The conversation asked for, the last one used, or — [fresh] — none. */
    fun load(context: Context, copy: AiCopy, conversationId: Long? = null, fresh: Boolean = false) {
        if (loading) return
        loading = true

        val fields = buildMap {
            if (conversationId != null) put("conversation_id", conversationId.toString())
            if (fresh) put("new", "1")
        }

        scope.launch {
            val outcome = OwnifyActions.assistant(context, "api/ai/state.php", fields, copy.error("unavailable"))
            loading = false

            when (outcome) {
                is Outcome.Done -> apply(outcome.body, copy)
                is Outcome.Refused -> failed(outcome, copy, reload = true)
                Outcome.SignedOut -> clear()
            }
        }
    }

    private fun apply(body: JSONObject, copy: AiCopy) {
        // Looking at the question again stays open unless the answer itself changed.
        val incoming = body.str("consent") ?: consent
        if (incoming != consent) reviewing = false
        consent = incoming
        available = body.optBoolean("available", true)
        usage = AiUsage.parse(body.obj("usage"))
        hasData = body.bool("has_data")
        conversations = body.arr("conversations").map(AiConversation::parse)
        conversation = AiConversation.parse(body.obj("conversation"))
        messages = body.arr("messages").map(AiMessage::parse)
        loaded = true
        historyStale = false

        notice = when {
            available == false -> AiNotice(body.str("notice") ?: copy.error("unavailable"))
            usage?.out == true -> AiNotice(copy.error("limit"))
            else -> null
        }
    }

    /* --------------------------------------------------------------- asking */

    fun ask(context: Context, copy: AiCopy, question: String) {
        val text = question.trim()
        if (text.isEmpty() || sending != null) return

        sending = text
        notice = null
        draft = ""

        val fields = buildMap {
            put("message", text)
            conversation?.let { put("conversation_id", it.id.toString()) }
        }

        scope.launch {
            val outcome = OwnifyActions.assistant(context, "api/ai/chat.php", fields, copy.error("unavailable"))
            sending = null

            when (outcome) {
                is Outcome.Done -> {
                    val wasNew = conversation == null
                    val incoming = outcome.body.arr("messages").map(AiMessage::parse)
                    conversation = AiConversation.parse(outcome.body.obj("conversation")) ?: conversation
                    merge(incoming)
                    outcome.body.obj("usage")?.let { usage = AiUsage.parse(it) }
                    if (wasNew) historyStale = true
                    notice = if (usage?.out == true) AiNotice(copy.error("limit")) else null
                    // A plain "ja" carries a proposal out here too.
                    reloadIfChanged(context, incoming)
                }

                is Outcome.Refused -> {
                    // Not answered, not stored: the question goes back in the field.
                    if (draft.isEmpty()) draft = text
                    failed(outcome, copy, retry = text)
                }

                Outcome.SignedOut -> clear()
            }
        }
    }

    /** New messages in, and ones already shown (a proposal just answered) redrawn in place. */
    private fun merge(incoming: List<AiMessage>) {
        if (incoming.isEmpty()) return
        val byId = incoming.filter { it.id > 0 }.associateBy { it.id }
        val kept = messages.map { byId[it.id] ?: it }
        val known = kept.map { it.id }.toSet()
        messages = kept + incoming.filter { it.id <= 0 || it.id !in known }
    }

    /** A goal the assistant just added or changed shows on Doelen straight away. */
    private suspend fun reloadIfChanged(context: Context, incoming: List<AiMessage>) {
        if (incoming.any { it.action?.state == "done" }) OwnifyAppState.reload(context)
    }

    /* ------------------------------------------------------------ proposals */

    fun resolve(context: Context, copy: AiCopy, messageId: Long, confirm: Boolean) {
        if (answering != null) return
        answering = messageId
        notice = null

        scope.launch {
            val outcome = OwnifyActions.assistant(
                context, "api/ai/action.php",
                mapOf("message_id" to messageId.toString(), "decision" to if (confirm) "confirm" else "decline"),
                copy.error("unavailable")
            )
            answering = null

            when (outcome) {
                is Outcome.Done -> {
                    val updated = outcome.body.arr("messages").map(AiMessage::parse)
                    merge(updated)
                    reloadIfChanged(context, updated)
                }

                is Outcome.Refused -> {
                    merge(outcome.body.arr("messages").map(AiMessage::parse))
                    failed(outcome, copy)
                }

                Outcome.SignedOut -> clear()
            }
        }
    }

    /* -------------------------------------------------------------- consent */

    fun decide(context: Context, copy: AiCopy, accept: Boolean) {
        if (consentBusy) return
        consentBusy = true
        consentError = null

        scope.launch {
            val outcome = OwnifyActions.assistant(
                context, "api/ai/consent.php",
                mapOf("decision" to if (accept) "accept" else "decline"),
                copy.error("unavailable")
            )
            consentBusy = false

            when (outcome) {
                is Outcome.Done -> {
                    consent = outcome.body.str("consent") ?: if (accept) "accepted" else "declined"
                    reviewing = false
                    // As on the website: a yes reads the conversation, a no reads nothing.
                    if (consent == "accepted") {
                        loaded = false
                        load(context, copy)
                    }
                    // Instellingen → Privacy shows the same switch.
                    OwnifyAppState.reload(context)
                }

                is Outcome.Refused -> consentError = outcome.message
                Outcome.SignedOut -> clear()
            }
        }
    }

    /* -------------------------------------------------------- conversations */

    /** A new, empty conversation: the next question starts it. */
    fun startNew(copy: AiCopy) {
        historyOpen = false
        conversation = null
        messages = emptyList()
        notice = when {
            available == false -> AiNotice(copy.error("unavailable"))
            usage?.out == true -> AiNotice(copy.error("limit"))
            else -> null
        }
    }

    fun openHistory(context: Context, copy: AiCopy) {
        historyOpen = true
        if (!historyStale) return
        historyStale = false

        scope.launch {
            val fields = conversation?.let { mapOf("conversation_id" to it.id.toString()) } ?: mapOf("new" to "1")
            val outcome = OwnifyActions.assistant(context, "api/ai/state.php", fields, copy.error("unavailable"))
            if (outcome is Outcome.Done) {
                conversations = outcome.body.arr("conversations").map(AiConversation::parse)
            }
        }
    }

    fun open(context: Context, copy: AiCopy, id: Long) {
        historyOpen = false
        load(context, copy, conversationId = id)
    }

    fun delete(context: Context, copy: AiCopy, id: Long) {
        scope.launch {
            val outcome = OwnifyActions.assistant(context, "api/ai/delete.php", mapOf("conversation_id" to id.toString()), copy.error("unavailable"))

            when (outcome) {
                is Outcome.Done -> {
                    conversations = conversations.filter { it.id != id }
                    if (conversation?.id == id) {
                        conversation = null
                        messages = emptyList()
                    }
                }

                is Outcome.Refused -> failed(outcome, copy)
                Outcome.SignedOut -> clear()
            }
        }
    }

    /** Every conversation wiped in Instellingen → Privacy: none left here either. */
    fun cleared() {
        conversation = null
        conversations = emptyList()
        messages = emptyList()
        historyOpen = false
    }

    /* -------------------------------------------------------------- failure */

    /**
     * What to show when something did not work: the server's sentence, or
     * ours when there was no answer at all. A few mean more than a notice.
     */
    private fun failed(outcome: Outcome.Refused, copy: AiCopy, retry: String? = null, reload: Boolean = false) {
        val code = outcome.body.str("code")
        outcome.body.obj("usage")?.let { usage = AiUsage.parse(it) }

        when (code) {
            "consent" -> {
                consent = "unknown"
                reviewing = false
                notice = null
            }

            "not_found" -> {
                conversation = null
                messages = emptyList()
                notice = AiNotice(outcome.message)
            }

            "limit" -> notice = AiNotice(outcome.message)

            else -> {
                val again = outcome.status == null || code in setOf("timeout", "unavailable", "quota") || code == null
                notice = AiNotice(
                    // No answer at all: our sentence; otherwise the server's.
                    if (outcome.status == null) copy.errors["network"] ?: outcome.message else outcome.message,
                    retry = if (again) retry else null,
                    reload = again && reload
                )
            }
        }
    }

    /** For tests and screenshots: an api/ai/state.php answer, as if it had just been read. */
    internal fun show(body: JSONObject, copy: AiCopy) = apply(body, copy)

    /** Signed out, or another account: nothing of this one stays in memory. */
    fun clear() {
        consent = null
        reviewing = false
        available = null
        usage = null
        hasData = null
        conversation = null
        conversations = emptyList()
        messages = emptyList()
        loaded = false
        loading = false
        sending = null
        notice = null
        draft = ""
        consentBusy = false
        consentError = null
        answering = null
        historyOpen = false
        historyStale = false
    }
}
