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
        // The limit is the server's own (config/goals.php), never one of the app's:
        // this recorded answer was made with a limit of 3, so 3 is what the app shows.
        assertEquals(3, data.goals.limits)
        assertEquals(7, Goals.parse(JSONObject("""{"limits":{"active":7},"used":2,"slots_left":5}""")).limits)
        assertEquals(5, Goals.parse(JSONObject("""{"used":2,"slots_left":3}""")).limits)

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
        assertEquals(73, c.score)
        assertEquals("mid", c.band)
        assertEquals(CompassDirection("down", "Dalend"), c.direction)

        // 1 — each category with its colour (which); the score is the last 168 hours.
        assertEquals(listOf("Slaap", "Voeding", "Sport"), c.composition.categories.map { it.label })
        assertEquals(listOf("sleep", "nutrition", "training"), c.composition.categories.map { it.accent })
        assertTrue(c.composition.note.contains("over de afgelopen 7 dagen"))

        // 2 — what is changing: one score, seen over four periods, the score's own week first.
        val t = c.trend
        assertEquals(listOf("7", "30", "90", "365"), t.periods.map { it.key })
        assertEquals(listOf("7 dagen", "30 dagen", "90 dagen", "1 jaar"), t.periods.map { it.label })
        assertEquals("7", t.defaultPeriod)
        assertEquals("Periode kiezen", t.switchLabel)
        val (week, month, quarter, year) = t.periods
        assertNull("a week is too short for a direction", week.direction)
        assertEquals(listOf("De afgelopen 7 dagen lag je score tussen 68 en 73."), week.text)
        assertTrue("every day of a week is a dot", week.dayDots)
        assertFalse(year.dayDots)
        assertEquals(listOf(0f, 33.33f, 66.67f, 100f), week.axis.map { it.x })
        assertEquals("Vandaag", week.axis.last().label)
        assertEquals(CompassDirection("down", "Dalend"), month.direction)
        assertEquals("the hero's direction is the 30 days'", c.direction, month.direction)
        assertEquals("Je score daalde van gemiddeld 71 in de week van 7 september naar 69 in de afgelopen week.", month.text.first())
        assertEquals("Je geschiedenis begint op 23 augustus.", quarter.since)
        assertNull(month.since)
        assertEquals("Je Gezondheidsscore per dag, het afgelopen jaar: stabiel", year.aria)

        // The days: only the real ones, from the first with a score — 45, not 365.
        assertEquals(45, t.days.size)
        assertEquals("2026-08-23", t.days.first().date)
        assertEquals("Vandaag", t.days.last().label)
        assertEquals("today", t.days.last().state)
        assertEquals(listOf(38, 15, 0, 0), t.periods.map { it.start })
        assertEquals(listOf(7, 30, 45, 45), t.periods.map { it.at.size })
        assertEquals(100f, week.at.last().first)

        // A day carried from an earlier one: its score, and why.
        val carried = t.days.first { it.date == "2026-09-12" }
        assertEquals("carried", carried.state)
        assertEquals(70, carried.value)
        assertEquals("Geen nieuwe gegevens: de score van 10 september gold nog.", carried.note)
        // A day after it expired: no score — never a 0 — and a gap in the line.
        val gap = t.days.indexOfFirst { it.date == "2026-09-14" }
        assertNull(t.days[gap].value)
        assertEquals("Geen score op deze dag.", t.days[gap].note)
        assertNull(quarter.at[gap - quarter.start].second)

        // A day read closely: each category with its band and its parts.
        val day = t.days.first { it.date == "2026-10-05" }
        assertEquals(listOf("sleep", "nutrition", "training"), day.categories.map { it.id })
        assertEquals("Slaapduur 68 · Regelmaat 57 · Kwaliteit 66", day.categories[0].parts)
        assertEquals("Dagcijfer 7,6", day.categories[1].parts)
        assertEquals("mid", day.categories[2].band)
        assertEquals("Gezondheidsscore", t.readout.score)
        assertEquals("Tik of schuif over de lijn om een dag te bekijken.", t.readout.hint)
        assertEquals(listOf(CompassName("sleep", "Slaap", "sleep"), CompassName("nutrition", "Voeding", "nutrition"), CompassName("training", "Sport", "training")), t.readout.categories)

        // 3 — compared with yourself: now is the last 168 hours.
        assertEquals(listOf(73, 69, 69, 70), c.comparison.rows.map { it.value })
        assertEquals("Over de afgelopen 7 dagen", c.comparison.rows.first().note)
        assertEquals("−1 ten opzichte van de 30 dagen daarvoor", c.comparison.delta)

        // 4 — the biggest opportunity.
        val o = c.opportunity
        assertTrue(o.filled)
        assertEquals("Dagcijfer voor voeding", o.name)
        assertEquals("nutrition", o.accent)
        assertEquals("Je gemiddelde dagcijfer voor voeding is 7,3, over 3 dagen.", o.fact)
    }

    @Test
    fun `a Scorekompas from before the periods - its 30 days, as they were shown`() {
        val c = compass("compass-legacy.json")

        assertTrue(c.available)
        assertEquals(81, c.score)
        assertEquals(CompassDirection("down", "Dalend"), c.direction)
        val (sleep, food, sport) = c.composition.categories
        assertEquals(listOf(45, 30, 25), sleep.parts.map { it.weight })
        assertTrue(food.parts.isEmpty())
        val intensity = sport.parts.single { it.id == "intensity" }
        assertFalse("a component without data does not count", intensity.counted)
        assertEquals("the others share its weight", listOf(36, 64), sport.parts.filter { it.counted }.map { it.weight })

        // No periods and no days: the card draws the 30 days it was sent.
        assertTrue(c.trend.periods.isEmpty())
        assertTrue(c.trend.days.isEmpty())
        assertEquals("filled", c.trend.state)
        assertEquals(CompassDirection("down", "Dalend"), c.trend.direction)
        assertEquals("Op 23 september ging je score van 86 naar 81; die dag ging Voeding meetellen, met 70.", c.trend.text[1])
        assertEquals(listOf("3 sep", "Vandaag"), c.trend.axis)
        assertTrue(c.trend.chart.hasData)
        assertEquals(1, c.trend.chart.line.size)
        assertEquals(300f, c.trend.width)
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
        // Four periods, every one empty, and no days at all: no placeholder history.
        assertEquals(listOf("empty", "empty", "empty", "empty"), c.trend.periods.map { it.state })
        assertTrue(c.trend.periods.none { it.chart.hasData })
        assertTrue(c.trend.days.isEmpty())
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
        assertEquals("no score yet: Gezondheid says how many days unlock one", "Je eerste score volgt na 3 dagen met gegevens: nog 3 dagen.", data.health.lede)
        assertFalse(data.health.trend.charts["sleep"]!!["week"]!!.hasData)
        assertTrue(data.community.friends.isEmpty())
        // No data yet: the assistant's empty screen will say so.
        assertFalse(data.ai.session.hasData)
    }

    // -------------------------------------------------------- the first days

    private fun resource(name: String) = JSONObject(javaClass.classLoader!!.getResource(name).readText())

    @Test
    fun `a new account's setup - four steps, every word the server's, and what was already answered`() {
        val setup = Setup.parse(resource("setup-pending.json"))

        assertTrue(setup.pending)
        assertEquals(listOf("focus", "connect", "profile", "goal"), setup.order)
        assertEquals("Stap 2 van 4", setup.countText(1))
        assertEquals("Wat wil je het liefst begrijpen?", setup.focus.title)
        assertEquals(listOf("sleep", "energy", "fitness", "weight", "general"), setup.focus.options.map { it.key })
        assertEquals("Alles", setup.focus.options.last().label)
        // This account already chose Energie: a restart opens past it.
        assertEquals("energy", setup.focus.options.single { it.chosen }.key)
        assertEquals("connect", setup.resume)
        assertEquals("Doorgaan zonder koppelen", setup.connect.skip)
        // The server's own state: this account has a phone signed in, so it counts as connected…
        assertTrue(setup.connect.source.connected)
        // …and the phone says what its own Health Connect allows, in the server's words.
        assertEquals("Verbonden", setup.connect.source.connectedLabel)
        assertEquals("Nog niet verbonden", setup.connect.source.notConnectedLabel)
        // Only what Ownify uses, each with why — and Instellingen's own inputs and endpoints.
        assertEquals(listOf("date_of_birth", "height", "weight"), setup.profile.fields.map { it.key })
        assertTrue(setup.profile.fields.all { it.reason.isNotEmpty() })
        assertEquals("api/profile/onboarding.php", setup.profile.fields[0].input!!.endpoint)
        assertEquals("api/profile/update.php", setup.profile.fields[2].input!!.endpoint)
        assertEquals("kg", setup.profile.fields[2].input!!.unit)
        // A first goal from the person's own data: the normal goal model's fields.
        val suggestion = setup.goal.suggestion!!
        assertEquals("5 nachten van minstens 7 uur", suggestion.name)
        assertEquals("accumulate", suggestion.input["type"])
        assertEquals("sleep_duration", suggestion.input["source_key"])
        assertEquals("Doel toegevoegd: X. Je vindt het bij Doelen.", setup.goal.addedText("X"))
    }

    @Test
    fun `an older server, or an account past its setup - not pending, and no card`() {
        val data = fixture("state-demo.json")
        assertFalse(data.setup.pending)
        assertNull(data.calibration)
        assertFalse(Setup.parse(null).pending)
        assertNull(Calibration.parse(null))
    }

    @Test
    fun `the first days - building, the first score with its components, and the starting point`() {
        val building = Calibration.parse(resource("calibration-building.json"))!!
        assertEquals("building", building.phase)
        assertEquals(2, building.day)
        assertEquals(listOf("sleep", "nutrition", "training"), building.progress.map { it.id })
        assertEquals(1, building.progress.first().days)
        assertEquals(3, building.progress.first().needed)
        assertNotNull(building.observation)
        assertNull(building.first)

        val first = Calibration.parse(resource("calibration-first.json"))!!
        assertEquals("first_score", first.phase)
        assertEquals("Je eerste slaapscore", first.title)
        assertEquals("sleep", first.first!!.id)
        assertEquals(listOf("duration", "regularity", "quality"), first.first!!.parts.map { it.id })
        assertNotNull(first.open)

        val baseline = Calibration.parse(resource("calibration-baseline.json"))!!
        assertEquals("baseline", baseline.phase)
        assertEquals("Je startpunt", baseline.title)
        assertTrue(baseline.baseline.isNotEmpty())
        assertTrue(baseline.progress.isEmpty())
    }
}

