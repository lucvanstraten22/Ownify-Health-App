package com.ownify.android.ui.app

import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.BlendMode
import androidx.compose.ui.draw.drawWithContent
import androidx.compose.ui.graphics.CompositingStrategy
import androidx.compose.ui.unit.Constraints
import androidx.compose.ui.layout.layout
import com.ownify.android.i18n.trn
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.interaction.collectIsPressedAsState
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.asPaddingValues
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawing
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.drawBehind
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.RectangleShape
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.em
import com.ownify.android.ui.design.hitArea
import com.ownify.android.ui.design.GlassFilter
import com.ownify.android.ui.design.JIcon
import com.ownify.android.ui.design.OwnifyIcons
import com.ownify.android.ui.design.LocalBackdrop
import com.ownify.android.ui.design.LocalGround
import com.ownify.android.ui.design.LocalScreen
import com.ownify.android.ui.design.Pane
import com.ownify.android.ui.design.Panes
import com.ownify.android.ui.design.Pill
import com.ownify.android.ui.design.T
import com.ownify.android.ui.design.glassBackdrop
import com.ownify.android.ui.design.rememberBlurLayer
import com.ownify.android.ui.theme.Ownify
import com.ownify.android.ui.theme.OwnifyType
import kotlinx.coroutines.withContext
import com.ownify.android.ui.design.Unscaled
import com.ownify.android.ui.design.LocalStillMotion
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.testTag
import androidx.compose.ui.layout.onGloballyPositioned
import androidx.compose.ui.layout.boundsInWindow
import androidx.compose.runtime.setValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.derivedStateOf
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.foundation.layout.widthIn
import androidx.compose.animation.core.Animatable

/** `--app-header-space`: safe top + 12 + the 46 button + 12 + the 1 px line. */
@Composable
fun headerSpace(): Dp = WindowInsets.safeDrawing.asPaddingValues().calculateTopPadding() + Ownify.Space3 + Ownify.HeaderButton + Ownify.Space3 + 1.dp

/** `.detail__top`: its bar holds only the back pill, so safe top + 12 + the 42 pill + 12 + the 1 px line. */
@Composable
fun detailHeaderSpace(): Dp = WindowInsets.safeDrawing.asPaddingValues().calculateTopPadding() + Ownify.Space3 + PillHeight + Ownify.Space3 + 1.dp

/** `.pill`'s min-height. */
val PillHeight = 42.dp

/** `.app-header.is-scrolled`: a solid tint that fades out at the bottom, over the page blurred 30 and saturated 140%. */
private val ScrolledFilter = GlassFilter(Ownify.BlurBar, saturate = 1.4f)

/**
 * The header the five pages share (components/header.php): the devices
 * button, the app's name, the account button. It sits above the rail, so
 * the pages slide under it; once the page under it is scrolled more than
 * 8 dp it takes its tint and blur.
 */
@Composable
fun SharedHeader(
    appName: String,
    devicesAria: String,
    accountAria: String,
    avatar: String?,
    requests: Int,
    scrolled: Boolean,
    onDevices: () -> Unit,
    onAccount: () -> Unit,
    modifier: Modifier = Modifier
) {
    val screen = LocalScreen.current
    HeaderFrame(scrolled, modifier) {
        Row(
            Modifier.fillMaxWidth().padding(horizontal = (screen.width - screen.shell) / 2),
            verticalAlignment = Alignment.CenterVertically
        ) {
            Box(Modifier.weight(1f), contentAlignment = Alignment.CenterStart) {
                HeaderButton(onClick = onDevices, label = devicesAria) {
                    JIcon(OwnifyIcons.device, size = Ownify.HeaderIcon, color = Ownify.TextPrimary)
                }
            }
            T(
                appName,
                OwnifyType.style(screen.fsBrand, FontWeight.SemiBold, tracking = (-0.03).em),
                Modifier.padding(horizontal = Ownify.Space3).semantics { heading() },
                maxLines = 1
            )
            Box(Modifier.weight(1f), contentAlignment = Alignment.CenterEnd) {
                val waiting = when {
                    requests >= 1 -> ", " + trn(requests, "%d nieuw vriendverzoek", "%d nieuwe vriendverzoeken")
                    else -> ""
                }
                HeaderButton(onClick = onAccount, label = accountAria + waiting, dot = requests > 0) {
                    Avatar(avatar, iconSize = Ownify.HeaderIcon, iconColor = Ownify.TextPrimary, modifier = Modifier.clip(CircleShape))
                }
            }
        }
    }
}

