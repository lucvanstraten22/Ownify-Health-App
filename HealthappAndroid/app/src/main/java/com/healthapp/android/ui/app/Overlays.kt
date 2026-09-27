package com.healthapp.android.ui.app

import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxScope
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.RowScope
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.asPaddingValues
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawing
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.ScrollState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.drawBehind
import androidx.compose.ui.graphics.ClipOp
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.RectangleShape
import androidx.compose.ui.graphics.Shape
import androidx.compose.ui.graphics.TransformOrigin
import androidx.compose.ui.graphics.addOutline
import androidx.compose.ui.graphics.drawscope.clipPath
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalGraphicsContext
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.paneTitle
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import com.healthapp.android.ui.design.CardStyle
import com.healthapp.android.ui.design.GlassFilter
import com.healthapp.android.ui.design.JIcon
import com.healthapp.android.ui.design.JStyle
import com.healthapp.android.ui.design.JoluIcons
import com.healthapp.android.ui.design.LocalBackdrop
import com.healthapp.android.ui.design.T
import com.healthapp.android.ui.design.cardShape
import com.healthapp.android.ui.design.cardSurface
import com.healthapp.android.ui.design.glassBackdrop
import com.healthapp.android.ui.design.press
import com.healthapp.android.ui.design.rememberBlurLayer
import com.healthapp.android.ui.theme.Jolu

/** Where a panel sits: in the middle (the account panel, the wizard, a confirmation) or hanging from the header (devices). */
enum class Placement { Center, UnderHeader }

/**
 * A panel in front of everything (`[data-overlay]`): the scrim — what is
 * behind blurred 3 px and dimmed — and the panel on it, which fades in while
 * it rises 10 dp and grows from 98% (`--screen-duration`), and leaves the
 * same way. A tap on the scrim closes it, unless [dismissible] is off.
 */
@Composable
fun OverlayFrame(
    overlay: Overlay,
    title: String,
    scrim: Float = 0.52f,
    placement: Placement = Placement.Center,
    maxWidth: Dp = 400.dp,
    dismissible: Boolean = true,
    onDismiss: (() -> Unit)? = null,
    panel: @Composable BoxScope.(panelModifier: Modifier) -> Unit
) {
    val shell = LocalShell.current
    val leaving = shell.isLeaving(overlay)
    val shown = remember { Animatable(0f) }
    val rise = remember { Animatable(0f) }
    val backdrop = LocalBackdrop.current
    val scrimBlur = rememberBlurLayer()
    val insets = WindowInsets.safeDrawing.asPaddingValues()

    LaunchedEffect(leaving) {
        val target = if (leaving) 0f else 1f
        kotlinx.coroutines.coroutineScope {
            launch { shown.animateTo(target, tween(Jolu.FastMs, easing = Jolu.Ease)) }
            launch { rise.animateTo(target, tween(Jolu.ScreenMs, easing = Jolu.ScreenEase)) }
        }
        if (leaving) shell.finish(overlay)
    }

    val dismiss = onDismiss ?: { shell.close(overlay) }

    Box(Modifier.fillMaxSize()) {
        // The scrim.
        Box(
            Modifier
                .fillMaxSize()
                .graphicsLayer { alpha = shown.value }
                .glassBackdrop(backdrop, RectangleShape, GlassFilter(3.dp), scrimBlur)
                .background(Color.Black.copy(alpha = scrim))
                .then(
                    if (dismissible && !leaving) Modifier.clickable(
                        interactionSource = remember { MutableInteractionSource() },
                        indication = null,
                        onClickLabel = "Sluiten"
                    ) { dismiss() } else Modifier
                )
        )

        val frame = when (placement) {
            Placement.Center -> Modifier
                .fillMaxSize()
                .padding(
                    start = Jolu.Space4, end = Jolu.Space4,
                    top = insets.calculateTopPadding() + Jolu.Space4,
                    bottom = insets.calculateBottomPadding() + Jolu.Space4
                )
                .imePadding()
            Placement.UnderHeader -> Modifier.fillMaxSize()
        }

        Box(frame, contentAlignment = if (placement == Placement.Center) Alignment.Center else Alignment.TopStart) {
            val panelModifier = Modifier
                .widthIn(max = maxWidth)
                .graphicsLayer {
                    alpha = shown.value
                    val t = 1f - rise.value
                    if (placement == Placement.Center) {
                        translationY = 10.dp.toPx() * t
                    } else {
                        translationY = -6.dp.toPx() * t
                        transformOrigin = TransformOrigin(0f, 0f)
                    }
                    scaleX = 1f - 0.02f * t
                    scaleY = 1f - 0.02f * t
                }
                .semantics { paneTitle = title }
            panel(panelModifier)
        }
    }
}

