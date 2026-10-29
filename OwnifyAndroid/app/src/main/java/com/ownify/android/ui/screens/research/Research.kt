package com.ownify.android.ui.screens.research

import android.app.DownloadManager
import android.content.Context
import android.content.Intent
import android.os.Build
import android.os.Environment
import androidx.compose.foundation.ScrollState
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.relocation.BringIntoViewRequester
import androidx.compose.foundation.relocation.bringIntoViewRequester
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.ui.Modifier
import androidx.compose.ui.layout.Layout
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.text
import androidx.compose.ui.text.AnnotatedString
import androidx.compose.ui.text.SpanStyle
import androidx.compose.ui.text.buildAnnotatedString
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.intl.LocaleList
import androidx.compose.ui.text.withStyle
import androidx.compose.ui.unit.Constraints
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.core.net.toUri
import com.ownify.android.data.Evidence
import com.ownify.android.data.Research
import com.ownify.android.data.ResearchCopy
import com.ownify.android.data.ResearchDetail
import com.ownify.android.i18n.tr
import com.ownify.android.ui.app.Detail
import com.ownify.android.ui.app.DetailColumn
import com.ownify.android.ui.app.LocalShell
import com.ownify.android.ui.design.Btn
import com.ownify.android.ui.design.BtnLook
import com.ownify.android.ui.design.GlassChip
import com.ownify.android.ui.design.JStyle
import com.ownify.android.ui.design.LocalScreen
import com.ownify.android.ui.design.PageIntro
import com.ownify.android.ui.design.T
import com.ownify.android.ui.design.chWidth
import com.ownify.android.ui.design.cssPadding
import com.ownify.android.ui.design.reveal
import com.ownify.android.ui.screens.settings.SettingsCard
import com.ownify.android.ui.screens.settings.SettingsEyebrow
import com.ownify.android.ui.screens.settings.SettingsNote
import com.ownify.android.ui.screens.settings.settingsRow
import com.ownify.android.ui.theme.Ownify
import com.ownify.android.ui.theme.OwnifyType

/*
 * "Uit onderzoek blijkt" (docs/INSIGHTS.md, docs/RESEARCH.md) on the phone,
 * as the website has it:
 *
 *   ResearchEvidence    components/research-evidence.php: the label as the
 *                       goal wizard's glass chip, "Bekijk onderzoek" as
 *                       the quiet link; both open the claim's detail over
 *                       whatever shows them
 *   ResearchDetailPage  pages/research-detail.php: what Ownify says with it
 *                       and how strong that is, then what the research found,
 *                       for whom, its limits, and the source with its PDF
 *
 * Every word is the server's (`research` in the state); the app only lays
 * them out, with Instellingen' own pieces — no new kind of page.
 */

/** The state's `research`: every detail a chip on these pages opens, and their words. */
val LocalResearch = staticCompositionLocalOf { Research.parse(null) }

/**
 * The evidence beside an insight or a reference value: nothing when there
 * is no detail to open. "Bekijk onderzoek" as the goal wizard's glass chip,
 * 8 above it (`.research-evidence`); how strong the research is, is said in
 * the detail it opens, not here.
 */
@Composable
fun ResearchEvidence(evidence: Evidence?, modifier: Modifier = Modifier) {
    val research = LocalResearch.current
    if (evidence == null || research.detail(evidence.research) == null) return
    val shell = LocalShell.current
    val open = {
        // Over the detail in front, when there is one; over the rail otherwise.
        val over = shell.detail?.takeIf { shell.detailShown }
        shell.openDetail(Detail.Research(evidence.research, over))
    }
    Box(modifier.fillMaxWidth().padding(top = Ownify.Space2)) {
        GlassChip(evidence.label, chosen = false, onClick = open)
    }
}

