package com.ownify.android.ui.app

import android.animation.ValueAnimator
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.animate
import androidx.compose.animation.core.animateDpAsState
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.spring
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
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.runtime.snapshotFlow
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.drawBehind
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
import androidx.compose.ui.layout.layout
import androidx.compose.ui.layout.onGloballyPositioned
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.platform.LocalGraphicsContext
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.selected
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.Constraints
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.em
import com.ownify.android.data.NavItem
import com.ownify.android.ui.design.BoxShadow
import com.ownify.android.ui.design.JIcon
import com.ownify.android.ui.design.OwnifyIcons
import com.ownify.android.ui.design.LocalScreen
import com.ownify.android.ui.design.LocalStillMotion
import com.ownify.android.ui.design.Pane
import com.ownify.android.ui.design.Panes
import com.ownify.android.ui.design.Stop
import com.ownify.android.ui.design.T
import com.ownify.android.ui.design.cssLinearGradient
import com.ownify.android.ui.design.cssRadialGradient
import com.ownify.android.ui.design.drawBoxShadows
import com.ownify.android.ui.theme.Ownify
import com.ownify.android.ui.theme.OwnifyType
import kotlin.math.abs
import kotlin.math.exp
import kotlin.math.roundToInt
import kotlinx.coroutines.Job
import kotlinx.coroutines.launch

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
    glassAt: () -> Float,
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
            .padding(bottom = if (screen.desktop) Ownify.Space5 else 0.dp),
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
            items, current, glassAt, onTab,
            Modifier
                .padding(bottom = bottom + Ownify.DockGap)
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
    val grip by animateDpAsState(if (pressed) 50.dp else 38.dp, tween(Ownify.FastMs, easing = Ownify.Ease), label = "grip")
    val tone by animateColorAsState(if (pressed) Ownify.white(0.42f) else Ownify.white(0.18f), tween(Ownify.FastMs, easing = Ownify.Ease), label = "tone")

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
 * face and the rim round it — and over the tabs the one pane of brighter
 * glass that marks the chosen one ([TabGlass]), at [glassAt] tabs along.
 */
@Composable
private fun Tabbar(items: List<NavItem>, current: String, glassAt: () -> Float, onTab: (String) -> Unit, modifier: Modifier) {
    val screen = LocalScreen.current
    val shape = RoundedCornerShape(if (screen.desktop) screen.cardRadius else 999.dp)

    Pane(
        modifier
            .width(screen.tabbarWidth)
            .height(Ownify.TabbarHeight)
            .semantics { contentDescription = "Hoofdnavigatie" },
        style = Panes.Tabbar,
        shape = shape,
        sheen = { drawSheen(shape) }
    ) {
        Box(
            Modifier
                .fillMaxSize()
                .padding(horizontal = if (screen.desktop) Ownify.Space2 else screen.tabEdge)
        ) {
            Row(Modifier.fillMaxSize()) {
                for (item in items) {
                    Tab(item, item.id == current, { onTab(item.id) }, Modifier.weight(1f).fillMaxHeight())
                }
            }
            if (items.isNotEmpty()) TabGlass(items.size, glassAt, Modifier.matchParentSize())
        }
    }
}

/**
 * The spring the chosen tab's glass travels on, the website's own numbers
 * (page-navigation.js): about 190 ms to arrive, 1.5 % past the mark, at rest
 * by about 430 ms. It runs in tabs, so a far tab takes as long as the next.
 */
private val GlassSpring = spring(dampingRatio = 0.8f, stiffness = 340f, visibilityThreshold = 0.001f)

/** At speed the glass is up to 30 % longer (tabs per second) and a quarter of that thinner. */
private const val GlassStretch = 0.30f
private const val GlassStretchSpeed = 9f
private const val GlassThin = 0.25f

/**
 * The chosen tab's pane as one piece of glass for the whole bar: a tab
 * sends it there from wherever it is, keeping its speed when it is sent
 * somewhere else on the way, and a swipe carries it along under the finger.
 * While it moves it stretches along the bar with its speed and settles with
 * the spring's small overshoot. It sits over the icons as `.tab__glow` does.
 * With animations off (the system's "remove animations"), it goes straight
 * to the tab.
 */
@Composable
private fun TabGlass(count: Int, at: () -> Float, modifier: Modifier) {
    val still = LocalStillMotion.current || !remember { ValueAnimator.areAnimatorsEnabled() }
    var x by remember { mutableFloatStateOf(at()) }
    var v by remember { mutableFloatStateOf(0f) }
    val shadows = LocalGraphicsContext.current.shadowContext

    LaunchedEffect(still) {
        var move: Job? = null
        snapshotFlow(at).collect { to ->
            move?.cancel()
            if (still) {
                x = to
                v = 0f
            } else {
                // From where it is, at the speed it has.
                move = launch {
                    animate(x, to, v, GlassSpring) { value, velocity ->
                        x = value
                        v = velocity
                    }
                    v = 0f
                }
            }
        }
    }

    Box(
        modifier
            .layout { measurable, constraints ->
                val inset = Ownify.TabGlowInset.roundToPx()
                val cell = constraints.maxWidth / count.toFloat()
                val pane = measurable.measure(
                    Constraints.fixed(
                        (cell - 2 * inset).roundToInt().coerceAtLeast(0),
                        (constraints.maxHeight - 2 * inset).coerceAtLeast(0)
                    )
                )
                layout(constraints.maxWidth, constraints.maxHeight) {
                    // Placed by its layer alone: a frame moves and stretches it, nothing is measured again.
                    pane.placeWithLayer(inset, inset) {
                        val long = 1f + GlassStretch * (1f - exp(-abs(v) / GlassStretchSpeed))
                        translationX = x * cell
                        scaleX = long
                        scaleY = 1f - GlassThin * (long - 1f)
                    }
                }
            }
            .testTag(TabGlassTag)
            .drawBehind { drawTabGlass(shadows) }
    )
}

