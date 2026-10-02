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
 * The state the real backend sent (api/app/state.php, local test server):
 * a demo account with five weeks of data, four goals and friends, and a
 * brand-new account with nothing yet. The app reads it as it is — every
 * value below is the server's, not worked out here.
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [36])
class AppDataParseTest {

    @Test
    fun `the theme - the one choice that saves, and this phone's choice where the settings name it`() {
        val data = fixture("state-demo.json")
        val choice = data.settings.page("theme")!!.blocks.filterIsInstance<SettingsBlock.Choice>().single()
        assertEquals("theme", choice.name)
        assertTrue(choice.saves)
        // Asked by the app, the server has no cookie: Dark.
        assertEquals("dark", choice.selected)
        assertEquals(listOf("dark" to "Donker", "light" to "Licht"), choice.options.map { it.key to it.label })
        assertFalse(choice.options.any { it.disabled })
        val others = data.settings.pages.flatMap { it.blocks }.filterIsInstance<SettingsBlock.Choice>().filter { it.name != "theme" }
        assertTrue(others.isNotEmpty())
        assertTrue("the other choices are not kept", others.none { it.saves })

        fun row(d: AppData) = d.settings.groups.flatMap { it.rows }.single { it.id == "theme" }.value
        assertEquals("Donker", row(data))

        val light = data.withTheme("light")
        assertEquals("light", light.settings.page("theme")!!.blocks.filterIsInstance<SettingsBlock.Choice>().single().selected)
        assertEquals("Licht", row(light))
        // Nothing else moves.
        assertEquals(data.copy(settings = data.settings.copy(pages = light.settings.pages, groups = light.settings.groups)), light)
        assertEquals(data, data.withTheme("dark"))
    }

    private fun fixture(name: String): AppData {
        val text = javaClass.classLoader!!.getResource(name).readText()
        return AppData.parse(JSONObject(text).getJSONObject("data"))
    }

    @Test
    fun `the demo account - every page's data is read as the server sent it`() {
        val data = fixture("state-demo.json")

        assertEquals("Ownify", data.app.name)
        assertEquals(listOf("health", "goals", "overview", "community", "settings"), data.navigation.map { it.id })
        assertTrue(data.navigation.single { it.id == "overview" }.active)
        assertEquals("", data.disclaimer)

        // Overzicht
        assertEquals(82, data.overview.overall.value)
        assertEquals(listOf(84, 74, 88), data.overview.contributors.map { it.value })
        assertEquals(listOf("sleep", "nutrition", "training"), data.overview.contributors.map { it.accent })
        assertEquals(listOf("high", "mid", "high"), data.overview.contributors.map { it.scoreBand })
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
        // Privacy's switches that save, as the account has them; the rest wait, disabled.
        val privacy = data.settings.page("privacy")!!
        val switches = privacy.blocks.filterIsInstance<SettingsBlock.Toggles>().flatMap { it.items }
        assertEquals(listOf("leaderboard_avatar", "ai_consent"), switches.mapNotNull { it.key })
        val board = switches.single { it.key == "leaderboard_avatar" }
        assertEquals("Profielfoto op de ranglijst", board.label)
        assertTrue(board.on)
        assertEquals("Anderen zien je profielfoto naast je naam.", board.note)
        assertEquals(board.note, board.noteOn)
        assertEquals("Op de ranglijst staat je initiaal in plaats van je foto.", board.noteOff)
        // Ownify AI: off until the account says yes, and it says what off means.
        val gemini = switches.single { it.key == "ai_consent" }
        assertEquals("Gegevens verwerken met Google Gemini", gemini.label)
        assertFalse(gemini.on)
        assertEquals("De assistent werkt niet, en er gaat niets naar Gemini.", gemini.note)
        assertEquals(2, switches.count { it.key == null })
        // …the conversations can be wiped, asked first, through the server's own endpoint.
        val wipe = privacy.blocks.filterIsInstance<SettingsBlock.Actions>().single().items.single()
        assertEquals("ai_clear_history", wipe.key)
        assertEquals("api/ai/delete.php", wipe.endpoint)
        assertEquals(mapOf("all" to "1"), wipe.fields)
        assertTrue(wipe.danger)
        assertEquals("Al je AI-gesprekken wissen? Dit kun je niet ongedaan maken.", wipe.question)
        // …and the facts at the top say where the assistant stands.
        val facts = privacy.blocks.filterIsInstance<SettingsBlock.States>().single().items
        assertEquals("Uit", facts.single { it.label == "Ownify AI" }.value)

        // Ownify AI's words and the account's answer, before anything is fetched.
        assertEquals("Ownify AI", data.ai.title)
        assertEquals("Ownify AI gebruiken?", data.ai.consent.title)
        assertEquals(5, data.ai.consent.points.size)
        assertTrue(data.ai.consent.points.any { "verbeteren" in it })
        assertEquals(3, data.ai.empty.suggestions.size)
        assertEquals("unknown", data.ai.session.consent)
        assertTrue(data.ai.session.available)
        assertEquals(10, data.ai.session.usage.limit)
        assertTrue(data.ai.session.hasData)
        assertEquals("Nog 10 van 10 berichten vandaag", data.ai.remainingText(data.ai.session.usage))
        assertTrue(data.ai.error("limit").startsWith("Je hebt de gratis AI-berichten van vandaag gebruikt"))

        // A board row carries the picture only when its owner shows it; otherwise none.
        val rows = data.community.boards.values.flatMap { it.values }.flatMap { it.entries }
        assertTrue(rows.any { it.avatar == "uploads/avatars/u776-ea125a85cccfbfc9.png" })
        assertTrue(rows.any { it.avatar == null })
        // …and your own line where a board's top does not reach you, the same way.
        assertEquals("uploads/avatars/u776-ea125a85cccfbfc9.png", data.community.youAvatar)

        val hc = data.settings.integrations.single { it.key == "health_connect" }
        assertTrue(hc.connected)
        assertEquals("Demo Pixel", hc.devices.single().label)

        assertEquals("sanne_7001", data.auth.username)
        assertFalse(data.auth.hasGoogle)
    }

