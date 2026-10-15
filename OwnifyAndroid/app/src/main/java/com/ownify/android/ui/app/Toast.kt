package com.ownify.android.ui.app

import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxScope
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.asPaddingValues
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawing
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.testTag
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import com.ownify.android.ui.design.LocalReducedMotion
import com.ownify.android.ui.design.LocalScreen
import com.ownify.android.ui.design.T
import com.ownify.android.ui.design.cssPadding
import com.ownify.android.ui.theme.Ownify
import com.ownify.android.ui.theme.OwnifyType
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/** The toast's tag, for tests. */
internal const val TOAST = "toast"

/**
 * One line, above the tab bar, for a moment (`.ch-toast`): what an action
 * did when nothing on screen says it yet — "Code 48213 gekopieerd",
 * "Uitnodiging afgewezen".
 */
object OwnifyToast {
    var message by mutableStateOf<String?>(null)
        private set

    /** Each showing, so the same words twice still show twice. */
    var serial by mutableIntStateOf(0)
        private set

    fun show(text: String?) {
        if (text.isNullOrBlank()) return
        message = text
        serial++
    }
}

/**
 * Where the toast shows: centred, 24 above the dock's room, a pill of the
 * floating glass. It fades and rises 8 dp in over 240 ms on the strong
 * ease-out, stays 2.6 s, and goes the same way; with reduced motion it only
 * fades.
 */
@Composable
fun BoxScope.ToastHost() {
    val serial = OwnifyToast.serial
    val text = OwnifyToast.message ?: return
    val reduced = LocalReducedMotion.current
    val shown = remember { Animatable(0f) }
    val safeBottom = WindowInsets.safeDrawing.asPaddingValues().calculateBottomPadding()
    val screen = LocalScreen.current

    LaunchedEffect(serial) {
        launch { shown.snapTo(0f) }
        shown.animateTo(1f, tween(240, easing = Ownify.EaseOutStrong))
        delay(2600)
        shown.animateTo(0f, tween(180, easing = Ownify.EaseOutStrong))
    }

    val shape = RoundedCornerShape(50)
    Box(
        Modifier
            .align(Alignment.BottomCenter)
            .padding(bottom = dockClearance(safeBottom) + Ownify.Space5)
            .widthIn(max = screen.width - Ownify.Space5 * 2)
            .graphicsLayer {
                alpha = shown.value
                if (!reduced) translationY = (1f - shown.value) * 8.dp.toPx()
            }
            .background(Ownify.paneBody(0.94f), shape)
            .border(1.dp, Ownify.GlassBorderSoft, shape)
            .cssPadding(PaddingValues(horizontal = Ownify.Space4, vertical = Ownify.Space2), border = 1.dp)
            .semantics {
                liveRegion = LiveRegionMode.Polite
                testTag = TOAST
            }
    ) {
        T(text, OwnifyType.style(Ownify.FsSmall, FontWeight.Medium, Ownify.TextPrimary), align = TextAlign.Center)
    }
}
