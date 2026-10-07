package com.ownify.android.ui.theme

import android.app.Activity
import android.content.Context
import android.content.res.Configuration
import android.view.ContextThemeWrapper
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.core.content.edit
import com.ownify.android.R

/**
 * What Instellingen → Thema & uiterlijk is set to: the phone's own appearance
 * (Systeem, the default), or Dark or White Mode whatever the phone shows —
 * the website's `ownify_theme` cookie (lib/theme.php).
 */
enum class OwnifyThemeChoice(val key: String) {
    SYSTEM("system"),
    DARK("dark"),
    LIGHT("light");

    /** The theme to show, with the phone in Dark Mode or not. */
    fun mode(phoneDark: Boolean): OwnifyMode = when (this) {
        SYSTEM -> if (phoneDark) OwnifyMode.DARK else OwnifyMode.LIGHT
        DARK -> OwnifyMode.DARK
        LIGHT -> OwnifyMode.LIGHT
    }

    companion object {
        /** A stored or sent word; Systeem for anything else, as on the website. */
        fun of(key: String?): OwnifyThemeChoice = entries.firstOrNull { it.key == key } ?: SYSTEM
    }
}

/**
 * Systeem, Dark Mode or White Mode, kept on this phone.
 *
 * A device preference, as the website keeps it in the browser (lib/theme.php)
 * and not on the account: the opening screen — where nobody is signed in yet —
 * comes up in the theme chosen on this phone, and signing out or in changes
 * nothing about it. Read before the app's first frame (MainActivity), so the
 * app opens in the theme it should and is never drawn in the other one first;
 * written the moment it is chosen in Instellingen → Thema & uiterlijk.
 *
 * shared_prefs/ownify_theme.xml holds one word, "system", "dark" or "light";
 * nothing there means Systeem, the default. Only a choice made in Instellingen
 * is ever stored, never what the phone happened to show. On Systeem the
 * phone's appearance decides; when it changes, Android starts the activity
 * again, and [restore] reads it anew.
 */
object OwnifyThemeStore {

    private const val PREFS_NAME = "ownify_theme"
    private const val KEY_THEME = "theme"

    /** The choice, as Thema & uiterlijk ticks it: state, so its screen follows a change. */
    var choice: OwnifyThemeChoice by mutableStateOf(OwnifyThemeChoice.SYSTEM)
        private set

    /** What this phone chose; Systeem when it never chose. */
    fun preference(context: Context): OwnifyThemeChoice =
        OwnifyThemeChoice.of(prefs(context).getString(KEY_THEME, null))

    /** The theme to show now: the one chosen, or the phone's. */
    fun read(context: Context): OwnifyMode = preference(context).mode(phoneDark(context))

    /** [choice] from now on — on screen at once, and kept for the next start. */
    fun choose(context: Context, choice: OwnifyThemeChoice) {
        prefs(context).edit { putString(KEY_THEME, choice.key) }
        this.choice = choice
        Ownify.use(choice.mode(phoneDark(context)))
    }

    /**
     * In an activity's onCreate, before anything is drawn: the theme on
     * screen, and the window in it — its ground, its bars and the system's
     * own pieces (Theme.Ownify.Light when White).
     */
    fun restore(activity: Activity): OwnifyMode {
        choice = preference(activity)
        val mode = choice.mode(phoneDark(activity))
        Ownify.use(mode)
        activity.setTheme(if (mode == OwnifyMode.LIGHT) R.style.Theme_Ownify_Light else R.style.Theme_Ownify)
        return mode
    }

    /** The window theme of the theme on screen, for the system's own dialogs (the date picker). */
    fun windowTheme(): Int = if (Ownify.light) R.style.Theme_Ownify_Light else R.style.Theme_Ownify

    /** The splash of the next start: the phone's on Systeem (Theme.Ownify.System follows it), else the chosen one. */
    fun splashTheme(): Int = if (choice == OwnifyThemeChoice.SYSTEM) R.style.Theme_Ownify_System else windowTheme()

    /** [context] in [windowTheme]: a dialog of the system's own made in it is light or dark with the app. */
    fun dialogContext(context: Context): Context = ContextThemeWrapper(context, windowTheme())

    /** A phone that never chose: nothing kept, Systeem — where tests begin. */
    internal fun forget(context: Context) {
        prefs(context).edit { remove(KEY_THEME) }
        choice = OwnifyThemeChoice.SYSTEM
    }

    /** Whether the phone is in Dark Mode now. */
    private fun phoneDark(context: Context): Boolean =
        context.resources.configuration.uiMode and Configuration.UI_MODE_NIGHT_MASK == Configuration.UI_MODE_NIGHT_YES

    private fun prefs(context: Context) =
        context.applicationContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
}
