package com.ownify.android.ui

import android.graphics.Bitmap
import android.os.Handler
import android.os.Looper
import android.view.PixelCopy
import androidx.activity.ComponentActivity
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.Modifier
import androidx.compose.ui.layout.LayoutInfo
import androidx.compose.ui.layout.boundsInRoot
import androidx.compose.ui.layout.positionInRoot
import androidx.compose.ui.text.TextLayoutResult
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.semantics.SemanticsNode
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.semantics.getOrNull
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.isRoot
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasSetTextAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.compose.ui.test.onLast
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performTouchInput
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performTextInput
import com.ownify.android.data.AppData
import com.ownify.android.data.OwnifyAssistant
import com.ownify.android.connection.OwnifyApi
import com.ownify.android.connection.OwnifyConnection
import com.ownify.android.ui.app.AppShell
import com.ownify.android.ui.app.Avatars
import com.ownify.android.ui.app.Detail
import com.ownify.android.ui.app.Overlay
import com.ownify.android.ui.app.ShellState
import com.ownify.android.ui.design.LocalScreen
import com.ownify.android.ui.design.LocalStillMotion
import com.ownify.android.ui.design.ScreenMetrics
import com.ownify.android.ui.screens.OwnifyScreens
import com.ownify.android.ui.screens.setup.SetupScreen
import com.ownify.android.ui.theme.Ownify
import com.ownify.android.ui.theme.OwnifyMode
import com.ownify.android.ui.theme.OwnifyTheme
import java.io.File
import java.io.FileOutputStream
import kotlin.math.roundToInt
import kotlinx.coroutines.runBlocking
import org.json.JSONArray
import org.json.JSONObject
import org.junit.After
import org.junit.Assume.assumeTrue
import org.junit.Before
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode
import org.robolectric.Shadows.shadowOf

/**
 * Screenshots of the app for the side-by-side check with the website (the
 * web shots come from a browser at the same size, on the same account, at
 * the same moment). Only runs when asked for:
 *
 *   gradlew :app:testDebugUnitTest --tests '*ScreenshotCapture*' \
 *     -Downify.shots=<dir> -Downify.state=<state.json> -Downify.server=<url> \
 *     -Downify.state.free=<state.json of an account with a free goal slot>
 *     [-Downify.theme=light: in White Mode]
 *
 * 412 × 915 dp at 420 dpi (2.625 px to the dp, a common phone), and the
 * browser at the same size and device pixel ratio, so both round to the
 * same pixels a phone has; the text and layout dumps are in dp.
 */
@RunWith(RobolectricTestRunner::class)
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [36], qualifiers = "w412dp-h915dp-port-420dpi")
class ScreenshotCapture {

    @get:Rule
    val compose = createAndroidComposeRule<ComponentActivity>()

    private val out = System.getProperty("ownify.shots").orEmpty()
    private val state = System.getProperty("ownify.state").orEmpty()
    private val server = System.getProperty("ownify.server").orEmpty()
    private val stateFree = System.getProperty("ownify.state.free").orEmpty()

    private lateinit var shell: ShellState

    @Before
    fun only() {
        assumeTrue("screenshots not asked for", out.isNotEmpty() && state.isNotEmpty())
        File(out).mkdirs()
        if (server.isNotEmpty()) OwnifyConnection.api = OwnifyApi(server)
        Ownify.use(OwnifyMode.of(System.getProperty("ownify.theme")))
    }

    @After
    fun dark() = Ownify.use(OwnifyMode.DARK)

    private fun data(file: String = state): AppData = AppData.parse(JSONObject(File(file).readText()).getJSONObject("data"))

