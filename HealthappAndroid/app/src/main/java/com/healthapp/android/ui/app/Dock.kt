package com.healthapp.android.ui.app

import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.animateDpAsState
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.interaction.collectIsPressedAsState
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.asPaddingValues
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawing
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.drawWithContent
import androidx.compose.ui.geometry.Rect
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.ClipOp
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.addOutline
import androidx.compose.ui.graphics.drawscope.DrawScope
import androidx.compose.ui.graphics.drawscope.clipPath
import androidx.compose.ui.graphics.drawscope.translate
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.layout.boundsInRoot
import androidx.compose.ui.layout.onGloballyPositioned
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.platform.LocalGraphicsContext
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.selected
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.em
import com.healthapp.android.data.NavItem
import com.healthapp.android.ui.design.BoxShadow
import com.healthapp.android.ui.design.JIcon
import com.healthapp.android.ui.design.JoluIcons
import com.healthapp.android.ui.design.LocalScreen
import com.healthapp.android.ui.design.Pane
import com.healthapp.android.ui.design.Panes
import com.healthapp.android.ui.design.Stop
import com.healthapp.android.ui.design.T
import com.healthapp.android.ui.design.cssLinearGradient
import com.healthapp.android.ui.design.cssRadialGradient
import com.healthapp.android.ui.design.drawBoxShadows
import com.healthapp.android.ui.theme.Jolu
import com.healthapp.android.ui.theme.JoluType

/**
 * The dock (components/app-dock.php): the assistant's handle, and under it
 * the tab bar floating over the page. It is also the sheet's grab area: an
 * upward swipe that starts on it opens the assistant ([onZones] reports where
 * it is).
 */
@Composable
fun Dock(
    items: List<NavItem>,
    current: String,
    openAria: String,
    onTab: (String) -> Unit,
    onOpenAssistant: () -> Unit,
    onZones: (List<Rect>) -> Unit,
    modifier: Modifier = Modifier
) {
    val screen = LocalScreen.current
    val density = LocalDensity.current
    val bottom = WindowInsets.safeDrawing.asPaddingValues().calculateBottomPadding()
    var handleZone = remember { arrayOf<Rect?>(null, null) }

    fun report() {
        onZones(handleZone.filterNotNull())
    }

    Column(
        modifier
            .fillMaxWidth()
            .padding(bottom = if (screen.desktop) Jolu.Space5 else 0.dp),
        horizontalAlignment = Alignment.CenterHorizontally
    ) {
        AssistantHandle(
            openAria,
            onOpenAssistant,
            Modifier.onGloballyPositioned {
                handleZone[0] = it.boundsInRoot().toDp(density.density)
                report()
            }
        )
        Tabbar(
            items, current, onTab,
            Modifier
                .padding(bottom = bottom + Jolu.DockGap)
                .onGloballyPositioned {
                    handleZone[1] = it.boundsInRoot().toDp(density.density)
                    report()
                }
        )
    }
}

private fun Rect.toDp(density: Float) = Rect(left / density, top / density, right / density, bottom / density)

/** `.ai-handle`: a 128 × 40 target around a 38 × 4 grip that widens to 50 and brightens under the finger. */
@Composable
private fun AssistantHandle(aria: String, onOpen: () -> Unit, modifier: Modifier) {
    val interaction = remember { MutableInteractionSource() }
    val pressed by interaction.collectIsPressedAsState()
    val grip by animateDpAsState(if (pressed) 50.dp else 38.dp, tween(Jolu.FastMs, easing = Jolu.Ease), label = "grip")
    val tone by animateColorAsState(if (pressed) Jolu.white(0.42f) else Jolu.white(0.18f), tween(Jolu.FastMs, easing = Jolu.Ease), label = "tone")

    Box(
        modifier
            .size(128.dp, 40.dp)
            .clickable(interaction, indication = null, role = Role.Button, onClick = onOpen)
            .semantics { contentDescription = aria },
        contentAlignment = Alignment.Center
    ) {
        Box(Modifier.size(grip, 4.dp).background(tone, RoundedCornerShape(50)))
    }
}

/**
 * `.tabbar`: a capsule of the floating glass, `min(94vw, 440)` wide and 54
 * high, five equal columns inset by `--tab-edge`, the reflection over its
 * face and the rim round it.
 */
@Composable
private fun Tabbar(items: List<NavItem>, current: String, onTab: (String) -> Unit, modifier: Modifier) {
    val screen = LocalScreen.current
    val shape = RoundedCornerShape(if (screen.desktop) screen.cardRadius else 999.dp)

    Pane(
        modifier
            .width(screen.tabbarWidth)
            .height(Jolu.TabbarHeight)
            .semantics { contentDescription = "Hoofdnavigatie" },
        style = Panes.Tabbar,
        shape = shape,
        sheen = { drawSheen(shape) }
    ) {
        Row(
            Modifier
                .fillMaxSize()
                .padding(horizontal = if (screen.desktop) Jolu.Space2 else screen.tabEdge)
        ) {
            for (item in items) {
                Tab(item, item.id == current, { onTab(item.id) }, Modifier.weight(1f).fillMaxHeight())
            }
        }
    }
}

