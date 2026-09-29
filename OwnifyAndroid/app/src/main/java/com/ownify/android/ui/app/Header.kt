package com.ownify.android.ui.app

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
                    requests == 1 -> ", 1 nieuw vriendverzoek"
                    requests > 1 -> ", $requests nieuwe vriendverzoeken"
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
 * at rest, tinted and blurred once the page under it moves. The switch is
 * immediate, as on the website: a gradient and a backdrop filter do not
 * transition.
 */
@Composable
fun HeaderFrame(scrolled: Boolean, modifier: Modifier = Modifier, content: @Composable BoxScope.() -> Unit) {
    val top = WindowInsets.safeDrawing.asPaddingValues().calculateTopPadding()
    val backdrop = LocalBackdrop.current
    val ground = LocalGround.current
    val blurLayer = rememberBlurLayer()

    Box(modifier.fillMaxWidth()) {
        if (scrolled) {
            Box(
                Modifier
                    .matchParentSize()
                    .glassBackdrop(backdrop, RectangleShape, ScrolledFilter, blurLayer, ground)
                    .drawBehind {
                        drawRect(
                            Brush.verticalGradient(
                                0f to Ownify.BgMain.copy(alpha = 0.95f),
                                0.58f to Ownify.BgMain.copy(alpha = 0.88f),
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
    val scale by animateFloatAsState(if (pressed) 0.96f else 1f, tween(Ownify.FastMs, easing = Ownify.Ease), label = "button")

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
                .size(Ownify.HeaderButton)
                .clickable(interaction, indication = null, role = Role.Button, onClick = onClick)
                .semantics { contentDescription = label },
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

/**
 * A detail page's own header: the back pill ("Gezondheid", "Doelen",
 * "Instellingen") where the shared header's devices button is.
 */
@Composable
fun DetailHeader(back: String, backAria: String, scrolled: Boolean, onBack: () -> Unit, modifier: Modifier = Modifier) {
    val screen = LocalScreen.current
    HeaderFrame(scrolled, modifier) {
        Row(
            Modifier.fillMaxWidth().padding(horizontal = (screen.width - screen.shell) / 2),
            verticalAlignment = Alignment.CenterVertically
        ) {
            Pill(back, onClick = onBack, icon = OwnifyIcons.chevronLeft, contentDescription = backAria)
        }
    }
}
