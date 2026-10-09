package com.ownify.android.data

import org.json.JSONObject

/**
 * Everything the signed-in app shows, as api/app/state.php sends it — the
 * data the website's templates are rendered from. Nothing in here is worked
 * out on the phone: scores, percentages, dates, chart geometry and every
 * sentence are the server's. See docs/APP-STATE.md in the backend.
 */
data class AppData(
    val app: AppInfo,
    val welcome: WelcomeCopy,
    val header: HeaderCopy,
    val navigation: List<NavItem>,
    val ai: AiCopy,
    val disclaimer: String,
    val today: Today,
    val focusLabel: String,
    val overview: Overview,
    val health: Health,
    val goals: Goals,
    val community: Community,
    val settings: Settings,
    val auth: Auth,
    /** The Scorekompas behind the Overzicht score (includes/score-compass.php). */
    val compass: Compass = Compass.parse(null),
    /** The setup a new account starts with (includes/setup.php); `pending` routes the app to it. */
    val setup: Setup = Setup.parse(null),
    /** Overzicht's card of the first days, or null outside them. */
    val calibration: Calibration? = null
) {
    companion object {
        fun parse(data: JSONObject): AppData {
            val focus = data.str("focus") ?: "general"
            val focusLabels = data.obj("focus_labels")
            return AppData(
                app = AppInfo.parse(data.obj("app")),
                welcome = WelcomeCopy.parse(data.obj("welcome")),
                header = HeaderCopy.parse(data.obj("header")),
                navigation = data.arr("navigation").map(NavItem::parse),
                ai = AiCopy.parse(data.obj("ai")),
                disclaimer = data.str("disclaimer").orEmpty(),
                today = Today(data.obj("today").str("weekday").orEmpty(), data.obj("today").str("date").orEmpty()),
                focusLabel = focusLabels.str(focus) ?: focusLabels.str("general").orEmpty(),
                overview = Overview.parse(data),
                health = Health.parse(data.obj("health")),
                goals = Goals.parse(data.obj("goals")),
                community = Community.parse(data.obj("community")),
                settings = Settings.parse(data.obj("settings")),
                auth = Auth.parse(data.obj("auth")),
                compass = Compass.parse(data.obj("compass")),
                setup = Setup.parse(data.obj("setup")),
                calibration = Calibration.parse(data.obj("calibration"))
            )
        }
    }
}

// ---------------------------------------------------------------- shell

data class AppInfo(val name: String, val tagline: String) {
    companion object {
        fun parse(o: JSONObject?) = AppInfo(o.str("name").orEmpty(), o.str("tagline").orEmpty())
    }
}

data class WelcomeCopy(val subtitle: String, val login: String, val register: String) {
    companion object {
        fun parse(o: JSONObject?) = WelcomeCopy(
            o.str("subtitle").orEmpty(), o.str("login") ?: "Inloggen", o.str("register") ?: "Registreren"
        )
    }
}

data class HeaderCopy(
    val devicesLabel: String,
    val devicesAria: String,
    val devicesEmpty: String,
    val devicesAdd: String,
    val accountLabel: String,
    val accountAria: String
) {
    companion object {
        fun parse(o: JSONObject?): HeaderCopy {
            val devices = o.obj("devices")
            val account = o.obj("account")
            return HeaderCopy(
                devices.str("label").orEmpty(), devices.str("aria").orEmpty(),
                devices.str("empty").orEmpty(), devices.str("add").orEmpty(),
                account.str("label").orEmpty(), account.str("aria").orEmpty()
            )
        }
    }
}

data class NavItem(val id: String, val label: String, val icon: String, val active: Boolean) {
    companion object {
        fun parse(o: JSONObject): NavItem? {
            val id = o.str("id") ?: return null
            return NavItem(id, o.str("label").orEmpty(), o.str("icon").orEmpty(), o.bool("active"))
        }
    }
}

/**
 * Ownify AI's words (config/dashboard.php → 'ai'), and [session]: the
 * account's answer to the consent question, whether the assistant can
 * answer now, and today's limit — so the sheet opens on the right screen
 * before anything is fetched. The conversation itself is api/ai/state.php
 * ([OwnifyAssistant]).
 */
data class AiCopy(
    val title: String,
    val status: String,
    val openAria: String,
    val closeLabel: String,
    val closeAria: String,
    val placeholder: String,
    val send: String,
    val composerAria: String,
    val newChat: String,
    val history: String,
    val historyEmpty: String,
    val deleteChat: String,
    val thinking: String,
    val remaining: String,
    val retry: String,
    val empty: AiEmptyCopy,
    val consent: AiConsentCopy,
    val declined: AiDeclinedCopy,
    val errors: Map<String, String>,
    val session: AiSession
) {
    /** One of the server's sentences for [code], or its general one. */
    fun error(code: String): String = errors[code] ?: errors["unavailable"] ?: "De AI-assistent is tijdelijk niet beschikbaar. Probeer het later opnieuw."

    /** "Nog 7 van 10 berichten vandaag", or nothing without a limit. */
    fun remainingText(usage: AiUsage): String =
        if (usage.limit <= 0) "" else remaining.replaceFirst("%d", usage.remaining.toString()).replaceFirst("%d", usage.limit.toString())

    companion object {
        fun parse(o: JSONObject?) = AiCopy(
            title = o.str("title").orEmpty(),
            status = o.str("status").orEmpty(),
            openAria = o.obj("open").str("aria").orEmpty(),
            closeLabel = o.obj("close").str("label").orEmpty(),
            closeAria = o.obj("close").str("aria").orEmpty(),
            placeholder = o.obj("composer").str("placeholder").orEmpty(),
            send = o.obj("composer").str("send") ?: "Versturen",
            composerAria = o.obj("composer").str("aria").orEmpty(),
            newChat = o.str("new_chat") ?: "Nieuw gesprek",
            history = o.str("history") ?: "Gesprekken",
            historyEmpty = o.str("history_empty") ?: "Nog geen gesprekken.",
            deleteChat = o.str("delete_chat") ?: "Gesprek verwijderen",
            thinking = o.str("thinking") ?: "Ownify AI denkt na…",
            remaining = o.str("remaining") ?: "Nog %d van %d berichten vandaag",
            retry = o.str("retry") ?: "Opnieuw proberen",
            empty = AiEmptyCopy(
                o.obj("empty").str("title").orEmpty(),
                o.obj("empty").str("body").orEmpty(),
                o.obj("empty").str("no_data").orEmpty(),
                o.obj("empty").arr("suggestions").strings()
            ),
            consent = AiConsentCopy(
                o.obj("consent").str("title").orEmpty(),
                o.obj("consent").str("intro").orEmpty(),
                o.obj("consent").arr("points").strings(),
                o.obj("consent").str("accept").orEmpty(),
                o.obj("consent").str("decline").orEmpty(),
                o.obj("consent").str("footer").orEmpty()
            ),
            declined = AiDeclinedCopy(
                o.obj("declined").str("title").orEmpty(),
                o.obj("declined").str("body").orEmpty(),
                o.obj("declined").str("review").orEmpty()
            ),
            errors = o.obj("errors").stringMap(),
            session = AiSession.parse(o.obj("session"))
        )
    }
}

data class AiEmptyCopy(val title: String, val body: String, val noData: String, val suggestions: List<String>)
data class AiConsentCopy(val title: String, val intro: String, val points: List<String>, val accept: String, val decline: String, val footer: String)
data class AiDeclinedCopy(val title: String, val body: String, val review: String)

/** Today's messages: [used] of [limit], [remaining] left. */
data class AiUsage(val used: Int, val limit: Int, val remaining: Int) {
    val out: Boolean get() = limit > 0 && remaining <= 0

    companion object {
        fun parse(o: JSONObject?) = AiUsage(o.int("used") ?: 0, o.int("limit") ?: 0, o.int("remaining") ?: 0)
    }
}

/**
 * The assistant at a glance (ai_summary() on the server): [consent] is
 * accepted, declined or unknown; [available] false with [unavailable]
 * ("unavailable" or "quota") and [notice] when it cannot answer now.
 */
data class AiSession(
    val consent: String,
    val available: Boolean,
    val unavailable: String?,
    val notice: String?,
    val usage: AiUsage,
    val hasData: Boolean
) {
    companion object {
        fun parse(o: JSONObject?) = AiSession(
            consent = o.str("consent") ?: "unknown",
            available = o?.optBoolean("available", false) ?: false,
            unavailable = o.str("unavailable"),
            notice = o.str("notice"),
            usage = AiUsage.parse(o.obj("usage")),
            hasData = o.bool("has_data")
        )
    }
}

data class Today(val weekday: String, val date: String)

// ------------------------------------------------------------- overzicht

data class Overview(
    val title: String,
    val overall: OverallScore,
    val contributors: List<Contributor>,
    val goal: GoalCard,
    val insights: Insights,
    val recommendation: Recommendation
) {
    companion object {
        fun parse(data: JSONObject): Overview {
            val scores = data.obj("scores")
            return Overview(
                title = data.obj("overview").str("title").orEmpty(),
                overall = OverallScore.parse(scores.obj("overall")),
                contributors = scores.arr("contributors").map(Contributor::parse),
                goal = GoalCard.parse(data.obj("goal")),
                insights = Insights.parse(data.obj("insights")),
                recommendation = Recommendation.parse(data.obj("recommendation"))
            )
        }
    }
}

data class OverallScore(
    val label: String,
    val value: Int?,
    val max: Int,
    val caption: String,
    val description: String,
    val emptyHint: String
) {
    companion object {
        fun parse(o: JSONObject?) = OverallScore(
            o.str("label").orEmpty(), o.int("value"), o.int("max") ?: 100,
            o.str("caption").orEmpty(), o.str("description").orEmpty(), o.str("empty_hint").orEmpty()
        )
    }
}

/**
 * One pillar under the Overzicht ring. [accent] is its category colour, fixed;
 * [scoreBand] is how high its score is (`high`, `mid`, `low`, or null without
 * a score), decided on the server — the colour of the dot beside it.
 */
data class Contributor(val area: String, val label: String, val accent: String, val value: Int?, val scoreBand: String? = null) {
    companion object {
        fun parse(o: JSONObject) = Contributor(
            o.str("area").orEmpty(), o.str("label").orEmpty(), o.str("accent").orEmpty(), o.int("value"), o.str("score_band")
        )
    }
}

// ----------------------------------------------------------- scorekompas

/**
 * The Scorekompas (pages/score-compass.php): the Health Score explained —
 * what it is made of, what is changing, the person's own earlier scores and
 * where the most room is. Every number and sentence is the server's
 * (includes/score-compass.php); the app only lays them out. Without a
 * `compass` in the state (an older server) everything reads as empty and
 * Overzicht's card does not open it.
 */
