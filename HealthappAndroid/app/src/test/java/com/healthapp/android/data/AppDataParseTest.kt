package com.healthapp.android.data

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
 * The state the real backend sent (api/app/state.php, local test server):
 * a demo account with five weeks of data, four goals and friends, and a
 * brand-new account with nothing yet. The app reads it as it is — every
 * value below is the server's, not worked out here.
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [36])
class AppDataParseTest {

    private fun fixture(name: String): AppData {
        val text = javaClass.classLoader!!.getResource(name).readText()
        return AppData.parse(JSONObject(text).getJSONObject("data"))
    }

    @Test
    fun `the demo account - every page's data is read as the server sent it`() {
        val data = fixture("state-demo.json")

        assertEquals("NaamKomtNog!!", data.app.name)
        assertEquals(listOf("health", "goals", "overview", "community", "settings"), data.navigation.map { it.id })
        assertTrue(data.navigation.single { it.id == "overview" }.active)
        assertEquals("", data.disclaimer)

        // Overzicht
        assertEquals(82, data.overview.overall.value)
        assertEquals(listOf(84, 74, 88), data.overview.contributors.map { it.value })
        assertEquals("active", data.overview.goal.state)
        assertEquals("0 dagen van 14 dagen op rij", data.overview.goal.unit)
        assertEquals(4, data.overview.goal.milestones.size)

        // Gezondheid: metrics printed as the template prints them.
        val sleep = data.health.area("sleep")!!
        assertEquals("6:47", sleep.highlights.single { it.key == "sleep_duration" }.value)
        assertEquals("88", sleep.highlights.single { it.label == "Efficiëntie" }.value)
        assertEquals("4.7", data.health.area("training")!!.highlights.single { it.label == "Afstand" }.value)
        assertEquals(listOf("deep", "rem", "light", "awake"), sleep.timeline!!.stages.map { it.tone })
        assertNotNull(data.health.area("nutrition")!!.rating)
        assertEquals(7, data.health.area("nutrition")!!.ratingToday)
        assertEquals(listOf("week", "month"), data.health.trend.ranges.map { it.key })
        assertTrue(data.health.trend.charts["sleep"]!!["week"]!!.hasData)
        assertEquals(300f, data.health.trend.width)

        // Doelen
        assertEquals(3, data.goals.used)
        assertFalse(data.goals.canAdd)
        assertEquals("Elke dag 8.000 stappen", data.goals.primary!!.name)
        val bench = data.goals.all.single { it.name == "Bench press 100 kg" }
        assertEquals(85, bench.percent)
        assertEquals("result", bench.entry!!.kind)
        assertEquals("Beste 85 kg", bench.chart!!.end!!.label)
        assertTrue(data.goals.all.single { it.name == "15 uur trainen" }.isPaused)
        assertTrue(data.goals.completed.single().isCompleted)
        val streak = data.goals.primary!!
        assertEquals(31, streak.days.size)
        assertEquals("8 sep · gehaald", streak.days[1].title)
        assertTrue(data.goals.wizardSources.isNotEmpty())
        assertEquals(6, data.goals.wizard.steps.size)

        // Community
        val friendsMonth = data.community.boards["friends"]!!["month"]!!
        assertEquals(1, friendsMonth.youRank)
        assertEquals(listOf("anna_7001", "bram_7001"), data.community.friends.map { it.username })
        assertEquals("cas_7001", data.community.pending.single().username)
        assertEquals("dewi_7001", data.community.sent.single().username)

        // Instellingen
        assertEquals(listOf("account", "devices", "privacy", "notifications", "theme", "language", "units", "week", "accessibility", "about"),
            data.settings.pages.map { it.id })
        val fields = data.settings.page("account")!!.blocks.filterIsInstance<SettingsBlock.Fields>().flatMap { it.fields }
        assertEquals("locked", fields.single { it.key == "gender" }.state)
        assertEquals("Vrouw", fields.single { it.key == "gender" }.value)
        assertEquals("editable", fields.single { it.key == "first_name" }.state)
        assertEquals("api/profile/update.php", fields.single { it.key == "height" }.input!!.endpoint)
        assertNull(fields.single { it.key == "gender" }.input)
        val hc = data.settings.integrations.single { it.key == "health_connect" }
        assertTrue(hc.connected)
        assertEquals("Demo Pixel", hc.devices.single().label)

        assertEquals("sanne_7001", data.auth.username)
        assertFalse(data.auth.hasGoogle)
    }

    @Test
    fun `a new account - empty scores, no goals, the "no data yet" line`() {
        val data = fixture("state-new-account.json")

        assertNull(data.overview.overall.value)
        assertEquals("Je gegevens verschijnen hier zodra je ze vastlegt of een bron koppelt.", data.disclaimer)
        assertEquals("unset", data.overview.goal.state)
        assertTrue(data.goals.all.isEmpty())
        assertTrue(data.goals.canAdd)
        assertNull(data.health.area("sleep")!!.score.value)
        assertFalse(data.health.trend.charts["sleep"]!!["week"]!!.hasData)
        assertTrue(data.community.friends.isEmpty())
    }
}