/**
 * Several chips side by side (31.0, `$data['evidences']`): e.g. "Bekijk
 * onderzoek" and "Bij jouw leeftijd" under one card, 8 apart, wrapping on a
 * narrow phone. Those without a detail to open are left out.
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
fun ResearchEvidence(evidences: List<Evidence?>, modifier: Modifier = Modifier) {
    val research = LocalResearch.current
    val shown = evidences.filterNotNull().filter { research.detail(it.research) != null }
    if (shown.isEmpty()) return
    if (shown.size == 1) {
        ResearchEvidence(shown.first(), modifier)
        return
    }
    val shell = LocalShell.current
    FlowRow(
        modifier.fillMaxWidth().padding(top = Ownify.Space2),
        horizontalArrangement = Arrangement.spacedBy(Ownify.Space2),
        verticalArrangement = Arrangement.spacedBy(Ownify.Space2)
    ) {
        shown.forEach { evidence ->
            GlassChip(evidence.label, chosen = false, onClick = {
                val over = shell.detail?.takeIf { shell.detailShown }
                shell.openDetail(Detail.Research(evidence.research, over))
            })
        }
    }
}

/**
 * One claim as Ownify uses it (pages/research-detail.php): short first —
 * what Ownify says with it and how strong that is — then what the research
 * found, for whom, its limits, and the source itself with its PDF where
 * that may be read.
 */
@Composable
fun ResearchDetailPage(research: Research, detail: ResearchDetail, scroll: ScrollState) {
    val copy = research.copy
    val source = detail.source
    DetailColumn(scroll, back = tr("Terug"), backAria = tr("Terug")) {
        PageIntro(copy.title, detail.statement, Modifier.reveal())

        // How strong: the chip as a status, never pressed, and what it means.
        Block(copy.evidence) {
            StateRow(divided = false) {
                GlassChip(detail.strength.label, chosen = false, onClick = null, description = "${copy.evidence}: ${detail.strength.label}")
                Note(detail.strength.means)
                detail.strength.why?.let { Note(it) }
                Note(detail.strength.note)
            }
        }

        Block(copy.found) { StateRow(divided = false) { Note(source.findings) } }
        Block(copy.population) { StateRow(divided = false) { Note(detail.population) } }
        Block(copy.limitations) {
            detail.limitations.forEachIndexed { i, limitation -> StateRow(divided = i > 0) { Note(limitation) } }
        }

        SourceBlock(copy, detail)
    }
}

/** A block: its eyebrow, and its card of rows (`.settings-block` > `.card.settings-card`). */
@Composable
private fun Block(title: String, rows: @Composable ColumnScope.() -> Unit) {
    Column(Modifier.fillMaxWidth().reveal()) {
        SettingsEyebrow(title)
        SettingsCard(content = rows)
    }
}

/** `.settings-state`: a row of the card — 12 above and below, 16 in (12 on the narrowest), its hairline after the first. */
@Composable
private fun StateRow(divided: Boolean, content: @Composable ColumnScope.() -> Unit) {
    val narrow = LocalScreen.current.narrow
    Column(
        Modifier
            .fillMaxWidth()
            .settingsRow(divided)
            .semantics(mergeDescendants = true) { }
            .cssPadding(if (narrow) Ownify.Space3 else Ownify.Space4, top = Ownify.Space3, bottom = Ownify.Space3, above = if (divided) 1.dp else 0.dp),
        content = content
    )
}

/** `.settings-state__label`: 15/500. In [language] for TalkBack when it is the publication's own title. */
@Composable
private fun Label(text: String, language: String? = null) {
    val style = OwnifyType.style(Ownify.FsLabel, FontWeight.Medium)
    if (language == null) {
        T(text, style)
        return
    }
    val spoken: AnnotatedString = buildAnnotatedString { withStyle(SpanStyle(localeList = LocaleList(language))) { append(text) } }
    T(text, style, Modifier.clearAndSetSemantics { this.text = spoken })
}

/** `.settings-state__note`: the footnote, 4 above, 44 characters at most. */
@Composable
private fun Note(text: String) {
    if (text.isEmpty()) return
    T(text, JStyle.Footnote, Modifier.padding(top = Ownify.Space1).widthIn(max = chWidth(JStyle.Footnote, 44f)))
}

/**
 * Bron: the publication — as published, in its own language — and what kind
 * of research it is; then reading it: Ownify's own CC BY copy or the
 * publisher's PDF in the app, Ownify's copy downloaded, or the publication at
 * its publisher. Offline, nothing loads and it says why.
 */