    /** The Scorekompas block as the server sent it (includes/score-compass.php). */
    private fun compass(name: String): Compass =
        Compass.parse(JSONObject(javaClass.classLoader!!.getResource(name).readText()))

    @Test
    fun `the Scorekompas - every number and sentence read as the server sent it`() {
        val c = compass("compass-demo.json")

        assertTrue(c.available)
        assertEquals("Scorekompas", c.title)
        assertEquals("Overzicht", c.back)
        assertEquals(81, c.score)
        assertEquals("high", c.band)
        assertEquals(CompassDirection("down", "Dalend"), c.direction)

        // 1 — each category with its colour (which) and its band (how high); its components with their weights.
        val (sleep, food, sport) = c.composition.categories
        assertEquals(listOf("Slaap", "Voeding", "Sport"), c.composition.categories.map { it.label })
        assertEquals(listOf("sleep", "nutrition", "training"), c.composition.categories.map { it.accent })
        assertEquals(listOf("high", "mid", "high"), c.composition.categories.map { it.band })
        assertEquals(listOf(45, 30, 25), sleep.parts.map { it.weight })
        assertEquals("Gemiddeld 6:39 per nacht", sleep.parts.first().note)
        assertTrue(food.parts.isEmpty())
        assertEquals("Je voedingsscore is je gemiddelde dagcijfer (7,4) keer tien.", food.summary)
        val intensity = sport.parts.single { it.id == "intensity" }
        assertFalse("a component without data does not count", intensity.counted)
        assertNull(intensity.value)
        assertNull(intensity.weight)
        assertEquals("the others share its weight", listOf(36, 64), sport.parts.filter { it.counted }.map { it.weight })

        // 2 — what is changing.
        assertEquals("filled", c.trend.state)
        assertEquals(CompassDirection("down", "Dalend"), c.trend.direction)
        assertEquals("Op 23 september ging je score van 86 naar 81; die dag ging Voeding meetellen, met 70.", c.trend.text[1])
        assertEquals(listOf("3 sep", "Vandaag"), c.trend.axis)
        assertTrue(c.trend.chart.hasData)
        assertEquals(1, c.trend.chart.line.size)
        assertEquals(300f, c.trend.width)

        // 3 — compared with yourself: too few earlier days, so no average for them and no difference.
        assertEquals(listOf(81, 82, 85, null), c.comparison.rows.map { it.value })
        assertEquals("Over de afgelopen 90 dagen", c.comparison.rows.first().note)
        assertEquals("Nog niet genoeg gegevens", c.comparison.rows.last().note)
        assertNull(c.comparison.delta)

        // 4 — the biggest opportunity.
        val o = c.opportunity
        assertTrue(o.filled)
        assertEquals("Dagcijfer voor voeding", o.name)
        assertEquals("nutrition", o.accent)
        assertEquals("mid", o.band)
        assertEquals("Je gemiddelde dagcijfer voor voeding is 7,4, over 7 dagen.", o.fact)
        assertEquals("Hogere dagcijfers zouden samengaan met een hogere voedingsscore.", o.relation)
        assertEquals("Op 100 zou dit onderdeel je Gezondheidsscore met zo'n 9 punten verhogen.", o.gainText)
    }

    @Test
    fun `the Scorekompas of a new account - empty states, never a zero`() {
        val c = compass("compass-new-account.json")

        assertTrue(c.available)
        assertNull(c.score)
        assertNull(c.direction)
        assertEquals("empty", c.trend.state)
        assertFalse(c.trend.chart.hasData)
        assertEquals("Nog niet genoeg gegevens.", c.trend.empty)
        assertTrue(c.comparison.rows.all { it.value == null && it.note == "Nog niet genoeg gegevens" })
        assertNull(c.comparison.delta)
        assertFalse(c.opportunity.filled)
        assertEquals("Nog niet genoeg gegevens om een kans aan te wijzen.", c.opportunity.empty)
        // What the score will be made of, before there is one: the weights it will have.
        assertEquals(listOf(45, 30, 25), c.composition.categories.first().parts.map { it.weight })
        assertTrue(c.composition.categories.flatMap { it.parts }.all { it.value == null })
    }

    @Test
    fun `a server without the Scorekompas - nothing to open, nothing to say`() {
        val data = fixture("state-demo.json")

        assertFalse(data.compass.available)
        assertNull(data.compass.direction)
        assertFalse(data.compass.opportunity.filled)
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
        assertEquals("no score yet: Gezondheid says how many days unlock one", "Je hebt nog 3 dagen data nodig om een score te ontgrendelen.", data.health.lede)
        assertFalse(data.health.trend.charts["sleep"]!!["week"]!!.hasData)
        assertTrue(data.community.friends.isEmpty())
        // No data yet: the assistant's empty screen will say so.
        assertFalse(data.ai.session.hasData)
    }
}
