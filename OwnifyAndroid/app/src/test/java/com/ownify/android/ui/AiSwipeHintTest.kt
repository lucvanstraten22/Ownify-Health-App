package com.ownify.android.ui

import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.Modifier
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithTag
import androidx.test.core.app.ApplicationProvider
import com.ownify.android.data.AiSession
import com.ownify.android.data.AppData
import com.ownify.android.ui.app.AiHint
import com.ownify.android.ui.app.AppShell
import com.ownify.android.ui.app.GestureContext
import com.ownify.android.ui.app.ShellState
import com.ownify.android.ui.design.LocalScreen
import com.ownify.android.ui.design.ScreenMetrics
import com.ownify.android.ui.screens.OwnifyScreens
import com.ownify.android.ui.theme.OwnifyTheme
import kotlinx.coroutines.runBlocking
import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/**
 * 42.0: OwnifyAI by swipe up, from every page, landing on a spring that
 * takes over the finger's speed; and the hint that shows the way — every 6
 * to 8 seconds until the account has used OwnifyAI, then once per app
 * session, never again once a finger finds the swipe (ai-sheet.js).
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [36], qualifiers = "+w412dp-h915dp-port-420dpi")
class AiSwipeHintTest {

    @get:Rule
    val compose = createComposeRule()

    private lateinit var shell: ShellState
    private val prefs get() = AiHint.prefs(ApplicationProvider.getApplicationContext())

    @Before
    fun fresh() {
        AiHint.Session.done = false
        prefs.edit().clear().commit()
    }

    private fun show() {
        val state = JSONObject(javaClass.classLoader!!.getResource("state-demo.json").readText()).getJSONObject("data")
        compose.setContent {
            OwnifyTheme {
                BoxWithConstraints(Modifier.fillMaxSize()) {
                    CompositionLocalProvider(LocalScreen provides ScreenMetrics(maxWidth, maxHeight)) {
                        AppShell(AppData.parse(state), OwnifyScreens.shell, onShell = { shell = it })
                    }
                }
            }
        }
        compose.waitForIdle()
        compose.mainClock.autoAdvance = false
    }

    /** A finger on the dock, moving up [dy] dp and leaving at [vy] dp/ms (up negative). */
    private fun swipeUp(dy: Float, vy: Float): List<Float> {
        val dock = shell.dockZones.first()
        val x = dock.center.x
        val y = dock.center.y
        val height = 915f
        val c = shell.aiController
        val start = GestureContext(x, y, 0f, -4f, width = 412f, height = height, duration = 0)
        compose.runOnUiThread {
            assertTrue("the dock starts it", c.canStart(start))
            c.begin(start)
            c.move(start.copy(dy = dy))
            c.end(start.copy(dy = dy, vy = vy, duration = 120))
        }
        val frames = mutableListOf(shell.aiProgress)
        repeat(60) { compose.mainClock.advanceTimeByFrame(); frames += shell.aiProgress }
        return frames
    }

    @Test
    fun `from all five pages a swipe up opens OwnifyAI on a spring that never passes open`() {
        show()
        assertEquals(5, shell.pageIds.size)
        for (page in shell.pageIds) {
            compose.runOnUiThread { shell.tab(page) }
            compose.mainClock.advanceTimeBy(700)
            val frames = swipeUp(dy = -140f, vy = -1.6f)
            assertTrue("$page: open", shell.aiOpen)
            assertEquals("$page: landed", 1f, frames.last(), 0.001f)
            assertTrue("$page: never past open", frames.all { it <= 1f })
            for (i in 1 until frames.size) assertTrue("$page: only ever up", frames[i] >= frames[i - 1])
            compose.runOnUiThread { shell.closeAi() }
            compose.mainClock.advanceTimeBy(900)
            assertEquals("$page: closed again", 0f, shell.aiProgress, 0.001f)
            assertEquals("$page: still on its page", page, shell.currentPage)
        }
    }

    @Test
    fun `the flick's speed carries on - it arrives sooner than a slow lift from the same place`() {
        show()
        val flick = swipeUp(dy = -460f, vy = -2.4f)
        compose.runOnUiThread { shell.closeAi() }
        compose.mainClock.advanceTimeBy(900)
        val slow = swipeUp(dy = -460f, vy = 0f)
        // Three frames after the lift the flick is further along.
        assertTrue("flick ${flick[3]} > slow ${slow[3]}", flick[3] > slow[3] + 0.02f)
        assertEquals(1f, slow.last(), 0.001f)
    }

    @Test
    fun `never used - a hint every 6 to 8 seconds, until a finger starts the swipe`() = runBlocking {
        val hint = AiHint(prefs, "acc1", serverUsed = false)
        val waits = mutableListOf<Long>()
        hint.run(free = { true }, wait = { ms -> waits += ms; if (waits.size == 6) hint.found() })
        assertEquals(2_500L, waits.first())
        assertTrue(waits.drop(1).all { it in 6_000L..8_000L })
        assertEquals("five hints, then the swipe ended them", 5, hint.tick)
        assertTrue(hint.done)
    }

    @Test
    fun `used before - one hint when the app opens, none again in that session`() = runBlocking {
        prefs.edit().putBoolean("opened:acc1", true).commit()
        val hint = AiHint(prefs, "acc1", serverUsed = false)
        hint.run(free = { true }, wait = { })
        assertEquals(1, hint.tick)
        // The app session goes on: a new shell (another composition) shows none.
        val again = AiHint(prefs, "acc1", serverUsed = false)
        again.run(free = { true }, wait = { })
        assertEquals(0, again.tick)
        // The app opens again (a new process): one more.
        AiHint.Session.done = false
        val next = AiHint(prefs, "acc1", serverUsed = false)
        next.run(free = { true }, wait = { })
        assertEquals(1, next.tick)
    }

    @Test
    fun `used is per account, from the server or this phone, and opening the sheet records it`() {
        assertTrue(AiHint(prefs, "acc1", serverUsed = true).everUsed)
        val one = AiHint(prefs, "acc1", serverUsed = false)
        assertFalse(one.everUsed)
        one.opened()
        assertTrue(one.everUsed)
        assertTrue("and the hints stop", one.done)
        assertFalse("another account on this phone has not", AiHint(prefs, "acc2", serverUsed = false).everUsed)
        // What the server sends.
        val session = AiSession.parse(JSONObject("""{"consent":"accepted","used":true,"account":"4592dd6c83106677"}"""))
        assertTrue(session.used)
        assertEquals("4592dd6c83106677", session.account)
    }

    @Test
    fun `not over something - the hint waits while the sheet or a panel is in front`() = runBlocking {
        val hint = AiHint(prefs, "acc1", serverUsed = false)
        var free = false
        val waits = mutableListOf<Long>()
        hint.run(free = { free }, wait = { ms -> waits += ms; if (waits.size == 3) free = true; if (waits.size == 4) hint.found() })
        assertEquals(listOf(2_500L, 2_000L, 2_000L), waits.take(3))
        assertEquals(1, hint.tick)
    }

    @Test
    fun `in the app - the grip rises and settles, and a swipe on the dock ends the hints`() {
        show()
        val rest = compose.onNodeWithTag("ai-grip", useUnmergedTree = true).fetchSemanticsNode().boundsInRoot.top
        // The first hint comes 2.5 s after the app opens, on the real clock.
        Thread.sleep(2_800)
        compose.waitForIdle()
        compose.mainClock.advanceTimeBy(240)
        val up = compose.onNodeWithTag("ai-grip", useUnmergedTree = true).fetchSemanticsNode().boundsInRoot.top
        assertTrue("risen: $rest → $up", rest - up > 8f)   // 6 dp at 420 dpi ≈ 16 px, eased
        compose.mainClock.advanceTimeBy(1_400)
        val back = compose.onNodeWithTag("ai-grip", useUnmergedTree = true).fetchSemanticsNode().boundsInRoot.top
        assertEquals("settled", rest, back, 0.5f)

        swipeUp(dy = -140f, vy = -1.6f)
        assertTrue(AiHint.Session.done)
        assertTrue("remembered for this account", prefs.all.keys.any { it.startsWith("opened:") })
    }
}
