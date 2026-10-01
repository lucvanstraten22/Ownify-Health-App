package com.ownify.android

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.LocalContentColor
import androidx.compose.material3.Text
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import com.ownify.android.ui.theme.Ownify
import com.ownify.android.ui.theme.OwnifyThemeStore

/**
 * What Ownify reads from Health Connect, shown when Health Connect asks for
 * it. In the theme this phone chose (OwnifyThemeStore), on that theme's
 * ground, in its text colour.
 */
class PermissionsRationaleActivity : ComponentActivity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        OwnifyThemeStore.restore(this)
        super.onCreate(savedInstanceState)

        setContent {
            CompositionLocalProvider(LocalContentColor provides Ownify.TextPrimary) {
                Column(
                    modifier = Modifier.fillMaxSize(),
                    horizontalAlignment = Alignment.CenterHorizontally,
                    verticalArrangement = Arrangement.Center
                ) {
                    Text(
                        text = "Ownify reads your steps, distance, active calories, heart rate, sleep, " +
                            "exercise and nutrition from Health Connect and sends them to your own " +
                            "Ownify account. With background access it also does this about once an " +
                            "hour while the app is closed. Ownify only reads: it never changes or " +
                            "deletes anything in Health Connect."
                    )
                }
            }
        }
    }
}
