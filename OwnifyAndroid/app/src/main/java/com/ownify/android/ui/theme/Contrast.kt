package com.ownify.android.ui.theme

import android.app.UiModeManager
import android.content.Context
import android.database.ContentObserver
import android.os.Build
import android.os.Handler
import android.os.Looper
import android.provider.Settings
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.platform.LocalContext

/**
 * Android's `prefers-contrast: more`: the phone asks for more contrast when
 * its contrast level is raised (Android 14+, Settings → Accessibility → Colour
 * and motion → Contrast: medium or high) or "High contrast text" is on.
 */
fun wantsMoreContrast(context: Context): Boolean {
    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE) {
        val modes = context.getSystemService(UiModeManager::class.java)
        if (modes != null && modes.contrast > 0f) return true
    }
    return Settings.Secure.getInt(context.contentResolver, HIGH_TEXT_CONTRAST, 0) == 1
}

/** The secure setting behind "High contrast text"; it has no public constant. */
private const val HIGH_TEXT_CONTRAST = "high_text_contrast_enabled"

/** [wantsMoreContrast], followed while the app is open. */
@Composable
fun rememberMoreContrast(): Boolean {
    val context = LocalContext.current
    var more by remember { mutableStateOf(wantsMoreContrast(context)) }
    DisposableEffect(context) {
        val resolver = context.contentResolver
        val observer = object : ContentObserver(Handler(Looper.getMainLooper())) {
            override fun onChange(selfChange: Boolean) {
                more = wantsMoreContrast(context)
            }
        }
        resolver.registerContentObserver(Settings.Secure.getUriFor(HIGH_TEXT_CONTRAST), false, observer)
        var listener: UiModeManager.ContrastChangeListener? = null
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE) {
            val modes = context.getSystemService(UiModeManager::class.java)
            listener = UiModeManager.ContrastChangeListener { more = wantsMoreContrast(context) }
            modes?.addContrastChangeListener(context.mainExecutor, listener)
        }
        onDispose {
            resolver.unregisterContentObserver(observer)
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE && listener != null) {
                context.getSystemService(UiModeManager::class.java)?.removeContrastChangeListener(listener)
            }
        }
    }
    return more
}
