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
    fun `Instellingen - App, then Voorkeuren with three of its rows, and Over de app without Gebouwd met or Contact`() {
        for (name in listOf("state-demo.json", "state-new-account.json")) {
            val settings = fixture(name).settings
            assertEquals(
                name,
                listOf(
                    "Account" to listOf("account"), "Gezondheid" to listOf("devices"), "Privacy" to listOf("privacy"),
                    "App" to listOf("notifications", "theme", "language"),
                    "Voorkeuren" to listOf("units", "week", "accessibility"),
                    "Over" to listOf("about")
                ),
                settings.groups.map { group -> group.label to group.rows.map { it.id } }
            )

            val about = settings.page("about")!!.blocks
            assertEquals(
                listOf("App" to listOf("Naam", "Versie"), "Juridisch" to listOf("Privacyverklaring", "Voorwaarden", "Licenties")),
                about.filterIsInstance<SettingsBlock.Rows>().map { block -> block.title to block.items.map { it.first } }
            )
            // Hulp keeps its heading, over the note that followed Contact.
            val hulp = about.last() as SettingsBlock.Note
            assertEquals("Hulp", hulp.title)
            assertTrue(hulp.text.startsWith("Ownify is geen medisch hulpmiddel."))
            // Every other note has no heading of its own, as before.
            val others = settings.pages.flatMap { it.blocks }.filterIsInstance<SettingsBlock.Note>().filter { it !== hulp }
            assertTrue(others.isNotEmpty())
            assertTrue(others.all { it.title == null })
        }
    }

    @Test
    fun `the theme - the one choice that saves, and this phone's choice where the settings name it`() {
        val data = fixture("state-demo.json")
        val choice = data.settings.page("theme")!!.blocks.filterIsInstance<SettingsBlock.Choice>().single()
        assertEquals("theme", choice.name)
        assertTrue(choice.saves)
        // Asked by the app, the server has no cookie: Systeem.
        assertEquals("system", choice.selected)
        assertEquals(listOf("system" to "Systeem", "dark" to "Donker", "light" to "Licht"), choice.options.map { it.key to it.label })
        assertFalse(choice.options.any { it.disabled })
        val others = data.settings.pages.flatMap { it.blocks }.filterIsInstance<SettingsBlock.Choice>().filter { it.name != "theme" }
        assertTrue(others.isNotEmpty())
        assertTrue("the other choices are not kept", others.none { it.saves })

        fun row(d: AppData) = d.settings.groups.flatMap { it.rows }.single { it.id == "theme" }.value
        assertEquals("Systeem", row(data))

        val light = data.withTheme("light")
        assertEquals("light", light.settings.page("theme")!!.blocks.filterIsInstance<SettingsBlock.Choice>().single().selected)
        assertEquals("Licht", row(light))
        assertEquals("Donker", row(data.withTheme("dark")))
        // Nothing else moves.
        assertEquals(data.copy(settings = data.settings.copy(pages = light.settings.pages, groups = light.settings.groups)), light)
        assertEquals(data, data.withTheme("system"))
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
        assertFalse(month.dayDots)
        assertTrue("every week and month is a dot", quarter.dayDots && year.dayDots)
        // Ownify's time axis (docs/CHARTS.md): every day of the week by its date, today too — never "Vandaag".
        assertEquals(listOf(0f, 16.67f, 33.33f, 50f, 66.67f, 83.33f, 100f), week.axis.map { it.x })
        assertEquals("6 okt", week.axis.last().label)
        assertEquals(CompassDirection("down", "Dalend"), month.direction)
        assertEquals("the hero's direction is the 30 days'", c.direction, month.direction)
        assertEquals("Je score daalde van gemiddeld 71 in de week van 7 september naar 69 in de afgelopen week.", month.text.first())
        assertEquals("Je geschiedenis begint op 23 augustus.", quarter.since)
        assertNull(month.since)
        assertEquals("Je Gezondheidsscore per maand, het afgelopen jaar: stabiel", year.aria)
        assertEquals("Je Gezondheidsscore per week, de afgelopen 90 dagen", quarter.aria.substringBefore(":"))

        // The days: only the real ones, from the first with a score — 45, not 365.
        assertEquals(45, t.days.size)
        assertEquals("2026-08-23", t.days.first().date)
        assertEquals("Vandaag", t.days.last().label)
        assertEquals("today", t.days.last().state)
        // Drawn as Gezondheid's Verloop: a day, a day, a week, a month, each with what its reading
        // shows; weeks and months point past the days. 45 days of history: 90 days and a year from
        // its first day, at the left — seven weeks begun, two months.
        assertEquals(listOf("day", "day", "week", "month"), t.periods.map { it.group })
        assertEquals(listOf(38, 15, 45, 45), t.periods.map { it.start })
        assertEquals(listOf(7, 30, 7, 2), t.periods.map { it.at.size })
        assertEquals(0f, quarter.at.first().first)
        assertEquals(listOf("23 aug", "30", "6 sep"), quarter.axis.take(3).map { it.label })
        assertEquals(listOf("aug", "sep", "okt"), year.axis.take(3).map { it.label })
        assertEquals(13, year.axis.size)
        assertEquals(t.periods.map { it.at.size }, t.periods.map { it.points.size })
        assertEquals(100f, week.at.last().first)
        assertEquals(t.days.takeLast(7), week.points)
        // The week that began on 4 oktober, so far: its three days.
        val lastWeek = quarter.points.last()
        assertEquals("4 – 6 okt", lastWeek.label)
        assertEquals("weekgemiddelde", lastWeek.detail)
        assertEquals(Math.round(t.days.takeLast(3).mapNotNull { it.value }.average()).toInt(), lastWeek.value)
        assertTrue("a week's categories: their means, no parts", lastWeek.categories.all { it.parts == null })
        assertTrue("its levels named", quarter.grid.isNotEmpty() && quarter.chart.area.isEmpty())

        // A day carried from an earlier one: its score, and why.
        val carried = t.days.first { it.date == "2026-09-12" }
        assertEquals("carried", carried.state)
        assertEquals(70, carried.value)
        assertEquals("Geen nieuwe gegevens: de score van 10 september gold nog.", carried.note)
        // A day after it expired: no score — never a 0 — and a gap in the line.
        val gap = t.days.indexOfFirst { it.date == "2026-09-14" }
        assertNull(t.days[gap].value)
        assertEquals("Geen score op deze dag.", t.days[gap].note)
        assertNull(month.at[gap - month.start].second)

        // A day read closely: each category with its band and its parts.
        val day = t.days.first { it.date == "2026-10-05" }
        assertEquals(listOf("sleep", "nutrition", "training"), day.categories.map { it.id })
        assertEquals("Slaapduur 68 · Regelmaat 57 · Kwaliteit 66", day.categories[0].parts)
        assertEquals("Dagcijfer 7,6", day.categories[1].parts)
        assertEquals("mid", day.categories[2].band)
        assertEquals("Gezondheidsscore", t.readout.score)
        assertEquals("Tik of schuif over de lijn om je score te bekijken.", t.readout.hint)
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

    // ------------------------------------------------------ Gezondheid's Verloop

    /**
     * health-history-demo.json is what the server builds from compass-demo.json
     * (hydrate_health_history(), lib/hydrate-compass.php): the same days, read
     * as Slaap, Voeding and Training — a point a day over 7 and 30 days, a
     * week over 90 and a month over a year, on Ownify's time axis: 45 days of
     * history start at the left of every period, the rest empty ahead.
     */
    @Test
    fun `the Verloop - Slaap, Voeding and Training over the Scorekompas's periods, every point a score of its days`() {
        val compass = Compass.parse(resource("compass-demo.json"))
        val history = HealthHistory.parse(resource("health-history-demo.json"))!!
        val days = compass.trend.days

        assertEquals("Verloop", history.title)
        assertEquals("7", history.defaultPeriod)
        assertEquals("Periode kiezen", history.switchLabel)
        assertEquals(listOf("Slaap", "Voeding", "Training"), history.categories.map { it.label })
        assertEquals(listOf("sleep", "nutrition", "training"), history.categories.map { it.accent })

        // The Scorekompas's switch: the same periods; a day, a day, a week, a month.
        assertEquals(compass.trend.periods.map { it.key to it.label }, history.periods.map { it.key to it.label })
        assertEquals(listOf("day", "day", "week", "month"), history.periods.map { it.group })

        history.periods.forEachIndexed { i, period ->
            assertEquals(listOf("sleep", "nutrition", "training"), period.lines.map { it.id })
            assertTrue(period.hasData)
            assertEquals(period.x.size, period.points.size)
            assertEquals("left to right in time", period.x.sorted(), period.x)

            // The height is the period's own, its levels named: every point sits
            // where its score falls between them — and where it had none there is
            // no point, never a 0.
            assertTrue("${period.key}: levels", period.grid.size >= 2)
            val (a, b) = period.grid.take(2)
            val at = { value: Int -> a.y + (value - a.label.toInt()) * (b.y - a.y) / (b.label.toInt() - a.label.toInt()) }
            for ((k, line) in period.lines.withIndex()) {
                assertEquals(period.x.size, line.y.size)
                line.y.forEachIndexed { d, y ->
                    val value = period.points[d].values[k]
                    if (value == null) assertNull("${period.key} ${line.id} point $d", y)
                    else assertEquals("${period.key} ${line.id} point $d", at(value), y!!, 0.02f)
                }
            }

            // A period of days: its days are the Scorekompas's window, each point that day's scores.
            if (period.group == "day") {
                val same = compass.trend.periods[i]
                assertEquals(same.start, period.start)
                assertEquals(same.at.map { it.first }, period.x)
                period.points.forEachIndexed { d, point ->
                    val day = days[period.start + d]
                    assertEquals(day.label, point.label)
                    assertEquals(day.state == "carried", point.carried)
                    assertEquals(history.categories.map { c -> day.categories.first { it.id == c.id }.value }, point.values)
                }
            } else {
                // Weeks and months read no day of the list: an older app finds none there.
                assertEquals(days.size, period.start)
            }
        }

        // 90 days: a week a point from the first day, at the left; the last the week begun on 4 oktober.
        val weeks = history.periods[2]
        val last = days.takeLast(3)
        val mean = history.categories.map { c ->
            last.mapNotNull { d -> d.categories.first { it.id == c.id }.value }.takeIf { it.isNotEmpty() }?.let { Math.round(it.average()).toInt() }
        }
        assertEquals(mean, weeks.points.last().values)
        assertEquals("4 – 6 okt", weeks.points.last().label)
        assertEquals("weekgemiddelde", weeks.points.last().detail)
        assertEquals(7, weeks.points.size)
        assertEquals(0f, weeks.x.first())
        assertEquals(listOf("23 aug", "30", "6 sep", "13"), weeks.axis.take(4).map { it.label })
        assertEquals(listOf("23", "30", "6", "13"), weeks.axis.take(4).map { it.day })
        assertEquals(listOf("aug", null, "sep", null), weeks.axis.take(4).map { it.month })
        assertEquals(weeks.x, weeks.axis.take(7).map { it.x })

        // A year of 45 days: the year from the first day, 13 boundaries, two months begun.
        val year = history.periods[3]
        assertEquals(2, year.points.size)
        assertEquals(listOf(0f, year.axis[1].x), year.x)
        assertEquals("maandgemiddelde", year.points.first().detail)
        assertEquals(listOf("aug", "aug"), listOf(year.axis.first().label, year.axis.last().label))

        // A category's own page: its line alone, its own levels, its own spoken label.
        val solo = history.periods[1].lines.first { it.id == "sleep" }.solo!!
        assertEquals("Slaap per dag, de afgelopen 30 dagen", solo.aria)
        assertTrue(solo.grid.isNotEmpty() && solo.y.size == history.periods[1].x.size)

        // A week: a dot and a date for every day; every point of every period a dot.
        val week = history.periods[0]
        assertTrue(week.every)
        assertEquals(week.x, week.axis.map { it.x })
        assertEquals(listOf("30 sep", "1 okt", "2 okt", "3 okt", "4 okt", "5 okt", "6 okt"), week.axis.map { it.label })
        assertEquals(listOf("every", "every", "every", "every"), history.periods.map { it.dots })
        assertEquals(compass.trend.periods[1].axis, history.periods[1].axis)
        assertEquals("Je geschiedenis begint op 23 augustus.", history.periods[2].since)
        assertEquals("Slaap, Voeding en Training per dag, de afgelopen 7 dagen", week.aria)
        assertEquals("Slaap, Voeding en Training per week, de afgelopen 90 dagen", weeks.aria)
    }

    @Test
    fun `the Verloop carries a category's last score through a day without new input, as long as it held`() {
        val days = Compass.parse(resource("compass-demo.json")).trend.days
        val carried = days.indices.filter { days[it].state == "carried" }
        assertTrue(carried.isNotEmpty())
        for (i in carried) {
            // The last day with a recorded score before it: each category it had, still the same.
            val from = (i - 1 downTo 0).first { days[it].state == "stored" }
            for (category in days[i].categories) {
                val before = days[from].categories.first { it.id == category.id }.value
                if (category.value != null) assertEquals("${days[i].date} ${category.id}", before, category.value)
            }
            assertTrue(days[i].note!!.contains("gold nog"))
        }
    }

    @Test
    fun `the Verloop - a new account's periods are empty, and a server from before it sends none`() {
        val empty = HealthHistory.parse(resource("health-history-new-account.json"))!!
        assertTrue(empty.periods.none { it.hasData })
        // Nothing to draw, but the period's dates from today on: a chart is time (docs/CHARTS.md).
        assertTrue(empty.periods.all { it.x.isEmpty() && it.axis.isNotEmpty() && it.axis.none { t -> t.label == "Vandaag" } })
        assertEquals("Zodra er meetmomenten zijn, verschijnt hier je verloop.", empty.empty)

        assertNull(Health.parse(JSONObject("""{"title":"Gezondheid","areas":{},"trend":{}}""")).history)
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

