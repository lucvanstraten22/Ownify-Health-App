package com.healthapp.android.ui.screens

import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Canvas
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
import androidx.compose.foundation.layout.fillMaxWidth
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
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.graphics.drawscope.rotate
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
import com.healthapp.android.ui.design.JStyle
import com.healthapp.android.ui.design.LocalScreen
import com.healthapp.android.ui.design.LocalStillMotion
import com.healthapp.android.ui.design.Pane
import com.healthapp.android.ui.design.Panes
import com.healthapp.android.ui.design.T
import com.healthapp.android.ui.design.chWidth
import com.healthapp.android.ui.design.loopValue
import com.healthapp.android.ui.design.press
import com.healthapp.android.ui.theme.Jolu
import com.healthapp.android.ui.theme.JoluType
import kotlinx.coroutines.delay
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.ui.graphics.BlurEffect
import androidx.compose.ui.graphics.TileMode
import androidx.compose.ui.graphics.ShaderBrush
import androidx.compose.ui.graphics.Shader
import androidx.compose.ui.graphics.toArgb
import androidx.compose.ui.platform.LocalGraphicsContext
import com.healthapp.android.ui.design.BoxShadow
import com.healthapp.android.ui.design.drawBoxShadows
import com.healthapp.android.ui.design.blurRadiusFor
import kotlin.math.pow
import kotlin.math.sqrt
import androidx.compose.foundation.layout.requiredSize
import com.healthapp.android.ui.design.Backdrop
import com.healthapp.android.ui.design.GlassFilter
import com.healthapp.android.ui.design.glassBackdrop
import com.healthapp.android.ui.design.rememberBackdrop
import com.healthapp.android.ui.design.rememberBlurLayer
import com.healthapp.android.ui.design.recordBackdrop
import com.healthapp.android.ui.design.LocalGround

