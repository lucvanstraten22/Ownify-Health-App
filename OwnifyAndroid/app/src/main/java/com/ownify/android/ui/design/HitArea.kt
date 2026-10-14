package com.ownify.android.ui.design

import androidx.compose.ui.Modifier
import androidx.compose.ui.layout.HorizontalAlignmentLine
import androidx.compose.ui.layout.Measurable
import androidx.compose.ui.layout.MeasureResult
import androidx.compose.ui.layout.MeasureScope
import androidx.compose.ui.layout.VerticalAlignmentLine
import androidx.compose.ui.node.LayoutModifierNode
import androidx.compose.ui.node.ModifierNodeElement
import androidx.compose.ui.platform.InspectorInfo
import androidx.compose.ui.unit.Constraints
import com.ownify.android.ui.theme.Ownify
import kotlin.math.max

/**
 * `--hit-min` (components.css): a control drawn smaller than [Ownify.HitMin]
 * takes taps over at least that much — an invisible margin, centred on it, as
 * far as it falls short, and none along a side already long enough. Nothing
 * grows, moves or is drawn: what is around it is laid out round the size it
 * is drawn at.
 *
 * Compose reaches a small control just outside its edge by itself, but only
 * where nothing else is hit outright — not over the page scrolling under the
 * header and the dock. Here the margin is the control's own: [control] is
 * its `clickable` (and its semantics), which then answers over the whole
 * area; what draws it follows this. [across] false widens it up and down
 * only — for options side by side, which must not reach over each other.
 */
fun Modifier.hitArea(control: Modifier, across: Boolean = true): Modifier =
    this then HitFrameElement then control then HitAreaElement(across)

// The drawn size, handed from the inside out: what the frame lays out as.
private val DrawnWidth = VerticalAlignmentLine(::max)
private val DrawnHeight = HorizontalAlignmentLine(::max)

/** Outside: lays out at the size drawn, and lets the hit area stand out round it. */
private data object HitFrameElement : ModifierNodeElement<HitFrameNode>() {
    override fun create() = HitFrameNode()
    override fun update(node: HitFrameNode) = Unit
    override fun InspectorInfo.inspectableProperties() {
        name = "hitArea"
    }
}

private class HitFrameNode : Modifier.Node(), LayoutModifierNode {
    override fun MeasureScope.measure(measurable: Measurable, constraints: Constraints): MeasureResult {
        val area = measurable.measure(constraints)
        val width = area[DrawnWidth].takeIf { it >= 0 } ?: area.width
        val height = area[DrawnHeight].takeIf { it >= 0 } ?: area.height
        return layout(width, height) {
            area.place((width - area.width) / 2, (height - area.height) / 2)
        }
    }
}

/** Inside: at least [Ownify.HitMin] each way (up and down only, unless [across]), the control centred in it. */
private data class HitAreaElement(val across: Boolean) : ModifierNodeElement<HitAreaNode>() {
    override fun create() = HitAreaNode(across)
    override fun update(node: HitAreaNode) {
        node.across = across
    }
}

private class HitAreaNode(var across: Boolean) : Modifier.Node(), LayoutModifierNode {
    override fun MeasureScope.measure(measurable: Measurable, constraints: Constraints): MeasureResult {
        val drawn = measurable.measure(constraints)
        val min = Ownify.HitMin.roundToPx()
        val width = if (across) max(drawn.width, min) else drawn.width
        val height = max(drawn.height, min)
        return layout(width, height, mapOf(DrawnWidth to drawn.width, DrawnHeight to drawn.height)) {
            drawn.place((width - drawn.width) / 2, (height - drawn.height) / 2)
        }
    }
}