    /** The pictures the pages show, fetched before the first frame so the shot has them. */
    private fun preload(data: AppData) = runBlocking {
        val paths = buildList {
            add(data.auth.avatar)
            add(data.settings.profile.avatar)
            data.community.friends.forEach { add(it.avatar) }
            data.community.pending.forEach { add(it.avatar) }
            data.community.sent.forEach { add(it.avatar) }
            data.community.boards.values.flatMap { it.values }.flatMap { it.entries }.forEach { add(it.avatar) }
        }.filterNotNull().distinct()
        paths.forEach { Avatars.load(it) }
    }

    private fun frame(content: @Composable () -> Unit) {
        compose.setContent {
            OwnifyTheme {
                BoxWithConstraints(Modifier.fillMaxSize()) {
                    CompositionLocalProvider(LocalScreen provides ScreenMetrics(maxWidth, maxHeight), LocalStillMotion provides true) {
                        content()
                    }
                }
            }
        }
    }

    private fun app(file: String = state, then: ShellState.() -> Unit = {}) {
        val data = data(file)
        preload(data)
        frame { AppShell(data, OwnifyScreens.shell, onShell = { shell = it }) }
        compose.waitForIdle()
        compose.runOnUiThread { shell.then() }
        settle()
    }

    private fun settle() {
        compose.mainClock.advanceTimeBy(2_000)
        compose.waitForIdle()
    }

    /** Scrolled [dp] down, as the browser's scrollTop (CSS px) is. */
    private fun scroll(dp: Int, detail: Boolean = false) {
        val px = (dp * compose.density.density).roundToInt()
        compose.runOnUiThread {
            runBlocking {
                if (detail) shell.detailScroll.scrollTo(px) else shell.scrolls.getValue(shell.currentPage).scrollTo(px)
            }
        }
        settle()
    }

    private fun shoot(name: String) {
        settle()
        FileOutputStream(File(out, "app-$name.png")).use { capture().compress(Bitmap.CompressFormat.PNG, 100, it) }
        File(out, "app-$name.json").writeText(texts().toString(1))
        if (System.getProperty("ownify.tree").orEmpty().isNotEmpty()) File(out, "app-$name.tree.txt").writeText(tree())
    }

    /**
     * Every layout node on screen with its box and what its modifiers are, for
     * holding a stretch of the app against the website's element tree
     * (tree.mjs). LayoutNode's children are internal, so they are read by
     * reflection; a debugging aid only.
     */
    private fun tree(): String {
        val lines = StringBuilder()
        val density = compose.density.density
        val getChildren = Class.forName("androidx.compose.ui.node.LayoutNode").methods
            .first { it.name.startsWith("getChildren") && it.parameterCount == 0 && List::class.java.isAssignableFrom(it.returnType) }
        fun label(info: LayoutInfo): String = info.getModifierInfo().mapNotNull { m ->
            val name = m.modifier.javaClass.simpleName.removeSuffix("Element").removeSuffix("ModifierNode")
            when {
                name.startsWith("Padding") -> "pad" + m.modifier.toString().substringAfter("(", "").take(0)
                name in setOf("SemanticsModifierNode", "AppendedSemantics", "ClearAndSetSemantics", "CombinedClickable", "Clickable", "HoverableElement") -> null
                else -> name
            }
        }.distinct().joinToString(",")
        fun walk(info: LayoutInfo, depth: Int) {
            if (!info.isPlaced) return
            val p = info.coordinates.positionInRoot()
            val bounds = info.coordinates.boundsInRoot()
            if (bounds.height <= 0f || bounds.width <= 0f) return
            val text = info.semanticsText()
            lines.append("  ".repeat(depth))
                .append("y=%.1f h=%.1f x=%.1f w=%.1f ".format(p.y / density, info.height / density, p.x / density, info.width / density))
                .append(label(info))
                .append(if (text != null) " \"${text.take(30)}\"" else "")
                .append('\n')
            @Suppress("UNCHECKED_CAST")
            (getChildren.invoke(info) as List<LayoutInfo>).forEach { walk(it, depth + 1) }
        }
        var root: LayoutInfo = compose.onAllNodes(isRoot(), useUnmergedTree = true).fetchSemanticsNodes().first().layoutInfo
        while (root.parentInfo != null) root = root.parentInfo!!
        walk(root, 0)
        return lines.toString()
    }

