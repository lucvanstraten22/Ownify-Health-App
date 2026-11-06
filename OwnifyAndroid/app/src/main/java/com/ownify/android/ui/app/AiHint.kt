package com.ownify.android.ui.app

import android.content.Context
import android.content.SharedPreferences
import androidx.compose.runtime.Stable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.setValue
import kotlinx.coroutines.delay
import kotlin.random.Random

/**
 * 42.0 — the swipe-up hint (ai-sheet.js `hint`, docs/AI.md): when the grip
 * above the tab bar rises a little and settles, twice, to show the way to
 * OwnifyAI. [tick] counts the hints; the handle plays one each time it
 * changes ([AssistantHandle]).
 *
 *   never used OwnifyAI   every 6 to 8 seconds
 *   used it before        once, a few seconds after the app opens; not
 *                         again while the app runs (this process)
 *
 * "Used" is the account's: it asked OwnifyAI something (the server's
 * `used`), or opened the sheet on this phone (remembered under the
 * account's own key, so two accounts on one phone are told apart). A finger
 * that starts the swipe ends the hints until the app opens again.
 */
@Stable
class AiHint(
    private val prefs: SharedPreferences?,
    private val account: String,
    private val serverUsed: Boolean
) {
    /** One more each time the handle should play the hint. */
    var tick by mutableIntStateOf(0)
        private set

    private val usedKey get() = "opened:$account"

    /** This account has used OwnifyAI: asked it something, or opened it on this phone. */
    val everUsed: Boolean get() = serverUsed || prefs?.getBoolean(usedKey, false) == true

    /** No more hints until the app opens again. */
    val done: Boolean get() = Session.done

    /** The sheet opened: this account has used OwnifyAI. */
    fun opened() {
        prefs?.edit()?.putBoolean(usedKey, true)?.apply()
        found()
    }

    /** The swipe started: no more hints this app session. */
    fun found() {
        Session.done = true
    }

    /**
     * Plays the hints while the app is in front, at their times, as long as
     * [free] — nothing in front of the five pages, no finger on the sheet;
     * otherwise it tries again a little later.
     */
    suspend fun run(
        free: () -> Boolean,
        random: Random = Random.Default,
        wait: suspend (Long) -> Unit = { delay(it) }
    ) {
        var next = FIRST_MS
        while (!Session.done) {
            wait(next)
            if (Session.done) return
            if (!free()) { next = RETRY_MS; continue }
            tick++
            if (everUsed) { Session.done = true; return }
            next = EVERY_MS + random.nextLong(SPREAD_MS + 1)
        }
    }

    /** This app session: the process, from launch until Android ends it. */
    internal object Session {
        @Volatile var done = false
    }

    companion object {
        const val FIRST_MS = 2_500L
        const val EVERY_MS = 6_000L
        const val SPREAD_MS = 2_000L
        const val RETRY_MS = 2_000L
        /** How long the hint takes on the handle (`.ai-handle.is-hinting`, 1500ms). */
        const val SHOWN_MS = 1_500

        private const val PREFS_NAME = "ownify_ai_hint"

        fun prefs(context: Context): SharedPreferences =
            context.applicationContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
    }
}
