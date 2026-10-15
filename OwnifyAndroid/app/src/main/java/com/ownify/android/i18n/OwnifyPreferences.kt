package com.ownify.android.i18n

import android.content.Context
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.core.content.edit
import com.ownify.android.data.I18n
import org.json.JSONObject
import java.time.DayOfWeek
import java.time.LocalDate
import java.time.temporal.TemporalAdjusters

/**
 * Voorkeuren as the account keeps them (includes/i18n.php, migration 024):
 * the first day of the week, and Toegankelijkheid's three switches — larger
 * text, more contrast, no animations — each on whatever the phone says. The
 * server is where they live, so the website and the app agree; the state
 * brings them (`i18n`), and a switch's answer at once.
 *
 * Kept on this phone too (shared_prefs/ownify_preferences.xml), as the
 * language is, so the next start opens in them before the first read, and
 * the screens after signing out stay in them.
 *
 * Where they act: OwnifyApp (the text size, the contrast, the motion — next
 * to the phone's own settings, never in their place) and the goal's day
 * calendar ([weekFirstDay], [weekInitials]).
 */
object OwnifyPreferences {

    private const val PREFS_NAME = "ownify_preferences"
    private const val KEY_WEEK = "week_start"
    private const val KEY_TEXT = "text_large"
    private const val KEY_CONTRAST = "contrast_more"
    private const val KEY_MOTION = "motion_off"

    /** How much larger Grotere tekst makes all text: the website's `font-size: 112.5%`. */
    const val LARGE_TEXT = 1.125f

    /** "monday" or "sunday". State, so every screen follows a change. */
    var weekStart: String by mutableStateOf("monday")
        private set

    var textLarge: Boolean by mutableStateOf(false)
        private set

    var contrastMore: Boolean by mutableStateOf(false)
        private set

    var motionOff: Boolean by mutableStateOf(false)
        private set

    val sundayFirst: Boolean get() = weekStart == "sunday"

    /** Before the first frame (MainActivity): what this phone kept. */
    fun restore(context: Context) {
        val prefs = prefs(context)
        weekStart = if (prefs.getString(KEY_WEEK, null) == "sunday") "sunday" else "monday"
        textLarge = prefs.getBoolean(KEY_TEXT, false)
        contrastMore = prefs.getBoolean(KEY_CONTRAST, false)
        motionOff = prefs.getBoolean(KEY_MOTION, false)
    }

    /** What the server says for the signed-in account (the state's `i18n`): in place at once, and kept. */
    fun adopt(context: Context, i18n: I18n) {
        weekStart = if (i18n.weekStart == "sunday") "sunday" else "monday"
        textLarge = i18n.textLarge
        contrastMore = i18n.contrastMore
        motionOff = i18n.motionOff
        keep(context)
    }

    /**
     * A Voorkeuren switch's answer (api/profile/preferences.php): the whole
     * app follows at once, before the pages are read again. Only the keys it
     * carries change.
     */
    fun answer(context: Context, body: JSONObject) {
        if (body.has("week")) weekStart = if (body.optString("week") == "sunday") "sunday" else "monday"
        if (body.has(KEY_TEXT)) textLarge = body.optBoolean(KEY_TEXT)
        if (body.has(KEY_CONTRAST)) contrastMore = body.optBoolean(KEY_CONTRAST)
        if (body.has(KEY_MOTION)) motionOff = body.optBoolean(KEY_MOTION)
        keep(context)
    }

    /** Whether the Toegankelijkheid switch [key] is on (`text_large`, `contrast_more`, `motion_off`). */
    fun on(key: String?): Boolean = when (key) {
        KEY_TEXT -> textLarge
        KEY_CONTRAST -> contrastMore
        KEY_MOTION -> motionOff
        else -> false
    }

    /** For a test: the choices outright. */
    fun use(weekStart: String = "monday", textLarge: Boolean = false, contrastMore: Boolean = false, motionOff: Boolean = false) {
        this.weekStart = if (weekStart == "sunday") "sunday" else "monday"
        this.textLarge = textLarge
        this.contrastMore = contrastMore
        this.motionOff = motionOff
    }

    /** The first day of the week [day] is in: its Monday, or its Sunday (includes/i18n.php week_first_day). */
    fun weekFirstDay(day: LocalDate): LocalDate =
        day.with(TemporalAdjusters.previousOrSame(if (sundayFirst) DayOfWeek.SUNDAY else DayOfWeek.MONDAY))

    /** The weekday initials of a week, in its order (includes/i18n.php week_initials). */
    fun weekInitials(): List<String> {
        val monday = if (OwnifyLanguage.english) listOf("M", "T", "W", "T", "F", "S", "S") else listOf("M", "D", "W", "D", "V", "Z", "Z")
        return if (sundayFirst) listOf(monday[6]) + monday.dropLast(1) else monday
    }

    private fun keep(context: Context) {
        prefs(context).edit {
            putString(KEY_WEEK, weekStart)
            putBoolean(KEY_TEXT, textLarge)
            putBoolean(KEY_CONTRAST, contrastMore)
            putBoolean(KEY_MOTION, motionOff)
        }
    }

    private fun prefs(context: Context) =
        context.applicationContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
}