data class Compass(
    val available: Boolean,
    val title: String,
    val lede: String,
    val back: String,
    val open: String,
    val footnote: String,
    val score: Int?,
    val max: Int,
    val band: String?,
    /** Stijgend / Stabiel / Dalend, or null while there is too little to say. */
    val direction: CompassDirection?,
    val composition: CompassComposition,
    val trend: CompassTrend,
    val comparison: CompassComparison,
    val opportunity: CompassOpportunity
) {
    companion object {
        fun parse(o: JSONObject?) = Compass(
            available = o != null,
            title = o.str("title").orEmpty(),
            lede = o.str("lede").orEmpty(),
            back = o.str("back").orEmpty(),
            open = o.str("open").orEmpty(),
            footnote = o.str("footnote").orEmpty(),
            score = o.obj("score").int("value"),
            max = o.obj("score").int("max") ?: 100,
            band = o.obj("score").str("band"),
            direction = CompassDirection.parse(o.obj("direction")),
            composition = CompassComposition.parse(o.obj("composition")),
            trend = CompassTrend.parse(o.obj("trend")),
            comparison = CompassComparison.parse(o.obj("comparison")),
            opportunity = CompassOpportunity.parse(o.obj("opportunity"))
        )
    }
}

/** [key] `up`, `flat` or `down` — which way the arrow turns; [label] what it says. */
data class CompassDirection(val key: String, val label: String) {
    companion object {
        fun parse(o: JSONObject?): CompassDirection? {
            val key = o.str("key") ?: return null
            return CompassDirection(key, o.str("label").orEmpty())
        }
    }
}

data class CompassComposition(val title: String, val note: String, val categories: List<CompassCategory>) {
    companion object {
        fun parse(o: JSONObject?) = CompassComposition(
            o.str("title").orEmpty(), o.str("note").orEmpty(), o.arr("categories").map(CompassCategory::parse)
        )
    }
}

/**
 * One category of the score: its colour ([accent], which category) and its
 * score's band ([band], how high); [meta] its days, or what it still needs;
 * [summary] for a category of one component (Voeding), [parts] for the rest.
 */
data class CompassCategory(
    val id: String,
    val label: String,
    val accent: String,
    val icon: String,
    val value: Int?,
    val band: String?,
    val meta: String,
    val summary: String?,
    val parts: List<CompassPart>
) {
    companion object {
        fun parse(o: JSONObject) = CompassCategory(
            o.str("id").orEmpty(), o.str("label").orEmpty(), o.str("accent").orEmpty(), o.str("icon").orEmpty(),
            o.int("value"), o.str("band"), o.str("meta").orEmpty(), o.str("summary"), o.arr("parts").map(CompassPart::parse)
        )
    }
}

/** A component: [weight] its share of the category in % (null when it does not count), [note] what it rests on. */
data class CompassPart(
    val id: String,
    val label: String,
    val value: Int?,
    val band: String?,
    val weight: Int?,
    val counted: Boolean,
    val note: String?
) {
    companion object {
        fun parse(o: JSONObject) = CompassPart(
            o.str("id").orEmpty(), o.str("label").orEmpty(), o.int("value"), o.str("band"),
            o.int("weight"), o.bool("counted"), o.str("note")
        )
    }
}

/**
 * What is changing. [periods] are the score's history over 7 days, 30, 90
 * and a year — views of one score, never scores of their own — and [days]
 * every recorded day from the first with a score, which a finger on a
 * period's line reads. The fields before them are the 30 days alone, as a
 * server from before the periods sends them: [state] `empty`, `collecting`
 * or `filled`; [text] the sentences; the line in the 300 × 120 box.
 */
data class CompassTrend(
    val title: String,
    val state: String,
    val direction: CompassDirection?,
    val text: List<String>,
    val empty: String,
    val axis: List<String>,
    val aria: String,
    val chart: TrendChart,
    val width: Float,
    val height: Float,
    val defaultPeriod: String = "",
    val switchLabel: String = "",
    val readout: CompassReadout = CompassReadout("", "", emptyList()),
    val periods: List<CompassPeriod> = emptyList(),
    val days: List<CompassDay> = emptyList()
) {
    companion object {
        fun parse(o: JSONObject?): CompassTrend {
            val chart = o.obj("chart")
            val periods = o.arr("periods").map(CompassPeriod::parse)
            return CompassTrend(
                title = o.str("title").orEmpty(),
                state = o.str("state") ?: "empty",
                direction = CompassDirection.parse(o.obj("direction")),
                text = o.arr("text").strings(),
                empty = o.str("empty").orEmpty(),
                axis = o.arr("axis").strings(),
                aria = o.str("aria").orEmpty(),
                chart = if (chart == null) TrendChart(emptyList(), emptyList(), emptyList(), false) else TrendChart.parse(chart),
                width = (chart.num("width") ?: 300.0).toFloat(),
                height = (chart.num("height") ?: 120.0).toFloat(),
                defaultPeriod = o.str("default") ?: periods.firstOrNull()?.key.orEmpty(),
                switchLabel = o.str("switch").orEmpty(),
                readout = CompassReadout.parse(o.obj("readout")),
                periods = periods,
                days = o.arr("days").map(CompassDay::parse)
            )
        }
    }
}

/** What a day's reading is called, its [hint], and the categories' names and colours, once. */
data class CompassReadout(val score: String, val hint: String, val categories: List<CompassName>) {
    companion object {
        fun parse(o: JSONObject?) = CompassReadout(
            o.str("score").orEmpty(),
            o.str("hint").orEmpty(),
            o.arr("categories").map { CompassName(it.str("id") ?: return@map null, it.str("label").orEmpty(), it.str("accent").orEmpty()) }
        )
    }
}

data class CompassName(val id: String, val label: String, val accent: String)

/**
 * One period: its sentences and direction, [since] when the history is
 * younger than the period, and its line, drawn as Gezondheid's Verloop
 * draws its three: what a point is ([group]: `day`, `week`, `month` or
 * `half`), [points] what a reading shows of each — a day of the trend's
 * days, or a week or month as one ([CompassDay.detail]) — [at] each point's
 * place on the line in % (x left to right, y top to bottom, null without a
 * score) and [grid] the levels named on it. From a server from before the
 * points, [start] is where its first day is in the trend's days.
 */
data class CompassPeriod(
    val key: String,
    val label: String,
    val state: String,
    val direction: CompassDirection?,
    val text: List<String>,
    val empty: String,
    val since: String?,
    val start: Int,
    val dayDots: Boolean,
    val axis: List<CompassTick>,
    val aria: String,
    val chart: TrendChart,
    val width: Float,
    val height: Float,
    val at: List<Pair<Float, Float?>>,
    val group: String = "day",
    val points: List<CompassDay> = emptyList(),
    val grid: List<HistoryLevel> = emptyList(),
    val every: Boolean = false
) {
    companion object {
        fun parse(o: JSONObject): CompassPeriod? {
            val key = o.str("key") ?: return null
            val chart = o.obj("chart")
            val at = chart.arr("at")
            return CompassPeriod(
                key = key,
                label = o.str("label").orEmpty(),
                state = o.str("state") ?: "empty",
                direction = CompassDirection.parse(o.obj("direction")),
                text = o.arr("text").strings(),
                empty = o.str("empty").orEmpty(),
                since = o.str("since"),
                start = o.int("start") ?: 0,
                dayDots = o.bool("day_dots"),
                axis = o.arr("axis").map(CompassTick::parse),
                aria = o.str("aria").orEmpty(),
                chart = if (chart == null) TrendChart(emptyList(), emptyList(), emptyList(), false) else TrendChart.parse(chart),
                width = (chart.num("width") ?: 300.0).toFloat(),
                height = (chart.num("height") ?: 120.0).toFloat(),
                at = if (at == null) emptyList() else (0 until at.length()).mapNotNull { i ->
                    at.optJSONArray(i)?.let { p ->
                        p.optDouble(0).toFloat() to (if (p.isNull(1)) null else p.optDouble(1).toFloat())
                    }
                },
                group = o.str("group") ?: "day",
                points = o.arr("points").map(CompassDay::parse),
                grid = chart.arr("grid").map { HistoryLevel((it.num("y") ?: return@map null).toFloat(), it.str("label").orEmpty()) },
                every = o.bool("every")
            )
        }
    }
}

/**
 * A date under a chart over time, at [x] % from the left (lib/time-axis.php):
 * [label] in one line ("8 okt", "okt"); over 30 and 90 days also [day] over
 * its [month] — the month only where it begins — drawn in two rows.
 */
data class CompassTick(val label: String, val x: Float, val day: String? = null, val month: String? = null) {
    companion object {
        fun parse(o: JSONObject) = CompassTick(o.str("label").orEmpty(), (o.num("x") ?: 0.0).toFloat(), o.str("day"), o.str("month"))
    }
}

/**
 * One recorded day: its [label] ("5 oktober", "Vandaag"), its Health Score
 * and band, [state] `stored`, `carried`, `none` or `today`, and [note] —
 * that an earlier score still held, or that there was none. A week or a
 * month of a period's line reads as one: its days as [label], [detail] that
 * its scores are their mean, and no parts.
 */
data class CompassDay(
    val date: String,
    val label: String,
    val value: Int?,
    val band: String?,
    val state: String,
    val note: String?,
    val categories: List<CompassDayCategory>,
    val detail: String? = null
) {
    companion object {
        fun parse(o: JSONObject): CompassDay? {
            val date = o.str("date") ?: return null
            return CompassDay(
                date, o.str("label").orEmpty(), o.int("value"), o.str("band"), o.str("state") ?: "stored", o.str("note"),
                o.arr("categories").map { CompassDayCategory(it.str("id") ?: return@map null, it.int("value"), it.str("band"), it.str("parts")) },
                o.str("detail")
            )
        }
    }
}

/** A category on a day: its score and band, and [parts] its components in one line. */
data class CompassDayCategory(val id: String, val value: Int?, val band: String?, val parts: String?)

data class CompassComparison(val title: String, val note: String, val rows: List<CompassRow>, val delta: String?) {
    companion object {
        fun parse(o: JSONObject?) = CompassComparison(
            o.str("title").orEmpty(), o.str("note").orEmpty(), o.arr("rows").map(CompassRow::parse), o.obj("delta").str("text")
        )
    }
}

data class CompassRow(val id: String, val label: String, val value: Int?, val band: String?, val note: String) {
    companion object {
        fun parse(o: JSONObject) = CompassRow(
            o.str("id").orEmpty(), o.str("label").orEmpty(), o.int("value"), o.str("band"), o.str("note").orEmpty()
        )
    }
}

/** The component with the most room, or [empty] saying why there is none. */
data class CompassOpportunity(
    val title: String,
    val filled: Boolean,
    val empty: String?,
    val label: String?,
    val accent: String?,
    val icon: String?,
    val name: String?,
    val value: Int?,
    val band: String?,
    val fact: String?,
    val relation: String?,
    val gainText: String?
) {
    companion object {
        fun parse(o: JSONObject?) = CompassOpportunity(
            title = o.str("title").orEmpty(),
            filled = o.str("state") == "filled",
            empty = o.str("empty"),
            label = o.str("label"),
            accent = o.str("accent"),
            icon = o.str("icon"),
            name = o.str("name"),
            value = o.int("value"),
            band = o.str("band"),
            fact = o.str("fact"),
            relation = o.str("relation"),
            gainText = o.str("gain_text")
        )
    }
}

