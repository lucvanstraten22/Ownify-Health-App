package com.healthapp.android

import android.graphics.Color
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.SystemBarStyle
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import com.healthapp.android.ui.app.JoluApp
import com.healthapp.android.ui.screens.JoluScreens
import com.healthapp.android.ui.theme.JoluTheme

/**
 * JoLu. Everything on screen is the app in ui/ — the website's pages drawn
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
            JoluTheme {
                JoluApp(JoluScreens.shell, JoluScreens.signedOut)
            }
        }
    }
}
