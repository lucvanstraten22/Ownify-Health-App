package com.ownify.android.data

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import org.json.JSONObject
import java.time.LocalTime

/**
 * The red dots: what waits for the person to do it, as the server worked it
 * out (lib/notices.php, docs/NOTICES.md) — the same summary the website
 * draws its dots from, so the two always agree.
 *
 *   [ratingWaiting]  today's Voeding cijfer is not given yet, shown from
 *                    [ratingHour] o'clock on this phone's clock
 *   [goals]          goals kept by hand that count days, not ticked today
 *   [friends]        the people whose friend request waits for an answer
 *   [invites]        the challenges you are invited to
 *   [sources]        the sources that stopped working
 *
 * Keys, as the website's notice_on() reads them: rating · goal:<id> ·
 * friend:<user id> · invite:<id> · source:<provider> · goals · sources ·
 * account · tab:health|goals|community|settings.
 */
data class Notices(
    val ratingWaiting: Boolean = false,
    val ratingHour: Int = 20,
    val goals: Set<String> = emptySet(),
    val friends: Set<String> = emptySet(),
    val invites: Set<String> = emptySet(),
    val sources: Set<String> = emptySet()
) {
    fun ratingDue(hourNow: Int): Boolean = ratingWaiting && hourNow >= ratingHour

    fun on(key: String, hourNow: Int): Boolean {
        val kind = key.substringBefore(':')
        val id = key.substringAfter(':', "")
        return when (kind) {
            "rating" -> ratingDue(hourNow)
            "goal" -> id in goals
            "friend" -> id in friends
            "invite" -> id in invites
            "source" -> id in sources
            "goals" -> goals.isNotEmpty()
            "sources" -> sources.isNotEmpty()
            "friends", "account" -> friends.isNotEmpty()
            "tab" -> when (id) {
                "health" -> ratingDue(hourNow)
                "goals" -> goals.isNotEmpty()
                "community" -> invites.isNotEmpty()
                "settings" -> sources.isNotEmpty()
                else -> false
            }
            else -> false
        }
    }

    companion object {
        fun parse(o: JSONObject?): Notices {
            val rating = o.obj("rating")
            return Notices(
                ratingWaiting = rating.bool("waiting"),
                ratingHour = rating.int("hour") ?: 20,
                goals = o.arr("goals").strings().toSet(),
                friends = o.arr("friends").strings().toSet(),
                invites = o.arr("invites").strings().toSet(),
                sources = o.arr("sources").strings().toSet()
            )
        }
    }
}

/**
 * The dots as they stand on this phone: the server's summary of the last
 * read, and the hour of this phone's clock, so the Voeding dot turns up at
 * its hour on a screen left open ([tick], from the app's root).
 */
object OwnifyNotices {
    var current: Notices by mutableStateOf(Notices())
        private set

    /** This phone's clock; a test sets its own. */
    var clock: () -> LocalTime = { LocalTime.now() }

    var hour: Int by mutableIntStateOf(LocalTime.now().hour)
        private set

    fun adopt(notices: Notices) { current = notices; tick() }

    fun tick() { hour = clock().hour }

    fun on(key: String): Boolean = current.on(key, hour)
}