@Composable
private fun SourceBlock(copy: ResearchCopy, detail: ResearchDetail) {
    val context = LocalContext.current
    val source = detail.source
    val pdf = detail.pdf
    val address = remember(pdf) { ResearchPdfs.address(pdf) }
    val byline = listOfNotNull(source.authors ?: source.organisation, source.year.takeIf { it.isNotEmpty() }).joinToString(" · ")

    var reading by remember(detail.id) { mutableStateOf(false) }
    var offline by remember(detail.id) { mutableStateOf(false) }
    var attempt by remember(detail.id) { mutableIntStateOf(0) }
    val frame = remember { BringIntoViewRequester() }

    Column(Modifier.fillMaxWidth().reveal()) {
        SettingsEyebrow(copy.source)
        SettingsCard {
            StateRow(divided = false) {
                Label(source.title, source.language)
                if (byline.isNotEmpty()) Note(byline)
                if (source.authors != null && source.organisation != null) Note(source.organisation)
                Note(source.citation)
                source.languageNote?.let { Note(it) }
            }
            StateRow(divided = true) {
                Label(copy.kindLabel)
                Note(source.kind)
                Note(source.verified)
            }
        }

        BasisRow(basis = 160.dp, gap = Ownify.Space2, modifier = Modifier.padding(vertical = Ownify.Space3)) {
            if (pdf.readable && address != null) {
                Btn(copy.readPdf, onClick = {
                    reading = true
                    attempt++
                }, look = BtnLook.Primary)
                if (pdf.hosted) Btn(copy.downloadPdf, onClick = { download(context, address, source.code + ".pdf") })
            }
            source.href?.let { href -> Btn(copy.openSource, onClick = { view(context, href) }) }
        }

        SettingsNote("info", pdf.note)
        if (offline) SettingsNote("info", copy.offline, Modifier.padding(top = Ownify.Space2))

        if (reading && address != null) {
            PdfReader(
                url = address,
                attempt = attempt,
                copy = copy,
                title = source.title,
                language = source.language,
                onOffline = { offline = it; if (it) reading = false },
                modifier = Modifier.padding(top = Ownify.Space3).bringIntoViewRequester(frame)
            )
            LaunchedEffect(attempt) { frame.bringIntoView() }
        }

        if (detail.retired) SettingsNote("info", copy.retired, Modifier.padding(top = Ownify.Space2))
    }
}

/**
 * `.research-detail__actions`: the buttons in rows, each at least [basis]
 * wide (`flex: 1 1 160px`), the ones on a row sharing it equally, [gap]
 * between them and between rows.
 */
@Composable
private fun BasisRow(basis: Dp, gap: Dp, modifier: Modifier = Modifier, content: @Composable () -> Unit) {
    Layout(content, modifier.fillMaxWidth()) { measurables, constraints ->
        if (measurables.isEmpty()) return@Layout layout(constraints.minWidth, 0) {}
        val width = constraints.maxWidth
        val space = gap.roundToPx()
        val perRow = ((width + space) / (basis.roundToPx() + space)).coerceIn(1, measurables.size)
        val rows = measurables.chunked(perRow)
        val placed = rows.map { row ->
            val each = (width - space * (row.size - 1)) / row.size
            row.map { it.measure(Constraints.fixedWidth(each)) }
        }
        val height = placed.sumOf { row -> row.maxOf { it.height } } + space * (placed.size - 1)
        layout(width, height) {
            var y = 0
            placed.forEach { row ->
                var x = 0
                val tall = row.maxOf { it.height }
                row.forEach { item ->
                    item.place(x, y + (tall - item.height) / 2)
                    x += item.width + space
                }
                y += tall + space
            }
        }
    }
}

/** The browser, or whatever opens it — as the website's links do. */
private fun view(context: Context, url: String) {
    runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, url.toUri()).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) }
}

/**
 * "Download de pdf": the phone's own downloads, with its notification —
 * since Android 10 without any permission. On Android 9 the browser takes
 * it instead, as it would on the website.
 */
private fun download(context: Context, url: String, name: String) {
    if (Build.VERSION.SDK_INT < Build.VERSION_CODES.Q) {
        view(context, url)
        return
    }
    val queued = runCatching {
        val request = DownloadManager.Request(url.toUri())
            .setTitle(name)
            .setMimeType("application/pdf")
            .setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED)
            .setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, name)
        (context.getSystemService(Context.DOWNLOAD_SERVICE) as DownloadManager).enqueue(request)
    }
    if (queued.isFailure) view(context, url)
}