    /** The text a BasicText node draws, read off its modifier element. */
    private fun LayoutInfo.semanticsText(): String? = getModifierInfo().firstNotNullOfOrNull { m ->
        val element = m.modifier
        if (!element.javaClass.simpleName.startsWith("Text")) return@firstNotNullOfOrNull null
        runCatching {
            element.javaClass.getDeclaredField("text").apply { isAccessible = true }.get(element)?.toString()
        }.getOrNull()
    }

    /**
     * Every text on screen with its first line's box (from its text layout),
     * for layout-diff.mjs to hold against the website's DOM.
     */
    private fun texts(): JSONArray {
        val list = JSONArray()
        val density = compose.density.density
        val screen = compose.onAllNodes(isRoot(), useUnmergedTree = true).fetchSemanticsNodes().first().size
        fun walk(node: SemanticsNode) {
            val text = node.config.getOrNull(SemanticsProperties.Text)?.joinToString(" ") { it.text }
            val layouts = mutableListOf<TextLayoutResult>()
            node.config.getOrNull(SemanticsActions.GetTextLayoutResult)?.action?.invoke(layouts)
            val layout = layouts.firstOrNull()
            // Clipped to nothing: scrolled out of its scroller.
            if (!text.isNullOrBlank() && layout != null && layout.lineCount > 0 && node.boundsInRoot.height > 0f) {
                // Where the text itself sits: inside any padding its own modifiers add.
                val p = node.layoutInfo.coordinates.positionInRoot()
                // The browser measures a line with the letter-spacing after its last
                // letter; Android leaves that off. Add it, so the widths compare.
                val style = layout.layoutInput.style
                val spacing = with(compose.density) {
                    when {
                        style.letterSpacing.isEm -> style.letterSpacing.value * style.fontSize.toPx()
                        style.letterSpacing.isSp -> style.letterSpacing.toPx()
                        else -> 0f
                    }
                }
                val line = layout.getLineRight(0) - layout.getLineLeft(0) + spacing
                list.put(
                    JSONObject()
                        .put("text", text)
                        // End-aligned text reports its line against the width it was
                        // measured at; it is drawn inside its own box.
                        .put("x", (p.x + minOf(layout.getLineLeft(0), node.layoutInfo.width - line)) / density)
                        .put("cy", (p.y + (layout.getLineTop(0) + layout.getLineBottom(0)) / 2f) / density)
                        .put("w", line / density)
                        .put("lines", layout.lineCount)
                        .put("size", layout.layoutInput.style.fontSize.value)
                        .put("weight", layout.layoutInput.style.fontWeight?.weight ?: 400)
                        .put("ls", layout.layoutInput.style.let { st ->
                            val ls = st.letterSpacing
                            when {
                                ls.isEm -> ls.value * st.fontSize.value
                                ls.isSp -> ls.value
                                else -> 0f
                            }
                        })
                )
            }
            // `clearAndSetSemantics { }` with nothing set is the shell hiding a
            // layer (a covered page, a closed sheet): TalkBack does not reach
            // what is under it, and neither does this list.
            val hides = node.config.isClearingSemantics && node.config.none() && node.size.width >= screen.width && node.size.height >= screen.height
            if (!hides) node.children.forEach { walk(it) }
        }
        compose.onAllNodes(isRoot(), useUnmergedTree = true).fetchSemanticsNodes().forEach { walk(it) }
        return list
    }

