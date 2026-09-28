package com.healthapp.android.ui.screens.assistant

import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
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
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.drawBehind
import androidx.compose.ui.geometry.CornerRadius
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.PathEffect
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.graphics.drawscope.rotate
import androidx.compose.ui.layout.boundsInRoot
import androidx.compose.ui.layout.onGloballyPositioned
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.em
import com.healthapp.android.data.AiCopy
import com.healthapp.android.ui.app.LocalShell
import com.healthapp.android.ui.design.JStyle
import com.healthapp.android.ui.design.JoluIcons
import com.healthapp.android.ui.design.LocalScreen
import com.healthapp.android.ui.design.LocalStillMotion
import com.healthapp.android.ui.design.Pill
import com.healthapp.android.ui.design.loopValue
import com.healthapp.android.ui.design.T
import com.healthapp.android.ui.screens.GlassOrb
import com.healthapp.android.ui.theme.Jolu
import com.healthapp.android.ui.theme.JoluType
import androidx.compose.foundation.layout.requiredSize
import com.healthapp.android.ui.design.rememberBackdrop
import com.healthapp.android.ui.design.recordBackdrop

/**
 * The assistant (pages/ai.php): a sheet pulled up over the current page.
 * Its top is where it is pulled down again; its middle is the orb and the
 * one line that the assistant is not there yet; its bottom keeps the room
 * the input will take — an outline, never a field.
 */
@Composable
fun AssistantSheet(ai: AiCopy) {
    val shell = LocalShell.current
    val screen = LocalScreen.current
    val density = LocalDensity.current
    val insets = WindowInsets.safeDrawing.asPaddingValues()

    Column(Modifier.fillMaxSize().semantics { contentDescription = ai.title }) {
        // .ai-top — the dismissal area.
        Column(
            Modifier
                .fillMaxWidth()
                .onGloballyPositioned { shell.sheetTopZone = it.boundsInRoot().let { r -> androidx.compose.ui.geometry.Rect(r.left / density.density, r.top / density.density, r.right / density.density, r.bottom / density.density) } }
                .padding(top = maxOf(Jolu.Space3, insets.calculateTopPadding()), bottom = Jolu.Space3),
            horizontalAlignment = Alignment.CenterHorizontally
        ) {
            Box(
                Modifier
                    .padding(top = Jolu.Space2)
                    .size(38.dp, 4.dp)
                    .background(Jolu.white(0.20f), RoundedCornerShape(50))
            )
            Row(Modifier.width(screen.shell).padding(top = Jolu.Space3)) {
                Pill(ai.closeLabel, onClick = shell::closeAi, icon = JoluIcons.chevronDown, contentDescription = ai.closeAria)
            }
        }

        // .ai-main — intentionally empty.
        Box(
            Modifier
                .weight(1f)
                .fillMaxWidth()
                .padding(vertical = Jolu.Space6),
            contentAlignment = Alignment.Center
        ) {
            Column(horizontalAlignment = Alignment.CenterHorizontally) {
                AssistantOrb()
                Spacer(Modifier.height(Jolu.Space6))
                T(
                    ai.title,
                    JoluType.style(screen.fsBrand, FontWeight.SemiBold, tracking = (-0.03).em),
                    Modifier.semantics { heading() },
                    align = TextAlign.Center
                )
                T(ai.status, JStyle.Meta, Modifier.padding(top = Jolu.Space2), align = TextAlign.Center)
            }
        }

        // .ai-composer — reserved, deliberately inert.
        Box(
            Modifier
                .width(screen.shell)
                .align(Alignment.CenterHorizontally)
                .padding(bottom = maxOf(Jolu.Space4, insets.calculateBottomPadding() + Jolu.Space4))
        ) {
            Box(
                Modifier
                    .fillMaxWidth()
                    .heightIn(min = 48.dp)
                    .drawBehind {
                        drawRoundRect(
                            Jolu.white(0.10f),
                            cornerRadius = CornerRadius(Jolu.RadiusMd.toPx()),
                            style = Stroke(1.dp.toPx(), pathEffect = PathEffect.dashPathEffect(floatArrayOf(3.dp.toPx(), 3.dp.toPx())))
                        )
                    }
                    .padding(horizontal = Jolu.Space4, vertical = Jolu.Space3)
                    .semantics { contentDescription = ai.composerAria },
                contentAlignment = Alignment.Center
            ) {
                T(ai.composerNote, JStyle.Tiny)
            }
        }
    }
}

/**
 * `.orb`: a dotted ring turning once every 72 s round a green light and a
 * breathing sphere of glass — the dashboard's "no data yet" idiom.
 */
@Composable
private fun AssistantOrb() {
    val still = LocalStillMotion.current
    val screen = LocalScreen.current
    val size = (screen.width * 0.36f).coerceIn(120.dp, 152.dp)
    val turn = loopValue(0f, 360f, infiniteRepeatable(tween(72_000, easing = LinearEasing)))
    val breathe = loopValue(1f, 1.035f, infiniteRepeatable(tween(3_500, easing = Jolu.Ease), RepeatMode.Reverse))

    val (behind, behindLayer) = rememberBackdrop()
    Box(Modifier.size(size), contentAlignment = Alignment.Center) {
      // What the glass looks through: the light and the ring, recorded.
      Box(Modifier.requiredSize(size * 1.68f).recordBackdrop(behind, behindLayer), contentAlignment = Alignment.Center) {
        // .orb__glow — inset -34%
        Canvas(Modifier.size(size * 1.68f)) {
            drawCircle(
                Brush.radialGradient(
                    0f to Jolu.Health.copy(alpha = 0.16f),
                    0.26f to Jolu.Health.copy(alpha = 0.08f),
                    0.48f to Jolu.Health.copy(alpha = 0.02f),
                    0.72f to Jolu.Health.copy(alpha = 0f),
                    center = center,
                    radius = this.size.width * 0.70710677f
                )
            )
        }
        Canvas(Modifier.size(size)) {
            val unit = this.size.width / 160f
            rotate(if (still) 0f else turn) {
                drawCircle(
                    Color.White.copy(alpha = 0.26f),
                    radius = 74f * unit,
                    style = Stroke(3.5f * unit, cap = StrokeCap.Round, pathEffect = PathEffect.dashPathEffect(floatArrayOf(0.5f * unit, 9f * unit)))
                )
            }
        }
      }
        // .orb__core — inset 16%
        GlassOrb(size * 0.68f, if (still) 1f else breathe, behind)
    }
}
