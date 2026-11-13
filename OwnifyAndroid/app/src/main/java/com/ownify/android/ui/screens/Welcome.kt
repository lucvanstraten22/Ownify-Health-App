package com.ownify.android.ui.screens

import com.ownify.android.ui.design.SplitButton
import com.ownify.android.ui.design.SplitPart
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Image
import androidx.compose.ui.graphics.FilterQuality
import androidx.compose.ui.graphics.ImageBitmap
import androidx.compose.ui.res.imageResource
import com.ownify.android.R
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.asPaddingValues
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawing
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.drawBehind
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.em
import androidx.compose.ui.unit.min
import androidx.compose.ui.unit.sp
import com.ownify.android.ui.design.LocalScreen
import com.ownify.android.ui.design.LocalStillMotion
import com.ownify.android.ui.design.Pane
import com.ownify.android.ui.design.Panes
import com.ownify.android.ui.design.T
import com.ownify.android.ui.design.chWidth
import com.ownify.android.ui.design.press
import com.ownify.android.ui.theme.Ownify
import com.ownify.android.ui.theme.OwnifyType
import kotlinx.coroutines.delay
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.ui.graphics.BlurEffect
import androidx.compose.ui.graphics.TileMode
import androidx.compose.ui.graphics.ShaderBrush
import androidx.compose.ui.graphics.Shader
import androidx.compose.ui.graphics.toArgb
import androidx.compose.ui.platform.LocalGraphicsContext
import com.ownify.android.ui.design.BoxShadow
import com.ownify.android.ui.design.drawBoxShadows
import com.ownify.android.ui.design.blurRadiusFor
import kotlin.math.pow
import kotlin.math.sqrt
import com.ownify.android.ui.design.Backdrop
import com.ownify.android.ui.design.GlassFilter
import com.ownify.android.ui.design.glassBackdrop
import com.ownify.android.ui.design.rememberBlurLayer
import com.ownify.android.ui.design.GroundPlacement

/**
 * The opening screen (pages/welcome.php): the mark — the Ownify logo — the
 * app's name, one line, and two ways on where the tab bar will be once signed
 * in: the same width, the same height, the same distance from the bottom.
 *
 * [below] is what an Android phone adds under the line: this phone's status
 * when it is paired with a code but not signed in (see docs/PARITY.md).
 */
@Composable
fun WelcomeScreen(
    appName: String,
    subtitle: String,
    login: String,
    register: String,
    onLogin: () -> Unit,
    onRegister: () -> Unit,
    below: (@Composable () -> Unit)? = null
) {
    val screen = LocalScreen.current
    val insets = WindowInsets.safeDrawing.asPaddingValues()
    val short = screen.height < 560.dp

    Column(
        Modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(top = insets.calculateTopPadding()),
        horizontalAlignment = Alignment.CenterHorizontally
    ) {
        // .welcome__stage — a little high: more room under the block than over it.
        Column(
            Modifier
                .width(screen.shell)
                // flex: 1 0 auto — at least the room above the buttons.
                .heightIn(min = screen.height - insets.calculateTopPadding() - insets.calculateBottomPadding() - Ownify.TabbarHeight - Ownify.DockGap)
                .padding(
                    top = if (short) Ownify.Space5 else Ownify.Space6,
                    bottom = if (short) Ownify.Space5 else (screen.height * 0.16f).coerceIn(Ownify.Space6, 136.dp)
                ),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.Center
        ) {
            Rise(0) {
                WelcomeMark(if (short) 104.dp else (screen.width * 0.40f).coerceIn(132.dp, 168.dp))
            }
            Spacer(Modifier.height(if (short) Ownify.Space4 else Ownify.Space6))
            Rise(90) {
                T(
                    appName,
                    OwnifyType.style(Ownify.FsDisplay, FontWeight.Bold),
                    Modifier.semantics { heading() },
                    align = TextAlign.Center
                )
            }
            Rise(160) {
                val style = OwnifyType.style(Ownify.FsBody, color = Ownify.TextSecondary)
                T(
                    subtitle,
                    style,
                    Modifier.padding(top = Ownify.Space3).widthIn(max = chWidth(style, 26f)),
                    align = TextAlign.Center
                )
            }
            if (below != null) {
                Rise(200) { below() }
            }
        }

        // .welcome__actions — the tab bar's footprint, split in two.
        Rise(260) {
            // 50.0: the split button, at the tab bar's height, Body text.
            SplitButton(
                back = SplitPart(login, onLogin),
                forward = SplitPart(register, onRegister),
                modifier = Modifier
                    .width(min(screen.width * 0.94f, 440.dp))
                    .padding(bottom = insets.calculateBottomPadding() + Ownify.DockGap),
                minHeight = Ownify.TabbarHeight,
                textSize = Ownify.FsBody
            )
        }
    }
}

/** `welcome-rise`: 700 ms ease-out from 12 dp lower and transparent, after [delayMs]. */
@Composable
private fun Rise(delayMs: Int, content: @Composable () -> Unit) {
    val still = LocalStillMotion.current
    val progress = remember { Animatable(if (still) 1f else 0f) }
    LaunchedEffect(Unit) {
        if (!still) {
            delay(delayMs.toLong())
            progress.animateTo(1f, tween(700, easing = Ownify.EaseOut))
        }
    }
    Box(
        Modifier.graphicsLayer {
            alpha = progress.value
            translationY = (1f - progress.value) * 12.dp.toPx()
        }
    ) { content() }
}

/**
 * `.welcome__mark`: the Ownify logo, icon-only, as supplied
 * (docs/BRANDING.md) — as large as the ring it took the place of, 89.4% of
 * the mark — and decorative: the name under it says "Ownify" already.
 */
@Composable
private fun WelcomeMark(size: Dp) {
    val logo = ImageBitmap.imageResource(R.drawable.ownify_logo)
    Box(Modifier.size(size), contentAlignment = Alignment.Center) {
        Image(
            bitmap = logo,
            contentDescription = null,
            modifier = Modifier.size(size * 0.894f),
            filterQuality = FilterQuality.High
        )
    }
}

/**
 * `radial-gradient(ellipse at 42% 24%, rgba(255,255,255,.30), transparent 72%)`:
 * an ellipse the shape `closest-side` would give it, grown until it reaches
 * the farthest corner (CSS's default size), fading out at 72% of that.
 */
private fun sheen(size: Size): Brush {
    val cx = size.width * 0.42f
    val cy = size.height * 0.24f
    val sx = minOf(cx, size.width - cx)
    val sy = minOf(cy, size.height - cy)
    val k = sqrt((maxOf(cx, size.width - cx) / sx).pow(2) + (maxOf(cy, size.height - cy) / sy).pow(2))
    val rx = sx * k
    val ry = sy * k
    val light = intArrayOf(Ownify.glint(0.30f).toArgb(), Ownify.glint(0f).toArgb())
    return object : ShaderBrush() {
        override fun createShader(size: Size): Shader =
            android.graphics.RadialGradient(
                cx, cy, rx,
                light,
                floatArrayOf(0f, 0.72f),
                android.graphics.Shader.TileMode.CLAMP
            ).apply { setLocalMatrix(android.graphics.Matrix().apply { setScale(1f, ry / rx, cx, cy) }) }
    }
}