    /**
     * The window as the phone would draw it: PixelCopy, which Robolectric
     * renders in hardware (robolectric.pixelCopyRenderMode), so layers and
     * RenderEffect blur are in the picture.
     */
    private fun capture(): Bitmap {
        val window = compose.activity.window
        val view = window.decorView
        val bitmap = Bitmap.createBitmap(view.width, view.height, Bitmap.Config.ARGB_8888)
        var result = -1
        compose.runOnUiThread {
            PixelCopy.request(window, bitmap, { result = it }, Handler(Looper.getMainLooper()))
        }
        shadowOf(Looper.getMainLooper()).idle()
        check(result == PixelCopy.SUCCESS) { "PixelCopy: $result" }
        return bitmap
    }

    // ------------------------------------------------------------------ pages

    @Test
    fun overview() {
        app()
        shoot("overview")
        scroll(760)
        shoot("overview-2")
    }

    @Test
    fun health() {
        app { tab("health") }
        shoot("health")
        scroll(560)
        shoot("health-2")
    }

    @Test
    fun goals() {
        app { tab("goals") }
        shoot("goals")
        scroll(600)
        shoot("goals-2")
    }

    @Test
    fun community() {
        app { tab("community") }
        shoot("community")
    }

    @Test
    fun settings() {
        app { tab("settings") }
        shoot("settings")
        scroll(600)
        shoot("settings-2")
    }

    // ---------------------------------------------------------------- details

    private fun detail(page: String, detail: Detail, name: String, vararg scrolls: Int) {
        app {
            tab(page)
        }
        compose.runOnUiThread { shell.openDetail(detail) }
        settle()
        shoot(name)
        scrolls.forEachIndexed { i, px ->
            scroll(px, detail = true)
            shoot("$name-${i + 2}")
        }
    }

    @Test fun scoreCompass() = detail("overview", Detail.ScoreCompass, "compass", 700, 1400, 2100)

    /** A history card in each of its periods: the card brought into view, then 7 dagen … 1 jaar. */
    private fun periods(name: String, title: String) {
        compose.onNode(hasText(title)).performScrollTo()
        settle()
        for (period in listOf("7 dagen", "30 dagen", "90 dagen", "1 jaar")) {
            compose.onAllNodes(hasText(period) and hasClickAction()).onLast().performClick()
            settle()
            shoot("$name-${period.substringBefore(' ')}")
        }
    }

    /** Gezondheid's Verloop — Slaap, Voeding and Training — in each period, then a day of its week read by a finger. */
    @Test
    fun healthPeriods() {
        app { tab("health") }
        periods("health-history", "Verloop")
        compose.onAllNodes(hasText("7 dagen") and hasClickAction()).onLast().performClick()
        settle()
        val plot = compose.onNode(hasContentDescription("per dag, de afgelopen 7 dagen", substring = true))
        plot.performTouchInput { down(Offset(width * 0.66f, height / 2f)) }
        compose.mainClock.advanceTimeBy(200)
        compose.waitForIdle()
        FileOutputStream(File(out, "app-health-history-reading.png")).use { capture().compress(Bitmap.CompressFormat.PNG, 100, it) }
        plot.performTouchInput { up() }
    }

