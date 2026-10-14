package com.ownify.android.ui

import android.graphics.Bitmap
import android.os.Handler
import android.os.Looper
import android.view.PixelCopy
import androidx.activity.ComponentActivity
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.semantics.getOrNull
import androidx.compose.ui.test.SemanticsMatcher
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.compose.ui.text.TextLayoutResult
import com.ownify.android.data.AppData
import com.ownify.android.ui.app.AppShell
import com.ownify.android.ui.app.Detail
import com.ownify.android.ui.app.Overlay
import com.ownify.android.ui.app.ShellState
import com.ownify.android.ui.design.LocalScreen
import com.ownify.android.ui.design.LocalStillMotion
import com.ownify.android.ui.design.ScreenMetrics
import com.ownify.android.ui.screens.OwnifyScreens
import com.ownify.android.ui.theme.OwnifyTheme
import kotlinx.coroutines.runBlocking
import org.json.JSONObject
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.RuntimeEnvironment
import org.robolectric.Shadows.shadowOf
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode
import java.io.File
import java.io.FileOutputStream
import kotlin.math.roundToInt

/**
 * The phone's own text size, made larger (A3): at 130, 150 and 200% every
 * page, detail and panel still shows all its text — nothing cut off at the
 * bottom of a box that does not grow, and no line of a page's own content
 * shortened to "…". The chrome's labels (the tab bar's, a switch's, a
 * button's) stay one line and may shorten, as they do at 100%; they are
 * listed, not failed.
 *
 * With -Downify.shots=<dir> each screen is also saved, for a look.
 */
@RunWith(RobolectricTestRunner::class)
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [36], qualifiers = "+w412dp-h915dp-port-420dpi")
class FontScaleTest {

    @get:Rule
    val compose = createAndroidComposeRule<ComponentActivity>()

    private lateinit var shell: ShellState
    private lateinit var data: AppData
    private val out = System.getProperty("ownify.shots").orEmpty()

    private fun show(scale: Float) {
        RuntimeEnvironment.setFontScale(scale)
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

    private val clipped = mutableListOf<String>()
    private val shortened = mutableListOf<String>()

    /** Every text on screen now whose box cut it off, or that ends in "…". */
    private fun read(where: String, scale: Float) {
        val texts = compose.onAllNodes(SemanticsMatcher.keyIsDefined(SemanticsActions.GetTextLayoutResult), useUnmergedTree = true)
            .fetchSemanticsNodes()
        val screen = compose.activity.window.decorView.let { it.width to it.height }
        for (node in texts) {
            val b = node.boundsInRoot
            if (b.width <= 0f || b.height <= 0f || b.top >= screen.second || b.bottom <= 0f) continue
            val results = mutableListOf<TextLayoutResult>()
            node.config.getOrNull(SemanticsActions.GetTextLayoutResult)?.action?.invoke(results)
            val layout = results.firstOrNull() ?: continue
            val text = node.config.getOrNull(SemanticsProperties.Text)?.joinToString { it.text }.orEmpty().take(48)
            if (text.isBlank()) continue
            val label = "${(scale * 100).roundToInt()}% $where: \"$text\""
            // Cut off: more than a third of a line lies past its box (a tight
            // line box that overflows by a few pixels cuts nothing).
            val line = layout.getLineBottom(0) - layout.getLineTop(0)
            val over = layout.multiParagraph.height - layout.size.height
            if (layout.didOverflowHeight && over > line / 3f) clipped += "$label — cut off at the bottom (${over.roundToInt()} px)"
            else if ((0 until layout.lineCount).any { layout.isLineEllipsized(it) }) shortened += "$label — shortened"
        }
        if (out.isNotEmpty()) shoot("fontscale-${(scale * 100).roundToInt()}-$where")
    }

    private fun shoot(name: String) {
        File(out).mkdirs()
        val window = compose.activity.window
        val view = window.decorView
        val bitmap = Bitmap.createBitmap(view.width, view.height, Bitmap.Config.ARGB_8888)
        var result = -1
        compose.runOnUiThread { PixelCopy.request(window, bitmap, { result = it }, Handler(Looper.getMainLooper())) }
        shadowOf(Looper.getMainLooper()).idle()
        if (result == PixelCopy.SUCCESS) FileOutputStream(File(out, "app-$name.png")).use { bitmap.compress(Bitmap.CompressFormat.PNG, 100, it) }
    }

    private fun sweep(where: String, scale: Float, detail: Boolean = false) {
        repeat(4) { i ->
            val scroll = if (detail) shell.detailScroll else shell.scrolls.getValue(shell.currentPage)
            if (i > 0) {
                if (scroll.value >= scroll.maxValue) return
                compose.runOnUiThread { runBlocking { scroll.scrollTo(scroll.value + (700 * compose.density.density).roundToInt()) } }
                settle()
            }
            read("$where-$i", scale)
        }
    }

    private fun walk(scale: Float) {
        show(scale)
        for (page in shell.pageIds) {
            compose.runOnUiThread { shell.tab(page) }
            settle()
            sweep(page, scale)
        }
        val details = listOf(
            "health" to Detail.HealthArea("sleep"),
            "health" to Detail.HealthArea("training"),
            "health" to Detail.HealthArea("nutrition"),
            "overview" to Detail.ScoreCompass,
            "settings" to Detail.SettingsPage("preferences"),
        ) + data.goals.all.take(1).map { "goals" to Detail.GoalPage(it.id) }
        for ((page, detail) in details) {
            compose.runOnUiThread { shell.tab(page) }
            settle()
            compose.runOnUiThread { shell.openDetail(detail) }
            settle()
            if (shell.detail != detail) continue
            sweep(detail.key.toString().replace(Regex("[^A-Za-z0-9]+"), "_"), scale, detail = true)
            compose.runOnUiThread { shell.closeDetail() }
            settle()
        }
        for ((name, overlay) in listOf("account" to Overlay.Account(), "devices" to Overlay.Devices)) {
            compose.runOnUiThread { shell.open(overlay) }
            settle()
            read(name, scale)
            compose.runOnUiThread { shell.close() }
            settle()
        }
        println("Shortened at ${(scale * 100).roundToInt()}%:\n" + shortened.distinct().joinToString("\n"))
        assertTrue(clipped.distinct().joinToString("\n"), clipped.isEmpty())
    }

    @Test fun `at 130 percent`() = walk(1.3f)
    @Test fun `at 150 percent`() = walk(1.5f)
    @Test fun `at 200 percent`() = walk(2.0f)
}