/**
 * A panel's card: its glass looks through the scrim at the app — blurred
 * 24 px and saturated 130%, darkened as the scrim darkens it — under the
 * card's own surface, border and highlight.
 */
@Composable
fun Modifier.panelGlass(shape: Shape = cardShape(), scrim: Float = 0.52f, style: CardStyle = CardStyle.Default): Modifier {
    val backdrop = LocalBackdrop.current
    val layer = rememberBlurLayer()
    val shadows = LocalGraphicsContext.current.shadowContext
    return this
        .glassBackdrop(backdrop, shape, GlassFilter(24.dp, saturate = 1.3f), layer)
        .drawBehind {
            val path = Path().apply { addOutline(shape.createOutline(size, layoutDirection, this@drawBehind)) }
            clipPath(path, ClipOp.Intersect) { drawRect(Color.Black.copy(alpha = scrim)) }
        }
        .cardSurface(shape, style, ground = null, shadows = shadows)
}

/**
 * A panel's scrolling card column: 24 around, as `.card`, and at most the
 * height the frame leaves it (`max-height: 100%; overflow-y: auto`).
 */
@Composable
fun PanelColumn(
    modifier: Modifier,
    scrim: Float = 0.52f,
    padding: PaddingValues = PaddingValues(Jolu.Space5),
    scroll: ScrollState = rememberScrollState(),
    content: @Composable ColumnScope.() -> Unit
) {
    Box(modifier.panelGlass(scrim = scrim)) {
        Column(
            Modifier
                .verticalScroll(scroll)
                .padding(padding),
            content = content
        )
    }
}

/**
 * `.account__head`: the panel's title (an eyebrow) and its round close
 * button (34, a chevron down), 16 above what follows. [leading] is the back
 * button of the Vrienden view.
 */
@Composable
fun PanelHead(
    title: String,
    onClose: () -> Unit,
    modifier: Modifier = Modifier,
    closeLabel: String = "Sluiten",
    leading: (@Composable RowScope.() -> Unit)? = null
) {
    Row(
        modifier.fillMaxWidth().padding(bottom = Jolu.Space4),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Jolu.Space3)
    ) {
        if (leading != null) leading()
        T(title, JStyle.Eyebrow, Modifier.weight(1f).semantics { heading() })
        RoundButton(JoluIcons.chevronDown, closeLabel, onClose)
    }
}

/** `.account__close`: a 34 circle, hairline border, `.055` white, a secondary 17 icon. */
@Composable
fun RoundButton(icon: ImageVector, label: String, onClick: () -> Unit, modifier: Modifier = Modifier) {
    val interaction = remember { MutableInteractionSource() }
    Box(
        modifier
            .press(interaction)
            .size(34.dp)
            .clip(CircleShape)
            .background(Jolu.white(0.055f))
            .border(1.dp, Jolu.GlassHairline, CircleShape)
            .clickable(interaction, indication = null, role = Role.Button, onClick = onClick)
            .semantics { contentDescription = label },
        contentAlignment = Alignment.Center
    ) {
        JIcon(icon, size = 17.dp, color = Jolu.TextSecondary)
    }
}

/** A minimum-height spacer the panels share. */
fun Modifier.touchTarget(): Modifier = this.heightIn(min = 44.dp)