    /** The Scorekompas's Wat er verandert, in each period. */
    @Test
    fun compassPeriods() {
        app { tab("overview") }
        compose.runOnUiThread { shell.openDetail(Detail.ScoreCompass) }
        settle()
        periods("compass-history", "Wat er verandert")
    }
    @Test fun detailSleep() = detail("health", Detail.HealthArea("sleep"), "detail-sleep", 760, 1520)
    @Test fun detailNutrition() = detail("health", Detail.HealthArea("nutrition"), "detail-nutrition", 760, 1520)
    @Test fun detailTraining() = detail("health", Detail.HealthArea("training"), "detail-training", 760, 1520)
    @Test fun goal301() = detail("goals", Detail.GoalPage("301"), "goal-301", 760, 1520, 2280)
    @Test fun goal303() = detail("goals", Detail.GoalPage("303"), "goal-303", 760, 1520)
    @Test fun goal302() = detail("goals", Detail.GoalPage("302"), "goal-302", 760, 1520)
    @Test fun goal300() = detail("goals", Detail.GoalPage("300"), "goal-300", 760)
    @Test fun settingsAccount() = detail("settings", Detail.SettingsPage("account"), "settings-account", 760, 1520)
    @Test fun settingsDevices() = detail("settings", Detail.SettingsPage("devices"), "settings-devices", 600)
    @Test fun settingsPrivacy() = detail("settings", Detail.SettingsPage("privacy"), "settings-privacy", 700)
    @Test fun settingsNotifications() = detail("settings", Detail.SettingsPage("notifications"), "settings-notifications")
    @Test fun settingsTheme() = detail("settings", Detail.SettingsPage("theme"), "settings-theme")
    @Test fun settingsLanguage() = detail("settings", Detail.SettingsPage("language"), "settings-language")
    @Test fun settingsUnits() = detail("settings", Detail.SettingsPage("units"), "settings-units")
    @Test fun settingsWeek() = detail("settings", Detail.SettingsPage("week"), "settings-week")
    @Test fun settingsAccessibility() = detail("settings", Detail.SettingsPage("accessibility"), "settings-accessibility")
    @Test fun settingsAbout() = detail("settings", Detail.SettingsPage("about"), "settings-about", 600)

    // ----------------------------------------------------------------- panels

    /**
     * [overlay] over [page] (or over the detail [over]), first scrolled [down]
     * dp, as far as a person scrolls to reach what opens it — as the browser
     * does to tap it.
     */
    private fun panel(overlay: Overlay, name: String, page: String = "overview", over: Detail? = null, down: Int = 0) {
        app { tab(page) }
        if (over != null) {
            compose.runOnUiThread { shell.openDetail(over) }
            settle()
        }
        if (down > 0) scroll(down, detail = over != null)
        compose.runOnUiThread { shell.open(overlay) }
        settle()
        shoot(name)
    }

    @Test fun account() = panel(Overlay.Account(), "account")
    @Test fun devices() = panel(Overlay.Devices, "devices")
    /**
     * The six steps, walked the way web-shots.mjs walks them: Activiteit, a
     * suggested name, Mijlpaal, kept by hand, 100 km and higher is better, a
     * month. On an account with a free goal slot (the demo account's three
     * are taken, and then the website's + is disabled).
     */
    @Test
    fun wizard() {
        assumeTrue("no account with a free goal slot (-Downify.state.free)", stateFree.isNotEmpty())
        app(stateFree) { tab("goals") }
        compose.runOnUiThread { shell.open(Overlay.Wizard) }
        settle()
        shoot("wizard-1")
        tap("Activiteit")
        shoot("wizard-1b")
        tap("Volgende")
        shoot("wizard-2")
        tap("10.000 stappen per dag")
        tap("Mijlpaal")
        shoot("wizard-2b")
        tap("Volgende")
        shoot("wizard-3")
        tap("Geen data mogelijk")
        tap("Volgende")
        shoot("wizard-4")
        type("Welk resultaat wil je halen?", "100")
        type("Eenheid", "km")
        tap("Hoger is beter")
        shoot("wizard-4b")
        tap("Volgende")
        shoot("wizard-5")
        tap("Maand")
        tap("Volgende")
        shoot("wizard-6")
    }

    private fun tap(text: String) {
        compose.onAllNodes(hasText(text) and hasClickAction()).onLast().performClick()
        settle()
    }

    private fun type(label: String, text: String) {
        compose.onNode(hasContentDescription(label) and hasSetTextAction()).performTextInput(text)
        settle()
    }

    // The website edits a field from its row in Instellingen › Account.
    @Test fun editorFirstName() = panel(Overlay.EditField("first_name"), "editor-first_name", "settings", Detail.SettingsPage("account"))
    @Test fun editorHeight() = panel(Overlay.EditField("height"), "editor-height", "settings", Detail.SettingsPage("account"), down = 760)
    @Test fun deleteConfirm() = panel(Overlay.DeleteAccount, "delete-1", page = "settings", down = 10_000)

