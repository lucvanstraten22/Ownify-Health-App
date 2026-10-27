package com.ownify.android.data

import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/** The red dots (lib/notices.php): read from the state, and the same rules as the website's notice_on(). */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [36])
class NoticesTest {

    private val state = JSONObject(
        """{"rating":{"waiting":true,"hour":20,"due":true},"goals":["655"],"friends":[261],"invites":[73,105],"sources":["polar"]}"""
    )

    @Test
    fun readsTheSummary() {
        val n = Notices.parse(state)
        assertTrue(n.ratingWaiting)
        assertEquals(20, n.ratingHour)
        assertEquals(setOf("655"), n.goals)
        assertEquals(setOf("261"), n.friends)
        assertEquals(setOf("73", "105"), n.invites)
        assertEquals(setOf("polar"), n.sources)
    }

    @Test
    fun theVoedingDotWaitsForItsHour() {
        val n = Notices.parse(state)
        assertFalse(n.on("rating", 19))
        assertFalse(n.on("tab:health", 19))
        assertTrue(n.on("rating", 20))
        assertTrue(n.on("tab:health", 22))
        // Another hour chosen in Meldingen.
        assertFalse(n.copy(ratingHour = 22).on("rating", 21))
    }

    @Test
    fun eachStepOfTheWay() {
        val n = Notices.parse(state)
        assertTrue(n.on("goal:655", 9))
        assertFalse(n.on("goal:1", 9))
        assertTrue(n.on("tab:goals", 9))
        assertTrue(n.on("account", 9))
        assertTrue(n.on("friend:261", 9))
        assertTrue(n.on("invite:105", 9))
        assertTrue(n.on("tab:community", 9))
        assertTrue(n.on("sources", 9))
        assertTrue(n.on("source:polar", 9))
        assertTrue(n.on("tab:settings", 9))
        assertFalse(n.on("tab:overview", 9))
    }

    @Test
    fun nothingWaitingIsNoDots() {
        val n = Notices.parse(null)
        listOf("rating", "goals", "account", "sources", "tab:health", "tab:goals", "tab:community", "tab:settings")
            .forEach { assertFalse(it, n.on(it, 23)) }
    }
}
