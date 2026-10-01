package com.ownify.android.ui.theme

import android.app.Activity
import android.content.Context
import android.view.ContextThemeWrapper
import androidx.core.content.edit
import com.ownify.android.R

/**
 * Dark Mode or White Mode, kept on this phone.
 *
 * A device preference, as the website keeps it in the browser (lib/theme.php)
 * and not on the account: the opening screen — where nobody is signed in yet —
 * comes up in the theme chosen on this phone, and signing out or in changes
 * nothing about it. Read before the app's first frame (MainActivity), so the
 * app opens in the theme it was left in and is never drawn in the other one
 * first; written the moment it is chosen in Instellingen → Thema & uiterlijk.
 *
 * shared_prefs/ownify_theme.xml holds one word, "light" or "dark"; nothing
 * there means Dark, the default.
 */
object OwnifyThemeStore {

    private const val PREFS_NAME = "ownify_theme"
    private const val KEY_THEME = "theme"

    /** The theme this phone chose; Dark when it never chose. */
    fun read(context: Context): OwnifyMode =
        OwnifyMode.of(prefs(context).getString(KEY_THEME, null))

    /** [mode] on screen from now on, and kept for the next start. */
    fun choose(context: Context, mode: OwnifyMode) {
        Ownify.use(mode)
        prefs(context).edit { putString(KEY_THEME, mode.key) }
    }

    /**
     * In an activity's onCreate, before anything is drawn: the theme this
     * phone chose on screen, and the window in it — its ground, its bars and
     * the system's own pieces (Theme.Ownify.Light when White).
     */
    fun restore(activity: Activity): OwnifyMode {
        val mode = read(activity)
        Ownify.use(mode)
        if (mode == OwnifyMode.LIGHT) activity.setTheme(R.style.Theme_Ownify_Light)
        return mode
    }

    /** The window theme of the theme on screen, for the system's own dialogs (the date picker). */
    fun windowTheme(): Int = if (Ownify.light) R.style.Theme_Ownify_Light else R.style.Theme_Ownify

    /** [context] in [windowTheme]: a dialog of the system's own made in it is light or dark with the app. */
    fun dialogContext(context: Context): Context = ContextThemeWrapper(context, windowTheme())

    private fun prefs(context: Context) =
        context.applicationContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
}