    @Test
    fun assistant() {
        OwnifyAssistant.clear()
        app()
        compose.runOnUiThread { shell.openAi() }
        settle()
        shoot("assistant")
    }

    /**
     * Ownify AI's screens, each from an answer api/ai/state.php gave
     * (-Downify.ai: one with a conversation in it) — the consent question,
     * the empty conversation, the conversation with a proposal waiting, the
     * list of conversations, today's limit reached, and switched off.
     */
    @Test
    fun assistantScreens() {
        val answer = System.getProperty("ownify.ai").orEmpty()
        assumeTrue("no api/ai/state.php answer given", answer.isNotEmpty())
        fun read() = JSONObject(File(answer).readText())

        OwnifyAssistant.clear()
        app()
        val copy = data().ai
        compose.runOnUiThread { shell.openAi() }
        settle()
        shoot("assistant-consent")

        val empty = read().put("conversations", org.json.JSONArray()).put("conversation", JSONObject.NULL).put("messages", org.json.JSONArray())
        compose.runOnUiThread { OwnifyAssistant.show(empty, copy) }
        shoot("assistant-empty")

        compose.runOnUiThread { OwnifyAssistant.show(read(), copy) }
        shoot("assistant-chat")

        compose.runOnUiThread { OwnifyAssistant.historyOpen = true }
        shoot("assistant-history")
        compose.runOnUiThread { OwnifyAssistant.historyOpen = false }

        val out = read().put("usage", JSONObject().put("used", 10).put("limit", 10).put("remaining", 0))
        compose.runOnUiThread { OwnifyAssistant.show(out, copy) }
        shoot("assistant-limit")

        compose.runOnUiThread { OwnifyAssistant.show(JSONObject().put("consent", "declined").put("available", true), copy) }
        shoot("assistant-declined")
        OwnifyAssistant.clear()
    }

    // ------------------------------------------------------- the first days

    /**
     * The first days, from api/app/state.php answers in -Downify.firstdays=<dir>:
     * the setup a new account starts with, step by step (setup-suggest.json —
     * an account whose phone already synced a few nights, so the goal step
     * has its suggestion), and Overzicht's card in each of its states.
     */
    private val firstDays = System.getProperty("ownify.firstdays").orEmpty()

    private fun setupStep(step: String, name: String, then: () -> Unit = {}) {
        assumeTrue("no first-days states given (-Downify.firstdays)", firstDays.isNotEmpty())
        val data = data("$firstDays/setup-suggest.json")
        frame { SetupScreen(data, start = step) }
        settle()
        then()
        shoot(name)
    }

    @Test fun setupFocus() = setupStep("focus", "setup-focus")
    @Test fun setupFocusChosen() = setupStep("focus", "setup-focus-chosen") { tap("Slaap") }
    @Test fun setupConnect() = setupStep("connect", "setup-connect")
    @Test fun setupProfile() = setupStep("profile", "setup-profile")
    @Test fun setupGoal() = setupStep("goal", "setup-goal")

    private fun firstDay(name: String) {
        assumeTrue("no first-days states given (-Downify.firstdays)", firstDays.isNotEmpty())
        app("$firstDays/$name.json")
        shoot("first-$name")
    }

    @Test fun firstDay1() = firstDay("day1-empty")
    @Test fun firstDay2() = firstDay("day2-one-night")
    @Test fun firstDay3() = firstDay("day3-two-nights")
    @Test fun firstDay4() = firstDay("day4-first-score")
    @Test fun firstBaseline() = firstDay("day3-baseline")
    @Test fun firstNothing() = firstDay("day5-nothing")
    @Test fun firstWeight() = firstDay("day1-weight")

    // ------------------------------------------------------------- signed out

    @Test
    fun welcome() {
        frame { OwnifyScreens.signedOut.welcome(false) }
        shoot("welcome")
    }
}
