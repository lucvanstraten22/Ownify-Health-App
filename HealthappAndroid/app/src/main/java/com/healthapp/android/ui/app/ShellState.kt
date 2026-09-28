package com.healthapp.android.ui.app

import androidx.compose.animation.core.animate
import androidx.compose.animation.core.tween
import androidx.compose.foundation.ScrollState
import androidx.compose.runtime.Stable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Rect
import com.healthapp.android.ui.theme.Jolu
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Job
import kotlinx.coroutines.launch

/** A page drilled into: a health area, a goal, a settings screen. */
sealed interface Detail {
    val key: String

    data class HealthArea(val id: String) : Detail {
        override val key get() = "health:$id"
    }

    data class GoalPage(val id: String) : Detail {
        override val key get() = "goal:$id"
    }

    data class SettingsPage(val id: String) : Detail {
        override val key get() = "settings:$id"
    }
}

/** A panel in front of everything, with its scrim (`[data-overlay]` on the website). */
sealed interface Overlay {
    data object Devices : Overlay
    data class Account(val view: String = "main") : Overlay
    data object Wizard : Overlay
    data object DeleteAccount : Overlay
    data class EditField(val key: String) : Overlay
    data class Pairing(val provider: String) : Overlay
}

/**
 * Where the app's layers stand — the rail, the detail layer, the assistant
 * sheet and the panels — and the three controllers that move them with a
 * finger, as page-navigation.js, detail-layer.js and ai-sheet.js do.
 *
 * Every layer is always resting, on its way somewhere, or held by a finger;
 * a released drag always ends on a real position, and a finger can catch a
 * layer mid-way and carry on from where it is.
 */
