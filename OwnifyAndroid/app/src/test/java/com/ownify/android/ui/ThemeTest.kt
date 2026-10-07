package com.ownify.android.ui

import android.app.Activity
import android.content.Context
import android.content.res.Configuration as UiConfiguration
import androidx.core.view.WindowCompat
import androidx.test.core.app.ApplicationProvider
import androidx.work.Configuration
import androidx.work.testing.SynchronousExecutor
import androidx.work.testing.WorkManagerTestInitHelper
import com.ownify.android.MainActivity
import com.ownify.android.PermissionsRationaleActivity
import com.ownify.android.R
import com.ownify.android.connection.MemoryTokenStorage
import com.ownify.android.connection.OwnifyApi
import com.ownify.android.connection.OwnifyConnection
import com.ownify.android.ui.theme.Ownify
import com.ownify.android.ui.theme.OwnifyMode
import com.ownify.android.ui.theme.OwnifyThemeChoice
import com.ownify.android.ui.theme.OwnifyThemeStore
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.Robolectric
import org.robolectric.RobolectricTestRunner
import org.robolectric.RuntimeEnvironment
import org.robolectric.android.controller.ActivityController
import org.robolectric.annotation.Config

/**
 * Systeem, Dark or White Mode as the phone keeps it (OwnifyThemeStore): a
 * phone that never chose follows its own appearance, a choice of Donker or
 * Licht stays whatever the phone does, the choice outlasts the app, and the
 * app's screens open in the theme — the window behind the first frame and the
 * system bars' icons — before anything is drawn.
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [36])
class ThemeTest {

    private lateinit var context: Context

    @Before
    fun setUp() {
        context = ApplicationProvider.getApplicationContext()
        WorkManagerTestInitHelper.initializeTestWorkManager(context, Configuration.Builder().setExecutor(SynchronousExecutor()).build())
        // Nobody signed in, and no server: the opening screen, whatever the theme.
        OwnifyConnection.storage = { MemoryTokenStorage }
        OwnifyConnection.api = OwnifyApi("http://127.0.0.1:9/")
        MemoryTokenStorage.clear()
        OwnifyConnection.reset()
        OwnifyThemeStore.forget(context)
        Ownify.use(OwnifyMode.DARK)
        phone(dark = false)
    }

    @After
    fun tearDown() {
        OwnifyThemeStore.forget(context)
        Ownify.use(OwnifyMode.DARK)
        RuntimeEnvironment.setQualifiers("notnight")
    }

    /** The phone's own appearance, as Android's dark-theme switch sets it. */
    private fun phone(dark: Boolean) = RuntimeEnvironment.setQualifiers(if (dark) "night" else "notnight")

    private fun stored(): String? = context.getSharedPreferences("ownify_theme", Context.MODE_PRIVATE).getString("theme", null)

    private fun store(word: String) =
        context.getSharedPreferences("ownify_theme", Context.MODE_PRIVATE).edit().putString("theme", word).commit()

    /** A cold start: nothing of the last run in memory, only what the phone kept. */
    private fun coldStart(): ActivityController<MainActivity> {
        Ownify.use(if (Ownify.light) OwnifyMode.DARK else OwnifyMode.LIGHT)
        return Robolectric.buildActivity(MainActivity::class.java).create()
    }

    /** The phone switches between light and dark while the app is open: the activity starts again, as Android does it. */
    private fun ActivityController<MainActivity>.phoneSwitches(dark: Boolean): MainActivity {
        phone(dark)
        val config = UiConfiguration(get().resources.configuration).apply {
            uiMode = (uiMode and UiConfiguration.UI_MODE_NIGHT_MASK.inv()) or
                (if (dark) UiConfiguration.UI_MODE_NIGHT_YES else UiConfiguration.UI_MODE_NIGHT_NO)
        }
        configurationChange(config)
        return get()
    }

    private fun windowColour(activity: Activity): Int {
        val window = activity.theme.obtainStyledAttributes(intArrayOf(android.R.attr.windowBackground))
        return window.getColor(0, 0).also { window.recycle() }
    }

    /** White: the light ground and dark icons in the bars; Dark: the dark ground and light icons. */
    private fun assertOpensIn(mode: OwnifyMode, activity: Activity) {
        assertEquals(mode, Ownify.mode)
        val bars = WindowCompat.getInsetsController(activity.window, activity.window.decorView)
        val light = mode == OwnifyMode.LIGHT
        assertEquals("status bar icons", light, bars.isAppearanceLightStatusBars)
        assertEquals("navigation bar icons", light, bars.isAppearanceLightNavigationBars)
        assertEquals("the window", if (light) 0xFFF0EDEE.toInt() else 0xFF252223.toInt(), windowColour(activity))
    }

    // ------------------------------------------------------- nothing chosen

    @Test
    fun `a phone that never chose follows its own appearance - White on a light phone, Dark on a dark one`() {
        assertEquals(OwnifyThemeChoice.SYSTEM, OwnifyThemeStore.preference(context))
        assertEquals(OwnifyMode.LIGHT, OwnifyThemeStore.read(context))
        phone(dark = true)
        assertEquals(OwnifyMode.DARK, OwnifyThemeStore.read(context))
    }

    @Test
    fun `never chosen, the app opens in the phone's appearance - and nothing is stored for it`() {
        assertOpensIn(OwnifyMode.LIGHT, coldStart().get())
        assertNull("what the phone showed is not a choice", stored())

        phone(dark = true)
        assertOpensIn(OwnifyMode.DARK, coldStart().get())
        assertNull(stored())
    }

    @Test
    fun `on Systeem the app follows the phone while it is open - light to dark and back`() {
        val app = coldStart()
        assertOpensIn(OwnifyMode.LIGHT, app.get())

        assertOpensIn(OwnifyMode.DARK, app.phoneSwitches(dark = true))
        assertOpensIn(OwnifyMode.LIGHT, app.phoneSwitches(dark = false))
        assertNull(stored())
    }

    // ------------------------------------------------------------ a choice

    @Test
    fun `Donker chosen on a light phone stays Dark - whatever the phone does after`() {
        val app = coldStart()
        OwnifyThemeStore.choose(app.get(), OwnifyThemeChoice.DARK)
        assertEquals(OwnifyMode.DARK, Ownify.mode)

        assertOpensIn(OwnifyMode.DARK, app.phoneSwitches(dark = true))
        assertOpensIn(OwnifyMode.DARK, app.phoneSwitches(dark = false))
        assertEquals("dark", stored())
    }

    @Test
    fun `Licht chosen on a dark phone stays White - whatever the phone does after`() {
        phone(dark = true)
        val app = coldStart()
        assertOpensIn(OwnifyMode.DARK, app.get())
        OwnifyThemeStore.choose(app.get(), OwnifyThemeChoice.LIGHT)
        assertEquals(OwnifyMode.LIGHT, Ownify.mode)

        assertOpensIn(OwnifyMode.LIGHT, app.phoneSwitches(dark = false))
        assertOpensIn(OwnifyMode.LIGHT, app.phoneSwitches(dark = true))
        assertEquals("light", stored())
    }

    @Test
    fun `back to Systeem, the app follows the phone again`() {
        phone(dark = true)
        val app = coldStart()
        OwnifyThemeStore.choose(app.get(), OwnifyThemeChoice.LIGHT)
        assertEquals(OwnifyMode.LIGHT, Ownify.mode)

        OwnifyThemeStore.choose(app.get(), OwnifyThemeChoice.SYSTEM)
        assertEquals("the phone is dark", OwnifyMode.DARK, Ownify.mode)
        assertEquals("system", stored())
        assertOpensIn(OwnifyMode.LIGHT, app.phoneSwitches(dark = false))
    }

    @Test
    fun `the choice outlasts the app - a cold start, and the activity made again`() {
        OwnifyThemeStore.choose(context, OwnifyThemeChoice.DARK)
        assertOpensIn(OwnifyMode.DARK, coldStart().get())
        assertEquals(OwnifyThemeChoice.DARK, OwnifyThemeStore.choice)

        val app = coldStart()
        app.recreate()
        assertOpensIn(OwnifyMode.DARK, app.get())

        phone(dark = true)
        OwnifyThemeStore.choose(context, OwnifyThemeChoice.LIGHT)
        assertOpensIn(OwnifyMode.LIGHT, coldStart().get())
        assertEquals(OwnifyThemeChoice.LIGHT, OwnifyThemeStore.choice)
    }

    @Test
    fun `the splash of the next start - the phone's on Systeem, the chosen one otherwise`() {
        coldStart()
        assertEquals(R.style.Theme_Ownify_System, OwnifyThemeStore.splashTheme())
        OwnifyThemeStore.choose(context, OwnifyThemeChoice.DARK)
        assertEquals(R.style.Theme_Ownify, OwnifyThemeStore.splashTheme())
        OwnifyThemeStore.choose(context, OwnifyThemeChoice.LIGHT)
        assertEquals(R.style.Theme_Ownify_Light, OwnifyThemeStore.splashTheme())
    }

    // ------------------------------------------- what the phone kept before

    @Test
    fun `a choice kept before Systeem existed stays a choice - Dark on a light phone, White on a dark one`() {
        store("dark")
        assertEquals(OwnifyThemeChoice.DARK, OwnifyThemeStore.preference(context))
        assertOpensIn(OwnifyMode.DARK, coldStart().get())

        store("light")
        phone(dark = true)
        assertEquals(OwnifyThemeChoice.LIGHT, OwnifyThemeStore.preference(context))
        assertOpensIn(OwnifyMode.LIGHT, coldStart().get())
    }

    @Test
    fun `a stored word the app does not know is Systeem`() {
        store("sepia")
        assertEquals(OwnifyThemeChoice.SYSTEM, OwnifyThemeStore.preference(context))
        assertEquals(OwnifyMode.LIGHT, OwnifyThemeStore.read(context))
        phone(dark = true)
        assertEquals(OwnifyMode.DARK, OwnifyThemeStore.read(context))
    }

    // ------------------------------------------------- the rationale screen

    @Test
    fun `the Health Connect rationale opens in the chosen theme too - its ground and its bars`() {
        phone(dark = true)
        OwnifyThemeStore.choose(context, OwnifyThemeChoice.LIGHT)
        Ownify.use(OwnifyMode.DARK)

        val activity = Robolectric.buildActivity(PermissionsRationaleActivity::class.java).create().get()

        assertTrue(Ownify.light)
        val theme = activity.theme.obtainStyledAttributes(
            intArrayOf(android.R.attr.windowBackground, android.R.attr.windowLightStatusBar, android.R.attr.windowLightNavigationBar)
        )
        assertEquals(0xFFF0EDEE.toInt(), theme.getColor(0, 0))
        assertTrue("dark status bar icons", theme.getBoolean(1, false))
        assertTrue("dark navigation bar icons", theme.getBoolean(2, false))
        theme.recycle()
    }

    @Test
    fun `the Health Connect rationale, never chosen, in the phone's appearance`() {
        phone(dark = true)
        Robolectric.buildActivity(PermissionsRationaleActivity::class.java).create().get()
        assertFalse(Ownify.light)
    }
}
