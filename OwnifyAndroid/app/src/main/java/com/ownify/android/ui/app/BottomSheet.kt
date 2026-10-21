package com.ownify.android.ui.app
import com.ownify.android.ui.design.liquidGlass
import com.ownify.android.ui.design.Panes
import com.ownify.android.ui.design.PaneFilter
import androidx.compose.animation.animateContentSize
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.gestures.Orientation
import androidx.compose.foundation.gestures.draggable
import androidx.compose.foundation.gestures.rememberDraggableState
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.asPaddingValues
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawing
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.ScrollState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.RectangleShape
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.layout.onSizeChanged
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.paneTitle
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import com.ownify.android.i18n.tr
import com.ownify.android.ui.design.GlassFilter
import com.ownify.android.ui.design.JStyle
import com.ownify.android.ui.design.LocalBackdrop
import com.ownify.android.ui.design.LocalReducedMotion
import com.ownify.android.ui.design.OwnifyIcons
import com.ownify.android.ui.design.T
import com.ownify.android.ui.design.glassBackdrop
import com.ownify.android.ui.design.reduceFadeTo
import com.ownify.android.ui.design.rememberBlurLayer
import com.ownify.android.ui.theme.Ownify
import kotlinx.coroutines.launch

/**
 * A bottom sheet (`.ch-sheet`): the scrim — what is behind blurred 3 px and
 * dimmed — and the floating glass rising from the bottom edge over
 * `--screen-duration` on the screen ease, leaving the same way down in
 * [Ownify.PanelOutMs]. Its grip and head are a handle: dragged down it
 * follows the finger 1:1, and a release past a third of its height or a
 * flick sends it away; upwards it gives a little and no more. A tap on the
 * scrim closes it. With reduced motion it only fades.
 *
 * [head] is drawn under the grip and drags the sheet too; [body] scrolls;
 * [foot] stays at the bottom, clear of the navigation bar and the keyboard.
 */