/**
 * The opening screen (pages/welcome.php): the mark — the score ring's
 * gradient drawn three quarters round the assistant's glass orb — the app's
 * name, one line, and two ways on where the tab bar will be once signed in:
 * the same width, the same height, the same distance from the bottom.
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
                .heightIn(min = screen.height - insets.calculateTopPadding() - insets.calculateBottomPadding() - Jolu.TabbarHeight - Jolu.DockGap)
                .padding(
                    top = if (short) Jolu.Space5 else Jolu.Space6,
                    bottom = if (short) Jolu.Space5 else (screen.height * 0.16f).coerceIn(Jolu.Space6, 136.dp)
                ),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.Center
        ) {
            Rise(0) {
                WelcomeMark(if (short) 104.dp else (screen.width * 0.40f).coerceIn(132.dp, 168.dp))
            }
            Spacer(Modifier.height(if (short) Jolu.Space4 else Jolu.Space6))
            Rise(90) {
                T(
                    appName,
                    JoluType.style(
                        (screen.width.value * 0.105f).coerceIn(36f, 48f).sp,
                        FontWeight.Bold,
                        tracking = (-0.04).em,
                        lineHeight = 1.05.em
                    ),
                    Modifier.semantics { heading() },
                    align = TextAlign.Center
                )
            }
            Rise(160) {
                val style = JoluType.style(Jolu.FsBody, color = Jolu.TextSecondary, lineHeight = 1.45.em)
                T(
                    subtitle,
                    style,
                    Modifier.padding(top = Jolu.Space3).widthIn(max = chWidth(style, 26f)),
                    align = TextAlign.Center
                )
            }
            if (below != null) {
                Rise(200) { below() }
            }
        }

        // .welcome__actions — the tab bar's footprint, split in two.
        Rise(260) {
            Row(
                Modifier
                    .width(min(screen.width * 0.94f, 440.dp))
                    .padding(bottom = insets.calculateBottomPadding() + Jolu.DockGap),
                horizontalArrangement = Arrangement.spacedBy(Jolu.Space3)
            ) {
                WelcomeButton(login, primary = false, onClick = onLogin, modifier = Modifier.weight(1f))
                WelcomeButton(register, primary = true, onClick = onRegister, modifier = Modifier.weight(1f))
            }
        }
    }
}

/** One of the two ways on: a pane of the tab bar's glass, 54 high; Registreren takes the app's green. */
@Composable
private fun WelcomeButton(label: String, primary: Boolean, onClick: () -> Unit, modifier: Modifier) {
    val interaction = remember { MutableInteractionSource() }
    Pane(
        modifier
            .press(interaction)
            .height(Jolu.TabbarHeight)
            .clickable(interaction, indication = null, role = Role.Button, onClick = onClick),
        style = if (primary) Panes.WelcomePrimary else Panes.WelcomeButton,
        shape = RoundedCornerShape(50),
        primary = primary
    ) {
        T(
            label,
            JoluType.style(Jolu.FsBody, FontWeight.SemiBold, tracking = (-0.01).em),
            Modifier.padding(horizontal = Jolu.Space4),
            maxLines = 1
        )
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
            progress.animateTo(1f, tween(700, easing = Jolu.EaseOut))
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
 * `.welcome__mark`: a 7-wide track, the gradient arc drawn in to three
 * quarters over 1400 ms (then turning once every 90 s), the green light
 * behind the glass, and the orb breathing every 7 s.
 */
@Composable
private fun WelcomeMark(size: Dp) {
    val still = LocalStillMotion.current
    val draw = remember { Animatable(if (still) 1f else 0f) }
    val turn = loopValue(0f, 360f, infiniteRepeatable(tween(90_000, delayMillis = 1_600, easing = LinearEasing)))
    val breathe = loopValue(1f, 1.035f, infiniteRepeatable(tween(3_500, delayMillis = 1_600, easing = Jolu.Ease), RepeatMode.Reverse))

    LaunchedEffect(Unit) {
        if (!still) {
            delay(200)
            draw.animateTo(1f, tween(1_400, easing = Jolu.EaseOut))
        }
    }

    val (behind, behindLayer) = rememberBackdrop()
    Box(Modifier.size(size), contentAlignment = Alignment.Center) {
      // What the glass looks through: the light and the ring, recorded.
      Box(Modifier.requiredSize(size * 1.6f).recordBackdrop(behind, behindLayer), contentAlignment = Alignment.Center) {
        // .welcome__glow — inset -30%, a soft green light with a long falloff.
        Canvas(Modifier.size(size * 1.6f)) {
            drawCircle(
                Brush.radialGradient(
                    0f to Jolu.Health.copy(alpha = 0.18f),
                    0.28f to Jolu.Health.copy(alpha = 0.08f),
                    0.50f to Jolu.Health.copy(alpha = 0.02f),
                    0.72f to Jolu.Health.copy(alpha = 0f),
                    center = center,
                    radius = this.size.width * 0.70710677f
                )
            )
        }

        // The ring.
        Canvas(Modifier.size(size)) {
            val unit = this.size.width / 160f
            val r = 68f * unit
            val angle = if (still) 0f else turn
            rotate(angle) {
                drawCircle(Color.White.copy(alpha = 0.07f), radius = r, style = Stroke(7f * unit))
                val sweep = 270f * draw.value
                if (sweep > 0f) {
                    val topLeft = Offset(center.x - r, center.y - r)
                    drawArc(
                        brush = Brush.linearGradient(
                            0f to Jolu.Health, 0.55f to Jolu.Activity, 1f to Jolu.Nutrition,
                            start = Offset(topLeft.x, topLeft.y + 2 * r),
                            end = Offset(topLeft.x + 2 * r, topLeft.y)
                        ),
                        startAngle = -90f,
                        sweepAngle = sweep,
                        useCenter = false,
                        topLeft = topLeft,
                        size = Size(2 * r, 2 * r),
                        style = Stroke(7f * unit, cap = StrokeCap.Round)
                    )
                }
            }
        }
      }

        GlassOrb(size * 0.58f, if (still) 1f else breathe, behind)
    }
}

/**
 * The glass orb (`.welcome__orb` / `.orb__core`): a sphere of glass — a
 * light radial wash, a hairline edge, a rim of light on top and depth under
 * it, and a soft specular sheen.
 */
@Composable
fun GlassOrb(size: Dp, scale: Float, backdrop: Backdrop?) {
    val shadows = LocalGraphicsContext.current.shadowContext
    val blurLayer = rememberBlurLayer()
    val ground = LocalGround.current
    Box(
        Modifier
            .size(size)
            .graphicsLayer {
                scaleX = scale
                scaleY = scale
            }
            // backdrop-filter: blur(14px) saturate(125%) — the light and the
            // ring behind it, seen through the glass instead of under it.
            .glassBackdrop(backdrop, CircleShape, OrbGlass, blurLayer, ground)
            // In CSS's order: the shadow outside it, the background, the
            // inset shadows, the border.
            .drawBehind {
                val r = this.size.minDimension / 2f
                // box-shadow: 0 20px 42px rgba(0,0,0,.28) — around the sphere, never under it
                drawBoxShadows(CircleShape, listOf(BoxShadow(y = 20.dp, blur = 42.dp, color = Color.Black.copy(alpha = 0.28f))), shadows)
                // radial-gradient(120% 120% at 30% 22%, …)
                drawCircle(
                    Brush.radialGradient(
                        0f to Color.White.copy(alpha = 0.24f),
                        0.34f to Color.White.copy(alpha = 0.09f),
                        0.62f to Color.White.copy(alpha = 0.02f),
                        1f to Color.White.copy(alpha = 0.06f),
                        center = Offset(this.size.width * 0.30f, this.size.height * 0.22f),
                        radius = this.size.width * 1.2f
                    ),
                    radius = r
                )
                // inset 0 2px 1px rgba(255,255,255,.24): the rim of light on top;
                // inset 0 -20px 30px rgba(0,0,0,.20): the depth underneath
                drawBoxShadows(
                    CircleShape,
                    listOf(
                        BoxShadow(y = 2.dp, blur = 1.dp, color = Color.White.copy(alpha = 0.24f), inset = true),
                        BoxShadow(y = (-20).dp, blur = 30.dp, color = Color.Black.copy(alpha = 0.20f), inset = true)
                    ),
                    shadows
                )
                // border: 1px solid rgba(255,255,255,.16)
                drawCircle(Color.White.copy(alpha = 0.16f), radius = r - 0.5.dp.toPx(), style = Stroke(1.dp.toPx()))
            }
    ) {
        // ::after — inset 9% 20% 54% 17%: the specular sheen, blurred 3px
        Box(
            Modifier
                .padding(start = size * 0.17f, top = size * 0.09f, end = size * 0.20f, bottom = size * 0.54f)
                .fillMaxSize()
                .graphicsLayer {
                    val radius = blurRadiusFor(3.dp.toPx())
                    renderEffect = BlurEffect(radius, radius, TileMode.Decal)
                }
                .drawBehind { drawOval(sheen(this.size)) }
        )
    }
}

/** The orb's `backdrop-filter`. */
private val OrbGlass = GlassFilter(14.dp, saturate = 1.25f)

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
    return object : ShaderBrush() {
        override fun createShader(size: Size): Shader =
            android.graphics.RadialGradient(
                cx, cy, rx,
                intArrayOf(Color.White.copy(alpha = 0.30f).toArgb(), Color.White.copy(alpha = 0f).toArgb()),
                floatArrayOf(0f, 0.72f),
                android.graphics.Shader.TileMode.CLAMP
            ).apply { setLocalMatrix(android.graphics.Matrix().apply { setScale(1f, ry / rx, cx, cy) }) }
    }
}
