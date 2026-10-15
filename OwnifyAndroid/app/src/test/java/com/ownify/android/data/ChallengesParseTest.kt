package com.ownify.android.data

import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/**
 * Uitdagingen as the real server sends it: challenges-state.json and
 * challenge-lookup.json are api/app/state.php's and api/challenges/lookup.php's
 * answers for a demo account (tools/challenges-test.php makes the same).
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [36])
class ChallengesParseTest {

    private fun fixture(name: String) = JSONObject(javaClass.classLoader!!.getResource(name).readText())

    private val state = fixture("challenges-state.json")
    private val challenges = Challenges.parse(state.getJSONObject("challenges"))

    @Test
    fun `invitations, the ones on now and the ones that ended, apart`() {
        assertTrue(challenges.available)
        assertEquals(listOf("Samen 500 km fietsen"), challenges.invites.map { it.name })
        assertEquals(3, challenges.running.size)
        assertEquals(listOf("Zeven dagen trainen"), challenges.ended.map { it.name })
        assertTrue(challenges.summary.startsWith("3 bezig"))
        assertEquals("Uitdagingen staan los van je punten op de ranglijst.", challenges.note)
    }

    @Test
    fun `a Samen challenge - everybody's piece of the target, the figures and the pace`() {
        val samen = challenges.running.first { it.name == "100 trainingen in oktober" }
        assertEquals("together", samen.kind)
        assertFalse(samen.versus)
        assertEquals("stack", samen.bar.type)
        assertEquals(1f, samen.bar.segments.sumOf { it.part.toDouble() }.toFloat() + samen.bar.rest, 0.01f)
        assertTrue("you are in the colour", samen.bar.segments.single { it.you }.tone == 0)
        assertTrue(samen.tile.strong.endsWith("%"))
        assertTrue(samen.line.rest.startsWith("van 100 trainingen"))
        assertTrue(samen.big.line.contains(" · dag "))
        assertEquals("Jij", samen.people.single { it.you }.label)
        assertEquals("Wie draagt wat bij", samen.peopleTitle)
        assertNotNull("a member sees the code", samen.code)
        assertTrue(samen.shareText!!.contains(samen.code!!))
        assertEquals("noor_e is uitgenodigd", samen.pendingText)
    }

    @Test
    fun `a Wedstrijd - places, the lead bar and the gap`() {
        val race = challenges.running.first { it.versus }
        assertEquals("lead", race.bar.type)
        assertTrue(race.bar.value in 0f..1f)
        assertEquals("Stand", race.peopleTitle)
        assertTrue(race.people.zipWithNext().all { (a, b) -> a.value >= b.value })
        assertTrue(race.tile.strong.endsWith("e"))
        assertTrue(race.line.sub.isNotEmpty())
    }

    @Test
    fun `one that ended - no code, and nobody can join or leave it`() {
        val over = challenges.ended.single()
        assertEquals("ended", over.state)
        assertNull(over.code)
        assertFalse(over.canLeave)
        assertTrue(over.left.startsWith("Afgelopen op"))
        assertTrue(over.invitable.isEmpty())
    }

    @Test
    fun `an invitation - who sent it and what to review, without totals`() {
        val invite = challenges.invites.single()
        assertEquals("bram_fietst", invite.inviter?.name)
        assertTrue(invite.inviteText.startsWith("nodigt je uit voor"))
        val review = invite.review!!
        assertTrue(review.invited)
        assertEquals("Uitnodiging", review.title)
        assertEquals(listOf("Soort", "Wat", "Periode", "Gemaakt door"), review.facts.map { it.first })
        assertTrue(review.privacy.contains("je gebruikersnaam en je totaal"))
        assertTrue(review.faces.people.all { it.value == 0.0 })
    }

    @Test
    fun `what a code found - the same review, found with the code`() {
        val answer = fixture("challenge-lookup.json")
        assertEquals("found", answer.getString("status"))
        val review = ChallengeReview.parse(answer.getJSONObject("review"))!!
        assertFalse(review.invited)
        assertEquals("Meedoen?", review.title)
        assertTrue(review.by.startsWith("Gevonden met code "))
    }

    @Test
    fun `Nieuwe uitdaging - five metrics, three lengths, two starts, and your friends with their ids`() {
        assertEquals(listOf("workouts", "steps", "active_minutes", "distance", "sleep_nights"), challenges.metrics.map { it.key })
        val km = challenges.metric("distance")!!
        assertEquals("%s km", km.many)
        assertEquals(25.0, km.step, 0.0)
        assertEquals(100.0, km.targets["1w"]!!, 0.0)
        assertEquals(listOf("1w", "2w", "month"), challenges.lengths.map { it.key })
        assertTrue(challenges.length("2w")!!.namePattern.contains("%s"))
        assertEquals(listOf("today", "monday"), challenges.from.map { it.first })
        assertTrue(challenges.from[1].second.startsWith("Vanaf "))
        assertTrue(challenges.friends.isNotEmpty() && challenges.friends.all { it.id != null })
        assertEquals("Nieuwe uitdaging", challenges.copy.text("create", "title"))
    }

    @Test
    fun `Community's Ranglijst card`() {
        val preview = BoardPreview.parse(state.getJSONObject("preview"))
        assertEquals("Ranglijst", preview.title)
        assertEquals("Vrienden · deze maand", preview.sub)
        assertTrue(preview.rows.size in 1..4)
    }

    @Test
    fun `a server without migration 022 - no challenges, nothing breaks`() {
        val none = Challenges.parse(null)
        assertFalse(none.available)
        assertTrue(none.running.isEmpty() && none.invites.isEmpty() && none.metrics.isEmpty())
    }
}
