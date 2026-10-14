package com.ownify.android.i18n

import android.content.Context
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.core.content.edit
import org.json.JSONObject
import java.math.BigDecimal
import java.math.RoundingMode
import java.text.NumberFormat
import java.time.LocalDate
import java.util.Locale
import kotlin.math.abs
import kotlin.math.roundToInt

/**
 * Language and units in the app — the website's rules (includes/i18n.php,
 * docs/I18N.md), for the few sentences, numbers and dates the app writes
 * itself. Everything the server sends (api/app/state.php) is already in the
 * person's language and units.
 *
 * Which language:
 *
 *   signed in    the account's: the language chosen in Instellingen → Taal,
 *                on the website or here, or — when none was — the phone's
 *                (the server reads it from Accept-Language and answers with
 *                `i18n.locale` in the state)
 *   signed out   the choice last made on this phone, else the phone's
 *
 * The phone's language is English → English, Dutch → Dutch, anything else
 * → Dutch. Only a choice made in Instellingen is ever kept
 * (shared_prefs/ownify_language.xml: "nl" or "en"; nothing is "follow the
 * phone"), never what the phone happened to be.
 */
object OwnifyLanguage {

    private const val PREFS_NAME = "ownify_language"
    private const val KEY_CHOICE = "language"
    private const val KEY_UNITS = "units"

    /** "nl" or "en": what the app is written in now. State, so every screen follows a change. */
    var locale: String by mutableStateOf(device())
        private set

    /** "nl" or "en" when chosen; null follows the phone. */
    var chosen: String? by mutableStateOf(null)
        private set

    /** "metric" or "imperial". */
    var units: String by mutableStateOf("metric")
        private set

    private var catalog: Map<String, String> = emptyMap()

    /** Before the first frame (MainActivity): the catalogue, the choice kept on this phone. */
    fun restore(context: Context) {
        if (catalog.isEmpty()) {
            catalog = runCatching {
                context.applicationContext.assets.open("lang-en.json").bufferedReader().use { read(it.readText()) }
            }.getOrDefault(emptyMap())
        }
        val prefs = prefs(context)
        chosen = valid(prefs.getString(KEY_CHOICE, null))
        units = if (prefs.getString(KEY_UNITS, null) == "imperial") "imperial" else "metric"
        locale = chosen ?: device()
    }

    /**
     * What the server says for the signed-in account (the state's `i18n`):
     * shown at once, and kept, so the screens after signing out — and the
     * next start, before the first read — stay in it.
     */
    fun adopt(context: Context, locale: String?, chosen: String?, units: String?) {
        this.chosen = valid(chosen)
        this.locale = valid(locale) ?: this.chosen ?: device()
        this.units = if (units == "imperial") "imperial" else "metric"
        prefs(context).edit {
            if (this@OwnifyLanguage.chosen == null) remove(KEY_CHOICE) else putString(KEY_CHOICE, this@OwnifyLanguage.chosen)
            putString(KEY_UNITS, this@OwnifyLanguage.units)
        }
    }

    /** For a test: the catalogue, the language and the units outright. */
    fun use(locale: String, units: String = "metric", catalog: Map<String, String>? = null) {
        if (catalog != null) this.catalog = catalog
        this.locale = valid(locale) ?: "nl"
        this.units = if (units == "imperial") "imperial" else "metric"
    }

    /**
     * The Accept-Language header of every request: the choice made on this
     * phone, else the phone's own language. The server decides with it when
     * the account chose nothing.
     */
    fun header(): String = chosen ?: Locale.getDefault().toLanguageTag().ifEmpty { "nl" }

    /** The phone's language as Ownify has it: English or Dutch, Dutch for anything else. */
    fun device(): String = of(Locale.getDefault().language)

    /** "en-GB" → "en", "nl" → "nl", anything else → "nl". */
    fun of(language: String?): String = when (language?.lowercase()?.take(2)) {
        "en" -> "en"
        else -> "nl"
    }

    val english: Boolean get() = locale == "en"
    val imperial: Boolean get() = units == "imperial"

    internal fun lookup(dutch: String): String =
        if (locale == "en") catalog[dutch] ?: dutch else dutch

    internal fun read(json: String): Map<String, String> {
        val o = JSONObject(json)
        return o.keys().asSequence().associateWith { o.optString(it) }
    }

    private fun valid(locale: String?): String? = locale?.takeIf { it == "nl" || it == "en" }

    private fun prefs(context: Context) =
        context.applicationContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
}

