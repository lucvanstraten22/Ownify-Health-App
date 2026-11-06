package com.ownify.android.connection

import android.content.Context
import android.os.Build
import androidx.health.connect.client.HealthConnectClient
import androidx.test.core.app.ApplicationProvider
import com.ownify.android.ui.screens.settings.PhoneHealth
import com.ownify.android.ui.screens.settings.canInstall
import com.ownify.android.ui.screens.settings.healthConnectNote
import com.ownify.android.ui.screens.settings.manufacturerNote
import java.time.Duration
import java.time.Instant
import kotlinx.coroutines.runBlocking
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/**
 * 43.0 — a newer Ownify on GitHub (OwnifyUpdates), and what the phone's card
 * says when Health Connect is missing, out of date, or the battery holds the
 * sync back. GitHub itself is stood in for: tests never reach it.
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [36])
class OwnifyUpdatesTest {

    private lateinit var context: Context
    private var clock = Instant.parse("2026-11-07T10:00:00Z")
    private val asked = mutableListOf<String>()
    private var answer: String? = null

    private fun release(tag: String, apk: String? = "Ownify-${tag.removePrefix("v")}.apk", draft: Boolean = false, pre: Boolean = false) = """
        {"tag_name":"$tag","draft":$draft,"prerelease":$pre,
         "html_url":"https://github.com/lucvanstraten22/Ownify-Health-App/releases/tag/$tag",
         "assets":[${if (apk == null) "" else """{"name":"$apk","browser_download_url":"https://github.com/lucvanstraten22/Ownify-Health-App/releases/download/$tag/$apk"}"""}]}
    """.trimIndent()

    @Before
    fun setUp() {
        context = ApplicationProvider.getApplicationContext()
        context.getSharedPreferences("ownify_updates", Context.MODE_PRIVATE).edit().clear().commit()
        OwnifyUpdates.allowed = { true }
        OwnifyUpdates.now = { clock }
        OwnifyUpdates.fetch = { url, agent -> asked += "$url|$agent"; answer }
    }

    @After
    fun tearDown() {
        OwnifyUpdates.allowed = { Build.FINGERPRINT != "robolectric" }
        OwnifyUpdates.now = { Instant.now() }
    }

    private fun mine() = OwnifyUpdates.installed(context)

    @Test
    fun `versions compare part by part, and the old 27_0_N releases are older`() {
        assertTrue(OwnifyUpdates.newer("43.0", "42.0"))
        assertTrue(OwnifyUpdates.newer("42.10", "42.9"))
        assertTrue(OwnifyUpdates.newer("43.0.1", "43.0"))
        assertFalse(OwnifyUpdates.newer("43.0", "43.0"))
        assertFalse(OwnifyUpdates.newer("27.0.15", "43.0"))
        assertFalse(OwnifyUpdates.newer("11.1.5", "43.0"))
        assertFalse(OwnifyUpdates.newer("", "43.0"))
    }

    @Test
    fun `a release - its version and its APK on GitHub - drafts, pre-releases and ones without an APK are none`() {
        val r = OwnifyUpdates.parse(release("v44.0"))!!
        assertEquals("44.0", r.version)
        assertEquals("https://github.com/lucvanstraten22/Ownify-Health-App/releases/download/v44.0/Ownify-44.0.apk", r.apk)
        assertNull(OwnifyUpdates.parse(release("v44.0", draft = true)))
        assertNull(OwnifyUpdates.parse(release("v44.0", pre = true)))
        assertNull(OwnifyUpdates.parse(release("v44.0", apk = null)))
        assertNull(OwnifyUpdates.parse(release("v44.0", apk = "notes.txt")))
        assertNull(OwnifyUpdates.parse("not json"))
    }

    @Test
    fun `a newer one is told once, shown until installed, and GitHub asked at most once a day`() = runBlocking {
        answer = release("v99.0")
        val told = OwnifyUpdates.check(context)
        assertEquals("99.0", told?.version)
        assertEquals("99.0", OwnifyUpdates.available?.version)
        assertTrue("the app's name and version only", asked.single().endsWith("|Ownify-Android/${mine()}"))
        assertTrue(asked.single().startsWith("https://api.github.com/repos/lucvanstraten22/Ownify-Health-App/releases/latest"))

        clock = clock.plus(Duration.ofHours(5))
        assertNull("not told twice", OwnifyUpdates.check(context))
        assertEquals("not asked again within a day", 1, asked.size)
        assertEquals("still shown", "99.0", OwnifyUpdates.available?.version)

        clock = clock.plus(Duration.ofHours(20))
        assertNull("asked again, the same version: not told again", OwnifyUpdates.check(context))
        assertEquals(2, asked.size)
    }

    @Test
    fun `nothing newer, or offline - nothing shown, and offline asks again next time`() = runBlocking {
        answer = release("v1.0")
        assertNull(OwnifyUpdates.check(context))
        assertNull(OwnifyUpdates.available)

        context.getSharedPreferences("ownify_updates", Context.MODE_PRIVATE).edit().clear().commit()
        answer = null
        assertNull(OwnifyUpdates.check(context))
        answer = release("v99.0")
        assertNotNull("offline did not count as asked", OwnifyUpdates.check(context))
    }

    // ------------------------------------------------- the phone's card

    private fun health(sdk: Int, android: Int, play: Boolean = true) = PhoneHealth().apply {
        this.sdk = sdk
        androidVersion = android
        playStore = play
        known = true
    }

    @Test
    fun `missing or old Health Connect - what to do, by Android version`() {
        val old = health(HealthConnectClient.SDK_UNAVAILABLE, Build.VERSION_CODES.TIRAMISU)
        assertTrue(canInstall(old))
        assertTrue(healthConnectNote(old)!!.contains("aparte, gratis app"))

        val builtIn = health(HealthConnectClient.SDK_UNAVAILABLE, Build.VERSION_CODES.UPSIDE_DOWN_CAKE)
        assertFalse("part of Android: nothing to install", canInstall(builtIn))
        assertTrue(healthConnectNote(builtIn)!!.contains("hoort bij Android"))

        val update14 = health(HealthConnectClient.SDK_UNAVAILABLE_PROVIDER_UPDATE_REQUIRED, Build.VERSION_CODES.UPSIDE_DOWN_CAKE)
        assertTrue(canInstall(update14))
        assertTrue(healthConnectNote(update14)!!.contains("systeemupdates"))

        val update13 = health(HealthConnectClient.SDK_UNAVAILABLE_PROVIDER_UPDATE_REQUIRED, Build.VERSION_CODES.TIRAMISU)
        assertTrue(healthConnectNote(update13)!!.contains("Werk de app Health Connect bij"))

        val noStore = health(HealthConnectClient.SDK_UNAVAILABLE, Build.VERSION_CODES.TIRAMISU, play = false)
        assertTrue(healthConnectNote(noStore)!!.contains("staat niet op deze telefoon"))

        assertNull(healthConnectNote(health(HealthConnectClient.SDK_AVAILABLE, Build.VERSION_CODES.UPSIDE_DOWN_CAKE)))
    }

    @Test
    fun `the phone maker's own switch for the background`() {
        assertTrue(manufacturerNote("Xiaomi")!!.contains("Autostart"))
        assertTrue(manufacturerNote("samsung")!!.contains("slaapstand"))
        assertTrue(manufacturerNote("HUAWEI")!!.contains("Handmatig beheren"))
        assertNull("a Pixel needs nothing more", manufacturerNote("Google"))
    }
}
