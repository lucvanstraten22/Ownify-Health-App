package com.ownify.android.ui.screens.settings

import android.content.Context
import android.content.Intent
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.tween
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.shrinkVertically
import androidx.compose.foundation.ScrollState
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.widthIn
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.core.net.toUri
import com.ownify.android.data.Legal
import com.ownify.android.data.LegalDocument
import com.ownify.android.data.LegalSection
import com.ownify.android.i18n.trf
import com.ownify.android.ui.app.DetailColumn
import com.ownify.android.ui.design.JStyle
import com.ownify.android.ui.design.LocalReducedMotion
import com.ownify.android.ui.design.LocalScreen
import com.ownify.android.ui.design.LocalStillMotion
import com.ownify.android.ui.design.PageIntro
import com.ownify.android.ui.design.T
import com.ownify.android.ui.design.TextLink
import com.ownify.android.ui.design.chWidth
import com.ownify.android.ui.design.cssPadding
import com.ownify.android.ui.design.reveal
import com.ownify.android.ui.screens.research.Points
import com.ownify.android.ui.theme.Ownify

/**
 * One of Over de app's documents (pages/legal-detail.php, 39.0): the
 * Privacyverklaring, the Voorwaarden or the Licenties, over Over de app.
 * Built from what Instellingen and "Uit onderzoek blijkt" already use — the
 * page intro, a block per section (its eyebrow, the card, its notes and
 * Samenvatting onderzoek's points), the text link, and Beperkingen's
 * in-place opening for a licence's full text.
 */
@Composable
fun LegalDocumentPage(legal: Legal, document: LegalDocument, scroll: ScrollState) {
    DetailColumn(scroll, back = legal.back, backAria = trf("Terug naar %s", legal.back)) {
        PageIntro(document.title, document.lede, Modifier.reveal())
        document.sections.forEachIndexed { n, section ->
            Column(Modifier.fillMaxWidth().reveal()) {
                SettingsEyebrow(section.title)
                SettingsCard { SectionBody(legal, document.id + n, section) }
            }
        }
    }
}

@Composable
private fun SectionBody(legal: Legal, key: String, section: LegalSection) {
    val context = LocalContext.current
    val narrow = LocalScreen.current.narrow
    var open by remember(key) { mutableStateOf(false) }
    val reduced = LocalReducedMotion.current || LocalStillMotion.current

    Column(
        Modifier
            .fillMaxWidth()
            .cssPadding(if (narrow) Ownify.Space3 else Ownify.Space4, top = Ownify.Space3, bottom = Ownify.Space3)
    ) {
        section.text.forEach { Note(it) }
        if (section.points.isNotEmpty()) Points(section.points, Modifier.padding(top = Ownify.Space2))
        section.link?.let { (label, href) ->
            TextLink(label, onClick = { view(context, href) }, modifier = Modifier.padding(top = Ownify.Space1))
        }
        section.licence?.let { text ->
            TextLink(legal.licenceText, onClick = { open = !open }, expanded = open, modifier = Modifier.padding(top = Ownify.Space1))
            AnimatedVisibility(
                visible = open,
                enter = expandVertically(tween(if (reduced) 0 else 280, easing = Ownify.EaseOutStrong)) + fadeIn(tween(if (reduced) 0 else Ownify.FastMs)),
                exit = shrinkVertically(tween(if (reduced) 0 else 280, easing = Ownify.EaseOutStrong)) + fadeOut(tween(if (reduced) 0 else Ownify.FastMs))
            ) {
                T(text, JStyle.Footnote, Modifier.padding(top = Ownify.Space2))
            }
        }
    }
}

/** `.settings-state__note`: the footnote, 4 above, 44 characters at most. */
@Composable
private fun Note(text: String) {
    if (text.isEmpty()) return
    T(text, JStyle.Footnote, Modifier.padding(top = Ownify.Space1).widthIn(max = chWidth(JStyle.Footnote, 44f)))
}

/** The browser, as the website's links open. */
private fun view(context: Context, url: String) {
    runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, url.toUri()).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) }
}
