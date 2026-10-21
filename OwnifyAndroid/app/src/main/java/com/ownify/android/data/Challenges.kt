package com.ownify.android.data

import org.json.JSONArray
import org.json.JSONObject

/**
 * Uitdagingen (docs/CHALLENGES.md), as api/app/state.php sends it in
 * `challenges` — the same data Community, Uitdagingen and a challenge's page
 * are rendered from on the website (includes/challenges.php). Every figure
 * and sentence is the server's, in the reader's language and units; the app
 * only lays them out.
 */
data class Challenges(
    /** Migration 022 is in: without it Community shows only the leaderboard. */
    val available: Boolean,
    val you: ChallengePerson?,
    val invites: List<ChallengeInvite>,
    val running: List<ChallengeView>,
    val ended: List<ChallengeView>,
    /** Your friends, to invite to a new challenge. */
    val friends: List<ChallengePerson>,
    /** "2 bezig · 1 uitnodiging". */
    val summary: String,
    /** "Uitdagingen staan los van je punten op de ranglijst." */
    val note: String,
    val copy: ChallengeCopy,
    val metrics: List<ChallengeMetric>,
    val kinds: List<ChallengeKind>,
    val lengths: List<ChallengeLength>,
    /** Vanaf vandaag, Vanaf ma 19 okt. */
    val from: List<Pair<String, String>>,
    /** "%s samen", "Wie de meeste %s haalt", "Alleen jij, tot iemand meedoet". */
    val preview: Map<String, String>
) {
    fun view(id: Int): ChallengeView? = running.firstOrNull { it.id == id } ?: ended.firstOrNull { it.id == id }

    fun invite(id: Int): ChallengeInvite? = invites.firstOrNull { it.id == id }

    fun metric(key: String?): ChallengeMetric? = metrics.firstOrNull { it.key == key }

    fun length(key: String): ChallengeLength? = lengths.firstOrNull { it.key == key }

    companion object {
        fun parse(o: JSONObject?) = Challenges(
            available = o.bool("available"),
            you = o.obj("you")?.let(ChallengePerson::parse),
            invites = o.arr("invites").map(ChallengeInvite::parse),
            running = o.arr("running").map(ChallengeView::parse),
            ended = o.arr("ended").map(ChallengeView::parse),
            friends = o.arr("friends").map(ChallengePerson::parse),
            summary = o.str("summary").orEmpty(),
            note = o.str("note").orEmpty(),
            copy = ChallengeCopy(o.obj("copy")),
            metrics = o.arr("metrics").map(ChallengeMetric::parse),
            kinds = o.arr("kinds").map { ChallengeKind(it.str("key").orEmpty(), it.str("label").orEmpty(), it.str("hint").orEmpty()) },
            lengths = o.arr("lengths").map(ChallengeLength::parse),
            from = o.arr("from").map { (it.str("key") ?: return@map null) to it.str("label").orEmpty() },
            preview = o.obj("preview").stringMap()
        )
    }
}

/** The screens' copy (config/challenges.php): `copy["page"]["title"]`. */
class ChallengeCopy(private val o: JSONObject?) {
    operator fun get(group: String): Map<String, String> = o.obj(group).stringMap()

    fun text(group: String, key: String): String = o.obj(group).str(key).orEmpty()

    /** Uitdagingen's three ways to begin when there is nothing yet (`page.ideas`). */
    fun ideas(): List<ChallengeIdea> = o.obj("page").arr("ideas").map {
        ChallengeIdea(it.str("kind") ?: "together", it.str("metric").orEmpty(), it.str("title").orEmpty(), it.str("sub").orEmpty())
    }
}

/** A way to begin: Nieuwe uitdaging with [kind] and [metric] already chosen. */
data class ChallengeIdea(val kind: String, val metric: String, val title: String, val sub: String)

/**
 * Somebody in a challenge, with only what another participant may see: a
 * username, a picture as the boards show it, and — for a participant — the
 * total for this challenge.
 */
data class ChallengePerson(
    /** Set for a friend you can invite; never sent for other participants. */
    val id: Int?,
    val name: String,
    val initial: String,
    val accent: String,
    val avatar: String?,
    val you: Boolean,
    /** "Jij" for yourself, the username otherwise. */
    val label: String,
    val value: Double,
    val valueText: String,
    /** 0 for you, 1–4 for the quiet ink of the others, in order. */
    val tone: Int,
    val rank: Int,
    /** Samen: your share of the total. Wedstrijd: your total against the leader's. */
    val share: Float,
    val shareText: String
) {
    companion object {
        fun parse(o: JSONObject) = ChallengePerson(
            id = o.int("id"),
            name = o.str("name").orEmpty(),
            initial = o.str("initial").orEmpty(),
            accent = o.str("accent") ?: "health",
            avatar = o.str("avatar"),
            you = o.bool("you"),
            label = o.str("label") ?: o.str("name").orEmpty(),
            value = o.num("value") ?: 0.0,
            valueText = o.text("value_text").orEmpty(),
            tone = o.int("tone") ?: 0,
            rank = o.int("rank") ?: 0,
            share = (o.num("share") ?: 0.0).toFloat(),
            shareText = o.str("share_text").orEmpty()
        )
    }
}

