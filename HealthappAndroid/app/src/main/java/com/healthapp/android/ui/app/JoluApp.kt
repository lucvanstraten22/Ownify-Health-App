package com.healthapp.android.ui.app

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.style.TextAlign
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import androidx.lifecycle.compose.LocalLifecycleOwner
import com.healthapp.android.data.AppLoad
import com.healthapp.android.data.JoluAppState
import com.healthapp.android.jolu.JoluConnection
import com.healthapp.android.jolu.JoluScope
import com.healthapp.android.jolu.JoluState
import com.healthapp.android.jolu.JoluSync
import com.healthapp.android.jolu.JoluSyncState
import com.healthapp.android.ui.design.Btn
import com.healthapp.android.ui.design.Ground
import com.healthapp.android.ui.design.GroundPlacement
import com.healthapp.android.ui.design.JCard
import com.healthapp.android.ui.design.JStyle
import com.healthapp.android.ui.design.LocalGround
import com.healthapp.android.ui.design.LocalScreen
import com.healthapp.android.ui.design.ScreenMetrics
import com.healthapp.android.ui.design.T
import com.healthapp.android.ui.design.ground
import com.healthapp.android.ui.theme.Jolu

/** Which screen the app is: the website decides this from the session; the app from its credential. */
private enum class Route { Splash, Welcome, Paired, App }

/**
 * The app. Signed out, it is the opening screen; signed in as an account, it
 * is the app; a phone paired with a code but not signed in sees the opening
 * screen with its sync status. Only the stored credential decides
 * (JoluConnection), never anything remembered on screen.
 */
@Composable
fun JoluApp(screens: ShellScreens, signedOut: SignedOutScreens) {
    val context = LocalContext.current

    LaunchedEffect(Unit) { JoluConnection.start(context) }

    BoxWithConstraints(Modifier.fillMaxSize().background(Jolu.BgDeep)) {
        CompositionLocalProvider(LocalScreen provides ScreenMetrics(maxWidth, maxHeight)) {
            val state = JoluConnection.state
            val last = remember { arrayOf(Route.Splash) }

            val route = when (state) {
                JoluState.Checking -> Route.Splash
                // On its way somewhere: keep what is showing until it gets there.
                JoluState.Loading -> last[0]
                is JoluState.Connected -> if (state.scope == JoluScope.ACCOUNT) Route.App else Route.Paired
                is JoluState.Failed -> when (state.scope) {
                    JoluScope.ACCOUNT -> Route.App
                    JoluScope.SYNC -> Route.Paired
                    null -> Route.Welcome
                }
                is JoluState.NotConnected, JoluState.Pairing -> Route.Welcome
            }.also { last[0] = it }

            when (route) {
                Route.Splash -> Box(Modifier.fillMaxSize().ground(remember { GroundPlacement(Ground.App) }))
                Route.Welcome -> signedOut.welcome(false)
                Route.Paired -> signedOut.welcome(true)
                Route.App -> SignedInApp(screens)
            }

            // Leaving the app — signed out, expired — takes its data out of memory.
            LaunchedEffect(route) {
                if (route != Route.App) JoluAppState.clear()
            }
        }
    }
}

/** The signed-out screens, drawn by the screens package. */
class SignedOutScreens(val welcome: @Composable (paired: Boolean) -> Unit)

/**
 * Signed in: the pages, read from the server — at once, again when the app
 * comes back to the front, and again after a sync has brought new data (as
 * reloading the website would show it).
 */
@Composable
private fun SignedInApp(screens: ShellScreens) {
    val context = LocalContext.current
    val lifecycle = LocalLifecycleOwner.current.lifecycle

    LaunchedEffect(Unit) { JoluAppState.refresh(context) }

    DisposableEffect(lifecycle) {
        val observer = LifecycleEventObserver { _, event ->
            if (event == Lifecycle.Event.ON_RESUME && JoluAppState.data != null) JoluAppState.refresh(context)
        }
        lifecycle.addObserver(observer)
        onDispose { lifecycle.removeObserver(observer) }
    }

    // A sync that stored something changes scores, points and goals: read them again.
    val sync = JoluSync.state
    LaunchedEffect(sync) {
        if (sync is JoluSyncState.Synced && sync.summary.result.written > 0) JoluAppState.refresh(context)
    }

    when (val load = JoluAppState.load) {
        is AppLoad.Ready -> AppShell(load.data, screens)
        is AppLoad.Failed -> {
            val data = load.data
            if (data != null) AppShell(data, screens)
            else Unreachable(load.message) {
                JoluConnection.retry(context)
                JoluAppState.refresh(context)
            }
        }
        AppLoad.Idle, AppLoad.Loading, AppLoad.SyncOnly ->
            Box(Modifier.fillMaxSize().ground(remember { GroundPlacement(Ground.App) }))
    }
}

/**
 * Signed in, but nothing could be read yet — offline, or trouble on the
 * server. Android-only (the website is a page the browser could not load):
 * the reason, and a way to try again. The session is kept.
 */
@Composable
private fun Unreachable(message: String, onRetry: () -> Unit) {
    val placement = remember { GroundPlacement(Ground.App) }
    Box(Modifier.fillMaxSize().ground(placement), contentAlignment = Alignment.Center) {
        CompositionLocalProvider(LocalGround provides placement) {
            JCard(Modifier.width(LocalScreen.current.shell)) {
                Column(horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(Jolu.Space3)) {
                    T("Je gegevens konden niet worden geladen", JStyle.Subtitle, align = TextAlign.Center)
                    T(message, JStyle.Meta, align = TextAlign.Center)
                    Btn("Opnieuw proberen", onClick = onRetry, modifier = Modifier.padding(top = Jolu.Space2))
                }
            }
        }
    }
}
