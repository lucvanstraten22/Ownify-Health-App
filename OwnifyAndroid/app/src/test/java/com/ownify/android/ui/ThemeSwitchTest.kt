package com.ownify.android.ui

import android.content.Context
import android.graphics.drawable.ColorDrawable
import androidx.core.view.WindowCompat
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.test.core.app.ApplicationProvider
import androidx.work.Configuration
import androidx.work.testing.SynchronousExecutor
import androidx.work.testing.WorkManagerTestInitHelper
import com.ownify.android.MainActivity
import com.ownify.android.connection.MemoryTokenStorage
import com.ownify.android.connection.OwnifyApi
import com.ownify.android.connection.OwnifyConnection
import com.ownify.android.ui.theme.Ownify
import com.ownify.android.ui.theme.OwnifyMode
import com.ownify.android.ui.theme.OwnifyThemeStore
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.rules.ExternalResource
import org.junit.rules.RuleChain
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/**
 * Switched in Instellingen while the app is open: the system bars' icons
 * and the window behind the app follow at once, both ways — as the website
 * swaps its theme-color and color-scheme in place.
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [36])
class ThemeSwitchTest {

    private val compose = createAndroidComposeRule<MainActivity>()

    /** A signed-out phone that never chose, set up before MainActivity starts. */
    private val phone = object : ExternalResource() {
        override fun before() {
            val context = ApplicationProvider.getApplicationContext<Context>()
            WorkManagerTestInitHelper.initializeTestWorkManager(context, Configuration.Builder().setExecutor(SynchronousExecutor()).build())
            OwnifyConnection.storage = { MemoryTokenStorage }
            OwnifyConnection.api = OwnifyApi("http://127.0.0.1:9/")
            MemoryTokenStorage.clear()
            OwnifyConnection.reset()
            context.getSharedPreferences("ownify_theme", Context.MODE_PRIVATE).edit().clear().commit()
            Ownify.use(OwnifyMode.DARK)
        }

        override fun after() = Ownify.use(OwnifyMode.DARK)
    }

    @get:Rule
    val rules: RuleChain = RuleChain.outerRule(phone).around(compose)

    private fun lightBars(): Pair<Boolean, Boolean> {
        val window = compose.activity.window
        val bars = WindowCompat.getInsetsController(window, window.decorView)
        return bars.isAppearanceLightStatusBars to bars.isAppearanceLightNavigationBars
    }

    private fun windowColour(): Int = (compose.activity.window.decorView.background as ColorDrawable).color

    @Test
    fun `switched while open, the system bars and the window follow - and back`() {
        compose.waitForIdle()
        assertEquals(false to false, lightBars())

        compose.runOnUiThread { OwnifyThemeStore.choose(compose.activity, OwnifyMode.LIGHT) }
        compose.waitForIdle()
        assertTrue(Ownify.light)
        assertEquals("dark icons over the light ground", true to true, lightBars())
        assertEquals(0xFFF0EDEE.toInt(), windowColour())

        compose.runOnUiThread { OwnifyThemeStore.choose(compose.activity, OwnifyMode.DARK) }
        compose.waitForIdle()
        assertFalse(Ownify.light)
        assertEquals("light icons over the dark ground", false to false, lightBars())
        assertEquals(0xFF252223.toInt(), windowColour())
    }
}