/** The first few people in a challenge, how many more, and "5 deelnemers". */
data class Faces(val people: List<ChallengePerson>, val more: Int, val label: String) {
    companion object {
        fun parse(o: JSONObject?) = Faces(o.arr("people").map(ChallengePerson::parse), o.int("more") ?: 0, o.str("label").orEmpty())
    }
}

/** A piece of a Samen bar: you, or one of the others, and its share of the target. */
data class Segment(val you: Boolean, val tone: Int, val part: Float)

/**
 * The bar under a challenge's name. `stack`: everybody's piece side by side
 * and [rest] still to go. `lead`: your total against the leader's ([value]).
 */
data class ChallengeBar(val type: String, val segments: List<Segment>, val rest: Float, val value: Float, val label: String) {
    companion object {
        fun parse(o: JSONObject?) = ChallengeBar(
            type = o.str("type") ?: "stack",
            segments = o.arr("segments").map { Segment(it.bool("you"), it.int("tone") ?: 0, (it.num("part") ?: 0.0).toFloat()) },
            rest = (o.num("rest") ?: 0.0).toFloat(),
            value = (o.num("value") ?: 0.0).toFloat(),
            label = o.str("label").orEmpty()
        )
    }
}

/** A line in two weights: "**64%** 64 van 100", with a [sub] under or beside it. */
data class Line(val strong: String, val rest: String, val sub: String = "") {
    companion object {
        fun parse(o: JSONObject?) = Line(o.str("strong").orEmpty(), o.str("rest").orEmpty(), o.str("sub").orEmpty())
    }
}

/** A challenge's big figure: "293" "van 500 km", its line, and the dot's tone (ahead, behind, neutral). */
data class Big(val figure: String, val of: String, val line: String, val tone: String)

/** A challenge you are in, as its tile, its row and its page show it. */
data class ChallengeView(
    val id: Int,
    val name: String,
    /** together | versus */
    val kind: String,
    val metric: String,
    val icon: String,
    val accent: String,
    /** upcoming | running | overtime | ended */
    val state: String,
    val kindLabel: String,
    val kindLine: String,
    val left: String,
    val deadline: String,
    val subtitle: String,
    val people: List<ChallengePerson>,
    val peopleTitle: String,
    val countText: String,
    val faces: Faces,
    val pending: List<ChallengePerson>,
    val pendingText: String?,
    /** While it can be joined: the five digits, and the sentence to share them with. */
    val code: String?,
    val shareText: String?,
    val note: String,
    val canLeave: Boolean,
    /** Your friends not in it yet, to invite. */
    val invitable: List<ChallengePerson>,
    val bar: ChallengeBar,
    val tile: Line,
    val line: Line,
    val big: Big
) {
    val versus: Boolean get() = kind == "versus"

    companion object {
        fun parse(o: JSONObject): ChallengeView? {
            val id = o.int("id") ?: return null
            val pending = o.obj("pending")
            return ChallengeView(
                id = id,
                name = o.str("name").orEmpty(),
                kind = o.str("kind") ?: "together",
                metric = o.str("metric").orEmpty(),
                icon = o.str("icon") ?: "flag",
                accent = o.str("accent") ?: "training",
                state = o.str("state") ?: "running",
                kindLabel = o.str("kind_label").orEmpty(),
                kindLine = o.str("kind_line").orEmpty(),
                left = o.str("left").orEmpty(),
                deadline = o.str("deadline").orEmpty(),
                subtitle = o.str("subtitle").orEmpty(),
                people = o.arr("people").map(ChallengePerson::parse),
                peopleTitle = o.str("people_title").orEmpty(),
                countText = o.str("count_text").orEmpty(),
                faces = Faces.parse(o.obj("faces")),
                pending = pending.arr("people").map(ChallengePerson::parse),
                pendingText = pending.str("text"),
                code = o.str("code"),
                shareText = o.str("share_text"),
                note = o.str("note").orEmpty(),
                canLeave = o.bool("can_leave"),
                invitable = o.arr("invitable").map(ChallengePerson::parse),
                bar = ChallengeBar.parse(o.obj("bar")),
                tile = Line.parse(o.obj("tile")),
                line = Line.parse(o.obj("line")),
                big = o.obj("big").let { Big(it.str("figure").orEmpty(), it.str("of").orEmpty(), it.str("line").orEmpty(), it.str("tone") ?: "neutral") }
            )
        }
    }
}

/**
 * A challenge as somebody not in it yet sees it — an invitation, or what a
 * code found: what it is, who made it, who is in it and what they would see.
 * No totals.
 */
