package com.ownify.android.ui.design

import androidx.compose.animation.core.animateDpAsState
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.tween
import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.foundation.hoverable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.interaction.collectIsHoveredAsState
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.layout.Layout
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.testTag
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.Constraints
import androidx.compose.ui.unit.dp
import com.ownify.android.ui.theme.LocalAccent
import com.ownify.android.ui.theme.Ownify
import com.ownify.android.ui.theme.OwnifyType
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/** How long a finger rests on a bar before its reading shows (dashboard.js HOLD_MS): never on a tap or a swipe's start. */
const val READING_HOLD_MS = 280L

/** A combined insight's reading bubble, for tests. */
const val InsightReadingTag = "insight-reading"

/**
 * `.goal__tip`: a bar's reading in a small near-solid glass pill
 * (`--tip-surface`). One line for the goal's numbers; [wrap] for a sentence
 * (a combined insight's "Nog 2 dagen …"), at most 260 dp wide.
 */
@Composable
fun ReadingBubble(text: String, modifier: Modifier = Modifier, wrap: Boolean = false, tag: String? = null) {
    val shape = RoundedCornerShape(50)
    Box(
        modifier
            .clearAndSetSemantics { if (tag != null) testTag = tag }
            .then(if (wrap) Modifier.widthIn(max = 260.dp) else Modifier)
            .liquidGlass(shape, Panes.Tip)
            .padding(horizontal = 10.dp, vertical = 5.dp)
    ) {
        T(text, OwnifyType.style(Ownify.FsSubhead, FontWeight.SemiBold, Ownify.TextPrimary, tabular = true), maxLines = if (wrap) 4 else 1)
    }
}

/**
 * A combined insight still gathering what it needs (38.0,
 * components/insight-progress.php): only its Meter — no percentage, no
 * label — and what is still needed as the goal bar's reading: shown while a
 * mouse is over it or a finger rests on it, gone when that ends. A plain
 * tap is [onTap] (the row or card it is in opens). TalkBack hears [reading]
 * as the bar's description.
 */
@Composable
fun ReadingBar(share: Float, reading: String, play: Boolean, modifier: Modifier = Modifier, accent: Color = LocalAccent.current.color, onTap: (() -> Unit)? = null) {
    val scope = rememberCoroutineScope()
    val hover = remember { MutableInteractionSource() }
    val hovered by hover.collectIsHoveredAsState()
    var held by remember { mutableStateOf(false) }
    // Whether the last press became a hold: only the next press clears it, so its release is never a tap.
    val wasHeld = remember { booleanArrayOf(false) }
    val shown = held || hovered
    val alpha by animateFloatAsState(if (shown) 1f else 0f, tween(Ownify.FastMs, easing = Ownify.Ease), label = "reading")
    val lift by animateDpAsState(if (shown) 4.dp else (-2).dp, tween(Ownify.FastMs, easing = Ownify.Ease), label = "reading-lift")
    val barTop = Ownify.Space2
    val value = share.coerceIn(0f, 1f)

    Layout(
        content = {
            // `.insight-lock`: 8 above and below the 6 dp bar, so a finger finds it.
            Box(Modifier.fillMaxWidth().padding(vertical = barTop)) {
                // Nothing yet (0 %): the dashed line of an empty bar, as the web draws it (C72, 50.0).
                Meter(share = value.takeIf { it > 0f }, play = play, accent = accent, height = 6.dp, emptyHeight = 6.dp,
                    modifier = Modifier.semantics { contentDescription = reading })
            }
            if (shown || alpha > 0f) ReadingBubble(reading, Modifier.graphicsLayer { this.alpha = alpha }, wrap = true, tag = InsightReadingTag)
        },
        modifier = modifier
            .fillMaxWidth()
            .hoverable(hover)
            .pointerInput(onTap) {
                detectTapGestures(
                    onPress = {
                        wasHeld[0] = false
                        val wait = scope.launch {
                            delay(READING_HOLD_MS)
                            held = true
                            wasHeld[0] = true
                        }
                        tryAwaitRelease()
                        wait.cancel()
                        held = false
                    },
                    // A hold that showed the reading is not also a tap.
                    onTap = { if (!wasHeld[0]) onTap?.invoke() }
                )
            }
    ) { measurables, constraints ->
        val track = measurables[0].measure(constraints)
        val bubble = measurables.getOrNull(1)?.measure(Constraints(maxWidth = constraints.maxWidth))
        layout(track.width, track.height) {
            track.placeRelative(0, 0)
            bubble?.let {
                // Over the fill's end, at the same share of its own width: over the bar at 0 and at 100 alike.
                val x = (value * track.width - value * it.width).toInt()
                val y = (barTop.toPx() - it.height - lift.toPx()).toInt()
                it.placeRelative(x, y, zIndex = 3f)
            }
        }
    }
}
