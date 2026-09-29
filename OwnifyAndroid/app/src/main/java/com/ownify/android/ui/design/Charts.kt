package com.ownify.android.ui.design

import androidx.compose.runtime.Composable
import androidx.compose.runtime.remember
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Matrix
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.StrokeJoin
import androidx.compose.ui.graphics.asAndroidPath
import androidx.compose.ui.graphics.asComposePath
import androidx.compose.ui.graphics.drawscope.DrawScope
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.graphics.vector.PathParser

/*
 * The website's charts are drawn on the server — lib/health.php and
 * lib/goal-chart.php write SVG path data in a fixed view box — and shown
 * stretched to the chart's box (`preserveAspectRatio="none"`). The app draws
 * the same paths the same way; it never works out a point itself.
 */

/** The server's SVG path data, parsed once. Anything unreadable is left out. */
@Composable
fun rememberSvgPaths(data: List<String>): List<Path> = remember(data) {
    data.mapNotNull { d -> runCatching { PathParser().parsePathString(d).toPath() }.getOrNull() }
}

/** [path], in view-box units, stretched to [size] as `preserveAspectRatio="none"` stretches it. */
fun Path.stretched(viewBox: Size, size: Size): Path {
    if (viewBox.width <= 0f || viewBox.height <= 0f) return this
    val out = Path()
    out.addPath(this)
    out.transform(Matrix().apply { scale(size.width / viewBox.width, size.height / viewBox.height) })
    return out
}

/**
 * A chart line — `stroke-width` in dp whatever the stretch
 * (`vector-effect: non-scaling-stroke`), round caps and joins — drawn on up
 * to [share] of its length: `stroke-dasharray` its length and the offset
 * released, as health-trend.js draws it.
 */
fun DrawScope.drawChartLine(path: Path, color: Color, width: Float, share: Float = 1f) {
    drawChartLine(path, SolidColor(color), width, share)
}

fun DrawScope.drawChartLine(path: Path, brush: Brush, width: Float, share: Float = 1f) {
    val stroke = Stroke(width, cap = StrokeCap.Round, join = StrokeJoin.Round)
    if (share >= 1f) {
        drawPath(path, brush, style = stroke)
        return
    }
    if (share <= 0f) return

    val source = path.asAndroidPath()
    val total = android.graphics.PathMeasure(source, false).let { measure ->
        var length = 0f
        do {
            length += measure.length
        } while (measure.nextContour())
        length
    }

    var left = total * share
    val drawn = android.graphics.Path()
    val measure = android.graphics.PathMeasure(source, false)
    do {
        if (left <= 0f) break
        val length = measure.length
        measure.getSegment(0f, minOf(length, left), drawn, true)
        left -= length
    } while (measure.nextContour())

    drawPath(drawn.asComposePath(), brush, style = stroke)
}