/** The Overzicht goal card (components/goal-progress.php). */
data class GoalCard(
    val title: String,
    val state: String,
    val name: String?,
    val progress: Int?,
    val unit: String,
    val headline: String,
    val description: String,
    val pending: String,
    val ctaLabel: String,
    val ctaEnabled: Boolean,
    val ctaNote: String,
    val milestones: List<Milestone>,
    /** What the bar says held or pointed at: "85 kg van 100 kg"; null without the goal's own numbers. */
    val reading: String? = null
) {
    companion object {
        fun parse(o: JSONObject?) = GoalCard(
            title = o.str("title").orEmpty(),
            state = o.str("state") ?: "unset",
            name = o.str("name"),
            progress = o.int("progress"),
            unit = o.str("unit").orEmpty(),
            headline = o.str("headline").orEmpty(),
            description = o.str("description").orEmpty(),
            pending = o.str("pending").orEmpty(),
            ctaLabel = o.obj("cta").str("label").orEmpty(),
            ctaEnabled = o.obj("cta").bool("enabled"),
            ctaNote = o.obj("cta").str("note").orEmpty(),
            milestones = o.arr("milestones").map { it }.let { list ->
                // `at`: where the stop sits on the bar, in percent (0, 50, 85, 100). An older
                // server did not say, and spread its stops evenly.
                list.mapIndexed { i, m ->
                    val even = if (list.size > 1) i * 100 / (list.size - 1) else 0
                    Milestone(m.str("label").orEmpty(), m.bool("reached"), m.int("at") ?: even)
                }
            },
            reading = o.str("reading")?.takeIf { it.isNotBlank() }
        )
    }
}

data class Milestone(val label: String, val reached: Boolean, val at: Int = 0)

data class Insights(val title: String, val subtitle: String, val items: List<Insight>) {
    companion object {
        fun parse(o: JSONObject?) = Insights(
            o.str("title").orEmpty(), o.str("subtitle").orEmpty(),
            o.arr("items").map {
                Insight(
                    it.str("icon").orEmpty(), it.str("accent").orEmpty(), it.str("title").orEmpty(),
                    it.str("body").orEmpty(), it.str("state") ?: "empty"
                )
            }
        )
    }
}

data class Insight(val icon: String, val accent: String, val title: String, val body: String, val state: String)

data class Recommendation(val title: String, val headline: String, val description: String, val note: String) {
    companion object {
        fun parse(o: JSONObject?) = Recommendation(
            o.str("title").orEmpty(), o.str("headline").orEmpty(), o.str("description").orEmpty(), o.str("note").orEmpty()
        )
    }
}

// ------------------------------------------------------------ gezondheid

/** Gezondheid: its areas, one area's week and month ([trend], on its detail), and the [history] the page charts. */
data class Health(val title: String, val lede: String, val areas: List<Area>, val trend: Trend, val history: HealthHistory? = null) {
    fun area(id: String): Area? = areas.firstOrNull { it.id == id }

    companion object {
        fun parse(o: JSONObject?) = Health(
            o.str("title").orEmpty(),
            o.str("lede").orEmpty(),
            o.obj("areas").entries { id, a -> Area.parse(id, a) },
            Trend.parse(o.obj("trend")),
            HealthHistory.parse(o.obj("history"))
        )
    }
}

/**
 * Gezondheid's Verloop (components/health-history.php): Slaap, Voeding and
 * Training as they were recorded, over the Scorekompas's periods — the
 * Scorekompas's history, three lines instead of its one score. Each period
 * carries its own points ([HistoryPeriod.points]: a day, a week or a month),
 * which the reading reads; from a server from before them, its
 * [HistoryPeriod.start] is where its days begin in the Scorekompas's own
 * list ([CompassTrend.days]). Null from a server from before the Verloop:
 * the week and month are shown then.
 */
data class HealthHistory(
    val title: String,
    val switchLabel: String,
    val defaultPeriod: String,
    val empty: String,
    val hint: String,
    val categories: List<CompassName>,
    val periods: List<HistoryPeriod>,
    val hintOne: String = hint
) {
    companion object {
        fun parse(o: JSONObject?): HealthHistory? {
            val periods = o.arr("periods").map(HistoryPeriod::parse)
            if (periods.isEmpty()) return null
            return HealthHistory(
                title = o.str("title").orEmpty(),
                switchLabel = o.str("switch").orEmpty(),
                defaultPeriod = o.str("default") ?: periods.first().key,
                empty = o.str("empty").orEmpty(),
                hint = o.str("hint").orEmpty(),
                categories = o.arr("categories").map { CompassName(it.str("id") ?: return@map null, it.str("label").orEmpty(), it.str("accent").orEmpty()) },
                periods = periods,
                hintOne = o.str("hint_one") ?: o.str("hint").orEmpty()
            )
        }
    }
}

/**
 * One period of the Verloop: what a point is ([group]: `day`, `week`,
 * `month`, or `half` — half a month, while the history is younger than half
 * a year), [start] where its days begin in the Scorekompas's list (a period
 * of days only), [since] when the history is younger than the period, which
 * points are a dot ([dots]: `every`, or `alone` — a point no line reaches),
 * its dates ([every]: one under each day), its [points], and a line per
 * category in the 300 × 160 box over the period's own height, with [x] each
 * point's place in % from the left and [grid] the levels named on it.
 */
data class HistoryPeriod(
    val key: String,
    val label: String,
    val group: String,
    val start: Int,
    val since: String?,
    val dots: String,
    val axis: List<CompassTick>,
    val every: Boolean,
    val aria: String,
    val points: List<HistoryPoint>,
    val width: Float,
    val height: Float,
    val hasData: Boolean,
    val x: List<Float>,
    val lines: List<HistoryLine>,
    val grid: List<HistoryLevel>
) {
    companion object {
        fun parse(o: JSONObject): HistoryPeriod? {
            val key = o.str("key") ?: return null
            val chart = o.obj("chart")
            return HistoryPeriod(
                key = key,
                label = o.str("label").orEmpty(),
                group = o.str("group") ?: "day",
                start = o.int("start") ?: 0,
                since = o.str("since"),
                dots = o.str("dots") ?: "alone",
                axis = o.arr("axis").map(CompassTick::parse),
                every = o.bool("every"),
                aria = o.str("aria").orEmpty(),
                points = o.arr("points").map(HistoryPoint::parse),
                width = (chart.num("width") ?: 300.0).toFloat(),
                height = (chart.num("height") ?: 120.0).toFloat(),
                hasData = chart.bool("has_data"),
                x = chart.arr("x").floats().map { it ?: 0f },
                lines = chart.arr("lines").map(HistoryLine::parse),
                grid = chart.arr("grid").map { HistoryLevel((it.num("y") ?: return@map null).toFloat(), it.str("label").orEmpty()) }
            )
        }
    }
}

/**
 * One point as the reading shows it: its date — a day's, or a week's or
 * month's days — what its scores are ([detail]: their days' mean), a [note]
 * when it had none (or a day's carried score), whether a day's scores were
 * carried, and each category's score in the order of the categories.
 */
data class HistoryPoint(val label: String, val detail: String?, val note: String?, val carried: Boolean, val values: List<Int?>) {
    companion object {
        fun parse(o: JSONObject): HistoryPoint = HistoryPoint(
            label = o.str("label").orEmpty(),
            detail = o.str("detail"),
            note = o.str("note"),
            carried = o.bool("carried"),
            values = o.arr("values").floats().map { it?.toInt() }
        )
    }
}

/** A level of the grid: its height in % from the top, and its score. */
data class HistoryLevel(val y: Float, val label: String)

/**
 * One category's line: its colour, its paths, and each point's height in %
 * from the top — null without a score; and [solo] the same line on its own,
 * over its own height, as that category's page draws it.
 */
data class HistoryLine(val id: String, val accent: String, val line: List<String>, val y: List<Float?>, val solo: HistorySolo? = null) {
    companion object {
        fun parse(o: JSONObject): HistoryLine? {
            val id = o.str("id") ?: return null
            val solo = o.obj("solo")?.let {
                HistorySolo(
                    it.arr("line").strings(),
                    it.arr("y").floats(),
                    it.arr("grid").map { g -> HistoryLevel((g.num("y") ?: return@map null).toFloat(), g.str("label").orEmpty()) },
                    it.bool("has_data"),
                    it.str("aria").orEmpty()
                )
            }
            return HistoryLine(id, o.str("accent").orEmpty(), o.arr("line").strings(), o.arr("y").floats(), solo)
        }
    }
}

/** A line on its own: its paths and heights over its own range, that range's levels, whether it has a point, its spoken label. */
data class HistorySolo(val line: List<String>, val y: List<Float?>, val grid: List<HistoryLevel>, val hasData: Boolean, val aria: String)

data class Score(val value: Int?, val max: Int)

data class Area(
    val id: String,
    val label: String,
    val icon: String,
    val accent: String,
    val score: Score,
    val summary: String,
    val empty: String,
    val rating: RatingCopy?,
    val ratingToday: Int?,
    val highlights: List<Metric>,
    val groups: List<MetricGroup>,
    val timeline: Timeline?,
    /**
     * The area drawn — Slaap's night and charts ([SleepView]), Training's
     * sessions, charts and heart rate ([TrainingView]) — instead of
     * [highlights] and [timeline]; null from a server from before it.
     */
    val view: AreaView? = null
) {
    companion object {
        fun parse(id: String, o: JSONObject) = Area(
            id = id,
            label = o.str("label").orEmpty(),
            icon = o.str("icon").orEmpty(),
            accent = o.str("accent").orEmpty(),
            score = Score(o.obj("score").int("value"), o.obj("score").int("max") ?: 100),
            summary = o.str("summary").orEmpty(),
            empty = o.str("empty").orEmpty(),
            rating = o.obj("rating")?.let {
                RatingCopy(
                    it.str("title").orEmpty(), it.str("label").orEmpty(), it.str("placeholder").orEmpty(),
                    it.str("button").orEmpty(), it.str("hint").orEmpty()
                )
            },
            ratingToday = o.int("rating_today"),
            highlights = o.arr("highlights").map(Metric::parse),
            groups = o.arr("groups").map { g ->
                MetricGroup(g.str("title").orEmpty(), g.str("hint"), g.arr("metrics").map(Metric::parse), g.bool("locked"))
            },
            timeline = o.obj("timeline")?.let { t ->
                Timeline(
                    t.str("title").orEmpty(), t.str("hint").orEmpty(),
                    t.arr("stages").map { s ->
                        Stage(s.str("key").orEmpty(), s.str("label").orEmpty(), s.str("tone").orEmpty(), s.num("share"))
                    }
                )
            },
            view = when (id) {
                "training" -> TrainingView.parse(o.obj("view"))
                else -> SleepView.parse(o.obj("view"))
            }
        )
    }
}

