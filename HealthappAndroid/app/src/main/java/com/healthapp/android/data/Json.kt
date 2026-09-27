package com.healthapp.android.data

import java.math.BigDecimal
import org.json.JSONArray
import org.json.JSONObject

/*
 * Reading the server's JSON leniently: a field that is missing, null or of
 * another type reads as absent, never as a crash. The app shows what the
 * server sent, and nothing it did not.
 */

internal fun JSONObject?.obj(name: String): JSONObject? = this?.optJSONObject(name)

internal fun JSONObject?.arr(name: String): JSONArray? = this?.optJSONArray(name)

/** A string, or null for a missing value, JSON null or any other type. Empty strings stay empty. */
internal fun JSONObject?.str(name: String): String? =
    when (val v = this?.opt(name)) {
        is String -> v
        else -> null
    }

/** A string as PHP would print the value: numbers too ("4.7", "82"). */
internal fun JSONObject?.text(name: String): String? =
    when (val v = this?.opt(name)) {
        is String -> v
        is Number -> numberText(v)
        is Boolean -> if (v) "1" else ""
        else -> null
    }

internal fun JSONObject?.int(name: String): Int? =
    when (val v = this?.opt(name)) {
        is Number -> v.toDouble().takeIf { it.isFinite() }?.let { Math.round(it).toInt() }
        is String -> v.trim().toDoubleOrNull()?.let { Math.round(it).toInt() }
        else -> null
    }

internal fun JSONObject?.num(name: String): Double? =
    when (val v = this?.opt(name)) {
        is Number -> v.toDouble().takeIf { it.isFinite() }
        is String -> v.trim().toDoubleOrNull()
        else -> null
    }

internal fun JSONObject?.bool(name: String): Boolean =
    when (val v = this?.opt(name)) {
        is Boolean -> v
        is Number -> v.toDouble() != 0.0
        is String -> v == "1" || v == "true"
        else -> false
    }

/** Whether the field is there and not null. */
internal fun JSONObject?.has(name: String): Boolean = this != null && has(name) && !isNull(name)

internal fun <T> JSONArray?.map(read: (JSONObject) -> T?): List<T> =
    if (this == null) emptyList() else (0 until length()).mapNotNull { i -> optJSONObject(i)?.let(read) }

internal fun JSONArray?.strings(): List<String> =
    if (this == null) emptyList() else (0 until length()).mapNotNull { i ->
        when (val v = opt(i)) {
            is String -> v
            is Number -> numberText(v)
            else -> null
        }
    }

/** An object's entries in the server's order, each value an object. */
internal fun <T> JSONObject?.entries(read: (String, JSONObject) -> T?): List<T> {
    if (this == null) return emptyList()
    val out = ArrayList<T>()
    val keys = keys()
    while (keys.hasNext()) {
        val key = keys.next()
        optJSONObject(key)?.let { value -> read(key, value)?.let(out::add) }
    }
    return out
}

/** An object's string values in the server's order. */
internal fun JSONObject?.stringMap(): Map<String, String> {
    if (this == null) return emptyMap()
    val out = LinkedHashMap<String, String>()
    val keys = keys()
    while (keys.hasNext()) {
        val key = keys.next()
        text(key)?.let { out[key] = it }
    }
    return out
}

/**
 * A number as PHP's `(string)` writes it: whole numbers without a decimal
 * point (88.0 → "88"), others in their shortest form with a dot (4.7).
 */
internal fun numberText(value: Number): String {
    val d = value.toDouble()
    if (!d.isFinite()) return ""
    if (d == Math.floor(d) && Math.abs(d) < 1e15) return d.toLong().toString()
    return BigDecimal(value.toString()).stripTrailingZeros().toPlainString()
}
