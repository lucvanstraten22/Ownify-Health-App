package com.ownify.android.ui

import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Rect
import androidx.compose.ui.semantics.SemanticsNode
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.semantics.getOrNull
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.isEnabled
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onRoot
import com.ownify.android.data.AppData
import com.ownify.android.ui.app.AppShell
import com.ownify.android.ui.app.Detail
import com.ownify.android.ui.app.Overlay
import com.ownify.android.ui.app.ShellState
import com.ownify.android.ui.design.LocalScreen
import com.ownify.android.ui.design.LocalStillMotion
import com.ownify.android.ui.design.ScreenMetrics
import com.ownify.android.ui.screens.OwnifyScreens
import com.ownify.android.ui.theme.Ownify
import com.ownify.android.ui.theme.OwnifyTheme
import kotlinx.coroutines.runBlocking
import org.json.JSONObject
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import kotlin.math.roundToInt

/**
 * Every control takes taps over at least [Ownify.HitMin] (48 dp, Android's
 * minimum) however small it is drawn — the website gives its controls 44 px
 * the same way (components.css, `--hit-min`). Nothing is enlarged: a tap
 * just outside a small control, within that minimum, still reaches it, or
 * reaches a neighbour drawn there.
 *
 * Checked the way a finger is: the app's own hit test, at the middle of the
 * margin each control is short by, on every page, the details and the panels.
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [36], qualifiers = "+w412dp-h915dp-port-420dpi")
class TouchTargetTest {

    @get:Rule
    val compose = createComposeRule()

    private lateinit var shell: ShellState
    private lateinit var data: AppData

    private fun show() {
        val state = JSONObject(javaClass.classLoader!!.getResource("state-demo.json").readText()).getJSONObject("data")
        data = AppData.parse(state)
        compose.setContent {
            OwnifyTheme {
                BoxWithConstraints(Modifier.fillMaxSize()) {
                    CompositionLocalProvider(LocalScreen provides ScreenMetrics(maxWidth, maxHeight), LocalStillMotion provides true) {
                        AppShell(data, OwnifyScreens.shell, onShell = { shell = it })
                    }
                }
            }
        }
        compose.waitForIdle()
    }

    private fun settle() {
        compose.mainClock.advanceTimeBy(1_500)
        compose.waitForIdle()
    }

    // ------------------------------------------------- the app's hit test

    private val layoutNodeOf = SemanticsNode::class.java.getMethod("getLayoutNode\$ui")
    private val hitClass = Class.forName("androidx.compose.ui.node.HitTestResult")
    private val coordinatorOf = Class.forName("androidx.compose.ui.Modifier\$Node").getMethod("getCoordinator\$ui")
    private val coordinatorLayout = Class.forName("androidx.compose.ui.node.NodeCoordinator").getMethod("getLayoutNode")
    private val hitTest = Class.forName("androidx.compose.ui.node.LayoutNode").getMethod(
        "hitTest-6fMxITs\$ui", Long::class.javaPrimitiveType, hitClass, Int::class.javaPrimitiveType, Boolean::class.javaPrimitiveType
    )

    /** The layout nodes a touch at [at] (root px) is delivered to. */
    private fun hits(at: Offset): Set<Any> {
        val root = layoutNodeOf.invoke(compose.onRoot().fetchSemanticsNode())
        val result = hitClass.getConstructor().newInstance()
        val packed = (java.lang.Float.floatToRawIntBits(at.x).toLong() shl 32) or (java.lang.Float.floatToRawIntBits(at.y).toLong() and 0xffffffffL)
        hitTest.invoke(root, packed, result, 1 /* PointerType.Touch */, true /* as a touch is dispatched */)
        return (result as List<*>).mapNotNull { node -> coordinatorOf.invoke(node)?.let { coordinatorLayout.invoke(it) } }.toSet()
    }

    private fun why(at: Offset): String {
        val root = layoutNodeOf.invoke(compose.onRoot().fetchSemanticsNode())
        val result = hitClass.getConstructor().newInstance()
        val packed = (java.lang.Float.floatToRawIntBits(at.x).toLong() shl 32) or (java.lang.Float.floatToRawIntBits(at.y).toLong() and 0xffffffffL)
        hitTest.invoke(root, packed, result, 1, true)
        return (result as List<*>).joinToString(" > ") { it!!.javaClass.simpleName + "@" + System.identityHashCode(coordinatorOf.invoke(it)?.let { c -> coordinatorLayout.invoke(c) }) % 1000 }
    }

    private val SemanticsNode.label: String
        get() = (config.getOrNull(SemanticsProperties.ContentDescription)?.firstOrNull()
            ?: config.getOrNull(SemanticsProperties.Text)?.firstOrNull()?.text
            ?: "?").take(40)

    private val misses = mutableListOf<String>()
    private val stolen = mutableListOf<String>()
    private val smallest = mutableMapOf<String, String>()

    /** Taps around every small control on screen now, at [where]. */
    private fun probe(where: String, covered: Boolean = false) {
        val px = compose.density.density
        val min = Ownify.HitMin.value * px
        val screen = compose.onRoot().fetchSemanticsNode().boundsInRoot
        val clickables = compose.onAllNodes(hasClickAction() and isEnabled()).fetchSemanticsNodes()
        val owners = clickables.associateBy { layoutNodeOf.invoke(it) }
        val headerBottom = 110f * px
        // The dock — the assistant's grip and the tab bar under it — floats over the page's foot.
        val dockTop = clickables.filter { it.label.startsWith("OwnifyAI openen") }.minOfOrNull { it.boundsInRoot.top } ?: screen.bottom

        for (node in clickables) {
            val b: Rect = node.boundsInRoot
            if (b.width <= 0f || b.height <= 0f) continue
            // Only what is wholly on screen: a control half under the edge is reached by scrolling.
            if (b.width < node.size.width - 1 || b.height < node.size.height - 1) continue
            // A tap on it lands on it, never on a neighbour's margin (what the
            // header, the dock or a panel covers aside).
            if (!covered && b.center.y > headerBottom && b.center.y < dockTop) {
                val centre = hits(b.center)
                // The control the tap ends on: the deepest one along its path.
                val by = centre.lastOrNull { it in owners }?.let { owners[it] }
                if (layoutNodeOf.invoke(node) !in centre && by != null) {
                    stolen += "$where: \"${node.label}\" — a tap on it reaches \"${by.label}\""
                }
            }
            if (b.width >= min && b.height >= min) continue
            smallest[node.label] = "${(b.width / px).roundToInt()}×${(b.height / px).roundToInt()} dp"

            val probes = buildList {
                val dy = (min - b.height) / 4f
                if (dy >= 1f) { add(Offset(b.center.x, b.top - dy) to "above"); add(Offset(b.center.x, b.bottom + dy) to "below") }
                val dx = (min - b.width) / 4f
                if (dx >= 1f) { add(Offset(b.left - dx, b.center.y) to "left"); add(Offset(b.right + dx, b.center.y) to "right") }
            }
            for ((at, side) in probes) {
                if (!screen.contains(at)) continue
                val hit = hits(at)
                val me = layoutNodeOf.invoke(node)
                if (me in hit) continue
                // A neighbour drawn — or nearer — there takes it: the tap still lands on a control.
                if (hit.any { it in owners }) continue
                misses += "$where: \"${node.label}\" (${smallest[node.label]}) — a tap just $side reaches nothing" +
                    " [" + why(at) + "]"
            }
        }
    }

    /** The page, top to bottom, a screen at a time. */
    private fun sweep(where: String, detail: Boolean = false) {
        repeat(6) { i ->
            val scroll = if (detail) shell.detailScroll else shell.scrolls.getValue(shell.currentPage)
            if (i > 0) {
                if (scroll.value >= scroll.maxValue) return
                compose.runOnUiThread { runBlocking { scroll.scrollTo(scroll.value + (600 * compose.density.density).roundToInt()) } }
                settle()
            }
            probe("$where#$i")
        }
    }

    @Test
    fun `every control on every page, detail and panel takes taps over at least 48 dp`() {
        show()
        for (page in shell.pageIds) {
            compose.runOnUiThread { shell.tab(page) }
            settle()
            sweep(page)
        }

        val details = listOf(
            "health" to Detail.HealthArea("sleep"),
            "health" to Detail.HealthArea("training"),
            "health" to Detail.HealthArea("nutrition"),
            "overview" to Detail.ScoreCompass,
            "settings" to Detail.SettingsPage("account"),
            "settings" to Detail.SettingsPage("preferences"),
            "settings" to Detail.SettingsPage("privacy")
        ) + data.goals.all.take(1).map { "goals" to Detail.GoalPage(it.id) }
        for ((page, detail) in details) {
            compose.runOnUiThread { shell.tab(page) }
            settle()
            compose.runOnUiThread { shell.openDetail(detail) }
            settle()
            if (shell.detail != detail) continue
            sweep(detail.toString(), detail = true)
            compose.runOnUiThread { shell.closeDetail() }
            settle()
        }

        for (overlay in listOf(Overlay.Account(), Overlay.Devices)) {
            compose.runOnUiThread { shell.open(overlay) }
            settle()
            probe(overlay.toString(), covered = true)
            compose.runOnUiThread { shell.close() }
            settle()
        }

        println("Drawn under 48 dp: " + smallest.entries.joinToString { "${it.key} ${it.value}" })
        println("Stolen: " + stolen.distinct().joinToString("\n"))
        assertTrue(misses.distinct().joinToString("\n"), misses.isEmpty())
        assertTrue(stolen.distinct().joinToString("\n"), stolen.isEmpty())
    }
}