data class RatingCopy(val title: String, val label: String, val placeholder: String, val button: String, val hint: String)

/** One metric as the templates print it: [value] as PHP's `(string)` of it, or null when there is none. */
data class Metric(val key: String, val label: String, val unit: String, val value: String?, val availability: String?) {
    companion object {
        fun parse(o: JSONObject) = Metric(
            o.str("key").orEmpty(), o.str("label").orEmpty(), o.str("unit").orEmpty(),
            o.text("value")?.takeIf { it.isNotEmpty() }, o.str("availability")
        )
    }
}

data class MetricGroup(val title: String, val hint: String?, val metrics: List<Metric>, val locked: Boolean)

data class Timeline(val title: String, val hint: String, val stages: List<Stage>)

/** An area drawn: its [charts] over time (lib/area-charts.php), each small on its page and large on a page of its own. */
interface AreaView {
    val charts: List<AreaChart>
    fun chart(id: String): AreaChart? = charts.firstOrNull { it.id == id }
}

/**
 * Slaap, drawn (lib/hydrate-sleep.php, docs/SLEEP.md): the last [night]'s
 * stages as a timeline, and four [charts] over time — Tijd in bed +
 * Regelmaat, SpO₂, Huidtemperatuur, Hartslagvariabiliteit.
 */
data class SleepView(val night: SleepNight, override val charts: List<AreaChart>) : AreaView {

    companion object {
        fun parse(o: JSONObject?): SleepView? {
            val night = SleepNight.parse(o.obj("night") ?: return null)
            return SleepView(night, o.arr("charts").map(AreaChart::parse))
        }
    }
}

/**
 * The night: its [rows] (Wakker, Rusteloosheid, REM, Licht, Diep, each with
 * its time over the night), its [blocks] — each recorded period of a stage,
 * from bedtime at 0 % to wake time at 100 % — the times under it ([ticks]),
 * how long was slept ([asleep], "7:40") and how much of the time in bed
 * ([efficiency], %), its [date], and a [note] when it has no stages or
 * there is no night.
 */
data class SleepNight(
    val title: String,
    val hint: String,
    val rows: List<SleepRow>,
    val staged: Boolean,
    val date: String?,
    val start: String?,
    val end: String?,
    val asleep: String?,
    val asleepLabel: String,
    val efficiency: Int?,
    val efficiencyLabel: String,
    val blocks: List<SleepBlock>,
    val ticks: List<CompassTick>,
    val aria: String,
    val note: String?
) {
    companion object {
        fun parse(o: JSONObject) = SleepNight(
            title = o.str("title").orEmpty(),
            hint = o.str("hint").orEmpty(),
            rows = o.arr("rows").map { SleepRow(it.str("key").orEmpty(), it.str("label").orEmpty(), it.str("total")) },
            staged = o.bool("staged"),
            date = o.str("date"),
            start = o.str("start"),
            end = o.str("end"),
            asleep = o.str("asleep"),
            asleepLabel = o.str("asleep_label").orEmpty(),
            efficiency = o.int("efficiency"),
            efficiencyLabel = o.str("efficiency_label").orEmpty(),
            blocks = o.arr("blocks").let { a ->
                if (a == null) emptyList() else (0 until a.length()).mapNotNull { i ->
                    val b = a.optJSONArray(i) ?: return@mapNotNull null
                    SleepBlock(b.optInt(0), b.optDouble(1).toFloat(), b.optDouble(2).toFloat(), b.optString(3), b.optString(4))
                }
            },
            ticks = o.arr("ticks").map(CompassTick::parse),
            aria = o.str("aria").orEmpty(),
            note = o.str("note")
        )
    }
}

data class SleepRow(val key: String, val label: String, val total: String?)

/** A period of a stage: its [row], from and to in % of the night, and when it [began] and [ended] ("00:48"). */
data class SleepBlock(val row: Int, val from: Float, val to: Float, val began: String, val ended: String)

/**
 * One of an area's charts (lib/area-charts.php): its [series] (one line,
 * bars, or bars with a line), its [latest] value for the small chart's head,
 * and its [periods] — 7 dagen first, which the small chart draws.
 */
data class AreaChart(
    val id: String,
    val title: String,
    val open: String,
    val back: String,
    val switchLabel: String,
    val hint: String,
    val empty: String,
    val series: List<AreaSeries>,
    val latest: AreaLatest?,
    val defaultPeriod: String,
    val periods: List<AreaPeriod>
) {
    /** Bars and a line: drawn in two shades. */
    val mixed get() = series.map { it.kind }.distinct().size > 1

    companion object {
        fun parse(o: JSONObject): AreaChart? {
            val id = o.str("id") ?: return null
            val periods = o.arr("periods").map(AreaPeriod::parse)
            if (periods.isEmpty()) return null
            return AreaChart(
                id = id,
                title = o.str("title").orEmpty(),
                open = o.str("open").orEmpty(),
                back = o.str("back").orEmpty(),
                switchLabel = o.str("switch").orEmpty(),
                hint = o.str("hint").orEmpty(),
                empty = o.str("empty").orEmpty(),
                series = o.arr("series").map { AreaSeries(it.str("key") ?: return@map null, it.str("label").orEmpty(), it.str("kind") ?: "line") },
                latest = o.obj("latest")?.let { AreaLatest(it.arr("texts").let(::textsOf), it.str("date")) },
                defaultPeriod = o.str("default") ?: periods.first().key,
                periods = periods
            )
        }
    }
}

data class AreaSeries(val key: String, val label: String, val kind: String)

data class AreaLatest(val texts: List<String?>, val date: String?)

/**
 * A chart over one period (docs/CHARTS.md): its dates ([axis]; [axisRows]
 * the same in two rows, for the small chart), each point's place ([x], %
 * from the left) and reading ([points]), which days' value was carried
 * (Regelmaat), and its lines, bars and named levels in the 300 × 160 box.
 */
data class AreaPeriod(
    val key: String,
    val label: String,
    val group: String,
    val aria: String,
    val axis: List<CompassTick>,
    val axisRows: List<CompassTick>?,
    val x: List<Float>,
    val points: List<AreaPoint>,
    val carried: Set<Int>,
    val width: Float,
    val height: Float,
    val hasData: Boolean,
    val lines: List<AreaLine>,
    val bars: List<AreaBars>,
    val grid: List<HistoryLevel>
) {
    companion object {
        fun parse(o: JSONObject): AreaPeriod? {
            val key = o.str("key") ?: return null
            val points = o.arr("points").let { a ->
                if (a == null) emptyList() else (0 until a.length()).mapNotNull { i ->
                    val p = a.optJSONArray(i) ?: return@mapNotNull null
                    val all = textsOf(p)
                    AreaPoint(all.getOrNull(0).orEmpty(), all.getOrNull(1), all.getOrNull(2), all.drop(3))
                }
            }
            return AreaPeriod(
                key = key,
                label = o.str("label").orEmpty(),
                group = o.str("group") ?: "day",
                aria = o.str("aria").orEmpty(),
                axis = o.arr("axis").map(CompassTick::parse),
                axisRows = o.arr("axis_rows")?.map(CompassTick::parse),
                x = o.arr("x").floats().map { it ?: 0f },
                points = points,
                carried = o.arr("carried").floats().mapNotNull { it?.toInt() }.toSet(),
                width = (o.num("width") ?: 300.0).toFloat(),
                height = (o.num("height") ?: 160.0).toFloat(),
                hasData = o.bool("has_data"),
                lines = o.arr("lines").map { AreaLine(it.str("key") ?: return@map null, it.arr("line").strings(), it.arr("y").floats()) },
                bars = o.arr("bars").map { AreaBars(it.str("key") ?: return@map null, (it.num("w") ?: 0.0).toFloat(), it.arr("top").floats()) },
                grid = o.arr("grid").map { HistoryLevel((it.num("y") ?: return@map null).toFloat(), it.str("label").orEmpty()) }
            )
        }
    }
}

/** A point as the reading shows it: its date or days, [detail] (a week's or month's mean), a [note], each series' value as written. */
data class AreaPoint(val label: String, val detail: String?, val note: String?, val texts: List<String?>)

data class AreaLine(val key: String, val line: List<String>, val y: List<Float?>)

/** Bars from the bottom: [w] each one's width and [top] its height, in % of the plot. */
data class AreaBars(val key: String, val w: Float, val top: List<Float?>)

/**
 * Training, drawn (lib/hydrate-training.php, docs/TRAINING.md): the latest
 * [sessions] beside the sessions per day ([perDay]), four [charts] two by two
 * ([grid]), the [heart] rate across the page, HRV and Hartbelasting under it
 * ([lower]) — and each listed session's own page ([details]).
 */
data class TrainingView(
    val perDay: String,
    val grid: List<String>,
    val lower: List<String>,
    val sessions: TrainingSessions,
    override val charts: List<AreaChart>,
    val heart: HeartChart,
    val details: List<TrainingSession>
) : AreaView {
    fun session(id: String): TrainingSession? = details.firstOrNull { it.id == id }

    companion object {
        fun parse(o: JSONObject?): TrainingView? {
            val heart = HeartChart.parse(o.obj("heart") ?: return null) ?: return null
            val layout = o.obj("layout")
            val sessions = o.obj("sessions")
            return TrainingView(
                perDay = layout.str("per_day") ?: "sessions",
                grid = layout.arr("grid").strings(),
                lower = layout.arr("lower").strings(),
                sessions = TrainingSessions(
                    sessions.str("title").orEmpty(),
                    sessions.str("empty").orEmpty(),
                    sessions.arr("items").map {
                        TrainingSessionItem(
                            it.str("id") ?: return@map null, it.str("label").orEmpty(), it.str("date").orEmpty(),
                            it.str("time").orEmpty(), it.str("duration"), it.str("open").orEmpty()
                        )
                    }
                ),
                charts = o.arr("charts").map(AreaChart::parse),
                heart = heart,
                details = o.arr("details").map(TrainingSession::parse)
            )
        }
    }
}

data class TrainingSessions(val title: String, val empty: String, val items: List<TrainingSessionItem>)

/** A session in the list: its kind ("Hardlopen"), day ("Vandaag", "5 okt"), start time, how long, and what opening it says. */
data class TrainingSessionItem(val id: String, val label: String, val date: String, val time: String, val duration: String?, val open: String)

/**
 * The heart rate: Vandaag's [days] — today first, then the six before it —
 * each 00:00 to 24:00 every five minutes, and the [periods] 7 dagen to
 * 1 jaar of daily averages; the switch's [options] (d0 is Vandaag), and the
 * four [zones].
 */
