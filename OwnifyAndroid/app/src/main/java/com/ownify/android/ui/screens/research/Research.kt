package com.ownify.android.ui.screens.research

import com.ownify.android.ui.design.SplitRow
import android.app.DownloadManager
import android.content.Context
import android.content.Intent
import android.os.Build
import android.os.Environment
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.shrinkVertically
import androidx.compose.animation.core.tween
import androidx.compose.foundation.layout.Row
import com.ownify.android.ui.design.TextLink
import com.ownify.android.ui.design.LocalReducedMotion
import com.ownify.android.ui.design.LocalStillMotion
import androidx.compose.foundation.ScrollState
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
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
import com.ownify.android.i18n.trf
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
 *   ResearchEvidence    components/research-evidence.php: "Bekijk
 *                       onderzoek" as the text link (38.0; a glass chip
 *                       before), opening the claim's detail over whatever
 *                       shows it
 *   ResearchDetailPage  pages/research-detail.php, since 38.0: Samenvatting
 *                       onderzoek (the findings as points), Bron (the
 *                       publication, Beperkingen opened in place beside it,
 *                       the PDF), Bewijskracht last. No "Voor wie": research
 *                       is only shown to the people it holds for.
 *
 * Every word is the server's (`research` in the state); the app only lays
 * them out, with Instellingen' own pieces — no new kind of page.
 */

/** The state's `research`: every detail a chip on these pages opens, and their words. */
val LocalResearch = staticCompositionLocalOf { Research.parse(null) }

/**
 * The evidence beside an insight or a reference value: nothing when there
 * is no detail to open. "Bekijk onderzoek" as the text link
 * (`.research-evidence`, its words 13 under the sentence as the chip's
 * were); how strong the research is, is said in the detail it opens.
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
    Box(modifier.fillMaxWidth()) {
        TextLink(evidence.label, onClick = open)
    }
}

/**
 * Several links side by side (31.0, `$data['evidences']`): e.g. "Bekijk
 * onderzoek" and "Bij jouw leeftijd" under one card, 16 apart, wrapping on
 * a narrow phone. Those without a detail to open are left out.
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
        modifier.fillMaxWidth(),
        horizontalArrangement = Arrangement.spacedBy(Ownify.Space4)
    ) {
        shown.forEach { evidence ->
            TextLink(evidence.label, onClick = {
                val over = shell.detail?.takeIf { shell.detailShown }
                shell.openDetail(Detail.Research(evidence.research, over))
            })
        }
    }
}

/**
 * One claim as Ownify uses it (pages/research-detail.php), since 38.0 in
 * this order: Samenvatting onderzoek — the findings as points; Bron — the
 * publication, Beperkingen beside it, the PDF; Bewijskracht last.
 */
@Composable
fun ResearchDetailPage(research: Research, detail: ResearchDetail, scroll: ScrollState, backTo: String? = null) {
    val copy = research.copy
    val source = detail.source
    // Opened over whatever showed its chip: the pill names that page (50.0), as every back pill names its own.
    DetailColumn(scroll, back = backTo ?: tr("Terug"), backAria = if (backTo != null) trf("Terug naar %s", backTo) else tr("Terug")) {
        PageIntro(copy.title, detail.statement, Modifier.reveal())

        // 1. Samenvatting onderzoek: one point a sentence, in the approved words.
        Block(copy.found) { StateRow(divided = false) { Points(source.summary) } }

        // 2. Bron, with Beperkingen beside the publication.
        SourceBlock(copy, detail)

        // 3. Bewijskracht, last: the chip as a status, never pressed, and what it means.
        Block(copy.evidence) {
            StateRow(divided = false) {
                GlassChip(detail.strength.label, chosen = false, onClick = null, description = "${copy.evidence}: ${detail.strength.label}")
                Note(detail.strength.means)
                detail.strength.why?.let { Note(it) }
                Note(detail.strength.note)
            }
        }
    }
}

/**
 * `.research-detail__points`: points as OwnifyAI's answers have them — a
 * muted disc, the footnote's type, 8 between.
 */
@Composable
internal fun Points(points: List<String>, modifier: Modifier = Modifier) {
    Column(modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
        points.forEach { point ->
            Row(Modifier.fillMaxWidth()) {
                T("•", JStyle.Footnote.copy(color = Ownify.TextMuted), Modifier.width(18.dp).clearAndSetSemantics { })
                T(point, JStyle.Footnote, Modifier.weight(1f))
            }
        }
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
    val style = OwnifyType.style(Ownify.FsBody, FontWeight.Normal)
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
    var limits by remember(detail.id) { mutableStateOf(false) }
    val reduced = LocalReducedMotion.current || LocalStillMotion.current
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
                // Beperkingen beside the source, opened in place as Voeding opens a row.
                if (detail.limitations.isNotEmpty()) {
                    TextLink(copy.limitations, onClick = { limits = !limits }, expanded = limits)
                    AnimatedVisibility(
                        visible = limits,
                        enter = expandVertically(tween(if (reduced) 0 else 280, easing = Ownify.EaseOutStrong)) + fadeIn(tween(if (reduced) 0 else Ownify.FastMs)),
                        exit = shrinkVertically(tween(if (reduced) 0 else 280, easing = Ownify.EaseOutStrong)) + fadeOut(tween(if (reduced) 0 else Ownify.FastMs))
                    ) {
                        Points(detail.limitations, Modifier.padding(bottom = Ownify.Space1))
                    }
                }
            }
            StateRow(divided = true) {
                Label(copy.kindLabel)
                Note(source.kind)
                Note(source.verified)
            }
        }

        if (pdf.readable && address != null) {
            val read: () -> Unit = {
                reading = true
                attempt++
            }
            // Hosted: Downloaden | Lezen, the split button (50.0); otherwise the one way to read it.
            if (pdf.hosted) {
                SplitRow(
                    { Btn(copy.downloadPdf, onClick = { download(context, address, source.code + ".pdf") }, modifier = it) },
                    { Btn(copy.readPdf, onClick = read, modifier = it) },
                    Modifier.padding(top = Ownify.Space3),
                    quietBack = false
                )
            } else {
                Btn(copy.readPdf, onClick = read, look = BtnLook.Primary, modifier = Modifier.padding(top = Ownify.Space3))
            }
        }
        // "Bekijk de publicatie": the text link (38.0, a button before), its words in line with the eyebrow's.
        source.href?.let { href ->
            TextLink(copy.openSource, onClick = { view(context, href) }, modifier = Modifier.padding(start = Ownify.Space2, bottom = Ownify.Space1))
        }

        // Nothing is said when there is no pdf to read in the app (38.1).
        if (pdf.note.isNotEmpty() && pdf.mode != "link") SettingsNote("info", pdf.note)
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