/**
 * `.tabbar::before`: two soft reflections — the light at the top left and
 * its echo at the bottom right — drawn 16 dp past the capsule and clipped
 * back to it, as the website draws them.
 */
private fun DrawScope.drawSheen(shape: androidx.compose.ui.graphics.Shape) {
    val over = Jolu.SheenOverhang.toPx()
    val box = Size(size.width + over * 2, size.height + over * 2)
    val clip = Path().apply { addOutline(shape.createOutline(size, layoutDirection, this@drawSheen)) }
    clipPath(clip, ClipOp.Intersect) {
        translate(-over, -over) {
            cssRadialGradient(
                0.57f, 0.94f, 0.242f, 0f,
                Stop(0f, Jolu.white(0.20f)), Stop(0.40f, Jolu.white(0.06f)), Stop(0.68f, Jolu.white(0f)),
                box = box
            )
            cssRadialGradient(
                0.44f, 0.82f, 0.85f, 0.99f,
                Stop(0f, Jolu.white(0.10f)), Stop(0.62f, Jolu.white(0f)),
                box = box
            )
        }
    }
}

/**
 * `.tab`: the icon (24) over its label (10/500), secondary at rest, primary
 * when chosen — and the chosen one sits on a second, brighter pane of the
 * same glass (`.tab__glow`), which fades and grows in over 420 ms.
 */
@Composable
private fun Tab(item: NavItem, active: Boolean, onClick: () -> Unit, modifier: Modifier) {
    val interaction = remember { MutableInteractionSource() }
    val pressed by interaction.collectIsPressedAsState()
    val glow by animateFloatAsState(
        when {
            active -> 1f
            pressed -> 0.45f
            else -> 0f
        },
        tween(Jolu.SlowMs, easing = Jolu.EaseOut),
        label = "glow"
    )
    val grow by animateFloatAsState(if (active || pressed) 1f else 0.94f, tween(Jolu.SlowMs, easing = Jolu.EaseOut), label = "grow")
    val color by animateColorAsState(if (active || pressed) Jolu.TextPrimary else Jolu.TextSecondary, tween(Jolu.FastMs, easing = Jolu.Ease), label = "ink")
    val shadows = LocalGraphicsContext.current.shadowContext
    val pill = RoundedCornerShape(50)

    Box(
        modifier
            .clickable(interaction, indication = null, role = Role.Tab, onClick = onClick)
            .semantics(mergeDescendants = true) { selected = active },
        contentAlignment = Alignment.Center
    ) {
        // .tab__glow — inset 4
        Box(
            Modifier
                .fillMaxSize()
                .padding(Jolu.TabGlowInset)
                .graphicsLayer {
                    alpha = glow
                    scaleX = grow
                    scaleY = grow
                }
                .drawWithContent {
                    if (glow <= 0f) return@drawWithContent
                    val outline = pill.createOutline(size, layoutDirection, this)
                    drawBoxShadows(pill, listOf(BoxShadow(y = 2.dp, blur = 7.dp, color = Color.Black.copy(alpha = 0.20f))), shadows, outline)
                    val path = Path().apply { addOutline(outline) }
                    drawPath(path, cssLinearGradient(178f, size, Stop(0f, Jolu.white(0.20f)), Stop(0.48f, Jolu.white(0.115f)), Stop(1f, Jolu.white(0.085f))))
                    drawBoxShadows(
                        pill,
                        listOf(
                            BoxShadow(y = 1.dp, blur = 0.dp, color = Jolu.white(0.28f), inset = true),
                            BoxShadow(y = (-1).dp, blur = 0.dp, color = Jolu.white(0.05f), inset = true)
                        ),
                        shadows, outline
                    )
                }
        )

        Column(
            Modifier.padding(bottom = (10f * 0.124f).dp),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.spacedBy(Jolu.TabGap)
        ) {
            JoluIcons.named(item.icon)?.let { JIcon(it, size = Jolu.TabIcon, color = color) }
            T(
                item.label,
                JoluType.style(Jolu.FsTab, FontWeight.Medium, color, tracking = (-0.012).em, lineHeight = 1.em),
                maxLines = 1
            )
        }
    }
}

/** For the settle of a tab bar that has no height yet. */
@Composable
internal fun DockSpacer() = Spacer(Modifier.height(Jolu.TabbarHeight + Jolu.DockGap))
