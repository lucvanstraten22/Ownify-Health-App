package com.ownify.android.ui

import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.semantics.getOrNull
import androidx.compose.ui.test.SemanticsMatcher
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performSemanticsAction
import androidx.test.core.app.ApplicationProvider
import com.ownify.android.connection.OwnifyApi
import com.ownify.android.connection.OwnifyConnection
import com.ownify.android.data.AppData
import com.ownify.android.data.SettingsBlock
import com.ownify.android.ui.app.AppShell
import com.ownify.android.ui.app.Detail
import com.ownify.android.ui.app.ShellState
import com.ownify.android.ui.design.LocalScreen
import com.ownify.android.ui.design.LocalStillMotion
import com.ownify.android.ui.design.ScreenMetrics
import com.ownify.android.ui.screens.OwnifyScreens
import com.ownify.android.ui.screens.research.ResearchPdfs
import com.ownify.android.ui.theme.OwnifyTheme
import com.sun.net.httpserver.HttpServer
import java.io.File
import java.net.InetSocketAddress
import java.net.ServerSocket
import kotlinx.coroutines.runBlocking
import org.json.JSONObject
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/**
 * "Uit onderzoek blijkt" (docs/INSIGHTS.md, docs/RESEARCH.md) on the phone:
 * the demo pages with what a server stored for a synthetic account
 * (research-state.json, read from api/app/state.php) — the day's insights on
 * Overzicht with their evidence, the suggestion, the area's own insight on
 * its page, Voeding's references, the Scorekompas's point of attention, the
 * BMI under Lichaamsmaten — and the detail each opens, over the page that
 * showed it, back going there. And the PDF: fetched from where the server
 * says, kept in the cache, never a publisher's error page in its place.
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [36], qualifiers = "+w412dp-h915dp-port-420dpi")
class ResearchFlowTest {

    @get:Rule
    val compose = createComposeRule()

    private lateinit var shell: ShellState
    private var server: HttpServer? = null
    private val api = OwnifyConnection.api

    @After
    fun tearDown() {
        server?.stop(0)
        OwnifyConnection.api = api
    }

    private fun json(name: String) = JSONObject(javaClass.classLoader!!.getResource(name).readText())

    /** The demo state with the stored insights and their research merged in, and Instellingen' BMI block. */
    private fun data(): AppData {
        val state = json("state-demo.json").getJSONObject("data")
        val research = json("research-state.json")
        state.put("insights", research.getJSONObject("insights"))
        state.put("recommendation", research.getJSONObject("recommendation"))
        state.put("research", research.getJSONObject("research"))
        // The Scorekompas a server sends (compass-demo.json), with the point of attention stored with the insights.
        state.put("compass", json("compass-demo.json").put("opportunity", research.getJSONObject("compass_opportunity")))
        val account = state.getJSONObject("settings").getJSONObject("pages").getJSONObject("account")
        account.getJSONArray("blocks").put(JSONObject().put("type", "insight").put("slot", "body"))
        return AppData.parse(state)
    }

    private fun show(data: AppData = data()) {
        compose.setContent {
            OwnifyTheme {
                BoxWithConstraints(Modifier.fillMaxSize()) {
                    CompositionLocalProvider(LocalScreen provides ScreenMetrics(maxWidth, maxHeight), LocalStillMotion provides true) {
                        AppShell(data, OwnifyScreens.shell, onShell = { shell = it })
                    }
                }
            }
        }
        compose.waitForIdle()
    }

    private fun text(text: String) = compose.onNode(hasText(text), useUnmergedTree = true)

    private fun count(text: String) = compose.onAllNodes(hasText(text), useUnmergedTree = true).fetchSemanticsNodes().size

    /** Every "Bekijk onderzoek" chip in the tree, the page under a detail first. */
    private fun research() = compose.onAllNodes(hasText("Bekijk onderzoek") and hasClickAction())

    /** A label (Betrouwbaar, …) anyone could tap: none, since 29.1 — it is only said in the detail. */
    private fun tappableLabels(label: String) = compose.onAllNodes(hasText(label) and hasClickAction()).fetchSemanticsNodes().size

    /** Taps the [index]th "Bekijk onderzoek" chip. */
    private fun tapResearch(index: Int) {
        val node = research()[index]
        node.performScrollTo()
        node.performSemanticsAction(SemanticsActions.OnClick)
        compose.waitForIdle()
    }

    /**
     * Taps the [matcher]'s last node on screen: a detail is drawn after the
     * page under it, which stays composed (and in the tree) behind it.
     */
    private fun tap(matcher: SemanticsMatcher) {
        val nodes = compose.onAllNodes(matcher)
        val node = nodes[nodes.fetchSemanticsNodes().lastIndex]
        node.performScrollTo()
        node.performSemanticsAction(SemanticsActions.OnClick)
        compose.waitForIdle()
    }

    private fun heading(text: String) =
        compose.onNode(hasText(text) and SemanticsMatcher.keyIsDefined(SemanticsProperties.Heading), useUnmergedTree = true)

    @Test
    fun `the state as the server sends it - insights with their evidence, the references, every detail`() {
        val data = data()
        val insights = data.overview.insights
        assertEquals("filled", insights.state)
        assertEquals(listOf("sleep", "nutrition", "training"), insights.items.map { it.slot })
        assertEquals(listOf("c2-0", "c16-1", "c10-1"), insights.items.map { it.evidence?.research })
        assertTrue(insights.items.all { it.evidence?.label == "Bekijk onderzoek" })
        assertEquals(setOf("sleep", "nutrition", "training", "body"), insights.context.keys)
        assertEquals("Beperkt onderbouwd", insights.context["body"]?.evidence?.strengthLabel)
        assertEquals(listOf("nutrition.fat", "nutrition.carbs", "nutrition.rating", "sleep.stages", "training.measurement"), insights.references.keys.toList())
        assertTrue(data.overview.recommendation.filled)
        assertEquals("c2-0", data.overview.recommendation.evidence?.research)
        assertEquals("c23-0", data.compass.opportunity.research?.research)

        val details = data.research.details
        assertEquals(9, details.size)
        // Every chip on the pages opens a detail that is there.
        val opened = insights.items.mapNotNull { it.evidence?.research } + insights.context.values.mapNotNull { it.evidence?.research } +
            insights.references.values.map { it.research } + listOfNotNull(data.overview.recommendation.evidence?.research, data.compass.opportunity.research?.research)
        assertTrue(opened.all { it in details })

        val mea = details.getValue("c14-0")
        assertEquals("hosted", mea.pdf.mode)
        assertEquals("assets/research/MEA-01.pdf", mea.pdf.href)
        assertTrue(mea.pdf.readable && mea.pdf.hosted)
        assertEquals("en", mea.source.language)
        assertEquals("De publicatie is in het Engels.", mea.source.languageNote)
        val nut = details.getValue("c15-1")
        assertEquals("official_pdf", nut.pdf.mode)
        assertTrue(nut.pdf.href!!.startsWith("https://www.gezondheidsraad.nl/"))
        assertEquals("nl", nut.source.language)
        assertNull("no note in the app's own language", nut.source.languageNote)
        assertTrue("one step lower: proxy", nut.strength.lowered && nut.strength.why != null)
        val sleep = details.getValue("c2-0")
        assertEquals("link", sleep.pdf.mode)
        assertFalse(sleep.pdf.readable)
        assertTrue(data.settings.page("account")!!.blocks.any { it == SettingsBlock.Insight("body") })
    }

    @Test
    fun `a href that is not the publisher's https address, or Ownify's own copy, is not kept`() {
        val state = data()
        val raw = json("research-state.json").getJSONObject("research")
        val detail = raw.getJSONObject("details").getJSONObject("c14-0")
        detail.getJSONObject("pdf").put("href", "javascript:alert(1)")
        detail.getJSONObject("source").put("href", "http://example.org/")
        val parsed = com.ownify.android.data.Research.parse(raw).details.getValue("c14-0")
        assertNull(parsed.pdf.href)
        assertFalse(parsed.pdf.readable)
        assertNull(parsed.source.href)
        assertEquals("the rest stays", state.research.details.getValue("c14-0").source.title, parsed.source.title)
    }

    @Test
    fun `Overzicht - three insights each with its chip and link, and the suggestion with its own`() {
        show()
        for (title in listOf("Slaapduur", "Verzadigd vet", "Beweging")) assertTrue(title, count(title) >= 1)
        // Three insights and the suggestion, each with "Bekijk onderzoek" — and no label: that is the detail's.
        assertEquals(4, research().fetchSemanticsNodes().size)
        assertEquals(0, count("Betrouwbaar"))
        text("Misschien iets voor deze week: één avond wat eerder naar bed gaan, en kijken hoe die nacht verloopt.").assertExists()
        // 29.2: the suggestion's closing line is no longer shown.
        assertEquals(0, count("Altijd een suggestie, nooit een opdracht."))
    }

    @Test
    fun `the chip opens the detail over Overzicht - what Ownify says, how strong, what was found, the source`() {
        show()
        tapResearch(0)
        assertEquals(Detail.Research("c2-0"), shell.detail)
        assertNull(shell.under)
        heading("Uit onderzoek blijkt").assertExists()
        text("Volwassenen van 18 tot 60 jaar: regelmatig minstens 7 uur slaap per nacht. Korter slapen hangt samen met meer gezondheidsrisico's.").assertExists()
        for (eyebrow in listOf("BEWIJSKRACHT", "WAT HET ONDERZOEK VOND", "VOOR WIE", "BEPERKINGEN", "BRON")) text(eyebrow).assertExists()
        text("Dit label zegt hoe sterk onderzoek deze bewering onderbouwt. Het is geen kans en geen percentage.").assertExists()
        text("Recommended Amount of Sleep for a Healthy Adult: A Joint Consensus Statement of the American Academy of Sleep Medicine and Sleep Research Society").assertExists()
        text("De publicatie is in het Engels.").assertExists()
        // Its source has no PDF Ownify may show: the publication at its publisher, nothing else.
        assertEquals(1, compose.onAllNodes(hasText("Bekijk de publicatie") and hasClickAction()).fetchSemanticsNodes().size)
        assertEquals(0, count("Lees de pdf"))
        text("Geen pdf om in Ownify te lezen: de publicatie opent bij de uitgever.").assertExists()
        // The label is said here, as a status: nothing to tap.
        assertTrue(count("Betrouwbaar") >= 1)
        assertEquals(0, tappableLabels("Betrouwbaar"))

        compose.runOnUiThread { shell.back() }
        compose.waitForIdle()
        assertTrue(!shell.detailShown)
    }

    @Test
    fun `on Slaap - its own insight, opened over Slaap, and back goes to Slaap`() {
        show()
        compose.runOnUiThread {
            shell.tab("health")
            shell.openDetail(Detail.HealthArea("sleep"))
        }
        compose.waitForIdle()
        // Overzicht's row, and the same stored insight on Slaap's own card.
        assertEquals(2, count("Je sliep de afgelopen 7 nachten gemiddeld 6:40 per nacht, volgens je metingen. Voor jouw leeftijd wordt minstens 7 uur aangeraden."))
        tapResearch(0)   // Slaap's own insight: the first chip on the page, right under its score
        assertEquals(Detail.Research("c2-0", over = Detail.HealthArea("sleep")), shell.detail)
        assertEquals(Detail.HealthArea("sleep"), shell.under)

        compose.runOnUiThread { shell.back() }
        compose.waitForIdle()
        assertEquals(Detail.HealthArea("sleep"), shell.detail)
        assertNull(shell.under)
        assertTrue(shell.detailShown)
    }

    @Test
    fun `the Scorekompas's point of attention - its research under the explanation`() {
        show()
        compose.runOnUiThread { shell.openDetail(Detail.ScoreCompass) }
        compose.waitForIdle()
        text("Mogelijk aandachtspunt").assertExists()
        tap(hasText("Bekijk onderzoek") and hasClickAction())
        assertEquals(Detail.Research("c23-0", over = Detail.ScoreCompass), shell.detail)
        text("Richtlijnen goede voeding 2015").assertExists()
        // The publisher's PDF, read in the app.
        assertEquals(1, compose.onAllNodes(hasText("Lees de pdf") and hasClickAction()).fetchSemanticsNodes().size)
        assertEquals("only Ownify's own copies are downloaded", 0, count("Download de pdf"))
    }

    @Test
    fun `Instellingen - the BMI under Lichaamsmaten, with its label`() {
        show()
        compose.runOnUiThread {
            shell.tab("settings")
            shell.openDetail(Detail.SettingsPage("account"))
        }
        compose.waitForIdle()
        text("BMI").assertExists()
        text("Met je lengte en gewicht is je BMI 25,2. De WHO noemt bij volwassenen een BMI vanaf 25 overgewicht en vanaf 30 obesitas. BMI zegt niets over je lichaamssamenstelling, zoals spiermassa.").assertExists()
        assertEquals(0, count("Beperkt onderbouwd"))
        tap(hasText("Bekijk onderzoek") and hasClickAction())
        assertEquals(Detail.Research("c25-2", over = Detail.SettingsPage("account")), shell.detail)
        // Two steps lower, and why.
        text("Je gegevens passen maar deels bij wat het onderzoek mat. Daarom staat het label hier twee stappen lager dan het onderzoek zelf.").assertExists()
    }

    @Test
    fun `without research in the state - no chips, no details, the cards as before`() {
        val state = json("state-demo.json").getJSONObject("data")
        show(AppData.parse(state))
        assertEquals(0, compose.onAllNodes(hasText("Bekijk onderzoek")).fetchSemanticsNodes().size)
        text("Nog geen suggestie").assertExists()
    }

    // ------------------------------------------------------------- the PDF

    private fun serve(path: String, status: Int, body: ByteArray, type: String = "application/pdf"): String {
        val http = HttpServer.create(InetSocketAddress("127.0.0.1", 0), 0)
        http.createContext("/") { exchange ->
            val (code, bytes) = if (exchange.requestURI.path == path) status to body else 404 to "not found".toByteArray()
            exchange.responseHeaders.add("Content-Type", type)
            exchange.sendResponseHeaders(code, bytes.size.toLong())
            exchange.responseBody.use { it.write(bytes) }
        }
        http.start()
        server = http
        return "http://127.0.0.1:${http.address.port}/"
    }

    private val context get() = ApplicationProvider.getApplicationContext<android.content.Context>()

    @Test
    fun `Ownify's own copy - from the server the app talks to, kept in the cache, read again from there`() {
        val pdf = File("../../assets/research/MEA-01.pdf").readBytes()
        val base = serve("/assets/research/MEA-01.pdf", 200, pdf)
        OwnifyConnection.api = OwnifyApi(base)
        val detail = data().research.details.getValue("c14-0")
        val url = ResearchPdfs.address(detail.pdf)!!
        assertEquals(base + "assets/research/MEA-01.pdf", url)

        val first = runBlocking { ResearchPdfs.fetch(context, url) }
        assertTrue(first is ResearchPdfs.Fetched.Ready)
        val file = (first as ResearchPdfs.Fetched.Ready).file
        assertTrue(file.path.contains("/research/"))
        assertTrue(file.readBytes().contentEquals(pdf))

        server?.stop(0)
        val again = runBlocking { ResearchPdfs.fetch(context, url) }
        assertEquals("from the cache, without the server", file, (again as ResearchPdfs.Fetched.Ready).file)
    }

    @Test
    fun `a publisher's error page instead of the PDF, a 404 or no server - never a PDF read`() {
        val base = serve("/rapport.pdf", 200, "<html>Access denied</html>".toByteArray(), "text/html")
        assertEquals(ResearchPdfs.Fetched.Failed, runBlocking { ResearchPdfs.fetch(context, base + "rapport.pdf") })
        assertEquals(ResearchPdfs.Fetched.Failed, runBlocking { ResearchPdfs.fetch(context, base + "missing.pdf") })
        assertTrue("nothing half-written stays", File(context.cacheDir, "research").listFiles().orEmpty().none { it.name.endsWith(".part") })

        val closed = ServerSocket(0).use { it.localPort }
        assertEquals(ResearchPdfs.Fetched.Offline, runBlocking { ResearchPdfs.fetch(context, "http://127.0.0.1:$closed/a.pdf") })
    }

    @Test
    fun `Lees de pdf - the PDF in the detail, or why not, never a crash`() {
        val pdf = File("../../assets/research/MEA-01.pdf").readBytes()
        val base = serve("/assets/research/MEA-01.pdf", 200, pdf)
        OwnifyConnection.api = OwnifyApi(base)
        show()
        compose.runOnUiThread {
            shell.tab("health")
            shell.openDetail(Detail.HealthArea("training"))
        }
        compose.waitForIdle()
        compose.runOnUiThread { shell.openDetail(Detail.Research("c14-0", over = Detail.HealthArea("training"))) }
        compose.waitForIdle()
        text("Keeping Pace with Wearables: A Living Umbrella Review of Systematic Reviews Evaluating the Accuracy of Consumer Wearable Technologies in Health Measurement").assertExists()
        text("Een ongewijzigde kopie van de publicatie, gedeeld onder CC BY 4.0.").assertExists()
        assertEquals(1, compose.onAllNodes(hasText("Download de pdf") and hasClickAction()).fetchSemanticsNodes().size)

        tap(hasText("Lees de pdf") and hasClickAction())
        compose.waitUntil(10_000) {
            count("Pagina 1 van 40") > 0 || count("De pdf kon niet worden geladen. Je kunt hem bij de uitgever openen.") > 0
        }
        // The pages where PdfRenderer can draw them (a phone); where it cannot, it says so.
        val drawn = compose.onAllNodes(hasContentDescription("Pagina 1 van 40")).fetchSemanticsNodes().isNotEmpty()
        val said = count("De pdf kon niet worden geladen. Je kunt hem bij de uitgever openen.") > 0
        println("Lees de pdf under Robolectric: pages drawn = $drawn, could not load = $said")
        assertTrue(drawn || said)
        assertEquals(0, count("Je bent offline. De publicatie opent zodra je weer verbinding hebt."))
    }
}
