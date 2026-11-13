package com.ownify.android.ui

import android.content.Context
import android.os.SystemClock
import android.view.MotionEvent
import android.view.View
import android.view.WindowManager
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.test.SemanticsNodeInteraction
import androidx.compose.ui.test.SemanticsNodeInteractionCollection
import androidx.compose.ui.test.performScrollTo
import org.robolectric.shadow.api.Shadow
import org.robolectric.shadows.ShadowWindowManagerImpl

/*
 * What the flow tests share about the wheel and the screen (50.0, Phase 5/6).
 */

/**
 * A tap outside the wheel that is open (Wheel.kt WheelPopup), as a finger
 * does: on the popup's own window, which is what closes it — a touch sent to
 * its content would land inside. A list's or a date's choice is made then,
 * on both apps (48.0).
 */
internal fun closeWheel(context: Context) {
    val windows: List<View> = Shadow.extract<ShadowWindowManagerImpl>(context.getSystemService(WindowManager::class.java)).views
    val wheel = windows.last { it.javaClass.name.contains("Popup") }
    val at = SystemClock.uptimeMillis()
    wheel.dispatchTouchEvent(MotionEvent.obtain(at, at, MotionEvent.ACTION_DOWN, -20f, -20f, 0))
    wheel.dispatchTouchEvent(MotionEvent.obtain(at, at + 50, MotionEvent.ACTION_UP, -20f, -20f, 0))
}

/**
 * Scrolled into view first only when it is off the screen and something
 * scrolls it. A node already on the screen is tapped where it is: a scroll
 * is animated, and a touch while it still moves stops the scroll instead of
 * tapping — as on a phone. Nor is a node in a fixed bar scrolled (nothing
 * scrolls it, and the failure writes out the whole app's semantics).
 */
internal fun SemanticsNodeInteraction.onScreen(): SemanticsNodeInteraction {
    val node = try { fetchSemanticsNode() } catch (_: Throwable) { return this }
    var top = node
    while (top.parent != null) top = top.parent!!
    // The floating tab bar covers the foot of the screen: a node under it is not where a finger can reach.
    val bar = 120f * node.layoutInfo.density.density
    val clear = top.size.height - bar
    if (node.boundsInRoot.top >= 0f && node.boundsInRoot.bottom <= clear) return this
    var parent = node.parent
    while (parent != null) {
        if (parent.config.contains(SemanticsActions.ScrollBy)) {
            try {
                if (node.boundsInRoot.top >= 0f && node.boundsInRoot.bottom <= top.size.height) {
                    // On the screen but under the bar: up by just enough to clear it.
                    val dy = node.boundsInRoot.bottom - clear + 16f * node.layoutInfo.density.density
                    parent.config[SemanticsActions.ScrollBy].action?.invoke(0f, dy)
                } else performScrollTo()
            } catch (_: Throwable) { }
            break
        }
        parent = parent.parent
    }
    return this
}

/**
 * The first of these that sits across the screen, not on a page beside it:
 * a pager lays its pages side by side, so the same button can be on a page
 * off to the left or right, where a tap at its place never reaches it.
 */
internal fun SemanticsNodeInteractionCollection.inSight(): SemanticsNodeInteraction {
    val nodes = fetchSemanticsNodes()
    val width = nodes.firstOrNull()?.let { n -> var t = n; while (t.parent != null) t = t.parent!!; t.size.width.toFloat() } ?: 0f
    val i = nodes.indexOfFirst { it.boundsInRoot.left >= 0f && it.boundsInRoot.right <= width }
    return get(if (i >= 0) i else 0).onScreen()
}