data class HeartChart(
    val title: String,
    val label: String,
    val switchLabel: String,
    val hint: String,
    val empty: String,
    val prev: String,
    val next: String,
    val defaultKey: String,
    val options: List<Pair<String, String>>,
    val days: List<HeartPlot>,
    val periods: List<HeartPlot>,
    val zones: HeartZones
) {
    companion object {
        fun parse(o: JSONObject): HeartChart? {
            val days = o.arr("days").map(HeartPlot::parse)
            if (days.isEmpty()) return null
            return HeartChart(
                title = o.str("title").orEmpty(),
                label = o.str("label").orEmpty(),
                switchLabel = o.str("switch").orEmpty(),
                hint = o.str("hint").orEmpty(),
                empty = o.str("empty").orEmpty(),
                prev = o.str("prev").orEmpty(),
                next = o.str("next").orEmpty(),
                defaultKey = o.str("default") ?: "d0",
                options = o.arr("options").map { (it.str("key") ?: return@map null) to it.str("label").orEmpty() },
                days = days,
                periods = o.arr("periods").map(HeartPlot::parse),
                zones = HeartZones.parse(o.obj("zones"))
            )
        }
    }
}

/**
 * One heart-rate plot — a day, a period or a session: its points' places
 * ([x], %) and readings ([points]: time or date, zone or "weekgemiddelde",
 * note, bpm), each point's [zone], the points no line reaches ([lone]; null:
 * a dot on every point), its line, levels and dates, and where zones 2, 3
 * and 4 begin ([zonesY], % from the top; null without zones).
 */
data class HeartPlot(
    val key: String,
    val title: String?,
    val group: String,
    val aria: String,
    val empty: String?,
    val axis: List<CompassTick>,
    val x: List<Float>,
    val points: List<AreaPoint>,
    val zone: List<Int?>,
    val lone: Set<Int>?,
    val width: Float,
    val height: Float,
    val hasData: Boolean,
    val line: List<String>,
    val y: List<Float?>,
    val grid: List<HistoryLevel>,
    val zonesY: List<Float>?
) {
    companion object {
        fun parse(o: JSONObject): HeartPlot? {
            val line = o.arr("lines")?.optJSONObject(0)
            return HeartPlot(
                key = o.str("key") ?: "session",
                title = o.str("title"),
                group = o.str("group") ?: "day",
                aria = o.str("aria").orEmpty(),
                empty = o.str("empty"),
                axis = o.arr("axis").map(CompassTick::parse),
                x = o.arr("x").floats().map { it ?: 0f },
                points = o.arr("points").let { a ->
                    if (a == null) emptyList() else (0 until a.length()).mapNotNull { i ->
                        val p = a.optJSONArray(i) ?: return@mapNotNull null
                        val all = textsOf(p)
                        AreaPoint(all.getOrNull(0).orEmpty(), all.getOrNull(1), all.getOrNull(2), all.drop(3))
                    }
                },
                zone = o.arr("zone").floats().map { it?.toInt() },
                lone = o.arr("lone")?.floats()?.mapNotNull { it?.toInt() }?.toSet(),
                width = (o.num("width") ?: 300.0).toFloat(),
                height = (o.num("height") ?: 160.0).toFloat(),
                hasData = o.bool("has_data"),
                line = line.arr("line").strings(),
                y = line.arr("y").floats(),
                grid = o.arr("grid").map { HistoryLevel((it.num("y") ?: return@map null).toFloat(), it.str("label").orEmpty()) },
                zonesY = o.arr("zones_y")?.floats()?.map { it ?: 0f }
            )
        }
    }
}

/** The zones' [labels] ("Zone 1" …), their [bands] (name and range), and what they are based on ([note]); no bands without zones. */
data class HeartZones(val labels: List<String>, val bands: List<Pair<String, String>>, val note: String) {
    companion object {
        fun parse(o: JSONObject?) = HeartZones(
            o.arr("labels").strings(),
            o.arr("bands").map { (it.str("label") ?: return@map null) to it.str("range").orEmpty() },
            o.str("note").orEmpty()
        )
    }
}

/** A session's own page: its kind, day and times, its figures ([stats]) and its heart rate minute by minute. */
data class TrainingSession(
    val id: String,
    val title: String,
    val date: String,
    val time: String,
    val back: String,
    val statsTitle: String,
    val stats: List<SessionStat>,
    val heartTitle: String,
    val heart: HeartPlot
) {
    companion object {
        fun parse(o: JSONObject): TrainingSession? {
            val heart = o.obj("heart") ?: return null
            return TrainingSession(
                id = o.str("id") ?: return null,
                title = o.str("title").orEmpty(),
                date = o.str("date").orEmpty(),
                time = o.str("time").orEmpty(),
                back = o.str("back").orEmpty(),
                statsTitle = o.str("stats_title").orEmpty(),
                stats = o.arr("stats").map { SessionStat(it.str("key").orEmpty(), it.str("label").orEmpty(), it.str("value") ?: return@map null, it.str("unit").orEmpty()) },
                heartTitle = heart.str("title").orEmpty(),
                heart = HeartPlot.parse(heart) ?: return null
            )
        }
    }
}

data class SessionStat(val key: String, val label: String, val value: String, val unit: String)

/** Strings and nulls, as written. */
private fun textsOf(a: org.json.JSONArray?): List<String?> =
    if (a == null) emptyList() else (0 until a.length()).map { i -> if (a.isNull(i)) null else a.opt(i)?.toString() }

data class Stage(val key: String, val label: String, val tone: String, val share: Double?)

data class Trend(
    val title: String,
    val empty: String,
    val ranges: List<TrendRange>,
    val width: Float,
    val height: Float,
    /** area → range → the line, as components/health-trend.php draws it. */
    val charts: Map<String, Map<String, TrendChart>>
) {
    companion object {
        fun parse(o: JSONObject?) = Trend(
            o.str("title").orEmpty(),
            o.str("empty").orEmpty(),
            o.obj("ranges").entries { key, r -> TrendRange(key, r.str("label").orEmpty(), r.arr("points").strings()) },
            (o.num("width") ?: 300.0).toFloat(),
            (o.num("height") ?: 120.0).toFloat(),
            o.obj("charts").entries { area, ranges ->
                area to ranges.entries { range, c -> range to TrendChart.parse(c) }.toMap()
            }.toMap()
        )
    }
}

data class TrendRange(val key: String, val label: String, val points: List<String>)

data class TrendChart(val line: List<String>, val area: List<String>, val dots: List<Pair<Float, Float>>, val hasData: Boolean) {
    companion object {
        fun parse(o: JSONObject): TrendChart {
            val dots = o.arr("dots")
            val list = if (dots == null) emptyList() else (0 until dots.length()).mapNotNull { i ->
                dots.optJSONArray(i)?.let { p -> p.optDouble(0).toFloat() to p.optDouble(1).toFloat() }
            }
            return TrendChart(o.arr("line").strings(), o.arr("area").strings(), list, o.bool("has_data"))
        }
    }
}

// ----------------------------------------------------------------- doelen

data class Goals(
    val title: String,
    val lede: String,
    val limits: Int,
    val views: List<Pair<String, String>>,
    val defaultView: String,
    val categories: List<GoalCategory>,
    val types: List<GoalType>,
    val durations: List<GoalDuration>,
    val labels: Map<String, String>,
    val emptyActive: GoalsEmpty,
    val emptyCompleted: GoalsEmpty,
    val detail: Map<String, String>,
    val wizard: WizardCopy,
    val wizardSources: List<SourceGroup>,
    val active: List<Goal>,
    val completed: List<Goal>,
    val primary: Goal?,
    val secondary: List<Goal>,
    val used: Int,
    val slotsLeft: Int,
    val canAdd: Boolean,
    val all: List<Goal>
) {
    fun goal(id: String): Goal? = all.firstOrNull { it.id == id }

    companion object {
        fun parse(o: JSONObject?): Goals {
            val empty = o.obj("empty")
            // One object per goal: the lists name the goals `all` holds in full.
            val all = o.arr("all").map(Goal::parse)
            fun same(goal: Goal?): Goal? = goal?.let { g -> all.firstOrNull { it.id == g.id } ?: g }
            val used = o.int("used") ?: 0
            val slotsLeft = o.int("slots_left") ?: 0
            return Goals(
                title = o.str("title").orEmpty(),
                lede = o.str("lede").orEmpty(),
                // The server's limit (config/goals.php `limits` → `active`); an
                // answer without it still says what is used and what is left.
                limits = o.obj("limits").int("active") ?: (used + slotsLeft),
                views = o.obj("views").entries { key, v -> key to v.str("label").orEmpty() },
                defaultView = o.str("default_view") ?: "active",
                categories = o.obj("categories").entries { key, c ->
                    GoalCategory(key, c.str("label").orEmpty(), c.str("icon").orEmpty(), c.str("accent").orEmpty(), c.arr("units").strings())
                },
                types = o.obj("types").entries { key, t -> GoalType(key, t.str("label").orEmpty(), t.str("hint").orEmpty()) },
                durations = o.obj("durations").entries { key, d -> GoalDuration(key, d.str("label").orEmpty(), d.int("days") ?: 0) },
                labels = o.obj("labels").stringMap(),
                emptyActive = GoalsEmpty.parse(empty.obj("active")),
                emptyCompleted = GoalsEmpty.parse(empty.obj("completed")),
                detail = o.obj("detail").stringMap(),
                wizard = WizardCopy.parse(o.obj("wizard")),
                wizardSources = o.arr("wizard_sources").map(SourceGroup::parse),
                active = o.arr("active").map { same(Goal.parse(it)) },
                completed = o.arr("completed").map { same(Goal.parse(it)) },
                primary = same(o.obj("primary")?.let(Goal::parse)),
                secondary = o.arr("secondary").map { same(Goal.parse(it)) },
                used = used,
                slotsLeft = slotsLeft,
                canAdd = o.bool("can_add"),
                all = all
            )
        }
    }
}

data class GoalCategory(val key: String, val label: String, val icon: String, val accent: String, val units: List<String>)
data class GoalType(val key: String, val label: String, val hint: String)
data class GoalDuration(val key: String, val label: String, val days: Int)

data class GoalsEmpty(val title: String, val body: String, val cta: String?) {
    companion object {
        fun parse(o: JSONObject?) = GoalsEmpty(o.str("title").orEmpty(), o.str("body").orEmpty(), o.str("cta"))
    }
}

/** The wizard's copy: its steps and every label (config/goals.php `wizard`). */
data class WizardCopy(val steps: List<WizardStep>, val copy: Map<String, String>, val suggestions: Map<String, List<String>>, val targetLede: Map<String, String>) {
    operator fun get(key: String): String = copy[key].orEmpty()

    companion object {
        fun parse(o: JSONObject?): WizardCopy {
            val suggestions = LinkedHashMap<String, List<String>>()
            o.obj("suggestions")?.let { s ->
                val keys = s.keys()
                while (keys.hasNext()) {
                    val key = keys.next()
                    suggestions[key] = s.optJSONArray(key).strings()
                }
            }
            return WizardCopy(
                steps = o.obj("steps").entries { key, s ->
                    WizardStep(key, s.str("label").orEmpty(), s.str("title").orEmpty(), s.str("lede").orEmpty())
                },
                copy = o.stringMap(),
                suggestions = suggestions,
                targetLede = o.obj("target_lede").stringMap()
            )
        }
    }
}