/** Where tests find the glass. */
internal const val TabGlassTag = "tab-glass"

/** `.tab__glow`: the brighter pane — its small shadow, the face, the lit top edge. */
private fun DrawScope.drawTabGlass(shadows: androidx.compose.ui.graphics.shadow.ShadowContext) {
    val pill = RoundedCornerShape(50)
    val outline = pill.createOutline(size, layoutDirection, this)
    drawBoxShadows(pill, listOf(BoxShadow(y = 2.dp, blur = 7.dp, color = Color.Black.copy(alpha = 0.20f))), shadows, outline)
    val path = Path().apply { addOutline(outline) }
    drawPath(path, cssLinearGradient(178f, size, Stop(0f, Ownify.white(0.20f)), Stop(0.48f, Ownify.white(0.115f)), Stop(1f, Ownify.white(0.085f))))
    drawBoxShadows(
        pill,
        listOf(
            BoxShadow(y = 1.dp, blur = 0.dp, color = Ownify.white(0.28f), inset = true),
            BoxShadow(y = (-1).dp, blur = 0.dp, color = Ownify.white(0.05f), inset = true)
        ),
        shadows, outline
    )
}

/**
 * `.tabbar::before`: two soft reflections — the light at the top left and
 * its echo at the bottom right — drawn 16 dp past the capsule and clipped
 * back to it, as the website draws them.
 */
private fun DrawScope.drawSheen(shape: androidx.compose.ui.graphics.Shape) {
    val over = Ownify.SheenOverhang.toPx()
    val box = Size(size.width + over * 2, size.height + over * 2)
    val clip = Path().apply { addOutline(shape.createOutline(size, layoutDirection, this@drawSheen)) }
    clipPath(clip, ClipOp.Intersect) {
        translate(-over, -over) {
            cssRadialGradient(
                0.57f, 0.94f, 0.242f, 0f,
                Stop(0f, Ownify.white(0.20f)), Stop(0.40f, Ownify.white(0.06f)), Stop(0.68f, Ownify.white(0f)),
                box = box
            )
            cssRadialGradient(
                0.44f, 0.82f, 0.85f, 0.99f,
                Stop(0f, Ownify.white(0.10f)), Stop(0.62f, Ownify.white(0f)),
                box = box
            )
        }
    }
}

/**
 * `.tab`: the icon (24) over its label (10/500), secondary at rest, primary
 * when chosen. The chosen one's pane is the bar's [TabGlass]; a tab keeps a
 * pane of its own only for a press, lifting under the finger (`.tab__glow`
 * at 45 %) and settling over 420 ms.
 */
@Composable
private fun Tab(item: NavItem, active: Boolean, onClick: () -> Unit, modifier: Modifier) {
    val interaction = remember { MutableInteractionSource() }
    val pressed by interaction.collectIsPressedAsState()
    val lifted = pressed && !active
    val glow by animateFloatAsState(if (lifted) 0.45f else 0f, tween(Ownify.SlowMs, easing = Ownify.EaseOut), label = "glow")
    val grow by animateFloatAsState(if (lifted) 1f else 0.94f, tween(Ownify.SlowMs, easing = Ownify.EaseOut), label = "grow")
    val color by animateColorAsState(if (active || pressed) Ownify.TextPrimary else Ownify.TextSecondary, tween(Ownify.FastMs, easing = Ownify.Ease), label = "ink")
    val shadows = LocalGraphicsContext.current.shadowContext

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
                .padding(Ownify.TabGlowInset)
                .graphicsLayer {
                    alpha = glow
                    scaleX = grow
                    scaleY = grow
                }
                .drawWithContent {
                    if (glow <= 0f) return@drawWithContent
                    drawTabGlass(shadows)
                }
        )

        Column(
            Modifier.padding(bottom = (10f * 0.124f).dp),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.spacedBy(Ownify.TabGap)
        ) {
            OwnifyIcons.named(item.icon)?.let { JIcon(it, size = Ownify.TabIcon, color = color) }
            T(
                item.label,
                OwnifyType.style(Ownify.FsTab, FontWeight.Medium, color, tracking = (-0.012).em, lineHeight = 1.em),
                maxLines = 1
            )
        }
    }
}

/** For the settle of a tab bar that has no height yet. */
@Composable
internal fun DockSpacer() = Spacer(Modifier.height(Ownify.TabbarHeight + Ownify.DockGap))
