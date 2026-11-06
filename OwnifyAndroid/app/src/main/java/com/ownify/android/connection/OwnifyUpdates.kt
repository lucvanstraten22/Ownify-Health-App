package com.ownify.android.connection

import android.content.Context
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.core.content.edit
import java.net.HttpURLConnection
import java.net.URL
import java.time.Duration
import java.time.Instant
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONObject

/**
 * 43.0 — is there a newer Ownify? (docs/HEALTH-CONNECT.md, *Updates*)
 *
 * Ownify for Android is a signed APK on GitHub Releases, not in a store, so
 * nothing updates it by itself. This asks GitHub's public Releases API —
 * free, no key, no account — for the latest release, at most once a day
 * and only while the app is open, and compares its version with this one.
 * A newer one is shown ([available]); the person taps through to the APK
 * in the browser and installs it there. Nothing is downloaded or installed
 * here, and nothing about the person is sent: the request carries only the
 * app's name and version, as GitHub asks every client to.
 *
 * Its answer is kept (shared_prefs/ownify_updates.xml): the time of the last
 * check, and the newer version found, if any — nothing else.
 */
object OwnifyUpdates {

    /** The public repository the release workflow (.github/workflows/android-apk.yml) publishes to. */
    const val RELEASES = "https://api.github.com/repos/lucvanstraten22/Ownify-Health-App/releases/latest"

    /** At most one question a day: GitHub allows 60 an hour per address without a key, and a day is soon enough. */
    val EVERY: Duration = Duration.ofHours(24)

    private const val PREFS = "ownify_updates"
    private const val KEY_CHECKED = "checked_at"
    private const val KEY_VERSION = "version"
    private const val KEY_APK = "apk"
    private const val KEY_PAGE = "page"
    private const val KEY_TOLD = "told"
    private const val MAX_BYTES = 256 * 1024

    /** A newer release: its version, its APK, and its page on GitHub. */
    data class Update(val version: String, val apk: String, val page: String)

    /** The newer release, once one is known; null while there is none. */
    var available by mutableStateOf<Update?>(null)
        private set

    /** For tests: the request itself. Answers the body, or null without one. */
    @Volatile
    internal var fetch: suspend (url: String, userAgent: String) -> String? = ::get

    /** For tests: the clock. */
    @Volatile
    internal var now: () -> Instant = { Instant.now() }

    /** Not under the JVM's test runner, unless a test asks: tests never reach GitHub. */
    @Volatile
    internal var allowed: () -> Boolean = { android.os.Build.FINGERPRINT != "robolectric" }

    /** This app's version, e.g. "42.0". */
    fun installed(context: Context): String =
        runCatching { context.packageManager.getPackageInfo(context.packageName, 0).versionName }.getOrNull().orEmpty()

    /**
     * Asks GitHub, unless it was asked less than [EVERY] ago — then what it
     * said last time stands. Returns the update to tell about once (a
     * toast), the first time a version is found; null otherwise.
     */
    suspend fun check(context: Context, force: Boolean = false): Update? {
        val prefs = context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val mine = installed(context)

        // What was found before, if it is still newer than this app (the person may have updated since).
        val kept = prefs.getString(KEY_VERSION, null)?.let { version ->
            Update(version, prefs.getString(KEY_APK, null).orEmpty(), prefs.getString(KEY_PAGE, null).orEmpty())
        }?.takeIf { newer(it.version, mine) && it.apk.isNotBlank() }
        available = kept

        val checked = prefs.getLong(KEY_CHECKED, 0L)
        if (!allowed()) return null
        if (!force && checked > 0 && Duration.between(Instant.ofEpochMilli(checked), now()) < EVERY) {
            return null
        }

        val body = runCatching { withContext(Dispatchers.IO) { fetch(RELEASES, "Ownify-Android/$mine") } }.getOrNull()
            ?: return null          // offline, or GitHub busy: try again next time the app opens
        prefs.edit { putLong(KEY_CHECKED, now().toEpochMilli()) }

        val latest = parse(body)?.takeIf { newer(it.version, mine) }
        prefs.edit {
            if (latest == null) {
                remove(KEY_VERSION); remove(KEY_APK); remove(KEY_PAGE)
            } else {
                putString(KEY_VERSION, latest.version); putString(KEY_APK, latest.apk); putString(KEY_PAGE, latest.page)
            }
        }
        available = latest

        if (latest == null || prefs.getString(KEY_TOLD, null) == latest.version) return null
        prefs.edit { putString(KEY_TOLD, latest.version) }
        return latest
    }

    /**
     * The release GitHub describes: its version from the tag ("v43.0" →
     * "43.0"), the first `.apk` among its files, its page. Null for a draft,
     * a pre-release or one without an APK.
     */
    internal fun parse(body: String): Update? {
        val json = runCatching { JSONObject(body) }.getOrNull() ?: return null
        if (json.optBoolean("draft") || json.optBoolean("prerelease")) return null
        val version = json.optString("tag_name").trim().removePrefix("v").removePrefix("V")
        if (version.isEmpty() || !version[0].isDigit()) return null
        val assets = json.optJSONArray("assets") ?: return null
        val apk = (0 until assets.length())
            .map { assets.getJSONObject(it) }
            .firstOrNull { it.optString("name").endsWith(".apk", ignoreCase = true) }
            ?.optString("browser_download_url")
            ?.takeIf { it.startsWith("https://github.com/") }
            ?: return null
        return Update(version, apk, json.optString("html_url").takeIf { it.startsWith("https://github.com/") } ?: apk)
    }

    /** Whether [remote] is a later version than [local], part by part: 43.0 > 42.9 > 42.0 > 27.0.15. */
    internal fun newer(remote: String, local: String): Boolean {
        fun parts(v: String) = v.split('.', '-', ' ').map { it.takeWhile(Char::isDigit) }.takeWhile { it.isNotEmpty() }.map { it.toInt() }
        val a = parts(remote)
        val b = parts(local)
        if (a.isEmpty()) return false
        if (b.isEmpty()) return true
        for (i in 0 until maxOf(a.size, b.size)) {
            val x = a.getOrElse(i) { 0 }
            val y = b.getOrElse(i) { 0 }
            if (x != y) return x > y
        }
        return false
    }

    private fun get(url: String, userAgent: String): String? {
        val connection = URL(url).openConnection() as HttpURLConnection
        return try {
            connection.connectTimeout = 10_000
            connection.readTimeout = 10_000
            connection.setRequestProperty("Accept", "application/vnd.github+json")
            connection.setRequestProperty("User-Agent", userAgent)
            if (connection.responseCode != 200) return null
            connection.inputStream.use { input ->
                val bytes = input.readNBytesCompat(MAX_BYTES)
                String(bytes, Charsets.UTF_8)
            }
        } finally {
            connection.disconnect()
        }
    }

    private fun java.io.InputStream.readNBytesCompat(max: Int): ByteArray {
        val out = java.io.ByteArrayOutputStream()
        val buffer = ByteArray(8192)
        while (out.size() < max) {
            val n = read(buffer, 0, minOf(buffer.size, max - out.size()))
            if (n < 0) break
            out.write(buffer, 0, n)
        }
        return out.toByteArray()
    }
}
