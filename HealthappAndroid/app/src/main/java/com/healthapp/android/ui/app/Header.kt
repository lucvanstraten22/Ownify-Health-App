package com.healthapp.android.ui.app

import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.tween
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
import androidx.compose.ui.draw.drawWithContent
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
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
import com.healthapp.android.ui.design.GlassFilter
import com.healthapp.android.ui.design.JIcon
import com.healthapp.android.ui.design.JoluIcons
import com.healthapp.android.ui.design.LocalBackdrop
import com.healthapp.android.ui.design.LocalGround
import com.healthapp.android.ui.design.LocalScreen
import com.healthapp.android.ui.design.Pane
import com.healthapp.android.ui.design.Panes
import com.healthapp.android.ui.design.Pill
import com.healthapp.android.ui.design.T
import com.healthapp.android.ui.design.glassBackdrop
import com.healthapp.android.ui.design.rememberBlurLayer
import com.healthapp.android.ui.theme.Jolu
import com.healthapp.android.ui.theme.JoluType

/** `--app-header-space`: safe top + 12 + the 46 button + 12 + the 1 px line. */
@Composable
fun headerSpace(): Dp = WindowInsets.safeDrawing.asPaddingValues().calculateTopPadding() + Jolu.Space3 + Jolu.HeaderButton + Jolu.Space3 + 1.dp

/** `.app-header.is-scrolled`: a solid tint that fades out at the bottom, over the page blurred 30 and saturated 140%. */
private val ScrolledFilter = GlassFilter(Jolu.BlurBar, saturate = 1.4f)

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
                    JIcon(JoluIcons.device, size = Jolu.HeaderIcon, color = Jolu.TextPrimary)
                }
            }
            T(
                appName,
                JoluType.style(screen.fsBrand, FontWeight.SemiBold, tracking = (-0.03).em),
                Modifier.padding(horizontal = Jolu.Space3).semantics { heading() },
                maxLines = 1
            )
            Box(Modifier.weight(1f), contentAlignment = Alignment.CenterEnd) {
                val waiting = when {
                    requests == 1 -> ", 1 nieuw vriendverzoek"
                    requests > 1 -> ", $requests nieuwe vriendverzoeken"
                    else -> ""
                }
                HeaderButton(onClick = onAccount, label = accountAria + waiting, dot = requests > 0) {
                    Avatar(avatar, iconSize = Jolu.HeaderIcon, iconColor = Jolu.TextPrimary, modifier = Modifier.clip(CircleShape))
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
                                0f to Jolu.BgMain.copy(alpha = 0.95f),
                                0.58f to Jolu.BgMain.copy(alpha = 0.88f),
                                1f to Jolu.BgMain.copy(alpha = 0f)
                            )
                        )
                    }
            )
        }
        Box(Modifier.fillMaxWidth().padding(top = top + Jolu.Space3, bottom = Jolu.Space3 + 1.dp), content = content)
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
    val scale by animateFloatAsState(if (pressed) 0.96f else 1f, tween(Jolu.FastMs, easing = Jolu.Ease), label = "button")

    Box(
        Modifier
            .size(Jolu.HeaderButton)
            .graphicsLayer {
                scaleX = scale
                scaleY = scale
            }
    ) {
        Pane(
            Modifier
                .size(Jolu.HeaderButton)
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
                                0f to Jolu.Health.copy(alpha = 0.55f),
                                1f to Jolu.Health.copy(alpha = 0f),
                                center = center,
                                radius = size.width / 2f + 8.dp.toPx()
                            ),
                            radius = size.width / 2f + 8.dp.toPx()
                        )
                    }
                    .clip(CircleShape)
                    .drawWithContent {
                        drawCircle(Jolu.Health)
                    }
                    .border(2.dp, Jolu.BgDeep, CircleShape)
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
            Box(Modifier.size(width = 0.dp, height = Jolu.HeaderButton))
            Pill(back, onClick = onBack, icon = JoluIcons.chevronLeft, contentDescription = backAria)
        }
    }
}
