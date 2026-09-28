package com.healthapp.android.data

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
    val auth: Auth
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
                auth = Auth.parse(data.obj("auth"))
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

data class AiCopy(
    val title: String,
    val status: String,
    val openAria: String,
    val closeLabel: String,
    val closeAria: String,
    val composerNote: String,
    val composerAria: String
) {
    companion object {
        fun parse(o: JSONObject?) = AiCopy(
            o.str("title").orEmpty(), o.str("status").orEmpty(),
            o.obj("open").str("aria").orEmpty(),
            o.obj("close").str("label").orEmpty(), o.obj("close").str("aria").orEmpty(),
            o.obj("composer").str("note").orEmpty(), o.obj("composer").str("aria").orEmpty()
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
    val patterns: Patterns,
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
                patterns = Patterns.parse(data.obj("patterns")),
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

data class Contributor(val area: String, val label: String, val accent: String, val value: Int?) {
    companion object {
        fun parse(o: JSONObject) = Contributor(
            o.str("area").orEmpty(), o.str("label").orEmpty(), o.str("accent").orEmpty(), o.int("value")
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

data class Patterns(
    val title: String,
    val headline: String,
    val description: String,
    val topics: List<String>,
    val range: String
) {
    companion object {
        fun parse(o: JSONObject?) = Patterns(
            o.str("title").orEmpty(), o.str("headline").orEmpty(), o.str("description").orEmpty(),
            o.arr("topics").strings(), o.str("range").orEmpty()
        )
    }
}

data class Recommendation(val title: String, val headline: String, val description: String, val note: String) {
    companion object {
        fun parse(o: JSONObject?) = Recommendation(
            o.str("title").orEmpty(), o.str("headline").orEmpty(), o.str("description").orEmpty(), o.str("note").orEmpty()
        )
    }
}

// ------------------------------------------------------------ gezondheid

data class Health(val title: String, val lede: String, val areas: List<Area>, val trend: Trend) {
    fun area(id: String): Area? = areas.firstOrNull { it.id == id }

    companion object {
        fun parse(o: JSONObject?) = Health(
            o.str("title").orEmpty(),
            o.str("lede").orEmpty(),
            o.obj("areas").entries { id, a -> Area.parse(id, a) },
            Trend.parse(o.obj("trend"))
        )
    }
}

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
    val timeline: Timeline?
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
            return Goals(
                title = o.str("title").orEmpty(),
                lede = o.str("lede").orEmpty(),
                limits = o.obj("limits").int("active") ?: 3,
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
                used = o.int("used") ?: 0,
                slotsLeft = o.int("slots_left") ?: 0,
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
                ChartPoint((it.num("x") ?: 0.0).toFloat(), (it.num("y") ?: 0.0).toFloat(), it.str("d").orEmpty(), it.str("v").orEmpty(), it.bool("dot"))
            },
            line = o.arr("line").strings(),
            area = o.arr("area").strings(),
            xTicks = o.arr("x_ticks").map { XTick((it.num("left") ?: 0.0).toFloat(), it.str("label").orEmpty(), it.str("align") ?: "center") },
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

data class ChartPoint(val x: Float, val y: Float, val date: String, val value: String, val dot: Boolean)
data class XTick(val left: Float, val label: String, val align: String)
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
    data class Choice(val title: String, val selected: String?, val options: List<ChoiceOption>) : SettingsBlock
    data class States(val title: String, val lede: String?, val items: List<StateItem>) : SettingsBlock
    data class Toggles(val title: String, val lede: String?, val items: List<ToggleItem>) : SettingsBlock
    data class Rows(val title: String, val items: List<Pair<String, String>>) : SettingsBlock
    data class Note(val icon: String, val text: String) : SettingsBlock

    companion object {
        fun parse(o: JSONObject): SettingsBlock? =
            when (o.str("type")) {
                "identity" -> Identity
                "fields" -> Fields(o.str("title").orEmpty(), o.str("lede"), o.arr("fields").map(ProfileField::parse))
                "signin" -> Signin(o.str("title").orEmpty())
                "integrations" -> Integrations(o.str("title").orEmpty())
                "choice" -> Choice(
                    o.str("title").orEmpty(), o.str("selected"),
                    o.arr("options").map { ChoiceOption(it.str("key").orEmpty(), it.str("label").orEmpty(), it.str("note"), it.bool("disabled")) }
                )
                "states" -> States(
                    o.str("title").orEmpty(), o.str("lede"),
                    o.arr("items").map { StateItem(it.str("label").orEmpty(), it.text("value"), it.str("note")) }
                )
                "toggles" -> Toggles(
                    o.str("title").orEmpty(), o.str("lede"),
                    o.arr("items").map { ToggleItem(it.str("label").orEmpty(), it.str("note"), it.bool("on")) }
                )
                "rows" -> Rows(o.str("title").orEmpty(), o.arr("items").map { it.str("label").orEmpty() to it.text("value").orEmpty() })
                "note" -> Note(o.str("icon") ?: "info", o.str("text").orEmpty())
                else -> null
            }
    }
}

data class ChoiceOption(val key: String, val label: String, val note: String?, val disabled: Boolean)
data class StateItem(val label: String, val value: String?, val note: String?)
data class ToggleItem(val label: String, val note: String?, val on: Boolean)

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
    val devices: List<PairedDevice>
) {
    companion object {
        fun parse(o: JSONObject) = Integration(
            o.str("key").orEmpty(), o.str("provider"), o.str("label").orEmpty(), o.str("icon").orEmpty(), o.str("note").orEmpty(),
            o.arr("categories").strings(), o.str("status") ?: "disconnected", o.bool("connected"), o.str("account"),
            o.str("last_sync"), o.str("error"), o.str("transport"), o.bool("available"), o.str("blocked"),
            o.arr("devices").map { PairedDevice(it.int("id") ?: 0, it.str("label").orEmpty(), it.str("last_sync"), it.str("paired")) }
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