data class WizardStep(val key: String, val label: String, val title: String, val lede: String)

data class SourceGroup(val domain: String, val label: String, val sources: List<GoalSource>) {
    companion object {
        fun parse(o: JSONObject) = SourceGroup(
            o.str("domain").orEmpty(), o.str("label").orEmpty(),
            o.arr("sources").map {
                GoalSource(
                    it.str("kind").orEmpty(), it.str("key").orEmpty(), it.str("label").orEmpty(),
                    it.str("unit").orEmpty(), it.str("hint").orEmpty(), it.arr("types").strings()
                )
            }
        )
    }
}

data class GoalSource(val kind: String, val key: String, val label: String, val unit: String, val hint: String, val types: List<String>)

/** One goal, as lib/goals.php expands it for the card and the detail page. */
data class Goal(
    val id: String,
    val name: String,
    val category: String,
    val categoryLabel: String,
    val icon: String,
    val accent: String,
    val type: String,
    val typeLabel: String,
    val priority: String,
    val status: String,
    val percent: Int?,
    val ratio: Double,
    val currentTitle: String,
    val currentLabel: String,
    val targetLabel: String,
    val extraFacts: List<Pair<String, String>>,
    val isPrimary: Boolean,
    val isPaused: Boolean,
    val isCompleted: Boolean,
    val isManual: Boolean,
    val needsInput: Boolean,
    val startLabel: String,
    val endLabel: String?,
    val tookLabel: String?,
    val remaining: String?,
    val lineActive: String,
    val linePaused: String,
    val deadlineLine: String,
    val durationLabel: String?,
    val note: String?,
    val daysMet: Int,
    val daysTotal: Int,
    val daysChip: String?,
    val daysNote: String?,
    val days: List<GoalDay>,
    val entry: GoalEntry?,
    val sourceList: List<GoalSourceLine>,
    val activity: List<GoalActivity>,
    val chart: GoalChart?,
    val hasHistory: Boolean,
    val countsDays: Boolean
) {
    companion object {
        fun parse(o: JSONObject): Goal? {
            val id = o.text("id") ?: return null
            return Goal(
                id = id,
                name = o.str("name").orEmpty(),
                category = o.str("category").orEmpty(),
                categoryLabel = o.str("category_label").orEmpty(),
                icon = o.str("icon").orEmpty(),
                accent = o.str("accent").orEmpty(),
                type = o.str("type").orEmpty(),
                typeLabel = o.str("type_label").orEmpty(),
                priority = o.str("priority").orEmpty(),
                status = o.str("status").orEmpty(),
                percent = o.int("percent"),
                ratio = o.num("ratio") ?: 0.0,
                currentTitle = o.str("current_title").orEmpty(),
                currentLabel = o.str("current_label").orEmpty(),
                targetLabel = o.str("target_label").orEmpty(),
                extraFacts = o.arr("extra_facts").map { (it.str("label").orEmpty()) to (it.text("value").orEmpty()) },
                isPrimary = o.bool("is_primary"),
                isPaused = o.bool("is_paused"),
                isCompleted = o.bool("is_completed"),
                isManual = o.bool("is_manual"),
                needsInput = o.bool("needs_input"),
                startLabel = o.str("start_label").orEmpty(),
                endLabel = o.str("end_label"),
                tookLabel = o.str("took_label"),
                remaining = o.str("remaining"),
                lineActive = o.str("line_active").orEmpty(),
                linePaused = o.str("line_paused").orEmpty(),
                deadlineLine = o.str("deadline_line").orEmpty(),
                durationLabel = o.str("duration_label"),
                note = o.str("note"),
                daysMet = o.int("days_met") ?: 0,
                daysTotal = o.int("days_total") ?: 0,
                daysChip = o.str("days_chip"),
                daysNote = o.str("days_note"),
                days = o.arr("days").map {
                    GoalDay(it.str("date"), it.str("state").orEmpty(), it.int("day"), it.str("title"))
                },
                entry = o.obj("entry")?.let {
                    GoalEntry(
                        it.str("kind").orEmpty(), it.str("label").orEmpty(), it.str("note"),
                        it.str("button"), it.str("placeholder"), it.bool("done"), it.text("value"), it.str("meta")
                    )
                },
                sourceList = o.arr("source_list").map {
                    GoalSourceLine(it.str("key").orEmpty(), it.str("label").orEmpty(), it.str("note").orEmpty(), it.str("icon").orEmpty(), it.str("accent").orEmpty())
                },
                activity = o.arr("activity").map { GoalActivity(it.str("label").orEmpty(), it.str("meta").orEmpty(), it.text("value").orEmpty()) },
                chart = o.obj("chart")?.let(GoalChart::parse),
                hasHistory = o.bool("has_history"),
                countsDays = o.bool("counts_days")
            )
        }
    }
}

data class GoalDay(val date: String?, val state: String, val day: Int?, val title: String?)

data class GoalEntry(
    val kind: String,
    val label: String,
    val note: String?,
    val button: String?,
    val placeholder: String?,
    val done: Boolean,
    val value: String?,
    val meta: String?
)

data class GoalSourceLine(val key: String, val label: String, val note: String, val icon: String, val accent: String)
data class GoalActivity(val label: String, val meta: String, val value: String)

/** lib/goal-chart.php: geometry in a [width] × [height] view box, positions in percent. */
data class GoalChart(
    val hasData: Boolean,
    val width: Float,
    val height: Float,
    val points: List<ChartPoint>,
    val line: List<String>,
    val area: List<String>,
    val xTicks: List<XTick>,
    val yTicks: List<YTick>,
    val target: ChartTarget?,
    val end: ChartEnd?,
    val axisUnit: String,
    val allDots: Boolean,
    val summary: String
) {
    companion object {
        fun parse(o: JSONObject) = GoalChart(
            hasData = o.bool("has_data"),
            width = (o.num("width") ?: 1000.0).toFloat(),
            height = (o.num("height") ?: 132.0).toFloat(),
            points = o.arr("points").map {
                ChartPoint((it.num("x") ?: 0.0).toFloat(), (it.num("y") ?: 0.0).toFloat(), it.str("d").orEmpty(), it.str("v").orEmpty(), it.bool("dot"), it.str("n").orEmpty())
            },
            line = o.arr("line").strings(),
            area = o.arr("area").strings(),
            xTicks = o.arr("x_ticks").map { XTick((it.num("left") ?: 0.0).toFloat(), it.str("label").orEmpty(), it.str("align") ?: "center", it.str("day"), it.str("month")) },
            yTicks = o.arr("y_ticks").map { YTick((it.num("top") ?: 0.0).toFloat(), it.str("label").orEmpty()) },
            target = o.obj("target")?.let { ChartTarget((it.num("top") ?: 0.0).toFloat(), it.str("label").orEmpty()) },
            end = o.obj("end")?.let {
                ChartEnd((it.num("x") ?: 0.0).toFloat(), (it.num("y") ?: 0.0).toFloat(), it.str("label").orEmpty(), it.bool("below"), it.str("align") ?: "end")
            },
            axisUnit = o.str("axis_unit").orEmpty(),
            allDots = o.bool("all_dots"),
            summary = o.str("summary").orEmpty()
        )
    }
}

/** [note]: what the day did and how far the goal was then ("+ 2,5 km · 62% van je doel"); empty when nothing is known. */
data class ChartPoint(val x: Float, val y: Float, val date: String, val value: String, val dot: Boolean, val note: String = "")
data class XTick(val left: Float, val label: String, val align: String, val day: String? = null, val month: String? = null)
data class YTick(val top: Float, val label: String)
data class ChartTarget(val top: Float, val label: String)
data class ChartEnd(val x: Float, val y: Float, val label: String, val below: Boolean, val align: String)

// -------------------------------------------------------------- community

data class Community(
    val title: String,
    val scopes: List<BoardScope>,
    val periods: List<Pair<String, String>>,
    val defaultScope: String,
    val defaultPeriod: String,
    val youName: String,
    /** Your picture for your own line where a board's top does not reach you: as the boards show it, or null. */
    val youAvatar: String?,
    val labels: Map<String, String>,
    val boards: Map<String, Map<String, Board>>,
    val friends: List<Person>,
    val pending: List<Person>,
    val sent: List<Person>,
    val allowRequests: Boolean,
    val badgesTitle: String,
    val badgesBody: String,
    /** "Vrienden toevoegen": the first row of every Vrienden board, never Nederland's. */
    val addFriends: String? = null
) {
    companion object {
        fun parse(o: JSONObject?) = Community(
            title = o.str("title").orEmpty(),
            scopes = o.obj("scopes").entries { key, s ->
                BoardScope(key, s.str("label").orEmpty(), s.int("limit") ?: 50, s.obj("empty").str("title").orEmpty(), s.obj("empty").str("body").orEmpty())
            },
            periods = o.obj("periods").entries { key, p -> key to p.str("label").orEmpty() },
            defaultScope = o.str("default_scope") ?: "friends",
            defaultPeriod = o.str("default_period") ?: "month",
            youName = o.obj("you").str("name") ?: "Jij",
            youAvatar = o.obj("you").str("avatar"),
            labels = o.obj("labels").stringMap(),
            boards = o.obj("boards").entries { scope, periods ->
                scope to periods.entries { period, b -> period to Board.parse(b) }.toMap()
            }.toMap(),
            friends = o.arr("friends").map(Person::parse),
            pending = o.arr("pending").map(Person::parse),
            sent = o.arr("sent").map(Person::parse),
            allowRequests = o.bool("allow_requests"),
            badgesTitle = o.obj("badges").str("title").orEmpty(),
            badgesBody = o.obj("badges").str("body").orEmpty(),
            addFriends = o.str("add_friends")?.takeIf { it.isNotBlank() }
        )
    }
}

data class BoardScope(val key: String, val label: String, val limit: Int, val emptyTitle: String, val emptyBody: String)

data class Board(val entries: List<BoardEntry>, val youRank: Int?, val youPoints: Int?) {
    companion object {
        fun parse(o: JSONObject) = Board(
            o.arr("entries").map {
                BoardEntry(it.int("rank") ?: 0, it.str("name").orEmpty(), it.int("points"), it.bool("self"), it.str("avatar"))
            },
            o.obj("you").int("rank"),
            o.obj("you").int("points")
        )
    }
}

data class BoardEntry(val rank: Int, val name: String, val points: Int?, val self: Boolean, val avatar: String?)