@Stable
class ShellState(
    val pageIds: List<String>,
    startPage: String,
    private val scope: CoroutineScope
) {
    // ------------------------------------------------------------ the rail

    /** The page shown, or being moved to. */
    var index by mutableIntStateOf(pageIds.indexOf(startPage).coerceAtLeast(0))
        private set

    /** The rail's position in pages, mid-way included. */
    var rail by mutableFloatStateOf(index.toFloat())
        private set

    /** Whether a finger holds the rail: then the tab bar's glass follows [rail], otherwise it goes to [index]. */
    var railHeld by mutableStateOf(false)
        private set

    val currentPage: String get() = pageIds[index]

    /** Each page keeps its own scroll, so leaving it and coming back finds it where it was. */
    val scrolls: Map<String, ScrollState> = pageIds.associateWith { ScrollState(0) }

    private var railDrag: Drag? = null
    private var railJob: Job? = null

    // --------------------------------------------------------- the detail

    /** The detail in front, or on its way in or out. */
    var detail by mutableStateOf<Detail?>(null)
        private set

    /** Whether [detail] is open, or opening. */
    var detailShown by mutableStateOf(false)
        private set

    /** 1 = open, 0 = parked off-screen right. */
    var detailProgress by mutableFloatStateOf(0f)
        private set

    /** A detail starts at the top, never where it was left: a fresh scroll per opening. */
    var detailScroll by mutableStateOf(ScrollState(0))
        private set

    private var detailDrag: Drag? = null
    private var detailJob: Job? = null

    // ---------------------------------------------------------- the sheet

    var aiOpen by mutableStateOf(false)
        private set

    /** 0 = closed, 1 = open. */
    var aiProgress by mutableFloatStateOf(0f)
        private set

    private var aiDrag: Drag? = null
    private var aiJob: Job? = null

    /** Where a vertical swipe may start: the dock to open, the sheet's top to close. */
    var dockZones: List<Rect> = emptyList()
    var sheetTopZone: Rect? = null

    // --------------------------------------------------------- the panels

    val overlays = mutableStateListOf<Overlay>()

    /** Panels on their way out: still drawn while they fade, no longer in front. */
    val leaving = mutableStateListOf<Overlay>()

    val overlayOpen: Boolean get() = overlays.any { it !in leaving }

    fun open(overlay: Overlay) {
        leaving.removeAll { it::class == overlay::class }
        overlays.removeAll { it::class == overlay::class }
        overlays.add(overlay)
    }

    /** Sends [overlay] (the frontmost by default) on its way out; [finish] removes it once it has gone. */
    fun close(overlay: Overlay? = overlays.lastOrNull { it !in leaving }) {
        if (overlay != null && overlay in overlays && overlay !in leaving) leaving.add(overlay)
    }

    fun finish(overlay: Overlay) {
        overlays.remove(overlay)
        leaving.remove(overlay)
    }

    fun closeAll() = overlays.toList().forEach { close(it) }

    /** Replaces an open panel of the same kind, e.g. the account panel's other view. */
    fun replace(overlay: Overlay) {
        val at = overlays.indexOfFirst { it::class == overlay::class }
        if (at >= 0) overlays[at] = overlay else overlays.add(overlay)
    }

    fun isLeaving(overlay: Overlay): Boolean = overlay in leaving

    // ------------------------------------------------------------ actions

    /** A tab: the rail goes there from wherever it is. Re-tapping the page you are on returns you to its top. */
    fun tab(id: String) {
        val target = pageIds.indexOf(id)
        if (target < 0) return
        if (target == index && railDrag == null) {
            scope.launch { scrolls[id]?.animateScrollTo(0) }
            return
        }
        if (detail != null && detailShown) closeDetail()
        goTo(id)
    }

    fun goTo(id: String) {
        val target = pageIds.indexOf(id)
        // A finger on the rail has the say until it lifts.
        if (target < 0 || target == index || railDrag != null) return
        settleRail(target)
    }

    fun openDetail(next: Detail) {
        if (detailDrag != null) return
        val current = detail
        if (current == next && detailShown) return
        if (current != null && current != next && detailShown) return

        val returning = current == next
        detail = next
        if (!returning) detailScroll = ScrollState(0)
        settleDetail(1f)
    }

    fun closeDetail() {
        if (detailDrag != null || detail == null || !detailShown) return
        settleDetail(0f)
    }

    fun openAi() {
        if (aiDrag != null || aiOpen) return
        settleAi(1f)
    }

    fun closeAi() {
        if (aiDrag != null || !aiOpen) return
        settleAi(0f)
    }

    /**
     * System back: the frontmost thing that can close, closes — a panel, the
     * assistant, a detail page. False when there is nothing to close.
     */
    fun back(): Boolean {
        when {
            overlayOpen -> close()
            aiOpen -> closeAi()
            detail != null && detailShown -> closeDetail()
            else -> return false
        }
        return true
    }

    // --------------------------------------------------------- settling

    private fun settleRail(target: Int) {
        val clamped = target.coerceIn(0, pageIds.lastIndex)
        railDrag = null
        railHeld = false
        index = clamped
        railJob?.cancel()
        railJob = scope.launch {
            animate(rail, clamped.toFloat(), animationSpec = tween(Jolu.ScreenMs, easing = Jolu.ScreenEase)) { v, _ -> rail = v }
        }
    }

    private fun settleDetail(target: Float) {
        val opening = target == 1f
        detailDrag = null
        detailShown = opening
        detailJob?.cancel()
        val closing = detail
        detailJob = scope.launch {
            animate(detailProgress, target, animationSpec = tween(Jolu.ScreenMs, easing = Jolu.ScreenEase)) { v, _ -> detailProgress = v }
            if (!opening && detail == closing) detail = null
        }
    }

    private fun settleAi(target: Float) {
        aiDrag = null
        aiOpen = target == 1f
        aiJob?.cancel()
        aiJob = scope.launch {
            animate(aiProgress, target, animationSpec = tween(Jolu.ScreenMs, easing = Jolu.ScreenEase)) { v, _ -> aiProgress = v }
        }
    }

    // ------------------------------------------------------- controllers

    private class Drag(val from: Float, val base: Float) {
        var position = from
    }

    /** Horizontal: the page rail. */
    val railController = object : AxisController {
        override fun canStart(ctx: GestureContext) = !aiOpen && !(detail != null && detailShown) && !overlayOpen && pageIds.size > 1

        override fun begin(ctx: GestureContext) {
            // Caught on its way to a page, the rail stays where it is.
            val from = if (railJob?.isActive == true) rail else index.toFloat()
            railJob?.cancel()
            val base = if (kotlin.math.abs(from - index) < 1f) index.toFloat() else kotlin.math.round(from)
            railDrag = Drag(from, base)
            railHeld = true
            rail = from
        }

        override fun move(ctx: GestureContext) {
            val drag = railDrag ?: return
            // One page either way, and not past the ends.
            val lowest = maxOf(0f, drag.base - 1)
            val highest = minOf(pageIds.lastIndex.toFloat(), drag.base + 1)
            drag.position = (drag.from - ctx.dx / ctx.width).coerceIn(lowest, highest)
            rail = drag.position
        }

        override fun end(ctx: GestureContext) {
            val drag = railDrag ?: return settleRail(index)
            settleRail(GestureRules.resolve(ctx, drag.base, drag.position, ctx.dx, ctx.vx, ctx.width).toInt())
        }

        override fun cancel() = settleRail(railDrag?.base?.toInt() ?: index)
    }

    /** Horizontal, rightward only: closing the detail in front. */
    val detailController = object : AxisController {
        override fun canStart(ctx: GestureContext) = detail != null && detailShown && !aiOpen && !overlayOpen && ctx.dx > 0

        override fun begin(ctx: GestureContext) {
            val from = if (detailJob?.isActive == true) detailProgress else 1f
            detailJob?.cancel()
            detailDrag = Drag(from, 1f)
            detailProgress = from
        }

        override fun move(ctx: GestureContext) {
            val drag = detailDrag ?: return
            drag.position = (drag.from - ctx.dx / ctx.width).coerceIn(0f, 1f)
            detailProgress = drag.position
        }

        override fun end(ctx: GestureContext) {
            val drag = detailDrag ?: return settleDetail(if (detailShown) 1f else 0f)
            settleDetail(GestureRules.resolve(ctx, drag.base, drag.position, ctx.dx, ctx.vx, ctx.width).coerceIn(0f, 1f))
        }

        // Interrupted: it stays open — unless it was taken away meanwhile.
        override fun cancel() = settleDetail(if (detail != null) 1f else 0f)
    }

    /** Vertical: the sheet, up from the dock, down from its top. */
    val aiController = object : AxisController {
        override fun canStart(ctx: GestureContext): Boolean {
            if (overlayOpen) return false
            val at = Offset(ctx.startX, ctx.startY)
            return if (!aiOpen) ctx.dy < 0 && dockZones.any { it.contains(at) }
            else ctx.dy > 0 && sheetTopZone?.contains(at) == true
        }

        override fun begin(ctx: GestureContext) {
            val state = if (aiOpen) 1f else 0f
            val from = if (aiJob?.isActive == true) aiProgress else state
            aiJob?.cancel()
            val base = if (kotlin.math.abs(from - state) < 1f) state else kotlin.math.round(from)
            aiDrag = Drag(from, base)
            aiProgress = from
        }

        override fun move(ctx: GestureContext) {
            val drag = aiDrag ?: return
            drag.position = (drag.from - ctx.dy / ctx.height).coerceIn(0f, 1f)
            aiProgress = drag.position
        }

        override fun end(ctx: GestureContext) {
            val drag = aiDrag ?: return settleAi(if (aiOpen) 1f else 0f)
            settleAi(GestureRules.resolve(ctx, drag.base, drag.position, ctx.dy, ctx.vy, ctx.height).coerceIn(0f, 1f))
        }

        override fun cancel() = settleAi(aiDrag?.base ?: if (aiOpen) 1f else 0f)
    }

    /** The detail layer claims a rightward drag before the rail may (their zones never overlap). */
    val controllers: Map<Axis, List<AxisController>>
        get() = mapOf(Axis.X to listOf(detailController, railController), Axis.Y to listOf(aiController))

    /** Predictive back on a detail page: the page follows the back gesture. */
    fun backProgress(progress: Float) {
        if (detail == null || !detailShown) return
        detailJob?.cancel()
        detailProgress = 1f - progress.coerceIn(0f, 1f)
    }

    fun backCancelled() {
        if (detail != null && detailShown) settleDetail(1f)
    }

}
