package com.ownify.android.ui.app

import androidx.compose.animation.Crossfade
import androidx.compose.animation.core.tween
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
import com.ownify.android.data.AppData
import com.ownify.android.data.AppLoad
import com.ownify.android.data.OwnifyAppState
import com.ownify.android.connection.OwnifyConnection
import com.ownify.android.connection.OwnifyScope
import com.ownify.android.connection.OwnifyState
import com.ownify.android.connection.OwnifySync
import com.ownify.android.connection.OwnifySyncState
import com.ownify.android.ui.design.Btn
import com.ownify.android.ui.design.Ground
import com.ownify.android.ui.design.GroundPlacement
import com.ownify.android.ui.design.JCard
import com.ownify.android.ui.design.JStyle
import com.ownify.android.ui.design.LocalGround
import com.ownify.android.ui.design.LocalScreen
import com.ownify.android.ui.design.LocalStillMotion
import com.ownify.android.ui.design.ScreenMetrics
import com.ownify.android.ui.design.T
import com.ownify.android.ui.design.ground
import com.ownify.android.ui.theme.Ownify

/** Which screen the app is: the website decides this from the session; the app from its credential. */
private enum class Route { Splash, Welcome, Paired, App }

/**
 * The app. Signed out, it is the opening screen; signed in as an account, it
 * is the app; a phone paired with a code but not signed in sees the opening
 * screen with its sync status. Only the stored credential decides
 * (OwnifyConnection), never anything remembered on screen.
 */
@Composable
fun OwnifyApp(screens: ShellScreens, signedOut: SignedOutScreens) {
    val context = LocalContext.current

    LaunchedEffect(Unit) { OwnifyConnection.start(context) }

    BoxWithConstraints(Modifier.fillMaxSize().background(Ownify.BgDeep)) {
        CompositionLocalProvider(LocalScreen provides ScreenMetrics(maxWidth, maxHeight)) {
            val state = OwnifyConnection.state
            val last = remember { arrayOf(Route.Splash) }

            val route = when (state) {
                OwnifyState.Checking -> Route.Splash
                // On its way somewhere: keep what is showing until it gets there.
                OwnifyState.Loading -> last[0]
                is OwnifyState.Connected -> if (state.scope == OwnifyScope.ACCOUNT) Route.App else Route.Paired
                is OwnifyState.Failed -> when (state.scope) {
                    OwnifyScope.ACCOUNT -> Route.App
                    OwnifyScope.SYNC -> Route.Paired
                    null -> Route.Welcome
                }
                is OwnifyState.NotConnected, OwnifyState.Pairing -> Route.Welcome
            }.also { last[0] = it }

            when (route) {
                Route.Splash -> Box(Modifier.fillMaxSize().ground(remember { GroundPlacement(Ground.App) }))
                Route.Welcome -> signedOut.welcome(false)
                Route.Paired -> signedOut.welcome(true)
                Route.App -> SignedInApp(screens)
            }

            // Leaving the app — signed out, expired — takes its data out of memory.
            LaunchedEffect(route) {
                if (route != Route.App) OwnifyAppState.clear()
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

    LaunchedEffect(Unit) { OwnifyAppState.refresh(context) }

    DisposableEffect(lifecycle) {
        val observer = LifecycleEventObserver { _, event ->
            if (event == Lifecycle.Event.ON_RESUME && OwnifyAppState.data != null) OwnifyAppState.refresh(context)
        }
        lifecycle.addObserver(observer)
        onDispose { lifecycle.removeObserver(observer) }
    }

    // A sync that stored something changes scores, points and goals: read them again.
    val sync = OwnifySync.state
    LaunchedEffect(sync) {
        if (sync is OwnifySyncState.Synced && sync.summary.result.written > 0) OwnifyAppState.refresh(context)
    }

    // The pages from one place whatever the last read did, so a read that
    // fails keeps what is on screen — the setup's step among it — as it is.
    val load = OwnifyAppState.load
    val data = when (load) {
        is AppLoad.Ready -> load.data
        is AppLoad.Failed -> load.data
        else -> null
    }

    when {
        data != null -> ShellOrSetup(data, screens)
        load is AppLoad.Failed -> Unreachable(load.message) {
            OwnifyConnection.retry(context)
            OwnifyAppState.refresh(context)
        }
        else -> Box(Modifier.fillMaxSize().ground(remember { GroundPlacement(Ground.App) }))
    }
}

/**
 * A new account's setup while the server says it is pending (`setup`,
 * includes/setup.php) — as the website shows pages/setup.php — and the app
 * once it is finished, the one fading into the other.
 */
@Composable
private fun ShellOrSetup(data: AppData, screens: ShellScreens) {
    val still = LocalStillMotion.current
    Crossfade(data.setup.pending, animationSpec = tween(if (still) 0 else Ownify.ScreenMs, easing = Ownify.ScreenEase), label = "setup") { pending ->
        if (pending) screens.setup(data) else AppShell(data, screens)
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
                Column(horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(Ownify.Space3)) {
                    T("Je gegevens konden niet worden geladen", JStyle.Subtitle, align = TextAlign.Center)
                    T(message, JStyle.Meta, align = TextAlign.Center)
                    Btn("Opnieuw proberen", onClick = onRetry, modifier = Modifier.padding(top = Ownify.Space2))
                }
            }
        }
    }
}