/**
 * The header's frame: safe top + 12 above, 12 below and a 1 px line; clear
 * at rest, tinted and blurred once the page under it moves (`.app-header::before`).
 * The tint and the blur fade out together towards the bottom and reach
 * [Ownify.EdgeFade] past the frame — a scroll edge, not a line — and the
 * veil fades in and out over `--transition-fast`, as on the website.
 */
@Composable
fun HeaderFrame(scrolled: Boolean, modifier: Modifier = Modifier, deep: Boolean = false, content: @Composable BoxScope.() -> Unit) {
    val top = WindowInsets.safeDrawing.asPaddingValues().calculateTopPadding()
    val backdrop = LocalBackdrop.current
    val ground = LocalGround.current
    val blurLayer = rememberBlurLayer()
    val veil by animateFloatAsState(if (scrolled) 1f else 0f, tween(Ownify.FastMs, easing = Ownify.Ease), label = "veil")

    Box(modifier.fillMaxWidth()) {
        if (veil > 0f) {
            Box(
                Modifier
                    .matchParentSize()
                    .layout { measurable, constraints ->
                        // A bar with a title in it reaches further (`.detail__top.is-titled::before`).
                        val extra = (if (deep) Ownify.EdgeFade * 2.5f else Ownify.EdgeFade).roundToPx()
                        val placeable = measurable.measure(Constraints.fixed(constraints.maxWidth, constraints.maxHeight + extra))
                        layout(constraints.maxWidth, constraints.maxHeight) { placeable.place(0, 0) }
                    }
                    .graphicsLayer {
                        alpha = veil
                        compositingStrategy = CompositingStrategy.Offscreen
                    }
                    // mask-image: the blur fades with the tint.
                    .drawWithContent {
                        drawContent()
                        drawRect(
                            Brush.verticalGradient(0f to Color.Black, 0.5f to Color.Black, 1f to Color.Transparent),
                            blendMode = BlendMode.DstIn
                        )
                    }
                    .glassBackdrop(backdrop, RectangleShape, ScrolledFilter, blurLayer, ground)
                    .drawBehind {
                        drawRect(
                            Brush.verticalGradient(
                                0f to Ownify.BgMain.copy(alpha = if (deep) Ownify.VeilDeepTop else 0.95f),
                                (if (deep) 0.62f else 0.5f) to Ownify.BgMain.copy(alpha = if (deep) Ownify.VeilDeepMid else 0.88f),
                                1f to Ownify.BgMain.copy(alpha = 0f)
                            )
                        )
                    }
            )
        }
        Box(Modifier.fillMaxWidth().padding(top = top + Ownify.Space3, bottom = Ownify.Space3 + 1.dp), content = content)
    }
}

/**
 * One of the two header buttons (`.pill--devices` / `.pill--account`): a 46
 * circle of the floating glass. A press settles the pane (scale .96, a
 * shorter shadow). [dot] is the friend-request dot on the rim.
 */
