package com.ownify.android.ui.app

import android.content.Context
import android.os.Build
import android.view.HapticFeedbackConstants
import android.view.View
import androidx.compose.animation.animateContentSize
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.animateDpAsState
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.rotate
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.onClick
import androidx.compose.ui.semantics.role
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.em
import com.ownify.android.connection.OwnifyConnection
import com.ownify.android.connection.OwnifyState
import com.ownify.android.connection.OwnifySync
import com.ownify.android.connection.OwnifySyncOutcome
import com.ownify.android.connection.OwnifySyncState
import com.ownify.android.data.AppData
import com.ownify.android.data.AppLoad
import com.ownify.android.data.OwnifyActions
import com.ownify.android.data.OwnifyAppState
import com.ownify.android.data.Outcome
import com.ownify.android.i18n.tr
import com.ownify.android.ui.design.JIcon
import com.ownify.android.ui.design.OwnifyIcons
import com.ownify.android.ui.design.Pane
import com.ownify.android.ui.design.Panes
import com.ownify.android.ui.design.T
import com.ownify.android.ui.design.loopValue
import com.ownify.android.ui.theme.Ownify
import com.ownify.android.ui.theme.OwnifyType

/**
 * What a pull syncs, on any of the five pages, and nothing it cannot
 * (pull-sync.js):
 *
 *   this phone   its Health Connect, read and sent through the one sync
 *                pipeline (OwnifySync.pull) — when this phone is set up to
 *                send. A phone without Health Connect, or without access, has
 *                nothing to send: that is not a failure, and not a sync.
 *   the server   every connected source it can ask for new data on request
 *                (`can_sync`: Polar, api/integrations/polar/sync.php).
 *   then         the pages read again, so what arrived is shown.
 *
 * Health Connect gets a watch's data from the watch's own app, on its own
 * schedule: no app can make a watch sync into it. What a pull does is send
 * what Health Connect has now.
 */
internal suspend fun pullSync(context: Context, data: AppData): PullResult {
    var synced = 0
    var problem: String? = null

    if (OwnifyConnection.state is OwnifyState.Connected) {
        when (OwnifySync.pull(context)) {
            is OwnifySyncOutcome.Synced -> synced++
            OwnifySyncOutcome.ReadFailed, is OwnifySyncOutcome.SendFailed ->
                problem = (OwnifySync.state as? OwnifySyncState.Failed)?.message
                    ?: tr("Er ging iets mis bij het synchroniseren. Probeer het opnieuw.")
            // No Health Connect, no access, no pairing, a token being replaced:
            // nothing of this phone's to send now (the connection handles the last two).
            else -> Unit
        }
    }

    data.settings.integrations
        .filter { it.connected && it.canSync && it.provider?.matches(Regex("^[a-z0-9_]+$")) == true }
        .forEach { source ->
            val outcome = OwnifyActions.form(
                context, "api/integrations/${source.provider}/sync.php", emptyMap(),
                tr("Er ging iets mis bij het synchroniseren. Probeer het opnieuw."), reload = false
            )
            when (outcome) {
                is Outcome.Done -> synced++
                is Outcome.Refused -> if (problem == null) problem = source.label + ": " + outcome.message
                Outcome.SignedOut -> Unit
            }
        }

    // What arrived, on screen — and an honest word when the pages could not be read.
    OwnifyAppState.reload(context)
    (OwnifyAppState.load as? AppLoad.Failed)?.let { if (problem == null) problem = it.message }

    return PullResult(synced, problem)
}

/**
 * One crisp tick for a pull that syncs: Android's own "a gesture's threshold
 * was crossed" (14+), else its confirm (11+), else a key's. The phone's
 * touch-feedback setting decides whether it is felt; a phone without a motor
 * simply has none.
 */
internal fun pullTick(view: View) {
    val constant = when {
        Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE -> HapticFeedbackConstants.GESTURE_THRESHOLD_ACTIVATE
        Build.VERSION.SDK_INT >= Build.VERSION_CODES.R -> HapticFeedbackConstants.CONFIRM
        else -> HapticFeedbackConstants.VIRTUAL_KEY
    }
    view.performHapticFeedback(constant)
}

/**
 * `.pull-sync`: the circle that comes out from under the header as the page
 * is pulled, centred in the room the pull opens — its ring filling towards
 * the point where letting go syncs, its arrow turning up there — and the
 * capsule it opens into: "Aan het synchroniseren…" with a turning arc, then
 * "Gesynchroniseerd" / "Bijgewerkt" with a check, or "Synchroniseren mislukt"
 * with Opnieuw (the whole capsule retries, 44 tall). The header buttons'
 * glass, in both themes.
 */
