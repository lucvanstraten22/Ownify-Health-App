package com.ownify.android.ui.design

import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.Easing
import androidx.compose.animation.core.InfiniteRepeatableSpec
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.tween
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.interaction.collectIsPressedAsState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.Stable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.layout.boundsInWindow
import androidx.compose.ui.layout.onGloballyPositioned
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.platform.LocalView
import androidx.compose.ui.unit.dp
import com.ownify.android.ui.theme.Ownify
import kotlinx.coroutines.delay
import kotlin.math.pow

/** `easeOutCubic(t) = 1 - (1 - t)^3`, the count-ups' curve in dashboard.js. */
val EaseOutCubic = Easing { t -> 1f - (1f - t).pow(3) }

/**
 * `.press:active { transform: scale(.97) }` over `--transition-fast`: every
 * tappable surface settles under the finger. [interaction] is the one the
 * clickable uses.
 */
@Composable
fun Modifier.press(interaction: MutableInteractionSource, scale: Float = 0.97f, enabled: Boolean = true): Modifier {
    val pressed by interaction.collectIsPressedAsState()
    val value by animateFloatAsState(
        targetValue = if (pressed && enabled) scale else 1f,
        animationSpec = tween(Ownify.FastMs, easing = Ownify.Ease),
        label = "press"
    )
    return this.graphicsLayer {
        scaleX = value
        scaleY = value
    }
}

/**
 * The website's "on screen for the first time" (IntersectionObserver): each
 * page has one, and what it watches reports in when it first comes into view,
 * in the order it does — which is what staggers the reveal.
 */
@Stable
class Visibility {
    private var last = 0L
    private var batch = 0

    /**
     * The n-th element to arrive together (within two frames of the one
     * before), for `min(index, 4) * 60ms`; a later arrival starts again at 0.
     */
    internal fun arrival(nowNanos: Long): Int {
        if (nowNanos - last > 32_000_000L) batch = 0
        last = nowNanos
        return batch++
    }
}

val LocalVisibility = staticCompositionLocalOf { Visibility() }

/** Whether the layout is being checked by a test or a screenshot, where things appear at once. */
val LocalStillMotion = staticCompositionLocalOf { false }

/**
 * An endlessly repeating value (the shimmer, the turning rings, the orb's
 * breath) — or [still] while the layout is being checked, where nothing
 * moves and a test can go idle.
 */
@Composable
fun loopValue(from: Float, to: Float, spec: InfiniteRepeatableSpec<Float>, still: Float = from): Float {
    if (LocalStillMotion.current) return still
    val value by rememberInfiniteTransition(label = "loop").animateFloat(from, to, spec, label = "loop")
    return value
}

/**
 * `.reveal`: fades in and rises 14 dp over `--transition-slow` the first time
 * it is on screen (12% of it, above the bottom 8%), staggered by 60 ms per
 * element arriving together, at most four steps.
 */
@Composable
fun Modifier.reveal(): Modifier {
    val still = LocalStillMotion.current
    val visibility = LocalVisibility.current
    val view = LocalView.current
    val density = LocalDensity.current
    var seen by remember { mutableStateOf(still) }
    var delayMs by remember { mutableIntStateOf(0) }
    val progress = remember { Animatable(if (still) 1f else 0f) }

    LaunchedEffect(seen) {
        if (seen && progress.value < 1f) {
            delay(delayMs.toLong())
            progress.animateTo(1f, tween(Ownify.SlowMs, easing = Ownify.EaseOut))
        }
    }

    return this
        .onGloballyPositioned { coordinates ->
            if (seen) return@onGloballyPositioned
            val bounds = coordinates.boundsInWindow()
            val bottom = view.height * 0.92f
            val visible = (minOf(bounds.bottom, bottom) - maxOf(bounds.top, 0f)).coerceAtLeast(0f)
            if (bounds.height > 0f && visible / bounds.height >= 0.12f && bounds.width > 0f &&
                bounds.left < view.width && bounds.right > 0f
            ) {
                delayMs = minOf(visibility.arrival(System.nanoTime()), 4) * 60
                seen = true
            }
        }
        .graphicsLayer {
            val p = progress.value
            alpha = p
            translationY = (1f - p) * with(density) { 14.dp.toPx() }
        }
}

/**
 * Whether a card's rings, bars and numbers may play: `dashboard.js` starts
 * them the first time 20% of the card is on screen, above the bottom 10%.
 */
@Composable
fun rememberPlayOnSight(): Pair<Boolean, Modifier> {
    val still = LocalStillMotion.current
    val view = LocalView.current
    var play by remember { mutableStateOf(still) }

    val modifier = Modifier.onGloballyPositioned { coordinates ->
        if (play) return@onGloballyPositioned
        val bounds = coordinates.boundsInWindow()
        val bottom = view.height * 0.90f
        val visible = (minOf(bounds.bottom, bottom) - maxOf(bounds.top, 0f)).coerceAtLeast(0f)
        if (bounds.height > 0f && visible / bounds.height >= 0.2f && bounds.left < view.width && bounds.right > 0f) {
            play = true
        }
    }

    return play to modifier
}

/** A value that runs from 0 to [target] once [play] is set: the rings' and bars' 1000/1100 ms ease-out. */
@Composable
fun animatedShare(target: Float, play: Boolean, durationMs: Int = 1000, easing: Easing = Ownify.EaseOut): Float {
    val still = LocalStillMotion.current
    val value by animateFloatAsState(
        targetValue = if (play || still) target else 0f,
        animationSpec = if (still) tween(0) else tween(durationMs, easing = easing),
        label = "share"
    )
    return value
}

/**
 * `data-count-to`: the number counts up from 0 over 1000 ms, ease-out cubic,
 * keeping as many decimals as the server wrote — or shows [text] as it is
 * when it is not a number (a placeholder, "7:24"). It counts once: a number
 * that changes afterwards (a new read after a save) shows as it is, the way
 * the website swaps a refreshed card in without counting again.
 */
@Composable
fun countUpText(text: String, play: Boolean): String {
    val still = LocalStillMotion.current
    val progress = remember { Animatable(if (still) 1f else 0f) }

    LaunchedEffect(play) {
        if (play && progress.value < 1f) progress.animateTo(1f, tween(1000, easing = EaseOutCubic))
    }

    val number = text.toDoubleOrNull()
    if (number == null || still || progress.value >= 1f) return text

    val decimals = text.substringAfter('.', "").length
    if (!play) return "%.${decimals}f".format(java.util.Locale.ROOT, 0.0)
    return "%.${decimals}f".format(java.util.Locale.ROOT, number * progress.value)
}
