package com.ownify.android

import android.graphics.Color
import android.os.Build
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.SystemBarStyle
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.ui.graphics.toArgb
import androidx.core.graphics.drawable.toDrawable
import com.ownify.android.ui.app.OwnifyApp
import com.ownify.android.ui.screens.OwnifyScreens
import com.ownify.android.ui.theme.Ownify
import com.ownify.android.ui.theme.OwnifyMode
import com.ownify.android.ui.theme.OwnifyTheme
import com.ownify.android.ui.theme.OwnifyThemeStore

/**
 * Ownify. Everything on screen is the app in ui/ — the website's pages drawn
 * natively from what the server sends (api/app/state.php); nothing is worked
 * out here. The window runs edge to edge, as the website does in a phone's
 * browser with `viewport-fit=cover`: under light system-bar icons in Dark
 * Mode, dark ones in White Mode.
 *
 * The theme this phone chose — or on Systeem the phone's own (OwnifyThemeStore)
 * — is put in place before anything is drawn, so the app opens in it and never
 * in the other one first. On Systeem a change of the phone's appearance starts
 * the activity again, and it comes back in the new one.
 */
class MainActivity : ComponentActivity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        systemBars(OwnifyThemeStore.restore(this))
        super.onCreate(savedInstanceState)

        setContent {
            // Chosen in Instellingen: the bars, the window and the next
            // start's splash follow at once.
            val now = Ownify.mode
            val choice = OwnifyThemeStore.choice
            LaunchedEffect(now, choice) { follow(now) }

            OwnifyTheme {
                OwnifyApp(OwnifyScreens.shell, OwnifyScreens.signedOut)
            }
        }
    }

    private fun systemBars(mode: OwnifyMode) {
        val style = if (mode == OwnifyMode.LIGHT) {
            SystemBarStyle.light(Color.TRANSPARENT, Color.TRANSPARENT)
        } else {
            SystemBarStyle.dark(Color.TRANSPARENT)
        }
        enableEdgeToEdge(statusBarStyle = style, navigationBarStyle = style)
    }

    private fun follow(mode: OwnifyMode) {
        systemBars(mode)
        // What shows behind the app while the keyboard moves it, or before a frame.
        window.setBackgroundDrawable(Ownify.BgDeep.toArgb().toDrawable())
        // The system's splash at the next start (Android 13 and later): in the
        // theme the app will open in, not the one it was installed with — on
        // Systeem the phone's, whichever it is by then.
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            runCatching { splashScreen.setSplashScreenTheme(OwnifyThemeStore.splashTheme()) }
        }
    }
}
