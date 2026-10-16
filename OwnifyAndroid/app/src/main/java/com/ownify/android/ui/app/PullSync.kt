package com.ownify.android.ui.app

import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.tween
import androidx.compose.runtime.Stable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import com.ownify.android.ui.theme.Ownify
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/** Where Gezondheid's pull to sync stands (assets/js/pull-sync.js's `data-state`). */
enum class PullPhase { Idle, Pulling, Armed, Syncing, Done, Failed }

/** How a sync went: how many sources really gave new data, and what went wrong, if anything. */
data class PullResult(val synced: Int, val problem: String?)

/**
 * pull-sync.js, for Compose: Gezondheid pulled down from the top syncs.
 *
 *   pull    the page follows the finger with growing resistance and a glass
 *           circle comes out from under the header, its ring filling towards
 *           [THRESHOLD]; there its arrow turns up — let go
 *   let go  past it: one haptic tick ([onCommit]) and the circle opens into a
 *           capsule under the header, "Aan het synchroniseren…", for as long
 *           as the sync really takes ([work]); the page is held [HELD] down
 *   then    "Gesynchroniseerd" (a source gave new data) or "Bijgewerkt"
 *           (only Ownify's data read again) for a moment, and away; failed:
 *           "Synchroniseren mislukt" and Opnieuw
 *
 * Every distance is in dp — the website's px — and every number is the
 * website's. The gesture is the deck's vertical axis (ShellState's
 * pullController); the work is the Gezondheid page's (PullSyncIndicator.kt),
 * handed in as [work] so it runs here, in the shell's scope, and is never cut
 * short by the page leaving the screen.
 */
@Stable
class PullSyncState(private val scope: CoroutineScope) {

    companion object {
        const val THRESHOLD = 72f   // dp the page must come down for letting go to sync
        const val HELD = 56f        // dp it stays down while the capsule is there
        const val REACH = 180f      // dp it can never pass
        const val SETTLE_MS = 420
        const val MIN_MS = 600L     // the capsule is on screen at least this long, so it can be read
        const val SHOWN_MS = 1400L  // how long "Gesynchroniseerd" stays
        const val FAILED_MS = 9000L // how long a failure stays; a new pull retries too

        /** The page's resistance: one to one at first, then less and less, never past [REACH]. */
        fun rubber(distance: Float): Float = if (distance <= 0f) 0f else REACH * distance / (REACH + distance)
    }

    /** How far the page is pulled down, dp. */
    var offset by mutableStateOf(0f)
        private set

    var phase by mutableStateOf(PullPhase.Idle)
        private set

    /** What went wrong, when [phase] is [PullPhase.Failed]. */
    var problem by mutableStateOf<String?>(null)
        private set

    /** Whether a source gave new data, when [phase] is [PullPhase.Done]. */
    var synced by mutableStateOf(false)
        private set

    /** The sync itself, set by the Gezondheid page with what it needs. */
    var work: (suspend () -> PullResult)? = null

    /** The one haptic tick of a pull that syncs, set by the page with its view. */
    var onCommit: (() -> Unit)? = null

    /** Reduced motion: placed at once, not eased. */
    var still: () -> Boolean = { false }

    private var base = 0f
    private var motion: Job? = null
    private var timer: Job? = null
    private var run: Job? = null

    val held: Boolean get() = phase == PullPhase.Syncing || phase == PullPhase.Done || phase == PullPhase.Failed

    /** A pull may start while nothing is going on, or after a failure (to retry). */
    val canPull: Boolean get() = phase == PullPhase.Idle || phase == PullPhase.Failed

    // --------------------------------------------------------- the gesture

    fun begin() {
        motion?.cancel()
        timer?.cancel()
        base = if (phase == PullPhase.Failed) offset else 0f
        phase = PullPhase.Pulling
    }

    fun move(dy: Float) {
        val px = (base + rubber(dy)).coerceIn(0f, REACH)
        phase = if (px >= THRESHOLD) PullPhase.Armed else PullPhase.Pulling
        offset = px
    }

    /** The lift: syncs when it was past the point, otherwise back. */
    fun end() {
        if (phase == PullPhase.Armed) {
            onCommit?.invoke()
            start()
        } else {
            phase = PullPhase.Idle
            settle(0f)
        }
    }

    fun cancel() {
        phase = PullPhase.Idle
        settle(0f)
    }

    // ------------------------------------------------------------ the sync

    /** Syncs: from a pull, from Opnieuw, or from the accessibility action. */
    fun start() {
        if (phase == PullPhase.Syncing) return
        timer?.cancel()
        problem = null
        phase = PullPhase.Syncing
        settle(HELD)
        val job = work
        run?.cancel()
        run = scope.launch {
            val began = System.currentTimeMillis()
            val result = try {
                job?.invoke() ?: PullResult(0, null)
            } catch (e: kotlinx.coroutines.CancellationException) {
                throw e
            } catch (e: Exception) {
                PullResult(0, com.ownify.android.i18n.tr("Er ging iets mis bij het synchroniseren. Probeer het opnieuw."))
            }
            // The capsule stays for the real duration — and long enough to be read.
            delay((MIN_MS - (System.currentTimeMillis() - began)).coerceAtLeast(0))
            if (result.problem != null) {
                problem = result.problem
                phase = PullPhase.Failed
                timer = scope.launch { delay(FAILED_MS); dismiss() }
            } else {
                synced = result.synced > 0
                phase = PullPhase.Done
                timer = scope.launch { delay(SHOWN_MS); dismiss() }
            }
        }
    }

    fun dismiss() {
        timer?.cancel()
        phase = PullPhase.Idle
        settle(0f)
    }

    private fun settle(target: Float) {
        motion?.cancel()
        if (still()) {
            offset = target
            return
        }
        motion = scope.launch {
            val anim = Animatable(offset)
            anim.animateTo(target, tween(SETTLE_MS, easing = Ownify.EaseOutStrong)) { offset = value }
        }
    }
}
