package com.ownify.android.ui

import com.ownify.android.i18n.OwnifyFormat
import com.ownify.android.i18n.OwnifyLanguage
import com.ownify.android.i18n.OwnifyUnits
import com.ownify.android.i18n.tr
import com.ownify.android.i18n.trf
import com.ownify.android.i18n.trn
import com.ownify.android.ui.screens.account.memberSince
import com.ownify.android.ui.screens.goals.daysText
import com.ownify.android.ui.screens.goals.dutchDate
import com.ownify.android.ui.screens.goals.dutchNumber
import com.ownify.android.ui.screens.goals.spanText
import com.ownify.android.ui.screens.goals.unitKey
import com.ownify.android.ui.screens.goals.unitLabel
import com.ownify.android.ui.screens.settings.moment
import java.io.File
import java.time.LocalDate
import java.time.ZoneId
import java.time.ZonedDateTime
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/**
 * Language and units in the app (docs/I18N.md): the same rules and the same
 * words as includes/i18n.php and assets/js/i18n.js. The English is the
 * generated catalogue the app ships (src/main/assets/lang-en.json), so a
 * string missing from it shows up here as Dutch. Robolectric for org.json,
 * which reads it.
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [36])
class I18nTest {

    private val catalog: Map<String, String> =
        OwnifyLanguage.read(File("src/main/assets/lang-en.json").readText())

    @Before
    fun dutch() = OwnifyLanguage.use("nl", catalog = catalog)

    @After
    fun reset() = OwnifyLanguage.use("nl", catalog = catalog)

    private fun english(units: String = "metric") = OwnifyLanguage.use("en", units)

    @Test
    fun `the phone's language - English is English, everything else Dutch`() {
        assertEquals("en", OwnifyLanguage.of("en"))
        assertEquals("en", OwnifyLanguage.of("en-GB"))
        assertEquals("en", OwnifyLanguage.of("EN-us"))
        assertEquals("nl", OwnifyLanguage.of("nl"))
        assertEquals("nl", OwnifyLanguage.of("nl-BE"))
        assertEquals("nl", OwnifyLanguage.of("de"))
        assertEquals("nl", OwnifyLanguage.of("fr-FR"))
        assertEquals("nl", OwnifyLanguage.of(""))
        assertEquals("nl", OwnifyLanguage.of(null))
    }

    @Test
    fun `the catalogue - every entry has English, with the same placeholders`() {
        assertTrue("the app ships English", catalog.size > 200)
        val placeholder = Regex("%(?:\\d+\\$)?(?:\\.\\d+)?[sdf]")
        for ((dutch, english) in catalog) {
            assertTrue("English for '$dutch'", english.isNotBlank())
            assertEquals("placeholders of '$dutch'",
                placeholder.findAll(dutch).map { it.value }.sorted().toList(),
                placeholder.findAll(english).map { it.value }.sorted().toList())
        }
    }

    @Test
    fun `tr, trf and trn - Dutch as written, English from the catalogue`() {
        assertEquals("Toegang geven", tr("Toegang geven"))
        assertEquals("Elke 3 uur", trf("Elke %d uur", 3))
        assertEquals("1 nieuwe meting", trn(1, "%d nieuwe meting", "%d nieuwe metingen"))
        assertEquals("4 nieuwe metingen", trn(4, "%d nieuwe meting", "%d nieuwe metingen"))

        english()
        assertEquals("Give access", tr("Toegang geven"))
        assertEquals("Every 3 hours", trf("Elke %d uur", 3))
        assertEquals("1 new reading", trn(1, "%d nieuwe meting", "%d nieuwe metingen"))
        assertEquals("4 new readings", trn(4, "%d nieuwe meting", "%d nieuwe metingen"))
        assertEquals("Paired with Pixel 8", trf("Gekoppeld aan %s", "Pixel 8"))
        // Not in the catalogue: the Dutch stays rather than nothing.
        assertEquals("Iets dat niet bestaat", tr("Iets dat niet bestaat"))
    }

    @Test
    fun `numbers - written and read as the website does`() {
        assertEquals("1.234,5", OwnifyFormat.number(1234.5, 1))
        assertEquals("72,5", OwnifyFormat.numberTrim(72.50, 2))
        assertEquals("2,36", OwnifyFormat.numberTrim(2.355, 2))
        assertEquals("80", OwnifyFormat.numberTrim(80.0, 1))
        english()
        assertEquals("1,234.5", OwnifyFormat.number(1234.5, 1))
        assertEquals("72.5", OwnifyFormat.numberTrim(72.50, 2))
        assertEquals("1234", OwnifyFormat.number(1234.0, 0, grouping = false))
        assertEquals("10,000", dutchNumber("10000"))

        for ((typed, value) in mapOf("72,5" to 72.5, "72.5" to 72.5, "1.234,5" to 1234.5, "1,234.5" to 1234.5, " 80 " to 80.0, "-3,5" to -3.5)) {
            assertEquals("'$typed'", value, OwnifyFormat.parseNumber(typed)!!, 1e-9)
        }
        for (typed in listOf("", "abc", "1e3", "12 kg")) assertNull("'$typed'", OwnifyFormat.parseNumber(typed))
    }

    @Test
    fun `dates - i18n_date()'s formats in both languages`() {
        val day = LocalDate.of(2026, 10, 5)                     // a Monday
        assertEquals("5 oktober", OwnifyFormat.date(day))
        assertEquals("5 okt", OwnifyFormat.date(day, "day_mon"))
        assertEquals("maandag 5 oktober", OwnifyFormat.date(day, "weekday_day_month"))
        assertEquals("5 okt 2026", OwnifyFormat.date(day, "day_mon_year"))
        assertEquals("oktober 2026", OwnifyFormat.date(day, "month_year"))
        assertEquals("5-10-2026", OwnifyFormat.date(day, "numeric"))

        english()
        assertEquals("October 5", OwnifyFormat.date(day))
        assertEquals("Oct 5", OwnifyFormat.date(day, "day_mon"))
        assertEquals("Monday, October 5", OwnifyFormat.date(day, "weekday_day_month"))
        assertEquals("Oct 5, 2026", OwnifyFormat.date(day, "day_mon_year"))
        assertEquals("October 5, 2026", OwnifyFormat.date(day, "day_month_year"))
        assertEquals("October 2026", OwnifyFormat.date(day, "month_year"))
        assertEquals("10/5/2026", OwnifyFormat.date(day, "numeric"))
        assertEquals("Sep 27, 2026", memberSince("2026-09-27 14:35:39"))
    }

    @Test
    fun `the app's own sentences in English - the wizard, the sync moment`() {
        english()
        assertEquals("1 day", spanText(1))
        assertEquals("30 days", spanText(30))
        assertEquals("6 weeks", spanText(45))
        assertEquals("6 months", spanText(182))
        assertEquals("1 year", spanText(365))
        assertEquals("2 years", spanText(730))
        assertEquals("1 day", daysText("1"))
        assertEquals("21 days", daysText("21"))
        assertEquals("Oct 4", dutchDate(LocalDate.of(2026, 10, 4)))

        val zone = ZoneId.systemDefault()
        val today = LocalDate.of(2026, 9, 27)
        fun at(date: LocalDate, h: Int, m: Int) = ZonedDateTime.of(date.atTime(h, m), zone).toInstant()
        assertEquals("Today, 14:35", moment(at(today, 14, 35), today))
        assertEquals("Yesterday, 09:12", moment(at(today.minusDays(1), 9, 12), today))
        assertEquals("9/3/2026, 18:02", moment(at(LocalDate.of(2026, 9, 3), 18, 2), today))
    }

    @Test
    fun `units - metric shows what is stored, unchanged`() {
        OwnifyLanguage.use("nl", "metric")
        for (unit in listOf("kg", "km", "cm", "m", "°C", "min/km", "stappen", "kcal")) {
            assertEquals(unit, OwnifyUnits.label(unit))
            assertEquals(72.5, OwnifyUnits.out(72.5, unit), 1e-9)
            assertEquals(72.5, OwnifyUnits.into(72.5, unit), 1e-9)
        }
        assertEquals("72,5 kg", OwnifyUnits.format(72.5, "kg"))
        assertEquals("180 cm", OwnifyUnits.format(180.0, "cm"))
    }

    @Test
    fun `units - imperial at the boundaries, and back without drift`() {
        english("imperial")
        assertEquals("lb", OwnifyUnits.label("kg"))
        assertEquals("mi", OwnifyUnits.label("km"))
        assertEquals("ft", OwnifyUnits.label("m"))
        assertEquals("°F", OwnifyUnits.label("°C"))
        assertEquals("min/mi", OwnifyUnits.label("min/km"))
        assertEquals("steps", OwnifyUnits.label("steps"))              // nothing to convert

        assertEquals(176.37, OwnifyUnits.out(80.0, "kg"), 0.01)
        assertEquals(6.21, OwnifyUnits.out(10.0, "km"), 0.01)
        assertEquals(98.6, OwnifyUnits.out(37.0, "°C"), 1e-9)
        assertEquals(328.08, OwnifyUnits.out(100.0, "m"), 0.01)
        // A pace of 5:00 per km is 8:03 per mile.
        assertEquals(482.8, OwnifyUnits.out(300.0, "min/km"), 0.1)

        // Typed in lb, kept in kg — and shown again as the same lb.
        val kg = OwnifyUnits.into(176.0, "kg")
        assertEquals(79.83, kg, 0.01)
        assertEquals(176.0, OwnifyUnits.out(kg, "kg"), 1e-9)
        assertEquals(37.0, OwnifyUnits.into(98.6, "°C"), 1e-9)

        assertEquals("176.4 lb", OwnifyUnits.format(80.0, "kg"))
        assertEquals("6.2 mi", OwnifyUnits.format(10.0, "km"))
        assertEquals("98.6 °F", OwnifyUnits.format(37.0, "°C"))
        assertEquals("5 ft 11 in", OwnifyUnits.format(180.0, "cm"))
        assertEquals("5 ft 11 in", OwnifyUnits.feetInches(180.0))
        assertEquals("6 ft 0 in", OwnifyUnits.feetInches(182.88))
    }

    @Test
    fun `units - a height typed in feet and inches`() {
        for (typed in listOf("5'11", "5' 11\"", "5 ft 11 in", "5 11", "5ft11", "71", "71 in")) {
            val cm = OwnifyUnits.parseFeetInches(typed)
            assertNotNull("'$typed'", cm)
            assertEquals("'$typed'", 180.34, cm!!, 0.01)
        }
        // A lone whole 2 to 8 is feet; "in" says inches.
        assertEquals(182.88, OwnifyUnits.parseFeetInches("6")!!, 0.01)
        assertEquals(15.24, OwnifyUnits.parseFeetInches("6 in")!!, 0.01)
        for (typed in listOf("", "abc", "12 ft", "5'12", "1'0")) assertNull("'$typed'", OwnifyUnits.parseFeetInches(typed))
    }

    @Test
    fun `the wizard's units - shown as the reader sees them, sent as kept`() {
        val labels = mapOf("kg" to "lb", "stappen" to "steps", "km" to "mi")
        assertEquals("lb", unitLabel("kg", labels))
        assertEquals("steps", unitLabel("stappen", labels))
        assertEquals("glazen", unitLabel("glazen", labels))           // none known: as it is
        assertEquals("kg", unitKey("lb", labels))
        assertEquals("stappen", unitKey(" steps ", labels))
        assertEquals("boeken", unitKey("boeken", labels))             // typed by the person: kept as typed
    }
}
