package com.ownify.android

import android.graphics.Color
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.SystemBarStyle
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import com.ownify.android.ui.app.OwnifyApp
import com.ownify.android.ui.screens.OwnifyScreens
import com.ownify.android.ui.theme.OwnifyTheme

/**
 * Ownify. Everything on screen is the app in ui/ — the website's pages drawn
 * natively from what the server sends (api/app/state.php); nothing is worked
 * out here. The window runs edge to edge under light system-bar icons, as
 * the website does in a phone's browser with `viewport-fit=cover`.
 */
class MainActivity : ComponentActivity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        enableEdgeToEdge(
            statusBarStyle = SystemBarStyle.dark(Color.TRANSPARENT),
            navigationBarStyle = SystemBarStyle.dark(Color.TRANSPARENT)
        )
        super.onCreate(savedInstanceState)

        setContent {
            OwnifyTheme {
                OwnifyApp(OwnifyScreens.shell, OwnifyScreens.signedOut)
            }
        }
    }
}