data class ChallengeReview(
    val id: Int,
    val name: String,
    val icon: String,
    val accent: String,
    val invited: Boolean,
    val code: String?,
    val title: String,
    val by: String,
    val facts: List<Pair<String, String>>,
    val peopleLabel: String,
    val faces: Faces,
    val privacy: String,
    val late: String?,
    val joined: String
) {
    companion object {
        fun parse(o: JSONObject?): ChallengeReview? {
            val id = o.int("id") ?: return null
            val facts = o.arr("facts")
            return ChallengeReview(
                id = id,
                name = o.str("name").orEmpty(),
                icon = o.str("icon") ?: "flag",
                accent = o.str("accent") ?: "training",
                invited = o.bool("invited"),
                code = o.str("code"),
                title = o.str("title").orEmpty(),
                by = o.str("by").orEmpty(),
                facts = facts.pairs(),
                peopleLabel = o.str("people_label").orEmpty(),
                faces = Faces.parse(o.obj("faces")),
                privacy = o.str("privacy").orEmpty(),
                late = o.str("late"),
                joined = o.str("joined").orEmpty()
            )
        }

        /** The facts: [label, value] pairs. */
        private fun JSONArray?.pairs(): List<Pair<String, String>> =
            if (this == null) emptyList() else (0 until length()).mapNotNull { i ->
                val pair = optJSONArray(i) ?: return@mapNotNull null
                pair.optString(0) to pair.optString(1)
            }
    }
}

/** An invitation waiting for you, on Community and Uitdagingen. */
data class ChallengeInvite(
    val id: Int,
    val name: String,
    val icon: String,
    val accent: String,
    val kindLine: String,
    val inviter: ChallengePerson?,
    /** "nodigt je uit voor …", after the inviter's name. */
    val inviteText: String,
    /** "Van bram · Bekijken". */
    val fromText: String,
    val faces: Faces,
    val review: ChallengeReview?
) {
    companion object {
        fun parse(o: JSONObject): ChallengeInvite? {
            val id = o.int("id") ?: return null
            return ChallengeInvite(
                id = id,
                name = o.str("name").orEmpty(),
                icon = o.str("icon") ?: "flag",
                accent = o.str("accent") ?: "training",
                kindLine = o.str("kind_line").orEmpty(),
                inviter = o.obj("inviter")?.let(ChallengePerson::parse),
                inviteText = o.str("invite_text").orEmpty(),
                fromText = o.str("from_text").orEmpty(),
                faces = Faces.parse(o.obj("faces")),
                review = ChallengeReview.parse(o.obj("review"))
            )
        }
    }
}

/**
 * What a challenge can count, in the reader's units: its patterns for one
 * and many ("%s training", "%s trainingen"), the stepper's step and the target
 * suggested per length — in what is shown (km or mi).
 */
data class ChallengeMetric(
    val key: String,
    val label: String,
    val icon: String,
    val accent: String,
    val one: String,
    val many: String,
    val what: String,
    val versus: String,
    val step: Double,
    val targets: Map<String, Double>
) {
    companion object {
        fun parse(o: JSONObject): ChallengeMetric? {
            val key = o.str("key") ?: return null
            val targets = o.obj("targets")
            return ChallengeMetric(
                key = key,
                label = o.str("label").orEmpty(),
                icon = o.str("icon") ?: "flag",
                accent = o.str("accent") ?: "training",
                one = o.str("one") ?: "%s",
                many = o.str("many") ?: "%s",
                what = o.str("what").orEmpty(),
                versus = o.str("versus").orEmpty(),
                step = o.num("step") ?: 1.0,
                targets = buildMap { targets?.keys()?.forEach { k -> targets.num(k)?.let { put(k, it) } } }
            )
        }
    }
}

data class ChallengeKind(val key: String, val label: String, val hint: String)

/** 1 week, 2 weken, 1 maand: their dates from today and from next Monday, and the name a Samen one suggests. */
data class ChallengeLength(val key: String, val label: String, val periods: Map<String, String>, val namePattern: String) {
    companion object {
        fun parse(o: JSONObject): ChallengeLength? {
            val key = o.str("key") ?: return null
            return ChallengeLength(key, o.str("label").orEmpty(), o.obj("periods").stringMap(), o.str("name_pattern") ?: "%s")
        }
    }
}

/** The Ranglijst card on Community: the top of your friends' board this month, and the line under it. */
data class BoardPreview(val title: String, val sub: String, val rows: List<BoardEntry>, val line: String) {
    companion object {
        fun parse(o: JSONObject?) = BoardPreview(
            title = o.str("title").orEmpty(),
            sub = o.str("sub").orEmpty(),
            rows = o.arr("rows").map {
                BoardEntry(it.int("rank") ?: 0, it.str("name").orEmpty(), it.int("points"), it.bool("self"), it.str("avatar"))
            },
            line = o.str("line").orEmpty()
        )
    }
}