/** Somebody in the friends lists: [id] names them in a request, never the account itself. */
data class Person(val id: Int, val username: String, val avatar: String?) {
    companion object {
        fun parse(o: JSONObject): Person? {
            val id = o.int("user_id") ?: o.int("id") ?: return null
            return Person(id, o.str("username").orEmpty(), o.str("avatar_path") ?: o.str("avatar"))
        }
    }
}

// ----------------------------------------------------------- instellingen

data class Settings(
    val title: String,
    val lede: String,
    val groups: List<SettingsGroup>,
    val logoutLabel: String,
    val delete: Map<String, String>,
    val notSaved: String,
    val pages: List<SettingsPage>,
    val integrations: List<Integration>,
    val integrationLabels: Map<String, String>,
    val connectedCount: Int,
    val profile: Profile
) {
    fun page(id: String): SettingsPage? = pages.firstOrNull { it.id == id }

    companion object {
        fun parse(o: JSONObject?) = Settings(
            title = o.str("title").orEmpty(),
            lede = o.str("lede").orEmpty(),
            groups = o.arr("groups").map { g ->
                SettingsGroup(
                    g.str("label").orEmpty(),
                    g.arr("rows").map { r ->
                        SettingsRow(r.str("id").orEmpty(), r.str("icon").orEmpty(), r.str("label").orEmpty(), r.str("hint"), r.text("value"))
                    }
                )
            },
            logoutLabel = o.obj("actions").obj("logout").str("label") ?: "Uitloggen",
            delete = o.obj("actions").obj("delete").stringMap(),
            notSaved = o.str("not_saved").orEmpty(),
            pages = o.obj("pages").entries { id, p -> SettingsPage.parse(id, p) },
            integrations = o.arr("integrations").map(Integration::parse),
            integrationLabels = o.obj("integration_labels").stringMap(),
            connectedCount = o.int("connected_count") ?: 0,
            profile = Profile.parse(o.obj("profile"))
        )
    }
}

data class SettingsGroup(val label: String, val rows: List<SettingsRow>)
data class SettingsRow(val id: String, val icon: String, val label: String, val hint: String?, val value: String?)

data class Profile(val avatar: String?, val username: String?, val firstName: String?, val lastName: String?, val memberSince: String?) {
    companion object {
        fun parse(o: JSONObject?) = Profile(
            o.str("avatar"), o.str("username"), o.str("first_name"), o.str("last_name"), o.str("member_since")
        )
    }
}

data class SettingsPage(val id: String, val title: String, val icon: String, val lede: String?, val blocks: List<SettingsBlock>) {
    companion object {
        fun parse(id: String, o: JSONObject) = SettingsPage(
            id, o.str("title").orEmpty(), o.str("icon").orEmpty(), o.str("lede"),
            o.arr("blocks").map(SettingsBlock::parse)
        )
    }
}

sealed interface SettingsBlock {
    data object Identity : SettingsBlock
    data class Fields(val title: String, val lede: String?, val fields: List<ProfileField>) : SettingsBlock
    data class Signin(val title: String) : SettingsBlock
    data class Integrations(val title: String) : SettingsBlock
    /**
     * One option of several. [name] is the setting's (`theme`, `language`…);
     * [saves] marks the one choice that is kept — the theme, on this phone
     * (OwnifyThemeStore) — so its screen does not say choices are not kept.
     */
    data class Choice(
        val title: String,
        val selected: String?,
        val options: List<ChoiceOption>,
        val name: String? = null,
        val saves: Boolean = false
    ) : SettingsBlock
    data class States(val title: String, val lede: String?, val items: List<StateItem>) : SettingsBlock
    data class Toggles(val title: String, val lede: String?, val items: List<ToggleItem>) : SettingsBlock
    data class Rows(val title: String, val items: List<Pair<String, String>>) : SettingsBlock
    /** [title]: the heading it sits under, when it has one (Over de app's Hulp). */
    data class Note(val icon: String, val text: String, val title: String? = null) : SettingsBlock
    data class Actions(val title: String, val items: List<ActionItem>) : SettingsBlock
    /** A heading over the blocks after it, with its icon and one line (Voorkeuren: one per setting it holds). */
    data class Section(val title: String, val icon: String, val lede: String?) : SettingsBlock

    companion object {
        fun parse(o: JSONObject): SettingsBlock? =
            when (o.str("type")) {
                "identity" -> Identity
                "fields" -> Fields(o.str("title").orEmpty(), o.str("lede"), o.arr("fields").map(ProfileField::parse))
                "signin" -> Signin(o.str("title").orEmpty())
                "integrations" -> Integrations(o.str("title").orEmpty())
                "choice" -> Choice(
                    o.str("title").orEmpty(), o.str("selected"),
                    o.arr("options").map { ChoiceOption(it.str("key").orEmpty(), it.str("label").orEmpty(), it.str("note"), it.bool("disabled")) },
                    name = o.str("name"),
                    saves = o.bool("saves")
                )
                "states" -> States(
                    o.str("title").orEmpty(), o.str("lede"),
                    o.arr("items").map { StateItem(it.str("label").orEmpty(), it.text("value"), it.str("note")) }
                )
                "toggles" -> Toggles(
                    o.str("title").orEmpty(), o.str("lede"),
                    o.arr("items").map {
                        ToggleItem(it.str("label").orEmpty(), it.str("note"), it.bool("on"), it.str("key"), it.str("note_on"), it.str("note_off"))
                    }
                )
                "rows" -> Rows(o.str("title").orEmpty(), o.arr("items").map { it.str("label").orEmpty() to it.text("value").orEmpty() })
                "note" -> Note(o.str("icon") ?: "info", o.str("text").orEmpty(), o.str("title")?.takeIf { it.isNotEmpty() })
                "actions" -> Actions(o.str("title").orEmpty(), o.arr("items").map(ActionItem::parse))
                "section" -> Section(o.str("title").orEmpty(), o.str("icon").orEmpty(), o.str("lede"))
                else -> null
            }
    }
}

data class ChoiceOption(val key: String, val label: String, val note: String?, val disabled: Boolean)

/**
 * The settings with [theme] ("system", "dark" or "light") as the theme choice:
 * Thema & uiterlijk's choice ticked on it, and the row on Instellingen that
 * names it saying so — lib/settings.php's settings_use_theme(). On the website
 * the server knows the browser's choice from its cookie; asked by the app it
 * has none and always says Systeem, so the app puts this phone's choice in its
 * place.
 */
fun Settings.withTheme(theme: String): Settings {
    val page = page("theme") ?: return this
    var label: String? = null
    val blocks = page.blocks.map { block ->
        if (block is SettingsBlock.Choice && block.name == "theme") {
            block.options.firstOrNull { it.key == theme }?.let { label = it.label }
            block.copy(selected = theme)
        } else {
            block
        }
    }
    val named = label
    return copy(
        pages = pages.map { if (it.id == page.id) it.copy(blocks = blocks) else it },
        groups = if (named == null) groups else groups.map { group ->
            group.copy(rows = group.rows.map { row -> if (row.id == "theme") row.copy(value = named) else row })
        }
    )
}

/** The same, for the whole of what the server sent. */
fun AppData.withTheme(theme: String): AppData = copy(settings = settings.withTheme(theme))

/**
 * A button that does one thing after "are you sure?" ("AI-gesprekken
 * wissen"): posted to [endpoint] with [fields], as the website posts it.
 */
data class ActionItem(
    val key: String,
    val label: String,
    val note: String?,
    val question: String,
    val confirm: String,
    val cancel: String,
    val endpoint: String,
    val fields: Map<String, String>,
    val danger: Boolean
) {
    companion object {
        fun parse(o: JSONObject): ActionItem? {
            val endpoint = o.str("endpoint") ?: return null
            // Only the app's own endpoints, as a relative path — never anywhere else.
            if (!endpoint.matches(Regex("^api/[a-z0-9/_-]+\\.php$"))) return null
            return ActionItem(
                o.str("key").orEmpty(), o.str("label").orEmpty(), o.str("note"),
                o.str("question").orEmpty(), o.str("confirm").orEmpty(), o.str("cancel").orEmpty(),
                endpoint, o.obj("fields").stringMap(), o.bool("danger")
            )
        }
    }
}
data class StateItem(val label: String, val value: String?, val note: String?)
/**
 * A switch. With a [key] it saves (api/profile/privacy.php): [on] and [note]
 * are the account's own, and [noteOn] / [noteOff] what it says either way;
 * without one it is shown off and disabled, as the feature does not exist yet.
 */
data class ToggleItem(
    val label: String,
    val note: String?,
    val on: Boolean,
    val key: String? = null,
    val noteOn: String? = null,
    val noteOff: String? = null
)

/** A profile field: `state` is "editable" (true), "once", "locked" or "derived". */
data class ProfileField(
    val key: String,
    val label: String,
    val note: String?,
    val kind: String?,
    val opens: String?,
    val unit: String?,
    val state: String,
    val value: String?,
    val blank: String,
    val input: FieldInput?
) {
    val live: Boolean get() = state == "editable" || state == "once"

    companion object {
        fun parse(o: JSONObject): ProfileField {
            val state = when (val s = o.opt("state")) {
                true -> "editable"
                false -> "locked"
                is String -> s
                else -> "editable"
            }
            return ProfileField(
                o.str("key").orEmpty(), o.str("label").orEmpty(), o.str("note"), o.str("kind"), o.str("opens"),
                o.str("unit"), state, o.str("value"), o.str("blank").orEmpty(),
                o.obj("input")?.let(FieldInput::parse)
            )
        }
    }
}

/** What the field editor puts on screen (settings_field_input()). */
data class FieldInput(
    val type: String,
    val value: String,
    val unit: String,
    val endpoint: String,
    val maxLength: Int?,
    val min: String?,
    val max: String?,
    val options: List<Pair<String, String>>
) {
    companion object {
        fun parse(o: JSONObject) = FieldInput(
            o.str("type") ?: "text", o.text("value").orEmpty(), o.str("unit").orEmpty(), o.str("endpoint").orEmpty(),
            o.int("maxlength"), o.text("min"), o.text("max"),
            o.obj("options").stringMap().entries.map { it.key to it.value }
        )
    }
}

data class Integration(
    val key: String,
    val provider: String?,
    val label: String,
    val icon: String,
    val note: String,
    val categories: List<String>,
    val status: String,
    val connected: Boolean,
    val account: String?,
    val lastSync: String?,
    val error: String?,
    val transport: String?,
    val available: Boolean,
    val blocked: String?,
    val devices: List<PairedDevice>,
    /** A cloud source's sync running now (`syncing`): the row says so. */
    val syncing: Boolean = false,
    /** What disconnecting means for this source, in place of the general line (Polar's). */
    val disconnectNote: String? = null,
    /** What the `account` row is called for this source (Polar: its watches), in place of "Account". */
    val accountLabel: String? = null
) {
    companion object {
        fun parse(o: JSONObject) = Integration(
            o.str("key").orEmpty(), o.str("provider"), o.str("label").orEmpty(), o.str("icon").orEmpty(), o.str("note").orEmpty(),
            o.arr("categories").strings(), o.str("status") ?: "disconnected", o.bool("connected"), o.str("account"),
            o.str("last_sync"), o.str("error"), o.str("transport"), o.bool("available"), o.str("blocked"),
            o.arr("devices").map { PairedDevice(it.int("id") ?: 0, it.str("label").orEmpty(), it.str("last_sync"), it.str("paired")) },
            o.bool("syncing"), o.str("disconnect_note"), o.str("account_label")
        )
    }
}

