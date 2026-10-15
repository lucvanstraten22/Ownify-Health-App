package com.ownify.android

import com.ownify.android.ui.theme.AccountTextSize
import com.ownify.android.i18n.OwnifyPreferences
import android.os.Bundle
import com.ownify.android.i18n.OwnifyLanguage
import com.ownify.android.i18n.tr
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
        OwnifyPreferences.restore(this)
        OwnifyThemeStore.restore(this)
        // Opened on its own by Health Connect: the language kept on this phone, else the phone's.
        OwnifyLanguage.restore(this)
        super.onCreate(savedInstanceState)

        setContent {
            AccountTextSize {
                CompositionLocalProvider(LocalContentColor provides Ownify.TextPrimary) {
                    Column(
                        modifier = Modifier.fillMaxSize(),
                        horizontalAlignment = Alignment.CenterHorizontally,
                        verticalArrangement = Arrangement.Center
                    ) {
                        Text(
                            // One literal: tools/i18n-build.php finds the English by it.
                            text = tr("Ownify leest je stappen, afstand, actieve calorieën, hartslag, slaap, trainingen en voeding uit Health Connect en stuurt ze naar je eigen Ownify-account. Met toegang op de achtergrond doet het dit ook ongeveer eens per uur terwijl de app dicht is. Ownify leest alleen: het verandert of verwijdert nooit iets in Health Connect.")
                        )
                    }
                }
            }
        }
    }
}
