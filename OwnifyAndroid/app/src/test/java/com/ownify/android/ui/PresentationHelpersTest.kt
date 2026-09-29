package com.ownify.android.ui

import com.ownify.android.connection.OwnifySyncOutcomeKind
import com.ownify.android.ui.screens.account.memberSince
import com.ownify.android.ui.screens.community.avatarAccent
import com.ownify.android.ui.screens.community.initial
import com.ownify.android.ui.screens.community.points
import com.ownify.android.ui.screens.goals.dutchNumber
import com.ownify.android.ui.screens.goals.slotNote
import com.ownify.android.ui.screens.goals.spanText
import com.ownify.android.ui.screens.settings.moment
import com.ownify.android.ui.screens.settings.outcomeText
import java.time.LocalDate
import java.time.ZoneId
import java.time.ZonedDateTime
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * The few things the app writes out itself rather than reading them from
 * the server. None of them is a calculation — they format what the server
 * sent — and each must write exactly what the website's own helper writes.
 * The expected values are the website's output, taken from its PHP and
 * JavaScript (lib/community.php, lib/goals.php, lib/settings.php,
 * assets/js/goal-wizard.js, account-modal.php's date('j M Y')).
 */
class PresentationHelpersTest {

    @Test
    fun `points - community_points(), the Dutch thousands dot, a dash for none`() {
        assertEquals("0", points(0))
        assertEquals("7", points(7))
        assertEquals("45", points(45))
        assertEquals("1.234", points(1234))
        assertEquals("1.000.000", points(1_000_000))
        assertEquals("—", points(null))
    }

    @Test
    fun `avatar accent and initial - community_avatar_accent() and community_initial()`() {
        val expected = mapOf(
            "sanne_7001" to ("activity" to "S"),
            "anna_7001" to ("nutrition" to "A"),
            "bram_7001" to ("activity" to "B"),
            "cas_7001" to ("health" to "C"),
            "dewi_7001" to ("activity" to "D"),
            "" to ("health" to ""),
            "élise" to ("activity" to "É"),
            "Ömer" to ("activity" to "Ö"),
        )
        for ((name, pair) in expected) {
            assertEquals("accent of '$name'", pair.first, avatarAccent(name))
            assertEquals("initial of '$name'", pair.second, initial(name))
        }
        assertEquals(avatarAccent(""), avatarAccent(null))
    }

    @Test
    fun `member since - PHP's date('j M Y')`() {
        assertEquals("27 Sep 2026", memberSince("2026-09-27 14:35:39"))
        assertEquals("3 Jan 2026", memberSince("2026-01-03 00:00:00"))
        assertEquals("", memberSince(null))
        assertEquals("", memberSince("not a date"))
    }

    @Test
    fun `slot note - goals_slot_note() in the page's own words`() {
        val labels = mapOf(
            "slots_full" to "Je drie doelplekken zijn bezet.",
            "slots_one" to "Nog één doelplek vrij.",
            "slots_free" to "Nog %d doelplekken vrij."
        )
        assertEquals("Je drie doelplekken zijn bezet.", slotNote(0, labels))
        assertEquals("Nog één doelplek vrij.", slotNote(1, labels))
        assertEquals("Nog 3 doelplekken vrij.", slotNote(3, labels))
    }

    @Test
    fun `span - goal-wizard's spanText()`() {
        val expected = mapOf(
            1 to "1 dag", 7 to "7 dagen", 30 to "30 dagen", 44 to "44 dagen",
            45 to "6 weken", 59 to "8 weken", 60 to "2 maanden", 182 to "6 maanden",
            329 to "11 maanden", 330 to "1 jaar", 365 to "1 jaar", 547 to "1 jaar", 730 to "2 jaar"
        )
        for ((days, text) in expected) assertEquals("$days days", text, spanText(days))
    }

    @Test
    fun `numbers - goal-wizard's dutchNumber(), toLocaleString('nl-NL') to two decimals`() {
        val expected = mapOf(
            "10000" to "10.000", "8000" to "8.000", "1234567" to "1.234.567",
            "2,5" to "2,5", "2.345" to "2,35", "2.355" to "2,36", "0.005" to "0,01",
            "abc" to "abc", "" to "0", "12 " to "12", "-3,456" to "-3,46", "1e3" to "1.000"
        )
        for ((typed, text) in expected) assertEquals("'$typed'", text, dutchNumber(typed))
    }

    @Test
    fun `a sync moment - settings_sync_label()`() {
        val zone = ZoneId.systemDefault()
        val today = LocalDate.of(2026, 9, 27)
        fun at(date: LocalDate, h: Int, m: Int) = ZonedDateTime.of(date.atTime(h, m), zone).toInstant()

        assertEquals("Vandaag, 14:35", moment(at(today, 14, 35), today))
        assertEquals("Gisteren, 09:12", moment(at(today.minusDays(1), 9, 12), today))
        assertEquals("3-9-2026, 18:02", moment(at(LocalDate.of(2026, 9, 3), 18, 2), today))
    }

    @Test
    fun `every outcome of a sync has its own line`() {
        val lines = OwnifySyncOutcomeKind.entries.map(::outcomeText)
        assertEquals(lines.size, lines.toSet().size)
        assertTrue(lines.all { it.isNotBlank() })
    }
}