@Composable
fun SheetFrame(
    overlay: Overlay,
    title: String,
    head: (@Composable ColumnScope.() -> Unit)? = null,
    foot: (@Composable ColumnScope.() -> Unit)? = null,
    scroll: ScrollState = rememberScrollState(),
    body: @Composable ColumnScope.() -> Unit
) {
    val shell = LocalShell.current
    val leaving = shell.isLeaving(overlay)
    val reduced = LocalReducedMotion.current
    val backdrop = LocalBackdrop.current
    val scrimBlur = rememberBlurLayer()
    val panelBlur = rememberBlurLayer()
    val insets = WindowInsets.safeDrawing.asPaddingValues()
    val scope = rememberCoroutineScope()

    // Kept per open sheet, not per call: a sheet that moves to its next step
    // (a code's review, "Je doet mee") stays where it is instead of rising again.
    val motion = remember(overlay) { SheetMotions.of(overlay) }
    val scrim = motion.scrim
    /** 1 = up, 0 = below the bottom edge. */
    val rise = motion.rise
    var height by remember { mutableFloatStateOf(1f) }
    var drag by remember { mutableFloatStateOf(0f) }

    LaunchedEffect(leaving) {
        val target = if (leaving) 0f else 1f
        kotlinx.coroutines.coroutineScope {
            launch { scrim.animateTo(target, tween(Ownify.FastMs, easing = Ownify.Ease)) }
            launch {
                if (reduced) rise.reduceFadeTo(target)
                else if (leaving) rise.animateTo(0f, tween(Ownify.PanelOutMs, easing = Ownify.EaseOutStrong))
                else rise.animateTo(1f, tween(Ownify.ScreenMs, easing = Ownify.ScreenEase))
            }
        }
        if (leaving) {
            shell.finish(overlay)
            SheetMotions.forget(overlay)
        }
    }

    val close = { shell.close(overlay) }

    val dragState = rememberDraggableState { delta ->
        // Down 1:1; up, a little give (`-sqrt` of the distance), no more.
        val next = drag + delta
        drag = if (next >= 0f) next else -kotlin.math.sqrt(-next) * 2f
    }
    val handle = Modifier.draggable(
        dragState,
        Orientation.Vertical,
        enabled = !leaving,
        onDragStopped = { velocity ->
            // px/s: a flick down, or past a third of the sheet, sends it away.
            if (drag > height / 3f || velocity > 1100f) {
                // From where the finger left it.
                val from = drag
                scope.launch {
                    rise.snapTo((1f - from / height).coerceIn(0f, 1f))
                    drag = 0f
                }
                close()
            } else {
                val from = drag
                scope.launch {
                    Animatable(from).animateTo(0f, tween(Ownify.ScreenMs, easing = Ownify.ScreenEase)) { drag = value }
                }
            }
        }
    )

    Box(Modifier.fillMaxSize()) {
        // The scrim.
        Box(
            Modifier
                .fillMaxSize()
                .graphicsLayer { alpha = scrim.value }
                .glassBackdrop(backdrop, RectangleShape, GlassFilter(3.dp), scrimBlur)
                .background(Ownify.scrim(0.52f))
                .then(
                    if (!leaving) Modifier.clickable(
                        interactionSource = remember { MutableInteractionSource() },
                        indication = null,
                        onClickLabel = tr("Sluiten")
                    ) { close() } else Modifier
                )
        )

        val shape = RoundedCornerShape(topStart = Ownify.RadiusCard, topEnd = Ownify.RadiusCard)
        Column(
            Modifier
                .align(Alignment.BottomCenter)
                .widthIn(max = 544.dp)
                .fillMaxWidth()
                .padding(top = insets.calculateTopPadding() + Ownify.Space6)
                // A next step of another height: the sheet grows or shrinks to it.
                .then(if (reduced) Modifier else Modifier.animateContentSize(tween(Ownify.ScreenMs, easing = Ownify.ScreenEase)))
                .onSizeChanged { height = it.height.toFloat().coerceAtLeast(1f) }
                .graphicsLayer {
                    if (reduced) {
                        alpha = rise.value
                        translationY = drag.coerceAtLeast(0f)
                    } else {
                        translationY = (1f - rise.value) * size.height + drag
                    }
                }
                .glassBackdrop(backdrop, shape, PaneFilter, panelBlur)
                // The Liquid Glass at its thick setting (theme.css --lg-tint-thick).
                .liquidGlass(shape, Panes.Panel)
                .semantics { paneTitle = title }
        ) {
            // The grip, and the head under it: the handle to drag it by.
            Column(handle.fillMaxWidth()) {
                Box(Modifier.fillMaxWidth().height(20.dp), contentAlignment = Alignment.Center) {
                    Box(Modifier.size(width = 36.dp, height = 5.dp).background(Ownify.ink(0.22f), RoundedCornerShape(50)))
                }
                if (head != null) head()
            }
            Column(
                Modifier
                    .weight(1f, fill = false)
                    .verticalScroll(scroll)
                    .padding(start = Ownify.Space5, end = Ownify.Space5, top = Ownify.Space2, bottom = if (foot == null) Ownify.Space6 + insets.calculateBottomPadding() else Ownify.Space5),
                content = body
            )
            if (foot != null) {
                Box(Modifier.fillMaxWidth().height(1.dp).background(Ownify.GlassHairline))
                Column(
                    Modifier
                        .fillMaxWidth()
                        .padding(start = Ownify.Space5, end = Ownify.Space5, top = Ownify.Space3, bottom = Ownify.Space4 + insets.calculateBottomPadding()),
                    content = foot
                )
            }
        }
    }
}

/** The sheet's own title row: the title, and the round close (a chevron down). */
@Composable
fun SheetHead(title: String, onClose: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().padding(start = Ownify.Space5, end = Ownify.Space5, top = Ownify.Space1, bottom = Ownify.Space2),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)
    ) {
        T(title, JStyle.Subtitle, Modifier.weight(1f).semantics { heading() })
        RoundButton(OwnifyIcons.chevronDown, tr("Sluiten"), onClose)
    }
}

/** The scrim's and the sheet's motion, per open sheet ([Overlay]). */
internal class SheetMotion {
    val scrim = Animatable(0f)
    val rise = Animatable(0f)
}

internal object SheetMotions {
    private val open = HashMap<Overlay, SheetMotion>()

    fun of(overlay: Overlay): SheetMotion = open.getOrPut(overlay) { SheetMotion() }

    fun forget(overlay: Overlay) {
        open.remove(overlay)
    }
}
