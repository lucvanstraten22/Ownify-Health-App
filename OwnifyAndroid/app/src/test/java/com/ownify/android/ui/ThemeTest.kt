package com.ownify.android.ui

import android.content.Context
import androidx.core.view.WindowCompat
import androidx.test.core.app.ApplicationProvider
import androidx.work.Configuration
import androidx.work.testing.SynchronousExecutor
import androidx.work.testing.WorkManagerTestInitHelper
import com.ownify.android.MainActivity
import com.ownify.android.PermissionsRationaleActivity
import com.ownify.android.connection.MemoryTokenStorage
import com.ownify.android.connection.OwnifyApi
import com.ownify.android.connection.OwnifyConnection
import com.ownify.android.ui.theme.Ownify
import com.ownify.android.ui.theme.OwnifyMode
import com.ownify.android.ui.theme.OwnifyThemeStore
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.Robolectric
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/**
 * Dark or White Mode as the phone keeps it (OwnifyThemeStore): the default,
 * the choice outlasting the app, and the app's screens opening in it — the
 * theme, the window behind the first frame and the system bars' icons —
 * before anything is drawn.
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
        context.getSharedPreferences("ownify_theme", Context.MODE_PRIVATE).edit().clear().commit()
        Ownify.use(OwnifyMode.DARK)
    }

    @After
    fun tearDown() = Ownify.use(OwnifyMode.DARK)

    @Test
    fun `a phone that never chose is in Dark Mode`() {
        assertEquals(OwnifyMode.DARK, OwnifyThemeStore.read(context))
    }

    @Test
    fun `the choice shows at once and outlasts the app, both ways`() {
        OwnifyThemeStore.choose(context, OwnifyMode.LIGHT)
        assertTrue(Ownify.light)

        // The app ends: what is on screen is gone, what is stored is not.
        Ownify.use(OwnifyMode.DARK)
        assertEquals(OwnifyMode.LIGHT, OwnifyThemeStore.read(context))

        OwnifyThemeStore.choose(context, OwnifyMode.DARK)
        assertFalse(Ownify.light)
        assertEquals(OwnifyMode.DARK, OwnifyThemeStore.read(context))
    }

    @Test
    fun `the Health Connect rationale opens in the chosen theme too - its ground and its bars`() {
        OwnifyThemeStore.choose(context, OwnifyMode.LIGHT)
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
    fun `a stored word the app does not know is Dark`() {
        context.getSharedPreferences("ownify_theme", Context.MODE_PRIVATE).edit().putString("theme", "sepia").commit()
        assertEquals(OwnifyMode.DARK, OwnifyThemeStore.read(context))
    }

    @Test
    fun `chosen White, the app opens white - its window, and dark icons in the system bars`() {
        OwnifyThemeStore.choose(context, OwnifyMode.LIGHT)
        Ownify.use(OwnifyMode.DARK) // a cold start: only the stored choice is left

        val activity = Robolectric.buildActivity(MainActivity::class.java).create().get()

        assertTrue("in White Mode before the first frame", Ownify.light)
        val bars = WindowCompat.getInsetsController(activity.window, activity.window.decorView)
        assertTrue(bars.isAppearanceLightStatusBars)
        assertTrue(bars.isAppearanceLightNavigationBars)
        val window = activity.theme.obtainStyledAttributes(intArrayOf(android.R.attr.windowBackground))
        assertEquals(0xFFF0EDEE.toInt(), window.getColor(0, 0))
        window.recycle()
    }

    @Test
    fun `never chosen, the app opens dark - its window, and light icons in the system bars`() {
        val activity = Robolectric.buildActivity(MainActivity::class.java).create().get()

        assertFalse(Ownify.light)
        val bars = WindowCompat.getInsetsController(activity.window, activity.window.decorView)
        assertFalse(bars.isAppearanceLightStatusBars)
        assertFalse(bars.isAppearanceLightNavigationBars)
        val window = activity.theme.obtainStyledAttributes(intArrayOf(android.R.attr.windowBackground))
        assertEquals(0xFF252223.toInt(), window.getColor(0, 0))
        window.recycle()
    }
}