@Composable
fun PullSyncIndicator(pull: PullSyncState, modifier: Modifier = Modifier) {
    val phase = pull.phase
    val held = pull.held
    val come = if (held) 1f else ((pull.offset - 8f) / 40f).coerceIn(0f, 1f)
    val p = if (held) 1f else (pull.offset / PullSyncState.THRESHOLD).coerceIn(0f, 1f)
    val failed = phase == PullPhase.Failed
    val height by animateDpAsState(if (failed) 44.dp else 36.dp, tween(320, easing = Ownify.EaseOutStrong), label = "pill")
    val turn by animateFloatAsState(if (phase == PullPhase.Armed) 180f else 0f, tween(220, easing = Ownify.EaseOutStrong), label = "arrow")
    val label = when (phase) {
        PullPhase.Syncing -> tr("Aan het synchroniseren…")
        PullPhase.Done -> if (pull.synced) tr("Gesynchroniseerd") else tr("Bijgewerkt")
        PullPhase.Failed -> tr("Synchroniseren mislukt")
        else -> null
    }
    val interaction = remember { MutableInteractionSource() }

    Box(
        modifier.graphicsLayer {
            translationY = ((pull.offset / 2f).dp - height / 2).toPx()
            val scale = 0.6f + 0.4f * come
            scaleX = scale
            scaleY = scale
            alpha = come
        }
    ) {
        Pane(
            Modifier
                .height(height)
                .then(
                    if (failed) Modifier.clickable(interaction, indication = null, role = Role.Button) { pull.start() }
                    else Modifier
                )
                .clearAndSetSemantics {
                    if (label != null) {
                        contentDescription = if (failed) label + ". " + pull.problem.orEmpty() else label
                        liveRegion = LiveRegionMode.Polite
                    }
                    if (failed) {
                        role = Role.Button
                        onClick(tr("Opnieuw")) { pull.start(); true }
                    }
                },
            style = Panes.HeaderButton,
            shape = RoundedCornerShape(50),
            contentAlignment = Alignment.CenterStart
        ) {
            // The width eases inside the glass: on the glass itself the change of size would clip its shadow square.
            Row(
                Modifier.fillMaxHeight().animateContentSize(tween(320, easing = Ownify.EaseOutStrong)).padding(horizontal = 1.dp),
                verticalAlignment = Alignment.CenterVertically
            ) {
                Box(Modifier.size(34.dp), contentAlignment = Alignment.Center) {
                    when (phase) {
                        PullPhase.Done -> JIcon(OwnifyIcons.check, size = 13.dp, color = Ownify.Health)
                        PullPhase.Failed -> JIcon(OwnifyIcons.info, size = 16.dp, color = Ownify.Attention)
                        PullPhase.Syncing -> Spinner()
                        else -> {
                            Ring(p, if (phase == PullPhase.Armed) Ownify.Health else Ownify.TextSecondary)
                            JIcon(
                                OwnifyIcons.chevronDown,
                                Modifier.rotate(turn).graphicsLayer { alpha = 0.4f + 0.6f * p },
                                size = 12.dp,
                                color = if (phase == PullPhase.Armed) Ownify.Health else Ownify.TextSecondary
                            )
                        }
                    }
                }
                if (label != null) {
                    T(
                        label,
                        OwnifyType.style(Ownify.FsSmall, FontWeight.Medium, Ownify.TextSecondary, lineHeight = 1.em),
                        Modifier.padding(end = if (failed) Ownify.Space2 else Ownify.Space4),
                        maxLines = 1
                    )
                }
                if (failed) {
                    Box(Modifier.width(1.dp).fillMaxHeight().background(Ownify.GlassHairline))
                    T(
                        tr("Opnieuw"),
                        OwnifyType.style(Ownify.FsSmall, FontWeight.SemiBold, Ownify.TextPrimary, lineHeight = 1.em),
                        Modifier.padding(start = Ownify.Space3, end = Ownify.Space3 + 3.dp),
                        maxLines = 1
                    )
                }
            }
        }
    }
}

/** The ring that fills as the page comes down, turning with it a little. */
@Composable
private fun Ring(p: Float, color: androidx.compose.ui.graphics.Color) {
    val track = Ownify.ink(0.12f)
    Canvas(Modifier.size(22.dp).rotate(200f * p)) {
        val stroke = Stroke(2.2.dp.toPx(), cap = StrokeCap.Round)
        val inset = 1.1.dp.toPx() + 1.5.dp.toPx()
        val box = androidx.compose.ui.geometry.Size(size.width - 2 * inset, size.height - 2 * inset)
        val at = androidx.compose.ui.geometry.Offset(inset, inset)
        drawArc(track, 0f, 360f, false, at, box, style = Stroke(2.2.dp.toPx()))
        if (p > 0f) drawArc(color, -90f, 360f * p, false, at, box, style = stroke)
    }
}

/** An arc turning on its track: no progress to report, so none pretended. Still with reduced motion. */
@Composable
internal fun Spinner() {
    val angle = loopValue(0f, 360f, infiniteRepeatable(tween(900, easing = LinearEasing)), still = 0f)
    val track = Ownify.ink(0.12f)
    val color = Ownify.Health
    Canvas(Modifier.size(22.dp).rotate(angle)) {
        val inset = 1.1.dp.toPx() + 1.5.dp.toPx()
        val box = androidx.compose.ui.geometry.Size(size.width - 2 * inset, size.height - 2 * inset)
        val at = androidx.compose.ui.geometry.Offset(inset, inset)
        drawArc(track, 0f, 360f, false, at, box, style = Stroke(2.2.dp.toPx()))
        drawArc(color, -90f, 108f, false, at, box, style = Stroke(2.2.dp.toPx(), cap = StrokeCap.Round))
    }
}