/** A built-in Dutch string in the app's language: tr("Opslaan") is "Save" in English. */
fun tr(dutch: String): String = OwnifyLanguage.lookup(dutch)

/** String.format over [tr]: trf("Nog %d dagen", 3). */
fun trf(dutch: String, vararg args: Any?): String = String.format(Locale.ROOT, tr(dutch), *args)

/** One or many: trn(3, "%d nacht", "%d nachten") → "3 nights". */
fun trn(n: Int, one: String, many: String): String =
    String.format(Locale.ROOT, tr(if (abs(n) == 1) one else many), n)

/** Numbers, dates and units as the website writes them (includes/i18n.php). */
object OwnifyFormat {

    private val MONTHS = mapOf(
        "nl" to listOf("januari" to "jan", "februari" to "feb", "maart" to "mrt", "april" to "apr", "mei" to "mei", "juni" to "jun",
            "juli" to "jul", "augustus" to "aug", "september" to "sep", "oktober" to "okt", "november" to "nov", "december" to "dec"),
        "en" to listOf("January" to "Jan", "February" to "Feb", "March" to "Mar", "April" to "Apr", "May" to "May", "June" to "Jun",
            "July" to "Jul", "August" to "Aug", "September" to "Sep", "October" to "Oct", "November" to "Nov", "December" to "Dec")
    )

    /** Monday = 1 … Sunday = 7, as java.time has it. */
    private val WEEKDAYS = mapOf(
        "nl" to listOf("maandag" to "ma", "dinsdag" to "di", "woensdag" to "wo", "donderdag" to "do", "vrijdag" to "vr", "zaterdag" to "za", "zondag" to "zo"),
        "en" to listOf("Monday" to "Mon", "Tuesday" to "Tue", "Wednesday" to "Wed", "Thursday" to "Thu", "Friday" to "Fri", "Saturday" to "Sat", "Sunday" to "Sun")
    )

    /** The Locale numbers are written in: "72,5" or "72.5". */
    fun locale(): Locale = if (OwnifyLanguage.english) Locale.US else Locale.forLanguageTag("nl-NL")

    fun number(value: Double, decimals: Int = 0, grouping: Boolean = true): String =
        NumberFormat.getNumberInstance(locale()).apply {
            minimumFractionDigits = decimals
            maximumFractionDigits = decimals
            isGroupingUsed = grouping
            roundingMode = RoundingMode.HALF_UP
        }.format(exact(value))

    /** As [number], without trailing zeros: 5,0 → "5". */
    fun numberTrim(value: Double, decimals: Int = 1, grouping: Boolean = true): String =
        NumberFormat.getNumberInstance(locale()).apply {
            minimumFractionDigits = 0
            maximumFractionDigits = decimals
            isGroupingUsed = grouping
            roundingMode = RoundingMode.HALF_UP
        }.format(exact(value))

    /**
     * Rounded as the website rounds (PHP's number_format, JavaScript's
     * toLocaleString): the shortest decimal that reads back as the number,
     * half away from zero — "2.355" → 2,36, not 2,35 from its binary value.
     */
    private fun exact(value: Double): Any = if (value.isFinite()) BigDecimal(value.toString()) else value

    /** Typed either way: "72,5", "72.5", "1.234,5" and "1,234.5" are read as meant. */
    fun parseNumber(input: String): Double? {
        var s = input.replace(" ", "").replace(" ", "").trim()
        if (s.isEmpty() || !Regex("^-?[0-9.,]+$").matches(s)) return null
        val comma = s.lastIndexOf(',')
        val dot = s.lastIndexOf('.')
        if (comma >= 0 && dot >= 0) {
            val decimal = if (comma > dot) ',' else '.'
            s = s.replace(if (decimal == ',') "." else ",", "").replace(',', '.')
        } else if (comma >= 0) {
            s = s.replace(',', '.')
        }
        return s.toDoubleOrNull()
    }

    fun month(month: Int, short: Boolean = false): String =
        MONTHS.getValue(OwnifyLanguage.locale)[month.coerceIn(1, 12) - 1].let { if (short) it.second else it.first }

    fun weekday(date: LocalDate, short: Boolean = false): String =
        WEEKDAYS.getValue(OwnifyLanguage.locale)[date.dayOfWeek.value - 1].let { if (short) it.second else it.first }