data class PairedDevice(val id: Int, val label: String, val lastSync: String?, val paired: String?)

// ------------------------------------------------------------------- auth

data class Auth(
    val signedIn: Boolean,
    val database: Boolean,
    val username: String?,
    val createdAt: String?,
    val avatar: String?,
    val googleAvailable: Boolean,
    val identities: List<Pair<String, String?>>
) {
    val hasGoogle: Boolean get() = identities.any { it.first == "google" }

    companion object {
        fun parse(o: JSONObject?): Auth {
            val user = o.obj("user")
            return Auth(
                o.bool("signed_in"), o.bool("database"),
                user.str("username"), user.str("created_at"), user.str("avatar_path"),
                o.obj("providers").bool("google"),
                o.arr("identities").map { (it.str("provider").orEmpty()) to it.str("email") }
            )
        }
    }
}

// ------------------------------------------------------------ the first days

/**
 * The setup a new account starts with (`setup`, lib/hydrate-setup.php):
 * while [pending], the app shows it instead of the pages, as the website
 * does. Its four steps arrive with every word and what the account has
 * already answered; an older server sends nothing, which reads as not
 * pending.
 */
data class Setup(
    val pending: Boolean,
    val title: String,
    /** "Stap %1\$d van %2\$d". */
    val count: String,
    val back: String,
    val next: String,
    val logout: String,
    /** The step to open on after a restart: past the focus once it is chosen. */
    val resume: String,
    val focus: SetupFocusStep,
    val connect: SetupConnectStep,
    val profile: SetupProfileStep,
    val goal: SetupGoalStep
) {
    /** The steps in their order, as ids: focus, connect, profile, goal. */
    val order: List<String> get() = listOf("focus", "connect", "profile", "goal")

    fun countText(index: Int): String = count.replace("%1\$d", (index + 1).toString()).replace("%2\$d", order.size.toString())

    fun label(step: String): String = when (step) {
        "focus" -> focus.label
        "connect" -> connect.label
        "profile" -> profile.label
        else -> goal.label
    }

    companion object {
        fun parse(o: JSONObject?): Setup {
            val steps = o.arr("steps")
            fun step(id: String): JSONObject? = steps.map { it }.firstOrNull { it.str("id") == id }
            return Setup(
                pending = o.bool("pending"),
                title = o.str("title").orEmpty(),
                count = o.str("count") ?: "%1\$d / %2\$d",
                back = o.str("back").orEmpty(),
                next = o.str("next").orEmpty(),
                logout = o.str("logout").orEmpty(),
                resume = o.str("resume") ?: "focus",
                focus = SetupFocusStep.parse(step("focus")),
                connect = SetupConnectStep.parse(step("connect")),
                profile = SetupProfileStep.parse(step("profile")),
                goal = SetupGoalStep.parse(step("goal"))
            )
        }
    }
}

data class SetupFocusStep(val label: String, val title: String, val lede: String, val options: List<SetupFocusOption>, val error: String) {
    companion object {
        fun parse(o: JSONObject?) = SetupFocusStep(
            o.str("label").orEmpty(), o.str("title").orEmpty(), o.str("lede").orEmpty(),
            o.arr("options").map(SetupFocusOption::parse), o.str("error").orEmpty()
        )
    }
}

data class SetupFocusOption(val key: String, val label: String, val line: String, val icon: String, val accent: String, val chosen: Boolean) {
    companion object {
        fun parse(o: JSONObject): SetupFocusOption? {
            val key = o.str("key") ?: return null
            return SetupFocusOption(key, o.str("label").orEmpty(), o.str("line").orEmpty(), o.str("icon").orEmpty(), o.str("accent").orEmpty(), o.bool("chosen"))
        }
    }
}

/** Health Connect as the setup offers it: [app] is the phone's own sentence, [web] the website's. */
data class SetupSource(
    val label: String,
    val note: String,
    val icon: String,
    val app: String,
    val connected: Boolean,
    val status: String,
    /**
     * The status in this phone's own terms. The server counts Health Connect
     * as connected the moment a phone signs in — on this phone always true —
     * so the app says whether its own Health Connect lets Ownify read.
     */
    val connectedLabel: String,
    val notConnectedLabel: String,
    val lastSync: String?
) {
    companion object {
        fun parse(o: JSONObject?) = SetupSource(
            o.str("label").orEmpty(), o.str("note").orEmpty(), o.str("icon") ?: "pulse", o.str("app").orEmpty(),
            o.bool("connected"), o.str("status").orEmpty(),
            o.str("connected_label") ?: o.str("status").orEmpty(),
            o.str("not_connected_label") ?: o.str("status").orEmpty(),
            o.str("last_sync")
        )
    }
}

data class SetupConnectStep(
    val label: String,
    val title: String,
    val lede: String,
    val source: SetupSource,
    val connected: Boolean,
    val manual: String,
    val later: String,
    val skip: String
) {
    companion object {
        fun parse(o: JSONObject?) = SetupConnectStep(
            o.str("label").orEmpty(), o.str("title").orEmpty(), o.str("lede").orEmpty(), SetupSource.parse(o.obj("source")),
            o.bool("connected"), o.str("manual").orEmpty(), o.str("later").orEmpty(), o.str("skip").orEmpty()
        )
    }
}

/** A profile fact the setup asks for, with [reason] — why Ownify uses it — and Instellingen's own input. */
data class SetupField(val key: String, val label: String, val reason: String, val note: String?, val locked: Boolean, val value: String?, val input: FieldInput?) {
    companion object {
        fun parse(o: JSONObject): SetupField? {
            val key = o.str("key") ?: return null
            return SetupField(
                key, o.str("label").orEmpty(), o.str("reason").orEmpty(), o.str("note"), o.bool("locked"), o.str("value"),
                o.obj("input")?.let(FieldInput::parse)
            )
        }
    }
}

data class SetupProfileStep(val label: String, val title: String, val lede: String, val fields: List<SetupField>, val skip: String, val save: String, val error: String) {
    companion object {
        fun parse(o: JSONObject?) = SetupProfileStep(
            o.str("label").orEmpty(), o.str("title").orEmpty(), o.str("lede").orEmpty(), o.arr("fields").map(SetupField::parse),
            o.str("skip").orEmpty(), o.str("save").orEmpty(), o.str("error").orEmpty()
        )
    }
}

/**
 * A first goal worked out from the person's own data: [input] is the goal
 * exactly as api/goals/create.php takes it, checked by the server with the
 * wizard's own rules.
 */
data class SetupSuggestion(val eyebrow: String, val basis: String, val name: String, val summary: String, val input: Map<String, String>, val add: String, val decline: String) {
    companion object {
        fun parse(o: JSONObject?): SetupSuggestion? {
            if (o == null) return null
            val input = o.obj("input").stringMap()
            if (input.isEmpty()) return null
            return SetupSuggestion(
                o.str("eyebrow").orEmpty(), o.str("basis").orEmpty(), o.str("name").orEmpty(), o.str("summary").orEmpty(),
                input, o.str("add").orEmpty(), o.str("decline").orEmpty()
            )
        }
    }
}

data class SetupGoalStep(
    val label: String,
    val title: String,
    val lede: String,
    val own: String,
    /** "Doel toegevoegd: %s. …" */
    val added: String,
    val finish: String,
    val error: String,
    val canAdd: Boolean,
    /** The account's active goals by name: one made during the setup shows as added. */
    val goals: List<String>,
    val suggestion: SetupSuggestion?
) {
    fun addedText(name: String): String = added.replace("%s", name)

    companion object {
        fun parse(o: JSONObject?) = SetupGoalStep(
            o.str("label").orEmpty(), o.str("title").orEmpty(), o.str("lede").orEmpty(), o.str("own").orEmpty(),
            o.str("added") ?: "%s", o.str("finish").orEmpty(), o.str("error").orEmpty(), o.bool("can_add"),
            o.arr("goals").strings(), SetupSuggestion.parse(o.obj("suggestion"))
        )
    }
}

/**
 * Overzicht's card of the first days (`calibration`, includes/setup.php):
 * [phase] `building` (how far each category is, and one fact), `first_score`
 * (the first category that scored, as the Scorekompas shows it) or
 * `baseline` (the starting point; empty without any data). Every word and
 * number is the server's — the engine's own scores, never the phone's.
 */
data class Calibration(
    val phase: String,
    val day: Int,
    val eyebrow: String,
    val title: String,
    val lede: String?,
    val progress: List<CalibrationProgress>,
    val observation: String?,
    val first: CompassCategory?,
    val baseline: List<CalibrationStart>,
    val note: String?,
    val open: String?
) {
    companion object {
        fun parse(o: JSONObject?): Calibration? {
            val phase = o.str("phase") ?: return null
            return Calibration(
                phase = phase,
                day = o.int("day") ?: 1,
                eyebrow = o.str("eyebrow").orEmpty(),
                title = o.str("title").orEmpty(),
                lede = o.str("lede")?.takeIf { it.isNotEmpty() },
                progress = o.arr("progress").map(CalibrationProgress::parse),
                observation = o.str("observation"),
                first = o.obj("first")?.let(CompassCategory::parse),
                baseline = o.arr("baseline").map(CalibrationStart::parse),
                note = o.str("note"),
                open = o.str("open")
            )
        }
    }
}

/** One category on its way to a first score: [days] of [needed], in [count]'s words. */
data class CalibrationProgress(
    val id: String,
    val label: String,
    val accent: String,
    val icon: String,
    val days: Int,
    val needed: Int,
    val count: String,
    val detail: String?,
    val how: String?
) {
    companion object {
        fun parse(o: JSONObject): CalibrationProgress? {
            val id = o.str("id") ?: return null
            return CalibrationProgress(
                id, o.str("label").orEmpty(), o.str("accent").orEmpty(), o.str("icon").orEmpty(),
                o.int("days") ?: 0, o.int("needed") ?: 3, o.str("count").orEmpty(), o.str("detail"), o.str("how")
            )
        }
    }
}

/** A category in the starting point: its score, its band, and one fact. */
data class CalibrationStart(val id: String, val label: String, val accent: String, val icon: String, val value: Int, val band: String?, val fact: String?) {
    companion object {
        fun parse(o: JSONObject): CalibrationStart? {
            val id = o.str("id") ?: return null
            val value = o.int("value") ?: return null
            return CalibrationStart(id, o.str("label").orEmpty(), o.str("accent").orEmpty(), o.str("icon").orEmpty(), value, o.str("band"), o.str("fact"))
        }
    }
}