@Composable
fun HeaderButton(onClick: () -> Unit, label: String, dot: Boolean = false, content: @Composable BoxScope.() -> Unit) {
    val interaction = remember { MutableInteractionSource() }
    val pressed by interaction.collectIsPressedAsState()
    val scale by animateFloatAsState(
        if (pressed) 0.96f else 1f,
        tween(if (pressed) Ownify.PressInMs else Ownify.PressOutMs, easing = Ownify.EaseOutStrong),
        label = "button"
    )

    Box(
        Modifier
            .size(Ownify.HeaderButton)
            .graphicsLayer {
                scaleX = scale
                scaleY = scale
            }
    ) {
        Pane(
            Modifier
                .hitArea(
                    Modifier
                        .clickable(interaction, indication = null, role = Role.Button, onClick = onClick)
                        .semantics { contentDescription = label }
                )
                .size(Ownify.HeaderButton),
            style = if (pressed) Panes.HeaderButtonPressed else Panes.HeaderButton,
            content = content
        )
        if (dot) {
            Box(
                Modifier
                    .align(Alignment.TopEnd)
                    .offset(x = (-1).dp, y = 1.dp)
                    .size(11.dp)
                    .drawBehind {
                        // box-shadow: 0 0 8px health 55%
                        drawCircle(
                            Brush.radialGradient(
                                0f to Ownify.Health.copy(alpha = 0.55f),
                                1f to Ownify.Health.copy(alpha = 0f),
                                center = center,
                                radius = size.width / 2f + 8.dp.toPx()
                            ),
                            radius = size.width / 2f + 8.dp.toPx()
                        )
                    }
                    .background(Ownify.Health, CircleShape)
                    // border: 2px solid var(--bg-deep)
                    .border(2.dp, Ownify.BgDeep, CircleShape)
            )
        }
    }
}

/** The bar's title, for tests (it is hidden from TalkBack: the hero's heading is the one read). */
internal const val DETAIL_TITLE = "detail-title"

/**
 * A detail page's own header: the back pill ("Gezondheid", "Doelen",
 * "Instellingen") where the shared header's devices button is.
 */
@Composable
fun DetailHeader(
    back: String,
    backAria: String,
    scrolled: Boolean,
    onBack: () -> Unit,
    modifier: Modifier = Modifier,
    title: String? = null,
    heroBottom: () -> Float = { Float.MAX_VALUE },
    /**
     * The title beside the back pill, giving way to it, rather than centred
     * over it: for pages whose back pill names a page ("Uitdagingen") and
     * whose names run long (challenges.css `.challenge-detail .detail__title`).
     */
    titleBeside: Boolean = false
) {
    val screen = LocalScreen.current
    val still = LocalStillMotion.current
    var barBottom by remember { mutableFloatStateOf(0f) }
    // `.detail__top.is-titled`: the hero's name has gone under the bar.
    // ([scrolled] is a plain parameter, so it is read here, not captured in the derived state.)
    val heroUnder by remember { derivedStateOf { heroBottom() <= barBottom } }
    val titled = title != null && scrolled && heroUnder
    // `.detail__title`: fades in over --transition-fast — also with reduced motion, as the website's does.
    val shown = remember { Animatable(0f) }
    LaunchedEffect(titled, still) {
        val target = if (titled) 1f else 0f
        if (still) shown.snapTo(target) else withContext(Unscaled) { shown.animateTo(target, tween(Ownify.FastMs, easing = Ownify.Ease)) }
    }

    HeaderFrame(scrolled, modifier.onGloballyPositioned { barBottom = it.boundsInWindow().bottom }, deep = titled) {
        Row(
            Modifier.fillMaxWidth().padding(horizontal = (screen.width - screen.shell) / 2),
            verticalAlignment = Alignment.CenterVertically
        ) {
            Pill(back, onClick = onBack, icon = OwnifyIcons.chevronLeft, contentDescription = backAria)
            if (titleBeside && title != null && shown.value > 0f) {
                T(
                    title,
                    OwnifyType.style(Ownify.FsHeadline, FontWeight.SemiBold, tracking = Ownify.TrackingTitle),
                    Modifier
                        .weight(1f)
                        .padding(start = Ownify.Space3)
                        .graphicsLayer { alpha = shown.value }
                        .clearAndSetSemantics { testTag = DETAIL_TITLE },
                    maxLines = 1,
                    ellipsis = true
                )
            }
        }
        // The page's name, beside the back pill; the heading below stays the one read out.
        if (!titleBeside && title != null && shown.value > 0f) {
            T(
                title,
                OwnifyType.style(Ownify.FsHeadline, FontWeight.SemiBold, tracking = Ownify.TrackingTitle),
                Modifier
                    .align(Alignment.Center)
                    .widthIn(max = screen.shell * 0.4f)
                    .graphicsLayer { alpha = shown.value }
                    .clearAndSetSemantics { testTag = DETAIL_TITLE },
                maxLines = 1,
                ellipsis = true
            )
        }
    }
}