    /**
     * i18n_date()'s formats: day_month, day_mon, weekday_day_month,
     * day_month_year, day_mon_year, month_year, month, mon, and numeric
     * ("3-9-2026" in Dutch, "9/3/2026" in English).
     */
    fun date(date: LocalDate, format: String = "day_month"): String {
        val en = OwnifyLanguage.english
        val d = date.dayOfMonth
        val m = date.monthValue
        val y = date.year
        return when (format) {
            "day_mon" -> if (en) "${month(m, true)} $d" else "$d ${month(m, true)}"
            "weekday_day_month" -> if (en) "${weekday(date)}, ${month(m)} $d" else "${weekday(date)} $d ${month(m)}"
            "day_month_year" -> if (en) "${month(m)} $d, $y" else "$d ${month(m)} $y"
            "day_mon_year" -> if (en) "${month(m, true)} $d, $y" else "$d ${month(m, true)} $y"
            "month_year" -> "${month(m)} $y"
            "month" -> month(m)
            "mon" -> month(m, true)
            "numeric" -> if (en) "$m/$d/$y" else "$d-$m-$y"
            else -> if (en) "${month(m)} $d" else "$d ${month(m)}"
        }
    }
}

/**
 * The units (Instellingen → Eenheden): how a stored value is shown and how a
 * typed one is read, as units_spec() on the server. Stored values are always
 * kg, cm, km or m, °C and seconds per km; nothing here changes one.
 */
object OwnifyUnits {

    data class Spec(val unit: String, val factor: Double, val offset: Double, val decimals: Int)

    fun spec(stored: String): Spec {
        val metric = Spec(stored, 1.0, 0.0, 1)
        if (!OwnifyLanguage.imperial) return metric
        return when (stored) {
            "kg" -> Spec("lb", 2.20462262185, 0.0, 1)
            "km" -> Spec("mi", 1 / 1.609344, 0.0, 1)
            "m" -> Spec("ft", 1 / 0.3048, 0.0, 0)
            "cm" -> Spec("in", 1 / 2.54, 0.0, 1)
            "°C", "C" -> Spec("°F", 9.0 / 5.0, 32.0, 1)
            "km/u", "km/h" -> Spec("mph", 1 / 1.609344, 0.0, 1)
            "min/km" -> Spec("min/mi", 1.609344, 0.0, 0)
            else -> metric
        }
    }

    fun label(stored: String): String = spec(stored).unit

    /** A stored value as the person sees it. Never rounded here. */
    fun out(value: Double, stored: String): Double = spec(stored).let { value * it.factor + it.offset }

    /** A value typed in the person's units, as it is stored. */
    fun into(value: Double, stored: String): Double = spec(stored).let { (value - it.offset) / it.factor }

    /** 180 → "5 ft 11 in". */
    fun feetInches(cm: Double): String {
        val inches = (cm / 2.54).roundToInt()
        return "${inches / 12} ft ${inches % 12} in"
    }

    /** "5'11", "5 ft 11 in", "5 11" or plain inches, in cm; null when it is none of those. */
    fun parseFeetInches(input: String): Double? {
        val s = input.trim().lowercase()
            .replace('′', '\'').replace('’', '\'')
            .replace("″", "").replace("”", "").replace("\"", "")
        // A lone number is inches ("71"), but a whole 2 to 8 is feet: nobody is six inches tall ("6" is 6 ft).
        Regex("^(\\d+(?:[.,]\\d+)?)\\s*(in|inch|inches)?$").matchEntire(s)?.let {
            val number = it.groupValues[1]
            val inches = number.replace(',', '.').toDouble()
            if (it.groupValues[2].isEmpty() && number.all(Char::isDigit) && inches in 2.0..8.0) return inches * 12 * 2.54
            return if (inches > 0 && inches < 120) inches * 2.54 else null
        }
        val m = Regex("^(\\d+)\\s*(?:'|ft|feet|foot)?\\s*(\\d+(?:[.,]\\d+)?)?\\s*(?:in|inch|inches)?$").matchEntire(s) ?: return null
        val feet = m.groupValues[1].toInt()
        val rest = m.groupValues[2].takeIf { it.isNotEmpty() }?.replace(',', '.')?.toDouble() ?: 0.0
        if (feet < 2 || feet > 8 || rest >= 12) return null
        return (feet * 12 + rest) * 2.54
    }

    /** "72,5 kg", "159.8 lb", "5 ft 11 in". */
    fun format(value: Double, stored: String, decimals: Int? = null, withUnit: Boolean = true): String {
        if (stored == "cm" && OwnifyLanguage.imperial && value >= 60) return feetInches(value)
        val s = spec(stored)
        val shown = OwnifyFormat.numberTrim(value * s.factor + s.offset, decimals ?: s.decimals)
        return if (withUnit) "$shown ${s.unit}" else shown
    }
}
